<?php

namespace Tests\Feature\Commercial;

use App\Jobs\Commercial\EvaluateCommercialSubject;
use App\Models\CommercialEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commercial\CommercialVocabulary as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Que los hechos reales del gimnasio lleguen al motor.
 *
 * Lo que se prueba aquí no es que el motor decida bien —eso vive en
 * NextBestActionTest— sino algo previo y más aburrido: que cuando un pago se
 * aprueba de verdad, alguien se entera. Sin este cableado el motor es una
 * maquinaria perfecta a la que nadie le cuenta nada.
 *
 * El caso que más importa es el de la idempotencia. La reconciliación de Wompi
 * revisa los pagos en vuelo cada cinco minutos y vuelve a escribir la misma
 * fila; si cada pasada produjera un hecho nuevo, el motor recalcularía y la
 * persona recibiría un mensaje por cada visita del reconciliador.
 */
class CommercialEventWiringTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;

    private Member $member;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Los observers están armados por su propio flag: sin esto no observan.
        config()->set('commercial.events_enabled', true);
        config()->set('commercial.enabled', false);

        Queue::fake([EvaluateCommercialSubject::class]);

        $this->user = User::create([
            'name' => 'Socio', 'email' => 'socio@iron.test', 'password' => bcrypt('x'),
        ]);
        $this->member = $this->member('Socio', '1010101010', '3150536026', $this->user->id);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573150536026',
            'phone' => '3150536026', 'name' => 'Socio', 'status' => MarketingLead::STATUS_NEW,
            'member_id' => $this->member->id,
        ]);
    }

    private function member(string $name, string $document, string $phone, ?int $userId = null): Member
    {
        return Member::create([
            'full_name' => $name,
            'document_number' => $document,
            'phone' => $phone,
            'user_id' => $userId,
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function transaction(array $attributes = []): PaymentTransaction
    {
        return PaymentTransaction::create(array_merge([
            'reference' => 'REF-'.uniqid(),
            'idempotency_key' => 'idem-'.uniqid(),
            'member_id' => $this->member->id,
            'user_id' => $this->user->id,
            'amount' => 120000,
            'currency' => 'COP',
            'status' => 'created',
            'provider' => 'wompi',
        ], $attributes));
    }

    private function events(string $event): int
    {
        return CommercialEvent::query()->where('event', $event)->count();
    }

    // ── Pagos ───────────────────────────────────────────────────────────────

    public function test_an_approved_payment_becomes_a_commercial_fact(): void
    {
        $tx = $this->transaction();

        $tx->update(['status' => 'approved']);

        $this->assertSame(1, $this->events(V::EV_PAYMENT_APPROVED));

        $fact = CommercialEvent::query()->where('event', V::EV_PAYMENT_APPROVED)->first();
        $this->assertSame($this->member->id, $fact->member_id);
        // El lead se resuelve aunque el pago solo conozca al miembro: sin eso no
        // habría por dónde contestarle a quien acaba de pagar.
        $this->assertSame($this->lead->id, $fact->marketing_lead_id);
    }

    /**
     * El caso central. La reconciliación reescribe la fila cada cinco minutos;
     * el hecho «este pago se aprobó» ocurrió UNA vez.
     */
    public function test_the_reconciler_seeing_the_same_payment_again_does_not_duplicate_the_fact(): void
    {
        $tx = $this->transaction();
        $tx->update(['status' => 'approved']);

        // Tres pasadas del reconciliador tocando la misma fila.
        for ($i = 0; $i < 3; $i++) {
            $tx->fresh()->update([
                'status' => 'approved',
                'last_reconciled_at' => now(),
                'raw_response' => ['pass' => $i],
            ]);
        }

        $this->assertSame(1, $this->events(V::EV_PAYMENT_APPROVED));
    }

    public function test_a_refreshed_pending_does_not_produce_a_fact_each_time(): void
    {
        $tx = $this->transaction(['status' => 'pending']);

        $tx->update(['status' => 'pending', 'status_message' => 'sigue en curso']);
        $tx->fresh()->update(['status' => 'pending', 'status_message' => 'y sigue']);

        // El estado nunca CAMBIÓ, así que no hay ningún hecho nuevo.
        $this->assertSame(0, $this->events(V::EV_PAYMENT_PENDING));
    }

    public function test_a_declined_payment_is_a_failure_not_an_expiry(): void
    {
        $this->transaction()->update(['status' => 'declined', 'failure_reason' => 'Fondos insuficientes']);

        $this->assertSame(1, $this->events(V::EV_PAYMENT_FAILED));
        $this->assertSame(0, $this->events(V::EV_PAYMENT_EXPIRED));

        // El motivo viaja: no es lo mismo un fondo insuficiente que una tarjeta
        // vencida, y la conversación siguiente depende de cuál fue.
        $fact = CommercialEvent::query()->where('event', V::EV_PAYMENT_FAILED)->first();
        $this->assertSame('Fondos insuficientes', $fact->payload['failure_reason']);
    }

    public function test_an_expired_payment_is_distinguished_from_a_rejection(): void
    {
        $this->transaction()->update(['status' => 'expired']);

        $this->assertSame(1, $this->events(V::EV_PAYMENT_EXPIRED));
        $this->assertSame(0, $this->events(V::EV_PAYMENT_FAILED));
    }

    public function test_a_transaction_with_a_checkout_link_announces_the_offer(): void
    {
        $this->transaction(['checkout_url' => 'https://checkout.wompi.co/l/abc']);

        $this->assertSame(1, $this->events(V::EV_PAYMENT_LINK_CREATED));
    }

    public function test_a_transaction_without_a_link_announces_nothing(): void
    {
        $this->transaction();

        $this->assertSame(0, $this->events(V::EV_PAYMENT_LINK_CREATED));
    }

    // ── Membresía ───────────────────────────────────────────────────────────

    public function test_a_first_membership_is_an_activation(): void
    {
        $this->user->update(['membership_end_date' => now()->addDays(30)->toDateString()]);

        $this->assertSame(1, $this->events(V::EV_MEMBERSHIP_ACTIVATED));
        $this->assertSame(0, $this->events(V::EV_MEMBERSHIP_RENEWED));
    }

    /**
     * Extender una membresía viva es renovar. Confundirlo con un alta produce
     * el mensaje de bienvenida que recibe quien lleva dos años viniendo.
     */
    public function test_extending_a_live_membership_is_a_renewal(): void
    {
        $this->user->update(['membership_end_date' => now()->addDays(5)->toDateString()]);
        $this->user->fresh()->update(['membership_end_date' => now()->addDays(35)->toDateString()]);

        $this->assertSame(1, $this->events(V::EV_MEMBERSHIP_RENEWED));
    }

    /** Volver después de haber vencido no es una renovación: es una vuelta. */
    public function test_returning_after_expiry_is_an_activation_not_a_renewal(): void
    {
        $this->user->forceFill(['membership_end_date' => now()->subDays(40)->toDateString()])->save();

        $this->user->fresh()->update(['membership_end_date' => now()->addDays(30)->toDateString()]);

        $this->assertSame(1, $this->events(V::EV_MEMBERSHIP_ACTIVATED));
        $this->assertSame(0, $this->events(V::EV_MEMBERSHIP_RENEWED));
    }

    /** Acortar la vigencia es una baja o una corrección, nunca una venta. */
    public function test_shortening_a_membership_is_not_a_commercial_fact(): void
    {
        $this->user->update(['membership_end_date' => now()->addDays(30)->toDateString()]);
        $this->user->fresh()->update(['membership_end_date' => now()->addDays(2)->toDateString()]);

        $this->assertSame(1, $this->events(V::EV_MEMBERSHIP_ACTIVATED));
        $this->assertSame(0, $this->events(V::EV_MEMBERSHIP_RENEWED));
    }

    // ── Conversación ────────────────────────────────────────────────────────

    /**
     * El único hecho que hace que el sistema se calle en vez de actuar.
     */
    public function test_asking_for_a_person_is_recorded(): void
    {
        $conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);

        $conversation->update(['human_takeover' => true, 'human_takeover_source' => 'customer_request']);

        $this->assertSame(1, $this->events(V::EV_HUMAN_REQUESTED));
    }

    public function test_returning_the_conversation_to_the_bot_is_not_a_takeover(): void
    {
        $conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => true,
        ]);

        $conversation->update(['human_takeover' => false]);

        $this->assertSame(0, $this->events(V::EV_HUMAN_REQUESTED));
    }

    // ── App ─────────────────────────────────────────────────────────────────

    public function test_linking_the_app_is_recorded_once(): void
    {
        $member = $this->member('Sin app', '2020202020', '3009998877');
        $other = User::create(['name' => 'U', 'email' => 'u@iron.test', 'password' => bcrypt('x')]);

        $member->update(['user_id' => $other->id]);
        $member->fresh()->update(['user_id' => $other->id, 'phone' => '3009998800']);

        $this->assertSame(1, $this->events(V::EV_APP_LINKED));
    }

    // ── Flag ────────────────────────────────────────────────────────────────

    /**
     * Con el flag apagado el sistema se comporta EXACTAMENTE como antes de que
     * este módulo existiera. Es la condición para poder desplegarlo.
     */
    public function test_with_the_flag_off_nothing_is_observed(): void
    {
        // El montaje de la prueba creó un lead con el flag encendido, así que
        // se parte de cero para medir solo lo que ocurre con el flag apagado.
        CommercialEvent::query()->delete();
        config()->set('commercial.events_enabled', false);

        $this->transaction(['checkout_url' => 'https://checkout.wompi.co/l/x'])
            ->update(['status' => 'approved']);
        $this->user->update(['membership_end_date' => now()->addDays(30)->toDateString()]);

        $this->assertSame(0, CommercialEvent::query()->count());
    }

    // ── Encolado ────────────────────────────────────────────────────────────

    /** Registrar un hecho no evalúa nada si el motor está apagado. */
    public function test_facts_are_recorded_but_not_evaluated_while_the_engine_is_off(): void
    {
        $this->transaction()->update(['status' => 'approved']);

        $this->assertSame(1, $this->events(V::EV_PAYMENT_APPROVED));
        Queue::assertNothingPushed();
    }

    public function test_with_the_engine_on_the_fact_queues_its_evaluation(): void
    {
        config()->set('commercial.enabled', true);

        $this->transaction()->update(['status' => 'approved']);

        Queue::assertPushed(EvaluateCommercialSubject::class);
    }
}
