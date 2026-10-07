<?php

namespace Tests\Unit\Marketing\Attribution;

use App\Models\MarketingLead;
use App\Models\MarketingLeadAttribution;
use App\Models\MetaAdEntity;
use App\Services\Marketing\Attribution\LeadSourceClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Feature\Marketing\Attribution\SeedsAttribution;
use Tests\TestCase;

/**
 * De dónde vino un lead, solo con evidencia (D8) y con la campaña que dice Meta,
 * nunca deducida (D9).
 *
 * Las atribuciones se escriben con el LeadAttributionService real: se prueba la
 * lectura de lo que de verdad guarda el webhook.
 */
class LeadSourceClassifierTest extends TestCase
{
    use RefreshDatabase, SeedsAttribution;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function classifier(): LeadSourceClassifier
    {
        return new LeadSourceClassifier;
    }

    private function attributionOf(MarketingLead $lead): ?MarketingLeadAttribution
    {
        return MarketingLeadAttribution::query()->where('marketing_lead_id', $lead->id)->first();
    }

    /** Contrato 4: un lead de pauta con su campaña, resuelta por la dimensión de Meta. */
    public function test_lead_de_pauta_con_campana_resuelta_por_la_dimension(): void
    {
        $lead = $this->lead('2026-10-02 15:00:00');
        $this->touch($lead, $this->adReferral('120230001'), '2026-10-02 15:00:00', $this->conversation($lead));
        $this->adHierarchy('120230001', '120211', '120201', 'Campaña Octubre', 'Neiva 18-35', 'Video 2x1');

        $c = $this->classifier()->classify($this->attributionOf($lead), $lead);

        $this->assertSame('paid', $c['origin']);
        $this->assertSame('facebook', $c['platform']);
        $this->assertSame('facebook_ads', $c['key']);
        $this->assertSame('whatsapp', $c['channel']);
        $this->assertSame('120230001', $c['ad_id']);
        $this->assertSame('120201', $c['campaign_id']);
        $this->assertSame('Campaña Octubre', $c['campaign_name']);
        $this->assertSame('120211', $c['adset_id']);
        $this->assertSame('Neiva 18-35', $c['adset_name']);
        $this->assertSame('Video 2x1', $c['ad_name']);
        $this->assertTrue($c['resolved']);
    }

    /**
     * Multicuenta: un anuncio de CUALQUIER cuenta conectada resuelve, con su
     * cuenta (cruda, para quien agrega); uno de una cuenta que no está conectada
     * no resuelve, aunque esté en la dimensión.
     */
    public function test_un_anuncio_de_cualquier_cuenta_conectada_resuelve(): void
    {
        config()->set('meta.ads.ad_account_ids', ['1111111111', 'act_2222222222']);
        $this->adHierarchy('120230001', '120211', '120201', 'Campaña de A', account: '1111111111');
        $this->adHierarchy('120230002', '120212', '120202', 'Campaña de B', 'Conjunto B', 'Anuncio B', account: '2222222222');
        $this->adHierarchy('120230003', '120213', '120203', 'Campaña ajena', account: '3333333333');

        $clasificar = function (string $adId): array {
            $lead = $this->lead('2026-10-02 15:00:00');
            $this->touch($lead, $this->adReferral($adId, 'https://fb.me/x', 'clic-'.$adId), '2026-10-02 15:00:00');

            return $this->classifier()->classify($this->attributionOf($lead), $lead);
        };

        $a = $clasificar('120230001');
        $this->assertSame([true, '1111111111', '120201', 'Campaña de A'], [$a['resolved'], $a['account_id'], $a['campaign_id'], $a['campaign_name']]);

        $b = $clasificar('120230002');
        $this->assertSame(
            [true, '2222222222', '120202', 'Campaña de B', '120212', 'Conjunto B', 'Anuncio B'],
            [$b['resolved'], $b['account_id'], $b['campaign_id'], $b['campaign_name'], $b['adset_id'], $b['adset_name'], $b['ad_name']],
        );

        $ajena = $clasificar('120230003');
        $this->assertSame(['paid', false, null, null, null], [$ajena['origin'], $ajena['resolved'], $ajena['account_id'], $ajena['campaign_id'], $ajena['campaign_name']]);

        // Lo que no es pauta no tiene cuenta.
        $organico = $this->lead('2026-10-02 16:00:00');
        $this->touch($organico, $this->postReferral(), '2026-10-02 16:00:00');
        $this->assertNull($this->classifier()->classify($this->attributionOf($organico), $organico)['account_id']);
    }

