<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioStrategy;
use App\Models\UltronStudio\StudioStrategyPublication;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\Outreach\Core\StrategyContent;
use App\Services\Outreach\Facts\CatalogDomains;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PUBLICAR, PROMOVER, PAUSAR, REANUDAR Y REVERTIR UNA ESTRATEGIA (contrato H9,
 * D9-8 «Reversión y no retroactividad», D9-11.0, D9-11.2, D9-11.3, §7 #13–#17;
 * T1, T2, #23, #25). Cada acción es UNA entrada firmada de la cadena
 * ({@see StudioStrategyPublications}) y, cuando toca, un cambio de estado de
 * las versiones, en la MISMA transacción, con el cerrojo de la cadena.
 *
 *  - PUBLICAR (`ultron.strategies_publish`): APPROVED → CANARY. Bandera
 *    (`ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED`), ventana de Bogotá,
 *    persona verificada distinta de la autora, la huella que se vio
 *    (`expectedContentHash`), la integridad de los bytes, la INTEGRIDAD de la
 *    referencia del Studio (no su estado: sin retroactividad, D9-11.4) y la
 *    simulación aprobada. Nunca con la estrategia en pausa ni con otra versión
 *    en canario.
 *  - PROMOVER (`ultron.strategies_publish`): CANARY → PUBLISHED; la publicada
 *    anterior → RETIRED. La evidencia del canario medida en el servidor
 *    ({@see StudioStrategyEvidence}) y su `evidenceHash` (lo que se vio).
 *  - PAUSAR (`ultron.strategies_rollback`) y REVERTIR: SIEMPRE, con motivo, sin
 *    bandera ni ventana (el sentido seguro, como H5 §A5.3). Revertir pasa la
 *    versión de la parte a ROLLED_BACK y, en la parte `rest`, devuelve la
 *    anterior RETIRED íntegra a PUBLISHED; si no hay ninguna, la estrategia
 *    queda apagada (`off`). La restaurada vuelve con una entrada NUEVA (otra
 *    `seq`: la ejecución empieza con cursor nuevo).
 *  - REANUDAR (`ultron.strategies_publish`): bandera y ventana, como publicar.
 *
 * NO RETROACTIVO: lo enviado no se toca; la cadena solo crece; la ejecución
 * (B9-run) cancela los trabajos pendientes de lo pausado o revertido en la
 * pasada siguiente (`strategy_moved`).
 *
 * TODA acción la firma una PERSONA verificada (`actedByPerson` nunca es null,
 * L9-23); `approvedByPerson` es quien aprobó la versión, solo en publicar y
 * promover. IDEMPOTENCIA por `clientRequestId` (200 `replayed`; otra acción,
 * otra persona u otro cuerpo con la misma llave, 409 `client_request_reused`).
 */
final class StudioStrategyPublishing
{
    public const REASON_MIN = 5;

    public const REASON_MAX = 500;

    /**
     * @param  Closure(string):string|null  $signer  solo las pruebas lo pasan: en producción, {@see StudioStrategyPublications::signer()}
     * @param  Closure(StudioStrategyVersion):array{dto: array<string,mixed>, record: array<string,mixed>, passes: bool, evidenceHash: string}|null  $evidence
     *                                                                                                                                                          solo las pruebas lo pasan: en producción, {@see StudioStrategyEvidence::measure()}
     */
    public function __construct(
        private readonly StudioAudit $audit,
        private readonly StudioPersonBindings $persons,
        private readonly ?Closure $signer = null,
        private readonly ?Closure $evidence = null,
    ) {}

    // ── Publicar en canario ─────────────────────────────────────────────────

    /** @return array{entry: array<string,mixed>, replayed: bool} */
    public function publish(StudioAccess $access, Request $request, string $versionUuid, string $clientRequestId, string $expectedContentHash, ?string $note): array
    {
        $access->require(StudioStrategyConfig::PUBLISH);
        StudioStrategyConfig::assertPublishingEnabled();
        $version = StudioStrategyVersions::findByUuid($versionUuid);
        if ($previa = $this->replay($access, StudioStrategyPublication::ACTION_PUBLISH, $clientRequestId, (int) $version->strategy_id, (int) $version->id, $note)) {
            return $previa;
        }
        $estrategia = $this->strategyOf($version);
        $persona = $this->persons->requirePerson($access, $request, 'strategy_version.publish', StudioStrategyConfig::SUBJECT_STRATEGY_VERSION, $version->uuid);
        $this->assertNotAuthor($access, $request, $version, $persona, StudioStrategyPublication::ACTION_PUBLISH);
        StudioStrategyConfig::assertWindow(now());
        if ($version->status !== StudioVersionStatus::Approved->value) {
            throw UltronStudioException::invalidTransition((string) $version->status, StudioVersionStatus::from((string) $version->status)->label(), 'publicar', [StudioVersionStatus::Approved->value]);
        }
        self::assertSeen($expectedContentHash, (string) $version->content_hash);
        self::assertIntegrity($version);
        $puerta = self::simulationGate($version);
        $aprobo = self::identityUuid($version->approved_by_person_id);
        $estado = StudioStrategyPublications::stateOf($estrategia);
        self::assertReadable($estado);
        if ($estado['status'] === StudioStrategyPublications::STATUS_PAUSED) {
            throw new UltronStudioException('La estrategia está en pausa: reanúdala o revierte antes de publicar otra versión.', 'strategy_paused', 409);
        }
        if ($estado['canary'] !== null) {
            throw new UltronStudioException('Ya hay una versión de esta estrategia en canario: promuévela o revierte antes de publicar otra.', 'canary_in_progress', 409);
        }

        return $this->write($access, $request, $estrategia, $version, StudioStrategyPublication::ACTION_PUBLISH, $clientRequestId, $persona, $aprobo, [
            'canary' => ['contentHash' => (string) $version->content_hash, 'version' => (string) $version->uuid],
            'rest' => $estado['rest'],
            'status' => StudioStrategyPublications::STATUS_ACTIVE,
        ], $puerta, null, $note, function () use ($version): void {
            self::move($version, StudioVersionStatus::Approved, StudioVersionStatus::Canary);
        }, 'strategy_version.published', "Publicó en canario la versión {$version->number} de la estrategia «{$estrategia->name}»", StudioVersionStatus::Canary->value);
    }

    // ── Promover ────────────────────────────────────────────────────────────

    /** @return array{entry: array<string,mixed>, replayed: bool} */
    public function promote(StudioAccess $access, Request $request, string $versionUuid, string $clientRequestId, string $evidenceHash, ?string $note): array
    {
        $access->require(StudioStrategyConfig::PUBLISH);
        StudioStrategyConfig::assertPublishingEnabled();
        $version = StudioStrategyVersions::findByUuid($versionUuid);
        if ($previa = $this->replay($access, StudioStrategyPublication::ACTION_PROMOTE, $clientRequestId, (int) $version->strategy_id, (int) $version->id, $note)) {
            return $previa;
        }
        $estrategia = $this->strategyOf($version);
        $persona = $this->persons->requirePerson($access, $request, 'strategy_version.promote', StudioStrategyConfig::SUBJECT_STRATEGY_VERSION, $version->uuid);
        $this->assertNotAuthor($access, $request, $version, $persona, StudioStrategyPublication::ACTION_PROMOTE);
        StudioStrategyConfig::assertWindow(now());
        if ($version->status !== StudioVersionStatus::Canary->value) {
            throw UltronStudioException::invalidTransition((string) $version->status, StudioVersionStatus::from((string) $version->status)->label(), 'promover', [StudioVersionStatus::Canary->value]);
        }
        self::assertIntegrity($version);
        $estado = StudioStrategyPublications::stateOf($estrategia);
        self::assertReadable($estado);
        if (($estado['canary']['version'] ?? null) !== (string) $version->uuid || $estado['status'] !== StudioStrategyPublications::STATUS_ACTIVE) {
            throw new UltronStudioException('La cadena no tiene esta versión activa en canario (¿pausada o revertida?): no se promueve.', 'not_active_canary', 409);
        }
        $evidencia = $this->evidence !== null ? ($this->evidence)($version) : StudioStrategyEvidence::measure($version);
        if (! $evidencia['passes']) {
            throw new UltronStudioException('La evidencia del canario todavía no basta para promover.', 'canary_evidence_insufficient', 409, ['evidence' => $evidencia['dto']]);
        }
        if (! hash_equals($evidencia['evidenceHash'], $evidenceHash)) {
            throw new UltronStudioException('La evidencia del canario cambió desde que la viste. Revísala otra vez antes de promover.', 'evidence_changed', 409, ['evidence' => $evidencia['dto']]);
        }
        $aprobo = self::identityUuid($version->approved_by_person_id);

        return $this->write($access, $request, $estrategia, $version, StudioStrategyPublication::ACTION_PROMOTE, $clientRequestId, $persona, $aprobo, [
            'canary' => null,
            'rest' => ['contentHash' => (string) $version->content_hash, 'version' => (string) $version->uuid],
            'status' => StudioStrategyPublications::STATUS_ACTIVE,
        ], StudioStrategyConfig::simulationGate(), $evidencia['record'], $note, function () use ($version): void {
            $anterior = StudioStrategyVersion::query()->where('strategy_id', $version->strategy_id)->where('status', StudioVersionStatus::Published->value)->first(['id', 'status']);
            if ($anterior !== null) {
                // Primero se suelta (los índices únicos parciales se comprueban fila a fila).
                self::move($anterior, StudioVersionStatus::Published, StudioVersionStatus::Retired);
            }
            self::move($version, StudioVersionStatus::Canary, StudioVersionStatus::Published);
        }, 'strategy_version.promoted', "Promovió la versión {$version->number} de la estrategia «{$estrategia->name}» del canario al resto", StudioVersionStatus::Published->value);
    }

    // ── Pausar y reanudar ───────────────────────────────────────────────────

    /** @return array{entry: array<string,mixed>, replayed: bool} */
    public function pause(StudioAccess $access, Request $request, string $strategyUuid, string $clientRequestId, string $reason): array
    {
        $access->require(StudioStrategyConfig::ROLLBACK);
        $estrategia = StudioStrategies::findByUuid($strategyUuid);
        if ($previa = $this->replay($access, StudioStrategyPublication::ACTION_PAUSE, $clientRequestId, (int) $estrategia->id, null, $reason)) {
            return $previa;
        }
        $persona = $this->persons->requirePerson($access, $request, 'strategy.pause', StudioStrategyConfig::SUBJECT_STRATEGY, $estrategia->uuid);
        $estado = StudioStrategyPublications::stateOf($estrategia);
        if ($estado['status'] !== StudioStrategyPublications::STATUS_ACTIVE) {
            throw new UltronStudioException('Solo se pausa una estrategia activa (en canario o publicada).', 'strategy_not_active', 409, ['status' => $estado['status']]);
        }

        return $this->write($access, $request, $estrategia, null, StudioStrategyPublication::ACTION_PAUSE, $clientRequestId, $persona, null,
            ['canary' => $estado['canary'], 'rest' => $estado['rest'], 'status' => StudioStrategyPublications::STATUS_PAUSED], null, null, $reason, null,
            'strategy.paused', "Pausó la estrategia «{$estrategia->name}»", StudioStrategyPublications::STATUS_PAUSED);
    }

    /** @return array{entry: array<string,mixed>, replayed: bool} */
    public function resume(StudioAccess $access, Request $request, string $strategyUuid, string $clientRequestId, ?string $note): array
    {
        $access->require(StudioStrategyConfig::PUBLISH);
        StudioStrategyConfig::assertPublishingEnabled();
        $estrategia = StudioStrategies::findByUuid($strategyUuid);
        if ($previa = $this->replay($access, StudioStrategyPublication::ACTION_RESUME, $clientRequestId, (int) $estrategia->id, null, $note)) {
            return $previa;
        }
        StudioStrategies::assertNotArchived($estrategia);
        $persona = $this->persons->requirePerson($access, $request, 'strategy.resume', StudioStrategyConfig::SUBJECT_STRATEGY, $estrategia->uuid);
        StudioStrategyConfig::assertWindow(now());
        $estado = StudioStrategyPublications::stateOf($estrategia);
        self::assertReadable($estado);
        if ($estado['status'] !== StudioStrategyPublications::STATUS_PAUSED) {
            throw new UltronStudioException('Solo se reanuda una estrategia en pausa.', 'strategy_not_paused', 409, ['status' => $estado['status']]);
        }

        return $this->write($access, $request, $estrategia, null, StudioStrategyPublication::ACTION_RESUME, $clientRequestId, $persona, null,
            ['canary' => $estado['canary'], 'rest' => $estado['rest'], 'status' => StudioStrategyPublications::STATUS_ACTIVE], null, null, $note, null,
            'strategy.resumed', "Reanudó la estrategia «{$estrategia->name}»", StudioStrategyPublications::STATUS_ACTIVE);
    }

    // ── Revertir ────────────────────────────────────────────────────────────

    /**
     * @param  string|null  $part  `canary` o `rest`; sin ella, la canaria si la hay y si no la publicada
     * @return array{entry: array<string,mixed>, replayed: bool}
     */
    public function rollback(StudioAccess $access, Request $request, string $strategyUuid, string $clientRequestId, string $reason, ?string $part): array
    {
        $access->require(StudioStrategyConfig::ROLLBACK);
        $estrategia = StudioStrategies::findByUuid($strategyUuid);
        if ($previa = $this->replay($access, StudioStrategyPublication::ACTION_ROLLBACK, $clientRequestId, (int) $estrategia->id, null, $reason)) {
            return $previa;
        }
        $persona = $this->persons->requirePerson($access, $request, 'strategy.rollback', StudioStrategyConfig::SUBJECT_STRATEGY, $estrategia->uuid);
        $estado = StudioStrategyPublications::stateOf($estrategia);
        $parte = $part ?? ($estado['canary'] !== null ? StudioStrategyPublication::PART_CANARY : StudioStrategyPublication::PART_REST);
        $vigente = $estado[$parte] ?? null;
        $version = $vigente === null ? null : StudioStrategyVersion::query()->where('uuid', $vigente['version'])->where('strategy_id', $estrategia->id)->first();
        $esperado = $parte === StudioStrategyPublication::PART_CANARY ? StudioVersionStatus::Canary : StudioVersionStatus::Published;
        if ($version === null || $version->status !== $esperado->value) {
            throw new UltronStudioException("No hay ninguna versión que revertir en la parte «{$parte}».", 'nothing_to_rollback', 409, ['part' => $parte]);
        }
        $vuelve = $parte === StudioStrategyPublication::PART_REST ? self::restorable($version) : null;
        $nuevo = [
            'canary' => $parte === StudioStrategyPublication::PART_CANARY ? null : $estado['canary'],
            'rest' => $parte === StudioStrategyPublication::PART_REST
                ? ($vuelve === null ? null : ['contentHash' => (string) $vuelve->content_hash, 'version' => (string) $vuelve->uuid])
                : $estado['rest'],
        ];
        $nuevo['status'] = $nuevo['canary'] === null && $nuevo['rest'] === null
            ? StudioStrategyPublications::STATUS_OFF
            : ($estado['status'] === StudioStrategyPublications::STATUS_PAUSED ? StudioStrategyPublications::STATUS_PAUSED : StudioStrategyPublications::STATUS_ACTIVE);

        return $this->write($access, $request, $estrategia, $version, StudioStrategyPublication::ACTION_ROLLBACK, $clientRequestId, $persona, null, $nuevo, null, null, $reason,
            function () use ($version, $esperado, $vuelve): void {
                self::move($version, $esperado, StudioVersionStatus::RolledBack);
                if ($vuelve !== null) {
                    self::move($vuelve, StudioVersionStatus::Retired, StudioVersionStatus::Published);
                }
            }, 'strategy_version.rolled_back',
            "Revirtió la versión {$version->number} ({$parte}) de la estrategia «{$estrategia->name}»".($vuelve !== null ? "; vuelve la versión {$vuelve->number}" : ''),
            $nuevo['status'], ['part' => $parte, 'restores' => $vuelve?->uuid]);
    }

    // ── La escritura (una transacción, con el cerrojo de la cadena) ────────

    /**
     * @param  array{bindingId:int,bindingUuid:string,identityId:int,identityUuid:string}  $persona
     * @param  array{canary: array{contentHash:string,version:string}|null, rest: array{contentHash:string,version:string}|null, status: string}  $estado
     * @param  string|null  $puerta  `required`/`waived` en publicar y promover (columna `simulation_gate`); null en las demás
     * @param  array<string,mixed>|null  $evidencia  solo al promover
     * @param  (callable():void)|null  $cambios  los cambios de estado de las versiones, dentro de la transacción
     * @param  array<string,mixed>  $extra  lo que va al libro además
     * @return array{entry: array<string,mixed>, replayed: bool}
     */
    private function write(StudioAccess $access, Request $request, StudioStrategy $estrategia, ?StudioStrategyVersion $version, string $accion, string $clientRequestId,
        array $persona, ?string $aprobo, array $estado, ?string $puerta, ?array $evidencia, ?string $motivo, ?callable $cambios, string $evento, string $resumen, string $toStatus, array $extra = []): array
    {
        $firma = $this->signer ?? StudioStrategyPublications::signer();
        $cabezaAntes = (int) (StudioStrategyPublications::head()?->seq ?? 0);
        try {
            $fila = DB::transaction(function () use ($access, $request, $estrategia, $version, $accion, $clientRequestId, $persona, $aprobo, $estado, $puerta, $evidencia, $motivo, $cambios, $evento, $resumen, $toStatus, $extra, $firma, $cabezaAntes): StudioStrategyPublication {
                StudioIntegrations::lock(StudioStrategyPublications::LOCK);
                $cabeza = StudioStrategyPublications::head();
                if ((int) ($cabeza?->seq ?? 0) !== $cabezaAntes) {
                    throw UltronStudioException::publicationMoved();
                }
                // La estrategia, bloqueada: archivar y publicar no se cruzan.
                StudioStrategy::query()->whereKey($estrategia->id)->lockForUpdate()->first(['id']);
                if ($cambios !== null) {
                    $cambios();
                }
                $ahora = now();
                $entrada = StudioStrategyPublications::insertEntry($accion, $estrategia, $version?->id, $cabeza, $ahora, $access,
                    $persona['identityUuid'], $persona['identityId'], $aprobo, $clientRequestId, $estado, $puerta, $evidencia, $motivo, $firma);
                $this->audit->record($request, $access->actor, $evento,
                    $version !== null ? StudioStrategyConfig::SUBJECT_STRATEGY_VERSION : StudioStrategyConfig::SUBJECT_STRATEGY,
                    $version !== null ? $version->uuid : $estrategia->uuid, $resumen, [
                        'toStatus' => $toStatus,
                        'contentHash' => $version?->content_hash,
                        'diff' => ['seq' => (int) $entrada->seq, 'entry' => $entrada->uuid, 'strategy' => $estrategia->uuid, 'state' => $estado['status'], 'reason' => $motivo] + $extra,
                    ]);

                return $entrada;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw self::conflict($e);
        }

        return ['entry' => StudioStrategyPublications::entryDto($fila) + ['verified' => StudioStrategyPublications::verifyRow($fila)], 'replayed' => false];
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** Un cambio de estado de una versión, condicionado al de antes (si otra petición lo cambió, 409). */
    private static function move(StudioStrategyVersion $v, StudioVersionStatus $de, StudioVersionStatus $a): void
    {
        $ahora = now();
        $hechas = StudioStrategyVersion::query()->whereKey($v->id)->where('status', $de->value)->update(['status' => $a->value, 'status_changed_at' => $ahora, 'updated_at' => $ahora]);
        if ($hechas !== 1) {
            throw UltronStudioException::publicationMoved();
        }
    }

    /**
     * La versión que vuelve a PUBLISHED al revertir la publicada: la RETIRED
     * más reciente de la estrategia, íntegra (sus bytes con su huella y el
     * catálogo vigente). Si no es íntegra, ninguna (la estrategia se apaga).
     */
    private static function restorable(StudioStrategyVersion $actual): ?StudioStrategyVersion
    {
        $candidata = StudioStrategyVersion::query()->where('strategy_id', $actual->strategy_id)->where('status', StudioVersionStatus::Retired->value)
            ->where('id', '<>', $actual->id)->orderByDesc('number')->first();
        if ($candidata === null) {
            return null;
        }
        $r = StrategyContent::fromStored((string) $candidata->content_json, (string) $candidata->content_hash, (new CatalogDomains)->read());

        return $r['canonical'] === null ? null : $candidata;
    }

    private function strategyOf(StudioStrategyVersion $v): StudioStrategy
    {
        $s = $v->strategy ?? StudioStrategy::query()->find($v->strategy_id);
        if (! $s instanceof StudioStrategy) {
            throw new UltronStudioException('La estrategia de la versión no existe.', 'strategy_not_found', 404);
        }
        StudioStrategies::assertNotArchived($s);

        return $s;
    }

    /**
     * Cuatro ojos al publicar y al promover, por PERSONA: nadie publica ni
     * promueve lo que creó, ni con otra cuenta (P9-4). El intento queda en el libro.
     *
     * @param  array{identityId:int}  $persona
     */
    private function assertNotAuthor(StudioAccess $access, Request $request, StudioStrategyVersion $v, array $persona, string $accion): void
    {
        if (! StudioPersonBindings::samePerson($access->id(), $persona['identityId'], (int) $v->created_by_admin_id, (int) $v->created_by_person_id)) {
            return;
        }
        $this->audit->recordDenied($request, $access->actor, 'strategy_version.four_eyes_denied', StudioStrategyConfig::SUBJECT_STRATEGY_VERSION, $v->uuid,
            'Intentó '.($accion === StudioStrategyPublication::ACTION_PROMOTE ? 'promover' : 'publicar').' una versión propia (la misma cuenta o la misma persona)', ['diff' => ['action' => $accion]]);

        throw UltronStudioException::publicationFourEyesRequired($accion);
    }

    /** Lo que se vio es lo que se publica: si la huella cambió, 409 `content_changed` con las dos. */
    private static function assertSeen(string $esperada, string $actual): void
    {
        if (! hash_equals($actual, strtolower($esperada))) {
            throw new UltronStudioException('La versión no es la que viste: recárgala y revísala antes de publicar.', 'content_changed', 409, ['expected' => $esperada, 'actual' => $actual]);
        }
    }

    /** Los bytes con su huella y el catálogo vigente (D9-3), y la integridad de la referencia del Studio (D9-11.4). */
    private static function assertIntegrity(StudioStrategyVersion $v): void
    {
        $c = StudioStrategyVersions::reread($v);
        $ref = StudioStrategyValidator::studioReference($c['studio'], false);
        if (! $ref['valid']) {
            throw new UltronStudioException('La versión comercial del Studio que referencia esta versión ya no cuadra con su huella: no se publica.', StudioStrategyValidator::STUDIO_REFERENCE_INVALID, 409, ['reason' => $ref['reason']]);
        }
    }

    /**
     * La puerta de la simulación (D9-12): `required` exige la simulación sin
     * bloqueantes con la que se aprobó (o una igual sobre la misma huella);
     * `waived` pasa y queda escrito en la entrada.
     */
    private static function simulationGate(StudioStrategyVersion $v): string
    {
        $modo = StudioStrategyConfig::simulationGate();
        if ($modo === StudioStrategyConfig::GATE_REQUIRED && StudioStrategyVersions::passingSimulation($v) === null) {
            throw new UltronStudioException('Falta una simulación sin bloqueantes de esta misma versión.', 'simulation_required', 409);
        }

        return $modo;
    }

    /** @param  array<string,mixed>  $estado */
    private static function assertReadable(array $estado): void
    {
        if ($estado['verified'] !== null && $estado['verified'] !== 'ok') {
            throw new UltronStudioException('La cadena de esta estrategia no verifica: no se publica nada sobre ella. Avisa a quien administra el sistema.', 'chain_unreadable', 409, ['verified' => $estado['verified']]);
        }
    }

    private static function identityUuid(?int $identityId): string
    {
        $uuid = $identityId === null ? null : DB::table('identities')->where('id', $identityId)->value('uuid');
        if (! is_string($uuid) || $uuid === '') {
            throw new UltronStudioException('No se encuentra la persona que aprobó la versión: no se publica.', 'approver_unknown', 409);
        }

        return strtolower($uuid);
    }

    /**
     * La misma petición repetida devuelve la entrada de la primera vez; la
     * misma llave de otra persona, para otra acción u otro cuerpo, es 409.
     *
     * @return array{entry: array<string,mixed>, replayed: bool}|null
     */
    private function replay(StudioAccess $access, string $accion, string $clientRequestId, int $strategyId, ?int $versionId, ?string $motivo): ?array
    {
        $previa = StudioStrategyPublication::query()->where('client_request_id', strtolower($clientRequestId))->first();
        if ($previa === null) {
            return null;
        }
        if ((int) $previa->actor_admin_id !== $access->id() || $previa->action !== $accion || (int) $previa->strategy_id !== $strategyId
            || ($versionId !== null && (int) $previa->version_id !== $versionId) || $previa->reason !== $motivo) {
            throw UltronStudioException::clientRequestReused();
        }

        return ['entry' => StudioStrategyPublications::entryDto($previa) + ['verified' => StudioStrategyPublications::verifyRow($previa)], 'replayed' => true];
    }

    /** Un UNIQUE paró la inserción: la carrera por la cabeza o por una parte → `publication_moved`. Lo que no se reconoce sube tal cual. */
    private static function conflict(UniqueConstraintViolationException $e): UltronStudioException
    {
        foreach (['ultron_studio_strategy_publications', 'ultron_studio_strategy_versions', 'ussv_one_', 'usspub_'] as $marca) {
            if (str_contains($e->getMessage(), $marca)) {
                return UltronStudioException::publicationMoved();
            }
        }

        throw $e;
    }
}
