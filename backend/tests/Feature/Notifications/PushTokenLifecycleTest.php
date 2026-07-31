<?php

namespace Tests\Feature\Notifications;

use App\Models\MemberDeviceToken;

/**
 * El ciclo de vida del token entre sesiones.
 *
 * Sale de un caso real: un socio entró, salió y volvió a entrar, y dejó de
 * recibir notificaciones sin ninguna señal de por qué. El logout borraba la
 * fila entera y el re-registro no devolvía el token a activo.
 */
class PushTokenLifecycleTest extends NotificationTestCase
{
    public function test_el_logout_desactiva_el_token_pero_no_lo_borra(): void
    {
        $member = $this->makeMember();
        $token = $this->giveDevice($member);

        $this->postJson('/api/members/push-token/remove', [
            'token' => $token->token,
        ], $this->asMember($member))->assertOk();

        $this->assertDatabaseHas('member_device_tokens', [
            'id' => $token->id,
            'is_active' => false,
        ]);
    }

    public function test_volver_a_entrar_reactiva_el_token(): void
    {
        $member = $this->makeMember();
        $token = $this->giveDevice($member, active: false);

        $this->postJson('/api/members/push-token', [
            'token' => $token->token,
            'platform' => 'android',
        ], $this->asMember($member))->assertOk();

        $token->refresh();
        $this->assertTrue(
            $token->is_active,
            'Tras volver a entrar el socio debe recibir otra vez, sin tener que reinstalar.',
        );
    }

    public function test_el_historial_del_dispositivo_sobrevive_a_varias_sesiones(): void
    {
        $member = $this->makeMember();
        $token = $this->giveDevice($member);
        $creado = $token->created_at;

        // Sale y vuelve dos veces.
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/members/push-token/remove', ['token' => $token->token], $this->asMember($member))->assertOk();
            $this->postJson('/api/members/push-token', ['token' => $token->token, 'platform' => 'android'], $this->asMember($member))->assertOk();
        }

        $this->assertSame(
            1,
            MemberDeviceToken::where('token', $token->token)->count(),
            'Cada ciclo no debe crear un dispositivo nuevo.',
        );
        $this->assertEquals(
            $creado->toDateTimeString(),
            $token->fresh()->created_at->toDateTimeString(),
            'La fecha en que se conoció el dispositivo debe conservarse.',
        );
    }

    public function test_un_socio_no_puede_dar_de_baja_el_token_de_otro(): void
    {
        $uno = $this->makeMember('Uno');
        $otro = $this->makeMember('Otro');
        $token = $this->giveDevice($otro);

        $this->postJson('/api/members/push-token/remove', [
            'token' => $token->token,
        ], $this->asMember($uno))->assertOk();

        $this->assertTrue($token->fresh()->is_active, 'El token del otro socio no debía tocarse.');
    }
}
