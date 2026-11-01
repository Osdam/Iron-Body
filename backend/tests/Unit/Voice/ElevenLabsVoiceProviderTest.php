<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\ElevenLabs\ElevenLabsVoiceProvider as Proveedor;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * El adaptador de ElevenLabs (contrato H6 §I6.1), siempre contra `Http::fake`
 * y con `Http::preventStrayRequests()`: una petición no simulada rompe la
 * prueba en vez de salir a la red.
 *
 * LO QUE SE PROTEGE. Que sin la bandera o sin todas las piezas NO SALE NADA,
 * aunque se instancie el adaptador a mano; que la única petición es un GET a
 * `/v1/convai/agents/{agent_id}` de uno de los dos hosts cerrados en
 * producción (aunque haya base del simulador); las cabeceras (`xi-api-key` y
 * `Accept: application/json`) sin cuerpo; los tiempos, sin redirecciones ni
 * reintentos; la tabla de resultados; y que ni la clave ni el cuerpo de la
 * respuesta salen en el resultado. Además, las huellas de los secretos.
 */
class ElevenLabsVoiceProviderTest extends TestCase
{
    private const CLAVE = 'xi-centinela-API-0123456789abcdef';

    private const SECRETO = 'whsec-centinela-WEBHOOK';

    private const HERRAMIENTAS = 'tool-centinela-TOOL';

    private const AGENTE = 'agent_8a7f3c';

    private const URL = 'https://api.elevenlabs.io/v1/convai/agents/agent_8a7f3c';

    private const URL_EU = 'https://api.eu.residency.elevenlabs.io/v1/convai/agents/agent_8a7f3c';

