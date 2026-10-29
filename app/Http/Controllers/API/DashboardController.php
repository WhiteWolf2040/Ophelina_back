<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    // ====================================
    //  ENDPOINT CONSOLIDADO
    // ====================================
    // Junta dashboard + morosidad + distribución + amortizaciones
    // en UNA sola petición. Se cachea 90s por empresa.
    // Con ?refresh=1 se descarta el caché (lo usa el botón "refrescar").
    public function completo(Request $request)
    {
        try {
            $user = $request->user();
            $idEmpresa = $user->id_empresa;
            $cacheKey = "dashboard_completo_{$idEmpresa}";

            if ($request->boolean('refresh')) {
                Cache::forget($cacheKey);
            }

            $data = Cache::remember($cacheKey, 90, function () use ($idEmpresa) {
                return [
                    "dashboard" => $this->getDashboardData($idEmpresa),
                    "morosidad" => $this->getMorosidadData($idEmpresa),
                    "distribucion" => $this->getDistribucionData($idEmpresa),
                    "amortizaciones" => $this->getAmortizacionesData($idEmpresa),
                ];
            });

            return response()->json([
                "success" => true,
                "data" => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Error en dashboard completo: ' . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error al cargar el dashboard",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    // ====================================
    // ENDPOINTS INDIVIDUALES (compatibilidad)
    // ====================================

    public function index(Request $request)
    {
        try {
            $user = $request->user();

            return response()->json([
                "success" => true,
                "data" => $this->getDashboardData($user->id_empresa)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => false,
                "message" => "Error al cargar el dashboard",
                "error" => $e->getMessage()
            ], 500);
        }
    }

    public function morosidad(Request $request)
    {
        try {
            $user = $request->user();
            return response()->json([
                "success" => true,
                "data" => $this->getMorosidadData($user->id_empresa)
            ]);
        } catch (\Exception $e) {
            \Log::error('Error en morosidad: ' . $e->getMessage());
            return response()->json([
                "success" => false,
                "message" => "Error al calcular morosidad: " . $e->getMessage()
            ], 500);
        }
    }

    public function distribucionCategorias(Request $request)
    {
        try {
            $user = $request->user();
            return response()->json([
                'success' => true,
                'data' => $this->getDistribucionData($user->id_empresa)
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
            return response()->json([
                'success' => true,
                'data' => $this->getAmortizacionesData($user->id_empresa)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ], 500);
        }
    }

    // ====================================
    // MÉTODOS PRIVADOS CON LA LÓGICA REAL
    // ====================================

    private function getDashboardData($idEmpresa)
    {
        $hoy = now()->toDateString();
        $en7Dias = now()->addDays(7)->toDateString();

        // Ganancias totales (solo intereses de liquidaciones)
        $gananciaTotal = DB::table('pagos')
            ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
            ->where('empeno.id_empresa', $idEmpresa)
            ->where('pagos.tipo_pago', 'liquidacion')
            ->sum('pagos.interes_pagado');

        // Pérdidas totales (cuotas vencidas sin pagar)
        $perdidaTotal = DB::table('amortizacion')
            ->join('empeno', 'empeno.id_empeno', '=', 'amortizacion.id_empeno')
            ->where('amortizacion.estado', 'pendiente')
            ->where('amortizacion.fecha_pago_programado', '<', now())
            ->where('empeno.id_empresa', $idEmpresa)
            ->select(DB::raw('SUM(amortizacion.monto_total - COALESCE(amortizacion.monto_pagado, 0)) as total'))
            ->first();

        $perdidaTotal = $perdidaTotal ? floatval($perdidaTotal->total) : 0;

        // Ingresos del mes actual
        $inicioMesActual = now()->startOfMonth();
        $finMesActual    = now()->endOfMonth();

        $ingresosMesActual = DB::table('pagos')
            ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
            ->where('empeno.id_empresa', $idEmpresa)
            ->whereBetween('pagos.fecha_pago', [$inicioMesActual, $finMesActual])
            ->sum('pagos.monto_total');

        // Empeños activos (vigentes: vencen hoy o después)
        $empenosActivos = DB::table('empeno')
            ->where('estado', 'activo')
            ->where('id_empresa', $idEmpresa)
            ->whereDate('fecha_vencimiento', '>=', $hoy)
            ->count();

        // Empeños vencidos
        $empenosVencidos = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->where(function ($query) use ($hoy) {
                $query->where('estado', 'vencido')
                    ->orWhere(function ($sub) use ($hoy) {
                        $sub->where('estado', 'activo')
                            ->whereDate('fecha_vencimiento', '<', $hoy);
                    });
            })
            ->count();

        // Próximos a vencer (hoy hasta 7 días, comparando por fecha, igual que el modal)
        $proximosVencer = DB::table('empeno')
            ->where('estado', 'activo')
            ->where('id_empresa', $idEmpresa)
            ->whereDate('fecha_vencimiento', '>=', $hoy)
            ->whereDate('fecha_vencimiento', '<=', $en7Dias)
            ->count();

        // Ingresos recientes (últimos 15 días)
        $ingresosRecientes = DB::table('pagos')
            ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
            ->where('empeno.id_empresa', $idEmpresa)
            ->where('pagos.fecha_pago', '>=', now()->subDays(15)->startOfDay())
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

        // Precio oro (cacheado 5 min)
        $precioOro = Cache::remember('precio_oro_actual', 300, function () {
            return DB::table('precio_oro')
                ->orderBy('fecha_actualizacion', 'desc')
                ->first();
        });

        $resumen = [
            "empenos_activos" => $empenosActivos,
            "empenos_vencidos" => $empenosVencidos,
            "proximos_vencer" => $proximosVencer,
            "ingresos_recientes" => floatval($ingresosRecientes),
            // null si no hay registro: el frontend muestra "No disponible"
            // en lugar de un 850 inventado que parece un dato real.
            "precio_oro" => $precioOro->precio_gramo_24k ?? null,
            "ultima_actualizacion_oro" => $precioOro->fecha_actualizacion ?? null,
            "total_clientes" => $totalClientes,
            "prendas_disponibles" => $prendasDisponibles,
            "ganancia_total" => floatval($gananciaTotal),
            "perdida_total" => $perdidaTotal,
            "ingresos_mes_actual" => floatval($ingresosMesActual)
        ];

        // Top Clientes
        // Los pagos de liquidación se agregan por empeño ANTES del join para
        // que un empeño con varios pagos no duplique filas e infle SUM(monto_prestado).
        $pagosLiquidacion = DB::table('pagos')
            ->select('id_empeno', DB::raw('SUM(interes_pagado) as interes'))
            ->where('tipo_pago', 'liquidacion')
            ->groupBy('id_empeno');

        $topClientes = DB::table('empeno')
            ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
            ->leftJoinSub($pagosLiquidacion, 'pl', 'pl.id_empeno', '=', 'empeno.id_empeno')
            ->where('empeno.id_empresa', $idEmpresa)
            ->select(
                'clientes.id_cliente',
                DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as nombre"),
                DB::raw("COUNT(DISTINCT empeno.id_empeno) as empenos"),
                DB::raw("SUM(empeno.monto_prestado) as monto_total"),
                DB::raw("SUM(COALESCE(pl.interes, 0)) as ganancia_realizada"),
                DB::raw("MAX(empeno.fecha_empeno) as ultimo_empeno")
            )
            ->groupBy('clientes.id_cliente', 'clientes.nombre', 'clientes.apellido')
            ->orderByDesc('ganancia_realizada')
            ->limit(5)
            ->get();

        $topClientes = $topClientes->map(function ($cliente) {
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

        $actividad = $actividad->map(function ($item) {
            $item->fecha = $item->fecha ? date('d/m/Y', strtotime($item->fecha)) : '';
            return $item;
        });

        // ------------------------------------
        // Capital vs retorno por mes (año actual)
        // ------------------------------------
        $inicioAnio = now()->startOfYear();
        $finAnio    = now()->endOfYear();

        $prestamosPorMes = DB::table('empeno')
            ->select(
                DB::raw("EXTRACT(MONTH FROM fecha_empeno) as numero_mes"),
                DB::raw("SUM(monto_prestado) as capital"),
                DB::raw("COUNT(id_empeno) as total_empenos")
            )
            ->whereBetween('fecha_empeno', [$inicioAnio, $finAnio])
            ->where('id_empresa', $idEmpresa)
            ->groupBy(DB::raw("EXTRACT(MONTH FROM fecha_empeno)"))
            ->get()
            ->keyBy(fn ($p) => (int) $p->numero_mes);

        $pagosPorMes = DB::table('pagos')
            ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
            ->select(
                DB::raw("EXTRACT(MONTH FROM pagos.fecha_pago) as numero_mes"),
                DB::raw("SUM(pagos.monto_total) as total_pagos"),
                DB::raw("SUM(pagos.interes_pagado) as total_intereses")
            )
            ->whereBetween('pagos.fecha_pago', [$inicioAnio, $finAnio])
            ->where('empeno.id_empresa', $idEmpresa)
            ->groupBy(DB::raw("EXTRACT(MONTH FROM pagos.fecha_pago)"))
            ->get()
            ->keyBy(fn ($p) => (int) $p->numero_mes);

        // Se recorren TODOS los meses desde enero hasta el actual, así un mes
        // con pagos pero sin préstamos nuevos (o al revés) no se pierde,
        // y los nombres de mes salen en español.
        $nombresMes = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

        $capitalAcumulado = 0;
        $retornoAcumulado = 0;
        $gananciaAcumulada = 0;
        $capitalRetorno = [];

        for ($m = 1; $m <= now()->month; $m++) {
            $prestamo = $prestamosPorMes->get($m);
            $pago     = $pagosPorMes->get($m);

            $capitalMes = $prestamo ? floatval($prestamo->capital) : 0;
            $retornoMes = $pago ? floatval($pago->total_pagos) : 0;
            $interesMes = $pago ? floatval($pago->total_intereses) : 0;

            $capitalAcumulado  += $capitalMes;
            $retornoAcumulado  += $retornoMes;
            $gananciaAcumulada += $interesMes;

            $capitalRetorno[] = [
                'numero_mes'    => $m,
                'mes'           => $nombresMes[$m - 1],
                'total_empenos' => $prestamo ? intval($prestamo->total_empenos) : 0,
                'capital'       => $capitalAcumulado,
                'retorno'       => $retornoAcumulado,
                'ganancia'      => $gananciaAcumulada,
                // valores del mes (no acumulados), para la gráfica de tendencia
                'retorno_mes'   => $retornoMes,
                'capital_mes'   => $capitalMes,
                'ganancia_mes'  => $interesMes,
            ];
        }

        return [
            "resumen" => $resumen,
            "top_clientes" => $topClientes,
            "top_articulos" => $topArticulos,
            "actividad_reciente" => $actividad,
            "capital_retorno" => $capitalRetorno
        ];
    }

    private function getMorosidadData($idEmpresa)
    {
        $morosos = DB::select("
            SELECT
                CONCAT(c.nombre, ' ', c.apellido) AS nombre,
                SUM(x.monto_prestado) AS total_prestado,
                SUM(x.deuda) AS deuda,
                SUM(x.cuotas_atrasadas) AS pagos_atrasados,
                MIN(x.fecha_mas_antigua) AS fecha_mas_antigua,
                MAX(x.ultimo_pago) AS ultimo_pago_real
            FROM (
                SELECT
                    e.id_empeno,
                    e.id_cliente,
                    e.monto_prestado,
                    SUM(a.monto_total - COALESCE(a.monto_pagado, 0)) AS deuda,
                    COUNT(a.id_amortizacion) AS cuotas_atrasadas,
                    MIN(a.fecha_pago_programado) AS fecha_mas_antigua,
                    (SELECT MAX(p.fecha_pago) FROM pagos p WHERE p.id_empeno = e.id_empeno) AS ultimo_pago
                FROM amortizacion a
                INNER JOIN empeno e ON e.id_empeno = a.id_empeno
                WHERE a.estado = 'pendiente'
                AND a.fecha_pago_programado < NOW()
                AND e.id_empresa = ?
                GROUP BY e.id_empeno, e.id_cliente, e.monto_prestado
            ) x
            INNER JOIN clientes c ON c.id_cliente = x.id_cliente
            GROUP BY c.id_cliente, c.nombre, c.apellido
            HAVING SUM(x.deuda) > 0
            ORDER BY SUM(x.deuda) DESC
            LIMIT 10
        ", [$idEmpresa]);

        if (empty($morosos)) {
            return [];
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
                try {
                    $fechaVencimiento = new \Carbon\Carbon($item->fecha_mas_antigua);
                    $diasMora = (int) $fechaVencimiento->diffInDays(now());
                } catch (\Exception $e) {
                    $diasMora = 0;
                }
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
                'pagos_atrasados' => intval($item->pagos_atrasados),
                'dias_mora' => $diasMora,
                'ultimo_pago' => $ultimoPagoFormateado
            ];
        }

        return $morosidadFormateada;
    }

    private function getDistribucionData($idEmpresa)
    {
        $inicioAnioActual = now()->startOfYear();
        $finAnioActual    = now()->endOfYear();

        $categorias = DB::table('prendas')
            ->join('empeno', 'empeno.id_prenda', '=', 'prendas.id_prenda')
            ->where('empeno.id_empresa', $idEmpresa)
            ->whereBetween('empeno.fecha_empeno', [$inicioAnioActual, $finAnioActual])
            ->select('prendas.tipo as categoria', DB::raw('COUNT(empeno.id_empeno) as total'))
            ->groupBy('prendas.tipo')
            ->orderByDesc('total')
            ->get()
            ->map(function ($c) {
                $c->total = (int) $c->total; // siempre numérico para la gráfica
                return $c;
            });

        // Si no hay datos se devuelve vacío (antes se inventaban categorías
        // con 0). El frontend muestra "Sin empeños este año".
        return $categorias->values();
    }

    private function getAmortizacionesData($idEmpresa)
    {
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

        return $amortizaciones->map(function ($item) {
            $fechaProgramada = \Carbon\Carbon::parse($item->fecha_pago_programado);
            $hoy = \Carbon\Carbon::now();

            $diasAtraso = 0;
            if ($fechaProgramada->lt($hoy)) {
                $diasAtraso = (int) ceil($fechaProgramada->diffInDays($hoy));
                if ($diasAtraso < 0) $diasAtraso = 0;
            }

            // Lo que falta de ESTA cuota. (saldo_final normalmente es el saldo
            // de capital después del pago, no lo que resta de la cuota.)
            $saldoRestante = max(0, floatval($item->monto_total) - floatval($item->monto_pagado ?? 0));

            $item->dias_atraso = $diasAtraso;
            $item->status = $diasAtraso > 0 ? 'Atrasado' : 'Pendiente';
            $item->saldo_restante = $saldoRestante;

            return $item;
        });
    }

    // ====================================
    // LISTADOS DETALLADOS (modales)
    // Usan whereDate igual que las tarjetas, para que los números coincidan.
    // ====================================

    public function activos(Request $request)
    {
        try {
            $user = $request->user();
            $hoy = now()->toDateString();

            $data = DB::table('empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.estado', 'activo')
                ->whereDate('empeno.fecha_vencimiento', '>=', $hoy)
                ->where('empeno.id_empresa', $user->id_empresa)
                ->select(
                    'empeno.id_empeno',
                    DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as cliente"),
                    'prendas.descripcion as nombre',
                    'empeno.monto_prestado as monto',
                    'empeno.fecha_empeno as fecha'
                )
                ->orderBy('empeno.fecha_vencimiento', 'asc')
                ->get();

            return response()->json(["success" => true, "data" => $data]);
        } catch (\Exception $e) {
            return response()->json(["success" => false, "message" => $e->getMessage()], 500);
        }
    }

    public function vencidos(Request $request)
    {
        try {
            $user = $request->user();
            $hoy = now()->toDateString();

            $data = DB::table('empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.id_empresa', $user->id_empresa)
                ->where(function ($query) use ($hoy) {
                    $query->where('empeno.estado', 'vencido')
                        ->orWhere(function ($sub) use ($hoy) {
                            $sub->where('empeno.estado', 'activo')
                                ->whereDate('empeno.fecha_vencimiento', '<', $hoy);
                        });
                })
                ->select(
                    'empeno.id_empeno',
                    DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as cliente"),
                    'prendas.descripcion as nombre',
                    'empeno.monto_prestado as monto',
                    'empeno.fecha_vencimiento as fecha',
                    DB::raw("GREATEST(0, CURRENT_DATE - CAST(empeno.fecha_vencimiento AS DATE)) as dias")
                )
                ->orderBy('empeno.fecha_vencimiento', 'asc')
                ->get();

            return response()->json(["success" => true, "data" => $data]);
        } catch (\Exception $e) {
            return response()->json(["success" => false, "message" => $e->getMessage()], 500);
        }
    }

    public function proximos(Request $request)
    {
        try {
            $user = $request->user();
            $hoy = now()->toDateString();
            $en7Dias = now()->addDays(7)->toDateString();

            $data = DB::table('empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.estado', 'activo')
                ->where('empeno.id_empresa', $user->id_empresa)
                ->whereDate('empeno.fecha_vencimiento', '>=', $hoy)
                ->whereDate('empeno.fecha_vencimiento', '<=', $en7Dias)
                ->select(
                    'empeno.id_empeno',
                    DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as cliente"),
                    'prendas.descripcion as nombre',
                    'empeno.monto_prestado as monto',
                    'empeno.fecha_vencimiento as fecha',
                    DB::raw("(CAST(empeno.fecha_vencimiento AS DATE) - CURRENT_DATE) as dias")
                )
                ->orderBy('empeno.fecha_vencimiento', 'asc')
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
}