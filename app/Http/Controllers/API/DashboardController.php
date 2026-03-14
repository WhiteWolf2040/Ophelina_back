<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        /*
        ===============================
        RESUMEN
        ===============================
        */
        $resumen = [
            "empenos_activos" => DB::table('empeno')->where('estado','activo')->count(),
            "empenos_vencidos" => DB::table('empeno')->where('estado','vencido')->count(),
            "proximos_vencer" => DB::table('empeno')
                ->whereBetween('fecha_vencimiento',[now(), now()->addDays(7)])
                ->where('estado','activo')
                ->count(),
            "ingresos_recientes" => DB::table('pagos')
                ->whereDate('fecha_pago', '>=', now()->subDays(30))
                ->sum('monto_total'),
            "precio_oro" => 850,
            "total_clientes" => DB::table('clientes')->count(),
            "prendas_disponibles" => DB::table('prendas')->where('estado','Disponible')->count()
        ];

        /*
        ===============================
        TOP CLIENTES
        ===============================
        */
        $topClientes = DB::table('empeno')
            ->join('clientes','clientes.id_cliente','=','empeno.id_cliente')
            ->select(
                'clientes.id_cliente',
                DB::raw("CONCAT(clientes.nombre,' ',clientes.apellido) as nombre"),
                DB::raw("COUNT(empeno.id_empeno) as empenos"),
                DB::raw("SUM(empeno.monto_prestado) as monto_total"),
                DB::raw("MAX(empeno.fecha_empeno) as ultimo_empeno")
            )
            ->groupBy('clientes.id_cliente','clientes.nombre','clientes.apellido')
            ->orderByDesc('empenos')
            ->limit(5)
            ->get();

        /*
        ===============================
        TOP ARTICULOS
        ===============================
        */
        $topArticulos = DB::table('empeno')
            ->join('prendas','prendas.id_prenda','=','empeno.id_prenda')
            ->select(
                'prendas.descripcion as nombre',
                'prendas.tipo as categoria',
                DB::raw("COUNT(empeno.id_prenda) as cantidad"),
                DB::raw("AVG(empeno.monto_prestado) as monto_promedio")
            )
            ->groupBy('prendas.descripcion','prendas.tipo')
            ->orderByDesc('cantidad')
            ->limit(5)
            ->get();

        /*
        ===============================
        ACTIVIDAD RECIENTE
        ===============================
        */
        $actividad = DB::table('pagos')
            ->join('empeno','empeno.id_empeno','=','pagos.id_empeno')
            ->join('clientes','clientes.id_cliente','=','empeno.id_cliente')
            ->select(
                DB::raw("'pago' as tipo"),
                DB::raw("CONCAT('Pago recibido de ',clientes.nombre,' ',clientes.apellido) as descripcion"),
                'pagos.fecha_pago as fecha'
            )
            ->orderByDesc('pagos.fecha_pago')
            ->limit(10)
            ->get();

        /*
/*
===========================================
CAPITAL VS RETORNO (BASADO EN TU BD)
===========================================
*/
// Obtener préstamos por mes (de la tabla empeno)
$prestamosPorMes = DB::table('empeno')
    ->select(
        DB::raw("MONTH(fecha_empeno) as numero_mes"),
        DB::raw("DATE_FORMAT(fecha_empeno, '%b') as mes"),
        DB::raw("SUM(monto_prestado) as capital"),
        DB::raw("COUNT(id_empeno) as total_empenos")
    )
    ->whereYear('fecha_empeno', date('Y'))
    ->groupBy(DB::raw("MONTH(fecha_empeno)"), DB::raw("DATE_FORMAT(fecha_empeno, '%b')"))
    ->orderBy(DB::raw("MONTH(fecha_empeno)"))
    ->get();

// Obtener pagos por mes (de la tabla pagos)
$pagosPorMes = DB::table('pagos')
    ->select(
        DB::raw("MONTH(fecha_pago) as numero_mes"),
        DB::raw("SUM(monto_total) as total_pagos"),
        DB::raw("SUM(interes_pagado) as total_intereses"),
        DB::raw("SUM(iva_pagado) as total_iva")
    )
    ->whereYear('fecha_pago', date('Y'))
    ->groupBy(DB::raw("MONTH(fecha_pago)"))
    ->get()
    ->keyBy('numero_mes');

// Combinar los datos
$capitalRetorno = $prestamosPorMes->map(function($prestamo) use ($pagosPorMes) {
    $mesNumero = $prestamo->numero_mes;
    $pagos = $pagosPorMes->get($mesNumero);
    
    // Si hay pagos en ese mes, usar datos reales
    if ($pagos) {
        $prestamo->retorno = $pagos->total_pagos ?? 0;
        $prestamo->ganancia = $pagos->total_intereses ?? 0;
        $prestamo->iva_generado = $pagos->total_iva ?? 0;
    } else {
        $prestamo->retorno = 0;
        $prestamo->ganancia = 0;
        $prestamo->iva_generado = 0;
    }
    
    // Calcular rentabilidad sobre capital
    $prestamo->rentabilidad = $prestamo->capital > 0 
        ? round(($prestamo->ganancia / $prestamo->capital) * 100, 2) 
        : 0;
    
    return $prestamo;
});

// Si no hay datos, usar datos de ejemplo basados en tu imagen

        /*
        ===============================
        PRESTAMOS GRANDES
        ===============================
        */
        $prestamosGrandes = DB::table('empeno')
            ->join('clientes','clientes.id_cliente','=','empeno.id_cliente')
            ->join('prendas','prendas.id_prenda','=','empeno.id_prenda')
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
    // LISTADOS DETALLADOS
    // ====================================
    public function activos()
    {
        $data = DB::table('empeno')
            ->join('clientes','clientes.id_cliente','=','empeno.id_cliente')
            ->join('prendas','prendas.id_prenda','=','empeno.id_prenda')
            ->where('empeno.estado','activo')
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

    public function vencidos()
    {
        $data = DB::table('empeno')
            ->join('clientes','clientes.id_cliente','=','empeno.id_cliente')
            ->join('prendas','prendas.id_prenda','=','empeno.id_prenda')
            ->where('empeno.estado','vencido')
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

    public function proximos()
    {
        $data = DB::table('empeno')
            ->join('clientes','clientes.id_cliente','=','empeno.id_cliente')
            ->join('prendas','prendas.id_prenda','=','empeno.id_prenda')
            ->where('empeno.estado','activo')
            ->whereBetween('empeno.fecha_vencimiento',[now(), now()->addDays(7)])
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

    // ====================================
    // MOROSIDAD REAL (pagos atrasados)
    // ====================================
    public function morosidad()
{
    try {
        // Clientes con pagos atrasados usando la tabla amortizacion
        $data = DB::table('amortizacion')
            ->join('empeno', 'empeno.id_empeno', '=', 'amortizacion.id_empeno')
            ->join('clientes', 'clientes.id_cliente', '=', 'empeno.id_cliente')
            ->where('amortizacion.estado', 'pendiente')
            ->where('amortizacion.fecha_pago_programado', '<', now())
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

 public function distribucionCategorias()
{
    try {
        $categorias = DB::table('prendas')
            ->join('empeno', 'empeno.id_prenda', '=', 'prendas.id_prenda')
            ->select('prendas.tipo as categoria', DB::raw('COUNT(empeno.id_empeno) as total'))
            ->whereYear('empeno.fecha_empeno', date('Y'))
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