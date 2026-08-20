<?php

namespace App\Services\Meta;

use App\Models\WhatsappBusinessIntegration;
use Throwable;

/**
 * De dónde salen las credenciales de WhatsApp Cloud API.
 *
 * Hay dos orígenes y un orden estricto entre ellos:
 *
 *   1. La conexión hecha desde el CRM (Embedded Signup), si está usable.
 *   2. El `.env` del servidor, como hasta ahora.
 *
 * Ese orden es el que permite que conectar desde la pantalla tenga efecto real
 * sin tener que entrar por SSH, y a la vez que desconectar devuelva el canal
 * exactamente al estado anterior en vez de dejarlo muerto. Mientras no haya
 * ninguna fila conectada —que es el estado de hoy— todas las respuestas de esta
 * clase son idénticas a leer `config('meta.*')` directamente.
 *
 * Lo que esta clase NO decide es si el canal puede enviar. `META_ENABLED` es el
 * único interruptor, y vive fuera de aquí a propósito: guardar credenciales y
 * autorizar mensajes a clientes reales son decisiones distintas, y quien hace la
 * primera desde el CRM no debería estar disparando la segunda sin saberlo.
 *
 * Se resuelve UNA vez por petición/job. Sin esa memoria, mandar diez mensajes
 * serían diez consultas idénticas por algo que no cambia a mitad de proceso.
 */
class WhatsappIntegrationRegistry
{
    /** false = aún no se consultó. null = se consultó y no hay conexión usable. */
    private WhatsappBusinessIntegration|null|false $resolved = false;

    /**
     * La conexión vigente si sirve para operar, o null.
     *
     * La consulta va envuelta: durante un despliegue el código nuevo corre unos
     * segundos contra un esquema todavía sin migrar, y en ese hueco preguntar
     * por una tabla que no existe tumbaría el canal entero. Ante la duda, se
     * cae al `.env`, que es el comportamiento que ya funcionaba.
     */
    public function integration(): ?WhatsappBusinessIntegration
    {
        if ($this->resolved !== false) {
            return $this->resolved;
        }

        if (! $this->precedenceEnabled()) {
            return $this->resolved = null;
        }

        try {
            $integration = WhatsappBusinessIntegration::current();
        } catch (Throwable) {
            return $this->resolved = null;
        }

        return $this->resolved = ($integration?->isUsable() ? $integration : null);
    }

    /** Olvida lo resuelto. Lo llama el onboarding tras conectar o desconectar. */
    public function forget(): void
    {
        $this->resolved = false;
    }

    /** ¿Las credenciales vienen de la base de datos ahora mismo? */
    public function usingDatabase(): bool
    {
        return $this->integration() !== null;
    }

    public function accessToken(): ?string
    {
        $token = $this->integration()?->access_token;

        return (string) $token !== '' ? (string) $token : config('meta.access_token');
    }

    public function phoneNumberId(): ?string
    {
        return $this->firstFilled(
            $this->integration()?->phone_number_id,
            config('meta.whatsapp_phone_number_id'),
        );
    }

    public function wabaId(): ?string
    {
        return $this->firstFilled(
            $this->integration()?->waba_id,
            config('meta.whatsapp_business_account_id'),
        );
    }

    public function businessId(): ?string
    {
        return $this->firstFilled(
            $this->integration()?->business_id,
            config('meta.business_id'),
        );
    }

    public function displayPhone(): ?string
    {
        return $this->firstFilled(
            $this->integration()?->display_phone_number,
            config('meta.whatsapp_display_phone'),
        );
    }

    /** De dónde salió la credencial que se está usando: 'database' o 'env'. */
    public function source(): string
    {
        return $this->usingDatabase() ? 'database' : 'env';
    }

    /** ¿La precedencia de la base de datos está habilitada por configuración? */
    public function precedenceEnabled(): bool
    {
        return (bool) config('meta.embedded_signup.db_credentials_precedence', true);
    }

    private function firstFilled(?string $preferred, mixed $fallback): ?string
    {
        if ((string) $preferred !== '') {
            return (string) $preferred;
        }

        $fallback = (string) $fallback;

        return $fallback !== '' ? $fallback : null;
    }
}
