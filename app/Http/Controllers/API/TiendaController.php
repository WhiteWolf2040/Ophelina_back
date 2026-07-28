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

             $publicacionesAutomaticas = ProductoTienda::where('id_empresa', $empresaId)
                ->whereNull('deleted_at')
                ->where('publicacion_automatica', true)
                ->count();


            return response()->json([
                'success' => true,
                'total' => $total,
                'visibles' => $visibles,
                'ocultos' => $ocultos,
                'destacados' => $destacados,
                'bajo_stock' => 0,
                'valor_total' => number_format($valorTotal, 2),
                'publicaciones_automaticas' => $publicacionesAutomaticas
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Crear producto - Guarda imagen en Base de Datos (SIN Cloudinary)
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
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
                'imagen_url' => 'nullable|url|max:500',
            ]);

            // Crear prenda
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

            $imagenUrl = null;
            $imagenSubida = false;

            // ✅ La imagen ya se subió a Cloudinary desde el frontend; aquí solo
            // guardamos la URL resultante como texto (sin manejar archivos ni binario).
            if (!empty($validated['imagen_url'])) {
                ImagenPrenda::create([
                    'id_prenda' => $prenda->id_prenda,
                    'cloudinary_url' => $validated['imagen_url'],
                    'es_principal' => true,
                    'orden' => 0,
                ]);
                $imagenSubida = true;
                $imagenUrl = $validated['imagen_url'];
            }

            // Crear producto en tienda
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
            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);
        } catch (\Throwable $e) {
            Log::error('❌ ERROR EN STORE: ' . $e->getMessage());
            Log::error('📁 Archivo: ' . $e->getFile() . ':' . $e->getLine());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
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
                'imagen_url' => 'nullable|url|max:500',
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

            // ✅ Si viene una imagen_url nueva (ya subida a Cloudinary desde el frontend),
            // se reemplaza la imagen anterior por la nueva URL.
            if (!empty($validated['imagen_url'])) {
                ImagenPrenda::where('id_prenda', $producto->id_prenda)->delete();

                ImagenPrenda::create([
                    'id_prenda' => $producto->id_prenda,
                    'cloudinary_url' => $validated['imagen_url'],
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

        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Helper: guarda una imagen codificada en base64 (texto) en imagen_prenda.imagen_data.
     *
     * IMPORTANTE: imagen_data debe ser columna `text` (NO `bytea`). En este entorno
     * (PHP 8.4-fpm-alpine + pdo_pgsql), bindear binario crudo contra una columna bytea
     * provoca un crash a nivel de proceso en PHP-FPM (nginx devuelve un 500 genérico,
     * sin ninguna excepción capturable). Guardar como base64/texto evita ese binding
     * binario por completo y es seguro para imágenes <1MB.
     */
    private function guardarImagenEnBD(int $idPrenda, $file, bool $esPrincipal = true, int $orden = 0): bool
    {
        try {
            $binario = file_get_contents($file->getRealPath());
            if ($binario === false) {
                throw new \RuntimeException('No se pudo leer el archivo subido');
            }

            $mime = $file->getMimeType();
            $nombreOriginal = $file->getClientOriginalName();
            $base64 = base64_encode($binario);

            ImagenPrenda::create([
                'id_prenda' => $idPrenda,
                'ruta_archivo' => $nombreOriginal,
                'imagen_data' => $base64, // texto puro, no binario
                'imagen_mime' => $mime,
                'es_principal' => $esPrincipal,
                'orden' => $orden,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('❌ Error guardando imagen en BD: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sirve los bytes de la imagen desde la BD
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

        // imagen_data se guarda como texto base64 (ver guardarImagenEnBD) — hay que
        // decodificarlo antes de servir los bytes reales de la imagen.
        $datos = base64_decode($imagen->imagen_data);

        return response($datos)
            ->header('Content-Type', $imagen->imagen_mime ?? 'image/jpeg')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /**
     * Helper: obtiene la URL de la imagen
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

        // Prioridad: cloudinary_url (si existe) > imagen_data (BD)
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
/**
 * Publicación automática: busca empeños vencidos (con periodo de gracia
 * cumplido) que aún no tengan producto en tienda, y los publica.
 *
 * POST /api/tienda/publicacion-automatica
 * body: { dias_gracia?: number }
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

        $diasGracia = (int) $request->input(
            'dias_gracia',
            $user->empresa->dias_gracia_publicacion ?? 5
        );

        $fechaLimite = now()->subDays($diasGracia)->toDateString();

        // 1) Igual que EmpenoController@actualizarEstados: pasar a 'vencido'
        //    los que ya vencieron por fecha y seguían como 'activo'
        DB::table('empeno')
            ->where('id_empresa', $user->id_empresa)
            ->where('estado', 'activo')
            ->whereDate('fecha_vencimiento', '<', now()->toDateString())
            ->update(['estado' => 'vencido']);

        // 2) Candidatos: vencidos, con periodo de gracia cumplido, y que
        //    todavía NO tengan producto_tienda creado (ni borrado)
        $empenosElegibles = DB::table('empeno as e')
            ->join('prendas as p', 'p.id_prenda', '=', 'e.id_prenda')
            ->where('e.id_empresa', $user->id_empresa)
            ->where('e.estado', 'vencido')
            ->whereDate('e.fecha_vencimiento', '<=', $fechaLimite)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('producto_tienda as pt')
                  ->whereColumn('pt.id_empeno_original', 'e.id_empeno')
                  ->whereNull('pt.deleted_at');
            })
            ->select(
                'e.id_empeno', 'e.id_prenda', 'e.folio',
                'p.descripcion', 'p.tipo', 'p.valor_estimado', 'p.imagen_url'
            )
            ->get();

        $creados = 0;

        DB::beginTransaction();

        foreach ($empenosElegibles as $item) {
            DB::table('producto_tienda')->insert([
                'id_empresa'             => $user->id_empresa,
                'id_prenda'              => $item->id_prenda,
                'id_empeno_original'     => $item->id_empeno,
                'nombre'                 => $item->descripcion,
                'categoria'              => $item->tipo,
                'precio'                 => $item->valor_estimado,
                'stock'                  => 1,
                'descripcion'            => $item->descripcion,
                'estado_producto'        => 'Buen estado',
                'visible'                => true,
                'destacado'              => false,
                'descuento'              => 0,
                'imagen_url'             => $item->imagen_url, // ← se copia directo, columna ya existe
                'publicacion_automatica' => true,
                'fecha_publicacion'      => now()->format('Y-m-d'),
            ]);

            // La prenda pasa a 'Vencido' (ya no está "En Empeño", está en venta)
            DB::table('prendas')
                ->where('id_prenda', $item->id_prenda)
                ->update(['estado' => 'Vencido']);

            // El empeño se marca 'en_tienda' → sale de "Mis Empeños" activos
            DB::table('empeno')
                ->where('id_empeno', $item->id_empeno)
                ->update(['estado' => 'en_tienda']);

            $creados++;
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => "{$creados} producto(s) publicado(s) automáticamente",
            'productos_creados' => $creados,
        ]);

    } catch (\Throwable $e) {
        DB::rollBack();
        Log::error('❌ Error en publicacionAutomatica: ' . $e->getMessage());
        Log::error($e->getTraceAsString());

        return response()->json([
            'success' => false,
            'message' => 'Error al ejecutar la publicación automática',
            'error' => $e->getMessage(),
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