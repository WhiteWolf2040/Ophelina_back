<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;

class MetabaseController extends Controller
{
    public function token(Request $request)
    {
        $payload = [
            'resource' => ['dashboard' => (int) config('services.metabase.dashboard_id')],
            'params'   => ['id_empresa' => $request->user()->id_empresa],
            'exp'      => time() + 600, // 10 minutos
        ];

        $token = JWT::encode($payload, config('services.metabase.secret'), 'HS256');

        return response()->json(['token' => $token]);
    }
}