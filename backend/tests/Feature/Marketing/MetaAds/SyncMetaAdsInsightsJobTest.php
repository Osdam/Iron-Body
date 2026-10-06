<?php

namespace Tests\Feature\Marketing\MetaAds;

use App\Jobs\SyncMetaAdsInsights;
use App\Models\MetaAdInsightDaily;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Meta\MetaAdsApiException;
use App\Services\Marketing\Meta\MetaAdsSync;
use Closure;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El job, su programación y el comando de relleno.
 *
 * Contrato 22: si Meta pide frenar o no contesta, se reintenta; si el token no
 * tiene permiso, no, porque repetir la misma petición da el mismo error.
 */
class SyncMetaAdsInsightsJobTest extends TestCase
{
    use FakesMetaAds, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 15:00:00');
        $this->configureMetaAds();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cola_intentos_y_esperas_son_los_del_contrato(): void
    {
        $job = new SyncMetaAdsInsights;

        $this->assertSame('commercial', $job->queue);
        $this->assertSame(4, $job->tries);
        $this->assertSame([60, 300, 900], $job->backoff);
        $this->assertSame('schedule', $job->trigger);
        $this->assertSame('manual', (new SyncMetaAdsInsights('manual'))->trigger);
    }

    /** @return array<string, array{0: string, 1: Closure}> */
    public static function fallosQueSeReintentan(): array
    {
        $graph = static fn (int $code, string $message, int $status = 400): Closure => static fn () => Http::response(
            ['error' => ['message' => $message, 'type' => 'OAuthException', 'code' => $code]],
            $status,
        );

        return [
            'límite de usuario (17)' => ['rate_limit', $graph(17, 'User request limit reached')],
            'límite de la app (4)' => ['rate_limit', $graph(4, 'Application request limit reached')],
            'límite de llamadas (613)' => ['rate_limit', $graph(613, 'Calls to this api have exceeded the rate limit.')],
            'límite de insights (80000)' => ['rate_limit', $graph(80000, 'There have been too many calls from this ad-account.')],
            'HTTP 429 sin cuerpo de Graph' => ['rate_limit', static fn () => Http::response('Too Many Requests', 429)],
            'Meta caída (503)' => ['transient', $graph(2, 'Service temporarily unavailable', 503)],
            'error interno (500)' => ['transient', static fn () => Http::response('', 500)],
            'timeout' => ['transient', static fn (Request $request) => Http::failedConnection('cURL error 28: timed out')($request)],
        ];
    }

