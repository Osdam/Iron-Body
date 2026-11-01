<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\ElevenLabs\ElevenLabsConfig;
use App\Services\Voice\ElevenLabs\ElevenLabsVoiceProvider;
use App\Services\Voice\NullVoiceProvider;
use App\Services\Voice\VoiceProviders;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La fábrica y el proveedor nulo (contrato H6 §I6.1).
 *
 * LO QUE SE PROTEGE. Que el adaptador de ElevenLabs solo sale con la bandera
 * encendida Y todas las piezas (exactamente `ELEVENLABS_UNVERIFIED`); que en
 * cualquier otro caso es el proveedor nulo, que verifica `not_configured` sin
 * red; que `status()` es siempre el de la configuración; y que
 * `status()->provider === current()->id()`.
 */
class VoiceProvidersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private static function completo(array $cambios = []): array
    {
        return $cambios + [
            'enabled' => false, 'api_key' => 'xi-centinela-API', 'agent_id' => 'agent_1', 'webhook_secret' => 'whsec-centinela',
            'region' => 'default', 'agent_phone_number_id' => null, 'tool_secret' => null, 'base_url' => null, 'timeout_seconds' => 10,
        ];
    }

    public function test_por_defecto_el_proveedor_nulo_y_sin_red(): void
    {
        Http::fake();

        $p = VoiceProviders::current();

        $this->assertInstanceOf(NullVoiceProvider::class, $p);
        $this->assertSame('none', $p->id());
        $this->assertSame('ELEVENLABS_NOT_CONFIGURED', VoiceProviders::status()->state);
        $this->assertSame(['outcome' => 'not_configured', 'httpStatus' => null, 'agentAudioUlaw8000' => null, 'latencyMs' => 0], $p->verifyCredentials()->toArray());
        $this->assertEquals(VoiceProviders::status(), $p->status());
        Http::assertNothingSent();
    }

    public function test_solo_con_la_bandera_y_todas_las_piezas_sale_el_adaptador(): void
    {
        $casos = [
            'completo y apagado' => [self::completo(), NullVoiceProvider::class],
            'completo y encendido' => [self::completo(['enabled' => true]), ElevenLabsVoiceProvider::class],
            'encendido sin clave' => [self::completo(['enabled' => true, 'api_key' => '']), NullVoiceProvider::class],
            'encendido sin agente válido' => [self::completo(['enabled' => true, 'agent_id' => 'a b']), NullVoiceProvider::class],
            'encendido sin secreto del webhook' => [self::completo(['enabled' => true, 'webhook_secret' => null]), NullVoiceProvider::class],
            'encendido con región fuera de la lista' => [self::completo(['enabled' => true, 'region' => 'sg']), NullVoiceProvider::class],
            'encendido como texto' => [self::completo(['enabled' => 'yes']), NullVoiceProvider::class],
        ];
        foreach ($casos as $nombre => [$bloque, $clase]) {
            config(['voice.elevenlabs' => $bloque]);
            $actual = VoiceProviders::current();
            $this->assertInstanceOf($clase, $actual, $nombre);
            $this->assertSame(VoiceProviders::status()->provider, $actual->id(), "{$nombre}: status()->provider === current()->id()");
            $this->assertEquals(ElevenLabsConfig::fromConfig()->status(), VoiceProviders::status(), $nombre);
            $this->assertEquals(VoiceProviders::status(), $actual->status(), $nombre);
        }
    }

    public function test_el_proveedor_nulo_nunca_sale_a_la_red_ni_con_todo_encendido(): void
    {
        config(['voice.elevenlabs' => self::completo(['enabled' => true])]);
        Http::fake();

        $this->assertSame('not_configured', (new NullVoiceProvider)->verifyCredentials()->outcome);
        Http::assertNothingSent();
    }

    public function test_la_fabrica_no_usa_el_contenedor(): void
    {
        $codigo = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Voice/VoiceProviders.php');
        foreach (['app(', 'resolve(', 'make(', 'bind(', 'singleton(', 'App::', 'Container'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $codigo, "la fábrica usa {$prohibido}");
        }
    }
}
