<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\User;
use App\Services\RealtimeEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bus real-time por miembro: cada mutación crítica emite una señal en el canal
 * PRIVADO del miembro y un miembro NUNCA ve eventos de otro. El stream SSE va
 * bajo `auth_member` (el token valida la conexión); aquí se prueba la capa de
 * datos (la conexión SSE se mantiene viva ~25s y no se ejercita por HTTP).
 */
class RealtimeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $doc): Member
    {
        $user = User::create([
            'name' => 'M'.$doc,
            'email' => 'm'.$doc.'@example.com',
            'password' => 'secret',
            'document' => $doc,
            'phone' => '300'.substr($doc, -7),
            'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'M'.$doc,
            'email' => 'm'.$doc.'@example.com',
            'document_number' => $doc,
            'phone' => '300'.substr($doc, -7),
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    public function test_realtime_channel_requires_session(): void
    {
        // Sin token, el middleware rechaza antes de abrir el stream.
        $this->getJson('/api/member/realtime')->assertStatus(401);
    }

    public function test_events_are_scoped_per_member(): void
    {
        $a = $this->member('1010101010');
        $b = $this->member('2020202020');

        RealtimeEvents::membership($a->id);

        $this->assertSame(1, MemberRealtimeEvent::where('member_id', $a->id)->count());
        // El canal de B NO recibe el evento de A (aislamiento de canal privado).
        $this->assertSame(0, MemberRealtimeEvent::where('member_id', $b->id)->count());
    }

    public function test_profile_update_broadcasts_event(): void
    {
        $a = $this->member('1010101010');

        $this->patchJson('/api/member/profile', [
            'full_name' => 'Nombre Actualizado',
        ], $this->auth($a))->assertOk();

        $this->assertTrue(
            MemberRealtimeEvent::where('member_id', $a->id)
                ->where('type', RealtimeEvents::PROFILE)
                ->exists()
        );
    }

    public function test_staff_access_update_broadcasts_live_permissions(): void
    {
        $a = $this->member('1010101010');

        $this->patchJson('/api/admin/members/'.$a->id.'/staff-access', [
            'is_staff' => true,
        ])->assertOk();

        $this->assertTrue(
            MemberRealtimeEvent::where('member_id', $a->id)
                ->where('type', RealtimeEvents::LIVE_PERMS)
                ->exists()
        );
    }

    public function test_payment_approval_broadcasts_event_via_service(): void
    {
        $a = $this->member('1010101010');

        RealtimeEvents::payment($a->id);

        $event = MemberRealtimeEvent::where('member_id', $a->id)
            ->where('type', RealtimeEvents::PAYMENT)
            ->first();
        $this->assertNotNull($event);
        $this->assertContains('membership', $event->changed);
    }
}
