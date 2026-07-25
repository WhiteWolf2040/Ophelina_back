<?php
// app/Http/Controllers/API/PagoController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Pago;
use App\Models\Empeno;
use App\Models\Amortizacio;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PagoController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $pagos = Pago::whereHas('empeno', function($query) use ($user) {
                    $query->where('id_empresa', $user->id_empresa);
                })
                ->with(['empeno.cliente', 'empeno.prenda', 'amortizacion'])
                ->orderBy('fecha_pago', 'desc')
                ->get()
                ->map(function ($pago) {
                    $clienteNombre = 'Cliente no disponible';
                    if ($pago->empeno && $pago->empeno->cliente) {
                        $clienteNombre = $pago->empeno->cliente->nombre . ' ' . $pago->empeno->cliente->apellido;
                    }

                    $articulo = 'Sin artículo';
                    if ($pago->empeno && $pago->empeno->prenda) {
                        $articulo = $pago->empeno->prenda->descripcion ?? 'Sin artículo';
                    }

                    $numeroPago = null;
                    if ($pago->amortizacion) {
                        $numeroPago = $pago->amortizacion->numero_pago ?? null;
                    }

                    return [
                        'id' => $pago->id_pago,
                        'cliente' => $clienteNombre,
                        'articulo' => $articulo,
                        'monto' => number_format($pago->monto_total ?? 0, 2),
                        'tipo' => ucfirst($pago->tipo_pago ?? 'Pago'),
                        'fecha' => $pago->fecha_pago ? date('d/m/Y', strtotime($pago->fecha_pago)) : null,
                        'metodo' => $pago->metodo_pago ?? 'Efectivo',
                        'id_empeno' => $pago->id_empeno,
                        'capital' => $pago->capital_pagado,
                        'interes' => $pago->interes_pagado,
                        'iva' => $pago->iva_pagado,
                        'numero_pago' => $numeroPago
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $pagos
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en PagoController@index: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener pagos: ' . $e->getMessage()
            ], 500);
        }
    }

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
     * Valida datos, busca la cuota pendiente más antigua y calcula intereses
     * de mora si hay atraso. Usado por el panel de la casa de empeño
     * (pagos en sucursal, no los de Stripe del cliente).
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

            /*
            Liquidación: paga la cuota completa.
            Abono: reduce capital (el cliente paga directamente a su deuda).
            Interés: solo paga intereses (mantiene el préstamo vivo).
            Prórroga: paga intereses + extiende el plazo.
            */

            switch ($request->tipo_pago) {
                case 'liquidacion':
                    $capitalPagado = $amortizacion->capital;
                    $interesPagado = $amortizacion->interes;
                    $ivaPagado = $amortizacion->iva_interes;
                    $montoTotalCalculado = $amortizacion->monto_total + $interesMora;
                    break;

                case 'abono':
                    // ABONO: solo reduce el capital, no paga intereses.
                    $capitalPagado = min($montoPagado, $amortizacion->capital);
                    $interesPagado = 0;
                    $ivaPagado = 0;
                    $montoTotalCalculado = $capitalPagado;

                    // ==================== FIX BUG ====================
                    // ANTES: se recalculaba el interés proporcional usando
                    // `$amortizacion->capital_original`, un atributo que NO
                    // existe en el modelo ni en la tabla -> División entre
                    // null -> DivisionByZeroError en cualquier abono real.
                    //
                    // AHORA: al reducir el capital, el interés y el IVA ya
                    // facturados NO se recalculan (el interés de una cuota
                    // ya generada no cambia solo porque se abone capital;
                    // eso solo pasaría si decidieran re-cotizar la cuota,
                    // lo cual es una decisión de negocio aparte). Solo se
                    // ajusta monto_total para reflejar el nuevo capital.
                    $nuevoCapital = max(0, $amortizacion->capital - $capitalPagado);
                    $amortizacion->capital = $nuevoCapital;
                    $amortizacion->monto_total = $nuevoCapital + $amortizacion->interes + $amortizacion->iva_interes;
                    // ===================================================
                    break;

                case 'interes':
                    $interesBase = $amortizacion->interes + $interesMora;
                    $interesPagado = min($montoPagado, $interesBase);
                    $ivaPagado = $interesPagado * 0.16;
                    $capitalPagado = 0;
                    $montoTotalCalculado = $interesPagado + $ivaPagado;
                    break;

                case 'prorroga':
                    // PRÓRROGA: paga intereses + IVA, y extiende la fecha
                    // de vencimiento (30 días), sincronizando también
                    // empeno.fecha_vencimiento vía Amortizacio::prorrogar().
                    $interesPagado = $amortizacion->interes + $interesMora;
                    $ivaPagado = $interesPagado * 0.16;
                    $capitalPagado = 0;
                    $montoTotalCalculado = $interesPagado + $ivaPagado;
                    break;

                default:
                    return response()->json([
                        'success' => false,
                        'message' => 'Tipo de pago no válido'
                    ], 400);
            }

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

            if ($request->tipo_pago === 'prorroga') {
                // Prórroga: no se toca monto_pagado/saldo, solo se extiende
                // el plazo (y se registra el pago de intereses de arriba).
                $amortizacion->prorrogar(30);
            } else {
                $nuevoMontoPagado = ($amortizacion->monto_pagado ?? 0) + $montoTotalCalculado;
                $nuevoSaldoPendiente = $amortizacion->monto_total - $nuevoMontoPagado;

                if ($interesMora > 0) {
                    $amortizacion->monto_total = $amortizacion->monto_total + $interesMora;
                    $nuevoSaldoPendiente = $amortizacion->monto_total - $nuevoMontoPagado;
                }

                $amortizacion->update([
                    'monto_pagado' => $nuevoMontoPagado,
                    'saldo_final' => max(0, $nuevoSaldoPendiente),
                    'fecha_pago_real' => $request->fecha_pago,
                    'estado' => $nuevoSaldoPendiente <= 0 ? 'pagado' : 'pendiente'
                ]);

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

    public function activosConSaldo(Request $request)
    {
        try {
            $user = $request->user();

            $empenos = Empeno::where('id_empresa', $user->id_empresa)
                ->where('estado', 'activo')
                ->with(['cliente', 'prenda'])
                ->get()
                ->map(function($empeno) {

                    $pagosRealizados = Pago::where('id_empeno', $empeno->id_empeno)->count();

                    $amortizacionPendiente = Amortizacio::where('id_empeno', $empeno->id_empeno)
                        ->where('estado', 'pendiente')
                        ->orderBy('numero_pago', 'asc')
                        ->first();

                    $saldoPendienteCuota = $amortizacionPendiente
                        ? ($amortizacionPendiente->monto_total - ($amortizacionPendiente->monto_pagado ?? 0))
                        : 0;

                    return [
                        'id_empeno' => $empeno->id_empeno,
                        'cliente' => $empeno->cliente->nombre . ' ' . $empeno->cliente->apellido,
                        'articulo' => $empeno->prenda->descripcion ?? 'Sin artículo',
                        'monto_prestado' => $empeno->monto_prestado,
                        'saldo_total_pendiente' => $empeno->monto_prestado - ($empeno->total_pagado ?? 0),
                        'saldo_pendiente_cuota' => $saldoPendienteCuota,
                        'fecha_empeno' => $empeno->fecha_empeno,
                        'fecha_vencimiento' => $empeno->fecha_vencimiento,
                        'pagos_realizados' => $pagosRealizados,
                        'total_pagado' => $empeno->total_pagado ?? 0
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $empenos
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar empeños: ' . $e->getMessage()
            ], 500);
        }
    }

    private function calcularInteresesMora($amortizacion, $fechaActual)
    {
        $fechaProgramada = $amortizacion->fecha_pago_programado;

        if ($fechaActual <= $fechaProgramada) {
            return 0;
        }

        $diasAtraso = $fechaActual->diffInDays($fechaProgramada);
        $tasaDiaria = $amortizacion->capital > 0
            ? ($amortizacion->interes / $amortizacion->capital) / 30
            : 0;

        $saldoPendiente = $amortizacion->saldo_pendiente;
        $interesMora = $saldoPendiente * $tasaDiaria * $diasAtraso;

        return round($interesMora, 2);
    }

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