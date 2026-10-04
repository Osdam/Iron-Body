<?php

namespace App\Services\Reports;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Support\Members\MembershipFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * LA POBLACIÓN DEL GIMNASIO: quién entra, quién se queda y quién se fue.
 *
 * Las altas se cuentan por `users.created_at` —cuándo se registró la ficha— y
 * no por su primer pago: son dos preguntas distintas y mezclarlas hacía que un
 * socio de hace dos años que hoy renueva apareciera como «nuevo».
 *
 * Y NO CUENTAN LAS FICHAS IMPORTADAS. El export del sistema anterior se vuelve
 * a cargar cada tanto, y para esas fichas `created_at` es el día de la carga, no
 * el día en que el socio se inscribió. Sin excluirlas, el periodo de la carga
 * enseñaba miles de altas y el siguiente un desplome del 99 % — una caída que
 * nunca ocurrió. Se cuentan aparte, en `imported`, que sí es una cifra real:
 * cuántas fichas viejas entraron al sistema en este periodo.
 *
 * LA RETENCIÓN se mide sobre el pasado, no sobre el futuro: de las membresías
 * que vencieron dentro del periodo, cuántas volvieron a pagar después. Por eso
 * un periodo que termina hoy da siempre una retención más baja de la real —a
 * los que vencieron ayer todavía les queda margen para volver—, y el informe lo
 * dice en vez de esconderlo.
 */
