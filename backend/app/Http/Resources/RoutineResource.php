<?php

namespace App\Http\Resources;

use App\Models\Exercise;
use App\Models\Routine;
use App\Models\RoutineExercise;
use App\Services\Exercises\ExerciseCatalogResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoutineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Routine $this */
        $resolver = app(ExerciseCatalogResolver::class);

        $exercises = $this->routineExercises
            ->sortBy('sort_order')
            ->values()
            ->map(fn (RoutineExercise $re): array => $this->serializeRoutineExercise($re, $resolver))
            ->all();

        // Fallback: si la tabla normalizada está vacía pero la rutina trae
        // ejercicios en la columna JSON `exercises` (caso Api\RoutineController
        // store/update desde el CRM Angular), serializamos desde ahí y
        // enriquecemos con la media del catálogo vía el resolver central
        // (exercise_id → external_id → alias verificado → nombre/local_name).
        $flatItems = is_array($this->exercises) ? $this->exercises : [];
        if (empty($exercises) && ! empty($flatItems)) {
            $exercises = collect($flatItems)
                ->map(fn (array $ex, int $i): array => $this->enrichJsonExercise($ex, $i, $resolver))
                ->values()
                ->all();
        }

        // Programa multi-día (Lunes…Domingo). Cada día trae su propio enfoque y
        // lista de ejercicios; también se enriquecen con el catálogo.
        $days = $this->serializeDays($resolver);

        // Si la lista plana viene vacía pero hay días, la rellenamos con la unión
        // de todos los ejercicios de los días (compatibilidad con clientes viejos).
        if (empty($exercises) && ! empty($days)) {
            $exercises = collect($days)
                ->flatMap(fn (array $d) => $d['exercises'])
                ->values()
                ->all();
        }

        // Build muscle_group from exercises if not explicitly set.
        $muscleGroup = trim((string) ($this->muscle_group ?? ''));
        if ($muscleGroup === '' && ! empty($exercises)) {
            $muscleGroup = collect($exercises)
                ->map(fn (array $e) => $e['muscle_group'] ?? $e['exercise']['muscle_group'] ?? null)
                ->filter()
                ->unique()
                ->values()
                ->implode(' · ');
        }

        return [
            'id'                => (string) $this->id,
            'name'              => $this->name,
            'objective'         => $this->objective ?? '',
            'level'             => $this->level ?? 'Principiante',
            'gender'            => $this->gender ?? '',
            'muscle_group'      => $muscleGroup,
            'estimated_minutes' => (int) ($this->estimated_minutes ?? $this->duration_minutes ?? 0),
            'days_per_week'     => (int) ($this->days_per_week ?? (! empty($days) ? count($days) : 0)),
            'description'       => $this->description ?? '',
            'notes'             => $this->notes ?? '',
            'is_assigned'       => (bool) $this->is_assigned,
            'created_by_admin'  => (bool) $this->created_by_admin,
            'is_template'       => (bool) $this->is_template,
            // Clasificación para la app (sin migración):
            //  - semi_personalized: plan base del gimnasio (plantilla/Seeder, por
            //    días, sin vínculo al catálogo) → premium SIN forzar video.
            //  - personalized: hecha para el miembro con ejercicios del catálogo
            //    (exercise_id) → puede mostrar referencia visual/video.
            'routine_type'      => $this->classifyRoutineType($exercises, $days),
            'exercises'         => $exercises,
            'exercise_count'    => count($exercises),
            'days'              => $days,
            'created_at'        => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * Clasifica la rutina para que la app separe los flujos:
     *  - 'semi_personalized': plantilla/base del gimnasio (Seeder), normalmente
     *    multi-día y sin vínculo al catálogo. NO debe forzar video.
     *  - 'personalized': hecha para el miembro con ejercicios del catálogo
     *    (algún exercise_id) → puede mostrar referencia visual.
     *
     * Usa SOLO campos existentes (sin migración). `is_template` es el separador
     * principal (los planes base del gimnasio son plantillas).
     *
     * @param  list<array<string,mixed>> $exercises
     * @param  list<array<string,mixed>> $days
     */
    private function classifyRoutineType(array $exercises, array $days): string
    {
        if ((bool) $this->is_template) {
            return 'semi_personalized';
        }

        $hasCatalogLink = $this->anyHasExerciseId($exercises)
            || collect($days)->contains(fn (array $d) => $this->anyHasExerciseId($d['exercises'] ?? []));

        if ($hasCatalogLink) {
            return 'personalized';
        }

        // Sin vínculo al catálogo: si es un programa multi-día es un plan base.
        return ! empty($days) ? 'semi_personalized' : 'personalized';
    }

    /** @param array<int,array<string,mixed>> $items */
    private function anyHasExerciseId(array $items): bool
    {
        foreach ($items as $it) {
            if (is_array($it) && ! empty($it['exercise_id'])) {
                return true;
            }
            // Ruta normalizada: exercise_id puede venir dentro de `exercise`.
            if (is_array($it) && ! empty($it['exercise']['id'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normaliza la columna JSON `days` a un arreglo estable para la app,
     * enriqueciendo cada ejercicio con la media del catálogo.
     */
    private function serializeDays(ExerciseCatalogResolver $resolver): array
    {
        if (! is_array($this->days) || empty($this->days)) {
            return [];
        }

        return collect($this->days)->map(function ($day, $i) use ($resolver): array {
            $day = is_array($day) ? $day : [];
            $rawExercises = is_array($day['exercises'] ?? null) ? $day['exercises'] : [];

            return [
                'day'       => (string) ($day['day'] ?? $day['weekday'] ?? ''),
                'title'     => (string) ($day['title'] ?? $day['name'] ?? ''),
                'objective' => (string) ($day['objective'] ?? ''),
                'sort_order'=> (int) ($day['sort_order'] ?? $i),
                'exercises' => collect($rawExercises)->map(function ($ex, int $j) use ($i, $resolver): array {
                    $base = [
                        'id'           => (string) ($ex['id'] ?? ($i + 1) . '-' . ($j + 1)),
                        'exercise_id'  => $ex['exercise_id'] ?? null,
                        'name'         => (string) ($ex['name'] ?? ''),
                        'muscle_group' => (string) ($ex['muscle_group'] ?? $ex['muscleGroup'] ?? ''),
                        'equipment'    => (string) ($ex['equipment'] ?? ''),
                        'sets'         => (int) ($ex['sets'] ?? 3),
                        'reps'         => (string) ($ex['reps'] ?? '10'),
                        'weight'       => (string) ($ex['weight'] ?? $ex['suggestedWeight'] ?? ''),
                        'notes'        => (string) ($ex['notes'] ?? ''),
                        'sort_order'   => (int) ($ex['sort_order'] ?? $ex['order'] ?? $j),
                        'gif_url'      => $ex['gif_url'] ?? null,
                        'thumbnail_url'=> $ex['thumbnail_url'] ?? null,
                        'video_url'    => $ex['video_url'] ?? null,
                        'media_type'   => $ex['media_type'] ?? null,
                    ];

                    return $this->withCatalogMedia($base, $resolver);
                })->values()->all(),
            ];
        })->values()->all();
    }

    private function serializeRoutineExercise(RoutineExercise $re, ExerciseCatalogResolver $resolver): array
    {
        /** @var Exercise|null $ex */
        $ex = $re->exercise;

        // Media absoluta y consistente con el resto del recurso.
        $media = $ex ? $resolver->mediaFor($ex) : null;

        return [
            'id'             => (string) $re->id,
            'exercise_id'    => $ex ? (int) $ex->id : null,
            'sets'           => (int) $re->sets,
            'reps'           => (string) ($re->reps ?: '10'),
            'weight'         => $re->weight !== null ? (float) $re->weight : null,
            'notes'          => $re->notes ?? '',
            'sort_order'     => (int) $re->sort_order,
            // Media también a nivel de item (forma estable que la app puede leer
            // directo, igual que en los ejercicios JSON).
            'video_url'      => $media['video_url'] ?? null,
            'media_type'     => $media['media_type'] ?? null,
            'gif_url'        => $media['gif_url'] ?? null,
            'thumbnail_url'  => $media['thumbnail_url'] ?? null,
            'playback_speed' => $media['playback_speed'] ?? null,
            'exercise'   => $ex ? [
                'id'                => (string) $ex->id,
                'name'              => $ex->name,
                'muscle_group'      => $ex->muscle_group ?? $ex->body_part ?? '',
                'equipment'         => $ex->equipment ?? '',
                'difficulty'        => $ex->difficulty ?? 'Principiante',
                'description'       => $ex->description ?? '',
                'steps'             => $ex->steps ?? [],
                'tips'              => $ex->tips ?? [],
                'common_mistakes'   => $ex->common_mistakes ?? [],
                'secondary_muscles' => $ex->secondary_muscles ?? [],
                'muscles_worked'    => $ex->muscles_worked ?? [],
                'suggested_sets'    => (int) ($ex->suggested_sets ?? 3),
                'suggested_reps'    => $ex->suggested_reps ?? '8-12',
                'gif_url'           => $media['gif_url'] ?? $ex->gif_url,
                'thumbnail_url'     => $media['thumbnail_url'] ?? $ex->thumbnail_url,
                'video_url'         => $media['video_url'] ?? $ex->video_path,
                'media_type'        => $media['media_type'] ?? null,
                'playback_speed'    => $media['playback_speed'] ?? null,
            ] : null,
        ];
    }

    // ── Enriquecimiento de ejercicios JSON con la media del catálogo ──────────

    /**
     * Añade la media del catálogo a un item de ejercicio si el resolver halla
     * un match SEGURO (id/external_id/alias/nombre exacto). NO sobrescribe media
     * que ya viniera; si no hay match, conserva el item sin romper la rutina.
     *
     * @param  array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function withCatalogMedia(array $item, ExerciseCatalogResolver $resolver): array
    {
        $exId = ! empty($item['exercise_id']) ? (int) $item['exercise_id'] : null;
        $match = $resolver->resolveSafe($exId, $item['name'] ?? null);

        if ($match === null) {
            $item['media_type'] = $item['media_type']
                ?? (! empty($item['video_url']) ? 'video' : 'gif');
            return $item;
        }

        $media = $resolver->mediaFor($match);

        if (empty($item['exercise_id'])) {
            $item['exercise_id'] = $media['exercise_id'];
        }
        if (empty($item['video_url'])) {
            $item['video_url'] = $media['video_url'];
        }
        if (empty($item['gif_url'])) {
            $item['gif_url'] = $media['gif_url'];
        }
        if (empty($item['thumbnail_url'])) {
            $item['thumbnail_url'] = $media['thumbnail_url'];
        }
        if (empty($item['equipment'])) {
            $item['equipment'] = $media['equipment'] ?? '';
        }
        if (empty($item['muscle_group'])) {
            $item['muscle_group'] = $media['muscle_group'] ?? '';
        }
        if (! isset($item['playback_speed']) || $item['playback_speed'] === null) {
            $item['playback_speed'] = $media['playback_speed'];
        }

        $item['media_type'] = ! empty($item['video_url'])
            ? 'video'
            : ($item['media_type'] ?? 'gif');

        return $item;
    }

    /**
     * @param  array<string,mixed> $ex
     * @return array<string,mixed>
     */
    private function enrichJsonExercise(array $ex, int $i, ExerciseCatalogResolver $resolver): array
    {
        $base = [
            'id'            => $ex['id'] ?? ($i + 1),
            'exercise_id'   => $ex['exercise_id'] ?? null,
            'name'          => $ex['name'] ?? '',
            'muscle_group'  => $ex['muscleGroup'] ?? $ex['muscle_group'] ?? '',
            'equipment'     => $ex['equipment'] ?? '',
            'sets'          => (int) ($ex['sets'] ?? 3),
            'reps'          => (int) ($ex['reps'] ?? 10),
            'weight'        => (string) ($ex['suggestedWeight'] ?? $ex['weight'] ?? ''),
            'notes'         => (string) ($ex['notes'] ?? ''),
            'sort_order'    => (int) ($ex['order'] ?? ($i + 1)),
            'gif_url'       => $ex['gif_url'] ?? null,
            'thumbnail_url' => $ex['thumbnail_url'] ?? null,
            'video_url'     => $ex['video_url'] ?? null,
            'media_type'    => $ex['media_type'] ?? null,
        ];

        return $this->withCatalogMedia($base, $resolver);
    }
}
