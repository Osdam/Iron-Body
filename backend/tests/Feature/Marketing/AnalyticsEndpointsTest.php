<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingLeadAttribution;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Los endpoints de analítica: quién puede verlos, qué devuelven y qué no.
 *
 * Lo que se prueba con más insistencia no es que las cifras salgan, sino las
 * dos cosas que convierten un panel en un problema: que enseñe datos a quien no
 * debe, y que deje escapar información de personas concretas por una vía donde
 * los permisos son otros.
 */
class AnalyticsEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private array $metricsHeaders = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'UTC'));

        $admin = Admin::create([
            'name' => 'Analitica QA', 'email' => 'analytics@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->metricsHeaders = $this->actingAsAdmin($admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function adminHeaders(array $headers = []): array
    {
        return array_merge($this->metricsHeaders, $headers);
    }

    /**
     * Peticion a un endpoint de analitica.
     *
     * Se llama `fetch` y no `get` porque `get()` ya existe en el TestCase de
     * Laravel y es publico: redeclararlo como privado es un error fatal de PHP.
     * Es la tercera colision de este tipo en el proyecto -antes `run()` y
     * `seed()`-, asi que conviene mirar el padre antes de nombrar un ayudante.
     */
    private function fetch(string $path, array $query = [])
    {
        return $this->getJson(
            '/api/admin/marketing/analytics/'.$path.($query ? '?'.http_build_query($query) : ''),
            $this->adminHeaders(),
        );
    }

    private function seedSale(string $campaign = 'Agosto', string $ad = 'AD-1', float $amount = 90000): MarketingLead
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 'qualified',
        ]);

        $conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);

        MarketingLeadAttribution::create([
            'marketing_lead_id' => $lead->id,
            'marketing_conversation_id' => $conversation->id,
            'source_type' => 'ad', 'source_platform' => 'instagram',
            'campaign_name' => $campaign, 'ad_id' => $ad,
            'first_touch_at' => now()->subDays(10), 'first_touch_source_type' => 'ad',
            'first_touch_ad_id' => $ad,
            'last_touch_at' => now()->subDays(2), 'last_touch_source_type' => 'ad',
            'last_touch_ad_id' => $ad,
            'received_at' => now()->subDays(2), 'attribution_confidence' => 'high',
        ]);

        $user = User::create([
            'name' => 'C'.$lead->id, 'email' => 'c'.$lead->id.'@ib.test', 'password' => 'x',
        ]);
        $member = Member::create([
            'user_id' => $user->id, 'full_name' => 'C'.$lead->id,
            'document_number' => (string) (20000000 + $lead->id), 'phone' => $lead->phone,
        ]);
        $lead->forceFill(['member_id' => $member->id])->save();

        PaymentTransaction::create([
            'reference' => 'R'.uniqid(), 'idempotency_key' => 'I'.uniqid('', true),
            'member_id' => $member->id, 'user_id' => $user->id,
            'amount' => $amount, 'currency' => 'COP', 'status' => 'approved',
            'provider' => 'wompi',
        ])->forceFill(['created_at' => now()->subDays(3), 'paid_at' => now()->subDays(3)])->save();

        return $lead->fresh();
    }

    // ── Autorización ────────────────────────────────────────────────────────

    public function test_sin_sesion_no_se_ve_la_analitica(): void
    {
        $this->getJson('/api/admin/marketing/analytics/summary')->assertUnauthorized();
    }

    /**
     * Ver dinero no es lo mismo que atender.
     *
     * Un rol que puede contestar conversaciones pero no ver métricas no puede
     * entrar por aquí a leer la facturación por campaña.
     */
    public function test_un_rol_sin_permiso_de_metricas_queda_fuera(): void
    {
        $recepcion = Admin::create([
            'name' => 'Recepción', 'email' => 'recepcion@ironbody.test',
            'password' => 'x', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ]);

        $response = $this->getJson(
            '/api/admin/marketing/analytics/summary',
            $this->actingAsAdmin($recepcion),
        );

        $this->assertContains($response->status(), [401, 403], 'Un rol sin métricas vio la analítica.');
    }

    // ── Forma de la respuesta ───────────────────────────────────────────────

    public function test_el_resumen_trae_embudo_tasas_ingresos_y_categorias(): void
    {
        $this->seedSale();

        $this->fetch('summary')->assertOk()->assertJsonStructure([
            'data' => [
                'period', 'funnel', 'rates',
                'revenue' => ['total', 'attributed', 'unattributed', 'unattributed_share'],
                'spend' => ['available'],
                'revenue_categories' => ['acquisition_revenue', 'renewal_revenue', 'upgrade_revenue', 'cross_sell_revenue', 'reactivation_revenue'],
            ],
        ]);
    }

    public function test_el_funnel_es_su_propio_endpoint_y_es_pequeno(): void
    {
        $this->seedSale();

        $data = $this->fetch('funnel')->assertOk()->json('data');

        $this->assertArrayHasKey('funnel', $data);
        $this->assertArrayHasKey('rates', $data);
        // Nada más: el funnel no arrastra la tabla entera de campañas.
        $this->assertSame(['funnel', 'rates'], array_keys($data));
    }

    public function test_el_desglose_por_campana_devuelve_filas_y_metadatos(): void
    {
        $this->seedSale('Agosto');

        $response = $this->fetch('breakdown/campaign')->assertOk();

        $this->assertSame('Agosto', $response->json('data.0.bucket'));
        $this->assertSame('first_touch', $response->json('meta.attribution_model'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_una_dimension_inventada_se_rechaza(): void
    {
        $this->fetch('breakdown/loquesea')->assertStatus(422);
    }

    // ── Filtros, orden y paginación ─────────────────────────────────────────

    public function test_se_puede_cambiar_a_ultimo_contacto(): void
    {
        $this->seedSale();

        $response = $this->fetch('breakdown/ad', ['attribution_model' => 'last_touch'])->assertOk();

        $this->assertSame('last_touch', $response->json('meta.attribution_model'));
    }

    public function test_se_puede_ordenar_por_ingreso(): void
    {
        $this->seedSale('Rica', 'AD-RICA', 240000);
        $this->seedSale('Pobre', 'AD-POBRE', 90000);

        $rows = $this->fetch('breakdown/campaign', ['sort' => 'revenue', 'direction' => 'desc'])
            ->assertOk()->json('data');

        $this->assertSame('Rica', $rows[0]['bucket']);
    }

    public function test_la_paginacion_acota_la_respuesta(): void
    {
        foreach (['A', 'B', 'C'] as $c) {
            $this->seedSale($c, 'AD-'.$c);
        }

        $response = $this->fetch('breakdown/campaign', ['per_page' => 2, 'page' => 1])->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function test_el_filtro_de_plataforma_llega_al_calculo(): void
    {
        $this->seedSale('Agosto');

        $vacio = $this->fetch('breakdown/campaign', ['platform' => 'tiktok'])->assertOk();
        $lleno = $this->fetch('breakdown/campaign', ['platform' => 'instagram'])->assertOk();

        $this->assertSame([], $vacio->json('data'));
        $this->assertCount(1, $lleno->json('data'));
    }

    /**
     * Un rango sin techo lo puede lanzar cualquiera con sesión y acabar
     * agregando años de pagos en cada recarga.
     */
    public function test_un_rango_desmesurado_se_acota(): void
    {
        $response = $this->fetch('summary', [
            'from' => '2010-01-01', 'to' => '2026-08-15',
        ])->assertOk();

        $from = Carbon::parse($response->json('data.period.from'));

        $this->assertTrue($from->greaterThan(Carbon::parse('2024-01-01')), 'No se acoto el rango.');
    }

    public function test_un_rango_al_reves_se_endereza(): void
    {
        $response = $this->fetch('summary', ['from' => '2026-08-15', 'to' => '2026-08-01'])->assertOk();

        $this->assertTrue(
            Carbon::parse($response->json('data.period.from'))
                ->lessThan(Carbon::parse($response->json('data.period.to'))),
        );
    }

    // ── Calidad e insights ──────────────────────────────────────────────────

    public function test_la_calidad_de_atribucion_se_puede_consultar(): void
    {
        $this->seedSale();

        $data = $this->fetch('quality')->assertOk()->json('data');

        $this->assertSame(1, $data['records']);
        $this->assertSame(1, $data['known']);
        $this->assertSame(1, $data['confidence']['high']);
    }

    public function test_los_insights_declaran_que_no_ejecutan_nada(): void
    {
        $this->seedSale();

        $response = $this->fetch('insights')->assertOk();

        $this->assertTrue($response->json('meta.read_only'));
        $this->assertSame('rules', $response->json('meta.computed_by'));
    }

    /** Un insight sin evidencia no sirve para decidir nada. */
    public function test_cada_insight_trae_su_evidencia_y_su_confianza(): void
    {
        // Campaña con volumen y sin ventas: dispara el insight de conversión.
        for ($i = 0; $i < 12; $i++) {
            $lead = MarketingLead::create([
                'channel' => 'whatsapp', 'source' => 'inbound',
                'phone' => '31000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => 'new',
            ]);
            MarketingLeadAttribution::create([
                'marketing_lead_id' => $lead->id, 'source_type' => 'ad',
                'campaign_name' => 'Ruido', 'ad_id' => 'AD-R',
                'first_touch_at' => now()->subDays(5), 'first_touch_source_type' => 'ad',
                'last_touch_at' => now()->subDays(5), 'last_touch_source_type' => 'ad',
                'received_at' => now()->subDays(5), 'attribution_confidence' => 'high',
            ]);
        }
        $this->seedSale('Buena', 'AD-B');

        $insights = $this->fetch('insights')->assertOk()->json('data');

        $this->assertNotEmpty($insights);

        foreach ($insights as $insight) {
            $this->assertArrayHasKey('type', $insight);
            $this->assertArrayHasKey('severity', $insight);
            $this->assertArrayHasKey('metric', $insight);
            $this->assertArrayHasKey('period', $insight);
            $this->assertArrayHasKey('evidence', $insight);
            $this->assertArrayHasKey('confidence', $insight);
            $this->assertArrayHasKey('recommended_review', $insight);
            $this->assertNull($insight['automated_action'], 'Un insight declaro una accion automatica.');
        }
    }

    // ── Detalle de campaña ──────────────────────────────────────────────────

    public function test_el_detalle_de_campana_trae_sus_partes(): void
    {
        $this->seedSale('Agosto', 'AD-1');

        $data = $this->fetch('campaigns/Agosto')->assertOk()->json('data');

        $this->assertSame('Agosto', $data['campaign']);
        $this->assertNotEmpty($data['ads']);
        $this->assertArrayHasKey('time_to_sale', $data);
        $this->assertArrayHasKey('revenue_categories', $data);
        $this->assertArrayHasKey('objections', $data);
    }

    // ── Lo que NO debe salir ────────────────────────────────────────────────

    /**
     * La analítica es de cifras agregadas. Un teléfono, un nombre o el payload
     * del canal saliendo por aquí sería una fuga por una puerta distinta de la
     * del Inbox, donde los permisos y el registro de accesos son otros.
     */
    public function test_la_analitica_no_deja_escapar_datos_personales(): void
    {
        $lead = $this->seedSale('Agosto');

        foreach (['summary', 'funnel', 'quality', 'insights', 'breakdown/campaign', 'campaigns/Agosto'] as $path) {
            $body = $this->fetch($path)->assertOk()->getContent();

            $this->assertStringNotContainsString($lead->phone, $body, "{$path} expuso un telefono.");
            $this->assertStringNotContainsString('raw_referral_payload', $body);
            $this->assertStringNotContainsString('ctwa_clid', $body);
            $this->assertStringNotContainsString('@ib.test', $body, "{$path} expuso un correo.");
        }
    }

    /** Sin gasto conectado, ni gasto ni ROAS: nunca cero. */
    public function test_sin_gasto_los_endpoints_dicen_no_disponible(): void
    {
        $this->seedSale();

        $summary = $this->fetch('summary')->assertOk();
        $row = $this->fetch('breakdown/campaign')->assertOk()->json('data.0');

        $this->assertFalse($summary->json('data.spend.available'));
        $this->assertNull($row['spend']);
        $this->assertNull($row['roas']);
    }
}
