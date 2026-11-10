<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioStrategy;
use App\Models\UltronStudio\StudioStrategyReview;
use App\Models\UltronStudio\StudioStrategySimulation;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\Outreach\Core\ConditionEvaluator;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\StrategyContent;
use App\Services\Outreach\Facts\CatalogDomains;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

/**
 * LAS VERSIONES INMUTABLES DE UNA ESTRATEGIA y su REVISIÓN (contrato H9,
 * D9-11.0, D9-11.1, D9-11.2, D9-12 «Puerta», §7 #7, #8, #11, #12).
 *
 * CONGELAR ({@see freeze()}) copia el borrador VALIDADO en su JSON canónico
 * (`content_json`, bytes exactos, y su huella), con la versión del catálogo y
 * del evaluador y la referencia del Studio; queda `VALIDATED`, o
 * `REVIEW_REQUIRED` si tiene avisos. Con un error o un bloqueante, 422 con
 * TODAS las claves. Exige la persona verificada de la autora: sin ella no se
 * pueden comparar personas al aprobar (P9-4).
 *
 * APROBAR o RECHAZAR ({@see approve()}, {@see reject()}) es de OTRA PERSONA
 * (cuenta e identidad distintas de la autora; dos cuentas de la misma persona
 * no se aprueban entre sí; una cuenta sin vínculo verificado no aprueba). La
 * aprobación:
 *  - relee la versión de sus bytes (`StrategyContent::fromStored`: integridad
 *    y catálogo vigente, D9-3) y la vuelve a validar AHORA (plantillas y
 *    referencia del Studio): con un bloqueante, 409 `revalidation_blocked`;
 *  - reconoce EXACTAMENTE los avisos de ahora (409 `warnings_not_acknowledged`);
 *  - exige una simulación SIN BLOQUEANTES sobre la MISMA huella (D9-12; la
 *    puerta `waived` solo la pone el propietario) — 409 `simulation_required`.
 * Tres capas: este servicio, el CHECK de la base y el libro.
 */
final class StudioStrategyVersions
{
    public const NOTE_MAX = 500;

    public const NOTE_MIN = 5;

    public const MAX_WARNINGS = 100;

    public const DIFF_MAX = 100;

    public function __construct(
        private readonly StudioAudit $audit,
        private readonly StudioPersonBindings $persons,
    ) {}

    // ── Congelar (POST strategies/{uuid}/versions) ──────────────────────────

