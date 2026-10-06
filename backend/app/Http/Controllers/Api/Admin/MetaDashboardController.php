<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\MetaAdEntity;
use App\Services\Marketing\Attribution\MetaDashboardService;
use App\Services\Marketing\MarketingInboxAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;

/**
 * El panel «Mercadeo digital (Meta)» con gasto real, atribución y conversiones.
 *
 * Mismo blindaje y mismo permiso que `admin/marketing/overview`: el grupo `api`
 * exige la sesión (ProtectAdminPaths) y AuthorizationMap pide `marketing.view`
 * para leer y `marketing.manage` para pedir una sincronización. `/overview`
 * sigue como estaba; esto convive con él.
 *
 * EL DINERO es otra puerta, la misma que /admin/marketing/analytics: los
 * ingresos, el ROAS y el CAC solo salen para quien tiene visión COMPLETA
 * ({@see MarketingInboxAuthorizationService::isFull()}). `marketing.view` lo
 * pueden tener roles comerciales para atender el Inbox, y eso no les da derecho
 * a ver lo que factura el gimnasio. A los demás, y al token compartido de
 * automatizaciones, los importes les llegan en null con el motivo `forbidden`;
 * los conteos sí.
 *
 * Las cifras las calcula {@see MetaDashboardService}; aquí solo se valida la
 * entrada, se decide quién ve dinero y se da forma a la respuesta.
 */
class MetaDashboardController extends Controller
{
    /** Peticiones de sincronización por minuto y por administrador. */
    public const SYNC_PER_MINUTE = 6;

    public function __construct(
        private readonly MetaDashboardService $dashboard,
        private readonly MarketingInboxAuthorizationService $authz,
    ) {}

    /** GET /api/admin/marketing/meta-dashboard?period=&from=&to= */
    public function show(Request $request): JsonResponse
    {
        $period = $this->period($request);
        if ($period instanceof JsonResponse) {
            return $period;
        }

        return response()->json(['ok' => true, 'data' => $this->dashboard->dashboard($period, $this->moneyVisible($request))]);
    }

    /**
     * GET /api/admin/marketing/meta-dashboard/campaigns?level=campaign|adset|ad&period=&parent=&page=&per_page=
     *
     * Sin `parent`, la tabla entera del nivel, como antes. Con `parent` (la clave
     * opaca de una fila), el desglose perezoso: los conjuntos de una campaña o
     * los anuncios de un conjunto, paginados (máximo 50 por página).
     */
    public function campaigns(Request $request): JsonResponse
    {
        $level = $request->query('level');
        if ($level === null || $level === '') {
            $level = MetaAdEntity::LEVEL_CAMPAIGN;
        }
        if (! is_string($level) || ! in_array($level, MetaAdEntity::LEVELS, true)) {
            return $this->invalid('invalid_level', 'Nivel desconocido: usa campaign, adset o ad.');
        }

        $parent = $request->query('parent');
        if ($parent !== null && $parent !== '') {
            $expected = match ($level) {
                MetaAdEntity::LEVEL_ADSET => MetaAdEntity::LEVEL_CAMPAIGN,
                MetaAdEntity::LEVEL_AD => MetaAdEntity::LEVEL_ADSET,
                default => null,
            };
            if ($expected === null || ! is_string($parent) || preg_match('/^'.$expected.':[a-f0-9]{16}\z/', $parent) !== 1) {
                return $this->invalid('invalid_parent', 'El padre del desglose no es válido: los conjuntos se piden con una campaña y los anuncios con un conjunto.');
            }
        } else {
            $parent = null;
        }

        [$page, $perPage] = $parent === null ? [1, PHP_INT_MAX] : $this->paging($request, MetaDashboardService::CHILDREN_PER_PAGE);
        if ($page === null) {
            return $this->invalid('invalid_page', 'page y per_page tienen que ser enteros positivos (page hasta '.MetaDashboardService::MAX_PAGE.').');
        }

        $period = $this->period($request);
        if ($period instanceof JsonResponse) {
            return $period;
        }

        $moneyVisible = $this->moneyVisible($request);
        // Sin padre, la tabla entera (como antes); con padre, páginas de hasta 50.
        $table = $this->dashboard->campaignTable(
            $level, $period['start'], $period['end'], $moneyVisible, $parent, $page,
            $parent === null ? PHP_INT_MAX : min($perPage, MetaDashboardService::MAX_PER_PAGE),
        );

        return response()->json(['ok' => true, 'data' => [
            'level' => $level,
            'rows' => $table['rows'],
            'money_visible' => $moneyVisible,
            'partial' => $table['partial'],
            'covered_to' => $table['covered_to'],
            'meta' => $table['meta'],
        ]]);
    }

