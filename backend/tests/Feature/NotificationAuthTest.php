<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blindaje de las rutas de notificaciones de la app (audience=member).
 *
 * Regresión de BACK-001: antes, el grupo `notifications*` no tenía middleware y
 * el controlador resolvía al miembro por `?document=`, permitiendo leer/alterar
 * notificaciones de cualquier miembro por cédula y sin token. Ahora exige
 * auth.member y la identidad sale SOLO del Bearer.
 */
class NotificationAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeMember(array $attrs = []): Member
    {
        return Member::create(array_merge([
            'full_name'       => 'Juan Pérez',
            'email'           => 'j'.random_int(1, 99999).'@example.com',
            'document_number' => (string) random_int(10000000, 99999999),
            'phone'           => '3001234567',
            'birth_date'      => '1995-05-10',
            'is_minor'        => false,
            'status'          => Member::STATUS_ACTIVE,
        ], $attrs));
    }

    private function notifyMember(Member $m, string $title): Notification
    {
        return Notification::create([
            'member_id' => $m->id,
            'document'  => $m->document_number,
            'audience'  => Notification::AUDIENCE_MEMBER,
            'type'      => 'payment',
            'title'     => $title,
            'message'   => 'Contenido sensible de '.$m->full_name,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    public function test_notifications_require_authentication(): void
    {
        $this->getJson('/api/notifications')
            ->assertStatus(401)
            ->assertJsonPath('code', 'token_required');
    }

    public function test_document_query_no_longer_leaks_other_members_notifications(): void
    {
        $victim = $this->makeMember(['full_name' => 'Víctima']);
        $this->notifyMember($victim, 'Tu pago fue aprobado');

        // Sin token, pasando la cédula de la víctima (el ataque original): 401.
        $this->getJson('/api/notifications?document='.$victim->document_number)
            ->assertStatus(401);

        $this->getJson('/api/notifications/unread-count?document='.$victim->document_number)
            ->assertStatus(401);
    }

    public function test_authenticated_member_only_sees_own_notifications_even_passing_foreign_document(): void
    {
        $attacker = $this->makeMember(['full_name' => 'Atacante']);
        $victim   = $this->makeMember(['full_name' => 'Víctima']);

        $this->notifyMember($attacker, 'Notificación propia');
        $this->notifyMember($victim, 'Secreto de la víctima');

        // Autenticado como atacante pero inyectando el documento de la víctima:
        // la identidad sale del token, así que solo ve lo suyo.
        $res = $this->getJson(
            '/api/notifications?document='.$victim->document_number,
            $this->auth($attacker)
        )->assertOk();

        $titles = collect($res->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Notificación propia'));
        $this->assertFalse($titles->contains('Secreto de la víctima'));
    }

    public function test_member_cannot_mark_or_delete_foreign_notification(): void
    {
        $attacker = $this->makeMember(['full_name' => 'Atacante']);
        $victim   = $this->makeMember(['full_name' => 'Víctima']);
        $target   = $this->notifyMember($victim, 'Alerta de seguridad de la víctima');

        // Intentar borrar la notificación de la víctima con el token del atacante
        // (y su cédula en el body) no debe eliminarla.
        $this->deleteJson(
            "/api/notifications/{$target->uuid}",
            ['document' => $victim->document_number],
            $this->auth($attacker)
        );

        $this->assertDatabaseHas('notifications', [
            'uuid'   => $target->uuid,
            'status' => Notification::STATUS_UNREAD,
        ]);
    }
}
