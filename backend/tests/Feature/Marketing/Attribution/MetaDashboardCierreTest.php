<?php

namespace Tests\Feature\Marketing\Attribution;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\MarketingLead;
use App\Models\MetaAdReachSnapshot;
use App\Services\Marketing\Attribution\MetaDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Marketing\MetaAds\FakesMetaAds;
use Tests\TestCase;

/**
 * Cierre del Objetivo 3, lado panel: métricas que no se mezclan, el retorno
 * después de pauta, el periodo anterior, los ceros en el denominador, la persona
 * con dos perfiles, «Otros», el desglose perezoso, lo parcial y lo que Meta no
 * reporta.
 *
 * Reloj: 5 de octubre de 2026, 10:00 en Bogotá (15:00 UTC).
 */
class MetaDashboardCierreTest extends TestCase
{
    use FakesMetaAds, RefreshDatabase, SeedsAttribution;

    private const BASE = '/api/admin/marketing/meta-dashboard';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 15:00:00');
        config()->set('caja.timezone', 'America/Bogota');
        config()->set('marketing.attribution.window_days', 30);
        config()->set('marketing.attribution.phone_index_cache_seconds', 0);
        foreach (Admin::ROLES as $rol) {
            AdminRole::firstOrCreate(['name' => $rol], ['is_system' => true]);
        }
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

    /** @return array{0: mixed, 1: mixed} */
    private function bounds(string $from, ?string $to = null): array
    {
        $p = $this->service()->period('custom', $from, $to ?? $from);

        return [$p['start'], $p['end']];
    }

    /**
     * Gasto real sincronizado de `$from` a `$to`: por defecto 100.000 COP del 1 al
     * 5 de octubre en la campaña 120201 (anuncio 120230001).
     */
    private function syncSpend(array $rows = [], string $from = '2026-10-01', string $to = '2026-10-05'): void
    {
        $this->configureMetaAds();
        $this->fakeMeta(['data' => $rows ?: [
            $this->insightRow('2026-10-02', '120230001', '60000.00'),
            $this->insightRow('2026-10-03', '120230001', '40000.00'),
        ]]);
        $this->assertSame('ok', $this->sync()->run('manual', $this->day($from), $this->day($to))['status']);
    }

    private function paidLead(string $at, string $adId, string $url = 'https://fb.me/2xYz9Ab', array $attrs = []): MarketingLead
    {
        $lead = $this->lead($at, $attrs);
        $this->touch($lead, $this->adReferral($adId, $url, 'clid-'.$lead->id), $at);

        return $lead;
    }

    // ── FASE 8 y 9: universos que no se mezclan y retorno después de pauta ──

    /** @return array<string, array{0: float, 1: float, 2: string}> */
    public static function retornos(): array
    {
        return [
            'positivo' => [150000.0, 50000.0, 'positive'],
            'equilibrio' => [100000.0, 0.0, 'breakeven'],
            'negativo' => [40000.0, -60000.0, 'negative'],
        ];
    }

    /** Retorno = ingresos Meta − gasto Meta. Lo orgánico no entra, ni en el KPI ni en la fila de la campaña. */
    #[DataProvider('retornos')]
    public function test_retorno_despues_de_pauta(float $cobro, float $retorno, string $estado): void
    {
        $this->syncSpend();
        [$cliente] = $this->customer('3001110001');
        [$organico] = $this->customer('3001110004');
        $this->paidLead('2026-10-02 15:00:00', '120230001', attrs: ['phone' => '573001110001']);
        $l4 = $this->lead('2026-10-03 16:00:00', ['phone' => '573001110004']);
        $this->touch($l4, $this->postReferral(), '2026-10-03 16:00:00');
        $this->payment($cliente, $cobro, '2026-10-03 15:00:00');
        $this->payment($organico, 80000, '2026-10-04 16:00:00');

        $d = $this->dashboard();

        $this->assertSame($cobro + 80000, $d['kpis']['revenue_attributed'], 'TOTAL: todos los leads');
        $this->assertSame($cobro, $d['kpis']['paid_resolved']['revenue'], 'META: solo la pauta de la cuenta');
        $this->assertSame($retorno, $d['kpis']['return_after_ad_spend']);
        $this->assertSame($estado, $d['kpis']['return_state']);
        $this->assertNull($d['kpis']['return_after_ad_spend_reason']);

        $fila = collect($d['campaigns']['rows'])->firstWhere('name', 'Campaña Octubre');
        $this->assertSame($retorno, $fila['return_after_ad_spend']);
        $this->assertSame([$cobro, 100000.0], [$fila['revenue'], $fila['spend']]);
    }

