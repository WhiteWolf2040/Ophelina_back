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
            
            // ✅ OBTENER Y VERIFICAR LA URL DE ÉXITO
            $successUrl = env('STRIPE_SUCCESS_URL');
            $cancelUrl = env('STRIPE_CANCEL_URL');
            
            Log::info('📌 STRIPE_SUCCESS_URL desde ENV: ' . $successUrl);
            Log::info('📌 STRIPE_CANCEL_URL desde ENV: ' . $cancelUrl);
            
            // ✅ VALIDAR QUE LAS URLS SEAN VÁLIDAS
            if (empty($successUrl) || !filter_var($successUrl, FILTER_VALIDATE_URL)) {
                Log::error('❌ STRIPE_SUCCESS_URL NO es una URL válida: ' . $successUrl);
                return response()->json([
                    'error' => 'La URL de éxito de Stripe no es válida',
                    'url' => $successUrl
                ], 500);
            }
            
            if (empty($cancelUrl) || !filter_var($cancelUrl, FILTER_VALIDATE_URL)) {
                Log::error('❌ STRIPE_CANCEL_URL NO es una URL válida: ' . $cancelUrl);
                return response()->json([
                    'error' => 'La URL de cancelación de Stripe no es válida',
                    'url' => $cancelUrl
                ], 500);
            }
            
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
public function verifyPayment(Request $request)
{
    try {
        // ✅ SIEMPRE DEVOLVER JSON CON LOS HEADERS CORRECTOS
        $response = response()->json([
            'success' => false,
            'message' => 'Error desconocido'
        ], 500);
        
        // ✅ VALIDAR SESSION_ID
        $sessionId = $request->session_id;
        if (empty($sessionId)) {
            Log::error('❌ session_id vacío');
            return response()->json([
                'success' => false,
                'message' => 'session_id es requerido'
            ], 400);
        }
        Log::info('🔍 Verificando pago - session_id: ' . $sessionId);

        // ✅ VALIDAR STRIPE_SECRET
        $stripeSecret = env('STRIPE_SECRET');
        if (empty($stripeSecret)) {
            Log::error('❌ STRIPE_SECRET no configurada en el entorno');
            return response()->json([
                'success' => false,
                'message' => 'STRIPE_SECRET no configurada'
            ], 500);
        }

        // ✅ CONFIGURAR STRIPE
        try {
            $stripe = new \Stripe\StripeClient($stripeSecret);
            $session = $stripe->checkout->sessions->retrieve($sessionId);
        } catch (\Exception $e) {
            Log::error('❌ Error al recuperar sesión de Stripe: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al recuperar sesión de Stripe: ' . $e->getMessage()
            ], 500);
        }
        
        Log::info('📊 Estado del pago: ' . $session->payment_status);

        if ($session->payment_status === 'paid') {
            // ✅ ACTUALIZAR EL PLAN DEL USUARIO
            $user = $request->user();
            
            if (!$user) {
                Log::error('❌ Usuario no autenticado');
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }
            
            $empresa = $user->empresa;
            
            if (!$empresa) {
                Log::error('❌ Empresa no encontrada para el usuario: ' . $user->id);
                return response()->json([
                    'success' => false,
                    'message' => 'Empresa no encontrada'
                ], 404);
            }
            
            // Obtener el plan desde los metadatos
            $planId = $session->metadata->plan_id ?? 3;
            $planName = $session->metadata->plan_name ?? 'Premium';
            
            Log::info('📝 Actualizando plan - empresa_id: ' . $empresa->id_empresa . ', plan_id: ' . $planId);
            
            try {
                $empresa->id_plan = $planId;
                $empresa->plan_activo = 1;
                $empresa->fecha_inicio_plan = now();
                $empresa->fecha_fin_plan = now()->addMonth();
                $empresa->save();
            } catch (\Exception $e) {
                Log::error('❌ Error al guardar empresa: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Error al actualizar el plan: ' . $e->getMessage()
                ], 500);
            }
            
            Log::info('✅ Plan actualizado correctamente');
            
            return response()->json([
                'success' => true,
                'message' => 'Pago verificado y plan actualizado',
                'data' => [
                    'plan_id' => $planId,
                    'plan_nombre' => $planName,
                    'fecha_inicio' => $empresa->fecha_inicio_plan,
                    'fecha_fin' => $empresa->fecha_fin_plan
                ]
            ]);
        } else {
            Log::warning('⚠️ Pago no completado - estado: ' . $session->payment_status);
            return response()->json([
                'success' => false,
                'message' => 'El pago no está completado',
                'payment_status' => $session->payment_status
            ], 400);
        }
        
    } catch (\Exception $e) {
        Log::error('❌ Error verificando pago: ' . $e->getMessage());
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
            // Validar datos
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
            
            // Verificar si ya existe
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
            $activo = $empresa->plan_activo == 1 && ($fechaFin && $fechaFin >= $hoy);
            $diasRestantes = $fechaFin ? max(0, $hoy->diffInDays($fechaFin, false)) : 0;
            
            Log::info('✅ Estado suscripción:', [
                'activo' => $activo ? 'SÍ' : 'NO',
                'días_restantes' => $diasRestantes
            ]);
            
            return response()->json([
                'activo' => $activo,
                'fecha_fin_plan' => $empresa->fecha_fin_plan,
                'plan_id' => $empresa->id_plan,
                'plan_nombre' => $empresa->plan_nombre ?? 'Sin plan',
                'dias_restantes' => $diasRestantes,
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