<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ComposerStyleGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** El tono, por el recorrido real: la presión no sale; el folleto queda anotado. */
class UltronComposerStyleTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.inbound.auto_analyze', false);
        Http::fake();

        foreach (['Plan Semana' => 7, 'Plan Mensual' => 30, 'Trimestre' => 90, 'Semestre' => 180, 'Anualidad' => 365] as $name => $days) {
            Plan::create(['name' => $name, 'price' => 80000, 'duration_days' => $days, 'active' => true, 'benefits' => json_encode(['Acceso'])]);
        }
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

    private function commit(MarketingMessage $m, string $draft): TestResponse
    {
        $token = $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h())->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => ['strategy_goal' => 'collect_data', 'next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO, 'reply_draft' => $draft,
                'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'recommend_plan', 'confidence' => 0.8, 'tools_requested' => []],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $this->h());
    }

    public function test_pressure_is_rejected_before_anything_happens(): void
    {
        foreach ([
            'Quedan los últimos cupos del mes, aprovecha ya.',
            'Sin excusas: todo está en la mente. A tu edad ya deberías estar entrenando.',
            'El 90% de nuestros clientes bajan 5 kilos el primer mes.',
        ] as $i => $draft) {
            $this->commit($this->inbound('hola', 'p.'.$i), $draft)
                ->assertStatus(422)->assertJsonPath('code', ComposerStyleGuard::CODE_PRESSURE);
        }
        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
        $this->assertSame(0, MarketingAiAction::count());
    }

    public function test_a_catalogue_dump_is_flagged_unless_the_person_asked_for_all(): void
    {
        $folleto = 'Tenemos Plan Semana, Plan Mensual, Trimestre, Semestre y Anualidad. ¿Cuál te interesa? ¿Hay algo más en lo que pueda ayudarte?';
        $this->commit($this->inbound('hola, info', 'd.1'), $folleto)->assertOk();
        $meta = MarketingAiAction::latest('id')->first()->metadata;
        $this->assertContains('style:plan_dump', $meta['risk_flags']);
        $this->assertContains('style:bot_phrase', $meta['risk_flags']);
        $this->assertSame(5, $meta['plans_mentioned']);

        $this->commit($this->inbound('qué planes tienen?', 'd.2'), 'Tenemos Plan Semana, Plan Mensual, Trimestre, Semestre y Anualidad; el más pedido es el mensual. ¿Cuánto tiempo quieres comprometerte?')->assertOk();
        $this->assertNotContains('style:plan_dump', MarketingAiAction::latest('id')->first()->metadata['risk_flags']);
    }

    /** La segunda puerta de texto de máquina comparte el criterio. */
    public function test_the_send_message_door_rejects_pressure_too(): void
    {
        $lead = $this->conversation->lead;
        $r = $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $lead->id, 'conversation_id' => $this->conversation->id,
            'body' => 'Quedan los últimos cupos, decide ya. Sin excusas.',
        ], $this->h());

        $r->assertStatus(422)->assertJsonPath('code', ComposerStyleGuard::CODE_PRESSURE);
        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    public function test_helpful_consultative_replies_carry_no_style_flags(): void
    {
        $this->commit($this->inbound('me da pena empezar', 'c.1'), 'Te entiendo: empezar da nervios. El primer día el equipo técnico te hace la valoración y te acompaña. ¿Qué día te queda bien pasar?')->assertOk();
        $flags = MarketingAiAction::latest('id')->first()->metadata['risk_flags'];
        $this->assertSame([], array_values(array_filter($flags, fn ($f) => str_starts_with($f, 'style:'))));
    }
}
