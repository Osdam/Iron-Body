<?php

namespace App\Services\Membership;

use App\Models\Admin;
use App\Models\User;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CONGELAR Y REANUDAR una membresía.
 *
 * Congelar es apartar los días que le quedan al socio y devolvérselos cuando
 * vuelva. Ni se le regalan días ni se le quitan: si le quedaban 18 y congela
 * una semana, al reanudar le quedan 18.
 *
 * CÓMO SE DICE «CONGELADA» SIN TOCAR LA APP NI EL TERMINAL
 * -------------------------------------------------------
 * Los dos clientes que deciden si alguien entra no se pueden modificar, y los
 * dos leen lo mismo que ya existe:
 *
 *   Terminal de recepción → `status` inactive/expired bloquea; si no, compara
 *                           `membership_end_date` con hoy.
 *   App del socio         → solo `plan` + `membership_end_date`.
 *
 * Así que congelar ESCRIBE en esos dos campos: la cuenta pasa a `inactive` y la
 * vigencia se corta en el día anterior. Con eso, los tres sistemas —CRM,
 * terminal y app— coinciden en que ahora mismo no puede entrar, sin desplegar
 * nada en ellos. Lo que solo el CRM sabe —que es un congelamiento y no una baja,
 * y cuántos días se guardaron— vive en las columnas `membership_frozen_*`.
 *
 * SI PAGA MIENTRAS ESTÁ CONGELADO se reanuda solo, y con sus días intactos: la
 * app le muestra la membresía vencida, así que puede renovar desde ahí, y sería
 * inaceptable que ese pago le costara los días que tenía guardados. Lo resuelve
 * {@see MembershipPeriod::apply()}, que pregunta por el congelamiento antes de
 * calcular el periodo nuevo.
 */
class MembershipFreeze
{
    /** Tope de días por congelamiento. Medio año ya no es una pausa. */
    public const MAX_DAYS = 180;

    public const MIN_DAYS = 1;

    /** Atajos que ofrece el CRM. El resto se escribe a mano. */
    public const PRESETS = [7, 15, 30, 60];

    public static function today(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    public function isFrozen(User $user): bool
    {
        return $user->membership_frozen_at !== null;
    }

    /**
     * Congela la membresía y devuelve cómo queda.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException  con el motivo, para que el CRM lo muestre tal cual
     */
    public function freeze(User $user, int $days, ?string $reason = null, ?Admin $actor = null): array
    {
        if ($this->isFrozen($user)) {
            throw new RuntimeException('Esta membresía ya está congelada.');
        }

        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            throw new RuntimeException('El congelamiento va de '.self::MIN_DAYS.' a '.self::MAX_DAYS.' días.');
        }

        $hoy = self::today();
        $fin = $user->membership_end_date
            ? CarbonImmutable::parse($user->membership_end_date, MembershipPeriod::TZ)->startOfDay()
            : null;

        if ($fin === null) {
            throw new RuntimeException('Este socio no tiene una membresía que congelar.');
        }

        if ($fin->lessThan($hoy)) {
            throw new RuntimeException('Su membresía ya está vencida: no hay días que guardar. Cóbrale una nueva.');
        }

        // Los días que se apartan. Es LO ÚNICO que hay que conservar: la fecha
        // de vencimiento se va a mover al pasado para que los otros sistemas
        // denieguen el paso, y con ella se perdería la cuenta.
        $diasGuardados = (int) $hoy->diffInDays($fin);

        DB::transaction(function () use ($user, $days, $reason, $actor, $hoy, $diasGuardados): void {
            $user->forceFill([
                'membership_frozen_at' => $hoy->toDateString(),
                'membership_frozen_until' => $hoy->addDays($days)->toDateString(),
                'membership_frozen_days_left' => $diasGuardados,
                'membership_status_before_freeze' => $user->status,
                'membership_frozen_reason' => $reason,
                'membership_frozen_by' => $actor?->id,
                'membership_frozen_by_name' => $actor?->name,

                // Lo que ven la app y el terminal: cuenta inactiva y vigencia
                // terminada ayer. Ninguno de los dos necesita saber por qué.
                'status' => 'inactive',
                'membership_end_date' => $hoy->subDay()->toDateString(),
            ])->save();

            // `members.status` NO se toca. Ahí viven estados de SEGURIDAD
            // —suspendida, eliminada— que bloquean el login de la app, y una
            // pausa comercial no es ninguno de ellos: el socio tiene que poder
            // entrar a la app a ver cuándo vuelve, o a renovar si cambia de idea.
        });

        return $this->state($user->refresh());
    }

