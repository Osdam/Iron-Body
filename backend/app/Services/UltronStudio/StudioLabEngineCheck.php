<?php

namespace App\Services\UltronStudio;

use App\Models\Plan;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use App\Services\Marketing\Ultron\ConversationMemory;
use App\Services\Marketing\Ultron\CriticContract;
use App\Services\Marketing\Ultron\GymFactsProvider;
use App\Services\Marketing\Ultron\UltronDecideService;
use App\Services\Marketing\Ultron\UltronDraftDefects;
use App\Services\Marketing\Ultron\UltronDraftPlaceholders;
use App\Services\Marketing\Ultron\UltronFormalReplies;
use App\Services\Marketing\Ultron\UltronPlansOverview;
use App\Services\Marketing\Ultron\UltronRegenerationNotes;
use App\Services\Marketing\Ultron\UltronRegisterDetector;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use App\Services\Marketing\Ultron\UltronTurnPolicyReader;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * LA COMPROBACIÓN DEL SERVIDOR (`ai/check`, contrato `ultron-regen/1`) PARA EL
 * RECORRIDO DEL MOTOR EN EL LABORATORIO (apartado 5 del cierre; diseño
 * `plans/informes-cierre/diseno-arq-lab.md` §3.1, B4). Uno de los DOS
 * lectores del motor del laboratorio (enmienda de H4 §A4 del 22-11-2026, con
 * `StudioLabEngineContext`): nombra el motor, SOLO LEE y nunca escribe.
 *
 * {@see check()} es `UltronDraftCheck::check()` sin el token ni la
 * conversación: el turno lo trae quien llama ({@see StudioLabEngineContext}),
 * con lo que el motor firmaría en el token (`sp`, `po`) y lo que leería al
 * comprobar (el catálogo vendible, el texto de la persona, la fase, la memoria
 * y el referente). Usa las MISMAS funciones del motor y en el mismo orden: los
 * defectos de {@see UltronDraftDefects::of()} y
 * {@see UltronDraftDefects::ofPolicy()} sobre el texto expandido como lo
 * expande el motor (el port de `UltronDraftCheck::expandido()`, que es
 * privado), la política con {@see CommercialTurnPolicy::decide()} como
 * {@see UltronTurnPolicyReader::read()}, el presupuesto con el intento y los
 * segundos desde el principio del caso (el «desde `decide`» del laboratorio)
 * y la respuesta con su lista blanca de claves. T4 lo compara con el `POST
 * ai/check` real sobre una conversación sembrada y más de 30 borradores.
 *
 * {@see predict()} dice si el texto final lo escribiría el modelo o saldría un
 * respaldo del servidor, con la regla del commit (`UltronCommitService`): el
 * revisor que lo rechaza tras el último intento ({@see CriticContract::isFail()}),
 * o un defecto que queda en el último borrador y que el commit no arregla
 * recortando (la longitud que no cabe quitando la pregunta final, el tuteo
 * fuera de esa pregunta o sin bloque de Laravel, la lista de planes, el
 * beneficio de otro plan y las seis reglas de política). Desde el compilador
 * 6, en un turno de lista todo borrador se mide contra el tope de la lista y
 * no se recorta ({@see UltronReplyFitter::plansListCap()}; PT-17 L2: un solo
 * tope por turno); `cap` dice el tope que se le aplicó. Un borrador que va a
 * sustituirse no pasa por el recorte: `trimmed` es false y `chars` es su
 * medida entera (lo que sale es el respaldo). Y en un turno de lista, el
 * rechazo BLANDO del revisor sobre la lista limpia del turno es un aviso, no
 * un respaldo (PT-17 L4, como el commit): sale del modelo con
 * `criticAdvisory`. Es una PREDICCIÓN:
 * siguen valiendo las once excepciones de `UltronCheckCommitParityTest` (el
 * commit pega o retira texto antes de medir). El texto del respaldo no se
 * genera.
 *
 * NI LOG, NI ESCRITURAS, NI RED, NI CONFIGURACIÓN: del motor solo funciones
 * puras y tres lecturas del catálogo (los planes vendibles por id y el mensual
 * por defecto) y, si la política exige los hechos del gimnasio, la base de
 * conocimiento y las clases. Lo vigilan `UltronStudioEngineReadOnlyTest` y
 * `StudioLabIsolationTest`.
 */
