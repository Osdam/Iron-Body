<?php

namespace App\Services\UltronStudio;

use InvalidArgumentException;

/**
 * EL RECORRIDO «S2» DE N8N, PORTADO AL LABORATORIO (apartado 5 del cierre;
 * diseño `plans/informes-cierre/diseno-arq-lab.md` §3.1, B2).
 *
 * El laboratorio ejecuta ANTES de publicar el mismo camino que el WhatsApp
 * real entre el estratega y Preparar Commit (`n8n/flujos/ultron-studio-s2/`):
 * Strategist → Validar Estrategia → Composer → ¿Comprobar 1? → Comprobar
 * Borrador 1 → Leer Comprobación 1 → ¿Reescribir 1? → (Nota del Servidor 1 →
 * Composer Reintento | Critic Comercial → Critic aprueba?) … → Decidir Intento
 * 3 → ¿Intento 3? → Composer Reintento 2 → Critic Comercial 3 → Preparar
 * Commit. Como mucho 3 borradores: el tope está en la forma del grafo.
 *
 * QUÉ HAY AQUÍ, Y CÓMO SE VIGILA (§3.4):
 *  - {@see NODES}: por nodo de OpenAI, el rol del prompt, el modelo, la
 *    temperatura, `maxTokens`, `store`, el esquema (la cadena, byte a byte) y
 *    las claves de su segundo mensaje, en su orden. T2 lo compara con
 *    `flujo-s2.json`.
 *  - {@see CONNECTIONS}: las aristas del tramo, salida a salida (T2).
 *  - Los IF y los Code del tramo, portados uno a uno: {@see validateStrategy()}
 *    (Validar Estrategia), {@see readCheck()} (Leer Comprobación 1 y 2),
 *    {@see serverNote()} (Nota del Servidor), {@see decideThird()} (Decidir
 *    Intento 3), {@see prepareCommit()} (lo que Preparar Commit elige: el
 *    borrador, el intento, el plan y el bloque `regeneration`) y
 *    {@see evaluateIf()}. T3 los compara con el JavaScript real ejecutado con
 *    Node sobre los `fixtures/check-*.json`, los veredictos del revisor y
 *    salidas del estratega hostiles.
 *  - {@see walk()}: el recorrido del grafo, nodo a nodo, como lo ejecuta n8n
 *    (una cola desde el estratega; cada nodo una vez).
 *
 * PURO y SIN NOMBRAR EL MOTOR: ni base, ni configuración, ni reloj, ni red.
 * Las llamadas al modelo y a la comprobación del servidor llegan como
 * funciones de quien recorre ({@see StudioLabEngineCase}); el tiempo
 * transcurrido, también. Lo que el motor calcula (el contexto de `decide`, la
 * comprobación) lo construyen {@see StudioLabEngineContext} y
 * {@see StudioLabEngineCheck}, los dos lectores autorizados (enmienda de H4
 * §A4 del 22-11-2026).
 *
 * VERSIÓN. Cambiar un nodo, una arista o un port obliga a subir
 * {@see VERSION}: va en el `prompt_version` de las ejecuciones del motor
 * (`motor-S2-c8-<VERSION>`), así que nunca se comparan dos recorridos
 * distintos con el mismo nombre.
 */
final class StudioLabEngineFlow
{
    public const VERSION = '2026-11-22.1';

    /** La revisión del flujo que se porta. */
    public const FLOW_REV = 'S2';

    /** La base y el compilador con los que se generó ese flujo (`_generado`). */
    public const BASE = 'n8n-29de5119';

    public const COMPILER = 8;

    /** El contrato de la comprobación del servidor (`ultron-regen/1`). */
    public const CHECK_CONTRACT = 'ultron-regen/1';

    /** Como mucho tres redacciones por turno: la primera y dos reescrituras. */
    public const MAX_DRAFTS = 3;

    /** Pasados estos segundos desde `decide`, el servidor ya no concede otra redacción (T4 lo ata al motor). */
    public const TIME_BUDGET_SECONDS = 25;

    // ── Los nodos del tramo ─────────────────────────────────────────────────

    public const STRATEGIST = 'Strategist';

    public const VALIDATE = 'Validar Estrategia';

    public const COMPOSER = 'Composer';

    public const IF_CHECK_1 = '¿Comprobar 1?';

    public const CHECK_1 = 'Comprobar Borrador 1';

    public const READ_1 = 'Leer Comprobación 1';

    public const IF_REWRITE_1 = '¿Reescribir 1?';

    public const NOTE_1 = 'Nota del Servidor 1';

    public const CRITIC = 'Critic Comercial';

    public const IF_CRITIC = 'Critic aprueba?';

    public const RETRY = 'Composer Reintento';

    public const IF_CHECK_2 = '¿Comprobar 2?';

    public const CHECK_2 = 'Comprobar Borrador 2';

    public const READ_2 = 'Leer Comprobación 2';

    public const IF_REWRITE_2 = '¿Reescribir 2?';

    public const NOTE_2 = 'Nota del Servidor 2';

    public const CRITIC_2 = 'Critic Comercial 2';

    public const DECIDE_3 = 'Decidir Intento 3';

    public const IF_THIRD = '¿Intento 3?';

    public const RETRY_2 = 'Composer Reintento 2';

    public const CRITIC_3 = 'Critic Comercial 3';

    public const COMMIT = 'Preparar Commit';

    /** Los redactores y los revisores, en el orden del grafo. */
    public const COMPOSERS = [self::COMPOSER, self::RETRY, self::RETRY_2];

    public const CRITICS = [self::CRITIC, self::CRITIC_2, self::CRITIC_3];

    /** Las comprobaciones (nodo HTTP → su intento). */
    public const CHECKS = [self::CHECK_1 => 1, self::CHECK_2 => 2];

    /**
     * Las aristas del tramo, salida a salida (`main[i]`), como en
     * `flujo-s2.json`. Preparar Commit no sigue: lo que viene después es el
     * commit y el envío, que el laboratorio no hace.
     */
    public const CONNECTIONS = [
        self::STRATEGIST => [[self::VALIDATE]],
        self::VALIDATE => [[self::COMPOSER]],
        self::COMPOSER => [[self::IF_CHECK_1]],
        self::IF_CHECK_1 => [[self::CHECK_1], [self::CRITIC]],
        self::CHECK_1 => [[self::READ_1]],
        self::READ_1 => [[self::IF_REWRITE_1]],
        self::IF_REWRITE_1 => [[self::NOTE_1], [self::CRITIC]],
        self::NOTE_1 => [[self::RETRY]],
        self::CRITIC => [[self::IF_CRITIC]],
        self::IF_CRITIC => [[self::COMMIT], [self::RETRY]],
        self::RETRY => [[self::IF_CHECK_2]],
        self::IF_CHECK_2 => [[self::CHECK_2], [self::CRITIC_2]],
        self::CHECK_2 => [[self::READ_2]],
        self::READ_2 => [[self::IF_REWRITE_2]],
        self::IF_REWRITE_2 => [[self::NOTE_2], [self::CRITIC_2]],
        self::NOTE_2 => [[self::RETRY_2]],
        self::CRITIC_2 => [[self::DECIDE_3]],
        self::DECIDE_3 => [[self::IF_THIRD]],
        self::IF_THIRD => [[self::RETRY_2], [self::COMMIT]],
        self::RETRY_2 => [[self::CRITIC_3]],
        self::CRITIC_3 => [[self::COMMIT]],
    ];

