<?php

namespace App\Services\Marketing;

use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Services\Observability\ChannelLog;
use App\Services\Payments\PaymentMembershipActivator;
use App\Services\Wompi\PaymentStateMachine as SM;
use Throwable;

/**
 * Un prospecto que pagó por el link del CRM sin tener ficha de socio queda con
 * el pago aprobado y sin membresía, porque la membresía vive en el usuario de
 * la app. Cuando esa persona se registra con el MISMO número de WhatsApp, aquí
 * se reclaman sus pagos aprobados y sin dueño: se enlazan a la ficha y al
 * usuario, el lead queda enlazado, y la activación de siempre hace el resto.
 * Solo pagos del CRM (`mkt-lead-…`), solo aprobados, solo sin socio, solo si el
 * número coincide en sus 10 dígitos. Nunca lanza: registrarse no puede fallar
 * por esto.
 */
final class ApprovedPaymentClaimer
{
    public function claimFor(Member $member): int
    {
        if (! $member->user_id) {
            return 0;
        }
        $digits = substr(preg_replace('/\D+/', '', (string) $member->phone) ?? '', -10);
        if (strlen($digits) < 10) {
            return 0;
        }

        $claimed = 0;
        $candidates = PaymentTransaction::query()
            ->where('status', SM::APPROVED)
            ->whereNull('member_id')
            ->where('idempotency_key', 'like', 'mkt-lead-%')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $tx) {
            try {
                if (! preg_match('/^mkt-lead-(\d+)-plan-/', (string) $tx->idempotency_key, $m)) {
                    continue;
                }
                $lead = MarketingLead::find((int) $m[1]);
                $leadDigits = substr(preg_replace('/\D+/', '', (string) ($lead?->phone ?? $tx->customer_phone)) ?? '', -10);
                if ($leadDigits !== $digits) {
                    continue;
                }

                $tx->forceFill(['member_id' => $member->id, 'user_id' => $member->user_id])->saveQuietly();
                if ($lead !== null && $lead->member_id === null) {
                    $lead->forceFill(['member_id' => $member->id])->save();
                }
                app(PaymentMembershipActivator::class)->activate($tx->fresh(), 'wompi');
                $claimed++;
                ChannelLog::info('ultron.payment_claimed_on_registration', ['transaction_id' => $tx->id, 'member_id' => $member->id, 'lead_id' => $lead?->id]);
            } catch (Throwable $e) {
                ChannelLog::error('ultron.payment_claim.failed', ['transaction_id' => $tx->id, 'member_id' => $member->id, 'exception' => class_basename($e)]);
            }
        }

        return $claimed;
    }
}
