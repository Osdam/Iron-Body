<?php

namespace Tests\Feature\Marketing\MetaAds;

use App\Services\Marketing\Meta\MetaAdsSync;
use App\Services\Marketing\Meta\MetaSpendReader;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Un Graph de mentira para las pruebas de Meta Ads.
 *
 * Ninguna prueba sale a la red: todas las peticiones caen en el falso, y una
 * ruta que la prueba no esperaba responde 404, así que si el código pidiera algo
 * de más se notaría como fallo y no pasaría en silencio.
 *
 * El falso se registra UNA vez y lee lo que debe contestar de dos propiedades;
 * volver a llamar a fakeMeta() cambia la respuesta a mitad de prueba. Registrar
 * otro Http::fake no serviría: gana siempre el primero que contesta.
 */
trait FakesMetaAds
{
    /** Token falso, sin forma de token real: sirve para buscarlo donde no debe estar. */
    protected const ADS_TOKEN = 'test-ads-token-NOT-REAL-7f3a9c';

    protected const ACCOUNT = '1234567890';

    protected const GRAPH = 'https://graph.facebook.com/v99.0';

    /** @var array<string,mixed>|callable */
    private mixed $metaInsights = ['data' => []];

    /** @var array<string,mixed>|callable */
    private mixed $metaCampaigns = ['data' => []];

    /** Alcance por rango (`/insights` con level=account|campaign). @var array<string,mixed>|callable */
    private mixed $metaReach = ['data' => []];

    /** Estados de los conjuntos de la cuenta (`GET /act_{id}/adsets`). @var array<string,mixed>|callable */
    private mixed $metaAdsets = ['data' => []];

    /** Estados de los anuncios de la cuenta (`GET /act_{id}/ads`). @var array<string,mixed>|callable */
    private mixed $metaAds = ['data' => []];

    /** Un objeto suelto por id (`GET /{id}`): ids → respuesta, o un callable. @var array<string,mixed>|callable */
    private mixed $metaNodes = [];

    /** La ficha de la cuenta (`GET /act_{id}`). @var array<string,mixed>|callable */
    private mixed $metaAccount = [];

    /** `/me` y `/me/permissions`: de quién es el token y qué tiene concedido. @var array<string,mixed>|callable */
    private mixed $metaMe = ['id' => '100001', 'name' => 'iron-ads-reader'];

    /** @var array<string,mixed>|callable */
    private mixed $metaPermissions = ['data' => [['permission' => 'ads_read', 'status' => 'granted']]];

    private bool $metaFaked = false;

    protected function configureMetaAds(array $overrides = []): void
    {
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.timeout', 5);
        config()->set('meta.ads', array_merge([
            'access_token' => self::ADS_TOKEN,
            'ad_account_id' => self::ACCOUNT,
            'graph_version' => 'v99.0',
            'sync_enabled' => false,
            'sync_days' => 7,
            'stale_after_minutes' => 180,
            'timezone' => 'America/Bogota',
        ], $overrides));
    }

