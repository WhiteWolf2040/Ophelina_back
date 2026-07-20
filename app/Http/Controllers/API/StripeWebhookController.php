<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Apartado;
use Illuminate\Http\Request;
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
        }

        return response()->json(['success' => true]);
    }
}