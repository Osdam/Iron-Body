<?php

namespace App\Http\Controllers\Api\Admin\Reports;

use App\Models\Admin;
use App\Models\Plan;
use App\Models\User;
use App\Services\Reports\MoneyLedger;
use App\Services\Reports\ReportRange;
use App\Services\Reports\StaffIdentity;
use App\Support\Caja\PaymentMethodKind;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/admin/reports/options — lo que va dentro de los desplegables.
 *
 * Existe para que abrir el módulo no cueste un informe. Antes el CRM rellenaba
 * el selector de personas pidiendo el ranking del AÑO y el de planes pidiendo
 * las ventas del año: dos agregaciones completas para pintar dos listas de
 * texto, cada vez que alguien entraba a mirar el resumen de hoy.
 *
 * Aquí todo sale de tablas pequeñas —cuentas del CRM y catálogo de planes— y
 * de constantes. Nada que dependa del periodo.
 */
class OptionsController extends ReportsController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'presets' => ReportRange::presetOptions(),
            'staff' => $this->staff(),
            'plans' => $this->plans(),
            'methods' => array_map(
                fn (PaymentMethodKind $m) => ['value' => $m->value, 'label' => $m->label()],
                PaymentMethodKind::cases(),
            ),
            'sources' => array_map(
                fn (string $s) => ['value' => $s, 'label' => MoneyLedger::sourceLabel($s)],
                MoneyLedger::SOURCES,
            ),
            'channels' => [
                ['value' => 'gym', 'label' => 'Gimnasio (caja)'],
                ['value' => 'app', 'label' => 'App'],
            ],
        ]);
    }

    /**
     * Las cuentas del CRM, activas primero.
     *
     * Se incluyen las inactivas porque sus cobros siguen estando en el
     * histórico: quitarlas del selector haría imposible consultar lo que hizo
     * alguien que ya no trabaja aquí.
     *
     * @return list<array{value: string, label: string, role: string|null, status: string}>
     */
    private function staff(): array
    {
        return Admin::query()
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'status'])
            ->map(fn (Admin $a) => [
                'value' => StaffIdentity::key($a->id, $a->name),
                'label' => $a->name,
                'role' => $a->role,
                'status' => $a->status,
            ])
            ->all();
    }

    /**
     * Planes del catálogo y los que aparezcan en fichas de socios: un plan
     * retirado sigue teniendo ventas que consultar.
     *
     * @return list<array{value: string, label: string}>
     */
    private function plans(): array
    {
        return Plan::query()->pluck('name')
            ->merge(User::query()->whereNotNull('plan')->where('plan', '!=', '')->distinct()->pluck('plan'))
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->map(fn (string $n) => ['value' => $n, 'label' => $n])
            ->all();
    }
}
