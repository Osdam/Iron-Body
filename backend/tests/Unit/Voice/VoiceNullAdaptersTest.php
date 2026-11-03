<?php

namespace Tests\Unit\Voice;

use App\Services\Twilio\TwilioVoiceCarrier;
use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentDeployer;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentMapper;
use App\Services\Voice\ElevenLabs\ElevenLabsCallStarter;
use App\Services\Voice\ElevenLabs\ElevenLabsEventVerifier;
use App\Services\Voice\ElevenLabs\ElevenLabsLabSessionIssuer;
use App\Services\Voice\NullTelephonyCarrier;
use App\Services\Voice\NullVoiceAgentDeployer;
use App\Services\Voice\NullVoiceCallStarter;
use App\Services\Voice\NullVoiceEventVerifier;
use App\Services\Voice\NullVoiceLabSessionIssuer;
use App\Services\Voice\VoiceAdapters;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Voice\Support\VoiceSpecSamples as M;

/**
 * Los nulos de las interfaces de D8-3 y la fábrica (contrato H8). Por defecto
 * todo es nulo y nada sale a la red; cada nulo responde su «no configurado».
 */
class VoiceNullAdaptersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    public function test_los_nulos_no_hablan_con_nadie(): void
    {
        $peticion = new OutboundCallRequest('agent_1', 'phnum_1', '+573000000001', '9a7c1e2b-3d4f-4a5b-8c6d-7e8f9a0b1c2d',
            ['call_direction' => 'saliente', 'call_ref' => '9a7c1e2b-3d4f-4a5b-8c6d-7e8f9a0b1c2d', 'caller_authorized' => 'si', 'version_label' => 'v1'], 30, 'hang_up');

        $this->assertSame('not_configured', (new NullVoiceAgentDeployer)->createAgent(ElevenLabsAgentMapper::payload(M::voiceSpec(), M::target()))->outcome);
        $this->assertSame('not_configured', (new NullVoiceAgentDeployer)->describeAgent('agent_1')->outcome);
        $this->assertSame('not_configured', (new NullVoiceAgentDeployer)->assignNumber('phnum_1', null)->outcome);
        $this->assertSame(['not_attempted', 'not_configured'], [(new NullVoiceCallStarter)->startOutbound($peticion)->outcome, (new NullVoiceCallStarter)->startOutbound($peticion)->reason]);
        $this->assertSame(['not_configured', false], [(new NullVoiceEventVerifier)->verifyPostCall('{}', 't=1,v0='.str_repeat('a', 64), 1)->outcome, (new NullVoiceEventVerifier)->verifyPostCall('{}', null, 1)->accepted()]);
        $this->assertSame('not_configured', (new NullVoiceLabSessionIssuer)->signedSessionUrl('agent_1')->outcome);
        $this->assertSame('not_configured', (new NullTelephonyCarrier)->hangup('CA'.str_repeat('a', 32))->outcome);
        $this->assertSame('not_configured', (new NullTelephonyCarrier)->listCalls(new DateTimeImmutable, null)->outcome);
    }

    public function test_por_defecto_la_fabrica_da_los_nulos(): void
    {
        // La configuración cargada de la suite (sin piezas): la de un despliegue sin voz.
        $this->assertInstanceOf(NullVoiceAgentDeployer::class, VoiceAdapters::deployer());
        $this->assertInstanceOf(NullVoiceCallStarter::class, VoiceAdapters::callStarter());
        $this->assertInstanceOf(NullVoiceEventVerifier::class, VoiceAdapters::eventVerifier());
        $this->assertInstanceOf(NullVoiceLabSessionIssuer::class, VoiceAdapters::labSessionIssuer());
        $this->assertInstanceOf(NullTelephonyCarrier::class, VoiceAdapters::carrier());
    }

    public function test_con_las_piezas_la_fabrica_da_los_adaptadores(): void
    {
        config(['voice.elevenlabs' => [
            'enabled' => true, 'api_key' => 'xi-centinela', 'agent_id' => 'agent_verify', 'webhook_secret' => 'whsec', 'region' => 'default',
            'agent_phone_number_id' => null, 'tool_secret' => null, 'initiation_secret' => null, 'base_url' => null, 'timeout_seconds' => 10,
        ]]);
        config(['voice.twilio.account_sid' => 'AC'.str_repeat('0', 32), 'voice.twilio.api_key_sid' => 'SK'.str_repeat('0', 32), 'voice.twilio.api_key_secret' => 's']);

        $this->assertInstanceOf(ElevenLabsAgentDeployer::class, VoiceAdapters::deployer());
        $this->assertInstanceOf(ElevenLabsCallStarter::class, VoiceAdapters::callStarter(), 'el adaptador; la constante del código lo sigue cerrando');
        $this->assertInstanceOf(ElevenLabsEventVerifier::class, VoiceAdapters::eventVerifier());
        $this->assertInstanceOf(ElevenLabsLabSessionIssuer::class, VoiceAdapters::labSessionIssuer());
        $this->assertInstanceOf(TwilioVoiceCarrier::class, VoiceAdapters::carrier(), 'cortar y conciliar no miran TWILIO_VOICE_ENABLED');

        // Con la bandera apagada (ELEVENLABS_DISABLED), nulos; el verificador
        // sigue con el secreto: comprobar un evento no necesita la bandera ni la red.
        config(['voice.elevenlabs.enabled' => false]);
        $this->assertInstanceOf(NullVoiceAgentDeployer::class, VoiceAdapters::deployer());
        $this->assertInstanceOf(NullVoiceCallStarter::class, VoiceAdapters::callStarter());
        $this->assertInstanceOf(NullVoiceLabSessionIssuer::class, VoiceAdapters::labSessionIssuer());
        $this->assertInstanceOf(ElevenLabsEventVerifier::class, VoiceAdapters::eventVerifier());
    }
}
