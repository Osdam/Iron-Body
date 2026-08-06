<?php

namespace Tests\Feature\Commercial;

use App\Jobs\Commercial\EvaluateCommercialSubject;
use App\Models\CommercialEvent;
use App\Models\CommercialOpportunity;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Member;
use App\Models\Plan;
use App\Models\User;
use App\Services\Commercial\CommercialVocabulary as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El principio que sostiene el módulo, puesto a prueba.
 *
 * «Ninguna venta termina la relación comercial.» Suena a lema, pero es una
 * afirmación verificable: cuando alguien paga, el objetivo de cobrar tiene que
 * cerrarse Y tiene que aparecer el siguiente. Un sistema que solo hace lo
 * primero se queda callado justo después de la venta —que es cuando más abierta
 * está la relación—; uno que solo hace lo segundo le sigue reclamando un pago a
 * quien ya pagó.
 *
 * Se prueba el ciclo entero desde el hecho crudo, no llamando al motor a mano:
 * lo que se quiere saber es si la cadena real funciona.
 */
class CommercialLoopTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;

    private Member $member;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('commercial.events_enabled', true);
        config()->set('commercial.enabled', true);

        $this->user = User::create([
            'name' => 'Socio', 'email' => 'socio@iron.test', 'password' => bcrypt('x'),
        ]);
        $this->member = Member::create([
            'full_name' => 'Socio', 'document_number' => '1010101010', 'phone' => '3150536026',
            'user_id' => $this->user->id, 'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'meta_user_id' => '573150536026',
            'phone' => '3150536026', 'name' => 'Socio', 'status' => MarketingLead::STATUS_INTERESTED,
            'member_id' => $this->member->id,
        ]);
        MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
            'last_inbound_at' => now()->subHour(),
        ]);
    }

    /** Ejecuta el job de verdad sobre el último hecho registrado. */
    private function evaluateLast(): void
    {
        $event = CommercialEvent::query()->orderByDesc('id')->firstOrFail();

        app()->call([new EvaluateCommercialSubject($event->id), 'handle']);
    }

    private function openOpportunity(string $goal): CommercialOpportunity
    {
        return CommercialOpportunity::create([
            'marketing_lead_id' => $this->lead->id,
            'member_id' => $this->member->id,
            'goal' => $goal,
            'status' => V::STATUS_OPEN,
            'next_action' => V::ACTION_SEND_PAYMENT_LINK,
            'reason' => 'montaje de prueba',
            'max_attempts' => 3,
            'estimated_value' => 120000,
        ]);
    }

    /** Da vigencia a la membresía sin pasar por el observer de pagos. */
    private function grantMembership(int $days = 30): void
    {
        $this->user->forceFill([
            'membership_end_date' => now()->addDays($days)->toDateString(),
            'membership_start_date' => now()->toDateString(),
            'plan' => 'Mensual',
        ])->save();
    }

    // ── El cierre ───────────────────────────────────────────────────────────

    /**
     * El fallo más caro que puede tener un motor comercial: seguir reclamando
     * un pago que ya se hizo.
     */
    public function test_paying_closes_the_goal_of_collecting(): void
    {
        $opportunity = $this->openOpportunity(V::GOAL_COLLECT_PAYMENT);

        $this->grantMembership();
        CommercialEvent::query()->delete();

        CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $this->member->id,
            'event' => V::EV_PAYMENT_APPROVED, 'dedupe_key' => 'test:paid',
            'occurred_at' => now(),
        ]);

        $this->evaluateLast();

        $opportunity->refresh();
        $this->assertSame(V::STATUS_WON, $opportunity->status);
        $this->assertSame('membership_active', $opportunity->outcome_reason);
        // El valor se retiene para poder atribuir ingresos después.
        $this->assertEquals(120000, $opportunity->realized_value);
    }

    /**
     * Cerrada la venta, tiene que aparecer un objetivo NUEVO. Esta es la
     * afirmación central del módulo.
     */
    public function test_after_the_sale_a_new_goal_appears(): void
    {
        $this->openOpportunity(V::GOAL_COLLECT_PAYMENT);

        $this->grantMembership();
        CommercialEvent::query()->delete();
        CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $this->member->id,
            'event' => V::EV_PAYMENT_APPROVED, 'dedupe_key' => 'test:paid',
            'occurred_at' => now(),
        ]);

        $this->evaluateLast();

        $next = CommercialOpportunity::query()
            ->whereIn('status', V::OPEN_STATUSES)
            ->first();

        $this->assertNotNull($next, 'Tras la venta el sistema se quedó sin objetivo siguiente.');
        $this->assertNotSame(V::GOAL_COLLECT_PAYMENT, $next->goal);
    }

    /**
     * Una oportunidad de renovación no puede darse por ganada en el mismo
     * instante en que se abre: la membresía vigente que se quiere renovar es
     * justo la que haría verdadera la condición.
     */
    public function test_a_renewal_is_not_won_by_the_membership_it_wants_to_renew(): void
    {
        $this->grantMembership(5);

        $opportunity = $this->openOpportunity(V::GOAL_RENEW);
        $opportunity->forceFill(['created_at' => now()])->save();

        CommercialEvent::query()->delete();
        CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $this->member->id,
            'event' => V::EV_RENEWAL_WINDOW_OPENED, 'dedupe_key' => 'test:renewal',
            'occurred_at' => now(),
        ]);

        $this->evaluateLast();

        $this->assertNotSame(V::STATUS_WON, $opportunity->fresh()->status);
    }

    /** Renovar de verdad —extender después de abrir el objetivo— sí lo cierra. */
    public function test_an_actual_renewal_closes_the_goal(): void
    {
        $opportunity = $this->openOpportunity(V::GOAL_RENEW);
        $opportunity->forceFill(['created_at' => now()->subDays(3)])->save();

        // La membresía empieza HOY: es posterior a la apertura del objetivo.
        $this->grantMembership(30);

        CommercialEvent::query()->delete();
        CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $this->member->id,
            'event' => V::EV_MEMBERSHIP_RENEWED, 'dedupe_key' => 'test:renewed',
            'occurred_at' => now(),
        ]);

        $this->evaluateLast();

        $this->assertSame(V::STATUS_WON, $opportunity->fresh()->status);
    }

    /**
     * Pedir una persona detiene el motor. Y la oportunidad queda BLOQUEADA, no
     * perdida: no fracasó, se apartó. La distinción importa para poder retomar
     * el caso y para no ensuciar la conversión con derrotas falsas.
     */
    public function test_asking_for_a_human_blocks_instead_of_losing(): void
    {
        $opportunity = $this->openOpportunity(V::GOAL_CLOSE_PLAN);

        MarketingConversation::query()->where('lead_id', $this->lead->id)
            ->update(['human_takeover' => true, 'ai_enabled' => false]);

        CommercialEvent::query()->delete();
        CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $this->member->id,
            'event' => V::EV_HUMAN_REQUESTED, 'dedupe_key' => 'test:human',
            'occurred_at' => now(),
        ]);

        $this->evaluateLast();

        $opportunity->refresh();
        $this->assertSame(V::STATUS_BLOCKED, $opportunity->status);
        $this->assertSame('human_in_control', $opportunity->outcome_reason);
    }

    /** Agotar los intentos cierra: el silencio sostenido es una respuesta. */
    public function test_an_exhausted_opportunity_is_closed_as_lost(): void
    {
        $opportunity = $this->openOpportunity(V::GOAL_CLOSE_PLAN);
        $opportunity->forceFill(['attempts' => 3, 'max_attempts' => 3])->save();

        CommercialEvent::query()->delete();
        CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $this->member->id,
            'event' => V::EV_LEAD_QUALIFIED, 'dedupe_key' => 'test:q',
            'occurred_at' => now(),
        ]);

        $this->evaluateLast();

        $opportunity->refresh();
        $this->assertSame(V::STATUS_LOST, $opportunity->status);
        $this->assertSame('max_attempts_reached', $opportunity->outcome_reason);
    }

    // ── Idempotencia del ciclo ──────────────────────────────────────────────

    /** Evaluar dos veces el mismo hecho no puede producir dos oportunidades. */
    public function test_evaluating_the_same_fact_twice_changes_nothing(): void
    {
        CommercialEvent::query()->delete();
        $event = CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $this->member->id,
            'event' => V::EV_LEAD_QUALIFIED, 'dedupe_key' => 'test:twice',
            'occurred_at' => now(),
        ]);

        app()->call([new EvaluateCommercialSubject($event->id), 'handle']);
        $after = CommercialOpportunity::query()->count();

        // La segunda pasada encuentra evaluated_at ya puesto y se retira.
        app()->call([new EvaluateCommercialSubject($event->id), 'handle']);

        $this->assertSame($after, CommercialOpportunity::query()->count());
    }

    public function test_the_event_is_marked_evaluated(): void
    {
        CommercialEvent::query()->delete();
        $event = CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $this->member->id,
            'event' => V::EV_LEAD_QUALIFIED, 'dedupe_key' => 'test:mark',
            'occurred_at' => now(),
        ]);

        app()->call([new EvaluateCommercialSubject($event->id), 'handle']);

        $this->assertNotNull($event->fresh()->evaluated_at);
    }

    /**
     * Con el motor apagado el job no hace nada, aunque le llegue el hecho. Es
     * la condición para poder desplegar esto sin encenderlo.
     */
    public function test_with_the_engine_off_the_job_decides_nothing(): void
    {
        config()->set('commercial.enabled', false);

        // El montaje corrió con el motor encendido y la cola en modo síncrono,
        // así que ya pudo abrirse alguna oportunidad. Se parte de cero para
        // medir solo lo que hace el job con el motor apagado.
        CommercialOpportunity::query()->delete();
        CommercialEvent::query()->delete();
        $event = CommercialEvent::create([
            'marketing_lead_id' => $this->lead->id, 'member_id' => $this->member->id,
            'event' => V::EV_LEAD_QUALIFIED, 'dedupe_key' => 'test:off',
            'occurred_at' => now(),
        ]);

        app()->call([new EvaluateCommercialSubject($event->id), 'handle']);

        $this->assertSame(0, CommercialOpportunity::query()->count());
        $this->assertNull($event->fresh()->evaluated_at);
    }
}
