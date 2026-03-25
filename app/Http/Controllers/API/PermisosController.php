<?php
// app/Http/Controllers/API/PermisoController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Permiso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;  // <--- AGREGAR ESTA LÍNEA
use Illuminate\Support\Facades\Log;

class PermisosController extends Controller
{
    /**
     * Obtener todos los permisos
     * GET /api/permisos
     */
    public function index()
    {
        try {
            $permisos = Permiso::orderBy('modulo', 'asc')
                ->orderBy('nombre', 'asc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $permisos
            ]);
        } catch (\Exception $e) {
            Log::error('Error al obtener permisos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos'
            ], 500);
        }
    }

    /**
     * Obtener permisos agrupados por módulo
     * GET /api/permisos/agrupados
     */
    public function agrupados()
    {
        try {
            $permisos = Permiso::orderBy('modulo', 'asc')
                ->orderBy('nombre', 'asc')
                ->get()
                ->groupBy('modulo');
            
            $resultado = [];
            foreach ($permisos as $modulo => $items) {
                $resultado[] = [
                    'modulo' => $modulo,
                    'permisos' => $items->map(function($permiso) {
                        return [
                            'id' => $permiso->id_permiso,
                            'nombre' => $permiso->nombre,
                            'descripcion' => $permiso->descripcion
                        ];
                    })
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => $resultado
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos'
            ], 500);
        }
    }

    /**
     * Obtener un permiso específico
     * GET /api/permisos/{id}
     */
    public function show($id)
    {
        try {
            $permiso = Permiso::findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $permiso
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Permiso no encontrado'
            ], 404);
        }
    }

    /**
     * Crear un nuevo permiso
     * POST /api/permisos
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'nombre' => 'required|string|max:50|unique:permisos,nombre',
                'codigo' => 'required|string|max:50|unique:permisos,nombre',
                'descripcion' => 'nullable|string',
                'modulo' => 'required|string|max:50'
            ]);
            
            $permiso = Permiso::create([
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion ?? '',
                'modulo' => $request->modulo
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Permiso creado exitosamente',
                'data' => $permiso
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un permiso
     * PUT /api/permisos/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $permiso = Permiso::findOrFail($id);
            
            $request->validate([
                'nombre' => 'required|string|max:50|unique:permisos,nombre,' . $id . ',id_permiso',
                'descripcion' => 'nullable|string',
                'modulo' => 'required|string|max:50'
            ]);
            
            $permiso->update([
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion ?? '',
                'modulo' => $request->modulo
            ]);
            
            // Si también quieres actualizar el estado
            if ($request->has('estado')) {
                // Si tienes campo estado en la tabla
                // $permiso->estado = $request->estado;
                // $permiso->save();
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Permiso actualizado exitosamente',
                'data' => $permiso
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un permiso
     * DELETE /api/permisos/{id}
     */
    public function destroy($id)
    {
        try {
            $permiso = Permiso::findOrFail($id);
            
            // Verificar si tiene roles asignados
            if ($permiso->roles()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el permiso porque está asignado a roles'
                ], 400);
            }
            
            $permiso->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Permiso eliminado exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar permiso: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener permisos por módulo
     * GET /api/permisos/modulo/{modulo}
     */
    public function porModulo($modulo)
    {
        try {
            $permisos = Permiso::where('modulo', $modulo)
                ->orderBy('nombre', 'asc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $permisos
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos por módulo'
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de permisos
     * GET /api/permisos/estadisticas
     */
    public function estadisticas()
    {
        try {
            $total = Permiso::count();
            $porModulo = Permiso::select('modulo', DB::raw('count(*) as total')) 
                ->groupBy('modulo')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'por_modulo' => $porModulo
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas'
            ], 500);
        }
    }

    /**
     * Crear múltiples permisos (masivo)
     * POST /api/permisos/masivo
     */
    public function storeMasivo(Request $request)
    {
        try {
            $request->validate([
                'permisos' => 'required|array',
                'permisos.*.nombre' => 'required|string|max:50',
                'permisos.*.modulo' => 'required|string|max:50'
            ]);
            
            $creados = 0;
            $errores = [];
            
            foreach ($request->permisos as $permisoData) {
                try {
                    Permiso::create([
                        'nombre' => $permisoData['nombre'],
                        'descripcion' => $permisoData['descripcion'] ?? '',
                        'modulo' => $permisoData['modulo']
                    ]);
                    $creados++;
                } catch (\Exception $e) {
                    $errores[] = [
                        'nombre' => $permisoData['nombre'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => "Se crearon {$creados} permisos, " . count($errores) . " errores",
                'data' => [
                    'creados' => $creados,
                    'errores' => $errores
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear permisos masivos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar múltiples permisos
     * DELETE /api/permisos/masivo
     */
    public function destroyMasivo(Request $request)
    {
        try {
            $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'required|integer|exists:permisos,id_permiso'
            ]);
            
            $eliminados = 0;
            $errores = 0;
            
            foreach ($request->ids as $id) {
                try {
                    $permiso = Permiso::findOrFail($id);
                    if ($permiso->roles()->count() == 0) {
                        $permiso->delete();
                        $eliminados++;
                    } else {
                        $errores++;
                    }
                } catch (\Exception $e) {
                    $errores++;
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => "Se eliminaron {$eliminados} permisos, {$errores} no se pudieron eliminar"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar permisos masivos: ' . $e->getMessage()
            ], 500);
        }
    }
}