<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\Trainer;
use App\Services\NotificationService;
use App\Services\Trainer\TrainerRealtimeEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asignación entrenador ↔ miembro (CRM admin) y consulta del entrenador del
 * miembro (app). Aditivo: notifica al asignar/desasignar sin romper nada.
 */
class MemberTrainerController extends Controller
{
    /** POST /api/admin/members/{member}/assign-trainer */
    public function assign(Request $request, Member $member): JsonResponse
    {
        $data = $request->validate([
            'trainer_id'  => 'required|integer|exists:trainers,id',
            'assigned_by' => 'nullable|string|max:120',
            'notes'       => 'nullable|string|max:500',
        ]);

        $trainer = Trainer::findOrFail($data['trainer_id']);

        // Cierra cualquier asignación activa previa (un entrenador vigente a la vez).
        MemberTrainerAssignment::where('member_id', $member->id)
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->update(['status' => MemberTrainerAssignment::STATUS_INACTIVE, 'ended_at' => now()]);

        $assignment = MemberTrainerAssignment::create([
            'member_id'   => $member->id,
            'trainer_id'  => $trainer->id,
            'assigned_by' => $data['assigned_by'] ?? null,
            'status'      => MemberTrainerAssignment::STATUS_ACTIVE,
            'notes'       => $data['notes'] ?? null,
            'assigned_at' => now(),
        ]);

        app(NotificationService::class)->notifyTrainerAssigned($member, $trainer);
        // Real-time: el nuevo entrenador ve aparecer al cliente al instante.
        TrainerRealtimeEvents::membersChanged($trainer->id);

        return response()->json([
            'ok'   => true,
            'data' => $this->serializeAssignment($assignment->load('trainer')),
        ], 201);
    }

    /** POST /api/admin/members/{member}/unassign-trainer */
    public function unassign(Request $request, Member $member): JsonResponse
    {
        $active = MemberTrainerAssignment::where('member_id', $member->id)
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->latest()
            ->first();

        if (! $active) {
            return response()->json(['ok' => true, 'message' => 'El miembro no tiene entrenador asignado.']);
        }

        $active->update(['status' => MemberTrainerAssignment::STATUS_INACTIVE, 'ended_at' => now()]);

        $trainer = Trainer::find($active->trainer_id);
        if ($trainer) {
            app(NotificationService::class)->notifyTrainerUnassigned($member, $trainer);
        }
        // Real-time: el entrenador ve desaparecer al cliente al instante.
        TrainerRealtimeEvents::membersChanged((int) $active->trainer_id);

        return response()->json(['ok' => true]);
    }

    /** GET /api/admin/members/{member}/trainer */
    public function showAdmin(Member $member): JsonResponse
    {
        $active = $member->activeTrainerAssignment()->with('trainer')->first();

        return response()->json([
            'ok'   => true,
            'data' => $active && $active->trainer ? $this->serializeAssignment($active) : null,
        ]);
    }

    /** GET /api/trainers/mine — entrenador asignado al miembro autenticado (app). */
    public function mine(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        $active = $member?->activeTrainerAssignment()->with('trainer')->first();

        return response()->json([
            'ok'   => true,
            'data' => $active && $active->trainer ? $this->serializeAssignment($active) : null,
        ]);
    }

    private function serializeAssignment(MemberTrainerAssignment $a): array
    {
        $t = $a->trainer;

        return [
            'assignment_id' => $a->id,
            'assigned_at'   => optional($a->assigned_at)->toIso8601String(),
            'notes'         => $a->notes,
            'trainer'       => $t ? [
                'id'               => $t->id,
                'full_name'        => $t->full_name,
                'specialty'        => $t->main_specialty ?? '',
                'experience_years' => (int) $t->experience_years,
                'phone'            => $t->phone,
                'email'            => $t->email,
                'photo_url'        => method_exists($t, 'publicPhotoUrl') ? $t->publicPhotoUrl() : $t->avatar_url,
                'status'           => $t->status,
                'is_active'        => $t->isActive(),
            ] : null,
        ];
    }
}
