<?php

namespace Tests\Unit\Meta\Templates;

use App\Services\Meta\MetaAuthService;
use App\Services\Meta\Templates\GraphWhatsappTemplateClient as Graph;
use App\Services\Meta\Templates\TemplateApiErrors as E;
use App\Services\Meta\Templates\TemplateApiResult as R;
use App\Services\Meta\Templates\TemplateRequestGuard;
use App\Services\Meta\Templates\WhatsappTemplateClient;
use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * PLANTILLAS DE META · PASO 1c (PLANTILLAS_META_PLAN §5.8.4; L9-96): el cliente
 * NEUTRO de Graph contra `Http::fake`. Cero red: `preventStrayRequests()`.
 *
 *  - el cuerpo EXACTO que sale, sus cabeceras (el token solo en `Authorization`),
 *    la versión `v25.0` propia (no la de `META_GRAPH_VERSION`), la base fija en
 *    producción y la del simulador fuera (nunca graph.facebook.com);
 *  - cada error de Meta normalizado en su clase cerrada, y el «desconocido» de un
 *    POST que no se sabe si llegó (tiempo agotado, 5xx, 2xx ilegible);
 *  - UNA sola petición por POST, pase lo que pase (nunca se reintenta);
 *  - la paginación por cursor (nunca la URL `next`) y sus topes;
 *  - los ids que no son dígitos no llegan a la ruta;
 *  - la credencial (un centinela) no aparece en ningún resultado, registro, URL
 *    ni mensaje de excepción.
 */
class GraphWhatsappTemplateClientTest extends TestCase
{
    private const TOKEN = 'EAAcentinelaTOKENplantillas9f8e7d6c5b4a3210ZZ';

    private const WABA = '102938475610293';

    private const SIMULADOR = 'http://127.0.0.1:8097';

