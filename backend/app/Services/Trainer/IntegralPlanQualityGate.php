<?php

namespace App\Services\Trainer;

/**
 * La puerta de calidad ANTES de guardar (docs/iron-ia/POTENCIAS-IRON-IA.md §4).
 *
 * Cada sección que devuelve el modelo pasa por aquí. Lo que sale es la sección
 * NORMALIZADA (tipos limpios, kcal recalculadas) y la lista de lo que no
 * cumple, en frases que se le pueden devolver al modelo para que lo corrija.
 * Una sección con algún problema se repara una vez; si sigue mal, la
 * generación falla entera: un plan premium a medias no se guarda.
 *
 * Es determinista: los mismos datos dan el mismo veredicto, y nada de lo que
 * comprueba se lo pregunta al modelo.
 */
class IntegralPlanQualityGate
{
    /** Suplementos proteicos: no van en las comidas de un plan. */
    private const PROTEIN_SUPPLEMENT = '/(?<![a-z])(proteina en polvo|proteina de suero|whey|suplemento proteico|aislado de proteina|batido de proteina|caseina en polvo)(?![a-z])/';

    /** Alimentos corrientes con más de 0,35 g de proteína por gramo (en seco o curados). */
    private const DENSE_PROTEIN = '/(?<![a-z])(soya texturizada|soja texturizada|proteina de soya texturizada|carve|seitan|gluten de trigo|parmesano|queso parmesano|cecina|carne seca|bacalao seco|atun seco)(?![a-z])/';

    public function __construct(private readonly PlanSafetyLexicon $lexicon) {}

    // ── Nutrición ───────────────────────────────────────────────────────────