    /** Lo que los revisores reciben de la política del turno (un subconjunto, en este orden). */
    public const CRITIC_POLICY_KEYS = ['active_goal', 'resume_goal_after_answer', 'price_verification', 'reception_mode', 'memory_mode', 'greet_required'];

    /** Los esquemas de salida, byte a byte los de `flujo-s2.json` (T2). */
    public const SCHEMA_STRATEGY = '{"type": "object", "additionalProperties": false, "required": ["strategy_goal", "next_state", "intent", "recommended_plan_id", "main_barrier", "next_best_action", "confidence", "tools_requested", "staff_review_reason", "reason", "human_handoff_requested", "human_handoff_reason", "human_handoff_evidence", "conversation_goal", "information_needed", "question_needed", "response_goal", "buying_signal", "closing_opportunity", "payment_opportunity", "app_support_opportunity", "recommendation_goal"], "properties": {"strategy_goal": {"type": "string"}, "next_state": {"type": "string"}, "intent": {"type": "string", "enum": ["pricing_question", "payment_link_request", "price_objection", "time_objection", "delay_objection", "beginner_fear", "insecurity_body", "location_question", "schedule_question", "general_info", "goal_fat_loss", "goal_muscle_gain", "goal_recomposition", "high_intent_close", "greeting", "thanks", "goodbye", "not_interested", "bot_question", "human_request", "complaint", "invoice_request", "medical_risk_escalation", "fraud_or_payment_claim", "do_not_contact_request", "spam_or_low_quality", "unknown"]}, "recommended_plan_id": {"type": ["integer", "null"]}, "main_barrier": {"type": ["string", "null"]}, "next_best_action": {"type": "string"}, "confidence": {"type": "number"}, "tools_requested": {"type": "array", "items": {"type": "string"}}, "staff_review_reason": {"type": ["string", "null"]}, "reason": {"type": "string"}, "human_handoff_requested": {"type": "boolean"}, "human_handoff_reason": {"type": ["string", "null"]}, "human_handoff_evidence": {"type": ["string", "null"]}, "conversation_goal": {"type": "string", "enum": ["understand", "recommend", "resolve_objection", "close", "collect_payment", "onboard", "support", "recover", "escalate", "wait"]}, "information_needed": {"type": "array", "items": {"type": "string"}}, "question_needed": {"type": "boolean"}, "response_goal": {"type": "string"}, "buying_signal": {"type": "string", "enum": ["none", "weak", "strong"]}, "closing_opportunity": {"type": "boolean"}, "payment_opportunity": {"type": "boolean"}, "app_support_opportunity": {"type": "boolean"}, "recommendation_goal": {"type": "string", "enum": ["none", "single_plan", "compare_plans"]}}}';

    public const SCHEMA_REPLY = '{"type":"object","additionalProperties":false,"required":["reply_draft","uses_price_placeholder","questions_asked","plans_mentioned"],"properties":{"reply_draft":{"type":"string"},"uses_price_placeholder":{"type":"boolean"},"questions_asked":{"type":"integer"},"plans_mentioned":{"type":"integer"}}}';

    public const SCHEMA_CRITIC = '{"type":"object","additionalProperties":false,"required":["verdict","score","issues","notes","hard_fail"],"properties":{"verdict":{"type":"string","enum":["pass","fail"]},"score":{"type":"number"},"issues":{"type":"array","items":{"type":"string","enum":["relevance","empathy","naturalness","context_use","sales_judgment","pressure_control","next_step","question_discipline","repetition","phase_alignment","reference_resolution","novelty","customer_fit","progressive_disclosure","factuality","tool_consistency","memory_consistency","payment_safety","url_safety","app_factuality","handoff_leak","membership_factuality"]}},"notes":{"type":"string"},"hard_fail":{"type":["string","null"],"enum":["invented_price","invented_plan","non_sellable_plan","invented_class","invented_payment_status","invented_url","unauthorized_handoff","request_card_data","contradiction","major_reference_failure","low_novelty","customer_misfit","pressure","progressive_disclosure","identity_lie","invented_membership_fact",null]}}}';

    public const NODES = [
        'Strategist' => [
            'role' => 'strategist', 'model' => 'gpt-4.1-mini', 'temperature' => 0.1, 'maxTokens' => 700, 'store' => false,
            'schemaName' => 'ultron_strategy', 'schema' => self::SCHEMA_STRATEGY, 'strict' => true,
            'keys' => ['commercial_phase', 'allowed_transitions', 'allowed_tools', 'allowed_staff_review_reasons', 'memory', 'resolved_reference', 'novelty', 'customer', 'strategy_hints', 'lead', 'recent_messages', 'knowledge_base', 'gym', 'business_time', 'payment', 'app', 'membership', 'active_plans', 'default_plan_id', 'flags', 'base_decision', 'sales_playbook', 'commercial_turn_policy'],
            'policySubset' => null,
        ],
        'Composer' => [
            'role' => 'composer', 'model' => 'gpt-4.1-mini', 'temperature' => 0.7, 'maxTokens' => 600, 'store' => false,
            'schemaName' => 'ultron_reply', 'schema' => self::SCHEMA_REPLY, 'strict' => true,
            'keys' => ['strategy', 'human_handoff_allowed', 'incoming_text', 'memory', 'resolved_reference', 'novelty', 'customer', 'recent_messages', 'knowledge_base', 'gym', 'business_time', 'payment', 'app', 'membership', 'active_plans', 'flags', 'commercial_phase', 'sales_playbook', 'approved_brand_copy', 'commercial_turn_policy', 'suggested_question'],
            'policySubset' => null,
        ],
        'Critic Comercial' => [
            'role' => 'critic', 'model' => 'gpt-4.1-mini', 'temperature' => 0.1, 'maxTokens' => 400, 'store' => false,
            'schemaName' => 'ultron_critic', 'schema' => self::SCHEMA_CRITIC, 'strict' => true,
            'keys' => ['reply_draft', 'questions_asked', 'plans_mentioned', 'strategy', 'resolved_reference', 'novelty', 'customer', 'human_handoff_allowed', 'incoming_text', 'recent_messages', 'memory', 'knowledge_base', 'gym', 'payment', 'app', 'membership', 'active_plans', 'flags', 'commercial_phase', 'sales_playbook', 'approved_brand_copy', 'commercial_turn_policy'],
            'policySubset' => self::CRITIC_POLICY_KEYS,
        ],
        'Composer Reintento' => [
            'role' => 'composer_retry', 'model' => 'gpt-4.1-mini', 'temperature' => 0.6, 'maxTokens' => 600, 'store' => false,
            'schemaName' => 'ultron_reply', 'schema' => self::SCHEMA_REPLY, 'strict' => true,
            'keys' => ['strategy', 'human_handoff_allowed', 'incoming_text', 'rejected_draft', 'critic_issues', 'critic_notes', 'memory', 'resolved_reference', 'novelty', 'customer', 'recent_messages', 'knowledge_base', 'gym', 'business_time', 'payment', 'app', 'membership', 'active_plans', 'flags', 'commercial_phase', 'sales_playbook', 'approved_brand_copy', 'commercial_turn_policy', 'suggested_question'],
            'policySubset' => null,
        ],
        'Critic Comercial 2' => [
            'role' => 'critic_retry', 'model' => 'gpt-4.1-mini', 'temperature' => 0.1, 'maxTokens' => 400, 'store' => false,
            'schemaName' => 'ultron_critic', 'schema' => self::SCHEMA_CRITIC, 'strict' => true,
            'keys' => ['reply_draft', 'questions_asked', 'plans_mentioned', 'strategy', 'active_plans', 'resolved_reference', 'novelty', 'customer', 'human_handoff_allowed', 'incoming_text', 'recent_messages', 'memory', 'knowledge_base', 'gym', 'payment', 'app', 'membership', 'flags', 'commercial_phase', 'sales_playbook', 'approved_brand_copy', 'commercial_turn_policy'],
            'policySubset' => self::CRITIC_POLICY_KEYS,
        ],
        'Composer Reintento 2' => [
            'role' => 'composer_retry', 'model' => 'gpt-4.1-mini', 'temperature' => 0.6, 'maxTokens' => 600, 'store' => false,
            'schemaName' => 'ultron_reply', 'schema' => self::SCHEMA_REPLY, 'strict' => true,
            'keys' => ['strategy', 'human_handoff_allowed', 'incoming_text', 'rejected_draft', 'critic_issues', 'critic_notes', 'memory', 'resolved_reference', 'novelty', 'customer', 'recent_messages', 'knowledge_base', 'gym', 'business_time', 'payment', 'app', 'membership', 'active_plans', 'flags', 'commercial_phase', 'sales_playbook', 'approved_brand_copy', 'commercial_turn_policy', 'suggested_question'],
            'policySubset' => null,
        ],
        'Critic Comercial 3' => [
            'role' => 'critic_retry', 'model' => 'gpt-4.1-mini', 'temperature' => 0.1, 'maxTokens' => 400, 'store' => false,
            'schemaName' => 'ultron_critic', 'schema' => self::SCHEMA_CRITIC, 'strict' => true,
            'keys' => ['reply_draft', 'questions_asked', 'plans_mentioned', 'strategy', 'active_plans', 'resolved_reference', 'novelty', 'customer', 'human_handoff_allowed', 'incoming_text', 'recent_messages', 'memory', 'knowledge_base', 'gym', 'payment', 'app', 'membership', 'flags', 'commercial_phase', 'sales_playbook', 'approved_brand_copy', 'commercial_turn_policy'],
            'policySubset' => self::CRITIC_POLICY_KEYS,
        ],
    ];

