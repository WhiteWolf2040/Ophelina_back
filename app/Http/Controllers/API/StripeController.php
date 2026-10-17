<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\SuscripcionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;
use Stripe\Stripe;

class StripeController extends Controller
{
    // Días extra después de la fecha de fin antes de marcar "vencida".
    // Da tiempo a que Stripe cobre y avise por webhook.
    

    public function __construct(private SuscripcionService $suscripciones)
    {
    }

    // 1. Crear sesión de checkout (suscripción mensual)
    public function createCheckoutSession(Request $request)
    {
        try {
            Log::info('=== createCheckoutSession INICIO ===', $request->all());

            $successUrl = config('services.stripe.success_url');
            $cancelUrl = config('services.stripe.cancel_url') . '?payment=canceled';

            Stripe::setApiKey(config('services.stripe.secret'));

            $metadata = [
                'plan_id' => $request->plan_id,
                'plan_name' => $request->plan_name,
                'empresa_id' => $request->empresa_id ?? 'nueva',
            ];

            $session = Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'mxn',
                        'product_data' => [
                            'name' => 'Ophelina - Plan ' . $request->plan_name,
                        ],
                        // IMPORTANTE: debe venir en CENTAVOS ($999 MXN = 99900)
                        'unit_amount' => $request->price,
                        'recurring' => ['interval' => 'month'],
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'subscription',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
                // Copia los datos a la suscripción misma: así cada renovación
                // (invoice.paid) puede saber de qué empresa/plan es.
                'subscription_data' => ['metadata' => $metadata],
                'customer_email' => $request->customer_email,
            ]);

            Log::info('✅ Sesión creada - session_id: ' . $session->id);

            return response()->json([
                'sessionId' => $session->id,
                'url' => $session->url,
            ]);
        } catch (\Exception $e) {
            Log::error('❌ Error en createCheckoutSession: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // 2. Verificar pago (cuando el usuario regresa de Stripe)
    public function verifyPayment(Request $request)
    {
        try {
            $sessionId = $request->session_id;
            if (empty($sessionId)) {
                return response()->json(['success' => false, 'message' => 'session_id es requerido'], 400);
            }

            $user = auth()->guard('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado. Por favor, inicia sesión nuevamente.',
                ], 401);
            }

            $empresa = (method_exists($user, 'empresa') ? $user->empresa : null)
                ?? ($user->id_empresa ? Empresa::where('id_empresa', $user->id_empresa)->first() : null);

            if (!$empresa) {
                return response()->json(['success' => false, 'message' => 'Empresa no encontrada'], 404);
            }

            if (empty(config('services.stripe.secret'))) {
                Log::error('❌ STRIPE_SECRET no configurada');
                return response()->json(['success' => false, 'message' => 'Stripe no configurado'], 500);
            }

            $session = $this->suscripciones->cliente()->checkout->sessions->retrieve($sessionId, [
                'expand' => ['subscription'],
            ]);

            if ($session->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'El pago no está completado',
                    'payment_status' => $session->payment_status,
                ], 400);
            }

            // La sesión debe pertenecer a la empresa del usuario
            $metaEmpresa = $session->metadata->empresa_id ?? null;
            if ($metaEmpresa && $metaEmpresa !== 'nueva' && (string) $metaEmpresa !== (string) $empresa->id_empresa) {
                Log::warning('⚠️ verifyPayment: sesión de otra empresa. session=' . $sessionId);
                return response()->json(['success' => false, 'message' => 'Sesión no pertenece a esta empresa'], 403);
            }

            $subscriptionId = $session->subscription->id ?? null;
            if (!$subscriptionId) {
                return response()->json(['success' => false, 'message' => 'La sesión no tiene suscripción'], 400);
            }

            $planId = $session->metadata->plan_id ?? $empresa->id_plan;
            $planName = $session->metadata->plan_name ?? 'Premium';

            // Idempotente: si el webhook (o una visita anterior) ya la ligó, no se tocan las fechas
            $empresa->refresh();
            if ($empresa->stripe_subscription_id !== $subscriptionId) {
                $this->suscripciones->vincular(
                    $empresa,
                    $planId,
                    $session->customer,
                    $subscriptionId,
                    $this->suscripciones->periodoFin($session->subscription)
                );
                $empresa->refresh();
            }

            return response()->json([
                'success' => true,
                'message' => '✅ Pago verificado y plan actualizado',
                'data' => [
                    'plan_id' => $empresa->id_plan,
                    'plan_nombre' => $planName,
                    'fecha_inicio' => $empresa->fecha_inicio_plan,
                    'fecha_fin' => $empresa->fecha_fin_plan,
                    'empresa_id' => $empresa->id_empresa,
                    'plan_activo' => $empresa->plan_activo,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('❌ Error general en verifyPayment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar el pago: ' . $e->getMessage(),
            ], 500);
        }
    }

    // 3. Activar plan free (30 días, no se renueva solo)
    public function activateFreePlan(Request $request)
    {
        try {
            if (empty($request->email)) {
                return response()->json(['success' => false, 'error' => 'El email es requerido'], 422);
            }

            if (empty($request->negocio_nombre)) {
                return response()->json(['success' => false, 'error' => 'El nombre del negocio es requerido'], 422);
            }

            if (DB::table('empresa')->where('email', $request->email)->exists()) {
                return response()->json(['success' => false, 'error' => 'Ya existe una empresa con este email'], 400);
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
                'precio_oro_gramo' => 850.00,
            ]);

            Log::info('✅ Plan Free activado - empresa_id: ' . $empresaId);

            return response()->json([
                'success' => true,
                'empresaId' => $empresaId,
                'message' => 'Plan Free activado por 30 días',
            ]);
        } catch (\Exception $e) {
            Log::error('❌ Error en activateFreePlan: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // 4. Verificar estado de suscripción
    public function checkSubscription($empresaId)
    {
        try {
            $empresa = DB::table('empresa')
                ->leftJoin('planes_saas', 'empresa.id_plan', '=', 'planes_saas.id_plan')
                ->where('empresa.id_empresa', $empresaId)
                ->select('empresa.*', 'planes_saas.nombre as plan_nombre')
                ->first();

            if (!$empresa) {
                return response()->json(['activo' => false, 'mensaje' => 'Empresa no encontrada'], 404);
            }

           $info = $this->suscripciones->estado($empresa->fecha_fin_plan, $empresa->plan_activo == 1);

$mensajes = [
    'activa' => 'Suscripción activa',
    'por_vencer' => 'Suscripción por vencer',
    'en_gracia' => 'Pago pendiente: tu acceso sigue activo unos días más',
    'vencida' => 'Suscripción vencida',
    'sin_plan' => 'Suscripción inactiva',
];

return response()->json([
    'activo' => $info['activo'],
    'estado' => $info['estado'],
    'fecha_fin_plan' => $empresa->fecha_fin_plan,
    'plan_id' => $empresa->id_plan,
    'plan_nombre' => $empresa->plan_nombre ?? 'Sin plan',
    'dias_restantes' => $info['dias_restantes'] === null
        ? null
        : ($info['activo'] ? max(0, $info['dias_restantes']) : 0),
    'plan_activo' => $empresa->plan_activo,
    'mensaje' => $mensajes[$info['estado']] ?? 'Suscripción',
    'suscripcion_indefinida' => $info['fecha_fin'] === null,
]);
        } catch (\Exception $e) {
            Log::error('❌ Error en checkSubscription: ' . $e->getMessage());
            return response()->json(['activo' => false, 'mensaje' => 'Error al verificar suscripción'], 500);
        }
    }
}