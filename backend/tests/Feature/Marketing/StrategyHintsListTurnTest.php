<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\Ultron\StrategyContract;
use App\Services\UltronStudio\StudioLabEngineContext;
use App\Services\UltronStudio\StudioLabEngineFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\HistoricalListTurn;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17 · PUERTA 1 · L3 · LAS PISTAS DE UN TURNO DE LISTA (diagnóstico
 * estructural del 24-11-2026, causa C1): las pone el SERVIDOR, igual en
 * `decide` (producción) y en el laboratorio.
 *
 * Medido con el compilador 7: toda `pricing_question` produce
 * `price_engagement` y con él `should_recommend_now=true`; el estratega lee
 * «PISTAS… síguelas» y pide un solo plan mientras el texto de la lista pide
 * la lista, y los 20 primeros borradores cerraron con una pregunta de
 * descubrimiento.
 *
 *  1. {@see StrategyContract::conLaLista()}: con la lista ofrecida,
 *     `should_recommend_now=false`, `question_budget=0`, `may_ask=[]` y
 *     `suggested_question=null`; lo demás, igual. Sin lista, nada cambia.
 *  2. `decide` real («profesional · 200» publicada en la canaria, el catálogo
 *     de la medición): a «¿Qué planes tienen y cuánto cuesta la
 *     mensualidad?» ofrece la lista y esas pistas; el laboratorio
 *     ({@see StudioLabEngineContext::build()}) da EXACTAMENTE las mismas, y su
 *     Validar Estrategia no deja preguntar.
 *  3. Fuera del turno de lista (el precio de un plan, el horario) y en Legacy
 *     (sin la oferta del Studio no hay lista), las pistas de siempre, y
 *     también iguales en los dos lados.
 */
class StrategyHintsListTurnTest extends TestCase
{
    use HistoricalListTurn;
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000f3';

    private const GOBERNADO = ['persona.tone' => 'profesional', 'persona.maxChars' => 200];

    /** El catálogo de la medición (3 · Plan Valera, 4 · Plan Mensual, 8 · Élite) y dos que no son de un mes. */
    private const CATALOGO = [[25, 'PLAN DÍA', 10000, 1], [3, 'Plan Valera', 65000, 30], [4, 'Plan Mensual', 80000, 30], [5, 'Trimestre', 210000, 90], [8, 'Élite', 180000, 30]];

    private const PANORAMA = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private const CUATRO = ['should_recommend_now', 'may_ask', 'question_budget', 'suggested_question'];

    private int $conversationId = 0;

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
        foreach (self::CATALOGO as $i => [$id, $nombre, $precio, $dias]) {
            (new Plan)->forceFill(['id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias, 'active' => true, 'sellable' => true, 'sort_order' => $i + 1, 'is_recommended' => false,
                'benefits' => json_encode(['Acceso total al gimnasio'])])->save();
        }
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1 ───────────────────────────────────────────────────────────────────

    public function test_1_con_la_lista_ofrecida_el_turno_responde_con_ella(): void
    {
        $customer = ['lead_temperature' => 'warm', 'customer_lifecycle' => 'prospect', 'unknown' => ['objective', 'experience_level', 'time_constraints'], 'inferred' => ['buying_signals' => ['price_engagement']], 'known' => []];
        $hints = StrategyContract::hints($customer, ['type' => 'none'], false, 'pricing_question');
        $this->assertSame([true, ['objective', 'experience_level', 'time_constraints'], 1], [$hints['should_recommend_now'], $hints['may_ask'], $hints['question_budget']], 'lo que hoy da una pregunta de precio');

        $conLista = StrategyContract::conLaLista($hints, true);
        $this->assertSame(['should_recommend_now' => false, 'may_ask' => [], 'question_budget' => 0, 'suggested_question' => null], array_intersect_key($conLista, array_flip(self::CUATRO)));
        $this->assertSame(array_diff_key($hints, array_flip(self::CUATRO)), array_diff_key($conLista, array_flip(self::CUATRO)), 'lo demás, igual (lo firmado en el token también)');
        $this->assertSame(array_keys($hints), array_keys($conLista), 'la misma forma');
        $this->assertSame($hints, StrategyContract::conLaLista($hints, false), 'sin lista, nada cambia');
    }

    // ── 2 ───────────────────────────────────────────────────────────────────

    public function test_2_decide_y_el_laboratorio_dan_las_mismas_pistas_en_un_turno_de_lista(): void
    {
        $real = $this->decideReal(self::PANORAMA, true);
        $this->assertSame([3, 4, 8], array_column($real['context']['commercial_turn_policy']['plans_overview']['plans'] ?? [], 'plan_id'), 'decide ofrece la lista');
        $h = $real['context']['strategy_hints'];
        $this->assertSame(['should_recommend_now' => false, 'may_ask' => [], 'question_budget' => 0, 'suggested_question' => null], array_intersect_key($h, array_flip(self::CUATRO)), 'producción: el turno responde con la lista');

        $lab = $this->laboratorio(self::PANORAMA);
        $this->assertSame([3, 4, 8], $lab['turn']['po']);
        $this->assertSame($h, $lab['decide']['context']['strategy_hints'], 'el laboratorio, las mismas pistas (paridad)');

        // Validar Estrategia (el port del Code de n8n) no deja preguntar aunque el estratega quiera.
        foreach (['producción' => $real, 'laboratorio' => $lab['decide']] as $lado => $decide) {
            $v = StudioLabEngineFlow::validateStrategy($decide, ['output' => [['content' => [['text' => [
                'intent' => 'pricing_question', 'next_state' => 'NEW_LEAD', 'recommended_plan_id' => 3, 'tools_requested' => [],
                'information_needed' => ['objective', 'experience_level'], 'question_needed' => true,
            ]]]]]]);
            $this->assertSame([[], false], [$v['strategy']['information_needed'], $v['strategy']['question_needed']], "{$lado}: sin preguntas en un turno de lista");
        }
    }