    /** El retorno solo resta a lo que trajo la cuenta del gasto: la pauta de OTRA cuenta no entra. */
    public function test_el_retorno_no_incluye_la_pauta_de_otra_cuenta(): void
    {
        $this->syncSpend();
        [$propio] = $this->customer('3001110001');
        [$ajeno] = $this->customer('3001110002');
        $this->paidLead('2026-10-02 15:00:00', '120230001', attrs: ['phone' => '573001110001']);
        // Anuncio que no está en la cuenta conectada: es pauta, pero de un gasto que no se conoce.
        $this->paidLead('2026-10-02 16:00:00', '999000111', attrs: ['phone' => '573001110002']);
        $this->payment($propio, 150000, '2026-10-03 15:00:00');
        $this->payment($ajeno, 70000, '2026-10-03 16:00:00');

        $k = $this->dashboard()['kpis'];

        $this->assertSame([220000.0, 150000.0], [$k['paid']['revenue'], $k['paid_resolved']['revenue']]);
        $this->assertSame(50000.0, $k['return_after_ad_spend'], '150.000 de la cuenta − 100.000 de gasto; los 70.000 de otra cuenta no');
        $this->assertSame(1, $k['unresolved_paid_leads']);
    }

    /** Con gasto cero COMPROBADO el retorno es el ingreso; no se divide (ROAS y CAC sin cifra). */
    public function test_retorno_con_gasto_cero_comprobado(): void
    {
        $this->syncSpend([$this->insightRow('2026-10-02', '120230001', '0.00')]);
        [$cliente] = $this->customer('3001110001');
        $this->paidLead('2026-10-02 15:00:00', '120230001', attrs: ['phone' => '573001110001']);
        $this->payment($cliente, 50000, '2026-10-03 15:00:00');

        $k = $this->dashboard()['kpis'];

        $this->assertSame([50000.0, 'positive'], [$k['return_after_ad_spend'], $k['return_state']]);
        $this->assertSame([null, 'spend_real_zero'], [$k['roas'], $k['roas_reason']]);
        $this->assertNull($k['cac']);
    }

    /** Sin conexión a Meta no hay retorno: ni cero ni el ingreso entero. */
    public function test_sin_conexion_no_hay_retorno(): void
    {
        [$cliente] = $this->customer('3001110001');
        $this->paidLead('2026-10-02 15:00:00', '120230001', attrs: ['phone' => '573001110001']);
        $this->payment($cliente, 50000, '2026-10-03 15:00:00');

        $k = $this->dashboard()['kpis'];

        $this->assertNull($k['return_after_ad_spend']);
        $this->assertNull($k['return_state']);
        $this->assertSame('spend_not_configured', $k['return_after_ad_spend_reason']);
    }

    /** Cada conversión con su universo; ROAS y CAC con el mismo universo que el gasto. */
    public function test_cada_conversion_y_cada_ratio_con_su_universo(): void
    {
        $this->syncSpend();
        [$u1] = $this->customer('3001110001');
        [$u2] = $this->customer('3001110002');
        [$u4] = $this->customer('3001110004');
        [$u5] = $this->customer('3001110005');
        $this->paidLead('2026-10-02 15:00:00', '120230001', attrs: ['phone' => '573001110001']);
        $this->paidLead('2026-10-02 16:00:00', '120230001', attrs: ['phone' => '573001110002']);
        $this->paidLead('2026-10-03 15:00:00', '120230001', attrs: ['phone' => '573001110003']);
        $l4 = $this->lead('2026-10-03 16:00:00', ['phone' => '573001110004']);
        $this->touch($l4, $this->postReferral(), '2026-10-03 16:00:00');
        $l5 = $this->lead('2026-10-04 15:00:00', ['phone' => '573001110005']);
        $this->touch($l5, null, '2026-10-04 15:00:00');
        $this->payment($u1, 150000, '2026-10-03 15:00:00');
        $this->payment($u2, 100000, '2026-10-04 15:00:00');
        $this->payment($u4, 80000, '2026-10-04 16:00:00');
        $this->payment($u5, 70000, '2026-08-01 15:00:00');
        $this->payment($u5, 50000, '2026-10-04 18:00:00');

        $k = $this->dashboard()['kpis'];

        // General: 3 nuevos (dos de pauta y uno orgánico) de 5 leads.
        $this->assertSame([5, 3, 1, 0.6], [$k['leads'], $k['converted'], $k['renewals'], $k['conversion_rate']]);
        // Meta: 2 nuevos de 3 leads de pauta. Ni el orgánico ni la renovación sin atribuir entran.
        $this->assertSame([3, 2, 0, 250000.0], [$k['paid_resolved']['leads'], $k['paid_resolved']['converted'], $k['paid_resolved']['renewals'], $k['paid_resolved']['revenue']]);
        $this->assertSame(0.6667, $k['meta_conversion_rate']);
        // ROAS × gasto = ingresos Meta; CAC × nuevos de pauta = gasto. El mismo universo arriba y abajo.
        $this->assertSame(250000.0, round($k['roas'] * 100000, 2));
        $this->assertSame(100000.0, round($k['cac'] * $k['paid_resolved']['converted'], 2));
    }

