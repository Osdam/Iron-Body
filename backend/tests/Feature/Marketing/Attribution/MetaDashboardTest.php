<?php

namespace Tests\Feature\Marketing\Attribution;

use App\Models\MarketingAiAction;
use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use App\Services\Marketing\Attribution\MetaDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Feature\Marketing\MetaAds\FakesMetaAds;
use Tests\TestCase;

/**
 * Las cifras del panel: un solo periodo, un solo universo y ningún número
 * inventado.
 *
 * Reloj: 5 de octubre de 2026, 10:00 en Bogotá (15:00 UTC). El periodo de
 * casi todas las pruebas es del 1 al 5 de octubre en Bogotá, es decir
 * [2026-10-01 05:00, 2026-10-06 05:00) en UTC.
 */
class MetaDashboardTest extends TestCase
{
    use FakesMetaAds, RefreshDatabase, SeedsAttribution;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 15:00:00');
        config()->set('marketing.attribution.window_days', 30);
        // Las pruebas dan de alta socios a mitad de prueba: sin el índice de
        // teléfonos en caché (tiene su propia prueba en MetaDashboardCacheTest).
        config()->set('marketing.attribution.phone_index_cache_seconds', 0);
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
    private function dashboard(string $from = '2026-10-01', string $to = '2026-10-05'): array
    {
        return $this->service()->dashboard($this->service()->period('custom', $from, $to));
    }

