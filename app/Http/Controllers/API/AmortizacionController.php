<?php
// app/Http/Controllers/API/AmortizacionController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Amortizacio;
use App\Models\Empeno;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AmortizacionController extends Controller
{
    /**
     * Obtener la amortización pendiente de un empeño
     * GET /api/amortizacion/pendiente/{id_empeno}
     */
    // app/Http/Controllers/API/AmortizacionController.php

public function pendiente(Request $request, $id_empeno)
{
    try {
        $user = $request->user();
        
        // Verificar que el empeño pertenezca a la empresa del usuario
        $empeno = Empeno::where('id_empeno', $id_empeno)
            ->where('id_empresa', $user->id_empresa)
            ->first();
            
        if (!$empeno) {
            return response()->json([
                'success' => false,
                'message' => 'Empeño no encontrado'
            ], 404);
        }
        
        // Buscar la primera cuota que tenga saldo pendiente
        $amortizacion = Amortizacio::where('id_empeno', $id_empeno)
            ->whereRaw('monto_total > IFNULL(monto_pagado, 0)')
            ->orderBy('numero_pago', 'asc')
            ->first();
        
        if (!$amortizacion) {
            return response()->json([
                'success' => false,
                'message' => 'No hay pagos pendientes para este empeño'
            ], 404);
        }
        
        // Calcular saldo pendiente (monto_total - lo que ya se pagó)
        $montoPagado = $amortizacion->monto_pagado ?? 0;
        $saldoPendiente = $amortizacion->monto_total - $montoPagado;
        
        return response()->json([
            'success' => true,
            'data' => [
                'id_amortizacion' => $amortizacion->id_amortizacion,
                'numero_pago' => $amortizacion->numero_pago,
                'capital' => floatval($amortizacion->capital),
                'interes' => floatval($amortizacion->interes),
                'iva_interes' => floatval($amortizacion->iva_interes),
                'monto_total' => floatval($amortizacion->monto_total),
                'monto_pagado' => floatval($montoPagado),  // <--- CONVERTIR A NÚMERO
                'saldo_pendiente' => floatval(max(0, $saldoPendiente)),  // <--- CONVERTIR A NÚMERO
                'fecha_programada' => $amortizacion->fecha_pago_programado,
                'dias_retraso' => $amortizacion->dias_retraso,
                'esta_vencido' => $amortizacion->esta_vencido
            ]
        ]);
        
    } catch (\Exception $e) {
            
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener amortización: ' . $e->getMessage()
        ], 500);
    }
}
    
    /**
     * Obtener todas las amortizaciones de un empeño
     * GET /api/amortizacion/empeno/{id_empeno}
     */
    public function porEmpeno(Request $request, $id_empeno)
    {
        try {
            $user = $request->user();
            
            $empeno = Empeno::where('id_empeno', $id_empeno)
                ->where('id_empresa', $user->id_empresa)
                ->first();
                
            if (!$empeno) {
                return response()->json([
                    'success' => false,
                    'message' => 'Empeño no encontrado'
                ], 404);
            }
            
            $amortizaciones = Amortizacio::where('id_empeno', $id_empeno)
                ->orderBy('numero_pago', 'asc')
                ->get()
                ->map(function ($amortizacion) {
                    return [
                        'id' => $amortizacion->id_amortizacion,
                        'numero_pago' => $amortizacion->numero_pago,
                        'fecha_programada' => $amortizacion->fecha_pago_programado,
                        'fecha_real' => $amortizacion->fecha_pago_real,
                        'capital' => $amortizacion->capital,
                        'interes' => $amortizacion->interes,
                        'iva' => $amortizacion->iva_interes,
                        'monto_total' => $amortizacion->monto_total,
                        'monto_pagado' => $amortizacion->monto_pagado,
                        'saldo_pendiente' => $amortizacion->saldo_pendiente,
                        'estado' => $amortizacion->estado,
                        'dias_retraso' => $amortizacion->dias_retraso
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $amortizaciones
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener amortizaciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}