    /**
     * Una sección de comidas.
     *
     * @param  array<string,mixed>|null  $json
     * @param  array<string,mixed>  $meta  la sección tal como se planificó
     * @param  array<string,mixed>  $spec  IntegralPlanStrategy::nutritionSpec()
     * @param  list<string>  $restrictionKeys
     * @return array{issues: list<string>, data: array<string, array<string,mixed>>}
     */
    public function nutritionDay(?array $json, array $meta, array $spec, array $restrictionKeys): array
    {
        $issues = [];
        $out = [];
        $comidas = is_array($json['meals'] ?? null) ? $json['meals'] : [];
        $porSlot = [];

        foreach ($comidas as $c) {
            if (is_array($c) && is_string($c['slot'] ?? null)) {
                $porSlot[$c['slot']][] = $c;
            }
        }

        $minItems = $spec['level'] >= 4 ? 2 : 1;

        foreach ($meta['slots'] as $slot => [$etiqueta]) {
            $lista = $porSlot[$slot] ?? [];
            if (count($lista) !== 1) {
                $issues[] = count($lista) === 0
                    ? "Falta la comida «{$etiqueta}»."
                    : "La comida «{$etiqueta}» viene repetida; debe haber exactamente una.";
                if ($lista === []) {
                    continue;
                }
            }
            $c = $lista[0];

            $titulo = $this->text($c['title'] ?? null);
            if ($titulo === null) {
                $issues[] = "La comida «{$etiqueta}» no tiene título.";
            }

            $items = [];
            foreach ((array) ($c['items'] ?? []) as $it) {
                if (! is_array($it)) {
                    continue;
                }
                $alimento = $this->text($it['food'] ?? null);
                $unidad = is_string($it['unit'] ?? null) ? $it['unit'] : 'g';
                $cantidad = is_numeric($it['amount'] ?? null) ? (float) $it['amount'] : 0.0;
                if ($alimento === null) {
                    continue;
                }
                if ($unidad !== 'al_gusto' && $cantidad <= 0) {
                    $issues[] = "En «{$etiqueta}», «{$alimento}» no trae cantidad.";
                }
                $cocido = $it['cooked_g'] ?? null;
                $linea = [
                    'food' => $alimento,
                    'amount' => $unidad === 'al_gusto' ? 0.0 : $cantidad,
                    'unit' => $unidad,
                    'cooked_g' => is_numeric($cocido) && (float) $cocido > 0 ? (float) $cocido : null,
                ];
                if ($spec['detail']['macros']) {
                    $issue = $this->ingredientMacros($it, $linea, $etiqueta);
                    if ($issue !== null) {
                        $issues[] = $issue;
                    }
                }
                $items[] = $linea;
            }
            if (count($items) < $minItems) {
                $issues[] = "La comida «{$etiqueta}» necesita al menos {$minItems} ingrediente(s) con cantidad.";
            }

            $preparacion = $this->text($c['preparation'] ?? null);
            if ($spec['detail']['preparation'] && $preparacion === null) {
                $issues[] = "La comida «{$etiqueta}» no trae preparación.";
            }
            $sustitucion = $this->text($c['substitutions'] ?? null);

            $macros = null;
            if ($spec['detail']['macros'] && $items !== []) {
                // Los macros de la comida son la SUMA de sus ingredientes y las
                // kcal las calcula PHP (4/4/9). Lo que el modelo diga de la
                // comida entera, o en kcal, no se lee.
                $p = round(array_sum(array_column($items, 'protein_g')), 1);
                $ch = round(array_sum(array_column($items, 'carbs_g')), 1);
                $g = round(array_sum(array_column($items, 'fat_g')), 1);
                $macros = ($p + $ch + $g) > 0
                    ? ['kcal' => (int) round(4 * $p + 4 * $ch + 9 * $g), 'protein_g' => $p, 'carbs_g' => $ch, 'fat_g' => $g]
                    : null;
                if ($macros === null) {
                    $issues[] = "La comida «{$etiqueta}» no trae los macros de sus ingredientes.";
                }
            }

            foreach (['título' => $titulo, 'preparación' => $preparacion, 'sustitución' => $sustitucion] + array_combine(
                array_map(fn ($it) => 'ingrediente «'.$it['food'].'»', $items),
                array_column($items, 'food'),
            ) as $campo => $texto) {
                $issue = $this->riskIssue("«{$etiqueta}» ({$campo})", (string) $texto);
                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }

            // Ninguna restricción declarada puede aparecer: ni en un
            // ingrediente, ni en cómo se prepara, ni en lo que se propone para
            // cambiarlo.
            if ($restrictionKeys !== []) {
                $campos = ['título' => $titulo, 'preparación' => $preparacion, 'sustitución' => $sustitucion];
                foreach ($items as $it) {
                    $campos['ingrediente «'.$it['food'].'»'] = $it['food'];
                }
                foreach ($campos as $campo => $texto) {
                    $issue = $this->conflictIssue("«{$etiqueta}» ({$campo})", (string) $texto, $restrictionKeys);
                    if ($issue !== null) {
                        $issues[] = $issue;
                    }
                }
            }

            $out[$slot] = [
                'slot' => $slot,
                'title' => $titulo,
                'items' => $items,
                'preparation' => $preparacion,
                'substitutions' => $sustitucion,
                'macros' => $macros,
                'target' => $meta['targets'][$slot] ?? null,
            ];
        }

        // Copia: varias comidas con EXACTAMENTE la meta por comida no son una
        // estimación de sus ingredientes (Ana, corrida 3: 33 de 35).
        $copias = array_filter($out, fn ($m) => $this->copiesTarget($m));
        if (count($copias) >= max(1, (int) config('iron_ai.plan_powers.copy_meals_threshold', 2))) {
            $issues[] = sprintf(
                'Los macros de %d comidas (%s) copian la meta por comida en vez de salir de sus ingredientes. La meta orienta las porciones; los macros se calculan de cada ingrediente según su cantidad.',
                count($copias), implode(', ', array_map(fn ($m) => '«'.$meta['slots'][$m['slot']][0].'»', $copias)),
            );
        }

        return ['issues' => array_values(array_unique($issues)), 'data' => $out];
    }

    /** ¿Coinciden los macros de la comida con su meta (±1 g en P, C y G)? */
    public function copiesTarget(array $meal): bool
    {
        $m = $meal['macros'] ?? null;
        $t = $meal['target'] ?? null;
        if (! is_array($m) || ! is_array($t)) {
            return false;
        }

        return abs($m['protein_g'] - $t['protein_g']) <= 1 && abs($m['carbs_g'] - $t['carbs_g']) <= 1 && abs($m['fat_g'] - $t['fat_g']) <= 1;
    }

