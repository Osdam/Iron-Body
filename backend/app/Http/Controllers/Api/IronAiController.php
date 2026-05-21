<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IronAiUsageLog;
use App\Services\IronAiMembershipAccessService;
use App\Services\IronAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * IRON IA — chat, acceso por membresía y recomendaciones.
 *
 * Arquitectura: Flutter → Laravel → OpenAI. Flutter solo habla con estos
 * endpoints; la API key de OpenAI nunca sale del backend.
 *
 * Acceso: se conecta con el módulo EXISTENTE de Planes/Membresías mediante
 * IronAiMembershipAccessService (prueba gratuita de 5 mensajes sin plan, luego
 * cuota por membresía). NO se llama a OpenAI si el caller no tiene acceso.
 */
class IronAiController extends Controller
{
    public function __construct(
        private readonly IronAiService $service,
        private readonly IronAiMembershipAccessService $access,
    ) {
    }

    /** POST /api/iron-ai/chat */
    public function chat(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'message'           => 'required|string|max:2000',
                'feature'           => 'nullable|string|in:progress_analysis,smart_recommendations',
                'conversation_uuid' => 'nullable|string|max:64',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'Escribe un mensaje válido para IRON IA.',
            ], 422);
        }

        try {
            $access = $this->access->resolveAccess($request);

            // 1) ¿Puede usar el chat? (cuota/prueba/IA habilitada)
            if (! $access['can_use_chat']) {
                $this->access->registerUsage($access, IronAiUsageLog::STATUS_BLOCKED, [
                    'block_reason' => $access['block']['code'] ?? 'BLOCKED',
                ]);

                return $this->blockResponse($access, $access['block']);
            }

            // 2) Función premium solicitada explícitamente pero no incluida.
            $feature = $data['feature'] ?? null;
            if ($feature === 'progress_analysis' && ! ($access['capabilities']['progress_analysis_enabled'] ?? false)) {
                $block = $this->access->block('PROGRESS_ANALYSIS_LOCKED');
                $this->access->registerUsage($access, IronAiUsageLog::STATUS_BLOCKED, ['block_reason' => $block['code']]);

                return $this->blockResponse($access, $block);
            }
            if ($feature === 'smart_recommendations' && ! ($access['capabilities']['smart_recommendations_enabled'] ?? false)) {
                $block = $this->access->block('SMART_RECOMMENDATIONS_LOCKED');
                $this->access->registerUsage($access, IronAiUsageLog::STATUS_BLOCKED, ['block_reason' => $block['code']]);

                return $this->blockResponse($access, $block);
            }

            // 3) Resuelve/crea la conversación (aislada por usuario).
            $uuid = $data['conversation_uuid'] ?? null;
            $conversation = $this->service->resolveConversationForChat($access, $uuid, $data['message']);
            if ($uuid && ! $conversation) {
                return response()->json([
                    'ok'      => false,
                    'code'    => 'CONVERSATION_NOT_FOUND',
                    'message' => 'La conversación no existe o no te pertenece.',
                ], 403);
            }

            // 4) Llama a OpenAI usando las capacidades de la membresía.
            $result = $this->service->chat(
                $conversation,
                $access['member'],
                $access['user'],
                $data['message'],
                $access['capabilities'],
            );

            $this->access->registerUsage(
                $access,
                $result['is_fallback'] ? IronAiUsageLog::STATUS_FALLBACK : IronAiUsageLog::STATUS_SUCCESS,
                [
                    'model'         => $result['model'] ?? null,
                    'input_tokens'  => $result['input_tokens'] ?? null,
                    'output_tokens' => $result['output_tokens'] ?? null,
                    'message_id'    => $result['message_id'] ?? null,
                ],
            );

            return response()->json([
                'ok'                => true,
                'reply'             => $result['reply'],
                'conversation_id'   => $result['conversation_uuid'],
                'conversation_uuid' => $result['conversation_uuid'],
                'quota'             => $this->access->quotaSnapshot($access),
                'suggestions'       => $result['suggestions'],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok'              => true,
                'reply'           => IronAiService::FRIENDLY_ERROR,
                'conversation_id' => $request->input('conversation_uuid'),
                'suggestions'     => [],
            ]);
        }
    }

    /** GET /api/iron-ai/access */
    public function access(Request $request): JsonResponse
    {
        try {
            $access = $this->access->resolveAccess($request);

            return response()->json($this->access->serializeAccess($access));
        } catch (Throwable $e) {
            report($e);

            // Fallback conservador: deja probar la prueba gratuita.
            return response()->json([
                'ok'                 => true,
                'has_active_membership' => false,
                'access_type'        => 'free_trial',
                'ai_enabled'         => true,
                'can_use_chat'       => true,
                'upgrade_required'   => false,
                'used_messages'      => 0,
                'message_limit'      => (int) config('iron_ai.free_trial.free_trial_messages', 5),
                'remaining_messages' => (int) config('iron_ai.free_trial.free_trial_messages', 5),
                'context_level'      => 'basic',
            ]);
        }
    }

    /** GET /api/iron-ai/quota */
    public function quota(Request $request): JsonResponse
    {
        try {
            $access = $this->access->resolveAccess($request);

            return response()->json([
                'ok'    => true,
                'quota' => $this->access->quotaSnapshot($access),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok'    => true,
                'quota' => [
                    'access_type' => 'free_trial',
                    'used'        => 0,
                    'limit'       => (int) config('iron_ai.free_trial.free_trial_messages', 5),
                    'remaining'   => (int) config('iron_ai.free_trial.free_trial_messages', 5),
                ],
            ]);
        }
    }

    /** GET /api/iron-ai/recommendations */
    public function recommendations(Request $request): JsonResponse
    {
        try {
            $access = $this->access->resolveAccess($request);

            // Recomendaciones inteligentes gated por la membresía.
            if (! ($access['capabilities']['smart_recommendations_enabled'] ?? false)) {
                return response()->json([
                    'ok'               => true,
                    'data'             => [],
                    'locked'           => true,
                    'upgrade_required' => true,
                    'message'          => 'Las recomendaciones inteligentes están disponibles en una membresía superior.',
                ]);
            }

            return response()->json([
                'ok'   => true,
                'data' => $this->service->recommendations($access['member'], $access['user']),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => true, 'data' => []]);
        }
    }

    /** Respuesta de bloqueo unificada (no llama a OpenAI). */
    private function blockResponse(array $access, array $block): JsonResponse
    {
        return response()->json([
            'ok'               => false,
            'code'             => $block['code'],
            'reply'            => $block['reply'],
            'upgrade_required' => $block['upgrade_required'] ?? true,
            'access_type'      => $access['access_type'] ?? null,
            'conversation_id'  => $access['conversation_id'] ?? null,
            'quota'            => $this->access->quotaSnapshot($access),
            'cta'              => $block['cta'] ?? null,
            'suggestions'      => $block['suggestions'] ?? ['Ver membresías'],
        ]);
    }
}
