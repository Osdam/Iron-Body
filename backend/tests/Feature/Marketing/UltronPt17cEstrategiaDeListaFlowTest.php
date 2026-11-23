<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\StrategyContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use ReflectionClassConstant;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17c · LA ESTRATEGIA DEL COMPILADOR 7 POR LOS ENDPOINTS REALES (Enmienda
 * A4-7 del H5; nota de diseño
 * `plans/informes-motores/diseno-pt17c-estratega-c7.md`): `decide` →
 * `commit`, con las clases reales, la v4 («profesional · 200») publicada solo
 * en la canaria, el catálogo vendible de producción y la ficha del horario
 * (el escenario de `UltronPlansListCapFlowTest`).
 *
 *  1. `decide`, a la pregunta de los planes y a la combinada: ofrece la lista
 *     (`plans_overview`, PLAN_1 a PLAN_3 = ids 3, 4 y 8), la política del
 *     turno trae `answer` en `allowed_next_actions` y el prompt del estratega
 *     que se le da a n8n lleva el texto del 7 (y no el del 6).
 *  2. `commit` con la estrategia que prescribe el texto del 7
 *     (`next_best_action answer`, `conversation_goal recommend`,
 *     `recommendation_goal compare_plans`, `information_needed []`,
 *     `question_needed false`, un `response_goal` del estratega de menos de
 *     160 caracteres y `recommended_plan_id` como referencia, 3 u 8) y un
 *     borrador real con la lista: 200, sale el texto del modelo expandido, sin
 *     violaciones de la política, y la auditoría guarda esa estrategia tal
 *     cual (`metadata.strategy`, `metadata.next_best_action`). Con la combinada,
 *     los cinco segundos borradores reales: lista y horario del domingo.
 *
 * Lo que el servidor hace con la lista no cambia con el 7 (lo fijan
 * `UltronPlansListCapFlowTest` y `UltronA4ListaDelModeloTest`); esto solo
 * comprueba que la estrategia nueva cabe en los contratos de hoy y que el
 * commit no la penaliza.
 */
