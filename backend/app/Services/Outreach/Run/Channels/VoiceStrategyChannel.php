<?php

namespace App\Services\Outreach\Run\Channels;

use App\Services\Outreach\Facts\FactPass;
use App\Services\Outreach\Facts\SubjectResolver;
use App\Services\Outreach\Facts\VoiceConsentFacts;
use App\Services\Outreach\Facts\WhatsappSignals;
use App\Services\Outreach\Run\LiveStrategy;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachSendGate;
use App\Services\Outreach\Run\VoiceCallOutcomes;
use App\Services\Telephony\OutboundGate;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LA VOZ POR ESTRATEGIA (contrato H9, D9-9.4, D9-8 etapa 7; paquete V9, como
 * Enmienda 17 del H8 propuesta; §13.1 #18, #19): el canal que pide UNA
 * saliente por la ÚNICA puerta de las salientes del H8,
 * `OutboundGate::requestForStrategy()`. Nunca nombra a Twilio ni a ElevenLabs
 * (protección 4) ni el trabajo de la cola de la voz: no hay otro camino.
 *
 * El despachador ya recorrió la escalera N1–N7 justo antes (TOCTOU); aun así,
 * ANTES de pedir nada el canal exige, por su cuenta (defensa en profundidad):
 *  0. la voz DECLARADA viva en `OutreachSendGate::LIVE_CHANNELS`
 *     (`channel_not_live`, lo mismo que dice la escalera). `voice_call` ya
 *     está declarada ahí (L9-81). Declarada significa implementada, no
 *     abierta: la cierran la constante del paso 3, la de la voz del H8
 *     (`VoiceCallPolicy::CALLS_ENABLED_IN_CODE`), el interruptor del paso 2 y
 *     los cupos y presupuestos de la voz;
 *  1. el trabajo de voz y en vivo (`channel_mismatch`);
 *  2. la maestra en `live` (`mode_off`) y `COMMERCIAL_AUTOMATION_VOICE_ENABLED`
 *     (`channel_disabled`);
 *  3. la constante `OutreachSendGate::SENDS_ENABLED_IN_CODE`
 *     (`sends_disabled_in_code`): hoy `false`, así que hoy NADA pasa de aquí;
 *  4. el cupo y el presupuesto RESERVADOS por el planificador para este
 *     trabajo y no liberados (`budget_not_reserved`);
 *  5. la persona resuelta (`unknown_subject`) y su consentimiento de VOZ para
 *     la FINALIDAD de la estrategia, leído otra vez del hecho
 *     (`consent.voice_call.<finalidad>`; `no_consent_for_purpose_channel`).
 *
 * Después, la puerta del H8 decide (todos sus motivos, intactos). Lo que dice
 * se traduce SIN reintentos ({@see fromGate()}):
 *  - 202 (o 200, la misma llamada de este trabajo) → `started`, con la
 *    referencia de la llamada (`voice_call_id`, una vez); sin referencia,
 *    `ambiguous` (`voice_call_ref_missing`);
 *  - cualquier negativa (409 con sus motivos o 422) → `failed_to_start`
 *    con el PRIMER motivo como `detail_code` (el orden cerrado de
 *    `OutboundPolicy::REASONS`: lo más de raíz primero); el despachador libera
 *    la reserva y registra `failed`;
 *  - una excepción (no se sabe si la fila llegó a confirmarse) → `ambiguous`
 *    (`voice_gate_error`): no se reintenta, se concilia
 *    ({@see VoiceCallOutcomes}).
 */
final class VoiceStrategyChannel implements OutreachChannel
{
    public const NAME = 'voice_call';

    /** La voz sin declarar en `OutreachSendGate::LIVE_CHANNELS`: lo dicen la escalera (N4) y este canal, y nada se pide. */
    public const NOT_LIVE = 'channel_not_live';

    public const DETAIL_PATTERN = '/^[a-z][a-z0-9_]{0,47}$/D';

    public function __construct(
        private readonly OutboundGate $gate = new OutboundGate,
        private readonly SubjectResolver $subjects = new SubjectResolver,
        private readonly VoiceConsentFacts $voiceConsents = new VoiceConsentFacts,
        private readonly WhatsappSignals $signals = new WhatsappSignals,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function attempt(object $job, object $enrollment, LiveStrategy $live, DateTimeImmutable $now): ChannelAttempt
    {
        if (! OutreachSendGate::channelLive(self::NAME)) {
            return ChannelAttempt::failed(self::NOT_LIVE);
        }
        if ((string) $job->channel !== self::NAME || (string) $job->mode !== OutreachConfig::MODE_LIVE) {
            return ChannelAttempt::failed('channel_mismatch');
        }
        if (! OutreachConfig::isLive()) {
            return ChannelAttempt::failed('mode_off');
        }
        if (! OutreachConfig::voiceEnabled()) {
            return ChannelAttempt::failed('channel_disabled');
        }
        if (! OutreachSendGate::sendsEnabledInCode()) {
            return ChannelAttempt::failed('sends_disabled_in_code');
        }
        if ((int) $job->reserved_units < 1 || $job->budget_released_at !== null) {
            return ChannelAttempt::failed('budget_not_reserved');
        }

        $clave = (string) $job->subject_key;
        $persona = $this->subjects->forKeys([$clave])[$clave] ?? null;
        if ($persona === null || ! $persona->resolved()) {
            return ChannelAttempt::failed('unknown_subject');
        }
        // R-1 de sec-fix-baja (L9-90; D9-15: toda señal negativa de cualquier fuente gana): quien escribió ALGUNA VEZ
        // por WhatsApp una baja, segura o posible, no recibe una llamada de una estrategia, aunque esa baja fuera en
        // una conversación que el H9 nunca vio (la de ULTRON Legacy). Lo mismo que hace el canal de WhatsApp.
        if ($persona->leadIds !== [] && $this->signals->everPossiblyOptedOut($persona->leadIds)) {
            return ChannelAttempt::failed('opt_out_word');
        }
        // El hecho, por su adaptador de solo lectura (la misma regla del H8 que la escalera: `VoiceConsentRule`).
        $voz = $this->voiceConsents->facts(new FactPass($now, [$clave => $persona]))[$clave]['policy'] ?? [];
        if (($voz['consent.voice_call.'.$live->purpose] ?? null) !== true) {
            return ChannelAttempt::failed('no_consent_for_purpose_channel');
        }

        // La ÚNICA petición, por la ÚNICA puerta. Sin reintentos.
        try {
            $respuesta = $this->gate->requestForStrategy(
                jobUuid: (string) $job->uuid,
                purposeKey: $live->purpose,
                memberIds: $persona->memberIds,
                leadIds: $persona->leadIds,
                canaryPart: $live->isCanary(),
                strategyKey: $live->strategyKey,
            );
        } catch (Throwable $e) {
            // No se sabe si la fila de la llamada llegó a confirmarse: `ambiguous` (se concilia, nunca se repite).
            Log::warning('outreach.voice.gate_error', ['job' => (string) $job->uuid, 'error_class' => $e::class]);

            return ChannelAttempt::ambiguous('voice_gate_error');
        }

        return self::fromGate($respuesta);
    }

    /**
     * Lo que dijo la puerta, como intento del canal: PURO.
     *
     * @param  array{http?: mixed, body?: mixed, callId?: mixed}  $respuesta
     */
    public static function fromGate(array $respuesta): ChannelAttempt
    {
        $http = $respuesta['http'] ?? null;
        $id = $respuesta['callId'] ?? null;
        if (in_array($http, [200, 202], true)) {
            // Aceptada: con su referencia, `started`; sin ella no se puede probar qué fila es (nunca `failed`, que liberaría la reserva).
            return is_int($id) && $id > 0 ? ChannelAttempt::started(['voice_call_id' => $id]) : ChannelAttempt::ambiguous('voice_call_ref_missing');
        }
        $cuerpo = is_array($respuesta['body'] ?? null) ? $respuesta['body'] : [];
        $motivos = is_array($cuerpo['reasons'] ?? null) ? array_values($cuerpo['reasons']) : [];
        foreach ([$motivos[0] ?? null, $cuerpo['code'] ?? null] as $codigo) {
            if (is_string($codigo) && preg_match(self::DETAIL_PATTERN, $codigo) === 1) {
                return ChannelAttempt::failed($codigo);
            }
        }

        return ChannelAttempt::failed('voice_gate_denied');
    }
}
