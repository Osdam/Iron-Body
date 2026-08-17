<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PersonalRecordService;
use App\Services\WorkoutSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Sesiones de entrenamiento del miembro.
 *
 *  POST /api/app/workout-sessions        → registra la sesión ejecutada
 *  GET  /api/app/workout-sessions/{cid}  → recupera una sesión ya registrada
 *
 * La respuesta es la fuente AUTORITATIVA del resumen: duración, volumen,
 * ejercicios, series y grupos musculares salen de lo persistido, no de lo que
 * la app tenga en memoria.
 */
class AppWorkoutSessionController extends Controller
{
    public function __construct(
        private readonly WorkoutSessionService $sessions,
        private readonly PersonalRecordService $records,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $data = $request->validate([
            'client_session_id' => ['required', 'string', 'max:64'],
            'routine_id' => ['nullable'],
            'routine_name' => ['nullable', 'string', 'max:255'],
            'started_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'exercises' => ['nullable', 'array', 'max:60'],
            'exercises.*.exercise_id' => ['nullable'],
            'exercises.*.name' => ['required_with:exercises', 'string', 'max:255'],
            'exercises.*.order' => ['nullable', 'integer', 'min:0', 'max:200'],
            'exercises.*.sets' => ['nullable', 'array', 'max:40'],
            'exercises.*.sets.*.set_number' => ['nullable', 'integer', 'min:1', 'max:200'],
            // Flutter ya normaliza y acota, pero el backend valida por su
            // cuenta: la app no puede ser la única barrera y el endpoint es
            // alcanzable directamente.
            'exercises.*.sets.*.reps' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'exercises.*.sets.*.weight_kg' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'exercises.*.sets.*.rpe' => ['nullable', 'integer', 'min:0', 'max:10'],
            'exercises.*.sets.*.completed' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $this->sessions->complete($member, $data);
        } catch (\Throwable $e) {
            // Un fallo inesperado sigue siendo 500 —no se disfraza de éxito—
            // pero se registra con contexto y se devuelve un mensaje que la app
            // pueda enseñar. Sin esto Laravel respondía `{"message":"Server
            // Error"}` y el socio leía literalmente "Server Error".
            Log::error('workout.session_store_failed', [
                'member_id' => $member->id,
                'client_session_id' => $data['client_session_id'],
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'code' => 'workout_session_not_saved',
                'message' => 'No pudimos guardar tu entrenamiento. Ya estamos revisándolo; '
                    .'vuelve a intentarlo en unos minutos.',
            ], 500);
        }

        /** @var \App\Models\WorkoutSession $session */
        $session = $result['session'];

        return response()->json([
            'ok' => true,
            // `created=false` significa que este envío era un reintento: la
            // sesión ya estaba registrada y no se duplicó nada.
            'created' => $result['created'],
            'data' => array_merge($session->toSummaryArray(), [
                'new_records' => $result['records']->map->toPublicArray()->values(),
            ]),
        ], $result['created'] ? 201 : 200);
    }

    public function show(Request $request, string $clientSessionId): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $session = $this->sessions->findForMember($member, $clientSessionId);
        if ($session === null) {
            return response()->json(['message' => 'Sesión no encontrada.'], 404);
        }

        return response()->json(['ok' => true, 'data' => $session->toSummaryArray()]);
    }
}
