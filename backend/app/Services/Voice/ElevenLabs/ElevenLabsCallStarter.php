<?php

namespace App\Services\Voice\ElevenLabs;

use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceCallStarter;
use App\Services\Voice\VoiceProviderStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * La única puerta de red de una llamada saliente (contrato H8, D8-3 y D8-12):
 * `POST /v1/convai/twilio/outbound-call` de la integración nativa (referencia
 * oficial comprobada el 03-11-2026). Este es el ÚNICO fichero que nombra esa
 * ruta (D8-2; la guarda estática de SEC8 lo vigila).
 *
 * CERRADA EN EL CÓDIGO: con `VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false`
 * responde `not_attempted` (`calls_disabled_in_code`) y NO SALE NADA, aunque
 * la configuración esté completa y alguien instancie la clase a mano. La
 * constante solo se gira en el commit `voice(calls-open)` con la autorización
 * escrita del propietario, y nunca en el árbol compartido para probar.
 *
 * Además, como el resto del espacio:
 *  - con un estado que no es `ELEVENLABS_UNVERIFIED`, `not_attempted`
 *    (`not_configured`) sin red;
 *  - hosts fijos en `production`; la clave, leída en el momento de usarla;
 *  - UNA petición, sin reintentos ni redirecciones, con un plazo menor que el
 *    del job ({@see MAX_TIMEOUT_SECONDS} < 30 s);
 *  - grabación apagada en el proveedor y en el operador [P-7];
 *  - el número va en el cuerpo y en ningún otro sitio: ni en el resultado, ni
 *    en un registro, ni en el mensaje de una excepción.
 *
 * La tabla de resultados es {@see resultFrom()} (pura, con sus pruebas):
 * `started` solo con `success = true` y los dos ids; `rejected` con
 * `success = false` o un 4xx que no es 408; todo lo demás `ambiguous`.
 */
final class ElevenLabsCallStarter implements VoiceCallStarter
{
    public const PATH = '/v1/convai/twilio/outbound-call';

    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** Por debajo del plazo de 30 s del job (D8-12): el job nunca muere con la petición en el aire sin saberlo. */
    public const MAX_TIMEOUT_SECONDS = 20;

    /** [P-10] colgar sin dejar mensaje: la detección de contestador cuelga ante una máquina o un fax. */
    public const MACHINE_ANSWERS = ['fax', 'machine_start'];

    private const JSON_MAX_DEPTH = 16;

