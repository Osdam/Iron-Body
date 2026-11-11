<?php

namespace App\Services\Voice\ElevenLabs;

use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceDeployResult;
use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\Dto\VoiceNumberAssignment;
use App\Services\Voice\Dto\VoiceSecretList;
use App\Services\Voice\Dto\VoiceWebhookList;
use App\Services\Voice\VoiceAgentDeployer;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceNumberObserver;
use App\Services\Voice\VoiceProviderStatus;
use App\Services\Voice\VoiceWebhookObserver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * El desplegador de agentes de ElevenLabs (contrato H8, D8-3 y D8-7). Rutas
 * comprobadas en la referencia oficial el 03-11-2026:
 *  - `POST /v1/convai/tools` (`{tool_config}` → `{id}`);
 *  - `POST /v1/convai/agents/create` (→ `{agent_id}`);
 *  - `GET  /v1/convai/agents/{agent_id}` y `GET /v1/convai/tools/{tool_id}`;
 *  - `PATCH /v1/convai/phone-numbers/{phone_number_id}` (`{agent_id}`);
 *  - `GET  /v1/convai/phone-numbers/{phone_number_id}` (→ `assigned_agent`),
 *    para la deriva del número (Enmienda 4, E4-8; {@see VoiceNumberObserver}).
 *  - Auditoría B-T5 (D8-21, decisión (f); {@see VoiceWebhookObserver}), las
 *    dos que comprobó OPS8-c en la referencia oficial el 09-11-2026
 *    (`ops/h8-secrets-register.sh`): `GET /v1/workspace/webhooks` (→
 *    `{webhooks: [{webhook_id, webhook_url, is_disabled, is_auto_disabled,
 *    auth_type, …}]}`) y `GET /v1/convai/secrets?page_size=` (→ `{secrets:
 *    [{type, secret_id, name, used_by}], next_cursor}`), para la salud del
 *    webhook. Sin el valor de ningún secreto: el proveedor no lo devuelve.
 *
 * Reglas:
 *  - Antes de salir vuelve a mirar la configuración: si el estado no es
 *    `ELEVENLABS_UNVERIFIED` (bandera apagada o una pieza que falta) responde
 *    `not_configured` SIN RED, aunque alguien instancie la clase a mano.
 *  - Hosts fijos en `production` ({@see ElevenLabsConfig::baseUrl()}).
 *  - Lee la clave en el momento de usarla, sin guardarla en una propiedad, un
 *    resultado ni el mensaje de una excepción (D8-5: lector enumerado).
 *  - Sin redirecciones (la clave no viaja a otro host) y sin reintentos.
 *  - Nunca lanza por la red: los fallos son resultados. En una creación, un
 *    tiempo agotado, un 5xx o un 2xx ilegible son `ambiguous`: el recurso
 *    puede existir (inerte: sin número y sin llamadas).
 *  - Asignar un agente al número abre las entrantes: con
 *    `VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false` es `not_attempted`, sin
 *    red. Dejar el número sin agente (null) siempre se puede: apagar no
 *    necesita el giro.
 *  - Nunca modifica un agente: no hay ningún PATCH ni DELETE de agentes.
 */
final class ElevenLabsAgentDeployer implements VoiceAgentDeployer, VoiceNumberObserver, VoiceWebhookObserver
{
    public const CREATE_AGENT_PATH = '/v1/convai/agents/create';

    public const AGENT_PATH = '/v1/convai/agents/';

    public const TOOLS_PATH = '/v1/convai/tools';

    public const PHONE_NUMBERS_PATH = '/v1/convai/phone-numbers/';

    /** B-T5: la lista de webhooks del workspace (solo GET). */
    public const WEBHOOKS_PATH = '/v1/workspace/webhooks';

    /** B-T5: la lista de secretos guardados del workspace (solo GET; nunca devuelve valores). */
    public const SECRETS_PATH = '/v1/convai/secrets';

    /** B-T5: UNA página de secretos (la del registro de OPS8-c); si hay más, la lectura lo dice (`truncated`). */
    public const SECRETS_PAGE_SIZE = 100;

    public const CONNECT_TIMEOUT_SECONDS = 5;

    private const JSON_MAX_DEPTH = 64;

