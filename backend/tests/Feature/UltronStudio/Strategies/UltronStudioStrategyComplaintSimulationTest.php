<?php

namespace Tests\Feature\UltronStudio\Strategies;

use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Run\LadderInputBuilder;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioStrategies;
use App\Services\UltronStudio\StudioStrategySimulations;
use App\Services\UltronStudio\StudioStrategySimulator;
use App\Services\UltronStudio\StudioStrategyVersions;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\TestCase;

/**
 * Q1-3 en la SIMULACIÓN del Studio (D9-12): quien se quejó hace poco no sale
 * como contactable tampoco aquí. La simulación es pura y no lee la
 * configuración: la ventana y las finalidades efectivas de la ejecución
 * (`OutreachConfig::complaintDays()` y `complaintPurposes()`) le llegan por el
 * contexto que arma `StudioStrategySimulations` (M-7 de security), y estas
 * pruebas atan la simulación a la ejecución con valores distintos de los de
 * por defecto.
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
        // M-7: la queja ya no es una constante de la simulación; la ventana y las finalidades le llegan por el contexto, de `OutreachConfig`.
        $this->assertSame(['days' => OutreachConfig::complaintDays(), 'purposes' => OutreachConfig::complaintPurposes()], StudioStrategySimulations::complaintContext());
        $this->assertSame(OutreachConfig::humanRequestedDays(), StudioStrategySimulator::HUMAN_REQUESTED_DAYS);
    }

    /**
     * M-7 de security: con `outreach.complaint_days` configurado (7, distinto de los 30 por defecto), la simulación
     * del Studio dice lo mismo que la ejecución. Antes, la de hace 10 días salía negada en la simulación y
     * contactable en la ejecución; con 0 días (el freno apagado), al revés.
     */
    public function test_la_simulacion_usa_la_ventana_configurada_y_dice_lo_mismo_que_la_ejecucion(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);
        $s = app(StudioStrategies::class)->create(new StudioAccess($ana), Request::create('/'), (string) Str::uuid(), 'quejas-config', 'Quejas (configuración)', 'commercial', $this->strategyContent($t))['strategy'];
        $base = ['membership.active' => true, 'membership.days_to_expiry' => 5, 'subject.has_whatsapp_identity' => true, 'subject.is_minor' => false, 'receivable.has_overdue' => false];
        $ahora = new DateTimeImmutable('2026-11-17T15:00:00Z');
        $horas = ['ayer' => 24, 'hace3' => 3 * 24, 'hace10' => 10 * 24];
        $simular = function () use ($s, $ana, $base, $horas): array {
            $gente = [];
            foreach ($horas as $ref => $h) {
                $gente[] = ['ref' => $ref, 'facts' => $base, 'consent' => ['whatsapp' => 'granted'], 'canary' => ['whatsapp' => true], 'events' => [['key' => 'complaint_created', 'hoursAgo' => $h]]];
            }
            $r = $this->s9('POST', "strategies/{$s->uuid}/simulations", $ana, ['kind' => 'synthetic', 'at' => '2026-11-17T15:00:00Z', 'people' => $gente])->assertCreated()->json('data');

            return array_column($r['people'], null, 'ref');
        };
        $ejecucion = static fn (int $h): ?bool => LadderInputBuilder::complaintRecently([['key' => 'complaint_created', 'at' => RelativeTime::format($ahora->modify("-{$h} hours"))]], $ahora, 'commercial');

        foreach ([7 => ['ayer' => 'deny', 'hace3' => 'deny', 'hace10' => 'plan'], 0 => ['ayer' => 'plan', 'hace3' => 'plan', 'hace10' => 'plan']] as $dias => $esperado) {
            config(['outreach.complaint_days' => $dias]);
            $por = $simular();
            foreach ($horas as $ref => $h) {
                $this->assertSame($esperado[$ref], $por[$ref]['decision']['kind'], "complaint_days={$dias}, {$ref}");
                // La simulación y la ejecución, atadas: niega por la queja exactamente cuando la ejecución la ve.
                $this->assertSame($ejecucion($h) === true, in_array('complaint_recent', $por[$ref]['decision']['reasons'], true), "complaint_days={$dias}, {$ref}: la simulación y la ejecución dicen cosas distintas");
            }
        }
    }

    /**
     * M-7: las finalidades configuradas (`outreach.complaint_purposes = ['commercial']`, sin la cobranza) también
     * llegan a la simulación: una estrategia de cobranza deja de frenar por la queja, como en la ejecución.
     */
    public function test_la_simulacion_usa_las_finalidades_configuradas(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto, 'assisted', 'collections', ['medium' => 'whatsapp', 'script' => 'Saluda y recuerda con respeto que hay una cuota pendiente; ofrece ayuda para ponerse al día.'], 'guion-cobro');
        $s = app(StudioStrategies::class)->create(new StudioAccess($ana), Request::create('/'), (string) Str::uuid(), 'quejas-cobro', 'Quejas (cobranza)', 'collections', $this->strategyContent($t, [
            'goal' => 'collect_payment',
            'purpose' => 'collections',
            'trigger' => ['event' => 'receivable.overdue', 'params' => ['daysMin' => 3, 'daysMax' => 30]],
            'audience' => ['fact' => 'receivable.has_overdue', 'op' => 'eq', 'value' => true],
            'success' => ['fact' => 'receivable.has_overdue', 'op' => 'eq', 'value' => false],
        ]))['strategy'];
        $persona = ['ref' => 'moroso', 'facts' => ['receivable.has_overdue' => true, 'subject.has_whatsapp_identity' => true, 'subject.is_minor' => false],
            'consent' => ['whatsapp' => 'granted'], 'canary' => ['whatsapp' => true], 'events' => [['key' => 'complaint_created', 'hoursAgo' => 24]]];
        $simular = fn (): array => $this->s9('POST', "strategies/{$s->uuid}/simulations", $ana, ['kind' => 'synthetic', 'at' => '2026-11-17T15:00:00Z', 'people' => [$persona]])
            ->assertCreated()->json('data.people.0.decision');
        $ahora = new DateTimeImmutable('2026-11-17T15:00:00Z');
        $queja = [['key' => 'complaint_created', 'at' => '2026-11-16T15:00:00Z']];

        // Por defecto la cobranza frena (y N6 llega a evaluarse: la prueba no es vacía).
        $this->assertTrue(LadderInputBuilder::complaintRecently($queja, $ahora, 'collections'));
        $this->assertSame(['deny', 'N6', ['complaint_recent']], array_values(array_intersect_key($simular(), array_flip(['kind', 'level', 'reasons']))));

        config(['outreach.complaint_purposes' => ['commercial']]);
        $this->assertFalse(LadderInputBuilder::complaintRecently($queja, $ahora, 'collections'), 'la ejecución ya no frena la cobranza');
        $this->assertNotContains('complaint_recent', $simular()['reasons'], 'y la simulación tampoco');
    }

    /**
     * M-7, la regla entera: para cada configuración, finalidad y foto de eventos, la simulación (pura, con el
     * contexto que le pone `StudioStrategySimulations`) y la ejecución (`LadderInputBuilder`) dan el mismo valor.
     */
    public function test_la_regla_de_la_queja_de_la_simulacion_es_la_de_la_ejecucion_para_cualquier_configuracion(): void
    {
        $ahora = new DateTimeImmutable('2026-11-17T15:00:00Z');
        $fotos = [
            'sin datos' => null,
            'sin quejas' => [],
            'ayer' => [['key' => 'complaint_created', 'at' => '2026-11-16T15:00:00Z', 'attrs' => []]],
            'hace 10 días' => [['key' => 'complaint_created', 'at' => '2026-11-07T15:00:00Z', 'attrs' => []]],
            'otro evento' => [['key' => 'human_requested', 'at' => '2026-11-16T15:00:00Z', 'attrs' => []]],
        ];
        $vistos = 0;
        foreach ([null, 7, 0, 400, -3] as $dias) {
            foreach ([null, ['commercial'], ['commercial', 'service']] as $finalidades) {
                config(['outreach.complaint_days' => $dias, 'outreach.complaint_purposes' => $finalidades]);
                $contexto = StudioStrategySimulations::complaintContext();
                foreach (['commercial', 'collections', 'service'] as $finalidad) {
                    foreach ($fotos as $nombre => $eventos) {
                        $foto = FactSnapshot::fromArray(['facts' => [], 'events' => $eventos]);
                        $this->assertSame(
                            LadderInputBuilder::complaintRecently($eventos, $ahora, $finalidad),
                            StudioStrategySimulator::complaintRecently($foto, $ahora, $finalidad, $contexto),
                            json_encode(['days' => $dias, 'purposes' => $finalidades, 'purpose' => $finalidad, 'foto' => $nombre]),
                        );
                        $vistos++;
                    }
                }
            }
        }
        $this->assertSame(5 * 3 * 3 * 5, $vistos);
    }

    public function test_sin_el_contexto_de_la_queja_la_simulacion_falla_en_voz_alta(): void
    {
        $foto = FactSnapshot::fromArray(['facts' => [], 'events' => []]);
        foreach ([[], ['days' => 30], ['purposes' => ['commercial']], ['days' => '30', 'purposes' => ['commercial']], ['days' => -1, 'purposes' => ['commercial']], ['days' => 30, 'purposes' => 'commercial'], ['days' => 30, 'purposes' => [1]]] as $malo) {
            try {
                StudioStrategySimulator::complaintRecently($foto, new DateTimeImmutable('2026-11-17T15:00:00Z'), 'commercial', $malo);
                $this->fail('Sin el contexto de la queja la simulación no puede caer en un valor por defecto: '.json_encode($malo));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
