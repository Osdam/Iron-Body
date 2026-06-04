<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveStream;
use App\Models\Member;
use App\Services\LiveKitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Story Live para la app (Bloque 5). Solo el staff puede crear/transmitir; los
 * miembros entran a mirar los lives activos. El token de la sala lo acuña el
 * backend (LiveKit). Si el proveedor no está configurado, responde "no
 * disponible" de forma clara (nunca crashea).
 */
class LiveController extends Controller
{
    public function __construct(private LiveKitService $livekit)
    {
    }

    /** Crea una sesión (solo staff). Queda 'scheduled' hasta start(). */
    public function create(Request $request): JsonResponse
    {
        $member = $this->member($request);
        if (! $member || ! $member->is_staff) {
            return $this->forbidden();
        }
        if (! $this->livekit->isConfigured()) {
            return $this->unavailable();
        }
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $live = LiveStream::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'host_member_id' => $member->id,
            'status' => LiveStream::STATUS_SCHEDULED,
            'provider' => config('live.provider', 'livekit'),
        ]);

        return response()->json(['ok' => true, 'data' => $live->toAppArray()], 201);
    }

    /** Inicia la transmisión (host o staff). */
    public function start(Request $request, LiveStream $live): JsonResponse
    {
        if (! $this->canManage($request, $live)) {
            return $this->forbidden();
        }
        $live->update([
            'status' => LiveStream::STATUS_LIVE,
            'started_at' => $live->started_at ?? now(),
        ]);
        return response()->json(['ok' => true, 'data' => $live->toAppArray()]);
    }

    /** Finaliza la transmisión (host o staff). */
    public function end(Request $request, LiveStream $live): JsonResponse
    {
        if (! $this->canManage($request, $live)) {
            return $this->forbidden();
        }
        $live->update([
            'status' => LiveStream::STATUS_ENDED,
            'ended_at' => now(),
        ]);
        return response()->json(['ok' => true, 'data' => $live->toAppArray()]);
    }

    /** Lives activos (para que cualquier miembro entre a mirar). */
    public function active(): JsonResponse
    {
        $lives = LiveStream::liveNow()
            ->with('host')
            ->latest('started_at')
            ->get()
            ->map(fn (LiveStream $l) => $l->toAppArray());

        return response()->json([
            'ok' => true,
            'enabled' => $this->livekit->isConfigured(),
            'data' => $lives,
        ]);
    }

    public function show(LiveStream $live): JsonResponse
    {
        $live->loadMissing('host');
        return response()->json(['ok' => true, 'data' => $live->toAppArray()]);
    }

    /**
     * Token de acceso a la sala. El host publica (cámara/mic); el resto solo
     * mira. La api_secret nunca sale del backend.
     */
    public function joinToken(Request $request, LiveStream $live): JsonResponse
    {
        $member = $this->member($request);
        if (! $member) {
            return $this->forbidden();
        }
        if (! $this->livekit->isConfigured()) {
            return $this->unavailable();
        }
        if (! $live->isLive()) {
            return response()->json([
                'ok' => false,
                'code' => 'live_not_active',
                'message' => 'Esta transmisión no está en vivo.',
            ], 409);
        }

        $canPublish = $live->host_member_id === $member->id;
        $token = $this->livekit->mintToken(
            $live->provider_room_id,
            'member-'.$member->id,
            $member->full_name ?: 'Miembro',
            $canPublish,
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'url' => $this->livekit->url(),
                'token' => $token,
                'room' => $live->provider_room_id,
                'can_publish' => $canPublish,
            ],
        ]);
    }

    private function member(Request $request): ?Member
    {
        return $request->attributes->get('auth_member');
    }

    private function canManage(Request $request, LiveStream $live): bool
    {
        $member = $this->member($request);
        return $member && $member->is_staff
            && ($live->host_member_id === $member->id || $member->is_staff);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'forbidden',
            'message' => 'Solo el personal de Iron Body puede transmitir en vivo.',
        ], 403);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'live_unavailable',
            'message' => 'Las transmisiones en vivo no están disponibles por ahora.',
        ], 503);
    }
}
