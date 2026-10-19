<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioLabOpenAiClient as Cliente;
use App\Services\UltronStudio\StudioLabPrompts as P;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * El cliente propio del laboratorio contra la Responses API (contrato H4 §A5),
 * siempre contra `Http::fake`.
 *
 * LO QUE SE PROTEGE. La URL fija en producción; el cuerpo (`store:false`,
 * formato estricto, `max_output_tokens`, sin `user`, `safety_identifier` ni
 * `metadata`, y `temperature`/`reasoning` solo si están configurados); la
 * cabecera con la clave DEL LABORATORIO y nunca con la de OpenAI del motor;
 * los tiempos (5 s para conectar, el configurado para responder), sin
 * redirecciones ni reintentos; que sin configuración no salga NINGUNA
 * petición; la tabla de A5 completa; y que ningún resultado lleve el cuerpo
 * del proveedor ni la clave.
 */
class StudioLabOpenAiClientTest extends TestCase
{
    private const CLAVE_LAB = 'sk-lab-centinela-ABCD1234';

    private const CLAVE_MOTOR = 'sk-motor-centinela-WXYZ9876';

    private const URL = 'https://api.openai.com/v1/responses';

    protected function setUp(): void
    {
        parent::setUp();
        // La clave del motor y de IRON IA, distinta: el laboratorio no puede tocarla.
        config(['services.openai.api_key' => self::CLAVE_MOTOR]);
        $this->configurar();
    }

    /** @param  array<string,mixed>  $cambios */
    private function configurar(array $cambios = []): void
    {
        config(['ultron_studio.lab' => array_replace_recursive([
            'enabled' => true,
            'driver' => 'openai',
            'openai' => [
                'api_key' => self::CLAVE_LAB,
                'forbidden_key_sha256' => hash('sha256', self::CLAVE_MOTOR),
                'model' => 'gpt-4.1-mini',
                'temperature' => null,
                'reasoning_effort' => null,
                'base_url' => 'http://127.0.0.1:8787',
            ],
            'price_input_usd_per_mtok' => '0.40',
            'price_output_usd_per_mtok' => '1.60',
            'daily_budget_usd' => '2',
            'run_budget_usd' => '0.50',
            'max_runs_per_day' => 20,
            'max_cases_per_run' => 12,
            'actor_max_output_tokens' => 400,
            'judge_max_output_tokens' => 900,
            'timeout_seconds' => 45,
        ], $cambios)]);
    }

