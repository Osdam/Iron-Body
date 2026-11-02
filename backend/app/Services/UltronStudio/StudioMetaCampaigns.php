<?php

namespace App\Services\UltronStudio;

use App\Models\MetaAdEntity;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Attribution\LeadSourceClassifier;
use App\Services\Marketing\Meta\MetaAdsApiClient;
use App\Services\Marketing\Meta\MetaAdsSync;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * LAS CAMPAÑAS DE META, LEÍDAS DEL ESPEJO LOCAL (contrato H6 §I3.3): sin red y
 * sin escribir nada. El Studio no llama a la Marketing API: lee lo que dejó la
 * sincronización horaria del motor en `meta_ad_entities` y `meta_sync_runs`.
 *
 * LISTA BLANCA de llamadas a clases de Meta (la vigila una prueba estática):
 * `configurationProblem`, `accountIds`, `statusAll`, `forConfiguredAccount`,
 * `accountNames`, `opaqueId`, `publicAccount`, `maskId` y `maskDigits`, más la
 * consulta de lectura de `MetaSyncRun`. PROHIBIDAS: `campaigns`, `adsets`,
 * `ads`, `insights`, `reach`, `account`, `tokenInfo`, `adOwner`,
 * `accountTotals`, `run`, `runAll`, `refreshReach` y `refreshStatuses` (las que
 * salen a Meta o escriben el espejo).
 *
 * LOS IDS DE META NO SALEN DEL SERVIDOR: fuera van su id opaco
 * (`MetaAdEntity::opaqueId`, HMAC con la `APP_KEY`) y «***» con sus cuatro
 * últimos dígitos; los nombres, con las series de dígitos enmascaradas.
 *
 * `possiblyDeleted` (§I3.3): `synced_at` nulo, o anterior al `started_at` de la
 * ÚLTIMA pasada (por id) de su cuenta con `kind = insights`, `status = ok` y
 * `trigger ≠ backfill` (los rellenos no refrescan estados). El borde de
 * campañas no devuelve las eliminadas, y su estado es «el último conocido».
 * Falso positivo aceptado: si en esa pasada falló el borde (es accesorio), las
 * campañas que no gastaron salen «posiblemente eliminadas» hasta la siguiente.
 */
final class StudioMetaCampaigns
{
    /** Los estados efectivos de Meta que se pueden pedir como filtro (§I2); `unknown` = sin estado. */
    public const STATUS_FILTERS = ['ACTIVE', 'PAUSED', 'ARCHIVED', 'IN_PROCESS', 'WITH_ISSUES', 'DELETED', 'unknown'];

    public const UNKNOWN_STATUS = 'unknown';

    /** Los estados de `connection.state` (los de `MetaAdsSync::statusAll()`). */
    public const CONNECTION_STATES = ['ok', 'running', 'stale', 'failed', 'not_configured'];

    public function __construct(
        private readonly MetaAdsApiClient $client,
        private readonly MetaAdsSync $sync,
    ) {}

    /** Qué falta para leer Meta, o null. Solo mira la configuración. */
    public function configurationProblem(): ?string
    {
        return $this->client->configurationProblem();
    }

    /** Cuántas cuentas publicitarias hay conectadas (META_AD_ACCOUNT_IDS). */
    public function accountCount(): int
    {
        return count($this->client->accountIds());
    }

    /**
     * Clasificación de Meta Ads (§I7.2), pura: con un problema de
     * configuración, `not_configured` con ese motivo; con la sincronización
     * apagada, `degraded` con `sync_disabled`, sea cual sea el estado; si no,
     * `ok`/`running` → `connected_read` y `stale`/`failed` → `degraded`.
     *
     * @return array{classification:string,state:string,reason:?string}
     */
    public static function classify(?string $problem, string $state, bool $syncEnabled): array
    {
        if ($problem !== null) {
            return ['classification' => 'not_configured', 'state' => 'not_configured', 'reason' => $problem];
        }
        if ($state === 'not_configured') {
            // Sin problema de configuración no debería ocurrir (statusAll solo lo
            // da sin cuentas, que ya es un problema): se dice lo mismo que el motor.
            return ['classification' => 'not_configured', 'state' => 'not_configured', 'reason' => 'missing_ad_account_id'];
        }
        if (! $syncEnabled) {
            return ['classification' => 'degraded', 'state' => $state, 'reason' => 'sync_disabled'];
        }

        return in_array($state, ['ok', 'running'], true)
            ? ['classification' => 'connected_read', 'state' => $state, 'reason' => null]
            : ['classification' => 'degraded', 'state' => $state, 'reason' => in_array($state, ['stale', 'failed'], true) ? $state : 'failed'];
    }

