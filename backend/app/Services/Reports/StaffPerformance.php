<?php

namespace App\Services\Reports;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Services\Caja\CashReceipts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * QUÉ HIZO CADA PERSONA DEL EQUIPO.
 *
 * La pregunta que lo motiva es literal: «¿qué vendió Eduar el 12 de septiembre?».
 * Para contestarla hay que juntar cuatro rastros que viven en cuatro tablas
 * —cobros, abonos, ventas de productos y la auditoría del CRM— y que se firman
 * de tres maneras distintas ({@see StaffIdentity}).
 *
 * TRES PAPELES QUE NO SON EL MISMO, y que este informe no mezcla:
 *
 *   VENDIÓ el plan          → quien registró la venta (`payments.registered_by`)
 *   COBRÓ el dinero         → quien registró el cobro o el abono
 *   TOCÓ el registro        → quien creó, modificó o anuló algo (auditoría)
 *
 * En el flujo actual del CRM el mismo endpoint crea la venta y su cobro, así
 * que para un pago de contado las dos primeras coinciden. Donde de verdad se
 * separan es en los planes a plazos: uno vende, y el saldo lo cobra quien esté
 * en el mostrador semanas después. Por eso los abonos suman a quien los cobró y
 * nunca al vendedor del plan.
 *
 * LO QUE NO SE PUEDE ATRIBUIR se dice. Los cobros anteriores a que el CRM
 * guardara quién cobraba no tienen responsable, y aparecen agrupados aparte en
 * vez de repartirse entre el equipo.
 */
final class StaffPerformance
{
    public function __construct(private readonly ReportRange $range) {}

    public function forRange(ReportRange $range): self
    {
        return new self($range);
    }

