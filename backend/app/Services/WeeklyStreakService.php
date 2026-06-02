<?php

namespace App\Services;

use App\Models\Member;
use App\Models\MemberAppActivityDay;
use App\Models\WeeklyStreakConfig;
use App\Models\WeeklyStreakReward;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de la racha semanal ("Esta semana").
 *
 * Reglas de negocio (PARTE 1 del brief):
 * - Un miembro suma como máximo 1 "día activo" por fecha calendario.
 * - La fecha/semana se calculan SIEMPRE en el servidor con timezone
 *   America/Bogota — la fecha del cliente nunca es fuente de verdad.
 * - La semana va de lunes a domingo.
 * - PostgreSQL es la fuente de verdad (tabla member_app_activity_days).
 */
class WeeklyStreakService
{
    /** Timezone de negocio para todos los cálculos de fecha. */
    public const TZ = 'America/Bogota';

    /** Días de la semana en orden lunes→domingo, con label corto ES. */
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    private const DAY_LABELS = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

    /**
     * Marca el día actual (Bogotá) como activo para el miembro. Idempotente:
     * si ya existe la fila para hoy no la duplica (upsert por índice único).
     */
    public function touch(Member $member, string $source = 'app_open'): array
    {
        $today = $this->today();

        // upsert idempotente: respeta el unique(member_id, activity_date).
        MemberAppActivityDay::query()->upsert(
            [[
                'member_id' => $member->id,
                'activity_date' => $today->toDateString(),
                'source' => $source,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['member_id', 'activity_date'], // claves de conflicto
            ['updated_at'] // no tocamos source si ya existía
        );

        $summary = $this->summary($member);

        // Evento de automatización: si la semana alcanzó la meta, avisa a n8n.
        // Idempotente por (member, semana) → solo se emite una vez por semana.
        if (($summary['active_days_this_week'] ?? 0) >= ($summary['weekly_goal_days'] ?? 5)
            && ($summary['weekly_goal_days'] ?? 0) > 0) {
            try {
                app(\App\Services\AutomationEventService::class)->emit(
                    'streak.completed',
                    $member->id,
                    [
                        'member' => ['id' => $member->id, 'name' => $member->full_name],
                        'streak' => [
                            'active_days' => $summary['active_days_this_week'],
                            'weekly_goal' => $summary['weekly_goal_days'],
                            'current_streak_days' => $summary['current_streak_days'] ?? 0,
                        ],
                    ],
                    'streak.completed:' . $member->id . ':' . ($summary['week_start'] ?? $today->toDateString()),
                );
            } catch (\Throwable $e) {
                // No rompemos el touch si la automatización falla.
            }
        }

        return $summary;
    }

    /**
     * Devuelve el resumen actual SIN registrar nada (idempotente, solo lectura).
     */
    public function summary(Member $member): array
    {
        $today = $this->today();
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(6);

        // Fechas activas de la semana (set para lookup O(1)).
        $weekDates = MemberAppActivityDay::query()
            ->where('member_id', $member->id)
            ->whereBetween('activity_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->pluck('activity_date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->all();
        $weekSet = array_flip($weekDates);

        // Mapa de días lunes→domingo.
        $days = [];
        $activeCount = 0;
        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->addDays($i);
            $isActive = isset($weekSet[$date->toDateString()]);
            if ($isActive) {
                $activeCount++;
            }
            $days[] = [
                'key' => self::DAY_KEYS[$i],
                'label' => self::DAY_LABELS[$i],
                'date' => $date->toDateString(),
                'active' => $isActive,
                'is_today' => $date->toDateString() === $today->toDateString(),
            ];
        }

        $config = WeeklyStreakConfig::activePrimary();
        $goal = $config?->weekly_goal_days ?? 5;

        $streaks = $this->computeStreaks($member, $today);

        return [
            'today_marked' => isset($weekSet[$today->toDateString()]),
            'active_days_this_week' => $activeCount,
            'weekly_goal_days' => $goal,
            'current_streak_days' => $streaks['current'],
            'longest_streak_days' => $streaks['longest'],
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'days' => $days,
            'config' => $this->serializeConfig($config),
            'rewards' => $this->serializeRewards($config, $activeCount),
        ];
    }

    /** Fecha "hoy" en timezone de negocio (no UTC, no fecha del cliente). */
    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /**
     * Racha actual (días consecutivos hasta hoy/ayer) y la más larga histórica.
     * La racha actual cuenta hacia atrás desde hoy; si hoy aún no está marcado
     * pero ayer sí, la racha sigue "viva" contando desde ayer.
     */
    private function computeStreaks(Member $member, CarbonImmutable $today): array
    {
        $dates = MemberAppActivityDay::query()
            ->where('member_id', $member->id)
            ->orderBy('activity_date')
            ->pluck('activity_date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->all();

        if (empty($dates)) {
            return ['current' => 0, 'longest' => 0];
        }

        $set = array_flip($dates);

        // Racha más larga: recorre fechas ordenadas contando consecutivos.
        $longest = 1;
        $run = 1;
        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $prev = CarbonImmutable::parse($dates[$i - 1]);
            $curr = CarbonImmutable::parse($dates[$i]);
            if ($prev->addDay()->toDateString() === $curr->toDateString()) {
                $run++;
                $longest = max($longest, $run);
            } else {
                $run = 1;
            }
        }

        // Racha actual: desde hoy (o ayer si hoy no marcado) hacia atrás.
        $current = 0;
        $cursor = $today;
        if (!isset($set[$today->toDateString()])) {
            $cursor = $today->subDay(); // hoy no marcado → la racha vive desde ayer
        }
        while (isset($set[$cursor->toDateString()])) {
            $current++;
            $cursor = $cursor->subDay();
        }

        return ['current' => $current, 'longest' => max($longest, $current)];
    }

    private function serializeConfig(?WeeklyStreakConfig $config): ?array
    {
        if ($config === null) {
            return null;
        }

        return [
            'title' => $config->title,
            'subtitle' => $config->subtitle,
            'weekly_goal_days' => $config->weekly_goal_days,
            'hero_title' => $config->hero_title,
            'hero_description' => $config->hero_description,
            'hero_image_url' => $config->hero_image_url,
            'promo_image_url' => $config->promo_image_url,
            'cta_label' => $config->cta_label,
            'cta_route' => $config->cta_route,
            'metadata' => $config->metadata,
        ];
    }

    /**
     * Beneficios activos con su estado calculado contra los días activos reales:
     *  - unlocked: activeDays >= required_days
     *  - in_progress: el siguiente beneficio por alcanzar (el más cercano arriba)
     *  - locked: el resto
     */
    private function serializeRewards(?WeeklyStreakConfig $config, int $activeDays): array
    {
        $query = WeeklyStreakReward::query()->active();
        if ($config !== null) {
            // Beneficios de esta config + los globales (config_id null).
            $query->where(function ($q) use ($config) {
                $q->where('config_id', $config->id)->orWhereNull('config_id');
            });
        } else {
            $query->whereNull('config_id');
        }

        $rewards = $query->orderBy('required_days')->orderBy('sort_order')->get();

        // El "en progreso" es el primer beneficio aún no desbloqueado.
        $nextLockedId = $rewards->firstWhere(fn ($r) => $activeDays < $r->required_days)?->id;

        return $rewards->map(function (WeeklyStreakReward $r) use ($activeDays, $nextLockedId) {
            if ($activeDays >= $r->required_days) {
                $status = 'unlocked';
            } elseif ($r->id === $nextLockedId) {
                $status = 'in_progress';
            } else {
                $status = 'locked';
            }

            return [
                'id' => $r->id,
                'required_days' => $r->required_days,
                'title' => $r->title,
                'description' => $r->description,
                'image_url' => $r->image_url,
                'badge_label' => $r->badge_label,
                'reward_type' => $r->reward_type,
                'status' => $status,
                'metadata' => $r->metadata,
            ];
        })->all();
    }
}
