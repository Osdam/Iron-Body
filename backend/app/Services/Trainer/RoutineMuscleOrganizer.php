<?php

namespace App\Services\Trainer;

/**
 * Organización muscular de las rutinas de Iron IA: EL ÚNICO sitio donde viven
 * el reparto por días, la clasificación de los ejercicios por grupo muscular y
 * la comprobación posterior de lo que devuelve el modelo.
 *
 * Por qué es una pieza aparte (13-11-2026). El reparto y la clasificación
 * vivían dentro de {@see IntegralPlanStrategy} y solo los usaban los niveles
 * 2–5. El nivel 1 pedía «entre 2 y 6 días» sin reparto, y en producción 25 de
 * 58 rutinas de nivel 1 tenían algún día con menos del 40 % de ejercicios de
 * los grupos que le tocaban (sonda de solo lectura del 13-11; las 14 de los
 * niveles 2–5, ≥ 80 % en todos los días). Para no tener dos sistemas, el
 * nivel 1 y los niveles 2–5 piden y comprueban con estas mismas tablas.
 *
 * Quien añada otra regla de organización muscular la añade aquí.
 * docs/iron-ia/ORGANIZACION-MUSCULAR.md
 */
class RoutineMuscleOrganizer
{
    /**
     * Parte mínima de ejercicios de un día que debe pertenecer a sus grupos.
     * Con 5 ejercicios admite 2 accesorios de otro grupo. Calibrado con las
     * rutinas reales: las de los niveles 2–5 están todas en 0,8 o más.
     */
    public const MIN_DAY_SHARE = 0.6;

    /**
     * Grupo de un ejercicio del catálogo según su `muscle_group` (ES) y su
     * `target` (EN, del proveedor). Ver ExerciseDbService::BODYPART_ES.
     */
    private const MUSCLE_TAGS = [
        'legs' => ['piernas', 'pierna', 'cuadriceps', 'gluteo', 'gluteos', 'femoral', 'femorales', 'isquio', 'aductor', 'aductores', 'abductor', 'abductores', 'cadera', 'quads', 'glutes', 'hamstrings', 'adductors', 'abductors', 'upper legs'],
        'calves' => ['pantorrilla', 'pantorrillas', 'gemelo', 'gemelos', 'calves', 'lower legs'],
        'chest' => ['pecho', 'pectoral', 'pectorales', 'chest', 'pectorals'],
        'back' => ['espalda', 'dorsal', 'dorsales', 'trapecio', 'lats', 'upper back', 'traps', 'back'],
        'shoulders' => ['hombro', 'hombros', 'deltoide', 'deltoides', 'shoulders', 'delts'],
        'biceps' => ['biceps'],
        'triceps' => ['triceps'],
        'arms' => ['brazo', 'brazos', 'antebrazo', 'antebrazos', 'upper arms', 'lower arms', 'forearms'],
        'core' => ['core', 'abdomen', 'abdominal', 'abdominales', 'abs', 'waist', 'oblicuo', 'oblicuos', 'lumbar'],
        'cardio' => ['cardio', 'cardiovascular system'],
    ];

