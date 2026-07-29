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
                    $apartado->update(['stripe_payment_status' => 'pagado']);
                    $producto = $apartado->producto;
                    if ($producto && $producto->stock > 0) {
                        $producto->decrement('stock');
                    }
                    Log::info('✅ Apartado confirmado como pagado: id_apartado=' . $idApartado);
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
                    Log::info('⏱️ Sesión expirada, apartado cancelado: id_apartado=' . $idApartado);
                }
            }
        }

        return response()->json(['success' => true]);
    }

    private function registrarAbono($session, $idAmortizacion)
    {
        $idEmpeno = $session->metadata->id_empeno ?? null;
        $monto = (float) ($session->metadata->monto ?? 0);
        $tipo = $session->metadata->tipo ?? 'abono_empeno';

        if (!$idEmpeno || $monto <= 0) {
            Log::warning('⚠️ Webhook de abono recibido con metadata incompleto');
            return;
        }

        DB::transaction(function () use ($session, $idAmortizacion, $idEmpeno, $monto, $tipo) {
            $amortizacion = Amortizacio::where('id_amortizacion', $idAmortizacion)
                ->lockForUpdate()
                ->first();

            if (!$amortizacion) {
                Log::warning('⚠️ Webhook de abono recibido pero amortización no encontrada');
                return;
            }

            $yaRegistrado = Pago::where('referencia', $session->id)->exists();
            if ($yaRegistrado) {
                Log::info('ℹ️ Pago ya registrado previamente para esta sesión: ' . $session->id);
                return;
            }

            // ✅ REFRENDO: paga intereses del periodo completo y extiende fecha
            if ($tipo === 'refrendo_empeno') {
                $this->registrarRefrendoWeb($session, $amortizacion, $idEmpeno, $monto);
                return;
            }

            // ==================== ABONO (Prorrateo) ====================
            $deudaTotal = round((float) $amortizacion->capital + $amortizacion->interes + $amortizacion->iva_interes, 2);

            if ($deudaTotal > 0) {
                $capitalPagado = round($monto * ($amortizacion->capital / $deudaTotal), 2);
                $ivaPagado = round($monto * ($amortizacion->iva_interes / $deudaTotal), 2);
                $interesPagado = round($monto - $capitalPagado - $ivaPagado, 2);
            } else {
                $capitalPagado = $interesPagado = $ivaPagado = 0;
            }

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

            $nuevoCapital = max(0, round($amortizacion->capital - $capitalPagado, 2));
            $nuevoInteres = max(0, round($amortizacion->interes - $interesPagado, 2));
            $nuevoIva = max(0, round($amortizacion->iva_interes - $ivaPagado, 2));

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

            Log::info('✅ Abono registrado: id_empeno=' . $idEmpeno);
        });
    }

    /**
     * ✅ REGISTRA REFRENDO: paga intereses del periodo completo y extiende fecha
     */
    private function registrarRefrendoWeb($session, Amortizacio $amortizacion, $idEmpeno, float $monto): void
    {
        $empeno = Empeno::find($idEmpeno);
        if (!$empeno) {
            Log::warning('⚠️ Empeño no encontrado para refrendo: id=' . $idEmpeno);
            return;
        }

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
            'tipo_pago' => 'refrendo',
            'metodo_pago' => 'tarjeta',
            'referencia' => $session->id,
        ]);

        // ✅ OBTENER EL PLAZO ORIGINAL
        $plazoMeses = $empeno->plazo_meses ?? 1;
        
        // ✅ EXTENDER LA FECHA DE VENCIMIENTO POR EL PLAZO COMPLETO
        $nuevaFechaVencimiento = now()->addMonths($plazoMeses);
        $empeno->update([
            'fecha_vencimiento' => $nuevaFechaVencimiento,
        ]);
        
        // ✅ ACTUALIZAR LA AMORTIZACIÓN
        $nuevoInteres = max(0, round($amortizacion->interes - $interesPagado, 2));
        $nuevoIva = max(0, round($amortizacion->iva_interes - $ivaPagado, 2));
        $nuevoMontoPagado = $amortizacion->monto_pagado + $monto;
        $nuevoSaldo = max(0, round($amortizacion->saldo_inicial - $nuevoMontoPagado, 2));

        $amortizacion->update([
            'interes' => $nuevoInteres,
            'iva_interes' => $nuevoIva,
            'monto_pagado' => $nuevoMontoPagado,
            'saldo_final' => $nuevoSaldo,
            'fecha_pago_programado' => $nuevaFechaVencimiento,
        ]);

        Log::info('✅ Refrendo registrado: id_empeno=' . $idEmpeno . 
                  ' monto=' . $monto . 
                  ' nueva_fecha=' . $nuevaFechaVencimiento->format('Y-m-d'));
    }
}