<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\HumanHandoffAuthority;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\Ultron\CriticContract;
use App\Services\Marketing\Ultron\StrategyContract;
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
    /** Fuente en IRON GUARD. Da su propio carril en el panel. */
    private const INCIDENT_SOURCE = 'ultron';

    /**
     * Las etapas del workflow, en el orden en que ocurren.
     *
     * Se corresponden una a una con los nodos de `Iron Body - ULTRON -
     * Commercial Advisor`; el Error Handler traduce el nombre del nodo que
     * murió a uno de estos.
     *
     * `unknown` existe a propósito: si aparece un nodo que esta lista no
     * contempla, el parte debe entrar igual. Un sistema de alarmas que se
     * niega a registrar lo que no esperaba deja de avisar justo cuando pasa
     * algo nuevo.
     */
    private const INCIDENT_STAGES = [
        'webhook', 'normalize', 'decide', 'strategist', 'validate_strategy',
        'composer', 'critic', 'composer_retry', 'critic_retry',
        'prepare_commit', 'commit', 'respond', 'unknown',
    ];

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
            /*
             * Campos de DERIVACIÓN. Son una PROPUESTA, nunca una orden: el
             * motivo tiene que estar en la allowlist para llegar siquiera al
             * servicio, y allí se corrobora contra el mensaje real antes de
             * producir un solo efecto. La evidencia la cita el modelo; la que
             * vale es la que extrae el backend del texto entrante.
             */
            /*
             * Contrato del Strategist Omega: PROPUESTAS de estrategia con enums
             * cerrados. Cualquier campo de autoridad de negocio (precio, URL,
             * estado de pago, vendibilidad) no está aquí y por tanto muere como
             * `unexpected_fields` antes de llegar al servicio.
             */
            'proposal.conversation_goal' => ['nullable', 'string', Rule::in(StrategyContract::CONVERSATION_GOALS)],
            'proposal.information_needed' => ['nullable', 'array', 'max:6', function (string $attr, mixed $v, \Closure $fail): void {
                if (is_array($v) && ! array_is_list($v)) {
                    $fail('information_needed debe ser una lista, no un objeto.');
                }
            }],
            'proposal.information_needed.*' => ['string', Rule::in(StrategyContract::ASKABLE)],
            'proposal.question_needed' => ['nullable', 'boolean'],
            'proposal.response_goal' => ['nullable', 'string', 'max:160'],
            'proposal.buying_signal' => ['nullable', 'string', Rule::in(StrategyContract::BUYING_SIGNALS)],
            'proposal.closing_opportunity' => ['nullable', 'boolean'],
            'proposal.payment_opportunity' => ['nullable', 'boolean'],
            'proposal.app_support_opportunity' => ['nullable', 'boolean'],
            'proposal.recommendation_goal' => ['nullable', 'string', Rule::in(StrategyContract::RECOMMENDATION_GOALS)],
            'proposal.human_handoff_requested' => ['nullable', 'boolean'],
            'proposal.human_handoff_reason' => ['nullable', 'string', Rule::in(HumanHandoffAuthority::ALLOWED_REASONS)],
            'proposal.human_handoff_evidence' => ['nullable', 'string', 'max:300'],
            'proposal.tools_requested' => ['nullable', 'array', 'max:4'],
            'proposal.tools_requested.*' => ['string', Rule::in(UltronDecideService::V1_ALLOWED_TOOLS)],

            'critic' => ['nullable', 'array'],
            'critic.verdict' => ['nullable', 'string', Rule::in(['pass', 'fail'])],
            'critic.attempt' => ['nullable', 'integer', 'min:1', 'max:2'],
            'critic.score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'critic.notes' => ['nullable', 'string', 'max:'.CriticContract::MAX_NOTES],
            // Vocabulario cerrado del Critic, para poder medirlo (PUNTO 5).
            'critic.issues' => ['nullable', 'array', 'max:'.CriticContract::MAX_ISSUES, function (string $attr, mixed $v, \Closure $fail): void {
                if (is_array($v) && ! array_is_list($v)) {
                    $fail('issues debe ser una lista.');
                }
            }],
            'critic.issues.*' => ['string', Rule::in(CriticContract::DIMENSIONS)],
            'critic.hard_fail' => ['nullable', 'string', Rule::in(CriticContract::HARD_FAILS)],
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
     * POST /api/internal/marketing/ai/ultron/incidents
     *
     * Por dónde avisa el workflow de que algo se le rompió.
     *
     * Existe porque un workflow que falla en silencio es peor que uno que se
     * cae con estruendo: si el Strategist se queda sin cuota o `/ai/commit`
     * rechaza sistemáticamente lo que propone, sin esto nadie se entera hasta
     * que un prospecto se queja de que nunca le contestaron.
     *
     * NO inventa observabilidad nueva: alimenta el mismo IRON GUARD que ya
     * agrupa las averías del canal por CLASE. Cien ejecuciones muertas en la
     * misma etapa por el mismo motivo son un incidente con cien ocurrencias,
     * no cien alarmas.
     *
     * El contrato es deliberadamente pobre. No se acepta el mensaje del
     * cliente, ni cabeceras, ni trazas: un parte de avería que arrastra el
     * texto de la conversación convierte el panel en un segundo sitio donde
     * vive la PII, y esta vez uno que se mira en pantalla compartida.
     */
    public function incident(Request $request, IncidentRecorder $incidents): JsonResponse
    {
        $data = $request->validate([
            'workflow_execution_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'stage' => ['required', 'string', Rule::in(self::INCIDENT_STAGES)],
            /*
             * `error_code` acaba dentro del `kind` y del título que se lee en
             * el panel. Que sea un CÓDIGO y no prosa libre no es cosmética:
             * es lo que impide que un workflow mal escrito publique ahí el
             * mensaje del cliente.
             */
            'error_code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'retryable' => ['required', 'boolean'],
            // Nullable y no `sometimes`: un nodo Set de n8n emite siempre todas
            // las claves, y las que no aplican llegan en null.
            'occurred_at' => ['nullable', 'date'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'source_event_id' => ['nullable', 'integer', 'min:1'],
        ]);

        /*
         * La regla `boolean` acepta "1" y "0" además del booleano, y
         * `validated()` devuelve el valor TAL CUAL llegó. Se normaliza aquí
         * para que en la evidencia quede `false` y no la cadena "0": esa
         * evidencia se lee en el panel y en JSON no significan lo mismo.
         */
        $reintentable = $request->boolean('retryable');

        if ($sobran = $this->unexpectedKeys($request->all(), $data)) {
            return response()->json([
                'ok' => false,
                'code' => 'unexpected_fields',
                'message' => 'El parte de avería trae campos que no están en el contrato.',
                'fields' => $sobran,
            ], 422);
        }

        $incidente = $incidents->record([
            'source' => self::INCIDENT_SOURCE,
            // La clase: etapa + código. Nunca la ejecución concreta, o cada
            // fallo abriría su propia alarma y el panel se volvería ilegible.
            'kind' => self::INCIDENT_SOURCE.'.'.$data['stage'].'.'.$data['error_code'],
            'title' => 'ULTRON falló en '.$data['stage'].' ('.$data['error_code'].')',
            // Lo que no se puede reintentar exige a alguien despierto; lo que
            // sí, normalmente se arregla solo en el siguiente intento.
            'severity' => $reintentable ? Incident::SEVERITY_MEDIUM : Incident::SEVERITY_HIGH,
            'evidence' => [
                'stage' => $data['stage'],
                'error_code' => $data['error_code'],
                'retryable' => $reintentable,
                'occurred_at' => $data['occurred_at'] ?? now()->toIso8601String(),
            ],
            // Hilos por los que tirar al investigar: la ejecución de n8n y, si
            // venían, los ids internos. Ids, no contenido.
            'correlation_ids' => array_values(array_filter([
                'n8n:'.$data['workflow_execution_id'],
                ($data['conversation_id'] ?? null) !== null ? 'conversation:'.$data['conversation_id'] : null,
                ($data['source_event_id'] ?? null) !== null ? 'message:'.$data['source_event_id'] : null,
            ])),
            'affected_conversations' => ($data['conversation_id'] ?? null) !== null ? 1 : 0,
            'affected_messages' => ($data['source_event_id'] ?? null) !== null ? 1 : 0,
        ]);

        return response()->json([
            'ok' => true,
            'incident_id' => (int) $incidente->id,
            'occurrences' => (int) $incidente->occurrences,
            'severity' => (string) $incidente->severity,
        ]);
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
