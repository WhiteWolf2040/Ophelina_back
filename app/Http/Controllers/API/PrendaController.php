<?php
// app/Http/Controllers/Api/PrendaController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Prenda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PrendaController extends Controller
{
    /**
     * ✅ NUEVO: valores permitidos, deben coincidir EXACTAMENTE (con acentos)
     * con los CHECK constraints "prendas_tipo_check" y "prendas_estado_check"
     * de la tabla `prendas`. Centralizados aquí para no repetirlos en store()
     * y update(), y para que si Postgres cambia el constraint algún día,
     * solo haya que tocar este archivo en un solo lugar.
     */
    private const TIPOS_VALIDOS = ['Joyería', 'Electrónica', 'Relojes', 'Herramientas', 'Instrumentos', 'Otros'];
    private const ESTADOS_VALIDOS = ['Disponible', 'En Empeño', 'Vendido', 'Vencido', 'Apartado'];

    /**
     * Listar todas las prendas (inventario)
     * GET /api/prendas
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

            $prendas = Prenda::where('id_empresa', $user->id_empresa)
                ->orderBy('fecha_registro', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $prendas
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en PrendaController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar inventario: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener una prenda específica
     * GET /api/prendas/{id}
     */
    public function show($id)
    {
        try {
            $user = request()->user();

            $prenda = Prenda::where('id_prenda', $id)
                ->where('id_empresa', $user->id_empresa)
                ->with(['empenos', 'producto_tienda']) // ← Cargar relaciones
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => [
                    'inventario' => $prenda,
                    'tienda' => $prenda->producto_tienda, // ← Datos de tienda
                    'empeno' => $prenda->empenos->first() // ← Último empeño
                ]
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Prenda no encontrada'
            ], 404);
        }
    }

    /**
     * Crear una nueva prenda
     * POST /api/prendas
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

            // ✅ NUEVO: se agregó 'in:' con los valores exactos del CHECK
            // constraint. Antes esto no existía, así que un valor mal
            // escrito desde el frontend (ej. "Electrónico" en vez de
            // "Electrónica") pasaba la validación de Laravel y explotaba
            // hasta abajo, en Postgres, como una excepción críptica.
            $validated = $request->validate([
                'descripcion' => 'required|string|max:255',
                'tipo' => 'required|string|in:' . implode(',', self::TIPOS_VALIDOS),
                'material' => 'nullable|string',
                'peso_gramos' => 'nullable|numeric',
                'valor_estimado' => 'required|numeric|min:1',
                'estado' => 'nullable|string|in:' . implode(',', self::ESTADOS_VALIDOS),
                // ✅ NUEVO: la imagen ya se sube a Cloudinary desde el
                // frontend (NuevoInventario.jsx); aquí solo se recibe y
                // guarda la URL resultante como texto, igual que
                // TiendaController@store hace con producto_tienda.
                'imagen_url' => 'nullable|url|max:255',
            ]);

            $prenda = Prenda::create([
                'id_empresa' => $user->id_empresa,
                'descripcion' => $validated['descripcion'],
                'tipo' => $validated['tipo'],
                'material' => $validated['material'] ?? null,
                'peso_gramos' => $validated['peso_gramos'] ?? null,
                'valor_estimado' => $validated['valor_estimado'],
                'estado' => $validated['estado'] ?? 'Disponible',
                'imagen_url' => $validated['imagen_url'] ?? null,
                'codigo_barras' => 'PRN-' . strtoupper(uniqid()),
                'fecha_registro' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Prenda creada correctamente',
                'data' => $prenda
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error en PrendaController@store: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear prenda: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar una prenda
     * PUT /api/prendas/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();

            $prenda = Prenda::where('id_prenda', $id)
                ->where('id_empresa', $user->id_empresa)
                ->firstOrFail();

            $validated = $request->validate([
                'descripcion' => 'required|string|max:255',
                'tipo' => 'required|string|in:' . implode(',', self::TIPOS_VALIDOS),
                'material' => 'nullable|string',
                'peso_gramos' => 'nullable|numeric',
                'valor_estimado' => 'required|numeric|min:1',
                'estado' => 'nullable|string|in:' . implode(',', self::ESTADOS_VALIDOS),
                // ✅ NUEVO: permite reemplazar la imagen al editar la prenda.
                'imagen_url' => 'nullable|url|max:255',
            ]);

            $prenda->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Prenda actualizada correctamente',
                'data' => $prenda
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error en PrendaController@update: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar prenda: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una prenda
     * DELETE /api/prendas/{id}
     */
    public function destroy($id)
    {
        try {
            $user = request()->user();

            $prenda = Prenda::where('id_prenda', $id)
                ->where('id_empresa', $user->id_empresa)
                ->firstOrFail();

            $prenda->delete();

            return response()->json([
                'success' => true,
                'message' => 'Prenda eliminada correctamente'
            ]);

        } catch (\Throwable $e) {
            Log::error('Error en PrendaController@destroy: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar prenda: ' . $e->getMessage()
            ], 500);
        }
    }
}