<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Apartado;
use App\Models\Amortizacio;
use App\Models\Pago;
use App\Models\Empeno;
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

                    // Baja el stock del producto ahora que el pago se confirmó de verdad
                    $producto = $apartado->producto;
                    if ($producto && $producto->stock > 0) {
                        $producto->decrement('stock');
                    }

                    Log::info('✅ Apartado confirmado como pagado: id_apartado=' . $idApartado);
                } else {
                    Log::warning('⚠️ Webhook recibido pero apartado no encontrado: id_apartado=' . $idApartado);
                }
            }

            // ✅ Abonos a empeños (viene de AbonoController::crearSesionPago)
            if ($idAmortizacion) {
                $this->registrarAbono($session, $idAmortizacion);
            }
        }

        if ($event->type === 'checkout.session.expired') {
            $session = $event->data->object;
            $idApartado = $session->metadata->id_apartado ?? null;

            if ($idApartado) {
                Apartado::where('id_apartado', $idApartado)->update([
                    'stripe_payment_status' => 'fallido',
                    'estado' => 'cancelado',
                ]);
                Log::info('⏱️ Sesión expirada, apartado cancelado: id_apartado=' . $idApartado);
            }

            // Para abonos no hace falta hacer nada al expirar: no se reserva
            // ningún stock ni saldo mientras el checkout está pendiente.
        }

        return response()->json(['success' => true]);
    }

    /**
     * Registra el abono a un empeño una vez que Stripe confirma el pago
     * (viene de AbonoController::crearSesionPago, metadata: tipo=abono_empeno,
     * id_empeno, id_amortizacion, id_cliente, monto).
     *
     * Reparte el monto proporcionalmente entre capital e interés según la
     * composición pendiente de la amortización, actualiza monto_pagado/saldo_final,
     * y marca como pagado el empeño si el saldo llega a 0.
     */
    private function registrarAbono($session, $idAmortizacion)
    {
        $idEmpeno = $session->metadata->id_empeno ?? null;
        $monto = (float) ($session->metadata->monto ?? 0);

        if (!$idEmpeno || $monto <= 0) {
            Log::warning('⚠️ Webhook de abono recibido con metadata incompleto: id_amortizacion=' . $idAmortizacion);
            return;
        }

        DB::transaction(function () use ($session, $idAmortizacion, $idEmpeno, $monto) {
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
                Log::info('ℹ️ Abono ya registrado previamente para esta sesión: ' . $session->id);
                return;
            }

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
}