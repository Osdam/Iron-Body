<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agenda comercial (Fase 4B). Citas con leads de marketing. Permisos por
 * rol/estado + ownership, igual filosofía que el Inbox.
 */
class MarketingAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private MarketingLead $lead;
    private MarketingConversation $conversation;
    private array $saHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        $super = Admin::create(['name' => 'Super', 'email' => 'super-appt@ironbody.test', 'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $this->saHeaders = $this->actingAsAdmin($super);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'name' => 'Lead Agenda', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/admin/marketing/appointments'.$suffix;
    }

    private function headersFor(string $role, string $status = 'active'): array
    {
        $admin = Admin::create([
            'name' => 'Role '.$role, 'email' => 'r-'.uniqid().'@ironbody.test',
            'password' => 'x', 'role' => $role, 'status' => $status,
        ]);

        return $this->actingAsAdmin($admin);
    }

    private function create(array $overrides = [], ?array $headers = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->url('/'), array_merge([
            'type'                      => MarketingAppointment::TYPE_VISIT,
            'title'                     => 'Visita al gimnasio',
            'scheduled_at'              => now()->addDay()->toIso8601String(),
            'duration_minutes'          => 45,
            'marketing_lead_id'         => $this->lead->id,
            'marketing_conversation_id' => $this->conversation->id,
        ], $overrides), $headers ?? $this->saHeaders);
    }

    // ── Creación / vínculo ───────────────────────────────────────────────────

    public function test_super_admin_creates_appointment(): void
    {
        $this->create()
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.type', 'visit')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.duration_minutes', 45);

        $this->assertDatabaseHas('marketing_appointments', [
            'marketing_lead_id' => $this->lead->id,
            'marketing_conversation_id' => $this->conversation->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_appointment_links_lead_and_prefills_contact(): void
    {
        $res = $this->create(['contact_name' => null, 'contact_phone' => null])->assertStatus(201);

        // Precarga nombre/teléfono desde el lead.
        $res->assertJsonPath('data.contact_name', 'Lead Agenda')
            ->assertJsonPath('data.contact_phone', '3150536026')
            ->assertJsonPath('data.lead.id', $this->lead->id);
    }

    public function test_requires_type_and_scheduled_at(): void
    {
        $this->postJson($this->url('/'), ['title' => 'X'], $this->saHeaders)->assertStatus(422);
    }

    // ── Listado / filtros ────────────────────────────────────────────────────

    public function test_lists_upcoming_appointments(): void
    {
        $this->create();
        $this->create(['type' => MarketingAppointment::TYPE_CALL, 'title' => 'Llamada']);

        $this->getJson($this->url('/'), $this->saHeaders)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_filters_by_status_type_and_date(): void
    {
        $a = MarketingAppointment::create(['type' => 'call', 'title' => 'C', 'scheduled_at' => now()->addDays(2), 'status' => 'scheduled', 'marketing_lead_id' => $this->lead->id]);
        MarketingAppointment::create(['type' => 'visit', 'title' => 'V', 'scheduled_at' => now()->addDays(5), 'status' => 'completed', 'marketing_lead_id' => $this->lead->id]);

        $this->getJson($this->url('/?type=call'), $this->saHeaders)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson($this->url('/?status=completed'), $this->saHeaders)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->url('/?date_from='.now()->addDays(4)->toDateString()), $this->saHeaders)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_conversation_appointments_endpoint(): void
    {
        $this->create();

        $this->getJson('/api/admin/marketing/inbox/conversations/'.$this->conversation->id.'/appointments', $this->saHeaders)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(1, 'data');
    }

    // ── Transiciones ─────────────────────────────────────────────────────────

    public function test_completes_appointment(): void
    {
        $id = $this->create()->json('data.id');

        $this->postJson($this->url("/$id/complete"), ['note' => 'asistió'], $this->saHeaders)
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $appt = MarketingAppointment::find($id);
        $this->assertNotNull($appt->completed_at);
    }

    public function test_cancels_appointment_without_deleting(): void
    {
        $id = $this->create()->json('data.id');

        $this->postJson($this->url("/$id/cancel"), ['reason' => 'lead no responde'], $this->saHeaders)
            ->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'lead no responde');

        // No se borra físicamente.
        $this->assertDatabaseHas('marketing_appointments', ['id' => $id, 'status' => 'cancelled']);
        $appt = MarketingAppointment::find($id);
        $this->assertNotNull($appt->cancelled_at);
    }

    public function test_reschedules_appointment(): void
    {
        $id = $this->create()->json('data.id');
        $newAt = now()->addDays(3)->startOfHour();

        $this->postJson($this->url("/$id/reschedule"), ['scheduled_at' => $newAt->toIso8601String(), 'duration_minutes' => 60], $this->saHeaders)
            ->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.duration_minutes', 60);

        $appt = MarketingAppointment::find($id);
        $this->assertSame($newAt->toIso8601String(), $appt->scheduled_at->toIso8601String());
        $this->assertNotEmpty($appt->metadata['reschedules'] ?? []);
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_inactive_admin_is_blocked(): void
    {
        $h = $this->headersFor(Admin::ROLE_SUPER_ADMIN, 'disabled');
        $this->getJson($this->url('/'), $h)->assertStatus(403);
    }

    public function test_unknown_role_is_blocked(): void
    {
        $h = $this->headersFor('Bodeguero');
        $this->getJson($this->url('/'), $h)->assertStatus(403);
        $this->create([], $h)->assertStatus(403);
    }

    public function test_requires_admin_auth(): void
    {
        $this->getJson($this->url('/'))->assertStatus(401);
    }

    public function test_advisor_can_create_and_operate_own(): void
    {
        $h = $this->headersFor(Admin::ROLE_RECEPCION);
        $id = $this->create([], $h)->assertStatus(201)->json('data.id');

        $this->postJson($this->url("/$id/complete"), [], $h)->assertOk();
    }

    public function test_advisor_cannot_operate_other_advisors_appointment(): void
    {
        // Cita asignada a OTRO asesor.
        $other = Admin::create(['name' => 'Otro', 'email' => 'otro@ironbody.test', 'password' => 'x', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active']);
        $appt = MarketingAppointment::create([
            'type' => 'visit', 'title' => 'Ajena', 'scheduled_at' => now()->addDay(),
            'status' => 'scheduled', 'marketing_lead_id' => $this->lead->id, 'assigned_to_admin_id' => $other->id,
        ]);

        $h = $this->headersFor(Admin::ROLE_RECEPCION);
        $this->postJson($this->url("/{$appt->id}/cancel"), [], $h)->assertStatus(403);
        $this->postJson($this->url("/{$appt->id}/complete"), [], $h)->assertStatus(403);
    }

    public function test_advisor_cannot_assign_to_other(): void
    {
        $other = Admin::create(['name' => 'Z', 'email' => 'z@ironbody.test', 'password' => 'x', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active']);
        $h = $this->headersFor(Admin::ROLE_RECEPCION);

        $this->create(['assigned_to_admin_id' => $other->id], $h)->assertStatus(403);
    }

    public function test_capabilities_reflect_role(): void
    {
        $this->getJson($this->url('/capabilities'), $this->saHeaders)
            ->assertOk()->assertJsonPath('data.can_assign', true)->assertJsonPath('data.can_create', true);

        $this->getJson($this->url('/capabilities'), $this->headersFor(Admin::ROLE_RECEPCION))
            ->assertOk()->assertJsonPath('data.can_create', true)->assertJsonPath('data.can_assign', false);
    }
}
