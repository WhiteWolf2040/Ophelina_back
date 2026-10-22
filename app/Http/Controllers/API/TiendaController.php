<?php
// app/Http/Controllers/API/TiendaController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\ImagenPrenda;
use App\Models\Prenda;
use App\Models\ProductoTienda;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

            if ($request->filled('busqueda')) {
                $busqueda = $request->busqueda;
                $query->where(function ($q) use ($busqueda) {
                    $q->where('nombre', 'LIKE', "%{$busqueda}%")
                      ->orWhere('descripcion', 'LIKE', "%{$busqueda}%")
                      ->orWhereHas('prenda', function ($pq) use ($busqueda) {
                          $pq->where('tipo', 'LIKE', "%{$busqueda}%")
                             ->orWhere('descripcion', 'LIKE', "%{$busqueda}%");
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
                $imagen = $p->prenda?->imagenPrincipal; // ya viene eager-loaded, sin query nueva

                if ($imagen && !empty($imagen->cloudinary_url)) {
                    $p->imagen_url = $imagen->cloudinary_url;
                } elseif ($imagen && !empty($imagen->imagen_data)) {
                    $p->imagen_url = url('/api/imagen-prenda/' . $p->id_prenda);
                } else {
                    $p->imagen_url = null;
                }
            });

            return response()->json([
                'success' => true,
                'data' => $productos,
                'total' => $productos->count()
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en TiendaController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar productos'
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

            $base = fn () => ProductoTienda::where('id_empresa', $empresaId)
                ->whereNull('deleted_at');

            $total = $base()->count();
            $visibles = $base()->where('visible', true)->count();
            $destacados = $base()->where('destacado', true)->count();
            $ocultos = $total - $visibles;

            // Valor total = suma de precio con descuento aplicado * stock
            $valorTotal = $base()
                ->selectRaw('COALESCE(SUM(precio * (1 - COALESCE(descuento, 0) / 100.0) * stock), 0) as total')
                ->value('total');

            $publicacionesAutomaticas = $base()
                ->where('publicacion_automatica', true)
                ->count();

            return response()->json([
                'success' => true,
                'total' => $total,
                'visibles' => $visibles,
                'ocultos' => $ocultos,
                'destacados' => $destacados,
                'bajo_stock' => 0,
                'valor_total' => round((float) $valorTotal, 2), // número real, no string
                'publicaciones_automaticas' => $publicacionesAutomaticas
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en TiendaController@estadisticas: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar estadísticas'
            ], 500);
        }
    }

    /**
     * Crear producto. La imagen ya viene subida a Cloudinary desde el frontend
     * (solo se recibe la URL resultante).
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

            $imagenUrl = $validated['imagen_url'] ?? null;

            // Todo o nada: si falla un paso, no queda una prenda huérfana
            [$prenda, $producto] = DB::transaction(function () use ($validated, $user, $imagenUrl) {
                $prenda = Prenda::create([
                    'id_empresa' => $user->id_empresa,
                    'descripcion' => $validated['nombre'],
                    'tipo' => $validated['categoria'],
                    'material' => null,
                    'peso_gramos' => null,
                    'valor_estimado' => $validated['precio'],
                    'estado' => 'Disponible',
                    'origen' => 'compra_directa',
                    'fecha_registro' => now(),
                    'codigo_barras' => 'PRN-' . strtoupper(uniqid()),
                ]);

                if (!empty($imagenUrl)) {
                    ImagenPrenda::create([
                        'id_prenda' => $prenda->id_prenda,
                        'cloudinary_url' => $imagenUrl,
                        'es_principal' => true,
                        'orden' => 0,
                    ]);
                }

                $producto = ProductoTienda::create([
                    'id_empresa' => $user->id_empresa,
                    'id_prenda' => $prenda->id_prenda,
                    'nombre' => $prenda->descripcion,
                    'categoria' => $validated['categoria'],
                    'precio' => $validated['precio'],
                    'stock' => $validated['stock'],
                    'descripcion' => $validated['descripcion'] ?? '',
                    'estado_producto' => $validated['estado'],
                    'visible' => $validated['visible'] ?? true,
                    'destacado' => $validated['destacado'] ?? false,
                    'descuento' => $validated['descuento'] ?? 0,
                    'fecha_publicacion' => now()->format('Y-m-d'),
                ]);

                return [$prenda, $producto];
            });

            $imagenSubida = !empty($imagenUrl);

            return response()->json([
                'success' => true,
                'message' => 'Producto creado exitosamente' . ($imagenSubida ? ' con imagen' : ' (sin imagen)'),
                'data' => [
                    'inventario' => $prenda,
                    'tienda' => $producto,
                    'imagen_subida' => $imagenSubida,
                    'imagen_url' => $imagenUrl
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);
        } catch (\Throwable $e) {
            Log::error('❌ ERROR EN STORE: ' . $e->getMessage());
            Log::error('📁 Archivo: ' . $e->getFile() . ':' . $e->getLine());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear el producto'
            ], 500);
        }
    }

    /**
     * Actualizar producto
     * (Si el frontend manda FormData, usar POST + _method=PUT; PHP no lee
     *  multipart en peticiones PUT.)
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

            DB::transaction(function () use ($producto, $validated) {
                $producto->nombre = $validated['nombre'];
                $producto->categoria = $validated['categoria'];
                $producto->precio = $validated['precio'];
                $producto->stock = $validated['stock'];
                $producto->descripcion = $validated['descripcion'] ?? '';
                $producto->estado_producto = $validated['estado'];
                $producto->visible = $validated['visible'] ?? true;
                $producto->destacado = $validated['destacado'] ?? false;
                $producto->descuento = $validated['descuento'] ?? 0;
                $producto->save();

                // index() filtra por prenda.tipo, así que hay que mantenerlo sincronizado
                if ($producto->id_prenda) {
                    Prenda::where('id_prenda', $producto->id_prenda)
                        ->update(['tipo' => $validated['categoria']]);
                }

                // Imagen nueva (ya subida a Cloudinary desde el frontend): reemplaza la anterior
                if (!empty($validated['imagen_url']) && $producto->id_prenda) {
                    ImagenPrenda::where('id_prenda', $producto->id_prenda)->delete();

                    ImagenPrenda::create([
                        'id_prenda' => $producto->id_prenda,
                        'cloudinary_url' => $validated['imagen_url'],
                        'es_principal' => true,
                        'orden' => 0,
                    ]);
                }
            });

            $producto->load('prenda');
            $producto->imagen_url = $this->urlImagenDePrenda($producto->prenda);

            return response()->json([
                'success' => true,
                'message' => 'Producto actualizado correctamente',
                'data' => $producto
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Error en TiendaController@update: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar el producto'
            ], 500);
        }
    }

    /**
     * Helper: guarda una imagen codificada en base64 (texto) en imagen_prenda.imagen_data.
     *
     * IMPORTANTE: imagen_data debe ser columna `text` (NO `bytea`). En este entorno
     * (PHP 8.4-fpm-alpine + pdo_pgsql), bindear binario crudo contra una columna bytea
     * provoca un crash a nivel de proceso en PHP-FPM. Guardar como base64/texto evita
     * ese binding binario por completo y es seguro para imágenes <1MB.
     *
     * (Actualmente no se usa en store/update porque las imágenes van a Cloudinary,
     *  pero se conserva por compatibilidad con imágenes antiguas.)
     */
    private function guardarImagenEnBD(int $idPrenda, $file, bool $esPrincipal = true, int $orden = 0): bool
    {
        try {
            $binario = file_get_contents($file->getRealPath());
            if ($binario === false) {
                throw new \RuntimeException('No se pudo leer el archivo subido');
            }

            ImagenPrenda::create([
                'id_prenda' => $idPrenda,
                'ruta_archivo' => $file->getClientOriginalName(),
                'imagen_data' => base64_encode($binario), // texto puro, no binario
                'imagen_mime' => $file->getMimeType(),
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
     * Sirve los bytes de la imagen desde la BD (imágenes antiguas en base64)
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

        // Prioridad: cloudinary_url > imagen_data (BD)
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

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Error en toggleVisibilidad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar visibilidad'
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

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Error en toggleDestacado: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar destacado'
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

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Error al eliminar producto: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar producto'
            ], 500);
        }
    }

    /**
     * Catálogo público
     * NOTA: no filtra por empresa; mezcla productos de todas las empresas.
     */
    public function catalogoPublico(Request $request)
    {
        try {
            $query = ProductoTienda::with('prenda')
                ->where('visible', true)
                ->where('stock', '>', 0)
                ->whereNull('deleted_at');

            if ($request->filled('categoria')) {
                $query->where('categoria', $request->categoria);
            }

            if ($request->filled('busqueda')) {
                $busqueda = $request->busqueda;
                $query->where(function ($q) use ($busqueda) {
                    $q->where('nombre', 'LIKE', "%{$busqueda}%")
                      ->orWhere('descripcion', 'LIKE', "%{$busqueda}%");
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

        } catch (\Throwable $e) {
            Log::error('Error en catalogoPublico: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar catálogo'
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

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        }
    }

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

            $empresa = Empresa::find($user->id_empresa);

            $diasGracia = (int) $request->input(
                'dias_gracia',
                $empresa->dias_gracia_publicacion ?? 5
            );

            $fechaLimite = now()->subDays($diasGracia)->toDateString();
            $tablaPrendas = (new Prenda)->getTable(); // evita fijar el nombre de la tabla

            $creados = DB::transaction(function () use ($user, $fechaLimite, $tablaPrendas) {

                // 1) Pasar a 'vencido' los que ya vencieron por fecha y seguían 'activo'
                DB::table('empeno')
                    ->where('id_empresa', $user->id_empresa)
                    ->where('estado', 'activo')
                    ->whereDate('fecha_vencimiento', '<', now()->toDateString())
                    ->update(['estado' => 'vencido']);

                // 2) Candidatos: vencidos, con gracia cumplida y sin producto_tienda activo
                $empenosElegibles = DB::table('empeno as e')
                    ->join("{$tablaPrendas} as p", 'p.id_prenda', '=', 'e.id_prenda')
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
                        'e.id_empeno', 'e.id_prenda', 'e.folio', 'e.fecha_vencimiento',
                        'p.descripcion', 'p.tipo', 'p.valor_estimado'
                    )
                    ->get();

                $creados = 0;

                foreach ($empenosElegibles as $item) {
                    // La imagen no se copia: el producto la hereda de su prenda
                    // (imagen_prenda), igual que los productos creados a mano.
                    DB::table('producto_tienda')->insert([
                        'id_empresa'                 => $user->id_empresa,
                        'id_prenda'                  => $item->id_prenda,
                        'id_empeno_original'         => $item->id_empeno,
                        'nombre'                     => $item->descripcion,
                        'categoria'                  => $item->tipo,
                        'precio'                     => $item->valor_estimado,
                        'stock'                      => 1,
                        'descripcion'                => $item->descripcion,
                        'estado_producto'            => 'Buen estado',
                        'visible'                    => true,
                        'destacado'                  => false,
                        'descuento'                  => 0,
                        'publicacion_automatica'     => true,
                        'fecha_vencimiento_contrato' => $item->fecha_vencimiento,
                        'fecha_publicacion'          => now()->format('Y-m-d'),
                    ]);

                    // La prenda pasa a 'Vencido' (ya no está en empeño, está en venta)
                    DB::table($tablaPrendas)
                        ->where('id_prenda', $item->id_prenda)
                        ->update(['estado' => 'Vencido']);

                    // El empeño se marca 'en_tienda' -> sale de los empeños activos
                    DB::table('empeno')
                        ->where('id_empeno', $item->id_empeno)
                        ->update(['estado' => 'en_tienda']);

                    $creados++;
                }

                return $creados;
            });

            return response()->json([
                'success' => true,
                'message' => "{$creados} producto(s) publicado(s) automáticamente",
                'productos_creados' => $creados,
            ]);

        } catch (\Throwable $e) {
            Log::error('❌ Error en publicacionAutomatica: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Error al ejecutar la publicación automática',
            ], 500);
        }
    }

    /**
     * Configurar días de gracia
     * (requiere la columna empresa.dias_gracia_publicacion: ver migración)
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

            $empresa = Empresa::find($user->id_empresa);

            if (!$empresa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Empresa no encontrada'
                ], 404);
            }

            $empresa->dias_gracia_publicacion = (int) $request->dias_gracia;
            $empresa->save();

            return response()->json([
                'success' => true,
                'message' => 'Días de gracia configurados correctamente',
                'dias_gracia' => (int) $request->dias_gracia
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors()
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error en configurarDiasGracia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al configurar días de gracia'
            ], 500);
        }
    }
}