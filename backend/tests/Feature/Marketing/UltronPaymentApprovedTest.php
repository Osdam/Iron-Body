<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Marketing\ApprovedPaymentClaimer;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\WompiPaymentLinkService;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiTransactionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Del pago a la membresía y al inicio, sin vender dos veces. Cuando Wompi
 * confirma un pago de un link del CRM: el lead queda convertido y la fase en
 * WON, la memoria lo sabe, y Laravel manda el mensaje de inicio. Si la persona
 * ya es socia con usuario, la membresía se activa de verdad; si no lo es, nadie
 * inventa una ficha: se le explica cómo registrarse en la app y una persona lo
 * mira. Al registrarse con el mismo número, el pago aprobado se reclama y la
 * membresía se activa. Un pago rechazado o vencido no arranca nada.
 */
class UltronPaymentApprovedTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('wompi.env', 'production');
        config()->set('wompi.public_key', 'pub_prod_test_key');
        config()->set('wompi.private_key', 'prv_prod_test_key');
        config()->set('wompi.integrity_secret', 'integrity_test_secret');
        config()->set('wompi.events_secret', 'events_test_secret');
        config()->set('wompi.checkout.base_url', 'https://checkout.wompi.co/p/');
        Http::fake();

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_HOT]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::CLOSING]);
    }

    private function link(): PaymentTransaction
    {
        WompiPaymentLinkService::make()->generateForLead($this->lead, $this->plan, ['channel' => 'whatsapp', 'conversation_id' => $this->conversation->id]);

        return PaymentTransaction::query()->where('idempotency_key', 'like', 'mkt-lead-'.$this->lead->id.'-plan-%')->firstOrFail();
    }

    /** Lo que hace el webhook firmado (o la reconciliación) al confirmar Wompi. */
    private function wompiSays(PaymentTransaction $tx, string $status): PaymentTransaction
    {
        return WompiTransactionService::make()->applyWompiTransaction($tx, [
            'id' => 'w-'.$tx->id, 'status' => $status, 'reference' => $tx->reference, 'amount_in_cents' => 8000000, 'currency' => 'COP',
            'payment_method_type' => 'NEQUI', 'payment_method' => ['type' => 'NEQUI'], 'finalized_at' => now()->toIso8601String(),
        ]);
    }

    private function onboarding(): Collection
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->where('sender_type', MarketingMessage::SENDER_AI)->orderBy('id')->get();
    }

    public function test_an_approved_payment_converts_the_lead_and_starts_onboarding_by_whatsapp(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');

        $this->assertSame(MarketingLead::STATUS_CONVERTED, $this->lead->fresh()->status);
        $this->assertNotNull($this->lead->fresh()->converted_at);
        $this->assertSame(P::WON, $this->conversation->fresh()->commercial_phase);
        $this->assertSame('approved', $this->conversation->fresh()->memory['payment_context']['status'] ?? null);

        $msgs = $this->onboarding();
        $this->assertCount(1, $msgs, 'un mensaje de inicio, de Laravel');
        $this->assertStringContainsString('Plan Mensual', $msgs->first()->body);
        $this->assertStringContainsString('app', mb_strtolower($msgs->first()->body), 'el siguiente paso real: la app');
        $this->assertStringNotContainsString('checkout.wompi.co', $msgs->first()->body);
    }

    public function test_a_prospect_without_member_is_not_invented_a_membership_and_a_person_is_told(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');

        $this->assertSame(0, Payment::count(), 'sin usuario al que asociar no hay pago contable ni membresía');
        $this->assertSame(0, Member::count(), 'nadie inventa una ficha de socio sin documento');
        $c = $this->conversation->fresh();
        $this->assertTrue((bool) $c->staff_review_pending);
        $this->assertSame('paid_without_member', $c->staff_review_reason);
        $this->assertStringContainsString('regístrate', mb_strtolower($this->onboarding()->first()->body), 'se le explica cómo activar su acceso');
    }

    public function test_an_existing_member_with_a_user_gets_the_membership_activated_for_real(): void
    {
        $user = User::create(['name' => 'Socia', 'email' => 'socia@x.co', 'password' => bcrypt('x')]);
        $member = Member::create(['full_name' => 'Socia Prueba', 'document_number' => '12345678', 'phone' => '3150536026', 'email' => 'socia@x.co', 'status' => Member::STATUS_ACTIVE, 'user_id' => $user->id]);
        $this->lead->forceFill(['member_id' => $member->id])->save();

        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');

        $this->assertTrue(Payment::where('reference', $tx->reference)->exists(), 'el pago contable existe');
        $this->assertNotNull($user->fresh()->membership_end_date, 'la membresía se extendió');
        $this->assertFalse((bool) $this->conversation->fresh()->staff_review_pending, 'nada que revisar: quedó activo');
        $this->assertStringContainsString('activ', mb_strtolower($this->onboarding()->first()->body));
    }

    public function test_registering_in_the_app_with_the_same_phone_claims_the_approved_payment(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');
        $this->assertNull($tx->fresh()->member_id);

        $user = User::create(['name' => 'Nuevo', 'email' => 'nuevo@x.co', 'password' => bcrypt('x')]);
        $member = Member::create(['full_name' => 'Nuevo Socio', 'document_number' => '87654321', 'phone' => '3150536026', 'email' => 'nuevo@x.co', 'status' => Member::STATUS_PENDING_REGISTRATION, 'user_id' => $user->id]);

        $claimed = app(ApprovedPaymentClaimer::class)->claimFor($member);

        $this->assertSame(1, $claimed);
        $this->assertSame($member->id, (int) $tx->fresh()->member_id);
        $this->assertSame($user->id, (int) $tx->fresh()->user_id);
        $this->assertTrue(Payment::where('reference', $tx->reference)->exists(), 'ahora sí hay pago contable y membresía');
        $this->assertNotNull($user->fresh()->membership_end_date);
        $this->assertSame($member->id, (int) $this->lead->fresh()->member_id, 'el lead queda enlazado a su ficha');
        $this->assertSame(0, app(ApprovedPaymentClaimer::class)->claimFor($member), 'reclamar dos veces no cobra dos veces');
    }

    public function test_another_phone_never_claims_someone_elses_payment(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');
        $user = User::create(['name' => 'Otra', 'email' => 'otra@x.co', 'password' => bcrypt('x')]);
        $member = Member::create(['full_name' => 'Otra Persona', 'document_number' => '11112222', 'phone' => '3009998877', 'email' => 'otra@x.co', 'status' => Member::STATUS_PENDING_REGISTRATION, 'user_id' => $user->id]);

        $this->assertSame(0, app(ApprovedPaymentClaimer::class)->claimFor($member));
        $this->assertNull($tx->fresh()->member_id);
    }

    public function test_a_declined_or_expired_payment_starts_nothing(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'DECLINED');

        $this->assertSame(MarketingLead::STATUS_HOT, $this->lead->fresh()->status);
        $this->assertSame(P::CLOSING, $this->conversation->fresh()->commercial_phase);
        $this->assertSame('declined', $this->conversation->fresh()->memory['payment_context']['status'] ?? null, 'la memoria sí lo sabe');
        $this->assertCount(0, $this->onboarding());
    }

    public function test_the_same_approval_delivered_twice_onboards_once(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');
        $this->wompiSays($tx->fresh(), 'APPROVED');

        $this->assertCount(1, $this->onboarding());
        $this->assertSame(PaymentStateMachine::APPROVED, $tx->fresh()->status);
    }
}
