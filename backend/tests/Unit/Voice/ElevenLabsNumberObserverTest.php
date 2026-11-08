<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Dto\VoiceNumberAssignment;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentDeployer;
use App\Services\Voice\NullVoiceNumberObserver;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Unit\Voice\Support\FreshHttpFake;

/**
 * La lectura del número importado (contrato H8, D8-21; Enmienda 2, E2-3;
 * Enmienda 4, E4-8: `describeNumber`), siempre contra `Http::fake` y con
 * `Http::preventStrayRequests()`.
 *
 *  - Sin la bandera o sin todas las piezas: «desconocido» SIN RED (también el nulo).
 *  - UN GET a `/v1/convai/phone-numbers/{id}`, la clave solo en `xi-api-key`,
 *    host oficial en `production`.
 *  - Del cuerpo solo sale el agente asignado: ni el teléfono ni la etiqueta.
 *  - Cada fallo es un resultado (nunca lanza por la red).
 */
class ElevenLabsNumberObserverTest extends TestCase
{
    use FreshHttpFake;

    private const CLAVE = 'xi-centinela-NUMBER-0123456789';

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

    public function test_sin_la_bandera_o_sin_una_pieza_es_desconocido_sin_red(): void
    {
        self::freshFake();
        foreach ([['enabled' => false], ['api_key' => null], ['agent_id' => null]] as $cambio) {
            $this->configurar($cambio);
            $r = (new ElevenLabsAgentDeployer)->describeNumber('phnum_0001');
            $this->assertSame(['not_configured', 'unknown'], [$r->outcome, $r->state()]);
            $this->assertInstanceOf(NullVoiceNumberObserver::class, VoiceAdapters::numberObserver());
        }
        $this->assertSame(VoiceNumberAssignment::unknown()->toArray(), (new NullVoiceNumberObserver)->describeNumber('phnum_0001')->toArray());
        Http::assertNothingSent();
    }

    public function test_un_get_y_del_cuerpo_solo_el_agente_asignado(): void
    {
        $this->app['env'] = 'production';
        $this->assertInstanceOf(ElevenLabsAgentDeployer::class, VoiceAdapters::numberObserver());
        self::freshFake(['https://api.elevenlabs.io/v1/convai/phone-numbers/phnum_0001' => Http::response([
            'phone_number' => '+576010000009', 'label' => 'Recepción Iron Body', 'phone_number_id' => 'phnum_0001', 'provider' => 'twilio',
            'assigned_agent' => ['agent_id' => 'agent_canary_0003', 'agent_name' => 'Ultron voz canario'],
        ], 200)]);

        $r = (new ElevenLabsAgentDeployer)->describeNumber('phnum_0001');
        $this->assertSame(['observed', 'assigned', 'phnum_0001', 'agent_canary_0003', 200, null], [$r->outcome, $r->state(), $r->phoneNumberId, $r->agentId, $r->httpStatus, $r->reason]);
        Http::assertSentCount(1);
        [$peticion] = Http::recorded()[0];
        $this->assertSame(['GET', 'https://api.elevenlabs.io/v1/convai/phone-numbers/phnum_0001', [self::CLAVE]], [$peticion->method(), $peticion->url(), $peticion->header('xi-api-key')]);
        $volcado = (string) json_encode($r->toArray()).var_export($r, true);
        foreach (['+576010000009', '6010000009', 'Recepción', 'Ultron voz', self::CLAVE] as $s) {
            $this->assertStringNotContainsString($s, $volcado, 'del cuerpo solo sale el agente');
        }
    }

    public function test_sin_asignar_y_cada_fallo_es_un_resultado(): void
    {
        $d = new ElevenLabsAgentDeployer;
        self::freshFake(['*' => Http::response(['phone_number_id' => 'phnum_0001', 'assigned_agent' => null], 200)]);
        $this->assertSame(['observed', 'unassigned', null], [$d->describeNumber('phnum_0001')->outcome, $d->describeNumber('phnum_0001')->state(), $d->describeNumber('phnum_0001')->agentId]);

        foreach ([[404, 'not_found'], [401, 'auth_failed'], [403, 'auth_failed'], [500, 'error'], [302, 'error']] as [$http, $resultado]) {
            self::freshFake(['*' => Http::response(['detail' => 'x'], $http)]);
            $r = $d->describeNumber('phnum_0001');
            $this->assertSame([$resultado, 'unknown', $http], [$r->outcome, $r->state(), $r->httpStatus], "HTTP {$http}");
        }
        foreach ([['no es json', 200], [['phone_number_id' => 'phnum_0001'], 200], [['assigned_agent' => ['agent_id' => '../x']], 200], [['assigned_agent' => 'agent_1'], 200]] as [$cuerpo, $http]) {
            self::freshFake(['*' => Http::response($cuerpo, $http)]);
            $this->assertSame(['error', 'invalid_response'], [$d->describeNumber('phnum_0001')->outcome, $d->describeNumber('phnum_0001')->reason], (string) json_encode($cuerpo));
        }
        self::freshFake(static fn () => throw new ConnectionException('sin conexión'));
        $this->assertSame('unreachable', $d->describeNumber('phnum_0001')->outcome);

        self::freshFake();
        $this->assertSame(['error', 'invalid_request'], [$d->describeNumber('../x')->outcome, $d->describeNumber('../x')->reason]);
        Http::assertNothingSent();
    }

    public function test_el_dto_no_admite_formas_imposibles(): void
    {
        foreach ([
            ['otro', 'phnum_1', null, null, null],
            ['observed', null, 'agent_1', 200, null],
            ['observed', 'phnum_1', 'agent_1', 200, 'invalid_response'],
            ['error', 'phnum_1', 'agent_1', 500, null],
            ['not_configured', 'phnum_1', null, null, null],
            ['observed', 'phnum_1', '../x', 200, null],
            ['error', 'phnum_1', null, 700, null],
        ] as $args) {
            try {
                new VoiceNumberAssignment(...$args);
                $this->fail('tenía que negarse: '.json_encode($args));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
