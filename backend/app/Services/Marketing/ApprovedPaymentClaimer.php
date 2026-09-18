<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Services\Observability\ChannelLog;
use App\Services\Wompi\PaymentStateMachine as SM;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Un prospecto que pagó por el link del CRM sin tener ficha de socio queda con
 * el pago aprobado y sin membresía, porque la membresía vive en el usuario de
 * la app. Cuando alguien se registra con el MISMO número de WhatsApp, esto
 * PROPONE el enlace; no lo hace.
 *
 * La diferencia importa: el registro (`POST /members/register`) no verifica el
 * teléfono —no hay OTP, el token del grupo es compartido y `members.phone` no
 * es único—, así que el número no prueba nada. Enlazar el pago y activar la
 * membresía con esa única "prueba" permitía que cualquiera que conociera el
 * número de quien pagó se registrara con su propio documento y se llevara la
 * membresía ajena. Aquí no se escribe `member_id`/`user_id` en la transacción,
 * no se enlaza el lead y no se activa ninguna membresía: se levanta la alerta
 * interna para el equipo con los ids implicados, se avisa a la persona que
 * pagó por su propio chat, y una persona decide.
 *
 * Solo mira pagos del CRM (`mkt-lead-…`), aprobados, sin socio y recientes.
 * Nunca lanza: registrarse no puede fallar por esto.
 */
final class ApprovedPaymentClaimer
{
    /** Motivo de la alerta interna: hay un registro que dice ser de quien pagó. */
    public const REVIEW_REASON = 'payment_claim_candidate';

    /** Un pago viejo ya no explica un registro de hoy. */
    public const CLAIM_WINDOW_DAYS = 90;

    /** Cuántos pagos coincidentes se proponen por registro (los más recientes). */
    private const MAX_CANDIDATES = 5;

    /**
     * Techo de cordura para los leads que comparten un número. Es alto a
     * propósito: un tope bajo reintroducía a nivel de lead el «tapado» que
     * {@see orphanPaymentsByLead()} evita a nivel de pagos (el sexto lead con
     * ese número dejaba fuera al que pagó). Alcanzarlo se registra.
     */
    private const MAX_LEADS = 50;

    /** Un aviso por lead cada 24 h: avisar no puede convertirse en spam. */
    private const NOTICE_COOLDOWN_HOURS = 24;

    /**
     * El aviso lo escribe Laravel, no el modelo. Dice la verdad de lo que va a
     * pasar (lo revisa el equipo) y le da a la persona que pagó la vía para
     * frenarlo si el registro no fue suyo.
     */
    public const NOTICE = 'Vimos un registro nuevo en la app Iron Body con tu número. Antes de enlazar tu pago a esa cuenta, el equipo lo verifica y te avisa cuando tu membresía quede lista. Si no fuiste tú, respóndenos por este chat.';

    public function __construct(
        private readonly MarketingMessageDispatcher $dispatcher,
    ) {}