    /** División de la rutina por días disponibles: [enfoque, grupos, grupos del catálogo]. */
    private const SPLITS = [
        1 => [['Cuerpo completo', 'piernas, empuje, tracción y core', ['legs', 'chest', 'back', 'shoulders', 'core']]],
        2 => [['Cuerpo completo A', 'piernas, empuje y core', ['legs', 'chest', 'shoulders', 'core']], ['Cuerpo completo B', 'cadera, tracción y core', ['legs', 'back', 'biceps', 'core']]],
        3 => [['Empuje', 'pecho, hombro y tríceps', ['chest', 'shoulders', 'triceps']], ['Pierna y core', 'cuádriceps, glúteo, femoral y core', ['legs', 'calves', 'core']], ['Tracción', 'espalda, bíceps y core', ['back', 'biceps', 'core']]],
        4 => [['Torso A', 'pecho, espalda y hombro', ['chest', 'back', 'shoulders']], ['Pierna A', 'cuádriceps, glúteo y core', ['legs', 'core']], ['Torso B', 'espalda, pecho y brazos', ['back', 'chest', 'biceps', 'triceps', 'arms']], ['Pierna B', 'femoral, glúteo y pantorrilla', ['legs', 'calves', 'core']]],
        5 => [['Empuje', 'pecho, hombro y tríceps', ['chest', 'shoulders', 'triceps']], ['Tracción', 'espalda y bíceps', ['back', 'biceps']], ['Pierna', 'cuádriceps, glúteo y femoral', ['legs', 'calves']], ['Torso', 'pecho, espalda y hombro', ['chest', 'back', 'shoulders']], ['Pierna y core', 'glúteo, femoral, pantorrilla y core', ['legs', 'calves', 'core']]],
        6 => [['Empuje A', 'pecho, hombro y tríceps', ['chest', 'shoulders', 'triceps']], ['Tracción A', 'espalda y bíceps', ['back', 'biceps']], ['Pierna A', 'cuádriceps y glúteo', ['legs', 'core']], ['Empuje B', 'hombro, pecho y tríceps', ['shoulders', 'chest', 'triceps']], ['Tracción B', 'espalda alta y bíceps', ['back', 'biceps', 'arms']], ['Pierna B', 'femoral, glúteo y core', ['legs', 'calves', 'core']]],
    ];

    /** Principiante con 3 días: cuerpo completo, que es lo que pide su nivel. */
    private const BEGINNER_3 = [['Cuerpo completo A', 'piernas, empuje y core', ['legs', 'chest', 'shoulders', 'core']], ['Cuerpo completo B', 'cadera, tracción y core', ['legs', 'back', 'biceps', 'core']], ['Cuerpo completo C', 'piernas, empuje, tracción y core', ['legs', 'chest', 'back', 'core']]];

    /**
     * Al COMPROBAR (no al repartir): un ejercicio genérico de «brazos» vale en
     * un día de bíceps o de tríceps, y uno de pantorrilla en un día de pierna.
     * El catálogo tiene ejercicios etiquetados solo como «Brazos».
     */
    private const COMPATIBLE = [
        'biceps' => ['arms'],
        'triceps' => ['arms'],
        'legs' => ['calves'],
    ];

    public function __construct(private readonly PlanSafetyLexicon $lexicon) {}

    /** @return list<array{0: string, 1: string, 2: list<string>}> */
    public function split(int $days, string $trainingLevel): array
    {
        $days = max(1, min(6, $days));
        if ($days === 3 && $trainingLevel === 'principiante') {
            return self::BEGINNER_3;
        }

        return self::SPLITS[$days];
    }

    /**
     * El reparto con el nombre que llevará cada día: «Día 1 · Empuje A».
     *
     * @return list<array{name: string, focus: string, muscles: string, tags: list<string>}>
     */
    public function dayPlan(int $days, string $trainingLevel): array
    {
        $division = $this->split($days, $trainingLevel);

        return array_map(
            fn (array $s, int $i) => ['name' => 'Día '.($i + 1).' · '.$s[0], 'focus' => $s[0], 'muscles' => $s[1], 'tags' => $s[2]],
            $division,
            array_keys($division),
        );
    }

