<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use App\Services\Trainer\TrainerMemberAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Miembros ASIGNADOS al entrenador autenticado (mínimo privilegio: nunca todos
 * los miembros del gimnasio). Devuelve solo lo necesario para el portal, sin
 * exponer el documento completo. Es la lista que alimenta el home profesional.
 */
class TrainerMembersController extends Controller
{
    public function __construct(private readonly TrainerMemberAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Trainer $trainer */
        $trainer = $request->attributes->get('auth_trainer');

        $memberIds = MemberTrainerAssignment::query()
            ->where('trainer_id', $trainer->getKey())
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->pluck('member_id');

        $members = Member::query()
            ->whereIn('id', $memberIds)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'profile_photo_url', 'training_level', 'goal']);

        // Conteo de valoraciones pendientes de lectura por miembro (señal útil).
        $unack = ProfessionalAssessment::query()
            ->whereIn('member_id', $memberIds)
            ->where('trainer_id', $trainer->getKey())
            ->where('status', ProfessionalAssessment::STATUS_SUBMITTED)
            ->whereNull('acknowledged_at')
            ->selectRaw('member_id, count(*) as c')
            ->groupBy('member_id')
            ->pluck('c', 'member_id');

        $data = $members->map(fn (Member $m): array => [
            'id' => $m->id,
            'full_name' => $m->full_name,
            'profile_photo_url' => $m->profile_photo_url,
            'training_level' => $m->training_level,
            'goal' => $m->goal,
            'pending_ack' => (int) ($unack[$m->id] ?? 0),
        ]);

        return response()->json(['ok' => true, 'data' => $data]);
    }

    /**
     * Detalle del miembro AUTORIZADO (asignado a este entrenador). Información
     * profesional pertinente — nunca documento ni correo — más un resumen de la
     * última valoración que este entrenador le hizo. Anti-IDOR: 403 si el miembro
     * no está asignado a quien consulta.
     */
    public function show(Request $request, Member $member): JsonResponse
    {
        /** @var Trainer $trainer */
        $trainer = $request->attributes->get('auth_trainer');

        abort_unless($this->access->canAccess($trainer, $member), 403, 'No tienes acceso a este miembro.');

        $assignment = MemberTrainerAssignment::query()
            ->where('trainer_id', $trainer->getKey())
            ->where('member_id', $member->getKey())
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->latest()
            ->first();

        $last = ProfessionalAssessment::query()
            ->where('trainer_id', $trainer->getKey())
            ->where('member_id', $member->getKey())
            ->whereIn('status', [ProfessionalAssessment::STATUS_SUBMITTED, ProfessionalAssessment::STATUS_AMENDED])
            ->orderByDesc('submitted_at')
            ->first();

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'photo_url' => $member->profile_photo_url,
                'goal' => $member->goal,
                'training_level' => $member->training_level,
                'gender' => $member->gender,
                'age' => $member->birth_date ? Carbon::parse($member->birth_date)->age : null,
                'injuries' => $member->injuries,
                'phone' => $member->phone,
                'member_since' => optional($member->created_at)->toIso8601String(),
                'assigned_at' => optional($assignment?->assigned_at)->toIso8601String(),
                'last_assessment' => $last ? [
                    'uuid' => $last->uuid,
                    'status' => $last->status,
                    'weight_kg' => $last->weight_kg,
                    'submitted_at' => optional($last->submitted_at)->toIso8601String(),
                ] : null,
            ],
        ]);
    }
}
