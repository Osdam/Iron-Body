<?php

namespace App\Services;

use App\Models\Exercise;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * FitGif — proveedor PRINCIPAL de referencia visual (GIF anatómico).
 *
 * Restricciones REALES de FitGif (verificadas):
 *  - Límite: 3 requests/min (HTTP 429). Por eso fallaban tantos GIFs: al
 *    abrir Entrenar y pasar cards se disparaban muchas llamadas.
 *  - La signed URL del GIF caduca en 60 s → no se puede guardar la URL.
 *
 * Estrategia:
 *  - SYNC controlado (artisan fitgif:sync / POST /api/exercises/sync):
 *    por cada ejercicio prueba una LISTA de candidatos (con throttle de
 *    ≥21 s/req), elige el mejor, DESCARGA el binario del GIF al instante y
 *    lo guarda en disco + metadatos en `exercises`.
 *  - RUNTIME (search/find/by-muscle): se sirve SOLO desde la DB/disco.
 *    Cero llamadas a FitGif al abrir/voltear cards → cero 429.
 *  - Sin fallback a WorkoutX/Free Exercise DB (lo decide la capa provider).
 *
 * La API key vive solo aquí; Flutter nunca llama a FitGif ni la ve.
 */
class FitGifExerciseService
{
    private string $baseUrl;
    private ?string $apiKey;
    private string $source;
    private float $vSpeed;
    private int $vFps;
    private int $vWidth;
    private int $vCrf;

    private const GIF_DIR = 'fitgif';
    private const VIDEO_DIR = 'fitgif_video';
    private const MIN_INTERVAL = 21;   // s entre llamadas (límite 3/min)
    private const MAX_ATTEMPTS = 16;   // candidatos por ejercicio (la mayoría
                                       // matchea en el 1.º; solo casos difíciles
                                       // como Plancha agotan la lista)