    /**
     * Grupos de un ejercicio del catálogo.
     *
     * @param  array<string,mixed>  $exercise  con `muscle_group` y `target`
     * @return list<string>
     */
    public function tagsOf(array $exercise): array
    {
        $texto = ' '.$this->lexicon->normalize(($exercise['muscle_group'] ?? '').' , '.($exercise['target'] ?? '')).' ';
        $out = [];
        foreach (self::MUSCLE_TAGS as $tag => $palabras) {
            foreach ($palabras as $w) {
                if (preg_match('/(?<![a-z])'.preg_quote($w, '/').'(?![a-z])/', $texto)) {
                    $out[] = $tag;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Comprobación POSTERIOR: ¿cada día trabaja los grupos que le tocaban?
     *
     * Se compara el día i con el día i del reparto pedido. Un día pasa si al
     * menos {@see MIN_DAY_SHARE} de sus ejercicios (los que están en el catálogo)
     * pertenece a sus grupos. Un ejercicio que no está en el catálogo no cuenta
     * ni a favor ni en contra: eso lo rechaza antes la validación de ids.
     *
     * @param  list<array<string,mixed>>  $days  `routine.days` tal como se va a guardar
     * @param  list<array{name: string, focus: string, tags: list<string>}>  $planned  {@see dayPlan()}
     * @param  array<int, array<string,mixed>>  $catalogById  id → ejercicio del catálogo
     * @return array{ok: bool, min_share: float, days: list<array{day: int, focus: string, matched: int, total: int, share: float}>, problems: list<string>}
     */
    public function review(array $days, array $planned, array $catalogById, ?float $minShare = null): array
    {
        $umbral = $minShare ?? (float) config('iron_ai.plan_powers.muscle_min_share', self::MIN_DAY_SHARE);
        $problemas = [];
        $detalle = [];
        $minimo = 1.0;

        if (count($days) !== count($planned)) {
            $problemas[] = 'la rutina trae '.count($days).' días y el reparto tiene '.count($planned);
        }

        foreach (array_values($days) as $i => $dia) {
            $plan = $planned[$i] ?? null;
            if ($plan === null) {
                break;
            }
            $esperados = $plan['tags'];
            foreach ($plan['tags'] as $t) {
                array_push($esperados, ...(self::COMPATIBLE[$t] ?? []));
            }

            $total = 0;
            $bien = 0;
            foreach ((array) ($dia['exercises'] ?? []) as $e) {
                $ej = $catalogById[(int) ($e['exercise_id'] ?? 0)] ?? null;
                if ($ej === null) {
                    continue;
                }
                $total++;
                if (array_intersect($this->tagsOf($ej), $esperados) !== []) {
                    $bien++;
                }
            }

            $cuota = $total > 0 ? round($bien / $total, 2) : 0.0;
            $minimo = min($minimo, $cuota);
            $detalle[] = ['day' => $i + 1, 'focus' => $plan['focus'], 'matched' => $bien, 'total' => $total, 'share' => $cuota];
            if ($cuota < $umbral) {
                $problemas[] = 'el día '.($i + 1)." («{$plan['focus']}») trae {$bien} de {$total} ejercicios de {$plan['muscles']}";
            }
        }

        return ['ok' => $problemas === [], 'min_share' => $minimo, 'days' => $detalle, 'problems' => $problemas];
    }

    /**
     * Reparte en días unos ejercicios YA ELEGIDOS («Organizar automáticamente»
     * del editor del entrenador, punto F; docs/iron-ia/ORGANIZAR-AUTOMATICAMENTE-CONTRATO.md).
     * Las mismas tablas que la generación: el reparto de {@see dayPlan()}, la
     * clasificación de {@see tagsOf()} y {@see COMPATIBLE}. No añade ni quita
     * ejercicios y no llama al modelo: es determinista.
     *
     * Reglas, en orden:
     *  1. Un ejercicio FIJADO se queda en su día si ese día existe en el reparto.
     *  2. Si ya está en un día que trabaja sus grupos (directamente o por
     *     {@see COMPATIBLE}, como en review()), se queda: no se mueve lo que está bien.
     *  3. Si no, va al día menos cargado de los que trabajan sus grupos (o un
     *     grupo compatible). Un compuesto (varios grupos, contando `target`) vale
     *     en cualquiera de sus días. Uno cuyos grupos no tiene ningún día (cardio,
     *     sin clasificar) se queda en su día si es del reparto; si no, al menos
     *     cargado. Organizar dos veces da lo mismo que una (idempotente).
     *  4. Un día que quede vacío se rellena SOLO con un ejercicio de sus grupos
     *     que sobre en otro día (que tenga más de uno); si no hay, queda vacío y
     *     quien llama avisa: no se pone un ejercicio de pierna en un día de torso.
     *  5. Dentro de cada día se conserva el orden relativo que traían.
     *
     * @param  list<array{tags: list<string>, day: ?string, pinned: bool}>  $items
     * @return array{plan: list<array{name: string, focus: string, muscles: string, tags: list<string>}>, days: list<list<int>>}
     *                                                                                                                           `days[k]` = índices de `$items` del día k
     *
     * @throws \InvalidArgumentException si hay más días que ejercicios
     */
    public function arrange(array $items, int $days, string $trainingLevel): array
    {
        $plan = $this->dayPlan($days, $trainingLevel);
        $n = count($plan);
        if (count($items) < $n) {
            throw new \InvalidArgumentException('more_days_than_exercises');
        }

        $nombres = array_column($plan, 'name');
        $directos = array_column($plan, 'tags');
        $ampliados = array_map(function (array $d): array {
            $t = $d['tags'];
            foreach ($d['tags'] as $x) {
                array_push($t, ...(self::COMPATIBLE[$x] ?? []));
            }

            return array_values(array_unique($t));
        }, $plan);
        $candidatos = function (array $tags, array $porDia): array {
            $out = [];
            foreach ($porDia as $k => $grupos) {
                if (array_intersect($tags, $grupos) !== []) {
                    $out[] = $k;
                }
            }

            return $out;
        };

        $cubos = array_fill(0, $n, []);
        $fijados = [];
        foreach ($items as $i => $it) {
            $k = $it['pinned'] && $it['day'] !== null ? array_search($it['day'], $nombres, true) : false;
            if ($k !== false) {
                $cubos[$k][] = $i;
                $fijados[$i] = true;
            }
        }
        foreach ($items as $i => $it) {
            if (isset($fijados[$i])) {
                continue;
            }
            $cand = $candidatos($it['tags'], $directos) ?: $candidatos($it['tags'], $ampliados);
            $actual = $it['day'] !== null ? array_search($it['day'], $nombres, true) : false;
            // Sin grupo en ningún día (cardio, cuello, sin clasificar): si ya está en un
            // día del reparto, se queda. Si no, rotaría en cada clic (no idempotente).
            if ($cand === []) {
                if ($actual !== false) {
                    $cubos[$actual][] = $i;

                    continue;
                }
                $cand = range(0, $n - 1);
            }
            // «Está bien» = su día trabaja sus grupos, directamente o por compatibilidad
            // (un «brazos» en un día de tríceps): la misma regla que review().
            if ($actual !== false && array_intersect($it['tags'], $ampliados[$actual]) !== []) {
                $cubos[$actual][] = $i;

                continue;
            }
            $mejor = $cand[0];
            foreach ($cand as $k) {
                if (count($cubos[$k]) < count($cubos[$mejor])) {
                    $mejor = $k;
                }
            }
            $cubos[$mejor][] = $i;
        }

        // Días vacíos: solo con ejercicios de SUS grupos que sobren en otro día.
        foreach (array_keys($cubos) as $vacio) {
            if ($cubos[$vacio] !== []) {
                continue;
            }
            foreach ([$directos, $ampliados] as $porDia) {
                $donante = null;
                $elegido = null;
                foreach ($cubos as $k => $cubo) {
                    if ($k === $vacio || count($cubo) < 2 || ($donante !== null && count($cubo) <= count($cubos[$donante]))) {
                        continue;
                    }
                    foreach (array_reverse($cubo) as $i) {
                        if (! isset($fijados[$i]) && array_intersect($items[$i]['tags'], $porDia[$vacio]) !== []) {
                            [$donante, $elegido] = [$k, $i];
                            break;
                        }
                    }
                }
                if ($elegido !== null) {
                    $cubos[$donante] = array_values(array_diff($cubos[$donante], [$elegido]));
                    $cubos[$vacio][] = $elegido;
                    break;
                }
            }
        }

        foreach ($cubos as &$cubo) {
            sort($cubo);
        }
        unset($cubo);

        return ['plan' => $plan, 'days' => $cubos];
    }

    /**
     * La regla para el prompt: el reparto día a día, en el orden del plan.
     *
     * @param  list<array{name: string, focus: string, muscles: string}>  $planned
     */
    public function promptRule(array $planned): string
    {
        $lineas = array_map(fn (array $d) => "{$d['name']} ({$d['muscles']})", $planned);

        return 'Reparte los días EXACTAMENTE en este orden y elige los ejercicios de cada día entre los de sus grupos '
            .'(`muscle_group` del catálogo); como mucho uno o dos accesorios de otro grupo: '.implode('; ', $lineas).'.';
    }
}
