<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;

class AutenticarApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $llave = $request->bearerToken() ?? $request->header('X-Api-Key');

        $registro = $llave
            ? ApiKey::where('key_hash', hash('sha256', $llave))->where('activo', true)->first()
            : null;

        if (!$registro) {
            return response()->json(['success' => false, 'message' => 'API key inválida'], 401);
        }

        // Evita escribir en la BD en cada petición: máximo 1 vez por minuto
        if (!$registro->ultimo_uso || $registro->ultimo_uso->lt(now()->subMinute())) {
            $registro->forceFill(['ultimo_uso' => now()])->save();
        }

        $request->attributes->set('id_empresa', $registro->id_empresa);

        return $next($request);
    }
}