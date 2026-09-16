<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\Ultron\UltronCommitException;
use App\Services\Marketing\Ultron\UltronCommitService;
use App\Services\Marketing\Ultron\UltronDecideService;
use App\Services\Marketing\Ultron\UltronSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /**
     * POST /api/internal/marketing/ai/commit
     *
     * La única vía por la que ULTRON ejecuta algo. La validación es
     * FAIL-CLOSED y por lista blanca: un campo que no esté aquí no llega al
     * servicio, así que los que n8n no puede decidir —destinatario, precio,
     * activación de membresía, saltarse el opt-out— no es que se rechacen, es
     * que no se pueden expresar.
     */
    public function commit(Request $request, UltronCommitService $commit): JsonResponse
    {
        $data = $request->validate([
            'source_type' => ['required', 'string', Rule::in(UltronSource::V1)],
            'source_event_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'conversation_id' => ['required', 'integer', 'exists:marketing_conversations,id'],
            'decide_token' => ['required', 'string', 'max:2048'],

            'proposal' => ['required', 'array'],
            'proposal.strategy_goal' => ['nullable', 'string', 'max:60'],
            'proposal.next_state' => ['required', 'string', Rule::in(CommercialPhaseMachine::PHASES)],
            'proposal.intent' => ['required', 'string', Rule::in(SalesAgentDecisionSchema::INTENTS)],
            'proposal.reply_draft' => ['nullable', 'string', 'max:4000'],
            'proposal.recommended_plan_id' => ['nullable', 'integer', 'min:1'],
            'proposal.main_barrier' => ['nullable', 'string', Rule::in(CommercialPhaseMachine::BARRIERS)],
            'proposal.next_best_action' => ['nullable', 'string', 'max:60'],
            'proposal.confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'proposal.tools_requested' => ['nullable', 'array', 'max:4'],
            'proposal.tools_requested.*' => ['string', Rule::in(UltronDecideService::V1_ALLOWED_TOOLS)],

            'critic' => ['nullable', 'array'],
            'critic.verdict' => ['nullable', 'string', Rule::in(['pass', 'fail'])],
            'critic.attempt' => ['nullable', 'integer', 'min:1', 'max:2'],
            'critic.score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'critic.notes' => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * Fail-closed sobre la FORMA, no solo sobre los valores.
         *
         * Un campo de más no se ignora en silencio: si el workflow empieza a
         * mandar `phone` o `price`, quiero que falle ruidosamente la primera vez
         * y no que alguien descubra dentro de seis meses que llevaba medio año
         * mandando algo que nadie leía.
         */
        if ($extra = $this->unexpectedKeys($request->all(), $data)) {
            return response()->json([
                'ok' => false,
                'code' => 'unexpected_fields',
                'message' => 'La petición trae campos que no pertenecen al contrato.',
                'fields' => $extra,
            ], 422);
        }

        try {
            return response()->json($commit->commit($data));
        } catch (UltronCommitException $e) {
            return response()->json(array_filter([
                'ok' => false,
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'detail' => $e->detail ?: null,
            ], fn ($v) => $v !== null), $e->status);
        }
    }

    /**
     * Claves del request que no están en el contrato, en el primer nivel y en
     * `proposal`/`critic`. Comparar contra lo validado y no contra una lista
     * escrita a mano evita que las dos se separen.
     *
     * @return string[]
     */
    private function unexpectedKeys(array $enviado, array $validado): array
    {
        $extra = array_keys(array_diff_key($enviado, $validado));

        foreach (['proposal', 'critic'] as $bloque) {
            if (! is_array($enviado[$bloque] ?? null)) {
                continue;
            }
            foreach (array_keys(array_diff_key($enviado[$bloque], (array) ($validado[$bloque] ?? []))) as $k) {
                $extra[] = $bloque.'.'.$k;
            }
        }

        return $extra;
    }
}
