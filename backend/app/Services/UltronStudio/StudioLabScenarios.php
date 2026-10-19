<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabScenario;
use App\Models\UltronStudio\StudioRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Los escenarios del laboratorio (contrato H4 §A2, rutas 23 y 28–30, y la
 * importación del catálogo).
 *
 *  - Los del CATÁLOGO (C-01…, {@see StudioLabCatalog}) los copia el comando
 *    `ultron-studio:lab-import-scenarios` y son de SOLO LECTURA: tocarlos desde
 *    la API es 409 `catalog_scenario_read_only`.
 *  - Los del PERSONAL nacen E-NNN con `ultron.lab_manage`; se editan con
 *    `baseRevision` (UPDATE condicionado, 409 `scenario_conflict` con
 *    `current`) y se archivan con motivo. Como mucho
 *    {@see StudioLabCatalog::MAX_LIVE_SCENARIOS} vivos. Nada se borra.
 *
 * EL TEXTO VA A UN PROVEEDOR EXTERNO. Todo texto de un escenario pasa
 * {@see StudioLabPersonalData}: un correo, una cifra de 7 dígitos, `+57` o un
 * carácter invisible son un 422 con el mensaje literal del contrato. Los
 * nombres propios no se pueden detectar: el editor lleva un aviso fijo.
 *
 * Los `id` de los criterios (`c1…`) los asigna el servidor por posición; las
 * reglas que cita un criterio tienen que existir (una R-xx del código o una
 * S-NNN del Studio sin archivar). La huella es la de
 * {@see StudioLabCatalog::scenarioHash()}.
 *
 * Los códigos E-NNN los reparte un cerrojo consultivo de transacción, el mismo
 * patrón que las S-NNN de las reglas ({@see serializeScenarioWrites()}); la
 * importación lo toma también, así que nunca corre a la vez que un alta.
 */
final class StudioLabScenarios
{
    /** La llave de escribir escenarios (contrato §A7, decisión 2 del lead). */
    public const LAB_MANAGE = 'ultron.lab_manage';

    /** Lo que se puede cambiar de un escenario del personal. */
    public const EDITABLE = ['category', 'title', 'description', 'turns', 'facts', 'criteria'];

    private const CODE_ATTEMPTS = 3;

    public function __construct(private readonly StudioLabEvents $events) {}

