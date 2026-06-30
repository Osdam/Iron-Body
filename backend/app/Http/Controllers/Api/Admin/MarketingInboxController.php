<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Services\Marketing\MarketingConversationAssignmentService;
use App\Services\Marketing\MarketingConversationNoteService;
use App\Services\Marketing\MarketingConversationTagService;
use App\Services\Marketing\MarketingInboxAuthorizationService;
use App\Services\Marketing\MarketingInboxService;
use App\Services\Marketing\MarketingManualReplyService;
use App\Services\Marketing\MarketingManualTakeoverService;
use App\Services\Marketing\MarketingStaffReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inbox CRM de WhatsApp (Fase 2A). Opera conversaciones de WhatsApp Cloud API
 * desde el CRM. Protegido por el blindaje global de /api/admin/* (ProtectAdminPaths).
 *
 * Regla crítica de IA:
 *  - human_takeover=true SOLO desde el endpoint takeover (o pause_ai=true en messages).
 *  - ai_enabled=false SOLO manual desde el CRM.
 *  - staff_review NO apaga la IA. Cerrar NO apaga la IA. Responder NO apaga la IA.
 *  - La IA nunca activa human_takeover sola.
 */
class MarketingInboxController extends Controller
{
    public function __construct(
        private readonly MarketingInboxService $inbox,
        private readonly MarketingInboxAuthorizationService $authz,
    ) {
    }

