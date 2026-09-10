<?php

namespace App\Services\Trainer;

use App\Exceptions\IntegralPlanException;
use App\Models\Member;
use App\Models\Routine;
use App\Services\RealtimeEvents;
use App\Models\RoutineExercise;
use App\Models\Trainer;
use App\Services\Exercises\ExerciseCatalogResolver;
use Illuminate\Support\Facades\DB;

/**
 * El entrenador revisa y corrige la rutina antes de entregarla.
 *
 * SE VUELVE A VALIDAR TODO. Flutter solo enseña ejercicios del catálogo, pero
 * eso no es una garantía: quien llame a la API directamente puede mandar
 * cualquier número. Cada `exercise_id` pasa otra vez por
 * {@see ExerciseCatalogResolver::resolveSafe()}, igual que al generar. Confiar
 * en el cliente aquí sería dejar entrar por la puerta de atrás justo lo que la
 * generación cierra por delante.
 *
 * SOLO BORRADORES. Una rutina ya publicada no se edita por aquí: el socio
 * podría estar entrenándola en ese momento y vería cambiar sus series a mitad
 * de sesión. Para eso hace falta un flujo distinto que nadie ha pedido.
 *
 * NO SE BORRA Y RECREA TODO. Las filas que siguen se actualizan en su sitio;
 * solo desaparecen las que el entrenador quitó. Recrearlas todas cambiaría sus
 * ids en cada guardado sin ninguna razón.
 */
class RoutineDraftEditor
{
    private const MAX_EXERCISES = 60;

    public function __construct(
        private readonly ExerciseCatalogResolver $catalog,
        private readonly TrainerMemberAccess $access,
    ) {}

    /**
     * Reemplaza la lista de ejercicios de un borrador.
     *
     * Cada entrada puede traer `id` —la fila que ya existía— para conservarla.
     * Sin `id` se crea nueva. Lo que no venga en la lista, se elimina.
     *
     * @param  list<array<string,mixed>>  $exercises
     *
     * @throws IntegralPlanException
     */
    public function replaceExercises(Routine $routine, Trainer $trainer, array $exercises): Routine
    {
        $this->assertEditable($routine, $trainer);

        $limpios = $this->sanitize($exercises);

        if ($limpios === []) {
            throw new IntegralPlanException(
                'La rutina necesita al menos un ejercicio del catálogo.',
                'routine_empty',
            );
        }

        $actualizada = DB::transaction(function () use ($routine, $limpios) {
            $conservadas = [];

            foreach ($limpios as $orden => $e) {
                $fila = $e['id'] !== null
                    ? RoutineExercise::where('routine_id', $routine->id)->find($e['id'])
                    : null;

                $datos = [
                    'routine_id' => $routine->id,
                    'exercise_id' => $e['exercise_id'],
                    'sets' => $e['sets'],
                    'reps' => $e['reps'],
                    'weight' => $e['weight'],
                    'notes' => $e['notes'],
                    // El orden lo fija la POSICIÓN en la lista que llega, no un
                    // número que mande el cliente: así reordenar es mover el
                    // elemento, y no queda hueco ni empate posible.
                    'sort_order' => $orden,
                ];

                if ($fila) {
                    $fila->update($datos);
                } else {
                    $fila = RoutineExercise::create($datos);
                }

                $conservadas[] = $fila->id;
            }

            // Lo que el entrenador quitó de la lista se va. Solo de ESTA rutina.
            RoutineExercise::where('routine_id', $routine->id)
                ->whereNotIn('id', $conservadas)
                ->delete();

            // La estructura por días se rehace desde la lista final para que el
            // resumen y las filas no cuenten cosas distintas.
            $routine->update([
                'days' => $this->daysFrom($limpios),
                'days_per_week' => count($this->daysFrom($limpios)),
            ]);

            return $routine->fresh('routineExercises');
        });

        // Solo si el socio la está viendo: un borrador que nadie tiene todavía
        // no le cambia nada, y avisar de él sería ruido.
        if ((bool) $actualizada->is_assigned) {
            RealtimeEvents::routine($actualizada->member_id);
        }
        TrainerRealtimeEvents::forMember(
            $actualizada->member_id,
            TrainerRealtimeEvents::ASSESSMENT,
            ['routines'],
        );

        return $actualizada;
    }

