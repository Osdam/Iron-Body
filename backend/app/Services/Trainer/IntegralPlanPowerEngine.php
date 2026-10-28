<?php

namespace App\Services\Trainer;

use App\Exceptions\IntegralPlanException;
use App\Services\Nutrition\Ai\NutritionAiClient;

/**
 * El plan integral por NIVELES (potencias 2..5, docs/iron-ia/POTENCIAS-IRON-IA.md §4).
 *
 * No es otra Iron IA: usa el mismo cliente, el mismo modelo, el mismo
 * validador y la misma persistencia que el nivel 1, desde
 * {@see IntegralPlanGenerationService}. Lo que cambia es CÓMO se pide:
 *
 *  1. PHP decide la estructura (días tipo, momentos, variedad, división de la
 *     rutina) — {@see IntegralPlanStrategy}.
 *  2. Cada sección sale en su propia llamada, TODAS A LA VEZ (`Http::pool`),
 *     con Structured Outputs estricto y dentro de un plazo común.
 *  3. Cada sección pasa la puerta de calidad — {@see IntegralPlanQualityGate}.
 *     La que no pasa (o llega cortada por `finish_reason=length`) se repite
 *     UNA vez, sola, si queda plazo.
 *  4. PHP ensambla, suma y consolida — {@see IntegralPlanAssembler} — y el
 *     validador de siempre normaliza lo que se va a guardar.
 *
 * NO GUARDA NADA. Devuelve el resultado o el fallo; persistir sigue siendo
 * cosa del servicio, en su transacción de siempre. Si algo no cabe en el plazo
 * o no pasa la puerta tras reparar, el resultado es un fallo REINTENTABLE: un
 * plan premium a medias nunca se entrega como si fuera completo.
 */
class IntegralPlanPowerEngine
{
    public function __construct(
        private readonly IntegralPlanStrategy $strategy,
        private readonly IntegralPlanQualityGate $gate,
        private readonly IntegralPlanAssembler $assembler,
        private readonly IntegralPlanValidator $validator,
        private readonly IntegralPlanEvaluator $evaluator,
        private readonly PlanSafetyLexicon $lexicon,
        private readonly NutritionAiClient $ai,
    ) {}