    /**
     * @param  array<string,mixed>|null  $context  el de {@see StudioStrategyValidator::context()}; solo las pruebas lo pasan
     * @return array{version:StudioStrategyVersion,replayed:bool}
     */
    public function freeze(StudioAccess $access, Request $request, string $strategyUuid, string $clientRequestId, ?array $context = null): array
    {
        $access->require(StudioStrategyConfig::EDIT);
        $s = StudioStrategies::findByUuid($strategyUuid);
        $llave = strtolower($clientRequestId);
        $previa = StudioStrategyVersion::query()->where('client_request_id', $llave)->first();
        if ($previa !== null) {
            if ((int) $previa->created_by_admin_id !== $access->id() || (int) $previa->strategy_id !== (int) $s->id) {
                throw UltronStudioException::clientRequestReused();
            }

            return ['version' => $previa, 'replayed' => true];
        }
        StudioStrategies::assertNotArchived($s);
        $persona = $this->persons->requirePerson($access, $request, 'strategy_version.create', StudioStrategyConfig::SUBJECT_STRATEGY, $s->uuid);
        $borrador = StudioStrategies::draftDto($s)['content'];
        if (! is_array($borrador)) {
            throw new UltronStudioException('La estrategia no tiene borrador guardado: guárdalo antes de crear una versión.', 'draft_not_found', 404);
        }
        $v = StudioStrategyValidator::validate($borrador, $context ?? StudioStrategyValidator::context(), (string) $s->purpose);
        if ($v['errors'] !== [] || $v['blocking'] !== [] || $v['canonical'] === null) {
            throw StudioStrategyValidator::invalid($v);
        }
        $ultima = StudioStrategyVersion::query()->where('strategy_id', $s->id)->orderByDesc('number')->first(['id', 'number', 'status', 'content_hash']);
        if ($ultima !== null && hash_equals((string) $ultima->content_hash, (string) $v['contentHash']) && ! in_array($ultima->status, ['REJECTED', 'ROLLED_BACK'], true)) {
            throw new UltronStudioException("El borrador es igual a la versión {$ultima->number}: no hay nada nuevo que congelar.", 'version_unchanged', 409, ['version' => (int) $ultima->number]);
        }
        $estado = $v['warnings'] === [] ? StudioVersionStatus::Validated : StudioVersionStatus::ReviewRequired;
        $ref = $v['studioRef'];

        try {
            $fila = DB::transaction(function () use ($access, $request, $s, $llave, $v, $estado, $ref, $persona): StudioStrategyVersion {
                StudioStrategy::query()->whereKey($s->id)->lockForUpdate()->first(['id']);
                $numero = (int) StudioStrategyVersion::query()->where('strategy_id', $s->id)->max('number') + 1;
                $yo = $access->snapshot();
                $ahora = now();
                $version = StudioStrategyVersion::query()->forceCreate([
                    'uuid' => strtolower((string) Str::uuid()),
                    'client_request_id' => $llave,
                    'strategy_id' => (int) $s->id,
                    'number' => $numero,
                    'content_json' => $v['json'],
                    'content_hash' => $v['contentHash'],
                    'catalog_version' => OutreachCatalog::VERSION,
                    'evaluator_version' => ConditionEvaluator::VERSION,
                    'studio_ref_kind' => $ref['kind'],
                    'studio_version_uuid' => $ref['kind'] === StudioStrategyVersion::STUDIO_REF_VERSION ? strtolower($ref['uuid']) : null,
                    'studio_content_hash' => $ref['kind'] === StudioStrategyVersion::STUDIO_REF_VERSION ? $ref['contentHash'] : $ref['catalogHash'],
                    'status' => $estado->value,
                    'validation_json' => StudioStrategyValidator::record($v),
                    'created_by_admin_id' => $yo['id'],
                    'created_by_person_id' => $persona['identityId'],
                    'created_by_name' => $yo['name'],
                    'created_by_role' => $yo['role'],
                    'status_changed_at' => $ahora,
                ]);
                $this->audit->record($request, $access->actor, 'strategy_version.created', StudioStrategyConfig::SUBJECT_STRATEGY_VERSION, $version->uuid,
                    "Congeló la versión {$numero} de la estrategia «{$s->name}»", [
                        'toStatus' => $estado->value,
                        'contentHash' => $v['contentHash'],
                        'diff' => ['strategy' => $s->uuid, 'number' => $numero, 'warnings' => count($v['warnings']), 'studioRef' => $ref['kind']],
                    ]);

                return $version;
            });
        } catch (UniqueConstraintViolationException) {
            throw new UltronStudioException('Otra persona congeló a la vez una versión de esta estrategia: recarga.', 'version_moved', 409);
        }

        return ['version' => $fila, 'replayed' => false];
    }

    // ── Revisar (POST strategies/versions/{uuid}/approve | reject) ──────────

