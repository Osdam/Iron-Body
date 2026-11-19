<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\Prompts\UltronPromptStamp;
use App\Services\Observability\ChannelLog;

/**
 * ¿ACEPTARÍA EL SERVIDOR ESTE BORRADOR? (cierre V2, apartado 4, «regenerar
 * antes de sustituir»; plan del arquitecto `plan-arquitecto-a4-regenerar.md`
 * §3 P-1, P-2, P-6 y P-8, §4.1 y §6 «Ola 4»; contrato `ultron-regen/1`).
 *
 * n8n le pregunta al servidor, entre el redactor y el revisor, si el borrador
 * incumple algo que el modelo puede corregir reescribiendo: el registro de
 * usted, el tope de longitud, la lista de planes o el beneficio de otro plan
 * ({@see UltronDraftDefects}, con las MISMAS funciones que el commit). Si lo
 * incumple y queda presupuesto, n8n pide otra redacción con la nota exacta.
 *
 * DE SOLO LECTURA, y es la mitad del diseño:
 *  - Ni cerrojo, ni escrituras, ni red, ni despachador. Lee el texto del
 *    mensaje (solo para saber si la persona pregunta un precio), el catálogo
 *    vendible y, si el borrador cotiza, el plan que pondría el precio.
 *  - Del token usa {@see UltronDecideToken::peek()}: la firma, la caducidad y
 *    el par conversación/mensaje (sin token válido para ESE par no se mira
 *    nada ajeno). No recalcula la fase, las transiciones ni el conocimiento: si
 *    el mundo cambió, lo decide el commit (`stale_decision`), como hoy. Tampoco
 *    lee la cadena del Studio: la versión, el tope y el tono son los firmados
 *    en `sp`.
 *  - Es un AVISO: el commit no lee su resultado y lo vuelve a comprobar todo
 *    sobre su texto final, que puede diferir (pega enlaces o el cobro, retira
 *    frases). La paridad y sus excepciones las fija
 *    `UltronCheckCommitParityTest`.
 *  - No es un oráculo de las guardas: no dice nada del guard de contenido ni
 *    del dinero, que siguen solo en el commit.
 *
 * SIN VERSIÓN DEL STUDIO (`sp.m` ≠ `studio`), contesta `legacy` sin leer nada:
 * Legacy no cambia ni un byte (P-6, defensa en profundidad: n8n ni siquiera
 * pregunta en ese caso).
 *
 * EL PRESUPUESTO lo calcula el servidor, con el instante firmado en el token y
 * sin el reloj de n8n (P-2): se concede otra redacción si este no es el tercer
 * borrador y han pasado como mucho {@see self::TIME_BUDGET_SECONDS} segundos
 * desde `decide`. El techo de verdad es el job de 45 s
 * (`MARKETING_ULTRON_TIMEOUT`), no el TTL de 120 s del token.
 *
 * NI LA RESPUESTA NI EL LOG LLEVAN TEXTO DE LA PERSONA NI EL TOKEN: códigos,
 * números, marcas de la lista cerrada del detector y beneficios del catálogo.
 */
final class UltronDraftCheck
{
    /** El nombre del contrato (§4). Lo ofrece `decide` y lo exige el commit en su traza. */
    public const CONTRACT = 'ultron-regen/1';

    /** Como mucho tres redacciones por turno: la primera y dos reescrituras. */
    public const MAX_DRAFTS = 3;

    /** Lo de siempre, sin versión del Studio: una redacción y la reescritura que pide el revisor. */
    public const LEGACY_MAX_DRAFTS = 2;

    /**
     * Pasados estos segundos desde `decide` no se concede otra redacción (§3
     * P-2) [supuesto a calibrar con la p95 del informe de origen, ola 6]. Una
     * constante y no `config/`: es parte del contrato, y moverla es una
     * decisión que se prueba.
     */
    public const TIME_BUDGET_SECONDS = 25;

    public const MODE_STUDIO = 'studio';

