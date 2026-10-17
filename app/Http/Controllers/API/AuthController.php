<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;
use App\Models\Empresa;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\SuscripcionService;

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

        // Obtener módulos únicos de los permisos
        $modulos = $usuario->rol ? $usuario->rol->permisos->pluck('modulo')->unique()->toArray() : [];

        if (empty($modulos)) {
            $modulos = ['home'];
        }

        // Obtener información del plan
        $planId = $usuario->empresa->id_plan ?? 1;

        // Módulos permitidos por plan
        $modulosPorPlan = [
            1 => ['home', 'clientes', 'empenos'],
            2 => ['home', 'clientes', 'pagos', 'empenos', 'configuracion', 'roles', 'permisos', 'inventario'],
            3 => ['home', 'clientes', 'pagos', 'inventario', 'empenos', 'tienda', 'apartados', 'reportes', 'roles', 'permisos', 'configuracion']
        ];

        $modulos = $modulosPorPlan[$planId] ?? $modulosPorPlan[1];

        // Obtener permisos del rol
        $permisos = $usuario->rol ? $usuario->rol->permisos->pluck('nombre')->toArray() : [];

        // Obtener nombre del plan
        $planNombre = 'Free';
        if ($usuario->empresa && $usuario->empresa->plan) {
            $planNombre = $usuario->empresa->plan->nombre;
        } elseif ($planId == 2) {
            $planNombre = 'Profesional';
        } elseif ($planId == 3) {
            $planNombre = 'Premium';
        }

        // ============================================
        // SUSCRIPCIÓN: fechas, días restantes y estado
        // ============================================
        $empresa = $usuario->empresa;

        $fechaInicio = ($empresa && $empresa->fecha_inicio_plan)
            ? Carbon::parse($empresa->fecha_inicio_plan)->startOfDay()
            : null;
            $info = app(SuscripcionService::class)->estado(
                $empresa->fecha_fin_plan ?? null,
                $empresa->plan_activo ?? false
            );

            $fechaFin = $info['fecha_fin'];
            $diasRestantes = $info['dias_restantes'];
            $estadoSuscripcion = $info['estado'];

        $planPrecio = ($empresa && $empresa->plan)
            ? (float) $empresa->plan->precio_mensual
            : 0;

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
                    "modulos" => $modulos,
                    "permisos" => $permisos,
                    "empresa" => $empresa ? [
                        "id" => $empresa->id_empresa,
                        "nombre" => $empresa->nombre,
                        "plan" => $planNombre
                    ] : null,
                    "suscripcion" => [
                        "plan_id"        => $planId,
                        "plan_nombre"    => $planNombre,
                        "precio_mensual" => $planPrecio,
                        "plan_activo"    => $empresa->plan_activo ?? null,
                        "fecha_inicio"   => $fechaInicio ? $fechaInicio->toDateString() : null,
                        "fecha_fin"      => $fechaFin ? $fechaFin->toDateString() : null,
                        "dias_restantes" => $diasRestantes,
                        "estado"         => $estadoSuscripcion,
                    ],
                ]
            ]
        ]);
    }

    /*
    ===============================
    REGISTRO
    ===============================
    */
    public function register(Request $request)
{
    $request->validate([
        'nombre'         => 'required|string|max:100',
        'apellido'       => 'nullable|string|max:100',
        'correo'         => 'required|email|unique:usuario,correo',
        'password'       => 'required|string|min:6',
        'telefono'       => 'nullable|string|max:20',
        'negocio_nombre' => 'required|string|max:255',
        'rfc'            => 'required|string|min:12|max:13|unique:empresa,rfc|regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/i',
        'codigo_postal'  => 'nullable|string|max:10',
        'ciudad'         => 'nullable|string|max:100',
        'estado'         => 'nullable|string|max:100',
        'direccion'      => 'nullable|string|max:255',
        'colonia'        => 'nullable|string|max:100',
    ]);

    try {
        return DB::transaction(function () use ($request) {

            // 1. Crear empresa (UNA SOLA VEZ)
            $empresa = Empresa::create([
                'nombre'            => $request->negocio_nombre,
                'email'             => $request->correo,
                'telefono'          => $request->telefono,
                'rfc'               => strtoupper($request->rfc),
                'direccion'         => $request->direccion,
                'ciudad'            => $request->ciudad,
                'estado'            => $request->estado,
                'codigo_postal'     => $request->codigo_postal,
                'colonia'           => $request->colonia,
                'activo'            => 1,
                'plan_activo'       => 1,
                'id_plan'           => 1,
                'fecha_registro'    => now(),
                'fecha_inicio_plan' => now(),
                'fecha_fin_plan'    => now()->addDays(10),
                'precio_oro_gramo'  => 0,
            ]);

            // 2. Crear usuario admin (¡AQUÍ ESTABA EL BUG!)
            $usuario = Usuario::create([
                'nombre'     => $request->nombre,
                'apellido'   => $request->apellido,
                'correo'     => $request->correo,
                'contrasena' => Hash::make($request->password),
                'telefono'   => $request->telefono,
                'id_empresa' => $empresa->id_empresa,
                'id_rol'     => 1,        // rol admin
                'activo'     => 1,
            ]);

            // 3. Generar token
            $token = $usuario->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'token'   => $token,
                'usuario' => $usuario,
                'empresa' => $empresa,
            ], 201);
        });

    } catch (\Illuminate\Database\QueryException $e) {
        // Errores de BD (unique, FK, etc.)
        if ($e->getCode() === '23505' || $e->getCode() === '23000') {
            return response()->json([
                'success' => false,
                'message' => 'El RFC o correo ya están registrados.',
                'error'   => $e->getMessage(),
            ], 409);
        }
        return response()->json([
            'success' => false,
            'message' => 'Error al registrar.',
            'error'   => $e->getMessage(),
        ], 500);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error inesperado.',
            'error'   => $e->getMessage(),
        ], 500);
    }
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