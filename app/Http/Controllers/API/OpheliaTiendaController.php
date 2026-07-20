<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductoTienda;
use Illuminate\Http\Request;

class OpheliaTiendaController extends Controller
{
    public function getProductos(Request $request)
    {
        $query = ProductoTienda::with('prenda')
            ->where('visible', 1);

        // Búsqueda: nombre, descripción del producto, o tipo/descripción de la prenda
        if ($request->filled('buscar')) {
            $buscar = $request->buscar;
            $query->where(function ($q) use ($buscar) {
                $q->where('nombre', 'like', "%{$buscar}%")
                  ->orWhere('descripcion', 'like', "%{$buscar}%")
                  ->orWhereHas('prenda', function ($p) use ($buscar) {
                      $p->where('tipo', 'like', "%{$buscar}%")
                        ->orWhere('descripcion', 'like', "%{$buscar}%");
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
                        $p->where('tipo', 'like', '%electr%');
                    });
                    break;
                case 'oro':
                case 'plata':
                    // ⚠️ Heurística temporal: no hay columna "material" en prendas,
                    // así que buscamos la palabra en nombre/descripción del producto.
                    $palabra = $request->categoria;
                    $query->where(function ($q) use ($palabra) {
                        $q->where('nombre', 'like', "%{$palabra}%")
                          ->orWhere('descripcion', 'like', "%{$palabra}%");
                    });
                    break;
            }
        }

        $productos = $query->orderBy('fecha_publicacion', 'desc')->get();

        $data = $productos->map(function ($p) {
            return [
                'id' => $p->id_producto,
                'nombre' => $p->nombre,
                'descripcion' => $p->descripcion,
                'precio' => $p->precio,
                'imagen_url' => $p->imagen_url,
                'exclusivo' => (bool) $p->destacado,
                'estado_producto' => $p->estado_producto,
                'stock' => $p->stock,
                'tipo_prenda' => $p->prenda->tipo ?? null,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }
}