    /**
     * @param  array<string,mixed>  $contexto  MemberContextAssembler::for()
     * @param  array<string,mixed>  $brief  MemberContextAssembler::planBrief()
     * @param  list<array<string,mixed>>  $catalogo
     * @return array{status: string, error: ?IntegralPlanException, error_code: ?string, plan: ?array, payload: array<string,mixed>, usage: array<string,mixed>, hash: string}
     */
    public function generate(array $contexto, array $brief, array $catalogo, int $nutritionLevel, int $routineLevel, string $model): array
    {
        $inicio = microtime(true);
        $plazo = $inicio + (float) config('iron_ai.plan_powers.deadline_seconds', 17);

        $plan = $this->strategy->plan($nutritionLevel, $routineLevel, $brief);
        $peticiones = $this->strategy->sections($this->modelBrief($contexto, $brief), $plan, $catalogo);
        $hash = hash('sha256', IntegralPlanStrategy::PROMPT_VERSION.'|'.json_encode(array_map(
            fn ($p) => [$p['messages'], $p['schema']['name'], $p['max_tokens']],
            $peticiones,
        )));
        $nombres = array_column($catalogo, 'name', 'id');

        $registro = [];
        $resultados = $this->call($model, $peticiones, $plazo, $registro, 1);
        $revision = $this->review($plan, $brief, $peticiones, $resultados, $nombres);
        $reparadas = [];

        $pendientes = $this->failing($revision);
        // Tope global: con más secciones malas que reparaciones permitidas, una
        // ronda no las arregla todas; repararlas solo gastaría plazo y dinero.
        $tope = max(0, (int) config('iron_ai.plan_powers.max_repairs', 6));
        $sinReparar = count($pendientes) > $tope;
        $sinTiempo = ($plazo - microtime(true)) < (float) config('iron_ai.plan_powers.min_repair_seconds', 6);
        // Por qué NO se reparó, si había algo que reparar (corrida 4: 0
        // reparaciones y no se veía por qué).
        $motivo = $pendientes === [] ? null : ($sinReparar ? 'cap' : ($sinTiempo ? 'deadline' : null));
        if ($pendientes !== [] && ! $sinReparar && ! $sinTiempo) {
            $reparaciones = [];
            foreach ($pendientes as $clave) {
                $reparaciones[$clave] = $this->repairRequest($peticiones[$clave], $resultados[$clave], $revision['sections'][$clave]);
            }
            $reparadas = array_keys($reparaciones);

            $resultados = array_merge($resultados, $this->call($model, $reparaciones, $plazo, $registro, 2));
            $revision = $this->review($plan, $brief, $peticiones, $resultados, $nombres);
            $pendientes = $this->failing($revision);
        }

        $uso = $this->usage($registro, $reparadas);
        $base = [
            'plan' => null,
            'hash' => $hash,
            'usage' => $uso,
            'payload' => [
                'powers' => ['nutrition' => $nutritionLevel, 'routine' => $routineLevel],
                'sections' => $this->sectionReport($revision),
                'repaired' => $reparadas,
                'repairs_skipped' => $motivo === null ? 0 : count($this->failing($revision)),
                'repairs_skipped_reason' => $motivo,
            ],
        ];

        if (microtime(true) > $plazo) {
            return $base + $this->failure('failed', 'ai_deadline', IntegralPlanException::deadline());
        }

        if ($pendientes !== []) {
            return $base + $this->classify($pendientes, $revision);
        }

        try {
            $crudo = $this->assemble($plan, $brief, $revision);
            $final = $this->validator->validate($crudo['raw'], extended: true);
        } catch (IntegralPlanException $e) {
            return $base + $this->failure('invalid', $e->code_, $e);
        }

        $evaluacion = $this->evaluator->evaluate($final, $crudo['structured'], $brief, $nutritionLevel, $routineLevel);

        $base['plan'] = $final;
        $base['payload'] = array_merge($final, [
            'powers' => ['nutrition' => $nutritionLevel, 'routine' => $routineLevel],
            'brief' => $this->briefSummary($brief),
            'structured' => $crudo['structured'],
            'quality' => [
                'repaired' => $reparadas,
                'balance' => $revision['balance'],
                'evaluation' => $evaluacion,
            ],
            'sections' => $this->sectionReport($revision),
        ]);

        return $base + ['status' => 'ok', 'error' => null, 'error_code' => null];
    }

    // ── Llamadas ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, array<string,mixed>>  $peticiones
     * @param  array<string, list<array<string,mixed>>>  $registro
     * @return array<string, array<string,mixed>>
     */
    private function call(string $model, array $peticiones, float $plazo, array &$registro, int $intento): array
    {
        $quedan = $plazo - microtime(true);
        if ($quedan <= 0.5) {
            $res = array_map(fn () => ['status' => 'timeout', 'error_code' => 'deadline', 'json' => null, 'content' => null, 'usage' => null, 'finish_reason' => null, 'latency_ms' => 0], $peticiones);
        } else {
            $envio = array_map(fn ($p) => array_diff_key($p, ['meta' => true]), $peticiones);
            $res = $this->ai->completeMany($model, $envio, $quedan);
        }

        foreach ($res as $clave => $r) {
            $registro[$clave][] = [
                'attempt' => $intento,
                'status' => $r['status'] ?? null,
                'error_code' => $r['error_code'] ?? null,
                'finish_reason' => $r['finish_reason'] ?? null,
                'latency_ms' => $r['latency_ms'] ?? null,
                'usage' => is_array($r['usage'] ?? null) ? $r['usage'] : null,
            ];
        }

        return $res;
    }

