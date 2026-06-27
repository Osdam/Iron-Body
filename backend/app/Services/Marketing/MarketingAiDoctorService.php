<?php

namespace App\Services\Marketing;

/**
 * Diagnóstico del cerebro comercial IA SIN exponer secretos. Reporta el driver
 * configurado, si OpenAI está listo y el responder EFECTIVO (fake/openai), más
 * sugerencias. Compartido por el comando marketing:ai-doctor y el endpoint
 * GET .../ai/doctor. Nunca imprime la OPENAI_API_KEY ni el modelo completo.
 */
class MarketingAiDoctorService
{
    /** @return array<string,mixed> reporte saneado. */
    public function report(): array
    {
        $driver       = (string) config('marketing.ai.driver', 'fake');
        $openaiCfg    = (array) config('marketing.ai.openai');
        $openaiEnable = (bool) ($openaiCfg['enabled'] ?? false);
        $hasKey       = SalesAiConfig::hasApiKey();
        $hasModel     = SalesAiConfig::model() !== '';
        $ready        = SalesAiConfig::openAiReady();

        return [
            'brain_enabled'     => (bool) config('marketing.ai.enabled', true),
            'driver'            => $driver,
            'openai_enabled'    => $openaiEnable,
            'present'           => [
                'openai_api_key' => $hasKey,    // SET/MISSING (sin valor)
                'openai_model'   => $hasModel,
            ],
            'openai_ready'      => $ready,
            // Responder que se usará realmente: openai (si listo) o fake.
            'effective_responder' => SalesAiConfig::effectiveDriver(),
            'fail_closed'       => SalesAiConfig::failClosed(),
            'suggestions'       => $this->suggestions($driver, $openaiEnable, $hasKey, $hasModel, $ready),
        ];
    }

    private function suggestions(string $driver, bool $enabled, bool $hasKey, bool $hasModel, bool $ready): array
    {
        if ($ready) {
            return ['OpenAI está listo: el cerebro usará el responder openai (Laravel valida y ejecuta).'];
        }

        $tips = [];
        if ($driver !== 'openai') {
            $tips[] = 'Pon MARKETING_SALES_AI_DRIVER=openai para activar el cerebro OpenAI.';
        }
        if (! $enabled) {
            $tips[] = 'Pon MARKETING_OPENAI_ENABLED=true (doble interruptor de seguridad).';
        }
        if (! $hasKey) {
            $tips[] = 'Define OPENAI_API_KEY (se reutiliza la de IRON IA; vive solo en el backend).';
        }
        if (! $hasModel) {
            $tips[] = 'Define MARKETING_OPENAI_MODEL (o OPENAI_MODEL) con un modelo válido.';
        }
        $tips[] = 'Mientras tanto, el cerebro usa el responder determinista (fake) de forma segura.';
        $tips[] = 'Tras completar .env: php artisan config:clear && php artisan marketing:ai-doctor.';
        return $tips;
    }
}
