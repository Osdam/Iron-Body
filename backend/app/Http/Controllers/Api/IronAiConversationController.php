<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\IronAiMembershipAccessService;
use App\Services\IronAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * IRON IA — centro de conversaciones por usuario.
 *
 * CRUD de conversaciones (listar, crear, leer mensajes, archivar, eliminar,
 * limpiar). Ninguna de estas acciones consume OpenAI ni cuota: solo enviar un
 * mensaje (POST /iron-ai/chat) consume cuota.
 *
 * Aislamiento: cada conversación pertenece a un usuario (user/member/document).
 * Acceder a una conversación ajena devuelve 403.
 */
class IronAiConversationController extends Controller
{
    public function __construct(
        private readonly IronAiService $service,
        private readonly IronAiMembershipAccessService $access,
    ) {
    }

    /** GET /api/iron-ai/conversations — conversaciones activas del usuario. */
    public function index(Request $request): JsonResponse
    {
        try {
            $ctx = $this->access->resolveMember($request);

            return response()->json([
                'ok'   => true,
                'data' => $this->service->listConversations($ctx),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'data' => [], 'message' => 'No pudimos cargar tus conversaciones.'], 200);
        }
    }

    /** POST /api/iron-ai/conversations — crea una conversación (no consume IA). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:255',
            'topic' => 'nullable|string|max:50',
        ]);

        try {
            $ctx = $this->access->resolveMember($request);
            $conversation = $this->service->createConversation(
                $ctx,
                $data['title'] ?? null,
                $data['topic'] ?? null,
            );

            return response()->json([
                'ok'   => true,
                'data' => $conversation->toPublicArray(),
            ], 201);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'No pudimos crear la conversación.'], 200);
        }
    }

    /** GET /api/iron-ai/conversations/{uuid}/messages — mensajes reales. */
    public function messages(Request $request, string $uuid): JsonResponse
    {
        try {
            $ctx = $this->access->resolveMember($request);
            $conversation = $this->service->findOwnedConversation($ctx, $uuid);
            if (! $conversation) {
                return $this->forbidden();
            }

            return response()->json([
                'ok'           => true,
                'conversation' => $conversation->toPublicArray(),
                'data'         => $this->service->conversationMessages($conversation),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'data' => [], 'message' => 'No pudimos cargar los mensajes.'], 200);
        }
    }

    /** POST /api/iron-ai/conversations/{uuid}/archive */
    public function archive(Request $request, string $uuid): JsonResponse
    {
        try {
            $ctx = $this->access->resolveMember($request);
            $conversation = $this->service->findOwnedConversation($ctx, $uuid);
            if (! $conversation) {
                return $this->forbidden();
            }

            $this->service->archiveConversation($conversation);

            return response()->json(['ok' => true, 'message' => 'Conversación archivada.']);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'No pudimos archivar la conversación.'], 200);
        }
    }

    /** DELETE /api/iron-ai/conversations/{uuid} — soft delete + status=deleted. */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        try {
            $ctx = $this->access->resolveMember($request);
            $conversation = $this->service->findOwnedConversation($ctx, $uuid);
            if (! $conversation) {
                return $this->forbidden();
            }

            $this->service->deleteConversation($conversation);

            return response()->json(['ok' => true, 'message' => 'Conversación eliminada.']);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'No pudimos eliminar la conversación.'], 200);
        }
    }

    /** POST /api/iron-ai/conversations/{uuid}/clear — limpia mensajes, conserva chat. */
    public function clear(Request $request, string $uuid): JsonResponse
    {
        try {
            $ctx = $this->access->resolveMember($request);
            $conversation = $this->service->findOwnedConversation($ctx, $uuid);
            if (! $conversation) {
                return $this->forbidden();
            }

            $this->service->clearConversation($conversation);

            return response()->json(['ok' => true, 'message' => 'Conversación limpiada.']);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => 'No pudimos limpiar la conversación.'], 200);
        }
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
