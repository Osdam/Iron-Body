<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberRealtimeEvent;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Separación de fuentes por sección de Entrenar:
 *  - "Mis rutinas" (assigned) = SOLO personalizadas del CRM del miembro.
 *  - "Semi" (templates) = SOLO plantillas del Seeder, sin las del CRM.
 *  - "Explorar" (templates?include_hidden) = plantillas, incluidas ocultas.
 * + SSE: crear/asignar desde el CRM emite el evento realtime al miembro.
 */
class RoutineSectionSourcesTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 'test-admin-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.api_token' => self::ADMIN]);
    }

    private function member(string $email = 'x@example.com', string $doc = '555'): Member
    {
        $user = User::create([
            'name' => 'X', 'email' => $email, 'password' => 'secret',
            'document' => $doc, 'phone' => '300' . $doc, 'status' => 'active',
        ]);
        return Member::create([
            'user_id' => $user->id, 'full_name' => 'X', 'email' => $email,
            'document_number' => $doc, 'phone' => '300' . $doc,
            'access_hash' => 'tok-' . uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function memberAuth(Member $m): array
    {
        return ['Authorization' => 'Bearer ' . $m->access_hash];
    }

    private function adminAuth(): array
    {
        return ['Authorization' => 'Bearer ' . self::ADMIN];
    }

    public function test_crm_routine_appears_in_my_routines_not_in_semi_or_explore(): void
    {
        $m = $this->member();

        // Crear rutina desde el CRM (/api/routines) asignada al miembro.
        $this->postJson('/api/routines', [
            'name' => 'Rutina CRM del miembro',
            'assignedMemberId' => $m->id,
            'exercises' => [['name' => 'Press banca', 'sets' => 4, 'reps' => 10]],
        ], $this->adminAuth())->assertCreated();

        // Aparece en "Mis rutinas".
        $this->getJson('/api/app/routines/assigned', $this->memberAuth($m))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.routine_type', 'personalized');

        // NO aparece en Semi-personalizadas (templates).
        $this->getJson('/api/app/routines/templates', $this->memberAuth($m))
            ->assertOk()->assertJsonCount(0, 'data');

        // NO aparece en Explorar.
        $this->getJson('/api/app/routines/templates?include_hidden=true', $this->memberAuth($m))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_other_member_does_not_see_crm_routine(): void
    {
        $m1 = $this->member('a@example.com', '111');
        $m2 = $this->member('b@example.com', '222');

        $this->postJson('/api/routines', [
            'name' => 'Solo para m1',
            'assignedMemberId' => $m1->id,
            'exercises' => [['name' => 'Sentadilla', 'sets' => 4, 'reps' => 10]],
        ], $this->adminAuth())->assertCreated();

        $this->getJson('/api/app/routines/assigned', $this->memberAuth($m1))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/app/routines/assigned', $this->memberAuth($m2))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_seeder_template_in_semi_and_explore_not_in_my_routines(): void
    {
        $m = $this->member();
        Routine::create([
            'name' => 'Plan base', 'is_template' => true, 'level' => 'Principiante',
            'gender' => 'Hombre',
            'days' => [['day' => 'Lunes', 'title' => 'Pierna', 'exercises' => [['name' => 'Sentadilla', 'sets' => 4, 'reps' => '12']]]],
        ]);

        $this->getJson('/api/app/routines/templates', $this->memberAuth($m))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.routine_type', 'semi_personalized');
        $this->getJson('/api/app/routines/assigned', $this->memberAuth($m))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_crm_routine_emits_realtime_event_to_member(): void
    {
        $m = $this->member();

        $this->postJson('/api/routines', [
            'name' => 'Con realtime',
            'assignedMemberId' => $m->id,
            'exercises' => [['name' => 'Remo', 'sets' => 4, 'reps' => 10]],
        ], $this->adminAuth())->assertCreated();

        // SSE: se encoló un evento routine.updated para el miembro.
        $this->assertDatabaseHas('member_realtime_events', [
            'member_id' => $m->id,
            'type'      => 'routine.updated',
        ]);
    }

    public function test_member_realtime_stream_requires_auth(): void
    {
        $this->getJson('/api/member/realtime')->assertStatus(401);
    }
}