    /** `possiblyDeleted` (§I3.3): sin `synced_at`, o anterior (estricto) al inicio de la pasada de referencia. */
    public static function possiblyDeleted(?CarbonInterface $syncedAt, ?CarbonInterface $referenceStartedAt): bool
    {
        if ($syncedAt === null) {
            return true;
        }

        return $referenceStartedAt !== null && $syncedAt->lessThan($referenceStartedAt);
    }

    /** `stale` por campaña: el estado de su cuenta es `stale` o `failed`. */
    public static function isStale(?string $accountState): bool
    {
        return in_array($accountState, ['stale', 'failed'], true);
    }

    /**
     * El orden de §I3.3: primero `effective_status = 'ACTIVE'`, después el
     * nombre sin distinguir mayúsculas y después `entity_id`.
     *
     * @param  list<array{entityId:string,name:?string,effectiveStatus:?string}>  $rows
     * @return list<array<string,mixed>>
     */
    public static function sort(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [($a['effectiveStatus'] ?? null) === 'ACTIVE' ? 0 : 1, mb_strtolower((string) ($a['name'] ?? ''), 'UTF-8'), (string) $a['entityId']]
            <=> [($b['effectiveStatus'] ?? null) === 'ACTIVE' ? 0 : 1, mb_strtolower((string) ($b['name'] ?? ''), 'UTF-8'), (string) $b['entityId']]);

