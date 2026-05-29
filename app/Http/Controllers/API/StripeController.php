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
                //  CAMBIADO: ahora redirige a /home en lugar de /dashboard
                'success_url' => env('STRIPE_SUCCESS_URL', 'http://localhost:5173/') . '?session_id={CHECKOUT_SESSION_ID}&payment=success',
                'cancel_url' => env('STRIPE_CANCEL_URL', 'http://localhost:5173/planes'),
                'metadata' => [
                    'plan_id' => $request->plan_id,
                    'plan_name' => $request->plan_name,
                    'empresa_id' => $request->empresa_id ?? 'nueva',
                ],
                'customer_email' => $request->customer_email,
            ]);
            
            Log::info(' Sesión creada - session_id: ' . $session->id);
            
            return response()->json([
                'sessionId' => $session->id,
                'url' => $session->url
            ]);
            
        } catch (\Exception $e) {
            Log::error(' Error en createCheckoutSession: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    // 2. Verificar pago y activar suscripción
    public function verifyPayment(Request $request)
    {
        Log::info(' verifyPayment - INICIO');
        Log::info(' DATOS RECIBIDOS:');
        Log::info('   session_id: ' . $request->session_id);
        Log::info('   empresa_id: ' . $request->empresa_id);
        Log::info('   plan_id: ' . $request->plan_id);
        Log::info('   customer_email: ' . $request->customer_email);
        Log::info('   negocio_nombre: ' . $request->negocio_nombre);
        
        try {
            // Configurar Stripe
            Stripe::setApiKey(env('STRIPE_SECRET'));
            Log::info(' Stripe configurado con clave');
            
            // Obtener la sesión de Stripe
            $session = Session::retrieve($request->session_id);
            Log::info(' Sesión recuperada de Stripe:');
            Log::info('   payment_status: ' . $session->payment_status);
            Log::info('   customer_email: ' . $session->customer_email);
            Log::info('   metadata.plan_id: ' . ($session->metadata->plan_id ?? 'null'));
            Log::info('   metadata.empresa_id: ' . ($session->metadata->empresa_id ?? 'null'));
            
            // Verificar que el pago fue exitoso
            if ($session->payment_status !== 'paid') {
                Log::warning(' Pago no completado - status: ' . $session->payment_status);
                return response()->json([
                    'success' => false,
                    'error' => 'El pago no se ha completado'
                ]);
            }
            
            // Mapeo de planes (según tu tabla planes_saas)
            $planMap = [
                'free' => 1,
                'profesional' => 3,
                'premium' => 4
            ];
            
            // Obtener plan_id (primero del request, luego de metadata)
            $planKey = $request->plan_id ?? $session->metadata->plan_id;
            $planId = $planMap[$planKey] ?? null;
            
            Log::info(' MAPEO DE PLAN:');
            Log::info('   plan_key recibido: ' . $planKey);
            Log::info('   plan_id mapeado: ' . ($planId ?? 'null'));
            
            if (!$planId) {
                Log::error(' Plan no encontrado para key: ' . $planKey);
                return response()->json([
                    'success' => false,
                    'error' => 'Plan no encontrado: ' . $planKey
                ], 400);
            }
            
            // Obtener empresa_id
            $empresaId = $request->empresa_id ?? $session->metadata->empresa_id;
            Log::info(' EMPRESA ID: ' . ($empresaId ?? 'null'));
            
            if (!$empresaId || $empresaId === 'nueva') {
                Log::error(' Empresa ID inválido: ' . $empresaId);
                return response()->json([
                    'success' => false,
                    'error' => 'Empresa no identificada. Por favor, inicia sesión nuevamente.'
                ], 400);
            }
            
            DB::beginTransaction();
            Log::info(' Iniciando transacción BD');
            
            // Verificar si la empresa existe
            $empresa = DB::table('empresa')->where('id_empresa', $empresaId)->first();
            Log::info(' Buscando empresa ID ' . $empresaId);
            Log::info('   ¿Empresa existe? ' . ($empresa ? 'SÍ' : 'NO'));
            
            if ($empresa) {
                Log::info('   Datos actuales empresa:');
                Log::info('      nombre: ' . ($empresa->nombre ?? 'null'));
                Log::info('      id_plan actual: ' . ($empresa->id_plan ?? 'null'));
                Log::info('      plan_activo: ' . ($empresa->plan_activo ?? 'null'));
                
                // Actualizar empresa existente
                $updateData = [
                    'plan_activo' => 1,
                    'fecha_inicio_plan' => now(),
                    'fecha_fin_plan' => now()->addMonth(),
                    'id_plan' => $planId,
                ];
                
                Log::info(' Actualizando empresa con:', $updateData);
                
                DB::table('empresa')
                    ->where('id_empresa', $empresaId)
                    ->update($updateData);
                
                Log::info(' Empresa actualizada correctamente');
            } else {
                Log::error('Empresa NO encontrada con ID: ' . $empresaId);
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'error' => 'Empresa no encontrada con ID: ' . $empresaId . '. Por favor, contacta a soporte.'
                ], 404);
            }
            
            DB::commit();
            Log::info(' Transacción completada exitosamente');
            
            // Verificar que se actualizó correctamente
            $empresaActualizada = DB::table('empresa')->where('id_empresa', $empresaId)->first();
            Log::info(' VERIFICACIÓN FINAL:');
            Log::info('   id_plan después de update: ' . ($empresaActualizada->id_plan ?? 'null'));
            Log::info('   plan_activo después de update: ' . ($empresaActualizada->plan_activo ?? 'null'));
            Log::info('   fecha_fin_plan: ' . ($empresaActualizada->fecha_fin_plan ?? 'null'));
            
            return response()->json([
                'success' => true,
                'empresaId' => $empresaId,
                'planId' => $planId,
                'message' => 'Suscripción activada correctamente'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ERROR en verifyPayment:');
            Log::error('   Mensaje: ' . $e->getMessage());
            Log::error('   Archivo: ' . $e->getFile());
            Log::error('   Línea: ' . $e->getLine());
            Log::error('   Trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ], 500);
        }
    }
    
    // 3. Activar plan free
    public function activateFreePlan(Request $request)
    {
        Log::info(' activateFreePlan - INICIO');
        Log::info(' Datos: email=' . $request->email . ', negocio=' . $request->negocio_nombre);
        
        try {
            $empresaId = DB::table('empresa')->insertGetId([
                'nombre' => $request->negocio_nombre,
                'email' => $request->email,
                'telefono' => $request->telefono,
                'plan_activo' => 1,
                'fecha_inicio_plan' => now(),
                'fecha_fin_plan' => now()->addDays(30),
                'id_plan' => 1,
                'fecha_registro' => now(),
                'activo' => 1,
            ]);
            
            Log::info(' Plan Free activado - empresa_id: ' . $empresaId);
            
            return response()->json([
                'success' => true,
                'empresaId' => $empresaId,
                'message' => 'Plan Free activado por 30 días'
            ]);
            
        } catch (\Exception $e) {
            Log::error(' Error en activateFreePlan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // 4. Verificar estado de suscripción
    public function checkSubscription($empresaId)
    {
        Log::info('checkSubscription - empresa_id: ' . $empresaId);
        
        $empresa = DB::table('empresa')
            ->leftJoin('planes_saas', 'empresa.id_plan', '=', 'planes_saas.id_plan')
            ->where('id_empresa', $empresaId)
            ->first();
            
        if (!$empresa) {
            Log::warning('Empresa no encontrada: ' . $empresaId);
            return response()->json(['activo' => false, 'mensaje' => 'Empresa no encontrada']);
        }
        
        $hoy = now();
        $fechaFin = \Carbon\Carbon::parse($empresa->fecha_fin_plan);
        $activo = $empresa->plan_activo == 1 && $fechaFin >= $hoy;
        $diasRestantes = max(0, $hoy->diffInDays($fechaFin, false));
        
        Log::info('Estado suscripción: activo=' . ($activo ? 'SÍ' : 'NO') . ', días_restantes=' . $diasRestantes);
        
        return response()->json([
            'activo' => $activo,
            'fecha_fin_plan' => $empresa->fecha_fin_plan,
            'plan_id' => $empresa->id_plan,
            'plan_nombre' => $empresa->nombre,
            'dias_restantes' => $diasRestantes,
        ]);
    }
}