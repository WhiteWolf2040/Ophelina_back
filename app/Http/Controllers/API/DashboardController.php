<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    // ====================================
    //  NUEVO: ENDPOINT CONSOLIDADO
    // ====================================
    // Junta dashboard + morosidad + distribución + amortizaciones
    // en UNA sola petición para reducir el número de round-trips.
    //
    // FIX (timeout / carga lenta): todo el payload se cachea 90s por
    // empresa. Esto reduce drásticamente el tiempo de respuesta en
    // cargas repetidas y, de paso, si el frontend llega a disparar dos
    // peticiones casi simultáneas (por ejemplo por un doble montaje en
    // React), la segunda lee de caché en vez de volver a golpear la
    // base de datos con las mismas 4 consultas pesadas.
    public function completo(Request $request)
    {
        try {
            $user = $request->user();
            $idEmpresa = $user->id_empresa;

            $data = Cache::remember("dashboard_completo_{$idEmpresa}", 90, function () use ($idEmpresa) {
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
    // ENDPOINTS INDIVIDUALES (se conservan por compatibilidad
    // con otras pantallas que puedan seguir llamándolos por separado)
    // ====================================

    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $idEmpresa = $user->id_empresa;

            return response()->json([
                "success" => true,
                "data" => $this->getDashboardData($idEmpresa)
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
    // (usados tanto por completo() como por los endpoints individuales)
    // ====================================

    private function getDashboardData($idEmpresa)
    {
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

        //  CAMBIADO: Ingresos del mes actual (whereYear+whereMonth -> whereBetween)
        $inicioMesActual = now()->startOfMonth();
        $finMesActual    = now()->endOfMonth();

        $ingresosMesActual = DB::table('pagos')
            ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
            ->where('empeno.id_empresa', $idEmpresa)
            ->whereBetween('pagos.fecha_pago', [$inicioMesActual, $finMesActual])
            ->sum('pagos.monto_total');

        $hoy = now()->toDateString();

        $empenosActivos = DB::table('empeno')
            ->where('estado', 'activo')
            ->where('id_empresa', $idEmpresa)
            ->whereDate('fecha_vencimiento', '>=', $hoy)
            ->count();

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

        // Próximos a vencer
        $proximosVencer = DB::table('empeno')
            ->whereBetween('fecha_vencimiento', [now(), now()->addDays(7)])
            ->where('estado', 'activo')
            ->where('id_empresa', $idEmpresa)
            ->count();

        //  CAMBIADO: Ingresos recientes (whereDate -> comparación directa de rango)
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

        // Precio oro (cacheado 5 min — no cambia segundo a segundo)
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
            ->leftJoin('pagos', function ($join) {
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

        //  CAMBIADO: Capital vs retorno por mes (whereYear -> whereBetween)
        $inicioAnio = now()->startOfYear();
        $finAnio    = now()->endOfYear();

        $prestamosPorMes = DB::table('empeno')
            ->select(
                DB::raw("EXTRACT(MONTH FROM fecha_empeno) as numero_mes"),
                DB::raw("TO_CHAR(fecha_empeno, 'Mon') as mes"),
                DB::raw("SUM(monto_prestado) as capital"),
                DB::raw("COUNT(id_empeno) as total_empenos")
            )
            ->whereBetween('fecha_empeno', [$inicioAnio, $finAnio])
            ->where('id_empresa', $idEmpresa)
            ->groupBy(DB::raw("EXTRACT(MONTH FROM fecha_empeno)"), DB::raw("TO_CHAR(fecha_empeno, 'Mon')"))
            ->orderBy(DB::raw("EXTRACT(MONTH FROM fecha_empeno)"))
            ->get();

        //  CAMBIADO: (whereYear -> whereBetween)
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
            ->keyBy('numero_mes');

        $capitalAcumulado = 0;
        $retornoAcumulado = 0;
        $gananciaAcumulada = 0;

        $capitalRetorno = $prestamosPorMes->map(function ($prestamo) use (&$capitalAcumulado, &$retornoAcumulado, &$gananciaAcumulada, $pagosPorMes) {
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
        //  CAMBIADO: whereYear -> whereBetween
        $inicioAnioActual = now()->startOfYear();
        $finAnioActual    = now()->endOfYear();

        $categorias = DB::table('prendas')
            ->join('empeno', 'empeno.id_prenda', '=', 'prendas.id_prenda')
            ->where('empeno.id_empresa', $idEmpresa)
            ->whereBetween('empeno.fecha_empeno', [$inicioAnioActual, $finAnioActual])
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

        return $categorias;
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

            $saldoRestante = $item->saldo_final ?? ($item->monto_total - ($item->monto_pagado ?? 0));

            $item->dias_atraso = $diasAtraso;
            $item->status = $diasAtraso > 0 ? 'Atrasado' : 'Pendiente';
            $item->saldo_restante = $saldoRestante;

            return $item;
        });
    }

    // ====================================
    // LISTADOS DETALLADOS (modales — se dejan igual)
    // ====================================

    public function activos(Request $request)
    {
        try {
            $user = $request->user();

            $data = DB::table('empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.estado', 'activo')
                ->where('empeno.fecha_vencimiento', '>=', now())
                ->where('empeno.id_empresa', $user->id_empresa)
                ->select(
                    'empeno.id_empeno',
                    DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as cliente"),
                    'prendas.descripcion as nombre',
                    'empeno.monto_prestado as monto',
                    'empeno.fecha_empeno as fecha'
                )
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

            $data = DB::table('empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.id_empresa', $user->id_empresa)
                ->where(function ($query) {
                    $query->where('empeno.estado', 'vencido')
                        ->orWhere(function ($sub) {
                            $sub->where('empeno.estado', 'activo')
                                ->where('empeno.fecha_vencimiento', '<', now());
                        });
                })
                ->select(
                    'empeno.id_empeno',
                    DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as cliente"),
                    'prendas.descripcion as nombre',
                    'empeno.monto_prestado as monto',
                    'empeno.fecha_vencimiento as fecha',
                    DB::raw("EXTRACT(DAY FROM (NOW() - empeno.fecha_vencimiento)) as dias")
                )
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
}