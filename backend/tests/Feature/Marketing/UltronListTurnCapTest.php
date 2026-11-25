<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Marketing\Ultron\ReferenceResolver;
use App\Services\Marketing\Ultron\UltronDecideToken;
use App\Services\Marketing\Ultron\UltronPlansOverview as O;
use App\Services\Marketing\Ultron\UltronRegenerationNotes as N;
use App\Services\Marketing\Ultron\UltronReplyFitter as F;
use App\Services\UltronStudio\StudioLabChecks;
use App\Services\UltronStudio\StudioLabEngineCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\HistoricalListTurn;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17 · PUERTA 1 · L2 · UN SOLO TOPE POR TURNO DE LISTA (diagnóstico
 * estructural del 24-11-2026, causa C2), por los endpoints reales y en el
 * laboratorio, con la versión «profesional · 200» publicada en la canaria, el
 * catálogo vendible de producción y la ficha del horario.
 *
 * Hasta hoy, en el turno en que `decide` ofrece la lista, un borrador con dos
 * marcadores se medía contra 320 y uno con un solo plan contra 200: se le
 * quitaba la pregunta (y salía el respaldo igual) y su nota calculaba sobre
 * 160 —«quedan 89»— una lista que con su pregunta mide unos 225. El contrato
 * firmado del redactor dice que en ESE turno el mensaje «no pasa de 320».
 *
 *  1. El tope es del turno: {@see F::plansListCap()} ya no mira el borrador;
 *     sin lista, en un turno de cobro, en Legacy o con el compilador 5,
 *     ninguno, como hoy.
 *  2. `ai/check`: un borrador que habla de UN solo plan —su nombre y su
 *     marcador, o su marcador con `{{PLAN_PRICE}}`— recibe «FALTA LA LISTA»
 *     con el presupuesto del tope de la lista (71 de los marcadores: quedan
 *     179 de 250) y sin la longitud que hoy le pedía 200; si pasa de 320, la
 *     nota de la longitud nombra 320. Con dos planes y uno a mano, sigue el
 *     nombre duplicado.
 *  3. El commit: ese borrador no se recorta (`list_cap` 320 en la traza) y
 *     se sustituye ENTERO por la lista fija de siempre.
 *  4. El laboratorio predice lo mismo, y un borrador que va a sustituirse no
 *     pasa por el recorte, también fuera de un turno de lista.
 *  5. El aviso de longitud del laboratorio usa la medida y el tope del
 *     servidor en el recorrido del motor; en la simulación, como siempre.
 */
class UltronListTurnCapTest extends TestCase
{
    use HistoricalListTurn;
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000f1';

    /** El catálogo vendible de producción (el de `UltronPlansListCapFlowTest`). */
    private const CATALOGO = [
        [25, 'PLAN DÍA', 10000, 1], [2, 'Plan Semana', 45000, 7], [3, 'Plan Valera', 65000, 30], [4, 'Plan Mensual', 80000, 30],
        [5, 'Trimestre', 210000, 90], [6, 'Semestre', 390000, 180], [7, 'Anualidad', 624000, 365], [8, 'Élite', 180000, 30],
        [9, 'Pro', 499000, 90], [10, 'Prime', 950000, 180], [11, 'Evolution', 1800000, 365], [14, 'PROMO X4', 75000, 30],
        [16, 'INGRESO EMPLEADOS', 1, 360],
    ];

    private const PANORAMA = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private const PO = [3, 4, 8];

    /** El respaldo de precios con tope 200 (la lista fija de hoy). */
    private const PRECIOS_200 = 'Claro. Algunos planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?';

    /** Un primer borrador real de la medición del compilador 7: el nombre y el marcador del MISMO plan (229 expandido). */
    private const UN_PLAN = 'Le cuento que el Plan Valera incluye acceso ilimitado al gimnasio, zona de pesas, asesoría semi personalizada y valoración inicial, todo por {{PLAN_1}}. Para orientarle mejor, ¿ha entrenado antes o es principiante?';

