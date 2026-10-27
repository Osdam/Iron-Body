<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\WorkforceException;
use App\Http\Controllers\Controller;
use App\Models\AuthorizedDevice;
use App\Models\FaceLookalike;
use App\Models\Identity;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\WorkforceAuthorizationCode;
use App\Models\WorkforcePin;
use App\Services\Audit\AuditTrail;
use App\Services\Workforce\AuthorizationCodeService;
use App\Services\Workforce\EmployeeDirectory;
use App\Services\Workforce\FaceReferenceService;
use App\Services\Workforce\LookalikeRegistry;
use App\Services\Workforce\WorkforceEvents;
use App\Services\Workforce\WorkforcePins;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión biométrica desde el CRM (contrato §7.3 y §10.4). Consultar:
 * `biometrics.view`. Autorizar un enrolamiento o un PIN, revocar, anular un PIN
 * o conceder una excepción de parecido: `biometrics.manage`.
 *
 * Nunca devuelve la plantilla ni el PIN. Revocar la BORRA y deja la traza. La
 * app no puede borrar una biometría: solo el CRM, con permiso y motivo.
 */
class BiometricAdminController extends Controller
{
    private const HISTORY_LABELS = [
        FaceReferenceService::EVENT_ENROLLED => 'Rostro registrado',
        FaceReferenceService::EVENT_REVOKED => 'Biometría revocada',
        FaceReferenceService::EVENT_CODE_ISSUED => 'Código de autorización generado',
        FaceReferenceService::EVENT_VERIFY_FAILED => 'Verificación fallida',
        FaceReferenceService::EVENT_DUPLICATE_REJECTED => 'Registro rechazado: rostro de otra persona',
        FaceReferenceService::EVENT_CODE_REJECTED => 'Código rechazado',
        FaceReferenceService::EVENT_VERIFY_AMBIGUOUS => 'Rostro ambiguo: pidió PIN',
        FaceReferenceService::EVENT_PIN_SET => 'PIN creado',
        FaceReferenceService::EVENT_PIN_FAILED => 'PIN equivocado',
        FaceReferenceService::EVENT_PIN_LOCKED => 'PIN bloqueado',
        FaceReferenceService::EVENT_PIN_REVOKED => 'PIN anulado',
        FaceReferenceService::EVENT_LOOKALIKE_DETECTED => 'Parecido biométrico detectado',
        FaceReferenceService::EVENT_LOOKALIKE_EXCEPTION_GRANTED => 'Excepción de registro autorizada',
    ];

    /** Por qué el rostro no bastó, dicho para el historial (sin con quién). */
    private const AMBIGUITY_LABELS = [
        FaceReferenceService::AMBIGUITY_PAIR => 'Tiene un parecido registrado',
        FaceReferenceService::AMBIGUITY_COINCIDENCE => 'La captura también coincidía con otra persona',
        FaceReferenceService::AMBIGUITY_MARGIN => 'La captura no se separaba lo suficiente de otra persona',
    ];

    public function __construct(
        private readonly FaceReferenceService $faces,
        private readonly AuthorizationCodeService $codes,
        private readonly AuditTrail $auditTrail,
        private readonly WorkforcePins $pins,
        private readonly LookalikeRegistry $lookalikes,
        private readonly EmployeeDirectory $directory,
    ) {}

    public function show(Request $request, string $identity): JsonResponse
    {
        $person = Identity::query()->where('uuid', $identity)->firstOrFail();
        $ref = $this->faces->referenceForIdentity($person);
        $trainerIds = Trainer::query()->where('identity_id', $person->getKey())->pluck('id');
        // Con quién se parece solo lo ve quien gestiona la biometría: hace
        // falta para comprobar en persona a las dos antes de una excepción.
        $canManage = CrmPermission::allows(AdminActor::from($request), 'biometrics.manage');

        // Los eventos de parecido salen en el mismo segundo en las dos
        // personas: con la hora, cualquiera deduciría la pareja (revisión
        // 2026-10-27). Solo para quien gestiona la biometría.
        $events = array_keys(self::HISTORY_LABELS);
        if (! $canManage) {
            $events = array_values(array_diff($events, [FaceReferenceService::EVENT_LOOKALIKE_DETECTED, FaceReferenceService::EVENT_LOOKALIKE_EXCEPTION_GRANTED]));
        }
        $history = TrainerAuditLog::query()
            ->where(fn ($q) => $q->where('identity_id', $person->getKey())->orWhereIn('trainer_id', $trainerIds))
            ->whereIn('event', $events)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (TrainerAuditLog $l) => [
                'event' => $l->event,
                'label' => self::HISTORY_LABELS[$l->event] ?? $l->event,
                'at' => $l->created_at?->toIso8601String(),
                'actor' => $l->metadata['by'] ?? ($l->actor_type === TrainerAuditLog::ACTOR_ADMIN ? 'Administrador' : 'Sistema'),
                // El motivo de una excepción suele nombrar a la otra persona.
                'detail' => $l->event === FaceReferenceService::EVENT_LOOKALIKE_EXCEPTION_GRANTED && ! $canManage
                    ? null
                    : ($l->metadata['reason'] ?? ($l->metadata['purpose'] ?? ($l->metadata['via']
                        ?? (self::AMBIGUITY_LABELS[$l->metadata['cause'] ?? ''] ?? null)))),
            ]);

