<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\NutritionMealLog;
use App\Models\RoutineCompletion;
use App\Models\User;
use App\Services\AutomationEventService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Escanea el estado real de los miembros y emite eventos de automatización de
 * BAJO RIESGO hacia n8n (vía AutomationEventService). Pensado para correr a
 * diario. Payloads mínimos: nunca documento, biometría, firma ni pagos.
 *
 *   php artisan ironbody:emit-automation-events
 *   php artisan ironbody:emit-automation-events --only=membership.expiring
 *
 * Idempotencia: cada evento usa una key con la fecha del día → correrlo varias
 * veces el mismo día NO duplica.
 */
class EmitAutomationEvents extends Command
{
    protected $signature = 'ironbody:emit-automation-events {--only= : Emitir solo un tipo de evento} {--expiring-days=3} {--nutrition-days=2} {--workout-days=3}';

    protected $description = 'Escanea y emite eventos de automatización de bajo riesgo hacia n8n.';

    public function handle(AutomationEventService $events): int
    {
        $tz = 'America/Bogota';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $dateTag = $today->toDateString();
        $only = $this->option('only');

        $want = fn (string $type) => $only === null || $only === $type;
        $count = 0;

        // ── membership.expiring ──────────────────────────────────────────────
        if ($want('membership.expiring')) {
            $days = max(1, (int) $this->option('expiring-days'));
            $limit = $today->addDays($days);
            $users = User::query()
                ->whereNotNull('membership_end_date')
                ->whereDate('membership_end_date', '>=', $today->toDateString())
                ->whereDate('membership_end_date', '<=', $limit->toDateString())
                ->get();

            foreach ($users as $user) {
                $member = Member::where('user_id', $user->id)->first();
                if (!$member) {
                    continue;
                }
                $expiresInDays = $today->diffInDays(CarbonImmutable::parse($user->membership_end_date));
                $events->emit('membership.expiring', $member->id, [
                    'member' => ['id' => $member->id, 'name' => $member->full_name],
                    'membership' => [
                        'plan' => $user->plan_name ?? $user->name ?? null,
                        'expires_in_days' => (int) $expiresInDays,
                    ],
                ], "membership.expiring:{$member->id}:{$dateTag}");
                $count++;
            }
        }

        // ── nutrition.missing (sin registrar comida en N días) ───────────────
        if ($want('nutrition.missing')) {
            $missingDays = max(1, (int) $this->option('nutrition-days'));
            $since = $today->subDays($missingDays);
            Member::query()->where('status', Member::STATUS_ACTIVE)->chunkById(200, function ($members) use ($events, $since, $missingDays, $dateTag, &$count) {
                foreach ($members as $member) {
                    $hasRecent = NutritionMealLog::query()
                        ->where('member_id', $member->id)
                        ->whereDate('log_date', '>=', $since->toDateString())
                        ->whereHas('items')
                        ->exists();
                    if ($hasRecent) {
                        continue;
                    }
                    $events->emit('nutrition.missing', $member->id, [
                        'member' => ['id' => $member->id, 'name' => $member->full_name],
                        'nutrition' => ['missing_days' => $missingDays],
                    ], "nutrition.missing:{$member->id}:{$dateTag}");
                    $count++;
                }
            });
        }

        // ── workout.missed (sin entrenar en N días) ──────────────────────────
        if ($want('workout.missed')) {
            $missedDays = max(1, (int) $this->option('workout-days'));
            $since = $today->subDays($missedDays);
            Member::query()->where('status', Member::STATUS_ACTIVE)->chunkById(200, function ($members) use ($events, $since, $missedDays, $dateTag, &$count) {
                foreach ($members as $member) {
                    $hasRecent = RoutineCompletion::query()
                        ->where('member_id', $member->id)
                        ->where('completed_at', '>=', $since)
                        ->exists();
                    if ($hasRecent) {
                        continue;
                    }
                    $events->emit('workout.missed', $member->id, [
                        'member' => ['id' => $member->id, 'name' => $member->full_name],
                        'workouts' => ['missed_days' => $missedDays],
                    ], "workout.missed:{$member->id}:{$dateTag}");
                    $count++;
                }
            });
        }

        // ── member.registration_abandoned (registro incompleto >24h) ─────────
        if ($want('member.registration_abandoned')) {
            $cutoff = CarbonImmutable::now()->subHours(24);
            Member::query()
                ->whereIn('status', [Member::STATUS_PENDING_REGISTRATION, Member::STATUS_INCOMPLETE])
                ->where('created_at', '<=', $cutoff)
                ->where('created_at', '>=', $cutoff->subDays(7)) // ventana razonable
                ->chunkById(200, function ($members) use ($events, $dateTag, &$count) {
                    foreach ($members as $member) {
                        $events->emit('member.registration_abandoned', $member->id, [
                            'member' => ['id' => $member->id, 'name' => $member->full_name],
                            'registration' => ['status' => $member->status],
                        ], "member.registration_abandoned:{$member->id}:{$dateTag}");
                        $count++;
                    }
                });
        }

        // ── evaluation.outdated (sin evaluación física en 60+ días) ──────────
        if ($want('evaluation.outdated')) {
            $monthTag = $today->format('Y-m');
            $cutoff = $today->subDays(60);
            Member::query()->where('status', Member::STATUS_ACTIVE)->chunkById(200, function ($members) use ($events, $cutoff, $monthTag, &$count) {
                foreach ($members as $member) {
                    $last = \App\Models\PhysicalEvaluation::query()
                        ->where('member_id', $member->id)
                        ->latest('created_at')
                        ->value('created_at');
                    // Si nunca tuvo evaluación o la última es vieja → outdated.
                    if ($last !== null && CarbonImmutable::parse($last)->greaterThan($cutoff)) {
                        continue;
                    }
                    $events->emit('evaluation.outdated', $member->id, [
                        'member' => ['id' => $member->id, 'name' => $member->full_name],
                        'evaluation' => ['has_any' => $last !== null],
                    ], "evaluation.outdated:{$member->id}:{$monthTag}");
                    $count++;
                }
            });
        }

        // ── progress.stalled (sin cambio de peso en las últimas 2 evals) ─────
        if ($want('progress.stalled')) {
            $weekTag = $today->format('o-\WW');
            Member::query()->where('status', Member::STATUS_ACTIVE)->chunkById(200, function ($members) use ($events, $weekTag, &$count) {
                foreach ($members as $member) {
                    $weights = \App\Models\PhysicalEvaluation::query()
                        ->where('member_id', $member->id)
                        ->whereNotNull('weight_kg')
                        ->latest('created_at')
                        ->limit(2)
                        ->pluck('weight_kg');
                    // Necesita ≥2 evaluaciones con peso para juzgar estancamiento.
                    if ($weights->count() < 2) {
                        continue;
                    }
                    $delta = abs((float) $weights[0] - (float) $weights[1]);
                    if ($delta > 0.3) {
                        continue; // hubo cambio → no estancado
                    }
                    $events->emit('progress.stalled', $member->id, [
                        'member' => ['id' => $member->id, 'name' => $member->full_name],
                        'progress' => ['weight_delta_kg' => round($delta, 1)],
                    ], "progress.stalled:{$member->id}:{$weekTag}");
                    $count++;
                }
            });
        }

        $this->info("Eventos emitidos: {$count}" . ($only ? " (solo {$only})" : ''));
        return self::SUCCESS;
    }
}