    /**
     * GET lab/scenarios: una lista SIN paginar (Enmienda 2, §1), por código.
     * Sin `status`, los activos.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $status, ?string $category): array
    {
        return StudioLabScenario::query()
            ->where('status', $status ?? StudioLabScenario::STATUS_ACTIVE)
            ->when($category, fn ($q, string $c) => $q->where('category', $c))
            ->orderBy('code')
            ->get()
            ->map(fn (StudioLabScenario $s): array => self::present($s))
            ->all();
    }

    public function findByUuid(string $uuid): StudioLabScenario
    {
        return StudioLabScenario::query()->where('uuid', strtolower($uuid))->first()
            ?? throw UltronStudioException::scenarioNotFound();
    }

    /**
     * POST lab/scenarios: nace E-NNN y activo. Idempotente por
     * `clientRequestId`: 201 la primera vez, 200 `replayed` al repetir.
     *
     * @param  array<string, mixed>  $data  {category, title, description?, turns, facts?, criteria}, con la forma ya validada
     * @return array{scenario:array<string,mixed>,replayed:bool}
     *
     * @throws ValidationException 422 por campo (datos personales, reglas que no existen, último turno, tamaño)
     * @throws UltronStudioException 409 `scenario_limit_reached`, `client_request_reused`
     */
    public function create(StudioAccess $access, array $data, string $clientRequestId): array
    {
        $access->require(self::LAB_MANAGE);

        $contenido = self::normalize($data);
        self::validate($contenido);
        $hash = StudioLabCatalog::scenarioHash($contenido);

        for ($intento = 1; ; $intento++) {
            if ($previa = $this->replay($access, $clientRequestId, $hash)) {
                return ['scenario' => self::present($previa), 'replayed' => true];
            }

            try {
                $repetida = false;
                $escenario = DB::transaction(function () use ($access, $contenido, $hash, $clientRequestId, &$repetida): StudioLabScenario {
                    $this->serializeScenarioWrites();
                    // La misma petición pudo confirmarse mientras esta esperaba el
                    // cerrojo: el segundo clic recibe su 200 `replayed`, no el tope.
                    if ($previa = $this->replay($access, $clientRequestId, $hash)) {
                        $repetida = true;

                        return $previa;
                    }

                    $vivos = StudioLabScenario::query()
                        ->where('source', StudioLabScenario::SOURCE_STUDIO)
                        ->where('status', StudioLabScenario::STATUS_ACTIVE)
                        ->count();
                    if ($vivos >= StudioLabCatalog::MAX_LIVE_SCENARIOS) {
                        throw UltronStudioException::scenarioLimitReached(StudioLabCatalog::MAX_LIVE_SCENARIOS);
                    }

                    $yo = $access->snapshot();
                    $escenario = StudioLabScenario::query()->forceCreate(self::columns($contenido) + [
                        'code' => $this->nextStudioCode(),
                        'source' => StudioLabScenario::SOURCE_STUDIO,
                        'status' => StudioLabScenario::STATUS_ACTIVE,
                        'content_hash' => $hash,
                        'revision' => 1,
                        'client_request_id' => $clientRequestId,
                        'created_by_admin_id' => $yo['id'],
                        'created_by_name' => $yo['name'],
                    ]);

                    $this->events->record($access->actor, 'scenario.created', StudioLabEvent::SUBJECT_SCENARIO, $escenario->uuid,
                        "Creó el escenario {$escenario->code} «".mb_substr($contenido['title'], 0, 120).'»', [
                            'toStatus' => StudioLabScenario::STATUS_ACTIVE,
                            'diff' => ['code' => $escenario->code] + $contenido,
                        ]);

                    return $escenario;
                });

                return ['scenario' => self::present($escenario->refresh()), 'replayed' => $repetida];
            } catch (UniqueConstraintViolationException $e) {
                // O la misma petición llegó dos veces a la vez (la resuelve el
                // replay de la siguiente vuelta), o dos altas calcularon el mismo
                // código. Fuera de la transacción: en PostgreSQL un error la deja
                // abortada.
                if ($intento >= self::CODE_ATTEMPTS && $this->replay($access, $clientRequestId, $hash) === null) {
                    throw $e;
                }
            }
        }
    }

    /**
     * PATCH lab/scenarios/{uuid}: parcial (Enmienda 2, §2), con `baseRevision`.
     * Sin cambios no hay escritura, ni revisión nueva, ni traza.
     *
     * @param  array<string, mixed>  $fields  solo los que llegaron
     * @return array<string, mixed>
     */
    public function update(StudioAccess $access, StudioLabScenario $escenario, int $baseRevision, array $fields): array
    {
        $access->require(self::LAB_MANAGE);
        $this->assertEditable($escenario, $baseRevision);

        $actual = self::contentOf($escenario);
        $nuevo = $actual;
        foreach (self::EDITABLE as $campo) {
            if (array_key_exists($campo, $fields)) {
                $nuevo[$campo] = $fields[$campo];
            }
        }
        $nuevo = self::normalize($nuevo);
        self::validate($nuevo);

        $cambios = [];
        foreach (self::EDITABLE as $campo) {
            if (json_encode($actual[$campo]) !== json_encode($nuevo[$campo])) {
                $cambios[] = ['field' => $campo, 'from' => $actual[$campo], 'to' => $nuevo[$campo]];
            }
        }
        if ($cambios === []) {
            return self::present($escenario);
        }

        $hash = StudioLabCatalog::scenarioHash($nuevo);
        $actualizado = DB::transaction(function () use ($access, $escenario, $baseRevision, $nuevo, $hash, $cambios): StudioLabScenario {
            $yo = $access->snapshot();
            $this->conditionalWrite($escenario, $baseRevision, self::columns($nuevo, encoded: true) + [
                'content_hash' => $hash,
                'revision' => $baseRevision + 1,
                'updated_by_admin_id' => $yo['id'],
                'updated_by_name' => $yo['name'],
            ]);

            $this->events->record($access->actor, 'scenario.updated', StudioLabEvent::SUBJECT_SCENARIO, $escenario->uuid,
                "Editó el escenario {$escenario->code} (revisión ".($baseRevision + 1).')', [
                    'diff' => ['fromRevision' => $baseRevision, 'toRevision' => $baseRevision + 1, 'changes' => $cambios],
                ]);

            return $escenario->refresh();
        });

        return self::present($actualizado);
    }

