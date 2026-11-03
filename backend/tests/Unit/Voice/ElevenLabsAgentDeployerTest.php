<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentDeployer;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentMapper;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Voice\Support\FreshHttpFake;
use Tests\Unit\Voice\Support\VoiceSpecSamples as M;

/**
 * El desplegador de ElevenLabs (contrato H8, D8-3 y D8-7), siempre contra
 * `Http::fake` y con `Http::preventStrayRequests()`.
 *
 * LO QUE SE PROTEGE.
 *  - Sin la bandera o sin todas las piezas NO SALE NADA, aunque se instancie a mano.
 *  - En `production`, solo el host oficial aunque haya base del simulador.
 *  - Crear = las herramientas primero y el agente con sus ids; nunca un PATCH
 *    ni un DELETE de un agente; la clave solo en la cabecera `xi-api-key`.
 *  - Un fallo a mitad dice en qué paso y qué herramientas quedaron (huérfanas).
 *  - Leer = el agente y sus herramientas (como mucho las del catálogo), y la
 *    proyección observada casa con la compilada si nadie tocó nada.
 *  - Asignar un agente al número abre las entrantes: con las llamadas
 *    cerradas en el código, `not_attempted` sin red. Dejarlo sin agente, sí.
 *  - Ni la clave ni un cuerpo salen en un resultado.
 */
class ElevenLabsAgentDeployerTest extends TestCase
{
    use FreshHttpFake;

    private const CLAVE = 'xi-centinela-DEPLOY-0123456789';

