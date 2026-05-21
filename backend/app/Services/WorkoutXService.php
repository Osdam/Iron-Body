<?php

namespace App\Services;

use App\Models\Exercise;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Integración con WorkoutX (proveedor principal de referencias visuales de
 * ejercicios: GIF + metadatos).
 *
 * API real (descubierta vía https://api.workoutxapp.com/openapi.json):
 *  - Todas las rutas cuelgan de `/v1`.
 *  - Auth por cabecera `X-WorkoutX-Key` (la key empieza por `wx_`).
 *  - Listas devuelven `{ total, count, offset, data: [Exercise] }`.
 *  - `Exercise.gifUrl` es relativo (p. ej. `/v1/gifs/0025.gif`) y exige la
 *    API key para descargarse → se sirve vía proxy del backend para que la
 *    key NUNCA llegue a Flutter.
 *
 * Reglas: la API key vive SOLO en el backend; persistencia local + caché para
 * no llamar a WorkoutX en cada request; fallback limpio y logs sanitizados.
 */
class WorkoutXService
{
    private string $baseUrl;
    private string $prefix;
    private ?string $apiKey;
    private string $provider;
    private string $authHeader;
    private int $cacheTtl;

    /**
     * Diccionario ES→EN para el catálogo de Iron Body. Permite que Flutter
     * envíe el nombre en español y WorkoutX (en inglés) igual resuelva un GIF.
     */
    private const ES_EN = [
        'press de banca'        => 'bench press',
        'press banca'           => 'bench press',
        'sentadilla'            => 'squat',
        'peso muerto'           => 'deadlift',
        'dominadas'             => 'pull up',
        'dominada'              => 'pull up',
        'press militar'         => 'overhead press',
        'curl con mancuernas'   => 'dumbbell curl',
        'curl de biceps'        => 'biceps curl',
        'plancha'               => 'plank',
        'burpees'               => 'burpee',
        'zancadas'              => 'lunge',
        'fondos'                => 'dips',
    ];

    /** Términos de músculo/parte del cuerpo ES→EN para WorkoutX. */
    private const MUSCLE_EN = [
        'pecho' => 'chest', 'pectoral' => 'chest', 'pectorales' => 'chest',
        'espalda' => 'back', 'biceps' => 'upper arms', 'bíceps' => 'upper arms',
        'triceps' => 'upper arms', 'tríceps' => 'upper arms',
        'hombros' => 'shoulders', 'hombro' => 'shoulders',
        'piernas' => 'upper legs', 'pierna' => 'upper legs',
        'cuadriceps' => 'upper legs', 'cuádriceps' => 'upper legs',
        'gluteos' => 'upper legs', 'glúteos' => 'upper legs',
        'isquiotibiales' => 'upper legs', 'pantorrilla' => 'lower legs',
        'core' => 'waist', 'abdomen' => 'waist', 'abdominales' => 'waist',
        'cardio' => 'cardio', 'brazos' => 'upper arms',
    ];

    public function __construct()
    {
        $cfg = config('services.workoutx');
        $this->baseUrl    = $cfg['base_url'] ?? 'https://api.workoutxapp.com';
        $this->prefix     = $cfg['api_prefix'] ?? '/v1';
        $this->apiKey     = $cfg['api_key'] ?? null;
        $this->provider   = $cfg['provider'] ?? 'workoutx';
        $this->authHeader = $cfg['auth_header'] ?? 'X-WorkoutX-Key';
        $this->cacheTtl   = (int) ($cfg['cache_ttl'] ?? 43200);
    }

    public function hasApiKey(): bool
    {
        return filled($this->apiKey);
    }

    // ── API pública ─────────────────────────────────────────────────────────

    /** Listado general de ejercicios (normalizados). */
    public function all(int $limit = 30, int $offset = 0): array
    {
        $limit  = max(1, min($limit, 100));
        $offset = max(0, $offset);

        return $this->guard(
            cacheKey: "wx:all:$limit:$offset",
            external: fn () => $this->fetch('/exercises', ['limit' => $limit, 'offset' => $offset]),
            fallback: fn () => Exercise::where('provider', $this->provider)
                ->orderBy('name')->limit($limit)->offset($offset)->get()
                ->map->toReference()->all(),
        );
    }

    /** Un ejercicio por su id externo. */
    public function find(string $externalId): ?array
    {
        $local = Exercise::where('provider', $this->provider)
            ->where('external_id', $externalId)->first();
        if ($local) {
            return $local->toReference();
        }

        $list = $this->guard(
            cacheKey: "wx:id:$externalId",
            external: fn () => $this->fetch('/exercises/exercise/' . rawurlencode($externalId)),
            fallback: fn () => [],
        );

        return $list[0] ?? null;
    }

    /**
     * Búsqueda por nombre. Acepta español (se traduce a inglés) y hace
     * matching aproximado: usa /exercises/search?name= y, si no hay nada,
     * cae a búsqueda por músculo/equipo derivados del término.
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $term = $this->translateExercise($query);

        // 1) Caché local: si ya lo sincronizamos, no llamamos a WorkoutX.
        $local = Exercise::where('provider', $this->provider)
            ->where(fn ($q) => $q
                ->where('name', 'like', "%$term%")
                ->orWhere('name', 'like', "%$query%"))
            ->limit(15)->get();
        if ($local->isNotEmpty()) {
            return $local->map->toReference()->all();
        }

        return $this->guard(
            cacheKey: 'wx:search:' . Str::slug($term),
            external: function () use ($term, $query) {
                // Match por nombre. /exercises/name/{name} es compatible con el
                // plan Free (a diferencia de /exercises/search, que es de pago).
                $res = $this->fetch('/exercises/name/' . rawurlencode($term), ['limit' => 12]);
                if (! empty($res)) {
                    return $res;
                }
                // Fallback: primeros resultados compatibles por parte del cuerpo.
                $muscle = $this->translateMuscle($query);
                if ($muscle !== '') {
                    return $this->fetch('/exercises/bodyPart/' . rawurlencode($muscle), ['limit' => 8]);
                }
                return [];
            },
            fallback: fn () => [],
        );
    }

    /** Ejercicios por músculo / parte del cuerpo. */
    public function byMuscle(string $muscle): array
    {
        $en = $this->translateMuscle(trim($muscle));
        if ($en === '') {
            return [];
        }

        $local = Exercise::where('provider', $this->provider)
            ->where(fn ($q) => $q
                ->where('body_part', 'like', "%$en%")
                ->orWhere('target', 'like', "%$en%"))
            ->limit(30)->get();
        if ($local->isNotEmpty()) {
            return $local->map->toReference()->all();
        }

        return $this->guard(
            cacheKey: 'wx:muscle:' . Str::slug($en),
            external: fn () => $this->fetch('/exercises/bodyPart/' . rawurlencode($en), ['limit' => 30]),
            fallback: fn () => [],
        );
    }

    /** Precarga el catálogo base de Iron Body para tener GIFs persistidos. */
    public function sync(): int
    {
        $count = 0;
        foreach (array_values(self::ES_EN) as $name) {
            try {
                $count += count($this->guard(
                    cacheKey: 'wx:sync:' . Str::slug($name),
                    external: fn () => $this->fetch('/exercises/name/' . rawurlencode($name), ['limit' => 3]),
                    fallback: fn () => [],
                ));
            } catch (Throwable) {
                // continuar; el guard ya logueó sanitizado
            }
        }
        return $count;
    }

    /**
     * Descarga el binario del GIF desde WorkoutX usando la key del servidor.
     * Lo invoca el proxy del backend (`/api/exercises/gif/{filename}`) para
     * que la key NUNCA viaje al cliente.
     */
    public function gifResponse(string $filename): ?Response
    {
        if (! $this->hasApiKey()) {
            return null;
        }
        // Solo nombres tipo `0025.gif` (sin path traversal).
        if (! preg_match('/^[A-Za-z0-9_-]+\.gif$/', $filename)) {
            return null;
        }
        try {
            $resp = Http::withHeaders([$this->authHeader => $this->apiKey])
                ->timeout(20)
                ->get("{$this->baseUrl}{$this->prefix}/gifs/{$filename}");
            return $resp->successful() ? $resp : null;
        } catch (Throwable $e) {
            Log::warning('WorkoutX gif no disponible', ['reason' => $this->sanitize($e->getMessage())]);
            return null;
        }
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function translateExercise(string $value): string
    {
        $k = Str::lower(trim($value));
        return self::ES_EN[$k] ?? $value;
    }

    private function translateMuscle(string $value): string
    {
        $k = Str::lower(trim($value));
        return self::MUSCLE_EN[$k] ?? $value;
    }

    /**
     * @param  callable():array  $external
     * @param  callable():array  $fallback
     */
    private function guard(string $cacheKey, callable $external, callable $fallback): array
    {
        if (! $this->hasApiKey()) {
            return $fallback();
        }
        try {
            return Cache::remember($cacheKey, $this->cacheTtl, function () use ($external) {
                $items = $external();
                $this->persist($items);
                return $items;
            });
        } catch (Throwable $e) {
            Log::warning('WorkoutX no disponible, usando fallback local', [
                'reason' => $this->sanitize($e->getMessage()),
            ]);
            return $fallback();
        }
    }

    /** Llama a WorkoutX y devuelve items normalizados. */
    private function fetch(string $path, array $query = []): array
    {
        $resp = Http::withHeaders([
                $this->authHeader => $this->apiKey,
                'Accept'          => 'application/json',
            ])
            ->timeout(15)
            ->retry(1, 250)
            ->get("{$this->baseUrl}{$this->prefix}{$path}", $query);

        if (! $resp->successful()) {
            // Nunca incluimos la API key ni la URL completa en el mensaje.
            throw new \RuntimeException("WorkoutX HTTP {$resp->status()} en {$path}");
        }

        $json = $resp->json();
        $rows = $json['data'] ?? $json; // {data:[...]} o [...]
        if (! is_array($rows)) {
            return [];
        }
        if (isset($rows['name']) || isset($rows['id'])) {
            $rows = [$rows]; // objeto único
        }

        return array_values(array_filter(array_map(
            fn ($r) => is_array($r) ? $this->normalize($r) : null,
            $rows,
        )));
    }

    /** Mapea la forma externa de WorkoutX a la forma interna estable. */
    private function normalize(array $r): ?array
    {
        $name = $r['name'] ?? $r['exerciseName'] ?? null;
        if (! $name) {
            return null;
        }

        $instructions = $r['instructions'] ?? $r['steps'] ?? [];
        if (is_string($instructions)) {
            $instructions = array_values(array_filter(array_map(
                'trim', preg_split('/\r\n|\r|\n/', $instructions),
            )));
        }

        $externalId = (string) ($r['id'] ?? $r['exerciseId'] ?? $r['_id'] ?? Str::slug($name));

        return [
            'external_id'  => $externalId,
            'name'         => trim($name),
            'body_part'    => $r['bodyPart'] ?? $r['body_part'] ?? $r['category'] ?? null,
            'target'       => $r['target'] ?? $r['primaryMuscle'] ?? null,
            'equipment'    => $r['equipment'] ?? null,
            // Guardamos SOLO el nombre de archivo del GIF (p. ej. `0025.gif`).
            // El backend lo sirve vía proxy; nunca exponemos la URL+key.
            'gif_url'      => $this->gifFilename($r['gifUrl'] ?? $r['gif_url'] ?? $r['gif'] ?? null, $externalId),
            'instructions' => is_array($instructions) ? array_values($instructions) : [],
            'provider'     => $this->provider,
        ];
    }

    /** Extrae `0025.gif` de `/v1/gifs/0025.gif` (o usa el id como respaldo). */
    private function gifFilename(?string $gifUrl, string $externalId): ?string
    {
        if ($gifUrl) {
            $base = basename(parse_url($gifUrl, PHP_URL_PATH) ?: $gifUrl);
            if (preg_match('/^[A-Za-z0-9_-]+\.gif$/', $base)) {
                return $base;
            }
        }
        // WorkoutX nombra los GIFs por id (`{id}.gif`).
        if (preg_match('/^[A-Za-z0-9_-]+$/', $externalId)) {
            return "{$externalId}.gif";
        }
        return null;
    }

    private function persist(array $items): void
    {
        foreach ($items as $it) {
            try {
                Exercise::updateOrCreate(
                    ['provider' => $this->provider, 'external_id' => $it['external_id']],
                    $it,
                );
            } catch (Throwable $e) {
                Log::warning('WorkoutX persist falló', ['reason' => $this->sanitize($e->getMessage())]);
            }
        }
    }

    /** Elimina credenciales/URL de los mensajes de log. */
    private function sanitize(string $msg): string
    {
        if (filled($this->apiKey)) {
            $msg = str_ireplace((string) $this->apiKey, '[REDACTED]', $msg);
        }
        $msg = str_ireplace($this->baseUrl, '[WORKOUTX]', $msg);
        return Str::limit($msg, 300);
    }
}