    public function createAgent(VoiceAgentPayload $payload): VoiceDeployResult
    {
        $config = ElevenLabsConfig::fromConfig();
        $base = $config->baseUrl();
        if ($config->status()->state !== VoiceProviderStatus::STATE_UNVERIFIED || $base === null) {
            return new VoiceDeployResult(VoiceDeployResult::OUTCOME_NOT_CONFIGURED, null, [], null, null);
        }
        if ($payload->provider !== VoiceAgentPayload::PROVIDER_ELEVENLABS) {
            return new VoiceDeployResult(VoiceDeployResult::OUTCOME_NOT_ATTEMPTED, null, [], null, VoiceDeployResult::REASON_INVALID_REQUEST);
        }
        $espera = $config->timeoutSeconds();

        // 1. Las herramientas, en orden de nombre (sus ids no entran en ninguna huella).
        $ids = [];
        foreach ($payload->tools() as $nombre => $herramienta) {
            try {
                $respuesta = Http::withHeaders(['xi-api-key' => trim((string) config('voice.elevenlabs.api_key'))])
                    ->acceptJson()
                    ->asJson()
                    ->withoutRedirecting()
                    ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $espera))
                    ->timeout($espera)
                    ->post($base.self::TOOLS_PATH, ['tool_config' => $herramienta]);
            } catch (StrayRequestException $e) {
                // Solo existe en las pruebas (`Http::preventStrayRequests()`): que se vea.
                throw $e;
            } catch (ConnectionException) {
                return new VoiceDeployResult(VoiceDeployResult::OUTCOME_AMBIGUOUS, null, $ids, null, VoiceDeployResult::REASON_TOOL_STEP);
            } catch (Throwable) {
                // Otro fallo del cliente con la petición en marcha: puede haberse
                // creado. Ni la clase ni el mensaje salen (pueden llevar la cabecera).
                return new VoiceDeployResult(VoiceDeployResult::OUTCOME_AMBIGUOUS, null, $ids, null, VoiceDeployResult::REASON_TOOL_STEP);
            }
            [$resultado, $id, $estado] = self::created($respuesta, 'id');
            if ($resultado !== VoiceDeployResult::OUTCOME_CREATED) {
                return new VoiceDeployResult($resultado, null, $ids, $estado, VoiceDeployResult::REASON_TOOL_STEP);
            }
            $ids[$nombre] = $id;
        }

        // 2. El agente, con los ids de sus herramientas.
        $cuerpo = $payload->agentBody();
        $cuerpo['conversation_config']['agent']['prompt']['tool_ids'] = array_values($ids);
        try {
            $respuesta = Http::withHeaders(['xi-api-key' => trim((string) config('voice.elevenlabs.api_key'))])
                ->acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $espera))
                ->timeout($espera)
                ->post($base.self::CREATE_AGENT_PATH, $cuerpo);
        } catch (StrayRequestException $e) {
            throw $e;
        } catch (ConnectionException) {
            return new VoiceDeployResult(VoiceDeployResult::OUTCOME_AMBIGUOUS, null, $ids, null, VoiceDeployResult::REASON_AGENT_STEP);
        } catch (Throwable) {
            return new VoiceDeployResult(VoiceDeployResult::OUTCOME_AMBIGUOUS, null, $ids, null, VoiceDeployResult::REASON_AGENT_STEP);
        }
        [$resultado, $agente, $estado] = self::created($respuesta, 'agent_id');
        if ($resultado !== VoiceDeployResult::OUTCOME_CREATED) {
            return new VoiceDeployResult($resultado, null, $ids, $estado, VoiceDeployResult::REASON_AGENT_STEP);
        }

        return new VoiceDeployResult(VoiceDeployResult::OUTCOME_CREATED, $agente, $ids, $estado, null);
    }

    public function describeAgent(string $agentId): VoiceAgentObservation
    {
        $config = ElevenLabsConfig::fromConfig();
        $base = $config->baseUrl();
        if ($config->status()->state !== VoiceProviderStatus::STATE_UNVERIFIED || $base === null) {
            return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_NOT_CONFIGURED, null, null, null, null, null, null);
        }
        if (! VoiceIds::isProviderId($agentId)) {
            return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_ERROR, null, null, null, null, null, VoiceAgentObservation::REASON_INVALID_REQUEST);
        }
        $espera = $config->timeoutSeconds();

        // 1. El agente.
        try {
            $respuesta = Http::withHeaders(['xi-api-key' => trim((string) config('voice.elevenlabs.api_key'))])
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $espera))
                ->timeout($espera)
                ->get($base.self::AGENT_PATH.rawurlencode($agentId));
        } catch (StrayRequestException $e) {
            throw $e;
        } catch (ConnectionException) {
            return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_UNREACHABLE, $agentId, null, null, null, null, null);
        } catch (Throwable) {
            return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_ERROR, $agentId, null, null, null, null, null);
        }
        $fallo = self::readFailure($respuesta, $agentId, null);
        if ($fallo !== null) {
            return $fallo;
        }
        $agente = self::jsonObject($respuesta);
        $idsHerramientas = $agente === null ? null : self::toolIds($agente);
        if ($agente === null || $idsHerramientas === null) {
            return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_ERROR, $agentId, null, null, null, $respuesta->status(), VoiceAgentObservation::REASON_INVALID_RESPONSE);
        }
        if (count($idsHerramientas) > VoiceAgentPayload::MAX_TOOLS) {
            return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_ERROR, $agentId, null, null, null, $respuesta->status(), VoiceAgentObservation::REASON_TOO_MANY_TOOLS);
        }

        // 2. Sus herramientas, una a una (como mucho las del catálogo).
        $herramientas = [];
        foreach ($idsHerramientas as $i => $idHerramienta) {
            try {
                $r = Http::withHeaders(['xi-api-key' => trim((string) config('voice.elevenlabs.api_key'))])
                    ->acceptJson()
                    ->withoutRedirecting()
                    ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $espera))
                    ->timeout($espera)
                    ->get($base.self::TOOLS_PATH.'/'.rawurlencode($idHerramienta));
            } catch (StrayRequestException $e) {
                throw $e;
            } catch (ConnectionException) {
                return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_UNREACHABLE, $agentId, null, null, null, null, VoiceAgentObservation::REASON_TOOL_STEP);
            } catch (Throwable) {
                return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_ERROR, $agentId, null, null, null, null, VoiceAgentObservation::REASON_TOOL_STEP);
            }
            $definicion = $r->successful() ? (self::jsonObject($r)['tool_config'] ?? null) : null;
            if (! is_array($definicion) || array_is_list($definicion)) {
                $estado = $r->status();

                return new VoiceAgentObservation(
                    in_array($estado, [401, 403], true) ? VoiceAgentObservation::OUTCOME_AUTH_FAILED : VoiceAgentObservation::OUTCOME_ERROR,
                    $agentId, null, null, null, $estado >= 100 && $estado <= 599 ? $estado : null, VoiceAgentObservation::REASON_TOOL_STEP,
                );
            }
            // Por nombre; un nombre repetido o ausente queda a la vista como deriva, no se fusiona.
            $nombre = is_string($definicion['name'] ?? null) && $definicion['name'] !== '' ? $definicion['name'] : '(sin nombre)';
            $clave = array_key_exists($nombre, $herramientas) ? $nombre.'#'.($i + 1) : $nombre;
            $herramientas[$clave] = $definicion;
        }

        return new VoiceAgentObservation(
            VoiceAgentObservation::OUTCOME_OBSERVED,
            $agentId,
            VoiceIds::providerIdOrNull($agente['version_id'] ?? null),
            VoiceIds::providerIdOrNull($agente['branch_id'] ?? null),
            ElevenLabsAgentMapper::projection($agente, $herramientas),
            $respuesta->status(),
            null,
        );
    }

    public function assignNumber(string $providerNumberId, ?string $agentId): VoiceDeployResult
    {
        // Abrir las entrantes exige el giro del código; apagarlas, no.
        if ($agentId !== null && VoiceCallPolicy::CALLS_ENABLED_IN_CODE !== true) {
            return new VoiceDeployResult(VoiceDeployResult::OUTCOME_NOT_ATTEMPTED, null, [], null, VoiceDeployResult::REASON_CALLS_DISABLED_IN_CODE);
        }
        $config = ElevenLabsConfig::fromConfig();
        $base = $config->baseUrl();
        if ($config->status()->state !== VoiceProviderStatus::STATE_UNVERIFIED || $base === null) {
            return new VoiceDeployResult(VoiceDeployResult::OUTCOME_NOT_CONFIGURED, null, [], null, null);
        }
        if (! VoiceIds::isProviderId($providerNumberId) || ($agentId !== null && ! VoiceIds::isProviderId($agentId))) {
            return new VoiceDeployResult(VoiceDeployResult::OUTCOME_NOT_ATTEMPTED, null, [], null, VoiceDeployResult::REASON_INVALID_REQUEST);
        }
        $espera = $config->timeoutSeconds();

        try {
            $respuesta = Http::withHeaders(['xi-api-key' => trim((string) config('voice.elevenlabs.api_key'))])
                ->acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $espera))
                ->timeout($espera)
                ->patch($base.self::PHONE_NUMBERS_PATH.rawurlencode($providerNumberId), ['agent_id' => $agentId]);
        } catch (StrayRequestException $e) {
            throw $e;
        } catch (ConnectionException) {
            // Repetir la misma asignación no hace daño: quien la pidió puede volver a pedirla.
            return new VoiceDeployResult(VoiceDeployResult::OUTCOME_AMBIGUOUS, null, [], null, null);
        } catch (Throwable) {
            return new VoiceDeployResult(VoiceDeployResult::OUTCOME_AMBIGUOUS, null, [], null, null);
        }

        $estado = $respuesta->status();
        if ($estado < 200 || $estado > 299) {
            return new VoiceDeployResult(self::failureOutcome($estado), null, [], $estado >= 100 && $estado <= 599 ? $estado : null, null);
        }
        $numero = self::jsonObject($respuesta);
        if ($numero === null || ! array_key_exists('assigned_agent', $numero)) {
            return new VoiceDeployResult(VoiceDeployResult::OUTCOME_ERROR, null, [], $estado, VoiceDeployResult::REASON_INVALID_RESPONSE);
        }
        $asignado = $numero['assigned_agent'];
        $observado = is_array($asignado) ? VoiceIds::providerIdOrNull($asignado['agent_id'] ?? null) : null;
        if (($asignado !== null && $observado === null) || $observado !== $agentId) {
            return new VoiceDeployResult(VoiceDeployResult::OUTCOME_ERROR, $observado, [], $estado, VoiceDeployResult::REASON_ASSIGNMENT_MISMATCH);
        }

        return new VoiceDeployResult(VoiceDeployResult::OUTCOME_ASSIGNED, $observado, [], $estado, null);
    }

    /**
     * E4-8: a qué agente apunta el número importado. Solo GET, UNA petición;
     * del cuerpo solo se toman `assigned_agent.agent_id` y `supports_inbound`
     * (el teléfono y la etiqueta que trae la respuesta no salen de aquí). Sin la
     * bandera o sin todas las piezas, «desconocido» sin red.
     *
     * B8-g, con la referencia oficial (`GET /v1/convai/phone-numbers/{id}`,
     * comprobada el 09-11-2026): `assigned_agent` es OPCIONAL (un objeto o
     * null), así que su ausencia es «sin asignar», como el null; un valor con
     * otra forma sigue siendo ilegible. `supports_inbound` (booleano, obsoleto,
     * por defecto true) da `inboundCapable`: un número «Outbound Only» (caller
     * ID verificado en Twilio) dice false; sin el campo, null. Y `phone_number`
     * (SEC8-c B-1), el E.164 con el que ElevenLabs llama de verdad, sale solo
     * en memoria ({@see VoiceNumberAssignment::phoneNumber()}); sin un E.164
     * estricto, null.
     */
    public function describeNumber(string $phoneNumberId): VoiceNumberAssignment
    {
        $config = ElevenLabsConfig::fromConfig();
        $base = $config->baseUrl();
        if ($config->status()->state !== VoiceProviderStatus::STATE_UNVERIFIED || $base === null) {
            return VoiceNumberAssignment::unknown();
        }
        if (! VoiceIds::isProviderId($phoneNumberId)) {
            return new VoiceNumberAssignment(VoiceNumberAssignment::OUTCOME_ERROR, null, null, null, VoiceNumberAssignment::REASON_INVALID_REQUEST);
        }
        $espera = $config->timeoutSeconds();

        try {
            $respuesta = Http::withHeaders(['xi-api-key' => trim((string) config('voice.elevenlabs.api_key'))])
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $espera))
                ->timeout($espera)
                ->get($base.self::PHONE_NUMBERS_PATH.rawurlencode($phoneNumberId));
        } catch (StrayRequestException $e) {
            throw $e;
        } catch (ConnectionException) {
            return new VoiceNumberAssignment(VoiceNumberAssignment::OUTCOME_UNREACHABLE, $phoneNumberId, null, null, null);
        } catch (Throwable) {
            return new VoiceNumberAssignment(VoiceNumberAssignment::OUTCOME_ERROR, $phoneNumberId, null, null, null);
        }

        $estado = $respuesta->status();
        $http = $estado >= 100 && $estado <= 599 ? $estado : null;
        if ($estado < 200 || $estado > 299) {
            $resultado = match (true) {
                $estado === 401, $estado === 403 => VoiceNumberAssignment::OUTCOME_AUTH_FAILED,
                $estado === 404 => VoiceNumberAssignment::OUTCOME_NOT_FOUND,
                default => VoiceNumberAssignment::OUTCOME_ERROR,
            };

            return new VoiceNumberAssignment($resultado, $phoneNumberId, null, $http, null);
        }
        $numero = self::jsonObject($respuesta);
        if ($numero === null) {
            return new VoiceNumberAssignment(VoiceNumberAssignment::OUTCOME_ERROR, $phoneNumberId, null, $http, VoiceNumberAssignment::REASON_INVALID_RESPONSE);
        }
        // Ausente (el campo es opcional) o null: sin asignar.
        $asignado = $numero['assigned_agent'] ?? null;
        $agente = is_array($asignado) ? VoiceIds::providerIdOrNull($asignado['agent_id'] ?? null) : null;
        if ($asignado !== null && $agente === null) {
            // Un agente asignado sin id legible no es «sin asignar»: no se sabe.
            return new VoiceNumberAssignment(VoiceNumberAssignment::OUTCOME_ERROR, $phoneNumberId, null, $http, VoiceNumberAssignment::REASON_INVALID_RESPONSE);
        }
        $entrantes = $numero['supports_inbound'] ?? null;
        $llama = is_string($numero['phone_number'] ?? null) && preg_match(VoiceAgentSpec::E164_PATTERN, $numero['phone_number']) === 1 ? $numero['phone_number'] : null;
        unset($numero);

        return new VoiceNumberAssignment(VoiceNumberAssignment::OUTCOME_OBSERVED, $phoneNumberId, $agente, $http, null, is_bool($entrantes) ? $entrantes : null, $llama);
    }

    /**
     * Auditoría B-T5: los webhooks del workspace, un GET. De cada uno solo su
     * id, la URL de entrega, si está desactivado (a mano o solo) y su
     * autenticación; un elemento sin id legible hace ilegible la lista entera
     * (no se puede afirmar que el de la referencia falte o esté).
     */
    public function describeWebhooks(): VoiceWebhookList
    {
        $config = ElevenLabsConfig::fromConfig();
        $base = $config->baseUrl();
        if ($config->status()->state !== VoiceProviderStatus::STATE_UNVERIFIED || $base === null) {
            return VoiceWebhookList::unknown();
        }
        $espera = $config->timeoutSeconds();

        try {
            $respuesta = Http::withHeaders(['xi-api-key' => trim((string) config('voice.elevenlabs.api_key'))])
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $espera))
                ->timeout($espera)
                ->get($base.self::WEBHOOKS_PATH);
        } catch (StrayRequestException $e) {
            throw $e;
        } catch (ConnectionException) {
            return new VoiceWebhookList(VoiceWebhookList::OUTCOME_UNREACHABLE, [], null, null);
        } catch (Throwable) {
            return new VoiceWebhookList(VoiceWebhookList::OUTCOME_ERROR, [], null, null);
        }

        [$fallo, $http] = self::listFailure($respuesta);
        if ($fallo !== null) {
            return new VoiceWebhookList($fallo, [], $http, null);
        }
        $lista = self::jsonObject($respuesta)['webhooks'] ?? null;
        if (! is_array($lista) || ! array_is_list($lista)) {
            return new VoiceWebhookList(VoiceWebhookList::OUTCOME_ERROR, [], $http, VoiceWebhookList::REASON_INVALID_RESPONSE);
        }
        $webhooks = [];
        foreach ($lista as $w) {
            $id = is_array($w) ? VoiceIds::providerIdOrNull($w['webhook_id'] ?? null) : null;
            if ($id === null) {
                return new VoiceWebhookList(VoiceWebhookList::OUTCOME_ERROR, [], $http, VoiceWebhookList::REASON_INVALID_RESPONSE);
            }
            $url = $w['webhook_url'] ?? null;
            $auth = $w['auth_type'] ?? null;
            $webhooks[] = [
                'id' => $id,
                'url' => is_string($url) && $url !== '' && strlen($url) <= VoiceWebhookList::URL_MAX ? $url : null,
                'disabled' => is_bool($w['is_disabled'] ?? null) ? $w['is_disabled'] : null,
                'autoDisabled' => is_bool($w['is_auto_disabled'] ?? null) ? $w['is_auto_disabled'] : null,
                'authType' => is_string($auth) && preg_match(VoiceWebhookList::AUTH_TYPE_PATTERN, $auth) === 1 ? $auth : null,
            ];
        }
        unset($lista);

        return new VoiceWebhookList(VoiceWebhookList::OUTCOME_OBSERVED, $webhooks, $http, null);
    }

    /**
     * Auditoría B-T5: los ids de los secretos guardados del workspace, un GET
     * de una página. Ni nombres ni valores salen de aquí. Un elemento sin id
     * legible hace ilegible la lista entera.
     */
    public function describeSecrets(): VoiceSecretList
    {
        $config = ElevenLabsConfig::fromConfig();
        $base = $config->baseUrl();
        if ($config->status()->state !== VoiceProviderStatus::STATE_UNVERIFIED || $base === null) {
            return VoiceSecretList::unknown();
        }
        $espera = $config->timeoutSeconds();

        try {
            $respuesta = Http::withHeaders(['xi-api-key' => trim((string) config('voice.elevenlabs.api_key'))])
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $espera))
                ->timeout($espera)
                ->get($base.self::SECRETS_PATH, ['page_size' => self::SECRETS_PAGE_SIZE]);
        } catch (StrayRequestException $e) {
            throw $e;
        } catch (ConnectionException) {
            return new VoiceSecretList(VoiceSecretList::OUTCOME_UNREACHABLE, [], false, null, null);
        } catch (Throwable) {
            return new VoiceSecretList(VoiceSecretList::OUTCOME_ERROR, [], false, null, null);
        }

        [$fallo, $http] = self::listFailure($respuesta);
        if ($fallo !== null) {
            return new VoiceSecretList($fallo, [], false, $http, null);
        }
        $cuerpo = self::jsonObject($respuesta);
        $lista = $cuerpo['secrets'] ?? null;
        if (! is_array($lista) || ! array_is_list($lista)) {
            return new VoiceSecretList(VoiceSecretList::OUTCOME_ERROR, [], false, $http, VoiceSecretList::REASON_INVALID_RESPONSE);
        }
        $ids = [];
        foreach ($lista as $secreto) {
            $id = is_array($secreto) ? VoiceIds::providerIdOrNull($secreto['secret_id'] ?? null) : null;
            if ($id === null) {
                return new VoiceSecretList(VoiceSecretList::OUTCOME_ERROR, [], false, $http, VoiceSecretList::REASON_INVALID_RESPONSE);
            }
            $ids[] = $id;
        }
        $siguiente = $cuerpo['next_cursor'] ?? null;
        unset($cuerpo, $lista);

        return new VoiceSecretList(VoiceSecretList::OUTCOME_OBSERVED, $ids, is_string($siguiente) && $siguiente !== '', $http, null);
    }

    // ── Piezas puras ────────────────────────────────────────────────────────

    /**
     * B-T5: el resultado de un GET de lista que no es 2xx (401/403 →
     * auth_failed; 404 → not_found; otro → error), o null si lo es; y el
     * estado HTTP.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private static function listFailure(Response $respuesta): array
    {
        $estado = $respuesta->status();
        $http = $estado >= 100 && $estado <= 599 ? $estado : null;
        if ($estado >= 200 && $estado <= 299) {
            return [null, $http];
        }

        return [match (true) {
            $estado === 401, $estado === 403 => VoiceWebhookList::OUTCOME_AUTH_FAILED,
            $estado === 404 => VoiceWebhookList::OUTCOME_NOT_FOUND,
            default => VoiceWebhookList::OUTCOME_ERROR,
        }, $http];
    }

    /**
     * El resultado de una creación: 2xx con el id → `created`; 2xx sin él,
     * 3xx, 408 o 5xx → `ambiguous` (puede existir); 401/403 → `auth_failed`;
     * 404 → `not_found`; otro 4xx → `rejected`.
     *
     * @return array{0:string, 1:?string, 2:?int}
     */
    private static function created(Response $respuesta, string $campo): array
    {
        $estado = $respuesta->status();
        $http = $estado >= 100 && $estado <= 599 ? $estado : null;
        if ($estado >= 200 && $estado <= 299) {
            $id = VoiceIds::providerIdOrNull(self::jsonObject($respuesta)[$campo] ?? null);

            return $id === null ? [VoiceDeployResult::OUTCOME_AMBIGUOUS, null, $http] : [VoiceDeployResult::OUTCOME_CREATED, $id, $http];
        }
        $resultado = self::failureOutcome($estado);

        return [$resultado === VoiceDeployResult::OUTCOME_ERROR ? VoiceDeployResult::OUTCOME_AMBIGUOUS : $resultado, null, $http];
    }

    /** Un estado que no es 2xx: 401/403 → auth_failed; 404 → not_found; 408 y 5xx → ambiguous; otro 4xx → rejected; lo demás → error. */
    private static function failureOutcome(int $estado): string
    {
        return match (true) {
            $estado === 401, $estado === 403 => VoiceDeployResult::OUTCOME_AUTH_FAILED,
            $estado === 404 => VoiceDeployResult::OUTCOME_NOT_FOUND,
            $estado === 408, $estado >= 500 && $estado <= 599 => VoiceDeployResult::OUTCOME_AMBIGUOUS,
            $estado >= 400 && $estado <= 499 => VoiceDeployResult::OUTCOME_REJECTED,
            default => VoiceDeployResult::OUTCOME_ERROR,
        };
    }

    /** La observación de un GET que no es 2xx; null si lo es. */
    private static function readFailure(Response $respuesta, string $agentId, ?string $motivo): ?VoiceAgentObservation
    {
        $estado = $respuesta->status();
        if ($estado >= 200 && $estado <= 299) {
            return null;
        }
        $resultado = match (true) {
            $estado === 401, $estado === 403 => VoiceAgentObservation::OUTCOME_AUTH_FAILED,
            $estado === 404 => VoiceAgentObservation::OUTCOME_NOT_FOUND,
            default => VoiceAgentObservation::OUTCOME_ERROR,
        };

        return new VoiceAgentObservation($resultado, $agentId, null, null, null, $estado >= 100 && $estado <= 599 ? $estado : null, $motivo);
    }

    /** @return array<string, mixed>|null el cuerpo si es un objeto JSON */
    private static function jsonObject(Response $respuesta): ?array
    {
        $cuerpo = json_decode($respuesta->body(), true, self::JSON_MAX_DEPTH);

        return is_array($cuerpo) && ($cuerpo === [] || ! array_is_list($cuerpo)) ? $cuerpo : null;
    }

    /**
     * Los ids de las herramientas del agente (`conversation_config.agent.prompt.tool_ids`):
     * una lista de ids con forma; ausente = ninguna; con otra forma = null.
     *
     * @param  array<string, mixed>  $agente
     * @return list<string>|null
     */
    private static function toolIds(array $agente): ?array
    {
        $ids = $agente['conversation_config']['agent']['prompt']['tool_ids'] ?? [];
        if (! is_array($ids) || ! array_is_list($ids)) {
            return null;
        }
        foreach ($ids as $id) {
            if (! VoiceIds::isProviderId($id)) {
                return null;
            }
        }

        return $ids;
    }
}
