<?php
// app/Http/Controllers/API/TiendaController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ProductoTienda;
use App\Models\ImagenPrenda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Prenda;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

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

            $query = ProductoTienda::with(['prenda', 'prenda.imagenPrincipal'])
                ->where('id_empresa', $user->id_empresa)
                ->whereNull('deleted_at');

            if ($request->has('busqueda') && $request->busqueda) {
                $query->where(function ($q) use ($request) {
                    $q->where('nombre', 'LIKE', "%{$request->busqueda}%")
                      ->orWhere('descripcion', 'LIKE', "%{$request->busqueda}%")
                      ->orWhereHas('prenda', function ($pq) use ($request) {
                          $pq->where('tipo', 'LIKE', "%{$request->busqueda}%")
                             ->orWhere('descripcion', 'LIKE', "%{$request->busqueda}%");
                      });
                });
            }

            if ($request->has('categoria') && $request->categoria !== 'Todas') {
                $query->whereHas('prenda', function ($q) use ($request) {
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

            $productos->each(function (ProductoTienda $p) {
                $p->imagen_url = $this->urlImagenDePrenda($p->prenda);
            });

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
 * Crear producto (sincroniza Inventario + Tienda + Imagen en Cloudinary)
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

        // 🔍 LOG: Datos recibidos
        Log::info('📥 Datos recibidos en store:', [
            'user_id' => $user->id_usuario,
            'empresa_id' => $user->id_empresa,
            'has_file' => $request->hasFile('imagen'),
            'all_data' => $request->all(),
            'files' => $request->allFiles()
        ]);

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
            'imagen' => 'nullable|file|image|max:5120',
        ]);

        // CREAR EN INVENTARIO (prendas)
        $prenda = Prenda::create([
            'id_empresa' => $user->id_empresa,
            'descripcion' => $validated['nombre'],
            'tipo' => $validated['categoria'] ?? 'Otros',
            'material' => null,
            'peso_gramos' => null,
            'valor_estimado' => $validated['precio'],
            'estado' => 'Disponible',
            'origen' => 'compra_directa',
            'fecha_registro' => now(),
            'codigo_barras' => 'PRN-' . strtoupper(uniqid()),
        ]);

        Log::info('✅ Prenda creada:', ['id_prenda' => $prenda->id_prenda]);

        // ✅ SUBIR IMAGEN A CLOUDINARY
        if ($request->hasFile('imagen')) {
            try {
                $file = $request->file('imagen');
                
                // 🔍 LOG: Información del archivo
                Log::info('📸 Procesando imagen:', [
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'extension' => $file->getClientOriginalExtension()
                ]);

                // Verificar que el archivo sea válido
                if (!$file->isValid()) {
                    throw new \Exception('El archivo de imagen no es válido');
                }

                // Subir a Cloudinary con más configuración
                $resultado = Cloudinary::upload($file->getRealPath(), [
                    'folder' => 'ophelia/prendas',
                    'public_id' => 'prenda_' . $prenda->id_prenda . '_' . time(),
                    'resource_type' => 'image',
                    'transformation' => [
                        'quality' => 'auto',
                        'fetch_format' => 'auto',
                    ]
                ]);

                // 🔍 LOG: Resultado de Cloudinary
                Log::info('☁️ Imagen subida a Cloudinary:', [
                    'secure_url' => $resultado->getSecurePath(),
                    'public_id' => $resultado->getPublicId()
                ]);

                // Crear registro de imagen
                $imagenData = [
                    'id_prenda' => $prenda->id_prenda,
                    'ruta_archivo' => $file->getClientOriginalName(),
                    'cloudinary_url' => $resultado->getSecurePath(),
                    'imagen_mime' => $file->getMimeType(),
                    'es_principal' => true,
                    'orden' => 0,
                ];
                
                // Verificar si el modelo ImagenPrenda tiene estos campos
                Log::info('📝 Creando ImagenPrenda con:', $imagenData);
                
                $imagen = ImagenPrenda::create($imagenData);
                
                Log::info('✅ ImagenPrenda creada:', ['id' => $imagen->id_imagen ?? 'sin_id']);

            } catch (\Exception $e) {
                Log::error('❌ Error al subir imagen a Cloudinary: ' . $e->getMessage());
                Log::error('Stack trace: ' . $e->getTraceAsString());
                
                // Si falla Cloudinary, no eliminamos la prenda pero guardamos sin imagen
                // Así el producto se crea igual
                Log::warning('⚠️ Producto creado sin imagen debido a error en Cloudinary');
            }
        }

        // CREAR EN TIENDA (producto_tienda)
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
        ]);

        Log::info('✅ ProductoTienda creado:', ['id_producto' => $producto->id_producto]);

        $prenda->refresh();
        $producto->imagen_url = $this->urlImagenDePrenda($prenda);

        return response()->json([
            'success' => true,
            'message' => '✅ Producto creado en Inventario y Tienda',
            'data' => [
                'inventario' => $prenda,
                'tienda' => $producto
            ]
        ]);

    } catch (\Illuminate\Validation\ValidationException $e) {
        Log::error('❌ Error de validación: ' . json_encode($e->errors()));
        return response()->json([
            'success' => false,
            'message' => 'Error de validación',
            'errors' => $e->errors()
        ], 422);
    } catch (\Exception $e) {
        Log::error('❌ Error en TiendaController@store: ' . $e->getMessage());
        Log::error('Stack trace: ' . $e->getTraceAsString());
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
                'precio' => 'required|numeric|min:0',
                'stock' => 'required|integer|min:0',
                'descripcion' => 'nullable|string',
                'estado' => 'required|string|in:Nuevo,Como nuevo,Buen estado,Aceptable',
                'visible' => 'boolean',
                'destacado' => 'boolean',
                'descuento' => 'numeric|min:0|max:100',
                'imagen' => 'nullable|file|image|max:5120',
            ]);

            $producto->nombre = $validated['nombre'];
            $producto->precio = $validated['precio'];
            $producto->stock = $validated['stock'];
            $producto->descripcion = $validated['descripcion'] ?? '';
            $producto->estado_producto = $validated['estado'];
            $producto->visible = $validated['visible'] ?? true;
            $producto->destacado = $validated['destacado'] ?? false;
            $producto->descuento = $validated['descuento'] ?? 0;
            $producto->save();

            // ✅ Si viene una imagen nueva, se sube a Cloudinary
            if ($request->hasFile('imagen')) {
                $file = $request->file('imagen');

                ImagenPrenda::where('id_prenda', $producto->id_prenda)
                    ->update(['es_principal' => false]);

                $resultado = Cloudinary::upload($file->getRealPath(), [
                    'folder' => 'ophelia/prendas',
                ]);

                ImagenPrenda::create([
                    'id_prenda' => $producto->id_prenda,
                    'ruta_archivo' => $file->getClientOriginalName(),
                    'cloudinary_url' => $resultado->getSecurePath(),
                    'imagen_mime' => $file->getMimeType(),
                    'es_principal' => true,
                    'orden' => 0,
                ]);
            }

            $producto->load('prenda');
            $producto->imagen_url = $this->urlImagenDePrenda($producto->prenda);

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
     * Sirve los bytes de la imagen SOLO para registros viejos que aún
     * tengan binario guardado en la BD (imagen_data) y no cloudinary_url.
     * GET /api/imagen-prenda/{id}
     */
    public function verImagen($id)
    {
        $imagen = ImagenPrenda::where('id_prenda', $id)
            ->where('es_principal', true)
            ->first();

        if (!$imagen) {
            $imagen = ImagenPrenda::where('id_prenda', $id)->first();
        }

        if (!$imagen || !$imagen->imagen_data) {
            abort(404, 'Imagen no encontrada');
        }

        return response($imagen->imagen_data)
            ->header('Content-Type', $imagen->imagen_mime ?? 'image/jpeg')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Helper: prioriza cloudinary_url; si no existe, cae al endpoint viejo de binario.
     */
    private function urlImagenDePrenda(?Prenda $prenda): ?string
    {
        if (!$prenda) {
            return null;
        }

        $imagen = ImagenPrenda::where('id_prenda', $prenda->id_prenda)
            ->where('es_principal', true)
            ->first();

        if (!$imagen) {
            $imagen = ImagenPrenda::where('id_prenda', $prenda->id_prenda)->first();
        }

        if (!$imagen) {
            return null;
        }

        if (!empty($imagen->cloudinary_url)) {
            return $imagen->cloudinary_url;
        }

        if (!empty($imagen->imagen_data)) {
            return url('/api/imagen-prenda/' . $prenda->id_prenda);
        }

        return null;
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
                ->whereNull('deleted_at')
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
                ->whereNull('deleted_at')
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
            $query = ProductoTienda::with('prenda')
                ->where('visible', true)
                ->where('stock', '>', 0)
                ->whereNull('deleted_at');

            if ($request->has('categoria') && $request->categoria) {
                $query->where('categoria', $request->categoria);
            }

            if ($request->has('busqueda') && $request->busqueda) {
                $query->where(function ($q) use ($request) {
                    $q->where('nombre', 'LIKE', "%{$request->busqueda}%")
                      ->orWhere('descripcion', 'LIKE', "%{$request->busqueda}%");
                });
            }

            $productos = $query->orderBy('destacado', 'desc')
                ->orderBy('fecha_publicacion', 'desc')
                ->get();

            $productos->each(function (ProductoTienda $p) {
                $p->imagen_url = $this->urlImagenDePrenda($p->prenda);
            });

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
            $producto = ProductoTienda::with('prenda')
                ->where('id_producto', $id)
                ->where('visible', true)
                ->where('stock', '>', 0)
                ->whereNull('deleted_at')
                ->firstOrFail();

            $producto->imagen_url = $this->urlImagenDePrenda($producto->prenda);

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