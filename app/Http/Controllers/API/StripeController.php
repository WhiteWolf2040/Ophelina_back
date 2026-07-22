<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripeController extends Controller
{
    // 1. Crear sesión de checkout
    public function createCheckoutSession(Request $request)
{
    try {
        Log::info('=== createCheckoutSession INICIO ===');
        Log::info('📥 Datos recibidos:', $request->all());
        
        // ✅ CONSTRUIR URL CORRECTAMENTE
        $baseSuccessUrl = env('STRIPE_SUCCESS_URL', 'https://ophelina-front.vercel.app/home');
        // Stripe reemplaza {CHECKOUT_SESSION_ID} automáticamente
        $successUrl = $baseSuccessUrl . '?session_id={CHECKOUT_SESSION_ID}&payment=success';
        
        $baseCancelUrl = env('STRIPE_CANCEL_URL', 'https://ophelina-front.vercel.app/planes');
        $cancelUrl = $baseCancelUrl . '?payment=canceled';
        
        Log::info('📌 Success URL final: ' . $successUrl);
        Log::info('📌 Cancel URL final: ' . $cancelUrl);
        
        // ✅ CONFIGURAR STRIPE
        Stripe::setApiKey(env('STRIPE_SECRET'));
        
        // ✅ CREAR SESIÓN
        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'mxn',
                    'product_data' => [
                        'name' => 'Ophelina - Plan ' . $request->plan_name,
                    ],
                    'unit_amount' => $request->price,
                    'recurring' => ['interval' => 'month'],
                ],
                'quantity' => 1,
            ]],
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'plan_id' => $request->plan_id,
                'plan_name' => $request->plan_name,
                'empresa_id' => $request->empresa_id ?? 'nueva',
            ],
            'customer_email' => $request->customer_email,
        ]);
        
        Log::info('✅ Sesión creada - session_id: ' . $session->id);
        
        return response()->json([
            'sessionId' => $session->id,
            'url' => $session->url
        ]);
        
    } catch (\Exception $e) {
        Log::error('❌ Error en createCheckoutSession: ' . $e->getMessage());
        Log::error('Trace: ' . $e->getTraceAsString());
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

    // 2. Verificar pago
    public function verifyPayment(Request $request)
    {
        try {
            Log::info('=== verifyPayment INICIO ===');
            Log::info('📥 Datos recibidos:', $request->all());
            
            $sessionId = $request->session_id;
            if (empty($sessionId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'session_id es requerido'
                ], 400);
            }
            
            // ✅ USAR SANCTUM DIRECTAMENTE (NO 'api')
            $user = auth()->guard('sanctum')->user();
            
            // Si no funciona con 'sanctum', intentar manualmente
            if (!$user) {
                $token = $request->bearerToken();
                Log::info('🔑 Token recibido: ' . ($token ? 'SÍ' : 'NO'));
                
                if ($token) {
                    try {
                        $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
                        if ($tokenModel) {
                            $user = $tokenModel->tokenable;
                            Log::info('👤 Usuario por token Sanctum: ' . ($user ? $user->correo : 'NULL'));
                        }
                    } catch (\Exception $e) {
                        Log::error('❌ Error al validar token: ' . $e->getMessage());
                    }
                }
            }
            
            if (!$user) {
                Log::error('❌ Usuario no autenticado');
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado. Por favor, inicia sesión nuevamente.'
                ], 401);
            }
            
            Log::info('✅ Usuario autenticado:', [
                'id' => $user->id_usuario,
                'email' => $user->correo,
                'id_empresa' => $user->id_empresa
            ]);
            
            // ✅ Verificar empresa
            $empresa = null;
            
            // Método 1: Relación directa
            if (method_exists($user, 'empresa')) {
                $empresa = $user->empresa;
                Log::info('🏢 Empresa por relación: ' . ($empresa ? 'Encontrada' : 'NULL'));
            }
            
            // Método 2: Por id_empresa del usuario
            if (!$empresa && $user->id_empresa) {
                $empresa = \App\Models\Empresa::where('id_empresa', $user->id_empresa)->first();
                Log::info('🏢 Empresa por id_empresa: ' . ($empresa ? 'Encontrada' : 'NULL'));
            }
            
            if (!$empresa) {
                Log::error('❌ Empresa no encontrada');
                return response()->json([
                    'success' => false,
                    'message' => 'Empresa no encontrada',
                    'debug' => [
                        'user_id' => $user->id_usuario,
                        'user_email' => $user->correo,
                        'user_id_empresa' => $user->id_empresa ?? 'N/A'
                    ]
                ], 404);
            }
            
            Log::info('✅ Empresa encontrada:', [
                'id' => $empresa->id_empresa,
                'nombre' => $empresa->nombre,
                'plan_actual' => $empresa->id_plan
            ]);
            
            // ✅ Configurar Stripe
            $stripeSecret = env('STRIPE_SECRET');
            if (empty($stripeSecret)) {
                Log::error('❌ STRIPE_SECRET no configurada');
                return response()->json([
                    'success' => false,
                    'message' => 'STRIPE_SECRET no configurada'
                ], 500);
            }
            
            // ✅ Recuperar sesión de Stripe
            $stripe = new \Stripe\StripeClient($stripeSecret);
            $session = $stripe->checkout->sessions->retrieve($sessionId);
            
            Log::info('📊 Sesión de Stripe:', [
                'payment_status' => $session->payment_status,
                'metadata' => $session->metadata->toArray() ?? []
            ]);
            
            if ($session->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'El pago no está completado',
                    'payment_status' => $session->payment_status
                ], 400);
            }
            
            // ✅ Obtener plan
            $planId = $session->metadata->plan_id ?? 3;
            $planName = $session->metadata->plan_name ?? 'Premium';
            
            Log::info('📝 Plan a actualizar:', [
                'plan_id' => $planId,
                'plan_name' => $planName
            ]);
            
            // ✅ Actualizar empresa
            try {
                $empresa->id_plan = $planId;
                $empresa->plan_activo = 1;
                $empresa->fecha_inicio_plan = now();
                $empresa->fecha_fin_plan = now()->addMonth();
                $empresa->save();
                
                Log::info('✅ Empresa actualizada correctamente');
                
                // Verificar que se guardó
                $verificar = \App\Models\Empresa::find($empresa->id_empresa);
                Log::info('🔍 Verificación post-guardado:', [
                    'id_plan' => $verificar->id_plan,
                    'plan_activo' => $verificar->plan_activo,
                    'fecha_inicio_plan' => $verificar->fecha_inicio_plan,
                    'fecha_fin_plan' => $verificar->fecha_fin_plan
                ]);
                
            } catch (\Exception $e) {
                Log::error('❌ Error al guardar empresa: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error al actualizar el plan: ' . $e->getMessage()
                ], 500);
            }
            
            return response()->json([
                'success' => true,
                'message' => '✅ Pago verificado y plan actualizado',
                'data' => [
                    'plan_id' => $planId,
                    'plan_nombre' => $planName,
                    'fecha_inicio' => $empresa->fecha_inicio_plan,
                    'fecha_fin' => $empresa->fecha_fin_plan,
                    'empresa_id' => $empresa->id_empresa,
                    'plan_activo' => $empresa->plan_activo
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('❌ Error general en verifyPayment: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar el pago: ' . $e->getMessage()
            ], 500);
        }
    }

    // 3. Activar plan free
    public function activateFreePlan(Request $request)
    {
        Log::info('activateFreePlan - INICIO');
        Log::info('Datos: email=' . $request->email . ', negocio=' . $request->negocio_nombre);
        
        try {
            if (empty($request->email)) {
                return response()->json([
                    'success' => false,
                    'error' => 'El email es requerido'
                ], 422);
            }
            
            if (empty($request->negocio_nombre)) {
                return response()->json([
                    'success' => false,
                    'error' => 'El nombre del negocio es requerido'
                ], 422);
            }
            
            $existe = DB::table('empresa')->where('email', $request->email)->exists();
            
            if ($existe) {
                return response()->json([
                    'success' => false,
                    'error' => 'Ya existe una empresa con este email'
                ], 400);
            }
            
            $empresaId = DB::table('empresa')->insertGetId([
                'nombre' => $request->negocio_nombre,
                'email' => $request->email,
                'telefono' => $request->telefono ?? '',
                'plan_activo' => 1,
                'fecha_inicio_plan' => now()->toDateString(),
                'fecha_fin_plan' => now()->addDays(30)->toDateString(),
                'id_plan' => 1,
                'fecha_registro' => now(),
                'activo' => 1,
                'rfc' => 'XAXX010101000',
                'precio_oro_gramo' => 850.00
            ]);
            
            Log::info('✅ Plan Free activado - empresa_id: ' . $empresaId);
            
            return response()->json([
                'success' => true,
                'empresaId' => $empresaId,
                'message' => 'Plan Free activado por 30 días'
            ]);
            
        } catch (\Exception $e) {
            Log::error('❌ Error en activateFreePlan: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // 4. Verificar estado de suscripción
    public function checkSubscription($empresaId)
    {
        try {
            Log::info('checkSubscription - empresa_id: ' . $empresaId);
            
            $empresa = DB::table('empresa')
                ->leftJoin('planes_saas', 'empresa.id_plan', '=', 'planes_saas.id_plan')
                ->where('empresa.id_empresa', $empresaId)
                ->select('empresa.*', 'planes_saas.nombre as plan_nombre')
                ->first();
                
            if (!$empresa) {
                Log::warning('⚠️ Empresa no encontrada: ' . $empresaId);
                return response()->json([
                    'activo' => false,
                    'mensaje' => 'Empresa no encontrada'
                ], 404);
            }
            
            $hoy = now();
            $fechaFin = $empresa->fecha_fin_plan ? \Carbon\Carbon::parse($empresa->fecha_fin_plan) : null;
            
            if ($fechaFin === null) {
                $activo = $empresa->plan_activo == 1;
                $diasRestantes = null;
                $mensaje = $activo ? 'Suscripción activa (sin fecha de vencimiento)' : 'Suscripción inactiva';
            } else {
                $activo = $empresa->plan_activo == 1 && $fechaFin >= $hoy;
                $diasRestantes = $activo ? max(0, $hoy->diffInDays($fechaFin, false)) : 0;
                $mensaje = $activo ? 'Suscripción activa' : 'Suscripción vencida';
            }
            
            Log::info('✅ Estado suscripción:', [
                'activo' => $activo ? 'SÍ' : 'NO',
                'días_restantes' => $diasRestantes ?? 'indefinido',
                'plan_activo' => $empresa->plan_activo,
                'fecha_fin_plan' => $empresa->fecha_fin_plan
            ]);
            
            return response()->json([
                'activo' => $activo,
                'fecha_fin_plan' => $empresa->fecha_fin_plan,
                'plan_id' => $empresa->id_plan,
                'plan_nombre' => $empresa->plan_nombre ?? 'Sin plan',
                'dias_restantes' => $diasRestantes,
                'plan_activo' => $empresa->plan_activo,
                'mensaje' => $mensaje,
                'suscripcion_indefinida' => $fechaFin === null
            ]);
            
        } catch (\Exception $e) {
            Log::error('❌ Error en checkSubscription: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            return response()->json([
                'activo' => false,
                'mensaje' => 'Error al verificar suscripción'
            ], 500);
        }
    }
}