final class MemberInsights
{
    /** Zona del negocio: «el día que volvió» es un día de Neiva, no de UTC. */
    private const TZ = Member::BUSINESS_TZ;

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
            .' COUNT(CASE WHEN users.created_at BETWEEN ? AND ? AND users.imported_at IS NULL THEN 1 END) as nuevos,'
            .' COUNT(CASE WHEN users.imported_at BETWEEN ? AND ? THEN 1 END) as importados,'
            .' COUNT(CASE WHEN '.$cuenta.' IN (?, ?, ?) THEN 1 END) as inactivos,'
            .' COUNT(CASE WHEN membership_end_date >= ? THEN 1 END) as vigentes,'
            .' COUNT(CASE WHEN membership_end_date < ? THEN 1 END) as vencidos,'
            .' COUNT(CASE WHEN membership_end_date IS NULL THEN 1 END) as sin_membresia',
            [$desde, $hasta, $desde, $hasta, 'inactive', 'inactivo', 'inactiva', $hoy, $hoy]
        )->first();

        $retencion = $this->retention();

        return [
            'members' => (int) $fila->total,
            'new' => (int) $fila->nuevos,
            'imported' => (int) $fila->importados,
            'inactive' => (int) $fila->inactivos,
            'active' => (int) $fila->vigentes,
            'expired' => (int) $fila->vencidos,
            'without_membership' => (int) $fila->sin_membresia,
            'returned' => $retencion['returned'],
            'lost' => $retencion['lost'],
        ];
    }

    /**
     * QUIÉN VOLVIÓ Y QUIÉN NO, medido sobre dos cosas distintas a propósito.
     *
     * POR QUÉ NO ES UN «X DE Y». La versión anterior contaba los socios cuya
     * `membership_end_date` caía en el periodo y, de esos, los que tenían un pago
     * posterior a esa fecha. Daba CERO siempre, y no por falta de gente que
     * volviera: al renovar, `membership_end_date` se mueve hacia delante. Quien
     * vuelve deja de tener la fecha de vencimiento dentro del periodo —sale del
     * grupo— y además su pago pasa a ser anterior a su nuevo vencimiento, así que
     * la condición «pagó después de vencer» tampoco se cumple nunca. El contador
     * estaba midiendo un conjunto del que, por construcción, ya habían salido
     * todos los que hacía falta contar.
     *
     * Así que se miden las dos cosas donde de verdad están:
     *
     *   `returned`  en los PAGOS: quién pagó dentro del periodo estando vencido.
     *               Esa es la vuelta, y queda registrada aunque el socio haya
     *               vencido hace medio año.
     *   `lost`      en los SOCIOS: a quién se le venció dentro del periodo y
     *               sigue sin volver. Esa cuenta sí funcionaba, justamente porque
     *               el que vuelve se sale del grupo.
     *
     * @return array{returned: int, lost: int, avg_days_away: float|null}
     */
    public function retention(): array
    {
        $vueltas = $this->reactivations();
        $dias = array_column($vueltas, 'days_away');

        return [
            'returned' => count($vueltas),
            'lost' => $this->lapsedQuery()->count(),
            // Cuánto tardan en volver. Le dice al gimnasio a los cuántos días
            // tiene sentido llamar al que no ha renovado.
            'avg_days_away' => $dias === [] ? null : round(array_sum($dias) / count($dias), 1),
        ];
    }

    /**
     * Socios que PAGARON dentro del periodo estando vencidos.
     *
     * La vuelta se reconstruye desde el historial de pagos, no desde la fecha de
     * vencimiento que hoy tiene el socio: esa ya la movió el propio pago que se
     * quiere detectar.
     *
     * De cada pago se conoce hasta cuándo cubrió: `period_end` si está congelado
     * en el pago, y si no —los pagos anteriores a que eso se guardara— el día de
     * cobro más la duración de su plan. Con eso, un pago es una VUELTA cuando el
     * socio ya tenía pagos antes y la cobertura de todos ellos se había agotado
     * antes de que empezara el nuevo periodo.
     *
     * Una renovación anticipada no cuenta: ahí la cobertura anterior todavía
     * alcanza al día en que empieza el periodo nuevo, que es exactamente lo que
     * significa no haber estado vencido.
     *
     * @return list<array{user_id: int, days_away: int, returned_on: string}>
     */
    public function reactivations(): array
    {
        [$desde, $hasta] = $this->range->utcBounds();
        $pagados = PaymentController::PAID_STATUSES;

        $enRango = Payment::query()
            ->whereNotNull('plan_id')
            ->whereRaw('LOWER(payments.status) IN ('.ReportSql::marks($pagados).')', $pagados)
            ->whereRaw('COALESCE(payments.paid_at, payments.created_at) BETWEEN ? AND ?', [$desde, $hasta])
            ->get(['id', 'user_id', 'plan_id', 'paid_at', 'created_at', 'period_start', 'period_end']);

        if ($enRango->isEmpty()) {
            return [];
        }

        $socios = $enRango->pluck('user_id')->unique()->all();

        // El historial COMPLETO de esos socios: hace falta para saber si estaban
        // cubiertos el día que volvieron a pagar.
        $historial = Payment::query()
            ->whereIn('user_id', $socios)
            ->whereNotNull('plan_id')
            ->whereRaw('LOWER(payments.status) IN ('.ReportSql::marks($pagados).')', $pagados)
            ->get(['id', 'user_id', 'plan_id', 'paid_at', 'created_at', 'period_start', 'period_end'])
            ->groupBy('user_id');

        $duraciones = Plan::query()->pluck('duration_days', 'id');

        $vueltas = [];
        foreach ($enRango->groupBy('user_id') as $userId => $pagosDelPeriodo) {
            /** @var \Illuminate\Support\Collection<int, Payment> $todos */
            $todos = $historial->get($userId) ?? collect();

            // Solo la PRIMERA vuelta del periodo: un socio que vuelve y renueva
            // otra vez el mismo mes volvió una vez, no dos.
            $pagosDelPeriodo = $pagosDelPeriodo->sortBy(fn (Payment $p) => $this->startDay($p));

            foreach ($pagosDelPeriodo as $pago) {
                $inicio = $this->startDay($pago);

                $anteriores = $todos->filter(fn (Payment $p) => $p->id !== $pago->id
                    && $this->startDay($p) < $inicio);

                // Sin pagos anteriores es un socio nuevo, no uno que vuelve. Los
                // nuevos ya se cuentan en su propia cifra.
                if ($anteriores->isEmpty()) {
                    continue;
                }

                $cobertura = $anteriores
                    ->map(fn (Payment $p) => $this->coverageEnd($p, $duraciones))
                    ->max();

                if ($cobertura === null || $cobertura >= $inicio) {
                    continue; // seguía vigente: es una renovación, no una vuelta
                }

                $vueltas[] = [
                    'user_id' => (int) $userId,
                    'days_away' => (int) $cobertura->diffInDays($inicio),
                    'returned_on' => $inicio->toDateString(),
                ];

                break;
            }
        }

        return $vueltas;
    }

    /** Socios que vencieron en el periodo y NO han vuelto: la lista de rescate. */
    public function lapsed(int $page = 1, int $perPage = 24, string $search = ''): array
    {
        return app(MembershipInsights::class)->pageOf(
            $this->lapsedQuery()->orderByDesc('membership_end_date'),
            $page,
            $perPage,
            $search,
        );
    }

    /**
     * La consulta de los que se fueron, en un solo sitio: la usan el contador y
     * la lista, y si discreparan el CRM enseñaría un número que no cuadra con la
     * gente que sale al abrirlo.
     *
     * Una membresía CONGELADA queda fuera a propósito: congelar mueve la fecha
     * de vencimiento al pasado —es lo que hace que el terminal y la app bloqueen
     * el paso— y sin excluirla cada socio de vacaciones aparecería en la lista de
     * rescate como si se hubiera ido sin renovar.
     */
    private function lapsedQuery(): Builder
    {
        return User::query()
            ->whereNotNull('membership_end_date')
            ->whereBetween('membership_end_date', [
                $this->range->from->toDateString(),
                $this->range->to->toDateString(),
            ])
            ->whereNull('membership_frozen_at')
            ->whereNotExists($this->paidAfterExpiry());
    }

    /**
     * El día en que empieza a contar un pago: el periodo que compró si está
     * congelado en él, y si no el día en que entró el dinero.
     */
    private function startDay(Payment $pago): CarbonImmutable
    {
        if ($pago->period_start) {
            return CarbonImmutable::parse($pago->period_start->format('Y-m-d'), self::TZ)->startOfDay();
        }

        $fecha = $pago->paid_at ?? $pago->created_at;

        return $fecha
            ? CarbonImmutable::parse($fecha)->setTimezone(self::TZ)->startOfDay()
            : CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /**
     * Hasta cuándo cubrió un pago.
     *
     * `period_end` es la respuesta buena, pero solo existe desde que se empezó a
     * congelar el periodo en el propio pago. Para los anteriores se reconstruye
     * con la duración del plan, que es lo que se le vendió al socio.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $duraciones
     */
    private function coverageEnd(Payment $pago, $duraciones): ?CarbonImmutable
    {
        if ($pago->period_end) {
            return CarbonImmutable::parse($pago->period_end->format('Y-m-d'), self::TZ)->startOfDay();
        }

        $dias = (int) ($duraciones[$pago->plan_id] ?? 0);

        return $dias > 0 ? $this->startDay($pago)->addDays($dias) : null;
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
            ->whereNull('users.imported_at')
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
