<?php

namespace App\Services\Marketing;

/**
 * Resuelve el responder EFECTIVO del cerebro comercial a partir de la config,
 * sin exponer secretos. Una sola fuente de verdad compartida por el binding del
 * contenedor y por el diagnóstico (marketing:ai-doctor). Por defecto: fake.
 */
final class SalesAiConfig
{
    /**
     * Driver efectivo. Degrada siempre hacia abajo y nunca rompe producción:
     * hermes → openai → fake. Si Hermes no está listo se usa OpenAI; si OpenAI
     * tampoco, reglas deterministas.
     */
    public static function effectiveDriver(): string
    {
        if (self::hermesReady()) {
            return 'hermes';
        }

        return self::openAiReady() ? 'openai' : 'fake';
    }

    /** ¿Hermes está realmente listo (driver + flag + base_url)? */
    public static function hermesReady(): bool
    {
        return (string) config('marketing.ai.driver', 'fake') === 'hermes'
            && (bool) config('marketing.ai.hermes.enabled', false)
            && trim((string) config('marketing.ai.hermes.base_url')) !== '';
    }

    /** ¿Está OpenAI realmente listo (driver + flag + API key + modelo)? */
    public static function openAiReady(): bool
    {
        return (string) config('marketing.ai.driver', 'fake') === 'openai'
            && (bool) config('marketing.ai.openai.enabled', false)
            && self::hasApiKey()
            && self::model() !== '';
    }

    public static function hasApiKey(): bool
    {
        return trim((string) config('services.openai.api_key')) !== '';
    }

    public static function model(): string
    {
        return trim((string) config('marketing.ai.openai.model'));
    }

    public static function failClosed(): bool
    {
        return (bool) config('marketing.ai.openai.fail_closed', true);
    }
}
