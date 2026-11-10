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
 *  - B8-g, con la referencia oficial (09-11-2026): `assigned_agent` es
 *    OPCIONAL (su ausencia es «sin asignar», como el null) y
 *    `supports_inbound` (obsoleto, por defecto true) da `inboundCapable`: un
 *    número «Outbound Only» (caller ID verificado en Twilio) no admite
 *    entrantes ni agente.
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
        // SEC8-c B-1: el número con el que llama sale SOLO en memoria, para compararlo; ningún volcado lo enseña.
        $this->assertSame('+576010000009', $r->phoneNumber());
        $volcado = (string) json_encode($r->toArray()).json_encode($r).var_export($r, true).print_r($r, true);
        foreach (['+576010000009', '6010000009', 'Recepción', 'Ultron voz', self::CLAVE] as $s) {
            $this->assertStringNotContainsString($s, $volcado, 'del cuerpo solo salen el agente y, en memoria, el número');
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
        // Sin `assigned_agent` (opcional en la referencia oficial): sin asignar, no ilegible (B8-g).
        self::freshFake(['*' => Http::response(['phone_number_id' => 'phnum_0001'], 200)]);
        $this->assertSame(['observed', 'unassigned', null, null], [($r = $d->describeNumber('phnum_0001'))->outcome, $r->state(), $r->agentId, $r->inboundCapable]);

        foreach ([['no es json', 200], [['assigned_agent' => ['agent_id' => '../x']], 200], [['assigned_agent' => 'agent_1'], 200], [['assigned_agent' => []], 200]] as [$cuerpo, $http]) {
            self::freshFake(['*' => Http::response($cuerpo, $http)]);
            $this->assertSame(['error', 'invalid_response'], [$d->describeNumber('phnum_0001')->outcome, $d->describeNumber('phnum_0001')->reason], (string) json_encode($cuerpo));
        }
        self::freshFake(static fn () => throw new ConnectionException('sin conexión'));
        $this->assertSame('unreachable', $d->describeNumber('phnum_0001')->outcome);

        self::freshFake();
        $this->assertSame(['error', 'invalid_request'], [$d->describeNumber('../x')->outcome, $d->describeNumber('../x')->reason]);
        Http::assertNothingSent();
    }

    /**
     * B8-g: el número «Outbound Only» tal como lo describe la referencia
     * oficial de ElevenLabs (un caller ID verificado en Twilio: «Cannot
     * receive calls or be assigned to agents»): sin agente y con
     * `supports_inbound: false`. `supports_inbound` solo cuenta como booleano.
     */
    public function test_un_numero_solo_saliente_se_lee_sin_agente_y_sin_entrantes(): void
    {
        $d = new ElevenLabsAgentDeployer;
        $solo = ['provider' => 'twilio', 'label' => 'Iron Body salientes', 'phone_number' => '+576010000009', 'phone_number_id' => 'phnum_0001',
            'supports_inbound' => false, 'supports_outbound' => true];
        foreach ([$solo, $solo + ['assigned_agent' => null]] as $cuerpo) {
            self::freshFake(['*' => Http::response($cuerpo, 200)]);
            $r = $d->describeNumber('phnum_0001');
            $this->assertSame(['observed', 'unassigned', null, false], [$r->outcome, $r->state(), $r->agentId, $r->inboundCapable], (string) json_encode(array_keys($cuerpo)));
            $this->assertFalse($r->toArray()['inboundCapable']);
            $this->assertStringNotContainsString('6010000009', (string) json_encode($r->toArray()), 'del cuerpo no sale el teléfono');
        }

        // `phone_number` sin un E.164 estricto: no se toma (no se compara).
        foreach (['6010000009', '+57 601 000 0009', 12345678, null] as $raro) {
            self::freshFake(['*' => Http::response(['phone_number_id' => 'phnum_0001', 'phone_number' => $raro], 200)]);
            $this->assertSame(['observed', null], [($r = $d->describeNumber('phnum_0001'))->outcome, $r->phoneNumber()], (string) json_encode($raro));
        }

        // Comprado (admite entrantes) y asignado; y sin el campo (obsoleto): no se sabe.
        self::freshFake(['*' => Http::response(['phone_number_id' => 'phnum_0001', 'supports_inbound' => true, 'assigned_agent' => ['agent_id' => 'agent_canary_0003', 'agent_name' => 'x']], 200)]);
        $this->assertSame([true, 'agent_canary_0003'], [($r = $d->describeNumber('phnum_0001'))->inboundCapable, $r->agentId]);
        foreach ([[], ['supports_inbound' => 'false'], ['supports_inbound' => 0], ['supports_inbound' => null]] as $extra) {
            self::freshFake(['*' => Http::response(['phone_number_id' => 'phnum_0001', 'assigned_agent' => null] + $extra, 200)]);
            $this->assertNull($d->describeNumber('phnum_0001')->inboundCapable, 'solo un booleano dice si admite entrantes: '.json_encode($extra));
        }
        Http::assertSentCount(1);
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
            // B8-g: solo un número observado dice si admite entrantes.
            ['error', 'phnum_1', null, 500, null, false],
            ['not_configured', null, null, null, null, true],
            // SEC8-c B-1: solo un número observado lleva su E.164, y estricto.
            ['error', 'phnum_1', null, 500, null, null, '+576010000009'],
            ['observed', 'phnum_1', null, 200, null, null, '6010000009'],
            ['observed', 'phnum_1', null, 200, null, null, '+57 601 000 0009'],
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
