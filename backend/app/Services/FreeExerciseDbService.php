<?php

namespace App\Services;

use App\Models\Exercise;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fallback open-data: **Free Exercise DB** (github.com/yuhonas/free-exercise-db)
 * vía CDN jsDelivr. SIN API key, SIN marca de agua. Imágenes estáticas
 * (2 frames: inicio/fin del movimiento → cross-fade en Flutter).
 *
 * Solo se usa si FitGif (y WorkoutX si está habilitado) no responden.
 * Arquitectura: Flutter → Laravel → fuente. Catálogo cacheado (Laravel cache +
 * tabla `exercises`) para no descargar el JSON en cada flip.
 */
class FreeExerciseDbService
{
    private string $baseUrl;
    private int $cacheTtl;
    private string $source;

    /** Mapeo ES→EN de ejercicios. */
    private const ES_EN = [
        'press de banca'  => 'bench press',
        'press banca'     => 'bench press',
        'sentadilla'      => 'squat',
        'peso muerto'     => 'deadlift',
        'curl biceps'     => 'biceps curl',
        'curl bíceps'     => 'biceps curl',
        'curl con mancuernas' => 'biceps curl',
        'jalon al pecho'  => 'lat pulldown',
        'jalón al pecho'  => 'lat pulldown',
        'dominadas'       => 'pull up',
        'press militar'   => 'shoulder press',
        'plancha'         => 'plank',
        'burpees'         => 'burpee',
        'zancadas'        => 'lunge',
        'fondos'          => 'dips',
    ];

    /** Mapeo ES→EN de músculos/zonas. */
    private const MUSCLE_EN = [
        'pecho' => 'chest', 'pectoral' => 'chest', 'pectorales' => 'chest',
        'espalda' => 'back', 'dorsal' => 'lats', 'dorsales' => 'lats',
        'biceps' => 'biceps', 'bíceps' => 'biceps',
        'triceps' => 'triceps', 'tríceps' => 'triceps',
        'hombro' => 'shoulders', 'hombros' => 'shoulders',
        'pierna' => 'quadriceps', 'piernas' => 'quadriceps',
        'cuadriceps' => 'quadriceps', 'cuádriceps' => 'quadriceps',
        'gluteos' => 'glutes', 'glúteos' => 'glutes',
        'isquiotibiales' => 'hamstrings', 'femoral' => 'hamstrings',
        'pantorrilla' => 'calves', 'gemelos' => 'calves',
        'core' => 'abdominals', 'abdomen' => 'abdominals',
        'abdominales' => 'abdominals', 'brazos' => 'biceps',
    ];

    public function __construct()
    {
        $cfg = config('services.freeexercisedb');
        $this->baseUrl  = $cfg['base_url'] ?? 'https://cdn.jsdelivr.net/gh/yuhonas/free-exercise-db@main';
        $this->cacheTtl = (int) ($cfg['cache_ttl'] ?? 86400);
        $this->source   = $cfg['source_label'] ?? 'Free Exercise DB';
    }

    // ── API pública ─────────────────────────────────────────────────────────

    public function all(int $limit = 30, int $offset = 0): array
    {
        $limit  = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $rows = array_slice($this->catalog(), $offset, $limit);

        return $this->persistAll(array_map([$this, 'normalize'], $rows));
    }

    public function find(string $externalId): ?array
    {
        $local = Exercise::where('provider', 'freeexercisedb')
            ->where('external_id', $externalId)->first();
        if ($local) {
            return $local->toReference();
        }
        foreach ($this->catalog() as $row) {
            if (($row['id'] ?? null) === $externalId) {
                $ref = $this->normalize($row);
                $this->persist($ref);
                return $ref;
            }
        }
        return null;
    }

    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $term = $this->translate($query, self::ES_EN);

        $local = Exercise::where('provider', 'freeexercisedb')
            ->where('name', 'like', "%$term%")->limit(15)->get();
        if ($local->isNotEmpty()) {
            return $local->map->toReference()->all();
        }

        $needle = Str::lower($term);
        $hits = array_values(array_filter(
            $this->catalog(),
            fn ($r) => str_contains(Str::lower($r['name'] ?? ''), $needle),
        ));