    /**
     * POST lab/scenarios/{uuid}/archive: `{reason}` y, si llega, `baseRevision`
     * (Enmienda 2, §3). Sube la revisión: una edición en vuelo choca.
     *
     * @return array<string, mixed>
     */
    public function archive(StudioAccess $access, StudioLabScenario $escenario, string $reason, ?int $baseRevision): array
    {
        $access->require(self::LAB_MANAGE);
        $this->assertEditable($escenario, $baseRevision ?? (int) $escenario->revision);
        $revision = (int) $escenario->revision;

        $archivado = DB::transaction(function () use ($access, $escenario, $reason, $revision): StudioLabScenario {
            $yo = $access->snapshot();
            $this->conditionalWrite($escenario, $revision, [
                'status' => StudioLabScenario::STATUS_ARCHIVED,
                'archived_at' => now(),
                'archive_reason' => $reason,
                'revision' => $revision + 1,
                'updated_by_admin_id' => $yo['id'],
                'updated_by_name' => $yo['name'],
            ]);

            $this->events->record($access->actor, 'scenario.archived', StudioLabEvent::SUBJECT_SCENARIO, $escenario->uuid,
                "Archivó el escenario {$escenario->code}", [
                    'fromStatus' => StudioLabScenario::STATUS_ACTIVE,
                    'toStatus' => StudioLabScenario::STATUS_ARCHIVED,
                    'diff' => ['reason' => $reason, 'fromRevision' => $revision, 'toRevision' => $revision + 1],
                ]);

            return $escenario->refresh();
        });

        return self::present($archivado);
    }

    /**
     * Copia el catálogo de escenarios del código ({@see StudioLabCatalog}) a la
     * tabla. IDEMPOTENTE: crea los que faltan, SINCRONIZA los que cambiaron en
     * el código (o alguien alteró a mano), con la revisión +1, y ARCHIVA los
     * que el código retiró. Nunca toca un escenario del personal: si uno
     * ocupara un código del catálogo, se aborta sin escribir nada. Todo en una
     * transacción, después del cerrojo de las escrituras. Actor `system`;
     * funciona con la bandera apagada.
     *
     * @return array{created:list<string>,updated:list<string>,unchanged:list<string>,retired:list<string>}
     *
     * @throws UltronStudioException `lab_code_taken`
     */
    public function importCatalog(bool $dryRun): array
    {
        if ($dryRun) {
            return self::catalogPlan()['plan'];
        }

        return DB::transaction(function (): array {
            $this->serializeScenarioWrites();
            ['plan' => $plan, 'actions' => $acciones] = self::catalogPlan();
            if ($acciones === []) {
                return $plan;
            }

            foreach ($acciones as [$tipo, $codigo, $esperado, $fila, $cambios]) {
                if ($tipo === 'create') {
                    $escenario = StudioLabScenario::query()->forceCreate($esperado + ['code' => $codigo, 'source' => StudioLabScenario::SOURCE_CATALOG, 'revision' => 1]);
                    $this->events->record(null, 'scenario.catalog_imported', StudioLabEvent::SUBJECT_SCENARIO, $escenario->uuid,
                        "Importó el escenario del catálogo {$codigo}", [
                            'toStatus' => StudioLabScenario::STATUS_ACTIVE,
                            'diff' => ['code' => $codigo, 'revision' => 1, 'catalogVersion' => StudioLabCatalog::VERSION],
                        ]);

                    continue;
                }

                if ($tipo === 'sync') {
                    $this->catalogWrite($fila, $esperado + ['revision' => (int) $fila->revision + 1, 'updated_by_admin_id' => null, 'updated_by_name' => null]);
                    $this->events->record(null, 'scenario.catalog_synced', StudioLabEvent::SUBJECT_SCENARIO, $fila->uuid,
                        "Sincronizó el escenario del catálogo {$codigo} con el código", [
                            'fromStatus' => $fila->status,
                            'toStatus' => StudioLabScenario::STATUS_ACTIVE,
                            'diff' => ['fromRevision' => (int) $fila->revision, 'toRevision' => (int) $fila->revision + 1, 'catalogVersion' => StudioLabCatalog::VERSION, 'changes' => $cambios],
                        ]);

                    continue;
                }

                // retire
                $motivo = 'El código retiró este escenario del catálogo ('.StudioLabCatalog::VERSION.').';
                $this->catalogWrite($fila, [
                    'status' => StudioLabScenario::STATUS_ARCHIVED,
                    'archived_at' => now(),
                    'archive_reason' => $motivo,
                    'revision' => (int) $fila->revision + 1,
                    'updated_by_admin_id' => null,
                    'updated_by_name' => null,
                ]);
                $this->events->record(null, 'scenario.catalog_retired', StudioLabEvent::SUBJECT_SCENARIO, $fila->uuid,
                    "Archivó el escenario {$codigo}: el código lo retiró del catálogo", [
                        'fromStatus' => StudioLabScenario::STATUS_ACTIVE,
                        'toStatus' => StudioLabScenario::STATUS_ARCHIVED,
                        'diff' => ['reason' => $motivo, 'fromRevision' => (int) $fila->revision, 'toRevision' => (int) $fila->revision + 1],
                    ]);
            }

            $this->events->record(null, 'system.lab_catalog_imported', StudioLabEvent::SUBJECT_SYSTEM, null,
                'Catálogo del laboratorio '.StudioLabCatalog::VERSION.': '.count($plan['created']).' nuevos, '.count($plan['updated']).' sincronizados, '
                .count($plan['unchanged']).' sin cambios, '.count($plan['retired']).' retirados', [
                    'diff' => $plan + ['catalogVersion' => StudioLabCatalog::VERSION],
                ]);

            return $plan;
        });
    }

