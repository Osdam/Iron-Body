<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\FaceDuplicateMatch;
use App\Models\Identity;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\TrainerFaceReference;
use App\Models\WorkforceAuthorizationCode;
use App\Models\WorkforcePin;
use App\Support\Workforce\FaceMatch;
use App\Support\Workforce\FaceVector;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * La plantilla facial de una persona: registrarla, compararla, revocarla.
 *
 * El rostro se compara AQUÍ, nunca en el teléfono: la app manda el embedding de
 * la captura y el servidor decide. La plantilla no sale nunca del servidor.
 *
 * Defensas:
 *  · norma del vector: un vector casi nulo coincidía con cualquiera;
 *  · nunca se sobrescribe una plantilla activa: cambiarla exige que un
 *    administrador lo autorice;
 *  · 1:N al registrar: un rostro que ya es de otra persona no se vincula;
 *  · 1:N al verificar (contrato §10.1): si el rostro no separa a la persona de
 *    otra (par de parecidos, coincidencia o margen), el rostro solo no
 *    autoriza: hace falta su PIN.
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

    /** El rostro coincidió pero no basta: pidió el PIN. Sin con quién ni a qué distancia. */
    public const EVENT_VERIFY_AMBIGUOUS = 'biometric.verify_ambiguous';

    public const EVENT_PIN_SET = 'biometric.pin_set';

    public const EVENT_PIN_FAILED = 'biometric.pin_failed';

    public const EVENT_PIN_LOCKED = 'biometric.pin_locked';

    public const EVENT_PIN_REVOKED = 'biometric.pin_revoked';

    public const EVENT_LOOKALIKE_DETECTED = 'biometric.lookalike_detected';

    public const EVENT_LOOKALIKE_EXCEPTION_GRANTED = 'biometric.lookalike_exception_granted';

    /** Por qué un rostro que coincide no basta (contrato §10.1). */
    public const AMBIGUITY_PAIR = 'pair';

    public const AMBIGUITY_COINCIDENCE = 'coincidence';

    public const AMBIGUITY_MARGIN = 'margin';

    public function __construct(
        private readonly WorkforceEvents $events,
        private readonly LookalikeRegistry $lookalikes,
        private readonly WorkforcePins $pins,
    ) {}

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

    /**
     * ¿Verifica esta plantilla? Con consentimiento y sin revocar. Puede ser
     * cierto en `renewal_pending` (renovación autorizada): la tablet y el CRM
     * lo usan para no tratar como inservible un rostro que funciona.
     */
    public function verifiable(?TrainerFaceReference $ref): bool
    {
        return $ref !== null && $ref->isValidated();
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
     * Verifica que el rostro es el de la persona y que la distingue de las
     * demás. Lanza: no enrolada, bloqueada por intentos, no coincide
     * (uniforme) o, si el rostro no basta, pide el PIN (contrato §10.1).
     *
     * Un PIN que llega sin hacer falta se comprueba igual: un PIN equivocado
     * nunca pasa.
     */
    public function verify(?TrainerFaceReference $ref, mixed $embedding, string $attemptKey, ?Request $request = null, ?string $pin = null): FaceMatch
    {
        [$ref, $distance, $limiterKey] = $this->matchOwn($ref, $embedding, $attemptKey, $request);

        // El rostro coincide; ¿lo separa de las demás personas? Si no, el
        // intento cuenta (no se limpia el contador) y hace falta el PIN.
        $ambiguity = $this->ambiguity($ref, $embedding, $distance);
        if ($ambiguity !== null || $pin !== null) {
            $identityId = $ref->identity_id !== null ? (int) $ref->identity_id : null;
            if ($pin === null) {
                $this->audit(self::EVENT_VERIFY_AMBIGUOUS, $ref, ['distance' => round($distance, 4), 'cause' => $ambiguity], $request);
                $current = $identityId !== null ? $this->pins->find($identityId) : null;

                throw WorkforceException::faceSecondFactorRequired($current?->status() ?? WorkforcePin::STATUS_NOT_SET, $current?->locked_until);
            }
            $this->assertPin($ref, $identityId, $pin, $request);
        }

        RateLimiter::clear($limiterKey);
        $ref->forceFill(['last_verified_at' => now()])->saveQuietly();

        return new FaceMatch($distance, $pin !== null);
    }

    /**
     * «El rostro coincide con su plantilla validada y ninguna otra está más
     * cerca», sin PIN: para crear el PIN, donde el factor presencial es el
     * código del administrador (contrato §10.2). Lo segundo frena a la otra
     * persona de un par que ya tiene rostro registrado (revisión 2026-10-27,
     * B-1). Mismos intentos limitados. NO autoriza ninguna operación sola.
     */
    public function assertWithinThreshold(?TrainerFaceReference $ref, mixed $embedding, string $attemptKey, ?Request $request = null): float
    {
        [$ref, $distance, $limiterKey] = $this->matchOwn($ref, $embedding, $attemptKey, $request);
        if ($this->nearestOther($ref, $embedding) < $distance) {
            throw WorkforceException::faceNoMatch();
        }
        RateLimiter::clear($limiterKey);

        return $distance;
    }

    /**
     * Rostro válido, plantilla que verifica, intentos limitados y distancia
     * dentro del umbral; si no, lanza (y el fallo queda en la traza).
     *
     * @return array{0: TrainerFaceReference, 1: float, 2: string}
     */
    private function matchOwn(?TrainerFaceReference $ref, mixed $embedding, string $attemptKey, ?Request $request): array
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
        if ($ref === null || ! $this->verifiable($ref)) {
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

        return [$ref, $distance, $limiterKey];
    }

    /**
     * ¿Por qué este rostro, que coincide con su plantilla, no basta? Null si
     * basta. Se compara contra TODAS las plantillas activas de otras personas
     * (también las heredadas: más conservador), nunca contra las revocadas.
     */
    private function ambiguity(TrainerFaceReference $ref, array $embedding, float $distance): ?string
    {
        if ($ref->identity_id !== null && $this->lookalikes->isPaired((int) $ref->identity_id)) {
            return self::AMBIGUITY_PAIR;
        }

        $nearest = $this->nearestOther($ref, $embedding);
        if ($nearest <= $this->threshold()) {
            return self::AMBIGUITY_COINCIDENCE;
        }
        if ($nearest - $distance < (float) config('workforce.face.ambiguity_margin', 0.20)) {
            return self::AMBIGUITY_MARGIN;
        }

        return null;
    }

    /**
     * Distancia de la captura a la plantilla activa más cercana de OTRA
     * persona (INF si no hay ninguna). No cuentan las de la misma persona,
     * tampoco una heredada solo con su ficha de entrenador.
     */
    private function nearestOther(TrainerFaceReference $ref, array $embedding): float
    {
        $ownTrainers = $ref->identity_id !== null
            ? Trainer::query()->where('identity_id', $ref->identity_id)->pluck('id')->push($ref->trainer_id)->filter()->all()
            : array_filter([$ref->trainer_id]);
        $others = TrainerFaceReference::query()->active()
            ->whereKeyNot($ref->getKey())
            ->when($ref->identity_id !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('identity_id')->orWhere('identity_id', '!=', $ref->identity_id)))
            ->where(fn ($q) => $q->whereNotNull('identity_id')->orWhereNull('trainer_id')->orWhereNotIn('trainer_id', $ownTrainers ?: [0]))
            ->get();

        $nearest = INF;
        foreach ($others as $other) {
            $d = FaceVector::distance((array) $other->vector(), $embedding);
            if ($d !== null) {
                $nearest = min($nearest, $d);
            }
        }

        return $nearest;
    }

    /** Comprueba el PIN que acompaña al rostro; deja la traza y lanza si no vale. */
    private function assertPin(TrainerFaceReference $ref, ?int $identityId, string $pin, ?Request $request): void
    {
        if ($identityId === null) {
            throw WorkforceException::pinNotSet();
        }
        $outcome = $this->pins->check($identityId, $pin);

        match ($outcome['result']) {
            WorkforcePins::OK => null,
            WorkforcePins::NOT_SET => throw WorkforceException::pinNotSet(),
            WorkforcePins::LOCKED => $this->pinLocked($ref, $outcome, $request),
            default => $this->pinFailed($ref, $request),
        };
    }

    /**
     * Quien prueba un PIN ajeno es quien se parece al titular: cada fallo
     * queda en su historial y avisa al CRM abierto (revisión 2026-10-27, M-1).
     */
    private function pinFailed(TrainerFaceReference $ref, ?Request $request): never
    {
        $this->audit(self::EVENT_PIN_FAILED, $ref, [], $request);
        $this->publishPinEvent($ref, 'pin_failed');

        throw WorkforceException::pinInvalid();
    }

    /** @param array{locked_until:?Carbon, just_locked:bool} $outcome */
    private function pinLocked(TrainerFaceReference $ref, array $outcome, ?Request $request): never
    {
        if ($outcome['just_locked']) {
            $this->audit(self::EVENT_PIN_LOCKED, $ref, ['until' => $outcome['locked_until']?->toIso8601String()], $request);
            $this->publishPinEvent($ref, 'pin_locked');
        }

        throw WorkforceException::pinLocked($outcome['locked_until'] ?? now());
    }

    private function publishPinEvent(TrainerFaceReference $ref, string $reason): void
    {
        if ($ref->identity_id !== null) {
            $uuid = (string) Identity::query()->whereKey($ref->identity_id)->value('uuid');
            $this->events->publish(WorkforceEvents::BIOMETRIC_UPDATED, $reason, $uuid, WorkforceEvents::SOURCE_KIOSK);
        }
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
        [$template, $exceptions] = $this->checkEnrollment($identity, $embeddings, $request);
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
        // Dentro de la transacción del kiosko: si la excepción ya no está viva,
        // se deshace también la plantilla.
        $this->lookalikes->consumeExceptions($exceptions, (int) $ref->getKey());

        $metadata = [
            'via' => TrainerFaceReference::VIA_KIOSK,
            'device_id' => $context['device_id'],
            'authorized_by' => $context['admin_name'],
            'consent_version' => $context['consent_version'],
            'replaced_previous' => $existing !== null,
            'captures' => count($embeddings),
        ];
        if ($exceptions !== []) {
            $metadata['lookalike_exceptions'] = $exceptions;
        }
        $this->audit(self::EVENT_ENROLLED, $ref, $metadata, $request);
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
        return $this->checkEnrollment($identity, $embeddings, $request)[0];
    }

    /**
     * @param  list<mixed>  $embeddings
     * @return array{0: list<float>, 1: list<int>} la plantilla y las excepciones de parecido usadas
     */
    private function checkEnrollment(Identity $identity, array $embeddings, ?Request $request): array
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
        $exceptions = $this->assertNotLinkedToSomeoneElse($template, $identity, $this->referenceForIdentity($identity), $request);

        return [$template, $exceptions];
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
        // Sin excepciones de parecido: solo valen en el kiosko, con código y consentimiento.
        $this->assertNotLinkedToSomeoneElse($template, $identity, null, $request, $trainer, allowExceptions: false);

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
        // Las distancias guardadas contra esta plantilla salen de ella: se van con ella.
        FaceDuplicateMatch::query()->where('face_reference_id', $ref->getKey())->delete();

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
     * Compara contra todas antes de rechazar (antes paraba en la primera) para
     * dejar en `face_duplicate_matches` CADA plantilla que coincide y su
     * distancia. La respuesta no lo dice: quien registra un rostro no debe
     * saber con quién se parece.
     *
     * Cada persona con la que coincide queda en un par de parecidos (§10.5):
     * desde ahí, las dos necesitan rostro + PIN. Con una excepción presencial
     * viva para ese par se ignora SOLO la plantilla de la otra persona, y solo
     * si la distancia es al menos la mínima (más cerca es la misma cara).
     * Devuelve las excepciones que se usaron, para consumirlas al guardar.
     *
     * @param  list<float>  $template
     * @return list<int>
     */
    private function assertNotLinkedToSomeoneElse(array $template, ?Identity $identity, ?TrainerFaceReference $own, ?Request $request, ?Trainer $trainer = null, bool $allowExceptions = true): array
    {
        $others = TrainerFaceReference::query()->active()
            ->when($own !== null, fn ($q) => $q->whereKeyNot($own->getKey()))
            ->when($identity !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('identity_id')->orWhere('identity_id', '!=', $identity->getKey())))
            ->when($trainer !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('trainer_id')->orWhere('trainer_id', '!=', $trainer->getKey())))
            ->get();

        $threshold = $this->threshold();
        $exceptions = $allowExceptions && $identity !== null ? $this->lookalikes->liveExceptions((int) $identity->getKey()) : [];
        $floor = (float) config('workforce.face.lookalike_exception_min_distance', 0.70);
        $matches = [];
        $matchedPeople = [];
        $used = [];
        foreach ($others as $other) {
            $d = FaceVector::distance((array) $other->vector(), $template);
            if ($d === null || $d > $threshold) {
                continue;
            }
            // Una plantilla heredada puede tener solo la ficha de entrenador: su
            // dueño es la identidad de esa ficha (prueba del tester 2026-10-27, D-1).
            $owner = $other->identity_id ?? Trainer::query()->whereKey($other->trainer_id)->value('identity_id');
            $owner = $owner !== null ? (int) $owner : null;
            if ($owner !== null && isset($exceptions[$owner]) && $d >= $floor) {
                $used[$exceptions[$owner]] = true;

                continue;
            }
            $matches[(int) $other->getKey()] = $d;
            if ($owner !== null) {
                $matchedPeople[$owner] = true;
            }
        }
        if ($matches === []) {
            return array_keys($used);
        }

        $log = $this->audit(self::EVENT_DUPLICATE_REJECTED, null, ['identity_id' => $identity?->getKey(), 'trainer_id' => $trainer?->getKey()], $request);
        // El par es un control de seguridad: si no se puede guardar, que falle
        // la petición (el rostro tampoco se registra) en vez de seguir sin él.
        if ($identity !== null) {
            foreach (array_keys($matchedPeople) as $owner) {
                if ($this->lookalikes->record((int) $identity->getKey(), $owner)) {
                    $this->audit(self::EVENT_LOOKALIKE_DETECTED, null, ['identity_id' => $identity->getKey()], $request);
                    $this->audit(self::EVENT_LOOKALIKE_DETECTED, null, ['identity_id' => $owner], $request);
                }
            }
        }
        $this->recordDuplicateMatches($log, $matches);

        throw WorkforceException::faceAlreadyLinked();
    }

    /**
     * El diagnóstico es auxiliar: si no se puede guardar, el rostro se rechaza
     * igual y con el mismo mensaje, y el fallo queda en el log.
     *
     * @param  array<int, float>  $matches  id de la plantilla => distancia
     */
    private function recordDuplicateMatches(TrainerAuditLog $log, array $matches): void
    {
        asort($matches);
        try {
            foreach ($matches as $referenceId => $distance) {
                FaceDuplicateMatch::create([
                    'audit_log_id' => $log->getKey(),
                    'face_reference_id' => $referenceId,
                    'distance' => round($distance, 4),
                    'created_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            // Solo la clase y el SQLSTATE: el mensaje de una QueryException lleva
            // los valores del INSERT (plantilla y distancia) y el log no caduca.
            Log::warning('biometric.duplicate_match_record_failed', [
                'audit_log_id' => $log->getKey(),
                'error' => class_basename($e).($e instanceof QueryException ? ' '.($e->errorInfo[0] ?? '') : ''),
            ]);
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
    public function audit(string $event, ?TrainerFaceReference $ref, array $metadata, ?Request $request = null, ?int $adminId = null): TrainerAuditLog
    {
        $request ??= request();

        return TrainerAuditLog::create([
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
