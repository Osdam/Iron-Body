<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica END-TO-END que los endpoints de rutinas de la app entregan la media
 * del catálogo local para TODOS los miembros, sin importar dónde viva el
 * ejercicio de la rutina:
 *   - asignada vía member_routine_assignments  (days)
 *   - personalizada (routines.exercises JSON)
 *   - plantilla (days)
 *
 * El member 50 era solo una cuenta de prueba; este fix aplica globalmente.
 */
class AppRoutineMediaTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Member
    {
        $user = User::create([
            'name'     => 'Tester Rutinas',
            'email'    => 'rutinas@example.com',
            'password' => 'secret',
            'document' => '9090909090',
            'phone'    => '3009090909',
            'status'   => 'active',
        ]);

        return Member::create([
            'user_id'         => $user->id,
            'full_name'       => 'Tester Rutinas',
            'email'           => 'rutinas@example.com',
            'document_number' => '9090909090',
            'phone'           => '3009090909',
            'access_hash'     => 'tok-' . uniqid(),
            'status'          => Member::STATUS_ACTIVE,
        ]);
    }

    /** @return array<string,string> */
    private function authHeaders(Member $member): array
    {
        return ['Authorization' => 'Bearer ' . $member->access_hash];
    }

    private function localExercise(string $name, string $video): Exercise
    {
        return Exercise::create([
            'external_id' => 'local-' . uniqid(),
            'name'        => $name,
            'muscle_group'=> 'Pecho',
            'equipment'   => 'Máquina',
            'provider'    => 'local',
            'source'      => 'manual',
            'video_path'  => $video,
            'media_type'  => 'video',
        ]);
    }

    public function test_assigned_via_assignment_with_days_returns_video(): void
    {
        $member = $this->member();
        $url = 'https://api.ironbodyneiva.cloud/storage/exercises/videos/assigned.mp4';
        $this->localExercise('Press militar con barra de pie', $url);

        $routine = Routine::create([
            'name' => 'Asignada con días',
            'days' => [[
                'day'       => 'Lunes',
                'title'     => 'Hombro',
                'exercises' => [['name' => 'Press militar con barra de pie', 'sets' => 4, 'reps' => 8]],
            ]],
        ]);
        MemberRoutineAssignment::create([
            'member_id'   => $member->id,
            'routine_id'  => $routine->id,
            'assigned_at' => now(),
        ]);

        $res = $this->getJson('/api/app/routines/assigned', $this->authHeaders($member));

        $res->assertOk();
        $res->assertJsonPath('data.0.days.0.exercises.0.video_url', $url);
        $res->assertJsonPath('data.0.days.0.exercises.0.media_type', 'video');
        // También en la lista plana que la app consume directo.
        $res->assertJsonPath('data.0.exercises.0.video_url', $url);
    }

    public function test_custom_routine_json_exercises_returns_video(): void
    {
        $member = $this->member();
        $url = 'https://api.ironbodyneiva.cloud/storage/exercises/videos/custom.mp4';
        $this->localExercise('Press plano en máquina Hammer', $url);

        Routine::create([
            'name'        => 'Mi rutina',
            'member_id'   => $member->id,
            'is_assigned' => false,
            'exercises'   => [['name' => 'Press plano en máquina Hammer', 'sets' => 3, 'reps' => 12]],
        ]);

        $res = $this->getJson('/api/app/routines/custom', $this->authHeaders($member));

        $res->assertOk();
        $res->assertJsonPath('data.0.exercises.0.video_url', $url);
        $res->assertJsonPath('data.0.exercises.0.media_type', 'video');
        $res->assertJsonPath('data.0.exercises.0.exercise_id', fn ($v) => ! empty($v));
    }

    public function test_templates_with_days_returns_video(): void
    {
        $member = $this->member();
        $url = 'https://api.ironbodyneiva.cloud/storage/exercises/videos/tpl.mp4';
        $this->localExercise('Sentadilla con barra', $url);

        Routine::create([
            'name'        => 'Plantilla pierna',
            'is_template' => true,
            'days'        => [[
                'day'       => 'Martes',
                'title'     => 'Pierna',
                'exercises' => [['name' => 'Sentadilla con barra', 'sets' => 5, 'reps' => 5]],
            ]],
        ]);

        $res = $this->getJson('/api/app/routines/templates', $this->authHeaders($member));

        $res->assertOk();
        $res->assertJsonPath('data.0.days.0.exercises.0.video_url', $url);
        $res->assertJsonPath('data.0.days.0.exercises.0.media_type', 'video');
    }

    public function test_assigned_without_catalog_match_does_not_break(): void
    {
        $member = $this->member();

        $routine = Routine::create([
            'name'      => 'Sin match',
            'exercises' => [['name' => 'Ejercicio fantasma 999', 'sets' => 3, 'reps' => 10]],
        ]);
        MemberRoutineAssignment::create([
            'member_id'   => $member->id,
            'routine_id'  => $routine->id,
            'assigned_at' => now(),
        ]);

        $res = $this->getJson('/api/app/routines/assigned', $this->authHeaders($member));

        $res->assertOk();
        $res->assertJsonPath('data.0.exercises.0.name', 'Ejercicio fantasma 999');
        $res->assertJsonPath('data.0.exercises.0.video_url', null);
    }
}