    /**
     * La reparación de UNA sección: lo que pidió, lo que contestó y por qué no
     * vale. Si se cortó por longitud, no se le reenvía el texto cortado (no
     * aporta) y se le da algo más de margen.
     *
     * @param  array<string,mixed>  $peticion
     * @param  array<string,mixed>  $resultado
     * @param  array<string,mixed>  $revision
     * @return array<string,mixed>
     */
    private function repairRequest(array $peticion, array $resultado, array $revision): array
    {
        $mensajes = $peticion['messages'];

        if ($revision['truncated']) {
            $mensajes[] = ['role' => 'user', 'content' => 'Tu respuesta anterior se cortó por longitud. Devuelve la parte COMPLETA otra vez, con el mismo esquema, siendo más conciso en los textos.'];
            $peticion['max_tokens'] = (int) min(2000, ceil($peticion['max_tokens'] * 1.4));
        } elseif ($revision['transport'] !== null) {
            // Falló el transporte (tiempo, 5xx): se repite tal cual.
        } else {
            if (is_string($resultado['content'] ?? null) && $resultado['content'] !== '') {
                $mensajes[] = ['role' => 'assistant', 'content' => $resultado['content']];
            }
            $mensajes[] = ['role' => 'user', 'content' => "Tu respuesta no pasó la revisión del gimnasio:\n- "
                .implode("\n- ", array_slice($revision['issues'], 0, 12))
                ."\nDevuelve la parte COMPLETA corregida, con el mismo esquema."];
        }

        $peticion['messages'] = $mensajes;

        return $peticion;
    }

    // ── Revisión ────────────────────────────────────────────────────────────

