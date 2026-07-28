<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\ListAppealsRequest;
use App\Http\Requests\Moderation\ListReportsRequest;
use App\Http\Resources\Moderation\AdminReportDetailResource;
use App\Http\Resources\Moderation\AdminReportResource;
use App\Models\Admin;
use App\Models\ContentReport;
use App\Models\Member;
use App\Models\MemberSuspension;
use App\Models\ModerationAction;
use App\Models\ModerationAppeal;
use App\Models\ModerationAuditLog;
use App\Models\Story;
use App\Services\Moderation\AppealService;
use App\Services\Moderation\EvidenceService;
use App\Services\Moderation\ModerationAudit;
use App\Services\Moderation\ModerationDecisionService;
use App\Services\Moderation\ModerationNotifier;
use App\Support\Moderation\ActionType;
use App\Support\Moderation\ModerationPermission;
use App\Support\Moderation\ModerationScope;
use App\Support\Moderation\ReportReason;
use App\Support\Moderation\ReportStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Moderación de comunidad — superficie administrativa (CRM).
 *
 * Autenticación: todas las rutas viven bajo `/api/admin/*`, cubiertas por el
 * guard global `ProtectAdminPaths`. Sobre eso, CADA acción comprueba su permiso
 * concreto con {@see ModerationPermission}: tener token de admin no basta para
 * suspender a nadie.
 *
 * El token compartido de automatizaciones no resuelve a un `Admin`, así que
 * obtiene únicamente lectura — ninguna sanción puede aplicarse con él.
 *
 * Ninguna respuesta de este controlador incluye datos del reportante.
 */
class ModerationController extends Controller
{
    public function __construct(
        private ModerationDecisionService $decisions,
        private AppealService $appeals,
        private EvidenceService $evidence,
        private ModerationAudit $audit,
        private ModerationNotifier $notifier,
    ) {}

    // ── Dashboard ─────────────────────────────────────────────────────────

