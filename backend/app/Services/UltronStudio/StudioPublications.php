<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioPublication;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVersionReview;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * PUBLICAR, PROMOVER, REVERTIR Y VOLVER A LEGACY (contrato H5 §A2, §A5, §A6,
 * §A7 y §A8; Enmienda 1): las puertas, la cadena y lo que se enseña de ella.
 *
 * LA CADENA (`ultron_studio_publications`, §A3.1) es de SOLO INSERCIÓN. Cada
 * escritura inserta una entrada con `seq` = cabeza + 1 cuyo texto firmado
 * (`effective_json`) dice qué versión rige en el canario y cuál en el resto;
 * el motor lee la cabeza (`UltronPublishedConfig`) y la anota en cada turno.
 * El texto lo compone {@see D} (núcleo puro) y lo
 * firma la ÚNICA implementación de la firma, la del motor
 * (`UltronPublishedConfig::signatureFor()`). Antes de componer encima de la
 * cabeza se verifican su contrato, su huella, su firma y su forma: una cabeza
 * que no verifica no se copia a una entrada firmada nueva (409
 * `publication_head_invalid`); volver a Legacy no copia nada y sigue abierto.
 *
 * LAS PUERTAS van en el orden de §A5 y del fixture del contrato
 * (`publication-cases.json`, sección `gates`): permiso → llaves → reintento →
 * versión y cuatro ojos → estado e integridad → … → ventana → transacción. La
 * vista previa calcula las mismas puertas sin escribir nada.
 *
 * LA TRANSACCIÓN: la entrada, las transiciones (`StudioVersion::transition()`,
 * UPDATE condicionado, sin `FOR UPDATE`) y los eventos del libro, juntos. Las
 * transiciones van siempre en el orden «la que sale y después la que entra»
 * (los índices únicos parciales `usv_one_*` se comprueban fila a fila: informe
 * del especialista de datos §5.4). Las carreras:
 *  - dos escrituras que leyeron la misma cabeza chocan en `seq` (23505) → 409
 *    `publication_moved`; dos versiones hacia CANARY, en `usv_one_canary` → 409
 *    `canary_in_progress`; la misma petición dos veces, en `client_request_id`
 *    → la entrada de la primera (200 `replayed`). En PostgreSQL un 23505 deja
 *    abortada la transacción: todo eso se resuelve FUERA de ella, como
 *    `StudioVersions::create`;
 *  - la versión movida por otra petición: el UPDATE condicionado no toca
 *    ninguna fila → 409 `invalid_transition` con el estado de AHORA.
 *
 * IDEMPOTENCIA (§A5.1, paso 3): la misma `clientRequestId`, la misma persona y
 * el mismo cuerpo devuelven la entrada de la primera vez, aunque la versión ya
 * se haya movido; cualquier otra cosa es 409 `client_request_reused`. Para
 * reconocer el cuerpo, la entrada guarda lo que lo identifica: el conjunto
 * reconocido y la `previewHash` (en `acknowledged`), la evidencia (cuya huella
 * es la `evidenceHash`) y la nota o el motivo.
 *
 * LO QUE NINGUNA PUERTA HACE (§A5.5): ni encender ni apagar ULTRON, ni tocar
 * entorno, pausa, freno, planes, conocimiento o n8n. Solo se escribe en
 * `ultron_studio_*`. Del motor se LEE (las lecturas de
 * {@see StudioEngineReadings}, los recuentos de {@see StudioCanaryMeasures}) y
 * se llama a la firma, que es pura.
 *
 * ENMIENDA M-1 (plan de motores §B.4 y §D): la versión publicada GOBIERNA los
 * prompts del motor en la conversación canaria. Con la llave de prompts del
 * motor encendida ({@see StudioEngineReadings::promptsKey()}), publicar compila
 * el paquete de la versión ({@see StudioPromptBundle}) y escribe
 * `ultron-engine-config/2`: cada parte con sus cuatro valores gobernados y la
 * huella del paquete. La vista previa enseña el cambio exacto (`prompts`,
 * `effect = prompts`) y su huella entra en `previewHash`. Las reglas de
 * escritura:
 *  - con la llave en `off`, todo como antes: `/1`, la clasificación y la huella
 *    de siempre (las partes `/2` de la cabeza se copian sin sus dos claves);
 *  - volver a Legacy, siempre `/1` (R10);
 *  - publicar: `/2`; la parte que se arrastra del resto, como estaba firmada
 *    si ya era `/2` o compilada ENTERA desde su versión si era `/1`, frases de
 *    sus reglas incluidas (la vista previa la enseña en `prompts.carried`, con
 *    sus frases en `phrases`); si sus frases no caben, 409
 *    `prompts_rule_phrases_invalid` con `part: carried` y sus `problems`; si
 *    otra cosa no compila, 409 `prompts_compile_failed`: nunca se firma una
 *    parte que no compila ni se arrastra a medias;
 *  - promover y revertir solo MUEVEN partes: `/2` si todas las de la entrada
 *    nueva ya lo eran (las firmó una publicación con su vista previa); si no,
 *    `/1` (la canaria vuelve a los prompts Legacy). Nunca dan efecto a un
 *    paquete que nadie vio.
 */
final class StudioPublications
{
    /** Topes de los cuerpos (§A5.1 y §A5.3). */
    public const MAX_ACKNOWLEDGED = 64;

    public const NOTE_MAX = 500;

    public const REASON_MIN = 5;

    public const REASON_MAX = 500;

    /** Tope de `per_page` del historial (`GET publications`). */
    public const PER_PAGE_MAX = 50;

    /** Hallazgo bloqueante propio de la revalidación cuando el catálogo actual no sabe leer el contenido (m-2 del reviewer del Hito 5). */
    public const FINDING_UNREADABLE = 'contenido-ilegible';

    /**
     * El limitador propio de la evidencia (`ultron-studio-evidence`, en
     * AppServiceProvider): por minuto y persona. Aquí para la exportación de
     * fixtures; una prueba lo ata al limitador.
     */
    public const EVIDENCE_PER_MINUTE = 10;

    /** `state` de `PublicationStateDto` (§A7). */
    public const STATE_APPLIED = 'applied';

    public const STATE_LEGACY = 'legacy';

    public const STATE_OFF = 'off';

    public const STATE_FALLBACK = 'fallback';

    /** El mensaje del bloque `engine` con la llave de prompts del motor en `off` (el de siempre). */
    public const ENGINE_MESSAGES = [
        StudioPublishing::SCOPE_OFF => 'El motor de ULTRON no lee la cadena de publicaciones (su llave está apagada): publicar aquí no cambia nada en el agente.',
        StudioPublishing::SCOPE_CANARY => 'El motor de ULTRON lee la cadena en la conversación canaria y anota en cada turno la versión publicada. Publicar no cambia lo que ULTRON dice ni hace.',
        StudioPublishing::SCOPE_ALL => 'El motor de ULTRON lee la cadena en todas las conversaciones y anota en cada turno la versión publicada. Publicar no cambia lo que ULTRON dice ni hace.',
    ];

    /**
     * Enmienda M-1 (§D): con la llave de prompts del motor encendida, publicar
     * SÍ cambia lo que ULTRON dice, y solo en la conversación canaria. Con la
     * llave del sello en `off` el motor no lee nada y el mensaje es el de
     * siempre.
     */
    public const ENGINE_MESSAGES_PROMPTS = [
        StudioPublishing::SCOPE_CANARY => 'El motor de ULTRON lee la cadena en la conversación canaria y anota en cada turno la versión publicada. Con la llave de prompts encendida, publicar CAMBIA lo que ULTRON dice en esa conversación: el tono, los emojis, la longitud y el objetivo comercial de la versión entran en sus prompts, y también las frases que prohíben sus reglas S-NNN vivas cuyo «si» se cumple (se le piden al modelo y el Critic las vigila; no es un filtro que bloquee el envío). La longitud, además, se mide antes de enviar: a un mensaje que pasa del tope se le quita la pregunta final y, si aun así no cabe, se sustituye por un texto que cabe (con el tope de la línea base, 320, no se mide). «Si no entiende» sigue sin efecto en WhatsApp.',
        StudioPublishing::SCOPE_ALL => 'El motor de ULTRON lee la cadena en todas las conversaciones y anota en cada turno la versión publicada. Con la llave de prompts encendida, publicar CAMBIA lo que ULTRON dice, solo en la conversación canaria: el tono, los emojis, la longitud y el objetivo comercial de la versión entran en sus prompts, y también las frases que prohíben sus reglas S-NNN vivas cuyo «si» se cumple (se le piden al modelo y el Critic las vigila; no es un filtro que bloquee el envío). En las demás conversaciones no cambia nada. La longitud, además, se mide antes de enviar: a un mensaje que pasa del tope se le quita la pregunta final y, si aun así no cabe, se sustituye por un texto que cabe (con el tope de la línea base, 320, no se mide). «Si no entiende» sigue sin efecto en WhatsApp.',
    ];

    /** Enmienda M-1: `effect` de la vista previa. */
    public const EFFECT_DECLARATION = 'declaration';

    public const EFFECT_PROMPTS = 'prompts';

    /** Enmienda M-1 (§D): turnos de la canaria en `status.engine.prompts.lastTurns`. */
    public const PROMPT_TURNS = 20;

    /** Las columnas de una entrada que salen en el historial y en los reintentos (sin el texto firmado). */
    private const COLUMNS = [
        'id', 'uuid', 'seq', 'client_request_id', 'action', 'version_id', 'restores_publication_id', 'previous_publication_id',
        'engine_contract', 'effective_hash', 'signature', 'catalog_version', 'lab_gate', 'acknowledged', 'evidence', 'reason',
        'actor_admin_id', 'actor_name', 'actor_role', 'created_at',
    ];

    public function __construct(
        private readonly StudioAudit $audit,
        private readonly StudioVersions $versions,
        private readonly StudioEngineReadings $engine,
        private readonly StudioCanaryMeasures $canary,
    ) {}

    // ── Publicar (§A5.1) ────────────────────────────────────────────────────

    /**
     * POST versions/{uuid}/publish: la versión APPROVED pasa a CANARY y la
     * cadena declara `{canary: ella, rest: lo que hubiera}`.
     *
     * @param  list<string>  $acknowledgedNoEffect
     * @return array{entry:array<string,mixed>,replayed:bool}
     */
    public function publish(StudioAccess $access, Request $request, string $uuid, string $clientRequestId, string $previewHash, array $acknowledgedNoEffect, ?string $note): array
    {
        // 1–2: el permiso y las dos llaves.
        $access->require(StudioAccess::PUBLISH);
        $this->assertKeys($access, $request, $uuid, D::ACTION_PUBLISH);

        // 3: el reintento.
        $cuerpo = ['noEffect' => self::asSet($acknowledgedNoEffect), 'previewHash' => $previewHash, 'note' => $note];
        if ($previa = $this->replay($access, D::ACTION_PUBLISH, $uuid, $clientRequestId, $cuerpo)) {
            return ['entry' => $previa, 'replayed' => true];
        }

        // 4–12, volviendo a mirar el reintento ante cualquier rechazo (H-1).
        return $this->orReplayed(fn (): array => $this->publishGated($access, $request, $uuid, $clientRequestId, $previewHash, $acknowledgedNoEffect, $note, $cuerpo), $access, D::ACTION_PUBLISH, $uuid, $clientRequestId, $cuerpo);
    }

