<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolExecutor;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MarketingManualTakeoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `lead.status = needs_human` abre la puerta de la derivación a una persona
 * (decide y commit lo leen como hecho). Lo escribe el escalado del motor y
 * nadie lo quitaba: al devolver la conversación a la IA, el lead seguía
 * «necesitando humano» para siempre, y con ello quedaba abierta una
 * derivación futura que nadie había autorizado. En producción había dos leads
 * así, uno desde junio.
 */
class LeadNeedsHumanLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

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
        config()->set('commercial.autonomy_enabled', true);
        config()->set('commercial.tools', ['catalog' => true, 'lead' => true, 'payments' => true, 'memberships' => true, 'agenda' => true, 'invoicing' => true, 'app' => true]);
        Http::fake();

        Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'benefits' => json_encode(['Acceso'])]);
        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_INTERESTED]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::RECOMMENDATION]);
    }

    private function escalate(): void
    {
        app(ToolExecutor::class)->execute('escalate_to_human', ['reason' => 'customer_request'], new ToolContext(
            lead: $this->lead, conversation: $this->conversation, requestedBy: 'engine', correlationId: 'lifecycle', idempotencyKey: uniqid('k', true),
        ));
    }

    /** @return string[] */
    private function allowedTransitions(string $body, string $wamid): array
    {
        $m = MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received']);

        return $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id], ['Authorization' => 'Bearer '.self::SECRET])
            ->assertOk()->json('allowed_transitions');
    }

    public function test_escalation_marks_the_lead_and_release_restores_what_it_was(): void
    {
        $this->escalate();

        $lead = $this->lead->fresh();
        $this->assertSame(MarketingLead::STATUS_NEEDS_HUMAN, $lead->status);
        $this->assertSame(MarketingLead::STATUS_INTERESTED, $lead->metadata['status_before_human'] ?? null, 'se recuerda de dónde venía');
        $this->assertTrue((bool) $this->conversation->fresh()->human_takeover);

        app(MarketingManualTakeoverService::class)->release($this->conversation->fresh(), 1);

        $lead = $this->lead->fresh();
        $this->assertSame(MarketingLead::STATUS_INTERESTED, $lead->status, 'devolver la conversación a la IA cierra la petición de humano');
        $this->assertNotNull($lead->last_human_takeover_at, 'la historia se conserva: se ve que pasó por una persona');
        $this->assertFalse((bool) $this->conversation->fresh()->human_takeover);
    }

    public function test_a_lead_stuck_in_needs_human_without_history_is_released_to_interested(): void
    {
        $this->lead->forceFill(['status' => MarketingLead::STATUS_NEEDS_HUMAN])->save();
        $svc = app(MarketingManualTakeoverService::class);
        $svc->takeover($this->conversation, 1, 'customer_asked');

        $svc->release($this->conversation->fresh(), 1);

        $this->assertSame(MarketingLead::STATUS_INTERESTED, $this->lead->fresh()->status);
    }

    public function test_after_release_the_model_has_no_standing_permit_to_hand_off(): void
    {
        $this->escalate();
        app(MarketingManualTakeoverService::class)->release($this->conversation->fresh(), 1);

        $this->assertNotContains(P::HUMAN_HANDOFF, $this->allowedTransitions('hola, sigo interesado', 'wamid.l.1'), 'sin petición ni takeover, derivar no está en el menú');
    }

    public function test_a_person_marking_the_lead_from_the_crm_still_opens_the_door(): void
    {
        $this->lead->forceFill(['status' => MarketingLead::STATUS_NEEDS_HUMAN])->save();

        $this->assertContains(P::HUMAN_HANDOFF, $this->allowedTransitions('hola', 'wamid.l.2'), 'needs_human puesto a mano es una instrucción del equipo');
    }

    public function test_manual_takeover_and_release_leave_the_lead_status_alone(): void
    {
        $svc = app(MarketingManualTakeoverService::class);
        $svc->takeover($this->conversation, 1, 'conflict');
        $this->assertTrue((bool) $this->conversation->fresh()->human_takeover);
        $this->assertSame(MarketingLead::STATUS_INTERESTED, $this->lead->fresh()->status);

        $svc->release($this->conversation->fresh(), 1);
        $this->assertFalse((bool) $this->conversation->fresh()->human_takeover);
        $this->assertSame(MarketingLead::STATUS_INTERESTED, $this->lead->fresh()->status);
    }

    /** Hallazgo del revisor: liberar una conversación que nunca estuvo en manos de una persona no borra una marca puesta a mano. */
    public function test_releasing_a_conversation_that_was_never_in_human_hands_keeps_a_manual_mark(): void
    {
        $this->lead->forceFill(['status' => MarketingLead::STATUS_NEEDS_HUMAN])->save();
        $this->assertFalse((bool) $this->conversation->human_takeover);

        app(MarketingManualTakeoverService::class)->release($this->conversation->fresh(), 1);

        $this->assertSame(MarketingLead::STATUS_NEEDS_HUMAN, $this->lead->fresh()->status, 'la petición la cierra quien la tenía, no cualquier release');
    }

    /** Hallazgo del revisor: el recuerdo se consume; un escalado de hace meses no decide el estado de hoy. */
    public function test_the_remembered_status_is_consumed_and_never_restored_twice(): void
    {
        $this->lead->forceFill(['status' => MarketingLead::STATUS_HOT])->save();
        $svc = app(MarketingManualTakeoverService::class);
        $this->escalate();
        $svc->release($this->conversation->fresh(), 1);
        $this->assertSame(MarketingLead::STATUS_HOT, $this->lead->fresh()->status);
        $this->assertArrayNotHasKey('status_before_human', $this->lead->fresh()->metadata ?? [], 'consumido');

        // Meses después: se enfría, alguien lo marca a mano y toma el control.
        $this->lead->forceFill(['status' => MarketingLead::STATUS_COLD])->save();
        $this->lead->forceFill(['status' => MarketingLead::STATUS_NEEDS_HUMAN])->save();
        $svc->takeover($this->conversation->fresh(), 1, 'conflict');
        $svc->release($this->conversation->fresh(), 1);

        $this->assertSame(MarketingLead::STATUS_INTERESTED, $this->lead->fresh()->status, 'sin recuerdo válido cae al fallback, nunca al «hot» caducado');
    }

    /** Hallazgo del revisor: si otra conversación del mismo lead sigue en manos de una persona, la petición sigue abierta. */
    public function test_another_conversation_still_in_human_hands_keeps_the_request_open(): void
    {
        $this->escalate();
        MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'instagram', 'status' => 'open', 'ai_enabled' => false, 'human_takeover' => true]);

        app(MarketingManualTakeoverService::class)->release($this->conversation->fresh(), 1);

        $this->assertSame(MarketingLead::STATUS_NEEDS_HUMAN, $this->lead->fresh()->status);
    }
}
