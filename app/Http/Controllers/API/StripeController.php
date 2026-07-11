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
            Log::info('createCheckoutSession - INICIO');
            Log::info('plan_id: ' . $request->plan_id);
            Log::info('plan_name: ' . $request->plan_name);
            Log::info('price: ' . $request->price);
            Log::info('empresa_id: ' . $request->empresa_id);
            Log::info('customer_email: ' . $request->customer_email);
            
            Stripe::setApiKey(env('STRIPE_SECRET'));
            
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
                'success_url' => env('STRIPE_SUCCESS_URL', 'http://localhost:5173/') . '?session_id={CHECKOUT_SESSION_ID}&payment=success',
                'cancel_url' => env('STRIPE_CANCEL_URL', 'http://localhost:5173/planes'),
                'metadata' => [
                    'plan_id' => $request->plan_id,
                    'plan_name' => $request->plan_name,
                    'empresa_id' => $request->empresa_id ?? 'nueva',
                ],
                'customer_email' => $request->customer_email,
            ]);
            
            Log::info('Sesión creada - session_id: ' . $session->id);
            
            return response()->json([
                'sessionId' => $session->id,
                'url' => $session->url
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en createCheckoutSession: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // 2. Verificar pago y activar suscripción - VERSIÓN CON MÁS LOGS
 public function verifyPayment(Request $request)
{
    try {
        Log::info('=== 🚀 INICIO verifyPayment ===');
        Log::info('📥 Datos recibidos RAW:', $request->all());
        
        // ✅ NORMALIZAR DATOS
        $sessionId = $request->session_id ?? $request->sessionId;
        $empresaId = $request->empresaId ?? $request->empresa_id;
        $planId = $request->planId ?? $request->plan_id;
        
        Log::info('📋 Datos normalizados:', [
            'session_id' => $sessionId,
            'empresa_id' => $empresaId,
            'plan_id' => $planId
        ]);
        
        // ✅ VALIDACIONES BÁSICAS
        if (empty($sessionId)) {
            Log::error('❌ session_id vacío');
            return response()->json(['success' => false, 'error' => 'Session ID requerido'], 422);
        }
        
        if (empty($empresaId)) {
            Log::error('❌ empresa_id vacío');
            return response()->json(['success' => false, 'error' => 'Empresa ID requerido'], 422);
        }
        
        if (empty($planId)) {
            Log::error('❌ plan_id vacío');
            return response()->json(['success' => false, 'error' => 'Plan ID requerido'], 422);
        }
        
        // ✅ VERIFICAR STRIPE KEY
        $stripeKey = env('STRIPE_SECRET');
        Log::info('🔑 Stripe Key:', [
            'configurada' => !empty($stripeKey),
            'longitud' => strlen($stripeKey ?? ''),
            'primeros_caracteres' => substr($stripeKey ?? '', 0, 10) . '...'
        ]);
        
        if (empty($stripeKey)) {
            Log::error('❌ STRIPE_SECRET no configurada en .env');
            return response()->json([
                'success' => false,
                'error' => 'Configuración de Stripe no disponible'
            ], 500);
        }
        
        // ✅ CONFIGURAR STRIPE
        try {
            \Stripe\Stripe::setApiKey($stripeKey);
            Log::info('✅ Stripe configurado correctamente');
        } catch (\Exception $e) {
            Log::error('❌ Error configurando Stripe: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Error configurando Stripe: ' . $e->getMessage()
            ], 500);
        }
        
        // ✅ RECUPERAR SESIÓN DE STRIPE
        try {
            Log::info('🔄 Recuperando sesión de Stripe: ' . $sessionId);
            $session = \Stripe\Checkout\Session::retrieve($sessionId);
            Log::info('✅ Sesión recuperada:', [
                'id' => $session->id,
                'payment_status' => $session->payment_status,
                'customer_email' => $session->customer_email ?? 'null',
                'amount_total' => $session->amount_total ?? 'null',
                'currency' => $session->currency ?? 'null'
            ]);
        } catch (\Exception $e) {
            Log::error('❌ Error recuperando sesión de Stripe: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'error' => 'Error recuperando sesión de Stripe: ' . $e->getMessage()
            ], 500);
        }
        
        // ✅ VERIFICAR ESTADO DEL PAGO
        if ($session->payment_status !== 'paid') {
            Log::warning('⚠️ Pago no completado - status: ' . $session->payment_status);
            return response()->json([
                'success' => false,
                'error' => 'El pago no se ha completado',
                'status' => $session->payment_status
            ], 400);
        }
        
        Log::info('✅ Pago completado exitosamente');
        
        // ✅ MAPEAR PLAN
        $planMap = [
            'free' => 1,
            'profesional' => 2,
            'premium' => 3
        ];
        
        $planKey = strtolower($planId);
        $planIdMapeado = $planMap[$planKey] ?? null;
        
        Log::info('📊 Mapeo de plan:', [
            'plan_key_recibido' => $planKey,
            'plan_id_mapeado' => $planIdMapeado
        ]);
        
        if (!$planIdMapeado) {
            Log::error('❌ Plan no encontrado para key: ' . $planKey);
            return response()->json([
                'success' => false,
                'error' => 'Plan no encontrado: ' . $planKey
            ], 400);
        }
        
        // ✅ PROCESAR EMPRESA
        $empresaId = (int)$empresaId;
        Log::info('🏢 Procesando empresa ID: ' . $empresaId);
        
        // ✅ VERIFICAR CONEXIÓN A BD
        try {
            DB::connection()->getPdo();
            Log::info('✅ Conexión a BD exitosa');
        } catch (\Exception $e) {
            Log::error('❌ Error de conexión a BD: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Error de conexión a base de datos'
            ], 500);
        }
        
        // ✅ VERIFICAR QUE LA EMPRESA EXISTA
        try {
            $empresa = DB::table('empresa')->where('id_empresa', $empresaId)->first();
            Log::info('🏢 Empresa encontrada:', [
                'existe' => $empresa ? 'SÍ' : 'NO',
                'nombre' => $empresa->nombre ?? 'null',
                'plan_actual' => $empresa->id_plan ?? 'null'
            ]);
        } catch (\Exception $e) {
            Log::error('❌ Error consultando empresa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Error consultando empresa: ' . $e->getMessage()
            ], 500);
        }
        
        if (!$empresa) {
            Log::error('❌ Empresa NO encontrada con ID: ' . $empresaId);
            return response()->json([
                'success' => false,
                'error' => 'Empresa no encontrada con ID: ' . $empresaId
            ], 404);
        }
        
        // ✅ ACTUALIZAR EMPRESA
        try {
            DB::beginTransaction();
            Log::info('🔄 Iniciando transacción para actualizar empresa');
            
            $updateData = [
                'plan_activo' => 1,
                'fecha_inicio_plan' => now()->toDateString(),
                'fecha_fin_plan' => now()->addMonth()->toDateString(),
                'id_plan' => $planIdMapeado,
            ];
            
            Log::info('📝 Datos de actualización:', $updateData);
            
            $actualizado = DB::table('empresa')
                ->where('id_empresa', $empresaId)
                ->update($updateData);
            
            Log::info('📊 Filas actualizadas: ' . $actualizado);
            
            if ($actualizado === 0) {
                Log::warning('⚠️ No se actualizó ninguna fila');
            }
            
            DB::commit();
            Log::info('✅ Transacción completada exitosamente');
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('❌ Error en transacción: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'error' => 'Error actualizando empresa: ' . $e->getMessage()
            ], 500);
        }
        
        // ✅ VERIFICAR ACTUALIZACIÓN
        $empresaActualizada = DB::table('empresa')->where('id_empresa', $empresaId)->first();
        Log::info('✅ Verificación final:', [
            'id_plan' => $empresaActualizada->id_plan ?? 'null',
            'plan_activo' => $empresaActualizada->plan_activo ?? 'null',
            'fecha_fin_plan' => $empresaActualizada->fecha_fin_plan ?? 'null'
        ]);
        
        // ✅ RESPONDER EXITO
        return response()->json([
            'success' => true,
            'empresaId' => $empresaId,
            'planId' => $planIdMapeado,
            'message' => 'Suscripción activada correctamente',
            'data' => [
                'plan_activo' => $empresaActualizada->plan_activo ?? 1,
                'fecha_fin_plan' => $empresaActualizada->fecha_fin_plan ?? null,
                'id_plan' => $empresaActualizada->id_plan ?? null
            ]
        ]);
        
    } catch (\Exception $e) {
        Log::error('❌ ERROR GENERAL en verifyPayment:', [
            'mensaje' => $e->getMessage(),
            'archivo' => $e->getFile(),
            'línea' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'error' => 'Error al verificar el pago: ' . $e->getMessage(),
            'line' => $e->getLine(),
            'file' => basename($e->getFile())
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
            
            Log::info('Plan Free activado - empresa_id: ' . $empresaId);
            
            return response()->json([
                'success' => true,
                'empresaId' => $empresaId,
                'message' => 'Plan Free activado por 30 días'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en activateFreePlan: ' . $e->getMessage());
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
                Log::warning('Empresa no encontrada: ' . $empresaId);
                return response()->json([
                    'activo' => false,
                    'mensaje' => 'Empresa no encontrada'
                ], 404);
            }
            
            $hoy = now();
            $fechaFin = $empresa->fecha_fin_plan ? \Carbon\Carbon::parse($empresa->fecha_fin_plan) : null;
            $activo = $empresa->plan_activo == 1 && ($fechaFin && $fechaFin >= $hoy);
            $diasRestantes = $fechaFin ? max(0, $hoy->diffInDays($fechaFin, false)) : 0;
            
            Log::info('Estado suscripción:', [
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
            Log::error('Error en checkSubscription: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            return response()->json([
                'activo' => false,
                'mensaje' => 'Error al verificar suscripción'
            ], 500);
        }
    }
}