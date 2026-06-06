<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Support\SseStream;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Canal real-time PRIVADO del miembro (SSE). Entrega las señales de cambio que
 * el backend emite ({@see \App\Services\RealtimeEvents}) para que la app
 * actualice AppState/módulos al instante, sin polling como mecanismo principal.
 *
 * Seguridad de canal (Bloque 3): la ruta va bajo `auth_member` (el token valida
 * la conexión) y la consulta está ACOTADA a `auth_member->id`, de modo que un
 * miembro JAMÁS recibe eventos de otro. No viajan tokens/OTP/secretos: solo el
 * tipo de cambio y los módulos afectados. La conexión es acotada y el cliente
 * reconecta solo (sin tener un worker tomado indefinidamente).
 */
class MemberRealtimeController extends Controller
{
    public function stream(Request $request): SymfonyResponse
    {
        /** @var Member|null $member */
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            return response()->json(['ok' => false, 'message' => 'Sesión requerida.'], 401);
        }

        // Solo lo NUEVO tras conectar (las señales son efímeras, no histórico).
        $cursor = $request->filled('after_id')
            ? (int) $request->query('after_id')
            : (int) (MemberRealtimeEvent::where('member_id', $member->id)->max('id') ?? 0);

        $memberId = (int) $member->id;

        return SseStream::response(function () use ($memberId, &$cursor): void {
            $items = MemberRealtimeEvent::query()
                ->where('member_id', $memberId)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(50)
                ->get();

            foreach ($items as $e) {
                SseStream::emit('app', [
                    'type'      => $e->type,
                    'member_id' => $memberId,
                    'version'   => (string) $e->version,
                    'changed'   => $e->changed ?? [],
                    'timestamp' => $e->created_at?->toIso8601String(),
                ], $e->id);
                $cursor = (int) $e->id;
            }
        }, 25, 1500); // tick 1.5s durante ~25s; el cliente reconecta solo.
    }
}