    /** El vocabulario cerrado de la comprobación (Leer Comprobación). */
    public const CHECK_CODES = ['register_tuteo', 'length_over_cap', 'plans_overview', 'benefit_misattributed', 'policy_greeting_sells', 'policy_active_goal_lost', 'policy_wrong_first_plan', 'policy_answer_without_fact', 'policy_general_info_only_discovery', 'policy_memory_turn_sells'];

    /** Las dimensiones del revisor (Leer Comprobación y Preparar Commit). */
    public const DIMENSIONS = ['relevance', 'empathy', 'naturalness', 'context_use', 'sales_judgment', 'pressure_control', 'next_step', 'question_discipline', 'repetition', 'phase_alignment', 'reference_resolution', 'novelty', 'customer_fit', 'progressive_disclosure', 'factuality', 'tool_consistency', 'memory_consistency', 'payment_safety', 'url_safety', 'app_factuality', 'handoff_leak', 'membership_factuality'];

    public const MAX_CODES = 8;

    public const MAX_ISSUES = 8;

    public const MAX_NOTES = 500;

    /** Validar Estrategia: las barreras que se aceptan. */
    private const BARRIERS = ['price', 'time', 'location', 'beginner_fear', 'body_insecurity', 'motivation', 'previous_bad_experience', 'not_ready', 'other'];

    /** Validar Estrategia: las intenciones que cotizan. */
    private const QUOTING = ['pricing_question', 'price_objection', 'payment_link_request', 'high_intent_close'];

    private const GOALS = ['understand', 'recommend', 'resolve_objection', 'close', 'collect_payment', 'onboard', 'support', 'recover', 'escalate', 'wait'];

    private const BUYING = ['none', 'weak', 'strong'];

    private const RECOMMENDATION = ['none', 'single_plan', 'compare_plans'];

    /**
     * LA MARCA de una ejecución del motor: su `prompt_version` empieza por
     * esto. El único sitio donde está escrita: la leen la puerta de publicar,
     * las listas del laboratorio y el runner (por {@see isEnginePromptVersion()}
     * y `StudioLabRuns::whereFidelity()`), así que no pueden separarse.
     */
    public const PROMPT_VERSION_PREFIX = 'motor-';

    /** El `prompt_version` de una ejecución del motor (≤ 32, la columna). */
    public static function promptVersion(): string
    {
        return self::PROMPT_VERSION_PREFIX.self::FLOW_REV.'-c'.self::COMPILER.'-'.self::VERSION;
    }

    /** ¿Es el `prompt_version` de una ejecución del motor (de cualquier revisión)? */
    public static function isEnginePromptVersion(?string $promptVersion): bool
    {
        return is_string($promptVersion) && str_starts_with($promptVersion, self::PROMPT_VERSION_PREFIX);
    }

    /**
     * Las huellas (sha256) del `jsCode` de los Code que se portan, con las que
     * se escribió el port: si el flujo cambia uno, T2 se pone en rojo y hay que
     * volver a portarlo y subir {@see VERSION}. Las cinco nuevas de S2 son las
     * de `huellas.json`.
     */
    public const PORTED_CODE_SHA256 = [
        self::VALIDATE => '66168dc71e0685c773e4e4697d2849b53265f7d7e60c022db75834ac0e194731',
        self::READ_1 => 'b34438a011038155c5d40607c717b5634c87ec3ed509908d4611c49c30abd605',
        self::READ_2 => '31dc4a9eb753a434715bd9003bdb48df02283b272bf196275cc27247dcbe8bb1',
        self::NOTE_1 => 'dea0ed184c9f5550cc05f995e8d2ea7082dad7f804c335a1343bc59a1db3c247',
        self::NOTE_2 => 'dea0ed184c9f5550cc05f995e8d2ea7082dad7f804c335a1343bc59a1db3c247',
        self::DECIDE_3 => '133872f4b7e062fd89d1a93fe7efdf554db278547125e6de811133647c2aca59',
        self::COMMIT => '8258faaefe8e7df2ed497dc0d7d935e1438f6992ec27b81be312c6a3ef76c109',
    ];

    /**
     * La huella de lo que se porta (`nodesHash` de la ejecución, §3.3): los
     * nodos de OpenAI, las aristas y las huellas de los Code. T2 la recalcula
     * desde `flujo-s2.json`. Con `json_encode` y no con la huella canónica del
     * Studio: lleva temperaturas, que son decimales.
     */
    public static function nodesHash(): string
    {
        return hash('sha256', self::encode(['flowRev' => self::FLOW_REV, 'nodes' => self::NODES, 'connections' => self::CONNECTIONS, 'code' => self::PORTED_CODE_SHA256]));
    }

    // ── Los mensajes de cada nodo de OpenAI ────────────────────────────────

