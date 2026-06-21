<?php

namespace Tests\Feature;

use App\Http\Resources\RoutineResource;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\RoutineExercise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * RoutineResource debe entregar la media del catálogo local (video_url/gif_url/
 * thumbnail_url/media_type) aunque la rutina esté guardada como JSON por nombre
 * (módulo Rutinas del CRM Angular: routines.exercises sin exercise_id ni media).
 *
 * Cubre el fix "opción A": resolución contra la tabla `exercises` por
 * exercise_id y por nombre normalizado, sin romper rutinas normalizadas.
 */
class RoutineResourceMediaTest extends TestCase
{
    use RefreshDatabase;

    private function request(): Request
    {
        return Request::create('/api/app/routines/assigned', 'GET');
    }

    private function localExercise(string $name, string $video): Exercise
    {
        return Exercise::create([
            'external_id'   => 'local-' . uniqid(),
            'name'          => $name,
            'muscle_group'  => 'Pecho',
            'equipment'     => 'Máquina',
            'provider'      => 'local',
            'source'        => 'manual',
            'video_path'    => $video,
            'media_type'    => 'video',
        ]);
    }

    /** @return array<string,mixed>|null */
    private function findExercise(array $serialized, string $name): ?array
    {
        foreach ($serialized['exercises'] as $ex) {
            if (($ex['name'] ?? null) === $name) {
                return $ex;
            }
        }

        return null;
    }

    public function test_json_exercise_by_name_gets_catalog_video(): void
    {
        $url = 'https://api.ironbodyneiva.cloud/storage/exercises/videos/abc.mp4';
        $this->localExercise('Press plano en máquina Hammer', $url);

        // Rutina vieja: ejercicios en JSON por NOMBRE, sin exercise_id ni media.
        $routine = Routine::create([
            'name'       => 'Pecho lunes',
            'is_assigned'=> true,
            'exercises'  => [
                ['name' => 'Press plano en máquina Hammer', 'muscleGroup' => 'Pecho', 'sets' => 4, 'reps' => 10],
            ],
        ]);

        $out = (new RoutineResource($routine))->toArray($this->request());
        $ex = $this->findExercise($out, 'Press plano en máquina Hammer');

        $this->assertNotNull($ex);
        $this->assertSame($url, $ex['video_url']);          // (3) incluye video_url
        $this->assertSame('video', $ex['media_type']);      // (4) media_type=video
        $this->assertNotEmpty($ex['exercise_id']);          // se vincula al catálogo
    }

    public function test_match_is_case_and_space_insensitive(): void
    {
        $url = 'https://api.ironbodyneiva.cloud/storage/exercises/videos/mil.mp4';
        $this->localExercise('Press militar con barra de pie', $url);

        $routine = Routine::create([
            'name'      => 'Hombro',
            'exercises' => [
                // Mismas palabras, distinta caja y espacios extra.
                ['name' => '  Press  Militar Con Barra De Pie ', 'sets' => 3, 'reps' => 8],
            ],
        ]);

        $out = (new RoutineResource($routine))->toArray($this->request());
        $ex = $out['exercises'][0];

        $this->assertSame($url, $ex['video_url']);
        $this->assertSame('video', $ex['media_type']);
    }

    public function test_existing_media_in_json_is_not_overwritten(): void
    {
        // Catálogo con OTRA url; el JSON ya trae su propia media.
        $this->localExercise('Sentadilla', 'https://api.ironbodyneiva.cloud/storage/exercises/videos/catalog.mp4');

        $jsonUrl = 'https://cdn.example.com/custom.mp4';
        $routine = Routine::create([
            'name'      => 'Pierna',
            'exercises' => [
                ['name' => 'Sentadilla', 'sets' => 5, 'reps' => 5, 'video_url' => $jsonUrl],
            ],
        ]);

        $out = (new RoutineResource($routine))->toArray($this->request());
        $ex = $out['exercises'][0];

        // (5) No se sobreescribe la media que ya viene en el JSON.
        $this->assertSame($jsonUrl, $ex['video_url']);
        $this->assertSame('video', $ex['media_type']);
    }

    public function test_no_match_keeps_fallback_without_error(): void
    {
        // No existe en el catálogo.
        $routine = Routine::create([
            'name'      => 'Rutina rara',
            'exercises' => [
                ['name' => 'Ejercicio inexistente XYZ', 'sets' => 3, 'reps' => 12],
            ],
        ]);

        $out = (new RoutineResource($routine))->toArray($this->request());
        $ex = $out['exercises'][0];

        // (6) Sin match: conserva el ejercicio, media null, sin romper.
        $this->assertSame('Ejercicio inexistente XYZ', $ex['name']);
        $this->assertNull($ex['video_url']);
        $this->assertArrayHasKey('media_type', $ex);
    }

    public function test_match_by_exercise_id_when_present(): void
    {
        $url = 'https://api.ironbodyneiva.cloud/storage/exercises/videos/byid.mp4';
        $catalog = $this->localExercise('Remo con barra', $url);

        $routine = Routine::create([
            'name'      => 'Espalda',
            'exercises' => [
                // Nombre distinto, pero exercise_id apunta al catálogo.
                ['name' => 'Remo (variante)', 'exercise_id' => $catalog->id, 'sets' => 4, 'reps' => 10],
            ],
        ]);

        $out = (new RoutineResource($routine))->toArray($this->request());
        $ex = $out['exercises'][0];

        $this->assertSame($url, $ex['video_url']);
        $this->assertSame('video', $ex['media_type']);
    }

    public function test_normalized_routine_still_serializes_media(): void
    {
        $url = 'https://api.ironbodyneiva.cloud/storage/exercises/videos/norm.mp4';
        $catalog = $this->localExercise('Curl de bíceps', $url);

        $routine = Routine::create(['name' => 'Brazo', 'is_assigned' => true]);
        RoutineExercise::create([
            'routine_id'  => $routine->id,
            'exercise_id' => $catalog->id,
            'sets'        => 3,
            'reps'        => 12,
            'sort_order'  => 0,
        ]);
        $routine->load('routineExercises.exercise');

        $out = (new RoutineResource($routine))->toArray($this->request());

        // (7) La ruta normalizada (exercise anidado) sigue trayendo el video.
        $this->assertCount(1, $out['exercises']);
        $this->assertSame($url, $out['exercises'][0]['exercise']['video_url']);
    }
}
