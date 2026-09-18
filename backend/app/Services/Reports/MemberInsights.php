<?php

namespace App\Services\Reports;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Payment;
use App\Models\User;
use App\Support\Members\MembershipFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * LA POBLACIÓN DEL GIMNASIO: quién entra, quién se queda y quién se fue.
 *
 * Las altas se cuentan por `users.created_at` —cuándo se registró la ficha— y
 * no por su primer pago: son dos preguntas distintas y mezclarlas hacía que un
 * socio de hace dos años que hoy renueva apareciera como «nuevo».
 *
 * LA RETENCIÓN se mide sobre el pasado, no sobre el futuro: de las membresías
 * que vencieron dentro del periodo, cuántas volvieron a pagar después. Por eso
 * un periodo que termina hoy da siempre una retención más baja de la real —a
 * los que vencieron ayer todavía les queda margen para volver—, y el informe lo
 * dice en vez de esconderlo.
 */
final class MemberInsights
{
    public function __construct(private readonly ReportRange $range) {}

    public function forRange(ReportRange $range): self
    {
        return new self($range);
    }

    /**
     * @return array<string, int>
     */
    public function totals(): array
    {
        [$desde, $hasta] = $this->range->utcBounds();
        $hoy = MembershipFilter::today()->toDateString();
        $cuenta = MembershipFilter::accountExpr();

        $fila = User::query()->selectRaw(
            'COUNT(*) as total,'
            .' COUNT(CASE WHEN users.created_at BETWEEN ? AND ? THEN 1 END) as nuevos,'
            .' COUNT(CASE WHEN '.$cuenta.' IN (?, ?, ?) THEN 1 END) as inactivos,'
            .' COUNT(CASE WHEN membership_end_date >= ? THEN 1 END) as vigentes,'
            .' COUNT(CASE WHEN membership_end_date < ? THEN 1 END) as vencidos,'
            .' COUNT(CASE WHEN membership_end_date IS NULL THEN 1 END) as sin_membresia',
            [$desde, $hasta, 'inactive', 'inactivo', 'inactiva', $hoy, $hoy]
        )->first();

        $retencion = $this->retention();

        return [
            'members' => (int) $fila->total,
            'new' => (int) $fila->nuevos,
            'inactive' => (int) $fila->inactivos,
            'active' => (int) $fila->vigentes,
            'expired' => (int) $fila->vencidos,
            'without_membership' => (int) $fila->sin_membresia,
            'expired_in_range' => $retencion['expired'],
            'recovered' => $retencion['recovered'],
            'lost' => $retencion['lost'],
        ];
    }

    /**
     * De los que vencieron dentro del periodo, cuántos volvieron a pagar.
     *
     * @return array{expired: int, recovered: int, lost: int, rate: float}
     */
    public function retention(): array
    {
        $vencidos = User::query()
            ->whereNotNull('membership_end_date')
            ->whereBetween('membership_end_date', [
                $this->range->from->toDateString(),
                $this->range->to->toDateString(),
            ]);

        $total = (clone $vencidos)->count();
        $recuperados = (clone $vencidos)->whereExists($this->paidAfterExpiry())->count();

        return [
            'expired' => $total,
            'recovered' => $recuperados,
            'lost' => $total - $recuperados,
            'rate' => $total > 0 ? round($recuperados / $total * 100, 1) : 0.0,
        ];
    }

    /** Socios que vencieron en el periodo y NO han vuelto: la lista de rescate. */
    public function lapsed(int $page = 1, int $perPage = 24, string $search = ''): array
    {
        $q = User::query()
            ->whereNotNull('membership_end_date')
            ->whereBetween('membership_end_date', [
                $this->range->from->toDateString(),
                $this->range->to->toDateString(),
            ])
            ->whereNotExists($this->paidAfterExpiry())
            ->orderByDesc('membership_end_date');

        return app(MembershipInsights::class)->pageOf($q, $page, $perPage, $search);
    }

    /**
     * Altas de socios en el periodo, listas para el gráfico.
     *
     * @return list<array{period: string, label: string, count: int}>
     */
    public function signupSeries(?string $granularity = null): array
    {
        $grano = $granularity ?? $this->range->granularity();
        [$desde, $hasta] = $this->range->utcBounds();
        $bucket = ReportSql::businessDate('users.created_at');

        $porDia = User::query()
            ->whereBetween('users.created_at', [$desde, $hasta])
            ->selectRaw("{$bucket} as periodo, COUNT(*) as n")
            ->groupByRaw($bucket)
            ->pluck('n', 'periodo')
            ->map(fn ($v) => (float) $v)
            ->all();

        $plegado = ReportCalendar::fold($porDia, $grano);

        return array_map(fn (array $p) => [
            'period' => $p['key'],
            'label' => $p['label'],
            'count' => (int) ($plegado[$p['key']] ?? 0),
        ], ReportCalendar::buckets($this->range, $grano));
    }

    /**
     * Buscador de socios para la ficha individual. Devuelve lo justo para
     * elegir a una persona; su historial completo lo sirve el perfil.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $op = ReportSql::likeOperator();
        $like = ReportSql::like($term);

        return User::query()
            ->where(fn (Builder $w) => $w
                ->where('name', $op, $like)
                ->orWhere('document', $op, $like)
                ->orWhere('email', $op, $like)
                ->orWhere('phone', $op, $like))
            ->orderBy('name')
            ->limit(max(1, min(50, $limit)))
            ->get(['id', 'name', 'document', 'plan', 'status', 'membership_start_date', 'membership_end_date'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'document' => $u->document,
                'plan' => $u->plan,
                'end' => $u->membership_end_date,
            ])
            ->all();
    }

    /** «Pagó un plan DESPUÉS de que se le venciera la membresía». */
    private function paidAfterExpiry(): \Closure
    {
        $pagados = PaymentController::PAID_STATUSES;

        return function ($q) use ($pagados): void {
            $q->select(\Illuminate\Support\Facades\DB::raw(1))
                ->from('payments')
                ->whereColumn('payments.user_id', 'users.id')
                ->whereNotNull('payments.plan_id')
                ->whereRaw('LOWER(payments.status) IN ('.ReportSql::marks($pagados).')', $pagados)
                ->whereRaw('DATE(COALESCE(payments.paid_at, payments.created_at)) > users.membership_end_date');
        };
    }
}
