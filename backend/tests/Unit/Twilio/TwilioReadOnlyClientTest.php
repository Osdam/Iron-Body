<?php

namespace Tests\Unit\Twilio;

use App\Services\Twilio\TwilioReadOnlyClient as Cliente;
use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Tests\TestCase;
use Throwable;

/**
 * El cliente de solo lectura de Twilio (contrato H6 §I5.4 e §I9.4), siempre
 * con `Http::preventStrayRequests()` y `Http::fake()`: cero red.
 *
 * LO QUE SE PROTEGE.
 *  - Solo GET: la clase no expone más métodos públicos que `__construct` y
 *    `get`; recorriéndolos contra un falso que anota el verbo, solo sale GET; y
 *    en el fichero no aparecen `post`, `put`, `patch`, `delete`, `asForm` ni
 *    `send` (ni en un comentario), ni otro método del cliente HTTP que los de
 *    la única cadena.
 *  - Las cinco rutas exactas, primera página siempre (sin `Page` ni
 *    `PageToken`), y un `next_page_uri` o `meta.next_page_url` no se pide.
 *  - Hosts oficiales en `production` aunque haya base o `TWILIO_BASE`; fuera,
 *    la base del simulador solo si es una URL limpia.
 *  - Un SID mal formado no llega a la ruta (ni con un salto de línea final).
 *  - Basic con la credencial dada; la credencial no está en la URL, el
 *    resultado, el log, los mensajes, las trazas ni los volcados del objeto.
 *  - Tiempos cortos (10 s y 5 s para conectar, con techo), sin redirecciones ni
 *    reintentos.
 *  - Nunca lanza por la red: la tabla 401/403/404/429/5xx/3xx/tiempo
 *    agotado/sin conexión/cuerpo que no es un objeto JSON.
 *  - El `auth_token` de la ficha de la cuenta no sale del cliente.
 *  - El espacio `App\Services\Twilio` es neutro: no lee configuración ni nombra
 *    nada del resto de la aplicación.
 */
class TwilioReadOnlyClientTest extends TestCase
{
    private const SID = 'ACdeadbeefdeadbeefdeadbeefdeadbeef';

    private const USUARIO = 'SKcafebabecafebabecafebabecafebabe';

    private const SECRETO = 'secreto-centinela-TW-7Q2Z9X';

    private const TOKEN_DE_LA_FICHA = 'tok3n-c3ntin3la-de-la-cuenta-9f8e7d';

    private const SIMULADOR = 'http://127.0.0.1:8095';

    private const FICHERO = 'app/Services/Twilio/TwilioReadOnlyClient.php';

