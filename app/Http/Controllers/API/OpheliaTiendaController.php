<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductoTienda;
use App\Models\Apartado;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class OpheliaTiendaController extends Controller
{
    /**
     * Listado de productos de la tienda con búsqueda y filtro por categoría
     * GET /api/tienda/productos
     */
    public function getProductos(Request $request)
    {
        $query = ProductoTienda::with('prenda')
            ->where('visible', 1);

        // Búsqueda: nombre, descripción del producto, o tipo/descripción/material de la prenda
        if ($request->filled('buscar')) {
            $buscar = $request->buscar;
            $query->where(function ($q) use ($buscar) {
                $q->where('nombre', 'like', "%{$buscar}%")
                  ->orWhere('descripcion', 'like', "%{$buscar}%")
                  ->orWhereHas('prenda', function ($p) use ($buscar) {
                      $p->where('tipo', 'like', "%{$buscar}%")
                        ->orWhere('descripcion', 'like', "%{$buscar}%")
                        ->orWhere('material', 'like', "%{$buscar}%");
                  });
            });
        }

        // Filtro por categoría
        if ($request->filled('categoria') && $request->categoria !== 'todas') {
            switch ($request->categoria) {
                case 'exclusivo':
                    $query->where('destacado', 1);
                    break;
                case 'electronicos':
                    $query->whereHas('prenda', function ($p) {
                        $p->where('tipo', 'Electrónica');
                    });
                    break;
                case 'oro':
                case 'plata':
                    $query->whereHas('prenda', function ($p) use ($request) {
                        $p->where('material', $request->categoria);
                    });
                    break;
            }
        }

        $productos = $query->orderBy('fecha_publicacion', 'desc')->get();

        $data = $productos->map(function (ProductoTienda $p) {
            $precio = (float) $p->precio;
            $descuento = (float) ($p->descuento ?? 0);
            $precioConDescuento = $descuento > 0
                ? $precio * (1 - $descuento / 100)
                : $precio;

            return [
                'id' => $p->id_producto,
                'nombre' => $p->nombre,
                'descripcion' => $p->descripcion,
                'precio' => '$' . number_format($precioConDescuento, 2),
                'precioOriginal' => '$' . number_format($precio, 2),
                'precioNumerico' => $precioConDescuento,
                'descuento' => $descuento,
                'anticipo' => '$' . number_format($precioConDescuento * 0.5, 2),
                'anticipoNumerico' => round($precioConDescuento * 0.5, 2),
                'imagen' => $this->resolverImagenUrl($p->id_prenda),
                'categoria' => $this->obtenerCategoria($p->prenda),
                'material' => $p->prenda->material ?? null,
                'exclusivo' => (bool) $p->destacado,
                'estado_producto' => $p->estado_producto,
                'stock' => $p->stock,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Devuelve la URL pública de la imagen principal guardada en imagen_prenda
     * para la prenda asociada a este producto. Regresa null si no tiene imagen.
     */
    private function resolverImagenUrl($idPrenda)
    {
        if (empty($idPrenda)) {
            return null;
        }

        $tieneImagen = \App\Models\ImagenPrenda::where('id_prenda', $idPrenda)->exists();

        if (!$tieneImagen) {
            return null;
        }

        return url('/api/imagen-prenda/' . $idPrenda);
    }

    /**
     * Iniciar el apartado de un producto: crea el registro en 'apartados'
     * (pendiente de pago) y una sesión de Stripe Checkout por el 50% de anticipo.
     * POST /api/tienda/productos/{id}/apartar
     */
    public function apartar(Request $request, $id)
    {
        try {
            $user = $request->user();

            $cliente = Cliente::where('id_usuario', $user->id_usuario)->first();

            if (!$cliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado',
                ], 404);
            }

            $clienteId = $cliente->id_cliente;

            $producto = ProductoTienda::where('id_producto', $id)
                ->where('visible', 1)
                ->first();

            if (!$producto) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este producto ya no está disponible',
                ], 404);
            }

            $yaApartado = Apartado::where('id_producto', $id)
                ->where('estado', 'activo')
                ->whereIn('stripe_payment_status', ['pendiente', 'pagado'])
                ->exists();

            if ($yaApartado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este producto ya está apartado',
                ], 409);
            }

            // ✅ El anticipo se calcula sobre el precio YA con descuento aplicado
            $descuento = (float) ($producto->descuento ?? 0);
            $precioFinal = $descuento > 0
                ? $producto->precio * (1 - $descuento / 100)
                : (float) $producto->precio;

            $montoAnticipo = round($precioFinal * 0.5, 2);

            $apartado = Apartado::create([
                'id_cliente' => $clienteId,
                'id_producto' => $producto->id_producto,
                'fecha_apartado' => now(),
                'fecha_expiracion' => now()->addDays(3),
                'estado' => 'activo',
                'monto_anticipo' => $montoAnticipo,
                'stripe_payment_status' => 'pendiente',
                'notas' => 'Anticipo del 50% para apartar el producto',
            ]);

            Stripe::setApiKey(env('STRIPE_SECRET'));

            $session = Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'mxn',
                        'product_data' => [
                            'name' => 'Anticipo (50%) - ' . $producto->nombre,
                        ],
                        'unit_amount' => intval(round($montoAnticipo * 100)),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => env('STRIPE_TIENDA_SUCCESS_URL', env('STRIPE_SUCCESS_URL')),
                'cancel_url' => env('STRIPE_TIENDA_CANCEL_URL', env('STRIPE_CANCEL_URL')),
                'metadata' => [
                    'id_apartado' => $apartado->id_apartado,
                ],
                'customer_email' => $user->correo,
            ]);

            $apartado->stripe_session_id = $session->id;
            $apartado->save();

            $producto->visible = 0;
            $producto->save();

            return response()->json([
                'success' => true,
                'data' => [
                    'checkout_url' => $session->url,
                    'id_apartado' => $apartado->id_apartado,
                    'anticipo' => '$' . number_format($montoAnticipo, 2),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error en OpheliaTiendaController@apartar: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar el apartado',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Apartados del cliente autenticado (pendientes de pago o ya pagados)
     * GET /api/tienda/apartados
     */
    public function misApartados(Request $request)
    {
        try {
            $user = $request->user();

            $cliente = Cliente::where('id_usuario', $user->id_usuario)->first();

            if (!$cliente) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $clienteId = $cliente->id_cliente;

            $apartados = Apartado::where('id_cliente', $clienteId)
                ->whereIn('estado', ['activo', 'completado'])
                ->with('producto.prenda')
                ->orderBy('fecha_apartado', 'desc')
                ->get();

            $data = $apartados->map(function (Apartado $a) {
                $producto = $a->producto;
                $precio = $producto ? (float) $producto->precio : 0;
                $descuento = $producto ? (float) ($producto->descuento ?? 0) : 0;
                $precioConDescuento = $descuento > 0
                    ? $precio * (1 - $descuento / 100)
                    : $precio;

                return [
                    'id' => $producto->id_producto ?? null,
                    'id_apartado' => $a->id_apartado,
                    'nombre' => $producto->nombre ?? 'Producto no disponible',
                    'descripcion' => $producto->descripcion ?? '',
                    'precio' => '$' . number_format($precioConDescuento, 2),
                    'precioOriginal' => '$' . number_format($precio, 2),
                    'descuento' => $descuento,
                    'anticipo' => '$' . number_format((float) $a->monto_anticipo, 2),
                    'imagen' => $producto ? $this->resolverImagenUrl($producto->id_prenda) : null,
                    'categoria' => $this->obtenerCategoria($producto->prenda ?? null),
                    'material' => $producto->prenda->material ?? null,
                    'exclusivo' => $producto ? (bool) $producto->destacado : false,
                    'estadoPago' => $a->stripe_payment_status,
                    'fechaApartado' => optional($a->fecha_apartado)->format('d/m/Y'),
                    'fechaExpiracion' => optional($a->fecha_expiracion)->format('d/m/Y'),
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            Log::error('Error en OpheliaTiendaController@misApartados: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tus apartados',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Determina la categoría del producto según el material/tipo de la prenda
     */
    private function obtenerCategoria($prenda)
    {
        if (!$prenda) {
            return 'otros';
        }

        $material = $prenda->material ?? '';
        $tipo = $prenda->tipo ?? '';

        if ($material === 'oro') {
            return 'oro';
        }
        if ($material === 'plata') {
            return 'plata';
        }
        if ($tipo === 'Electrónica') {
            return 'electronicos';
        }
        return 'otros';
    }
}