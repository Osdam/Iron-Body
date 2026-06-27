<?php

namespace App\Services\Meta;

/**
 * Diagnóstico de la integración Meta / WhatsApp Cloud API SIN exponer secretos.
 * Reporta solo presencia (SET/MISSING) y decisiones derivadas. Lo comparten el
 * comando `meta:doctor` y el endpoint interno GET .../meta/doctor (n8n/operación).
 *
 * NUNCA imprime ni devuelve valores de tokens/secretos.
 */
class MetaDoctorService
{
    public function __construct(private readonly MetaAuthService $auth)
    {
    }

    /**
     * @return array<string,mixed> reporte saneado (sin valores sensibles).
     */
    public function report(): array
    {
        $enabled = (bool) config('meta.enabled');

        $present = [
            'access_token'                 => $this->isSet(config('meta.access_token')),
            'app_secret'                   => $this->isSet(config('meta.app_secret')),
            'verify_token'                 => $this->isSet(config('meta.verify_token')),
            'webhook_secret'               => $this->isSet(config('meta.webhook_secret')),
            'whatsapp_business_account_id' => $this->isSet(config('meta.whatsapp_business_account_id')),
            'whatsapp_phone_number_id'     => $this->isSet(config('meta.whatsapp_phone_number_id')),
            'whatsapp_display_phone'       => $this->isSet(config('meta.whatsapp_display_phone')),
        ];

        // MetaMessagingService considera "configurado" = enabled + access_token + app_secret.
        $authConfigured = $this->auth->isConfigured();
        // El envío real de WhatsApp además exige el phone_number_id.
        $liveSendAllowed = $authConfigured && $present['whatsapp_phone_number_id'];
        $sendMode = $liveSendAllowed ? 'real' : 'dry_run';

        return [
            'enabled'          => $enabled,
            'graph_version'    => (string) config('meta.graph_version'),
            'present'          => $present,
            'auth_configured'  => $authConfigured,
            'live_send_allowed' => $liveSendAllowed,
            'send_mode'        => $sendMode,
            'webhook_url'      => $this->expectedWebhookUrl(),
            'webhook'          => $this->webhookSection($enabled, $present, $liveSendAllowed),
            'missing'          => $this->missing($enabled, $present),
            'suggestions'      => $this->suggestions($enabled, $present, $liveSendAllowed),
        ];
    }

    /** Estado del webhook entrante (Fase 4-A) sin secretos. */
    private function webhookSection(bool $enabled, array $present, bool $liveSendAllowed): array
    {
        [$get, $post] = $this->webhookRoutesExist();

        return [
            'get_route_exists'         => $get,
            'post_route_exists'        => $post,
            'verify_token'             => $present['verify_token'],
            'webhook_secret'           => $present['webhook_secret'],
            'whatsapp_phone_number_id' => $present['whatsapp_phone_number_id'],
            'inbound_meta_enabled'     => (bool) config('marketing.inbound.meta_enabled', true),
            'inbound_auto_analyze'     => (bool) config('marketing.inbound.auto_analyze', true),
            'inbound_auto_execute'     => (bool) config('marketing.inbound.auto_execute', false),
            'meta_enabled'             => $enabled,
            // Modo efectivo de envío de respuestas: real solo si Meta está listo.
            'effective_mode'           => $liveSendAllowed ? 'real' : 'dry_run',
        ];
    }

    /** @return array{0:bool,1:bool} [GET existe, POST existe] para /api/webhooks/meta. */
    private function webhookRoutesExist(): array
    {
        $get = $post = false;
        foreach (app('router')->getRoutes() as $route) {
            if ($route->uri() === 'api/webhooks/meta') {
                $methods = $route->methods();
                $get = $get || in_array('GET', $methods, true);
                $post = $post || in_array('POST', $methods, true);
            }
        }
        return [$get, $post];
    }

    /** URL del webhook que debe registrarse en Meta (derivada de APP_URL). */
    public function expectedWebhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/webhooks/meta';
    }

    /** Variables faltantes que impiden el envío real (sin valores). */
    private function missing(bool $enabled, array $present): array
    {
        $missing = [];
        if (! $enabled) {
            $missing[] = 'META_ENABLED (debe ser true para envío real)';
        }
        if (! $present['access_token']) {
            $missing[] = 'META_ACCESS_TOKEN';
        }
        if (! $present['app_secret']) {
            $missing[] = 'META_APP_SECRET';
        }
        if (! $present['whatsapp_phone_number_id']) {
            $missing[] = 'META_WHATSAPP_PHONE_NUMBER_ID';
        }
        return $missing;
    }

    /** Sugerencias accionables (sin secretos). */
    private function suggestions(bool $enabled, array $present, bool $liveSendAllowed): array
    {
        if ($liveSendAllowed) {
            return ['Configuración completa: el envío real de WhatsApp está habilitado.'];
        }

        $tips = [];
        if (! $enabled) {
            $tips[] = 'Pon META_ENABLED=true cuando tengas credenciales reales (queda en dry_run mientras tanto).';
        }
        if (! $present['access_token']) {
            $tips[] = 'Define META_ACCESS_TOKEN con un token válido de WhatsApp Cloud API (System User / larga duración).';
        }
        if (! $present['app_secret']) {
            $tips[] = 'Define META_APP_SECRET (también usado para la firma del webhook si META_WEBHOOK_SECRET no se establece).';
        }
        if (! $present['whatsapp_phone_number_id']) {
            $tips[] = 'Define META_WHATSAPP_PHONE_NUMBER_ID (el ID del número en Cloud API, NO el teléfono visible).';
        }
        if (! $present['verify_token']) {
            $tips[] = 'Define META_VERIFY_TOKEN para verificar el webhook (GET hub.verify_token).';
        }
        $tips[] = 'Tras completar .env: php artisan config:clear && php artisan meta:doctor.';
        return $tips;
    }

    private function isSet(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : ! empty($value);
    }
}