    private ?string $twilioBaseAntes = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->twilioBaseAntes = getenv('TWILIO_BASE') === false ? null : (string) getenv('TWILIO_BASE');
    }

    protected function tearDown(): void
    {
        $this->twilioBaseAntes === null ? putenv('TWILIO_BASE') : putenv('TWILIO_BASE='.$this->twilioBaseAntes);
        unset($_ENV['TWILIO_BASE'], $_SERVER['TWILIO_BASE']);
        parent::tearDown();
    }

    /** 05:30 en Bogotá = 10:30 UTC del 31-10-2026. */
    private static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-31T05:30:00-05:00');
    }

    private static function cliente(?string $base = null, int $timeout = 10): Cliente
    {
        return new Cliente(self::SID, self::USUARIO, self::SECRETO, $base, $timeout);
    }

    /** Una fábrica nueva (los stubs se acumulan), con la red cerrada y solo este falso. */
    private static function soloEsto(mixed $falso): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => $falso]);
    }

    /** @return array<string,mixed> */
    private static function cuerpo(string $nombre): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/twilio-api-responses.json'), true, 512, JSON_THROW_ON_ERROR)[$nombre];
    }

    /**
     * Lo que se pidió: verbo, URL sin consulta y consulta, en orden.
     *
     * @return list<array{0: string, 1: string, 2: array<string,mixed>}>
     */
    private static function pedidas(): array
    {
        return Http::recorded()->map(static function (array $par): array {
            $partes = explode('?', $par[0]->url(), 2);
            parse_str($partes[1] ?? '', $consulta);

            return [$par[0]->method(), $partes[0], $consulta];
        })->values()->all();
    }

    /**
     * Las cinco peticiones que se esperan, en el orden de los pasos.
     *
     * @return list<array{0: string, 1: string, 2: array<string,string>}>
     */
    private static function esperadas(string $api, string $monitor): array
    {
        $cuenta = "{$api}/2010-04-01/Accounts/".self::SID;

        return [
            ['GET', "{$cuenta}.json", []],
            ['GET', "{$cuenta}/IncomingPhoneNumbers.json", ['PageSize' => '20']],
            ['GET', "{$cuenta}/OutgoingCallerIds.json", ['PageSize' => '20']],
            ['GET', "{$cuenta}/Calls.json", ['PageSize' => '50', 'StartTime>' => '2026-10-24']],
            ['GET', "{$monitor}/v1/Alerts", ['PageSize' => '50', 'StartDate' => '2026-10-30T10:30:00Z']],
        ];
    }

    private static function recorrer(Cliente $cliente, ?DateTimeImmutable $ahora = null): void
    {
        foreach (Cliente::STEPS as $paso) {
            $cliente->get($paso, $ahora ?? self::ahora());
        }
    }

    public function test_las_cinco_rutas_exactas_con_get_y_solo_la_primera_pagina(): void
    {
        self::soloEsto(Http::response(['page' => 0]));

        self::recorrer(self::cliente());

        $this->assertSame(self::esperadas(Cliente::PRODUCTION_API_BASE, Cliente::PRODUCTION_MONITOR_BASE), self::pedidas());
        foreach (Http::recorded() as [$peticion]) {
            $this->assertDoesNotMatchRegularExpression('/[?&](Page|PageToken)=/', $peticion->url(), 'solo la primera página');
            $this->assertSame('', $peticion->body(), 'un GET sin cuerpo');
        }
        // El `>` es parte del NOMBRE del parámetro (`StartTime>=fecha`), codificado como manda RFC 3986.
        $this->assertStringEndsWith('/Calls.json?PageSize=50&StartTime%3E=2026-10-24', Http::recorded()->values()[3][0]->url());
        $this->assertSame(['account', 'numbers', 'caller_ids', 'calls', 'alerts'], Cliente::STEPS);
    }

    public function test_las_fechas_de_llamadas_y_alertas_se_calculan_en_utc(): void
    {
        self::soloEsto(Http::response(['page' => 0]));

        // 21:00 en Bogotá del 31-10 = 02:00 UTC del 01-11: «hoy» en UTC es el 1 de noviembre.
        $ahora = new DateTimeImmutable('2026-10-31T21:00:00-05:00');
        self::cliente()->get('calls', $ahora);
        self::cliente()->get('alerts', $ahora);

        $pedidas = self::pedidas();
        $this->assertSame('2026-10-25', $pedidas[0][2]['StartTime>']);
        $this->assertSame('2026-10-31T02:00:00Z', $pedidas[1][2]['StartDate']);
    }

    public function test_en_produccion_siempre_los_hosts_oficiales_aunque_haya_base_o_twilio_base(): void
    {
        config(['otp.twilio.base' => 'https://otp-base.invalid', 'otp.twilio.verify_base' => 'https://verify-base.invalid']);
        putenv('TWILIO_BASE=https://twilio-base.invalid');
        $_ENV['TWILIO_BASE'] = $_SERVER['TWILIO_BASE'] = 'https://twilio-base.invalid';
        $this->app['env'] = 'production';
        $this->assertTrue($this->app->isProduction());

        self::soloEsto(Http::response(['page' => 0]));
        self::recorrer(self::cliente(self::SIMULADOR));
        $this->assertSame(self::esperadas(Cliente::PRODUCTION_API_BASE, Cliente::PRODUCTION_MONITOR_BASE), self::pedidas());

        // Los hosts se deciden en cada llamada: un cliente construido antes de pasar a producción tampoco usa la base.
        $this->app['env'] = 'testing';
        $anterior = self::cliente(self::SIMULADOR);
        $this->app['env'] = 'production';
        self::soloEsto(Http::response(['page' => 0]));
        self::recorrer($anterior);
        $this->assertSame(self::esperadas(Cliente::PRODUCTION_API_BASE, Cliente::PRODUCTION_MONITOR_BASE), self::pedidas());

        foreach (Http::recorded() as [$peticion]) {
            $this->assertContains(parse_url($peticion->url(), PHP_URL_HOST), ['api.twilio.com', 'monitor.twilio.com']);
            $this->assertSame('https', parse_url($peticion->url(), PHP_URL_SCHEME));
        }
    }

    public function test_fuera_de_produccion_la_base_del_simulador_vale_para_los_cinco_pasos_si_es_limpia(): void
    {
        $this->assertFalse($this->app->isProduction());

        self::soloEsto(Http::response(['page' => 0]));
        self::recorrer(self::cliente(self::SIMULADOR.'/'));
        $this->assertSame(self::esperadas(self::SIMULADOR, self::SIMULADOR), self::pedidas());

        $malas = [
            'otro esquema' => 'ftp://127.0.0.1:8095',
            'con usuario y clave' => 'http://ops:hunter2@127.0.0.1:8095',
            'con consulta' => 'http://127.0.0.1:8095/?x=1',
            'con consulta vacía' => 'http://127.0.0.1:8095/?',
            'con fragmento' => 'http://127.0.0.1:8095/#f',
            'sin host' => 'http:///v1',
            'texto' => 'no es una url',
            'vacía' => '',
            'espacios' => '   ',
        ];
        foreach ($malas as $nombre => $mala) {
            self::soloEsto(Http::response(['page' => 0]));
            self::cliente($mala)->get('account', self::ahora());
            $this->assertSame([['GET', Cliente::PRODUCTION_API_BASE.'/2010-04-01/Accounts/'.self::SID.'.json', []]], self::pedidas(), $nombre);
        }
    }

    public function test_un_next_page_uri_en_la_respuesta_no_se_pide(): void
    {
        $cuenta = '/2010-04-01/Accounts/'.self::SID;
        $conSiguiente = [
            'next_page_uri' => "{$cuenta}/Calls.json?PageSize=50&Page=1&PageToken=PAsiguiente",
            'first_page_uri' => "{$cuenta}/Calls.json?PageSize=50&Page=0",
            'uri' => "{$cuenta}/Calls.json?PageSize=50&Page=0",
            'meta' => ['next_page_url' => 'https://monitor.twilio.com/v1/Alerts?PageSize=50&Page=1&PageToken=PAsiguiente', 'key' => 'alerts'],
            'calls' => [], 'incoming_phone_numbers' => [], 'outgoing_caller_ids' => [], 'alerts' => [],
        ];
        self::soloEsto(Http::response($conSiguiente));

        $cliente = self::cliente();
        foreach (Cliente::STEPS as $i => $paso) {
            $r = $cliente->get($paso, self::ahora());
            $this->assertTrue($r['ok'], $paso);
            $this->assertCount($i + 1, Http::recorded(), "{$paso}: una sola petición por paso");
        }
        foreach (Http::recorded() as [$peticion]) {
            $this->assertStringNotContainsString('PageToken', $peticion->url());
            $this->assertStringNotContainsString('Page=1', $peticion->url());
        }
    }

    public function test_un_sid_mal_formado_no_llega_a_la_ruta(): void
    {
        self::soloEsto(Http::response(['page' => 0]));
        $malos = [
            'vacío' => '',
            'corto' => 'AC123',
            'mayúsculas' => 'ACDEADBEEFDEADBEEFDEADBEEFDEADBEEF',
            'una API key' => self::USUARIO,
            'salto de línea al final' => self::SID."\n",
            'espacio al final' => self::SID.' ',
            'ruta escondida' => self::SID.'/Messages',
            'subida de directorio' => 'AC/../../2010-04-01/Accounts/ACdeadbeefdeadbeefdeadbe',
            'consulta' => self::SID.'?PageSize=1000',
            'no hexadecimal' => 'AC'.str_repeat('g', 32),
            '33 hexadecimales' => 'AC'.str_repeat('a', 33),
            'el token cruzado' => self::SECRETO,
        ];
        foreach ($malos as $nombre => $sid) {
            try {
                new Cliente($sid, self::USUARIO, self::SECRETO);
                $this->fail("{$nombre}: tenía que lanzar");
            } catch (InvalidArgumentException $e) {
                if (trim($sid) !== '') {
                    $this->assertStringNotContainsString(trim($sid), $e->getMessage(), "{$nombre}: el mensaje repite el valor");
                }
                $this->assertStringNotContainsString(self::SECRETO, $e->getMessage(), $nombre);
            }
        }
        Http::assertNothingSent();
    }

    public function test_sin_credencial_o_con_un_paso_desconocido_no_sale_nada(): void
    {
        self::soloEsto(Http::response(['page' => 0]));
        foreach ([['', self::SECRETO], [self::USUARIO, ''], ['   ', self::SECRETO], [self::USUARIO, "\t\n"]] as [$usuario, $secreto]) {
            try {
                new Cliente(self::SID, $usuario, $secreto);
                $this->fail('tenía que lanzar');
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString(self::SECRETO, $e->getMessage());
                $this->assertStringNotContainsString(self::USUARIO, $e->getMessage());
            }
        }

        $cliente = self::cliente();
        foreach (['', 'Account', 'messages', 'account ', '../account', 'calls?PageSize=1000'] as $paso) {
            try {
                $cliente->get($paso, self::ahora());
                $this->fail("«{$paso}»: tenía que lanzar");
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString('messages', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_basic_con_la_credencial_dada_y_nada_de_ella_en_la_url_ni_en_el_resultado(): void
    {
        self::soloEsto(Http::response(self::cuerpo('numbers_two')));

        $r = self::cliente()->get('numbers', self::ahora());

        $this->assertTrue($r['ok']);
        Http::assertSent(function (Request $peticion): bool {
            $this->assertSame(['Basic '.base64_encode(self::USUARIO.':'.self::SECRETO)], $peticion->header('Authorization'));
            $this->assertSame(['application/json'], $peticion->header('Accept'));
            $this->assertStringNotContainsString(self::SECRETO, $peticion->url());
            $this->assertStringNotContainsString(self::USUARIO, $peticion->url());

            return true;
        });
        $this->assertStringNotContainsString(self::SECRETO, json_encode($r, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::USUARIO, json_encode($r, JSON_THROW_ON_ERROR));
    }

    public function test_la_ficha_de_la_cuenta_sale_sin_su_auth_token_y_el_resto_crudo(): void
    {
        self::soloEsto(Http::response(self::cuerpo('account_full_active')));

        $r = self::cliente()->get('account', self::ahora());

        $this->assertSame(['ok', 'status', 'body', 'error'], array_keys($r));
        $this->assertTrue($r['ok']);
        $this->assertSame(200, $r['status']);
        $this->assertNull($r['error']);
        $this->assertArrayNotHasKey('auth_token', $r['body']);
        $this->assertStringNotContainsString(self::TOKEN_DE_LA_FICHA, json_encode($r, JSON_THROW_ON_ERROR));
        $esperado = self::cuerpo('account_full_active');
        unset($esperado['auth_token']);
        $this->assertSame($esperado, $r['body'], 'el resto, crudo: sanearlo es cosa del saneador');
    }

    public function test_tiempos_cortos_sin_redirecciones_ni_reintentos(): void
    {
        $opciones = [];
        self::soloEsto(function (Request $peticion, array $o) use (&$opciones) {
            $opciones[] = $o;

            return Http::response(['code' => 20500, 'message' => 'Internal Server Error'], 500);
        });

        $r = self::cliente()->get('calls', self::ahora());

        $this->assertSame(['ok' => false, 'status' => 500, 'body' => null, 'error' => 'error'], $r);
        Http::assertSentCount(1);
        $this->assertSame(10, $opciones[0]['timeout']);
        $this->assertSame(5, $opciones[0]['connect_timeout']);
        $this->assertFalse($opciones[0]['allow_redirects'], 'sin redirecciones: la credencial no viaja a otro sitio');

        // El techo manda aunque quien llama pida más; el mínimo es 1 s.
        foreach ([60 => [10, 5], 11 => [10, 5], 7 => [7, 5], 3 => [3, 3], 0 => [1, 1], -5 => [1, 1]] as $pedido => [$espera, $conexion]) {
            $opciones = [];
            self::cliente(null, $pedido)->get('account', self::ahora());
            $this->assertSame($espera, $opciones[0]['timeout'], "timeout pedido {$pedido}");
            $this->assertSame($conexion, $opciones[0]['connect_timeout'], "timeout pedido {$pedido}");
        }

        $codigo = self::codigo();
        $this->assertStringNotContainsString('retry', strtolower($codigo));
        $this->assertSame(10, Cliente::MAX_TIMEOUT_SECONDS);
        $this->assertSame(5, Cliente::CONNECT_TIMEOUT_SECONDS);
    }

    public function test_una_redireccion_no_se_sigue_y_cuenta_como_error(): void
    {
        $pedidas = [];
        self::soloEsto(function (Request $peticion) use (&$pedidas) {
            $pedidas[] = $peticion->url();

            return str_contains($peticion->url(), 'twilio.com')
                ? Http::response('', 302, ['Location' => 'https://robar.invalid/credencial'])
                : Http::response(['robado' => true]);
        });

        $r = self::cliente()->get('account', self::ahora());

        $this->assertSame(['ok' => false, 'status' => 302, 'body' => null, 'error' => 'error'], $r);
        $this->assertCount(1, $pedidas);
        $this->assertStringNotContainsString('robar.invalid', implode(' ', $pedidas));
    }

    public function test_nunca_lanza_por_la_red_la_tabla_completa(): void
    {
        $errorTwilio = static fn (int $codigo, int $estado): array => ['code' => $codigo, 'message' => 'Mensaje con +12025550143', 'more_info' => 'https://www.twilio.com/docs/errors/'.$codigo, 'status' => $estado];
        $casos = [
            '401' => [Http::response($errorTwilio(20003, 401), 401), 'auth_failed', 401],
            '403' => [Http::response($errorTwilio(20403, 403), 403), 'auth_failed', 403],
            '404' => [Http::response($errorTwilio(20404, 404), 404), 'error', 404],
            '429' => [Http::response($errorTwilio(20429, 429), 429), 'error', 429],
            '400' => [Http::response($errorTwilio(21211, 400), 400), 'error', 400],
            '500' => [Http::response($errorTwilio(20500, 500), 500), 'error', 500],
            '503 con HTML' => [Http::response('<html><body>503 Service Unavailable</body></html>', 503), 'error', 503],
            '200 con HTML' => [Http::response('<html><body>hola</body></html>', 200), 'error', 200],
            '200 con un JSON que es un texto' => [Http::response('"+12025550143"', 200), 'error', 200],
            '200 con un JSON que es un número' => [Http::response('42', 200), 'error', 200],
            '200 con un JSON null' => [Http::response('null', 200), 'error', 200],
            '200 vacío' => [Http::response('', 200), 'error', 200],
            '200 con JSON roto' => [Http::response('{"calls": [', 200), 'error', 200],
            '200 con un JSON demasiado hondo' => [Http::response(str_repeat('[', 40).str_repeat(']', 40), 200), 'error', 200],
            '204 sin cuerpo' => [Http::response(null, 204), 'error', 204],
            '304' => [Http::response('', 304), 'error', 304],
            'tiempo agotado (cURL 28)' => [static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out after 10001 milliseconds for '.$r->url(), $r->toPsrRequest(), null, ['errno' => 28])), 'unreachable', null],
            'sin conexión (cURL 7)' => [static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect to api.twilio.com', $r->toPsrRequest(), null, ['errno' => 7])), 'unreachable', null],
            'sin resolver el nombre (failedConnection)' => [Http::failedConnection(), 'unreachable', null],
        ];

        foreach ($casos as $nombre => [$falso, $error, $estado]) {
            self::soloEsto($falso);
            foreach (Cliente::STEPS as $paso) {
                $r = self::cliente()->get($paso, self::ahora());
                $this->assertSame(['ok' => false, 'status' => $estado, 'body' => null, 'error' => $error], $r, "{$nombre} en {$paso}");
            }
            Http::assertSentCount(count(Cliente::STEPS));
        }
    }

    public function test_otro_fallo_del_cliente_http_es_error_y_solo_se_registra_su_clase(): void
    {
        Log::spy();
        self::soloEsto(static fn () => throw new RuntimeException('detalle interno con '.self::SECRETO.' y https://api.twilio.com/2010-04-01/Accounts/'.self::SID));

        $r = self::cliente()->get('alerts', self::ahora());

        $this->assertSame(['ok' => false, 'status' => null, 'body' => null, 'error' => 'error'], $r);
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $mensaje, array $contexto): bool {
            $todo = $mensaje.json_encode($contexto, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString(self::SECRETO, $todo);
            $this->assertStringNotContainsString(self::SID, $todo);
            $this->assertStringNotContainsString('detalle interno', $todo);

            return $contexto === ['step' => 'alerts', 'exception' => RuntimeException::class];
        });
    }

    public function test_una_peticion_que_se_escapa_del_falso_se_ve_y_no_se_convierte_en_resultado(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $this->expectException(StrayRequestException::class);
        self::cliente()->get('account', self::ahora());
    }

    public function test_solo_get_recorriendo_los_metodos_publicos(): void
    {
        $clase = new ReflectionClass(Cliente::class);
        $this->assertTrue($clase->isFinal());
        $publicos = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $clase->getMethods(ReflectionMethod::IS_PUBLIC));
        sort($publicos);
        $this->assertSame(['__construct', 'get'], $publicos, 'ningún método público más: nada que pueda crear, cambiar ni borrar en Twilio');

        // El falso no responde a otro verbo (405) y lo anota; al final, solo GET.
        $verbos = [];
        self::soloEsto(function (Request $peticion) use (&$verbos) {
            $verbos[] = $peticion->method();

            return $peticion->method() === 'GET' ? Http::response(['page' => 0]) : Http::response('verbo no admitido', 405);
        });
        $cliente = self::cliente();
        foreach ($publicos as $metodo) {
            if ($metodo === 'get') {
                foreach (Cliente::STEPS as $paso) {
                    $this->assertTrue($cliente->get($paso, self::ahora())['ok'], $paso);
                }
            }
        }
        $this->assertSame(array_fill(0, count(Cliente::STEPS), 'GET'), $verbos);
        Http::assertNotSent(static fn (Request $peticion): bool => $peticion->method() !== 'GET');
    }

    public function test_estatica_el_fichero_no_tiene_verbos_de_escritura_y_usa_una_sola_cadena_get(): void
    {
        $fuente = (string) file_get_contents(base_path(self::FICHERO));

        // Ni como palabra, ni siquiera en un comentario.
        preg_match_all('/\b(post|put|patch|delete|asForm|send)\b/i', $fuente, $palabras);
        $this->assertSame([], $palabras[0], 'el fichero nombra un verbo de escritura');

        // Ningún identificador del cliente HTTP que envíe otra cosa, reintente o cambie opciones.
        $prohibidos = ['post', 'put', 'patch', 'delete', 'head', 'send', 'sendrequest', 'asform', 'asmultipart', 'attach', 'withbody',
            'retry', 'pool', 'async', 'withoptions', 'withmiddleware', 'withrequestmiddleware', 'beforesending', 'sink', 'baseurl', 'withurlparameters'];
        $identificadores = array_map('strtolower', array_column(array_filter(self::tokens(), static fn (array $t): bool => $t[0] === T_STRING), 1));
        $this->assertSame([], array_values(array_intersect($prohibidos, $identificadores)));

        // Una sola cadena con el cliente HTTP, con estos métodos y terminada en get.
        $codigo = self::codigo();
        $this->assertSame(1, substr_count($codigo, 'Http::'), 'una sola cadena');
        $this->assertSame(['withbasicauth', 'acceptjson', 'withoutredirecting', 'connecttimeout', 'timeout', 'get'], self::cadenaHttp());
    }

    public function test_el_espacio_twilio_es_neutro_no_lee_configuracion_ni_nombra_el_resto_de_la_aplicacion(): void
    {
        $directorio = base_path('app/Services/Twilio');
        $ficheros = array_map('basename', glob($directorio.'/*') ?: []);
        sort($ficheros);
        $this->assertSame(['TwilioProbeSanitizer.php', 'TwilioReadOnlyClient.php'], $ficheros);

        foreach ($ficheros as $fichero) {
            $fuente = (string) file_get_contents("{$directorio}/{$fichero}");
            $this->assertStringNotContainsStringIgnoringCase('studio', $fuente, "{$fichero} nombra el Studio");
            $this->assertDoesNotMatchRegularExpression('/App\\\\(Models|Services\\\\(?!Twilio\b)|Http\\\\Controllers|Support|Exceptions|Jobs)/', $fuente, "{$fichero} depende de otra parte de la aplicación");
            foreach (['config', 'env', 'getenv'] as $funcion) {
                $this->assertDoesNotMatchRegularExpression('/(?<![\w>:$])'.$funcion.'\s*\(/', self::sinComentarios($fuente), "{$fichero} lee la configuración con {$funcion}()");
            }
        }
    }

    public function test_la_credencial_no_sale_en_trazas_volcados_ni_serializaciones(): void
    {
        $antes = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            try {
                new Cliente('AC-no-vale', self::USUARIO, self::SECRETO);
                $this->fail('tenía que lanzar');
            } catch (InvalidArgumentException $e) {
                // El marco del constructor, con sus argumentos (los demás arrastrarían toda la suite).
                $traza = $e->getTraceAsString().print_r($e->getTrace()[0]['args'] ?? [], true);
                $this->assertStringContainsString('SensitiveParameterValue', $traza, 'la traza lleva los argumentos y estos van tapados');
                $this->assertStringNotContainsString(substr(self::SECRETO, 0, 12), $traza);
                $this->assertStringNotContainsString(substr(self::USUARIO, 0, 12), $traza);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $antes);
        }

        $cliente = self::cliente(self::SIMULADOR);
        ob_start();
        var_dump($cliente);
        $volcados = (string) ob_get_clean();
        $volcados .= print_r($cliente, true);
        $volcados .= var_export($cliente, true);
        $volcados .= json_encode($cliente, JSON_THROW_ON_ERROR);
        $volcados .= (new CliDumper)->dump((new VarCloner)->cloneVar($cliente), true);
        $this->assertStringNotContainsString(self::SECRETO, $volcados);
        $this->assertStringNotContainsString(self::USUARIO, $volcados);

        $serializado = null;
        $mensaje = '';
        try {
            $serializado = serialize($cliente);
        } catch (Throwable $e) {
            $mensaje = $e->getMessage();
        }
        $this->assertNull($serializado, 'el cliente no se puede serializar: llevaría la credencial a una cola o una caché');
        $this->assertStringContainsString('SensitiveParameterValue', $mensaje);
        $this->assertStringNotContainsString(self::SECRETO, $mensaje);
    }

    /** @return list<array{0:int,1:string}> los tokens del cliente, sin comentarios ni espacios */
    private static function tokens(): array
    {
        $out = [];
        foreach (token_get_all((string) file_get_contents(base_path(self::FICHERO))) as $t) {
            $t = is_array($t) ? $t : [0, $t];
            if (! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** El código del cliente sin comentarios. */
    private static function codigo(): string
    {
        return self::sinComentarios((string) file_get_contents(base_path(self::FICHERO)));
    }

    private static function sinComentarios(string $fuente): string
    {
        $out = '';
        foreach (token_get_all($fuente) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    /**
     * Los métodos encadenados tras `Http::`, hasta el `;`, en minúscula (solo
     * los del primer nivel: los argumentos no cuentan).
     *
     * @return list<string>
     */
    private static function cadenaHttp(): array
    {
        $tokens = self::tokens();
        $inicio = null;
        foreach ($tokens as $i => $t) {
            if ($t[0] === T_STRING && $t[1] === 'Http' && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON) {
                $inicio = $i;
                break;
            }
        }
        if ($inicio === null) {
            return [];
        }

        $metodos = [];
        $nivel = 0;
        for ($i = $inicio + 1; isset($tokens[$i]) && ! ($nivel === 0 && $tokens[$i][1] === ';'); $i++) {
            $valor = $tokens[$i][1];
            if ($valor === '(') {
                $nivel++;
            } elseif ($valor === ')') {
                $nivel--;
            } elseif ($nivel === 0 && in_array($tokens[$i][0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR], true) && ($tokens[$i + 1][0] ?? null) === T_STRING) {
                $metodos[] = strtolower($tokens[$i + 1][1]);
            }
        }

        return $metodos;
    }
}