    /**
     * Pasa cada sección por la puerta y añade lo que solo se ve juntando
     * secciones: los totales de cada día frente a la meta y la variedad.
     *
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $brief
     * @param  array<string, array<string,mixed>>  $peticiones
     * @param  array<string, array<string,mixed>>  $resultados
     * @param  array<int,string>  $nombres
     * @return array{sections: array<string, array<string,mixed>>, totals: array<int, array<string,mixed>|null>}
     */
    private function review(array $plan, array $brief, array $peticiones, array $resultados, array $nombres): array
    {
        $restricciones = array_column($brief['restrictions'], 'key');
        $secciones = [];

        foreach ($peticiones as $clave => $p) {
            $r = $resultados[$clave] ?? [];
            $meta = $p['meta'];
            $s = ['kind' => $meta['kind'], 'issues' => [], 'data' => null, 'transport' => null, 'truncated' => false];

            if (($r['status'] ?? null) !== NutritionAiClient::STATUS_SUCCESS) {
                $s['transport'] = (string) ($r['error_code'] ?? 'upstream');
                $s['issues'][] = 'La sección no llegó ('.$s['transport'].').';
                $secciones[$clave] = $s;

                continue;
            }
            if (($r['finish_reason'] ?? null) === 'length') {
                $s['truncated'] = true;
                $s['issues'][] = 'La respuesta se cortó por longitud.';
                $secciones[$clave] = $s;

                continue;
            }

            $json = is_array($r['json'] ?? null) ? $r['json'] : null;
            if ($json === null) {
                $s['issues'][] = 'La respuesta no es un objeto JSON.';
                $secciones[$clave] = $s;

                continue;
            }

            $g = match ($meta['kind']) {
                'nutrition_day' => $this->gate->nutritionDay($json, $meta, $plan['nutrition'], $restricciones),
                'routine_day' => $this->gate->routineDay($json, $plan['routine'], $nombres, $meta['name']),
                'routine_frame' => $this->gate->routineFrame($json, $plan['routine']),
                'nutrition_core' => $this->core($json, $restricciones),
                'nutrition_v1' => $this->nutritionV1($json, $restricciones),
                'routine_v1' => $this->routineV1($json, $nombres),
            };
            $s['issues'] = $g['issues'];
            $s['data'] = $g['data'];
            // M2: ningún texto generado, de ninguna sección, puede nombrar
            // fármacos ni ayunos prolongados (las comidas ya las mira la puerta).
            if ($meta['kind'] !== 'nutrition_day') {
                foreach ($this->texts($g['data']) as $campo => $texto) {
                    $issue = $this->gate->riskIssue("«{$campo}»", $texto);
                    if ($issue !== null) {
                        $s['issues'][] = $issue;
                    }
                }
            }
            $secciones[$clave] = $s;
        }

        $totales = [];
        $notas = [];
        $balance = [];
        if (($plan['nutrition_level'] ?? 1) >= 2) {
            $porTipo = [];
            $clavesPorTipo = [];
            foreach ($plan['nutrition_sections'] as $clave => $sec) {
                $clavesPorTipo[$sec['day_type']][] = $clave;
                foreach ((array) ($secciones[$clave]['data'] ?? []) as $slot => $comida) {
                    $porTipo[$sec['day_type']][$slot] = $comida;
                }
            }

            $minimo = $this->strategy->minDailyKcal($brief);
            foreach ($clavesPorTipo as $tipo => $claves) {
                $completo = array_reduce($claves, fn ($ok, $c) => $ok && $secciones[$c]['issues'] === [], true);
                $nombreDia = $plan['nutrition_sections'][$claves[0]]['day_name'];

                // Corrida 4: las porciones las fija PHP. Con el día completo y
                // una meta, se escalan los ingredientes de cada rol con las
                // tasas del propio modelo; lo balanceado es lo que se muestra,
                // se suma y se compra. Sin meta no hay balance (solo el mínimo).
                if ($completo && ($plan['nutrition']['detail']['macros'] ?? false) && is_array($brief['targets'] ?? null)) {
                    $b = $this->assembler->balanceDay($porTipo[$tipo] ?? [], $brief['targets']);
                    $porTipo[$tipo] = $b['meals'];
                    $balance[$tipo] = $b['factors'];
                    foreach ($claves as $c) {
                        foreach (array_keys((array) $secciones[$c]['data']) as $slot) {
                            $secciones[$c]['data'][$slot] = $b['meals'][$slot];
                        }
                    }
                }

                $t = $completo ? $this->gate->dayTotals($porTipo[$tipo] ?? [], $brief['targets'], $nombreDia, $minimo) : ['totals' => null, 'issues' => [], 'note' => null];
                $totales[$tipo] = $t['totals'];
                $notas[$tipo] = $t['note'];
                if ($t['issues'] !== []) {
                    // Solo se repara la parte del día que más se desvía de lo
                    // que le tocaba (corrida 2: reparar las dos mitades de cada
                    // día disparaba las reparaciones), y se le dice cuánto.
                    [$clave, $parte] = $this->worstSection($claves, $plan, $secciones, $brief['targets'] ?? ['kcal' => $minimo, 'protein_g' => null]);
                    foreach ($t['issues'] as $issue) {
                        $secciones[$clave]['issues'][] = $issue;
                    }
                    if ($parte !== null) {
                        $secciones[$clave]['issues'][] = $parte;
                    }
                }
            }

            foreach ($this->gate->repeatedDays($porTipo) as $tipo) {
                foreach ($clavesPorTipo[$tipo] ?? [] as $c) {
                    $secciones[$c]['issues'][] = 'Este día repite el menú de otro día de la semana: cambia los platos manteniendo sus alimentos ancla.';
                }
            }

            // Variedad por plato: el mismo plato en el mismo momento 3 o más
            // veces en la semana. Se quedan los dos primeros; se reparan los
            // demás.
            if (($plan['nutrition']['day_types'] ?? 1) === 7) {
                $vistos = [];
                ksort($porTipo);
                foreach ($porTipo as $tipo => $comidas) {
                    foreach ($comidas as $slot => $comida) {
                        $firma = $this->lexicon->normalize((string) ($comida['title'] ?? ''));
                        if ($firma === '') {
                            continue;
                        }
                        $vistos[$slot][$firma] = ($vistos[$slot][$firma] ?? 0) + 1;
                        if ($vistos[$slot][$firma] >= 3) {
                            foreach ($clavesPorTipo[$tipo] as $c) {
                                if (isset($plan['nutrition_sections'][$c]['slots'][$slot])) {
                                    $etiqueta = $plan['slots'][$slot][0];
                                    $secciones[$c]['issues'][] = "«{$comida['title']}» ya va en «{$etiqueta}» dos veces esta semana: cambia este plato manteniendo su alimento ancla.";
                                }
                            }
                        }
                    }
                }
            }
        }

        return ['sections' => $secciones, 'totals' => $totales, 'notes' => $notas, 'balance' => $balance];
    }

