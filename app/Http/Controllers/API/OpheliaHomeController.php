<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpheliaHomeController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $empresaId = $user->id_empresa;

            // Buscamos el registro del cliente vinculado a este usuario
            $cliente = DB::table('clientes')
                ->where('id_usuario', $user->id_usuario)
                ->where('id_empresa', $empresaId)
                ->first();

            if (!$cliente) {
                return response()->json(['success' => false, 'message' => 'Cliente no encontrado'], 404);
            }

            $clienteId = $cliente->id_cliente;

            // Resumen
            $empenos = DB::table('empeno')
                ->where('id_cliente', $clienteId)
                ->where('id_empresa', $empresaId)
                ->get();

            $activos = $empenos->where('estado', 'activo')->count();
            $vencidos = $empenos->where('estado', 'vencido')->count();

            // Para calcular saldo pendiente y pagados, necesitamos sumar pagos.
            $idsEmpenos = $empenos->pluck('id_empeno');
            $pagos = DB::table('pagos')
                ->whereIn('id_empeno', $idsEmpenos)
                ->select('id_empeno', DB::raw('SUM(monto_total) as total_pagado'))
                ->groupBy('id_empeno')
                ->get()
                ->keyBy('id_empeno');

            $deudaTotal = 0;
            $capitalTotal = 0;
            $interesesTotal = 0;
            $proximosVencer = [];

            foreach ($empenos as $empeno) {
                $totalPagado = $pagos->get($empeno->id_empeno)->total_pagado ?? 0;
                $saldoRestante = max(0, $empeno->monto_prestado - $totalPagado);
                $deudaTotal += $saldoRestante;
                $capitalTotal += $empeno->monto_prestado;
                $interesesTotal += $empeno->intereses ?? 0;

                // Próximos a vencer (activos con vencimiento en 7 días)
                if ($empeno->estado === 'activo' && $empeno->fecha_vencimiento) {
                    $dias = now()->diffInDays($empeno->fecha_vencimiento, false);
                    if ($dias <= 7 && $dias >= 0) {
                        $proximosVencer[] = [
                            'id' => $empeno->id_empeno,
                            'nombre' => DB::table('prendas')->where('id_prenda', $empeno->id_prenda)->value('descripcion') ?? 'Sin nombre',
                            'fechaVencimiento' => date('d/m/Y', strtotime($empeno->fecha_vencimiento)),
                            'diasRestantes' => $dias . ' días restantes'
                        ];
                    }
                }
            }

            // Calcular pagados (aquellos con saldoRestante <= 0)
            $pagados = $empenos->filter(function ($e) use ($pagos) {
                $totalPagado = $pagos->get($e->id_empeno)->total_pagado ?? 0;
                return $e->monto_prestado - $totalPagado <= 0;
            })->count();

            $actividadReciente = [];

            // Últimos 5 pagos del cliente
            $pagosRecientes = DB::table('pagos')
                ->join('empeno', 'empeno.id_empeno', '=', 'pagos.id_empeno')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.id_cliente', $clienteId)
                ->where('empeno.id_empresa', $empresaId)
                ->orderBy('pagos.fecha_pago', 'desc')
                ->limit(5)
                ->select('pagos.*', 'empeno.id_empeno', 'prendas.descripcion as prenda_nombre')
                ->get();

            foreach ($pagosRecientes as $pago) {
                $actividadReciente[] = [
                    'id' => $pago->id_pago,
                    'tipo' => 'pago',
                    'titulo' => 'Pago realizado',
                    'detalle' => $pago->prenda_nombre . ' - $' . number_format($pago->monto_total, 2) . ' • ' . date('d/m/Y', strtotime($pago->fecha_pago)),
                    'icono' => 'PaymentIcon'
                ];
            }

            // También agregamos empeños nuevos recientes
            $empenosRecientes = DB::table('empeno')
                ->join('prendas', 'prendas.id_prenda', '=', 'empeno.id_prenda')
                ->where('empeno.id_cliente', $clienteId)
                ->where('empeno.id_empresa', $empresaId)
                ->orderBy('empeno.fecha_empeno', 'desc')
                ->limit(3)
                ->select('empeno.*', 'prendas.descripcion as prenda_nombre')
                ->get();

            foreach ($empenosRecientes as $e) {
                $actividadReciente[] = [
                    'id' => 'nuevo_' . $e->id_empeno,
                    'tipo' => 'nuevo',
                    'titulo' => 'Nuevo empeño',
                    'detalle' => $e->prenda_nombre . ' - $' . number_format($e->monto_prestado, 2) . ' • ' . date('d/m/Y', strtotime($e->fecha_empeno)),
                    'icono' => 'AddCircleOutlineIcon'
                ];
            }

            usort($actividadReciente, function($a, $b) {
                preg_match('/•\s*(\d{2}\/\d{2}\/\d{4})/', $a['detalle'], $matchA);
                preg_match('/•\s*(\d{2}\/\d{2}\/\d{4})/', $b['detalle'], $matchB);
                $fechaA = isset($matchA[1]) ? strtotime(str_replace('/', '-', $matchA[1])) : 0;
                $fechaB = isset($matchB[1]) ? strtotime(str_replace('/', '-', $matchB[1])) : 0;
                return $fechaB - $fechaA;
            });
            $actividadReciente = array_slice($actividadReciente, 0, 5);

            // Resumen final
            $resumen = [
                'activos' => (string)$activos,
                'totalPendiente' => '$' . number_format($deudaTotal, 2),
                'proximoVencimiento' => count($proximosVencer) > 0 ? min(array_column($proximosVencer, 'diasRestantes')) : 'Sin vencimientos',
                'precioOro' => '$' . number_format(DB::table('precio_oro')->orderBy('fecha_actualizacion', 'desc')->value('precio_gramo_24k') ?? 850, 2)
            ];

            // Desglose de deuda
            $deuda = [
                'capital' => '$' . number_format($capitalTotal, 2),
                'intereses' => '$' . number_format($interesesTotal, 2),
                'total' => '$' . number_format($capitalTotal + $interesesTotal, 2)
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'resumen' => $resumen,
                    'proximosVencer' => $proximosVencer,
                    'deuda' => $deuda,
                    'actividadReciente' => $actividadReciente
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar dashboard del cliente',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}