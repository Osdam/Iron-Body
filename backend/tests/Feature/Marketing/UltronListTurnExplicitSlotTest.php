<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\Prompts\UltronListTurnPrompts;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\Marketing\Ultron\UltronDecideToken;
use App\Services\Marketing\Ultron\UltronListTurnContract;
use App\Services\Marketing\Ultron\UltronReplyOrigin;
use App\Services\UltronStudio\StudioLabEngineCheck;
use App\Services\UltronStudio\StudioLabEngineContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

class UltronListTurnExplicitSlotTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const INBOUND = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 15:00:00', 'UTC'));
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', 'test-internal-secret');
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.ultron.studio_config', 'canary');
        config()->set('marketing.ultron.studio_prompts', 'canary');
        config()->set('automation.rate_limits.marketing_ai', 100000);
        Http::fake();
        foreach ([[3, 'Plan Valera', 65000], [4, 'Plan Mensual', 80000], [8, 'Élite', 180000]] as $i => [$id, $name, $price]) {
            (new Plan)->forceFill(['id' => $id, 'name' => $name, 'price' => $price, 'duration_days' => 30,
                'active' => true, 'sellable' => true, 'sort_order' => $i, 'benefits' => json_encode(['Acceso total al gimnasio'])])->save();
        }
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150539555', 'meta_user_id' => '573150539555', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2('c0000000-0000-4000-8000-0000000000c9', ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function invalidDrafts(): array
    {
        return [
            'missing slot' => ['Le comparto nuestros planes. ¿Cuál le interesa?', []],
            'missing slot with app tool' => ['Le comparto nuestros planes. ¿Cuál le interesa?', [SalesIntents::TOOL_APP_LINKS_SEND]],
            'spaces inside slot' => ['Le comparto {{ LISTA_DE_PLANES }}. ¿Cuál le interesa?', []],
            'old positional markers' => ['Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}. ¿Cuál le interesa?', []],
            'repeated app slot' => ['{{LISTA_DE_PLANES}} {{APP_LINKS}} {{APP_LINKS}}', [SalesIntents::TOOL_APP_LINKS_SEND]],
        ];
    }

    #[DataProvider('invalidDrafts')]
    public function test_invalid_c9_first_drafts_are_rejected_and_never_count_as_model_finals(string $raw, array $tools): void
    {
        [$message, $decide] = $this->turn();
        $draft = ['reply_draft' => $raw, 'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => 3, 'tools_requested' => $tools];
        $check = $this->postJson('/api/internal/marketing/ai/check', ['conversation_id' => $this->conversation->id, 'message_id' => $message->id,
            'decide_token' => $decide['decide_token'], 'attempt' => 1, 'draft' => $draft], $this->headers())->assertOk()->json();
        $this->assertFalse($check['pass']);
        $this->assertContains('plans_overview', array_column($check['defects'], 'code'));
        $this->assertStringContainsString('{{LISTA_DE_PLANES}}', $check['notes']);
        $this->assertStringContainsString('prosa maximo 245', $check['notes']);

        $sp = self::cargaDelToken($decide['decide_token'])['x']['sp'];
        $turn = app(StudioLabEngineContext::class)->build(['turns' => [['role' => 'prospect', 'text' => self::INBOUND]], 'facts' => ['paymentState' => 'none', 'handoffRequested' => false]], $sp)['turn'];
        $predicted = app(StudioLabEngineCheck::class)->predict($turn, ['reply_draft' => $raw, 'recommended_plan_id' => 3, 'critic' => ['verdict' => 'pass', 'issues' => [], 'notes' => '']], $draft);
        $this->assertSame('fallback', $predicted['origin']);

        $payload = [
            'source_type' => 'inbound_message', 'source_event_id' => $message->id, 'idempotency_key' => $message->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $decide['decide_token'],
            'proposal' => $draft + ['strategy_goal' => 'resolve_objection', 'next_state' => CommercialPhaseMachine::GOAL_DISCOVERY,
                'main_barrier' => null, 'next_best_action' => 'answer', 'confidence' => 0.9],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ];
        $result = $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->headers())->assertOk()->json();
        $action = MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
        $origin = UltronReplyOrigin::classify($action->metadata, $action->action_type);
        $this->assertSame('fallback', $origin['origin']);
        $this->assertFalse($action->metadata['plans_overview']['valid']);
        $this->assertNotSame($raw, $result['reply_final']);
        $this->assertStringNotContainsString('{{', $result['reply_final']);
    }

    public function test_c8_implicit_insertion_is_still_accepted_but_c9_has_no_insertion_path(): void
    {
        [$message, $decide] = $this->turn();
        $signed = self::cargaDelToken($decide['decide_token'])['x'];
        $signed['sp']['k'] = 8;
        $token = app(UltronDecideToken::class)->issue((int) $this->conversation->id, (int) $message->id, $decide['commercial_phase'], $decide['allowed_transitions'], $decide['knowledge_version'], $signed)['token'];
        $response = $this->postJson('/api/internal/marketing/ai/check', [
            'conversation_id' => $this->conversation->id, 'message_id' => $message->id, 'decide_token' => $token, 'attempt' => 1,
            'draft' => ['reply_draft' => 'Le comparto nuestros planes. ¿Cuál le interesa?', 'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => 3, 'tools_requested' => []],
        ], $this->headers())->assertOk()->json();
        $this->assertTrue($response['pass']);
    }

    public function test_the_controller_only_delivers_c9_role_alternatives_after_the_generation_handshake(): void
    {
        $message = MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => self::INBOUND, 'meta_message_id' => 'wamid.handshake.'.uniqid(), 'status' => 'received']);
        $request = ['conversation_id' => $this->conversation->id, 'message_id' => $message->id, 'prompt_contract' => 'ultron-prompts/1'];
        foreach ([[], ['generation_contract' => 'unknown'], ['generation_contract' => []]] as $handshake) {
            $decide = $this->postJson('/api/internal/marketing/ai/decide', $request + $handshake, $this->headers())->assertOk()->json();
            $this->assertSame('legacy', $decide['studio_prompts']['mode']);
            $this->assertSame(UltronPromptOffer::GENERATION_CONTRACT_MISSING, $decide['studio_prompts']['reason']);
            $this->assertNull($decide['studio_prompts']['roles']);
            $signed = self::cargaDelToken($decide['decide_token'])['x'];
            $this->assertSame('legacy', $signed['sp']['m']);
            $this->assertArrayNotHasKey('po', $signed);
            $this->assertArrayNotHasKey('plans_overview', $decide['context']['commercial_turn_policy']);
        }
        $decide = $this->postJson('/api/internal/marketing/ai/decide', $request + ['generation_contract' => UltronListTurnContract::CONTRACT], $this->headers())->assertOk()->json();
        $this->assertSame('studio', $decide['studio_prompts']['mode']);
        $this->assertSame(9, $decide['studio_prompts']['compiler']);
        $this->assertNotNull(UltronListTurnContract::fromPayload($decide['context']));
        $this->assertSame([3, 4, 8], self::cargaDelToken($decide['decide_token'])['x']['po']);
        foreach ($decide['studio_prompts']['roles'] as $role => $packed) {
            $listInstructions = UltronListTurnPrompts::select($role, $packed, true);
            $generalInstructions = UltronListTurnPrompts::select($role, $packed, false);
            $this->assertTrue(UltronListTurnPrompts::isSpecialized($listInstructions));
            $this->assertFalse(UltronListTurnPrompts::isSpecialized($generalInstructions));
        }
    }

    private function turn(): array
    {
        $message = MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => self::INBOUND, 'meta_message_id' => 'wamid.explicit.'.uniqid(), 'status' => 'received']);
        $decide = $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $message->id,
            'prompt_contract' => 'ultron-prompts/1', 'generation_contract' => UltronListTurnContract::CONTRACT], $this->headers())->assertOk()->json();
        $this->assertSame(9, self::cargaDelToken($decide['decide_token'])['x']['sp']['k']);

        return [$message, $decide];
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer test-internal-secret'];
    }
}
