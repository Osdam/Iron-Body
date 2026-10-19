<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabScenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * La BANDERA y la CONFIGURACIÓN del laboratorio (contrato H4 §A1, §A3 y §A7;
 * Enmienda 3).
 *
 *  - Apagada: `GET lab/status` responde 200 `{enabled:false, message, can}` y
 *    las otras once rutas, 409 `lab_disabled`, sin escribir nada.
 *  - El orden de las cerraduras: quien no tiene permiso recibe 403 aunque la
 *    bandera esté apagada.
 *  - Encendida pero sin configurar para `openai`: el motivo, en el estado, en
 *    la estimación y al crear, y NINGUNA petición HTTP sale.
 *  - Un driver que no es `fake` ni `openai` no abre nada: `mode: null` e
 *    `invalid_driver` (Enmienda 3).
 */
class UltronStudioLabFlagTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** Las once rutas que la bandera apagada cierra (todas menos `GET lab/status`). */
    private const GUARDED = [
        ['GET', 'scenarios'],
        ['GET', 'runs'],
        ['GET', 'runs/{run}'],
        ['POST', 'estimate'],
        ['GET', 'events'],
        ['POST', 'scenarios'],
        ['PATCH', 'scenarios/{scenario}'],
        ['POST', 'scenarios/{scenario}/archive'],
        ['POST', 'runs'],
        ['POST', 'runs/{run}/cancel'],
        ['POST', 'runs/{run}/verdict'],
    ];

    public function test_apagado_el_estado_responde_200_y_las_otras_once_409_sin_escribir_nada(): void
    {
        $this->labOff();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');

        $this->lab('GET', 'status', $root)->assertOk()->assertExactJson(['ok' => true, 'data' => [
            'enabled' => false,
            'message' => 'El laboratorio está apagado en el servidor.',
            'can' => ['view' => true, 'manageScenarios' => true, 'run' => true, 'verdict' => true],
        ]]);

        foreach (self::GUARDED as [$metodo, $ruta]) {
            $this->call409($this->lab($metodo, $this->fill($ruta), $root, $this->body($metodo, $ruta)), "{$metodo} {$ruta}");
        }
        $this->assertSame(0, StudioLabScenario::query()->count());
        $this->assertSame(0, StudioLabRun::query()->count());
        $this->assertSame(0, StudioLabEvent::query()->count());
    }

    public function test_sin_permiso_es_403_aunque_la_bandera_este_apagada(): void
    {
        $this->labOff();

        // Sin nada del Studio, ni el estado.
        $recepcion = $this->person(Admin::ROLE_RECEPCION, 'Recepción');
        $this->lab('GET', 'status', $recepcion)->assertStatus(403)->assertJsonPath('required_permission', 'ultron.view');
        foreach (self::GUARDED as [$metodo, $ruta]) {
            $this->lab($metodo, $this->fill($ruta), $recepcion, $this->body($metodo, $ruta))->assertStatus(403);
        }

        // Con solo `ultron.view`: las lecturas, 409 (la bandera); las escrituras, 403 por su llave.
        $soloVer = $this->adminWithPermissions(['ultron.view']);
        $llave = ['scenarios' => 'ultron.lab_manage', 'runs' => 'ultron.lab_run', 'cancel' => 'ultron.lab_run', 'verdict' => 'ultron.approve', 'archive' => 'ultron.lab_manage'];
        foreach (self::GUARDED as [$metodo, $ruta]) {
            $r = $this->json($metodo, '/api/admin/ultron-studio/lab/'.$this->fill($ruta), $this->body($metodo, $ruta), $soloVer);
            if ($metodo === 'GET' || $ruta === 'estimate') {
                $this->call409($r, "solo ver · {$metodo} {$ruta}");

                continue;
            }
            $ultimo = basename($ruta);
            $esperada = $metodo === 'PATCH' ? 'ultron.lab_manage' : ($llave[$ultimo] ?? $llave[explode('/', $ruta)[0]]);
            $r->assertStatus(403)->assertJsonPath('code', 'forbidden')->assertJsonPath('required_permission', $esperada);
        }
    }

    public function test_encendido_sin_configurar_dice_por_que_y_no_sale_ninguna_peticion(): void
    {
        Http::fake();
        Http::preventStrayRequests();
        $this->importLabScenarios();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        config(['ultron_studio.lab.enabled' => true, 'ultron_studio.lab.driver' => 'openai', 'ultron_studio.lab.openai.api_key' => null,
            'ultron_studio.lab.openai.model' => null, 'ultron_studio.lab.price_input_usd_per_mtok' => null, 'ultron_studio.lab.price_output_usd_per_mtok' => null,
            'ultron_studio.lab.daily_budget_usd' => '0']);

        $estado = $this->lab('GET', 'status', $root)->assertOk()->json('data');
        $this->assertTrue($estado['enabled']);
        $this->assertSame('openai', $estado['mode']);
        $this->assertFalse($estado['configured']);
        $this->assertSame(['missing_key', 'missing_model', 'missing_price', 'zero_budget'], $estado['reasons']);

        $cuerpo = ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01')]];
        $estimacion = $this->lab('POST', 'estimate', $root, $cuerpo)->assertOk()->json('data');
        $this->assertFalse($estimacion['allowed']);
        $this->assertSame('lab_not_configured', $estimacion['refusal']['code']);

        $this->lab('POST', 'runs', $root, $cuerpo + ['acceptedMaxCostMicros' => 0, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)
            ->assertJsonPath('code', 'lab_not_configured')
            ->assertJsonPath('reasons', ['missing_key', 'missing_model', 'missing_price', 'zero_budget'])
            ->assertJsonPath('mode', 'openai');
        $this->assertSame(0, StudioLabRun::query()->count());
        Http::assertNothingSent();
    }

    public function test_la_clave_del_motor_no_vale_para_el_laboratorio(): void
    {
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->labOpenAi(['ultron_studio.lab.openai.api_key' => self::ENGINE_KEY]);

        $estado = $this->lab('GET', 'status', $root)->assertOk()->json('data');
        $this->assertFalse($estado['configured']);
        $this->assertSame(['shared_key'], $estado['reasons']);
    }

    public function test_un_driver_que_no_es_fake_ni_openai_no_abre_nada(): void
    {
        Http::fake();
        $this->importLabScenarios();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->labOpenAi(['ultron_studio.lab.driver' => 'opneai']);

        $estado = $this->lab('GET', 'status', $root)->assertOk()->json('data');
        $this->assertNull($estado['mode']);
        $this->assertFalse($estado['configured']);
        $this->assertSame(['invalid_driver'], $estado['reasons']);
        $this->assertNull($estado['model']);

        $cuerpo = ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01')]];
        $estimacion = $this->lab('POST', 'estimate', $root, $cuerpo)->assertOk()->json('data');
        $this->assertNull($estimacion['mode']);
        $this->assertSame('lab_not_configured', $estimacion['refusal']['code']);
        $this->lab('POST', 'runs', $root, $cuerpo + ['acceptedMaxCostMicros' => 0, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)
            ->assertJsonPath('code', 'lab_not_configured')
            ->assertJsonPath('reasons', ['invalid_driver'])
            ->assertJsonPath('mode', null);
        Http::assertNothingSent();
    }

    public function test_presupuesto_cero_cierra_openai_y_deja_el_ensayo_sin_ia(): void
    {
        $this->importLabScenarios();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $cuerpo = ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01')]];

        $this->labOpenAi(['ultron_studio.lab.daily_budget_usd' => '0']);
        $this->assertSame(['zero_budget'], $this->lab('GET', 'status', $root)->json('data.reasons'));
        $this->lab('POST', 'runs', $root, $cuerpo + ['acceptedMaxCostMicros' => 1000000, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'lab_not_configured');

        // En `fake` no hay nada que pagar: el presupuesto 0 no lo para.
        $this->labFake(['ultron_studio.lab.daily_budget_usd' => '0']);
        $this->lab('POST', 'runs', $root, $cuerpo + ['acceptedMaxCostMicros' => 0, 'clientRequestId' => (string) Str::uuid()])->assertCreated();
    }

    // ────────────────────────────────────────────────────────────────────────

    private function call409(TestResponse $r, string $donde): void
    {
        $this->assertSame(409, $r->status(), "{$donde}: ".$r->getContent());
        $this->assertSame(['ok' => false, 'code' => 'lab_disabled', 'message' => 'El laboratorio está apagado en el servidor.'], $r->json(), $donde);
    }

    private function fill(string $ruta): string
    {
        return str_replace(['{run}', '{scenario}'], [(string) Str::uuid(), (string) Str::uuid()], $ruta);
    }

    /** @return array<string, mixed> */
    private function body(string $metodo, string $ruta): array
    {
        return match (true) {
            $metodo === 'POST' && $ruta === 'scenarios' => $this->scenarioBody(),
            $metodo === 'POST' && $ruta === 'estimate' => ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [(string) Str::uuid()]],
            $metodo === 'POST' && $ruta === 'runs' => ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [(string) Str::uuid()], 'acceptedMaxCostMicros' => 0, 'clientRequestId' => (string) Str::uuid()],
            default => [],
        };
    }
}