    /**
     * Lee los macros de UN ingrediente en `$linea` y comprueba que sean
     * posibles para su peso: P + C + G no pueden pesar más que el alimento,
     * la densidad no pasa de 9 kcal/g (grasa pura) y la proteína no pasa del
     * 90 % del peso. Es un tope físico, no una tabla de alimentos.
     */
    private function ingredientMacros(array $it, array &$linea, string $etiqueta): ?string
    {
        $p = $this->num($it['protein_g'] ?? null) ?? 0.0;
        $ch = $this->num($it['carbs_g'] ?? null) ?? 0.0;
        $g = $this->num($it['fat_g'] ?? null) ?? 0.0;
        $peso = match ($linea['unit']) {
            'g', 'ml' => $linea['amount'],
            default => $this->num($it['grams'] ?? null) ?? 0.0,
        };
        $rol = in_array($it['role'] ?? null, ['protein', 'carb', 'fat', 'other'], true) ? $it['role'] : 'other';
        $linea += ['grams' => round($peso, 1), 'role' => $rol, 'protein_g' => round($p, 1), 'carbs_g' => round($ch, 1), 'fat_g' => round($g, 1)];

        $nombre = $this->lexicon->normalize($linea['food']);
        if (preg_match(self::PROTEIN_SUPPLEMENT, $nombre)) {
            return "En «{$etiqueta}», «{$linea['food']}» es un suplemento proteico: no va en las comidas. Usa alimentos.";
        }

        // Corrida 4: con la tasa por gramo, más de ~0,35 g de proteína por
        // gramo no existe en un alimento corriente (la pechuga cruda ronda
        // 0,23-0,31). Los densos conocidos quedan fuera de esta regla.
        $tope = (float) config('iron_ai.plan_powers.protein_per_gram_max', 0.35);
        if ($peso > 0 && $p / $peso > $tope && ! preg_match(self::DENSE_PROTEIN, $nombre)) {
            return sprintf(
                'En «%s», «%s» (%s g) trae %s g de proteína por gramo (%s g en total): ningún alimento corriente pasa de %s. Calcula sus macros con valores de tabla según su cantidad.',
                $etiqueta, $linea['food'], $this->fmt($peso), $this->fmt2($p / $peso), $this->fmt($p), $this->fmt2($tope),
            );
        }

        if (! self::plausible($peso, $p, $ch, $g)) {
            return sprintf(
                'En «%s», «%s» (%s g) trae P %s g · C %s g · G %s g: no es verosímil para esa cantidad. Calcula los macros de cada ingrediente con valores de tabla según su cantidad.',
                $etiqueta, $linea['food'], $this->fmt($peso), $this->fmt($p), $this->fmt($ch), $this->fmt($g),
            );
        }

        return null;
    }

    /** Topes físicos de un ingrediente (con 1-2 g de margen por redondeo). */
    public static function plausible(float $peso, float $p, float $ch, float $g): bool
    {
        if ($peso <= 0) {
            return ($p + $ch + $g) <= 2;
        }

        return ($p + $ch + $g) <= $peso + 2
            && (4 * $p + 4 * $ch + 9 * $g) <= 9 * $peso + 5
            && $p <= 0.9 * $peso + 1;
    }

    /**
     * Lo que nunca puede ir en un plan (M2): fármacos, ayunos prolongados,
     * prácticas purgativas. Mencionarlo, aunque sea para desaconsejarlo,
     * también cuenta.
     */
    public function riskIssue(string $donde, string $texto): ?string
    {
        $r = $this->lexicon->riskIn($texto);

        return $r === null ? null : "En {$donde} aparece «{$r['found']}» («…{$r['snippet']}…»). No menciones fármacos, diuréticos, laxantes, esteroides, quemadores ni ayunos prolongados, ni siquiera para desaconsejarlos: reescribe ese texto sin eso.";
    }

