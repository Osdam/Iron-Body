<?php

namespace Tests\Feature\Marketing\Attribution;

use App\Jobs\SyncMetaAdsInsights;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\MarketingAiAction;
use App\Models\MarketingCampaign;
use App\Models\MarketingFollowup;
use App\Models\MarketingLeadIdentity;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Attribution\MetaDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Marketing\MetaAds\FakesMetaAds;
use Tests\TestCase;

/**
 * Los endpoints del panel vistos desde fuera: quién entra, qué sale y qué no
 * sale nunca. Y que `/overview` sigue exactamente como estaba.
 */
class MetaDashboardEndpointsTest extends TestCase
{
    use FakesMetaAds, RefreshDatabase, SeedsAttribution;

    private const BASE = '/api/admin/marketing/meta-dashboard';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 15:00:00');
        foreach (Admin::ROLES as $rol) {
            AdminRole::firstOrCreate(['name' => $rol], ['is_system' => true]);
        }
        // Las pruebas dan de alta socios a mitad de prueba: sin el índice de
        // teléfonos en caché (tiene su propia prueba en MetaDashboardCacheTest).
        config()->set('marketing.attribution.phone_index_cache_seconds', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array{0: string, 1: string}> */
    private function reads(): array
    {
        return [
            ['GET', self::BASE.'?period=7d'],
            ['GET', self::BASE.'/campaigns?level=adset&period=30d'],
            ['GET', self::BASE.'/leads?period=this_month&page=1&per_page=10'],
        ];
    }

    /** Contrato 24: 401 sin sesión, 403 sin marketing.view, como /overview. */
    public function test_rbac_igual_que_overview(): void
    {
        foreach ([...$this->reads(), ['GET', '/api/admin/marketing/overview'], ['POST', self::BASE.'/sync']] as [$verb, $uri]) {
            $this->json($verb, $uri)->assertStatus(401);
        }

        $sinMercadeo = $this->adminWithPermissions(['members.view']);
        foreach ([...$this->reads(), ['GET', '/api/admin/marketing/overview'], ['POST', self::BASE.'/sync']] as [$verb, $uri]) {
            $this->json($verb, $uri, [], $sinMercadeo)->assertStatus(403);
        }

        $lector = $this->adminWithPermissions(['marketing.view']);
        $this->getJson('/api/admin/marketing/overview', $lector)->assertOk();
        foreach ($this->reads() as [$verb, $uri]) {
            $this->json($verb, $uri, [], $lector)->assertOk()->assertJsonPath('ok', true);
        }
        // Pedir una sincronización escribe: con solo ver, no.
        $this->postJson(self::BASE.'/sync', [], $lector)->assertStatus(403);

        $gestor = $this->adminWithPermissions(['marketing.view', 'marketing.manage']);
        $this->postJson(self::BASE.'/sync', [], $gestor)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'not_configured');

        // El token compartido de automatizaciones no es nadie: no dispara nada.
        $this->postJson(self::BASE.'/sync', [], $this->sharedTokenHeaders())->assertStatus(403);
    }

