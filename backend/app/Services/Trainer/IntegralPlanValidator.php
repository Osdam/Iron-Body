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

    /**
     * Límites del modo AMPLIADO (potencias 2..5, docs/iron-ia/POTENCIAS-IRON-IA.md §5):
     * una semana de 7 días con hasta 6 comidas, la lista de mercado y los
     * totales. Van en sintonía con StoreNutritionGuideRequest, para que lo que
     * se genera se pueda volver a guardar al editarlo sin un 422.
     */
    public const EXTENDED_LIMITS = [
        'meals' => 42,
        'meal_description' => 2000,
        'objective_description' => 2000,
        'recommendations' => 9000,
        'restrictions' => 3000,
        'supplements' => 2000,
        'notes' => 9000,
        'routine_description' => 2000,
        'day_objective' => 300,
    ];

    /** Límites de siempre (nivel 1). */
    private const BASE_LIMITS = [
        'meals' => self::MAX_MEALS,
        'meal_description' => 2000,
        'objective_description' => 2000,
        'recommendations' => 4000,
        'restrictions' => 2000,
        'supplements' => 2000,
        'notes' => 4000,
        'routine_description' => 2000,
        'day_objective' => null,
    ];

    /** @var array<string, int|null> */
    private array $limits = self::BASE_LIMITS;

    public function __construct(private readonly ExerciseCatalogResolver $catalog) {}

    /**
     * Valida y NORMALIZA la respuesta. Devuelve solo lo que tiene destino en el
     * modelo real; lo que el modelo se invente de más se descarta en silencio,
     * porque no hay dónde guardarlo.
     *
     * `$extended` (potencias 2..5) sube los límites a {@see EXTENDED_LIMITS} y
     * conserva el objetivo de cada día de la rutina. Sin él, todo es como
     * siempre: es lo que usa el nivel 1.
     *
     * @param  array<string,mixed>|null  $raw
     * @return array{nutrition: array<string,mixed>, routine: array<string,mixed>}
     *
     * @throws IntegralPlanException
     */
    public function validate(?array $raw, bool $extended = false): array
    {
        $this->limits = $extended ? self::EXTENDED_LIMITS : self::BASE_LIMITS;

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
            // El análisis es lo que el entrenador lee de un vistazo antes de
            // revisar el detalle. NO se exige: un modelo que devuelva un plan
            // correcto sin síntesis sigue siendo un plan correcto, y tumbar la
            // generación entera por un texto de cortesía sería absurdo.
            'analysis' => $this->analysis($raw['analysis'] ?? null),
            'nutrition' => $this->nutrition($nutrition),
            'routine' => $this->routine($routine),
        ];
    }

    // ── Nutrición ───────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $in
     * @return array<string,mixed>
     */
    /**
     * La síntesis profesional, acotada.
     *
     * Se limita el tamaño por la misma razón que todo lo demás: lo que llega
     * del modelo es una propuesta, no un dato de confianza, y un texto sin
     * tope acabaría siendo un muro que nadie lee. Si no viene, no pasa nada.
     *
     * @return array<string, mixed>|null
     */
    private function analysis(mixed $in): ?array
    {
        if (! is_array($in)) {
            return null;
        }

        $prioridades = [];
        foreach (array_slice((array) ($in['priorities'] ?? []), 0, 5) as $p) {
            $texto = $this->str($p, 160);
            if ($texto !== null) {
                $prioridades[] = $texto;
            }
        }

        $out = array_filter([
            'profile' => $this->str($in['profile'] ?? null, 300),
            'priorities' => $prioridades ?: null,
            'approach' => $this->str($in['approach'] ?? null, 800),
            'limitations' => $this->str($in['limitations'] ?? null, 600),
            'strategy' => $this->str($in['strategy'] ?? null, 800),
        ], static fn ($v) => $v !== null);

        return $out === [] ? null : $out;
    }

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
            'objective_description' => $this->str($in['objective_description'] ?? null, $this->limits['objective_description']),
            'training_stage' => $this->str($in['training_stage'] ?? null, 160),
            'meals' => $comidas,
            'recommendations' => $this->str($in['recommendations'] ?? null, $this->limits['recommendations']),
            'restrictions' => $this->str($in['restrictions'] ?? null, $this->limits['restrictions']),
            'supplements' => $this->str($in['supplements'] ?? null, $this->limits['supplements']),
            'notes' => $this->str($in['notes'] ?? null, $this->limits['notes']),
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
        foreach (array_slice($in, 0, $this->limits['meals']) as $i => $m) {
            if (! is_array($m)) {
                continue;
            }

            $etiqueta = $this->str($m['label'] ?? null, 80);
            $descripcion = $this->str($m['description'] ?? null, $this->limits['meal_description']);

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
            'description' => $this->str($in['description'] ?? null, $this->limits['routine_description']),
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

            $dia = [
                'name' => $this->str($d['name'] ?? null, 80) ?? 'Día '.($i + 1),
                'order' => $i,
            ];
            // El objetivo del día es un campo que la app YA pinta
            // (RoutineResource::serializeDays lo lee como texto); solo el modo
            // ampliado lo pide, y por eso solo él lo conserva.
            if ($this->limits['day_objective'] !== null) {
                $objetivo = $this->str($d['objective'] ?? null, $this->limits['day_objective']);
                if ($objetivo !== null) {
                    $dia['objective'] = $objetivo;
                }
            }
            $dia['exercises'] = $ejercicios;
            $out[] = $dia;
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

        return $s === '' ? '10' : self::clip($s, 20);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function str(mixed $v, int $max): ?string
    {
        if (! is_string($v) && ! is_numeric($v)) {
            return null;
        }

        $s = trim((string) $v);

        return $s === '' ? null : self::clip($s, $max);
    }

    /**
     * Acota un texto SIN partirlo a la mitad.
     *
     * Antes era `mb_substr`: un texto largo llegaba al socio cortado en mitad
     * de una palabra («…200 g de pe»). Ahora, si sobra, se corta en el último
     * final de frase o de línea que cabe y, si no lo hay en una distancia
     * razonable, en el último espacio, y se marca con «…». Lo que cabe no se
     * toca: en un texto dentro del límite el resultado es idéntico al de antes.
     */
    public static function clip(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        $corte = mb_substr($s, 0, max(1, $max - 1));
        $minimo = (int) floor($max * 0.6);

        $fin = max((int) mb_strrpos($corte, '. '), (int) mb_strrpos($corte, "\n"));
        if ($fin >= $minimo) {
            return rtrim(mb_substr($corte, 0, $fin + 1));
        }

        $espacio = mb_strrpos($corte, ' ');
        if ($espacio !== false && $espacio >= $minimo) {
            return rtrim(mb_substr($corte, 0, $espacio), ' ,;:').'…';
        }

        // Una sola «palabra» más larga que el límite (p. ej. «8-10-12-15-20…»).
        return $corte.'…';
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