    /**
     * Diccionario robusto: nombre local ES → bodyPart preferido + lista
     * ordenada de candidatos EN que FitGif suele resolver.
     */
    public const DICTIONARY = [
        'press de banca'        => ['chest',      ['barbell bench press', 'bench press', 'dumbbell bench press', 'chest press']],
        'press banca'           => ['chest',      ['barbell bench press', 'bench press', 'chest press']],
        'sentadilla'            => ['upper legs', ['barbell full squat', 'barbell squat', 'squat', 'bodyweight squat']],
        'peso muerto'           => ['upper legs', ['barbell deadlift', 'deadlift', 'romanian deadlift', 'dumbbell deadlift']],
        'dominadas'             => ['back',       ['pull-up', 'pull up', 'assisted pull-up', 'wide grip pull-up']],
        'dominada'              => ['back',       ['pull-up', 'pull up', 'assisted pull-up']],
        'press militar'         => ['shoulders',  ['barbell shoulder press', 'shoulder press', 'overhead press', 'dumbbell shoulder press', 'military press']],
        'curl con mancuernas'   => ['upper arms', ['dumbbell biceps curl', 'dumbbell curl', 'dumbbell alternate biceps curl', 'hammer curl']],
        'curl biceps'           => ['upper arms', ['dumbbell biceps curl', 'barbell curl', 'biceps curl', 'hammer curl']],
        'curl bíceps'           => ['upper arms', ['dumbbell biceps curl', 'barbell curl', 'biceps curl', 'hammer curl']],
        'jalon al pecho'        => ['back',       ['lat pulldown', 'cable pulldown', 'pulldown', 'wide grip lat pulldown']],
        'jalón al pecho'        => ['back',       ['lat pulldown', 'cable pulldown', 'pulldown']],
        'fondos'                => ['upper arms', ['triceps dip', 'chest dip', 'dips', 'bench dip']],
        'extension de triceps'  => ['upper arms', ['triceps extension', 'cable triceps pushdown', 'overhead triceps extension']],
        'extensión de tríceps'  => ['upper arms', ['triceps extension', 'cable triceps pushdown', 'overhead triceps extension']],
        'remo'                  => ['back',       ['barbell row', 'seated row', 'cable row', 'bent over row']],
        'zancadas'              => ['upper legs', ['lunge', 'barbell lunge', 'dumbbell lunge', 'walking lunge']],

        // ── Ampliación de cobertura (reduce placeholders) ────────────────────
        // Plancha: lista amplia EN orden del usuario. bodyPart=null para no
        // filtrar de más; un guard de relevancia (isCoreRelevant) descarta
        // resultados que NO sean core/abdomen (p. ej. FitGif devuelve un
        // "barbell front squat" para "front plank" → se rechaza).
        // 'push up plank' va 1.º: el probe real confirmó que FitGif resuelve
        // ese término a "3/4 sit-up" (bodyPart=waist, target=abs) — un
        // ejercicio de abdomen/core válido. Resto como fallback en orden.
        'plancha'               => [null, [
            'push up plank', 'push-up plank', 'plank', 'front plank',
            'forearm plank', 'elbow plank', 'high plank', 'bodyweight plank',
            'abdominal plank', 'core plank', 'hover',
            'mountain climber', 'mountain climbers', 'bear plank', 'bear crawl',
        ]],
        // Burpees: sin burpee real → fallback explosivo "jump squat"
        // (FitGif: "barbell jump squat"), el patrón más cercano.
        'burpees'               => [null,         ['burpee', 'burpees', 'bodyweight burpee', 'jump squat']],
        'burpee'                => [null,         ['burpee', 'burpees', 'bodyweight burpee', 'jump squat']],
        'mountain climbers'     => ['cardio',     ['mountain climber', 'mountain climbers']],
        'escaladores'           => ['cardio',     ['mountain climber', 'mountain climbers']],
        'jumping jacks'         => ['cardio',     ['jumping jack', 'jumping jacks']],
        'saltos de tijera'      => ['cardio',     ['jumping jack', 'jumping jacks']],
        'flexiones'             => ['chest',      ['push up', 'push-up', 'pushup', 'close grip push up']],
        'flexiones de pecho'    => ['chest',      ['push up', 'push-up', 'pushup', 'close grip push up']],
        'push ups'              => ['chest',      ['push up', 'push-up', 'pushup', 'close grip push up']],
        'abdominales'           => ['waist',      ['crunch', 'sit up', 'sit-up', 'abdominal crunch']],
        'crunch'                => ['waist',      ['crunch', 'abdominal crunch', 'sit up', 'sit-up']],
        'hip thrust'            => ['upper legs', ['hip thrust', 'barbell hip thrust', 'glute bridge']],
        'empuje de cadera'      => ['upper legs', ['hip thrust', 'barbell hip thrust', 'glute bridge']],
        'puente de gluteos'     => ['upper legs', ['glute bridge', 'hip thrust', 'barbell hip thrust']],
        'prensa de pierna'      => ['upper legs', ['leg press', 'sled leg press']],
        'leg press'             => ['upper legs', ['leg press', 'sled leg press']],
        'curl femoral'          => ['upper legs', ['leg curl', 'lying leg curl', 'seated leg curl']],
        'leg curl'              => ['upper legs', ['leg curl', 'lying leg curl', 'seated leg curl']],
        'extension de pierna'   => ['upper legs', ['leg extension', 'seated leg extension']],
        'extensión de pierna'   => ['upper legs', ['leg extension', 'seated leg extension']],
        'leg extension'         => ['upper legs', ['leg extension', 'seated leg extension']],
        'elevacion de pantorrilla' => ['lower legs', ['calf raise', 'standing calf raise', 'seated calf raise']],
        'elevación de pantorrilla' => ['lower legs', ['calf raise', 'standing calf raise', 'seated calf raise']],
        'gemelos'               => ['lower legs', ['calf raise', 'standing calf raise', 'seated calf raise']],
        'calf raise'            => ['lower legs', ['calf raise', 'standing calf raise', 'seated calf raise']],
        'elevaciones laterales' => ['shoulders',  ['lateral raise', 'dumbbell lateral raise', 'side lateral raise']],
        'elevacion lateral'     => ['shoulders',  ['lateral raise', 'dumbbell lateral raise', 'side lateral raise']],
        'lateral raise'         => ['shoulders',  ['lateral raise', 'dumbbell lateral raise', 'side lateral raise']],
        'vuelos posteriores'    => ['shoulders',  ['rear delt fly', 'reverse fly', 'dumbbell reverse fly']],
        'pajaros'               => ['shoulders',  ['rear delt fly', 'reverse fly', 'dumbbell reverse fly']],
        'rear delt'             => ['shoulders',  ['rear delt fly', 'reverse fly', 'dumbbell reverse fly']],
        'aperturas'             => ['chest',      ['chest fly', 'dumbbell fly', 'cable fly']],
        'aperturas con mancuernas' => ['chest',   ['dumbbell fly', 'chest fly', 'cable fly']],
        'chest fly'             => ['chest',      ['chest fly', 'dumbbell fly', 'cable fly']],
        'press frances'         => ['upper arms', ['french press', 'skull crusher', 'lying triceps extension']],
        'press francés'         => ['upper arms', ['french press', 'skull crusher', 'lying triceps extension']],
        'rompecraneos'          => ['upper arms', ['skull crusher', 'french press', 'lying triceps extension']],
        'pullover'              => ['back',       ['pullover', 'dumbbell pullover']],
        'pull over'             => ['back',       ['pullover', 'dumbbell pullover']],
        'remo con mancuerna'    => ['back',       ['dumbbell row', 'one arm dumbbell row']],
        'remo mancuerna'        => ['back',       ['dumbbell row', 'one arm dumbbell row']],
        'remo sentado'          => ['back',       ['seated row', 'cable row']],
        'press inclinado'       => ['chest',      ['incline bench press', 'incline dumbbell press']],
        'press declinado'       => ['chest',      ['decline bench press']],
    ];

    /** ES→EN de músculos → bodyPart válido de FitGif (para by-muscle). */
    private const MUSCLE_EN = [
        'pecho' => 'chest', 'pectoral' => 'chest', 'pectorales' => 'chest',
        'espalda' => 'back', 'dorsal' => 'back', 'dorsales' => 'back',
        'biceps' => 'upper arms', 'bíceps' => 'upper arms', 'brazos' => 'upper arms',
        'triceps' => 'upper arms', 'tríceps' => 'upper arms',
        'hombro' => 'shoulders', 'hombros' => 'shoulders',
        'pierna' => 'upper legs', 'piernas' => 'upper legs',
        'cuadriceps' => 'upper legs', 'cuádriceps' => 'upper legs',
        'gluteos' => 'upper legs', 'glúteos' => 'upper legs',
        'isquiotibiales' => 'upper legs', 'femoral' => 'upper legs',
        'pantorrilla' => 'lower legs', 'gemelos' => 'lower legs',
        'core' => 'waist', 'abdomen' => 'waist', 'abdominales' => 'waist',
        'cardio' => 'cardio',
    ];

