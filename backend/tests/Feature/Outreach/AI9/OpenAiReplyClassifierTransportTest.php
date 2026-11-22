<?php

namespace Tests\Feature\Outreach\AI9;

use App\Services\Outreach\Run\Classifier\ReplyClassifierPrompt;
use App\Services\Outreach\Run\Classifier\ReplyClassifierTransport;
use App\Services\Outreach\Run\WhatsappTemplateOutcomes;
use App\Services\ReplyClassification\OpenAiReplyClassifierTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Outreach\AI9\Support\AI9World;
use Tests\TestCase;

/**
 * AI9 · EL TRANSPORTE REAL hacia la Responses API (contrato H9 §4: «OpenAI,
 * para AI9, con `store: false`, solo el texto de la respuesta y sin otros
 * datos de la persona»), con la red fingida por `Http::fake()` y TODO lo
 * demás de verdad: la forma exacta de la petición, la clave PROPIA (la
 * compartida no vale), sin reintentos, la clasificación de cada fallo y la
 * cadena completa W9 → clasificador → este transporte → resultado.
 */
class OpenAiReplyClassifierTransportTest extends TestCase
{
    use AI9World;
    use RefreshDatabase;

    private const NOW = '2026-11-17 15:00:00';

    private const OWN_KEY = 'sk-clasificador-propio-de-prueba';

