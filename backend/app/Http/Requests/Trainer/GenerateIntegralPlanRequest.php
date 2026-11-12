<?php

namespace App\Http\Requests\Trainer;

use App\Services\Trainer\SessionTimeBudget;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El contexto que el entrenador escribe en «Contexto para el plan».
 *
 * LAS CLAVES SON LAS DE LA APP. Salen tal cual de `PlanContext.toJson()`
 * (lib/features/trainer/models/plan_context.dart): no se inventan nombres, y la
 * app las omite cuando están vacías.
 *
 * TODO ES OPCIONAL. Las versiones instaladas hasta la 2.0.9 envían `{}`: eso
 * sigue siendo una petición válida y genera exactamente como antes.
 *
 * LA POTENCIA NO SE ACEPTA AQUÍ. Cualquier otra clave (p. ej. `nutrition_power`)
 * se ignora: el nivel sale del plan vigente del socio, nunca de la petición.
 *
 * Los textos libres son DATOS del socio: se limitan en longitud aquí y se
 * envían al modelo marcados como datos, nunca como instrucciones.
 */
class GenerateIntegralPlanRequest extends FormRequest
{
    public const LEVELS = ['principiante', 'intermedio', 'avanzado'];

    public const TEXT_FIELDS = ['objective', 'injuries', 'restrictions', 'observations'];

    public function authorize(): bool
    {
        // La autorización real (sesión del entrenador + asignación activa) la
        // aplican el middleware `auth.trainer` y TrainerMemberAccess.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $limpio = [];
        foreach (self::TEXT_FIELDS as $campo) {
            if (is_string($this->input($campo))) {
                $limpio[$campo] = trim(preg_replace('/\s+/u', ' ', $this->input($campo)) ?? '');
            }
        }
        if (is_string($this->input('training_level'))) {
            $limpio['training_level'] = mb_strtolower(trim($this->input('training_level')));
        }
        $this->merge($limpio);
    }

    /**
     * Los topes de texto son los de las FUENTES que la app precarga en el
     * formulario (`PlanContext.from`): objetivo de la guía hasta 180
     * (StoreNutritionGuideRequest), lesiones del socio hasta 2000
     * (RegisterMemberRequest), restricciones de la guía hasta 5000. Más bajos,
     * la 2.0.10 rechazaría un texto que el entrenador ni escribió y que con `{}`
     * generaba sin problema.
     */
    public function rules(): array
    {
        return [
            'objective' => ['sometimes', 'nullable', 'string', 'max:180'],
            'training_level' => ['sometimes', 'nullable', 'string', Rule::in(self::LEVELS)],
            'days_per_week' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:7'],
            'session_minutes' => ['sometimes', 'nullable', 'integer', 'min:'.SessionTimeBudget::MIN_SESSION_MINUTES, 'max:'.SessionTimeBudget::MAX_SESSION_MINUTES],
            'injuries' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'restrictions' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'observations' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'objective.max' => 'El objetivo no puede pasar de 180 caracteres.',
            'training_level.in' => 'El nivel de entrenamiento debe ser principiante, intermedio o avanzado.',
            'days_per_week.integer' => 'Los días por semana deben ser un número entero.',
            'days_per_week.min' => 'Los días por semana deben estar entre 1 y 7.',
            'days_per_week.max' => 'Los días por semana deben estar entre 1 y 7.',
            'session_minutes.integer' => 'Los minutos por sesión deben ser un número entero.',
            'session_minutes.min' => 'Los minutos por sesión deben estar entre '.SessionTimeBudget::MIN_SESSION_MINUTES.' y '.SessionTimeBudget::MAX_SESSION_MINUTES.'.',
            'session_minutes.max' => 'Los minutos por sesión deben estar entre '.SessionTimeBudget::MIN_SESSION_MINUTES.' y '.SessionTimeBudget::MAX_SESSION_MINUTES.'.',
            'injuries.max' => 'Las lesiones o limitaciones no pueden pasar de 2000 caracteres.',
            'restrictions.max' => 'Las alergias o restricciones no pueden pasar de 5000 caracteres.',
            'observations.max' => 'La observación no puede pasar de 2000 caracteres.',
            '*.string' => 'El campo :attribute debe ser texto.',
        ];
    }

    /**
     * Lo que el entrenador aportó para ESTA generación, sin vacíos.
     *
     * @return array{objective?: string, training_level?: string, days_per_week?: int, session_minutes?: int, injuries?: string, restrictions?: string, observations?: string}
     */
    public function trainerInput(): array
    {
        $datos = array_filter(
            $this->validated(),
            fn ($v) => $v !== null && $v !== '',
        );
        // La regla `integer` acepta "6" y 6.0; aguas abajo se usa is_int(). Sin
        // esto, un cliente que mande "6" vería sus días ignorados en silencio.
        foreach (['days_per_week', 'session_minutes'] as $entero) {
            if (array_key_exists($entero, $datos)) {
                $datos[$entero] = (int) $datos[$entero];
            }
        }

        return $datos;
    }
}