    /** D9: sin la dimensión no hay campaña, y no se deduce de nada que coincida en fechas. */
    public function test_sin_dimension_no_hay_campana_y_no_se_deduce_por_fechas(): void
    {
        $lead = $this->lead('2026-10-02 15:00:00');
        $this->touch($lead, $this->adReferral('120230009', 'https://www.instagram.com/reel/abc'), '2026-10-02 15:00:00');

        // Una campaña activa ese mismo día, con otro anuncio: no es la de este lead.
        $this->adHierarchy('120239999', '120219', '120209', 'Campaña activa ese día');

        $c = $this->classifier()->classify($this->attributionOf($lead), $lead);

        $this->assertSame('paid', $c['origin']);
        $this->assertSame('instagram', $c['platform']);
        $this->assertSame('instagram_ads', $c['key']);
        $this->assertSame('120230009', $c['ad_id']);
        $this->assertNull($c['campaign_id']);
        $this->assertNull($c['campaign_name']);
        $this->assertNull($c['adset_name']);
        $this->assertNull($c['ad_name']);
        $this->assertFalse($c['resolved']);
    }

    /** Contrato 5: una publicación orgánica. */
    public function test_lead_organico(): void
    {
        $lead = $this->lead('2026-10-02 15:00:00');
        $this->touch($lead, $this->postReferral('https://www.instagram.com/p/C9xYz/'), '2026-10-02 15:00:00');

        $c = $this->classifier()->classify($this->attributionOf($lead), $lead);

        $this->assertSame('organic', $c['origin']);
        $this->assertSame('instagram', $c['platform']);
        $this->assertSame('instagram_organic', $c['key']);
        // El id de la publicación no es un anuncio: no se presenta como tal.
        $this->assertNull($c['ad_id']);
        $this->assertNull($c['campaign_name']);
        $this->assertFalse($c['resolved']);
    }

    /**
     * Contrato 6: `direct` existe en el modelo, pero escribir sin referral NO
     * prueba que la persona viniera directa. Hoy nada lo asigna.
     */
    public function test_directo_existe_en_el_modelo_y_no_se_asigna_sin_evidencia(): void
    {
        // Escribió sin referral: el webhook deja «unknown», y así se lee.
        $sinReferral = $this->lead('2026-10-02 15:00:00');
        $this->touch($sinReferral, null, '2026-10-02 15:00:00');
        $c = $this->classifier()->classify($this->attributionOf($sinReferral), $sinReferral);
        $this->assertSame('unknown', $c['origin']);
        $this->assertSame('unattributed', $c['key']);

        // El modelo sí lo soporta: una fila que dijera `direct` (ningún escritor lo
        // hace hoy) se leería como directo, en su fila propia.
        $directo = $this->lead('2026-10-02 16:00:00');
        MarketingLeadAttribution::forceCreate([
            'marketing_lead_id' => $directo->id,
            'source_type' => MarketingLeadAttribution::SOURCE_DIRECT,
            'first_touch_at' => '2026-10-02 16:00:00',
            'first_touch_source_type' => MarketingLeadAttribution::SOURCE_DIRECT,
            'last_touch_at' => '2026-10-02 16:00:00',
            'last_touch_source_type' => MarketingLeadAttribution::SOURCE_DIRECT,
            'attribution_confidence' => 'low',
        ]);
        $c = $this->classifier()->classify($this->attributionOf($directo), $directo);
        $this->assertSame('direct', $c['origin']);
        $this->assertSame('whatsapp_direct', $c['key']);
        $this->assertNull($c['platform']);
        $this->assertContains('whatsapp_direct', LeadSourceClassifier::KEYS);
    }

    /** Contrato 7: sin atribución, o con evidencia incompleta, «Sin atribuir». */
    public function test_lead_sin_atribucion(): void
    {
        $sinFila = $this->lead('2026-10-02 15:00:00');
        $c = $this->classifier()->classify(null, $sinFila);
        $this->assertSame('unknown', $c['origin']);
        $this->assertSame('unattributed', $c['key']);
        $this->assertNull($c['ad_id']);

        // Un «anuncio» sin ad_id no prueba qué anuncio fue.
        $sinAnuncio = $this->lead('2026-10-02 16:00:00');
        $this->touch($sinAnuncio, ['source_type' => 'ad', 'source_url' => 'https://fb.me/x', 'ctwa_clid' => 'clid-1'], '2026-10-02 16:00:00');
        $c = $this->classifier()->classify($this->attributionOf($sinAnuncio), $sinAnuncio);
        $this->assertSame('unknown', $c['origin']);
        $this->assertSame('unattributed', $c['key']);
        $this->assertNull($c['platform']);
    }

