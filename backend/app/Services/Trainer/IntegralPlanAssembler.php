<?php

namespace App\Services\Trainer;

/**
 * Del plan estructurado a lo que la app publicada sabe pintar
 * (docs/iron-ia/POTENCIAS-IRON-IA.md §5).
 *
 * La app 2.0.9+30 no cambia: pinta una lista de comidas con `label`, `time` y
 * `description`, unos bloques de texto plano debajo, y en la rutina días ×
 * ejercicios con series, repeticiones, `notes` y el objetivo del día. Todo lo
 * que un nivel alto añade —ingredientes, preparación, macros, totales, lista
 * de mercado, descanso, RPE, progresión— se escribe aquí como TEXTO en esos
 * mismos campos, con sus mismos tipos.
 *
 * Los números los pone PHP, no el modelo: los totales del día y la lista de
 * mercado se suman aquí a partir de las comidas, así que cuadran por
 * construcción.
 *
 * NADA SE CORTA A LA MITAD. Cada texto se compone por piezas en orden de
 * prioridad; si una pieza no cabe en el límite del campo, se omite entera.
 */
class IntegralPlanAssembler
{
    /** Orden de la lista: el primero que case manda («aceite de coco» es grasa, no fruta). */
    private const SHOPPING_CATEGORIES = [
        'Proteínas' => ['pollo', 'pechuga', 'res', 'carne', 'cerdo', 'pescado', 'tilapia', 'merluza', 'atun', 'salmon', 'pavo', 'huevo', 'clara', 'tofu', 'lomo', 'muslo', 'trucha', 'mojarra'],
        'Lácteos y alternativas' => ['leche', 'queso', 'yogur', 'kumis', 'kefir', 'cuajada', 'bebida'],
        'Legumbres' => ['lenteja', 'frijol', 'garbanzo', 'arveja'],
        'Cereales, tubérculos y harinas' => ['arroz', 'papa', 'yuca', 'platano', 'arepa', 'pasta', 'quinua', 'avena', 'pan', 'mazorca', 'maiz', 'tortilla', 'granola', 'arracacha', 'name'],
        'Grasas' => ['aceite', 'aguacate', 'mantequilla'],
        'Frutos secos y semillas' => ['almendra', 'nuez', 'nueces', 'mani', 'maranon', 'pistacho', 'avellana', 'macadamia', 'pecana', 'semilla', 'chia', 'linaza', 'ajonjoli'],
        'Frutas' => ['banano', 'manzana', 'pera', 'fresa', 'mango', 'papaya', 'pina', 'naranja', 'mandarina', 'guayaba', 'mora', 'uva', 'melon', 'sandia', 'kiwi', 'fruta', 'lulo', 'maracuya', 'limon', 'granadilla', 'pitahaya', 'uchuva', 'tomate de arbol', 'durazno', 'ciruela', 'arandano', 'coco', 'feijoa', 'curuba'],
        'Verduras' => ['tomate', 'cebolla', 'zanahoria', 'lechuga', 'espinaca', 'brocoli', 'pepino', 'pimenton', 'ahuyama', 'calabacin', 'habichuela', 'repollo', 'champinon', 'verdura', 'ensalada', 'cilantro', 'ajo', 'apio', 'remolacha'],
    ];

    /**
     * Sinónimos MÍNIMOS para la lista de mercado (clave normalizada → nombre).
     * El plátano NO es el banano: en Colombia son dos compras distintas.
     */
    private const SHOPPING_SYNONYMS = [
        'banana' => 'banano', 'pimiento' => 'pimentón', 'miel de abeja' => 'miel', 'avena en hojuela' => 'avena',
        // «miel de abejas», «avena en hojuelas»: el plural va en la última palabra.
        'miel de abejas' => 'miel', 'avena en hojuelas' => 'avena',
    ];

    /** Lo que no se compra. */
    private const SHOPPING_EXCLUDED = ['agua', 'agua tibia', 'agua caliente', 'agua fria', 'hielo'];

    public function __construct(private readonly PlanSafetyLexicon $lexicon) {}