    /**
     * @param  list<string>  $acknowledgedWarnings
     * @param  array<string,mixed>|null  $context  solo las pruebas lo pasan
     */
    public function approve(StudioAccess $access, Request $request, string $uuid, array $acknowledgedWarnings, ?string $note, ?array $context = null): StudioStrategyVersion
    {
        $access->require(StudioStrategyConfig::APPROVE);
        $version = self::findByUuid($uuid);
        $persona = $this->assertReviewer($access, $request, $version, 'approve');
        $this->assertReviewable($version, 'aprobar');
        $releida = self::reread($version);
        $v = StudioStrategyValidator::validate($releida, $context ?? StudioStrategyValidator::context(), (string) $version->strategy?->purpose);
        if ($v['errors'] !== [] || $v['blocking'] !== [] || ! hash_equals((string) $v['contentHash'], (string) $version->content_hash)) {
            throw UltronStudioException::revalidationBlocked(array_values([...$v['errors'], ...$v['blocking']]), 'La versión ya no pasa la validación (plantillas, referencia del Studio o catálogo): no se puede aprobar. Crea una versión nueva.');
        }
        $avisos = StudioStrategyValidator::warningIds($v);
        $reconocidos = array_values(array_unique($acknowledgedWarnings));
        sort($reconocidos, SORT_STRING);
        if ($reconocidos !== $avisos) {
            throw UltronStudioException::warningsNotAcknowledged($avisos);
        }
        $simulacion = self::passingSimulation($version);
        if ($simulacion === null && StudioStrategyConfig::simulationGate() === StudioStrategyConfig::GATE_REQUIRED) {
            throw new UltronStudioException('Para aprobar hace falta una simulación SIN BLOQUEANTES de esta misma versión (la misma huella).', 'simulation_required', 409, ['contentHash' => (string) $version->content_hash]);
        }

        return DB::transaction(function () use ($access, $request, $version, $persona, $reconocidos, $note, $simulacion): StudioStrategyVersion {
            $ahora = now();
            $yo = $access->snapshot();
            StudioStrategyReview::query()->forceCreate([
                'uuid' => strtolower((string) Str::uuid()),
                'version_id' => (int) $version->id,
                'decision' => StudioStrategyReview::DECISION_APPROVED,
                'content_hash' => (string) $version->content_hash,
                'acknowledged_warnings' => $reconocidos,
                'note' => $note,
                'reviewer_admin_id' => $yo['id'],
                'reviewer_person_id' => $persona['identityId'],
                'reviewer_name' => $yo['name'],
                'reviewer_role' => $yo['role'],
                'created_at' => $ahora,
            ]);
            $hechas = StudioStrategyVersion::query()->whereKey($version->id)->whereIn('status', self::reviewableValues())->update([
                'status' => StudioVersionStatus::Approved->value,
                'status_changed_at' => $ahora,
                'approved_by_admin_id' => $yo['id'],
                'approved_by_name' => $yo['name'],
                'approved_by_role' => $yo['role'],
                'approved_by_person_id' => $persona['identityId'],
                'approved_at' => $ahora,
                'simulation_uuid' => $simulacion?->uuid,
                'simulation_hash' => $simulacion?->result_hash,
                'updated_at' => $ahora,
            ]);
            if ($hechas !== 1) {
                throw new UltronStudioException('La versión cambió mientras la revisabas: recárgala.', 'version_moved', 409);
            }
            $this->audit->record($request, $access->actor, 'strategy_version.approved', StudioStrategyConfig::SUBJECT_STRATEGY_VERSION, $version->uuid,
                "Aprobó la versión {$version->number} de una estrategia", [
                    'fromStatus' => $version->status, 'toStatus' => StudioVersionStatus::Approved->value, 'contentHash' => $version->content_hash,
                    'diff' => ['acknowledged' => count($reconocidos), 'simulation' => $simulacion?->uuid, 'simulationGate' => StudioStrategyConfig::simulationGate()],
                ]);

            return $version->refresh();
        });
    }