    /**
     * Gasto real sincronizado del 1 al 5 de octubre: 100.000 COP en la campaña
     * de octubre (anuncio 120230001) y 0 en la de reels.
     */
    private function syncSpend(string $currency = 'COP', array $rows = []): void
    {
        $this->configureMetaAds();
        $this->fakeMeta(['data' => $rows ?: [
            $this->insightRow('2026-10-02', '120230001', '60000.00', ['account_currency' => $currency]),
            $this->insightRow('2026-10-03', '120230001', '40000.00', ['account_currency' => $currency]),
            $this->insightRow('2026-10-03', '120230002', '0.00', [
                'campaign_id' => '120202', 'campaign_name' => 'Campaña Reels',
                'adset_id' => '120212', 'adset_name' => 'Reels Neiva', 'account_currency' => $currency,
            ]),
        ]]);

        $result = $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));
        $this->assertSame('ok', $result['status']);
    }

    /** Un lead de pauta del anuncio dado, con su contacto registrado por el servicio real. */
    private function paidLead(string $at, string $adId, string $url = 'https://fb.me/2xYz9Ab', array $attrs = []): MarketingLead
    {
        $lead = $this->lead($at, $attrs);
        $this->touch($lead, $this->adReferral($adId, $url, 'clid-'.$lead->id), $at);

        return $lead;
    }

    /**
     * El escenario de los contratos 14, 15 y 16.
     *
     * Pauta: L1 y L2 compran (150.000 y 100.000); L3 no.
     * Orgánico: L4 compra 80.000 (no cuenta para el ROAS).
     * Sin atribuir: L5 ya era cliente y renueva con 50.000.
     *
     * @return array<string, MarketingLead>
     */
    private function scenario(bool $withPayments = true): array
    {
        [$u1] = $this->customer('3001110001');
        [$u2] = $this->customer('3001110002');
        [$u4] = $this->customer('3001110004');
        [$u5] = $this->customer('3001110005');

        $l1 = $this->paidLead('2026-10-02 15:00:00', '120230001', attrs: ['phone' => '573001110001']);
        $l2 = $this->paidLead('2026-10-02 16:00:00', '120230001', attrs: ['phone' => '573001110002']);
        $l3 = $this->paidLead('2026-10-03 15:00:00', '120230001', attrs: ['phone' => '573001110003']);
        $l4 = $this->lead('2026-10-03 16:00:00', ['phone' => '573001110004']);
        $this->touch($l4, $this->postReferral(), '2026-10-03 16:00:00');
        $l5 = $this->lead('2026-10-04 15:00:00', ['phone' => '573001110005']);
        $this->touch($l5, null, '2026-10-04 15:00:00');

        if ($withPayments) {
            $this->payment($u1, 150000, '2026-10-03 15:00:00');
            $this->payment($u2, 100000, '2026-10-04 15:00:00');
            $this->payment($u4, 80000, '2026-10-04 16:00:00');
            $this->payment($u5, 70000, '2026-08-01 15:00:00');   // antes de escribir: ya era cliente
            $this->payment($u5, 50000, '2026-10-04 18:00:00');
        }

        return compact('l1', 'l2', 'l3', 'l4', 'l5');
    }

    /** Contrato 14: ROAS = ingresos de los leads de PAUTA / gasto. */
    public function test_roas_solo_con_el_universo_pauta(): void
    {
        $this->syncSpend();
        $this->scenario();

        $d = $this->dashboard();

        $this->assertSame('ok', $d['spend']['status']);
        $this->assertSame(100000.0, $d['spend']['amount']);
        $this->assertSame(['leads' => 3, 'converted' => 2, 'revenue' => 250000.0], $d['kpis']['paid']);
        $this->assertSame(2.5, $d['kpis']['roas']);
        $this->assertNull($d['kpis']['roas_reason']);
    }

    /** Contrato 15: CAC = gasto / clientes NUEVOS de pauta. */
    public function test_cac_con_clientes_nuevos_de_pauta(): void
    {
        $this->syncSpend();
        $this->scenario();

        $kpis = $this->dashboard()['kpis'];

        $this->assertSame(50000.0, $kpis['cac']);
        $this->assertNull($kpis['cac_reason']);
    }

    /** Contrato 16: tasa de conversión = convertidos / leads, con su desglose. */
    public function test_tasa_de_conversion_y_desglose(): void
    {
        $this->syncSpend();
        $this->scenario();

        $kpis = $this->dashboard()['kpis'];

        $this->assertSame(5, $kpis['leads']);
        $this->assertSame(3, $kpis['converted']);
        $this->assertSame(1, $kpis['renewals']);
        $this->assertSame(0.6, $kpis['conversion_rate']);
        $this->assertSame(330000.0, $kpis['revenue_new']);
        $this->assertSame(50000.0, $kpis['revenue_renewal']);
        $this->assertSame(380000.0, $kpis['revenue_attributed']);
        $this->assertSame('COP', $kpis['currency']);
    }

    /** Contrato 18: sin conversiones, ceros de verdad y ningún CAC inventado. */
    public function test_conversiones_cero(): void
    {
        $this->syncSpend();
        $this->scenario(withPayments: false);

        $kpis = $this->dashboard()['kpis'];

        $this->assertSame(5, $kpis['leads']);
        $this->assertSame(0, $kpis['converted']);
        $this->assertSame(0, $kpis['renewals']);
        $this->assertSame(0.0, $kpis['revenue_attributed']);
        $this->assertSame(0.0, $kpis['conversion_rate']);
        // Hubo gasto y no hubo ingresos: un ROAS de 0 es un dato, no una ausencia.
        $this->assertSame(0.0, $kpis['roas']);
        $this->assertNull($kpis['cac']);
        $this->assertSame('no_paid_conversions', $kpis['cac_reason']);

        // Y sin leads, la tasa no se puede calcular: null, no 0 %.
        $vacio = $this->dashboard('2026-09-01', '2026-09-02')['kpis'];
        $this->assertSame(0, $vacio['leads']);
        $this->assertNull($vacio['conversion_rate']);
    }

    /** Sin gasto conocido, ROAS y CAC valen null con el motivo; nunca 0. */
    public function test_sin_gasto_valido_roas_y_cac_no_se_calculan(): void
    {
        $this->scenario();

        // Sin token ni cuenta.
        $kpis = $this->dashboard()['kpis'];
        $this->assertNull($kpis['roas']);
        $this->assertSame('spend_not_configured', $kpis['roas_reason']);
        $this->assertNull($kpis['cac']);
        $this->assertSame('spend_not_configured', $kpis['cac_reason']);

        // Configurado pero sin sincronizar.
        $this->configureMetaAds();
        $this->assertSame('spend_unknown', $this->dashboard()['kpis']['roas_reason']);
    }

    public function test_gasto_en_otra_moneda_no_se_divide(): void
    {
        $this->syncSpend('USD');
        $this->scenario();

        $kpis = $this->dashboard()['kpis'];

        $this->assertNull($kpis['roas']);
        $this->assertSame('currency_mismatch', $kpis['roas_reason']);
        $this->assertNull($kpis['cac']);
        $this->assertSame('currency_mismatch', $kpis['cac_reason']);
    }

    /**
     * Contrato 19: lo que pasa entre las 19:00 y las 23:59 de Bogotá es de ese
     * día de Bogotá, aunque en UTC ya sea el día siguiente.
     */
    public function test_borde_de_bogota_de_las_19_a_las_23_59(): void
    {
        $this->configureMetaAds();
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-01', '120230001', '30000.00'),
            $this->insightRow('2026-10-02', '120230001', '70000.00'),
        ]]);
        $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'));

        [$user] = $this->customer('3001112233');
        // 1 de octubre, 19:30 en Bogotá = 2 de octubre, 00:30 UTC.
        $tarde = $this->paidLead('2026-10-02 00:30:00', '120230001', attrs: ['phone' => '573001112233']);
        // 1 de octubre, 23:59 en Bogotá.
        $noche = $this->lead('2026-10-02 04:59:00');
        // 2 de octubre, 00:00 en Bogotá: ya es el día siguiente.
        $siguiente = $this->lead('2026-10-02 05:00:00');
        // Pago a las 21:00 de Bogotá del mismo día 1.
        $this->payment($user, 90000, '2026-10-02 02:00:00');
        // Un mensaje entrante a las 19:00 de Bogotá del día 1.
        $this->inbound($this->conversation($noche), '2026-10-02 00:00:00');
        // Una acción del agente a las 20:00 de Bogotá del día 1.
        MarketingAiAction::forceCreate([
            'lead_id' => $tarde->id, 'action_type' => 'reply', 'status' => 'executed',
            'created_at' => '2026-10-02 01:00:00', 'updated_at' => '2026-10-02 01:00:00',
        ]);

        $dia1 = $this->dashboard('2026-10-01', '2026-10-01');
        $this->assertSame(['key' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-01', 'timezone' => 'America/Bogota'], $dia1['period']);
        $this->assertSame(2, $dia1['kpis']['leads']);
        $this->assertSame(1, $dia1['kpis']['conversations']);
        $this->assertSame(1, $dia1['kpis']['converted']);
        $this->assertSame(90000.0, $dia1['kpis']['revenue_attributed']);
        $this->assertSame(1, $dia1['secondary']['ai_actions']['value']);
        $this->assertSame(30000.0, $dia1['spend']['amount'], 'el gasto del día 1 de la cuenta, no el del 2');
        $this->assertSame(3.0, $dia1['kpis']['roas']);

        $dia2 = $this->dashboard('2026-10-02', '2026-10-02');
        $this->assertSame(1, $dia2['kpis']['leads']);
        $this->assertSame(0, $dia2['kpis']['conversations']);
        $this->assertSame(0, $dia2['kpis']['converted']);
        $this->assertSame(0, $dia2['secondary']['ai_actions']['value']);
        $this->assertSame(70000.0, $dia2['spend']['amount']);

        $this->assertSame([$siguiente->id], array_column($this->service()->leads(...$this->bounds('2026-10-02'))['data'], 'id'));
    }

    /**
     * Contrato 20: todas las cifras miran el MISMO periodo. Nada de fuera entra
     * en ninguna, y el periodo que se devuelve es el que se usó.
     */
    public function test_periodo_comun_a_todas_las_cifras(): void
    {
        $this->syncSpend();

        // Dentro: un lead de pauta que compra, conversa y tiene acción del agente.
        [$dentro] = $this->customer('3001112233');
        $lead = $this->paidLead('2026-10-02 15:00:00', '120230001', attrs: ['phone' => '573001112233', 'temperature' => 'hot']);
        $this->inbound($this->conversation($lead), '2026-10-02 15:00:00');
        $this->payment($dentro, 100000, '2026-10-03 15:00:00');
        MarketingAiAction::forceCreate(['lead_id' => $lead->id, 'action_type' => 'reply', 'status' => 'executed', 'created_at' => '2026-10-02 15:01:00', 'updated_at' => '2026-10-02 15:01:00']);

        // Fuera: lo mismo, un mes antes.
        [$fuera] = $this->customer('3004445566');
        $viejo = $this->paidLead('2026-09-02 15:00:00', '120230001', attrs: ['phone' => '573004445566', 'temperature' => 'hot']);
        $this->inbound($this->conversation($viejo), '2026-09-02 15:00:00');
        $this->payment($fuera, 999000, '2026-09-03 15:00:00');
        MarketingAiAction::forceCreate(['lead_id' => $viejo->id, 'action_type' => 'reply', 'status' => 'executed', 'created_at' => '2026-09-02 15:01:00', 'updated_at' => '2026-09-02 15:01:00']);
        // Y gasto de septiembre que no se sincronizó para este periodo: no se suma.

        $d = $this->dashboard();

        $this->assertSame('2026-10-01', $d['period']['from']);
        $this->assertSame('2026-10-05', $d['period']['to']);
        $this->assertSame(1, $d['kpis']['leads']);
        $this->assertSame(1, $d['kpis']['conversations']);
        $this->assertSame(1, $d['kpis']['converted']);
        $this->assertSame(100000.0, $d['kpis']['revenue_attributed']);
        $this->assertSame(100000.0, $d['spend']['amount']);
        $this->assertSame(1.0, $d['kpis']['roas']);
        $this->assertSame(100000.0, $d['kpis']['cac']);
        $this->assertSame(1, $d['secondary']['hot_leads']['value']);
        $this->assertSame(1, $d['secondary']['ai_actions']['value']);
        $this->assertSame(1, array_sum(array_column($d['origins'], 'leads')));
        $this->assertSame(1, array_sum(array_column($d['campaigns']['rows'], 'leads')));
        $this->assertSame(1, $this->service()->leads(...$this->bounds('2026-10-01', '2026-10-05'))['meta']['total']);

        // Un lead viejo que vuelve a escribir SÍ cuenta como conversación del
        // periodo (está documentado), pero no como lead.
        $this->inbound($this->conversation($viejo), '2026-10-04 15:00:00');
        $d = $this->dashboard();
        $this->assertSame(1, $d['kpis']['leads']);
        $this->assertSame(2, $d['kpis']['conversations']);
    }

    /** Las cifras de «ahora» lo dicen, y las del periodo también. */
    public function test_cifras_secundarias_con_su_alcance(): void
    {
        $real = $this->lead('2026-10-02 15:00:00');
        $prueba = $this->lead('2026-10-02 15:00:00', ['source' => 'manual_test']);
        $this->conversation($real, ['human_takeover' => true]);
        $this->conversation($prueba, ['human_takeover' => true]);
        MarketingFollowup::forceCreate(['lead_id' => $real->id, 'status' => 'pending', 'due_at' => '2026-12-01 15:00:00']);
        MarketingFollowup::forceCreate(['lead_id' => $prueba->id, 'status' => 'pending']);
        MarketingFollowup::forceCreate(['lead_id' => $real->id, 'status' => 'done']);

        $secondary = $this->dashboard()['secondary'];

        $this->assertSame(['value' => 1, 'scope' => 'now'], $secondary['pending_followups']);
        $this->assertSame(['value' => 1, 'scope' => 'now'], $secondary['human_control']);
        $this->assertSame('period', $secondary['hot_leads']['scope']);
        $this->assertSame('period', $secondary['ai_actions']['scope']);
    }

    /** D6: sin leads de prueba, y la misma persona cuenta una vez. */
    public function test_leads_reales_y_personas_unicas(): void
    {
        $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);
        $this->lead('2026-10-03 15:00:00', ['phone' => '3001112233', 'meta_user_id' => 'otro-id']);
        foreach (['manual_test', 'internal_test', 'manual_phone_test', ''] as $source) {
            $this->lead('2026-10-02 15:00:00', ['source' => $source]);
        }

        $this->assertSame(1, $this->dashboard()['kpis']['leads']);
        // La lista enseña PERSONAS (FASE 16): la misma persona con dos leads sale una vez, como en Leads.
        $lista = $this->service()->leads(...$this->bounds('2026-10-01', '2026-10-05'));
        $this->assertSame(1, $lista['meta']['total'], 'la lista enseña a la persona una vez');
        $this->assertCount(1, $lista['data']);
    }

    /**
     * Un teléfono, dos leads, y uno enlazado a mano a la ficha de OTRA persona
     * (el hijo, con otro número). Es una persona para Leads (D6), así que
     * convierte una sola vez: nunca más convertidos que leads ni una tasa de
     * más del 100 %. El segundo lead sale como duplicado del primero.
     */
    public function test_una_persona_con_dos_leads_convierte_una_vez(): void
    {
        [$padre] = $this->customer('3001112233');
        [$hijo, $fichaHijo] = $this->customer('3209998877');
        $primero = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);
        $segundo = $this->lead('2026-10-03 15:00:00', ['phone' => '573001112233', 'meta_user_id' => 'otro', 'member_id' => $fichaHijo->id]);
        $this->payment($padre, 100000, '2026-10-03 16:00:00');
        $this->payment($hijo, 50000, '2026-10-04 15:00:00');

        $kpis = $this->dashboard()['kpis'];

        $this->assertSame(1, $kpis['leads']);
        $this->assertSame(1, $kpis['converted']);
        $this->assertSame(1.0, $kpis['conversion_rate']);
        $this->assertSame(100000.0, $kpis['revenue_attributed'], 'el dinero de la persona de su lead principal, una vez');

        // La lista enseña a la persona una vez, con su lead principal y su compra; el segundo lead no sale aparte.
        $lista = collect($this->service()->leads(...$this->bounds('2026-10-01', '2026-10-05'))['data'])->keyBy('id');
        $this->assertSame([$primero->id], $lista->keys()->all());
        $this->assertFalse($lista->has($segundo->id));
        $this->assertSame(['kind' => 'new', 'revenue' => 100000.0, 'duplicate_of' => null], $lista[$primero->id]['conversion']);
    }

    /**
     * El lead principal decide la pauta: un segundo lead de la misma persona
     * que llegó por un anuncio no suma convertidos de pauta sin sumar leads de
     * pauta. Ninguna fila (pauta, origen o campaña) tiene más convertidos que
     * leads.
     */
    public function test_la_pauta_de_un_lead_duplicado_no_cuenta_aparte(): void
    {
        [$titular] = $this->customer('3001112233');
        [, $fichaOtra] = $this->customer('3209998877');
        // Primero escribió sin anuncio y alguien lo enlazó a otra ficha; luego volvió por un anuncio.
        $primero = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233', 'member_id' => $fichaOtra->id]);
        $this->touch($primero, null, '2026-10-02 15:00:00');
        $segundo = $this->paidLead('2026-10-03 15:00:00', '120230001', attrs: ['phone' => '573001112233', 'meta_user_id' => 'otro']);
        $this->payment($titular, 120000, '2026-10-03 16:00:00');

        $d = $this->dashboard();

        $this->assertSame(['leads' => 0, 'converted' => 0, 'revenue' => 0.0], $d['kpis']['paid']);
        $this->assertLessThanOrEqual($d['kpis']['leads'], $d['kpis']['converted']);
        foreach ($d['origins'] as $row) {
            $this->assertLessThanOrEqual($row['leads'], $row['converted'], "origen {$row['key']}");
        }
        foreach ($d['campaigns']['rows'] as $row) {
            $this->assertLessThanOrEqual($row['leads'], $row['converted'], "campaña {$row['id']}");
        }

        // La persona sale una vez, con el origen de su lead principal (sin anuncio): la pauta del duplicado no aparece.
        $lista = collect($this->service()->leads(...$this->bounds('2026-10-01', '2026-10-05'))['data'])->keyBy('id');
        $this->assertSame([$primero->id], $lista->keys()->all());
        $this->assertFalse($lista->has($segundo->id));
        $this->assertSame('unknown', $lista[$primero->id]['first_touch']['origin']);
    }

    /**
     * La persona pertenece al periodo de su PRIMER contacto de siempre. Un
     * subperiodo no puede atribuir a la pauta más de lo que atribuye el periodo
     * que lo contiene: el lead principal no cambia con el periodo elegido.
     */
    public function test_un_subperiodo_no_atribuye_mas_que_su_periodo(): void
    {
        $this->syncSpend();
        [$user] = $this->customer('3001112233');
        // Escribió el 25 de septiembre sin anuncio, y el 1 de octubre volvió por uno.
        $antes = $this->lead('2026-09-25 15:00:00', ['phone' => '573001112233']);
        $this->touch($antes, null, '2026-09-25 15:00:00');
        $despues = $this->paidLead('2026-10-01 15:00:00', '120230001', attrs: ['phone' => '573001112233', 'meta_user_id' => 'otro']);
        $this->payment($user, 200000, '2026-10-01 16:00:00');

        $mes = $this->dashboard('2026-09-06', '2026-10-05');
        $semana = $this->dashboard('2026-09-29', '2026-10-05');

        // En el mes, la persona es un lead sin atribuir que compró.
        $this->assertSame(1, $mes['kpis']['leads']);
        $this->assertSame(1, $mes['kpis']['converted']);
        $this->assertSame(0.0, $mes['kpis']['paid']['revenue']);

        // En la semana no es un lead nuevo: ya había escrito antes.
        $this->assertSame(0, $semana['kpis']['leads']);
        $this->assertSame(0, $semana['kpis']['converted']);
        foreach (['paid', 'paid_resolved'] as $universe) {
            foreach (['leads', 'converted', 'revenue'] as $field) {
                $this->assertLessThanOrEqual($mes['kpis'][$universe][$field], $semana['kpis'][$universe][$field], "{$universe}.{$field}");
            }
        }
        $this->assertLessThanOrEqual($mes['kpis']['revenue_attributed'], $semana['kpis']['revenue_attributed']);

        // Y la lista de la semana no la enseña: no es una persona nueva de la semana (Leads = 0).
        $lista = $this->service()->leads(...$this->bounds('2026-09-29', '2026-10-05'));
        $this->assertSame([], $lista['data']);
        $this->assertSame(0, $lista['meta']['total']);
        $this->assertNotNull($despues);
    }

    /** «Origen de leads»: solo las categorías con evidencia (FASE 13), en su orden, y «Sin atribuir» bien visible. */
    public function test_origen_de_leads(): void
    {
        $this->scenario();
        $ig = $this->paidLead('2026-10-04 16:00:00', '120230002', 'https://www.instagram.com/reel/abc');

        $d = $this->dashboard();
        $origins = collect($d['origins'])->keyBy('key');

        $this->assertSame(
            ['facebook_ads', 'instagram_ads', 'instagram_organic', 'unattributed'],
            array_column($d['origins'], 'key'),
        );
        $this->assertSame(['leads' => 3, 'share' => 0.5, 'converted' => 2, 'revenue' => 250000.0], Arr::only($origins['facebook_ads'], ['leads', 'share', 'converted', 'revenue']));
        $this->assertSame(1, $origins['instagram_ads']['leads']);
        $this->assertSame(['leads' => 1, 'converted' => 1, 'revenue' => 80000.0], Arr::only($origins['instagram_organic'], ['leads', 'converted', 'revenue']));
        // Sin evidencia, sin fila: no se pinta «WhatsApp directo» ni «Facebook orgánico» en cero.
        $this->assertFalse($origins->has('whatsapp_direct'));
        $this->assertFalse($origins->has('facebook_organic'));
        // Las cuotas (redondeadas a 4 decimales cada una) suman el 100 %.
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($d['origins'], 'share')), 0.0005);
        $this->assertSame(['leads' => 1, 'converted' => 0, 'revenue' => 50000.0], Arr::only($origins['unattributed'], ['leads', 'converted', 'revenue']));
        $this->assertSame('Sin atribuir', $origins['unattributed']['label']);
        $this->assertSame(0.1667, $d['unattributed_share']);
        $this->assertNotNull($ig);
    }

    /**
     * Contrato 21 (lado B): campaña, conjunto y anuncio salen de la dimensión de
     * Meta, y los leads de pauta sin anuncio conocido van a «Sin campaña».
     */
    public function test_campanas_conjuntos_y_anuncios(): void
    {
        $this->syncSpend();
        $this->scenario();
        // Un anuncio que Meta no ha devuelto: no se le inventa campaña.
        $this->paidLead('2026-10-04 17:00:00', '120239999');
        // Un lead viejo de la campaña de octubre que vuelve a escribir.
        $viejo = $this->paidLead('2026-09-10 15:00:00', '120230001');
        $this->inbound($this->conversation($viejo), '2026-10-03 15:00:00');

        $rows = collect($this->dashboard()['campaigns']['rows']);
        // Con Meta Ads conectado, el anuncio que no está en la cuenta no es «falta de acceso».
        $this->assertSame(['Campaña Octubre', 'Campaña Reels', 'Sin campaña (anuncio fuera de la cuenta conectada)'], $rows->pluck('name')->all());

        $octubre = $rows->firstWhere('name', 'Campaña Octubre');
        $this->assertSame(100000.0, $octubre['spend']);
        $this->assertSame('COP', $octubre['currency']);
        $this->assertSame(3, $octubre['leads']);
        $this->assertSame(1, $octubre['conversations']);
        $this->assertSame(2, $octubre['converted']);
        $this->assertSame(250000.0, $octubre['revenue']);
        $this->assertSame(2.5, $octubre['roas']);
        $this->assertSame(50000.0, $octubre['cac']);
        $this->assertSame(0.6667, $octubre['conversion_rate']);
        $this->assertSame('***0201', $octubre['ref']);
        $this->assertStringNotContainsString('120201', $octubre['id']);

        $reels = $rows->firstWhere('name', 'Campaña Reels');
        $this->assertSame(0.0, $reels['spend'], 'gasto cero sincronizado: cero de verdad');
        $this->assertNull($reels['roas']);
        $this->assertSame(0, $reels['leads']);

        $sin = $rows->last();
        $this->assertSame('unresolved', $sin['id']);
        $this->assertNull($sin['spend']);
        $this->assertSame(1, $sin['leads']);
        $this->assertNull($sin['ref']);

        $adsets = collect($this->service()->campaigns('adset', ...$this->bounds('2026-10-01', '2026-10-05')));
        $this->assertSame(['Neiva 18-35', 'Reels Neiva', 'Sin campaña (anuncio fuera de la cuenta conectada)'], $adsets->pluck('name')->all());
        $this->assertSame(3, $adsets->first()['leads']);

        $ads = collect($this->service()->campaigns('ad', ...$this->bounds('2026-10-01', '2026-10-05')));
        $this->assertSame('Video promo ***0001', $ads->first()['name']);
        $this->assertSame(100000.0, $ads->first()['spend']);
        $this->assertSame('***0001', $ads->first()['ref']);
        $this->assertSame(1, $ads->firstWhere('id', 'unresolved')['leads']);
    }

    /**
     * ROAS y CAC con el MISMO universo que el gasto: solo las personas de pauta
     * cuyo anuncio es de la cuenta conectada. Un anuncio de otra cuenta trae
     * ventas, pero su gasto no está en el denominador: no infla el ROAS, va a
     * «Sin campaña» y se avisa.
     */
    public function test_roas_y_cac_solo_con_la_pauta_de_la_cuenta_conectada(): void
    {
        $this->syncSpend(); // 100.000 de la cuenta, en el anuncio 120230001

        [$u1] = $this->customer('3001110001');
        [$u2] = $this->customer('3001110002');
        $this->paidLead('2026-10-02 15:00:00', '120230001', attrs: ['phone' => '573001110001']);
        // Un anuncio que no es de la cuenta conectada (otra cuenta, otra página).
        $this->paidLead('2026-10-02 16:00:00', '555000111', attrs: ['phone' => '573001110002']);
        $this->payment($u1, 50000, '2026-10-03 15:00:00');
        $this->payment($u2, 150000, '2026-10-03 16:00:00');

        $d = $this->dashboard();
        $kpis = $d['kpis'];

        $this->assertSame(['leads' => 2, 'converted' => 2, 'revenue' => 200000.0], $kpis['paid']);
        $this->assertSame(['leads' => 1, 'converted' => 1, 'revenue' => 50000.0, 'revenue_new' => 50000.0, 'renewals' => 0, 'revenue_renewal' => 0.0], $kpis['paid_resolved']);
        $this->assertSame(0.5, $kpis['roas'], 'solo los ingresos de la cuenta del gasto');
        $this->assertSame(0.5, $kpis['roas_new']);
        $this->assertSame(100000.0, $kpis['cac'], 'solo los clientes nuevos de la cuenta del gasto');
        $this->assertNull($kpis['roas_reason']);
        $this->assertSame(1, $kpis['unresolved_paid_leads']);
        $this->assertSame('unresolved_paid_leads', $kpis['roas_warning']);

        $rows = collect($d['campaigns']['rows'])->keyBy('name');
        $this->assertSame([100000.0, 1, 50000.0, 0.5, 100000.0], [
            $rows['Campaña Octubre']['spend'], $rows['Campaña Octubre']['leads'], $rows['Campaña Octubre']['revenue'],
            $rows['Campaña Octubre']['roas'], $rows['Campaña Octubre']['cac'],
        ]);
        $fuera = $rows['Sin campaña (anuncio fuera de la cuenta conectada)'];
        $this->assertSame(['unresolved', null, 1, 150000.0, null], [$fuera['id'], $fuera['spend'], $fuera['leads'], $fuera['revenue'], $fuera['roas']]);
    }

    /** ROAS de clientes nuevos: sin las renovaciones de quien ya pagaba. */
    public function test_roas_de_clientes_nuevos(): void
    {
        $this->syncSpend();
        [$nuevo] = $this->customer('3001110001');
        [$antiguo] = $this->customer('3001110002');
        $this->payment($antiguo, 70000, '2026-08-01 15:00:00'); // ya era cliente
        $this->paidLead('2026-10-02 15:00:00', '120230001', attrs: ['phone' => '573001110001']);
        $this->paidLead('2026-10-02 16:00:00', '120230001', attrs: ['phone' => '573001110002']);
        $this->payment($nuevo, 60000, '2026-10-03 15:00:00');
        $this->payment($antiguo, 90000, '2026-10-03 16:00:00');

        $kpis = $this->dashboard()['kpis'];

        $this->assertSame(['leads' => 2, 'converted' => 1, 'revenue' => 150000.0, 'revenue_new' => 60000.0, 'renewals' => 1, 'revenue_renewal' => 90000.0], $kpis['paid_resolved']);
        $this->assertSame(1.5, $kpis['roas']);
        $this->assertSame(0.6, $kpis['roas_new']);
        $this->assertNull($kpis['roas_new_reason']);
        $this->assertNull($kpis['roas_warning']);
        $this->assertSame(0, $kpis['unresolved_paid_leads']);
    }

    /**
     * Gasto cero comprobado y leads de pauta que no son de la cuenta: el cero
     * es de la cuenta, no de esos anuncios. Se avisa en vez de callar.
     */
    public function test_gasto_cero_con_pauta_de_otra_cuenta_avisa(): void
    {
        $this->configureMetaAds();
        $this->fakeMeta(['data' => []]);
        $this->assertSame('ok', $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);

        foreach (['555000111', '555000112', '555000113'] as $i => $adId) {
            $this->paidLead('2026-10-0'.($i + 2).' 15:00:00', $adId);
        }

        $d = $this->dashboard();

        $this->assertSame('real_zero', $d['spend']['status']);
        $this->assertNull($d['kpis']['roas']);
        $this->assertSame('spend_real_zero', $d['kpis']['roas_reason']);
        $this->assertSame(3, $d['kpis']['unresolved_paid_leads']);
        $this->assertSame('unresolved_paid_leads', $d['kpis']['roas_warning']);
        $this->assertSame(['unresolved'], array_column($d['campaigns']['rows'], 'id'));
        $this->assertSame('Sin campaña (anuncio fuera de la cuenta conectada)', $d['campaigns']['rows'][0]['name']);
    }

    /**
     * Al cambiar la cuenta configurada, lo sincronizado de la anterior ya no
     * resuelve leads: su campaña no sale con «$ 0» comprobado, porque su gasto
     * en la cuenta actual no se conoce. Sus leads van a «Sin campaña».
     */
    public function test_al_cambiar_de_cuenta_la_campana_de_la_anterior_no_sale_con_cero(): void
    {
        $this->configureMetaAds(['ad_account_id' => '1111111111']);
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-02', '120230001', '50000.00')]]);
        $this->assertSame('ok', $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);
        $this->paidLead('2026-10-02 15:00:00', '120230001');

        $this->configureMetaAds(['ad_account_id' => 'act_2222222222']);
        $this->fakeMeta(['data' => [$this->insightRow('2026-10-03', '120239002', '30000.00', [
            'campaign_id' => '120209', 'campaign_name' => 'Campaña B', 'adset_id' => '120219', 'adset_name' => 'Conjunto B',
        ])]]);
        $this->assertSame('ok', $this->sync()->run('manual', $this->day('2026-10-01'), $this->day('2026-10-05'))['status']);

        $rows = $this->dashboard()['campaigns']['rows'];

        $this->assertSame(['Campaña B', 'Sin campaña (anuncio fuera de la cuenta conectada)'], array_column($rows, 'name'));
        $this->assertSame([30000.0, 0], [$rows[0]['spend'], $rows[0]['leads']]);
        $this->assertSame([null, 1], [$rows[1]['spend'], $rows[1]['leads']]);
    }

    /**
     * El id público de una fila no se puede invertir: es un HMAC con la clave de
     * la aplicación, no un SHA-256 sin secreto que, junto con el `ref` y el
     * prefijo típico de los ids de Meta, se rompe por fuerza bruta.
     */
    public function test_el_id_opaco_lleva_la_clave_de_la_aplicacion(): void
    {
        $this->syncSpend();
        $this->paidLead('2026-10-02 15:00:00', '120230001');

        $id = collect($this->dashboard()['campaigns']['rows'])->firstWhere('name', 'Campaña Octubre')['id'];

        $this->assertNotSame('campaign:'.substr(hash('sha256', 'campaign|120201'), 0, 16), $id, 'SHA-256 sin secreto');
        $this->assertSame('campaign:'.substr(hash_hmac('sha256', 'campaign|120201', (string) config('app.key')), 0, 16), $id);
        $this->assertSame($id, collect($this->dashboard()['campaigns']['rows'])->firstWhere('name', 'Campaña Octubre')['id'], 'estable');

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $this->assertNotSame($id, MetaDashboardService::opaqueId('campaign', '120201'), 'depende de la clave');
    }

    public function test_sin_conexion_a_meta_los_leads_de_pauta_van_a_sin_campana(): void
    {
        $this->scenario();

        $rows = $this->dashboard()['campaigns']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('unresolved', $rows[0]['id']);
        $this->assertSame('Sin campaña (Meta Ads sin conexión)', $rows[0]['name']);
        $this->assertSame(3, $rows[0]['leads']);
        $this->assertSame(2, $rows[0]['converted']);
        $this->assertNull($rows[0]['spend']);
        $this->assertNull($rows[0]['roas']);
        $this->assertNull($rows[0]['cac']);
    }

    /** La lista: del contacto más reciente al más antiguo, con origen y conversión. */
    public function test_lista_de_leads_con_toques_y_conversion(): void
    {
        $this->syncSpend();
        $l = $this->scenario();
        // L3 vuelve días después desde Instagram: su último toque cambia, el primero no.
        $this->touch($l['l3'], $this->adReferral('120230002', 'https://www.instagram.com/reel/x', 'clid-otro'), '2026-10-04 20:00:00');

        $page = $this->service()->leads(...$this->bounds('2026-10-01', '2026-10-05'), page: 1, perPage: 2);

        $this->assertSame(['current_page' => 1, 'last_page' => 3, 'per_page' => 2, 'total' => 5], $page['meta']);
        $this->assertSame([$l['l5']->id, $l['l4']->id], array_column($page['data'], 'id'));

        $renovacion = $page['data'][0];
        $this->assertSame(['kind' => 'renewal', 'revenue' => 50000.0, 'duplicate_of' => null], $renovacion['conversion']);
        $this->assertSame('unattributed', $renovacion['first_touch']['key']);
        $this->assertSame('Sin atribuir', $renovacion['first_touch']['label']);
        $this->assertSame('2026-10-04T15:00:00+00:00', $renovacion['first_contact_at']);

        $page2 = $this->service()->leads(...$this->bounds('2026-10-01', '2026-10-05'), page: 2, perPage: 2);
        $l3 = collect($page2['data'])->firstWhere('id', $l['l3']->id);
        $this->assertSame(['origin' => 'paid', 'platform' => 'facebook', 'key' => 'facebook_ads', 'label' => 'Facebook Ads',
            'campaign_name' => 'Campaña Octubre', 'adset_name' => 'Neiva 18-35', 'ad_name' => 'Video promo ***0001', 'ad_ref' => '***0001'], $l3['first_touch']);
        $this->assertSame('instagram_ads', $l3['last_touch']['key']);
        $this->assertSame('Campaña Reels', $l3['last_touch']['campaign_name']);
        $this->assertSame(['kind' => 'unlinked', 'revenue' => 0.0, 'duplicate_of' => null], $l3['conversion']);

        // per_page no pasa de 50.
        $this->assertSame(50, $this->service()->leads(...$this->bounds('2026-10-01', '2026-10-05'), perPage: 500)['meta']['per_page']);
    }

    /** Los periodos se calculan en Bogotá, también a las 21:00 de Bogotá (ya mañana en UTC). */
    public function test_periodos_en_hora_de_bogota(): void
    {
        Carbon::setTestNow('2026-10-01 02:00:00'); // 30 de septiembre, 21:00 en Bogotá
        $s = $this->service();

        $this->assertSame(['2026-09-30', '2026-09-30'], [$s->period('today')['from'], $s->period('today')['to']]);
        $this->assertSame('2026-09-24', $s->period('7d')['from']);
        $this->assertSame('2026-09-01', $s->period('30d')['from']);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$s->period('this_month')['from'], $s->period('this_month')['to']]);
        $this->assertSame(['2026-08-01', '2026-08-31'], [$s->period('last_month')['from'], $s->period('last_month')['to']]);
        $this->assertSame('30d', $s->period()['key']);

        $hoy = $s->period('today');
        $this->assertSame('2026-09-30 05:00:00', $hoy['start']->toDateTimeString());
        $this->assertSame('2026-10-01 05:00:00', $hoy['end']->toDateTimeString());
        $this->assertSame('UTC', $hoy['start']->timezoneName);

        $custom = $s->period(null, '2026-02-01', '2026-02-28');
        $this->assertSame('custom', $custom['key']);
        $this->assertSame('2026-03-01 05:00:00', $custom['end']->toDateTimeString());
    }

    /**
     * «Este mes» es del día 1 a HOY en Bogotá, no a fin de mes: el periodo que
     * se devuelve dice lo que cubren las cifras, igual que calcula el front.
     */
    public function test_este_mes_llega_hasta_hoy(): void
    {
        Carbon::setTestNow('2026-10-16 02:30:00'); // 15 de octubre, 21:30 en Bogotá
        $mes = $this->service()->period('this_month');

        $this->assertSame(['2026-10-01', '2026-10-15'], [$mes['from'], $mes['to']]);
        $this->assertSame('2026-10-01 05:00:00', $mes['start']->toDateTimeString());
        $this->assertSame('2026-10-16 05:00:00', $mes['end']->toDateTimeString(), 'fin exclusivo: la medianoche de Bogotá tras hoy');

        $panel = $this->service()->dashboard($mes);
        $this->assertSame(['key' => 'this_month', 'from' => '2026-10-01', 'to' => '2026-10-15', 'timezone' => 'America/Bogota'], $panel['period']);
    }

    public function test_periodos_invalidos(): void
    {
        $s = $this->service();
        foreach ([
            ['yesterday', null, null],
            ['custom', null, '2026-10-01'],
            ['custom', '2026-02-31', '2026-03-01'],
            ['custom', '2026-10-05', '2026-10-01'],
            ['custom', '2025-01-01', '2026-10-01'],
            ['custom', '01/10/2026', '2026-10-05'],
        ] as [$key, $from, $to]) {
            try {
                $s->period($key, $from, $to);
                $this->fail("Se aceptó un periodo inválido: {$key} {$from} {$to}");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function bounds(string $from, ?string $to = null): array
    {
        $p = $this->service()->period('custom', $from, $to ?? $from);

        return [$p['start'], $p['end']];
    }
}
