<?php

namespace Tests\Feature;

use App\Models\Exercise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Flujo de guardado del CRM (/api/routines): cuando un ejercicio viene de la
 * Biblioteca con `exerciseId`, la rutina debe persistir `exercise_id` y el
 * backend completar `video_url`/`media_type` desde el catálogo. No depende del
 * nombre visible.
 */
class RoutineSaveExerciseLinkTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-admin-secret';
    private const VID = 'https://api.ironbodyneiva.cloud/storage/exercises/videos';

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.api_token' => self::SECRET]);
    }

    /** @return array<string,string> */
    private function adminAuth(): array
    {
        return ['Authorization' => 'Bearer ' . self::SECRET];
    }

    private function catalog(string $name, string $file): Exercise
    {
        return Exercise::create([
            'external_id' => 'local-' . uniqid(),
            'name'        => $name,
            'provider'    => 'local',
            'source'      => 'manual',
            'video_path'  => self::VID . "/{$file}.mp4",
            'media_type'  => 'video',
        ]);
    }

    public function test_guardar_con_exerciseId_persiste_link_y_completa_video(): void
    {
        $ex = $this->catalog('Press de pecho en máquina', 'pecho');

        $res = $this->postJson('/api/routines', [
            'name' => 'Rutina pecho',
            'exercises' => [[
                // Nombre VISIBLE distinto a propósito: el vínculo es exerciseId.
                'name' => 'Press plano Hammer (mi nombre)',
                'exerciseId' => $ex->id,
                'sets' => 4, 'reps' => 10, 'restSeconds' => 60,
            ]],
        ], $this->adminAuth());

        $res->assertCreated();
        $res->assertJsonPath('exercises.0.exercise_id', $ex->id);
        $res->assertJsonPath('exercises.0.video_url', self::VID . '/pecho.mp4');
        $res->assertJsonPath('exercises.0.media_type', 'video');
    }

    public function test_guardar_sin_exerciseId_resuelve_por_nombre_exacto(): void
    {
        $ex = $this->catalog('Press militar con barra de pie', 'mil');

        $res = $this->postJson('/api/routines', [
            'name' => 'Hombro',
            'exercises' => [[
                'name' => 'Press militar con barra de pie',
                'sets' => 3, 'reps' => 8, 'restSeconds' => 60,
            ]],
        ], $this->adminAuth());

        $res->assertCreated();
        $res->assertJsonPath('exercises.0.exercise_id', $ex->id);
        $res->assertJsonPath('exercises.0.video_url', self::VID . '/mil.mp4');
    }

    public function test_guardar_sin_match_no_rompe(): void
    {
        $res = $this->postJson('/api/routines', [
            'name' => 'Rara',
            'exercises' => [[
                'name' => 'Ejercicio fantasma 999',
                'sets' => 3, 'reps' => 12, 'restSeconds' => 45,
            ]],
        ], $this->adminAuth());

        $res->assertCreated();
        $res->assertJsonPath('exercises.0.name', 'Ejercicio fantasma 999');
        $res->assertJsonPath('exercises.0.exercise_id', null);
        $res->assertJsonMissingPath('exercises.0.video_url');
    }
}
