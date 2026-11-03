<?php

namespace App\Services\Voice\ElevenLabs;

use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceDeployResult;
use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\VoiceAgentDeployer;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceProviderStatus;
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
 *  - `PATCH /v1/convai/phone-numbers/{phone_number_id}` (`{agent_id}`).
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
final class ElevenLabsAgentDeployer implements VoiceAgentDeployer
{
    public const CREATE_AGENT_PATH = '/v1/convai/agents/create';

    public const AGENT_PATH = '/v1/convai/agents/';

    public const TOOLS_PATH = '/v1/convai/tools';

    public const PHONE_NUMBERS_PATH = '/v1/convai/phone-numbers/';

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

    // ── Piezas puras ────────────────────────────────────────────────────────

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
