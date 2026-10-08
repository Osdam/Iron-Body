<?php

namespace App\Services\Membership;

use App\Models\Admin;
use App\Models\MembershipAdjustment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SUMAR DÍAS Y ENTRADAS a mano, dejando dicho quién y por qué.
 *
 * Va a hacer falta todos los meses: se fue la luz, el lector no lo reconoció y
 * quedó marcado dos veces, estuvo enfermo una semana, el recepcionista le vendió
 * el plan equivocado. Antes eso se resolvía editándole la fecha de vencimiento al
 * socio en la ficha, y el rastro se perdía: al mes siguiente nadie sabía si esos
 * días se los dio el dueño o se los puso alguien solo.
 *
 * Aquí cada ajuste queda escrito. Y como son días regalados —dinero— la acción
 * está detrás de su propio permiso (`members.adjust`), que el gimnasio le da a
 * quien quiera desde Usuarios y roles.
 *
 * SE PUEDE RESTAR. Un ajuste negativo corrige uno anterior sin borrarlo: la
 * historia queda entera, con el error y su corrección.
 */
class MembershipAdjustments
{
    /** Tope por ajuste. Más que eso no es un ajuste, es un plan nuevo. */
    public const MAX_DAYS = 365;

    public const MAX_ENTRIES = 100;

    /** Atajos que ofrece el CRM. */
    public const DAY_PRESETS = [1, 3, 7, 15, 30];

    public const ENTRY_PRESETS = [1, 2, 5, 10];

    /**
     * Suma o quita días de vigencia. Negativo = quitar.
     *
     * @return array{adjustment: MembershipAdjustment, membership: array{previous_end: string, end: string}}
     *
     * @throws RuntimeException
     */
    public function addDays(User $user, int $days, ?string $reason = null, ?Admin $actor = null): array
    {
        if ($days === 0) {
            throw new RuntimeException('Indica cuántos días quieres sumar o quitar.');
        }
        if (abs($days) > self::MAX_DAYS) {
            throw new RuntimeException('El ajuste no puede pasar de '.self::MAX_DAYS.' días.');
        }

        // Una membresía congelada tiene la vigencia movida al pasado a propósito
        // —es lo que hace que el terminal y la app bloqueen el paso—, así que
        // tocarle los días ahí los tiraría a la basura al reanudarse.
        if (app(MembershipFreeze::class)->isFrozen($user)) {
            throw new RuntimeException('Esta membresía está congelada. Reanúdala primero y después ajústale los días.');
        }

        $hoy = MembershipFreeze::today();
        $fin = $this->day($user->membership_end_date);
        $inicio = $this->day($user->membership_start_date);

        /*
         * DE DÓNDE SE CUENTA, que no es lo mismo al sumar que al quitar.
         *
         * SUMANDO, si ya venció se cuenta desde HOY: quien regala tres días
         * quiere que el socio entre tres días, y ponerlos detrás de un
         * vencimiento de hace un mes no le devolvería el acceso, que es justo
         * lo que se está intentando.
         *
         * QUITANDO se cuenta siempre desde su vencimiento. Recortar «desde hoy»
         * le quitaría de golpe todo lo que le quedaba: a quien le sobran veinte
         * días y se le quitan tres, le tienen que quedar diecisiete.
         */
        if ($days < 0) {
            if ($fin === null) {
                throw new RuntimeException('Este socio no tiene vigencia que recortar.');
            }
            $base = $fin;
        } else {
            $base = $fin && $fin->greaterThanOrEqualTo($hoy) ? $fin : $hoy;
        }

        $nuevo = $base->addDays($days);

        // El único límite de verdad: una vigencia no puede terminar antes de
        // empezar. Que quede vencida en el pasado SÍ se permite — es lo que
        // hace falta para deshacer unos días que se dieron por error el mes
        // pasado—, y queda registrado con su motivo y su responsable.
        if ($inicio !== null && $nuevo->lessThan($inicio)) {
            throw new RuntimeException(
                'Con ese descuento la membresía terminaría antes de empezar ('
                .$inicio->toDateString().'). Revisa la cantidad.'
            );
        }

        $previo = $fin?->toDateString() ?? '—';

        $ajuste = DB::transaction(function () use ($user, $days, $reason, $actor, $fin, $nuevo): MembershipAdjustment {
            $ajuste = MembershipAdjustment::create([
                'user_id' => $user->id,
                'kind' => MembershipAdjustment::KIND_DAYS,
                'amount' => $days,
                'previous_end_date' => $fin?->toDateString(),
                'new_end_date' => $nuevo->toDateString(),
                'reason' => $reason,
                'created_by' => $actor?->id,
                'created_by_name' => $actor?->name,
            ]);

            $user->membership_end_date = $nuevo->toDateString();
            // Sin inicio no habría desde cuándo contar: se le pone el día en que
            // se le dieron los días, que es cuando de verdad empieza a valerle.
            if (! $user->membership_start_date) {
                $user->membership_start_date = MembershipFreeze::today()->toDateString();
            }
            $user->save();

            return $ajuste;
        });

        return [
            'adjustment' => $ajuste,
            'membership' => ['previous_end' => $previo, 'end' => $nuevo->toDateString()],
        ];
    }

