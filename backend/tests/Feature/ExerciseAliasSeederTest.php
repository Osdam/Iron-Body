<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Services\Exercises\ExerciseCatalogResolver;
use Database\Seeders\ExerciseAliasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El seeder de aliases verificados vincula nombres de rutina con el ejercicio
 * EXACTO del catálogo, omite con seguridad lo que no existe y NO siembra los
 * casos dudosos (Press francés, etc.).
 */
class ExerciseAliasSeederTest extends TestCase
{
    use RefreshDatabase;

    private const VID = 'https://api.ironbodyneiva.cloud/storage/exercises/videos';

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

    public function test_seeder_vincula_alias_y_resuelve_video(): void
    {
        // Catálogo con los nombres REALES (target).
        $militar = $this->catalog('Press militar con barra de pie', 'militar');
        $this->catalog('Remo sentado en polea', 'remo');
        $this->catalog('Curl martillo con mancuernas', 'curl');

        $this->seed(ExerciseAliasSeeder::class);

        // Alias creado con los metadatos correctos.
        $alias = ExerciseAlias::where('alias_name', 'Press militar con barra')->first();
        $this->assertNotNull($alias);
        $this->assertSame($militar->id, $alias->exercise_id);
        $this->assertTrue($alias->is_verified);
        $this->assertSame('manual_verified', $alias->source);

        // El nombre de rutina ahora resuelve al video del catálogo.
        $resolver = new ExerciseCatalogResolver();
        $m = $resolver->resolveSafe(null, 'Press militar con barra');
        $this->assertSame($militar->id, $m?->id);
        $this->assertStringContainsString('militar.mp4', $m->video_path);

        // Dos alias distintos pueden apuntar al mismo ejercicio (agarre abierto).
        $this->assertNotNull(
            $resolver->resolveSafe(null, 'Remo polea sentado agarre abierto'),
        );
    }

    public function test_seeder_omite_target_inexistente_y_no_siembra_dudosos(): void
    {
        // Solo existe uno de los targets; los demás se omiten sin error.
        $this->catalog('Press militar con barra de pie', 'militar');

        $this->seed(ExerciseAliasSeeder::class);

        // El que existe → alias creado.
        $this->assertDatabaseHas('exercise_aliases', ['alias_name' => 'Press militar con barra']);
        // El que no existe en catálogo → NO se crea alias.
        $this->assertDatabaseMissing('exercise_aliases', ['alias_name' => 'Remo polea sentado']);
        // Casos dudosos NUNCA se siembran.
        $this->assertDatabaseMissing('exercise_aliases', ['alias_name' => 'Press francés']);
        $this->assertDatabaseMissing('exercise_aliases', ['alias_name' => 'Curl antebrazo']);
    }

    public function test_seeder_es_idempotente(): void
    {
        $this->catalog('Press militar con barra de pie', 'militar');

        $this->seed(ExerciseAliasSeeder::class);
        $this->seed(ExerciseAliasSeeder::class);

        $this->assertSame(
            1,
            ExerciseAlias::where('alias_name', 'Press militar con barra')->count(),
        );
    }
}