    // ── FASE 10: periodo anterior ───────────────────────────────────────────

    public function test_el_periodo_anterior_es_el_mismo_numero_de_dias_justo_antes(): void
    {
        $this->syncSpend([
            $this->insightRow('2026-09-28', '120230001', '30000.00'),
            $this->insightRow('2026-10-02', '120230001', '60000.00'),
        ], '2026-09-26', '2026-10-05');
        [$antes] = $this->customer('3001110009');
        $this->paidLead('2026-09-27 15:00:00', '120230001', attrs: ['phone' => '573001110009']);
        $this->payment($antes, 30000, '2026-09-28 15:00:00');
        $this->paidLead('2026-10-02 15:00:00', '120230001');
        $this->lead('2026-10-03 15:00:00');

        $d = $this->dashboard();

        $this->assertSame(['from' => '2026-09-26', 'to' => '2026-09-30'], $d['previous_period']);
        $this->assertSame(2, $d['kpis']['leads']);
        $p = $d['previous'];
        $this->assertSame(['from' => '2026-09-26', 'to' => '2026-09-30'], $p['period']);
        $this->assertSame(['status' => 'ok', 'amount' => 30000.0, 'currency' => 'COP', 'complete' => true], $p['spend']);
        $this->assertSame(
            ['leads' => 1, 'conversations' => 0, 'converted' => 1, 'renewals' => 0, 'revenue_attributed' => 30000.0,
                'meta_leads' => 1, 'meta_converted' => 1, 'meta_revenue' => 30000.0, 'roas' => 1.0, 'cac' => 30000.0,
                'conversion_rate' => 1.0, 'meta_conversion_rate' => 1.0, 'return_after_ad_spend' => 0.0],
            $p['kpis'],
        );

        // «30 días»: los 30 anteriores.
        $treinta = $this->service()->dashboard($this->service()->period('30d'));
        $this->assertSame(['from' => '2026-08-07', 'to' => '2026-09-05'], $treinta['previous_period']);
    }

    /** Sin base anterior no se inventa nada: recuentos en cero, ratios y gasto sin cifra. */
    public function test_sin_datos_del_periodo_anterior_no_se_inventa_nada(): void
    {
        $this->syncSpend();
        $this->paidLead('2026-10-02 15:00:00', '120230001');

        $p = $this->dashboard()['previous'];

        $this->assertSame([0, 0, 0], [$p['kpis']['leads'], $p['kpis']['converted'], $p['kpis']['meta_leads']]);
        $this->assertSame([null, null, null, null], [$p['kpis']['conversion_rate'], $p['kpis']['meta_conversion_rate'], $p['kpis']['roas'], $p['kpis']['cac']]);
        // Ninguna pasada cubrió 26–30 de septiembre: el gasto no se sabe, no es cero.
        $this->assertSame(['unknown', null], [$p['spend']['status'], $p['spend']['amount']]);
        $this->assertNull($p['kpis']['return_after_ad_spend']);
    }

    // ── Ceros en el denominador ─────────────────────────────────────────────

    public function test_cero_en_el_denominador_no_da_cifra(): void
    {
        $vacio = $this->dashboard()['kpis'];
        $this->assertSame([0, null, null, null], [$vacio['leads'], $vacio['conversion_rate'], $vacio['meta_conversion_rate'], $vacio['attributable_share']]);

        // Leads, pero ninguno de pauta: la conversión Meta no tiene universo.
        $this->lead('2026-10-02 15:00:00');
        $k = $this->dashboard()['kpis'];
        $this->assertSame([1, 0.0, null, 0.0], [$k['leads'], $k['conversion_rate'], $k['meta_conversion_rate'], $k['attributable_share']]);
    }

    // ── FASE 16: una persona con dos perfiles sale una vez ─────────────────

    /** Dos perfiles de WhatsApp con teléfonos distintos que llevan al MISMO cliente: una persona. */
    public function test_dos_perfiles_de_whatsapp_del_mismo_cliente_son_una_persona(): void
    {
        // El usuario tiene un teléfono y su ficha de socio otro.
        [$cliente] = $this->customer('3001112233', true, '3209998877');
        $a = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);
        $b = $this->lead('2026-10-03 15:00:00', ['phone' => '573209998877']);
        $this->payment($cliente, 120000, '2026-10-03 16:00:00');

