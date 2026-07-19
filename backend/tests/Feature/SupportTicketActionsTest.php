<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberDeviceBinding;
use App\Models\MemberDeviceSession;
use App\Models\MemberSupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acciones rápidas de resolución del CRM sobre tickets de soporte de acceso:
 * actualizar teléfono, revocar dispositivos, restablecer confianza y vincular
 * miembro por documento. Todas exigen sesión admin y miembro vinculado.
 */
class SupportTicketActionsTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $doc = '1010101010', string $phone = '3001234567'): Member
    {
        $user = User::create([
            'name' => 'Ana Prueba',
            'email' => 'ana'.$doc.'@example.com',
            'password' => 'secret',
            'document' => $doc,
            'phone' => $phone,
            'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Ana Prueba',
            'email' => 'ana'.$doc.'@example.com',
            'document_number' => $doc,
            'phone' => $phone,
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function ticket(?Member $member, string $doc = '1010101010'): MemberSupportTicket
    {
        return MemberSupportTicket::create([
            'member_id' => $member?->id,
            'user_id'   => $member?->user_id,
            'document'  => $doc,
            'type'      => 'access',
            'message'   => 'Perdí acceso a mi número.',
            'status'    => MemberSupportTicket::STATUS_NEW,
            'platform'  => 'login',
        ]);
    }

    public function test_admin_can_change_member_phone_from_ticket(): void
    {
        $member = $this->member();
        $ticket = $this->ticket($member);

        $this->postJson("/api/admin/support/{$ticket->id}/phone", [
            'phone' => '3019998877',
        ], $this->actingAsAdmin())
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('3019998877', $member->fresh()->phone);
        $this->assertSame('3019998877', $member->fresh()->user->phone);
        // Deja traza y pasa a en proceso.
        $this->assertSame(MemberSupportTicket::STATUS_IN_PROGRESS, $ticket->fresh()->status);
    }

    public function test_change_phone_rejects_number_used_by_other(): void
    {
        $this->member('2020202020', '3055556666');
        $member = $this->member('3030303030', '3001112222');
        $ticket = $this->ticket($member, '3030303030');

        $this->postJson("/api/admin/support/{$ticket->id}/phone", [
            'phone' => '3055556666',
        ], $this->actingAsAdmin())->assertStatus(422);

        $this->assertSame('3001112222', $member->fresh()->phone);
    }

    public function test_change_phone_rejects_invalid_format(): void
    {
        $member = $this->member();
        $ticket = $this->ticket($member);

        $this->postJson("/api/admin/support/{$ticket->id}/phone", [
            'phone' => '12345',
        ], $this->actingAsAdmin())->assertStatus(422);
    }

    public function test_actions_require_linked_member(): void
    {
        $ticket = $this->ticket(null); // sin miembro vinculado

        $this->postJson("/api/admin/support/{$ticket->id}/phone", [
            'phone' => '3019998877',
        ], $this->actingAsAdmin())->assertStatus(422);
    }

    public function test_admin_can_revoke_all_devices(): void
    {
        $member = $this->member();
        MemberDeviceSession::create([
            'member_id'   => $member->id,
            'device_id'   => 'dev-1',
            'device_name' => 'iPhone',
            'platform'    => 'ios',
            'token_hash'  => hash('sha256', 'session-token-1'),
            'last_seen_at' => now(),
        ]);
        $ticket = $this->ticket($member);

        $this->postJson("/api/admin/support/{$ticket->id}/revoke-devices", [], $this->actingAsAdmin())
            ->assertOk()
            ->assertJsonPath('revoked_count', 1);

        $this->assertSame(0, MemberDeviceSession::where('member_id', $member->id)->whereNull('revoked_at')->count());
    }

    public function test_admin_can_reset_device_trust(): void
    {
        $member = $this->member();
        MemberDeviceBinding::create([
            'device_id'   => 'dev-trusted',
            'member_id'   => $member->id,
            'device_name' => 'iPhone de Ana',
            'platform'    => 'ios',
            'bound_at'    => now(),
        ]);
        $ticket = $this->ticket($member);

        $this->postJson("/api/admin/support/{$ticket->id}/reset-trust", [], $this->actingAsAdmin())
            ->assertOk()
            ->assertJsonPath('released_count', 1);

        $this->assertSame(0, MemberDeviceBinding::where('member_id', $member->id)->count());
    }

    public function test_admin_can_lookup_and_link_member_by_document(): void
    {
        $member = $this->member('4040404040', '3007778888');
        $ticket = $this->ticket(null, '4040404040');

        // Lookup por documento.
        $this->getJson('/api/admin/support/member-lookup?document=4040404040', $this->actingAsAdmin())
            ->assertOk()
            ->assertJsonPath('member.id', $member->id);

        // Vincular al ticket.
        $this->postJson("/api/admin/support/{$ticket->id}/link-member", [
            'member_id' => $member->id,
        ], $this->actingAsAdmin())->assertOk();

        $this->assertSame($member->id, $ticket->fresh()->member_id);

        // Ahora sí se puede actuar sobre el ticket.
        $this->postJson("/api/admin/support/{$ticket->id}/phone", [
            'phone' => '3011122233',
        ], $this->actingAsAdmin())->assertOk();

        $this->assertSame('3011122233', $member->fresh()->phone);
    }

    public function test_context_reports_linked_member(): void
    {
        $member = $this->member();
        $ticket = $this->ticket($member);

        $this->getJson("/api/admin/support/{$ticket->id}/context", $this->actingAsAdmin())
            ->assertOk()
            ->assertJsonPath('linked', true)
            ->assertJsonPath('member.id', $member->id);
    }

    public function test_endpoints_require_admin_auth(): void
    {
        $member = $this->member();
        $ticket = $this->ticket($member);

        $this->postJson("/api/admin/support/{$ticket->id}/phone", ['phone' => '3019998877'])
            ->assertStatus(401);
    }
}
