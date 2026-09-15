<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Caja\CashReceipts;
use App\Services\Exports\PaymentsExport;
use App\Services\Payments\MembershipPeriod;
use App\Support\Caja\PaymentOrigin;
use App\Support\Members\MembershipFilter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * GET /api/admin/users/{user}/payment-history — la historia de pagos del socio.
 *
 * El perfil enseñaba inicio y vencimiento, y nada más: no se podía saber qué
 * pagó cada vez, por dónde, quién se lo cobró ni qué periodo le cubrió. Esa
 * información existía repartida en cuatro tablas —pagos, desgloses, turnos y
 * cuentas por cobrar— y aquí se junta en una sola respuesta.
 *
 * Todo sale ya calculado en el servidor (estado de la membresía, días, periodo,
 * canal): si el CRM lo dedujera, el perfil podría decir «activa» de alguien a
 * quien Asistencia no deja entrar.
 */
class MemberPaymentHistoryController extends Controller
{
    /** Tope de filas. Un socio de años tiene decenas, no miles. */
    private const LIMIT = 200;

    public function __invoke(User $user): JsonResponse
    {
        $pagos = Payment::query()
            ->where('user_id', $user->id)
            ->with(['plan:id,name,duration_days', 'splits', 'cashShift'])
            ->orderByRaw('COALESCE(paid_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $cuotas = $this->installmentsFor($pagos);
        $cash = app(CashReceipts::class);

        $filas = $pagos->map(fn (Payment $p) => $this->row($p, $cuotas->get($p->id), $cash));

        return response()->json([
            'member' => ['id' => $user->id, 'name' => $user->name],
            'membership' => $this->membership($user),
            'summary' => $this->summary($filas),
            'payments' => $filas->values(),
            'truncated' => $pagos->count() === self::LIMIT,
        ]);
    }

    /**
     * Estado de la membresía, con la misma vara que el filtro de Miembros y
     * que Asistencia: «programada» es pagada con inicio futuro, y no entra.
     *
     * @return array<string, mixed>
     */
    private function membership(User $user): array
    {
        $hoy = MembershipFilter::today();
        $inicio = $user->membership_start_date
            ? CarbonImmutable::parse($user->membership_start_date, MembershipPeriod::TZ)->startOfDay()
            : null;
        $fin = $user->membership_end_date
            ? CarbonImmutable::parse($user->membership_end_date, MembershipPeriod::TZ)->startOfDay()
            : null;

        $diasRestantes = $fin ? (int) $hoy->diffInDays($fin, false) : null;
        $diasParaEmpezar = $inicio && $inicio->greaterThan($hoy) ? (int) $hoy->diffInDays($inicio) : null;

        $estado = match (true) {
            $fin === null => 'none',
            $diasRestantes < 0 => 'expired',
            $diasParaEmpezar !== null => 'scheduled',
            $diasRestantes <= MembershipFilter::EXPIRING_SOON_DAYS => 'expiring',
            default => 'active',
        };

        $total = $inicio && $fin ? max(0, (int) $inicio->diffInDays($fin, false)) : null;
        $transcurridos = $inicio && $total !== null
            ? min($total, max(0, (int) $inicio->diffInDays($hoy, false)))
            : null;

        return [
            'plan' => $user->plan,
            'start' => $inicio?->toDateString(),
            'end' => $fin?->toDateString(),
            'state' => $estado,
            'state_label' => match ($estado) {
                'none' => 'Sin membresía',
                'expired' => 'Vencida',
                'scheduled' => 'Programada',
                'expiring' => 'Por vencer',
                default => 'Activa',
            },
            // Asistencia usa exactamente esta regla para abrir o no la puerta.
            'can_enter' => in_array($estado, ['active', 'expiring'], true),
            'days_left' => $diasRestantes,
            'days_until_start' => $diasParaEmpezar,
            'total_days' => $total,
            'elapsed_days' => $transcurridos,
            'progress' => $total ? round(($transcurridos ?? 0) / $total * 100, 1) : null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    private function summary(Collection $filas): array
    {
        // Solo dinero: el asiento de un plan a plazos es el contrato, y lo que
        // entró de él son sus abonos.
        $cobrados = $filas->where('status_group', 'paid');
        $dinero = $cobrados->sum(fn (array $f) => $f['is_accrual']
            ? (float) ($f['installments']['paid'] ?? 0)
            : (float) $f['amount']);

        $porCanal = fn (string $canal) => $cobrados->where('channel', $canal)->count();

        return [
            'total_paid' => round($dinero, 2),
            'payments_count' => $cobrados->count(),
            'via_gym' => $porCanal('gym'),
            'via_app' => $porCanal('app'),
            'via_legacy' => $porCanal('legacy'),
            'pending_count' => $filas->where('status_group', 'pending')->count(),
            'outstanding_balance' => round((float) $filas->sum(fn (array $f) => (float) ($f['installments']['balance'] ?? 0)), 2),
            'last_paid_at' => $cobrados->first()['paid_at'] ?? null,
            'first_paid_at' => $cobrados->last()['paid_at'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function row(Payment $p, ?Receivable $cuenta, CashReceipts $cash): array
    {
        $canal = $p->channel();
        $turno = $p->cashShift;
        $encadenado = $p->starts_on && $p->period_start && $p->period_start->greaterThan($p->starts_on);

        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'amount' => (float) $p->amount,
            'status' => $p->status,
            'status_group' => $this->statusGroup($p->status),
            'paid_at' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),

            'method' => $p->method,
            'method_label' => $cash->isAccrual($p->method)
                ? 'Plan a plazos'
                : PaymentsExport::methodLabel($p->method),
            'is_mixed' => $p->isMixed(),
            'splits' => $p->splits->map(fn ($s) => [
                'method' => $s->method,
                'method_label' => PaymentsExport::methodLabel($s->method),
                'amount' => (float) $s->amount,
                'reference' => $s->reference,
            ])->values(),

            'plan' => $p->plan ? [
                'id' => $p->plan->id,
                'name' => $p->plan->name,
                'duration_days' => (int) $p->plan->duration_days,
            ] : null,

            // El inicio que se pidió y el periodo que de verdad cubrió. Difieren
            // cuando se renovó antes de vencer: el periodo se encadena.
            'starts_on' => $p->starts_on?->toDateString(),
            'period_start' => $p->period_start?->toDateString(),
            'period_end' => $p->period_end?->toDateString(),
            'chained' => (bool) $encadenado,

            'channel' => $canal,
            'channel_label' => PaymentOrigin::channelLabelFor($canal),
            'registered_by' => $p->registered_by_name ? [
                'id' => $p->registered_by,
                'name' => $p->registered_by_name,
                'role' => $p->registered_by_role,
            ] : null,
            'cash_shift' => $turno ? [
                'id' => $turno->id,
                'opened_by_name' => $turno->opened_by_name,
                'opened_at' => $turno->opened_at?->toIso8601String(),
                'closed_at' => $turno->closed_at?->toIso8601String(),
                'is_open' => $turno->isOpen(),
            ] : null,

            'is_accrual' => $cash->isAccrual($p->method),
            'installments' => $cuenta ? $this->installments($cuenta) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function installments(Receivable $cuenta): array
    {
        return [
            'receivable_id' => $cuenta->id,
            'total' => $cuenta->total()->toFloat(),
            'paid' => $cuenta->paidAmount()->toFloat(),
            'balance' => $cuenta->isCancelled() ? 0.0 : $cuenta->balance()->toFloat(),
            'status' => $cuenta->status,
            'status_label' => Receivable::statusLabel((string) $cuenta->status),
            'due_at' => $cuenta->due_at ? substr((string) $cuenta->due_at, 0, 10) : null,
            'payments' => $cuenta->payments
                ->sortBy('id')
                ->map(fn (ReceivablePayment $a) => [
                    'id' => $a->id,
                    'amount' => (float) $a->amount,
                    'method_label' => PaymentsExport::methodLabel($a->method),
                    'status' => $a->status,
                    'created_at' => $a->created_at?->toIso8601String(),
                    'created_by_name' => $a->created_by_name,
                    'cash_shift_id' => $a->cash_shift_id,
                ])->values(),
        ];
    }

    /**
     * Cuentas por cobrar de los planes a plazos, por id de pago.
     *
     * @param  Collection<int, Payment>  $pagos
     * @return Collection<int, Receivable>
     */
    private function installmentsFor(Collection $pagos): Collection
    {
        $ids = $pagos->filter(fn (Payment $p) => app(CashReceipts::class)->isAccrual($p->method))->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }

        return Receivable::query()
            ->where('source_type', Payment::class)
            ->whereIn('source_id', $ids->all())
            ->with('payments')
            ->get()
            ->keyBy('source_id');
    }

    private function statusGroup(?string $status): string
    {
        $clave = strtolower(trim((string) $status));

        return match (true) {
            in_array($clave, PaymentController::PAID_STATUSES, true) => 'paid',
            in_array($clave, PaymentController::PENDING_STATUSES, true) => 'pending',
            $clave === 'refunded' || $clave === 'reembolsado' => 'refunded',
            default => 'failed',
        };
    }
}
