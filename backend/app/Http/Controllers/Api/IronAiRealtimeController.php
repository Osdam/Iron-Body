<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IronAiUsageLog;
use App\Services\IronAiMembershipAccessService;
use App\Services\IronAiRealtimeService;
use App\Services\IronAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * IRON IA — conversación de voz EN VIVO (OpenAI Realtime, GA).
 *
 * Flutter → Laravel (token efímero) → OpenAI Realtime (WebRTC). La API key real
 * NUNCA sale del backend: este controlador devuelve solo un client_secret
 * efímero (ek_..., ~1 min). Gating por plan: si realtime_voice_enabled no está
 * activo o no hay cuota, NO se acuña sesión (no se conecta a OpenAI).
 */
class IronAiRealtimeController extends Controller
{
    public function __construct(
        private readonly IronAiService $service,
        private readonly IronAiMembershipAccessService $access,
        private readonly IronAiRealtimeService $realtime,
    ) {
    }

    /** POST /api/iron-ai/realtime/session */
    public function session(Request $request): JsonResponse
    {
        try {
            $accessState = $this->access->resolveAccess($request);

            // 1) Gating realtime (capacidad + cuota general). Sin acceso → NO OpenAI.
            $decision = $this->access->decideRealtime($accessState);
            if (! $decision['can']) {
                $this->access->registerUsage($accessState, IronAiUsageLog::STATUS_BLOCKED, [
                    'kind'         => IronAiUsageLog::KIND_REALTIME,
                    'block_reason' => $decision['block']['code'] ?? 'BLOCKED',
                ]);

                return $this->blockResponse($accessState, $decision['block']);
            }

            // 2) Conversación asociada al usuario/document (aislada).
            $uuid = $request->input('conversation_uuid');
            $conversation = $uuid ? $this->service->findOwnedConversation($accessState, $uuid) : null;
            if ($uuid && ! $conversation) {
                return $this->forbidden();
            }
            // Modo visión: sesión multimodal (voz + cámara). Solo enriquece las
            // instrucciones; el gating y la cuota siguen siendo los de realtime.
            $vision = filter_var($request->input('vision', false), FILTER_VALIDATE_BOOLEAN);
            $conversation ??= $this->service->createConversation(
                $accessState,
                $vision ? 'Conversación con cámara' : 'Conversación en vivo',
                'realtime',
            );

            // 3) Acuña el token efímero (la key real no sale del backend).
            $session = $this->realtime->createSession(
                $accessState['member'],
                $accessState['user'],
                $accessState['capabilities'],
                $vision,
            );

            // Si falla, NO se presenta como disponible (regla del producto).
            if ($session === null) {
                $this->access->registerUsage($accessState, IronAiUsageLog::STATUS_ERROR, [
                    'kind'         => IronAiUsageLog::KIND_REALTIME,
                    'block_reason' => 'REALTIME_UNAVAILABLE',
                ]);

                return response()->json([
                    'ok'      => false,
                    'code'    => 'REALTIME_UNAVAILABLE',
                    'message' => 'La conversación en vivo no está disponible en este momento. Intenta más tarde.',
                ], 200);
            }

            // 4) Consumo realtime registrado (cuota/costo).
            $this->access->registerUsage($accessState, IronAiUsageLog::STATUS_SUCCESS, [
                'kind'  => IronAiUsageLog::KIND_REALTIME,
                'model' => $session['model'] ?? null,
            ]);

            return response()->json([
                'ok'                => true,
                'client_secret'     => $session['client_secret'], // efímero (ek_...)
                'expires_at'        => $session['expires_at'] ?? null,
                'model'             => $session['model'],
                'voice'             => $session['voice'],
                'webrtc_url'        => $session['webrtc_url'],
                'conversation_uuid' => $conversation->uuid,
                'conversation_id'   => $conversation->uuid,
                'quota'             => $this->access->quotaSnapshot($accessState),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok'      => false,
                'code'    => 'REALTIME_UNAVAILABLE',
                'message' => 'La conversación en vivo no está disponible en este momento. Intenta más tarde.',
            ], 200);
        }
    }

    /**
     * POST /api/iron-ai/realtime/transcript
     * Persiste los turnos de la conversación en vivo (no llama a OpenAI ni
     * consume cuota de chat). Mantiene el historial asociado al usuario.
     */
    public function transcript(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_uuid' => 'required|string|max:64',
            'turns'             => 'required|array',
            'turns.*.role'      => 'required|string|in:user,assistant',
            'turns.*.content'   => 'required|string',
        ]);

        try {
            $ctx = $this->access->resolveMember($request);
            $conversation = $this->service->findOwnedConversation($ctx, $data['conversation_uuid']);
            if (! $conversation) {
                return $this->forbidden();
            }

            $stored = $this->service->appendRealtimeTurns(
                $conversation,
                $ctx['member'],
                $ctx['user'],
                $data['turns'],
            );

            return response()->json(['ok' => true, 'stored' => $stored]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'No pudimos guardar la conversación.'], 200);
        }
    }

    private function blockResponse(array $access, array $block): JsonResponse
    {
        return response()->json([
            'ok'               => false,
            'code'             => $block['code'],
            'reply'            => $block['reply'],
            'message'          => $block['reply'],
            'upgrade_required' => $block['upgrade_required'] ?? true,
            'cta'              => $block['cta'] ?? null,
            'quota'            => $this->access->quotaSnapshot($access),
        ], 200);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'code'    => 'CONVERSATION_NOT_FOUND',
            'message' => 'La conversación no existe o no te pertenece.',
        ], 403);
    }
}
