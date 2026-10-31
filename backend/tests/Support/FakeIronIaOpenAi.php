<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Un OpenAI de mentira para el plan integral por niveles.
 *
 * Responde a cada sección LEYENDO LO QUE SE LE PIDIÓ —el `json_schema`, los
 * momentos del día, los ids de `exercise_catalog`, la meta de la sección que va
 * en el prompt— igual que tendría que hacerlo el modelo. El contenido es el de
 * un plan colombiano normal, y los macros salen de una tabla de alimentos real
 * (por 100 g o por unidad), así que kcal = 4P + 4C + 9G por construcción y los
 * días se acercan a la meta como lo haría un buen modelo.
 *
 * No sustituye a una llamada real (calidad, latencia y coste se miden con
 * `iron-ia:evaluate` y clave): sirve para probar que el motor ensambla, suma,
 * valida y repara lo que le llega.
 *
 * `$tweak(string $schema, array $user, array $content, int $call)` permite
 * torcer una respuesta concreta: devolver otro contenido, un
 * `finish_reason=length`, un error HTTP o tardar. `$call` cuenta las llamadas
 * con el mismo esquema y la misma sección (1 = primera, 2 = reparación).
 */
final class FakeIronIaOpenAi
{
    /** Macros por 100 g (o por unidad si trae `unit_g`): [P, C, G]. */
    private const FOODS = [
        'huevo' => [6.3, 0.4, 5.0, 'unit'], 'arepa de maíz' => [2.5, 25.0, 2.0, 'unit'], 'banano' => [1.3, 27.0, 0.4, 'unit'],
        'manzana' => [0.4, 21.0, 0.3, 'unit'], 'pan integral' => [3.9, 12.3, 1.0, 'unit'],
        'avena en hojuelas' => [13.0, 66.0, 7.0], 'pechuga de pollo' => [31.0, 0.0, 3.6], 'carne de res magra' => [26.0, 0.0, 8.0],
        'tilapia' => [26.0, 0.0, 2.7], 'lentejas' => [24.0, 60.0, 1.0], 'fríjoles' => [22.0, 60.0, 1.5], 'lomo de cerdo' => [27.0, 0.0, 7.0],
        'atún en agua' => [25.0, 0.0, 1.0], 'pechuga de pavo' => [29.0, 0.0, 2.0], 'garbanzos' => [19.0, 61.0, 6.0],
        'queso campesino' => [18.0, 3.0, 20.0], 'tofu' => [12.0, 2.0, 7.0], 'arroz blanco' => [7.0, 79.0, 0.6], 'papa' => [2.0, 17.0, 0.1],
        'plátano maduro' => [1.3, 32.0, 0.4], 'yuca' => [1.4, 38.0, 0.3], 'pasta' => [13.0, 75.0, 1.5], 'quinua' => [14.0, 64.0, 6.0],
        'mazorca' => [3.3, 19.0, 1.4], 'aguacate' => [2.0, 9.0, 15.0], 'aceite de oliva' => [0.0, 0.0, 92.0], 'tomate' => [0.9, 3.9, 0.2],
        'cebolla' => [1.1, 9.0, 0.1], 'lechuga' => [1.4, 2.9, 0.2], 'brócoli' => [2.8, 7.0, 0.4], 'zanahoria' => [0.9, 10.0, 0.2],
        'yogur griego' => [10.0, 4.0, 0.4], 'leche deslactosada' => [3.3, 4.8, 1.5], 'bebida de almendras' => [0.5, 1.5, 1.1],
        'mantequilla de maní' => [25.0, 20.0, 50.0], 'papaya' => [0.5, 11.0, 0.3], 'fresas' => [0.7, 7.7, 0.3],
        'mango' => [0.8, 15.0, 0.4], 'piña' => [0.5, 13.0, 0.1], 'guayaba' => [2.6, 14.0, 1.0],
    ];

    /** Peso de una unidad, en gramos. */
    private const UNIT_G = ['huevo' => 50, 'arepa de maíz' => 60, 'banano' => 120, 'manzana' => 150, 'pan integral' => 30];

