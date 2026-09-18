<?php

namespace App\Services\Reports;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * QUIÉN HIZO QUÉ en el CRM, dentro del periodo.
 *
 * Se apoya en `audit_logs`, que ya escribe el propio servidor en la misma
 * transacción que la operación ({@see \App\Services\Audit\FinancialAudit}).
 * Aquí no se fabrica historia: si una acción no dejó traza, no aparece. Un
 * informe que rellenara los huecos «deduciendo» quién debió de hacer algo sería
 * peor que no tener el dato, porque nadie podría distinguir lo comprobado de lo
 * supuesto.
 *
 * El detalle —valores antes y después— solo existe donde la operación lo
 * guardó; se devuelve tal cual, sin inventar el estado anterior.
 */
final class ActivityFeed
{
    /**
     * @param  array<string, mixed>  $filters  staff, module, action, entity, search
     */
    public function __construct(
        private readonly ReportRange $range,
        private readonly array $filters = [],
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function page(int $page = 1, int $perPage = 30): array
    {
        $perPage = max(1, min(100, $perPage));

        $pagina = $this->query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'data' => $pagina->getCollection()->map(fn (AuditLog $l) => [
                'id' => (string) $l->id,
                'at' => $l->created_at?->toIso8601String(),
                'action' => $l->action,
                'action_label' => self::actionLabel($l->action),
                'module' => $l->module,
                'entity' => $l->entity,
                'entity_id' => $l->entity_id,
                'target' => $l->target_name,
                'summary' => $l->summary,
                'actor' => [
                    'key' => StaffIdentity::key(is_numeric($l->actor_id) ? (int) $l->actor_id : null, $l->actor_name),
                    'name' => $l->actor_name,
                    'role' => $l->actor_role,
                ],
                'changes' => is_array($l->changes) ? array_values($l->changes) : [],
                'ip' => $l->ip_address,
            ])->values()->all(),
            'total' => $pagina->total(),
            'page' => $pagina->currentPage(),
            'per_page' => $pagina->perPage(),
            'last_page' => $pagina->lastPage(),
        ];
    }

    /**
     * Las opciones reales de los filtros, sacadas de lo que hay en el periodo:
     * ofrecer un módulo que no tuvo actividad solo produce listas vacías.
     *
     * @return array{modules: list<array{value: string, label: string, count: int}>, actions: list<array{value: string, label: string, count: int}>, actors: list<array{value: string, label: string, count: int}>}
     */
    public function facets(): array
    {
        $porModulo = $this->baseQuery()
            ->groupBy('module')->selectRaw('module, COUNT(*) as n')->get()
            ->map(fn ($f) => ['value' => (string) $f->module, 'label' => (string) $f->module, 'count' => (int) $f->n])
            ->sortByDesc('count')->values()->all();

        $porAccion = $this->baseQuery()
            ->groupBy('action')->selectRaw('action, COUNT(*) as n')->get()
            ->map(fn ($f) => [
                'value' => (string) $f->action,
                'label' => self::actionLabel((string) $f->action),
                'count' => (int) $f->n,
            ])
            ->sortByDesc('count')->values()->all();

        $porActor = $this->baseQuery()
            ->groupBy('actor_id', 'actor_name')->selectRaw('actor_id, actor_name, COUNT(*) as n')->get()
            ->map(fn ($f) => [
                'value' => StaffIdentity::key(is_numeric($f->actor_id) ? (int) $f->actor_id : null, $f->actor_name),
                'label' => $f->actor_name ?: StaffIdentity::UNKNOWN_LABEL,
                'count' => (int) $f->n,
            ])
            ->sortByDesc('count')->values()->all();

        return ['modules' => $porModulo, 'actions' => $porAccion, 'actors' => $porActor];
    }

    private function baseQuery(): Builder
    {
        [$desde, $hasta] = $this->range->utcBounds();

        return AuditLog::query()->whereBetween('created_at', [$desde, $hasta]);
    }

    private function query(): Builder
    {
        $q = $this->baseQuery();

        if ($staff = trim((string) ($this->filters['staff'] ?? ''))) {
            if (str_starts_with($staff, 'admin-')) {
                $q->where('actor_id', substr($staff, 6));
            } elseif (str_starts_with($staff, 'name-')) {
                $q->whereRaw('LOWER(actor_name) = ?', [substr($staff, 5)]);
            } else {
                $q->whereRaw('1 = 0');
            }
        }

        if ($modulo = trim((string) ($this->filters['module'] ?? ''))) {
            $q->where('module', $modulo);
        }

        if ($accion = trim((string) ($this->filters['action'] ?? ''))) {
            $q->where('action', $accion);
        }

        if ($entidad = trim((string) ($this->filters['entity'] ?? ''))) {
            $q->where('entity', $entidad);
        }

        if ($texto = trim((string) ($this->filters['search'] ?? ''))) {
            $op = ReportSql::likeOperator();
            $like = ReportSql::like($texto);
            $q->where(fn (Builder $w) => $w
                ->where('summary', $op, $like)
                ->orWhere('target_name', $op, $like)
                ->orWhere('actor_name', $op, $like)
                ->orWhere('entity', $op, $like)
                ->orWhere('entity_id', $op, $like));
        }

        return $q;
    }

    public static function actionLabel(?string $action): string
    {
        return match ($action) {
            'create' => 'Creó',
            'update' => 'Modificó',
            'delete' => 'Archivó',
            'status' => 'Cambió el estado',
            'assign' => 'Asignó',
            'settings' => 'Ajustes',
            'export' => 'Exportó',
            default => (string) $action,
        };
    }
}
