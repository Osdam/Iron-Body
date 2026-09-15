<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CashShiftType;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\ReceivablePayment;
use App\Services\Caja\CashReceipts;
use App\Services\Exports\PaymentsExport;
use App\Support\Caja\PaymentOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/admin/reports/payment-channels — quién pagó por la app y quién en el
 * gimnasio, y quién del equipo cobró cada cosa.
 *
 * DINERO, NO CONTRATOS. Se cuenta lo que entró en el periodo, con la misma
 * regla que el arqueo (CashReceipts): el asiento de un plan a plazos no suma,
 * sus abonos sí, cada uno el día que se cobró y a nombre de quien lo cobró.
 * Así el total del gimnasio de un día cuadra con las cajas de ese día.
 *
 * Solo membresías. Los abonos de cafetería son de la caja de productos y ya
 * tienen su informe; mezclarlos aquí inflaría «pagos en el gimnasio».
 */
class ReportsPaymentChannelsController extends Controller
{
    private const TZ = 'America/Bogota';

    private const MAX_PER_PAGE = 100;

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'channel' => 'nullable|in:all,gym,app,legacy,automation',
            'registered_by' => 'nullable|string|max:120',
            'search' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.self::MAX_PER_PAGE,
        ]);

        $hasta = isset($data['to'])
            ? CarbonImmutable::parse($data['to'], self::TZ)->endOfDay()
            : CarbonImmutable::now(self::TZ)->endOfDay();
        $desde = isset($data['from'])
            ? CarbonImmutable::parse($data['from'], self::TZ)->startOfDay()
            : $hasta->subDays(29)->startOfDay();

        $movimientos = $this->payments($desde, $hasta)->concat($this->installments($desde, $hasta));

        // Totales sobre TODO el periodo; el listado, sobre lo filtrado.
        $filtrado = $this->filter($movimientos, $data);
        $porPagina = (int) ($data['per_page'] ?? 20);
        $pagina = (int) ($data['page'] ?? 1);

        return response()->json([
            'range' => ['from' => $desde->toDateString(), 'to' => $hasta->toDateString()],
            'totals' => [
                'amount' => round($movimientos->sum('amount'), 2),
                'count' => $movimientos->count(),
                'members' => $movimientos->pluck('member_id')->filter()->unique()->count(),
            ],
            'channels' => $this->byChannel($movimientos),
            'by_staff' => $this->byStaff($movimientos),
            'by_role' => $this->byRole($movimientos),
            'rows' => [
                'data' => $filtrado->sortByDesc('date')->forPage($pagina, $porPagina)->values(),
                'total' => $filtrado->count(),
                'amount' => round($filtrado->sum('amount'), 2),
                'page' => $pagina,
                'per_page' => $porPagina,
                'last_page' => max(1, (int) ceil($filtrado->count() / $porPagina)),
            ],
        ]);
    }

    /**
     * Cobros de membresía con dinero dentro del periodo.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function payments(CarbonImmutable $desde, CarbonImmutable $hasta): Collection
    {
        $pagados = PaymentController::PAID_STATUSES;

        return app(CashReceipts::class)->cashPayments()
            ->with(['user:id,name,document', 'plan:id,name', 'splits'])
            ->whereRaw('LOWER(status) IN ('.implode(', ', array_fill(0, count($pagados), '?')).')', $pagados)
            ->whereRaw('COALESCE(paid_at, created_at) BETWEEN ? AND ?', [$desde->utc(), $hasta->utc()])
            ->get()
            ->map(function (Payment $p): array {
                $canal = $p->channel();

                return [
                    'key' => 'payment-'.$p->id,
                    'kind' => 'payment',
                    'kind_label' => 'Cobro',
                    'id' => $p->id,
                    'date' => ($p->paid_at ?? $p->created_at)?->toIso8601String(),
                    'member_id' => $p->user_id,
                    'member_name' => $p->user?->name,
                    'member_document' => $p->user?->document,
                    'plan' => $p->plan?->name,
                    'amount' => (float) $p->amount,
                    'method_label' => $p->isMixed()
                        ? 'Mixto: '.PaymentsExport::breakdown($p)
                        : PaymentsExport::methodLabel($p->method),
                    'channel' => $canal,
                    'channel_label' => PaymentOrigin::channelLabelFor($canal),
                    'registered_by' => $p->registered_by,
                    'registered_by_name' => $p->registered_by_name,
                    'registered_by_role' => $p->registered_by_role,
                    'cash_shift_id' => $p->cash_shift_id,
                    'period_start' => $p->period_start?->toDateString(),
                    'period_end' => $p->period_end?->toDateString(),
                ];
            });
    }

    /**
     * Abonos de planes a plazos cobrados en el periodo. Siempre en el gimnasio:
     * la app no vende a plazos.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function installments(CarbonImmutable $desde, CarbonImmutable $hasta): Collection
    {
        $roles = Admin::query()->pluck('role', 'id');

        return app(CashReceipts::class)->appliedInstallments(CashShiftType::GYM)
            ->with(['receivable.member.user:id,name,document'])
            ->whereBetween('receivable_payments.created_at', [$desde->utc(), $hasta->utc()])
            ->get()
            ->map(function (ReceivablePayment $a) use ($roles): array {
                $member = $a->receivable?->member;
                $user = $member?->user;

                return [
                    'key' => 'installment-'.$a->id,
                    'kind' => 'installment',
                    'kind_label' => 'Abono',
                    'id' => $a->id,
                    'date' => $a->created_at?->toIso8601String(),
                    'member_id' => $user?->id,
                    'member_name' => $user?->name ?? $member?->full_name,
                    'member_document' => $user?->document,
                    'plan' => $a->receivable?->concept,
                    'amount' => (float) $a->amount,
                    'method_label' => PaymentsExport::methodLabel($a->method),
                    'channel' => 'gym',
                    'channel_label' => PaymentOrigin::channelLabelFor('gym'),
                    'registered_by' => $a->created_by,
                    'registered_by_name' => $a->created_by_name,
                    // Los abonos no congelan el rol: se toma el actual de la
                    // cuenta, que es lo mejor que se sabe.
                    'registered_by_role' => $a->created_by ? $roles->get($a->created_by) : null,
                    'cash_shift_id' => $a->cash_shift_id,
                    'period_start' => null,
                    'period_end' => null,
                ];
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $movimientos
     * @param  array<string, mixed>  $data
     * @return Collection<int, array<string, mixed>>
     */
    private function filter(Collection $movimientos, array $data): Collection
    {
        $canal = $data['channel'] ?? 'all';
        if ($canal !== 'all') {
            $movimientos = $movimientos->where('channel', $canal);
        }

        if (filled($data['registered_by'] ?? null)) {
            $clave = (string) $data['registered_by'];
            $movimientos = $movimientos->filter(fn (array $m) => $this->staffKey($m) === $clave);
        }

        if (filled($data['search'] ?? null)) {
            $texto = $this->normalize((string) $data['search']);
            $movimientos = $movimientos->filter(fn (array $m) => str_contains(
                $this->normalize(($m['member_name'] ?? '').' '.($m['member_document'] ?? '').' '.($m['registered_by_name'] ?? '')),
                $texto,
            ));
        }

        return $movimientos;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $movimientos
     * @return list<array<string, mixed>>
     */
    private function byChannel(Collection $movimientos): array
    {
        $total = (float) $movimientos->sum('amount');
        $orden = ['gym', 'app', 'legacy', 'automation', 'unknown'];

        return $movimientos->groupBy('channel')
            ->map(fn (Collection $grupo, string $canal) => [
                'key' => $canal,
                'label' => PaymentOrigin::channelLabelFor($canal),
                'amount' => round((float) $grupo->sum('amount'), 2),
                'count' => $grupo->count(),
                'members' => $grupo->pluck('member_id')->filter()->unique()->count(),
                'share' => $total > 0 ? round($grupo->sum('amount') / $total * 100, 1) : 0,
            ])
            // Gimnasio y app siempre aparecen, aunque sea en cero: una tarjeta
            // que desaparece se lee como un fallo, no como «nadie pagó así».
            ->union(collect(['gym', 'app'])->mapWithKeys(fn ($c) => [$c => [
                'key' => $c, 'label' => PaymentOrigin::channelLabelFor($c),
                'amount' => 0, 'count' => 0, 'members' => 0, 'share' => 0,
            ]]))
            ->sortBy(fn ($fila, $canal) => array_search($canal, $orden, true))
            ->values()
            ->all();
    }

    /**
     * Lo que cobró cada persona del equipo, solo en el gimnasio: la app no la
     * cobra nadie.
     *
     * @param  Collection<int, array<string, mixed>>  $movimientos
     * @return list<array<string, mixed>>
     */
    private function byStaff(Collection $movimientos): array
    {
        return $movimientos->where('channel', 'gym')
            ->groupBy(fn (array $m) => $this->staffKey($m))
            ->map(function (Collection $grupo, string $clave): array {
                $primero = $grupo->first();

                return [
                    'key' => $clave,
                    'admin_id' => $primero['registered_by'],
                    'name' => $primero['registered_by_name'] ?? 'Sin registro de quién cobró',
                    'role' => $primero['registered_by_role'],
                    'amount' => round((float) $grupo->sum('amount'), 2),
                    'count' => $grupo->count(),
                    'installments' => $grupo->where('kind', 'installment')->count(),
                    'members' => $grupo->pluck('member_id')->filter()->unique()->count(),
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $movimientos
     * @return list<array<string, mixed>>
     */
    private function byRole(Collection $movimientos): array
    {
        return $movimientos->where('channel', 'gym')
            ->groupBy(fn (array $m) => $m['registered_by_role'] ?: 'Sin rol registrado')
            ->map(fn (Collection $grupo, string $rol) => [
                'role' => $rol,
                'amount' => round((float) $grupo->sum('amount'), 2),
                'count' => $grupo->count(),
                'people' => $grupo->map(fn (array $m) => $this->staffKey($m))->unique()->count(),
            ])
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * Identidad de quien cobró. Por id cuando existe; por nombre si la cuenta
     * se borró; y un grupo aparte para los cobros de antes de registrarlo.
     */
    private function staffKey(array $m): string
    {
        return match (true) {
            ! empty($m['registered_by']) => 'admin-'.$m['registered_by'],
            ! empty($m['registered_by_name']) => 'name-'.mb_strtolower((string) $m['registered_by_name']),
            default => 'none',
        };
    }

    private function normalize(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
