<?php

namespace App\Http\Resources;

use App\Models\MembershipSubscription;
use App\Models\PaymentTransaction;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Respuesta pública de una suscripción de pago automático. LIMPIA y sin secretos:
 * método enmascarado (marca + últimos 4), sin `payment_source_id` crudo, sin
 * tokens, sin llaves, sin payload de Wompi. Incluye estado, plan, próximo cobro,
 * periodo, info de reintentos y estado del último intento.
 *
 * @property MembershipSubscription $resource
 */
class SubscriptionResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var MembershipSubscription $s */
        $s  = $this->resource;
        $ps = $s->relationLoaded('paymentSource') ? $s->paymentSource : $s->paymentSource;

        $maxRetries = (int) config('wompi.recurring.max_retries', 3);

        $last = $s->last_charge_reference
            ? PaymentTransaction::where('reference', $s->last_charge_reference)->first()
            : null;

        return [
            'id'                   => $s->id,
            'status'               => $s->status,
            'plan'                 => [
                'id'   => $s->plan_id,
                'name' => optional($s->plan)->name,
            ],
            'amount'               => (float) $s->price_snapshot,
            'currency'             => $s->currency,
            'interval_days'        => (int) $s->interval_days,
            'next_charge_at'       => optional($s->next_charge_at)->toIso8601String(),
            'current_period_end'   => optional($s->current_period_end)->toDateString(),
            'cancel_at_period_end' => (bool) $s->cancel_at_period_end,
            'past_due'             => $s->status === MembershipSubscription::STATUS_PAST_DUE,
            'payment_method'       => $ps ? [
                'type'      => $ps->type,
                'brand'     => $ps->card_brand,
                'last_four' => $ps->card_last_four,
                'label'     => trim(($ps->card_brand ?: strtoupper((string) $ps->type))
                    .($ps->card_last_four ? ' •••• '.$ps->card_last_four : '')),
            ] : null,
            'retry'                => [
                'failed_attempts' => (int) $s->failed_attempts,
                'stage'           => (int) $s->retry_stage,
                'max_retries'     => $maxRetries,
            ],
            'last_attempt_status'  => $last?->status,
        ];
    }
}
