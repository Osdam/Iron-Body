<?php

namespace App\Services\Trainer;

use App\Exceptions\IntegralPlanException;
use App\Models\Member;
use App\Services\RealtimeEvents;
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
    /**
     * Retira la rutina del socio SIN destruir nada.
     *
     * Es el gemelo de {@see publish()} y hace exactamente lo contrario de lo
     * que aquél escribió: el socio deja de verla en Mis Rutinas, y el
     * entrenador la sigue viendo en el seguimiento.
     *
     * NO borra la rutina, ni sus ejercicios, ni los entrenamientos que el socio
     * ya hizo con ella. Un histórico no deja de haber ocurrido porque el plan
     * cambie: `workout_sessions` y `routine_completions` apuntan a esta rutina y
     * son lo que el socio ve en su progreso.
     */
    public function retire(Routine $routine, Trainer $trainer): Routine
    {
        $member = Member::find($routine->member_id);

        if ($member instanceof Member && ! $this->access->canAccess($trainer, $member)) {
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

        $retirada = DB::transaction(function () use ($routine) {
            $routine->update([
                'is_assigned' => false,
                'status' => 'Retirada',
            ]);

            // Solo la ASIGNACIÓN se va: es la fila que decide si el socio la ve.
            MemberRoutineAssignment::where('routine_id', $routine->getKey())->delete();

            return $routine->fresh();
        });

        // DESPUÉS del commit, nunca antes: si se avisara dentro, la app podría
        // consultar una base que todavía no tiene el cambio y volver a pintar
        // la rutina que acaba de desaparecer.
        $this->notifyRoutineChanged($routine->member_id);

        return $retirada;
    }

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

        $publicada = DB::transaction(function () use ($routine, $member) {
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

        $this->notifyRoutineChanged($member->getKey());

        return $publicada;
    }

    /**
     * Avisa a quien esté mirando: al socio, para que Mis Rutinas se actualice
     * sin cerrar sesión; y a su entrenador, para que el seguimiento no enseñe
     * un estado viejo.
     *
     * Es una notificación, no el dato: cada lado vuelve a preguntar por REST.
     * Si el aviso se pierde, la pantalla se actualiza igual al volver a ella.
     */
    private function notifyRoutineChanged(?int $memberId): void
    {
        RealtimeEvents::routine($memberId);
        TrainerRealtimeEvents::forMember($memberId, TrainerRealtimeEvents::ASSESSMENT, ['routines']);
    }
}
