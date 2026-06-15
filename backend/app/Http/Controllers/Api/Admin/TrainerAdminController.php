<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTrainerProfessionalRequest;
use App\Http\Resources\TrainerProfessionalResource;
use App\Models\Identity;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\TrainerDeviceSession;
use App\Services\Identity\IdentityLinkService;
use App\Services\Trainer\TrainerAuditService;
use App\Services\Trainer\TrainerSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Administración del perfil PROFESIONAL de los entrenadores desde el CRM. Es
 * aditivo al CRUD existente (`TrainerController`): aquí viven roles, sede,
 * enlace de identidad, activación/desactivación y auditoría. Sigue el patrón
 * `/admin/*` del CRM (sin auth a nivel de ruta; el acceso se controla en la capa
 * del CRM). Cada mutación queda auditada.
 *
 * Desactivar un entrenador corta su acceso profesional (status inactivo ⇒ sin
 * permisos ⇒ rechazado por `auth.trainer`) pero CONSERVA su cuenta de miembro,
 * su membresía y sus valoraciones históricas. No se elimina evidencia.
 */
class TrainerAdminController extends Controller
{
    public function __construct(
        private readonly IdentityLinkService $identities,
        private readonly TrainerAuditService $audit,
        private readonly TrainerSessionService $sessions,
    ) {}

    public function show(Trainer $trainer): JsonResponse
    {
        $trainer->load('roleAssignments');

        return response()->json([
            'ok' => true,
            'data' => new TrainerProfessionalResource($trainer),
        ]);
    }

