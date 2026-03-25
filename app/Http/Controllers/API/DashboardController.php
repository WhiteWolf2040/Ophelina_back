<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;  // <--- AGREGAR Request

class DashboardController extends Controller
{
    public function index(Request $request)  // <--- AGREGAR $request
    {
        $user = $request->user();  // <--- OBTENER USUARIO LOGUEADO
        $idEmpresa = $user->id_empresa;  // <--- ID DE LA EMPRESA

        /*
        ===============================
        RESUMEN (FILTRADO POR EMPRESA)
        ===============================
        */
        
        // Empeños activos de la empresa
        $empenosActivos = DB::table('empeno')
            ->where('estado', 'activo')
            ->where('id_empresa', $idEmpresa)
            ->count();
        
        // Empeños vencidos de la empresa
        $empenosVencidos = DB::table('empeno')
            ->where('estado', 'vencido')
            ->where('id_empresa', $idEmpresa)
            ->count();
        
        // Próximos a vencer de la empresa
        $proximosVencer = DB::table('empeno')
            ->whereBetween('fecha_vencimiento', [now(), now()->addDays(7)])
            ->where('estado', 'activo')
            ->where('id_empresa', $idEmpresa)
            ->count();
        
        // Ingresos recientes de la empresa (a través de empeños)
        $ingresosRecientes = DB::table('pagos')
            ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
            ->where('empeno.id_empresa', $idEmpresa)
            ->whereDate('pagos.fecha_pago', '>=', now()->subDays(30))
            ->sum('pagos.monto_total');
        
        // Total clientes de la empresa
        $totalClientes = DB::table('clientes')
            ->where('id_empresa', $idEmpresa)
            ->count();
        
        // Prendas disponibles de la empresa
        $prendasDisponibles = DB::table('prendas')
            ->where('estado', 'Disponible')
            ->where('id_empresa', $idEmpresa)
            ->count();
        
        $resumen = [
            "empenos_activos" => $empenosActivos,
            "empenos_vencidos" => $empenosVencidos,
            "proximos_vencer" => $proximosVencer,
            "ingresos_recientes" => $ingresosRecientes,
            "precio_oro" => 850,
            "total_clientes" => $totalClientes,
            "prendas_disponibles" => $prendasDisponibles
        ];

        /*
        ===============================
        TOP CLIENTES (FILTRADO POR EMPRESA)
        ===============================
        */
        $topClientes = DB::table('empeno')
            ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
            ->where('empeno.id_empresa', $idEmpresa)
            ->select(
                'clientes.id_cliente',
                DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as nombre"),
                DB::raw("COUNT(empeno.id_empeno) as empenos"),
                DB::raw("SUM(empeno.monto_prestado) as monto_total"),
                DB::raw("MAX(empeno.fecha_empeno) as ultimo_empeno")
            )
            ->groupBy('clientes.id_cliente', 'clientes.nombre', 'clientes.apellido')
            ->orderByDesc('empenos')
            ->limit(5)
            ->get();

        /*
        ===============================
        TOP ARTICULOS (FILTRADO POR EMPRESA)
        ===============================
        */
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

        /*
        ===============================
        ACTIVIDAD RECIENTE (FILTRADO POR EMPRESA)
        ===============================
        */
        $actividad = DB::table('pagos')
            ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
            ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
            ->where('empeno.id_empresa', $idEmpresa)
            ->select(
                DB::raw("'pago' as tipo"),
                DB::raw("CONCAT('Pago recibido de ',clientes.nombre,' ',clientes.apellido) as descripcion"),
                'pagos.fecha_pago as fecha'
            )
            ->orderByDesc('pagos.fecha_pago')
            ->limit(10)
            ->get();

        /*
        ===============================
        CAPITAL VS RETORNO (FILTRADO POR EMPRESA)
        ===============================
        */
        // Préstamos por mes de la empresa
        $prestamosPorMes = DB::table('empeno')
            ->select(
                DB::raw("MONTH(fecha_empeno) as numero_mes"),
                DB::raw("DATE_FORMAT(fecha_empeno, '%b') as mes"),
                DB::raw("SUM(monto_prestado) as capital"),
                DB::raw("COUNT(id_empeno) as total_empenos")
            )
            ->whereYear('fecha_empeno', date('Y'))
            ->where('id_empresa', $idEmpresa)
            ->groupBy(DB::raw("MONTH(fecha_empeno)"), DB::raw("DATE_FORMAT(fecha_empeno, '%b')"))
            ->orderBy(DB::raw("MONTH(fecha_empeno)"))
            ->get();

        // Pagos por mes de la empresa
        $pagosPorMes = DB::table('pagos')
            ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
            ->select(
                DB::raw("MONTH(pagos.fecha_pago) as numero_mes"),
                DB::raw("SUM(pagos.monto_total) as total_pagos"),
                DB::raw("SUM(pagos.interes_pagado) as total_intereses"),
                DB::raw("SUM(pagos.iva_pagado) as total_iva")
            )
            ->whereYear('pagos.fecha_pago', date('Y'))
            ->where('empeno.id_empresa', $idEmpresa)
            ->groupBy(DB::raw("MONTH(pagos.fecha_pago)"))
            ->get()
            ->keyBy('numero_mes');

        // Combinar los datos
        $capitalRetorno = $prestamosPorMes->map(function($prestamo) use ($pagosPorMes) {
            $mesNumero = $prestamo->numero_mes;
            $pagos = $pagosPorMes->get($mesNumero);
            
            if ($pagos) {
                $prestamo->retorno = $pagos->total_pagos ?? 0;
                $prestamo->ganancia = $pagos->total_intereses ?? 0;
                $prestamo->iva_generado = $pagos->total_iva ?? 0;
            } else {
                $prestamo->retorno = 0;
                $prestamo->ganancia = 0;
                $prestamo->iva_generado = 0;
            }
            
            $prestamo->rentabilidad = $prestamo->capital > 0 
                ? round(($prestamo->ganancia / $prestamo->capital) * 100, 2) 
                : 0;
            
            return $prestamo;
        });

        /*
        ===============================
        PRESTAMOS GRANDES (FILTRADO POR EMPRESA)
        ===============================
        */
        $prestamosGrandes = DB::table('empeno')
            ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
            ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
            ->where('empeno.id_empresa', $idEmpresa)
            ->select(
                DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as cliente"),
                'prendas.descripcion as prenda',
                'empeno.monto_prestado as monto',
                'empeno.fecha_empeno'
            )
            ->orderByDesc('empeno.monto_prestado')
            ->limit(5)
            ->get();

        return response()->json([
            "success" => true,
            "data" => [
                "resumen" => $resumen,
                "top_clientes" => $topClientes,
                "top_articulos" => $topArticulos,
                "actividad_reciente" => $actividad,
                "ingresos_mensuales" => $capitalRetorno,
                "capital_retorno" => $capitalRetorno,
                "prestamos_grandes" => $prestamosGrandes
            ]
        ]);
    }