    // ── Validación ──────────────────────────────────────────────────────────

    /**
     * @param  list<array<string,mixed>>  $in
     * @return list<array<string,mixed>>
     *
     * @throws IntegralPlanException
     */
    private function sanitize(array $in): array
    {
        $out = [];

        foreach (array_slice($in, 0, self::MAX_EXERCISES) as $e) {
            if (! is_array($e)) {
                continue;
            }

            $id = $this->int($e['exercise_id'] ?? null);
            if ($id === null) {
                throw new IntegralPlanException(
                    'Falta el ejercicio en una de las líneas.',
                    'missing_exercise_id',
                );
            }

            // La misma comprobación que al generar. Un id que no resuelve NO
            // es un aviso: es un rechazo, porque el socio abriría su rutina y
            // encontraría un ejercicio sin vídeo ni instrucciones.
            $ejercicio = $this->catalog->resolveSafe($id, null);
            if ($ejercicio === null) {
                throw new IntegralPlanException(
                    "El ejercicio #{$id} no está en el catálogo del gimnasio.",
                    'invalid_exercise_id',
                );
            }

            $sets = $this->int($e['sets'] ?? null);
            if ($sets === null || $sets < 1 || $sets > 10) {
                throw new IntegralPlanException(
                    'Las series deben ser un número entre 1 y 10.',
                    'invalid_sets',
                );
            }

            $out[] = [
                'id' => $this->int($e['id'] ?? null),
                'exercise_id' => (int) $ejercicio->id,
                'name' => $ejercicio->local_name ?: $ejercicio->name,
                'day' => $this->str($e['day'] ?? null, 80),
                'sets' => $sets,
                // `reps` es texto en el modelo real: admite «10», «8-10» y
                // «al fallo», que es como lo escriben los entrenadores.
                'reps' => $this->str($e['reps'] ?? null, 20) ?? '10',
                'weight' => $this->str($e['weight'] ?? null, 40),
                'notes' => $this->str($e['notes'] ?? null, 500),
            ];
        }

        return $out;
    }

    /**
     * Reagrupa la lista plana en días, respetando el orden de llegada. Sin
     * `day`, todo cae en un único día.
     *
     * @param  list<array<string,mixed>>  $exercises
     * @return list<array<string,mixed>>
     */
    private function daysFrom(array $exercises): array
    {
        $porDia = [];

        foreach ($exercises as $e) {
            $dia = $e['day'] ?? 'Día 1';
            $porDia[$dia][] = array_filter([
                'exercise_id' => $e['exercise_id'],
                'name' => $e['name'],
                'sets' => $e['sets'],
                'reps' => $e['reps'],
                'weight' => $e['weight'],
                'notes' => $e['notes'],
            ], fn ($v) => $v !== null);
        }

        $out = [];
        $i = 0;
        foreach ($porDia as $nombre => $lista) {
            $out[] = ['name' => $nombre, 'order' => $i++, 'exercises' => $lista];
        }

        return $out;
    }

    /**
     * @throws IntegralPlanException
     */
    private function assertEditable(Routine $routine, Trainer $trainer): void
    {
        if ((int) $routine->trainer_id !== (int) $trainer->getKey()) {
            throw new IntegralPlanException(
                'Esta rutina la creó otro entrenador.',
                'routine_not_owned',
            );
        }

        if ((bool) $routine->is_assigned) {
            throw new IntegralPlanException(
                'Esta rutina ya está publicada. El socio podría estar entrenándola ahora mismo.',
                'routine_published',
            );
        }

        $member = Member::find($routine->member_id);
        if (! $member instanceof Member || ! $this->access->canAccess($trainer, $member)) {
            throw new IntegralPlanException(
                'Este socio ya no está asignado a ti.',
                'assignment_inactive',
            );
        }
    }

    private function int(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && ctype_digit(trim($v))) {
            return (int) trim($v);
        }

        return null;
    }

    private function str(mixed $v, int $max): ?string
    {
        if (! is_string($v) && ! is_numeric($v)) {
            return null;
        }

        $s = trim((string) $v);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