    private const VALID_BODYPARTS = [
        'back', 'cardio', 'chest', 'lower arms', 'lower legs',
        'neck', 'shoulders', 'upper arms', 'upper legs', 'waist',
    ];

    // ── Traducciones ES (todo lo visible al usuario en español) ──────────────

    /** Nombre de display ES por nombre local (clave = local_name lowercase). */
    private const NAME_ES = [
        'press de banca' => 'Press de Banca', 'press banca' => 'Press de Banca',
        'sentadilla' => 'Sentadilla', 'peso muerto' => 'Peso Muerto',
        'dominadas' => 'Dominadas', 'dominada' => 'Dominadas',
        'press militar' => 'Press Militar',
        'curl con mancuernas' => 'Curl con Mancuernas',
        'curl biceps' => 'Curl de Bíceps', 'curl bíceps' => 'Curl de Bíceps',
        'jalon al pecho' => 'Jalón al Pecho', 'jalón al pecho' => 'Jalón al Pecho',
        'fondos' => 'Fondos', 'remo' => 'Remo', 'remo con mancuerna' => 'Remo con Mancuerna',
        'remo mancuerna' => 'Remo con Mancuerna', 'remo sentado' => 'Remo Sentado',
        'abdominales' => 'Abdominales', 'crunch' => 'Abdominales',
        'zancadas' => 'Zancadas', 'plancha' => 'Plancha',
        'burpees' => 'Burpees', 'burpee' => 'Burpees',
        'mountain climbers' => 'Mountain Climbers', 'escaladores' => 'Mountain Climbers',
        'jumping jacks' => 'Jumping Jacks', 'saltos de tijera' => 'Jumping Jacks',
        'flexiones' => 'Flexiones', 'flexiones de pecho' => 'Flexiones', 'push ups' => 'Flexiones',
        'hip thrust' => 'Hip Thrust', 'empuje de cadera' => 'Hip Thrust',
        'puente de gluteos' => 'Puente de Glúteos',
        'prensa de pierna' => 'Prensa de Pierna', 'leg press' => 'Prensa de Pierna',
        'curl femoral' => 'Curl Femoral', 'leg curl' => 'Curl Femoral',
        'extension de pierna' => 'Extensión de Pierna', 'extensión de pierna' => 'Extensión de Pierna',
        'leg extension' => 'Extensión de Pierna',
        'extension de triceps' => 'Extensión de Tríceps', 'extensión de tríceps' => 'Extensión de Tríceps',
        'elevacion de pantorrilla' => 'Elevación de Pantorrilla',
        'elevación de pantorrilla' => 'Elevación de Pantorrilla',
        'gemelos' => 'Elevación de Pantorrilla', 'calf raise' => 'Elevación de Pantorrilla',
        'elevaciones laterales' => 'Elevaciones Laterales',
        'elevacion lateral' => 'Elevaciones Laterales', 'lateral raise' => 'Elevaciones Laterales',
        'vuelos posteriores' => 'Vuelos Posteriores', 'pajaros' => 'Vuelos Posteriores',
        'rear delt' => 'Vuelos Posteriores',
        'aperturas' => 'Aperturas', 'aperturas con mancuernas' => 'Aperturas con Mancuernas',
        'chest fly' => 'Aperturas',
        'press frances' => 'Press Francés', 'press francés' => 'Press Francés',
        'rompecraneos' => 'Press Francés',
        'pullover' => 'Pullover', 'pull over' => 'Pullover',
        'press inclinado' => 'Press Inclinado', 'press declinado' => 'Press Declinado',
    ];

    /** body_part / target EN → ES. */
    private const PART_ES = [
        'chest' => 'Pecho', 'back' => 'Espalda', 'legs' => 'Piernas',
        'upper legs' => 'Piernas', 'lower legs' => 'Pantorrillas',
        'shoulders' => 'Hombros', 'biceps' => 'Bíceps', 'triceps' => 'Tríceps',
        'upper arms' => 'Brazos', 'lower arms' => 'Antebrazos', 'forearms' => 'Antebrazos',
        'abs' => 'Abdomen', 'waist' => 'Abdomen', 'glutes' => 'Glúteos',
        'calves' => 'Pantorrillas', 'cardio' => 'Cardio', 'neck' => 'Cuello',
        'pectorals' => 'Pecho', 'lats' => 'Espalda', 'quads' => 'Cuádriceps',
        'hamstrings' => 'Isquiotibiales', 'delts' => 'Hombros', 'traps' => 'Trapecio',
        'abductors' => 'Abductores', 'adductors' => 'Aductores',
        'spine' => 'Lumbar', 'serratus anterior' => 'Serrato', 'upper back' => 'Espalda alta',
    ];