    /**
     * El segundo mensaje de un nodo de OpenAI (`values[1]`): el objeto que
     * n8n pasa por `JSON.stringify`, con sus claves en su orden. Una clave
     * cuyo origen no existe se omite (como `JSON.stringify` con `undefined`);
     * un null viaja como null.
     *
     * @param  array<string,mixed>  $decide  la respuesta de `decide` (Pedir Contexto al CRM)
     * @param  array<string,mixed>  $ran  lo que ya corrió, por nodo (el json de cada uno)
     * @param  array<string,mixed>  $input  el json que entra en el nodo (`$json`)
     * @return array<string,mixed>
     */
    public static function userPayload(string $node, array $decide, string $inbound, array $ran, array $input): array
    {
        $spec = self::NODES[$node] ?? throw new InvalidArgumentException('Nodo de OpenAI desconocido.');
        $ctx = is_array($decide['context'] ?? null) ? $decide['context'] : [];
        $ve = is_array($ran[self::VALIDATE] ?? null) ? $ran[self::VALIDATE] : [];
        $propio = self::modelText($input);
        $anterior = $node === self::RETRY ? self::modelText($ran[self::COMPOSER] ?? null) : ($node === self::RETRY_2 ? self::modelText($ran[self::RETRY] ?? null) : null);

        $out = [];
        foreach ($spec['keys'] as $clave) {
            $valor = match (true) {
                $node === self::STRATEGIST && in_array($clave, ['commercial_phase', 'allowed_transitions', 'allowed_tools', 'allowed_staff_review_reasons'], true) => self::lookup($decide, [$clave]),
                $node === self::STRATEGIST && $clave === 'base_decision' => self::lookup($decide, ['decision']),
                $clave === 'strategy' => self::lookup($ve, ['strategy']),
                $clave === 'human_handoff_allowed' => [true, ($ve['human_handoff_allowed'] ?? null) === true],
                $clave === 'incoming_text' => [true, $inbound],
                $clave === 'commercial_phase' => self::lookup($decide, ['commercial_phase']),
                $clave === 'suggested_question' => self::lookup($ctx, ['strategy_hints', 'suggested_question']),
                in_array($clave, ['reply_draft', 'questions_asked', 'plans_mentioned'], true) => self::lookup($propio ?? [], [$clave]),
                $clave === 'rejected_draft' => self::lookup($anterior ?? [], ['reply_draft']),
                $clave === 'critic_issues' => self::lookup($propio ?? [], ['issues']),
                $clave === 'critic_notes' => self::lookup($propio ?? [], ['notes']),
                $clave === 'commercial_turn_policy' && $spec['policySubset'] !== null => [true, self::subset(is_array($ctx['commercial_turn_policy'] ?? null) ? $ctx['commercial_turn_policy'] : [], $spec['policySubset'])],
                default => self::lookup($ctx, [$clave]),
            };
            if ($valor[0]) {
                $out[$clave] = $valor[1];
            }
        }

        return $out;
    }

    /** El segundo mensaje como lo escribe `JSON.stringify` (sin espacios, UTF-8 y barras tal cual). */
    public static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    /** El esquema de un nodo, decodificado (para validar la salida del modelo). */
    public static function schemaOf(string $node): array
    {
        $spec = self::NODES[$node] ?? throw new InvalidArgumentException('Nodo de OpenAI desconocido.');

        return json_decode($spec['schema'], true, 64, JSON_THROW_ON_ERROR);
    }

    // ── Los IF ──────────────────────────────────────────────────────────────

    /**
     * Un IF del tramo con su condición de `flujo-s2.json`: true → salida 0.
     *
     * @param  array<string,mixed>  $input  `$json`
     * @param  array<string,mixed>  $decide
     */
    public static function evaluateIf(string $node, array $input, array $decide): bool
    {
        return match ($node) {
            // `$('Pedir Contexto al CRM').first().json.regeneration ? … .contract === 'ultron-regen/1' : false`
            self::IF_CHECK_1, self::IF_CHECK_2 => is_array($decide['regeneration'] ?? null) && ($decide['regeneration']['contract'] ?? null) === self::CHECK_CONTRACT,
            // `$json.ultron_check ? $json.ultron_check.rewrite === true : false`
            self::IF_REWRITE_1, self::IF_REWRITE_2 => is_array($input['ultron_check'] ?? null) && ($input['ultron_check']['rewrite'] ?? null) === true,
            // `$json.output[0].content[0].text.verdict` igual a `pass` (estricto)
            self::IF_CRITIC => (self::modelText($input)['verdict'] ?? null) === 'pass',
            // `$json.ultron_intento3 === true`
            self::IF_THIRD => ($input['ultron_intento3'] ?? null) === true,
            default => throw new InvalidArgumentException('IF desconocido.'),
        };
    }

    // ── Los Code ────────────────────────────────────────────────────────────

