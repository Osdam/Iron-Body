<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/reports/overview — agregados de Analítica (pagos + miembros).
 *
 * El módulo de reportes los calculaba en el navegador descargando pagos y
 * miembros página por página, con un techo de 10 páginas (200 filas por
 * recurso): además de lento, los KPIs quedaban SILENCIOSAMENTE truncados en
 * cuanto el gimnasio pasaba de 200 pagos. Aquí todo sale de consultas agregadas
 * sobre la tabla completa.
 *
 * Clases y planes NO se agregan aquí: son catálogos pequeños y acotados, y el
 * CRM sigue leyéndolos directo para conservar exactamente la misma semántica de
 * cupos (`enrolled_count` depende de la próxima ocurrencia de cada clase).
 */
class ReportsOverviewController extends Controller
{
    /** Filas de actividad reciente por fuente (el CRM muestra 20 tras filtrar). */
    private const ACTIVITY_LIMIT = 200;

    /** Días de la serie de ingresos (el gráfico muestra el último año). */
    private const SERIES_DAYS = 365;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : CarbonImmutable::now()->endOfDay();
        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])->startOfDay()
            : $to->subDays(29)->startOfDay();

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => array_merge(
                $this->paymentKpis($from, $to),
                $this->memberKpis($from, $to),
            ),
            'revenue_series' => $this->revenueSeries(),
            'plan_sales' => $this->planSales(),
            'members_by_status' => $this->membersByStatus(),
            'payments_by_status' => $this->paymentsByStatus(),
            'activity' => $this->activity(),
        ]);
    }

    /** Expresión SQL de la fecha efectiva de un pago (cobro o creación). */
    private function paymentDateExpr(): string
    {
        return 'COALESCE(payments.paid_at, payments.created_at)';
    }

    /** `IN (?, ?, …)` para una lista de estados. */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /** Ingresos totales, del período y pagos pendientes. */
    private function paymentKpis(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $paid = PaymentController::PAID_STATUSES;
        $pending = PaymentController::PENDING_STATUSES;
        $date = $this->paymentDateExpr();

        $row = Payment::query()->selectRaw(
            'COALESCE(SUM(CASE WHEN LOWER(status) IN ('.$this->placeholders($paid).') THEN amount ELSE 0 END), 0) as total_revenue,'
            .' COALESCE(SUM(CASE WHEN LOWER(status) IN ('.$this->placeholders($paid).')'
            .' AND '.$date.' BETWEEN ? AND ? THEN amount ELSE 0 END), 0) as period_revenue,'
            .' COUNT(CASE WHEN LOWER(status) IN ('.$this->placeholders($pending).') THEN 1 END) as pending_payments',
            array_merge($paid, $paid, [$from, $to], $pending)
        )->first();

        return [
            'total_revenue' => round((float) ($row->total_revenue ?? 0), 2),
            'period_revenue' => round((float) ($row->period_revenue ?? 0), 2),
            'pending_payments' => (int) ($row->pending_payments ?? 0),
        ];
    }

    /**
     * Estado normalizado del miembro en SQL. Espejo de `memberStatusKey` del CRM:
     * un estado vacío o nulo cuenta como activo.
     */
    private function memberStatusExpr(): string
    {
        return "LOWER(COALESCE(NULLIF(status, ''), 'active'))";
    }

    /** Condición SQL "membresía vencida" (estado vencido o fecha ya pasada). */
    private function expiredExpr(): string
    {
        return '('.$this->memberStatusExpr()." IN ('expired', 'vencido', 'vencida')"
            .' OR (membership_end_date IS NOT NULL AND membership_end_date < ?))';
    }

    private function memberKpis(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = User::query()->selectRaw(
            'COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) as new_members',
            [$from, $to]
        )->first();

        return ['new_members' => (int) ($row->new_members ?? 0)];
    }

    /** Conteo de miembros por estado (activos / inactivos / vencidos / pendientes). */
    private function membersByStatus(): array
    {
        $status = $this->memberStatusExpr();
        $expired = $this->expiredExpr();
        $now = CarbonImmutable::now();

        $row = User::query()->selectRaw(
            "COUNT(CASE WHEN {$status} IN ('active', 'activo', 'activa') AND NOT {$expired} THEN 1 END) as active,"
            ." COUNT(CASE WHEN {$status} IN ('inactive', 'inactivo', 'inactiva') THEN 1 END) as inactive,"
            ." COUNT(CASE WHEN {$expired} THEN 1 END) as expired,"
            ." COUNT(CASE WHEN {$status} IN ('pending', 'pendiente') THEN 1 END) as pending",
            [$now, $now]
        )->first();

        return [
            'active' => (int) ($row->active ?? 0),
            'inactive' => (int) ($row->inactive ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'pending' => (int) ($row->pending ?? 0),
        ];
    }

    /** Conteo de pagos por estado, con el mismo vocabulario que el CRM. */
    private function paymentsByStatus(): array
    {
        $paid = PaymentController::PAID_STATUSES;
        $pending = PaymentController::PENDING_STATUSES;
        $failed = PaymentController::FAILED_STATUSES;

        $row = Payment::query()->selectRaw(
            'COUNT(CASE WHEN LOWER(status) IN ('.$this->placeholders($paid).') THEN 1 END) as paid,'
            .' COUNT(CASE WHEN LOWER(status) IN ('.$this->placeholders($pending).') THEN 1 END) as pending,'
            .' COUNT(CASE WHEN LOWER(status) IN ('.$this->placeholders($failed).') THEN 1 END) as cancelled',
            array_merge($paid, $pending, $failed)
        )->first();

        return [
            'paid' => (int) ($row->paid ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
        ];
    }

    /** Ingresos diarios del último año, con los días sin cobros en cero. */
    private function revenueSeries(): array
    {
        $end = CarbonImmutable::now()->endOfDay();
        $start = $end->subDays(self::SERIES_DAYS - 1)->startOfDay();
        $paid = PaymentController::PAID_STATUSES;
        $date = $this->paymentDateExpr();

        $rows = Payment::query()
            ->selectRaw("DATE({$date}) as day, COALESCE(SUM(amount), 0) as revenue")
            ->whereRaw('LOWER(status) IN ('.$this->placeholders($paid).')', $paid)
            ->whereRaw("{$date} BETWEEN ? AND ?", [$start, $end])
            ->groupByRaw("DATE({$date})")
            ->pluck('revenue', 'day');

        $series = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $key = $day->toDateString();
            $series[] = ['date' => $key, 'revenue' => (int) round((float) ($rows[$key] ?? 0))];
        }

        return $series;
    }

    /**
     * Pagos cobrados por nombre de plan. Se incluyen con cero los planes del
     * catálogo y los valores de `users.plan` sin ventas, igual que el CRM.
     */
    private function planSales(): array
    {
        $paid = PaymentController::PAID_STATUSES;

        $sales = Payment::query()
            ->leftJoin('plans', 'plans.id', '=', 'payments.plan_id')
            ->whereRaw('LOWER(payments.status) IN ('.$this->placeholders($paid).')', $paid)
            ->selectRaw("COALESCE(plans.name, 'Sin plan') as plan, COUNT(*) as sales")
            ->groupByRaw("COALESCE(plans.name, 'Sin plan')")
            ->pluck('sales', 'plan');

        $counts = [];
        foreach ($sales as $plan => $total) {
            $name = trim((string) $plan);
            if ($name === '') {
                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + (int) $total;
        }

        // Planes del catálogo y planes asignados a miembros, aunque no vendieran.
        $catalog = Plan::query()->pluck('name')
            ->merge(User::query()->whereNotNull('plan')->distinct()->pluck('plan'));
        foreach ($catalog as $name) {
            $name = trim((string) $name);
            if ($name !== '' && ! array_key_exists($name, $counts)) {
                $counts[$name] = 0;
            }
        }

        arsort($counts);

        // Ojo: las claves numéricas de un array PHP se vuelven int (un plan
        // llamado "2024"), así que el nombre se re-castea a string.
        return collect($counts)
            ->take(8)
            ->map(fn ($sales, $plan) => ['plan' => (string) $plan, 'sales' => (int) $sales])
            ->values()
            ->all();
    }

    /**
     * Actividad reciente de pagos y miembros, con las mismas etiquetas que
     * construía el CRM. Las filas de clases las sigue añadiendo el cliente.
     */
    private function activity(): array
    {
        $rows = [];

        $payments = Payment::query()
            ->with(['user:id,name', 'plan:id,name'])
            ->orderByRaw($this->paymentDateExpr().' DESC')
            ->limit(self::ACTIVITY_LIMIT)
            ->get(['id', 'user_id', 'plan_id', 'amount', 'status', 'paid_at', 'created_at']);

        foreach ($payments as $payment) {
            $planName = $payment->plan?->name ? 'Plan '.$payment->plan->name : 'Pago';
            $userName = $payment->user?->name ? ' - '.$payment->user->name : '';

            $rows[] = [
                'date' => optional($payment->paid_at ?? $payment->created_at)->toDateString(),
                'type' => 'Pago',
                'description' => $planName.$userName,
                'value' => (float) $payment->amount,
                'status' => $this->paymentStatusLabel($payment->status),
            ];
        }

        $now = CarbonImmutable::now();
        $users = User::query()
            ->orderByDesc('created_at')
            ->limit(self::ACTIVITY_LIMIT)
            ->get(['id', 'name', 'plan', 'status', 'membership_end_date', 'created_at']);

        foreach ($users as $user) {
            $statusKey = strtolower(trim((string) ($user->status ?: 'active')));
            $endDate = $user->membership_end_date ? CarbonImmutable::parse($user->membership_end_date) : null;
            $expired = in_array($statusKey, ['expired', 'vencido', 'vencida'], true)
                || ($endDate !== null && $endDate->lessThan($now));
            $pending = in_array($statusKey, ['pending', 'pendiente'], true);
            $planSuffix = $user->plan ? ' - '.$user->plan : '';

            $rows[] = [
                'date' => ($endDate?->toDateString()) ?? optional($user->created_at)->toDateString(),
                'type' => $expired ? 'Membresía' : 'Miembro',
                'description' => match (true) {
                    $expired => "Membresía vencida: {$user->name}{$planSuffix}",
                    $pending => "Miembro pendiente: {$user->name}{$planSuffix}",
                    default => "Miembro activo: {$user->name}{$planSuffix}",
                },
                'value' => 0,
                'status' => match (true) {
                    $expired => 'Vencida',
                    $pending => 'Pendiente',
                    default => 'Activo',
                },
            ];
        }

        // Más reciente primero; las filas sin fecha van al final.
        usort($rows, fn (array $a, array $b) => strcmp((string) $b['date'], (string) $a['date']));

        return $rows;
    }

    /** Etiqueta del CRM para el estado de un pago. */
    private function paymentStatusLabel(?string $status): string
    {
        $key = strtolower(trim((string) $status));

        if (in_array($key, PaymentController::PAID_STATUSES, true)) {
            return 'Pagado';
        }
        if (in_array($key, PaymentController::FAILED_STATUSES, true)) {
            return 'Anulado';
        }

        return 'Pendiente';
    }
}
