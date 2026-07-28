<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Apartado;
use App\Models\Amortizacio;
use App\Models\Pago;
use App\Models\Empeno;
use App\Models\Prenda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            Log::error('❌ Firma de webhook inválida: ' . $e->getMessage());
            return response()->json(['error' => 'Firma inválida'], 400);
        } catch (\Exception $e) {
            Log::error('❌ Error al leer el webhook: ' . $e->getMessage());
            return response()->json(['error' => 'Payload inválido'], 400);
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $idApartado = $session->metadata->id_apartado ?? null;
            $idAmortizacion = $session->metadata->id_amortizacion ?? null;

            if ($idApartado) {
                $apartado = Apartado::find($idApartado);

                if ($apartado) {
                    $apartado->update([
                        'stripe_payment_status' => 'pagado',
                    ]);

                    $producto = $apartado->producto;
                    if ($producto && $producto->stock > 0) {
                        $producto->decrement('stock');
                    }

                    Log::info('✅ Apartado confirmado como pagado: id_apartado=' . $idApartado);
                } else {
                    Log::warning('⚠️ Webhook recibido pero apartado no encontrado: id_apartado=' . $idApartado);
                }
            }

            // Abonos y prórrogas a empeños (vienen de AbonoController::crearSesionPago)
            if ($idAmortizacion) {
                $this->registrarAbono($session, $idAmortizacion);
            }
        }

        if ($event->type === 'checkout.session.expired') {
            $session = $event->data->object;
            $idApartado = $session->metadata->id_apartado ?? null;

            if ($idApartado) {
                $apartado = Apartado::find($idApartado);

                if ($apartado) {
                    $apartado->update([
                        'stripe_payment_status' => 'fallido',
                        'estado' => 'cancelado',
                    ]);

                    // ✅ NUEVO: al expirar sin pagar, se revierte todo lo
                    // que se había reservado en OpheliaTiendaController@apartar
                    // -el producto vuelve a ser visible en la tienda y la
                    // prenda regresa a 'Disponible' en el inventario del
                    // dueño-. Antes esto no pasaba: un apartado que
                    // expiraba dejaba el producto oculto para siempre.
                    $producto = $apartado->producto;
                    if ($producto) {
                        $producto->visible = 1;
                        $producto->save();

                        if ($producto->id_prenda) {
                            Prenda::where('id_prenda', $producto->id_prenda)->update([
                                'estado' => 'Disponible',
                            ]);
                        }
                    }

                    Log::info('⏱️ Sesión expirada, apartado cancelado y producto restaurado: id_apartado=' . $idApartado);
                } else {
                    Log::warning('⚠️ Webhook de expiración recibido pero apartado no encontrado: id_apartado=' . $idApartado);
                }
            }

            // Para abonos/prórrogas no hace falta hacer nada al expirar: no
            // se reserva ningún saldo ni fecha mientras el checkout está pendiente.
        }

        return response()->json(['success' => true]);
    }

    /**
     * Registra el abono (o la prórroga) a un empeño una vez que Stripe
     * confirma el pago (viene de AbonoController::crearSesionPago).
     *
     * Metadata esperada en la sesión de Stripe (viene de
     * AbonoController::crearSesionPago):
     *   - tipo: 'abono_empeno' | 'prorroga_empeno'  (✅ 'prorroga_empeno' es
     *     NUEVO, antes no existía ningún valor que lo distinguiera)
     *   - id_empeno
     *   - id_amortizacion
     *   - id_cliente
     *   - monto
     *   - mora_incluida
     *
     * - 'abono_empeno': reparte el monto proporcionalmente entre capital e
     *   interés según la composición pendiente de la amortización, actualiza
     *   monto_pagado/saldo_final, y marca como pagado el empeño si el saldo
     *   llega a 0. NO mueve la fecha de vencimiento.
     * - 'prorroga_empeno': registra el pago de intereses (+IVA) y extiende
     *   30 días tanto Amortizacio.fecha_pago_programado como
     *   Empeno.fecha_vencimiento (vía Amortizacio::prorrogar(), que
     *   sincroniza ambas).
     */
    private function registrarAbono($session, $idAmortizacion)
    {
        $idEmpeno = $session->metadata->id_empeno ?? null;
        $monto = (float) ($session->metadata->monto ?? 0);
        // ✅ NUEVO: AbonoController manda 'abono_empeno' o 'prorroga_empeno'
        // en la metadata. Si por algún motivo no viene (sesiones viejas ya
        // creadas antes de este cambio), se asume 'abono_empeno'.
        $tipo = $session->metadata->tipo ?? 'abono_empeno';

        if (!$idEmpeno || $monto <= 0) {
            Log::warning('⚠️ Webhook de abono recibido con metadata incompleto: id_amortizacion=' . $idAmortizacion);
            return;
        }

        DB::transaction(function () use ($session, $idAmortizacion, $idEmpeno, $monto, $tipo) {
            $amortizacion = Amortizacio::where('id_amortizacion', $idAmortizacion)
                ->lockForUpdate()
                ->first();

            if (!$amortizacion) {
                Log::warning('⚠️ Webhook de abono recibido pero amortización no encontrada: id_amortizacion=' . $idAmortizacion);
                return;
            }

            // Evita duplicar el pago si Stripe reenvía el mismo evento
            $yaRegistrado = Pago::where('referencia', $session->id)->exists();
            if ($yaRegistrado) {
                Log::info('ℹ️ Pago ya registrado previamente para esta sesión: ' . $session->id);
                return;
            }

            if ($tipo === 'prorroga_empeno') {
                $this->registrarPrórrogaWeb($session, $amortizacion, $idEmpeno, $monto);
                return;
            }

            // ---- Flujo normal de abono (sin cambios de lógica) ----
            $totalOriginal = $amortizacion->capital + $amortizacion->interes + $amortizacion->iva_interes;
            $propCapital = $totalOriginal > 0 ? $amortizacion->capital / $totalOriginal : 0;
            $propInteres = $totalOriginal > 0 ? $amortizacion->interes / $totalOriginal : 0;

            $capitalPagado = round($monto * $propCapital, 2);
            $interesPagado = round($monto * $propInteres, 2);
            $ivaPagado = round($monto - $capitalPagado - $interesPagado, 2);

            Pago::create([
                'id_empeno' => $idEmpeno,
                'id_amortizacion' => $idAmortizacion,
                'fecha_pago' => now()->toDateString(),
                'capital_pagado' => $capitalPagado,
                'interes_pagado' => $interesPagado,
                'iva_pagado' => $ivaPagado,
                'monto_total' => $monto,
                'tipo_pago' => $monto >= $amortizacion->saldo_final ? 'liquidacion' : 'abono',
                'metodo_pago' => 'tarjeta',
                'referencia' => $session->id,
            ]);

            $nuevoMontoPagado = $amortizacion->monto_pagado + $monto;
            $nuevoSaldo = round($amortizacion->saldo_inicial - $nuevoMontoPagado, 2);

            $amortizacion->update([
                'monto_pagado' => $nuevoMontoPagado,
                'saldo_final' => max($nuevoSaldo, 0),
                'estado' => $nuevoSaldo <= 0 ? 'pagado' : 'pendiente',
                'fecha_pago_real' => $nuevoSaldo <= 0 ? now()->toDateString() : $amortizacion->fecha_pago_real,
            ]);

            if ($nuevoSaldo <= 0) {
                Empeno::where('id_empeno', $idEmpeno)->update(['estado' => 'pagado']);
            }

            Log::info('✅ Abono registrado: id_empeno=' . $idEmpeno . ' monto=' . $monto);
        });
    }

    /**
     * ✅ NUEVO: registra el pago de intereses de una prórroga hecha desde la
     * web y extiende el vencimiento 30 días.
     *
     * Importante: el monto de una prórroga cubre intereses (+ IVA), NO
     * capital, así que no reduce el capital de la amortización.
     */
    private function registrarPrórrogaWeb($session, Amortizacio $amortizacion, $idEmpeno, float $monto): void
    {
        $ivaPagado = round($monto - ($monto / 1.16), 2);
        $interesPagado = round($monto - $ivaPagado, 2);

        Pago::create([
            'id_empeno' => $idEmpeno,
            'id_amortizacion' => $amortizacion->id_amortizacion,
            'fecha_pago' => now()->toDateString(),
            'capital_pagado' => 0,
            'interes_pagado' => $interesPagado,
            'iva_pagado' => $ivaPagado,
            'monto_total' => $monto,
            'tipo_pago' => 'prorroga',
            'metodo_pago' => 'tarjeta',
            'referencia' => $session->id,
        ]);

        // Extiende fecha_pago_programado Y empeno.fecha_vencimiento juntas.
        $amortizacion->prorrogar(30);

        Log::info('✅ Prórroga registrada desde web: id_empeno=' . $idEmpeno . ' monto=' . $monto);
    }
}