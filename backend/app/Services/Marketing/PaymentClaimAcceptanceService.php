<?php

namespace App\Services\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Observability\ChannelLog;
use App\Services\Payments\PaymentMembershipActivator;
use App\Services\Wompi\PaymentStateMachine as SM;
use Illuminate\Support\Facades\DB;

/**
 * La decisión HUMANA sobre un pago del CRM que se quedó sin dueño.
 *
 * El otro extremo de {@see ApprovedPaymentClaimer}. Aquel PROPONE —levanta la
 * revisión interna cuando alguien se registra en la app con el mismo número que
 * el prospecto que pagó— y deliberadamente no enlaza nada, porque el registro
 * no verifica el teléfono (sin OTP, token compartido, `members.phone` sin
 * unique) y el número es una pista, no una prueba. Faltaba la otra mitad: sin
 * ninguna acción con la que cerrar la propuesta, quien pagaba y se registraba
 * se quedaba sin membresía y sin forma de conseguirla.
 *
 * Esto es esa acción. La ejecuta una persona del equipo con sesión del Inbox y
 * deja constancia de quién fue: el enlace no se deduce, se firma.
 *
 * INVARIANTES
 *
 *  1. Fail-closed. Cada precondición tiene su código y su estado; ninguna se
 *     resuelve «por si acaso». Ver {@see PaymentClaimException}.
 *  2. Las precondiciones se vuelven a comprobar DENTRO de la transacción, sobre
 *     la fila bloqueada. Dos operadores mirando la misma alerta a la vez es el
 *     caso normal un sábado, no una rareza: sin el bloqueo, ambos verían
 *     `member_id` null y ambos escribirían.
 *  3. La activación de la membresía ocurre FUERA de la transacción. Es
 *     idempotente por `payments.reference` y crea filas propias (pago legado,
 *     notificaciones, factura); meterla dentro alargaría el bloqueo de la
 *     transacción de pasarela hasta cubrir facturación electrónica.
 *  4. Nada de datos personales en el log. Solo identificadores.
 */
class PaymentClaimAcceptanceService
{
    /**
     * Un pago más viejo que esto ya no explica un registro de ahora. Es el
     * doble de la ventana con la que el proponente busca candidatos
     * ({@see ApprovedPaymentClaimer::CLAIM_WINDOW_DAYS}) a propósito: la alerta
     * puede llevar semanas esperando a que alguien la mire, y que caduque
     * mientras espera dejaría el pago sin salida.
     */
    public const CLAIM_WINDOW_DAYS = 180;

    /** Ventana en la que un segundo pago del mismo plan huele a error humano. */
    public const DUPLICATE_WINDOW_DAYS = 7;

    /** El teléfono del socio coincide con el del prospecto que pagó. */
    public const MATCH_PHONE = 'phone';

    /** No coincidía: alguien del equipo respondió por la identidad. */
    public const MATCH_STAFF_OVERRIDE = 'staff_override';

    public const NOTE_ACCEPTED = 'payment_claim_accepted';

    public const NOTE_REJECTED = 'payment_claim_rejected';

    /**
     * Método con el que nace el pago legado. Los links del CRM los emite
     * {@see WompiPaymentLinkService} contra Wompi (`provider='wompi'`), así que
     * el dinero entra por esa pasarela aunque el cliente eligiera Nequi dentro
     * del checkout: `payments.method` dice por dónde entró, no qué botón pulsó.
     */
    private const ACTIVATION_METHOD = 'wompi';

    public function __construct(
        private readonly PaymentMembershipActivator $activator,
        private readonly MarketingStaffReviewService $staffReview,
        private readonly MarketingConversationNoteService $notes,
    ) {}

