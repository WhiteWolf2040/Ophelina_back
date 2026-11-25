<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ImagenPrenda;
use App\Models\ProductoTienda;
use Illuminate\Http\Request;

class PosProductosController extends Controller
{
    /**
     * GET /api/v1/pos/productos
     *
     * Sin parámetros: solo productos disponibles (catálogo actual).
     * Con ?actualizado_desde=2026-11-24T10:00:00Z: todo lo que cambió desde esa
     * fecha, incluyendo lo apartado, vendido o eliminado (disponible=false),
     * para que el POS sepa qué quitar de su lista.
     */
    public function index(Request $request)
    {
        $request->validate([
            'actualizado_desde' => 'nullable|date',
            'por_pagina'        => 'nullable|integer|min:1|max:200',
        ]);

        $idEmpresa = $request->attributes->get('id_empresa');

        $query = ProductoTienda::with('prenda')->where('id_empresa', $idEmpresa);

        if ($request->filled('actualizado_desde')) {
            $query->withTrashed()->where('updated_at', '>=', $request->actualizado_desde);
        } else {
            $query->where('visible', 1)->where('stock', '>', 0);
        }

        $paginado = $query->orderBy('id_producto')->paginate($request->integer('por_pagina', 100));

        $imagenes = $this->cargarImagenes(
            $paginado->getCollection()->pluck('id_prenda')->filter()->unique()->values()
        );

        return response()->json([
            'success' => true,
            'data'    => $paginado->getCollection()->map(fn ($p) => $this->transformar($p, $imagenes))->values(),
            'meta'    => [
                'pagina'     => $paginado->currentPage(),
                'ultima'     => $paginado->lastPage(),
                'total'      => $paginado->total(),
                'generado_en' => now()->toIso8601String(), // úsalo como siguiente "actualizado_desde"
            ],
        ]);
    }

    /** GET /api/v1/pos/productos/{id} */
    public function show(Request $request, $id)
    {
        $producto = ProductoTienda::withTrashed()
            ->with('prenda')
            ->where('id_empresa', $request->attributes->get('id_empresa'))
            ->where('id_producto', $id)
            ->first();

        if (!$producto) {
            return response()->json(['success' => false, 'message' => 'Producto no encontrado'], 404);
        }

        $imagenes = $this->cargarImagenes(collect([$producto->id_prenda])->filter());

        return response()->json(['success' => true, 'data' => $this->transformar($producto, $imagenes)]);
    }

    private function transformar(ProductoTienda $p, $imagenes): array
    {
        $precio    = (float) $p->precio;
        $descuento = (float) ($p->descuento ?? 0);
        $final     = $descuento > 0 ? $precio * (1 - $descuento / 100) : $precio;

        $disponible = $p->deleted_at === null && (bool) $p->visible && (int) $p->stock > 0;

        return [
            'id'                       => $p->id_producto,
            'referencia'               => 'producto-' . $p->id_producto,
            'nombre'                   => $p->nombre,
            'descripcion'              => $p->descripcion,
            'categoria'                => $this->categoria($p),
            'material'                 => $p->prenda->material ?? null,
            'precio_centavos'          => (int) round($final * 100),
            'precio_original_centavos' => (int) round($precio * 100),
            'descuento_pct'            => $descuento,
            'moneda'                   => 'MXN',
            'stock'                    => (int) $p->stock,
            'disponible'               => $disponible,
            'imagen'                   => $this->imagen($p, $imagenes),
            'actualizado_en'           => $p->updated_at
                ? \Carbon\Carbon::parse($p->updated_at)->toIso8601String()
                : null,
        ];
    }

    private function categoria(ProductoTienda $p): string
    {
        $prenda = $p->prenda;
        if (!$prenda) return 'otros';
        if ($prenda->material === 'oro') return 'oro';
        if ($prenda->material === 'plata') return 'plata';
        if (($prenda->tipo ?? '') === 'Electrónica') return 'electronicos';
        return 'otros';
    }

    private function cargarImagenes($idsPrendas)
    {
        if ($idsPrendas->isEmpty()) return collect();

        return ImagenPrenda::whereIn('id_prenda', $idsPrendas)
            ->orderByDesc('es_principal')
            ->orderBy('orden')
            ->get()
            ->groupBy('id_prenda');
    }

    private function imagen(ProductoTienda $p, $imagenes): ?string
    {
        if ($p->id_prenda && isset($imagenes[$p->id_prenda])) {
            foreach ($imagenes[$p->id_prenda] as $img) {
                if ($img->url_publica) return $img->url_publica;
            }
        }
        return $p->imagen_url; // respaldo: la imagen guardada en el propio producto
    }
}