    /**
     * `$insights` contesta a /insights y `$campaigns` a /campaigns. Cada uno es un
     * cuerpo JSON o un callable(Request) que devuelve la respuesta.
     */
    protected function fakeMeta(array|callable $insights, array|callable|null $campaigns = null): void
    {
        $this->metaInsights = $insights;
        $this->metaCampaigns = $campaigns ?? ['data' => []];

        if ($this->metaFaked) {
            return;
        }
        $this->metaFaked = true;

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $query = $this->queryOf($request);

            $responder = match (true) {
                // El alcance de un rango es /insights sin desglose por día ni por anuncio.
                str_ends_with($path, '/insights') && in_array($query['level'] ?? 'ad', ['account', 'campaign'], true) => $this->metaReach,
                str_ends_with($path, '/insights') => $this->metaInsights,
                str_ends_with($path, '/campaigns') => $this->metaCampaigns,
                str_ends_with($path, '/me/permissions') => $this->metaPermissions,
                str_ends_with($path, '/me') => $this->metaMe,
                str_ends_with($path, '/adsets') => $this->metaAdsets,
                str_ends_with($path, '/ads') => $this->metaAds,
                // `?ids=` ya no existe en v26: si algo lo pidiera, la prueba lo vería.
                isset($query['ids']) => fn () => Http::response(['error' => ['message' => 'The ids query parameter is deprecated in v26.0+.', 'code' => 100]], 500),
                preg_match('#/act_\d+$#', $path) === 1 => $this->metaAccount,
                preg_match('#/v[0-9.]+/(\d+)$#', $path, $m) === 1 => is_callable($this->metaNodes)
                    ? $this->metaNodes
                    : ($this->metaNodes[$m[1]] ?? fn () => $this->graphError(100, "Unsupported get request. Object with ID '{$m[1]}' does not exist, cannot be loaded due to missing permissions", 400, ['error_subcode' => 33])),
                default => fn () => Http::response(['error' => ['message' => 'Ruta no fingida en la prueba', 'code' => 100]], 404),
            };

            return is_callable($responder) ? $responder($request) : Http::response($responder);
        });
    }

    /**
     * Respuestas de las aristas nuevas: alcance por rango, estados de conjuntos y
     * anuncios de la cuenta, la ficha de la cuenta, y `/me` y `/me/permissions`.
     * Solo cambia lo que se pasa.
     */
    protected function fakeMetaExtras(
        array|callable|null $reach = null,
        array|callable|null $adsets = null,
        array|callable|null $ads = null,
        array|callable|null $nodes = null,
        array|callable|null $account = null,
        array|callable|null $me = null,
        array|callable|null $permissions = null,
    ): void {
        $this->metaReach = $reach ?? $this->metaReach;
        $this->metaAdsets = $adsets ?? $this->metaAdsets;
        $this->metaAds = $ads ?? $this->metaAds;
        $this->metaNodes = $nodes ?? $this->metaNodes;
        $this->metaAccount = $account ?? $this->metaAccount;
        $this->metaMe = $me ?? $this->metaMe;
        $this->metaPermissions = $permissions ?? $this->metaPermissions;
    }

    /** @return list<Request> las peticiones de gasto por anuncio y día (no las de alcance) */
    protected function dailyInsightRequests(): array
    {
        return array_values(array_filter(
            $this->requestsTo('insights'),
            fn (Request $r): bool => ($this->queryOf($r)['level'] ?? null) === 'ad',
        ));
    }

    /** Una fila de /insights tal como la da Meta: todo en texto. */
    protected function insightRow(string $date, string $adId, string $spend, array $extra = []): array
    {
        return array_merge([
            'date_start' => $date,
            'date_stop' => $date,
            'campaign_id' => '120201',
            'campaign_name' => 'Campaña Octubre',
            'adset_id' => '120211',
            'adset_name' => 'Neiva 18-35',
            'ad_id' => $adId,
            'ad_name' => 'Video promo '.$adId,
            'spend' => $spend,
            'impressions' => '1000',
            'reach' => '800',
            'clicks' => '25',
            'account_currency' => 'COP',
        ], $extra);
    }

    /**
     * Un borde de estados (`/campaigns`, `/adsets`, `/ads`) que contesta como Meta:
     * solo los objetos cuyo estado se pidió en `effective_status`, y 400 100/1815001
     * si se piden eliminados (comprobado con la cuenta real).
     *
     * @param  list<array{id: string, effective_status: string}>  $objetos
     */
    protected function bordeComoMeta(array $objetos): callable
    {
        return function (Request $r) use ($objetos) {
            $pedidos = json_decode($this->queryOf($r)['effective_status'] ?? '[]', true) ?: [];
            if (in_array('DELETED', $pedidos, true)) {
                return $this->graphError(100, 'No se pueden solicitar objetos eliminados en este extremo.', 400, ['error_subcode' => 1815001]);
            }

            return Http::response(['data' => array_values(array_filter($objetos, fn (array $o) => in_array($o['effective_status'], $pedidos, true)))]);
        };
    }

    /** Un error de Graph con su forma real. */
    protected function graphError(int $code, string $message, int $status = 400, array $extra = []): PromiseInterface
    {
        return Http::response(['error' => array_merge([
            'message' => $message,
            'type' => 'OAuthException',
            'code' => $code,
            'fbtrace_id' => 'AbCdEf123',
        ], $extra)], $status);
    }

    /** @return array<string,mixed> los parámetros de la query de una petición */
    protected function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /** @return list<Request> las peticiones a una arista, en orden */
    protected function requestsTo(string $edge): array
    {
        return Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $r): bool => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/'.$edge))
            ->values()
            ->all();
    }

    /** Un día de la cuenta (Bogotá), como lo construye el comando. */
    protected function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'America/Bogota')->startOfDay();
    }

    /**
     * Días de Bogotá como rango UTC `[desde, hasta)`, igual que
     * CashReceipts::businessDay: así llega el periodo desde el panel.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function bogotaDays(string $from, string $to): array
    {
        return [
            CarbonImmutable::parse($from, 'America/Bogota')->startOfDay()->utc(),
            CarbonImmutable::parse($to, 'America/Bogota')->startOfDay()->addDay()->utc(),
        ];
    }

    protected function sync(): MetaAdsSync
    {
        return app(MetaAdsSync::class);
    }

    protected function reader(): MetaSpendReader
    {
        return app(MetaSpendReader::class);
    }

    /** @return array<string,mixed> */
    protected function spendFor(string $from, string $to): array
    {
        return $this->reader()->snapshot(...$this->bogotaDays($from, $to));
    }
}
