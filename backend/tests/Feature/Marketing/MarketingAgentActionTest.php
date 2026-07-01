<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingAgentAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Acciones CRM del agente (Fase 4C). Human-in-the-loop: recomendar, ejecutar de
 * forma segura (whitelist por tipo), permisos, dedupe, payload inválido, y que
 * draft_reply NUNCA envía WhatsApp.
 */
class MarketingAgentActionTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;
    private MarketingConversation $conversation;
    private array $saHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.driver', 'fake');
        Http::fake();

        $super = Admin::create(['name' => 'Super', 'email' => 'super-aa@ironbody.test', 'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $this->saHeaders = $this->actingAsAdmin($super);

        $this->lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026', 'name' => 'Lead AA', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
    }

    private function url(string $s = ''): string
    {
        return '/api/admin/marketing/agent-actions'.$s;
    }

    private function inbound(string $body): void
    {
        MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'sender_type' => 'lead', 'body' => $body]);
    }

    private function headersFor(string $role, string $status = 'active'): array
    {
        $admin = Admin::create(['name' => 'R '.$role, 'email' => 'aa-'.uniqid().'@ironbody.test', 'password' => 'x', 'role' => $role, 'status' => $status]);

        return $this->actingAsAdmin($admin);
    }

    private function suggestion(string $type, array $payload = [], array $extra = []): MarketingAgentAction
    {
        return MarketingAgentAction::create(array_merge([
            'marketing_lead_id'         => $this->lead->id,
            'marketing_conversation_id' => $this->conversation->id,
            'suggested_by'              => 'ai',
            'action_type'               => $type,
            'status'                    => 'suggested',
            'priority'                  => 'normal',
            'title'                     => 'Test '.$type,
            'payload'                   => $payload,
        ], $extra));
    }

    // ── Recomendación ────────────────────────────────────────────────────────

    public function test_recommend_generates_actions_for_price(): void
    {
        $this->inbound('hola, cuanto vale la mensualidad?');

        $res = $this->postJson($this->url('/recommend'), ['marketing_conversation_id' => $this->conversation->id], $this->saHeaders)
            ->assertStatus(200) // nunca 204
            ->assertJsonPath('ok', true);

        $this->assertGreaterThan(0, $res->json('created_count'));
        $types = collect($res->json('actions'))->pluck('action_type')->all();
        $this->assertContains('add_tag', $types);
        $this->assertContains('draft_reply', $types);
    }

    public function test_recommend_generates_actions_for_plan_info(): void
    {
        // Mensaje comercial real que ANTES no generaba nada.
        $this->inbound('quiero informacion de planes, plan mensual');

        $res = $this->postJson($this->url('/recommend'), ['marketing_conversation_id' => $this->conversation->id], $this->saHeaders)
            ->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->assertGreaterThan(0, $res->json('created_count'));
        $types = collect($res->json('actions'))->pluck('action_type')->all();
        $this->assertContains('add_tag', $types);
        $this->assertContains('draft_reply', $types);
    }

    public function test_recommend_returns_json_never_204(): void
    {
        $this->inbound('cuanto cuesta?');
        $res = $this->postJson($this->url('/recommend'), ['marketing_conversation_id' => $this->conversation->id], $this->saHeaders);

        $res->assertStatus(200);
        $this->assertNotSame(204, $res->getStatusCode());
        $res->assertJsonStructure(['ok', 'created_count', 'actions', 'skipped', 'reason', 'message']);
    }

    public function test_recommend_without_messages_returns_reason(): void
    {
        // Conversación sin mensajes del lead.
        $this->postJson($this->url('/recommend'), ['marketing_conversation_id' => $this->conversation->id], $this->saHeaders)
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('reason', 'conversation_has_no_messages');

        $this->assertDatabaseCount('marketing_agent_actions', 0);
    }

    public function test_recommend_duplicates_returns_skipped(): void
    {
        $this->inbound('cuanto cuesta el plan?');
        $first = $this->postJson($this->url('/recommend'), ['marketing_conversation_id' => $this->conversation->id], $this->saHeaders)->assertStatus(200);
        $this->assertGreaterThan(0, $first->json('created_count'));
        $firstCount = MarketingAgentAction::count();

        // Segundo análisis: no crea nuevas, pero devuelve skipped con detalle.
        $res = $this->postJson($this->url('/recommend'), ['marketing_conversation_id' => $this->conversation->id], $this->saHeaders)
            ->assertStatus(200)
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('reason', 'all_suggestions_deduplicated');

        $this->assertNotEmpty($res->json('skipped'));
        $this->assertSame($firstCount, MarketingAgentAction::count());
    }

    public function test_recommend_for_conversation_route(): void
    {
        $this->inbound('quiero hablar con una persona');

        $this->postJson('/api/admin/marketing/inbox/conversations/'.$this->conversation->id.'/agent-actions/recommend', [], $this->saHeaders)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('marketing_agent_actions', ['marketing_conversation_id' => $this->conversation->id, 'action_type' => 'request_staff_review']);
    }

    public function test_recommend_does_not_duplicate_open_actions(): void
    {
        $this->inbound('cuanto cuesta?');
        $this->postJson($this->url('/recommend'), ['marketing_conversation_id' => $this->conversation->id], $this->saHeaders)->assertOk();
        $firstCount = MarketingAgentAction::where('marketing_conversation_id', $this->conversation->id)->count();

        // Segundo análisis: no debe duplicar acciones abiertas del mismo tipo.
        $this->postJson($this->url('/recommend'), ['marketing_conversation_id' => $this->conversation->id], $this->saHeaders)->assertOk();
        $secondCount = MarketingAgentAction::where('marketing_conversation_id', $this->conversation->id)->count();

        $this->assertSame($firstCount, $secondCount);
    }

    public function test_recommend_skips_staff_review_if_already_pending(): void
    {
        $this->conversation->forceFill(['staff_review_pending' => true])->save();
        $this->inbound('quiero hablar con alguien');

        $this->postJson($this->url('/recommend'), ['marketing_conversation_id' => $this->conversation->id], $this->saHeaders)->assertOk();

        $this->assertDatabaseMissing('marketing_agent_actions', ['marketing_conversation_id' => $this->conversation->id, 'action_type' => 'request_staff_review']);
    }

    // ── Ejecución segura ─────────────────────────────────────────────────────

    public function test_execute_create_note_creates_note(): void
    {
        $a = $this->suggestion('create_note', ['body' => 'Cliente pidió llamar']);

        $this->postJson($this->url("/{$a->id}/execute"), [], $this->saHeaders)
            ->assertOk()->assertJsonPath('data.status', 'executed');

        $this->assertDatabaseHas('marketing_conversation_notes', ['conversation_id' => $this->conversation->id, 'body' => 'Cliente pidió llamar']);
    }

    public function test_execute_add_tag_creates_tag(): void
    {
        $a = $this->suggestion('add_tag', ['tag' => 'lead-caliente']);

        $this->postJson($this->url("/{$a->id}/execute"), [], $this->saHeaders)
            ->assertOk()->assertJsonPath('data.status', 'executed');

        $this->assertDatabaseHas('marketing_conversation_tags', ['conversation_id' => $this->conversation->id, 'tag' => 'lead-caliente']);
    }

    public function test_execute_create_appointment_creates_appointment(): void
    {
        $a = $this->suggestion('create_appointment', ['type' => 'visit', 'title' => 'Visita', 'scheduled_at' => now()->addDay()->toIso8601String()]);

        $this->postJson($this->url("/{$a->id}/execute"), [], $this->saHeaders)
            ->assertOk()->assertJsonPath('data.status', 'executed');

        $this->assertDatabaseHas('marketing_appointments', ['marketing_conversation_id' => $this->conversation->id, 'marketing_lead_id' => $this->lead->id, 'type' => 'visit']);
    }

    public function test_execute_create_follow_up_persists_conversation(): void
    {
        $a = $this->suggestion('create_follow_up', ['due_at' => now()->addDay()->toIso8601String(), 'type' => 'call', 'reason' => 'seguir interés']);

        $this->postJson($this->url("/{$a->id}/execute"), [], $this->saHeaders)
            ->assertOk()->assertJsonPath('data.status', 'executed');

        $this->assertDatabaseHas('marketing_followups', [
            'lead_id'                   => $this->lead->id,
            'marketing_conversation_id' => $this->conversation->id,
            'status'                    => 'pending',
        ]);
    }

    public function test_execute_draft_reply_does_not_send_whatsapp(): void
    {
        $a = $this->suggestion('draft_reply', ['draft' => 'Hola, te comparto info']);

        $this->postJson($this->url("/{$a->id}/execute"), [], $this->saHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', 'executed')
            ->assertJsonPath('data.result.sent', false);

        // No se creó ningún mensaje saliente.
        $this->assertDatabaseMissing('marketing_messages', ['conversation_id' => $this->conversation->id, 'direction' => 'outbound']);
        Http::assertNothingSent();
    }

    public function test_execute_request_staff_review_does_not_disable_ai(): void
    {
        $a = $this->suggestion('request_staff_review', ['reason' => 'human_requested']);

        $this->postJson($this->url("/{$a->id}/execute"), [], $this->saHeaders)->assertOk();

        $fresh = $this->conversation->fresh();
        $this->assertTrue((bool) $fresh->staff_review_pending);
        $this->assertTrue((bool) $fresh->ai_enabled); // NO apaga la IA
    }

    public function test_execute_update_lead_profile_uses_real_columns(): void
    {
        $a = $this->suggestion('update_lead_profile', ['temperature' => 'hot', 'stage' => 'interested']);

        $this->postJson($this->url("/{$a->id}/execute"), [], $this->saHeaders)->assertOk();

        $this->assertSame('hot', $this->lead->fresh()->temperature);
        $this->assertSame('interested', $this->conversation->fresh()->lead_stage);
    }

    public function test_execute_invalid_payload_fails_with_reason(): void
    {
        $a = $this->suggestion('create_note', []); // falta body

        $this->postJson($this->url("/{$a->id}/execute"), [], $this->saHeaders)
            ->assertStatus(422)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failed_reason', 'note_body_required');
    }

    public function test_create_appointment_without_date_fails(): void
    {
        $a = $this->suggestion('create_appointment', ['title' => 'Visita']); // sin scheduled_at

        $this->postJson($this->url("/{$a->id}/execute"), [], $this->saHeaders)
            ->assertStatus(422)
            ->assertJsonPath('data.failed_reason', 'scheduled_at_required');

        $this->assertDatabaseCount('marketing_appointments', 0);
    }

    // ── Transiciones ─────────────────────────────────────────────────────────

    public function test_reject_changes_status(): void
    {
        $a = $this->suggestion('add_tag', ['tag' => 'precio']);

        $this->postJson($this->url("/{$a->id}/reject"), ['reason' => 'no aplica'], $this->saHeaders)
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'no aplica');
    }

    public function test_cannot_execute_rejected_action(): void
    {
        $a = $this->suggestion('add_tag', ['tag' => 'precio'], ['status' => 'rejected']);

        $this->postJson($this->url("/{$a->id}/execute"), [], $this->saHeaders)->assertStatus(422)->assertJsonPath('code', 'agent_action_not_executable');
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_requires_admin_auth(): void
    {
        $this->getJson($this->url('/'))->assertStatus(401);
    }

    public function test_inactive_admin_blocked(): void
    {
        $this->getJson($this->url('/'), $this->headersFor(Admin::ROLE_SUPER_ADMIN, 'disabled'))->assertStatus(403);
    }

    public function test_unknown_role_blocked(): void
    {
        $this->getJson($this->url('/'), $this->headersFor('Bodeguero'))->assertStatus(403);
    }

    public function test_advisor_can_execute_safe_action(): void
    {
        $h = $this->headersFor(Admin::ROLE_RECEPCION);
        $a = $this->suggestion('create_note', ['body' => 'nota asesor']);

        $this->postJson($this->url("/{$a->id}/execute"), [], $h)->assertOk()->assertJsonPath('data.status', 'executed');
    }

    public function test_advisor_cannot_execute_restricted_action(): void
    {
        // pause_ai es FULL-only.
        $h = $this->headersFor(Admin::ROLE_RECEPCION);
        $a = $this->suggestion('pause_ai', ['reason' => 'x']);

        $this->postJson($this->url("/{$a->id}/execute"), [], $h)
            ->assertStatus(403)->assertJsonPath('code', 'agent_action_type_forbidden');

        // No se ejecutó: la IA sigue activa.
        $this->assertTrue((bool) $this->conversation->fresh()->ai_enabled);
    }

    public function test_capabilities_reflect_role(): void
    {
        $this->getJson($this->url('/capabilities'), $this->saHeaders)
            ->assertOk()->assertJsonPath('data.can_approve', true);

        $this->getJson($this->url('/capabilities'), $this->headersFor(Admin::ROLE_RECEPCION))
            ->assertOk()
            ->assertJsonPath('data.can_approve', false)
            ->assertJsonPath('data.can_execute', true);
    }

    public function test_capabilities_executable_types_excludes_restricted_for_advisor(): void
    {
        $types = $this->getJson($this->url('/capabilities'), $this->headersFor(Admin::ROLE_RECEPCION))->json('data.executable_types');
        $this->assertContains('create_note', $types);
        $this->assertNotContains('pause_ai', $types);
        $this->assertNotContains('assign_conversation', $types);
    }
}