    private const SIM = 'http://127.0.0.1:8096';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configurar();
    }

    /** @param  array<string, mixed>  $cambios */
    private function configurar(array $cambios = []): void
    {
        config(['voice.elevenlabs' => $cambios + [
            'enabled' => true, 'api_key' => self::CLAVE, 'agent_id' => 'agent_verify_0001', 'webhook_secret' => 'whsec-centinela',
            'region' => 'default', 'agent_phone_number_id' => 'phnum_0001', 'tool_secret' => 'tool-centinela', 'initiation_secret' => 'init-centinela',
            'base_url' => self::SIM, 'timeout_seconds' => 7,
        ]]);
    }

    private static function carga(): VoiceAgentPayload
    {
        return ElevenLabsAgentMapper::payload(M::voiceSpec(), M::target());
    }

    public function test_sin_la_bandera_o_sin_una_pieza_no_sale_nada(): void
    {
        self::freshFake();
        $carga = self::carga();
        foreach ([['enabled' => false], ['api_key' => ''], ['agent_id' => null], ['webhook_secret' => null], ['region' => 'mars']] as $cambio) {
            $this->configurar($cambio);
            $d = new ElevenLabsAgentDeployer;
            $this->assertSame('not_configured', $d->createAgent($carga)->outcome);
            $this->assertSame('not_configured', $d->describeAgent('agent_0001')->outcome);
            $this->assertSame('not_configured', $d->assignNumber('phnum_0001', null)->outcome);
        }
        Http::assertNothingSent();
    }

    public function test_crea_las_herramientas_y_despues_el_agente_con_sus_ids(): void
    {
        $this->app['env'] = 'production';
        $n = 0;
        self::freshFake(static function (Request $r) use (&$n) {
            if ($r->url() === 'https://api.elevenlabs.io/v1/convai/tools') {
                $n++;

                return Http::response(['id' => 'tool_'.$n, 'tool_config' => $r->data()['tool_config']], 200);
            }
            if ($r->url() === 'https://api.elevenlabs.io/v1/convai/agents/create') {
                return Http::response(['agent_id' => 'agent_new_0001'], 200);
            }

            return Http::response('no', 418);
        });

        $r = (new ElevenLabsAgentDeployer)->createAgent(self::carga());

        $this->assertSame('created', $r->outcome);
        $this->assertSame('agent_new_0001', $r->agentId);
        $this->assertSame(['consultar_horarios' => 'tool_1', 'consultar_planes' => 'tool_2', 'no_volver_a_llamar' => 'tool_3', 'solicitar_humano' => 'tool_4'], $r->toolIds);
        $this->assertNull($r->reason);

        $enviadas = Http::recorded();
        $this->assertCount(5, $enviadas);
        foreach ($enviadas as $i => [$peticion]) {
            $this->assertSame('POST', $peticion->method());
            $this->assertSame([self::CLAVE], $peticion->header('xi-api-key'));
            $this->assertSame('api.elevenlabs.io', parse_url($peticion->url(), PHP_URL_HOST), 'en production, el host oficial aunque haya simulador');
            $this->assertStringNotContainsString(self::CLAVE, $peticion->body(), 'la clave solo va en la cabecera');
            if ($i < 4) {
                $this->assertSame(['tool_config'], array_keys($peticion->data()));
            }
        }
        $agente = $enviadas[4][0]->data();
        $this->assertSame(['tool_1', 'tool_2', 'tool_3', 'tool_4'], $agente['conversation_config']['agent']['prompt']['tool_ids']);
        $this->assertFalse($agente['platform_settings']['call_limits']['bursting_enabled']);
        $this->assertStringNotContainsString(self::CLAVE, (string) json_encode($r->toArray()));
    }

    public function test_fuera_de_produccion_va_al_simulador(): void
    {
        self::freshFake([self::SIM.'/v1/convai/tools' => Http::response(['id' => 'tool_x'], 200), self::SIM.'/v1/convai/agents/create' => Http::response(['agent_id' => 'agent_sim'], 200)]);
        $this->assertSame('created', (new ElevenLabsAgentDeployer)->createAgent(self::carga())->outcome);
        foreach (Http::recorded() as [$peticion]) {
            $this->assertStringStartsWith(self::SIM.'/', $peticion->url());
        }
    }

    public function test_un_fallo_a_mitad_dice_el_paso_y_lo_que_quedo(): void
    {
        // La segunda herramienta da 500: ambigua, con la primera ya creada.
        $n = 0;
        self::freshFake(static function (Request $r) use (&$n) {
            $n++;

            return $n === 1 ? Http::response(['id' => 'tool_1'], 200) : Http::response(['detail' => 'boom'], 500);
        });
        $r = (new ElevenLabsAgentDeployer)->createAgent(self::carga());
        $this->assertSame(['ambiguous', 'tool_step', ['consultar_horarios' => 'tool_1'], 500, null], [$r->outcome, $r->reason, $r->toolIds, $r->httpStatus, $r->agentId]);
        Http::assertSentCount(2);

        // El agente da 422: rechazado en su paso, con las cuatro herramientas.
        self::freshFake(static fn (Request $r) => str_ends_with($r->url(), '/tools') ? Http::response(['id' => 'tool_ok'], 200) : Http::response(['detail' => [['msg' => 'x']]], 422));
        $r = (new ElevenLabsAgentDeployer)->createAgent(self::carga());
        $this->assertSame(['rejected', 'agent_step', 422], [$r->outcome, $r->reason, $r->httpStatus]);
        $this->assertCount(4, $r->toolIds);

        // Un 200 sin agent_id: puede existir → ambiguo.
        self::freshFake(static fn (Request $r) => str_ends_with($r->url(), '/tools') ? Http::response(['id' => 'tool_ok'], 200) : Http::response(['ok' => true], 200));
        $this->assertSame('ambiguous', (new ElevenLabsAgentDeployer)->createAgent(self::carga())->outcome);

        // Sin conexión al crear el agente: ambiguo.
        self::freshFake(static fn (Request $r) => str_ends_with($r->url(), '/tools')
            ? Http::response(['id' => 'tool_ok'], 200)
            : Create::rejectionFor(new ConnectException('sin red', new Psr7Request('POST', $r->url()))));
        $r = (new ElevenLabsAgentDeployer)->createAgent(self::carga());
        $this->assertSame(['ambiguous', 'agent_step', null], [$r->outcome, $r->reason, $r->httpStatus]);

        // 401 en la primera herramienta: credencial mala, sin seguir.
        self::freshFake(['*' => Http::response(['detail' => 'nope'], 401)]);
        $r = (new ElevenLabsAgentDeployer)->createAgent(self::carga());
        $this->assertSame(['auth_failed', 'tool_step', []], [$r->outcome, $r->reason, $r->toolIds]);
        Http::assertSentCount(1);
    }

    public function test_leer_el_agente_sin_tocar_da_la_proyeccion_compilada(): void
    {
        $carga = self::carga();
        $herramientas = $carga->tools();
        $ids = ['tool_a' => 'consultar_horarios', 'tool_b' => 'consultar_planes', 'tool_c' => 'no_volver_a_llamar', 'tool_d' => 'solicitar_humano'];
        $agente = $carga->agentBody();
        $agente['agent_id'] = 'agent_obs_0001';
        $agente['version_id'] = 'agtvrsn_0001';
        $agente['branch_id'] = 'agtbrch_0001';
        $agente['conversation_config']['agent']['prompt']['tool_ids'] = array_keys($ids);
        $agente['conversation_config']['agent']['prompt']['temperature'] = 0.0;
        $agente['conversation_config']['agent']['prompt']['built_in_tools']['skip_turn'] = null;

        self::freshFake(static function (Request $r) use ($agente, $herramientas, $ids) {
            if ($r->url() === self::SIM.'/v1/convai/agents/agent_obs_0001') {
                return Http::response($agente, 200);
            }
            foreach ($ids as $id => $nombre) {
                if ($r->url() === self::SIM.'/v1/convai/tools/'.$id) {
                    return Http::response(['id' => $id, 'tool_config' => $herramientas[$nombre] + ['assignments' => []], 'access_info' => []], 200);
                }
            }

            return Http::response('no', 404);
        });

        $o = (new ElevenLabsAgentDeployer)->describeAgent('agent_obs_0001');
        $this->assertSame('observed', $o->outcome);
        $this->assertSame(['agtvrsn_0001', 'agtbrch_0001'], [$o->versionId, $o->branchId]);
        $this->assertTrue($o->matches($carga), 'sin deriva: la misma proyección');
        $this->assertSame($carga->projectionHash, $o->projectionHash);
        Http::assertSentCount(5);
        foreach (Http::recorded() as [$peticion]) {
            $this->assertSame('GET', $peticion->method());
        }
        $this->assertArrayNotHasKey('projection', $o->toArray());

        // Alguien enciende la ráfaga en el panel: deriva.
        $agente['platform_settings']['call_limits']['bursting_enabled'] = true;
        self::freshFake(static fn (Request $r) => str_contains($r->url(), '/agents/') ? Http::response($agente, 200)
            : Http::response(['id' => 'x', 'tool_config' => $herramientas[$ids[basename($r->url())]]], 200));
        $this->assertFalse((new ElevenLabsAgentDeployer)->describeAgent('agent_obs_0001')->matches($carga));
    }

    public function test_leer_fallos(): void
    {
        $d = new ElevenLabsAgentDeployer;

        self::freshFake(['*' => Http::response(['detail' => 'no'], 404)]);
        $this->assertSame(['not_found', 404], [$d->describeAgent('agent_x')->outcome, $d->describeAgent('agent_x')->httpStatus]);

        self::freshFake(['*' => Http::response(['detail' => 'no'], 403)]);
        $this->assertSame('auth_failed', $d->describeAgent('agent_x')->outcome);

        self::freshFake(['*' => Http::response('no es json', 200)]);
        $this->assertSame(['error', 'invalid_response'], [$d->describeAgent('agent_x')->outcome, $d->describeAgent('agent_x')->reason]);

        // Más herramientas de las del catálogo: deriva por sí misma, sin leerlas.
        self::freshFake(['*' => Http::response(['agent_id' => 'agent_x', 'conversation_config' => ['agent' => ['prompt' => ['tool_ids' => ['t1', 't2', 't3', 't4', 't5']]]]], 200)]);
        $o = $d->describeAgent('agent_x');
        $this->assertSame(['error', 'too_many_tools'], [$o->outcome, $o->reason]);
        Http::assertSentCount(1);

        // Un id sin forma no sale a la red.
        self::freshFake();
        $this->assertSame(['error', 'invalid_request'], [$d->describeAgent("agent_x\n")->outcome, $d->describeAgent("agent_x\n")->reason]);
        $this->assertSame('error', $d->describeAgent('../agents')->outcome);
        Http::assertNothingSent();
    }

    public function test_asignar_un_agente_esta_cerrado_en_el_codigo_y_quitarlo_no(): void
    {
        self::freshFake(['*' => Http::response(['phone_number_id' => 'phnum_0001', 'assigned_agent' => null, 'phone_number' => '+576010000009'], 200)]);
        $d = new ElevenLabsAgentDeployer;

        $r = $d->assignNumber('phnum_0001', 'agent_new_0001');
        $this->assertSame(['not_attempted', 'calls_disabled_in_code'], [$r->outcome, $r->reason]);
        Http::assertNothingSent();

        $r = $d->assignNumber('phnum_0001', null);
        $this->assertSame(['assigned', null, null], [$r->outcome, $r->agentId, $r->reason]);
        Http::assertSentCount(1);
        [$peticion] = Http::recorded()[0];
        $this->assertSame('PATCH', $peticion->method());
        $this->assertSame(self::SIM.'/v1/convai/phone-numbers/phnum_0001', $peticion->url());
        $this->assertSame(['agent_id' => null], $peticion->data());
        $this->assertStringNotContainsString('+576010000009', (string) json_encode($r->toArray()), 'el número del cuerpo no sale');

        // El número sigue con un agente: no casa.
        self::freshFake(['*' => Http::response(['phone_number_id' => 'phnum_0001', 'assigned_agent' => ['agent_id' => 'agent_otro', 'agent_name' => 'x']], 200)]);
        $r = $d->assignNumber('phnum_0001', null);
        $this->assertSame(['error', 'assignment_mismatch', 'agent_otro'], [$r->outcome, $r->reason, $r->agentId]);

        self::freshFake(['*' => Http::response('no es json', 200)]);
        $this->assertSame(['error', 'invalid_response'], [$d->assignNumber('phnum_0001', null)->outcome, $d->assignNumber('phnum_0001', null)->reason]);
        self::freshFake(['*' => Http::response(['detail' => 'x'], 503)]);
        $this->assertSame('ambiguous', $d->assignNumber('phnum_0001', null)->outcome);
        self::freshFake(['*' => Http::response(['detail' => 'x'], 422)]);
        $this->assertSame('rejected', $d->assignNumber('phnum_0001', null)->outcome);

        self::freshFake();
        $this->assertSame(['not_attempted', 'invalid_request'], [$d->assignNumber('../x', null)->outcome, $d->assignNumber('../x', null)->reason]);
        Http::assertNothingSent();
    }

    public function test_no_hay_ningun_verbo_que_modifique_o_borre_un_agente(): void
    {
        $codigo = (string) file_get_contents(base_path('app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php'));
        $this->assertSame(1, preg_match_all('/->patch\(/', $codigo), 'un solo PATCH: el del número');
        $this->assertStringContainsString('->patch($base.self::PHONE_NUMBERS_PATH', $codigo);
        $this->assertSame(0, preg_match_all('/->(delete|put)\(/', $codigo));
    }
}