    /** Etiqueta de restricción → alimentos que la rompen (para no proponerlos). */
    private const AVOID = [
        'Sin maní' => ['mantequilla de maní'],
        'Sin lactosa' => ['queso campesino', 'yogur griego'],
        'Sin lácteos' => ['queso campesino', 'yogur griego', 'leche deslactosada'],
        'Vegetariano' => ['pechuga de pollo', 'carne de res magra', 'tilapia', 'lomo de cerdo', 'atún en agua', 'pechuga de pavo'],
        'Vegano' => ['pechuga de pollo', 'carne de res magra', 'tilapia', 'lomo de cerdo', 'atún en agua', 'pechuga de pavo', 'huevo', 'queso campesino', 'yogur griego', 'leche deslactosada'],
        'Sin gluten' => ['avena en hojuelas', 'pasta', 'pan integral'],
        'Sin huevo' => ['huevo'],
        'Sin carne roja' => ['carne de res magra', 'lomo de cerdo'],
    ];

    private const WEIGHTS = ['desayuno' => 0.25, 'media_manana' => 0.10, 'almuerzo' => 0.30, 'algo' => 0.10, 'cena' => 0.25, 'refrigerio' => 0.08];

    /** @var array<string,int> */
    private array $calls = [];

    /** @param  (callable(string, array, array, int): ?array)|null  $tweak */
    public function __construct(private $tweak = null) {}

    /** Registra el doble en `Http::fake` y lo devuelve para poder inspeccionarlo. */
    public static function install(?callable $tweak = null): self
    {
        $fake = new self($tweak);
        Http::fake(['*/v1/chat/completions' => fn (Request $r) => $fake->respond($r)]);

        return $fake;
    }

