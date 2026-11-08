<?php

namespace App\Services\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVersionReview;
use App\Models\UltronStudio\StudioVoiceAgent;
use App\Models\UltronStudio\StudioVoiceProfile;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\Dto\VoiceAgentTarget;
use App\Services\Voice\Dto\VoiceDeployResult;
use App\Services\Voice\Dto\VoiceIds;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentMapper;
use App\Services\Voice\VoiceAdapters;
use App\Services\Voice\VoiceAgentDeployer;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * PUBLICAR, PROMOVER, REVERTIR Y APAGAR LA VOZ (contrato H8, D8-7, D8-9,
 * D8-19, D8-20, D8-22, E2-4, E3-2; P-19, P-25): las puertas, el despliegue y
 * las entradas de la cadena de voz ({@see StudioVoicePublications}).
 *
 * PUBLICAR (D8-7) empieza siempre en la parte `canary` (D8-19):
 *  1. compilar la versión aprobada y el perfil aprobado ({@see StudioVoiceCompiler});
 *  2. `createAgent`: un agente NUEVO e inmutable (nunca se modifica uno que
 *     haya atendido llamadas), con `call_limits`, la privacidad y el audio
 *     apagado ya en la carga (`ElevenLabsAgentMapper`, B8); su fila guarda
 *     los destinos compilados como alias y huella (E5-1,
 *     {@see destinationHashes()});
 *  3. `describeAgent`: la proyección observada tiene que ser la compilada; si
 *     no, el agente queda `rejected` y no se publica;
 *  4. `assignNumber`: la parte canaria atiende las entrantes ({@see INBOUND_PART});
 *  5. la entrada firmada de la cadena, en una transacción con el cerrojo de la
 *     cadena, y `serves_canary` movido al agente nuevo (primero soltar).
 *  Si la cabeza se movió mientras se desplegaba, 409 `publication_moved`: el
 *  agente nuevo queda desplegado y sin parte (inerte) y el número vuelve al
 *  agente que diga la cadena (compensación, {@see restoreNumber()}).
 *
 * PROMOVER lleva el agente de la parte canaria a la parte `rest`, con la
 * evidencia del canario medida en el servidor (D8-19, P-16;
 * {@see StudioVoiceEvidence}) y el agente sin deriva. No toca el número.
 *
 * LO QUE SE VIO ES LO QUE SE PUBLICA (S8-c, E5-6; como la `previewHash` del
 * H5): publicar lleva la `specHash` que devolvió `compile-preview` y promover
 * la del agente del canario que se enseñó. Si la de ahora es otra, 409
 * `spec_changed` con `{expected, actual}`, antes de desplegar o escribir nada.
 *
 * REVERTIR (D8-9, D8-20 nivel 1) inserta una entrada que apunta al agente
 * ANTERIOR de la parte (que sigue existiendo) y, en la parte de las entrantes,
 * le reasigna el número. No es retroactivo: las llamadas en curso siguen con el
 * agente con el que empezaron. APAGAR (nivel 2) inserta una entrada sin agente
 * por cada parte que tenga uno (E3-2: una por parte afectada) y deja el número
 * sin agente. Las dos van en el sentido seguro: sin la bandera, sin la ventana
 * y sin cuatro ojos (como revertir en el H5), y la entrada se escribe ANTES de
 * tocar el número: si el proveedor no responde, la cadena ya deniega (la deriva
 * del número, E2-3, lo vigila).
 *
 * LAS PUERTAS, en orden: permiso (`ultron.voice_publish`, solo Super Admin por
 * defecto) → bandera (`ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED`, apagada; solo
 * publicar y promover, E2-4) → reintento → recursos y cuatro ojos (nadie
 * publica ni promueve lo que creó, versión o perfil) → ventana de 07:00 a
 * 19:00 de Bogotá (P-25) → freno suelto → compilar → la huella que se vio
 * (`spec_changed`) → puerta del laboratorio hablado (`required`: un veredicto
 * `approved` sobre la MISMA huella, P-19) → piezas del despliegue → red →
 * transacción. Promover: … → ventana → la huella del canario que se vio →
 * evidencia → deriva → transacción.
 *
 * IDEMPOTENCIA: la misma `clientRequestId`, la misma persona y el mismo cuerpo
 * devuelven las entradas de la primera vez (200 `replayed`); otra cosa es 409
 * `client_request_reused`. Si la primera vez falló después de desplegar, el
 * reintento con la misma llave encuentra SU agente (`client_request_id` del
 * agente) y sigue desde ahí; si falló antes, devuelve el mismo fallo.
 *
 * CERO RED POR DEFECTO: con la bandera apagada, publicar y promover son 409
 * antes de compilar; revertir y apagar sin nada que revertir o apagar, 409 sin
 * red. Solo `assignNumber` (desde aquí y desde ningún otro sitio, guarda de
 * SEC8) toca el número; con `CALLS_ENABLED_IN_CODE = false` asignar un agente
 * es `not_attempted` sin red (B8) y publicar se detiene (fallo cerrado).
 */
final class StudioVoicePublishing
{
    /** D8-22: publicar, promover, revertir y apagar la voz; soltar el freno. */
    public const PUBLISH = 'ultron.voice_publish';

    public const CODE_DISABLED = 'voice_publishing_disabled';

    /** S8-c: la huella de ahora no es la que se vio (`{expected, actual}`). */
    public const CODE_SPEC_CHANGED = 'spec_changed';

    public const LAB_REQUIRED = 'required';

    public const LAB_WAIVED = 'waived';

    /**
     * La parte que atiende las ENTRANTES: en el H8 las entrantes son solo de
     * canarios de voz (`voice.inbound.scope` = canary, E2-8), así que el número
     * sigue al agente de la parte canaria.
     */
    public const INBOUND_PART = StudioVoicePublication::SCOPE_CANARY;

    public const DEFAULT_MAX_CALL_SECONDS = 300;

    public const REASON_MIN = 5;

    public const REASON_MAX = 500;

    public const NOTE_MAX = 500;

    /** El cerrojo consultivo de la cadena (PostgreSQL). */
    public const LOCK = 'ultron_studio_voice:chain';

    /**
     * @param  VoiceAgentDeployer|null  $deployer  solo las pruebas lo pasan: en producción, {@see VoiceAdapters::deployer()}
     * @param  Closure(string):string|null  $signer  solo las pruebas lo pasan: en producción, {@see StudioVoicePublications::signer()}
     */
    public function __construct(
        private readonly StudioAudit $audit,
        private readonly StudioVersions $versions,
        private readonly StudioVoiceEvidence $evidence,
        private readonly ?VoiceAgentDeployer $deployer = null,
        private readonly ?Closure $signer = null,
    ) {}