    /**
     * VALIDAR ESTRATEGIA, portado línea a línea. Devuelve `{strategy,
     * corrections, context, human_handoff_allowed}` o lanza si la salida del
     * estratega no es un objeto («Estrategia ilegible»): en n8n la ejecución
     * falla y nadie contesta.
     *
     * @param  array<string,mixed>  $decide
     * @param  array<string,mixed>  $strategistJson  el json del nodo Strategist
     * @return array{strategy:array<string,mixed>,corrections:list<string>,context:array<string,mixed>,human_handoff_allowed:bool}
     *
     * @throws InvalidArgumentException
     */
    public static function validateStrategy(array $decide, array $strategistJson): array
    {
        $s = self::extract($strategistJson);
        if (! is_array($s) || ($s !== [] && array_is_list($s))) {
            throw new InvalidArgumentException('Estrategia ilegible: el modelo no devolvio el JSON esperado');
        }

        $permitidas = self::listOrEmpty($decide['allowed_transitions'] ?? null);
        $herramientas = self::listOrEmpty($decide['allowed_tools'] ?? null);
        $contexto = is_array($decide['context'] ?? null) ? $decide['context'] : [];
        $planes = array_map(static fn ($p) => is_array($p) ? ($p['id'] ?? null) : null, self::listOrEmpty($contexto['active_plans'] ?? null));
        $c = [];

        if (! self::jsIncludes($permitidas, $s['next_state'] ?? null)) {
            $c[] = 'next_state fuera de la lista blanca: '.self::jsString($s['next_state'] ?? null, array_key_exists('next_state', $s));
            $s['next_state'] = $decide['commercial_phase'] ?? null;
        }
        $pedidas = is_array($s['tools_requested'] ?? null) && array_is_list($s['tools_requested']) ? $s['tools_requested'] : [];
        $validas = array_values(array_filter($pedidas, static fn ($t) => self::jsIncludes($herramientas, $t)));
        if (count($validas) !== count($pedidas)) {
            $c[] = 'herramientas fuera de la lista blanca';
        }
        $s['tools_requested'] = $validas;

        if (array_key_exists('recommended_plan_id', $s) && $s['recommended_plan_id'] !== null && ! self::jsIncludes($planes, $s['recommended_plan_id'])) {
            $c[] = 'plan inexistente: '.self::jsString($s['recommended_plan_id'], true);
            $s['recommended_plan_id'] = null;
        }
        if (! array_key_exists('recommended_plan_id', $s)) {
            $s['recommended_plan_id'] = null;
        }

        $intentCrm = is_array($decide['decision'] ?? null) ? ($decide['decision']['intent'] ?? null) : null;
        $intentCrm = self::jsTruthy($intentCrm) ? $intentCrm : '';
        if ($s['recommended_plan_id'] === null && (self::jsIncludes(self::QUOTING, $intentCrm) || self::jsIncludes(self::QUOTING, $s['intent'] ?? null))) {
            $porDefecto = $contexto['default_plan_id'] ?? null;
            if (self::jsTruthy($porDefecto) && self::jsIncludes($planes, $porDefecto)) {
                $s['recommended_plan_id'] = $porDefecto;
                $c[] = 'plan por defecto del negocio aplicado: '.self::jsString($porDefecto, true);
            } else {
                $c[] = 'cotiza pero el CRM no ofrecio plan por defecto';
            }
        }

        $puedeDerivar = in_array('HUMAN_HANDOFF', $permitidas, true);
        $pide = ($s['human_handoff_requested'] ?? null) === true;
        $motivo = is_string($s['human_handoff_reason'] ?? null) ? mb_strtolower(self::jsTrim($s['human_handoff_reason'])) : null;
        $prueba = is_string($s['human_handoff_evidence'] ?? null) && self::jsTrim($s['human_handoff_evidence']) !== ''
            ? self::jsSlice(self::jsTrim($s['human_handoff_evidence']), 300) : null;
        if ($pide && ! $puedeDerivar) {
            $c[] = 'derivacion propuesta sin permiso del CRM';
            $pide = false;
        }
        if ($pide && ($s['next_state'] ?? null) !== 'HUMAN_HANDOFF') {
            $c[] = 'derivacion pedida sin next_state HUMAN_HANDOFF';
            $pide = false;
        }
        if ($pide && $motivo !== 'explicit_human_request') {
            $c[] = 'motivo de derivacion no proponible por el modelo: '.($motivo ?? 'null');
            $pide = false;
        }
        if (! $pide && ($s['next_state'] ?? null) === 'HUMAN_HANDOFF') {
            $c[] = 'next_state HUMAN_HANDOFF sin derivacion valida';
            $s['next_state'] = $decide['commercial_phase'] ?? null;
        }
        $s['human_handoff_requested'] = $pide;
        $s['human_handoff_reason'] = $pide ? $motivo : null;
        $s['human_handoff_evidence'] = $pide ? $prueba : null;

        $motivosRevision = self::listOrEmpty($decide['allowed_staff_review_reasons'] ?? null);
        $motivoRevision = is_string($s['staff_review_reason'] ?? null) ? mb_strtolower(self::jsTrim($s['staff_review_reason'])) : null;
        $pideRevision = in_array('staff_review', $s['tools_requested'], true);
        if ($pideRevision && ! self::jsIncludes($motivosRevision, $motivoRevision)) {
            $c[] = 'staff_review sin motivo proponible: '.($motivoRevision ?? 'null');
        }
        $s['staff_review_reason'] = ($pideRevision && self::jsIncludes($motivosRevision, $motivoRevision)) ? $motivoRevision : null;

        $hints = is_array($contexto['strategy_hints'] ?? null) ? $contexto['strategy_hints'] : [];
        $enumOr = static fn (mixed $v, array $lista, string $def): string => is_string($v) && in_array($v, $lista, true) ? $v : $def;
        $s['conversation_goal'] = $enumOr($s['conversation_goal'] ?? null, self::GOALS, 'understand');
        $s['buying_signal'] = $enumOr($s['buying_signal'] ?? null, self::BUYING, 'none');
        $s['recommendation_goal'] = $enumOr($s['recommendation_goal'] ?? null, self::RECOMMENDATION, 'none');
        $mayAsk = is_array($hints['may_ask'] ?? null) && array_is_list($hints['may_ask']) ? $hints['may_ask'] : [];
        $info = is_array($s['information_needed'] ?? null) && array_is_list($s['information_needed']) ? $s['information_needed'] : [];
        $s['information_needed'] = array_slice(array_values(array_filter($info, static fn ($x) => is_string($x) && in_array($x, $mayAsk, true))), 0, 6);
        if (($hints['hot_lead_fast_path'] ?? null) === true || (array_key_exists('question_budget', $hints) && self::jsLooseZero($hints['question_budget']))) {
            if ($s['information_needed'] !== []) {
                $c[] = 'preguntas descartadas: hot lead o sin presupuesto de preguntas';
            }
            $s['information_needed'] = [];
        }
        $s['question_needed'] = count($s['information_needed']) > 0;
        $s['response_goal'] = is_string($s['response_goal'] ?? null) ? self::jsSlice($s['response_goal'], 160) : '';
        $s['closing_opportunity'] = ($s['closing_opportunity'] ?? null) === true && ($hints['do_not_sell'] ?? null) !== true;
        $s['payment_opportunity'] = ($s['payment_opportunity'] ?? null) === true && ($hints['payment_possible'] ?? null) === true;
        $s['app_support_opportunity'] = ($s['app_support_opportunity'] ?? null) === true;
        if (($hints['hot_lead_fast_path'] ?? null) === true && ($hints['do_not_sell'] ?? null) !== true && ($s['next_best_action'] ?? null) === 'ask_discovery') {
            $c[] = 'ask_discovery a un hot lead';
            $s['next_best_action'] = 'recommend_plan';
        }
        if (($hints['do_not_sell'] ?? null) === true && ($s['next_best_action'] ?? null) === 'recommend_plan' && $s['conversation_goal'] !== 'support' && $s['conversation_goal'] !== 'onboard') {
            $c[] = 'recomendar a quien ya es cliente';
            $s['next_best_action'] = 'check_in';
            $s['recommendation_goal'] = 'none';
        }

        if (array_key_exists('main_barrier', $s) && $s['main_barrier'] !== null && ! self::jsIncludes(self::BARRIERS, $s['main_barrier'])) {
            $c[] = 'barrera desconocida: '.self::jsString($s['main_barrier'], true);
            $s['main_barrier'] = null;
        }
        if (! array_key_exists('main_barrier', $s)) {
            $s['main_barrier'] = null;
        }
        $s['confidence'] = self::jsConfidence($s['confidence'] ?? null, array_key_exists('confidence', $s));

        return ['strategy' => $s, 'corrections' => $c, 'context' => $decide, 'human_handoff_allowed' => $pide];
    }

    /**
     * LEER COMPROBACIÓN k: el json del redactor k más `ultron_check = {rewrite,
     * may_rewrite, error, codes, issues, notes}`. Solo un 200 bien formado del
     * contrato con el intento k puede pedir reescribir; cualquier otra cosa es
     * error y no reescribir. Nunca lanza.
     *
     * @param  array<string,mixed>  $composerJson  el json del redactor k
     * @param  mixed  $response  la salida del nodo HTTP (`fullResponse`: `{statusCode, body}`)
     * @return array<string,mixed>
     */
    public static function readCheck(int $k, array $composerJson, mixed $response): array
    {
        return $composerJson + ['ultron_check' => self::readCheckBody($k, $response)];
    }

