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
        // ==========================================
        // 🔍 LOG 1: VERIFICAR AUTENTICACIÓN
        // ==========================================
        $user = $request->user();
        
        Log::channel('daily')->info('🔍 [STORE] Iniciando creación de producto', [
            'timestamp' => now()->toDateTimeString(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        Log::channel('daily')->info('👤 [STORE] Usuario:', [
            'id' => $user?->id_usuario,
            'email' => $user?->email,
            'empresa_id' => $user?->id_empresa,
            'autenticado' => $user ? 'SÍ' : 'NO'
        ]);

        if (!$user) {
            Log::channel('daily')->warning('❌ [STORE] Usuario no autenticado');
            return response()->json([
                'success' => false,
                'message' => 'Usuario no autenticado. Inicia sesión primero.'
            ], 401);
        }

        // ==========================================
        // 🔍 LOG 2: VERIFICAR DATOS RECIBIDOS
        // ==========================================
        Log::channel('daily')->info('📥 [STORE] Datos recibidos:', [
            'all' => $request->all(),
            'files_keys' => array_keys($request->allFiles()),
            'has_file' => $request->hasFile('imagen'),
            'content_type' => $request->headers->get('content-type'),
            'method' => $request->method(),
            'uri' => $request->getRequestUri()
        ]);

        if ($request->hasFile('imagen')) {
            $file = $request->file('imagen');
            Log::channel('daily')->info('📸 [STORE] Detalles de la imagen:', [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'size_mb' => round($file->getSize() / 1024 / 1024, 2),
                'extension' => $file->getClientOriginalExtension(),
                'is_valid' => $file->isValid()
            ]);
        }

        // ==========================================
        // 🔍 LOG 3: VALIDACIÓN
        // ==========================================
        Log::channel('daily')->info('🔍 [STORE] Iniciando validación...');

        try {
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

            Log::channel('daily')->info('✅ [STORE] Validación exitosa:', $validated);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::channel('daily')->error('❌ [STORE] Error de validación:', [
                'errors' => $e->errors(),
                'all_data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        }

        // ==========================================
        // 🔍 LOG 4: CREAR PRENDA (INVENTARIO)
        // ==========================================
        Log::channel('daily')->info('🔍 [STORE] Creando prenda en inventario...');

        try {
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

            Log::channel('daily')->info('✅ [STORE] Prenda creada:', [
                'id_prenda' => $prenda->id_prenda,
                'codigo_barras' => $prenda->codigo_barras
            ]);
        } catch (\Exception $e) {
            Log::channel('daily')->error('❌ [STORE] Error al crear prenda:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        // ==========================================
        // 🔍 LOG 5: SUBIR IMAGEN A CLOUDINARY
        // ==========================================
        $imagenSubida = false;
        $imagenUrl = null;

        if ($request->hasFile('imagen')) {
            Log::channel('daily')->info('🔍 [STORE] Iniciando subida a Cloudinary...');

            try {
                $file = $request->file('imagen');

                // Verificar que el archivo sea válido
                if (!$file->isValid()) {
                    throw new \Exception('El archivo de imagen no es válido: ' . $file->getErrorMessage());
                }

                // Verificar que Cloudinary esté configurado
                if (!config('cloudinary.cloud_url')) {
                    Log::channel('daily')->error('❌ [STORE] CLOUDINARY_URL no está configurada');
                    throw new \Exception('Cloudinary no está configurado en el servidor');
                }

                Log::channel('daily')->info('☁️ [STORE] Configuración de Cloudinary:', [
                    'cloud_name' => config('cloudinary.cloud_name'),
                    'api_key' => config('cloudinary.api_key') ? '***' : 'NO CONFIGURADO',
                    'api_secret' => config('cloudinary.api_secret') ? '***' : 'NO CONFIGURADO',
                    'cloud_url' => config('cloudinary.cloud_url') ? 'CONFIGURADO' : 'NO CONFIGURADO'
                ]);

                // Subir a Cloudinary
                $resultado = Cloudinary::upload($file->getRealPath(), [
                    'folder' => 'ophelia/prendas',
                    'public_id' => 'prenda_' . $prenda->id_prenda . '_' . time(),
                    'resource_type' => 'image',
                    'transformation' => [
                        'quality' => 'auto',
                        'fetch_format' => 'auto',
                    ]
                ]);

                Log::channel('daily')->info('☁️ [STORE] Resultado de Cloudinary:', [
                    'secure_url' => $resultado->getSecurePath(),
                    'public_id' => $resultado->getPublicId(),
                    'bytes' => $resultado->getBytes()
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

                $imagen = ImagenPrenda::create($imagenData);
                $imagenUrl = $resultado->getSecurePath();
                $imagenSubida = true;

                Log::channel('daily')->info('✅ [STORE] Imagen guardada en BD:', [
                    'id_imagen' => $imagen->id_imagen ?? 'sin_id',
                    'url' => $imagenUrl
                ]);

            } catch (\Exception $e) {
                Log::channel('daily')->error('❌ [STORE] Error al subir imagen a Cloudinary:', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // Continúa con el producto pero sin imagen (no es crítico)
                Log::channel('daily')->warning('⚠️ [STORE] Producto creado SIN imagen debido a error en Cloudinary');
            }
        } else {
            Log::channel('daily')->info('ℹ️ [STORE] No se recibió imagen para subir');
        }

        // ==========================================
        // 🔍 LOG 6: CREAR PRODUCTO EN TIENDA
        // ==========================================
        Log::channel('daily')->info('🔍 [STORE] Creando producto en tienda...');

        try {
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

            Log::channel('daily')->info('✅ [STORE] Producto creado:', [
                'id_producto' => $producto->id_producto,
                'nombre' => $producto->nombre,
                'precio' => $producto->precio,
                'stock' => $producto->stock
            ]);

        } catch (\Exception $e) {
            Log::channel('daily')->error('❌ [STORE] Error al crear producto en tienda:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        // ==========================================
        // 🔍 LOG 7: RESPUESTA FINAL
        // ==========================================
        Log::channel('daily')->info('🎉 [STORE] Producto creado exitosamente:', [
            'id_producto' => $producto->id_producto,
            'id_prenda' => $prenda->id_prenda,
            'imagen_subida' => $imagenSubida,
            'imagen_url' => $imagenUrl
        ]);

        // Cargar relaciones para la respuesta
        $producto->load('prenda');
        $producto->imagen_url = $imagenUrl ?? $this->urlImagenDePrenda($producto->prenda);

        return response()->json([
            'success' => true,
            'message' => '✅ Producto creado exitosamente' . ($imagenSubida ? ' con imagen' : ' (sin imagen)'),
            'data' => [
                'inventario' => $prenda,
                'tienda' => $producto,
                'imagen_subida' => $imagenSubida,
                'imagen_url' => $imagenUrl
            ]
        ]);

    } catch (\Illuminate\Validation\ValidationException $e) {
        Log::channel('daily')->error('❌ [STORE] Error de validación:', [
            'errors' => $e->errors(),
            'all_data' => $request->all()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Error de validación',
            'errors' => $e->errors()
        ], 422);

    } catch (\Exception $e) {
        Log::channel('daily')->error('❌ [STORE] Error general:', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Error interno del servidor: ' . $e->getMessage(),
            'debug' => [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
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