final class StudioLabEngineCheck
{
    public const CONTRACT = StudioLabEngineFlow::CHECK_CONTRACT;

    public const MODE_STUDIO = 'studio';

    public const MODE_LEGACY = 'legacy';

    public const REASON_LAST_ATTEMPT = 'last_attempt';

    public const REASON_TIME_BUDGET = 'time_budget';

    public const REASON_LEGACY = 'legacy';

    /** «Lista firme» (PT-17 L4): la de `UltronDraftCheck::REASON_LIST_FINAL`, con su riesgo R5 dicho allí. */
    public const REASON_LIST_FINAL = 'list_final';

    /** Lo que dice la predicción del texto final. */
    public const ORIGIN_MODEL = 'model';

    public const ORIGIN_FALLBACK = 'fallback';

    /** La causa de un respaldo cuando lo pide el revisor (las demás son códigos de la comprobación). */
    public const CAUSE_CRITIC = 'critic_failed';

    public function __construct(
        private readonly MarketingKnowledgeBaseService $knowledge,
        private readonly UltronDraftPlaceholders $placeholders = new UltronDraftPlaceholders,
        private readonly GymFactsProvider $gym = new GymFactsProvider,
    ) {}

    /**
     * LO QUE RECIBE N8N DEL NODO «Comprobar Borrador k» (`fullResponse`):
     * `{statusCode, body}`. Con el borde HTTP del endpoint, en su orden: los
     * textos se recortan y los vacíos pasan a null (`TrimStrings` y
     * `ConvertEmptyStringsToNull`, globales), y el cuerpo se valida con las
     * reglas del controlador (`UltronController::check()`); si no pasan, un
     * 422 sin detalle, que Leer Comprobación lee como «no reescribir». El
     * token no se mira: el laboratorio no tiene (el turno ya es el suyo).
     *
     * @param  array<string,mixed>  $turn  como en {@see check()}
     * @param  array{attempt:int,draft:array<string,mixed>}  $body  {@see StudioLabEngineFlow::checkBody()}
     * @return array{statusCode:int,body:array<string,mixed>}
     */
    public function respond(array $turn, array $body, int $elapsedSeconds): array
    {
        $datos = self::likeTheRequest($body);
        $v = Validator::make($datos, [
            'attempt' => ['required', 'integer', 'min:1', 'max:'.StudioLabEngineFlow::MAX_DRAFTS],
            'draft' => ['required', 'array'],
            'draft.reply_draft' => ['required', 'string', 'max:4000'],
            'draft.intent' => ['required', 'string', Rule::in(SalesAgentDecisionSchema::INTENTS)],
            'draft.recommended_plan_id' => ['nullable', 'integer', 'min:1'],
            'draft.tools_requested' => ['nullable', 'array', 'max:'.count(UltronDecideService::TOOL_VOCABULARY)],
            'draft.tools_requested.*' => ['string', Rule::in(UltronDecideService::TOOL_VOCABULARY)],
        ]);
        if ($v->fails()) {
            return ['statusCode' => 422, 'body' => ['message' => 'The given data was invalid.']];
        }
        $validos = $v->validated();

        return ['statusCode' => 200, 'body' => $this->check($turn, (int) $validos['attempt'], $validos['draft'], $elapsedSeconds)];
    }

