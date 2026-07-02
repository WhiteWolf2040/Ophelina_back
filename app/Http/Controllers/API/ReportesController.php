<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportesController extends Controller
{
    // middleware auth:sanctum aplicado desde api.php — no se necesita __construct

    // ══════════════════════════════════════════════
    //  GET /api/reportes/kpis
    //  KPIs financieros principales del dashboard
    // ══════════════════════════════════════════════
    public function kpis(Request $request)
    {
        $idEmpresa = $request->user()->id_empresa;
        $hoy       = Carbon::today();
        $inicioMes = Carbon::now()->startOfMonth();
        $finMes    = Carbon::now()->endOfMonth();

        // ── 1. Capital prestado (suma de empeños activos) ──
        $capitalPrestado = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->whereIn('estado', ['activo', 'vencido'])
            ->sum('monto_prestado');

        // ── 2. Intereses cobrados este mes ──
        // pagos de tipo 'interes' o 'refrendo' en el mes actual
        $interesesCobrados = DB::table('pagos')
            ->join('empeno', 'pagos.id_empeno', '=', 'empeno.id_empeno')
            ->where('empeno.id_empresa', $idEmpresa)
            ->whereIn('pagos.tipo_pago', ['interes', 'abono', 'liquidacion'])
            ->whereBetween('pagos.fecha_pago', [$inicioMes, $finMes])
            ->sum('pagos.interes_pagado');

        // ── 3. Intereses pendientes (empeños activos no pagados) ──
        $interesesPendientes = DB::table('empeno')
            ->join('tasas_interes', 'empeno.id_tasa', '=', 'tasas_interes.id_tasa')
            ->where('empeno.id_empresa', $idEmpresa)
            ->whereIn('empeno.estado', ['activo', 'vencido'])
            ->selectRaw('
                SUM(
                    empeno.monto_prestado
                    * (tasas_interes.porcentaje / 100)
                    * GREATEST(DATEDIFF(NOW(), empeno.fecha_vencimiento), 0)
                ) as total
            ')
            ->value('total') ?? 0;

        // ── 4. Cartera vigente (activos NO vencidos) ──
        $carteraVigente = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->where('estado', 'activo')
            ->where('fecha_vencimiento', '>=', $hoy)
            ->sum('monto_prestado');

        // ── 5. Cartera vencida ──
        $carteraVencida = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->where(function ($q) use ($hoy) {
                $q->where('estado', 'vencido')
                  ->orWhere(function ($q2) use ($hoy) {
                      $q2->where('estado', 'activo')
                         ->where('fecha_vencimiento', '<', $hoy);
                  });
            })
            ->sum('monto_prestado');

        // ── 6. Contratos que vencen en los próximos 7 días ──
        $vencenEsta7Dias = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->where('estado', 'activo')
            ->whereBetween('fecha_vencimiento', [$hoy, $hoy->copy()->addDays(7)])
            ->count();

        // ── 7. Ingresos del mes (todos los pagos) ──
        $ingresosMes = DB::table('pagos')
            ->join('empeno', 'pagos.id_empeno', '=', 'empeno.id_empeno')
            ->where('empeno.id_empresa', $idEmpresa)
            ->whereBetween('pagos.fecha_pago', [$inicioMes, $finMes])
            ->sum('pagos.monto_total');

        // ── 8. Total empeños activos ──
        $totalActivos = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->where('estado', 'activo')
            ->count();

        // ── 9. Total clientes activos (con al menos un empeño activo) ──
        $clientesActivos = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->whereIn('estado', ['activo', 'vencido'])
            ->distinct('id_cliente')
            ->count('id_cliente');

        return response()->json([
            'capital_prestado'      => (float) $capitalPrestado,
            'intereses_cobrados'    => (float) $interesesCobrados,
            'intereses_pendientes'  => (float) $interesesPendientes,
            'cartera_vigente'       => (float) $carteraVigente,
            'cartera_vencida'       => (float) $carteraVencida,
            'vencen_7_dias'         => (int)   $vencenEsta7Dias,
            'ingresos_mes'          => (float) $ingresosMes,
            'total_activos'         => (int)   $totalActivos,
            'clientes_activos'      => (int)   $clientesActivos,
            'periodo'               => [
                'inicio' => $inicioMes->toDateString(),
                'fin'    => $finMes->toDateString(),
                'hoy'    => $hoy->toDateString(),
            ],
        ]);
    }

    // ══════════════════════════════════════════════
    //  GET /api/reportes/empeños?inicio=&fin=
    //  Reporte de empeños por estado y período
    // ══════════════════════════════════════════════
    public function empenos(Request $request)
    {
        $idEmpresa = $request->user()->id_empresa;
        $inicio    = $request->query('inicio', Carbon::now()->startOfMonth()->toDateString());
        $fin       = $request->query('fin',    Carbon::now()->endOfMonth()->toDateString());

        // Conteos por estado
        $porEstado = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->whereBetween('fecha_empeno', [$inicio, $fin])
            ->select('estado', DB::raw('COUNT(*) as total'), DB::raw('SUM(monto_prestado) as monto'))
            ->groupBy('estado')
            ->get()
            ->keyBy('estado');

        // Tiempo promedio de recuperación (rescatados)
        $tiempoPromedio = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->where('estado', 'pagado')
            ->whereBetween('fecha_empeno', [$inicio, $fin])
            ->selectRaw('AVG(DATEDIFF(fecha_vencimiento, fecha_empeno)) as dias_promedio')
            ->value('dias_promedio');

        // Empeños refrendados (con al menos 1 renovación)
        $refrendados = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->whereBetween('fecha_empeno', [$inicio, $fin])
            ->where('estado', 'prorrogado')
            ->count();

        // Tendencia mensual (últimos 6 meses)
        $tendencia = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->where('fecha_empeno', '>=', Carbon::now()->subMonths(6)->startOfMonth())
            ->selectRaw("
                DATE_FORMAT(fecha_empeno, '%Y-%m') as mes,
                COUNT(*) as cantidad,
                SUM(monto_prestado) as monto_total
            ")
            ->groupBy('mes')
            ->orderBy('mes')
            ->get();

        return response()->json([
            'por_estado' => [
                'activos'     => $porEstado['activo']    ?? ['total' => 0, 'monto' => 0],
                'vencidos'    => $porEstado['vencido']   ?? ['total' => 0, 'monto' => 0],
                'rescatados'  => $porEstado['pagado'] ?? ['total' => 0, 'monto' => 0],
                'vendidos'    => $porEstado['vendido']   ?? ['total' => 0, 'monto' => 0],
                'refrendados' => $refrendados,
            ],
            'tiempo_promedio_recuperacion' => round((float) $tiempoPromedio, 1),
            'tendencia_mensual'            => $tendencia,
            'periodo'                      => ['inicio' => $inicio, 'fin' => $fin],
        ]);
    }

    // ══════════════════════════════════════════════
    //  GET /api/reportes/flujo-caja?inicio=&fin=
    //  Flujo de caja (entradas y salidas)
    // ══════════════════════════════════════════════
    public function flujoCaja(Request $request)
    {
        $idEmpresa = $request->user()->id_empresa;
        $inicio    = $request->query('inicio', Carbon::now()->startOfMonth()->toDateString());
        $fin       = $request->query('fin',    Carbon::now()->toDateString());

        // Entradas: pagos recibidos (rescates, refrendos, intereses)
        $entradas = DB::table('pagos')
            ->join('empeno', 'pagos.id_empeno', '=', 'empeno.id_empeno')
            ->where('empeno.id_empresa', $idEmpresa)
            ->whereBetween('pagos.fecha_pago', [$inicio, $fin])
            ->selectRaw("
                pagos.fecha_pago as fecha,
                pagos.tipo_pago as concepto,
                pagos.monto_total as monto,
                'entrada' as tipo,
                pagos.id_pago,
                empeno.id_empeno
            ")
            ->get();

        // Entradas por ventas en tienda
        $ventas = DB::table('venta_tienda')
            ->join('detalle_venta', 'venta_tienda.id_venta', '=', 'detalle_venta.id_venta')
            ->join('producto_tienda', 'detalle_venta.id_producto', '=', 'producto_tienda.id_producto')
            ->join('prendas', 'producto_tienda.id_prenda', '=', 'prendas.id_prenda')
            ->where('prendas.id_empresa', $idEmpresa)
            ->whereBetween('venta_tienda.fecha_venta', [$inicio, $fin])
            ->selectRaw("
                venta_tienda.fecha_venta as fecha,
                CONCAT('Venta tienda #', venta_tienda.folio) as concepto,
                SUM(detalle_venta.subtotal) as monto,
                'entrada' as tipo
            ")
            ->groupBy('venta_tienda.id_venta', 'venta_tienda.fecha_venta', 'venta_tienda.folio')
            ->get();

        // Salidas: préstamos otorgados
        $salidas = DB::table('empeno')
            ->where('id_empresa', $idEmpresa)
            ->whereBetween('fecha_empeno', [$inicio, $fin])
            ->selectRaw("
                fecha_empeno as fecha,
                CONCAT('Préstamo empeño #', id_empeno) as concepto,
                monto_prestado as monto,
                'salida' as tipo
            ")
            ->get();

        // Movimientos de caja adicionales
        $movimientosCaja = DB::table('movimientos_caja')
            ->join('usuario', 'movimientos_caja.id_usuario', '=', 'usuario.id_usuario')
            ->where('usuario.id_empresa', $idEmpresa)
            ->whereBetween('movimientos_caja.fecha', [$inicio, $fin])
            ->selectRaw("
                movimientos_caja.fecha as fecha,
                movimientos_caja.descripcion as concepto,
                movimientos_caja.monto,
                movimientos_caja.tipo
            ")
            ->get();

        // Unir y ordenar todos los movimientos
        $todos = collect()
            ->merge($entradas)
            ->merge($ventas)
            ->merge($salidas)
            ->merge($movimientosCaja)
            ->sortBy('fecha')
            ->values();

        // Calcular totales
        $totalEntradas = $todos->where('tipo', 'entrada')->sum('monto');
        $totalSalidas  = $todos->where('tipo', 'salida')->sum('monto');
        $balance       = $totalEntradas - $totalSalidas;

        // Resumen diario
        $resumenDiario = $todos
            ->groupBy('fecha')
            ->map(function ($movs, $fecha) {
                $entradas = $movs->where('tipo', 'entrada')->sum('monto');
                $salidas  = $movs->where('tipo', 'salida')->sum('monto');
                return [
                    'fecha'    => $fecha,
                    'entradas' => $entradas,
                    'salidas'  => $salidas,
                    'balance'  => $entradas - $salidas,
                ];
            })
            ->values();

        return response()->json([
            'movimientos'    => $todos,
            'resumen_diario' => $resumenDiario,
            'totales' => [
                'entradas' => (float) $totalEntradas,
                'salidas'  => (float) $totalSalidas,
                'balance'  => (float) $balance,
            ],
            'periodo' => ['inicio' => $inicio, 'fin' => $fin],
        ]);
    }

    // ══════════════════════════════════════════════
    //  GET /api/reportes/clientes
    //  Clientes segmentados: frecuentes, atrasados, inactivos
    // ══════════════════════════════════════════════
    public function clientes(Request $request)
    {
        $idEmpresa = $request->user()->id_empresa;

        // Clientes frecuentes (3+ empeños)
        $frecuentes = DB::table('clientes')
            ->join('empeno', 'clientes.id_cliente', '=', 'empeno.id_cliente')
            ->where('clientes.id_empresa', $idEmpresa)
            ->select(
                'clientes.id_cliente',
                'clientes.nombre',
                'clientes.apellido',
                DB::raw('COUNT(empeno.id_empeno) as total_empenos'),
                DB::raw('SUM(empeno.monto_prestado) as monto_total'),
                DB::raw('MAX(empeno.fecha_empeno) as ultimo_empeno')
            )
            ->groupBy('clientes.id_cliente', 'clientes.nombre', 'clientes.apellido')
            ->having('total_empenos', '>=', 3)
            ->orderByDesc('total_empenos')
            ->limit(10)
            ->get();

        // Clientes con empeños vencidos sin pagar
        $conAtraso = DB::table('clientes')
            ->join('empeno', 'clientes.id_cliente', '=', 'empeno.id_cliente')
            ->where('clientes.id_empresa', $idEmpresa)
            ->whereIn('empeno.estado', ['vencido'])
            ->select(
                'clientes.id_cliente',
                'clientes.nombre',
                'clientes.apellido',
                DB::raw('COUNT(empeno.id_empeno) as empenos_vencidos'),
                DB::raw('SUM(empeno.monto_prestado) as monto_en_riesgo'),
                DB::raw('MAX(DATEDIFF(NOW(), empeno.fecha_vencimiento)) as dias_max_atraso')
            )
            ->groupBy('clientes.id_cliente', 'clientes.nombre', 'clientes.apellido')
            ->orderByDesc('dias_max_atraso')
            ->get();

        // Clientes inactivos (sin empeños en los últimos 6 meses)
        $inactivos = DB::table('clientes')
            ->join('empeno', 'clientes.id_cliente', '=', 'empeno.id_cliente')
            ->where('clientes.id_empresa', $idEmpresa)
            ->select(
                'clientes.id_cliente',
                'clientes.nombre',
                'clientes.apellido',
                DB::raw('MAX(empeno.fecha_empeno) as ultimo_empeno'),
                DB::raw('DATEDIFF(NOW(), MAX(empeno.fecha_empeno)) as dias_inactivo')
            )
            ->groupBy('clientes.id_cliente', 'clientes.nombre', 'clientes.apellido')
            ->havingRaw('dias_inactivo > 180')
            ->orderByDesc('dias_inactivo')
            ->limit(20)
            ->get();

        // Clientes nuevos este mes
        $nuevosEsteMes = DB::table('clientes')
            ->where('id_empresa', $idEmpresa)
            ->where('fecha_registro', '>=', Carbon::now()->startOfMonth())
            ->count();

        return response()->json([
            'frecuentes'      => $frecuentes,
            'con_atraso'      => $conAtraso,
            'inactivos'       => $inactivos,
            'nuevos_mes'      => $nuevosEsteMes,
            'totales' => [
                'frecuentes'  => $frecuentes->count(),
                'con_atraso'  => $conAtraso->count(),
                'inactivos'   => $inactivos->count(),
            ],
        ]);
    }

    // ══════════════════════════════════════════════
    //  GET /api/reportes/inventario
    //  Estado del inventario de prendas
    // ══════════════════════════════════════════════
    public function inventario(Request $request)
    {
        $idEmpresa = $request->user()->id_empresa;

        // Inventario en garantía (prendas de empeños activos)
        $enGarantia = DB::table('prendas')
            ->join('empeno', 'prendas.id_prenda', '=', 'empeno.id_prenda')
            ->where('prendas.id_empresa', $idEmpresa)
            ->whereIn('empeno.estado', ['activo', 'vencido'])
            ->select(
                DB::raw('COUNT(*) as cantidad'),
                DB::raw('SUM(empeno.monto_prestado) as valor_prestado'),
                DB::raw('SUM(prendas.valor_estimado) as valor_estimado')
            )
            ->first();

        // Inventario en tienda (disponible para venta)
        $enTienda = DB::table('producto_tienda')
            ->join('prendas', 'producto_tienda.id_prenda', '=', 'prendas.id_prenda')
            ->where('prendas.id_empresa', $idEmpresa)
            ->where('producto_tienda.visible', 1)
            ->select(
                DB::raw('COUNT(*) as cantidad'),
                DB::raw('SUM(producto_tienda.precio) as valor_total')
            )
            ->first();

        // Artículos más empeñados (por categoría/tipo de prenda)
        $masEmpenados = DB::table('prendas')
            ->join('empeno', 'prendas.id_prenda', '=', 'empeno.id_prenda')
            ->where('prendas.id_empresa', $idEmpresa)
            ->select(
                'prendas.tipo',
                'prendas.material',
                DB::raw('COUNT(*) as veces_empenado'),
                DB::raw('AVG(empeno.monto_prestado) as monto_promedio')
            )
            ->groupBy('prendas.tipo', 'prendas.material')
            ->orderByDesc('veces_empenado')
            ->limit(10)
            ->get();

        // Artículos vendidos este mes
        $vendidosMes = DB::table('detalle_venta')
            ->join('venta_tienda', 'detalle_venta.id_venta', '=', 'venta_tienda.id_venta')
            ->join('producto_tienda', 'detalle_venta.id_producto', '=', 'producto_tienda.id_producto')
            ->join('prendas', 'producto_tienda.id_prenda', '=', 'prendas.id_prenda')
            ->where('prendas.id_empresa', $idEmpresa)
            ->where('venta_tienda.fecha_venta', '>=', Carbon::now()->startOfMonth())
            ->select(
                DB::raw('COUNT(*) as cantidad'),
                DB::raw('SUM(detalle_venta.subtotal) as ingresos')
            )
            ->first();

        // Valor total del inventario
        $valorTotal = DB::table('prendas')
            ->where('id_empresa', $idEmpresa)
            ->sum('valor_estimado');

        return response()->json([
            'en_garantia'    => $enGarantia,
            'en_tienda'      => $enTienda,
            'vendidos_mes'   => $vendidosMes,
            'mas_empenados'  => $masEmpenados,
            'valor_total'    => (float) $valorTotal,
        ]);
    }
}