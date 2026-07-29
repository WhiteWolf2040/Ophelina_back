<?php
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

            return response()->json(['success' => true, 'data' => $pagos]);

        } catch (\Exception $e) {
            \Log::error('Error en PagoController@index: ' . $e->getMessage());
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

            return response()->json(['success' => true, 'data' => $data]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener pago: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ NUEVO: misma regla que AbonoController@refrendoEsElegible, para que
     * un empleado en sucursal no pueda registrar más refrendos de los que
     * el cliente ya podría pagar en línea.
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

        return [
            'elegible' => $refrendosPagados < $refrendosPermitidos && $mesesTranscurridos > $refrendosPagados,
            'refrendos_pagados' => $refrendosPagados,
            'refrendos_permitidos' => $refrendosPermitidos,
        ];
    }

    public function store(Request $request)
    {
        try {
            $user = $request->user();

            $request->validate([
                'id_empeno' => 'required|exists:empeno,id_empeno',
                'monto' => 'required|numeric|min:0',
                'fecha_pago' => 'required|date',
                'metodo_pago' => 'required|in:efectivo,transferencia,tarjeta,deposito',
                // ✅ NUEVO: 'refrendo' agregado
                'tipo_pago' => 'required|in:interes,abono,liquidacion,prorroga,refrendo'
            ]);

            $empeno = Empeno::where('id_empeno', $request->id_empeno)
                ->where('id_empresa', $user->id_empresa)
                ->first();

            if (!$empeno) {
                return response()->json(['success' => false, 'message' => 'Empeño no encontrado'], 404);
            }

            $amortizacion = Amortizacio::where('id_empeno', $request->id_empeno)
                ->where('estado', 'pendiente')
                ->orderBy('numero_pago', 'asc')
                ->first();

            if (!$amortizacion) {
                return response()->json(['success' => false, 'message' => 'No hay pagos pendientes para este empeño'], 400);
            }

            // ✅ NUEVO: valida elegibilidad de refrendo ANTES de abrir transacción
            if ($request->tipo_pago === 'refrendo') {
                $plazoMeses = $empeno->plazo_meses ?? 1;

                if ($plazoMeses <= 1) {
                    return response()->json(['success' => false, 'message' => 'Este empeño es de un solo periodo; no aplica refrendo.'], 422);
                }
                if ($empeno->estado === 'vencido') {
                    return response()->json(['success' => false, 'message' => 'Este empeño ya venció; registra una prórroga en vez de un refrendo.'], 422);
                }

                $elegibilidad = $this->refrendoEsElegible($empeno);
                if (!$elegibilidad['elegible']) {
                    $mensaje = $elegibilidad['refrendos_pagados'] >= $elegibilidad['refrendos_permitidos']
                        ? 'Ya se pagaron todos los refrendos disponibles para este empeño.'
                        : 'Aún no corresponde el siguiente refrendo mensual.';
                    return response()->json(['success' => false, 'message' => $mensaje], 422);
                }
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
                    $capitalPagado = $amortizacion->capital;
                    $interesPagado = $amortizacion->interes;
                    $ivaPagado = $amortizacion->iva_interes;
                    $montoTotalCalculado = $amortizacion->monto_total + $interesMora;
                    break;

                case 'abono':
                    // ✅ CORREGIDO: monto_total ya NO se reasigna aquí. Antes se
                    // sobreescribía con el remanente reducido, y el cálculo de
                    // saldo_final de más abajo (que resta usando monto_total
                    // como referencia FIJA) quedaba mal — restaba el pago dos
                    // veces. Ahora monto_total se queda como referencia
                    // original intacta; solo se reduce el desglose
                    // (capital/interes/iva) para que cotización lo muestre bien.
                    $deudaTotal = round((float) $amortizacion->capital + $amortizacion->interes + $amortizacion->iva_interes, 2);

                    if ($deudaTotal > 0) {
                        $capitalPagado = round($montoPagado * ($amortizacion->capital / $deudaTotal), 2);
                        $ivaPagado = round($montoPagado * ($amortizacion->iva_interes / $deudaTotal), 2);
                        $interesPagado = round($montoPagado - $capitalPagado - $ivaPagado, 2);
                    } else {
                        $capitalPagado = $interesPagado = $ivaPagado = 0;
                    }

                    $montoTotalCalculado = $ivaPagado + $interesPagado + $capitalPagado;

                    $amortizacion->capital = max(0, round($amortizacion->capital - $capitalPagado, 2));
                    $amortizacion->interes = max(0, round($amortizacion->interes - $interesPagado, 2));
                    $amortizacion->iva_interes = max(0, round($amortizacion->iva_interes - $ivaPagado, 2));
                    break;

                case 'interes':
                    $interesBase = $amortizacion->interes + $interesMora;
                    $interesPagado = min($montoPagado, $interesBase);
                    $ivaPagado = $interesPagado * 0.16;
                    $capitalPagado = 0;
                    $montoTotalCalculado = $interesPagado + $ivaPagado;
                    break;

                case 'prorroga':
                    $interesPagado = $amortizacion->interes + $interesMora;
                    $ivaPagado = $interesPagado * 0.16;
                    $capitalPagado = 0;
                    $montoTotalCalculado = $interesPagado + $ivaPagado;
                    break;

                case 'refrendo':
                    // ✅ NUEVO: mismo criterio que registrarRefrendoWeb en el
                    // webhook de Stripe — paga interés+IVA del periodo, sin
                    // tocar capital ni mover fecha de vencimiento.
                    $ivaPagado = round($montoPagado - ($montoPagado / 1.16), 2);
                    $interesPagado = round($montoPagado - $ivaPagado, 2);
                    $capitalPagado = 0;
                    $montoTotalCalculado = $montoPagado;

                    $amortizacion->interes = max(0, round($amortizacion->interes - $interesPagado, 2));
                    $amortizacion->iva_interes = max(0, round($amortizacion->iva_interes - $ivaPagado, 2));
                    break;

                default:
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Tipo de pago no válido'], 400);
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

            return response()->json(['success' => true, 'message' => 'Pago eliminado exitosamente']);

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
                return response()->json(['success' => false, 'message' => 'Cliente no encontrado'], 404);
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

            return response()->json(['success' => true, 'data' => $pagos]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener pagos del cliente: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ⚡ OPTIMIZADO: la versión anterior hacía, dentro del foreach, una
     * consulta DB::table('amortizacion')->where(...)->first() POR CADA
     * empeño — es decir, N+1 queries (si tienes 200 empeños activos, eran
     * 201 queries a la BD en cada carga de esta pantalla).
     *
     * Ahora:
     * 1. 'pagos' se reemplaza por withSum(), que hace la suma en la propia
     *    BD (un JOIN/subquery) en vez de traer todas las filas de pagos a
     *    PHP solo para sumarlas aquí.
     * 2. Las amortizaciones pendientes de TODOS los empeños se traen en
     *    UNA sola consulta (whereIn), agrupadas en memoria por id_empeno,
     *    y luego se les hace lookup O(1) en el map() — cero queries
     *    adicionales dentro del loop.
     *
     * Resultado: de N+1 consultas a 2 consultas totales, sin importar
     * cuántos empeños tenga la empresa.
     */
    public function activosConSaldo(Request $request)
    {
        try {
            $user = $request->user();

            $empenos = Empeno::where('id_empresa', $user->id_empresa)
                ->with(['cliente:id_cliente,nombre,apellido', 'prenda:id_prenda,descripcion'])
                ->withSum('pagos as total_pagado', 'monto_total')
                ->get();

            $idsEmpenos = $empenos->pluck('id_empeno');

            // Una sola consulta trae la primera amortización pendiente de
            // cada empeño (ordenada por numero_pago), agrupada en memoria.
            $amortizacionesPendientesPorEmpeno = Amortizacio::whereIn('id_empeno', $idsEmpenos)
                ->where('estado', 'pendiente')
                ->orderBy('numero_pago', 'asc')
                ->get()
                ->groupBy('id_empeno')
                ->map(fn ($grupo) => $grupo->first());

            $resultados = $empenos->map(function (Empeno $empeno) use ($amortizacionesPendientesPorEmpeno) {
                $totalPagado = $empeno->total_pagado ?? 0;

                $amortizacionPendiente = $amortizacionesPendientesPorEmpeno->get($empeno->id_empeno);

                $saldoPendienteCuota = $amortizacionPendiente
                    ? (($amortizacionPendiente->monto_total ?? 0) - ($amortizacionPendiente->monto_pagado ?? 0))
                    : 0;

                $saldoTotalPendiente = max(0, ($empeno->monto_prestado ?? 0) - $totalPagado);

                $estadoReal = $empeno->estado_real;
                $diasVencidos = $empeno->dias_vencidos;

                return [
                    'id_empeno' => $empeno->id_empeno,
                    'cliente' => $empeno->cliente ? $empeno->cliente->nombre . ' ' . $empeno->cliente->apellido : 'Cliente no disponible',
                    'articulo' => $empeno->prenda ? $empeno->prenda->descripcion : 'Sin artículo',
                    'monto_prestado' => floatval($empeno->monto_prestado ?? 0),
                    'total_pagado' => floatval($totalPagado),
                    'saldo_total_pendiente' => floatval($saldoTotalPendiente),
                    'saldo_pendiente_cuota' => floatval($saldoPendienteCuota),
                    'fecha_empeno' => $empeno->fecha_empeno,
                    'fecha_vencimiento' => $empeno->fecha_vencimiento,
                    'estado' => $estadoReal,
                    'dias_vencidos' => $diasVencidos
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $resultados->values()
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en activosConSaldo: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener empeños: ' . $e->getMessage()
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

        $tasaMensual = (float) optional($amortizacion->empeno->tasa)->porcentaje ?? 0;
        $tasaDiaria = ($tasaMensual / 100) / 30;

        $saldoPendiente = $amortizacion->saldo_pendiente;
        $interesMora = $saldoPendiente * $tasaDiaria * $diasAtraso;

        return round($interesMora, 2);
    }

    public function countByEmpeno($id_empeno)
    {
        try {
            $total = Pago::where('id_empeno', $id_empeno)->count();
            return response()->json(['success' => true, 'data' => ['total' => $total]]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al contar pagos'], 500);
        }
    }
}