    public const MODE_LEGACY = 'legacy';

    /** Por qué no se concede otra redacción (`budget.reason`). */
    public const REASON_LAST_ATTEMPT = 'last_attempt';

    public const REASON_TIME_BUDGET = 'time_budget';

    public const REASON_LEGACY = 'legacy';

    public function __construct(
        private readonly UltronDecideToken $tokens,
        private readonly MarketingKnowledgeBaseService $knowledge,
        private readonly UltronDraftPlaceholders $placeholders,
    ) {}

    /**
     * @param  array{conversation_id:int,message_id:int,decide_token:string,attempt:int,draft:array{reply_draft:string,intent:string,recommended_plan_id?:int|null,tools_requested?:list<string>|null}}  $data  ya validado en su FORMA por el controlador
     * @return array<string,mixed>|null la respuesta 200, o null si el token no vale para ese par (el controlador contesta 422 `invalid_token`, sin detalle)
     */
    public function check(array $data): ?array
    {
        $conversationId = (int) $data['conversation_id'];
        $messageId = (int) $data['message_id'];
        $attempt = (int) $data['attempt'];

        $token = $this->tokens->peek($data['decide_token'] ?? null, $conversationId, $messageId);
        if (! $token['valid']) {
            return null;
        }

        $sp = is_array($token['extra']['sp'] ?? null) ? $token['extra']['sp'] : [];
        if (($sp['m'] ?? null) !== UltronPromptStamp::MODE_STUDIO) {
            return self::respuesta(self::MODE_LEGACY, $attempt, [], false, ['max_drafts' => self::LEGACY_MAX_DRAFTS, 'elapsed_s' => null, 'reason' => self::REASON_LEGACY]);
        }

        $draft = (array) $data['draft'];
        $raw = (string) $draft['reply_draft'];
        $intent = (string) $draft['intent'];
        $tools = array_values((array) ($draft['tools_requested'] ?? []));

        /*
         * La lista del turno, con las mismas reglas que el commit (paso 8.ter):
         * la firmada, si tiene la forma que emite `decide`, y vacía en un turno
         * de cobro. Una forma rara solo la fabrica quien re-firma el token: se
         * trata como «sin lista» sin avisar aquí, porque el commit del mismo
         * turno lo registra (`ultron.commit.plans_overview_invalid`).
         */
        $po = UltronPlansOverview::signedList($token['extra']['po'] ?? null) ?? [];
        $poDelTurno = UltronPlansOverview::isMoneyTurn($intent, $tools) ? [] : $po;

        $catalog = $this->knowledge->activePlans();
        $inbound = (string) MarketingMessage::query()->whereKey($messageId)->where('conversation_id', $conversationId)->value('body');

        $defectos = UltronDraftDefects::of(
            ['reply_draft' => $raw, 'intent' => $intent, 'tools_requested' => $tools],
            $this->expandido($raw, $draft['recommended_plan_id'] ?? null, $tools, $poDelTurno),
            $sp,
            $poDelTurno,
            $catalog,
            $inbound,
        );

        $elapsed = max(0, now()->getTimestamp() - (int) $token['issued_at']);
        $reason = match (true) {
            $attempt >= self::MAX_DRAFTS => self::REASON_LAST_ATTEMPT,
            $elapsed > self::TIME_BUDGET_SECONDS => self::REASON_TIME_BUDGET,
            default => null,
        };
        $respuesta = self::respuesta(self::MODE_STUDIO, $attempt, $defectos, $reason === null, ['max_drafts' => self::MAX_DRAFTS, 'elapsed_s' => $elapsed, 'reason' => $reason]);

        // Códigos y números: ni el borrador, ni el entrante, ni las notas, ni el token.
        ChannelLog::info('ultron.check.result', [
            'conversation_id' => $conversationId,
            'attempt' => $attempt,
            'codes' => array_map(fn (array $d): string => $d['detail'] === null ? $d['code'] : $d['code'].'/'.$d['detail'], $defectos),
            'may_rewrite' => $respuesta['may_rewrite'],
            'rewrite' => $respuesta['rewrite'],
            'reason' => $reason,
            'elapsed_s' => $elapsed,
        ]);

        return $respuesta;
    }