    /** Cuántos escenarios del catálogo hay importados y activos (`GET lab/status`). */
    public function catalogImported(): int
    {
        return StudioLabScenario::query()
            ->where('source', StudioLabScenario::SOURCE_CATALOG)
            ->where('status', StudioLabScenario::STATUS_ACTIVE)
            ->count();
    }

    /**
     * Lo que un caso congela del escenario (`scenario_snapshot`) y lo que usan
     * los prompts: `{category, title, description, turns, facts, criteria}`.
     *
     * @return array{category:string,title:string,description:?string,turns:list<array<string,mixed>>,facts:array<string,mixed>,criteria:list<array<string,mixed>>}
     */
    public static function contentOf(StudioLabScenario $s): array
    {
        $c = is_array($s->content) ? $s->content : [];

        return [
            'category' => (string) $s->category,
            'title' => (string) $s->title,
            'description' => $s->description,
            'turns' => array_values(is_array($c['turns'] ?? null) ? $c['turns'] : []),
            'facts' => is_array($c['facts'] ?? null) ? $c['facts'] : StudioLabCatalog::DEFAULT_FACTS,
            'criteria' => array_values(is_array($c['criteria'] ?? null) ? $c['criteria'] : []),
        ];
    }

    /**
     * ScenarioDto (contrato §A2).
     *
     * @return array<string, mixed>
     */
    public static function present(StudioLabScenario $s): array
    {
        $c = self::contentOf($s);

        return [
            'uuid' => $s->uuid,
            'code' => $s->code,
            'source' => $s->source,
            'status' => $s->status,
            'revision' => (int) $s->revision,
            'category' => $c['category'],
            'title' => $c['title'],
            'description' => $c['description'],
            'turns' => $c['turns'],
            'facts' => [
                'paymentState' => (string) ($c['facts']['paymentState'] ?? StudioLabCatalog::DEFAULT_FACTS['paymentState']),
                'handoffRequested' => ($c['facts']['handoffRequested'] ?? false) === true,
            ],
            'criteria' => array_map(static fn (array $k): array => [
                'id' => (string) $k['id'],
                'kind' => (string) $k['kind'],
                'text' => (string) $k['text'],
                'rules' => array_values($k['rules'] ?? []),
            ], $c['criteria']),
            'contentHash' => $s->content_hash,
            'createdBy' => $s->created_by_name !== null ? ['name' => $s->created_by_name, 'role' => null] : null,
            'updatedBy' => $s->updated_by_name !== null ? ['name' => $s->updated_by_name, 'role' => null] : null,
            'createdAt' => $s->created_at?->toIso8601String(),
            'updatedAt' => $s->updated_at?->toIso8601String(),
            'archivedAt' => $s->archived_at?->toIso8601String(),
            'archiveReason' => $s->archive_reason,
        ];
    }