    public function startOutbound(OutboundCallRequest $request): OutboundCallResult
    {
        if (VoiceCallPolicy::CALLS_ENABLED_IN_CODE !== true) {
            return new OutboundCallResult(OutboundCallResult::OUTCOME_NOT_ATTEMPTED, OutboundCallResult::REASON_CALLS_DISABLED_IN_CODE, null, null, null);
        }
        $config = ElevenLabsConfig::fromConfig();
        $base = $config->baseUrl();
        if ($config->status()->state !== VoiceProviderStatus::STATE_UNVERIFIED || $base === null) {
            return new OutboundCallResult(OutboundCallResult::OUTCOME_NOT_ATTEMPTED, OutboundCallResult::REASON_NOT_CONFIGURED, null, null, null);
        }
        $espera = min(self::MAX_TIMEOUT_SECONDS, $config->timeoutSeconds());

        try {
            $respuesta = Http::withHeaders(['xi-api-key' => trim((string) config('voice.elevenlabs.api_key'))])
                ->acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $espera))
                ->timeout($espera)
                ->post($base.self::PATH, self::requestBody($request));
        } catch (StrayRequestException $e) {
            // Solo existe en las pruebas (`Http::preventStrayRequests()`): que se vea.
            throw $e;
        } catch (ConnectionException) {
            // Sin conexión o tiempo agotado: la llamada puede haber salido.
            return new OutboundCallResult(OutboundCallResult::OUTCOME_AMBIGUOUS, OutboundCallResult::REASON_CONNECTION, null, null, null);
        } catch (Throwable) {
            // Otro fallo con la petición en marcha: no prueba que no se marcó.
            // Ni la clase ni el mensaje salen (pueden llevar la cabecera o el cuerpo).
            return new OutboundCallResult(OutboundCallResult::OUTCOME_AMBIGUOUS, OutboundCallResult::REASON_UNEXPECTED, null, null, null);
        }

        return self::resultFrom($respuesta->status(), $respuesta->body());
    }

    /**
     * El cuerpo de la petición (puro). LLEVA EL NÚMERO EN CLARO: se construye
     * al enviar y no se guarda.
     *
     * @return array<string, mixed>
     */
    public static function requestBody(OutboundCallRequest $request): array
    {
        return [
            'agent_id' => $request->agentId,
            'agent_phone_number_id' => $request->phoneNumberRef,
            'to_number' => $request->toNumber(),
            'call_recording_enabled' => false,
            'telephony_call_config' => [
                'ringing_timeout_secs' => $request->ringingTimeoutSeconds,
                'twilio_call_recording_enabled' => false,
                // null = sin detección de contestador (dejar el mensaje, [P-10]).
                'twilio_machine_detection' => $request->voicemailPolicy === VoiceAgentSpec::VOICEMAIL_HANG_UP
                    ? ['mode' => 'enable', 'actions' => [['action' => 'hang_up', 'answered_by' => self::MACHINE_ANSWERS]]]
                    : null,
            ],
            'conversation_initiation_client_data' => [
                'user_id' => $request->callRef,
                'dynamic_variables' => $request->dynamicVariables,
            ],
        ];
    }

    /**
     * La tabla de resultados (pura):
     *  - 2xx + `success === true` + `conversation_id` y `callSid` con forma → `started`;
     *  - 2xx + `success === false` → `rejected` (`provider_declined`), con los ids si vienen;
     *  - 2xx con un cuerpo que no es un objeto JSON → `ambiguous` (`unreadable_response`);
     *  - 2xx con éxito sin los dos ids, o sin un `success` booleano → `ambiguous` (`missing_ids`);
     *  - 408 o 5xx → `ambiguous` (`server_error`);
     *  - otro 4xx → `rejected` (`client_error`);
     *  - cualquier otro estado → `ambiguous` (`unexpected`).
     */
    public static function resultFrom(int $status, string $body): OutboundCallResult
    {
        $http = $status >= 100 && $status <= 599 ? $status : null;

        if ($status >= 200 && $status <= 299) {
            $json = json_decode($body, true, self::JSON_MAX_DEPTH);
            if (! is_array($json) || array_is_list($json)) {
                return new OutboundCallResult(OutboundCallResult::OUTCOME_AMBIGUOUS, OutboundCallResult::REASON_UNREADABLE_RESPONSE, null, null, $http);
            }
            $conversacion = VoiceIds::providerIdOrNull($json['conversation_id'] ?? null);
            $sid = VoiceIds::callSidOrNull($json['callSid'] ?? null);
            $exito = $json['success'] ?? null;
            unset($json);

            return match (true) {
                $exito === true && $conversacion !== null && $sid !== null => new OutboundCallResult(OutboundCallResult::OUTCOME_STARTED, null, $conversacion, $sid, $http),
                $exito === false => new OutboundCallResult(OutboundCallResult::OUTCOME_REJECTED, OutboundCallResult::REASON_PROVIDER_DECLINED, $conversacion, $sid, $http),
                default => new OutboundCallResult(OutboundCallResult::OUTCOME_AMBIGUOUS, OutboundCallResult::REASON_MISSING_IDS, $conversacion, $sid, $http),
            };
        }
        if ($status === 408 || ($status >= 500 && $status <= 599)) {
            return new OutboundCallResult(OutboundCallResult::OUTCOME_AMBIGUOUS, OutboundCallResult::REASON_SERVER_ERROR, null, null, $http);
        }
        if ($status >= 400 && $status <= 499) {
            return new OutboundCallResult(OutboundCallResult::OUTCOME_REJECTED, OutboundCallResult::REASON_CLIENT_ERROR, null, null, $http);
        }

        return new OutboundCallResult(OutboundCallResult::OUTCOME_AMBIGUOUS, OutboundCallResult::REASON_UNEXPECTED, null, null, $http);
    }
}
