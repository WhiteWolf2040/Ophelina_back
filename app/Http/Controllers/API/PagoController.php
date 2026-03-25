<?php
// app/Http/Controllers/API/PagoController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Pago;
use App\Models\Empeno;
use App\Models\Amortizacio;  // <--- Tu modelo se llama Amortizacio
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PagoController extends Controller
{
    /**
     * Obtener todos los pagos (FILTRADOS POR EMPRESA)
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            $pagos = Pago::whereHas('empeno', function($query) use ($user) {
                    $query->where('id_empresa', $user->id_empresa);
                })
                ->with('empeno.cliente', 'empeno.prenda', 'amortizacion')
                ->orderBy('fecha_pago', 'desc')
                ->get()
                ->map(function ($pago) {
                    return [
                        'id' => $pago->id_pago,
                        'cliente' => $pago->empeno->cliente->nombre . ' ' . $pago->empeno->cliente->apellido,
                        'articulo' => $pago->empeno->prenda->descripcion ?? 'Sin artículo',
                        'monto' => number_format($pago->monto_total ?? 0, 2),
                        'tipo' => ucfirst($pago->tipo_pago ?? 'Pago'),
                        'fecha' => date('d/m/Y', strtotime($pago->fecha_pago)),
                        'metodo' => $pago->metodo_pago ?? 'Efectivo',
                        'id_empeno' => $pago->id_empeno,
                        'capital' => $pago->capital_pagado,
                        'interes' => $pago->interes_pagado,
                        'iva' => $pago->iva_pagado,
                        'numero_pago' => $pago->amortizacion->numero_pago ?? null
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $pagos
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener pagos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un pago específico
     */
   // En PagoController.php - método show
public function show(Request $request, $id)
{
    try {
        $user = $request->user();
        
        $pago = Pago::whereHas('empeno', function($query) use ($user) {
                $query->where('id_empresa', $user->id_empresa);
            })
            ->with('empeno.cliente', 'empeno.prenda', 'amortizacion')
            ->findOrFail($id);

        $data = [
            'id' => $pago->id_pago,
            'cliente' => [
                'id' => $pago->empeno->cliente->id_cliente,
                'nombre' => $pago->empeno->cliente->nombre . ' ' . $pago->empeno->cliente->apellido,
                'telefono' => $pago->empeno->cliente->telefono,
                'correo' => $pago->empeno->cliente->correo
            ],
            'empeno' => [
                'id' => $pago->empeno->id_empeno,
                'folio' => $pago->empeno->folio,
                'monto_prestado' => $pago->empeno->monto_prestado,
                'fecha_empeno' => $pago->empeno->fecha_empeno,
                'fecha_vencimiento' => $pago->empeno->fecha_vencimiento,
                'estado' => $pago->empeno->estado,
                'prenda' => [
                    'descripcion' => $pago->empeno->prenda->descripcion ?? 'N/A',
                    'tipo' => $pago->empeno->prenda->tipo ?? 'N/A'
                ]
            ],
            'pago' => [
                'id_amortizacion' => $pago->id_amortizacion,
                'fecha' => $pago->fecha_pago,
                'capital' => $pago->capital_pagado,
                'interes' => $pago->interes_pagado,
                'iva' => $pago->iva_pagado,
                'monto_total' => $pago->monto_total,
                'tipo' => $pago->tipo_pago,
                'metodo' => $pago->metodo_pago,
                'referencia' => $pago->referencia
            ],
            'amortizacion' => $pago->amortizacion ? [
                'numero_pago' => $pago->amortizacion->numero_pago,
                'capital_original' => $pago->amortizacion->capital,
                'interes_original' => $pago->amortizacion->interes,
                'iva_original' => $pago->amortizacion->iva_interes,
                'monto_total_original' => $pago->amortizacion->monto_total,
                'saldo_pendiente' => $pago->amortizacion->saldo_pendiente,
                'fecha_programada' => $pago->amortizacion->fecha_pago_programado,
                'estado' => $pago->amortizacion->estado
            ] : null
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener pago: ' . $e->getMessage()
        ], 500);
    }
}
    /**
 * Crear un nuevo pago con lógica de amortización e intereses de mora
 */
