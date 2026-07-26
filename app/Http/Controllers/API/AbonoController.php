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

            // ✅ CORREGIDO: STRIPE_TIENDA_SUCCESS_URL / STRIPE_TIENDA_CANCEL_URL
            // ahora son URLs base GENÉRICAS compartidas por los 3 flujos
            // (apartado, abono, prórroga), ej:
            //   https://ophelina-front.vercel.app/homecliente?pago=exitoso
            // Aquí le agregamos &tipo=abono o &tipo=prorroga dinámicamente,
            // para que el frontend sepa qué toast mostrar SIN necesitar una
            // variable de entorno distinta por cada tipo de operación (eso
            // evitaría que se pisen entre sí como pasaría si cada controlador
            // asumiera un valor fijo distinto en la misma variable).
            $successBase = env('STRIPE_TIENDA_SUCCESS_URL');
            $cancelBase = env('STRIPE_TIENDA_CANCEL_URL');

            // ✅ NUEVO: misma validación defensiva que en OpheliaTiendaController@apartar.
            // Evita un TypeError fatal (no capturable por el catch de abajo)
            // si estas env vars vienen null.
            if (empty($successBase) || empty($cancelBase)) {
                Log::error('❌ STRIPE_TIENDA_SUCCESS_URL o STRIPE_TIENDA_CANCEL_URL no están configuradas (revisa las env vars en Render y haz un redeploy).');
                return response()->json([
                    'success' => false,
                    'message' => 'Configuración de pago incompleta en el servidor (faltan URLs de Stripe). Contacta al administrador.',
                ], 500);
            }

            $successUrl = $this->agregarParametro($successBase, 'tipo', $tipo);
            $cancelUrl = $this->agregarParametro($cancelBase, 'tipo', $tipo);

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
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
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

    /**
     * ✅ NUEVO: desglose de lo que se debe ANTES de pagar, para que el
     * cliente vea capital / interés / mora / IVA en el popup, en vez de
     * solo un input vacío de "monto a abonar".
     *
     * GET /api/empenos/{empeno}/cotizacion
     */
    public function cotizacion(Request $request, Empeno $empeno)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
            }

            $cliente = Cliente::where('id_usuario', $user->id_usuario)->first();

            if (!$cliente || (int) $empeno->id_cliente !== (int) $cliente->id_cliente) {
                return response()->json(['success' => false, 'message' => 'No autorizado'], 403);
            }

            $amortizacion = Amortizacio::where('id_empeno', $empeno->id_empeno)
                ->where('estado', '!=', 'pagado')
                ->orderBy('numero_pago')
                ->first();

            if (!$amortizacion) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay saldo pendiente en este empeño.',
                ], 422);
            }

            $mora = $amortizacion->calcularMora();
            $diasAtraso = $amortizacion->dias_retraso;

            $interesConMora = round((float) $amortizacion->interes + $mora, 2);
            $ivaProrroga = round($interesConMora * 0.16, 2);
            $montoProrroga = round($interesConMora + $ivaProrroga, 2);

            $saldoPendiente = round((float) $amortizacion->saldo_final, 2);
            $totalConMora = round($saldoPendiente + $mora, 2);

            return response()->json([
                'success' => true,
                'data' => [
                    // Desglose "original" de la cuota (referencia para el cliente)
                    'capital' => round((float) $amortizacion->capital, 2),
                    'interes' => round((float) $amortizacion->interes, 2),
                    'iva_interes' => round((float) $amortizacion->iva_interes, 2),
                    // Mora acumulada por días de atraso (0 si no hay atraso)
                    'mora' => $mora,
                    'dias_atraso' => $diasAtraso,
                    // Lo que realmente puede abonar (tope del input)
                    'saldo_pendiente' => $saldoPendiente,
                    'saldo_pendiente_con_mora' => $totalConMora,
                    // Lo que cuesta prorrogar (fijo, no editable por el cliente)
                    'monto_prorroga' => $montoProrroga,
                    'fecha_vencimiento_actual' => optional($empeno->fecha_vencimiento)->format('d/m/Y'),
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('❌ Error en AbonoController@cotizacion: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al calcular la cotización',
            ], 500);
        }
    }

    /**
     * ✅ NUEVO: helper para agregarle un query param a una URL base,
     * sin importar si esa URL base ya trae otros parámetros o no.
     * Usado para inyectar &tipo=abono / &tipo=prorroga / &tipo=apartado
     * a las URLs genéricas STRIPE_TIENDA_SUCCESS_URL / _CANCEL_URL.
     */
    private function agregarParametro(string $urlBase, string $clave, string $valor): string
    {
        $separador = (strpos($urlBase, '?') !== false) ? '&' : '?';
        return $urlBase . $separador . $clave . '=' . urlencode($valor);
    }

    // El registro del pago ya NO se hace aquí. Stripe llama al webhook
    // que ya tienes en StripeWebhookController::handle, que es el único
    // lugar donde se confirma que el pago realmente se completó.
}