<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\WompiPaymentLinkService;
use App\Services\Wompi\PaymentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Estado del pago para el modelo: sale de las transacciones que el CRM creó para
 * este lead (por la clave de idempotencia lead+plan), no de lo que la persona
 * diga ni de una captura. «Ya pagué» dispara una consulta real a Wompi cuando
 * hay una transacción abierta allí; si no la hay, la respuesta honesta es que
 * no se ve un pago iniciado. Y nunca, bajo ningún pretexto, se piden datos de
 * tarjeta.
 */
class UltronPaymentStatusTest extends TestCase
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
        config()->set('wompi.env', 'production');
        config()->set('wompi.public_key', 'pub_prod_test_key');
        config()->set('wompi.private_key', 'prv_prod_test_key');
        config()->set('wompi.integrity_secret', 'integrity_test_secret');
        config()->set('wompi.events_secret', 'events_test_secret');
        config()->set('wompi.checkout.base_url', 'https://checkout.wompi.co/p/');
        config()->set('marketing.ultron.payment_links_enabled', true);
        // Sin catch-all aquí: un stub «*» registrado antes respondería vacío a Wompi
        // y la consulta en vivo no vería nada. Cada test registra lo que necesita.

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
        return $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], $this->h())->assertOk();
    }

    /** Un link vivo creado por el propio CRM para este lead, como lo haría el PUNTO 7. */
    private function linkPendiente(): PaymentTransaction
    {
        WompiPaymentLinkService::make()->generateForLead($this->lead, $this->plan, ['channel' => 'whatsapp', 'conversation_id' => $this->conversation->id]);

        return PaymentTransaction::query()->where('idempotency_key', 'like', 'mkt-lead-'.$this->lead->id.'-plan-%')->firstOrFail();
    }

    public function test_without_any_transaction_the_state_is_none_and_nothing_is_claimed(): void
    {
        $p = $this->decide($this->inbound('hola, quiero info', 'ps.1'))->json('context.payment');

        $this->assertSame('none', $p['state']);
        $this->assertFalse($p['claimed_by_lead']);
        $this->assertFalse($p['checked_live']);
    }

    public function test_a_pending_link_is_a_fact_for_the_model_even_without_member_identity(): void
    {
        $tx = $this->linkPendiente();

        $p = $this->decide($this->inbound('listo, lo miro', 'ps.2'))->json('context.payment');

        $this->assertSame('pending', $p['state']);
        $this->assertSame($this->plan->id, $p['plan_id']);
        $this->assertNotNull($p['expires_at']);
        $this->assertArrayNotHasKey('checkout_url', $p, 'la URL no viaja al modelo');
        $this->assertStringNotContainsString((string) $tx->checkout_url, json_encode($p));
    }

    public function test_a_payment_claim_without_an_open_wompi_transaction_is_honest_not_believed(): void
    {
        $this->linkPendiente();
        Http::fake();

        $r = $this->decide($this->inbound('ya pagué, actívame', 'ps.3'));
        $p = $r->json('context.payment');

        $this->assertTrue($p['claimed_by_lead'], 'la persona dice que pagó');
        $this->assertSame('pending', $p['state'], 'decirlo no lo convierte en pago');
        $this->assertFalse($p['checked_live'], 'sin transacción abierta en Wompi no hay nada que consultar');
        Http::assertNothingSent();
        $this->assertSame('payment_pending', $r->json('context.strategy_hints.lifecycle_mode'), 'la estrategia sabe que hay un pago en curso');
    }

    public function test_a_payment_claim_with_an_open_wompi_transaction_is_checked_live(): void
    {
        $tx = $this->linkPendiente();
        $tx->forceFill(['wompi_transaction_id' => '11111-1700000000-11111'])->save();
        Http::preventStrayRequests();
        Http::fake(['*/transactions/*' => Http::response(['data' => [
            'id' => '11111-1700000000-11111', 'status' => 'APPROVED', 'reference' => $tx->reference,
            'amount_in_cents' => 8000000, 'currency' => 'COP', 'payment_method_type' => 'NEQUI', 'finalized_at' => now()->toIso8601String(),
        ]], 200)]);

        $p = $this->decide($this->inbound('ya pagué', 'ps.4'))->json('context.payment');

        Http::assertSentCount(1);
        $this->assertTrue($p['checked_live']);
        $this->assertSame('approved', $p['state'], 'lo que dice Wompi, no lo que dice la persona');
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->fresh()->status);
    }

    public function test_an_approved_or_expired_transaction_is_reported_as_such(): void
    {
        $tx = $this->linkPendiente();
        $tx->forceFill(['status' => PaymentStateMachine::APPROVED])->save();
        $this->assertSame('approved', $this->decide($this->inbound('y ahora?', 'ps.5'))->json('context.payment.state'));

        $tx->forceFill(['status' => PaymentStateMachine::EXPIRED])->save();
        $p = $this->decide($this->inbound('el link no me sirve', 'ps.6'))->json('context.payment');
        $this->assertSame('expired', $p['state']);
        $this->assertTrue($p['can_regenerate'], 'un link vencido se puede volver a pedir');
    }

    public function test_the_model_never_asks_for_card_data(): void
    {
        $m = $this->inbound('cómo pago?', 'ps.7');
        $token = $this->decide($m)->json('decide_token');

        $r = $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => ['strategy_goal' => 'collect_payment', 'next_state' => P::CLOSING, 'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
                'reply_draft' => 'Claro, mándame el número de tu tarjeta, la fecha de vencimiento y el código CVV y lo proceso por acá.',
                'recommended_plan_id' => $this->plan->id, 'main_barrier' => null, 'next_best_action' => 'recommend_plan', 'confidence' => 0.9, 'tools_requested' => []],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ], $this->h());

        $r->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_CARD_DATA_REQUEST);
        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count());
    }
}
