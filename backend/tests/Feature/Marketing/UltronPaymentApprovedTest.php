<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingConversationNote;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Member;
use App\Models\MembershipSubscription;
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
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Del pago a la membresía y al inicio, sin vender dos veces. Cuando Wompi
 * confirma un pago de un link del CRM: el lead queda convertido y la fase en
 * WON, la memoria lo sabe, y Laravel manda el mensaje de inicio. Si la persona
 * ya es socia con usuario, la membresía se activa de verdad; si no lo es, nadie
 * inventa una ficha: se le explica cómo registrarse en la app y una persona lo
 * mira.
 *
 * Registrarse en la app con el mismo número NO reclama el pago: el backend no
 * puede atestiguar que quien se registra posee ese teléfono (no hay OTP en el
 * registro), así que el número es una PISTA, no una prueba. El registro deja el
 * caso propuesto —alerta interna con los ids y aviso al lead por WhatsApp— y el
 * enlace lo decide una persona. Un pago rechazado o vencido no arranca nada.
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

    /** Alguien se registra en la app (endpoint real, con su validación real). */
    private function registerInApp(array $override = []): TestResponse
    {
        return $this->postJson('/api/members/register', array_merge([
            'full_name' => 'Nuevo Socio',
            'email' => 'nuevo@x.co',
            'document_number' => '87654321',
            'phone' => '3150536026',
            'gender' => 'Masculino',
        ], $override));
    }

    /** Los avisos de reclamo que salieron por WhatsApp (dry_run en la suite). */
    private function claimNotices(): Collection
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)
            ->orderBy('id')
            ->get()
            ->filter(fn (MarketingMessage $m): bool => ($m->metadata['kind'] ?? null) === 'claim_notice')
            ->values();
    }

    private function notes(): Collection
    {
        return MarketingConversationNote::where('conversation_id', $this->conversation->id)->orderBy('id')->get();
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

    /**
     * El mensaje de inicio no puede prometer lo que el backend no hace: el
     * registro NO enlaza el pago solo. Promete lo que sí ocurre: el equipo lo
     * enlaza y avisa.
     */
    public function test_the_onboarding_message_does_not_promise_an_automatic_link(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');

        $body = mb_strtolower($this->onboarding()->first()->body);
        $this->assertStringContainsString('el equipo enlaza tu pago', $body);
        $this->assertStringContainsString('te avisa cuando tu membresía quede activa', $body);
        $this->assertStringNotContainsString('tu pago queda enlazado', $body, 'ya no se promete el enlace automático');
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

    /**
     * ALTO-1: registrarse con el número de quien pagó NO se lleva la membresía.
     * El número coincide, así que el caso se PROPONE (alerta interna con los
     * ids y aviso al lead), pero la transacción sigue sin dueño y no hay
     * membresía activada.
     */
    public function test_registering_with_the_same_phone_never_takes_the_payment_it_only_proposes_it(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');

        $this->registerInApp()->assertCreated();

        $member = Member::where('document_number', '87654321')->firstOrFail();
        $tx = $tx->fresh();
        $this->assertNull($tx->member_id, 'el pago NO se enlaza a quien se registra');
        $this->assertNull($tx->user_id);
        $this->assertNull($this->lead->fresh()->member_id, 'el lead tampoco se enlaza a esa ficha');
        $this->assertSame(0, Payment::count(), 'ninguna membresía se activa sin que una persona lo apruebe');
        $this->assertSame(0, MembershipSubscription::count());
        $this->assertNull($member->user->fresh()->membership_end_date);

        $c = $this->conversation->fresh();
        $this->assertTrue((bool) $c->staff_review_pending);
        $this->assertSame('payment_claim_candidate', $c->staff_review_reason);

        $note = $this->notes()->last();
        $this->assertNotNull($note, 'la alerta lleva los ids para que el equipo pueda verificarlo');
        $this->assertSame(
            '[payment_claim_candidate] '.json_encode(['member_id' => $member->id, 'transaction_ids' => [$tx->id], 'matched_by' => 'phone']),
            $note->body
        );

        $notices = $this->claimNotices();
        $this->assertCount(1, $notices, 'un solo aviso al lead');
        $this->assertSame(ApprovedPaymentClaimer::NOTICE, $notices->first()->body);
        $this->assertSame('system', $notices->first()->metadata['origin'] ?? null);
    }

    /** Un segundo registro con el mismo número dentro de 24 h no vuelve a avisar. */
    public function test_a_second_matching_registration_within_a_day_does_not_send_a_second_notice(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');

        $this->registerInApp()->assertCreated();
        $this->registerInApp(['document_number' => '11223344', 'email' => 'otro@x.co', 'full_name' => 'Otro Nuevo'])->assertCreated();

        $this->assertCount(1, $this->claimNotices(), 'máximo un aviso por lead cada 24 h');
        $this->assertCount(2, $this->notes(), 'dos socios DISTINTOS reclamando el mismo número: el equipo debe ver a los dos');
        $this->assertNull($tx->fresh()->member_id);
    }

    /** El registro no tiene throttle: el mismo socio repitiendo la llamada no apila notas ni avisos. */
    public function test_the_same_member_repeating_the_registration_does_not_pile_up_alerts(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');

        $this->registerInApp()->assertCreated();
        $this->registerInApp()->assertOk()->assertJsonPath('status', 'resumed');
        $this->registerInApp()->assertOk()->assertJsonPath('status', 'resumed');

        $this->assertCount(1, $this->claimNotices(), 'un aviso');
        $this->assertCount(1, $this->notes(), 'una sola alerta interna para el mismo socio dentro de 24 h');
        $this->assertNull($tx->fresh()->member_id);

        $this->travel(25)->hours();
        $this->registerInApp()->assertOk();
        $this->assertCount(2, $this->notes(), 'pasadas 24 h, un nuevo intento vuelve a alertar');
    }

    public function test_another_phone_is_not_even_a_candidate(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');

        $this->registerInApp(['phone' => '3009998877'])->assertCreated();

        $this->assertNull($tx->fresh()->member_id);
        $this->assertCount(0, $this->claimNotices());
        $this->assertCount(0, $this->notes());
        $this->assertSame('paid_without_member', $this->conversation->fresh()->staff_review_reason, 'nadie tocó la alerta del pago sin socio');
    }

    /** Retomar un registro incompleto es el mismo camino: también propone. */
    public function test_resuming_an_incomplete_registration_also_proposes_the_claim(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');

        $existing = Member::create(['full_name' => 'Nuevo Socio', 'document_number' => '87654321', 'phone' => '3150536026', 'status' => Member::STATUS_PENDING_REGISTRATION]);

        $this->registerInApp()->assertOk()->assertJsonPath('status', 'resumed')->assertJsonPath('member_id', $existing->id);

        $this->assertNull($tx->fresh()->member_id);
        $this->assertSame('payment_claim_candidate', $this->conversation->fresh()->staff_review_reason);
        $this->assertCount(1, $this->claimNotices());
    }

    /** Un pago de hace más de 90 días ya no es candidato de un registro nuevo. */
    public function test_an_old_approved_payment_is_out_of_the_claim_window(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');
        PaymentTransaction::query()->whereKey($tx->id)->update(['created_at' => now()->subDays(100)]);

        $this->registerInApp()->assertCreated();

        $this->assertCount(0, $this->claimNotices());
        $this->assertCount(0, $this->notes());
        $this->assertSame('paid_without_member', $this->conversation->fresh()->staff_review_reason);
    }

    /**
     * El tope de candidatos acota los pagos QUE COINCIDEN con este número, no
     * el barrido de la tabla. Los pagos sin socio se acumulan —desde que el
     * enlace lo decide una persona, se quedan ahí hasta que alguien los mire—,
     * así que si el tope se aplicara antes de mirar el teléfono, cualquier
     * pago posterior de otra persona taparía al de quien se registra ahora y
     * el equipo no se enteraría nunca.
     */
    public function test_other_peoples_orphan_payments_do_not_hide_the_matching_one(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');

        // Otras seis personas pagan por WhatsApp sin tener ficha, después.
        foreach (range(1, 6) as $i) {
            $otro = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '30011122'.$i.'0', 'meta_user_id' => '5730011122'.$i.'0', 'name' => 'Otro '.$i, 'status' => MarketingLead::STATUS_HOT]);
            MarketingConversation::create(['lead_id' => $otro->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::CLOSING]);
            WompiPaymentLinkService::make()->generateForLead($otro, $this->plan, ['channel' => 'whatsapp']);
            $this->wompiSays(PaymentTransaction::query()->where('idempotency_key', 'like', 'mkt-lead-'.$otro->id.'-plan-%')->firstOrFail(), 'APPROVED');
        }

        $this->registerInApp()->assertCreated();

        $member = Member::where('document_number', '87654321')->firstOrFail();
        $this->assertSame('payment_claim_candidate', $this->conversation->fresh()->staff_review_reason);
        $this->assertCount(1, $this->claimNotices());
        $this->assertSame(
            '[payment_claim_candidate] '.json_encode(['member_id' => $member->id, 'transaction_ids' => [$tx->id], 'matched_by' => 'phone']),
            $this->notes()->last()->body,
            'la alerta apunta al pago de ESTE número, no a los de los demás'
        );
        $this->assertNull($tx->fresh()->member_id);
    }

    /** Proponer es accesorio: si se cae, el alta se completa igual. */
    public function test_a_broken_proposer_never_breaks_the_registration(): void
    {
        $tx = $this->link();
        $this->wompiSays($tx, 'APPROVED');

        // Inyección de fallo (no un doble de comportamiento): resolver el
        // proponente revienta y el registro tiene que responder 201 igual.
        $this->app->bind(ApprovedPaymentClaimer::class, fn () => throw new RuntimeException('proponente caído'));

        $this->registerInApp()->assertCreated();

        $this->assertTrue(Member::where('document_number', '87654321')->exists(), 'el socio quedó creado');
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
