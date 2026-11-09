<?php

namespace Tests\Feature\Voice;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `voice:doctor [--json] [--verify]` de punta a punta (contrato H6 §I6.3), con
 * `Http::preventStrayRequests()`: solo pueden responder los dos hosts
 * cerrados de ElevenLabs (o el simulador fuera de producción); cualquier otra
 * petición rompe la prueba en vez de salir a la red.
 *
 * LO QUE SE PROTEGE.
 *  - Sin la configuración imprime `ELEVENLABS_NOT_CONFIGURED`, las piezas que
 *    faltan POR NOMBRE y sale con 0, también en JSON (la forma exacta de
 *    `VoiceProviderStatus::toArray()`), sin red.
 *  - `--verify` solo actúa con `ELEVENLABS_UNVERIFIED`: con otro estado no
 *    llama a nada y sale con 1; con él, un GET al host de la región (en
 *    producción, aunque haya base del simulador), 0 solo con
 *    `credentials_ok` y 1 con credenciales inválidas, agente inexistente o
 *    sin conexión. Nada se guarda.
 *  - Ningún secreto, valor de configuración ni cuerpo del proveedor aparece
 *    en la salida, en texto ni en JSON.
 */
class VoiceDoctorCommandTest extends TestCase
{
    private const CLAVE = 'xi-centinela-API-0123456789abcdef';

    private const SECRETO = 'whsec-centinela-WEBHOOK';

    private const HERRAMIENTAS = 'tool-centinela-TOOL';

    private const NUMERO = 'phnum_centinela_0123';

    private const AGENTE = 'agent_8a7f3c';

    private const HOST = 'https://api.elevenlabs.io';

    private const HOST_EU = 'https://api.eu.residency.elevenlabs.io';

    private const RUTA = '/v1/convai/agents/agent_8a7f3c';

    protected function setUp(): void
    {
        parent::setUp();
        // Graba y no deja salir nada que no esté simulado: sin stubs, cualquier petición lanza.
        Http::preventStrayRequests();
        Http::fake([]);
    }

    /** @param  array<string,mixed>  $cambios */
    private function configurar(array $cambios = []): void
    {
        config(['voice.elevenlabs' => $cambios + [
            'enabled' => true,
            'api_key' => self::CLAVE,
            'agent_id' => self::AGENTE,
            'webhook_secret' => self::SECRETO,
            'region' => 'default',
            'agent_phone_number_id' => self::NUMERO,
            'tool_secret' => self::HERRAMIENTAS,
            'base_url' => 'https://evil.example',
            'timeout_seconds' => 10,
        ]]);
    }

