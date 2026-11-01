<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\MetaAdEntity;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioCampaignLink;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\Attribution\LeadSourceClassifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as LengthAwarePaginatorContract;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * ASOCIACIONES CAMPAÑA ↔ ULTRON (contrato H6 §I3): qué campaña de Meta se
 * asocia a la línea base o a qué versión del Studio. ES REGISTRO DEL STUDIO:
 * no cambia nada en Meta ni en lo que ULTRON dice o hace, y por eso no lleva
 * cuatro ojos (no gasta ni contiene datos personales).
 *
 * UNA FILA ES UN PERIODO: el historial son filas, nunca ediciones. Sustituir
 * una asociación son TRES sentencias, en este orden y en la misma transacción
 * que sus eventos (informe del especialista de datos, H-4 y D-1): cerrar la
 * vieja (UPDATE condicionado a `status = 'active'`), insertar la nueva y anotar
 * la nueva en la vieja (UPDATE condicionado a `replaced_by_link_id IS NULL`).
 *
 * LAS PUERTAS de crear van en el orden de §I3.4 y del fixture del contrato:
 * permiso → bandera → reintento → Meta configurado → campaña → versión →
 * asociación activa y `replaces` → tope → transacción. Las carreras:
 *  - dos asociaciones de la misma campaña chocan en el único parcial
 *    `uscl_one_active` (23505) → 409 `campaign_already_linked`;
 *  - la misma petición dos veces, en `client_request_id` → la de la primera
 *    (200 `replayed`). En PostgreSQL un 23505 deja abortada la transacción:
 *    todo eso se resuelve FUERA de ella, como `StudioVersions::create`;
 *  - el tope de activas se cuenta con un cerrojo consultivo (READ COMMITTED
 *    primero: lección B-2 de G): dos creaciones a la vez no lo pasan.
 *
 * Del motor solo LEE: el espejo de Meta ({@see StudioMetaCampaigns}), los ids
 * opacos y el enmascarado.
 */
final class StudioCampaignLinks
{
    public const PER_PAGE_MAX = 50;

    public const REASON_MIN = 5;

    public const REASON_MAX = 500;

    /** Las versiones que se pueden asociar (decisión 7 del lead). */
    public const LINKABLE = [StudioVersionStatus::Approved, StudioVersionStatus::Canary, StudioVersionStatus::Published];

    /** El motivo con el que se cierra la asociación sustituida (§I3.4, paso 11). */
    public const REPLACED_REASON = 'Sustituida por otra asociación';

    public const DEFAULT_MAX_ACTIVE = 50;

    private const LOCK_KEY = 'ultron_studio_campaign_links';

    public function __construct(
        private readonly StudioAudit $audit,
        private readonly StudioMetaCampaigns $meta,
    ) {}

    public static function writesEnabled(): bool
    {
        return StudioIntegrations::writesEnabled();
    }

    /** Asociaciones activas en total: 1–200; la configuración ya lo recorta y aquí se vuelve a acotar. */
    public static function maxActive(): int
    {
        $max = config('ultron_studio.integrations.campaign_links.max_active');

        return is_int($max) ? min(200, max(1, $max)) : self::DEFAULT_MAX_ACTIVE;
    }

    /** @return list<string> */
    public static function linkableStatuses(): array
    {
        return array_map(static fn (StudioVersionStatus $s): string => $s->value, self::LINKABLE);
    }

    // ── 40 · GET integrations/meta/campaigns ────────────────────────────────

