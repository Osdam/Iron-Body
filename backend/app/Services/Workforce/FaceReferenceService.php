<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\Identity;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\TrainerFaceReference;
use App\Models\WorkforceAuthorizationCode;
use App\Support\Workforce\FaceVector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * La plantilla facial de una persona: registrarla, compararla, revocarla.
 *
 * El rostro se compara AQUÍ, nunca en el teléfono: la app manda el embedding de
 * la captura y el servidor decide. La plantilla no sale nunca del servidor.
 *
 * Tres defensas que antes no existían:
 *  · norma del vector: un vector casi nulo coincidía con cualquiera;
 *  · nunca se sobrescribe una plantilla activa: cambiarla exige que un
 *    administrador lo autorice;
 *  · 1:N al registrar: un rostro que ya es de otra persona no se vincula.
 */
class FaceReferenceService
{
    public const STATUS_NOT_ENROLLED = 'not_enrolled';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RENEWAL_PENDING = 'renewal_pending';

    public const STATUS_REVOKED = 'revoked';

    public const EVENT_ENROLLED = 'biometric.enrolled';

    public const EVENT_REVOKED = 'biometric.revoked';

    public const EVENT_VERIFY_FAILED = 'biometric.verify_failed';

    public const EVENT_DUPLICATE_REJECTED = 'biometric.duplicate_rejected';

    public const EVENT_CODE_ISSUED = 'biometric.code_issued';

    /** Un código de autorización rechazado: sin el código ni su huella, solo el motivo. */
    public const EVENT_CODE_REJECTED = 'biometric.code_rejected';

    public function __construct(private readonly WorkforceEvents $events) {}

    // ── Consulta ────────────────────────────────────────────────────────────

    public function referenceForIdentity(Identity $identity): ?TrainerFaceReference
    {
        $trainerIds = Trainer::query()->where('identity_id', $identity->getKey())->pluck('id');

        return TrainerFaceReference::query()
            ->where(fn ($q) => $q->where('identity_id', $identity->getKey())
                ->orWhere(fn ($q) => $q->whereNull('identity_id')->whereIn('trainer_id', $trainerIds)))
            ->orderByDesc('id')
            ->first();
    }

    public function referenceForTrainer(Trainer $trainer): ?TrainerFaceReference
    {
        if ($trainer->identity_id) {
            $identity = Identity::find($trainer->identity_id);
            if ($identity !== null) {
                return $this->referenceForIdentity($identity);
            }
        }

        return TrainerFaceReference::query()->where('trainer_id', $trainer->getKey())->first();
    }

    public function status(?TrainerFaceReference $ref): string
    {
        if ($ref === null) {
            return self::STATUS_NOT_ENROLLED;
        }
        if (! $ref->isActive()) {
            return self::STATUS_REVOKED;
        }
        // Sin consentimiento ni autorización (registro anterior al kiosko) o
        // con un registro nuevo ya autorizado: hay que renovarla. Lo segundo
        // solo ofrece «Registrar mi rostro» en la tablet: la plantilla válida
        // sigue verificando hasta que la nueva la sustituya (ver verify()).
        if (! $ref->isValidated()
            || ($ref->identity_id !== null && $this->pendingEnrollmentCode((int) $ref->identity_id))) {
            return self::STATUS_RENEWAL_PENDING;
        }

        return self::STATUS_ACTIVE;
    }

    public function statusForIdentity(Identity $identity): string
    {
        return $this->status($this->referenceForIdentity($identity));
    }

    // ── Comparación ─────────────────────────────────────────────────────────

    /** El embedding es un rostro calculado por la app (tamaño, finitos, norma). */
    public function assertValidEmbedding(mixed $embedding): void
    {
        if (! FaceVector::isValid($embedding, (float) config('workforce.face.norm_min'), (float) config('workforce.face.norm_max'))) {
            throw WorkforceException::embeddingInvalid();
        }
    }

    /**
     * Verifica que el rostro es el de la persona. Devuelve la distancia.
     * Lanza: no enrolada, bloqueada por intentos o no coincide (uniforme).
     */
    public function verify(?TrainerFaceReference $ref, mixed $embedding, string $attemptKey, ?Request $request = null): float
    {
        $max = (int) config('workforce.face.max_failed_attempts', 5);
        $lockMinutes = (int) config('workforce.face.lockout_minutes', 15);
        $limiterKey = 'workforce-face:'.$attemptKey;

        if (RateLimiter::tooManyAttempts($limiterKey, $max)) {
            throw WorkforceException::faceAttemptsExceeded($lockMinutes);
        }

        $this->assertValidEmbedding($embedding);

        // Verifica toda plantilla registrada con consentimiento y autorización
        // que no esté revocada; nunca una heredada sin consentimiento ni una
        // revocada (contrato §7.3). Un código de enrolamiento vivo NO la
        // invalida: hasta 2026-10-19 la dejaba sin verificar mientras el código
        // vivía, y un código emitido por error («para la clase») bloqueaba un
        // rostro bueno y obligaba a pedir el código de respaldo.
        if ($ref === null || ! $ref->isValidated()) {
            throw WorkforceException::biometricNotEnrolled($this->status($ref));
        }

        // El intento se cuenta ANTES de comparar (incremento atómico): varias
        // peticiones en paralelo no cuelan más de `max` comparaciones (revisión
        // de seguridad 2026-10-11, F8). Si coincide, el contador se limpia.
        if (RateLimiter::hit($limiterKey, $lockMinutes * 60) > $max) {
            throw WorkforceException::faceAttemptsExceeded($lockMinutes);
        }

        $distance = FaceVector::distance((array) $ref->vector(), $embedding);
        if ($distance === null || $distance > $this->threshold()) {
            $this->audit(self::EVENT_VERIFY_FAILED, $ref, ['distance' => $distance !== null ? round($distance, 4) : null], $request);

            throw WorkforceException::faceNoMatch();
        }

        RateLimiter::clear($limiterKey);
        $ref->forceFill(['last_verified_at' => now()])->saveQuietly();

        return $distance;
    }

