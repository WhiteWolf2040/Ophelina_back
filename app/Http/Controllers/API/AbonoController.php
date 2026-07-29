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
     * El cliente puede refrendar mientras no haya pagado el plazo completo.
     */
    private function refrendoEsElegible(Empeno $empeno): array
    {
        $plazoMeses = $empeno->plazo_meses ?? 1;
        
        $refrendosPagados = Pago::where('id_empeno', $empeno->id_empeno)
            ->where('tipo_pago', 'refrendo')
            ->count();

        // ✅ Puede refrendar si ha pagado menos refrendos que el plazo en meses
        $elegible = $refrendosPagados < $plazoMeses;

        return [
            'elegible' => $elegible,
            'refrendos_pagados' => $refrendosPagados,
            'refrendos_permitidos' => $plazoMeses,
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

            if (!in_array($tipo, ['abono', 'refrendo'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tipo de operación no válido.',
                ], 422);
            }

            $mora = $amortizacion->calcularMora();

            if ($tipo === 'refrendo') {
                // ✅ OBTENER EL PLAZO ORIGINAL EN MESES
                $plazoMeses = $empeno->plazo_meses ?? 1;
                
                // ✅ VERIFICAR ELEGIBILIDAD
                $elegibilidad = $this->refrendoEsElegible($empeno);
                if (!$elegibilidad['elegible']) {
                    return response()->json([
                        'success' => false, 
                        'message' => 'Ya has pagado todos los refrendos disponibles para este empeño.'
                    ], 422);
                }

                // ✅ CALCULAR INTERÉS TOTAL DEL PERIODO COMPLETO
                $capitalRestante = (float) $amortizacion->capital;
                $tasaPorcentaje = optional($empeno->tasa)->porcentaje ?? 15;
                
                $interesTotalPeriodo = $capitalRestante * ($tasaPorcentaje / 100) * $plazoMeses;
                $ivaPeriodo = $interesTotalPeriodo * 0.16;
                $monto = round($interesTotalPeriodo + $ivaPeriodo + $mora, 2);
                
                if ($monto <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No hay interés pendiente por pagar en este periodo.',
                    ], 422);
                }
                
                $nombreProducto = "Refrendo {$plazoMeses} meses - empeño {$empeno->folio}";
                $tipoMetadata = 'refrendo_empeno';

            } else {
                // ABONO
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
                    'plazo_meses' => $empeno->plazo_meses ?? 1, // ✅ PARA EL WEBHOOK
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

        $plazoMeses = $empeno->plazo_meses ?? 1;
        $capitalRestante = round((float) $amortizacion->capital, 2);
        $tasaPorcentaje = optional($empeno->tasa)->porcentaje ?? 15;
        
        // ✅ CALCULAR REFRENDO POR EL PLAZO COMPLETO
        $interesRefrendo = $capitalRestante * ($tasaPorcentaje / 100) * $plazoMeses;
        $ivaRefrendo = $interesRefrendo * 0.16;
        $montoRefrendo = round($interesRefrendo + $ivaRefrendo + $mora, 2);
        
        // ✅ VERIFICAR ELEGIBILIDAD PARA REFRENDO
        $refrendosPagados = Pago::where('id_empeno', $empeno->id_empeno)
            ->where('tipo_pago', 'refrendo')
            ->count();
        
        $puedeRefrendar = $refrendosPagados < $plazoMeses;

        // ✅ DATOS CORRECTOS PARA EL ABONO (sin mora, sin recalcular)
        $saldoPendiente = round((float) $amortizacion->saldo_final, 2);
        $totalConMora = round($saldoPendiente + $mora, 2);
        
        // ✅ INTERESES ACTUALES DE LA AMORTIZACIÓN (los que realmente se deben)
        $interesActual = round((float) $amortizacion->interes, 2);
        $ivaActual = round((float) $amortizacion->iva_interes, 2);
        $capitalActual = round((float) $amortizacion->capital, 2);

        return response()->json([
            'success' => true,
            'data' => [
                // ✅ DATOS REALES PARA EL ABONO
                'capital' => $capitalActual,
                'interes' => $interesActual,
                'iva_interes' => $ivaActual,
                'mora' => $mora,
                'dias_atraso' => $diasAtraso,
                'saldo_pendiente' => $saldoPendiente,
                'saldo_pendiente_con_mora' => $totalConMora,
                'plazo_meses' => $plazoMeses,
                'tasa_porcentaje' => $tasaPorcentaje,
                // ✅ REFRENDO
                'aplica_refrendo' => $puedeRefrendar,
                'monto_refrendo' => $montoRefrendo,
                'refrendos_pagados' => $refrendosPagados,
                'refrendos_permitidos' => $plazoMeses,
                'fecha_vencimiento_actual' => optional($empeno->fecha_vencimiento)->format('d/m/Y'),
                'nueva_fecha_vencimiento' => $puedeRefrendar 
                    ? now()->addMonths($plazoMeses)->format('d/m/Y')
                    : null,
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