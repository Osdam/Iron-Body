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

            $responder = match (true) {
                str_ends_with($path, '/insights') => $this->metaInsights,
                str_ends_with($path, '/campaigns') => $this->metaCampaigns,
                default => fn () => Http::response(['error' => ['message' => 'Ruta no fingida en la prueba', 'code' => 100]], 404),
            };

            return is_callable($responder) ? $responder($request) : Http::response($responder);
        });
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
