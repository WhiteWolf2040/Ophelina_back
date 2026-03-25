<?php
// app/Http/Controllers/API/EmpenoController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Empeno;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmpenoController extends Controller
{
    /**
     * Obtener empeños activos con saldo pendiente para el formulario de pagos
     * GET /api/empenos/activos-con-saldo
     */
    public function activosConSaldo(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }
            
            // Obtener empeños activos de la empresa del usuario
            $empenos = Empeno::where('estado', 'activo')
                ->where('id_empresa', $user->id_empresa)
                ->with(['cliente', 'prenda'])
                ->get();
            
            $resultados = [];
            
            foreach ($empenos as $empeno) {
                // Calcular total pagado
                $totalPagado = DB::table('pagos')
                    ->where('id_empeno', $empeno->id_empeno)
                    ->sum('monto_total') ?? 0;
                
                // Obtener amortización pendiente
                $amortizacionPendiente = DB::table('amortizacion')
                    ->where('id_empeno', $empeno->id_empeno)
                    ->where('estado', 'pendiente')
                    ->orderBy('numero_pago', 'asc')
                    ->first();
                
                // Calcular saldo pendiente de la cuota actual
                $saldoPendienteCuota = 0;
                if ($amortizacionPendiente) {
                    $saldoPendienteCuota = ($amortizacionPendiente->monto_total ?? 0) - ($amortizacionPendiente->monto_pagado ?? 0);
                }
                
                // Saldo total pendiente del empeño
                $saldoTotalPendiente = max(0, ($empeno->monto_prestado ?? 0) - $totalPagado);
                
                $resultados[] = [
                    'id_empeno' => $empeno->id_empeno,
                    'cliente' => $empeno->cliente ? $empeno->cliente->nombre . ' ' . $empeno->cliente->apellido : 'Cliente no disponible',
                    'articulo' => $empeno->prenda ? $empeno->prenda->descripcion : 'Sin artículo',
                    'monto_prestado' => floatval($empeno->monto_prestado ?? 0),
                    'total_pagado' => floatval($totalPagado),
                    'saldo_total_pendiente' => floatval($saldoTotalPendiente),
                    'saldo_pendiente_cuota' => floatval($saldoPendienteCuota),
                    'fecha_empeno' => $empeno->fecha_empeno,
                    'fecha_vencimiento' => $empeno->fecha_vencimiento
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => $resultados
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en activosConSaldo: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener empeños: ' . $e->getMessage()
            ], 500);
        }
    }
}