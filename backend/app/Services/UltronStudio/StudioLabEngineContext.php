<?php

namespace App\Services\UltronStudio;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\FakeAiSalesResponder;
use App\Services\Marketing\HumanHandoffAuthority;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesAgentOrchestratorService;
use App\Services\Marketing\SalesIntentClassifierService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\SalesPaymentReadinessService;
use App\Services\Marketing\StaffReviewAuthority;
use App\Services\Marketing\Ultron\BusinessClock;
use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use App\Services\Marketing\Ultron\ConversationMemory;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Marketing\Ultron\CustomerIntelligenceService;
use App\Services\Marketing\Ultron\GymFactsProvider;
use App\Services\Marketing\Ultron\MembershipFactsProvider;
use App\Services\Marketing\Ultron\MemoryRedactor;
use App\Services\Marketing\Ultron\NoveltyGuard;
use App\Services\Marketing\Ultron\PaymentStatusProvider;
use App\Services\Marketing\Ultron\ReferenceResolver;
use App\Services\Marketing\Ultron\StrategyContract;
use App\Services\Marketing\Ultron\UltronDecideService;
use App\Services\Marketing\Ultron\UltronListTurnContract;
use App\Services\Marketing\Ultron\UltronPlansOverview;
use App\Services\Marketing\Ultron\UltronSalesPlaybook;
use InvalidArgumentException;

/**
 * EL CONTEXTO DE `decide` PARA EL RECORRIDO DEL MOTOR EN EL LABORATORIO
 * (apartado 5 del cierre; diseño `plans/informes-cierre/diseno-arq-lab.md`
 * §3.1, B3). Uno de los DOS lectores del motor del laboratorio (enmienda 9 de
 * H4 §A4, 22-11-2026, con `StudioLabEngineCheck`): nombra el motor, SOLO LEE y
 * nunca escribe, ni sale a la red.
 *
 * {@see build()} devuelve la respuesta de `decide` con su forma (las claves,
 * en su orden, de `UltronDecideService::decide()`), hecha de dos mitades:
 *
 *  1. LO SINTÉTICO, del escenario: la persona es un prospecto nuevo SIN datos
 *     (ni nombre, ni teléfono, ni historial: un prospecto y una conversación
 *     que NO se guardan), la conversación son sus turnos y la memoria es la de
 *     un primer contacto ({@see ConversationMemory::empty()}, con la pregunta
 *     sin resolver que `ConversationMemoryService::load()` deriva al leer). El estado del
 *     pago es el del escenario. La «petición de humano corroborada» del
 *     escenario cuenta como `needs_human`, igual que la del texto.
 *  2. LO REAL, en solo lectura, con las mismas funciones que `decide`: la base
 *     de conocimiento, los hechos del gimnasio, los planes vendibles SIN
 *     precio, el plan por defecto, las frases de marca, la técnica de venta,
 *     la hora del gimnasio, la app, la política del turno, las transiciones,
 *     las herramientas, el referente, la novedad, el perfil, las pistas del
 *     estratega (con el registro firmado en `sp`) y la lista de planes (`po`).
 *
 * LA FRONTERA DE LOS DATOS PERSONALES (enmienda 9 de H4). Todo lo que viene
 * del escenario (los textos de los turnos) vuelve a pasar aquí por
 * {@see StudioLabPersonalData}: si algo no pasa, no se construye nada
 * (InvalidArgumentException, sin datos en el mensaje; el caso termina
 * `blocked_input`). El contexto de NEGOCIO lo construye el servidor desde las
 * tablas del negocio y NO pasa por ese filtro: puede llevar los datos públicos
 * de contacto del gimnasio, que es lo que el motor real envía. Security decide
 * si hay que enmascarar algo de esa mitad.
 *
 * LAS DIVERGENCIAS, DECLARADAS (las cubre T5 en lo determinista):
 *  - `decision` sale del cerebro de siempre (`SalesAgentOrchestratorService::
 *    analyze()`, documentado como puro) con el clasificador DETERMINISTA por
 *    reglas ({@see FakeAiSalesResponder}, el último eslabón de la propia cadena
 *    del motor), nunca con el proveedor configurado: el laboratorio no gasta
 *    la clave del motor. Con el proveedor real, la intención base puede ser
 *    otra;
 *  - el ritmo de la conversación (`customer.conversation_momentum`) se mide
 *    sobre el hilo sintético, un minuto entre turnos (el perfil lo mide sobre
 *    los mensajes guardados);
 *  - el enlace de pago se mira para «ninguna conversación» (falla cerrado: sin
 *    la puerta del canario);
 *  - sin `decide_token`, `expires_at`, `source` real ni `studio_prompts`: el
 *    laboratorio entrega los cinco prompts directamente.
 *
 * Además devuelve `turn`: lo que el motor firmaría en el token y leería al
 * comprobar (`sp`, `po`, el catálogo, el texto, la fase, la memoria y el
 * referente), para {@see StudioLabEngineCheck}.
 */
