<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\MembershipAiCapability;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Audit\AuditTrail;
use App\Models\User;
use App\Services\IronAiMembershipAccessService;
use App\Services\RealtimeEvents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    use ResolvesPagination;

    /** Estados de pago que cuentan como cobrados (vocabulario mixto ES/EN). */
    private const PAID_STATUSES = PaymentController::PAID_STATUSES;

    public function __construct(private readonly IronAiMembershipAccessService $aiAccess) {}

    public function index(Request $request)
    {
        return Plan::query()->paginate($this->resolvePerPage($request));
    }

    /**
     * GET /api/admin/plans/stats — métricas agregadas de planes para el CRM.
     *
     * Antes el módulo de Planes descargaba TODOS los miembros y TODOS los pagos
     * (página por página) solo para contar suscriptores e ingresos del mes. Aquí
     * se resuelve con dos consultas agregadas.
     */
    public function crmStats()
    {
        $plans = Plan::query()->orderBy('id')->get(['id', 'name']);

        // Suscriptores activos agrupados por el valor de `users.plan` (el CRM
        // guarda el NOMBRE del plan en esa columna).
        $subscribersByPlanValue = User::query()
            ->select('plan', DB::raw('COUNT(*) as total'))
            ->whereNotNull('plan')
            ->where('plan', '<>', '')
            ->where(function ($q): void {
                $q->whereNull('status')->orWhereIn('status', ['', 'active', 'activo']);
            })
            ->groupBy('plan')
            ->pluck('total', 'plan');

        // Ingresos del mes en curso (pagos cobrados). Los pagos sin `plan_id` se
        // atribuyen al plan del miembro, igual que hacía el cálculo en el cliente.
        $rows = Payment::query()
            ->leftJoin('users', 'users.id', '=', 'payments.user_id')
            ->whereIn(DB::raw('LOWER(payments.status)'), self::PAID_STATUSES)
            ->whereRaw('COALESCE(payments.paid_at, payments.created_at) BETWEEN ? AND ?', [
                now()->startOfMonth(),
                now()->endOfMonth(),
            ])
            ->get(['payments.plan_id', 'payments.amount', 'users.plan as user_plan']);

        // Índice normalizado (nombre e id) → plan, para casar valores sueltos.
        $planByKey = [];
        foreach ($plans as $plan) {
            $planByKey[self::planKey($plan->name)] = $plan->id;
            $planByKey[self::planKey((string) $plan->id)] = $plan->id;
        }

        $subscribers = [];
        foreach ($subscribersByPlanValue as $planValue => $total) {
            $planId = $planByKey[self::planKey((string) $planValue)] ?? null;
            if ($planId === null) {
                continue; // valor huérfano (plan renombrado o eliminado)
            }
            $subscribers[$planId] = ($subscribers[$planId] ?? 0) + (int) $total;
        }

        $income = [];
        $monthlyIncome = 0.0;
        foreach ($rows as $row) {
            $amount = (float) $row->amount;
            $monthlyIncome += $amount;

            $planId = $row->plan_id !== null
                ? (int) $row->plan_id
                : ($planByKey[self::planKey((string) ($row->user_plan ?? ''))] ?? null);

            if ($planId !== null) {
                $income[$planId] = ($income[$planId] ?? 0.0) + $amount;
            }
        }

        return response()->json([
            'plans' => $plans->map(fn (Plan $p) => [
                'plan_id' => $p->id,
                'subscribers' => (int) ($subscribers[$p->id] ?? 0),
                'month_income' => round($income[$p->id] ?? 0.0, 2),
            ])->values(),
            'active_plans' => Plan::where('active', true)->count(),
            'subscribers_total' => array_sum($subscribers),
            'monthly_income' => round($monthlyIncome, 2),
        ]);
    }

    /** Normaliza un nombre de plan (minúsculas sin acentos) para comparar. */
    private static function planKey(string $value): string
    {
        return strtr(mb_strtolower(trim($value)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }

    public function show(Plan $plan)
    {
        return $plan;
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request, false);

        $plan = Plan::create($data);

        app(AuditTrail::class)->record($request, [
            'action' => 'create', 'module' => 'Planes', 'entity' => 'plan',
            'entity_id' => $plan->id, 'target_name' => $plan->name,
            'summary' => "Creó el plan {$plan->name}",
            'metadata' => ['price' => (string) $plan->price, 'duration_days' => $plan->duration_days],
        ]);

        return response()->json($plan, 201);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $this->validatedData($request, true);

        if (array_key_exists('features', $data)) {
            $data['features'] = array_merge(
                $plan->resolvedFeatures(),
                is_array($data['features']) ? $data['features'] : []
            );
        }

        $plan->update($data);

        app(AuditTrail::class)->record($request, [
            'action' => 'update', 'module' => 'Planes', 'entity' => 'plan',
            'entity_id' => $plan->id, 'target_name' => $plan->name,
            'summary' => "Modificó el plan {$plan->name}",
            'changes' => array_map(static fn (string $c) => ['field' => $c], array_keys($request->all())),
            'metadata' => ['price' => (string) $plan->price],
        ]);

        return response()->json($plan);
    }

    public function destroy(Request $request, Plan $plan)
    {
        $nombre = $plan->name;
        $id = $plan->id;
        $plan->delete();

        app(AuditTrail::class)->record($request, [
            'action' => 'delete', 'module' => 'Planes', 'entity' => 'plan',
            'entity_id' => $id, 'target_name' => $nombre,
            'summary' => "Eliminó el plan {$nombre}",
        ]);

        return response()->json(null, 204);
    }

    /** GET /api/plans/features — todos los planes con sus feature flags. */
    public function allFeatures()
    {
        $plans = Plan::orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'plans' => $plans->map(fn (Plan $p) => [
                'planId' => (string) $p->id,
                'planName' => $p->name,
                'features' => $p->resolvedFeatures(),
            ])->values(),
        ]);
    }

    /** PUT /api/plans/{plan}/features — actualiza feature flags de un plan. */
    public function updateFeatures(Request $request, Plan $plan)
    {
        $allowed = array_keys(Plan::defaultFeatures());
        $featureRules = collect($allowed)
            ->mapWithKeys(fn ($k) => ["features.{$k}" => 'sometimes|boolean'])
            ->all();

        $data = $request->validate(array_merge(
            ['features' => 'required|array'],
            $featureRules
        ));

        $plan->features = array_merge($plan->resolvedFeatures(), $data['features']);
        $plan->save();

        // Empuja la señal de cambio por SSE a los miembros activos del plan para
        // que la app reevalúe el gating de módulos al instante (sin reiniciar).
        $this->notifyPlanMembers($plan);

        app(AuditTrail::class)->record($request, [
            'action' => 'update', 'module' => 'Planes', 'entity' => 'plan',
            'entity_id' => $plan->id, 'target_name' => $plan->name,
            'summary' => "Cambió las funciones incluidas en {$plan->name}",
            'changes' => [['field' => 'features']],
        ]);

        return response()->json([
            'planId' => (string) $plan->id,
            'planName' => $plan->name,
            'features' => $plan->resolvedFeatures(),
        ]);
    }

    /**
     * GET /api/plans/{plan}/ai-capabilities
     * Capacidades de IRON IA del plan (fila en membership_ai_capabilities o,
     * si no existe, los valores por defecto del tier inferido). El CRM las edita.
     */
    public function aiCapabilities(Plan $plan)
    {
        $caps = $this->aiAccess->getAiCapabilities([
            'active' => true,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan' => $plan,
            'end_date' => null,
        ]);

        return response()->json([
            'planId' => (string) $plan->id,
            'planName' => $plan->name,
            'capabilities' => $this->capabilitiesToApi($caps),
        ]);
    }

    /**
     * PUT /api/plans/{plan}/ai-capabilities
     * Guarda las capacidades de IRON IA del plan en membership_ai_capabilities
     * (NO crea un sistema de planes paralelo). Flutter las consume vía /access.
     */
    public function updateAiCapabilities(Request $request, Plan $plan)
    {
        $d = $request->validate([
            'ai_enabled' => ['sometimes', 'boolean'],
            'ai_chat_enabled' => ['sometimes', 'boolean'],
            'ai_image_analysis_enabled' => ['sometimes', 'boolean'],
            'ai_voice_chat_enabled' => ['sometimes', 'boolean'],
            'ai_realtime_voice_enabled' => ['sometimes', 'boolean'],
            'ai_progress_analysis_enabled' => ['sometimes', 'boolean'],
            'ai_smart_recommendations_enabled' => ['sometimes', 'boolean'],
            'ai_weekly_summary_enabled' => ['sometimes', 'boolean'],
            'ai_proactive_notifications_enabled' => ['sometimes', 'boolean'],
            'ai_monthly_messages_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'ai_daily_messages_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'ai_monthly_image_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'ai_monthly_audio_limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'ai_max_audio_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'ai_max_image_size_mb' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'ai_context_level' => ['sometimes', 'string', 'in:basic,personalized,full'],
        ]);

        // Mapea el contrato del CRM (ai_*) a las columnas reales.
        $map = [
            'ai_enabled' => 'ai_enabled',
            'ai_chat_enabled' => 'ai_chat_enabled',
            'ai_image_analysis_enabled' => 'ai_image_analysis_enabled',
            'ai_voice_chat_enabled' => 'ai_voice_chat_enabled',
            'ai_realtime_voice_enabled' => 'ai_realtime_voice_enabled',
            'ai_progress_analysis_enabled' => 'progress_analysis_enabled',
            'ai_smart_recommendations_enabled' => 'smart_recommendations_enabled',
            'ai_weekly_summary_enabled' => 'weekly_summary_enabled',
            'ai_proactive_notifications_enabled' => 'proactive_notifications_enabled',
            'ai_monthly_messages_limit' => 'monthly_messages_limit',
            'ai_daily_messages_limit' => 'daily_messages_limit',
            'ai_monthly_image_limit' => 'ai_image_monthly_limit',
            'ai_monthly_audio_limit' => 'ai_audio_monthly_limit',
            'ai_max_audio_seconds' => 'ai_max_audio_seconds',
            'ai_max_image_size_mb' => 'ai_max_image_size_mb',
            'ai_context_level' => 'context_level',
        ];

        $columns = [];
        foreach ($map as $apiKey => $col) {
            if (array_key_exists($apiKey, $d)) {
                $columns[$col] = $d[$apiKey];
            }
        }

        $row = MembershipAiCapability::updateOrCreate(
            ['membership_plan_id' => $plan->id],
            array_merge($columns, [
                'plan_code' => $this->planSlug($plan->name),
                'is_active' => true,
            ]),
        );

        app(AuditTrail::class)->record($request, [
            'action' => 'update', 'module' => 'Planes', 'entity' => 'plan',
            'entity_id' => $plan->id, 'target_name' => $plan->name,
            'summary' => "Cambió las capacidades de IRON IA de {$plan->name}",
            'changes' => array_map(static fn (string $c) => ['field' => $c], array_keys($columns)),
        ]);

        // Notifica a los miembros activos del plan (SSE) para refrescar acceso.
        $this->notifyPlanMembers($plan);

        return response()->json([
            'planId' => (string) $plan->id,
            'planName' => $plan->name,
            'capabilities' => $this->capabilitiesToApi($row->toCapabilities()),
        ]);
    }

    /** Convierte capacidades internas (config/fila) al contrato del CRM (ai_*). */
    private function capabilitiesToApi(array $c): array
    {
        return [
            'ai_enabled' => (bool) ($c['ai_enabled'] ?? true),
            'ai_chat_enabled' => (bool) ($c['ai_chat_enabled'] ?? true),
            'ai_image_analysis_enabled' => (bool) ($c['ai_image_analysis_enabled'] ?? false),
            'ai_voice_chat_enabled' => (bool) ($c['ai_voice_chat_enabled'] ?? false),
            'ai_realtime_voice_enabled' => (bool) ($c['ai_realtime_voice_enabled'] ?? false),
            'ai_progress_analysis_enabled' => (bool) ($c['progress_analysis_enabled'] ?? false),
            'ai_smart_recommendations_enabled' => (bool) ($c['smart_recommendations_enabled'] ?? false),
            'ai_weekly_summary_enabled' => (bool) ($c['weekly_summary_enabled'] ?? false),
            'ai_proactive_notifications_enabled' => (bool) ($c['proactive_notifications_enabled'] ?? false),
            'ai_monthly_messages_limit' => $c['monthly_messages_limit'] ?? null,
            'ai_daily_messages_limit' => $c['daily_messages_limit'] ?? null,
            'ai_monthly_image_limit' => $c['ai_image_monthly_limit'] ?? 0,
            'ai_monthly_audio_limit' => $c['ai_audio_monthly_limit'] ?? 0,
            'ai_max_audio_seconds' => (int) ($c['ai_max_audio_seconds'] ?? 60),
            'ai_max_image_size_mb' => (int) ($c['ai_max_image_size_mb'] ?? 5),
            'ai_context_level' => $c['context_level'] ?? 'basic',
        ];
    }

    private function planSlug(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'u', 'n'], $s);

        return trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_');
    }

    /**
     * Emite una señal real-time (SSE) a cada miembro activo del plan tras cambiar
     * sus features o capacidades de IA. El cliente recibe `app_state.updated`,
     * refresca /member/app-state y reevalúa el gating de módulos sin reiniciar.
     */
    private function notifyPlanMembers(Plan $plan): void
    {
        User::where('plan', $plan->name)
            ->whereHas('appMember')
            ->with('appMember')
            ->get()
            ->each(function (User $user): void {
                RealtimeEvents::features((int) $user->appMember->id);
            });
    }

    private function validatedData(Request $request, bool $updating): array
    {
        $req = $updating ? ['sometimes', 'required'] : ['required'];

        $data = $request->validate([
            'name' => [...$req, 'string', 'max:255'],
            'tier' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', Plan::TIERS)],
            'price' => [...$req, 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'duration_days' => [$updating ? 'sometimes' : 'required_without_all:duration_months,months', 'integer', 'min:1'],
            'duration_months' => ['sometimes', 'integer', 'min:1'],
            'months' => ['sometimes', 'integer', 'min:1'],
            'benefits' => ['nullable'],
            'is_recommended' => ['nullable', 'boolean'],
            'badge' => ['nullable', 'string', 'max:80'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'access_classes' => ['nullable', 'boolean'],
            'reservations_limit' => ['nullable', 'integer', 'min:0'],
            'access_locations' => ['nullable', 'string'],
            'restrictions' => ['nullable', 'string'],
            'active' => [$updating ? 'sometimes' : 'required', 'boolean'],
            'features' => ['sometimes', 'nullable', 'array'],
            'features.*' => ['boolean'],
        ]);

        if (! isset($data['duration_days'])) {
            $months = $data['duration_months'] ?? $data['months'] ?? null;

            if ($months !== null) {
                $data['duration_days'] = (int) $months * 30;
            }
        }

        unset($data['duration_months'], $data['months']);

        // El tier es opcional desde el cliente; por defecto un plan es "lite".
        if (array_key_exists('tier', $data) && empty($data['tier'])) {
            $data['tier'] = 'lite';
        }

        if (array_key_exists('benefits', $data) && is_array($data['benefits'])) {
            $data['benefits'] = json_encode(array_values(array_filter(array_map(
                fn (mixed $benefit): string => trim((string) $benefit),
                $data['benefits']
            ))));
        }

        return $data;
    }
}