    /** equipment EN → ES. */
    private const EQUIP_ES = [
        'barbell' => 'Barra', 'dumbbell' => 'Mancuerna', 'body weight' => 'Peso corporal',
        'cable' => 'Polea', 'machine' => 'Máquina', 'leverage machine' => 'Máquina asistida',
        'kettlebell' => 'Kettlebell', 'resistance band' => 'Banda elástica',
        'band' => 'Banda elástica', 'assisted' => 'Asistido',
        'sled machine' => 'Máquina de prensa', 'smith machine' => 'Máquina Smith',
        'ez barbell' => 'Barra Z', 'olympic barbell' => 'Barra olímpica',
        'medicine ball' => 'Balón medicinal', 'stability ball' => 'Pelota de estabilidad',
        'bosu ball' => 'Bosu', 'rope' => 'Cuerda', 'roller' => 'Rodillo',
        'weighted' => 'Con peso', 'wheel roller' => 'Rueda abdominal',
        'trap bar' => 'Barra trap', 'hammer' => 'Hammer',
    ];

    /** Instrucciones ES manuales (clave = local_name lowercase). */
    private const INSTRUCTIONS_ES = [
        'press de banca' => [
            'Acuéstate en el banco con los pies firmes en el suelo.',
            'Toma la barra con un agarre ligeramente más ancho que los hombros.',
            'Baja la barra de forma controlada hacia el pecho.',
            'Empuja la barra hacia arriba sin perder estabilidad.',
            'Mantén el abdomen activo y controla la respiración.',
        ],
        'sentadilla' => [
            'Coloca los pies al ancho de los hombros.',
            'Mantén la espalda recta y el abdomen activo.',
            'Flexiona caderas y rodillas bajando de forma controlada.',
            'Empuja desde los talones para volver a la posición inicial.',
            'Evita que las rodillas colapsen hacia adentro.',
        ],
        'peso muerto' => [
            'Coloca la barra cerca de las piernas y los pies firmes.',
            'Mantén la espalda neutra y el abdomen activo.',
            'Toma la barra y empuja el suelo con las piernas.',
            'Extiende caderas y rodillas hasta quedar erguido.',
            'Baja la barra controlando el movimiento.',
        ],
        'dominadas' => [
            'Cuélgate de la barra con agarre prono, manos al ancho de hombros.',
            'Activa la espalda y lleva los codos hacia abajo.',
            'Sube hasta que el mentón pase la barra.',
            'Baja de forma controlada hasta extender los brazos.',
            'Evita balancear el cuerpo.',
        ],
        'press militar' => [
            'De pie, sostén la barra a la altura de los hombros.',
            'Mantén el abdomen y los glúteos activos.',
            'Empuja la barra por encima de la cabeza sin arquear la espalda.',
            'Baja de forma controlada hasta los hombros.',
            'Controla la respiración en cada repetición.',
        ],
        'curl con mancuernas' => [
            'De pie, sostén una mancuerna en cada mano.',
            'Mantén los codos pegados al torso.',
            'Flexiona los codos subiendo las mancuernas.',
            'Baja lentamente controlando el peso.',
            'Evita usar impulso con la espalda.',
        ],
        'jalon al pecho' => [
            'Siéntate y sujeta la barra con agarre amplio.',
            'Mantén el pecho arriba y la espalda firme.',
            'Tira de la barra hacia la parte alta del pecho.',
            'Vuelve de forma controlada extendiendo los brazos.',
            'Evita inclinarte demasiado hacia atrás.',
        ],
        'fondos' => [
            'Sujétate en las barras con los brazos extendidos.',
            'Inclina ligeramente el torso hacia adelante.',
            'Baja flexionando los codos de forma controlada.',
            'Empuja hasta extender los brazos.',
            'Mantén el movimiento estable y sin impulso.',
        ],
        'remo' => [
            'Inclina el torso con la espalda neutra.',
            'Sujeta la barra con agarre firme.',
            'Tira de la barra hacia el abdomen.',
            'Aprieta la espalda en la parte alta.',
            'Baja controlando el movimiento.',
        ],
        'abdominales' => [
            'Acuéstate boca arriba con las rodillas flexionadas.',
            'Coloca las manos en el pecho o detrás de la cabeza.',
            'Eleva el torso contrayendo el abdomen.',
            'Baja de forma controlada sin tirar del cuello.',
            'Mantén la respiración constante.',
        ],
        'zancadas' => [
            'De pie, da un paso largo hacia adelante.',
            'Baja flexionando ambas rodillas a 90 grados.',
            'Mantén el torso erguido y el abdomen activo.',
            'Empuja con la pierna delantera para volver.',
            'Alterna las piernas de forma controlada.',
        ],
        'plancha' => [
            'Apoya los antebrazos o las manos en el suelo y extiende las piernas hacia atrás.',
            'Mantén el cuerpo alineado desde la cabeza hasta los talones.',
            'Activa el abdomen y los glúteos para evitar que la cadera caiga.',
            'Respira de forma controlada y mantén la posición.',
            'Evita arquear la espalda o elevar demasiado la cadera.',
        ],
        'burpees' => [
            'De pie, baja a cuclillas y apoya las manos en el suelo.',
            'Lleva los pies atrás a posición de plancha.',
            'Haz una flexión (opcional) y vuelve los pies a las manos.',
            'Salta de forma explosiva extendiendo todo el cuerpo.',
            'Aterriza suave y repite de forma controlada.',
        ],
        'burpee' => [
            'De pie, baja a cuclillas y apoya las manos en el suelo.',
            'Lleva los pies atrás a posición de plancha.',
            'Haz una flexión (opcional) y vuelve los pies a las manos.',
            'Salta de forma explosiva extendiendo todo el cuerpo.',
            'Aterriza suave y repite de forma controlada.',
        ],
    ];

