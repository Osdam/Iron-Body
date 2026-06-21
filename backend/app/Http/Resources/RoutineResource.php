<?php

namespace App\Http\Resources;

use App\Models\Exercise;
use App\Models\Routine;
use App\Models\RoutineExercise;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class RoutineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Routine $this */
        $exercises = $this->routineExercises
            ->sortBy('sort_order')
            ->values()
            ->map(fn (RoutineExercise $re): array => $this->serializeRoutineExercise($re))
            ->all();

        // Catálogo para enriquecer ejercicios guardados como JSON por nombre
        // (sin media). Se resuelve UNA sola vez por rutina (sin N+1): cubre tanto
        // la lista plana `exercises` como los ejercicios de cada día.
        $flatItems = is_array($this->exercises) ? $this->exercises : [];
        $dayItems  = $this->collectDayExercises();
        $catalog   = (! empty($flatItems) || ! empty($dayItems))
            ? $this->catalogLookup(array_merge($flatItems, $dayItems))
            : ['byId' => [], 'byName' => []];

        // Fallback: si la tabla normalizada está vacía pero la rutina trae
        // ejercicios en la columna JSON `exercises` (caso Api\RoutineController
        // store/update desde el CRM Angular), serializamos desde ahí para no
        // devolver un array vacío a la app. Se enriquece con la media del
        // catálogo local (video_url/gif_url/thumbnail_url) cuando hay match.
        if (empty($exercises) && ! empty($flatItems)) {
            $exercises = collect($flatItems)
                ->map(fn (array $ex, int $i): array => $this->enrichJsonExercise($ex, $i, $catalog))
                ->values()
                ->all();
        }

        // Programa multi-día (Lunes…Domingo). Cada día trae su propio enfoque y
        // lista de ejercicios. Si la rutina no define `days`, va vacío y la app
        // usa la lista plana `exercises` (rutina de un solo día).
        $days = $this->serializeDays($catalog);

        // Si la lista plana viene vacía pero hay días, la rellenamos con la unión
        // de todos los ejercicios de los días (compatibilidad con clientes viejos).
        if (empty($exercises) && ! empty($days)) {
            $exercises = collect($days)
                ->flatMap(fn (array $d) => $d['exercises'])
                ->values()
                ->all();
        }

        // Build muscle_group from exercises if not explicitly set.
        // El fallback del JSON expone la clave `muscle_group` plana; el formato
        // normal de serializeRoutineExercise() trae el nombre dentro de `exercise.muscle_group`.
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
            'exercises'         => $exercises,
            'exercise_count'    => count($exercises),
            'days'              => $days,
            'created_at'        => optional($this->created_at)->toIso8601String(),
        ];
    }

    /**
     * Normaliza la columna JSON `days` a un arreglo estable para la app:
     * [{ day, title, objective, exercises: [{ name, muscle_group, sets, reps, notes }] }]
     *
     * @param array{byId: array<int,Exercise>, byName: array<string,Exercise>} $catalog
     */
    private function serializeDays(array $catalog): array
    {
        if (! is_array($this->days) || empty($this->days)) {
            return [];
        }

        return collect($this->days)->map(function ($day, $i) use ($catalog): array {
            $day = is_array($day) ? $day : [];
            $rawExercises = is_array($day['exercises'] ?? null) ? $day['exercises'] : [];

            return [
                'day'       => (string) ($day['day'] ?? $day['weekday'] ?? ''),
                'title'     => (string) ($day['title'] ?? $day['name'] ?? ''),
                'objective' => (string) ($day['objective'] ?? ''),
                'sort_order'=> (int) ($day['sort_order'] ?? $i),
                'exercises' => collect($rawExercises)->map(function ($ex, int $j) use ($i, $catalog): array {
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

                    return $this->withCatalogMedia($base, $catalog);
                })->values()->all(),
            ];
        })->values()->all();
    }

    private function serializeRoutineExercise(RoutineExercise $re): array
    {
        /** @var Exercise|null $ex */
        $ex = $re->exercise;

        return [
            'id'         => (string) $re->id,
            'sets'       => (int) $re->sets,
            'reps'       => (string) ($re->reps ?: '10'),
            'weight'     => $re->weight !== null ? (float) $re->weight : null,
            'notes'      => $re->notes ?? '',
            'sort_order' => (int) $re->sort_order,
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
                'gif_url'           => $ex->gif_url,
                'thumbnail_url'     => $ex->thumbnail_url,
                'video_url'         => $ex->video_path,
            ] : null,
        ];
    }

    // ── Enriquecimiento de ejercicios JSON con la media del catálogo ──────────

    /**
     * Aplana los ejercicios de todos los días para resolverlos contra el
     * catálogo en una sola pasada.
     *
     * @return list<array<string,mixed>>
     */
    private function collectDayExercises(): array
    {
        if (! is_array($this->days) || empty($this->days)) {
            return [];
        }

        $out = [];
        foreach ($this->days as $day) {
            if (! is_array($day)) {
                continue;
            }
            $exs = is_array($day['exercises'] ?? null) ? $day['exercises'] : [];
            foreach ($exs as $ex) {
                if (is_array($ex)) {
                    $out[] = $ex;
                }
            }
        }

        return $out;
    }

    /**
     * Construye el mapa de resolución del catálogo para los ejercicios JSON de
     * esta rutina. UNA sola consulta (evita N+1): indexa por id y por nombre
     * normalizado, prefiriendo provider=local y descartando nombres ambiguos.
     *
     * @param  list<array<string,mixed>> $items
     * @return array{byId: array<int,Exercise>, byName: array<string,Exercise>}
     */
    private function catalogLookup(array $items): array
    {
        $ids = [];
        $names = [];
        foreach ($items as $ex) {
            if (! is_array($ex)) {
                continue;
            }
            if (! empty($ex['exercise_id'])) {
                $ids[(int) $ex['exercise_id']] = true;
            }
            $n = $this->normalizeName($ex['name'] ?? null);
            if ($n !== '') {
                $names[$n] = true;
            }
        }
        $ids = array_keys($ids);
        $names = array_keys($names);

        if (empty($ids) && empty($names)) {
            return ['byId' => [], 'byName' => []];
        }

        $candidates = Exercise::query()
            ->where(function ($q) use ($ids, $names): void {
                if (! empty($ids)) {
                    $q->orWhereIn('id', $ids);
                }
                if (! empty($names)) {
                    // Filtro grueso case-insensitive; el match fino se hace en PHP
                    // con normalizeName() (mismo criterio en ambos lados).
                    $q->orWhereIn(DB::raw('LOWER(name)'), $names);
                }
            })
            ->get([
                'id', 'name', 'provider', 'equipment', 'muscle_group', 'body_part',
                'gif_url', 'thumbnail_url', 'video_path', 'media_type', 'playback_speed',
            ]);

        $byId = [];
        $byName = [];
        $ambiguous = [];
        foreach ($candidates as $ex) {
            $byId[(int) $ex->id] = $ex;

            $key = $this->normalizeName($ex->name);
            if ($key === '' || isset($ambiguous[$key])) {
                continue;
            }

            if (! isset($byName[$key])) {
                $byName[$key] = $ex;
                continue;
            }

            // Ya hay un candidato para este nombre. Preferir provider=local; si
            // hay dos locales distintos con el mismo nombre, es ambiguo → se
            // descarta para no servir un video equivocado.
            $current = $byName[$key];
            $exLocal = $ex->provider === 'local';
            $curLocal = $current->provider === 'local';

            if ($exLocal && ! $curLocal) {
                $byName[$key] = $ex;
            } elseif ($exLocal && $curLocal && (int) $ex->id !== (int) $current->id) {
                unset($byName[$key]);
                $ambiguous[$key] = true;
            }
        }

        return ['byId' => $byId, 'byName' => $byName];
    }

    /**
     * Resuelve el ejercicio del catálogo para un item JSON: primero por
     * exercise_id, luego por nombre normalizado.
     *
     * @param  array<string,mixed> $item
     * @param  array{byId: array<int,Exercise>, byName: array<string,Exercise>} $catalog
     */
    private function resolveCatalogExercise(array $item, array $catalog): ?Exercise
    {
        $id = $item['exercise_id'] ?? null;
        if (! empty($id) && isset($catalog['byId'][(int) $id])) {
            return $catalog['byId'][(int) $id];
        }

        $key = $this->normalizeName($item['name'] ?? null);
        if ($key !== '' && isset($catalog['byName'][$key])) {
            return $catalog['byName'][$key];
        }

        return null;
    }

    /**
     * Devuelve el item con la media del catálogo añadida cuando falta. NO
     * sobreescribe media que ya viniera en el JSON. Si no hay match, conserva el
     * item tal cual (sin romper la rutina).
     *
     * @param  array<string,mixed> $item
     * @param  array{byId: array<int,Exercise>, byName: array<string,Exercise>} $catalog
     * @return array<string,mixed>
     */
    private function withCatalogMedia(array $item, array $catalog): array
    {
        $ex = $this->resolveCatalogExercise($item, $catalog);

        if ($ex === null) {
            $item['media_type'] = $item['media_type']
                ?? (! empty($item['video_url']) ? 'video' : 'gif');
            return $item;
        }

        if (empty($item['exercise_id'])) {
            $item['exercise_id'] = (int) $ex->id;
        }
        if (empty($item['video_url'])) {
            $item['video_url'] = $this->mediaUrl($ex->video_path);
        }
        if (empty($item['gif_url'])) {
            $item['gif_url'] = $this->mediaUrl($ex->gif_url);
        }
        if (empty($item['thumbnail_url'])) {
            $item['thumbnail_url'] = $this->mediaUrl($ex->thumbnail_url);
        }
        if (empty($item['equipment'])) {
            $item['equipment'] = $ex->equipment ?? '';
        }
        if (empty($item['muscle_group'])) {
            $item['muscle_group'] = $ex->muscle_group ?? $ex->body_part ?? '';
        }
        if (! isset($item['playback_speed']) || $item['playback_speed'] === null) {
            $item['playback_speed'] = $ex->playback_speed;
        }

        $item['media_type'] = ! empty($item['video_url'])
            ? 'video'
            : ($item['media_type'] ?? 'gif');

        return $item;
    }

    /**
     * URL absoluta de una media: deja igual las http(s); resuelve rutas del
     * disco public ('exercises/...' o '/storage/...') contra el host público.
     * En producción `video_path` ya es absoluta, así que esto es defensivo.
     */
    private function mediaUrl(?string $v): ?string
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $v)) {
            return $v;
        }
        $base = rtrim(config('app.public_url') ?: url('/'), '/');
        $rel = ltrim($v, '/');
        if (! str_starts_with($rel, 'storage/')) {
            $rel = 'storage/' . $rel;
        }

        return "{$base}/{$rel}";
    }

    /**
     * Normaliza un nombre de ejercicio para comparación: minúsculas (UTF-8),
     * recorte y colapso de espacios internos.
     */
    private function normalizeName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return mb_strtolower($name, 'UTF-8');
    }

    // ── Mapeo de un item JSON plano (lista `exercises`) ───────────────────────

    /**
     * @param  array<string,mixed> $ex
     * @param  array{byId: array<int,Exercise>, byName: array<string,Exercise>} $catalog
     * @return array<string,mixed>
     */
    private function enrichJsonExercise(array $ex, int $i, array $catalog): array
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

        return $this->withCatalogMedia($base, $catalog);
    }
}
