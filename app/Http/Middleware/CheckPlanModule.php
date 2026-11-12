<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use App\Services\SuscripcionService;
use Closure;
use Illuminate\Http\Request;

class CheckPlanModule
{
    public function handle(Request $request, Closure $next, $module)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'No autenticado'], 401);
        }

        $empresa = Empresa::find($user->id_empresa);

        if (!$empresa) {
            return response()->json(['success' => false, 'message' => 'Empresa no encontrada'], 403);
        }

        $servicio = app(SuscripcionService::class);

        // Suscripción vencida
        $info = $servicio->estado($empresa->fecha_fin_plan, $empresa->plan_activo);
        if (!$info['activo']) {
            return response()->json([
                'success' => false,
                'code'    => 'suscripcion_vencida',
                'message' => 'Tu suscripción no está activa.',
            ], 403);
        }

        // El plan no incluye el módulo
        if (!in_array($module, $servicio->modulosDelPlan($empresa->id_plan), true)) {
            return response()->json([
                'success' => false,
                'code'    => 'plan_insuficiente',
                'message' => 'Tu plan actual no incluye este módulo.',
            ], 403);
        }

        return $next($request);
    }
}