public function store(Request $request)
{
    try {
        $user = $request->user();
        
        $request->validate([
            'id_empeno' => 'required|exists:empeno,id_empeno',
            'monto' => 'required|numeric|min:0',
            'fecha_pago' => 'required|date',
            'metodo_pago' => 'required|in:efectivo,transferencia,tarjeta,deposito',
            'tipo_pago' => 'required|in:interes,abono,liquidacion,prorroga'
        ]);

        $empeno = Empeno::where('id_empeno', $request->id_empeno)
            ->where('id_empresa', $user->id_empresa)
            ->first();
            
        if (!$empeno) {
            return response()->json([
                'success' => false,
                'message' => 'Empeño no encontrado'
            ], 404);
        }

        $amortizacion = Amortizacio::where('id_empeno', $request->id_empeno)
            ->where('estado', 'pendiente')
            ->orderBy('numero_pago', 'asc')
            ->first();

        if (!$amortizacion) {
            return response()->json([
                'success' => false,
                'message' => 'No hay pagos pendientes para este empeño'
            ], 400);
        }

        DB::beginTransaction();

        $fechaPago = new \Carbon\Carbon($request->fecha_pago);
        $fechaProgramada = new \Carbon\Carbon($amortizacion->fecha_pago_programado);
        
        $interesMora = 0;
        $diasAtraso = 0;
        
        if ($fechaPago > $fechaProgramada) {
            $diasAtraso = $fechaProgramada->diffInDays($fechaPago);
            $interesMora = $this->calcularInteresesMora($amortizacion, $fechaPago);
        }
        
        $montoPagado = floatval($request->monto);
        $capitalPagado = 0;
        $interesPagado = 0;
        $ivaPagado = 0;
        $montoTotalCalculado = 0;

        switch ($request->tipo_pago) {
            case 'liquidacion':
                // Paga toda la cuota + intereses de mora
                $capitalPagado = $amortizacion->capital;
                $interesPagado = $amortizacion->interes;
                $ivaPagado = $amortizacion->iva_interes;
                $montoTotalCalculado = $amortizacion->monto_total + $interesMora;
                break;
                
            case 'abono':
                // ABONO: Solo reduce el capital, NO paga intereses
                // El monto va directo a reducir la deuda principal
                $capitalPagado = min($montoPagado, $amortizacion->capital);
                $interesPagado = 0;
                $ivaPagado = 0;
                $montoTotalCalculado = $capitalPagado;
                
                // Actualizar el capital pendiente de la amortización
                $nuevoCapital = $amortizacion->capital - $capitalPagado;
                $amortizacion->capital = max(0, $nuevoCapital);
                
                // Recalcular el monto total de la cuota con el nuevo capital
                $nuevoInteres = $amortizacion->capital * ($amortizacion->interes / $amortizacion->capital_original ?? 0.10);
                $nuevoIva = $nuevoInteres * 0.16;
                $amortizacion->monto_total = $amortizacion->capital + $nuevoInteres + $nuevoIva;
                break;
                
            case 'interes':
                // Pago de intereses (puede incluir mora)
                $interesBase = $amortizacion->interes + $interesMora;
                $interesPagado = min($montoPagado, $interesBase);
                $ivaPagado = $interesPagado * 0.16;
                $capitalPagado = 0;
                $montoTotalCalculado = $interesPagado + $ivaPagado;
                break;
                
            case 'prorroga':
                // PRÓRROGA: Paga intereses + IVA, y EXTENDE la fecha de vencimiento
                // 1. Calcular intereses a pagar
                $interesPagado = $amortizacion->interes + $interesMora;
                $ivaPagado = $interesPagado * 0.16;
                $capitalPagado = 0;
                $montoTotalCalculado = $interesPagado + $ivaPagado;
                
                // 2. EXTENDER la fecha de vencimiento (ej: 30 días más)
                $nuevaFecha = $fechaProgramada->addDays(30);
                $amortizacion->fecha_pago_programado = $nuevaFecha;
                
                // 3. Resetear el estado de la amortización
                $amortizacion->estado = 'pendiente';
                break;
                
            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Tipo de pago no válido'
                ], 400);
        }

        // Si es abono, actualizar el capital y recalcular
        if ($request->tipo_pago === 'abono') {
            $amortizacion->capital = max(0, $amortizacion->capital - $capitalPagado);
        }

        // Crear el registro de pago
        $pago = Pago::create([
            'id_empeno' => $request->id_empeno,
            'id_amortizacion' => $amortizacion->id_amortizacion,
            'fecha_pago' => $request->fecha_pago,
            'capital_pagado' => $capitalPagado,
            'interes_pagado' => $interesPagado,
            'iva_pagado' => $ivaPagado,
            'monto_total' => $montoTotalCalculado,
            'tipo_pago' => $request->tipo_pago,
            'metodo_pago' => $request->metodo_pago,
            'referencia' => $request->referencia,
            'fecha_registro' => now()
        ]);

        // Actualizar la amortización
        $nuevoMontoPagado = ($amortizacion->monto_pagado ?? 0) + $montoTotalCalculado;
        $nuevoSaldoPendiente = $amortizacion->monto_total - $nuevoMontoPagado;
        
        if ($interesMora > 0 && $request->tipo_pago !== 'prorroga') {
            $amortizacion->monto_total = $amortizacion->monto_total + $interesMora;
        }
        
        $amortizacion->update([
            'monto_pagado' => $nuevoMontoPagado,
            'saldo_final' => max(0, $nuevoSaldoPendiente),
            'fecha_pago_real' => $request->fecha_pago,
            'estado' => $nuevoSaldoPendiente <= 0 ? 'pagado' : 'pendiente'
        ]);

        // Si se pagó completamente esta amortización
        if ($nuevoSaldoPendiente <= 0) {
            $siguienteAmortizacion = Amortizacio::where('id_empeno', $request->id_empeno)
                ->where('estado', 'pendiente')
                ->where('numero_pago', '>', $amortizacion->numero_pago)
                ->orderBy('numero_pago', 'asc')
                ->first();
            
            if (!$siguienteAmortizacion) {
                $empeno->estado = 'pagado';
                $empeno->save();
            }
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Pago registrado exitosamente',
            'data' => [
                'pago' => $pago,
                'amortizacion' => [
                    'id' => $amortizacion->id_amortizacion,
                    'numero_pago' => $amortizacion->numero_pago,
                    'capital' => $amortizacion->capital,
                    'monto_pagado' => $amortizacion->monto_pagado,
                    'saldo_pendiente' => $amortizacion->saldo_pendiente,
                    'estado' => $amortizacion->estado,
                    'dias_atraso' => $diasAtraso,
                    'interes_mora' => $interesMora,
                    'nueva_fecha_vencimiento' => $amortizacion->fecha_pago_programado ?? null
                ]
            ]
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Error al registrar pago: ' . $e->getMessage()
        ], 500);
    }
}
    /**
     * Eliminar un pago y revertir la amortización
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            $pago = Pago::whereHas('empeno', function($query) use ($user) {
                    $query->where('id_empresa', $user->id_empresa);
                })
                ->with('amortizacion')
                ->findOrFail($id);
                
            DB::beginTransaction();
            
            // Revertir la amortización
            $amortizacion = $pago->amortizacion;
            if ($amortizacion) {
                $nuevoMontoPagado = ($amortizacion->monto_pagado ?? 0) - $pago->monto_total;
                $nuevoSaldoPendiente = $amortizacion->monto_total - max(0, $nuevoMontoPagado);
                
                $amortizacion->update([
                    'monto_pagado' => max(0, $nuevoMontoPagado),
                    'saldo_final' => $nuevoSaldoPendiente,
                    'fecha_pago_real' => null,
                    'estado' => $nuevoSaldoPendiente > 0 ? 'pendiente' : 'pagado'
                ]);
                
                // Si la amortización vuelve a estar pendiente, actualizar estado del empeño
                if ($nuevoSaldoPendiente > 0) {
                    $empeno = Empeno::find($pago->id_empeno);
                    if ($empeno && $empeno->estado === 'pagado') {
                        $empeno->estado = 'activo';
                        $empeno->save();
                    }
                }
            }
            
            $pago->delete();
            
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Pago eliminado exitosamente'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar pago: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener pagos por cliente
     */
    public function porCliente(Request $request, $id_cliente)
    {
        try {
            $user = $request->user();
            
            $cliente = Cliente::where('id_cliente', $id_cliente)
                ->where('id_empresa', $user->id_empresa)
                ->first();
                
            if (!$cliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado'
                ], 404);
            }
            
            $pagos = Pago::whereHas('empeno', function($query) use ($id_cliente, $user) {
                    $query->where('id_cliente', $id_cliente)
                        ->where('id_empresa', $user->id_empresa);
                })
                ->with('empeno.prenda', 'amortizacion')
                ->orderBy('fecha_pago', 'desc')
                ->get()
                ->map(function ($pago) {
                    return [
                        'id' => $pago->id_pago,
                        'fecha' => date('d/m/Y', strtotime($pago->fecha_pago)),
                        'monto' => $pago->monto_total,
                        'capital' => $pago->capital_pagado,
                        'interes' => $pago->interes_pagado,
                        'iva' => $pago->iva_pagado,
                        'tipo' => $pago->tipo_pago,
                        'articulo' => $pago->empeno->prenda->descripcion ?? 'N/A',
                        'folio' => $pago->empeno->folio,
                        'numero_pago' => $pago->amortizacion->numero_pago ?? null
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $pagos
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener pagos del cliente: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
 * Calcular intereses por días de atraso
 */
private function calcularInteresesMora($amortizacion, $fechaActual)
{
    $fechaProgramada = $amortizacion->fecha_pago_programado;
    
    if ($fechaActual <= $fechaProgramada) {
        return 0;
    }
    
    $diasAtraso = $fechaActual->diffInDays($fechaProgramada);
    $tasaDiaria = ($amortizacion->interes / $amortizacion->capital) / 30; // Tasa diaria
    
    // Interés por cada día de atraso sobre el saldo pendiente
    $saldoPendiente = $amortizacion->saldo_pendiente;
    $interesMora = $saldoPendiente * $tasaDiaria * $diasAtraso;
    
    return round($interesMora, 2);
}

/**
 * Contar pagos realizados para un empeño
 * GET /api/pagos/empeno/{id_empeno}/count
 */
public function countByEmpeno($id_empeno)
{
    try {
        $total = Pago::where('id_empeno', $id_empeno)->count();
        
        return response()->json([
            'success' => true,
            'data' => [
                'total' => $total
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al contar pagos'
        ], 500);
    }
}


}