    public function respond(Request $request)
    {
        $body = $request->data();
        $schema = (string) data_get($body, 'response_format.json_schema.name', 'v1');
        $system = (string) data_get($body, 'messages.0.content', '');
        $user = json_decode((string) data_get($body, 'messages.1.content', '{}'), true) ?: [];
        $props = (array) data_get($body, 'response_format.json_schema.schema.properties', []);

        // Por SECCIÓN (día + primera comida): con 3 secciones por día, «la
        // primera llamada» tiene que ser la de esa sección, no la del día.
        $primera = (string) (data_get($props, 'meals.items.properties.slot.enum')[0] ?? '');
        $clave = $schema.'|'.($user['section']['day'] ?? $user['day']['name'] ?? '').($primera !== '' ? '|'.$primera : '');
        $n = $this->calls[$clave] = ($this->calls[$clave] ?? 0) + 1;

        // Sin `json_schema` es el nivel 1 de siempre: la respuesta capturada,
        // con sus ids 1..4 llevados al catálogo REAL de la petición, como haría
        // el modelo (en PostgreSQL las secuencias no vuelven a 1 entre pruebas).
        // El 999 inventado sigue inventado.
        if ($schema === 'v1') {
            $captura = json_decode(file_get_contents(base_path('tests/fixtures/iron-ia/v1-openai-response.json')), true);
            $ids = array_column((array) ($user['exercise_catalog'] ?? []), 'id');
            $plan = json_decode($captura['choices'][0]['message']['content'], true);
            foreach ($plan['routine']['days'] as &$d) {
                foreach ($d['exercises'] as &$e) {
                    $e['exercise_id'] = $ids[$e['exercise_id'] - 1] ?? $e['exercise_id'];
                }
            }
            unset($d, $e);
            if ($this->tweak) {
                $t = ($this->tweak)('v1', $user, $plan, 1, $system);
                $plan = is_array($t) ? ($t['content'] ?? $plan) : $plan;
            }
            $captura['choices'][0]['message']['content'] = json_encode($plan, JSON_UNESCAPED_UNICODE);

            return Http::response($captura, 200);
        }

        // En una reparación, el modelo ajusta las cantidades a lo que se le dijo.
        $ultimo = (string) data_get($body, 'messages.'.(count($body['messages'] ?? []) - 1).'.content', '');
        $correccion = preg_match('/suma (\d+) kcal y la meta es (\d+) kcal/', $ultimo, $mc) ? (int) $mc[2] / max(1, (int) $mc[1]) : 1.0;

        $content = match ($schema) {
            'nutrition_core' => $this->core($user),
            'nutrition_day' => $this->day($user, $system, $props, $correccion),
            'routine_frame' => $this->frame($props),
            'routine_day' => $this->routineDay($user, $system, $props),
            'nutrition_v1' => $this->nutritionV1($user),
            'routine_v1' => $this->routineV1($user),
            default => [],
        };

        $finish = 'stop';
        $status = 200;
        $raw = null;
        if ($this->tweak) {
            $t = ($this->tweak)($schema, $user, $content, $n, $system);
            if (is_array($t)) {
                $content = $t['content'] ?? $content;
                $finish = $t['finish_reason'] ?? $finish;
                $status = $t['status'] ?? $status;
                $raw = $t['raw'] ?? null;
                if (isset($t['sleep_ms'])) {
                    usleep((int) $t['sleep_ms'] * 1000);
                }
            }
        }

        if ($status !== 200) {
            return Http::response(['error' => ['message' => 'upstream']], $status);
        }

        $texto = $raw ?? json_encode($content, JSON_UNESCAPED_UNICODE);

        return Http::response([
            'id' => 'chatcmpl-fake-'.md5($clave.$n),
            'object' => 'chat.completion',
            'model' => 'gpt-4.1-mini-2025-04-14',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $texto, 'refusal' => null],
                'finish_reason' => $finish,
            ]],
            'usage' => [
                'prompt_tokens' => (int) ceil(mb_strlen(json_encode($body['messages'], JSON_UNESCAPED_UNICODE)) / 4),
                'completion_tokens' => (int) ceil(mb_strlen($texto) / 4),
                'total_tokens' => (int) ceil(mb_strlen(json_encode($body['messages'], JSON_UNESCAPED_UNICODE)) / 4) + (int) ceil(mb_strlen($texto) / 4),
            ],
        ], 200);
    }

    public function calls(): array
    {
        return $this->calls;
    }

    // ── Nutrición ───────────────────────────────────────────────────────────

    private function core(array $user): array
    {
        $b = $user['member_brief'] ?? [];
        $seguro = (bool) ($b['safety_mode'] ?? false);

        return [
            'analysis' => [
                'profile' => 'Socio de nivel '.($b['training_level'] ?? 'principiante').', objetivo: '.($b['objective'] ?? 'no consta').'; disponibilidad de '.($b['days_available'] ?? '?').' días.',
                'priorities' => ['Constancia en el entrenamiento', 'Proteína suficiente en cada comida', 'Hidratación diaria'],
                'approach' => 'Alimentación casera colombiana repartida en el día y entrenamiento de fuerza progresivo. Se ajusta cada dos semanas según la respuesta.',
                'limitations' => (string) ($b['injuries'] ?? ''),
                'strategy' => 'Fuerza en los días disponibles y comidas planificadas con anticipación.',
            ],
            'objective' => (string) ($b['objective'] ?? 'Bienestar general'),
            'objective_description' => 'Mejorar la composición corporal de forma gradual, sin restricciones extremas.',
            'training_stage' => 'Adaptación',
            'hydration' => 'Entre 2 y 3 litros de agua al día, más en los días de entrenamiento.',
            'organization' => 'Prepara las proteínas y los granos dos veces por semana y lleva el almuerzo en recipiente.',
            'supplements' => $seguro ? 'No se recomiendan suplementos sin valoración de un profesional de la salud.' : 'No son necesarios con esta alimentación.',
            'general_substitutions' => 'El arroz se puede cambiar por papa o yuca en la misma cantidad cocida. Las frutas se pueden intercambiar entre sí.',
        ];
    }

    private function day(array $user, string $system, array $props, float $correccion = 1.0): array
    {
        $b = $user['member_brief'] ?? [];
        $evitar = [];
        foreach ((array) ($b['restrictions'] ?? []) as $etiqueta) {
            $evitar = array_merge($evitar, self::AVOID[$etiqueta] ?? []);
        }
        $slots = (array) data_get($props, 'meals.items.properties.slot.enum', []);
        $porIngrediente = isset($props['meals']['items']['properties']['items']['items']['properties']['protein_g']);
        $conPrep = isset($props['meals']['items']['properties']['preparation']);
        $conCocido = isset($props['meals']['items']['properties']['items']['items']['properties']['cooked_g']);
        $anclas = (array) ($user['section']['anchors'] ?? []);
        $dia = (string) ($user['section']['day'] ?? 'Día');

        $objetivo = preg_match('/sumar cerca de (\d+) kcal y (\d+) g de proteína/', $system, $m) ? [(int) round($m[1] * $correccion), (int) $m[2]] : null;
        // Sin meta, un buen modelo respeta el mínimo prudente con algo de margen
        // (proteína ≈ 20 % de esas kcal).
        if ($objetivo === null && preg_match('/deben aportar al menos (\d+) kcal/', $system, $mm)) {
            $k = (int) round($mm[1] * 1.25 * $correccion);
            $objetivo = [$k, (int) round($k * 0.2 / 4)];
        }
        $pesos = array_intersect_key(self::WEIGHTS, array_flip($slots));
        $sumaPesos = array_sum($pesos) ?: 1;

        $porSlot = [];
        foreach ($slots as $i => $slot) {
            $porSlot[$slot] = $this->template($slot, $anclas[$slot] ?? [], $evitar, $dia.$i);
        }

        // Como lo haría un buen modelo: las comidas pequeñas se ajustan a su
        // parte de las kcal y la principal cierra lo que falte de kcal Y de
        // proteína, resolviendo las cantidades de su proteína y su acompañante.
        if ($objetivo !== null) {
            [$kSec, $pSec] = $objetivo;
            $principales = array_values(array_intersect($slots, ['almuerzo', 'cena']));
            $kRest = $kSec;
            $pRest = $pSec;
            foreach ($slots as $slot) {
                if (in_array($slot, $principales, true)) {
                    continue;
                }
                $porSlot[$slot] = $this->scale($porSlot[$slot], $kSec * $pesos[$slot] / $sumaPesos);
                [$p, $c, $g] = $this->macros($porSlot[$slot]);
                $kRest -= 4 * $p + 4 * $c + 9 * $g;
                $pRest -= $p;
            }
            // Sin plato principal en la sección (desayuno + media mañana), un
            // buen modelo completa la proteína que le toca con huevo, yogur o
            // tofu, lo que su dieta permita.
            if ($principales === [] && $pRest > 3 && $slots !== []) {
                foreach ([['huevo', 'unidad', 6.3], ['yogur griego', 'g', 0.10], ['tofu', 'g', 0.12]] as [$extra, $unidad, $porUnidad]) {
                    if (! in_array($extra, $evitar, true)) {
                        $cantidad = $unidad === 'unidad' ? min(3.0, ceil($pRest / $porUnidad)) : round($pRest / $porUnidad / 5) * 5;
                        $porSlot[$slots[0]][] = [$extra, (float) $cantidad, $unidad];
                        break;
                    }
                }
            }
            $pesoPrincipales = array_sum(array_intersect_key($pesos, array_flip($principales))) ?: 1;
            foreach ($principales as $slot) {
                $f = $pesos[$slot] / $pesoPrincipales;
                $porSlot[$slot] = $this->solve($porSlot[$slot], $kRest * $f, $pRest * $f);
            }
        }

        $comidas = [];
        foreach ($slots as $slot) {
            $items = $porSlot[$slot];
            [$p, $c, $g] = $this->macros($items);
            $comida = [
                'slot' => $slot,
                'title' => $this->title($slot, $items, $dia, (string) ($anclas[$slot]['protein'] ?? '')),
                'items' => array_map(function ($it) use ($conCocido, $porIngrediente) {
                    $linea = array_filter([
                        'food' => $it[0], 'amount' => $it[1], 'unit' => $it[2],
                        'cooked_g' => $conCocido ? ($it[2] === 'g' && in_array($it[0], ['arroz blanco', 'pasta', 'quinua', 'lentejas', 'fríjoles', 'garbanzos'], true) ? round($it[1] * 2.5) : null) : 'omit',
                    ], fn ($v) => $v !== 'omit');
                    if ($porIngrediente) {
                        // Como un buen modelo: macros de ESE ingrediente según
                        // su cantidad, de tabla; y su peso en gramos.
                        [$ip, $ic, $ig] = $this->macros([$it]);
                        $linea += [
                            'grams' => match ($it[2]) {
                                'g', 'ml' => $it[1], 'unidad' => $it[1] * (self::UNIT_G[$it[0]] ?? 100), default => 0.0
                            },
                            'role' => $this->role($it[0]),
                            'protein_g' => round($ip, 1), 'carbs_g' => round($ic, 1), 'fat_g' => round($ig, 1),
                        ];
                    }

                    return $linea;
                }, $items),
            ];
            if ($conPrep) {
                $comida['preparation'] = 'Cocina la proteína a la plancha con poco aceite y sirve con el acompañante y la verdura.';
            }
            $comida['substitutions'] = 'Puedes cambiar el acompañante por papa o yuca en la misma cantidad.';

            $comidas[] = $comida;
        }

        return ['meals' => $comidas];
    }

    /** @return list<array{0: string, 1: float, 2: string}> */
    private function template(string $slot, array $ancla, array $evitar, string $semilla): array
    {
        // Rotación por día de la semana (como un buen modelo): con 4 o más
        // opciones, ningún plato sale más de 2 veces en el mismo momento.
        $dia = array_search(preg_replace('/\d+$/', '', $semilla), ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'], true);
        $indice = $dia === false ? crc32($semilla) : $dia;
        $pick = function (array $opciones, int $desfase = 0) use ($evitar, $indice) {
            $validas = array_values(array_filter($opciones, fn ($o) => array_intersect(array_column($o, 0), $evitar) === []));

            return $validas[($indice + $desfase) % max(1, count($validas))] ?? $opciones[0];
        };

        $proteina = $this->protein((string) ($ancla['protein'] ?? 'pollo'), $evitar);
        $carbo = $this->carb((string) ($ancla['carb'] ?? 'arroz'));
        $meriendas = [
            [['papaya', 200.0, 'g'], ['yogur griego', 120.0, 'g']],
            [['banano', 1.0, 'unidad'], ['mantequilla de maní', 15.0, 'g']],
            [['manzana', 1.0, 'unidad'], ['queso campesino', 40.0, 'g']],
            [['fresas', 150.0, 'g'], ['aguacate', 30.0, 'g'], ['arepa de maíz', 1.0, 'unidad']],
            [['manzana', 1.0, 'unidad'], ['fresas', 100.0, 'g'], ['aguacate', 20.0, 'g']],
            [['banano', 1.0, 'unidad'], ['fresas', 100.0, 'g']],
            [['mango', 200.0, 'g'], ['banano', 1.0, 'unidad']],
            [['piña', 200.0, 'g'], ['fresas', 100.0, 'g']],
            [['guayaba', 150.0, 'g'], ['manzana', 1.0, 'unidad']],
        ];

        return match ($slot) {
            'desayuno' => $pick([
                [['huevo', 2.0, 'unidad'], ['arepa de maíz', 1.0, 'unidad'], ['tomate', 50.0, 'g'], ['cebolla', 20.0, 'g'], ['sal', 0.0, 'al_gusto']],
                [['avena en hojuelas', 50.0, 'g'], ['bebida de almendras', 200.0, 'ml'], ['banano', 1.0, 'unidad']],
                [['fríjoles', 40.0, 'g'], ['arroz blanco', 30.0, 'g'], ['arepa de maíz', 1.0, 'unidad'], ['aguacate', 40.0, 'g']],
                [['pan integral', 2.0, 'unidad'], ['aguacate', 50.0, 'g'], ['tomate', 40.0, 'g'], ['huevo', 1.0, 'unidad']],
                [['arepa de maíz', 1.0, 'unidad'], ['aguacate', 60.0, 'g'], ['fresas', 100.0, 'g']],
                [['papaya', 200.0, 'g'], ['avena en hojuelas', 30.0, 'g'], ['banano', 1.0, 'unidad']],
            ]),
            'media_manana' => $pick($meriendas),
            'algo', 'refrigerio' => $pick($meriendas, 3),
            // La proteína que se ajusta es siempre una magra; si el ancla no lo
            // es (legumbre, huevo, queso, tofu), va como porción fija al lado,
            // como en un almuerzo corriente («lentejas con arroz y pollo»).
            default => in_array($proteina, self::LEAN, true)
                ? [[$proteina, 130.0, 'g'], [$carbo, 70.0, 'g'], ['brócoli', 80.0, 'g'], ['zanahoria', 50.0, 'g'], ['aceite de oliva', 5.0, 'ml']]
                : [[$this->lean($evitar), 100.0, 'g'], [$carbo, 50.0, 'g'], $proteina === 'huevo' ? ['huevo', 2.0, 'unidad'] : [$proteina, 60.0, 'g'], ['tomate', 60.0, 'g'], ['aceite de oliva', 5.0, 'ml']],
        };
    }

    private function role(string $alimento): string
    {
        return match (true) {
            in_array($alimento, array_merge(self::LEAN, ['huevo', 'fríjoles', 'lentejas', 'garbanzos', 'queso campesino', 'tofu', 'yogur griego']), true) => 'protein',
            in_array($alimento, ['aceite de oliva', 'aguacate', 'mantequilla de maní'], true) => 'fat',
            in_array($alimento, ['tomate', 'cebolla', 'lechuga', 'brócoli', 'zanahoria', 'sal', 'bebida de almendras', 'leche deslactosada'], true) => 'other',
            default => 'carb',
        };
    }

    private const LEAN = ['pechuga de pollo', 'carne de res magra', 'tilapia', 'lomo de cerdo', 'atún en agua', 'pechuga de pavo'];

    private function lean(array $evitar): string
    {
        foreach (['pechuga de pollo', 'pechuga de pavo', 'tofu'] as $p) {
            if (! in_array($p, $evitar, true)) {
                return $p;
            }
        }

        return 'tofu';
    }

    private function protein(string $ancla, array $evitar): string
    {
        $mapa = ['pollo' => 'pechuga de pollo', 'res' => 'carne de res magra', 'pescado' => 'tilapia', 'huevo' => 'huevo', 'lentejas' => 'lentejas',
            'cerdo' => 'lomo de cerdo', 'fríjol' => 'fríjoles', 'atún' => 'atún en agua', 'pavo' => 'pechuga de pavo', 'garbanzo' => 'garbanzos',
            'queso' => 'queso campesino', 'tofu' => 'tofu'];
        foreach ($mapa as $k => $v) {
            if (str_contains($ancla, $k) && ! in_array($v, $evitar, true)) {
                return $v;
            }
        }

        return in_array('pechuga de pollo', $evitar, true) ? 'lentejas' : 'pechuga de pollo';
    }

    private function carb(string $ancla): string
    {
        $mapa = ['arroz' => 'arroz blanco', 'papa' => 'papa', 'plátano' => 'plátano maduro', 'yuca' => 'yuca', 'arepa' => 'arepa de maíz',
            'pasta' => 'pasta', 'quinua' => 'quinua', 'mazorca' => 'mazorca', 'pan' => 'pan integral'];
        foreach ($mapa as $k => $v) {
            if (str_contains($ancla, $k)) {
                return match ($v) {
                    'arepa de maíz' => 'yuca', 'pan integral' => 'papa', default => $v
                };
            }
        }

        return 'arroz blanco';
    }

    /**
     * Ajusta la proteína (ítem 0) y el acompañante (ítem 1) de una comida
     * principal para que dé esas kcal y esa proteína: dos ecuaciones, dos
     * cantidades. Si no hay solución razonable, escala y ya.
     */
    private function solve(array $items, float $kcal, float $prot): array
    {
        $resto = array_slice($items, 2);
        [$pr, $cr, $gr] = $this->macros($resto);
        $kr = 4 * $pr + 4 * $cr + 9 * $gr;
        $densidad = function (string $alimento): array {
            $f = self::FOODS[$alimento];
            $div = ($f[3] ?? null) === 'unit' ? 1 : 100;

            return [$f[0] / $div, 4 * $f[0] / $div + 4 * $f[1] / $div + 9 * $f[2] / $div];
        };
        [$pp, $kp] = $densidad($items[0][0]);
        [$cp, $kc] = $densidad($items[1][0]);
        $det = $pp * $kc - $cp * $kp;
        if (abs($det) < 1e-9) {
            return $this->scale($items, $kcal);
        }
        $x = (($prot - $pr) * $kc - $cp * ($kcal - $kr)) / $det;
        $y = ($pp * ($kcal - $kr) - $kp * ($prot - $pr)) / $det;
        $redondea = fn (array $it, float $v) => $it[2] === 'unidad'
            ? [$it[0], max(1.0, round($v)), 'unidad']
            : [$it[0], max(20.0, min(450.0, round($v / 5) * 5)), $it[2]];

        return array_merge([$redondea($items[0], $x), $redondea($items[1], $y)], $resto);
    }

    /** Escala las cantidades para acercar la comida a su parte de la meta. */
    private function scale(array $items, float $kcalObjetivo): array
    {
        [$p, $c, $g] = $this->macros($items);
        $kcal = 4 * $p + 4 * $c + 9 * $g;
        if ($kcal <= 0) {
            return $items;
        }
        $f = max(0.4, min(3.0, $kcalObjetivo / $kcal));

        return array_map(function ($it) use ($f) {
            if ($it[2] === 'al_gusto') {
                return $it;
            }
            // Las unidades no se escalan (nadie come 2,7 bananos); el ajuste
            // fino lo hacen los gramos y la comida principal.
            if ($it[2] === 'unidad') {
                return $it;
            }

            return [$it[0], max(5.0, round($it[1] * min($f, 2.0) / 5) * 5), $it[2]];
        }, $items);
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function macros(array $items): array
    {
        $p = $c = $g = 0.0;
        foreach ($items as [$alimento, $cantidad, $unidad]) {
            $f = self::FOODS[$alimento] ?? null;
            if ($f === null || $unidad === 'al_gusto') {
                continue;
            }
            $factor = ($f[3] ?? null) === 'unit' ? $cantidad : $cantidad / 100;
            $p += $f[0] * $factor;
            $c += $f[1] * $factor;
            $g += $f[2] * $factor;
        }

        return [$p, $c, $g];
    }

    private function title(string $slot, array $items, string $dia, string $ancla = ''): string
    {
        $nombres = array_column(array_filter($items, fn ($i) => $i[2] !== 'al_gusto'), 0);
        // Si la proteína magra sustituye al ancla (lentejas, tofu…), el plato
        // se llama por el ancla, que es lo que varía de un día a otro.
        $principal = in_array($slot, ['almuerzo', 'cena'], true) && isset($nombres[2]) && ! in_array($nombres[0], self::LEAN, true) ? $nombres[2] : ($nombres[0] ?? 'Comida');

        return ucfirst($principal).' con '.($nombres[1] ?? 'verduras').($slot === 'cena' ? ' y ensalada' : '');
    }

    // ── Rutina ──────────────────────────────────────────────────────────────

    private function frame(array $props): array
    {
        $out = [
            'name' => 'Programa de fuerza progresiva',
            'objective' => 'Ganar fuerza y mejorar la composición corporal',
            'schedule' => 'Entrena en días no consecutivos cuando puedas y deja al menos un día de descanso completo.',
            'recovery' => 'Duerme entre 7 y 9 horas; si el dolor muscular dura más de 72 horas, baja la carga esa semana.',
        ];
        if (isset($props['progression'])) {
            $out['progression'] = 'Sube repeticiones dentro del rango y, al completar el tope dos sesiones seguidas, aumenta la carga.';
        }
        if (isset($props['blocks'])) {
            $out['blocks'] = [['weeks' => 'Semanas 1-2', 'focus' => 'Técnica y volumen moderado'], ['weeks' => 'Semanas 3-4', 'focus' => 'Más carga con menos repeticiones']];
        }
        if (isset($props['weeks'])) {
            $out['weeks'] = [
                ['week' => 1, 'focus' => 'Aprender la técnica', 'load' => 'RPE 6'],
                ['week' => 2, 'focus' => 'Subir volumen', 'load' => 'RPE 7'],
                ['week' => 3, 'focus' => 'Subir intensidad', 'load' => 'RPE 7-8'],
                ['week' => 4, 'focus' => 'Descarga', 'load' => 'Mitad de series, RPE 6'],
            ];
            $out['adjustment_criteria'] = 'Si completas todas las series con técnica limpia, sube la carga; si fallas dos sesiones, mantenla.';
            $out['warmup'] = 'Ocho minutos de bicicleta suave y movilidad de cadera y hombros.';
        }

        return $out;
    }

    private function routineDay(array $user, string $system, array $props): array
    {
        $catalogo = array_values(array_column((array) ($user['exercise_catalog'] ?? []), 'id'));
        preg_match('/Entre (\d+) y (\d+) ejercicios/', $system, $m);
        $min = (int) ($m[1] ?? 4);
        $max = (int) ($m[2] ?? 6);
        $rpeMax = preg_match('/nunca por encima de ([\d.]+)/', $system, $r) ? (float) $r[1] : 9.0;
        $indice = preg_match('/Día (\d+)/', (string) ($user['day']['name'] ?? ''), $d) ? (int) $d[1] - 1 : 0;

        $n = min($max, max($min, $min + 1), count($catalogo));
        $ex = [];
        for ($i = 0; $i < $n; $i++) {
            $id = $catalogo[($indice * 2 + $i) % count($catalogo)];
            $e = [
                'exercise_id' => $id,
                'sets' => 3,
                'reps' => '10-12',
                'rest_seconds' => 90,
                'alternative_exercise_id' => $catalogo[($indice * 2 + $i + $n) % count($catalogo)],
            ];
            if (isset($props['exercises']['items']['properties']['progression'])) {
                $e['progression'] = 'Sube una repetición por semana; al llegar a 12, aumenta la carga.';
            }
            if (isset($props['exercises']['items']['properties']['rpe'])) {
                $e['rpe'] = (string) (int) min(7, $rpeMax);
            }
            if (isset($props['exercises']['items']['properties']['technique'])) {
                $e['technique'] = 'Baja en dos segundos y mantén el abdomen firme.';
            }
            $ex[] = $e;
        }

        $out = ['day_objective' => 'Trabajar '.($user['day']['muscles'] ?? 'cuerpo completo').' con técnica controlada.'];
        if (isset($props['warmup'])) {
            $out['warmup'] = 'Dos series de aproximación del primer ejercicio con poco peso.';
        }
        $out['exercises'] = $ex;

        return $out;
    }

    private function nutritionV1(array $user): array
    {
        return [
            'analysis' => $this->core($user)['analysis'],
            'nutrition' => [
                'objective' => (string) ($user['member_brief']['objective'] ?? 'Bienestar general'),
                'objective_description' => 'Comer mejor de forma sostenible.',
                'training_stage' => 'Adaptación',
                'meals' => [
                    ['label' => 'Desayuno', 'time' => '07:00', 'description' => 'Huevos revueltos con arepa y fruta.'],
                    ['label' => 'Almuerzo', 'time' => '12:30', 'description' => 'Pollo a la plancha, arroz y ensalada.'],
                    ['label' => 'Cena', 'time' => '19:00', 'description' => 'Pescado al horno con verduras.'],
                ],
                'recommendations' => 'Beber 2 litros de agua al día.',
                'restrictions' => 'Ver restricciones registradas.',
                'supplements' => 'No se recomiendan suplementos sin valoración profesional.',
                'notes' => 'Revisar con el socio sus horarios.',
            ],
        ];
    }

    private function routineV1(array $user): array
    {
        $ids = array_values(array_column((array) ($user['exercise_catalog'] ?? []), 'id'));

        return ['routine' => [
            'name' => 'Rutina base 2 días',
            'objective' => 'Acondicionamiento',
            'level' => 'principiante',
            'days' => [
                ['name' => 'Día 1', 'exercises' => array_map(fn ($id) => ['exercise_id' => $id, 'sets' => 3, 'reps' => '12', 'rest_seconds' => 90, 'notes' => ''], array_slice($ids, 0, 4))],
                ['name' => 'Día 2', 'exercises' => array_map(fn ($id) => ['exercise_id' => $id, 'sets' => 3, 'reps' => '12', 'rest_seconds' => 90, 'notes' => ''], array_slice($ids, 4, 4) ?: array_slice($ids, 0, 4))],
            ],
        ]];
    }
}
