<?php

namespace App\Services\Voice\ElevenLabs;

use App\Services\Voice\VoiceAgentProvider;
use App\Services\Voice\VoiceCheckResult;
use App\Services\Voice\VoiceProviders;
use App\Services\Voice\VoiceProviderStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * El adaptador de ElevenLabs (contrato H6 §I6.1), tras `ELEVENLABS_ENABLED`
 * (apagada por defecto). Lo devuelve {@see VoiceProviders::current()}
 * solo con la bandera encendida y todas las piezas.
 *
 * NO LLAMA A NADIE. Su única petición es un GET de solo lectura al agente
 * configurado, para comprobar la clave ({@see verifyCredentials()}), y en
 * este hito solo lo lanza la consola. No hay ningún método que inicie,
 * registre ni transfiera una llamada.
 *
 * Lee los secretos de la configuración en el momento de usarlos y sin
 * guardarlos en una propiedad, un resultado, un registro ni el mensaje de una
 * excepción. En el H6 era el único lector; desde el H8 (D8-5) los lectores
 * están ENUMERADOS (la clave también la leen los adaptadores de D8-3, y el
 * secreto del webhook el verificador) y este fichero sigue siendo el único
 * punto de las huellas de los de ElevenLabs ({@see secretFingerprints()}).
 * `VoiceIsolationTest` (SEC8) lo vigila.
 */
final class ElevenLabsVoiceProvider implements VoiceAgentProvider
{
    /** `GET {base}/v1/convai/agents/{agent_id}`. */
    public const PATH = '/v1/convai/agents/';

    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** El formato que exige Twilio en los dos sentidos (μ-law 8000 Hz). */
    public const ULAW_8000 = 'ulaw_8000';

    /** Las huellas de los secretos son los primeros 8 hexadecimales de un HMAC-SHA256. */
    public const FINGERPRINT_HEX_LENGTH = 8;

    public function id(): string
    {
        return VoiceProviderStatus::PROVIDER_ELEVENLABS;
    }

    public function status(): VoiceProviderStatus
    {
        return ElevenLabsConfig::fromConfig()->status();
    }

