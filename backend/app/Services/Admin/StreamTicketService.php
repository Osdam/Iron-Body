<?php

namespace App\Services\Admin;

use App\Models\AdminSession;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Vale de corta vida para abrir un stream SSE (o cargar una face-image).
 *
 * POR QUÉ EXISTE
 * --------------
 * `EventSource` y `<img src>` no pueden mandar la cabecera Authorization, así
 * que el CRM venía poniendo el token de sesión del administrador en la query
 * string. Nginx registra la línea de petición completa, de modo que esos
 * tokens quedaban en claro en access.log y en sus 13 rotaciones: 812
 * peticiones y 3 tokens distintos en un solo día. Quien pudiera leer los logs
 * —o una copia de seguridad de ellos— tenía sesión de administrador.
 *
 * El vale sustituye a ese token en la URL. Es distinto en tres cosas que
 * importan:
 *   1. caduca en minutos, no cuando el admin cierra sesión;
 *   2. sólo sirve para las rutas de stream y face-image, nunca para la API;
 *   3. no es el token: filtrarlo no entrega la sesión.
 *
 * Va cifrado con la clave de la app (`Crypt`, que además autentica), así que
 * no se puede fabricar uno ni alterar su caducidad.
 */
class StreamTicketService
{
    /** Suficiente para abrir el stream y reconectar un par de veces. */
    public const TTL_SECONDS = 300;

    public function issue(AdminSession $session): array
    {
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        return [
            'ticket' => Crypt::encryptString(json_encode([
                'sid' => $session->id,
                'exp' => $expiresAt->getTimestamp(),
            ], JSON_THROW_ON_ERROR)),
            'expires_in' => self::TTL_SECONDS,
        ];
    }

    /**
     * Resuelve el vale a su sesión, o null si no vale.
     *
     * Devuelve null —y nunca lanza— ante cualquier problema: un vale caducado
     * o manipulado se trata igual que no haber mandado nada.
     */
    public function resolve(string $ticket): ?AdminSession
    {
        try {
            $payload = json_decode(Crypt::decryptString($ticket), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload) || ! isset($payload['sid'], $payload['exp'])) {
            return null;
        }

        if ((int) $payload['exp'] < now()->getTimestamp()) {
            return null;
        }

        return AdminSession::query()->active()->whereKey($payload['sid'])->first();
    }
}