    /**
     * Un escenario en su forma canónica: hechos con sus dos claves exactas
     * (por defecto `none` y `false`) y los `id` de los criterios por posición.
     * La forma de cada campo ya la validó el controlador.
     *
     * @param  array<string, mixed>  $data
     * @return array{category:string,title:string,description:?string,turns:list<array{role:string,text:string}>,facts:array{paymentState:string,handoffRequested:bool},criteria:list<array{id:string,kind:string,text:string,rules:list<string>}>}
     */
    public static function normalize(array $data): array
    {
        $hechos = is_array($data['facts'] ?? null) ? $data['facts'] : [];
        $criterios = [];
        foreach (array_values(is_array($data['criteria'] ?? null) ? $data['criteria'] : []) as $i => $c) {
            $criterios[] = [
                'id' => 'c'.($i + 1),
                'kind' => (string) ($c['kind'] ?? ''),
                'text' => (string) ($c['text'] ?? ''),
                'rules' => array_values(array_map('strval', is_array($c['rules'] ?? null) ? $c['rules'] : [])),
            ];
        }
        $descripcion = $data['description'] ?? null;

        return [
            'category' => (string) ($data['category'] ?? ''),
            'title' => (string) ($data['title'] ?? ''),
            'description' => is_string($descripcion) && $descripcion !== '' ? $descripcion : null,
            'turns' => array_map(static fn (array $t): array => ['role' => (string) ($t['role'] ?? ''), 'text' => (string) ($t['text'] ?? '')],
                array_values(array_filter(is_array($data['turns'] ?? null) ? $data['turns'] : [], 'is_array'))),
            'facts' => [
                'paymentState' => (string) ($hechos['paymentState'] ?? StudioLabCatalog::DEFAULT_FACTS['paymentState']),
                'handoffRequested' => ($hechos['handoffRequested'] ?? false) === true,
            ],
            'criteria' => $criterios,
        ];
    }