    /**
     * EL TEXTO EXPANDIDO, SOLO PARA MEDIR (§4.1, paso 3): lo que mediría el
     * commit, hasta donde se puede saber sin efectos.
     *  - `{{PLAN_k}}` de la lista del turno → «Nombre ($precio COP)» del plan
     *    vendible AHORA ({@see UltronPlansOverview::item()}); el de un plan que
     *    ya no se vende queda literal (eso lo decide el commit).
     *  - `{{PLAN_PRICE}}`/`NAME`/`DURATION` → el plan propuesto si se vende; si
     *    no, el mensual por defecto, la misma regla que Preparar Commit.
     *  - `{{APP_LINKS}}` → los enlaces de la app; si se piden sin marcador, al
     *    final, como `conEnlaces()`. Aproximación declarada: el commit no los
     *    vuelve a pegar si salieron hace menos de una hora (eso está en la
     *    memoria, que aquí no se lee), y en un turno de cobro pega además la
     *    línea del enlace de pago, que aquí no se acuña.
     *
     * @param  list<string>  $tools
     * @param  list<int>  $po
     */
    private function expandido(string $raw, mixed $planId, array $tools, array $po): string
    {
        $texto = $raw;

        if ($po !== []) {
            $nombra = UltronPlansOverview::expand($texto, array_fill_keys($po, ''), $po)['used'];
            $textos = [];
            if ($nombra !== []) {
                foreach (Plan::query()->sellable()->whereIn('id', $nombra)->get() as $plan) {
                    $textos[(int) $plan->id] = UltronPlansOverview::item($plan);
                }
            }
            $texto = UltronPlansOverview::expand($texto, $textos, $po)['text'];
        }

        if ($this->placeholders->needsPlan($texto)) {
            // El id como lo lee el commit (`resolvePlan()`): un entero, o nada.
            $plan = is_numeric($planId) ? Plan::query()->sellable()->whereKey((int) $planId)->first() : null;
            $texto = $this->placeholders->resolve($texto, $plan ?? $this->knowledge->defaultMonthlyPlan());
        }

        if ($this->placeholders->wantsAppLinks($texto)) {
            $texto = $this->placeholders->resolveAppLinks($texto, MobileAppCatalog::linksInline());
        } elseif (in_array(SalesIntents::TOOL_APP_LINKS_SEND, $tools, true)) {
            $texto = rtrim($texto)."\n\n".MobileAppCatalog::linksInline();
        }

        return $texto;
    }

    /**
     * La respuesta 200, con su lista blanca de claves y en su orden (§4.1).
     * Cada defecto, sin su nota (las notas van unidas en `notes`, cortadas a
     * 500 por notas enteras); los `issues`, del vocabulario del revisor y sin
     * repetir.
     *
     * @param  list<array{code:string,detail:?string,issue:string,note:string}>  $defectos
     * @param  array{max_drafts:int,elapsed_s:?int,reason:?string}  $budget
     * @return array<string,mixed>
     */
    private static function respuesta(string $mode, int $attempt, array $defectos, bool $mayRewrite, array $budget): array
    {
        return [
            'ok' => true,
            'contract' => self::CONTRACT,
            'mode' => $mode,
            'attempt' => $attempt,
            'pass' => $defectos === [],
            'defects' => array_map(fn (array $d): array => ['code' => $d['code'], 'detail' => $d['detail'], 'issue' => $d['issue']], $defectos),
            'issues' => CriticContract::issues(array_column($defectos, 'issue')),
            'notes' => UltronRegenerationNotes::merge(array_column($defectos, 'note')),
            'may_rewrite' => $mayRewrite,
            'rewrite' => $defectos !== [] && $mayRewrite,
            'budget' => $budget,
        ];
    }
}
