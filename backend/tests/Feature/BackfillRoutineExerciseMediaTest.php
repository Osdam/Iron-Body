<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\Routine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backfill de media en rutinas JSON antiguas: dry-run no escribe; --apply solo
 * aplica matches seguros y preserva la estructura/datos existentes.
 */
class BackfillRoutineExerciseMediaTest extends TestCase
{
    use RefreshDatabase;

    private const VID = 'https://api.ironbodyneiva.cloud/storage/exercises/videos';

    private function localExercise(string $name, string $video): Exercise
    {
        return Exercise::create([
            'external_id' => 'local-' . uniqid(),
            'name'        => $name,
            'provider'    => 'local',
            'source'      => 'manual',
            'video_path'  => $video,
            'media_type'  => 'video',
        ]);
    }

    public function test_dry_run_no_modifica(): void
    {
        $this->localExercise('Press militar con barra de pie', self::VID . '/mil.mp4');
        $routine = Routine::create([
            'name'      => 'R',
            'exercises' => [['name' => 'Press militar con barra de pie', 'sets' => 3, 'reps' => 10]],
        ]);

        $this->artisan('ironbody:backfill-routine-exercise-media')->assertSuccessful();

        $routine->refresh();
        $this->assertArrayNotHasKey('video_url', $routine->exercises[0]);
    }

    public function test_apply_modifica_solo_matches_seguros_en_exercises_y_days(): void
    {
        $this->localExercise('Press militar con barra de pie', self::VID . '/mil.mp4');
        $this->localExercise('Press plano en máquina Hammer', self::VID . '/hammer.mp4'); // existe exacto

        $routine = Routine::create([
            'name'      => 'R',
            'exercises' => [
                ['name' => 'Press militar con barra de pie', 'sets' => 4, 'reps' => 8, 'notes' => 'cuidado'],
                ['name' => 'Ejercicio fantasma', 'sets' => 3, 'reps' => 12], // sin match → intacto
            ],
            'days' => [[
                'day' => 'Lunes',
                'exercises' => [['name' => 'Press plano en máquina Hammer', 'sets' => 4, 'reps' => 10]],
            ]],
        ]);

        $this->artisan('ironbody:backfill-routine-exercise-media --apply')->assertSuccessful();

        $routine->refresh();

        // exercises[0] enriquecido con media, preservando datos.
        $e0 = $routine->exercises[0];
        $this->assertSame(self::VID . '/mil.mp4', $e0['video_url']);
        $this->assertSame('video', $e0['media_type']);
        $this->assertNotEmpty($e0['exercise_id']);
        $this->assertSame('cuidado', $e0['notes']);   // preserva
        $this->assertSame(4, $e0['sets']);             // preserva

        // exercises[1] sin match → sin video_url (no rompe).
        $this->assertArrayNotHasKey('video_url', $routine->exercises[1]);

        // days[].exercises[] enriquecido.
        $this->assertSame(self::VID . '/hammer.mp4', $routine->days[0]['exercises'][0]['video_url']);
    }

    public function test_apply_no_sobreescribe_video_existente(): void
    {
        $this->localExercise('Sentadilla', self::VID . '/catalog.mp4');
        $routine = Routine::create([
            'name'      => 'R',
            'exercises' => [['name' => 'Sentadilla', 'video_url' => 'https://cdn/x.mp4']],
        ]);

        $this->artisan('ironbody:backfill-routine-exercise-media --apply')->assertSuccessful();

        $routine->refresh();
        $this->assertSame('https://cdn/x.mp4', $routine->exercises[0]['video_url']);
    }
}