    /**
     * Override de etiqueta para fallbacks visuales: aunque FitGif devuelva
     * otro ejercicio parecido, al usuario se le muestra el nombre/datos ES
     * correctos (el inglés real queda solo en original_*).
     */
    private const OVERRIDE_ES = [
        'burpees' => ['body_part' => 'Cuerpo completo', 'target' => 'Full Body', 'equipment' => 'Peso corporal'],
        'burpee'  => ['body_part' => 'Cuerpo completo', 'target' => 'Full Body', 'equipment' => 'Peso corporal'],
        'plancha' => ['body_part' => 'Abdomen', 'target' => 'Core', 'equipment' => 'Peso corporal'],
    ];

    private const INSTRUCTIONS_ES_FALLBACK = [
        'Realiza el movimiento de forma controlada.',
        'Mantén buena postura durante toda la ejecución.',
        'Controla la respiración y evita movimientos bruscos.',
        'Ajusta la carga según tu nivel.',
    ];

    public function __construct()
    {
        $cfg = config('services.fitgif');
        $this->baseUrl = $cfg['base_url'] ?? 'https://fitgif.vercel.app';
        $this->apiKey  = $cfg['api_key'] ?? null;
        $this->source  = $cfg['source_label'] ?? 'FitGif';
        $this->vSpeed  = (float) ($cfg['video_speed'] ?? 1.5);
        $this->vFps    = (int) ($cfg['video_fps'] ?? 60);
        $this->vWidth  = (int) ($cfg['video_width'] ?? 540);
        $this->vCrf    = (int) ($cfg['video_crf'] ?? 26);
    }

    /**
     * Localiza una referencia a español (campos principales en ES + originales
     * conservados). Lo visible al usuario queda siempre en español.
     */
    public function localize(array $ref): array
    {
        $local = Str::lower(trim((string) ($ref['local_name'] ?? '')));

        $origName  = $ref['name'] ?? '';
        $origPart  = $ref['body_part'] ?? null;
        $origTgt   = $ref['target'] ?? null;
        $origEquip = $ref['equipment'] ?? null;
        $origInstr = array_values($ref['instructions'] ?? []);

        $ref['original_name']         = $origName;
        $ref['original_body_part']    = $origPart;
        $ref['original_equipment']    = $origEquip;
        $ref['original_instructions'] = $origInstr;

        $ref['name']      = self::NAME_ES[$local] ?? Str::title($local !== '' ? $local : $origName);
        $ref['body_part'] = $this->es(self::PART_ES, $origPart);
        $ref['target']    = $this->es(self::PART_ES, $origTgt);
        $ref['equipment'] = $this->es(self::EQUIP_ES, $origEquip);
        $ref['instructions'] = self::INSTRUCTIONS_ES[$local]
            ?? (! empty($origInstr) ? $origInstr : self::INSTRUCTIONS_ES_FALLBACK);

        // Fallback visual (p. ej. Burpees → jump squat): forzar etiqueta ES
        // correcta; el nombre inglés real solo queda en original_name.
        if (isset(self::OVERRIDE_ES[$local])) {
            $ref['body_part'] = self::OVERRIDE_ES[$local]['body_part'];
            $ref['target']    = self::OVERRIDE_ES[$local]['target'];
            $ref['equipment'] = self::OVERRIDE_ES[$local]['equipment'];
        }

        return $ref;
    }