    /**
     * La respuesta 200 de `ai/check` para un borrador del turno.
     *
     * @param  array{sp:array<string,mixed>,po:list<int>,catalog:array<int,array<string,mixed>>,inbound:string,phase:string,memory:ConversationMemory,resolution:array<string,mixed>}  $turn
     * @param  array{reply_draft:mixed,intent:mixed,recommended_plan_id?:mixed,tools_requested?:mixed}  $draft
     * @return array<string,mixed>
     */
    public function check(array $turn, int $attempt, array $draft, int $elapsedSeconds): array
    {
        $sp = $turn['sp'];
        if (($sp['m'] ?? null) !== self::MODE_STUDIO) {
            return self::response(self::MODE_LEGACY, $attempt, [], false, ['max_drafts' => StudioLabEngineFlow::MAX_DRAFTS - 1, 'elapsed_s' => null, 'reason' => self::REASON_LEGACY]);
        }

        $defectos = $this->defects($turn, $draft);
        $elapsed = max(0, $elapsedSeconds);
        $reason = match (true) {
            $attempt >= StudioLabEngineFlow::MAX_DRAFTS => self::REASON_LAST_ATTEMPT,
            $elapsed > StudioLabEngineFlow::TIME_BUDGET_SECONDS => self::REASON_TIME_BUDGET,
            // La lista limpia de un turno de lista no se reescribe (PT-17 L4), como `UltronDraftCheck::check()`.
            $this->listFinal($turn, $draft, $defectos) => self::REASON_LIST_FINAL,
            default => null,
        };

        return self::response(self::MODE_STUDIO, $attempt, $defectos, $reason === null, ['max_drafts' => StudioLabEngineFlow::MAX_DRAFTS, 'elapsed_s' => $elapsed, 'reason' => $reason]);
    }

