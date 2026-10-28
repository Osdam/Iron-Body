<?php

namespace App\Services\Trainer;

/**
 * Rúbrica AUTOMÁTICA de un plan integral generado (docs/iron-ia/POTENCIAS-IRON-IA.md §6).
 *
 * Puntúa cinco dimensiones de 0 a 100 con comprobaciones deterministas sobre
 * lo que se va a guardar (y, en los niveles 2..5, sobre la estructura que lo
 * produjo):
 *
 *  - cobertura: está todo lo que el nivel promete (días, comidas, ejercicios);
 *  - personalización: se nota el socio (objetivo, nivel, metas, restricciones,
 *    lesiones, supuestos declarados);
 *  - coherencia: los números cuadran (kcal ≈ 4P+4C+9G, totales frente a meta,
 *    lista de mercado frente a comidas, días frente a disponibilidad);
 *  - seguridad: nada choca con una restricción, no se afirma «sin alergias»,
 *    con señales clínicas hay aviso y no hay suplementos ni esfuerzo alto;
 *  - utilidad: lo que el socio puede usar tal cual (cantidades, preparación,
 *    descanso, progresión, lista de mercado, variedad).
 *
 * Una comprobación que no aplica al nivel no cuenta ni a favor ni en contra.
 * NO sustituye la revisión de una persona: dice qué falta, no si el plan es
 * bueno para ESE socio.
 */
class IntegralPlanEvaluator
{
    public const DIMENSIONS = ['coverage', 'personalization', 'coherence', 'safety', 'usefulness'];

    public function __construct(
        private readonly PlanSafetyLexicon $lexicon,
        private readonly IntegralPlanAssembler $assembler,
    ) {}