    private const SHARED_KEY = 'sk-compartida-iron-ia-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true, 'outreach.classifier.api_key' => self::OWN_KEY, 'services.openai.api_key' => self::SHARED_KEY]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Una respuesta `completed` de la Responses API con este JSON como salida. @param  array<string, mixed>|string  $salida @return array<string, mixed> */
    private static function completed(array|string $salida, int $in = 412, int $out = 9): array
    {
        return [
            'id' => 'resp_prueba', 'object' => 'response', 'status' => 'completed',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => is_string($salida) ? $salida : json_encode($salida)]]]],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out, 'output_tokens_details' => ['reasoning_tokens' => 0]],
        ];
    }

    private function classify(string $text = 'No me interesa, gracias'): array
    {
        return (new OpenAiReplyClassifierTransport)->classify(self::MODEL, ReplyClassifierPrompt::INSTRUCTIONS, $text, ReplyClassifierPrompt::schema(), 64, 5);
    }

    public function test_la_peticion_es_exactamente_la_del_contrato_con_store_false_la_clave_propia_y_solo_el_texto(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(self::completed(['label' => 'not_interested', 'confidence' => 93]))]);

        $r = $this->classify('No me interesa, gracias');

        $this->assertNull($r['error']);
        $this->assertSame(['label' => 'not_interested', 'confidence' => 93], $r['output']);
        $this->assertSame(['inputTokens' => 412, 'outputTokens' => 9], $r['usage']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $req): bool {
            $this->assertSame('POST', $req->method());
            $this->assertSame('https://api.openai.com/v1/responses', $req->url());
            $this->assertSame(['Bearer '.self::OWN_KEY], $req->header('Authorization'), 'la clave PROPIA del clasificador');
            $this->assertSame([
                'model' => self::MODEL,
                'store' => false,
                'instructions' => ReplyClassifierPrompt::INSTRUCTIONS,
                'input' => [['role' => 'user', 'content' => 'No me interesa, gracias']],
                'max_output_tokens' => 64,
                'text' => ['format' => ['type' => 'json_schema', 'name' => ReplyClassifierPrompt::SCHEMA_NAME, 'strict' => true, 'schema' => ReplyClassifierPrompt::schema()]],
            ], $req->data(), 'ni `user`, ni `metadata`, ni `safety_identifier`, ni herramientas: nada más');

            return true;
        });
    }

    public function test_sin_clave_propia_o_con_la_compartida_no_hay_peticion(): void
    {
        Http::fake();
        config(['outreach.classifier.api_key' => '']);
        $this->assertFalse((new OpenAiReplyClassifierTransport)->configured());
        $this->assertSame('not_configured', $this->classify()['error']);

        config(['outreach.classifier.api_key' => '  '.self::SHARED_KEY.' ']);
        $this->assertFalse((new OpenAiReplyClassifierTransport)->configured(), 'la clave compartida de la IA del CRM no vale');
        $this->assertSame('not_configured', $this->classify()['error']);

        config(['outreach.classifier.api_key' => self::OWN_KEY]);
        $this->assertTrue((new OpenAiReplyClassifierTransport)->configured());
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{string}> */
    public static function clavesAjenas(): iterable
    {
        foreach (OpenAiReplyClassifierTransport::FOREIGN_KEYS as $ruta) {
            yield $ruta => [$ruta];
        }
    }

    /**
     * M4 de security: ninguna clave de OpenAI que ya use otra parte del
     * sistema vale como la del clasificador (compartiría proyecto y
     * presupuesto), también con espacios alrededor; y con otra distinta en
     * ese sitio, la propia sí vale (la comparación no es un «no» fijo).
     */
    #[DataProvider('clavesAjenas')]
    public function test_con_la_clave_de_otra_parte_del_sistema_no_esta_configurado_y_no_sale_nada(string $ruta): void
    {
        Http::fake();
        config(['services.openai.api_key' => null, 'iron_crm.api_key' => null, OpenAiReplyClassifierTransport::FOREIGN_KEY_HASHES => []]);
        config([$ruta => ' '.self::OWN_KEY."\n"]);

        $this->assertFalse((new OpenAiReplyClassifierTransport)->configured(), "con la clave de {$ruta}");
        $r = $this->classify();
        $this->assertSame(['not_configured', null, null], [$r['error'], $r['output'], $r['usage']]);
        $this->assertStringNotContainsString(self::OWN_KEY, (string) json_encode($r), 'no devuelve la clave');

        config([$ruta => 'sk-otra-clave-de-'.str_replace('.', '-', $ruta)]);
        $this->assertTrue((new OpenAiReplyClassifierTransport)->configured(), 'con otra distinta en ese sitio, la propia vale');
        Http::assertNothingSent();
    }

    /**
     * M4: la clave del laboratorio del Studio, de punta a punta desde el
     * ENTORNO. `config/outreach.php` se vuelve a evaluar con
     * `ULTRON_STUDIO_LAB_OPENAI_API_KEY` y lleva su huella, nunca la clave.
     * Con la misma que la del clasificador (también con espacios), no está
     * configurado; con otra, sí.
     */
    public function test_con_la_clave_del_laboratorio_del_studio_no_esta_configurado_y_la_configuracion_solo_lleva_su_huella(): void
    {
        Http::fake();
        config(['services.openai.api_key' => null, 'iron_crm.api_key' => null]);

        $huellas = self::outreachConfigWithEnv('ULTRON_STUDIO_LAB_OPENAI_API_KEY', ' '.self::OWN_KEY."\n")['classifier']['foreign_key_sha256'];
        $this->assertSame([hash('sha256', self::OWN_KEY)], $huellas);
        $this->assertStringNotContainsString(self::OWN_KEY, (string) json_encode($huellas), 'la configuración lleva la huella, no la clave');
        config([OpenAiReplyClassifierTransport::FOREIGN_KEY_HASHES => $huellas]);
        $this->assertFalse((new OpenAiReplyClassifierTransport)->configured());
        $this->assertSame('not_configured', $this->classify()['error']);

        config([OpenAiReplyClassifierTransport::FOREIGN_KEY_HASHES => self::outreachConfigWithEnv('ULTRON_STUDIO_LAB_OPENAI_API_KEY', 'sk-otra-del-laboratorio')['classifier']['foreign_key_sha256']]);
        $this->assertTrue((new OpenAiReplyClassifierTransport)->configured(), 'con otra en el laboratorio, la propia vale');
        $this->assertSame([], self::outreachConfigWithEnv('ULTRON_STUDIO_LAB_OPENAI_API_KEY', '  ')['classifier']['foreign_key_sha256'], 'sin clave del laboratorio, ninguna huella');
        Http::assertNothingSent();
    }

    /** `config/outreach.php` evaluado con una variable de entorno puesta, y el entorno como estaba. @return array<string, mixed> */
    private static function outreachConfigWithEnv(string $variable, string $valor): array
    {
        $antes = [$_SERVER[$variable] ?? null, $_ENV[$variable] ?? null, getenv($variable)];
        $_SERVER[$variable] = $_ENV[$variable] = $valor;
        putenv("{$variable}={$valor}");
        try {
            return (static fn (string $f): array => require $f)(config_path('outreach.php'));
        } finally {
            [$servidor, $entorno, $proceso] = $antes;
            if ($servidor === null) {
                unset($_SERVER[$variable]);
            } else {
                $_SERVER[$variable] = $servidor;
            }
            if ($entorno === null) {
                unset($_ENV[$variable]);
            } else {
                $_ENV[$variable] = $entorno;
            }
            putenv($proceso === false ? $variable : "{$variable}={$proceso}");
        }
    }

    /** @return iterable<string, array{int, mixed, string}> */
    public static function fallos(): iterable
    {
        yield '401' => [401, ['error' => ['code' => 'invalid_api_key']], 'auth'];
        yield '403' => [403, null, 'auth'];
        yield '429 de ritmo' => [429, ['error' => ['code' => 'rate_limit_exceeded']], 'rate_limited'];
        yield '429 de cuota' => [429, ['error' => ['code' => 'insufficient_quota']], 'quota'];
        yield '400' => [400, ['error' => ['message' => 'Invalid schema']], 'bad_request'];
        yield '500' => [500, null, 'server'];
        yield '503' => [503, 'no es json', 'server'];
        yield 'incompleta' => [200, ['status' => 'incomplete', 'output' => [], 'usage' => ['input_tokens' => 400, 'output_tokens' => 64]], 'incomplete'];
        yield 'negativa' => [200, ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No.']]]]], 'refusal'];
        yield 'sin salida' => [200, ['status' => 'completed', 'output' => []], 'invalid_output'];
        yield 'texto que no es JSON' => [200, self::completed('not_interested'), 'invalid_output'];
        yield 'fallida' => [200, ['status' => 'failed'], 'invalid_output'];
    }

    #[DataProvider('fallos')]
    public function test_cada_fallo_es_una_clase_cerrada_sin_el_cuerpo_del_proveedor_y_sin_reintento(int $http, mixed $cuerpo, string $clase): void
    {
        Http::fake(['api.openai.com/*' => Http::response($cuerpo, $http)]);

        $r = $this->classify();

        $this->assertSame($clase, $r['error']);
        $this->assertContains($clase, ReplyClassifierTransport::ERRORS);
        $this->assertNull($r['output']);
        $this->assertStringNotContainsString('Invalid schema', (string) json_encode($r), 'nunca el texto del proveedor');
        Http::assertSentCount(1);
    }

    public function test_sin_conexion_no_salio_y_un_tiempo_agotado_pudo_cobrarse(): void
    {
        // Un solo fake (los stubs se suman y gana el primero): la primera llamada no conecta y la segunda agota el tiempo.
        $errores = ['cURL error 7: Failed to connect', 'cURL error 28: Operation timed out after 5001 milliseconds'];
        Http::fake(['api.openai.com/*' => static function () use (&$errores): never {
            throw new ConnectionException((string) array_shift($errores));
        }]);
        $this->assertSame('connection', $this->classify()['error']);
        $this->assertContains('connection', ReplyClassifierTransport::NOT_SENT);

        $this->assertSame('timeout', $this->classify()['error']);
        $this->assertNotContains('timeout', ReplyClassifierTransport::NOT_SENT);
    }

    public function test_el_transporte_no_usa_retry_ni_otra_url_que_la_oficial(): void
    {
        $codigo = (string) file_get_contents(app_path('Services/ReplyClassification/OpenAiReplyClassifierTransport.php'));
        $this->assertStringNotContainsString('->retry(', $codigo);
        $this->assertSame(1, substr_count($codigo, "'https://api.openai.com/v1/responses'"), 'la URL fija, una vez');
        $this->assertStringNotContainsString('base_url', $codigo, 'sin base configurable');
        foreach (["'user' =>", "'metadata'", "'safety_identifier'", "'previous_response_id'", "'tools'"] as $campo) {
            $this->assertStringNotContainsString($campo, $codigo, "nunca {$campo}");
        }
    }

    /**
     * LA CADENA COMPLETA con el transporte real enlazado como lo haría el
     * proveedor de servicios: la plantilla sale (Meta falso), la persona
     * contesta, la pasada de W9 registra `replied`, el clasificador llama por
     * ESTE transporte (OpenAI falso) y el freno entra por la ingesta.
     */
    public function test_de_extremo_a_extremo_por_el_transporte_real_con_la_red_fingida(): void
    {
        $this->threePeople();
        $this->classifierOn(new OpenAiReplyClassifierTransport);
        // Antes de que salga la plantilla: `Http::fake()` borra lo grabado cada vez que se llama (el Graph falso se suma después).
        Http::fake(['api.openai.com/*' => Http::response(self::completed(['label' => 'wrong_person', 'confidence' => 97], 380, 9))]);
        $s = $this->sentTemplate();
        $this->inbound($s['member']['lead'], 'Se equivocaron, este no es el número de esa persona. Escríbanle al 3109876543');

        $r = (new WhatsappTemplateOutcomes)->run(now());

        $this->assertSame(['replied' => 1, 'wrong_person' => 1], $r['recorded']);
        $f = DB::table('outreach_outcomes')->where('source', 'classifier')->sole();
        $this->assertSame(['wrong_person', 'end_exit'], [$f->outcome, $f->next_action]);
        Http::assertSent(static fn (Request $req): bool => str_starts_with($req->url(), 'https://graph.facebook.com/'));
        Http::assertSent(function (Request $req): bool {
            if ($req->url() !== 'https://api.openai.com/v1/responses') {
                return false;
            }
            $this->assertFalse($req->data()['store']);
            $this->assertSame([['role' => 'user', 'content' => 'Se equivocaron, este no es el número de esa persona. Escríbanle al [número]']], $req->data()['input']);
            $this->assertStringNotContainsString('3109876543', $req->body());
            $this->assertStringNotContainsString('3001234567', $req->body(), 'ni el teléfono de la persona');

            return true;
        });
        // Lo gastado de verdad, según el `usage`: 380 de entrada y 9 de salida a 0,40 y 1,60 US$ por millón.
        $this->assertSame(152 + 15, (int) $this->classifierCounter()->reserved_micros);
    }
}