    /**
     * La sección de un día que más se aleja de SU parte de la meta (kcal y
     * proteína relativas), y una frase con lo que le toca.
     *
     * @param  list<string>  $claves
     * @return array{0: string, 1: ?string}
     */
    private function worstSection(array $claves, array $plan, array $secciones, ?array $metas): array
    {
        if (count($claves) === 1 || ! is_array($metas) || ($metas['kcal'] ?? 0) <= 0) {
            return [$claves[0], null];
        }
        $conProteina = ($metas['protein_g'] ?? 0) > 0;

        $peor = null;
        foreach ($claves as $c) {
            $sub = $this->gate->sum((array) $secciones[$c]['data']);
            $parte = $plan['nutrition_sections'][$c]['share'];
            if ($sub === null) {
                continue;
            }
            $k = $parte * $metas['kcal'];
            $p = $conProteina ? $parte * $metas['protein_g'] : 0.0;
            $desvio = abs($sub['kcal'] - $k) / max(1, $k) + ($conProteina ? abs($sub['protein_g'] - $p) / max(1, $p) : 0.0);
            if ($peor === null || $desvio > $peor[1]) {
                $peor = [$c, $desvio, $conProteina
                    ? sprintf('Tu parte de ese día (estos momentos) suma %d kcal y %s g de proteína; debería acercarse a %d kcal y %d g.',
                        $sub['kcal'], rtrim(rtrim(number_format($sub['protein_g'], 1, ',', ''), '0'), ','), (int) round($k), (int) round($p))
                    : sprintf('Tu parte de ese día (estos momentos) suma %d kcal; debería aportar al menos %d kcal.', $sub['kcal'], (int) round($k)),
                ];
            }
        }

        return $peor === null ? [$claves[0], null] : [$peor[0], $peor[2]];
    }

