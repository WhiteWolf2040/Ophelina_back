<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Apartado;
use App\Models\Prenda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminApartadosController extends Controller
{
    /**
     * Listado de apartados de la empresa del dueño (para gestionar entregas)
     * Soporta búsqueda opcional por ?buscar= (producto, cliente o código)
     * GET /api/tienda/apartados-admin
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();

            $query = Apartado::whereHas('producto', function ($q) use ($user) {
                    $q->where('id_empresa', $user->id_empresa);
                })
                ->where('stripe_payment_status', 'pagado')
                ->where(function ($q) {
                    $q->where('entregado', false)
                      ->orWhere('fecha_entrega', '>=', now()->subDays(30));
                });

            // SCRUM-355: búsqueda por producto, cliente o código de entrega
            if ($request->filled('buscar')) {
                $buscar = $request->buscar;
                $query->where(function ($q) use ($buscar) {
                    $q->where('codigo_entrega', 'like', "%{$buscar}%")
                      ->orWhereHas('producto', function ($p) use ($buscar) {
                          $p->where('nombre', 'like', "%{$buscar}%");
                      })
                      ->orWhereHas('cliente', function ($c) use ($buscar) {
                          $c->where('nombre', 'like', "%{$buscar}%")
                            ->orWhere('apellido', 'like', "%{$buscar}%");
                      });
                });
            }

            $apartados = $query
                // ✅ AMPLIADO: ahora también traemos precio/descuento/id_prenda,
                // necesarios para calcular el monto pendiente y para SCRUM-358
                ->with([
                    'producto:id_producto,nombre,id_empresa,precio,descuento,id_prenda',
                    'cliente:id_cliente,nombre,apellido',
                ])
                ->orderBy('entregado', 'asc')
                ->orderBy('fecha_apartado', 'desc')
                ->get();

            $data = $apartados->map(function (Apartado $a) {
                // SCRUM-356: calcular monto pendiente = precio total - anticipo
                $montoPendienteNumerico = $this->calcularMontoPendiente($a);

                return [
                    'id_apartado' => $a->id_apartado,
                    'producto' => $a->producto->nombre ?? 'Producto no disponible',
                    'cliente' => $a->cliente
                        ? trim($a->cliente->nombre . ' ' . $a->cliente->apellido)
                        : 'Cliente no disponible',
                    'monto_anticipo' => '$' . number_format((float) $a->monto_anticipo, 2),
                    // SCRUM-355 + 356: detalle del apartado con el monto pendiente
                    'monto_pendiente' => '$' . number_format($montoPendienteNumerico, 2),
                    'monto_pendiente_numerico' => $montoPendienteNumerico,
                    'fecha_apartado' => optional($a->fecha_apartado)->format('d/m/Y'),
                    'codigo_entrega' => $a->codigo_entrega,
                    'entregado' => (bool) $a->entregado,
                    'fecha_entrega' => optional($a->fecha_entrega)->format('d/m/Y H:i'),
                    'entregado_por' => $a->id_usuario_entrego,
                    // SCRUM-357: para mostrar cómo se cobró el resto, en el historial de entregados
                    'monto_pagado_resto' => $a->monto_pagado_resto
                        ? '$' . number_format((float) $a->monto_pagado_resto, 2)
                        : null,
                    'metodo_pago_resto' => $a->metodo_pago_resto,
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
     * Marca un apartado como entregado: valida el código, registra el pago
     * del saldo restante y libera el inventario marcando el producto/prenda
     * como vendido.
     */
    public function marcarEntregado(Request $request, $id)
    {
        try {
            $user = $request->user();

            $validated = $request->validate([
                'codigo_entrega' => 'required|string|max:10',
                // SCRUM-357: opcionales — si no los manda el front, se asume
                // que se cobró exactamente el monto pendiente calculado y
                // que fue en efectivo (comportamiento por defecto)
                'monto_pagado' => 'nullable|numeric|min:0',
                'metodo_pago' => 'nullable|string|in:efectivo,tarjeta,transferencia',
            ]);

            $apartado = Apartado::whereHas('producto', function ($q) use ($user) {
                    $q->where('id_empresa', $user->id_empresa);
                })
                ->where('id_apartado', $id)
                ->with('producto')
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

            if ($apartado->entregado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este producto ya fue marcado como entregado el '
                        . $apartado->fecha_entrega->format('d/m/Y H:i')
                        . '. No se puede volver a marcar.',
                ], 409);
            }

            $codigoIngresado = strtoupper(trim($validated['codigo_entrega']));
            if ($codigoIngresado !== strtoupper($apartado->codigo_entrega)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El código de entrega no coincide. Pídele al cliente que te muestre el código desde su app.',
                ], 422);
            }

            // SCRUM-356/357: si no mandan el monto, se calcula y se asume
            // que se cobró completo
            $montoPendiente = $this->calcularMontoPendiente($apartado);
            $montoPagado = $validated['monto_pagado'] ?? $montoPendiente;
            $metodoPago = $validated['metodo_pago'] ?? 'efectivo';

            $apartado->entregado = true;
            $apartado->fecha_entrega = now();
            $apartado->id_usuario_entrego = $user->id_usuario;
            $apartado->estado = 'completado';
            // SCRUM-357: registrar el pago del saldo restante
            $apartado->monto_pagado_resto = $montoPagado;
            $apartado->metodo_pago_resto = $metodoPago;
            $apartado->save();

            // SCRUM-358: marcar producto como vendido y liberar inventario
            $producto = $apartado->producto;
            if ($producto && $producto->id_prenda) {
                Prenda::where('id_prenda', $producto->id_prenda)->update([
                    'estado' => 'Vendido',
                ]);
            }
            // El producto ya estaba con visible=0 desde que se apartó
            // (OpheliaTiendaController::apartar), así que no necesita
            // tocarse de nuevo aquí — solo la Prenda pasa de 'Apartado' a
            // 'Vendido' para reflejar correctamente el inventario del dueño.

            return response()->json([
                'success' => true,
                'message' => 'Entrega confirmada correctamente',
                'data' => [
                    'fecha_entrega' => $apartado->fecha_entrega->format('d/m/Y H:i'),
                    'monto_pagado_resto' => '$' . number_format($montoPagado, 2),
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

    /**
     *precio total del producto (con descuento si aplica) menos
     * el anticipo ya pagado.
     */
    private function calcularMontoPendiente(Apartado $apartado): float
    {
        $producto = $apartado->producto;
        if (!$producto) {
            return 0;
        }

        $precio = (float) $producto->precio;
        $descuento = (float) ($producto->descuento ?? 0);
        $precioFinal = $descuento > 0 ? $precio * (1 - $descuento / 100) : $precio;

        return max(0, round($precioFinal - (float) $apartado->monto_anticipo, 2));
    }
}