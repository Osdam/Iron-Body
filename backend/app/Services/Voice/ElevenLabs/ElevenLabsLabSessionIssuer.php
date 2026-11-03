<?php

namespace App\Services\Voice\ElevenLabs;

use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\Dto\VoiceLabSession;
use App\Services\Voice\VoiceLabSessionIssuer;
use App\Services\Voice\VoiceProviderStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * El emisor de URL firmadas del laboratorio hablado (contrato H8, D8-3 y
 * D8-23): `GET /v1/convai/conversation/get-signed-url?agent_id=…` (referencia
 * oficial comprobada el 03-11-2026; responde `{signed_url}`). La clave la pone
 * el servidor y nunca sale de aquí: al navegador solo llega la URL.
 *
 * Reglas, como el resto del espacio: sin la configuración completa
 * (`ELEVENLABS_UNVERIFIED`), `not_configured` sin red; hosts fijos en
 * `production`; la clave, leída al usarla; sin redirecciones ni reintentos;
 * nunca lanza por la red.
 *
 * LA URL QUE VUELVE SE COMPRUEBA: tiene que ser un WebSocket (`wss`; `ws` solo
 * hacia el simulador local por http) del MISMO host al que se pidió y sin
 * usuario ni fragmento. Una URL hacia otro host no se entrega: el navegador
 * hablaría con quien no es (`error`).
 *
 * Los cuatro cerrojos del gasto (la bandera, el driver, el presupuesto y la
 * clave verificada) y la reserva son de LAB8, antes de llamar aquí.
 */
final class ElevenLabsLabSessionIssuer implements VoiceLabSessionIssuer
{
    public const PATH = '/v1/convai/conversation/get-signed-url';

    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** Una URL firmada es corta; nada de un megabyte. */
    public const MAX_URL_LENGTH = 4096;

    private const JSON_MAX_DEPTH = 8;

    public function signedSessionUrl(string $agentId): VoiceLabSession
    {
        $config = ElevenLabsConfig::fromConfig();
        $base = $config->baseUrl();
        if ($config->status()->state !== VoiceProviderStatus::STATE_UNVERIFIED || $base === null) {
            return new VoiceLabSession(VoiceLabSession::OUTCOME_NOT_CONFIGURED, null, null, null);
        }
        if (! VoiceIds::isProviderId($agentId)) {
            return new VoiceLabSession(VoiceLabSession::OUTCOME_REJECTED, null, null, null);
        }
        $espera = $config->timeoutSeconds();

        try {
            $respuesta = Http::withHeaders(['xi-api-key' => trim((string) config('voice.elevenlabs.api_key'))])
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $espera))
                ->timeout($espera)
                ->get($base.self::PATH, ['agent_id' => $agentId]);
        } catch (StrayRequestException $e) {
            // Solo existe en las pruebas (`Http::preventStrayRequests()`): que se vea.
            throw $e;
        } catch (ConnectionException) {
            return new VoiceLabSession(VoiceLabSession::OUTCOME_UNREACHABLE, null, $agentId, null);
        } catch (Throwable) {
            // Ni la clase ni el mensaje salen: pueden llevar la cabecera.
            return new VoiceLabSession(VoiceLabSession::OUTCOME_ERROR, null, $agentId, null);
        }

        $estado = $respuesta->status();
        $http = $estado >= 100 && $estado <= 599 ? $estado : null;
        if ($estado < 200 || $estado > 299) {
            $resultado = match (true) {
                $estado === 401, $estado === 403 => VoiceLabSession::OUTCOME_AUTH_FAILED,
                $estado === 404 => VoiceLabSession::OUTCOME_NOT_FOUND,
                $estado >= 400 && $estado <= 499 => VoiceLabSession::OUTCOME_REJECTED,
                default => VoiceLabSession::OUTCOME_ERROR,
            };

            return new VoiceLabSession($resultado, null, $agentId, $http);
        }

        $json = json_decode($respuesta->body(), true, self::JSON_MAX_DEPTH);
        $url = is_array($json) ? ($json['signed_url'] ?? null) : null;
        unset($json);
        if (! is_string($url) || ! self::sameHostWebSocket($url, $base)) {
            return new VoiceLabSession(VoiceLabSession::OUTCOME_ERROR, null, $agentId, $http);
        }

        return new VoiceLabSession(VoiceLabSession::OUTCOME_ISSUED, $url, $agentId, $http);
    }

    /**
     * ¿Es la URL un WebSocket del mismo host que la base? `wss` siempre; `ws`
     * solo si la base es http (el simulador local). Sin usuario, contraseña ni
     * fragmento, y de un largo razonable. Pura.
     */
    public static function sameHostWebSocket(string $url, string $base): bool
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }
        $u = parse_url($url);
        $b = parse_url($base);
        if (! is_array($u) || ! is_array($b) || isset($u['user']) || isset($u['pass']) || isset($u['fragment'])) {
            return false;
        }
        $esquema = strtolower((string) ($u['scheme'] ?? ''));
        $esquemaBase = strtolower((string) ($b['scheme'] ?? ''));
        $admitidos = $esquemaBase === 'http' ? ['ws', 'wss'] : ['wss'];

        return in_array($esquema, $admitidos, true)
            && strtolower((string) ($u['host'] ?? '')) !== ''
            && strtolower((string) ($u['host'] ?? '')) === strtolower((string) ($b['host'] ?? ''));
    }
}
