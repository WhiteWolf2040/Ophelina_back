<?php
// app/Http/Controllers/Api/TiendaController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductoTienda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Prenda; // ← Agregar esta línea


class TiendaController extends Controller
{
    /**
     * Listar productos con filtros
     */
   public function index(Request $request)
{
    try {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        // ✅ Cargar la relación con prendas
        $query = ProductoTienda::with('prenda')
            ->where('id_empresa', $user->id_empresa)
            ->whereNull('deleted_at');

        // Filtro de búsqueda
        if ($request->has('busqueda') && $request->busqueda) {
            $query->where(function($q) use ($request) {
                $q->where('nombre', 'LIKE', "%{$request->busqueda}%")
                  ->orWhere('descripcion', 'LIKE', "%{$request->busqueda}%")
                  // ✅ Buscar en 'tipo' de la tabla prendas
                  ->orWhereHas('prenda', function($pq) use ($request) {
                      $pq->where('tipo', 'LIKE', "%{$request->busqueda}%")
                         ->orWhere('descripcion', 'LIKE', "%{$request->busqueda}%");
                  });
            });
        }

        // ✅ Filtro por categoría usando relación con prendas
        if ($request->has('categoria') && $request->categoria !== 'Todas') {
            $query->whereHas('prenda', function($q) use ($request) {
                $q->where('tipo', $request->categoria);
            });
        }

        if ($request->has('estado') && $request->estado !== 'Todos') {
            $query->where('estado_producto', $request->estado);
        }

        if ($request->has('solo_visibles') && $request->solo_visibles === 'true') {
            $query->where('visible', true);
        }

        $productos = $query->orderBy('fecha_publicacion', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $productos,
            'total' => $productos->count()
        ]);

    } catch (\Exception $e) {
        Log::error('Error en TiendaController@index: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al cargar productos: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Obtener estadísticas
     */
    public function estadisticas(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $empresaId = $user->id_empresa;
            
            // ✅ Solo contar NO eliminados
            $total = ProductoTienda::where('id_empresa', $empresaId)
                ->whereNull('deleted_at')
                ->count();
            
            $visibles = ProductoTienda::where('id_empresa', $empresaId)
                ->whereNull('deleted_at')
                ->where('visible', true)
                ->count();
            
            $destacados = ProductoTienda::where('id_empresa', $empresaId)
                ->whereNull('deleted_at')
                ->where('destacado', true)
                ->count();
            
            $ocultos = $total - $visibles;
            
            $valorTotal = ProductoTienda::where('id_empresa', $empresaId)
                ->whereNull('deleted_at')
                ->select(DB::raw('SUM(precio * stock) as total'))
                ->value('total') ?? 0;

            return response()->json([
                'success' => true,
                'total' => $total,
                'visibles' => $visibles,
                'ocultos' => $ocultos,
                'destacados' => $destacados,
                'bajo_stock' => 0,
                'valor_total' => number_format($valorTotal, 2),
                'publicaciones_automaticas' => 0
            ]);

        } catch (\Exception $e) {
            Log::error('Error en TiendaController@estadisticas: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar estadísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
 * Crear producto (sincroniza Inventario + Tienda)
 */

public function store(Request $request)
{
    try {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        $validated = $request->validate([
            'nombre' => 'required|string|max:100',
            'categoria' => 'required|string|max:100',
            'precio' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'descripcion' => 'nullable|string',
            'estado' => 'required|string|in:Nuevo,Como nuevo,Buen estado,Aceptable',
            'visible' => 'boolean',
            'destacado' => 'boolean',
            'descuento' => 'numeric|min:0|max:100',
            'imagen' => 'nullable|string'
        ]);

        // ========================================
        // 🔥 CREAR EN INVENTARIO (prendas) usando DB
        // ========================================
        $idPrenda = DB::table('prendas')->insertGetId([
            'id_empresa' => $user->id_empresa,
            'descripcion' => $validated['nombre'],
            'tipo' => $validated['categoria'] ?? 'Otros',
            'material' => null,
            'peso_gramos' => null,
            'valor_estimado' => $validated['precio'],
            'estado' => 'Disponible',
            'fecha_registro' => now(),
            'codigo_barras' => 'PRN-' . strtoupper(uniqid()),
            'imagen_url' => $request->imagen ?? null
        ]);

        // Obtener la prenda creada
        $prenda = Prenda::find($idPrenda);

        // ========================================
        // 🔥 CREAR EN TIENDA (producto_tienda)
        // ========================================
        $producto = ProductoTienda::create([
            'id_empresa' => $user->id_empresa,
            'id_prenda' => $prenda->id_prenda,
            'nombre' => $prenda->descripcion,
            'categoria' => $validated['categoria'] ?? 'Otros',
            'precio' => $validated['precio'],
            'stock' => $validated['stock'],
            'descripcion' => $validated['descripcion'] ?? '',
            'estado_producto' => $validated['estado'],
            'visible' => $validated['visible'] ?? true,
            'destacado' => $validated['destacado'] ?? false,
            'descuento' => $validated['descuento'] ?? 0,
            'fecha_publicacion' => now()->format('Y-m-d'),
            'imagen_url' => $request->imagen ?? null
        ]);

        return response()->json([
            'success' => true,
            'message' => '✅ Producto creado en Inventario y Tienda',
            'data' => [
                'inventario' => $prenda,
                'tienda' => $producto
            ]
        ]);

    } catch (\Exception $e) {
        Log::error('Error en TiendaController@store: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al crear producto: ' . $e->getMessage()
        ], 500);
    }
}
    /**
     * Actualizar producto
     */
   public function update(Request $request, $id)
{
    try {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        $producto = ProductoTienda::where('id_producto', $id)
            ->where('id_empresa', $user->id_empresa)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $validated = $request->validate([
            'nombre' => 'required|string|max:100',
            // ❌ ELIMINAR 'categoria' de la validación
            'precio' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'descripcion' => 'nullable|string',
            'estado' => 'required|string|in:Nuevo,Como nuevo,Buen estado,Aceptable',
            'visible' => 'boolean',
            'destacado' => 'boolean',
            'descuento' => 'numeric|min:0|max:100'
        ]);

        // ✅ SIN categoria
        $producto->nombre = $validated['nombre'];
        $producto->precio = $validated['precio'];
        $producto->stock = $validated['stock'];
        $producto->descripcion = $validated['descripcion'] ?? '';
        $producto->estado_producto = $validated['estado'];
        $producto->visible = $validated['visible'] ?? true;
        $producto->destacado = $validated['destacado'] ?? false;
        $producto->descuento = $validated['descuento'] ?? 0;
        
        if ($request->has('imagen')) {
            $producto->imagen_url = $request->imagen;
        }
        
        $producto->save();

        return response()->json([
            'success' => true,
            'message' => 'Producto actualizado correctamente',
            'data' => $producto
        ]);

    } catch (\Exception $e) {
        Log::error('Error en TiendaController@update: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al actualizar producto: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Cambiar visibilidad
     */
    public function toggleVisibilidad($id)
    {
        try {
            $user = request()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $producto = ProductoTienda::where('id_producto', $id)
                ->where('id_empresa', $user->id_empresa)
                ->whereNull('deleted_at') // ← Solo NO eliminados
                ->firstOrFail();

            $producto->visible = !$producto->visible;
            $producto->save();

            return response()->json([
                'success' => true,
                'message' => $producto->visible ? 'Producto visible' : 'Producto oculto',
                'visible' => $producto->visible
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar visibilidad: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar destacado
     */
    public function toggleDestacado($id)
    {
        try {
            $user = request()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $producto = ProductoTienda::where('id_producto', $id)
                ->where('id_empresa', $user->id_empresa)
                ->whereNull('deleted_at') // ← Solo NO eliminados
                ->firstOrFail();

            $producto->destacado = !$producto->destacado;
            $producto->save();

            return response()->json([
                'success' => true,
                'message' => $producto->destacado ? 'Producto destacado' : 'Producto no destacado',
                'destacado' => $producto->destacado
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar destacado: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar producto (Soft Delete)
     */
    public function destroy($id)
    {
        try {
            $user = request()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $producto = ProductoTienda::where('id_producto', $id)
                ->where('id_empresa', $user->id_empresa)
                ->whereNull('deleted_at')
                ->firstOrFail();

            $producto->delete();

            return response()->json([
                'success' => true,
                'message' => 'Producto eliminado correctamente'
            ]);

        } catch (\Exception $e) {
            Log::error('Error al eliminar producto: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar producto: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Catálogo público
     */
    public function catalogoPublico(Request $request)
    {
        try {
            $query = ProductoTienda::where('visible', true)
                ->where('stock', '>', 0)
                ->whereNull('deleted_at'); // ← Solo NO eliminados

            if ($request->has('categoria') && $request->categoria) {
                $query->where('categoria', $request->categoria);
            }

            if ($request->has('busqueda') && $request->busqueda) {
                $query->where(function($q) use ($request) {
                    $q->where('nombre', 'LIKE', "%{$request->busqueda}%")
                      ->orWhere('descripcion', 'LIKE', "%{$request->busqueda}%");
                });
            }

            $productos = $query->orderBy('destacado', 'desc')
                ->orderBy('fecha_publicacion', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $productos
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar catálogo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Detalle público
     */
    public function detallePublico($id)
    {
        try {
            $producto = ProductoTienda::where('id_producto', $id)
                ->where('visible', true)
                ->where('stock', '>', 0)
                ->whereNull('deleted_at') // ← Solo NO eliminados
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $producto
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        }
    }

    /**
     * Publicación automática
     */
    public function publicacionAutomatica(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            // TODO: Implementar lógica
            return response()->json([
                'success' => true,
                'message' => 'Publicación automática ejecutada',
                'productos_creados' => 0
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en publicación automática: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Configurar días de gracia
     */
    public function configurarDiasGracia(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $request->validate([
                'dias_gracia' => 'required|integer|min:0|max:30'
            ]);

            $empresa = $user->empresa;
            if ($empresa) {
                $empresa->dias_gracia_publicacion = $request->dias_gracia;
                $empresa->save();
            }

            return response()->json([
                'success' => true,
                'message' => 'Días de gracia configurados correctamente',
                'dias_gracia' => $request->dias_gracia
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al configurar días de gracia: ' . $e->getMessage()
            ], 500);
        }
    }
}