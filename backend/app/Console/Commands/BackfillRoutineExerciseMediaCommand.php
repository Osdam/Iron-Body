<?php

namespace App\Console\Commands;

use App\Models\Routine;
use App\Services\Exercises\ExerciseCatalogResolver;
use Illuminate\Console\Command;

/**
 * Backfill de media en rutinas antiguas guardadas como JSON (routines.exercises
 * y routines.days). Añade exercise_id/video_url/gif_url/thumbnail_url/media_type/
 * playback_speed cuando hay un match SEGURO contra el catálogo, sin borrar nada
 * ni alterar nombre visible/sets/reps/weight/notes/sort_order/estructura.
 *
 * Modo dry-run por defecto; requiere --apply para escribir.
 */
class BackfillRoutineExerciseMediaCommand extends Command
{
    protected $signature = 'ironbody:backfill-routine-exercise-media
                            {--apply : Escribe los cambios (por defecto dry-run)}
                            {--dry-run : Forzar simulación (no escribe)}';

    protected $description = 'Rellena media (video/gif/thumbnail) en rutinas JSON antiguas usando el catálogo.';

    public function handle(ExerciseCatalogResolver $resolver): int
    {
        $resolver->refresh();
        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');

        $routinesTouched = 0;
        $itemsEnriched = 0;
        $unmatched = 0;

        Routine::query()->chunkById(100, function ($routines) use ($resolver, $apply, &$routinesTouched, &$itemsEnriched, &$unmatched) {
            foreach ($routines as $routine) {
                $changed = false;

                $exercises = is_array($routine->exercises) ? $routine->exercises : null;
                if ($exercises !== null) {
                    foreach ($exercises as $idx => $item) {
                        if (! is_array($item)) {
                            continue;
                        }
                        $res = $this->enrichItem($item, $resolver, $itemsEnriched, $unmatched);
                        if ($res !== null) {
                            $exercises[$idx] = $res;
                            $changed = true;
                        }
                    }
                }

                $days = is_array($routine->days) ? $routine->days : null;
                if ($days !== null) {
                    foreach ($days as $di => $day) {
                        if (! is_array($day) || ! is_array($day['exercises'] ?? null)) {
                            continue;
                        }
                        foreach ($day['exercises'] as $ei => $item) {
                            if (! is_array($item)) {
                                continue;
                            }
                            $res = $this->enrichItem($item, $resolver, $itemsEnriched, $unmatched);
                            if ($res !== null) {
                                $days[$di]['exercises'][$ei] = $res;
                                $changed = true;
                            }
                        }
                    }
                }

                if ($changed) {
                    $routinesTouched++;
                    if ($apply) {
                        if ($exercises !== null) {
                            $routine->exercises = $exercises;
                        }
                        if ($days !== null) {
                            $routine->days = $days;
                        }
                        $routine->save();
                    }
                }
            }
        });

        $this->newLine();
        $this->info(sprintf(
            '%s · rutinas con cambios=%d · ejercicios enriquecidos=%d · sin match seguro=%d',
            $apply ? 'APLICADO' : 'DRY-RUN (sin escribir)',
            $routinesTouched,
            $itemsEnriched,
            $unmatched,
        ));
        if (! $apply) {
            $this->comment('Simulación. Ejecuta con --apply para persistir.');
        }

        return self::SUCCESS;
    }

    /**
     * Devuelve el item enriquecido (solo añade campos faltantes) o null si no
     * cambió. Preserva siempre todas las claves existentes.
     *
     * @param  array<string,mixed> $item
     * @return array<string,mixed>|null
     */
    private function enrichItem(array $item, ExerciseCatalogResolver $resolver, int &$itemsEnriched, int &$unmatched): ?array
    {
        // Ya tiene video → nada que hacer.
        if (! empty($item['video_url'])) {
            return null;
        }

        $exId = ! empty($item['exercise_id']) ? (int) $item['exercise_id'] : null;
        $match = $resolver->resolveSafe($exId, $item['name'] ?? null);
        if ($match === null) {
            $unmatched++;
            return null;
        }

        $media = $resolver->mediaFor($match);
        $before = $item;

        $item['exercise_id'] ??= $media['exercise_id'];
        if (empty($item['video_url'])) {
            $item['video_url'] = $media['video_url'];
        }
        if (empty($item['gif_url'])) {
            $item['gif_url'] = $media['gif_url'];
        }
        if (empty($item['thumbnail_url'])) {
            $item['thumbnail_url'] = $media['thumbnail_url'];
        }
        if (empty($item['media_type'])) {
            $item['media_type'] = $media['media_type'];
        }
        if (! isset($item['playback_speed']) || $item['playback_speed'] === null) {
            if ($media['playback_speed'] !== null) {
                $item['playback_speed'] = $media['playback_speed'];
            }
        }

        if ($item === $before) {
            return null;
        }
        $itemsEnriched++;

        return $item;
    }
}