    // ── Nutrición ───────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $plan  IntegralPlanStrategy::plan()
     * @param  array<string,mixed>  $brief  MemberContextAssembler::planBrief()
     * @param  array<string,mixed>  $core  sección `nutrition_core` normalizada
     * @param  array<int, array<string, array<string,mixed>>>  $dayTypes  día tipo → slot → comida
     * @param  array<int, array<string,mixed>|null>  $totals  día tipo → totales
     * @param  array<int, string|null>  $notes  día tipo → nota de ajuste (desvío del 10-25 %)
     * @return array{nutrition: array<string,mixed>, structured: array<string,mixed>}
     */
    public function nutrition(array $plan, array $brief, array $core, array $dayTypes, array $totals, array $notes = []): array
    {
        $spec = $plan['nutrition'];
        $slots = $plan['slots'];
        $rotacion = $plan['rotation'];
        $limite = IntegralPlanValidator::EXTENDED_LIMITS;

        // Un día tipo (nivel 2): las comidas sin día, como hoy. Con rotación o
        // semana: una entrada por comida Y día, «Lunes · Desayuno», en orden.
        $comidas = [];
        $semana = [];
        $dias = $spec['day_types'] === 1 ? [0] : range(0, 6);
        foreach ($dias as $d) {
            $tipo = $spec['day_types'] === 1 ? 0 : $rotacion[$d];
            $nombreDia = $spec['day_types'] === 1 ? null : IntegralPlanStrategy::WEEKDAYS[$d];
            $semana[] = [
                'day' => $nombreDia ?? 'Día tipo',
                'day_type' => $tipo,
                'meals' => array_values($dayTypes[$tipo] ?? []),
                'totals' => $totals[$tipo] ?? null,
                'note' => $notes[$tipo] ?? null,
            ];

            foreach ($slots as $slot => [$etiqueta, $hora]) {
                $c = $dayTypes[$tipo][$slot] ?? null;
                if ($c === null) {
                    continue;
                }
                $comidas[] = [
                    'label' => $nombreDia ? "{$nombreDia} · {$etiqueta}" : $etiqueta,
                    'time' => $hora,
                    'description' => $this->mealText($c, $spec, $limite['meal_description']),
                    'order' => count($comidas),
                ];
            }
        }

        $mercado = $spec['level'] >= 4 ? $this->shoppingList($semana) : [];

        $nutrition = array_filter([
            'objective' => $core['objective'] ?? null,
            'objective_description' => $core['objective_description'] ?? null,
            'training_stage' => $core['training_stage'] ?? null,
            'meals' => $comidas,
            'recommendations' => $this->compose($this->recommendationBlocks($plan, $brief, $core, $semana), $limite['recommendations']),
            'restrictions' => $this->compose($this->restrictionBlocks($brief), $limite['restrictions']),
            'supplements' => $this->supplements($brief, $core),
            'notes' => $mercado !== [] ? $this->compose($this->shoppingBlocks($mercado), $limite['notes']) : null,
        ], fn ($v) => $v !== null && $v !== '');

        return ['nutrition' => $nutrition, 'structured' => [
            'week' => $semana,
            'rotation' => $spec['day_types'] === 3 ? $rotacion : null,
            'shopping_list' => $mercado,
            'targets' => $brief['targets'],
        ]];
    }

