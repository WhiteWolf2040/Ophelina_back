<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupersetController extends Controller
{
    public function token(Request $request)
    {
        // ==== DATOS DE SUPERSET (todo aquí, sin .env) ====
      $supersetUrl = 'http://superset_app:8088';
        $username    = 'admin';
        $password    = 'admin';
        $dashboardId = '885a9524-41c1-4580-99ad-747d3ec48b71';

        try {
            // 1. Iniciar sesión en Superset
            $login = Http::connectTimeout(5)->timeout(15)
                ->post("{$supersetUrl}/api/v1/security/login", [
                    'username' => $username,
                    'password' => $password,
                    'provider' => 'db',
                    'refresh'  => false,
                ]);

            if (!$login->successful()) {
                Log::error('Superset login falló', [
                    'status' => $login->status(),
                    'body'   => $login->body(),
                ]);
                return response()->json(['error' => 'Superset login falló: ' . $login->status()], 502);
            }

            $accessToken = $login->json('access_token');

            if (!$accessToken) {
                return response()->json(['error' => 'Superset no devolvió el access token'], 502);
            }

            // 2. Generar el guest token
            $guest = Http::connectTimeout(5)->timeout(15)
                ->withToken($accessToken)
                ->post("{$supersetUrl}/api/v1/security/guest_token/", [
                    'user' => [
                        'username'   => 'ophelina_' . $request->user()->getKey(),
                        'first_name' => 'Ophelina',
                        'last_name'  => 'Guest',
                    ],
                    'resources' => [
                        ['type' => 'dashboard', 'id' => $dashboardId],
                    ],
                    'rls' => [],
                ]);

            if (!$guest->successful()) {
                Log::error('Superset guest_token falló', [
                    'status' => $guest->status(),
                    'body'   => $guest->body(),
                ]);
                return response()->json(['error' => 'guest_token falló: ' . $guest->status()], 502);
            }

            // 3. Devolver el token a React
            return response()->json(['token' => $guest->json('token')]);

        } catch (ConnectionException $e) {
            Log::error('Superset: no se pudo conectar', ['url' => $supersetUrl, 'msg' => $e->getMessage()]);
            return response()->json(['error' => 'No se pudo conectar con Superset en ' . $supersetUrl], 502);
        }
    }
}