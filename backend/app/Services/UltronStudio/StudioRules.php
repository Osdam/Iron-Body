<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\MarketingKnowledgeItem;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioRule;
use Illuminate\Database\Eloquent\Collection;
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
 * CONDICIONES (Hito G, contrato G §A3). Una regla puede llevar `conditions`
 * (`{when, then}`): la valida y canoniza {@see StudioRuleCoherence} contra el
 * catálogo, y lo que choca con un control del motor (una restricción que
 * bloquea, una arista obligatoria o una regla del código) es un 422. Lo que
 * contradice otra regla del Studio VIVA es un 409 `rule_contradiction`, y para
 * eso hacen falta las reglas vivas: crear, editar e importar toman el MISMO
 * cerrojo de transacción que reparte los códigos ({@see serializeRuleWrites()})
 * ANTES de leerlas. Sin él, dos escrituras simultáneas y contradictorias entre
 * sí pasarían las dos. Archivar no lo toma: quitar una regla no crea
 * contradicciones. Cada RuleDto lleva su `analysis`, calculado sobre las reglas
 * vivas y los controles.
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

    /** Campos que se pueden editar de una regla del Studio. `conditions` desde el Hito G. */
    public const EDITABLE = ['description', 'type', 'priority', 'layers', 'refs', 'conditions'];

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
     * `conditions` llega TAL COMO LA MANDÓ EL CLIENTE (sin los recortes de los
     * middleware): la canonización es la validación del borde, igual que el
     * normalizador del borrador, y tiene que ver lo mismo que el CRM.
     *
     * @param  array{description:string,type:string,priority:string,layers:list<string>,refs?:array<string,mixed>|null,conditions?:mixed}  $data
     * @return array{rule:array<string,mixed>,replayed:bool}
     *
     * @throws ValidationException 422 con las claves `refs.*`, `conditions.*` y `layers` a la vez
     * @throws UltronStudioException 409 `rule_contradiction`, `rule_limit_reached`, `client_request_reused`
     */
    public function create(StudioAccess $access, Request $request, array $data, string $clientRequestId): array
    {
        $access->require(StudioAccess::MANAGE_RULES);

        $capas = array_values($data['layers']);
        [$refs, $conditions] = $this->validatedContent($data['refs'] ?? null, $data['conditions'] ?? null, $capas);

        $campos = [
            'description' => $data['description'],
            'type' => $data['type'],
            'priority' => $data['priority'],
            'layers' => $capas,
            'refs' => $refs,
            'origin' => null,
            'conditions' => $conditions,
        ];
        $hash = StudioContentHasher::ruleHash($campos);

        for ($intento = 1; ; $intento++) {
            if ($previa = $this->replay($access, $clientRequestId, $hash)) {
                return ['rule' => $this->present([$previa])[0], 'replayed' => true];
            }

            try {
                $repetida = false;
                $regla = DB::transaction(function () use ($access, $request, $campos, $hash, $clientRequestId, &$repetida): StudioRule {
                    $this->serializeRuleWrites();
                    // La misma petición pudo confirmarse mientras esta esperaba
                    // el cerrojo (doble clic simultáneo) y la comprobación de
                    // arriba no la vio. Se repite aquí, ANTES del tope: con 99
                    // vivas, el segundo clic recibe su 200 `replayed` y no un
                    // 409 `rule_limit_reached` por la regla que creó el primero
                    // (tester del Hito G, H-3; el orden venía de F).
                    if ($previa = $this->replay($access, $clientRequestId, $hash)) {
                        $repetida = true;

                        return $previa;
                    }

                    $vivas = self::liveStudioRows();
                    if ($vivas->count() >= self::MAX_LIVE_STUDIO_RULES) {
                        throw UltronStudioException::ruleLimitReached(self::MAX_LIVE_STUDIO_RULES);
                    }

                    $codigo = $this->nextStudioCode();
                    $this->assertNoContradiction($campos + [
                        'code' => $codigo,
                        'source' => StudioRule::SOURCE_STUDIO,
                        'enforcement' => StudioRule::ENFORCEMENT_NONE,
                        'status' => StudioRule::STATUS_DRAFT,
                    ], $vivas);

                    $yo = $access->snapshot();
                    $regla = StudioRule::query()->forceCreate($campos + [
                        'code' => $codigo,
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
                        ], maxValueLength: StudioAudit::MAX_RULE_VALUE_LENGTH);

                    return $regla;
                });

                return ['rule' => $this->present([$regla->refresh()])[0], 'replayed' => $repetida];
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
     * `conditions` (si llega) es la del cliente sin recortar, como en
     * {@see create()}; `null` la quita y la regla vuelve a ser documental.
     *
     * @param  array<string, mixed>  $fields  solo los que llegaron
     * @return array<string, mixed>
     *
     * @throws ValidationException 422 con las claves `refs.*`, `conditions.*` y `layers`
     * @throws UltronStudioException 409 `legacy_rule_read_only`, `rule_archived`, `rule_conflict`, `rule_contradiction`
     */
    public function update(StudioAccess $access, Request $request, StudioRule $regla, int $baseRevision, array $fields): array
    {
        $access->require(StudioAccess::MANAGE_RULES);
        $this->assertEditable($regla, $baseRevision);

        $nuevos = [];
        foreach (self::EDITABLE as $campo) {
            if (array_key_exists($campo, $fields)) {
                $nuevos[$campo] = $fields[$campo];
            }
        }
        if (isset($nuevos['layers'])) {
            $nuevos['layers'] = array_values($nuevos['layers']);
        }
        $nuevos = $this->validatedChanges($regla, $nuevos);

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
                'layers' => $regla->layers, 'refs' => $regla->refs, 'origin' => $regla->origin, 'conditions' => $regla->conditions],
            $nuevos,
        );
        $hash = StudioContentHasher::ruleHash($completo);

        $actualizada = DB::transaction(function () use ($access, $request, $regla, $baseRevision, $nuevos, $completo, $hash, $cambios): StudioRule {
            // El cerrojo va ANTES de leer las reglas vivas: una edición y otra
            // escritura que la contradice no pueden pasar a la vez.
            $this->serializeRuleWrites();
            if (array_key_exists('conditions', $nuevos) && $nuevos['conditions'] !== null) {
                $this->assertNoContradiction($completo + [
                    'code' => $regla->code,
                    'source' => $regla->source,
                    'enforcement' => $regla->enforcement,
                    'status' => $regla->status,
                ], self::liveStudioRows());
            }

            $yo = $access->snapshot();
            $valores = $nuevos;
            foreach (['layers', 'refs', 'conditions'] as $json) {
                // El UPDATE del constructor no aplica los casts del modelo; un
                // `conditions` null queda NULL, no el texto «null».
                if (array_key_exists($json, $valores) && $valores[$json] !== null) {
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
                ], maxValueLength: StudioAudit::MAX_RULE_VALUE_LENGTH);

            return $regla->refresh();
        });

        return $this->present([$actualizada])[0];
    }

    /**
     * POST rules/{uuid}/archive. Sin el cerrojo de las escrituras: quitar una
     * regla no crea contradicciones.
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
                ], maxValueLength: StudioAudit::MAX_RULE_VALUE_LENGTH);

            return $regla->refresh();
        });

        return $this->present([$archivada])[0];
    }

    /**
     * Las reglas que copia una versión: todas las no archivadas, ordenadas por
     * código y reducidas a los campos de su formato
     * ({@see StudioContentHasher::versionRuleFields()}): en el 1 (F),
     * exactamente los de F; en el 2 (G), además `conditions`. Son también las
     * reglas con las que se evalúa el borrador: las mismas que copiará.
     *
     * @return list<array<string, mixed>>
     */
    public static function snapshotForVersion(int $format = StudioContentHasher::LATEST_FORMAT): array
    {
        $campos = StudioContentHasher::versionRuleFields($format);

        return self::liveRows()
            ->map(fn (StudioRule $r): array => self::pickInOrder([
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
                'conditions' => $r->conditions,
            ], $campos))
            ->values()
            ->all();
    }

    /**
     * Las reglas de la línea base del código (`StudioCatalog::legacyRules()`)
     * con la forma de las que copia una versión de formato 2, para
     * `GET compare` (contrato G §A4). La revisión es la que tendrían en la
     * tabla tras `import-baseline`: la de su fila si ya coincide con el código,
     * la siguiente si el comando aún tiene que sincronizarla y 1 si no existe.
     * Así, comparar la línea base con un borrador que no la cambia da «sin
     * diferencias» con la tabla al día, y no una revisión inventada.
     *
     * @return list<array<string, mixed>>
     */
    public static function baselineSnapshot(): array
    {
        $codigos = array_column(StudioCatalog::legacyRules(), 'code');
        $filas = StudioRule::query()->whereIn('code', $codigos)->get()->keyBy('code');

        $out = [];
        foreach (StudioCatalog::legacyRules() as $r) {
            $fila = $filas->get($r['code']);
            $revision = 1;
            if ($fila !== null && $fila->source === StudioRule::SOURCE_LEGACY) {
                $revision = (int) $fila->revision + (self::legacyChanges(self::legacyRow($r), $fila) === [] ? 0 : 1);
            }
            $out[] = self::pickInOrder([
                'code' => $r['code'],
                'revision' => $revision,
                'source' => StudioRule::SOURCE_LEGACY,
                'enforcement' => StudioRule::ENFORCEMENT_CODE,
                'status' => StudioRule::STATUS_ACTIVE,
                'description' => $r['description'],
                'type' => $r['type'],
                'layers' => $r['layers'],
                'priority' => $r['priority'],
                'refs' => $r['refs'],
                'conditions' => $r['conditions'],
            ], StudioContentHasher::versionRuleFields(StudioContentHasher::FORMAT_2));
        }

        return $out;
    }

    public function legacyCount(): int
    {
        return StudioRule::query()->where('source', StudioRule::SOURCE_LEGACY)->count();
    }

    /**
     * Copia `LEGACY_BASELINE_V1` (las reglas del código, {@see StudioCatalog::legacyRules()})
     * a la tabla. Idempotente: crea las que faltan, SINCRONIZA las que alguien
     * alteró a mano o las que cambió el código (y lo audita) y no toca las que
     * ya coinciden. Nunca toca una regla del Studio: si una ocupara un código
     * del código, se aborta sin escribir nada. Todo en una transacción: o se
     * aplica entero o nada.
     *
     * Desde el Hito G la tabla se lee DENTRO de la transacción y después del
     * cerrojo de las escrituras ({@see serializeRuleWrites()}): una importación
     * simultánea a otra (o a una edición) espera y calcula su plan con lo que
     * la otra dejó.
     *
     * @return array{created:list<string>,updated:list<string>,unchanged:list<string>,orphans:list<string>}
     */
    public function importLegacyBaseline(bool $dryRun): array
    {
        if ($dryRun) {
            return self::legacyPlan()['plan'];
        }

        return DB::transaction(function (): array {
            $this->serializeRuleWrites();
            ['plan' => $plan, 'actions' => $acciones] = self::legacyPlan();
            if ($acciones === []) {
                return $plan;
            }

            foreach ($acciones as [$tipo, $codigo, $esperado, $fila, $cambios]) {
                if ($tipo === 'create') {
                    $regla = StudioRule::query()->forceCreate($esperado + ['code' => $codigo, 'revision' => 1]);
                    $this->audit->record(null, null, 'rule.legacy_imported', StudioAuditEvent::SUBJECT_RULE, $regla->uuid,
                        "Importó la regla del código {$codigo}", [
                            'toStatus' => StudioRule::STATUS_ACTIVE,
                            'contentHash' => $esperado['content_hash'],
                            'diff' => ['code' => $codigo, 'revision' => 1],
                        ], maxValueLength: StudioAudit::MAX_RULE_VALUE_LENGTH);

                    continue;
                }

                $valores = $esperado;
                foreach (['layers', 'refs', 'conditions'] as $json) {
                    if ($valores[$json] !== null) {
                        $valores[$json] = json_encode($valores[$json], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    }
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
                    ], maxValueLength: StudioAudit::MAX_RULE_VALUE_LENGTH);
            }

            $this->audit->record(null, null, 'system.baseline_imported', StudioAuditEvent::SUBJECT_SYSTEM, null,
                StudioCatalog::BASELINE_LABEL.': '.count($plan['created']).' nuevas, '.count($plan['updated']).' sincronizadas, '
                .count($plan['unchanged']).' sin cambios', [
                    'diff' => ['created' => $plan['created'], 'updated' => $plan['updated'], 'unchanged' => $plan['unchanged']],
                    'metadata' => ['catalogVersion' => StudioCatalog::VERSION, 'label' => StudioCatalog::BASELINE_LABEL],
                ]);

            return $plan;
        });
    }

    /**
     * RuleDto, con el estado de cada clave de conocimiento citada y, desde el
     * Hito G, `conditions` y `analysis`. Dos consultas para todas las reglas:
     * la base de conocimiento y las reglas vivas del análisis.
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
        $analisis = $reglas === [] ? [] : self::analyses($reglas);

        return array_map(function (StudioRule $r) use ($conocimiento, $analisis): array {
            $refs = self::refsShape($r->refs);

            return [
                'uuid' => $r->uuid,
                'code' => $r->code,
                'description' => $r->description,
                'type' => $r->type,
                'priority' => $r->priority,
                'layers' => array_values($r->layers),
                'refs' => $refs,
                'conditions' => $r->conditions,
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
                'analysis' => $analisis[$r->id],
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
     * `refs` y `conditions` de una regla nueva, validadas a la vez: un 422 con
     * TODAS las claves (`refs.*`, `conditions.*` y `layers`), no solo las del
     * primer bloque que falla.
     *
     * @param  list<string>  $layers
     * @return array{0:array<string,list<mixed>>,1:array<string,mixed>|null}
     *
     * @throws ValidationException
     */
    private function validatedContent(mixed $refs, mixed $conditions, array $layers): array
    {
        $errores = [];
        $refsValidas = null;
        try {
            $refsValidas = $this->refs($refs);
        } catch (ValidationException $e) {
            $errores = $e->errors();
        }

        $coherencia = StudioRuleCoherence::check($conditions, StudioCatalog::toArray(), $layers);
        $errores += $coherencia['errors'];
        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return [$refsValidas, $coherencia['conditions']];
    }

    /**
     * Lo que llegó en un PATCH, validado y en su forma canónica. Las
     * condiciones se comprueban con las capas que TENDRÁ la regla; si solo
     * cambian las capas, se comprueba que sigan cubriendo los objetivos de las
     * condiciones guardadas (422 en `layers`).
     *
     * @param  array<string, mixed>  $nuevos
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validatedChanges(StudioRule $regla, array $nuevos): array
    {
        $errores = [];
        if (array_key_exists('refs', $nuevos)) {
            try {
                $nuevos['refs'] = $this->refs($nuevos['refs']);
            } catch (ValidationException $e) {
                $errores = $e->errors();
            }
        }

        $capas = $nuevos['layers'] ?? array_values($regla->layers);
        if (array_key_exists('conditions', $nuevos)) {
            $coherencia = StudioRuleCoherence::check($nuevos['conditions'], StudioCatalog::toArray(), $capas);
            $errores += $coherencia['errors'];
            $nuevos['conditions'] = $coherencia['conditions'];
        } elseif (array_key_exists('layers', $nuevos) && $regla->conditions !== null) {
            // Las condiciones guardadas no se tocan: de su comprobación solo
            // cuenta si las capas nuevas siguen cubriendo sus objetivos.
            $coherencia = StudioRuleCoherence::check($regla->conditions, StudioCatalog::toArray(), $capas);
            if (isset($coherencia['errors']['layers'])) {
                $errores['layers'] = $coherencia['errors']['layers'];
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $nuevos;
    }

    /**
     * 409 `rule_contradiction` si las condiciones de `$candidate` contradicen
     * alguna regla del Studio VIVA de `$vivas` (ella misma no cuenta). Lo que
     * choca con un control del motor ya es un 422 de
     * {@see StudioRuleCoherence::check()}: aquí solo quedan las otras reglas.
     *
     * @param  array<string, mixed>  $candidate  RuleInput (contrato G §A9)
     * @param  Collection<int, StudioRule>  $vivas  las reglas del Studio sin archivar, por código
     *
     * @throws UltronStudioException
     */
    private function assertNoContradiction(array $candidate, Collection $vivas): void
    {
        if (($candidate['conditions'] ?? null) === null) {
            return;
        }

        $porCodigo = $vivas->keyBy('code');
        $conflictos = [];
        $choques = StudioRuleCoherence::contradictions($candidate, array_map(self::input(...), $vivas->all()), StudioCatalog::toArray());
        foreach ($choques as $c) {
            if ($c['kind'] !== 'rule') {
                continue;
            }
            $otra = $porCodigo[$c['with']];
            $conflictos[] = [
                'code' => $otra->code,
                'uuid' => $otra->uuid,
                'revision' => (int) $otra->revision,
                'target' => $c['target'],
                'label' => $c['label'],
            ];
        }

        if ($conflictos !== []) {
            throw UltronStudioException::ruleContradiction($conflictos);
        }
    }

    /**
     * `analysis` de cada regla (contrato G §A3), por id de fila: contra los
     * controles del motor y contra las reglas VIVAS, que se leen y se analizan
     * UNA vez por llamada, y solo si alguna de `$reglas` está viva.
     *
     * Una ARCHIVADA lleva un análisis neutro: su verificación y ningún
     * problema. Ya no entra en las versiones ni en las contradicciones, así que
     * analizarla contra las vivas no aporta nada, y costaba volver a analizar
     * todas las vivas por cada archivada: las archivadas no cuentan para el
     * tope ni se pueden borrar, y con mil `GET rules?status=archived` tardaba
     * ~8 s (revisión de seguridad de la API del Hito G, M-1).
     *
     * @param  list<StudioRule>  $reglas
     * @return array<int, array{verification:string,issues:list<array{id:string,level:string,with:list<string>,target:string}>}>
     */
    private static function analyses(array $reglas): array
    {
        $out = [];
        $vivasPedidas = [];
        foreach ($reglas as $r) {
            if ($r->isArchived()) {
                $out[$r->id] = ['verification' => StudioRuleCoherence::verification($r->conditions), 'issues' => []];
            } else {
                $vivasPedidas[] = $r;
            }
        }
        if ($vivasPedidas === []) {
            return $out;
        }

        $catalogo = StudioCatalog::toArray();
        $vivas = array_map(self::input(...), self::liveRows()->all());
        $deLasVivas = StudioRuleCoherence::analyzeAll($vivas, $catalogo);
        foreach ($vivasPedidas as $r) {
            // Si otra petición la archivó entre las dos lecturas, ya no está
            // entre las vivas: se analiza ella sola contra ellas.
            $out[$r->id] = $deLasVivas[$r->code] ?? StudioRuleCoherence::analyze(self::input($r), $vivas, $catalogo);
        }

        return $out;
    }

    /**
     * RuleInput (contrato G §A9) de una fila: lo que necesita la coherencia.
     *
     * @return array{code:string,source:string,enforcement:string,status:string,description:string,layers:list<string>,conditions:array<string,mixed>|null,refs:array<string,list<mixed>>}
     */
    private static function input(StudioRule $r): array
    {
        return [
            'code' => $r->code,
            'source' => $r->source,
            'enforcement' => $r->enforcement,
            'status' => $r->status,
            'description' => $r->description,
            'layers' => array_values($r->layers),
            'conditions' => $r->conditions,
            'refs' => self::refsShape($r->refs),
        ];
    }

    /** @return Collection<int, StudioRule> Las reglas no archivadas (de las dos procedencias), por código. */
    private static function liveRows(): Collection
    {
        return StudioRule::query()->where('status', '<>', StudioRule::STATUS_ARCHIVED)->orderBy('code')->get();
    }

    /** @return Collection<int, StudioRule> Las reglas del Studio no archivadas, por código. */
    private static function liveStudioRows(): Collection
    {
        return StudioRule::query()
            ->where('source', StudioRule::SOURCE_STUDIO)
            ->where('status', '<>', StudioRule::STATUS_ARCHIVED)
            ->orderBy('code')
            ->get();
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
        // `conditions` no es identidad ni estado: se edita como el resto.
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
     * EL cerrojo de las escrituras de reglas. En PostgreSQL, un cerrojo de
     * transacción serializa a quienes crean, editan o importan reglas a la vez:
     *
     *  - dos creaciones no calculan el mismo S-NNN (sin él, cuatro peticiones
     *    simultáneas agotaban los reintentos y una recibía un 500);
     *  - y desde el Hito G, quien lo tiene lee las reglas vivas sabiendo que
     *    nadie más está escribiendo: dos reglas que se contradicen entre sí no
     *    pueden guardarse a la vez (una recibe 409 `rule_contradiction`).
     *
     * Se toma ANTES de leer nada. SQLite escribe en serie por sí mismo. El
     * UNIQUE de `code` y el reintento de {@see create()} siguen como red de
     * seguridad. La clave es la de F: es el MISMO cerrojo (contrato G §A3).
     *
     * Y solo sirve en READ COMMITTED, así que se FIJA aquí, como primera
     * sentencia de la transacción (lo es en las tres que llaman a esto). En
     * REPEATABLE READ o SERIALIZABLE la instantánea la toma la propia sentencia
     * del cerrojo, ANTES de esperar: quien esperó leería las reglas vivas de
     * antes de que la otra escritura confirmara, y dos reglas contradictorias
     * se guardarían (revisión de seguridad de la API del Hito G, B-2: 3 de 3
     * en una sesión con REPEATABLE READ). Hoy PostgreSQL trae READ COMMITTED
     * por defecto y producción lo confirma, pero nada lo garantizaba: un
     * `default_transaction_isolation` en el rol o en `postgresql.conf` lo
     * habría roto sin avisar. PostgreSQL acepta repetir el mismo nivel en
     * cualquier momento (también dentro de un punto de guardado); cambiarlo
     * cuando la transacción ya leyó algo es un error, nunca un fallo silencioso.
     */
    private function serializeRuleWrites(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
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
     * Qué haría la importación con la tabla tal como está: las reglas que
     * crearía, las que sincronizaría (con sus cambios), las que no tocaría y
     * las huérfanas. Solo lee.
     *
     * @return array{plan:array{created:list<string>,updated:list<string>,unchanged:list<string>,orphans:list<string>},actions:list<array{0:string,1:string,2:array<string,mixed>,3:StudioRule|null,4:list<array<string,mixed>>}>}
     *
     * @throws UltronStudioException `legacy_code_taken` si una regla del Studio ocupa un código del código
     */
    private static function legacyPlan(): array
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

            $cambios = self::legacyChanges($esperado, $fila);
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

        return ['plan' => $plan, 'actions' => $acciones];
    }

    /**
     * En qué difiere una fila de lo que el código dice que tiene que ser.
     *
     * @param  array<string, mixed>  $esperado  {@see legacyRow()}
     * @return list<array{field:string,from:mixed,to:mixed}>
     */
    private static function legacyChanges(array $esperado, StudioRule $fila): array
    {
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

        return $cambios;
    }

    /**
     * La fila que corresponde a una regla del código, tal como la escribe el
     * importador (sin código ni revisión). Desde el Hito G, con sus
     * condiciones (null en las documentales).
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
            'conditions' => $r['conditions'],
            'origin' => $r['origin'],
            'content_hash' => StudioContentHasher::ruleHash($r),
            'archived_at' => null,
            'archive_reason' => null,
        ];
    }

    /**
     * Los campos `$fields` de `$values`, en el orden de `$fields`: el de
     * {@see StudioContentHasher::versionRuleFields()}, que es el de F con
     * `conditions` al final en el formato 2.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private static function pickInOrder(array $values, array $fields): array
    {
        $out = [];
        foreach ($fields as $campo) {
            $out[$campo] = $values[$campo];
        }

        return $out;
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