    /**
     * GET {baseUrl}/v1/convai/agents/{agent_id} con las cabeceras xi-api-key y Accept: application/json, y timeout voice.elevenlabs.timeout_seconds.
     * 200 → credentials_ok (agentAudioUlaw8000 = true/false si la respuesta trae conversation_config.tts.agent_output_audio_format
     *       y conversation_config.asr.user_input_audio_format —nombres confirmados en la referencia oficial del 31-10-2026, a revisar al activar—; si no, null);
     * 401/403 → invalid_credentials; 404 → agent_not_found; sin conexión o tiempo agotado → unreachable; otro → error.
     * Nunca registra ni devuelve la clave, ni el cuerpo de la respuesta.
     *
     * Además (defensa, fuera de la tabla del contrato):
     *  - Vuelve a mirar la configuración antes de salir: si el estado no es
     *    `ELEVENLABS_UNVERIFIED` (bandera apagada o una pieza que falta)
     *    responde `not_configured` SIN RED, aunque alguien instancie esta
     *    clase a mano saltándose la fábrica.
     *  - Un 200 cuyo cuerpo no es un objeto JSON es `error`: sin la ficha del
     *    agente no hay prueba de que la clave abra ESE agente.
     *  - Sin redirecciones (la clave no viaja a otro host) y sin reintentos.
     */
    public function verifyCredentials(): VoiceCheckResult
    {
        $config = ElevenLabsConfig::fromConfig();
        $base = $config->baseUrl();
        $agente = $config->agentId();
        if ($config->status()->state !== VoiceProviderStatus::STATE_UNVERIFIED || $base === null || $agente === null) {
            return new VoiceCheckResult(VoiceCheckResult::OUTCOME_NOT_CONFIGURED, null, null, 0);
        }
        $espera = $config->timeoutSeconds();

        $inicio = hrtime(true);
        try {
            $respuesta = Http::withHeaders(['xi-api-key' => trim((string) config('voice.elevenlabs.api_key'))])
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $espera))
                ->timeout($espera)
                ->get($base.self::PATH.rawurlencode($agente));
        } catch (StrayRequestException $e) {
            // Solo existe en las pruebas (`Http::preventStrayRequests()`): que se vea.
            throw $e;
        } catch (ConnectionException) {
            // Sin conexión, sin resolver el nombre, TLS fallido o tiempo agotado.
            return new VoiceCheckResult(VoiceCheckResult::OUTCOME_UNREACHABLE, null, null, self::since($inicio));
        } catch (Throwable) {
            // Cualquier otro fallo del cliente (p. ej., una cabecera que no es
            // RFC 7230). Ni la clase ni el mensaje salen: el mensaje puede
            // llevar el valor de la cabecera.
            return new VoiceCheckResult(VoiceCheckResult::OUTCOME_ERROR, null, null, self::since($inicio));
        }

        $latencia = self::since($inicio);
        $http = $respuesta->status();
        if ($http < 100 || $http > 599) {
            return new VoiceCheckResult(VoiceCheckResult::OUTCOME_ERROR, null, null, $latencia);
        }

        return match (true) {
            $http === 200 => self::fromAgent($respuesta->body(), $latencia),
            $http === 401, $http === 403 => new VoiceCheckResult(VoiceCheckResult::OUTCOME_INVALID_CREDENTIALS, $http, null, $latencia),
            $http === 404 => new VoiceCheckResult(VoiceCheckResult::OUTCOME_AGENT_NOT_FOUND, $http, null, $latencia),
            default => new VoiceCheckResult(VoiceCheckResult::OUTCOME_ERROR, $http, null, $latencia),
        };
    }

    /**
     * Las huellas de los secretos de ElevenLabs, sin red: los primeros 8
     * hexadecimales de `hash_hmac('sha256', <secreto recortado>, $key)`, o null
     * si el secreto no está. Para que quien describa la configuración pueda
     * decir «cambió» sin leer los secretos: la clave de las huellas la pone
     * quien llama, y de aquí solo salen las huellas.
     *
     * Contrato H8, D8-5: gana la del secreto del webhook de iniciación
     * (`initiation_secret`), al final, para que las tres del H6 no se muevan.
     * El único punto de las huellas de ElevenLabs: S8 la consume en
     * `voiceFactsFrom` y no la duplica.
     *
     * @return array{api_key: ?string, webhook_secret: ?string, tool_secret: ?string, initiation_secret: ?string}
     *
     * @throws InvalidArgumentException si $key === '' (una huella sin clave se podría comprobar contra un diccionario)
     */
    public static function secretFingerprints(#[\SensitiveParameter] string $key): array
    {
        if ($key === '') {
            throw new InvalidArgumentException('La clave de las huellas no puede estar vacía.');
        }
        $huella = static function (mixed $valor) use ($key): ?string {
            $valor = is_string($valor) ? trim($valor) : '';

            return $valor === '' ? null : substr(hash_hmac('sha256', $valor, $key), 0, self::FINGERPRINT_HEX_LENGTH);
        };

        return [
            'api_key' => $huella(config('voice.elevenlabs.api_key')),
            'webhook_secret' => $huella(config('voice.elevenlabs.webhook_secret')),
            'tool_secret' => $huella(config('voice.elevenlabs.tool_secret')),
            'initiation_secret' => $huella(config('voice.elevenlabs.initiation_secret')),
        ];
    }

    /** Un 200: la ficha del agente tiene que ser un objeto JSON; de ella solo se lee el formato de audio. */
    private static function fromAgent(string $cuerpo, int $latencia): VoiceCheckResult
    {
        $agente = json_decode($cuerpo);
        if (! $agente instanceof stdClass) {
            return new VoiceCheckResult(VoiceCheckResult::OUTCOME_ERROR, 200, null, $latencia);
        }

        return new VoiceCheckResult(VoiceCheckResult::OUTCOME_CREDENTIALS_OK, 200, self::ulaw8000($agente), $latencia);
    }

    /**
     * ¿Habla el agente en μ-law 8000 en los dos sentidos? true/false solo si
     * la ficha trae los dos formatos como texto; si falta alguno, null.
     */
    private static function ulaw8000(stdClass $agente): ?bool
    {
        $conversacion = $agente->conversation_config ?? null;
        if (! $conversacion instanceof stdClass) {
            return null;
        }
        $tts = $conversacion->tts ?? null;
        $asr = $conversacion->asr ?? null;
        $salida = $tts instanceof stdClass ? ($tts->agent_output_audio_format ?? null) : null;
        $entrada = $asr instanceof stdClass ? ($asr->user_input_audio_format ?? null) : null;
        if (! is_string($salida) || ! is_string($entrada)) {
            return null;
        }

        return $salida === self::ULAW_8000 && $entrada === self::ULAW_8000;
    }

    private static function since(int $inicio): int
    {
        return intdiv(max(0, hrtime(true) - $inicio), 1_000_000);
    }
}
