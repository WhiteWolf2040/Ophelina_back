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
     * Obtener todos los roles (SOLO DE LA EMPRESA DEL USUARIO)
     * GET /api/roles
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            // Filtrar roles por la empresa del usuario
            $roles = Rol::where('id_empresa', $user->id_empresa)
                ->with(['usuarios', 'permisos'])
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
     * Obtener un rol específico (VERIFICANDO EMPRESA)
     * GET /api/roles/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            $rol = Rol::where('id_empresa', $user->id_empresa)
                ->with(['usuarios', 'permisos'])
                ->findOrFail($id);
            
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
     * Crear un nuevo rol (ASIGNANDO LA EMPRESA DEL USUARIO)
     * POST /api/roles
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            
            $request->validate([
                'nombre' => 'required|string|max:50',
                'nivel' => 'required|integer|min:1|max:10',
                'descripcion' => 'nullable|string',
                'permisos' => 'nullable|array'
            ]);
            
            // Verificar que el nombre del rol no exista en la misma empresa
            $existe = Rol::where('id_empresa', $user->id_empresa)
                ->where('nombre', $request->nombre)
                ->exists();
                
            if ($existe) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe un rol con este nombre en tu empresa'
                ], 400);
            }
            
            DB::beginTransaction();
            
            // Crear el rol con la empresa del usuario
            $rol = Rol::create([
                'id_empresa' => $user->id_empresa,
                'nombre' => $request->nombre,
                'nivel' => $request->nivel,
                'descripcion' => $request->descripcion ?? ''
            ]);
            
            // Asignar permisos si existen (filtrando por permisos de la misma empresa)
            if ($request->has('permisos') && is_array($request->permisos)) {
                // Verificar que los permisos pertenezcan a la empresa
                $permisosValidos = Permiso::where('id_empresa', $user->id_empresa)
                    ->whereIn('id_permiso', $request->permisos)
                    ->pluck('id_permiso')
                    ->toArray();
                
                $permisosData = [];
                foreach ($permisosValidos as $permisoId) {
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
     * Actualizar un rol (VERIFICANDO EMPRESA)
     * PUT /api/roles/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            $rol = Rol::where('id_empresa', $user->id_empresa)
                ->findOrFail($id);
            
            $request->validate([
                'nombre' => 'required|string|max:50',
                'nivel' => 'required|integer|min:1|max:10',
                'descripcion' => 'nullable|string',
                'permisos' => 'nullable|array'
            ]);
            
            // Verificar que el nombre no esté siendo usado por otro rol en la misma empresa
            $existe = Rol::where('id_empresa', $user->id_empresa)
                ->where('nombre', $request->nombre)
                ->where('id_rol', '!=', $id)
                ->exists();
                
            if ($existe) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe otro rol con este nombre en tu empresa'
                ], 400);
            }
            
            DB::beginTransaction();
            
            // Actualizar el rol
            $rol->update([
                'nombre' => $request->nombre,
                'nivel' => $request->nivel,
                'descripcion' => $request->descripcion ?? ''
            ]);
            
            // Actualizar permisos (solo los de la misma empresa)
            if ($request->has('permisos')) {
                // Verificar que los permisos pertenezcan a la empresa
                $permisosValidos = Permiso::where('id_empresa', $user->id_empresa)
                    ->whereIn('id_permiso', $request->permisos)
                    ->pluck('id_permiso')
                    ->toArray();
                
                $permisosData = [];
                foreach ($permisosValidos as $permisoId) {
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
     * Eliminar un rol (VERIFICANDO EMPRESA)
     * DELETE /api/roles/{id}
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            $rol = Rol::where('id_empresa', $user->id_empresa)
                ->findOrFail($id);
            
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