<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Empeno;
use App\Models\Amortizacio;
use Illuminate\Http\Request;
use Stripe\StripeClient;

class AbonoController extends Controller
{
    /**
     * Crea una sesión de Stripe Checkout para que el cliente abone
     * a su empeño (parcial o liquidación total).
     *
     * POST /api/empenos/{empeno}/abono
     * body: { monto: number }  (opcional, si no se manda se sugiere el saldo pendiente)
     */
    public function crearSesionPago(Request $request, Empeno $empeno)
    {
        // Seguridad: el empeño debe pertenecer al cliente autenticado
        $idClienteUsuario = $request->user()->id_cliente;

        if (!$idClienteUsuario || (int) $empeno->id_cliente !== (int) $idClienteUsuario) {
            abort(403, 'No autorizado');
        }

        if ($empeno->estado !== 'activo' && $empeno->estado !== 'vencido') {
            return response()->json([
                'success' => false,
                'message' => 'Este empeño no admite abonos.',
            ], 422);
        }

        $amortizacion = Amortizacio::where('id_empeno', $empeno->id_empeno)
            ->where('estado', '!=', 'pagado')
            ->orderBy('numero_pago')
            ->firstOrFail();

        $saldoPendiente = round($amortizacion->saldo_final, 2);

        $monto = round((float) $request->input('monto', $saldoPendiente), 2);

        if ($monto <= 0 || $monto > $saldoPendiente) {
            return response()->json([
                'success' => false,
                'message' => 'Monto inválido. Debe ser mayor a 0 y no exceder el saldo pendiente.',
            ], 422);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'mxn',
                    'unit_amount' => (int) round($monto * 100),
                    'product_data' => [
                        'name' => "Abono a empeño {$empeno->folio}",
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'tipo' => 'abono_empeno',
                'id_empeno' => $empeno->id_empeno,
                'id_amortizacion' => $amortizacion->id_amortizacion,
                'id_cliente' => $empeno->id_cliente,
                'monto' => $monto,
            ],
            'success_url' => config('app.frontend_url') . '/misempenos',
            'cancel_url' => config('app.frontend_url') . '/misempenos',
        ]);

        return response()->json([
            'success' => true,
            'data' => ['checkout_url' => $session->url],
        ]);
    }

    // El registro del pago ya NO se hace aquí. Stripe llama al webhook
    // que ya tienes en StripeWebhookController::handle, que es el único
    // lugar donde se confirma que el pago realmente se completó.
}