        $d = $this->dashboard();
        $this->assertSame([1, 1, 120000.0], [$d['kpis']['leads'], $d['kpis']['converted'], $d['kpis']['revenue_attributed']]);
        $this->assertSame(1, array_sum(array_column($d['origins'], 'leads')));

        $lista = $this->service()->leads(...$this->bounds('2026-10-01', '2026-10-05'));
        $this->assertSame(1, $lista['meta']['total']);
        $this->assertSame([$a->id], array_column($lista['data'], 'id'));
        $this->assertSame('new', $lista['data'][0]['conversion']['kind']);
        $this->assertNotSame($a->id, $b->id);
    }

    /**
     * Quien ya escribió ANTES del periodo desde otro teléfono que lleva al mismo
     * cliente no es nuevo en el periodo: su primer toque es el de entonces (aquí,
     * orgánico), así que su cobro no se le da a Meta, y dos periodos seguidos
     * suman lo mismo que su unión.
     */
    public function test_quien_escribio_antes_desde_otro_telefono_no_es_nuevo_en_el_periodo(): void
    {
        $this->syncSpend();
        // El usuario tiene un teléfono y su ficha de socio otro.
        [$cliente] = $this->customer('3001112233', true, '3209998877');
        $antes = $this->lead('2026-09-28 15:00:00', ['phone' => '573001112233']);
        $this->touch($antes, $this->postReferral(), '2026-09-28 15:00:00');
        $this->paidLead('2026-10-02 15:00:00', '120230001', attrs: ['phone' => '573209998877']);
        $this->payment($cliente, 100000, '2026-10-03 15:00:00');

        $actual = $this->dashboard('2026-10-01', '2026-10-05');
        $anterior = $this->dashboard('2026-09-26', '2026-09-30');
        $union = $this->dashboard('2026-09-26', '2026-10-05');

        $cifras = fn (array $d): array => [$d['kpis']['leads'], $d['kpis']['converted'], $d['kpis']['revenue_attributed'], $d['kpis']['paid_resolved']['revenue']];
        $this->assertSame([0, 0, 0.0, 0.0], $cifras($actual), 'no es nuevo en el periodo, y su cobro no es de Meta');
        $this->assertSame([1, 1, 100000.0, 0.0], $cifras($anterior));
        $this->assertSame([1, 1, 100000.0, 0.0], $cifras($union));
        $this->assertSame(['instagram_organic' => 1], collect($union['origins'])->pluck('leads', 'key')->all(), 'el primer toque es el de antes');
        $this->assertSame(0, $this->service()->leads(...$this->bounds('2026-10-01', '2026-10-05'))['meta']['total']);
    }

    /** Lo mismo si el lead de antes se enlazó a la ficha del cliente (un reclamo de pago) desde un teléfono que no es suyo. */
    public function test_quien_escribio_antes_enlazado_a_su_ficha_no_es_nuevo_en_el_periodo(): void
    {
        $this->syncSpend();
        [$cliente, $ficha] = $this->customer('3001112233');
        $this->lead('2026-09-28 15:00:00', ['phone' => '573157770000', 'member_id' => $ficha->id]);
        $this->paidLead('2026-10-02 15:00:00', '120230001', attrs: ['phone' => '573001112233']);
        $this->payment($cliente, 100000, '2026-10-03 15:00:00');

        $k = $this->dashboard('2026-10-01', '2026-10-05')['kpis'];
        $this->assertSame([0, 0, 0.0], [$k['leads'], $k['converted'], $k['paid_resolved']['revenue']]);
        $this->assertSame([1, 1, 100000.0], [
            $this->dashboard('2026-09-26', '2026-10-05')['kpis']['leads'],
            $this->dashboard('2026-09-26', '2026-09-30')['kpis']['converted'],
            $this->dashboard('2026-09-26', '2026-09-30')['kpis']['revenue_attributed'],
        ]);
    }

    /** «Leads calientes» cuenta a la persona aunque su lead caliente sea el del perfil fusionado por identidad. */
    public function test_leads_calientes_cuenta_a_la_persona_con_dos_perfiles(): void
    {
        $this->customer('3001112233', true, '3209998877');
        $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);
        $this->lead('2026-10-03 15:00:00', ['phone' => '573209998877', 'temperature' => MarketingLead::STATUS_HOT]);

        $d = $this->dashboard();
        $this->assertSame(1, $d['kpis']['leads']);
        $this->assertSame(1, $d['secondary']['hot_leads']['value']);
    }

    /**
     * Con Meta conectado pero sin ninguna pasada buena, la dimensión está vacía:
     * la fila de la pauta no resuelta no dice que el anuncio sea de otra cuenta,
     * porque no se sabe. Tras la primera pasada buena, sí.
     */
    public function test_sin_ninguna_pasada_buena_la_fila_sin_campana_lo_dice(): void
    {
        $this->configureMetaAds();
        $this->paidLead('2026-10-02 15:00:00', '999000111');

        $this->assertSame([MetaDashboardService::UNRESOLVED_NAME_NEVER_SYNCED], array_column($this->dashboard()['campaigns']['rows'], 'name'));

        $this->syncSpend();
        $this->assertContains(MetaDashboardService::UNRESOLVED_NAME_OTHER_ACCOUNT, array_column($this->dashboard()['campaigns']['rows'], 'name'));
    }

    // ── FASE 13: «Otros» y el filtro de la lista ───────────────────────────

    public function test_otros_agrupa_lo_que_no_es_de_la_lista_y_filtra_la_lista(): void
    {
        $fb = $this->paidLead('2026-10-02 15:00:00', '120230001');
        // Anuncio con una URL que no es de Facebook ni de Instagram: plataforma sin identificar.
        $meta = $this->paidLead('2026-10-03 15:00:00', '120230005', 'https://ejemplo.com/anuncio');
        $sin = $this->lead('2026-10-04 15:00:00');
        $this->touch($sin, null, '2026-10-04 15:00:00');

        $d = $this->dashboard();
        $this->assertSame(['facebook_ads', 'others', 'unattributed'], array_column($d['origins'], 'key'));
        $otros = collect($d['origins'])->firstWhere('key', 'others');
        $this->assertSame(['Otros', 1, ['Meta Ads (plataforma sin identificar)']], [$otros['label'], $otros['leads'], $otros['includes']]);

        $porOrigen = fn (string $origen) => array_column($this->service()->leads(...[...$this->bounds('2026-10-01', '2026-10-05'), 1, 20, true, $origen])['data'], 'id');
        $this->assertSame([$fb->id], $porOrigen('facebook_ads'));
        $this->assertSame([$meta->id], $porOrigen('others'));
        $this->assertSame([$sin->id], $porOrigen('unattributed'));

        $h = $this->adminWithPermissions(['marketing.view']);
        $q = '?period=custom&from=2026-10-01&to=2026-10-05';
        $this->getJson(self::BASE.'/leads'.$q.'&origin=others', $h)->assertOk()
            ->assertJsonPath('meta.origin', 'others')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $meta->id);
        $this->getJson(self::BASE.'/leads'.$q.'&origin=meta_ads', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_origin');
        $this->getJson(self::BASE.'/leads'.$q.'&origin[]=others', $h)->assertStatus(422)->assertJsonPath('code', 'invalid_origin');
    }

    // ── FASE 14 y 15: tabla y desglose perezoso ────────────────────────────

    public function test_el_desglose_baja_de_campana_a_conjunto_y_a_anuncio(): void
    {
        $this->syncSpend([
            $this->insightRow('2026-10-02', '120230001', '60000.00'),
            $this->insightRow('2026-10-02', '120230003', '10000.00', ['ad_name' => 'Video 03']),
            $this->insightRow('2026-10-03', '120230002', '40000.00', [
                'campaign_id' => '120202', 'campaign_name' => 'Campaña Reels', 'adset_id' => '120212', 'adset_name' => 'Reels Neiva',
            ]),
        ]);
        $this->paidLead('2026-10-02 15:00:00', '120230001');
        $this->paidLead('2026-10-02 16:00:00', '120230003');
        $this->paidLead('2026-10-03 15:00:00', '120230002');

        $campanas = collect($this->dashboard()['campaigns']['rows'])->keyBy('name');
        $this->assertSame([70000.0, 2, true, 'campaign'], [$campanas['Campaña Octubre']['spend'], $campanas['Campaña Octubre']['leads'], $campanas['Campaña Octubre']['has_children'], $campanas['Campaña Octubre']['level']]);

        [$desde, $hasta] = $this->bounds('2026-10-01', '2026-10-05');
        $octubre = MetaDashboardService::opaqueId('campaign', '120201');
        $conjuntos = $this->service()->campaignTable('adset', $desde, $hasta, true, $octubre);
        $this->assertSame(['Neiva 18-35'], array_column($conjuntos['rows'], 'name'), 'solo los conjuntos de esa campaña');
        $this->assertSame([70000.0, 2, true], [$conjuntos['rows'][0]['spend'], $conjuntos['rows'][0]['leads'], $conjuntos['rows'][0]['has_children']]);

        // Los anuncios de ese conjunto, paginados: de mayor a menor gasto.
        $neiva = MetaDashboardService::opaqueId('adset', '120211');
        $p1 = $this->service()->campaignTable('ad', $desde, $hasta, true, $neiva, 1, 1);
        $p2 = $this->service()->campaignTable('ad', $desde, $hasta, true, $neiva, 2, 1);
        $this->assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 1, 'total' => 2], $p1['meta']);
        $this->assertSame([60000.0, 10000.0], [$p1['rows'][0]['spend'], $p2['rows'][0]['spend']]);
        $this->assertFalse($p1['rows'][0]['has_children']);

        // Un padre que no es de la cuenta no tiene hijos; tampoco uno del nivel equivocado.
        $this->assertSame([], $this->service()->campaignTable('adset', $desde, $hasta, true, MetaDashboardService::opaqueId('campaign', '999'))['rows']);
        $this->assertSame([], $this->service()->campaignTable('ad', $desde, $hasta, true, $octubre)['rows']);

        $h = $this->adminWithPermissions(['marketing.view']);
        $q = '&period=custom&from=2026-10-01&to=2026-10-05';
        // Sin padre, la tabla entera: la «página» es el total (nunca un PHP_INT_MAX).
        $this->getJson(self::BASE.'/campaigns?level=campaign'.$q, $h)->assertOk()
            ->assertJsonPath('data.meta', ['current_page' => 1, 'last_page' => 1, 'per_page' => 2, 'total' => 2]);
        $this->getJson(self::BASE.'/campaigns?level=adset&parent='.$octubre.$q, $h)->assertOk()
            ->assertJsonPath('data.rows.0.name', 'Neiva 18-35')
            ->assertJsonPath('data.meta.total', 1);
        $this->getJson(self::BASE.'/campaigns?level=ad&parent='.$neiva.$q.'&per_page=500', $h)->assertOk()
            ->assertJsonPath('data.meta.per_page', 50)
            ->assertJsonPath('data.meta.total', 2);
        foreach ([
            '?level=adset&parent=campaign:xyz',          // no es una clave
            '?level=ad&parent='.$octubre,                 // los anuncios se piden con un conjunto
            '?level=campaign&parent='.$octubre,           // una campaña no tiene padre
            '?level=adset&parent='.$octubre.'&page=0',    // página inválida
        ] as $q2) {
            $res = $this->getJson(self::BASE.'/campaigns'.$q2.$q, $h)->assertStatus(422);
            $this->assertContains($res->json('code'), ['invalid_parent', 'invalid_page'], $q2);
        }
        // Un salto de línea al final lo quita TrimStrings antes del controlador: es la misma
        // campaña, no un rodeo (y la validación usa \z, que tampoco lo aceptaría).
        $this->getJson(self::BASE.'/campaigns?level=adset&parent='.$octubre.'%0A'.$q, $h)->assertOk()
            ->assertJsonPath('data.rows.0.name', 'Neiva 18-35');
    }

    /** Una métrica que Meta no reporta para la cuenta es null en la tabla, nunca 0. */
    public function test_una_metrica_que_meta_no_reporta_es_null_y_no_cero(): void
    {
        $this->syncSpend();
        $this->paidLead('2026-10-02 15:00:00', '120230001');

        $d = $this->dashboard();
        $fila = collect($d['campaigns']['rows'])->firstWhere('name', 'Campaña Octubre');
        $this->assertSame(['messaging_started' => false, 'messaging_replied' => false, 'landing_page_views' => false, 'reach' => false], $d['metrics_available']);
        $this->assertSame([null, null, null, null], [$fila['meta_conversations'], $fila['meta_replies'], $fila['landing_page_views'], $fila['cost_per_conversation']]);
        // Lo que sí se reporta, con cifra: clics e impresiones, y sus derivadas de las SUMAS.
        $this->assertSame([2000, 50, 50000.0, 0.025, 2000.0], [$fila['impressions'], $fila['clicks'], $fila['cpm'], $fila['ctr'], $fila['cpc']]);

        // Con conversaciones de mensajería reportadas, salen con su costo.
        $this->syncSpend([
            $this->insightRow('2026-10-02', '120230001', '60000.00', ['impressions' => '1000', 'clicks' => '50', 'actions' => [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '30']]]),
            $this->insightRow('2026-10-03', '120230001', '40000.00', ['impressions' => '3000', 'clicks' => '30', 'actions' => [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '10']]]),
        ]);
        $fila = collect($this->dashboard()['campaigns']['rows'])->firstWhere('name', 'Campaña Octubre');
        // CTR del periodo = 80 / 4000 = 2 %, no la media de los diarios (5 % y 1 %).
        $this->assertSame([40, 2500.0, 0.02, 25000.0, 1250.0], [$fila['meta_conversations'], $fila['cost_per_conversation'], $fila['ctr'], $fila['cpm'], $fila['cpc']]);
    }

    /** El alcance de la tabla solo para el rango exacto que Meta calculó. */
    public function test_el_alcance_de_la_tabla_solo_para_el_rango_que_meta_calculo(): void
    {
        $this->syncSpend([], '2026-09-06', '2026-10-05');
        $this->paidLead('2026-10-02 15:00:00', '120230001');
        DB::table('meta_ad_reach_snapshots')->insert([
            ['ad_account_id' => self::ACCOUNT, 'level' => MetaAdReachSnapshot::LEVEL_CAMPAIGN, 'entity_id' => '120201', 'date_from' => '2026-09-06', 'date_to' => '2026-10-05', 'reach' => 4321, 'impressions' => 2000, 'frequency' => 1.2, 'synced_at' => now()],
        ]);

        $treinta = collect($this->service()->dashboard($this->service()->period('30d'))['campaigns']['rows'])->firstWhere('name', 'Campaña Octubre');
        $this->assertSame(4321, $treinta['reach']);

        $otro = collect($this->dashboard('2026-10-01', '2026-10-05')['campaigns']['rows'])->firstWhere('name', 'Campaña Octubre');
        $this->assertNull($otro['reach'], 'del 1 al 5 de octubre Meta no dio alcance: no se suma por días');

        // Con el gasto del periodo incompleto (falló la pasada de hoy), el alcance
        // tampoco sale: la fila sería medio de un periodo y medio de otro.
        $this->fakeMeta(fn () => $this->graphError(80000, 'There have been too many calls'));
        DB::table('meta_sync_runs')->delete();
        $this->syncSpendSinFallo('2026-09-06', '2026-10-04');
        $this->assertSame('failed', $this->sync()->run('manual', $this->day('2026-10-05'), $this->day('2026-10-05'))['status']);
        $parcial = $this->service()->dashboard($this->service()->period('30d'));
        $this->assertSame(true, $parcial['campaigns']['partial']);
        $this->assertNull(collect($parcial['campaigns']['rows'])->firstWhere('name', 'Campaña Octubre')['reach']);
    }

    /** Una pasada buena de `$from` a `$to` con el gasto de siempre, aunque el falso de Meta esté fallando. */
    private function syncSpendSinFallo(string $from, string $to): void
    {
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-02', '120230001', '60000.00'),
            $this->insightRow('2026-10-03', '120230001', '40000.00'),
        ]]);
        $this->assertSame('ok', $this->sync()->run('manual', $this->day($from), $this->day($to))['status']);
    }

    // ── FASE 18: lo último bueno, dicho como parcial ───────────────────────

    public function test_tras_un_fallo_se_enseña_lo_ultimo_bueno_como_parcial_y_no_se_divide(): void
    {
        $this->syncSpend([], '2026-10-01', '2026-10-04');
        $this->paidLead('2026-10-02 15:00:00', '120230001');
        $this->fakeMeta(fn () => $this->graphError(80000, 'There have been too many calls'));
        $this->assertSame('failed', $this->sync()->run('manual', $this->day('2026-10-05'), $this->day('2026-10-05'))['status']);

        $d = $this->dashboard();

        $this->assertSame(
            ['status' => 'sync_failed', 'amount' => 100000.0, 'covered_to' => '2026-10-04', 'complete' => false],
            array_intersect_key($d['spend'], array_flip(['status', 'amount', 'covered_to', 'complete'])),
        );
        foreach (['roas', 'cac', 'return_after_ad_spend'] as $k) {
            $this->assertNull($d['kpis'][$k], $k);
        }
        $this->assertSame(['spend_incomplete', 'spend_incomplete', 'spend_incomplete'], [$d['kpis']['roas_reason'], $d['kpis']['cac_reason'], $d['kpis']['return_after_ad_spend_reason']]);
        $this->assertSame([true, '2026-10-04'], [$d['campaigns']['partial'], $d['campaigns']['covered_to']]);
        $fila = collect($d['campaigns']['rows'])->firstWhere('name', 'Campaña Octubre');
        $this->assertSame([100000.0, true, null, null], [$fila['spend'], $fila['partial'], $fila['roas'], $fila['return_after_ad_spend']]);

        // Un periodo que sí está cubierto entero se calcula con normalidad.
        $cubierto = $this->dashboard('2026-10-01', '2026-10-04');
        $this->assertSame([true, 100000.0], [$cubierto['spend']['complete'], $cubierto['spend']['amount']]);
        $this->assertNull($cubierto['kpis']['return_after_ad_spend_reason']);
    }

    // ── FASE 11: las definiciones del cliente ──────────────────────────────

    /**
     * Meta cae después de la pasada de las 00:15: hoy solo se vio hasta esa hora.
     * «Hoy» no puede salir como un cero comprobado, ni el retorno igual a los
     * ingresos; 7 días sale con lo visto entero y su fecha, sin ROAS, CAC ni retorno.
     */
    public function test_con_meta_caido_el_dia_de_la_ultima_pasada_no_cuenta_como_visto(): void
    {
        $this->configureMetaAds();
        $this->fakeMeta(['data' => [
            $this->insightRow('2026-10-02', '120230001', '60000.00'),
            $this->insightRow('2026-10-03', '120230001', '40000.00'),
        ]]);
        Carbon::setTestNow('2026-10-05 05:15:00');
        $this->assertSame('ok', $this->sync()->run('schedule')['status']);
        $this->fakeMeta(fn () => $this->graphError(190, 'Error validating access token'));
        Carbon::setTestNow('2026-10-05 06:15:00');
        $this->assertSame('failed', $this->sync()->run('schedule')['status']);
        Carbon::setTestNow('2026-10-05 15:00:00');

        [$cliente] = $this->customer('3001110001');
        $this->paidLead('2026-10-05 13:00:00', '120230001', attrs: ['phone' => '573001110001']);
        $this->payment($cliente, 150000, '2026-10-05 14:00:00');

        $hoy = $this->dashboard('2026-10-05', '2026-10-05');
        $this->assertSame(['sync_failed', null], [$hoy['spend']['status'], $hoy['spend']['amount']]);
        $this->assertSame([null, null], [$hoy['kpis']['return_after_ad_spend'], $hoy['kpis']['return_state']]);
        $this->assertSame(150000.0, $hoy['kpis']['paid_resolved']['revenue']);

        $semana = $this->dashboard('2026-09-29', '2026-10-05');
        $this->assertSame([100000.0, false, '2026-10-04'], [$semana['spend']['amount'], $semana['spend']['complete'], $semana['spend']['covered_to']]);
        $this->assertSame([null, null, null], [$semana['kpis']['roas'], $semana['kpis']['cac'], $semana['kpis']['return_after_ad_spend']]);
        $this->assertSame('spend_incomplete', $semana['kpis']['return_after_ad_spend_reason']);
        $this->assertSame([true, '2026-10-04'], [$semana['campaigns']['partial'], $semana['campaigns']['covered_to']]);
    }

    public function test_las_definiciones_son_las_del_cliente(): void
    {
        $d = MetaDashboardService::definitions();

        foreach ([
            'spend' => 'Suma del importe gastado que Meta reporta para el periodo.',
            'leads' => 'Personas únicas que iniciaron contacto comercial en el periodo.',
            'conversations' => 'Hilos con al menos un mensaje entrante en el periodo.',
            'converted' => 'Leads que realizaron su primer cobro válido de membresía dentro de la ventana de atribución',
            'renewals' => 'Miembros existentes que realizaron un cobro válido después del contacto comercial',
            'revenue_attributed' => 'Dinero realmente cobrado y vinculado a leads dentro de la ventana',
            'roas' => 'Ingreso atribuible a Meta / gasto Meta.',
            'cac' => 'Gasto Meta / nuevos clientes obtenidos desde pauta.',
            'conversion_rate' => 'Nuevos clientes / leads del mismo universo',
            'return_after_ad_spend' => 'Ingresos atribuidos a Meta menos el gasto publicitario. No incluye nómina, arriendo ni otros costos operativos.',
            'unattributed' => 'Sin atribuir significa que no existe evidencia técnica suficiente para asignar el lead a una campaña. No se asignan campañas por suposición.',
        ] as $clave => $texto) {
            $this->assertStringStartsWith($texto, $d[$clave], $clave);
        }
        foreach ($d as $clave => $texto) {
            $this->assertDoesNotMatchRegularExpression('/ganancia|utilidad/i', $texto, $clave);
        }
    }

    // ── FASE 19: sin N+1 ────────────────────────────────────────────────────

    /** El número de consultas del panel no crece con los leads (incluido el periodo anterior). */
    public function test_el_panel_no_hace_una_consulta_por_lead(): void
    {
        $this->syncSpend();
        $sembrar = function (int $desde, int $n): void {
            for ($i = $desde; $i < $desde + $n; $i++) {
                $tel = '30011'.str_pad((string) $i, 5, '0', STR_PAD_LEFT);
                [$u] = $this->customer($tel);
                $this->paidLead('2026-10-0'.(2 + $i % 3).' 15:00:00', '120230001', attrs: ['phone' => '57'.$tel]);
                $this->lead('2026-09-2'.(6 + $i % 4).' 15:00:00');
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
}