    private static function respuesta(string $texto, string $estado = 'completed', array $extra = []): array
    {
        return [
            'id' => 'resp_x', 'object' => 'response', 'status' => $estado, 'error' => null, 'incomplete_details' => null,
            'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
                ['type' => 'message', 'id' => 'msg_1', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $texto, 'annotations' => []]]],
            ],
            'usage' => ['input_tokens' => 1200, 'input_tokens_details' => ['cached_tokens' => 1000], 'output_tokens' => 300, 'output_tokens_details' => ['reasoning_tokens' => 120], 'total_tokens' => 1500],
        ] + $extra;
    }

    /**
     * `Http::fake` acumula los stubs y responde el primero que casa: para
     * cambiar de respuesta hay que empezar con una fábrica nueva.
     */
    private static function soloEsto(mixed $falso): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => $falso]);
    }

    private function actor(): array
    {
        return (new Cliente)->complete('actor', P::ACTOR_INSTRUCTIONS, '{"entrada":"del actor"}', P::actorSchema(), 400);
    }

    public function test_en_produccion_la_url_es_la_oficial_y_el_cuerpo_el_del_contrato(): void
    {
        $this->app['env'] = 'production';
        $opciones = null;
        Http::fake(function (Request $r, array $o) use (&$opciones) {
            $opciones = $o;

            return Http::response(self::respuesta('{"reply":"Hola"}'));
        });

        $r = $this->actor();

        $this->assertSame('ok', $r['status']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $req): bool {
            $this->assertSame('POST', $req->method());
            $this->assertSame(self::URL, $req->url(), 'en producción, siempre la URL oficial aunque haya base configurada');
            $cuerpo = $req->data();
            $this->assertSame(['model', 'store', 'instructions', 'input', 'max_output_tokens', 'text'], array_keys($cuerpo));
            $this->assertSame('gpt-4.1-mini', $cuerpo['model']);
            $this->assertFalse($cuerpo['store'], 'store:false siempre');
            $this->assertSame(P::ACTOR_INSTRUCTIONS, $cuerpo['instructions']);
            $this->assertSame([['role' => 'user', 'content' => '{"entrada":"del actor"}']], $cuerpo['input']);
            $this->assertSame(400, $cuerpo['max_output_tokens']);
            $this->assertSame(['format' => ['type' => 'json_schema', 'name' => 'ultron_lab_actor_v1', 'strict' => true, 'schema' => P::actorSchema()]], $cuerpo['text']);
            foreach (['user', 'safety_identifier', 'metadata', 'temperature', 'reasoning', 'previous_response_id'] as $prohibida) {
                $this->assertArrayNotHasKey($prohibida, $cuerpo);
            }

            return true;
        });

        $this->assertSame(5, $opciones['connect_timeout'], 'connectTimeout(5)');
        $this->assertSame(45, $opciones['timeout'], 'el timeout configurado');
        $this->assertFalse($opciones['allow_redirects'], 'sin redirecciones: la clave no viaja a otro sitio');
    }

    public function test_la_cabecera_lleva_la_clave_del_laboratorio_y_nunca_la_del_motor(): void
    {
        Http::fake(['*' => Http::response(self::respuesta('{"reply":"Hola"}'))]);

        $this->actor();

        Http::assertSent(function (Request $req): bool {
            $this->assertSame(['Bearer '.self::CLAVE_LAB], $req->header('Authorization'));
            $todo = json_encode($req->headers()).$req->body();
            $this->assertStringNotContainsString(self::CLAVE_MOTOR, $todo, 'la clave de OpenAI del motor no sale nunca');

            return true;
        });
    }

    public function test_fuera_de_produccion_se_admite_la_base_del_simulador_si_es_una_url_limpia(): void
    {
        Http::fake(['*' => Http::response(self::respuesta('{"reply":"Hola"}'))]);
        $this->actor();
        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://127.0.0.1:8787/v1/responses');

        foreach (['ftp://127.0.0.1/', 'http://usuario:clave@127.0.0.1', 'http://127.0.0.1/?x=1', 'no es una url', ''] as $mala) {
            $this->configurar(['openai' => ['base_url' => $mala]]);
            $this->assertSame(self::URL, Cliente::url(), $mala);
        }
    }

    public function test_temperatura_y_esfuerzo_solo_si_estan_configurados(): void
    {
        $this->configurar(['openai' => ['temperature' => '0.2', 'reasoning_effort' => 'low']]);
        Http::fake(['*' => Http::response(self::respuesta('{"reply":"Hola"}'))]);

        $this->actor();

        Http::assertSent(function (Request $req): bool {
            $this->assertSame(0.2, $req->data()['temperature']);
            $this->assertSame(['effort' => 'low'], $req->data()['reasoning']);

            return true;
        });
    }

    public function test_sin_configuracion_no_sale_ninguna_peticion(): void
    {
        $casos = [
            'modo de prueba' => ['driver' => 'fake'],
            'driver desconocido' => ['driver' => 'gpt'],
            'sin clave' => ['openai' => ['api_key' => '']],
            'clave compartida con el motor' => ['openai' => ['api_key' => self::CLAVE_MOTOR]],
            'sin modelo' => ['openai' => ['model' => '']],
            'sin precio' => ['price_output_usd_per_mtok' => ''],
            'presupuesto cero' => ['daily_budget_usd' => '0'],
        ];
        Http::fake();
        foreach ($casos as $nombre => $cambios) {
            $this->configurar($cambios);
            $r = $this->actor();
            $this->assertSame('error', $r['status'], $nombre);
            $this->assertSame('not_configured', $r['class'], $nombre);
            $this->assertNull($r['http'], $nombre);
        }
        Http::assertNothingSent();
    }

    public function test_respuesta_buena_salida_y_usage_tal_cual(): void
    {
        Http::fake(['*' => Http::response(self::respuesta('{"reply":"¿En qué te ayudo?"}'))]);

        $r = $this->actor();

        $this->assertSame(['status', 'output', 'usage', 'class', 'http', 'latencyMs', 'exception'], array_keys($r));
        $this->assertSame('ok', $r['status']);
        $this->assertSame(['reply' => '¿En qué te ayudo?'], $r['output']);
        $this->assertSame(['inputTokens' => 1200, 'outputTokens' => 300, 'reasoningTokens' => 120], $r['usage'], 'ni se suma el razonamiento ni se resta la caché');
        $this->assertNull($r['class']);
        $this->assertSame(200, $r['http']);
        $this->assertNull($r['exception']);
    }

    public function test_la_tabla_de_a5_completa(): void
    {
        $error = static fn (string $tipo, ?string $codigo, string $mensaje): array => ['error' => ['message' => $mensaje, 'type' => $tipo, 'param' => null, 'code' => $codigo]];
        $casos = [
            'timeout' => [static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out', $r->toPsrRequest(), null, ['errno' => 28])), 'timeout', null],
            'sin conexión (cURL 7)' => [static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $r->toPsrRequest(), null, ['errno' => 7])), 'connection', null],
            'sin resolver el nombre (failedConnection)' => [Http::failedConnection(), 'connection', null],
            '401' => [Http::response($error('invalid_request_error', 'invalid_api_key', 'Incorrect API key provided: sk-lab-***'), 401), 'auth', 401],
            '403' => [Http::response($error('request_forbidden', 'unsupported_country_region_territory', 'Country not supported'), 403), 'auth', 403],
            '429 insufficient_quota (type)' => [Http::response($error('insufficient_quota', null, 'You exceeded your current quota'), 429), 'quota', 429],
            '429 insufficient_quota (code)' => [Http::response($error('requests', 'insufficient_quota', 'You exceeded your current quota'), 429), 'quota', 429],
            // Punto 21 del README (Enmienda 1): solo insufficient_quota, en code o en type.
            '429 saldo agotado con type insufficient_quota' => [Http::response($error('insufficient_quota', 'credit_balance_exhausted', 'Credit balance exhausted'), 429), 'quota', 429],
            '429 límite de gasto sin insufficient_quota: ritmo' => [Http::response($error('invalid_request_error', 'project_spend_limit_exceeded', 'Project spend limit reached'), 429), 'rate_limited', 429],
            '429 de ritmo' => [Http::response($error('requests', 'rate_limit_exceeded', 'Rate limit reached'), 429), 'rate_limited', 429],
            '429 sin JSON' => [Http::response('Too Many Requests', 429), 'rate_limited', 429],
            '400' => [Http::response($error('invalid_request_error', null, "Invalid schema for response_format 'ultron_lab_actor_v1'"), 400), 'bad_request', 400],
            '404' => [Http::response($error('invalid_request_error', 'model_not_found', 'The model does not exist'), 404), 'bad_request', 404],
            '422' => [Http::response($error('invalid_request_error', null, 'Unprocessable'), 422), 'bad_request', 422],
            '413 (sin fila: otro 4xx)' => [Http::response('Payload Too Large', 413), 'bad_request', 413],
            '408' => [Http::response('Request Timeout', 408), 'server', 408],
            '500' => [Http::response($error('server_error', null, 'The server had an error'), 500), 'server', 500],
            '502 con HTML' => [Http::response('<html><body>502 Bad Gateway</body></html>', 502), 'server', 502],
            '503' => [Http::response($error('server_error', null, 'overloaded'), 503), 'server', 503],
            '200 negativa' => [Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No puedo ayudar con eso.']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]), 'refusal', 200],
            '200 incompleta' => [Http::response(self::respuesta('{"reply":"Hol', 'incomplete', ['incomplete_details' => ['reason' => 'max_output_tokens']])), 'incomplete', 200],
            '200 texto que no es JSON' => [Http::response(self::respuesta('Hola, ¿cómo estás?')), 'invalid_output', 200],
            '200 sin la clave reply' => [Http::response(self::respuesta('{"respuesta":"Hola"}')), 'invalid_output', 200],
            '200 con una clave de más' => [Http::response(self::respuesta('{"reply":"Hola","extra":1}')), 'invalid_output', 200],
            '200 failed' => [Http::response(self::respuesta('{"reply":"Hola"}', 'failed')), 'invalid_output', 200],
            '200 sin mensaje' => [Http::response(['status' => 'completed', 'output' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]), 'invalid_output', 200],
            '200 cuerpo que no es JSON' => [Http::response('<html>ok</html>', 200), 'invalid_output', 200],
        ];

        foreach ($casos as $nombre => [$falso, $clase, $http]) {
            self::soloEsto($falso);
            $r = $this->actor();
            $this->assertSame($clase, $r['class'], $nombre);
            $this->assertSame('error', $r['status'], $nombre);
            $this->assertSame($http, $r['http'], $nombre);
            $this->assertNull($r['output'], $nombre);
            // Nada del proveedor ni de la clave en el resultado.
            $plano = json_encode($r);
            foreach (['Incorrect API key', 'exceeded', 'Bad Gateway', 'No puedo ayudar', 'Hola', 'sk-lab', self::CLAVE_LAB] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $plano, "{$nombre}: el resultado lleva «{$prohibido}»");
            }
        }

        // El usage se conserva en los 200 que cobran (negativa, incompleta, salida no válida).
        self::soloEsto(Http::response(self::respuesta('{"reply":"Hol', 'incomplete')));
        $this->assertSame(['inputTokens' => 1200, 'outputTokens' => 300, 'reasoningTokens' => 120], $this->actor()['usage']);
        self::soloEsto(Http::response('<html>ok</html>', 200));
        $this->assertNull($this->actor()['usage'], 'sin usage legible: null, y el runner cobra el máximo');
    }

    public function test_sin_reintentos_ni_aunque_falle_el_servidor(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'x', 'type' => 'server_error']], 500)]);
        $this->actor();
        Http::assertSentCount(1);

        $codigo = (string) file_get_contents(dirname(__DIR__, 3).'/app/Services/UltronStudio/StudioLabOpenAiClient.php');
        $this->assertStringNotContainsString('->retry(', $codigo);
        $this->assertSame(5, Cliente::CONNECT_TIMEOUT_SECONDS);
    }

    public function test_otro_fallo_del_cliente_http_cuenta_como_timeout_y_solo_se_guarda_su_clase(): void
    {
        Http::fake(fn () => throw new RuntimeException('detalle interno con '.self::CLAVE_LAB));

        $r = $this->actor();

        $this->assertSame('timeout', $r['class'], 'no se sabe si se cobró: la opción que no subestima');
        $this->assertSame('RuntimeException', $r['exception']);
        $this->assertStringNotContainsString(self::CLAVE_LAB, json_encode($r));
    }

    public function test_max_output_tokens_nunca_supera_el_tope_duro_de_su_papel(): void
    {
        Http::fake(['*' => Http::response(self::respuesta('{"reply":"Hola"}'))]);

        (new Cliente)->complete('actor', '', '{}', P::actorSchema(), 5000);
        (new Cliente)->complete('judge', '', '{}', P::judgeSchema(), 5000);
        (new Cliente)->complete('actor', '', '{}', P::actorSchema(), 3);

        $enviados = array_map(static fn (array $par): int => $par[0]->data()['max_output_tokens'], Http::recorded()->all());
        $this->assertSame([800, 1600, 16], $enviados);
    }

    public function test_el_juez_usa_su_esquema_y_su_nombre(): void
    {
        $salida = ['criteria' => [['id' => 'c1', 'verdict' => 'pass', 'evidence' => '', 'reason' => 'ok']], 'rules' => [], 'risks' => [], 'summary' => 'Bien.'];
        Http::fake(['*' => Http::response(self::respuesta(json_encode($salida)))]);

        $r = (new Cliente)->complete('judge', P::JUDGE_INSTRUCTIONS, '{}', P::judgeSchema(), 900);

        $this->assertSame($salida, $r['output']);
        Http::assertSent(fn (Request $req): bool => $req->data()['text']['format']['name'] === 'ultron_lab_judge_v1' && $req->data()['max_output_tokens'] === 900);
    }
}
