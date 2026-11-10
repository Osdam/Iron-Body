<?php

namespace App\Services\Telephony;

use App\Services\Twilio\TwilioVoiceCarrier;
use App\Services\Voice\VoiceCallPolicy;
use Throwable;

/**
 * El estado de la telefonía SIN RED y SIN SECRETOS (contrato H8, D8-20,
 * D8-21; `GET /api/admin/voice/status` y `voice:doctor --telephony`): las
 * banderas, la constante, el proveedor, el número (si está y si choca con el
 * de WhatsApp, por nombre), la cadena por partes, el freno, los límites y lo
 * del día. Nunca valores de configuración sensibles ni números.
 */
final class VoiceTelephonyStatus
{
    /**
     * La cadena y el freno se leen UNA vez y sirven para todo lo demás (las
     * métricas, la evidencia de cada parte y los destinos; PERF8, P8-1): antes,
     * tres lecturas de la cadena y cuatro del freno por petición.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(): array
    {
        $leidas = null;
        try {
            $leidas = VoicePublishedConfig::read();
            $partes = array_map(static fn (VoicePublishedPart $p): array => [
                'status' => $p->status, 'seq' => $p->seq, 'reason' => $p->reason, 'providerAgentId' => $p->providerAgentId,
            ], $leidas);
            $freno = VoiceBrake::isOpen();
            $metricas = VoiceCallsQuery::metrics(null, $leidas, $freno);
        } catch (Throwable $e) {
            // Sin la base (p. ej., el doctor en una máquina sin migrar) el estado sigue saliendo, sin lo que vive en ella.
            $partes = null;
            $freno = null;
            $metricas = ['error_class' => $e::class];
        }
        $vigilancia = VoiceNumberGuard::policyVerdict();

        return [
            'callsEnabledInCode' => VoiceCallPolicy::CALLS_ENABLED_IN_CODE,
            'flags' => [
                'inbound' => config('voice.inbound.enabled') === true,
                'outbound' => config('voice.outbound.enabled') === true,
                'twilioVoice' => config('voice.twilio.enabled') === true,
                'lab' => config('voice.lab.enabled') === true,
                'monitor' => config('voice.monitor.enabled') === true,
            ],
            'scopes' => ['inbound' => (string) config('voice.inbound.scope', 'canary'), 'outbound' => (string) config('voice.outbound.scope', 'canary')],
            'provider' => VoiceProviderState::current(),
            // E4-6 (B8-g): la marca de la última verificación del Studio, con su fecha (sin ella, `UNVERIFIED`).
            'providerVerification' => VoiceProviderState::verification(),
            'number' => [
                'configured' => VoicePhone::isE164(config('voice.twilio.from')),
                'collidesWithMessaging' => VoiceNumberGuard::collidesWithConfig(),
                'importedNumberIdConfigured' => is_string(config('voice.elevenlabs.agent_phone_number_id')) && config('voice.elevenlabs.agent_phone_number_id') !== '',
                // B8-g: lo DECLARADO (`VOICE_NUMBER_INBOUND_CAPABLE`): false = «Outbound Only»; null = sin declarar (admite).
                'inboundCapable' => is_bool(config('voice.number.inbound_capable')) ? config('voice.number.inbound_capable') : null,
                // SEC8-c B-1: lo último que vio la conciliación del número importado (sin el número), o null.
                // REV8-c (segunda mirada) H-5: sin el identificador del proveedor; solo si es la del número configurado.
                'observed' => self::observedForStatus(VoiceNumberGuard::lastObservation()),
                // Lo que la puerta concluye de ella: `verified`, `mismatch` o `unverified` (sin una de menos de 2 h).
                'check' => $vigilancia['check'],
                // B8-h (REV8-c M-1), aditivo: por qué (`VoiceNumberGuard::CHECK_REASONS`; null con `verified`). P. ej.
                // `other_number_id`: la observación es de otro ELEVENLABS_AGENT_PHONE_NUMBER_ID que el configurado.
                'checkReason' => $vigilancia['reason'],
            ],
            'carrierConfigured' => TwilioVoiceCarrier::configured(),
            'publication' => $partes,
            'brakeOpen' => $freno,
            'limits' => [
                'maxConcurrentCalls' => (int) config('voice.limits.max_concurrent_calls', 1),
                'perCallMaxSeconds' => (int) config('voice.limits.per_call_max_seconds', 300),
                'outboundDailyCap' => (int) config('voice.twilio.daily_call_cap', 0),
                'inboundDailyCap' => (int) config('voice.inbound.daily_cap', 0),
                'dailyBudgetMicros' => VoiceCostGuard::budgetFromConfig(),
                'rneValidityDays' => (int) config('voice.contacts.rne_validity_days', 0),
            ],
            'schedule' => [
                'ownHours' => OutboundSchedule::ownFromConfig() === false ? 'invalid' : (OutboundSchedule::ownFromConfig() === null ? 'legal_only' : 'configured'),
                'staffHours' => OutboundSchedule::staffFromConfig() === false ? 'invalid' : (OutboundSchedule::staffFromConfig() === null ? 'none' : 'configured'),
            ],
            'facts' => ['hoursConfigured' => (string) config('voice.facts.site', '') !== '' && (string) config('voice.facts.hours', '') !== ''],
            'transferDirectory' => VoiceTransferDirectory::countsByPart($leidas),
            'today' => $metricas,
        ];
    }

    /**
     * REV8-c (segunda mirada) H-5: la observación del número para el estado SIN el
     * identificador del proveedor (`phoneNumberId`): cuándo fue, si es la del número
     * configurado y qué concluyó. El identificador solo lo usa la puerta por dentro.
     *
     * @param  array{at: string, phoneNumberId: string, matchesFrom: ?bool, collides: bool}|null  $o
     * @return array{at: string, matchesConfigured: bool, matchesFrom: ?bool, collides: bool}|null
     */
    private static function observedForStatus(?array $o): ?array
    {
        if ($o === null) {
            return null;
        }
        $configurado = config('voice.elevenlabs.agent_phone_number_id');

        return [
            'at' => $o['at'],
            'matchesConfigured' => is_string($configurado) && $configurado !== '' && $o['phoneNumberId'] === $configurado,
            'matchesFrom' => $o['matchesFrom'],
            'collides' => $o['collides'],
        ];
    }
}