    // ── Registro ────────────────────────────────────────────────────────────

    /**
     * Enrolamiento autorizado (kiosko): varias capturas coherentes, ningún otro
     * dueño del rostro, consentimiento registrado. Reemplaza una plantilla
     * previa de la MISMA persona solo porque un administrador lo autorizó (el
     * código ya se consumió al empezar).
     *
     * @param  list<mixed>  $embeddings
     * @param  array{device_id:?int, admin_id:?int, admin_name:?string, consent_version:string}  $context
     */
    public function enroll(Identity $identity, array $embeddings, array $context, ?Request $request = null): TrainerFaceReference
    {
        $template = $this->precheckEnrollment($identity, $embeddings, $request);
        $existing = $this->referenceForIdentity($identity);

        $trainerId = $existing?->trainer_id
            ?? Trainer::query()->where('identity_id', $identity->getKey())
                ->whereNotIn('id', TrainerFaceReference::query()->whereNotNull('trainer_id')->select('trainer_id'))
                ->orderBy('id')->value('id');

        $ref = $existing ?? new TrainerFaceReference;
        $ref->fill([
            'identity_id' => $identity->getKey(),
            'trainer_id' => $trainerId,
            'status' => TrainerFaceReference::STATUS_ACTIVE,
            'enrolled_at' => now(),
            'template_version' => (string) config('workforce.face.template_version'),
            'enrolled_via' => TrainerFaceReference::VIA_KIOSK,
            'enrolled_device_id' => $context['device_id'],
            'enrolled_by_admin_id' => $context['admin_id'],
            'enrolled_by_name' => $context['admin_name'],
            'consent_version' => $context['consent_version'],
            'consent_accepted_at' => now(),
            'last_verified_at' => now(),
            'revoked_at' => null,
            'revoked_by_admin_id' => null,
            'revoked_by_name' => null,
            'revoked_reason' => null,
        ]);
        $ref->storeVector($template);
        $ref->save();

        $this->audit(self::EVENT_ENROLLED, $ref, [
            'via' => TrainerFaceReference::VIA_KIOSK,
            'device_id' => $context['device_id'],
            'authorized_by' => $context['admin_name'],
            'consent_version' => $context['consent_version'],
            'replaced_previous' => $existing !== null,
            'captures' => count($embeddings),
        ], $request);
        $this->events->publish(WorkforceEvents::BIOMETRIC_UPDATED, 'enrolled', (string) $identity->uuid, WorkforceEvents::SOURCE_KIOSK);

        return $ref;
    }

    /**
     * Comprobaciones del enrolamiento (capturas válidas y coherentes, rostro que
     * no sea de otra persona) SIN escribir la plantilla. Devuelve la plantilla.
     *
     * El kiosko la llama ANTES de abrir la transacción del enrolamiento: si el
     * rostro ya es de otra persona, la traza `biometric.duplicate_rejected` se
     * confirma aunque la operación se rechace (dentro de la transacción, el
     * rollback la borraba). `enroll()` la repite dentro: es la red ante carreras.
     *
     * @param  list<mixed>  $embeddings
     * @return list<float>
     */
    public function precheckEnrollment(Identity $identity, array $embeddings, ?Request $request = null): array
    {
        if (count($embeddings) < 3 || count($embeddings) > 5) {
            throw WorkforceException::embeddingInvalid();
        }
        foreach ($embeddings as $e) {
            $this->assertValidEmbedding($e);
        }
        if (FaceVector::maxPairwiseDistance($embeddings) > (float) config('workforce.face.enrollment_consistency', 0.9)) {
            throw WorkforceException::embeddingsInconsistent();
        }

        $template = FaceVector::template($embeddings);
        $this->assertNotLinkedToSomeoneElse($template, $identity, $this->referenceForIdentity($identity), $request);

        return $template;
    }

