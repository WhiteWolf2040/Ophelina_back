<?php

namespace App\Services;

use App\Models\Empresa;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;



/**
 * Concentra todo lo que se hace con la suscripción de una empresa.
 * Lo usan StripeController (verifyPayment) y StripeWebhookController,
 * así los dos hacen exactamente lo mismo y no se pisan.
 */
class SuscripcionService
{
    public const DIAS_GRACIA = 1;
    public const DIAS_POR_VENCER = 7;
    public const DIAS_PLAN_FREE = 30;


    public function cliente(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    public function obtener(string $subscriptionId)
    {
        return $this->cliente()->subscriptions->retrieve($subscriptionId);
    }

    /**
     * Fecha en que termina el periodo ya pagado (= próximo cobro).
     * En la API nueva vive en el item; en la vieja, en la suscripción.
     */
    public function periodoFin($subscription): ?Carbon
    {
        $ts = $subscription->items->data[0]->current_period_end
            ?? $subscription->current_period_end
            ?? null;

        return $ts ? Carbon::createFromTimestamp($ts) : null;
    }

    public function periodoInicio($subscription): ?Carbon
{
    $ts = $subscription->items->data[0]->current_period_start
        ?? $subscription->current_period_start
        ?? null;

    return $ts ? Carbon::createFromTimestamp($ts) : null;
}


    /**
     * Liga una suscripción de Stripe a una empresa.
     * Es idempotente: si ya estaba ligada la misma suscripción, no reinicia fechas.
     * Si la empresa tenía OTRA suscripción (cambio de plan), cancela la anterior
     * para que Stripe no cobre dos veces.
     */
    public function vincular(
        Empresa $empresa,
        $planId,
        ?string $customerId,
        string $subscriptionId,
        ?Carbon $periodoFin
    ): void {
        $anterior = $empresa->stripe_subscription_id;
        $esNueva = $anterior !== $subscriptionId;

        $datos = [
            'id_plan' => $planId ?? $empresa->id_plan,
            'plan_activo' => 1,
            'stripe_customer_id' => $customerId ?? $empresa->stripe_customer_id,
            'stripe_subscription_id' => $subscriptionId,
            'fecha_fin_plan' => $periodoFin ?? $empresa->fecha_fin_plan ?? now()->addMonth(),
        ];

        if ($esNueva) {
            $datos['fecha_inicio_plan'] = now();
        }

        // Primero guardamos la nueva y DESPUÉS cancelamos la vieja,
        // para que el aviso "subscription.deleted" de la vieja no encuentre a la empresa.
        $empresa->update($datos);

        if ($esNueva && $anterior) {
            $this->cancelar($anterior);
        }
    }

   /** Renovación mensual: extiende la fecha de fin y reinicia el inicio del periodo. */
public function renovar(Empresa $empresa, ?Carbon $periodoFin, ?Carbon $periodoInicio = null): void
{
    $datos = [
        'plan_activo' => 1,
        'fecha_fin_plan' => $periodoFin ?? now()->addMonth(),
    ];

    if ($periodoInicio) {
        $datos['fecha_inicio_plan'] = $periodoInicio;
    }

    $empresa->update($datos);
}

    public function cancelar(string $subscriptionId): void
    {
        try {
            $this->cliente()->subscriptions->cancel($subscriptionId);
            Log::info('ℹ️ Suscripción anterior cancelada en Stripe: ' . $subscriptionId);
        } catch (\Throwable $e) {
            Log::warning('⚠️ No se pudo cancelar la suscripción anterior ' . $subscriptionId . ': ' . $e->getMessage());
        }
    }

    public function estado($fechaFin, $planActivo): array
{
    $planActivo = (bool) $planActivo;
    $fin = $fechaFin ? Carbon::parse($fechaFin)->startOfDay() : null;

    if ($fin === null) {
        return [
            'estado' => $planActivo ? 'activa' : 'sin_plan',
            'activo' => $planActivo,
            'dias_restantes' => null,
            'fecha_fin' => null,
        ];
    }

    $dias = (int) now()->startOfDay()->diffInDays($fin, false);

    if (!$planActivo || $dias < -self::DIAS_GRACIA) {
        $estado = 'vencida';
    } elseif ($dias < 0) {
        $estado = 'en_gracia';
    } elseif ($dias <= self::DIAS_POR_VENCER) {
        $estado = 'por_vencer';
    } else {
        $estado = 'activa';
    }

    return [
        'estado' => $estado,
        'activo' => $estado !== 'vencida',
        'dias_restantes' => $dias,
        'fecha_fin' => $fin,
    ];
}



/**
 * Tarjeta con la que se cobra (marca y últimos 4). Null si no hay.
 */
public function metodoPago(Empresa $empresa): ?array
{
    try {
        $stripe = $this->cliente();
        $pm = null;

        if ($empresa->stripe_subscription_id) {
            $sub = $stripe->subscriptions->retrieve($empresa->stripe_subscription_id, [
                'expand' => ['default_payment_method'],
            ]);
            $pm = $sub->default_payment_method ?? null;
        }

        if (!$pm && $empresa->stripe_customer_id) {
            $cliente = $stripe->customers->retrieve($empresa->stripe_customer_id, [
                'expand' => ['invoice_settings.default_payment_method'],
            ]);
            $pm = $cliente->invoice_settings->default_payment_method ?? null;

            if (!$pm) {
                $lista = $stripe->paymentMethods->all([
                    'customer' => $empresa->stripe_customer_id,
                    'type' => 'card',
                    'limit' => 1,
                ]);
                $pm = $lista->data[0] ?? null;
            }
        }

        if (!is_object($pm) || empty($pm->card)) {
            return null;
        }

        return [
            'marca' => $pm->card->brand,
            'ultimos4' => $pm->card->last4,
            'exp_mes' => $pm->card->exp_month,
            'exp_anio' => $pm->card->exp_year,
        ];
    } catch (\Throwable $e) {
        Log::warning('⚠️ No se pudo obtener el método de pago: ' . $e->getMessage());
        return null;
    }
}

/**
 * Últimas facturas del cliente (sin borradores).
 */
public function facturas(Empresa $empresa, int $limite = 12): array
{
    try {
        $lista = $this->cliente()->invoices->all([
            'customer' => $empresa->stripe_customer_id,
            'limit' => $limite,
        ]);

        return collect($lista->data)
            ->filter(fn ($f) => $f->status !== 'draft')
            ->map(fn ($f) => [
                'id' => $f->id,
                'numero' => $f->number,
                'monto' => (($f->amount_paid ?? 0) > 0 ? $f->amount_paid : $f->amount_due) / 100,
                'moneda' => strtoupper($f->currency),
                'estado' => $f->status,
                'fecha' => Carbon::createFromTimestamp(
                    $f->status_transitions->paid_at ?? $f->created
                )->toIso8601String(),
                'url' => $f->hosted_invoice_url,
                'pdf' => $f->invoice_pdf,
            ])
            ->values()
            ->all();
    } catch (\Throwable $e) {
        Log::warning('⚠️ No se pudieron listar las facturas: ' . $e->getMessage());
        return [];
    }
}
}