    // ── Llaves y lo que se sabe sin tocar la base ───────────────────────────

    /** ¿Está encendida la bandera de publicar y promover? (E2-4; solo `true` la enciende.) */
    public static function enabled(): bool
    {
        return config('ultron_studio.voice.publishing_enabled') === true;
    }

    /** `required` (defecto) o `waived`, que solo decide el propietario (P-19). */
    public static function labGateMode(): string
    {
        return config('ultron_studio.voice.lab_gate') === self::LAB_WAIVED ? self::LAB_WAIVED : self::LAB_REQUIRED;
    }

    /** `VOICE_PER_CALL_MAX_SECONDS` efectivo, recortado a 60–600 (D8-15). */
    public static function maxCallSeconds(): int
    {
        $valor = config('voice.limits.per_call_max_seconds');

        return is_int($valor) ? min(StudioVoiceCompiler::MAX_DURATION_CEILING, max(StudioVoiceCompiler::MIN_DURATION_SECONDS, $valor)) : self::DEFAULT_MAX_CALL_SECONDS;
    }

    /**
     * La ventana de publicar y promover, en hora de Bogotá: la MISMA del Hito 5
     * (D8-19, P-25: `ULTRON_STUDIO_PUBLISH_WINDOW`, de 07:00 a 19:00 por defecto).
     *
     * @return array{start:int,end:int,label:string}|null
     */
    public static function window(): ?array
    {
        return StudioPublishing::window();
    }

    /**
     * El bloque `publishing` de la voz: si se puede publicar con las llaves de
     * ahora, por qué no, la ventana y la puerta del laboratorio.
     *
     * @return array{enabled:bool,code:string|null,window:array{label:string|null,open:bool},labGate:string}
     */
    public static function status(): array
    {
        $ventana = self::window();

        return [
            'enabled' => self::enabled(),
            'code' => self::enabled() ? null : self::CODE_DISABLED,
            'window' => ['label' => $ventana['label'] ?? null, 'open' => StudioPublishing::windowOpenFor($ventana, now())],
            'labGate' => self::labGateMode(),
        ];
    }

    // ── Previsualizar (POST voice/compile-preview) ──────────────────────────

    /**
     * La compilación, SIN RED y sin escribir nada: la especificación neutra
     * (sin teléfonos ni secretos), su huella, el último veredicto del
     * laboratorio sobre esa huella y si se podría desplegar con la
     * configuración de ahora (solo nombres de lo que falta).
     *
     * @return array<string, mixed>
     */
    public function preview(StudioAccess $access, string $versionUuid, string $profileUuid, string $purpose): array
    {
        $access->require(StudioVoiceProfiles::MANAGE);
        $version = $this->versions->findByUuid($versionUuid);
        $perfil = StudioVoiceProfiles::findByUuid($profileUuid);

        $spec = self::compile($version, $perfil, $purpose);

        return [
            'purpose' => $purpose,
            'specHash' => $spec->specHash,
            'shortSpecHash' => substr($spec->specHash, 0, 8),
            'compilerVersion' => $spec->compilerVersion(),
            'template' => ['version' => $spec->templateVersion(), 'hash' => $spec->templateHash()],
            'spec' => $spec->toArray(),
            'version' => ['uuid' => (string) $version->uuid, 'label' => (string) $version->label, 'status' => (string) $version->status],
            'profile' => ['uuid' => (string) $perfil->uuid, 'label' => (string) $perfil->label, 'revision' => (int) $perfil->revision, 'status' => (string) $perfil->status],
            'labVerdict' => StudioVoiceEvidence::labVerdict($spec->specHash),
            'deployable' => ['missing' => self::missingPieces($spec)],
        ];
    }

    // ── Publicar (POST voice/publish) ───────────────────────────────────────

    /**
     * @return array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool}
     */
    public function publish(StudioAccess $access, Request $request, string $clientRequestId, string $versionUuid, string $profileUuid, string $expectedSpecHash, ?string $note): array
    {
        $access->require(self::PUBLISH);
        $this->assertEnabled();
        $cuerpo = ['version' => strtolower($versionUuid), 'profile' => strtolower($profileUuid), 'specHash' => $expectedSpecHash, 'note' => $note];
        if ($previa = $this->replay($access, StudioVoicePublication::ACTION_PUBLISH, $clientRequestId, $cuerpo)) {
            return $previa;
        }

        return $this->orReplayed(fn (): array => $this->publishGated($access, $request, $clientRequestId, $versionUuid, $profileUuid, $expectedSpecHash, $note), $access, StudioVoicePublication::ACTION_PUBLISH, $clientRequestId, $cuerpo);
    }

