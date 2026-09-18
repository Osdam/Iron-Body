<?php

namespace App\Services\Reports;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Payment;
use App\Models\User;
use App\Support\Members\MembershipFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * EL ESTADO DE LAS MEMBRESÍAS, hoy.
 *
 * Es una foto del presente y no del periodo: cuántos socios pueden entrar
 * ahora, a cuántos se les vence esta semana y quién lleva doce días vencido.
 * Mover el filtro de fechas no cambia estas cifras, y eso es correcto —«¿cuántos
 * vencidos tengo?» no es una pregunta sobre septiembre, es sobre hoy—; lo que
 * sí depende del periodo son las ventas y las renovaciones, que viven en
 * {@see SalesInsights}.
 *
 * UNA MEMBRESÍA NO ES UN SOCIO. Un socio con tres membresías históricas
 * vencidas y una vigente está ACTIVO: cuenta una vez, y por la que vale. Contar
 * filas de historial fue justo lo que hacía que el panel dijera que había más
 * vencidos que miembros.
 *
 * Las reglas de qué es «activa», «por vencer» o «vencida» no se reinventan
 * aquí: son las de {@see MembershipFilter}, que es lo que aplica el listado de
 * Miembros y la exportación. Si divergieran, el informe diría 25 vencidos y la
 * lista enseñaría 31.
 */
final class MembershipInsights
{
    /** Ventanas de «vence pronto» que ofrece el informe, en días. */
    public const EXPIRING_BUCKETS = [0, 1, 3, 5, 7, 15, 30];

    /** Tramos de antigüedad del vencimiento, en días: [desde, hasta]. */
    public const OVERDUE_BUCKETS = [
        ['key' => '1_3', 'label' => '1 a 3 días', 'from' => 1, 'to' => 3],
        ['key' => '4_7', 'label' => '4 a 7 días', 'from' => 4, 'to' => 7],
        ['key' => '8_15', 'label' => '8 a 15 días', 'from' => 8, 'to' => 15],
        ['key' => '16_30', 'label' => '16 a 30 días', 'from' => 16, 'to' => 30],
        ['key' => '30_plus', 'label' => 'Más de 30 días', 'from' => 31, 'to' => null],
    ];

    public function today(): CarbonImmutable
    {
        return MembershipFilter::today();
    }

    /**
     * Las cifras de la portada de Membresías, en una sola consulta.
     *
     * @return array<string, int>
     */
    public function snapshot(): array
    {
        $hoy = $this->today()->toDateString();
        $en7 = $this->today()->addDays(7)->toDateString();
        $en30 = $this->today()->addDays(30)->toDateString();
        $cuenta = MembershipFilter::accountExpr();

        $fila = User::query()->selectRaw(
            'COUNT(*) as total,'
            // Vigente: tiene fecha de fin que no ha pasado y ya empezó.
            .' COUNT(CASE WHEN membership_end_date >= ? AND (membership_start_date IS NULL OR membership_start_date <= ?) THEN 1 END) as activas,'
            // Programadas: pagadas, pero empiezan más adelante.
            .' COUNT(CASE WHEN membership_start_date > ? THEN 1 END) as programadas,'
            .' COUNT(CASE WHEN membership_end_date >= ? AND membership_end_date <= ? THEN 1 END) as por_vencer_7,'
            .' COUNT(CASE WHEN membership_end_date >= ? AND membership_end_date <= ? THEN 1 END) as por_vencer_30,'
            .' COUNT(CASE WHEN membership_end_date < ? THEN 1 END) as vencidas,'
            .' COUNT(CASE WHEN membership_end_date IS NULL THEN 1 END) as sin_membresia,'
            .' COUNT(CASE WHEN '.$cuenta.' IN (?, ?, ?) THEN 1 END) as cuentas_inactivas',
            [$hoy, $hoy, $hoy, $hoy, $en7, $hoy, $en30, $hoy, 'inactive', 'inactivo', 'inactiva']
        )->first();

        return [
            'members' => (int) $fila->total,
            'active' => (int) $fila->activas,
            'scheduled' => (int) $fila->programadas,
            'expiring_7' => (int) $fila->por_vencer_7,
            'expiring_30' => (int) $fila->por_vencer_30,
            'expired' => (int) $fila->vencidas,
            'without_membership' => (int) $fila->sin_membresia,
            'inactive_accounts' => (int) $fila->cuentas_inactivas,
        ];
    }