    /**
     * Reanuda: devuelve los días guardados a partir de HOY.
     *
     * `$trigger` dice quién la reanudó —una persona, el trabajo diario o un
     * pago— y solo viaja a la traza de auditoría.
     *
     * @return array<string, mixed>
     */
    public function resume(User $user, ?Admin $actor = null, string $trigger = 'manual'): array
    {
        if (! $this->isFrozen($user)) {
            throw new RuntimeException('Esta membresía no está congelada.');
        }

        $hoy = self::today();
        $guardados = (int) ($user->membership_frozen_days_left ?? 0);
        $actual = $user->membership_end_date
            ? CarbonImmutable::parse($user->membership_end_date, MembershipPeriod::TZ)->startOfDay()
            : null;

        // Si renovó durante el congelamiento, ese periodo se RESPETA y los días
        // guardados se suman detrás. Pisarlo con «hoy + guardados» le quitaría
        // lo que acaba de pagar.
        $base = $actual && $actual->greaterThanOrEqualTo($hoy) ? $actual : $hoy;

        DB::transaction(function () use ($user, $base, $guardados): void {
            $user->forceFill([
                'membership_end_date' => $base->addDays($guardados)->toDateString(),
                'status' => $user->membership_status_before_freeze ?: 'active',
                'membership_frozen_at' => null,
                'membership_frozen_until' => null,
                'membership_frozen_days_left' => null,
                'membership_status_before_freeze' => null,
                'membership_frozen_reason' => null,
                'membership_frozen_by' => null,
                'membership_frozen_by_name' => null,
            ])->save();
        });

        return array_merge($this->state($user->refresh()), [
            'resumed_by' => $trigger,
            'days_returned' => $guardados,
        ]);
    }

    /**
     * Reanuda todas las que ya cumplieron su plazo.
     *
     * Lo llama el trabajo diario. Es idempotente: una membresía que ya se
     * reanudó no vuelve a entrar porque deja de tener fecha de congelamiento.
     *
     * @return list<array{user_id: int, name: string, days_returned: int}>
     */
    public function resumeDue(?CarbonImmutable $today = null): array
    {
        $hoy = $today ?? self::today();

        $vencidos = User::query()
            ->whereNotNull('membership_frozen_until')
            ->whereDate('membership_frozen_until', '<=', $hoy->toDateString())
            ->get();

        $reanudadas = [];
        foreach ($vencidos as $user) {
            $resultado = $this->resume($user, null, 'schedule');
            $reanudadas[] = [
                'user_id' => $user->id,
                'name' => $user->name,
                'days_returned' => (int) $resultado['days_returned'],
            ];
        }

        return $reanudadas;
    }

    /**
     * El congelamiento como lo necesita el CRM: qué pasa hoy y qué pasará al
     * reanudar.
     *
     * @return array<string, mixed>
     */
    public function state(User $user): array
    {
        if (! $this->isFrozen($user)) {
            return ['frozen' => false];
        }

        $hoy = self::today();
        $desde = CarbonImmutable::parse($user->membership_frozen_at, MembershipPeriod::TZ)->startOfDay();
        $hasta = CarbonImmutable::parse($user->membership_frozen_until, MembershipPeriod::TZ)->startOfDay();
        $guardados = (int) ($user->membership_frozen_days_left ?? 0);

        return [
            'frozen' => true,
            'from' => $desde->toDateString(),
            'until' => $hasta->toDateString(),
            // Cuántos días de pausa se pidieron y cuántos faltan.
            'days' => (int) $desde->diffInDays($hasta),
            'days_remaining' => max(0, (int) $hoy->diffInDays($hasta, false)),
            // Los días de membresía que están guardados, y hasta cuándo le
            // valdrá si se reanuda hoy mismo.
            'days_left' => $guardados,
            'resumes_to' => $hasta->addDays($guardados)->toDateString(),
            'was_valid_until' => $desde->addDays($guardados)->toDateString(),
            'reason' => $user->membership_frozen_reason,
            'by' => $user->membership_frozen_by_name,
            // Ya cumplió el plazo y el trabajo diario todavía no ha pasado: el
            // CRM lo dice en vez de enseñar una pausa que ya terminó.
            'due' => $hasta->lessThanOrEqualTo($hoy),
        ];
    }
}
