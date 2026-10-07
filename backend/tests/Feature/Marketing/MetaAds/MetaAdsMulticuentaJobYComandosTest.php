<?php

namespace Tests\Feature\Marketing\MetaAds;

use App\Jobs\SyncMetaAdsInsights;
use App\Models\MetaAdInsightDaily;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Attribution\MetaDashboardService;
use App\Services\Marketing\Meta\MetaAdsApiException;
use App\Services\Marketing\Meta\MetaAdsSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Feature\Marketing\Attribution\SeedsAttribution;
use Tests\TestCase;

/**
 * Meta Ads MULTICUENTA, lado job y comandos: el job es un abanico (uno por
 * cuenta, cada uno con sus reintentos) y los comandos trabajan por cuenta, con
 * `--account` para elegir. Todo de solo lectura contra Meta.
 *
 * Reloj: 5 de octubre de 2026, 10:00 en Bogotá. Ninguna prueba sale a la red.
 */
class MetaAdsMulticuentaJobYComandosTest extends TestCase
{
    use FakesMetaAds, RefreshDatabase, SeedsAttribution;

    private const A = '1759800000004714';

    private const B = '1577100000009023';

    private const NAMES = [self::A => 'IronBody2', self::B => 'Fredy Medina'];

    /** @var array<string, list<array<string, mixed>>> filas de /insights por cuenta */
    private array $porCuenta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 15:00:00');
        config()->set('caja.timezone', 'America/Bogota');
        config()->set('marketing.attribution.coverage_since', '2026-01-01');
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B]]);

        $this->fakeMeta(function (Request $r) {
            $rango = json_decode($this->queryOf($r)['time_range'] ?? '{}', true);

            // Solo las filas del rango pedido, como Meta.
            return Http::response(['data' => array_values(array_filter(
                $this->porCuenta[$this->accountOf($r)] ?? [],
                fn (array $f): bool => $f['date_start'] >= ($rango['since'] ?? '') && $f['date_start'] <= ($rango['until'] ?? '9999'),
            ))]);
        });
        $this->fakeMetaExtras(account: fn (Request $r) => Http::response($this->ficha((string) $this->accountOf($r))));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> la ficha de una cuenta, como la da Meta */
    private function ficha(string $cuenta, array $extra = []): array
    {
        return array_merge([
            'id' => 'act_'.$cuenta, 'account_id' => $cuenta, 'name' => self::NAMES[$cuenta] ?? 'Otra', 'currency' => 'COP',
            'timezone_name' => 'America/Bogota', 'timezone_offset_hours_utc' => -5, 'account_status' => 1,
        ], $extra);
    }

    /** Las cuentas a las que se les pidió algo, en orden de primera petición. */
    private function cuentasPedidas(): array
    {
        return collect(Http::recorded())->map(fn (array $p) => $this->accountOf($p[0]))->filter()->unique()->values()->all();
    }

    // ── El job ──────────────────────────────────────────────────────────────

    /** Sin cuenta, el job despacha UNO por cuenta conectada (con su disparador) y termina, sin llamar a Meta. */
    public function test_sin_cuenta_el_job_despacha_uno_por_cuenta(): void
    {
        Queue::fake();

        app()->call([new SyncMetaAdsInsights('manual'), 'handle']);

        $despachados = Queue::pushed(SyncMetaAdsInsights::class)->values();
        $this->assertSame([[self::A, 'manual'], [self::B, 'manual']], $despachados->map(fn (SyncMetaAdsInsights $j) => [$j->account, $j->trigger])->all());
        Queue::assertPushedOn('commercial', SyncMetaAdsInsights::class);
        Http::assertNothingSent();
        $this->assertSame(0, MetaSyncRun::count());

        // Sin ninguna cuenta: no hay abanico, y queda constancia del motivo como siempre.
        $this->configureMetaAds(['ad_account_ids' => [], 'ad_account_id' => '']);
        app()->call([new SyncMetaAdsInsights('schedule'), 'handle']);
        Queue::assertPushed(SyncMetaAdsInsights::class, 2);
        $this->assertSame(['not_configured', 'missing_ad_account_id'], [MetaSyncRun::sole()->status, MetaSyncRun::sole()->error_code]);
        Http::assertNothingSent();
    }

    /**
     * Un job encolado ANTES de multicuenta (sin la propiedad `account` en su
     * carga) se deserializa como el abanico, no revienta por una propiedad sin
     * inicializar.
     */
    public function test_un_job_encolado_antes_de_multicuenta_es_el_abanico(): void
    {
        Queue::fake();
        $actual = serialize(new SyncMetaAdsInsights('manual'));
        $this->assertStringContainsString('s:7:"account";N;', $actual);
        $viejo = preg_replace_callback(
            '/^(O:\d+:"[^"]+":)(\d+)(:\{)/',
            fn (array $m): string => $m[1].((int) $m[2] - 1).$m[3],
            str_replace('s:7:"account";N;', '', $actual),
        );

        $job = unserialize($viejo);

        $this->assertInstanceOf(SyncMetaAdsInsights::class, $job);
        $this->assertSame(['manual', null], [$job->trigger, $job->account]);
        app()->call([$job, 'handle']);
        $this->assertSame([self::A, self::B], Queue::pushed(SyncMetaAdsInsights::class)->map(fn (SyncMetaAdsInsights $j) => $j->account)->values()->all());
    }

    /** «Actualizar» despacha el abanico: un job sin cuenta, con el disparador manual. */
    public function test_actualizar_despacha_el_abanico(): void
    {
        Queue::fake();

        $this->assertSame('queued', app(MetaDashboardService::class)->requestSync()['status']);

        Queue::assertPushed(SyncMetaAdsInsights::class, 1);
        Queue::assertPushed(SyncMetaAdsInsights::class, fn (SyncMetaAdsInsights $j) => $j->account === null && $j->trigger === 'manual');
    }

    /** Cada cuenta reintenta sola: el límite de A relanza su job; el de B sincroniza y deja sus datos. */
    public function test_cada_cuenta_reintenta_sola(): void
    {
        $this->porCuenta = [self::B => [$this->insightRow('2026-10-02', '8001', '1000.00')]];
        $this->fakeMeta(fn (Request $r) => $this->accountOf($r) === self::A
            ? $this->graphError(17, 'User request limit reached')
            : Http::response(['data' => $this->porCuenta[self::B]]));

        try {
            app()->call([new SyncMetaAdsInsights('schedule', self::A), 'handle']);
            $this->fail('El job de A tenía que lanzar para que la cola lo reintente.');
        } catch (MetaAdsApiException $e) {
            $this->assertSame('rate_limit', $e->category);
            $this->assertTrue($e->retryable());
        }

        app()->call([new SyncMetaAdsInsights('schedule', self::B), 'handle']);

        $this->assertSame([[self::A, 'failed'], [self::B, 'ok']], MetaSyncRun::orderBy('id')->get()->map(fn ($r) => [$r->ad_account_id, $r->status])->all());
        $this->assertSame(['1000.00'], MetaAdInsightDaily::where('ad_account_id', self::B)->pluck('spend')->all());
        // Con su gasto al día, B pidió también su alcance (tiene impresiones recientes).
        $this->assertNotEmpty(array_filter($this->requestsTo('insights'), fn (Request $r) => $this->accountOf($r) === self::B && ($this->queryOf($r)['level'] ?? null) === 'account'));

        // Un permiso de A no se reintenta.
        $this->fakeMeta(fn () => $this->graphError(190, 'Error validating access token'));
        $job = (new SyncMetaAdsInsights('schedule', self::A))->withFakeQueueInteractions();
        $job->handle(app(MetaAdsSync::class));
        $job->assertNotReleased();
        $job->assertNotFailed();
    }

    /** Con la cola de verdad: el abanico, y luego cada cuenta por su lado (A vuelve a la cola, B termina). */
    public function test_el_abanico_con_un_worker_de_verdad(): void
    {
        config()->set('queue.default', 'database');
        $trabajar = fn () => $this->artisan('queue:work', [
            'connection' => 'database', '--queue' => 'commercial', '--once' => true, '--sleep' => 0,
        ])->assertExitCode(0);
        $this->porCuenta = [self::B => [$this->insightRow('2026-10-02', '8001', '1000.00')]];
        $this->fakeMeta(fn (Request $r) => $this->accountOf($r) === self::A
            ? $this->graphError(17, 'User request limit reached')
            : Http::response(['data' => $this->porCuenta[self::B]]));

        SyncMetaAdsInsights::dispatch();
        $trabajar();
        $this->assertSame(2, DB::table('jobs')->where('queue', 'commercial')->count(), 'el abanico dejó un job por cuenta');

        $trabajar();
        $trabajar();

        $pendiente = DB::table('jobs')->sole();
        $this->assertSame(1, (int) $pendiente->attempts);
        $this->assertSame(now()->getTimestamp() + 60, (int) $pendiente->available_at);
        $this->assertStringContainsString(self::A, (string) $pendiente->payload, 'el que vuelve es el de A');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(['failed', 'ok'], [MetaSyncRun::where('ad_account_id', self::A)->sole()->status, MetaSyncRun::where('ad_account_id', self::B)->sole()->status]);
    }

    /** Una cuenta que dejó de estar conectada entre el despacho y la ejecución: nada que hacer ni que reintentar. */
    public function test_un_job_de_una_cuenta_que_ya_no_esta_conectada(): void
    {
        Log::spy();

        $job = (new SyncMetaAdsInsights('schedule', '999000111'))->withFakeQueueInteractions();
        $job->handle(app(MetaAdsSync::class));

        $job->assertNotReleased();
        $job->assertNotFailed();
        Http::assertNothingSent();
        $this->assertSame(0, MetaSyncRun::count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $evento, array $ctx): bool => $evento === 'meta.ads.sync.account_not_connected' && ($ctx['account'] ?? null) === '***0111')
            ->once();
    }

    // ── marketing:meta-ads-sync ─────────────────────────────────────────────

    /** Por defecto todas las cuentas, una tabla por cuenta; con --account, solo esas. */
    public function test_el_comando_de_sincronizar_por_cuenta(): void
    {
        $this->porCuenta = [
            self::A => [$this->insightRow('2026-10-02', '7001', '30000.00')],
            self::B => [$this->insightRow('2026-10-02', '8001', '1000.00')],
        ];

        $this->artisan('marketing:meta-ads-sync', ['--from' => '2026-10-01', '--to' => '2026-10-05', '--account' => ['act_'.self::B]])
            ->expectsOutputToContain('Cuenta Fredy Medina (***9023):')
            ->doesntExpectOutputToContain('IronBody2')
            ->expectsOutputToContain('Listo')
            ->assertSuccessful();
        $this->assertSame([self::B], $this->cuentasPedidas());
        $this->assertSame([self::B], MetaSyncRun::pluck('ad_account_id')->all());

        $this->artisan('marketing:meta-ads-sync', ['--from' => '2026-10-01', '--to' => '2026-10-05', '--no-reach' => true])
            ->expectsOutputToContain('Cuenta IronBody2 (***4714):')
            ->expectsOutputToContain('Cuenta Fredy Medina (***9023):')
            ->expectsOutputToContain('Listo')
            ->assertSuccessful();
        $this->assertSame(2, MetaSyncRun::where('ad_account_id', self::B)->count());
        $this->assertSame(1, MetaSyncRun::where('ad_account_id', self::A)->count());

        // Varias en un valor, separadas por comas.
        $this->artisan('marketing:meta-ads-sync', ['--account' => [self::A.','.self::B], '--no-reach' => true])->assertSuccessful();
        $this->assertSame(5, MetaSyncRun::count());
    }

    /** Una cuenta que no está conectada no se acepta; sin pedir nada a Meta. */
    public function test_el_comando_de_sincronizar_rechaza_una_cuenta_no_conectada(): void
    {
        $this->artisan('marketing:meta-ads-sync', ['--account' => ['999']])
            ->expectsOutputToContain('--account tiene que ser una cuenta conectada')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertSame(0, MetaSyncRun::count());
    }

    /** Si Meta pide frenar en una cuenta, las siguientes no se piden y el comando lo dice. */
    public function test_el_comando_de_sincronizar_se_frena_ante_un_limite(): void
    {
        $this->fakeMeta(fn () => $this->graphError(17, 'User request limit reached'));

        $this->artisan('marketing:meta-ads-sync')
            ->expectsOutputToContain('Meta pidió frenar en una cuenta anterior')
            ->assertFailed();

        $this->assertSame([self::A], $this->cuentasPedidas());
        $this->assertSame([[self::A, 'failed']], MetaSyncRun::get()->map(fn ($r) => [$r->ad_account_id, $r->status])->all());
    }

    /**
     * El límite llega en la ÚLTIMA cuenta (B), en su petición principal: no
     * queda ninguna que saltar, pero la vuelta topó con el cupo de la app, así
     * que tampoco se pide el alcance de A. Tras el límite no sale ninguna
     * petición.
     */
    public function test_el_comando_de_sincronizar_no_pide_alcance_tras_un_limite_en_la_ultima(): void
    {
        $this->porCuenta = [self::A => [$this->insightRow('2026-10-05', '7001', '100.00', ['impressions' => '50', 'campaign_id' => '501'])]];
        $this->fakeMeta(fn (Request $r) => $this->accountOf($r) === self::B
            ? $this->graphError(17, 'User request limit reached', 400, ['error_subcode' => 2446079])
            : Http::response(['data' => $this->porCuenta[self::A]]));
        $this->fakeMetaExtras(reach: ['data' => [['reach' => '10', 'impressions' => '50', 'frequency' => '5']]]);

        $this->artisan('marketing:meta-ads-sync')
            ->expectsOutputToContain('Alcance: no se pide en esta vuelta, Meta pidió frenar.')
            ->assertFailed();

        $peticiones = collect(Http::recorded())->map(fn (array $p) => $p[1]?->status())->values();
        $limite = $peticiones->search(400);
        $this->assertNotFalse($limite, 'B recibió el límite');
        $this->assertSame([], $peticiones->slice($limite + 1)->all(), 'tras el límite no se pidió nada');
        $this->assertSame([], array_filter($this->requestsTo('insights'), fn (Request $r) => ($this->queryOf($r)['level'] ?? null) === 'account'), 'ni el alcance de A');
        $this->assertSame([[self::A, 'ok'], [self::B, 'failed']], MetaSyncRun::orderBy('id')->get()->map(fn ($r) => [$r->ad_account_id, $r->status])->all());
    }

    // ── marketing:meta-ads-backfill ─────────────────────────────────────────

    /** La simulación de una sola cuenta pide solo la suya. */
    public function test_el_relleno_simulado_de_una_cuenta(): void
    {
        Sleep::fake();
        $this->porCuenta = [
            self::A => [$this->insightRow('2026-10-02', '7001', '30000.00')],
            self::B => [$this->insightRow('2026-10-02', '8001', '1000.00'), $this->insightRow('2026-10-04', '8002', '500.00')],
        ];

        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2026-10-01', '--to' => '2026-10-05', '--batch-days' => 7, '--pause' => 0, '--dry-run' => true, '--account' => [self::B]])
            ->expectsTable(['dato', 'valor'], [
                ['days', 5], ['batches', 1], ['campaigns', 1], ['adsets', 1], ['ads', 2],
                ['insight_rows', 2], ['spend', '1500.00'], ['estimated_requests', 1 + 10 + 3],
            ])
            ->assertSuccessful();

        $this->assertSame([self::B], $this->cuentasPedidas());
        $this->assertSame(0, MetaAdInsightDaily::count());
    }

    /** Por cuenta: salta lo que ya cubre ESA cuenta, y al final, estados, alcance y el total. */
    public function test_el_relleno_por_cuenta_salta_lo_cubierto_de_cada_una(): void
    {
        Sleep::fake();
        $this->porCuenta = [
            self::A => [$this->insightRow('2026-10-02', '7001', '30000.00')],
            self::B => [$this->insightRow('2026-10-02', '8001', '1000.00')],
        ];
        // A ya tiene cubierto el rango; B no.
        $this->assertSame('ok', $this->sync()->forAccount(self::A)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);
        $antes = Http::recorded()->count();

        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2026-10-01', '--to' => '2026-10-05', '--batch-days' => 7, '--pause' => 0])
            ->expectsOutputToContain('Cuenta IronBody2 (***4714):')
            ->expectsOutputToContain('[1/1] 2026-10-01 → 2026-10-05: ya cubierto, se salta')
            // El nombre de B aún no se ha leído de Meta (lo trae su primera pasada): solo su referencia.
            ->expectsOutputToContain('Cuenta ***9023:')
            ->expectsOutputToContain('[1/1] 2026-10-01 → 2026-10-05: OK · 1 filas, 0 borradas')
            ->expectsOutputToContain('Total de las cuentas:')
            ->assertSuccessful();

        $this->assertSame(1, MetaSyncRun::where('ad_account_id', self::B)->where('trigger', 'backfill')->count());
        $this->assertSame(0, MetaSyncRun::where('ad_account_id', self::A)->where('trigger', 'backfill')->count());
        // De A no se pidió ningún tramo; los estados, una vez por cuenta, al final.
        $pedidas = collect(Http::recorded())->slice($antes)->map(fn (array $p): Request => $p[0]);
        $tramos = $pedidas->filter(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/insights') && ($this->queryOf($r)['level'] ?? null) === 'ad');
        $this->assertSame([self::B], $tramos->map(fn (Request $r) => $this->accountOf($r))->values()->all());
        $estados = $pedidas->filter(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/campaigns'));
        $this->assertSame([self::A, self::B], $estados->map(fn (Request $r) => $this->accountOf($r))->values()->all());
    }

    /** Un permiso denegado en una cuenta detiene esa cuenta, no las demás; un límite que no se pasa, sí. */
    public function test_el_relleno_sigue_con_las_demas_si_una_falla(): void
    {
        Sleep::fake();
        $this->porCuenta = [self::B => [$this->insightRow('2026-10-02', '8001', '1000.00')]];
        $this->fakeMeta(fn (Request $r) => $this->accountOf($r) === self::A
            ? $this->graphError(200, 'Requires ads_read permission')
            : Http::response(['data' => $this->porCuenta[self::B]]));

        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2026-10-01', '--to' => '2026-10-05', '--batch-days' => 7, '--pause' => 0])
            ->expectsOutputToContain('se detiene el relleno (permission)')
            ->expectsOutputToContain('Cuenta ***9023:')
            ->assertFailed();
        $this->assertSame([[self::A, 'failed'], [self::B, 'ok']], MetaSyncRun::where('trigger', 'backfill')->orderBy('id')->get()->map(fn ($r) => [$r->ad_account_id, $r->status])->all());

        // Un límite que no se pasa reintentando: las siguientes cuentas no se piden.
        MetaSyncRun::query()->delete();
        $this->fakeMeta(fn (Request $r) => $this->accountOf($r) === self::A
            ? $this->graphError(17, 'User request limit reached')
            : Http::response(['data' => []]));
        $this->artisan('marketing:meta-ads-backfill', ['--from' => '2026-10-01', '--to' => '2026-10-05', '--batch-days' => 7, '--pause' => 0, '--max-retries' => 0])
            ->expectsOutputToContain('se detiene el relleno (rate_limit)')
            ->expectsOutputToContain('No se pidió: Meta pidió frenar en una cuenta anterior')
            ->assertFailed();
        $this->assertSame([self::A], MetaSyncRun::pluck('ad_account_id')->unique()->values()->all());
    }

    // ── marketing:meta-ads-reconcile ────────────────────────────────────────

    /** @param  array<string, string>  $gastoMeta  lo que Meta dice de cada cuenta */
    private function conciliable(array $gastoMeta): void
    {
        $this->porCuenta = [
            self::A => [
                $this->insightRow('2026-10-02', '7001', '60000.00', ['campaign_id' => '501', 'impressions' => '1000']),
                $this->insightRow('2026-10-03', '7001', '40000.00', ['campaign_id' => '501', 'impressions' => '3000']),
            ],
            self::B => [$this->insightRow('2026-10-02', '8001', '2500.00', ['campaign_id' => '601', 'impressions' => '500'])],
        ];
        $this->sync()->runAll('manual', $this->day('2026-10-01'), $this->day('2026-10-04'));

        $impresiones = [self::A => '4000', self::B => '500'];
        $campana = [self::A => '501', self::B => '601'];
        $this->fakeMetaExtras(reach: function (Request $r) use ($gastoMeta, $impresiones, $campana) {
            $cuenta = $this->accountOf($r);

            return Http::response(['data' => $this->queryOf($r)['level'] === 'account'
                ? [['spend' => $gastoMeta[$cuenta], 'impressions' => $impresiones[$cuenta], 'reach' => '3000']]
                : [['campaign_id' => $campana[$cuenta], 'campaign_name' => 'Campaña', 'spend' => $gastoMeta[$cuenta], 'impressions' => $impresiones[$cuenta], 'reach' => '3000']]]);
        });
    }

    /** Por cuenta y en total: el gasto del panel es la suma de las cuentas, y sus filas por cuenta suman lo mismo. */
    public function test_la_conciliacion_por_cuenta_y_consolidada(): void
    {
        $this->conciliable([self::A => '100000.00', self::B => '2500.00']);

        $this->artisan('marketing:meta-ads-reconcile', ['--period' => 'custom', '--from' => '2026-10-01', '--to' => '2026-10-04'])
            ->expectsOutputToContain('Cuenta IronBody2 (***4714):')
            ->expectsOutputToContain('META_SPEND=100.000,00')
            ->expectsOutputToContain('Cuenta Fredy Medina (***9023):')
            ->expectsOutputToContain('META_SPEND=2.500,00')
            ->expectsOutputToContain('DASHBOARD_SPEND=2.500,00')
            ->expectsOutputToContain('TOTAL_META_SPEND=102.500,00')
            ->expectsOutputToContain('DASHBOARD_TOTAL_SPEND=102.500,00')
            ->expectsOutputToContain('ACCOUNT_ROWS_SPEND=102.500,00')
            ->expectsOutputToContain('CONSOLIDATED_MATCH=YES')
            ->doesntExpectOutputToContain('SPEND_MATCH=NO')
            ->doesntExpectOutputToContain('CAMPAIGNS_MATCH=NO')
            ->assertSuccessful();
    }

    /**
     * Con una cuenta parcial, el panel corta TODAS las cuentas en el día común;
     * la que está completa se concilia con su propia lectura, sin cortar, y
     * cuadra. El consolidado no se aprueba, porque el periodo no está entero.
     */
    public function test_la_conciliacion_de_una_cuenta_completa_no_se_corta_por_otra_parcial(): void
    {
        $this->porCuenta = [
            self::A => [
                $this->insightRow('2026-10-02', '7001', '60000.00', ['campaign_id' => '501', 'impressions' => '1000']),
                $this->insightRow('2026-10-04', '7001', '40000.00', ['campaign_id' => '501', 'impressions' => '3000']),
            ],
            self::B => [$this->insightRow('2026-10-02', '8001', '2500.00', ['campaign_id' => '601', 'impressions' => '500'])],
        ];
        $this->sync()->forAccount(self::A)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));
        $this->sync()->forAccount(self::B)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-03'));
        $meta = [self::A => ['100000.00', '4000', '501'], self::B => ['2500.00', '500', '601']];
        $this->fakeMetaExtras(reach: function (Request $r) use ($meta) {
            [$gasto, $impresiones, $campana] = $meta[$this->accountOf($r)];

            return Http::response(['data' => $this->queryOf($r)['level'] === 'account'
                ? [['spend' => $gasto, 'impressions' => $impresiones, 'reach' => '3000']]
                : [['campaign_id' => $campana, 'campaign_name' => 'Campaña', 'spend' => $gasto, 'impressions' => $impresiones, 'reach' => '3000']]]);
        });

        $this->artisan('marketing:meta-ads-reconcile', ['--period' => 'custom', '--from' => '2026-10-01', '--to' => '2026-10-05'])
            ->expectsOutputToContain('Cuenta IronBody2 (***4714):')
            // Su lectura entera (60.000 + 40.000), no los 60.000 del corte en el 3 de octubre.
            ->expectsOutputToContain('DASHBOARD_SPEND=100.000,00')
            ->expectsOutputToContain('SPEND_MATCH=YES')
            ->doesntExpectOutputToContain('DASHBOARD_SPEND=60.000,00')
            ->expectsOutputToContain('CONSOLIDATED_MATCH=NO')
            ->assertFailed();
    }

    public function test_la_conciliacion_falla_si_una_cuenta_no_cuadra(): void
    {
        $this->conciliable([self::A => '100000.00', self::B => '2600.00']);

        $this->artisan('marketing:meta-ads-reconcile', ['--period' => 'custom', '--from' => '2026-10-01', '--to' => '2026-10-04'])
            ->expectsOutputToContain('SPEND_MATCH=NO')
            ->expectsOutputToContain('TOTAL_META_SPEND=102.600,00')
            ->expectsOutputToContain('CONSOLIDATED_MATCH=NO')
            ->assertFailed();
    }

    /**
     * Si Meta pide frenar en una cuenta, la conciliación no pide las
     * siguientes: el cupo es de la app. Las dice «no pedidas», el total de Meta
     * no se sabe y el comando falla. Tras el límite no sale ninguna petición.
     */
    public function test_la_conciliacion_se_frena_ante_un_limite(): void
    {
        $this->conciliable([self::A => '100000.00', self::B => '2500.00']);
        $antes = Http::recorded()->count();
        $this->fakeMetaExtras(reach: fn () => $this->graphError(17, 'User request limit reached', 400, ['error_subcode' => 2446079]));

        $this->artisan('marketing:meta-ads-reconcile', ['--period' => 'custom', '--from' => '2026-10-01', '--to' => '2026-10-04'])
            ->expectsOutputToContain('Meta no contestó (rate_limit)')
            ->expectsOutputToContain('Cuenta Fredy Medina (***9023):')
            ->expectsOutputToContain('No se pidió: Meta pidió frenar en una cuenta anterior')
            ->expectsOutputToContain('TOTAL_META_SPEND=—')
            ->expectsOutputToContain('CONSOLIDATED_MATCH=NO')
            ->doesntExpectOutputToContain('META_SPEND=2.500,00')
            ->assertFailed();

        // Una sola petición, la primera de A, y ninguna después.
        $pedidas = collect(Http::recorded())->slice($antes)->values();
        $this->assertSame([[self::A, 400]], $pedidas->map(fn (array $p) => [$this->accountOf($p[0]), $p[1]?->status()])->all());
    }

    // ── marketing:meta-ads-check ────────────────────────────────────────────

    /** El token se comprueba una vez; cada cuenta, por su lado; monedas distintas entre cuentas se avisan. */
    public function test_la_comprobacion_de_varias_cuentas(): void
    {
        $this->artisan('marketing:meta-ads-check', ['--expect-account' => 'act_'.self::B, '--expect-name' => 'fredy medina'])
            ->expectsOutputToContain('META_ACCOUNTS_CONNECTED=2')
            ->expectsOutputToContain('META_ACCOUNT_NAME=IronBody2')
            ->expectsOutputToContain('META_ACCOUNT_NAME=Fredy Medina')
            ->expectsOutputToContain('Las cuentas son las esperadas y el token puede leerlas.')
            ->doesntExpectOutputToContain(self::ADS_TOKEN)
            ->assertSuccessful();

        $rutas = collect(Http::recorded())->map(fn (array $p) => parse_url($p[0]->url(), PHP_URL_PATH))->all();
        $this->assertSame(['/v99.0/me', '/v99.0/me/permissions', '/v99.0/act_'.self::A, '/v99.0/act_'.self::B], $rutas);

        // Otra moneda en una: aviso, no bloqueo. Otro nombre en la esperada: bloqueo.
        $this->fakeMetaExtras(account: fn (Request $r) => Http::response($this->ficha((string) $this->accountOf($r), $this->accountOf($r) === self::B ? ['currency' => 'USD'] : [])));
        // (Una línea de la tabla solo cuenta para una expectativa: la del aviso de «Misma moneda en todas».)
        $this->artisan('marketing:meta-ads-check')
            ->expectsOutputToContain('monedas: COP, USD')
            ->expectsOutputToContain('Las cuentas son las esperadas y el token puede leerlas.')
            ->assertSuccessful();
        $this->artisan('marketing:meta-ads-check', ['--expect-account' => self::B, '--expect-name' => 'Otra Persona'])
            ->expectsOutputToContain('No se debe sincronizar: Nombre esperado (***9023).')
            ->assertFailed();
    }

    // ── marketing:meta-ads-ad-accounts ──────────────────────────────────────

    /** «Conectadas» son todas: los anuncios de A y de B salen como de una cuenta conectada. */
    public function test_el_diagnostico_cuenta_todas_las_conectadas(): void
    {
        Sleep::fake();
        foreach (['1202500387' => 3, '1202600194' => 1, '1202510062' => 2] as $ad => $n) {
            for ($i = 0; $i < $n; $i++) {
                $this->touch($this->lead('2026-10-01 15:00:00'), $this->adReferral((string) $ad), '2026-10-01 15:00:00');
            }
        }
        $this->fakeMetaExtras(nodes: [
            '1202500387' => ['id' => '1202500387', 'account_id' => self::A, 'effective_status' => 'ACTIVE'],
            '1202600194' => ['id' => '1202600194', 'account_id' => self::B, 'effective_status' => 'PAUSED'],
            '1202510062' => ['id' => '1202510062', 'account_id' => '555000111', 'effective_status' => 'ACTIVE'],
        ]);

        $this->artisan('marketing:meta-ads-ad-accounts', ['--since' => '2026-09-01'])
            ->expectsOutputToContain(self::A.' (conectada)')
            ->expectsOutputToContain(self::B.' (conectada)')
            ->expectsOutputToContain('CONNECTED_ACCOUNTS=2')
            ->expectsOutputToContain('CONNECTED_ACCOUNT_ADS=2 · leads 4')
            ->expectsOutputToContain('OTHER_ACCOUNT_ADS=1')
            ->expectsOutputToContain('CONNECTED_ACCOUNT_HAS_LEAD_ADS=YES')
            ->expectsOutputToContain('MULTI_AD_ACCOUNT_REQUIRED=YES')
            ->expectsOutputToContain('Nada cambia: el panel sigue resolviendo contra las cuentas conectadas y avisa de la pauta que no está en ellas.')
            ->assertSuccessful();
    }
}
