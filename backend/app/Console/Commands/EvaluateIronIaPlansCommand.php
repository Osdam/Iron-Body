<?php

namespace App\Console\Commands;

use App\Models\IronAiUserProfile;
use App\Models\NutritionGoal;
use App\Services\Trainer\EligibleExerciseCatalog;
use App\Services\Trainer\IntegralPlanEvaluator;
use App\Services\Trainer\IntegralPlanGenerationService;
use App\Services\Trainer\IntegralPlanStrategy;
use App\Services\Trainer\MemberContextAssembler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Mide las potencias de Iron IA con perfiles FICTICIOS (docs/iron-ia/POTENCIAS-IRON-IA.md §6).
 *
 * Genera los niveles pedidos (por defecto 1, 3 y 5) de tres perfiles inventados
 * y deja un informe (JSON + markdown) con la puntuación de la rúbrica, los
 * tokens, la latencia y las diferencias entre niveles.
 *
 * SOLO LECTURA EN LA BASE. Lee el catálogo de ejercicios (es el que verá el
 * modelo en producción) y nada más: ni borradores, ni corridas en
 * `nutrition_ai_runs`, ni el guardián de coste. Los perfiles no son socios y
 * no se guardan. Lo único que escribe son los dos ficheros del informe.
 *
 * CUESTA DINERO. Sin `--run` no llama a nadie: enseña cuántas llamadas y qué
 * presupuesto de tokens haría cada nivel. Con `--run`, y solo si hay clave,
 * hace las llamadas reales.
 */
class EvaluateIronIaPlansCommand extends Command
{
    protected $signature = 'iron-ia:evaluate
        {--levels=1,3,5 : Niveles a generar (la misma potencia para nutrición y rutina)}
        {--profiles= : Perfiles a usar (claves separadas por coma; por defecto todos)}
        {--run : Hace las llamadas REALES a OpenAI (cuesta dinero). Sin esto solo enseña el plan de llamadas}
        {--out= : Carpeta del informe (por defecto storage/app/iron-ia/evaluations/<fecha>)}';

    protected $description = 'Evalúa la calidad, el coste y la latencia de las potencias de Iron IA con perfiles ficticios (no escribe en la base)';