    /** Un segundo borrador real (46/213): su marcador con `{{PLAN_PRICE}}` (188). */
    private const UN_PLAN_PRECIO = 'Por lo que pregunta, tenemos el {{PLAN_1}} con acceso ilimitado y asesoría semi personalizada por {{PLAN_PRICE}}. ¿Ha entrenado antes o es su primera vez para orientarle mejor?';

    /** Un solo plan que pasa del tope de la lista (366). */
    private const UN_PLAN_LARGO = 'Le cuento que el Plan Valera incluye acceso ilimitado al gimnasio, zona de pesas, asesoría semi personalizada, seguimiento en la ejecución de los ejercicios, valoración inicial y acceso básico a la app, todo por {{PLAN_1}}. Es una muy buena opción para empezar con buena guía y a su ritmo. Para orientarle mejor, ¿ha entrenado antes o es principiante?';

    /** Dos planes y uno escrito a mano: la lista está, sobra el nombre. */
    private const DOS_UNO_A_MANO = 'Claro. Tenemos {{PLAN_1}} y el Plan Mensual, los dos de un mes. ¿Cuál le interesa más?';

    /** Fuera de un turno de lista: un horario que pasa de 200 y cabe sin su pregunta (234 → 117). */
    private const HORARIO_234 = 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m., y los sábados, domingos y festivos de 8:00 a. m. a 2:00 p. m. ¿Le gustaría que le cuente también cómo funciona la inscripción y qué necesita para empezar a entrenar con nosotros?';