    /**
     * La conexión con Meta y una página de campañas del espejo, cada una con su
     * asociación ACTIVA (o ninguna). Dos estados que nunca se mezclan:
     * `meta.effectiveStatus` (lo que dice Meta) y `ultron.link` (lo que dice el
     * Studio). Sin configuración: `not_configured`, sin cuentas ni campañas.
     *
     * @param  array{status?:?string,linked?:?string,account?:?string}  $filters
     * @return array{connection:array<string,mixed>,campaigns:LengthAwarePaginatorContract}
     */
    public function campaignsPage(array $filters, int $page, int $perPage): array
    {
        $conexion = $this->meta->connection();
        $filas = $this->meta->campaigns($conexion['accountStates']);

        if (($filters['account'] ?? null) !== null) {
            $cuenta = $this->meta->accountFor((string) $filters['account']);
            $filas = $cuenta === null ? [] : array_values(array_filter($filas, static fn (array $f): bool => $f['account'] === $cuenta));
        }
        if (($filters['status'] ?? null) !== null) {
            $buscado = $filters['status'] === StudioMetaCampaigns::UNKNOWN_STATUS ? null : $filters['status'];
            $filas = array_values(array_filter($filas, static fn (array $f): bool => $f['effectiveStatus'] === $buscado));
        }

        // Las activas de esas campañas: UNA consulta.
        $activas = [];
        if ($filas !== []) {
            $enlaces = StudioCampaignLink::query()
                ->with(['version:id,uuid,label,status,content,content_hash'])
                ->where('status', StudioCampaignLink::STATUS_ACTIVE)
                ->whereIn('campaign_id', array_values(array_unique(array_column($filas, 'entityId'))))
                ->get();
            foreach ($this->dtos($enlaces) as $i => $dto) {
                $e = $enlaces[$i];
                $activas[$e->ad_account_id.'|'.$e->campaign_id] = $dto;
            }
        }
        if (($filters['linked'] ?? null) !== null) {
            $quiere = $filters['linked'] === 'yes';
            $filas = array_values(array_filter($filas, static fn (array $f): bool => isset($activas[$f['account'].'|'.$f['entityId']]) === $quiere));
        }

        $total = count($filas);
        $pagina = array_map(static fn (array $f): array => $f['dto'] + ['ultron' => ['link' => $activas[$f['account'].'|'.$f['entityId']] ?? null]],
            array_slice($filas, ($page - 1) * $perPage, $perPage));

        return [
            'connection' => $conexion['connection'],
            'campaigns' => new LengthAwarePaginator($pagina, $total, $perPage, $page, ['path' => LengthAwarePaginator::resolveCurrentPath()]),
        ];
    }

    // ── 41 · GET integrations/meta/links ────────────────────────────────────

    /** @param  array{campaign?:?string,status?:?string}  $filters */
    public function linksPage(array $filters, int $perPage): LengthAwarePaginatorContract
    {
        $consulta = StudioCampaignLink::query()
            ->with(['version:id,uuid,label,status,content,content_hash', 'replacedBy:id,uuid'])
            ->when($filters['status'] ?? null, fn ($q, string $estado) => $q->where('status', $estado))
            ->orderByDesc('id');

        if (($filters['campaign'] ?? null) !== null) {
            // El id opaco no se invierte: se busca entre las campañas que tienen asociaciones.
            $ids = array_values(array_filter(
                StudioCampaignLink::query()->distinct()->pluck('campaign_id')->map(static fn (mixed $c): string => (string) $c)->all(),
                static fn (string $c): bool => hash_equals(MetaAdEntity::opaqueId(MetaAdEntity::LEVEL_CAMPAIGN, $c), (string) $filters['campaign']),
            ));
            $ids === [] ? $consulta->whereRaw('0 = 1') : $consulta->whereIn('campaign_id', $ids);
        }

        $pagina = $consulta->paginate($perPage);

        return $pagina->setCollection(collect($this->dtos($pagina->getCollection())));
    }

    // ── 47 · POST integrations/meta/links ───────────────────────────────────