    /**
     * El BALANCE de porciones de un día (corrida 4): el modelo elige platos e
     * ingredientes, dice el rol de cada uno y sus macros por la cantidad que
     * escribió; PHP saca de ahí la tasa por gramo (o por unidad) de cada
     * ingrediente y escala, en orden, los de rol `protein`, luego `carb` y
     * luego `fat`, para acercar P, C y G a la meta del día. Lo que no tiene
     * rol que escalar (`other`, «al gusto») se queda como está.
     *
     * Límites realistas: cada ingrediente entre ×0,6 y ×1,8 de lo que propuso
     * el modelo (configurable), y redondeo práctico: 5 g (10 g desde 100),
     * piezas, cucharadas y tazas de media en media. Las macros se recalculan
     * con la tasa del propio modelo, así que siguen siendo SUS estimaciones,
     * solo que para otra cantidad. Lo que quede fuera de tolerancia lo dice
     * la nota del día; esto no lo esconde.
     *
     * @param  array<string, array<string,mixed>>  $meals  momento → comida (con items con rol y macros)
     * @param  array{protein_g: int|float, carbs_g: int|float, fat_g: int|float}  $targets
     * @return array{meals: array<string, array<string,mixed>>, factors: array{protein: ?float, carb: ?float, fat: ?float}}
     */
    public function balanceDay(array $meals, array $targets): array
    {
        $min = (float) config('iron_ai.plan_powers.balance_min_factor', 0.6);
        $max = (float) config('iron_ai.plan_powers.balance_max_factor', 1.8);
        $factores = ['protein' => null, 'carb' => null, 'fat' => null];

        foreach (['protein' => 'protein_g', 'carb' => 'carbs_g', 'fat' => 'fat_g'] as $rol => $macro) {
            $delRol = 0.0;
            $resto = 0.0;
            foreach ($meals as $m) {
                foreach ($m['items'] as $it) {
                    if ($this->scalable($it) && ($it['role'] ?? 'other') === $rol) {
                        $delRol += (float) $it[$macro];
                    } else {
                        $resto += (float) ($it[$macro] ?? 0);
                    }
                }
            }
            if ($delRol <= 0 || ! isset($targets[$macro])) {
                continue;
            }
            $f = max($min, min($max, ((float) $targets[$macro] - $resto) / $delRol));
            $factores[$rol] = round($f, 3);

            foreach ($meals as $slot => $m) {
                foreach ($m['items'] as $i => $it) {
                    if ($this->scalable($it) && ($it['role'] ?? 'other') === $rol) {
                        $meals[$slot]['items'][$i] = $this->scaleItem($it, $f);
                    }
                }
            }
        }

        foreach ($meals as $slot => $m) {
            $p = round(array_sum(array_column($m['items'], 'protein_g')), 1);
            $c = round(array_sum(array_column($m['items'], 'carbs_g')), 1);
            $g = round(array_sum(array_column($m['items'], 'fat_g')), 1);
            $meals[$slot]['macros'] = ['kcal' => (int) round(4 * $p + 4 * $c + 9 * $g), 'protein_g' => $p, 'carbs_g' => $c, 'fat_g' => $g];
        }

        return ['meals' => $meals, 'factors' => $factores];
    }

    private function scalable(array $it): bool
    {
        return ($it['unit'] ?? 'g') !== 'al_gusto' && (float) ($it['amount'] ?? 0) > 0 && isset($it['protein_g']);
    }

    /** Un ingrediente con su cantidad escalada y redondeada, y sus macros a la tasa del modelo. */
    private function scaleItem(array $it, float $f): array
    {
        $antes = (float) $it['amount'];
        $nueva = $antes * $f;
        $nueva = match ($it['unit']) {
            'g', 'ml' => max(5.0, round($nueva / ($nueva >= 100 ? 10 : 5)) * ($nueva >= 100 ? 10 : 5)),
            default => max(0.5, round($nueva * 2) / 2),
        };
        $real = $nueva / $antes;

        foreach (['protein_g', 'carbs_g', 'fat_g'] as $k) {
            $it[$k] = round((float) $it[$k] * $real, 1);
        }
        foreach (['grams', 'cooked_g'] as $k) {
            if (isset($it[$k]) && $it[$k] !== null) {
                $it[$k] = round((float) $it[$k] * $real, 1);
            }
        }
        $it['amount'] = $nueva;

        return $it;
    }

