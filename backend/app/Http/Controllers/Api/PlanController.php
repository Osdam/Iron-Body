<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PlanArchiveException;
use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\MembershipAiCapability;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Audit\AuditTrail;
use App\Services\Admin\AdminSessionService;
use App\Services\Membership\PlanAccessRules;
use App\Services\Membership\PlanArchive;
use App\Models\User;
use App\Services\IronAiMembershipAccessService;
use App\Services\RealtimeEvents;
use App\Support\Access\CrmPermission;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanController extends Controller
{
    use ResolvesPagination;

    /** Estados de pago que cuentan como cobrados (vocabulario mixto ES/EN). */
    private const PAID_STATUSES = PaymentController::PAID_STATUSES;

    public function __construct(private readonly IronAiMembershipAccessService $aiAccess) {}

    /**
     * GET /api/plans — PÚBLICA (la app la lee sin sesión).
     *
     * `archived=include` (por defecto) es lo de siempre: todos los planes,
     * archivados incluidos, como los ha listado siempre. `exclude` deja fuera
     * los archivados (los selectores para vender) y `only` deja solo esos.
     */
    public function index(Request $request)
    {
        $data = $request->validate(['archived' => ['sometimes', 'string', 'in:exclude,only,include']]);
        $admin = $this->adminViewer($request);

        // `access` va junto a las columnas: son las reglas ya normalizadas, que
        // es lo que el CRM pinta. Sin esto cada pantalla tendría que volver a
        // interpretar el JSON de días y franjas, y acabarían discrepando.
        return Plan::query()
            ->when(($data['archived'] ?? 'include') === 'exclude', fn ($q) => $q->notArchived())
            ->when(($data['archived'] ?? 'include') === 'only', fn ($q) => $q->archived())
            ->when($admin, fn ($q) => $q->with('archiver:id,name'))
            ->paginate($this->resolvePerPage($request))
            ->through(fn (Plan $plan): array => $this->payload($plan, $admin));
    }

    /**
     * Un plan como lo lee el CRM: sus columnas más las reglas de acceso ya
     * interpretadas y el archivo.
     *
     * Quién lo archivó y por qué solo van con sesión de administrador: el
     * listado es público. Sin ella las dos claves llegan, en null.
     *
     * @return array<string, mixed>
     */
    private function payload(Plan $plan, bool $admin = true): array
    {
        if ($admin && $plan->archived_by !== null && ! $plan->relationLoaded('archiver')) {
            $plan->load('archiver:id,name');
        }

        return array_merge($plan->toArray(), [
            'access' => $plan->accessRules()->toArray(),
            'is_archived' => $plan->isArchived(),
            'archived_by_name' => $admin ? $plan->archiver?->name : null,
            'archive_reason' => $admin ? $plan->archive_reason : null,
        ]);
    }

    /**
     * ¿Lo pide una persona del CRM que puede ver planes? En las rutas con
     * `auth.admin` ya está resuelta; en las públicas se mira la credencial sin
     * exigirla (y sin tocar la sesión). El token compartido no es nadie.
     */
    private function adminViewer(Request $request): bool
    {
        $admin = $request->attributes->get('auth_admin');
        if (! $admin instanceof Admin && is_string($token = $request->bearerToken()) && $token !== '') {
            $admin = app(AdminSessionService::class)->resolveByToken($token)?->admin;
        }

        return $admin instanceof Admin && $admin->isActive() && CrmPermission::allows($admin, 'plans.view');
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

    public function show(Request $request, Plan $plan)
    {
        return response()->json($this->payload($plan, $this->adminViewer($request)));
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request, false);

        $plan = DB::transaction(function () use ($request, $data): Plan {
            $plan = Plan::create($data);

            app(AuditTrail::class)->record($request, [
                'action' => 'create', 'module' => 'Planes', 'entity' => 'plan',
                'entity_id' => $plan->id, 'target_name' => $plan->name,
                'summary' => "Creó el plan {$plan->name}",
                'metadata' => $this->eventMetadata($plan, 'plan.created') + [
                    'price' => (string) $plan->price, 'duration_days' => $plan->duration_days,
                ],
            ]);

            return $plan;
        });

        return response()->json($this->payload($plan), 201);
    }

    public function update(Request $request, Plan $plan)
    {
        $data = $this->validatedData($request, true, $plan);

        // Un plan archivado no vuelve a la venta ni a la app por la edición:
        // eso es «Reactivar», que comprueba que se pueda vender y lo audita.
        if ($plan->isArchived() && (($data['active'] ?? false) || ($data['visible_in_app'] ?? false))) {
            throw PlanArchiveException::archivedEdit();
        }

        if (array_key_exists('features', $data)) {
            $data['features'] = array_merge(
                $plan->resolvedFeatures(),
                is_array($data['features']) ? $data['features'] : []
            );
        }

        DB::transaction(function () use ($request, $plan, $data): void {
            $previoPlan = $plan->getOriginal();
            $plan->update($data);

            app(AuditTrail::class)->record($request, [
                'action' => 'update', 'module' => 'Planes', 'entity' => 'plan',
                'entity_id' => $plan->id, 'target_name' => $plan->name,
                'summary' => "Modificó el plan {$plan->name}",
                'changes' => app(AuditTrail::class)->changesOf(
                    $plan,
                    $previoPlan,
                    // Un cambio de precio o de vigencia sin el antes y el después
                    // obliga a reconstruirlo a mano. No son datos personales.
                    ['price', 'duration_days', 'active', 'tier', 'access_mode', 'entry_credits', 'visible_in_app'],
                ),
                'metadata' => $this->eventMetadata($plan, 'plan.updated') + ['price' => (string) $plan->price],
            ]);

            if (array_key_exists('features', $data)) {
                $this->notifyPlanMembers($plan);
            }
        });

        return response()->json($this->payload($plan));
    }

    /**
     * Borra un plan SOLO si nada lo referencia. Si algo lo hace, 409
     * `plan_has_history` con lo que lo usa y la alternativa: archivarlo
     * ({@see archive()}).
     *
     * Antes borraba sin mirar, y la llave de `payments.plan_id` era ON DELETE
     * SET NULL: el 16-10-2026 se borró «TOTAL ACCESS - ELITE» y sus 31 cobros
     * se quedaron «sin plan», con la transferencia de membresía rota encima.
     *
     * Bajo cerrojo de la fila: un cobro que se registre a la vez con este plan
     * espera (su llave foránea pide FOR KEY SHARE sobre ella) y llega cuando el
     * plan ya no está, o llega antes y se cuenta aquí. En PostgreSQL la llave es
     * además RESTRICT; si algo se cuela, su 23503 también responde 409.
     */
    public function destroy(Request $request, Plan $plan)
    {
        try {
            $dependencias = DB::transaction(function () use ($request, $plan): ?array {
                $bloqueado = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
                if ($bloqueado === null) {
                    return null;
                }

                $dependencias = $this->dependenciesOf($bloqueado);
                if (array_sum($dependencias) > 0) {
                    return $dependencias;
                }

                $nombre = $bloqueado->name;
                $id = $bloqueado->id;
                $bloqueado->delete();

                app(AuditTrail::class)->record($request, [
                    'action' => 'delete', 'module' => 'Planes', 'entity' => 'plan',
                    'entity_id' => $id, 'target_name' => $nombre,
                    'summary' => "Eliminó el plan {$nombre}",
                    'metadata' => $this->eventMetadata($bloqueado, 'plan.deleted'),
                ]);

                return [];
            });
        } catch (QueryException $e) {
            // 23503: una fila que lo referencia con llave RESTRICT (PostgreSQL).
            if ((string) ($e->errorInfo[0] ?? '') !== '23503') {
                throw $e;
            }
            $dependencias = $this->dependenciesOf($plan) + ['other' => 1];
        }

        if ($dependencias === null) {
            return response()->json(['ok' => false, 'message' => 'El plan ya no existe.'], 404);
        }
        if ($dependencias !== []) {
            return response()->json([
                'ok' => false,
                'code' => 'plan_has_history',
                'message' => 'No se puede eliminar «'.$plan->name.'»: '.$this->describeDependencies($dependencias).
                    '. Borrarlo los dejaría sin plan. Archívalo en su lugar: deja de venderse y de verse en la app, '.
                    'y su historial se conserva.',
                'can_archive' => true,
                'is_archived' => $plan->isArchived(),
                'dependencies' => $dependencias,
                // La alternativa: POST con `{reason}` (5–500).
                'archive' => ['method' => 'POST', 'path' => '/api/plans/'.$plan->id.'/archive'],
            ], 409);
        }

        return response()->json(null, 204);
    }

    /**
     * Lo que referencia al plan y se quedaría huérfano si se borrara, con
     * todas las claves (cero incluido). Con DB::table: el scope del entrenador
     * sobre User no puede esconder socios aquí, o el plan se borraría con gente
     * dentro.
     *
     * @return array{payments: int, members: int, plan_transfers: int, membership_transfers: int, gateway_transactions: int, subscriptions: int}
     */
    private function dependenciesOf(Plan $plan): array
    {
        $id = (int) $plan->id;

        return [
            // Cualquier estado: un cobro anulado también es historia.
            'payments' => DB::table('payments')
                ->where(fn ($q) => $q->where('plan_id', $id)->orWhere('upgraded_from_plan_id', $id))
                ->count(),
            // `users.plan` guarda el NOMBRE; se compara como lo lee la puerta.
            'members' => PlanArchive::membersNamed((string) $plan->name),
            'plan_transfers' => DB::table('plan_transfer_batches')
                ->where(fn ($q) => $q->where('from_plan_id', $id)->orWhere('to_plan_id', $id))
                ->count(),
            'membership_transfers' => DB::table('membership_transfers')
                ->where(fn ($q) => $q->where('plan_id', $id)->orWhere('source_plan_id', $id))
                ->count(),
            'gateway_transactions' => DB::table('payment_transactions')->where('plan_id', $id)->count(),
            'subscriptions' => DB::table('membership_subscriptions')->where('plan_id', $id)->count(),
        ];
    }

    /**
     * POST /api/plans/{plan}/archive — `plans.manage`. Cuerpo `{reason}`.
     *
     * Lo saca de la venta y de la app sin tocar a nadie que lo tenga
     * ({@see PlanArchive}). Idempotente: si ya estaba archivado, 200 con
     * `already: true` y nada escrito.
     */
    public function archive(Request $request, Plan $plan, PlanArchive $archive)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $r = $archive->archive($request, $plan, trim($data['reason']));

        return response()->json([
            'ok' => true,
            'already' => $r['already'],
            'plan' => $this->payload($r['plan']->refresh()),
            ...$archive->effects($r['plan']),
        ]);
    }

    /**
     * POST /api/plans/{plan}/reactivate — `plans.manage`. Cuerpo
     * `{reason, visible_in_app?}` (por defecto visible).
     *
     * Solo si se puede volver a vender (422 `plan_not_reactivable` con los
     * motivos). No toca membresías, vencidas ni vigentes. Si ya estaba activo,
     * vendible y sin archivar, 200 con `already: true`.
     */
    public function reactivate(Request $request, Plan $plan, PlanArchive $archive)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'visible_in_app' => ['sometimes', 'boolean'],
        ]);

        $r = $archive->reactivate($request, $plan, trim($data['reason']), array_key_exists('visible_in_app', $data)
            ? (bool) $data['visible_in_app'] : null);

        return response()->json([
            'ok' => true,
            'already' => $r['already'],
            'plan' => $this->payload($r['plan']->refresh()),
        ]);
    }

    /** @param  array<string, int>  $dependencias */
    private function describeDependencies(array $dependencias): string
    {
        $nombres = [
            'payments' => ['cobro', 'cobros'],
            'members' => ['socio con este plan', 'socios con este plan'],
            'plan_transfers' => ['traslado de plan', 'traslados de plan'],
            'membership_transfers' => ['traspaso de membresía', 'traspasos de membresía'],
            'gateway_transactions' => ['transacción de pasarela', 'transacciones de pasarela'],
            'subscriptions' => ['suscripción automática', 'suscripciones automáticas'],
        ];
        $partes = [];
        foreach ($dependencias as $clave => $n) {
            if (isset($nombres[$clave]) && $n > 0) {
                $partes[] = $n.' '.$nombres[$clave][$n === 1 ? 0 : 1];
            }
        }
        if ($partes === []) {
            return 'hay registros que lo referencian';
        }
        $ultima = array_pop($partes);

        return 'lo usan '.($partes === [] ? $ultima : implode(', ', $partes).' y '.$ultima);
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

        DB::transaction(function () use ($request, $plan, $data): void {
            $plan->features = array_merge($plan->resolvedFeatures(), $data['features']);
            $plan->save();

            app(AuditTrail::class)->record($request, [
                'action' => 'update', 'module' => 'Planes', 'entity' => 'plan',
                'entity_id' => $plan->id, 'target_name' => $plan->name,
                'summary' => "Cambió las funciones incluidas en {$plan->name}",
                'changes' => [['field' => 'features']],
                'metadata' => $this->eventMetadata($plan, 'plan.updated'),
            ]);

            // La señal privada de la app también pertenece a esta mutación.
            $this->notifyPlanMembers($plan);
        });

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
        $membresia = [
            'active' => true,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'plan' => $plan,
            'end_date' => null,
        ];
        $caps = $this->aiAccess->getAiCapabilities($membresia);
        // Lo GUARDADO, no lo aplicado: si Iron IA está apagada para el plan se
        // aplica el nivel 1, pero el CRM tiene que volver a enseñar lo que el
        // administrador eligió o lo perdería al guardar otra cosa.
        $potencias = $this->aiAccess->getPlanPowers($membresia)['configured'];

        return response()->json([
            'planId' => (string) $plan->id,
            'planName' => $plan->name,
            'capabilities' => $this->capabilitiesToApi($caps, $potencias),
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
            // Potencias de Iron IA (docs/iron-ia/POTENCIAS-IRON-IA.md §3): un
            // entero de 1 a 5. Ni null, ni 3.5, ni `true` (que `integer` solo
            // daría por un 1; `numeric` lo rechaza): un nivel que no existe no
            // tiene estrategia detrás.
            'ai_nutrition_power' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:5'],
            'ai_routine_power' => ['sometimes', 'numeric', 'integer', 'min:1', 'max:5'],
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
            'ai_nutrition_power' => 'nutrition_power',
            'ai_routine_power' => 'routine_power',
        ];

        $columns = [];
        foreach ($map as $apiKey => $col) {
            if (array_key_exists($apiKey, $d)) {
                $columns[$col] = $d[$apiKey];
            }
        }

        $row = DB::transaction(function () use ($request, $plan, $columns): MembershipAiCapability {
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
                // `updateOrCreate` conserva el seguimiento de Eloquent.
                'changes' => app(AuditTrail::class)->changesOf($row),
                'metadata' => $this->eventMetadata($plan, 'plan.updated'),
            ]);

            $this->notifyPlanMembers($plan);

            return $row;
        });

        return response()->json([
            'planId' => (string) $plan->id,
            'planName' => $plan->name,
            'capabilities' => $this->capabilitiesToApi($row->toCapabilities(), $row->toPowers()),
        ]);
    }

    /** Señal mínima que el canal del CRM publica desde la auditoría comprometida. */
    private function eventMetadata(Plan $plan, string $type): array
    {
        return ['event' => $type, 'plan_id' => (int) $plan->id, 'changed' => ['plans', 'features']];
    }

    /**
     * Convierte capacidades internas (config/fila) al contrato del CRM (ai_*).
     *
     * @param  array{nutrition?: int, routine?: int}  $powers  lo guardado en la fila
     */
    private function capabilitiesToApi(array $c, array $powers = []): array
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
            'ai_nutrition_power' => MembershipAiCapability::power($powers['nutrition'] ?? 1),
            'ai_routine_power' => MembershipAiCapability::power($powers['routine'] ?? 1),
        ];
    }

    /**
     * Guarda las reglas de acceso ya normalizadas, nunca lo que llegó crudo.
     *
     * Por qué aquí y no al leer: lo que se escribe en la fila tiene que ser
     * exactamente lo que después leen el terminal de recepción y el CRM. Si se
     * guardara «6:0» o el día 0 y cada uno lo interpretara a su manera, un plan
     * de horas valle abriría a horas distintas según quién pregunte.
     *
     * En una edición parcial se completa con lo que el plan ya tiene: quien
     * cambia solo el precio no debe perder sus franjas horarias.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeAccess(array $data, ?Plan $plan): array
    {
        $claves = ['access_mode', 'entry_credits', 'access_days', 'access_windows'];
        $tocadas = array_filter($claves, fn (string $k): bool => array_key_exists($k, $data));

        if ($tocadas === []) {
            return $data;
        }

        $crudo = [];
        foreach ($claves as $clave) {
            $crudo[$clave] = array_key_exists($clave, $data) ? $data[$clave] : ($plan?->{$clave});
        }

        // Un plan por consumo sin número de entradas sería una puerta cerrada.
        // PlanAccessRules lo degrada a ilimitado en silencio —hace falta, porque
        // lee filas que ya existen—, pero aquí hay alguien llenando un
        // formulario y se le puede decir.
        if (($crudo['access_mode'] ?? null) === PlanAccessRules::MODE_ENTRIES
            && (int) ($crudo['entry_credits'] ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'entry_credits' => 'Indica cuántas entradas incluye el plan por consumo.',
            ]);
        }

        $reglas = PlanAccessRules::fromArray($crudo);

        $data['access_mode'] = $reglas->mode;
        $data['entry_credits'] = $reglas->entries;
        // Vacío se guarda como NULL y no como «[]»: «sin restricción» es la
        // ausencia de regla, y así lo dice también la columna.
        $data['access_days'] = $reglas->days === [] ? null : $reglas->days;
        $data['access_windows'] = $reglas->windows === [] ? null : $reglas->windows;

        return $data;
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

    private function validatedData(Request $request, bool $updating, ?Plan $plan = null): array
    {
        $req = $updating ? ['sometimes', 'required'] : ['required'];

        $data = $request->validate([
            'name' => [...$req, 'string', 'max:255'],
            'tier' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', Plan::TIERS)],
            'price' => [...$req, 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            /*
             * CERO DÍAS ES UN COBRO QUE NO DA VIGENCIA.
             *
             * Existe porque ya existía el caso: la «Comisión de
             * personalizados» estaba cargada como un plan de treinta días, y
             * un plan define la membresía de quien lo tiene. Por eso hubo
             * socios con la vigencia puesta por una comisión, vencida en 2027
             * y sin poder entrar.
             *
             * MembershipPeriod::apply() ya se detiene en `dias <= 0` y no
             * toca la membresía de nadie; lo único que faltaba era poder
             * ponerlo sin entrar a la base a mano. Para cobros nuevos está el
             * módulo de Comisiones: esto es para los planes heredados que ya
             * están en el catálogo y en el histórico.
             */
            'duration_days' => [$updating ? 'sometimes' : 'required_without_all:duration_months,months', 'integer', 'min:0'],
            // TIPO DE PLAN. 'unlimited' es el de siempre: entra cuantas veces
            // quiera mientras esté vigente. 'entries' es por consumo: la
            // vigencia sigue siendo `duration_days` y ademas trae un número de
            // entradas («15 entradas en un mes», el plan Valera).
            'access_mode' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', PlanAccessRules::MODES)],
            'entry_credits' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.PlanAccessRules::MAX_ENTRIES],
            // Días de la semana en ISO (1 lunes … 7 domingo). Vacío o los siete
            // significa sin restricción.
            'access_days' => ['sometimes', 'nullable', 'array', 'max:7'],
            'access_days.*' => ['integer', 'min:0', 'max:7'],
            // Horas valle: las franjas en las que se puede entrar.
            'access_windows' => ['sometimes', 'nullable', 'array', 'max:'.PlanAccessRules::MAX_WINDOWS],
            'access_windows.*.from' => ['required', 'string', 'max:8'],
            'access_windows.*.to' => ['required', 'string', 'max:8'],
            'access_windows.*.label' => ['nullable', 'string', 'max:40'],
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
            // Visible en la app del socio. Independiente de `active`: un plan
            // puede seguir cobrándose en caja sin anunciarse en el teléfono.
            'visible_in_app' => ['sometimes', 'boolean'],
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

        $data = $this->normalizeAccess($data, $plan);

        if (array_key_exists('benefits', $data) && is_array($data['benefits'])) {
            $data['benefits'] = json_encode(array_values(array_filter(array_map(
                fn (mixed $benefit): string => trim((string) $benefit),
                $data['benefits']
            ))));
        }

        return $data;
    }
}
