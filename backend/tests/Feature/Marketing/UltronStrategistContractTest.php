<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\StrategyContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El Strategist propone estrategia; Laravel le adelanta las pistas y le cierra
 * la puerta a cualquier autoridad de negocio.
 */
class UltronStrategistContractTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private Plan $plan;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.inbound.auto_analyze', false);
        Http::fake();

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::RECOMMENDATION]);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function inbound(string $body, string $wamid): MarketingMessage
    {
        return MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received']);
    }

    private function decide(MarketingMessage $m): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h());
    }

    private function commit(MarketingMessage $m, array $proposal): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $this->decide($m)->json('decide_token'),
            'proposal' => array_merge(['strategy_goal' => 'close_plan', 'next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te cuento.', 'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'recommend_plan',
                'confidence' => 0.8, 'tools_requested' => []], $proposal),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $this->h());
    }

    /** A un prospecto frío se le puede preguntar; a quien ya quiere pagar, no. */
    public function test_decide_hands_the_strategist_its_hints(): void
    {
        $frio = $this->decide($this->inbound('hola, info porfa', 's.1'))->assertOk()->json('context.strategy_hints');
        $this->assertFalse($frio['hot_lead_fast_path']);
        $this->assertSame('consultative', $frio['lifecycle_mode']);
        $this->assertContains('objective', $frio['may_ask']);
        $this->assertSame(1, $frio['question_budget']);
        $this->assertFalse($frio['payment_possible']);

        $listo = $this->decide($this->inbound('quiero el mensual, mándame el link', 's.2'))->assertOk()->json('context.strategy_hints');
        $this->assertTrue($listo['hot_lead_fast_path']);
        $this->assertSame('closing', $listo['lifecycle_mode']);
        $this->assertSame([], $listo['may_ask'], 'nada que preguntar a quien ya quiere pagar');
        $this->assertSame(0, $listo['question_budget']);
        $this->assertTrue($listo['should_recommend_now']);
    }

    /** Las propuestas del contrato se aceptan y quedan auditables en la acción. */
    public function test_strategy_proposals_are_accepted_and_persisted(): void
    {
        $this->commit($this->inbound('quiero ganar masa, que me recomiendas?', 's.3'), [
            'intent' => SalesIntents::GOAL_MUSCLE_GAIN, 'recommended_plan_id' => $this->plan->id,
            'conversation_goal' => 'recommend', 'information_needed' => [], 'question_needed' => false,
            'response_goal' => 'recomendar el mensual y dar el siguiente paso', 'buying_signal' => 'weak',
            'closing_opportunity' => false, 'payment_opportunity' => false, 'app_support_opportunity' => false, 'recommendation_goal' => 'single_plan',
            'reply_draft' => 'Para ganar masa el {{PLAN_NAME}} te sirve: acceso ilimitado y rutina asignada. Sale en {{PLAN_PRICE}}.',
        ])->assertOk();

        $meta = MarketingAiAction::latest('id')->first()->metadata;
        $this->assertSame('recommend', $meta['strategy']['conversation_goal']);
        $this->assertSame('single_plan', $meta['strategy']['recommendation_goal']);
        $this->assertFalse($meta['strategy']['question_needed']);
        $this->assertSame('consultative', $meta['strategy_hints']['lifecycle_mode']);
    }

    /** Ningún campo de autoridad de negocio entra, se llame como se llame. */
    public function test_business_authority_fields_are_rejected_before_anything_runs(): void
    {
        foreach (['price' => 50000, 'amount_in_cents' => 5000000, 'payment_url' => 'https://checkout.wompi.co/l/abc', 'payment_status' => 'APPROVED', 'sellable' => true, 'human_handoff_allowed' => true] as $campo => $valor) {
            $m = $this->inbound('quiero pagar', 's.4.'.$campo);
            $this->commit($m, [$campo => $valor, 'reply_draft' => 'Listo.'])
                ->assertStatus(422)->assertJsonPath('code', 'unexpected_fields')->assertJsonPath('fields.0', 'proposal.'.$campo);
        }
        $this->assertSame(0, MarketingAiAction::count());
        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    /** Los enums son cerrados. */
    public function test_strategy_enums_are_closed(): void
    {
        $this->commit($this->inbound('hola', 's.5'), ['conversation_goal' => 'upsell_hard', 'reply_draft' => 'Hola.'])
            ->assertStatus(422)->assertJsonValidationErrors(['proposal.conversation_goal']);
        $this->commit($this->inbound('hola', 's.6'), ['buying_signal' => 'very_strong', 'reply_draft' => 'Hola.'])
            ->assertStatus(422)->assertJsonValidationErrors(['proposal.buying_signal']);
    }

    /** Hallazgo: las claves de information_needed no tenían tope; ahora es una lista cerrada. */
    public function test_information_needed_is_a_closed_list(): void
    {
        $this->commit($this->inbound('hola', 's.8'), ['information_needed' => [str_repeat('k', 200) => 'objective'], 'reply_draft' => 'Hola.'])
            ->assertStatus(422)->assertJsonValidationErrors(['proposal.information_needed']);
        $this->commit($this->inbound('hola', 's.9'), ['information_needed' => ['shoe_size'], 'reply_draft' => 'Hola.'])
            ->assertStatus(422)->assertJsonValidationErrors(['proposal.information_needed.0']);
        $this->assertSame(0, MarketingAiAction::count());
    }

    /** Hallazgo: response_goal iba al almacén de auditoría tal cual; ahora sin etiquetas, cifras ni identificadores. */
    public function test_free_text_proposals_are_sanitised_before_being_persisted(): void
    {
        $this->commit($this->inbound('hola', 's.10'), [
            'response_goal' => '<img src=x onerror=alert(1)> llamar al 3001234567 y cotizar $80.000', 'question_needed' => '1', 'closing_opportunity' => '0',
            'reply_draft' => 'Hola, te cuento.',
        ])->assertOk();

        $st = MarketingAiAction::latest('id')->first()->metadata['strategy'];
        $this->assertStringNotContainsString('<img', $st['response_goal']);
        $this->assertStringNotContainsString('3001234567', $st['response_goal']);
        $this->assertStringNotContainsString('80.000', $st['response_goal']);
        $this->assertTrue($st['question_needed']);
        $this->assertFalse($st['closing_opportunity']);
    }

    /** Hallazgo: el modelo apagaba la bandera declarando otro intent; las pistas viajan firmadas desde decide. */
    public function test_the_model_cannot_switch_off_the_hot_lead_flag_by_declaring_another_intent(): void
    {
        $this->commit($this->inbound('ya me decidí, quiero inscribirme ya, mándame el link del mensual', 's.11'), [
            'intent' => SalesIntents::GENERAL_INFO, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Perfecto. Antes, ¿cuál es tu objetivo principal? Así te confirmo el {{PLAN_NAME}} por {{PLAN_PRICE}}.',
        ])->assertOk();

        $meta = MarketingAiAction::latest('id')->first()->metadata;
        $this->assertTrue($meta['strategy_hints']['hot_lead_fast_path'], 'la pista es la que decide emitió, no la que el modelo insinúa');
        $this->assertContains('discovery_question_to_ready_buyer', $meta['risk_flags']);
    }

    /** Hallazgo: «ya pagué, ¿cómo empiezo?» no es un hot lead: es onboarding. */
    public function test_a_paying_customer_who_wants_to_start_is_onboarding_not_a_hot_lead(): void
    {
        $hints = StrategyContract::hints(
            ['lead_temperature' => 'HOT', 'customer_lifecycle' => 'ONBOARDING', 'renewal_window' => false, 'known' => [], 'inferred' => [], 'unknown' => ['objective']],
            ['type' => 'how_to_start', 'plan_id' => 4], false, SalesIntents::GENERAL_INFO,
        );
        $this->assertFalse($hints['hot_lead_fast_path']);
        $this->assertSame('start', $hints['fast_path_kind']);
        $this->assertSame('onboarding', $hints['lifecycle_mode']);
        $this->assertTrue($hints['do_not_sell']);
        $this->assertSame([], $hints['may_ask'], 'sin presupuesto de preguntas, la lista va vacía');
        $this->assertSame(0, $hints['question_budget']);
    }

    /** Hallazgo: renovar a punto de vencer y pedir el link es cierre, no soporte. */
    public function test_a_renewing_member_asking_for_the_link_is_closing(): void
    {
        $hints = StrategyContract::hints(
            ['lead_temperature' => 'READY', 'customer_lifecycle' => 'ACTIVE_MEMBER', 'renewal_window' => true, 'known' => [], 'inferred' => [], 'unknown' => []],
            ['type' => 'send_it', 'plan_id' => 4], false, SalesIntents::PAYMENT_LINK_REQUEST,
        );
        $this->assertTrue($hints['hot_lead_fast_path']);
        $this->assertSame('closing', $hints['lifecycle_mode']);
        $this->assertFalse($hints['do_not_sell']);
        $this->assertTrue($hints['should_recommend_now']);

        $lapsed = StrategyContract::hints(
            ['lead_temperature' => 'READY', 'customer_lifecycle' => 'LAPSED', 'renewal_window' => false, 'known' => [], 'inferred' => [], 'unknown' => []],
            ['type' => 'send_it', 'plan_id' => 4], false, SalesIntents::PAYMENT_LINK_REQUEST,
        );
        $this->assertSame('closing', $lapsed['lifecycle_mode']);
    }

    /** Hallazgo: tras «está muy caro» no se vuelve a recomendar; la barrera sí se puede preguntar. */
    public function test_an_open_objection_stops_the_recommendation_and_allows_asking_the_barrier(): void
    {
        $hints = StrategyContract::hints(
            ['lead_temperature' => 'HOT', 'customer_lifecycle' => 'INTERESTED', 'renewal_window' => false, 'known' => [], 'inferred' => ['buying_signals' => ['price_engagement'], 'plan_interest' => 4, 'main_barrier' => null], 'unknown' => ['main_barrier', 'objective']],
            ['type' => 'none', 'plan_id' => null], false, SalesIntents::PRICE_OBJECTION,
        );
        $this->assertTrue($hints['open_objection']);
        $this->assertFalse($hints['should_recommend_now']);
        $this->assertContains('main_barrier', $hints['may_ask']);
    }

    /** Preguntarle el objetivo a quien ya quiere pagar queda registrado como riesgo. */
    public function test_a_discovery_question_to_a_ready_buyer_is_flagged(): void
    {
        $this->commit($this->inbound('quiero el mensual, mándame el link', 's.7'), [
            'intent' => SalesIntents::PAYMENT_LINK_REQUEST, 'recommended_plan_id' => $this->plan->id, 'next_state' => P::CLOSING,
            'reply_draft' => 'Claro. Antes, ¿cuál es tu objetivo principal? Así te confirmo si el {{PLAN_NAME}} por {{PLAN_PRICE}} es el indicado.',
        ])->assertOk();

        $this->assertContains('discovery_question_to_ready_buyer', MarketingAiAction::latest('id')->first()->metadata['risk_flags']);
        $this->assertTrue(MarketingAiAction::latest('id')->first()->metadata['strategy_hints']['hot_lead_fast_path']);
    }
}