    /**
     * ¿El texto final lo escribiría el modelo o saldría un respaldo? Con lo
     * que Preparar Commit manda (el borrador, el plan y el veredicto).
     *
     * Con el aviso del revisor (PT-17 L4), además `criticAdvisory: true`; sin
     * él, la clave no va (la forma de siempre). Con la lista del servidor
     * (`k` ≥ 8; PT-17, puerta 2, L1) y la lista en el turno, además `list`:
     * la forma del borrador (`markers` o `server`) y si traía la ranura; sin
     * ella, la clave no va.
     *
     * @param  array<string,mixed>  $turn  como en {@see check()}
     * @param  array{reply_draft:string,recommended_plan_id:mixed,critic:array<string,mixed>}  $commit  {@see StudioLabEngineFlow::prepareCommit()}
     * @param  array<string,mixed>  $strategy  la estrategia validada (intención y herramientas)
     * @return array{origin:string,cause:?string,codes:list<string>,measured:int,chars:int,cap:?int,withinCap:bool,register:string,tuteo:list<string>,trimmed:bool,criticAdvisory?:true,list?:array{shape:string,slot:bool}}
     */
    public function predict(array $turn, array $commit, array $strategy): array
    {
        $sp = $turn['sp'];
        $draft = [
            'reply_draft' => (string) $commit['reply_draft'],
            'intent' => (string) ($strategy['intent'] ?? ''),
            'recommended_plan_id' => $commit['recommended_plan_id'] ?? null,
            'tools_requested' => array_values((array) ($strategy['tools_requested'] ?? [])),
        ];
        $expandido = $this->expanded($turn, $draft);
        // En un turno de lista (compilador 6), el tope de la lista, lo traiga o no el borrador (PT-17 L2), como el commit; si no, el publicado.
        $topeDeLista = UltronReplyFitter::plansListCap($sp, $this->turnList($turn, $draft['intent'], $draft['tools_requested']));
        $tope = $topeDeLista ?? UltronReplyFitter::cap($sp['n'] ?? null);
        $tono = is_string($sp['t'] ?? null) ? $sp['t'] : null;

        // El commit, en su orden: la longitud (F1 quita la pregunta final si así cabe; con la lista, no) y después el registro.
        $texto = $expandido;
        $recortado = false;
        $descartado = false;
        if ($tope !== null) {
            $recorte = $topeDeLista !== null ? UltronReplyFitter::plansListDraft($texto, $tope) : UltronReplyFitter::draft($texto, $tope);
            $descartado = $recorte === null;
            if ($recorte !== null && $recorte['dropped'] > 0) {
                $texto = $recorte['text'];
                $recortado = true;
            }
        }
        $marcas = UltronFormalReplies::aplica($tono) ? UltronRegisterDetector::tuteo($texto, $turn['catalog']) : [];
        $tuteoQueQueda = $marcas;
        if ($marcas !== [] && ! $descartado && $this->withAppLinks($turn, $draft)) {
            $sinPregunta = UltronReplyFitter::withoutFinalQuestion($texto);
            if ($sinPregunta !== null && UltronRegisterDetector::tuteo($sinPregunta, $turn['catalog']) === []) {
                $texto = $sinPregunta;
                $recortado = true;
                $tuteoQueQueda = [];
            }
        }

        // Lo que sigue sin arreglar: la longitud que no cabe, el tuteo que queda y todo lo demás de la comprobación.
        $defectos = $this->defects($turn, $draft);
        $codigos = [];
        foreach ($defectos as $d) {
            $fatal = match ($d['code']) {
                UltronRegenerationNotes::LENGTH_OVER_CAP => $descartado,
                UltronRegenerationNotes::REGISTER_TUTEO => $tuteoQueQueda !== [],
                default => true,
            };
            if ($fatal && ! in_array($d['code'], $codigos, true)) {
                $codigos[] = $d['code'];
            }
        }
        $revisor = is_array($commit['critic'] ?? null) ? $commit['critic'] : [];
        // PT-17 L4, como el commit: el rechazo blando sobre la lista limpia del turno es un aviso, no un respaldo.
        $aviso = CriticContract::isSoftFail($revisor) && $this->listFinal($turn, $draft, $defectos);
        $causa = CriticContract::isFail($revisor) && ! $aviso ? self::CAUSE_CRITIC : ($codigos[0] ?? null);
        if ($causa !== null) {
            // Un borrador que va a sustituirse no pasa por el recorte (PT-17 L2): lo que sale es el respaldo, no él.
            $texto = $expandido;
            $recortado = false;
            $marcas = UltronFormalReplies::aplica($tono) ? UltronRegisterDetector::tuteo($texto, $turn['catalog']) : [];
        }
        $mide = UltronReplyFitter::measure($texto);

        return [
            'origin' => $causa === null ? self::ORIGIN_MODEL : self::ORIGIN_FALLBACK,
            'cause' => $causa,
            'codes' => $codigos,
            // Lo que mide el borrador expandido, antes de cualquier recorte; `chars`, el texto que saldría (el borrador entero si se sustituye).
            'measured' => UltronReplyFitter::measure($expandido),
            'chars' => $mide,
            'cap' => $tope,
            'withinCap' => $tope === null || $mide <= $tope,
            'register' => UltronFormalReplies::aplica($tono) ? ($marcas === [] ? 'ok' : 'tuteo') : 'n/a',
            'tuteo' => array_values(array_map('strval', $marcas)),
            'trimmed' => $recortado,
        ] + ($aviso ? ['criticAdvisory' => true] : []) + $this->listShape($turn, $draft);
    }

    /**
     * La forma de la lista del borrador (PT-17, puerta 2, L1), solo con la
     * lista del servidor y la lista en el turno: `markers` o `server` y si
     * trae la ranura. Lo mismo que el commit anota en `metadata.plans_overview`.
     *
     * @param  array<string,mixed>  $turn
     * @param  array<string,mixed>  $draft
     * @return array{list?:array{shape:string,slot:bool}}
     */
    private function listShape(array $turn, array $draft): array
    {
        $po = $this->turnList($turn, (string) $draft['intent'], array_values((array) ($draft['tools_requested'] ?? [])));
        if (! UltronPlansOverview::serverList($turn['sp']) || $po === []) {
            return [];
        }
        $r = UltronPlansOverview::render((string) $draft['reply_draft'], $po, $this->listTexts($po), $turn['catalog'], true);

        return ['list' => ['shape' => $r['shape'], 'slot' => $r['slot']]];
    }