    /** @var list<MessageLogged> */
    private array $registros = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // El canal de WhatsApp a ninguna parte (no escribe ficheros): las líneas se leen por su evento.
        config(['logging.channels.channel' => ['driver' => 'monolog', 'handler' => NullHandler::class], 'meta.graph_version' => 'v21.0']);
        $this->registros = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->registros[] = $m;
        });
    }

    protected function tearDown(): void
    {
        // Ninguna prueba deja el centinela en un registro.
        foreach ($this->registros as $m) {
            $this->assertStringNotContainsString(self::TOKEN, $m->message.json_encode($m->context), 'el token apareció en un registro');
        }
        parent::tearDown();
    }

    private function auth(?string $token = self::TOKEN): MetaAuthService
    {
        return new class($token) extends MetaAuthService
        {
            public function __construct(private readonly ?string $t) {}

            public function accessToken(): ?string
            {
                return $this->t;
            }
        };
    }

    private function cliente(?string $token = self::TOKEN, ?string $base = self::SIMULADOR, string $version = Graph::DEFAULT_GRAPH_VERSION): Graph
    {
        return new Graph($this->auth($token), $version, $base);
    }

    /** @return array<string, mixed> */
    private static function plantilla(array $cambios = []): array
    {
        return array_replace([
            'name' => 'renovacion_aviso_v2',
            'language' => 'es_CO',
            'category' => 'MARKETING',
            'parameter_format' => 'POSITIONAL',
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Tu plan está por vencer'],
                ['type' => 'BODY', 'text' => 'Hola {{1}}, tu plan vence pronto. Responde BAJA para no recibir más mensajes.', 'example' => ['body_text' => [['Laura']]]],
                ['type' => 'FOOTER', 'text' => 'Escribe BAJA'],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Quiero renovar'], ['type' => 'URL', 'text' => 'Ver planes', 'url' => 'https://ironbodyneiva.cloud/planes']]],
            ],
        ], $cambios);
    }

    /** Meta de mentira: una respuesta (o un cierre) para cualquier petición. */
    private static function meta(mixed $respuesta): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => $respuesta]);
    }

    private static function error(int $estado, ?int $code, ?int $subcode = null, array $mas = []): Closure
    {
        $error = array_filter(array_replace(['message' => 'Developer message with access_token='.self::TOKEN, 'type' => 'OAuthException', 'code' => $code, 'error_subcode' => $subcode, 'fbtrace_id' => 'AbCdEf123_-x'], $mas), static fn ($v) => $v !== null);

        return static fn () => Http::response(['error' => $error], $estado);
    }

    private static function tiempoAgotado(): Closure
    {
        return static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 28: Operation timed out after 15001 milliseconds for '.$r->url(), $r->toPsrRequest(), null, ['errno' => 28]));
    }

    /** Nada de lo que sale del cliente lleva el centinela (y, fuera de producción, tampoco la cabecera: PM-1). */
    private function sinToken(R $r): void
    {
        $this->assertStringNotContainsString(self::TOKEN, json_encode($r->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        foreach (Http::recorded() as [$peticion]) {
            $this->assertStringNotContainsString(self::TOKEN, $peticion->url(), 'el token en la URL');
            $this->assertStringNotContainsString(self::TOKEN, $peticion->body(), 'el token en el cuerpo');
            if (! app()->isProduction()) {
                $this->assertSame(['Bearer '.Graph::SIMULATOR_BEARER], $peticion->header('Authorization'), 'al simulador, solo el portador fijo');
            }
        }
    }

    // ── Crear ───────────────────────────────────────────────────────────────

    public function test_crear_envia_el_cuerpo_exacto_con_sus_cabeceras_a_la_v25_del_simulador_una_sola_vez(): void
    {
        self::meta(Http::response(['id' => '1234567890123456', 'status' => 'PENDING', 'category' => 'MARKETING']));

        $r = $this->cliente()->create(self::WABA, self::plantilla());

        $this->assertTrue($r->ok());
        $this->assertSame('1234567890123456', $r->createdId);
        $this->assertSame('PENDING', $r->createdStatus);
        $this->assertSame('MARKETING', $r->createdCategory);
        $this->assertSame(1, $r->requests);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $p): bool {
            $this->assertSame('POST', $p->method());
            $this->assertSame(self::SIMULADOR.'/v25.0/'.self::WABA.'/message_templates', $p->url(), 'la versión propia, no la de META_GRAPH_VERSION (v21.0)');
            $this->assertSame(TemplateRequestGuard::payload(self::plantilla()), $p->body(), 'el cuerpo, byte a byte');
            $this->assertStringContainsString('"text":"Tu plan está por vencer"', $p->body(), 'UTF-8 sin escapar');
            $this->assertStringContainsString('https://ironbodyneiva.cloud/planes', $p->body(), 'barras sin escapar');
            $this->assertSame(['Bearer '.Graph::SIMULATOR_BEARER], $p->header('Authorization'), 'fuera de producción, el portador fijo del simulador (PM-1)');
            $this->assertSame(['application/json'], $p->header('Accept'));
            $this->assertStringStartsWith('application/json', $p->header('Content-Type')[0] ?? '');

            return true;
        });
        $this->sinToken($r);
    }

    public function test_un_id_numerico_de_meta_tambien_vale_y_uno_ilegible_deja_el_post_desconocido(): void
    {
        self::meta(Http::response(['id' => 987654321, 'status' => 'APPROVED']));
        $r = $this->cliente()->create(self::WABA, self::plantilla());
        $this->assertTrue($r->ok());
        $this->assertSame('987654321', $r->createdId);
        $this->assertNull($r->createdCategory);

        foreach (['sin id' => ['status' => 'PENDING'], 'id con letras' => ['id' => '12ab'], 'id negativo' => ['id' => -5]] as $caso => $cuerpo) {
            self::meta(Http::response($cuerpo));
            $r = $this->cliente()->create(self::WABA, self::plantilla());
            $this->assertTrue($r->unknown(), $caso);
            $this->assertSame([E::INVALID_RESPONSE, 'unexpected_shape'], [$r->error, $r->detail], $caso);
            Http::assertSentCount(1);
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: string, 3: ?string, 4: ?int, 5: ?int}>
     *                                                                                            respuesta, desenlace, clase, detalle, código, subcódigo
     */
    public static function erroresDeCrear(): array
    {
        return [
            'nombre duplicado 100/2388024' => [self::error(400, 100, 2388024, ['error_user_title' => 'Content in this language already exists', 'error_user_msg' => 'Ya hay una plantilla con este nombre.']), R::FAILED, E::DUPLICATE_NAME, null, 100, 2388024],
            'otro 100 (validación)' => [self::error(400, 100, 2388043), R::FAILED, E::VALIDATION, null, 100, 2388043],
            '131009 (parámetro)' => [self::error(400, 131009), R::FAILED, E::VALIDATION, null, 131009, null],
            '200 (permiso)' => [self::error(403, 200), R::FAILED, E::PERMISSION, null, 200, null],
            '10 (permiso)' => [self::error(403, 10), R::FAILED, E::PERMISSION, null, 10, null],
            '403 sin código' => [static fn () => Http::response('forbidden', 403), R::FAILED, E::PERMISSION, null, null, null],
            '190 (token)' => [self::error(401, 190, 463), R::FAILED, E::AUTH, null, 190, 463],
            '401 sin código' => [static fn () => Http::response('', 401), R::FAILED, E::AUTH, null, null, null],
            '4 (ritmo)' => [self::error(400, 4), R::FAILED, E::RATE_LIMITED, null, 4, null],
            '80008 (ritmo de la WABA)' => [self::error(400, 80008), R::FAILED, E::RATE_LIMITED, null, 80008, null],
            '429 sin código' => [static fn () => Http::response('slow down', 429), R::FAILED, E::RATE_LIMITED, null, null, null],
            '368 (bloqueo de política)' => [self::error(400, 368), R::FAILED, E::REJECTED, null, 368, null],
            '500 de Meta: desconocido' => [self::error(500, 1), R::UNKNOWN, E::SERVER, null, 1, null],
            '503 en HTML: desconocido' => [static fn () => Http::response('<html>Service Unavailable</html>', 503), R::UNKNOWN, E::SERVER, null, null, null],
            'is_transient en un 400: desconocido' => [self::error(400, 9999, null, ['is_transient' => true]), R::UNKNOWN, E::SERVER, null, 9999, null],
            '2xx que no es JSON: desconocido' => [static fn () => Http::response('<html>ok</html>', 200), R::UNKNOWN, E::INVALID_RESPONSE, 'not_json', null, null],
            '2xx con una lista: desconocido' => [static fn () => Http::response([1, 2], 200), R::UNKNOWN, E::INVALID_RESPONSE, 'unexpected_shape', null, null],
            '2xx con error dentro' => [static fn () => Http::response(['error' => ['code' => 100, 'error_subcode' => 2388024]], 200), R::FAILED, E::DUPLICATE_NAME, null, 100, 2388024],
            'redirección: no se sigue' => [static fn () => Http::response('', 302, ['Location' => 'https://evil.example/steal']), R::FAILED, E::INVALID_RESPONSE, 'redirect', null, null],
            '400 ilegible' => [static fn () => Http::response('???', 400), R::FAILED, E::INVALID_RESPONSE, 'not_json', null, null],
            'tiempo agotado (cURL 28): desconocido' => [self::tiempoAgotado(), R::UNKNOWN, E::TRANSPORT, 'maybe_sent', null, null],
            'conexión cortada (cURL 56): desconocido' => [static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 56: Recv failure: Connection reset by peer', $r->toPsrRequest(), null, ['errno' => 56])), R::UNKNOWN, E::TRANSPORT, 'maybe_sent', null, null],
            'no resolvió el host (cURL 6): no salió' => [Factory::failedConnection(), R::FAILED, E::TRANSPORT, 'not_sent', null, null],
            'no conectó (cURL 7): no salió' => [static fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 7: Failed to connect', $r->toPsrRequest(), null, ['errno' => 7])), R::FAILED, E::TRANSPORT, 'not_sent', null, null],
        ];
    }

    #[DataProvider('erroresDeCrear')]
    public function test_cada_error_al_crear_se_normaliza_y_el_post_nunca_se_repite(mixed $respuesta, string $desenlace, string $clase, ?string $detalle, ?int $codigo, ?int $subcodigo): void
    {
        self::meta($respuesta);

        $r = $this->cliente()->create(self::WABA, self::plantilla());

        $this->assertSame($desenlace, $r->outcome);
        $this->assertSame($clase, $r->error);
        $this->assertSame($detalle, $r->detail);
        $this->assertSame($codigo, $r->metaCode);
        $this->assertSame($subcodigo, $r->metaSubcode);
        $this->assertNull($r->createdId);
        $this->assertFalse($r->retryable() && $r->unknown(), 'un POST desconocido nunca es «reintentable»');
        Http::assertSentCount(1);
        $this->sinToken($r);
    }

    public function test_del_error_de_meta_solo_quedan_codigos_y_el_texto_para_el_usuario_saneado(): void
    {
        self::meta(self::error(400, 100, 2388024, [
            'error_user_title' => "Nombre\u{200B} repetido\x07",
            'error_user_msg' => 'Ya existe. Token '.self::TOKEN.' y Bearer abc.def, access_token=XYZ123 en la cuenta 102938475610293. '.str_repeat('largo ', 80),
            'fbtrace_id' => 'Ab12_-Cd',
        ]));

        $r = $this->cliente()->create(self::WABA, self::plantilla());

        $this->assertSame('Ab12_-Cd', $r->fbtraceId);
        $this->assertSame('Nombre repetido', $r->userTitle);
        $this->assertNotNull($r->userMessage);
        $this->assertLessThanOrEqual(E::USER_MESSAGE_MAX, mb_strlen($r->userMessage));
        $this->assertStringContainsString('[token]', $r->userMessage);
        $this->assertStringContainsString('Bearer [redactado]', $r->userMessage);
        $this->assertStringContainsString('access_token=[redactado]', $r->userMessage);
        $this->assertStringContainsString('***0293', $r->userMessage, 'los números largos, por sus 4 últimas cifras');
        $this->assertStringNotContainsString('102938475610293', $r->userMessage);
        $this->assertStringEndsWith('…', $r->userMessage);
        $this->assertStringNotContainsString('Developer message', json_encode($r->toArray()), 'el `message` para desarrolladores nunca se guarda');
        $this->sinToken($r);

        // Un fbtrace_id con otra forma no se guarda.
        self::meta(self::error(400, 100, null, ['fbtrace_id' => 'con espacios y <script>']));
        $this->assertNull($this->cliente()->create(self::WABA, self::plantilla())->fbtraceId);
    }

    // ── Dónde y con qué ─────────────────────────────────────────────────────

    public function test_en_produccion_la_base_es_graph_facebook_com_y_se_ignora_la_del_simulador(): void
    {
        $this->app['env'] = 'production';
        self::meta(Http::response(['id' => '1', 'status' => 'PENDING', 'category' => 'UTILITY']));

        $this->assertTrue($this->cliente()->create(self::WABA, self::plantilla())->ok());
        Http::assertSent(fn (Request $p): bool => $p->url() === 'https://graph.facebook.com/v25.0/'.self::WABA.'/message_templates'
            && $p->header('Authorization') === ['Bearer '.self::TOKEN]);

        // Sin base del simulador también: en producción no hace falta.
        self::meta(Http::response(['data' => []]));
        $this->assertTrue($this->cliente(base: null)->list(self::WABA, ['id'])->ok());
        Http::assertSent(fn (Request $p): bool => str_starts_with($p->url(), 'https://graph.facebook.com/v25.0/'.self::WABA.'/message_templates?')
            && $p->header('Authorization') === ['Bearer '.self::TOKEN]);
        Http::assertNotSent(fn (Request $p): bool => $p->header('Authorization') === ['Bearer '.Graph::SIMULATOR_BEARER]);
    }

    public function test_fuera_de_produccion_sin_simulador_o_con_un_host_de_meta_no_sale_a_la_red(): void
    {
        foreach ([null, '', 'https://graph.facebook.com', 'https://GRAPH.facebook.com.', 'https://x.whatsapp.net/', 'ftp://127.0.0.1', 'http://u:p@127.0.0.1:8097', 'http://127.0.0.1:8097/?q=1', 'no es una url',
            // PM-1: sin TLS, solo 127.0.0.1 y localhost.
            'http://192.168.1.20:8097', 'http://simulador.interno:8097', 'http://127.0.0.1.nip.io:8097', 'http://localhost.evil.example', 'http://[::1]:8097', 'http://10.0.0.5'] as $base) {
            self::meta(Http::response(['id' => '1']));
            $cliente = $this->cliente(base: $base);
            $this->assertSame('no_simulator', $cliente->configurationProblem(), var_export($base, true));
            $r = $cliente->create(self::WABA, self::plantilla());
            $this->assertSame([R::FAILED, E::NOT_CONFIGURED, 'no_simulator', 0], [$r->outcome, $r->error, $r->detail, $r->requests], var_export($base, true));
            Http::assertNothingSent();
        }
        $this->assertTrue(Graph::isMetaHost('graph.facebook.com'));
        $this->assertTrue(Graph::isMetaHost('Graph.FaceBook.com.'));
        $this->assertFalse(Graph::isMetaHost('notfacebook.com'));

        // Lo que sí vale como simulador: https a cualquier host que no sea de Meta, o http a 127.0.0.1 / localhost.
        foreach (['http://127.0.0.1:8097', 'http://localhost:8097/', 'http://LOCALHOST', 'https://simulador.interno:8443', 'https://127.0.0.1'] as $base) {
            $this->assertNull($this->cliente(base: $base)->configurationProblem(), $base);
        }
    }

    public function test_pm1_fuera_de_produccion_ninguna_peticion_lleva_el_token_real_en_la_cabecera(): void
    {
        $this->assertFalse(app()->isProduction(), 'APP_ENV=testing');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $p) {
            $ruta = (string) parse_url($p->url(), PHP_URL_PATH);

            return match (true) {
                $p->method() === 'POST' => Http::response(['id' => '1234567890123456', 'status' => 'PENDING']),
                str_ends_with($ruta, '/me/permissions') => Http::response(['data' => [['permission' => 'whatsapp_business_management', 'status' => 'granted']]]),
                str_ends_with($ruta, '/message_templates') => Http::response(['data' => [['id' => '555', 'name' => 'renovacion_aviso_v2', 'language' => 'es_CO']]]),
                default => Http::response(['error' => ['code' => 190, 'error_user_msg' => 'Token '.self::TOKEN.' inválido']], 401),
            };
        });

        foreach ([self::SIMULADOR, 'http://localhost:8097', 'https://simulador.interno:8443'] as $base) {
            $cliente = $this->cliente(base: $base);
            $resultados = [
                $cliente->create(self::WABA, self::plantilla()),
                $cliente->list(self::WABA, ['id']),
                $cliente->findByName(self::WABA, 'renovacion_aviso_v2', 'es_CO', ['status']),
                $cliente->find('555', ['status']),
                $cliente->permissions(),
            ];
            $this->assertSame([true, true, true, false, true], array_map(static fn (R $r): bool => $r->ok(), $resultados), $base);
            foreach ($resultados as $r) {
                $this->sinToken($r);
            }
        }

        $this->assertCount(15, Http::recorded());
        foreach (Http::recorded() as [$peticion]) {
            $cabecera = implode(',', $peticion->header('Authorization'));
            $this->assertStringNotContainsString(self::TOKEN, $cabecera, 'el token real viajó al simulador');
            $this->assertSame('Bearer '.Graph::SIMULATOR_BEARER, $cabecera);
        }
    }

    public function test_sin_token_o_con_un_token_que_no_cabe_en_una_cabecera_no_se_llama(): void
    {
        foreach (['' => 'missing_token', '   ' => 'missing_token', "EAA123\nX-Inyectada: 1" => 'malformed_token', 'EAA con espacio' => 'malformed_token'] as $token => $motivo) {
            self::meta(Http::response(['data' => []]));
            $cliente = $this->cliente(token: $token);
            $this->assertSame($motivo, $cliente->configurationProblem());
            foreach ([$cliente->list(self::WABA, ['id']), $cliente->permissions(), $cliente->find('123', ['status'])] as $r) {
                $this->assertSame([E::NOT_CONFIGURED, $motivo, 0], [$r->error, $r->detail, $r->requests]);
            }
            Http::assertNothingSent();
        }
        $this->assertSame('missing_token', $this->cliente(token: null)->configurationProblem());
    }

    public function test_la_version_de_graph_es_la_propia_y_se_valida(): void
    {
        $this->assertSame('v25.0', Graph::DEFAULT_GRAPH_VERSION);
        $this->assertSame('v25.0', $this->cliente()->graphVersion());
        self::meta(Http::response(['data' => []]));
        $this->cliente(version: 'v26.0')->list(self::WABA, ['id']);
        Http::assertSent(fn (Request $p): bool => str_starts_with($p->url(), self::SIMULADOR.'/v26.0/'));

        $mensajes = [];
        foreach (['25.0', 'v25', 'v25.0/../x', "v25.0\n", 'v0.1', 'v2x.0'] as $mala) {
            try {
                $this->cliente(version: $mala);
                $this->fail("aceptó la versión {$mala}");
            } catch (InvalidArgumentException $e) {
                $mensajes[] = $e->getMessage();
            }
        }
        $this->assertCount(1, array_unique($mensajes), 'el mensaje es fijo: nunca repite lo recibido');
    }

    public function test_los_ids_y_las_entradas_que_no_valen_no_llegan_a_la_ruta_ni_se_repiten_en_el_mensaje(): void
    {
        self::meta(Http::response(['data' => []]));
        $cliente = $this->cliente();
        $intentos = [
            'waba con letras' => fn () => $cliente->list('123abc', ['id']),
            'waba con salto final' => fn () => $cliente->list("123\n", ['id']),
            'waba con barra' => fn () => $cliente->create('123/../me', self::plantilla()),
            'waba = el token' => fn () => $cliente->create(self::TOKEN, self::plantilla()),
            'plantilla con letras' => fn () => $cliente->find('12?fields=x', ['id']),
            'plantilla = el token' => fn () => $cliente->find(self::TOKEN, ['id']),
            'campo desconocido' => fn () => $cliente->find('123', ['status', 'access_token']),
            'campos que no son lista' => fn () => $cliente->find('123', ['a' => 'status']),
            'página de 0' => fn () => $cliente->list(self::WABA, ['id'], null, 0),
            'página de 101' => fn () => $cliente->list(self::WABA, ['id'], null, 101),
            'cursor con otra forma' => fn () => $cliente->list(self::WABA, ['id'], 'abc&access_token=x'),
            'nombre con mayúsculas' => fn () => $cliente->findByName(self::WABA, 'Renovacion', null, ['status']),
            'idioma raro' => fn () => $cliente->findByName(self::WABA, 'renovacion', 'es-CO', ['status']),
            'plantilla sin componentes' => fn () => $cliente->create(self::WABA, self::plantilla(['components' => []])),
            'plantilla con una clave de más' => fn () => $cliente->create(self::WABA, self::plantilla(['access_token' => self::TOKEN])),
            'categoría en minúsculas' => fn () => $cliente->create(self::WABA, self::plantilla(['category' => 'marketing'])),
            'nombre que es el token' => fn () => $cliente->create(self::WABA, self::plantilla(['name' => self::TOKEN])),
            'componente sin tipo' => fn () => $cliente->create(self::WABA, self::plantilla(['components' => [['text' => 'x']]])),
            'texto que no es UTF-8' => fn () => $cliente->create(self::WABA, self::plantilla(['components' => [['type' => 'BODY', 'text' => "\xB1\x31"]]])),
        ];
        foreach ($intentos as $caso => $intento) {
            try {
                $intento();
                $this->fail("{$caso}: no lanzó");
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString(self::TOKEN, $e->getMessage(), $caso);
                $this->assertStringNotContainsString('123abc', $e->getMessage(), $caso);
            }
        }
        Http::assertNothingSent();
    }

    // ── Leer ────────────────────────────────────────────────────────────────

    public function test_find_pide_sus_campos_y_normaliza_la_plantilla(): void
    {
        self::meta(Http::response([
            'id' => '555', 'name' => 'renovacion_aviso_v2', 'language' => 'es_CO', 'status' => 'approved', 'category' => 'MARKETING',
            'quality_score' => ['score' => 'green', 'date' => 1700000000], 'rejected_reason' => 'NONE',
            'components' => [
                ['type' => 'header', 'format' => 'text', 'text' => 'Tu plan', 'example' => ['header_text' => ['x']]],
                ['type' => 'BODY', 'text' => "Hola {{1}}\n", 'example' => ['body_text' => [['Laura']]]],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'phone_number', 'text' => 'Llamar', 'phone_number' => '+57 314 3455483', 'raro' => 1]]],
            ],
        ]));

        $r = $this->cliente()->find('555', ['status', 'components', 'quality_score', 'status']);

        $this->assertTrue($r->ok());
        Http::assertSent(function (Request $p): bool {
            $this->assertSame('GET', $p->method());
            [$ruta, $consulta] = explode('?', $p->url(), 2);
            $this->assertSame(self::SIMULADOR.'/v25.0/555', $ruta);
            parse_str($consulta, $q);
            $this->assertSame(['fields' => 'id,status,components,quality_score'], $q, 'id siempre, sin repetir y en su orden');

            return true;
        });
        $t = $r->template();
        $this->assertSame(['555', 'APPROVED', 'MARKETING', 'GREEN', 'NONE'], [$t->id, $t->status, $t->category, $t->qualityScore, $t->rejectedReason]);
        $this->assertSame([
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Tu plan'],
            ['type' => 'BODY', 'text' => "Hola {{1}}\n"],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'PHONE_NUMBER', 'text' => 'Llamar', 'phone_number' => '+57 314 3455483', 'extraKeys' => ['raro']]]],
        ], $t->components, 'los textos tal cual, sin los ejemplos; lo desconocido, nombrado');
        $this->assertSame([], $t->unreadable);
    }

    public function test_un_valor_que_no_tiene_la_forma_de_meta_se_anota_y_no_se_inventa(): void
    {
        self::meta(Http::response(['id' => '555', 'status' => 'APPROVED_BUT_A_VERY_LONG_STATUS_X', 'category' => 'MARKETING-1', 'components' => [['type' => 'BODY', 'text' => 7]]]));

        $t = $this->cliente()->find('555', ['status', 'category', 'components'])->template();

        $this->assertNull($t->status);
        $this->assertNull($t->category);
        $this->assertNull($t->components);
        $this->assertSame(['status', 'category', 'components'], $t->unreadable);
    }

    public function test_find_de_otra_plantilla_o_sin_id_es_respuesta_invalida_y_un_5xx_al_leer_no_es_desconocido(): void
    {
        self::meta(Http::response(['id' => '999', 'status' => 'APPROVED']));
        $r = $this->cliente()->find('555', ['status']);
        $this->assertSame([R::FAILED, E::INVALID_RESPONSE, 'unexpected_shape'], [$r->outcome, $r->error, $r->detail]);

        self::meta(self::error(500, 2));
        $r = $this->cliente()->find('555', ['status']);
        $this->assertSame([R::FAILED, E::SERVER], [$r->outcome, $r->error], 'una lectura no cambia nada en Meta: nunca «desconocido»');
        $this->assertTrue($r->retryable());

        self::meta(self::tiempoAgotado());
        $r = $this->cliente()->find('555', ['status']);
        $this->assertSame([R::FAILED, E::TRANSPORT, 'maybe_sent'], [$r->outcome, $r->error, $r->detail]);

        self::meta(self::error(400, 100, 33));
        $this->assertSame(E::NOT_FOUND, $this->cliente()->find('555', ['status'])->error);
    }

    public function test_list_pagina_por_cursor_sobre_la_misma_ruta_y_nunca_pide_la_url_next(): void
    {
        $paginas = [
            ['data' => [['id' => '1', 'name' => 'a', 'status' => 'APPROVED'], ['sin' => 'id'], ['id' => '2', 'name' => 'b']],
                'paging' => ['cursors' => ['before' => 'QVFI', 'after' => 'QVFIUmX3Rz-_+/='], 'next' => 'https://evil.example/steal?access_token=leak']],
            ['data' => [['id' => '3', 'name' => 'c']], 'paging' => ['cursors' => ['after' => 'ZZZ']]],
        ];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $p) use ($paginas) {
            $this->assertStringStartsWith(self::SIMULADOR.'/v25.0/'.self::WABA.'/message_templates?', $p->url(), 'siempre la misma ruta');
            parse_str((string) parse_url($p->url(), PHP_URL_QUERY), $q);

            return Http::response(isset($q['after']) ? $paginas[1] : $paginas[0]);
        });

        $cliente = $this->cliente();
        $primera = $cliente->list(self::WABA, ['name', 'status'], null, 2);
        $this->assertTrue($primera->ok());
        $this->assertSame(['1', '2'], array_map(static fn ($t) => $t->id, $primera->templates));
        $this->assertSame(1, $primera->skipped, 'la fila sin id se cuenta, no se inventa');
        $this->assertSame('QVFIUmX3Rz-_+/=', $primera->nextCursor);

        $segunda = $cliente->list(self::WABA, ['name', 'status'], $primera->nextCursor, 2);
        $this->assertSame(['3'], array_map(static fn ($t) => $t->id, $segunda->templates));
        $this->assertNull($segunda->nextCursor, 'sin `next`, no hay más aunque haya cursor');

        Http::assertSentCount(2);
        Http::assertSent(function (Request $p): bool {
            parse_str((string) parse_url($p->url(), PHP_URL_QUERY), $q);

            return ($q['after'] ?? null) === 'QVFIUmX3Rz-_+/=' && $q['limit'] === '2' && $q['fields'] === 'id,name,status';
        });
        Http::assertNotSent(fn (Request $p): bool => str_contains($p->url(), 'evil.example'));
    }

    public function test_una_pagina_con_next_y_sin_cursor_valido_es_bad_cursor(): void
    {
        self::meta(Http::response(['data' => [], 'paging' => ['next' => 'https://graph.facebook.com/x', 'cursors' => ['after' => 'tiene espacios']]]));
        $r = $this->cliente()->list(self::WABA, ['id']);
        $this->assertSame([R::FAILED, E::INVALID_RESPONSE, 'bad_cursor'], [$r->outcome, $r->error, $r->detail]);

        self::meta(Http::response(['nodata' => true]));
        $this->assertSame('unexpected_shape', $this->cliente()->list(self::WABA, ['id'])->detail);
    }

    public function test_find_by_name_filtra_el_nombre_exacto_recorre_las_paginas_y_corta_los_bucles(): void
    {
        $llamadas = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $p) use (&$llamadas) {
            $llamadas++;
            parse_str((string) parse_url($p->url(), PHP_URL_QUERY), $q);
            $this->assertSame('renovacion', $q['name']);
            $this->assertSame('100', $q['limit']);
            $this->assertSame('id,name,language,status', $q['fields']);

            return isset($q['after'])
                ? Http::response(['data' => [['id' => '3', 'name' => 'renovacion', 'language' => 'es']]])
                : Http::response(['data' => [
                    ['id' => '1', 'name' => 'renovacion', 'language' => 'es_CO'],
                    ['id' => '2', 'name' => 'renovacion_v2', 'language' => 'es_CO'],
                ], 'paging' => ['cursors' => ['after' => 'P2'], 'next' => 'https://graph.facebook.com/next']]);
        });

        $todas = $this->cliente()->findByName(self::WABA, 'renovacion', null, ['status']);
        $this->assertSame(['1', '3'], array_map(static fn ($t) => $t->id, $todas->templates), '«contiene» no basta: nombre exacto');
        $this->assertSame(2, $todas->requests);

        $es = $this->cliente()->findByName(self::WABA, 'renovacion', 'es', ['status']);
        $this->assertSame(['3'], array_map(static fn ($t) => $t->id, $es->templates));

        // El mismo cursor otra vez: bucle cortado.
        self::meta(Http::response(['data' => [], 'paging' => ['cursors' => ['after' => 'SIEMPRE'], 'next' => 'https://graph.facebook.com/n']]));
        $r = $this->cliente()->findByName(self::WABA, 'renovacion', null, ['status']);
        $this->assertSame(['bad_cursor', 2], [$r->detail, $r->requests]);

        // Cursores distintos sin fin: el tope de páginas.
        $n = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function () use (&$n) {
            $n++;

            return Http::response(['data' => [], 'paging' => ['cursors' => ['after' => 'C'.$n], 'next' => 'https://graph.facebook.com/n']]);
        });
        $r = $this->cliente()->findByName(self::WABA, 'renovacion', null, ['status']);
        $this->assertSame(['too_many_pages', Graph::FIND_BY_NAME_MAX_PAGES], [$r->detail, $r->requests]);
        $this->assertSame(Graph::FIND_BY_NAME_MAX_PAGES, $n);

        // Un fallo en la segunda página cuenta las dos peticiones.
        $n = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function () use (&$n) {
            $n++;

            return $n === 1 ? Http::response(['data' => [], 'paging' => ['cursors' => ['after' => 'P2'], 'next' => 'x']]) : Http::response(['error' => ['code' => 4]], 400);
        });
        $r = $this->cliente()->findByName(self::WABA, 'renovacion', null, ['status']);
        $this->assertSame([E::RATE_LIMITED, 2], [$r->error, $r->requests]);
    }

    public function test_permissions_lee_me_permissions_con_el_token_en_la_cabecera_y_salta_lo_que_no_entiende(): void
    {
        self::meta(Http::response(['data' => [
            ['permission' => 'whatsapp_business_management', 'status' => 'granted'],
            ['permission' => 'whatsapp_business_messaging', 'status' => 'declined'],
            ['permission' => 'Mal Formado', 'status' => 'granted'],
            ['permission' => 'business_management', 'status' => 'raro'],
        ]]));

        $r = $this->cliente()->permissions();

        $this->assertTrue($r->ok());
        $this->assertSame(['whatsapp_business_management' => 'granted', 'whatsapp_business_messaging' => 'declined'], $r->permissions);
        $this->assertSame(2, $r->skipped);
        Http::assertSent(function (Request $p): bool {
            $this->assertSame(self::SIMULADOR.'/v25.0/me/permissions', $p->url(), 'sin query: nunca debug_token ni el token en la URL');
            $this->assertSame(['Bearer '.Graph::SIMULATOR_BEARER], $p->header('Authorization'), 'fuera de producción, el portador fijo (PM-1)');

            return true;
        });
        $this->sinToken($r);
    }

    // ── Registros ───────────────────────────────────────────────────────────

    public function test_cada_operacion_deja_una_linea_con_codigos_y_tiempos_y_sin_cuerpos(): void
    {
        self::meta(self::error(400, 100, 2388024, ['error_user_msg' => 'Texto para el usuario con el token '.self::TOKEN]));
        $this->cliente()->create(self::WABA, self::plantilla());
        self::meta(Http::response(['data' => []]));
        $this->cliente()->list(self::WABA, ['id']);

        $lineas = array_values(array_filter($this->registros, static fn (MessageLogged $m): bool => str_starts_with($m->message, 'meta.templates.')));
        $this->assertSame(['meta.templates.create', 'meta.templates.list'], array_map(static fn (MessageLogged $m) => $m->message, $lineas));
        $this->assertSame(['warning', 'info'], array_map(static fn (MessageLogged $m) => $m->level, $lineas));
        $contexto = $lineas[0]->context;
        $this->assertSame(['failed', 400, 'duplicate_name', 100, 2388024, 'v25.0'], [$contexto['outcome'], $contexto['http_status'], $contexto['error_class'], $contexto['error_code'], $contexto['error_subcode'], $contexto['graph_version']]);
        $todo = json_encode(array_map(static fn (MessageLogged $m) => [$m->message, $m->context], $this->registros));
        foreach (['Texto para el usuario', 'renovacion_aviso_v2', 'Tu plan', self::WABA, '127.0.0.1'] as $nada) {
            $this->assertStringNotContainsString($nada, $todo, "el registro lleva «{$nada}»");
        }
    }

    public function test_un_fallo_inesperado_del_cliente_http_es_un_resultado_y_se_registra_solo_su_clase(): void
    {
        self::meta(static fn () => throw new \RuntimeException('boom con '.self::TOKEN.' en https://x/'.self::WABA));

        $r = $this->cliente()->create(self::WABA, self::plantilla());

        $this->assertSame([R::UNKNOWN, E::TRANSPORT, 'maybe_sent'], [$r->outcome, $r->error, $r->detail], 'no se sabe si salió: desconocido');
        $linea = collect($this->registros)->first(fn (MessageLogged $m) => $m->message === 'meta.templates.client_exception');
        $this->assertNotNull($linea);
        $this->assertSame(['operation' => 'create', 'exception_class' => \RuntimeException::class], array_intersect_key($linea->context, ['operation' => 1, 'exception_class' => 1]));
        $this->assertStringNotContainsString('boom', json_encode($linea->context));

        self::meta(static fn () => throw new \RuntimeException('boom'));
        $this->assertSame([R::FAILED, E::TRANSPORT], [($l = $this->cliente()->list(self::WABA, ['id']))->outcome, $l->error], 'una lectura, fallida');
    }

    // ── Lo que el fichero no puede hacer (estática) ─────────────────────────

    public function test_el_cliente_solo_conoce_sus_rutas_nunca_mensajes_ni_reintentos(): void
    {
        $dir = base_path('app/Services/Meta/Templates');
        $codigo = '';
        foreach (glob($dir.'/*.php') as $f) {
            foreach (token_get_all((string) file_get_contents($f)) as $t) {
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $codigo .= is_array($t) ? $t[1] : $t;
            }
        }
        foreach (["'/messages", '/messages\'', '"messages"', 'MetaMessagingService', 'WhatsappOutboxService', 'MarketingMessageDispatcher', '->retry(', 'debug_token', 'unpause', '->delete(', 'Http::delete', 'meta.graph_version', 'UltronStudio', 'ultron_studio', 'ultron-studio'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $codigo, "el cliente neutro nombra {$prohibido}");
        }
        $graph = (string) file_get_contents($dir.'/GraphWhatsappTemplateClient.php');
        $this->assertSame(1, substr_count($graph, '->post('), 'un solo POST: crear');
        $this->assertSame(1, substr_count($graph, 'Http::'), 'una sola construcción de la petición');
        $this->assertStringContainsString('->withoutRedirecting()', $graph);
        $this->assertSame(5, Graph::CONNECT_TIMEOUT_SECONDS);
        $this->assertSame(15, Graph::TIMEOUT_SECONDS);
        $this->assertContains('rejected_reason', WhatsappTemplateClient::SYNC_FIELDS);
    }

    public function test_el_resultado_no_admite_estados_imposibles(): void
    {
        foreach ([
            fn () => R::failure(R::OP_FIND, R::UNKNOWN, E::TRANSPORT),
            fn () => R::failure(R::OP_CREATE, R::OK, E::TRANSPORT),
            fn () => R::failure(R::OP_CREATE, R::FAILED, 'inventada'),
            fn () => R::failure(R::OP_CREATE, R::FAILED, E::TRANSPORT, 'detalle_inventado'),
            fn () => R::created('12a', null, null, 200, 0),
        ] as $i => $imposible) {
            try {
                $imposible();
                $this->fail("caso {$i}: aceptó un resultado imposible");
            } catch (Throwable $e) {
                $this->assertInstanceOf(\LogicException::class, $e);
            }
        }
    }
}
