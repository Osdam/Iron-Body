<?php

namespace App\Services\Outreach\Run\Channels;

use App\Models\MarketingLead;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Marketing\OutreachTemplateMessage;
use App\Services\Outreach\Facts\BatchSnapshotBuilder;
use App\Services\Outreach\Facts\FactSelection;
use App\Services\Outreach\Facts\WhatsappRecipient;
use App\Services\Outreach\Facts\WhatsappSignals;
use App\Services\Outreach\Run\LiveStrategy;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\OutreachSendGate;
use App\Services\Outreach\Run\OutreachTables;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * EL CANAL DE WHATSAPP POR PLANTILLAS (contrato H9, D9-9.3 «W-B», paquete W9;
 * §3 protecciones 2, 4, 5 y 12; D9-8 etapa 7). Ejecuta UN trabajo ya
 * reevaluado y en `dispatching` con UNA petición a la ÚNICA puerta de
 * WhatsApp —`MarketingMessageDispatcher::dispatchWhatsappTemplate`, que crea
 * el mensaje como hoy y lo entrega por el outbox— y nunca nombra a Meta, al
 * outbox ni la red (protección 4). Antes de llamar a la puerta vuelve a
 * mirar, por su cuenta, sus propios cerrojos (la escalera ya los miró en la
 * etapa 6; esto es la red de debajo, como el job de voz del H8):
 *
 *  1. la CONSTANTE de código (`OutreachSendGate::sendsEnabledInCode()`): con
 *     la puerta cerrada no sale nada (`sends_disabled_in_code`, el mismo
 *     código que N4), diga lo que diga lo demás;
 *  2. el trabajo es de este canal y en vivo, la maestra en `live`
 *     (`mode_not_live`), `COMMERCIAL_AUTOMATION_WHATSAPP_ENABLED`
 *     (`channel_disabled`) y la puerta implementada (`channel_not_live`);
 *  3. el cupo y el presupuesto del día reservados al planificar
 *     (`budget_not_reserved`);
 *  4. la plantilla del SELLO, APROBADA en el Studio, de su huella, de la
 *     finalidad de la estrategia y con su categoría (`template_not_approved`);
 *  5. su correspondencia con una plantilla APROBADA por Meta: la última
 *     comprobación de `ultron_studio_template_meta_checks`
 *     (`template_not_approved_by_meta`), el cuarto cerrojo de la protección 12;
 *  6. la persona por sus HECHOS de este instante: resuelta
 *     (`unknown_subject`), con identidad de WhatsApp (`no_whatsapp_identity`)
 *     y con el consentimiento para esa finalidad por WhatsApp
 *     (`consent.whatsapp.<finalidad>` de `Facts`, sin negativas suyas:
 *     `no_consent_for_purpose_channel`);
 *  7. una baja SEGURA o una POSIBLE baja (SC-2:
 *     {@see WhatsappSignals::isPossibleOptOutText()}, «no me llamen», «dejen de
 *     escribirme», también «¿qué es la baja?») que la persona escribió alguna
 *     vez por WhatsApp (`opt_out_word`: no se envía y una persona la revisa;
 *     el despachador lo deja en el libro con ese detalle y
 *     `SubjectSuppressions` lo lee como una baja de la finalidad), o una
 *     respuesta a un contacto anterior de esta
 *     misma inscripción (`reply_pending`) que el normalizador aún no registró:
 *     la secuencia ya no debe seguir (D9-10: «nunca otro contacto automático»);
 *  8. los marcadores, resueltos por Laravel con los hechos de este instante
 *     (`template_marker_unresolvable`), y el texto compuesto pasa otra vez las
 *     restricciones R-01/02/03/06 (`template_violates_restriction`).
 *
 * La pausa del agente es la guarda de la puerta (la misma que hoy detiene
 * los mensajes de la máquina: `agent_paused`); el opt-out de los leads
 * hermanos, `canContactProactively()` y el simulacro con Meta apagado,
 * también. Lo que devuelve:
 *  - `started` con `marketing_message_id` si el mensaje quedó en el outbox
 *    (aceptado por Meta o con su reintento programado: los reintentos son del
 *    outbox; el resultado real lo trae el normalizador de estados de Meta);
 *  - `failed_to_start` con el motivo (un código, nunca el texto del
 *    proveedor) si no salió nada hacia la persona: la reserva se libera;
 *  - `ambiguous` si la puerta lanzó después de crear el mensaje: se concilia
 *    por su correlación (el `uuid` del trabajo), nunca se reenvía.
 *
 * `$sendsEnabledInCode` existe SOLO para que una prueba ensaye la puerta
 * abierta sin girar la constante (como el doble del iniciador de llamadas
 * del H8); por defecto pregunta a {@see OutreachSendGate::sendsEnabledInCode()}.
 */
