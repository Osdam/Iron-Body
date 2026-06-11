<?php

namespace App\Services\Nutrition;

/**
 * Traduce los valores que el CRM/registro guardan en español ("Hipertrofia
 * muscular", "Masculino", "Principiante"…) a las claves canónicas que usa la
 * calculadora. Tolerante a tildes, mayúsculas, slugs y claves ya en inglés.
 *
 * No inventa datos: si no reconoce un valor, devuelve null y el llamador decide
 * (normalmente → setup_required para que el usuario lo confirme).
 */
class NutritionGoalMapper
{
    /** "Masculino"|"Femenino"|"Otro" → male|female|null (Otro requiere elección). */
    public function metabolicSex(?string $gender): ?string
    {
        return match ($this->slug($gender)) {
            'masculino', 'hombre', 'male', 'm' => 'male',
            'femenino', 'mujer', 'female', 'f' => 'female',
            default => null, // "Otro"/desconocido: el usuario elige referencia o meta manual
        };
    }

    /** Objetivo físico (CRM) → muscle_gain|fat_loss|endurance|strength|general_wellness. */
    public function objective(?string $goal): ?string
    {
        return match ($this->slug($goal)) {
            'hipertrofia muscular', 'hipertrofia', 'muscle gain', 'muscle_gain', 'gain muscle', 'gain_muscle' => 'muscle_gain',
            'perdida de grasa', 'perder grasa', 'fat loss', 'fat_loss', 'lose fat', 'lose_fat' => 'fat_loss',
            'resistencia', 'endurance' => 'endurance',
            'fuerza', 'strength' => 'strength',
            'bienestar general', 'bienestar', 'general wellness', 'general_wellness', 'maintain' => 'general_wellness',
            default => null,
        };
    }

    /** Nivel de experiencia (CRM) → beginner|intermediate|advanced. */
    public function experienceLevel(?string $level): ?string
    {
        return match ($this->slug($level)) {
            'principiante', 'beginner', 'novato' => 'beginner',
            'intermedio', 'intermediate' => 'intermediate',
            'avanzado', 'advanced', 'experto' => 'advanced',
            default => null,
        };
    }

    /** Sugiere nivel de actividad desde días de entrenamiento/semana. */
    public function activityFromTrainingDays(?int $days): ?string
    {
        if ($days === null) {
            return null;
        }
        $days = max(0, min(7, $days));
        $map = (array) config('nutrition.goal_calculator.training_days_to_activity', []);
        return $map[$days] ?? null;
    }

    /** Normaliza: minúsculas, sin tildes, espacios colapsados. */
    private function slug(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
