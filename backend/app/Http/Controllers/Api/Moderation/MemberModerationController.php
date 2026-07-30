<?php

namespace App\Http\Controllers\Api\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Moderation\SubmitAppealRequest;
use App\Http\Requests\Moderation\SubmitReportRequest;
use App\Models\Member;
use App\Models\MemberUgcConsent;
use App\Models\ModerationAction;
use App\Models\ModerationAuditLog;
use App\Models\UserBlock;
use App\Services\Moderation\AppealService;
use App\Services\Moderation\BlockService;
use App\Services\Moderation\ModerationAudit;
use App\Services\Moderation\ReportService;
use App\Services\Moderation\SuspensionService;
use App\Support\Moderation\ActionType;
use App\Support\Moderation\ModerationScope;
use App\Support\Moderation\ReportReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Superficie de moderación para la APP (miembros autenticados).
 *
 * Contrato de seguridad de todo este controlador:
 *  - El actor SIEMPRE sale de `auth_member` (bearer). Ningún endpoint lee un
 *    id de usuario del body o de la query.
 *  - Ningún endpoint revela quién reportó a quién, ni si alguien te bloqueó.
 *  - Ningún endpoint devuelve notas internas de moderación.
 *  - Los códigos de error son estables (`cannot_report_own_content`,
 *    `rate_limited`, …) para que la app reaccione sin parsear textos.
 *
 * Rutas (todas bajo `auth.member`):
 *   GET    /api/app/moderation/report-reasons
 *   POST   /api/app/stories/{story}/report
 *   POST   /api/app/members/{member}/block
 *   DELETE /api/app/members/{member}/block
 *   GET    /api/app/moderation/blocked-members
 *   GET    /api/app/moderation/status
 *   GET    /api/app/moderation/actions
 *   POST   /api/app/moderation/actions/{action}/appeal
 *   POST   /api/app/moderation/guidelines/accept
 */
class MemberModerationController extends Controller
{
    public function __construct(
        private ReportService $reports,
        private BlockService $blocks,
        private SuspensionService $suspensions,
        private AppealService $appeals,
        private ModerationAudit $audit,
    ) {}

    // ── Catálogo ──────────────────────────────────────────────────────────