    /**
     * La política del turno como la calcula el commit
     * ({@see UltronTurnPolicyReader::read()}): con la intención del borrador
     * (el saludo puro es saludo) y, si los exige, los hechos del gimnasio.
     *
     * @param  array<string,mixed>  $turn
     * @return array{policy:array<string,mixed>,facts:list<string>}
     */
    public function policyFor(array $turn, string $intent): array
    {
        $politica = CommercialTurnPolicy::decide(
            UltronTurnPolicyReader::intentForPolicy($intent, $turn['inbound']),
            $turn['phase'],
            $turn['memory'],
            $turn['catalog'],
            $turn['resolution'],
            $turn['inbound'],
        );
        $hechos = in_array('gym_identity', (array) ($politica['required_facts'] ?? []), true)
            ? CommercialTurnPolicy::knownFacts($this->knowledge->activeItems(), (array) ($this->gym->forPrompt()['classes'] ?? []))
            : [];

        return ['policy' => $politica, 'facts' => $hechos];
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /**
     * Lo que hacen con el cuerpo los dos middleware globales antes del
     * controlador: cada texto, recortado (`Str::trim`, el de `TrimStrings`), y
     * el vacío, null.
     *
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    private static function likeTheRequest(array $datos): array
    {
        foreach ($datos as $k => $v) {
            if (is_array($v)) {
                $datos[$k] = self::likeTheRequest($v);
            } elseif (is_string($v)) {
                $t = Str::trim($v);
                $datos[$k] = $t === '' ? null : $t;
            }
        }

        return $datos;
    }

    /**
     * Los defectos de un borrador, como `UltronDraftCheck::check()`: los cuatro
     * de §4.4 y detrás los de política, con el tope de 8.
     *
     * @param  array<string,mixed>  $turn
     * @param  array<string,mixed>  $draft
     * @return list<array{code:string,detail:?string,issue:string,note:string}>
     */
    private function defects(array $turn, array $draft): array
    {
        $raw = (string) $draft['reply_draft'];
        $intent = (string) $draft['intent'];
        $tools = array_values((array) ($draft['tools_requested'] ?? []));
        $po = $this->turnList($turn, $intent, $tools);
        $turno = $this->policyFor($turn, $intent);
        // Los textos de TODOS los planes de la lista, una vez por borrador, como `UltronDraftCheck::check()`: con ellos
        // se expande y de ellos saca `UltronDraftDefects::listBudget()` el presupuesto de las notas (Enmienda A4-4).
        $textosDeLaLista = $this->listTexts($po);
        $expandido = $this->expanded($turn, $draft, $turno['policy'], $textosDeLaLista);

        return array_slice([
            ...UltronDraftDefects::of(['reply_draft' => $raw, 'intent' => $intent, 'tools_requested' => $tools], $expandido, $turn['sp'], $po, $turn['catalog'], $turn['inbound'], $textosDeLaLista),
            ...UltronDraftDefects::ofPolicy($expandido, $turn['sp'], $turno['policy'], $turn['catalog'], $turno['facts'], $this->withAppLinks($turn, $draft, $turno['policy']), UltronReplyFitter::plansListCap($turn['sp'], $po)),
        ], 0, UltronDraftDefects::MAX_DEFECTS);
    }

    /**
     * ¿Es la lista firme del turno (PT-17 L4)? El port de
     * `UltronDraftCheck::inspect()['list_final']`, con la lista del turno y los
     * defectos que ya le encontró {@see defects()}.
     *
     * @param  array<string,mixed>  $turn
     * @param  array<string,mixed>  $draft
     * @param  list<array<string,mixed>>  $defectos
     */
    private function listFinal(array $turn, array $draft, array $defectos): bool
    {
        $po = $this->turnList($turn, (string) $draft['intent'], array_values((array) ($draft['tools_requested'] ?? [])));

        return UltronDraftDefects::isListFinal((string) $draft['reply_draft'], $turn['sp'], $po, $turn['catalog'], $defectos);
    }

    /**
     * La lista del turno, con las reglas del commit: la firmada si tiene la
     * forma, y vacía en un turno de cobro.
     *
     * @param  array<string,mixed>  $turn
     * @param  list<string>  $tools
     * @return list<int>
     */
    private function turnList(array $turn, string $intent, array $tools): array
    {
        $po = UltronPlansOverview::signedList($turn['po'] ?? null) ?? [];

        return UltronPlansOverview::isMoneyTurn($intent, $tools) ? [] : $po;
    }

    /**
     * ¿Pega el commit los enlaces de la app? Con la herramienta pedida y fuera
     * del modo memoria (que los retira).
     *
     * @param  array<string,mixed>  $turn
     * @param  array<string,mixed>  $draft
     * @param  array<string,mixed>|null  $policy
     */
    private function withAppLinks(array $turn, array $draft, ?array $policy = null): bool
    {
        $policy ??= $this->policyFor($turn, (string) $draft['intent'])['policy'];

        return ($policy['memory_mode'] ?? false) !== true
            && in_array(SalesIntents::TOOL_APP_LINKS_SEND, array_values((array) ($draft['tools_requested'] ?? [])), true);
    }

    /**
     * EL TEXTO EXPANDIDO, SOLO PARA MEDIR: el port de
     * `UltronDraftCheck::expandido()` (privado), con las mismas funciones del
     * motor y en el mismo orden.
     *
     * @param  array<string,mixed>  $turn
     * @param  array<string,mixed>  $draft
     * @param  array<string,mixed>|null  $policy
     * @param  array<int,string>|null  $listTexts  {@see self::listTexts()}; si no llegan, se leen aquí
     */
    private function expanded(array $turn, array $draft, ?array $policy = null, ?array $listTexts = null): string
    {
        $texto = (string) $draft['reply_draft'];
        $tools = array_values((array) ($draft['tools_requested'] ?? []));
        $po = $this->turnList($turn, (string) $draft['intent'], $tools);
        $politica = $policy ?? $this->policyFor($turn, (string) $draft['intent'])['policy'];
        $planId = $draft['recommended_plan_id'] ?? null;

        if ($po !== []) {
            // El mismo punto que el motor (PT-17, puerta 2, L1): forma A, sus marcadores; forma B, la lista entera en su sitio.
            $texto = UltronPlansOverview::render($texto, $po, $listTexts ?? $this->listTexts($po), $turn['catalog'], UltronPlansOverview::serverList($turn['sp']))['text'];
        }

        if ($this->placeholders->needsPlan($texto)) {
            $obligado = $politica['required_plan_id'] ?? null;
            if ($obligado !== null && in_array($politica['required_plan_source'] ?? null, ['commercial_priority', 'named_by_person'], true)
                && (! is_numeric($planId) || (int) $obligado !== (int) $planId)) {
                $planId = (int) $obligado;
            }
            $plan = is_numeric($planId) ? Plan::query()->sellable()->whereKey((int) $planId)->first() : null;
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
     * ({@see UltronPlansOverview::item()}), en una sola consulta: el port de
     * `UltronDraftCheck::textosDeLaLista()` (privado). De TODOS los
     * ofrecidos, los nombre o no el borrador; el de un plan que ya no se
     * vende falta. Vacío sin lista.
     *
     * @param  list<int>  $po
     * @return array<int,string> plan_id → su texto
     */
    private function listTexts(array $po): array
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
     * La respuesta 200, con la lista blanca de claves y su orden de
     * `UltronDraftCheck` (privada allí): cada defecto sin su nota; las notas,
     * unidas; los `issues`, del vocabulario del revisor y sin repetir.
     *
     * @param  list<array{code:string,detail:?string,issue:string,note:string}>  $defectos
     * @param  array{max_drafts:int,elapsed_s:?int,reason:?string}  $budget
     * @return array<string,mixed>
     */
    private static function response(string $mode, int $attempt, array $defectos, bool $mayRewrite, array $budget): array
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
