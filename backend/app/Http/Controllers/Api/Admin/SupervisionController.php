<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CommercialApproval;
use App\Services\Commercial\ApprovalQueueService;
use App\Services\Commercial\CommercialAlertService;
use App\Services\Commercial\CommercialVocabulary as V;
use App\Services\Commercial\SupervisionService;
use App\Services\Marketing\MarketingInboxAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Centro de supervisión del agente.
 *
 * Los permisos NO son uno solo, y esa es la parte importante. Ver qué está
 * pasando y autorizar un reembolso son cosas distintas: recepción necesita lo
 * primero para trabajar y no debería poder lo segundo. Se apoyan en el mapa de
 * capacidades que ya existía en vez de inventar roles nuevos:
 *
 *  · **Ver supervisión** — cualquier rol con acceso al inbox. Es la pantalla
 *    desde la que se trabaja.
 *  · **Aprobar excepciones** — solo visión completa. Devolver dinero o corregir
 *    un documento fiscal no es una tarea de atención al cliente.
 *  · **Diagnóstico técnico** — solo visión completa. A recepción no le dice
 *    nada y le añade ruido a una pantalla que se usa con gente esperando.
 */
class SupervisionController extends Controller
{
    public function __construct(
        private readonly SupervisionService $supervision,
        private readonly ApprovalQueueService $approvals,
        private readonly MarketingInboxAuthorizationService $authz,
    ) {}

    // ── E.1 Estado ──────────────────────────────────────────────────────

    public function state(Request $request): JsonResponse
    {
        if ($denied = $this->guardView($request)) {
            return $denied;
        }

        return response()->json(['ok' => true, 'data' => $this->supervision->state()]);
    }

    /**
     * Qué puede hacer ESTE supervisor.
     *
     * La interfaz lo pregunta antes de pintar: un botón de aprobar que después
     * devuelve 403 es peor que no enseñarlo.
     */
    public function capabilities(Request $request): JsonResponse
    {
        $admin = $this->admin($request);

        if (! $admin instanceof Admin || ! $admin->isActive()) {
            return response()->json(['ok' => false, 'code' => 'requires_admin'], 401);
        }

        $full = $this->authz->isFull($admin);

        return response()->json(['ok' => true, 'data' => [
            'can_view' => $this->authz->can($admin, MarketingInboxAuthorizationService::CAP_VIEW),
            'can_view_metrics' => $this->authz->can($admin, MarketingInboxAuthorizationService::CAP_VIEW_METRICS),
            'can_takeover' => $this->authz->can($admin, MarketingInboxAuthorizationService::CAP_TAKEOVER),
            'can_approve' => $full,
            'can_view_incidents' => $full,
            'can_run_safe_actions' => $full,
        ]]);
    }

    // ── E.2 Actividad ───────────────────────────────────────────────────

