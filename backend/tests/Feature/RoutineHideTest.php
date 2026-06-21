<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Member;
use App\Models\MemberHiddenRoutine;
use App\Models\MemberRoutineAssignment;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ocultamiento de rutinas semi-personalizadas POR MIEMBRO:
 *  - no borra la rutina global,
 *  - es per-member (otro miembro la sigue viendo),
 *  - no afecta personalizadas ni "Más rutinas",
 *  - requiere auth, idempotente, valida existencia.
 */
class RoutineHideTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $email = 'h@example.com', string $doc = '111'): Member
    {
        $user = User::create([
            'name' => 'H', 'email' => $email, 'password' => 'secret',
            'document' => $doc, 'phone' => '300' . $doc, 'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id, 'full_name' => 'H', 'email' => $email,
            'document_number' => $doc, 'phone' => '300' . $doc,
            'access_hash' => 'tok-' . uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    /** @return array<string,string> */
    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer ' . $m->access_hash];
    }

    private function templateRoutine(string $name = 'Plan base'): Routine
    {
        return Routine::create([
            'name' => $name, 'is_template' => true, 'level' => 'Principiante',
            'gender' => 'Hombre',
            'days' => [[
                'day' => 'Lunes', 'title' => 'Pierna',
                'exercises' => [['name' => 'Sentadilla', 'sets' => 4, 'reps' => '12']],
            ]],
        ]);
    }

    private function assignToMember(Routine $r, Member $m): void
    {
        MemberRoutineAssignment::create([
            'member_id' => $m->id, 'routine_id' => $r->id, 'assigned_at' => now(),
        ]);
    }

    public function test_member_can_hide_semi_personalized_routine(): void
    {
        $m = $this->member();
        $this->templateRoutine('Rutina Principiante - Hombre - prueba');
        $r = \App\Models\Routine::first();

        // Lista principal de Semi-personalizadas (templates): la ve.
        $this->getJson('/api/app/routines/templates', $this->auth($m))
            ->assertOk()->assertJsonCount(1, 'data');

        // Oculta.
        $this->postJson("/api/app/routines/{$r->id}/hide", [], $this->auth($m))
            ->assertOk()->assertJsonPath('hidden', true);

        // Lista principal: ya no aparece.
        $this->getJson('/api/app/routines/templates', $this->auth($m))
            ->assertOk()->assertJsonCount(0, 'data');

        // Explorar (include_hidden): SIGUE apareciendo, marcada como oculta.
        $this->getJson('/api/app/routines/templates?include_hidden=true', $this->auth($m))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_hidden', true)
            ->assertJsonPath('data.0.can_restore', true);
    }

    public function test_hiding_does_not_delete_global_routine(): void
    {
        $m = $this->member();
        $r = $this->templateRoutine();
        $this->assignToMember($r, $m);

        $this->postJson("/api/app/routines/{$r->id}/hide", [], $this->auth($m))->assertOk();

        $this->assertDatabaseHas('routines', ['id' => $r->id]); // sigue existiendo
        $this->assertDatabaseHas('member_hidden_routines', [
            'member_id' => $m->id, 'routine_id' => $r->id,
        ]);
    }

    public function test_other_member_still_sees_routine(): void
    {
        $m1 = $this->member('a@example.com', '111');
        $m2 = $this->member('b@example.com', '222');
        $this->templateRoutine();
        $r = \App\Models\Routine::first();

        $this->postJson("/api/app/routines/{$r->id}/hide", [], $this->auth($m1))->assertOk();

        // m1 no la ve en su lista principal; m2 sí (ocultamiento por miembro).
        $this->getJson('/api/app/routines/templates', $this->auth($m1))
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/app/routines/templates', $this->auth($m2))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_personalized_routine_cannot_be_hidden(): void
    {
        $m = $this->member();
        $ex = Exercise::create([
            'external_id' => 'local-' . uniqid(), 'name' => 'Press de pecho en máquina',
            'provider' => 'local', 'source' => 'manual',
            'video_path' => 'https://api.ironbodyneiva.cloud/storage/exercises/videos/p.mp4',
            'media_type' => 'video',
        ]);
        // Personalizada: vínculo real al catálogo.
        $r = Routine::create([
            'name' => 'Hecha para el miembro', 'member_id' => $m->id, 'is_assigned' => true,
            'exercises' => [['name' => 'X', 'exercise_id' => $ex->id, 'sets' => 4, 'reps' => 10]],
        ]);

        $this->postJson("/api/app/routines/{$r->id}/hide", [], $this->auth($m))
            ->assertStatus(422);

        // Sigue visible en assigned.
        $this->getJson('/api/app/routines/assigned', $this->auth($m))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_more_routines_custom_not_affected(): void
    {
        $m = $this->member();
        // Custom (Más rutinas): member_id + is_assigned=false.
        Routine::create([
            'name' => 'Mi custom', 'member_id' => $m->id, 'is_assigned' => false,
            'exercises' => [['name' => 'Libre', 'sets' => 3, 'reps' => 10]],
        ]);

        // Aunque ocultáramos algo, custom no se toca.
        $this->getJson('/api/app/routines/custom', $this->auth($m))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_hide_requires_authentication(): void
    {
        $r = $this->templateRoutine();
        $this->postJson("/api/app/routines/{$r->id}/hide")->assertStatus(401);
    }

    public function test_hide_unknown_routine_returns_404(): void
    {
        $m = $this->member();
        $this->postJson('/api/app/routines/999999/hide', [], $this->auth($m))
            ->assertStatus(404);
    }

    public function test_hide_is_idempotent(): void
    {
        $m = $this->member();
        $r = $this->templateRoutine();
        $this->assignToMember($r, $m);

        $this->postJson("/api/app/routines/{$r->id}/hide", [], $this->auth($m))->assertOk();
        $this->postJson("/api/app/routines/{$r->id}/hide", [], $this->auth($m))->assertOk();

        $this->assertSame(1, MemberHiddenRoutine::where('routine_id', $r->id)->count());
    }

    public function test_unhide_restores_routine(): void
    {
        $m = $this->member();
        $this->templateRoutine();
        $r = \App\Models\Routine::first();

        $this->postJson("/api/app/routines/{$r->id}/hide", [], $this->auth($m))->assertOk();
        $this->getJson('/api/app/routines/templates', $this->auth($m))
            ->assertOk()->assertJsonCount(0, 'data');

        $this->postJson("/api/app/routines/{$r->id}/unhide", [], $this->auth($m))
            ->assertOk()->assertJsonPath('hidden', false);

        // Restaurada en la lista principal de Semi-personalizadas.
        $this->getJson('/api/app/routines/templates', $this->auth($m))
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
