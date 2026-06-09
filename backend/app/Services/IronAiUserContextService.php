<?php

namespace App\Services;

use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\NutritionAiRecommendation;
use App\Models\PhysicalEvaluation;
use App\Models\RoutineCompletion;
use Carbon\CarbonImmutable;

/**
 * Capa de CONTEXTO GLOBAL de IRON IA.
 *
 * Construye, desde PostgreSQL, un resumen SEGURO y mínimo del usuario para que
 * la IA pueda guiarlo hacia su meta. Reutilizable por: Nutrición IA, Progreso
 * IA, chat IRON IA, notificaciones inteligentes y workflows n8n — para no
 * duplicar la lógica de contexto en cada módulo.
 *
 * **Seguridad (lo que NUNCA incluye):** tokens, hashes, contraseñas, IDs
 * internos innecesarios, datos de otros usuarios, información sensible no
 * requerida. Solo lo necesario para una recomendación útil.
 */
class IronAiUserContextService
{
    public function __construct(
        private readonly NutritionService $nutrition,
        private readonly ProgressSummaryService $progress,
        private readonly WeeklyStreakService $streak,
        private readonly GymEquipmentContextService $equipment,
    ) {
    }

    /**
     * Contexto modular del usuario. `$modules` permite pedir solo lo necesario
     * (p. ej. nutrición + progreso para el coach nutricional).
     *
     * @param array<int,string> $modules profile|membership|workouts|streak|nutrition|progress|evaluation|classes|last_ai_summary|gym_equipment
     */
    public function build(Member $member, array $modules = []): array
    {
        $all = empty($modules);
        $want = fn (string $m) => $all || in_array($m, $modules, true);

        $ctx = [];

        if ($want('profile')) {
            $ctx['profile'] = $this->profile($member);
        }
        if ($want('membership')) {
            $ctx['membership'] = $this->membership($member);
        }
        if ($want('workouts')) {
            $ctx['workouts'] = $this->workouts($member);
        }
        if ($want('streak')) {
            $s = $this->streak->summary($member);
            $ctx['weekly_streak'] = [
                'current_days' => $s['current_streak_days'] ?? 0,
                'active_this_week' => $s['active_days_this_week'] ?? 0,
                'weekly_goal' => $s['weekly_goal_days'] ?? 0,
            ];
        }
        if ($want('nutrition')) {
            $ctx['nutrition'] = $this->nutritionContext($member);
        }
        if ($want('progress') || $want('evaluation')) {
            $ctx['progress'] = $this->progressContext($member, $want('evaluation'));
        }
        if ($want('classes')) {
            $ctx['classes'] = $this->classesContext($member);
        }
        if ($want('last_ai_summary')) {
            $ctx['last_ai_summary'] = $this->lastAiSummary($member);
        }
        // Equipos reales del gimnasio: la IA NO debe recomendar ejercicios que
        // requieran máquinas inexistentes. Opt-in (no se incluye salvo que se pida).
        if ($want('gym_equipment')) {
            $ctx['gym_equipment'] = $this->equipment->availableNames();
        }

        return $ctx;
    }

    private function profile(Member $member): array
    {
        // Edad en rango (no fecha exacta) — dato mínimo para fitness, no PII fina.
        $ageRange = null;
        if ($member->birth_date !== null) {
            $age = CarbonImmutable::parse($member->birth_date)->age;
            $ageRange = match (true) {
                $age < 18 => 'menor',
                $age < 26 => '18-25',
                $age < 36 => '26-35',
                $age < 46 => '36-45',
                $age < 56 => '46-55',
                default => '56+',
            };
        }

        // Observaciones del entrenador de la última evaluación (si existen).
        $trainerNotes = PhysicalEvaluation::query()
            ->where('member_id', $member->id)
            ->whereNotNull('trainer_notes')
            ->latest('created_at')
            ->value('trainer_notes');

        return [
            'name' => $member->full_name,
            'age_range' => $ageRange,
            'gender' => $member->gender,
            'goal' => $member->goal,                  // objetivo declarado por el miembro
            'training_level' => $member->training_level,
            'injuries' => $member->injuries,          // restricciones/lesiones declaradas
            'trainer_notes' => $trainerNotes,         // observaciones del entrenador (no diagnóstico)
        ];
    }

    private function classesContext(Member $member): array
    {
        $today = CarbonImmutable::now(NutritionService::TZ);
        return [
            'reservations_last_30d' => ClassReservation::query()
                ->where('member_id', $member->id)
                ->where('reserved_at', '>=', $today->subDays(30))
                ->count(),
            'next_reservation_at' => ClassReservation::query()
                ->where('member_id', $member->id)
                ->where('reserved_at', '>=', $today)
                ->orderBy('reserved_at')
                ->value('reserved_at')?->toIso8601String(),
        ];
    }

    /** Último resumen IA generado (solo el texto, para dar continuidad). */
    private function lastAiSummary(Member $member): ?array
    {
        $last = NutritionAiRecommendation::query()
            ->where('member_id', $member->id)
            ->latest('created_at')
            ->first();

        if ($last === null) {
            return null;
        }

        return [
            'date' => $last->recommendation_date?->toDateString(),
            'summary' => $last->summary,
        ];
    }

    private function membership(Member $member): array
    {
        $user = $member->user ?? null;
        return [
            'is_premium' => $user?->status === 'active',
            'status' => $user?->status,
        ];
    }

    private function workouts(Member $member): array
    {
        $today = CarbonImmutable::now(NutritionService::TZ);
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);
        return [
            'completed_this_week' => RoutineCompletion::query()
                ->where('member_id', $member->id)
                ->where('completed_at', '>=', $weekStart)
                ->count(),
            'completed_last_30d' => RoutineCompletion::query()
                ->where('member_id', $member->id)
                ->where('completed_at', '>=', $today->subDays(30))
                ->count(),
        ];
    }

    private function nutritionContext(Member $member): array
    {
        $day = $this->nutrition->dayPayload($member);
        return [
            'goal' => $day['goal'],
            'consumed_today' => $day['consumed'],
            'remaining_today' => $day['remaining'],
            'meals_logged_today' => collect($day['meals'])
                ->filter(fn ($m) => count($m['items']) > 0)
                ->pluck('meal_type')
                ->values()
                ->all(),
            'streak_days' => $day['streak']['current'] ?? 0,
            'has_logged_today' => $day['streak']['has_logged_today'] ?? false,
        ];
    }

    private function progressContext(Member $member, bool $includeEvaluation): array
    {
        $latest = PhysicalEvaluation::query()
            ->where('member_id', $member->id)
            ->latest('created_at')
            ->first();

        $out = [
            'current_weight_kg' => $latest?->weight_kg,
            'bmi' => $latest?->bmi(),
            'bmi_label' => $latest?->bmiLabel(),
            'has_evaluation' => $latest !== null,
        ];

        if ($includeEvaluation && $latest !== null) {
            $out['evaluation'] = [
                'body_fat_pct' => $latest->body_fat_pct,
                'muscle_mass_pct' => $latest->muscle_mass_pct,
            ];
        }

        return $out;
    }
}
