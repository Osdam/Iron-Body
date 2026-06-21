<?php

namespace Database\Seeders;

use App\Models\ExerciseAlias;
use App\Services\Exercises\ExerciseCatalogResolver;
use Illuminate\Database\Seeder;

/**
 * Aliases verificados iniciales para nombres de rutinas que NO coinciden con el
 * catálogo pero son biomecánicamente equivalentes y NO ambiguos.
 *
 * Los candidatos peligrosos (Press francés, Curl antebrazo, Jalón polea agarre
 * abierto, Remo con barra T…) se dejan FUERA a propósito: requieren revisión
 * humana antes de crear su alias.
 *
 * Es idempotente y seguro: si el ejercicio del catálogo no existe (otra BD),
 * simplemente omite ese alias.
 */
class ExerciseAliasSeeder extends Seeder
{
    /** alias usado en rutinas → nombre del ejercicio real en el catálogo */
    private const SAFE_ALIASES = [
        'Press plano en máquina Hammer'        => 'Press de pecho en máquina',
        'Press inclinado en máquina isolateral'=> 'Press banca inclinado en máquina Smith',
        'Curl de bíceps en polea baja'         => 'Curl de bíceps en polea con barra',
        'Curl concentrado con mancuerna'       => 'Curl de concentración con mancuerna',
        'Remo polea sentado agarre abierto'    => 'Remo sentado en polea',
    ];

    public function run(): void
    {
        $resolver = app(ExerciseCatalogResolver::class);
        $resolver->refresh();

        $created = 0;
        $skipped = 0;

        foreach (self::SAFE_ALIASES as $alias => $catalogName) {
            // El candidato debe existir en el catálogo (match exacto por nombre).
            $exercise = $resolver->resolveSafe(null, $catalogName);
            if ($exercise === null) {
                $skipped++;
                $this->command?->warn("Alias omitido (catálogo sin '{$catalogName}'): {$alias}");
                continue;
            }

            ExerciseAlias::query()->updateOrCreate(
                ['normalized_alias' => $resolver->normalize($alias), 'exercise_id' => (int) $exercise->id],
                [
                    'alias_name'  => $alias,
                    'source'      => 'seed',
                    'confidence'  => 1.000,
                    'is_verified' => true,
                    'notes'       => 'Equivalencia biomecánica verificada (seed inicial).',
                ],
            );
            $created++;
        }

        $resolver->refresh();
        $this->command?->info("ExerciseAliasSeeder: {$created} aliases verificados, {$skipped} omitidos.");
    }
}