    /**
     * Cuántas membresías vigentes hay de cada plan. Solo las que valen: incluir
     * las vencidas mezclaría el plan que alguien tuvo en 2024 con el que paga
     * hoy, y la tarta dejaría de significar nada.
     *
     * @return list<array{plan: string, count: int, share: float}>
     */
    public function byPlan(): array
    {
        $hoy = $this->today()->toDateString();

        $filas = User::query()
            ->whereNotNull('membership_end_date')
            ->where('membership_end_date', '>=', $hoy)
            ->selectRaw("COALESCE(NULLIF(plan, ''), 'Sin plan') as plan, COUNT(*) as n")
            ->groupByRaw("COALESCE(NULLIF(plan, ''), 'Sin plan')")
            ->get();

        $total = (int) $filas->sum('n');

        return $filas
            ->map(fn ($f) => [
                'plan' => (string) $f->plan,
                'count' => (int) $f->n,
                'share' => $total > 0 ? round((int) $f->n / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Cuántas vencen en cada ventana. Acumulativo a propósito: «en 7 días»
     * incluye las de mañana, porque quien llama por teléfono quiere una lista,
     * no cinco listas que se solapan.
     *
     * @return list<array{days: int, label: string, count: int}>
     */
    public function expiringBuckets(): array
    {
        $hoy = $this->today();

        return array_map(function (int $dias) use ($hoy) {
            $n = User::query()
                ->whereNotNull('membership_end_date')
                ->whereBetween('membership_end_date', [$hoy->toDateString(), $hoy->addDays($dias)->toDateString()])
                ->count();

            return [
                'days' => $dias,
                'label' => match ($dias) {
                    0 => 'Vencen hoy',
                    1 => 'Hoy y mañana',
                    default => 'En '.$dias.' días',
                },
                'count' => $n,
            ];
        }, self::EXPIRING_BUCKETS);
    }

    /**
     * Cuántos vencidos hay en cada tramo de antigüedad. El tramo importa: a
     * quien venció ayer se le llama; a quien lleva cuatro meses, no es la misma
     * conversación.
     *
     * @return list<array{key: string, label: string, count: int}>
     */
    public function overdueBuckets(): array
    {
        $hoy = $this->today();

        return array_map(function (array $tramo) use ($hoy) {
            $desde = $hoy->subDays($tramo['to'] ?? 3650)->toDateString();
            $hasta = $hoy->subDays($tramo['from'])->toDateString();

            return [
                'key' => $tramo['key'],
                'label' => $tramo['label'],
                'count' => User::query()
                    ->whereNotNull('membership_end_date')
                    ->whereBetween('membership_end_date', [$desde, $hasta])
                    ->count(),
            ];
        }, self::OVERDUE_BUCKETS);
    }

    /**
     * Las membresías que vencen dentro de N días, con lo necesario para actuar:
     * a quién llamar, por qué plan y cuánto le queda.
     *
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function expiring(int $days = 7, int $page = 1, int $perPage = 24, string $search = ''): array
    {
        $hoy = $this->today();

        $q = User::query()
            ->whereNotNull('membership_end_date')
            ->whereBetween('membership_end_date', [$hoy->toDateString(), $hoy->addDays(max(0, $days))->toDateString()])
            ->orderBy('membership_end_date');

        return $this->pageOf($q, $page, $perPage, $search);
    }

    /**
     * Los vencidos, del más reciente al más antiguo: el de ayer es el que se
     * recupera, y va primero.
     *
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function expired(?string $bucket = null, int $page = 1, int $perPage = 24, string $search = ''): array
    {
        $hoy = $this->today();

        $q = User::query()
            ->whereNotNull('membership_end_date')
            ->where('membership_end_date', '<', $hoy->toDateString())
            ->orderByDesc('membership_end_date');

        $tramo = collect(self::OVERDUE_BUCKETS)->firstWhere('key', $bucket);
        if ($tramo) {
            $q->whereBetween('membership_end_date', [
                $hoy->subDays($tramo['to'] ?? 3650)->toDateString(),
                $hoy->subDays($tramo['from'])->toDateString(),
            ]);
        }

        return $this->pageOf($q, $page, $perPage, $search);
    }

    /**
     * Página de socios con su membresía y su último pago.
     *
     * El último pago se resuelve para TODA la página de una vez: preguntarlo
     * socio a socio convertía una lista de 24 tarjetas en 25 consultas.
     */
    public function pageOf(Builder $query, int $page, int $perPage, string $search = ''): array
    {
        $perPage = max(1, min(60, $perPage));

        if (trim($search) !== '') {
            $op = ReportSql::likeOperator();
            $like = ReportSql::like($search);
            $query->where(fn (Builder $w) => $w
                ->where('users.name', $op, $like)
                ->orWhere('users.document', $op, $like)
                ->orWhere('users.phone', $op, $like));
        }

        $pagina = $query->paginate($perPage, [
            'id', 'name', 'document', 'phone', 'email', 'plan', 'status',
            'membership_start_date', 'membership_end_date',
        ], 'page', max(1, $page));

        $ultimos = $this->lastPaymentsFor($pagina->getCollection()->pluck('id'));
        $hoy = $this->today();

        return [
            'data' => $pagina->getCollection()->map(function (User $u) use ($ultimos, $hoy): array {
                $fin = $u->membership_end_date
                    ? CarbonImmutable::parse($u->membership_end_date, ReportRange::TZ)->startOfDay()
                    : null;
                $dias = $fin ? (int) $hoy->diffInDays($fin, false) : null;
                $pago = $ultimos->get($u->id);

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'document' => $u->document,
                    'phone' => $u->phone,
                    'plan' => $u->plan ?: 'Sin plan',
                    'account_status' => $u->status,
                    'start' => $u->membership_start_date,
                    'end' => $u->membership_end_date,
                    'days_left' => $dias,
                    // Positivo = lleva vencido tantos días. El CRM no tiene que
                    // interpretar un número negativo para poder escribir
                    // «lleva 12 días vencido».
                    'days_overdue' => $dias !== null && $dias < 0 ? abs($dias) : 0,
                    'last_payment_at' => $pago?->paid_at?->toIso8601String() ?? $pago?->created_at?->toIso8601String(),
                    'last_payment_amount' => $pago ? (float) $pago->amount : null,
                ];
            })->values()->all(),
            'total' => $pagina->total(),
            'page' => $pagina->currentPage(),
            'per_page' => $pagina->perPage(),
            'last_page' => $pagina->lastPage(),
        ];
    }

    /**
     * Último pago cobrado de cada socio de la lista.
     *
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, Payment>
     */
    private function lastPaymentsFor(Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        $pagados = PaymentController::PAID_STATUSES;

        return Payment::query()
            ->whereIn('user_id', $userIds->all())
            ->whereRaw('LOWER(status) IN ('.ReportSql::marks($pagados).')', $pagados)
            ->orderBy('user_id')
            // Ascendente A PROPÓSITO: `keyBy` conserva la ÚLTIMA fila de cada
            // clave, así que la que queda es la más reciente. Con orden
            // descendente se quedaría el pago más viejo del socio.
            ->orderBy('id')
            ->get(['id', 'user_id', 'amount', 'paid_at', 'created_at'])
            ->keyBy('user_id');
    }
}