    /** GET /api/admin/moderation/dashboard */
    public function dashboard(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request, ModerationPermission::VIEW)) {
            return $denied;
        }

        $byStatus = ContentReport::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Tiempo medio de resolución (minutos) de los casos ya resueltos.
        $resolved = ContentReport::query()
            ->whereNotNull('resolved_at')
            ->whereNotNull('submitted_at')
            ->orderByDesc('resolved_at')
            ->limit(500)
            ->get(['submitted_at', 'resolved_at']);

        $avgMinutes = $resolved->isEmpty()
            ? null
            : (int) round($resolved->avg(
                fn ($r) => $r->submitted_at->diffInMinutes($r->resolved_at)
            ));

        // Reincidentes: miembros con 2+ acciones de moderación no revocadas.
        $repeatOffenders = ModerationAction::query()
            ->whereNotNull('target_member_id')
            ->whereNull('revoked_at')
            ->select('target_member_id')
            ->groupBy('target_member_id')
            ->havingRaw('COUNT(*) >= 2')
            ->get()
            ->count();

        return response()->json([
            'ok' => true,
            'data' => [
                'new_reports' => (int) ($byStatus[ReportStatus::SUBMITTED] ?? 0),
                'triaged' => (int) ($byStatus[ReportStatus::TRIAGED] ?? 0),
                'under_review' => (int) ($byStatus[ReportStatus::UNDER_REVIEW] ?? 0),
                'awaiting_information' => (int) ($byStatus[ReportStatus::AWAITING_INFORMATION] ?? 0),
                'actioned' => (int) ($byStatus[ReportStatus::ACTIONED] ?? 0),
                'dismissed' => (int) ($byStatus[ReportStatus::DISMISSED] ?? 0),
                'closed' => (int) ($byStatus[ReportStatus::CLOSED] ?? 0),
                'critical_open' => ContentReport::query()
                    ->whereIn('status', ReportStatus::open())
                    ->where('severity', ReportReason::SEVERITY_CRITICAL)
                    ->count(),
                'actions_applied' => ModerationAction::query()->whereNull('revoked_at')->count(),
                'actions_revoked' => ModerationAction::query()->whereNotNull('revoked_at')->count(),
                'pending_appeals' => ModerationAppeal::query()->open()->count(),
                'active_suspensions' => MemberSuspension::query()->effective()->count(),
                'quarantined_content' => Story::withTrashed()
                    ->whereIn('moderation_state', [
                        Story::MODERATION_QUARANTINED,
                        Story::MODERATION_REMOVED,
                    ])->count(),
                'avg_resolution_minutes' => $avgMinutes,
                'repeat_offenders' => $repeatOffenders,
                'reports_by_reason' => ContentReport::query()
                    ->selectRaw('reason_code, COUNT(*) as total')
                    ->groupBy('reason_code')
                    ->pluck('total', 'reason_code'),
                // El CRM pinta los botones con esto; la autoridad sigue siendo
                // el backend en cada endpoint.
                'permissions' => ModerationPermission::forAdmin($this->admin($request)),
            ],
        ]);
    }

    // ── Cola de casos ─────────────────────────────────────────────────────

    /** GET /api/admin/moderation/reports */
    public function index(ListReportsRequest $request): JsonResponse
    {
        if ($denied = $this->deny($request, ModerationPermission::VIEW)) {
            return $denied;
        }

        // Los booleanos de la query (`open_only=1`, `open_only=true`, …) ya
        // llegan normalizados por el FormRequest. Ver ListReportsRequest.
        $filters = $request->filters();
        // Reglas y normalización: App\Http\Requests\Moderation\ListReportsRequest.

        $query = ContentReport::query()
            ->with(['reportedMember:id,full_name', 'assignedAdmin:id,name'])
            // Agregados calculados en SQL — el reportante nunca se materializa.
            ->withCount([
                'snapshot as has_evidence',
            ])
            // Reportantes ÚNICOS por contenido, agregado en SQL. Es un entero:
            // nunca se materializa la identidad de quien reportó.
            ->addSelect([
                'unique_reporters' => DB::table('content_reports as cr2')
                    ->selectRaw('COUNT(DISTINCT cr2.reporter_member_id)')
                    ->whereColumn('cr2.content_type', 'content_reports.content_type')
                    ->whereColumn('cr2.content_id', 'content_reports.content_id'),
            ]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['open_only'])) {
            $query->whereIn('status', ReportStatus::open());
        }
        if (! empty($filters['reason_code'])) {
            $query->where('reason_code', $filters['reason_code']);
        }
        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (! empty($filters['assigned_admin_id'])) {
            $query->where('assigned_admin_id', (int) $filters['assigned_admin_id']);
        }
        if (! empty($filters['reported_member_id'])) {
            $query->where('reported_member_id', (int) $filters['reported_member_id']);
        }
        if (! empty($filters['content_type'])) {
            $query->where('content_type', $filters['content_type']);
        }
        if (! empty($filters['from'])) {
            $query->where('submitted_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('submitted_at', '<=', $filters['to']);
        }
        if (! empty($filters['with_evidence'])) {
            $query->whereHas('snapshot', fn ($q) => $q->whereNull('media_purged_at'));
        }
        if (! empty($filters['with_appeal'])) {
            $query->whereHas('actions.appeals');
        }

        $sort = $filters['sort'] ?? null;
        if ($sort) {
            $query->orderBy($sort, $filters['direction'] ?? 'desc');
        } else {
            $query->queueOrder();
        }

        $page = $query->paginate(min(100, max(5, (int) ($filters['per_page'] ?? 25))));

        // `has_appeal` se resuelve en una sola consulta extra en vez de N+1.
        $reportIds = collect($page->items())->pluck('id');
        $withAppeals = ModerationAppeal::query()
            ->whereIn('moderation_action_id', ModerationAction::query()
                ->whereIn('report_id', $reportIds)
                ->select('id'))
            ->join('moderation_actions', 'moderation_actions.id', '=', 'moderation_appeals.moderation_action_id')
            ->pluck('moderation_actions.report_id')
            ->unique()
            ->flip();

        foreach ($page->items() as $item) {
            $item->has_appeal = $withAppeals->has($item->id);
        }

        return response()->json([
            'ok' => true,
            'data' => AdminReportResource::collection($page->items())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'permissions' => ModerationPermission::forAdmin($this->admin($request)),
        ]);
    }

    /** GET /api/admin/moderation/reports/{report} */
    public function show(Request $request, string $report): JsonResponse
    {
        if ($denied = $this->deny($request, ModerationPermission::VIEW)) {
            return $denied;
        }

        $model = $this->findReport($report);

        $model->unique_reporters = (int) ContentReport::query()
            ->forContent($model->content_type, (int) $model->content_id)
            ->distinct('reporter_member_id')
            ->count('reporter_member_id');

        $model->load(['reportedMember:id,full_name', 'assignedAdmin:id,name', 'snapshot', 'story']);

        return response()->json([
            'ok' => true,
            'data' => (new AdminReportDetailResource($model))->resolve($request),
            'permissions' => ModerationPermission::forAdmin($this->admin($request)),
        ]);
    }

    // ── Evidencia ─────────────────────────────────────────────────────────

    /**
     * GET /api/admin/moderation/reports/{report}/evidence
     *
     * Devuelve una URL FIRMADA y temporal (minutos). No existe ninguna URL
     * pública permanente de evidencia, y cada acceso queda auditado.
     */
    public function evidence(Request $request, string $report): JsonResponse
    {
        if ($denied = $this->deny($request, ModerationPermission::VIEW_SENSITIVE_EVIDENCE)) {
            return $denied;
        }

        $model = $this->findReport($report);
        $snapshot = $model->snapshot;

        if (! $snapshot || ! $snapshot->hasReviewableMedia()) {
            return response()->json([
                'ok' => false,
                'code' => 'evidence_unavailable',
                'message' => 'La evidencia ya no está disponible (retención cumplida).',
            ], 404);
        }

        $url = $this->evidence->signedEvidenceUrl($snapshot);

        if ($url === null) {
            return response()->json([
                'ok' => false,
                'code' => 'evidence_unavailable',
                'message' => 'No pudimos recuperar el archivo de evidencia.',
            ], 404);
        }

        $this->audit->admin(
            $this->admin($request),
            ModerationAuditLog::ACTION_EVIDENCE_VIEWED,
            'content_report',
            (int) $model->id,
            null,
            ['report_public_id' => $model->public_id],
            $request,
        );

        return response()->json([
            'ok' => true,
            'data' => [
                // La URL caduca sola. NUNCA se persiste ni se registra en logs.
                'url' => $url,
                'media_type' => $snapshot->media_type,
                'expires_in_minutes' => (int) config('ugc.evidence_signed_url_minutes', 10),
            ],
        ]);
    }

    // ── Flujo del caso ────────────────────────────────────────────────────

    /** POST /api/admin/moderation/reports/{report}/assign */
    public function assign(Request $request, string $report): JsonResponse
    {
        $model = $this->findReport($report);

        $data = $request->validate([
            'admin_id' => 'nullable|integer|exists:admins,id',
            // `self` asigna al admin autenticado sin exponer su id al cliente.
            'assign_to_self' => 'nullable|boolean',
        ]);

        $actor = $this->admin($request);
        $assignee = ! empty($data['assign_to_self'])
            ? $actor?->id
            : ($data['admin_id'] ?? null);

        try {
            $updated = $this->decisions->assign($model, $actor, $assignee, $request);
        } catch (RuntimeException $e) {
            return $this->mapError($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'data' => ['id' => $updated->public_id, 'assigned_admin_id' => $updated->assigned_admin_id],
        ]);
    }

    /** POST /api/admin/moderation/reports/{report}/transition */
    public function transition(Request $request, string $report): JsonResponse
    {
        $model = $this->findReport($report);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(ReportStatus::all())],
            'notes' => 'nullable|string|max:2000',
            'expected_version' => 'nullable|integer|min:0',
        ]);

        try {
            $updated = $this->decisions->transition(
                report: $model,
                actor: $this->admin($request),
                toStatus: $data['status'],
                expectedVersion: isset($data['expected_version']) ? (int) $data['expected_version'] : null,
                notes: $data['notes'] ?? null,
                request: $request,
            );
        } catch (RuntimeException $e) {
            return $this->mapError($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $updated->public_id,
                'status' => $updated->status,
                'lock_version' => (int) $updated->lock_version,
            ],
        ]);
    }

    /**
     * POST /api/admin/moderation/reports/{report}/decision
     *
     * Aplica la acción administrativa. El permiso lo exige el tipo de acción;
     * una sanción permanente exige además permiso elevado.
     */
    public function decision(Request $request, string $report): JsonResponse
    {
        $model = $this->findReport($report);

        $data = $request->validate([
            'action_type' => ['required', 'string', Rule::in(ActionType::all())],
            'duration_minutes' => 'nullable|integer|min:1|max:525600', // hasta 1 año
            'public_reason' => 'nullable|string|max:300',
            'internal_notes' => 'nullable|string|max:2000',
            'idempotency_key' => 'nullable|string|max:100',
            'expected_version' => 'nullable|integer|min:0',
        ]);

        try {
            $action = $this->decisions->decide($model, $this->admin($request), $data, $request);
        } catch (RuntimeException $e) {
            return $this->mapError($e->getMessage());
        }

        // Aviso al sancionado (motivo público, alcance, duración, apelación).
        $this->notifier->actionApplied($action->fresh());

        return response()->json([
            'ok' => true,
            'data' => [
                'action_id' => $action->public_id,
                'action_type' => $action->action_type,
                'scope' => $action->scope,
                'ends_at' => $action->ends_at?->toIso8601String(),
                'is_permanent' => $action->isPermanent(),
            ],
        ], 201);
    }

    // ── Sanciones ─────────────────────────────────────────────────────────

    /** GET /api/admin/moderation/members/{member}/suspensions */
    public function memberSuspensions(Request $request, int $memberId): JsonResponse
    {
        if ($denied = $this->deny($request, ModerationPermission::VIEW)) {
            return $denied;
        }

        $items = MemberSuspension::query()
            ->where('member_id', $memberId)
            ->orderByDesc('starts_at')
            ->limit(100)
            ->get()
            ->map(fn (MemberSuspension $s) => [
                'id' => $s->public_id,
                'scope' => $s->scope,
                'scope_label' => $s->scopeLabel(),
                'status' => $s->status,
                'is_effective' => $s->isEffective(),
                'starts_at' => $s->starts_at?->toIso8601String(),
                'ends_at' => $s->ends_at?->toIso8601String(),
                'is_permanent' => $s->isPermanent(),
                'public_reason' => $s->public_reason,
                'revoked_at' => $s->revoked_at?->toIso8601String(),
            ]);

        return response()->json(['ok' => true, 'data' => $items]);
    }

    /**
     * POST /api/admin/moderation/members/{member}/suspensions
     *
     * Sanción directa (sin caso previo). Se materializa igualmente como
     * `ModerationAction` para que exista traza y para poder apelarla.
     */
    public function createSuspension(Request $request, int $memberId): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', Rule::in(ModerationScope::suspendable())],
            'duration_minutes' => 'nullable|integer|min:1|max:525600',
            'public_reason' => 'required|string|max:300',
            'internal_notes' => 'nullable|string|max:2000',
            'reason_code' => ['nullable', 'string', Rule::in(ReportReason::codes())],
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $actor = $this->admin($request);

        $required = $data['scope'] === ModerationScope::FULL_APP_ACCESS
            ? ModerationPermission::SUSPEND_FULL_ACCESS
            : ModerationPermission::SUSPEND_SOCIAL;

        if ($denied = $this->deny($request, $required)) {
            return $denied;
        }

        // Sin fecha de fin = permanente: siempre exige permiso elevado.
        if (empty($data['duration_minutes'])
            && ($denied = $this->deny($request, ModerationPermission::SUSPEND_FULL_ACCESS))) {
            return $denied;
        }

        if (! Member::whereKey($memberId)->exists()) {
            return response()->json([
                'ok' => false, 'code' => 'member_not_found', 'message' => 'Miembro no encontrado.',
            ], 404);
        }

        if (! empty($data['idempotency_key'])) {
            $existing = ModerationAction::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return response()->json([
                    'ok' => true,
                    'data' => ['action_id' => $existing->public_id, 'duplicate' => true],
                ]);
            }
        }

        $actionType = match ($data['scope']) {
            ModerationScope::STORY_POSTING => ActionType::RESTRICT_POSTING,
            ModerationScope::FULL_APP_ACCESS => ActionType::SUSPEND_FULL,
            default => ActionType::SUSPEND_SOCIAL,
        };

        $duration = isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null;
        $startsAt = now();
        $endsAt = $duration ? $startsAt->copy()->addMinutes($duration) : null;

        $action = DB::transaction(function () use (
            $memberId, $data, $actionType, $duration, $startsAt, $endsAt, $actor
        ): ModerationAction {
            $action = ModerationAction::create([
                'target_member_id' => $memberId,
                'action_type' => $actionType,
                'scope' => $data['scope'],
                'duration_minutes' => $duration,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => strip_tags($data['public_reason']),
                'internal_notes' => isset($data['internal_notes'])
                    ? strip_tags($data['internal_notes'])
                    : null,
                'created_by_admin_id' => $actor?->id,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            MemberSuspension::create([
                'member_id' => $memberId,
                'scope' => $data['scope'],
                'status' => MemberSuspension::STATUS_ACTIVE,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason_code' => $data['reason_code'] ?? null,
                'public_reason' => strip_tags($data['public_reason']),
                'internal_reason' => isset($data['internal_notes'])
                    ? strip_tags($data['internal_notes'])
                    : null,
                'moderation_action_id' => $action->id,
                'created_by_admin_id' => $actor?->id,
            ]);

            return $action;
        });

        $this->audit->admin(
            $actor,
            ModerationAuditLog::ACTION_ACTION_APPLIED,
            'moderation_action',
            (int) $action->id,
            null,
            [
                'target_member_id' => $memberId,
                'scope' => $data['scope'],
                'permanent' => $endsAt === null,
                'source' => 'direct_suspension',
            ],
            $request,
        );

        $this->notifier->actionApplied($action->fresh());

        return response()->json([
            'ok' => true,
            'data' => [
                'action_id' => $action->public_id,
                'scope' => $action->scope,
                'ends_at' => $action->ends_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** POST /api/admin/moderation/suspensions/{suspension}/revoke */
    public function revokeSuspension(Request $request, string $suspension): JsonResponse
    {
        $model = MemberSuspension::where('public_id', $suspension)->first();
        if (! $model) {
            return response()->json([
                'ok' => false, 'code' => 'not_found', 'message' => 'Sanción no encontrada.',
            ], 404);
        }

        $data = $request->validate(['reason' => 'nullable|string|max:300']);

        try {
            $updated = $this->decisions->revokeSuspension(
                $model, $this->admin($request), $data['reason'] ?? null, $request
            );
        } catch (RuntimeException $e) {
            return $this->mapError($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'data' => ['id' => $updated->public_id, 'status' => $updated->fresh()->status],
        ]);
    }

    /** POST /api/admin/moderation/actions/{action}/revoke */
    public function revokeAction(Request $request, string $action): JsonResponse
    {
        $model = ModerationAction::where('public_id', $action)->first();
        if (! $model) {
            return response()->json([
                'ok' => false, 'code' => 'not_found', 'message' => 'Medida no encontrada.',
            ], 404);
        }

        $data = $request->validate(['reason' => 'nullable|string|max:300']);

        try {
            $updated = $this->decisions->revoke(
                $model, $this->admin($request), $data['reason'] ?? null, $request
            );
        } catch (RuntimeException $e) {
            return $this->mapError($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $updated->public_id,
                'revoked_at' => $updated->revoked_at?->toIso8601String(),
            ],
        ]);
    }

    // ── Apelaciones ───────────────────────────────────────────────────────

    /** GET /api/admin/moderation/appeals */
    public function appeals(ListAppealsRequest $request): JsonResponse
    {
        if ($denied = $this->deny($request, ModerationPermission::VIEW)) {
            return $denied;
        }

        $filters = $request->filters();
        // Reglas y normalización: App\Http\Requests\Moderation\ListAppealsRequest.

        $query = ModerationAppeal::query()->with(['member:id,full_name', 'action']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['open_only'])) {
            $query->open();
        }

        $page = $query->orderByDesc('submitted_at')
            ->paginate(min(100, max(5, (int) ($filters['per_page'] ?? 25))));

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(fn (ModerationAppeal $a) => [
                'id' => $a->public_id,
                'status' => $a->status,
                'status_label' => $a->statusLabel(),
                'submitted_at' => $a->submitted_at?->toIso8601String(),
                'resolved_at' => $a->resolved_at?->toIso8601String(),
                'member' => [
                    'id' => (int) $a->member_id,
                    'name' => trim((string) ($a->member?->full_name ?? '')) ?: 'Miembro Iron Body',
                ],
                'action' => [
                    'id' => $a->action?->public_id,
                    'type' => $a->action?->action_type,
                    'type_label' => $a->action ? ActionType::label($a->action->action_type) : null,
                    'scope' => $a->action?->scope,
                    'ends_at' => $a->action?->ends_at?->toIso8601String(),
                    'is_revoked' => $a->action?->isRevoked() ?? false,
                ],
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** GET /api/admin/moderation/appeals/{appeal} */
    public function showAppeal(Request $request, string $appeal): JsonResponse
    {
        if ($denied = $this->deny($request, ModerationPermission::VIEW)) {
            return $denied;
        }

        $model = ModerationAppeal::where('public_id', $appeal)
            ->with(['member:id,full_name', 'action.report'])
            ->first();

        if (! $model) {
            return response()->json([
                'ok' => false, 'code' => 'not_found', 'message' => 'Apelación no encontrada.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $model->public_id,
                'status' => $model->status,
                'status_label' => $model->statusLabel(),
                // Texto del miembro (ya saneado al guardarse).
                'appeal_text' => $model->appeal_text,
                'submitted_at' => $model->submitted_at?->toIso8601String(),
                'resolved_at' => $model->resolved_at?->toIso8601String(),
                'public_resolution' => $model->public_resolution,
                // `resolution_notes` es interno pero SÍ se muestra al moderador
                // en el CRM (no viaja nunca a la app).
                'resolution_notes' => $model->resolution_notes,
                'member' => [
                    'id' => (int) $model->member_id,
                    'name' => trim((string) ($model->member?->full_name ?? '')) ?: 'Miembro Iron Body',
                ],
                'action' => $model->action ? [
                    'id' => $model->action->public_id,
                    'type' => $model->action->action_type,
                    'type_label' => ActionType::label($model->action->action_type),
                    'scope' => $model->action->scope,
                    'public_reason' => $model->action->reason,
                    'internal_notes' => $model->action->internal_notes,
                    'starts_at' => $model->action->starts_at?->toIso8601String(),
                    'ends_at' => $model->action->ends_at?->toIso8601String(),
                    'is_revoked' => $model->action->isRevoked(),
                    'report_id' => $model->action->report?->public_id,
                ] : null,
            ],
        ]);
    }

    /** POST /api/admin/moderation/appeals/{appeal}/resolve */
    public function resolveAppeal(Request $request, string $appeal): JsonResponse
    {
        $model = ModerationAppeal::where('public_id', $appeal)->first();
        if (! $model) {
            return response()->json([
                'ok' => false, 'code' => 'not_found', 'message' => 'Apelación no encontrada.',
            ], 404);
        }

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([
                ModerationAppeal::STATUS_UNDER_REVIEW,
                ModerationAppeal::STATUS_UPHELD,
                ModerationAppeal::STATUS_GRANTED,
                ModerationAppeal::STATUS_REJECTED,
            ])],
            'internal_notes' => 'nullable|string|max:2000',
            'public_resolution' => 'nullable|string|max:300',
        ]);

        try {
            $updated = $this->appeals->resolve(
                appeal: $model,
                actor: $this->admin($request),
                status: $data['status'],
                internalNotes: $data['internal_notes'] ?? null,
                publicResolution: $data['public_resolution'] ?? null,
                request: $request,
            );
        } catch (RuntimeException $e) {
            return $this->mapError($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'data' => ['id' => $updated->public_id, 'status' => $updated->status],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** El admin autenticado, o null si se entró con el token compartido. */
    private function admin(Request $request): ?Admin
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? $admin : null;
    }

    /** Rechazo 403 con el permiso concreto que falta (útil para el CRM). */
    private function deny(Request $request, string $permission): ?JsonResponse
    {
        if (ModerationPermission::allows($this->admin($request), $permission)) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'code' => 'forbidden',
            'message' => 'No tienes permiso para esta acción de moderación.',
            'required_permission' => $permission,
        ], 403);
    }

    /**
     * Resuelve un caso por su `public_id`. Nunca por el id secuencial: eso
     * permitiría enumerar casos ajenos probando enteros.
     */
    private function findReport(string $publicId): ContentReport
    {
        $report = ContentReport::where('public_id', $publicId)->first();

        abort_if($report === null, 404);

        return $report;
    }

    private function mapError(string $code): JsonResponse
    {
        if (str_starts_with($code, 'forbidden:')) {
            return response()->json([
                'ok' => false,
                'code' => 'forbidden',
                'message' => 'No tienes permiso para esta acción de moderación.',
                'required_permission' => substr($code, strlen('forbidden:')),
            ], 403);
        }

        return match ($code) {
            'concurrent_modification' => response()->json([
                'ok' => false,
                'code' => 'concurrent_modification',
                'message' => 'Otro moderador actualizó este caso. Recarga para ver los cambios.',
            ], 409),
            'invalid_transition' => response()->json([
                'ok' => false,
                'code' => 'invalid_transition',
                'message' => 'Esa transición de estado no está permitida.',
            ], 422),
            'invalid_status', 'invalid_action' => response()->json([
                'ok' => false, 'code' => $code, 'message' => 'Valor no válido.',
            ], 422),
            'report_closed' => response()->json([
                'ok' => false,
                'code' => 'report_closed',
                'message' => 'El caso ya está cerrado.',
            ], 422),
            'appeal_already_resolved' => response()->json([
                'ok' => false,
                'code' => 'appeal_already_resolved',
                'message' => 'Otro moderador ya resolvió esta apelación.',
            ], 409),
            'assignee_not_found' => response()->json([
                'ok' => false, 'code' => 'assignee_not_found', 'message' => 'Moderador no encontrado.',
            ], 422),
            default => response()->json([
                'ok' => false, 'code' => 'moderation_failed', 'message' => 'No se pudo completar la acción.',
            ], 400),
        };
    }
}