    /** El admin autenticado (sesión real). Null cuando se usa el secreto compartido. */
    private function adminId(Request $request): ?int
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? $admin->id : null;
    }

    private function admin(Request $request): ?Admin
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? $admin : null;
    }

    /**
     * Verifica una capacidad del Inbox. Devuelve la respuesta de rechazo
     * (401/403) o null si está permitida. Toda la lógica de permisos vive en
     * MarketingInboxAuthorizationService (no dispersa en el controlador).
     */
    private function guard(Request $request, string $capability): ?JsonResponse
    {
        $deny = $this->authz->deny($this->admin($request), $capability);
        if ($deny !== null) {
            return response()->json([
                'ok'      => false,
                'code'    => $deny['code'],
                'message' => $deny['message'],
            ], $deny['status']);
        }

        return null;
    }

    private function findConversation(int $id): ?MarketingConversation
    {
        return MarketingConversation::find($id);
    }

    // ── 1. Lista ──────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_VIEW)) {
            return $r;
        }

        $request->validate([
            'q'            => ['nullable', 'string', 'max:80'],
            'status'       => ['nullable', Rule::in(['open', 'closed', 'snoozed', 'pending'])],
            'ai'           => ['nullable', Rule::in(['active', 'paused'])],
            'staff_review' => ['nullable', Rule::in(['pending', 'resolved'])],
            'unread'       => ['nullable', 'boolean'],
            'channel'      => ['nullable', 'string', 'max:20'],
            'per_page'     => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $page = $this->inbox->list($request, $this->adminId($request));

        return response()->json([
            'ok'   => true,
            'data' => collect($page->items())->map(fn ($c) => $this->inbox->presentListItem($c))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }

    // ── 2. Detalle ──────────────────────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_VIEW)) {
            return $r;
        }

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }

        return response()->json(['ok' => true, 'data' => $this->inbox->detail($conversation)]);
    }

    // ── 3. Envío manual ──────────────────────────────────────────────────────────
    public function sendMessage(Request $request, int $id, MarketingManualReplyService $replies): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_REPLY)) {
            return $r;
        }

        $data = $request->validate([
            'body'     => ['required', 'string', 'min:1', 'max:4096'],
            'pause_ai' => ['nullable', 'boolean'],
        ]);

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }
        if ($conversation->lead && ! $conversation->lead->isContactable()) {
            return response()->json(['ok' => false, 'code' => 'dnc_blocked', 'message' => 'El lead pidió no ser contactado.'], 422);
        }

        $result = $replies->send(
            $conversation,
            trim($data['body']),
            (bool) ($data['pause_ai'] ?? false),
            $this->adminId($request),
        );

        return response()->json([
            'ok'        => $result['ok'],
            'dry_run'   => (bool) ($result['dispatch']['dry_run'] ?? false),
            'sent'      => (bool) ($result['dispatch']['sent'] ?? false),
            'reason'    => $result['dispatch']['reason'] ?? null,
            'message_id' => $result['dispatch']['message_id'] ?? null,
            'ai_paused' => $result['ai_paused'],
        ]);
    }

    // ── 4. Pausar IA (manual) ────────────────────────────────────────────────────
    public function takeover(Request $request, int $id, MarketingManualTakeoverService $takeover): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_TAKEOVER)) {
            return $r;
        }

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }

        $takeover->takeover($conversation, $this->adminId($request), $data['reason'] ?? null);

        return response()->json(['ok' => true, 'human_takeover' => true, 'ai_enabled' => false]);
    }

    // ── 5. Reactivar IA (manual) ─────────────────────────────────────────────────
    public function release(Request $request, int $id, MarketingManualTakeoverService $takeover): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_RELEASE)) {
            return $r;
        }

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }

        $takeover->release($conversation, $this->adminId($request));

        return response()->json(['ok' => true, 'human_takeover' => false, 'ai_enabled' => true]);
    }

    // ── 6. Asignar asesor ────────────────────────────────────────────────────────
    public function assign(Request $request, int $id, MarketingConversationAssignmentService $assignment): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_ASSIGN)) {
            return $r;
        }

        $data = $request->validate([
            'assigned_to_admin_id' => ['nullable', 'integer', 'exists:admins,id'],
        ]);

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }

        $assignment->assign($conversation, $data['assigned_to_admin_id'] ?? null, $this->adminId($request));
        $fresh = $conversation->fresh('assignedAdmin');

        return response()->json([
            'ok'          => true,
            'assigned_to' => $fresh?->assignedAdmin ? ['id' => $fresh->assignedAdmin->id, 'name' => $fresh->assignedAdmin->name] : null,
        ]);
    }

    // ── 7. Nota interna ──────────────────────────────────────────────────────────
    public function addNote(Request $request, int $id, MarketingConversationNoteService $notes): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_NOTE)) {
            return $r;
        }

        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:2000']]);

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }

        $note = $notes->add($conversation, $data['body'], $this->adminId($request));

        return response()->json(['ok' => true, 'note' => [
            'id'         => $note->id,
            'body'       => $note->body,
            'created_at' => $note->created_at?->toIso8601String(),
        ]]);
    }

    // ── 8. Tags ──────────────────────────────────────────────────────────────────
    public function tags(Request $request, int $id, MarketingConversationTagService $tags): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_TAG)) {
            return $r;
        }

        $data = $request->validate([
            'add'      => ['nullable', 'array', 'max:10'],
            'add.*'    => ['string', 'max:40'],
            'remove'   => ['nullable', 'array', 'max:10'],
            'remove.*' => ['string', 'max:40'],
        ]);

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }

        $result = $tags->apply($conversation, $data['add'] ?? [], $data['remove'] ?? [], $this->adminId($request));

        return response()->json(['ok' => true, 'tags' => $result]);
    }

    // ── 9. Estado operativo ──────────────────────────────────────────────────────
    public function status(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_UPDATE_STATUS)) {
            return $r;
        }

        $data = $request->validate([
            'status'       => ['required', Rule::in(['open', 'closed', 'snoozed'])],
            'snooze_until' => ['nullable', 'date', 'after:now'],
        ]);

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }

        // CRÍTICO: cambiar el estado operativo NO toca ai_enabled ni human_takeover.
        $changes = ['status' => $data['status']];
        $changes['closed_at'] = $data['status'] === 'closed' ? now() : null;
        $changes['snooze_until'] = $data['status'] === 'snoozed' ? ($data['snooze_until'] ?? null) : null;
        $conversation->forceFill($changes)->save();

        return response()->json([
            'ok'        => true,
            'status'    => $conversation->status,
            'closed_at' => $conversation->closed_at?->toIso8601String(),
        ]);
    }

    // ── 10. Resolver staff_review ────────────────────────────────────────────────
    public function resolveStaffReview(Request $request, int $id, MarketingStaffReviewService $staffReview): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_RESOLVE_REVIEW)) {
            return $r;
        }

        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }

        $staffReview->resolve($conversation, $this->adminId($request), $data['note'] ?? null);

        return response()->json(['ok' => true, 'staff_review_pending' => false]);
    }

    // ── 11. Métricas ──────────────────────────────────────────────────────────────
    public function metrics(Request $request): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_VIEW_METRICS)) {
            return $r;
        }

        return response()->json(['ok' => true, 'data' => $this->inbox->metrics($this->adminId($request))]);
    }

    // ── 12. Capacidades del admin actual (para el frontend) ──────────────────────
    // Requiere admin activo, pero devuelve el mapa aunque algunas capacidades
    // sean false (un rol bloqueado recibe todo en false y el front oculta acciones).
    public function capabilities(Request $request): JsonResponse
    {
        $admin = $this->admin($request);
        if (! $admin instanceof Admin) {
            return response()->json(['ok' => false, 'code' => 'inbox_requires_admin', 'message' => 'El Inbox requiere una sesión de administrador.'], 401);
        }
        if (! $admin->isActive()) {
            return response()->json(['ok' => false, 'code' => 'inbox_admin_inactive', 'message' => 'Tu cuenta no está activa.'], 403);
        }

        return response()->json(['ok' => true, 'data' => $this->authz->frontendCapabilities($admin)]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => 'not_found', 'message' => 'Conversación no encontrada.'], 404);
    }
}
