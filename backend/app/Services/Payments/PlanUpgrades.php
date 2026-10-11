<?php

namespace App\Services\Payments;

use App\Enums\CashShiftType;
use App\Exceptions\CashShiftException;
use App\Http\Controllers\Api\PaymentController;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\MembershipFreeze;
use App\Services\RealtimeEvents;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Mejora de plan: la duración destino descuenta los días calendario consumidos
 * del periodo vigente. Por ejemplo, Trimestre de 90 días menos 9 consumidos
 * deja 81 días desde hoy, con el cobro de la diferencia completa de precios.
 * El periodo sustituido y la vigencia resultante quedan congelados para poder
 * anular o reconstruir sin duplicar días ni alterar el pago mensual original.
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
        $periodo = $this->currentPeriod($user, $actual);
        $motivo = $this->blocker($user, $actual, $nuevo, $fin) ?? $periodo['reason'];
        $consumidos = $periodo['start'] && $periodo['start']->lessThanOrEqualTo($this->today())
            ? (int) $periodo['start']->diffInDays($this->today()) : null;
        $duracion = (int) $nuevo->duration_days;
        $restantes = $consumidos !== null ? max(0, $duracion - $consumidos) : null;
        $nuevoFin = $periodo['start'] && $duracion > 0 ? $periodo['start']->addDays($duracion) : null;
        if ($motivo === null && $restantes <= 0) {
            $motivo = 'Ya consumió la duración del plan destino. Cóbrale una renovación normalmente.';
        }
        if ($motivo === null && $nuevoFin->lessThan($fin)) {
            $motivo = 'Ese cambio recortaría días de la membresía vigente. Revisa el plan destino.';
        }

        $diferencia = $actual && $nuevo->price > $actual->price
            ? (float) $nuevo->price - (float) $actual->price
            : 0.0;

        return [
            'from' => $actual ? ['id' => $actual->id, 'name' => $actual->name, 'price' => (float) $actual->price] : null,
            'to' => ['id' => $nuevo->id, 'name' => $nuevo->name, 'price' => (float) $nuevo->price],
            'amount' => round($diferencia, 2),
            'starts_on' => $periodo['start']?->toDateString(),
            'previous_ends_on' => $fin?->toDateString(),
            'days_consumed' => $consumidos,
            'duration_days' => $duracion,
            'ends_on' => $nuevoFin?->toDateString(),
            'days_left' => $restantes,
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
        return DB::transaction(function () use ($user, $nuevo, $method, $actor, $cashShiftId): array {
            // El cierre de caja toma este mismo bloqueo antes de congelar totales.
            if ($cashShiftId !== null && ! CashShift::openOfType(CashShiftType::GYM)
                ->whereKey($cashShiftId)->lockForUpdate()->first()) {
                throw CashShiftException::noOpenShift();
            }
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $actual = $this->currentPlan($user);
            $planes = Plan::whereIn('id', array_filter([$actual?->id, $nuevo->id]))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $nuevo = $planes->get($nuevo->id);
            if (! $nuevo) {
                throw new RuntimeException('El plan destino ya no está disponible.');
            }
            Payment::where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get();
            $vista = $this->preview($user, $nuevo);

            if (! $vista['can_upgrade']) {
                throw new RuntimeException((string) $vista['reason']);
            }

            $actual = $this->currentPlan($user);
            $periodo = $this->currentPeriod($user, $actual);

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
                // Se sustituye el periodo original, no se encadena otro completo.
                'starts_on' => $vista['starts_on'],
                'period_start' => $vista['starts_on'],
                'period_end' => $vista['ends_on'],
                'upgrade_snapshot' => [
                    'previous_start_date' => $user->membershipStartDate,
                    'previous_end_date' => $user->membershipEndDate,
                    'from_plan_name' => $actual->name,
                    'period_start' => $vista['starts_on'],
                    'new_end_date' => $vista['ends_on'],
                    'source_payment_id' => $periodo['payment']?->id,
                    'days_consumed' => $vista['days_consumed'],
                    'duration_days' => $vista['duration_days'],
                ],
                'origin' => 'counter',
                'registered_by' => $actor?->id,
                'registered_by_name' => $actor?->name,
                'registered_by_role' => $actor?->role,
            ]);

            $user->forceFill([
                'plan' => $nuevo->name,
                'status' => 'active',
                'membership_end_date' => $vista['ends_on'],
            ])->save();
            $this->signal($user);

            return ['payment' => $pago->fresh(), 'preview' => $vista];
        });
    }

    /** Reconfirmar usa el periodo congelado, nunca añade otra duración completa. */
    public function apply(Payment $payment): ?array
    {
        $user = User::find($payment->user_id);
        $plan = Plan::find($payment->plan_id);
        if (! $user || ! $plan) {
            return null;
        }
        $snapshot = $payment->upgrade_snapshot;
        if ($snapshot) {
            $sourceId = $snapshot['source_payment_id'] ?? null;
            if ($sourceId && ! $this->paidPayments($user)->whereKey($sourceId)->exists()) {
                throw ValidationException::withMessages(['status' => [
                    'El cobro que sostiene esta mejora ya no está pagado. Revísalo antes de reconfirmarla.',
                ]]);
            }
            $replacement = $this->paidPayments($user)->whereNotNull('upgraded_from_plan_id')->whereKeyNot($payment->id);
            if ($sourceId) {
                $replacement->where('upgrade_snapshot->source_payment_id', $sourceId);
            } else {
                $replacement->whereNull('upgrade_snapshot->source_payment_id')
                    ->where('upgrade_snapshot->period_start', $snapshot['period_start'])
                    ->where('upgrade_snapshot->previous_end_date', $snapshot['previous_end_date']);
            }
            if ($replacement->exists()) {
                throw ValidationException::withMessages(['status' => [
                    'Otra mejora pagada ya sustituyó este periodo. Revisa sus cobros antes de reconfirmarla.',
                ]]);
            }
            if (! in_array($user->membershipEndDate, [$snapshot['previous_end_date'], $snapshot['new_end_date']], true)) {
                throw ValidationException::withMessages(['status' => [
                    'La vigencia cambió después de esta mejora. Revisa sus periodos antes de reconfirmarla.',
                ]]);
            }
            $user->membership_end_date = $snapshot['new_end_date'];
            $payment->forceFill([
                'starts_on' => $snapshot['period_start'],
                'period_start' => $snapshot['period_start'],
                'period_end' => $snapshot['new_end_date'],
            ])->save();
        } elseif ($this->paidPayments($user)->whereNotNull('upgraded_from_plan_id')
            ->where('id', '>', $payment->id)->exists()) {
            // Sin snapshot no se puede demostrar qué periodo reemplazaba.
            // Reconfirmarla sobre otra mejora duplicaría el diferencial y
            // podría sobrescribir los beneficios de la venta posterior.
            throw ValidationException::withMessages(['status' => [
                'La membresía tiene una mejora posterior. Revísala antes de reconfirmar este cobro histórico.',
            ]]);
        }
        // Las mejoras legacy no compraron días adicionales.
        $user->forceFill(['plan' => $plan->name, 'status' => 'active'])->save();

        return ['start' => $payment->period_start?->toDateString(), 'end' => $user->membershipEndDate,
            'chained' => false, 'upgrade' => true];
    }

    /** @return array{end: string, previous_end: string, days: int, upgrade: bool}|null */
    public function revert(Payment $payment): ?array
    {
        return DB::transaction(function () use ($payment): ?array {
            $user = User::whereKey($payment->user_id)->lockForUpdate()->first();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $user || ! $payment->period_end || ! $user->membershipEndDate) {
                throw ValidationException::withMessages(['status' => [
                    'La membresía tiene cambios posteriores. Revisa sus periodos antes de anular esta mejora.',
                ]]);
            }
            $fin = $this->day($payment->period_end->toDateString());
            $finActual = $this->day($user->membershipEndDate);
            $posteriores = $this->paidPayments($user)->whereKeyNot($payment->id)
                ->whereDate('period_start', '>=', $fin->toDateString())
                ->orderBy('period_start')->orderBy('id')->lockForUpdate()->get();
            $otraMejora = $this->paidPayments($user)->whereNotNull('upgraded_from_plan_id')
                ->where('id', '>', $payment->id)->exists();
            $cierre = $posteriores->last()?->period_end?->toDateString() ?? $fin->toDateString();
            if ($otraMejora || $cierre !== $finActual->toDateString()) {
                Log::warning('Anulación de mejora: otros cambios impiden restaurar el periodo con seguridad',
                    ['payment_id' => $payment->id, 'user_id' => $user->id]);

                throw ValidationException::withMessages(['status' => [
                    'La membresía tiene cambios posteriores. Revisa sus periodos antes de anular esta mejora.',
                ]]);
            }

            $snapshot = $payment->upgrade_snapshot;
            // Una mejora legacy solo devuelve su plan, conservando la vigencia.
            $cursor = $this->day($snapshot['previous_end_date'] ?? $fin->toDateString());
            foreach ($posteriores as $posterior) {
                $dias = (int) $posterior->period_start->diffInDays($posterior->period_end);
                $inicio = $posterior->starts_on ? $this->day($posterior->starts_on->toDateString())
                    : CarbonImmutable::parse($posterior->paid_at ?? now())->setTimezone(MembershipPeriod::TZ)->startOfDay();
                $base = $cursor->greaterThan($inicio) ? $cursor : $inicio;
                $cursor = $base->addDays($dias);
                $posterior->forceFill(['period_start' => $base->toDateString(), 'period_end' => $cursor->toDateString()])->save();
            }
            $plan = $posteriores->last()?->plan?->name
                ?? ($snapshot['from_plan_name'] ?? $payment->upgradedFrom?->name);
            if (! $plan) {
                throw new RuntimeException('No se puede identificar el plan anterior de esta mejora.');
            }
            $user->forceFill(['plan' => $plan, 'membership_end_date' => $cursor->toDateString()])->save();
            if ($snapshot) {
                $payment->forceFill(['period_start' => null, 'period_end' => null])->save();
            }

            return ['end' => $cursor->toDateString(), 'previous_end' => $finActual->toDateString(),
                'days' => (int) $cursor->diffInDays($finActual), 'upgrade' => true];
        });
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

        if ((int) $actual->duration_days <= 0 || (int) $nuevo->duration_days <= 0) {
            return 'Los dos planes deben tener una duración válida para calcular la mejora.';
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

    /** La membresía puede encadenar años; se cuentan los días de ESTE periodo. */
    private function currentPeriod(User $user, ?Plan $actual): array
    {
        $inicio = $this->day($user->membershipStartDate);
        $fin = $this->day($user->membershipEndDate);
        $resultado = ['start' => $inicio, 'payment' => null, 'reason' => null];
        if (! $inicio || ! $fin) {
            return [...$resultado, 'reason' => 'Faltan las fechas de la membresía. Revisa su periodo antes de mejorar el plan.'];
        }
        if ($inicio->greaterThan($this->today())) {
            return [...$resultado, 'reason' => 'La membresía todavía no ha empezado. Espera a su fecha de inicio.'];
        }
        if ($this->paidPayments($user)->whereDate('period_start', '>', $this->today()->toDateString())->exists()) {
            return [...$resultado, 'reason' => 'Tiene renovaciones futuras ya pagadas. Revisa esos periodos antes de mejorar el plan.'];
        }
        $pago = $this->paidPayments($user)->where('plan_id', $actual?->id)
            ->whereDate('period_start', '<=', $this->today()->toDateString())
            ->whereDate('period_end', '>=', $this->today()->toDateString())
            ->orderByDesc('period_start')->orderByDesc('id')->first();
        if ($pago) {
            $resultado = ['start' => $this->day($pago->period_start->toDateString()), 'payment' => $pago, 'reason' => null];
            if ($pago->upgraded_from_plan_id && ! $pago->upgrade_snapshot) {
                return [...$resultado, 'reason' => 'La mejora anterior no tiene el inicio original del periodo. Revisa sus fechas antes de mejorar otra vez.'];
            }
            if ($pago->period_end->toDateString() !== $fin->toDateString()) {
                return [...$resultado, 'reason' => 'La vigencia contiene otros ajustes o periodos. Revisa sus fechas antes de mejorar el plan.'];
            }

            return $resultado;
        }
        // Un cobro anulado o pendiente conserva a veces su periodo mientras se
        // revisa la vigencia. Es evidencia de una cobertura sin pago confirmado,
        // no una importación/cortesía que permita usar las fechas como respaldo.
        if (Payment::where('user_id', $user->id)->where('plan_id', $actual?->id)
            ->whereDate('period_start', '<=', $this->today()->toDateString())
            ->whereDate('period_end', '>=', $this->today()->toDateString())->exists()) {
            return [...$resultado, 'reason' => 'El cobro de este periodo no está pagado. Revisa su estado antes de mejorar el plan.'];
        }
        // Fechas de importaciones/cortesías: se usan solo si describen un
        // periodo del plan, no el inicio histórico de una cadena desconocida.
        if ($inicio->greaterThan($fin) || ($actual && (int) $inicio->diffInDays($fin) > (int) $actual->duration_days)) {
            return [...$resultado, 'reason' => 'No se puede identificar el periodo vigente con esas fechas. Revisa su historial de pagos.'];
        }

        return $resultado;
    }

    private function paidPayments(User $user): Builder
    {
        return Payment::where('user_id', $user->id)->whereNotNull('plan_id')
            ->whereNotNull('period_start')->whereNotNull('period_end')
            ->whereRaw('LOWER(status) IN ('.implode(',', array_fill(0, count(PaymentController::PAID_STATUSES), '?')).')',
                PaymentController::PAID_STATUSES);
    }

    private function day(?string $fecha): ?CarbonImmutable
    {
        return $fecha ? CarbonImmutable::parse(substr($fecha, 0, 10), MembershipPeriod::TZ)->startOfDay() : null;
    }

    private function signal(User $user): void
    {
        $memberId = $user->appMember?->id;
        RealtimeEvents::payment($memberId);
        RealtimeEvents::membership($memberId);
        RealtimeEvents::features($memberId);
    }
}
