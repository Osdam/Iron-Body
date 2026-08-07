<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingLeadAttribution;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\Marketing\Analytics\CampaignAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Qué pauta generó dinero de verdad.
 *
 * La afirmación que sostiene todo el archivo: **una venta solo cuenta para una
 * campaña si se puede seguir la cadena hasta ella**. Atribución → lead →
 * miembro → pago aprobado, con identificadores guardados, no con coincidencias
 * de teléfono ni ventanas temporales.
 *
 * Eso deja fuera ventas reales, y está bien que las deje fuera. Quien llegó por
 * un anuncio, no dejó lead y pagó en recepción cuenta como NO atribuido, y se
 * dice cuánto es. Repartir esas ventas «proporcionalmente» es exactamente cómo
 * un panel de rentabilidad empieza a mentir sin que nadie lo note.
 */
class CampaignAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private CampaignAnalyticsService $analytics;

    private Carbon $from;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('meta.enabled', false);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'UTC'));

        $this->analytics = app(CampaignAnalyticsService::class);
        $this->from = Carbon::parse('2026-08-01 00:00:00', 'UTC');
        $this->to = Carbon::parse('2026-08-31 23:59:59', 'UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Montaje ─────────────────────────────────────────────────────────────

    private function plan(string $name, float $price): Plan
    {
        return Plan::create([
            'name' => $name, 'price' => $price, 'duration_days' => 30,
            'active' => true, 'sort_order' => 1,
        ]);
    }

    /**
     * Un prospecto con su atribución. `$firstAd` y `$lastAd` permiten montar el
     * caso de varios contactos sin tocar el servicio de atribución.
     */
    private function leadWithAttribution(
        ?string $firstAd = 'AD-A',
        ?string $lastAd = null,
        string $sourceType = 'ad',
        ?string $platform = 'instagram',
        ?string $campaign = null,
        ?string $status = 'new',
    ): MarketingLead {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => $status,
        ]);

        $conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);

        MarketingLeadAttribution::create([
            'marketing_lead_id' => $lead->id,
            'marketing_conversation_id' => $conversation->id,
            'source_type' => $sourceType,
            'source_platform' => $platform,
            'campaign_name' => $campaign,
            'ad_id' => $firstAd,
            'first_touch_at' => now()->subDays(5),
            'first_touch_source_type' => $sourceType,
            'first_touch_ad_id' => $firstAd,
            'last_touch_at' => now()->subDay(),
            'last_touch_source_type' => $sourceType,
            'last_touch_ad_id' => $lastAd ?? $firstAd,
            'received_at' => now()->subDay(),
            'attribution_confidence' => 'high',
        ]);

        return $lead->fresh();
    }

    /** Convierte un lead en miembro para poder colgarle pagos. */
    private function makeMember(MarketingLead $lead): Member
    {
        $user = User::create([
            'name' => 'Cliente '.$lead->id,
            'email' => 'c'.$lead->id.'@ironbody.test',
            'password' => 'x',
        ]);

        $member = Member::create([
            'user_id' => $user->id,
            'full_name' => 'Cliente '.$lead->id,
            'document_number' => (string) (10000000 + $lead->id),
            'phone' => $lead->phone,
        ]);

        $lead->forceFill(['member_id' => $member->id])->save();

        return $member;
    }

    private function payment(
        Member $member,
        float $amount,
        string $status = 'approved',
        ?Carbon $at = null,
        string $currency = 'COP',
    ): PaymentTransaction {
        $at ??= now()->subDays(2);

        $payment = PaymentTransaction::create([
            'reference' => 'REF-'.uniqid(),
            // Obligatoria en la tabla: es la que impide cobrar dos veces el
            // mismo intento, asi que no puede quedarse vacia ni en pruebas.
            'idempotency_key' => 'IDEM-'.uniqid('', true),
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'provider' => 'wompi',
        ]);

        $payment->forceFill([
            'created_at' => $at,
            'paid_at' => $status === 'approved' ? $at : null,
        ])->save();

        return $payment;
    }

    private function rowFor(array $rows, string $bucket): ?array
    {
        return collect($rows)->firstWhere('bucket', $bucket);
    }

    // ── 1-3. La cadena de atribución ────────────────────────────────────────

    public function test_1_un_lead_con_primer_contacto_cuenta_en_su_anuncio(): void
    {
        $this->leadWithAttribution('AD-A');

        $rows = $this->analytics->breakdown('ad', $this->from, $this->to);

        $this->assertSame(1, $this->rowFor($rows, 'AD-A')['leads']);
    }

    /** Primer y último contacto son preguntas distintas y se cuentan aparte. */
    public function test_2_primer_y_ultimo_contacto_distintos_no_se_mezclan(): void
    {
        $this->leadWithAttribution('AD-PRIMERO', 'AD-ULTIMO');

        $first = $this->analytics->breakdown('ad', $this->from, $this->to, touch: 'first');
        $last = $this->analytics->breakdown('ad', $this->from, $this->to, touch: 'last');

        $this->assertSame(1, $this->rowFor($first, 'AD-PRIMERO')['leads']);
        $this->assertNull($this->rowFor($first, 'AD-ULTIMO'));

        $this->assertSame(1, $this->rowFor($last, 'AD-ULTIMO')['leads']);
        $this->assertNull($this->rowFor($last, 'AD-PRIMERO'));
    }

    public function test_3_un_lead_sin_atribucion_cae_en_desconocido(): void
    {
        $this->leadWithAttribution(firstAd: null, sourceType: 'unknown', platform: null);

        $rows = $this->analytics->breakdown('ad', $this->from, $this->to);

        $this->assertSame(1, $this->rowFor($rows, CampaignAnalyticsService::UNKNOWN_LABEL)['leads']);
    }

    // ── 4-6. El dinero ──────────────────────────────────────────────────────

    /**
     * La prueba central: una venta que no se puede seguir hasta una pauta NO se
     * le regala a ninguna. Se cuenta aparte y se dice cuánto es.
     */
    public function test_4_una_venta_sin_atribucion_no_se_asigna_a_nadie(): void
    {
        // Miembro que pagó sin lead detrás: llegó por la puerta.
        $user = User::create(['name' => 'Walk In', 'email' => 'walkin@ironbody.test', 'password' => 'x']);
        $member = Member::create([
            'user_id' => $user->id, 'full_name' => 'Walk In',
            'document_number' => '999888777', 'phone' => '3001112233',
        ]);
        $this->payment($member, 150000);

        $revenue = $this->analytics->revenueTotals($this->from, $this->to);

        $this->assertSame(150000.0, $revenue['total']);
        $this->assertSame(0.0, $revenue['attributed'], 'Se atribuyo una venta sin evidencia.');
        $this->assertSame(150000.0, $revenue['unattributed']);
        $this->assertSame(1.0, $revenue['unattributed_share']);
    }

    public function test_5_una_venta_atribuible_suma_a_su_anuncio(): void
    {
        $lead = $this->leadWithAttribution('AD-A');
        $this->payment($this->makeMember($lead), 90000);

        $rows = $this->analytics->breakdown('ad', $this->from, $this->to);
        $revenue = $this->analytics->revenueTotals($this->from, $this->to);

        $this->assertSame(90000.0, $this->rowFor($rows, 'AD-A')['revenue']);
        $this->assertSame(90000.0, $revenue['attributed']);
        $this->assertSame(0.0, $revenue['unattributed']);
    }

    public function test_6_los_ingresos_de_ultimo_contacto_se_pueden_ver_aparte(): void
    {
        $lead = $this->leadWithAttribution('AD-PRIMERO', 'AD-ULTIMO');
        $this->payment($this->makeMember($lead), 90000);

        $first = $this->analytics->breakdown('ad', $this->from, $this->to, touch: 'first');
        $last = $this->analytics->breakdown('ad', $this->from, $this->to, touch: 'last');

        $this->assertSame(90000.0, $this->rowFor($first, 'AD-PRIMERO')['revenue']);
        $this->assertSame(90000.0, $this->rowFor($last, 'AD-ULTIMO')['revenue']);

        // Y NO se suman: es la misma venta contada desde dos preguntas.
        $this->assertSame(90000.0, $this->analytics->revenueTotals($this->from, $this->to)['total']);
    }

    // ── 7-8. Pagos que no son ventas ────────────────────────────────────────

    public function test_7_de_dos_pagos_solo_cuenta_el_aprobado(): void
    {
        $lead = $this->leadWithAttribution('AD-A');
        $member = $this->makeMember($lead);

        $this->payment($member, 90000, 'approved');
        $this->payment($member, 90000, 'declined');

        $row = $this->rowFor($this->analytics->breakdown('ad', $this->from, $this->to), 'AD-A');

        $this->assertSame(1, $row['sales']);
        $this->assertSame(1, $row['payments_declined']);
        $this->assertSame(90000.0, $row['revenue']);
    }

    /** Dos filas por el mismo intento no pueden contar como dos ventas. */
    public function test_8_un_pago_pendiente_que_luego_se_aprueba_no_cuenta_dos_veces(): void
    {
        $lead = $this->leadWithAttribution('AD-A');
        $member = $this->makeMember($lead);

        $payment = $this->payment($member, 90000, 'pending');
        $payment->forceFill(['status' => 'approved', 'paid_at' => now()->subDay()])->save();

        $row = $this->rowFor($this->analytics->breakdown('ad', $this->from, $this->to), 'AD-A');

        $this->assertSame(1, $row['sales']);
        $this->assertSame(90000.0, $row['revenue']);
    }

    // ── 9-11. Tipos de venta ────────────────────────────────────────────────

    public function test_9_el_segundo_pago_del_mismo_miembro_es_renovacion(): void
    {
        $lead = $this->leadWithAttribution('AD-A');
        $member = $this->makeMember($lead);

        $this->payment($member, 90000, 'approved', now()->subDays(40));
        $this->payment($member, 90000, 'approved', now()->subDays(5));

        $row = $this->rowFor($this->analytics->breakdown('ad', $this->from, $this->to), 'AD-A');

        $this->assertSame(1, $row['renewals'], 'El alta se conto como renovacion o al reves.');
        $this->assertSame(90000.0, $row['renewal_revenue']);
    }

    public function test_10_una_renovacion_mas_cara_es_un_upgrade(): void
    {
        $lead = $this->leadWithAttribution('AD-A');
        $member = $this->makeMember($lead);

        $this->payment($member, 90000, 'approved', now()->subDays(40));
        $this->payment($member, 240000, 'approved', now()->subDays(5));

        $row = $this->rowFor($this->analytics->breakdown('ad', $this->from, $this->to), 'AD-A');

        $this->assertSame(1, $row['upgrades']);
        $this->assertSame(240000.0, $row['upgrade_revenue']);
    }

    public function test_11_una_reactivacion_sigue_contando_como_ingreso_del_anuncio(): void
    {
        $lead = $this->leadWithAttribution('AD-A');
        $member = $this->makeMember($lead);

        $this->payment($member, 90000, 'approved', now()->subDays(200));
        $this->payment($member, 90000, 'approved', now()->subDays(3));

        $row = $this->rowFor($this->analytics->breakdown('ad', $this->from, $this->to), 'AD-A');

        // El alta antigua queda fuera del periodo; la vuelta cuenta.
        $this->assertSame(1, $row['sales']);
        $this->assertSame(90000.0, $row['revenue']);
    }

    // ── 12. Ventas que no valen ─────────────────────────────────────────────

    public function test_12_una_venta_anulada_no_suma_ingresos(): void
    {
        $lead = $this->leadWithAttribution('AD-A');
        $this->payment($this->makeMember($lead), 90000, 'voided');

        $row = $this->rowFor($this->analytics->breakdown('ad', $this->from, $this->to), 'AD-A');

        $this->assertSame(0, $row['sales']);
        $this->assertSame(0.0, $row['revenue']);
    }

    // ── 13-15. Gasto, división y vacíos ─────────────────────────────────────

    /** Sin gasto conectado: «no disponible», jamás «ROAS = 0». */
    public function test_13_sin_gasto_no_hay_roas_inventado(): void
    {
        $lead = $this->leadWithAttribution('AD-A');
        $this->payment($this->makeMember($lead), 90000);

        $row = $this->rowFor($this->analytics->breakdown('ad', $this->from, $this->to), 'AD-A');
        $summary = $this->analytics->summary($this->from, $this->to);

        $this->assertNull($row['roas'], 'Se calculo un ROAS sin gasto real.');
        $this->assertNull($row['spend']);
        $this->assertFalse($summary['spend']['available']);
        $this->assertStringContainsString('no disponible', $summary['spend']['note']);
    }

    /** Sin denominador, null. Ni cero, ni infinito, ni una excepción. */
    public function test_14_las_tasas_no_dividen_entre_cero(): void
    {
        $rates = $this->analytics->ratesFor([
            'conversations' => 0, 'leads' => 0, 'qualified_leads' => 0,
            'opportunities' => 0, 'payments_approved' => 0, 'sales' => 0,
        ]);

        foreach ($rates as $name => $value) {
            $this->assertNull($value, "La tasa {$name} deberia ser null sin datos.");
        }
    }

    public function test_15_una_campana_sin_leads_no_rompe_el_informe(): void
    {
        $rows = $this->analytics->breakdown('campaign', $this->from, $this->to);

        $this->assertIsArray($rows);
        $this->assertSame([], $rows);
    }

    // ── 16-18. Filtros y rangos ─────────────────────────────────────────────

    public function test_16_el_rango_temporal_excluye_lo_de_fuera(): void
    {
        $this->leadWithAttribution('AD-A');

        $rows = $this->analytics->breakdown(
            'ad',
            Carbon::parse('2026-01-01', 'UTC'),
            Carbon::parse('2026-01-31', 'UTC'),
        );

        $this->assertSame([], $rows);
    }

    public function test_17_una_campana_desactivada_conserva_su_historia(): void
    {
        $lead = $this->leadWithAttribution('AD-VIEJO', campaign: 'Campaña de julio');
        $this->payment($this->makeMember($lead), 90000);

        $rows = $this->analytics->breakdown('campaign', $this->from, $this->to);

        // Que la campaña ya no exista en Meta no borra lo que produjo.
        $this->assertSame(90000.0, $this->rowFor($rows, 'Campaña de julio')['revenue']);
    }

    public function test_18_un_producto_eliminado_sigue_apareciendo_en_su_historia(): void
    {
        $plan = $this->plan('Semestral', 400000);
        $lead = $this->leadWithAttribution('AD-A');

        MarketingLeadAttribution::where('marketing_lead_id', $lead->id)
            ->update(['advertised_product' => 'Semestral', 'advertised_plan_id' => $plan->id]);

        $plan->forceFill(['active' => false])->save();

        $rows = $this->analytics->breakdown('advertised_product', $this->from, $this->to);

        $this->assertSame(1, $this->rowFor($rows, 'Semestral')['leads']);
    }

    // ── 19-21. Casos del canal ──────────────────────────────────────────────

    public function test_19_un_webhook_duplicado_no_infla_los_leads(): void
    {
        $lead = $this->leadWithAttribution('AD-A');

        // El servicio de atribucion es idempotente por lead: una sola fila.
        $this->assertSame(1, MarketingLeadAttribution::where('marketing_lead_id', $lead->id)->count());

        $rows = $this->analytics->breakdown('ad', $this->from, $this->to);

        $this->assertSame(1, $this->rowFor($rows, 'AD-A')['leads']);
    }

    /** Mezclar monedas invalida el total, y hay que decirlo. */
    public function test_20_mas_de_una_moneda_queda_avisado(): void
    {
        $lead = $this->leadWithAttribution('AD-A');
        $member = $this->makeMember($lead);

        $this->payment($member, 90000, 'approved', now()->subDays(3), 'COP');
        $this->payment($member, 25, 'approved', now()->subDays(2), 'USD');

        $revenue = $this->analytics->revenueTotals($this->from, $this->to);

        $this->assertTrue($revenue['multi_currency'], 'No se aviso de que hay varias monedas.');
    }

    public function test_21_una_venta_con_varios_contactos_se_cuenta_una_vez(): void
    {
        $lead = $this->leadWithAttribution('AD-PRIMERO', 'AD-ULTIMO');
        $this->payment($this->makeMember($lead), 90000);

        $revenue = $this->analytics->revenueTotals($this->from, $this->to);

        $this->assertSame(1, $revenue['sales']);
        $this->assertSame(90000.0, $revenue['total']);
    }

    // ── 22-24. Filtros y orden ──────────────────────────────────────────────

    public function test_22_se_puede_filtrar_por_campana(): void
    {
        $this->leadWithAttribution('AD-A', campaign: 'Agosto');
        $this->leadWithAttribution('AD-B', campaign: 'Julio');

        $rows = $this->analytics->breakdown('ad', $this->from, $this->to, ['campaign' => 'Agosto']);

        $this->assertNotNull($this->rowFor($rows, 'AD-A'));
        $this->assertNull($this->rowFor($rows, 'AD-B'), 'El filtro de campaña no filtro.');
    }

    public function test_23_se_puede_filtrar_por_plataforma(): void
    {
        $this->leadWithAttribution('AD-IG', platform: 'instagram');
        $this->leadWithAttribution('AD-FB', platform: 'facebook');

        $rows = $this->analytics->breakdown('ad', $this->from, $this->to, ['platform' => 'facebook']);

        $this->assertNotNull($this->rowFor($rows, 'AD-FB'));
        $this->assertNull($this->rowFor($rows, 'AD-IG'));
    }

    public function test_24_las_filas_traen_lo_necesario_para_ordenar_por_ingreso(): void
    {
        $rico = $this->leadWithAttribution('AD-RICO');
        $this->payment($this->makeMember($rico), 240000);

        $pobre = $this->leadWithAttribution('AD-POBRE');
        $this->payment($this->makeMember($pobre), 90000);

        $rows = collect($this->analytics->breakdown('ad', $this->from, $this->to))
            ->sortByDesc('revenue')->values();

        $this->assertSame('AD-RICO', $rows[0]['bucket']);
        $this->assertSame('AD-POBRE', $rows[1]['bucket']);
    }

    // ── Volumen sin dinero: la pregunta que importa ─────────────────────────

    /**
     * «¿Qué campañas atraen volumen pero no dinero?» tiene que poder
     * responderse mirando una fila.
     */
    public function test_una_campana_con_muchos_leads_y_sin_ventas_se_ve(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->leadWithAttribution('AD-RUIDO');
        }

        $row = $this->rowFor($this->analytics->breakdown('ad', $this->from, $this->to), 'AD-RUIDO');

        $this->assertSame(5, $row['leads']);
        $this->assertSame(0, $row['sales']);
        $this->assertSame(0.0, $row['revenue']);
        $this->assertSame(0.0, $row['conversion_rate']);
    }
}
