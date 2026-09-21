<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
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
use App\Services\Marketing\Ultron\ReferenceResolver as R;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** El perfil del cliente por el recorrido real, y la guardia de reventa en commit. */
class UltronCustomerIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private Plan $plan;

    private Plan $otro;

    private MarketingLead $lead;

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

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $this->otro = Plan::create(['name' => 'Trimestre', 'price' => 200000, 'duration_days' => 90, 'active' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::RECOMMENDATION]);
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

    private function commit(MarketingMessage $m, array $proposal): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $this->decide($m)->json('decide_token'),
            'proposal' => array_merge(['strategy_goal' => 'close_plan', 'next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te cuento.', 'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'recommend_plan',
                'confidence' => 0.8, 'tools_requested' => []], $proposal),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $this->h());
    }

    public function test_decide_exposes_the_customer_profile(): void
    {
        $d = $this->decide($this->inbound('hola quiero informacion', 'c.1'))->assertOk();

        $this->assertSame(C::PROSPECT, $d->json('context.customer.customer_lifecycle'));
        $this->assertSame(C::COLD, $d->json('context.customer.lead_temperature'));
        $this->assertIsArray($d->json('context.customer.known'));
        $this->assertIsArray($d->json('context.customer.inferred'));
        $this->assertContains('objective', $d->json('context.customer.unknown'));
        $this->assertStringNotContainsString('3150536026', json_encode($d->json('context.customer')));
    }

    /** Hot lead fast path: «quiero el mensual, mándame el link» no vuelve a discovery. */
    public function test_a_ready_buyer_is_read_as_ready(): void
    {
        $d = $this->decide($this->inbound('quiero el mensual, mándame el link', 'c.2'))->assertOk();

        $this->assertSame(R::SEND_IT, $d->json('context.resolved_reference.type'));
        $this->assertSame($this->plan->id, $d->json('context.resolved_reference.plan_id'));
        $this->assertSame(C::READY, $d->json('context.customer.lead_temperature'));
        $this->assertSame(C::READY_TO_BUY, $d->json('context.customer.customer_lifecycle'));
        $this->assertContains('reference:send_it', $d->json('context.customer.temperature_signals'));
    }

    // ── Sujetos REALES (CommercialSubject::build de verdad, sin sustituir) ─────

    /** Socia de pago único: users.plan es un NOMBRE y no hay fila en membership_subscriptions. */
    private function sociaReal(int $startedDaysAgo, int $endsInDays, ?Plan $plan = null): Member
    {
        $plan ??= $this->plan;
        $user = User::create([
            'name' => 'Socia', 'email' => 'socia-'.uniqid().'@ironbody.test', 'password' => bcrypt('secret-password'),
            'document' => '10'.random_int(10000000, 99999999), 'status' => 'active', 'plan' => $plan->name,
            'membership_start_date' => now()->subDays($startedDaysAgo)->toDateString(),
            'membership_end_date' => now()->addDays($endsInDays)->toDateString(),
        ]);
        $member = Member::create(['user_id' => $user->id, 'full_name' => 'Socia', 'email' => $user->email, 'document_number' => $user->document, 'phone' => '3150536026', 'status' => Member::STATUS_ACTIVE]);
        $this->lead->forceFill(['member_id' => $member->id])->save();

        return $member;
    }

    private function pagoAprobado(?Member $member, Plan $plan, int $daysAgo): void
    {
        $t = PaymentTransaction::create([
            'reference' => 'REF-'.uniqid(), 'idempotency_key' => 'idem-'.uniqid(), 'status' => 'APPROVED', 'amount' => $plan->price, 'currency' => 'COP',
            'provider' => 'wompi', 'plan_id' => $plan->id, 'member_id' => $member?->id, 'customer_phone' => '3150536026',
        ]);
        // created_at no es fillable: se fecha después, como haría el tiempo.
        PaymentTransaction::whereKey($t->id)->update(['created_at' => now()->subDays($daysAgo), 'approved_at' => now()->subDays($daysAgo)]);
    }

    /** Hallazgo bloqueante 1: el plan actual sale del NOMBRE en users.plan; otro plan sí se cierra. */
    public function test_a_real_one_time_member_can_be_sold_another_plan_but_not_her_own(): void
    {
        $this->sociaReal(40, 20);
        $d = $this->decide($this->inbound('y el trimestre?', 'r.1'))->assertOk();
        $this->assertSame(C::ACTIVE_MEMBER, $d->json('context.customer.customer_lifecycle'));
        $this->assertSame($this->plan->id, $d->json('context.customer.known.current_plan_id'), 'resuelto desde users.plan');
        $this->assertSame('identity', $d->json('context.customer.known.money_facts_source'));

        $this->commit($this->inbound('quiero pasarme al trimestre', 'r.2'), ['next_state' => P::CLOSING, 'intent' => SalesIntents::HIGH_INTENT_CLOSE, 'recommended_plan_id' => $this->otro->id,
            'reply_draft' => 'El {{PLAN_NAME}} sale en {{PLAN_PRICE}} y te da más tiempo. El equipo confirma el medio de pago.'])->assertOk()->assertJsonPath('outcome', 'dry_run');

        // El mismo plan, con 20 días por delante: el texto no sale, la fase no se mueve, queda rastro.
        $faseAntes = $this->conversation->fresh()->commercial_phase;
        $r = $this->commit($this->inbound('y cómo pago el mensual otra vez?', 'r.3'), ['next_state' => P::CLOSING, 'intent' => SalesIntents::HIGH_INTENT_CLOSE, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Perfecto, el {{PLAN_NAME}} por {{PLAN_PRICE}}: vienes con tu documento y el equipo confirma el medio de pago.'])->assertOk();
        // No se le vuelve a cotizar el plan que ya tiene, pero se le contesta:
        // callarse ante una socia que escribe nunca fue parte del trato.
        $this->assertSame(2, MarketingMessage::where('direction', 'outbound')->count(), 'la socia se quedó sin respuesta');
        $ultimo = mb_strtolower((string) MarketingMessage::where('direction', 'outbound')->latest('id')->first()?->body);
        $this->assertStringNotContainsString('documento', $ultimo, 'salió la cotización bloqueada');
        $this->assertDoesNotMatchRegularExpression('/\$\s*\d/', $ultimo, 'salió una cifra');
        $this->assertSame($faseAntes, $this->conversation->fresh()->commercial_phase, 'la fase no se mueve sobre un texto que no salió');
        // El turno se ejecuta: salió un texto, aunque no fuera el del modelo.
        $this->assertSame('executed', MarketingAiAction::latest('id')->first()->status);
    }

    /** Hallazgo medio: la guardia ya no mata el opt-out del turno. */
    public function test_a_blocked_resell_still_runs_the_tools_of_the_turn(): void
    {
        $this->sociaReal(40, 20);
        $this->commit($this->inbound('no me escriban más, luego veo si pago el mensual', 'r.4'), ['next_state' => P::CLOSING, 'intent' => SalesIntents::DO_NOT_CONTACT_REQUEST,
            'recommended_plan_id' => $this->plan->id, 'tools_requested' => [SalesIntents::TOOL_MARK_DNC],
            'reply_draft' => 'Listo. Cuando quieras retomar el {{PLAN_NAME}} por {{PLAN_PRICE}}, aquí estamos.'])->assertOk();

        $this->assertTrue((bool) $this->lead->fresh()->do_not_contact);
        $this->assertSame(0, MarketingMessage::where('direction', 'outbound')->count());
    }

    /** Hallazgo medio: la etiqueta de fase no decide si aplica la guardia; el plan cotizado sí. */
    public function test_the_guard_is_anchored_to_the_quoted_plan_not_to_next_state(): void
    {
        $this->sociaReal(40, 20);
        $r = $this->commit($this->inbound('recuérdame cuánto es el mensual', 'r.5'), ['next_state' => P::BUYING_SIGNAL, 'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Claro: el {{PLAN_NAME}} vale {{PLAN_PRICE}}. ¿Lo dejamos listo?'])->assertOk();

        // Lo que importa es que no salga la cifra del plan que ya tiene; que
        // además se le conteste es el arreglo del turno mudo.
        $this->assertDoesNotMatchRegularExpression(
            '/\$\s*\d/',
            (string) MarketingMessage::where('direction', 'outbound')->latest('id')->first()?->body,
            'se volvió a cotizar el plan que ya tiene',
        );
    }

    /** Hallazgo bloqueante 2: un pago aprobado de hace un año no convierte a nadie en PAID. */
    public function test_an_old_payment_matched_only_by_phone_does_not_block_a_returning_prospect(): void
    {
        $this->pagoAprobado(null, $this->plan, 365);

        $d = $this->decide($this->inbound('hola, quiero volver al gym', 'r.6'))->assertOk();
        $this->assertNotSame(C::PAID, $d->json('context.customer.customer_lifecycle'));
        $this->assertSame('none', $d->json('context.customer.known.money_facts_source'));
        $this->assertSame(1, $d->json('context.customer.inferred.payment_hint.approved_payments_by_phone'));

        $this->commit($this->inbound('quiero el mensual otra vez, cómo hago?', 'r.7'), ['next_state' => P::CLOSING, 'intent' => SalesIntents::HIGH_INTENT_CLOSE, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Perfecto: el {{PLAN_NAME}} vale {{PLAN_PRICE}}, te esperamos y el equipo confirma el medio de pago.'])->assertOk()->assertJsonPath('outcome', 'dry_run');
    }

    /** Exsocia real con membresía vencida: LAPSED, y se le puede vender su plan de nuevo. */
    public function test_a_lapsed_member_is_a_winback_prospect(): void
    {
        $member = $this->sociaReal(400, -200);
        $this->pagoAprobado($member, $this->plan, 400);

        $d = $this->decide($this->inbound('quiero volver', 'r.8'))->assertOk();
        $this->assertSame(C::LAPSED, $d->json('context.customer.customer_lifecycle'));

        $this->commit($this->inbound('el mensual otra vez', 'r.9'), ['next_state' => P::CLOSING, 'intent' => SalesIntents::HIGH_INTENT_CLOSE, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'De una: el {{PLAN_NAME}} vale {{PLAN_PRICE}}. El equipo confirma el medio de pago.'])->assertOk()->assertJsonPath('outcome', 'dry_run');
    }

    /** PAID de verdad: pagó hace un día y la membresía aún no está activa. Ese plan no se le vuelve a cotizar; otro sí. */
    public function test_a_freshly_paid_customer_is_not_quoted_the_same_plan_again(): void
    {
        $user = User::create(['name' => 'Recién', 'email' => 'recien@ironbody.test', 'password' => bcrypt('x'), 'document' => '1099999999', 'status' => 'active', 'plan' => null]);
        $member = Member::create(['user_id' => $user->id, 'full_name' => 'Recién', 'email' => $user->email, 'document_number' => '1099999999', 'phone' => '3150536026', 'status' => Member::STATUS_ACTIVE]);
        $this->lead->forceFill(['member_id' => $member->id])->save();
        $this->pagoAprobado($member, $this->plan, 1);

        $d = $this->decide($this->inbound('ya pagué, y ahora?', 'r.10'))->assertOk();
        $this->assertSame(C::PAID, $d->json('context.customer.customer_lifecycle'));
        $this->assertSame($this->plan->id, $d->json('context.customer.known.paid_plan_id'));
        $this->assertSame('approved', $d->json('context.customer.payment_state'));

        $r = $this->commit($this->inbound('y el mensual cuánto era?', 'r.11'), ['next_state' => P::CLOSING, 'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'El {{PLAN_NAME}} vale {{PLAN_PRICE}}. ¿Lo dejamos listo?'])->assertOk();
        // Lo que importa es que no salga la cifra del plan que ya tiene; que
        // además se le conteste es el arreglo del turno mudo.
        $this->assertDoesNotMatchRegularExpression(
            '/\$\s*\d/',
            (string) MarketingMessage::where('direction', 'outbound')->latest('id')->first()?->body,
            'se volvió a cotizar el plan que ya tiene',
        );

        $this->commit($this->inbound('y si quiero el trimestre?', 'r.12'), ['next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO, 'recommended_plan_id' => $this->otro->id,
            'reply_draft' => 'El {{PLAN_NAME}} sale en {{PLAN_PRICE}}; cuando actives el tuyo lo miramos con calma.'])->assertOk()->assertJsonPath('outcome', 'dry_run');
    }

    /** Renovar a punto de vencer sigue permitido (sujeto real). */
    public function test_renewing_near_expiry_is_allowed(): void
    {
        $this->sociaReal(27, 3);
        $this->commit($this->inbound('quiero renovar el mensual', 'r.13'), ['next_state' => P::CLOSING, 'intent' => SalesIntents::HIGH_INTENT_CLOSE, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Listo: renuevas el {{PLAN_NAME}} por {{PLAN_PRICE}} y sigues sin cortes. El equipo confirma el medio de pago al final.'])->assertOk()->assertJsonPath('outcome', 'dry_run');
    }
}