    /**
     * Totales de un día frente a la meta, ESCALONADO (corrida 2: reparar el
     * día entero por 21 g de proteína no convergía en 10 intentos):
     *
     *  - dentro de `daily_target_tolerance` (±10 %): bien;
     *  - hasta `daily_target_repair_threshold` (±25 %): se acepta SIN reparar,
     *    con una nota honesta para ese día calculada aquí («queda ~21 g por
     *    debajo… añade una porción de proteína»); la rúbrica lo penaliza;
     *  - por encima: `issues`, y la sección se repara una vez o la generación
     *    falla.
     *
     * Solo con macros y con meta: sin meta no hay contra qué comparar, y eso
     * ya está declarado como supuesto.
     *
     * @param  array<string, array<string,mixed>>  $meals
     * @param  array{kcal: int, protein_g: int}|null  $targets
     * @return array{totals: array{kcal: int, protein_g: float, carbs_g: float, fat_g: float}|null, issues: list<string>, note: ?string}
     */
    public function dayTotals(array $meals, ?array $targets, string $dayName, ?int $minKcal = null): array
    {
        $t = $this->sum($meals);
        if ($t === null) {
            return ['totals' => null, 'issues' => [], 'note' => null];
        }

        $issues = [];
        $notas = [];
        // M2: un mínimo prudente por día, también SIN meta y en modo seguro.
        if ($minKcal !== null && $t['kcal'] < $minKcal) {
            $issues[] = sprintf('El %s suma %d kcal: por debajo del mínimo prudente de %d kcal al día. Aumenta las cantidades.', $dayName, $t['kcal'], $minKcal);
        }
        if (is_array($targets) && ($targets['kcal'] ?? 0) > 0) {
            $ok = (float) config('iron_ai.plan_powers.daily_target_tolerance', 0.10);
            $tope = (float) config('iron_ai.plan_powers.daily_target_repair_threshold', 0.25);

            if (($targets['protein_g'] ?? 0) > 0) {
                $dif = $t['protein_g'] - $targets['protein_g'];
                $desvio = abs($dif) / $targets['protein_g'];
                if ($desvio > $tope) {
                    $issues[] = sprintf('El %s suma %s g de proteína y la meta es %d g (más de ±%d %%). Ajusta las porciones de proteína.', $dayName, $this->fmt($t['protein_g']), $targets['protein_g'], (int) round($tope * 100));
                } elseif ($desvio > $ok) {
                    $g = (int) round(abs($dif));
                    $notas[] = $dif < 0
                        ? sprintf('Este día queda ~%d g por debajo de la meta de proteína: añade una porción de proteína (~%d g) en la merienda o la cena.', $g, max(5, (int) (round($g / 5) * 5)))
                        : sprintf('Este día queda ~%d g por encima de la meta de proteína: reduce un poco la porción de proteína de la cena.', $g);
                }
            }

            $dif = $t['kcal'] - $targets['kcal'];
            $desvio = abs($dif) / $targets['kcal'];
            if ($desvio > $tope) {
                $issues[] = sprintf('El %s suma %d kcal y la meta es %d kcal (más de ±%d %%). Ajusta las cantidades.', $dayName, $t['kcal'], $targets['kcal'], (int) round($tope * 100));
            } elseif ($desvio > $ok) {
                $k = (int) (round(abs($dif) / 10) * 10);
                $notas[] = $dif < 0
                    ? sprintf('Este día queda ~%d kcal por debajo de la meta: añade una porción de carbohidrato o de grasa saludable en la merienda.', $k)
                    : sprintf('Este día queda ~%d kcal por encima de la meta: reduce la porción de carbohidrato de la cena.', $k);
            }
        }

        return ['totals' => $t, 'issues' => $issues, 'note' => $issues === [] && $notas !== [] ? implode(' ', $notas) : null];
    }

    /**
     * Suma de las comidas con macros. null si falta alguna: un total con
     * huecos no es un total.
     *
     * @param  array<string, array<string,mixed>>  $meals
     * @return array{kcal: int, protein_g: float, carbs_g: float, fat_g: float}|null
     */
    public function sum(array $meals): ?array
    {
        $con = array_filter($meals, fn ($m) => is_array($m['macros'] ?? null));
        if ($con === [] || count($con) !== count($meals)) {
            return null;
        }

        $t = ['kcal' => 0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0];
        foreach ($con as $m) {
            $t['kcal'] += $m['macros']['kcal'];
            $t['protein_g'] += $m['macros']['protein_g'];
            $t['carbs_g'] += $m['macros']['carbs_g'];
            $t['fat_g'] += $m['macros']['fat_g'];
        }

        return ['kcal' => (int) $t['kcal'], 'protein_g' => round($t['protein_g'], 1), 'carbs_g' => round($t['carbs_g'], 1), 'fat_g' => round($t['fat_g'], 1)];
    }

    /**
     * Variedad: dos días tipo no pueden ser el mismo menú con otro nombre.
     * Devuelve los índices de los días que repiten a uno anterior.
     *
     * @param  array<int, array<string, array<string,mixed>>>  $days  día tipo → slot → comida
     * @return list<int>
     */
    public function repeatedDays(array $days): array
    {
        $vistos = [];
        $repetidos = [];
        foreach ($days as $i => $comidas) {
            $firma = array_map(fn ($m) => $this->lexicon->normalize((string) ($m['title'] ?? '')), $comidas);
            foreach ($vistos as $otra) {
                $iguales = count(array_intersect_assoc($firma, $otra));
                if ($firma !== [] && $iguales >= max(2, (int) ceil(0.8 * count($firma)))) {
                    $repetidos[] = $i;
                    break;
                }
            }
            $vistos[] = $firma;
        }

        return $repetidos;
    }