    /** @return array{rewrite:bool,may_rewrite:bool,error:bool,codes:list<string>,issues:list<string>,notes:string} */
    public static function readCheckBody(int $k, mixed $r): array
    {
        $no = ['rewrite' => false, 'may_rewrite' => false, 'error' => true, 'codes' => [], 'issues' => [], 'notes' => ''];
        if (! is_array($r) || ($r['statusCode'] ?? null) !== 200) {
            return $no;
        }
        $b = $r['body'] ?? null;
        if (is_string($b)) {
            $b = json_decode($b, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $no;
            }
        }
        $forma = is_array($b) && ($b === [] || ! array_is_list($b))
            && ($b['ok'] ?? null) === true && ($b['contract'] ?? null) === self::CHECK_CONTRACT
            && in_array($b['mode'] ?? null, ['studio', 'legacy'], true) && ($b['attempt'] ?? null) === $k
            && is_bool($b['pass'] ?? null) && is_array($b['defects'] ?? null) && array_is_list($b['defects'])
            && is_array($b['issues'] ?? null) && array_is_list($b['issues'])
            && is_string($b['notes'] ?? null) && is_bool($b['may_rewrite'] ?? null) && is_bool($b['rewrite'] ?? null);
        if (! $forma) {
            return $no;
        }
        $codes = self::uniques(array_map(static fn ($d) => is_array($d) ? ($d['code'] ?? null) : null, $b['defects']), self::CHECK_CODES, self::MAX_CODES);
        $issues = self::uniques($b['issues'], self::DIMENSIONS, self::MAX_ISSUES);
        $notes = self::jsSlice(self::jsTrim($b['notes']), self::MAX_NOTES);
        $puede = $b['mode'] === 'studio' && $b['may_rewrite'] === true;
        if ($b['rewrite'] !== true) {
            return ['rewrite' => false, 'may_rewrite' => $puede, 'error' => false, 'codes' => $codes, 'issues' => $issues, 'notes' => $notes];
        }
        if (! $puede || $b['defects'] === [] || $notes === '') {
            return $no;
        }

        return ['rewrite' => true, 'may_rewrite' => true, 'error' => false, 'codes' => $codes, 'issues' => $issues, 'notes' => $notes];
    }

    /**
     * NOTA DEL SERVIDOR: la petición de reescribir, con la forma del revisor
     * (`output[0].content[0].text`), para que el redactor lea `critic_issues` y
     * `critic_notes` sin tocar su mensaje. Nunca lanza.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public static function serverNote(array $input): array
    {
        $c = is_array($input['ultron_check'] ?? null) ? $input['ultron_check'] : [];

        return ['output' => [['content' => [['text' => [
            'verdict' => 'fail',
            'score' => null,
            'issues' => is_array($c['issues'] ?? null) && array_is_list($c['issues']) ? $c['issues'] : [],
            'notes' => is_string($c['notes'] ?? null) ? $c['notes'] : '',
            'hard_fail' => null,
            'by' => 'server',
            'codes' => is_array($c['codes'] ?? null) && array_is_list($c['codes']) ? $c['codes'] : [],
        ]]]]]];
    }

    /**
     * DECIDIR INTENTO 3: el veredicto del revisor 2 tal cual, más
     * `ultron_intento3`: un tercer borrador solo si ese revisor lo rechazó y la
     * comprobación 2 dijo, sin error, que queda presupuesto. Nunca lanza.
     *
     * @param  array<string,mixed>  $input  el json del revisor 2
     * @param  array<string,mixed>|null  $read2  el json de Leer Comprobación 2, si corrió
     * @return array<string,mixed>
     */
    public static function decideThird(array $input, ?array $read2): array
    {
        $critic = self::extract($input);
        $falla = is_array($critic) && (($critic['verdict'] ?? null) === 'fail' || (is_string($critic['hard_fail'] ?? null) && $critic['hard_fail'] !== ''));
        $intento3 = false;
        if ($falla) {
            $c = is_array($read2['ultron_check'] ?? null) ? $read2['ultron_check'] : null;
            $intento3 = $c !== null && ($c['may_rewrite'] ?? null) === true && ($c['error'] ?? null) === false;
        }

        return array_merge($input, ['ultron_intento3' => $intento3]);
    }

    /**
     * Lo que PREPARAR COMMIT decide del turno: el borrador que va (el del
     * último redactor que trajo texto), su intento, el plan (el por defecto
     * del negocio si cotiza sin plan), el veredicto que llega y el bloque
     * `regeneration` (con la oferta). Sin los campos de la propuesta que no
     * cambian (los copia de la estrategia validada). Lanza, como el Code, si
     * el veredicto es ilegible o no hay borrador.
     *
     * @param  array<string,mixed>  $decide
     * @param  array<string,mixed>  $ran
     * @param  array<string,mixed>  $input  el json del revisor (o de Decidir Intento 3) que entra
     * @return array{reply_draft:string,attempt:int,recommended_plan_id:mixed,critic:array<string,mixed>,regeneration:array<string,mixed>|null}
     *
     * @throws InvalidArgumentException
     */
    public static function prepareCommit(array $decide, array $ran, array $input): array
    {
        $est = is_array($ran[self::VALIDATE]['strategy'] ?? null) ? $ran[self::VALIDATE]['strategy'] : [];
        $critic = self::extract($input);
        if (! is_array($critic)) {
            throw new InvalidArgumentException('Veredicto del critico ilegible');
        }

        $intento = 1;
        $draft = self::modelText($ran[self::COMPOSER] ?? null)['reply_draft'] ?? null;
        foreach ([self::RETRY => 2, self::RETRY_2 => 3] as $nodo => $k) {
            $alt = isset($ran[$nodo]) ? self::modelText($ran[$nodo]) : null;
            if (is_array($alt) && self::jsTruthy($alt['reply_draft'] ?? null)) {
                $intento = $k;
                $draft = $alt['reply_draft'];
            }
        }
        if (! is_string($draft) || self::jsTrim($draft) === '') {
            throw new InvalidArgumentException('El Composer no produjo borrador');
        }

        $plan = array_key_exists('recommended_plan_id', $est) ? $est['recommended_plan_id'] : null;
        if (preg_match('/\{\{\s*PLAN_(PRICE|NAME|DURATION)\s*\}\}/', $draft) === 1 && $plan === null) {
            $ctx = is_array($decide['context'] ?? null) ? $decide['context'] : [];
            $porDefecto = $ctx['default_plan_id'] ?? null;
            $activos = array_map(static fn ($p) => is_array($p) ? ($p['id'] ?? null) : null, self::listOrEmpty($ctx['active_plans'] ?? null));
            if (self::jsTruthy($porDefecto) && self::jsIncludes($activos, $porDefecto)) {
                $plan = $porDefecto;
            }
        }

        return [
            'reply_draft' => $draft,
            'attempt' => $intento,
            'recommended_plan_id' => $plan,
            'critic' => [
                'verdict' => $critic['verdict'] ?? null,
                'attempt' => $intento,
                'score' => $critic['score'] ?? null,
                'notes' => self::jsSlice(self::jsStringOf($critic['notes'] ?? null), 500),
                'issues' => array_slice(array_values(array_filter(is_array($critic['issues'] ?? null) && array_is_list($critic['issues']) ? $critic['issues'] : [], 'is_string')), 0, 8),
                'hard_fail' => is_string($critic['hard_fail'] ?? null) && $critic['hard_fail'] !== '' ? $critic['hard_fail'] : null,
            ],
            'regeneration' => self::regenerationBlock($decide, $ran),
        ];
    }

    // ── El recorrido ────────────────────────────────────────────────────────