    /**
     * Enlaza el pago al socio y activa su membresía.
     *
     * @param  string|null  $note  lo que escribió quien decide; va a la nota interna.
     * @param  bool  $force  el teléfono no coincide y el actor responde por ello.
     * @return array<string,mixed>
     *
     * @throws PaymentClaimException
     */
    public function accept(
        PaymentTransaction $tx,
        Member $member,
        Admin $actor,
        ?string $note = null,
        bool $force = false,
    ): array {
        $decision = DB::transaction(function () use ($tx, $member, $actor, $force): array {
            /*
             * El cerrojo de la transacción no basta.
             *
             * El freno anti-duplicado mira los pagos YA enlazados a este socio,
             * así que dos aceptaciones simultáneas sobre transacciones DISTINTAS
             * bloquean filas distintas, ninguna ve la escritura de la otra y las
             * dos activan: doble cobro, doble factura y membresía apilada. Quien
             * manda aquí es el SOCIO, y por él se toma el turno primero.
             */
            Member::query()->whereKey($member->getKey())->lockForUpdate()->first();

            // Se relee bajo bloqueo: lo que trajo el controlador puede estar
            // obsoleto desde hace milisegundos.
            $locked = PaymentTransaction::query()->whereKey($tx->getKey())->lockForUpdate()->first();
            if (! $locked instanceof PaymentTransaction) {
                throw PaymentClaimException::unknownTransaction();
            }

            $lead = $this->assertClaimable($locked, $member, $force);
            $matchedBy = $force && ! $this->phonesMatch($member, $lead)
                ? self::MATCH_STAFF_OVERRIDE
                : self::MATCH_PHONE;

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['claim'] = [
                'accepted_by' => (int) $actor->id,
                'accepted_at' => now()->toIso8601String(),
                'matched_by' => $matchedBy,
                'forced' => $matchedBy === self::MATCH_STAFF_OVERRIDE,
            ];

            // `user_id` se escribe aquí y no se le deja al activador: es el
            // dato que convierte el pago en membresía, y si esa escritura
            // viviera fuera de la transacción podría quedarse a medias.
            $locked->forceFill([
                'member_id' => $member->id,
                'user_id' => $member->user_id,
                'metadata' => $metadata,
            ])->save();

            // El lead pasa a tener ficha de socio si aún no la tenía. No se
            // pisa una existente: eso sería reasignar un prospecto ajeno.
            if ($lead->member_id === null) {
                $lead->forceFill(['member_id' => $member->id])->save();
            }

            return ['tx' => $locked, 'lead' => $lead, 'matched_by' => $matchedBy, 'claim' => $metadata['claim']];
        });

        /** @var PaymentTransaction $fresh la fila ya escrita y comprometida */
        $fresh = $decision['tx'];
        /** @var MarketingLead $lead */
        $lead = $decision['lead'];
        $matchedBy = (string) $decision['matched_by'];
        $forced = (bool) $decision['claim']['forced'];

        // Ya comprometido el enlace: activar es idempotente por referencia y
        // nunca lanza (ver PaymentMembershipActivator::activate), así que un
        // fallo suyo no puede deshacer lo de arriba — se detecta abajo.
        $this->activator->activate($fresh, self::ACTIVATION_METHOD);

        $membership = $this->membershipOf($fresh);
        $conversation = $this->conversationOf($lead);

        $this->close($conversation, $actor, self::NOTE_ACCEPTED, [
            'transaction_id' => (int) $fresh->id,
            'member_id' => (int) $member->id,
            'lead_id' => (int) $lead->id,
            'matched_by' => $matchedBy,
            'forced' => $forced,
            'activated' => $membership['activated'],
            'note' => $this->clean($note),
        ]);

        ChannelLog::info('marketing.payment_claim.accepted', [
            'transaction_id' => (int) $fresh->id,
            'member_id' => (int) $member->id,
            'user_id' => (int) $member->user_id,
            'lead_id' => (int) $lead->id,
            'conversation_id' => $conversation?->id,
            'plan_id' => $fresh->plan_id,
            'admin_id' => (int) $actor->id,
            'matched_by' => $matchedBy,
            'forced' => $forced,
            'activated' => $membership['activated'],
        ]);

        if (! $membership['activated']) {
            // El enlace quedó escrito pero la membresía no: alguien tiene que
            // mirarlo. El activador se traga sus errores por diseño (no puede
            // tumbar un webhook), así que este es el único sitio donde el
            // silencio se convierte en señal.
            ChannelLog::error('marketing.payment_claim.activation_missing', [
                'transaction_id' => (int) $fresh->id,
                'member_id' => (int) $member->id,
                'plan_id' => $fresh->plan_id,
            ]);
        }

        return [
            'transaction_id' => (int) $fresh->id,
            'member_id' => (int) $member->id,
            'user_id' => (int) $member->user_id,
            'lead_id' => (int) $lead->id,
            'conversation_id' => $conversation?->id,
            'matched_by' => $matchedBy,
            'forced' => $forced,
            'accepted_at' => $decision['claim']['accepted_at'],
            'membership' => $membership,
        ];
    }