    /**
     * Lo que ha ido pasando, en orden y en lenguaje operativo.
     *
     * Se mezclan varias fuentes —hechos comerciales, herramientas ejecutadas y
     * decisiones del agente— en una sola línea de tiempo, porque quien
     * supervisa no piensa en tablas: piensa en «qué pasó con esta persona».
     *
     * **No sale el razonamiento del modelo.** Solo la decisión resumida, la
     * regla aplicada y la evidencia. El razonamiento interno no es auditable ni
     * estable, y publicarlo invita a discutir con él en vez de con los hechos.
     */
    public function activity(Request $request): JsonResponse
    {
        if ($denied = $this->guardView($request)) {
            return $denied;
        }

        $data = $request->validate([
            'hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        $since = now()->subHours((int) ($data['hours'] ?? 24));
        $limit = (int) ($data['limit'] ?? 60);
        $conversationId = $data['conversation_id'] ?? null;

        $events = collect()
            ->merge($this->toolEvents($since, $limit, $conversationId))
            ->merge($this->decisionEvents($since, $limit, $conversationId))
            ->merge($this->commercialEvents($since, $limit))
            ->sortByDesc('at')
            ->take($limit)
            ->values();

        return response()->json(['ok' => true, 'data' => $events->all()]);
    }

    /** @return array<int,array<string,mixed>> */
    private function toolEvents(Carbon $since, int $limit, ?int $conversationId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('commercial_tool_invocations')) {
            return [];
        }

        return DB::table('commercial_tool_invocations')
            ->where('created_at', '>=', $since)
            ->when($conversationId, fn ($q) => $q->where('marketing_conversation_id', $conversationId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'at' => $r->created_at,
                'kind' => 'tool',
                'actor' => 'ia',
                'conversation_id' => $r->marketing_conversation_id,
                'lead_id' => $r->marketing_lead_id,
                'action' => 'Ejecutó una herramienta',
                'tool' => $r->tool,
                'goal' => $r->goal,
                'result' => $r->status,
                'reason' => $r->reason,
                'duration_ms' => $r->duration_ms,
                'correlation_id' => $r->correlation_id,
                'error' => $r->error_code,
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function decisionEvents(Carbon $since, int $limit, ?int $conversationId): array
    {
        return DB::table('marketing_ai_actions')
            ->where('created_at', '>=', $since)
            ->when($conversationId, fn ($q) => $q->where('conversation_id', $conversationId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'at' => $r->created_at,
                'kind' => 'decision',
                'actor' => 'ia',
                'conversation_id' => $r->conversation_id,
                'lead_id' => $r->lead_id,
                'action' => $this->decisionLabel($r->action_type),
                // `reason` es el motivo OPERATIVO que registró el sistema, no
                // el razonamiento del modelo.
                'reason' => $r->reason,
                'confidence' => $r->confidence,
                'result' => $r->status,
            ])->all();
    }

    /** @return array<int,array<string,mixed>> */
    private function commercialEvents(Carbon $since, int $limit): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('commercial_events')) {
            return [];
        }

        return DB::table('commercial_events')
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'at' => $r->occurred_at,
                'kind' => 'fact',
                'actor' => 'sistema',
                'lead_id' => $r->marketing_lead_id,
                'action' => $this->factLabel($r->event),
                'result' => 'ok',
                'correlation_id' => $r->correlation_id,
            ])->all();
    }

    // ── E.3 Decisiones ──────────────────────────────────────────────────

    /** Vista auditable: qué decidió, con qué evidencia y en qué acabó. */
    public function decisions(Request $request): JsonResponse
    {
        if ($denied = $this->guardView($request)) {
            return $denied;
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'goal' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = DB::table('commercial_opportunities')
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['goal'] ?? null, fn ($q, $g) => $q->where('goal', $g))
            ->orderByDesc('id')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return response()->json(['ok' => true, 'data' => $rows->map(fn ($r) => [
            'id' => $r->id,
            'goal' => $r->goal,
            'status' => $r->status,
            'next_action' => $r->next_action,
            'next_offer' => $r->next_offer,
            'reason' => $r->reason,
            'confidence' => $r->confidence,
            'exclusions' => $this->decodeJson($r->exclusions),
            'evidence' => $this->decodeJson($r->evidence),
            'attempts' => $r->attempts,
            'max_attempts' => $r->max_attempts,
            'estimated_value' => $r->estimated_value,
            'realized_value' => $r->realized_value,
            'outcome' => $r->outcome,
            'act_after' => $r->act_after,
            'created_at' => $r->created_at,
        ])->all()]);
    }

    // ── E.4 Aprobaciones ────────────────────────────────────────────────

    public function approvals(Request $request): JsonResponse
    {
        if ($denied = $this->guardView($request)) {
            return $denied;
        }

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = CommercialApproval::query()
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return response()->json(['ok' => true, 'data' => $rows->map(
            fn (CommercialApproval $a) => $this->presentApproval($a),
        )->all()]);
    }