    /**
     * GET /api/app/moderation/report-reasons
     *
     * La app NO hardcodea los motivos: los pide aquí para que añadir o retirar
     * uno no exija publicar una versión nueva en Google Play.
     */
    public function reportReasons(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => [
                'reasons' => ReportReason::forClient(),
                'detail_max_length' => (int) config('ugc.report_detail_max_length', 500),
                'confidential_notice' => 'Tu reporte es confidencial. La persona reportada '
                    .'nunca sabrá quién lo envió.',
            ],
        ]);
    }

    // ── Reportar ──────────────────────────────────────────────────────────

    /** POST /api/app/stories/{story}/report */
    public function reportStory(SubmitReportRequest $request, int $story): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        // Una cuenta con la interacción social restringida no puede reportar
        // (es una vía habitual de abuso del sistema de reportes).
        if ($this->suspensions->isRestricted((int) $member->id, ModerationScope::STORY_INTERACTION)) {
            return $this->error('interaction_restricted',
                'Tu cuenta tiene las funciones sociales restringidas.', 403);
        }

        try {
            $result = $this->reports->reportStory(
                reporter: $member,
                storyId: $story,
                reasonCode: $request->reasonCode(),
                detail: $request->reasonDetail(),
                request: $request,
            );
        } catch (RuntimeException $e) {
            return $this->mapReportError($e->getMessage());
        }

        $report = $result['report'];

        return response()->json([
            'ok' => true,
            'data' => [
                'report_id' => $report->public_id,
                'status' => $report->status,
                'created' => $result['created'],
                // Mensaje honesto: se recibió el reporte, NO se afirma que el
                // contenido fue eliminado.
                'message' => $result['created']
                    ? 'Recibimos tu reporte. Nuestro equipo revisará el contenido.'
                    : 'Ya tenías un reporte en revisión sobre este contenido.',
            ],
        ], $result['created'] ? 201 : 200);
    }

    /**
     * POST /api/app/members/{member}/report
     *
     * Denuncia a una PERSONA. Función separada de «reportar publicación» y de
     * «bloquear»: bloquear es una decisión privada del usuario, reportar pide
     * la intervención del equipo. Ambas deben existir por separado y así lo
     * exige la política de contenido generado por usuarios de Google Play.
     */
    public function reportMember(SubmitReportRequest $request, int $memberId): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        if ($this->suspensions->isRestricted((int) $member->id, ModerationScope::STORY_INTERACTION)) {
            return $this->error('interaction_restricted',
                'Tu cuenta tiene las funciones sociales restringidas.', 403);
        }

        try {
            $result = $this->reports->reportMember(
                reporter: $member,
                reportedMemberId: $memberId,
                reasonCode: $request->reasonCode(),
                detail: $request->reasonDetail(),
                request: $request,
            );
        } catch (RuntimeException $e) {
            return $this->mapReportError($e->getMessage());
        }

        $report = $result['report'];

        return response()->json([
            'ok' => true,
            'data' => [
                'report_id' => $report->public_id,
                'status' => $report->status,
                'created' => $result['created'],
                'message' => $result['created']
                    ? 'Recibimos tu reporte. Nuestro equipo revisará esta cuenta.'
                    : 'Ya tenías un reporte en revisión sobre esta cuenta.',
            ],
        ], $result['created'] ? 201 : 200);
    }

    // ── Bloquear / desbloquear ────────────────────────────────────────────

    /** POST /api/app/members/{member}/block */
    public function block(Request $request, int $memberId): JsonResponse
    {
        if (! config('ugc.blocking_enabled', true)) {
            return $this->error('blocking_disabled', 'La función no está disponible.', 503);
        }

        /** @var Member $actor */
        $actor = $request->attributes->get('auth_member');

        $data = $request->validate([
            'reason' => 'nullable|string|max:200',
        ]);

        try {
            $result = $this->blocks->block(
                blocker: $actor,
                blockedMemberId: $memberId,
                reason: isset($data['reason']) ? strip_tags((string) $data['reason']) : null,
                request: $request,
            );
        } catch (RuntimeException $e) {
            return match ($e->getMessage()) {
                'self_block_not_allowed' => $this->error(
                    'self_block_not_allowed', 'No puedes bloquearte a ti mismo.', 422
                ),
                'member_not_found' => $this->error(
                    'member_not_found', 'No encontramos a esa persona.', 404
                ),
                default => $this->error('block_failed', 'No pudimos completar el bloqueo.', 400),
            };
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'blocked_member_id' => $memberId,
                'created' => $result['created'],
                'message' => 'Listo. Ya no verán el contenido del otro.',
            ],
        ], $result['created'] ? 201 : 200);
    }

    /** DELETE /api/app/members/{member}/block */
    public function unblock(Request $request, int $memberId): JsonResponse
    {
        /** @var Member $actor */
        $actor = $request->attributes->get('auth_member');

        $removed = $this->blocks->unblock($actor, $memberId, $request);

        return response()->json([
            'ok' => true,
            'data' => [
                'blocked_member_id' => $memberId,
                'removed' => $removed,
            ],
        ]);
    }

    /**
     * GET /api/app/moderation/blocked-members
     *
     * Solo a quien YO bloqueé. Nunca quién me bloqueó a mí: revelarlo abriría
     * una vía de acoso por otro canal.
     */
    public function blockedMembers(Request $request): JsonResponse
    {
        /** @var Member $actor */
        $actor = $request->attributes->get('auth_member');

        $perPage = min(50, max(5, (int) $request->query('per_page', 20)));
        $page = $this->blocks->listBlockedBy($actor, $perPage);

        $memberIds = collect($page->items())->pluck('blocked_member_id')->all();
        $members = Member::whereIn('id', $memberIds)
            ->get(['id', 'full_name', 'profile_photo_url', 'profile_photo_path'])
            ->keyBy('id');

        $items = collect($page->items())->map(function (UserBlock $block) use ($members) {
            $target = $members[$block->blocked_member_id] ?? null;
            $name = trim((string) ($target->full_name ?? ''));

            return [
                'member_id' => (int) $block->blocked_member_id,
                'name' => $name !== '' ? $name : 'Miembro Iron Body',
                'avatar_url' => $this->photoUrl($target),
                'blocked_at' => $block->created_at?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'data' => [
                'items' => $items,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ],
        ]);
    }

    // ── Estado de moderación ──────────────────────────────────────────────

    /**
     * GET /api/app/moderation/status
     *
     * Fuente de verdad de "¿qué puedo hacer?". La app la consulta al iniciar
     * sesión y al refrescar; además cada acción se revalida en el servidor.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        return response()->json([
            'ok' => true,
            'data' => $this->suspensions->statusFor($member),
        ]);
    }

    /**
     * GET /api/app/moderation/actions
     *
     * Historial de medidas aplicadas a MI cuenta, con motivo público y estado
     * de apelación. Nunca notas internas, ni el caso, ni el reportante.
     */
    public function actions(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        $actions = ModerationAction::query()
            ->where('target_member_id', $member->id)
            ->with(['appeals' => fn ($q) => $q->orderByDesc('submitted_at')])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $data = $actions->map(function (ModerationAction $action) {
            $latestAppeal = $action->appeals->first();

            return [
                'id' => $action->public_id,
                'type' => $action->action_type,
                'type_label' => ActionType::label($action->action_type),
                'scope' => $action->scope,
                'scope_label' => $action->scopeLabel(),
                'explanation' => ActionType::createsSuspension($action->action_type)
                    ? ModerationScope::memberExplanation($action->scope)
                    : null,
                // Motivo PÚBLICO. `internal_notes` está en $hidden del modelo.
                'public_reason' => $action->reason,
                'starts_at' => $action->starts_at?->toIso8601String(),
                'ends_at' => $action->ends_at?->toIso8601String(),
                'is_permanent' => $action->isPermanent(),
                'is_revoked' => $action->isRevoked(),
                // Apelable solo si no hay ninguna apelación previa: ni abierta
                // (ya está en revisión) ni resuelta (la decisión es definitiva).
                'can_appeal' => (bool) config('ugc.appeals_enabled', true)
                    && $action->isAppealable()
                    && $latestAppeal === null,
                'appeal' => $latestAppeal ? [
                    'id' => $latestAppeal->public_id,
                    'status' => $latestAppeal->status,
                    'status_label' => $latestAppeal->statusLabel(),
                    'submitted_at' => $latestAppeal->submitted_at?->toIso8601String(),
                    'resolved_at' => $latestAppeal->resolved_at?->toIso8601String(),
                    // Solo el mensaje público; `resolution_notes` es interno.
                    'public_resolution' => $latestAppeal->public_resolution,
                ] : null,
            ];
        })->values();

        return response()->json(['ok' => true, 'data' => $data]);
    }

    // ── Apelaciones ───────────────────────────────────────────────────────

    /** POST /api/app/moderation/actions/{action}/appeal */
    public function appeal(SubmitAppealRequest $request, string $action): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        try {
            $appeal = $this->appeals->submit(
                member: $member,
                actionPublicId: $action,
                text: (string) $request->validated('appeal_text'),
                request: $request,
            );
        } catch (RuntimeException $e) {
            return match ($e->getMessage()) {
                'appeals_disabled' => $this->error(
                    'appeals_disabled', 'Las apelaciones no están disponibles ahora.', 503
                ),
                // 404 también cuando la acción es de otro miembro: no
                // confirmamos la existencia de recursos ajenos (anti-IDOR).
                'action_not_found' => $this->error(
                    'action_not_found', 'No encontramos esa medida en tu cuenta.', 404
                ),
                'not_appealable' => $this->error(
                    'not_appealable', 'Esta medida no admite apelación.', 422
                ),
                'appeal_already_open' => $this->error(
                    'appeal_already_open', 'Ya tienes una apelación en revisión para esta medida.', 409
                ),
                'appeal_already_resolved' => $this->error(
                    'appeal_already_resolved',
                    'Ya revisamos una apelación sobre esta medida. La decisión es definitiva.',
                    409,
                ),
                'rate_limited' => $this->error(
                    'rate_limited', 'Has enviado demasiadas apelaciones. Intenta más tarde.', 429
                ),
                default => $this->error('appeal_failed', 'No pudimos registrar tu apelación.', 400),
            };
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'appeal_id' => $appeal->public_id,
                'status' => $appeal->status,
                'message' => 'Recibimos tu apelación. Un moderador la revisará.',
            ],
        ], 201);
    }

    // ── Lineamientos de comunidad ─────────────────────────────────────────

    /**
     * POST /api/app/moderation/guidelines/accept
     *
     * Aceptación versionada y SEPARADA del contrato de membresía. Solo es
     * requisito para publicar; nunca bloquea rutinas, nutrición ni clases.
     */
    public function acceptGuidelines(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        $data = $request->validate([
            'version' => 'nullable|string|max:24',
            'platform' => 'nullable|string|max:24',
            'app_version' => 'nullable|string|max:24',
        ]);

        $current = (string) config('ugc.guidelines_version');
        $version = $data['version'] ?? $current;

        // No se acepta una versión arbitraria enviada por el cliente: solo la
        // vigente. Evita "aceptar" una versión futura para saltarse un cambio.
        if ($version !== $current) {
            return $this->error('version_mismatch',
                'Los lineamientos se actualizaron. Vuelve a intentarlo.', 409);
        }

        $consent = MemberUgcConsent::firstOrCreate(
            [
                'member_id' => $member->id,
                'community_guidelines_version' => $current,
            ],
            [
                'accepted_at' => now(),
                'platform' => $data['platform'] ?? null,
                'app_version' => $data['app_version'] ?? null,
            ],
        );

        if ($consent->wasRecentlyCreated) {
            $this->audit->member(
                (int) $member->id,
                ModerationAuditLog::ACTION_GUIDELINES_ACCEPTED,
                'member_ugc_consent',
                (int) $consent->id,
                ['version' => $current],
                $request,
            );
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'version' => $current,
                'accepted_at' => $consent->accepted_at?->toIso8601String(),
            ],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function mapReportError(string $code): JsonResponse
    {
        return match ($code) {
            'reports_disabled' => $this->error(
                'reports_disabled', 'Los reportes no están disponibles ahora.', 503
            ),
            'content_not_found' => $this->error(
                'content_not_found', 'Ese contenido ya no existe.', 404
            ),
            'member_not_found' => $this->error(
                'member_not_found', 'No encontramos a esa persona.', 404
            ),
            'cannot_report_own_content' => $this->error(
                'cannot_report_own_content', 'No puedes reportar tu propio contenido.', 422
            ),
            'invalid_reason' => $this->error(
                'invalid_reason', 'El motivo seleccionado no es válido.', 422
            ),
            'rate_limited' => $this->error(
                'rate_limited', 'Has enviado demasiados reportes. Intenta más tarde.', 429
            ),
            default => $this->error('report_failed', 'No pudimos registrar tu reporte.', 400),
        };
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $code,
            'message' => $message,
        ], $status);
    }

    /**
     * URL de la foto de perfil si existe. Prefiere la URL ya resuelta que
     * guarda `members` (Firebase) y cae a la ruta del disco público para las
     * cuentas antiguas. Nunca devuelve la ruta interna de almacenamiento.
     */
    private function photoUrl(?Member $member): ?string
    {
        $url = $member?->profile_photo_url;
        if (is_string($url) && $url !== '') {
            return $url;
        }

        $path = $member?->profile_photo_path;

        return is_string($path) && $path !== ''
            ? Storage::disk('public')->url($path)
            : null;
    }
}
