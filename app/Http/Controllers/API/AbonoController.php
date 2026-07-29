<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Empeno;
use App\Models\Amortizacio;
use App\Models\Pago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

class AbonoController extends Controller
{
    /**
     * ✅ NUEVO: determina si el empeño puede refrendar ahora mismo.
     * Regla: máximo (plazo_meses - 1) refrendos en toda la vida del
     * préstamo (el último mes se liquida o se prorroga, no se refrenda),
     * y solo uno por mes ya transcurrido desde fecha_empeno -- evita que
     * el cliente pague varios refrendos seguidos el mismo día.
     */
    private function refrendoEsElegible(Empeno $empeno): array
    {
        $plazoMeses = $empeno->plazo_meses ?? 1;
        $refrendosPermitidos = max(0, $plazoMeses - 1);

        $refrendosPagados = Pago::where('id_empeno', $empeno->id_empeno)
            ->where('tipo_pago', 'refrendo')
            ->count();

        $mesesTranscurridos = $empeno->fecha_empeno
            ? (int) floor($empeno->fecha_empeno->diffInDays(now()) / 30)
            : 0;

        $elegible = $refrendosPagados < $refrendosPermitidos
            && $mesesTranscurridos > $refrendosPagados;

        return [
            'elegible' => $elegible,
            'refrendos_pagados' => $refrendosPagados,
            'refrendos_permitidos' => $refrendosPermitidos,
            'meses_transcurridos' => $mesesTranscurridos,
        ];
    }

    public function crearSesionPago(Request $request, Empeno $empeno)
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

            $tipo = $request->input('tipo', 'abono');

            if (!in_array($tipo, ['abono', 'prorroga', 'refrendo'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tipo de operación no válido.',
                ], 422);
            }

            $mora = $amortizacion->calcularMora();

            if ($tipo === 'prorroga') {
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

            } elseif ($tipo === 'refrendo') {
                $plazoMeses = $empeno->plazo_meses ?? 1;

                if ($plazoMeses <= 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Este empeño es de un solo periodo; no aplica refrendo. Usa la prórroga cuando venza.',
                    ], 422);
                }

                if ($empeno->estado === 'vencido') {
                    return response()->json([
                        'success' => false,
                        'message' => 'Este empeño ya venció; usa la prórroga en vez del refrendo.',
                    ], 422);
                }

                // ✅ NUEVO: bloquea refrendos duplicados o adelantados
                $elegibilidad = $this->refrendoEsElegible($empeno);
                if (!$elegibilidad['elegible']) {
                    $mensaje = $elegibilidad['refrendos_pagados'] >= $elegibilidad['refrendos_permitidos']
                        ? 'Ya pagaste todos los refrendos disponibles para este empeño; al vencer, usa la prórroga.'
                        : 'Aún no te toca pagar el siguiente refrendo mensual.';

                    return response()->json(['success' => false, 'message' => $mensaje], 422);
                }

                $interesMensualOriginal = round(((float) $empeno->intereses) / $plazoMeses, 2);
                $interesDisponible = min($interesMensualOriginal, (float) $amortizacion->interes);
                $interesConMora = round($interesDisponible + $mora, 2);
                $ivaRefrendo = round($interesConMora * 0.16, 2);
                $monto = round($interesConMora + $ivaRefrendo, 2);

                if ($monto <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No hay refrendo pendiente por pagar en este periodo.',
                    ], 422);
                }

                $nombreProducto = "Refrendo mensual - empeño {$empeno->folio}";
                $tipoMetadata = 'refrendo_empeno';

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

            $stripe = new StripeClient(env('STRIPE_SECRET'));

            $successBase = env('STRIPE_TIENDA_SUCCESS_URL');
            $cancelBase = env('STRIPE_TIENDA_CANCEL_URL');

            if (empty($successBase) || empty($cancelBase)) {
                Log::error('❌ STRIPE_TIENDA_SUCCESS_URL o STRIPE_TIENDA_CANCEL_URL no están configuradas.');
                return response()->json([
                    'success' => false,
                    'message' => 'Configuración de pago incompleta en el servidor. Contacta al administrador.',
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
                        'product_data' => ['name' => $nombreProducto],
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

            $plazoMeses = $empeno->plazo_meses ?? 1;
            $montoRefrendo = 0;
            $elegibilidadRefrendo = ['elegible' => false, 'refrendos_pagados' => 0, 'refrendos_permitidos' => 0];

            if ($plazoMeses > 1 && $empeno->estado !== 'vencido') {
                $elegibilidadRefrendo = $this->refrendoEsElegible($empeno);

                if ($elegibilidadRefrendo['elegible']) {
                    $interesMensualOriginal = round(((float) $empeno->intereses) / $plazoMeses, 2);
                    $interesDisponible = min($interesMensualOriginal, (float) $amortizacion->interes);
                    $interesRefrendoConMora = round($interesDisponible + $mora, 2);
                    $ivaRefrendo = round($interesRefrendoConMora * 0.16, 2);
                    $montoRefrendo = round($interesRefrendoConMora + $ivaRefrendo, 2);
                }
            }

            $saldoPendiente = round((float) $amortizacion->saldo_final, 2);
            $totalConMora = round($saldoPendiente + $mora, 2);

            return response()->json([
                'success' => true,
                'data' => [
                    'capital' => round((float) $amortizacion->capital, 2),
                    'interes' => round((float) $amortizacion->interes, 2),
                    'iva_interes' => round((float) $amortizacion->iva_interes, 2),
                    'mora' => $mora,
                    'dias_atraso' => $diasAtraso,
                    'saldo_pendiente' => $saldoPendiente,
                    'saldo_pendiente_con_mora' => $totalConMora,
                    'monto_prorroga' => $montoProrroga,
                    'plazo_meses' => $plazoMeses,
                    // ✅ NUEVO: tasa real, para que el cliente vea el % coherente
                    'tasa_porcentaje' => optional($empeno->tasa)->porcentaje,
                    'aplica_refrendo' => $elegibilidadRefrendo['elegible'],
                    'monto_refrendo' => $montoRefrendo,
                    'refrendos_pagados' => $elegibilidadRefrendo['refrendos_pagados'],
                    'refrendos_permitidos' => $elegibilidadRefrendo['refrendos_permitidos'],
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

    private function agregarParametro(string $urlBase, string $clave, string $valor): string
    {
        $separador = (strpos($urlBase, '?') !== false) ? '&' : '?';
        return $urlBase . $separador . $clave . '=' . urlencode($valor);
    }
}