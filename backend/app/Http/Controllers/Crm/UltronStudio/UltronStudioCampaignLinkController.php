<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\UltronStudio\StudioCampaignLink;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioCampaignLinks;
use App\Services\UltronStudio\StudioCanaryPhone;
use App\Services\UltronStudio\StudioMetaCampaigns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ULTRON STUDIO: campañas de Meta y su asociación con ULTRON (contrato H6 §I3,
 * rutas 40, 41, 47 y 48). Ver {@see StudioCampaignLinks} para las puertas y la
 * sustitución, y {@see UltronStudioController} para las dos puertas comunes.
 *
 * Meta se lee del ESPEJO local (sin red). La asociación es REGISTRO del Studio:
 * no cambia nada en Meta ni en lo que ULTRON dice o hace. La API acepta y
 * devuelve solo ids opacos de Meta (`campaign:` y `account:` + 16 hex): el id
 * de Meta no sale del servidor. Sin route-model binding: el uuid es texto.
 *
 * Orden (D-13 del H5): la forma (422) antes que las llaves (409).
 */
class UltronStudioCampaignLinkController extends Controller
{
    use ResolvesPagination;

    private const CAMPAIGN_ID = '/^campaign:[0-9a-f]{16}$/D';

    private const ACCOUNT_ID = '/^account:[0-9a-f]{16}$/D';

    /** GET /api/admin/ultron-studio/integrations/meta/campaigns?status&linked&account&page&per_page (≤ 50) */
    public function campaigns(Request $request, StudioCampaignLinks $links): JsonResponse
    {
        StudioAccess::requirePerson($request);
        $data = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::in(StudioMetaCampaigns::STATUS_FILTERS)],
            'linked' => ['sometimes', 'nullable', 'string', Rule::in(['yes', 'no'])],
            'account' => ['sometimes', 'nullable', 'string', 'regex:'.self::ACCOUNT_ID],
            ...self::pagination(),
            // AS-2 (Enmienda 3): esta lista se pagina en PHP; `page` lleva tope (nunca 500).
            'page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.StudioCampaignLinks::CAMPAIGNS_PAGE_MAX],
        ]);

        return response()->json(['ok' => true, 'data' => $links->campaignsPage(
            ['status' => $data['status'] ?? null, 'linked' => $data['linked'] ?? null, 'account' => $data['account'] ?? null],
            max(1, (int) ($data['page'] ?? 1)),
            $this->resolvePerPage($request, 20, StudioCampaignLinks::PER_PAGE_MAX),
        )]);
    }

    /** GET /api/admin/ultron-studio/integrations/meta/links?campaign&status&page&per_page (≤ 50), de la más reciente a la más antigua */
    public function links(Request $request, StudioCampaignLinks $links): JsonResponse
    {
        StudioAccess::requirePerson($request);
        $data = $request->validate([
            'campaign' => ['sometimes', 'nullable', 'string', 'regex:'.self::CAMPAIGN_ID],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(StudioCampaignLink::STATUSES)],
            ...self::pagination(),
        ]);

        return response()->json(['ok' => true, 'data' => $links->linksPage(
            ['campaign' => $data['campaign'] ?? null, 'status' => $data['status'] ?? null],
            $this->resolvePerPage($request, 20, StudioCampaignLinks::PER_PAGE_MAX),
        )]);
    }

    /**
     * POST /api/admin/ultron-studio/integrations/meta/links
     *   {clientRequestId, campaign, target:{kind, versionUuid?}, replaces?}
     *
     * 201 `{replayed:false, link, replaced}`; 200 `{replayed:true, …}` con la de
     * la primera vez si la petición se repite.
     */
    public function store(Request $request, StudioCampaignLinks $links): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'campaign' => ['required', 'string', 'regex:'.self::CAMPAIGN_ID],
            'target' => ['required', 'array'],
            'target.kind' => ['required', 'string', Rule::in(StudioCampaignLink::TARGET_KINDS)],
            'target.versionUuid' => ['required_if:target.kind,version', 'prohibited_unless:target.kind,version', 'uuid'],
            'replaces' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $resultado = $links->create(
            $access,
            $request,
            strtolower($data['clientRequestId']),
            $data['campaign'],
            ['kind' => $data['target']['kind'], 'versionUuid' => $data['target']['versionUuid'] ?? null],
            $data['replaces'] ?? null,
        );

        return response()->json(['ok' => true, 'data' => $resultado], $resultado['replayed'] ? 200 : 201);
    }

    /** POST /api/admin/ultron-studio/integrations/meta/links/{uuid}/deactivate   {reason 5–500} */
    public function deactivate(Request $request, string $uuid, StudioCampaignLinks $links): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            // AS-1 (Enmienda 3): el motivo queda para siempre en la fila y en el libro: sin teléfonos ni correos.
            'reason' => ['required', 'string', 'min:'.StudioCampaignLinks::REASON_MIN, 'max:'.StudioCampaignLinks::REASON_MAX, new PlainText(multiline: true, strict: true), StudioCanaryPhone::contactDataRule()],
        ]);

        return response()->json(['ok' => true, 'data' => $links->deactivate($access, $request, $uuid, $data['reason'])]);
    }

    /** @return array<string, list<string>> */
    private static function pagination(): array
    {
        return [
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
