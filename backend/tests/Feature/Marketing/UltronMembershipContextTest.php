<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CustomerIntelligenceService as C;
use App\Services\Marketing\Ultron\MembershipFactGuard;
use App\Services\Marketing\Ultron\MembershipFactsProvider as M;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PUNTO 11 — asistente posventa. Lo que el modelo puede afirmar sobre la
 * membresía de quien escribe viaja en `context.membership` como hecho del CRM,
 * solo con identidad; renovar es la única venta a un cliente y solo en ventana
 * con un plan que hoy se vende.
 */
class UltronMembershipContextTest extends TestCase
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
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.inbound.auto_analyze', false);
        Http::fake();

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::RECOMMENDATION]);
    }

    private function inbound(string $body, string $wamid): MarketingMessage
    {
        return MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received']);
    }

    private function decide(MarketingMessage $m): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], ['Authorization' => 'Bearer '.self::SECRET]);
    }

    /** Socia de pago único (users.plan es un NOMBRE), ligada al lead por identidad. */
    private function socia(int $startedDaysAgo, int $endsInDays): Member
    {
        $user = User::create([
            'name' => 'Socia', 'email' => 'socia-'.uniqid().'@ironbody.test', 'password' => bcrypt('secret-password'),
            'document' => '10'.random_int(10000000, 99999999), 'status' => 'active', 'plan' => $this->plan->name,
            'membership_start_date' => now()->subDays($startedDaysAgo)->toDateString(),
            'membership_end_date' => now()->addDays($endsInDays)->toDateString(),
        ]);
        $member = Member::create(['user_id' => $user->id, 'full_name' => 'Socia', 'email' => $user->email, 'document_number' => $user->document, 'phone' => '3150536026', 'status' => Member::STATUS_ACTIVE]);
        $this->lead->forceFill(['member_id' => $member->id])->save();

        return $member;
    }

    public function test_a_prospect_without_identity_gets_an_unknown_membership_block(): void
    {
        $d = $this->decide($this->inbound('hola, ¿hasta cuándo tengo plan?', 'm.1'))->assertOk();

        $this->assertSame(M::STATUS_UNKNOWN, $d->json('context.membership.status'));
        $this->assertFalse($d->json('context.membership.identity'));
        $this->assertSame(M::SOURCE_NOT_AVAILABLE, $d->json('context.membership.attendance'));
        $this->assertSame(M::TEAM_ONLY, $d->json('context.membership.team_only'));
        $this->assertNull($d->json('context.strategy_hints.renewal_plan_id'));
        $this->assertFalse($d->json('context.strategy_hints.renewal_possible'));
    }

    public function test_an_active_member_sees_her_membership_as_facts_and_is_in_support_mode(): void
    {
        $this->socia(startedDaysAgo: 10, endsInDays: 20);

        $d = $this->decide($this->inbound('¿hasta cuándo tengo plan?', 'm.2'))->assertOk();

        $this->assertSame(C::ACTIVE_MEMBER, $d->json('context.customer.customer_lifecycle'));
        $this->assertSame('support', $d->json('context.strategy_hints.lifecycle_mode'));
        $this->assertTrue($d->json('context.strategy_hints.do_not_sell'));
        $this->assertSame(M::STATUS_ACTIVE, $d->json('context.membership.status'));
        $this->assertSame('Plan Mensual', $d->json('context.membership.plan.name'));
        $this->assertSame(now()->addDays(20)->toDateString(), $d->json('context.membership.ends_on'));
        $this->assertFalse($d->json('context.membership.renewal.window_open'));
        $this->assertNull($d->json('context.strategy_hints.renewal_plan_id'));
        $this->assertFalse($d->json('context.strategy_hints.renewal_possible'));
        $this->assertStringNotContainsString('3150536026', json_encode($d->json('context.membership')));
        $this->assertStringNotContainsString('Socia', json_encode($d->json('context.membership')));
    }

    public function test_inside_the_renewal_window_the_current_plan_is_the_renewal_but_the_link_still_needs_the_engine(): void
    {
        $this->socia(startedDaysAgo: 25, endsInDays: 5);

        $d = $this->decide($this->inbound('quiero renovar', 'm.3'))->assertOk();

        $this->assertSame(M::STATUS_EXPIRING, $d->json('context.membership.status'));
        $this->assertTrue($d->json('context.membership.renewal.window_open'));
        $this->assertSame($this->plan->id, $d->json('context.membership.renewal.plan_id'));
        $this->assertTrue($d->json('context.customer.renewal_window'));
        $this->assertFalse($d->json('context.strategy_hints.do_not_sell'), 'en ventana sí se puede renovar');
        $this->assertSame($this->plan->id, $d->json('context.strategy_hints.renewal_plan_id'));
        $this->assertFalse($d->json('context.strategy_hints.renewal_possible'), 'sin motor de pagos no hay link: lo cobra el equipo');
        $this->assertNotContains('payment_link_send', $d->json('allowed_tools'));
    }

    public function test_a_renewal_of_a_plan_no_longer_sold_names_it_but_proposes_none(): void
    {
        $this->plan->forceFill(['sellable' => false])->save();
        $this->socia(startedDaysAgo: 25, endsInDays: 5);

        $d = $this->decide($this->inbound('quiero renovar', 'm.4'))->assertOk();

        $this->assertSame('Plan Mensual', $d->json('context.membership.plan.name'));
        $this->assertFalse($d->json('context.membership.plan.sellable'));
        $this->assertNull($d->json('context.membership.renewal.plan_id'));
        $this->assertNull($d->json('context.strategy_hints.renewal_plan_id'));
    }

    public function test_a_lapsed_member_is_expired_and_in_winback_mode(): void
    {
        $this->socia(startedDaysAgo: 60, endsInDays: -12);

        $d = $this->decide($this->inbound('hola, quiero volver', 'm.5'))->assertOk();

        $this->assertSame(C::LAPSED, $d->json('context.customer.customer_lifecycle'));
        $this->assertSame('winback', $d->json('context.strategy_hints.lifecycle_mode'));
        $this->assertSame(M::STATUS_EXPIRED, $d->json('context.membership.status'));
        $this->assertEqualsWithDelta(12, $d->json('context.membership.days_since_expiry'), 1);
        $this->assertSame($this->plan->id, $d->json('context.membership.renewal.plan_id'));
    }

    // ── Commit: la membresía se afirma con los hechos del CRM o no se afirma ──

    private function commit(MarketingMessage $m, array $proposal = []): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $this->decide($m)->json('decide_token'),
            'proposal' => array_merge([
                'strategy_goal' => 'increase_adherence', 'next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te cuento.', 'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'check_in',
                'confidence' => 0.8, 'tools_requested' => [],
            ], $proposal),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ], ['Authorization' => 'Bearer '.self::SECRET]);
    }

    private function outbound(): int
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count();
    }

    public function test_a_reply_that_contradicts_the_membership_end_date_never_goes_out(): void
    {
        $this->socia(startedDaysAgo: 10, endsInDays: 20);
        $mal = now()->addDays(23);

        $r = $this->commit($this->inbound('¿hasta cuándo tengo plan?', 'm.6'), [
            'reply_draft' => 'Tu plan vence el '.$mal->day.' de '.$this->mes($mal).', vas bien.',
        ]);

        $r->assertStatus(422)->assertJsonPath('code', MembershipFactGuard::CODE);
        $this->assertSame(0, $this->outbound(), 'una fecha equivocada de SU membresía no sale');
    }

    public function test_a_reply_that_states_the_real_end_date_goes_out(): void
    {
        $this->socia(startedDaysAgo: 10, endsInDays: 20);
        $fin = now()->addDays(20);

        $this->commit($this->inbound('¿hasta cuándo tengo plan?', 'm.7'), [
            'reply_draft' => 'Tu plan vence el '.$fin->day.' de '.$this->mes($fin).'. Te quedan 20 días.',
        ])->assertOk();

        $this->assertSame(1, $this->outbound());
    }

    public function test_without_an_identified_member_no_membership_fact_can_be_asserted(): void
    {
        $r = $this->commit($this->inbound('¿hasta cuándo tengo plan?', 'm.8'), [
            'reply_draft' => 'Tu membresía está activa y vence el 15 de octubre.',
        ]);

        $r->assertStatus(422)->assertJsonPath('code', MembershipFactGuard::CODE);
        $this->assertSame(0, $this->outbound());
    }

    public function test_a_reply_without_membership_claims_is_untouched_by_the_guard(): void
    {
        $this->commit($this->inbound('¿qué clases tienen?', 'm.9'), [
            'reply_draft' => 'Ese dato lo confirma el equipo con tu documento; mientras, te cuento cómo funcionan las clases.',
        ])->assertOk();

        $this->assertSame(1, $this->outbound());
    }

    public function test_renewal_inside_the_window_can_send_the_payment_link_when_the_engine_allows(): void
    {
        config()->set('wompi.env', 'production');
        config()->set('wompi.public_key', 'pub_prod_test_key');
        config()->set('wompi.private_key', 'prv_prod_test_key');
        config()->set('wompi.integrity_secret', 'integrity_test_secret');
        config()->set('wompi.events_secret', 'events_test_secret');
        config()->set('wompi.checkout.base_url', 'https://checkout.wompi.co/p/');
        config()->set('wompi.checkout.redirect_url', 'https://web.ironbodyneiva.cloud/pago');
        config()->set('marketing.ultron.payment_links_enabled', true);
        $this->socia(startedDaysAgo: 25, endsInDays: 5);

        $m = $this->inbound('quiero renovar, mándame el link', 'm.10');
        $d = $this->decide($m)->assertOk();
        $this->assertTrue($d->json('context.strategy_hints.renewal_possible'));
        $this->assertContains(SalesIntents::TOOL_PAYMENT_LINK_SEND, $d->json('allowed_tools'));

        $this->commit($m, [
            'strategy_goal' => 'collect_payment', 'next_state' => P::CLOSING, 'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
            'reply_draft' => 'Listo, te paso el link para renovar tu {{PLAN_NAME}}.', 'recommended_plan_id' => $this->plan->id,
            'next_best_action' => 'recommend_plan', 'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
        ])->assertOk();

        $tx = PaymentTransaction::query()->where('plan_id', $this->plan->id)->first();
        $this->assertNotNull($tx, 'renovar en ventana genera el link del plan actual');
        $this->assertStringStartsWith('mkt-lead-'.$this->lead->id.'-plan-'.$this->plan->id.'-', (string) $tx->idempotency_key);
        $this->assertSame(1, MarketingMessage::where('conversation_id', $this->conversation->id)->where('body', 'like', '%checkout.wompi.co%')->count());
    }

    public function test_outside_the_window_the_same_plan_is_a_resell_and_is_blocked(): void
    {
        $this->socia(startedDaysAgo: 10, endsInDays: 20);

        $r = $this->commit($this->inbound('quiero pagar el mensual otra vez', 'm.11'), [
            'strategy_goal' => 'close_plan', 'next_state' => P::CLOSING, 'intent' => SalesIntents::HIGH_INTENT_CLOSE,
            'reply_draft' => 'El {{PLAN_NAME}} cuesta {{PLAN_PRICE}}.', 'recommended_plan_id' => $this->plan->id,
        ])->assertOk();

        $this->assertSame('resell_blocked', $r->json('blocked_reason'));
        $this->assertSame(0, PaymentTransaction::count());
    }

    private function mes(Carbon $d): string
    {
        return ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][$d->month - 1];
    }
}
