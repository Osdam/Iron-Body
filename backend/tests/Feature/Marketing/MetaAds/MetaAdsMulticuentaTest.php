<?php

namespace Tests\Feature\Marketing\MetaAds;

use App\Models\MetaAdEntity;
use App\Models\MetaAdInsightDaily;
use App\Models\MetaAdReachSnapshot;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Attribution\MetaDashboardService;
use App\Services\Marketing\Meta\MetaAdsApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Meta Ads MULTICUENTA, lado sincronización y lectura: cada cuenta conectada se
 * sincroniza por su lado (su cerrojo, su transacción, sus filas), el gasto se
 * consolida sin contar nada dos veces, y un fallo o un límite de Meta en una
 * cuenta no destruye lo de las otras.
 *
 * Reloj: 5 de octubre de 2026, 10:00 en Bogotá. Ninguna prueba sale a la red.
 */
class MetaAdsMulticuentaTest extends TestCase
{
    use FakesMetaAds, RefreshDatabase;

    /** IronBody2: la que gasta hoy. */
    private const A = '1759800000004714';

    /** Fredy: el histórico. */
    private const B = '1577100000009023';

    /** IRONBODY: conectada, sin actividad. */
    private const C = '3611000000005856';

    private const NAMES = [self::A => 'IronBody2', self::B => 'Fredy Medina', self::C => 'IRONBODY cuenta publicitaria'];