    public function handle(
        IntegralPlanGenerationService $generator,
        MemberContextAssembler $assembler,
        IntegralPlanStrategy $strategy,
        EligibleExerciseCatalog $catalog,
    ): int {
        $niveles = array_values(array_unique(array_filter(
            array_map('intval', explode(',', (string) $this->option('levels'))),
            fn ($n) => $n >= 1 && $n <= 5,
        )));
        if ($niveles === []) {
            $this->error('--levels debe traer niveles de 1 a 5.');

            return self::INVALID;
        }

        $perfiles = self::profiles();
        if ($this->option('profiles')) {
            $pedidos = array_map('trim', explode(',', (string) $this->option('profiles')));
            $perfiles = array_intersect_key($perfiles, array_flip($pedidos));
        }
        if ($perfiles === []) {
            $this->error('Ningún perfil coincide. Disponibles: '.implode(', ', array_keys(self::profiles())));

            return self::INVALID;
        }

        $catalogo = $catalog->forPrompt();
        if ($catalogo === []) {
            $this->error('El catálogo de ejercicios está vacío: no hay con qué armar rutinas.');

            return self::FAILURE;
        }

        if (! $this->option('run')) {
            return $this->dryRun($perfiles, $niveles, $assembler, $strategy, $catalogo);
        }

        $openai = config('services.openai');
        if (empty($openai['enabled']) || empty($openai['api_key'])) {
            $this->error('No hay clave de OpenAI configurada (o Iron IA está apagada): no se ejecuta nada.');

            return self::FAILURE;
        }

        $resultados = [];
        foreach ($perfiles as $clave => $p) {
            [$contexto, $brief] = $this->build($p, $assembler);
            foreach ($niveles as $n) {
                $this->line("· {$clave} · nivel {$n}…");
                $r = $generator->preview($contexto, $brief, $catalogo, $n, $n);
                $resultados[$clave][$n] = $this->summarize($r);
                $s = $resultados[$clave][$n];
                $this->line(sprintf('  %s · puntuación %s · %s tokens · %s ms · %d llamadas',
                    $s['status'], $s['score'] ?? '—', $s['tokens']['total'] ?? '—', $s['latency_ms'], $s['calls']));
            }
        }

        $informe = [
            'generated_at' => now()->toIso8601String(),
            'model' => [
                'level_1' => config('services.openai.model'),
                'levels_2_5' => config('iron_ai.plan_powers.model') ?: config('services.openai.model'),
            ],
            'deadline_seconds' => (float) config('iron_ai.plan_powers.deadline_seconds', 17),
            'catalog_size' => count($catalogo),
            'profiles' => array_map(fn ($p) => $p['label'], $perfiles),
            'levels' => $niveles,
            'results' => $resultados,
            'differences' => $this->differences($resultados, $niveles),
        ];

        $dir = (string) ($this->option('out') ?: storage_path('app/iron-ia/evaluations/'.now()->format('Ymd-His')));
        File::ensureDirectoryExists($dir);
        File::put($dir.'/report.json', json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        File::put($dir.'/report.md', $this->markdown($informe));

        $this->info("Informe: {$dir}/report.json y {$dir}/report.md");

        return self::SUCCESS;
    }

    /**
     * Tres perfiles INVENTADOS que cubren lo que más cambia el plan: meta
     * guardada y restricción; datos parciales; señal clínica con dieta.
     *
     * @return array<string, array<string,mixed>>
     */
    public static function profiles(): array
    {
        return [
            'ana' => [
                'label' => 'Ana (ficticia): 28 años, bajar grasa, principiante, 3 días, sin lactosa, meta guardada',
                'member' => ['age_years' => 28, 'gender' => 'femenino', 'is_minor' => false],
                'body' => ['weight_kg' => 68.0, 'height_cm' => 162.0, 'body_fat_pct' => 31.0, 'muscle_mass_pct' => null],
                'goal' => 'Bajar grasa', 'level' => 'principiante', 'injuries' => null,
                'restrictions' => 'Intolerancia a la lactosa.',
                'notes' => ['observations' => 'Buena disposición; trabaja de pie.', 'recommendations' => null],
                'nutrition_goal' => ['daily_calories' => 1650, 'protein_g' => 120, 'carbs_g' => 170, 'fat_g' => 55, 'training_days_per_week' => 3, 'objective' => 'fat_loss'],
                'ai_profile' => ['nutrition_style' => null, 'preferences_summary' => 'Prefiere preparaciones rápidas.'],
            ],
            'carlos' => [
                'label' => 'Carlos (ficticio): 35 años, ganar masa, intermedio, sin peso registrado ni meta (datos parciales)',
                'member' => ['age_years' => 35, 'gender' => 'masculino', 'is_minor' => false],
                'body' => ['weight_kg' => null, 'height_cm' => 176.0, 'body_fat_pct' => null, 'muscle_mass_pct' => null],
                'goal' => 'Ganar masa muscular', 'level' => 'intermedio', 'injuries' => 'Hombro derecho sensible en press por encima de la cabeza',
                'restrictions' => null,
                'notes' => ['observations' => null, 'recommendations' => null],
                'nutrition_goal' => null,
                'ai_profile' => null,
            ],
            'luisa' => [
                'label' => 'Luisa (ficticia): 41 años, vegetariana, hipertensión en las notas (señal clínica), 4 días',
                'member' => ['age_years' => 41, 'gender' => 'femenino', 'is_minor' => false],
                'body' => ['weight_kg' => 74.0, 'height_cm' => 165.0, 'body_fat_pct' => 34.0, 'muscle_mass_pct' => null],
                'goal' => 'Mejorar la salud y bajar de peso', 'level' => 'intermedio', 'injuries' => null,
                'restrictions' => 'Vegetariana. Sin cerdo.',
                'notes' => ['observations' => 'Refiere hipertensión controlada con medicación.', 'recommendations' => 'Control de presión antes de entrenar.'],
                'nutrition_goal' => ['daily_calories' => 1800, 'protein_g' => 110, 'carbs_g' => 200, 'fat_g' => 60, 'training_days_per_week' => 4, 'objective' => 'fat_loss'],
                'ai_profile' => ['nutrition_style' => 'Vegetariana', 'preferences_summary' => null],
            ],
        ];
    }

    /**
     * El mismo contexto que vería el generador para un socio así, armado SIN
     * tocar la base: modelos sin guardar y la parte pura del ensamblador.
     *
     * @param  array<string,mixed>  $p
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function build(array $p, MemberContextAssembler $assembler): array
    {
        $contexto = [
            'member' => ['id' => null, 'full_name' => null] + $p['member'],
            'body_composition' => $p['body'] + ['visceral_fat' => null, 'age_years' => $p['member']['age_years'], 'basal_kcal' => null],
            'measurements' => ['waist_cm' => null, 'hip_cm' => null, 'chest_cm' => null, 'arm_cm' => null, 'leg_cm' => null],
            'goal' => ['objective' => $p['goal'], 'objective_description' => null, 'training_stage' => null],
            'training' => ['training_level' => $p['level'], 'injuries' => $p['injuries']],
            'nutrition_constraints' => ['restrictions' => $p['restrictions'], 'supplements' => null, 'injuries' => $p['injuries']],
            'trainer_notes' => $p['notes'],
            'sources' => [],
        ];

        $meta = $p['nutrition_goal'] ? new NutritionGoal($p['nutrition_goal'] + ['status' => 'complete', 'is_active' => true]) : null;
        $perfil = $p['ai_profile'] ? new IronAiUserProfile($p['ai_profile']) : null;

        return [$contexto, $assembler->buildBrief($contexto, $meta, $perfil)];
    }

    private function dryRun(array $perfiles, array $niveles, MemberContextAssembler $assembler, IntegralPlanStrategy $strategy, array $catalogo): int
    {
        $this->warn('Simulación: no se llama a nadie. Añade --run para hacer las llamadas reales (cuestan dinero).');
        $filas = [];
        foreach ($perfiles as $clave => $p) {
            [, $brief] = $this->build($p, $assembler);
            foreach ($niveles as $n) {
                if ($n === 1) {
                    $filas[] = [$clave, 1, 1, 3000, '—'];

                    continue;
                }
                $secciones = $strategy->sections([], $strategy->plan($n, $n, $brief), $catalogo);
                $filas[] = [$clave, $n, count($secciones), array_sum(array_column($secciones, 'max_tokens')), $brief['safety_mode'] ? 'sí' : 'no'];
            }
        }
        $this->table(['perfil', 'nivel', 'llamadas', 'tope de tokens de salida', 'modo seguro'], $filas);

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function summarize(array $r): array
    {
        $plan = $r['plan'] ?? null;
        $comidas = $plan['nutrition']['meals'] ?? [];
        $dias = $plan['routine']['days'] ?? [];
        $ejercicios = array_merge(...array_map(fn ($d) => $d['exercises'] ?? [], $dias ?: [[]]));

        return [
            'status' => $r['status'],
            'error_code' => $r['error_code'],
            'message' => $r['message'],
            'score' => $r['evaluation']['total'] ?? null,
            'scores' => $r['evaluation']['scores'] ?? null,
            'failed_checks' => array_values(array_column(array_filter($r['evaluation']['checks'] ?? [], fn ($c) => ! $c['ok']), 'id')),
            'tokens' => [
                'prompt' => $r['usage']['prompt_tokens'] ?? null,
                'completion' => $r['usage']['completion_tokens'] ?? null,
                'total' => $r['usage']['total_tokens'] ?? null,
            ],
            'calls' => (int) ($r['usage']['calls'] ?? 1),
            'repairs' => (int) ($r['usage']['repairs'] ?? 0),
            'repairs_skipped_reason' => $r['repairs_skipped_reason'] ?? null,
            'latency_ms' => $r['latency_ms'],
            'meals' => count($comidas),
            'avg_meal_chars' => $comidas === [] ? 0 : (int) round(array_sum(array_map(fn ($c) => mb_strlen($c['description']), $comidas)) / count($comidas)),
            'routine_days' => count($dias),
            'exercises' => count($ejercicios),
            'avg_note_chars' => $ejercicios === [] ? 0 : (int) round(array_sum(array_map(fn ($e) => mb_strlen((string) ($e['notes'] ?? '')), $ejercicios)) / count($ejercicios)),
            'plan' => $plan,
        ];
    }

    /** Lo que cambia cada nivel respecto al más bajo evaluado, por perfil. */
    private function differences(array $resultados, array $niveles): array
    {
        sort($niveles);
        $base = $niveles[0];
        $out = [];
        foreach ($resultados as $clave => $porNivel) {
            foreach ($niveles as $n) {
                if ($n === $base || ! isset($porNivel[$n], $porNivel[$base])) {
                    continue;
                }
                $a = $porNivel[$base];
                $b = $porNivel[$n];
                $out[$clave]["{$base}->{$n}"] = [
                    'score' => ($b['score'] ?? 0) - ($a['score'] ?? 0),
                    'tokens' => ($b['tokens']['total'] ?? 0) - ($a['tokens']['total'] ?? 0),
                    'latency_ms' => $b['latency_ms'] - $a['latency_ms'],
                    'calls' => $b['calls'] - $a['calls'],
                    'meals' => $b['meals'] - $a['meals'],
                    'avg_meal_chars' => $b['avg_meal_chars'] - $a['avg_meal_chars'],
                    'exercises' => $b['exercises'] - $a['exercises'],
                    'avg_note_chars' => $b['avg_note_chars'] - $a['avg_note_chars'],
                ];
            }
        }

        return $out;
    }

    private function markdown(array $i): string
    {
        $md = "# Evaluación de las potencias de Iron IA\n\n";
        $md .= "Generado: {$i['generated_at']} · modelo nivel 1: {$i['model']['level_1']} · niveles 2–5: {$i['model']['levels_2_5']} · plazo: {$i['deadline_seconds']} s · catálogo: {$i['catalog_size']} ejercicios.\n\n";
        $md .= "Perfiles ficticios; nada de esto se guardó en la base.\n\n";

        foreach ($i['results'] as $clave => $porNivel) {
            $md .= "## {$i['profiles'][$clave]}\n\n";
            $md .= "| Nivel | Estado | Puntuación | Cobertura | Personalización | Coherencia | Seguridad | Utilidad | Tokens (entrada/salida) | Latencia | Llamadas | Reparaciones | Comidas | Ejercicios |\n";
            $md .= "|---|---|---|---|---|---|---|---|---|---|---|---|---|---|\n";
            foreach ($porNivel as $n => $r) {
                $s = $r['scores'] ?? [];
                $md .= sprintf("| %d | %s | %s | %s | %s | %s | %s | %s | %s / %s | %d ms | %d | %d | %d | %d |\n",
                    $n, $r['status'].($r['error_code'] ? " ({$r['error_code']})" : ''), $r['score'] ?? '—',
                    $s['coverage'] ?? '—', $s['personalization'] ?? '—', $s['coherence'] ?? '—', $s['safety'] ?? '—', $s['usefulness'] ?? '—',
                    $r['tokens']['prompt'] ?? '—', $r['tokens']['completion'] ?? '—', $r['latency_ms'], $r['calls'], $r['repairs'], $r['meals'], $r['exercises']);
            }
            foreach ($porNivel as $n => $r) {
                if ($r['failed_checks'] !== []) {
                    $md .= "\n- Nivel {$n}, comprobaciones que fallan: ".implode(', ', $r['failed_checks']).'.';
                }
            }
            foreach ($i['differences'][$clave] ?? [] as $par => $d) {
                $md .= sprintf("\n- %s: puntuación %+d, tokens %+d, latencia %+d ms, llamadas %+d, comidas %+d, ejercicios %+d.",
                    $par, $d['score'], $d['tokens'], $d['latency_ms'], $d['calls'], $d['meals'], $d['exercises']);
            }
            $md .= "\n\n";
        }

        $md .= 'Rúbrica: '.implode(', ', IntegralPlanEvaluator::DIMENSIONS).". Una comprobación que no aplica al nivel no cuenta.\n";

        return $md;
    }
}