    /**
     * Autoenrolamiento desde el portal del entrenador (app 2.0.x). Solo el
     * PRIMER registro y solo si la política lo permite; nunca sobrescribe.
     */
    public function enrollLegacyTrainer(Trainer $trainer, mixed $embedding, ?Request $request = null): TrainerFaceReference
    {
        $existing = $this->referenceForTrainer($trainer);
        if ($existing !== null && $existing->isActive()) {
            throw WorkforceException::faceAlreadyEnrolled();
        }
        if ($existing !== null || config('workforce.legacy.self_enrollment') !== 'first_only') {
            throw WorkforceException::enrollmentRequiresAuthorization();
        }

        $this->assertValidEmbedding($embedding);
        $template = FaceVector::template([$embedding]);
        $identity = $trainer->identity_id ? Identity::find($trainer->identity_id) : null;
        $this->assertNotLinkedToSomeoneElse($template, $identity, null, $request, $trainer);

        $ref = new TrainerFaceReference([
            'trainer_id' => $trainer->getKey(),
            'identity_id' => $identity?->getKey(),
            'status' => TrainerFaceReference::STATUS_ACTIVE,
            'enrolled_at' => now(),
            'template_version' => (string) config('workforce.face.template_version'),
            'enrolled_via' => TrainerFaceReference::VIA_TRAINER_PORTAL,
        ]);
        $ref->storeVector($template);
        $ref->save();

        $this->audit(self::EVENT_ENROLLED, $ref, ['via' => TrainerFaceReference::VIA_TRAINER_PORTAL], $request);
        if ($identity !== null) {
            $this->events->publish(WorkforceEvents::BIOMETRIC_UPDATED, 'enrolled', (string) $identity->uuid, WorkforceEvents::SOURCE_TRAINER);
        }

        return $ref;
    }

    // ── Revocación ──────────────────────────────────────────────────────────

    /**
     * Revoca y BORRA la plantilla. La fila queda como evidencia (quién, cuándo,
     * por qué); el vector no. Revocar una ya revocada no hace nada.
     */
    public function revoke(TrainerFaceReference $ref, string $reason, ?int $adminId, string $actorName, string $source, ?Request $request = null): bool
    {
        if (! $ref->isActive()) {
            return false;
        }

        $ref->fill([
            'status' => TrainerFaceReference::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by_admin_id' => $adminId,
            'revoked_by_name' => mb_substr($actorName, 0, 120),
            'revoked_reason' => mb_substr($reason, 0, 500),
        ]);
        $ref->eraseVector();
        $ref->save();

        $this->audit(self::EVENT_REVOKED, $ref, ['reason' => $reason, 'by' => $actorName], $request, $adminId);
        if ($ref->identity_id !== null) {
            $uuid = (string) Identity::query()->whereKey($ref->identity_id)->value('uuid');
            $this->events->publish(WorkforceEvents::BIOMETRIC_UPDATED, 'revoked', $uuid, $source);
        }

        return true;
    }

    // ── Interno ─────────────────────────────────────────────────────────────

    private function threshold(): float
    {
        return (float) config('trainer.face.match_threshold', 1.0);
    }

    /**
     * 1:N: ningún OTRO rostro activo puede estar dentro del umbral.
     *
     * @param  list<float>  $template
     */
    private function assertNotLinkedToSomeoneElse(array $template, ?Identity $identity, ?TrainerFaceReference $own, ?Request $request, ?Trainer $trainer = null): void
    {
        $others = TrainerFaceReference::query()->active()
            ->when($own !== null, fn ($q) => $q->whereKeyNot($own->getKey()))
            ->when($identity !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('identity_id')->orWhere('identity_id', '!=', $identity->getKey())))
            ->when($trainer !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('trainer_id')->orWhere('trainer_id', '!=', $trainer->getKey())))
            ->get();

        foreach ($others as $other) {
            $d = FaceVector::distance((array) $other->vector(), $template);
            if ($d !== null && $d <= $this->threshold()) {
                $this->audit(self::EVENT_DUPLICATE_REJECTED, null, ['identity_id' => $identity?->getKey(), 'trainer_id' => $trainer?->getKey()], $request);

                throw WorkforceException::faceAlreadyLinked();
            }
        }
    }

    private function pendingEnrollmentCode(int $identityId): bool
    {
        return WorkforceAuthorizationCode::query()
            ->where('identity_id', $identityId)
            ->where('purpose', WorkforceAuthorizationCode::PURPOSE_ENROLLMENT)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Historial biométrico de la persona en la bitácora del portal (la misma
     * que ya guarda logins y enrolamientos de entrenadores).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function audit(string $event, ?TrainerFaceReference $ref, array $metadata, ?Request $request = null, ?int $adminId = null): void
    {
        $request ??= request();
        TrainerAuditLog::create([
            'actor_type' => $adminId !== null ? TrainerAuditLog::ACTOR_ADMIN : TrainerAuditLog::ACTOR_SYSTEM,
            'actor_id' => $adminId,
            'trainer_id' => $ref?->trainer_id,
            'identity_id' => $ref?->identity_id ?? ($metadata['identity_id'] ?? null),
            'event' => $event,
            'metadata' => $metadata ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 512) : null,
            'created_at' => now(),
        ]);
    }
}
