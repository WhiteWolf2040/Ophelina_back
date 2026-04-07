<?php
// app/Http/Middleware/CheckPermission.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $permission
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $permission)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado'
            ], 401);
        }
        
        // Verificar si el usuario tiene el permiso
        if (!$this->userHasPermission($user, $permission)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para realizar esta acción'
            ], 403);
        }
        
        return $next($request);
    }
    
    /**
     * Verificar si el usuario tiene un permiso específico
     */
    private function userHasPermission($user, $permission)
    {
        // Obtener el rol del usuario
        $rol = $user->rol;
        
        if (!$rol) {
            return false;
        }
        
        // Verificar si el rol tiene el permiso
        return $rol->permisos()->where('nombre', $permission)->exists();
    }
}