    private function es(array $map, ?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return $v;
        }
        return $map[Str::lower(trim($v))] ?? $v;
    }

    public function hasApiKey(): bool
    {
        return filled($this->apiKey);
    }

    // ── RUNTIME: solo DB/disco, CERO llamadas a FitGif ──────────────────────

    /** Búsqueda por nombre del ejercicio de la rutina (ES). */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $k = Str::lower($query);

        $rows = Exercise::where('provider', 'fitgif')
            ->where(fn ($q) => $q
                ->where('local_name', $k)
                ->orWhere('local_name', 'like', "%$k%")
                ->orWhere('name', 'like', "%$query%"))
            ->orderByRaw('CASE WHEN gif_path IS NOT NULL THEN 0 ELSE 1 END')
            ->limit(15)->get();

        return $rows->map->toReference()->all();
    }

    public function byMuscle(string $muscle): array
    {
        $en = self::MUSCLE_EN[Str::lower(trim($muscle))] ?? Str::lower(trim($muscle));

        return Exercise::where('provider', 'fitgif')
            ->where(fn ($q) => $q
                ->where('body_part', 'like', "%$en%")
                ->orWhere('target', 'like', "%$en%"))
            ->whereNotNull('gif_path')
            ->limit(30)->get()->map->toReference()->all();
    }

    public function all(int $limit = 30, int $offset = 0): array
    {
        return Exercise::where('provider', 'fitgif')
            ->whereNotNull('gif_path')
            ->orderBy('name')
            ->limit(max(1, min($limit, 100)))->offset(max(0, $offset))
            ->get()->map->toReference()->all();
    }

    public function find(string $externalId): ?array
    {
        return optional(
            Exercise::where('provider', 'fitgif')->where('external_id', $externalId)->first()
        )?->toReference();
    }

    /** Sirve el GIF guardado en disco (sin llamar a FitGif). */
    public function gifContents(string $externalId): ?string
    {
        $row = Exercise::where('provider', 'fitgif')
            ->where('external_id', $externalId)->first();
        if (! $row || ! $row->gif_path || ! Storage::exists($row->gif_path)) {
            return null;
        }
        return Storage::get($row->gif_path);
    }

    // ── SYNC: llamadas a FitGif con throttle + descarga del binario ─────────

    /**
     * Sincroniza todos los ejercicios del diccionario (o los pasados).
     * @param  callable|null  $progress  fn(string $line)
     * @return array{ok:int,fail:int,details:array}
     */
    public function sync(?callable $progress = null): array
    {
        $ok = 0; $fail = 0; $details = [];
        foreach (array_keys(self::DICTIONARY) as $localName) {
            $r = $this->resolveAndStore($localName);
            $details[$localName] = $r;
            if ($r['has_gif_url']) {
                $ok++;
            } else {
                $fail++;
            }
            if ($progress) {
                $progress(sprintf(
                    '%-22s -> %s (%s)',
                    $localName,
                    $r['has_gif_url'] ? 'OK' : 'sin GIF',
                    $r['selected_query'] ?? ($r['rate_limited'] ? 'rate-limit' : 'sin match'),
                ));
            }
        }
        return ['ok' => $ok, 'fail' => $fail, 'details' => $details];
    }

    /**
     * Resuelve un ejercicio probando candidatos y guarda el GIF.
     * @return array  estructura de diagnóstico (sin API key)
     */
    public function resolveAndStore(string $localName): array
    {
        $diag = $this->diagnose($localName, store: true);
        return $diag;
    }

    /**
     * Diagnóstico (lo usa el sync y el endpoint debug). Si store=true
     * descarga y persiste el GIF del resultado elegido.
     */
    public function diagnose(string $localName, bool $store = false): array
    {
        $k = Str::lower(trim($localName));
        [$bodyPart, $candidates] = self::DICTIONARY[$k]
            ?? [$this->muscleBodyPart($k), [$this->bareTerm($k)]];
        $bodyPart = $this->validBodyPart($bodyPart);

        $tried = [];
        $selected = null;
        $rateLimited = false;

        foreach (array_slice($candidates, 0, self::MAX_ATTEMPTS) as $cand) {
            $res = $this->fetch($cand, $bodyPart);
            $tried[] = [
                'query'        => $cand,
                'bodyPart'     => $bodyPart,
                'http_status'  => $res['status'],
                'result_count' => count($res['results']),
            ];
            if ($res['rate_limited']) {
                $rateLimited = true;
                break;
            }
            $best = $this->pickBest($res['results'], $cand, $bodyPart);
            // Guard de relevancia para ejercicios de core (Plancha): FitGif
            // a veces devuelve un squat irrelevante; se descarta y se sigue
            // probando candidatos. Mejor placeholder que un movimiento errado.
            if ($best && ! empty($best['url'])
                && (! $this->isCore($k) || $this->isCoreRelevant($best))) {
                $selected = ['query' => $cand, 'result' => $best];
                break;
            }
        }

        $hasGif = false;
        $stored = null;
        if ($selected) {
            $ref = $this->normalize($selected['result']);
            if ($store) {
                $stored = $this->store($k, $selected['query'], $ref, $selected['result']['url']);
                $hasGif = $stored !== null;
            } else {
                $hasGif = ! empty($selected['result']['url']);
            }
        }

        $this->log($localName, count($candidates), $selected['query'] ?? null, $hasGif);

        return [
            'query'           => $localName,
            'body_part'       => $bodyPart,
            'candidates'      => $candidates,
            'candidates_count'=> count($candidates),
            'attempts'        => $tried,
            'selected_query'  => $selected['query'] ?? null,
            'selected_name'   => $selected['result']['name'] ?? null,
            'has_gif_url'     => $hasGif,
            'rate_limited'    => $rateLimited,
            'external_id'     => $stored,
        ];
    }

    /** Proxy: el GIF se guardó en disco; gifContents lo sirve. */
    public function gifResponse(string $externalId): ?\Illuminate\Http\Client\Response
    {
        return null; // ya no se proxa en vivo; ver gifContents()
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function muscleBodyPart(string $k): string
    {
        return self::MUSCLE_EN[$k] ?? '';
    }

    private function bareTerm(string $k): string
    {
        return $k;
    }

    private function validBodyPart(?string $bp): ?string
    {
        $bp = $bp !== null ? Str::lower(trim($bp)) : '';
        return in_array($bp, self::VALID_BODYPARTS, true) ? $bp : null;
    }

    private function extId(string $name): string
    {
        return Str::slug(trim($name)) ?: 'fitgif';
    }

    /** Espera para respetar el límite de 3 req/min de FitGif. */
    private function throttle(): void
    {
        $last = (float) Cache::get('fitgif:lastcall', 0);
        $elapsed = microtime(true) - $last;
        if ($last > 0 && $elapsed < self::MIN_INTERVAL) {
            usleep((int) ((self::MIN_INTERVAL - $elapsed) * 1_000_000));
        }
        Cache::put('fitgif:lastcall', microtime(true), 180);
    }

    /**
     * POST {base}/api/search con throttle.
     * @return array{status:?int,results:array,rate_limited:bool}
     */
    private function fetch(string $search, ?string $bodyPart): array
    {
        if (! $this->hasApiKey()) {
            return ['status' => null, 'results' => [], 'rate_limited' => false];
        }
        $payload = [
            'key'         => $this->apiKey,
            'search'      => $search,
            'includeData' => true,
        ];
        if (filled($bodyPart)) {
            $payload['bodyPart'] = $bodyPart;
        }

        try {
            $this->throttle();
            $resp = Http::asJson()->timeout(20)
                ->post($this->baseUrl . '/api/search', $payload);
            $status = $resp->status();

            if ($status === 429) {
                Log::warning('FitGif rate limit', ['provider' => 'fitgif', 'http_status' => 429]);
                return ['status' => 429, 'results' => [], 'rate_limited' => true];
            }
            $json = $resp->json();
            $results = is_array($json['results'] ?? null) ? $json['results'] : [];
            return ['status' => $status, 'results' => $results, 'rate_limited' => false];
        } catch (Throwable $e) {
            Log::warning('FitGif request falló', [
                'provider' => 'fitgif',
                'reason'   => $this->sanitize($e->getMessage()),
            ]);
            return ['status' => null, 'results' => [], 'rate_limited' => false];
        }
    }

    /** Ejercicios cuyo fallback DEBE ser de core/abdomen. */
    private function isCore(string $local): bool
    {
        return in_array($local, ['plancha', 'abdominales', 'crunch'], true);
    }

    /** ¿El resultado FitGif es realmente de core/abdomen (no un squat)? */
    private function isCoreRelevant(array $r): bool
    {
        $name = Str::lower($r['name'] ?? '');
        $bp   = Str::lower($r['bodyPart'] ?? '');
        $tgt  = Str::lower($r['target'] ?? '');
        foreach (['plank', 'crawl', 'hover', 'climber', 'abdominal', 'core',
                  'hollow', 'crunch', 'sit-up', 'sit up', 'leg raise'] as $kw) {
            if (str_contains($name, $kw)) {
                return true;
            }
        }
        return in_array($bp, ['waist'], true)
            || in_array($tgt, ['abs', 'core', 'abductors'], true);
    }

    /** Elige el mejor resultado: con url, mejor score / coincidencia. */
    private function pickBest(array $results, string $cand, ?string $bodyPart): ?array
    {
        $withUrl = array_values(array_filter($results, fn ($r) => ! empty($r['url'])));
        if (empty($withUrl)) {
            return null;
        }
        usort($withUrl, function ($a, $b) use ($cand, $bodyPart) {
            return $this->scoreOf($b, $cand, $bodyPart) <=> $this->scoreOf($a, $cand, $bodyPart);
        });
        return $withUrl[0];
    }

    private function scoreOf(array $r, string $cand, ?string $bodyPart): int
    {
        $s = (int) ($r['score'] ?? 0);
        $name = Str::lower($r['name'] ?? '');
        if ($name === Str::lower($cand)) {
            $s += 1000;
        } elseif (str_contains($name, Str::lower($cand))) {
            $s += 400;
        }
        if ($bodyPart && Str::lower($r['bodyPart'] ?? '') === $bodyPart) {
            $s += 200;
        }
        return $s;
    }

    /** Mapea la respuesta FitGif a la forma interna estable. */
    private function normalize(array $r): array
    {
        $name = trim((string) ($r['name'] ?? ''));
        $steps = $r['steps'] ?? $r['instructions'] ?? [];
        if (is_string($steps)) {
            $steps = array_values(array_filter(array_map(
                'trim', preg_split('/\r\n|\r|\n/', $steps),
            )));
        }
        if (empty($steps) && ! empty($r['summary'])) {
            $steps = [trim((string) $r['summary'])];
        }
        return [
            'external_id'  => $this->extId($name),
            'name'         => $name,
            'body_part'    => $r['bodyPart'] ?? null,
            'target'       => $r['target'] ?? null,
            'equipment'    => $r['equipment'] ?? null,
            'instructions' => is_array($steps) ? array_values($steps) : [],
            'provider'     => 'fitgif',
            'source'       => $this->source,
        ];
    }

    /**
     * Descarga el GIF (la signed URL caduca en 60 s → se baja YA) y persiste
     * el match. Devuelve el external_id si quedó GIF guardado, o null.
     */
    private function store(string $localName, string $matchedQuery, array $ref, string $signedUrl): ?string
    {
        try {
            $resp = Http::timeout(30)->get($signedUrl);
            if (! $resp->successful() || ! str_contains((string) $resp->header('Content-Type'), 'image')) {
                return null;
            }
            // UNA fila por ejercicio local (clave = slug del nombre ES); así
            // varios nombres que matchean el mismo ejercicio FitGif NO se
            // pisan entre sí (ese era el bug de "sin GIF" pese a OK en sync).
            $extId = $this->extId($localName);
            $path = self::GIF_DIR . '/' . $extId . '.gif';
            Storage::put($path, $resp->body());

            Exercise::updateOrCreate(
                ['provider' => 'fitgif', 'external_id' => $extId],
                array_merge($ref, [
                    'external_id'    => $extId,
                    'local_name'     => $localName,
                    'matched_query'  => $matchedQuery,
                    'gif_url'        => 'stored',          // sentinel → proxy
                    'gif_path'       => $path,
                    'thumbnail_url'  => null,
                    'last_synced_at' => now(),
                ]),
            );
            // MP4 optimizado (más rápido/fluido). El GIF se conserva como
            // fallback; si ffmpeg falla, no rompe nada.
            $this->transcode($extId);
            return $extId;
        } catch (Throwable $e) {
            Log::warning('FitGif store falló', [
                'provider' => 'fitgif',
                'reason'   => $this->sanitize($e->getMessage()),
            ]);
            return null;
        }
    }

    /**
     * Genera el MP4 H.264 optimizado a partir del GIF cacheado (ffmpeg LOCAL,
     * sin llamar a FitGif). El GIF original se conserva como fallback.
     * Idempotente: salta si el MP4 ya existe y es más nuevo que el GIF.
     */
    public function transcode(string $externalId, bool $force = false): bool
    {
        $row = Exercise::where('provider', 'fitgif')
            ->where('external_id', $externalId)->first();
        if (! $row || ! $row->gif_path || ! Storage::exists($row->gif_path)) {
            return false;
        }

        $gifAbs   = Storage::path($row->gif_path);
        $relMp4   = self::VIDEO_DIR . '/' . $externalId . '.mp4';
        $mp4Abs   = Storage::path($relMp4);

        if (! $force
            && $row->video_path
            && is_file($mp4Abs)
            && filemtime($mp4Abs) >= @filemtime($gifAbs)) {
            return true; // ya está al día
        }

        @mkdir(dirname($mp4Abs), 0775, true);

        // Configurable: velocidad/fps/ancho/crf desde .env. Sin audio, H.264
        // compatible móvil, faststart para inicio rápido.
        $vf = sprintf(
            'setpts=PTS/%s,scale=%d:-2:flags=lanczos,fps=%d',
            $this->vSpeed,
            $this->vWidth,
            $this->vFps,
        );
        $cmd = [
            'ffmpeg', '-y', '-i', $gifAbs,
            '-vf', $vf,
            '-an', '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
            '-crf', (string) $this->vCrf, '-preset', 'veryfast',
            '-movflags', '+faststart',
            $mp4Abs,
        ];

        try {
            $res = \Illuminate\Support\Facades\Process::timeout(120)->run($cmd);
            if (! $res->successful() || ! is_file($mp4Abs) || filesize($mp4Abs) < 1024) {
                Log::warning('FitGif transcode falló', [
                    'provider'    => 'fitgif',
                    'external_id' => $externalId,
                    'exit'        => $res->exitCode(),
                    'reason'      => $this->sanitize(Str::limit($res->errorOutput(), 200)),
                ]);
                return false;
            }
            $row->update([
                'video_path'     => $relMp4,
                'media_type'     => 'video',
                'playback_speed' => $this->vSpeed,
            ]);
            Log::info('FitGif transcode OK', [
                'provider'    => 'fitgif',
                'external_id' => $externalId,
                'bytes'       => filesize($mp4Abs),
            ]);
            return true;
        } catch (Throwable $e) {
            Log::warning('FitGif transcode excepción', [
                'provider' => 'fitgif',
                'reason'   => $this->sanitize($e->getMessage()),
            ]);
            return false;
        }
    }

    /**
     * Transcodea TODOS los GIFs FitGif ya cacheados que no tengan MP4.
     * 100% local (ffmpeg) → no consume el límite de FitGif.
     * @param  callable|null  $progress  fn(string $line)
     * @return array{ok:int,fail:int,total:int}
     */
    public function transcodeAll(?callable $progress = null, bool $force = false): array
    {
        $rows = Exercise::where('provider', 'fitgif')
            ->whereNotNull('gif_path')->orderBy('external_id')->get();

        $ok = 0; $fail = 0;
        foreach ($rows as $row) {
            $done = $this->transcode($row->external_id, $force);
            $done ? $ok++ : $fail++;
            if ($progress) {
                $progress(sprintf('%-26s -> %s', $row->external_id, $done ? 'MP4 OK' : 'falló'));
            }
        }
        return ['ok' => $ok, 'fail' => $fail, 'total' => $rows->count()];
    }

    /** Ruta absoluta del MP4 (para servirlo con soporte de Range). */
    public function videoAbsolutePath(string $externalId): ?string
    {
        $row = Exercise::where('provider', 'fitgif')
            ->where('external_id', $externalId)->first();
        if (! $row || ! $row->video_path) {
            return null;
        }
        $abs = Storage::path($row->video_path);
        return is_file($abs) ? $abs : null;
    }

    private function log(string $exercise, int $candidatesCount, ?string $selected, bool $hasGif): void
    {
        Log::info('FitGif sync', [
            'provider'         => 'fitgif',
            'exercise'         => Str::limit($exercise, 60),
            'candidates_count' => $candidatesCount,
            'selected_query'   => $selected,
            'has_gif_url'      => $hasGif,
        ]);
    }

    private function sanitize(string $msg): string
    {
        if (filled($this->apiKey)) {
            $msg = str_ireplace((string) $this->apiKey, '[REDACTED]', $msg);
        }
        return Str::limit($msg, 300);
    }
}
