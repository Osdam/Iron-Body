<?php

namespace App\Services\Trainer;

use App\Exceptions\IntegralPlanException;
use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\Routine;
use App\Models\Trainer;
use Illuminate\Support\Facades\DB;

/**
 * Hace visible una rutina en «Mis Rutinas» del socio.
 *
 * NO CREA UNA CATEGORÍA NUEVA. «Mis Rutinas» encuentra las rutinas por dos
 * vías —`member_routine_assignments`, y `routines.member_id` con
 * `is_assigned = true`— y descarta las semi-personalizadas. Publicar es poner
 * esas dos marcas: la rutina aparece donde el socio ya mira, sin pestañas
 * nuevas ni «Rutinas IA».
 *
 * SE ESCRIBEN LAS DOS VÍAS a propósito. La consulta las une, así que con una
 * bastaría; poner ambas deja la rutina localizable por cualquiera de los dos
 * caminos y evita que un cambio futuro en esa consulta la haga desaparecer
 * del listado de alguien que ya la tenía.
 */
class RoutinePublisher
{
    public function __construct(private readonly TrainerMemberAccess $access) {}

    /**
     * @throws IntegralPlanException
     */
    public function publish(Routine $routine, Trainer $trainer): Routine
    {
        $member = Member::find($routine->member_id);

        if (! $member instanceof Member) {
            throw new IntegralPlanException(
                'Esta rutina no tiene socio asignado.',
                'routine_without_member',
            );
        }

        // La asignación se comprueba AL PUBLICAR, no solo al generar: entre una
        // cosa y otra el socio puede haber cambiado de entrenador, y publicarle
        // entonces un plan sería entregar trabajo de alguien que ya no le
        // atiende.
        if (! $this->access->canAccess($trainer, $member)) {
            throw new IntegralPlanException(
                'Este socio ya no está asignado a ti.',
                'assignment_inactive',
            );
        }

        if ((int) $routine->trainer_id !== (int) $trainer->getKey()) {
            throw new IntegralPlanException(
                'Esta rutina la creó otro entrenador.',
                'routine_not_owned',
            );
        }

        if ($routine->routineExercises()->count() === 0) {
            throw new IntegralPlanException(
                'La rutina no tiene ejercicios. Añade al menos uno antes de publicarla.',
                'routine_empty',
            );
        }

        return DB::transaction(function () use ($routine, $member) {
            $routine->update([
                'is_assigned' => true,
                'assigned_member_id' => $member->getKey(),
                'assigned_member_name' => $member->full_name,
                'status' => 'Activa',
            ]);

            // `firstOrCreate`: publicar dos veces no duplica la asignación —hay
            // un único (member_id, routine_id)— y no es un error, es alguien
            // pulsando dos veces.
            MemberRoutineAssignment::firstOrCreate(
                ['member_id' => $member->getKey(), 'routine_id' => $routine->getKey()],
                ['assigned_at' => now()],
            );

            return $routine->fresh();
        });
    }
}