        return $rows;
    }

    /**
     * El bloque `connection` de `GET integrations/meta/campaigns` y la
     * evidencia de `GET integrations/status`. Sin configuración no se lee el
     * estado de ninguna cuenta: `not_configured`, sin cuentas.
     *
     * @return array{connection:array<string,mixed>,classification:string,coveredTo:?string,accountStates:array<string,string>}
     */
    public function connection(): array
    {
        $problema = $this->client->configurationProblem();

        // Sin configuración no se lee el estado de ninguna cuenta.
        return self::connectionFrom($problema, $problema === null ? $this->sync->statusAll() : null,
            config('meta.ads.sync_enabled') === true, max(1, (int) config('meta.ads.stale_after_minutes', 180)));
    }

    /**
     * El bloque `connection` a partir del problema de configuración y de
     * `MetaAdsSync::statusAll()` (null sin configuración). Pura: la usa también
     * la exportación de fixtures.
     *
     * @param  array<string,mixed>|null  $statusAll
     * @return array{connection:array<string,mixed>,classification:string,coveredTo:?string,accountStates:array<string,string>}
     */
    public static function connectionFrom(?string $problem, ?array $statusAll, bool $syncEnabled, int $staleAfterMinutes): array
    {
        if ($problem !== null || $statusAll === null) {
            $c = self::classify($problem ?? 'missing_ad_account_id', 'not_configured', $syncEnabled);

            return [
                'connection' => [
                    'state' => $c['state'], 'reason' => $c['reason'], 'syncEnabled' => $syncEnabled, 'staleAfterMinutes' => $staleAfterMinutes,
                    'lastSuccessAt' => null, 'minutesSinceSuccess' => null, 'accounts' => [],
                ],
                'classification' => $c['classification'],
                'coveredTo' => null,
                'accountStates' => [],
            ];
        }

        $c = self::classify(null, (string) ($statusAll['status'] ?? 'failed'), $syncEnabled);
        $cuentas = [];
        $porCuenta = [];
        foreach (is_array($statusAll['accounts'] ?? null) ? $statusAll['accounts'] : [] as $fila) {
            $cuentas[] = [
                'id' => (string) $fila['id'],
                'ref' => $fila['ref'] ?? null,
                'name' => $fila['name'] ?? null,
                'state' => (string) $fila['status'],
                'lastSuccessAt' => $fila['last_success_at'] ?? null,
            ];
            $porCuenta[(string) $fila['id']] = (string) $fila['status'];
        }

        return [
            'connection' => [
                'state' => $c['state'],
                'reason' => $c['reason'],
                'syncEnabled' => $syncEnabled,
                'staleAfterMinutes' => $staleAfterMinutes,
                'lastSuccessAt' => $statusAll['last_success_at'] ?? null,
                'minutesSinceSuccess' => $statusAll['minutes_since_success'] ?? null,
                'accounts' => $cuentas,
            ],
            'classification' => $c['classification'],
            'coveredTo' => $statusAll['covered_to'] ?? null,
            'accountStates' => $porCuenta,
        ];
    }

    /**
     * Las campañas del espejo de las cuentas conectadas, con lo que dice Meta
     * de cada una (el último estado conocido), ordenadas como §I3.3. Una
     * consulta por las campañas, una por los nombres de las cuentas y dos por
     * las pasadas de referencia. Sin configuración, ninguna.
     *
     * `dto` es un cierre que arma la DTO de la fila ({@see campaignDto()}) con
     * los datos y el `$ahora` de este momento: quien pagina la arma solo para
     * las filas de la página (F-2 del H7).
     *
     * @param  array<string,string>  $accountStates  id opaco de cuenta → estado (de {@see connection()})
     * @return list<array{account:string,entityId:string,name:?string,effectiveStatus:?string,dto:\Closure():array<string,mixed>}>
     */
    public function campaigns(array $accountStates): array
    {
        if ($this->client->configurationProblem() !== null) {
            return [];
        }

        $filas = MetaAdEntity::forConfiguredAccount()
            ->where('level', MetaAdEntity::LEVEL_CAMPAIGN)
            ->get(['ad_account_id', 'entity_id', 'name', 'effective_status', 'synced_at']);
        if ($filas->isEmpty()) {
            return [];
        }

        $cuentas = array_values(array_unique($filas->map(static fn (MetaAdEntity $e): string => (string) $e->ad_account_id)->all()));
        $nombres = MetaAdEntity::accountNames($cuentas);
        $publicas = [];
        foreach ($cuentas as $cuenta) {
            $publicas[$cuenta] = MetaAdEntity::publicAccount($cuenta, $nombres[$cuenta] ?? null);
        }
        $referencias = self::referenceRuns($cuentas);
        $ahora = CarbonImmutable::now();

        $out = [];
        foreach ($filas as $e) {
            $cuenta = (string) $e->ad_account_id;
            $entidad = (string) $e->entity_id;
            $nombre = $e->name;
            $sincronizada = $e->synced_at !== null ? CarbonImmutable::instance($e->synced_at) : null;
            $estado = $e->effective_status !== null ? (string) $e->effective_status : null;
            $publica = $publicas[$cuenta];
            $estadoCuenta = $accountStates[$publica['id']] ?? null;
            $referencia = $referencias[$cuenta] ?? null;
            $out[] = [
                'account' => $cuenta,
                'entityId' => $entidad,
                'name' => $nombre,
                'effectiveStatus' => $estado,
                // Valores ya leídos e inmutables: armarla después da la misma DTO que armarla aquí.
                'dto' => static fn (): array => self::campaignDto($entidad, $nombre, $estado, $sincronizada, $publica, $estadoCuenta, $referencia, $ahora),
            ];
        }

        return self::sort($out);
    }

    /**
     * Una campaña del espejo tal como sale (§I3.4), sin `ultron`: el id de Meta
     * no sale (id opaco y «***» + 4 dígitos) y el nombre va enmascarado. Pura.
     *
     * @param  array{id:string,ref:?string,name:?string}  $publicAccount  {@see MetaAdEntity::publicAccount()}
     * @return array<string,mixed>
     */
    public static function campaignDto(string $entityId, ?string $name, ?string $effectiveStatus, ?CarbonImmutable $syncedAt, array $publicAccount,
        ?string $accountState, ?CarbonImmutable $referenceStartedAt, CarbonImmutable $now): array
    {
        return [
            'id' => MetaAdEntity::opaqueId(MetaAdEntity::LEVEL_CAMPAIGN, $entityId),
            'ref' => LeadSourceClassifier::maskId($entityId),
            'name' => LeadSourceClassifier::maskDigits($name),
            'account' => $publicAccount,
            'meta' => [
                'effectiveStatus' => $effectiveStatus,
                'statusKnownAt' => $syncedAt?->toIso8601String(),
                'ageMinutes' => $syncedAt === null ? null : max(0, (int) floor($syncedAt->diffInMinutes($now, true))),
                'stale' => self::isStale($accountState),
                'possiblyDeleted' => self::possiblyDeleted($syncedAt, $referenceStartedAt),
            ],
        ];
    }

    /**
     * Una campaña de las cuentas conectadas por su id opaco, o null si no está
     * en el espejo (o Meta no está configurado). Una consulta.
     *
     * @return array{account:string,campaignId:string,name:?string}|null `name` ya enmascarado
     */
    public function resolve(string $opaqueId): ?array
    {
        if ($this->client->configurationProblem() !== null) {
            return null;
        }

        foreach (MetaAdEntity::forConfiguredAccount()->where('level', MetaAdEntity::LEVEL_CAMPAIGN)->orderBy('id')->get(['ad_account_id', 'entity_id', 'name']) as $e) {
            if (hash_equals(MetaAdEntity::opaqueId(MetaAdEntity::LEVEL_CAMPAIGN, (string) $e->entity_id), $opaqueId)) {
                return ['account' => (string) $e->ad_account_id, 'campaignId' => (string) $e->entity_id, 'name' => LeadSourceClassifier::maskDigits($e->name)];
            }
        }

        return null;
    }

    /**
     * El nombre de ahora (enmascarado) de las campañas que siguen en el espejo
     * de una cuenta conectada: «cuenta|campaña» → nombre (o null si no tiene).
     * Las que no aparecen ya no están conectadas. Una consulta.
     *
     * @param  list<string>  $campaignIds
     * @return array<string, ?string>
     */
    public function mirrorNames(array $campaignIds): array
    {
        if ($campaignIds === [] || $this->client->configurationProblem() !== null) {
            return [];
        }

        $out = [];
        foreach (MetaAdEntity::forConfiguredAccount()
            ->where('level', MetaAdEntity::LEVEL_CAMPAIGN)
            ->whereIn('entity_id', array_values(array_unique($campaignIds)))
            ->get(['ad_account_id', 'entity_id', 'name']) as $e) {
            $out[$e->ad_account_id.'|'.$e->entity_id] = LeadSourceClassifier::maskDigits($e->name);
        }

        return $out;
    }

    /** La cuenta conectada cuyo id opaco es `$opaqueId`, o null (una cuenta desconocida da una lista vacía, no un error). */
    public function accountFor(string $opaqueId): ?string
    {
        foreach ($this->client->accountIds() as $cuenta) {
            if (hash_equals(MetaAdEntity::opaqueId(MetaAdEntity::LEVEL_ACCOUNT, $cuenta), $opaqueId)) {
                return $cuenta;
            }
        }

        return null;
    }

    /**
     * El inicio de la pasada de referencia de cada cuenta: la ÚLTIMA (por id)
     * con `kind = insights`, `status = ok` y `trigger ≠ backfill`. Dos
     * consultas de lectura sobre `meta_sync_runs_kind_status_idx`.
     *
     * @param  list<string>  $accounts
     * @return array<string, ?CarbonImmutable>
     */
    public static function referenceRuns(array $accounts): array
    {
        if ($accounts === []) {
            return [];
        }

        $ultimas = MetaSyncRun::query()->toBase()
            ->where('kind', MetaSyncRun::KIND_INSIGHTS)
            ->where('status', MetaSyncRun::STATUS_OK)
            ->where('trigger', '<>', MetaSyncRun::TRIGGER_BACKFILL)
            ->whereIn('ad_account_id', $accounts)
            ->groupBy('ad_account_id')
            ->selectRaw('max(id) as id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($ultimas === []) {
            return [];
        }

        $out = [];
        foreach (MetaSyncRun::query()->toBase()->whereIn('id', $ultimas)->get(['ad_account_id', 'started_at']) as $fila) {
            $out[(string) $fila->ad_account_id] = $fila->started_at !== null ? CarbonImmutable::parse((string) $fila->started_at) : null;
        }

        return $out;
    }
}