    /** Solo los dos hosts cerrados responden; otra URL es una petición «perdida» y lanza. */
    private static function hostsCerrados(mixed $respuesta, mixed $respuestaEu = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            self::HOST.'/*' => $respuesta,
            self::HOST_EU.'/*' => $respuestaEu ?? $respuesta,
        ]);
    }

    private static function agente(string $formato = 'ulaw_8000'): array
    {
        return [
            'agent_id' => self::AGENTE,
            'name' => 'CUERPO-CENTINELA',
            'conversation_config' => [
                'agent' => ['first_message' => 'Hola, llámame al +57 300 123 4567'],
                'tts' => ['agent_output_audio_format' => $formato],
                'asr' => ['user_input_audio_format' => $formato],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $opciones
     * @return array{0:int, 1:string}
     */
    private static function doctor(array $opciones = []): array
    {
        $codigo = Artisan::call('voice:doctor', $opciones);

        return [$codigo, Artisan::output()];
    }

    private function assertSinSecretos(string $salida, string $caso): void
    {
        foreach ([self::CLAVE, 'xi-centinela', self::SECRETO, self::HERRAMIENTAS, self::NUMERO, 'CUERPO-CENTINELA', '+57 300', 'evil.example', self::AGENTE] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $salida, "{$caso}: la salida lleva «{$prohibido}»");
        }
    }

    public function test_sin_configuracion_dice_not_configured_por_nombre_sale_0_y_no_sale_a_la_red(): void
    {
        [$codigo, $salida] = self::doctor();

        $this->assertSame(0, $codigo, 'NOT_CONFIGURED es el estado del hito, no un fallo');
        $this->assertStringContainsString('ELEVENLABS_NOT_CONFIGURED', $salida);
        $this->assertStringContainsString('api_key (ELEVENLABS_API_KEY), agent_id (ELEVENLABS_AGENT_ID), webhook_secret (ELEVENLABS_WEBHOOK_SECRET)', $salida);
        $this->assertStringContainsString('Región     : default', $salida);
        $this->assertStringContainsString('apagada (ELEVENLABS_ENABLED)', $salida);
        // DOC8 D-11: la línea de las llamadas lee la constante de VoiceCallPolicy (cerrada en el árbol compartido).
        $this->assertStringContainsString('Llamadas   : cerradas en el código (la constante de VoiceCallPolicy)', $salida);
        Http::assertNothingSent();
    }

    public function test_sin_configuracion_el_json_es_exactamente_el_estado(): void
    {
        [$codigo, $salida] = self::doctor(['--json' => true]);

        $this->assertSame(0, $codigo);
        $this->assertSame(
            ['provider' => 'none', 'state' => 'ELEVENLABS_NOT_CONFIGURED', 'enabled' => false, 'missing' => ['api_key', 'agent_id', 'webhook_secret'], 'region' => 'default'],
            json_decode($salida, true, flags: JSON_THROW_ON_ERROR),
        );
        Http::assertNothingSent();
    }

    public function test_verify_sin_configuracion_no_llama_a_nada_y_sale_1(): void
    {
        [$codigo, $salida] = self::doctor(['--verify' => true]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('No se verifica: el estado es ELEVENLABS_NOT_CONFIGURED', $salida);
        $this->assertStringContainsString('No se ha hecho ninguna petición.', $salida);

        [$codigo, $salida] = self::doctor(['--verify' => true, '--json' => true]);
        $this->assertSame(1, $codigo);
        $json = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ELEVENLABS_NOT_CONFIGURED', $json['state']);
        $this->assertArrayHasKey('verification', $json);
        $this->assertNull($json['verification']);

        Http::assertNothingSent();
    }

    public function test_completo_y_apagado_es_disabled_y_verify_tampoco_llama(): void
    {
        $this->configurar(['enabled' => false]);

        [$codigo, $salida] = self::doctor();
        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('ELEVENLABS_DISABLED', $salida);
        $this->assertStringContainsString('Faltan     : ninguna', $salida);
        $this->assertSinSecretos($salida, 'DISABLED');

        [$codigo, $salida] = self::doctor(['--verify' => true]);
        $this->assertSame(1, $codigo, 'la bandera apagada no deja verificar');
        $this->assertStringContainsString('No se verifica: el estado es ELEVENLABS_DISABLED', $salida);
        $this->assertSinSecretos($salida, 'DISABLED --verify');

        Http::assertNothingSent();
    }

    public function test_sin_verify_el_doctor_nunca_llama_ni_con_todo_encendido(): void
    {
        $this->configurar();

        [$codigo, $salida] = self::doctor();
        [$codigoJson, $salidaJson] = self::doctor(['--json' => true]);

        $this->assertSame(0, $codigo);
        $this->assertSame(0, $codigoJson);
        $this->assertStringContainsString('ELEVENLABS_UNVERIFIED', $salida);
        $this->assertSame(['provider' => 'elevenlabs', 'state' => 'ELEVENLABS_UNVERIFIED', 'enabled' => true, 'missing' => [], 'region' => 'default'], json_decode($salidaJson, true));
        $this->assertSinSecretos($salida.$salidaJson, 'UNVERIFIED sin --verify');
        Http::assertNothingSent();
    }

    public function test_verify_con_credenciales_validas_en_produccion_va_al_host_oficial_y_sale_0(): void
    {
        $this->app['env'] = 'production';
        $this->configurar();
        self::hostsCerrados(Http::response(self::agente()));

        [$codigo, $salida] = self::doctor(['--verify' => true]);

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('Verificación: credentials_ok · HTTP 200', $salida);
        $this->assertStringContainsString('agente en μ-law 8000: sí', $salida);
        $this->assertStringContainsString('No se guarda: es solo una comprobación por consola.', $salida);
        $this->assertSinSecretos($salida, 'credentials_ok');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $req): bool {
            $this->assertSame('GET', $req->method());
            $this->assertSame(self::HOST.self::RUTA, $req->url(), 'en producción, el host de la región aunque haya base configurada');
            $this->assertSame([self::CLAVE], $req->header('xi-api-key'));
            $this->assertSame(['application/json'], $req->header('Accept'));
            $this->assertSame('', $req->body());

            return true;
        });
    }

    public function test_verify_con_la_region_eu_va_a_su_host_de_residencia(): void
    {
        $this->app['env'] = 'production';
        $this->configurar(['region' => 'eu']);
        self::hostsCerrados(Http::response('', 500), Http::response(self::agente('pcm_16000')));

        [$codigo, $salida] = self::doctor(['--verify' => true]);

        $this->assertSame(0, $codigo, 'el host de la UE respondió bien; el de por defecto habría dado 500');
        $this->assertStringContainsString('agente en μ-law 8000: no', $salida);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $req): bool => $req->url() === self::HOST_EU.self::RUTA);
    }

    public function test_verify_con_credenciales_invalidas_o_agente_inexistente_sale_1(): void
    {
        $this->app['env'] = 'production';
        $this->configurar();
        $casos = [
            '401' => [Http::response(['detail' => ['status' => 'invalid_api_key', 'message' => 'Invalid API key CUERPO-CENTINELA']], 401), 'invalid_credentials', 'HTTP 401'],
            '403' => [Http::response(['detail' => ['status' => 'forbidden', 'message' => 'CUERPO-CENTINELA']], 403), 'invalid_credentials', 'HTTP 403'],
            '404' => [Http::response(['detail' => ['status' => 'agent_not_found', 'message' => 'CUERPO-CENTINELA']], 404), 'agent_not_found', 'HTTP 404'],
            '500' => [Http::response('CUERPO-CENTINELA', 500), 'error', 'HTTP 500'],
            'sin conexión' => [Http::failedConnection(), 'unreachable', 'HTTP —'],
        ];

        foreach ($casos as $nombre => [$respuesta, $resultado, $http]) {
            self::hostsCerrados($respuesta);

            [$codigo, $salida] = self::doctor(['--verify' => true]);

            $this->assertSame(1, $codigo, $nombre);
            $this->assertStringContainsString("Verificación: {$resultado} · {$http}", $salida, $nombre);
            $this->assertStringContainsString("La verificación no salió bien: {$resultado}.", $salida, $nombre);
            $this->assertSinSecretos($salida, $nombre);
            Http::assertSentCount(1);
        }
    }

    public function test_verify_en_json_con_credenciales_validas_e_invalidas(): void
    {
        $this->app['env'] = 'production';
        $this->configurar();

        self::hostsCerrados(Http::response(self::agente()));
        [$codigo, $salida] = self::doctor(['--verify' => true, '--json' => true]);
        $json = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $codigo);
        $this->assertSame(['provider', 'state', 'enabled', 'missing', 'region', 'verification'], array_keys($json));
        $this->assertSame('ELEVENLABS_UNVERIFIED', $json['state']);
        $this->assertSame(['outcome', 'httpStatus', 'agentAudioUlaw8000', 'latencyMs'], array_keys($json['verification']));
        $this->assertSame('credentials_ok', $json['verification']['outcome']);
        $this->assertSame(200, $json['verification']['httpStatus']);
        $this->assertTrue($json['verification']['agentAudioUlaw8000']);
        $this->assertIsInt($json['verification']['latencyMs']);
        $this->assertSinSecretos($salida, 'JSON credentials_ok');

        self::hostsCerrados(Http::response(['detail' => 'CUERPO-CENTINELA'], 401));
        [$codigo, $salida] = self::doctor(['--verify' => true, '--json' => true]);
        $json = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $codigo);
        $this->assertSame(['outcome' => 'invalid_credentials', 'httpStatus' => 401, 'agentAudioUlaw8000' => null], array_diff_key($json['verification'], ['latencyMs' => true]));
        $this->assertSinSecretos($salida, 'JSON invalid_credentials');
    }

    public function test_fuera_de_produccion_verify_va_al_simulador_del_e2e(): void
    {
        $this->configurar(['base_url' => 'http://127.0.0.1:8096']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:8096/*' => Http::response(self::agente())]);

        [$codigo] = self::doctor(['--verify' => true]);

        $this->assertSame(0, $codigo);
        Http::assertSent(fn (Request $req): bool => $req->url() === 'http://127.0.0.1:8096'.self::RUTA);
    }

    public function test_una_region_no_valida_se_nombra_sin_su_valor(): void
    {
        $this->configurar(['region' => 'mars-centinela']);

        [$codigo, $salida] = self::doctor();
        [, $json] = self::doctor(['--json' => true]);

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('ELEVENLABS_NOT_CONFIGURED', $salida);
        $this->assertStringContainsString('no válida (ELEVENLABS_REGION; admitidas: default, eu)', $salida);
        $this->assertStringContainsString('region (ELEVENLABS_REGION)', $salida);
        $this->assertSame(['region'], json_decode($json, true)['missing']);
        $this->assertStringNotContainsString('mars', $salida.$json);
        $this->assertSinSecretos($salida.$json, 'región no válida');
        Http::assertNothingSent();
    }
}
