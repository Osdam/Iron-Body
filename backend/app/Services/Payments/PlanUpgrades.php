<?php

namespace App\Services\Payments;

use App\Models\Admin;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\MembershipFreeze;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * MEJORAR DE PLAN A MITAD DE PERIODO.
 *
 * Paga el Mensual el día 1 y el día 8 quiere el Total Access. Pasa todas las
 * semanas, y hasta ahora no había forma de registrarlo sin ensuciar la caja:
 * o se le cobraba el plan nuevo entero —y el sistema le encadenaba treinta
 * días al final de los que ya tenía, regalándole un mes— o se anulaba el cobro
 * anterior y se rehacía, moviendo dinero de un turno que a lo mejor ya estaba
 * cerrado.
 *
 * LAS DOS REGLAS, y las dos se eligieron por lo mismo: que se puedan explicar
 * de pie en el mostrador.
 *
 *   SE COBRA LA DIFERENCIA DE PRECIO, completa y sin prorratear.
 *   «El Total Access cuesta 150, el Mensual 80, pagas 70.»
 *
 *   EL VENCIMIENTO NO SE MUEVE. Se mejora lo que le queda del periodo que ya
 *   compró; no se le vende uno nuevo. Así nadie se lleva días de regalo ni
 *   pierde los que pagó.
 *
 * SOLO HACIA ARRIBA. Bajar de plan obligaría a devolver dinero, y devolver
 * dinero es otro producto —con su autorización, su registro y su efecto en el
 * arqueo— que nadie ha pedido. Quien quiera bajar, espera a que venza.
 *
 * EL IMPORTE LO CALCULA EL SERVIDOR, siempre. Si llegara del cliente, el
 * precio de una mejora sería lo que diga el navegador.
 */
class PlanUpgrades
{
    /**
     * Qué pasaría si mejorara a ese plan. No escribe nada.
     *
     * Devuelve también los motivos por los que NO se puede, porque el
     * mostrador necesita decirle algo a la persona que tiene delante, no
     * encontrarse un botón apagado.
     *
     * @return array<string, mixed>
     */
    public function preview(User $user, Plan $nuevo): array
    {
        $actual = $this->currentPlan($user);
        $fin = $this->endDate($user);
        $motivo = $this->blocker($user, $actual, $nuevo, $fin);

        $diferencia = $actual && $nuevo->price > $actual->price
            ? (float) $nuevo->price - (float) $actual->price
            : 0.0;

        return [
            'from' => $actual ? ['id' => $actual->id, 'name' => $actual->name, 'price' => (float) $actual->price] : null,
            'to' => ['id' => $nuevo->id, 'name' => $nuevo->name, 'price' => (float) $nuevo->price],
            'amount' => $diferencia,
            // El vencimiento NO se mueve: es la mitad de la explicación.
            'ends_on' => $fin?->toDateString(),
            'days_left' => $fin ? max(0, $this->today()->diffInDays($fin, false)) : null,
            'can_upgrade' => $motivo === null,
            'reason' => $motivo,
        ];
    }

    /**
     * Registra la mejora: el cobro de la diferencia y el cambio de plan.
     *
     * El pago se crea aquí y no por la vía normal porque la vía normal haría
     * lo contrario de lo que hace falta: sumarle al socio la duración del plan
     * nuevo. `upgraded_from_plan_id` es lo que marca la diferencia, y
     * {@see MembershipPeriod::apply()} lo respeta.
     *
     * @return array{payment: Payment, preview: array<string, mixed>}
     */
    public function upgrade(User $user, Plan $nuevo, string $method, ?Admin $actor = null, ?int $cashShiftId = null): array
    {
        $vista = $this->preview($user, $nuevo);

        if (! $vista['can_upgrade']) {
            throw new RuntimeException((string) $vista['reason']);
        }

        $actual = $this->currentPlan($user);
        $fin = $this->endDate($user);
        $hoy = $this->today();

        $pago = Payment::create([
            'user_id' => $user->id,
            'plan_id' => $nuevo->id,
            'upgraded_from_plan_id' => $actual?->id,
            'amount' => $vista['amount'],
            'method' => $method,
            'status' => 'paid',
            // El dinero entra HOY, como cualquier cobro de mostrador.
            'paid_at' => now(),
            'cash_shift_id' => $cashShiftId,
            // El periodo que esta mejora cubre: de hoy hasta el vencimiento
            // que ya tenía. Congelado, igual que en cualquier otro cobro, para
            // que anularlo sepa exactamente qué deshacer.
            'starts_on' => $hoy->toDateString(),
            'period_start' => $hoy->toDateString(),
            'period_end' => $fin?->toDateString(),
            'origin' => 'counter',
            'registered_by' => $actor?->id,
            'registered_by_name' => $actor?->name,
            'registered_by_role' => $actor?->role,
        ]);

        // El plan cambia; las fechas no.
        $user->forceFill([
            'plan' => $nuevo->name,
            'status' => 'active',
        ])->save();

        return ['payment' => $pago->fresh(), 'preview' => $vista];
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Por qué NO se puede mejorar, dicho para leerlo en voz alta.
     */
    private function blocker(User $user, ?Plan $actual, Plan $nuevo, ?CarbonImmutable $fin): ?string
    {
        if (! $actual) {
            return 'No tiene un plan vigente que mejorar. Véndele el plan nuevo normalmente.';
        }

        if ($fin === null || $fin->lessThan($this->today())) {
            return 'Su membresía ya venció: esto es una renovación, no una mejora. Cóbrale el plan nuevo normalmente.';
        }

        if (app(MembershipFreeze::class)->isFrozen($user)) {
            return 'Su membresía está congelada. Reanúdala antes de mejorar el plan.';
        }

        if ($nuevo->id === $actual->id) {
            return 'Ya tiene ese plan.';
        }

        if (! $nuevo->active) {
            return 'Ese plan está inactivo.';
        }

        if ((float) $nuevo->price <= (float) $actual->price) {
            return 'Solo se puede mejorar a un plan más caro. Para bajar de plan hay que esperar a que venza.';
        }

        return null;
    }

    /**
     * El plan que tiene hoy. `users.plan` guarda el NOMBRE —es una columna de
     * texto heredada—, así que se busca normalizado: el sistema anterior
     * escribió el mismo plan de varias maneras.
     */
    public function currentPlan(User $user): ?Plan
    {
        $nombre = trim((string) ($user->plan ?? ''));

        if ($nombre === '') {
            return null;
        }

        return Plan::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($nombre)])
            ->first();
    }

    private function endDate(User $user): ?CarbonImmutable
    {
        return $user->membership_end_date
            ? CarbonImmutable::parse($user->membership_end_date, MembershipPeriod::TZ)->startOfDay()
            : null;
    }

    private function today(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }
}
