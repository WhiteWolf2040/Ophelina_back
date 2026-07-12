<?php
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
        
        Log::info('=== SHOW ROL ===');
        Log::info('Usuario ID: ' . $user->id);
        Log::info('Empresa ID: ' . $user->id_empresa);
        Log::info('Rol ID: ' . $id);
        
        // Buscar el rol sin cargar relaciones primero
        $rol = Rol::where('id_empresa', $user->id_empresa)
            ->where('id_rol', $id)
            ->first();
        
        if (!$rol) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        }
        
        // Cargar usuarios y permisos por separado
        $usuariosCount = $rol->usuarios()->count();
        
        // Obtener permisos directamente con query builder para evitar problemas de relación
        $permisos = DB::table('rol_permiso')
            ->join('permisos', 'rol_permiso.id_permiso', '=', 'permisos.id_permiso')
            ->where('rol_permiso.id_rol', $rol->id_rol)
            ->where('rol_permiso.id_empresa', $user->id_empresa)
            ->where('rol_permiso.permitido', 1)
            ->select('permisos.id_permiso as id', 'permisos.nombre', 'permisos.modulo', 'permisos.descripcion')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $rol->id_rol,
                'nombre' => $rol->nombre,
                'nivel' => $rol->nivel,
                'nivel_texto' => $rol->nivel_texto,
                'descripcion' => $rol->descripcion,
                'usuarios' => $usuariosCount,
                'permisos' => $permisos
            ]
        ]);
        
    } catch (\Exception $e) {
        Log::error('Error en show: ' . $e->getMessage());
        Log::error('Trace: ' . $e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener el rol: ' . $e->getMessage()
        ], 500);
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
        
        $rol = Rol::create([
            'id_empresa' => $user->id_empresa,
            'nombre' => $request->nombre,
            'nivel' => $request->nivel,
            'descripcion' => $request->descripcion ?? ''
        ]);
        
        $permisosArray = $request->input('permisos', []);
        
        if (!empty($permisosArray)) {
            $permisosValidos = Permiso::where('id_empresa', $user->id_empresa)
                ->whereIn('id_permiso', $permisosArray)
                ->pluck('id_permiso')
                ->toArray();
            
            foreach ($permisosValidos as $permisoId) {
                $rol->permisos()->attach($permisoId, [
                    'permitido' => 1,
                    'id_empresa' => $user->id_empresa
                ]);
            }
        }
        
        DB::commit();
        
        return response()->json([
            'success' => true,
            'message' => 'Rol creado exitosamente',
            'data' => $rol
        ], 201);
        
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error en store: ' . $e->getMessage());
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
        
        Log::info('=== UPDATE ROL ===');
        Log::info('Rol ID: ' . $id);
        Log::info('Empresa ID: ' . $user->id_empresa);
        
        $rol = Rol::where('id_empresa', $user->id_empresa)
            ->where('id_rol', $id)
            ->first();
        
        if (!$rol) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        }
        
        $request->validate([
            'nombre' => 'required|string|max:50',
            'nivel' => 'required|integer|min:1|max:10',
            'descripcion' => 'nullable|string',
            'permisos' => 'nullable|array'
        ]);
        
        // Verificar nombre duplicado
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
        
        // Obtener permisos del request
        $permisosArray = $request->input('permisos', []);
        
        Log::info('Permisos recibidos del frontend:', $permisosArray);
        Log::info('Cantidad de permisos recibidos: ' . count($permisosArray));
        
        // Verificar permisos actuales antes de eliminar
        $permisosActuales = DB::table('rol_permiso')
            ->where('id_rol', $rol->id_rol)
            ->where('id_empresa', $user->id_empresa)
            ->pluck('id_permiso')
            ->toArray();
        
        Log::info('Permisos actuales en BD:', $permisosActuales);
        
        // ELIMINAR todos los permisos actuales
        DB::table('rol_permiso')
            ->where('id_rol', $rol->id_rol)
            ->where('id_empresa', $user->id_empresa)
            ->delete();
        
        Log::info('Permisos eliminados');
        
        // AGREGAR nuevos permisos
        if (!empty($permisosArray)) {
            Log::info('Intentando agregar ' . count($permisosArray) . ' permisos');
            
            // Validar que los permisos existan y pertenezcan a la empresa
            $permisosValidos = Permiso::where('id_empresa', $user->id_empresa)
                ->whereIn('id_permiso', $permisosArray)
                ->pluck('id_permiso')
                ->toArray();
            
            Log::info('Permisos válidos encontrados:', $permisosValidos);
            Log::info('Cantidad de permisos válidos: ' . count($permisosValidos));
            
            // Si hay permisos válidos, insertarlos
            if (!empty($permisosValidos)) {
                foreach ($permisosValidos as $permisoId) {
                    $inserted = DB::table('rol_permiso')->insert([
                        'id_rol' => $rol->id_rol,
                        'id_permiso' => $permisoId,
                        'id_empresa' => $user->id_empresa,
                        'permitido' => 1
                    ]);
                    Log::info('Insertado permiso ID: ' . $permisoId . ' - Resultado: ' . ($inserted ? 'OK' : 'FAIL'));
                }
            } else {
                Log::warning('No se encontraron permisos válidos para insertar');
                Log::warning('Permisos solicitados:', $permisosArray);
            }
        } else {
            Log::info('No hay permisos para agregar (array vacío)');
        }
        
        // Verificar permisos después de la actualización
        $permisosFinales = DB::table('rol_permiso')
            ->where('id_rol', $rol->id_rol)
            ->where('id_empresa', $user->id_empresa)
            ->pluck('id_permiso')
            ->toArray();
        
        Log::info('Permisos finales en BD:', $permisosFinales);
        
        DB::commit();
        
        return response()->json([
            'success' => true,
            'message' => 'Rol actualizado exitosamente',
            'data' => $rol,
            'permisos_recibidos' => $permisosArray,
            'permisos_finales' => $permisosFinales
        ]);
        
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error en update: ' . $e->getMessage());
        Log::error('Trace: ' . $e->getTraceAsString());
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
            Log::error('Error en destroy: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar rol: ' . $e->getMessage()
            ], 500);
        }
    }
}