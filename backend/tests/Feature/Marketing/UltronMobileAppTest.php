<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MobileAppLinks;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lo que el modelo puede decir de la app sale de un catálogo escrito desde el
 * código real de la app (no de nombres de carpetas ni de la imaginación), y los
 * enlaces oficiales los envía Laravel en su propio mensaje cuando el modelo lo
 * pide con `app_links_send`. El modelo nunca escribe una URL de la app.
 */
class UltronMobileAppTest extends TestCase
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
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_INTERESTED]);
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
        return $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h())->assertOk();
    }

    private function commit(MarketingMessage $m, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $this->decide($m)->json('decide_token'),
            'proposal' => array_merge([
                'strategy_goal' => 'collect_data', 'next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te paso los enlaces de la app para que la descargues.', 'recommended_plan_id' => null, 'main_barrier' => null,
                'next_best_action' => 'recommend_plan', 'confidence' => 0.8, 'tools_requested' => ['app_links_send'],
            ], $overrides),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ], $this->h());
    }

    public function test_decide_hands_the_model_the_app_catalogue_built_from_the_real_app(): void
    {
        $app = $this->decide($this->inbound('qué puedo hacer en la app?', 'a.1'))->json('context.app');

        $this->assertSame(['android' => MobileAppLinks::ANDROID, 'ios' => MobileAppLinks::IOS, 'web' => MobileAppLinks::WEB], $app['links']);
        $keys = array_column($app['features'], 'key');
        foreach (['otp_login', 'membership_and_renewal', 'wompi_payments', 'class_reservations', 'workouts', 'nutrition', 'progress', 'store', 'iron_ai_coach', 'weekly_streak'] as $k) {
            $this->assertContains($k, $keys, "la app sí tiene $k");
        }
        foreach ($app['features'] as $f) {
            $this->assertNotEmpty($f['title']);
            $this->assertNotEmpty($f['what']);
        }
        $this->assertStringContainsString('SMS', $app['help']['login'], 'el acceso es con documento o teléfono y código por SMS');
        $this->assertStringContainsString('documento', mb_strtolower($app['help']['register']));
        $this->assertFalse($app['account']['has_account'], 'este prospecto no tiene cuenta');
        $this->assertStringNotContainsString('3150536026', json_encode($app));
    }

    public function test_the_model_may_ask_laravel_to_send_the_official_links(): void
    {
        $m = $this->inbound('mándame el link de la app', 'a.2');
        $this->assertContains('app_links_send', $this->decide($m)->json('allowed_tools'), 'no hay dinero en juego: siempre disponible');

        $r = $this->commit($m)->assertOk();

        $links = MarketingMessage::where('conversation_id', $this->conversation->id)->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->where('body', 'like', '%apps.apple.com%')->get();
        $this->assertCount(1, $links, 'los enlaces salen en un mensaje propio de Laravel');
        $this->assertStringContainsString(MobileAppLinks::ANDROID, $links->first()->body);
        $this->assertStringContainsString(MobileAppLinks::WEB, $links->first()->body);
        $reply = MarketingMessage::where('conversation_id', $this->conversation->id)->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->where('body', 'like', 'Claro, te paso los enlaces%')->first();
        $this->assertNotNull($reply);
        $this->assertLessThan($links->first()->id, $reply->id, 'primero la respuesta, después los enlaces');
        $this->assertContains('app_links_send', $r->json('applied.tools_executed'));
        $this->assertContains('app_links_send', MarketingAiAction::latest('id')->first()->metadata['tools_executed']);
        $this->assertNotNull($this->conversation->fresh()->memory['app_context']['links_sent_at'] ?? null, 'la memoria sabe que ya se enviaron');
    }

    public function test_the_model_never_writes_the_app_url_itself(): void
    {
        $r = $this->commit($this->inbound('mándame el link de la app', 'a.3'), ['reply_draft' => 'Descárgala en https://play.google.com/store/apps/details?id=com.ironbodyneiva.workout', 'tools_requested' => []]);

        $r->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_URL_IN_REPLY);
        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count());
    }

    public function test_without_the_tool_no_links_go_out(): void
    {
        $this->commit($this->inbound('hola', 'a.4'), ['reply_draft' => 'Hola, ¿qué quieres lograr entrenando?', 'tools_requested' => []])->assertOk();

        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->where('body', 'like', '%apps.apple.com%')->count());
    }
}
