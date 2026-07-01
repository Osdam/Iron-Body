<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\MarketingAgentAction;
use App\Models\MarketingConversation;
use App\Services\Marketing\MarketingAgentActionAuthorizationService;
use App\Services\Marketing\MarketingAgentActionExecutionService;
use App\Services\Marketing\MarketingAgentActionService;
use App\Services\Marketing\MarketingAgentRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Acciones CRM del agente comercial (Fase 4C). Human-in-the-loop: recomendar,
 * aprobar, rechazar, ejecutar, cancelar — todo auditado. Controlador delgado;
 * la lógica vive en los servicios. Protegido por el blindaje global /api/admin/*.
 */
class MarketingAgentActionController extends Controller
{
    public function __construct(
        private readonly MarketingAgentActionService $actions,
        private readonly MarketingAgentActionAuthorizationService $authz,
    ) {
    }

    private function admin(Request $request): ?Admin
    {
        $a = $request->attributes->get('auth_admin');

        return $a instanceof Admin ? $a : null;
    }

    private function guard(Request $request, string $capability): ?JsonResponse
    {
        $deny = $this->authz->deny($this->admin($request), $capability);
        if ($deny !== null) {
            return response()->json(['ok' => false, 'code' => $deny['code'], 'message' => $deny['message']], $deny['status']);
        }

        return null;
    }

    /** Carga la acción + valida ownership de su conversación. */
    private function findOwned(Request $request, int $id): array
    {
        $action = MarketingAgentAction::with('conversation')->find($id);
        if (! $action) {
            return [null, response()->json(['ok' => false, 'code' => 'not_found', 'message' => 'Acción no encontrada.'], 404)];
        }
        if (! $this->authz->ownsConversation($this->admin($request), $action->conversation)) {
            return [null, response()->json(['ok' => false, 'code' => 'agent_actions_forbidden', 'message' => 'No puedes operar acciones de otra conversación.'], 403)];
        }

        return [$action, null];
    }

