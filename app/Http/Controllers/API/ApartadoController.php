<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Apartado;
use App\Models\Cliente;
use App\Models\ProductoTienda;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Carbon\Carbon;

class ApartadoController extends Controller
{
    public function crearSesion(Request $request)
    {
        $request->validate([
            'id_producto' => 'required|integer|exists:producto_tienda,id_producto',
        ]);

        $usuario = $request->user();
        $cliente = Cliente::where('id_usuario', $usuario->id_usuario)->first();

        if (!$cliente) {
            return response()->json(['success' => false, 'message' => 'Cliente no encontrado'], 404);
        }

        $producto = ProductoTienda::findOrFail($request->id_producto);

        if ($producto->stock < 1 || !$producto->visible) {
            return response()->json(['success' => false, 'message' => 'Producto no disponible'], 422);
        }

        $porcentaje = config('services.stripe.anticipo_porcentaje');
        $montoAnticipo = round($producto->precio * ($porcentaje / 100), 2);

        Stripe::setApiKey(config('services.stripe.secret'));

        // Creamos el apartado en estado "activo" con pago "pendiente" ANTES de mandar a Stripe
        $apartado = Apartado::create([
            'id_cliente' => $cliente->id_cliente,
            'id_producto' => $producto->id_producto,
            'fecha_apartado' => now(),
            'fecha_expiracion' => Carbon::now()->addDays(3), // ajusta los días que quieras dar de plazo
            'estado' => 'activo',
            'monto_anticipo' => $montoAnticipo,
            'stripe_payment_status' => 'pendiente',
        ]);

        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'mxn',
                    'product_data' => [
                        'name' => 'Anticipo: ' . $producto->nombre,
                    ],
                    'unit_amount' => (int) round($montoAnticipo * 100), // Stripe usa centavos
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => config('app.frontend_url') . '/tienda/apartado-exitoso?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => config('app.frontend_url') . '/tienda/apartado-cancelado',
            'metadata' => [
                'id_apartado' => $apartado->id_apartado,
            ],
        ]);

        $apartado->update(['stripe_session_id' => $session->id]);

        return response()->json([
            'success' => true,
            'checkout_url' => $session->url,
        ]);
    }
}