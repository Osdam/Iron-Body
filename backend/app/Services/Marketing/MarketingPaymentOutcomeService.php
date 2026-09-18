<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Observability\ChannelLog;
use App\Services\Payments\PaymentMembershipActivator;
use App\Services\Wompi\PaymentStateMachine as SM;
use Throwable;

/**
 * Del pago al inicio, sin vender dos veces. Cuando una transacción creada por el
 * CRM para un lead (clave `mkt-lead-{lead}-plan-{plan}-…`) llega a un estado
 * final, aquí se decide qué pasa en la conversación:
 *
 *  - APPROVED: el lead queda convertido y la fase en WON; la memoria sabe que el
 *    pago está aprobado; Laravel manda el mensaje de inicio. La membresía la
 *    activa {@see PaymentMembershipActivator} —solo si
 *    la persona ya tiene usuario—; si no lo tiene, NADIE inventa una ficha: se
 *    le explica cómo registrarse en la app —registrarse solo PROPONE el
 *    enlace, nunca lo hace: {@see ApprovedPaymentClaimer}— y una persona
 *    lo revisa.
 *  - DECLINED / VOIDED / ERROR / EXPIRED: la memoria lo sabe y nada más; el
 *    siguiente turno del asesor lo maneja con `context.payment`.
 *
 * Idempotente por transacción y estado (se anota en `metadata.marketing_outcome`)
 * y nunca lanza: un fallo aquí no puede romper el webhook ni la reconciliación.
 */
final class MarketingPaymentOutcomeService
{
    public const REVIEW_PAID_WITHOUT_MEMBER = 'paid_without_member';

    public function __construct(
        private readonly MarketingMessageDispatcher $dispatcher,
        private readonly ConversationMemoryService $memory,
    ) {}

    public function handle(PaymentTransaction $tx): void
    {
        try {
            $this->process($tx);
        } catch (Throwable $e) {
            ChannelLog::error('ultron.payment_outcome.failed', ['transaction_id' => $tx->id, 'exception' => class_basename($e)]);
        }
    }

    private function process(PaymentTransaction $tx): void
    {
        if (! preg_match('/^mkt-lead-(\d+)-plan-(\d+)-/', (string) $tx->idempotency_key, $m)) {
            return;
        }
        $status = (string) $tx->status;
        if (! in_array($status, SM::TERMINAL, true) && $status !== SM::EXPIRED) {
            return;
        }
        $meta = is_array($tx->metadata) ? $tx->metadata : [];
        if (data_get($meta, 'marketing_outcome.status') === $status) {
            return; // ya tratado: una reentrega no repite nada
        }

        $lead = MarketingLead::find((int) $m[1]);
        $conversation = $this->conversationFor($tx, $lead);
        $outcome = $status === SM::APPROVED ? 'approved' : ($status === SM::EXPIRED ? 'expired' : 'declined');

        if ($conversation !== null) {
            $this->memory->recordPaymentOutcome($conversation, $outcome, (string) $tx->reference);
        }

        if ($status === SM::APPROVED && $lead !== null) {
            $this->onApproved($tx, $lead, $conversation);
        }

        // Marca sin disparar observers otra vez.
        $tx->forceFill(['metadata' => $meta + ['marketing_outcome' => ['status' => $status, 'handled_at' => now()->toIso8601String()]]])->saveQuietly();
    }

    private function onApproved(PaymentTransaction $tx, MarketingLead $lead, ?MarketingConversation $conversation): void
    {
        $lead->forceFill(['status' => MarketingLead::STATUS_CONVERTED, 'converted_at' => $lead->converted_at ?? now()])->save();

        $hasUser = $lead->member?->user_id !== null;
        if ($conversation !== null) {
            $conversation->forceFill(['commercial_phase' => CommercialPhaseMachine::WON])->save();
            if (! $hasUser) {
                self::flagStaffReview($conversation, self::REVIEW_PAID_WITHOUT_MEMBER);
            }
        }

        $plan = $tx->plan_id ? Plan::find($tx->plan_id) : null;
        $body = $this->onboardingMessage($lead, $plan, $hasUser);
        $channel = $conversation?->channel ?? $lead->channel ?? 'whatsapp';
        $send = $this->dispatcher->dispatchWhatsapp($lead, (string) $channel, $body, [
            'kind' => 'onboarding', 'origin' => 'ultron', 'reference' => (string) $tx->reference,
        ], MarketingMessage::SENDER_AI);

        ChannelLog::info('ultron.payment_outcome.approved', [
            'transaction_id' => $tx->id, 'lead_id' => $lead->id, 'conversation_id' => $conversation?->id,
            'has_user' => $hasUser, 'sent' => $send['sent'] ?? null, 'dry_run' => $send['dry_run'] ?? null,
        ]);
        if (! $hasUser) {
            ChannelLog::warning('ultron.payment_outcome.paid_without_member', ['transaction_id' => $tx->id, 'lead_id' => $lead->id]);
        }
    }

    /**
     * Alerta interna para el equipo sobre una conversación. Marca la revisión
     * y, cuando hay ids que verificar, los deja en una NOTA interna —que no
     * sale a WhatsApp—: la conversación solo guarda el motivo, que es una
     * columna, no un sitio donde quepa un detalle.
     *
     * Pública y estática porque el reclamo que propone el registro
     * ({@see ApprovedPaymentClaimer}) levanta exactamente esta misma alerta:
     * dos copias de esta escritura se separarían el día que una cambie.
     *
     * @param  array<string,mixed>  $data  ids implicados; vacío = sin nota.
     */
    public static function flagStaffReview(MarketingConversation $conversation, string $reason, array $data = []): void
    {
        $conversation->forceFill([
            'staff_review_pending' => true,
            'staff_review_reason' => $reason,
        ])->save();

        if ($data === []) {
            return;
        }

        app(MarketingConversationNoteService::class)->add($conversation, "[{$reason}] ".json_encode($data), null);
    }

    /**
     * El mensaje lo escribe Laravel: nombre del plan, siguiente paso real y los
     * enlaces oficiales de la app. Sin cifras, sin URL de pago.
     */
    private function onboardingMessage(MarketingLead $lead, ?Plan $plan, bool $hasUser): string
    {
        $nombre = trim((string) $lead->name) !== '' ? ' '.trim(explode(' ', trim((string) $lead->name))[0]) : '';
        $planName = $plan?->name ?? 'tu plan';

        if ($hasUser) {
            return "¡Listo{$nombre}! Tu pago del {$planName} quedó confirmado y tu membresía ya está activa. "
                .'Entra a la app Iron Body Workout con tu cuenta para ver tu plan, reservar clases y llevar tus entrenos. '
                .MobileAppLinks::asLine().' Cuando quieras, te cuento cómo empezar.';
        }

        return "¡Listo{$nombre}! Tu pago del {$planName} quedó confirmado. Para activar tu acceso, descarga la app Iron Body Workout "
            .'y regístrate en la app con tu documento: el equipo enlaza tu pago a tu cuenta y te avisa cuando tu membresía quede activa. '
            .'El equipo ya lo tiene en su lista. '.MobileAppLinks::asLine();
    }

    private function conversationFor(PaymentTransaction $tx, ?MarketingLead $lead): ?MarketingConversation
    {
        $id = data_get($tx->metadata, 'marketing.marketing_conversation_id') ?? data_get($tx->metadata, 'marketing_conversation_id');
        $c = $id ? MarketingConversation::find((int) $id) : null;

        return $c ?? ($lead?->conversations()->latest('id')->first());
    }
}
