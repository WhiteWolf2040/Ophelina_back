<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Apartado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminApartadosController extends Controller
{
    /**
     * Listado de apartados de la empresa del dueño (para gestionar entregas)
     * GET /api/tienda/apartados-admin
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $apartados = Apartado::whereHas('producto', function ($q) use ($user) {
                    $q->where('id_empresa', $user->id_empresa);
                })
                ->where('stripe_payment_status', 'pagado') // solo los ya pagados tienen sentido gestionar
                ->with(['producto.prenda', 'cliente'])
                ->orderBy('entregado', 'asc')   // pendientes de entregar primero
                ->orderBy('fecha_apartado', 'desc')
                ->get();

            $data = $apartados->map(function (Apartado $a) {
                return [
                    'id_apartado' => $a->id_apartado,
                    'producto' => $a->producto->nombre ?? 'Producto no disponible',
                    'cliente' => $a->cliente
                        ? trim($a->cliente->nombre . ' ' . $a->cliente->apellido)
                        : 'Cliente no disponible',
                    'monto_anticipo' => '$' . number_format((float) $a->monto_anticipo, 2),
                    'fecha_apartado' => optional($a->fecha_apartado)->format('d/m/Y'),
                    'entregado' => (bool) $a->entregado,
                    'fecha_entrega' => optional($a->fecha_entrega)->format('d/m/Y H:i'),
                    'entregado_por' => $a->id_usuario_entrego,
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);

        } catch (\Throwable $e) {
            Log::error('Error en AdminApartadosController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar apartados',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Marca un apartado como entregado, validando el código que el
     * cliente le da al dueño en persona al recoger el producto.
     * POST /api/tienda/apartados-admin/{id}/entregar
     * body: { codigo_entrega: "A7K92X" }
     */
    public function marcarEntregado(Request $request, $id)
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'codigo_entrega' => 'required|string|max:10',
            ]);

            $apartado = Apartado::whereHas('producto', function ($q) use ($user) {
                    $q->where('id_empresa', $user->id_empresa);
                })
                ->where('id_apartado', $id)
                ->first();

            if (!$apartado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Apartado no encontrado',
                ], 404);
            }

            if ($apartado->stripe_payment_status !== 'pagado') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este apartado aún no ha sido pagado, no se puede entregar',
                ], 422);
            }

            // ← Ya fue entregado antes: no se puede volver a marcar
            // (evita que se "reescriba" la fecha/usuario de una entrega ya hecha)
            if ($apartado->entregado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este producto ya fue marcado como entregado el '
                        . $apartado->fecha_entrega->format('d/m/Y H:i')
                        . '. No se puede volver a marcar.',
                ], 409);
            }

            // ← Validación del código: sin el código correcto, no se puede marcar
            $codigoIngresado = strtoupper(trim($validated['codigo_entrega']));
            if ($codigoIngresado !== strtoupper($apartado->codigo_entrega)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El código de entrega no coincide. Pídele al cliente que te muestre el código desde su app.',
                ], 422);
            }

            $apartado->entregado = true;
            $apartado->fecha_entrega = now();
            $apartado->id_usuario_entrego = $user->id_usuario;
            $apartado->estado = 'completado';
            $apartado->save();

            return response()->json([
                'success' => true,
                'message' => 'Entrega confirmada correctamente',
                'data' => [
                    'fecha_entrega' => $apartado->fecha_entrega->format('d/m/Y H:i'),
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en AdminApartadosController@marcarEntregado: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al marcar la entrega',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}