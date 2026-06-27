<?php

namespace App\Services\Marketing;

/**
 * Resuelve el responder EFECTIVO del cerebro comercial a partir de la config,
 * sin exponer secretos. Una sola fuente de verdad compartida por el binding del
 * contenedor y por el diagnóstico (marketing:ai-doctor). Por defecto: fake.
 */
final class SalesAiConfig
{
    /** Driver efectivo a usar: 'openai' solo si TODO está listo; si no, 'fake'. */
    public static function effectiveDriver(): string
    {
        return self::openAiReady() ? 'openai' : 'fake';
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
