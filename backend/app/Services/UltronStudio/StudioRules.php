<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\MarketingKnowledgeItem;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioRule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Las reglas comerciales del Studio (contrato §A3, rutas 15–18, y la
 * importación de `LEGACY_BASELINE_V1`).
 *
 *  - Las del CÓDIGO (R-01…R-12, `legacy_baseline`) las copia el comando
 *    `ultron-studio:import-baseline` y son de SOLO LECTURA: tocarlas desde la
 *    API es 409 `legacy_rule_read_only`. Se cambian cuando cambia el código.
 *  - Las del STUDIO nacen como S-NNN, en `draft` y con `enforcement = none`, y
 *    así se quedan: el motor no las aplica. Se editan con `baseRevision`
 *    (UPDATE condicionado, como el borrador) y se archivan con motivo; nada se
 *    borra.
 *
 * Las referencias (`refs`) se validan contra el catálogo (capas, parámetros y
 * conexiones que existen) y contra la base de conocimiento: una
 * `knowledgeKey` que no está en `marketing_knowledge_items.key` es 422. El
 * Studio LEE esa tabla; el motor no lee nada de aquí.
 *
 * Cada versión guarda una copia de las reglas no archivadas
 * ({@see snapshotForVersion()}): esa copia, la revisión y la auditoría son la
 * historia de cada regla.
 */
final class StudioRules
{
    public const DEFAULT_STATUSES = [StudioRule::STATUS_ACTIVE, StudioRule::STATUS_DRAFT];

    /** Tope de referencias por lista: una regla no cita medio catálogo. */
    public const MAX_REFS = 30;

    /**
     * Tope de reglas del Studio sin archivar: cada versión copia todas las
     * reglas vivas en una fila inmutable y `GET rules` no se pagina, así que
     * sin tope el libro crecería sin freno (revisión de seguridad, H4).
     */
    public const MAX_LIVE_STUDIO_RULES = 100;

    /** Campos que se pueden editar de una regla del Studio. */
    public const EDITABLE = ['description', 'type', 'priority', 'layers', 'refs'];

    private const CODE_ATTEMPTS = 3;

    public function __construct(private readonly StudioAudit $audit) {}

    /**
     * GET rules.
     *
     * @param  list<string>  $statuses
     * @return list<array<string, mixed>>
     */
    public function list(array $statuses): array
    {
        $reglas = StudioRule::query()->whereIn('status', $statuses ?: self::DEFAULT_STATUSES)->orderBy('code')->get();

        return $this->present($reglas->all());
    }

    /**
     * POST rules: nace S-NNN, `draft`, `enforcement = none`. Idempotente por
     * `clientRequestId`: 201 la primera vez, 200 `replayed` al repetir.
     *
     * @param  array{description:string,type:string,priority:string,layers:list<string>,refs?:array<string,mixed>|null}  $data
     * @return array{rule:array<string,mixed>,replayed:bool}
     */
    public function create(StudioAccess $access, Request $request, array $data, string $clientRequestId): array
    {
        $access->require(StudioAccess::MANAGE_RULES);

        $campos = [
            'description' => $data['description'],
            'type' => $data['type'],
            'priority' => $data['priority'],
            'layers' => array_values($data['layers']),
            'refs' => $this->refs($data['refs'] ?? null),
            'origin' => null,
        ];
        $hash = StudioContentHasher::ruleHash($campos);

        for ($intento = 1; ; $intento++) {
            if ($previa = $this->replay($access, $clientRequestId, $hash)) {
                return ['rule' => $this->present([$previa])[0], 'replayed' => true];
            }

            try {
                $regla = DB::transaction(function () use ($access, $request, $campos, $hash, $clientRequestId): StudioRule {
                    $this->serializeCodeAllocation();
                    $vivas = StudioRule::query()->where('source', StudioRule::SOURCE_STUDIO)->where('status', '<>', StudioRule::STATUS_ARCHIVED)->count();
                    if ($vivas >= self::MAX_LIVE_STUDIO_RULES) {
                        throw UltronStudioException::ruleLimitReached(self::MAX_LIVE_STUDIO_RULES);
                    }

                    $yo = $access->snapshot();
                    $regla = StudioRule::query()->forceCreate($campos + [
                        'code' => $this->nextStudioCode(),
                        'source' => StudioRule::SOURCE_STUDIO,
                        'enforcement' => StudioRule::ENFORCEMENT_NONE,
                        'status' => StudioRule::STATUS_DRAFT,
                        'revision' => 1,
                        'content_hash' => $hash,
                        'client_request_id' => $clientRequestId,
                        'created_by_admin_id' => $yo['id'],
                        'created_by_name' => $yo['name'],
                    ]);

                    $this->audit->record($request, $access->actor, 'rule.created', StudioAuditEvent::SUBJECT_RULE, $regla->uuid,
                        "Creó la regla {$regla->code} (borrador, el motor no la aplica)", [
                            'toStatus' => StudioRule::STATUS_DRAFT,
                            'contentHash' => $hash,
                            'diff' => ['code' => $regla->code] + $campos,
                        ]);

                    return $regla;
                });

                return ['rule' => $this->present([$regla->refresh()])[0], 'replayed' => false];
            } catch (UniqueConstraintViolationException $e) {
                // O la misma petición llegó dos veces a la vez (se resuelve con
                // el replay de la siguiente vuelta), o dos reglas nuevas
                // calcularon el mismo código (se reintenta con el siguiente).
                // Fuera de la transacción: en PostgreSQL un error la deja
                // abortada.
                if ($intento >= self::CODE_ATTEMPTS && $this->replay($access, $clientRequestId, $hash) === null) {
                    throw $e;
                }
            }
        }
    }