    // ====================================
    // LISTADOS DETALLADOS (FILTRADOS POR EMPRESA)
    // ====================================
    
    public function activos(Request $request)  // <--- AGREGAR $request
    {
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
            "success"=>true,
            "data"=>$data
        ]);
    }

    public function vencidos(Request $request)  // <--- AGREGAR $request
    {
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
                DB::raw("DATEDIFF(NOW(),empeno.fecha_vencimiento) as dias")
            )
            ->get();

        return response()->json([
            "success"=>true,
            "data"=>$data
        ]);
    }

    public function proximos(Request $request)  // <--- AGREGAR $request
    {
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
                DB::raw("DATEDIFF(empeno.fecha_vencimiento,NOW()) as dias")
            )
            ->get();

        return response()->json([
            "success"=>true,
            "data"=>$data
        ]);
    }

    public function morosidad(Request $request)  // <--- AGREGAR $request
    {
        try {
            $user = $request->user();
            
            // Clientes con pagos atrasados usando la tabla amortizacion
            $data = DB::table('amortizacion')
                ->join('empeno', 'empeno.id_empeno', '=', 'amortizacion.id_empeno')
                ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
                ->where('amortizacion.estado', 'pendiente')
                ->where('amortizacion.fecha_pago_programado', '<', now())
                ->where('empeno.id_empresa', $user->id_empresa)
                ->select(
                    DB::raw("CONCAT(clientes.nombre, ' ', clientes.apellido) as cliente"),
                    DB::raw("COUNT(amortizacion.id_amortizacion) as pagos_atrasados"),
                    DB::raw("SUM(amortizacion.monto_total - IFNULL(amortizacion.monto_pagado, 0)) as deuda"),
                    DB::raw("MAX(amortizacion.fecha_pago_programado) as ultimo_pago_programado")
                )
                ->groupBy('clientes.id_cliente', 'clientes.nombre', 'clientes.apellido')
                ->orderByDesc('deuda')
                ->limit(5)
                ->get();

            // Calcular días de atraso para cada registro
            $data = $data->map(function($item) {
                $fechaProgramada = new \Carbon\Carbon($item->ultimo_pago_programado);
                $item->dias_mora = $fechaProgramada->diffInDays(now());
                return $item;
            });

            return response()->json([
                "success" => true,
                "data" => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                "success" => true,
                "data" => []
            ]);
        }
    }

    public function distribucionCategorias(Request $request)  // <--- AGREGAR $request
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

            return response()->json([
                'success' => true,
                'data' => $categorias
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => true,
                'data' => [
                    ['categoria' => 'Joyería', 'total' => 0],
                    ['categoria' => 'Electrónica', 'total' => 0],
                    ['categoria' => 'Relojes', 'total' => 0],
                    ['categoria' => 'Herramientas', 'total' => 0],
                    ['categoria' => 'Instrumentos', 'total' => 0]
                ]
            ]);
        }
    }
}