    /**
     * El tramo entero, nodo a nodo, como n8n: una cola desde el estratega,
     * cada nodo una vez, y las aristas de {@see CONNECTIONS}.
     *
     *  - `$model(string $node, string $system, string $user): array` llama al
     *    modelo y devuelve `{ok: true, json}` (el json del nodo de OpenAI:
     *    `output[0].content[0].text` con la salida decodificada) o `{ok:
     *    false, …}` (un fallo del proveedor: el recorrido para ahí, como una
     *    ejecución de n8n que falla).
     *  - `$check(int $attempt, array $body): array` devuelve la salida del nodo
     *    HTTP (`{statusCode, body}`).
     *
     * Devuelve `{status: done|stopped|failed, order, ran, sent, bodies, commit,
     * stoppedAt, failure, error}`: `stopped` si una llamada falló (`failure` es
     * lo que devolvió), `failed` si un Code lanzó (`error`, el mensaje fijo del
     * Code), `done` con `commit` ({@see prepareCommit()}).
     *
     * @param  array<string,mixed>  $decide
     * @param  array<string,string>  $roles  los cinco prompts compilados
     * @param  callable(string,string,string):array<string,mixed>  $model
     * @param  callable(int,array<string,mixed>):array<string,mixed>  $check
     * @return array<string,mixed>
     */
    public static function walk(array $decide, array $roles, string $inbound, callable $model, callable $check): array
    {
        $ran = [];
        $orden = [];
        $enviados = [];
        $cuerpos = [];
        $cola = [[self::STRATEGIST, $decide]];
        // Por referencia: el resultado lleva lo recorrido hasta el momento de salir.
        $fin = static function (string $status, array $extra = []) use (&$orden, &$ran, &$enviados, &$cuerpos): array {
            return ['status' => $status, 'order' => $orden, 'ran' => $ran, 'sent' => $enviados, 'bodies' => $cuerpos] + $extra + ['commit' => null, 'stoppedAt' => null, 'failure' => null, 'error' => null];
        };

        while ($cola !== []) {
            [$nodo, $entrada] = array_shift($cola);
            if (in_array($nodo, $orden, true) || count($orden) > 40) {
                // Por la forma del grafo no puede pasar: si pasa, el port está roto.
                throw new InvalidArgumentException('El recorrido repite un nodo o no termina.');
            }
            $orden[] = $nodo;

            try {
                if ($nodo === self::COMMIT) {
                    $commit = self::prepareCommit($decide, $ran, $entrada);
                    $ran[$nodo] = $commit;

                    return $fin('done', ['commit' => $commit]);
                }
                if (isset(self::NODES[$nodo])) {
                    $sistema = (string) ($roles[self::NODES[$nodo]['role']] ?? '');
                    $usuario = self::encode(self::userPayload($nodo, $decide, $inbound, $ran, $entrada));
                    $enviados[$nodo] = ['system' => $sistema, 'user' => $usuario];
                    $r = $model($nodo, $sistema, $usuario);
                    if (($r['ok'] ?? false) !== true || ! is_array($r['json'] ?? null)) {
                        return $fin('stopped', ['stoppedAt' => $nodo, 'failure' => $r]);
                    }
                    $salidas = [$r['json']];
                } elseif (isset(self::CHECKS[$nodo])) {
                    $k = self::CHECKS[$nodo];
                    $redactor = $k === 1 ? self::COMPOSER : self::RETRY;
                    $cuerpo = self::checkBody($k, $ran[self::VALIDATE]['strategy'] ?? [], self::modelText($ran[$redactor] ?? null)['reply_draft'] ?? null);
                    $cuerpos[$nodo] = $cuerpo;
                    $salidas = [$check($k, $cuerpo)];
                } elseif (in_array($nodo, [self::IF_CHECK_1, self::IF_CHECK_2, self::IF_REWRITE_1, self::IF_REWRITE_2, self::IF_CRITIC, self::IF_THIRD], true)) {
                    $salidas = self::evaluateIf($nodo, $entrada, $decide) ? [$entrada, null] : [null, $entrada];
                } else {
                    $salidas = [match ($nodo) {
                        self::VALIDATE => self::validateStrategy($decide, $entrada),
                        self::READ_1 => self::readCheck(1, is_array($ran[self::COMPOSER] ?? null) ? $ran[self::COMPOSER] : [], $entrada),
                        self::READ_2 => self::readCheck(2, is_array($ran[self::RETRY] ?? null) ? $ran[self::RETRY] : [], $entrada),
                        self::NOTE_1, self::NOTE_2 => self::serverNote($entrada),
                        self::DECIDE_3 => self::decideThird($entrada, is_array($ran[self::READ_2] ?? null) ? $ran[self::READ_2] : null),
                        default => throw new InvalidArgumentException('Nodo desconocido en el tramo.'),
                    }];
                }
            } catch (InvalidArgumentException $e) {
                if (! in_array($nodo, [self::VALIDATE, self::COMMIT], true)) {
                    throw $e;
                }

                // Un Code que lanza: en n8n la ejecución falla y nadie contesta. El mensaje es el fijo del Code.
                return $fin('failed', ['stoppedAt' => $nodo, 'error' => $e->getMessage()]);
            }

            foreach ($salidas as $i => $json) {
                if ($json === null) {
                    continue;
                }
                $ran[$nodo] ??= $json;
                foreach (self::CONNECTIONS[$nodo][$i] ?? [] as $siguiente) {
                    $cola[] = [$siguiente, $json];
                }
            }
        }

        // Por la forma del grafo, todo camino llega a Preparar Commit.
        throw new InvalidArgumentException('El recorrido no llega a Preparar Commit.');
    }

    /**
     * El cuerpo de Comprobar Borrador k, sin lo que identifica el turno
     * (conversación, mensaje y token: el laboratorio no tiene).
     *
     * @param  array<string,mixed>  $strategy  la estrategia validada
     * @return array{attempt:int,draft:array{reply_draft:mixed,intent:mixed,recommended_plan_id:mixed,tools_requested:mixed}}
     */
    public static function checkBody(int $k, array $strategy, mixed $replyDraft): array
    {
        return ['attempt' => $k, 'draft' => [
            'reply_draft' => $replyDraft,
            'intent' => $strategy['intent'] ?? null,
            'recommended_plan_id' => array_key_exists('recommended_plan_id', $strategy) ? $strategy['recommended_plan_id'] : null,
            'tools_requested' => $strategy['tools_requested'] ?? null,
        ]];
    }

