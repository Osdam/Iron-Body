<?php

namespace Tests\Unit\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Member;
use App\Services\Commercial\CommercialSubject;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ConversationMemory;
use App\Services\Marketing\Ultron\CustomerIntelligenceService as C;
use App\Services\Marketing\Ultron\ReferenceResolver as R;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quién es la persona, con honestidad: hechos, inferencias y huecos por
 * separado; temperatura por señales observables; ciclo de vida por hechos.
 */
class CustomerIntelligenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true]);
    }

    /** Un servicio cuyo sujeto Commercial es el que dictemos. */
    private function service(array $facts = []): C
    {
        $lead = $this->lead;
        $subject = new CommercialSubject(...array_merge(['lead' => $lead], $facts));

        return new class($subject) extends C
        {
            public function __construct(private readonly CommercialSubject $s) {}

            public function subjectFor(MarketingLead $lead): CommercialSubject
            {
                return $this->s;
            }
        };
    }

    private function inbound(string $body): MarketingMessage
    {
        return MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $body, 'meta_message_id' => uniqid('t.'), 'status' => 'received']);
    }

    private function profile(string $body, string $intent, array $resolved = ['type' => R::NONE, 'plan_id' => null], ?ConversationMemory $memory = null, array $facts = []): array
    {
        return $this->service($facts)->profile($this->conversation->fresh(), $this->inbound($body), $memory ?? ConversationMemory::empty(), $resolved, $intent);
    }

    // ── Temperatura por señales ───────────────────────────────────────────────

    public function test_just_browsing_is_cold_or_warm_never_hot(): void
    {
        $p = $this->profile('solo estaba mirando, gracias', SalesIntents::GENERAL_INFO);
        $this->assertContains($p['lead_temperature'], [C::COLD, C::WARM]);
        $this->assertContains('just_browsing', $p['temperature_signals']);
        $this->assertTrue($p['inferred']['just_browsing']);
    }

    public function test_a_price_question_is_warm_first_and_hot_once_a_plan_is_on_the_table(): void
    {
        $this->assertSame(C::WARM, $this->profile('cuánto vale?', SalesIntents::PRICING_QUESTION)['lead_temperature']);

        $m = ConversationMemory::empty()->recommend([4], 'x');
        $this->assertSame(C::HOT, $this->profile('y cuánto vale?', SalesIntents::PRICING_QUESTION, ['type' => R::PRICE_OF_PENDING, 'plan_id' => 4], $m)['lead_temperature']);
    }

    public function test_asking_for_the_link_or_wanting_to_pay_is_ready(): void
    {
        $this->assertSame(C::READY, $this->profile('mándame el link', SalesIntents::PAYMENT_LINK_REQUEST, ['type' => R::SEND_IT, 'plan_id' => 4])['lead_temperature']);
        $this->assertSame(C::READY, $this->profile('quiero pagar ya', SalesIntents::HIGH_INTENT_CLOSE)['lead_temperature']);
        $this->assertSame(C::READY, $this->profile('quiero ese', SalesIntents::GENERAL_INFO, ['type' => R::CHOOSE_PLAN, 'plan_id' => 4])['lead_temperature']);
    }

    public function test_a_decline_cools_everything_down(): void
    {
        $m = ConversationMemory::empty()->recommend([4], 'x')->deliverPrice(4, 'x', 1);
        $p = $this->profile('no gracias, ya no', SalesIntents::NOT_INTERESTED, ['type' => R::DECLINE_OFFER, 'plan_id' => 4], $m);

        $this->assertSame(C::COLD, $p['lead_temperature']);
        $this->assertSame('stalling', $p['conversation_momentum']);
    }

    // ── Ciclo de vida por hechos ──────────────────────────────────────────────

    public function test_lifecycle_follows_the_facts_not_the_model(): void
    {
        $this->assertSame(C::PROSPECT, $this->profile('hola', SalesIntents::GREETING)['customer_lifecycle']);
        $this->assertSame(C::INTERESTED, $this->profile('quiero ganar masa', SalesIntents::GOAL_MUSCLE_GAIN)['customer_lifecycle']);
        $this->assertSame(C::READY_TO_BUY, $this->profile('quiero pagar', SalesIntents::HIGH_INTENT_CLOSE)['customer_lifecycle']);
        $member = new Member(['full_name' => 'Socia']);
        $member->id = 1;
        $this->assertSame(C::PAYMENT_PENDING, $this->profile('hola', SalesIntents::GREETING, facts: ['member' => $member, 'hasPendingPaymentLink' => true, 'pendingPaymentPlanId' => 4])['customer_lifecycle']);
        $this->assertSame(C::ONBOARDING, $this->profile('hola', SalesIntents::GREETING, facts: ['member' => $member, 'hasActiveMembership' => true, 'daysAsMember' => 2, 'approvedPaymentsCount' => 1, 'currentPlanId' => 4, 'daysToExpiry' => 28])['customer_lifecycle']);
        $this->assertSame(C::ACTIVE_MEMBER, $this->profile('hola', SalesIntents::GREETING, facts: ['member' => $member, 'hasActiveMembership' => true, 'daysAsMember' => 40, 'approvedPaymentsCount' => 1, 'currentPlanId' => 4, 'daysToExpiry' => 20])['customer_lifecycle']);
        $this->assertSame(C::LAPSED, $this->profile('quiero volver', SalesIntents::GENERAL_INFO, facts: ['member' => $member, 'daysSinceExpiry' => 200, 'approvedPaymentsCount' => 3])['customer_lifecycle'], 'exsocio = recuperación, nunca PAID');
    }

    /** Hallazgo: «empezando» es la primera membresía, no los 7 días que siguen a cada cobro recurrente. */
    public function test_onboarding_does_not_restart_with_every_recurring_charge(): void
    {
        $member = new Member(['full_name' => 'Veterano']);
        $member->id = 1;
        $p = $this->profile('hola', SalesIntents::GREETING, facts: ['member' => $member, 'hasActiveMembership' => true, 'daysAsMember' => 3, 'approvedPaymentsCount' => 24, 'currentPlanId' => 4, 'daysToExpiry' => 27]);

        $this->assertSame(C::ACTIVE_MEMBER, $p['customer_lifecycle']);
    }

    /** Hallazgo: un pago que sólo coincide por teléfono es pista, no hecho ni ciclo de vida. */
    public function test_phone_only_payment_matches_are_inferred_never_known(): void
    {
        $p = $this->profile('ya pagué', SalesIntents::GENERAL_INFO, facts: ['approvedPaymentsCount' => 1, 'hasPendingPaymentLink' => true]);

        $this->assertNotSame(C::PAID, $p['customer_lifecycle']);
        $this->assertNotSame(C::PAYMENT_PENDING, $p['customer_lifecycle']);
        $this->assertSame(0, $p['known']['approved_payments_count']);
        $this->assertFalse($p['known']['has_pending_payment_link']);
        $this->assertSame('none', $p['known']['money_facts_source']);
        $this->assertSame(1, $p['inferred']['payment_hint']['approved_payments_by_phone']);
        $this->assertSame('unknown', $p['payment_state']);
    }

    /** La guardia se ancla al plan cotizado y falla en abierto cuando no se sabe. */
    public function test_reselling_is_detected_only_when_the_quoted_plan_is_known_to_be_the_same(): void
    {
        $svc = $this->service();
        $member = new Member(['full_name' => 'Socia']);
        $member->id = 1;

        $active = $this->profile('hola', SalesIntents::GREETING, facts: ['member' => $member, 'hasActiveMembership' => true, 'daysAsMember' => 40, 'approvedPaymentsCount' => 1, 'currentPlanId' => 4, 'daysToExpiry' => 20]);
        $this->assertTrue($svc->isResell($active, 4), 'mismo plan con 20 días por delante');
        $this->assertFalse($svc->isResell($active, 8), 'otro plan sí se puede proponer');
        $this->assertFalse($svc->isResell($active, null), 'sin plan cotizado no hay reventa que bloquear');

        $sinPlanConocido = $this->profile('hola', SalesIntents::GREETING, facts: ['member' => $member, 'hasActiveMembership' => true, 'daysAsMember' => 40, 'approvedPaymentsCount' => 1, 'currentPlanId' => null, 'daysToExpiry' => 20]);
        $this->assertFalse($svc->isResell($sinPlanConocido, 4), 'si no se sabe qué plan tiene, no se afirma que sea el mismo');

        $renewing = $this->profile('hola', SalesIntents::GREETING, facts: ['member' => $member, 'hasActiveMembership' => true, 'daysAsMember' => 40, 'approvedPaymentsCount' => 1, 'currentPlanId' => 4, 'daysToExpiry' => 3]);
        $this->assertTrue($renewing['renewal_window']);
        $this->assertFalse($svc->isResell($renewing, 4), 'renovar a 3 días del vencimiento es legítimo');

        $lapsed = $this->profile('quiero volver', SalesIntents::GENERAL_INFO, facts: ['member' => $member, 'daysSinceExpiry' => 200, 'approvedPaymentsCount' => 3, 'currentPlanId' => 4]);
        $this->assertFalse($svc->isResell($lapsed, 4), 'al exsocio se le puede vender el plan que tenía');
    }

    // ── KNOWN / INFERRED / UNKNOWN ───────────────────────────────────────────

    public function test_a_staff_objective_is_known_and_a_classifier_objective_is_only_inferred(): void
    {
        $this->conversation->forceFill(['detected_objective' => 'ganar masa'])->save();
        $p = $this->profile('hola', SalesIntents::GREETING);
        $this->assertNull($p['known']['objective']);
        $this->assertSame('ganar masa', $p['inferred']['objective']);
        $this->assertNotContains('objective', $p['unknown']);

        $this->lead->update(['objective' => 'bajar grasa']);
        $p = $this->profile('hola', SalesIntents::GREETING);
        $this->assertSame('bajar grasa', $p['known']['objective']);
        $this->assertNull($p['inferred']['objective'], 'lo sabido no se vuelve a inferir');
    }

    public function test_unknowns_are_listed_so_the_strategist_knows_what_it_may_ask(): void
    {
        $p = $this->profile('hola', SalesIntents::GREETING);
        foreach (['objective', 'experience_level', 'main_barrier', 'plan_interest', 'time_constraints'] as $k) {
            $this->assertContains($k, $p['unknown']);
        }
        $this->inbound('es mi primera vez en un gym');
        $p = $this->profile('quiero empezar', SalesIntents::GENERAL_INFO);
        $this->assertSame('beginner', $p['inferred']['experience_level']);
        $this->assertNotContains('experience_level', $p['unknown']);
    }

    public function test_the_profile_carries_no_identifiers(): void
    {
        $json = json_encode($this->profile('hola', SalesIntents::GREETING));
        foreach (['3150536026', '573150536026', 'phone', 'meta_user_id', 'document', 'email'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, $forbidden);
        }
    }

    /** Ni cifras: CommercialSubject conoce precio del plan y valor de vida, y no viajan. */
    public function test_the_profile_carries_no_money_figures(): void
    {
        $json = json_encode($this->profile('hola', SalesIntents::GREETING, facts: ['hasActiveMembership' => true, 'daysAsMember' => 40, 'currentPlanId' => 4, 'daysToExpiry' => 20, 'currentPlanPrice' => 80000.0, 'lifetimeValue' => 240000.0, 'approvedPaymentsCount' => 3]));
        foreach (['80000', '240000', 'plan_price', 'lifetime'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, $forbidden);
        }
    }
}