final class WhatsappTemplateChannel implements OutreachChannel
{
    public const NAME = 'whatsapp_template';

    public const NOT_LIVE = 'channel_not_live';

    /** Con la constante de código cerrada: el mismo código que N4 (`PolicyLadder`, `DecisionTraceText`). */
    public const SENDS_CLOSED = 'sends_disabled_in_code';

    public const NOT_APPROVED_BY_META = 'template_not_approved_by_meta';

    private const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,47}$/D';

    private readonly Closure $sendsEnabledInCode;

    /** @param  (Closure(): bool)|null  $sendsEnabledInCode  el doble de la puerta en las pruebas; null = la constante */
    public function __construct(
        ?Closure $sendsEnabledInCode = null,
        private readonly OutreachPublishedStrategies $bridge = new OutreachPublishedStrategies,
        private readonly WhatsappRecipient $recipients = new WhatsappRecipient,
        private readonly WhatsappSignals $signals = new WhatsappSignals,
        private readonly BatchSnapshotBuilder $snapshots = new BatchSnapshotBuilder,
        private readonly ?MarketingMessageDispatcher $gate = null,
    ) {
        $this->sendsEnabledInCode = $sendsEnabledInCode ?? OutreachSendGate::sendsEnabledInCode(...);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt
    {
        // 1 · La constante de código (protección 2).
        if (($this->sendsEnabledInCode)() !== true) {
            return ChannelAttempt::failed(self::SENDS_CLOSED);
        }
        // 2 · El trabajo, el modo, la bandera y la puerta.
        if ((string) $job->channel !== self::NAME || (string) $job->mode !== OutreachConfig::MODE_LIVE) {
            return ChannelAttempt::failed('job_channel_mismatch');
        }
        if (! OutreachConfig::isLive()) {
            return ChannelAttempt::failed('mode_not_live');
        }
        if (! OutreachConfig::whatsappEnabled()) {
            return ChannelAttempt::failed('channel_disabled');
        }
        if (! OutreachSendGate::channelLive(self::NAME)) {
            return ChannelAttempt::failed(self::NOT_LIVE);
        }
        // 3 · El cupo y el presupuesto, reservados al planificar (etapa 5).
        if ((int) $job->reserved_units < 1) {
            return ChannelAttempt::failed('budget_not_reserved');
        }
        // 4 · La plantilla del sello, aprobada en el Studio.
        $plantilla = $job->template_version_id === null ? null
            : $this->bridge->approvedWhatsappTemplate((int) $job->template_version_id, (string) $job->template_hash, $live->purpose);
        if ($plantilla === null) {
            return ChannelAttempt::failed('template_not_approved');
        }
        // 5 · Aprobada también por Meta (protección 12).
        if (! $this->bridge->metaApproved($plantilla)) {
            return ChannelAttempt::failed(self::NOT_APPROVED_BY_META);
        }
        // 6 · El consentimiento del hecho y el prospecto de WhatsApp.
        $clave = (string) $enrollment->subject_key;
        $persona = $this->personState($clave, $live->purpose, $now);
        if ($persona !== null) {
            return ChannelAttempt::failed($persona);
        }
        $leadId = $this->recipients->leadFor($clave);
        if ($leadId === null) {
            return ChannelAttempt::failed('no_whatsapp_identity');
        }
        // 7 · Una baja (segura o POSIBLE, SC-2) o una respuesta que el normalizador todavía no registró.
        $leads = $this->recipients->leadIdsOf($clave);
        if ($this->signals->everPossiblyOptedOut($leads)) {
            return ChannelAttempt::failed('opt_out_word');
        }
        if ($this->repliedInThisEnrollment($job, $enrollment, $leads)) {
            return ChannelAttempt::failed('reply_pending');
        }
        // 8 · Los marcadores y el texto compuesto.
        $mensaje = $this->compose($plantilla, $clave, $leadId, $now);
        if (is_string($mensaje)) {
            return ChannelAttempt::failed($mensaje);
        }
        $lead = MarketingLead::query()->find($leadId);
        if ($lead === null) {
            return ChannelAttempt::failed('no_whatsapp_identity');
        }

        // LA PUERTA de WhatsApp: una petición.
        try {
            $r = ($this->gate ?? app(MarketingMessageDispatcher::class))->dispatchWhatsappTemplate($lead, $mensaje, (string) $job->uuid);
        } catch (Throwable $e) {
            Log::error('outreach.whatsapp.gate_failed', ['job' => (string) $job->uuid, 'error_class' => $e::class]);

            // Sin mensaje con su correlación no salió nada; con él, no se sabe: se concilia por la correlación, nunca se reenvía.
            return $this->signals->hasOutboundFor((string) $job->uuid) ? ChannelAttempt::ambiguous('gate_error') : ChannelAttempt::failed('gate_error');
        }

        return self::fromGate($r);
    }

    /**
     * Lo que la puerta devolvió, como intento del canal.
     *
     * @param  array<string, mixed>  $r
     */
    public static function fromGate(array $r): ChannelAttempt
    {
        $id = is_int($r['message_id'] ?? null) ? $r['message_id'] : null;
        if ($id === null) {
            // Una guarda de la puerta lo paró antes de crear nada (do_not_contact, no_proactive_consent, agent_paused…).
            return ChannelAttempt::failed(self::code($r['reason'] ?? null, 'gate_refused'));
        }
        if (($r['dry_run'] ?? false) === true) {
            // Meta apagado o sin credenciales: el mensaje quedó como simulacro y no llegó a nadie.
            return ChannelAttempt::failed('meta_disabled_or_unconfigured');
        }
        if (($r['replayed'] ?? false) === true) {
            // El mismo trabajo ya pasó por la puerta (no debería: el despachador lo ejecuta una vez).
            return match (true) {
                ($r['sent'] ?? false) === true, ($r['status'] ?? null) === 'failed' => ChannelAttempt::started(['marketing_message_id' => $id]),
                ($r['status'] ?? null) === 'queued' => ChannelAttempt::ambiguous('already_dispatched'),
                default => ChannelAttempt::failed(self::code($r['status'] ?? null, 'already_dispatched')),
            };
        }
        if (($r['sent'] ?? false) === true || ($r['will_retry'] ?? false) === true) {
            // Aceptado por Meta, o con su reintento programado en el outbox (que es quien reintenta).
            return ChannelAttempt::started(['marketing_message_id' => $id]);
        }
        $error = $r['provider_error_code'] ?? null;

        // Definitivo sin salir: Meta lo rechazó, una persona tomó la conversación o la pausa lo retuvo en el outbox.
        return ChannelAttempt::failed(is_int($error) && $error > 0 ? 'meta_error_'.$error : self::code($r['reason'] ?? null, 'provider_send_failed'));
    }

    /**
     * La persona según los HECHOS de este instante (`Facts`): null si tiene
     * identidad de WhatsApp y el hecho autoriza esta finalidad por WhatsApp
     * (`consent.whatsapp.<finalidad>`) sin ninguna negativa suya; si no, el
     * código por el que no se le escribe.
     */
    private function personState(string $subjectKey, string $purpose, DateTimeImmutable $now): ?string
    {
        $s = $this->snapshots->build([$subjectKey], $now, new FactSelection(['subject.has_whatsapp_identity'], policy: true))->subject($subjectKey);
        if ($s === null || ! $s->resolved()) {
            return 'unknown_subject';
        }
        if ($s->fact('subject.has_whatsapp_identity') !== true) {
            return 'no_whatsapp_identity';
        }

        return $s->policy("consent.whatsapp.{$purpose}") === true
            && $s->policy('lead.consent_denied') !== true
            && $s->policy('lead.do_not_contact') !== true
            && $s->policy('lead.do_not_contact_sibling') !== true ? null : 'no_consent_for_purpose_channel';
    }

    /**
     * ¿Contestó la persona después del primer contacto de WhatsApp de ESTA
     * inscripción? Entonces la secuencia para (`replied`: la conversación
     * sigue con ULTRON o con una persona), aunque el normalizador no lo haya
     * registrado todavía.
     *
     * @param  list<int>  $leadIds
     */
    private function repliedInThisEnrollment(object $job, object $enrollment, array $leadIds): bool
    {
        $previo = DB::table(OutreachTables::JOBS)
            ->where('enrollment_id', (int) $enrollment->id)
            ->where('channel', self::NAME)
            ->whereIn('status', ['started', 'ambiguous'])
            ->where('id', '<>', (int) $job->id)
            ->orderBy('id')
            ->first(['uuid', 'marketing_message_id']);
        if ($previo === null) {
            return false;
        }
        $salida = $this->signals->outboundOf($previo->marketing_message_id !== null ? (int) $previo->marketing_message_id : null, (string) $previo->uuid);

        return $salida !== null && $this->signals->inboundBetween($leadIds, $salida['createdAt'], null, 1) !== [];
    }

    /**
     * La plantilla compuesta para esta persona, o el código por el que no se
     * puede componer.
     *
     * @param  array{id: int, hash: string, content: array<string, mixed>}  $plantilla
     */
    private function compose(array $plantilla, string $subjectKey, int $leadId, DateTimeImmutable $now): OutreachTemplateMessage|string
    {
        $c = $plantilla['content'];
        $variables = array_values(array_filter((array) ($c['variables'] ?? []), 'is_string'));
        $marca = config('legal.brand');
        $r = $this->recipients->markers($subjectKey, $leadId, $variables, $now, is_string($marca) ? $marca : '');
        if ($r['missing'] !== []) {
            return 'template_marker_unresolvable';
        }
        $parametros = array_map(static fn (string $m): string => $r['values'][$m], $variables);
        $texto = (string) preg_replace_callback('/\{\{(\d+)\}\}/u', static fn (array $m): string => $parametros[(int) $m[1] - 1] ?? $m[0], (string) ($c['bodyPreview'] ?? ''));
        if (OutreachPublishedStrategies::restrictionViolations($texto) !== []) {
            return 'template_violates_restriction';
        }
        try {
            return new OutreachTemplateMessage((string) ($c['metaName'] ?? ''), (string) ($c['language'] ?? ''), (string) ($c['category'] ?? ''), $plantilla['hash'], $parametros, $texto);
        } catch (InvalidArgumentException) {
            // No se traga: se vuelve el motivo del fallo del trabajo (un valor que Meta rechazaría no sale).
            return 'template_parameter_invalid';
        }
    }

    /** Un motivo de la puerta como código del intento (nunca un texto). */
    private static function code(mixed $reason, string $fallback): string
    {
        return is_string($reason) && preg_match(self::CODE_PATTERN, $reason) === 1 ? $reason : $fallback;
    }
}