    /**
     * Asociar una campaña a la línea base o a una versión asociable. Con
     * `replaces` (el uuid de la activa de esa campaña), la sustituye.
     *
     * @param  array{kind:string,versionUuid?:?string}  $target
     * @return array{link:array<string,mixed>,replaced:array<string,mixed>|null,replayed:bool}
     */
    public function create(StudioAccess $access, Request $request, string $clientRequestId, string $campaign, array $target, ?string $replaces): array
    {
        $access->require(StudioAccess::CAMPAIGNS_MANAGE);
        if (! self::writesEnabled()) {
            throw UltronStudioException::integrationsWritesDisabled();
        }

        $cuerpo = [
            'campaign' => $campaign,
            'targetKind' => $target['kind'],
            'versionUuid' => $target['kind'] === StudioCampaignLink::TARGET_VERSION ? strtolower((string) ($target['versionUuid'] ?? '')) : null,
            'replaces' => $replaces === null ? null : strtolower($replaces),
        ];
        if ($previa = $this->replay($access, $clientRequestId, $cuerpo)) {
            return $previa + ['replayed' => true];
        }

        // La misma petición repetida puede pasar el reintento sin ver nada y
        // tropezar con lo que su gemela acaba de dejar: antes de rechazar se
        // vuelve a mirar (H-1 del Hito 5).
        try {
            return $this->createGated($access, $request, $clientRequestId, $cuerpo);
        } catch (UltronStudioException $e) {
            if ($previa = $this->replay($access, $clientRequestId, $cuerpo)) {
                return $previa + ['replayed' => true];
            }

            throw $e;
        }
    }

