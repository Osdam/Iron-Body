<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVersionReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Las versiones del Studio y su máquina de estados (contrato §A2).
 *
 *   — crear desde el borrador ─▶ DRAFT ─ enviar (solo quien la creó) ─▶ VALIDATED (sin avisos)
 *                                                                    └▶ REVIEW_REQUIRED (con avisos)
 *   VALIDATED | REVIEW_REQUIRED ─ aprobar (OTRA persona, reconociendo los avisos) ─▶ APPROVED
 *                               └ rechazar (OTRA persona, con motivo) ─────────────▶ REJECTED
 *
 * No hay transiciones hacia atrás y REJECTED es terminal. Publicar y revertir
 * responden 409 SIEMPRE en esta fase ({@see StudioPublishing}), y el intento
 * queda auditado.
 *
 * EL SERVIDOR NO SE FÍA DEL NAVEGADOR: cada transición revalida contra el
 * catálogo ACTUAL y recalcula la huella. Los avisos los tiene que reconocer al
 * aprobar una segunda persona, y el conjunto reconocido tiene que ser
 * exactamente el de los avisos actuales.
 *
 * CONCURRENCIA. Cada transición es un UPDATE condicionado al estado esperado
 * ({@see StudioVersion::transition()}); la reseña se inserta después en la
 * misma transacción. Sin `FOR UPDATE`, así que no hay interbloqueos: de dos
 * aprobaciones simultáneas, una gana y la otra recibe 409 `invalid_transition`.
 *
 * Aprobar aquí no cambia al agente: el motor no lee versiones del Studio.
 */
final class StudioVersions
{
    public function __construct(
        private readonly StudioAudit $audit,
        private readonly StudioDrafts $drafts,
        private readonly StudioRules $rules,
    ) {}