    // ── Lista ────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAgentActionAuthorizationService::CAP_VIEW)) {
            return $r;
        }
        $request->validate([
            'status'   => ['nullable', Rule::in(MarketingAgentAction::STATUSES)],
            'type'     => ['nullable', Rule::in(MarketingAgentAction::TYPES)],
            'priority' => ['nullable', Rule::in(MarketingAgentAction::PRIORITIES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = $this->actions->list($request, $this->admin($request));

        return response()->json([
            'ok'   => true,
            'data' => collect($page->items())->map(fn ($a) => $this->actions->present($a))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAgentActionAuthorizationService::CAP_VIEW)) {
            return $r;
        }
        [$action, $err] = $this->findOwned($request, $id);
        if ($err) {
            return $err;
        }

        return response()->json(['ok' => true, 'data' => $this->actions->present($action)]);
    }

    // ── Recomendar (global por payload de conversación) ──────────────────────
    public function recommend(Request $request, MarketingAgentRecommendationService $engine): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAgentActionAuthorizationService::CAP_RECOMMEND)) {
            return $r;
        }
        $data = $request->validate([
            'marketing_conversation_id' => ['required', 'integer', 'exists:marketing_conversations,id'],
        ]);

        return $this->runRecommend($request, (int) $data['marketing_conversation_id'], $engine);
    }

    // ── Recomendar para una conversación (ruta del Inbox) ────────────────────
    public function recommendForConversation(Request $request, int $id, MarketingAgentRecommendationService $engine): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAgentActionAuthorizationService::CAP_RECOMMEND)) {
            return $r;
        }

        return $this->runRecommend($request, $id, $engine);
    }

    private function runRecommend(Request $request, int $conversationId, MarketingAgentRecommendationService $engine): JsonResponse
    {
        $conversation = MarketingConversation::find($conversationId);
        if (! $conversation) {
            return response()->json(['ok' => false, 'code' => 'not_found', 'message' => 'Conversación no encontrada.'], 404);
        }
        if (! $this->authz->ownsConversation($this->admin($request), $conversation)) {
            return response()->json(['ok' => false, 'code' => 'agent_actions_forbidden', 'message' => 'No puedes analizar una conversación de otro asesor.'], 403);
        }

        $result = $engine->recommend($conversation);
        $created = $result['created'];
        $skipped = $result['skipped'];
        $reason = $result['reason'];

        // Respuesta SIEMPRE 200 con JSON explícito (nunca 204 silencioso).
        return response()->json([
            'ok'            => true,
            'created_count' => count($created),
            'actions'       => collect($created)->map(fn ($a) => $this->actions->present($a))->all(),
            'skipped'       => $skipped,
            'reason'        => $reason,
            'message'       => $this->recommendMessage(count($created), $skipped, $reason),
        ], 200);
    }

    /** Mensaje legible para el CRM según el resultado del análisis. */
    private function recommendMessage(int $createdCount, array $skipped, ?string $reason): string
    {
        if ($createdCount > 0) {
            return "Se generaron {$createdCount} acción(es) sugerida(s).";
        }

        return match ($reason) {
            'conversation_has_no_messages' => 'La conversación aún no tiene mensajes del lead para analizar.',
            'all_suggestions_deduplicated' => 'No se generaron acciones nuevas: ya existen sugerencias abiertas para esta conversación.',
            default                        => 'No se generaron acciones nuevas para esta conversación.',
        };
    }

    // ── Citas/acciones de una conversación ───────────────────────────────────
    public function forConversation(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAgentActionAuthorizationService::CAP_VIEW)) {
            return $r;
        }
        $conversation = MarketingConversation::find($id);
        if (! $conversation) {
            return response()->json(['ok' => false, 'code' => 'not_found', 'message' => 'Conversación no encontrada.'], 404);
        }
        if (! $this->authz->ownsConversation($this->admin($request), $conversation)) {
            return response()->json(['ok' => false, 'code' => 'agent_actions_forbidden', 'message' => 'Sin acceso.'], 403);
        }

        $data = MarketingAgentAction::with('lead:id,name,phone')
            ->where('marketing_conversation_id', $id)
            ->latest('created_at')
            ->limit(30)
            ->get()
            ->map(fn ($a) => $this->actions->present($a))
            ->all();

        return response()->json(['ok' => true, 'data' => $data]);
    }

    // ── Transiciones ─────────────────────────────────────────────────────────
    public function approve(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAgentActionAuthorizationService::CAP_APPROVE)) {
            return $r;
        }
        [$action, $err] = $this->findOwned($request, $id);
        if ($err) {
            return $err;
        }
        $this->actions->approve($action, $this->admin($request)?->id);

        return response()->json(['ok' => true, 'data' => $this->actions->present($action->fresh())]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAgentActionAuthorizationService::CAP_REJECT)) {
            return $r;
        }
        [$action, $err] = $this->findOwned($request, $id);
        if ($err) {
            return $err;
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $this->actions->reject($action, $this->admin($request)?->id, $data['reason'] ?? null);

        return response()->json(['ok' => true, 'data' => $this->actions->present($action->fresh())]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAgentActionAuthorizationService::CAP_CANCEL)) {
            return $r;
        }
        [$action, $err] = $this->findOwned($request, $id);
        if ($err) {
            return $err;
        }
        $this->actions->cancel($action, $this->admin($request)?->id);

        return response()->json(['ok' => true, 'data' => $this->actions->present($action->fresh())]);
    }

    // ── Ejecutar (la confirmación humana) ────────────────────────────────────
    public function execute(Request $request, int $id, MarketingAgentActionExecutionService $executor): JsonResponse
    {
        if ($r = $this->guard($request, MarketingAgentActionAuthorizationService::CAP_EXECUTE)) {
            return $r;
        }
        [$action, $err] = $this->findOwned($request, $id);
        if ($err) {
            return $err;
        }

        // Solo se ejecuta desde estados accionables.
        if (! in_array($action->status, [MarketingAgentAction::STATUS_SUGGESTED, MarketingAgentAction::STATUS_APPROVED], true)) {
            return response()->json(['ok' => false, 'code' => 'agent_action_not_executable', 'message' => 'La acción no está en un estado ejecutable.'], 422);
        }

        // Permiso por TIPO (whitelist) — nunca un tipo desconocido.
        if (! in_array($action->action_type, MarketingAgentAction::TYPES, true)
            || ! $this->authz->canExecuteType($this->admin($request), $action->action_type)) {
            return response()->json(['ok' => false, 'code' => 'agent_action_type_forbidden', 'message' => 'Tu rol no puede ejecutar este tipo de acción.'], 403);
        }

        $executed = $executor->execute($action, $this->admin($request)?->id);

        $httpOk = $executed->status === MarketingAgentAction::STATUS_EXECUTED;

        return response()->json([
            'ok'   => $httpOk,
            'data' => $this->actions->present($executed->fresh()),
        ], $httpOk ? 200 : 422);
    }

    // ── Capacidades ──────────────────────────────────────────────────────────
    public function capabilities(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        if (! $admin instanceof Admin) {
            return response()->json(['ok' => false, 'code' => 'agent_actions_requires_admin', 'message' => 'Requiere sesión de administrador.'], 401);
        }
        if (! $admin->isActive()) {
            return response()->json(['ok' => false, 'code' => 'agent_actions_admin_inactive', 'message' => 'Tu cuenta no está activa.'], 403);
        }

        return response()->json(['ok' => true, 'data' => $this->authz->frontendCapabilities($admin)]);
    }
}
