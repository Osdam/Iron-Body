<?php

namespace App\Services\Voice;

/**
 * La política de llamadas (contrato H6 §I6.5): una tabla de decisión PURA,
 * sin configuración, base, reloj ni red. No llama a nadie ni sabe hacerlo:
 * dice si una llamada estaría permitida y por qué no.
 *
 * EN ESTE HITO NUNCA PERMITE NADA: {@see CALLS_ENABLED_IN_CODE} es `false` y
 * `calls_disabled_in_code` sale siempre el primero. Girar la constante es una
 * línea de código que solo puede ir en el commit que implemente las llamadas,
 * con autorización expresa del propietario (como ENGINE_READS_VERSIONS en el
 * Hito 5). Una prueba estática (`VoiceIsolationTest`) la vigila.
 *
 * `source_not_allowed` codifica la regla del hito de voz (T-7): un marcador
 * solo podrá llamar a canarios del registro, nunca a las filas pendientes que
 * el motor deja en `marketing_calls` con el teléfono en claro.
 */
final class VoiceCallPolicy
{
    /** Se gira SOLO en el commit que implemente las llamadas, con autorización expresa (como ENGINE_READS_VERSIONS en el H5). */
    public const CALLS_ENABLED_IN_CODE = false;

    public const REASONS = ['calls_disabled_in_code', 'twilio_voice_disabled', 'no_voice_number', 'elevenlabs_not_configured',
        'elevenlabs_disabled', 'elevenlabs_unverified', 'not_an_active_voice_canary', 'source_not_allowed', 'daily_cap_zero'];

    /**
     * Acumula los motivos que apliquen, en el orden de {@see REASONS}.
     * `allowed` = sin motivos.
     */
    public static function decide(VoiceCallContext $ctx): VoiceCallDecision
    {
        $motivos = [];

        if (self::CALLS_ENABLED_IN_CODE !== true) {
            $motivos[] = 'calls_disabled_in_code';
        }
        if (! $ctx->twilioVoiceEnabled) {
            $motivos[] = 'twilio_voice_disabled';
        }
        if (! $ctx->twilioVoiceFromConfigured) {
            $motivos[] = 'no_voice_number';
        }

        $porEstado = match ($ctx->providerState) {
            VoiceProviderStatus::STATE_NOT_CONFIGURED => 'elevenlabs_not_configured',
            VoiceProviderStatus::STATE_DISABLED => 'elevenlabs_disabled',
            VoiceProviderStatus::STATE_UNVERIFIED => 'elevenlabs_unverified',
            // VERIFIED no añade nada. Otro valor no llega: VoiceCallContext lo rechaza.
            default => null,
        };
        if ($porEstado !== null) {
            $motivos[] = $porEstado;
        }

        if ($ctx->canary !== VoiceCallContext::CANARY_ACTIVE_VOICE) {
            $motivos[] = 'not_an_active_voice_canary';
        }
        if ($ctx->source !== VoiceCallContext::SOURCE_CANARY_REGISTRY) {
            $motivos[] = 'source_not_allowed';
        }
        if ($ctx->dailyCap <= 0) {
            $motivos[] = 'daily_cap_zero';
        }

        return new VoiceCallDecision($motivos === [], $motivos);
    }
}
