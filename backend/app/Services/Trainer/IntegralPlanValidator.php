<?php

namespace App\Services\Trainer;

use App\Exceptions\IntegralPlanException;
use App\Services\Exercises\ExerciseCatalogResolver;

/**
 * Comprueba, campo a campo, lo que devolvió el modelo.
 *
 * LA IA NUNCA ES LA AUTORIDAD. Aunque se le pida JSON, lo que llega es texto de
 * un generador de texto: puede faltar una clave, venir un número donde va una
 * cadena, o —lo más caro de todo— un ejercicio que no existe. Un ejercicio
 * inventado no es un error cosmético: el socio abriría su rutina y encontraría
 * un nombre sin vídeo, sin instrucciones y sin nada detrás.
 *
 * Por eso NADA se persiste sin pasar por aquí, y cada `exercise_id` se resuelve
 * contra el catálogo real con {@see ExerciseCatalogResolver::resolveSafe()},
 * que devuelve null si no lo encuentra. Lo que no resuelve, no entra.
 *
 * FALLA COMO UNIDAD. Si la rutina no valida, tampoco se guarda la nutrición.
 * Medio plan es peor que ninguno: el entrenador publicaría una guía creyendo
 * que su rutina también está, y el socio se quedaría a medias sin que nadie
 * se entere.
 */
class IntegralPlanValidator
{
    /** Un plan con más de esto no es un plan, es un vertido. */
    private const MAX_DAYS = 7;

    private const MAX_EXERCISES_PER_DAY = 12;

    private const MAX_MEALS = 10;

    public function __construct(private readonly ExerciseCatalogResolver $catalog) {}

    /**
     * Valida y NORMALIZA la respuesta. Devuelve solo lo que tiene destino en el
     * modelo real; lo que el modelo se invente de más se descarta en silencio,
     * porque no hay dónde guardarlo.
     *
     * @param  array<string,mixed>|null  $raw
     * @return array{nutrition: array<string,mixed>, routine: array<string,mixed>}
     *
     * @throws IntegralPlanException
     */
    public function validate(?array $raw): array
    {
        if (! is_array($raw)) {
            throw IntegralPlanException::malformed('la respuesta no es un objeto JSON');
        }

        $nutrition = $raw['nutrition'] ?? null;
        $routine = $raw['routine'] ?? null;

        if (! is_array($nutrition)) {
            throw IntegralPlanException::malformed('falta el bloque `nutrition`');
        }
        if (! is_array($routine)) {
            throw IntegralPlanException::malformed('falta el bloque `routine`');
        }

        return [
            'nutrition' => $this->nutrition($nutrition),
            'routine' => $this->routine($routine),
        ];
    }