    /**
     * Descarta la propuesta: cierra la revisión y deja el motivo por escrito.
     * NO toca la transacción — el pago sigue huérfano y reclamable por quien
     * corresponda.
     *
     * @return array<string,mixed>
     *
     * @throws PaymentClaimException
     */
    public function reject(PaymentTransaction $tx, Admin $actor, string $reason): array
    {
        $leadId = $this->leadIdFromKey($tx);
        if ($leadId === null) {
            throw PaymentClaimException::notAMarketingClaim();
        }

        // Rechazar algo que ya se aceptó es una contradicción, y cerrarla en
        // silencio dejaría a quien la pide creyendo que deshizo el enlace.
        if ($tx->member_id !== null) {
            throw PaymentClaimException::alreadyClaimed();
        }

        $lead = MarketingLead::find($leadId);
        if (! $lead instanceof MarketingLead) {
            throw PaymentClaimException::leadNotFound();
        }

        $conversation = $this->conversationOf($lead);

        /*
         * El rechazo se escribe en la TRANSACCIÓN, no solo en la conversación.
         *
         * Sin esto, «este pago no es de esta persona» vivía únicamente en una
         * nota, y aceptar el mismo par acto seguido funcionaba sin que nada
         * avisara. Ahora queda, y {@see assertClaimable()} lo mira.
         */
        $metadata = is_array($tx->metadata) ? $tx->metadata : [];
        $rechazos = is_array($metadata['claim_rejections'] ?? null) ? $metadata['claim_rejections'] : [];
        $rechazos[] = [
            'rejected_by' => (int) $actor->id,
            'rejected_at' => now()->toIso8601String(),
            'reason' => $this->clean($reason),
        ];
        $metadata['claim_rejections'] = array_slice($rechazos, -10);
        $tx->forceFill(['metadata' => $metadata])->saveQuietly();

        $this->close($conversation, $actor, self::NOTE_REJECTED, [
            'transaction_id' => (int) $tx->id,
            'lead_id' => (int) $lead->id,
            'reason' => $this->clean($reason),
        ]);

        ChannelLog::info('marketing.payment_claim.rejected', [
            'transaction_id' => (int) $tx->id,
            'lead_id' => (int) $lead->id,
            'conversation_id' => $conversation?->id,
            'admin_id' => (int) $actor->id,
        ]);

        return [
            'transaction_id' => (int) $tx->id,
            'lead_id' => (int) $lead->id,
            'conversation_id' => $conversation?->id,
            'review_resolved' => $conversation !== null,
        ];
    }

    // ── Precondiciones ────────────────────────────────────────────────────────

    /**
     * Todas las razones por las que este pago NO se enlaza a este socio. Se
     * ejecuta sobre la fila ya bloqueada. Devuelve el lead que lo originó.
     *
     * El orden va de lo más barato y más frecuente a lo más caro: el estado y
     * el dueño son columnas de la propia fila; el duplicado es una consulta.
     *
     * @throws PaymentClaimException
     */
    /**
     * ¿Hay una propuesta viva para este lead?
     *
     * Se mira la marca de revisión con SU motivo, que es lo que el equipo ve en
     * el inbox. No se exige que la nota nombre a este socio concreto: la
     * propuesta puede haberse levantado por otro registro con el mismo número,
     * y esa es justo la ambigüedad que una persona viene a resolver.
     */
    private function hasLiveProposal(MarketingLead $lead): bool
    {
        return $lead->conversations()
            ->where('staff_review_pending', true)
            ->where('staff_review_reason', ApprovedPaymentClaimer::REVIEW_REASON)
            ->exists();
    }

