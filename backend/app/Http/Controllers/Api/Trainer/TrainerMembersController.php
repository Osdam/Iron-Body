<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Miembros ASIGNADOS al entrenador autenticado (mínimo privilegio: nunca todos
 * los miembros del gimnasio). Devuelve solo lo necesario para el portal, sin
 * exponer el documento completo. Es la lista que alimenta el home profesional.
 */
class TrainerMembersController extends Controller
{
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
}
