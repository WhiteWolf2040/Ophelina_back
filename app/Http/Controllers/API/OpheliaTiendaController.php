<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Apartado;
use App\Models\Cliente;
use App\Models\ImagenPrenda;
use App\Models\Prenda;
use App\Models\ProductoTienda;
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
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no autenticado'], 401);
            }

            $cliente = Cliente::where('id_usuario', $user->id_usuario)->first();
            if (!$cliente) {
                return response()->json(['success' => false, 'message' => 'Cliente no encontrado'], 404);
            }

            $idEmpresa = $cliente->id_empresa;

            // Solo productos visibles, no eliminados, de la empresa del cliente
            $query = ProductoTienda::with('prenda')
                ->where('visible', 1)
                ->whereNull('deleted_at')
                ->where('id_empresa', $idEmpresa);

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

            $imagenesPorPrenda = $this->cargarImagenes(
                $productos->pluck('id_prenda')->filter()->unique()->values()
            );

            $data = $productos->map(function (ProductoTienda $p) use ($imagenesPorPrenda) {
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
                    'imagen' => $this->resolverImagenDesdeColeccion($p->id_prenda, $imagenesPorPrenda),
                    'categoria' => $this->obtenerCategoria($p->prenda),
                    'material' => $p->prenda->material ?? null,
                    'exclusivo' => (bool) $p->destacado,
                    'estado_producto' => $p->estado_producto,
                    'stock' => $p->stock,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $data,
                'empresa_id' => $idEmpresa
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en OpheliaTiendaController@getProductos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar productos',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * UNA sola consulta para todas las imágenes, agrupadas por prenda
     * (la principal primero).
     */
    private function cargarImagenes($idsPrendas)
    {
        if ($idsPrendas->isEmpty()) {
            return collect();
        }

        return ImagenPrenda::whereIn('id_prenda', $idsPrendas)
            ->orderByDesc('es_principal')
            ->orderBy('orden')
            ->get()
            ->groupBy('id_prenda');
    }

    /**
     * URL de imagen lista para un <img>; null si la prenda no tiene imagen válida.
     * Usa ImagenPrenda::url_publica (Cloudinary, rutas relativas o base64 antiguo).
     */
    private function resolverImagenDesdeColeccion($idPrenda, $imagenesPorPrenda)
    {
        if (empty($idPrenda) || !isset($imagenesPorPrenda[$idPrenda])) {
            return null;
        }

        // Primera imagen de la lista que sí tenga una URL utilizable
        foreach ($imagenesPorPrenda[$idPrenda] as $imagen) {
            $url = $imagen->url_publica;
            if ($url) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Agrega un query param a una URL base, tenga ya parámetros o no.
     * Se usa para inyectar tipo=apartado a las URLs de Stripe compartidas
     * con AbonoController (tipo=abono / tipo=prorroga).
     */
    private function agregarParametro(string $urlBase, string $clave, string $valor): string
    {
        $separador = (strpos($urlBase, '?') !== false) ? '&' : '?';
        return $urlBase . $separador . $clave . '=' . urlencode($valor);
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

            // Solo productos visibles, no eliminados y de la empresa del cliente
            $producto = ProductoTienda::where('id_producto', $id)
                ->where('visible', 1)
                ->whereNull('deleted_at')
                ->where('id_empresa', $cliente->id_empresa)
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

            $descuento = (float) ($producto->descuento ?? 0);
            $precioFinal = $descuento > 0
                ? $producto->precio * (1 - $descuento / 100)
                : (float) $producto->precio;

            $montoAnticipo = round($precioFinal * 0.5, 2);

            // Validar la configuración ANTES de crear nada en la BD,
            // para no dejar un apartado huérfano si faltan las URLs.
            $successBase = env('STRIPE_TIENDA_SUCCESS_URL', env('STRIPE_SUCCESS_URL'));
            $cancelBase = env('STRIPE_TIENDA_CANCEL_URL', env('STRIPE_CANCEL_URL'));

            if (empty($successBase) || empty($cancelBase)) {
                Log::error('❌ STRIPE_TIENDA_SUCCESS_URL o STRIPE_TIENDA_CANCEL_URL no están configuradas (revisa las env vars en Render y haz un redeploy).');
                return response()->json([
                    'success' => false,
                    'message' => 'Configuración de pago incompleta en el servidor (faltan URLs de Stripe). Contacta al administrador.',
                ], 500);
            }

            $apartado = Apartado::create([
                'id_cliente' => $clienteId,
                'id_producto' => $producto->id_producto,
                'fecha_apartado' => now(),
                'fecha_expiracion' => now()->addDays(3),
                'estado' => 'activo',
                'monto_anticipo' => $montoAnticipo,
                'stripe_payment_status' => 'pendiente',
                'codigo_entrega' => $this->generarCodigoEntrega(),
                'notas' => 'Anticipo del 50% para apartar el producto',
            ]);

            Stripe::setApiKey(env('STRIPE_SECRET'));

            $successUrl = $this->agregarParametro($successBase, 'tipo', 'apartado');
            $cancelUrl = $this->agregarParametro($cancelBase, 'tipo', 'apartado');

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
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'id_apartado' => $apartado->id_apartado,
                ],
                'customer_email' => $user->correo,
            ]);

            $apartado->stripe_session_id = $session->id;
            $apartado->save();

            $producto->visible = 0;
            $producto->save();

            // Refleja el apartado en el inventario del dueño
            if ($producto->id_prenda) {
                Prenda::where('id_prenda', $producto->id_prenda)->update([
                    'estado' => 'Apartado',
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'checkout_url' => $session->url,
                    'id_apartado' => $apartado->id_apartado,
                    'anticipo' => '$' . number_format($montoAnticipo, 2),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en OpheliaTiendaController@apartar: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al iniciar el apartado',
                'error' => config('app.debug') ? $e->getMessage() : null,
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

            $apartados = Apartado::where('id_cliente', $cliente->id_cliente)
                ->whereIn('estado', ['activo', 'completado'])
                ->with('producto.prenda')
                ->orderBy('fecha_apartado', 'desc')
                ->get();

            $imagenesPorPrenda = $this->cargarImagenes(
                $apartados->map(fn ($a) => $a->producto?->id_prenda)->filter()->unique()->values()
            );

            $data = $apartados->map(function (Apartado $a) use ($imagenesPorPrenda) {
                $producto = $a->producto;
                $precio = $producto ? (float) $producto->precio : 0;
                $descuento = $producto ? (float) ($producto->descuento ?? 0) : 0;
                $precioConDescuento = $descuento > 0 ? $precio * (1 - $descuento / 100) : $precio;

                return [
                    'id' => $producto->id_producto ?? null,
                    'id_apartado' => $a->id_apartado,
                    'nombre' => $producto->nombre ?? 'Producto no disponible',
                    'descripcion' => $producto->descripcion ?? '',
                    'precio' => '$' . number_format($precioConDescuento, 2),
                    'precioOriginal' => '$' . number_format($precio, 2),
                    'descuento' => $descuento,
                    'anticipo' => '$' . number_format((float) $a->monto_anticipo, 2),
                    'imagen' => $producto ? $this->resolverImagenDesdeColeccion($producto->id_prenda, $imagenesPorPrenda) : null,
                    'categoria' => $this->obtenerCategoria($producto->prenda ?? null),
                    'material' => $producto->prenda->material ?? null,
                    'exclusivo' => $producto ? (bool) $producto->destacado : false,
                    'estadoPago' => $a->stripe_payment_status,
                    'fechaApartado' => optional($a->fecha_apartado)->format('d/m/Y'),
                    'fechaExpiracion' => optional($a->fecha_expiracion)->format('d/m/Y'),
                    'codigoEntrega' => $a->stripe_payment_status === 'pagado' ? $a->codigo_entrega : null,
                    'entregado' => (bool) $a->entregado,
                    'fechaEntrega' => optional($a->fecha_entrega)->format('d/m/Y H:i'),
                ];
            });

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            Log::error('Error en OpheliaTiendaController@misApartados: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tus apartados',
                'error' => config('app.debug') ? $e->getMessage() : null,
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

    private function generarCodigoEntrega(): string
    {
        $caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0,O,1,I para evitar confusión
        $codigo = '';
        for ($i = 0; $i < 6; $i++) {
            $codigo .= $caracteres[random_int(0, strlen($caracteres) - 1)];
        }
        return $codigo;
    }
}