    /**
     * Lo que no se ve en la forma: el último turno es de la persona, ningún
     * texto lleva datos personales ni invisibles, las reglas citadas existen y
     * el contenido cabe en 16 KB. Un 422 con TODAS las claves a la vez.
     *
     * @param  array<string, mixed>  $c  la forma de {@see normalize()}
     *
     * @throws ValidationException
     */
    public static function validate(array $c): array
    {
        $errores = [];
        $personal = StudioLabPersonalData::MESSAGE.'.';

        if (! StudioLabPersonalData::passes($c['title'])) {
            $errores['title'][] = $personal;
        } elseif (preg_match('/[\r\n]/', $c['title']) === 1) {
            $errores['title'][] = 'El título va en una sola línea.';
        }
        if ($c['description'] !== null && ! StudioLabPersonalData::passes($c['description'])) {
            $errores['description'][] = $personal;
        }
        foreach ($c['turns'] as $i => $t) {
            if (! StudioLabPersonalData::passes($t['text'])) {
                $errores["turns.{$i}.text"][] = $personal;
            }
        }
        $ultimo = $c['turns'] === [] ? null : $c['turns'][array_key_last($c['turns'])];
        if ($ultimo === null || $ultimo['role'] !== 'prospect') {
            $errores['turns'][] = 'El último turno es de la persona (prospect): es a quien responde el actor.';
        }

        $existen = self::citableRules($c['criteria']);
        foreach ($c['criteria'] as $i => $k) {
            if (! StudioLabPersonalData::passes($k['text'])) {
                $errores["criteria.{$i}.text"][] = $personal;
            }
            // Repetidas dentro de un criterio (entre criterios distintos, sí se repiten).
            if (count($k['rules']) !== count(array_unique($k['rules']))) {
                $errores["criteria.{$i}.rules"][] = 'Hay reglas repetidas en este criterio.';
            }
            foreach ($k['rules'] as $j => $codigo) {
                if (! isset($existen[$codigo])) {
                    $errores["criteria.{$i}.rules.{$j}"][] = 'Esa regla no existe o está archivada.';
                }
            }
        }

        $bytes = strlen((string) json_encode(['turns' => $c['turns'], 'facts' => $c['facts'], 'criteria' => $c['criteria']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($bytes > StudioLabCatalog::MAX_BYTES) {
            $errores['content'][] = 'El escenario ocupa más de 16 KB: acorta la conversación o los criterios.';
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $c;
    }

    /**
     * Qué reglas citadas existen: una R-xx del código
     * ({@see StudioCatalog::legacyRules()}) o una S-NNN del Studio sin
     * archivar. Una sola consulta.
     *
     * @param  list<array{rules:list<string>}>  $criteria
     * @return array<string, true>
     */
    private static function citableRules(array $criteria): array
    {
        $citadas = [];
        foreach ($criteria as $k) {
            foreach ($k['rules'] as $codigo) {
                $citadas[$codigo] = true;
            }
        }
        if ($citadas === []) {
            return [];
        }

        $existen = array_fill_keys(array_column(StudioCatalog::legacyRules(), 'code'), true);
        $delStudio = array_values(array_filter(array_keys($citadas), static fn (string $c): bool => str_starts_with($c, 'S-')));
        if ($delStudio !== []) {
            foreach (StudioRule::query()->whereIn('code', $delStudio)->where('status', '<>', StudioRule::STATUS_ARCHIVED)->pluck('code') as $codigo) {
                $existen[(string) $codigo] = true;
            }
        }

        return array_intersect_key($existen, $citadas);
    }

    /**
     * Las columnas de un escenario normalizado. Con `$encoded`, `content` va en
     * JSON: el UPDATE del constructor no aplica los casts del modelo.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private static function columns(array $c, bool $encoded = false): array
    {
        $contenido = ['turns' => $c['turns'], 'facts' => $c['facts'], 'criteria' => $c['criteria']];

        return [
            'category' => $c['category'],
            'title' => $c['title'],
            'description' => $c['description'],
            'content' => $encoded ? json_encode($contenido, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $contenido,
        ];
    }

    /**
     * Lo que hace falta para editar o archivar: que sea del personal, que no
     * esté archivado y que nadie lo haya cambiado desde que se leyó.
     */
    private function assertEditable(StudioLabScenario $escenario, int $baseRevision): void
    {
        if ($escenario->isCatalog()) {
            throw UltronStudioException::catalogScenarioReadOnly();
        }
        if ($escenario->isArchived()) {
            throw UltronStudioException::scenarioArchived();
        }
        if ((int) $escenario->revision !== $baseRevision) {
            throw UltronStudioException::scenarioConflict(self::present($escenario));
        }
    }

    /**
     * `UPDATE … WHERE id = ? AND revision = ?` solo sobre un escenario del
     * personal sin archivar. Cero filas: alguien se adelantó.
     *
     * @param  array<string, mixed>  $values
     */
    private function conditionalWrite(StudioLabScenario $escenario, int $baseRevision, array $values): void
    {
        $filas = StudioLabScenario::query()
            ->whereKey($escenario->id)
            ->where('revision', $baseRevision)
            ->where('source', StudioLabScenario::SOURCE_STUDIO)
            ->where('status', StudioLabScenario::STATUS_ACTIVE)
            ->update($values);
        if ($filas === 1) {
            return;
        }

        $actual = $escenario->fresh();
        if ($actual !== null && $actual->isArchived()) {
            throw UltronStudioException::scenarioArchived();
        }

        throw UltronStudioException::scenarioConflict($actual !== null ? self::present($actual) : []);
    }

    /**
     * La escritura de la importación sobre una fila del catálogo, condicionada
     * a la revisión que se leyó dentro del cerrojo.
     *
     * @param  array<string, mixed>  $values
     */
    private function catalogWrite(StudioLabScenario $fila, array $values): void
    {
        if (isset($values['content']) && is_array($values['content'])) {
            $values['content'] = json_encode($values['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $filas = StudioLabScenario::query()
            ->whereKey($fila->id)
            ->where('revision', (int) $fila->revision)
            ->where('source', StudioLabScenario::SOURCE_CATALOG)
            ->update($values);
        if ($filas !== 1) {
            throw new UltronStudioException('Un escenario del catálogo cambió mientras se importaba. No se importó nada: vuelve a ejecutar la importación.', 'lab_import_conflict');
        }
    }

    /**
     * Qué haría la importación con la tabla tal como está. Solo lee.
     *
     * @return array{plan:array{created:list<string>,updated:list<string>,unchanged:list<string>,retired:list<string>},actions:list<array{0:string,1:string,2:array<string,mixed>,3:StudioLabScenario|null,4:list<array<string,mixed>>}>}
     *
     * @throws UltronStudioException `lab_code_taken` si un escenario del personal ocupa un código del catálogo
     */
    private static function catalogPlan(): array
    {
        $plan = ['created' => [], 'updated' => [], 'unchanged' => [], 'retired' => []];
        $acciones = [];

        /** @var Collection<string, StudioLabScenario> $existentes */
        $existentes = StudioLabScenario::query()->whereIn('code', StudioLabCatalog::codes())->get()->keyBy('code');
        foreach (StudioLabCatalog::scenarios() as $s) {
            $esperado = self::catalogRow($s);
            $fila = $existentes->get($s['code']);

            if ($fila === null) {
                $plan['created'][] = $s['code'];
                $acciones[] = ['create', $s['code'], $esperado, null, []];

                continue;
            }
            if ($fila->source !== StudioLabScenario::SOURCE_CATALOG) {
                throw new UltronStudioException(
                    "El escenario {$s['code']} existe y no es del catálogo (source={$fila->source}). No se importa nada: un escenario del personal no se pisa.",
                    'lab_code_taken',
                );
            }

            $cambios = self::catalogChanges($esperado, $fila);
            if ($cambios === []) {
                $plan['unchanged'][] = $s['code'];
            } else {
                $plan['updated'][] = $s['code'];
                $acciones[] = ['sync', $s['code'], $esperado, $fila, $cambios];
            }
        }

        $retirados = StudioLabScenario::query()
            ->where('source', StudioLabScenario::SOURCE_CATALOG)
            ->where('status', StudioLabScenario::STATUS_ACTIVE)
            ->whereNotIn('code', StudioLabCatalog::codes())
            ->orderBy('code')
            ->get();
        foreach ($retirados as $fila) {
            $plan['retired'][] = $fila->code;
            $acciones[] = ['retire', $fila->code, [], $fila, []];
        }

        return ['plan' => $plan, 'actions' => $acciones];
    }

    /**
     * La fila que corresponde a un escenario del catálogo (sin código, fuente
     * ni revisión): activo, con su contenido y su huella.
     *
     * @param  array<string, mixed>  $s  un elemento de {@see StudioLabCatalog::scenarios()}
     * @return array<string, mixed>
     */
    private static function catalogRow(array $s): array
    {
        return [
            'status' => StudioLabScenario::STATUS_ACTIVE,
            'category' => $s['category'],
            'title' => $s['title'],
            'description' => $s['description'],
            'content' => ['turns' => $s['turns'], 'facts' => $s['facts'], 'criteria' => $s['criteria']],
            'content_hash' => StudioLabCatalog::scenarioHash($s),
            'archived_at' => null,
            'archive_reason' => null,
        ];
    }

    /**
     * En qué difiere una fila de lo que el código dice que tiene que ser.
     *
     * @param  array<string, mixed>  $esperado
     * @return list<array{field:string,from:mixed,to:mixed}>
     */
    private static function catalogChanges(array $esperado, StudioLabScenario $fila): array
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
     * EL cerrojo de las escrituras de escenarios: en PostgreSQL, un cerrojo de
     * transacción serializa a quienes crean o importan escenarios, para que dos
     * altas no calculen el mismo E-NNN ni una importación corra a la vez que un
     * alta. Se toma ANTES de leer nada, en READ COMMITTED (la lección B-2 de G:
     * en REPEATABLE READ la instantánea la toma la sentencia del cerrojo, antes
     * de esperar). SQLite escribe en serie por sí mismo. El UNIQUE de `code` y
     * el reintento de {@see create()} siguen como red de seguridad.
     */
    private function serializeScenarioWrites(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('ultron_studio_lab:scenarios')]);
        }
    }

    /** El siguiente E-NNN. */
    private function nextStudioCode(): string
    {
        $mayor = 0;
        foreach (StudioLabScenario::query()->where('code', 'like', 'E-%')->pluck('code') as $codigo) {
            if (preg_match('/^E-(\d+)$/', (string) $codigo, $m) === 1) {
                $mayor = max($mayor, (int) $m[1]);
            }
        }

        return sprintf('E-%03d', $mayor + 1);
    }

    /**
     * La misma petición repetida encuentra el escenario de la primera. La
     * misma llave con otro contenido, o de otra persona, es 409.
     */
    private function replay(StudioAccess $access, string $clientRequestId, string $hash): ?StudioLabScenario
    {
        $previo = StudioLabScenario::query()->where('client_request_id', strtolower($clientRequestId))->first();
        if ($previo === null) {
            return null;
        }

        $mismaPersona = (int) $previo->created_by_admin_id === $access->id();
        // Si ya se editó, su huella cambió: basta que sea de la misma persona.
        $mismoContenido = (int) $previo->revision > 1 || hash_equals($previo->content_hash, $hash);
        if (! $mismaPersona || ! $mismoContenido) {
            throw UltronStudioException::clientRequestReused();
        }

        return $previo;
    }
}