    private const APROBADO = ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9];

    private MarketingConversation $conversation;

    private int $mundo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 15:00:00', 'UTC'));
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.ultron.studio_config', 'canary');
        config()->set('marketing.ultron.studio_prompts', 'canary');
        config()->set('automation.rate_limits.marketing_ai', 100000);
        Http::fake();

        $this->catalogoDeProduccion();
        $this->fichaDelHorario();
        $this->nuevaConversacion();
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1 · el tope es del turno ─────────────────────────────────────────────

    public function test_1_el_tope_de_la_lista_es_del_turno_y_no_del_borrador(): void
    {
        $this->assertSame([229, 188, 366, 101], [self::mide(self::UN_PLAN), self::mide(self::UN_PLAN_PRECIO), self::mide(self::UN_PLAN_LARGO), self::mide(self::DOS_UNO_A_MANO)], 'lo que miden los textos ya expandidos');

        $sp = self::cargaDelToken($this->decide($this->entrante(self::PANORAMA), true)['decide_token'])['x']['sp'];
        $this->assertSame([200, 8], [$sp['n'], $sp['k']], 'historical c8 list cap');
        // En el turno de lista, 320, lo traiga o no el borrador.
        $this->assertSame(320, F::plansListCap($sp, self::PO));
        // Sin lista (no se ofreció, o un turno de cobro), Legacy o el compilador 5: ninguno, como hoy.
        $this->assertNull(F::plansListCap($sp, []));
        $this->assertNull(F::plansListCap(['m' => 'legacy'] + $sp, self::PO));
        $this->assertNull(F::plansListCap(array_diff_key($sp, ['k' => 1]), self::PO));
        $this->assertNull(F::plansListCap(['n' => 150] + $sp, self::PO), 'un tope firmado que no vale');
    }

    // ── 2 · `ai/check` ───────────────────────────────────────────────────────

    public function test_2_ai_check_al_borrador_de_un_solo_plan_le_dice_que_falta_la_lista_con_el_tope_de_la_lista(): void
    {
        $m = $this->entrante(self::PANORAMA);
        // PT-17, puerta 2, L1: estas notas son las de los marcadores (compiladores ≤ 7); con 8, las de la ranura con el mismo tope (UltronListaDelServidorTest).
        $token = $this->conCompilador($this->decide($m, true), (int) $this->conversation->id, (int) $m->id, 7);
        $faltaLaLista = 'FALTA LA LISTA DE PLANES: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME ni PLAN_PRICE. Ya expandidos ocupan 71 caracteres: quedan 179 de 250. Si no caben, dos; una pregunta corta o ninguna.';

        // El nombre y el marcador del mismo plan (229): falta la lista, sin la longitud de 200.
        $r = $this->check($m, $token, self::UN_PLAN)->assertOk()->json();
        $this->assertSame([[N::PLANS_OVERVIEW, O::MISSING]], array_map(static fn (array $d): array => [$d['code'], $d['detail']], $r['defects']));
        $this->assertSame($faltaLaLista, $r['notes']);
        $this->assertSame([false, true, true], [$r['pass'], $r['may_rewrite'], $r['rewrite']]);

        // El marcador con {{PLAN_PRICE}} (188): también habla de un solo plan.
        $r = $this->check($m, $token, self::UN_PLAN_PRECIO)->assertOk()->json();
        $this->assertSame([[N::PLANS_OVERVIEW, O::MISSING]], array_map(static fn (array $d): array => [$d['code'], $d['detail']], $r['defects']));
        $this->assertSame($faltaLaLista, $r['notes']);

        // Uno de 366: la longitud, con el MISMO tope que la lista (320) y su presupuesto.
        $r = $this->check($m, $token, self::UN_PLAN_LARGO)->assertOk()->json();
        $this->assertSame([N::PLANS_OVERVIEW, N::LENGTH_OVER_CAP], array_column($r['defects'], 'code'));
        $this->assertStringStartsWith($faltaLaLista, $r['notes']);
        $this->assertStringContainsString('DEMASIADO LARGO: mide 366 caracteres y el limite publicado es 320. Reescribelo en unos 250', $r['notes']);
        $this->assertStringNotContainsString('200', $r['notes']);
        $this->assertStringNotContainsString(' 160', $r['notes']);

        // Con dos planes y uno a mano, la lista está: sobra el nombre, como hoy.
        $r = $this->check($m, $token, self::DOS_UNO_A_MANO)->assertOk()->json();
        $this->assertSame([[N::PLANS_OVERVIEW, O::NAME_WRITTEN]], array_map(static fn (array $d): array => [$d['code'], $d['detail']], $r['defects']));
    }

    // ── 3 · el commit ────────────────────────────────────────────────────────

    public function test_3_el_commit_no_recorta_el_borrador_de_un_solo_plan_y_lo_sustituye_entero(): void
    {
        $r = $this->aprobado(self::UN_PLAN);

        $this->assertSame(self::PRECIOS_200, $r['reply_final'], 'el respaldo de precios de hoy');
        $fila = $this->ultimaAccion();
        $this->assertSame(self::expandida(self::UN_PLAN), $fila->metadata['policy_discarded_draft'], 'el borrador descartado, ENTERO: no se le quitó la pregunta');
        $this->assertSame(['cap' => 200, 'action' => 'replaced', 'from' => 229, 'to' => F::measure(self::PRECIOS_200), 'dropped' => 0, 'list_cap' => 320], $fila->metadata['length'], 'medido contra el tope de la lista');
        $this->assertFalse($fila->metadata['plans_overview']['valid']);
        $this->assertSame(O::MISSING, $fila->metadata['plans_overview']['detail'], 'habla de un solo plan: falta la lista');
    }

    // ── 4 · el laboratorio ───────────────────────────────────────────────────

    public function test_4_el_laboratorio_predice_lo_mismo_y_lo_que_se_sustituye_no_se_recorta(): void
    {
        $lab = app(StudioLabEngineCheck::class);
        $m = $this->entrante(self::PANORAMA);
        $turno = $this->turnoDe($m, $this->decide($m, true)['decide_token']);
        $this->assertSame(self::PO, $turno['po']);

        $p = $lab->predict($turno, ['reply_draft' => self::UN_PLAN, 'recommended_plan_id' => null, 'critic' => self::APROBADO], ['intent' => SalesIntents::PRICING_QUESTION, 'tools_requested' => []]);
        // PT-17, puerta 2, L1: con k = 8 la predicción añade la forma de la lista (`list`, aditivo).
        $this->assertSame(
            ['origin' => StudioLabEngineCheck::ORIGIN_FALLBACK, 'cause' => N::PLANS_OVERVIEW, 'codes' => [N::PLANS_OVERVIEW], 'measured' => 229, 'chars' => 229, 'cap' => 320, 'withinCap' => true, 'register' => 'ok', 'tuteo' => [], 'trimmed' => false, 'list' => ['shape' => O::SHAPE_MARKERS, 'slot' => false]],
            $p,
            'un solo tope, y el respaldo no recorta el borrador',
        );

        // Fuera de un turno de lista: el revisor lo rechaza, así que sale el respaldo y el borrador NO pasa por el recorte.
        $h = $this->entrante('¿Qué horario tienen?');
        $turnoH = $this->turnoDe($h, $this->decide($h, true)['decide_token']);
        $this->assertSame([], $turnoH['po'], 'sin lista ofrecida');
        $rechazo = ['verdict' => 'fail', 'attempt' => 3, 'issues' => ['relevance'], 'hard_fail' => null];
        $p = $lab->predict($turnoH, ['reply_draft' => self::HORARIO_234, 'recommended_plan_id' => null, 'critic' => $rechazo], ['intent' => SalesIntents::SCHEDULE_QUESTION, 'tools_requested' => []]);
        $this->assertSame([StudioLabEngineCheck::ORIGIN_FALLBACK, StudioLabEngineCheck::CAUSE_CRITIC, false, 234, 200], [$p['origin'], $p['cause'], $p['trimmed'], $p['chars'], $p['cap']]);
        // El mismo borrador aprobado sí se recorta (la regla de siempre, F1): sale del modelo sin su pregunta.
        $p = $lab->predict($turnoH, ['reply_draft' => self::HORARIO_234, 'recommended_plan_id' => null, 'critic' => self::APROBADO], ['intent' => SalesIntents::SCHEDULE_QUESTION, 'tools_requested' => []]);
        $this->assertSame([StudioLabEngineCheck::ORIGIN_MODEL, true, 117, 200], [$p['origin'], $p['trimmed'], $p['chars'], $p['cap']]);
    }

    // ── 5 · el aviso de longitud del laboratorio ─────────────────────────────

    public function test_5_el_aviso_de_longitud_del_laboratorio_usa_la_medida_y_el_tope_del_servidor(): void
    {
        $objetivo = ['configs' => ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]];
        $lista = 'Le cuento que tenemos estos planes: {{PLAN_1}} para acceso básico, {{PLAN_2}} con asesoría y {{PLAN_3}} con acceso total y guía nutricional. Los tres son de un mes y con acceso desde el primer día. ¿Ha entrenado antes?';
        $this->assertSame([218, 259], [mb_strlen($lista), self::mide($lista)]);
        $ids = static fn (array $h): array => array_column($h, 'id');

        // El recorrido del motor: lo que mide el servidor (259, ya expandido) contra el tope de la lista (320).
        $this->assertNotContains(StudioLabChecks::ID_LENGTH, $ids(StudioLabChecks::check($lista, $objetivo, ['chars' => 259, 'cap' => 320])));
        // Si el servidor lo ve largo, avisa con SU medida y SU tope.
        $h = StudioLabChecks::check($lista, $objetivo, ['chars' => 366, 'cap' => 320]);
        $this->assertSame([StudioLabChecks::ID_LENGTH], $ids($h));
        $this->assertSame('Tiene 366 caracteres y la longitud máxima es 320.', $h[0]['detail']);
        // Sin tope del servidor (la línea base sin lista), el de la versión, con la medida del servidor.
        $this->assertSame([StudioLabChecks::ID_LENGTH], $ids(StudioLabChecks::check($lista, $objetivo, ['chars' => 259, 'cap' => null])));
        // La simulación, como siempre: el texto contra `persona.maxChars`.
        $this->assertSame([StudioLabChecks::ID_LENGTH], $ids(StudioLabChecks::check($lista, $objetivo)));
        $this->assertSame([], $ids(StudioLabChecks::check('Claro, con gusto.', $objetivo)));
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    private static function expandida(string $borrador): string
    {
        return str_replace(['{{PLAN_1}}', '{{PLAN_2}}', '{{PLAN_3}}', '{{PLAN_PRICE}}'], ['Plan Valera ($65.000 COP)', 'Plan Mensual ($80.000 COP)', 'Élite ($180.000 COP)', '$65.000 COP'], $borrador);
    }

    private static function mide(string $borrador): int
    {
        return F::measure(self::expandida($borrador));
    }

    private function catalogoDeProduccion(): void
    {
        foreach (self::CATALOGO as $i => [$id, $nombre, $precio, $dias]) {
            (new Plan)->forceFill([
                'id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias,
                'active' => true, 'sellable' => true, 'sort_order' => $i + 1, 'is_recommended' => false,
                'benefits' => json_encode(['Acceso total al gimnasio']),
            ])->save();
        }
        Plan::query()->whereKey(3)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Asesoría semi personalizada'])]);
        Plan::query()->whereKey(4)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Clases grupales ilimitadas'])]);
        Plan::query()->whereKey(8)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Entrenador personal dedicado'])]);
    }

    private function fichaDelHorario(): void
    {
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
            'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
            'metadata' => ['windows' => [
                'lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'],
                'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'],
                'sabado' => ['08:00', '14:00'], 'domingo' => ['08:00', '14:00'],
            ]],
        ]);
    }

    private function nuevaConversacion(): void
    {
        $n = str_pad((string) ++$this->mundo, 3, '0', STR_PAD_LEFT);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3150541'.$n, 'meta_user_id' => '573150541'.$n,
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
    }

    private function entrante(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.l2.'.$this->mundo.'.'.uniqid(), 'status' => 'received',
        ]);
    }

    /** @return array<string,mixed> */
    private function decide(MarketingMessage $m, bool $negocia): array
    {
        $decide = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ] + ($negocia ? ['prompt_contract' => 'ultron-prompts/1', 'generation_contract' => 'ultron-list-turn/1'] : []), $this->headers())->assertOk()->json();

        return $this->historicalListTurn($decide, $m);
    }

    private function check(MarketingMessage $m, string $token, string $borrador, int $attempt = 1): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/check', [
            'conversation_id' => $m->conversation_id, 'message_id' => $m->id, 'decide_token' => $token, 'attempt' => $attempt,
            'draft' => ['reply_draft' => $borrador, 'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => null, 'tools_requested' => []],
        ], $this->headers());
    }

    /** Un borrador que el revisor aprobó, a la pregunta de los planes: el turno sale por el camino normal. */
    private function aprobado(string $borrador): array
    {
        $m = $this->entrante(self::PANORAMA);
        $token = $this->decide($m, true)['decide_token'];

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => [
                'strategy_goal' => 'resolve_objection', 'next_state' => P::GOAL_DISCOVERY, 'intent' => SalesIntents::PRICING_QUESTION, 'reply_draft' => $borrador,
                'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'answer', 'confidence' => 0.9, 'tools_requested' => [],
            ],
            'critic' => self::APROBADO,
        ], $this->headers())->assertOk()->json();
    }

    /**
     * El turno del laboratorio con lo que el motor tendría al comprobar ESTE
     * mensaje (lo firmado en el token y lo que leería), como en
     * `StudioLabEngineCheckParityTest`.
     *
     * @return array<string,mixed>
     */
    private function turnoDe(MarketingMessage $m, string $token): array
    {
        $firmado = app(UltronDecideToken::class)->peek($token, (int) $m->conversation_id, (int) $m->id);
        $this->assertTrue($firmado['valid']);
        $memoria = app(ConversationMemoryService::class);
        $leida = $memoria->load($this->conversation->fresh());

        return [
            'sp' => $firmado['extra']['sp'],
            'po' => $firmado['extra']['po'] ?? [],
            'catalog' => app(MarketingKnowledgeBaseService::class)->activePlans(),
            'inbound' => (string) $m->body,
            'phase' => app(P::class)->normalize($this->conversation->fresh()->commercial_phase),
            'memory' => $leida,
            'resolution' => app(ReferenceResolver::class)->resolve((string) $m->body, $leida, $memoria->sellablePlansForMemory()),
        ];
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function ultimaAccion(): MarketingAiAction
    {
        return MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
    }
}
