<?php

namespace App\Console\Commands;

use App\Models\ExerciseAlias;
use App\Models\Routine;
use App\Models\RoutineExercise;
use App\Services\Exercises\ExerciseCatalogResolver;
use Illuminate\Console\Command;

/**
 * Audita TODOS los nombres de ejercicios usados por rutinas contra el catálogo
 * real y reporta su estado de vinculación. Con --apply-safe persiste aliases
 * verificados SOLO para matches seguros (nunca tokens/ambiguos).
 */
class ExerciseMediaAuditCommand extends Command
{
    protected $signature = 'ironbody:exercise-media-audit
                            {--apply-safe : Crea aliases verificados para matches seguros (id/alias/nombre exacto)}
                            {--limit=0 : Limita filas mostradas (0 = todas)}';

    protected $description = 'Audita la vinculación de ejercicios de rutinas con el catálogo (video/media).';

    public function handle(ExerciseCatalogResolver $resolver): int
    {
        $resolver->refresh();

        // name normalizado → ['name'=>display, 'count'=>n, 'sources'=>set, 'routine_id'=>first, 'exercise_id'=>?]
        $usages = [];

        $track = function (string $name, ?int $exerciseId, string $source, int $routineId) use (&$usages, $resolver): void {
            $name = trim($name);
            if ($name === '') {
                return;
            }
            $key = $resolver->normalize($name) . '|' . ($exerciseId ?? '');
            if (! isset($usages[$key])) {
                $usages[$key] = [
                    'name' => $name, 'exercise_id' => $exerciseId,
                    'count' => 0, 'sources' => [], 'routine_id' => $routineId,
                ];
            }
            $usages[$key]['count']++;
            $usages[$key]['sources'][$source] = true;
        };

        // routines.exercises (JSON) + routines.days (JSON)
        Routine::query()->select(['id', 'exercises', 'days'])->chunk(200, function ($routines) use ($track) {
            foreach ($routines as $r) {
                foreach ((is_array($r->exercises) ? $r->exercises : []) as $ex) {
                    if (is_array($ex)) {
                        $track((string) ($ex['name'] ?? ''), isset($ex['exercise_id']) ? (int) $ex['exercise_id'] : null, 'exercises', (int) $r->id);
                    }
                }
                foreach ((is_array($r->days) ? $r->days : []) as $day) {
                    foreach ((is_array($day['exercises'] ?? null) ? $day['exercises'] : []) as $ex) {
                        if (is_array($ex)) {
                            $track((string) ($ex['name'] ?? ''), isset($ex['exercise_id']) ? (int) $ex['exercise_id'] : null, 'days', (int) $r->id);
                        }
                    }
                }
            }
        });

        // routine_exercises (normalizada): por exercise_id (ya vinculados).
        RoutineExercise::query()->with('exercise:id,name')->chunk(200, function ($items) use ($track) {
            foreach ($items as $re) {
                $name = $re->exercise->name ?? '';
                $track((string) $name, (int) $re->exercise_id, 'routine_exercises', (int) $re->routine_id);
            }
        });

        $rows = [];
        $stats = ['matched' => 0, 'needs_review' => 0, 'ambiguous' => 0, 'no_candidate' => 0];
        $aliasesCreated = 0;
        $applySafe = (bool) $this->option('apply-safe');

        foreach ($usages as $u) {
            $audit = $resolver->audit($u['exercise_id'], $u['name']);
            $status = $audit['status'];
            $stats[$status] = ($stats[$status] ?? 0) + 1;

            $matchTxt = $audit['exercise']
                ? "#{$audit['exercise']->id} {$audit['exercise']->name} ({$audit['method']})"
                : '—';

            $cands = collect($audit['candidates'])
                ->map(fn ($c) => "#{$c['id']} {$c['name']} [{$c['score']}]")
                ->implode(' · ');

            $rows[] = [
                'routine' => $u['routine_id'],
                'source'  => implode('/', array_keys($u['sources'])),
                'name'    => mb_strimwidth($u['name'], 0, 38, '…'),
                'x'       => $u['count'],
                'status'  => $status,
                'match'   => $matchTxt,
                'top5'    => $cands !== '' ? mb_strimwidth($cands, 0, 60, '…') : '—',
            ];

            // --apply-safe: registra alias verificado SOLO en match seguro y solo
            // cuando es un alias genuino (nombre distinto al del catálogo).
            if ($applySafe && $status === 'matched' && $audit['exercise']) {
                $aliasNorm = $resolver->normalize($u['name']);
                $exactNorm = $resolver->normalize($audit['exercise']->name);
                if ($aliasNorm !== '' && $aliasNorm !== $exactNorm) {
                    $created = ExerciseAlias::query()->firstOrCreate(
                        ['normalized_alias' => $aliasNorm, 'exercise_id' => (int) $audit['exercise']->id],
                        ['alias_name' => $u['name'], 'source' => 'audit', 'confidence' => 1.000, 'is_verified' => true],
                    );
                    if ($created->wasRecentlyCreated) {
                        $aliasesCreated++;
                    }
                }
            }
        }

        // Orden: primero lo que requiere acción.
        $order = ['ambiguous' => 0, 'needs_review' => 1, 'no_candidate' => 2, 'matched' => 3];
        usort($rows, fn ($a, $b) => ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9));

        $limit = (int) $this->option('limit');
        $shown = $limit > 0 ? array_slice($rows, 0, $limit) : $rows;

        $this->table(
            ['routine', 'source', 'name', '×', 'status', 'match', 'top5'],
            array_map(fn ($r) => array_values($r), $shown),
        );

        $this->newLine();
        $this->info(sprintf(
            'Total: %d nombres únicos · matched=%d · needs_review=%d · ambiguous=%d · no_candidate=%d',
            count($usages), $stats['matched'], $stats['needs_review'], $stats['ambiguous'], $stats['no_candidate'],
        ));
        if ($applySafe) {
            $this->info("Aliases verificados creados: {$aliasesCreated}");
            $resolver->refresh();
        } else {
            $this->comment('Solo lectura. Usa --apply-safe para persistir aliases de matches seguros.');
        }

        return self::SUCCESS;
    }
}