    // ── Rutina ──────────────────────────────────────────────────────────────

    /**
     * Un día de la rutina.
     *
     * @param  array<string,mixed>|null  $json
     * @param  array<string,mixed>  $spec  IntegralPlanStrategy::routineSpec()
     * @param  array<int, string>  $catalogNames  id → nombre, del catálogo que vio el modelo
     * @return array{issues: list<string>, data: array<string,mixed>}
     */
    public function routineDay(?array $json, array $spec, array $catalogNames, string $dayName): array
    {
        $issues = [];
        $ejercicios = [];
        $vistos = [];

        foreach ((array) ($json['exercises'] ?? []) as $e) {
            if (! is_array($e) || ! is_int($e['exercise_id'] ?? null)) {
                continue;
            }
            $id = $e['exercise_id'];
            if (! isset($catalogNames[$id])) {
                $issues[] = "En {$dayName} el ejercicio #{$id} no está en el catálogo: usa solo ids de `exercise_catalog`.";

                continue;
            }
            if (isset($vistos[$id])) {
                continue;
            }
            $vistos[$id] = true;

            $nombre = $catalogNames[$id];
            $series = is_int($e['sets'] ?? null) ? $e['sets'] : 0;
            if ($series < 1 || $series > $spec['max_sets']) {
                $issues[] = "En {$dayName}, «{$nombre}» debe tener entre 1 y {$spec['max_sets']} series.";
            }
            $reps = $this->text($e['reps'] ?? null);
            if ($reps === null) {
                $issues[] = "En {$dayName}, «{$nombre}» no trae repeticiones.";
            }
            $descanso = is_int($e['rest_seconds'] ?? null) ? $e['rest_seconds'] : 0;
            if ($descanso < 30 || $descanso > 240) {
                $issues[] = "En {$dayName}, «{$nombre}» necesita un descanso entre 30 y 240 segundos.";
            }

            $progresion = $this->text($e['progression'] ?? null);
            if ($spec['fields']['progression'] && $progresion === null) {
                $issues[] = "En {$dayName}, «{$nombre}» no trae progresión.";
            }

            $rpe = $this->text($e['rpe'] ?? null);
            if ($spec['fields']['rpe']) {
                $max = $this->rpeMax($rpe);
                if ($max === null) {
                    $issues[] = "En {$dayName}, «{$nombre}» no trae un RPE válido (de 1 a 10).";
                } elseif ($max > $spec['max_rpe']) {
                    $issues[] = "En {$dayName}, «{$nombre}» pide RPE {$rpe}; para este socio el máximo es ".$this->fmt($spec['max_rpe']).'.';
                }
            }

            $tecnica = $this->text($e['technique'] ?? null);
            if ($spec['fields']['technique'] && $tecnica === null) {
                $issues[] = "En {$dayName}, «{$nombre}» no trae la clave técnica.";
            }

            // La alternativa es una ayuda, no un requisito: si no existe o es
            // el mismo ejercicio, se quita y ya.
            $alt = $e['alternative_exercise_id'] ?? null;
            $alt = (is_int($alt) && $alt !== $id && isset($catalogNames[$alt])) ? $alt : null;

            $ejercicios[] = [
                'exercise_id' => $id,
                'name' => $nombre,
                'sets' => max(1, min($spec['max_sets'], $series ?: 3)),
                'reps' => $reps ?? '10',
                'rest_seconds' => max(30, min(240, $descanso ?: 90)),
                'rpe' => $rpe,
                'progression' => $progresion,
                'technique' => $tecnica,
                'alternative_exercise_id' => $alt,
                'alternative_name' => $alt !== null ? $catalogNames[$alt] : null,
            ];
        }

        $n = count($ejercicios);
        if ($n < $spec['min_exercises'] || $n > $spec['max_exercises']) {
            $issues[] = "{$dayName} tiene {$n} ejercicios válidos; deben ser entre {$spec['min_exercises']} y {$spec['max_exercises']}.";
        }

        $calentamiento = $this->text($json['warmup'] ?? null);
        if ($spec['fields']['warmup'] && $calentamiento === null) {
            $issues[] = "{$dayName} no trae calentamiento.";
        }

        return ['issues' => array_values(array_unique($issues)), 'data' => [
            'objective' => $this->text($json['day_objective'] ?? null),
            'warmup' => $calentamiento,
            'exercises' => $ejercicios,
        ]];
    }