    /** Contratos 12 y 13: el primer toque no cambia; el último sí. */
    public function test_primer_y_ultimo_toque(): void
    {
        $this->adHierarchy('120230002', '120212', '120202', 'Campaña Reels');

        // Escribió sin anuncio y después volvió desde un anuncio de Instagram.
        $lead = $this->lead('2026-10-01 15:00:00');
        $this->touch($lead, null, '2026-10-01 15:00:00');
        $this->touch($lead, $this->adReferral('120230002', 'https://www.instagram.com/reel/xyz'), '2026-10-03 15:00:00');
        $a = $this->attributionOf($lead);

        $first = $this->classifier()->classify($a, $lead, 'first');
        $last = $this->classifier()->classify($a, $lead, 'last');
        $this->assertSame('unknown', $first['origin']);
        $this->assertSame('unattributed', $first['key']);
        $this->assertSame('paid', $last['origin']);
        $this->assertSame('instagram', $last['platform']);
        $this->assertSame('Campaña Reels', $last['campaign_name']);

        // Llegó por un anuncio de Facebook y volvió por otro de Instagram.
        $this->adHierarchy('120230003', '120213', '120203', 'Campaña Facebook');
        $otro = $this->lead('2026-10-01 16:00:00');
        $this->touch($otro, $this->adReferral('120230003', 'https://fb.me/abc'), '2026-10-01 16:00:00');
        $this->touch($otro, $this->adReferral('120230002', 'https://www.instagram.com/reel/xyz', 'otro-clic'), '2026-10-04 16:00:00');
        $a = $this->attributionOf($otro);

        $first = $this->classifier()->classify($a, $otro, 'first');
        $last = $this->classifier()->classify($a, $otro, 'last');
        $this->assertSame(['paid', 'facebook', '120230003', 'Campaña Facebook'], [$first['origin'], $first['platform'], $first['ad_id'], $first['campaign_name']]);
        $this->assertSame(['paid', 'instagram', '120230002', 'Campaña Reels'], [$last['origin'], $last['platform'], $last['ad_id'], $last['campaign_name']]);

        // Con un solo contacto, el último es el primero.
        $uno = $this->lead('2026-10-01 17:00:00');
        $this->touch($uno, $this->adReferral('120230003', 'https://fb.me/abc', 'clic-3'), '2026-10-01 17:00:00');
        $a = $this->attributionOf($uno);
        $this->assertSame(
            $this->classifier()->classify($a, $uno, 'first')['key'],
            $this->classifier()->classify($a, $uno, 'last')['key'],
        );
    }

