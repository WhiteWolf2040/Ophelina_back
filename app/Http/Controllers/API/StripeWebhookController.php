<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Apartado;
use App\Models\Amortizacio;
use App\Models\Pago;
use App\Models\Empeno;
use App\Models\Prenda;
use App\Models\Empresa;
use App\Services\SuscripcionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use App\Mail\RenovacionSuscripcion;
use Illuminate\Support\Facades\Mail; 
use Illuminate\Support\Facades\Cache;

class StripeWebhookController extends Controller
{
    public function __construct(private SuscripcionService $suscripciones)
    {
    }

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

        // ==================== PAGO DE CHECKOUT COMPLETADO ====================
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;
            $idApartado = $session->metadata->id_apartado ?? null;
            $idAmortizacion = $session->metadata->id_amortizacion ?? null;

            // Alta de suscripción (plan SaaS)
            if ($session->mode === 'subscription') {
                try {
                    $this->vincularDesdeCheckout($session);
                } catch (\Throwable $e) {
                    Log::error('❌ Error vinculando suscripción: ' . $e->getMessage());
                    // 500 => Stripe reintenta el aviso más tarde
                    return response()->json(['error' => 'Error procesando suscripción'], 500);
                }
            }

            if ($idApartado) {
                $apartado = Apartado::find($idApartado);
                if ($apartado) {
                    $apartado->update(['stripe_payment_status' => 'pagado']);
                    Log::info('Apartado confirmado como pagado: id_apartado=' . $idApartado);
                }
            }