    /**
     * Lista de mercado de la SEMANA, sumada en PHP desde las comidas.
     *
     * Mismo alimento y misma unidad se suman; «al gusto» se lista sin cifra.
     * El nombre se normaliza (tildes, mayúsculas y plurales simples) para que
     * «Huevos» y «huevo» sean una sola línea.
     *
     * @param  list<array<string,mixed>>  $semana
     * @return list<array{food: string, amount: float, unit: string, category: string}>
     */
    public function shoppingList(array $semana): array
    {
        $acum = [];
        foreach ($semana as $dia) {
            foreach ($dia['meals'] as $comida) {
                foreach ($comida['items'] as $it) {
                    $linea = $this->shoppingLine($it);
                    if ($linea === null) {
                        continue;
                    }
                    $acum[$linea['key']] ??= ['food' => $linea['food'], 'amount' => 0.0, 'unit' => $linea['unit'], 'category' => $this->category($linea['food'])];
                    $acum[$linea['key']]['amount'] += $linea['amount'];
                }
            }
        }

        $orden = array_flip(array_merge(array_keys(self::SHOPPING_CATEGORIES), ['Otros']));
        $lista = array_values($acum);
        usort($lista, fn ($a, $b) => [$orden[$a['category']], $a['unit'] === 'al_gusto', $a['food']] <=> [$orden[$b['category']], $b['unit'] === 'al_gusto', $b['food']]);

        return array_map(fn ($l) => ['food' => $l['food'], 'amount' => round($l['amount'], 1), 'unit' => $l['unit'], 'category' => $l['category']], $lista);
    }

    /**
     * Un ingrediente tal como entra en la lista: con su nombre de compra
     * (sinónimos mínimos), su unidad de compra donde la conversión es clara
     * y su clave de consolidación. null si no se compra (agua).
     *
     * Conversiones: el aceite se compra en ml (1 cucharada = 15 ml, 1 taza =
     * 240 ml, 1 g = 1/0,92 ml) y el huevo en unidades (≈ 50 g cada uno). Nada
     * más: convertir arroz cocido a crudo, por ejemplo, ya no es «claro».
     *
     * @param  array<string,mixed>  $it
     * @return array{key: string, food: string, amount: float, unit: string}|null
     */
    public function shoppingLine(array $it): ?array
    {
        $food = $this->synonym((string) $it['food']);
        $clave = $this->foodKey($food);
        if (in_array($clave, self::SHOPPING_EXCLUDED, true)) {
            return null;
        }

        $unidad = (string) $it['unit'];
        $cantidad = (float) $it['amount'];
        if (str_starts_with($clave, 'aceite')) {
            [$cantidad, $unidad] = match ($unidad) {
                'cucharada' => [$cantidad * 15, 'ml'],
                'taza' => [$cantidad * 240, 'ml'],
                'g' => [$cantidad / 0.92, 'ml'],
                default => [$cantidad, $unidad],
            };
        }
        if ($clave === 'huevo' && $unidad === 'g') {
            [$cantidad, $unidad] = [$cantidad / 50, 'unidad'];
        }

        return ['key' => $clave.'|'.$unidad, 'food' => $this->displayName($food), 'amount' => $cantidad, 'unit' => $unidad];
    }

    /** La clave de una línea YA consolidada (para comprobar que cubre un ingrediente). */
    public function shoppingKey(string $food, string $unit): string
    {
        return $this->foodKey($this->synonym($food)).'|'.$unit;
    }

    /** El nombre de compra: cada sinónimo, como palabra completa (y su plural), por su nombre. */
    private function synonym(string $food): string
    {
        $out = $food;
        foreach (self::SHOPPING_SYNONYMS as $de => $a) {
            $out = (string) preg_replace('/(?<!\p{L})'.preg_quote($de, '/').'(?:s|es)?(?!\p{L})/iu', $a, $out);
        }

        return $out;
    }

    /** @param  array<string,mixed>  $c @param  array<string,mixed>  $spec */
    private function mealText(array $c, array $spec, int $max): string
    {
        $ingredientes = implode('; ', array_map(fn ($it) => $this->itemText($it, $spec['detail']['cooked']), $c['items']));
        $piezas = [
            (string) ($c['title'] ?? ''),
            $ingredientes !== '' ? 'Ingredientes: '.$ingredientes.'.' : null,
        ];
        if (is_array($c['macros'] ?? null)) {
            $m = $c['macros'];
            $piezas[] = sprintf('Aporte estimado: %s kcal · proteína %s g · carbohidratos %s g · grasa %s g.',
                $this->int($m['kcal']), $this->n($m['protein_g']), $this->n($m['carbs_g']), $this->n($m['fat_g']));
        }
        if ($spec['detail']['preparation'] && $c['preparation']) {
            $piezas[] = 'Preparación: '.$this->sentence($c['preparation']);
        }
        if ($c['substitutions']) {
            $piezas[] = 'Sustitución: '.$this->sentence($c['substitutions']);
        }

        return $this->compose(array_filter($piezas), $max, "\n");
    }

