<?php

namespace App\Services\Admin\IronCrm;

use App\Models\ClassReservation;
use App\Models\ElectronicInvoice;
use App\Models\MarketingCampaign;
use App\Models\MarketingLead;
use App\Models\MemberContract;
use App\Models\MyClass;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Trainer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Herramientas internas de SOLO LECTURA que IRON (copiloto del CRM) puede
 * invocar vía "function calling". Cada herramienta es una consulta FIJA y
 * acotada: el modelo elige qué herramienta llamar y con qué argumentos, pero
 * NUNCA escribe SQL ni ejecuta mutaciones. Así se evita el SQL arbitrario y se
 * garantiza que solo se lee lo que el CRM ya expone.
 *
 * Diseño defensivo: si un módulo/tabla no existe en esta instancia, la
 * herramienta responde `{ available: false, ... }` en vez de fallar. IRON debe
 * comunicar honestamente cuando un módulo no está disponible.
 *
 * Todas las listas se recortan a `iron_crm.max_rows_per_tool` para no volcar la
 * base de datos al prompt.
 */
class IronCrmToolService
{
    private int $maxRows;

    public function __construct()
    {
        $this->maxRows = max(1, (int) config('iron_crm.max_rows_per_tool', 25));
    }

    /**
     * Definiciones de herramientas para la API de OpenAI (tools/function calling).
     *
     * @return array<int, array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        $defs = [
            ['search_members', 'Busca miembros del CRM por nombre, documento, teléfono o correo. Devuelve coincidencias con su estado y plan.', [
                'query' => ['type' => 'string', 'description' => 'Texto a buscar: nombre, documento, teléfono o email.'],
            ], ['query']],
            ['member_overview', 'Ficha administrativa de un miembro: estado, plan, vigencia de membresía, últimos pagos y contratos. Acepta el id o un texto de búsqueda.', [
                'user_id' => ['type' => 'integer', 'description' => 'ID del miembro (si se conoce).'],
                'query' => ['type' => 'string', 'description' => 'Alternativa: nombre, documento, teléfono o email.'],
            ], []],
            ['list_payments', 'Lista pagos recientes del CRM. Filtra por estado (paid|pending|failed) y texto.', [
                'status' => ['type' => 'string', 'enum' => ['paid', 'pending', 'failed', 'all'], 'description' => 'Grupo de estado.'],
                'search' => ['type' => 'string', 'description' => 'Referencia o nombre del miembro.'],
            ], []],
            ['membership_metrics', 'Métricas de membresías: activos, vencidos, por vencer esta semana y nuevos del mes.', [], []],
            ['list_plans', 'Lista los planes/membresías con precio y duración.', [
                'active_only' => ['type' => 'boolean', 'description' => 'Solo planes activos (por defecto true).'],
            ], []],
            ['list_trainers', 'Lista entrenadores. Filtra por estado (active|inactive|all).', [
                'status' => ['type' => 'string', 'description' => 'Estado del entrenador.'],
            ], []],
            ['list_classes', 'Lista clases del catálogo. Filtra por día de la semana (0=domingo..6=sábado) y estado.', [
                'day_of_week' => ['type' => 'integer', 'description' => '0..6 (opcional).'],
                'status' => ['type' => 'string', 'description' => 'active|inactive|all (opcional).'],
            ], []],
            ['reservations_for_date', 'Reservas de clases para una fecha (por defecto hoy).', [
                'date' => ['type' => 'string', 'description' => 'Fecha YYYY-MM-DD (opcional; por defecto hoy).'],
            ], []],
            ['list_leads', 'Prospectos/leads de marketing. Filtra por estado.', [
                'status' => ['type' => 'string', 'description' => 'new|interested|hot|warm|cold|converted|all (opcional).'],
            ], []],
            ['list_campaigns', 'Campañas de marketing con su estado y métricas básicas.', [
                'status' => ['type' => 'string', 'description' => 'active|paused|all (opcional).'],
            ], []],
            ['list_contracts', 'Contratos de miembros. Filtra por estado (draft|pending_signature|signed|void).', [
                'status' => ['type' => 'string', 'description' => 'Estado del contrato (opcional).'],
            ], []],
            ['list_invoices', 'Facturas electrónicas emitidas. Filtra por estado.', [
                'status' => ['type' => 'string', 'description' => 'Estado de la factura (opcional).'],
            ], []],
            ['dashboard_summary', 'Resumen administrativo global del gimnasio: miembros, ingresos, pagos, clases y alertas.', [], []],
            ['available_modules', 'Lista los módulos del CRM detectados en esta instancia y si están disponibles.', [], []],
        ];

        return array_map(function (array $d): array {
            [$name, $description, $props, $required] = $d;

            return [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $description,
                    'parameters' => [
                        'type' => 'object',
                        'properties' => (object) $props,
                        'required' => $required,
                        'additionalProperties' => false,
                    ],
                ],
            ];
        }, $defs);
    }

    /**
     * Ejecuta una herramienta por nombre. Nunca lanza: cualquier error se
     * devuelve como `{ error: ... }` para que el modelo lo comunique.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function run(string $name, array $args): array
    {
        try {
            return match ($name) {
                'search_members' => $this->searchMembers((string) ($args['query'] ?? '')),
                'member_overview' => $this->memberOverview($args),
                'list_payments' => $this->listPayments($args),
                'membership_metrics' => $this->membershipMetrics(),
                'list_plans' => $this->listPlans($args),
                'list_trainers' => $this->listTrainers($args),
                'list_classes' => $this->listClasses($args),
                'reservations_for_date' => $this->reservationsForDate($args),
                'list_leads' => $this->listLeads($args),
                'list_campaigns' => $this->listCampaigns($args),
                'list_contracts' => $this->listContracts($args),
                'list_invoices' => $this->listInvoices($args),
                'dashboard_summary' => $this->dashboardSummary(),
                'available_modules' => $this->availableModules(),
                default => ['error' => "Herramienta desconocida: {$name}"],
            };
        } catch (Throwable $e) {
            return ['error' => 'No se pudo completar la consulta interna.', 'tool' => $name];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Herramientas
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function searchMembers(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['count' => 0, 'members' => [], 'note' => 'Consulta vacía.'];
        }

        $like = '%'.$query.'%';
        $members = User::query()
            ->where(fn ($q) => $q->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('document', 'like', $like)
                ->orWhere('phone', 'like', $like))
            ->orderBy('name')
            ->limit($this->maxRows)
            ->get();

        return [
            'count' => $members->count(),
            'members' => $members->map(fn (User $u) => $this->memberBrief($u))->all(),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function memberOverview(array $args): array
    {
        $user = null;
        if (! empty($args['user_id'])) {
            $user = User::find((int) $args['user_id']);
        }
        if (! $user && ! empty($args['query'])) {
            $found = $this->searchMembers((string) $args['query']);
            if (($found['count'] ?? 0) === 1) {
                $user = User::find((int) $found['members'][0]['id']);
            } elseif (($found['count'] ?? 0) > 1) {
                return ['ambiguous' => true, 'matches' => $found['members'], 'note' => 'Hay varias coincidencias; pide precisar.'];
            }
        }

        if (! $user) {
            return ['found' => false, 'note' => 'No se encontró el miembro.'];
        }

        $payments = Payment::where('user_id', $user->id)
            ->orderByRaw('COALESCE(paid_at, created_at) DESC')
            ->limit(10)
            ->get()
            ->map(fn (Payment $p) => $this->paymentBrief($p))
            ->all();

        $contracts = [];
        if ($this->tableExists('member_contracts') && Schema::hasColumn('member_contracts', 'user_id')) {
            $contracts = MemberContract::where('user_id', $user->id)
                ->orderByDesc('created_at')->limit(5)->get()
                ->map(fn (MemberContract $c) => [
                    'folio' => $c->folio,
                    'type' => $c->contract_type,
                    'status' => $c->status,
                    'signed_at' => optional($c->signed_at)->toDateString(),
                ])->all();
        }

        return [
            'found' => true,
            'member' => $this->memberBrief($user),
            'payments' => $payments,
            'contracts' => $contracts,
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listPayments(array $args): array
    {
        $status = strtolower((string) ($args['status'] ?? 'all'));
        $search = trim((string) ($args['search'] ?? ''));

        $q = Payment::query()->with(['user:id,name'])->orderByRaw('COALESCE(paid_at, created_at) DESC');

        $map = [
            'paid' => Payment::PAID_STATUSES ?? ['paid'],
            'pending' => Payment::PENDING_STATUSES ?? ['pending'],
            'failed' => Payment::FAILED_STATUSES ?? ['failed'],
        ];
        if (isset($map[$status])) {
            $q->whereIn(DB::raw('LOWER(status)'), array_map('strtolower', $map[$status]));
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(fn ($sub) => $sub->where('reference', 'like', $like)
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)));
        }

        $rows = $q->limit($this->maxRows)->get();

        return [
            'count' => $rows->count(),
            'filter' => ['status' => $status, 'search' => $search],
            'payments' => $rows->map(fn (Payment $p) => $this->paymentBrief($p))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function membershipMetrics(): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $weekEnd = $today->addDays(7)->endOfDay();
        $monthStart = $today->startOfMonth();

        $hasEnd = Schema::hasColumn('users', 'membership_end_date');

        $expired = 0;
        $expiringThisWeek = 0;
        if ($hasEnd) {
            $expired = User::whereNotNull('membership_end_date')
                ->whereDate('membership_end_date', '<', $today->toDateString())->count();
            $expiringThisWeek = User::whereNotNull('membership_end_date')
                ->whereDate('membership_end_date', '>=', $today->toDateString())
                ->whereDate('membership_end_date', '<=', $weekEnd->toDateString())->count();
        }

        $active = User::whereRaw("LOWER(COALESCE(NULLIF(status,''),'active')) = 'active'")->count();

        return [
            'total_members' => User::count(),
            'active_members' => $active,
            'expired_members' => $expired,
            'expiring_this_week' => $expiringThisWeek,
            'new_members_month' => User::where('created_at', '>=', $monthStart)->count(),
            'as_of' => $today->toDateString(),
            'note' => $hasEnd ? null : 'La columna membership_end_date no existe; vencimientos no disponibles.',
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listPlans(array $args): array
    {
        $activeOnly = array_key_exists('active_only', $args) ? (bool) $args['active_only'] : true;
        $q = Plan::query()->orderBy('sort_order')->orderBy('name');
        if ($activeOnly) {
            $q->where('active', true);
        }
        $rows = $q->limit($this->maxRows)->get();

        return [
            'count' => $rows->count(),
            'plans' => $rows->map(fn (Plan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'tier' => $p->tier,
                'price' => (float) $p->price,
                'duration_days' => $p->duration_days,
                'active' => (bool) $p->active,
                'benefits' => $p->benefits,
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listTrainers(array $args): array
    {
        $status = strtolower((string) ($args['status'] ?? 'all'));
        $q = Trainer::query()->orderBy('full_name');
        if (in_array($status, ['active', 'inactive'], true)) {
            $q->whereRaw('LOWER(status) = ?', [$status]);
        }
        $rows = $q->limit($this->maxRows)->get();

        return [
            'count' => $rows->count(),
            'trainers' => $rows->map(fn (Trainer $t) => [
                'id' => $t->id,
                'name' => $t->full_name,
                'main_specialty' => $t->main_specialty,
                'status' => $t->status,
                'assigned_classes' => $t->assigned_classes,
                'assigned_members' => $t->assigned_members,
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listClasses(array $args): array
    {
        $q = MyClass::query()->with(['trainer:id,full_name'])->orderBy('day_of_week')->orderBy('start_time');
        if (isset($args['day_of_week']) && $args['day_of_week'] !== '') {
            $q->where('day_of_week', (int) $args['day_of_week']);
        }
        $status = strtolower((string) ($args['status'] ?? 'all'));
        if (in_array($status, ['active', 'inactive'], true)) {
            $q->whereRaw('LOWER(status) = ?', [$status]);
        }
        $rows = $q->limit($this->maxRows)->get();

        return [
            'count' => $rows->count(),
            'classes' => $rows->map(fn (MyClass $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'trainer' => $c->trainer?->full_name ?? $c->instructor,
                'day_of_week' => $c->day_of_week,
                'start_time' => $c->start_time,
                'end_time' => $c->end_time,
                'max_capacity' => $c->max_capacity,
                'enrolled' => $c->enrolled_count,
                'location' => $c->location,
                'status' => $c->status,
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function reservationsForDate(array $args): array
    {
        if (! $this->tableExists('class_reservations')) {
            return ['available' => false, 'note' => 'Módulo de reservas no detectado.'];
        }
        $date = ! empty($args['date'])
            ? CarbonImmutable::parse((string) $args['date'])->toDateString()
            : CarbonImmutable::now()->toDateString();

        $rows = ClassReservation::query()
            ->with(['member:id,full_name'])
            ->whereDate('session_date', $date)
            ->orderBy('class_id')
            ->limit($this->maxRows)
            ->get();

        return [
            'date' => $date,
            'count' => $rows->count(),
            'reservations' => $rows->map(fn ($r) => [
                'class_id' => $r->class_id,
                'member' => $r->member?->full_name,
                'reserved_at' => optional($r->reserved_at)->toIso8601String(),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listLeads(array $args): array
    {
        if (! $this->tableExists('marketing_leads')) {
            return ['available' => false, 'note' => 'Módulo de marketing/leads no detectado.'];
        }
        $status = strtolower((string) ($args['status'] ?? 'all'));
        $q = MarketingLead::query()->orderByDesc('last_message_at')->orderByDesc('created_at');
        if ($status !== 'all' && $status !== '') {
            $q->whereRaw('LOWER(status) = ?', [$status]);
        }
        $rows = $q->limit($this->maxRows)->get();

        return [
            'count' => $rows->count(),
            'leads' => $rows->map(fn (MarketingLead $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'phone' => $l->phone,
                'channel' => $l->channel,
                'status' => $l->status,
                'temperature' => $l->temperature,
                'created_at' => optional($l->created_at)->toDateString(),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listCampaigns(array $args): array
    {
        if (! $this->tableExists('marketing_campaigns')) {
            return ['available' => false, 'note' => 'Módulo de campañas no detectado.'];
        }
        $status = strtolower((string) ($args['status'] ?? 'all'));
        $q = MarketingCampaign::query()->orderByDesc('date_start');
        if ($status !== 'all' && $status !== '') {
            $q->whereRaw('LOWER(status) = ?', [$status]);
        }
        $rows = $q->limit($this->maxRows)->get();

        return [
            'count' => $rows->count(),
            'campaigns' => $rows->map(fn (MarketingCampaign $c) => [
                'name' => $c->name,
                'status' => $c->status,
                'objective' => $c->objective,
                'spend' => (float) $c->spend,
                'leads' => $c->leads,
                'impressions' => $c->impressions,
                'clicks' => $c->clicks,
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listContracts(array $args): array
    {
        if (! $this->tableExists('member_contracts')) {
            return ['available' => false, 'note' => 'Módulo de contratos no detectado.'];
        }
        $status = strtolower((string) ($args['status'] ?? 'all'));
        $q = MemberContract::query()->with(['member:id,full_name'])->orderByDesc('created_at');
        if ($status !== 'all' && $status !== '') {
            $q->whereRaw('LOWER(status) = ?', [$status]);
        }
        $rows = $q->limit($this->maxRows)->get();

        return [
            'count' => $rows->count(),
            'contracts' => $rows->map(fn (MemberContract $c) => [
                'folio' => $c->folio,
                'member' => $c->member?->full_name,
                'type' => $c->contract_type,
                'status' => $c->status,
                'signed_at' => optional($c->signed_at)->toDateString(),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $args @return array<string, mixed> */
    private function listInvoices(array $args): array
    {
        if (! $this->tableExists('electronic_invoices')) {
            return ['available' => false, 'note' => 'Módulo de facturación electrónica no detectado.'];
        }
        $status = strtolower((string) ($args['status'] ?? 'all'));
        $q = ElectronicInvoice::query()->orderByDesc('created_at');
        if ($status !== 'all' && $status !== '') {
            $q->whereRaw('LOWER(status) = ?', [$status]);
        }
        $rows = $q->limit($this->maxRows)->get();

        return [
            'count' => $rows->count(),
            'invoices' => $rows->map(fn (ElectronicInvoice $i) => [
                'full_number' => $i->full_number,
                'status' => $i->status,
                'dian_status' => $i->dian_status,
                'customer_name' => $i->customer_name,
                'total' => (float) $i->total,
                'issued_at' => optional($i->issued_at)->toDateString(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function dashboardSummary(): array
    {
        $metrics = $this->membershipMetrics();
        $paid = Payment::PAID_STATUSES ?? ['paid'];
        $pending = Payment::PENDING_STATUSES ?? ['pending'];
        $monthStart = CarbonImmutable::now()->startOfMonth();

        $revenueMonth = (float) Payment::whereIn(DB::raw('LOWER(status)'), array_map('strtolower', $paid))
            ->where(fn ($q) => $q->where('paid_at', '>=', $monthStart)->orWhere('created_at', '>=', $monthStart))
            ->sum('amount');
        $pendingCount = Payment::whereIn(DB::raw('LOWER(status)'), array_map('strtolower', $pending))->count();

        return [
            'members' => [
                'active' => $metrics['active_members'],
                'expired' => $metrics['expired_members'],
                'expiring_this_week' => $metrics['expiring_this_week'],
                'new_this_month' => $metrics['new_members_month'],
            ],
            'revenue_month' => round($revenueMonth, 2),
            'pending_payments' => $pendingCount,
            'active_plans' => Plan::where('active', true)->count(),
            'active_classes' => $this->tableExists('classes') ? MyClass::whereRaw("LOWER(status) = 'active'")->count() : null,
            'as_of' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function availableModules(): array
    {
        $modules = [
            'miembros' => 'users',
            'planes' => 'plans',
            'pagos' => 'payments',
            'membresias' => 'membership_subscriptions',
            'entrenadores' => 'trainers',
            'clases' => 'classes',
            'reservas' => 'class_reservations',
            'rutinas' => 'routines',
            'contratos' => 'member_contracts',
            'facturacion_electronica' => 'electronic_invoices',
            'marketing_leads' => 'marketing_leads',
            'marketing_campanas' => 'marketing_campaigns',
            'evaluaciones_fisicas' => 'physical_evaluations',
            'nutricion' => 'nutrition_goals',
            'auditoria' => 'audit_logs',
        ];

        $result = [];
        foreach ($modules as $label => $table) {
            $result[$label] = $this->tableExists($table);
        }

        return ['modules' => $result];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function memberBrief(User $u): array
    {
        $end = $u->membership_end_date ? CarbonImmutable::parse($u->membership_end_date) : null;
        $status = $u->status ?: 'active';
        $isExpired = $end !== null && $end->lt(CarbonImmutable::now()->startOfDay());

        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'document' => $u->document,
            'phone' => $u->phone,
            'status' => $status,
            'plan' => $u->plan,
            'membership_start_date' => optional($u->membership_start_date)->toDateString(),
            'membership_end_date' => $end?->toDateString(),
            'membership_active' => ! $isExpired && in_array(strtolower($status), ['active', ''], true),
            'membership_expired' => $isExpired,
        ];
    }

    /** @return array<string, mixed> */
    private function paymentBrief(Payment $p): array
    {
        return [
            'id' => $p->id,
            'member' => $p->relationLoaded('user') ? $p->user?->name : null,
            'amount' => (float) $p->amount,
            'method' => $p->method,
            'reference' => $p->reference,
            'status' => $p->status,
            'paid_at' => optional($p->paid_at)->toIso8601String(),
        ];
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