    /**
     * @param  array{campaign:string,targetKind:string,versionUuid:?string,replaces:?string}  $cuerpo
     * @return array{link:array<string,mixed>,replaced:array<string,mixed>|null,replayed:bool}
     */
    private function createGated(StudioAccess $access, Request $request, string $clientRequestId, array $cuerpo): array
    {
        $problema = $this->meta->configurationProblem();
        if ($problema !== null) {
            throw UltronStudioException::metaNotConfigured($problema);
        }
        $campana = $this->meta->resolve($cuerpo['campaign']);
        if ($campana === null) {
            throw UltronStudioException::campaignNotFound();
        }

        $version = null;
        if ($cuerpo['targetKind'] === StudioCampaignLink::TARGET_VERSION) {
            $version = self::linkableVersion((string) $cuerpo['versionUuid']);
        }

        $activa = $this->activeFor($campana['account'], $campana['campaignId']);
        if ($activa !== null && $cuerpo['replaces'] === null) {
            throw UltronStudioException::campaignAlreadyLinked($this->dtoOf($activa));
        }
        if ($cuerpo['replaces'] !== null && ($activa === null || strtolower((string) $activa->uuid) !== $cuerpo['replaces'])) {
            throw UltronStudioException::campaignLinkMoved($activa === null ? null : $this->dtoOf($activa));
        }

        try {
            [$nueva, $sustituida] = DB::transaction(function () use ($access, $request, $clientRequestId, $cuerpo, $campana, $version, $activa): array {
                StudioIntegrations::lock(self::LOCK_KEY);
                $ahora = now();
                $yo = $access->snapshot();

                // El tope, sin contar la que se sustituye.
                $max = self::maxActive();
                $activas = StudioCampaignLink::query()->where('status', StudioCampaignLink::STATUS_ACTIVE)->count();
                if ($activas - ($activa !== null ? 1 : 0) >= $max) {
                    throw UltronStudioException::campaignLinksLimit($max);
                }

                // 1) Cerrar la vieja, solo si sigue activa.
                if ($activa !== null && ! StudioCampaignLink::close((int) $activa->id, $yo, self::REPLACED_REASON, $ahora)) {
                    $ahoraActiva = $this->activeFor($campana['account'], $campana['campaignId']);
                    throw UltronStudioException::campaignLinkMoved($ahoraActiva === null ? null : $this->dtoOf($ahoraActiva));
                }

                // 2) Insertar la nueva.
                $nueva = StudioCampaignLink::query()->forceCreate([
                    'client_request_id' => $clientRequestId,
                    'ad_account_id' => $campana['account'],
                    'campaign_id' => $campana['campaignId'],
                    'campaign_name_at_link' => $campana['name'] === null ? null : mb_substr($campana['name'], 0, 255),
                    'target_kind' => $cuerpo['targetKind'],
                    'version_id' => $version?->id,
                    'version_content_hash' => $version?->content_hash,
                    'status' => StudioCampaignLink::STATUS_ACTIVE,
                    'created_by_admin_id' => $yo['id'],
                    'created_by_name' => $yo['name'],
                    'created_by_role' => $yo['role'],
                    'created_at' => $ahora,
                ]);

                // 3) Anotar la nueva en la vieja, una sola vez.
                if ($activa !== null && ! StudioCampaignLink::markReplacedBy((int) $activa->id, (int) $nueva->id)) {
                    throw UltronStudioException::campaignLinkMoved(null);
                }

                $destino = ['kind' => $cuerpo['targetKind']] + ($version !== null ? ['versionUuid' => (string) $version->uuid] : []);
                $this->audit->record($request, $access->actor, 'campaign_link.created', StudioAuditEvent::SUBJECT_CAMPAIGN_LINK, $nueva->uuid,
                    'Asociación de campaña creada', [
                        'toStatus' => StudioCampaignLink::STATUS_ACTIVE,
                        'diff' => [
                            'campaign' => MetaAdEntity::opaqueId(MetaAdEntity::LEVEL_CAMPAIGN, $campana['campaignId']),
                            'accountRef' => LeadSourceClassifier::maskId($campana['account']),
                            'target' => $destino,
                            'replaces' => $activa?->uuid,
                        ],
                    ]);
                if ($activa !== null) {
                    $this->audit->record($request, $access->actor, 'campaign_link.deactivated', StudioAuditEvent::SUBJECT_CAMPAIGN_LINK, $activa->uuid,
                        'Asociación de campaña desactivada', [
                            'fromStatus' => StudioCampaignLink::STATUS_ACTIVE,
                            'toStatus' => StudioCampaignLink::STATUS_INACTIVE,
                            'diff' => ['reason' => self::REPLACED_REASON, 'replacedBy' => $nueva->uuid],
                        ], maxValueLength: StudioAudit::MAX_RULE_VALUE_LENGTH);
                }

                return [$nueva, $activa];
            });
        } catch (UniqueConstraintViolationException $e) {
            // La misma llave dos veces a la vez, u otra asociación de la misma
            // campaña que se confirmó antes: FUERA de la transacción abortada.
            if ($previa = $this->replay($access, $clientRequestId, $cuerpo)) {
                return $previa + ['replayed' => true];
            }
            $ahoraActiva = $this->activeFor($campana['account'], $campana['campaignId']);
            if ($ahoraActiva === null) {
                throw $e;
            }

            throw UltronStudioException::campaignAlreadyLinked($this->dtoOf($ahoraActiva));
        }

        $dtos = $this->dtos(StudioCampaignLink::query()
            ->with(['version:id,uuid,label,status,content,content_hash', 'replacedBy:id,uuid'])
            ->whereIn('id', array_values(array_filter([$nueva->id, $sustituida?->id])))
            ->orderByDesc('id')
            ->get());

        return ['link' => $dtos[0], 'replaced' => $dtos[1] ?? null, 'replayed' => false];
    }

    // ── 48 · POST integrations/meta/links/{uuid}/deactivate ─────────────────

