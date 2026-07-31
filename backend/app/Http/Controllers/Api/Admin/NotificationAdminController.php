<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MemberDeviceToken;
use App\Models\NotificationCampaign;
use App\Models\NotificationDispatch;
use App\Models\NotificationTemplate;
use App\Services\Notifications\CampaignService;
use App\Support\Notifications\NotificationCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Administración de notificaciones desde el CRM.
 *
 * El envío masivo está deliberadamente incómodo: crear una campaña nunca envía,
 * y enviarla exige repetir el número exacto de destinatarios cuando la audiencia
 * es grande. Un clic de más aquí llega a los teléfonos de socios reales y no se
 * puede deshacer.
 */
class NotificationAdminController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns) {}

    // ── Plantillas ───────────────────────────────────────────────────────────

    public function templates(Request $request): JsonResponse
    {
        $query = NotificationTemplate::query();

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        return response()->json([
            'data' => $query->orderBy('category')->orderBy('key')->get()->map(fn (NotificationTemplate $t) => [
                'id' => $t->id,
                'key' => $t->key,
                'category' => $t->category,
                'category_label' => NotificationCategory::label($t->category),
                'supplement_kind' => $t->supplement_kind,
                'supplement_label' => $t->supplement_kind
                    ? NotificationCategory::supplementLabel($t->supplement_kind)
                    : null,
                'title' => $t->title,
                'body' => $t->body,
                'disclaimer' => $t->disclaimer,
                'preview' => $t->renderedBody(),
                'version' => $t->version,
                'is_active' => $t->is_active,
                'is_seeded' => $t->is_seeded,
            ]),
        ]);
    }

    public function updateTemplate(Request $request, NotificationTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:60'],
            'body' => ['sometimes', 'string', 'max:180'],
            'disclaimer' => ['sometimes', 'nullable', 'string', 'max:200'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Editar el texto sube la versión: el historial de envíos conserva con
        // qué redacción salió cada aviso, así corregir hoy no reescribe el ayer.
        if (array_key_exists('title', $data) || array_key_exists('body', $data)) {
            $data['version'] = ((int) $template->version) + 1;
        }

        $template->update($data);

        return response()->json(['data' => $template->fresh()]);
    }

    // ── Campañas ─────────────────────────────────────────────────────────────

    public function campaigns(): JsonResponse
    {
        return response()->json([
            'data' => NotificationCampaign::query()->latest('id')->limit(100)->get(),
        ]);
    }

    /** Crea SIEMPRE un borrador. No existe camino que cree y envíe a la vez. */
    public function createCampaign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string'],
            'title' => ['required', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:180'],
            'action_route' => ['nullable', 'string', 'max:190'],
            'audience' => ['nullable', 'array'],
            'audience.member_ids' => ['nullable', 'array'],
            'audience.inactive_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        if (! NotificationCategory::isValid($data['category'])) {
            return response()->json(['message' => 'Categoría desconocida.', 'code' => 'invalid_category'], 422);
        }

        $campaign = NotificationCampaign::create($data + [
            'status' => NotificationCampaign::STATUS_DRAFT,
            'created_by' => $this->actor($request),
        ]);

        $campaign->estimated_recipients = $this->campaigns->estimate($campaign);

        return response()->json(['data' => $campaign->fresh()], 201);
    }

    public function updateCampaign(Request $request, NotificationCampaign $campaign): JsonResponse
    {
        if (! $campaign->isSendable()) {
            return response()->json([
                'message' => 'Una campaña que ya salió no se puede editar.',
                'code' => 'campaign_not_editable',
            ], 422);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'title' => ['sometimes', 'string', 'max:60'],
            'body' => ['sometimes', 'string', 'max:180'],
            'action_route' => ['sometimes', 'nullable', 'string', 'max:190'],
            'audience' => ['sometimes', 'nullable', 'array'],
        ]);

        $campaign->update($data);
        $this->campaigns->estimate($campaign);

        return response()->json(['data' => $campaign->fresh()]);
    }

    /** Recuento en vivo, con la MISMA consulta que usaría el envío. */
    public function estimateCampaign(NotificationCampaign $campaign): JsonResponse
    {
        $count = $this->campaigns->estimate($campaign);

        return response()->json([
            'data' => [
                'estimated_recipients' => $count,
                'requires_confirmation' => $count >= (int) config('notifications.campaigns.large_audience_threshold', 50),
                'threshold' => (int) config('notifications.campaigns.large_audience_threshold', 50),
            ],
        ]);
    }

    /**
     * Envía. Con audiencia grande exige que el CRM repita el número exacto:
     * un `confirm_recipients` que no coincide es la señal de que quien pulsa no
     * ha visto a cuánta gente va, y entonces no sale nada.
     */
    public function sendCampaign(Request $request, NotificationCampaign $campaign): JsonResponse
    {
        $request->validate(['confirm_recipients' => ['nullable', 'integer']]);

        $count = $this->campaigns->estimate($campaign);
        $threshold = (int) config('notifications.campaigns.large_audience_threshold', 50);

        if ($count >= $threshold && (int) $request->input('confirm_recipients') !== $count) {
            return response()->json([
                'message' => "Esta campaña llegaría a {$count} socios. Confirma ese número exacto para enviarla.",
                'code' => 'confirmation_required',
                'estimated_recipients' => $count,
            ], 422);
        }

        if ($count === 0) {
            return response()->json([
                'message' => 'La audiencia está vacía: no hay a quién enviar.',
                'code' => 'empty_audience',
            ], 422);
        }

        try {
            $stats = $this->campaigns->send($campaign, $this->actor($request));
        } catch (RuntimeException $e) {
            return response()->json(['message' => 'No se pudo enviar.', 'code' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $campaign->fresh(), 'stats' => $stats]);
    }

    public function cancelCampaign(Request $request, NotificationCampaign $campaign): JsonResponse
    {
        try {
            $this->campaigns->cancel($campaign, $this->actor($request));
        } catch (RuntimeException $e) {
            return response()->json(['message' => 'No se pudo cancelar.', 'code' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $campaign->fresh()]);
    }

    // ── Métricas ─────────────────────────────────────────────────────────────

    /**
     * Qué salió, qué no y por qué. Los motivos de supresión son la parte útil:
     * si "quiet_hours" domina, el horario está mal elegido, no roto.
     */
    public function metrics(): JsonResponse
    {
        $since = now()->subDays(30);

        $porEstado = NotificationDispatch::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $porMotivo = NotificationDispatch::query()
            ->where('created_at', '>=', $since)
            ->where('status', NotificationDispatch::STATUS_SUPPRESSED)
            ->selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason');

        $porCategoria = NotificationDispatch::query()
            ->where('created_at', '>=', $since)
            ->sent()
            ->selectRaw('category, count(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category');

        return response()->json([
            'data' => [
                'window_days' => 30,
                'by_status' => $porEstado,
                'suppression_reasons' => $porMotivo,
                'sent_by_category' => $porCategoria,
                'active_devices' => MemberDeviceToken::query()->where('is_active', true)->count(),
                'inactive_devices' => MemberDeviceToken::query()->where('is_active', false)->count(),
            ],
        ]);
    }

    private function actor(Request $request): string
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin?->email ?? 'admin';
    }
}
