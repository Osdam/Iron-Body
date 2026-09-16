<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La máquina comercial y las dos columnas que la sostienen.
 *
 * El caso que justifica que exista: `lead_stage` se DERIVA de la intención del
 * último mensaje, así que alguien que ya dijo «quiero pagar» vuelve a `new` en
 * cuanto escribe «hola». Para una etiqueta de color da igual; para decidir qué
 * decir a continuación, no. Eso es lo primero que se prueba aquí.
 */
class CommercialPhaseMachineTest extends TestCase
{
    use RefreshDatabase;

    private P $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = app(P::class);
    }

    // ── Vocabulario ───────────────────────────────────────────────────────────

    public function test_all_eighteen_phases_are_recognised(): void
    {
        $esperadas = [
            'NEW_LEAD', 'RAPPORT', 'DISCOVERY', 'GOAL_DISCOVERY', 'BARRIER_DISCOVERY',
            'QUALIFICATION', 'VALUE_BUILDING', 'RECOMMENDATION', 'OBJECTION_HANDLING',
            'BUYING_SIGNAL', 'CLOSING', 'PAYMENT_HANDOFF', 'FOLLOW_UP', 'NURTURE',
            'HUMAN_HANDOFF', 'WON', 'LOST', 'DO_NOT_CONTACT',
        ];

        $this->assertSame($esperadas, P::PHASES);
        $this->assertCount(18, P::PHASES);

        foreach ($esperadas as $fase) {
            $this->assertTrue($this->m->isPhase($fase), "{$fase} debería reconocerse");
        }

        $this->assertFalse($this->m->isPhase('INVENTADA'));
        $this->assertFalse($this->m->isPhase(null));
        $this->assertFalse($this->m->isPhase('closing'), 'Distingue mayúsculas: el vocabulario es cerrado.');
    }

    public function test_unknown_phase_falls_back_to_the_beginning(): void
    {
        $this->assertSame(P::NEW_LEAD, $this->m->normalize(null));
        $this->assertSame(P::NEW_LEAD, $this->m->normalize('CUALQUIER_COSA'));
        $this->assertSame(P::CLOSING, $this->m->normalize(P::CLOSING));
    }

    // ── El caso que motiva toda la clase ──────────────────────────────────────

    /**
     * La invariante central: un saludo no puede devolver al principio a quien ya
     * estaba cerrando. Es exactamente lo que hace hoy `lead_stage`, y es lo que
     * esta máquina impide.
     */
    public function test_a_greeting_cannot_reset_a_closing_conversation(): void
    {
        $permitidas = $this->m->allowedTransitions(P::CLOSING);

        $this->assertNotContains(P::NEW_LEAD, $permitidas);
        $this->assertNotContains(P::RAPPORT, $permitidas);
        $this->assertNotContains(P::DISCOVERY, $permitidas);
        $this->assertNotContains(P::GOAL_DISCOVERY, $permitidas);

        $this->assertFalse($this->m->canTransition(P::CLOSING, P::NEW_LEAD));
        // Y si alguien lo intenta igual, se queda donde estaba.
        $this->assertSame(P::CLOSING, $this->m->transition(P::CLOSING, P::NEW_LEAD));
    }

    /** Lo mismo desde una señal de compra: no se vuelve a explorar el objetivo. */
    public function test_buying_signal_does_not_go_back_to_discovery(): void
    {
        $this->assertFalse($this->m->canTransition(P::BUYING_SIGNAL, P::GOAL_DISCOVERY));
        $this->assertFalse($this->m->canTransition(P::BUYING_SIGNAL, P::DISCOVERY));
        // Pero sí puede objetar en el último momento: eso pasa todos los días.
        $this->assertTrue($this->m->canTransition(P::BUYING_SIGNAL, P::OBJECTION_HANDLING));
    }

    // ── Transiciones legales ──────────────────────────────────────────────────

    public function test_legal_transitions(): void
    {
        $legales = [
            [P::NEW_LEAD, P::RAPPORT],
            [P::RAPPORT, P::GOAL_DISCOVERY],
            [P::GOAL_DISCOVERY, P::BARRIER_DISCOVERY],
            [P::BARRIER_DISCOVERY, P::VALUE_BUILDING],
            [P::VALUE_BUILDING, P::RECOMMENDATION],
            // Objetar después de que te recomienden es lo NORMAL, no la excepción.
            [P::RECOMMENDATION, P::OBJECTION_HANDLING],
            [P::OBJECTION_HANDLING, P::RECOMMENDATION],
            // Volver a descubrir la barrera tras una objeción: correcto.
            [P::OBJECTION_HANDLING, P::BARRIER_DISCOVERY],
            [P::OBJECTION_HANDLING, P::BUYING_SIGNAL],
            [P::BUYING_SIGNAL, P::CLOSING],
            [P::CLOSING, P::WON],
            // Compra rápida: del primer mensaje al cierre, sin pasar por todo.
            [P::NEW_LEAD, P::CLOSING],
            // Retomar una conversación perdida.
            [P::LOST, P::DISCOVERY],
            [P::FOLLOW_UP, P::RECOMMENDATION],
            [P::NURTURE, P::GOAL_DISCOVERY],
            // Cambiar de objetivo a mitad.
            [P::RECOMMENDATION, P::GOAL_DISCOVERY],
        ];

        foreach ($legales as [$de, $a]) {
            $this->assertTrue($this->m->canTransition($de, $a), "{$de} → {$a} debería ser legal");
        }
    }

    public function test_staying_in_the_same_phase_is_always_legal(): void
    {
        foreach (P::PHASES as $fase) {
            $this->assertContains($fase, $this->m->allowedTransitions($fase), "{$fase} → {$fase}");
        }
    }

    // ── Transiciones ilegales ─────────────────────────────────────────────────

    public function test_illegal_transitions(): void
    {
        $ilegales = [
            [P::CLOSING, P::NEW_LEAD],
            [P::PAYMENT_HANDOFF, P::NEW_LEAD],
            [P::BUYING_SIGNAL, P::RAPPORT],
            // No se «gana» una venta desde una pregunta de ubicación.
            [P::DISCOVERY, P::WON],
            [P::GOAL_DISCOVERY, P::WON],
            // De los terminales de verdad no se sale.
            [P::WON, P::DISCOVERY],
            [P::DO_NOT_CONTACT, P::RECOMMENDATION],
            [P::DO_NOT_CONTACT, P::HUMAN_HANDOFF],
        ];

        foreach ($ilegales as [$de, $a]) {
            $this->assertFalse($this->m->canTransition($de, $a), "{$de} → {$a} NO debería ser legal");
        }

        $this->assertFalse($this->m->canTransition(P::DISCOVERY, 'FASE_QUE_NO_EXISTE'));
        $this->assertFalse($this->m->canTransition(P::DISCOVERY, null));
    }

    // ── Salidas globales ──────────────────────────────────────────────────────

    /** Pedir que no le escriban se puede desde cualquier punto de la venta. */
    public function test_do_not_contact_is_reachable_from_everywhere(): void
    {
        foreach (P::PHASES as $fase) {
            if (in_array($fase, P::TERMINAL, true)) {
                continue;
            }
            $this->assertTrue(
                $this->m->canTransition($fase, P::DO_NOT_CONTACT),
                "Desde {$fase} se tiene que poder pedir que no le escriban",
            );
        }
    }

    public function test_human_handoff_is_reachable_from_everywhere(): void
    {
        foreach (P::PHASES as $fase) {
            if (in_array($fase, P::TERMINAL, true)) {
                continue;
            }
            $this->assertTrue(
                $this->m->canTransition($fase, P::HUMAN_HANDOFF),
                "Desde {$fase} se tiene que poder pedir un humano",
            );
        }
    }

    public function test_do_not_contact_in_context_closes_every_other_door(): void
    {
        $permitidas = $this->m->allowedTransitions(P::RECOMMENDATION, ['do_not_contact' => true]);

        $this->assertSame([P::DO_NOT_CONTACT], $permitidas);
    }

    /** Con una persona al mando el agente no mueve la venta. */
    public function test_human_takeover_freezes_the_funnel(): void
    {
        $permitidas = $this->m->allowedTransitions(P::RECOMMENDATION, ['human_takeover' => true]);

        $this->assertContains(P::HUMAN_HANDOFF, $permitidas);
        $this->assertContains(P::RECOMMENDATION, $permitidas);
        $this->assertNotContains(P::CLOSING, $permitidas);
        $this->assertNotContains(P::WON, $permitidas);
    }

    // ── El flag de pago condiciona la fase ────────────────────────────────────

    /**
     * Sin permiso de link automático, `PAYMENT_HANDOFF` describiría algo que el
     * sistema no puede hacer. Por eso deja de ser un destino válido: en la v1,
     * con el flag apagado, esta fase es inalcanzable.
     */
    public function test_payment_handoff_needs_the_link_permission(): void
    {
        $sinPermiso = $this->m->allowedTransitions(P::CLOSING, ['can_offer_link' => false]);
        $this->assertNotContains(P::PAYMENT_HANDOFF, $sinPermiso);

        $conPermiso = $this->m->allowedTransitions(P::CLOSING, ['can_offer_link' => true]);
        $this->assertContains(P::PAYMENT_HANDOFF, $conPermiso);

        // Por defecto (contexto vacío) se asume que NO: fail closed.
        $this->assertNotContains(P::PAYMENT_HANDOFF, $this->m->allowedTransitions(P::CLOSING));
    }

    // ── Mapeo a la taxonomía legada ───────────────────────────────────────────

    public function test_every_phase_maps_to_a_legacy_lead_stage(): void
    {
        $validas = [
            SalesIntents::LEAD_STAGE_NEW, SalesIntents::LEAD_STAGE_INFORMED,
            SalesIntents::LEAD_STAGE_INTERESTED, SalesIntents::LEAD_STAGE_READY_TO_PAY,
            SalesIntents::LEAD_STAGE_NEEDS_HUMAN, SalesIntents::LEAD_STAGE_LOST,
        ];

        foreach (P::PHASES as $fase) {
            $this->assertContains($this->m->toLeadStage($fase), $validas, "Mapeo de {$fase}");
        }

        $this->assertSame(SalesIntents::LEAD_STAGE_NEW, $this->m->toLeadStage(P::NEW_LEAD));
        $this->assertSame(SalesIntents::LEAD_STAGE_READY_TO_PAY, $this->m->toLeadStage(P::CLOSING));
        $this->assertSame(SalesIntents::LEAD_STAGE_NEEDS_HUMAN, $this->m->toLeadStage(P::HUMAN_HANDOFF));
        $this->assertSame(SalesIntents::LEAD_STAGE_LOST, $this->m->toLeadStage(P::DO_NOT_CONTACT));
        // Una fase desconocida no revienta: cae al principio.
        $this->assertSame(SalesIntents::LEAD_STAGE_NEW, $this->m->toLeadStage('NO_EXISTE'));
    }

    public function test_barriers_are_a_closed_vocabulary(): void
    {
        foreach (['price', 'time', 'beginner_fear', 'body_insecurity', 'not_ready'] as $b) {
            $this->assertTrue($this->m->isBarrier($b));
        }
        $this->assertFalse($this->m->isBarrier('no_tiene_plata'));
        $this->assertFalse($this->m->isBarrier(null));
    }

    // ── M1 — columnas de la conversación ──────────────────────────────────────

    public function test_m1_columns_exist_and_legacy_ones_survive(): void
    {
        foreach (['commercial_phase', 'main_barrier', 'recommended_plan_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('marketing_conversations', $col), "falta {$col}");
        }
        // Las de siempre conviven: no se sustituyó nada.
        foreach (['lead_stage', 'lead_score', 'primary_intent', 'last_intent', 'detected_objective'] as $col) {
            $this->assertTrue(Schema::hasColumn('marketing_conversations', $col), "se perdió {$col}");
        }
    }

    public function test_m1_recommended_plan_is_nulled_when_the_plan_disappears(): void
    {
        $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'name' => 'L']);
        $conv = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'commercial_phase' => P::RECOMMENDATION, 'main_barrier' => 'price',
            'recommended_plan_id' => $plan->id,
        ]);

        $this->assertSame($plan->id, $conv->fresh()->recommended_plan_id);

        // Borrar el plan no puede llevarse por delante el historial.
        $plan->delete();
        $conv->refresh();
        $this->assertNull($conv->recommended_plan_id);
        $this->assertSame(P::RECOMMENDATION, $conv->commercial_phase);
        $this->assertSame('price', $conv->main_barrier);
    }

    // ── M2 — procedencia e idempotencia ───────────────────────────────────────

    public function test_m2_columns_exist(): void
    {
        foreach (['source_type', 'source_event_id', 'idempotency_key'] as $col) {
            $this->assertTrue(Schema::hasColumn('marketing_ai_actions', $col), "falta {$col}");
        }
    }

    /** Las filas de siempre no llevan clave y tienen que seguir cabiendo. */
    public function test_m2_allows_many_legacy_rows_with_null_key(): void
    {
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'name' => 'L']);

        foreach (range(1, 3) as $i) {
            MarketingAiAction::create([
                'lead_id' => $lead->id, 'action_type' => 'reply', 'status' => 'proposed',
            ]);
        }

        $this->assertSame(3, MarketingAiAction::whereNull('idempotency_key')->count());
    }

    public function test_m2_idempotency_key_is_unique_in_the_database(): void
    {
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'name' => 'L']);

        MarketingAiAction::create([
            'lead_id' => $lead->id, 'action_type' => 'reply', 'status' => 'executed',
            'source_type' => 'inbound_message', 'source_event_id' => 812,
            'idempotency_key' => 'wamid.ABC123',
        ]);

        // El segundo insert con la misma clave falla en la BD, no en una
        // comprobación lógica que dos procesos concurrentes pueden pasar a la vez.
        $this->expectException(QueryException::class);

        MarketingAiAction::create([
            'lead_id' => $lead->id, 'action_type' => 'reply', 'status' => 'executed',
            'source_type' => 'inbound_message', 'source_event_id' => 812,
            'idempotency_key' => 'wamid.ABC123',
        ]);
    }

    public function test_m2_source_fields_round_trip(): void
    {
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'name' => 'L']);

        $a = MarketingAiAction::create([
            'lead_id' => $lead->id, 'action_type' => 'reply', 'status' => 'executed',
            'source_type' => 'inbound_message', 'source_event_id' => 991,
            'idempotency_key' => 'wamid.XYZ',
        ]);

        $a->refresh();
        $this->assertSame('inbound_message', $a->source_type);
        $this->assertSame(991, (int) $a->source_event_id);
        $this->assertSame('wamid.XYZ', $a->idempotency_key);

        // Y la forma prevista para la v2 cabe en la misma columna.
        $b = MarketingAiAction::create([
            'lead_id' => $lead->id, 'action_type' => 'reply', 'status' => 'proposed',
            'source_type' => 'followup', 'source_event_id' => 41,
            'idempotency_key' => 'followup:41:2',
        ]);
        $this->assertSame('followup:41:2', $b->fresh()->idempotency_key);
    }
}
