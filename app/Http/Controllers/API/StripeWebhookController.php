<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Apartado;
use App\Models\Amortizacio;
use App\Models\Pago;
use App\Models\Empeno;
use App\Models\Prenda;
use App\Models\Empresa;
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
              $idApartado = $session->metadata->id_apartado ?? null;        // ← faltaba
             $idAmortizacion = $session->metadata->id_amortizacion ?? null; // ← faltaba

    // ==================== NUEVO: vincular suscripción a la empresa ====================
    if ($session->mode === 'subscription') {
        $empresaId = $session->metadata->empresa_id ?? null;
        $planId = $session->metadata->plan_id ?? null;

        if ($empresaId && $empresaId !== 'nueva') {
            $empresa = Empresa::find($empresaId);
            if ($empresa) {
                $empresa->update([
                    'id_plan' => $planId ?? $empresa->id_plan,
                    'plan_activo' => 1,
                    'stripe_customer_id' => $session->customer,
                    'stripe_subscription_id' => $session->subscription,
                    'fecha_inicio_plan' => now(),
                ]);
                Log::info('✅ Suscripción vinculada vía webhook: empresa_id=' . $empresa->id_empresa);
            } else {
                Log::warning('⚠️ checkout.session.completed: empresa_id no encontrado: ' . $empresaId);
            }
        }
    }

        if ($idApartado) {
            $apartado = Apartado::find($idApartado);
            if ($apartado) {
                $apartado->update(['stripe_payment_status' => 'pagado']);
                Log::info(' Apartado confirmado como pagado: id_apartado=' . $idApartado);
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

        // ==================== NUEVO: RENOVACIÓN DE SUSCRIPCIÓN ====================
    if ($event->type === 'invoice.paid') {
    try {
        $invoice = $event->data->object;
        $subscriptionId = $invoice->subscription ?? null;

        if ($subscriptionId) {
            $empresa = Empresa::where('stripe_subscription_id', $subscriptionId)->first();

            if ($empresa) {
                \Stripe\Stripe::setApiKey(env('STRIPE_SECRET'));
                $subscription = \Stripe\Subscription::retrieve($subscriptionId);

                // Nuevo modelo: el periodo vive en el subscription item, no en la suscripción
                $periodoFin = $subscription->items->data[0]->current_period_end
                    ?? $invoice->lines->data[0]->period->end
                    ?? null;

                $empresa->update([
                    'plan_activo' => 1,
                    'fecha_fin_plan' => $periodoFin
                        ? \Carbon\Carbon::createFromTimestamp($periodoFin)
                        : now()->addMonth(),
                ]);

                Log::info('✅ Renovación de suscripción registrada: empresa_id=' . $empresa->id_empresa);
            } else {
                Log::warning('⚠️ invoice.paid sin empresa asociada: subscription=' . $subscriptionId);
            }
        }
    } catch (\Throwable $e) {
        Log::error('❌ Error procesando invoice.paid: ' . $e->getMessage());
    }
}

        // ==================== NUEVO: PAGO DE SUSCRIPCIÓN FALLIDO ====================
        if ($event->type === 'invoice.payment_failed') {
            $invoice = $event->data->object;
            $subscriptionId = $invoice->subscription ?? null;

            if ($subscriptionId) {
                $empresa = Empresa::where('stripe_subscription_id', $subscriptionId)->first();

                if ($empresa) {
                    $empresa->update(['plan_activo' => 0]);
                    Log::warning('⚠️ Pago de suscripción fallido: empresa_id=' . $empresa->id_empresa);
                } else {
                    Log::warning('⚠️ invoice.payment_failed sin empresa asociada: subscription=' . $subscriptionId);
                }
            }
        }

        // ==================== NUEVO: SUSCRIPCIÓN CANCELADA ====================
        if ($event->type === 'customer.subscription.deleted') {
            $subscription = $event->data->object;
            $empresa = Empresa::where('stripe_subscription_id', $subscription->id)->first();

            if ($empresa) {
                $empresa->update([
                    'plan_activo' => 0,
                    'stripe_subscription_id' => null,
                ]);
                Log::info('ℹ️ Suscripción cancelada: empresa_id=' . $empresa->id_empresa);
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

            if ($tipo === 'refrendo_empeno') {
                $this->registrarRefrendoWeb($session, $amortizacion, $idEmpeno, $monto);
                return;
            }

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

        $plazoMeses = $empeno->plazo_meses ?? 1;
        $nuevaFechaVencimiento = now()->addMonths($plazoMeses);
        $empeno->update([
            'fecha_vencimiento' => $nuevaFechaVencimiento,
        ]);

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