    /** @param  array<string,mixed>  $it */
    private function itemText(array $it, bool $conCocido): string
    {
        if ($it['unit'] === 'al_gusto') {
            return $it['food'].' al gusto';
        }

        $txt = $it['food'].' '.$this->amount($it['amount'], $it['unit']);
        if ($conCocido && in_array($it['unit'], ['g'], true)) {
            $txt .= $it['cooked_g'] !== null
                ? ' en crudo (≈ '.$this->int($it['cooked_g']).' g cocido)'
                : '';
        }

        return $txt;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $brief
     * @param  array<string,mixed>  $core
     * @param  list<array<string,mixed>>  $semana
     * @return list<string>
     */
    private function recommendationBlocks(array $plan, array $brief, array $core, array $semana): array
    {
        $bloques = [];

        if ($brief['safety_mode']) {
            $bloques[] = "AVISO\nPlan general orientativo. Por "
                .mb_strtolower(implode(', ', array_column($brief['clinical_signals'], 'label')))
                .', esta guía no prescribe calorías ni restricciones: debe revisarla un profesional de la salud (médico o nutricionista) antes de seguirla.';
        }

        $metas = $brief['targets'];
        $bloques[] = "METAS DEL DÍA\n".(is_array($metas)
            ? sprintf('%s kcal · proteína %s g · carbohidratos %s g · grasa %s g (%s).',
                $this->int($metas['kcal']), $this->int($metas['protein_g']), $this->int($metas['carbs_g']), $this->int($metas['fat_g']),
                $metas['source'] === 'nutrition_goal' ? 'meta nutricional guardada del socio' : 'estimación de la calculadora del gimnasio')
            : 'Sin meta de calorías registrada: las cantidades son porciones orientativas.');

        if ($brief['assumptions'] !== []) {
            $bloques[] = "SUPUESTOS\n- ".implode("\n- ", $brief['assumptions']);
        }
        if ($core['hydration'] ?? null) {
            $bloques[] = "HIDRATACIÓN\n".$core['hydration'];
        }

        $org = (string) ($core['organization'] ?? '');
        if (($plan['nutrition']['day_types'] ?? 1) === 3) {
            $porTipo = [];
            foreach ($plan['rotation'] as $d => $t) {
                $porTipo[$t][] = IntegralPlanStrategy::WEEKDAYS[$d];
            }
            $rot = [];
            foreach ($porTipo as $t => $diasTipo) {
                $rot[] = 'Día '.chr(ord('A') + $t).': '.implode(', ', $diasTipo);
            }
            $org = trim($org."\nRotación de la semana: ".implode('; ', $rot).'.');
        }
        if ($org !== '') {
            $bloques[] = "ORGANIZACIÓN\n".$org;
        }

        if ($core['general_substitutions'] ?? null) {
            $bloques[] = "SUSTITUCIONES\n".$core['general_substitutions'];
        }

        $lineas = [];
        foreach ($semana as $dia) {
            $t = $dia['totals'];
            if (is_array($t)) {
                $lineas[] = sprintf('%s: %s kcal · P %s g · C %s g · G %s g', $dia['day'], $this->int($t['kcal']), $this->n($t['protein_g']), $this->n($t['carbs_g']), $this->n($t['fat_g']))
                    .($dia['note'] ? ' — '.$dia['note'] : '');
            }
        }
        if ($lineas !== []) {
            $bloques[] = "TOTALES ESTIMADOS POR DÍA (suma de las comidas)\n".implode("\n", $lineas);
        }

        return $bloques;
    }

    /** @param  array<string,mixed>  $brief @return list<string> */
    private function restrictionBlocks(array $brief): array
    {
        $bloques = [];
        $etiquetas = array_column($brief['restrictions'], 'label');
        $bloques[] = $etiquetas !== []
            ? 'Restricciones registradas: '.implode('; ', $etiquetas).'.'
            : 'No constan restricciones alimentarias registradas.';

        $declarado = trim(implode(' ', $brief['restrictions_text'] ?? []));
        if ($declarado !== '') {
            $bloques[] = 'Según lo registrado: '.$this->sentence($declarado);
        }
        $bloques[] = 'Alergias: no registradas. Confirmar con el socio antes de entregar el plan.';

        return $bloques;
    }

    /** @param  array<string,mixed>  $brief @param  array<string,mixed>  $core */
    private function supplements(array $brief, array $core): string
    {
        if ($brief['safety_mode'] || $brief['is_minor']) {
            return 'No se recomiendan suplementos sin valoración de un profesional de la salud.';
        }

        return (string) ($core['supplements'] ?? '') ?: 'No se recomiendan suplementos sin valoración profesional.';
    }

    /**
     * @param  list<array{food: string, amount: float, unit: string, category: string}>  $mercado
     * @return list<string>
     */
    private function shoppingBlocks(array $mercado): array
    {
        $porCategoria = [];
        foreach ($mercado as $l) {
            $porCategoria[$l['category']][] = $l['unit'] === 'al_gusto'
                ? $l['food']
                : $l['food'].' '.$this->amount($l['amount'], $l['unit'], true);
        }

        $bloques = ["LISTA DE MERCADO (7 días)\nCantidades en crudo, sumadas de todas las comidas de la semana."];
        foreach ($porCategoria as $cat => $items) {
            $bloques[] = $cat.': '.implode('; ', $items).'.';
        }

        return $bloques;
    }

    /**
     * El aviso clínico de un plan de nivel 1 (o de su mitad de nivel 1 en los
     * niveles mezclados), como POSPROCESO: no cambia el prompt. Sin señales,
     * el plan sale intacto. Con alguna se retiran los suplementos, se antepone
     * el aviso de revisión profesional en `recommendations` y `notes` y
     * —seguridad B6— también en la descripción de la rutina. Mismos campos y
     * tipos.
     *
     * @param  array{nutrition?: array<string,mixed>, routine?: array<string,mixed>}  $plan
     * @param  list<array{key: string, label: string}>  $signals
     * @return array<string,mixed>
     */
    public function clinicalNotice(array $plan, array $signals): array
    {
        if ($signals === []) {
            return $plan;
        }
        $motivo = mb_strtolower(implode(', ', array_column($signals, 'label')));

        if (isset($plan['nutrition'])) {
            $n = $plan['nutrition'];
            $n['supplements'] = 'No se recomiendan suplementos sin valoración de un profesional de la salud.';
            $n['recommendations'] = $this->prependNotice(
                "AVISO\nPlan general orientativo. Por {$motivo}, esta guía debe revisarla un profesional de la salud (médico o nutricionista) antes de seguirla.",
                $n['recommendations'] ?? null,
            );
            $n['notes'] = $this->prependNotice("Revisión profesional obligatoria antes de entregar este plan ({$motivo}).", $n['notes'] ?? null);
            $plan['nutrition'] = $n;
        }

        if (isset($plan['routine']) && ! str_starts_with((string) ($plan['routine']['description'] ?? ''), 'Aviso:')) {
            $plan['routine']['description'] = $this->prependNotice(
                'Aviso: plan general de intensidad moderada. Debe revisarlo un profesional de la salud antes de empezar.',
                $plan['routine']['description'] ?? null,
                2000,
            );
        }

        return $plan;
    }

    /** El aviso delante; lo del modelo, detrás y sin partirlo, dentro del límite (4000 en el nivel 1). */
    private function prependNotice(string $aviso, ?string $texto, int $max = 4000): string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? $aviso : $aviso."\n\n".IntegralPlanValidator::clip($texto, max(1, $max - mb_strlen($aviso) - 2));
    }

