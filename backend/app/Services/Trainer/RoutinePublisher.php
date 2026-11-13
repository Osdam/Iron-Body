<?php

namespace App\Services\Trainer;

use App\Exceptions\IntegralPlanException;
use App\Models\IronIaRecoveryItem;
use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\Routine;
use App\Models\Trainer;
use App\Services\RealtimeEvents;
use App\Services\Trainer\Recovery\RoutineRecoveryCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            // Orden de bloqueo único en todo el flujo: primero la fila de la
            // campaña, después la rutina (publish y regenerate hacen lo mismo).
            // Al revés, publicar y retirar a la vez se interbloqueaban (40P01).
            $item = IronIaRecoveryItem::query()
                ->where('proposal_routine_id', $routine->getKey())
                ->lockForUpdate()
                ->first();

            $routine->update([
                'is_assigned' => false,
                'status' => 'Retirada',
            ]);

            // Si era una propuesta de recuperación sin publicar, el entrenador la descartó.
            if ($item !== null && $item->status === IronIaRecoveryItem::STATUS_PROPOSED) {
                $item->forceFill(['status' => IronIaRecoveryItem::STATUS_DISCARDED, 'discarded_at' => now()])->save();
            }

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

    /**
     * @param  array<string,mixed>|null  $recoveryConfirmation  obligatoria si la rutina es una propuesta
     *                                                          de recuperación pendiente (RECUPERACION-REVISION-CONTRATO.md §2);
     *                                                          en cualquier otra rutina se ignora
     */
    public function publish(Routine $routine, Trainer $trainer, ?array $recoveryConfirmation = null): Routine
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

        $publicada = DB::transaction(function () use ($routine, $member, $trainer, $recoveryConfirmation) {
            // La fila de la campaña se bloquea ANTES de tocar nada: si es una
            // propuesta pendiente, la confirmación se comprueba aquí y, si no
            // cuadra, la excepción deshace la transacción entera (nada publicado).
            $item = IronIaRecoveryItem::query()
                ->where('proposal_routine_id', $routine->getKey())
                ->lockForUpdate()
                ->first();
            if ($item !== null && $item->isPending()) {
                // Y después la rutina: el editor no puede cambiarla entre la
                // comprobación y la publicación.
                $bloqueada = Routine::query()->whereKey($routine->getKey())->lockForUpdate()->firstOrFail();
                $recoveryConfirmation = $this->assertRecoveryConfirmation($bloqueada, $recoveryConfirmation);
            }
            // Una propuesta que el entrenador regeneró quedó sustituida: publicarla
            // pondría al socio una rutina sin revisar junto a la original.
            if ($item === null && IronIaRecoveryItem::supersededBy((int) $routine->getKey(), lock: true) !== null) {
                throw new IntegralPlanException(
                    'Esta propuesta se sustituyó por otra más reciente al regenerarla: revisa y publica la última.',
                    'recovery_superseded',
                );
            }

            $routine->update([
                'is_assigned' => true,
                'assigned_member_id' => $member->getKey(),
                'assigned_member_name' => $member->full_name,
                'status' => 'Activa',
            ]);

            $this->replaceRecoveredOriginal($routine, $trainer, $item, $recoveryConfirmation);

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
     * Recuperación de Iron IA (docs/iron-ia/RECUPERACION-RUTINAS.md): publicar una
     * propuesta la hace la vigente y retira la rutina original que venía a
     * corregir. Se llama DENTRO de la transacción de {@see publish()}.
     *
     * - La fila de la campaña se bloquea: dos publicaciones a la vez de la misma
     *   propuesta hacen una sola sustitución (la segunda la ve ya publicada).
     * - El nombre pierde la marca «por confirmar»: la publicación ES la
     *   confirmación del entrenador, y el socio no debe verla.
     * - La original se retira con el mismo efecto que {@see retire()}: deja de
     *   verse en Mis Rutinas, pero la rutina, sus ejercicios, las sesiones y los
     *   completados que apuntan a ella se quedan. También cuando la generó otro
     *   entrenador: la propuesta la sustituye por decisión aprobada de la campaña.
     * - Una rutina que no es una propuesta de recuperación no cambia en nada.
     */
    private function replaceRecoveredOriginal(Routine $routine, Trainer $trainer, ?IronIaRecoveryItem $item, ?array $confirmation): void
    {
        // «discarded» también: si el entrenador la retiró y luego decide
        // publicarla, es la misma sustitución (sin la marca y sin la original).
        if ($item === null || ! $item->isPending()) {
            return;
        }

        // Se quita SOLO la marca: si alguien renombró la rutina, su nombre se respeta.
        $prefijo = sprintf(RoutineRecoveryCampaign::NAME_PREFIX, (int) $item->proposed_days);
        if (str_starts_with((string) $routine->name, $prefijo)) {
            $routine->update(['name' => mb_substr((string) $routine->name, mb_strlen($prefijo))]);
        }

        $retirada = null;
        $original = $item->original_routine_id !== null
            ? Routine::query()->whereKey($item->original_routine_id)->lockForUpdate()->first()
            : null;
        if ($original instanceof Routine
            && (int) $original->member_id === (int) $routine->member_id
            && ((bool) $original->is_assigned || MemberRoutineAssignment::where('routine_id', $original->getKey())->exists())) {
            $original->update(['is_assigned' => false, 'status' => 'Retirada']);
            MemberRoutineAssignment::where('routine_id', $original->getKey())->delete();
            $retirada = now();
        }

        $item->forceFill([
            'status' => IronIaRecoveryItem::STATUS_PUBLISHED,
            'discarded_at' => null,
            'published_at' => now(),
            'published_by_trainer_id' => $trainer->getKey(),
            'original_retired_at' => $retirada,
            'confirmation' => $confirmation,
            'confirmed_at' => now(),
        ])->save();
    }

    /** El nivel en una de las tres formas canónicas, o null si no se reconoce. */
    private static function canonicalLevel(string $v): ?string
    {
        $t = mb_strtolower(Str::ascii($v));

        return match (true) {
            str_contains($t, 'princip') || str_contains($t, 'inicia') => 'principiante',
            str_contains($t, 'interm') => 'intermedio',
            str_contains($t, 'avanz') => 'avanzado',
            default => null,
        };
    }

    /**
     * Lo que el entrenador confirmó con el socio tiene que cuadrar con la rutina.
     * Si no, NO se publica: o la ajusta en el editor, o la regenera con lo
     * confirmado (RECUPERACION-REVISION-CONTRATO.md §2-§3).
     *
     * @param  array<string,mixed>|null  $c
     * @return array<string,mixed> lo que se guarda como confirmación
     *
     * @throws IntegralPlanException
     */
    private function assertRecoveryConfirmation(Routine $routine, ?array $c): array
    {
        if ($c === null || $c === []) {
            throw new IntegralPlanException(
                'Es una propuesta de recuperación: antes de publicarla, confirma con el socio sus días reales, los minutos por sesión, su nivel, su objetivo y sus lesiones.',
                'recovery_confirmation_required',
            );
        }
        if (($c['availability_confirmed'] ?? null) !== true || ($c['muscle_split_reviewed'] ?? null) !== true) {
            throw new IntegralPlanException(
                'Falta confirmar que hablaste con el socio de su disponibilidad y que revisaste el reparto muscular, los ejercicios y la recuperación.',
                'recovery_confirmation_required',
            );
        }

        $dias = (int) $routine->days_per_week;
        $confirmados = (int) $c['days_per_week'];
        $esperados = min(MemberContextAssembler::MAX_PLAN_DAYS, $confirmados);
        if ($esperados !== $dias) {
            throw new IntegralPlanException(
                "La rutina tiene {$dias} ".($dias === 1 ? 'día' : 'días')." y el socio puede {$confirmados}: ajústala en el editor o regénerala con {$confirmados} ".($confirmados === 1 ? 'día' : 'días').'.',
                'recovery_days_mismatch',
            );
        }

        $nivel = (string) $c['training_level'];
        // El nivel de la rutina lo escribe el ensamblador (uno de los tres) o el
        // modelo (texto libre): se compara normalizado y, si no se reconoce, no
        // se bloquea por él.
        $nivelRutina = self::canonicalLevel((string) $routine->level);
        if ($nivelRutina !== null && $nivelRutina !== $nivel) {
            throw new IntegralPlanException(
                "La rutina es de nivel {$routine->level} y confirmaste {$nivel}: regénerala con el nivel correcto.",
                'recovery_level_mismatch',
            );
        }

        $minutos = (int) $c['session_minutes'];
        $peor = 0.0;
        foreach ((array) $routine->days as $dia) {
            $peor = max($peor, SessionTimeBudget::estimateMinutes((array) ($dia['exercises'] ?? []), $minutos));
        }
        if ($peor > $minutos * SessionTimeBudget::TOLERANCE) {
            $texto = str_replace('.', ',', (string) round($peor, 1));
            throw new IntegralPlanException(
                "Algún día dura unos {$texto} min y el socio tiene {$minutos}: ajusta la rutina en el editor o regénerala con {$minutos} minutos.",
                'recovery_minutes_mismatch',
            );
        }

        return [
            'days_per_week' => $confirmados,
            'session_minutes' => $minutos,
            'training_level' => $nivel,
            'objective' => (string) $c['objective'],
            'injuries' => (string) $c['injuries'],
            'availability_confirmed' => true,
            'muscle_split_reviewed' => true,
        ];
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