    #[DataProvider('fallosQueSeReintentan')]
    public function test_rate_limit_y_transitorio_se_reintentan(string $categoria, Closure $respuesta): void
    {
        $this->fakeMeta($respuesta);

        try {
            app()->call([new SyncMetaAdsInsights, 'handle']);
            $this->fail('El job tenía que lanzar para que la cola lo reintente.');
        } catch (MetaAdsApiException $e) {
            $this->assertSame($categoria, $e->category);
            $this->assertTrue($e->retryable());
            $this->assertStringNotContainsString(self::ADS_TOKEN, $e->getMessage());
        }

        $run = MetaSyncRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame($categoria, $run->error_code);

        // Una sola petición: quien reintenta es la cola, con su espera, no el cliente.
        $this->assertCount(1, $this->requestsTo('insights'));
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> */
    public static function fallosQueNoSeReintentan(): array
    {
        return [
            'token caducado (190)' => ['permission', 190, 'Error validating access token: Session has expired.'],
            'sin permiso (10)' => ['permission', 10, 'Application does not have permission for this action'],
            'sin ads_read (200)' => ['permission', 200, 'Requires ads_read permission'],
            'sin acceso a la cuenta (294)' => ['permission', 294, 'Managing advertisements requires an access token with ads_management'],
            'petición inválida (100)' => ['other', 100, 'Invalid parameter'],
        ];
    }

    #[DataProvider('fallosQueNoSeReintentan')]
    public function test_permiso_y_otros_no_se_reintentan(string $categoria, int $codigo, string $mensaje): void
    {
        $this->fakeMeta(fn () => $this->graphError($codigo, $mensaje));

        $job = (new SyncMetaAdsInsights)->withFakeQueueInteractions();
        $job->handle(app(MetaAdsSync::class));

        $job->assertNotReleased();
        $job->assertNotFailed();

        $run = MetaSyncRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame($categoria, $run->error_code);
        $this->assertCount(1, $this->requestsTo('insights'));
    }

    /**
     * Lo mismo, con la cola de verdad: un worker procesa el job contra la tabla
     * `jobs`. El rate limit vuelve a la cola con 60 s de espera; el de permiso
     * termina y no queda ni en la cola ni en `failed_jobs`.
     */
    public function test_con_un_worker_de_verdad(): void
    {
        config()->set('queue.default', 'database');
        $trabajar = fn () => $this->artisan('queue:work', [
            'connection' => 'database', '--queue' => 'commercial', '--once' => true, '--sleep' => 0,
        ])->assertExitCode(0);

        $this->fakeMeta(fn () => $this->graphError(17, 'User request limit reached'));
        SyncMetaAdsInsights::dispatch();
        $this->assertSame(1, DB::table('jobs')->where('queue', 'commercial')->count());

        $trabajar();

        $pendiente = DB::table('jobs')->sole();
        $this->assertSame(1, (int) $pendiente->attempts);
        $this->assertSame(now()->getTimestamp() + 60, (int) $pendiente->available_at);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        DB::table('jobs')->delete();

        $this->fakeMeta(fn () => $this->graphError(190, 'Error validating access token'));
        SyncMetaAdsInsights::dispatch();

        $trabajar();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(['rate_limit', 'permission'], MetaSyncRun::orderBy('id')->pluck('error_code')->all());
    }

    /**
     * Un fallo propio (no de Meta) marca el job como fallido y no se repite a
     * ciegas. El detalle viaja con la excepción a `failed_jobs`; la fila, que
     * llega al panel, solo dice que fue un error interno.
     */
    public function test_un_fallo_propio_falla_el_job_sin_reintentos(): void
    {
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '30000')]]);

        // La tabla de destino no existe: el fallo es nuestro, no de Meta.
        DB::statement('DROP TABLE meta_ad_insights_daily');

        $job = (new SyncMetaAdsInsights)->withFakeQueueInteractions();
        $job->handle(app(MetaAdsSync::class));

        $job->assertFailedWith(QueryException::class);
        $job->assertNotReleased();
        $this->assertStringContainsString('meta_ad_insights_daily', $job->job->failedWith->getMessage());

        $run = MetaSyncRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('internal', $run->error_code);
        $this->assertSame('Error interno al guardar la sincronización.', $run->error_message);
    }

    public function test_el_scheduler_solo_la_programa_si_esta_encendida(): void
    {
        $this->assertCount(0, $this->programadas(false));

        $eventos = $this->programadas(true);
        $this->assertCount(1, $eventos);
        $this->assertSame('15 * * * *', $eventos[0]->expression);

        // Y lo que despacha va al carril comercial, como pasada programada.
        Queue::fake();
        $eventos[0]->run($this->app);
        Queue::assertPushedOn('commercial', SyncMetaAdsInsights::class, fn (SyncMetaAdsInsights $job) => $job->trigger === 'schedule');
    }

    public function test_el_comando_rellena_el_rango_pedido(): void
    {
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-09-17', '9001', '1000'),
            $this->insightRow('2026-09-20', '9001', '500'),
        ]]);

        $this->artisan('marketing:meta-ads-sync', ['--from' => '2026-09-17', '--to' => '2026-09-20'])
            ->expectsOutputToContain('Listo')
            ->assertExitCode(0);

        $run = MetaSyncRun::sole();
        $this->assertSame('ok', $run->status);
        $this->assertSame('manual', $run->trigger);
        $this->assertSame(['2026-09-17', '2026-09-20'], [$run->range_from, $run->range_to]);

        $query = $this->queryOf($this->requestsTo('insights')[0]);
        $this->assertSame(['since' => '2026-09-17', 'until' => '2026-09-20'], json_decode($query['time_range'], true));
        $this->assertSame(2, MetaAdInsightDaily::count());
    }

    public function test_el_comando_rechaza_fechas_que_no_existen_sin_llamar_a_meta(): void
    {
        $this->fakeMeta(['data' => []]);

        $this->artisan('marketing:meta-ads-sync', ['--from' => '2026-02-31'])
            ->expectsOutputToContain('No entiendo esa fecha')
            ->assertExitCode(1);

        $this->artisan('marketing:meta-ads-sync', ['--from' => '2026-10-05', '--to' => '2026-10-01'])
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(0, MetaSyncRun::count());
    }

    public function test_el_comando_sin_configuracion_o_con_error_falla_sin_ensenar_el_token(): void
    {
        $this->configureMetaAds(['access_token' => '']);
        $this->artisan('marketing:meta-ads-sync')
            ->expectsOutputToContain('META_ADS_ACCESS_TOKEN')
            ->assertExitCode(1);

        $this->configureMetaAds();
        $this->fakeMeta(fn () => $this->graphError(190, 'Invalid OAuth access token: '.self::ADS_TOKEN));
        $this->artisan('marketing:meta-ads-sync')
            ->expectsOutputToContain('código 190')
            ->doesntExpectOutputToContain(self::ADS_TOKEN)
            ->assertExitCode(1);
    }

    /**
     * Vuelve a leer routes/console.php sobre un scheduler limpio con el
     * interruptor como se pida, y devuelve las entradas de este job.
     *
     * @return list<Event>
     */
    private function programadas(bool $encendida): array
    {
        config()->set('meta.ads.sync_enabled', $encendida);

        $limpio = new Schedule;
        ScheduleFacade::swap($limpio);
        require base_path('routes/console.php');

        return collect($limpio->events())
            ->filter(fn ($evento) => $evento->description === SyncMetaAdsInsights::class)
            ->values()
            ->all();
    }
}