    /** @return array{issues: list<string>, data: array<string,mixed>} */
    private function core(array $json, array $restricciones): array
    {
        $issues = [];
        $data = [
            'analysis' => is_array($json['analysis'] ?? null) ? $json['analysis'] : null,
            'objective' => $this->text($json['objective'] ?? null),
            'objective_description' => $this->text($json['objective_description'] ?? null),
            'training_stage' => $this->text($json['training_stage'] ?? null),
            'hydration' => $this->text($json['hydration'] ?? null),
            'organization' => $this->text($json['organization'] ?? null),
            'supplements' => $this->text($json['supplements'] ?? null),
            'general_substitutions' => $this->text($json['general_substitutions'] ?? null),
        ];
        if ($data['objective'] === null) {
            $issues[] = 'Falta el objetivo de la guía.';
        }
        // Todo el texto libre del núcleo pasa por el léxico, no solo las
        // sustituciones: cualquiera puede nombrar un alimento.
        foreach ([
            'sustituciones generales' => 'general_substitutions', 'organización' => 'organization',
            'hidratación' => 'hydration', 'descripción del objetivo' => 'objective_description',
        ] as $donde => $campo) {
            $issue = $restricciones === [] ? null : $this->gate->conflictIssue("«{$donde}»", (string) $data[$campo], $restricciones);
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return ['issues' => $issues, 'data' => $data];
    }

    /** @return array{issues: list<string>, data: array<string,mixed>} */
    private function nutritionV1(array $json, array $restricciones): array
    {
        $issues = [];
        $n = is_array($json['nutrition'] ?? null) ? $json['nutrition'] : [];
        if ($this->text($n['objective'] ?? null) === null) {
            $issues[] = 'Falta el objetivo de la guía.';
        }
        $comidas = array_filter((array) ($n['meals'] ?? []), fn ($m) => is_array($m) && $this->text($m['label'] ?? null) && $this->text($m['description'] ?? null));
        if ($comidas === []) {
            $issues[] = 'La guía no trae ninguna comida.';
        }
        foreach ($comidas as $m) {
            $issue = $restricciones === [] ? null : $this->gate->conflictIssue("«{$m['label']}»", (string) $m['description'], $restricciones);
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        return ['issues' => $issues, 'data' => $json];
    }

    /** @return array{issues: list<string>, data: array<string,mixed>} */
    private function routineV1(array $json, array $nombres): array
    {
        $r = is_array($json['routine'] ?? null) ? $json['routine'] : [];
        $validos = 0;
        foreach ((array) ($r['days'] ?? []) as $d) {
            foreach ((array) ($d['exercises'] ?? []) as $e) {
                if (is_array($e) && is_int($e['exercise_id'] ?? null) && isset($nombres[$e['exercise_id']])) {
                    $validos++;
                }
            }
        }

        $issues = [];
        if ($this->text($r['name'] ?? null) === null) {
            $issues[] = 'La rutina no trae nombre.';
        }
        if ($validos === 0) {
            $issues[] = 'La rutina no trae ningún ejercicio del catálogo.';
        }

        return ['issues' => $issues, 'data' => $json];
    }

    /**
     * Todos los textos de una sección, con su ruta, para pasarlos por el
     * léxico de riesgo.
     *
     * @return array<string,string>
     */
    private function texts(mixed $data, string $ruta = ''): array
    {
        if (is_string($data)) {
            return [$ruta => $data];
        }
        $out = [];
        foreach (is_array($data) ? $data : [] as $k => $v) {
            $out += $this->texts($v, $ruta === '' ? (string) $k : $ruta.'.'.$k);
        }

        return $out;
    }

    /** @return list<string> */
    private function failing(array $revision): array
    {
        return array_keys(array_filter($revision['sections'], fn ($s) => $s['issues'] !== []));
    }

    // ── Ensamblado ──────────────────────────────────────────────────────────

    /**
     * @return array{raw: array<string,mixed>, structured: array<string,mixed>}
     */
    private function assemble(array $plan, array $brief, array $revision): array
    {
        $s = $revision['sections'];
        $estructura = [];

        if ($plan['nutrition_level'] === 1) {
            $v1 = $s['nutrition_v1']['data'];
            $analisis = $v1['analysis'] ?? null;
            // Revisor 4: la nutrición de nivel 1 dentro del motor pasa por la
            // MISMA seguridad que el nivel 1 de siempre.
            $nutricion = $this->assembler->clinicalNotice(['nutrition' => $v1['nutrition']], $brief['clinical_signals'])['nutrition'];
        } else {
            $core = $s['nutrition_core']['data'];
            $analisis = $core['analysis'];
            $porTipo = [];
            foreach ($plan['nutrition_sections'] as $clave => $sec) {
                foreach ($s[$clave]['data'] as $slot => $comida) {
                    $porTipo[$sec['day_type']][$slot] = $comida;
                }
            }
            // Mismo orden de momentos que en el plan, sea cual sea el de llegada.
            foreach ($porTipo as $t => $comidas) {
                $porTipo[$t] = array_replace(array_intersect_key($plan['slots'], $comidas), $comidas);
            }
            $n = $this->assembler->nutrition($plan, $brief, $core, $porTipo, $revision['totals'], $revision['notes']);
            $nutricion = $n['nutrition'];
            $estructura['nutrition'] = $n['structured'];
        }

        if ($plan['routine_level'] === 1) {
            $rutina = $this->assembler->clinicalNotice(['routine' => $s['routine_v1']['data']['routine']], $brief['clinical_signals'])['routine'];
        } else {
            $dias = [];
            foreach (array_keys($plan['routine_days']) as $i) {
                $dias[] = $s['routine_day_'.($i + 1)]['data'];
            }
            $rutina = $this->assembler->routine($plan, $brief, $s['routine_frame']['data'], $dias);
            $estructura['routine'] = ['frame' => $s['routine_frame']['data'], 'days' => $dias, 'split' => $plan['routine_days']];
        }

        return [
            'raw' => ['analysis' => $analisis, 'nutrition' => $nutricion, 'routine' => $rutina],
            'structured' => $estructura,
        ];
    }

    // ── Fallos y registro ───────────────────────────────────────────────────

    /**
     * Qué se le dice al entrenador. Si alguna sección no llegó por tiempo, es
     * el plazo; si no llegó por otro motivo, es el proveedor; si llegó pero no
     * pasó la puerta tras reparar, es un plan incompleto. Las tres son
     * reintentables y ninguna guarda nada.
     *
     * @param  list<string>  $pendientes
     * @return array{status: string, error: IntegralPlanException, error_code: string}
     */
    private function classify(array $pendientes, array $revision): array
    {
        $transporte = array_filter(array_map(fn ($c) => $revision['sections'][$c]['transport'], $pendientes));
        if (array_intersect($transporte, ['timeout', 'deadline']) !== []) {
            return $this->failure('failed', 'ai_deadline', IntegralPlanException::deadline());
        }
        if ($transporte !== []) {
            return $this->failure('failed', (string) reset($transporte), IntegralPlanException::upstream());
        }

        $primera = $revision['sections'][$pendientes[0]]['issues'][0] ?? 'una sección no pasó la revisión';

        return $this->failure('invalid', 'quality_gate', IntegralPlanException::malformed(
            'una parte no pasó la revisión de calidad ('.rtrim(mb_strtolower(mb_substr($primera, 0, 1)).mb_substr($primera, 1), '.').')',
        ));
    }

    /** @return array{status: string, error: IntegralPlanException, error_code: string} */
    private function failure(string $status, string $code, IntegralPlanException $e): array
    {
        return ['status' => $status, 'error' => $e, 'error_code' => $code];
    }

    /**
     * Tokens por sección y sumados. Es lo que cuesta la generación: incluye
     * las reparaciones y las secciones que fallaron, porque también se pagan.
     *
     * @param  array<string, list<array<string,mixed>>>  $registro
     * @param  list<string>  $reparadas
     * @return array<string,mixed>
     */
    private function usage(array $registro, array $reparadas): array
    {
        $tot = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $llamadas = 0;
        foreach ($registro as $intentos) {
            foreach ($intentos as $i) {
                $llamadas++;
                foreach (array_keys($tot) as $k) {
                    $tot[$k] += (int) ($i['usage'][$k] ?? 0);
                }
            }
        }

        return $tot + ['calls' => $llamadas, 'repairs' => count($reparadas), 'sections' => $registro];
    }

    /** @return array<string, array<string,mixed>> */
    private function sectionReport(array $revision): array
    {
        return array_map(fn ($s) => [
            'kind' => $s['kind'],
            'ok' => $s['issues'] === [],
            'issues' => $s['issues'],
            'transport' => $s['transport'],
            'truncated' => $s['truncated'],
        ], $revision['sections']);
    }

    /**
     * Lo que el modelo ve del socio. Igual que en el nivel 1, nada que no
     * cambie el plan: ni nombre, ni teléfono, ni documento.
     *
     * @return array<string,mixed>
     */
    private function modelBrief(array $contexto, array $brief): array
    {
        return [
            'objective' => $brief['objective'],
            'training_level' => $brief['training_level'],
            'days_available' => $brief['days_available'],
            'targets' => $brief['targets'],
            'restrictions' => array_column($brief['restrictions'], 'label'),
            'restrictions_text' => $brief['restrictions_text'],
            'allergies' => $brief['allergies'],
            'preferences' => $brief['preferences'],
            'injuries' => $brief['injuries'],
            'is_minor' => $brief['is_minor'],
            'age_years' => $brief['age_years'],
            'gender' => $brief['gender'],
            'safety_mode' => $brief['safety_mode'],
            'clinical_signals' => array_column($brief['clinical_signals'], 'label'),
            'assumptions' => $brief['assumptions'],
            'body_composition' => $contexto['body_composition'] ?? null,
            'measurements' => $contexto['measurements'] ?? null,
            'trainer_notes' => $contexto['trainer_notes'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function briefSummary(array $brief): array
    {
        return array_intersect_key($brief, array_flip([
            'objective_key', 'training_level', 'days_available', 'days_source', 'targets',
            'restrictions', 'allergies', 'is_minor', 'clinical_signals', 'safety_mode', 'assumptions',
        ]));
    }

    private function text(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