    public function reject(StudioAccess $access, Request $request, string $uuid, string $note): StudioStrategyVersion
    {
        $access->require(StudioStrategyConfig::APPROVE);
        $version = self::findByUuid($uuid);
        $persona = $this->assertReviewer($access, $request, $version, 'reject');
        $this->assertReviewable($version, 'rechazar');

        return DB::transaction(function () use ($access, $request, $version, $persona, $note): StudioStrategyVersion {
            $ahora = now();
            $yo = $access->snapshot();
            StudioStrategyReview::query()->forceCreate([
                'uuid' => strtolower((string) Str::uuid()),
                'version_id' => (int) $version->id,
                'decision' => StudioStrategyReview::DECISION_REJECTED,
                'content_hash' => (string) $version->content_hash,
                'acknowledged_warnings' => [],
                'note' => $note,
                'reviewer_admin_id' => $yo['id'],
                'reviewer_person_id' => $persona['identityId'],
                'reviewer_name' => $yo['name'],
                'reviewer_role' => $yo['role'],
                'created_at' => $ahora,
            ]);
            $hechas = StudioStrategyVersion::query()->whereKey($version->id)->whereIn('status', self::reviewableValues())->update([
                'status' => StudioVersionStatus::Rejected->value, 'status_changed_at' => $ahora, 'updated_at' => $ahora,
            ]);
            if ($hechas !== 1) {
                throw new UltronStudioException('La versión cambió mientras la revisabas: recárgala.', 'version_moved', 409);
            }
            $this->audit->record($request, $access->actor, 'strategy_version.rejected', StudioStrategyConfig::SUBJECT_STRATEGY_VERSION, $version->uuid,
                "Rechazó la versión {$version->number} de una estrategia", ['fromStatus' => $version->status, 'toStatus' => StudioVersionStatus::Rejected->value,
                    'contentHash' => $version->content_hash, 'diff' => ['note' => $note]]);

            return $version->refresh();
        });
    }

    // ── Lecturas ────────────────────────────────────────────────────────────

    public static function findByUuid(string $uuid): StudioStrategyVersion
    {
        $v = StudioStrategyVersion::query()->with('strategy')->where('uuid', strtolower($uuid))->first();
        if ($v === null) {
            throw new UltronStudioException('La versión de la estrategia no existe.', 'version_not_found', 404);
        }

        return $v;
    }

    /**
     * El contenido canónico de una versión GUARDADA (sus bytes exactos): si no
     * cuadra con su huella, no es canónico o el catálogo vigente ya no lo
     * admite, 409 (denegada, no reinterpretada; D9-3).
     *
     * @return array<string,mixed>
     */
    public static function reread(StudioStrategyVersion $version): array
    {
        $r = StrategyContent::fromStored((string) $version->content_json, (string) $version->content_hash, (new CatalogDomains)->read());
        if ($r['canonical'] === null) {
            if ($r['reason'] === StrategyContent::CATALOG_INCOMPATIBLE) {
                throw new UltronStudioException('El catálogo vigente ya no admite esta versión (catalog_incompatible): no se reinterpreta. Crea una versión nueva.', StrategyContent::CATALOG_INCOMPATIBLE, 409);
            }

            throw UltronStudioException::integrityMismatch('La huella de la versión no coincide con su contenido (o no es canónico): no se usa. Avisa a quien administra el sistema.');
        }

        return $r['canonical'];
    }

    /** @return array<string, mixed> */
    public static function summaryDto(StudioStrategyVersion $v): array
    {
        return [
            'uuid' => (string) $v->uuid,
            'number' => (int) $v->number,
            'status' => (string) $v->status,
            'statusLabel' => StudioVersionStatus::tryFrom((string) $v->status)?->label(),
            'contentHash' => (string) $v->content_hash,
            'shortHash' => substr((string) $v->content_hash, 0, 8),
            'studioRefKind' => (string) $v->studio_ref_kind,
            'createdBy' => ['name' => (string) $v->created_by_name, 'role' => $v->created_by_role],
            'createdAt' => $v->created_at?->toIso8601String(),
            'statusChangedAt' => $v->status_changed_at?->toIso8601String(),
        ];
    }