    // ── 3 ───────────────────────────────────────────────────────────────────

    public function test_3_fuera_del_turno_de_lista_y_en_legacy_las_pistas_de_siempre(): void
    {
        // El precio de un plan nombrado: no es el panorama, no hay lista; la pregunta de precio sigue recomendando.
        $real = $this->decideReal('¿Cuánto vale el Plan Mensual?', true);
        $this->assertArrayNotHasKey('plans_overview', $real['context']['commercial_turn_policy'], 'sin lista');
        $this->assertTrue($real['context']['strategy_hints']['should_recommend_now'], 'sin lista, las de siempre');
        $this->assertSame($real['context']['strategy_hints'], $this->laboratorio('¿Cuánto vale el Plan Mensual?')['decide']['context']['strategy_hints'], 'paridad sin lista');

        // El horario: se puede preguntar, como hoy.
        $real = $this->decideReal('¿Qué horario tienen?', true);
        $this->assertArrayNotHasKey('plans_overview', $real['context']['commercial_turn_policy']);
        $this->assertSame(1, $real['context']['strategy_hints']['question_budget']);
        $this->assertNotSame([], $real['context']['strategy_hints']['may_ask']);
        $this->assertSame($real['context']['strategy_hints'], $this->laboratorio('¿Qué horario tienen?')['decide']['context']['strategy_hints'], 'paridad en el horario');

        // Legacy (sin negociar los prompts): sin la oferta del Studio no hay lista, y la pregunta de los planes trae las pistas de siempre…
        $legacy = $this->decideReal(self::PANORAMA, false);
        $this->assertArrayNotHasKey('plans_overview', $legacy['context']['commercial_turn_policy']);
        $this->assertSame([true, 1], [$legacy['context']['strategy_hints']['should_recommend_now'], $legacy['context']['strategy_hints']['question_budget']], 'Legacy no cambia');
        // …y la MISMA pregunta con la oferta del Studio cambia exactamente las cuatro, nada más: lo decide la lista, no la pregunta.
        $studio = $this->decideReal(self::PANORAMA, true)['context']['strategy_hints'];
        $this->assertSame(['should_recommend_now' => false, 'may_ask' => [], 'question_budget' => 0, 'suggested_question' => null], array_intersect_key($studio, array_flip(self::CUATRO)));
        $this->assertSame(array_diff_key($legacy['context']['strategy_hints'], array_flip(self::CUATRO)), array_diff_key($studio, array_flip(self::CUATRO)), 'lo demás, igual que en Legacy');
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    /**
     * Una conversación nueva con ese mensaje y su `decide` real, con o sin
     * negociar los prompts (sin negociar: Legacy).
     *
     * @return array<string,mixed>
     */
    private function decideReal(string $texto, bool $negocia): array
    {
        $n = str_pad((string) (++$this->conversationId), 3, '0', STR_PAD_LEFT);
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150542'.$n, 'meta_user_id' => '573150542'.$n, 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $c = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
        config()->set('marketing.ultron.canary_conversation_id', $c->id);
        if ($this->conversationId === 1) {
            $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, self::GOBERNADO), null);
        }
        $m = MarketingMessage::create([
            'conversation_id' => $c->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.l3.'.$n.'.'.uniqid(), 'status' => 'received',
        ]);
        $real = $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $c->id, 'message_id' => $m->id] + ($negocia ? ['prompt_contract' => 'ultron-prompts/1', 'generation_contract' => 'ultron-list-turn/1'] : []),
            ['Authorization' => 'Bearer '.self::SECRET])->assertOk()->json();
        $real = $this->historicalListTurn($real, $m);
        if ($negocia) {
            $this->assertSame('studio', $real['studio_prompts']['mode'], 'decide ofrece la versión');
        }

        return $real;
    }

    /** @return array{decide:array<string,mixed>,turn:array<string,mixed>,inbound:string} el mismo turno en el laboratorio, con el `sp` de la misma versión */
    private function laboratorio(string $texto): array
    {
        $parte = self::parteV2(self::VERSION, self::GOBERNADO);
        $sp = self::historicalListBundle($parte['governed'], self::VERSION, $parte['contentHash'])['sp'];

        return app(StudioLabEngineContext::class)->build(['turns' => [['role' => 'prospect', 'text' => $texto]], 'facts' => ['paymentState' => 'none', 'handoffRequested' => false]], $sp);
    }
}
