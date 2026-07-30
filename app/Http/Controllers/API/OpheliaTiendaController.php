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
        try {
            // ✅ Obtener el usuario autenticado
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            // ✅ Obtener el cliente y su empresa
            $cliente = Cliente::where('id_usuario', $user->id_usuario)->first();

            if (!$cliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado'
                ], 404);
            }

            // ✅ ID de la empresa del cliente
            $idEmpresa = $cliente->id_empresa;

            // ✅ Filtrar productos SOLO de la empresa del cliente
            $query = ProductoTienda::with('prenda')
                ->where('visible', 1)
                ->where('id_empresa', $idEmpresa); // 🔥 FILTRO POR EMPRESA

            // Búsqueda por texto
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

            return response()->json([
                'success' => true,
                'data' => $data,
                'empresa_id' => $idEmpresa // Opcional: para debug
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en OpheliaTiendaController@getProductos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar productos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ Devuelve la URL pública de la imagen principal de una prenda.
     * Prioriza Cloudinary; si no existe, cae al endpoint viejo de binario.
     */
    private function resolverImagenUrl($idPrenda)
    {
        if (empty($idPrenda)) {
            return null;
        }

        $imagen = \App\Models\ImagenPrenda::where('id_prenda', $idPrenda)
            ->where('es_principal', true)
            ->first();

        if (!$imagen) {
            $imagen = \App\Models\ImagenPrenda::where('id_prenda', $idPrenda)->first();
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
     * ✅ NUEVO: helper para agregarle un query param a una URL base,
     * sin importar si esa URL base ya trae otros parámetros o no.
     * Usado para inyectar &tipo=apartado a las URLs genéricas
     * STRIPE_TIENDA_SUCCESS_URL / STRIPE_TIENDA_CANCEL_URL, que ahora
     * también reutiliza AbonoController con &tipo=abono / &tipo=prorroga.
     * Así una sola variable de entorno sirve para los 3 flujos sin que
     * se pisen entre sí.
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
                'codigo_entrega' => $this->generarCodigoEntrega(),
                'notas' => 'Anticipo del 50% para apartar el producto',
            ]);

            Stripe::setApiKey(env('STRIPE_SECRET'));

            // ✅ CORREGIDO: STRIPE_TIENDA_SUCCESS_URL / STRIPE_TIENDA_CANCEL_URL
            // ahora son URLs base GENÉRICAS (ej. https://ophelina-front.vercel.app/homecliente?pago=exitoso)
            // compartidas también con AbonoController. Aquí le agregamos
            // &tipo=apartado dinámicamente para no pisar el &tipo=abono /
            // &tipo=prorroga que agrega ese otro controlador.
            $successBase = env('STRIPE_TIENDA_SUCCESS_URL', env('STRIPE_SUCCESS_URL'));
            $cancelBase = env('STRIPE_TIENDA_CANCEL_URL', env('STRIPE_CANCEL_URL'));

            // ✅ NUEVO: validación defensiva. Si estas variables vienen null
            // (typo en el nombre en Render, o no se hizo redeploy después de
            // guardarlas), pasar null a agregarParametro() -que exige
            // string- provocaría un TypeError FATAL que no cae en el catch
            // de abajo (TypeError no extiende Exception en PHP 8), y eso se
            // ve en el frontend como un 500 genérico sin mensaje. Mejor
            // devolver un error controlado y explícito.
            if (empty($successBase) || empty($cancelBase)) {
                Log::error('❌ STRIPE_TIENDA_SUCCESS_URL o STRIPE_TIENDA_CANCEL_URL no están configuradas (revisa las env vars en Render y haz un redeploy).');
                return response()->json([
                    'success' => false,
                    'message' => 'Configuración de pago incompleta en el servidor (faltan URLs de Stripe). Contacta al administrador.',
                ], 500);
            }

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

            // ✅ NUEVO: refleja el apartado también en el inventario del
            // dueño. Como cada ProductoTienda corresponde 1 a 1 con una
            // Prenda física (confirmado: no hay múltiples unidades por
            // artículo), marcamos esa prenda como 'Apartado' para que el
            // admin la vea así en /inventario, en vez de que el artículo
            // simplemente "desaparezca" de la tienda sin ninguna señal en
            // el inventario de por qué.
            if ($producto->id_prenda) {
                \App\Models\Prenda::where('id_prenda', $producto->id_prenda)->update([
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
                // ← NUEVO: solo mostramos el código si ya está pagado
                'codigoEntrega' => $a->stripe_payment_status === 'pagado' ? $a->codigo_entrega : null,
                'entregado' => (bool) $a->entregado,
                'fechaEntrega' => optional($a->fecha_entrega)->format('d/m/Y H:i'),
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