    /** ¿Alguien descartó ya este reclamo? */
    private function wasRejected(PaymentTransaction $tx): bool
    {
        $metadata = is_array($tx->metadata) ? $tx->metadata : [];

        return is_array($metadata['claim_rejections'] ?? null) && $metadata['claim_rejections'] !== [];
    }

    private function assertClaimable(PaymentTransaction $tx, Member $member, bool $force): MarketingLead
    {
        if ($tx->status !== SM::APPROVED) {
            throw PaymentClaimException::notApproved();
        }

        if ($tx->member_id !== null) {
            throw PaymentClaimException::alreadyClaimed();
        }

        $leadId = $this->leadIdFromKey($tx);
        if ($leadId === null) {
            throw PaymentClaimException::notAMarketingClaim();
        }

        if ($tx->plan_id === null) {
            throw PaymentClaimException::withoutPlan();
        }

        if ($tx->created_at === null || $tx->created_at->lt(now()->subDays(self::CLAIM_WINDOW_DAYS))) {
            throw PaymentClaimException::expired(self::CLAIM_WINDOW_DAYS);
        }

        /*
         * Levantar una suspensión es otra decisión del negocio, y no se toma
         * desde un reclamo de pago: el activador pone el socio en `active` sin
         * preguntar, así que aceptar aquí reactivaba a quien estaba suspendido.
         */
        if ($member->status === Member::STATUS_SUSPENDED) {
            throw PaymentClaimException::memberSuspended();
        }

        if (! $member->user_id) {
            throw PaymentClaimException::memberWithoutUser();
        }

        $lead = MarketingLead::find($leadId);
        if (! $lead instanceof MarketingLead) {
            throw PaymentClaimException::leadNotFound();
        }

        /*
         * Aceptar algo que nadie propuso no es aceptar: es otra cosa.
         *
         * La propuesta la levanta ApprovedPaymentClaimer cuando aparece un
         * registro con ese número, y deja la revisión pendiente con su motivo.
         * Sin ella, este endpoint sería una vía para enlazar dinero a mano sin
         * que ningún hecho del CRM lo respalde. El rol pleno puede seguir
         * haciéndolo con `force`, que queda auditado como lo que es.
         */
        if (! $force && ! $this->hasLiveProposal($lead)) {
            throw PaymentClaimException::withoutProposal();
        }

        // Alguien ya decidió que este pago no era de esta persona. Puede
        // revertirse, pero no por descuido: exige responder por ello.
        if (! $force && $this->wasRejected($tx)) {
            throw PaymentClaimException::alreadyRejected();
        }

        if (! $this->phonesMatch($member, $lead) && ! $force) {
            throw PaymentClaimException::phoneMismatch();
        }

        if ($this->hasRecentClaim($tx, $member)) {
            throw PaymentClaimException::recentDuplicate(self::DUPLICATE_WINDOW_DAYS);
        }

        return $lead;
    }

    /**
     * ¿Ya se le enlazó a este socio otro pago del CRM del MISMO plan hace poco?
     *
     * No es una regla de negocio sobre renovaciones —renovar dos veces la misma
     * semana es legítimo desde la app—: es un freno sobre ESTA acción concreta,
     * donde el segundo enlace casi siempre es la misma alerta resuelta dos
     * veces. Se puede confirmar mirando el socio, no adivinando aquí.
     */
    private function hasRecentClaim(PaymentTransaction $tx, Member $member): bool
    {
        return PaymentTransaction::query()
            ->where('member_id', $member->id)
            ->where('plan_id', $tx->plan_id)
            ->where('status', SM::APPROVED)
            ->where('idempotency_key', 'like', 'mkt-lead-%')
            ->where('created_at', '>=', now()->subDays(self::DUPLICATE_WINDOW_DAYS))
            ->whereKeyNot($tx->getKey())
            ->exists();
    }

