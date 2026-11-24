<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingConversation;
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
 * Y LAS REGLAS DE POLÍTICA CORREGIBLES (ola 4b, decisión L-3 del lead): el
 * saludo que vende, la visita a medias perdida, el primer plan equivocado, la
 * información sin el dato, solo preguntas y la memoria que vende. La política
 * del turno se calcula IGUAL que en el commit ({@see UltronTurnPolicyReader}:
 * la memoria se LEE) y el texto que se mira es el del commit: el plan que
 * impone la política pone `{{PLAN_NAME}}`/`{{PLAN_PRICE}}`, el modo memoria
 * quita los enlaces de la app, y los recortes de la pregunta final los hace
 * {@see UltronDraftDefects::ofPolicy()}. Van DETRÁS de los cuatro de §4.4, con
 * el tope {@see UltronDraftDefects::MAX_DEFECTS}.
 *
 * DE SOLO LECTURA, y es la mitad del diseño:
 *  - Ni cerrojo, ni escrituras, ni red, ni despachador. Lee el texto del
 *    mensaje (para saber si la persona pregunta un precio y para la política),
 *    el catálogo vendible, la conversación y su memoria (sin escribirla), los
 *    hechos del gimnasio si la política los exige y, si el borrador cotiza, el
 *    plan que pondría el precio.
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
 *  - No es un oráculo de las guardas: no dice nada del guard de contenido,
 *    de las URL, de los datos de tarjeta ni del pago o la membresía
 *    inventados, que siguen solo en el commit. Del dinero dice una sola cosa,
 *    y a propósito: con la lista, `plans_overview/price_written` es la regla
 *    del validador (una cifra escrita por el modelo), con su nota fija. Es
 *    R-5 de seguridad, aceptado y declarado.
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

    /**
     * «LISTA FIRME» (PT-17, puerta 1, L4; decisión P-1 del propietario del
     * 24-11-2026): el borrador es la lista limpia de un turno de lista
     * ({@see UltronDraftDefects::isListFinal()}) y no se pide otro. Sin
     * tocar n8n: «Decidir Intento 3» solo lanza el tercer borrador con
     * `may_rewrite === true`, así que un segundo borrador que ya vale no se
     * pierde porque el revisor lo rechace y el tercero empeore.
     *
     * RIESGO R5 DEL DIAGNÓSTICO, aceptado y dicho aquí: dentro del mismo
     * contrato `ultron-regen/1`, `may_rewrite=false` deja de significar solo
     * «no queda presupuesto» (el último intento o el tiempo) y pasa a
     * significar también «este borrador es definitivo». Quien lea el campo
     * para medir el presupuesto debe mirar `budget.reason`.
     */
    public const REASON_LIST_FINAL = 'list_final';

    public function __construct(
        private readonly UltronDecideToken $tokens,
        private readonly MarketingKnowledgeBaseService $knowledge,
        private readonly UltronDraftPlaceholders $placeholders,
        private readonly UltronTurnPolicyReader $policyReader,
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

        $inspeccion = $this->inspect($conversationId, $messageId, $sp, $token['extra']['po'] ?? null, (array) $data['draft']);
        $defectos = $inspeccion['defects'];

        $elapsed = max(0, now()->getTimestamp() - (int) $token['issued_at']);
        $reason = match (true) {
            $attempt >= self::MAX_DRAFTS => self::REASON_LAST_ATTEMPT,
            $elapsed > self::TIME_BUDGET_SECONDS => self::REASON_TIME_BUDGET,
            // La lista limpia de un turno de lista no se reescribe (L4): ver la nota de R5 en la constante.
            $inspeccion['list_final'] => self::REASON_LIST_FINAL,
            default => null,
        };
        $respuesta = self::respuesta(self::MODE_STUDIO, $attempt, $defectos, $reason === null, ['max_drafts' => self::MAX_DRAFTS, 'elapsed_s' => $elapsed, 'reason' => $reason]);

        // Una nota que no se pudo escribir (C6): el defecto va sin ella, y aquí queda dicho (códigos, sin parámetros).
        $sinNota = array_values(array_map(fn (array $d): string => $d['detail'] === null ? $d['code'] : $d['code'].'/'.$d['detail'], array_filter($defectos, fn (array $d): bool => $d['note'] === '')));
        if ($sinNota !== []) {
            ChannelLog::warning('ultron.check.note_unavailable', ['conversation_id' => $conversationId, 'codes' => $sinNota]);
        }

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
     * LO QUE ESTA COMPROBACIÓN LE ENCUENTRA A UN BORRADOR, sin el token ni el
     * presupuesto de intentos: los defectos de {@see self::check()} (los
     * cuatro de §4.4 y, detrás, los de la política, con el tope
     * {@see UltronDraftDefects::MAX_DEFECTS}) y la medida del texto expandido
     * ({@see UltronReplyFitter::measure()}), con el `sp` y la lista que firmó
     * `decide` para ESE turno. Quien llama ya verificó el token.
     *
     * La usan `check()` y el commit (corrección (1b) del turno 2 de la tercera
     * prueba real, 22-11-2026): cuando el revisor rechaza el último borrador,
     * la fila del respaldo anota lo que el servidor le habría encontrado, para
     * separar «lo rechazó el revisor» de «el revisor y el servidor». Las MISMAS
     * lecturas, de solo lectura, que `check()`.
     *
     * Y si el borrador es la lista firme del turno (`list_final`, PT-17 L4,
     * {@see UltronDraftDefects::isListFinal()}): con eso `check()` no pide otro
     * y el commit trata un rechazo blando del revisor como un aviso.
     *
     * @param  array<string,mixed>  $sp  el `sp` firmado; sin `m = studio`, ningún defecto
     * @param  mixed  $po  la lista firmada tal cual (`x.po`); una forma rara cuenta como «sin lista»
     * @param  array{reply_draft:string,intent:string,recommended_plan_id?:mixed,tools_requested?:list<string>|null}  $draft
     * @return array{defects:list<array{code:string,detail:?string,issue:string,note:string}>,length:int,list_final:bool}
     */
    public function inspect(int $conversationId, int $messageId, array $sp, mixed $po, array $draft): array
    {
        $raw = (string) ($draft['reply_draft'] ?? '');
        $intent = (string) ($draft['intent'] ?? '');
        $tools = array_values((array) ($draft['tools_requested'] ?? []));

        /*
         * La lista del turno, con las mismas reglas que el commit (paso 8.ter):
         * la firmada, si tiene la forma que emite `decide`, y vacía en un turno
         * de cobro. Una forma rara solo la fabrica quien re-firma el token: se
         * trata como «sin lista» sin avisar aquí, porque el commit del mismo
         * turno lo registra (`ultron.commit.plans_overview_invalid`).
         */
        $po = UltronPlansOverview::signedList($po) ?? [];
        $poDelTurno = UltronPlansOverview::isMoneyTurn($intent, $tools) ? [] : $po;

        $catalog = $this->knowledge->activePlans();
        $inbound = (string) MarketingMessage::query()->whereKey($messageId)->where('conversation_id', $conversationId)->value('body');

        /*
         * La política del turno, como la calcula el commit (ola 4b). El token
         * ata el par, así que la conversación existe; si no (se borró en estos
         * segundos), no hay política que mirar y el commit lo decidirá.
         */
        $conversation = MarketingConversation::query()->whereKey($conversationId)->first();
        $turno = $conversation === null ? null : $this->policyReader->read($conversation, $inbound, $intent, $catalog);
        $politica = $turno['policy'] ?? null;
        // En modo memoria el commit retira los enlaces de la app (el marcador y la herramienta) antes de nada.
        $modoMemoria = ($politica['memory_mode'] ?? false) === true;
        $conEnlaces = ! $modoMemoria && in_array(SalesIntents::TOOL_APP_LINKS_SEND, $tools, true);

        $textosDeLaLista = $this->textosDeLaLista($poDelTurno);
        $expandido = $this->expandido($raw, $draft['recommended_plan_id'] ?? null, $tools, $poDelTurno, $textosDeLaLista, $politica, $sp, $catalog);
        // Con la lista del servidor, tampoco se escribe a mano un plan que no se vende ahora (revisión final de la puerta 2, I1-a): una lectura más, solo en ese turno.
        $conocidos = UltronPlansOverview::serverList($sp) && $poDelTurno !== [] ? $this->knowledge->knownPlans() : null;
        $defectos = array_slice([
            ...UltronDraftDefects::of(
                ['reply_draft' => $raw, 'intent' => $intent, 'tools_requested' => $tools],
                $expandido,
                $sp,
                $poDelTurno,
                $catalog,
                $inbound,
                $textosDeLaLista,
                $conocidos,
            ),
            // En un turno de lista (compilador 6; PT-17 L2, lo traiga o no el borrador), el tope de la lista y sin recortar la pregunta final, como el commit.
            ...($politica === null ? [] : UltronDraftDefects::ofPolicy($expandido, $sp, $politica, $catalog, $turno['facts'], $conEnlaces, UltronReplyFitter::plansListCap($sp, $poDelTurno))),
        ], 0, UltronDraftDefects::MAX_DEFECTS);

        return [
            'defects' => $defectos,
            'length' => UltronReplyFitter::measure($expandido),
            // Sin la política del turno no se miraron sus reglas: entonces no es firme (falla cerrado).
            'list_final' => $politica !== null && UltronDraftDefects::isListFinal($raw, $sp, $poDelTurno, $catalog, $defectos),
        ];
    }

    /**
     * EL TEXTO EXPANDIDO, SOLO PARA MEDIR (§4.1, paso 3): lo que mediría el
     * commit, hasta donde se puede saber sin efectos.
     *  - `{{PLAN_k}}` de la lista del turno → «Nombre ($precio COP)» del plan
     *    vendible AHORA ({@see UltronPlansOverview::item()}); el de un plan que
     *    ya no se vende queda literal (eso lo decide el commit).
     *  - `{{PLAN_PRICE}}`/`NAME`/`DURATION` → el plan propuesto si se vende; si
     *    no, el mensual por defecto, la misma regla que Preparar Commit. Con la
     *    política del turno (ola 4b), el plan que ella IMPONE (la prioridad
     *    comercial o el que nombró la persona) si no es el propuesto: el
     *    commit pisa el plan igual («LA POLÍTICA PISA EL PLAN DEL MODELO»).
     *  - `{{APP_LINKS}}` → los enlaces de la app; si se piden sin marcador, al
     *    final, como `conEnlaces()`. Aproximación declarada: el commit no los
     *    vuelve a pegar si salieron hace menos de una hora (para eso habría que
     *    mirar cuándo salieron), y en un turno de cobro pega además la línea
     *    del enlace de pago, que aquí no se acuña. En modo memoria (ola 4b),
     *    ninguno: el commit quita el marcador y la herramienta.
     *
     * Con la lista del servidor (`k` ≥ 8; PT-17, puerta 2, L1), la lista se
     * pone con {@see UltronPlansOverview::render()}, el mismo punto que el
     * commit y el laboratorio: la ranura (o, sin ella, antes de la pregunta
     * final) pasa a la lista entera.
     *
     * @param  list<string>  $tools
     * @param  list<int>  $po
     * @param  array<int,string>  $textosDeLaLista  {@see self::textosDeLaLista()}
     * @param  array<string,mixed>|null  $politica  la política del turno, si se pudo leer
     * @param  array<string,mixed>  $sp  el `sp` firmado
     * @param  array<int,array<string,mixed>>  $catalog  `activePlans()`
     */
    private function expandido(string $raw, mixed $planId, array $tools, array $po, array $textosDeLaLista, ?array $politica = null, array $sp = [], array $catalog = []): string
    {
        $texto = $raw;

        if ($po !== []) {
            // Solo se sustituyen los marcadores que lleva el borrador (forma A); con la forma B, la lista entera en su sitio.
            $texto = UltronPlansOverview::render($texto, $po, $textosDeLaLista, $catalog, UltronPlansOverview::serverList($sp))['text'];
        }

        if ($this->placeholders->needsPlan($texto)) {
            // El plan que impone la política pisa el propuesto, con la misma condición que el commit.
            $obligado = $politica['required_plan_id'] ?? null;
            if ($obligado !== null && in_array($politica['required_plan_source'] ?? null, ['commercial_priority', 'named_by_person'], true)
                && (! is_numeric($planId) || (int) $obligado !== (int) $planId)) {
                $planId = (int) $obligado;
            }
            // El id como lo lee el commit (`resolvePlan()`): un entero, o nada.
            $plan = is_numeric($planId) ? Plan::query()->sellable()->whereKey((int) $planId)->first() : null;
            // El nombre sin llaves, como en el commit de un turno del Studio (C1): la puerta solo mira turnos del Studio.
            $texto = $this->placeholders->resolve($texto, $plan ?? $this->knowledge->defaultMonthlyPlan(), true);
        }

        if (($politica['memory_mode'] ?? false) === true) {
            return $this->placeholders->resolveAppLinks($texto, '');
        }

        if ($this->placeholders->wantsAppLinks($texto)) {
            $texto = $this->placeholders->resolveAppLinks($texto, MobileAppCatalog::linksInline());
        } elseif (in_array(SalesIntents::TOOL_APP_LINKS_SEND, $tools, true)) {
            $texto = rtrim($texto)."\n\n".MobileAppCatalog::linksInline();
        }

        return $texto;
    }

    /**
     * El texto de cada plan de la lista del turno que se vende AHORA
     * («Nombre ($precio COP)», {@see UltronPlansOverview::item()}), en una
     * sola consulta: el de un plan que ya no se vende falta (su marcador queda
     * literal, eso lo decide el commit). Sirve para expandir el borrador y
     * para el presupuesto de la lista de las notas
     * ({@see UltronDraftDefects::listBudget()}): de TODOS los ofrecidos, los
     * nombre o no el borrador. Vacío sin lista.
     *
     * @param  list<int>  $po
     * @return array<int,string> plan_id → su texto
     */
    private function textosDeLaLista(array $po): array
    {
        $textos = [];
        if ($po !== []) {
            foreach (Plan::query()->sellable()->whereIn('id', $po)->get() as $plan) {
                $textos[(int) $plan->id] = UltronPlansOverview::item($plan);
            }
        }

        return $textos;
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