    /** @return array{link:array<string,mixed>} */
    public function deactivate(StudioAccess $access, Request $request, string $uuid, string $reason): array
    {
        $access->require(StudioAccess::CAMPAIGNS_MANAGE);
        if (! self::writesEnabled()) {
            throw UltronStudioException::integrationsWritesDisabled();
        }

        $enlace = StudioCampaignLink::query()->where('uuid', strtolower($uuid))->first();
        if ($enlace === null) {
            throw UltronStudioException::linkNotFound();
        }
        if ($enlace->status !== StudioCampaignLink::STATUS_ACTIVE) {
            throw UltronStudioException::campaignLinkInvalidTransition((string) $enlace->status, [StudioCampaignLink::STATUS_ACTIVE]);
        }

        DB::transaction(function () use ($access, $request, $enlace, $reason): void {
            if (! StudioCampaignLink::close((int) $enlace->id, $access->snapshot(), $reason, now())) {
                // Otra petición la cerró mientras tanto.
                throw UltronStudioException::campaignLinkInvalidTransition(StudioCampaignLink::STATUS_INACTIVE, [StudioCampaignLink::STATUS_ACTIVE]);
            }
            $this->audit->record($request, $access->actor, 'campaign_link.deactivated', StudioAuditEvent::SUBJECT_CAMPAIGN_LINK, $enlace->uuid,
                'Asociación de campaña desactivada', [
                    'fromStatus' => StudioCampaignLink::STATUS_ACTIVE,
                    'toStatus' => StudioCampaignLink::STATUS_INACTIVE,
                    'diff' => ['reason' => $reason, 'replacedBy' => null],
                ], maxValueLength: StudioAudit::MAX_RULE_VALUE_LENGTH);
        });

        return ['link' => $this->dtoOf(StudioCampaignLink::query()->whereKey($enlace->id)->firstOrFail())];
    }

    // ── Presentación ────────────────────────────────────────────────────────

    /**
     * `CampaignLinkDto` de varias asociaciones con TRES consultas en total (las
     * versiones y la sustituta, si no venían cargadas, y el espejo de Meta), en
     * el mismo orden.
     *
     * @param  iterable<StudioCampaignLink>  $links
     * @return list<array<string,mixed>>
     */
    public function dtos(iterable $links): array
    {
        $lista = (new EloquentCollection(is_array($links) ? $links : iterator_to_array($links, false)))->values();
        if ($lista->isEmpty()) {
            return [];
        }
        $lista->loadMissing(['version:id,uuid,label,status,content,content_hash', 'replacedBy:id,uuid']);
        $espejo = $this->meta->mirrorNames($lista->map(static fn (StudioCampaignLink $l): string => (string) $l->campaign_id)->all());

        return $lista->map(function (StudioCampaignLink $l) use ($espejo): array {
            $clave = $l->ad_account_id.'|'.$l->campaign_id;
            $conectada = array_key_exists($clave, $espejo);

            return self::dto($l, self::versionRef($l), $conectada, $conectada ? $espejo[$clave] : null, $l->replacedBy?->uuid);
        })->all();
    }

    /** @return array<string,mixed> */
    public function dtoOf(StudioCampaignLink $link): array
    {
        return $this->dtos([$link])[0];
    }

    /**
     * `CampaignLinkDto` (§I3.5). Pura: la usa también la exportación de fixtures.
     *
     * @param  array{uuid:string,label:string,status:string,integrityOk:bool}|null  $version  la versión de destino (estado de AHORA)
     * @return array<string,mixed>
     */
    public static function dto(StudioCampaignLink $l, ?array $version, bool $connected, ?string $currentName, ?string $replacedByUuid): array
    {
        $destino = $l->target_kind === StudioCampaignLink::TARGET_VERSION && $version !== null
            ? [
                'kind' => StudioCampaignLink::TARGET_VERSION,
                'versionUuid' => $version['uuid'],
                'label' => $version['label'],
                'status' => $version['status'],
                'statusLabel' => StudioVersionStatus::tryFrom($version['status'])?->label() ?? $version['status'],
                'integrityOk' => $version['integrityOk'],
            ]
            : ['kind' => StudioCampaignLink::TARGET_BASELINE, 'label' => StudioCatalog::BASELINE_LABEL];

        return [
            'uuid' => (string) $l->uuid,
            'status' => (string) $l->status,
            'campaign' => [
                'id' => MetaAdEntity::opaqueId(MetaAdEntity::LEVEL_CAMPAIGN, (string) $l->campaign_id),
                'ref' => LeadSourceClassifier::maskId((string) $l->campaign_id),
                'nameAtLink' => $l->campaign_name_at_link,
                'connected' => $connected,
                'currentName' => $connected ? $currentName : null,
            ],
            'target' => $destino,
            'createdBy' => ['name' => (string) $l->created_by_name, 'role' => $l->created_by_role],
            'createdAt' => $l->created_at?->toIso8601String(),
            'deactivated' => $l->status === StudioCampaignLink::STATUS_ACTIVE ? null : [
                'at' => $l->deactivated_at?->toIso8601String(),
                'by' => ['name' => (string) $l->deactivated_by_name, 'role' => $l->deactivated_by_role],
                'reason' => (string) $l->deactivation_reason,
                'replacedBy' => $replacedByUuid,
            ],
        ];
    }

