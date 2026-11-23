<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentDeployer;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentMapper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Voice\Support\FreshHttpFake;
use Tests\Unit\Voice\Support\VoiceSpecSamples as M;

/**
 * LA LECTURA DEL AGENTE CON UN PLAZO TOTAL (Enmienda del 22-11-2026, E19-2;
 * H-2 del informe `informe-be-voz.md`): el job de la saliente lee la deriva
 * con lo que le queda antes de tener que marcar. Contra `Http::fake`, con la
 * red cerrada y un RELOJ MONÓTONO FALSO que el proveedor falso adelanta: un
 * proveedor lento sin esperar de verdad.
 *
 * LO QUE SE PROTEGE.
 *  - Cada GET espera, como mucho, lo que queda del plazo total (y nunca más
 *    que el de la configuración): aunque cada uno agote su espera, la lectura
 *    acaba dentro del plazo.
 *  - Ninguno empieza con menos de `MIN_REQUEST_SECONDS` por delante: entonces
 *    `unreachable` con motivo `deadline`, sin esa petición.
 *  - Sin plazo, como siempre: el de la configuración en cada petición.
 */
class ElevenLabsAgentDeployerDeadlineTest extends TestCase
{
    use FreshHttpFake;

    private const SIM = 'http://127.0.0.1:8096';

    private const AGENTE = 'agent_obs_0001';

    private float $reloj = 1000.0;

    /** @var list<array{inicio: float, timeout: float|int, connect: float|int, url: string}> */
    private array $peticiones = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['voice.elevenlabs' => [
            'enabled' => true, 'api_key' => 'xi-centinela-PLAZO-0123456789', 'agent_id' => 'agent_verify_0001', 'webhook_secret' => 'whsec-centinela',
            'region' => 'default', 'agent_phone_number_id' => 'phnum_0001', 'tool_secret' => 'tool-centinela', 'initiation_secret' => 'init-centinela',
            'base_url' => self::SIM, 'timeout_seconds' => 7,
        ]]);
    }

    public function test_con_un_proveedor_lento_cada_get_cabe_en_lo_que_queda_y_se_corta_antes_de_pasarse(): void
    {
        $this->proveedor(segundosPorPeticion: 2.5);
        $o = $this->desplegador()->describeAgent(self::AGENTE, 6.0);

        $this->assertSame([VoiceAgentObservation::OUTCOME_UNREACHABLE, VoiceAgentObservation::REASON_DEADLINE], [$o->outcome, $o->reason]);
        $this->assertNull($o->projectionHash, 'sin observación, ninguna proyección');
        // El agente con 6 s (menos que los 7 de la configuración), la 1.ª herramienta con 3,5 y la 2.ª con 1; la 3.ª, con
        // −1,5 por delante, no sale.
        $this->assertSame([6.0, 3.5, 1.0], array_map(static fn (array $p): float => (float) $p['timeout'], $this->peticiones));
        $this->assertCount(3, Http::recorded(), 'de las cinco del agente con sus cuatro herramientas, solo tres');
        foreach ($this->peticiones as $p) {
            $this->assertLessThanOrEqual(1006.0, $p['inicio'] + $p['timeout'], 'aunque agote su espera, acaba dentro del plazo total');
            $this->assertLessThanOrEqual(min(ElevenLabsAgentDeployer::CONNECT_TIMEOUT_SECONDS, $p['timeout']), $p['connect']);
        }
    }

    public function test_con_un_proveedor_sano_la_misma_lectura_que_sin_plazo(): void
    {
        $this->proveedor(segundosPorPeticion: 0.2);
        $o = $this->desplegador()->describeAgent(self::AGENTE, 6.0);
        $this->assertSame(VoiceAgentObservation::OUTCOME_OBSERVED, $o->outcome);
        $this->assertSame(self::carga()->projectionHash, $o->projectionHash, 'sin deriva');
        $this->assertCount(5, Http::recorded());

        // Sin plazo: los 7 s de la configuración en cada una, como antes de E19-2.
        $this->peticiones = [];
        $this->proveedor(segundosPorPeticion: 2.5);
        $sinPlazo = $this->desplegador()->describeAgent(self::AGENTE);
        $this->assertSame(VoiceAgentObservation::OUTCOME_OBSERVED, $sinPlazo->outcome, 'sin plazo, un proveedor lento no corta la lectura');
        $this->assertSame([7, 7, 7, 7, 7], array_column($this->peticiones, 'timeout'));
    }

    public function test_sin_el_minimo_por_delante_no_sale_ninguna_peticion(): void
    {
        $this->proveedor(segundosPorPeticion: 0.2);
        foreach ([0.0, 0.5, ElevenLabsAgentDeployer::MIN_REQUEST_SECONDS - 0.001] as $plazo) {
            $o = $this->desplegador()->describeAgent(self::AGENTE, $plazo);
            $this->assertSame([VoiceAgentObservation::OUTCOME_UNREACHABLE, VoiceAgentObservation::REASON_DEADLINE], [$o->outcome, $o->reason], "plazo {$plazo}");
        }
        Http::assertNothingSent();
    }

    private function desplegador(): ElevenLabsAgentDeployer
    {
        return new ElevenLabsAgentDeployer(fn (): float => $this->reloj);
    }

    private static function carga(): VoiceAgentPayload
    {
        return ElevenLabsAgentMapper::payload(M::voiceSpec(), M::target());
    }

    /** El agente sin tocar y sus cuatro herramientas; cada petición «tarda» `$segundosPorPeticion` en el reloj falso. */
    private function proveedor(float $segundosPorPeticion): void
    {
        $carga = self::carga();
        $herramientas = $carga->tools();
        $ids = ['tool_a' => 'consultar_horarios', 'tool_b' => 'consultar_planes', 'tool_c' => 'no_volver_a_llamar', 'tool_d' => 'solicitar_humano'];
        $agente = $carga->agentBody();
        $agente['agent_id'] = self::AGENTE;
        $agente['conversation_config']['agent']['prompt']['tool_ids'] = array_keys($ids);
        $agente['conversation_config']['agent']['prompt']['temperature'] = 0.0;
        $agente['conversation_config']['agent']['prompt']['built_in_tools']['skip_turn'] = null;

        self::freshFake(function (Request $r, array $opciones) use ($agente, $herramientas, $ids, $segundosPorPeticion) {
            $this->peticiones[] = ['inicio' => $this->reloj, 'timeout' => $opciones['timeout'], 'connect' => $opciones['connect_timeout'], 'url' => $r->url()];
            $this->reloj += $segundosPorPeticion;
            if ($r->url() === self::SIM.'/v1/convai/agents/'.self::AGENTE) {
                return Http::response($agente, 200);
            }
            foreach ($ids as $id => $nombre) {
                if ($r->url() === self::SIM.'/v1/convai/tools/'.$id) {
                    return Http::response(['id' => $id, 'tool_config' => $herramientas[$nombre] + ['assignments' => []], 'access_info' => []], 200);
                }
            }

            return Http::response('no', 404);
        });
    }
}