            if ($idAmortizacion) {
                $this->registrarAbono($session, $idAmortizacion);
            }
        }

        // ==================== SESIÓN EXPIRADA (APARTADOS) ====================
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

        // ==================== RENOVACIÓN AUTOMÁTICA (cobro mensual exitoso) ====================
        if ($event->type === 'invoice.paid') {
            try {
                $invoice = $event->data->object;
                $subscriptionId = $this->subscriptionIdDeInvoice($invoice);

                if ($subscriptionId) {
                    $subscription = $this->suscripciones->obtener($subscriptionId);
                    $periodoFin = $this->suscripciones->periodoFin($subscription)
                        ?? (isset($invoice->lines->data[0]->period->end)
                            ? \Carbon\Carbon::createFromTimestamp($invoice->lines->data[0]->period->end)
                            : null);

                    $empresa = Empresa::where('stripe_subscription_id', $subscriptionId)->first();

                    // Plan B: la empresa nunca quedó ligada (ej. el usuario cerró la pestaña
                    // antes de volver de Stripe). Se busca por correo y se liga ahora.
                    if (!$empresa) {
                        $empresa = $this->buscarEmpresaPorCorreo($invoice->customer_email ?? null);
                        if ($empresa) {
                            $this->suscripciones->vincular(
                                $empresa,
                                $subscription->metadata->plan_id ?? null,
                                $invoice->customer ?? null,
                                $subscriptionId,
                                $periodoFin
                            );
                            Log::info('✅ Suscripción ligada en invoice.paid por correo: empresa_id=' . $empresa->id_empresa);
                            return response()->json(['success' => true]);
                        }
                    }

                  if ($empresa) {
                        $this->suscripciones->renovar($empresa, $periodoFin);
                        Log::info('✅ Renovación registrada: empresa_id=' . $empresa->id_empresa);

                        // Solo en renovaciones (no en el primer cobro, que ya muestra Stripe)
                       // Correo en cada cobro real de renovación (no en el alta ni en facturas de $0)
                        if (($invoice->billing_reason ?? null) !== 'subscription_create' && ($invoice->amount_paid ?? 0) > 0) {
                            $this->notificarRenovacion($empresa, $invoice, $periodoFin);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::error('❌ Error procesando invoice.paid: ' . $e->getMessage());
                // 500 => Stripe reintenta; así una renovación no se pierde
                return response()->json(['error' => 'Error procesando renovación'], 500);
            }
        }

        // ==================== COBRO MENSUAL FALLIDO ====================
        // Stripe reintenta el cobro varios días. NO se desactiva aquí:
        // si todos los reintentos fallan, Stripe cancela y llega customer.subscription.deleted.
        // Mientras tanto el acceso lo controla fecha_fin_plan (+ días de gracia).
        if ($event->type === 'invoice.payment_failed') {
            $invoice = $event->data->object;
            $subscriptionId = $this->subscriptionIdDeInvoice($invoice);

            if ($subscriptionId) {
                $empresa = Empresa::where('stripe_subscription_id', $subscriptionId)->first();
                Log::warning('⚠️ Cobro de suscripción fallido (Stripe reintentará): empresa_id='
                    . ($empresa->id_empresa ?? 'desconocida') . ' intento=' . ($invoice->attempt_count ?? '?'));
                // Aquí puedes mandar un correo/notificación a la empresa para que actualice su tarjeta.
            }
        }

        // ==================== SUSCRIPCIÓN CANCELADA ====================
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

    // ------------------------------------------------------------------
    // Helpers de suscripción
    // ------------------------------------------------------------------

    private function subscriptionIdDeInvoice($invoice): ?string
    {
        return $invoice->subscription
            ?? $invoice->parent->subscription_details->subscription
            ?? null;
    }

    private function buscarEmpresaPorCorreo(?string $correo): ?Empresa
    {
        return $correo ? Empresa::where('email', $correo)->first() : null;
    }

  private function notificarRenovacion(Empresa $empresa, $invoice, ?\Carbon\Carbon $periodoFin): void
{
    $clave = 'mail_renovacion_' . $invoice->id;
    $marcado = false;

    try {
        // Anti-duplicados (Stripe puede reenviar el evento). Si el caché falla, se envía igual.
        try {
            if (!Cache::add($clave, true, now()->addDays(2))) {
                return;
            }
            $marcado = true;
        } catch (\Throwable $e) {
            Log::warning('⚠️ Caché no disponible, se envía sin anti-duplicados: ' . $e->getMessage());
        }

        $destino = config('mail.destino_pruebas') ?: $empresa->email;
        if (!$destino) {
            return;
        }

        $empresa->loadMissing('plan');

        Mail::to($destino)->send(new RenovacionSuscripcion(
            empresa: $empresa,
            planNombre: $empresa->plan->nombre ?? 'Premium',
            monto: ($invoice->amount_paid ?? 0) / 100,
            moneda: $invoice->currency ?? 'mxn',
            proximoCobro: $periodoFin?->locale('es')->translatedFormat('d \d\e F \d\e Y'),
            facturaUrl: $invoice->hosted_invoice_url ?? null,
            numeroFactura: $invoice->number ?? null,
        ));

        Log::info('📧 Correo de renovación enviado a ' . $destino . ' (empresa_id=' . $empresa->id_empresa . ')');
    } catch (\Throwable $e) {
        if ($marcado) {
            try { Cache::forget($clave); } catch (\Throwable $ignorar) {}
        }
        Log::error('❌ No se pudo enviar correo de renovación: ' . $e->getMessage());
    }
}



    private function vincularDesdeCheckout($session): void
    {
        $empresaId = $session->metadata->empresa_id ?? null;
        $empresa = ($empresaId && $empresaId !== 'nueva') ? Empresa::find($empresaId) : null;

        // Si no vino empresa_id ("nueva"), se intenta por correo
        if (!$empresa) {
            $correo = $session->customer_details->email ?? $session->customer_email ?? null;
            $empresa = $this->buscarEmpresaPorCorreo($correo);
        }

        if (!$empresa) {
            Log::warning('⚠️ checkout.session.completed: no se encontró empresa (empresa_id=' . ($empresaId ?? 'null')
                . '). Se ligará en verifyPayment o en la primera invoice.paid.');
            return;
        }

        $subscriptionId = is_string($session->subscription) ? $session->subscription : ($session->subscription->id ?? null);
        if (!$subscriptionId) {
            return;
        }

        $subscription = $this->suscripciones->obtener($subscriptionId);

        $this->suscripciones->vincular(
            $empresa,
            $session->metadata->plan_id ?? null,
            $session->customer,
            $subscriptionId,
            $this->suscripciones->periodoFin($subscription)
        );

        Log::info('✅ Suscripción vinculada vía webhook: empresa_id=' . $empresa->id_empresa);
    }

    // ------------------------------------------------------------------
    // Abonos y refrendos de empeños (sin cambios)
    // ------------------------------------------------------------------

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