<?php

namespace Tests\Feature\Marketing;

use App\Jobs\Wompi\ReconcileWompiTransaction;
use App\Models\ElectronicInvoice;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\WompiPaymentLinkService;
use App\Services\Wompi\PaymentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

        $this->plan = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'benefits' => json_encode(['Acceso ilimitado'])]);
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
    private function linkPendiente(?Plan $plan = null): PaymentTransaction
    {
        $plan ??= $this->plan;
        WompiPaymentLinkService::make()->generateForLead($this->lead, $plan, ['channel' => 'whatsapp', 'conversation_id' => $this->conversation->id]);

        return PaymentTransaction::query()
            ->where('idempotency_key', 'like', 'mkt-lead-'.$this->lead->id.'-plan-'.$plan->id.'-%')
            ->orderByDesc('id')->firstOrFail();
    }

    /** El socio al que la activación le extendería la membresía si llegara a correr. */
    private function socio(): User
    {
        return User::create(['name' => 'Socio Prueba', 'email' => 'socio-'.uniqid().'@example.com', 'password' => 'secret', 'status' => 'active']);
    }

    /** Wompi responde APPROVED a la consulta de estado (y solo a eso). */
    private function wompiResponde(string $wompiId, string $status): void
    {
        Http::preventStrayRequests();
        Http::fake(['*/transactions/*' => Http::response(['data' => [
            'id' => $wompiId, 'status' => $status, 'reference' => 'ref-'.$wompiId,
            'amount_in_cents' => 8000000, 'currency' => 'COP', 'payment_method_type' => 'NEQUI', 'finalized_at' => now()->toIso8601String(),
        ]], 200)]);
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

    /**
     * `decide` LEE. Pregunta a Wompi y le cuenta al modelo lo que Wompi dijo,
     * pero no mueve la fila: la transición —y con ella la membresía, el mensaje
     * al cliente y la factura electrónica— es del webhook y del cron. Si una
     * frase de un tercero pudiera dispararlas, bastaría con escribir «ya pagué»
     * y que n8n reintentara el turno.
     */
    public function test_a_payment_claim_with_an_open_wompi_transaction_is_read_live_without_touching_anything(): void
    {
        // La cola se finge A PROPÓSITO, y es parte de lo que se afirma: sellar la
        // fila es trabajo de la COLA (ReconcileWompiTransaction → reconcileOne),
        // no del turno de `decide`. En producción el carril es asíncrono; en la
        // suite la conexión es `sync`, así que sin esto el job correría dentro de
        // la petición y ya no se vería si `decide` escribe por su cuenta.
        Queue::fake();
        $socio = $this->socio();
        $tx = $this->linkPendiente();
        $tx->forceFill(['wompi_transaction_id' => '11111-1700000000-11111', 'user_id' => $socio->id])->save();
        $this->wompiResponde('11111-1700000000-11111', 'APPROVED');
        $antes = (string) $tx->fresh()->status;

        $p = $this->decide($this->inbound('ya pagué', 'ps.4'))->json('context.payment');

        Http::assertSentCount(1);
        $this->assertTrue($p['checked_live']);
        $this->assertSame('approved', $p['state'], 'lo que dice Wompi, no lo que dice la persona');

        $fresca = $tx->fresh();
        $this->assertSame($antes, (string) $fresca->status, 'la fila la sella el webhook, no una lectura');
        $this->assertNotSame(PaymentStateMachine::APPROVED, (string) $fresca->status);
        $this->assertNull($fresca->approved_at);
        $this->assertNull($fresca->paid_at);
        $this->assertSame(0, Payment::count(), 'ningún pago creado desde una lectura');
        $this->assertNull($socio->fresh()->membership_end_date, 'ninguna membresía extendida');
        $this->assertSame(0, Notification::count(), 'ningún aviso al cliente');
        $this->assertSame(0, ElectronicInvoice::count(), 'ninguna factura electrónica encolada');
    }

    /**
     * Freno por transacción: el throttle de la ruta lo comparte todo el workflow
     * de n8n, así que no dice nada sobre cuántas veces se consulta ESTA
     * transacción. Un turno reintentado no son dos consultas a Wompi.
     *
     * Pero el freno GUARDA la respuesta en vez de tirarla, y esa es la parte que
     * faltaba. Cuando solo frenaba, la segunda vez que la persona escribía «ya
     * pagué» dentro del mismo minuto el modelo pasaba de «tu pago está aprobado»
     * a «sigue pendiente» sobre el MISMO hecho: nada había cambiado salvo que no
     * se volvió a preguntar. Un agente que se contradice solo porque ahorró una
     * llamada HTTP es peor que uno que no consulta.
     */
    public function test_the_live_answer_is_reused_inside_the_window_instead_of_being_thrown_away(): void
    {
        Queue::fake();
        $tx = $this->linkPendiente();
        $tx->forceFill(['wompi_transaction_id' => '22222-1700000000-22222'])->save();
        $this->wompiResponde('22222-1700000000-22222', 'APPROVED');

        $primera = $this->decide($this->inbound('ya pagué', 'ps.8'))->json('context.payment');
        $segunda = $this->decide($this->inbound('ya pagué, en serio', 'ps.9'))->json('context.payment');

        Http::assertSentCount(1);
        $this->assertTrue($primera['checked_live']);
        $this->assertFalse($primera['live_cached'], 'la primera sí preguntó');
        $this->assertSame('approved', $primera['state']);
        $this->assertTrue($segunda['checked_live'], 'la segunda no pregunta, pero sabe lo mismo');
        $this->assertTrue($segunda['live_cached'], 'y dice de dónde lo sabe');
        $this->assertSame('approved', $segunda['state'], 'el mismo hecho no cambia de respuesta en 60 s');
    }

    /**
     * Wompi dio la transacción por terminada: la fila se entrega a la cola.
     *
     * `decide` sigue sin escribirla —lo escribe `reconcileOne`, que es el camino
     * auditado, con `retry_count` y `last_reconciled_at`—, pero ya no se espera a
     * la pasada del cron. Esa ventana de hasta cinco minutos con la fila en
     * `pending` es justo donde el cerrojo `already_paid` no protege y el mismo
     * lead puede recibir un segundo link por algo que ya pagó.
     */
    public function test_a_terminal_live_state_hands_the_row_to_the_audited_reconciliation(): void
    {
        Queue::fake();
        $tx = $this->linkPendiente();
        $tx->forceFill(['wompi_transaction_id' => '44444-1700000000-44444'])->save();
        $this->wompiResponde('44444-1700000000-44444', 'APPROVED');
        $antes = (string) $tx->fresh()->status;

        $p = $this->decide($this->inbound('ya pagué', 'ps.13'))->json('context.payment');

        $this->assertSame('approved', $p['state']);
        Queue::assertPushed(ReconcileWompiTransaction::class, 1);
        Queue::assertPushed(
            ReconcileWompiTransaction::class,
            fn (ReconcileWompiTransaction $job): bool => $job->transactionId === $tx->id,
        );
        $this->assertSame($antes, (string) $tx->fresh()->status, 'la fila la sigue sellando la cola, no la lectura');
    }

    /** Wompi sigue en vuelo: no hay nada que sellar y no se encola nada. */
    public function test_a_pending_live_state_hands_nothing_to_the_queue(): void
    {
        Queue::fake();
        $tx = $this->linkPendiente();
        $tx->forceFill(['wompi_transaction_id' => '55555-1700000000-55555'])->save();
        $this->wompiResponde('55555-1700000000-55555', 'PENDING');

        $p = $this->decide($this->inbound('ya pagué', 'ps.14'))->json('context.payment');

        $this->assertTrue($p['checked_live'], 'sí se preguntó');
        $this->assertSame('pending', $p['state']);
        Queue::assertNotPushed(ReconcileWompiTransaction::class);
    }

    /**
     * Un turno repetido dentro de la ventana no encola dos veces: el hecho es el
     * mismo y el job ya lo tiene quien lo procesa.
     */
    public function test_the_reused_answer_does_not_queue_the_reconciliation_again(): void
    {
        Queue::fake();
        $tx = $this->linkPendiente();
        $tx->forceFill(['wompi_transaction_id' => '66666-1700000000-66666'])->save();
        $this->wompiResponde('66666-1700000000-66666', 'APPROVED');

        $this->decide($this->inbound('ya pagué', 'ps.15'));
        $this->decide($this->inbound('ya pagué, de verdad', 'ps.16'));

        Http::assertSentCount(1);
        Queue::assertPushed(ReconcileWompiTransaction::class, 1);
    }

    /** Wompi caído no inventa un estado: vale el de la BD y el modelo sabe que no se consultó. */
    public function test_a_failed_live_query_falls_back_to_the_stored_state(): void
    {
        $tx = $this->linkPendiente();
        $tx->forceFill(['wompi_transaction_id' => '33333-1700000000-33333'])->save();
        Http::preventStrayRequests();
        Http::fake(['*/transactions/*' => Http::response(['error' => ['type' => 'NOT_FOUND_ERROR']], 404)]);
        $antes = (string) $tx->fresh()->status;

        $p = $this->decide($this->inbound('ya pagué', 'ps.12'))->json('context.payment');

        $this->assertFalse($p['checked_live']);
        $this->assertSame('pending', $p['state'], 'el estado de la BD, nunca una aprobación inventada');
        $this->assertSame($antes, (string) $tx->fresh()->status);
    }

    /**
     * Un link vencido DESPUÉS de un pago aprobado no borra el pago. Tomar «la
     * última por id» hacía que el modelo le dijera «no veo ningún pago» a quien
     * ya había pagado y solo había dejado morir un segundo link.
     */
    public function test_an_approved_payment_wins_over_a_later_expired_link(): void
    {
        $pagado = $this->linkPendiente();
        $pagado->forceFill(['status' => PaymentStateMachine::APPROVED])->save();

        $otroPlan = Plan::create(['name' => 'Plan Trimestral', 'price' => 210000, 'duration_days' => 90, 'active' => true, 'sellable' => true]);
        $this->linkPendiente($otroPlan)->forceFill(['status' => PaymentStateMachine::EXPIRED])->save();

        $p = $this->decide($this->inbound('y ahora qué sigue?', 'ps.10'))->json('context.payment');

        $this->assertSame('approved', $p['state'], 'el pago aprobado pesa más que un link muerto');
        $this->assertSame($this->plan->id, $p['plan_id'], 'y es el plan que se pagó');
    }

    /**
     * ORDEN 1 — aprobado VIEJO + intento nuevo, sin reclamo: manda el intento
     * vivo, que es lo que está pasando ahora mismo. Quien ya pagó y acaba de
     * pedir otro link pregunta por el link nuevo, no por el recibo de antes.
     */
    public function test_a_later_open_link_wins_over_an_older_approved_payment(): void
    {
        $this->linkPendiente()->forceFill(['status' => PaymentStateMachine::APPROVED])->save();

        $otroPlan = Plan::create(['name' => 'Plan Semestral', 'price' => 400000, 'duration_days' => 180, 'active' => true, 'sellable' => true]);
        $this->linkPendiente($otroPlan);

        $p = $this->decide($this->inbound('me pasas el link otra vez?', 'ps.11'))->json('context.payment');

        $this->assertSame('pending', $p['state']);
        $this->assertSame($otroPlan->id, $p['plan_id']);
    }

    /**
     * ORDEN 2 — intento VIEJO + pago aprobado nuevo, sin reclamo.
     *
     * Es el orden que no estaba cubierto y donde vivía el fallo: la elección
     * por categoría fija («primero el que esté en vuelo») hacía ganar al link
     * abandonado del plan A sobre el pago consumado del plan B, porque el
     * cerrojo `already_paid` es por lead+PLAN y nunca tocó la fila de A.
     */
    public function test_a_newer_approved_payment_wins_over_an_older_abandoned_link(): void
    {
        $abandonada = $this->linkPendiente();                       // plan A: pedida y nunca pagada.
        $comoEstaba = (string) $abandonada->status;

        $planB = Plan::create(['name' => 'Plan Semestral', 'price' => 400000, 'duration_days' => 180, 'active' => true, 'sellable' => true]);
        $this->linkPendiente($planB)->forceFill(['status' => PaymentStateMachine::APPROVED])->save();

        $p = $this->decide($this->inbound('y ahora qué sigue?', 'ps.12'))->json('context.payment');

        $this->assertSame('approved', $p['state'], 'un hecho consumado pesa más que un link abandonado');
        $this->assertSame($planB->id, $p['plan_id'], 'y es el plan que de verdad se pagó');
        $this->assertContains($comoEstaba, PaymentStateMachine::IN_FLIGHT, 'la fila vieja sigue siendo un intento en vuelo');
        $this->assertSame($comoEstaba, (string) $abandonada->fresh()->status, 'y esto LEE: no la mueve ni la cierra');
    }

    /**
     * El mismo orden 2, pero con la persona RECLAMANDO que pagó. Es la frase
     * con la que el fallo llegaba al cliente: se le contestaba `pending` con el
     * plan equivocado a quien acababa de pagar.
     */
    public function test_a_payment_claim_never_gets_answered_with_someone_elses_abandoned_link(): void
    {
        $this->linkPendiente();                                     // plan A en vuelo, sin pagar.

        $planB = Plan::create(['name' => 'Plan Semestral', 'price' => 400000, 'duration_days' => 180, 'active' => true, 'sellable' => true]);
        $this->linkPendiente($planB)->forceFill(['status' => PaymentStateMachine::APPROVED])->save();

        Http::preventStrayRequests();
        Http::fake();                                               // si consultara Wompi, sería por la fila equivocada.

        $p = $this->decide($this->inbound('ya pagué', 'ps.13'))->json('context.payment');

        $this->assertTrue($p['claimed_by_lead']);
        $this->assertSame('approved', $p['state'], 'a quien pagó no se le dice que no consta');
        $this->assertSame($planB->id, $p['plan_id']);
        Http::assertNothingSent();
    }

    /**
     * Y el orden 1 con reclamo: aquí el criterio se invierte a propósito.
     *
     * Sin reclamo manda el intento nuevo; en cuanto la persona dice que pagó,
     * gana el pago aprobado aunque sea más viejo. Un intento en vuelo puede
     * quedarse en vuelo para siempre; un `approved` es un hecho del que no se
     * sale. Este par de pruebas es el que fija esa asimetría: si alguien la
     * quita para «simplificar», una de las dos cae.
     */
    public function test_the_claim_tips_the_choice_towards_the_payment_that_already_happened(): void
    {
        $this->linkPendiente()->forceFill(['status' => PaymentStateMachine::APPROVED])->save();

        $planB = Plan::create(['name' => 'Plan Semestral', 'price' => 400000, 'duration_days' => 180, 'active' => true, 'sellable' => true]);
        $this->linkPendiente($planB);                               // intento nuevo, sin pagar.

        Http::preventStrayRequests();
        Http::fake();

        $p = $this->decide($this->inbound('ya pagué, ahí está el comprobante', 'ps.14'))->json('context.payment');

        $this->assertSame('approved', $p['state']);
        $this->assertSame($this->plan->id, $p['plan_id'], 'el plan que consta pagado, no el intento abierto');
        $this->assertFalse($p['can_regenerate'], 'no se le ofrece regenerar nada a quien ya pagó');
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

    /**
     * El job SÍ escribe, y por el camino del cron: `reconcileOne`. Es la otra
     * mitad del reparto —la lectura informa, la cola sella— y sin esta prueba
     * «se despachó el job» solo diría que se encoló algo.
     */
    public function test_the_queued_job_seals_the_row_through_the_audited_path(): void
    {
        $tx = $this->linkPendiente();
        $tx->forceFill(['wompi_transaction_id' => '77777-1700000000-77777'])->save();
        $this->wompiResponde('77777-1700000000-77777', 'APPROVED');

        (new ReconcileWompiTransaction($tx->id))->handle();

        $fresca = $tx->fresh();
        $this->assertSame(PaymentStateMachine::APPROVED, (string) $fresca->status);
        $this->assertSame(1, (int) $fresca->retry_count, 'la pasada queda contada, como la del cron');
        $this->assertNotNull($fresca->last_reconciled_at);
    }

    /** Si el webhook llegó primero, el job no tiene nada que hacer y no pregunta. */
    public function test_the_queued_job_does_nothing_when_the_row_is_already_sealed(): void
    {
        $tx = $this->linkPendiente();
        $tx->forceFill(['wompi_transaction_id' => '88888-1700000000-88888', 'status' => PaymentStateMachine::APPROVED])->save();
        Http::preventStrayRequests();
        Http::fake();

        (new ReconcileWompiTransaction($tx->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(0, (int) $tx->fresh()->retry_count);
    }

    /** Una fila que ya no está no puede tumbar al worker. */
    public function test_the_queued_job_survives_a_transaction_that_no_longer_exists(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        (new ReconcileWompiTransaction(999999))->handle();

        Http::assertNothingSent();
        $this->assertSame(0, PaymentTransaction::query()->whereKey(999999)->count());
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
