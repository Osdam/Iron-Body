<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberDeviceBinding;
use App\Models\MemberDeviceSession;
use App\Models\MemberSecurityEvent;
use App\Models\MemberSupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Incidente real (2026-09-02): un socio no podía entrar porque su iPhone había
 * quedado vinculado a OTRA cuenta, y "Restablecer confianza" reportaba
 * "0 vínculo(s) liberado(s)".
 *
 * La causa es una asimetría de criterio: el guard del login busca el binding
 * GLOBALMENTE por `device_id`; la acción de soporte buscaba por `member_id`.
 * Como el vínculo era del otro titular, la acción no encontraba nada mientras
 * el login seguía denegando.
 *
 * Estos tests fijan el comportamiento en los dos lados: que `reset-trust` sigue
 * sin resolverlo (no es su cometido) y que `release-device` sí lo resuelve, de
 * forma acotada y auditada.
 */
class DeviceConflictReleaseTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_X = 'dev_conflicto0000000000000000000000000001';

    private function member(string $doc, string $name = 'Socio Prueba'): Member
    {
        $user = User::create([
            'name' => $name,
            'email' => 'socio'.$doc.'@example.com',
            'password' => 'secret',
            'document' => $doc,
            'phone' => '30012'.substr($doc, -5),
            'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'email' => 'socio'.$doc.'@example.com',
            'document_number' => $doc,
            'phone' => '30012'.substr($doc, -5),
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function ticket(Member $member): MemberSupportTicket
    {
        return MemberSupportTicket::create([
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'document' => $member->document_number,
            'type' => 'access',
            'message' => 'Este dispositivo está vinculado a otra cuenta.',
            'status' => MemberSupportTicket::STATUS_NEW,
            'platform' => 'login',
        ]);
    }

    /** Vincula el equipo X al miembro A, como haría un login fuerte suyo. */
    private function bindDeviceTo(Member $owner): MemberDeviceBinding
    {
        return MemberDeviceBinding::create([
            'device_id' => self::DEVICE_X,
            'member_id' => $owner->id,
            'device_name' => 'iPhone',
            'platform' => 'ios',
            'bound_at' => now(),
        ]);
    }

    /** B intenta entrar desde el equipo X. Devuelve la respuesta del login. */
    private function loginAttempt(Member $member)
    {
        return $this->postJson('/api/members/login', [
            'document_number' => $member->document_number,
        ], [
            'X-Device-Id' => self::DEVICE_X,
            'X-Device-Name' => 'iPhone',
            'X-Platform' => 'ios',
        ]);
    }

    public function test_el_login_deniega_a_B_cuando_el_equipo_es_de_A(): void
    {
        $a = $this->member('1000000001', 'Titular A');
        $b = $this->member('1000000002', 'Socio B');
        $this->bindDeviceTo($a);

        $this->loginAttempt($b)
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_mismatch')
            ->assertJsonPath('reason_code', 'device_bound_to_another_member');

        // Queda registrada la denegación CONTRA el titular del equipo, con el
        // device_id exacto: es la evidencia de la que parte el diagnóstico.
        $event = MemberSecurityEvent::where('type', MemberSecurityEvent::TYPE_DEVICE_MISMATCH)->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame($a->id, $event->member_id);
        $this->assertSame(self::DEVICE_X, $event->device_id);
        $this->assertSame($b->id, (int) $event->metadata['attempted_member']);
    }

    public function test_la_respuesta_no_revela_quien_es_el_titular(): void
    {
        $a = $this->member('1000000001', 'Titular A');
        $b = $this->member('1000000002', 'Socio B');
        $this->bindDeviceTo($a);

        $body = $this->loginAttempt($b)->assertStatus(403)->json();
        $raw = json_encode($body);

        $this->assertStringNotContainsString('Titular A', $raw);
        $this->assertStringNotContainsString($a->document_number, $raw);
        $this->assertStringNotContainsString((string) $a->phone, $raw);
    }

    public function test_reset_trust_no_resuelve_el_conflicto_de_otro_titular(): void
    {
        $a = $this->member('1000000001', 'Titular A');
        $b = $this->member('1000000002', 'Socio B');
        $this->bindDeviceTo($a);
        $this->loginAttempt($b);
        $ticket = $this->ticket($b);

        // Exactamente lo que se vio en producción: 0 vínculos liberados.
        $this->postJson("/api/admin/support/{$ticket->id}/reset-trust", [], $this->actingAsAdmin())
            ->assertOk()
            ->assertJsonPath('released_count', 0);

        // Y el equipo sigue siendo de A, así que B sigue bloqueado.
        $this->assertDatabaseHas('member_device_bindings', [
            'device_id' => self::DEVICE_X,
            'member_id' => $a->id,
        ]);
        $this->loginAttempt($b)->assertStatus(403)->assertJsonPath('code', 'account_mismatch');
    }

    public function test_el_contexto_del_ticket_expone_el_conflicto(): void
    {
        $a = $this->member('1000000001', 'Titular A');
        $b = $this->member('1000000002', 'Socio B');
        $this->bindDeviceTo($a);
        $this->loginAttempt($b);
        $ticket = $this->ticket($b);

        $res = $this->getJson("/api/admin/support/{$ticket->id}/context", $this->actingAsAdmin())
            ->assertOk()
            // Las dos métricas antiguas siguen diciendo "0 / no": por eso hacían
            // falta, pero no bastaban.
            ->assertJsonPath('active_devices', 0)
            ->assertJsonPath('trusted_device', false)
            ->assertJsonPath('device_conflict.device_name', 'iPhone')
            ->assertJsonPath('device_conflict.platform', 'ios');

        // El identificador va enmascarado y no se filtra la identidad del otro.
        $conflict = $res->json('device_conflict');
        $this->assertStringNotContainsString(self::DEVICE_X, json_encode($conflict));
        $this->assertStringContainsString('…', $conflict['device_id_masked']);
        $this->assertStringNotContainsString('Titular A', json_encode($conflict));
    }

    public function test_sin_conflicto_no_se_anuncia_ninguno(): void
    {
        $b = $this->member('1000000002', 'Socio B');
        $ticket = $this->ticket($b);

        $this->getJson("/api/admin/support/{$ticket->id}/context", $this->actingAsAdmin())
            ->assertOk()
            ->assertJsonPath('device_conflict', null);
    }

    public function test_admin_libera_el_equipo_y_B_ya_puede_entrar(): void
    {
        $a = $this->member('1000000001', 'Titular A');
        $b = $this->member('1000000002', 'Socio B');
        $this->bindDeviceTo($a);
        $this->loginAttempt($b)->assertStatus(403);
        $ticket = $this->ticket($b);

        $this->postJson("/api/admin/support/{$ticket->id}/release-device", [
            'reason' => 'El socio cambió de dueño el equipo',
        ], $this->actingAsAdmin())
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('member_device_bindings', ['device_id' => self::DEVICE_X]);

        // El login ya NO devuelve account_mismatch. Lo que responda a partir de
        // aquí es el flujo normal (OTP, etc.), que este test no fija.
        $res = $this->loginAttempt($b);
        $this->assertNotSame('account_mismatch', $res->json('code'));
    }

    public function test_la_liberacion_queda_auditada_en_ambos_miembros(): void
    {
        $a = $this->member('1000000001', 'Titular A');
        $b = $this->member('1000000002', 'Socio B');
        $this->bindDeviceTo($a);
        $this->loginAttempt($b);
        $ticket = $this->ticket($b);

        $this->postJson("/api/admin/support/{$ticket->id}/release-device", [
            'reason' => 'Equipo reasignado',
        ], $this->actingAsAdmin())->assertOk();

        foreach ([$a->id, $b->id] as $memberId) {
            $event = MemberSecurityEvent::where('member_id', $memberId)
                ->where('type', MemberSecurityEvent::TYPE_DEVICE_RELEASED)
                ->latest('id')->first();
            $this->assertNotNull($event, "Falta la auditoría del miembro {$memberId}");
            $this->assertSame('admin_support', $event->metadata['source']);
            $this->assertSame('Equipo reasignado', $event->metadata['reason']);
            $this->assertSame($ticket->id, (int) $event->metadata['ticket_id']);
        }

        // Y traza legible en el propio ticket.
        $this->assertStringContainsString('Liberó el equipo', (string) $ticket->fresh()->admin_note);
    }

    public function test_solo_se_cierran_las_sesiones_de_ESE_equipo(): void
    {
        $a = $this->member('1000000001', 'Titular A');
        $b = $this->member('1000000002', 'Socio B');
        $this->bindDeviceTo($a);
        $this->loginAttempt($b);
        $ticket = $this->ticket($b);

        $enX = MemberDeviceSession::create([
            'member_id' => $a->id, 'device_id' => self::DEVICE_X,
            'token_hash' => hash('sha256', 'x'), 'device_name' => 'iPhone',
            'platform' => 'ios', 'last_seen_at' => now(),
        ]);
        $enOtro = MemberDeviceSession::create([
            'member_id' => $a->id, 'device_id' => 'dev_otro_equipo_de_A_000000000000000001',
            'token_hash' => hash('sha256', 'y'), 'device_name' => 'Android',
            'platform' => 'android', 'last_seen_at' => now(),
        ]);

        $this->postJson("/api/admin/support/{$ticket->id}/release-device", [], $this->actingAsAdmin())
            ->assertOk()
            ->assertJsonPath('revoked_sessions', 1);

        $this->assertNotNull($enX->fresh()->revoked_at, 'La sesión del equipo liberado debía cerrarse');
        $this->assertNull($enOtro->fresh()->revoked_at, 'El otro equipo de A NO debía tocarse');
    }

    public function test_no_borra_los_dispositivos_del_propio_B(): void
    {
        $a = $this->member('1000000001', 'Titular A');
        $b = $this->member('1000000002', 'Socio B');
        $this->bindDeviceTo($a);
        $this->loginAttempt($b);
        $ticket = $this->ticket($b);

        $suyo = MemberDeviceBinding::create([
            'device_id' => 'dev_equipo_propio_de_B_00000000000000001',
            'member_id' => $b->id, 'device_name' => 'Android',
            'platform' => 'android', 'bound_at' => now(),
        ]);

        $this->postJson("/api/admin/support/{$ticket->id}/release-device", [], $this->actingAsAdmin())->assertOk();

        $this->assertDatabaseHas('member_device_bindings', ['id' => $suyo->id, 'member_id' => $b->id]);
    }

    public function test_sin_conflicto_responde_409_y_no_borra_nada(): void
    {
        $b = $this->member('1000000002', 'Socio B');
        $ticket = $this->ticket($b);

        $this->postJson("/api/admin/support/{$ticket->id}/release-device", [], $this->actingAsAdmin())
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_device_conflict');

        $this->assertSame(0, MemberDeviceBinding::count());
    }

    public function test_no_libera_dos_veces_el_mismo_equipo(): void
    {
        $a = $this->member('1000000001', 'Titular A');
        $b = $this->member('1000000002', 'Socio B');
        $this->bindDeviceTo($a);
        $this->loginAttempt($b);
        $ticket = $this->ticket($b);

        $this->postJson("/api/admin/support/{$ticket->id}/release-device", [], $this->actingAsAdmin())->assertOk();
        // El evento de denegación sigue en el historial, pero ya no hay vínculo:
        // la segunda llamada no debe encontrar conflicto ni inventarse uno.
        $this->postJson("/api/admin/support/{$ticket->id}/release-device", [], $this->actingAsAdmin())
            ->assertStatus(409);
    }

    public function test_exige_sesion_admin(): void
    {
        $a = $this->member('1000000001', 'Titular A');
        $b = $this->member('1000000002', 'Socio B');
        $this->bindDeviceTo($a);
        $this->loginAttempt($b);
        $ticket = $this->ticket($b);

        $this->postJson("/api/admin/support/{$ticket->id}/release-device", [])
            ->assertStatus(401);

        $this->assertDatabaseHas('member_device_bindings', ['device_id' => self::DEVICE_X]);
    }

    public function test_ticket_sin_miembro_vinculado_no_hace_nada(): void
    {
        $ticket = MemberSupportTicket::create([
            'document' => '9999999999',
            'type' => 'access',
            'message' => 'Sin miembro',
            'status' => MemberSupportTicket::STATUS_NEW,
        ]);

        $this->postJson("/api/admin/support/{$ticket->id}/release-device", [], $this->actingAsAdmin())
            ->assertStatus(422);
    }
}
