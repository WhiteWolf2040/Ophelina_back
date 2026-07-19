<?php
// app/Http/Controllers/Api/PrendaController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prenda;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PrendaController extends Controller
{
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

        } catch (\Exception $e) {
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
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $prenda
            ]);

        } catch (\Exception $e) {
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

            $validated = $request->validate([
                'descripcion' => 'required|string|max:255',
                'tipo' => 'required|string',
                'material' => 'nullable|string',
                'peso_gramos' => 'nullable|numeric',
                'valor_estimado' => 'required|numeric|min:1',
                'estado' => 'nullable|string'
            ]);

            $prenda = Prenda::create([
                'id_empresa' => $user->id_empresa,
                'descripcion' => $validated['descripcion'],
                'tipo' => $validated['tipo'],
                'material' => $validated['material'] ?? null,
                'peso_gramos' => $validated['peso_gramos'] ?? null,
                'valor_estimado' => $validated['valor_estimado'],
                'estado' => $validated['estado'] ?? 'Disponible',
                'codigo_barras' => 'PRN-' . strtoupper(uniqid()),
                'fecha_registro' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Prenda creada correctamente',
                'data' => $prenda
            ]);

        } catch (\Exception $e) {
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
                'tipo' => 'required|string',
                'material' => 'nullable|string',
                'peso_gramos' => 'nullable|numeric',
                'valor_estimado' => 'required|numeric|min:1',
                'estado' => 'nullable|string'
            ]);

            $prenda->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Prenda actualizada correctamente',
                'data' => $prenda
            ]);

        } catch (\Exception $e) {
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

        } catch (\Exception $e) {
            Log::error('Error en PrendaController@destroy: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar prenda: ' . $e->getMessage()
            ], 500);
        }
    }
}