    /** @return array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool} */
    private function publishGated(StudioAccess $access, Request $request, string $clientRequestId, string $versionUuid, string $profileUuid, string $expectedSpecHash, ?string $note): array
    {
        $version = $this->versions->findByUuid($versionUuid);
        $perfil = StudioVoiceProfiles::findByUuid($profileUuid);
        $this->assertFourEyes($access, $request, $version, $perfil, StudioVoicePublication::ACTION_PUBLISH);
        $this->assertWindow();
        $this->assertBrakeReleased();

        $spec = self::compile($version, $perfil, StudioVoiceCompiler::PURPOSE_PUBLICATION);
        // Lo que se vio es lo que se publica: antes de la puerta del
        // laboratorio (su veredicto es de otra huella) y de desplegar nada.
        self::assertSeenSpec($expectedSpecHash, $spec->specHash, 'La compilación de la voz cambió desde que la previsualizaste: vuelve a previsualizarla y revísala antes de publicar.');
        $puerta = self::labGate($spec->specHash);
        $numeroId = self::numberId();
        $faltan = self::missingPieces($spec);
        if ($faltan !== []) {
            throw self::notDeployable($faltan);
        }
        $payload = ElevenLabsAgentMapper::payload($spec, self::target($perfil, $spec));
        $firma = $this->signer();

        // La cabeza sobre la que se despliega: si se mueve, no se publica.
        $cabezaAntes = (int) (StudioVoicePublications::head()?->seq ?? 0);

        $agente = $this->deployedAgent($access, $clientRequestId, $version, $perfil, $spec, $payload);

        $asignacion = $this->deployer()->assignNumber((string) $numeroId, (string) $agente->provider_agent_id);
        if ($asignacion->outcome !== VoiceDeployResult::OUTCOME_ASSIGNED) {
            // Nada se publica. Si el proveedor pudo quedarse a medias (sin
            // respuesta, o con el número en otro agente), el número vuelve al
            // que diga la cadena; si no lo tocó, no se le pide nada más.
            if (in_array($asignacion->outcome, [VoiceDeployResult::OUTCOME_AMBIGUOUS, VoiceDeployResult::OUTCOME_ERROR], true)) {
                $this->restoreNumber($numeroId);
            }

            throw new UltronStudioException(
                'No se pudo asignar el número de voz al agente nuevo: no se publica. El agente queda desplegado y sin uso.',
                'voice_number_not_assigned', 409,
                ['outcome' => $asignacion->outcome, 'reason' => $asignacion->reason, 'agent' => StudioVoicePublications::agentDto($agente)],
            );
        }

        try {
            $filas = DB::transaction(function () use ($access, $request, $clientRequestId, $agente, $puerta, $note, $cabezaAntes, $firma, $version, $perfil): array {
                $cabeza = $this->lockedHead($cabezaAntes);
                $ahora = now();
                $fila = StudioVoicePublications::insertEntry(StudioVoicePublication::ACTION_PUBLISH, StudioVoicePublication::SCOPE_CANARY, $agente, $cabeza, $ahora,
                    $access, $clientRequestId, $puerta, null, $note, null, $firma);
                $this->serve($agente, StudioVoicePublication::SCOPE_CANARY);

                $this->audit->record($request, $access->actor, 'voice_publication.published', StudioAuditEvent::SUBJECT_INTEGRATION, $fila->uuid,
                    "Publicó la voz en canario: «{$version->label}» con el perfil «{$perfil->label}» (entrada {$fila->seq})", [
                        'toStatus' => 'canary',
                        'contentHash' => $version->content_hash,
                        'diff' => ['seq' => (int) $fila->seq, 'agent' => $agente->uuid, 'specHash' => $agente->spec_hash, 'labGate' => $puerta['mode']],
                    ]);

                return [$fila];
            });
        } catch (Throwable $e) {
            // FUERA de la transacción (no se confirmó nada): el número vuelve al
            // agente que diga la cadena, y el fallo sube con su motivo.
            $this->restoreNumber($numeroId);

            throw $e instanceof UniqueConstraintViolationException ? $this->conflictRefusal($e) : $e;
        }

        return $this->written($filas, $agente, ['action' => 'assigned', 'outcome' => $asignacion->outcome, 'reason' => $asignacion->reason]);
    }

    // ── Promover (POST voice/promote) ───────────────────────────────────────

    /**
     * @return array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool}
     */
    public function promote(StudioAccess $access, Request $request, string $clientRequestId, string $evidenceHash, string $expectedSpecHash, ?string $note): array
    {
        $access->require(self::PUBLISH);
        $this->assertEnabled();
        $cuerpo = ['evidenceHash' => $evidenceHash, 'specHash' => $expectedSpecHash, 'note' => $note];
        if ($previa = $this->replay($access, StudioVoicePublication::ACTION_PROMOTE, $clientRequestId, $cuerpo)) {
            return $previa;
        }

        return $this->orReplayed(fn (): array => $this->promoteGated($access, $request, $clientRequestId, $evidenceHash, $expectedSpecHash, $note), $access, StudioVoicePublication::ACTION_PROMOTE, $clientRequestId, $cuerpo);
    }

    /** @return array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool} */
    private function promoteGated(StudioAccess $access, Request $request, string $clientRequestId, string $evidenceHash, string $expectedSpecHash, ?string $note): array
    {
        $canario = StudioVoicePublications::current(StudioVoicePublication::SCOPE_CANARY);
        $agente = $canario?->agent;
        if ($agente === null) {
            throw new UltronStudioException('No hay ninguna voz publicada en canario: se promueve la que está en canario.', 'no_canary_publication', 409);
        }
        // Las filas ENTERAS (la relación cargada con la cadena lleva solo unas
        // columnas): los cuatro ojos comparan quién las creó.
        $version = StudioVersion::query()->find($agente->studio_version_id);
        $perfil = StudioVoiceProfile::query()->find($agente->voice_profile_id);
        if ($version === null || $perfil === null) {
            throw new LogicException('ultron_studio: el agente publicado no tiene su versión o su perfil.');
        }
        $this->assertFourEyes($access, $request, $version, $perfil, StudioVoicePublication::ACTION_PROMOTE);
        $this->assertWindow();
        // El agente del canario es el que se vio (otra publicación en canario
        // entretanto lo cambia), antes de medir su evidencia.
        self::assertSeenSpec($expectedSpecHash, (string) $agente->spec_hash, 'La voz en canario ya no es la que viste: vuelve a cargarla y revísala antes de promover.');

        $evidencia = $this->evidence->canary();
        if (! $evidencia['evaluation']['passes']) {
            throw new UltronStudioException('La evidencia del canario de voz todavía no basta para promover.', 'canary_evidence_insufficient', 409, ['evidence' => $evidencia['dto']]);
        }
        if (! hash_equals($evidencia['evaluation']['evidenceHash'], $evidenceHash)) {
            throw new UltronStudioException('La evidencia del canario cambió desde que la viste. Revísala otra vez antes de promover.', 'evidence_changed', 409, ['evidence' => $evidencia['dto']]);
        }
        $this->assertNoDrift($agente);
        $firma = $this->signer();
        $cabezaAntes = (int) (StudioVoicePublications::head()?->seq ?? 0);

        try {
            $filas = DB::transaction(function () use ($access, $request, $clientRequestId, $agente, $evidencia, $note, $cabezaAntes, $firma, $canario): array {
                $cabeza = $this->lockedHead($cabezaAntes);
                $ahora = now();
                $fila = StudioVoicePublications::insertEntry(StudioVoicePublication::ACTION_PROMOTE, StudioVoicePublication::SCOPE_REST, $agente, $cabeza, $ahora,
                    $access, $clientRequestId, ['mode' => null], $evidencia['record'], $note, null, $firma);
                $this->serve($agente, StudioVoicePublication::SCOPE_REST);

                $this->audit->record($request, $access->actor, 'voice_publication.promoted', StudioAuditEvent::SUBJECT_INTEGRATION, $fila->uuid,
                    "Promovió la voz del canario al resto (entrada {$fila->seq})", [
                        'toStatus' => 'rest',
                        'diff' => ['seq' => (int) $fila->seq, 'agent' => $agente->uuid, 'fromEntry' => $canario?->uuid, 'evidenceHash' => $evidencia['evaluation']['evidenceHash']],
                    ]);

                return [$fila];
            });
        } catch (UniqueConstraintViolationException $e) {
            throw $this->conflictRefusal($e);
        }

        return $this->written($filas, $agente, null);
    }

