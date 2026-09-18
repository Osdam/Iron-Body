<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** El Critic habla en claves cerradas y su veredicto queda auditado siempre, también cuando aprueba. */
class UltronCriticContractTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

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
        Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso'])]);
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

    private function commit(MarketingMessage $m, ?array $critic, string $draft = 'Claro, te cuento como empezar.'): TestResponse
    {
        $token = $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h())->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => ['strategy_goal' => 'collect_data', 'next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO, 'reply_draft' => $draft,
                'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'recommend_plan', 'confidence' => 0.8, 'tools_requested' => []],
        ] + ($critic === null ? [] : ['critic' => array_merge(['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9], $critic)]), $this->h());
    }

    public function test_critic_vocabulary_is_closed(): void
    {
        $this->commit($this->inbound('hola', 'k.1'), ['issues' => ['vibes']])->assertStatus(422)->assertJsonValidationErrors(['critic.issues.0']);
        $this->commit($this->inbound('hola', 'k.2'), ['issues' => ['a' => 'novelty']])->assertStatus(422)->assertJsonValidationErrors(['critic.issues']);
        $this->commit($this->inbound('hola', 'k.3'), ['hard_fail' => 'too_boring'])->assertStatus(422)->assertJsonValidationErrors(['critic.hard_fail']);
        $this->assertSame(0, MarketingAiAction::count());
    }

    public function test_an_approving_verdict_is_persisted_too(): void
    {
        $this->commit($this->inbound('hola', 'k.4'), ['issues' => ['naturalness'], 'score' => 0.82, 'notes' => 'Un poco formal; llamar al <b>3001234567</b> no.'])->assertOk();

        $critic = MarketingAiAction::latest('id')->first()->metadata['critic'] ?? null;
        $this->assertNotNull($critic, 'el veredicto se audita también cuando aprueba');
        $this->assertSame('pass', $critic['verdict']);
        $this->assertSame(1, $critic['attempt']);
        $this->assertSame(0.82, $critic['score']);
        $this->assertSame(['naturalness'], $critic['issues']);
        $this->assertNull($critic['hard_fail']);
        $this->assertStringNotContainsString('<b>', $critic['notes']);
        $this->assertStringNotContainsString('3001234567', $critic['notes']);
    }

    public function test_a_failing_verdict_keeps_its_hard_fail_code(): void
    {
        $r = $this->commit($this->inbound('dónde quedan?', 'k.5'), ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.2, 'issues' => ['factuality'], 'hard_fail' => 'invented_class'], 'borrador malo');

        $r->assertOk();
        $critic = MarketingAiAction::latest('id')->first()->metadata['critic'];
        $this->assertSame('fail', $critic['verdict']);
        $this->assertSame('invented_class', $critic['hard_fail']);
        $this->assertSame(['factuality'], $critic['issues']);
    }

    /** Hallazgo del revisor: sin bloque critic no se inventa un pass. */
    public function test_without_a_critic_block_no_verdict_is_fabricated(): void
    {
        $this->commit($this->inbound('hola', 'k.6'), null)->assertOk();

        $this->assertArrayNotHasKey('critic', MarketingAiAction::latest('id')->first()->metadata, 'un pass que nadie emitió no es un dato');
    }

    /** n8n emite todas las claves y las que no aplican llegan en null: null no es «intento 1». */
    public function test_attempt_null_on_the_failure_path_is_audited_as_the_second_attempt(): void
    {
        $this->commit($this->inbound('dónde quedan?', 'k.7'), ['verdict' => 'fail', 'attempt' => null, 'score' => 0.1, 'issues' => ['relevance']])->assertOk();

        $this->assertSame(2, MarketingAiAction::latest('id')->first()->metadata['critic']['attempt']);
    }

    /** Un fallo duro es un fallo aunque el veredicto diga pass: la contradicción se resuelve del lado que no envía. */
    public function test_a_hard_fail_is_a_fail_even_if_the_verdict_says_pass(): void
    {
        $draft = 'La clase de yoga es a las 5 am todos los días.';
        $this->commit($this->inbound('hay yoga?', 'k.8'), ['verdict' => 'pass', 'hard_fail' => 'invented_class', 'issues' => ['factuality']], $draft)->assertOk();

        $action = MarketingAiAction::latest('id')->first();
        $this->assertSame('fail', $action->metadata['critic']['verdict']);
        $this->assertSame('invented_class', $action->metadata['critic']['hard_fail']);
        $this->assertNotNull($action->metadata['fallback_mode'] ?? null, 'tomó el camino del critic fallido');
        $this->assertFalse(MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->where('body', $draft)->exists(), 'el borrador con la clase inventada no se envía');
        $this->assertTrue((bool) $this->conversation->fresh()->staff_review_pending);
    }

    public function test_critic_notes_keep_up_to_500_characters_and_still_get_redacted(): void
    {
        $notes = str_repeat('Falta el paso concreto. ', 18).'Dijo que cuesta 80.000 y no.';
        $this->commit($this->inbound('hola', 'k.9'), ['notes' => $notes])->assertOk();

        $saved = MarketingAiAction::latest('id')->first()->metadata['critic']['notes'];
        $this->assertGreaterThan(400, mb_strlen($saved), 'el endpoint admite 500; el contrato no recorta a 160');
        $this->assertMatchesRegularExpression('/\[(precio|numero)\]/', $saved);
        $this->assertStringNotContainsString('80.000', $saved);
    }

    /** Residuo del revisor: n8n manda todas las claves, y todas en null no es un veredicto. */
    public function test_a_critic_block_with_every_key_null_is_not_persisted_as_a_pass(): void
    {
        $this->commit($this->inbound('hola', 'k.10'), ['verdict' => null, 'attempt' => null, 'score' => null, 'notes' => null, 'issues' => null, 'hard_fail' => null])->assertOk();

        $this->assertArrayNotHasKey('critic', MarketingAiAction::latest('id')->first()->metadata);
    }
}
