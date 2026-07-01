<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $idEmpresa = $user->id_empresa;

            // Ganancias totales
            $gananciaTotal = DB::table('pagos')
                ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
                ->where('empeno.id_empresa', $idEmpresa)
                ->where('pagos.tipo_pago', 'liquidacion')
                ->sum('pagos.interes_pagado');

            // Pérdidas totales
            $perdidaTotal = DB::table('amortizacion')
                ->join('empeno', 'empeno.id_empeno', '=', 'amortizacion.id_empeno')
                ->where('amortizacion.estado', 'pendiente')
                ->where('amortizacion.fecha_pago_programado', '<', now())
                ->where('empeno.id_empresa', $idEmpresa)
                ->select(DB::raw('SUM(amortizacion.monto_total - COALESCE(amortizacion.monto_pagado, 0)) as total'))
                ->first();

            $perdidaTotal = $perdidaTotal ? floatval($perdidaTotal->total) : 0;

            // Ingresos del mes actual
            $ingresosMesActual = DB::table('pagos')
                ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
                ->where('empeno.id_empresa', $idEmpresa)
                ->whereYear('pagos.fecha_pago', now()->year)
                ->whereMonth('pagos.fecha_pago', now()->month)
                ->sum('pagos.monto_total');

            // Empeños activos
            $empenosActivos = DB::table('empeno')
                ->where('estado', 'activo')
                ->where('id_empresa', $idEmpresa)
                ->count();

            // Empeños vencidos
            $empenosVencidos = DB::table('empeno')
                ->where('estado', 'vencido')
                ->where('id_empresa', $idEmpresa)
                ->count();

            // Próximos a vencer
            $proximosVencer = DB::table('empeno')
                ->whereBetween('fecha_vencimiento', [now(), now()->addDays(7)])
                ->where('estado', 'activo')
                ->where('id_empresa', $idEmpresa)
                ->count();

            // Ingresos recientes
            $ingresosRecientes = DB::table('pagos')
                ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
                ->where('empeno.id_empresa', $idEmpresa)
                ->whereDate('pagos.fecha_pago', '>=', now()->subDays(15))
                ->sum('pagos.monto_total');

            // Total clientes
            $totalClientes = DB::table('clientes')
                ->where('id_empresa', $idEmpresa)
                ->count();

            // Prendas disponibles
            $prendasDisponibles = DB::table('prendas')
                ->where('estado', 'Disponible')
                ->where('id_empresa', $idEmpresa)
                ->count();

            // Precio oro
            $precioOro = DB::table('precio_oro')
                ->orderBy('fecha_actualizacion', 'desc')
                ->first();

            $resumen = [
                "empenos_activos" => $empenosActivos,
                "empenos_vencidos" => $empenosVencidos,
                "proximos_vencer" => $proximosVencer,
                "ingresos_recientes" => floatval($ingresosRecientes),
                "precio_oro" => $precioOro->precio_gramo_24k ?? 850,
                "ultima_actualizacion_oro" => $precioOro->fecha_actualizacion ?? null,
                "total_clientes" => $totalClientes,
                "prendas_disponibles" => $prendasDisponibles,
                "ganancia_total" => floatval($gananciaTotal),
                "perdida_total" => $perdidaTotal,
                "ingresos_mes_actual" => floatval($ingresosMesActual)
            ];

            // Top Clientes
            $topClientes = DB::table('empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->leftJoin('pagos', function($join) {
                    $join->on('pagos.id_empeno', '=', 'empeno.id_empeno')
                         ->where('pagos.tipo_pago', 'liquidacion');
                })
                ->where('empeno.id_empresa', $idEmpresa)
                ->select(
                    'clientes.id_cliente',
                    DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as nombre"),
                    DB::raw("COUNT(DISTINCT empeno.id_empeno) as empenos"),
                    DB::raw("SUM(empeno.monto_prestado) as monto_total"),
                    DB::raw("SUM(COALESCE(pagos.interes_pagado, 0)) as ganancia_realizada"),
                    DB::raw("MAX(empeno.fecha_empeno) as ultimo_empeno")
                )
                ->groupBy('clientes.id_cliente', 'clientes.nombre', 'clientes.apellido')
                ->orderByDesc('ganancia_realizada')
                ->limit(5)
                ->get();

            $topClientes = $topClientes->map(function($cliente) {
                $montoTotal = floatval($cliente->monto_total);
                $gananciaRealizada = floatval($cliente->ganancia_realizada);
                $cliente->porcentaje_ganancia = $montoTotal > 0
                    ? ($gananciaRealizada / $montoTotal) * 100
                    : 0;
                $cliente->ganancia_generada = $gananciaRealizada;
                $cliente->monto_total = $montoTotal;
                return $cliente;
            });

            // Top Artículos
            $topArticulos = DB::table('empeno')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.id_empresa', $idEmpresa)
                ->select(
                    'prendas.descripcion as nombre',
                    'prendas.tipo as categoria',
                    DB::raw("COUNT(empeno.id_prenda) as cantidad"),
                    DB::raw("AVG(empeno.monto_prestado) as monto_promedio")
                )
                ->groupBy('prendas.descripcion', 'prendas.tipo')
                ->orderByDesc('cantidad')
                ->limit(5)
                ->get();

            // Actividad reciente
            $actividad = DB::table('pagos')
                ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->where('empeno.id_empresa', $idEmpresa)
                ->select(
                    DB::raw("'pago' as tipo"),
                    DB::raw("CONCAT('Pago recibido de ',clientes.nombre,' ',clientes.apellido) as descripcion"),
                    'pagos.fecha_pago as fecha',
                    'pagos.monto_total as monto'
                )
                ->orderByDesc('pagos.fecha_pago')
                ->limit(10)
                ->get();

            $actividad = $actividad->map(function($item) {
                $item->fecha = $item->fecha ? date('d/m/Y', strtotime($item->fecha)) : '';
                return $item;
            });

            // ✅ CAPITAL vs RETORNO POR MES (CORREGIDO PARA POSTGRESQL)
            $prestamosPorMes = DB::table('empeno')
                ->select(
                    DB::raw("EXTRACT(MONTH FROM fecha_empeno) as numero_mes"),
                    DB::raw("TO_CHAR(fecha_empeno, 'Mon') as mes"),
                    DB::raw("SUM(monto_prestado) as capital"),
                    DB::raw("COUNT(id_empeno) as total_empenos")
                )
                ->whereYear('fecha_empeno', date('Y'))
                ->where('id_empresa', $idEmpresa)
                ->groupBy(DB::raw("EXTRACT(MONTH FROM fecha_empeno)"), DB::raw("TO_CHAR(fecha_empeno, 'Mon')"))
                ->orderBy(DB::raw("EXTRACT(MONTH FROM fecha_empeno)"))
                ->get();

            $pagosPorMes = DB::table('pagos')
                ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
                ->select(
                    DB::raw("EXTRACT(MONTH FROM pagos.fecha_pago) as numero_mes"),
                    DB::raw("SUM(pagos.monto_total) as total_pagos"),
                    DB::raw("SUM(pagos.interes_pagado) as total_intereses")
                )
                ->whereYear('pagos.fecha_pago', date('Y'))
                ->where('empeno.id_empresa', $idEmpresa)
                ->groupBy(DB::raw("EXTRACT(MONTH FROM pagos.fecha_pago)"))
                ->get()
                ->keyBy('numero_mes');

            // Calcular valores ACUMULADOS
            $capitalAcumulado = 0;
            $retornoAcumulado = 0;
            $gananciaAcumulada = 0;

            $capitalRetorno = $prestamosPorMes->map(function($prestamo) use (&$capitalAcumulado, &$retornoAcumulado, &$gananciaAcumulada, $pagosPorMes) {
                $mesNumero = $prestamo->numero_mes;
                $pagos = $pagosPorMes->get($mesNumero);

                $capitalAcumulado += floatval($prestamo->capital);
                $retornoAcumulado += $pagos ? floatval($pagos->total_pagos) : 0;
                $gananciaAcumulada += $pagos ? floatval($pagos->total_intereses) : 0;

                $prestamo->capital = $capitalAcumulado;
                $prestamo->retorno = $retornoAcumulado;
                $prestamo->ganancia = $gananciaAcumulada;

                return $prestamo;
            });

            return response()->json([
                "success" => true,
                "data" => [
                    "resumen" => $resumen,
                    "top_clientes" => $topClientes,
                    "top_articulos" => $topArticulos,
                    "actividad_reciente" => $actividad,
                    "capital_retorno" => $capitalRetorno
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error al cargar el dashboard",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    // ====================================
    // LISTADOS DETALLADOS
    // ====================================

    public function activos(Request $request)
    {
        try {
            $user = $request->user();

            $data = DB::table('empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.estado', 'activo')
                ->where('empeno.id_empresa', $user->id_empresa)
                ->select(
                    'empeno.id_empeno',
                    DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as cliente"),
                    'prendas.descripcion as nombre',
                    'empeno.monto_prestado as monto',
                    'empeno.fecha_empeno as fecha'
                )
                ->get();

            return response()->json([
                "success" => true,
                "data" => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
            ], 500);
        }
    }

    public function vencidos(Request $request)
    {
        try {
            $user = $request->user();

            $data = DB::table('empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.estado', 'vencido')
                ->where('empeno.id_empresa', $user->id_empresa)
                ->select(
                    'empeno.id_empeno',
                    DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as cliente"),
                    'prendas.descripcion as nombre',
                    'empeno.monto_prestado as monto',
                    'empeno.fecha_vencimiento as fecha',
                    DB::raw("EXTRACT(DAY FROM (NOW() - empeno.fecha_vencimiento)) as dias")
                )
                ->get();

            return response()->json([
                "success" => true,
                "data" => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
            ], 500);
        }
    }

    public function proximos(Request $request)
    {
        try {
            $user = $request->user();

            $data = DB::table('empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.estado', 'activo')
                ->where('empeno.id_empresa', $user->id_empresa)
                ->whereBetween('empeno.fecha_vencimiento', [now(), now()->addDays(7)])
                ->select(
                    'empeno.id_empeno',
                    DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as cliente"),
                    'prendas.descripcion as nombre',
                    'empeno.monto_prestado as monto',
                    'empeno.fecha_vencimiento as fecha',
                    DB::raw("EXTRACT(DAY FROM (empeno.fecha_vencimiento - NOW())) as dias")
                )
                ->get();

            return response()->json([
                "success" => true,
                "data" => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
            ], 500);
        }
    }

    public function morosidad(Request $request)
    {
        try {
            $user = $request->user();
            $idEmpresa = $user->id_empresa;

            // ✅ Consulta SQL corregida para PostgreSQL
            $morosos = DB::select("
                SELECT 
                    CONCAT(c.nombre, ' ', c.apellido) as nombre,
                    SUM(e.monto_prestado) as total_prestado,
                    SUM(a.monto_total - COALESCE(a.monto_pagado, 0)) as deuda,
                    COUNT(DISTINCT a.id_amortizacion) as pagos_atrasados,
                    MIN(a.fecha_pago_programado) as fecha_mas_antigua,
                    MAX(p.fecha_pago) as ultimo_pago_real
                FROM amortizacion a
                INNER JOIN empeno e ON e.id_empeno = a.id_empeno
                INNER JOIN clientes c ON c.id_cliente = e.id_cliente
                LEFT JOIN pagos p ON p.id_empeno = e.id_empeno
                WHERE a.estado = 'pendiente'
                AND a.fecha_pago_programado < NOW()
                AND e.id_empresa = ?
                GROUP BY c.id_cliente, c.nombre, c.apellido
                HAVING deuda > 0
                ORDER BY deuda DESC
                LIMIT 10
            ", [$idEmpresa]);

            if (empty($morosos)) {
                return response()->json([
                    "success" => true,
                    "data" => []
                ]);
            }

            $morosidadFormateada = [];
            foreach ($morosos as $item) {
                $totalPrestado = floatval($item->total_prestado);
                $deuda = floatval($item->deuda);

                $porcentajePerdida = 0;
                if ($totalPrestado > 0 && $deuda > 0) {
                    $porcentajePerdida = ($deuda / $totalPrestado) * 100;
                    if ($porcentajePerdida > 100) {
                        $porcentajePerdida = 100;
                    }
                }

                $diasMora = 0;
                if ($item->fecha_mas_antigua) {
                    $fechaVencimiento = new \Carbon\Carbon($item->fecha_mas_antigua);
                    $diasMora = $fechaVencimiento->diffInDays(now());
                }

                $ultimoPagoFormateado = '';
                if ($item->ultimo_pago_real) {
                    try {
                        $fechaReal = new \Carbon\Carbon($item->ultimo_pago_real);
                        $ultimoPagoFormateado = $fechaReal->format('d/m/Y');
                    } catch (\Exception $e) {
                        $ultimoPagoFormateado = '';
                    }
                }

                $morosidadFormateada[] = [
                    'nombre' => $item->nombre,
                    'total_prestado' => $totalPrestado,
                    'deuda' => $deuda,
                    'perdida_proyectada' => $deuda,
                    'porcentaje_perdida' => round($porcentajePerdida, 2),
                    'pagos_atrasados' => $item->pagos_atrasados,
                    'dias_mora' => $diasMora,
                    'ultimo_pago' => $ultimoPagoFormateado
                ];
            }

            return response()->json([
                "success" => true,
                "data" => $morosidadFormateada
            ]);

        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => $e->getMessage()
            ], 500);
        }
    }

    public function distribucionCategorias(Request $request)
    {
        try {
            $user = $request->user();

            $categorias = DB::table('prendas')
                ->join('empeno', 'empeno.id_prenda', '=', 'prendas.id_prenda')
                ->where('empeno.id_empresa', $user->id_empresa)
                ->whereYear('empeno.fecha_empeno', date('Y'))
                ->select('prendas.tipo as categoria', DB::raw('COUNT(empeno.id_empeno) as total'))
                ->groupBy('prendas.tipo')
                ->get();

            if ($categorias->isEmpty()) {
                $categorias = collect([
                    ['categoria' => 'Joyería', 'total' => 0],
                    ['categoria' => 'Electrónica', 'total' => 0],
                    ['categoria' => 'Relojes', 'total' => 0],
                    ['categoria' => 'Herramientas', 'total' => 0],
                    ['categoria' => 'Instrumentos', 'total' => 0]
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $categorias
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function amortizacionPendiente(Request $request)
    {
        try {
            $user = $request->user();
            $idEmpresa = $user->id_empresa;

            $amortizaciones = DB::table('amortizacion')
                ->join('empeno', 'empeno.id_empeno', '=', 'amortizacion.id_empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.id_empresa', $idEmpresa)
                ->where('amortizacion.estado', 'pendiente')
                ->select(
                    'amortizacion.id_amortizacion',
                    'amortizacion.numero_pago',
                    'amortizacion.fecha_pago_programado',
                    'amortizacion.monto_total',
                    'amortizacion.monto_pagado',
                    'amortizacion.saldo_final',
                    DB::raw("CONCAT(clientes.nombre, ' ', COALESCE(clientes.apellido, '')) as cliente_nombre"),
                    'prendas.descripcion as articulo',
                    'empeno.monto_prestado',
                    'empeno.folio'
                )
                ->orderBy('amortizacion.fecha_pago_programado', 'asc')
                ->limit(20)
                ->get();

            $amortizaciones = $amortizaciones->map(function($item) {
                $fechaProgramada = \Carbon\Carbon::parse($item->fecha_pago_programado);
                $hoy = \Carbon\Carbon::now();

                $diasAtraso = 0;
                if ($fechaProgramada->lt($hoy)) {
                    $diasAtraso = (int) ceil($fechaProgramada->diffInDays($hoy));
                    if ($diasAtraso < 0) $diasAtraso = 0;
                }

                $saldoRestante = $item->saldo_final ?? ($item->monto_total - ($item->monto_pagado ?? 0));

                $item->dias_atraso = $diasAtraso;
                $item->status = $diasAtraso > 0 ? 'Atrasado' : 'Pendiente';
                $item->saldo_restante = $saldoRestante;

                return $item;
            });

            return response()->json([
                'success' => true,
                'data' => $amortizaciones
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ], 500);
        }
    }
}   