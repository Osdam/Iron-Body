<?php

namespace App\Services;

use App\Models\Exercise;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proveedor de referencias visuales: **ExerciseDB** (AscendAPI v1).
 *
 * Catálogo de ~1.500 ejercicios con GIF animado (mini-video en bucle). Usa el
 * endpoint OSS abierto (https://oss.exercisedb.dev/api/v1/exercises), sin API
 * key. El `gifUrl` es una URL pública del CDN (static.exercisedb.dev), así que
 * se guarda directa en la tabla `exercises` y la app la carga sin proxy.
 *
 * Reemplaza a FitGif (servicio descontinuado). Arquitectura igual:
 *   Flutter → Laravel (/api/app/exercises lee la tabla) → catálogo sincronizado.
 */
class ExerciseDbService
{
    private string $baseUrl;
    private string $host;
    private string $apiKey;
    private string $source;

    /** bodyPart (EN) → grupo muscular en ES para mostrar/filtrar en la app. */
    private const BODYPART_ES = [
        'back'        => 'Espalda',
        'cardio'      => 'Cardio',
        'chest'       => 'Pecho',
        'lower arms'  => 'Antebrazos',
        'lower legs'  => 'Pantorrillas',
        'neck'        => 'Cuello',
        'shoulders'   => 'Hombros',
        'upper arms'  => 'Brazos',
        'upper legs'  => 'Piernas',
        'waist'       => 'Core',
    ];

    /** Equipo (EN) → sufijo en ES (con preposición). '' = no se añade sufijo. */
    private const EQUIPMENT_ES = [
        'barbell'           => 'con barra',
        'ez barbell'        => 'con barra Z',
        'olympic barbell'   => 'con barra olímpica',
        'trap bar'          => 'con barra hexagonal',
        'dumbbell'          => 'con mancuerna',
        'kettlebell'        => 'con pesa rusa',
        'cable'             => 'en polea',
        'machine'           => 'en máquina',
        'smith machine'     => 'en máquina Smith',
        'leverage machine'  => 'en máquina',
        'lever'             => 'en máquina',
        'sled machine'      => 'en máquina',
        'sled'              => 'en máquina',
        'band'              => 'con banda elástica',
        'resistance band'   => 'con banda elástica',
        'elastic band'      => 'con banda elástica',
        'medicine ball'     => 'con balón medicinal',
        'stability ball'    => 'con pelota de estabilidad',
        'bosu ball'         => 'con bosu',
        'exercise ball'     => 'con pelota',
        'roller'            => 'con rodillo',
        'rope'              => 'con cuerda',
        'wheel roller'      => 'con rueda abdominal',
        'weighted'          => 'con peso',
        'assisted'          => '(asistido)',
        'body weight'       => '',
        'tire'              => 'con llanta',
        'hammer'            => '',
    ];

    /** Frases de movimiento (EN → ES). Orden: de más larga a más corta. */
    private const PHRASES = [
        'clean and jerk'        => 'cargada y envión',
        'romanian deadlift'     => 'peso muerto rumano',
        'stiff leg deadlift'    => 'peso muerto piernas rígidas',
        'sumo deadlift'         => 'peso muerto sumo',
        'bent over row'         => 'remo inclinado',
        'upright row'           => 'remo al mentón',
        'seated row'            => 'remo sentado',
        'lat pulldown'          => 'jalón al pecho',
        'lateral raise'         => 'elevación lateral',
        'front raise'           => 'elevación frontal',
        'leg raise'             => 'elevación de piernas',
        'calf raise'            => 'elevación de pantorrilla',
        'knee raise'            => 'elevación de rodillas',
        'shoulder press'        => 'press de hombro',
        'military press'        => 'press militar',
        'overhead press'        => 'press sobre la cabeza',
        'chest press'           => 'press de pecho',
        'bench press'           => 'press de banca',
        'leg press'             => 'prensa de pierna',
        'push press'            => 'push press',
        'leg curl'              => 'curl femoral',
        'leg extension'         => 'extensión de pierna',
        'biceps curl'           => 'curl de bíceps',
        'bicep curl'            => 'curl de bíceps',
        'hammer curl'           => 'curl martillo',
        'preacher curl'         => 'curl predicador',
        'concentration curl'    => 'curl concentrado',
        'wrist curl'            => 'curl de muñeca',
        'triceps extension'     => 'extensión de tríceps',
        'tricep extension'      => 'extensión de tríceps',
        'triceps pushdown'      => 'extensión en polea',
        'tricep pushdown'       => 'extensión en polea',
        'skull crusher'         => 'rompecráneos',
        'hip thrust'            => 'empuje de cadera',
        'glute bridge'          => 'puente de glúteo',
        'good morning'          => 'buenos días',
        'face pull'             => 'jalón a la cara',
        'pull through'          => 'jalón entre piernas',
        'russian twist'         => 'giro ruso',
        'mountain climber'      => 'escalador',
        'jumping jack'          => 'salto de tijera',
        'front squat'           => 'sentadilla frontal',
        'hack squat'            => 'sentadilla hack',
        'goblet squat'          => 'sentadilla goblet',
        'split squat'           => 'sentadilla búlgara',
        'step up'               => 'subida al cajón',
        'box jump'              => 'salto al cajón',
        'pull up'               => 'dominada',
        'chin up'               => 'dominada supina',
        'push up'               => 'flexión',
        'sit up'                => 'abdominal completo',
        'back extension'        => 'hiperextensión',
        'deadlift'              => 'peso muerto',
        'pulldown'              => 'jalón',
        'pushdown'              => 'extensión en polea',
        'squat'                 => 'sentadilla',
        'lunge'                 => 'zancada',
        'shrug'                 => 'encogimiento',
        'pullover'              => 'pull over',
        'kickback'              => 'patada',
        'crunch'                => 'abdominal',
        'plank'                 => 'plancha',
        'burpee'                => 'burpee',
        'thruster'             => 'thruster',
        'snatch'                => 'arranque',
        'clean'                 => 'cargada',
        'swing'                 => 'swing',
        'twist'                 => 'giro',
        'dips'                  => 'fondos',
        'dip'                   => 'fondos',
        'flyes'                 => 'aperturas',
        'flye'                  => 'aperturas',
        'fly'                   => 'aperturas',
        'row'                   => 'remo',
        'raise'                 => 'elevación',
        'extension'             => 'extensión',
        'curl'                  => 'curl',
        'press'                 => 'press',
    ];

    /** Modificadores / anatomía (EN → ES) por palabra. */
    private const WORDS = [
        'incline' => 'inclinado', 'decline' => 'declinado', 'flat' => 'plano',
        'seated' => 'sentado', 'standing' => 'de pie', 'lying' => 'acostado',
        'kneeling' => 'arrodillado', 'bent' => 'inclinado', 'reverse' => 'inverso',
        'alternating' => 'alterno', 'alternate' => 'alterno', 'hanging' => 'colgado',
        'wide' => 'abierto', 'close' => 'cerrado', 'grip' => 'agarre',
        'overhead' => 'sobre la cabeza', 'front' => 'frontal', 'rear' => 'posterior',
        'side' => 'lateral', 'cross' => 'cruzado', 'high' => 'alto', 'low' => 'bajo',
        'single' => 'a una', 'arm' => 'brazo', 'leg' => 'pierna',
        'chest' => 'pecho', 'shoulder' => 'hombro', 'shoulders' => 'hombros',
        'bicep' => 'bíceps', 'biceps' => 'bíceps', 'tricep' => 'tríceps', 'triceps' => 'tríceps',
        'glute' => 'glúteo', 'glutes' => 'glúteos', 'hamstring' => 'femoral', 'hamstrings' => 'femoral',
        'quad' => 'cuádriceps', 'quads' => 'cuádriceps', 'calf' => 'pantorrilla', 'calves' => 'pantorrillas',
        'oblique' => 'oblicuo', 'obliques' => 'oblicuos', 'forearm' => 'antebrazo',
        'trap' => 'trapecio', 'traps' => 'trapecio', 'lat' => 'dorsal', 'lats' => 'dorsales',
        'neck' => 'cuello', 'wrist' => 'muñeca', 'hip' => 'cadera', 'hips' => 'cadera',
        'and' => 'y', 'with' => 'con', 'to' => 'a', 'the' => '', 'of' => 'de', 'over' => '',
    ];

    /** Modificadores que, si van al inicio, se mueven al final para mejor orden. */
    private const LEAD_MODS = [
        'incline', 'decline', 'flat', 'seated', 'standing', 'lying',
        'kneeling', 'reverse', 'bent', 'hanging', 'alternating', 'alternate',
    ];

    /** Palabras de equipo a quitar del nombre (el equipo va como sufijo en ES). */
    private const STRIP_EQUIP = [
        'barbell', 'dumbbell', 'dumbell', 'cable', 'machine', 'smith',
        'kettlebell', 'lever', 'leverage', 'sled', 'ez', 'olympic',
        'weighted', 'assisted', 'bosu', 'roller',
    ];

    public function __construct()
    {
        $cfg = config('services.exercisedb');
        // Endpoint OSS abierto (sin key). El host RapidAPI es opcional.
        $this->baseUrl = rtrim($cfg['base_url'] ?? 'https://oss.exercisedb.dev', '/');
        $this->host    = $cfg['host'] ?? '';
        $this->apiKey  = (string) ($cfg['api_key'] ?? '');
        $this->source  = $cfg['source_label'] ?? 'ExerciseDB';
    }

    // ── API pública (lee de la BD ya sincronizada) ───────────────────────────

    public function all(int $limit = 30, int $offset = 0): array
    {
        return Exercise::where('provider', 'exercisedb')
            ->orderBy('name')
            ->limit(max(1, min($limit, 100)))
            ->offset(max(0, $offset))
            ->get()->map->toReference()->all();
    }

    public function find(string $id): ?array
    {
        $ex = Exercise::where('provider', 'exercisedb')
            ->where('external_id', $id)
            ->first();

        return $ex?->toReference();
    }

    public function search(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        return Exercise::where('provider', 'exercisedb')
            ->where(function ($w) use ($q) {
                $w->where('name', 'like', "%$q%")
                  ->orWhere('local_name', 'like', "%$q%")
                  ->orWhere('target', 'like', "%$q%")
                  ->orWhere('muscle_group', 'like', "%$q%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get()->map->toReference()->all();
    }

    public function byMuscle(string $muscle): array
    {
        $muscle = trim($muscle);

        return Exercise::where('provider', 'exercisedb')
            ->where(function ($w) use ($muscle) {
                $w->where('muscle_group', 'like', "%$muscle%")
                  ->orWhere('body_part', 'like', "%$muscle%")
                  ->orWhere('target', 'like', "%$muscle%");
            })
            ->orderBy('name')
            ->limit(50)
            ->get()->map->toReference()->all();
    }

    // ── Sincronización del catálogo (RapidAPI → tabla `exercises`) ────────────

    /**
     * Descarga el catálogo completo de ExerciseDB y lo persiste en `exercises`.
     * Pagina de a 100 para no depender del límite por defecto de la API.
     *
     * @param  callable|null  $progress  recibe líneas de avance (para el comando).
     * @return array{ok:int,fail:int}
     */
    public function sync(?callable $progress = null): array
    {
        $ok = 0;
        $fail = 0;
        $cursor = '';
        $safety = 0;       // tope de páginas (1500/25 = 60 → 80 holgado)
        $seen = [];        // cursores ya vistos → corta bucles

        do {
            $page = $this->fetchPage($cursor);
            if ($page === null) {
                $progress && $progress("error al consultar ExerciseDB (cursor '{$cursor}')");
                break;
            }

            if (empty($page['rows'])) {
                break;
            }

            foreach ($page['rows'] as $row) {
                $ref = $this->normalize($row);
                if ($ref === null) {
                    $fail++;
                    continue;
                }
                if ($this->persist($ref)) {
                    $ok++;
                    $progress && $progress(sprintf('%-32s -> OK', Str::limit($ref['name'], 30)));
                } else {
                    $fail++;
                }
            }

            // Paginación por cursor: la API expone meta.nextCursor + hasNextPage.
            $next = $page['next'];
            if (! $page['hasNext'] || $next === '' || isset($seen[$next])) {
                break;
            }
            $seen[$next] = true;
            $cursor = $next;
            $safety++;
        } while ($safety < 80);

        return ['ok' => $ok, 'fail' => $fail];
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /**
     * Trae una página del catálogo (máx. 25/página, paginación por cursor `after`).
     *
     * @return array{rows:array,next:string,hasNext:bool}|null  null si falló.
     */
    private function fetchPage(string $cursor): ?array
    {
        try {
            $headers = ['Accept' => 'application/json'];
            // El endpoint OSS (oss.exercisedb.dev) es abierto. Las cabeceras de
            // RapidAPI solo se envían si hay key + host configurados.
            if ($this->apiKey !== '' && $this->host !== '') {
                $headers['X-RapidAPI-Key']  = $this->apiKey;
                $headers['X-RapidAPI-Host'] = $this->host;
            }

            $query = ['limit' => 25];
            if ($cursor !== '') {
                $query['after'] = $cursor;
            }

            $resp = Http::withHeaders($headers)->timeout(30)->retry(1, 500)
              ->get("{$this->baseUrl}/api/v1/exercises", $query);

            Log::info('ExerciseDB request', [
                'provider'    => 'exercisedb',
                'cursor'      => $cursor,
                'http_status' => $resp->status(),
            ]);

            if (! $resp->successful()) {
                return null;
            }

            $json = $resp->json();
            if (! is_array($json)) {
                return null;
            }

            $rows = is_array($json['data'] ?? null) ? $json['data'] : [];
            $meta = is_array($json['meta'] ?? null) ? $json['meta'] : [];

            return [
                'rows'    => $rows,
                'next'    => (string) ($meta['nextCursor'] ?? ''),
                'hasNext' => (bool) ($meta['hasNextPage'] ?? false),
            ];
        } catch (Throwable $e) {
            Log::warning('ExerciseDB catálogo no disponible', [
                'provider' => 'exercisedb',
                'reason'   => Str::limit($e->getMessage(), 300),
            ]);
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function normalize(array $r): ?array
    {
        // Formato v1 (AscendAPI): exerciseId, bodyParts[], targetMuscles[],
        // equipments[], secondaryMuscles[], instructions[]. Tolera el formato
        // antiguo (id/bodyPart/target/equipment) por si cambia el host.
        $id   = (string) ($r['exerciseId'] ?? $r['id'] ?? '');
        $name = trim((string) ($r['name'] ?? ''));
        if ($id === '' || $name === '') {
            return null;
        }

        $bodyPart = strtolower(trim((string) ($r['bodyParts'][0] ?? $r['bodyPart'] ?? '')));
        $muscleEs = self::BODYPART_ES[$bodyPart] ?? Str::ucfirst($bodyPart);
        $target    = trim((string) ($r['targetMuscles'][0] ?? $r['target'] ?? ''));
        $equipment = trim((string) ($r['equipments'][0] ?? $r['equipment'] ?? ''));
        $secondary = array_values(array_filter((array) ($r['secondaryMuscles'] ?? [])));
        $targets   = array_values(array_filter((array) ($r['targetMuscles'] ?? [])));

        // Limpia el prefijo "Step:N " de cada instrucción.
        $instructions = array_values(array_filter(array_map(
            fn ($s) => trim(preg_replace('/^Step\s*:?\s*\d+\s*/i', '', (string) $s)),
            (array) ($r['instructions'] ?? [])
        )));

        return [
            'external_id'       => $id,
            // Nombre mostrado en ES; el original EN se guarda para búsqueda.
            'name'              => $this->translateName($name, $equipment),
            'local_name'        => Str::ucfirst($name),
            'body_part'         => $bodyPart !== '' ? Str::ucfirst($bodyPart) : null,
            'muscle_group'      => $muscleEs ?: null,
            'target'            => $target !== '' ? $target : null,
            'equipment'         => $equipment !== '' ? $equipment : null,
            'difficulty'        => 'Principiante',
            'secondary_muscles' => $secondary,
            'muscles_worked'    => array_values(array_filter(array_merge($targets, $secondary))),
            'steps'             => $instructions,
            'instructions'      => $instructions,
            'gif_url'           => $this->httpUrl($r['gifUrl'] ?? null),
            'media_type'        => 'gif',
            'provider'          => 'exercisedb',
            'source'            => $this->source,
            'last_synced_at'    => now(),
        ];
    }

    private function httpUrl(?string $v): ?string
    {
        return (is_string($v) && preg_match('#^https?://#i', $v)) ? $v : null;
    }

    /**
     * Traduce el nombre EN del ejercicio a ES con diccionarios:
     *  1) quita el equipo del inicio y lo añade como sufijo ("con barra", "en polea");
     *  2) mueve modificadores líderes (incline, seated…) al final para mejor orden;
     *  3) traduce frases de movimiento y luego palabras sueltas.
     * Lo que no esté en el diccionario queda en inglés (degradación limpia).
     */
    private function translateName(string $en, string $equipEn): string
    {
        $s = str_replace('-', ' ', mb_strtolower(trim($en)));
        $equip = mb_strtolower(trim($equipEn));

        $words = preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // 1) Quita palabras de equipo en cualquier posición (el equipo se añade
        //    como sufijo) y el conector 'over'.
        $words = array_values(array_filter(
            $words,
            fn ($w) => $w !== 'over' && ! in_array($w, self::STRIP_EQUIP, true),
        ));

        // 2) Reordena modificadores líderes al final ("incline bench press"
        //    → "bench press incline" → "press de banca inclinado").
        $lead = [];
        while (! empty($words) && in_array($words[0], self::LEAD_MODS, true)) {
            $lead[] = array_shift($words);
        }
        $s = trim(implode(' ', array_merge($words, $lead)));

        // 3) Traduce frases (largas→cortas) y luego palabras sueltas.
        $s = $this->replaceTokens($s, self::PHRASES);
        $s = $this->replaceTokens($s, self::WORDS);
        $s = trim(preg_replace('/\s{2,}/', ' ', $s));

        $suffix = self::EQUIPMENT_ES[$equip] ?? '';
        if ($suffix !== '') {
            $s = trim($s . ' ' . $suffix);
        }

        return $s !== '' ? Str::ucfirst($s) : Str::ucfirst($en);
    }

    /** Reemplaza claves del diccionario respetando límites de palabra. */
    private function replaceTokens(string $s, array $dict): string
    {
        foreach ($dict as $en => $es) {
            $s = preg_replace('/\b' . preg_quote($en, '/') . '\b/u', $es, $s);
        }

        return $s;
    }

    private function persist(array $ref): bool
    {
        try {
            Exercise::updateOrCreate(
                ['provider' => 'exercisedb', 'external_id' => $ref['external_id']],
                $ref,
            );
            return true;
        } catch (Throwable $e) {
            Log::warning('ExerciseDB persist falló', [
                'provider' => 'exercisedb',
                'reason'   => Str::limit($e->getMessage(), 300),
            ]);
            return false;
        }
    }
}