    /**
     * La salida de un nodo de OpenAI (`output[0].content[0].text`, o lo que
     * saca `sacar()`), o null.
     *
     * @return array<string,mixed>|null
     */
    public static function modelText(mixed $json): ?array
    {
        $t = self::extract($json);

        return is_array($t) ? $t : null;
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * El bloque `regeneration` de Preparar Commit (sin los tokens, que pone
     * quien recorre: el laboratorio los tiene de cada llamada), o null sin la
     * oferta o fuera de su rango.
     *
     * @param  array<string,mixed>  $decide
     * @param  array<string,mixed>  $ran
     * @return array<string,mixed>|null
     */
    private static function regenerationBlock(array $decide, array $ran): ?array
    {
        $rg = $decide['regeneration'] ?? null;
        if (! is_array($rg) || ($rg['contract'] ?? null) !== self::CHECK_CONTRACT) {
            return null;
        }
        $quien = static function (string $lectura, string $revisor) use ($ran): array {
            $c = is_array($ran[$lectura]['ultron_check'] ?? null) ? $ran[$lectura]['ultron_check'] : null;
            if ($c !== null && ($c['rewrite'] ?? null) === true) {
                return ['by' => 'server', 'codes' => self::uniques($c['codes'] ?? [], self::CHECK_CODES, self::MAX_CODES), 'issues' => self::uniques($c['issues'] ?? [], self::DIMENSIONS, self::MAX_ISSUES)];
            }
            $v = isset($ran[$revisor]) ? self::extract($ran[$revisor]) : null;

            return ['by' => 'critic', 'codes' => [], 'issues' => self::uniques(is_array($v) ? ($v['issues'] ?? []) : [], self::DIMENSIONS, self::MAX_ISSUES)];
        };
        $lecturas = array_values(array_map(static fn (string $n) => $ran[$n]['ultron_check'] ?? null, array_filter([self::READ_1, self::READ_2], static fn (string $n) => isset($ran[$n]))));
        $reescrituras = [];
        if (isset($ran[self::RETRY])) {
            $reescrituras[] = $quien(self::READ_1, self::CRITIC);
        }
        if (isset($ran[self::RETRY_2])) {
            $reescrituras[] = $quien(self::READ_2, self::CRITIC_2);
        }
        $bloque = [
            'contract' => self::CHECK_CONTRACT,
            'drafts' => count(array_filter(self::COMPOSERS, static fn (string $n) => isset($ran[$n]))),
            'critics' => count(array_filter(self::CRITICS, static fn (string $n) => isset($ran[$n]))),
            'checks' => count($lecturas),
            'check_errors' => count(array_filter($lecturas, static fn ($c) => ! is_array($c) || ($c['error'] ?? null) !== false)),
            'rewrites' => $reescrituras,
        ];

        return $bloque['drafts'] >= 1 && $bloque['drafts'] <= 3 && $bloque['critics'] <= 3 && $bloque['checks'] <= 2 && count($reescrituras) === $bloque['drafts'] - 1
            ? $bloque : null;
    }

    /** `sacar()` de los Code: `output[0].content[0].text` (o el json entero si no hay `output`). */
    private static function extract(mixed $j): mixed
    {
        if (! is_array($j)) {
            return $j === null ? null : $j;
        }
        if (! array_key_exists('output', $j) || $j['output'] === null) {
            return $j;
        }
        $o = $j['output'];
        if (is_array($o) && array_is_list($o)) {
            $c = is_array($o[0] ?? null) && is_array($o[0]['content'] ?? null) ? ($o[0]['content'][0] ?? null) : null;
            $t = is_array($c) ? ($c['text'] ?? null) : null;
            if (is_string($t)) {
                $d = json_decode($t, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $t = $d;
                }
            }

            return $t;
        }
        if (is_string($o)) {
            $d = json_decode($o, true);

            return json_last_error() === JSON_ERROR_NONE ? $d : $o;
        }

        return $o;
    }

    /**
     * Un valor del contexto por su ruta: `[existe, valor]` (existe = false es
     * el `undefined` de JavaScript, que `JSON.stringify` omite).
     *
     * @param  list<string>  $ruta
     * @return array{0:bool,1:mixed}
     */
    private static function lookup(array $de, array $ruta): array
    {
        $v = $de;
        foreach ($ruta as $paso) {
            if (! is_array($v) || ! array_key_exists($paso, $v)) {
                return [false, null];
            }
            $v = $v[$paso];
        }

        return [true, $v];
    }

    /**
     * @param  list<string>  $claves
     * @return array<string,mixed>
     */
    private static function subset(array $de, array $claves): array
    {
        $out = [];
        foreach ($claves as $k) {
            if (array_key_exists($k, $de)) {
                $out[$k] = $de[$k];
            }
        }

        return $out;
    }

    /** @return list<mixed> `x || []` sobre lo que debería ser una lista */
    private static function listOrEmpty(mixed $v): array
    {
        return is_array($v) && array_is_list($v) ? $v : [];
    }

    /**
     * `unicos()` de los Code: los de la lista que son del vocabulario, sin
     * repetir y hasta el tope.
     *
     * @param  list<string>  $vocabulario
     * @return list<string>
     */
    private static function uniques(mixed $lista, array $vocabulario, int $tope): array
    {
        $out = [];
        foreach (is_array($lista) && array_is_list($lista) ? $lista : [] as $x) {
            if (is_string($x) && in_array($x, $vocabulario, true) && ! in_array($x, $out, true)) {
                $out[] = $x;
            }
        }

        return array_slice($out, 0, $tope);
    }

    /** `Array.prototype.includes` (SameValueZero): sin conversión de tipos. */
    private static function jsIncludes(array $lista, mixed $x): bool
    {
        foreach ($lista as $v) {
            if ($v === $x || (is_int($v) && is_float($x) && (float) $v === $x) || (is_float($v) && is_int($x) && $v === (float) $x)) {
                return true;
            }
        }

        return false;
    }

    private static function jsTruthy(mixed $v): bool
    {
        return ! ($v === null || $v === false || $v === 0 || $v === 0.0 || $v === '' || (is_float($v) && is_nan($v)));
    }

    /** `hints.question_budget === 0`. */
    private static function jsLooseZero(mixed $v): bool
    {
        return $v === 0 || $v === 0.0;
    }

    /** `String.prototype.trim` (los espacios de JavaScript, también los Unicode). */
    private static function jsTrim(string $s): string
    {
        return (string) preg_replace('/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $s);
    }

    /** `String.prototype.slice(0, n)`, en unidades UTF-16 como JavaScript. */
    private static function jsSlice(string $s, int $n): string
    {
        $u = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        if (strlen($u) <= 2 * $n) {
            return $s;
        }

        return (string) mb_convert_encoding(substr($u, 0, 2 * $n), 'UTF-8', 'UTF-16LE');
    }

    /** `String(x || "")`. */
    private static function jsStringOf(mixed $v): string
    {
        if (! self::jsTruthy($v)) {
            return '';
        }

        return is_string($v) ? $v : self::jsString($v, true);
    }

    /** Cómo concatena JavaScript un valor en una cadena (`"…: " + x`). */
    private static function jsString(mixed $v, bool $existe): string
    {
        return match (true) {
            ! $existe => 'undefined',
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v) => (string) $v,
            is_float($v) => self::jsNumber($v),
            is_string($v) => $v,
            is_array($v) && array_is_list($v) => implode(',', array_map(static fn ($x) => $x === null ? '' : self::jsString($x, true), $v)),
            default => '[object Object]',
        };
    }

    /** `String(n)`: la forma más corta que vuelve al mismo número, sin «.0». */
    private static function jsNumber(float $v): string
    {
        if (is_nan($v)) {
            return 'NaN';
        }
        $s = (string) json_encode($v);

        return str_ends_with($s, '.0') ? substr($s, 0, -2) : $s;
    }

    /** `Number(c)`, `isNaN` → 0,5 y acotado a [0, 1]. */
    private static function jsConfidence(mixed $v, bool $existe): float|int
    {
        $n = match (true) {
            ! $existe => NAN,
            $v === null, $v === false => 0,
            $v === true => 1,
            is_int($v), is_float($v) => $v,
            is_string($v) => trim($v) === '' ? 0 : (is_numeric(trim($v)) ? trim($v) + 0 : NAN),
            is_array($v) => $v === [] ? 0 : (count($v) === 1 && array_is_list($v) && (is_int($v[0]) || is_float($v[0])) ? $v[0] : NAN),
            default => NAN,
        };
        if (is_float($n) && is_nan($n)) {
            return 0.5;
        }

        return max(0, min(1, $n));
    }
}
