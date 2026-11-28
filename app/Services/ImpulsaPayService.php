<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ImpulsaPayService
{
    private function http(): PendingRequest
    {
        return Http::withHeaders([
                'X-API-KEY' => config('services.impulsapay.key'),
                'Accept'    => 'application/json',
            ])
            ->baseUrl(rtrim(config('services.impulsapay.url'), '/'))
            ->timeout(25);
    }

    /** Crea el pedido externo. Devuelve ['pedido_id' => ..., ...] */
    public function crearPedido(float $monto, string $referencia): array
    {
        return $this->http()->post('/integraciones/crear-qr', [
            'monto'              => $monto,
            'referencia_externa' => $referencia,
        ])->throw()->json();
    }

    /** Crea el PaymentIntent. Devuelve ['checkout_url' => ..., ...] */
    public function crearPago(int $pedidoId, ?string $returnUrl = null): array
    {
        return $this->http()->post('/integraciones/crear-pago', array_filter([
            'pedido_id'  => $pedidoId,
            'return_url' => $returnUrl, // solo funciona cuando tu compañero lo soporte
        ]))->throw()->json();
    }

    /** Devuelve ['status' => 'pagado', 'monto' => '150.00', 'referencia_externa' => ...] */
    public function estado(int $pedidoId): array
    {
        return $this->http()->get("/integraciones/estado-pedido/{$pedidoId}")->throw()->json();
    }
}