    /**
     * `GET strategies/versions/{uuid}`: el contenido, las diferencias con la
     * anterior, la validación guardada, las últimas simulaciones, la reseña y
     * lo que puede hacer quien mira (y por qué no).
     *
     * @return array<string, mixed>
     */
    public static function detailDto(StudioStrategyVersion $v, StudioAccess $access): array
    {
        $contenido = self::decode((string) $v->content_json);
        $integra = StrategyContent::fromStored((string) $v->content_json, (string) $v->content_hash, (new CatalogDomains)->read());
        $anterior = StudioStrategyVersion::query()->where('strategy_id', $v->strategy_id)->where('number', '<', $v->number)->orderByDesc('number')->first(['id', 'uuid', 'number', 'content_json']);
        $simulaciones = StudioStrategySimulation::query()->where('content_hash', $v->content_hash)->where('strategy_id', $v->strategy_id)->orderByDesc('id')->limit(10)->get();
        $v->loadMissing('review');
        $persona = StudioPersonBindings::personOf($access->id());

        return self::summaryDto($v) + [
            'strategy' => $v->strategy === null ? null : StudioStrategies::summaryDto($v->strategy),
            'content' => $contenido,
            'integrity' => ['ok' => $integra['canonical'] !== null, 'reason' => $integra['reason']],
            'catalogVersion' => (string) $v->catalog_version,
            'evaluatorVersion' => (string) $v->evaluator_version,
            'studioRef' => ['kind' => (string) $v->studio_ref_kind, 'versionUuid' => $v->studio_version_uuid, 'contentHash' => (string) $v->studio_content_hash],
            'validation' => is_array($v->validation_json) ? $v->validation_json : null,
            'diff' => $anterior === null ? null : ['against' => ['uuid' => (string) $anterior->uuid, 'number' => (int) $anterior->number], 'changes' => self::diff(self::decode((string) $anterior->content_json), $contenido)],
            'simulations' => $simulaciones->map(static fn (StudioStrategySimulation $s): array => StudioStrategySimulations::summaryDto($s))->all(),
            'review' => $v->review === null ? null : [
                'decision' => (string) $v->review->decision,
                'acknowledgedWarnings' => $v->review->acknowledged_warnings ?? [],
                'note' => $v->review->note,
                'reviewer' => ['name' => (string) $v->review->reviewer_name, 'role' => $v->review->reviewer_role],
                'at' => $v->review->created_at?->toIso8601String(),
            ],
            'actions' => self::actions($v, $access, $persona),
        ];
    }

    /**
     * Lo que puede hacer quien mira con esta versión, y si no, por qué. Solo lo
     * que se sabe sin leer nada más: el servidor lo vuelve a comprobar todo al actuar.
     *
     * @param  array{identityId:int}|null  $persona
     * @return array<string, array{allowed:bool,code?:string}>
     */
    public static function actions(StudioStrategyVersion $v, StudioAccess $access, ?array $persona): array
    {
        $estado = StudioVersionStatus::tryFrom((string) $v->status);
        $propia = StudioPersonBindings::samePerson($access->id(), $persona['identityId'] ?? null, (int) $v->created_by_admin_id, (int) $v->created_by_person_id);
        $no = static fn (string $code): array => ['allowed' => false, 'code' => $code];
        $revisar = match (true) {
            ! $access->can(StudioStrategyConfig::APPROVE) => $no('forbidden'),
            $persona === null => $no('person_binding_required'),
            $propia => $no('four_eyes_required'),
            $estado === null || ! $estado->isReviewable() => $no('invalid_transition'),
            default => ['allowed' => true],
        };
        $publicar = match (true) {
            ! $access->can(StudioStrategyConfig::PUBLISH) => $no('forbidden'),
            ! StudioStrategyConfig::publishingEnabled() => $no(StudioStrategyConfig::CODE_PUBLISHING_DISABLED),
            $persona === null => $no('person_binding_required'),
            $propia => $no('four_eyes_required'),
            $estado !== StudioVersionStatus::Approved => $no('invalid_transition'),
            default => ['allowed' => true],
        };
        $promover = match (true) {
            ! $access->can(StudioStrategyConfig::PUBLISH) => $no('forbidden'),
            ! StudioStrategyConfig::publishingEnabled() => $no(StudioStrategyConfig::CODE_PUBLISHING_DISABLED),
            $persona === null => $no('person_binding_required'),
            $propia => $no('four_eyes_required'),
            $estado !== StudioVersionStatus::Canary => $no('invalid_transition'),
            default => ['allowed' => true],
        };

        return ['approve' => $revisar, 'reject' => $revisar, 'publish' => $publicar, 'promote' => $promover];
    }