    /** @var array<string, list<array<string, mixed>>> filas de /insights por cuenta */
    private array $porCuenta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 15:00:00');
        config()->set('caja.timezone', 'America/Bogota');
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B]]);

        // Cada cuenta contesta con lo suyo, y su ficha con su nombre.
        $this->fakeMeta(fn (Request $r) => Http::response(['data' => $this->porCuenta[$this->accountOf($r)] ?? []]));
        $this->fakeMetaExtras(account: fn (Request $r) => Http::response([
            'id' => 'act_'.$this->accountOf($r), 'account_id' => $this->accountOf($r), 'name' => self::NAMES[$this->accountOf($r)] ?? null,
            'currency' => 'COP', 'timezone_name' => 'America/Bogota', 'timezone_offset_hours_utc' => -5, 'account_status' => 1,
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Configuración ───────────────────────────────────────────────────────

    /** META_AD_ACCOUNT_IDS: `act_`, comas y espacios, sin duplicados, en orden; si no queda ninguna, META_AD_ACCOUNT_ID. */
    public function test_el_parseo_de_las_cuentas_conectadas(): void
    {
        $this->assertSame(['111', '222', '333'], MetaAdsApiClient::parseAccountIds('act_111, 222  333,act_111 ,, 222'));
        $this->assertSame(['333', '111', '222'], MetaAdsApiClient::parseAccountIds("333\n111\t act_222"), 'el orden escrito');
        $this->assertSame(['444'], MetaAdsApiClient::parseAccountIds('abc,act_12/3,444,act_,12.5'), 'lo que no es un id se descarta');
        $this->assertSame(['1', '2', '3'], MetaAdsApiClient::parseAccountIds(['act_1', ' 2 ', 3, null, '1']), 'también una lista');
        $this->assertSame([str_repeat('9', 40)], MetaAdsApiClient::parseAccountIds(str_repeat('9', 40).','.str_repeat('9', 41)), 'de 1 a 40 dígitos');

        // El respaldo, solo si la lista queda vacía.
        $this->assertSame(['111'], MetaAdsApiClient::parseAccountIds('111', '999'));
        $this->assertSame(['555'], MetaAdsApiClient::parseAccountIds('', 'act_555'));
        $this->assertSame(['555'], MetaAdsApiClient::parseAccountIds('abc, act_x', ' 555 '));
        $this->assertSame([], MetaAdsApiClient::parseAccountIds(null, null));
        $this->assertSame([], MetaAdsApiClient::parseAccountIds(' , ', 'act_12/3'));

        // El cliente aplica la misma regla a lo que haya en la configuración, y la primera es la principal.
        $client = app(MetaAdsApiClient::class);
        config()->set('meta.ads.ad_account_ids', 'act_222, 111 222');
        config()->set('meta.ads.ad_account_id', '999');
        $this->assertSame(['222', '111'], $client->accountIds());
        $this->assertSame('222', $client->accountId());
        $this->assertNull($client->configurationProblem());

        config()->set('meta.ads.ad_account_ids', []);
        $this->assertSame(['999'], $client->accountIds(), 'respaldo a META_AD_ACCOUNT_ID');

        config()->set('meta.ads.ad_account_id', '');
        $this->assertSame([], $client->accountIds());
        $this->assertSame('missing_ad_account_id', $client->configurationProblem());

        config()->set('meta.ads.ad_account_id', 'act_123/../me');
        $this->assertSame('invalid_ad_account_id', $client->configurationProblem());
        config()->set('meta.ads.ad_account_ids', 'abc');
        config()->set('meta.ads.ad_account_id', '');
        $this->assertSame('invalid_ad_account_id', $client->configurationProblem());
    }

    /** config/meta.php lee META_AD_ACCOUNT_IDS con esa regla y cae a META_AD_ACCOUNT_ID; la clave de siempre no cambia. */
    public function test_la_configuracion_lee_la_lista_del_entorno(): void
    {
        // Evalúa config/meta.php con esas variables de entorno, y deja el entorno como estaba.
        $leer = function (?string $lista, ?string $una): array {
            $claves = ['META_AD_ACCOUNT_IDS' => $lista, 'META_AD_ACCOUNT_ID' => $una];
            $antes = [];
            foreach ($claves as $clave => $valor) {
                $antes[$clave] = [$_SERVER[$clave] ?? null, $_ENV[$clave] ?? null];
                unset($_SERVER[$clave], $_ENV[$clave]);
                if ($valor !== null) {
                    $_SERVER[$clave] = $_ENV[$clave] = $valor;
                }
            }

            try {
                $config = require base_path('config/meta.php');
            } finally {
                foreach ($antes as $clave => [$server, $env]) {
                    unset($_SERVER[$clave], $_ENV[$clave]);
                    if ($server !== null) {
                        $_SERVER[$clave] = $server;
                    }
                    if ($env !== null) {
                        $_ENV[$clave] = $env;
                    }
                }
            }

            return [$config['ads']['ad_account_ids'], $config['ads']['ad_account_id']];
        };

        $this->assertSame([[self::A, self::B, self::C], '1577100000009023'], $leer('act_'.self::A.', '.self::B.' '.self::C.','.self::A, self::B));
        $this->assertSame([[self::B], 'act_'.self::B], $leer('', 'act_'.self::B), 'vacía: la de siempre');
        $this->assertSame([[self::B], self::B], $leer(null, self::B), 'sin declarar: la de siempre');
        $this->assertSame([[], null], $leer(null, null));
    }

    /** forAccount() liga un clon a una cuenta conectada; una que no lo está, no. El token no sale del cliente. */
    public function test_el_cliente_se_liga_a_una_cuenta_conectada(): void
    {
        $client = app(MetaAdsApiClient::class);
        $this->assertSame([self::A, self::B], $client->accountIds());
        $this->assertSame(self::A, $client->accountId(), 'sin ligar, la principal');
        $this->assertFalse($client->isBound());

        $b = $client->forAccount('act_'.self::B);
        $this->assertSame(self::B, $b->accountId());
        $this->assertTrue($b->isBound());
        $this->assertSame(self::A, $client->accountId(), 'el original no cambia');
        $this->assertNull($b->configurationProblem());

        foreach (['999', 'act_'.self::C, '', 'act_123/../me'] as $ajena) {
            try {
                $client->forAccount($ajena);
                $this->fail("Se ligó una cuenta no conectada: «{$ajena}».");
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString(self::C, $e->getMessage());
            }
        }

        // Ligado, lee su cuenta: la ficha de B es la de B.
        $this->assertSame('Fredy Medina', $b->account()['name']);
        $peticion = collect(Http::recorded())->map(fn (array $p): Request => $p[0])->last();
        $this->assertSame('/v99.0/act_'.self::B, parse_url($peticion->url(), PHP_URL_PATH));
        $this->assertStringNotContainsString(self::ADS_TOKEN, $peticion->url());
        $this->assertSame(['Bearer '.self::ADS_TOKEN], $peticion->header('Authorization'));
    }

    // ── Dos cuentas, cada una con lo suyo ───────────────────────────────────

    /** Cada insight con su cuenta; el gasto consolidado es A + B exacto, y ninguna entidad sale dos veces. */
    public function test_dos_cuentas_cada_una_con_lo_suyo_y_la_suma_exacta(): void
    {
        $this->porCuenta = [
            self::A => [
                $this->insightRow('2026-10-02', '7001', '30000.50', ['campaign_id' => '501', 'campaign_name' => 'Mensajes oct', 'adset_id' => '511']),
                $this->insightRow('2026-10-03', '7002', '20000.00', ['campaign_id' => '501', 'campaign_name' => 'Mensajes oct', 'adset_id' => '511']),
            ],
            self::B => [
                $this->insightRow('2026-10-02', '8001', '1000.25', ['campaign_id' => '601', 'campaign_name' => 'Fredy vieja', 'adset_id' => '611']),
            ],
        ];

        $todo = $this->sync()->runAll('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        $this->assertSame('ok', $todo['status']);
        $this->assertFalse($todo['limited'], 'la vuelta no topó con ningún límite');
        $this->assertSame([self::A, self::B], array_map('strval', array_keys($todo['accounts'])));
        $this->assertSame([2, 1], array_column($todo['accounts'], 'rows_upserted'));
        $this->assertSame([false, false], array_column($todo['accounts'], 'limited'));

        // Una pasada (y una fila) por cuenta, y cada insight con su cuenta.
        $this->assertSame([[self::A, 'ok'], [self::B, 'ok']], MetaSyncRun::orderBy('id')->get()->map(fn ($r) => [$r->ad_account_id, $r->status])->all());
        $this->assertSame(
            [['7001', self::A], ['7002', self::A], ['8001', self::B]],
            MetaAdInsightDaily::orderBy('ad_id')->get()->map(fn ($r) => [$r->ad_id, $r->ad_account_id])->all(),
        );
        $this->assertSame(
            ['/v99.0/act_'.self::A.'/insights', '/v99.0/act_'.self::B.'/insights'],
            array_map(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH), $this->dailyInsightRequests()),
        );

        // El gasto: la suma exacta, y el de cada cuenta aparte.
        $gasto = $this->spendFor('2026-10-01', '2026-10-05');
        $this->assertSame(['ok', 51000.75, 'COP', true, null], [$gasto['status'], $gasto['amount'], $gasto['currency'], $gasto['complete'], $gasto['reason']]);
        $this->assertSame([50000.5, 1000.25], array_column($gasto['accounts'], 'amount'));
        $this->assertSame(['IronBody2', 'Fredy Medina'], array_column($gasto['accounts'], 'name'));
        $this->assertSame(['***4714', '***9023'], array_column($gasto['accounts'], 'ref'));
        $this->assertSame(MetaAdEntity::opaqueId('account', self::A), $gasto['accounts'][0]['id']);

        // Por campaña: una fila por entidad, cada una con su cuenta; suman lo mismo que el total.
        [$desde, $hasta] = $this->bogotaDays('2026-10-01', '2026-10-05');
        $campanas = $this->reader()->byLevel('campaign', $desde, $hasta, ['501', '601', '501']);
        $this->assertSame([['501', self::A, 50000.5], ['601', self::B, 1000.25]], $campanas->map(fn (array $r) => [$r['id'], $r['account_id'], $r['spend']])->all());
        $this->assertSame($gasto['amount'], round($campanas->sum('spend'), 2));
        $this->assertSame(['7001', '7002', '8001'], $this->reader()->byLevel('ad', $desde, $hasta)->pluck('id')->sort()->values()->all());

        // Por cuenta: las mismas sumas.
        $cuentas = $this->reader()->accounts($desde, $hasta);
        $this->assertSame([[self::A, 'IronBody2', 50000.5, 2000], [self::B, 'Fredy Medina', 1000.25, 1000]], array_map(fn (array $r) => [$r['account'], $r['name'], $r['spend'], $r['impressions']], $cuentas));

        $cobertura = $this->reader()->coverage($desde, $hasta);
        $this->assertSame(['2026-10-05', true, true], [$cobertura['through'], $cobertura['known'], $cobertura['complete']]);
    }

    /** Los ids de Meta son globales: si una entidad apareciera en dos cuentas, sale una vez y no se suma dos veces. */
    public function test_una_entidad_nunca_sale_dos_veces(): void
    {
        $this->porCuenta = [
            self::A => [$this->insightRow('2026-10-02', '7001', '500.00', ['campaign_id' => '901'])],
            self::B => [$this->insightRow('2026-10-02', '8001', '300.00', ['campaign_id' => '901'])],
        ];
        $this->sync()->runAll('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        [$desde, $hasta] = $this->bogotaDays('2026-10-01', '2026-10-05');
        $filas = $this->reader()->byLevel('campaign', $desde, $hasta, ['901']);
        $this->assertCount(1, $filas, 'una sola fila para la campaña 901');
        $this->assertSame([self::A, 500.0], [$filas->sole()['account_id'], $filas->sole()['spend']], 'la de la primera cuenta, sin sumarle la otra');
    }

    // ── Independencia ───────────────────────────────────────────────────────

    /** A falla: B sincroniza y sus datos quedan; los de A de antes, también. Ningún dato de una toca a la otra. */
    public function test_un_fallo_de_una_cuenta_no_toca_a_la_otra(): void
    {
        $this->porCuenta = [
            self::A => [$this->insightRow('2026-10-02', '7001', '30000.00', ['campaign_id' => '501'])],
            self::B => [$this->insightRow('2026-10-02', '8001', '1000.00', ['campaign_id' => '601'])],
        ];
        $this->assertSame('ok', $this->sync()->runAll('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);

        // A: Meta cae. B: trae un día nuevo.
        $this->fakeMeta(fn (Request $r) => $this->accountOf($r) === self::A
            ? Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2, 'is_transient' => true]], 503)
            : Http::response(['data' => [
                $this->insightRow('2026-10-02', '8001', '1000.00', ['campaign_id' => '601']),
                $this->insightRow('2026-10-03', '8001', '2000.00', ['campaign_id' => '601']),
            ]]));

        $todo = $this->sync()->runAll('schedule');

        $this->assertSame('partial', $todo['status']);
        $this->assertSame(['failed', 'transient', true], [$todo['accounts'][self::A]['status'], $todo['accounts'][self::A]['error_code'], $todo['accounts'][self::A]['retryable']]);
        $this->assertSame('ok', $todo['accounts'][self::B]['status']);

        $this->assertSame('30000.00', MetaAdInsightDaily::where('ad_account_id', self::A)->sole()->spend, 'lo de A sigue');
        $this->assertSame(['1000.00', '2000.00'], MetaAdInsightDaily::where('ad_account_id', self::B)->orderBy('date')->pluck('spend')->all());
        $this->assertSame(1, MetaSyncRun::where('ad_account_id', self::A)->where('status', 'failed')->count());
        $this->assertSame(0, MetaSyncRun::where('ad_account_id', self::B)->where('status', 'failed')->count());

        // El gasto de un periodo que A sí había cubierto se sigue sabiendo; el de su hoy, no.
        $this->assertSame(33000.0, $this->spendFor('2026-10-01', '2026-10-03')['amount']);
        $this->assertSame('sync_failed', $this->reader()->forAccount(self::A)->snapshot(...$this->bogotaDays('2026-09-29', '2026-10-05'))['status']);
    }

    /** Con un límite de Meta en una cuenta, las siguientes NO se piden en esa vuelta: el cupo es de la app. */
    public function test_un_limite_en_una_cuenta_salta_las_siguientes(): void
    {
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B, self::C]]);
        $this->fakeMeta(fn (Request $r) => $this->accountOf($r) === self::A
            ? $this->graphError(17, 'User request limit reached', 400, ['error_subcode' => 2446079])
            : Http::response(['data' => []]));

        $todo = $this->sync()->runAll('schedule');

        $this->assertSame('failed', $todo['status']);
        $this->assertTrue($todo['limited']);
        $this->assertSame(['failed', 'rate_limit'], [$todo['accounts'][self::A]['status'], $todo['accounts'][self::A]['error_code']]);
        foreach ([self::B, self::C] as $cuenta) {
            $this->assertSame(['account' => $cuenta, 'status' => 'skipped', 'reason' => 'rate_limit'], $todo['accounts'][$cuenta]);
        }
        $this->assertSame([self::A], collect(Http::recorded())->map(fn (array $p) => $this->accountOf($p[0]))->unique()->values()->all(), 'a B y a C no se les pidió nada');
        $this->assertSame([self::A], MetaSyncRun::pluck('ad_account_id')->all());

        // Un límite en una petición accesoria de A (los estados) también frena la vuelta; lo de A se guarda.
        $this->fakeMeta(fn () => Http::response(['data' => [$this->insightRow('2026-10-02', '7001', '100.00')]]),
            fn () => $this->graphError(17, 'User request limit reached', 400, ['error_subcode' => 2446079]));
        $todo = $this->sync()->runAll('schedule');
        $this->assertSame(['ok', true], [$todo['accounts'][self::A]['status'], $todo['accounts'][self::A]['limited']]);
        $this->assertSame(['skipped', 'skipped'], [$todo['accounts'][self::B]['status'], $todo['accounts'][self::C]['status']]);
        $this->assertSame('partial', $todo['status']);
        $this->assertSame(1, MetaAdInsightDaily::where('ad_account_id', self::A)->count());

        // Si el límite llega en la segunda, la primera ya quedó y la tercera se salta.
        $this->fakeMeta(fn (Request $r) => $this->accountOf($r) === self::B
            ? $this->graphError(80000, 'There have been too many calls from this ad-account.')
            : Http::response(['data' => []]), ['data' => []]);
        $todo = $this->sync()->runAll('schedule');
        $this->assertSame(['ok', 'failed', 'skipped'], array_column($todo['accounts'], 'status'));
        $this->assertSame('partial', $todo['status']);
        $this->assertTrue($todo['limited']);

        // En la ÚLTIMA cuenta no queda nadie a quien saltar, pero la vuelta también topó con el
        // límite: lo dice `limited`, para que quien la llamó no pida nada más (el alcance).
        $this->fakeMeta(fn (Request $r) => $this->accountOf($r) === self::C
            ? $this->graphError(17, 'User request limit reached', 400, ['error_subcode' => 2446079])
            : Http::response(['data' => []]), ['data' => []]);
        $todo = $this->sync()->runAll('schedule');
        $this->assertSame(['ok', 'ok', 'failed'], array_column($todo['accounts'], 'status'));
        $this->assertSame([false, false, false], array_column($todo['accounts'], 'limited'), 'el límite fue en la petición principal, no en una accesoria');
        $this->assertTrue($todo['limited']);
    }

    /** Una cuenta que no está conectada no entra en la vuelta: se rechaza antes de pedir nada. */
    public function test_runall_solo_con_cuentas_conectadas(): void
    {
        try {
            $this->sync()->runAll('manual', null, null, [self::A, '999']);
            $this->fail('Una cuenta no conectada no se sincroniza.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], Http::recorded()->all());
            $this->assertSame(0, MetaSyncRun::count());
        }

        $this->porCuenta = [self::B => [$this->insightRow('2026-10-02', '8001', '10.00')]];
        $todo = $this->sync()->runAll('manual', null, null, ['act_'.self::B]);
        $this->assertSame([self::B], array_map('strval', array_keys($todo['accounts'])));
        $this->assertSame([self::B], MetaSyncRun::pluck('ad_account_id')->all());

        // Sin token: no se llama a Meta y queda constancia, como siempre.
        $this->configureMetaAds(['access_token' => '', 'ad_account_ids' => [self::A, self::B]]);
        $this->assertSame(['status' => 'not_configured', 'reason' => 'missing_access_token', 'accounts' => []], $this->sync()->runAll());
        $this->assertSame('not_configured', MetaSyncRun::latest('id')->first()->status);
    }

    // ── El cerrojo es por cuenta ────────────────────────────────────────────

    /** Con A ocupada, B sincroniza igual; la fila `running` de A no la cierra la pasada de B. */
    public function test_el_cerrojo_es_por_cuenta(): void
    {
        $this->assertSame('meta-ads-sync:'.self::A, $this->sync()->lockKey(), 'sin ligar, el de la principal');
        $this->assertSame('meta-ads-sync:'.self::B, $this->sync()->forAccount(self::B)->lockKey());

        $viva = MetaSyncRun::create([
            'kind' => 'insights', 'status' => 'running', 'trigger' => 'schedule', 'ad_account_id' => self::A,
            'range_from' => '2026-09-29', 'range_to' => '2026-10-05', 'started_at' => now()->subMinute(),
        ]);
        $cerrojo = Cache::lock('meta-ads-sync:'.self::A, 900);
        $this->assertTrue($cerrojo->get());

        $this->porCuenta = [self::B => [$this->insightRow('2026-10-02', '8001', '1000.00')]];
        $todo = $this->sync()->runAll('schedule');

        $this->assertSame(['running', 'ok'], [$todo['accounts'][self::A]['status'], $todo['accounts'][self::B]['status']]);
        $this->assertSame('partial', $todo['status']);
        $this->assertSame([self::B], collect(Http::recorded())->map(fn (array $p) => $this->accountOf($p[0]))->unique()->values()->all());
        $this->assertSame('running', $viva->fresh()->status, 'la pasada de B no toca las filas de A');
        $this->assertSame('running', $this->sync()->forAccount(self::A)->status()['status']);

        $cerrojo->release();
    }

    // ── Estado consolidado ──────────────────────────────────────────────────

    /** statusAll(): el peor estado, el éxito más viejo, el intento más reciente y la intersección de la cobertura. */
    public function test_el_estado_de_todas_las_cuentas(): void
    {
        $pasada = fn (string $cuenta, string $estado, string $desde, string $hasta, string $a, ?string $error = null) => MetaSyncRun::create([
            'kind' => 'insights', 'status' => $estado, 'trigger' => 'schedule', 'ad_account_id' => $cuenta,
            'range_from' => $desde, 'range_to' => $hasta, 'started_at' => $a, 'finished_at' => $a,
            'error_code' => $error, 'error_message' => $error === null ? null : 'Meta respondió 503.',
        ]);
        $pasada(self::A, 'ok', '2026-09-29', '2026-10-05', '2026-10-05 13:00:00');
        $pasada(self::B, 'ok', '2026-10-01', '2026-10-05', '2026-10-05 14:30:00');
        MetaAdEntity::create(['ad_account_id' => self::B, 'level' => 'account', 'entity_id' => self::B, 'name' => 'Fredy 3001234567', 'synced_at' => now()]);

        $estado = $this->sync()->statusAll();
        $this->assertSame(
            ['ok', true, null, '2026-10-05T13:00:00+00:00', '2026-10-05T14:30:00+00:00', 120, null, '2026-10-01', '2026-10-05'],
            [$estado['status'], $estado['configured'], $estado['reason'], $estado['last_success_at'], $estado['last_attempt_at'],
                $estado['minutes_since_success'], $estado['last_error'], $estado['covered_from'], $estado['covered_to']],
        );
        $this->assertSame(
            ['status', 'configured', 'reason', 'last_success_at', 'last_attempt_at', 'minutes_since_success', 'last_error', 'covered_from', 'covered_to', 'accounts'],
            array_keys($estado),
        );
        // Cada cuenta, con su último intento (ISO, como el consolidado).
        $this->assertSame([
            ['id' => MetaAdEntity::opaqueId('account', self::A), 'ref' => '***4714', 'name' => null, 'status' => 'ok',
                'last_success_at' => '2026-10-05T13:00:00+00:00', 'last_attempt_at' => '2026-10-05T13:00:00+00:00',
                'minutes_since_success' => 120, 'last_error' => null, 'covered_from' => '2026-09-29', 'covered_to' => '2026-10-05'],
            ['id' => MetaAdEntity::opaqueId('account', self::B), 'ref' => '***9023', 'name' => 'Fredy ***4567', 'status' => 'ok',
                'last_success_at' => '2026-10-05T14:30:00+00:00', 'last_attempt_at' => '2026-10-05T14:30:00+00:00',
                'minutes_since_success' => 30, 'last_error' => null, 'covered_from' => '2026-10-01', 'covered_to' => '2026-10-05'],
        ], $estado['accounts']);

        // Sin solaparse, no hay intersección.
        MetaSyncRun::where('ad_account_id', self::B)->update(['range_from' => '2026-09-10', 'range_to' => '2026-09-20']);
        $this->assertSame([null, null], array_values(array_intersect_key($this->sync()->statusAll(), array_flip(['covered_from', 'covered_to']))));
        MetaSyncRun::where('ad_account_id', self::B)->update(['range_from' => '2026-10-01', 'range_to' => '2026-10-05']);

        // Vieja una: el estado es «stale» y el éxito es el más viejo.
        Carbon::setTestNow('2026-10-05 16:10:00');
        $estado = $this->sync()->statusAll();
        $this->assertSame(['stale', 190], [$estado['status'], $estado['minutes_since_success']]);

        // Falla una: «failed», con su error y su cuenta enmascarada.
        $pasada(self::B, 'failed', '2026-09-29', '2026-10-05', '2026-10-05 16:00:00', 'transient');
        $estado = $this->sync()->statusAll();
        $this->assertSame('failed', $estado['status']);
        $this->assertSame(['code' => 'transient', 'message' => 'Meta respondió 503.', 'account_id' => '***9023'], $estado['last_error']);
        $this->assertSame('2026-10-05T16:00:00+00:00', $estado['last_attempt_at']);
        // El intento fallido es de B: su fila lo dice; el éxito sigue siendo el de antes.
        $this->assertSame(
            [['2026-10-05T13:00:00+00:00', '2026-10-05T13:00:00+00:00'], ['2026-10-05T16:00:00+00:00', '2026-10-05T14:30:00+00:00']],
            array_map(fn (array $c) => [$c['last_attempt_at'], $c['last_success_at']], $estado['accounts']),
        );
        $this->assertStringNotContainsString(self::B, json_encode($estado));

        // Una en curso pesa más que el fallo de otra.
        MetaSyncRun::create(['kind' => 'insights', 'status' => 'running', 'trigger' => 'manual', 'ad_account_id' => self::A,
            'range_from' => '2026-10-05', 'range_to' => '2026-10-05', 'started_at' => now()]);
        $this->assertSame('running', $this->sync()->statusAll()['status']);

        // Una cuenta que nunca sincronizó: sin «éxito más viejo», y su fila sin intento.
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B, self::C]]);
        $estado = $this->sync()->statusAll();
        $this->assertSame([null, null], [$estado['last_success_at'], $estado['minutes_since_success']]);
        $this->assertSame(['stale', null], [$estado['accounts'][2]['status'], $estado['accounts'][2]['last_attempt_at']]);

        // Sin token: sin conexión, con el motivo de siempre.
        $this->configureMetaAds(['access_token' => '', 'ad_account_ids' => [self::A]]);
        $estado = $this->sync()->statusAll();
        $this->assertSame(['not_configured', false, 'missing_access_token'], [$estado['status'], $estado['configured'], $estado['reason']]);
    }

    // ── Consolidación del gasto ─────────────────────────────────────────────

    /** Si una cuenta no tiene datos que cubran el periodo, el total no se afirma: no es la suma de las demás. */
    public function test_sin_datos_de_una_cuenta_el_total_no_se_sabe(): void
    {
        $this->porCuenta = [
            self::A => [$this->insightRow('2026-10-02', '7001', '30000.00')],
            self::B => [$this->insightRow('2026-10-04', '8001', '1000.00')],
        ];
        $this->sync()->forAccount(self::A)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));
        $this->sync()->forAccount(self::B)->run('manual', $this->day('2026-10-04'), $this->day('2026-10-05'));

        $gasto = $this->spendFor('2026-10-01', '2026-10-05');
        $this->assertSame(['unknown', null, null, 'not_covered', true], [$gasto['status'], $gasto['amount'], $gasto['currency'], $gasto['reason'], $gasto['complete']]);
        $this->assertSame([['ok', 30000.0], ['unknown', null]], array_map(fn (array $c) => [$c['status'], $c['amount']], $gasto['accounts']));

        // Los días que las dos cubren, sí.
        $this->assertSame(['ok', 1000.0], array_values(array_intersect_key($this->spendFor('2026-10-04', '2026-10-05'), array_flip(['status', 'amount']))));
    }

    /** Alguna parcial: la suma de lo conocido, incompleta, hasta el día más temprano y con el peor estado. */
    public function test_con_una_cuenta_parcial_el_total_es_parcial_hasta_el_minimo(): void
    {
        $this->porCuenta = [
            self::A => [$this->insightRow('2026-10-02', '7001', '30000.00'), $this->insightRow('2026-10-03', '7001', '5000.00')],
            self::B => [$this->insightRow('2026-10-02', '8001', '1000.00'), $this->insightRow('2026-10-04', '8001', '700.00')],
        ];
        // A: datos buenos hasta el 3 y la pasada de hoy falla. B: datos buenos hasta el 4, sin fallos.
        $this->sync()->forAccount(self::A)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-03'));
        $this->sync()->forAccount(self::B)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-04'));
        $this->fakeMeta(fn () => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2, 'is_transient' => true]], 503));
        $this->assertSame('failed', $this->sync()->forAccount(self::A)->run('schedule')['status']);

        // Hasta el 3, el día común: 35.000 de A y 1.000 de B. Los 700 de B del día 4 no entran (antes
        // salían 36.700 rotulados «hasta el 3»: la suma de cada cuenta hasta su propio día).
        $gasto = $this->spendFor('2026-10-01', '2026-10-05');
        $this->assertSame(
            ['sync_failed', 36000.0, 'COP', '2026-10-03', 'transient', false],
            [$gasto['status'], $gasto['amount'], $gasto['currency'], $gasto['covered_to'], $gasto['reason'], $gasto['complete']],
        );
        $this->assertSame(
            [['sync_failed', 35000.0, false, '2026-10-03'], ['stale', 1700.0, false, '2026-10-04']],
            array_map(fn (array $c) => [$c['status'], $c['amount'], $c['complete'], $c['covered_to']], $gasto['accounts']),
        );
        $this->assertSame(['through' => '2026-10-03', 'known' => true, 'complete' => false], array_intersect_key(
            $this->reader()->coverage(...$this->bogotaDays('2026-10-01', '2026-10-05')), array_flip(['known', 'complete', 'through']),
        ));

        // Sin fallos, la peor es «stale» (parcial).
        MetaSyncRun::where('status', 'failed')->delete();
        $gasto = $this->spendFor('2026-10-01', '2026-10-05');
        $this->assertSame(['stale', 'partial', '2026-10-03', false], [$gasto['status'], $gasto['reason'], $gasto['covered_to'], $gasto['complete']]);
    }

    /**
     * Con una cuenta parcial, TODO lo consolidado se corta en el mismo día común
     * D (el más temprano que cubren todas): el importe, las filas de campaña y
     * las de cada cuenta son lo gastado hasta D, también en la cuenta completa
     * que siguió gastando después. Así «hasta D» es verdad en la tarjeta, en la
     * tabla y en las filas. Con una sola cuenta, nada cambia.
     */
    public function test_lo_consolidado_parcial_se_corta_en_el_dia_comun(): void
    {
        $this->porCuenta = [
            // A, completa: gasta el 2, el 4 y el 5 (los dos últimos, después del día común).
            self::A => [
                $this->insightRow('2026-10-02', '7001', '10000.00', ['campaign_id' => '501', 'adset_id' => '511']),
                $this->insightRow('2026-10-04', '7001', '5000.00', ['campaign_id' => '501', 'adset_id' => '511']),
                $this->insightRow('2026-10-05', '7002', '3000.00', ['campaign_id' => '502', 'adset_id' => '521']),
            ],
            // B: datos buenos hasta el 3, y la pasada de hoy falla.
            self::B => [
                $this->insightRow('2026-10-02', '8001', '1000.00', ['campaign_id' => '601', 'adset_id' => '611']),
                $this->insightRow('2026-10-03', '8001', '500.00', ['campaign_id' => '601', 'adset_id' => '611']),
                $this->insightRow('2026-10-04', '8001', '700.00', ['campaign_id' => '601', 'adset_id' => '611']),
            ],
        ];
        // Como Meta: solo las filas del rango pedido.
        $this->fakeMeta(function (Request $r) {
            $rango = json_decode($this->queryOf($r)['time_range'] ?? '{}', true);

            return Http::response(['data' => array_values(array_filter(
                $this->porCuenta[$this->accountOf($r)] ?? [],
                fn (array $f): bool => $f['date_start'] >= ($rango['since'] ?? '') && $f['date_start'] <= ($rango['until'] ?? '9999'),
            ))]);
        });
        $this->assertSame('ok', $this->sync()->forAccount(self::A)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);
        $this->assertSame('ok', $this->sync()->forAccount(self::B)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-03'))['status']);
        $this->fakeMeta(fn () => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2, 'is_transient' => true]], 503));
        $this->assertSame('failed', $this->sync()->forAccount(self::B)->run('schedule')['status']);
        [$desde, $hasta] = $this->bogotaDays('2026-10-01', '2026-10-05');

        // La tarjeta: hasta el 3 en las dos, 10.000 + 1.500 (no los 19.500 de cada una hasta su día).
        $gasto = $this->reader()->snapshot($desde, $hasta);
        $this->assertSame(
            ['sync_failed', 11500.0, 'COP', '2026-10-03', 'transient', false],
            [$gasto['status'], $gasto['amount'], $gasto['currency'], $gasto['covered_to'], $gasto['reason'], $gasto['complete']],
        );
        // El detalle por cuenta sigue siendo el de cada una, con su propia fecha.
        $this->assertSame(
            [['ok', 18000.0, true], ['sync_failed', 1500.0, false]],
            array_map(fn (array $c) => [$c['status'], $c['amount'], $c['complete']], $gasto['accounts']),
        );
        $this->assertSame(['through' => '2026-10-03', 'known' => true, 'complete' => false], array_intersect_key(
            $this->reader()->coverage($desde, $hasta), array_flip(['known', 'complete', 'through']),
        ));

        // La tabla: las campañas de A, también hasta el 3 y parciales; la 502 (solo gastó el 5) no sale.
        $campanas = $this->reader()->byLevel('campaign', $desde, $hasta);
        $this->assertSame(
            [['501', self::A, 10000.0, 1000, true, '2026-10-02'], ['601', self::B, 1500.0, 2000, true, '2026-10-03']],
            $campanas->map(fn (array $r) => [$r['id'], $r['account_id'], $r['spend'], $r['impressions'], $r['partial'], $r['last_day']])->all(),
        );
        $this->assertSame($gasto['amount'], round($campanas->sum('spend'), 2));
        // Si un lead lleva a la 502, sale con el cero comprobado hasta el 3, no con lo del 5.
        $this->assertSame(
            [0.0, 0, true],
            array_values(array_intersect_key($this->reader()->byLevel('campaign', $desde, $hasta, ['502'])->firstWhere('id', '502'), array_flip(['spend', 'impressions', 'partial']))),
        );
        $this->assertSame([['7001', 10000.0], ['8001', 1500.0]], $this->reader()->byLevel('ad', $desde, $hasta)->map(fn (array $r) => [$r['id'], $r['spend']])->all());

        // Las filas por cuenta: A sin lo del 4 ni lo del 5, parcial y con la misma fecha.
        $cuentas = $this->reader()->accounts($desde, $hasta);
        $this->assertSame(
            [[self::A, 10000.0, 1000, true, '2026-10-03', '2026-10-02'], [self::B, 1500.0, 2000, true, '2026-10-03', '2026-10-03']],
            array_map(fn (array $r) => [$r['account'], $r['spend'], $r['impressions'], $r['partial'], $r['covered_to'], $r['last_day']], $cuentas),
        );
        $this->assertSame($gasto['amount'], round(array_sum(array_column($cuentas, 'spend')), 2));

        // Con una sola cuenta conectada, nada cambia: la de A, entera.
        $this->configureMetaAds(['ad_account_ids' => [self::A]]);
        $sola = $this->reader()->snapshot($desde, $hasta);
        $this->assertSame(['ok', 18000.0, true], [$sola['status'], $sola['amount'], $sola['complete']]);
        $this->assertSame([[self::A, 18000.0, false, null]], array_map(fn (array $r) => [$r['account'], $r['spend'], $r['partial'], $r['covered_to']], $this->reader()->accounts($desde, $hasta)));
        $this->assertSame([['501', 15000.0, false], ['502', 3000.0, false]], $this->reader()->byLevel('campaign', $desde, $hasta)->map(fn (array $r) => [$r['id'], $r['spend'], $r['partial']])->all());
    }

    /** Gasto en dos monedas entre cuentas: no se suma. Ceros de verdad en todas: cero comprobado. */
    public function test_monedas_distintas_y_ceros_de_verdad(): void
    {
        $this->porCuenta = [
            self::A => [$this->insightRow('2026-10-02', '7001', '30000.00')],
            self::B => [$this->insightRow('2026-10-02', '8001', '15.00', ['account_currency' => 'USD'])],
        ];
        $this->sync()->runAll('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        $gasto = $this->spendFor('2026-10-01', '2026-10-05');
        $this->assertSame(['unknown', null, null, 'mixed_currency'], [$gasto['status'], $gasto['amount'], $gasto['currency'], $gasto['reason']]);
        $this->assertSame([['COP', 30000.0], ['USD', 15.0]], array_map(fn (array $c) => [$c['currency'], $c['amount']], $gasto['accounts']));

        // Una cuenta con 0 en otra moneda no mezcla nada: solo cuenta la moneda de quien gastó.
        MetaAdInsightDaily::where('ad_account_id', self::B)->update(['spend' => '0.00']);
        $gasto = $this->spendFor('2026-10-01', '2026-10-05');
        $this->assertSame(['ok', 30000.0, 'COP'], [$gasto['status'], $gasto['amount'], $gasto['currency']]);

        // Nada gastado en ninguna: cero comprobado.
        MetaAdInsightDaily::query()->update(['spend' => '0.00']);
        $gasto = $this->spendFor('2026-10-01', '2026-10-05');
        $this->assertSame(['real_zero', 0.0, null, true], [$gasto['status'], $gasto['amount'], $gasto['reason'], $gasto['complete']]);

        // Vieja una: sigue siendo cero comprobado, con el aviso.
        Carbon::setTestNow(now()->addMinutes(181));
        $gasto = $this->spendFor('2026-10-01', '2026-10-04');
        $this->assertSame(['real_zero', 0.0, 'stale'], [$gasto['status'], $gasto['amount'], $gasto['reason']]);
        $this->assertSame('2026-10-05T15:00:00+00:00', $gasto['last_synced_at'], 'el éxito más viejo');
    }

    /**
     * La moneda del total sale SOLO de las cuentas que gastaron. A gasta sin
     * moneda conocida y B no gasta, en COP: el total no hereda la COP de B, va
     * sin moneda, como el de A sola, y el panel no divide con él. La de una
     * cuenta en cero solo vale si nadie gastó.
     */
    public function test_la_moneda_del_total_sale_solo_de_quien_gasto(): void
    {
        config()->set('marketing.attribution.coverage_since', '2026-01-01');
        $this->porCuenta = [
            self::A => [$this->insightRow('2026-10-02', '7001', '30000.00', ['account_currency' => null])],
            self::B => [$this->insightRow('2026-10-02', '8001', '0.00')],
        ];
        $this->assertSame('ok', $this->sync()->runAll('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);

        $solaA = $this->reader()->forAccount(self::A)->snapshot(...$this->bogotaDays('2026-10-01', '2026-10-05'));
        $this->assertSame(['ok', 30000.0, null], [$solaA['status'], $solaA['amount'], $solaA['currency']]);

        $gasto = $this->spendFor('2026-10-01', '2026-10-05');
        $this->assertSame(['ok', 30000.0, null, null], [$gasto['status'], $gasto['amount'], $gasto['currency'], $gasto['reason']]);
        $this->assertSame([[30000.0, null], [0.0, 'COP']], array_map(fn (array $c) => [$c['amount'], $c['currency']], $gasto['accounts']));

        // Sin moneda, nada se divide ni se resta con la caja en pesos: igual que con una sola cuenta.
        $panel = app(MetaDashboardService::class);
        $k = $panel->dashboard($panel->period('custom', '2026-10-01', '2026-10-05'))['kpis'];
        $this->assertSame(
            ['currency_mismatch', 'currency_mismatch', 'currency_mismatch', null, null],
            [$k['roas_reason'], $k['cac_reason'], $k['return_after_ad_spend_reason'], $k['roas'], $k['return_after_ad_spend']],
        );

        // Nadie gastó: el cero comprobado dice la moneda que se conoce.
        MetaAdInsightDaily::where('ad_account_id', self::A)->update(['spend' => '0.00']);
        $gasto = $this->spendFor('2026-10-01', '2026-10-05');
        $this->assertSame(['real_zero', 0.0, 'COP'], [$gasto['status'], $gasto['amount'], $gasto['currency']]);

        // Las dos gastan, una sin moneda y otra en COP: no se suman (no se sabe si son la misma moneda).
        MetaAdInsightDaily::where('ad_account_id', self::A)->update(['spend' => '30000.00']);
        MetaAdInsightDaily::where('ad_account_id', self::B)->update(['spend' => '5000.00']);
        $gasto = $this->spendFor('2026-10-01', '2026-10-05');
        $this->assertSame(['unknown', null, 'mixed_currency'], [$gasto['status'], $gasto['amount'], $gasto['reason']]);
    }

    // ── Alcance ─────────────────────────────────────────────────────────────

    /** El alcance no se suma entre cuentas: con 0 o con 2 cuentas activas en el rango, null; con 1, su foto. */
    public function test_el_alcance_consolidado_no_se_suma(): void
    {
        $foto = fn (string $cuenta, int $alcance) => DB::table('meta_ad_reach_snapshots')->insert([
            'ad_account_id' => $cuenta, 'level' => MetaAdReachSnapshot::LEVEL_ACCOUNT, 'entity_id' => $cuenta,
            'date_from' => '2026-09-06', 'date_to' => '2026-10-05', 'reach' => $alcance, 'impressions' => 9000, 'frequency' => 1.5,
            'synced_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $impresiones = fn (string $cuenta, int $n) => MetaAdInsightDaily::create([
            'ad_account_id' => $cuenta, 'date' => '2026-10-02', 'ad_id' => $cuenta.'01', 'spend' => '10.00', 'currency' => 'COP', 'impressions' => $n,
        ]);
        $foto(self::A, 5000);
        $foto(self::B, 300);

        $this->assertNull($this->reader()->accountReach('2026-09-06', '2026-10-05'), 'ninguna cuenta con impresiones');

        $impresiones(self::A, 1000);
        $impresiones(self::B, 0);
        $this->assertSame(5000, $this->reader()->accountReach('2026-09-06', '2026-10-05')['reach'], 'solo A tuvo impresiones');
        $this->assertNull($this->reader()->accountReach('2026-10-01', '2026-10-05'), 'A activa, pero sin foto de ese rango');
        $this->assertNull($this->reader()->accountReach('2026-10-03', '2026-10-05'), 'ninguna cuenta con impresiones en ese rango');

        MetaAdInsightDaily::where('ad_account_id', self::B)->update(['impressions' => 10]);
        $this->assertNull($this->reader()->accountReach('2026-09-06', '2026-10-05'), 'dos cuentas: el alcance no se suma');
        $this->assertSame(300, $this->reader()->forAccount(self::B)->accountReach('2026-09-06', '2026-10-05')['reach'], 'el de cada cuenta, aparte');

        // En la fila de cada cuenta, su foto exacta.
        $filas = collect($this->reader()->accounts(...$this->bogotaDays('2026-09-06', '2026-10-05')))->keyBy('account');
        $this->assertNull($filas[self::A]['spend'], 'sin pasada que cubra el periodo, sin cifras');
    }

    /** El alcance de una cuenta sin impresiones en 62 días no se pide (cupo): `skipped_inactive`, sin escribir ceros. */
    public function test_el_alcance_salta_las_cuentas_inactivas(): void
    {
        $this->fakeMetaExtras(reach: ['data' => [['reach' => '10', 'impressions' => '20', 'frequency' => '2']]]);
        // A tuvo impresiones justo hace 62 días; C, hace 63.
        MetaAdInsightDaily::create(['ad_account_id' => self::A, 'date' => '2026-08-04', 'ad_id' => '7001', 'spend' => '1', 'currency' => 'COP', 'impressions' => 5]);
        MetaAdInsightDaily::create(['ad_account_id' => self::C, 'date' => '2026-08-03', 'ad_id' => '9001', 'spend' => '1', 'currency' => 'COP', 'impressions' => 5]);
        // B tuvo filas recientes, pero sin impresiones.
        MetaAdInsightDaily::create(['ad_account_id' => self::B, 'date' => '2026-10-01', 'ad_id' => '8001', 'spend' => '0', 'currency' => 'COP', 'impressions' => 0]);
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B, self::C]]);

        $this->assertSame(['status' => 'ok', 'ranges_ok' => 5, 'ranges_failed' => 0, 'rows' => 5], $this->sync()->forAccount(self::A)->refreshReach());
        foreach ([self::B, self::C] as $cuenta) {
            $this->assertSame(['status' => 'skipped_inactive', 'ranges_ok' => 0, 'ranges_failed' => 0, 'rows' => 0], $this->sync()->forAccount($cuenta)->refreshReach());
        }

        $this->assertSame([self::A], collect($this->requestsTo('insights'))->map(fn (Request $r) => $this->accountOf($r))->unique()->values()->all());
        $this->assertSame(0, MetaAdReachSnapshot::whereIn('ad_account_id', [self::B, self::C])->count(), 'ningún 0 inventado');
    }

    // ── El nombre de la cuenta ──────────────────────────────────────────────

    /** Cada pasada guarda el nombre de su cuenta (nivel `account`) y no lo vuelve a pedir en 24 horas. */
    public function test_el_nombre_de_la_cuenta_se_guarda_y_se_renueva(): void
    {
        $this->assertSame(['campaign', 'adset', 'ad'], MetaAdEntity::LEVELS, 'la cuenta no es un nivel de la tabla');
        $fichas = fn (): int => count(array_filter(Http::recorded()->all(), fn (array $p) => preg_match('#/act_\d+$#', (string) parse_url($p[0]->url(), PHP_URL_PATH)) === 1));

        $this->sync()->runAll('schedule');
        $this->assertSame(2, $fichas());
        $this->assertSame([self::A => 'IronBody2', self::B => 'Fredy Medina'], MetaAdEntity::accountNames([self::A, self::B]));
        $fila = MetaAdEntity::where('level', 'account')->where('ad_account_id', self::A)->sole();
        $this->assertSame([self::A, null, null], [$fila->entity_id, $fila->campaign_id, $fila->adset_id]);
        $this->assertSame(MetaAdsApiClient::ACCOUNT_FIELDS, $this->queryOf(collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn (Request $r) => preg_match('#/act_\d+$#', (string) parse_url($r->url(), PHP_URL_PATH)) === 1))['fields']);

        Carbon::setTestNow(now()->addHours(23));
        $this->sync()->runAll('schedule');
        $this->assertSame(2, $fichas(), 'en menos de 24 horas no se vuelve a pedir');

        // Pasadas 24 horas se renueva; si Meta falla, la pasada sigue y el nombre se queda.
        Carbon::setTestNow(now()->addHours(2));
        $this->fakeMetaExtras(account: fn (Request $r) => $this->accountOf($r) === self::A
            ? Http::response(['id' => 'act_'.self::A, 'name' => 'IronBody2 (nueva)'])
            : $this->graphError(1, 'An unknown error has occurred.', 500));
        $this->assertSame('ok', $this->sync()->runAll('schedule')['status']);
        $this->assertSame(4, $fichas());
        $this->assertSame([self::A => 'IronBody2 (nueva)', self::B => 'Fredy Medina'], MetaAdEntity::accountNames([self::A, self::B]));

        // Sin nombre en la respuesta, se conserva el que había.
        Carbon::setTestNow(now()->addHours(25));
        $this->fakeMetaExtras(account: ['id' => 'act_x']);
        $this->sync()->forAccount(self::A)->run('schedule');
        $this->assertSame('IronBody2 (nueva)', MetaAdEntity::accountNames([self::A])[self::A]);
    }

    // ── Métricas que reporta Meta ───────────────────────────────────────────

    /** Una métrica existe si alguna cuenta la reporta; para las filas de cada cuenta, la suya. */
    public function test_las_metricas_reportadas_son_por_cuenta(): void
    {
        $this->porCuenta = [
            self::A => [$this->insightRow('2026-10-02', '7001', '100.00', ['actions' => [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '4']]])],
            self::B => [$this->insightRow('2026-10-02', '8001', '100.00')],
        ];
        $this->sync()->runAll('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        $this->assertSame(['messaging_started' => true, 'messaging_replied' => false, 'landing_page_views' => false, 'reach' => false], $this->reader()->metricsAvailable());
        $porCuenta = $this->reader()->availabilityByAccount();
        $this->assertTrue($porCuenta[self::A]['messaging_started']);
        $this->assertFalse($porCuenta[self::B]['messaging_started']);
        $this->assertFalse($this->reader()->forAccount(self::B)->metricsAvailable()['messaging_started']);
    }
}