    /**
     * GET versions.
     *
     * @param  list<string>  $statuses
     */
    public function page(StudioAccess $access, array $statuses, string $scope, int $perPage): LengthAwarePaginator
    {
        $revisables = array_map(static fn (StudioVersionStatus $s): string => $s->value, StudioVersionStatus::REVIEWABLE);

        return StudioVersion::query()
            // La lista no necesita el contenido (puede pesar decenas de KB por fila).
            ->select([
                'id', 'uuid', 'label', 'status', 'content_hash', 'catalog_version', 'created_by_admin_id',
                'created_by_name', 'created_by_role', 'last_validation', 'submitted_at', 'created_at',
            ])
            ->with('review')
            ->when($statuses !== [], fn ($q) => $q->whereIn('status', $statuses))
            ->when($scope === 'mine', fn ($q) => $q->where('created_by_admin_id', $access->id()))
            ->when($scope === 'reviewable', fn ($q) => $q
                ->whereIn('status', $revisables)
                ->where('created_by_admin_id', '<>', $access->id()))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (StudioVersion $v): array => $this->summary($access, $v));
    }

    public function findByUuid(string $uuid): StudioVersion
    {
        return StudioVersion::query()->with('review')->where('uuid', strtolower($uuid))->first()
            ?? throw UltronStudioException::versionNotFound();
    }

    /**
     * POST versions: copia el borrador GUARDADO de quien la pide. Idempotente
     * por `clientRequestId`: 201 la primera vez, 200 `replayed` al repetir.
     *
     * @return array{version:StudioVersion,replayed:bool}
     */
    public function create(StudioAccess $access, Request $request, string $label, ?string $note, int $draftRevision, string $clientRequestId): array
    {
        $access->require(StudioAccess::EDIT_DRAFT);

        // EL REINTENTO VA PRIMERO: el doble clic encuentra la versión del primero.
        if ($previa = $this->replay($access, $clientRequestId, $label, $draftRevision, $note)) {
            return ['version' => $previa, 'replayed' => true];
        }

        try {
            $version = DB::transaction(function () use ($access, $request, $label, $note, $draftRevision, $clientRequestId): StudioVersion {
                $draft = $this->drafts->find($access);
                if ($draft === null) {
                    throw UltronStudioException::draftNotFound();
                }
                if ($draft->revision !== $draftRevision) {
                    throw UltronStudioException::draftConflict(StudioDrafts::present($draft));
                }

                // Se vuelve a pasar por el normalizador: si el catálogo cambió
                // desde que se guardó, lo que ya no conoce es un 422, no una
                // versión con piezas que el motor no tiene.
                $content = StudioDraftNormalizer::normalize($draft->content);
                $findings = self::findings($content);
                if (! StudioGraphValidator::isPublishable($findings)) {
                    throw UltronStudioException::validationBlocked('El borrador tiene bloqueos: corrígelos antes de crear una versión.', $findings);
                }

                $reglas = $this->rules->snapshotForVersion();
                $copia = [
                    'catalogVersion' => StudioCatalog::VERSION,
                    'definition' => $content['definition'],
                    'configs' => $content['configs'],
                    'layout' => $content['layout'],
                    'rules' => $reglas,
                ];
                $hash = StudioContentHasher::versionHash(StudioCatalog::VERSION, $copia, $reglas);
                $validacion = self::lastValidation($findings);
                $yo = $access->snapshot();

                $version = StudioVersion::query()->forceCreate([
                    'client_request_id' => $clientRequestId,
                    'label' => $label,
                    'note' => $note,
                    'status' => StudioVersionStatus::Draft->value,
                    'content' => $copia,
                    'content_hash' => $hash,
                    'catalog_version' => StudioCatalog::VERSION,
                    'catalog_hash' => StudioCatalog::hash(),
                    'source_draft_revision' => $draftRevision,
                    'created_by_admin_id' => $yo['id'],
                    'created_by_name' => $yo['name'],
                    'created_by_role' => $yo['role'],
                    'last_validation' => $validacion,
                    'status_changed_at' => now(),
                ]);

                $this->audit->record($request, $access->actor, 'version.created', StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
                    "Creó la versión «{$label}»", [
                        'toStatus' => StudioVersionStatus::Draft->value,
                        'contentHash' => $hash,
                        'diff' => [
                            'draftRevision' => $draftRevision,
                            'catalogVersion' => StudioCatalog::VERSION,
                            'warnings' => $validacion['warnings'],
                            'rulesCount' => count($reglas),
                        ],
                    ]);

                return $version;
            });
        } catch (UniqueConstraintViolationException $e) {
            // La misma llave llegó dos veces a la vez y el índice único paró a
            // la segunda. Se resuelve FUERA de la transacción: en PostgreSQL un
            // error la deja abortada.
            $previa = $this->replay($access, $clientRequestId, $label, $draftRevision);
            if ($previa === null) {
                throw $e;
            }

            return ['version' => $previa, 'replayed' => true];
        }

        return ['version' => $this->findByUuid($version->uuid), 'replayed' => false];
    }

    /**
     * POST versions/{uuid}/validate: simulación contra el catálogo actual. No
     * escribe nada ni deja traza.
     *
     * @return array{catalogVersion:string,publishable:bool,warnings:list<string>,findings:list<array<string,mixed>>,wouldBecome:string|null}
     */
    public function simulate(StudioVersion $version): array
    {
        $findings = self::findings($version->content);
        $publicable = StudioGraphValidator::isPublishable($findings);
        $avisos = StudioGraphValidator::warnings($findings);

        $destino = null;
        if ($version->status === StudioVersionStatus::Draft->value && $publicable) {
            $destino = $avisos !== [] ? StudioVersionStatus::ReviewRequired->value : StudioVersionStatus::Validated->value;
        }

        return [
            'catalogVersion' => StudioCatalog::VERSION,
            'publishable' => $publicable,
            'warnings' => $avisos,
            'findings' => $findings,
            'wouldBecome' => $destino,
        ];
    }

    /** POST versions/{uuid}/submit: solo quien la creó, desde DRAFT. */
    public function submit(StudioAccess $access, Request $request, StudioVersion $version): StudioVersion
    {
        $access->require(StudioAccess::EDIT_DRAFT);
        if (! $access->isCreator($version)) {
            throw UltronStudioException::notVersionOwner();
        }
        $this->assertStatus($version, [StudioVersionStatus::Draft], 'enviar a revisión');
        $this->assertIntegrity($version);

        $findings = self::findings($version->content);
        if (! StudioGraphValidator::isPublishable($findings)) {
            throw UltronStudioException::validationBlocked(
                'La versión ya no pasa la validación con el catálogo actual: sigue sin enviar. Corrige el borrador y crea una versión nueva.',
                $findings,
            );
        }

        $validacion = self::lastValidation($findings);
        $destino = $validacion['warnings'] !== [] ? StudioVersionStatus::ReviewRequired : StudioVersionStatus::Validated;

        DB::transaction(function () use ($access, $request, $version, $validacion, $destino, $findings): void {
            $ahora = now();
            if (! StudioVersion::transition($version->id, [StudioVersionStatus::Draft->value], [
                'status' => $destino->value,
                'last_validation' => $validacion,
                'submitted_at' => $ahora,
                'status_changed_at' => $ahora,
            ])) {
                throw $this->movedMeanwhile($version, 'enviar a revisión', [StudioVersionStatus::Draft]);
            }

            $this->audit->record($request, $access->actor, 'version.submitted', StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
                "Envió a revisión «{$version->label}»", [
                    'fromStatus' => StudioVersionStatus::Draft->value,
                    'toStatus' => $destino->value,
                    'contentHash' => $version->content_hash,
                    'diff' => ['warnings' => $validacion['warnings'], 'findingsCount' => count($findings)],
                ]);
        });

        return $this->findByUuid($version->uuid);
    }

    /**
     * POST versions/{uuid}/approve: OTRA persona, revalidando y reconociendo
     * exactamente los avisos actuales.
     *
     * @param  list<string>  $acknowledgedWarnings
     */
    public function approve(StudioAccess $access, Request $request, StudioVersion $version, array $acknowledgedWarnings, ?string $note): StudioVersion
    {
        $access->require(StudioAccess::APPROVE);
        $this->assertFourEyes($access, $request, $version, 'approve');
        $this->assertStatus($version, StudioVersionStatus::REVIEWABLE, 'aprobar');
        $this->assertIntegrity($version);

        $findings = self::findings($version->content);
        if (! StudioGraphValidator::isPublishable($findings)) {
            throw UltronStudioException::revalidationBlocked($findings);
        }
        $avisos = StudioGraphValidator::warnings($findings);
        if (self::asSet($acknowledgedWarnings) !== self::asSet($avisos)) {
            throw UltronStudioException::warningsNotAcknowledged($avisos);
        }

        $validacion = self::lastValidation($findings);
        $desde = $version->status;

        DB::transaction(function () use ($access, $request, $version, $validacion, $avisos, $note, $desde): void {
            if (! StudioVersion::transition($version->id, self::reviewableValues(), [
                'status' => StudioVersionStatus::Approved->value,
                'last_validation' => $validacion,
                'status_changed_at' => now(),
            ])) {
                throw $this->movedMeanwhile($version, 'aprobar', StudioVersionStatus::REVIEWABLE);
            }

            $this->insertReview($access, $version, StudioVersionReview::DECISION_APPROVED, $note, $avisos, $validacion);

            $this->audit->record($request, $access->actor, 'version.approved', StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
                "Aprobó «{$version->label}»", [
                    'fromStatus' => $desde,
                    'toStatus' => StudioVersionStatus::Approved->value,
                    'contentHash' => $version->content_hash,
                    'diff' => ['acknowledgedWarnings' => $avisos, 'note' => $note],
                ]);
        });

        return $this->findByUuid($version->uuid);
    }

    /** POST versions/{uuid}/reject: OTRA persona, con motivo (5–500, lo valida el controlador). */
    public function reject(StudioAccess $access, Request $request, StudioVersion $version, string $reason): StudioVersion
    {
        $access->require(StudioAccess::APPROVE);
        $this->assertFourEyes($access, $request, $version, 'reject');
        $this->assertStatus($version, StudioVersionStatus::REVIEWABLE, 'rechazar');

        // La validación que vio quien rechaza queda en la reseña.
        $validacion = self::lastValidation(self::findings($version->content));
        $desde = $version->status;

        DB::transaction(function () use ($access, $request, $version, $reason, $validacion, $desde): void {
            if (! StudioVersion::transition($version->id, self::reviewableValues(), [
                'status' => StudioVersionStatus::Rejected->value,
                'status_changed_at' => now(),
            ])) {
                throw $this->movedMeanwhile($version, 'rechazar', StudioVersionStatus::REVIEWABLE);
            }

            $this->insertReview($access, $version, StudioVersionReview::DECISION_REJECTED, $reason, null, $validacion);

            $this->audit->record($request, $access->actor, 'version.rejected', StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
                "Rechazó «{$version->label}»", [
                    'fromStatus' => $desde,
                    'toStatus' => StudioVersionStatus::Rejected->value,
                    'contentHash' => $version->content_hash,
                    'diff' => ['reason' => $reason],
                ]);
        });

        return $this->findByUuid($version->uuid);
    }

    /**
     * POST versions/{uuid}/publish | rollback: 409 SIEMPRE en esta fase. El
     * intento queda auditado aparte (la operación falla, no hay transacción
     * que confirmar) y se devuelve el rechazo para que el controlador lo lance.
     */
    public function refuse(StudioAccess $access, Request $request, StudioVersion $version, string $action): UltronStudioException
    {
        $access->require($action === 'rollback' ? StudioAccess::ROLLBACK : StudioAccess::PUBLISH);
        $rechazo = StudioPublishing::refusal($action);

        $this->audit->recordDenied($request, $access->actor, "version.{$action}_refused", StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
            ($action === 'rollback' ? 'Pidió revertir' : 'Pidió publicar')." «{$version->label}»: rechazado ({$rechazo['code']})", [
                'fromStatus' => $version->status,
                'contentHash' => $version->content_hash,
                'diff' => ['code' => $rechazo['code']],
            ]);

        return UltronStudioException::publishingRefused($rechazo['code'], $rechazo['message']);
    }

    /**
     * VersionSummary (contrato §A3).
     *
     * @return array<string, mixed>
     */
    public function summary(StudioAccess $access, StudioVersion $v): array
    {
        $ultima = is_array($v->last_validation) ? $v->last_validation : [];
        $bloqueos = 0;
        foreach ($ultima['findings'] ?? [] as $f) {
            if (($f['level'] ?? null) === 'blocking') {
                $bloqueos++;
            }
        }
        $estado = $v->statusEnum();
        $resena = $v->relationLoaded('review') ? $v->review : $v->review()->first();

        return [
            'uuid' => $v->uuid,
            'label' => $v->label,
            'status' => $estado->value,
            'statusLabel' => $estado->label(),
            'contentHash' => $v->content_hash,
            'shortHash' => substr($v->content_hash, 0, 8),
            'catalogVersion' => $v->catalog_version,
            'createdBy' => ['name' => $v->created_by_name, 'role' => $v->created_by_role],
            'mine' => $access->isCreator($v),
            'createdAt' => $v->created_at?->toIso8601String(),
            'submittedAt' => $v->submitted_at?->toIso8601String(),
            'warnings' => array_values($ultima['warnings'] ?? []),
            'blocking' => $bloqueos,
            'review' => $resena !== null ? [
                'decision' => $resena->decision,
                'by' => ['name' => $resena->reviewer_name, 'role' => $resena->reviewer_role],
                'at' => $resena->created_at?->toIso8601String(),
                'note' => $resena->note,
                'acknowledgedWarnings' => array_values($resena->acknowledged_warnings ?? []),
            ] : null,
            'engineReads' => StudioPublishing::ENGINE_READS_VERSIONS,
        ];
    }

    /**
     * VersionDetail: el resumen más la nota, el contenido, la última
     * validación, la integridad recalculada y qué puede hacer quien mira.
     *
     * @return array<string, mixed>
     */
    public function detail(StudioAccess $access, StudioVersion $v): array
    {
        $contenido = $v->content;
        $salida = StudioDrafts::output($contenido);

        return $this->summary($access, $v) + [
            'note' => $v->note,
            'content' => $salida + ['rules' => array_values($contenido['rules'] ?? [])],
            'lastValidation' => $v->last_validation,
            'integrityOk' => self::integrityOk($v),
            'actions' => $this->actions($access, $v),
        ];
    }

    /**
     * Recalcula la huella del contenido guardado y la compara con la guardada.
     * Un contenido que ni siquiera se puede firmar (decimales, campos que
     * faltan) tampoco es íntegro.
     */
    public static function integrityOk(StudioVersion $v): bool
    {
        $c = $v->content;
        if (! is_array($c) || ! is_string($c['catalogVersion'] ?? null) || ! is_array($c['rules'] ?? null)) {
            return false;
        }

        try {
            $hash = StudioContentHasher::versionHash($c['catalogVersion'], $c, $c['rules']);
        } catch (InvalidArgumentException) {
            return false;
        }

        return hash_equals((string) $v->content_hash, $hash);
    }

    /**
     * Qué puede hacer quien mira con esta versión, y si no, por qué (el CRM
     * pinta los botones con esto; el servidor lo vuelve a comprobar al actuar).
     *
     * @return array<string, array{allowed:bool,code?:string,message?:string}>
     */
    private function actions(StudioAccess $access, StudioVersion $v): array
    {
        $estado = $v->statusEnum();
        $mia = $access->isCreator($v);
        $no = static fn (string $code, string $message): array => ['allowed' => false, 'code' => $code, 'message' => $message];

        $enviar = match (true) {
            ! $access->can(StudioAccess::EDIT_DRAFT) => $no('forbidden', 'Hace falta el permiso «Editar el borrador y crear versiones».'),
            ! $mia => $no('not_version_owner', 'Solo quien creó la versión puede enviarla a revisión.'),
            $estado !== StudioVersionStatus::Draft => $no('invalid_transition', 'Solo se envía una versión recién creada.'),
            default => ['allowed' => true],
        };

        $revisar = match (true) {
            ! $access->can(StudioAccess::APPROVE) => $no('forbidden', 'Hace falta el permiso «Aprobar o rechazar versiones de otra persona».'),
            $mia => $no('four_eyes', 'Nadie aprueba ni rechaza lo que creó: tiene que revisarla otra persona.'),
            ! $estado->isReviewable() => $no('invalid_transition', 'Solo se revisa una versión enviada a revisión.'),
            default => ['allowed' => true],
        };

        $publicar = StudioPublishing::refusal('publish');
        $revertir = StudioPublishing::refusal('rollback');

        return [
            'submit' => $enviar,
            'approve' => $revisar,
            'reject' => $revisar,
            'publish' => $no($publicar['code'], $publicar['message']),
            'rollback' => $no($revertir['code'], $revertir['message']),
        ];
    }

    /**
     * Cuatro ojos por IDENTIDAD. El intento queda auditado aparte y se
     * rechaza.
     */
    private function assertFourEyes(StudioAccess $access, Request $request, StudioVersion $version, string $action): void
    {
        if (! $access->isCreator($version)) {
            return;
        }

        $this->audit->recordDenied($request, $access->actor, 'version.four_eyes_denied', StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
            ($action === 'reject' ? 'Intentó rechazar' : 'Intentó aprobar')." su propia versión «{$version->label}»", [
                'fromStatus' => $version->status,
                'contentHash' => $version->content_hash,
                'diff' => ['action' => $action],
            ]);

        throw UltronStudioException::fourEyesRequired();
    }

    /** @param  list<StudioVersionStatus>  $allowed */
    private function assertStatus(StudioVersion $version, array $allowed, string $action): void
    {
        $estado = $version->statusEnum();
        if (! in_array($estado, $allowed, true)) {
            throw UltronStudioException::invalidTransition(
                $estado->value,
                $estado->label(),
                $action,
                array_map(static fn (StudioVersionStatus $s): string => $s->value, $allowed),
            );
        }
    }

    private function assertIntegrity(StudioVersion $version): void
    {
        if (! self::integrityOk($version)) {
            throw UltronStudioException::integrityMismatch();
        }
    }

    /**
     * El UPDATE condicionado no tocó ninguna fila: otra petición la movió
     * mientras esta revalidaba. Se responde con el estado de AHORA.
     *
     * @param  list<StudioVersionStatus>  $allowed
     */
    private function movedMeanwhile(StudioVersion $version, string $action, array $allowed): UltronStudioException
    {
        $ahora = StudioVersionStatus::from((string) StudioVersion::query()->whereKey($version->id)->value('status'));

        return UltronStudioException::invalidTransition(
            $ahora->value,
            $ahora->label(),
            $action,
            array_map(static fn (StudioVersionStatus $s): string => $s->value, $allowed),
        );
    }

    /**
     * @param  list<string>|null  $acknowledged
     * @param  array<string, mixed>  $validation
     */
    private function insertReview(StudioAccess $access, StudioVersion $version, string $decision, ?string $note, ?array $acknowledged, array $validation): void
    {
        $yo = $access->snapshot();

        StudioVersionReview::query()->forceCreate([
            'version_id' => $version->id,
            'decision' => $decision,
            'reviewer_admin_id' => $yo['id'],
            'reviewer_name' => $yo['name'],
            'reviewer_role' => $yo['role'],
            'note' => $note,
            'acknowledged_warnings' => $acknowledged,
            'validation' => $validation,
            // Lo que se revisó: la huella de la versión, que no cambia.
            'content_hash' => $version->content_hash,
        ]);
    }

    /**
     * La misma petición repetida devuelve la versión de la primera. La misma
     * llave para otra cosa (otra persona, otra revisión, otra etiqueta) es 409.
     */
    private function replay(StudioAccess $access, string $clientRequestId, string $label, int $draftRevision, ?string $note = null): ?StudioVersion
    {
        $previa = StudioVersion::query()->with('review')->where('client_request_id', strtolower($clientRequestId))->first();
        if ($previa === null) {
            return null;
        }

        // Otra persona, otra revisión, otra etiqueta u otra nota: no es la misma petición.
        if (! $access->isCreator($previa) || $previa->source_draft_revision !== $draftRevision || $previa->label !== $label || $previa->note !== $note) {
            throw UltronStudioException::clientRequestReused();
        }

        return $previa;
    }

    /**
     * Valida un contenido contra el catálogo actual.
     *
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    private static function findings(array $content): array
    {
        return StudioGraphValidator::validate(StudioCatalog::graph(), $content['definition'], $content['configs']);
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @return array{catalogVersion:string,validatedAt:string,publishable:bool,warnings:list<string>,findings:list<array<string,mixed>>}
     */
    private static function lastValidation(array $findings): array
    {
        return [
            'catalogVersion' => StudioCatalog::VERSION,
            'validatedAt' => now()->toIso8601String(),
            'publishable' => StudioGraphValidator::isPublishable($findings),
            'warnings' => StudioGraphValidator::warnings($findings),
            'findings' => $findings,
        ];
    }

    /** @return list<string> */
    private static function reviewableValues(): array
    {
        return array_map(static fn (StudioVersionStatus $s): string => $s->value, StudioVersionStatus::REVIEWABLE);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function asSet(array $ids): array
    {
        $set = array_values(array_unique($ids));
        sort($set, SORT_STRING);

        return $set;
    }
}
