<?php
// app/Http/Controllers/API/MisEmpenosController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\MisEmpenos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MisEmpenosController extends Controller
{
    /**
     * Obtener todos los empeños del cliente autenticado
     * GET /api/cliente/empenos
     */
    public function getMisEmpenos(Request $request)
    {
        try {
            $user = $request->user();
            $clienteId = $user->id_cliente ?? null;

            if (!$clienteId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado'
                ], 404);
            }

            $empenos = MisEmpenos::where('id_cliente', $clienteId)
                ->where('id_empresa', $user->id_empresa)
                ->with(['cliente', 'prenda', 'pagos'])
                ->orderBy('fecha_empeno', 'desc')
                ->get();

            $empenosFormateados = $empenos->map(function (MisEmpenos $empeno) {
                return [
                    'id' => $empeno->id_empeno,
                    'nombre' => $empeno->prenda?->descripcion ?? 'Sin descripción',
                    'descripcion' => $empeno->prenda
                        ? $empeno->prenda->tipo . ' - ' . $empeno->prenda->material
                        : '',
                    'prestado' => '$' . number_format($empeno->monto_prestado, 2),
                    'prestadoNumerico' => floatval($empeno->monto_prestado),
                    'totalPagar' => '$' . number_format($empeno->total_pagar, 2),
                    'totalPagarNumerico' => floatval($empeno->total_pagar),
                    'vencimiento' => $empeno->fecha_vencimiento
                        ? date('d/m/Y', strtotime($empeno->fecha_vencimiento))
                        : 'Sin fecha',
                    'imagen' => $empeno->prenda && $empeno->prenda->imagen
                        ? asset('storage/' . $empeno->prenda->imagen)
                        : 'https://via.placeholder.com/150',
                    'gramos' => $empeno->prenda && $empeno->prenda->peso_gramos
                        ? $empeno->prenda->peso_gramos . ' gramos'
                        : 'N/A',
                    'casaEmpeño' => $empeno->empresa?->nombre ?? 'JSK',
                    // Nota: si 'intereses' es la tasa, usa $empeno->intereses . '%', 
                    // pero si es monto, mejor usa $empeno->tasa?->porcentaje ?? 0
                    'tasaInteres' => $empeno->intereses . '%',
                    'intereses' => '$' . number_format($empeno->intereses ?? 0, 2),
                    'interesesNumerico' => floatval($empeno->intereses ?? 0),
                    'pagadoCompleto' => $empeno->pagado_completo,
                    'enTienda' => $empeno->en_tienda,
                    'saldoRestante' => floatval($empeno->saldo_pendiente['saldo_restante']),
                    'estado' => $empeno->estado_frontend,
                    'abonos' => $empeno->abonos_formateados
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $empenosFormateados,
                'total' => $empenosFormateados->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Error en getMisEmpenos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los empeños',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener detalle de un empeño específico
     * GET /api/cliente/empenos/{id}
     */
    public function getMisEmpenosDetalle(Request $request, $id)
    {
        try {
            $user = $request->user();
            $clienteId = $user->id_cliente ?? null;

            if (!$clienteId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado'
                ], 404);
            }

            $empeno = MisEmpenos::where('id_empeno', $id)
                ->where('id_cliente', $clienteId)
                ->where('id_empresa', $user->id_empresa)
                ->with(['cliente', 'prenda', 'pagos', 'amortizaciones'])
                ->first();

            if (!$empeno) {
                return response()->json([
                    'success' => false,
                    'message' => 'Empeño no encontrado'
                ], 404);
            }

            $empenoFormateado = [
                'id' => $empeno->id_empeno,
                'nombre' => $empeno->prenda?->descripcion ?? 'Sin descripción',
                'descripcion' => $empeno->prenda
                    ? $empeno->prenda->tipo . ' - ' . $empeno->prenda->material
                    : '',
                'prestado' => '$' . number_format($empeno->monto_prestado, 2),
                'prestadoNumerico' => floatval($empeno->monto_prestado),
                'totalPagar' => '$' . number_format($empeno->total_pagar, 2),
                'totalPagarNumerico' => floatval($empeno->total_pagar),
                'vencimiento' => $empeno->fecha_vencimiento
                    ? date('d/m/Y', strtotime($empeno->fecha_vencimiento))
                    : 'Sin fecha',
                'imagen' => $empeno->prenda && $empeno->prenda->imagen
                    ? asset('storage/' . $empeno->prenda->imagen)
                    : 'https://via.placeholder.com/150',
                'gramos' => $empeno->prenda && $empeno->prenda->peso_gramos
                    ? $empeno->prenda->peso_gramos . ' gramos'
                    : 'N/A',
                'casaEmpeño' => $empeno->empresa?->nombre ?? 'JSK',
                'tasaInteres' => $empeno->intereses . '%',
                'intereses' => '$' . number_format($empeno->intereses ?? 0, 2),
                'interesesNumerico' => floatval($empeno->intereses ?? 0),
                'pagadoCompleto' => $empeno->pagado_completo,
                'enTienda' => $empeno->en_tienda,
                'saldoRestante' => floatval($empeno->saldo_pendiente['saldo_restante']),
                'estado' => $empeno->estado_frontend,
                'abonos' => $empeno->abonos_formateados,
                'folio' => $empeno->folio,
                'fecha_empeno' => $empeno->fecha_empeno
                    ? date('d/m/Y', strtotime($empeno->fecha_empeno))
                    : 'N/A'
            ];

            return response()->json([
                'success' => true,
                'data' => $empenoFormateado
            ]);
        } catch (\Exception $e) {
            Log::error('Error en getMisEmpenosDetalle: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el detalle del empeño',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen de empeños del cliente
     * GET /api/cliente/empenos/resumen
     */
    public function getResumenMisEmpenos(Request $request)
    {
        try {
            $user = $request->user();
            $clienteId = $user->id_cliente ?? null;

            if (!$clienteId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado'
                ], 404);
            }

            $empenos = MisEmpenos::where('id_cliente', $clienteId)
                ->where('id_empresa', $user->id_empresa)
                ->get();

            $totalEmpenos = $empenos->count();
            $activos = $empenos->where('estado', 'activo')->count();
            $vencidos = $empenos->where('estado', 'vencido')->count();
            $pagados = $empenos->filter(fn($e) => $e->pagado_completo)->count();

            $deudaTotal = $empenos->sum(fn($e) => $e->saldo_pendiente['saldo_restante']);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_empenos' => $totalEmpenos,
                    'activos' => $activos,
                    'vencidos' => $vencidos,
                    'pagados' => $pagados,
                    'deuda_total' => '$' . number_format($deudaTotal, 2)
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener resumen',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}