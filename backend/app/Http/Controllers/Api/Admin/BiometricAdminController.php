<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuthorizedDevice;
use App\Models\Identity;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\WorkforceAuthorizationCode;
use App\Services\Audit\AuditTrail;
use App\Services\Workforce\AuthorizationCodeService;
use App\Services\Workforce\FaceReferenceService;
use App\Services\Workforce\WorkforceEvents;
use App\Support\Access\AdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión biométrica desde el CRM (contrato §7.3). Consultar: `biometrics.view`.
 * Autorizar un enrolamiento o revocar: `biometrics.manage`.
 *
 * Nunca devuelve la plantilla. Revocar la BORRA y deja la traza. La app no
 * puede borrar una biometría: solo el CRM, con permiso y motivo.
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
    ];

    public function __construct(
        private readonly FaceReferenceService $faces,
        private readonly AuthorizationCodeService $codes,
        private readonly AuditTrail $auditTrail,
    ) {}

    public function show(string $identity): JsonResponse
    {
        $person = Identity::query()->where('uuid', $identity)->firstOrFail();
        $ref = $this->faces->referenceForIdentity($person);
        $trainerIds = Trainer::query()->where('identity_id', $person->getKey())->pluck('id');

        $history = TrainerAuditLog::query()
            ->where(fn ($q) => $q->where('identity_id', $person->getKey())->orWhereIn('trainer_id', $trainerIds))
            ->whereIn('event', array_keys(self::HISTORY_LABELS))
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (TrainerAuditLog $l) => [
                'event' => $l->event,
                'label' => self::HISTORY_LABELS[$l->event] ?? $l->event,
                'at' => $l->created_at?->toIso8601String(),
                'actor' => $l->metadata['by'] ?? ($l->actor_type === TrainerAuditLog::ACTOR_ADMIN ? 'Administrador' : 'Sistema'),
                'detail' => $l->metadata['reason'] ?? ($l->metadata['purpose'] ?? ($l->metadata['via'] ?? null)),
            ]);

        return response()->json(['ok' => true, 'data' => [
            'status' => $this->faces->status($ref),
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
        ]]);
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
