<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Marketing\Analytics\CampaignAnalyticsService;
use App\Services\Marketing\Analytics\CommercialInsightsService;
use App\Services\Marketing\MarketingInboxAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Analítica comercial de pautas. **Solo lectura.**
 *
 * Varios endpoints pequeños en vez de uno que devuelva todo. Un único endpoint
 * gigante obliga a calcularlo entero aunque la pantalla solo enseñe el resumen,
 * y hace imposible cachear o medir por partes: cuando va lento no se sabe qué
 * parte va lenta.
 *
 * Nada de esto expone el payload crudo del canal, ni identificadores de clic,
 * ni teléfonos, ni nombres. Son cifras agregadas: para hablar con una persona
 * está el Inbox, y ahí los permisos son otros.
 */
class MarketingAnalyticsController extends Controller
{
    /** Techo del rango consultable, en días. */
    private const MAX_RANGE_DAYS = 400;

    public function __construct(
        private readonly CampaignAnalyticsService $analytics,
        private readonly MarketingInboxAuthorizationService $authz,
    ) {}

    // ── Endpoints ───────────────────────────────────────────────────────────

    public function summary(Request $request): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        [$from, $to, $filters] = $this->inputs($request);

        return response()->json(['ok' => true, 'data' => array_merge(
            $this->analytics->summary($from, $to, $filters),
            ['revenue_categories' => $this->analytics->revenueCategories($from, $to, $filters)],
        )]);
    }

    public function funnel(Request $request): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        [$from, $to, $filters] = $this->inputs($request);
        $summary = $this->analytics->summary($from, $to, $filters);

        return response()->json([
            'ok' => true,
            'data' => ['funnel' => $summary['funnel'], 'rates' => $summary['rates']],
        ]);
    }

    /**
     * Desglose por una dimensión, ordenable y paginado.
     *
     * La paginación no es cosmética: con muchos anuncios activos, devolver la
     * tabla entera son cientos de filas que el navegador tiene que ordenar y
     * pintar para enseñar veinte.
     */
    public function breakdown(Request $request, string $dimension): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        if (! in_array($dimension, CampaignAnalyticsService::DIMENSIONS, true)) {
            return response()->json(['ok' => false, 'code' => 'unknown_dimension'], 422);
        }

        $data = $request->validate([
            'sort' => ['nullable', 'string', Rule::in([
                'revenue', 'leads', 'conversations', 'sales', 'conversion_rate',
                'average_ticket', 'renewals', 'upgrades', 'qualified_leads',
            ])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        [$from, $to, $filters, $touch] = $this->inputs($request, withTouch: true);

        $rows = collect($this->analytics->breakdown($dimension, $from, $to, $filters, $touch));

        $sort = $data['sort'] ?? 'revenue';
        $rows = ($data['direction'] ?? 'desc') === 'asc'
            ? $rows->sortBy(fn ($r) => $r[$sort] ?? 0)
            : $rows->sortByDesc(fn ($r) => $r[$sort] ?? 0);

        $perPage = (int) ($data['per_page'] ?? 25);
        $page = (int) ($data['page'] ?? 1);
        $total = $rows->count();

        return response()->json([
            'ok' => true,
            'data' => $rows->values()->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'meta' => [
                'dimension' => $dimension,
                'attribution_model' => $touch === 'last' ? 'last_touch' : 'first_touch',
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    /** Todo lo de una campaña: anuncios, creatividades, tiempos, objeciones. */
    public function campaign(Request $request, string $campaign): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        [$from, $to] = $this->inputs($request);

        return response()->json([
            'ok' => true,
            'data' => $this->analytics->campaignDetail($campaign, $from, $to),
        ]);
    }

    /**
     * Qué tan de fiar son los números de arriba.
     *
     * Endpoint propio porque se lee SIEMPRE junto al resumen: sin esto, «esta
     * campaña trajo el 40 % de las ventas» significa una cosa con el 10 % sin
     * atribuir y otra muy distinta con el 70 %.
     */
    public function quality(Request $request): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        [$from, $to] = $this->inputs($request);

        return response()->json([
            'ok' => true,
            'data' => $this->analytics->attributionQuality($from, $to),
        ]);
    }

    public function insights(Request $request, CommercialInsightsService $insights): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        [$from, $to] = $this->inputs($request);

        return response()->json([
            'ok' => true,
            'data' => $insights->forPeriod($from, $to),
            // Se dice en la respuesta, no solo en la documentación: nada de
            // esto cambia una campaña, un precio ni una promoción.
            'meta' => ['read_only' => true, 'computed_by' => 'rules'],
        ]);
    }

    // ── Entrada ─────────────────────────────────────────────────────────────

    /**
     * Rango, filtros y modelo de atribución, ya validados.
     *
     * El rango se acota a 400 días. No es una limitación de producto: es que
     * una consulta sin techo la puede lanzar cualquiera con sesión y acabar
     * agregando años de pagos en cada recarga del panel.
     *
     * @return array{0:Carbon,1:Carbon,2:array<string,mixed>,3?:string}
     */
    private function inputs(Request $request, bool $withTouch = false): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:40'],
            'platform' => ['nullable', 'string', 'max:40'],
            'campaign_id' => ['nullable', 'string', 'max:120'],
            'adset_id' => ['nullable', 'string', 'max:120'],
            'ad_id' => ['nullable', 'string', 'max:120'],
            'advertised_product' => ['nullable', 'string', 'max:120'],
            'attribution_model' => ['nullable', Rule::in(['first_touch', 'last_touch'])],
        ]);

        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(30);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->copy()->subDays(self::MAX_RANGE_DAYS);
        }

        $filters = array_filter([
            'source_type' => $data['source'] ?? null,
            'platform' => $data['platform'] ?? null,
            'campaign' => $data['campaign_id'] ?? null,
            'adset' => $data['adset_id'] ?? null,
            'ad' => $data['ad_id'] ?? null,
            'advertised_product' => $data['advertised_product'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $result = [$from, $to, $filters];

        if ($withTouch) {
            $result[] = ($data['attribution_model'] ?? 'first_touch') === 'last_touch' ? 'last' : 'first';
        }

        return $result;
    }

    /**
     * Ver la facturación por campaña exige visión COMPLETA.
     *
     * No basta con `view_metrics`. Ese permiso lo tienen también los roles
     * comerciales —recepción incluida—, y con razón: cubre las métricas
     * operativas del inbox, cuántas conversaciones hay abiertas y cuántas sin
     * leer. Esto es otra cosa. Aquí se ve cuánto factura el gimnasio, qué
     * ticket tiene y de dónde sale el dinero, y eso no es información que
     * necesite quien atiende para atender.
     *
     * Es una puerta más estrecha a propósito, y se deja dicho porque no es lo
     * que se esperaría leyendo solo el nombre del permiso.
     */
    private function guard(Request $request): ?JsonResponse
    {
        $admin = $request->attributes->get('auth_admin');

        if (! $admin instanceof Admin) {
            return response()->json([
                'ok' => false,
                'code' => 'requires_admin',
                'message' => 'La analítica requiere una sesión de administrador.',
            ], 401);
        }

        if (! $this->authz->isFull($admin)) {
            return response()->json([
                'ok' => false,
                'code' => 'analytics_forbidden',
                'message' => 'Tu rol no tiene acceso a la analítica comercial.',
            ], 403);
        }

        return null;
    }
}