    /**
     * Publicar, de los cuatro ojos a la transacción (pasos 4–12 de §A5.1).
     *
     * @param  list<string>  $acknowledgedNoEffect
     * @param  array<string,mixed>  $cuerpo
     * @return array{entry:array<string,mixed>,replayed:bool}
     */
    private function publishGated(StudioAccess $access, Request $request, string $uuid, string $clientRequestId, string $previewHash, array $acknowledgedNoEffect, ?string $note, array $cuerpo): array
    {
        // 4: la versión y los cuatro ojos.
        $version = $this->versions->findByUuid($uuid);
        $this->assertFourEyes($access, $request, $version, D::ACTION_PUBLISH);

        // 5–11: sobre las puertas calculadas (lo mismo que enseña la vista previa).
        $h = $this->facts($access, $version);
        $rechazo = self::publishRefusal($h, $acknowledgedNoEffect, $previewHash, (string) $version->uuid, (string) $version->content_hash);
        if ($rechazo !== null) {
            throw $rechazo;
        }

        // 12: la transacción.
        $acknowledged = ['noEffect' => D::requiredAcknowledgements($h['classification']), 'previewHash' => $previewHash];

        try {
            $fila = DB::transaction(function () use ($access, $request, $version, $clientRequestId, $h, $acknowledged, $note): StudioPublication {
                $ahora = now();
                $cabeza = $this->head();
                $partes = $this->verifiedParts($cabeza);
                $parte = D::part(
                    (string) $version->uuid,
                    (string) $version->content_hash,
                    (string) $version->catalog_version,
                    (int) $version->review?->reviewer_admin_id,
                    $access->id(),
                    D::declared($version->content['configs']),
                );
                try {
                    $nuevas = D::compose(D::ACTION_PUBLISH, (string) $version->uuid, StudioVersionStatus::Approved->value, $partes, $parte);
                } catch (InvalidArgumentException) {
                    // La cabeza ya tiene otra versión en canario (otra
                    // publicación se confirmó después de las puertas).
                    throw $this->otherCanaryExists($version) ? UltronStudioException::canaryInProgress() : $this->raceRefusal($version, 'publicar', [StudioVersionStatus::Approved]);
                }
                // Enmienda M-1: `/2` con el paquete que enseñó la vista previa, o `/1`.
                [$contrato, $nuevas] = $this->promptedForPublish($nuevas, $h['prompts'] ?? null);

                $labGate = array_filter([
                    'mode' => $h['labGate']['mode'],
                    'runUuid' => $h['labGate']['runUuid'],
                    'verdictId' => $h['labGate']['verdictId'],
                    'promptVersion' => $h['labGate']['promptVersion'],
                ], static fn (mixed $v): bool => $v !== null);

                $fila = $this->insertEntry(D::ACTION_PUBLISH, $version, $cabeza, $nuevas, $ahora, $access, $clientRequestId, $labGate, $acknowledged, null, $note, null, $contrato);

                if (! StudioVersion::transition($version->id, [StudioVersionStatus::Approved->value], [
                    'status' => StudioVersionStatus::Canary->value,
                    'status_changed_at' => $ahora,
                ])) {
                    throw $this->raceRefusal($version, 'publicar', [StudioVersionStatus::Approved]);
                }

                $this->audit->record($request, $access->actor, 'version.published', StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
                    "Publicó «{$version->label}» en canario (entrada {$fila->seq})", [
                        'fromStatus' => StudioVersionStatus::Approved->value,
                        'toStatus' => StudioVersionStatus::Canary->value,
                        'contentHash' => $version->content_hash,
                        'diff' => [
                            'entry' => $fila->uuid,
                            'seq' => (int) $fila->seq,
                            'effectiveHash' => $fila->effective_hash,
                            'labGate' => $labGate,
                            'noEffect' => $acknowledged['noEffect'],
                        ],
                    ]);

                return $fila;
            });
        } catch (UniqueConstraintViolationException $e) {
            // FUERA de la transacción (en PostgreSQL un 23505 la deja abortada).
            if ($previa = $this->replay($access, D::ACTION_PUBLISH, $uuid, $clientRequestId, $cuerpo)) {
                return ['entry' => $previa, 'replayed' => true];
            }

            throw $this->conflictRefusal($e, D::ACTION_PUBLISH);
        }

        return ['entry' => self::entryDto($fila, self::versionRef($version)), 'replayed' => false];
    }

    // ── Promover (§A5.2) ────────────────────────────────────────────────────

    /**
     * POST versions/{uuid}/promote: la versión CANARY pasa a PUBLISHED (la
     * PUBLISHED anterior, si la hay, a RETIRED) y la cadena declara
     * `{canary: ella, rest: ella}`, con la MISMA parte (Q-3: quien promueve va
     * en la fila y en el libro, no en el texto firmado).
     *
     * @return array{entry:array<string,mixed>,replayed:bool}
     */
    public function promote(StudioAccess $access, Request $request, string $uuid, string $clientRequestId, string $evidenceHash, ?string $note): array
    {
        $access->require(StudioAccess::PUBLISH);
        $this->assertKeys($access, $request, $uuid, D::ACTION_PROMOTE);

        $cuerpo = ['evidenceHash' => $evidenceHash, 'note' => $note];
        if ($previa = $this->replay($access, D::ACTION_PROMOTE, $uuid, $clientRequestId, $cuerpo)) {
            return ['entry' => $previa, 'replayed' => true];
        }

        // De aquí en adelante, volviendo a mirar el reintento ante cualquier rechazo (H-1).
        return $this->orReplayed(fn (): array => $this->promoteGated($access, $request, $uuid, $clientRequestId, $evidenceHash, $note, $cuerpo), $access, D::ACTION_PROMOTE, $uuid, $clientRequestId, $cuerpo);
    }

