<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Inbox CRM de WhatsApp (Fase 2A). Opera conversaciones desde el CRM con Meta
 * apagado (dry_run). Invariante crítica: la IA NUNCA se apaga sola; solo el
 * takeover manual (o pause_ai=true) puede poner human_takeover=true.
 */
class InboxTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;
    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        Http::fake();

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead Demo', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp',
            'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/admin/marketing/inbox'.$suffix;
    }

    // ── Lectura ────────────────────────────────────────────────────────────────

    public function test_lists_conversations(): void
    {
        MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => 'inbound',
            'sender_type' => 'lead', 'body' => 'hola quiero info',
        ]);

        $this->adminGetJson($this->url('/conversations'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.0.id', $this->conversation->id)
            ->assertJsonPath('data.0.lead_name', 'Lead Demo')
            ->assertJsonPath('data.0.ai_enabled', true);
    }

    public function test_shows_detail_with_messages_ordered(): void
    {
        MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => 'inbound', 'sender_type' => 'lead', 'body' => 'primero']);
        MarketingMessage::create(['conversation_id' => $this->conversation->id, 'direction' => 'outbound', 'sender_type' => 'ai', 'body' => 'segundo']);

        $res = $this->adminGetJson($this->url('/conversations/'.$this->conversation->id))
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'primero')
            ->assertJsonPath('data.messages.1.body', 'segundo');

        // No expone metadata cruda del proveedor.
        $this->assertArrayNotHasKey('metadata', $res->json('data.messages.0'));
    }

    public function test_opening_detail_resets_unread(): void
    {
        $this->conversation->forceFill(['unread_count' => 3])->save();

        $this->adminGetJson($this->url('/conversations/'.$this->conversation->id))->assertOk();

        $this->assertSame(0, (int) $this->conversation->fresh()->unread_count);
    }

    public function test_detail_404_for_missing(): void
    {
        $this->adminGetJson($this->url('/conversations/999999'))->assertStatus(404);
    }

    // ── Envío manual ────────────────────────────────────────────────────────────

    public function test_manual_message_dry_run_with_meta_off(): void
    {
        $this->adminPostJson($this->url('/conversations/'.$this->conversation->id.'/messages'), [
            'body' => 'Hola, te ayudo por aquí.',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('sent', false)
            ->assertJsonPath('ai_paused', false);

        $this->assertDatabaseHas('marketing_messages', [
            'conversation_id' => $this->conversation->id,
            'direction'       => 'outbound',
            'sender_type'     => 'human',
        ]);
    }

    public function test_manual_message_records_author(): void
    {
        $admin = Admin::create(['name' => 'Asesor Uno', 'email' => 'a1@ironbody.test', 'password' => 'x', 'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active']);

        $this->postJson($this->url('/conversations/'.$this->conversation->id.'/messages'),
            ['body' => 'mensaje con autor'],
            $this->actingAsAdmin($admin),
        )->assertOk();

        $this->assertDatabaseHas('marketing_messages', [
            'conversation_id' => $this->conversation->id,
            'sender_type'     => 'human',
            'sender_user_id'  => $admin->id,
        ]);
    }

    public function test_manual_message_without_pause_ai_does_not_disable_ai(): void
    {
        $this->adminPostJson($this->url('/conversations/'.$this->conversation->id.'/messages'), [
            'body' => 'respuesta normal', 'pause_ai' => false,
        ])->assertOk()->assertJsonPath('ai_paused', false);

        $fresh = $this->conversation->fresh();
        $this->assertTrue((bool) $fresh->ai_enabled);
        $this->assertFalse((bool) $fresh->human_takeover);
    }

    public function test_manual_message_with_pause_ai_pauses_manually(): void
    {
        $this->adminPostJson($this->url('/conversations/'.$this->conversation->id.'/messages'), [
            'body' => 'tomo yo la conversación', 'pause_ai' => true,
        ])->assertOk()->assertJsonPath('ai_paused', true);

        $fresh = $this->conversation->fresh();
        $this->assertFalse((bool) $fresh->ai_enabled);
        $this->assertTrue((bool) $fresh->human_takeover);
        $this->assertSame('manual', $fresh->human_takeover_source);
    }

    public function test_manual_message_blocked_for_do_not_contact(): void
    {
        $this->lead->update(['do_not_contact' => true]);

        $this->adminPostJson($this->url('/conversations/'.$this->conversation->id.'/messages'), [
            'body' => 'hola',
        ])->assertStatus(422)->assertJsonPath('code', 'dnc_blocked');

        $this->assertDatabaseMissing('marketing_messages', ['conversation_id' => $this->conversation->id, 'direction' => 'outbound']);
    }

    // ── Takeover / release ──────────────────────────────────────────────────────

    public function test_takeover_sets_manual_human_takeover(): void
    {
        $admin = Admin::create(['name' => 'Asesor', 'email' => 'a2@ironbody.test', 'password' => 'x', 'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active']);

        $this->postJson($this->url('/conversations/'.$this->conversation->id.'/takeover'),
            ['reason' => 'cliente molesto'],
            $this->actingAsAdmin($admin),
        )->assertOk()->assertJsonPath('human_takeover', true)->assertJsonPath('ai_enabled', false);

        $fresh = $this->conversation->fresh();
        $this->assertTrue((bool) $fresh->human_takeover);
        $this->assertSame('manual', $fresh->human_takeover_source);
        $this->assertFalse((bool) $fresh->ai_enabled);
        $this->assertNotNull($fresh->manual_takeover_at);
        $this->assertSame($admin->id, (int) $fresh->manual_takeover_by);
        $this->assertDatabaseHas('marketing_ai_actions', ['conversation_id' => $this->conversation->id, 'action_type' => 'human_takeover']);
    }

    public function test_release_reactivates_ai(): void
    {
        $this->conversation->forceFill(['human_takeover' => true, 'human_takeover_source' => 'manual', 'ai_enabled' => false])->save();

        $this->adminPostJson($this->url('/conversations/'.$this->conversation->id.'/release'))
            ->assertOk()->assertJsonPath('human_takeover', false)->assertJsonPath('ai_enabled', true);

        $fresh = $this->conversation->fresh();
        $this->assertFalse((bool) $fresh->human_takeover);
        $this->assertNull($fresh->human_takeover_source);
        $this->assertTrue((bool) $fresh->ai_enabled);
    }

    // ── Asignación / notas / tags ───────────────────────────────────────────────

    public function test_assigns_advisor(): void
    {
        $advisor = Admin::create(['name' => 'Carlos', 'email' => 'carlos@ironbody.test', 'password' => 'x', 'role' => Admin::ROLE_ADMINISTRADOR, 'status' => 'active']);

        $this->adminPostJson($this->url('/conversations/'.$this->conversation->id.'/assign'), [
            'assigned_to_admin_id' => $advisor->id,
        ])->assertOk()->assertJsonPath('assigned_to.id', $advisor->id);

        $this->assertSame($advisor->id, (int) $this->conversation->fresh()->assigned_to_admin_id);
        // No apaga la IA.
        $this->assertTrue((bool) $this->conversation->fresh()->ai_enabled);
    }

    public function test_assign_rejects_unknown_admin(): void
    {
        $this->adminPostJson($this->url('/conversations/'.$this->conversation->id.'/assign'), [
            'assigned_to_admin_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_adds_internal_note(): void
    {
        $this->adminPostJson($this->url('/conversations/'.$this->conversation->id.'/notes'), [
            'body' => 'Cliente pidió llamar mañana',
        ])->assertOk()->assertJsonPath('note.body', 'Cliente pidió llamar mañana');

        $this->assertDatabaseHas('marketing_conversation_notes', ['conversation_id' => $this->conversation->id]);
        // La nota NO sale por WhatsApp.
        $this->assertDatabaseMissing('marketing_messages', ['conversation_id' => $this->conversation->id, 'direction' => 'outbound']);
    }

    public function test_adds_and_removes_tags(): void
    {
        $this->adminPostJson($this->url('/conversations/'.$this->conversation->id.'/tags'), [
            'add' => ['Pago', 'Caliente'], 'remove' => [],
        ])->assertOk()->assertJsonPath('tags', ['caliente', 'pago']);

        $this->adminPostJson($this->url('/conversations/'.$this->conversation->id.'/tags'), [
            'add' => [], 'remove' => ['pago'],
        ])->assertOk()->assertJsonPath('tags', ['caliente']);
    }

    // ── Estado / staff_review ───────────────────────────────────────────────────

    public function test_status_closed_does_not_disable_ai(): void
    {
        $this->adminPatchJson($this->url('/conversations/'.$this->conversation->id.'/status'), [
            'status' => 'closed',
        ])->assertOk()->assertJsonPath('status', 'closed');

        $fresh = $this->conversation->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertNotNull($fresh->closed_at);
        $this->assertTrue((bool) $fresh->ai_enabled);
        $this->assertFalse((bool) $fresh->human_takeover);
    }

    public function test_resolve_staff_review_does_not_disable_ai(): void
    {
        $this->conversation->forceFill(['staff_review_pending' => true, 'staff_review_reason' => 'payment'])->save();

        $this->adminPostJson($this->url('/conversations/'.$this->conversation->id.'/staff-review/resolve'), [
            'note' => 'resuelto por caja',
        ])->assertOk()->assertJsonPath('staff_review_pending', false);

        $fresh = $this->conversation->fresh();
        $this->assertFalse((bool) $fresh->staff_review_pending);
        $this->assertNotNull($fresh->staff_review_resolved_at);
        // CRÍTICO: resolver NO apaga la IA.
        $this->assertTrue((bool) $fresh->ai_enabled);
        $this->assertFalse((bool) $fresh->human_takeover);
    }

    // ── Invariante: solo takeover puede poner human_takeover=true ────────────────

    public function test_only_takeover_endpoint_can_set_human_takeover(): void
    {
        $cid = $this->conversation->id;

        // Mensaje sin pause_ai, asignar, nota, tag, cerrar, resolver review:
        // ninguno debe poner human_takeover=true.
        $this->adminPostJson($this->url("/conversations/$cid/messages"), ['body' => 'hola'])->assertOk();
        $this->adminPostJson($this->url("/conversations/$cid/notes"), ['body' => 'n'])->assertOk();
        $this->adminPostJson($this->url("/conversations/$cid/tags"), ['add' => ['x']])->assertOk();
        $this->adminPatchJson($this->url("/conversations/$cid/status"), ['status' => 'closed'])->assertOk();
        $this->conversation->forceFill(['staff_review_pending' => true])->save();
        $this->adminPostJson($this->url("/conversations/$cid/staff-review/resolve"))->assertOk();

        $this->assertFalse((bool) $this->conversation->fresh()->human_takeover);

        // Solo el endpoint takeover lo activa.
        $this->adminPostJson($this->url("/conversations/$cid/takeover"))->assertOk();
        $this->assertTrue((bool) $this->conversation->fresh()->human_takeover);
    }

    // ── Métricas ────────────────────────────────────────────────────────────────

    public function test_metrics_returns_basic_counts(): void
    {
        $this->adminGetJson($this->url('/metrics'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['data' => [
                'open_conversations', 'unassigned', 'unread_total', 'staff_review_pending',
                'handled_by_ai', 'handled_by_human', 'first_response_time_avg_seconds', 'conversations_by_status',
            ]]);
    }

    // ── Seguridad ───────────────────────────────────────────────────────────────

    public function test_requires_admin_auth(): void
    {
        $this->getJson($this->url('/conversations'))->assertStatus(401);
    }
}
