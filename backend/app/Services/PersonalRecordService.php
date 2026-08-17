<?php

namespace App\Services;

use App\Models\Member;
use App\Models\PersonalRecord;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionSet;
use Illuminate\Support\Collection;

/**
 * Deriva los récords personales de las series realmente ejecutadas.
 *
 * REGLA (documentada aquí porque el producto no tenía ninguna):
 *
 *  - Solo entran series COMPLETADAS con carga > 0 y repeticiones > 0. Un
 *    ejercicio sin carga no produce récord: no hay métrica definida para él.
 *  - Métricas por ejercicio:
 *      · `max_weight`     → la carga más alta levantada en una serie.
 *      · `estimated_1rm`  → 1RM estimada con Epley: peso × (1 + reps/30),
 *        limitada a series de 1 a 12 repeticiones, que es el rango donde la
 *        fórmula es razonable. Por encima de 12 la estimación se dispara y no
 *        se registra.
 *  - La PRIMERA serie válida de un ejercicio establece el récord base. A partir
 *    de ahí solo se sustituye cuando el valor SUPERA estrictamente al vigente:
 *    repetir el mismo entrenamiento no genera un récord nuevo ni duplicado.
 *  - El récord vigente es único por (miembro, ejercicio, métrica) gracias al
 *    índice único, así que un reintento tampoco puede duplicarlo.
 *
 * Los récords que registre un entrenador (`source = 'trainer'`) conviven en la
 * misma tabla y NO se pisan desde aquí: si el récord vigente lo puso el
 * entrenador, el entrenamiento solo lo sustituye si de verdad lo supera.
 */
class PersonalRecordService
{
    /** Rango de repeticiones donde la fórmula de Epley es utilizable. */
    private const EPLEY_MIN_REPS = 1;

    private const EPLEY_MAX_REPS = 12;

    /**
     * Evalúa las series de una sesión y devuelve los récords nuevos o mejorados.
     *
     * @return Collection<int, PersonalRecord>
     */
    public function evaluateSession(Member $member, WorkoutSession $session): Collection
    {
        $achieved = collect();

        // Mejor serie por ejercicio y métrica dentro de ESTA sesión: así una
        // sesión con 4 series del mismo ejercicio produce como mucho un récord
        // por métrica, no cuatro escrituras encadenadas.
        $best = [];

        foreach ($session->sets as $set) {
            if (! $set->completed) {
                continue;
            }

            $weight = (float) ($set->weight_kg ?? 0);
            $reps = (int) ($set->reps ?? 0);
            if ($weight <= 0 || $reps <= 0) {
                continue;
            }

            $key = $set->exercise_key;

            $this->considerCandidate($best, $key, PersonalRecord::METRIC_MAX_WEIGHT, $weight, $set);

            if ($reps >= self::EPLEY_MIN_REPS && $reps <= self::EPLEY_MAX_REPS) {
                $oneRm = round($weight * (1 + $reps / 30), 2);
                $this->considerCandidate($best, $key, PersonalRecord::METRIC_ESTIMATED_1RM, $oneRm, $set);
            }
        }

        foreach ($best as $candidate) {
            $record = $this->applyCandidate($member, $session, $candidate);
            if ($record !== null) {
                $achieved->push($record);
            }
        }

        return $achieved;
    }

    /** Guarda el mejor candidato de la sesión por (ejercicio, métrica). */
    private function considerCandidate(array &$best, string $key, string $metric, float $value, WorkoutSessionSet $set): void
    {
        $slot = $key.'|'.$metric;
        if (! isset($best[$slot]) || $value > $best[$slot]['value']) {
            $best[$slot] = ['metric' => $metric, 'value' => $value, 'set' => $set];
        }
    }

    /**
     * Crea el récord si no existía, o lo sustituye solo si el candidato supera
     * al vigente. Devuelve el récord cuando hubo marca; null si no mejoró.
     */
    private function applyCandidate(Member $member, WorkoutSession $session, array $candidate): ?PersonalRecord
    {
        /** @var WorkoutSessionSet $set */
        $set = $candidate['set'];
        $metric = $candidate['metric'];
        $value = $candidate['value'];

        $existing = PersonalRecord::query()
            ->where('member_id', $member->id)
            ->where('exercise_key', $set->exercise_key)
            ->where('metric', $metric)
            ->first();

        if ($existing !== null && (float) $existing->value >= $value) {
            return null; // no supera el vigente: no es récord
        }

        $attributes = [
            'exercise_id' => $set->exercise_id,
            'exercise_name' => $set->exercise_name,
            'metric' => $metric,
            'value' => $value,
            'unit' => 'kg',
            'reps' => $set->reps,
            'weight_kg' => $set->weight_kg,
            'previous_value' => $existing?->value,
            'achieved_at' => $set->performed_at ?? $session->completed_at,
            'source' => PersonalRecord::SOURCE_WORKOUT,
            'workout_session_id' => $session->id,
            'workout_session_set_id' => $set->id,
            'trainer_id' => null,
        ];

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return $existing->refresh();
        }

        return PersonalRecord::create(array_merge($attributes, [
            'member_id' => $member->id,
            'exercise_key' => $set->exercise_key,
        ]));
    }

    /**
     * Récords vigentes del miembro, más recientes primero. Los consume tanto la
     * pantalla de Progreso como el contexto de IRON IA.
     *
     * @return Collection<int, PersonalRecord>
     */
    public function forMember(Member $member, int $limit = 10): Collection
    {
        return PersonalRecord::query()
            ->where('member_id', $member->id)
            ->orderByDesc('achieved_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