    /**
     * Propone —nunca ejecuta— el enlace de los pagos aprobados sin dueño cuyo
     * lead comparte los 10 últimos dígitos del teléfono con este socio.
     *
     * @return list<array{lead_id:int,transaction_ids:list<int>,conversation_id:?int,notice_sent:bool}>
     */
    public function proposeFor(Member $member): array
    {
        $digits = $this->last10($member->phone);
        if ($digits === null) {
            return [];
        }

        // El teléfono del lead es la única pista; sin lead que lo comparta no
        // hay nada que proponer.
        $leads = $this->leadsWithPhoneEndingIn($digits);
        if ($leads === []) {
            return [];
        }

        $proposals = [];
        foreach ($this->orphanPaymentsByLead(array_keys($leads)) as $leadId => $transactionIds) {
            try {
                $lead = $leads[$leadId];
                $conversation = $this->conversationFor($lead);

                // Una alerta por (lead, socio) cada 24 h: el endpoint de registro
                // no tiene throttle y cada llamada repetida apilaba una nota
                // nueva en el hilo de quien pagó. Otro socio distinto con el
                // mismo número sí alerta: eso es justo lo que el equipo debe ver.
                if ($this->proposedRecently($lead, (int) $member->id)) {
                    ChannelLog::info('ultron.payment_claim_candidate.repeated', [
                        'member_id' => $member->id, 'lead_id' => $lead->id,
                    ]);

                    continue;
                }

                if ($conversation !== null) {
                    MarketingPaymentOutcomeService::flagStaffReview($conversation, self::REVIEW_REASON, [
                        'member_id' => $member->id,
                        'transaction_ids' => $transactionIds,
                        'matched_by' => 'phone',
                    ]);
                }
                $this->rememberProposal($lead, (int) $member->id);

                ChannelLog::info('ultron.payment_claim_candidate', [
                    'member_id' => $member->id,
                    'lead_id' => $lead->id,
                    'conversation_id' => $conversation?->id,
                    'transaction_ids' => $transactionIds,
                    'matched_by' => 'phone',
                ]);

                $proposals[] = [
                    'lead_id' => (int) $lead->id,
                    'transaction_ids' => $transactionIds,
                    'conversation_id' => $conversation?->id,
                    'notice_sent' => $this->notice($lead, $conversation),
                ];
            } catch (Throwable $e) {
                // Un lead que falla no puede llevarse a los demás por delante,
                // y desde luego no puede tumbar el registro que lo disparó.
                ChannelLog::warning('ultron.payment_claim_candidate.failed', [
                    'member_id' => $member->id,
                    'lead_id' => $leadId,
                    'exception' => class_basename($e),
                ]);
            }
        }

        return $proposals;
    }

    /**
     * Los leads cuyo teléfono termina en esos 10 dígitos, los más recientes
     * primero y acotados: un número puede repetirse (familias, un mismo
     * teléfono reutilizado) y esto corre en cada registro de la app.
     *
     * El `LIKE` va sobre el teléfono tal cual está guardado, que es como lo
     * deja el canal: dígitos sin separadores, normalizados desde el `wa_id` de
     * WhatsApp (ver ProcessMetaWebhookEvent). La comprobación exacta la vuelve
     * a hacer {@see last10()} sobre lo leído, así que un teléfono con formato
     * raro no se cuela; como mucho, no se encuentra.
     *
     * @return array<int,MarketingLead>
     */
    private function leadsWithPhoneEndingIn(string $digits): array
    {
        $leads = MarketingLead::query()
            ->where('phone', 'like', '%'.$digits)
            ->orderByDesc('id')
            ->limit(self::MAX_LEADS)
            ->get();

        if ($leads->count() >= self::MAX_LEADS) {
            ChannelLog::warning('ultron.payment_claim_candidate.lead_cap_reached', ['cap' => self::MAX_LEADS]);
        }

        $found = [];
        foreach ($leads as $lead) {
            if ($this->last10($lead->phone) === $digits) {
                $found[(int) $lead->id] = $lead;
            }
        }

        return $found;
    }

    /**
     * Pagos aprobados del CRM sin socio y dentro de la ventana, de ESOS leads y
     * solo de ellos, agrupados por el lead que los originó.
     *
     * El tope se aplica aquí, sobre los pagos que ya coinciden con el número, y
     * no sobre la tabla entera: los pagos sin socio se acumulan mientras nadie
     * los revisa, y acotar el barrido en lugar de las coincidencias haría que
     * el pago de otra persona tapara el de quien se está registrando.
     *
     * @param  list<int>  $leadIds  no vacío
     * @return array<int,list<int>>
     */
    private function orphanPaymentsByLead(array $leadIds): array
    {
        $rows = PaymentTransaction::query()
            ->where('status', SM::APPROVED)
            ->whereNull('member_id')
            ->where('created_at', '>=', now()->subDays(self::CLAIM_WINDOW_DAYS))
            ->where(function ($q) use ($leadIds) {
                foreach ($leadIds as $leadId) {
                    // Mismo prefijo que construye WompiPaymentLinkService y que
                    // ya consultan PaymentStatusProvider y UltronCommitService.
                    $q->orWhere('idempotency_key', 'like', 'mkt-lead-'.$leadId.'-plan-%');
                }
            })
            ->orderByDesc('id')
            ->limit(self::MAX_CANDIDATES)
            ->get(['id', 'idempotency_key']);

        $byLead = [];
        foreach ($rows as $tx) {
            if (! preg_match('/^mkt-lead-(\d+)-plan-/', (string) $tx->idempotency_key, $m)) {
                continue;
            }
            $byLead[(int) $m[1]][] = (int) $tx->id;
        }

        foreach ($byLead as $leadId => $ids) {
            sort($ids);
            $byLead[$leadId] = $ids;
        }

        return $byLead;
    }

