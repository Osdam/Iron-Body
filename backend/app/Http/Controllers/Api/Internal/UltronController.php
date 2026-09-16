<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Services\Marketing\Ultron\UltronDecideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La frontera entre ULTRON (n8n) y Laravel.
 *
 * Dos puertas y ninguna más:
 *
 *   POST /ai/decide  → lee. Devuelve contexto, decisión base, fase y listas
 *                      blancas. No escribe una sola fila.
 *   POST /ai/commit  → escribe. Valida, aplica guardrails, persiste, ejecuta y
 *                      envía. (Se añade en su propia fase.)
 *
 * n8n propone; Laravel decide y ejecuta. Nada que llegue por aquí puede
 * nombrar un destinatario de Meta, un precio, ni una activación de membresía:
 * esos campos no existen en el contrato, así que no es que se validen, es que
 * no se pueden expresar.
 */
class UltronController extends Controller
{
    public function __construct(
        private readonly UltronDecideService $decide,
    ) {}

    /**
     * POST /api/internal/marketing/ai/decide
     *
     * Sin efectos de negocio. Puede llamar al modelo —eso cuesta dinero y
     * tiempo, pero no cambia nada—; lo que no hace es crear mensajes, acciones
     * ni transiciones.
     */
    public function decide(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:marketing_conversations,id'],
            'message_id' => ['required', 'integer', 'exists:marketing_messages,id'],
        ]);

        $conversation = MarketingConversation::with('lead')->find($data['conversation_id']);
        $message = MarketingMessage::find($data['message_id']);

        if ($conversation === null || $message === null) {
            return response()->json(['ok' => false, 'code' => 'not_found'], 404);
        }

        if ($reason = $this->decide->ineligibleReason($conversation, $message)) {
            return response()->json([
                'ok' => false,
                'code' => 'not_eligible',
                'reason' => $reason,
                'conversation_id' => (int) $conversation->id,
            ], 422);
        }

        /*
         * Alguien escribió otra vez mientras esto se decidía.
         *
         * No es un error y no se trata como tal: es lo que pasa cuando una
         * persona manda «hola», «quiero información» y «cuánto vale?» en quince
         * segundos. Se responde 200 diciendo que este recorrido sobra, y ULTRON
         * corta sin componer nada. El del último mensaje sí llegará.
         */
        if ($newer = $this->decide->supersededBy($conversation, $message)) {
            return response()->json([
                'ok' => true,
                'superseded' => true,
                'superseded_by' => $newer,
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $message->id,
            ]);
        }

        return response()->json(array_merge(
            $this->decide->decide($conversation, $message),
            ['superseded' => false],
        ));
    }
}