final class StudioLabEngineContext
{
    /** Lo que el modelo ve donde había una cifra (como `UltronDecideService::PRICE_MARK`, privada allí). */
    private const PRICE_MARK = '[precio]';

    public function __construct(
        private readonly CommercialPhaseMachine $phases,
        private readonly MarketingKnowledgeBaseService $knowledge,
        private readonly SalesPaymentReadinessService $paymentReadiness,
        private readonly ConversationMemoryService $memoryService,
        private readonly ReferenceResolver $references,
        private readonly NoveltyGuard $novelty,
        private readonly CustomerIntelligenceService $customers,
        private readonly GymFactsProvider $gym,
        private readonly PaymentStatusProvider $payments,
        private readonly MobileAppCatalog $appCatalog,
        private readonly MembershipFactsProvider $membership,
        private readonly StudioEngineReadings $readings,
        private readonly HumanHandoffAuthority $handoff = new HumanHandoffAuthority,
    ) {}

    /**
     * @param  array<string,mixed>  $scenario  el `scenario_snapshot` del caso: `{turns, facts, …}`
     * @param  array<string,mixed>  $sp  el `sp` de la versión ({@see StudioPromptBundle::rolesFor()})
     * @return array{decide:array<string,mixed>,turn:array<string,mixed>,inbound:string}
     *
     * @throws InvalidArgumentException si un texto del escenario no pasa el filtro de datos personales, o no hay turno de la persona
     */
    public function build(array $scenario, array $sp): array
    {
        $turnos = array_values(array_filter(is_array($scenario['turns'] ?? null) ? $scenario['turns'] : [], 'is_array'));
        $hechos = is_array($scenario['facts'] ?? null) ? $scenario['facts'] : StudioLabCatalog::DEFAULT_FACTS;
        // La frontera: lo del escenario vuelve a pasar por el filtro, siempre.
        foreach ($turnos as $t) {
            if (StudioLabPersonalData::violation((string) ($t['text'] ?? '')) !== null) {
                throw new InvalidArgumentException('Un texto del escenario no pasa el filtro de datos personales.');
            }
        }
        $ultimo = $turnos === [] ? null : $turnos[count($turnos) - 1];
        if ($ultimo === null || ($ultimo['role'] ?? null) !== 'prospect') {
            throw new InvalidArgumentException('El escenario no termina con un turno de la persona.');
        }
        $inbound = (string) $ultimo['text'];

        // La persona y la conversación, sintéticas y EN MEMORIA: un prospecto nuevo, sin datos. Ningún modelo
        // de estos se guarda (no se llama a save() ni a nada que escriba; lo vigilan las guardas estáticas
        // y, en ejecución, `UltronStudioLabNoEngineEffectsTest`): solo son la forma que piden las funciones del motor.
        $lead = new MarketingLead;
        $lead->setRawAttributes(['channel' => 'whatsapp', 'source' => 'inbound', 'status' => MarketingLead::STATUS_NEW, 'do_not_contact' => false]);
        $conversacion = new MarketingConversation;
        $conversacion->setRawAttributes(['channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => null]);
        // Sin prospecto en la conversación, a propósito: las lecturas por su id (`where lead_id = null` es
        // `IS NULL` y traería filas de otros) no se hacen; el motor tiene su rama para «no hay prospecto».
        $conversacion->setRelation('lead', null);
        $mensaje = new MarketingMessage;
        $mensaje->setRawAttributes(['direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $inbound, 'created_at' => now()]);

        // El cerebro de siempre, con el clasificador determinista: sin red y sin la clave del motor.
        $orquestador = app()->make(SalesAgentOrchestratorService::class, ['classifier' => new SalesIntentClassifierService(new FakeAiSalesResponder)]);
        $decision = $orquestador->analyze($lead, $inbound, ['lead' => $lead, 'channel' => 'whatsapp', 'conversation' => $conversacion, 'plan' => null]);

        $phase = $this->phases->normalize(null);
        $puedeEnlace = $this->paymentReadiness->canGenerateForConversation(null);
        $transitions = $this->phases->allowedTransitions($phase, [
            'do_not_contact' => false,
            'human_takeover' => false,
            'can_offer_link' => $puedeEnlace,
            'needs_human' => ($hechos['handoffRequested'] ?? false) === true || $this->handoff->peticionDeHumanoEn($inbound) !== null,
        ]);

        $memory = self::firstContactMemory($turnos);
        $plansForMemory = $this->memoryService->sellablePlansForMemory();
        $resolved = $this->references->resolve($inbound, $memory, $plansForMemory);
        $novelty = $this->novelty->guidance($memory, $plansForMemory, $resolved);
        $saludoPuro = SalesIntents::isPureGreeting($inbound);
        $intentTurno = $saludoPuro ? SalesIntents::GREETING : (string) ($decision['intent'] ?? SalesIntents::UNKNOWN);

        $customer = $this->customers->profile($conversacion, $mensaje, $memory, $resolved, $intentTurno);
        // El ritmo lo mide el perfil sobre los mensajes guardados; la conversación del laboratorio no se guarda.
        $customer['conversation_momentum'] = self::momentum($turnos, $intentTurno, $resolved);
        // El pago: la forma del motor sin prospecto (sin base ni pasarela), con el estado del escenario.
        $payment = ['state' => (string) ($hechos['paymentState'] ?? 'none')] + $this->payments->forLead(null, $inbound);
        $membershipFacts = $this->membership->forPrompt(null);
        $memoriaPura = ! $saludoPuro && CommercialTurnPolicy::esTurnoDeMemoria($intentTurno, $inbound, $this->knowledge->activePlans(), $resolved);
        $strategyHints = StrategyContract::hints($customer, $resolved, $puedeEnlace, $intentTurno, $payment, $membershipFacts, $saludoPuro, $memoriaPura);
        $objetivoVivo = $memory->activeGoal();
        $strategyHints['active_goal'] = $objetivoVivo === null ? null : ['kind' => $objetivoVivo['kind'], 'status' => $objetivoVivo['status']];
        $tools = array_values(array_merge(UltronDecideService::V1_ALLOWED_TOOLS, $puedeEnlace ? [SalesIntents::TOOL_PAYMENT_LINK_SEND] : []));
        $knowledgeVersion = $this->knowledge->version();
        $strategyHints = StrategyContract::conElRegistro($strategyHints, $sp);

        $catalogo = $this->knowledge->activePlans();
        $politica = CommercialTurnPolicy::decide($intentTurno, $phase, $memory, $catalogo, $resolved, $inbound);
        $panorama = self::plansOverview($sp, $politica, $resolved, $catalogo, $intentTurno, $inbound);
        // Las pistas de un turno de lista, como `decide` (PT-17, L3).
        $strategyHints = StrategyContract::conLaLista($strategyHints, $panorama !== null);

        $enlaces = $this->readings->only(['rules.paymentLinksEnabled'])['params']['rules.paymentLinksEnabled'] ?? [];
        $decide = [
            'ok' => true,
            'source' => ['source_type' => 'inbound_message', 'source_event_id' => null, 'idempotency_key' => null],
            'decision' => $decision,
            'context' => [
                'lead' => ['name' => null, 'objective' => null, 'temperature' => $lead->temperature, 'do_not_contact' => false, 'is_member' => false],
                'memory' => [
                    'summary' => null,
                    'detected_objective' => null,
                    'primary_intent' => null,
                    'last_intent' => null,
                    'lead_score' => 0,
                    'lead_stage' => null,
                    'commercial_phase' => $phase,
                    'main_barrier' => null,
                    'structured' => $memory->toArray(),
                ],
                'resolved_reference' => $resolved,
                'novelty' => $novelty,
                'customer' => $customer,
                'payment' => $payment,
                'strategy_hints' => $strategyHints,
                'recent_messages' => self::recentMessages($turnos),
                'knowledge_base' => $this->knowledge->groupedForPrompt(),
                'active_plans' => self::plansWithoutPrice($catalogo),
                'gym' => $this->gym->forPrompt(),
                'business_time' => BusinessClock::forPrompt(),
                'app' => $this->appCatalog->forPrompt((bool) data_get($customer, 'known.has_app_account', false)),
                'membership' => $membershipFacts,
                'default_plan_id' => $this->knowledge->defaultMonthlyPlan()?->id,
                'commercial_turn_policy' => self::turnPolicy($politica) + ($panorama === null ? [] : ['plans_overview' => $panorama['overview']]),
                'sales_playbook' => UltronSalesPlaybook::forPhase($phase, UltronSalesPlaybook::signalsFrom($memory, false, null, null)),
                'approved_brand_copy' => $this->knowledge->approvedBrandCopy(),
                'flags' => [
                    'can_offer_link' => $this->paymentReadiness->canGenerateAutomaticLink(),
                    'payment_readiness' => $this->paymentReadiness->state(),
                    'ultron_payment_links_enabled' => ($enlaces['value'] ?? false) === true,
                ],
            ],
            'commercial_phase' => $phase,
            'allowed_transitions' => $transitions,
            'allowed_tools' => $tools,
            'allowed_staff_review_reasons' => StaffReviewAuthority::MODEL_PROPOSABLE,
            'knowledge_version' => $knowledgeVersion,
            'decide_token' => null,
            'expires_at' => null,
        ] + (($sp['m'] ?? null) === StudioPromptBundle::MODE_STUDIO
            ? ['regeneration' => ['contract' => StudioLabEngineFlow::CHECK_CONTRACT, 'max_drafts' => StudioLabEngineFlow::MAX_DRAFTS]]
            : [])
            // Lo añade el controlador al final; el IF «Mensaje superado?» lo lee. En el laboratorio nadie escribe después.
            + ['superseded' => false];

        return [
            'decide' => $decide,
            'turn' => [
                'sp' => $sp,
                'po' => $panorama['po'] ?? [],
                'catalog' => $catalogo,
                'inbound' => $inbound,
                'phase' => $phase,
                'memory' => $memory,
                'resolution' => $resolved,
            ],
            'inbound' => $inbound,
        ];
    }

    // ── Los ports de lo privado de `UltronDecideService` (T5 los ata) ───────

    /**
     * `listaDelPanorama()`: la lista que redacta el modelo, solo con la oferta
     * del Studio con el parche del motor (`sp.o = 1`), si la persona pide el
     * panorama y hay al menos dos planes.
     *
     * @param  array<string,mixed>  $sp
     * @param  array<string,mixed>  $politica
     * @param  array<string,mixed>  $resolution
     * @param  array<int,array<string,mixed>>  $activePlans
     * @return array{po:list<int>,overview:array{plans:list<array<string,mixed>>,price_asked:bool}}|null
     */
    private static function plansOverview(array $sp, array $politica, array $resolution, array $activePlans, string $intent, string $inbound): ?array
    {
        if (($sp['m'] ?? null) !== StudioPromptBundle::MODE_STUDIO || ($sp['o'] ?? null) !== 1
            || ! CommercialTurnPolicy::pideElPanorama($inbound, $politica, $resolution)) {
            return null;
        }
        $po = UltronPlansOverview::select($politica, $activePlans);
        if (count($po) < UltronPlansOverview::MIN_MARKERS) {
            return null;
        }

        $renderContract = UltronListTurnContract::build($sp, $po, $activePlans, $intent);

        return ['po' => $po, 'overview' => [
            // Con la lista del servidor (`k` ≥ 8; PT-17, puerta 2, L1), sin marcadores: como `decide`.
            'plans' => UltronPlansOverview::items($po, $activePlans, ! UltronPlansOverview::serverList($sp)),
            'price_asked' => $intent === SalesIntents::PRICING_QUESTION || CommercialTurnPolicy::preguntaPorPrecio($inbound),
        ] + ($renderContract === null ? [] : ['render_contract' => $renderContract])];
    }

    /**
     * `politicaDelTurno()`: la política recortada a su lista blanca.
     *
     * @param  array<string,mixed>  $politica
     * @return array<string,mixed>
     */
    private static function turnPolicy(array $politica): array
    {
        $recorte = [];
        foreach ([
            'intent', 'commercial_phase', 'greet_required', 'welcome_required',
            'answer_first', 'required_plan_id', 'allowed_plan_ids',
            'forbidden_actions', 'required_facts', 'allowed_next_actions',
            'hard_required_plan', 'preferred_commercial_plan', 'price_verification',
            'active_goal', 'resume_goal_after_answer', 'reception_mode', 'memory_mode',
        ] as $campo) {
            $recorte[$campo] = $politica[$campo] ?? null;
        }

        return $recorte;
    }

    /**
     * `plansWithoutPrice()`: los planes vendibles sin `price`.
     *
     * @param  array<int,array<string,mixed>>  $activePlans
     * @return array<int,array<string,mixed>>
     */
    private static function plansWithoutPrice(array $activePlans): array
    {
        return array_map(
            fn (array $p) => array_filter([
                'id' => $p['id'],
                'name' => $p['name'],
                'duration_days' => $p['duration_days'],
                'benefits' => $p['benefits'],
                'tier' => $p['tier'] ?? null,
                'is_recommended' => $p['is_recommended'] ?? null,
                'badge' => $p['badge'] ?? null,
                'access_classes' => $p['access_classes'] ?? null,
                'restrictions' => $p['restrictions'] ?? null,
                'has_discount' => ! empty($p['original_price']),
            ], fn ($v) => $v !== null && $v !== []),
            $activePlans,
        );
    }

    /**
     * La memoria de un primer contacto como la LEE `decide`
     * (`ConversationMemoryService::load()`): vacía, con lo que se deriva al
     * leer y no se guarda (`withUnresolvedQuestion()`, privada allí): la última
     * pregunta de la persona a la que no siguió ningún mensaje del asesor, de
     * los seis últimos. Los ids y las horas son los sintéticos del hilo
     * ({@see recentMessages()}). Sin objetivo vivo, no hay nada que caduque.
     *
     * @param  list<array<string,mixed>>  $turnos
     */
    private static function firstContactMemory(array $turnos): ConversationMemory
    {
        $n = count($turnos);
        $pendiente = null;
        foreach ($turnos as $i => $t) {
            if ($i < $n - 6) {
                continue;
            }
            if (($t['role'] ?? null) === 'prospect') {
                $q = MemoryRedactor::lead(self::questionIn((string) ($t['text'] ?? '')));
                if ($q !== null) {
                    $pendiente = ['source' => 'lead_verbatim', 'text' => $q, 'at' => self::syntheticAt($n, $i), 'message_id' => $i + 1];
                }
            } else {
                $pendiente = null;
            }
        }
        $datos = ConversationMemory::empty()->toArray();
        $datos['unresolved_question'] = $pendiente;

        return ConversationMemory::fromArray($datos);
    }

    /** `questionIn()` (privada en la memoria): el texto es una pregunta si lleva «?» o empieza como una. */
    private static function questionIn(string $text): ?string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($t === '') {
            return null;
        }
        $n = SalesAgentDecisionSchema::normalize($t);
        if (str_contains($t, '?') || preg_match('/^(que|cual|cuales|cuanto|cuantos|cuanta|cuantas|como|donde|cuando|quien|tienes|tienen|hay|puedo|se puede|me (puedes|podrias))\b/u', $n) === 1) {
            return mb_substr($t, 0, 200);
        }

        return null;
    }

    /**
     * `momentum()` del perfil (privada allí, y lee los mensajes guardados),
     * sobre el hilo sintético: un minuto entre turnos.
     *
     * @param  list<array<string,mixed>>  $turnos
     * @param  array<string,mixed>  $resolved
     */
    private static function momentum(array $turnos, string $intent, array $resolved): string
    {
        if (in_array($intent, [SalesIntents::NOT_INTERESTED, SalesIntents::GOODBYE], true) || ($resolved['type'] ?? null) === ReferenceResolver::DECLINE_OFFER) {
            return 'stalling';
        }
        $n = count($turnos);
        $dePersona = array_keys(array_filter($turnos, static fn (array $t): bool => ($t['role'] ?? null) === 'prospect'));
        if (count($dePersona) < 2) {
            return 'steady';
        }
        $ultimo = $dePersona[count($dePersona) - 1];
        $hueco = $ultimo - $dePersona[count($dePersona) - 2];
        $recientes = count(array_filter($dePersona, static fn (int $i): bool => ($n - 1 - $i) <= 15));
        if ($hueco >= 24 * 60) {
            return 'cold_restart';
        }
        if ($recientes >= 3 || $hueco <= 3) {
            return 'accelerating';
        }

        return $hueco > 120 ? 'stalling' : 'steady';
    }

    /** La hora sintética del turno i de n: un minuto entre turnos, el último ahora. */
    private static function syntheticAt(int $n, int $i): string
    {
        return now()->subMinutes($n - 1 - $i)->toIso8601String();
    }

    /**
     * `recentMessages()`: la conversación del escenario como el hilo de
     * `decide` (rol, texto y hora; el último, el de la persona). Lo que
     * escribió el asesor pierde, como allí, las URL y las cifras.
     *
     * @param  list<array<string,mixed>>  $turnos
     * @return list<array{role:string,body:?string,at:?string}>
     */
    private static function recentMessages(array $turnos): array
    {
        $ultimos = array_slice($turnos, -10);
        $n = count($ultimos);
        $out = [];
        foreach ($ultimos as $i => $t) {
            $persona = ($t['role'] ?? null) === 'prospect';
            $texto = (string) ($t['text'] ?? '');
            $out[] = [
                'role' => $persona ? MarketingMessage::SENDER_LEAD : MarketingMessage::SENDER_AI,
                'body' => $persona ? $texto : self::redactedMachineBody($texto),
                'at' => self::syntheticAt($n, $i),
            ];
        }

        return $out;
    }

    /** `redactedBody()` de un mensaje de la máquina (sin el enlace de pago, que el laboratorio no tiene). */
    private static function redactedMachineBody(string $body): ?string
    {
        if (OutboundContentGuard::containsUrl($body)) {
            $limpio = OutboundContentGuard::withoutUrls($body);

            return $limpio === null ? '[enlace enviado por el CRM]' : self::withoutPrices($limpio);
        }

        return self::withoutPrices($body);
    }

    /** `withoutPrices()`: la cifra, con el marcador que nadie resuelve. */
    private static function withoutPrices(?string $body): ?string
    {
        if ($body === null || ! SalesAgentDecisionSchema::containsPrice($body)) {
            return $body;
        }
        $masked = preg_replace(SalesAgentDecisionSchema::PRICE_PATTERN, self::PRICE_MARK, $body);
        if ($masked === null) {
            return '[mensaje del CRM]';
        }
        $mark = preg_quote(self::PRICE_MARK, '/');

        return preg_replace('/'.$mark.'(?:[\s.,]*'.$mark.'|[.,]\d{3})+/', self::PRICE_MARK, $masked) ?? '[mensaje del CRM]';
    }
}
