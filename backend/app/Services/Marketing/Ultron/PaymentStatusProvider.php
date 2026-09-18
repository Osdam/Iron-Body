<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingLead;
use App\Models\PaymentTransaction;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Observability\ChannelLog;
use App\Services\Wompi\PaymentStateMachine as SM;
use App\Services\Wompi\WompiReconciliationService;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * El estado del pago que el modelo puede afirmar. Sale de las transacciones que
 * el propio CRM creó para este lead (clave de idempotencia lead+plan), nunca de
 * lo que la persona diga ni de una captura. Si dice que pagó y hay una
 * transacción abierta en Wompi, se consulta a Wompi en ese momento: la respuesta
 * es lo que dice la pasarela. Si no hay nada abierto, no hay nada que consultar
 * y la verdad es «no veo un pago iniciado». Sin identidad de socio también vale:
 * la transacción la creó el CRM para este lead, no se adivina por teléfono.
 */
final class PaymentStatusProvider
{
    private const RECLAMO = '/\b(ya (pague|pago|hice el pago|transferi|consigne|lo pague|quedo pago|realice el pago|hice la transferencia)|te (mando|envio|paso|adjunto) (el|la|mi) (comprobante|captura|soporte)|(ahi|aqui) (esta|va|te va) (el pago|el comprobante|la captura)|listo el pago|pago (hecho|realizado|listo)|acabo de pagar|ya esta pago)\b/u';

    /** @return array{state:string,plan_id:?int,expires_at:?string,claimed_by_lead:bool,checked_live:bool,can_regenerate:bool} */
    public function forLead(?MarketingLead $lead, string $inboundText): array
    {
        $claimed = preg_match(self::RECLAMO, SalesAgentDecisionSchema::normalize($inboundText)) === 1;
        $none = ['state' => 'none', 'plan_id' => null, 'expires_at' => null, 'claimed_by_lead' => $claimed, 'checked_live' => false, 'can_regenerate' => true];

        if ($lead === null || ! Schema::hasTable('payment_transactions')) {
            return $none;
        }
        $tx = PaymentTransaction::query()
            ->where('idempotency_key', 'like', 'mkt-lead-'.$lead->id.'-plan-%')
            ->orderByDesc('id')
            ->first();
        if ($tx === null) {
            return $none;
        }

        // «Ya pagué» con una transacción abierta en Wompi: se pregunta a Wompi, no se cree.
        $checked = false;
        if ($claimed && in_array((string) $tx->status, SM::IN_FLIGHT, true) && $tx->wompi_transaction_id) {
            try {
                $tx = WompiReconciliationService::make()->refresh($tx);
                $checked = true;
            } catch (Throwable $e) {
                ChannelLog::warning('ultron.payment_status.refresh_failed', ['transaction_id' => $tx->id, 'exception' => class_basename($e)]);
            }
        }

        $state = $this->state((string) $tx->status);

        return [
            'state' => $state,
            'plan_id' => $tx->plan_id ? (int) $tx->plan_id : null,
            'expires_at' => $tx->expires_at?->toIso8601String(),
            'claimed_by_lead' => $claimed,
            'checked_live' => $checked,
            'can_regenerate' => in_array($state, ['none', 'expired', 'declined'], true),
        ];
    }

    private function state(string $status): string
    {
        return match (true) {
            $status === SM::APPROVED => 'approved',
            $status === SM::EXPIRED => 'expired',
            in_array($status, [SM::DECLINED, SM::VOIDED, SM::ERROR], true) => 'declined',
            default => 'pending',
        };
    }
}
