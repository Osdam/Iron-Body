<?php

namespace Tests\Feature\Commercial;

use App\Models\CommercialOpportunity;
use App\Models\MarketingLead;
use App\Models\Plan;
use App\Services\Commercial\CommercialSubject;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\NextBestActionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El criterio comercial del sistema, fijado como pruebas.
 *
 * El motor decide qué hacer con cada persona, cuándo, y —lo que más importa—
 * qué NO hacer todavía. Aquí se pinta la diferencia entre un sistema que vende
 * y uno que molesta: ofrecerle un plan anual a alguien que aún no ha pisado el
 * gimnasio es la forma más rápida de que pida la baja, y ninguna métrica de
 * conversión lo detecta a tiempo.
 *
 * Las reglas son deterministas a propósito: una oferta la tiene que poder
 * explicar un humano mirando una fila, no preguntándole a un modelo.
 */
class NextBestActionTest extends TestCase
{
    use RefreshDatabase;

    private Plan $mensual;

    private Plan $trimestral;

    private Plan $anual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mensual = Plan::create([
            'name' => 'Plan Mensual', 'price' => 90000, 'duration_days' => 30, 'active' => true,
        ]);
        $this->trimestral = Plan::create([
            'name' => 'Plan Trimestral', 'price' => 240000, 'duration_days' => 90, 'active' => true,
        ]);
        $this->anual = Plan::create([
            'name' => 'Plan Anual', 'price' => 850000, 'duration_days' => 365, 'active' => true,
        ]);
    }

    private function engine(): NextBestActionEngine
    {
        return app(NextBestActionEngine::class);
    }

    private function lead(array $attributes = []): MarketingLead
    {
        return MarketingLead::create(array_merge([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'meta_user_id' => '5731505'.random_int(10000, 99999),
            'phone' => '3150536026', 'name' => 'Prospecto',
            'status' => MarketingLead::STATUS_NEW,
        ], $attributes));
    }

    /** Construye una fotografía a mano: las reglas se prueban contra hechos. */
    private function subject(array $facts = []): CommercialSubject
    {
        return new CommercialSubject(...array_merge([
            'lead' => $this->lead(),
        ], $facts));
    }

    // ── Lo primero de todo ────────────────────────────────────────────────────

    /** El opt-out está por encima de cualquier oportunidad. No admite matices. */
    public function test_a_person_who_asked_not_to_be_contacted_gets_no_decision(): void
    {
        $subject = $this->subject([
            'doNotContact' => true,
            // Con una oportunidad valiosísima encima: da igual.
            'hasPendingPaymentLink' => true,
            'pendingPaymentLinkAt' => now()->subDays(2),
        ]);

        $this->assertNull($this->engine()->decide($subject));
    }

    /** Si una persona lleva el caso, el motor no propone nada comercial. */
    public function test_a_conversation_with_a_human_produces_no_commercial_offer(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'needsHuman' => true,
            'hasActiveMembership' => true,
            'daysToExpiry' => 3,
        ]));

        $this->assertSame(V::GOAL_ESCALATE, $decision['goal']);
        $this->assertSame(V::ACTION_ESCALATE_HUMAN, $decision['action']);
        $this->assertArrayHasKey('no_automated_offer', $decision['exclusions']);
    }

    // ── Dinero ya comprometido ────────────────────────────────────────────────

    /**
     * Un enlace de pago sin usar es lo más valioso que hay: esa persona ya dijo
     * que sí. Pero recordárselo a las dos horas es impaciencia, no diligencia.
     */
    public function test_a_fresh_payment_link_is_left_alone_for_a_few_hours(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'hasPendingPaymentLink' => true,
            'pendingPaymentLinkAt' => now()->subHours(2),
            'pendingPaymentPlanId' => $this->mensual->id,
        ]));

        $this->assertSame(V::GOAL_RECOVER_PAYMENT_LINK, $decision['goal']);
        $this->assertSame(V::ACTION_WAIT, $decision['action']);
        $this->assertArrayHasKey('too_soon', $decision['exclusions']);
        $this->assertTrue($decision['act_after']->isFuture());
    }

    public function test_an_abandoned_payment_link_is_chased_with_the_right_plan(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'hasPendingPaymentLink' => true,
            'pendingPaymentLinkAt' => now()->subHours(20),
            'pendingPaymentPlanId' => $this->mensual->id,
        ]));

        $this->assertSame(V::ACTION_RESEND_PAYMENT_LINK, $decision['action']);
        $this->assertSame($this->mensual->id, $decision['offer_plan_id']);
        $this->assertSame(90000.0, $decision['estimated_value']);
        // Dos recordatorios como mucho: el tercero ya es acoso.
        $this->assertSame(2, $decision['max_attempts']);
    }

    /** Pagó y no tiene acceso: eso se resuelve antes que cualquier venta. */
    public function test_a_paid_customer_without_access_is_the_top_priority(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'approvedPaymentsCount' => 1,
            'hasActiveMembership' => false,
        ]));

        $this->assertSame(V::GOAL_ACTIVATE_MEMBERSHIP, $decision['goal']);
        $this->assertArrayHasKey('no_selling', $decision['exclusions']);
    }

    /** Un rechazo del banco no es una objeción de precio. */
    public function test_a_declined_payment_never_triggers_a_discount(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'hasDeclinedPayment' => true,
        ]));

        $this->assertSame(V::GOAL_COLLECT_PAYMENT, $decision['goal']);
        $this->assertArrayHasKey('no_discount', $decision['exclusions']);
    }

    // ── El corazón del asunto: no vender cuando no toca ───────────────────────

    /**
     * LA regla que separa un sistema comercial de una máquina de molestar: un
     * miembro nuevo que todavía no ha venido NO recibe una oferta de plan más
     * largo. Recibe ayuda para empezar.
     */
    public function test_a_brand_new_member_who_never_came_is_never_upsold(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'lead' => null,
            'hasActiveMembership' => true,
            'daysAsMember' => 5,
            'attendancesLast30Days' => 0,
            'currentPlanDurationDays' => 30,
        ]));

        $this->assertSame(V::GOAL_COMPLETE_ONBOARDING, $decision['goal']);
        $this->assertSame(V::ACTION_OFFER_APPOINTMENT, $decision['action']);
        $this->assertArrayHasKey('no_upsell', $decision['exclusions']);
        $this->assertNull($decision['offer_plan_id']);
    }

    /** Ya viene pero no tiene la app: acompañar, no vender. */
    public function test_a_new_member_without_the_app_is_guided_not_sold(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'lead' => null,
            'hasActiveMembership' => true,
            'daysAsMember' => 10,
            'attendancesLast30Days' => 6,
            'hasAppAccount' => false,
        ]));

        $this->assertSame(V::GOAL_LINK_APP, $decision['goal']);
        $this->assertSame(V::ACTION_GUIDE_APP_LINK, $decision['action']);
    }

    /**
     * Paga y no viene. Desde caja parece un cliente sano; es el patrón previo a
     * no renovar. Aquí NO se empuja la renovación: se pregunta qué pasa.
     */
    public function test_a_member_who_pays_but_never_shows_up_is_rescued_not_pushed(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'lead' => null,
            'hasActiveMembership' => true,
            'daysAsMember' => 40,
            'daysSinceLastAttendance' => 21,
            'attendancesLast30Days' => 1,
            'currentPlanDurationDays' => 30,
        ]));

        $this->assertSame(V::GOAL_INCREASE_ADHERENCE, $decision['goal']);
        $this->assertSame(V::ACTION_CHECK_IN, $decision['action']);
        $this->assertArrayHasKey('no_upsell', $decision['exclusions']);
        $this->assertArrayHasKey('no_renewal_push', $decision['exclusions']);
    }

    // ── Renovación y upgrade: con evidencia de uso ───────────────────────────

    /**
     * El caso que el negocio quiere: viene cuatro veces por semana y le vence
     * pronto. Ahí sí se propone el plan largo, con alternativa y mínimo, porque
     * ir a por el anual y volver sin nada es el error clásico.
     */
    public function test_an_engaged_member_near_expiry_gets_a_full_offer_ladder(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'lead' => null,
            'hasActiveMembership' => true,
            'daysAsMember' => 60,
            'daysToExpiry' => 5,
            'attendancesLast30Days' => 17,      // ~4/semana
            'currentPlanDurationDays' => 30,
        ]));

        $this->assertSame(V::GOAL_RENEW, $decision['goal']);
        $this->assertSame(V::ACTION_OFFER_UPGRADE, $decision['action']);

        // Los tres escalones presentes: sin el mínimo, una negativa al anual
        // se convierte en cliente perdido en vez de renovación mensual.
        $this->assertSame($this->anual->id, $decision['offer_plan_id']);
        $this->assertSame($this->trimestral->id, $decision['alternative_plan_id']);
        $this->assertSame($this->mensual->id, $decision['floor_plan_id']);
        $this->assertStringContainsString('veces por semana', $decision['reason']);
    }

    /** Poco uso y vencimiento cerca: asegurar la renovación, no alargar. */
    public function test_a_low_usage_member_near_expiry_is_not_offered_a_longer_plan(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'lead' => null,
            'hasActiveMembership' => true,
            'daysAsMember' => 60,
            'daysToExpiry' => 4,
            'attendancesLast30Days' => 3,       // <1/semana
            'daysSinceLastAttendance' => 5,
            'currentPlanDurationDays' => 30,
        ]));

        $this->assertSame(V::GOAL_RENEW, $decision['goal']);
        $this->assertSame(V::ACTION_OFFER_RENEWAL, $decision['action']);
        $this->assertSame($this->mensual->id, $decision['offer_plan_id']);
        $this->assertArrayHasKey('no_upgrade', $decision['exclusions']);
    }

    /** Buen candidato pero le queda mucho plan: se agenda, no se fuerza. */
    public function test_a_good_upgrade_candidate_with_time_left_is_scheduled_not_pushed(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'lead' => null,
            'hasActiveMembership' => true,
            // Pasados los 30 días ya no está en onboarding, que tiene
            // precedencia sobre cualquier mejora y es lo correcto.
            'daysAsMember' => 40,
            'daysToExpiry' => 60,
            'attendancesLast30Days' => 14,
            'daysSinceLastAttendance' => 2,
            'currentPlanDurationDays' => 90,
            'hasAppAccount' => true,
        ]));

        $this->assertSame(V::ACTION_WAIT, $decision['action']);
        $this->assertArrayHasKey('bad_timing', $decision['exclusions']);
        $this->assertTrue($decision['act_after']->isFuture());
    }

    // ── Reactivación ──────────────────────────────────────────────────────────

    public function test_a_just_expired_membership_gets_a_few_days_of_margin(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'lead' => null, 'hasActiveMembership' => false, 'daysSinceExpiry' => 1,
        ]));

        $this->assertSame(V::ACTION_WAIT, $decision['action']);
        $this->assertArrayHasKey('too_soon', $decision['exclusions']);
    }

    /** Se pregunta antes de ofrecer: puede haberse ido por algo concreto. */
    public function test_reactivation_starts_by_understanding_not_by_discounting(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'lead' => null, 'hasActiveMembership' => false, 'daysSinceExpiry' => 20,
        ]));

        $this->assertSame(V::GOAL_REACTIVATE, $decision['goal']);
        $this->assertSame(V::ACTION_CHECK_IN, $decision['action']);
        $this->assertArrayHasKey('no_discount', $decision['exclusions']);
        $this->assertSame(2, $decision['max_attempts']);
    }

    /** Pasado el año, escribir es spam. */
    public function test_a_membership_expired_over_a_year_ago_is_left_alone(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'lead' => null, 'hasActiveMembership' => false, 'daysSinceExpiry' => 400,
        ]));

        $this->assertNull($decision);
    }

    // ── Prospectos ────────────────────────────────────────────────────────────

    public function test_an_unknown_prospect_is_asked_before_being_offered_anything(): void
    {
        $decision = $this->engine()->decide($this->subject());

        $this->assertSame(V::GOAL_COLLECT_DATA, $decision['goal']);
        $this->assertSame(V::ACTION_ASK_DISCOVERY, $decision['action']);
        $this->assertArrayHasKey('no_offer', $decision['exclusions']);
    }

    public function test_a_qualified_prospect_gets_a_recommendation(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'objective' => 'bajar de peso',
        ]));

        $this->assertSame(V::GOAL_CLOSE_PLAN, $decision['goal']);
        $this->assertSame(V::ACTION_RECOMMEND_PLAN, $decision['action']);
        $this->assertNotNull($decision['offer_plan_id']);
    }

    /** Quien ya objetó el precio no recibe la opción más cara primero. */
    public function test_a_price_sensitive_prospect_starts_from_the_entry_plan(): void
    {
        $decision = $this->engine()->decide($this->subject([
            'objective' => 'ganar masa',
            'priceObjections' => 2,
        ]));

        $this->assertSame($this->mensual->id, $decision['offer_plan_id']);
        $this->assertArrayHasKey('price_sensitive', $decision['exclusions']);
    }

    /** Referidos: solo a quien lleva tiempo y viene de verdad. */
    public function test_referrals_are_only_asked_of_committed_members(): void
    {
        $noPide = $this->engine()->decide($this->subject([
            'lead' => null, 'hasActiveMembership' => true,
            'daysAsMember' => 20, 'attendancesLast30Days' => 15,
            'currentPlanDurationDays' => 365, 'daysToExpiry' => 300,
        ]));
        $this->assertNotSame(V::GOAL_REQUEST_REFERRAL, $noPide['goal'] ?? null);

        $pide = $this->engine()->decide($this->subject([
            'lead' => null, 'hasActiveMembership' => true,
            'daysAsMember' => 90, 'attendancesLast30Days' => 16,
            'currentPlanDurationDays' => 365, 'daysToExpiry' => 200,
        ]));
        $this->assertSame(V::GOAL_REQUEST_REFERRAL, $pide['goal']);
    }

    // ── Persistencia ──────────────────────────────────────────────────────────

    public function test_evaluating_persists_an_auditable_opportunity(): void
    {
        $lead = $this->lead(['objective' => 'bajar de peso']);
        $subject = new CommercialSubject(lead: $lead, objective: 'bajar de peso');

        $opportunity = $this->engine()->evaluate($subject);

        $this->assertNotNull($opportunity);
        $this->assertSame(V::GOAL_CLOSE_PLAN, $opportunity->goal);
        // Lo que hace auditable la decisión: razón, evidencia y prioridad.
        $this->assertNotEmpty($opportunity->reason);
        $this->assertNotEmpty($opportunity->evidence);
        $this->assertSame(V::priorityFor(V::GOAL_CLOSE_PLAN), $opportunity->priority);
        $this->assertNotNull($opportunity->uuid);
    }

    /** Reevaluar no abre una segunda oportunidad para lo mismo. */
    public function test_re_evaluating_updates_instead_of_duplicating(): void
    {
        $lead = $this->lead(['objective' => 'bajar de peso']);
        $subject = new CommercialSubject(lead: $lead, objective: 'bajar de peso');

        $this->engine()->evaluate($subject);
        $this->engine()->evaluate($subject);
        $this->engine()->evaluate($subject);

        $this->assertSame(1, CommercialOpportunity::where('goal', V::GOAL_CLOSE_PLAN)->count());
    }

    /** Reevaluar tampoco reinicia los intentos: sería una vía para insistir. */
    public function test_re_evaluating_never_resets_the_attempt_counter(): void
    {
        $lead = $this->lead(['objective' => 'bajar de peso']);
        $subject = new CommercialSubject(lead: $lead, objective: 'bajar de peso');

        $opportunity = $this->engine()->evaluate($subject);
        $opportunity->recordAttempt();
        $opportunity->recordAttempt();

        $this->engine()->evaluate($subject);

        $this->assertSame(2, $opportunity->fresh()->attempts);
    }

    /** Agotados los intentos, la oportunidad deja de ser accionable. */
    public function test_an_opportunity_stops_being_actionable_after_its_last_attempt(): void
    {
        $lead = $this->lead(['objective' => 'bajar de peso']);
        $opportunity = $this->engine()->evaluate(new CommercialSubject(lead: $lead, objective: 'bajar de peso'));

        $this->assertTrue($opportunity->isActionable());

        for ($i = 0; $i < $opportunity->max_attempts; $i++) {
            $opportunity->recordAttempt();
        }

        $this->assertFalse($opportunity->fresh()->isActionable());
    }

    /** Una oportunidad con fecha futura no se toca hasta que llegue. */
    public function test_an_opportunity_scheduled_for_later_is_not_actionable_yet(): void
    {
        $lead = $this->lead(['objective' => 'bajar de peso']);
        $opportunity = $this->engine()->evaluate(new CommercialSubject(lead: $lead, objective: 'bajar de peso'));

        $opportunity->forceFill(['act_after' => now()->addDays(2)])->save();
        $this->assertFalse($opportunity->fresh()->isActionable());

        Carbon::setTestNow(now()->addDays(3));
        $this->assertTrue($opportunity->fresh()->isActionable());
        Carbon::setTestNow();
    }

    /** Sin catálogo de planes no se inventa ninguno. */
    public function test_with_no_active_plans_no_offer_is_invented(): void
    {
        Plan::query()->update(['active' => false]);

        $decision = $this->engine()->decide($this->subject(['objective' => 'bajar de peso']));

        // Sigue decidiendo qué hacer, pero sin plan concreto.
        $this->assertSame(V::GOAL_CLOSE_PLAN, $decision['goal']);
        $this->assertNull($decision['offer']);
    }
}
