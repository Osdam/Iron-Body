<?php

namespace Tests\Feature\Marketing\Attribution;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\MarketingLead;
use App\Models\MetaAdEntity;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Attribution\MetaDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Marketing\MetaAds\FakesMetaAds;
use Tests\TestCase;

/**
 * El panel con VARIAS cuentas publicitarias conectadas: el gasto y la pauta
 * consolidados sin contar nada dos veces, la cobertura de pauta, el desglose
 * cuenta → campaña, la pauta de una cuenta no conectada (sin dinero inventado)
 * y el resumen para administración con su conclusión.
 *
 * Reloj: 5 de octubre de 2026, 10:00 en Bogotá (15:00 UTC). Periodo de casi
 * todas las pruebas: del 1 al 5 de octubre.
 */
class MetaDashboardMulticuentaTest extends TestCase
{
    use FakesMetaAds, RefreshDatabase, SeedsAttribution;

    private const BASE = '/api/admin/marketing/meta-dashboard';

    /** IronBody2: la pauta de hoy. */
    private const A = '1759800000004714';

    /** Fredy Medina: el histórico, sin gasto en octubre. */
    private const B = '1577100000009023';

    /** IRONBODY: conectada, sin actividad. */
    private const C = '3611000000005856';

    private const NAMES = [self::A => 'IronBody2', self::B => 'Fredy Medina', self::C => 'IRONBODY cuenta publicitaria'];

    /** Los anuncios de IronBody2 que traen leads, como los de producción (sufijo …0387). */
    private const AD_A1 = '120253617613620387';

    private const AD_A2 = '120253617613610387';

    private const AD_A3 = '120253617613630387';

    private const AD_A4 = '120253043436250387';

    /** Los de la cuenta EXTERNA, no conectada (sufijo …0062). */
    private const AD_X1 = '120251095823850062';

    private const AD_X2 = '120248492523300062';

