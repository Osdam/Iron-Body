<?php

namespace Tests\Feature\Outreach\Guards;

use App\Models\Admin;
use App\Models\UltronStudio\StudioStrategyVersion;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachStop;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioStrategies;
use App\Services\UltronStudio\StudioStrategySimulations;
use App\Services\UltronStudio\StudioStrategyVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\UltronStudio\Strategies\StrategyFixtures;
use Tests\TestCase;

/**
 * CUATRO OJOS POR PERSONA (contrato H9, P9-4, L9-7, L9-39, T1 y T15; SEC9-a).
 * La regla que importa no es «otra cuenta» sino «otra PERSONA»: dos cuentas
 * vinculadas a la misma identidad son la misma persona para aprobar, publicar
 * y soltar frenos. S9 lo prueba a fondo en `UltronStudioStrategyFourEyesTest`;
 * esta guarda fija el núcleo de la regla en los dos sitios donde vive
 * (`StudioPersonBindings::samePerson` y `OutreachStop::release`) para que un
 * cambio en cualquiera de los dos caiga aquí con su nombre.
 */
class OutreachFourEyesGuardTest extends TestCase
{
    use RefreshDatabase;
    use StrategyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withoutRouteMap();
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: true, publishing: true);
        config(['outreach.enabled' => true, 'outreach.mode' => 'shadow']);
        Carbon::setTestNow($this->weekdayMorning());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_la_segunda_cuenta_de_la_misma_persona_no_aprueba_ni_publica_y_una_tercera_persona_si(): void
    {
        [$ana, $beto, $identidadAna] = $this->bootstrapPair();
        $carla = $this->account(Admin::ROLE_SUPER_ADMIN, 'Carla Tercera');
        $this->bindAccount($carla, $this->identity(), $ana, $beto);
        $ana2 = $this->account(Admin::ROLE_SUPER_ADMIN, 'Ana (segunda cuenta)');
        $this->bindAccount($ana2, $identidadAna, $beto, $carla);

        $t = $this->approvedTemplate($ana, $beto);
        $s = app(StudioStrategies::class)->create(new StudioAccess($ana), Request::create('/'), (string) Str::uuid(), 'renovacion', 'Estrategia renovación', 'commercial', $this->strategyContent($t))['strategy'];
        $v = app(StudioStrategyVersions::class)->freeze(new StudioAccess($ana), Request::create('/'), (string) $s->uuid, (string) Str::uuid())['version'];
        app(StudioStrategySimulations::class)->simulate(new StudioAccess($ana), Request::create('/'), $s, $v, 'synthetic', ['people' => $this->syntheticPeople(), 'at' => '2026-11-17T15:00:00Z']);
        $v = $v->refresh();
        $avisos = $v->validation_json['warningIds'];

        foreach ([$ana, $ana2] as $quien) {
            $this->s9('POST', "strategies/versions/{$v->uuid}/approve", $quien, ['acknowledgedWarnings' => $avisos])->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        }
        $this->assertSame('REVIEW_REQUIRED', StudioStrategyVersion::query()->whereKey($v->id)->value('status'), 'ninguna de las dos cuentas de Ana la movió');
        $this->s9('POST', "strategies/versions/{$v->uuid}/approve", $carla, ['acknowledgedWarnings' => $avisos])->assertOk()->assertJsonPath('data.status', 'APPROVED');

        foreach ([$ana, $ana2] as $quien) {
            $this->s9('POST', "strategies/versions/{$v->uuid}/publish", $quien, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v->content_hash])
                ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        }
        $this->assertSame(0, DB::table('ultron_studio_strategy_publications')->count(), 'nada publicado por la autora ni por su otra cuenta');
        $this->s9('POST', "strategies/versions/{$v->uuid}/publish", $beto, ['clientRequestId' => (string) Str::uuid(), 'expectedContentHash' => $v->content_hash])->assertCreated();

        $fila = StudioStrategyVersion::query()->whereKey($v->id)->first();
        $this->assertNotNull($fila->approved_by_person_id);
        $this->assertNotSame($fila->created_by_person_id, $fila->approved_by_person_id, 'la aprobación guarda dos personas distintas');
    }

    public function test_quien_abre_un_freno_no_lo_suelta_ni_con_otra_cuenta_de_su_misma_persona(): void
    {
        [$ana, $beto, $identidadAna] = $this->bootstrapPair();
        $carla = $this->account(Admin::ROLE_SUPER_ADMIN, 'Carla Tercera');
        $this->bindAccount($carla, $this->identity(), $ana, $beto);
        $ana2 = $this->account(Admin::ROLE_SUPER_ADMIN, 'Ana (segunda cuenta)');
        $this->bindAccount($ana2, $identidadAna, $beto, $carla);

        $stop = app(OutreachStop::class);
        $abierto = $stop->open(OutreachStop::SCOPE_GLOBAL, null, OutreachStop::REASON_MANUAL, 'Parada de la prueba de cuatro ojos', $ana, (string) Str::uuid());
        $uuid = (string) ($abierto['brake']['uuid'] ?? $abierto['uuid'] ?? DB::table('outreach_brakes')->whereNull('released_at')->value('uuid'));
        $this->assertTrue($stop->isGlobalOpen());

        foreach ([$ana, $ana2] as $quien) {
            try {
                $stop->release($quien, $uuid, 'Intento de la persona que lo abrió', (string) Str::uuid());
                $this->fail("{$quien->name} soltó el freno que abrió su misma persona (L9-7).");
            } catch (OutreachException $e) {
                $this->assertSame(403, $e->status, $e->errorCode);
            }
            $this->assertTrue($stop->isGlobalOpen(), 'sigue echado');
        }

        // Un freno del SISTEMA (sin persona que lo abrió) lo suelta cualquier persona con la llave; uno manual, otra persona.
        $stop->release($beto, $uuid, 'Lo suelta otra persona con nota', (string) Str::uuid());
        $this->assertFalse($stop->isGlobalOpen());
        $fila = DB::table('outreach_brakes')->where('uuid', $uuid)->first();
        $this->assertNotNull($fila->released_at);
        $this->assertNotSame((int) $fila->opened_by_person_id, (int) $fila->released_by_person_id, 'personas distintas en la fila');
        $this->assertNotSame((int) $fila->opened_by_admin_id, (int) $fila->released_by_admin_id);

        // Soltar dos veces no vale (una sola vez, 409), ni soltar con otra nota.
        try {
            $stop->release($carla, $uuid, 'Segunda suelta', (string) Str::uuid());
            $this->fail('Un freno se suelta una sola vez.');
        } catch (OutreachException $e) {
            $this->assertSame('brake_already_released', $e->errorCode);
        }
    }

    /** La regla, por dentro: misma cuenta O misma identidad; dos cuentas sin identidad conocida son distintas solo si son cuentas distintas. */
    public function test_la_comparacion_de_personas_es_por_cuenta_o_por_identidad(): void
    {
        $this->assertTrue(OutreachStop::samePerson(1, 10, 1, 10));
        $this->assertTrue(OutreachStop::samePerson(1, 10, 2, 10), 'dos cuentas, una identidad: la misma persona');
        $this->assertTrue(OutreachStop::samePerson(1, null, 1, null), 'la misma cuenta aunque no haya identidad');
        $this->assertFalse(OutreachStop::samePerson(1, 10, 2, 11));
        $this->assertFalse(OutreachStop::samePerson(1, null, 2, null), 'sin identidades, cuentas distintas: no se puede afirmar que sea la misma persona (por eso L9-39 exige persona verificada para gobernar)');
        $this->assertFalse(OutreachStop::samePerson(1, 10, 2, null));
    }
}
