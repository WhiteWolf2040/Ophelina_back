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

        $usuario = Usuario::with('rol')
            ->where('correo',$request->correo)
            ->where('activo',1)
            ->first();

        if(!$usuario || !Hash::check($request->contrasena,$usuario->contrasena)){
            return response()->json([
                "success"=>false,
                "message"=>"Credenciales incorrectas"
            ],401);
        }

        $token = $usuario->createToken("auth_token")->plainTextToken;

        return response()->json([
            "success"=>true,
            "data"=>[
                "token"=>$token,
                "usuario"=>[
                    "id"=>$usuario->id_usuario,
                    "nombre"=>$usuario->nombre,
                    "correo"=>$usuario->correo,
                    "rol"=>$usuario->rol->nombre ?? null
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

        $usuario = $request->user()->load('rol');

        return response()->json([
            "success"=>true,
            "data"=>[
                "usuario"=>$usuario
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
            "success"=>true,
            "message"=>"Sesión cerrada"
        ]);

    }

}