    // ── Rutina ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $brief
     * @param  array<string,mixed>  $frame  sección `routine_frame` normalizada
     * @param  list<array<string,mixed>>  $days  secciones `routine_day_*` normalizadas, en orden
     * @return array<string,mixed>
     */
    public function routine(array $plan, array $brief, array $frame, array $days): array
    {
        $spec = $plan['routine'];
        $limite = IntegralPlanValidator::EXTENDED_LIMITS;

        $dias = [];
        foreach ($plan['routine_days'] as $i => $planDia) {
            $d = $days[$i];
            $objetivo = array_filter([
                'Enfoque: '.$this->sentence($d['objective'] ?? $planDia['muscles']),
                $spec['fields']['warmup'] && ($d['warmup'] ?? null) ? 'Calentamiento: '.$this->sentence($d['warmup']) : null,
            ]);

            $dias[] = [
                'name' => $planDia['name'],
                'objective' => $this->compose($objetivo, $limite['day_objective'], ' '),
                'exercises' => array_map(fn ($e) => [
                    'exercise_id' => $e['exercise_id'],
                    'sets' => $e['sets'],
                    'reps' => $e['reps'],
                    'rest_seconds' => $e['rest_seconds'],
                    'notes' => $this->exerciseNotes($e, $spec),
                ], $d['exercises']),
            ];
        }

        return [
            'name' => $frame['name'],
            'objective' => $frame['objective'],
            'level' => $brief['training_level'],
            'description' => $this->compose($this->programBlocks($spec, $brief, $frame), $limite['routine_description']),
            'days' => $dias,
        ];
    }

