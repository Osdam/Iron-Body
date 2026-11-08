<?php

namespace App\Services\UltronStudio;

use App\Models\UltronStudio\StudioVoiceAgent;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\VoiceAdapters;
use App\Services\Voice\VoiceAgentDeployer;

/**
 * LA DERIVA DE LA VOZ (contrato H8, D8-7 paso 3, D8-21 y E2-3): lo DESPLEGADO
 * frente a lo PUBLICADO. Un cambio hecho en el panel de ElevenLabs no es una
 * vía de cambio: es deriva.
 *
 *  - Del AGENTE: la proyección que se observa al leerlo (`describeAgent`, solo
 *    GET) frente a la compilada que guardó el despliegue (`projection_hash`).
 *    La misma función de proyección para las dos (`ElevenLabsAgentMapper`, B8):
 *    los valores por defecto que añada el proveedor no cuentan; un campo
 *    controlado cambiado, una herramienta de más o una sobrescritura abierta, sí.
 *  - Del NÚMERO (E2-3): el agente al que apunta el número importado, según la
 *    observación que hace la telefonía (B8), frente al agente publicado de la
 *    parte que atiende las entrantes ({@see StudioVoicePublishing::INBOUND_PART}).
 *    La comparación es pura ({@see numberVerdict()}): este fichero no lee el
 *    número.
 *
 * VIGILA, NO ACTÚA: devuelve veredictos. Abrir el freno por una deriva es de la
 * telefonía (D8-19, B8) y programar la vigilancia, de OPS8 (D8-21). Quien
 * publica la usa antes de promover y de revertir: no se promueve ni se vuelve a
 * un agente que ya no es el que se verificó.
 *
 * RED: una GET por agente y una por cada herramienta (hasta 4), solo con el
 * desplegador real (ElevenLabs configurado); con el nulo, `not_configured` sin
 * red. Nunca se llama desde una lectura de la API (cero red en las rutas de
 * lectura, E2-4).
 */
final class StudioVoiceDrift
{
    public const OK = 'ok';

    public const DRIFT = 'drift';

    /** No se pudo leer (sin conexión, 404, 401…): no se sabe; cuenta como no verificado. */
    public const UNREADABLE = 'unreadable';

    public const NOT_CONFIGURED = 'not_configured';

    public const NUMBER_OK = 'ok';

    public const NUMBER_DRIFT = 'number_drift';

    public const NUMBER_UNKNOWN = 'unknown';

    /**
     * @param  VoiceAgentDeployer|null  $deployer  solo las pruebas lo pasan: en producción, {@see VoiceAdapters::deployer()}
     */
    public function __construct(private readonly ?VoiceAgentDeployer $deployer = null) {}

    /**
     * La deriva de los agentes que atienden alguna parte, uno por agente.
     *
     * @return list<array{agentUuid:string,parts:list<string>,providerAgentRef:string|null,outcome:string,expectedHash:string,observedHash:string|null,versionId:string|null}>
     */
    public function published(): array
    {
        $agentes = StudioVoiceAgent::query()
            ->where(fn ($q) => $q->where('serves_canary', true)->orWhere('serves_rest', true))
            ->orderBy('id')
            ->get();

        return $agentes->map(fn (StudioVoiceAgent $a): array => $this->agent($a))->values()->all();
    }

    /**
     * La deriva de UN agente desplegado.
     *
     * @return array{agentUuid:string,parts:list<string>,providerAgentRef:string|null,outcome:string,expectedHash:string,observedHash:string|null,versionId:string|null}
     */
    public function agent(StudioVoiceAgent $agente): array
    {
        $partes = array_values(array_filter([
            $agente->serves_canary ? StudioVoicePublication::SCOPE_CANARY : null,
            $agente->serves_rest ? StudioVoicePublication::SCOPE_REST : null,
        ]));
        $id = $agente->provider_agent_id;
        $observacion = $id === null ? null : $this->deployer()->describeAgent((string) $id);

        return [
            'agentUuid' => (string) $agente->uuid,
            'parts' => $partes,
            'providerAgentRef' => $id === null ? null : '…'.mb_substr((string) $id, -4),
            'outcome' => self::verdict((string) $agente->projection_hash, $observacion),
            'expectedHash' => (string) $agente->projection_hash,
            'observedHash' => $observacion?->projectionHash,
            'versionId' => $observacion?->versionId,
        ];
    }

    /**
     * El veredicto de un agente, puro: `ok` si se observó y la proyección es la
     * compilada; `drift` si se observó y no; `not_configured` sin el
     * proveedor; `unreadable` en cualquier otro caso (falla cerrado: no se
     * sabe, así que no vale como verificado).
     */
    public static function verdict(string $expectedProjectionHash, ?VoiceAgentObservation $observation): string
    {
        if ($observation === null) {
            return self::UNREADABLE;
        }
        if ($observation->outcome === VoiceAgentObservation::OUTCOME_NOT_CONFIGURED) {
            return self::NOT_CONFIGURED;
        }
        if ($observation->outcome !== VoiceAgentObservation::OUTCOME_OBSERVED || $observation->projectionHash === null) {
            return self::UNREADABLE;
        }

        return hash_equals($expectedProjectionHash, $observation->projectionHash) ? self::OK : self::DRIFT;
    }

    /**
     * E2-3, puro: ¿apunta el número al agente publicado de la parte que atiende
     * las entrantes? `$observedAgentId` es lo que ve la telefonía en el número
     * (null = sin agente); `$known` dice si la telefonía lo pudo leer. Sin
     * agente publicado, el número tiene que estar sin agente (voz apagada).
     */
    public static function numberVerdict(bool $known, ?string $observedAgentId, ?string $publishedAgentId): string
    {
        if (! $known || ($observedAgentId !== null && ! VoiceIds::isProviderId($observedAgentId))) {
            return self::NUMBER_UNKNOWN;
        }
        if ($observedAgentId === null || $publishedAgentId === null) {
            return $observedAgentId === $publishedAgentId ? self::NUMBER_OK : self::NUMBER_DRIFT;
        }

        return hash_equals($publishedAgentId, $observedAgentId) ? self::NUMBER_OK : self::NUMBER_DRIFT;
    }

    /**
     * El `agent_id` del proveedor que tendría que tener el número ahora: el
     * del agente que atiende la parte de las entrantes, o null (voz apagada).
     */
    public static function expectedNumberAgent(): ?string
    {
        $columna = StudioVoiceAgent::SERVES[StudioVoicePublishing::INBOUND_PART];

        return StudioVoiceAgent::query()->where($columna, true)->value('provider_agent_id');
    }

    private function deployer(): VoiceAgentDeployer
    {
        return $this->deployer ?? VoiceAdapters::deployer();
    }
}