    /** Aprobar, rechazar, pedir cambios o cancelar. */
    public function decide(Request $request, int $id): JsonResponse
    {
        $admin = $this->admin($request);

        // Autorizar excepciones exige visión completa. Es una puerta más
        // estrecha que la de ver la pantalla, a propósito.
        if (! $admin instanceof Admin || ! $this->authz->isFull($admin)) {
            return response()->json([
                'ok' => false,
                'code' => 'approval_forbidden',
                'message' => 'Tu rol no puede autorizar operaciones excepcionales.',
            ], $admin instanceof Admin ? 403 : 401);
        }

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'request_changes', 'cancel'])],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $approval = CommercialApproval::find($id);

        if ($approval === null) {
            return response()->json(['ok' => false, 'code' => 'not_found'], 404);
        }

        $comment = $data['comment'] ?? null;

        if ($data['decision'] === 'request_changes' && ($comment === null || trim($comment) === '')) {
            return response()->json([
                'ok' => false,
                'code' => 'comment_required',
                'message' => 'Pedir cambios sin decir cuáles no ayuda a nadie.',
            ], 422);
        }

        $result = match ($data['decision']) {
            'approve' => $this->approvals->approve($approval, $admin, $comment),
            'reject' => $this->approvals->reject($approval, $admin, $comment),
            'request_changes' => $this->approvals->requestChanges($approval, $admin, (string) $comment),
            'cancel' => $this->approvals->cancel($approval, $admin, $comment),
        };

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'code' => $result['code'],
                'message' => $this->denialMessage($result['code']),
                'data' => $this->presentApproval($result['approval']),
            ], 409);
        }

        return response()->json(['ok' => true, 'data' => $this->presentApproval($result['approval'])]);
    }

    // ── E.5 Oportunidades ───────────────────────────────────────────────

    public function opportunities(Request $request): JsonResponse
    {
        if ($denied = $this->guardView($request)) {
            return $denied;
        }

        $data = $request->validate([
            'goal' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = DB::table('commercial_opportunities')
            ->whereIn('status', V::OPEN_STATUSES)
            ->when($data['goal'] ?? null, fn ($q, $g) => $q->where('goal', $g))
            // El dinero ya comprometido manda: recuperar un pago a medias vale
            // más que empezar una conversación nueva.
            ->orderByDesc('priority')
            ->orderByDesc('estimated_value')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return response()->json(['ok' => true, 'data' => $rows->map(fn ($r) => [
            'id' => $r->id,
            'goal' => $r->goal,
            'status' => $r->status,
            'priority' => $r->priority,
            'next_action' => $r->next_action,
            'next_offer' => $r->next_offer,
            'estimated_value' => $r->estimated_value,
            // La confianza del motor NO es una probabilidad financiera. Se
            // devuelve tal cual y con ese nombre para que nadie la multiplique
            // por el valor y lo llame previsión de ingresos.
            'confidence' => $r->confidence,
            'reason' => $r->reason,
            'evidence' => $this->decodeJson($r->evidence),
            'attempts' => $r->attempts,
            'act_after' => $r->act_after,
            'lead_id' => $r->marketing_lead_id,
            'member_id' => $r->member_id,
        ])->all()]);
    }

    // ── E.8 Incidentes ──────────────────────────────────────────────────

    /**
     * Averías técnicas. Solo para roles con visión completa.
     *
     * Un identificador de correlación, un código de Meta o un estado de job no
     * le dicen nada a recepción y solo añaden ruido a una pantalla que se usa
     * con un cliente esperando al otro lado.
     */
    public function incidents(Request $request): JsonResponse
    {
        $admin = $this->admin($request);

        if (! $admin instanceof Admin || ! $this->authz->isFull($admin)) {
            return response()->json([
                'ok' => false,
                'code' => 'incidents_forbidden',
                'message' => 'Tu rol no tiene acceso al diagnóstico técnico.',
            ], $admin instanceof Admin ? 403 : 401);
        }

        $data = $request->validate([
            'severity' => ['nullable', 'string', 'max:20'],
            'source' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', 'string', 'max:20'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = \App\Models\Incident::query()
            ->when($data['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($data['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            // Abiertos primero y los graves arriba: es como se lee una guardia.
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('last_seen_at')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return response()->json(['ok' => true, 'data' => $rows->map(fn ($i) => [
            'id' => $i->id,
            'severity' => $i->severity,
            'status' => $i->status,
            'source' => $i->source,
            'kind' => $i->kind,
            'title' => $i->title,
            'occurrences' => (int) $i->occurrences,
            'first_seen_at' => $i->first_seen_at?->toIso8601String(),
            'last_seen_at' => $i->last_seen_at?->toIso8601String(),
            'affected_conversations' => (int) $i->affected_conversations,
            'affected_messages' => (int) $i->affected_messages,
            'root_cause' => $i->root_cause,
            'confidence' => $i->confidence,
            'recommended_action' => $i->recommended_action,
            'prevention' => $i->prevention,
            'evidence' => $i->evidence,
            'correlation_ids' => $i->correlation_ids,
            'resolution' => $i->resolution,
            'resolved_at' => $i->resolved_at?->toIso8601String(),
        ])->all()]);
    }

    // ── E.9 Alertas comerciales ─────────────────────────────────────────

    /**
     * Personas esperando algo. Las ve cualquiera que atienda.
     *
     * Al revés que los incidentes: esto NO es técnico y es exactamente el
     * trabajo de quien está en recepción.
     */
    public function alerts(Request $request): JsonResponse
    {
        if ($denied = $this->guardView($request)) {
            return $denied;
        }

        $data = $request->validate([
            'severity' => ['nullable', 'string', 'max:20'],
            'type' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'string', 'max:20'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = \App\Models\CommercialAlert::query()
            ->when($data['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($data['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when(
                $data['status'] ?? null,
                fn ($q, $v) => $q->where('status', $v),
                fn ($q) => $q->whereIn('status', \App\Models\CommercialAlert::OPEN_STATUSES),
            )
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('detected_at')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        return response()->json(['ok' => true, 'data' => $rows->map(fn ($a) => [
            'id' => $a->id,
            'type' => $a->type,
            'severity' => $a->severity,
            'status' => $a->status,
            'title' => $a->title,
            'summary' => $a->summary,
            'suggested_action' => $a->suggested_action,
            'opportunity_value' => $a->opportunity_value !== null ? (float) $a->opportunity_value : null,
            'evidence' => $a->evidence,
            'lead_id' => $a->marketing_lead_id,
            'member_id' => $a->member_id,
            'conversation_id' => $a->marketing_conversation_id,
            'opportunity_id' => $a->commercial_opportunity_id,
            'detected_at' => $a->detected_at?->toIso8601String(),
            'due_at' => $a->due_at?->toIso8601String(),
            'is_overdue' => $a->isOverdue(),
            'owner_admin_id' => $a->owner_admin_id,
            'resolution_note' => $a->resolution_note,
        ])->all()]);
    }

    /** Resolver, ignorar con motivo o asignar. */
    public function decideAlert(Request $request, int $id, CommercialAlertService $alerts): JsonResponse
    {
        if ($denied = $this->guardView($request)) {
            return $denied;
        }

        $admin = $this->admin($request);
        $data = $request->validate([
            'action' => ['required', Rule::in(['resolve', 'ignore', 'assign'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $alert = \App\Models\CommercialAlert::find($id);

        if ($alert === null) {
            return response()->json(['ok' => false, 'code' => 'not_found'], 404);
        }

        // Ignorar SIN motivo no se admite: una alerta que desaparece sin
        // explicación es indistinguible de un fallo, y nadie sabrá después por
        // qué se decidió no hacer nada.
        if ($data['action'] === 'ignore' && trim((string) ($data['note'] ?? '')) === '') {
            return response()->json([
                'ok' => false,
                'code' => 'note_required',
                'message' => 'Para ignorar una alerta hay que decir por qué.',
            ], 422);
        }

        $result = match ($data['action']) {
            'assign' => $alerts->assign($alert, (int) $admin->id),
            'ignore' => $alerts->resolve($alert, (int) $admin->id, 'ignored', $data['note'] ?? null),
            'resolve' => $alerts->resolve($alert, (int) $admin->id, 'resolved', $data['note'] ?? null),
        };

        return response()->json(['ok' => true, 'data' => ['id' => $result->id, 'status' => $result->status]]);
    }

    // ── E.6 Dinero ──────────────────────────────────────────────────────

    public function revenue(Request $request): JsonResponse
    {
        $admin = $this->admin($request);

        if (! $admin instanceof Admin
            || ! $this->authz->can($admin, MarketingInboxAuthorizationService::CAP_VIEW_METRICS)) {
            return response()->json(['ok' => false, 'code' => 'metrics_forbidden'], $admin ? 403 : 401);
        }

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(30);

        return response()->json([
            'ok' => true,
            'data' => $this->supervision->agentRevenue($from, $to),
        ]);
    }

    // ── Presentación ────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function presentApproval(CommercialApproval $a): array
    {
        return [
            'id' => $a->id,
            'uuid' => $a->uuid,
            'type' => $a->type,
            // El estado EFECTIVO, que cuenta la caducidad aunque el job que la
            // marca no haya pasado todavía.
            'status' => $a->effectiveStatus(),
            'stored_status' => $a->status,
            'risk' => $a->risk,
            'amount' => $a->amount !== null ? (float) $a->amount : null,
            'currency' => $a->currency,
            'justification' => $a->justification,
            'impact' => $a->impact,
            'evidence' => $a->evidence,
            'requested_by' => $a->requested_by,
            'lead_id' => $a->marketing_lead_id,
            'member_id' => $a->member_id,
            'conversation_id' => $a->marketing_conversation_id,
            'decided_by_admin_id' => $a->decided_by_admin_id,
            'decided_at' => $a->decided_at?->toIso8601String(),
            'decision_comment' => $a->decision_comment,
            'executed_at' => $a->executed_at?->toIso8601String(),
            'failure_reason' => $a->failure_reason,
            'expires_at' => $a->expires_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'is_open' => $a->isOpen(),
        ];
    }

    private function denialMessage(?string $code): string
    {
        return match ($code) {
            'already_executed' => 'Esta operación ya se ejecutó. Lo que ya ocurrió no se desautoriza cambiando una fila.',
            'already_decided' => 'Otra persona ya decidió sobre esta solicitud.',
            'expired' => 'La solicitud venció antes de que nadie la mirara.',
            default => 'No se pudo aplicar la decisión.',
        };
    }

    private function decisionLabel(?string $type): string
    {
        return match ($type) {
            'reply' => 'Preparó una respuesta',
            'payment_link' => 'Propuso un enlace de pago',
            'escalate_human' => 'Pidió que lo atienda una persona',
            'schedule_followup' => 'Programó un seguimiento',
            default => (string) ($type ?? 'Decisión'),
        };
    }

    private function factLabel(?string $event): string
    {
        return match ($event) {
            V::EV_LEAD_CREATED => 'Se identificó un prospecto',
            V::EV_LEAD_QUALIFIED => 'El prospecto quedó calificado',
            V::EV_PAYMENT_LINK_CREATED => 'Se generó un enlace de pago',
            V::EV_PAYMENT_APPROVED => 'Se aprobó un pago',
            V::EV_PAYMENT_FAILED => 'Un pago fue rechazado',
            V::EV_MEMBERSHIP_ACTIVATED => 'Se activó una membresía',
            V::EV_MEMBERSHIP_RENEWED => 'Se renovó una membresía',
            V::EV_APPOINTMENT_CREATED => 'Se creó una cita',
            V::EV_APPOINTMENT_COMPLETED => 'Se completó una cita',
            V::EV_HUMAN_REQUESTED => 'El cliente pidió hablar con una persona',
            V::EV_OBJECTION_RAISED => 'El cliente puso una objeción',
            default => (string) ($event ?? 'Hecho comercial'),
        };
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value) || $value === null) {
            return $value;
        }

        return json_decode((string) $value, true);
    }

    // ── Puertas ─────────────────────────────────────────────────────────

    private function guardView(Request $request): ?JsonResponse
    {
        $admin = $this->admin($request);
        $denial = $this->authz->deny($admin, MarketingInboxAuthorizationService::CAP_VIEW);

        if ($denial !== null) {
            return response()->json(
                ['ok' => false, 'code' => $denial['code'], 'message' => $denial['message']],
                $denial['status'],
            );
        }

        return null;
    }

    private function admin(Request $request): ?Admin
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? $admin : null;
    }
}
