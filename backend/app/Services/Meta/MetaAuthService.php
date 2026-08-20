<?php

namespace App\Services\Meta;

/**
 * Acceso central a la configuración de Meta. Los tokens viven SOLO aquí (config
 * → env del servidor, o la conexión guardada por Embedded Signup); nunca se
 * exponen a Angular/Flutter. Mientras `META_ENABLED=false`, `enabled()` es false
 * y los servicios no contactan Graph.
 *
 * Desde el onboarding desde el CRM hay DOS orígenes posibles de credenciales, y
 * quién gana lo decide {@see WhatsappIntegrationRegistry}: la conexión hecha
 * desde la pantalla si está usable, y si no el `.env` de siempre. Esta clase
 * sigue siendo la única puerta para el resto del sistema, así que ningún
 * servicio necesita enterarse de ese cambio.
 *
 * Lo que NO cambió: `enabled()` mira exclusivamente `META_ENABLED`. Conectar
 * una cuenta desde el CRM guarda credenciales; no autoriza a escribirle a
 * nadie. Son dos decisiones y siguen siendo dos interruptores.
 */
class MetaAuthService
{
    public function __construct(private readonly WhatsappIntegrationRegistry $registry) {}

    public function enabled(): bool
    {
        return (bool) config('meta.enabled');
    }

    /** ¿Hay credenciales mínimas para operar contra Graph API? */
    public function isConfigured(): bool
    {
        return $this->enabled()
            && (string) $this->accessToken() !== ''
            && (string) config('meta.app_secret') !== '';
    }

    public function accessToken(): ?string
    {
        return $this->registry->accessToken();
    }

    /** ID del número en Cloud API (NO el teléfono visible). */
    public function phoneNumberId(): ?string
    {
        return $this->registry->phoneNumberId();
    }

    /** WhatsApp Business Account en uso. */
    public function wabaId(): ?string
    {
        return $this->registry->wabaId();
    }

    /** Business Manager dueño de la cuenta. */
    public function businessId(): ?string
    {
        return $this->registry->businessId();
    }

    /** Teléfono visible del negocio (informativo; el envío usa el phone_number_id). */
    public function displayPhone(): ?string
    {
        return $this->registry->displayPhone();
    }

    /** 'database' si rige una conexión del CRM, 'env' si rige el .env. */
    public function credentialSource(): string
    {
        return $this->registry->source();
    }

    public function timeout(): int
    {
        return (int) config('meta.timeout', 20);
    }

    /** URL absoluta de un nodo/edge de la Graph API. */
    public function graphUrl(string $path): string
    {
        $base = rtrim((string) config('meta.graph_base'), '/');
        $version = trim((string) config('meta.graph_version'), '/');
        $path = ltrim($path, '/');

        return "{$base}/{$version}/{$path}";
    }
}