    // ── Revertir (POST voice/rollback) ──────────────────────────────────────

    /**
     * @return array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool}
     */
    public function rollback(StudioAccess $access, Request $request, string $clientRequestId, string $part, string $reason): array
    {
        $access->require(self::PUBLISH);
        $cuerpo = ['scope' => $part, 'reason' => $reason];
        if ($previa = $this->replay($access, StudioVoicePublication::ACTION_ROLLBACK, $clientRequestId, $cuerpo)) {
            return $previa;
        }

        return $this->orReplayed(fn (): array => $this->rollbackGated($access, $request, $clientRequestId, $part, $reason), $access, StudioVoicePublication::ACTION_ROLLBACK, $clientRequestId, $cuerpo);
    }

    /** @return array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool} */
    private function rollbackGated(StudioAccess $access, Request $request, string $clientRequestId, string $part, string $reason): array
    {
        $actual = StudioVoicePublications::current($part);
        if ($actual === null || $actual->agent_id === null) {
            throw self::nothingToRollback($part);
        }
        $vuelve = self::previousServing($part, (int) $actual->agent_id, (int) $actual->seq);
        if ($vuelve === null || $vuelve->agent === null) {
            throw self::nothingToRollback($part);
        }
        $agente = $vuelve->agent;
        if ($agente->purpose !== StudioVoiceAgent::PURPOSE_PUBLICATION || $agente->status !== StudioVoiceAgent::STATUS_DEPLOYED) {
            throw new UltronStudioException('El agente anterior ya no se puede usar. Apaga la voz si hace falta.', 'restore_unavailable', 409);
        }
        // No se vuelve a un agente que ya no es el que se verificó (o que no se puede leer).
        $this->assertNoDrift($agente);
        $firma = $this->signer();
        $cabezaAntes = (int) (StudioVoicePublications::head()?->seq ?? 0);

        try {
            $filas = DB::transaction(function () use ($access, $request, $clientRequestId, $part, $reason, $agente, $vuelve, $actual, $cabezaAntes, $firma): array {
                $cabeza = $this->lockedHead($cabezaAntes);
                $ahora = now();
                $fila = StudioVoicePublications::insertEntry(StudioVoicePublication::ACTION_ROLLBACK, $part, $agente, $cabeza, $ahora,
                    $access, $clientRequestId, ['mode' => null], null, $reason, (int) $vuelve->id, $firma);
                $this->serve($agente, $part);

                $this->audit->record($request, $access->actor, 'voice_publication.rolled_back', StudioAuditEvent::SUBJECT_INTEGRATION, $fila->uuid,
                    'Revirtió la voz ('.$part.") al agente anterior (entrada {$fila->seq})", [
                        'toStatus' => $part,
                        'diff' => ['seq' => (int) $fila->seq, 'agent' => $agente->uuid, 'from' => $actual->uuid, 'restores' => $vuelve->uuid, 'reason' => $reason],
                    ]);

                return [$fila];
            });
        } catch (UniqueConstraintViolationException $e) {
            throw $this->conflictRefusal($e);
        }

        // La entrada ya deniega lo que había; el número, después (sentido seguro).
        $numero = $part === self::INBOUND_PART ? $this->pointNumber((string) $agente->provider_agent_id) : null;

        return $this->written($filas, $agente, $numero);
    }

    // ── Apagar (POST voice/off) ─────────────────────────────────────────────

    /**
     * @return array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool}
     */
    public function off(StudioAccess $access, Request $request, string $clientRequestId, string $reason): array
    {
        $access->require(self::PUBLISH);
        $cuerpo = ['reason' => $reason];
        if ($previa = $this->replay($access, StudioVoicePublication::ACTION_OFF, $clientRequestId, $cuerpo)) {
            return $previa;
        }

        return $this->orReplayed(fn (): array => $this->offGated($access, $request, $clientRequestId, $reason), $access, StudioVoicePublication::ACTION_OFF, $clientRequestId, $cuerpo);
    }

