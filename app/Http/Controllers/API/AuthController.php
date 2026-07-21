<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{

    /*
    ===============================
    LOGIN
    ===============================
    */

    public function login(Request $request)
    {
        $request->validate([
            'correo' => 'required|email',
            'contrasena' => 'required'
        ]);

        // Cargar relación con rol, empresa y permisos del rol
        $usuario = Usuario::with(['rol', 'rol.permisos', 'empresa'])
            ->where('correo', $request->correo)
            ->where('activo', 1)
            ->first();

        if (!$usuario || !Hash::check($request->contrasena, $usuario->contrasena)) {
            return response()->json([
                "success" => false,
                "message" => "Credenciales incorrectas"
            ], 401);
        }

        // Verificar que la empresa esté activa
        if (!$usuario->empresa || $usuario->empresa->activo != 1) {
            return response()->json([
                "success" => false,
                "message" => "La empresa no está activa"
            ], 401);
        }

        $token = $usuario->createToken("auth_token")->plainTextToken;
        
        // Obtener permisos del rol
        $permisos = $usuario->rol ? $usuario->rol->permisos->pluck('nombre')->toArray() : [];
        
        // Obtener módulos a los que tiene acceso (módulos únicos)
        $modulos = $usuario->rol ? $usuario->rol->permisos->pluck('modulo')->unique()->toArray() : [];

        return response()->json([
            "success" => true,
            "data" => [
                "token" => $token,
                "usuario" => [
                    "id" => $usuario->id_usuario,
                    "nombre" => $usuario->nombre,
                    "correo" => $usuario->correo,
                    "rol" => $usuario->rol->nombre ?? null,
                    "rol_id" => $usuario->id_rol,
                    "id_empresa" => $usuario->id_empresa,
                    "permisos" => $permisos,
                    "modulos" => $modulos,
                    "empresa" => [
                        "id" => $usuario->empresa->id_empresa,
                        "nombre" => $usuario->empresa->nombre,
                        "nombre_comercial" => $usuario->empresa->nombre_comercial,
                        "rfc" => $usuario->empresa->rfc
                    ]
                ]
            ]
        ]);
    }

    /*
    ===============================
    USUARIO ACTUAL
    ===============================
    */
public function user(Request $request)
{
    $usuario = $request->user()->load(['rol', 'rol.permisos', 'empresa.plan']);
    
    // ✅ OBTENER PERMISOS DEL ROL
    $permisos = $usuario->rol ? $usuario->rol->permisos->pluck('nombre')->toArray() : [];
    
    // ✅ OBTENER MÓDULOS ÚNICOS DE LOS PERMISOS (NO SOBREESCRIBIR)
    $modulos = $usuario->rol ? $usuario->rol->permisos->pluck('modulo')->unique()->toArray() : [];
    
    // Si no hay módulos, asignar un conjunto mínimo
    if (empty($modulos)) {
        $modulos = ['home'];
    }
    
    // Obtener información del plan
    $planId = $usuario->empresa->id_plan ?? 1;


    
    // Módulos permitidos por plan
    $modulosPorPlan = [
        1 => ['home', 'clientes', 'empenos'],
        2 => ['home', 'clientes', 'pagos', 'empenos', 'configuracion', 'roles', 'permisos', 'inventario'],
        3 => ['home', 'clientes', 'pagos','inventario', 'empenos', 'tienda', 'reportes', 'roles', 'permisos', 'configuracion']
    ];
    
    $modulos = $modulosPorPlan[$planId] ?? $modulosPorPlan[1];
    
    // Obtener permisos del rol
    $permisosDelRol = $usuario->rol ? $usuario->rol->permisos->pluck('nombre')->toArray() : [];
    
    // Obtener nombre del plan

    $planNombre = 'Free';
    if ($usuario->empresa && $usuario->empresa->plan) {
        $planNombre = $usuario->empresa->plan->nombre;
    } elseif ($planId == 2) {
        $planNombre = 'Profesional';
    } elseif ($planId == 3) {
        $planNombre = 'Premium';
    }
    
    return response()->json([
        "success" => true,
        "data" => [
            "usuario" => [
                "id" => $usuario->id_usuario,
                "nombre" => $usuario->nombre,
                "correo" => $usuario->correo,
                "telefono" => $usuario->telefono,
                "rol" => $usuario->rol->nombre ?? null,
                "rol_id" => $usuario->id_rol,
                "id_empresa" => $usuario->id_empresa,
                "plan_id" => $planId,
                "plan_nombre" => $planNombre,
                "modulos" => $modulos,        //  AHORA USA LOS MÓDULOS REALES
                "permisos" => $permisos,      //  AHORA USA LOS PERMISOS REALES
                "empresa" => $usuario->empresa ? [
                    "id" => $usuario->empresa->id_empresa,
                    "nombre" => $usuario->empresa->nombre,
                    "plan" => $planNombre
                ] : null
            ]
        ]
    ]);
}

    /*
    ===============================
    LOGOUT
    ===============================
    */

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            "success" => true,
            "message" => "Sesión cerrada"
        ]);
    }

}