    public function test_un_contacto_desconocido_se_rechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->classifier()->classify(null, $this->lead('2026-10-01 15:00:00'), 'middle');
    }

    /** Plataforma por el dominio, no por un «contiene». */
    public function test_plataforma_por_dominio_de_la_url(): void
    {
        $casos = [
            'https://fb.me/2xYz9Ab' => 'facebook',
            'https://www.facebook.com/ironbody/posts/1' => 'facebook',
            'https://m.facebook.com/story.php' => 'facebook',
            'https://www.instagram.com/p/C9xYz/' => 'instagram',
            'instagram.com/reel/abc' => 'instagram',
            'https://facebook.com.sitio-falso.co/x' => null,
            'https://notinstagram.com/p/1' => null,
            'https://example.com/?u=facebook.com' => null,
            '' => null,
        ];

        foreach ($casos as $url => $esperada) {
            $this->assertSame($esperada, LeadSourceClassifier::platformFromUrl($url), "URL: {$url}");
        }
    }

    /** Muchos leads, consultas acotadas: la dimensión se carga en lote. */
    public function test_la_dimension_se_precarga_en_lote(): void
    {
        $leads = [];
        foreach (range(1, 12) as $i) {
            $adId = '1202400'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->adHierarchy($adId, '1202140'.$i, '1202040'.$i, 'Campaña '.$i);
            $lead = $this->lead('2026-10-02 15:00:00');
            $this->touch($lead, $this->adReferral($adId, 'https://fb.me/x', 'clic-'.$i), '2026-10-02 15:00:00');
            $leads[] = [$lead, $this->attributionOf($lead)];
        }

        $classifier = $this->classifier();
        $consultas = 0;
        DB::listen(function () use (&$consultas): void {
            $consultas++;
        });

        $classifier->preload(array_map(fn ($p) => $p[1]->first_touch_ad_id, $leads));
        foreach ($leads as [$lead, $a]) {
            $this->assertNotNull($classifier->classify($a, $lead)['campaign_name']);
        }

        // Anuncios + campañas + conjuntos: tres consultas, no una por lead.
        $this->assertLessThanOrEqual(3, $consultas);
        $this->assertSame(36, MetaAdEntity::query()->count());
    }

    public function test_el_id_de_meta_sale_enmascarado(): void
    {
        $this->assertSame('***0001', LeadSourceClassifier::maskId('120230001'));
        $this->assertNull(LeadSourceClassifier::maskId(null));
        $this->assertNull(LeadSourceClassifier::maskId(''));
    }

    /** Los nombres pueden llevar un id o un teléfono; el resto del texto se respeta. */
    public function test_los_nombres_salen_sin_ids_ni_telefonos(): void
    {
        $this->assertSame('Video promo ***0001', LeadSourceClassifier::maskDigits('Video promo 120230001'));
        $this->assertSame('CTWA_OCT_2026-10-01_Neiva_Hombres_18-35', LeadSourceClassifier::maskDigits('CTWA_OCT_2026-10-01_Neiva_Hombres_18-35'));
        $this->assertSame('Plan 2x1 de 89900', LeadSourceClassifier::maskDigits('Plan 2x1 de 89900'));
        $this->assertNull(LeadSourceClassifier::maskDigits(null));

        // Nombres de personas: también el teléfono con separadores.
        $this->assertSame('***9900', LeadSourceClassifier::maskPersonName('+57 316 888 9900'));
        $this->assertSame('Ana ***4567', LeadSourceClassifier::maskPersonName('Ana 300-123-4567'));
        $this->assertSame('María José', LeadSourceClassifier::maskPersonName('María José'));
        $this->assertSame('Juan 2026', LeadSourceClassifier::maskPersonName('Juan 2026'));
    }

    /**
     * Los teléfonos se tapan con cualquier separador corto que no sea una letra,
     * en nombres de personas y de campañas, conjuntos y anuncios. En el nombre
     * de una persona, también el correo. Las fechas de las campañas se respetan.
     */
    public function test_telefonos_con_cualquier_separador_y_correos(): void
    {
        foreach ([
            'Ana (316) 888-9900' => 'Ana (***9900',
            '316/888/9900' => '***9900',
            '316_888_9900' => '***9900',
            'Luis 300 - 123 - 4567' => 'Luis ***4567',
            'Pedro +57 (316) 555 1234' => 'Pedro ***1234',
            'Marta 300.123.4567' => 'Marta ***4567',
        ] as $nombre => $esperado) {
            $this->assertSame($esperado, LeadSourceClassifier::maskPersonName($nombre), $nombre);
            $this->assertSame($esperado, LeadSourceClassifier::maskDigits($nombre), "campaña: {$nombre}");
        }

        $this->assertSame('***@***', LeadSourceClassifier::maskPersonName('ana.perez@gmail.com'));
        $this->assertSame('Ana ***@*** ***9900', LeadSourceClassifier::maskPersonName('Ana ana.perez@gmail.com 3168889900'));

        // Campañas: el teléfono se tapa, el id también; la fecha y la franja de edad, no.
        $this->assertSame('Público tel. (***8899', LeadSourceClassifier::maskDigits('Público tel. (315) 777-8899'));
        $this->assertSame('Video ***4567 lead ***8899', LeadSourceClassifier::maskDigits('Video 1202-3000-1234-567 lead 5731 5777 8899'));
        $this->assertSame('CTWA 2026/10/01 Neiva 18-35', LeadSourceClassifier::maskDigits('CTWA 2026/10/01 Neiva 18-35'));
        $this->assertSame('Promo 01-10-2026', LeadSourceClassifier::maskDigits('Promo 01-10-2026'));
    }
}