    /** @var array<string, list<array<string, mixed>>> filas de /insights por cuenta */
    private array $porCuenta = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.attribution.coverage_since', '2026-01-01');
        Carbon::setTestNow('2026-10-05 15:00:00');
        config()->set('caja.timezone', 'America/Bogota');
        config()->set('marketing.attribution.window_days', 30);
        config()->set('marketing.attribution.phone_index_cache_seconds', 0);
        foreach (Admin::ROLES as $rol) {
            AdminRole::firstOrCreate(['name' => $rol], ['is_system' => true]);
        }

        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B, self::C]]);
        $this->fakeMeta(function (Request $r) {
            $rango = json_decode($this->queryOf($r)['time_range'] ?? '{}', true);

            return Http::response(['data' => array_values(array_filter(
                $this->porCuenta[$this->accountOf($r)] ?? [],
                fn (array $f): bool => $f['date_start'] >= ($rango['since'] ?? '') && $f['date_start'] <= ($rango['until'] ?? '9999'),
            ))]);
        });
        $this->fakeMetaExtras(account: fn (Request $r) => Http::response([
            'id' => 'act_'.$this->accountOf($r), 'account_id' => $this->accountOf($r), 'name' => self::NAMES[$this->accountOf($r)] ?? null,
            'currency' => 'COP', 'timezone_name' => 'America/Bogota', 'account_status' => 1,
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): MetaDashboardService
    {
        return app(MetaDashboardService::class);
    }

    /** @return array<string, mixed> */
    private function dashboard(string $from = '2026-10-01', string $to = '2026-10-05', bool $money = true): array
    {
        return $this->service()->dashboard($this->service()->period('custom', $from, $to), $money);
    }

    /** IronBody2 gasta 41.516 COP del 2 al 4 de octubre en sus cuatro anuncios con leads (dos campañas). */
    private function gastoDeIronBody2(): array
    {
        $mensajes = ['campaign_id' => '120253617613600387', 'campaign_name' => 'mensajes ultima sept - Oct', 'adset_id' => '120253617613640387', 'adset_name' => 'Neiva'];
        $nueva = ['campaign_id' => '120253043436220387', 'campaign_name' => 'Nueva campaña w', 'adset_id' => '120253043436230387', 'adset_name' => 'Neiva w'];

        return [
            $this->insightRow('2026-10-02', self::AD_A1, '20000.00', $mensajes + ['ad_name' => 'creativo 2', 'impressions' => '5000']),
            $this->insightRow('2026-10-03', self::AD_A1, '15000.00', $mensajes + ['ad_name' => 'creativo 2', 'impressions' => '4000']),
            $this->insightRow('2026-10-03', self::AD_A2, '4000.00', $mensajes + ['ad_name' => 'creativo 1', 'impressions' => '900']),
            $this->insightRow('2026-10-04', self::AD_A3, '2000.00', $mensajes + ['ad_name' => 'creativo 3', 'impressions' => '400']),
            $this->insightRow('2026-10-04', self::AD_A4, '516.00', $nueva + ['ad_name' => 'creativo 2', 'impressions' => '100']),
        ];
    }

    private function sincronizar(string $from = '2026-10-01', string $to = '2026-10-05'): void
    {
        $this->assertSame('ok', $this->sync()->runAll('manual', $this->day($from), $this->day($to))['status']);
    }

    private function paidLead(string $at, string $adId, array $attrs = []): MarketingLead
    {
        $lead = $this->lead($at, $attrs);
        $this->touch($lead, $this->adReferral($adId, 'https://fb.me/4XT8pn1QR', 'clid-'.$lead->id), $at);

        return $lead;
    }

    /** `$n` personas de pauta del anuncio, repartidas entre el 1 y el 4 de octubre. */
    private function leadsDe(string $adId, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->paidLead(sprintf('2026-10-0%d %02d:%02d:00', 1 + $i % 4, 13 + intdiv($i, 60) % 8, $i % 60), $adId);
        }
    }

    // ── El caso real: 124 leads de pauta, 98 de cuentas conectadas ─────────

    /**
     * Producción: 98 leads de pauta de 4 anuncios de IronBody2 y 26 de 2 anuncios
     * de una cuenta externa. La cobertura de pauta es 98 / 124 (0,7903); el gasto
     * es el de IronBody2 (nunca «$ 0»); ROAS, CAC y Conversión Meta salen de los
     * 98; los 26 van a «Cuenta publicitaria no conectada», sin dinero.
     */
    public function test_el_caso_real_98_de_124(): void
    {
        $this->porCuenta = [self::A => $this->gastoDeIronBody2()];
        $this->sincronizar();

        // Cuatro clientes nuevos y una renovación de IronBody2: 500.000 en caja.
        foreach (['3001110001', '3001110002', '3001110003', '3001110004'] as $i => $tel) {
            [$u] = $this->customer($tel);
            $this->paidLead('2026-10-02 14:00:00', $i < 3 ? self::AD_A1 : self::AD_A2, ['phone' => '57'.$tel]);
            $this->payment($u, 100000, '2026-10-03 1'.$i.':00:00');
        }
        [$antiguo] = $this->customer('3001110005');
        $this->payment($antiguo, 70000, '2026-08-01 15:00:00');
        $this->paidLead('2026-10-02 16:00:00', self::AD_A1, ['phone' => '573001110005']);
        $this->payment($antiguo, 100000, '2026-10-04 15:00:00');
        // Y una conversión de la cuenta externa: es pauta, pero no entra en lo de las cuentas conectadas.
        [$externo] = $this->customer('3001110006');
        $this->paidLead('2026-10-03 14:00:00', self::AD_X1, ['phone' => '573001110006']);
        $this->payment($externo, 80000, '2026-10-04 16:00:00');

        // El resto, hasta 91 + 4 + 2 + 1 = 98 de IronBody2 y 25 + 1 = 26 externos.
        $this->leadsDe(self::AD_A1, 91 - 4);
        $this->leadsDe(self::AD_A2, 4 - 1);
        $this->leadsDe(self::AD_A3, 2);
        $this->leadsDe(self::AD_A4, 1);
        $this->leadsDe(self::AD_X1, 25 - 1);
        $this->leadsDe(self::AD_X2, 1);
        // Conversaciones del periodo: dos de IronBody2 y una externa.
        foreach (MarketingLead::query()->orderBy('id')->take(2)->get() as $lead) {
            $this->inbound($this->conversation($lead), '2026-10-04 12:00:00');
        }
        $this->inbound($this->conversation(MarketingLead::query()->where('phone', '573001110006')->sole()), '2026-10-04 12:00:00');

        $d = $this->dashboard();

        // Cobertura de pauta: con las tres cuentas sincronizadas y el periodo cubierto entero, se afirma.
        $this->assertSame(['total' => 124, 'connected' => 98, 'unconnected' => 26, 'share' => 0.7903, 'established' => true], $d['paid_coverage']);

        // El gasto: el de las cuentas conectadas, que es el de IronBody2.
        $this->assertSame(['ok', 41516.0, 'COP', true], [$d['spend']['status'], $d['spend']['amount'], $d['spend']['currency'], $d['spend']['complete']]);
        $this->assertSame(
            [['IronBody2', 'ok', 41516.0], ['Fredy Medina', 'real_zero', 0.0], ['IRONBODY cuenta publicitaria', 'real_zero', 0.0]],
            array_map(fn (array $c) => [$c['name'], $c['status'], $c['amount']], $d['spend']['accounts']),
        );

        // ROAS, CAC y Conversión Meta: los 98 de las cuentas conectadas frente a su gasto.
        $k = $d['kpis'];
        $this->assertSame(['leads' => 98, 'converted' => 4, 'revenue' => 500000.0, 'revenue_new' => 400000.0, 'renewals' => 1, 'revenue_renewal' => 100000.0], $k['paid_resolved']);
        $this->assertSame(['leads' => 124, 'converted' => 5, 'revenue' => 580000.0], $k['paid']);
        $this->assertSame([12.04, 10379.0, 0.0408, 458484.0, 26], [$k['roas'], $k['cac'], $k['meta_conversion_rate'], $k['return_after_ad_spend'], $k['unresolved_paid_leads']]);

        // La tabla por cuenta: de mayor a menor gasto, y suman el gasto del panel.
        $filas = $d['accounts']['rows'];
        $this->assertSame(['IronBody2', 'Fredy Medina', 'IRONBODY cuenta publicitaria'], array_column($filas, 'name'));
        $this->assertSame($d['spend']['amount'], round(array_sum(array_column($filas, 'spend')), 2));
        $a = $filas[0];
        $this->assertSame(
            [MetaAdEntity::opaqueId('account', self::A), '***4714', 'account', 41516.0, 'COP', false, null, 10400, null],
            [$a['id'], $a['ref'], $a['level'], $a['spend'], $a['currency'], $a['partial'], $a['covered_to'], $a['impressions'], $a['reach']],
        );
        $this->assertSame(
            [98, 2, 4, 1, 500000.0, 10379.0, 12.04, 0.0408, 458484.0, 2, true],
            [$a['leads'], $a['conversations'], $a['converted'], $a['renewals'], $a['revenue'], $a['cac'], $a['roas'], $a['conversion_rate'], $a['return_after_ad_spend'], $a['campaign_count'], $a['has_children']],
        );
        $fredy = $filas[1];
        $this->assertSame([0.0, 0, 0, 0, null, null, null, 0, false], [$fredy['spend'], $fredy['impressions'], $fredy['leads'], $fredy['converted'], $fredy['roas'], $fredy['cac'], $fredy['return_after_ad_spend'], $fredy['campaign_count'], $fredy['has_children']]);
        foreach (['id', 'ref', 'name', 'level', 'spend', 'currency', 'partial', 'covered_to', 'impressions', 'reach', 'clicks', 'link_clicks', 'meta_conversations',
            'meta_replies', 'leads', 'conversations', 'converted', 'renewals', 'revenue', 'cac', 'roas', 'conversion_rate', 'return_after_ad_spend', 'campaign_count', 'has_children'] as $clave) {
            $this->assertArrayHasKey($clave, $a, "fila de cuenta: {$clave}");
        }
        $this->assertArrayNotHasKey('account_id', $a);

        // La pauta de la cuenta no conectada: sus recuentos, SIN dinero.
        $this->assertSame(
            ['id' => 'unconnected', 'name' => 'Cuenta publicitaria no conectada', 'leads' => 26, 'conversations' => 1, 'converted' => 1, 'renewals' => 0],
            $d['accounts']['unconnected'],
        );

        // Las campañas dicen de qué cuenta son; la pauta sin cuenta va al final, sin cuenta.
        $campanas = collect($d['campaigns']['rows']);
        $this->assertSame(['mensajes ultima sept - Oct', 'Nueva campaña w', 'Cuenta publicitaria no conectada'], $campanas->pluck('name')->all());
        $this->assertSame([[$a['id'], '***4714', 'IronBody2'], [$a['id'], '***4714', 'IronBody2'], [null, null, null]], $campanas->map(fn (array $c) => [$c['account_id'], $c['account_ref'], $c['account_name']])->all());
        $this->assertSame([97, 1, 26], $campanas->pluck('leads')->all());
        $this->assertNull($campanas->last()['spend']);

        // El resumen para administración: las mismas cifras de las tarjetas.
        $e = $d['executive'];
        $this->assertSame(['spend' => 41516.0, 'currency' => 'COP', 'complete' => true], $e['investment']);
        $this->assertSame(['paid_leads' => 124, 'connected_paid_leads' => 98, 'new_customers' => 4, 'renewals' => 1, 'conversion_rate' => 0.0408, 'cac' => 10379.0], $e['acquisition']);
        // ROI = (500.000 − 41.516) / 41.516 = 11,04355 → 11,0435 con 4 decimales.
        $this->assertSame(['revenue' => 500000.0, 'roas' => 12.04, 'roi' => 11.0435], $e['return']);
        $this->assertSame(['connected' => 98, 'unconnected' => 26, 'share' => 0.7903], $e['coverage']);
        $this->assertSame([
            'profitable' => 'YES',
            'reason' => 'Los leads de pauta de las cuentas conectadas pagaron $ 500.000 en caja frente a $ 41.516 de gasto (ROAS 12,04×). No incluye 26 leads (21 %) de una cuenta publicitaria no conectada.',
            'recommendation' => 'Mantener la inversión y vigilar el CAC. Conectar esa cuenta para medir el resto.',
        ], $e['conclusion']);

        // El estado de la sincronización, consolidado y por cuenta.
        $this->assertSame('ok', $d['sync']['status']);
        $this->assertSame(['IronBody2', 'Fredy Medina', 'IRONBODY cuenta publicitaria'], array_column($d['sync']['accounts'], 'name'));
    }

    // ── Lo que sale al front ────────────────────────────────────────────────

    /** Por la API: claves opacas y referencias enmascaradas; ningún id de cuenta completo, en ninguna respuesta. */
    public function test_ningun_id_de_cuenta_completo_sale_al_front(): void
    {
        $this->porCuenta = [self::A => $this->gastoDeIronBody2()];
        $this->sincronizar();
        $this->paidLead('2026-10-02 15:00:00', self::AD_A1);
        $this->paidLead('2026-10-02 16:00:00', self::AD_X1);

        $h = $this->adminAs(Admin::ROLE_ADMINISTRADOR);
        $q = 'period=custom&from=2026-10-01&to=2026-10-05';
        $panel = $this->getJson(self::BASE.'?'.$q, $h)->assertOk();
        $campanas = $this->getJson(self::BASE.'/campaigns?level=campaign&'.$q, $h)->assertOk();
        $conjuntos = $this->getJson(self::BASE.'/campaigns?level=adset&parent='.MetaAdEntity::opaqueId('campaign', '120253617613600387').'&'.$q, $h)->assertOk();
        $leads = $this->getJson(self::BASE.'/leads?'.$q, $h)->assertOk();

        $panel->assertJsonPath('data.accounts.rows.0.ref', '***4714')
            ->assertJsonPath('data.accounts.rows.0.name', 'IronBody2')
            ->assertJsonPath('data.campaigns.rows.0.account_id', MetaAdEntity::opaqueId('account', self::A))
            ->assertJsonPath('data.spend.accounts.0.ref', '***4714')
            ->assertJsonPath('data.sync.accounts.0.ref', '***4714');
        $campanas->assertJsonPath('data.rows.0.account_name', 'IronBody2');
        $conjuntos->assertJsonPath('data.rows.0.account_ref', '***4714')->assertJsonPath('data.meta.total', 1);
        $leads->assertJsonPath('data.0.first_touch.account_status', 'unconnected')
            ->assertJsonPath('data.1.first_touch.account_status', 'connected')
            ->assertJsonPath('data.1.first_touch.account_name', 'IronBody2');

        foreach ([$panel, $campanas, $conjuntos, $leads] as $i => $res) {
            foreach ([self::A, self::B, self::C, self::AD_A1, self::AD_X1, '120253617613600387'] as $id) {
                $this->assertStringNotContainsString($id, $res->getContent(), "la respuesta #{$i} expone {$id}");
            }
        }
    }

    /** La lista de leads dice, en un toque de pauta, si su anuncio es de una cuenta conectada y de cuál. */
    public function test_la_lista_de_leads_dice_la_cuenta(): void
    {
        $this->porCuenta = [self::A => $this->gastoDeIronBody2()];
        $this->sincronizar();
        $conectado = $this->paidLead('2026-10-02 15:00:00', self::AD_A2);
        $externo = $this->paidLead('2026-10-02 16:00:00', self::AD_X2);
        $organico = $this->lead('2026-10-02 17:00:00');
        $this->touch($organico, $this->postReferral(), '2026-10-02 17:00:00');

        $vista = fn () => collect($this->service()->leads(...$this->bounds('2026-10-01', '2026-10-05'))['data'])
            ->mapWithKeys(fn (array $l) => [$l['id'] => [$l['first_touch']['account_status'], $l['first_touch']['account_name']]]);

        $this->assertSame([
            $organico->id => [null, null],
            $externo->id => ['unconnected', null],
            $conectado->id => ['connected', 'IronBody2'],
        ], $vista()->all());

        // Con una cuenta conectada que aún no se ha sincronizado nunca, no se puede afirmar que el
        // anuncio sea de una cuenta no conectada: ni en la lista, ni en las filas, ni en la conclusión.
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B, self::C, '4444000000004444']]);
        $this->assertSame([null, null], $vista()[$externo->id]);
        $this->assertSame(['connected', 'IronBody2'], $vista()[$conectado->id]);
        $d = $this->dashboard();
        $this->assertSame(MetaDashboardService::UNRESOLVED_NAME_NEVER_SYNCED, collect($d['campaigns']['rows'])->last()['name']);
        $this->assertSame(MetaDashboardService::UNRESOLVED_NAME_NEVER_SYNCED, $d['accounts']['unconnected']['name']);
        $this->assertStringNotContainsString('cuenta publicitaria no conectada', $d['executive']['conclusion']['reason']);

        // Sin Meta configurado, ningún toque dice cuenta.
        $this->configureMetaAds(['access_token' => '', 'ad_account_ids' => [self::A]]);
        $this->assertSame([[null, null], [null, null], [null, null]], $vista()->values()->all());
    }

    /** Sin visión completa: los conteos sí, el dinero no; ni en las filas por cuenta ni en el resumen. */
    public function test_sin_permiso_de_dinero(): void
    {
        $this->porCuenta = [self::A => $this->gastoDeIronBody2()];
        $this->sincronizar();
        [$u] = $this->customer('3001110001');
        $this->paidLead('2026-10-02 14:00:00', self::AD_A1, ['phone' => '573001110001']);
        $this->payment($u, 123457, '2026-10-03 15:00:00');

        $d = $this->dashboard(money: false);

        $a = $d['accounts']['rows'][0];
        $this->assertSame([1, 1, null, null, null, null], [$a['leads'], $a['converted'], $a['revenue'], $a['roas'], $a['cac'], $a['return_after_ad_spend']]);
        $this->assertSame(41516.0, $a['spend'], 'el gasto es de Meta y se ve');
        $this->assertSame(['profitable' => 'INCONCLUSIVE', 'reason' => 'Sin permiso para ver ingresos.', 'recommendation' => 'Pedir la conclusión a un administrador que pueda ver los ingresos.'], $d['executive']['conclusion']);
        $this->assertSame(['revenue' => null, 'roas' => null, 'roi' => null], $d['executive']['return']);
        $this->assertSame([1, 1, null], [$d['executive']['acquisition']['paid_leads'], $d['executive']['acquisition']['new_customers'], $d['executive']['acquisition']['cac']]);
        $this->assertStringNotContainsString('123457', (string) json_encode($d));
        $this->assertStringNotContainsString('123.457', (string) json_encode($d, JSON_UNESCAPED_UNICODE));
    }

    // ── La conclusión ejecutiva, regla a regla ─────────────────────────────

    /** IronBody2 gasta 100.000 del 2 al 3 de octubre en AD_A1; un lead suyo paga `$cobro`. Opcionalmente, uno externo. */
    private function escenario(float $cobro, bool $externo = false): void
    {
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B]]);
        $this->porCuenta = [self::A => [
            $this->insightRow('2026-10-02', self::AD_A1, '60000.00', ['campaign_id' => '120253617613600387']),
            $this->insightRow('2026-10-03', self::AD_A1, '40000.00', ['campaign_id' => '120253617613600387']),
        ]];
        $this->sincronizar();
        [$u] = $this->customer('3001110001');
        $this->paidLead('2026-10-02 14:00:00', self::AD_A1, ['phone' => '573001110001']);
        $this->paidLead('2026-10-02 15:00:00', self::AD_A1);
        if ($cobro > 0) {
            $this->payment($u, $cobro, '2026-10-03 15:00:00');
        }
        if ($externo) {
            $this->paidLead('2026-10-02 16:00:00', self::AD_X1);
        }
    }

    /** @return array{profitable: string, reason: string, recommendation: string} */
    private function conclusion(string $from = '2026-10-01', string $to = '2026-10-05', bool $money = true): array
    {
        return $this->dashboard($from, $to, $money)['executive']['conclusion'];
    }

    /** 2. Sin Meta, o con el gasto desconocido o incompleto: no concluyente. */
    public function test_conclusion_sin_gasto_completo(): void
    {
        $esperada = ['profitable' => 'INCONCLUSIVE', 'reason' => 'El gasto de Meta del periodo no está completo o no se conoce.', 'recommendation' => 'Revisar la sincronización de Meta Ads.'];

        // Sin Meta configurado (y aunque haya pauta: no se dice que sea de una cuenta no conectada).
        $this->configureMetaAds(['access_token' => '', 'ad_account_ids' => [self::A]]);
        $this->paidLead('2026-10-02 16:00:00', self::AD_X1);
        $this->assertSame($esperada, $this->conclusion());

        // Configurado, pero una cuenta no tiene datos del periodo: el total no se sabe. B nunca ha
        // sincronizado: tampoco se puede afirmar que el anuncio externo sea de una cuenta no conectada.
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B]]);
        $this->porCuenta = [self::A => [$this->insightRow('2026-10-02', self::AD_A1, '100.00')]];
        $this->sync()->forAccount(self::A)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));
        $this->assertSame('unknown', $this->dashboard()['spend']['status']);
        $this->assertSame($esperada, $this->conclusion());

        // Parcial: B cubre solo hasta el 4. Aunque las dos ya sincronizaron, el periodo no está cubierto
        // entero: un anuncio que solo entregó el día 5 no estaría en la dimensión. No se afirma que el
        // externo sea de una cuenta no conectada, así que la regla 8 NO se añade (antes se añadía).
        $this->sync()->forAccount(self::B)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-04'));
        $d = $this->dashboard();
        $this->assertSame([100.0, false], [$d['spend']['amount'], $d['spend']['complete']]);
        $this->assertSame($esperada, $d['executive']['conclusion']);
        $this->assertSame(['total' => 1, 'connected' => null, 'unconnected' => null, 'share' => null, 'established' => false], $d['paid_coverage']);
        $this->assertSame(['spend' => 100.0, 'currency' => 'COP', 'complete' => false], $d['executive']['investment']);
    }

    /** Con el gasto en otra moneda que la caja, no se comparan. */
    public function test_conclusion_con_gasto_en_otra_moneda(): void
    {
        $this->configureMetaAds(['ad_account_ids' => [self::A]]);
        $this->porCuenta = [self::A => [$this->insightRow('2026-10-02', self::AD_A1, '30.00', ['account_currency' => 'USD'])]];
        $this->sincronizar();
        $this->paidLead('2026-10-02 14:00:00', self::AD_A1);

        $this->assertSame([
            'profitable' => 'INCONCLUSIVE',
            'reason' => 'El gasto de Meta está en otra moneda (USD): no se puede comparar con la caja en pesos.',
            'recommendation' => 'Revisar la moneda de las cuentas publicitarias.',
        ], $this->conclusion());
    }

    /** 3. El periodo empieza antes de la cobertura histórica del CRM: la fecha sale de coverageSince(). */
    public function test_conclusion_antes_de_la_cobertura_historica(): void
    {
        config()->set('marketing.attribution.coverage_since', '2026-09-16');
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B]]);
        $this->porCuenta = [self::A => [$this->insightRow('2026-09-18', self::AD_A1, '20000.00')]];
        $this->sincronizar('2026-09-10', '2026-09-20');
        $this->paidLead('2026-09-17 15:00:00', self::AD_A1);

        $this->assertSame([
            'profitable' => 'INCONCLUSIVE',
            'reason' => 'El periodo empieza antes del 16 sept 2026, cuando el CRM empezó a captar leads.',
            'recommendation' => 'Elige un periodo desde el 16 sept 2026.',
        ], $this->conclusion('2026-09-10', '2026-09-20'));

        // Sin ninguna cobertura deducible, lo dice sin fecha.
        config()->set('marketing.attribution.coverage_since', null);
        $c = $this->conclusion('2026-09-10', '2026-09-20');
        $this->assertSame(['INCONCLUSIVE', 'El CRM todavía no capta leads de forma continua: no hay con qué medir el periodo.'], [$c['profitable'], $c['reason']]);
    }

    /** 4. Gasto 0 comprobado: sin inversión no hay rentabilidad que medir, con o sin ingresos. */
    public function test_conclusion_con_gasto_cero(): void
    {
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B]]);
        // AD_A1 gastó en septiembre (está en la dimensión de IronBody2); en octubre, nada.
        $this->porCuenta = [self::A => [$this->insightRow('2026-09-20', self::AD_A1, '50000.00')]];
        $this->sincronizar('2026-09-15', '2026-10-05');

        $this->assertSame([
            'profitable' => 'INCONCLUSIVE',
            'reason' => 'Sin inversión ni ingresos de las cuentas conectadas en el periodo.',
            'recommendation' => 'Elegir un periodo con pauta activa.',
        ], $this->conclusion());

        [$u] = $this->customer('3001110001');
        $this->paidLead('2026-10-02 14:00:00', self::AD_A1, ['phone' => '573001110001']);
        $this->payment($u, 90000, '2026-10-03 15:00:00');
        $d = $this->dashboard();
        $this->assertSame(['real_zero', 0.0, 90000.0], [$d['spend']['status'], $d['spend']['amount'], $d['kpis']['paid_resolved']['revenue']]);
        $this->assertSame([
            'profitable' => 'INCONCLUSIVE',
            'reason' => 'Hubo ingresos de leads de pauta, pero ningún gasto en el periodo: sus anuncios gastaron antes.',
            'recommendation' => 'Mirar un periodo más largo, que incluya el gasto de esos anuncios.',
        ], $d['executive']['conclusion']);
        $this->assertNull($d['executive']['return']['roi']);
    }

    /** 5. Ingresos ≥ gasto: rentable, con las cifras en pesos y el ROAS. */
    public function test_conclusion_rentable(): void
    {
        $this->escenario(150000);

        $d = $this->dashboard();

        $this->assertSame([
            'profitable' => 'YES',
            'reason' => 'Los leads de pauta de las cuentas conectadas pagaron $ 150.000 en caja frente a $ 100.000 de gasto (ROAS 1,50×).',
            'recommendation' => 'Mantener la inversión y vigilar el CAC.',
        ], $d['executive']['conclusion']);
        $this->assertSame(['revenue' => 150000.0, 'roas' => 1.5, 'roi' => 0.5], $d['executive']['return']);
    }

    /** Ingresos iguales al gasto también lo cubren: rentable, con ROAS 1,00×. */
    public function test_conclusion_en_el_punto_de_equilibrio(): void
    {
        $this->escenario(100000);

        $c = $this->conclusion();
        $this->assertSame(['YES', 'Los leads de pauta de las cuentas conectadas pagaron $ 100.000 en caja frente a $ 100.000 de gasto (ROAS 1,00×).'], [$c['profitable'], $c['reason']]);
        $this->assertSame(0.0, $this->dashboard()['executive']['return']['roi']);
    }

    /** 6. Ingresos < gasto con la ventana de 30 días abierta: aún pueden llegar pagos. */
    public function test_conclusion_con_la_ventana_abierta(): void
    {
        $this->escenario(40000);

        $d = $this->dashboard();

        $this->assertSame([
            'profitable' => 'INCONCLUSIVE',
            'reason' => 'Por ahora los ingresos ($ 40.000) no cubren el gasto ($ 100.000), pero la ventana de 30 días sigue abierta: aún pueden llegar pagos.',
            'recommendation' => 'Revisar de nuevo cuando cierre la ventana (el 5 nov 2026).',
        ], $d['executive']['conclusion']);
        $this->assertSame(['revenue' => 40000.0, 'roas' => 0.4, 'roi' => -0.6], $d['executive']['return']);
    }

    /** 7. Ingresos < gasto con la ventana cerrada: no rentable. */
    public function test_conclusion_no_rentable(): void
    {
        // Un mes después: la ventana del periodo (1–5 de octubre) cerró el 5 de noviembre.
        Carbon::setTestNow('2026-11-10 15:00:00');
        $this->escenario(40000);

        $this->assertSame([
            'profitable' => 'NO',
            'reason' => 'Los ingresos en caja ($ 40.000) no cubren el gasto ($ 100.000): ROAS 0,40×.',
            'recommendation' => 'Revisar las campañas con gasto y sin clientes nuevos.',
        ], $this->conclusion());

        // El último instante de la ventana todavía es «abierta»; el siguiente, no.
        Carbon::setTestNow('2026-11-05 04:59:59');
        $this->assertSame('INCONCLUSIVE', $this->conclusion()['profitable']);
        Carbon::setTestNow('2026-11-05 05:00:00');
        $this->assertSame('NO', $this->conclusion()['profitable']);
    }

    /** 8. Con leads de pauta de una cuenta no conectada, se añade cuántos son al motivo y a la recomendación. */
    public function test_conclusion_avisa_de_la_pauta_no_conectada(): void
    {
        $this->escenario(150000, externo: true);

        $d = $this->dashboard();

        $this->assertSame(['total' => 3, 'connected' => 2, 'unconnected' => 1, 'share' => 0.6667, 'established' => true], $d['paid_coverage']);
        $this->assertSame([
            'profitable' => 'YES',
            'reason' => 'Los leads de pauta de las cuentas conectadas pagaron $ 150.000 en caja frente a $ 100.000 de gasto (ROAS 1,50×). No incluye 1 lead (33 %) de una cuenta publicitaria no conectada.',
            'recommendation' => 'Mantener la inversión y vigilar el CAC. Conectar esa cuenta para medir el resto.',
        ], $d['executive']['conclusion']);
        // Sin el externo no se añade nada (lo comprueba test_conclusion_rentable).
    }

    // ── Cobertura de pauta en los bordes ────────────────────────────────────

    public function test_la_cobertura_de_pauta_en_los_bordes(): void
    {
        $this->porCuenta = [self::A => $this->gastoDeIronBody2()];
        $this->sincronizar();

        // Sin leads de pauta: total 0 y cuota sin cifra.
        $this->assertSame(['total' => 0, 'connected' => 0, 'unconnected' => 0, 'share' => null, 'established' => true], $this->dashboard()['paid_coverage']);
        $this->assertNull($this->dashboard()['accounts']['unconnected']);

        // Todos de cuentas conectadas: 100 %.
        $this->paidLead('2026-10-02 14:00:00', self::AD_A1);
        $this->assertSame(['total' => 1, 'connected' => 1, 'unconnected' => 0, 'share' => 1.0, 'established' => true], $this->dashboard()['paid_coverage']);

        // Antes de la cobertura histórica: los recuentos, null (un 0 no sería una medición). `established`
        // es de Meta, no del CRM: sigue diciendo lo que se sabe de las cuentas.
        config()->set('marketing.attribution.coverage_since', '2026-11-01');
        $this->assertSame(['total' => null, 'connected' => null, 'unconnected' => null, 'share' => null, 'established' => true], $this->dashboard()['paid_coverage']);
    }

    // ── «Cuenta publicitaria no conectada», solo con evidencia ──────────────

    /**
     * La pasada horaria mira 7 días: un anuncio de IronBody2 que solo entregó
     * hace 20 días no está en la dimensión. En un periodo de 30 días, que esa
     * pasada no cubre entero, NO se afirma que su lead (ni el de la cuenta
     * externa) sea de una cuenta no conectada: ni en la fila, ni en la
     * cobertura, ni en la lista, ni en la conclusión. Con el periodo cubierto
     * entero, sí: el anuncio viejo resulta ser de IronBody2, y solo el externo
     * es de una cuenta no conectada.
     */
    public function test_no_conectada_solo_con_la_cobertura_completa(): void
    {
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B]]);
        $viejo = '120253617613690387';
        $mensajes = ['campaign_id' => '120253617613600387', 'campaign_name' => 'mensajes ultima sept - Oct', 'adset_id' => '120253617613640387', 'adset_name' => 'Neiva'];
        $this->porCuenta = [self::A => [
            $this->insightRow('2026-09-15', $viejo, '9000.00', $mensajes + ['ad_name' => 'creativo viejo']),
            $this->insightRow('2026-10-02', self::AD_A1, '20000.00', $mensajes + ['ad_name' => 'creativo 2']),
        ]];
        // La pasada de cada hora: los últimos 7 días de cada cuenta (del 29 de septiembre al 5 de octubre).
        $this->assertSame('ok', $this->sync()->runAll('schedule')['status']);
        $deViejo = $this->paidLead('2026-09-15 15:00:00', $viejo);
        $externo = $this->paidLead('2026-10-02 16:00:00', self::AD_X1);
        $conectado = $this->paidLead('2026-10-02 15:00:00', self::AD_A1);

        $periodo = $this->service()->period('30d');
        $this->assertSame(['2026-09-06', '2026-10-05'], [$periodo['from'], $periodo['to']]);
        $estados = fn (): array => collect($this->service()->leads($periodo['start'], $periodo['end'])['data'])
            ->mapWithKeys(fn (array $l) => [$l['id'] => $l['first_touch']['account_status']])->all();

        $d = $this->service()->dashboard($periodo);
        $this->assertSame(['total' => 3, 'connected' => null, 'unconnected' => null, 'share' => null, 'established' => false], $d['paid_coverage']);
        $this->assertSame(['connected' => null, 'unconnected' => null, 'share' => null], $d['executive']['coverage']);
        $this->assertNull($d['executive']['acquisition']['connected_paid_leads']);
        $sin = collect($d['campaigns']['rows'])->firstWhere('id', MetaDashboardService::UNRESOLVED);
        $this->assertSame([MetaDashboardService::UNRESOLVED_NAME_NEVER_SYNCED, 2], [$sin['name'], $sin['leads']]);
        $this->assertSame(['id' => 'unconnected', 'name' => MetaDashboardService::UNRESOLVED_NAME_NEVER_SYNCED, 'leads' => 2, 'conversations' => 0, 'converted' => 0, 'renewals' => 0], $d['accounts']['unconnected']);
        $this->assertStringNotContainsString('cuenta publicitaria no conectada', $d['executive']['conclusion']['reason']);
        $this->assertStringNotContainsString('Conectar esa cuenta', $d['executive']['conclusion']['recommendation']);
        $this->assertSame([$externo->id => null, $conectado->id => 'connected', $deViejo->id => null], $estados());

        // Con los 30 días cubiertos en las dos cuentas: el viejo es de IronBody2; el externo, de una no conectada.
        $this->sincronizar('2026-09-06', '2026-10-05');
        $d = $this->service()->dashboard($periodo);
        $this->assertSame(['total' => 3, 'connected' => 2, 'unconnected' => 1, 'share' => 0.6667, 'established' => true], $d['paid_coverage']);
        $sin = collect($d['campaigns']['rows'])->firstWhere('id', MetaDashboardService::UNRESOLVED);
        $this->assertSame([MetaDashboardService::UNRESOLVED_NAME_OTHER_ACCOUNT, 1], [$sin['name'], $sin['leads']]);
        $this->assertSame([MetaDashboardService::UNRESOLVED_NAME_OTHER_ACCOUNT, 1], [$d['accounts']['unconnected']['name'], $d['accounts']['unconnected']['leads']]);
        $this->assertStringEndsWith('No incluye 1 lead (33 %) de una cuenta publicitaria no conectada.', $d['executive']['conclusion']['reason']);
        $this->assertSame([$externo->id => 'unconnected', $conectado->id => 'connected', $deViejo->id => 'connected'], $estados());
    }

    /**
     * La regla 8 con leads a los dos lados: el redondeo no borra a nadie. 1 de
     * 299 es «1 %», no «0 %»; 298 de 299, «99 %», no «100 %» (igual que el front).
     */
    public function test_el_porcentaje_de_la_regla_8_no_dice_cero_ni_cien(): void
    {
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B]]);
        $this->porCuenta = [self::A => [$this->insightRow('2026-10-02', self::AD_A1, '100000.00', ['campaign_id' => '120253617613600387'])]];
        $this->sincronizar();
        // El 1: 297 de IronBody2. El 2: uno de IronBody2 y uno externo. El 3: 297 externos.
        $deDia = function (string $dia, string $adId, int $n): void {
            for ($i = 0; $i < $n; $i++) {
                $this->paidLead(sprintf('%s %02d:%02d:00', $dia, 13 + intdiv($i, 60), $i % 60), $adId);
            }
        };
        $deDia('2026-10-01', self::AD_A1, 297);
        $deDia('2026-10-02', self::AD_A1, 1);
        $deDia('2026-10-02', self::AD_X1, 1);
        $deDia('2026-10-03', self::AD_X2, 297);

        $d = $this->dashboard('2026-10-01', '2026-10-02');
        $this->assertSame(['total' => 299, 'connected' => 298, 'unconnected' => 1, 'share' => 0.9967, 'established' => true], $d['paid_coverage']);
        $this->assertStringEndsWith('No incluye 1 lead (1 %) de una cuenta publicitaria no conectada.', $d['executive']['conclusion']['reason']);

        $d = $this->dashboard('2026-10-02', '2026-10-03');
        $this->assertSame(['total' => 299, 'connected' => 1, 'unconnected' => 298, 'share' => 0.0033, 'established' => true], $d['paid_coverage']);
        $this->assertStringEndsWith('No incluye 298 leads (99 %) de una cuenta publicitaria no conectada.', $d['executive']['conclusion']['reason']);
    }

    // ── El gasto parcial, cortado en el día común ───────────────────────────

    /**
     * IronBody2, completa, gasta también DESPUÉS del día hasta el que llega
     * Fredy. En el panel, todo lo consolidado se corta en ese día: la tarjeta,
     * la tabla de campañas y las filas por cuenta dicen lo gastado hasta él, y
     * suman lo mismo.
     */
    public function test_el_panel_parcial_se_corta_en_el_dia_comun(): void
    {
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B]]);
        $this->porCuenta = [
            self::A => $this->gastoDeIronBody2(),
            self::B => [$this->insightRow('2026-10-02', '120200000000010194', '1000.00', ['campaign_id' => '120200000000000194', 'campaign_name' => 'Fredy vieja', 'adset_id' => '120200000000020194'])],
        ];
        $this->sync()->forAccount(self::A)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));
        $this->sync()->forAccount(self::B)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-03'));
        $this->paidLead('2026-10-02 15:00:00', self::AD_A1);

        $d = $this->dashboard();

        // Hasta el 3: 20.000 + 15.000 + 4.000 de IronBody2 (sin los 2.516 del 4) y 1.000 de Fredy.
        $this->assertSame(['stale', 40000.0, false, '2026-10-03'], [$d['spend']['status'], $d['spend']['amount'], $d['spend']['complete'], $d['spend']['covered_to']]);
        $this->assertSame([true, '2026-10-03'], [$d['campaigns']['partial'], $d['campaigns']['covered_to']]);
        $this->assertSame(
            [['mensajes ultima sept - Oct', 39000.0, true, '***4714'], ['Fredy vieja', 1000.0, true, '***9023']],
            array_map(fn (array $c) => [$c['name'], $c['spend'], $c['partial'], $c['account_ref']], $d['campaigns']['rows']),
        );
        $this->assertSame(
            [['IronBody2', 39000.0, true, '2026-10-03', null], ['Fredy Medina', 1000.0, true, '2026-10-03', null]],
            array_map(fn (array $r) => [$r['name'], $r['spend'], $r['partial'], $r['covered_to'], $r['roas']], $d['accounts']['rows']),
        );
        $this->assertSame($d['spend']['amount'], round(array_sum(array_column($d['accounts']['rows'], 'spend')), 2));
        $this->assertSame($d['spend']['amount'], round(array_sum(array_column($d['campaigns']['rows'], 'spend')), 2));
        $this->assertSame(['spend' => 40000.0, 'currency' => 'COP', 'complete' => false], $d['executive']['investment']);
    }

    /** Una cuenta con datos solo hasta un día: su fila es parcial, sin ROAS ni CAC, y dice hasta cuándo. */
    public function test_la_fila_de_una_cuenta_parcial(): void
    {
        $this->configureMetaAds(['ad_account_ids' => [self::A, self::B]]);
        $this->porCuenta = [self::A => $this->gastoDeIronBody2()];
        $this->sync()->forAccount(self::A)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-03'));
        $this->sync()->forAccount(self::B)->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));
        [$u] = $this->customer('3001110001');
        $this->paidLead('2026-10-02 14:00:00', self::AD_A1, ['phone' => '573001110001']);
        $this->payment($u, 500000, '2026-10-03 15:00:00');

        $d = $this->dashboard();
        $a = $d['accounts']['rows'][0];
        $this->assertSame(['IronBody2', 39000.0, true, '2026-10-03', null, null, null], [$a['name'], $a['spend'], $a['partial'], $a['covered_to'], $a['roas'], $a['cac'], $a['return_after_ad_spend']]);
        $this->assertSame(500000.0, $a['revenue'], 'los ingresos son de la caja y se ven');
        $this->assertSame([39000.0, false], [$d['spend']['amount'], $d['spend']['complete']]);
        $this->assertSame('INCONCLUSIVE', $d['executive']['conclusion']['profitable']);
    }

    /** Las definiciones de lo consolidado hablan de las cuentas conectadas; las demás no cambian. */
    public function test_las_definiciones_hablan_de_las_cuentas_conectadas(): void
    {
        $d = MetaDashboardService::definitions();

        $this->assertStringStartsWith('Suma del gasto que Meta reporta para el periodo en las cuentas conectadas', $d['spend']);
        foreach (['meta_revenue', 'roas', 'cac', 'paid_coverage'] as $clave) {
            $this->assertStringContainsString('cuentas', $d[$clave], $clave);
            $this->assertStringContainsString('conectada', $d[$clave], $clave);
        }
        $this->assertStringStartsWith('Ingreso atribuible a Meta / gasto Meta.', $d['roas']);
        $this->assertStringStartsWith('Gasto Meta / nuevos clientes obtenidos desde pauta.', $d['cac']);
        $this->assertStringStartsWith('Cobertura de pauta', $d['paid_coverage']);
        // Las que hablaban de UNA cuenta, en plural (antes, «de la cuenta publicitaria conectada»).
        $this->assertSame(
            'Pauta Meta: personas cuyo primer contacto llegó desde un anuncio de una de las cuentas publicitarias conectadas, que son las del gasto. ROAS, CAC, conversión Meta y retorno usan solo este universo; la pauta de una cuenta no conectada se cuenta aparte, en la cobertura de pauta.',
            $d['paid'],
        );
        $this->assertSame('Nuevos clientes de pauta / leads de pauta de las cuentas conectadas.', $d['meta_conversion_rate']);
        $this->assertSame(
            'La campaña se obtiene del anuncio por la Marketing API de Meta, de las cuentas publicitarias conectadas. Sin conexión, los leads de pauta van a «Sin campaña»; si el anuncio es de una cuenta no conectada, a «Cuenta publicitaria no conectada».',
            $d['campaign'],
        );
        foreach ($d as $clave => $texto) {
            $this->assertStringNotContainsString('la cuenta conectada', $texto, $clave);
            $this->assertStringNotContainsString('la cuenta publicitaria conectada', $texto, $clave);
        }
    }

    /** Con Meta caído en una cuenta, las demás siguen en el panel y el estado consolidado lo dice. */
    public function test_el_fallo_de_una_cuenta_no_tumba_el_panel(): void
    {
        $this->porCuenta = [self::A => $this->gastoDeIronBody2()];
        $this->sincronizar();
        $this->fakeMeta(fn (Request $r) => $this->accountOf($r) === self::B
            ? $this->graphError(2, 'Service temporarily unavailable', 503)
            : Http::response(['data' => []]));
        $this->sync()->forAccount(self::B)->run('schedule');

        $d = $this->dashboard();

        $this->assertSame('failed', $d['sync']['status']);
        $this->assertSame('***9023', $d['sync']['last_error']['account_id']);
        $this->assertSame(['ok', 'failed', 'ok'], array_column($d['sync']['accounts'], 'status'));
        // El periodo del 1 al 5 lo cubría el éxito anterior de B: el gasto se sigue sabiendo.
        $this->assertSame(41516.0, $d['spend']['amount']);
        $this->assertSame(MetaSyncRun::STATUS_FAILED, MetaSyncRun::where('ad_account_id', self::B)->latest('id')->first()->status);
    }

    /** Con tres cuentas, el número de consultas del panel no crece con los leads: nada por lead. */
    public function test_el_panel_multicuenta_no_hace_una_consulta_por_lead(): void
    {
        $this->porCuenta = [self::A => $this->gastoDeIronBody2()];
        $this->sincronizar();
        $sembrar = function (int $desde, int $n): void {
            for ($i = $desde; $i < $desde + $n; $i++) {
                $tel = '30022'.str_pad((string) $i, 5, '0', STR_PAD_LEFT);
                [$u] = $this->customer($tel);
                $this->paidLead('2026-10-0'.(2 + $i % 3).' 15:00:00', $i % 2 === 0 ? self::AD_A1 : self::AD_X1, ['phone' => '57'.$tel]);
                $this->payment($u, 1000 + $i, '2026-10-04 15:00:00');
            }
        };
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->dashboard();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $sembrar(0, 3);
        $pocos = $contar();
        $sembrar(3, 20);
        $muchos = $contar();

        $this->assertSame($pocos, $muchos, "con 3 personas: {$pocos} consultas; con 23: {$muchos}");
    }

    /** @return array{0: mixed, 1: mixed} */
    private function bounds(string $from, string $to): array
    {
        $p = $this->service()->period('custom', $from, $to);

        return [$p['start'], $p['end']];
    }
}