    /**
     * La versión de un destino `version`, si es asociable: existe (404), está
     * en APPROVED, CANARY o PUBLISHED (409, el estado antes que la integridad)
     * y es íntegra (409). La usan también los números canarios (versión esperada).
     *
     * @throws UltronStudioException
     */
    public static function linkableVersion(string $uuid): StudioVersion
    {
        $version = StudioVersion::query()->where('uuid', strtolower($uuid))->first();
        if ($version === null) {
            throw UltronStudioException::versionNotFound();
        }
        if (! in_array($version->statusEnum(), self::LINKABLE, true)) {
            throw UltronStudioException::versionNotLinkable((string) $version->status);
        }
        if (! StudioVersions::integrityOk($version)) {
            throw UltronStudioException::integrityMismatch('La huella de la versión no coincide con su contenido. No se puede asociar: avisa a quien administra el sistema.');
        }

        return $version;
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    private function activeFor(string $account, string $campaignId): ?StudioCampaignLink
    {
        return StudioCampaignLink::query()
            ->where('ad_account_id', $account)
            ->where('campaign_id', $campaignId)
            ->where('status', StudioCampaignLink::STATUS_ACTIVE)
            ->first();
    }

    /** @return array{uuid:string,label:string,status:string,integrityOk:bool}|null */
    private static function versionRef(StudioCampaignLink $l): ?array
    {
        $v = $l->target_kind === StudioCampaignLink::TARGET_VERSION ? $l->version : null;

        return $v === null ? null : [
            'uuid' => (string) $v->uuid,
            'label' => (string) $v->label,
            'status' => (string) $v->status,
            'integrityOk' => StudioVersions::integrityOk($v),
        ];
    }

    /**
     * La misma petición repetida devuelve la asociación de la primera vez (y la
     * que sustituyó). La misma llave de otra persona o con otro cuerpo —otra
     * campaña, otro destino u otro `replaces`— es 409 `client_request_reused`.
     *
     * @param  array{campaign:string,targetKind:string,versionUuid:?string,replaces:?string}  $cuerpo
     * @return array{link:array<string,mixed>,replaced:array<string,mixed>|null}|null
     */
    private function replay(StudioAccess $access, string $clientRequestId, array $cuerpo): ?array
    {
        $previa = StudioCampaignLink::query()
            ->with(['version:id,uuid,label,status,content,content_hash', 'replacedBy:id,uuid'])
            ->where('client_request_id', strtolower($clientRequestId))
            ->first();
        if ($previa === null) {
            return null;
        }

        $sustituida = StudioCampaignLink::query()->where('replaced_by_link_id', $previa->id)->first();
        $mismaVersion = $cuerpo['targetKind'] !== StudioCampaignLink::TARGET_VERSION
            || ($previa->version !== null && strtolower((string) $previa->version->uuid) === $cuerpo['versionUuid']);
        if ((int) $previa->created_by_admin_id !== $access->id()
            || ! hash_equals(MetaAdEntity::opaqueId(MetaAdEntity::LEVEL_CAMPAIGN, (string) $previa->campaign_id), $cuerpo['campaign'])
            || $previa->target_kind !== $cuerpo['targetKind']
            || ! $mismaVersion
            || ($sustituida === null ? null : strtolower((string) $sustituida->uuid)) !== $cuerpo['replaces']) {
            throw UltronStudioException::clientRequestReused();
        }

        $dtos = $this->dtos(array_values(array_filter([$previa, $sustituida])));

        return ['link' => $dtos[0], 'replaced' => $dtos[1] ?? null];
    }
}
