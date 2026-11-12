<?php

namespace Tests\Feature\UltronStudio\Strategies;

use App\Services\Outreach\Run\OutreachConfig;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioStrategies;
use App\Services\UltronStudio\StudioStrategySimulator;
use App\Services\UltronStudio\StudioStrategyVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\TestCase;

/**
 * Q1-3 en la SIMULACIÓN del Studio (D9-12): quien se quejó hace poco no sale
 * como contactable tampoco aquí. La simulación es pura y no lee la
 * configuración: sus valores (30 días; comercial y cobranza) son los mismos
 * que los de la ejecución por defecto, y esta prueba los ata.
 */
class UltronStudioStrategyComplaintSimulationTest extends TestCase
{
    use FactsWorld;
    use RefreshDatabase;
    use StrategyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withoutRouteMap();
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: true);
        Carbon::setTestNow($this->weekdayMorning());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_la_simulacion_niega_en_n6_a_quien_se_quejo_hace_poco_y_no_a_quien_se_quejo_hace_31_dias(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);
        $s = app(StudioStrategies::class)->create(new StudioAccess($ana), Request::create('/'), (string) Str::uuid(), 'quejas-sim', 'Quejas (simulación)', 'commercial', $this->strategyContent($t))['strategy'];
        $base = ['membership.active' => true, 'membership.days_to_expiry' => 5, 'subject.has_whatsapp_identity' => true, 'subject.is_minor' => false, 'receivable.has_overdue' => false];
        $persona = static fn (string $ref, ?array $eventos): array => ['ref' => $ref, 'facts' => $base, 'consent' => ['whatsapp' => 'granted'], 'canary' => ['whatsapp' => true], 'events' => $eventos];

        $r = $this->s9('POST', "strategies/{$s->uuid}/simulations", $ana, ['kind' => 'synthetic', 'at' => '2026-11-17T15:00:00Z', 'people' => [
            $persona('limpia', []),
            $persona('ayer', [['key' => 'complaint_created', 'hoursAgo' => 24]]),
            $persona('hace31', [['key' => 'complaint_created', 'hoursAgo' => 31 * 24]]),
            $persona('sindatos', null),
        ]])->assertCreated()->json('data');
        $por = array_column($r['people'], null, 'ref');

        $this->assertSame('plan', $por['limpia']['decision']['kind']);
        $this->assertSame(['deny', 'N6', ['complaint_recent']], [$por['ayer']['decision']['kind'], $por['ayer']['decision']['level'], $por['ayer']['decision']['reasons']]);
        $this->assertSame('exit_complaint', $por['ayer']['trace']['policy']['effect']);
        $this->assertSame('plan', $por['hace31']['decision']['kind'], 'fuera de la ventana de 30 días de Bogotá');
        // Sin datos de eventos: no se sabe si pidió una persona ni si se quejó; N6 niega por las dos (el lado seguro).
        $this->assertSame(['deny', 'N6', ['human_requested_recently', 'complaint_recent']], [$por['sindatos']['decision']['kind'], $por['sindatos']['decision']['level'], $por['sindatos']['decision']['reasons']]);
        $this->assertStringContainsString('N6', $por['ayer']['text'][0]);
    }

    public function test_en_la_muestra_real_la_queja_registrada_niega_en_n6_y_la_otra_persona_sale_planificada(): void
    {
        config(['commercial.events_enabled' => true]);   // la fuente de eventos con datos (L9-26)
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);
        $s = app(StudioStrategies::class)->create(new StudioAccess($ana), Request::create('/'), (string) Str::uuid(), 'quejas-real', 'Quejas (muestra real)', 'commercial', $this->strategyContent($t))['strategy'];
        $v = app(StudioStrategyVersions::class)->freeze(new StudioAccess($ana), Request::create('/'), (string) $s->uuid, (string) Str::uuid())['version'];
        $this->plan(['name' => 'Mensual']);
        $socios = [];
        foreach (['3001234571', '3001234572'] as $telefono) {
            $socio = $this->socio(['membership_end_date' => '2026-11-20']);
            $lead = $this->lead(['member_id' => $socio['member'], 'phone' => $telefono, 'consent_status' => 'granted']);
            $this->conversation($lead);
            $this->canary('+57'.$telefono, 'whatsapp');
            $socios[] = $socio;
        }
        $this->commercialEvent('complaint_created', '2026-11-16 15:00', $socios[0]['member']);   // ayer

        $r = $this->s9('POST', "strategies/versions/{$v->uuid}/simulations", $ana, ['kind' => 'sample'])->assertCreated()->json('data');
        $this->assertSame(2, $r['source']['candidates']);
        $porTipo = [];
        foreach ($r['people'] as $p) {
            $porTipo[$p['decision']['kind']][] = $p;
        }
        ksort($porTipo);
        $this->assertSame(['deny', 'plan'], array_keys($porTipo), json_encode(array_column(array_column($r['people'], 'decision'), 'reasons')));
        $this->assertSame(['N6', ['complaint_recent']], [$porTipo['deny'][0]['decision']['level'], $porTipo['deny'][0]['decision']['reasons']]);
        $this->assertSame(0, DB::table('outreach_enrollments')->count(), 'solo lectura');
    }

    public function test_los_valores_de_la_simulacion_son_los_de_la_ejecucion_por_defecto(): void
    {
        $this->assertSame(OutreachConfig::complaintDays(), StudioStrategySimulator::COMPLAINT_DAYS);
        $this->assertSame(OutreachConfig::complaintPurposes(), StudioStrategySimulator::COMPLAINT_PURPOSES);
        $this->assertSame(OutreachConfig::humanRequestedDays(), StudioStrategySimulator::HUMAN_REQUESTED_DAYS);
    }
}