    // ── Nutrición ───────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $in
     * @return array<string,mixed>
     */
    private function nutrition(array $in): array
    {
        $objetivo = $this->str($in['objective'] ?? null, 160);
        if ($objetivo === null) {
            throw IntegralPlanException::malformed('la nutrición no trae objetivo');
        }

        $comidas = $this->meals($in['meals'] ?? null);
        if ($comidas === []) {
            throw IntegralPlanException::malformed('la nutrición no trae ninguna comida');
        }

        return array_filter([
            'objective' => $objetivo,
            'objective_description' => $this->str($in['objective_description'] ?? null, 2000),
            'training_stage' => $this->str($in['training_stage'] ?? null, 160),
            'meals' => $comidas,
            'recommendations' => $this->str($in['recommendations'] ?? null, 4000),
            'restrictions' => $this->str($in['restrictions'] ?? null, 2000),
            'supplements' => $this->str($in['supplements'] ?? null, 2000),
            'notes' => $this->str($in['notes'] ?? null, 4000),
        ], fn ($v) => $v !== null);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function meals(mixed $in): array
    {
        if (! is_array($in)) {
            return [];
        }

        $out = [];
        foreach (array_slice($in, 0, self::MAX_MEALS) as $i => $m) {
            if (! is_array($m)) {
                continue;
            }

            $etiqueta = $this->str($m['label'] ?? null, 80);
            $descripcion = $this->str($m['description'] ?? null, 2000);

            // Una comida sin nombre ni contenido no le dice nada al socio.
            if ($etiqueta === null || $descripcion === null) {
                continue;
            }

            $out[] = array_filter([
                'label' => $etiqueta,
                'time' => $this->str($m['time'] ?? null, 20),
                'description' => $descripcion,
                'order' => $i,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    // ── Rutina ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $in
     * @return array<string,mixed>
     */
    private function routine(array $in): array
    {
        $nombre = $this->str($in['name'] ?? null, 160);
        if ($nombre === null) {
            throw IntegralPlanException::malformed('la rutina no trae nombre');
        }

        $dias = $this->days($in['days'] ?? null);
        if ($dias === []) {
            throw IntegralPlanException::malformed('la rutina no trae ningún día con ejercicios válidos');
        }

        // `days_per_week` sale de los días que de verdad hay, no de lo que diga
        // el modelo: si dice 5 y trae 3, manda lo que trae.
        return array_filter([
            'name' => $nombre,
            'objective' => $this->str($in['objective'] ?? null, 160),
            'level' => $this->str($in['level'] ?? null, 60),
            'days_per_week' => count($dias),
            'description' => $this->str($in['description'] ?? null, 2000),
            'days' => $dias,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function days(mixed $in): array
    {
        if (! is_array($in)) {
            return [];
        }

        $out = [];
        foreach (array_slice($in, 0, self::MAX_DAYS) as $i => $d) {
            if (! is_array($d)) {
                continue;
            }

            $ejercicios = $this->exercises($d['exercises'] ?? null);
            if ($ejercicios === []) {
                // Un día sin ejercicios válidos no es un día de entrenamiento.
                continue;
            }

            $out[] = [
                'name' => $this->str($d['name'] ?? null, 80) ?? 'Día '.($i + 1),
                'order' => $i,
                'exercises' => $ejercicios,
            ];
        }

        return $out;
    }

    /**
     * Aquí está el candado. Cada `exercise_id` se resuelve contra el catálogo
     * REAL; lo que no resuelve, se descarta. El modelo no puede añadir un
     * ejercicio al gimnasio escribiendo su nombre.
     *
     * @return list<array<string,mixed>>
     */
    private function exercises(mixed $in): array
    {
        if (! is_array($in)) {
            return [];
        }

        $out = [];
        $vistos = [];

        foreach (array_slice($in, 0, self::MAX_EXERCISES_PER_DAY) as $e) {
            if (! is_array($e)) {
                continue;
            }

            $id = $this->int($e['exercise_id'] ?? null);
            if ($id === null) {
                continue;
            }

            // SOLO por id, y solo si existe. No se resuelve por nombre a
            // propósito: aceptar un nombre parecido es la puerta por la que
            // entra un ejercicio que nadie eligió.
            $ejercicio = $this->catalog->resolveSafe($id, null);
            if ($ejercicio === null) {
                continue;
            }

            // El mismo ejercicio dos veces el mismo día es casi siempre un
            // descuido del modelo, y al socio le sobra.
            if (isset($vistos[$ejercicio->id])) {
                continue;
            }
            $vistos[$ejercicio->id] = true;

            $out[] = array_filter([
                'exercise_id' => (int) $ejercicio->id,
                'name' => $ejercicio->local_name ?: $ejercicio->name,
                'sets' => $this->intInRange($e['sets'] ?? null, 1, 10) ?? 3,
                'reps' => $this->reps($e['reps'] ?? null),
                'rest_seconds' => $this->intInRange($e['rest_seconds'] ?? null, 0, 600),
                'notes' => $this->str($e['notes'] ?? null, 500),
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /**
     * `reps` es texto en el modelo real (`routine_exercises.reps` es string):
     * admite «10» y también «8-10» o «al fallo», que es como se escriben.
     */
    private function reps(mixed $v): string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? '10' : mb_substr($s, 0, 20);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function str(mixed $v, int $max): ?string
    {
        if (! is_string($v) && ! is_numeric($v)) {
            return null;
        }

        $s = trim((string) $v);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function int(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && ctype_digit(trim($v))) {
            return (int) trim($v);
        }
        if (is_float($v) && $v == (int) $v) {
            return (int) $v;
        }

        return null;
    }

    private function intInRange(mixed $v, int $min, int $max): ?int
    {
        $n = $this->int($v);

        return ($n !== null && $n >= $min && $n <= $max) ? $n : null;
    }
}
