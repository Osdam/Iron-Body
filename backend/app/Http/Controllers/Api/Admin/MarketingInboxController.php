<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Services\Marketing\InboxContextService;
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
    ) {}

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
                'ok' => false,
                'code' => $deny['code'],
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
            'q' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['open', 'closed', 'snoozed', 'pending'])],
            'ai' => ['nullable', Rule::in(['active', 'paused'])],
            'staff_review' => ['nullable', Rule::in(['pending', 'resolved'])],
            'unread' => ['nullable', 'boolean'],
            'channel' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $page = $this->inbox->list($request, $this->adminId($request));

        return response()->json([
            'ok' => true,
            'data' => collect($page->items())->map(fn ($c) => $this->inbox->presentListItem($c))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
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

        $maxFiles = max(1, (int) config('marketing.media.outbound.max_per_send', 5));

        $data = $request->validate([
            // El texto deja de ser obligatorio cuando hay archivo: mandar una
            // foto sin pie es normal, y exigir una palabra para poder hacerlo
            // llevaría a que la gente escriba un punto.
            'body' => ['nullable', 'string', 'max:4096', 'required_without:attachment_ids'],
            'pause_ai' => ['nullable', 'boolean'],
            'attachment_ids' => ['nullable', 'array', 'max:'.$maxFiles],
            'attachment_ids.*' => ['integer', 'min:1'],
            // Mensaje del cliente al que se responde. Se acepta el id INTERNO;
            // el del proveedor se resuelve aquí para que el navegador no
            // necesite conocerlo ni pueda inventárselo.
            'reply_to_message_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }
        if ($conversation->lead && ! $conversation->lead->canReplyReactively()) {
            return response()->json(['ok' => false, 'code' => 'dnc_blocked', 'message' => 'El lead pidió no ser contactado.'], 422);
        }

        $attachmentIds = array_map('intval', (array) ($data['attachment_ids'] ?? []));

        // Se comprueba ANTES de enviar nada: si un id no es reclamable (de otro
        // asesor, ya enviado, caducado), el asesor tiene que enterarse en vez
        // de ver salir media respuesta.
        if ($attachmentIds !== []) {
            $claimable = app(\App\Services\Marketing\OutboundAttachmentService::class)
                ->claim($attachmentIds, $this->adminId($request));

            if ($claimable->count() !== count(array_unique($attachmentIds))) {
                return response()->json([
                    'ok' => false,
                    'code' => 'attachment_unavailable',
                    'message' => 'Alguno de los archivos ya no está disponible. Vuelve a adjuntarlo.',
                ], 409);
            }
        }

        $result = $replies->send(
            $conversation,
            trim((string) ($data['body'] ?? '')),
            (bool) ($data['pause_ai'] ?? false),
            $this->adminId($request),
            $attachmentIds,
            $this->quotedMetaIdFor($conversation, $data['reply_to_message_id'] ?? null),
        );

        return response()->json([
            'ok' => $result['ok'],
            'dry_run' => (bool) ($result['dispatch']['dry_run'] ?? false),
            'sent' => (bool) ($result['dispatch']['sent'] ?? false),
            'reason' => $result['dispatch']['reason'] ?? null,
            'message_id' => $result['dispatch']['message_id'] ?? null,
            // Con archivos sale más de un mensaje: el frontend necesita saber
            // cuántos para no creer que el envío se quedó a medias.
            'message_ids' => array_values(array_filter(array_map(
                fn (array $d) => $d['message_id'] ?? null,
                $result['dispatches'],
            ))),
            'attachments_sent' => $result['attachments_sent'],
            'ai_paused' => $result['ai_paused'],
        ]);
    }

    /**
     * Sube un archivo para adjuntarlo a una respuesta.
     *
     * Va aparte del envío a propósito. El archivo tiene que estar arriba y
     * validado ANTES de que el asesor pulse enviar: así ve la miniatura, sabe
     * que pesa lo que debe y puede quitarlo. Metido dentro del envío, un
     * archivo de 20 MB dejaría la respuesta bloqueada sin explicar por qué, y
     * un rechazo llegaría cuando ya no hay nada que corregir.
     *
     * POST /api/admin/marketing/inbox/attachments
     */
    public function uploadAttachment(Request $request, \App\Services\Marketing\OutboundAttachmentService $outbound): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_REPLY)) {
            return $r;
        }

        $ceiling = (int) config('marketing.media.max_size_bytes', 25 * 1024 * 1024);

        $request->validate([
            // El techo de `max` es la primera barrera y está en kilobytes. Las
            // de verdad —tipo real, límite de Meta por familia— las aplica el
            // servicio sobre los bytes; esta solo evita tragarse el cuerpo.
            'file' => ['required', 'file', 'max:'.(int) ($ceiling / 1024)],
            'voice' => ['nullable', 'boolean'],
        ]);

        $result = $outbound->store(
            $request->file('file'),
            $this->adminId($request),
            $request->boolean('voice'),
        );

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'code' => $result['code'],
                'message' => $result['message'],
            ], 422);
        }

        $attachment = $result['attachment'];

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $attachment->id,
                'kind' => $attachment->kind,
                'mime_type' => $attachment->detected_mime_type,
                'size_bytes' => $attachment->size_bytes,
                'filename' => $attachment->original_filename,
                'width' => $attachment->width,
                'height' => $attachment->height,
                'voice' => (bool) $attachment->voice,
            ],
        ], 201);
    }

    /**
     * El id de proveedor del mensaje citado.
     *
     * Se exige que el mensaje sea de ESTA conversación: sin esa comprobación,
     * pasar el id de otra dejaría citar ante el cliente algo que se dijo en un
     * chat ajeno.
     */
    private function quotedMetaIdFor(MarketingConversation $conversation, ?int $messageId): ?string
    {
        if ($messageId === null) {
            return null;
        }

        $quoted = \App\Models\MarketingMessage::query()
            ->where('id', $messageId)
            ->where('conversation_id', $conversation->id)
            ->first();

        $metaId = $quoted?->meta_message_id;

        return is_string($metaId) && $metaId !== '' ? $metaId : null;
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
            'ok' => true,
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
            'id' => $note->id,
            'body' => $note->body,
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
            'add' => ['nullable', 'array', 'max:10'],
            'add.*' => ['string', 'max:40'],
            'remove' => ['nullable', 'array', 'max:10'],
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
            'status' => ['required', Rule::in(['open', 'closed', 'snoozed'])],
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
            'ok' => true,
            'status' => $conversation->status,
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

        /*
         * Las capacidades de archivo se calculan en el servidor y no se
         * suponen en el navegador. Que exista ffmpeg —lo que decide si una
         * nota de voz grabada en Chrome se puede convertir a lo que WhatsApp
         * reproduce— es un hecho de ESTA máquina. Enseñar el micrófono sin
         * saberlo llevaría a que alguien grabe treinta segundos y descubra al
         * final que no se puede mandar.
         */
        $outbound = app(\App\Services\Marketing\OutboundAttachmentService::class);

        return response()->json(['ok' => true, 'data' => array_merge(
            $this->authz->frontendCapabilities($admin),
            [
                'attachments' => [
                    'enabled' => (bool) config('marketing.media.outbound.enabled', true),
                    'voice_notes' => $outbound->voiceNotesAvailable(),
                    'max_per_send' => (int) config('marketing.media.outbound.max_per_send', 5),
                    'max_size_bytes' => (array) config('marketing.media.outbound.max_size_bytes', []),
                    'voice_max_seconds' => (int) config('marketing.media.outbound.voice.max_seconds', 300),
                ],
            ],
        )]);
    }

    /**
     * Contexto completo de la conversación para el panel derecho del Inbox V2.
     *
     * Una sola respuesta con lo que vive en siete sitios distintos. Pedirlo con
     * siete llamadas desde el navegador daría una cascada visible: el panel se
     * rellenaría a trozos, en un orden distinto cada vez, mientras quien atiende
     * ya está leyendo.
     *
     * Es de solo lectura. El diagnóstico técnico solo viaja para roles con
     * visión completa: a recepción no le dice nada y le añade ruido.
     */
    public function context(Request $request, int $id, InboxContextService $context): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_VIEW)) {
            return $r;
        }

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }

        return response()->json([
            'ok' => true,
            'data' => $context->build(
                $conversation,
                includeDiagnostics: $this->authz->isFull($this->admin($request)),
            ),
        ]);
    }

    /**
     * Catalogo de etiquetas para el autocompletado del inbox.
     *
     * Devuelve tambien las bloqueadas, marcadas como no editables: el equipo
     * tiene que poder VER que existe "Meta Ads" y filtrar por ella aunque no
     * pueda ponerla a mano. Ocultarlas haria pensar que no existen.
     */
    public function tagCatalog(Request $request): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_VIEW)) {
            return $r;
        }

        $tags = \App\Models\MarketingTag::query()
            ->where('active', true)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => $tags->map(fn ($t) => $t->present())->all(),
        ]);
    }

    /**
     * Historial paginado por cursor.
     *
     * Existe porque el detalle cargaba la conversacion entera: con miles de
     * mensajes eso son megabytes de JSON y varios segundos para leer el
     * ultimo, que es lo unico que se quiere ver al abrir.
     *
     * Se pagina por cursor y no por offset: en una conversacion viva, un
     * mensaje nuevo desplaza todas las paginas y con offset acabarias viendo
     * repetidos o saltandote alguno.
     */
    public function messages(Request $request, int $id): JsonResponse
    {
        if ($r = $this->guard($request, MarketingInboxAuthorizationService::CAP_VIEW)) {
            return $r;
        }

        $data = $request->validate([
            'before' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $conversation = $this->findConversation($id);
        if (! $conversation) {
            return $this->notFound();
        }

        $page = $this->inbox->messagePage(
            $conversation,
            before: $data['before'] ?? null,
            limit: isset($data['limit']) ? (int) $data['limit'] : null,
        );

        return response()->json([
            'ok' => true,
            'items' => $page['items'],
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
            'oldest_id' => $page['oldest_id'],
            'newest_id' => $page['newest_id'],
            // Reloj del servidor: el navegador puede ir desfasado y las horas
            // relativas ("hace 5 min") saldrian mal.
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => 'not_found', 'message' => 'Conversación no encontrada.'], 404);
    }
}