    /**
     * @param  array<string,mixed>|null  $json
     * @param  array<string,mixed>  $spec
     * @return array{issues: list<string>, data: array<string,mixed>}
     */
    public function routineFrame(?array $json, array $spec): array
    {
        $issues = [];
        $data = [
            'name' => $this->text($json['name'] ?? null),
            'objective' => $this->text($json['objective'] ?? null),
            'schedule' => $this->text($json['schedule'] ?? null),
            'recovery' => $this->text($json['recovery'] ?? null),
            'progression' => $this->text($json['progression'] ?? null),
            'adjustment_criteria' => $this->text($json['adjustment_criteria'] ?? null),
            'warmup' => $this->text($json['warmup'] ?? null),
            'blocks' => [],
            'weeks' => [],
        ];

        foreach (['name' => 'el nombre', 'objective' => 'el objetivo', 'schedule' => 'la distribución semanal'] as $k => $que) {
            if ($data[$k] === null) {
                $issues[] = "Falta {$que} del programa.";
            }
        }
        if ($spec['fields']['progression'] && $data['progression'] === null) {
            $issues[] = 'Falta la progresión del programa.';
        }

        foreach ((array) ($json['blocks'] ?? []) as $b) {
            if (is_array($b) && $this->text($b['weeks'] ?? null) && $this->text($b['focus'] ?? null)) {
                $data['blocks'][] = ['weeks' => $this->text($b['weeks']), 'focus' => $this->text($b['focus'])];
            }
        }
        if ($spec['level'] === 4 && $data['blocks'] === []) {
            $issues[] = 'Faltan los bloques de progresión.';
        }

        foreach ((array) ($json['weeks'] ?? []) as $w) {
            if (is_array($w) && is_int($w['week'] ?? null) && $this->text($w['focus'] ?? null)) {
                $data['weeks'][$w['week']] = ['week' => $w['week'], 'focus' => $this->text($w['focus']), 'load' => $this->text($w['load'] ?? null)];
            }
        }
        ksort($data['weeks']);
        $data['weeks'] = array_values($data['weeks']);
        if ($spec['level'] >= 5) {
            if (array_column($data['weeks'], 'week') !== [1, 2, 3, 4]) {
                $issues[] = 'El programa debe traer exactamente las semanas 1, 2, 3 y 4.';
            }
            if ($data['adjustment_criteria'] === null) {
                $issues[] = 'Faltan los criterios de ajuste.';
            }
        }

        return ['issues' => $issues, 'data' => $data];
    }

    /**
     * El aviso de un texto que nombra algo prohibido, listo para devolvérselo
     * al modelo: TODAS las palabras que chocan, dónde están y la orden de no
     * nombrarlas, ni siquiera para decir que se evitan («en lugar de res»).
     * Corrida 1: con solo «menciona res» el modelo no supo qué quitar.
     *
     * @param  list<string>  $keys
     */
    public function conflictIssue(string $donde, string $texto, array $keys): ?string
    {
        $choques = $this->lexicon->conflicts($texto, $keys);
        if ($choques === []) {
            return null;
        }

        $palabras = implode(', ', array_map(fn ($c) => '«'.$c['found'].'»', $choques));
        $etiquetas = implode(', ', array_unique(array_map(fn ($c) => '«'.$c['label'].'»', $choques)));

        return "En {$donde} aparece {$palabras} («…{$choques[0]['snippet']}…»), que choca con {$etiquetas}. "
            .'Reescribe ese texto SIN nombrar esos alimentos ni ningún otro prohibido, tampoco para decir que se evita o se sustituye: nombra solo lo que sí se come.';
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    /** El tope de un RPE escrito como «7», «7-8» o «7,5». Fuera de 1..10, null. */
    public function rpeMax(?string $rpe): ?float
    {
        if ($rpe === null || ! preg_match_all('/\d+(?:[.,]\d+)?/', $rpe, $m)) {
            return null;
        }
        $nums = array_map(fn ($n) => (float) str_replace(',', '.', $n), $m[0]);
        $max = max($nums);

        return ($max >= 1 && $max <= 10) ? $max : null;
    }

    private function text(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $s = trim($v);

        return $s === '' ? null : $s;
    }

    private function num(mixed $v): ?float
    {
        return is_numeric($v) && (float) $v >= 0 ? (float) $v : null;
    }

    private function fmt2(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, ',', ''), '0'), ',');
    }
}
