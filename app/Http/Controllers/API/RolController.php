<?php
// app/Http/Controllers/API/RolController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Rol;
use App\Models\Permiso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RolController extends Controller
{
    /**
     * Obtener todos los roles
     * GET /api/roles
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            $roles = Rol::with(['usuarios', 'permisos'])
                ->orderBy('nivel', 'asc')
                ->get()
                ->map(function ($rol) {
                    return [
                        'id' => $rol->id_rol,
                        'nombre' => $rol->nombre,
                        'nivel' => $rol->nivel,
                        'nivel_texto' => $rol->nivel_texto,
                        'descripcion' => $rol->descripcion,
                        'usuarios' => $rol->usuarios->count(),
                        'permisos' => $rol->permisos->count(),
                        'fecha_creacion' => $rol->fecha_registro ?? date('d/m/Y')
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $roles
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error al obtener roles: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener roles'
            ], 500);
        }
    }

    /**
     * Obtener un rol específico
     * GET /api/roles/{id}
     */
    public function show($id)
    {
        try {
            $rol = Rol::with(['usuarios', 'permisos'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $rol->id_rol,
                    'nombre' => $rol->nombre,
                    'nivel' => $rol->nivel,
                    'nivel_texto' => $rol->nivel_texto,
                    'descripcion' => $rol->descripcion,
                    'usuarios' => $rol->usuarios->count(),
                    'permisos' => $rol->permisos->map(function($permiso) {
                        return [
                            'id' => $permiso->id_permiso,
                            'nombre' => $permiso->nombre,
                            'modulo' => $permiso->modulo,
                            'descripcion' => $permiso->descripcion
                        ];
                    })
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        }
    }

    /**
     * Crear un nuevo rol
     * POST /api/roles
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'nombre' => 'required|string|max:50|unique:rol,nombre',
                'nivel' => 'required|integer|min:1|max:10',
                'descripcion' => 'nullable|string',
                'permisos' => 'nullable|array'
            ]);
            
            DB::beginTransaction();
            
            // Crear el rol
            $rol = Rol::create([
                'nombre' => $request->nombre,
                'nivel' => $request->nivel,
                'descripcion' => $request->descripcion ?? ''
            ]);
            
            // Asignar permisos si existen
            if ($request->has('permisos') && is_array($request->permisos)) {
                $permisosData = [];
                foreach ($request->permisos as $permisoId) {
                    $permisosData[$permisoId] = ['permitido' => 1];
                }
                $rol->permisos()->attach($permisosData);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Rol creado exitosamente',
                'data' => $rol
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear rol: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un rol
     * PUT /api/roles/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $rol = Rol::findOrFail($id);
            
            $request->validate([
                'nombre' => 'required|string|max:50|unique:rol,nombre,' . $id . ',id_rol',
                'nivel' => 'required|integer|min:1|max:10',
                'descripcion' => 'nullable|string',
                'permisos' => 'nullable|array'
            ]);
            
            DB::beginTransaction();
            
            // Actualizar el rol
            $rol->update([
                'nombre' => $request->nombre,
                'nivel' => $request->nivel,
                'descripcion' => $request->descripcion ?? ''
            ]);
            
            // Actualizar permisos
            if ($request->has('permisos')) {
                $permisosData = [];
                foreach ($request->permisos as $permisoId) {
                    $permisosData[$permisoId] = ['permitido' => 1];
                }
                $rol->permisos()->sync($permisosData);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Rol actualizado exitosamente',
                'data' => $rol
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar rol: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un rol
     * DELETE /api/roles/{id}
     */
    public function destroy($id)
    {
        try {
            $rol = Rol::findOrFail($id);
            
            // Verificar si tiene usuarios asignados
            if ($rol->usuarios()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el rol porque tiene usuarios asignados'
                ], 400);
            }
            
            // Eliminar relaciones con permisos
            $rol->permisos()->detach();
            
            // Eliminar rol
            $rol->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Rol eliminado exitosamente'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar rol: ' . $e->getMessage()
            ], 500);
        }
    }
}