    /**
     * PATCH rules/{uuid}.
     *
     * @param  array<string, mixed>  $fields  solo los que llegaron
     * @return array<string, mixed>
     */
    public function update(StudioAccess $access, Request $request, StudioRule $regla, int $baseRevision, array $fields): array
    {
        $access->require(StudioAccess::MANAGE_RULES);
        $this->assertEditable($regla, $baseRevision);

        $nuevos = [];
        foreach (self::EDITABLE as $campo) {
            if (array_key_exists($campo, $fields)) {
                $nuevos[$campo] = $campo === 'refs' ? $this->refs($fields['refs']) : $fields[$campo];
            }
        }
        if (isset($nuevos['layers'])) {
            $nuevos['layers'] = array_values($nuevos['layers']);
        }

        $cambios = [];
        foreach ($nuevos as $campo => $valor) {
            if (json_encode($regla->getAttribute($campo)) !== json_encode($valor)) {
                $cambios[] = ['field' => $campo, 'from' => $regla->getAttribute($campo), 'to' => $valor];
            }
        }
        // Nada cambia: no hay escritura, ni revisión nueva, ni traza.
        if ($cambios === []) {
            return $this->present([$regla])[0];
        }

        $completo = array_merge(
            ['description' => $regla->description, 'type' => $regla->type, 'priority' => $regla->priority,
                'layers' => $regla->layers, 'refs' => $regla->refs, 'origin' => $regla->origin],
            $nuevos,
        );
        $hash = StudioContentHasher::ruleHash($completo);

        $actualizada = DB::transaction(function () use ($access, $request, $regla, $baseRevision, $nuevos, $hash, $cambios): StudioRule {
            $yo = $access->snapshot();
            $valores = $nuevos;
            foreach (['layers', 'refs'] as $json) {
                if (array_key_exists($json, $valores)) {
                    $valores[$json] = json_encode($valores[$json], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            }

            $this->conditionalWrite($regla, $baseRevision, $valores + [
                'revision' => $baseRevision + 1,
                'content_hash' => $hash,
                'updated_by_admin_id' => $yo['id'],
                'updated_by_name' => $yo['name'],
            ]);

            $this->audit->record($request, $access->actor, 'rule.updated', StudioAuditEvent::SUBJECT_RULE, $regla->uuid,
                "Editó la regla {$regla->code} (revisión ".($baseRevision + 1).')', [
                    'contentHash' => $hash,
                    'diff' => ['fromRevision' => $baseRevision, 'toRevision' => $baseRevision + 1, 'changes' => $cambios],
                ]);

            return $regla->refresh();
        });

        return $this->present([$actualizada])[0];
    }

    /**
     * POST rules/{uuid}/archive.
     *
     * @return array<string, mixed>
     */
    public function archive(StudioAccess $access, Request $request, StudioRule $regla, int $baseRevision, string $reason): array
    {
        $access->require(StudioAccess::MANAGE_RULES);
        $this->assertEditable($regla, $baseRevision);

        $archivada = DB::transaction(function () use ($access, $request, $regla, $baseRevision, $reason): StudioRule {
            $yo = $access->snapshot();
            $desde = $regla->status;

            $this->conditionalWrite($regla, $baseRevision, [
                'status' => StudioRule::STATUS_ARCHIVED,
                'archived_at' => now(),
                'archive_reason' => $reason,
                'revision' => $baseRevision + 1,
                'updated_by_admin_id' => $yo['id'],
                'updated_by_name' => $yo['name'],
            ]);

            $this->audit->record($request, $access->actor, 'rule.archived', StudioAuditEvent::SUBJECT_RULE, $regla->uuid,
                "Archivó la regla {$regla->code}", [
                    'fromStatus' => $desde,
                    'toStatus' => StudioRule::STATUS_ARCHIVED,
                    'contentHash' => $regla->content_hash,
                    'diff' => ['reason' => $reason, 'fromRevision' => $baseRevision, 'toRevision' => $baseRevision + 1],
                ]);

            return $regla->refresh();
        });

        return $this->present([$archivada])[0];
    }

    /**
     * Las reglas que copia una versión: todas las no archivadas, ordenadas por
     * código y reducidas a {@see StudioContentHasher::VERSION_RULE_FIELDS}.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshotForVersion(): array
    {
        return StudioRule::query()
            ->where('status', '<>', StudioRule::STATUS_ARCHIVED)
            ->orderBy('code')
            ->get()
            ->map(fn (StudioRule $r): array => [
                'code' => $r->code,
                'revision' => (int) $r->revision,
                'source' => $r->source,
                'enforcement' => $r->enforcement,
                'status' => $r->status,
                'description' => $r->description,
                'type' => $r->type,
                'layers' => array_values($r->layers),
                'priority' => $r->priority,
                'refs' => $r->refs,
            ])
            ->all();
    }

    public function legacyCount(): int
    {
        return StudioRule::query()->where('source', StudioRule::SOURCE_LEGACY)->count();
    }

    /**
     * Copia `LEGACY_BASELINE_V1` (las reglas del código, {@see StudioCatalog::legacyRules()})
     * a la tabla. Idempotente: crea las que faltan, SINCRONIZA las que alguien
     * alteró a mano (y lo audita) y no toca las que ya coinciden. Nunca toca
     * una regla del Studio: si una ocupara un código del código, se aborta sin
     * escribir nada. Todo en una transacción: o se aplica entero o nada.
     *
     * @return array{created:list<string>,updated:list<string>,unchanged:list<string>,orphans:list<string>}
     */
    public function importLegacyBaseline(bool $dryRun): array
    {
        $plan = ['created' => [], 'updated' => [], 'unchanged' => [], 'orphans' => []];
        $acciones = [];

        $existentes = StudioRule::query()->whereIn('code', array_column(StudioCatalog::legacyRules(), 'code'))->get()->keyBy('code');
        foreach (StudioCatalog::legacyRules() as $r) {
            $esperado = self::legacyRow($r);
            $fila = $existentes->get($r['code']);

            if ($fila === null) {
                $plan['created'][] = $r['code'];
                $acciones[] = ['create', $r['code'], $esperado, null, []];

                continue;
            }
            if ($fila->source !== StudioRule::SOURCE_LEGACY) {
                throw new UltronStudioException(
                    "La regla {$r['code']} existe y no es del código (source={$fila->source}). No se importa nada: una regla del Studio no se pisa.",
                    'legacy_code_taken',
                );
            }

            $cambios = [];
            foreach ($esperado as $campo => $valor) {
                $actual = $fila->getAttribute($campo);
                if ($actual instanceof \DateTimeInterface) {
                    $actual = $actual->format(DATE_ATOM);
                }
                if (json_encode($actual) !== json_encode($valor)) {
                    $cambios[] = ['field' => $campo, 'from' => $actual, 'to' => $valor];
                }
            }
            if ($cambios === []) {
                $plan['unchanged'][] = $r['code'];
            } else {
                $plan['updated'][] = $r['code'];
                $acciones[] = ['sync', $r['code'], $esperado, $fila, $cambios];
            }
        }

        // Reglas del código que ya no están en el catálogo: se señalan, no se tocan.
        $plan['orphans'] = StudioRule::query()
            ->where('source', StudioRule::SOURCE_LEGACY)
            ->whereNotIn('code', array_column(StudioCatalog::legacyRules(), 'code'))
            ->orderBy('code')
            ->pluck('code')
            ->all();

        if ($dryRun || $acciones === []) {
            return $plan;
        }

        DB::transaction(function () use ($acciones, $plan): void {
            foreach ($acciones as [$tipo, $codigo, $esperado, $fila, $cambios]) {
                if ($tipo === 'create') {
                    $regla = StudioRule::query()->forceCreate($esperado + ['code' => $codigo, 'revision' => 1]);
                    $this->audit->record(null, null, 'rule.legacy_imported', StudioAuditEvent::SUBJECT_RULE, $regla->uuid,
                        "Importó la regla del código {$codigo}", [
                            'toStatus' => StudioRule::STATUS_ACTIVE,
                            'contentHash' => $esperado['content_hash'],
                            'diff' => ['code' => $codigo, 'revision' => 1],
                        ]);

                    continue;
                }

                $valores = $esperado;
                foreach (['layers', 'refs'] as $json) {
                    $valores[$json] = json_encode($valores[$json], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
                $this->conditionalWrite($fila, (int) $fila->revision, $valores + [
                    'revision' => (int) $fila->revision + 1,
                    // NULL = el comando (contrato §A1).
                    'updated_by_admin_id' => null,
                    'updated_by_name' => null,
                ], legacy: true);

                $this->audit->record(null, null, 'rule.legacy_synced', StudioAuditEvent::SUBJECT_RULE, $fila->uuid,
                    "Sincronizó la regla del código {$codigo} con el código", [
                        'fromStatus' => $fila->status,
                        'toStatus' => StudioRule::STATUS_ACTIVE,
                        'contentHash' => $esperado['content_hash'],
                        'diff' => ['fromRevision' => (int) $fila->revision, 'toRevision' => (int) $fila->revision + 1, 'changes' => $cambios],
                    ]);
            }

            $this->audit->record(null, null, 'system.baseline_imported', StudioAuditEvent::SUBJECT_SYSTEM, null,
                StudioCatalog::BASELINE_LABEL.': '.count($plan['created']).' nuevas, '.count($plan['updated']).' sincronizadas, '
                .count($plan['unchanged']).' sin cambios', [
                    'diff' => ['created' => $plan['created'], 'updated' => $plan['updated'], 'unchanged' => $plan['unchanged']],
                    'metadata' => ['catalogVersion' => StudioCatalog::VERSION, 'label' => StudioCatalog::BASELINE_LABEL],
                ]);
        });

        return $plan;
    }

    /**
     * RuleDto, con el estado de cada clave de conocimiento citada. Una sola
     * consulta para todas las reglas.
     *
     * @param  list<StudioRule>  $reglas
     * @return list<array<string, mixed>>
     */
    public function present(array $reglas): array
    {
        $claves = [];
        foreach ($reglas as $r) {
            foreach ($r->refs['knowledgeKeys'] ?? [] as $k) {
                $claves[$k] = true;
            }
        }
        $conocimiento = $claves === [] ? collect() : MarketingKnowledgeItem::query()
            ->whereIn('key', array_keys($claves))
            ->get(['key', 'review_status', 'is_active'])
            ->keyBy('key');

        return array_map(function (StudioRule $r) use ($conocimiento): array {
            $refs = self::refsShape($r->refs);

            return [
                'uuid' => $r->uuid,
                'code' => $r->code,
                'description' => $r->description,
                'type' => $r->type,
                'priority' => $r->priority,
                'layers' => array_values($r->layers),
                'refs' => $refs,
                'source' => $r->source,
                'enforcement' => $r->enforcement,
                'status' => $r->status,
                'origin' => $r->origin,
                'revision' => (int) $r->revision,
                'knowledgeRefs' => array_map(function (string $k) use ($conocimiento): array {
                    $item = $conocimiento->get($k);

                    return [
                        'key' => $k,
                        'exists' => $item !== null,
                        'reviewStatus' => $item?->review_status,
                        'isActive' => $item !== null ? (bool) $item->is_active : null,
                    ];
                }, $refs['knowledgeKeys']),
                'createdBy' => $r->created_by_name !== null ? ['name' => $r->created_by_name, 'role' => null] : null,
                'updatedBy' => $r->updated_by_name !== null ? ['name' => $r->updated_by_name, 'role' => null] : null,
                'createdAt' => $r->created_at?->toIso8601String(),
                'updatedAt' => $r->updated_at?->toIso8601String(),
                'archivedAt' => $r->archived_at?->toIso8601String(),
                'archiveReason' => $r->archive_reason,
            ];
        }, $reglas);
    }

    public function findByUuid(string $uuid): StudioRule
    {
        return StudioRule::query()->where('uuid', strtolower($uuid))->first()
            ?? throw UltronStudioException::ruleNotFound();
    }

    /**
     * Las referencias de una regla, validadas y en su forma completa. Lo que el
     * catálogo o la base de conocimiento no conocen es 422.
     *
     * @return array{configs:list<array{layer:string,key:string}>,edges:list<string>,knowledgeKeys:list<string>,knowledgeCategories:list<string>}
     *
     * @throws ValidationException
     */
    public function refs(mixed $refs): array
    {
        $refs = is_array($refs) ? $refs : [];
        $errores = [];
        $parametros = StudioCatalog::parameters();

        $configs = [];
        $vistas = [];
        foreach (array_values($refs['configs'] ?? []) as $i => $c) {
            $capa = (string) ($c['layer'] ?? '');
            $clave = (string) ($c['key'] ?? '');
            if (! in_array($clave, array_column($parametros[$capa] ?? [], 'key'), true)) {
                $errores["refs.configs.{$i}.key"][] = 'Ese parámetro no existe en esa capa del catálogo.';

                continue;
            }
            if (isset($vistas["{$capa}.{$clave}"])) {
                $errores["refs.configs.{$i}"][] = 'Referencia repetida.';

                continue;
            }
            $vistas["{$capa}.{$clave}"] = true;
            $configs[] = ['layer' => $capa, 'key' => $clave];
        }

        $claves = array_values($refs['knowledgeKeys'] ?? []);
        if ($claves !== []) {
            $existen = MarketingKnowledgeItem::query()->whereIn('key', $claves)->pluck('key')->all();
            foreach ($claves as $i => $k) {
                if (! in_array($k, $existen, true)) {
                    $errores["refs.knowledgeKeys.{$i}"][] = 'Esa clave no existe en la base de conocimiento.';
                }
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return [
            'configs' => $configs,
            'edges' => array_values($refs['edges'] ?? []),
            'knowledgeKeys' => $claves,
            'knowledgeCategories' => array_values($refs['knowledgeCategories'] ?? []),
        ];
    }

    /**
     * Lo que hace falta para editar o archivar: que sea del Studio, que no esté
     * archivada y que nadie la haya cambiado desde que se leyó.
     */
    private function assertEditable(StudioRule $regla, int $baseRevision): void
    {
        if ($regla->isLegacy()) {
            throw UltronStudioException::legacyRuleReadOnly();
        }
        if ($regla->isArchived()) {
            throw UltronStudioException::ruleArchived();
        }
        if ((int) $regla->revision !== $baseRevision) {
            throw UltronStudioException::ruleConflict($this->present([$regla])[0]);
        }
    }

    /**
     * `UPDATE … WHERE id = ? AND revision = ?` (y, desde la API, solo sobre una
     * regla del Studio sin archivar). Cero filas: alguien se adelantó.
     *
     * @param  array<string, mixed>  $values
     */
    private function conditionalWrite(StudioRule $regla, int $baseRevision, array $values, bool $legacy = false): void
    {
        // Este UPDATE no pasa por el modelo: la guarda «una regla del Studio
        // nunca se activa ni cambia de identidad» se repite aquí en código, no
        // solo en el CHECK de PostgreSQL (revisión de seguridad, H6).
        if (! $legacy) {
            $intocables = array_intersect(array_keys($values), ['source', 'enforcement', 'code', 'uuid', 'client_request_id', 'created_by_admin_id', 'created_by_name', 'origin']);
            if ($intocables !== [] || (array_key_exists('status', $values) && $values['status'] !== StudioRule::STATUS_ARCHIVED)) {
                throw new \LogicException('Una regla del Studio solo cambia de contenido o se archiva: '.implode(', ', array_keys($values)));
            }
        }

        $consulta = StudioRule::query()->whereKey($regla->id)->where('revision', $baseRevision);
        if (! $legacy) {
            $consulta->where('source', StudioRule::SOURCE_STUDIO)->where('status', '<>', StudioRule::STATUS_ARCHIVED);
        }

        if ($consulta->update($values) === 1) {
            return;
        }

        $actual = $regla->fresh();
        if ($actual !== null && $actual->isArchived()) {
            throw UltronStudioException::ruleArchived();
        }

        throw UltronStudioException::ruleConflict($actual !== null ? $this->present([$actual])[0] : []);
    }

    /**
     * En PostgreSQL, un cerrojo de transacción serializa a quienes crean reglas
     * a la vez: así dos creaciones no calculan el mismo S-NNN (sin él, cuatro
     * peticiones simultáneas agotaban los reintentos y una recibía un 500).
     * SQLite escribe en serie por sí mismo. El UNIQUE de `code` y el reintento
     * de {@see create()} siguen como red de seguridad.
     */
    private function serializeCodeAllocation(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('ultron_studio_rules:code')]);
        }
    }

    /**
     * El siguiente S-NNN. Dos creaciones simultáneas pueden calcular el mismo:
     * el UNIQUE de `code` para a la segunda y {@see create()} reintenta.
     */
    private function nextStudioCode(): string
    {
        $mayor = 0;
        foreach (StudioRule::query()->where('code', 'like', 'S-%')->pluck('code') as $codigo) {
            if (preg_match('/^S-(\d+)$/', (string) $codigo, $m) === 1) {
                $mayor = max($mayor, (int) $m[1]);
            }
        }

        return sprintf('S-%03d', $mayor + 1);
    }

    /**
     * La misma petición repetida (doble clic, reintento de red) encuentra la
     * regla de la primera. La misma llave con otro contenido, o de otra
     * persona, es 409: no es la misma operación.
     */
    private function replay(StudioAccess $access, string $clientRequestId, string $hash): ?StudioRule
    {
        $previa = StudioRule::query()->where('client_request_id', strtolower($clientRequestId))->first();
        if ($previa === null) {
            return null;
        }

        $mismaPersona = (int) $previa->created_by_admin_id === $access->id();
        // Si ya se editó, su huella cambió: basta que sea de la misma persona.
        $mismoContenido = (int) $previa->revision > 1 || hash_equals($previa->content_hash, $hash);
        if (! $mismaPersona || ! $mismoContenido) {
            throw UltronStudioException::clientRequestReused();
        }

        return $previa;
    }

    /**
     * La fila que corresponde a una regla del código, tal como la escribe el
     * importador (sin código ni revisión).
     *
     * @param  array<string, mixed>  $r  un elemento de {@see StudioCatalog::legacyRules()}
     * @return array<string, mixed>
     */
    private static function legacyRow(array $r): array
    {
        return [
            'source' => StudioRule::SOURCE_LEGACY,
            'enforcement' => StudioRule::ENFORCEMENT_CODE,
            'status' => StudioRule::STATUS_ACTIVE,
            'description' => $r['description'],
            'type' => $r['type'],
            'priority' => $r['priority'],
            'layers' => $r['layers'],
            'refs' => $r['refs'],
            'origin' => $r['origin'],
            'content_hash' => StudioContentHasher::ruleHash($r),
            'archived_at' => null,
            'archive_reason' => null,
        ];
    }

    /**
     * `refs` siempre con sus cuatro listas.
     *
     * @return array{configs:list<array{layer:string,key:string}>,edges:list<string>,knowledgeKeys:list<string>,knowledgeCategories:list<string>}
     */
    private static function refsShape(mixed $refs): array
    {
        $refs = is_array($refs) ? $refs : [];

        return [
            'configs' => array_values($refs['configs'] ?? []),
            'edges' => array_values($refs['edges'] ?? []),
            'knowledgeKeys' => array_values($refs['knowledgeKeys'] ?? []),
            'knowledgeCategories' => array_values($refs['knowledgeCategories'] ?? []),
        ];
    }
}