    /**
     * El dinero (ingresos, ROAS, CAC) solo con visión COMPLETA, como
     * /analytics. Con marketing.view a secas, o con el token compartido, los
     * importes llegan en null con el motivo `forbidden`; los conteos, no.
     */
    public function test_el_dinero_solo_con_vision_completa(): void
    {
        $this->configureMetaAds();
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-02', '120230001', '100000.00')]]);
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        [$user] = $this->customer('3001112233');
        $lead = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233', 'name' => 'Ana Cliente']);
        $this->touch($lead, $this->adReferral('120230001'), '2026-10-02 15:00:00');
        $this->payment($user, 150000, '2026-10-03 15:00:00');

        $q = '?period=custom&from=2026-10-01&to=2026-10-05';
        $sinDinero = [
            'marketing.view sin visión completa' => $this->adminWithPermissions(['marketing.view']),
            'token compartido' => $this->sharedTokenHeaders(),
        ];

        foreach ($sinDinero as $quien => $h) {
            $kpis = $this->getJson(self::BASE.$q, $h)->assertOk()->json('data.kpis');
            foreach (['revenue_new', 'revenue_renewal', 'revenue_attributed', 'roas', 'roas_new', 'cac', 'return_after_ad_spend', 'return_state'] as $campo) {
                $this->assertNull($kpis[$campo], "{$quien}: {$campo}");
            }
            $this->assertFalse($kpis['money_visible'], $quien);
            foreach (['revenue_reason', 'roas_reason', 'roas_new_reason', 'cac_reason', 'return_after_ad_spend_reason'] as $campo) {
                $this->assertSame('forbidden', $kpis[$campo], "{$quien}: {$campo}");
            }
            $this->assertSame(['leads' => 1, 'converted' => 1, 'revenue' => null], $kpis['paid'], $quien);
            $this->assertSame(['leads' => 1, 'converted' => 1, 'revenue' => null, 'revenue_new' => null, 'renewals' => 0, 'revenue_renewal' => null], $kpis['paid_resolved'], $quien);
            // Los conteos se ven, y las conversiones (que no son dinero) también.
            $this->assertEquals([1, 1, 1.0, 1.0], [$kpis['leads'], $kpis['converted'], $kpis['conversion_rate'], $kpis['meta_conversion_rate']], $quien);

            $res = $this->getJson(self::BASE.$q, $h);
            $panel = $res->json('data');
            $this->assertSame([null], array_values(array_unique(array_column($panel['origins'], 'revenue'))), $quien);
            foreach ($panel['campaigns']['rows'] as $row) {
                $this->assertSame([null, null, null, null], [$row['revenue'], $row['roas'], $row['cac'], $row['return_after_ad_spend']], $quien);
            }
            // El periodo anterior tampoco enseña dinero.
            foreach (['revenue_attributed', 'meta_revenue', 'roas', 'cac', 'return_after_ad_spend'] as $campo) {
                $this->assertNull($panel['previous']['kpis'][$campo], "{$quien}: previous.{$campo}");
            }
            $this->assertStringNotContainsString('150000', $res->getContent(), $quien);

            $tabla = $this->getJson(self::BASE.'/campaigns?level=ad&period=custom&from=2026-10-01&to=2026-10-05', $h)->assertOk();
            $tabla->assertJsonPath('data.money_visible', false)
                ->assertJsonPath('data.rows.0.revenue', null)
                ->assertJsonPath('data.rows.0.roas', null)
                ->assertJsonPath('data.rows.0.cac', null)
                ->assertJsonPath('data.rows.0.leads', 1);

            $lista = $this->getJson(self::BASE.'/leads'.$q, $h)->assertOk();
            $lista->assertJsonPath('meta.money_visible', false)
                ->assertJsonPath('data.0.conversion.kind', 'new')
                ->assertJsonPath('data.0.conversion.revenue', null);
            $this->assertStringNotContainsString('150000', $lista->getContent(), $quien);
            $this->assertStringNotContainsString('150000', $tabla->getContent(), $quien);
        }

        // Con visión completa, el dinero sale.
        foreach ([Admin::ROLE_SUPER_ADMIN, Admin::ROLE_ADMINISTRADOR] as $rol) {
            $h = $this->adminAs($rol);
            $this->getJson(self::BASE.$q, $h)->assertOk()
                ->assertJsonPath('data.kpis.money_visible', true)
                ->assertJsonPath('data.kpis.revenue_attributed', 150000)
                ->assertJsonPath('data.kpis.revenue_reason', null)
                ->assertJsonPath('data.kpis.paid_resolved.revenue', 150000)
                ->assertJsonPath('data.kpis.roas', 1.5)
                ->assertJsonPath('data.kpis.cac', 100000)
                ->assertJsonPath('data.kpis.cac_reason', null)
                ->assertJsonPath('data.kpis.return_after_ad_spend', 50000)
                ->assertJsonPath('data.kpis.return_state', 'positive')
                ->assertJsonPath('data.kpis.meta_conversion_rate', 1);
            $this->getJson(self::BASE.'/campaigns?level=ad&period=custom&from=2026-10-01&to=2026-10-05', $h)
                ->assertJsonPath('data.money_visible', true)
                ->assertJsonPath('data.rows.0.revenue', 150000);
            $this->getJson(self::BASE.'/leads'.$q, $h)
                ->assertJsonPath('meta.money_visible', true)
                ->assertJsonPath('data.0.conversion.revenue', 150000);
        }
    }