    /**
     * Las notas del ejercicio, que la app enseña enteras. El descanso va
     * aquí porque la app usa 90 s fijos en el entrenamiento activo. Tope de
     * 500: es lo que admite el editor del borrador al guardarlo.
     *
     * @param  array<string,mixed>  $e
     * @param  array<string,mixed>  $spec
     */
    private function exerciseNotes(array $e, array $spec): string
    {
        // Por prioridad: lo OBLIGATORIO del nivel (descanso, RPE, progresión)
        // va primero; lo que no quepa después (técnica, alternativa) se omite
        // entero. Antes una técnica larga podía dejar fuera la progresión.
        $obligatorias = ['Descanso '.$e['rest_seconds'].' s'];
        if ($spec['fields']['rpe'] && $e['rpe']) {
            $obligatorias[] = 'RPE '.$e['rpe'];
        }
        if ($spec['fields']['progression'] && $e['progression']) {
            $obligatorias[] = 'Progresión: '.$this->sentence($e['progression'], false);
        }
        $opcionales = [];
        if ($spec['fields']['technique'] && $e['technique']) {
            $opcionales[] = 'Técnica: '.$this->sentence($e['technique'], false);
        }
        if ($e['alternative_name']) {
            $opcionales[] = 'Alternativa: '.$e['alternative_name'];
        }

        $base = IntegralPlanValidator::clip(implode(' · ', $obligatorias), 500);

        return $this->compose(array_merge([$base], $opcionales), 500, ' · ');
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $brief
     * @param  array<string,mixed>  $frame
     * @return list<string>
     */
    private function programBlocks(array $spec, array $brief, array $frame): array
    {
        $b = [];
        if ($brief['safety_mode']) {
            $b[] = 'Aviso: plan general de intensidad moderada. Debe revisarlo un profesional de la salud antes de empezar.';
        }
        if ($frame['schedule']) {
            $b[] = 'Distribución semanal: '.$this->sentence($frame['schedule']);
        }
        if ($spec['fields']['warmup'] && $frame['warmup']) {
            $b[] = 'Calentamiento general: '.$this->sentence($frame['warmup']);
        }
        if ($spec['fields']['progression'] && $frame['progression']) {
            $b[] = 'Progresión: '.$this->sentence($frame['progression']);
        }
        foreach ($frame['blocks'] as $bl) {
            $b[] = $bl['weeks'].': '.$this->sentence($bl['focus']);
        }
        foreach ($frame['weeks'] as $w) {
            $b[] = 'Semana '.$w['week'].': '.$this->sentence(rtrim($w['focus'], '. ').($w['load'] ? '. '.$w['load'] : ''));
        }
        if ($frame['adjustment_criteria']) {
            $b[] = 'Criterios de ajuste: '.$this->sentence($frame['adjustment_criteria']);
        }
        if ($frame['recovery']) {
            $b[] = 'Recuperación: '.$this->sentence($frame['recovery']);
        }
        $b[] = 'El descanso, el esfuerzo (RPE) y la progresión de cada ejercicio están en sus notas.';

        return $b;
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    /**
     * Une piezas sin pasarse del límite. Una pieza que no cabe se OMITE entera
     * —nunca se corta—, y las demás siguen su orden de prioridad.
     *
     * @param  array<int, string|null>  $piezas
     */
    public function compose(array $piezas, int $max, string $sep = "\n\n"): string
    {
        $out = '';
        foreach ($piezas as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            $candidato = $out === '' ? $p : $out.$sep.$p;
            if (mb_strlen($candidato) <= $max) {
                $out = $candidato;
            }
        }

        // Dos puntos seguidos son un error de unión («Técnica.. RPE»); los
        // puntos suspensivos («...», «…») se respetan.
        return (string) preg_replace('/(?<!\.)\.\.(?!\.)/u', '.', $out);
    }

    private function sentence(string $s, bool $punto = true): string
    {
        $s = trim($s);
        if (! $punto) {
            return rtrim($s, '.');
        }

        return preg_match('/[.!?…]$/u', $s) ? $s : $s.'.';
    }

    private function amount(float $n, string $unit, bool $compra = false): string
    {
        return match ($unit) {
            'g' => $compra && $n >= 1000 ? $this->n($n / 1000).' kg' : $this->int($n).' g',
            'ml' => $compra && $n >= 1000 ? $this->n($n / 1000).' l' : $this->int($n).' ml',
            'unidad' => $this->n($n).' '.($n == 1.0 ? 'unidad' : 'unidades'),
            'cucharada' => $this->n($n).' '.($n == 1.0 ? 'cucharada' : 'cucharadas'),
            'taza' => $this->n($n).' '.($n == 1.0 ? 'taza' : 'tazas'),
            default => $this->n($n).' '.$unit,
        };
    }

    private function int(float|int $n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }

    private function n(float|int $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 1, ',', '.'), '0'), ',');
    }

    /**
     * «Huevos» y «huevo», «Fríjoles rojos secos» y «fríjol rojo seco»: una
     * sola clave. Pública porque la rúbrica tiene que comparar con la MISMA
     * clave con la que se consolidó (corrida 2: comparaba con otra y daba por
     * fuera de la lista un ingrediente que sí estaba).
     */
    public function foodKey(string $food): string
    {
        $t = $this->lexicon->normalize((string) preg_replace('/\(.*?\)/u', '', $food));
        $palabras = array_map(function (string $w): string {
            if (mb_strlen($w) > 4 && preg_match('/[lnr]es$/', $w)) {
                return mb_substr($w, 0, -2);
            }
            if (mb_strlen($w) > 3 && str_ends_with($w, 's')) {
                return mb_substr($w, 0, -1);
            }

            return $w;
        }, explode(' ', $t));

        return implode(' ', $palabras);
    }

    private function displayName(string $food): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $food));

        return mb_strtoupper(mb_substr($s, 0, 1)).mb_substr($s, 1);
    }

    private function category(string $food): string
    {
        $t = $this->lexicon->normalize($food);
        foreach (self::SHOPPING_CATEGORIES as $cat => $claves) {
            foreach ($claves as $k) {
                // Palabra entera o su plural: «papa» no es «papaya», ni «pan»
                // es «panela».
                if (preg_match('/(?<![a-z])'.preg_quote($k, '/').'(s|es)?(?![a-z])/', $t)) {
                    return $cat;
                }
            }
        }

        return 'Otros';
    }
}