    /**
     * Avisa al lead por su propio chat, con el mismo despachador que manda el
     * mensaje de inicio. Sale como mensaje de Laravel (sender ai, igual que el
     * onboarding) marcado `origin=system` para distinguirlo de lo que escribe
     * el modelo. Devuelve si este registro produjo aviso.
     */
    private function notice(MarketingLead $lead, ?MarketingConversation $conversation): bool
    {
        $meta = is_array($lead->metadata) ? $lead->metadata : [];
        if ($this->noticedRecently($meta['claim_notice_sent_at'] ?? null)) {
            return false;
        }

        $channel = (string) ($conversation?->channel ?? $lead->channel ?? 'whatsapp');
        $send = $this->dispatcher->dispatchWhatsapp($lead, $channel, self::NOTICE, [
            'kind' => 'claim_notice', 'origin' => 'system',
        ], MarketingMessage::SENDER_AI);

        // La espera de 24 h cuenta desde que HUBO mensaje (entregado o en
        // dry_run). Si el despachador lo bloqueó —do_not_contact, canal no
        // soportado, lead sin teléfono válido— no hubo aviso que espaciar.
        if (! ($send['safe_to_send'] ?? false)) {
            return false;
        }

        $meta['claim_notice_sent_at'] = now()->toIso8601String();
        $lead->forceFill(['metadata' => $meta])->saveQuietly();

        return true;
    }

    /**
     * El hilo donde vive el pago: el de WhatsApp más reciente, que es por donde
     * salió el link y por donde puede salir el aviso. Si el lead solo tiene
     * hilos de otro canal, el más reciente de todos (la alerta interna sigue
     * teniendo dónde caer; el aviso lo decide el despachador).
     */
    private function conversationFor(MarketingLead $lead): ?MarketingConversation
    {
        return $lead->conversations()->where('channel', 'whatsapp')->latest('id')->first()
            ?? $lead->conversations()->latest('id')->first();
    }

    /** ¿Ya se propuso este mismo socio para este lead dentro de la ventana de silencio? */
    private function proposedRecently(MarketingLead $lead, int $memberId): bool
    {
        $meta = is_array($lead->metadata) ? $lead->metadata : [];
        $proposals = is_array($meta['claim_proposals'] ?? null) ? $meta['claim_proposals'] : [];

        return $this->noticedRecently($proposals[(string) $memberId] ?? null);
    }

    /** Anota la propuesta (socio → cuándo), acotado a los últimos 10 socios distintos. */
    private function rememberProposal(MarketingLead $lead, int $memberId): void
    {
        $meta = is_array($lead->metadata) ? $lead->metadata : [];
        $proposals = is_array($meta['claim_proposals'] ?? null) ? $meta['claim_proposals'] : [];
        $proposals[(string) $memberId] = now()->toIso8601String();
        $meta['claim_proposals'] = array_slice($proposals, -10, null, true);
        $lead->forceFill(['metadata' => $meta])->saveQuietly();
    }

    /** ¿Ya se avisó a este lead dentro de la ventana de silencio? */
    private function noticedRecently(mixed $sentAt): bool
    {
        if (! is_string($sentAt) || trim($sentAt) === '') {
            return false;
        }

        try {
            $last = CarbonImmutable::parse($sentAt);
        } catch (Throwable) {
            // Una marca ilegible no puede bloquear el aviso para siempre; se
            // trata como si no existiera y la próxima escritura la corrige.
            return false;
        }

        return $last->greaterThan(now()->subHours(self::NOTICE_COOLDOWN_HOURS));
    }

    /** Los 10 últimos dígitos del teléfono, o null si no hay teléfono utilizable. */
    private function last10(?string $phone): ?string
    {
        $digits = substr(preg_replace('/\D+/', '', (string) $phone) ?? '', -10);

        return strlen($digits) === 10 ? $digits : null;
    }
}