    /**
     * Suma (o resta) entradas del periodo en curso.
     *
     * @return array{adjustment: MembershipAdjustment, access: array<string, mixed>}
     *
     * @throws RuntimeException
     */
    public function addEntries(User $user, int $entries, ?string $reason = null, ?Admin $actor = null): array
    {
        if ($entries === 0) {
            throw new RuntimeException('Indica cuántas entradas quieres sumar.');
        }
        if (abs($entries) > self::MAX_ENTRIES) {
            throw new RuntimeException('El ajuste no puede pasar de '.self::MAX_ENTRIES.' entradas.');
        }

        $acceso = app(MembershipAccess::class);

        // Regalar entradas a un plan ilimitado no significa nada: ese socio ya
        // entra cuando quiere. Decirlo es más útil que guardar un ajuste que no
        // va a tener efecto en ninguna parte.
        if (! $acceso->rulesFor($user)->isByEntries()) {
            throw new RuntimeException('El plan de este socio no es por consumo: sus entradas no se cuentan.');
        }

        $ventana = $acceso->entriesWindow($user);

        $ajuste = MembershipAdjustment::create([
            'user_id' => $user->id,
            'kind' => MembershipAdjustment::KIND_ENTRIES,
            'amount' => $entries,
            'window_start' => $ventana['from']->toDateString(),
            'window_end' => $ventana['to']->toDateString(),
            'reason' => $reason,
            'created_by' => $actor?->id,
            'created_by_name' => $actor?->name,
        ]);

        return [
            'adjustment' => $ajuste,
            'access' => $acceso->state($user->refresh()),
        ];
    }

    /**
     * La historia de ajustes de un socio, lo último primero.
     *
     * @return Collection<int, MembershipAdjustment>
     */
    public function history(User $user, int $limit = 50): Collection
    {
        return MembershipAdjustment::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 200)))
            ->get();
    }

    /**
     * Un ajuste como lo lee el CRM.
     *
     * @return array<string, mixed>
     */
    public function toArray(MembershipAdjustment $ajuste): array
    {
        return [
            'id' => $ajuste->id,
            'kind' => $ajuste->kind,
            'amount' => (int) $ajuste->amount,
            'label' => $ajuste->label(),
            'reason' => $ajuste->reason,
            'by' => $ajuste->created_by_name,
            'created_at' => $ajuste->created_at?->toIso8601String(),
            'previous_end_date' => $ajuste->previous_end_date?->toDateString(),
            'new_end_date' => $ajuste->new_end_date?->toDateString(),
            'window' => $ajuste->window_start ? [
                'from' => $ajuste->window_start->toDateString(),
                'to' => $ajuste->window_end?->toDateString(),
            ] : null,
        ];
    }

    private function day(mixed $fecha): ?CarbonImmutable
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        return CarbonImmutable::parse(substr((string) $fecha, 0, 10), MembershipAccess::TZ)->startOfDay();
    }
}