        if (empty($hits)) {
            $muscle = $this->translate($query, self::MUSCLE_EN);
            $hits = $this->byMuscleRows($muscle, 8);
        }

        $hits = array_slice($hits, 0, 15);
        $this->log('search', $query, count($hits));

        return $this->persistAll(array_map([$this, 'normalize'], $hits));
    }

    public function byMuscle(string $muscle): array
    {
        $en = $this->translate(trim($muscle), self::MUSCLE_EN);
        if ($en === '') {
            return [];
        }
        $rows = $this->byMuscleRows($en, 30);
        $this->log('by-muscle', $muscle, count($rows));

        return $this->persistAll(array_map([$this, 'normalize'], $rows));
    }

    public function sync(): int
    {
        $count = 0;
        foreach (array_values(self::ES_EN) as $name) {
            $count += count($this->search($name));
        }
        return $count;
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function translate(string $value, array $dict): string
    {
        return $dict[Str::lower(trim($value))] ?? $value;
    }

    private function byMuscleRows(string $muscle, int $limit): array
    {
        $m = Str::lower($muscle);
        $hits = array_filter($this->catalog(), function ($r) use ($m) {
            $muscles = array_map('strtolower', array_merge(
                $r['primaryMuscles'] ?? [],
                $r['secondaryMuscles'] ?? [],
            ));
            foreach ($muscles as $mu) {
                if (str_contains($mu, $m) || str_contains($m, $mu)) {
                    return true;
                }
            }
            return false;
        });
        return array_slice(array_values($hits), 0, $limit);
    }

    /** @return array<int,array<string,mixed>> */
    private function catalog(): array
    {
        return Cache::remember('freeexercisedb:catalog', $this->cacheTtl, function () {
            try {
                $resp = Http::withHeaders(['Accept' => 'application/json'])
                    ->timeout(20)->retry(1, 300)
                    ->get($this->baseUrl . '/dist/exercises.json');

                $this->log('catalog', 'dist/exercises.json', 0, $resp->status());

                if (! $resp->successful()) {
                    return [];
                }
                $json = $resp->json();
                return is_array($json) ? $json : [];
            } catch (Throwable $e) {
                Log::warning('FreeExerciseDB catálogo no disponible', [
                    'provider' => 'freeexercisedb',
                    'reason'   => Str::limit($e->getMessage(), 300),
                ]);
                return [];
            }
        });
    }

    private function normalize(array $r): array
    {
        $name   = trim($r['name'] ?? '');
        $images = array_values($r['images'] ?? []);
        $gif    = isset($images[0]) ? $this->imageUrl($images[0]) : null;
        $thumb  = isset($images[1]) ? $this->imageUrl($images[1]) : null;
        $primary = $r['primaryMuscles'][0] ?? null;

        return [
            'external_id'   => (string) ($r['id'] ?? Str::slug($name)),
            'name'          => $name,
            'body_part'     => $r['category'] ?? $primary,
            'target'        => $primary,
            'equipment'     => $r['equipment'] ?? null,
            'gif_url'       => $gif,
            'thumbnail_url' => $thumb,
            'instructions'  => array_values($r['instructions'] ?? []),
            'provider'      => 'freeexercisedb',
            'source'        => $this->source . ' · Open data',
        ];
    }

    private function imageUrl(string $path): string
    {
        return $this->baseUrl . '/exercises/' . ltrim($path, '/');
    }

    private function persistAll(array $refs): array
    {
        foreach ($refs as $ref) {
            $this->persist($ref);
        }
        return array_values($refs);
    }

    private function persist(array $ref): void
    {
        try {
            Exercise::updateOrCreate(
                ['provider' => 'freeexercisedb', 'external_id' => $ref['external_id']],
                $ref,
            );
        } catch (Throwable $e) {
            Log::warning('FreeExerciseDB persist falló', [
                'provider' => 'freeexercisedb',
                'reason'   => Str::limit($e->getMessage(), 300),
            ]);
        }
    }

    private function log(string $endpoint, string $query, int $count, ?int $status = 200): void
    {
        Log::info('FreeExerciseDB request', [
            'provider'     => 'freeexercisedb',
            'endpoint'     => $endpoint,
            'query'        => Str::limit($query, 80),
            'http_status'  => $status,
            'result_count' => $count,
        ]);
    }
}
