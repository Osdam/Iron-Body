<?php

namespace App\Services;

use App\Models\Exercise;
use Illuminate\Support\Facades\Log;

/**
 * Capa de proveedor de referencias visuales de ejercicios.
 *
 * Conmutador `services.exercises.provider`:
 *   fitgif | workoutx | freeexercisedb | local
 *
 * Cadena de fallback cuando el primario (FitGif) no responde:
 *   FitGif → WorkoutX (solo si SHOW_WORKOUTX_GIFS) → Free Exercise DB → local.
 *
 * `gif_url`/`thumbnail_url` se entregan ya finales:
 *  - fitgif:   URL del proxy del backend (la signed URL caduca; key oculta).
 *  - workoutx: proxy del backend (API key oculta), solo si flag activa.
 *  - freeexercisedb: URLs públicas del CDN (sin key, sin marca de agua).
 *
 * Flutter nunca llama a un proveedor externo ni ve credenciales.
 */
class ExerciseProviderService
{
    public function __construct(
        private readonly FitGifExerciseService $fitgif,
        private readonly WorkoutXService $workoutx,
        private readonly FreeExerciseDbService $freeexercisedb,
    ) {}

    private function provider(): string
    {
        return config('services.exercises.provider', 'fitgif');
    }

    private function showWorkoutxGifs(): bool
    {
        return (bool) config('services.exercises.show_workoutx_gifs', false);
    }

    private function showFreeExerciseDb(): bool
    {
        return (bool) config('services.exercises.show_free_exercise_db', false);
    }

    // ── API usada por el controller ──────────────────────────────────────────

    public function all(int $limit = 30, int $offset = 0): array
    {
        return $this->finalize($this->dispatch(
            fn () => $this->fitgif->all($limit, $offset),
            fn () => $this->workoutx->all($limit, $offset),
            fn () => $this->freeexercisedb->all($limit, $offset),
            fn () => Exercise::orderBy('name')->limit($limit)->offset($offset)
                ->get()->map->toReference()->all(),
        ));
    }

    public function find(string $id): ?array
    {
        $ref = match ($this->provider()) {
            'workoutx'       => $this->workoutx->find($id),
            'freeexercisedb' => $this->freeexercisedb->find($id),
            'local'          => optional(Exercise::where('external_id', $id)->first())->toReference(),
            default          => $this->fitgif->find($id)
                                    ?? ($this->showFreeExerciseDb()
                                        ? $this->freeexercisedb->find($id)
                                        : null),
        };
        return $ref ? $this->finalizeOne($ref) : null;
    }

    public function search(string $q): array
    {
        return $this->finalize($this->dispatch(
            fn () => $this->fitgif->search($q),
            fn () => $this->workoutx->search($q),
            fn () => $this->freeexercisedb->search($q),
            fn () => Exercise::where('name', 'like', "%$q%")
                ->limit(15)->get()->map->toReference()->all(),
        ));
    }

    public function byMuscle(string $muscle): array
    {
        return $this->finalize($this->dispatch(
            fn () => $this->fitgif->byMuscle($muscle),
            fn () => $this->workoutx->byMuscle($muscle),
            fn () => $this->freeexercisedb->byMuscle($muscle),
            fn () => Exercise::where('body_part', 'like', "%$muscle%")
                ->orWhere('target', 'like', "%$muscle%")
                ->limit(30)->get()->map->toReference()->all(),
        ));
    }

    public function sync(): array
    {
        return match ($this->provider()) {
            'workoutx'       => ['ok' => $this->workoutx->sync(), 'fail' => 0, 'details' => []],
            'freeexercisedb' => ['ok' => $this->freeexercisedb->sync(), 'fail' => 0, 'details' => []],
            'local'          => ['ok' => 0, 'fail' => 0, 'details' => []],
            default          => $this->fitgif->sync(),
        };
    }

    /** Proxy del GIF de WorkoutX (la key vive solo en el backend). */
    public function workoutxGif(string $filename)
    {
        return $this->workoutx->gifResponse($filename);
    }

    /** GIF de FitGif guardado en disco (sin llamar a FitGif; key oculta). */
    public function fitgifGifContents(string $externalId): ?string
    {
        return $this->fitgif->gifContents($externalId);
    }

    /** MP4 optimizado de FitGif (ruta absoluta, para Range). */
    public function fitgifVideoPath(string $externalId): ?string
    {
        return $this->fitgif->videoAbsolutePath($externalId);
    }

    /** Genera MP4 de los GIFs ya cacheados (ffmpeg local, sin FitGif). */
    public function fitgifTranscodeAll(?callable $progress = null, bool $force = false): array
    {
        return $this->fitgif->transcodeAll($progress, $force);
    }

    /** Sincroniza el catálogo FitGif del diccionario (throttled). */
    public function fitgifSync(?callable $progress = null): array
    {
        return $this->fitgif->sync($progress);
    }

    public function fitgifDiagnose(string $localName): array
    {
        return $this->fitgif->diagnose($localName, store: false);
    }

    // ── Selección de proveedor + fallback ────────────────────────────────────