    /**
     * Promover, de los cuatro ojos a la transacción (§A5.2).
     *
     * @param  array<string,mixed>  $cuerpo
     * @return array{entry:array<string,mixed>,replayed:bool}
     */
    private function promoteGated(StudioAccess $access, Request $request, string $uuid, string $clientRequestId, string $evidenceHash, ?string $note, array $cuerpo): array
    {
        $version = $this->versions->findByUuid($uuid);
        $this->assertFourEyes($access, $request, $version, D::ACTION_PROMOTE);

        // La llave del motor (tiene que alcanzar a todos: si no, promover no
        // cambiaría nada para el resto; fixture P-7, después de los cuatro
        // ojos), el estado y la integridad.
        $rechazo = self::promoteStateRefusal(StudioPublishing::engineScope(), $version->statusEnum(), StudioVersions::integrityOk($version));
        if ($rechazo !== null) {
            if ($rechazo->errorCode === StudioPublishing::CODE_SCOPE_NOT_ALLOWED) {
                $this->recordRefused($access, $request, $version, D::ACTION_PROMOTE, StudioPublishing::CODE_SCOPE_NOT_ALLOWED);
            }

            throw $rechazo;
        }

        // La evidencia (§A6), el freno y la ventana.
        $evidencia = $this->evidenceFor($version);
        $freno = self::brakeState($this->engine->only([], ['governance.brake'])['extras']['governance.brake']);
        $ventana = StudioPublishing::window();
        $rechazo = self::promoteEvidenceRefusal($evidencia['evaluation'], $evidencia['dto'], $evidenceHash, $freno, StudioPublishing::windowOpenFor($ventana, now()), $ventana['label'] ?? null);
        if ($rechazo !== null) {
            throw $rechazo;
        }

        try {
            $fila = DB::transaction(function () use ($access, $request, $version, $clientRequestId, $evidencia, $note): StudioPublication {
                $ahora = now();
                $cabeza = $this->head();
                $partes = $this->verifiedParts($cabeza);
                try {
                    $nuevas = D::compose(D::ACTION_PROMOTE, (string) $version->uuid, StudioVersionStatus::Canary->value, $partes);
                } catch (InvalidArgumentException) {
                    throw $this->raceRefusal($version, 'promover', [StudioVersionStatus::Canary]);
                }
                [$contrato, $nuevas] = $this->promptedForMove($nuevas);

                $anterior = StudioVersion::query()->where('status', StudioVersionStatus::Published->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']);
                $fila = $this->insertEntry(D::ACTION_PROMOTE, $version, $cabeza, $nuevas, $ahora, $access, $clientRequestId, self::notApplicableLabGate(), null, $evidencia['record'], $note, null, $contrato);

                // Primero la que SALE de PUBLISHED, después la que entra.
                if ($anterior !== null && ! StudioVersion::transition($anterior->id, [StudioVersionStatus::Published->value], [
                    'status' => StudioVersionStatus::Retired->value,
                    'status_changed_at' => $ahora,
                ])) {
                    throw UltronStudioException::publicationMoved();
                }
                if (! StudioVersion::transition($version->id, [StudioVersionStatus::Canary->value], [
                    'status' => StudioVersionStatus::Published->value,
                    'status_changed_at' => $ahora,
                ])) {
                    throw $this->raceRefusal($version, 'promover', [StudioVersionStatus::Canary]);
                }

                if ($anterior !== null) {
                    $this->recordRetired($request, $access, $anterior, $fila, D::ACTION_PROMOTE);
                }
                $this->audit->record($request, $access->actor, 'version.promoted', StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
                    "Promovió «{$version->label}» a todas las conversaciones (entrada {$fila->seq})", [
                        'fromStatus' => StudioVersionStatus::Canary->value,
                        'toStatus' => StudioVersionStatus::Published->value,
                        'contentHash' => $version->content_hash,
                        'diff' => ['entry' => $fila->uuid, 'seq' => (int) $fila->seq, 'evidence' => $evidencia['record']],
                    ]);

                return $fila;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($previa = $this->replay($access, D::ACTION_PROMOTE, $uuid, $clientRequestId, $cuerpo)) {
                return ['entry' => $previa, 'replayed' => true];
            }

            throw $this->conflictRefusal($e, D::ACTION_PROMOTE);
        }

        return ['entry' => self::entryDto($fila, self::versionRef($version)), 'replayed' => false];
    }

    // ── Revertir (§A5.3) ────────────────────────────────────────────────────

    /**
     * POST versions/{uuid}/rollback. Sin llaves, sin cuatro ojos y sin ventana:
     * es el sentido seguro.
     *
     *  - La CANARY → ROLLED_BACK; la cadena declara `{canary: rest, rest: rest}`.
     *  - La PUBLISHED → ROLLED_BACK; vuelve la parte resto de la entrada anterior
     *    a su promoción (esa versión RETIRED → PUBLISHED, si es íntegra; si no,
     *    409 `restore_integrity_mismatch` y la salida es Legacy) o nada si no
     *    había. Con otra versión en canario se conserva su parte (Q-2).
     *
     * @return array{entry:array<string,mixed>,replayed:bool}
     */
    public function rollback(StudioAccess $access, Request $request, string $uuid, string $clientRequestId, string $reason): array
    {
        $access->require(StudioAccess::ROLLBACK);

        $cuerpo = ['reason' => $reason];
        if ($previa = $this->replay($access, D::ACTION_ROLLBACK, $uuid, $clientRequestId, $cuerpo)) {
            return ['entry' => $previa, 'replayed' => true];
        }

        // De aquí en adelante, volviendo a mirar el reintento ante cualquier rechazo (H-1).
        return $this->orReplayed(fn (): array => $this->rollbackGated($access, $request, $uuid, $clientRequestId, $reason, $cuerpo), $access, D::ACTION_ROLLBACK, $uuid, $clientRequestId, $cuerpo);
    }

    /**
     * Revertir, del estado de la versión a la transacción (§A5.3).
     *
     * @param  array<string,mixed>  $cuerpo
     * @return array{entry:array<string,mixed>,replayed:bool}
     */
    private function rollbackGated(StudioAccess $access, Request $request, string $uuid, string $clientRequestId, string $reason, array $cuerpo): array
    {
        $version = $this->versions->findByUuid($uuid);
        $estado = $version->statusEnum();
        if ($rechazo = self::rollbackStateRefusal($estado)) {
            throw $rechazo;
        }

        $origen = null;
        $parteRestaurada = null;
        $restaurada = null;
        if ($estado === StudioVersionStatus::Published) {
            [$origen, $parteRestaurada, $restaurada] = $this->restoreSource($access, $request, $version);
        }

        try {
            $fila = DB::transaction(function () use ($access, $request, $version, $estado, $clientRequestId, $reason, $origen, $parteRestaurada, $restaurada): StudioPublication {
                $ahora = now();
                $cabeza = $this->head();
                $partes = $this->verifiedParts($cabeza);
                try {
                    $nuevas = D::compose(D::ACTION_ROLLBACK, (string) $version->uuid, $estado->value, $partes, null, $parteRestaurada);
                } catch (InvalidArgumentException) {
                    throw $this->raceRefusal($version, 'revertir', [$estado]);
                }
                [$contrato, $nuevas] = $this->promptedForMove($nuevas);

                $fila = $this->insertEntry(D::ACTION_ROLLBACK, $version, $cabeza, $nuevas, $ahora, $access, $clientRequestId, self::notApplicableLabGate(), null, null, $reason, $origen?->id, $contrato);

                // Primero la que sale, después la que vuelve.
                if (! StudioVersion::transition($version->id, [$estado->value], [
                    'status' => StudioVersionStatus::RolledBack->value,
                    'status_changed_at' => $ahora,
                ])) {
                    throw $this->raceRefusal($version, 'revertir', [$estado]);
                }
                if ($restaurada !== null && ! StudioVersion::transition($restaurada->id, [StudioVersionStatus::Retired->value], [
                    'status' => StudioVersionStatus::Published->value,
                    'status_changed_at' => $ahora,
                ])) {
                    throw UltronStudioException::publicationMoved();
                }

                $this->audit->record($request, $access->actor, 'version.rolled_back', StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
                    "Revirtió «{$version->label}» (entrada {$fila->seq})", [
                        'fromStatus' => $estado->value,
                        'toStatus' => StudioVersionStatus::RolledBack->value,
                        'contentHash' => $version->content_hash,
                        'diff' => ['entry' => $fila->uuid, 'seq' => (int) $fila->seq, 'reason' => $reason],
                    ]);
                if ($restaurada !== null) {
                    $this->audit->record($request, $access->actor, 'version.restored', StudioAuditEvent::SUBJECT_VERSION, $restaurada->uuid,
                        "Volvió a publicar «{$restaurada->label}» al revertir la que la sustituyó (entrada {$fila->seq})", [
                            'fromStatus' => StudioVersionStatus::Retired->value,
                            'toStatus' => StudioVersionStatus::Published->value,
                            'contentHash' => $restaurada->content_hash,
                            'diff' => ['entry' => $fila->uuid, 'seq' => (int) $fila->seq],
                        ]);
                }

                return $fila;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($previa = $this->replay($access, D::ACTION_ROLLBACK, $uuid, $clientRequestId, $cuerpo)) {
                return ['entry' => $previa, 'replayed' => true];
            }

            throw $this->conflictRefusal($e, D::ACTION_ROLLBACK);
        }

        return ['entry' => self::entryDto($fila, self::versionRef($version)), 'replayed' => false];
    }

    // ── Volver a Legacy (§A5.4) ─────────────────────────────────────────────

    /**
     * POST publications/legacy: la cadena deja de declarar (`{null, null}`); la
     * CANARY → ROLLED_BACK y la PUBLISHED → RETIRED. Es el «parar de declarar»
     * del Studio; el «parar de leer» es la llave del motor en `off`. Sin llaves,
     * ni ventana: siempre. Una cabeza que no verifica no es Legacy: esto es,
     * justamente, la salida.
     *
     * @return array{entry:array<string,mixed>,replayed:bool}
     */
    public function legacy(StudioAccess $access, Request $request, string $clientRequestId, string $reason): array
    {
        $access->require(StudioAccess::ROLLBACK);

        $cuerpo = ['reason' => $reason];
        if ($previa = $this->replay($access, D::ACTION_LEGACY, null, $clientRequestId, $cuerpo)) {
            return ['entry' => $previa, 'replayed' => true];
        }

        // De aquí en adelante, volviendo a mirar el reintento ante cualquier rechazo (H-1).
        return $this->orReplayed(fn (): array => $this->legacyGated($access, $request, $clientRequestId, $reason, $cuerpo), $access, D::ACTION_LEGACY, null, $clientRequestId, $cuerpo);
    }

    /**
     * Volver a Legacy, de la cabeza a la transacción (§A5.4).
     *
     * @param  array<string,mixed>  $cuerpo
     * @return array{entry:array<string,mixed>,replayed:bool}
     */
    private function legacyGated(StudioAccess $access, Request $request, string $clientRequestId, string $reason, array $cuerpo): array
    {
        $this->assertDeclaring($this->head());

        try {
            $fila = DB::transaction(function () use ($access, $request, $clientRequestId, $reason): StudioPublication {
                $ahora = now();
                $cabeza = $this->head();
                // Otra vuelta a Legacy pudo confirmarse mientras tanto.
                $this->assertDeclaring($cabeza);

                $canario = StudioVersion::query()->where('status', StudioVersionStatus::Canary->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']);
                $publicada = StudioVersion::query()->where('status', StudioVersionStatus::Published->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']);
                $fila = $this->insertEntry(D::ACTION_LEGACY, null, $cabeza, D::compose(D::ACTION_LEGACY, null, null, null), $ahora, $access, $clientRequestId, self::notApplicableLabGate(), null, null, $reason, null);

                if ($canario !== null && ! StudioVersion::transition($canario->id, [StudioVersionStatus::Canary->value], [
                    'status' => StudioVersionStatus::RolledBack->value,
                    'status_changed_at' => $ahora,
                ])) {
                    throw UltronStudioException::publicationMoved();
                }
                if ($publicada !== null && ! StudioVersion::transition($publicada->id, [StudioVersionStatus::Published->value], [
                    'status' => StudioVersionStatus::Retired->value,
                    'status_changed_at' => $ahora,
                ])) {
                    throw UltronStudioException::publicationMoved();
                }

                if ($canario !== null) {
                    $this->audit->record($request, $access->actor, 'version.rolled_back', StudioAuditEvent::SUBJECT_VERSION, $canario->uuid,
                        "Revirtió «{$canario->label}» al volver a Legacy (entrada {$fila->seq})", [
                            'fromStatus' => StudioVersionStatus::Canary->value,
                            'toStatus' => StudioVersionStatus::RolledBack->value,
                            'contentHash' => $canario->content_hash,
                            'diff' => ['entry' => $fila->uuid, 'seq' => (int) $fila->seq, 'reason' => $reason],
                        ]);
                }
                if ($publicada !== null) {
                    $this->recordRetired($request, $access, $publicada, $fila, D::ACTION_LEGACY);
                }
                $this->audit->record($request, $access->actor, 'system.publication_legacy', StudioAuditEvent::SUBJECT_SYSTEM, $fila->uuid,
                    "Volvió a Legacy: la cadena no declara ninguna versión (entrada {$fila->seq})", [
                        'diff' => ['entry' => $fila->uuid, 'seq' => (int) $fila->seq, 'reason' => $reason],
                    ]);

                return $fila;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($previa = $this->replay($access, D::ACTION_LEGACY, null, $clientRequestId, $cuerpo)) {
                return ['entry' => $previa, 'replayed' => true];
            }

            throw $this->conflictRefusal($e, D::ACTION_LEGACY);
        }

        return ['entry' => self::entryDto($fila), 'replayed' => false];
    }

    // ── Lecturas ────────────────────────────────────────────────────────────

    /**
     * GET versions/{uuid}/publication-preview (`PublicationPreviewDto`): las
     * puertas de publicar sobre esta versión, para quien mira, sin escribir
     * nada. `previewHash` es la huella que publicar exige (Q-4).
     *
     * @return array<string,mixed>
     */
    public function preview(StudioAccess $access, StudioVersion $version): array
    {
        return self::previewFrom((string) $version->uuid, (string) $version->content_hash, $this->facts($access, $version));
    }

    /**
     * GET versions/{uuid}/canary-evidence (`CanaryEvidenceDto`, §A6): solo de
     * la versión en CANARY. No escribe nada.
     *
     * @return array<string,mixed>
     */
    public function evidence(StudioVersion $version): array
    {
        $estado = $version->statusEnum();
        if ($estado !== StudioVersionStatus::Canary) {
            throw UltronStudioException::invalidTransition($estado->value, $estado->label(), 'ver la evidencia del canario', [StudioVersionStatus::Canary->value]);
        }

        return $this->evidenceFor($version)['dto'];
    }

    /**
     * GET publications: la cadena, de la más reciente a la más antigua, cada
     * entrada con su `verified` ({@see historyEntryDto()}). El texto firmado se
     * lee acotado para verificarla, como la cabeza, pero no se devuelve: lo que
     * la identifica es su huella.
     */
    public static function page(int $perPage): LengthAwarePaginator
    {
        return self::withJson(StudioPublication::query())
            ->with('version:id,uuid,label')
            ->orderByDesc('seq')
            ->paginate($perPage)
            ->through(static fn (StudioPublication $p): array => self::historyEntryDto($p));
    }

    /**
     * Las entradas que nombran una versión (`publications` del detalle), de la
     * más reciente a la más antigua, cada una con su `verified`.
     *
     * @return list<array<string,mixed>>
     */
    public static function forVersion(StudioVersion $version): array
    {
        $ref = self::versionRef($version);

        return self::withJson(StudioPublication::query())
            ->where('version_id', $version->id)
            ->orderByDesc('seq')
            ->get()
            ->map(static fn (StudioPublication $p): array => self::historyEntryDto($p, $ref))
            ->values()
            ->all();
    }

    /**
     * El bloque `engine` de `GET status` (§A7). Si la publicación no se puede
     * leer, el bloque sale sin `publication` (el CRM lo tolera) y queda una
     * línea en el log, sin el mensaje.
     *
     * @return array{readsVersions:bool,activeVersion:string|null,message:string,publication?:array<string,mixed>}
     */
    public function engineBlock(): array
    {
        $alcance = StudioPublishing::engineScope();
        $bloque = [
            'readsVersions' => StudioPublishing::ENGINE_READS_VERSIONS,
            'activeVersion' => null,
            'message' => self::engineMessage($alcance, StudioEngineReadings::promptsKey()),
        ];

        try {
            $estado = $this->state();
            $bloque['activeVersion'] = $estado['rest']['versionUuid'] ?? null;
            $bloque['publication'] = $estado;
        } catch (Throwable $e) {
            // Tragado a propósito: `GET status` es la puerta del Studio y no
            // puede caerse por una lectura de la cadena. Sin la clave, el CRM no
            // enseña nada de la publicación (nunca un estado inventado).
            Log::warning('ultron_studio.publication_state_unavailable', ['error_class' => class_basename($e)]);
        }

        try {
            $bloque['prompts'] = $this->promptsTrace();
        } catch (Throwable $e) {
            // Tragado a propósito, por el mismo motivo: sin la clave, el CRM no
            // enseña la traza de los prompts (nunca una inventada). Al log, solo
            // la clase (el mensaje puede llevar SQL).
            Log::warning('ultron_studio.prompts_trace_unavailable', ['error_class' => class_basename($e)]);
        }

        return $bloque;
    }

    /**
     * El mensaje del bloque `engine`: el de siempre con la llave de prompts en
     * `off` (o con el motor sin leer la cadena) y, con ella encendida, el que
     * dice que publicar cambia lo que ULTRON dice en la canaria (Enmienda M-1).
     */
    public static function engineMessage(string $engineScope, string $promptsKey): string
    {
        return $promptsKey === StudioEngineReadings::PROMPTS_CANARY && isset(self::ENGINE_MESSAGES_PROMPTS[$engineScope])
            ? self::ENGINE_MESSAGES_PROMPTS[$engineScope]
            : self::ENGINE_MESSAGES[$engineScope];
    }

    /**
     * `status.engine.prompts` (Enmienda M-1, plan de motores §D): la llave de
     * prompts del motor, la base y el compilador de este código, la huella
     * Legacy, la oferta que haría `decide` AHORA a la conversación canaria
     * (`current`, la misma regla que `UltronPromptOffer`, sobre la cabeza que
     * verifica el Studio) y los últimos turnos de la canaria
     * (`lastTurns`, ≤ {@see PROMPT_TURNS}). Sin ids de conversación ni de
     * mensaje y sin textos. La cabeza solo se lee si la oferta llega a
     * necesitarla (con las dos llaves encendidas y canario configurado).
     * Propaga un fallo de lectura.
     *
     * @return array{key:string,base:string,compiler:int,legacyBundleHash:string,current:array{mode:string,version:?string,bundleHash:?string,reason:?string},lastTurns:list<array<string,mixed>>}
     */
    public function promptsTrace(): array
    {
        $llave = StudioEngineReadings::promptsKey();
        $alcance = StudioPublishing::engineScope();
        $canario = false;
        $cabeza = ['state' => 'none', 'reason' => null, 'contract' => null, 'part' => null];
        if ($llave === StudioEngineReadings::PROMPTS_CANARY && $alcance !== StudioPublishing::SCOPE_OFF) {
            $lectura = $this->engine->only(['governance.canaryOnly'])['params']['governance.canaryOnly'];
            $canario = ($lectura['available'] ?? false) === true && ($lectura['value'] ?? null) === true;
            if ($canario) {
                $cabeza = self::offerHead($this->head());
            }
        }

        return [
            'key' => $llave,
            'base' => StudioPromptBundle::base(),
            'compiler' => StudioPromptBundle::compilerVersion(),
            'legacyBundleHash' => StudioPromptBundle::legacyBundleHash(),
            'current' => StudioPromptBundle::offer($llave, $alcance, $canario, $cabeza),
            'lastTurns' => $this->canary->promptTurns(self::PROMPT_TURNS),
        ];
    }

    /**
     * La cabeza como la lee la oferta: sin entradas, que no verifica (con el
     * motivo del lector) o verificada, con la parte que rige en la canaria (la
     * canaria o, si es null, la del resto) y el contrato de la entrada.
     *
     * @return array{state:string,reason:?string,contract:?string,part:?array<string,mixed>}
     */
    private static function offerHead(?StudioPublication $cabeza): array
    {
        if ($cabeza === null) {
            return ['state' => 'none', 'reason' => null, 'contract' => null, 'part' => null];
        }
        $v = self::verification($cabeza);
        if ($v['parts'] === null) {
            return ['state' => 'invalid', 'reason' => $v['reason'], 'contract' => null, 'part' => null];
        }

        return ['state' => 'ok', 'reason' => null, 'contract' => $v['contract'], 'part' => $v['parts']['canary'] ?? $v['parts']['rest']];
    }

    /**
     * `PublicationStateDto` (§A7): la llave del motor, la cabeza (con su
     * verificación entera en `signatureOk`), qué versión declara cada parte, la
     * deriva de los cuatro `must_match` frente a las lecturas de ahora (Q-5:
     * una lectura caída cuenta como deriva, con `engine: null`) y el estado:
     * `off` con la llave apagada; `legacy` sin cabeza o con las dos partes
     * vacías; `fallback` con una cabeza que no verifica; si no, `applied`.
     * Propaga un fallo de lectura de la cadena.
     *
     * @return array{envScope:string,head:array<string,mixed>|null,canary:array{versionUuid:string,label:string}|null,rest:array{versionUuid:string,label:string}|null,drift:list<array<string,mixed>>,state:string}
     */
    public function state(): array
    {
        $alcance = StudioPublishing::engineScope();
        $cabeza = $this->head();
        if ($cabeza === null) {
            return self::stateDto($alcance, null, null, null, [], $alcance === StudioPublishing::SCOPE_OFF ? self::STATE_OFF : self::STATE_LEGACY);
        }

        $partes = self::verify($cabeza);
        $head = [
            'uuid' => (string) $cabeza->uuid,
            'seq' => (int) $cabeza->seq,
            'action' => (string) $cabeza->action,
            'at' => $cabeza->created_at?->toIso8601String(),
            'signatureOk' => $partes !== null,
        ];
        if ($partes === null) {
            return self::stateDto($alcance, $head, null, null, [], $alcance === StudioPublishing::SCOPE_OFF ? self::STATE_OFF : self::STATE_FALLBACK);
        }

        $vivas = array_values(array_filter([$partes['canary'], $partes['rest']], static fn (?array $p): bool => $p !== null));
        $etiquetas = $vivas === [] ? [] : StudioVersion::query()
            ->whereIn('uuid', array_values(array_unique(array_column($vivas, 'version'))))
            ->pluck('label', 'uuid')
            ->all();
        $parteDto = static fn (?array $p): ?array => $p === null ? null : [
            'versionUuid' => $p['version'],
            'label' => (string) ($etiquetas[$p['version']] ?? 'Versión sin etiqueta'),
        ];

        $deriva = [];
        if ($vivas !== []) {
            $lecturas = $this->engine->only(StudioEngineReadings::MUST_MATCH)['params'];
            $catalogo = StudioCatalog::toArray();
            foreach ($vivas as $parte) {
                foreach (D::drift($parte['declared'], $lecturas, $catalogo) as $d) {
                    $deriva[json_encode($d)] = $d;
                }
            }
        }

        $estado = match (true) {
            $alcance === StudioPublishing::SCOPE_OFF => self::STATE_OFF,
            $vivas === [] => self::STATE_LEGACY,
            default => self::STATE_APPLIED,
        };

        return self::stateDto($alcance, $head, $parteDto($partes['canary']), $parteDto($partes['rest']), array_values($deriva), $estado);
    }

    // ── Presentación (estática: la usa también la exportación de fixtures) ──

    /**
     * `PublicationEntryDto` (§A7). La versión, `{uuid, label}`, o null en
     * Legacy. `labGate` solo en las publicaciones (es la puerta con la que se
     * publicó); en las demás acciones no aplica.
     *
     * @param  array{uuid:string,label:string}|null  $version  si no se pasa, la relación cargada
     * @return array{uuid:string,seq:int,action:string,version:array{uuid:string,label:string}|null,effectiveHash:string,shortHash:string,labGate:array{mode:string}|null,actor:array{name:string,role:?string},reason:?string,at:?string}
     */
    public static function entryDto(StudioPublication $p, ?array $version = null): array
    {
        if ($version === null && $p->version_id !== null && $p->relationLoaded('version') && $p->version !== null) {
            $version = self::versionRef($p->version);
        }
        $modo = is_array($p->lab_gate) ? ($p->lab_gate['mode'] ?? null) : null;

        return [
            'uuid' => (string) $p->uuid,
            'seq' => (int) $p->seq,
            'action' => (string) $p->action,
            'version' => $p->version_id === null ? null : $version,
            'effectiveHash' => (string) $p->effective_hash,
            'shortHash' => substr((string) $p->effective_hash, 0, 8),
            'labGate' => $p->action === D::ACTION_PUBLISH && is_string($modo) ? ['mode' => $modo] : null,
            'actor' => ['name' => (string) $p->actor_name, 'role' => $p->actor_role],
            'reason' => $p->reason,
            'at' => $p->created_at?->toIso8601String(),
        ];
    }

    /**
     * Una entrada del HISTORIAL (`GET publications` y `publications` del
     * detalle de la versión): `PublicationEntryDto` más `verified` (B-2 de
     * `security` en el Hito 5, diferido por la Enmienda 2 §E2.5 al Hito 7).
     *
     * `verified` son las MISMAS cuatro comprobaciones que el lector del motor
     * hace sobre la cabeza y que el Studio enseña en `head.signatureOk`
     * ({@see verify()}): el contrato, la huella de los bytes, la firma con la
     * clave de este entorno y la decodificación estricta atada al `uuid` y al
     * `seq` de su fila (una entrada copiada de otra fila no verifica). Una fila
     * tocada a mano sin la clave sale `false`, y solo esa: las entradas no se
     * verifican unas con otras. Cubre lo firmado; las columnas que no entran en
     * la firma (`action`, `reason`, el actor, `version_id`, la fecha) solo las
     * protege la cadena de solo inserción.
     *
     * Exige la fila con el texto firmado ({@see withJson()}). Las respuestas de
     * las escrituras no lo llevan: la entrada que devuelven es la que se acaba
     * de firmar, o la de la primera vez.
     *
     * @param  array{uuid:string,label:string}|null  $version
     * @return array<string,mixed>
     */
    public static function historyEntryDto(StudioPublication $p, ?array $version = null): array
    {
        return self::entryDto($p, $version) + ['verified' => self::verify($p) !== null];
    }

    /**
     * @param  array<string,mixed>|null  $head
     * @param  array{versionUuid:string,label:string}|null  $canary
     * @param  array{versionUuid:string,label:string}|null  $rest
     * @param  list<array<string,mixed>>  $drift
     * @return array{envScope:string,head:array<string,mixed>|null,canary:array{versionUuid:string,label:string}|null,rest:array{versionUuid:string,label:string}|null,drift:list<array<string,mixed>>,state:string}
     */
    public static function stateDto(string $envScope, ?array $head, ?array $canary, ?array $rest, array $drift, string $state): array
    {
        return ['envScope' => $envScope, 'head' => $head, 'canary' => $canary, 'rest' => $rest, 'drift' => $drift, 'state' => $state];
    }

    /**
     * `PublicationPreviewDto` (§A7) a partir de las puertas calculadas
     * ({@see facts()}). `params`: una fila por parámetro CON clase, en el orden
     * del catálogo; `ok` solo decide en `must_match` (en `matches` y
     * `no_effect`, que no bloquean, es true). `blocking`: los códigos de cada
     * requisito que no se cumple, en el orden de las puertas; `publishable` ⇔
     * vacío. El reconocimiento y la propia `previewHash` no son requisitos:
     * dependen de lo que envíe quien publica.
     *
     * Enmienda M-1 (§B.4 y §D), al final y siempre presentes: `effect`
     * (`declaration` con la llave de prompts del motor en `off`: publicar solo
     * declara; `prompts`: publicar cambia los prompts de la canaria) y `prompts`
     * (null con `declaration`; si no, el paquete que se firmaría: base,
     * compilador, huella, la Legacy, la tabla de cambios con el texto exacto de
     * cada parche y la parte que se arrastra del resto). Los gobernados que se
     * apartan de la línea base salen en `params` con la clase `prompt`; un
     * paquete que no compila bloquea con `prompts_compile_failed`, y unas
     * frases de las reglas que no caben (de la versión o de la parte que se
     * arrastra), con `prompts_rule_phrases_invalid`.
     *
     * @param  array<string,mixed>  $h
     * @return array<string,mixed>
     */
    public static function previewFrom(string $versionUuid, string $contentHash, array $h): array
    {
        $clasificacion = $h['classification'];
        $configs = $h['configs'];
        $prompts = $h['prompts'] ?? null;
        $porParametro = [];
        foreach ($clasificacion['mustMatch'] as $m) {
            $porParametro[$m['param']] = ['class' => 'must_match', 'versionValue' => $m['versionValue'], 'engineValue' => $m['engineValue'], 'ok' => $m['ok']];
        }
        foreach (['matches' => 'matches', 'noEffect' => 'no_effect', 'prompt' => D::CLASS_PROMPT] as $lista => $clase) {
            foreach ($clasificacion[$lista] ?? [] as $id) {
                if (! str_starts_with($id, D::RULE_PREFIX)) {
                    $porParametro[$id] = ['class' => $clase];
                }
            }
        }

        $params = [];
        foreach (StudioCatalog::layerIds() as $capa) {
            foreach (StudioCatalog::bindings()[$capa] ?? [] as $clave => $mando) {
                $id = "{$capa}.{$clave}";
                if (! isset($porParametro[$id])) {
                    continue;
                }
                $fila = $porParametro[$id];
                $params[] = $fila['class'] === 'must_match'
                    ? ['layer' => $capa, 'key' => (string) $clave] + $fila
                    : [
                        'layer' => $capa,
                        'key' => (string) $clave,
                        'class' => $fila['class'],
                        'versionValue' => $configs[$capa][$clave] ?? null,
                        'engineValue' => $mando['declaredValue'] ?? null,
                        'ok' => true,
                    ];
            }
        }

        $estado = $h['status'];
        $lab = $h['labGate'];
        $ventana = $h['window'];
        $requisitos = [
            'status' => self::requirement($estado === StudioVersionStatus::Approved, "La versión está en «{$estado->label()}»: solo se publica una aprobada.") + ['current' => $estado->value],
            'fourEyes' => self::requirement($h['fourEyes'], 'Nadie publica lo que creó: tiene que publicarla otra persona.'),
            'integrity' => self::requirement($h['integrity'], 'La huella de la versión no coincide con su contenido.'),
            'revalidation' => self::requirement($h['revalidation'] === 'ok', $h['revalidation'] === 'changed'
                ? 'Sus avisos con el catálogo actual no son los que se reconocieron al aprobar.'
                : 'Con el catálogo actual tiene bloqueos.'),
            'labGate' => ['mode' => $lab['mode'], 'ok' => $lab['ok'], 'latest' => $lab['latest']] + self::labMessage($lab),
            'engineCanary' => self::requirement($h['engineCanary'], 'El motor no tiene configurada la conversación canaria.'),
            'brake' => self::requirement($h['brake'] === 'released', self::brakeMessage($h['brake'])),
            'canaryFree' => self::requirement(! $h['otherCanary'], 'Otra versión está en canario.'),
            'window' => self::requirement($ventana['open'], 'Fuera de la ventana de publicación ('.$ventana['label'].', hora de Bogotá).') + ['window' => $ventana['label']],
            'keys' => ['studio' => $h['keys']['studio'], 'engine' => $h['keys']['engine']],
        ];

        $bloqueos = [];
        $si = static function (bool $falla, string $codigo) use (&$bloqueos): void {
            if ($falla) {
                $bloqueos[] = $codigo;
            }
        };
        $si(! $h['canPublish'], 'forbidden');
        $si(! $h['keys']['studio'], StudioPublishing::CODE_DISABLED);
        $si($h['keys']['engine'] === StudioPublishing::SCOPE_OFF, StudioPublishing::CODE_ENGINE_NOT_READING);
        $si(! $h['fourEyes'], 'four_eyes_required');
        $si($estado !== StudioVersionStatus::Approved, 'invalid_transition');
        $si(! $h['integrity'], 'integrity_mismatch');
        $si($h['revalidation'] === 'blocked', 'revalidation_blocked');
        $si($h['revalidation'] === 'changed', 'revalidation_changed');
        $si(! $lab['ok'], 'lab_verdict_required');
        $si(($prompts['error'] ?? null) !== null, (string) ($prompts['error'] ?? StudioPromptBundle::CODE_COMPILE_FAILED));
        $si($clasificacion['mismatches'] !== [], D::CODE_ENGINE_VALUE_MISMATCH);
        $si($clasificacion['engineReadingUnavailable'] !== [], D::CODE_ENGINE_READING_UNAVAILABLE);
        $si(! $h['engineCanary'], 'engine_has_no_canary');
        $si($h['brake'] !== 'released', 'engine_braked');
        $si($h['otherCanary'], 'canary_in_progress');
        $si(! $ventana['open'], 'outside_publication_window');

        return [
            'versionUuid' => $versionUuid,
            'contentHash' => $contentHash,
            'previewHash' => $h['previewHash'],
            'publishable' => $bloqueos === [],
            'params' => $params,
            'noEffect' => D::requiredAcknowledgements($clasificacion),
            'requirements' => $requisitos,
            'blocking' => $bloqueos,
            'effect' => $prompts === null ? self::EFFECT_DECLARATION : self::EFFECT_PROMPTS,
            'prompts' => $prompts === null ? null : self::promptsDto($prompts),
        ];
    }

    /**
     * El bloque `prompts` de la vista previa (Enmienda M-1, §D): lo que se
     * firmaría en la parte nueva y lo que enseña el diálogo de publicar. Sin
     * los prompts enteros: la base es la del repositorio y lo único que cambia
     * son los parches, que salen con su texto exacto.
     *
     * Compilador 2, al final: `phrases` = `{list, rules, max, problems}`, las
     * frases de las reglas S-NNN que entran (en la tabla de cambios van como
     * el parámetro `rules.forbiddenPhrases`), las reglas que las aportan y, si
     * alguna no cabe, por qué ({@see StudioPromptBundle::phrases()}); con
     * `problems` no vacío, `error` es `prompts_rule_phrases_invalid`.
     *
     * `carried` (vuelta 2: B-1 de seguridad y hallazgo 1 del reviewer), la
     * parte del resto que la entrada arrastraría, siempre con `phrases`: las
     * frases que lleva a los prompts (lista ordenada; `[]` si no lleva). Si NO
     * compila por sus frases, identifica igual la versión, `bundleHash`,
     * `shortHash` y `baseline` van a null y lleva `problems` (la forma y los
     * motivos de `phrases.problems`); entonces `error` es
     * `prompts_rule_phrases_invalid` si la versión que se publica no tenía ya
     * otro error. `problems` solo sale en ese caso.
     *
     * @param  array<string,mixed>  $p  los hechos de {@see promptFacts()}
     * @return array{key:string,base:string,compiler:int,legacyBundleHash:string,bundleHash:?string,shortHash:?string,baseline:?bool,bytes:?int,changes:list<array<string,mixed>>,carried:?array<string,mixed>,error:?string,phrases:array{list:list<string>,rules:list<string>,max:int,problems:list<array<string,mixed>>}}
     */
    public static function promptsDto(array $p): array
    {
        $c = $p['compiled'];
        $arrastrada = $p['carried'];

        return [
            'key' => $p['key'],
            'base' => StudioPromptBundle::base(),
            'compiler' => StudioPromptBundle::compilerVersion(),
            'legacyBundleHash' => StudioPromptBundle::legacyBundleHash(),
            'bundleHash' => $c['prompts']['bundleHash'] ?? null,
            'shortHash' => isset($c['prompts']['bundleHash']) ? substr($c['prompts']['bundleHash'], 0, 8) : null,
            'baseline' => $c['baseline'] ?? null,
            'bytes' => $c['bytes'] ?? null,
            'changes' => $c['changes'] ?? [],
            'carried' => $arrastrada === null ? null : [
                'versionUuid' => $arrastrada['version'],
                'label' => $arrastrada['label'],
                'bundleHash' => $arrastrada['bundleHash'],
                'shortHash' => $arrastrada['bundleHash'] === null ? null : substr($arrastrada['bundleHash'], 0, 8),
                'baseline' => $arrastrada['baseline'],
                'phrases' => $arrastrada['phrases'] ?? [],
            ] + (($arrastrada['problems'] ?? []) !== [] ? ['problems' => $arrastrada['problems']] : []),
            'error' => $p['error'],
            'phrases' => $p['phrases'] ?? ['list' => [], 'rules' => [], 'max' => StudioPromptBundle::maxPhrases(), 'problems' => []],
        ];
    }

    /**
     * `CanaryEvidenceDto` (§A6 y §A7; fixture V-1…V-10): los recuentos ya
     * normalizados ({@see StudioCanaryEvidence::evidence()}) y la decisión.
     *
     * @param  array<string,mixed>  $record  lo que devuelve `StudioCanaryEvidence::evidence()`
     * @param  array{hours:int,passes:bool,failing:list<string>,evidenceHash:string}  $evaluation
     * @return array<string,mixed>
     */
    public static function evidenceDto(string $versionUuid, array $record, array $evaluation): array
    {
        return [
            'versionUuid' => $versionUuid,
            'since' => $record['since'],
            'thresholds' => $record['thresholds'],
            'hours' => $evaluation['hours'],
            'turns' => $record['turns'],
            'stamped' => $record['stamped'],
            'byState' => $record['byState'],
            'brakes' => $record['brakes'],
            'acta' => $record['acta'],
            'recoveries' => $record['recoveries'],
            'drift' => $record['drift'],
            'signatureOk' => $record['signatureOk'],
            'evidenceHash' => $evaluation['evidenceHash'],
            'passes' => $evaluation['passes'],
            'failing' => $evaluation['failing'],
        ];
    }

    // ── Las puertas, en su orden (puras: el fixture las fija caso a caso) ───

    /**
     * Los pasos 5–11 de publicar (§A5.1) sobre las puertas calculadas
     * ({@see facts()}), en el orden del texto y del fixture (P-1): estado →
     * integridad → revalidación (bloqueos → avisos cambiados) → laboratorio →
     * (Enmienda M-1, solo con la llave de prompts encendida: el paquete
     * compila) → `must_match` (distinto → sin lectura) → reconocimiento exacto →
     * `previewHash` → canario del motor → freno → otro canario → ventana. El
     * primer rechazo, o null. Las puertas 1–4 (persona, permiso, llaves,
     * reintento, versión y cuatro ojos) y la 12 (la transacción) van antes y
     * después, en {@see publish()}.
     *
     * Con el catálogo de hoy, el canario del motor (paso 10) no se alcanza por
     * HTTP: `governance.canaryOnly` es un `must_match` cuya lectura es
     * justamente «hay canario», y el catálogo exige que la versión lo declare
     * en true, así que sin canario el paso 8 responde antes. Queda como defensa
     * en profundidad, y su orden lo prueba el fixture sobre esta función.
     *
     * @param  array<string,mixed>  $h
     * @param  list<mixed>  $acknowledgedNoEffect
     */
    public static function publishRefusal(array $h, array $acknowledgedNoEffect, string $sentPreviewHash, string $versionUuid, string $contentHash): ?UltronStudioException
    {
        if ($h['status'] !== StudioVersionStatus::Approved) {
            return UltronStudioException::invalidTransition($h['status']->value, $h['status']->label(), 'publicar', [StudioVersionStatus::Approved->value]);
        }
        if (! $h['integrity']) {
            return UltronStudioException::integrityMismatch('La huella de la versión no coincide con su contenido. No se puede publicar: avisa a quien administra el sistema.');
        }
        if ($h['revalidation'] === 'blocked') {
            return UltronStudioException::revalidationBlocked($h['findings'], 'La versión ya no pasa la validación con el catálogo actual: tiene bloqueos y no se puede publicar.');
        }
        if ($h['revalidation'] === 'changed') {
            return UltronStudioException::revalidationChanged($h['warnings']);
        }
        if (! $h['labGate']['ok']) {
            return UltronStudioException::labVerdictRequired($h['labGate']['latest']);
        }
        // Enmienda M-1 (§B.4): nunca se firma una parte que no compila. Compilador
        // 2: unas frases de las reglas que no caben lo dicen con su propio código
        // y de qué parte son (la versión, o la que se arrastra del resto).
        if (($h['prompts']['error'] ?? null) === StudioPromptBundle::CODE_PHRASES_INVALID) {
            return self::phrasesRefusal($h['prompts']);
        }
        if (($h['prompts']['error'] ?? null) !== null) {
            return UltronStudioException::promptsCompileFailed();
        }

        $rechazo = D::refusal($h['classification'], $acknowledgedNoEffect, $sentPreviewHash, $h['previewHash']);
        if ($rechazo !== null) {
            return match ($rechazo['code']) {
                D::CODE_ENGINE_VALUE_MISMATCH => UltronStudioException::engineValueMismatch($rechazo['payload']['mismatches']),
                D::CODE_ENGINE_READING_UNAVAILABLE => UltronStudioException::engineReadingUnavailable($rechazo['payload']['engineReadingUnavailable']),
                D::CODE_NO_EFFECT_NOT_ACKNOWLEDGED => UltronStudioException::noEffectNotAcknowledged($rechazo['payload']['noEffect']),
                default => UltronStudioException::previewStale(self::previewFrom($versionUuid, $contentHash, $h)),
            };
        }

        if (! $h['engineCanary']) {
            return UltronStudioException::engineHasNoCanary();
        }
        if ($h['brake'] !== 'released') {
            return UltronStudioException::engineBraked(self::brakeMessage($h['brake']));
        }
        if ($h['otherCanary']) {
            return UltronStudioException::canaryInProgress();
        }
        if (! $h['window']['open']) {
            return UltronStudioException::outsidePublicationWindow($h['window']['label']);
        }

        return null;
    }

    /**
     * El 409 `prompts_rule_phrases_invalid` de unos hechos de prompts
     * ({@see promptFacts()}) cuyo error es ese: con los `problems` y el `part`
     * de la parte cuyas frases no caben (la versión que se publica o la que se
     * arrastra del resto).
     *
     * @param  array<string,mixed>  $p
     */
    private static function phrasesRefusal(array $p): UltronStudioException
    {
        return ($p['errorPart'] ?? null) === StudioPromptBundle::PHRASES_PART_CARRIED
            ? UltronStudioException::promptsRulePhrasesInvalid($p['carried']['problems'] ?? [], StudioPromptBundle::PHRASES_PART_CARRIED)
            : UltronStudioException::promptsRulePhrasesInvalid($p['phrases']['problems'] ?? [], StudioPromptBundle::PHRASES_PART_VERSION);
    }

    /**
     * Promover (§A5.2), después de los cuatro ojos y antes de la evidencia: la
     * llave del motor en `all` (fixture P-7), el estado CANARY y la integridad.
     */
    public static function promoteStateRefusal(string $engineScope, StudioVersionStatus $status, bool $integrity): ?UltronStudioException
    {
        if ($engineScope !== StudioPublishing::SCOPE_ALL) {
            return UltronStudioException::scopeNotAllowed();
        }
        if ($status !== StudioVersionStatus::Canary) {
            return UltronStudioException::invalidTransition($status->value, $status->label(), 'promover', [StudioVersionStatus::Canary->value]);
        }
        if (! $integrity) {
            return UltronStudioException::integrityMismatch('La huella de la versión no coincide con su contenido. No se puede promover: avisa a quien administra el sistema.');
        }

        return null;
    }

    /**
     * Promover (§A5.2), lo último antes de la transacción: la evidencia
     * suficiente → la misma huella que vio quien promueve → el freno suelto →
     * la ventana. Con el freno echado AHORA la evidencia ya falla en `brakes`,
     * así que sale `canary_evidence_insufficient` (fixture P-7); el freno se
     * vuelve a mirar después por si se echó entre las dos lecturas.
     *
     * @param  array{hours:int,passes:bool,failing:list<string>,evidenceHash:string}  $evaluation
     * @param  array<string,mixed>  $evidenceDto
     */
    public static function promoteEvidenceRefusal(array $evaluation, array $evidenceDto, string $sentEvidenceHash, string $brake, bool $windowOpen, ?string $windowLabel): ?UltronStudioException
    {
        $rechazo = StudioCanaryEvidence::refusal($evaluation, $sentEvidenceHash);
        if ($rechazo !== null) {
            return $rechazo['code'] === StudioCanaryEvidence::CODE_INSUFFICIENT
                ? UltronStudioException::canaryEvidenceInsufficient($evidenceDto)
                : UltronStudioException::evidenceChanged($evidenceDto);
        }
        if ($brake !== 'released') {
            return UltronStudioException::engineBraked(self::brakeMessage($brake));
        }
        if (! $windowOpen) {
            return UltronStudioException::outsidePublicationWindow($windowLabel);
        }

        return null;
    }

    /** Revertir (§A5.3): solo la versión en CANARY o la PUBLISHED (fixture P-8). */
    public static function rollbackStateRefusal(StudioVersionStatus $status): ?UltronStudioException
    {
        return $status->isActivePublication() ? null : UltronStudioException::notActivePublication($status->value);
    }

    // ── Las puertas, calculadas ─────────────────────────────────────────────

    /**
     * Todo lo que miran las puertas de publicar (§A5.1, pasos 5–11) sobre una
     * versión, para quien mira. Solo lee: la vista previa y publicar lo usan
     * igual, así que las dos dicen lo mismo.
     *
     * @return array<string,mixed>
     */
    private function facts(StudioAccess $access, StudioVersion $version): array
    {
        $contenido = is_array($version->content) ? $version->content : [];
        $integridad = StudioVersions::integrityOk($version);

        // Revalidación con el catálogo actual (paso 6): bloqueos, o, en una
        // versión que ya pasó por la revisión, avisos distintos de los que
        // reconoció la reseña al aprobar (o ninguna reseña aprobada con la que
        // comparar: una versión así no se firma).
        $hallazgos = [];
        $avisos = [];
        $revalidacion = 'ok';
        try {
            $hallazgos = StudioVersions::findings($contenido);
            $avisos = StudioGraphValidator::warnings($hallazgos);
            $resena = $version->review;
            $revisada = ! in_array($version->statusEnum(), [StudioVersionStatus::Draft, StudioVersionStatus::Validated, StudioVersionStatus::ReviewRequired, StudioVersionStatus::Rejected], true);
            if (! StudioGraphValidator::isPublishable($hallazgos)) {
                $revalidacion = 'blocked';
            } elseif ($revisada && ($resena === null
                || $resena->decision !== StudioVersionReview::DECISION_APPROVED
                || self::asSet($avisos) !== self::asSet($resena->acknowledged_warnings ?? []))) {
                $revalidacion = 'changed';
            }
        } catch (UltronStudioException|InvalidArgumentException) {
            // Un contenido que el catálogo actual no sabe leer no se publica,
            // y el 409 dice POR QUÉ (m-2 del reviewer del Hito 5): un hallazgo
            // bloqueante propio, no «tiene bloqueos» con la lista vacía.
            $revalidacion = 'blocked';
            $hallazgos = self::withUnreadableFinding($hallazgos);
        }

        $lecturas = $this->engine->only(StudioEngineReadings::MUST_MATCH, ['governance.brake']);
        $lab = $this->labGate($version);
        // Enmienda M-1: con la llave de prompts del motor encendida, los cuatro
        // gobernados tienen efecto (clase `prompt`) y se publica en `/2`.
        // Compilador 2: también las reglas S-NNN que aportan frases prohibidas.
        $conPrompts = StudioEngineReadings::promptsKey() === StudioEngineReadings::PROMPTS_CANARY;
        $frases = $conPrompts ? StudioPromptBundle::phrases($contenido) : null;

        $clasificable = true;
        try {
            $clasificacion = D::classify(
                $contenido['configs'] ?? [],
                is_array($contenido['rules'] ?? null) ? array_values($contenido['rules']) : [],
                StudioCatalog::baseline()['configs'],
                $lecturas['params'],
                StudioCatalog::toArray(),
                $conPrompts,
                $frases['rules'] ?? [],
            );
        } catch (InvalidArgumentException) {
            // La forma del contenido no casa con el catálogo actual (un
            // parámetro que ya no existe, una regla sin código): no se
            // clasifica ni se publica. Cae en la revalidación (paso 6), DESPUÉS
            // del estado y la integridad, y la vista previa sigue teniendo su
            // forma: los cuatro `must_match`, sin coincidir.
            $clasificable = false;
            $revalidacion = 'blocked';
            $hallazgos = self::withUnreadableFinding($hallazgos);
            $clasificacion = self::unclassified($lecturas['params'], $conPrompts);
        }

        $prompts = $frases !== null ? $this->promptFacts($contenido, $frases) : null;

        $huella = null;
        if ($clasificable) {
            try {
                $huella = D::previewHash((string) $version->uuid, (string) $version->content_hash, (string) $version->catalog_version, $clasificacion,
                    ['mode' => $lab['mode'], 'latest' => $lab['latest']], $prompts === null ? null : self::signedPrompts($prompts));
            } catch (InvalidArgumentException) {
                // Una huella o una versión del catálogo sin la forma del
                // contrato: solo con la fila alterada, y entonces la integridad
                // (paso 5) ya falla antes.
            }
        }
        // Una huella que ninguna vista previa publicable produce.
        $huella ??= StudioContentHasher::hash(['versionUuid' => (string) $version->uuid, 'unpublishable' => true]);

        $canario = $lecturas['params']['governance.canaryOnly'] ?? [];
        $ventana = StudioPublishing::window();

        return [
            'canPublish' => $access->can(StudioAccess::PUBLISH),
            'status' => $version->statusEnum(),
            'fourEyes' => ! $access->isCreator($version),
            'integrity' => $integridad,
            'revalidation' => $revalidacion,
            'findings' => $hallazgos,
            'warnings' => $avisos,
            'labGate' => $lab,
            'configs' => is_array($contenido['configs'] ?? null) ? $contenido['configs'] : [],
            'classification' => $clasificacion,
            'previewHash' => $huella,
            'engineCanary' => ($canario['available'] ?? false) === true && ($canario['value'] ?? null) === true,
            'brake' => self::brakeState($lecturas['extras']['governance.brake']),
            'otherCanary' => $this->otherCanaryExists($version),
            'window' => ['open' => StudioPublishing::windowOpen(now()), 'label' => $ventana['label'] ?? null],
            'keys' => ['studio' => StudioPublishing::flagEnabled(), 'engine' => StudioPublishing::engineScope()],
            'prompts' => $prompts,
        ];
    }

    /**
     * La puerta del laboratorio (§A5.1, paso 7; §A13). `latest`: el veredicto
     * MÁS RECIENTE sobre una ejecución de esta versión, con esta misma huella y
     * la versión de prompts vigente; si no hay ninguno con los prompts de hoy
     * pero sí con otros, `stale_prompts`; sin ninguno, null. Con `required`
     * pasa solo `approved`; con `waived`, siempre (y queda escrito).
     *
     * @return array{mode:string,ok:bool,latest:string|null,runUuid:string|null,verdictId:int|null,promptVersion:string|null}
     */
    private function labGate(StudioVersion $version): array
    {
        $modo = StudioPublishing::labGateMode();
        $ultimo = StudioLabVerdicts::latestFor($version, StudioLabPrompts::PROMPT_VERSION);

        return [
            'mode' => $modo,
            // `approved` es la decisión aprobatoria del laboratorio (StudioLabVerdict::DECISION_APPROVED).
            'ok' => $modo === D::LAB_WAIVED || $ultimo['latest'] === 'approved',
        ] + $ultimo;
    }

    /**
     * La evidencia del canario de una versión (§A6): desde la entrada que la
     * puso en CANARY, los recuentos, la deriva de sus cuatro valores frente a
     * las lecturas de ahora y la firma de la cabeza (`describe()` del lector;
     * si no se puede leer, cuenta como no verificada: falla cerrado).
     *
     * @return array{dto:array<string,mixed>,evaluation:array{hours:int,passes:bool,failing:list<string>,evidenceHash:string},record:array<string,mixed>}
     */
    private function evidenceFor(StudioVersion $version): array
    {
        $entrada = StudioPublication::query()
            ->where('version_id', $version->id)
            ->where('action', D::ACTION_PUBLISH)
            ->orderByDesc('seq')
            ->first(['id', 'created_at']);
        $desde = CarbonImmutable::instance($entrada?->created_at ?? $version->status_changed_at ?? now())->utc();

        $medidas = $this->canary->measure((string) $version->uuid, $desde);
        $lecturas = $this->engine->only(StudioEngineReadings::MUST_MATCH)['params'];
        $deriva = D::drift(D::declared($version->content['configs']), $lecturas, StudioCatalog::toArray());

        $measures = [
            'since' => $desde,
            'turns' => $medidas['turns'],
            'stamped' => $medidas['stamped'],
            'byState' => $medidas['byState'],
            'brakes' => $medidas['brakes'],
            'acta' => $medidas['acta'],
            'recoveries' => $medidas['recoveries'],
            'drift' => $deriva,
            'signatureOk' => $this->headSignatureOk(),
        ];
        $umbrales = StudioPublishing::thresholds();
        $evaluacion = StudioCanaryEvidence::evaluate($measures, $umbrales, now());
        $registro = StudioCanaryEvidence::evidence($measures, $umbrales);

        return [
            'dto' => self::evidenceDto((string) $version->uuid, $registro, $evaluacion),
            'evaluation' => $evaluacion,
            'record' => $registro,
        ];
    }

    /** ¿Verifica entera la cabeza, según el lector del motor? Un fallo de lectura es «no». */
    private function headSignatureOk(): bool
    {
        try {
            $cabeza = $this->engine->publication()['head'] ?? null;
        } catch (Throwable $e) {
            // Tragado a propósito: la evidencia falla cerrada en `signatureOk`.
            // Al log, solo la clase (el mensaje puede llevar SQL).
            Log::warning('ultron_studio.publication_describe_unavailable', ['error_class' => class_basename($e)]);

            return false;
        }

        return is_array($cabeza)
            && in_array($cabeza['contract'] ?? null, D::CONTRACTS, true)
            && ($cabeza['hashOk'] ?? null) === true
            && ($cabeza['signatureOk'] ?? null) === true
            && ($cabeza['parseOk'] ?? null) === true;
    }

    /**
     * De dónde vuelve la parte resto al revertir la PUBLISHED (§A5.3): la
     * entrada anterior a su (última) promoción. Tiene que verificar entera, y
     * la versión que vuelve tiene que existir, estar RETIRED, ser íntegra y
     * tener la huella que dice la parte. Si no, 409
     * `restore_integrity_mismatch` (auditado) y la salida es Legacy.
     *
     * @return array{0:StudioPublication,1:array<string,mixed>|null,2:StudioVersion|null}
     */
    private function restoreSource(StudioAccess $access, Request $request, StudioVersion $version): array
    {
        $promocion = StudioPublication::query()
            ->where('version_id', $version->id)
            ->where('action', D::ACTION_PROMOTE)
            ->orderByDesc('seq')
            ->first(['id', 'previous_publication_id']);
        $anterior = $promocion?->previous_publication_id !== null ? $this->entryWithJson((int) $promocion->previous_publication_id) : null;
        $partes = $anterior !== null ? self::verify($anterior) : null;
        if ($partes === null) {
            $this->refuseRestore($access, $request, $version);
        }

        $parte = $partes['rest'];
        if ($parte === null) {
            return [$anterior, null, null];
        }

        $vuelve = StudioVersion::query()->where('uuid', $parte['version'])->first();
        if ($vuelve === null
            || $vuelve->statusEnum() !== StudioVersionStatus::Retired
            || ! StudioVersions::integrityOk($vuelve)
            || ! hash_equals((string) $vuelve->content_hash, (string) $parte['contentHash'])) {
            $this->refuseRestore($access, $request, $version);
        }

        return [$anterior, $parte, $vuelve];
    }

    private function refuseRestore(StudioAccess $access, Request $request, StudioVersion $version): never
    {
        $this->recordRefused($access, $request, $version, D::ACTION_ROLLBACK, 'restore_integrity_mismatch');

        throw UltronStudioException::restoreIntegrityMismatch();
    }

    // ── La cadena ───────────────────────────────────────────────────────────

    /**
     * La cabeza de la cadena: la fila con `max(seq)` (índice único), con el
     * texto firmado acotado como lo lee el motor (una fila enorme escrita a
     * mano no agota la memoria; una truncada no casa con su huella).
     */
    private function head(): ?StudioPublication
    {
        return self::withJson(StudioPublication::query())->orderByDesc('seq')->first();
    }

    private function entryWithJson(int $id): ?StudioPublication
    {
        return self::withJson(StudioPublication::query())->whereKey($id)->first();
    }

    /**
     * Las columnas de {@see COLUMNS} y el texto firmado acotado como lo lee el
     * motor. Estática: también la usa el historial ({@see page()},
     * {@see forVersion()}), que no construye el servicio.
     *
     * @param  Builder<StudioPublication>  $consulta
     * @return Builder<StudioPublication>
     */
    private static function withJson(Builder $consulta): Builder
    {
        return $consulta->select(self::COLUMNS)->selectRaw('substr(effective_json, 1, '.(D::MAX_EFFECTIVE_JSON + 1).') as effective_json');
    }

    /**
     * Las partes de la cabeza, verificadas, para componer encima; null sin
     * cabeza. Una cabeza que no verifica no se copia (409).
     *
     * @return array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}|null
     */
    private function verifiedParts(?StudioPublication $cabeza): ?array
    {
        if ($cabeza === null) {
            return null;
        }

        return self::verify($cabeza) ?? throw UltronStudioException::publicationHeadInvalid();
    }

    /**
     * Las cuatro comprobaciones del lector (§A4.3, paso 4) sobre una fila: el
     * contrato, la huella, la firma (la del motor, sobre los bytes guardados) y
     * la decodificación estricta atada a su `uuid` y su `seq`. Las partes, o
     * null si algo no verifica.
     *
     * @return array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}|null
     */
    private static function verify(StudioPublication $fila): ?array
    {
        return self::verification($fila)['parts'];
    }

    /**
     * {@see verify()} con el motivo de la primera comprobación que falla, en el
     * orden y con los nombres del lector del motor (`contract_unknown`,
     * `hash_mismatch`, `signature_invalid`, `malformed`), y el contrato de la
     * entrada. Enmienda M-1: lee `/1` y `/2`, y el contrato del texto firmado
     * tiene que ser el de la columna.
     *
     * @return array{reason:?string,contract:?string,parts:array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}|null}
     */
    private static function verification(StudioPublication $fila): array
    {
        $no = static fn (string $motivo): array => ['reason' => $motivo, 'contract' => null, 'parts' => null];
        $json = $fila->effective_json;
        $contrato = $fila->engine_contract;
        if (! in_array($contrato, D::CONTRACTS, true)) {
            return $no('contract_unknown');
        }
        if (! is_string($json) || ! hash_equals(D::effectiveHash($json), (string) $fila->effective_hash)) {
            return $no('hash_mismatch');
        }
        if (! hash_equals(UltronPublishedConfig::signatureFor($json), (string) $fila->signature)) {
            return $no('signature_invalid');
        }

        try {
            $entrada = D::entry($json, (string) $fila->uuid, (int) $fila->seq);
        } catch (InvalidArgumentException) {
            return $no('malformed');
        }
        if ($entrada['contract'] !== $contrato) {
            return $no('malformed');
        }

        return ['reason' => null, 'contract' => $contrato, 'parts' => ['canary' => $entrada['canary'], 'rest' => $entrada['rest']]];
    }

    // ── Los prompts de la entrada (Enmienda M-1) ────────────────────────────

    /**
     * Lo que miran la vista previa y publicar con la llave de prompts
     * encendida: el paquete de la versión, el de la parte del resto que la
     * entrada arrastraría (la de la cabeza de ahora, si la hay) y si algo no
     * compila. Solo lee.
     *
     * Compilador 2: `phrases` son las frases de las reglas S-NNN de la versión
     * ({@see StudioPromptBundle::phrases()}). Si alguna no cabe, no se compila
     * nada y el error es `prompts_rule_phrases_invalid` (con el detalle en
     * `phrases.problems`); nunca se compila una lista recortada.
     *
     * La parte que se arrastra (vuelta 2): con sus frases (`carried.phrases`);
     * si son las suyas las que no caben, `carried` identifica la versión con
     * la huella a null y sus `problems`, y, si la versión que se publica no
     * tenía ya otro error, el error es `prompts_rule_phrases_invalid` con
     * `errorPart` = `carried` (`version` cuando son las de la versión).
     *
     * @param  array<string,mixed>  $contenido
     * @param  array{list:list<string>,rules:list<string>,max:int,problems:list<array<string,mixed>>}  $frases
     * @return array{key:string,compiled:array<string,mixed>|null,carried:array{version:string,label:string,bundleHash:?string,baseline:?bool,phrases:list<string>,problems:list<array<string,mixed>>}|null,phrases:array<string,mixed>,error:string|null,errorPart:string|null}
     */
    private function promptFacts(array $contenido, array $frases): array
    {
        $error = null;
        $parteDelError = null;
        $compilado = null;
        if ($frases['problems'] !== []) {
            // Solo los códigos cerrados: las frases son texto del personal.
            Log::warning('ultron_studio.prompts_compile_failed', ['part' => 'new', 'reason' => 'invalid_phrases', 'problems' => array_values(array_unique(array_column($frases['problems'], 'reason')))]);
            $error = StudioPromptBundle::CODE_PHRASES_INVALID;
            $parteDelError = StudioPromptBundle::PHRASES_PART_VERSION;
        } else {
            try {
                $compilado = StudioPromptBundle::compile(StudioPromptBundle::governedFrom($contenido, $frases['list']));
            } catch (InvalidArgumentException $e) {
                Log::warning('ultron_studio.prompts_compile_failed', ['part' => 'new', 'reason' => StudioPromptBundle::failureReason($e)]);
                $error = StudioPromptBundle::CODE_COMPILE_FAILED;
            }
        }

        $arrastrada = null;
        $cabeza = $this->head();
        $resto = $cabeza === null ? null : (self::verify($cabeza)['rest'] ?? null);
        if ($resto !== null) {
            $con = $this->promptedPart($resto);
            if ($con === null) {
                $error ??= StudioPromptBundle::CODE_COMPILE_FAILED;
            } elseif ($con['part'] === null) {
                // Sus frases no caben: se dice cuáles y de qué versión, y no se
                // compila ni se arrastra nada a medias.
                if ($error === null) {
                    $error = StudioPromptBundle::CODE_PHRASES_INVALID;
                    $parteDelError = StudioPromptBundle::PHRASES_PART_CARRIED;
                }
                $arrastrada = [
                    'version' => (string) $resto['version'],
                    'label' => $con['label'],
                    'bundleHash' => null,
                    'baseline' => null,
                    'phrases' => $con['phrases'],
                    'problems' => $con['problems'],
                ];
            } else {
                $arrastrada = [
                    'version' => $con['part']['version'],
                    'label' => $con['label'],
                    'bundleHash' => $con['part']['prompts']['bundleHash'],
                    'baseline' => hash_equals(StudioPromptBundle::legacyBundleHash(), $con['part']['prompts']['bundleHash']),
                    'phrases' => $con['phrases'],
                    'problems' => [],
                ];
            }
        }

        return ['key' => StudioEngineReadings::PROMPTS_CANARY, 'compiled' => $compilado, 'carried' => $arrastrada, 'phrases' => $frases, 'error' => $error, 'errorPart' => $parteDelError];
    }

    /**
     * Lo que de los prompts entra en `previewHash` (§B.4, E11): lo que se
     * firmaría en la parte nueva y la parte que se arrastra.
     *
     * @param  array<string,mixed>  $p  {@see promptFacts()}
     * @return array{base:string,bundleHash:?string,carried:array{bundleHash:string,version:string}|null,compiler:int}
     */
    private static function signedPrompts(array $p): array
    {
        return [
            'base' => StudioPromptBundle::base(),
            'bundleHash' => $p['compiled']['prompts']['bundleHash'] ?? null,
            'carried' => $p['carried'] === null ? null : ['bundleHash' => $p['carried']['bundleHash'], 'version' => $p['carried']['version']],
            'compiler' => StudioPromptBundle::compilerVersion(),
        ];
    }

    /**
     * Una parte de la cabeza en `/2`, con la etiqueta de su versión y las
     * frases que lleva a los prompts: tal cual si ya lo es (la firmó una
     * publicación con su vista previa; las frases, las de su `governed`, `[]`
     * si se firmó con el compilador 1); si es `/1`, con el contenido ENTERO de
     * su versión (los valores gobernados y las frases de sus reglas) y el
     * paquete que compilan, solo si la versión existe, es íntegra y es la de
     * la parte.
     *
     * Si las frases de sus reglas no caben, `part` es null y `problems` dice
     * cuáles ({@see StudioPromptBundle::phrases()}): no se compila una lista
     * recortada ni la parte sin sus frases. Null si no se puede por otra causa
     * (queda en el log, con el motivo y sin textos).
     *
     * @param  array<string,mixed>  $parte
     * @return array{part:array<string,mixed>|null,label:string,phrases:list<string>,problems:list<array<string,mixed>>}|null
     */
    private function promptedPart(array $parte): ?array
    {
        $version = StudioVersion::query()->where('uuid', $parte['version'])->first(['id', 'uuid', 'label', 'content', 'content_hash']);
        $etiqueta = (string) ($version?->label ?? 'Versión sin etiqueta');
        if (D::isPartV2($parte)) {
            $firmadas = $parte['governed'][StudioPromptBundle::phrasesParameter()] ?? [];

            return ['part' => $parte, 'label' => $etiqueta, 'phrases' => is_array($firmadas) ? array_values(array_filter($firmadas, 'is_string')) : [], 'problems' => []];
        }

        if ($version === null || ! StudioVersions::integrityOk($version) || ! hash_equals((string) $version->content_hash, (string) $parte['contentHash'])) {
            Log::warning('ultron_studio.prompts_compile_failed', ['part' => 'carried', 'reason' => 'version_unreadable']);

            return null;
        }
        $contenido = is_array($version->content) ? $version->content : [];
        $frases = StudioPromptBundle::phrases($contenido);
        if ($frases['problems'] !== []) {
            // Solo los códigos cerrados: las frases son texto del personal.
            Log::warning('ultron_studio.prompts_compile_failed', ['part' => 'carried', 'reason' => 'invalid_phrases', 'problems' => array_values(array_unique(array_column($frases['problems'], 'reason')))]);

            return ['part' => null, 'label' => $etiqueta, 'phrases' => $frases['list'], 'problems' => $frases['problems']];
        }
        try {
            $paquete = StudioPromptBundle::compile(StudioPromptBundle::governedFrom($contenido, $frases['list']));
        } catch (InvalidArgumentException $e) {
            Log::warning('ultron_studio.prompts_compile_failed', ['part' => 'carried', 'reason' => StudioPromptBundle::failureReason($e)]);

            return null;
        }

        return ['part' => D::partV2($parte, $paquete['governed'], $paquete['prompts']), 'label' => $etiqueta, 'phrases' => $frases['list'], 'problems' => []];
    }

    /**
     * Las partes de la entrada nueva de PUBLICAR, en su contrato. Sin
     * `$prompts` (la llave en `off` al calcular las puertas): `/1`. Con ellos:
     * `/2`, la parte nueva con el paquete de la vista previa y la del resto
     * como la enseñó la vista previa (`carried`); si la cabeza se movió entre
     * las puertas y la transacción y la parte del resto ya no es esa, 409
     * `publication_moved`; si unas frases no caben, 409
     * `prompts_rule_phrases_invalid` con su `part`; si otra cosa no compila,
     * 409 `prompts_compile_failed`.
     *
     * @param  array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}  $nuevas
     * @param  array<string,mixed>|null  $prompts  {@see promptFacts()}
     * @return array{0:string,1:array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}}
     */
    private function promptedForPublish(array $nuevas, ?array $prompts): array
    {
        if ($prompts === null) {
            return [D::CONTRACT, self::asV1($nuevas)];
        }
        if ($prompts['error'] === StudioPromptBundle::CODE_PHRASES_INVALID) {
            throw self::phrasesRefusal($prompts);
        }
        if ($prompts['error'] !== null || $prompts['compiled'] === null || $nuevas['canary'] === null) {
            throw UltronStudioException::promptsCompileFailed();
        }

        $canario = D::partV2($nuevas['canary'], $prompts['compiled']['governed'], $prompts['compiled']['prompts']);
        $resto = null;
        if ($nuevas['rest'] !== null) {
            $con = $this->promptedPart($nuevas['rest']);
            if ($con === null) {
                throw UltronStudioException::promptsCompileFailed();
            }
            if ($con['part'] === null) {
                // La cabeza se movió tras las puertas y la parte del resto de
                // ahora trae frases que no caben: tampoco se firma.
                throw UltronStudioException::promptsRulePhrasesInvalid($con['problems'], StudioPromptBundle::PHRASES_PART_CARRIED);
            }
            $resto = $con['part'];
        }

        $vista = $prompts['carried'];
        $ahora = $resto === null ? null : ['version' => $resto['version'], 'bundleHash' => $resto['prompts']['bundleHash']];
        if (($vista === null) !== ($ahora === null)
            || ($vista !== null && ($vista['version'] !== $ahora['version'] || ! hash_equals($vista['bundleHash'], $ahora['bundleHash'])))) {
            throw UltronStudioException::publicationMoved();
        }

        return [D::CONTRACT_V2, ['canary' => $canario, 'rest' => $resto]];
    }

    /**
     * Las partes de la entrada nueva de PROMOVER o REVERTIR, en su contrato:
     * `/2` solo con la llave de prompts encendida y si todas las partes no
     * nulas ya eran `/2` (las firmó una publicación con su vista previa); si
     * no, `/1`. Mover partes nunca da efecto a un paquete que nadie vio: en el
     * peor caso, la canaria vuelve a los prompts Legacy (`declaration_v1`).
     *
     * @param  array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}  $nuevas
     * @return array{0:string,1:array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}}
     */
    private function promptedForMove(array $nuevas): array
    {
        $vivas = array_values(array_filter([$nuevas['canary'], $nuevas['rest']], static fn (?array $p): bool => $p !== null));
        if ($vivas === [] || StudioEngineReadings::promptsKey() !== StudioEngineReadings::PROMPTS_CANARY) {
            return [D::CONTRACT, self::asV1($nuevas)];
        }
        foreach ($vivas as $parte) {
            if (! D::isPartV2($parte)) {
                // No es un error: es la regla (§B.4 y la nota de la clase).
                Log::notice('ultron_studio.publication_written_v1', ['reason' => 'part_without_prompts']);

                return [D::CONTRACT, self::asV1($nuevas)];
            }
        }

        return [D::CONTRACT_V2, $nuevas];
    }

    /**
     * Las partes en `/1` (sin `governed` ni `prompts`).
     *
     * @param  array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}  $partes
     * @return array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}
     */
    private static function asV1(array $partes): array
    {
        return [
            'canary' => $partes['canary'] === null ? null : D::partV1($partes['canary']),
            'rest' => $partes['rest'] === null ? null : D::partV1($partes['rest']),
        ];
    }

    /** Legacy solo si hay algo que dejar de declarar: sin cabeza, o con las dos partes vacías y verificadas, ya lo está. */
    private function assertDeclaring(?StudioPublication $cabeza): void
    {
        if ($cabeza === null) {
            throw UltronStudioException::alreadyLegacy();
        }
        $partes = self::verify($cabeza);
        if ($partes !== null && $partes['canary'] === null && $partes['rest'] === null) {
            throw UltronStudioException::alreadyLegacy();
        }
    }

    /**
     * Firma e inserta la entrada siguiente a `$cabeza` (`seq` = cabeza + 1).
     * La firma es la del motor, sobre los bytes exactos del texto. `$contract`
     * es el de la entrada (Enmienda M-1): `/1` salvo que quien llama haya
     * pasado todas las partes a `/2`.
     *
     * @param  array{canary:array<string,mixed>|null,rest:array<string,mixed>|null}  $partes
     * @param  array<string,mixed>  $labGate
     * @param  array<string,mixed>|null  $acknowledged
     * @param  array<string,mixed>|null  $evidence
     */
    private function insertEntry(
        string $action,
        ?StudioVersion $version,
        ?StudioPublication $cabeza,
        array $partes,
        DateTimeInterface $ahora,
        StudioAccess $access,
        string $clientRequestId,
        array $labGate,
        ?array $acknowledged,
        ?array $evidence,
        ?string $reason,
        ?int $restores,
        string $contract = D::CONTRACT,
    ): StudioPublication {
        $entrada = strtolower((string) Str::uuid());
        $seq = ($cabeza !== null ? (int) $cabeza->seq : 0) + 1;
        $declaracion = D::declaration($entrada, $seq, $ahora, $partes['canary'], $partes['rest'], UltronPublishedConfig::signatureFor(...), $contract);
        $yo = $access->snapshot();

        return StudioPublication::query()->forceCreate([
            'uuid' => $entrada,
            'seq' => $seq,
            'client_request_id' => $clientRequestId,
            'action' => $action,
            'version_id' => $version?->id,
            'restores_publication_id' => $restores,
            'previous_publication_id' => $cabeza?->id,
            'engine_contract' => $declaracion['contract'],
            'effective_json' => $declaracion['effectiveJson'],
            'effective_hash' => $declaracion['effectiveHash'],
            'signature' => $declaracion['signature'],
            'catalog_version' => StudioCatalog::VERSION,
            'lab_gate' => $labGate,
            'acknowledged' => $acknowledged,
            'evidence' => $evidence,
            'reason' => $reason,
            'actor_admin_id' => $yo['id'],
            'actor_name' => $yo['name'],
            'actor_role' => $yo['role'],
            'created_at' => $ahora,
        ]);
    }

    // ── Reintentos, carreras y rechazos auditados ───────────────────────────

    /**
     * La misma petición repetida devuelve la entrada de la primera vez. La
     * misma llave de otra persona, para otra acción u otra versión, o con otro
     * cuerpo, es 409 `client_request_reused`.
     *
     * @param  array<string,mixed>  $cuerpo  lo que identifica el cuerpo de esta petición
     * @return array<string,mixed>|null la entrada (PublicationEntryDto) de la primera vez
     */
    private function replay(StudioAccess $access, string $action, ?string $versionUuid, string $clientRequestId, array $cuerpo): ?array
    {
        $previa = StudioPublication::query()
            ->select(self::COLUMNS)
            ->with('version:id,uuid,label')
            ->where('client_request_id', strtolower($clientRequestId))
            ->first();
        if ($previa === null) {
            return null;
        }

        $mismaVersion = $action === D::ACTION_LEGACY
            ? $previa->version_id === null
            : $previa->version !== null && strtolower((string) $previa->version->uuid) === strtolower((string) $versionUuid);
        if ((int) $previa->actor_admin_id !== $access->id() || $previa->action !== $action || ! $mismaVersion || self::bodyOf($previa) !== $cuerpo) {
            throw UltronStudioException::clientRequestReused();
        }

        return self::entryDto($previa);
    }

    /**
     * H-1 (carreras con dos sesiones, 25-10): la MISMA petición repetida (misma
     * persona, mismo `clientRequestId`, mismo cuerpo) puede pasar el paso 3 sin
     * ver nada y tropezar después con el estado que su gemela acaba de dejar
     * (`invalid_transition`, `not_active_publication`, `already_legacy`,
     * `canary_in_progress`, `publication_moved`…). Antes de rechazar se vuelve a
     * mirar el reintento FUERA de la transacción, igual que tras el 23505: si ya
     * hay entrada, es 200 `replayed` (§A5.1 paso 3). Una llave reutilizada con
     * otro cuerpo o por otra persona sigue siendo `client_request_reused`.
     *
     * @param  callable():array{entry:array<string,mixed>,replayed:bool}  $accion
     * @param  array<string,mixed>  $cuerpo
     * @return array{entry:array<string,mixed>,replayed:bool}
     */
    private function orReplayed(callable $accion, StudioAccess $access, string $action, ?string $versionUuid, string $clientRequestId, array $cuerpo): array
    {
        try {
            return $accion();
        } catch (UltronStudioException $e) {
            if ($previa = $this->replay($access, $action, $versionUuid, $clientRequestId, $cuerpo)) {
                return ['entry' => $previa, 'replayed' => true];
            }

            throw $e;
        }
    }

    /**
     * Lo que identifica el cuerpo de la petición que creó una entrada, con la
     * misma forma que lo calcula cada acción.
     *
     * @return array<string,mixed>
     */
    private static function bodyOf(StudioPublication $p): array
    {
        return match ($p->action) {
            D::ACTION_PUBLISH => [
                'noEffect' => self::asSet(is_array($p->acknowledged['noEffect'] ?? null) ? $p->acknowledged['noEffect'] : []),
                'previewHash' => $p->acknowledged['previewHash'] ?? null,
                'note' => $p->reason,
            ],
            D::ACTION_PROMOTE => [
                'evidenceHash' => is_array($p->evidence) ? StudioContentHasher::hash($p->evidence) : null,
                'note' => $p->reason,
            ],
            default => ['reason' => $p->reason],
        };
    }

    /**
     * Un UNIQUE paró la inserción y no era el reintento: la carrera por la
     * cabeza (`seq`) o por el estado (`usv_one_canary` / `usv_one_published`).
     * Se decide FUERA de la transacción. Un UNIQUE que no se reconoce no se
     * disfraza: sube tal cual.
     */
    private function conflictRefusal(UniqueConstraintViolationException $e, string $action): UltronStudioException
    {
        $mensaje = $e->getMessage();
        // PostgreSQL nombra el índice; SQLite, la columna (los dos parciales
        // son `ultron_studio_versions.status`: lo distingue la acción).
        $estado = str_contains($mensaje, 'ultron_studio_versions.status');
        if (str_contains($mensaje, 'usv_one_canary') || ($estado && $action === D::ACTION_PUBLISH)) {
            return UltronStudioException::canaryInProgress();
        }
        if (str_contains($mensaje, 'ultron_studio_publications_seq_unique')
            || str_contains($mensaje, 'ultron_studio_publications.seq')
            || str_contains($mensaje, 'usv_one_published')
            || $estado) {
            return UltronStudioException::publicationMoved();
        }

        throw $e;
    }

    /**
     * La versión ya no está donde la dejaron las puertas: 409
     * `invalid_transition` con el estado de AHORA; si sigue igual, lo que se
     * movió fue la cadena (`publication_moved`).
     *
     * @param  list<StudioVersionStatus>  $desde
     */
    private function raceRefusal(StudioVersion $version, string $verbo, array $desde): UltronStudioException
    {
        $ahora = StudioVersionStatus::from((string) StudioVersion::query()->whereKey($version->id)->value('status'));
        if (! in_array($ahora, $desde, true)) {
            return UltronStudioException::invalidTransition(
                $ahora->value,
                $ahora->label(),
                $verbo,
                array_map(static fn (StudioVersionStatus $s): string => $s->value, $desde),
            );
        }

        return UltronStudioException::publicationMoved();
    }

    private function otherCanaryExists(StudioVersion $version): bool
    {
        return StudioVersion::query()
            ->where('status', StudioVersionStatus::Canary->value)
            ->whereKeyNot($version->id)
            ->exists();
    }

    /**
     * Las dos llaves de publicar o promover (paso 2). El rechazo queda en el
     * libro (deduplicado), como en F, si la versión existe: con un uuid
     * inventado no se escribe nada (un libro que no se puede vaciar no admite
     * una fila por cada uuid que alguien se invente).
     */
    private function assertKeys(StudioAccess $access, Request $request, string $uuid, string $action): void
    {
        $rechazo = StudioPublishing::keyRefusal($action);
        if ($rechazo === null) {
            return;
        }

        $version = StudioVersion::query()->where('uuid', strtolower($uuid))->first(['id', 'uuid', 'label', 'status', 'content_hash']);
        if ($version !== null) {
            $this->recordRefused($access, $request, $version, $action, $rechazo['code']);
        }

        throw UltronStudioException::publishingRefused($rechazo['code'], $rechazo['message']);
    }

    /** Cuatro ojos al publicar y al promover, por IDENTIDAD: el intento queda en el libro. */
    private function assertFourEyes(StudioAccess $access, Request $request, StudioVersion $version, string $action): void
    {
        if (! $access->isCreator($version)) {
            return;
        }

        $this->audit->recordDenied($request, $access->actor, 'version.four_eyes_denied', StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
            ($action === D::ACTION_PROMOTE ? 'Intentó promover' : 'Intentó publicar')." su propia versión «{$version->label}»", [
                'fromStatus' => $version->status,
                'contentHash' => $version->content_hash,
                'diff' => ['action' => $action],
            ]);

        throw UltronStudioException::publicationFourEyesRequired($action);
    }

    /** `version.{publish|promote|rollback}_refused` `{code}`, deduplicado (§A8). */
    private function recordRefused(StudioAccess $access, Request $request, StudioVersion $version, string $action, string $code): void
    {
        $verbo = match ($action) {
            D::ACTION_PROMOTE => 'Pidió promover',
            D::ACTION_ROLLBACK => 'Pidió revertir',
            default => 'Pidió publicar',
        };

        $this->audit->recordDenied($request, $access->actor, "version.{$action}_refused", StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
            "{$verbo} «{$version->label}»: rechazado ({$code})", [
                'fromStatus' => $version->status,
                'contentHash' => $version->content_hash,
                'diff' => ['code' => $code],
            ]);
    }

    private function recordRetired(Request $request, StudioAccess $access, StudioVersion $version, StudioPublication $fila, string $by): void
    {
        $this->audit->record($request, $access->actor, 'version.retired', StudioAuditEvent::SUBJECT_VERSION, $version->uuid,
            ($by === D::ACTION_LEGACY ? "«{$version->label}» quedó sustituida al volver a Legacy" : "«{$version->label}» quedó sustituida por la promoción")." (entrada {$fila->seq})", [
                'fromStatus' => StudioVersionStatus::Published->value,
                'toStatus' => StudioVersionStatus::Retired->value,
                'contentHash' => $version->content_hash,
                'diff' => ['entry' => $fila->uuid, 'by' => $by],
            ]);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** @return array{uuid:string,label:string} */
    private static function versionRef(StudioVersion $version): array
    {
        return ['uuid' => (string) $version->uuid, 'label' => (string) $version->label];
    }

    /**
     * La clasificación de una versión que no se pudo clasificar: los cuatro
     * `must_match` sin valor de la versión y sin coincidir (lo que el motor lee
     * sí se enseña), y nada más. Nunca publica: la revalidación ya la bloquea.
     *
     * @param  array<string,mixed>  $readings
     * @return array{mustMatch:list<array{param:string,versionValue:null,engineValue:mixed,ok:false}>,matches:list<string>,noEffect:list<string>,mismatches:list<array<string,mixed>>,engineReadingUnavailable:list<string>}
     */
    private static function unclassified(array $readings, bool $conPrompts = false): array
    {
        $mustMatch = [];
        foreach (array_keys(D::MUST_MATCH) as $id) {
            $lectura = $readings[$id] ?? [];
            $mustMatch[] = [
                'param' => $id,
                'versionValue' => null,
                'engineValue' => ($lectura['available'] ?? false) === true ? ($lectura['value'] ?? null) : null,
                'ok' => false,
            ];
        }

        $clasificacion = ['mustMatch' => $mustMatch, 'matches' => [], 'noEffect' => [], 'mismatches' => [], 'engineReadingUnavailable' => []];

        return $conPrompts ? $clasificacion + ['prompt' => []] : $clasificacion;
    }

    /**
     * m-2 del reviewer del Hito 5: cuando el catálogo actual no sabe leer el
     * contenido (una capa, un parámetro o una regla que ya no existen, unas
     * condiciones que no casan), la revalidación queda `blocked` sin que la
     * validación del grafo haya producido ningún bloqueo. El 409
     * `revalidation_blocked` enseña entonces este hallazgo, con la forma de
     * los demás, para que quien publica sepa qué hacer: una versión nueva.
     *
     * @param  list<array<string,mixed>>  $findings
     * @return list<array<string,mixed>>
     */
    private static function withUnreadableFinding(array $findings): array
    {
        if (StudioGraphValidator::isPublishable($findings)) {
            $findings[] = [
                'id' => self::FINDING_UNREADABLE,
                'level' => 'blocking',
                'title' => 'El catálogo actual no sabe leer este contenido',
                'detail' => 'Una capa, un parámetro, una regla o unas condiciones de esta versión ya no existen en el catálogo vigente. Hace falta una versión nueva, validada y aprobada con el catálogo de hoy.',
            ];
        }

        return $findings;
    }

    /**
     * `lab_gate` de las acciones que no publican: la columna es NOT NULL y la
     * puerta del laboratorio solo se mira al publicar.
     *
     * @return array{mode:null}
     */
    private static function notApplicableLabGate(): array
    {
        return ['mode' => null];
    }

    /** @param  array{available?:bool,value?:mixed}  $lectura */
    private static function brakeState(array $lectura): string
    {
        if (($lectura['available'] ?? false) !== true) {
            return 'unknown';
        }

        return ($lectura['value'] ?? null) === false ? 'released' : 'engaged';
    }

    private static function brakeMessage(string $freno): string
    {
        return $freno === 'unknown'
            ? 'No se pudo leer el freno de ULTRON: sin saber que está suelto no se publica ni se promueve.'
            : 'El freno de ULTRON está echado: no se publica ni se promueve hasta que alguien lo suelte.';
    }

    /** @return array{ok:bool,message?:string} */
    private static function requirement(bool $ok, string $message): array
    {
        return $ok ? ['ok' => true] : ['ok' => false, 'message' => $message];
    }

    /**
     * @param  array{mode:string,ok:bool,latest:string|null}  $lab
     * @return array{message?:string}
     */
    private static function labMessage(array $lab): array
    {
        if ($lab['mode'] === D::LAB_WAIVED) {
            return ['message' => 'La puerta del laboratorio está dispensada en el servidor (waived): queda escrito en la publicación.'];
        }
        if ($lab['ok']) {
            return [];
        }

        return ['message' => match ($lab['latest']) {
            null => 'Sin veredicto del laboratorio sobre esta huella.',
            'rejected' => 'El último veredicto del laboratorio la rechazó.',
            'needs_changes' => 'El último veredicto del laboratorio pide cambios.',
            default => 'El veredicto del laboratorio es de una versión anterior de los prompts.',
        }];
    }

    /**
     * Como `StudioVersions::asSet`: sin repetidos y por orden de bytes.
     *
     * @param  list<mixed>  $ids
     * @return list<string>
     */
    private static function asSet(array $ids): array
    {
        $conjunto = array_values(array_unique(array_map('strval', $ids)));
        sort($conjunto, SORT_STRING);

        return $conjunto;
    }
}
