<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Empeno;
use App\Models\Amortizacio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class AbonoController extends Controller
{
    /**
     * Crea una sesión de Stripe Checkout para que el cliente abone a su
     * empeño (parcial o liquidación total) O para prorrogar 30 días.
     *
     * POST /api/empenos/{empeno}/abono
     * body:
     *   - monto: number (opcional; solo aplica si tipo=abono. Si no se
     *     manda, se sugiere el saldo pendiente + mora)
     *   - tipo: 'abono' | 'prorroga'  ✅ NUEVO. Default 'abono' para no
     *     romper llamadas viejas del frontend que todavía no lo mandan.
     */
    public function crearSesionPago(Request $request, Empeno $empeno)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
            }

            // Mismo patrón que OpheliaTiendaController: el id_cliente no vive
            // directo en el usuario, se busca por id_usuario en la tabla clientes.
            $cliente = Cliente::where('id_usuario', $user->id_usuario)->first();

            if (!$cliente || (int) $empeno->id_cliente !== (int) $cliente->id_cliente) {
                return response()->json(['success' => false, 'message' => 'No autorizado'], 403);
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
                ->first();

            if (!$amortizacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay saldo pendiente por abonar en este empeño.',
                ], 422);
            }

            // ✅ NUEVO: 'abono' (default) o 'prorroga'
            $tipo = $request->input('tipo', 'abono');

            if (!in_array($tipo, ['abono', 'prorroga'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tipo de operación no válido.',
                ], 422);
            }

            // Mora: si la amortización ya tiene días de retraso, se suma el
            // cargo moratorio (5% mensual default, configurable por empresa
            // en tasas_interes.porcentaje_moratorio). Se usa el mismo método
            // del modelo (calcularMora) que ya usa el resto del proyecto, en
            // vez de recalcularlo manualmente aquí otra vez.
            $mora = $amortizacion->calcularMora();

            if ($tipo === 'prorroga') {
                // ==================== NUEVO: FLUJO DE PRÓRROGA ====================
                // La prórroga NO es un monto libre elegido por el cliente: cobra
                // el interés de la cuota actual (+ mora si la hay) más su IVA,
                // y a cambio extiende 30 días el vencimiento. Se ignora
                // cualquier 'monto' que venga en el body.
                $interesConMora = round((float) $amortizacion->interes + $mora, 2);
                $ivaInteres = round($interesConMora * 0.16, 2);
                $monto = round($interesConMora + $ivaInteres, 2);

                if ($monto <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No hay interés pendiente que prorrogar en este empeño.',
                    ], 422);
                }

                $nombreProducto = "Prórroga 30 días - empeño {$empeno->folio}";
                $tipoMetadata = 'prorroga_empeno';
                // ===================================================================
            } else {
                $saldoPendiente = round($amortizacion->saldo_final, 2);
                $saldoPendienteConMora = round($saldoPendiente + $mora, 2);
                $monto = round((float) $request->input('monto', $saldoPendienteConMora), 2);

                if ($monto <= 0 || $monto > $saldoPendienteConMora) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Monto inválido. Debe ser mayor a 0 y no exceder el saldo pendiente' . ($mora > 0 ? ' (incluyendo mora).' : '.'),
                    ], 422);
                }

                $nombreProducto = "Abono a empeño {$empeno->folio}";
                $tipoMetadata = 'abono_empeno';
            }

            // Misma convención que el resto del proyecto: env('STRIPE_SECRET')
            // directo, no config('services.stripe.secret') (que no existe mapeado).
            $stripe = new StripeClient(env('STRIPE_SECRET'));

            $session = $stripe->checkout->sessions->create([
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'mxn',
                        'unit_amount' => (int) round($monto * 100),
                        'product_data' => [
                            'name' => $nombreProducto,
                        ],
                    ],
                    'quantity' => 1,
                ]],
                'metadata' => [
                    'tipo' => $tipoMetadata,
                    'id_empeno' => $empeno->id_empeno,
                    'id_amortizacion' => $amortizacion->id_amortizacion,
                    'id_cliente' => $cliente->id_cliente,
                    'monto' => $monto,
                    'mora_incluida' => $mora,
                ],
                // Reutiliza las mismas variables que ya configuraste en Render,
                // apuntando a /cliente/empenos (página de "Mis Empeños" del cliente).
                'success_url' => env('STRIPE_TIENDA_SUCCESS_URL'),
                'cancel_url' => env('STRIPE_TIENDA_CANCEL_URL'),
                'customer_email' => $user->correo,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'checkout_url' => $session->url,
                    'tipo' => $tipo,
                    'monto' => $monto,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('❌ Error en AbonoController@crearSesionPago: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar el pago',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    // El registro del pago ya NO se hace aquí. Stripe llama al webhook
    // que ya tienes en StripeWebhookController::handle, que es el único
    // lugar donde se confirma que el pago realmente se completó.
}