<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Wompi\PaymentStateMachine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El motor de pago de ULTRON: el modelo solo PIDE `payment_link_send`; Laravel
 * decide si puede (Wompi productivo + permiso del negocio), genera el link con
 * el precio de la base de datos, lo manda como mensaje propio después de la
 * respuesta, y lo recuerda. Nunca se cobra dos veces, nunca a quien pidió que no
 * lo contacten, nunca un plan que no se vende, y el modelo nunca escribe una URL.
 */
class UltronPaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private Plan $plan;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        // Wompi «productivo» sin tocar la red: el checkout web se firma en local.
        config()->set('wompi.env', 'production');
        config()->set('wompi.public_key', 'pub_prod_test_key');
        config()->set('wompi.private_key', 'prv_prod_test_key');
        config()->set('wompi.integrity_secret', 'integrity_test_secret');
        config()->set('wompi.events_secret', 'events_test_secret');
        config()->set('wompi.checkout.base_url', 'https://checkout.wompi.co/p/');
        config()->set('wompi.checkout.redirect_url', 'https://web.ironbodyneiva.cloud/pago');
        config()->set('marketing.ultron.payment_links_enabled', true);
        Http::fake();

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_HOT]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::CLOSING]);
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

    /** Propuesta de cierre: el modelo pide el link para el plan recomendado. */
    private function commitLink(MarketingMessage $m, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $this->decide($m)->json('decide_token'),
            'proposal' => array_merge([
                'strategy_goal' => 'collect_payment', 'next_state' => P::CLOSING, 'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
                'reply_draft' => 'Listo, te paso el link de pago del {{PLAN_NAME}} para que lo hagas desde el celular.',
                'recommended_plan_id' => $this->plan->id, 'main_barrier' => null, 'next_best_action' => 'recommend_plan',
                'confidence' => 0.9, 'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
            ], $overrides),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ], $this->h());
    }

    private function linkMessages(): Collection
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->where('body', 'like', '%checkout.wompi.co%')->orderBy('id')->get();
    }

    public function test_decide_tells_the_model_whether_a_link_can_be_offered_and_lets_it_ask_for_one(): void
    {
        $r = $this->decide($this->inbound('quiero pagar', 'w.1'))->assertOk();
        $this->assertTrue($r->json('context.flags.can_offer_link'));
        $this->assertContains(SalesIntents::TOOL_PAYMENT_LINK_SEND, $r->json('allowed_tools'), 'con Wompi productivo y permiso del negocio, el link es una herramienta pedible');

        config()->set('marketing.ultron.payment_links_enabled', false);
        $r = $this->decide($this->inbound('quiero pagar', 'w.2'))->assertOk();
        $this->assertFalse($r->json('context.flags.can_offer_link'));
        $this->assertNotContains(SalesIntents::TOOL_PAYMENT_LINK_SEND, $r->json('allowed_tools'), 'sin permiso del negocio no se puede ni pedir');
    }

    public function test_a_requested_link_comes_from_the_catalogue_and_goes_out_after_the_reply(): void
    {
        $this->commitLink($this->inbound('dale, mándame el link', 'w.3'))->assertOk();

        $tx = PaymentTransaction::query()->where('plan_id', $this->plan->id)->first();
        $this->assertNotNull($tx, 'la transacción existe');
        $this->assertSame(80000.0, (float) $tx->amount, 'el monto sale del catálogo, no de la propuesta');
        $this->assertStringStartsWith('mkt-lead-'.$this->lead->id.'-plan-'.$this->plan->id.'-', (string) $tx->idempotency_key);
        $this->assertStringContainsString('checkout.wompi.co/p/', (string) $tx->checkout_url);
        $this->assertStringContainsString('signature:integrity=', (string) $tx->checkout_url);

        $links = $this->linkMessages();
        $this->assertCount(1, $links, 'el link viaja en un mensaje propio de Laravel');
        $this->assertStringContainsString($tx->checkout_url, $links->first()->body);
        $this->assertStringContainsString('80.000', $links->first()->body);
        $reply = MarketingMessage::where('conversation_id', $this->conversation->id)->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->where('body', 'like', 'Listo, te paso el link%')->first();
        $this->assertNotNull($reply, 'la respuesta del modelo se envió (y «te paso el link» no es un traspaso)');
        $this->assertLessThan($links->first()->id, $reply->id, 'primero la respuesta, después el link');

        $action = MarketingAiAction::latest('id')->first();
        $this->assertContains(SalesIntents::TOOL_PAYMENT_LINK_SEND, $action->metadata['tools_executed'] ?? []);
        $this->assertSame($tx->reference, $this->conversation->fresh()->memory['payment_context']['reference'] ?? null, 'la memoria sabe que hay un link vivo');
    }

    public function test_asking_twice_never_creates_a_second_charge(): void
    {
        $this->commitLink($this->inbound('mándame el link', 'w.4'))->assertOk();
        $this->commitLink($this->inbound('no me llegó, mándalo otra vez', 'w.5'))->assertOk();

        $this->assertSame(1, PaymentTransaction::count(), 'misma (lead, plan) en vuelo → misma transacción');
        $this->assertSame(1, $this->linkMessages()->pluck('body')->map(fn ($b) => preg_replace('/.*reference=([^&]+).*/s', '$1', $b))->unique()->count(), 'y la misma referencia');
    }

    public function test_without_the_business_permit_nothing_is_generated_or_promised(): void
    {
        config()->set('marketing.ultron.payment_links_enabled', false);

        $this->commitLink($this->inbound('mándame el link', 'w.6'), ['reply_draft' => 'Claro, dime qué plan te interesa y lo cerramos.'])->assertOk();

        $this->assertSame(0, PaymentTransaction::count());
        $this->assertCount(0, $this->linkMessages());
        $this->assertNotContains(SalesIntents::TOOL_PAYMENT_LINK_SEND, MarketingAiAction::latest('id')->first()->metadata['tools_executed'] ?? []);
    }

    public function test_do_not_contact_and_unsellable_plans_never_get_a_link(): void
    {
        $this->lead->forceFill(['do_not_contact' => true])->save();
        $this->commitLink($this->inbound('mándame el link', 'w.7'));
        $this->assertSame(0, PaymentTransaction::count(), 'do_not_contact gana');

        $this->lead->forceFill(['do_not_contact' => false])->save();
        $interno = Plan::create(['name' => 'Comisión interna', 'price' => 1, 'duration_days' => 2, 'active' => true, 'sellable' => false, 'benefits' => json_encode([])]);
        $this->commitLink($this->inbound('ese de un peso', 'w.8'), ['recommended_plan_id' => $interno->id]);
        $this->assertSame(0, PaymentTransaction::count(), 'lo que no se vende no se cobra');
        $this->assertCount(0, $this->linkMessages());
    }

    public function test_an_approved_payment_is_never_charged_again(): void
    {
        $this->commitLink($this->inbound('mándame el link', 'w.9'))->assertOk();
        PaymentTransaction::query()->update(['status' => PaymentStateMachine::APPROVED]);

        $this->commitLink($this->inbound('mándame el link otra vez', 'w.10'), ['reply_draft' => 'Claro, te cuento cómo empezar.'])->assertOk();

        $this->assertSame(1, PaymentTransaction::count(), 'ya pagó: no hay segunda transacción');
        $this->assertCount(1, $this->linkMessages(), 'y no se le vuelve a mandar un link');
    }

    public function test_the_model_never_writes_a_url_itself(): void
    {
        $r = $this->commitLink($this->inbound('mándame el link', 'w.11'), ['reply_draft' => 'Paga aquí: https://checkout.wompi.co/p/?public-key=pub_x&reference=inventada', 'tools_requested' => []]);

        $r->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_URL_IN_REPLY);
        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count(), 'una URL escrita por el modelo no sale nunca');
    }
}