    public function updateProfessional(UpdateTrainerProfessionalRequest $request, Trainer $trainer): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($trainer, $data): void {
            $attributes = collect($data)
                ->only(['location', 'contract_type', 'main_specialty', 'specialties'])
                ->all();

            if ($attributes !== []) {
                $trainer->fill($attributes)->save();
            }

            if (array_key_exists('roles', $data)) {
                $trainer->syncRoles($data['roles']);
            }
        });

        $trainer->refresh()->load('roleAssignments');

        if (array_key_exists('roles', $data)) {
            $this->audit->record(
                TrainerAuditLog::EVENT_ROLES_UPDATED,
                $trainer,
                actorType: TrainerAuditLog::ACTOR_ADMIN,
                actorId: $data['admin_id'] ?? null,
                metadata: ['roles' => $trainer->roleNames()],
                request: $request,
            );
        }

        $this->audit->record(
            TrainerAuditLog::EVENT_PROFILE_UPDATED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_ADMIN,
            actorId: $data['admin_id'] ?? null,
            metadata: ['fields' => array_keys(collect($data)->except(['admin_id', 'roles'])->all())],
            request: $request,
        );

        return response()->json([
            'ok' => true,
            'message' => 'Perfil profesional actualizado.',
            'data' => new TrainerProfessionalResource($trainer),
        ]);
    }

    /**
     * Enlaza el entrenador a una identidad. El CRM es autoridad: el enlace por
     * documento aquí es administrativo (no es el flujo self-service que exige
     * OTP). Si se pasa `document`, se resuelve/crea la identidad; si se pasa
     * `identity_id`, se usa la existente.
     */
    public function linkIdentity(Request $request, Trainer $trainer): JsonResponse
    {
        $data = $request->validate([
            'identity_id' => ['required_without:document', 'nullable', 'integer', 'exists:identities,id'],
            'document' => ['required_without:identity_id', 'nullable', 'string', 'max:50'],
            'admin_id' => ['nullable', 'integer'],
        ]);

        $identity = isset($data['identity_id'])
            ? Identity::findOrFail($data['identity_id'])
            : $this->identities->ensureIdentity($data['document'] ?? null, $trainer->phone);

        $this->identities->attachTrainer($trainer, $identity, ownershipVerified: true);
        $trainer->refresh()->load('roleAssignments');

        $this->audit->record(
            TrainerAuditLog::EVENT_IDENTITY_LINKED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_ADMIN,
            actorId: $data['admin_id'] ?? null,
            metadata: ['identity_id' => $identity->getKey()],
            request: $request,
        );

        return response()->json([
            'ok' => true,
            'message' => 'Identidad vinculada.',
            'data' => new TrainerProfessionalResource($trainer),
        ]);
    }

    public function activate(Request $request, Trainer $trainer): JsonResponse
    {
        return $this->setActive($request, $trainer, true);
    }

    public function deactivate(Request $request, Trainer $trainer): JsonResponse
    {
        return $this->setActive($request, $trainer, false);
    }

    private function setActive(Request $request, Trainer $trainer, bool $active): JsonResponse
    {
        $data = $request->validate(['admin_id' => ['nullable', 'integer']]);

        $trainer->forceFill(['status' => $active ? 'active' : 'inactive'])->save();

        // Desactivar corta el acceso profesional de inmediato: además del status
        // (que ya lo rechaza), se revocan las sesiones de dispositivo activas.
        $revoked = 0;
        if (! $active) {
            $revoked = $this->sessions->revokeAll($trainer, 'trainer_deactivated');
        }

        $trainer->refresh()->load('roleAssignments');

        $this->audit->record(
            $active ? TrainerAuditLog::EVENT_ACTIVATED : TrainerAuditLog::EVENT_DEACTIVATED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_ADMIN,
            actorId: $data['admin_id'] ?? null,
            metadata: $active ? [] : ['revoked_sessions' => $revoked],
            request: $request,
        );

        return response()->json([
            'ok' => true,
            'message' => $active ? 'Entrenador activado.' : 'Entrenador desactivado.',
            'data' => new TrainerProfessionalResource($trainer),
        ]);
    }

    /** Dispositivos con sesión profesional activa (para el CRM). */
    public function devices(Trainer $trainer): JsonResponse
    {
        $devices = $this->sessions->activeSessions($trainer)
            ->map(fn ($session) => $session->toPublicArray());

        return response()->json([
            'ok' => true,
            'data' => $devices,
        ]);
    }

    /** Revoca una sesión profesional concreta (cierre remoto desde el CRM). */
    public function revokeDevice(Request $request, Trainer $trainer, string $uuid): JsonResponse
    {
        $data = $request->validate(['admin_id' => ['nullable', 'integer']]);

        $session = TrainerDeviceSession::query()
            ->where('trainer_id', $trainer->getKey())
            ->where('uuid', $uuid)
            ->active()
            ->first();

        if (! $session) {
            return response()->json(['ok' => false, 'code' => 'not_found', 'message' => 'Sesión no encontrada.'], 404);
        }

        $this->sessions->revoke($session, 'revoked_by_admin');

        $this->audit->record(
            TrainerAuditLog::EVENT_SESSION_REVOKED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_ADMIN,
            actorId: $data['admin_id'] ?? null,
            metadata: ['session' => $uuid, 'scope' => 'one'],
            request: $request,
        );

        return response()->json(['ok' => true, 'message' => 'Sesión revocada.']);
    }

    /** Revoca TODAS las sesiones profesionales del entrenador. */
    public function revokeAllSessions(Request $request, Trainer $trainer): JsonResponse
    {
        $data = $request->validate(['admin_id' => ['nullable', 'integer']]);

        $count = $this->sessions->revokeAll($trainer, 'revoked_by_admin');

        $this->audit->record(
            TrainerAuditLog::EVENT_SESSION_REVOKED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_ADMIN,
            actorId: $data['admin_id'] ?? null,
            metadata: ['scope' => 'all', 'revoked' => $count],
            request: $request,
        );

        return response()->json(['ok' => true, 'message' => 'Sesiones revocadas.', 'revoked' => $count]);
    }

    public function audit(Request $request, Trainer $trainer): JsonResponse
    {
        $logs = TrainerAuditLog::query()
            ->where('trainer_id', $trainer->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit((int) min(200, max(1, $request->integer('limit', 50))))
            ->get(['id', 'actor_type', 'actor_id', 'event', 'metadata', 'created_at']);

        return response()->json([
            'ok' => true,
            'data' => $logs,
        ]);
    }
}