    /**
     * Una lectura no escribe en la base: la identidad de cada lead se resuelve
     * en memoria. La tabla derivada solo la escribe el comando. Ni el token
     * compartido ni nadie escribe abriendo el panel.
     */
    public function test_las_lecturas_no_escriben_identidades(): void
    {
        [$user] = $this->customer('3001112233');
        $lead = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);
        $this->touch($lead, $this->adReferral('120230001'), '2026-10-02 15:00:00');
        $this->payment($user, 120000, '2026-10-03 15:00:00');

        DB::enableQueryLog();
        foreach ([$this->adminAs(Admin::ROLE_ADMINISTRADOR), $this->sharedTokenHeaders()] as $h) {
            $this->getJson(self::BASE.'?period=7d', $h)->assertOk()->assertJsonPath('data.kpis.converted', 1);
            $this->getJson(self::BASE.'/campaigns?level=ad&period=7d', $h)->assertOk();
            $this->getJson(self::BASE.'/leads?period=7d', $h)->assertOk()->assertJsonPath('data.0.conversion.kind', 'new');
        }

        $escrituras = array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $sql): bool => preg_match('/^(insert|update|delete)\b.*marketing_lead_identities/i', $sql) === 1,
        );
        $this->assertSame([], array_values($escrituras), 'un GET escribió en marketing_lead_identities');
        $this->assertSame(0, MarketingLeadIdentity::query()->count());

        // El comando sí la escribe.
        $this->artisan('marketing:resolve-lead-identities')->assertSuccessful();
        $this->assertSame(1, MarketingLeadIdentity::query()->count());
    }

    /**
     * El límite de «Actualizar» va por administrador y después de autenticar:
     * las peticiones anónimas desde la misma IP (el wifi del gimnasio) no gastan
     * el cupo del personal, y un administrador no gasta el de otro.
     */
    public function test_el_limite_de_sync_es_por_administrador_y_tras_autenticar(): void
    {
        Queue::fake();

        foreach (range(1, 10) as $i) {
            $this->postJson(self::BASE.'/sync')->assertStatus(401);
        }

        $a = $this->adminWithPermissions(['marketing.view', 'marketing.manage']);
        foreach (range(1, 6) as $i) {
            $this->postJson(self::BASE.'/sync', [], $a)->assertStatus(202);
        }
        $this->postJson(self::BASE.'/sync', [], $a)
            ->assertStatus(429)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');

        // Otro administrador, desde la misma IP: su propio cupo.
        $b = $this->adminWithPermissions(['marketing.view', 'marketing.manage']);
        $this->postJson(self::BASE.'/sync', [], $b)->assertStatus(202);

        // Pasado el minuto, el primero vuelve a poder.
        Carbon::setTestNow('2026-10-05 15:01:01');
        $this->postJson(self::BASE.'/sync', [], $a)->assertStatus(202);
    }

    /** Las lecturas recalculan el periodo entero: 60 por minuto, como la analítica. */
    public function test_las_lecturas_tienen_limite_por_minuto(): void
    {
        $h = $this->adminWithPermissions(['marketing.view']);

        foreach (range(1, 60) as $i) {
            $this->getJson(self::BASE.'/leads?period=today', $h)->assertOk();
        }
        $this->getJson(self::BASE.'/leads?period=today', $h)->assertStatus(429);
        $this->getJson(self::BASE.'?period=today', $h)->assertStatus(429);
    }

    /** Una página absurda se rechaza antes de calcular nada: 422, nunca 500. */
    public function test_una_pagina_enorme_no_da_500(): void
    {
        $h = $this->adminWithPermissions(['marketing.view']);
        $this->lead('2026-10-02 15:00:00');

        $this->getJson(self::BASE.'/leads?period=7d&page='.PHP_INT_MAX, $h)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_page');
        $this->getJson(self::BASE.'/leads?period=7d&page=10001', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_page');
        $this->getJson(self::BASE.'/leads?period=7d&page=10000&per_page=50', $h)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 10000);

        // Llamado directamente, el servicio tampoco se desborda.
        $s = app(MetaDashboardService::class);
        $p = $s->period('7d');
        $page = $s->leads($p['start'], $p['end'], PHP_INT_MAX, 50);
        $this->assertSame([], $page['data']);
        $this->assertSame(MetaDashboardService::MAX_PAGE, $page['meta']['current_page']);
    }

    /** La forma de la respuesta es la del contrato. */
    public function test_forma_de_la_respuesta(): void
    {
        $lead = $this->lead('2026-10-02 15:00:00');
        $this->touch($lead, $this->adReferral('120230001'), '2026-10-02 15:00:00');

        // Con visión completa: la forma entera, dinero incluido.
        $res = $this->getJson(self::BASE.'?period=custom&from=2026-10-01&to=2026-10-05', $this->adminAs(Admin::ROLE_ADMINISTRADOR))
            ->assertOk();

        // El contrato del cierre del Objetivo 3: lo de antes, más periodo anterior,
        // datos atribuibles y qué métricas reporta Meta.
        $claves = [
            'period', 'previous_period', 'spend', 'kpis', 'previous', 'secondary', 'origins', 'unattributed_share',
            'attributable_share', 'campaigns', 'attribution', 'sync', 'metrics_available',
        ];
        $this->assertSame($claves, array_keys($res->json('data')));
        $res->assertJsonPath('data.period', ['key' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-05', 'timezone' => 'America/Bogota'])
            ->assertJsonPath('data.spend.status', 'not_configured')
            ->assertJsonPath('data.spend.amount', null)
            ->assertJsonPath('data.campaigns.level', 'campaign')
            ->assertJsonPath('data.attribution.model', 'first_touch')
            ->assertJsonPath('data.attribution.window_days', 30)
            ->assertJsonPath('data.sync.configured', false)
            ->assertJsonPath('data.kpis.leads', 1)
            ->assertJsonPath('data.kpis.roas', null)
            ->assertJsonPath('data.kpis.roas_reason', 'spend_not_configured');
        $this->assertSame($claves, array_keys($res->json('data')));
        $res->assertJsonPath('data.previous_period', ['from' => '2026-09-26', 'to' => '2026-09-30'])
            ->assertJsonPath('data.previous.period', ['from' => '2026-09-26', 'to' => '2026-09-30'])
            ->assertJsonPath('data.spend.complete', true)
            ->assertJsonPath('data.campaigns.partial', false);
        $this->assertSame(['messaging_started', 'messaging_replied', 'landing_page_views', 'reach'], array_keys($res->json('data.metrics_available')));
        $this->assertSame(['hot_leads', 'pending_followups', 'ai_actions', 'human_control'], array_keys($res->json('data.secondary')));
        $this->assertIsString($res->json('data.attribution.definitions.leads'));

        $this->getJson(self::BASE.'/campaigns?level=ad&period=7d', $this->adminWithPermissions(['marketing.view']))
            ->assertOk()
            ->assertJsonPath('data.level', 'ad')
            ->assertJsonPath('data.rows.0.id', 'unresolved');

        $this->getJson(self::BASE.'/leads?period=7d&per_page=500', $this->adminWithPermissions(['marketing.view']))
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('data.0.id', $lead->id)
            ->assertJsonPath('data.0.first_touch.ad_ref', '***0001');
    }

    public function test_entradas_invalidas_dan_422_con_motivo(): void
    {
        $h = $this->adminWithPermissions(['marketing.view']);

        $this->getJson(self::BASE.'?period=ayer', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_period');
        $this->getJson(self::BASE.'?period=custom&from=2026-10-05&to=2026-10-01', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_period');
        $this->getJson(self::BASE.'?period[]=7d', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_period');
        $this->getJson(self::BASE.'/campaigns?level=creative', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_level');
        $this->getJson(self::BASE.'/campaigns?level[]=ad', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_level');
        $this->getJson(self::BASE.'/campaigns?level=', $h)->assertOk()->assertJsonPath('data.level', 'campaign');
        $this->getJson(self::BASE.'/leads?page[]=1', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_page');
        $this->getJson(self::BASE.'/leads?page=0', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_page');
        $this->getJson(self::BASE.'/leads?per_page=abc', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_page');
    }

    /**
     * Contrato 25: ni teléfono, ni wa_id, ni referral crudo, ni `ad_id`
     * completo, ni token, en ninguna respuesta.
     */
    public function test_ningun_dato_sensible_sale_al_front(): void
    {
        $adId = '120239876543210';
        $this->configureMetaAds();
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-02', $adId, '50000.00', [
            'campaign_id' => '120209876543211', 'adset_id' => '120219876543212',
            // Un nombre de campaña con un teléfono con separadores.
            'campaign_name' => 'Promo tel. (315) 777-8899',
        ])]]);
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        [$user] = $this->customer('315 777 8899');
        $lead = $this->lead('2026-10-02 15:00:00', ['phone' => '573157778899', 'meta_user_id' => '573157778899', 'name' => 'Ana Prospecto']);
        $conversation = $this->conversation($lead);
        $referral = $this->adReferral($adId, 'https://fb.me/SecretPath77', 'CLID-SECRETO-123');
        $this->inbound($conversation, '2026-10-02 15:00:00', ['wa_id' => '573157778899', 'referral' => $referral]);
        $this->touch($lead, $referral, '2026-10-02 15:00:00', $conversation);
        $this->payment($user, 120000, '2026-10-03 15:00:00');
        // Gente que puso su número o su correo como nombre de perfil de WhatsApp.
        $this->lead('2026-10-02 16:00:00', ['phone' => '573168889900', 'meta_user_id' => '573168889900', 'name' => '+57 316 888 9900']);
        foreach (['(316) 888-9900', '316/888/9900', '316_888_9900', 'ana.perez@gmail.com'] as $i => $nombre) {
            $telefono = '57301000000'.$i;
            $this->lead('2026-10-02 10:'.$i.'0:00', ['phone' => $telefono, 'meta_user_id' => $telefono, 'name' => $nombre]);
        }

        Queue::fake();
        $quienes = [
            'marketing.view y manage' => $this->adminWithPermissions(['marketing.view', 'marketing.manage']),
            'visión completa' => $this->adminAs(Admin::ROLE_SUPER_ADMIN),
        ];

        foreach ($quienes as $quien => $h) {
            $responses = [
                $this->getJson(self::BASE.'?period=custom&from=2026-10-01&to=2026-10-05', $h)->assertOk(),
                $this->getJson(self::BASE.'/campaigns?level=campaign&period=custom&from=2026-10-01&to=2026-10-05', $h)->assertOk(),
                $this->getJson(self::BASE.'/campaigns?level=adset&period=custom&from=2026-10-01&to=2026-10-05', $h)->assertOk(),
                $this->getJson(self::BASE.'/campaigns?level=ad&period=custom&from=2026-10-01&to=2026-10-05', $h)->assertOk(),
                $this->getJson(self::BASE.'/leads?period=custom&from=2026-10-01&to=2026-10-05', $h)->assertOk(),
                $this->postJson(self::BASE.'/sync', [], $h)->assertStatus(202),
            ];

            // Que la prueba no sea vacía: el lead, su campaña y su conversión sí salen.
            $responses[0]->assertJsonPath('data.kpis.converted', 1)->assertJsonPath('data.kpis.leads', 6);
            $responses[1]->assertJsonPath('data.rows.0.name', 'Promo tel. (***8899');
            $responses[4]->assertJsonPath('data.1.name', 'Ana Prospecto')
                ->assertJsonPath('data.1.first_touch.ad_ref', '***3210')
                ->assertJsonPath('data.1.first_touch.campaign_name', 'Promo tel. (***8899')
                ->assertJsonPath('data.1.first_touch.ad_name', 'Video promo ***3210')
                ->assertJsonPath('data.0.name', '***9900');
            $this->assertSame(
                ['***@***', '***9900', '***9900', '(***9900'],
                array_column(array_slice($responses[4]->json('data'), 2), 'name'),
                $quien,
            );

            $prohibido = [
                '3157778899', '573157778899', '316 888 9900', '3168889900', 'CLID-SECRETO-123', 'SecretPath77', $adId,
                '120209876543211', '120219876543212', self::ADS_TOKEN, self::ACCOUNT,
                'Plan de octubre con 2x1', 'Escríbenos',
                '(316) 888-9900', '316/888/9900', '316_888_9900', '888-9900', 'ana.perez', 'gmail.com',
                '(315) 777-8899', '777-8899',
            ];
            $clavesProhibidas = ['phone', 'wa_id', 'meta_user_id', 'contact_id', 'click_id', 'raw_referral_payload',
                'referral', 'ad_id', 'access_token', 'user_id', 'member_id', 'source_url', 'headline'];

            foreach ($responses as $i => $res) {
                // El cuerpo tal cual y sin escapar: json_encode escribe «\/» y «\u00ed».
                $plano = (string) json_encode($res->json(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                foreach ($prohibido as $dato) {
                    $this->assertStringNotContainsString($dato, $res->getContent(), "{$quien}: la respuesta #{$i} expone «{$dato}».");
                    $this->assertStringNotContainsString($dato, $plano, "{$quien}: la respuesta #{$i} expone «{$dato}».");
                }
                $this->assertSame([], array_values(array_intersect($clavesProhibidas, $this->keysOf($res))), "{$quien}: la respuesta #{$i} lleva una clave sensible.");
            }
        }
    }

    /** «Actualizar»: encola si puede, y si no dice por qué. Siempre 202. */
    public function test_pedir_sincronizacion(): void
    {
        Queue::fake();
        $h = $this->adminWithPermissions(['marketing.view', 'marketing.manage']);

        // Sin token ni cuenta: no se despacha nada.
        $this->postJson(self::BASE.'/sync', [], $h)->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.status', 'not_configured')
            ->assertJsonPath('data.sync.configured', false);
        Queue::assertNothingPushed();

        $this->configureMetaAds();
        $this->postJson(self::BASE.'/sync', [], $h)->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.sync.configured', true);
        Queue::assertPushed(SyncMetaAdsInsights::class, fn (SyncMetaAdsInsights $job) => $job->trigger === 'manual');

        // Un segundo clic dentro de los cinco minutos: no se repite.
        $this->postJson(self::BASE.'/sync', [], $h)->assertStatus(202)->assertJsonPath('data.status', 'cooldown');
        Queue::assertPushed(SyncMetaAdsInsights::class, 1);

        // Pasado el plazo, con una pasada en curso: tampoco.
        Carbon::setTestNow('2026-10-05 15:10:00');
        MetaSyncRun::forceCreate([
            'kind' => 'insights', 'status' => 'running', 'trigger' => 'schedule', 'ad_account_id' => self::ACCOUNT,
            'range_from' => '2026-09-29', 'range_to' => '2026-10-05', 'started_at' => now()->subMinute(),
        ]);
        $this->postJson(self::BASE.'/sync', [], $h)->assertStatus(202)->assertJsonPath('data.status', 'running');

        // La pasada terminó hace dos minutos: hay que esperar.
        MetaSyncRun::query()->update(['status' => 'ok', 'finished_at' => now()->subMinutes(2), 'started_at' => now()->subMinutes(2)]);
        $this->postJson(self::BASE.'/sync', [], $h)->assertStatus(202)->assertJsonPath('data.status', 'cooldown');

        Carbon::setTestNow('2026-10-05 15:20:00');
        $this->postJson(self::BASE.'/sync', [], $h)->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(SyncMetaAdsInsights::class, 2);
    }

    /**
     * El 202 trae el estado de ANTES de despachar: si el job corre antes de
     * contestar (aquí la cola es síncrona), el panel ve cambiar la hora del
     * último intento y sabe que terminó, en vez de esperarlo tres minutos.
     */
    public function test_el_202_trae_el_estado_de_antes_de_despachar(): void
    {
        $this->configureMetaAds();
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-05', '9001', '1000.00')]]);
        $h = $this->adminWithPermissions(['marketing.view', 'marketing.manage']);

        $this->postJson(self::BASE.'/sync', [], $h)->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.sync.last_attempt_at', null);

        $this->assertSame('ok', MetaSyncRun::sole()->status, 'el job corrió dentro de la petición');
        $this->getJson(self::BASE.'?period=today', $h)->assertOk()
            ->assertJsonPath('data.sync.last_attempt_at', MetaSyncRun::sole()->started_at->toIso8601String());
    }

    /**
     * `/overview` no cambia: mismas claves, mismas cifras de siempre (todo el
     * histórico, pruebas incluidas), y abrir el panel nuevo no las mueve.
     */
    public function test_overview_no_ha_cambiado(): void
    {
        $real = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233', 'temperature' => 'hot']);
        $this->lead('2026-09-02 15:00:00', ['status' => 'converted']);
        $this->lead('2026-10-02 16:00:00', ['source' => 'manual_test']);
        $this->conversation($real, ['human_takeover' => true]);
        MarketingFollowup::forceCreate(['lead_id' => $real->id, 'status' => 'pending']);
        MarketingAiAction::forceCreate(['lead_id' => $real->id, 'action_type' => 'reply', 'status' => 'executed']);
        MarketingCampaign::forceCreate(['name' => 'Campaña vieja', 'spend' => 50000]);
        [$user] = $this->customer('3001112233');
        $this->payment($user, 120000, '2026-10-03 15:00:00');

        $esperado = [
            'ok' => true,
            'data' => [
                'spend_total' => 50000,
                'leads_total' => 3,
                'conversations_total' => 1,
                'converted_leads' => 1,
                'revenue_total' => 0,
                'roas' => 0,
                'cac' => null,
                'conversion_rate' => 0.3333,
                'hot_leads' => 1,
                'pending_followups' => 1,
                'ai_actions_count' => 1,
                'human_takeover_count' => 1,
            ],
        ];

        $h = $this->adminWithPermissions(['marketing.view']);
        $this->getJson('/api/admin/marketing/overview', $h)->assertOk()->assertExactJson($esperado);

        // El panel nuevo resuelve identidades y cuenta el pago; /overview no se entera.
        $this->getJson(self::BASE.'?period=30d', $h)->assertOk()->assertJsonPath('data.kpis.converted', 1);
        $this->getJson('/api/admin/marketing/overview', $h)->assertOk()->assertExactJson($esperado);
        $this->assertNull($real->fresh()->member_id);
    }

    /** @return list<string> todas las claves de la respuesta, a cualquier profundidad */
    private function keysOf(TestResponse $res): array
    {
        $keys = [];
        $walk = function (mixed $node) use (&$walk, &$keys): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $k => $v) {
                if (is_string($k)) {
                    $keys[$k] = true;
                }
                $walk($v);
            }
        };
        $walk($res->json());

        return array_keys($keys);
    }
}