    /**
     * El id del lead que hay dentro de la clave de idempotencia, o null si la
     * transacción no nació de un link del CRM. Mismo patrón que ya leen
     * MarketingPaymentOutcomeService y ApprovedPaymentClaimer.
     */
    private function leadIdFromKey(PaymentTransaction $tx): ?int
    {
        if (! preg_match('/^mkt-lead-(\d+)-plan-(\d+)-/', (string) $tx->idempotency_key, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * Los 10 últimos dígitos, que es como compara el proponente: el lead trae
     * el número normalizado desde el `wa_id` de WhatsApp (con indicativo) y el
     * socio lo escribe como quiere. Si alguno no da 10 dígitos utilizables, NO
     * coinciden: sin teléfono legible no hay pista que verificar.
     */
    private function phonesMatch(Member $member, MarketingLead $lead): bool
    {
        $socio = $this->last10($member->phone);
        $prospecto = $this->last10($lead->phone);

        return $socio !== null && $socio === $prospecto;
    }

    private function last10(?string $phone): ?string
    {
        $digits = substr(preg_replace('/\D+/', '', (string) $phone) ?? '', -10);

        return strlen($digits) === 10 ? $digits : null;
    }

    // ── Efectos posteriores ───────────────────────────────────────────────────

    /**
     * Qué membresía dejó la activación. `activated` mira el pago legado, que es
     * la fila que de verdad extiende el periodo: el activador es best-effort y
     * un `true` optimista aquí le diría al operador que ya está cuando no lo está.
     *
     * @return array{activated:bool,payment_id:?int,origin:?string,ends_on:?string,period_end:?string}
     */
    private function membershipOf(PaymentTransaction $tx): array
    {
        $payment = Payment::query()->where('reference', $tx->reference)->first();
        $user = $tx->user_id ? User::find($tx->user_id) : null;

        return [
            'activated' => $payment !== null,
            'payment_id' => $payment?->id,
            'origin' => $payment?->origin,
            'ends_on' => $user?->membership_end_date,
            'period_end' => $payment?->period_end?->format('Y-m-d'),
        ];
    }

    /**
     * La conversación donde vive la alerta: la misma que eligió el proponente
     * (la última del lead). Puede no existir —un lead sin conversación— y eso
     * no puede tumbar la aceptación: la auditoría dura vive en
     * `payment_transactions.metadata.claim`, que ya está escrita.
     */
    private function conversationOf(MarketingLead $lead): ?MarketingConversation
    {
        return $lead->conversations()->latest('id')->first();
    }

    /**
     * Cierra la revisión del equipo y deja UNA nota interna con lo decidido.
     *
     * Se reutiliza MarketingStaffReviewService para la marca (es el mismo
     * cierre que el botón del Inbox, incluido `staff_review_resolved_by`) pero
     * la nota se escribe aparte: la suya es texto libre y aquí hace falta un
     * registro con estructura, que se pueda leer meses después.
     *
     * @param  array<string,mixed>  $detail
     */
    private function close(?MarketingConversation $conversation, Admin $actor, string $kind, array $detail): void
    {
        if ($conversation === null) {
            ChannelLog::warning('marketing.payment_claim.conversation_missing', [
                'transaction_id' => $detail['transaction_id'] ?? null,
                'lead_id' => $detail['lead_id'] ?? null,
                'kind' => $kind,
            ]);

            return;
        }

        /*
         * Solo se cierra la revisión SI es la del reclamo.
         *
         * Cerrar cualquiera haría desaparecer, como efecto colateral de una
         * decisión de pago, una alerta de otro motivo —una petición de hablar
         * con una persona, por ejemplo— que nadie ha atendido. La nota se
         * escribe igual: lo que pasó queda, aunque la marca siga en pie.
         */
        if ($conversation->staff_review_pending
            && $conversation->staff_review_reason === ApprovedPaymentClaimer::REVIEW_REASON) {
            $this->staffReview->resolve($conversation, (int) $actor->id, null);
        }

        $this->notes->add(
            $conversation,
            "[{$kind}] ".json_encode(array_filter($detail, fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE),
            (int) $actor->id,
        );
    }

    /** Texto del operador: recortado y vacío → null (no se guarda ruido). */
    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, 500);
    }
}
