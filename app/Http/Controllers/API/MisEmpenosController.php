<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\ImagenPrenda;
use App\Models\MisEmpenos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MisEmpenosController extends Controller
{
    private function obtenerClienteId(Request $request): ?int
    {
        $user = $request->user();
        if (!$user) return null;

        $cliente = Cliente::where('id_usuario', $user->id_usuario)->first();
        return $cliente ? $cliente->id_cliente : null;
    }

    private function resolverImagenUrl($idPrenda): ?string
    {
        if (empty($idPrenda)) return null;

        $imagen = ImagenPrenda::where('id_prenda', $idPrenda)->where('es_principal', true)->first();
        if (!$imagen) {
            $imagen = ImagenPrenda::where('id_prenda', $idPrenda)->first();
        }
        if (!$imagen) return null;

        if (!empty($imagen->cloudinary_url)) return $imagen->cloudinary_url;
        if (!empty($imagen->imagen_data)) return url('/api/imagen-prenda/' . $idPrenda);
        return null;
    }

    public function getMisEmpenos(Request $request)
    {
        try {
            $idCliente = $this->obtenerClienteId($request);

            if (!$idCliente) {
                return response()->json(['success' => false, 'message' => 'Cliente no encontrado para este usuario'], 404);
            }

            // ✅ NUEVO: 'tasa' agregada al eager load, para exponer el % real
            $empenos = MisEmpenos::with(['prenda', 'pagos', 'empresa', 'tasa'])
                ->where('id_cliente', $idCliente)
                ->where('estado', '!=', 'en_tienda')
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
            //  ENVIAR NÚMEROS SIN FORMATO
            'prestado' => (float) $e->monto_prestado,
            'prestadoNumerico' => (float) $e->monto_prestado,
            'intereses' => (float) ($e->intereses ?? 0),
            'tasaPorcentaje' => optional($e->tasa)->porcentaje,
            'plazoMeses' => $e->plazo_meses ?? 1,
            'totalPagar' => (float) $e->total_pagar,
            'totalPagarNumerico' => $e->total_pagar,
            'diasRetraso' => $e->dias_retraso,
            'mora' => (float) $e->mora,
            'moraNumerica' => $e->mora,
            'totalPagarConMora' => (float) $e->total_pagar_con_mora,
            'saldoRestante' => (float) $saldo['saldo_restante'],
            'saldoRestanteNumerico' => $saldo['saldo_restante'],
            'totalAbonado' => (float) $saldo['total_abonado'],
            'vencimiento' => optional($e->fecha_vencimiento)->format('d/m/Y'),
            'vencimientoTimestamp' => $e->fecha_vencimiento ? $e->fecha_vencimiento->timestamp : null,
            'imagen' => $this->resolverImagenUrl($e->id_prenda),
            'abonos' => $e->abonos_formateados,
            'pagadoCompleto' => $e->pagado_completo,
            'enTienda' => $e->en_tienda,
            'proximoAVencer' => $e->proximo_a_vencer,
            'estado' => $e->estado_frontend,
        ];
    });

            $prioridadEstado = [
                'VENCIDO' => 0, 'PROXIMO A VENCER' => 1, 'ACTIVO' => 2, 'EN TIENDA' => 3, 'PAGADO' => 4,
            ];

            $data = $data->sort(function ($a, $b) use ($prioridadEstado) {
                $prioridadA = $prioridadEstado[$a['estado']] ?? 5;
                $prioridadB = $prioridadEstado[$b['estado']] ?? 5;

                if ($prioridadA !== $prioridadB) return $prioridadA <=> $prioridadB;

                $tsA = $a['vencimientoTimestamp'] ?? PHP_INT_MAX;
                $tsB = $b['vencimientoTimestamp'] ?? PHP_INT_MAX;
                return $tsA <=> $tsB;
            })->values();

            return response()->json(['success' => true, 'data' => $data]);

        } catch (\Throwable $e) {
            Log::error('Error en MisEmpenosController@getMisEmpenos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar tus empeños',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getResumenMisEmpenos(Request $request)
    {
        try {
            $idCliente = $this->obtenerClienteId($request);

            if (!$idCliente) {
                return response()->json(['success' => false, 'message' => 'Cliente no encontrado para este usuario'], 404);
            }

            $empenos = MisEmpenos::with('pagos')
                ->where('id_cliente', $idCliente)
                ->get();

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

    public function getMisEmpenosDetalle(Request $request, $id)
    {
        try {
            $idCliente = $this->obtenerClienteId($request);

            if (!$idCliente) {
                return response()->json(['success' => false, 'message' => 'Cliente no encontrado para este usuario'], 404);
            }

            // ✅ NUEVO: 'tasa' agregada
            $empeno = MisEmpenos::with(['prenda', 'pagos', 'empresa', 'aval', 'tasa'])
                ->where('id_empeno', $id)
                ->where('id_cliente', $idCliente)
                ->first();

            if (!$empeno) {
                return response()->json(['success' => false, 'message' => 'Empeño no encontrado'], 404);
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
                'tasaPorcentaje' => optional($empeno->tasa)->porcentaje,
                'plazoMeses' => $empeno->plazo_meses ?? 1,
                'totalPagar' => '$' . number_format($empeno->total_pagar, 2),
                'diasRetraso' => $empeno->dias_retraso,
                'mora' => $empeno->mora > 0 ? '$' . number_format($empeno->mora, 2) : null,
                'moraNumerica' => $empeno->mora,
                'totalPagarConMora' => '$' . number_format($empeno->total_pagar_con_mora, 2),
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

            return response()->json(['success' => true, 'data' => $data]);

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