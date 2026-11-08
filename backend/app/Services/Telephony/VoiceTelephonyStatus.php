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
    /** @return array<string, mixed> */
    public static function snapshot(): array
    {
        try {
            $partes = array_map(static fn (VoicePublishedPart $p): array => [
                'status' => $p->status, 'seq' => $p->seq, 'reason' => $p->reason, 'providerAgentId' => $p->providerAgentId,
            ], VoicePublishedConfig::read());
            $freno = VoiceBrake::isOpen();
            $metricas = VoiceCallsQuery::metrics();
        } catch (Throwable $e) {
            // Sin la base (p. ej., el doctor en una máquina sin migrar) el estado sigue saliendo, sin lo que vive en ella.
            $partes = null;
            $freno = null;
            $metricas = ['error_class' => $e::class];
        }

        return [
            'callsEnabledInCode' => VoiceCallPolicy::CALLS_ENABLED_IN_CODE,
            'flags' => [
                'inbound' => config('voice.inbound.enabled') === true,
                'outbound' => config('voice.outbound.enabled') === true,
                'twilioVoice' => config('voice.twilio.enabled') === true,
                'publishing' => config('voice.publishing.enabled') === true,
                'lab' => config('voice.lab.enabled') === true,
                'monitor' => config('voice.monitor.enabled') === true,
            ],
            'scopes' => ['inbound' => (string) config('voice.inbound.scope', 'canary'), 'outbound' => (string) config('voice.outbound.scope', 'canary')],
            'provider' => VoiceProviderState::current(),
            'number' => [
                'configured' => VoicePhone::isE164(config('voice.twilio.from')),
                'collidesWithMessaging' => VoiceNumberGuard::collidesWithConfig(),
                'importedNumberIdConfigured' => is_string(config('voice.elevenlabs.agent_phone_number_id')) && config('voice.elevenlabs.agent_phone_number_id') !== '',
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
            'transferDirectory' => VoiceTransferDirectory::approvedHashes() === null ? 'unavailable' : 'available',
            'today' => $metricas,
        ];
    }
}
