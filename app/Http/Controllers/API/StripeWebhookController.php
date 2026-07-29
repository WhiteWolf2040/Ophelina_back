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
        }

        return response()->json(['success' => true]);
    }

    /**
     * Registra el abono (o la prórroga) a un empeño una vez que Stripe
     * confirma el pago.
     *
     * ✅ ABONO — Opción C (orden de prioridad estándar del sector /
     * práctica documentada por CONDUSEF): IVA + interés se cubren primero,
     * completos, hasta donde alcance el monto abonado; el sobrante -si lo
     * hay- reduce el capital (deuda principal). Mismo criterio aplicado en
     * PagoController@store para pagos en sucursal, para que ambos canales
     * (cliente en línea / empleado presencial) se comporten igual.
     *
     * IMPORTANTE: el saldo TOTAL pendiente (saldo_final) siempre se reduce
     * por el monto completo pagado, sin importar el reparto interno.
     */
    private function registrarAbono($session, $idAmortizacion)
    {
        $idEmpeno = $session->metadata->id_empeno ?? null;
        $monto = (float) ($session->metadata->monto ?? 0);
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

            $yaRegistrado = Pago::where('referencia', $session->id)->exists();
            if ($yaRegistrado) {
                Log::info('ℹ️ Pago ya registrado previamente para esta sesión: ' . $session->id);
                return;
            }

            if ($tipo === 'prorroga_empeno') {
                $this->registrarPrórrogaWeb($session, $amortizacion, $idEmpeno, $monto);
                return;
            }

            // ==================== Opción C: IVA + interés primero, capital con el sobrante ====================
            $ivaPagado = min($monto, (float) $amortizacion->iva_interes);
            $restante1 = round($monto - $ivaPagado, 2);

            $interesPagado = min($restante1, (float) $amortizacion->interes);
            $restante2 = round($restante1 - $interesPagado, 2);

            $capitalPagado = min($restante2, (float) $amortizacion->capital);
            // =======================================================================================================

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

            // Se reduce el capital/interés/IVA real de la cuota, para que
            // cotizacion() refleje siempre lo que realmente queda pendiente.
            $nuevoCapital = max(0, round($amortizacion->capital - $capitalPagado, 2));
            $nuevoInteres = max(0, round($amortizacion->interes - $interesPagado, 2));
            $nuevoIva     = max(0, round($amortizacion->iva_interes - $ivaPagado, 2));

            $nuevoMontoPagado = $amortizacion->monto_pagado + $monto;
            $nuevoSaldo = round($amortizacion->saldo_inicial - $nuevoMontoPagado, 2);

            $amortizacion->update([
                'capital' => $nuevoCapital,
                'interes' => $nuevoInteres,
                'iva_interes' => $nuevoIva,
                'monto_pagado' => $nuevoMontoPagado,
                'saldo_final' => max($nuevoSaldo, 0),
                'estado' => $nuevoSaldo <= 0 ? 'pagado' : 'pendiente',
                'fecha_pago_real' => $nuevoSaldo <= 0 ? now()->toDateString() : $amortizacion->fecha_pago_real,
            ]);

            if ($nuevoSaldo <= 0) {
                Empeno::where('id_empeno', $idEmpeno)->update(['estado' => 'pagado']);
            }

            Log::info('✅ Abono registrado: id_empeno=' . $idEmpeno . ' capital=' . $capitalPagado . ' interes=' . $interesPagado . ' iva=' . $ivaPagado);
        });
    }

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

        $amortizacion->prorrogar(30);

        Log::info('✅ Prórroga registrada desde web: id_empeno=' . $idEmpeno . ' monto=' . $monto);
    }
}