<?php
// app/Http/Controllers/API/MisEmpenosController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ImagenPrenda;
use App\Models\MisEmpenos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MisEmpenosController extends Controller
{
    /**
     * Helper: obtiene el id_cliente del usuario autenticado.
     * Sigue el mismo patrón que OpheliaTiendaController.
     */
    private function obtenerClienteId(Request $request): ?int
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        $cliente = Cliente::where('id_usuario', $user->id_usuario)->first();

        return $cliente ? $cliente->id_cliente : null;
    }

    /**
     * Helper: URL de la imagen principal de una prenda (Cloudinary o binario legacy).
     */
    private function resolverImagenUrl($idPrenda): ?string
    {
        if (empty($idPrenda)) {
            return null;
        }

        $imagen = ImagenPrenda::where('id_prenda', $idPrenda)
            ->where('es_principal', true)
            ->first();

        if (!$imagen) {
            $imagen = ImagenPrenda::where('id_prenda', $idPrenda)->first();
        }

        if (!$imagen) {
            return null;
        }

        if (!empty($imagen->cloudinary_url)) {
            return $imagen->cloudinary_url;
        }

        if (!empty($imagen->imagen_data)) {
            return url('/api/imagen-prenda/' . $idPrenda);
        }

        return null;
    }

    /**
     * Listado de empeños del cliente autenticado.
     * GET /api/cliente/empenos
     */
    public function getMisEmpenos(Request $request)
    {
        try {
            $idCliente = $this->obtenerClienteId($request);

            if (!$idCliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado para este usuario',
                ], 404);
            }

            $empenos = MisEmpenos::with(['prenda', 'pagos', 'empresa'])
                ->where('id_cliente', $idCliente)
                ->orderBy('fecha_empeno', 'desc')
                ->get();

            $data = $empenos->map(function (MisEmpenos $e) {
                $saldo = $e->saldo_pendiente;

                return [
                    'id' => $e->id_empeno,
                    'folio' => $e->folio,
                    'nombre' => $e->prenda->descripcion ?? 'Prenda sin descripción',
                    'descripcion' => $e->prenda->descripcion ?? '',
                    'gramos' => $e->prenda->peso_gramos ? $e->prenda->peso_gramos . ' gramos' : null,
                    'casaEmpeño' => optional($e->empresa)->nombre_comercial ?? optional($e->empresa)->nombre ?? 'N/A',
                    'prestado' => '$' . number_format($e->monto_prestado, 2),
                    'prestadoNumerico' => (float) $e->monto_prestado,
                    'intereses' => '$' . number_format($e->intereses ?? 0, 2),
                    'totalPagar' => '$' . number_format($e->total_pagar, 2),
                    'totalPagarNumerico' => $e->total_pagar,
                    'saldoRestante' => '$' . number_format($saldo['saldo_restante'], 2),
                    'saldoRestanteNumerico' => $saldo['saldo_restante'],
                    'totalAbonado' => '$' . number_format($saldo['total_abonado'], 2),
                    'vencimiento' => optional($e->fecha_vencimiento)->format('d/m/Y'),
                    'imagen' => $this->resolverImagenUrl($e->id_prenda),
                    'abonos' => $e->abonos_formateados,
                    'pagadoCompleto' => $e->pagado_completo,
                    'enTienda' => $e->en_tienda,
                    'proximoAVencer' => $e->proximo_a_vencer,
                    'estado' => $e->estado_frontend,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en MisEmpenosController@getMisEmpenos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar tus empeños',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resumen/estadísticas de los empeños del cliente autenticado.
     * GET /api/cliente/empenos/resumen
     */
    public function getResumenMisEmpenos(Request $request)
    {
        try {
            $idCliente = $this->obtenerClienteId($request);

            if (!$idCliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado para este usuario',
                ], 404);
            }

            $empenos = MisEmpenos::where('id_cliente', $idCliente)->get();

            $activos = $empenos->filter(fn (MisEmpenos $e) => $e->estado_frontend === 'ACTIVO')->count();
            $vencidos = $empenos->filter(fn (MisEmpenos $e) => $e->estado_frontend === 'VENCIDO')->count();
            $pagados = $empenos->filter(fn (MisEmpenos $e) => $e->estado_frontend === 'PAGADO')->count();
            $proximosAVencer = $empenos->filter(fn (MisEmpenos $e) => $e->proximo_a_vencer)->count();

            $totalPrestado = $empenos->sum(fn (MisEmpenos $e) => (float) $e->monto_prestado);
            $totalPendiente = $empenos->sum(fn (MisEmpenos $e) => $e->saldo_pendiente['saldo_restante']);

            return response()->json([
                'success' => true,
                'total' => $empenos->count(),
                'activos' => $activos,
                'vencidos' => $vencidos,
                'pagados' => $pagados,
                'proximos_a_vencer' => $proximosAVencer,
                'total_prestado' => number_format($totalPrestado, 2),
                'total_pendiente' => number_format($totalPendiente, 2),
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en MisEmpenosController@getResumenMisEmpenos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar el resumen',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detalle de un empeño específico del cliente autenticado.
     * GET /api/cliente/empenos/{id}
     */
    public function getMisEmpenosDetalle(Request $request, $id)
    {
        try {
            $idCliente = $this->obtenerClienteId($request);

            if (!$idCliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado para este usuario',
                ], 404);
            }

            $empeno = MisEmpenos::with(['prenda', 'pagos', 'empresa', 'aval', 'tasa'])
                ->where('id_empeno', $id)
                ->where('id_cliente', $idCliente) // seguridad: solo su propio empeño
                ->first();

            if (!$empeno) {
                return response()->json([
                    'success' => false,
                    'message' => 'Empeño no encontrado',
                ], 404);
            }

            $saldo = $empeno->saldo_pendiente;

            $data = [
                'id' => $empeno->id_empeno,
                'folio' => $empeno->folio,
                'nombre' => $empeno->prenda->descripcion ?? 'Prenda sin descripción',
                'descripcion' => $empeno->prenda->descripcion ?? '',
                'gramos' => $empeno->prenda->peso_gramos ? $empeno->prenda->peso_gramos . ' gramos' : null,
                'material' => $empeno->prenda->material ?? null,
                'casaEmpeño' => optional($empeno->empresa)->nombre_comercial ?? optional($empeno->empresa)->nombre ?? 'N/A',
                'prestado' => '$' . number_format($empeno->monto_prestado, 2),
                'intereses' => '$' . number_format($empeno->intereses ?? 0, 2),
                'totalPagar' => '$' . number_format($empeno->total_pagar, 2),
                'saldoRestante' => '$' . number_format($saldo['saldo_restante'], 2),
                'totalAbonado' => '$' . number_format($saldo['total_abonado'], 2),
                'fechaEmpeno' => optional($empeno->fecha_empeno)->format('d/m/Y'),
                'vencimiento' => optional($empeno->fecha_vencimiento)->format('d/m/Y'),
                'imagen' => $this->resolverImagenUrl($empeno->id_prenda),
                'abonos' => $empeno->abonos_formateados,
                'pagadoCompleto' => $empeno->pagado_completo,
                'enTienda' => $empeno->en_tienda,
                'proximoAVencer' => $empeno->proximo_a_vencer,
                'estado' => $empeno->estado_frontend,
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en MisEmpenosController@getMisEmpenosDetalle: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar el detalle del empeño',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}