    /** GET /api/admin/marketing/meta-dashboard/leads?period=&page=&per_page=&origin= (máximo 50; página hasta 10000) */
    public function leads(Request $request): JsonResponse
    {
        [$page, $perPage] = $this->paging($request, 20);
        if ($page === null) {
            return $this->invalid('invalid_page', 'page y per_page tienen que ser enteros positivos (page hasta '.MetaDashboardService::MAX_PAGE.').');
        }

        $origin = $request->query('origin');
        if ($origin === '') {
            $origin = null;
        }
        if ($origin !== null && (! is_string($origin) || ! in_array($origin, MetaDashboardService::ORIGIN_ORDER, true))) {
            return $this->invalid('invalid_origin', 'Origen desconocido.');
        }

        $period = $this->period($request);
        if ($period instanceof JsonResponse) {
            return $period;
        }

        $moneyVisible = $this->moneyVisible($request);
        $result = $this->dashboard->leads(
            $period['start'], $period['end'], $page, min($perPage, MetaDashboardService::MAX_PER_PAGE), $moneyVisible, $origin,
        );

        return response()->json(['ok' => true, 'data' => $result['data'], 'meta' => [
            'current_page' => $result['meta']['current_page'],
            'last_page' => $result['meta']['last_page'],
            'per_page' => $result['meta']['per_page'],
            'total' => $result['meta']['total'],
            'money_visible' => $moneyVisible,
            'origin' => $origin,
        ]]);
    }

    /**
     * `page` y `per_page` de la query, o [null, null] si no valen. Antes de
     * calcular nada: una página absurda no puede costar el periodo entero, ni
     * desbordar la cuenta del desplazamiento.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function paging(Request $request, int $defaultPerPage): array
    {
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT);
        $perPage = filter_var($request->query('per_page', $defaultPerPage), FILTER_VALIDATE_INT);

        if ($page === false || $page < 1 || $perPage === false || $perPage < 1 || $page > MetaDashboardService::MAX_PAGE) {
            return [null, null];
        }

        return [$page, $perPage];
    }

    /**
     * POST /api/admin/marketing/meta-dashboard/sync
     *
     * Siempre 202 si se atendió: `status` dice qué pasó (`queued`, `running`,
     * `cooldown` o `not_configured`). El front recarga sin esperar.
     *
     * El límite de frecuencia va AQUÍ, después de autenticar y autorizar, y su
     * clave es el administrador: un throttle de ruta corre antes de la sesión y
     * cuenta por IP, así que las peticiones anónimas desde la misma red (el
     * wifi del gimnasio) gastaban el cupo del personal. El límite que protege la
     * cuota de Meta es otro, el cooldown global de la sincronización.
     */
    public function sync(Request $request): JsonResponse
    {
        $key = 'meta-dashboard-sync:'.$this->actorKey($request);

        if (RateLimiter::tooManyAttempts($key, self::SYNC_PER_MINUTE)) {
            return response()->json([
                'ok' => false,
                'code' => 'too_many_requests',
                'message' => 'Demasiadas peticiones de sincronización seguidas. Espera un minuto.',
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        return response()->json(['ok' => true, 'data' => $this->dashboard->requestSync()], 202);
    }

    /** ¿Puede este usuario ver dinero? Solo con visión completa, como la analítica. */
    private function moneyVisible(Request $request): bool
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin && $this->authz->isFull($admin);
    }

    /** Quién pide: el administrador de la sesión. Sin él (no debería llegar aquí), la IP. */
    private function actorKey(Request $request): string
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? 'admin:'.$admin->getKey() : 'ip:'.$request->ip();
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function period(Request $request): array|JsonResponse
    {
        foreach (['period', 'from', 'to'] as $field) {
            if ($request->query($field) !== null && ! is_string($request->query($field))) {
                return $this->invalid('invalid_period', 'El periodo no tiene un formato válido.');
            }
        }

        try {
            return $this->dashboard->period(
                $request->query('period'),
                $request->query('from'),
                $request->query('to'),
            );
        } catch (InvalidArgumentException $e) {
            // El mensaje es nuestro y no lleva datos de nadie.
            return $this->invalid('invalid_period', $e->getMessage());
        }
    }

    private function invalid(string $code, string $message): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => $code, 'message' => $message], 422);
    }
}