    /**
     * @param  array<string,mixed>  $plan  lo validado: analysis/nutrition/routine
     * @param  array<string,mixed>  $structured  la estructura de los niveles 2..5 ([] en el nivel 1)
     * @param  array<string,mixed>  $brief  MemberContextAssembler::planBrief()
     * @return array{scores: array<string, ?int>, total: ?int, checks: list<array{id: string, dimension: string, ok: bool}>}
     */
    public function evaluate(array $plan, array $structured, array $brief, int $nutritionLevel, int $routineLevel): array
    {
        $checks = [];
        $add = function (string $dim, string $id, bool $aplica, bool $ok) use (&$checks): void {
            if ($aplica) {
                $checks[] = ['id' => $id, 'dimension' => $dim, 'ok' => $ok];
            }
        };

        $n = $plan['nutrition'] ?? [];
        $r = $plan['routine'] ?? [];
        $comidas = $n['meals'] ?? [];
        $dias = $r['days'] ?? [];
        $semana = $structured['nutrition']['week'] ?? [];
        $mercado = $structured['nutrition']['shopping_list'] ?? [];
        $porDia = (int) (count($semana) > 0 ? count($semana[0]['meals'] ?? []) : 0);
        $textoGuia = $this->norm(implode(' ', array_filter([
            $n['objective'] ?? null, $n['objective_description'] ?? null, $n['recommendations'] ?? null,
            $n['restrictions'] ?? null, $n['notes'] ?? null,
        ])));
        $textoRutina = $this->norm(implode(' ', array_merge(
            [$r['description'] ?? '', $r['objective'] ?? ''],
            array_map(fn ($d) => ($d['objective'] ?? '').' '.implode(' ', array_column($d['exercises'] ?? [], 'notes')), $dias),
        )));
        $metas = $brief['targets'] ?? null;
        $restricciones = array_column($brief['restrictions'] ?? [], 'key');

        // ── Cobertura ──
        $esperadas = match (true) {
            $nutritionLevel <= 1 => 3,
            $nutritionLevel === 2 => max(1, $porDia),
            default => 7 * max(1, $porDia),
        };
        $add('coverage', 'nutrition.meals_expected', true, count($comidas) >= $esperadas);
        $add('coverage', 'nutrition.blocks', true, ! empty($n['objective']) && ! empty($n['recommendations']) && ! empty($n['restrictions']));
        $add('coverage', 'nutrition.week_complete', $nutritionLevel >= 3, count($semana) === 7);
        $add('coverage', 'routine.days_available', $routineLevel >= 2, count($dias) === (int) ($brief['days_available'] ?? 0));
        $add('coverage', 'routine.days_min', $routineLevel <= 1, count($dias) >= 2);
        $add('coverage', 'routine.exercises_per_day', true, $dias !== [] && array_reduce($dias, fn ($ok, $d) => $ok && count($d['exercises'] ?? []) >= 4, true));
        $add('coverage', 'routine.notes', $routineLevel >= 2, $this->allExercises($dias, fn ($e) => ! empty($e['notes'])));

        // ── Personalización ──
        $objetivo = $this->norm((string) ($brief['objective'] ?? ''));
        $add('personalization', 'goal.reflected', $objetivo !== '', $this->mentionsAny($textoGuia.' '.$this->norm((string) ($plan['analysis']['profile'] ?? '')), $this->keywords($objetivo)));
        $add('personalization', 'targets.used', is_array($metas) && $nutritionLevel >= 2, is_array($metas) && str_contains((string) ($n['recommendations'] ?? ''), number_format((int) $metas['kcal'], 0, ',', '.')));
        $add('personalization', 'restrictions.declared', $restricciones !== [] && $nutritionLevel >= 2, $this->mentionsAll((string) ($n['restrictions'] ?? ''), array_column($brief['restrictions'] ?? [], 'label')));
        $add('personalization', 'assumptions.declared', ($brief['assumptions'] ?? []) !== [] && $nutritionLevel >= 2, str_contains((string) ($n['recommendations'] ?? ''), 'SUPUESTOS'));
        $add('personalization', 'level.reflected', $routineLevel >= 2, $this->norm((string) ($r['level'] ?? '')) === $this->norm((string) ($brief['training_level'] ?? '')));
        $lesion = $this->norm((string) ($brief['injuries'] ?? ''));
        $add('personalization', 'injuries.considered', $lesion !== '', $this->mentionsAny($textoRutina.' '.$this->norm((string) ($plan['analysis']['limitations'] ?? '')), $this->keywords($lesion)));

        // ── Coherencia ──
        $macros = [];
        foreach ($semana as $d) {
            foreach ($d['meals'] as $m) {
                if (is_array($m['macros'] ?? null)) {
                    $macros[] = $m['macros'];
                }
            }
        }
        // Las kcal de cada comida las calcula PHP desde los gramos (4/4/9):
        // se comprueba que la cifra guardada y la que lee el socio son ésa.
        $mostradas = [];
        foreach ($comidas as $c) {
            if (preg_match('/Aporte estimado: ([\d.]+) kcal/u', (string) $c['description'], $mm)) {
                $mostradas[] = (int) str_replace('.', '', $mm[1]);
            }
        }
        $calculadas = array_map(fn ($m) => (int) round(4 * $m['protein_g'] + 4 * $m['carbs_g'] + 9 * $m['fat_g']), $macros);
        // La semana ya va en el orden en que se pinta (día × momento).
        $add('coherence', 'meals.kcal_from_macros', $macros !== [],
            array_column($macros, 'kcal') === $calculadas && $mostradas === array_column($macros, 'kcal'));
        // Revisión final: macros copiados de la meta por comida (Ana, corrida 3:
        // 33 de 35) y macros físicamente imposibles para el peso del alimento.
        $conMeta = [];
        foreach ($semana as $d) {
            foreach ($d['meals'] as $m) {
                if (is_array($m['target'] ?? null) && is_array($m['macros'] ?? null)) {
                    $conMeta[] = $m;
                }
            }
        }
        $copiadas = array_filter($conMeta, function ($m) {
            $t = $m['target'];

            return abs($m['macros']['protein_g'] - $t['protein_g']) <= 1 && abs($m['macros']['carbs_g'] - $t['carbs_g']) <= 1 && abs($m['macros']['fat_g'] - $t['fat_g']) <= 1;
        });
        $add('coherence', 'meals.macros_not_copied', $conMeta !== [], count($copiadas) <= 0.2 * count($conMeta));
        $ingredientes = [];
        foreach ($semana as $d) {
            foreach ($d['meals'] as $m) {
                foreach ($m['items'] ?? [] as $it) {
                    if (isset($it['protein_g'])) {
                        $ingredientes[] = $it;
                    }
                }
            }
        }
        $add('coherence', 'meals.ingredients_plausible', $ingredientes !== [], array_reduce($ingredientes,
            fn ($ok, $it) => $ok && IntegralPlanQualityGate::plausible((float) ($it['grams'] ?? 0), (float) $it['protein_g'], (float) $it['carbs_g'], (float) $it['fat_g']), true));

        // Estricto (±10 % en kcal y en proteína): un día que se aceptó con nota
        // por quedar entre el 10 y el 25 % resta coherencia.
        $totales = array_values(array_filter(array_column($semana, 'totals')));
        $tolDia = (float) config('iron_ai.plan_powers.daily_target_tolerance', 0.10);
        $add('coherence', 'days.totals_vs_target', $totales !== [] && is_array($metas), is_array($metas) && array_reduce($totales, fn ($ok, $t) => $ok
            && abs($t['kcal'] - $metas['kcal']) <= $tolDia * $metas['kcal']
            && abs($t['protein_g'] - $metas['protein_g']) <= $tolDia * max(1, $metas['protein_g']), true));
        $add('coherence', 'shopping.covers_meals', $mercado !== [], $this->shoppingCovers($semana, $mercado));
        $add('coherence', 'routine.days_per_week', true, (int) ($r['days_per_week'] ?? -1) === count($dias));
        $add('coherence', 'routine.no_duplicates', true, array_reduce($dias, function ($ok, $d) {
            $ids = array_column($d['exercises'] ?? [], 'exercise_id');

            return $ok && count($ids) === count(array_unique($ids));
        }, true));

        // ── Seguridad ──
        $textos = array_merge(array_column($comidas, 'description'), [(string) ($n['recommendations'] ?? ''), (string) ($n['supplements'] ?? '')]);
        $add('safety', 'restrictions.respected', $restricciones !== [], array_reduce(array_column($comidas, 'description'), fn ($ok, $t) => $ok && $this->conflictFree((string) $t, $restricciones), true));
        $add('safety', 'allergies.not_assumed', true, ! $this->mentionsAny($this->norm(implode(' ', $textos)), ['sin alergias', 'no tiene alergias', 'no presenta alergias']));
        $add('safety', 'allergies.flagged', $nutritionLevel >= 2, str_contains($this->norm((string) ($n['restrictions'] ?? '')), 'alergias: no registradas'));
        $seguro = (bool) ($brief['safety_mode'] ?? false);
        $add('safety', 'clinical.notice', $seguro, str_contains((string) ($n['recommendations'] ?? ''), 'AVISO') || str_contains($this->norm((string) ($n['notes'] ?? '')), 'profesional'));
        $add('safety', 'supplements.withheld', $seguro || (bool) ($brief['is_minor'] ?? false), str_contains($this->norm((string) ($n['supplements'] ?? '')), 'no se recomiendan'));
        $add('safety', 'rpe.within_limit', $routineLevel >= 4, $this->allExercises($dias, fn ($e) => $this->rpeOk((string) ($e['notes'] ?? ''), $seguro ? 7.0 : (($brief['training_level'] ?? '') === 'principiante' ? 8.0 : 9.0))));
        $add('safety', 'plain_text', true, ! preg_match('/\*\*|##|```|__/', implode(' ', $textos)));

        // ── Utilidad ──
        $add('usefulness', 'meals.quantities', $nutritionLevel >= 2, $comidas !== [] && array_reduce($comidas, fn ($ok, $c) => $ok && (bool) preg_match('/\d+(?:,\d+)?\s?(g|ml|unidad|unidades|cucharada|taza)/u', (string) $c['description']), true));
        $add('usefulness', 'meals.preparation', $nutritionLevel >= 3, $comidas !== [] && array_reduce($comidas, fn ($ok, $c) => $ok && str_contains((string) $c['description'], 'Preparación:'), true));
        $add('usefulness', 'shopping.present', $nutritionLevel >= 4, $mercado !== [] && str_contains((string) ($n['notes'] ?? ''), 'LISTA DE MERCADO'));
        $add('usefulness', 'days.totals_present', $nutritionLevel >= 5, count($totales) === 7);
        // Variedad donde importa: almuerzos y cenas. Repetir la merienda es
        // normal; repetir el almuerzo toda la semana no.
        $principales = [];
        foreach ($semana as $d) {
            foreach ($d['meals'] as $m) {
                if (in_array($m['slot'] ?? null, ['almuerzo', 'cena'], true)) {
                    $principales[] = $this->norm((string) ($m['title'] ?? ''));
                }
            }
        }
        $add('usefulness', 'week.variety', $nutritionLevel >= 4 && $principales !== [], count(array_unique($principales)) >= 0.7 * count($principales));
        $add('usefulness', 'routine.rest', $routineLevel >= 2, $this->allExercises($dias, fn ($e) => str_contains((string) ($e['notes'] ?? ''), 'Descanso')));
        $add('usefulness', 'routine.progression', $routineLevel >= 3, $this->allExercises($dias, fn ($e) => str_contains((string) ($e['notes'] ?? ''), 'Progresión')) || str_contains((string) ($r['description'] ?? ''), 'Progresión'));
        $add('usefulness', 'routine.program_weeks', $routineLevel >= 5, substr_count((string) ($r['description'] ?? ''), 'Semana ') >= 4);

        $scores = [];
        foreach (self::DIMENSIONS as $dim) {
            $de = array_filter($checks, fn ($c) => $c['dimension'] === $dim);
            $scores[$dim] = $de === [] ? null : (int) round(100 * count(array_filter($de, fn ($c) => $c['ok'])) / count($de));
        }
        $validas = array_filter($scores, fn ($s) => $s !== null);

        return [
            'scores' => $scores,
            'total' => $validas === [] ? null : (int) round(array_sum($validas) / count($validas)),
            'checks' => $checks,
        ];
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function allExercises(array $dias, callable $fn): bool
    {
        $todos = array_merge(...array_map(fn ($d) => $d['exercises'] ?? [], $dias ?: [[]]));

        return $todos !== [] && array_reduce($todos, fn ($ok, $e) => $ok && $fn($e), true);
    }

    private function rpeOk(string $notas, float $max): bool
    {
        if (! preg_match('/RPE ([\d.,\-– ]+)/u', $notas, $m)) {
            return true;
        }
        preg_match_all('/\d+(?:[.,]\d+)?/', $m[1], $nums);

        return $nums[0] === [] || max(array_map(fn ($x) => (float) str_replace(',', '.', $x), $nums[0])) <= $max;
    }

    /**
     * Cada ingrediente de la semana tiene su línea de mercado, con la MISMA
     * canonización que el consolidador (sinónimos, unidades convertidas y lo
     * que no se compra, como el agua).
     */
    private function shoppingCovers(array $semana, array $mercado): bool
    {
        $lista = [];
        foreach ($mercado as $l) {
            $lista[$this->assembler->shoppingKey($l['food'], $l['unit'])] = true;
        }
        foreach ($semana as $d) {
            foreach ($d['meals'] as $m) {
                foreach ($m['items'] as $it) {
                    $linea = $this->assembler->shoppingLine($it);
                    if ($linea !== null && ! isset($lista[$linea['key']])) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function conflictFree(string $texto, array $restricciones): bool
    {
        // Línea a línea, igual que la puerta de calidad: ingredientes,
        // preparación y sustitución.
        foreach (preg_split('/\n/', $texto) as $linea) {
            if ($this->lexicon->conflict($linea, $restricciones) !== null) {
                return false;
            }
        }

        return true;
    }

    /** Palabras con significado de un texto libre (sin las vacías). */
    private function keywords(string $t): array
    {
        $vacias = ['de', 'la', 'el', 'los', 'las', 'y', 'en', 'a', 'al', 'del', 'con', 'por', 'para', 'un', 'una', 'mas', 'muy'];

        return array_values(array_filter(explode(' ', $t), fn ($w) => mb_strlen($w) > 3 && ! in_array($w, $vacias, true)));
    }

    private function mentionsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($haystack, $this->norm($n))) {
                return true;
            }
        }

        return false;
    }

    private function mentionsAll(string $haystack, array $needles): bool
    {
        $h = $this->norm($haystack);
        foreach ($needles as $n) {
            if (! str_contains($h, $this->norm($n))) {
                return false;
            }
        }

        return true;
    }

    private function norm(string $s): string
    {
        return $this->lexicon->normalize($s);
    }
}