        $pin = $this->pins->find((int) $person->getKey());
        $lookalikes = $canManage
            ? $this->lookalikes->pairsOf((int) $person->getKey())->map(function (FaceLookalike $pair) use ($person): array {
                $other = Identity::query()->findOrFail($pair->otherThan((int) $person->getKey()));

                return [
                    'identity' => $other->uuid,
                    'name' => $this->directory->profile($other)->fullName,
                    'since' => $pair->first_detected_at?->toIso8601String(),
                    'exception_expires_at' => $this->lookalikes->liveException((int) $person->getKey(), (int) $other->getKey())?->expires_at->toIso8601String(),
                ];
            })->values()
            : [];

        return response()->json(['ok' => true, 'data' => [
            'status' => $this->faces->status($ref),
            'verifiable' => $this->faces->verifiable($ref),
            'enrolled_at' => $ref?->enrolled_at?->toIso8601String(),
            'updated_at' => $ref?->updated_at?->toIso8601String(),
            'enrolled_via' => $ref?->enrolled_via,
            'enrolled_by' => $ref?->enrolled_by_name,
            'enrolled_device' => $ref?->enrolled_device_id
                ? AuthorizedDevice::query()->find($ref->enrolled_device_id)?->toBrief()
                : null,
            'template_version' => $ref?->template_version,
            'consent_version' => $ref?->consent_version,
            'consent_accepted_at' => $ref?->consent_accepted_at?->toIso8601String(),
            'last_verified_at' => $ref?->last_verified_at?->toIso8601String(),
            'revoked' => $ref?->revoked_at ? [
                'at' => $ref->revoked_at->toIso8601String(),
                'by' => $ref->revoked_by_name,
                'reason' => $ref->revoked_reason,
            ] : null,
            'pending_codes' => $this->codes->pending($person)->map(fn (WorkforceAuthorizationCode $c) => [
                'purpose' => $c->purpose,
                'expires_at' => $c->expires_at->toIso8601String(),
                'created_by' => $c->created_by_name,
            ])->values(),
            'history' => $history,
            'pin' => [
                'status' => $pin?->status() ?? WorkforcePin::STATUS_NOT_SET,
                'set_at' => $pin?->set_at?->toIso8601String(),
                'locked_until' => $pin?->status() === WorkforcePin::STATUS_LOCKED ? $pin->locked_until?->toIso8601String() : null,
            ],
            'second_factor_always' => $this->lookalikes->isPaired((int) $person->getKey()),
            'lookalikes' => $lookalikes,
        ]]);
    }

    /** Autoriza crear o cambiar el PIN en la tablet (contrato §10.4). */
    public function pinCode(Request $request, string $identity): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $person = Identity::query()->where('uuid', $identity)->firstOrFail();

        $issued = $this->codes->issue($person, WorkforceAuthorizationCode::PURPOSE_PIN_SETUP, $data['reason'], AdminActor::id($request), AdminActor::label($request));
        $this->faces->audit(FaceReferenceService::EVENT_CODE_ISSUED, null, [
            'identity_id' => $person->getKey(),
            'purpose' => WorkforceAuthorizationCode::PURPOSE_PIN_SETUP,
            'reason' => $data['reason'],
            'by' => AdminActor::label($request),
        ], $request, AdminActor::id($request));
        app(WorkforceEvents::class)->publish(WorkforceEvents::BIOMETRIC_UPDATED, 'code_issued', $person->uuid, WorkforceEvents::SOURCE_CRM);
        $this->auditTrail->record($request, [
            'action' => 'create',
            'module' => 'biometrics',
            'entity' => 'authorization_code',
            'entity_id' => (string) $issued['model']->getKey(),
            'summary' => 'Autorizó crear un PIN',
            'metadata' => ['identity' => $person->uuid],
        ]);

        return response()->json([
            'ok' => true,
            'code' => $issued['code'],
            'expires_at' => $issued['model']->expires_at->toIso8601String(),
        ], 201);
    }

    public function revokePin(Request $request, string $identity): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $person = Identity::query()->where('uuid', $identity)->firstOrFail();

        if (! $this->pins->revoke((int) $person->getKey(), $data['reason'], AdminActor::id($request), AdminActor::label($request))) {
            throw WorkforceException::pinNotActive();
        }
        $this->faces->audit(FaceReferenceService::EVENT_PIN_REVOKED, null, [
            'identity_id' => $person->getKey(),
            'reason' => $data['reason'],
            'by' => AdminActor::label($request),
        ], $request, AdminActor::id($request));
        app(WorkforceEvents::class)->publish(WorkforceEvents::BIOMETRIC_UPDATED, 'pin_revoked', $person->uuid, WorkforceEvents::SOURCE_CRM);
        $this->auditTrail->record($request, [
            'action' => 'delete',
            'module' => 'biometrics',
            'entity' => 'pin',
            'entity_id' => (string) $person->uuid,
            'summary' => 'Anuló un PIN',
            'metadata' => ['identity' => $person->uuid, 'reason' => $data['reason']],
        ]);

        return response()->json(['ok' => true, 'data' => ['status' => WorkforcePin::STATUS_REVOKED]]);
    }

    /**
     * Excepción presencial para registrar el rostro de esta persona pese a su
     * parecido con la otra del par (contrato §10.4/§10.5). Solo enrolamiento:
     * nunca afecta a la verificación, y las dos quedan con rostro + PIN.
     */
    public function lookalikeException(Request $request, string $identity): JsonResponse
    {
        // Las dos confirmaciones presenciales, exactamente `true` (no «yes»,
        // «on» ni 1): son lo que el administrador firma.
        $strictTrue = fn (string $attribute, mixed $value, \Closure $fail) => $value === true
            || $fail('Hay que confirmar la comprobación en persona.');
        $data = $request->validate([
            'other_identity' => ['required', 'uuid'],
            'subject_verified_in_person' => ['required', $strictTrue],
            'other_verified_in_person' => ['required', $strictTrue],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $subject = Identity::query()->where('uuid', $identity)->firstOrFail();
        $other = Identity::query()->where('uuid', $data['other_identity'])->firstOrFail();
        if ($subject->is($other)) {
            throw WorkforceException::lookalikeNotRegistered();
        }
        if (! $this->faces->verifiable($this->faces->referenceForIdentity($other))) {
            throw WorkforceException::lookalikeOtherNotEnrolled();
        }

        $exception = $this->lookalikes->grantException($subject, $other, $data['reason'], AdminActor::id($request), AdminActor::label($request));

        // En el historial de las DOS personas, sin plantilla (la columna
        // trainer_id queda vacía: la auditoría del entrenador no lo enseña).
        foreach ([$subject, $other] as $person) {
            $this->faces->audit(FaceReferenceService::EVENT_LOOKALIKE_EXCEPTION_GRANTED, null, [
                'identity_id' => $person->getKey(),
                'reason' => $data['reason'],
                'by' => AdminActor::label($request),
            ], $request, AdminActor::id($request));
        }
        $this->auditTrail->record($request, [
            'action' => 'create',
            'module' => 'biometrics',
            'entity' => 'lookalike_exception',
            'entity_id' => (string) $exception->getKey(),
            'summary' => 'Autorizó registrar un rostro pese a un parecido biométrico, tras comprobar en persona a las dos personas',
            'metadata' => ['identity' => $subject->uuid, 'other_identity' => $other->uuid, 'reason' => $data['reason']],
        ]);

        return response()->json(['ok' => true, 'exception' => ['expires_at' => $exception->expires_at->toIso8601String()]], 201);
    }

    public function enrollmentCode(Request $request, string $identity): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $person = Identity::query()->where('uuid', $identity)->firstOrFail();

        $issued = $this->codes->issue($person, WorkforceAuthorizationCode::PURPOSE_ENROLLMENT, $data['reason'], AdminActor::id($request), AdminActor::label($request));
        $this->faces->audit(FaceReferenceService::EVENT_CODE_ISSUED, $this->faces->referenceForIdentity($person), [
            'identity_id' => $person->getKey(),
            'purpose' => WorkforceAuthorizationCode::PURPOSE_ENROLLMENT,
            'reason' => $data['reason'],
            'by' => AdminActor::label($request),
        ], $request, AdminActor::id($request));
        app(WorkforceEvents::class)->publish(WorkforceEvents::BIOMETRIC_UPDATED, 'code_issued', $person->uuid, WorkforceEvents::SOURCE_CRM);
        $this->auditTrail->record($request, [
            'action' => 'create',
            'module' => 'biometrics',
            'entity' => 'authorization_code',
            'entity_id' => (string) $issued['model']->getKey(),
            'summary' => 'Autorizó un registro de rostro',
            'metadata' => ['identity' => $person->uuid],
        ]);

        return response()->json([
            'ok' => true,
            'code' => $issued['code'],
            'expires_at' => $issued['model']->expires_at->toIso8601String(),
        ], 201);
    }

    public function revoke(Request $request, string $identity): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $person = Identity::query()->where('uuid', $identity)->firstOrFail();
        $ref = $this->faces->referenceForIdentity($person);

        if ($ref === null || ! $ref->isActive()) {
            return response()->json(['ok' => false, 'code' => 'biometric_not_active', 'message' => 'Esta persona no tiene una biometría activa.'], 409);
        }

        $this->faces->revoke($ref, $data['reason'], AdminActor::id($request), AdminActor::label($request), WorkforceEvents::SOURCE_CRM, $request);
        $this->auditTrail->record($request, [
            'action' => 'delete',
            'module' => 'biometrics',
            'entity' => 'face_reference',
            'entity_id' => (string) $ref->getKey(),
            'summary' => 'Revocó una biometría (plantilla borrada)',
            'metadata' => ['identity' => $person->uuid, 'reason' => $data['reason']],
        ]);

        return response()->json(['ok' => true, 'data' => ['status' => FaceReferenceService::STATUS_REVOKED, 'revoked_at' => now()->toIso8601String()]]);
    }
}