    private function dispatch(
        callable $fitgif,
        callable $workoutx,
        callable $freeexercisedb,
        callable $local,
    ): array {
        $p = $this->provider();

        if ($p === 'local') {
            return $local();
        }
        if ($p === 'workoutx') {
            $res = $workoutx();
            return ! empty($res) ? $res : $local();
        }
        if ($p === 'freeexercisedb') {
            $res = $freeexercisedb();
            return ! empty($res) ? $res : $local();
        }

        // fitgif (primario). Fallbacks SOLO si están habilitados por flag.
        // En la demo ambos están apagados → si FitGif no trae nada se
        // devuelve [] y Flutter muestra el placeholder premium (nunca
        // WorkoutX ni Free Exercise DB).
        $res = $fitgif();
        if (! empty($res)) {
            return $res;
        }
        if ($this->showWorkoutxGifs()) {
            Log::info('Exercise provider fallback', ['from' => 'fitgif', 'to' => 'workoutx']);
            $res = $workoutx();
            if (! empty($res)) {
                return $res;
            }
        }
        if ($this->showFreeExerciseDb()) {
            Log::info('Exercise provider fallback', ['from' => 'fitgif', 'to' => 'freeexercisedb']);
            $res = $freeexercisedb();
            if (! empty($res)) {
                return $res;
            }
        }
        return [];
    }

    // ── Finalización de URLs + flags de visibilidad ──────────────────────────

    private function finalize(array $refs): array
    {
        return array_values(array_map([$this, 'finalizeOne'], $refs));
    }

    private function finalizeOne(array $ref): array
    {
        $provider = $ref['provider'] ?? 'fitgif';
        $cfg = config('services.exercises');
        $base = rtrim(config('app.public_url') ?: url('/'), '/');

        $videoUrl = null;
        $mediaType = $ref['media_type'] ?? 'gif';
        if ($provider === 'fitgif') {
            // Todo lo visible al usuario en español (conserva original_*).
            $ref = $this->fitgif->localize($ref);
            // El GIF se guardó en disco durante el sync → proxy estable del
            // backend (gif_url='stored' es el centinela que marca match).
            $show = (bool) ($cfg['show_fitgif_gifs'] ?? true);
            $id = $ref['external_id'] ?? null;
            $hasGif = $show && $id && ! empty($ref['gif_url']);
            $ref['gif_url'] = $hasGif
                ? "{$base}/api/exercises/fitgif/gif/{$id}"
                : null;
            // MP4 optimizado si existe; el GIF queda como fallback.
            if ($show && $id && ! empty($ref['_has_video'])) {
                $videoUrl  = "{$base}/api/exercises/fitgif/video/{$id}.mp4";
                $mediaType = 'video';
            } else {
                $mediaType = $hasGif ? 'gif' : ($mediaType ?? 'gif');
            }
            $ref['thumbnail_url'] = $this->isHttpUrl($ref['thumbnail_url'] ?? null)
                ? $ref['thumbnail_url']
                : null;
        } elseif ($provider === 'workoutx') {
            $file = $ref['gif_url'] ?? null;
            $show = (bool) ($cfg['show_workoutx_gifs'] ?? false);
            $ref['gif_url'] = ($show && $file && preg_match('/^[A-Za-z0-9_-]+\.gif$/', $file))
                ? "{$base}/api/exercises/gif/{$file}"
                : null;
            $ref['thumbnail_url'] = null;
        } elseif ($provider === 'freeexercisedb') {
            // CDN público: sin key, sin marca de agua → se exponen directas.
            if (! $this->isHttpUrl($ref['gif_url'] ?? null)) {
                $ref['gif_url'] = null;
            }
            if (! $this->isHttpUrl($ref['thumbnail_url'] ?? null)) {
                $ref['thumbnail_url'] = null;
            }
        }

        // Forma estable garantizada hacia Flutter.
        return [
            'external_id'   => $ref['external_id'] ?? '',
            'name'          => $ref['name'] ?? '',
            'body_part'     => $ref['body_part'] ?? null,
            'target'        => $ref['target'] ?? null,
            'equipment'     => $ref['equipment'] ?? null,
            'gif_url'       => $ref['gif_url'] ?? null,
            'video_url'     => $videoUrl,
            'media_type'    => $videoUrl ? 'video' : ($mediaType ?? 'gif'),
            'thumbnail_url' => $ref['thumbnail_url'] ?? null,
            'instructions'  => array_values($ref['instructions'] ?? []),
            'provider'      => $provider,
            'source'        => $ref['source'] ?? null,
            'playback_speed' => $ref['playback_speed'] ?? null,
            // Originales en inglés (por si se necesitan); la UI usa los ES.
            'original_name'         => $ref['original_name'] ?? null,
            'original_body_part'    => $ref['original_body_part'] ?? null,
            'original_equipment'    => $ref['original_equipment'] ?? null,
            'original_instructions' => $ref['original_instructions'] ?? null,
        ];
    }

    private function isHttpUrl(?string $v): bool
    {
        return is_string($v) && (bool) preg_match('#^https?://#i', $v);
    }
}
