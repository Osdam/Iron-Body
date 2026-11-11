<?php

namespace App\Services\Meta\Templates;

/**
 * LA SONDA DE SOLO LECTURA del permiso de plantillas (plan de plantillas de
 * Meta §5.9). NEUTRA y sin estado: dos GET con el cliente que se le dé y un
 * veredicto de una lista cerrada. No prueba la escritura: crear una plantilla
 * solo se comprueba creándola.
 *
 *  1. `GET /me/permissions` (el token en la cabecera, nunca `debug_token`):
 *     ¿está `whatsapp_business_management` en `granted`? true / false, o null
 *     si la respuesta no se pudo leer (con un token de usuario del sistema
 *     puede no contestar: manda el paso 2).
 *  2. `GET /{waba}/message_templates?limit=1&fields=id`: solo contesta 200 si
 *     el token puede leer las plantillas de ESA cuenta (`templatesReadable`),
 *     o el motivo de que no (`templatesError`).
 *
 * VEREDICTO ({@see OUTCOMES}): `ok` (lee las plantillas de la cuenta),
 * `missing_permission` (sin `whatsapp_business_management`), `no_waba_access`
 * (con el permiso, pero la cuenta no está asignada o no existe),
 * `auth_failed` (el token no vale), `unreachable` (Meta no contestó o pidió
 * frenar), `not_configured` (no se llegó a llamar) o `error` (otra cosa).
 * Nunca lanza por Meta ni por la red (las reglas del cliente).
 */
final class WhatsappTemplateProbe
{
    public const PERMISSION = 'whatsapp_business_management';

    public const OUTCOME_OK = 'ok';

    public const OUTCOMES = ['ok', 'missing_permission', 'no_waba_access', 'auth_failed', 'unreachable', 'not_configured', 'error'];

    /** Los motivos de que la lectura de las plantillas falle (`templatesError`). */
    public const READ_ERRORS = ['permission', 'auth', 'not_found', 'unreachable', 'not_configured', 'error'];

    /**
     * @return array{outcome: string, scopes: array{whatsapp_business_management: bool|null}, templatesReadable: bool|null,
     *               templatesError: string|null, requests: int, durationMs: int}
     */
    public static function run(WhatsappTemplateClient $client, string $wabaId): array
    {
        $permisos = $client->permissions();
        $gestion = $permisos->ok() ? (($permisos->permissions[self::PERMISSION] ?? null) === 'granted') : null;

        $lectura = $client->list($wabaId, ['id'], null, 1);
        $motivo = $lectura->ok() ? null : match ($lectura->error) {
            TemplateApiErrors::PERMISSION => 'permission',
            TemplateApiErrors::AUTH => 'auth',
            TemplateApiErrors::NOT_FOUND => 'not_found',
            TemplateApiErrors::TRANSPORT, TemplateApiErrors::SERVER, TemplateApiErrors::RATE_LIMITED => 'unreachable',
            TemplateApiErrors::NOT_CONFIGURED => 'not_configured',
            default => 'error',
        };

        $veredicto = match (true) {
            $motivo === null => self::OUTCOME_OK,
            $motivo === 'auth' || $permisos->error === TemplateApiErrors::AUTH => 'auth_failed',
            $motivo === 'permission' => $gestion === false ? 'missing_permission' : 'no_waba_access',
            $motivo === 'not_found' => 'no_waba_access',
            $motivo === 'unreachable' => 'unreachable',
            $motivo === 'not_configured' => 'not_configured',
            default => 'error',
        };

        return [
            'outcome' => $veredicto,
            'scopes' => [self::PERMISSION => $gestion],
            'templatesReadable' => $motivo === 'not_configured' ? null : $motivo === null,
            'templatesError' => $motivo,
            'requests' => $permisos->requests + $lectura->requests,
            'durationMs' => $permisos->durationMs + $lectura->durationMs,
        ];
    }
}
