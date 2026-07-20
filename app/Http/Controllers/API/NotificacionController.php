<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Empeno;
use App\Models\Cliente;
use App\Models\ProductoTienda;
use Illuminate\Http\Request;
use Carbon\Carbon;

class NotificacionController extends Controller
{
    public function index(Request $request)
    {
        $usuario = $request->user();

        $cliente = Cliente::where('id_usuario', $usuario->id_usuario)->first();

        if (!$cliente) {
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        }

        $hoy = Carbon::today();
        $notificaciones = [];

        // 1. Empeños vencidos
        $vencidos = Empeno::where('id_cliente', $cliente->id_cliente)
            ->where('estado', 'vencido')
            ->with('prenda')
            ->get();

        foreach ($vencidos as $empeno) {
            $notificaciones[] = [
                'id'        => 'empeno_' . $empeno->id_empeno,
                'titulo'    => $empeno->prenda->nombre ?? ('Empeño ' . $empeno->folio),
                'subtitulo' => 'Vencido',
                'tipo'      => 'vencido',
                'fecha'     => $empeno->fecha_vencimiento,
            ];
        }

        // 2. Empeños próximos a vencer (activos, vencen en los próximos 5 días)
        $proximos = Empeno::where('id_cliente', $cliente->id_cliente)
            ->where('estado', 'activo')
            ->whereBetween('fecha_vencimiento', [$hoy, $hoy->copy()->addDays(5)])
            ->with('prenda')
            ->get();

        foreach ($proximos as $empeno) {
            $diasRestantes = $hoy->diffInDays(Carbon::parse($empeno->fecha_vencimiento), false);

            $notificaciones[] = [
                'id'        => 'empeno_' . $empeno->id_empeno,
                'titulo'    => $empeno->prenda->nombre ?? ('Empeño ' . $empeno->folio),
                'subtitulo' => $diasRestantes <= 0
                    ? 'Vence hoy'
                    : "Vence en {$diasRestantes} día(s)",
                'tipo'      => 'proximo',
                'fecha'     => $empeno->fecha_vencimiento,
            ];
        }

        // 3. Artículos nuevos en tienda (publicados en los últimos 7 días)
        $nuevos = ProductoTienda::where('id_empresa', $cliente->id_empresa)
            ->where('visible', 1)
            ->where('fecha_publicacion', '>=', $hoy->copy()->subDays(7))
            ->get();

        foreach ($nuevos as $producto) {
            $notificaciones[] = [
                'id'        => 'producto_' . $producto->id_producto,
                'titulo'    => $producto->nombre,
                'subtitulo' => 'Nuevo artículo en tienda',
                'tipo'      => 'nuevo',
                'fecha'     => $producto->fecha_publicacion,
            ];
        }

        // Ordenar: vencidos primero, luego próximos, luego nuevos (por fecha más reciente/urgente)
        $orden = ['vencido' => 0, 'proximo' => 1, 'nuevo' => 2];
        usort($notificaciones, function ($a, $b) use ($orden) {
            return $orden[$a['tipo']] <=> $orden[$b['tipo']];
        });

        return response()->json([
            'success' => true,
            'data' => array_slice($notificaciones, 0, 10), // límite para no saturar el dropdown
        ]);
    }
}