    /** Los dos únicos hosts a los que puede salir en producción. */
    private const HOSTS = ['api.elevenlabs.io', 'api.eu.residency.elevenlabs.io'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configurar();
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
            'agent_phone_number_id' => null,
            'tool_secret' => self::HERRAMIENTAS,
            'base_url' => 'http://127.0.0.1:8096',
            'timeout_seconds' => 7,
        ]]);
    }

    /** Una ficha de agente como la de la referencia oficial (solo lo que importa, más ruido con datos). */
    private static function agente(?string $salida = 'ulaw_8000', ?string $entrada = 'ulaw_8000'): array
    {
        $conversacion = ['agent' => ['first_message' => 'Hola, soy el asistente. Mi número es +57 300 123 4567.']];
        if ($salida !== null) {
            $conversacion['tts'] = ['voice_id' => 'voz_1', 'agent_output_audio_format' => $salida];
        }
        if ($entrada !== null) {
            $conversacion['asr'] = ['quality' => 'high', 'user_input_audio_format' => $entrada];
        }

        return ['agent_id' => self::AGENTE, 'name' => 'Agente de prueba CUERPO-CENTINELA', 'conversation_config' => $conversacion];
    }

    /** `Http::fake` acumula stubs y responde el primero que casa: para cambiar de respuesta, una fábrica nueva. */
    private static function soloEsto(mixed $falso): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => $falso]);
    }

    private static function verificar(): array
    {
        return (new Proveedor)->verifyCredentials()->toArray();
    }

    public function test_identidad(): void
    {
        $this->assertSame('elevenlabs', (new Proveedor)->id());
        $this->assertSame('ELEVENLABS_UNVERIFIED', (new Proveedor)->status()->state);
    }

    public function test_en_produccion_un_solo_get_al_host_oficial_con_sus_cabeceras_y_sin_cuerpo(): void
    {
        $this->app['env'] = 'production';
        $opciones = null;
        Http::fake(function (Request $r, array $o) use (&$opciones) {
            $opciones = $o;

            return Http::response(self::agente());
        });

        $r = self::verificar();

        $this->assertSame('credentials_ok', $r['outcome']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $req): bool {
            $this->assertSame('GET', $req->method());
            $this->assertSame(self::URL, $req->url(), 'en producción, siempre el host de la región aunque haya base del simulador');
            $this->assertSame([self::CLAVE], $req->header('xi-api-key'));
            $this->assertSame(['application/json'], $req->header('Accept'));
            $this->assertSame('', $req->body(), 'un GET sin cuerpo');
            $this->assertFalse($req->hasHeader('Authorization'));

            return true;
        });
        $this->assertSame(7, $opciones['timeout'], 'el timeout configurado');
        $this->assertSame(5, $opciones['connect_timeout']);
        $this->assertFalse($opciones['allow_redirects'], 'sin redirecciones: la clave no viaja a otro host');
    }

    public function test_la_region_eu_va_a_su_host_de_residencia(): void
    {
        $this->app['env'] = 'production';
        $this->configurar(['region' => 'eu']);
        Http::fake(['*' => Http::response(self::agente())]);

        self::verificar();

        Http::assertSent(fn (Request $req): bool => $req->url() === self::URL_EU);
    }

    public function test_fuera_de_produccion_va_al_simulador_y_si_la_base_no_es_limpia_al_host_oficial(): void
    {
        Http::fake(['*' => Http::response(self::agente())]);
        self::verificar();
        Http::assertSent(fn (Request $req): bool => $req->url() === 'http://127.0.0.1:8096/v1/convai/agents/agent_8a7f3c');

        self::soloEsto(Http::response(self::agente()));
        $this->configurar(['base_url' => 'http://usuario:clave@127.0.0.1:8096']);
        self::verificar();
        Http::assertSent(fn (Request $req): bool => $req->url() === self::URL);
    }

    public function test_con_poco_tiempo_de_espera_el_de_conexion_no_lo_supera(): void
    {
        $opciones = null;
        Http::fake(function (Request $r, array $o) use (&$opciones) {
            $opciones = $o;

            return Http::response(self::agente());
        });
        $this->configurar(['timeout_seconds' => 3]);

        self::verificar();

        $this->assertSame(3, $opciones['timeout']);
        $this->assertSame(3, $opciones['connect_timeout']);
    }

    public function test_sin_la_bandera_o_sin_una_pieza_no_sale_ninguna_peticion_aunque_se_instancie_a_mano(): void
    {
        $casos = [
            'bandera apagada' => ['enabled' => false],
            'bandera como texto' => ['enabled' => 'true'],
            'sin clave' => ['api_key' => null],
            'sin agente' => ['agent_id' => ''],
            'agente mal formado' => ['agent_id' => '../../v1/user'],
            'sin secreto del webhook' => ['webhook_secret' => null],
            'región fuera de la lista' => ['region' => 'us'],
        ];
        Http::fake();
        foreach ($casos as $nombre => $cambios) {
            $this->configurar($cambios);
            $this->assertSame(['outcome' => 'not_configured', 'httpStatus' => null, 'agentAudioUlaw8000' => null, 'latencyMs' => 0], self::verificar(), $nombre);
        }
        Http::assertNothingSent();
    }

    public function test_la_tabla_de_resultados(): void
    {
        $casos = [
            '200 en μ-law 8000 en los dos sentidos' => [Http::response(self::agente()), 'credentials_ok', 200, true],
            '200 con la salida en pcm' => [Http::response(self::agente('pcm_16000')), 'credentials_ok', 200, false],
            '200 con la entrada en pcm' => [Http::response(self::agente('ulaw_8000', 'pcm_16000')), 'credentials_ok', 200, false],
            '200 sin el formato de salida' => [Http::response(self::agente(null)), 'credentials_ok', 200, null],
            '200 sin el formato de entrada' => [Http::response(self::agente('ulaw_8000', null)), 'credentials_ok', 200, null],
            '200 con un formato que no es texto' => [Http::response(['conversation_config' => ['tts' => ['agent_output_audio_format' => 8000], 'asr' => ['user_input_audio_format' => 'ulaw_8000']]]), 'credentials_ok', 200, null],
            '200 con conversation_config que no es objeto' => [Http::response(['conversation_config' => 'ulaw_8000']), 'credentials_ok', 200, null],
            '200 con un objeto vacío' => [Http::response('{}', 200), 'credentials_ok', 200, null],
            '200 que no es JSON (decisión defensiva)' => [Http::response('<html>CUERPO-CENTINELA</html>', 200), 'error', 200, null],
            '200 con una lista JSON (decisión defensiva)' => [Http::response('[{"agent_id":"x"}]', 200), 'error', 200, null],
            '204 sin cuerpo' => [Http::response('', 204), 'error', 204, null],
            '401' => [Http::response(['detail' => ['status' => 'invalid_api_key', 'message' => 'Invalid API key CUERPO-CENTINELA']], 401), 'invalid_credentials', 401, null],
            '403 (también una IP fuera de la lista permitida)' => [Http::response(['detail' => ['status' => 'forbidden', 'message' => 'CUERPO-CENTINELA']], 403), 'invalid_credentials', 403, null],
            '404' => [Http::response(['detail' => ['status' => 'agent_not_found', 'message' => 'CUERPO-CENTINELA']], 404), 'agent_not_found', 404, null],
            '302 (no se sigue)' => [Http::response('', 302, ['Location' => 'https://otro.example/robar']), 'error', 302, null],
            '400' => [Http::response(['detail' => 'CUERPO-CENTINELA'], 400), 'error', 400, null],
            '422' => [Http::response(['detail' => 'CUERPO-CENTINELA'], 422), 'error', 422, null],
            '429' => [Http::response(['detail' => 'CUERPO-CENTINELA'], 429), 'error', 429, null],
            '500' => [Http::response('CUERPO-CENTINELA', 500), 'error', 500, null],
            '503' => [Http::response('CUERPO-CENTINELA', 503), 'error', 503, null],
            'tiempo agotado (cURL 28)' => [static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out', $r->toPsrRequest(), null, ['errno' => 28])), 'unreachable', null, null],
            'sin conexión (cURL 7)' => [static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $r->toPsrRequest(), null, ['errno' => 7])), 'unreachable', null, null],
            'sin resolver el nombre (failedConnection)' => [Http::failedConnection(), 'unreachable', null, null],
        ];

        foreach ($casos as $nombre => [$falso, $resultado, $http, $audio]) {
            self::soloEsto($falso);
            $r = self::verificar();
            $this->assertSame($resultado, $r['outcome'], $nombre);
            $this->assertSame($http, $r['httpStatus'], $nombre);
            $this->assertSame($audio, $r['agentAudioUlaw8000'], $nombre);
            $this->assertIsInt($r['latencyMs'], $nombre);
            $this->assertGreaterThanOrEqual(0, $r['latencyMs'], $nombre);
            Http::assertSentCount(1);
            // Nada del proveedor ni de la clave en el resultado.
            $plano = (string) json_encode($r);
            foreach (['CUERPO-CENTINELA', 'Invalid API key', 'robar', self::CLAVE, 'xi-centinela', '+57'] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $plano, "{$nombre}: el resultado lleva «{$prohibido}»");
            }
        }
    }

    public function test_sin_reintentos_ni_aunque_falle_el_servidor(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        self::verificar();
        Http::assertSentCount(1);

        $codigo = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/Voice/ElevenLabs/ElevenLabsVoiceProvider.php');
        $this->assertStringNotContainsString('->retry(', $codigo);
        $this->assertStringContainsString('->withoutRedirecting()', $codigo);
    }

    public function test_otro_fallo_del_cliente_es_error_y_no_se_filtra_nada(): void
    {
        Http::fake(fn () => throw new RuntimeException('detalle interno con '.self::CLAVE));

        $r = self::verificar();

        $this->assertSame('error', $r['outcome']);
        $this->assertNull($r['httpStatus']);
        $this->assertStringNotContainsString(self::CLAVE, (string) json_encode($r));
    }

    public function test_una_clave_que_no_cabe_en_una_cabecera_da_error_sin_lanzar_ni_filtrarla(): void
    {
        // Guzzle rechaza la cabecera antes de salir y su mensaje lleva el valor: no puede llegar a ningún lado.
        $this->configurar(['api_key' => "xi-centinela\r\nX-Inyectada: 1"]);
        Http::fake(['*' => Http::response(self::agente())]);

        $r = self::verificar();

        $this->assertSame('error', $r['outcome']);
        $this->assertStringNotContainsString('centinela', (string) json_encode($r));
        $this->assertStringNotContainsString('Inyectada', (string) json_encode($r));
    }

    public function test_una_peticion_no_simulada_se_ve_en_las_pruebas(): void
    {
        // Sin Http::fake y con preventStrayRequests, el GET no sale: se lanza y no se disfraza de «error».
        $this->expectException(StrayRequestException::class);

        self::verificar();
    }

    public function test_en_produccion_solo_los_dos_hosts_cerrados(): void
    {
        $this->app['env'] = 'production';
        Http::fake(['*' => Http::response(self::agente())]);
        foreach (['default', 'eu', 'DEFAULT', ' eu '] as $region) {
            $this->configurar(['region' => $region, 'base_url' => 'https://evil.example']);
            self::verificar();
        }
        foreach (Http::recorded() as [$req]) {
            $this->assertContains(parse_url($req->url(), PHP_URL_HOST), self::HOSTS);
            $this->assertSame('https', parse_url($req->url(), PHP_URL_SCHEME));
        }
        Http::assertSentCount(4);
    }

    public function test_las_huellas_de_los_secretos(): void
    {
        Http::fake();

        // Derivadas con `openssl dgst -sha256 -hmac` y con `hmac` de Python: los 8 primeros hex.
        $this->assertSame(
            ['api_key' => '1a33febe', 'webhook_secret' => 'c7e86c56', 'tool_secret' => '5be3314a'],
            Proveedor::secretFingerprints('clave-de-huellas-de-prueba'),
        );

        // Se recorta como al usarla: el mismo secreto con espacios alrededor tiene la misma huella.
        $this->configurar(['api_key' => '  '.self::CLAVE."\n", 'webhook_secret' => '', 'tool_secret' => null]);
        $this->assertSame(['api_key' => '1a33febe', 'webhook_secret' => null, 'tool_secret' => null], Proveedor::secretFingerprints('clave-de-huellas-de-prueba'));

        // Otra clave, otras huellas; nunca el secreto.
        $otra = Proveedor::secretFingerprints('otra-clave');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string) $otra['api_key']);
        $this->assertNotSame('1a33febe', $otra['api_key']);
        $this->assertStringNotContainsString('centinela', (string) json_encode($otra));

        Http::assertNothingSent();
    }

    public function test_las_huellas_sin_clave_no_se_calculan(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Proveedor::secretFingerprints('');
    }
}
