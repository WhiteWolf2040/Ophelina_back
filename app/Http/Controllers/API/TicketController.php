<?php
// app/Http/Controllers/API/TicketController.php
//
// Misma forma de respuesta que PagoController::show() (admin), para poder
// reusar casi el mismo componente de recibo del lado del cliente:
// { cliente, empeno, pago, amortizacion }. Diferencias:
//   - Se filtra por id_cliente del usuario autenticado, no por id_empresa.
//   - Se agrega el bloque 'empresa' (nombre de la casa de empeño) para que
//     el recibo del cliente NO tenga "OPHELINA" fijo en el JSX, sino que
//     venga de la base de datos (Empeno::empresa).
//   - No expone endpoint de eliminación (eso es admin-only, en PagoController).

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Pago;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
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
     * Historial de tickets (pagos) del cliente autenticado, de todos sus
     * empeños. Lista ligera para la vista de "Mis Tickets".
     *
     * GET /api/cliente/tickets
     */
    public function index(Request $request)
    {
        try {
            $idCliente = $this->obtenerClienteId($request);

            if (!$idCliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado para este usuario',
                ], 404);
            }

            $pagos = Pago::whereHas('empeno', function ($q) use ($idCliente) {
                    $q->where('id_cliente', $idCliente);
                })
                ->with(['empeno.prenda', 'empeno.empresa'])
                ->orderBy('fecha_pago', 'desc')
                ->get()
                ->map(function (Pago $pago) {
                    return [
                        'id' => $pago->id_pago,
                        'folio_empeno' => $pago->empeno->folio ?? 'N/A',
                        'articulo' => $pago->empeno->prenda->descripcion ?? 'Sin artículo',
                        'fecha' => $pago->fecha_pago ? date('d/m/Y', strtotime($pago->fecha_pago)) : 'N/A',
                        'tipo' => ucfirst($pago->tipo_pago ?? 'Pago'),
                        'monto' => '$' . number_format($pago->monto_total ?? 0, 2),
                        'montoNumerico' => (float) ($pago->monto_total ?? 0),
                        'casaEmpeño' => optional($pago->empeno->empresa)->nombre_comercial
                            ?? optional($pago->empeno->empresa)->nombre
                            ?? 'N/A',
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $pagos,
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en TicketController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar tus tickets',
            ], 500);
        }
    }

    /**
     * Ticket/recibo completo de un pago específico del cliente autenticado.
     * MISMA FORMA que PagoController::show() (admin): { cliente, empeno,
     * pago, amortizacion } + un bloque 'empresa' nuevo para el nombre
     * dinámico de la casa de empeño. Así el componente de recibo del
     * cliente puede ser casi una copia del admin, solo cambiando el
     * fetch y quitando "Eliminar Pago".
     *
     * GET /api/cliente/tickets/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $idCliente = $this->obtenerClienteId($request);

            if (!$idCliente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cliente no encontrado para este usuario',
                ], 404);
            }

            $pago = Pago::whereHas('empeno', function ($q) use ($idCliente) {
                    $q->where('id_cliente', $idCliente);
                })
                ->with(['empeno.cliente', 'empeno.prenda', 'empeno.empresa', 'amortizacion'])
                ->where('id_pago', $id)
                ->first();

            if (!$pago) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ticket no encontrado',
                ], 404);
            }

            $data = [
                'id' => $pago->id_pago,
                'fecha_pago' => $pago->fecha_pago,

                'cliente' => [
                    'id' => $pago->empeno->cliente->id_cliente,
                    'nombre' => trim($pago->empeno->cliente->nombre . ' ' . $pago->empeno->cliente->apellido),
                    'telefono' => $pago->empeno->cliente->telefono,
                    'correo' => $pago->empeno->cliente->correo,
                ],

                'empeno' => [
                    'id' => $pago->empeno->id_empeno,
                    'folio' => $pago->empeno->folio,
                    'monto_prestado' => $pago->empeno->monto_prestado,
                    'fecha_empeno' => $pago->empeno->fecha_empeno,
                    'fecha_vencimiento' => $pago->empeno->fecha_vencimiento,
                    'estado' => $pago->empeno->estado,
                    'prenda' => [
                        'descripcion' => $pago->empeno->prenda->descripcion ?? 'N/A',
                        'tipo' => $pago->empeno->prenda->tipo ?? 'N/A',
                    ],
                ],

                'pago' => [
                    'id_amortizacion' => $pago->id_amortizacion,
                    'fecha' => $pago->fecha_pago,
                    'capital' => $pago->capital_pagado,
                    'interes' => $pago->interes_pagado,
                    'iva' => $pago->iva_pagado,
                    'monto_total' => $pago->monto_total,
                    'tipo' => $pago->tipo_pago,
                    'metodo' => $pago->metodo_pago,
                    'referencia' => $pago->referencia,
                ],

                'amortizacion' => $pago->amortizacion ? [
                    'numero_pago' => $pago->amortizacion->numero_pago,
                    'capital_original' => $pago->amortizacion->capital,
                    'interes_original' => $pago->amortizacion->interes,
                    'iva_original' => $pago->amortizacion->iva_interes,
                    'monto_total_original' => $pago->amortizacion->monto_total,
                    'saldo_pendiente' => $pago->amortizacion->saldo_pendiente,
                    'fecha_programada' => $pago->amortizacion->fecha_pago_programado,
                    'estado' => $pago->amortizacion->estado,
                ] : null,

                // ✅ NUEVO: nombre dinámico de la casa de empeño. Cae a
                // "Ophelina" solo si la empresa no tiene nombre configurado.
                'empresa' => [
                    'nombre' => optional($pago->empeno->empresa)->nombre_comercial
                        ?? optional($pago->empeno->empresa)->nombre
                        ?? 'Ophelina',
                    'rfc' => optional($pago->empeno->empresa)->rfc ?? null,
                    'direccion' => optional($pago->empeno->empresa)->direccion ?? null,
                    'telefono' => optional($pago->empeno->empresa)->telefono ?? null,
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en TicketController@show: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar el ticket',
            ], 500);
        }
    }
}