class UltronPt17cEstrategiaDeListaFlowTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000c7';

    private const FIXTURE = __DIR__.'/../../fixtures/ultron-studio/pt17c-borradores-reales-c6.json';

    /** El catálogo vendible de producción (A4 §2.1): id, nombre, precio, días; en el orden de `activePlans()`. */
    private const CATALOGO = [
        [25, 'PLAN DÍA', 10000, 1],
        [2, 'Plan Semana', 45000, 7],
        [3, 'Plan Valera', 65000, 30],
        [4, 'Plan Mensual', 80000, 30],
        [5, 'Trimestre', 210000, 90],
        [6, 'Semestre', 390000, 180],
        [7, 'Anualidad', 624000, 365],
        [8, 'Élite', 180000, 30],
        [9, 'Pro', 499000, 90],
        [10, 'Prime', 950000, 180],
        [11, 'Evolution', 1800000, 365],
        [14, 'PROMO X4', 75000, 30],
        [16, 'INGRESO EMPLEADOS', 1, 360],
    ];

    private const PANORAMA = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private const COMBINADA = '¿Qué planes tienen y abren los domingos?';

    private const PO = [3, 4, 8];

    private MarketingConversation $conversation;

    private int $mundo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-23 18:00:00', 'UTC'));
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
            (new Plan)->forceFill([
                'id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias,
                'active' => true, 'sellable' => true, 'sort_order' => $i + 1, 'is_recommended' => false,
                'benefits' => json_encode(['Acceso total al gimnasio']),
            ])->save();
        }
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
            'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
        ]);
        $this->nuevaConversacion();
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1 · `decide` ─────────────────────────────────────────────────────────

    public function test_decide_ofrece_la_lista_permite_answer_y_da_al_estratega_el_texto_del_7(): void
    {
        $texto7 = (string) (new ReflectionClassConstant(UltronPromptCompiler::class, 'T_PLANS_STRATEGIST'))->getValue();
        foreach ([self::PANORAMA, self::COMBINADA] as $pregunta) {
            $this->nuevaConversacion();
            $decide = $this->decide($this->entrante($pregunta));
            $politica = $decide['context']['commercial_turn_policy'];
            $this->assertSame(self::PO, array_column($politica['plans_overview']['plans'], 'plan_id'), "{$pregunta}: la lista");
            $this->assertSame(['{{PLAN_1}}', '{{PLAN_2}}', '{{PLAN_3}}'], array_column($politica['plans_overview']['plans'], 'marker'), "{$pregunta}: cada marcador, su posición");
            $this->assertSame(self::PO, self::cargaDelToken($decide['decide_token'])['x']['po']);
            $this->assertContains('answer', $politica['allowed_next_actions'], "{$pregunta}: la acción del 7 la permite la política");

            $estratega = $decide['studio_prompts']['roles']['strategist'];
            $this->assertSame(['studio', UltronPromptCompiler::VERSION], [$decide['studio_prompts']['mode'], $decide['studio_prompts']['compiler']]);
            $this->assertSame(1, substr_count($estratega, $texto7), "{$pregunta}: el estratega lleva el texto del 7");
            $this->assertStringContainsString('next_best_action answer (de allowed_next_actions)', $estratega);
            $this->assertStringNotContainsString('next_best_action recommend_plan, conversation_goal recommend, recommendation_goal compare_plans', $estratega, 'ni el del 6');
            $this->assertSame(1, substr_count($estratega, 'next_best_action recommend_plan'), 'solo la de la base (aceptar una oferta, strategist.txt:31), que el 7 no toca');
        }
    }

    // ── 2 · `commit` ─────────────────────────────────────────────────────────

    public function test_commit_acepta_la_estrategia_del_7_con_un_borrador_real_de_la_lista(): void
    {
        $f = json_decode((string) file_get_contents(self::FIXTURE), true, 16, JSON_THROW_ON_ERROR);
        $real = array_values(array_filter($f['finalModel'], static fn (array $c): bool => $c['case'] === '38/171/E-003'))[0];

        foreach ([3, 8] as $plan) {
            $this->nuevaConversacion();
            $m = $this->entrante(self::PANORAMA);
            $decide = $this->decide($m);
            $propuesta = self::propuestaDelSiete($real['text'], $plan, 'Dar los planes de la lista con su marcador y un beneficio de cada uno y cerrar con una pregunta para elegir');
            $r = $this->commit($this->payload($m, $decide['decide_token'], $propuesta, 2))->assertOk()->json();

            $this->assertSame(self::expandida($real['text']), $r['reply_final'], "plan {$plan}: sale el texto del modelo, expandido");
            $this->assertSame($real['labChars'], mb_strlen($r['reply_final']));
            $this->assertAuditada($propuesta, "plan {$plan}");
        }
    }

    public function test_commit_acepta_la_combinada_lista_y_horario_de_los_cinco_segundos_borradores_reales(): void
    {
        $f = json_decode((string) file_get_contents(self::FIXTURE), true, 16, JSON_THROW_ON_ERROR);
        $this->assertCount(5, $f['combinedSecond']);

        foreach ($f['combinedSecond'] as $caso) {
            $this->nuevaConversacion();
            $m = $this->entrante(self::COMBINADA);
            $decide = $this->decide($m);
            $propuesta = self::propuestaDelSiete($caso['text'], 8, 'Dar los planes de la lista con su marcador y un beneficio de cada uno, contestar el horario del domingo y cerrar con una pregunta para elegir');
            $r = $this->commit($this->payload($m, $decide['decide_token'], $propuesta, 2))->assertOk()->json();

            $final = $r['reply_final'];
            $this->assertSame(self::expandida($caso['text']), $final, "{$caso['case']}: sale el texto del modelo, expandido");
            foreach (['Plan Valera ($65.000 COP)', 'Plan Mensual ($80.000 COP)', 'Élite ($180.000 COP)'] as $planTexto) {
                $this->assertStringContainsString($planTexto, $final, "{$caso['case']}: la lista");
            }
            $this->assertMatchesRegularExpression('/domingos de 8 a\.? ?m\.? a 2 p\.? ?m\./u', $final, "{$caso['case']}: el horario del domingo");
            $this->assertAuditada($propuesta, $caso['case']);
        }
    }

    // ── Piezas ───────────────────────────────────────────────────────────────

    /** Sin violaciones de la política, la lista del modelo válida y la estrategia del 7 guardada tal cual. */
    private function assertAuditada(array $propuesta, string $nombre): void
    {
        $meta = $this->ultimaAccion()->metadata;
        $this->assertArrayNotHasKey('policy_violations', $meta, "{$nombre}: el commit no la penaliza");
        $this->assertTrue($meta['plans_overview']['valid'] ?? false, "{$nombre}: la lista del modelo vale");
        $this->assertSame('answer', $meta['next_best_action'], $nombre);
        $this->assertSame(StrategyContract::fromProposal($propuesta), $meta['strategy'], "{$nombre}: la estrategia, tal cual");
        $this->assertSame(['recommend', 'compare_plans', [], false], [$meta['strategy']['conversation_goal'], $meta['strategy']['recommendation_goal'], $meta['strategy']['information_needed'], $meta['strategy']['question_needed']], $nombre);
    }

    /**
     * Una propuesta con la estrategia que prescribe el texto del 7. El
     * `response_goal` lo escribe aquí la prueba como lo haría el estratega
     * (no es una frase del motor); `recommended_plan_id`, la referencia.
     *
     * @return array<string,mixed>
     */
    private static function propuestaDelSiete(string $borrador, int $plan, string $objetivo): array
    {
        return [
            'strategy_goal' => 'collect_data',
            'next_state' => P::GOAL_DISCOVERY,
            'intent' => SalesIntents::PRICING_QUESTION,
            'reply_draft' => $borrador,
            'recommended_plan_id' => $plan,
            'main_barrier' => null,
            'next_best_action' => 'answer',
            'confidence' => 0.8,
            'tools_requested' => [],
            'conversation_goal' => 'recommend',
            'information_needed' => [],
            'question_needed' => false,
            'response_goal' => $objetivo,
            'buying_signal' => 'weak',
            'closing_opportunity' => false,
            'payment_opportunity' => false,
            'app_support_opportunity' => false,
            'recommendation_goal' => 'compare_plans',
        ];
    }

    private static function expandida(string $borrador): string
    {
        return str_replace(['{{PLAN_1}}', '{{PLAN_2}}', '{{PLAN_3}}'], ['Plan Valera ($65.000 COP)', 'Plan Mensual ($80.000 COP)', 'Élite ($180.000 COP)'], $borrador);
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
        config()->set('marketing.ultron.studio_prompts', 'canary');
    }

    private function entrante(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.c7.'.$this->mundo.'.'.uniqid(), 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m): array
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id, 'prompt_contract' => 'ultron-prompts/1',
        ], $this->headers())->assertOk()->json();
    }

    private function payload(MarketingMessage $m, string $token, array $propuesta, int $intento): array
    {
        return [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => $propuesta,
            'critic' => ['verdict' => 'pass', 'attempt' => $intento, 'score' => 0.9],
        ];
    }

    private function commit(array $payload): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->headers());
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function ultimaAccion(): MarketingAiAction
    {
        return MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
    }
}