    /**
     * La simulación sin bloqueantes más reciente DE ESTA VERSIÓN, sobre su
     * misma huella (D9-11.0, D9-12 «Puerta»: la obligatoria es la de la
     * versión, no la del borrador; DB9 lo exige en `ussv_simulation_gate`), o null.
     */
    public static function passingSimulation(StudioStrategyVersion $v): ?StudioStrategySimulation
    {
        return StudioStrategySimulation::query()
            ->where('strategy_id', $v->strategy_id)
            ->where('version_id', $v->id)
            ->where('content_hash', $v->content_hash)
            ->where('blocking_findings', 0)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Las diferencias entre dos contenidos: las rutas que cambian (como mucho
     * {@see DIFF_MAX}), con `added`, `removed` o `changed`. Sin valores: los
     * enseña el contenido.
     *
     * @return list<array{path:string,change:string}>
     */
    public static function diff(mixed $antes, mixed $despues, string $ruta = 'content'): array
    {
        $salida = [];
        self::walkDiff($antes, $despues, $ruta, $salida);

        return array_slice($salida, 0, self::DIFF_MAX);
    }

    /** @param  list<array{path:string,change:string}>  $salida */
    private static function walkDiff(mixed $a, mixed $b, string $ruta, array &$salida): void
    {
        if (count($salida) >= self::DIFF_MAX) {
            return;
        }
        if (is_array($a) && is_array($b) && ($a === [] || ! array_is_list($a)) && ($b === [] || ! array_is_list($b))) {
            $claves = array_unique([...array_map('strval', array_keys($a)), ...array_map('strval', array_keys($b))]);
            sort($claves, SORT_STRING);
            foreach ($claves as $k) {
                if (! array_key_exists($k, $a)) {
                    $salida[] = ['path' => "{$ruta}.{$k}", 'change' => 'added'];
                } elseif (! array_key_exists($k, $b)) {
                    $salida[] = ['path' => "{$ruta}.{$k}", 'change' => 'removed'];
                } else {
                    self::walkDiff($a[$k], $b[$k], "{$ruta}.{$k}", $salida);
                }
            }

            return;
        }
        if ($a !== $b) {
            $salida[] = ['path' => $ruta, 'change' => 'changed'];
        }
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * Cuatro ojos POR PERSONA: la persona verificada de quien revisa, distinta
     * (cuenta e identidad) de la autora. El intento queda en el libro.
     *
     * @return array{bindingId:int,bindingUuid:string,identityId:int,identityUuid:string}
     */
    private function assertReviewer(StudioAccess $access, Request $request, StudioStrategyVersion $v, string $accion): array
    {
        $persona = $this->persons->requirePerson($access, $request, "strategy_version.{$accion}", StudioStrategyConfig::SUBJECT_STRATEGY_VERSION, $v->uuid);
        if (StudioPersonBindings::samePerson($access->id(), $persona['identityId'], (int) $v->created_by_admin_id, (int) $v->created_by_person_id)) {
            $this->audit->recordDenied($request, $access->actor, 'strategy_version.four_eyes_denied', StudioStrategyConfig::SUBJECT_STRATEGY_VERSION, $v->uuid,
                'Intentó revisar una versión propia (la misma cuenta o la misma persona)', ['diff' => ['action' => $accion, 'sameAccount' => $access->id() === (int) $v->created_by_admin_id]]);

            throw UltronStudioException::fourEyesRequired();
        }

        return $persona;
    }

    private function assertReviewable(StudioStrategyVersion $v, string $accion): void
    {
        $estado = StudioVersionStatus::tryFrom((string) $v->status);
        if ($estado === null || ! $estado->isReviewable()) {
            throw UltronStudioException::invalidTransition((string) $v->status, $estado?->label() ?? (string) $v->status, $accion, self::reviewableValues());
        }
    }

    /** @return list<string> */
    private static function reviewableValues(): array
    {
        return array_map(static fn (StudioVersionStatus $s): string => $s->value, StudioVersionStatus::REVIEWABLE);
    }

    /** @return array<string,mixed>|null */
    private static function decode(string $json): ?array
    {
        try {
            $d = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Tragado a propósito: unos bytes ilegibles se enseñan como null; la integridad lo dice aparte.
            return null;
        }

        return is_array($d) ? $d : null;
    }
}
