<?php

namespace Tests\Feature\UltronStudio\Strategies;

use App\Models\Admin;
use App\Models\RolePermission;
use App\Models\UltronStudio\StudioStrategySimulation;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\Outreach\Core\DecisionTrace;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioStrategies;
use App\Services\UltronStudio\StudioStrategyConfig;
use App\Services\UltronStudio\StudioStrategyVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\TestCase;

/**
 * T12 · SIMULACIÓN EXPLICABLE Y REPRODUCIBLE (contrato H9, D9-12; protección
 * 10): la misma versión y la misma foto de hechos dan la misma decisión y la
 * misma `traceHash`, también sobre una muestra REAL leída por
 * `BatchSnapshotBuilder`; las trazas, los recuentos y las respuestas no llevan
 * datos personales (solo `subjectRef`); lo que se guarda son recuentos y
 * huellas, nunca trazas por persona; y la puerta de aprobar exige una
 * simulación sin bloqueantes de la MISMA versión. Cero red.
 */
class UltronStudioStrategySimulationTest extends TestCase
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

    /** @return array{0: Admin, 1: Admin, 2: StudioStrategyVersion} */
    private function frozenVersion(): array
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);
        $s = app(StudioStrategies::class)->create(new StudioAccess($ana), Request::create('/'), (string) Str::uuid(), 'renovacion-sim', 'Renovación (simulación)', 'commercial', $this->strategyContent($t))['strategy'];

        return [$ana, $beto, app(StudioStrategyVersions::class)->freeze(new StudioAccess($ana), Request::create('/'), (string) $s->uuid, (string) Str::uuid())['version']];
    }

    public function test_t12_sintetica_la_misma_entrada_da_la_misma_decision_traza_y_resultado(): void
    {
        [$ana, , $v] = $this->frozenVersion();
        $cuerpo = ['kind' => 'synthetic', 'at' => '2026-11-17T15:00:00Z', 'people' => $this->syntheticPeople()];
        $a = $this->s9('POST', "strategies/versions/{$v->uuid}/simulations", $ana, $cuerpo)->assertCreated()->json('data');
        $b = $this->s9('POST', "strategies/versions/{$v->uuid}/simulations", $ana, $cuerpo)->assertCreated()->json('data');

        $this->assertSame($a['inputHash'], $b['inputHash']);
        $this->assertSame($a['resultHash'], $b['resultHash']);
        $this->assertSame($a['decisionsHash'], $b['decisionsHash']);
        $this->assertSame(array_column($a['people'], 'traceHash'), array_column($b['people'], 'traceHash'));
        foreach ($a['people'] as $p) {
            // La huella es la de la traza que se devuelve (cualquiera la puede recalcular).
            $this->assertSame($p['traceHash'], DecisionTrace::hash($p['trace']));
            $this->assertNotSame([], $p['text']);
        }
        // Otro instante (un domingo): otra decisión para quien entra, y otra huella.
        $domingo = $this->s9('POST', "strategies/versions/{$v->uuid}/simulations", $ana, ['at' => '2026-11-22T15:00:00Z'] + $cuerpo)->assertCreated()->json('data');
        $this->assertNotSame($a['decisionsHash'], $domingo['decisionsHash']);
        $this->assertSame('defer', $domingo['people'][0]['decision']['kind']);
        $this->assertContains('calendar_sunday', $domingo['people'][0]['decision']['reasons']);

        // Lo guardado: recuentos y huellas; ninguna traza por persona.
        $fila = StudioStrategySimulation::query()->where('uuid', $a['uuid'])->firstOrFail();
        $this->assertSame($a['resultHash'], $fila->result_hash);
        $guardado = json_encode($fila->result_json);
        $this->assertStringNotContainsString('p01', $guardado);
        $this->assertStringNotContainsString('"trace"', $guardado);
        $this->assertSame(2, $fila->result_json['counts']['evaluated']);
        $this->assertSame((int) $v->id, (int) $fila->version_id);
    }

    /**
     * T20/T21 vistos desde la simulación: la guarda del paso (N5), el objetivo
     * ya cumplido (sale por la puerta: `success_reached`), `human_requested`
     * reciente (N6, se deriva) y la fuente de eventos sin datos (L9-26:
     * desconocido, no se inscribe).
     */
    public function test_t20_t21_la_guarda_del_paso_el_objetivo_cumplido_y_la_peticion_de_una_persona_en_la_simulacion(): void
    {
        [$ana, $beto] = $this->bootstrapPair();
        $t = $this->approvedTemplate($ana, $beto);
        $contenido = $this->strategyContent($t, ['success' => ['event' => 'membership_renewed', 'op' => 'exists', 'within' => ['n' => 14, 'unit' => 'days']]]);
        $contenido['sequence'][0]['guard'] = ['fact' => 'membership.days_to_expiry', 'op' => 'gte', 'value' => 6];
        $s = app(StudioStrategies::class)->create(new StudioAccess($ana), Request::create('/'), (string) Str::uuid(), 'precedencia', 'Precedencia', 'commercial', $contenido)['strategy'];
        $base = ['membership.active' => true, 'subject.has_whatsapp_identity' => true, 'subject.is_minor' => false, 'receivable.has_overdue' => false];
        $persona = static fn (string $ref, int $dias, ?array $eventos): array => ['ref' => $ref, 'facts' => ['membership.days_to_expiry' => $dias] + $base,
            'consent' => ['whatsapp' => 'granted'], 'canary' => ['whatsapp' => true], 'events' => $eventos];

        $r = $this->s9('POST', "strategies/{$s->uuid}/simulations", $ana, ['kind' => 'synthetic', 'at' => '2026-11-17T15:00:00Z', 'people' => [
            $persona('guarda', 5, []),
            $persona('pidio', 7, [['key' => 'human_requested', 'hoursAgo' => 48]]),
            $persona('renovo', 7, [['key' => 'membership_renewed', 'hoursAgo' => 24]]),
            $persona('sindatos', 7, null),
        ]])->assertCreated()->json('data');
        $por = array_column($r['people'], null, 'ref');

        $this->assertSame(['deny', 'N5', ['step_guard_false']], [$por['guarda']['decision']['kind'], $por['guarda']['decision']['level'], $por['guarda']['decision']['reasons']]);
        $this->assertSame(['deny', 'N6'], [$por['pidio']['decision']['kind'], $por['pidio']['decision']['level']]);
        $this->assertContains('human_requested_recently', $por['pidio']['decision']['reasons']);
        $this->assertSame('handoff', $por['pidio']['trace']['policy']['effect']);
        $this->assertSame(['exclude', 'success_reached'], [$por['renovo']['decision']['kind'], $por['renovo']['gate']]);
        $this->assertSame(['exclude', 'insufficient_facts'], [$por['sindatos']['decision']['kind'], $por['sindatos']['gate']]);
        // Y cada frase explica el porqué (los textos que espeja el CRM).
        $this->assertStringContainsString('N5', $por['guarda']['text'][0]);
    }

    public function test_t12_sintetica_valida_los_hechos_contra_el_catalogo_con_todas_las_claves(): void
    {
        [$ana, , $v] = $this->frozenVersion();
        $r = $this->s9('POST', "strategies/versions/{$v->uuid}/simulations", $ana, ['kind' => 'synthetic', 'people' => [
            ['ref' => 'p01', 'facts' => ['membership.no_existe' => 1, 'membership.active' => 'sí', 'membership.days_to_expiry' => 99999]],
            ['ref' => 'P 02', 'facts' => [], 'contacts' => [['hoursAgo' => -3, 'channel' => 'sms', 'purpose' => 'commercial']]],
        ]])->assertStatus(422);
        $this->assertSame('simulation_input_invalid', $r->json('code'));
        foreach (['people.0.facts.membership.no_existe', 'people.0.facts.membership.active', 'people.0.facts.membership.days_to_expiry', 'people.1.ref', 'people.1.contacts.0.hoursAgo'] as $k) {
            $this->assertArrayHasKey($k, $r->json('errors'), json_encode($r->json('errors')));
        }
        $this->assertSame(0, StudioStrategySimulation::query()->count());
    }

    public function test_t12_muestra_real_reproducible_y_sin_datos_personales(): void
    {
        [$ana, , $v] = $this->frozenVersion();
        $this->plan(['name' => 'Mensual']);
        $socios = [];
        foreach (['2026-11-20', '2026-11-22', '2026-12-30'] as $i => $fin) {
            $socios[] = $this->socio(['name' => "Persona Real {$i}", 'membership_end_date' => $fin], ['full_name' => "Persona Real {$i}", 'document_number' => "CC99887{$i}"]);
        }

        $a = $this->s9('POST', "strategies/versions/{$v->uuid}/simulations", $ana, ['kind' => 'sample'])->assertCreated()->json('data');
        $b = $this->s9('POST', "strategies/versions/{$v->uuid}/simulations", $ana, ['kind' => 'sample'])->assertCreated()->json('data');
        $this->assertSame($a['inputHash'], $b['inputHash'], 'la misma foto de hechos');
        $this->assertSame($a['resultHash'], $b['resultHash']);
        $this->assertSame(array_column($a['people'], 'traceHash'), array_column($b['people'], 'traceHash'));

        // Las dos que vencen dentro de 7 días son candidatas; la de diciembre no.
        $this->assertSame(2, $a['source']['candidates']);
        $this->assertCount(2, $a['people']);
        foreach ($a['people'] as $p) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $p['ref'], 'la persona va por su seudónimo');
        }
        $todo = json_encode($a);
        foreach (['Persona Real', 'CC99887', '@facts.test', '"m:'.$socios[0]['member'].'"'] as $personal) {
            $this->assertStringNotContainsString($personal, $todo, "la respuesta lleva {$personal}");
        }
        // Sin autorización comercial registrada, nadie se contacta (N1): es el lado seguro de §1.6.
        foreach ($a['people'] as $p) {
            if ($p['gate'] === 'enroll') {
                $this->assertSame('deny', $p['decision']['kind']);
            }
        }
        // Solo lectura: ni inscripciones ni trabajos.
        $this->assertSame(0, DB::table('outreach_enrollments')->count());
        $this->assertSame(0, DB::table('outreach_jobs')->count());
    }

    public function test_la_muestra_real_exige_su_llave_y_la_sintetica_la_de_editar(): void
    {
        [$ana, , $v] = $this->frozenVersion();
        RolePermission::query()->updateOrCreate(['role' => 'Super Admin', 'permission' => 'commercial_automation.simulate_real'], ['granted' => false]);
        Cache::forget('crm.role_permissions.v1');
        $this->s9('POST', "strategies/versions/{$v->uuid}/simulations", $ana, ['kind' => 'sample'])->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->s9('POST', "strategies/versions/{$v->uuid}/simulations", $ana, ['kind' => 'synthetic', 'people' => $this->syntheticPeople()])->assertCreated();
    }

    public function test_aprobar_exige_una_simulacion_sin_bloqueantes_de_la_misma_version_y_la_del_borrador_no_basta(): void
    {
        [$ana, $beto, $v] = $this->frozenVersion();
        $s = StudioStrategyVersion::query()->with('strategy')->whereKey($v->id)->first()->strategy;
        // La del borrador (misma huella) no habilita aprobar: la obligatoria es la de la versión.
        $this->s9('POST', "strategies/{$s->uuid}/simulations", $ana, ['kind' => 'synthetic', 'people' => $this->syntheticPeople(), 'at' => '2026-11-17T15:00:00Z'])->assertCreated();
        $this->s9('POST', "strategies/versions/{$v->uuid}/approve", $beto, ['acknowledgedWarnings' => $v->validation_json['warningIds']])
            ->assertStatus(409)->assertJsonPath('code', 'simulation_required');
        // Una de la versión con bloqueantes (nadie entra: audiencia vacía) tampoco.
        $vacia = $this->s9('POST', "strategies/versions/{$v->uuid}/simulations", $ana, ['kind' => 'synthetic', 'at' => '2026-11-17T15:00:00Z',
            'people' => [['ref' => 'x1', 'facts' => ['membership.active' => false]]]])->assertCreated()->json('data');
        $this->assertContains('audience_empty', array_column($vacia['findings']['blocking'], 'code'));
        $this->s9('POST', "strategies/versions/{$v->uuid}/approve", $beto, ['acknowledgedWarnings' => $v->validation_json['warningIds']])
            ->assertStatus(409)->assertJsonPath('code', 'simulation_required');
        // Una sin bloqueantes, sí; y la aprobación cita esa simulación.
        $buena = $this->s9('POST', "strategies/versions/{$v->uuid}/simulations", $ana, ['kind' => 'synthetic', 'at' => '2026-11-17T15:00:00Z', 'people' => $this->syntheticPeople()])
            ->assertCreated()->json('data');
        $this->s9('POST', "strategies/versions/{$v->uuid}/approve", $beto, ['acknowledgedWarnings' => $v->validation_json['warningIds']])->assertOk();
        $this->assertSame($buena['uuid'], StudioStrategyVersion::query()->whereKey($v->id)->value('simulation_uuid'));
        // Con la puerta `waived` (solo el propietario), aprobar no la exige.
        $this->strategyFlags(enabled: true, gate: 'waived');
        $this->assertSame('waived', StudioStrategyConfig::simulationGate());
    }
}
