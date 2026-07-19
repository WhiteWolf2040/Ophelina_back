<?php
// app/Http/Middleware/CheckPlanModule.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CheckPlanModule
{
    // Módulos permitidos por plan (usando tus IDs reales)
    private $modulesByPlan = [
        1 => [  // Free Trial
            'home', 'clientes', 'empenos'
        ],
        2=> [  // Profesional
            'home', 'clientes', 'pagos', 'empenos', 'configuracion','reportes','inventario','roles', 'permisos'
        ],
        3=> [  // Empresarial (Premium)
            'home', 'clientes', 'pagos', 'empenos', 'tienda', 'reportes', 'roles', 'permisos', 'configuracion', 'inventario'
        ]
    ];

    public function handle(Request $request, Closure $next, $module)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado'
            ], 401);
        }
        
        // Obtener el plan de la empresa (usando tu tabla empresa)
        $empresa = DB::table('empresa')
            ->where('id_empresa', $user->id_empresa)
            ->first();
        
        $planId = $empresa->id_plan ?? 1;
        
        // Verificar si el módulo está permitido para este plan
        if (!in_array($module, $this->modulesByPlan[$planId] ?? [])) {
            return response()->json([
                'success' => false,
                'message' => 'Tu plan no tiene acceso a este módulo. Actualiza a Empresarial para acceder.',
                'plan_required' => $planId == 3 ? 'Empresarial' : 'Profesional o Empresarial'
            ], 403);
        }
        
        return $next($request);
    }
}