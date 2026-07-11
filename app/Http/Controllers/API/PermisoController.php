<?php
// app/Http/Controllers/API/PermisosController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Permiso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PermisoController extends Controller
{
    /**
     * Verificar si el usuario tiene permisos de administración
     */
    private function verificarPermisoAdmin($user)
    {
        // 1. Verificar por nivel de rol
        $nivelUsuario = $user->rol->nivel ?? 0;
        
        // Si el nivel es 8 o superior, tiene acceso total
        if ($nivelUsuario >= 8) {
            Log::info('Acceso concedido por nivel: ' . $nivelUsuario);
            return true;
        }
        
        // 2. Verificar por permisos específicos
        $permisosRequeridos = [
            'gestionar_permisos',
            'administrar_sistema',
            'ver_permisos',
            'gestionar_roles'
        ];
        
        $tienePermiso = $user->rol->permisos()
            ->whereIn('permisos.nombre', $permisosRequeridos)
            ->where('rol_permiso.permitido', 1)
            ->exists();
        
        if ($tienePermiso) {
            Log::info('Acceso concedido por permisos específicos');
            return true;
        }
        
        // 3. Si el usuario es administrador de la empresa
        if (isset($user->es_admin) && $user->es_admin == 1) {
            Log::info('Acceso concedido por ser administrador de empresa');
            return true;
        }
        
        throw new \Exception('No tienes permisos para gestionar permisos del sistema');
    }

    /**
     * Obtener todos los permisos (SOLO DE LA EMPRESA DEL USUARIO)
     * GET /api/permisos
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            // ✅ VERIFICACIÓN DE SEGURIDAD - Aquí va la validación
            try {
                $this->verificarPermisoAdmin($user);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 403);
            }
            
            // ✅ FILTRAR POR EMPRESA
            $permisos = Permiso::where(function($query) use ($user) {
                    $query->where('id_empresa', $user->id_empresa)
                        ->orWhereNull('id_empresa'); // Permisos globales del sistema
                })
                ->orderBy('modulo', 'asc')
                ->orderBy('nombre', 'asc')
                ->get()
                ->map(function ($permiso) {
                    return [
                        'id' => $permiso->id_permiso,
                        'nombre' => $permiso->nombre,
                        'codigo' => $permiso->nombre,
                        'descripcion' => $permiso->descripcion,
                        'id_empresa' => $permiso->id_empresa,
                        'modulo' => $permiso->modulo,
                        'estado' => $permiso->estado ?? 'activo'
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $permisos
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error al obtener permisos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener permisos agrupados por módulo
     * GET /api/permisos/agrupados
     */
    public function agrupados(Request $request)
    {
        try {
            $user = $request->user();
            
            // ✅ VERIFICACIÓN DE SEGURIDAD
            try {
                $this->verificarPermisoAdmin($user);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 403);
            }
            
            $permisos = Permiso::where(function($query) use ($user) {
                    $query->where('id_empresa', $user->id_empresa)
                        ->orWhereNull('id_empresa');
                })
                ->orderBy('modulo', 'asc')
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
            Log::error('Error en agrupados: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un permiso específico
     * GET /api/permisos/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            // ✅ VERIFICACIÓN DE SEGURIDAD
            try {
                $this->verificarPermisoAdmin($user);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 403);
            }
            
            $permiso = Permiso::where(function($query) use ($user) {
                    $query->where('id_empresa', $user->id_empresa)
                        ->orWhereNull('id_empresa');
                })
                ->where('id_permiso', $id)
                ->firstOrFail();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $permiso->id_permiso,
                    'nombre' => $permiso->nombre,
                    'codigo' => $permiso->nombre,
                    'descripcion' => $permiso->descripcion,
                    'modulo' => $permiso->modulo,
                    'id_empresa' => $permiso->id_empresa,
                    'estado' => $permiso->estado ?? 'activo'
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error en show: ' . $e->getMessage());
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
            $user = $request->user();
            
            
            try {
                // Verificar que tenga permisos de escritura
                $nivelUsuario = $user->rol->nivel ?? 0;
                if ($nivelUsuario < 8) {
                    throw new \Exception('Necesitas nivel 8 o superior para crear permisos');
                }
                $this->verificarPermisoAdmin($user);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 403);
            }
            
            $request->validate([
                'nombre' => 'required|string|max:50',
                'codigo' => 'required|string|max:50',
                'descripcion' => 'nullable|string',
                'modulo' => 'required|string|max:50',
                'estado' => 'sometimes|in:activo,inactivo'
            ]);
            
            //  Verificar que no exista duplicado en la empresa
            $existe = Permiso::where('id_empresa', $user->id_empresa)
                ->where('nombre', $request->nombre)
                ->exists();
            
            if ($existe) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe un permiso con este nombre en tu empresa'
                ], 400);
            }
            
            //  Asignar automáticamente la empresa del usuario
            $permiso = Permiso::create([
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion ?? '',
                'modulo' => $request->modulo,
                'id_empresa' => $user->id_empresa,
                'estado' => $request->estado ?? 'activo'
            ]);
            
            Log::info('Permiso creado', [
                'id' => $permiso->id_permiso,
                'empresa' => $user->id_empresa,
                'usuario' => $user->id
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Permiso creado exitosamente',
                'data' => [
                    'id' => $permiso->id_permiso,
                    'nombre' => $permiso->nombre,
                    'codigo' => $permiso->nombre,
                    'descripcion' => $permiso->descripcion,
                    'modulo' => $permiso->modulo,
                    'id_empresa' => $permiso->id_empresa,
                    'estado' => $permiso->estado
                ]
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Error en store: ' . $e->getMessage());
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
            $user = $request->user();
            
            // ✅ VERIFICACIÓN DE SEGURIDAD
            try {
                $nivelUsuario = $user->rol->nivel ?? 0;
                if ($nivelUsuario < 8) {
                    throw new \Exception('Necesitas nivel 8 o superior para actualizar permisos');
                }
                $this->verificarPermisoAdmin($user);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 403);
            }
            
            $permiso = Permiso::where(function($query) use ($user) {
                    $query->where('id_empresa', $user->id_empresa)
                        ->orWhereNull('id_empresa');
                })
                ->where('id_permiso', $id)
                ->firstOrFail();
            
            // ✅ No permitir modificar permisos del sistema (globales)
            if (is_null($permiso->id_empresa)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No puedes modificar permisos del sistema'
                ], 403);
            }
            
            $request->validate([
                'nombre' => 'required|string|max:50',
                'descripcion' => 'nullable|string',
                'modulo' => 'required|string|max:50',
                'estado' => 'sometimes|in:activo,inactivo'
            ]);
            
            // ✅ Verificar nombre duplicado en la empresa
            $existe = Permiso::where('id_empresa', $user->id_empresa)
                ->where('nombre', $request->nombre)
                ->where('id_permiso', '!=', $id)
                ->exists();
            
            if ($existe) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe otro permiso con este nombre en tu empresa'
                ], 400);
            }
            
            $permiso->update([
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion ?? '',
                'modulo' => $request->modulo,
                'estado' => $request->estado ?? $permiso->estado
            ]);
            
            Log::info('Permiso actualizado', [
                'id' => $permiso->id_permiso,
                'usuario' => $user->id
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Permiso actualizado exitosamente',
                'data' => [
                    'id' => $permiso->id_permiso,
                    'nombre' => $permiso->nombre,
                    'codigo' => $permiso->nombre,
                    'descripcion' => $permiso->descripcion,
                    'modulo' => $permiso->modulo,
                    'id_empresa' => $permiso->id_empresa,
                    'estado' => $permiso->estado
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en update: ' . $e->getMessage());
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
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            // ✅ VERIFICACIÓN DE SEGURIDAD
            try {
                $nivelUsuario = $user->rol->nivel ?? 0;
                if ($nivelUsuario < 8) {
                    throw new \Exception('Necesitas nivel 8 o superior para eliminar permisos');
                }
                $this->verificarPermisoAdmin($user);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 403);
            }
            
            $permiso = Permiso::where(function($query) use ($user) {
                    $query->where('id_empresa', $user->id_empresa)
                        ->orWhereNull('id_empresa');
                })
                ->where('id_permiso', $id)
                ->firstOrFail();
            
            // ✅ No permitir eliminar permisos del sistema (globales)
            if (is_null($permiso->id_empresa)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No puedes eliminar permisos del sistema'
                ], 403);
            }
            
            // Verificar si tiene roles asignados
            if ($permiso->roles()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el permiso porque está asignado a roles'
                ], 400);
            }
            
            $permiso->delete();
            
            Log::info('Permiso eliminado', [
                'id' => $permiso->id_permiso,
                'usuario' => $user->id
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Permiso eliminado exitosamente'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en destroy: ' . $e->getMessage());
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
    public function porModulo(Request $request, $modulo)
    {
        try {
            $user = $request->user();
            
            // ✅ VERIFICACIÓN DE SEGURIDAD
            try {
                $this->verificarPermisoAdmin($user);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 403);
            }
            
            $permisos = Permiso::where('modulo', $modulo)
                ->where(function($query) use ($user) {
                    $query->where('id_empresa', $user->id_empresa)
                        ->orWhereNull('id_empresa');
                })
                ->orderBy('nombre', 'asc')
                ->get()
                ->map(function($permiso) {
                    return [
                        'id' => $permiso->id_permiso,
                        'nombre' => $permiso->nombre,
                        'codigo' => $permiso->nombre,
                        'descripcion' => $permiso->descripcion,
                        'modulo' => $permiso->modulo,
                        'id_empresa' => $permiso->id_empresa,
                        'estado' => $permiso->estado
                    ];
                });
            
            return response()->json([
                'success' => true,
                'data' => $permisos
            ]);
        } catch (\Exception $e) {
            Log::error('Error en porModulo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos por módulo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de permisos
     * GET /api/permisos/estadisticas
     */
    public function estadisticas(Request $request)
    {
        try {
            $user = $request->user();
            
            // ✅ VERIFICACIÓN DE SEGURIDAD
            try {
                $this->verificarPermisoAdmin($user);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 403);
            }
            
            $total = Permiso::where('id_empresa', $user->id_empresa)->count();
            $activos = Permiso::where('id_empresa', $user->id_empresa)
                ->where('estado', 'activo')
                ->count();
            $inactivos = Permiso::where('id_empresa', $user->id_empresa)
                ->where('estado', 'inactivo')
                ->count();
            $porModulo = Permiso::where('id_empresa', $user->id_empresa)
                ->select('modulo', DB::raw('count(*) as total'))
                ->groupBy('modulo')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'activos' => $activos,
                    'inactivos' => $inactivos,
                    'por_modulo' => $porModulo
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error en estadisticas: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas: ' . $e->getMessage()
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
            $user = $request->user();
            
            // ✅ VERIFICACIÓN DE SEGURIDAD
            try {
                $nivelUsuario = $user->rol->nivel ?? 0;
                if ($nivelUsuario < 8) {
                    throw new \Exception('Necesitas nivel 8 o superior para crear permisos masivos');
                }
                $this->verificarPermisoAdmin($user);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 403);
            }
            
            $request->validate([
                'permisos' => 'required|array',
                'permisos.*.nombre' => 'required|string|max:50',
                'permisos.*.modulo' => 'required|string|max:50'
            ]);
            
            $creados = 0;
            $errores = [];
            
            DB::beginTransaction();
            
            foreach ($request->permisos as $permisoData) {
                try {
                    // ✅ Verificar duplicado en la empresa
                    $existe = Permiso::where('id_empresa', $user->id_empresa)
                        ->where('nombre', $permisoData['nombre'])
                        ->exists();
                    
                    if ($existe) {
                        $errores[] = [
                            'nombre' => $permisoData['nombre'],
                            'error' => 'Ya existe en tu empresa'
                        ];
                        continue;
                    }
                    
                    // ✅ Asignar empresa
                    Permiso::create([
                        'nombre' => $permisoData['nombre'],
                        'descripcion' => $permisoData['descripcion'] ?? '',
                        'modulo' => $permisoData['modulo'],
                        'id_empresa' => $user->id_empresa,
                        'estado' => $permisoData['estado'] ?? 'activo'
                    ]);
                    $creados++;
                } catch (\Exception $e) {
                    $errores[] = [
                        'nombre' => $permisoData['nombre'],
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "Se crearon {$creados} permisos, " . count($errores) . " errores",
                'data' => [
                    'creados' => $creados,
                    'errores' => $errores
                ]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en storeMasivo: ' . $e->getMessage());
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
            $user = $request->user();
            
            // ✅ VERIFICACIÓN DE SEGURIDAD
            try {
                $nivelUsuario = $user->rol->nivel ?? 0;
                if ($nivelUsuario < 8) {
                    throw new \Exception('Necesitas nivel 8 o superior para eliminar permisos masivos');
                }
                $this->verificarPermisoAdmin($user);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 403);
            }
            
            $request->validate([
                'ids' => 'required|array',
                'ids.*' => 'required|integer'
            ]);
            
            $eliminados = 0;
            $errores = 0;
            
            DB::beginTransaction();
            
            foreach ($request->ids as $id) {
                try {
                    $permiso = Permiso::where('id_permiso', $id)
                        ->where('id_empresa', $user->id_empresa)
                        ->first();
                    
                    if (!$permiso) {
                        $errores++;
                        continue;
                    }
                    
                    // No permitir eliminar permisos globales
                    if (is_null($permiso->id_empresa)) {
                        $errores++;
                        continue;
                    }
                    
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
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "Se eliminaron {$eliminados} permisos, {$errores} no se pudieron eliminar"
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en destroyMasivo: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar permisos masivos: ' . $e->getMessage()
            ], 500);
        }
    }
}