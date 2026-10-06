<?php

namespace Tests\Feature\Marketing\Attribution;

use App\Models\MarketingLeadAttribution;
use App\Models\MarketingLeadIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Los comandos de preparación: resolver identidades, auditar la atribución
 * (solo lectura) y recuperar atribuciones perdidas (simula por defecto).
 */
class AttributionCommandsTest extends TestCase
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

    public function test_resolver_identidades_simula_y_luego_escribe_sin_duplicar(): void
    {
        $this->customer('3001112233');
        $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);
        $this->lead('2026-10-02 16:00:00', ['phone' => '573009990000']);
        $this->lead('2026-10-02 17:00:00', ['source' => 'manual_test']);

        $this->artisan('marketing:resolve-lead-identities', ['--dry-run' => true])
            ->expectsOutputToContain('Simulación: se escribirían 2 filas')
            ->assertSuccessful();
        $this->assertSame(0, MarketingLeadIdentity::query()->count());

        $this->artisan('marketing:resolve-lead-identities')
            ->expectsOutputToContain('Listo: 2 filas')
            ->assertSuccessful();
        $this->assertSame(1, MarketingLeadIdentity::query()->where('method', 'phone_member')->count());
        $this->assertSame(1, MarketingLeadIdentity::query()->where('method', 'none')->count());

        $this->artisan('marketing:resolve-lead-identities')
            ->expectsOutputToContain('Listo: 0 filas')
            ->assertSuccessful();
        $this->assertSame(2, MarketingLeadIdentity::query()->count());
    }

    /** La auditoría cuenta las tres categorías y no escribe nada. */
    public function test_auditoria_de_solo_lectura(): void
    {
        $this->adHierarchy('120230001');

        // ATTRIBUTABLE: pauta con campaña resuelta, y una publicación orgánica.
        $resuelto = $this->lead('2026-10-01 15:00:00');
        $this->touch($resuelto, $this->adReferral('120230001'), '2026-10-01 15:00:00');
        $organico = $this->lead('2026-10-01 16:00:00');
        $this->touch($organico, $this->postReferral(), '2026-10-01 16:00:00');

        // PARTIALLY_ATTRIBUTABLE: pauta sin campaña resoluble, y un lead sin fila
        // que tiene el referral guardado en un mensaje.
        $pendiente = $this->lead('2026-10-01 17:00:00');
        $this->touch($pendiente, $this->adReferral('120239999', clickId: 'clid-p'), '2026-10-01 17:00:00');
        $sinFila = $this->lead('2026-10-01 18:00:00');
        $this->inbound($this->conversation($sinFila), '2026-10-01 18:00:00', ['referral' => $this->adReferral('120230001', clickId: 'clid-s')]);

        // UNATTRIBUTABLE: sin referral en el primer contacto (aunque vuelva por
        // un anuncio), y sin fila ni referral.
        $desconocido = $this->lead('2026-10-01 19:00:00');
        $this->touch($desconocido, null, '2026-10-01 19:00:00');
        $this->touch($desconocido, $this->adReferral('120230001', clickId: 'clid-d'), '2026-10-02 19:00:00');
        $nada = $this->lead('2026-10-01 20:00:00');
        $this->inbound($this->conversation($nada), '2026-10-01 20:00:00');

        // Las pruebas no cuentan.
        $prueba = $this->lead('2026-10-01 21:00:00', ['source' => 'manual_test']);
        $this->touch($prueba, $this->adReferral('120230001', clickId: 'clid-t'), '2026-10-01 21:00:00');

        $antes = [MarketingLeadAttribution::query()->count(), MarketingLeadIdentity::query()->count()];

        Artisan::call('marketing:attribution-audit', ['--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertSame(6, $report['real_leads']);
        $this->assertSame(['ATTRIBUTABLE' => 2, 'PARTIALLY_ATTRIBUTABLE' => 2, 'UNATTRIBUTABLE' => 2], $report['categories']);
        $this->assertSame([
            'paid_campaign_resolved' => 1,
            'organic' => 1,
            'direct' => 0,
            'paid_campaign_pending_meta_ads' => 1,
            'no_row_with_stored_referral' => 1,
            'unknown_first_touch' => 1,
            'no_row_without_referral' => 1,
        ], $report['details']);
        $this->assertSame(1, $report['first_unknown_last_known']);
        $this->assertSame(6, array_sum($report['identity']));
        $this->assertSame($antes, [MarketingLeadAttribution::query()->count(), MarketingLeadIdentity::query()->count()], 'la auditoría escribió');

        $this->artisan('marketing:attribution-audit')
            ->expectsOutputToContain('Leads reales: 6')
            ->expectsOutputToContain('Solo lectura')
            ->assertSuccessful();
    }

    /**
     * El relleno simula por defecto; con --apply reproduce lo que el webhook
     * habría hecho, solo en leads reales sin fila y con referral guardado.
     */
    public function test_relleno_de_atribuciones(): void
    {
        // Recuperable: su primer entrante traía el anuncio.
        $recuperable = $this->lead('2026-09-20 15:00:00');
        $c1 = $this->conversation($recuperable);
        $this->inbound($c1, '2026-09-20 15:00:00', ['wa_id' => $recuperable->meta_user_id, 'referral' => $this->adReferral('120230001', 'https://fb.me/abc', 'clid-r')]);
        $this->inbound($c1, '2026-09-20 15:05:00');

        // Recuperable, pero su primer entrante NO traía referral: queda «unknown»
        // con el anuncio como último contacto, igual que en vivo.
        $tardio = $this->lead('2026-09-21 15:00:00');
        $c2 = $this->conversation($tardio);
        $this->inbound($c2, '2026-09-21 15:00:00');
        $this->inbound($c2, '2026-09-25 15:00:00', ['referral' => $this->adReferral('120230002', 'https://www.instagram.com/reel/x', 'clid-t')]);

        // No se tocan: uno con fila, uno sin referral y uno de prueba.
        $conFila = $this->lead('2026-09-22 15:00:00');
        $this->touch($conFila, null, '2026-09-22 15:00:00');
        $this->inbound($this->conversation($conFila), '2026-09-22 15:00:00', ['referral' => $this->adReferral('120230003', clickId: 'clid-f')]);
        $sinReferral = $this->lead('2026-09-23 15:00:00');
        $this->inbound($this->conversation($sinReferral), '2026-09-23 15:00:00');
        $prueba = $this->lead('2026-09-24 15:00:00', ['source' => 'manual_test']);
        $this->inbound($this->conversation($prueba), '2026-09-24 15:00:00', ['referral' => $this->adReferral('120230004', clickId: 'clid-x')]);

        $this->artisan('marketing:attribution-backfill')
            ->expectsOutputToContain('Simulación: no se escribió nada')
            ->assertSuccessful();
        $this->assertSame(1, MarketingLeadAttribution::query()->count(), 'la simulación escribió');

        $this->artisan('marketing:attribution-backfill', ['--apply' => true])
            ->expectsOutputToContain('Atribuciones creadas: 2. Fallidas: 0.')
            ->assertSuccessful();

        $a = MarketingLeadAttribution::query()->where('marketing_lead_id', $recuperable->id)->firstOrFail();
        $this->assertSame('ad', $a->first_touch_source_type);
        $this->assertSame('120230001', $a->first_touch_ad_id);
        $this->assertSame('facebook', $a->source_platform);
        $this->assertSame('2026-09-20 15:00:00', $a->first_touch_at->toDateTimeString(), 'con la fecha del mensaje, no la de hoy');
        $this->assertSame($c1->id, (int) $a->marketing_conversation_id);

        $b = MarketingLeadAttribution::query()->where('marketing_lead_id', $tardio->id)->firstOrFail();
        $this->assertSame('unknown', $b->first_touch_source_type);
        $this->assertSame('ad', $b->last_touch_source_type);
        $this->assertSame('120230002', $b->last_touch_ad_id);
        $this->assertSame('2026-09-25 15:00:00', $b->last_touch_at->toDateTimeString());

        $this->assertSame('unknown', MarketingLeadAttribution::query()->where('marketing_lead_id', $conFila->id)->value('first_touch_source_type'), 'una fila existente no se reescribe');
        $this->assertFalse(MarketingLeadAttribution::query()->whereIn('marketing_lead_id', [$sinReferral->id, $prueba->id])->exists());

        // Repetirlo no hace nada.
        $this->artisan('marketing:attribution-backfill', ['--apply' => true])
            ->expectsOutputToContain('Nada que hacer')
            ->assertSuccessful();
        $this->assertSame(3, MarketingLeadAttribution::query()->count());
    }
}
