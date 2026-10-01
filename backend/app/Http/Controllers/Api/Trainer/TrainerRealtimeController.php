<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Controller;
use App\Models\Trainer;
use App\Models\TrainerRealtimeEvent;
use App\Services\Trainer\TrainerRealtimeEvents;
use App\Support\SseStream;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Canal real-time del portal profesional (SSE). Entrega las señales de cambio
 * que el backend emite ({@see TrainerRealtimeEvents}) para
 * que el panel del entrenador refresque sus clientes al instante, sin polling.
 *
 * Seguridad de canal: va bajo `auth.trainer` (el token valida la conexión) y la
 * consulta está ACOTADA a `auth_trainer->id`: un entrenador JAMÁS recibe eventos
 * de otro. No viajan tokens/OTP/secretos, solo el tipo de cambio y los módulos
 * afectados. La conexión es acotada (~25s) y el cliente reconecta solo.
 */
class TrainerRealtimeController extends Controller
{
    public function stream(Request $request): SymfonyResponse
    {
        /** @var Trainer|null $trainer */
        $trainer = $request->attributes->get('auth_trainer');
        if (! $trainer) {
            return response()->json(['ok' => false, 'message' => 'Sesión requerida.'], 401);
        }

        $trainerId = (int) $trainer->getKey();

        $sinCursor = ! $request->filled('after_id');

        // Solo lo NUEVO tras conectar (señales efímeras, no histórico).
        $cursor = $request->filled('after_id')
            ? (int) $request->query('after_id')
            : (int) (TrainerRealtimeEvent::where('trainer_id', $trainerId)->max('id') ?? 0);

        return SseStream::response(function () use ($trainerId, &$cursor): void {
            $items = TrainerRealtimeEvent::query()
                ->where('trainer_id', $trainerId)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(50)
                ->get();

            foreach ($items as $e) {
                SseStream::emit('app', [
                    'type' => $e->type,
                    'trainer_id' => $trainerId,
                    'version' => (string) $e->version,
                    'changed' => $e->changed ?? [],
                    'timestamp' => $e->created_at?->toIso8601String(),
                ], $e->id);
                $cursor = (int) $e->id;
            }
        }, 25, 1500, function () use ($sinCursor, &$cursor): void {
            // Un cliente sin cursor aprende el suyo antes del primer evento: si
            // no, al reconectar pedía «desde ahora» y perdía lo del hueco.
            if ($sinCursor) {
                echo "id: {$cursor}\n\n";
            }
        }); // tick 1.5s durante ~25s; el cliente reconecta solo.
    }
}