    /** @return array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool} */
    private function offGated(StudioAccess $access, Request $request, string $clientRequestId, string $reason): array
    {
        $partes = [];
        foreach (StudioVoicePublication::SCOPES as $parte) {
            $actual = StudioVoicePublications::current($parte);
            if ($actual !== null && $actual->agent_id !== null) {
                $partes[] = $parte;
            }
        }
        if ($partes === []) {
            throw new UltronStudioException('La voz ya está apagada: ninguna parte tiene agente.', 'voice_already_off', 409);
        }
        $firma = $this->signer();
        $cabezaAntes = (int) (StudioVoicePublications::head()?->seq ?? 0);

        try {
            $filas = DB::transaction(function () use ($access, $request, $clientRequestId, $reason, $partes, $cabezaAntes, $firma): array {
                $cabeza = $this->lockedHead($cabezaAntes);
                $ahora = now();
                $filas = [];
                foreach ($partes as $i => $parte) {
                    // Una entrada por parte (E3-2); la primera lleva la llave de la petición y las demás, una derivada de ella.
                    $llave = $i === 0 ? $clientRequestId : self::derivedRequestId($clientRequestId, $parte);
                    $cabeza = StudioVoicePublications::insertEntry(StudioVoicePublication::ACTION_OFF, $parte, null, $cabeza, $ahora,
                        $access, $llave, ['mode' => null], null, $reason, null, $firma);
                    StudioVoiceAgent::release($parte);
                    $filas[] = $cabeza;
                }

                $this->audit->record($request, $access->actor, 'voice_publication.off', StudioAuditEvent::SUBJECT_INTEGRATION, $filas[0]->uuid,
                    'Apagó la voz ('.implode(' y ', $partes).')', [
                        'toStatus' => 'off',
                        'diff' => ['seqs' => array_map(static fn (StudioVoicePublication $f): int => (int) $f->seq, $filas), 'parts' => $partes, 'reason' => $reason],
                    ]);

                return $filas;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw $this->conflictRefusal($e);
        }

        // La cadena ya deniega; el número se queda sin agente (siempre se puede).
        return $this->written($filas, null, in_array(self::INBOUND_PART, $partes, true) ? $this->pointNumber(null) : null);
    }

    // ── El despliegue ───────────────────────────────────────────────────────

    /**
     * La especificación compilada y leída por el lector estricto de B8 (la
     * misma huella). La negativa del compilador sube como su 422.
     */
    private static function compile(StudioVersion $version, StudioVoiceProfile $perfil, string $purpose): VoiceAgentSpec
    {
        $compilado = StudioVoiceCompiler::compile(self::versionInput($version), StudioVoiceProfiles::compilerInput($perfil), self::maxCallSeconds(), $purpose);
        try {
            return VoiceAgentSpec::fromCompiled($compilado);
        } catch (InvalidArgumentException $e) {
            // El compilador y el lector se fijan por paridad: esto es un error del código.
            throw new LogicException('La especificación compilada no la acepta el lector de la voz.', 0, $e);
        }
    }

    /**
     * El agente desplegado y VERIFICADO de esta petición: el suyo si un
     * reintento lo encuentra ya desplegado; si no, uno nuevo (`pending` →
     * `createAgent` → `describeAgent` → `deployed`). Un fallo deja el agente
     * `failed` (o `rejected` si la proyección no cuadra) y es 409.
     */
    private function deployedAgent(StudioAccess $access, string $clientRequestId, StudioVersion $version, StudioVoiceProfile $perfil, VoiceAgentSpec $spec, VoiceAgentPayload $payload): StudioVoiceAgent
    {
        $existente = StudioVoiceAgent::query()->where('client_request_id', $clientRequestId)->first();
        if ($existente !== null) {
            if ((int) $existente->created_by_admin_id !== $access->id() || $existente->purpose !== StudioVoiceAgent::PURPOSE_PUBLICATION
                || (int) $existente->studio_version_id !== (int) $version->id || (int) $existente->voice_profile_id !== (int) $perfil->id) {
                throw UltronStudioException::clientRequestReused();
            }
            if ($existente->status === StudioVoiceAgent::STATUS_DEPLOYED && $existente->spec_hash === $spec->specHash && $existente->payload_hash === $payload->payloadHash) {
                return $existente;
            }

            throw self::deployFailed($existente->failure_code ?? 'agent_'.$existente->status, $existente);
        }

        $ahora = now();
        $yo = $access->snapshot();
        $agente = StudioVoiceAgent::query()->forceCreate([
            'uuid' => strtolower((string) Str::uuid()),
            'client_request_id' => $clientRequestId,
            'purpose' => StudioVoiceAgent::PURPOSE_PUBLICATION,
            'studio_version_id' => $version->id,
            'studio_content_hash' => $version->content_hash,
            'voice_profile_id' => $perfil->id,
            'voice_profile_hash' => $perfil->content_hash,
            'compiler_version' => $spec->compilerVersion(),
            'spec_hash' => $spec->specHash,
            'payload_hash' => $payload->payloadHash,
            'projection_hash' => $payload->projectionHash,
            'transfer_destination_hashes' => self::destinationHashes($spec),
            'provider' => StudioVoiceAgent::PROVIDER_ELEVENLABS,
            'status' => StudioVoiceAgent::STATUS_PENDING,
            'serves_canary' => false,
            'serves_rest' => false,
            'created_by_admin_id' => $yo['id'],
            'created_by_name' => $yo['name'],
            'created_by_role' => $yo['role'],
            'created_at' => $ahora,
            'status_changed_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $creado = $this->deployer()->createAgent($payload);
        if ($creado->outcome !== VoiceDeployResult::OUTCOME_CREATED || $creado->agentId === null) {
            $this->markAgent($agente, StudioVoiceAgent::STATUS_FAILED, ['failure_code' => 'create_'.$creado->outcome]);

            throw self::deployFailed('create_'.$creado->outcome, $agente->refresh());
        }

        $observado = $this->deployer()->describeAgent($creado->agentId);
        if ($observado->projectionHash === null || $observado->agentId !== $creado->agentId) {
            $this->markAgent($agente, StudioVoiceAgent::STATUS_FAILED, ['provider_agent_id' => $creado->agentId, 'failure_code' => 'observe_'.$observado->outcome]);

            throw self::deployFailed('observe_'.$observado->outcome, $agente->refresh());
        }
        if (! $observado->matches($payload)) {
            // D8-7 paso 3: no cuadra → `rejected`, y nunca se publica.
            $this->markAgent($agente, StudioVoiceAgent::STATUS_REJECTED, [
                'provider_agent_id' => $creado->agentId, 'observed_hash' => $observado->projectionHash, 'observed_at' => now(),
            ]);

            throw new UltronStudioException('El agente que quedó en el proveedor no es el compilado (deriva al desplegar): no se publica.', 'voice_agent_rejected', 409,
                ['agent' => StudioVoicePublications::agentDto($agente->refresh())]);
        }

        $this->markAgent($agente, StudioVoiceAgent::STATUS_DEPLOYED, [
            'provider_agent_id' => $creado->agentId, 'observed_hash' => $observado->projectionHash, 'observed_at' => now(),
        ]);

        return $agente->refresh();
    }

    /**
     * Los destinos de transferencia APROBADOS del agente (E5-1): los compilados,
     * no los del perfil (el perfil puede cambiar; el agente no). De cada uno, el
     * alias y la huella `voice-phone` de la especificación (`ref`, la de
     * {@see StudioVoiceProfiles::phoneHash()}, que {@see target()} ya comprobó
     * contra los números descifrados), NUNCA el número; `[]` sin destinos. La
     * lee la telefonía (`VoicePublishedConfig`) sin descifrar nada.
     *
     * @return list<array{alias:string,hash:string}>
     */
    private static function destinationHashes(VoiceAgentSpec $spec): array
    {
        return array_map(static fn (array $d): array => ['alias' => $d['alias'], 'hash' => $d['ref']], $spec->transferDestinations());
    }

    /**
     * Dónde y con qué límites se despliega (D8-7 paso 4, D8-15): la base de los
     * webhooks, las referencias de los secretos (nunca sus valores), el techo
     * diario (salientes + entrantes), la concurrencia y los teléfonos de los
     * destinos, DESCIFRADOS EN MEMORIA y comprobados contra la especificación
     * (R-07: exactamente los aprobados, `transfer_destination_not_in_profile`).
     */
    private static function target(StudioVoiceProfile $perfil, VoiceAgentSpec $spec): VoiceAgentTarget
    {
        try {
            $numeros = StudioVoiceProfiles::transferNumbers($perfil);
        } catch (Throwable $e) {
            // Tragado a propósito (sin el mensaje): una `APP_KEY` rotada deja los
            // destinos ilegibles; el perfil hay que volver a crearlo.
            Log::warning('ultron_studio.voice_profile_destinations_unreadable', ['error_class' => class_basename($e)]);

            throw new UltronStudioException('Los destinos de transferencia del perfil no se pueden leer en este servidor: crea otra revisión del perfil.', 'voice_destinations_unreadable', 409);
        }
        StudioVoiceCompiler::assertDestinationsMatch($spec->toArray(), $numeros, StudioVoiceProfiles::phoneHash(...));

        return new VoiceAgentTarget(
            VoiceAgentTarget::PURPOSE_PUBLICATION,
            (string) config('voice.webhook_base_url'),
            self::ref(config('voice.elevenlabs.tool_secret_ref')),
            self::ref(config('voice.elevenlabs.initiation_secret_ref')),
            self::ref(config('voice.elevenlabs.post_call_webhook_ref')),
            min(VoiceAgentTarget::MAX_DAILY_CALL_LIMIT, self::dailyCallLimit()),
            min(VoiceAgentTarget::MAX_CONCURRENCY, max(1, (int) config('voice.limits.max_concurrent_calls'))),
            $numeros,
        );
    }

    /**
     * Lo que falta para desplegar con la configuración de ahora: solo
     * NOMBRES, nunca valores. Vacío = se puede intentar.
     *
     * @return list<string>
     */
    private static function missingPieces(VoiceAgentSpec $spec): array
    {
        $faltan = [];
        if (VoiceAgentTarget::cleanBase((string) config('voice.webhook_base_url')) === null) {
            $faltan[] = 'webhook_base_url';
        }
        if ($spec->tools() !== [] && self::ref(config('voice.elevenlabs.tool_secret_ref')) === null) {
            $faltan[] = 'tool_secret_ref';
        }
        if (self::ref(config('voice.elevenlabs.initiation_secret_ref')) === null) {
            $faltan[] = 'initiation_secret_ref';
        }
        $postCall = config('voice.elevenlabs.post_call_webhook_ref');
        if (is_string($postCall) && trim($postCall) !== '' && self::ref($postCall) === null) {
            $faltan[] = 'post_call_webhook_ref';
        }
        if (self::dailyCallLimit() < 1) {
            $faltan[] = 'daily_call_cap';
        }
        if (self::numberId() === null) {
            $faltan[] = 'phone_number_id';
        }

        return $faltan;
    }

    /** El techo diario del agente: salientes + entrantes (D8-15). */
    private static function dailyCallLimit(): int
    {
        return max(0, (int) config('voice.twilio.daily_call_cap')) + max(0, (int) config('voice.inbound.daily_cap'));
    }

    /** El id del número importado en el proveedor (`ELEVENLABS_AGENT_PHONE_NUMBER_ID`), o null. */
    private static function numberId(): ?string
    {
        return VoiceIds::providerIdOrNull(is_string($v = config('voice.elevenlabs.agent_phone_number_id')) ? trim($v) : null);
    }

    private static function ref(mixed $valor): ?string
    {
        $valor = is_string($valor) ? trim($valor) : '';

        return $valor !== '' && preg_match(VoiceAgentTarget::REF_PATTERN, $valor) === 1 ? $valor : null;
    }

    /**
     * Apunta el número al agente (o lo deja sin agente) después de escribir la
     * cadena, en el sentido seguro: un fallo no deshace la entrada, se informa
     * y lo vigila la deriva del número (E2-3).
     *
     * @return array{action:string,outcome:string,reason:string|null}
     */
    private function pointNumber(?string $providerAgentId): array
    {
        $numero = self::numberId();
        $accion = $providerAgentId === null ? 'unassigned' : 'assigned';
        if ($numero === null) {
            return ['action' => $accion, 'outcome' => VoiceDeployResult::OUTCOME_NOT_CONFIGURED, 'reason' => null];
        }
        $r = $this->deployer()->assignNumber($numero, $providerAgentId);
        if ($r->outcome !== VoiceDeployResult::OUTCOME_ASSIGNED) {
            Log::warning('ultron_studio.voice_number_not_moved', ['outcome' => $r->outcome, 'reason' => $r->reason]);
        }

        return ['action' => $accion, 'outcome' => $r->outcome, 'reason' => $r->reason];
    }

    /**
     * Compensación (publicar falló DESPUÉS de asignar el número): el número
     * vuelve al agente que la cadena dice que atiende las entrantes, o a
     * ninguno. Lo mejor que se puede; si falla, lo vigila la deriva del número.
     */
    private function restoreNumber(?string $numeroId): void
    {
        if ($numeroId === null) {
            return;
        }
        try {
            $r = $this->deployer()->assignNumber($numeroId, StudioVoiceDrift::expectedNumberAgent());
            if ($r->outcome !== VoiceDeployResult::OUTCOME_ASSIGNED) {
                Log::warning('ultron_studio.voice_number_not_restored', ['outcome' => $r->outcome, 'reason' => $r->reason]);
            }
        } catch (Throwable $e) {
            // Tragado a propósito: la petición ya falla con su motivo; esto es
            // una compensación y la deriva del número la vigila. Solo la clase.
            Log::warning('ultron_studio.voice_number_not_restored', ['error_class' => class_basename($e)]);
        }
    }

    /** El agente no tiene deriva (D8-21): lo que se observa es lo que se verificó al desplegar. */
    private function assertNoDrift(StudioVoiceAgent $agente): void
    {
        $deriva = (new StudioVoiceDrift($this->deployer()))->agent($agente);
        if ($deriva['outcome'] !== StudioVoiceDrift::OK) {
            throw new UltronStudioException(
                $deriva['outcome'] === StudioVoiceDrift::DRIFT
                    ? 'El agente cambió en el proveedor desde que se verificó (deriva): no se usa. Apaga la voz si hace falta.'
                    : 'No se pudo comprobar el agente en el proveedor: sin comprobarlo no se usa. Apaga la voz si hace falta.',
                'voice_agent_drift', 409, ['drift' => $deriva],
            );
        }
    }

    /**
     * La puerta del laboratorio hablado (P-19): `required` exige que el
     * último veredicto sobre la MISMA huella sea `approved` (de otra identidad
     * que quien abrió la sesión, lo exige la base); `waived` pasa y queda
     * escrito en la entrada.
     *
     * @return array{mode:string,verdict?:string,sessionUuid?:string}
     */
    private static function labGate(string $specHash): array
    {
        $modo = self::labGateMode();
        $ultimo = StudioVoiceEvidence::labVerdict($specHash);
        if ($modo !== self::LAB_WAIVED && $ultimo['latest'] !== 'approved') {
            throw new UltronStudioException(
                $ultimo['latest'] === null
                    ? 'Falta el veredicto del laboratorio hablado sobre esta misma compilación: pruébala en el laboratorio y que otra persona dé su veredicto.'
                    : 'El último veredicto del laboratorio hablado sobre esta compilación no la aprueba.',
                'lab_verdict_required', 409, ['latest' => $ultimo['latest'], 'specHash' => $specHash],
            );
        }

        return array_filter(['mode' => $modo, 'verdict' => $ultimo['latest'], 'sessionUuid' => $ultimo['sessionUuid']], static fn (mixed $v): bool => $v !== null);
    }

    // ── La cadena ───────────────────────────────────────────────────────────

    /**
     * Dentro de la transacción: el cerrojo de la cadena y la cabeza, que tiene
     * que ser la misma sobre la que se desplegó. Si se movió, 409
     * `publication_moved` (la carrera, como en el H5).
     */
    private function lockedHead(int $cabezaAntes): ?StudioVoicePublication
    {
        StudioIntegrations::lock(self::LOCK);
        $cabeza = StudioVoicePublications::head();
        if ((int) ($cabeza?->seq ?? 0) !== $cabezaAntes) {
            throw UltronStudioException::publicationMoved();
        }

        return $cabeza;
    }

    /** La parte pasa al agente: primero se suelta, después se toma (los índices únicos parciales se comprueban fila a fila). */
    private function serve(StudioVoiceAgent $agente, string $parte): void
    {
        StudioVoiceAgent::release($parte);
        if (! StudioVoiceAgent::take((int) $agente->id, $parte)) {
            throw UltronStudioException::publicationMoved();
        }
    }

    /**
     * La entrada de la parte cuyo agente vuelve al revertir: la más reciente
     * anterior a la vigente con un agente distinto del actual (D8-9: el
     * agente anterior, que sigue existiendo).
     */
    private static function previousServing(string $part, int $agentActual, int $seqActual): ?StudioVoicePublication
    {
        return StudioVoicePublication::query()
            ->with(['agent.version', 'agent.profile'])
            ->where('scope', $part)
            ->where('seq', '<', $seqActual)
            ->whereNotNull('agent_id')
            ->where('agent_id', '<>', $agentActual)
            ->orderByDesc('seq')
            ->first();
    }

    /**
     * La firma de la cadena: la de las pruebas o la del lector de la
     * telefonía (la única implementación, D8-8).
     *
     * @return Closure(string):string
     */
    private function signer(): Closure
    {
        return $this->signer ?? StudioVoicePublications::signer();
    }

    private function deployer(): VoiceAgentDeployer
    {
        return $this->deployer ?? VoiceAdapters::deployer();
    }

    // ── Reintentos, carreras y rechazos ─────────────────────────────────────

    /**
     * La misma petición repetida devuelve lo de la primera vez. La misma llave
     * de otra persona, para otra acción o con otro cuerpo, es 409
     * `client_request_reused`.
     *
     * @param  array<string, mixed>  $cuerpo
     * @return array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool}|null
     */
    private function replay(StudioAccess $access, string $action, string $clientRequestId, array $cuerpo): ?array
    {
        $llave = strtolower($clientRequestId);
        $previa = StudioVoicePublication::query()->with(['agent.version', 'agent.profile'])->where('client_request_id', $llave)->first();
        if ($previa === null) {
            return null;
        }
        if ((int) $previa->actor_admin_id !== $access->id() || $previa->action !== $action || self::bodyOf($previa) !== $cuerpo) {
            throw UltronStudioException::clientRequestReused();
        }

        $filas = [$previa];
        if ($action === StudioVoicePublication::ACTION_OFF) {
            $derivadas = array_map(static fn (string $p): string => self::derivedRequestId($llave, $p), StudioVoicePublication::SCOPES);
            $filas = StudioVoicePublication::query()->whereIn('client_request_id', [$llave, ...$derivadas])->orderBy('seq')->get()->all();
        }

        return [
            'entries' => array_map(static fn (StudioVoicePublication $f): array => StudioVoicePublications::entryDto($f), $filas),
            'agent' => $previa->agent === null ? null : StudioVoicePublications::agentDto($previa->agent),
            'number' => null,
            'replayed' => true,
        ];
    }

    /**
     * H-1 del H5: la MISMA petición repetida puede pasar el reintento sin ver
     * nada y tropezar después con el estado que su gemela acaba de dejar.
     * Antes de rechazar se vuelve a mirar el reintento FUERA de la transacción.
     *
     * @param  callable():array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool}  $accion
     * @param  array<string, mixed>  $cuerpo
     * @return array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool}
     */
    private function orReplayed(callable $accion, StudioAccess $access, string $action, string $clientRequestId, array $cuerpo): array
    {
        try {
            return $accion();
        } catch (UltronStudioException $e) {
            if ($previa = $this->replay($access, $action, $clientRequestId, $cuerpo)) {
                return $previa;
            }

            throw $e;
        }
    }

    /**
     * Lo que identifica el cuerpo de la petición que creó una entrada, con la
     * misma forma que lo calcula cada acción. La `specHash` que se vio es la
     * del agente de la entrada: publicar y promover solo escriben si cuadran.
     *
     * @return array<string, mixed>
     */
    private static function bodyOf(StudioVoicePublication $p): array
    {
        return match ($p->action) {
            StudioVoicePublication::ACTION_PUBLISH => [
                'version' => $p->agent?->version?->uuid,
                'profile' => $p->agent?->profile?->uuid,
                'specHash' => $p->agent?->spec_hash,
                'note' => $p->reason,
            ],
            StudioVoicePublication::ACTION_PROMOTE => [
                'evidenceHash' => is_array($p->evidence) ? StudioContentHasher::hash($p->evidence) : null,
                'specHash' => $p->agent?->spec_hash,
                'note' => $p->reason,
            ],
            StudioVoicePublication::ACTION_ROLLBACK => ['scope' => $p->scope, 'reason' => $p->reason],
            default => ['reason' => $p->reason],
        };
    }

    /**
     * Un UNIQUE paró la inserción y no era el reintento: la carrera por la
     * cabeza (`seq`) o por una parte (`usva_one_*`) → `publication_moved`. Un
     * UNIQUE que no se reconoce no se disfraza: sube tal cual.
     */
    private function conflictRefusal(UniqueConstraintViolationException $e): UltronStudioException
    {
        $mensaje = $e->getMessage();
        foreach (['ultron_studio_voice_publications_seq_unique', 'ultron_studio_voice_publications.seq', 'usva_one_canary', 'usva_one_rest',
            'ultron_studio_voice_agents.serves_canary', 'ultron_studio_voice_agents.serves_rest'] as $marca) {
            if (str_contains($mensaje, $marca)) {
                return UltronStudioException::publicationMoved();
            }
        }

        throw $e;
    }

    /** La bandera de publicar y promover (E2-4). */
    private function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new UltronStudioException('Publicar la voz está desactivado en el servidor: lo enciende quien administra el sistema.', self::CODE_DISABLED, 409);
        }
    }

    /**
     * S8-c (E5-6): la huella que se vio (`expectedSpecHash`, la de
     * `compile-preview` al publicar o la del agente del canario al promover) es
     * la de ahora. Si no, 409 `spec_changed` con las dos, sin desplegar ni
     * escribir nada; el CRM vuelve a enseñar la compilación.
     */
    private static function assertSeenSpec(string $expected, string $actual, string $message): void
    {
        if (! hash_equals($actual, $expected)) {
            throw new UltronStudioException($message, self::CODE_SPEC_CHANGED, 409, ['expected' => $expected, 'actual' => $actual]);
        }
    }

    /** P-25: publicar y promover, solo dentro de la ventana (hora de Bogotá). */
    private function assertWindow(): void
    {
        $ventana = self::window();
        if (! StudioPublishing::windowOpenFor($ventana, now())) {
            throw UltronStudioException::outsidePublicationWindow($ventana['label'] ?? null);
        }
    }

    /** D8-19: con el freno echado (o sin poder leerlo) no se publica ni se promueve. */
    private function assertBrakeReleased(): void
    {
        $freno = StudioVoiceEvidence::brakeState();
        if ($freno !== StudioVoiceEvidence::BRAKE_RELEASED) {
            throw new UltronStudioException(
                $freno === StudioVoiceEvidence::BRAKE_UNKNOWN
                    ? 'No se pudo leer el freno de voz: sin saber que está suelto no se publica.'
                    : 'El freno de voz está echado: no se publica hasta que una persona lo suelte.',
                'voice_braked', 409, ['brake' => $freno],
            );
        }
    }

    /**
     * Cuatro ojos al publicar y al promover, por IDENTIDAD: nadie publica ni
     * promueve lo que creó, sea la versión o el perfil (D8-19). El intento
     * queda en el libro.
     */
    private function assertFourEyes(StudioAccess $access, Request $request, ?StudioVersion $version, ?StudioVoiceProfile $perfil, string $action): void
    {
        $mia = $version !== null && $access->isCreator($version);
        $miPerfil = $perfil !== null && (int) $perfil->created_by_admin_id === $access->id();
        if (! $mia && ! $miPerfil) {
            return;
        }
        $verbo = $action === StudioVoicePublication::ACTION_PROMOTE ? 'promover' : 'publicar';
        $this->audit->recordDenied($request, $access->actor, 'voice_publication.four_eyes_denied', StudioAuditEvent::SUBJECT_INTEGRATION, $perfil?->uuid,
            "Intentó {$verbo} una voz que creó", ['diff' => ['action' => $action, 'version' => $mia, 'profile' => $miPerfil]]);

        throw UltronStudioException::publicationFourEyesRequired($action);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /**
     * La entrada del compilador desde una versión del Studio (`approvedBy`:
     * quien firmó la reseña `approved`, o null).
     *
     * @return array{uuid:string,status:string,contentHash:string,content:array<string,mixed>,createdBy:int,approvedBy:int|null}
     */
    public static function versionInput(StudioVersion $v): array
    {
        $v->loadMissing('review');
        $resena = $v->review;

        return [
            'uuid' => (string) $v->uuid,
            'status' => (string) $v->status,
            'contentHash' => (string) $v->content_hash,
            'content' => is_array($v->content) ? $v->content : [],
            'createdBy' => (int) $v->created_by_admin_id,
            'approvedBy' => $resena?->decision === StudioVersionReview::DECISION_APPROVED ? (int) $resena->reviewer_admin_id : null,
        ];
    }

    /**
     * La llave de la segunda entrada (y siguientes) de una misma petición:
     * determinista (UUID v5 con la llave de la petición como espacio de
     * nombres), para que el reintento las encuentre todas.
     */
    public static function derivedRequestId(string $clientRequestId, string $part): string
    {
        return strtolower(Uuid::uuid5(strtolower($clientRequestId), 'ultron-studio-voice:'.$part)->toString());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function markAgent(StudioVoiceAgent $agente, string $estado, array $values): void
    {
        $ahora = now();
        if (! StudioVoiceAgent::transition((int) $agente->id, [StudioVoiceAgent::STATUS_PENDING], ['status' => $estado, 'status_changed_at' => $ahora] + $values)) {
            throw new LogicException('ultron_studio: el agente de voz no estaba pendiente al marcar su despliegue.');
        }
    }

    /**
     * @param  list<StudioVoicePublication>  $filas
     * @param  array<string,mixed>|null  $numero
     * @return array{entries:list<array<string,mixed>>,agent:array<string,mixed>|null,number:array<string,mixed>|null,replayed:bool}
     */
    private function written(array $filas, ?StudioVoiceAgent $agente, ?array $numero): array
    {
        $agente?->refresh()->loadMissing(['version:id,uuid,label', 'profile:id,uuid,label,revision']);

        return [
            'entries' => array_map(static fn (StudioVoicePublication $f): array => StudioVoicePublications::entryDto($f), $filas),
            'agent' => $agente === null ? null : StudioVoicePublications::agentDto($agente),
            'number' => $numero,
            'replayed' => false,
        ];
    }

    /** @param  list<string>  $faltan */
    private static function notDeployable(array $faltan): UltronStudioException
    {
        return new UltronStudioException('Faltan piezas de la configuración de voz para desplegar un agente (se arreglan en el servidor).', 'voice_not_deployable', 409, ['missing' => $faltan]);
    }

    private static function deployFailed(string $code, StudioVoiceAgent $agente): UltronStudioException
    {
        return new UltronStudioException('El proveedor de voz no dejó un agente verificado: no se publica. Inténtalo de nuevo con otra petición.', 'voice_deploy_failed', 409,
            ['failure' => $code, 'agent' => StudioVoicePublications::agentDto($agente)]);
    }

    private static function nothingToRollback(string $part): UltronStudioException
    {
        return new UltronStudioException("No hay un agente anterior al que volver en la parte «{$part}». Apaga la voz si hace falta.", 'nothing_to_rollback', 409, ['scope' => $part]);
    }
}