    /**
     * El equipo, para el selector: las cuentas del CRM más cualquier firma que
     * aparezca en los datos y ya no tenga cuenta (alguien que se fue).
     *
     * @return list<array{key: string, admin_id: int|null, name: string, role: string|null, status: string}>
     */
    public function roster(): array
    {
        $cuentas = Admin::query()
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'status'])
            ->map(fn (Admin $a) => [
                'key' => StaffIdentity::key($a->id, $a->name),
                'admin_id' => $a->id,
                'name' => $a->name,
                'role' => $a->role,
                'status' => $a->status,
            ]);

        $vistas = collect($this->leaderboard())
            ->reject(fn (array $f) => $cuentas->contains('key', $f['key']))
            ->map(fn (array $f) => [
                'key' => $f['key'],
                'admin_id' => $f['admin_id'],
                'name' => $f['name'],
                'role' => $f['role'],
                'status' => 'historic',
            ]);

        return $cuentas->concat($vistas)->values()->all();
    }

    /**
     * Ranking del periodo: lo cobrado y lo vendido por cada persona, en una
     * sola tabla.
     *
     * @return list<array<string, mixed>>
     */
    public function leaderboard(): array
    {
        $dinero = collect((new MoneyLedger($this->range))->byStaff())->keyBy('key');
        $ventas = collect((new SalesInsights($this->range))->byStaff())->keyBy('key');
        $acciones = $this->auditCounts();

        return $dinero->keys()
            ->merge($ventas->keys())
            ->merge($acciones->keys())
            ->unique()
            ->map(function (string $clave) use ($dinero, $ventas, $acciones) {
                $d = $dinero->get($clave);
                $v = $ventas->get($clave);

                return [
                    'key' => $clave,
                    'admin_id' => $d['admin_id'] ?? $v['admin_id'] ?? null,
                    'name' => $d['name'] ?? $v['name'] ?? StaffIdentity::UNKNOWN_LABEL,
                    'role' => $d['role'] ?? $v['role'] ?? null,
                    'collected' => (float) ($d['amount'] ?? 0),
                    'operations' => (int) ($d['count'] ?? 0),
                    'by_source' => $d['by_source'] ?? array_fill_keys(MoneyLedger::SOURCES, 0.0),
                    'sales' => (int) ($v['count'] ?? 0),
                    'sold' => (float) ($v['sold'] ?? 0),
                    'actions' => (int) ($acciones[$clave] ?? 0),
                ];
            })
            ->sortByDesc('collected')
            ->values()
            ->all();
    }

    /**
     * La ficha de una persona en el periodo: sus cifras, qué planes vendió y
     * en qué días trabajó.
     *
     * @return array<string, mixed>
     */
    public function profile(string $staffKey): array
    {
        $dinero = new MoneyLedger($this->range, ['staff' => $staffKey]);
        $ventas = new SalesInsights($this->range, ['staff' => $staffKey]);

        $identidad = collect($this->roster())->firstWhere('key', $staffKey) ?? [
            'key' => $staffKey,
            'admin_id' => null,
            'name' => StaffIdentity::UNKNOWN_LABEL,
            'role' => null,
            'status' => 'historic',
        ];

        return [
            'staff' => $identidad,
            'range' => $this->range->toArray(),
            'money' => $dinero->totals(),
            'by_method' => $dinero->byMethod(),
            'sales' => $ventas->totals(),
            'by_plan' => $ventas->byPlan(),
            'series' => $dinero->series(),
            'sales_series' => $ventas->series(),
            'days' => $this->activeDays($staffKey),
            'actions' => (int) ($this->auditCounts()[$staffKey] ?? 0),
        ];
    }

    /**
     * Los días del periodo en que esa persona registró algo, con el resumen de
     * cada uno. Es el índice de la línea de tiempo: se toca un día y se ve
     * qué pasó.
     *
     * @return list<array{date: string, collected: float, operations: int, sales: int}>
     */
    public function activeDays(string $staffKey): array
    {
        $dinero = new MoneyLedger($this->range, ['staff' => $staffKey]);
        $ventas = new SalesInsights($this->range, ['staff' => $staffKey]);

        $porDia = [];
        foreach ($dinero->series('day') as $punto) {
            if ($punto['total'] > 0) {
                $porDia[$punto['period']] = [
                    'date' => $punto['period'],
                    'collected' => $punto['total'],
                    'operations' => 0,
                    'sales' => 0,
                ];
            }
        }

        foreach ($ventas->series('day') as $punto) {
            if ($punto['count'] > 0) {
                $porDia[$punto['period']] ??= [
                    'date' => $punto['period'],
                    'collected' => 0.0,
                    'operations' => 0,
                    'sales' => 0,
                ];
                $porDia[$punto['period']]['sales'] = $punto['count'];
            }
        }

        krsort($porDia);

        return array_values($porDia);
    }

    /**
     * LA LÍNEA DE TIEMPO de un día concreto.
     *
     * Todo lo que consta de esa persona ese día, en orden: cobros, abonos,
     * ventas de productos, planes vendidos a plazos y las acciones que dejaron
     * traza en la auditoría (modificar un socio, anular un pago…).
     *
     * Solo eventos REALES. Si una acción no quedó registrada, aquí no aparece
     * inventada; la auditoría del CRM es lo que hay, y lo que no pasó por ella
     * no existe para este informe.
     *
     * @return array{date: string, staff: array<string,mixed>, summary: array<string,mixed>, events: list<array<string,mixed>>}
     */
    public function timeline(string $staffKey, string $date): array
    {
        $dia = ReportRange::fromRequest(['from' => $date, 'to' => $date]);
        $dinero = new MoneyLedger($dia, ['staff' => $staffKey]);
        $ventas = new SalesInsights($dia, ['staff' => $staffKey]);

        $eventos = collect($dinero->rows(1, 100)['data'])
            ->map(fn (array $m) => [
                'at' => $m['date'],
                'kind' => $m['source'],
                'title' => match ($m['source']) {
                    MoneyLedger::SOURCE_MEMBERSHIP => 'Cobró '.$m['concept'],
                    MoneyLedger::SOURCE_INSTALLMENT => 'Recibió un '.mb_strtolower($m['concept']),
                    default => 'Registró la '.mb_strtolower($m['concept']),
                },
                'member' => $m['member_name'],
                'member_id' => $m['member_id'],
                'amount' => $m['amount'],
                'detail' => $m['method_label'],
                'reference' => $m['reference'],
                'entity_id' => $m['id'],
            ]);

        // Planes vendidos a plazos: no son dinero (el dinero son sus abonos),
        // pero sí son trabajo suyo de ese día, y sin esto desaparecían.
        $aPlazos = $ventas->sales()
            ->whereRaw('LOWER(payments.method) = ?', [CashReceipts::ACCRUAL_METHOD])
            ->with(['user:id,name', 'plan:id,name'])
            ->get()
            ->map(fn (Payment $p) => [
                'at' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
                'kind' => 'plan_credit',
                'title' => 'Vendió a plazos el plan '.($p->plan?->name ?? ''),
                'member' => $p->user?->name,
                'member_id' => $p->user_id,
                'amount' => (float) $p->amount,
                'detail' => 'Se cobra en abonos',
                'reference' => $p->reference,
                'entity_id' => $p->id,
            ]);

        $trazas = $this->auditEvents($staffKey, $dia);

        $todos = $eventos->concat($aPlazos)->concat($trazas)
            ->sortBy('at')
            ->values();

        return [
            'date' => $dia->from->toDateString(),
            'staff' => collect($this->roster())->firstWhere('key', $staffKey),
            'summary' => [
                'collected' => $dinero->totals()['collected'],
                'operations' => $dinero->totals()['count'],
                'sales' => $ventas->totals()['count'],
                'sold' => $ventas->totals()['sold'],
                'actions' => $trazas->count(),
            ],
            'events' => $todos->all(),
        ];
    }

    /**
     * Acciones auditadas de esa persona en el día, sin repetir lo que ya cuenta
     * el dinero: la traza de «registró un cobro» sería la misma línea dos veces.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function auditEvents(string $staffKey, ReportRange $dia): Collection
    {
        [$desde, $hasta] = $dia->utcBounds();

        $q = AuditLog::query()->whereBetween('created_at', [$desde, $hasta]);
        $this->constrainAudit($q, $staffKey);

        return $q->orderBy('created_at')
            ->limit(200)
            ->get()
            ->reject(fn (AuditLog $l) => $l->action === 'create' && in_array($l->entity, ['pago', 'payment', 'abono', 'venta'], true))
            ->map(fn (AuditLog $l) => [
                'at' => $l->created_at?->toIso8601String(),
                'kind' => 'audit',
                'title' => $l->summary ?: ucfirst((string) $l->action).' en '.$l->module,
                'member' => $l->target_name,
                'member_id' => null,
                'amount' => null,
                'detail' => $l->module,
                'reference' => null,
                'entity_id' => $l->entity_id,
            ])
            ->values();
    }

    /**
     * Cuántas acciones auditadas tiene cada persona en el periodo.
     *
     * @return Collection<string, int>
     */
    private function auditCounts(): Collection
    {
        [$desde, $hasta] = $this->range->utcBounds();

        return AuditLog::query()
            ->whereBetween('created_at', [$desde, $hasta])
            ->groupBy('actor_id', 'actor_name')
            ->selectRaw('actor_id, actor_name, COUNT(*) as n')
            ->get()
            ->mapWithKeys(fn ($f) => [
                StaffIdentity::key(is_numeric($f->actor_id) ? (int) $f->actor_id : null, $f->actor_name) => (int) $f->n,
            ]);
    }

    /** La auditoría guarda el actor como texto: se filtra por id o por nombre. */
    private function constrainAudit(\Illuminate\Database\Eloquent\Builder $query, string $staffKey): void
    {
        if (str_starts_with($staffKey, 'admin-')) {
            $query->where('actor_id', substr($staffKey, 6));

            return;
        }

        if (str_starts_with($staffKey, 'name-')) {
            $query->whereRaw('LOWER(actor_name) = ?', [substr($staffKey, 5)]);

            return;
        }

        $query->whereRaw('1 = 0');
    }

    /** Fecha del negocio de hoy, para el selector de día. */
    public static function today(): CarbonImmutable
    {
        return ReportRange::today();
    }
}
