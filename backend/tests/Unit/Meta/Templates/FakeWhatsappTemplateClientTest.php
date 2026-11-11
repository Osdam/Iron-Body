<?php

namespace Tests\Unit\Meta\Templates;

use App\Services\Meta\MetaAuthService;
use App\Services\Meta\Templates\FakeWhatsappTemplateClient as Fake;
use App\Services\Meta\Templates\GraphWhatsappTemplateClient as Graph;
use App\Services\Meta\Templates\TemplateApiErrors as E;
use App\Services\Meta\Templates\TemplateApiResult as R;
use App\Services\Meta\Templates\WhatsappTemplateClient;
use App\Services\Meta\Templates\WhatsappTemplateClientFactory as Factory;
use App\Services\Meta\Templates\WhatsappTemplateProbe as Probe;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PLANTILLAS DE META · PASO 1c (PLANTILLAS_META_PLAN §5.8.4, §5.9, R7; L9-96):
 *
 *  - R7: EL DOBLE NO EXISTE EN PRODUCCIÓN. Ni se construye ni, construido en
 *    pruebas, responde si el entorno pasa a ser otro; la fábrica nunca lo da
 *    fuera de `local`/`testing` (en producción, siempre Graph, aunque la
 *    configuración diga `fake`).
 *  - Los escenarios del doble por el prefijo del nombre, como los documenta.
 *  - La sonda de permisos: su veredicto con el doble y con Graph de mentira.
 * Cero red.
 */
class FakeWhatsappTemplateClientTest extends TestCase
{
    private const WABA = '102938475610293';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['logging.channels.channel' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
    }

    private function doble(int $pasadas = Fake::DEFAULT_PENDING_PASSES): Fake
    {
        return new Fake(Cache::store('array'), $pasadas);
    }

    /** @return array<string, mixed> */
    private static function plantilla(string $nombre, string $categoria = 'UTILITY', string $idioma = 'es_CO'): array
    {
        return [
            'name' => $nombre, 'language' => $idioma, 'category' => $categoria, 'parameter_format' => 'POSITIONAL',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hola {{1}}, tu clase sigue en pie. Responde BAJA para no recibir más.', 'example' => ['body_text' => [['Laura']]]],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Darme de baja']]],
            ],
        ];
    }

    /** Mirar = pedir el estado (lo que avanza el escenario). */
    private static function mirar(WhatsappTemplateClient $c, string $id): R
    {
        return $c->find($id, WhatsappTemplateClient::SYNC_FIELDS);
    }

    // ── R7 ──────────────────────────────────────────────────────────────────

    public function test_r7_el_doble_no_se_construye_en_produccion_ni_en_un_entorno_fuera_de_la_lista(): void
    {
        $this->assertTrue(Fake::allowedHere());
        foreach (['production', 'staging', 'prod', 'Production', ''] as $entorno) {
            $this->app['env'] = $entorno;
            $this->assertFalse(Fake::allowedHere(), $entorno);
            try {
                new Fake(Cache::store('array'));
                $this->fail("se construyó en «{$entorno}»");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_r7_construido_en_pruebas_deja_de_responder_si_el_entorno_pasa_a_produccion(): void
    {
        $doble = $this->doble();
        $this->app['env'] = 'production';
        foreach ([
            fn () => $doble->create(self::WABA, self::plantilla('aprobar_x')),
            fn () => $doble->find('1', ['status']),
            fn () => $doble->findByName(self::WABA, 'aprobar_x', null, ['status']),
            fn () => $doble->list(self::WABA, ['id']),
            fn () => $doble->permissions(),
            fn () => $doble->configurationProblem(),
            fn () => $doble->seed(self::WABA, ['name' => 'x', 'language' => 'es', 'category' => 'UTILITY']),
        ] as $i => $llamada) {
            try {
                $llamada();
                $this->fail("el método {$i} respondió en producción");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_r7_la_fabrica_da_graph_en_produccion_aunque_la_configuracion_diga_fake(): void
    {
        $this->assertInstanceOf(Fake::class, Factory::make('fake', 'v25.0', null));
        $this->assertSame('fake', Factory::effectiveDriver(' FAKE '));
        $this->assertInstanceOf(Graph::class, Factory::make('graph', 'v25.0', null));
        $this->assertInstanceOf(Graph::class, Factory::make('grafo', 'v25.0', null), 'un driver desconocido es graph');
        $this->assertInstanceOf(Graph::class, Factory::make(null, 'v25.0', null));

        foreach (['production', 'staging'] as $entorno) {
            $this->app['env'] = $entorno;
            $this->assertSame('graph', Factory::effectiveDriver('fake'), $entorno);
            $this->assertInstanceOf(Graph::class, Factory::make('fake', 'v25.0', 'http://127.0.0.1:1'), $entorno);
        }

        $this->assertSame('v25.0', Factory::effectiveGraphVersion('v2'));
        $this->assertSame('v25.0', Factory::effectiveGraphVersion(null));
        $this->assertSame('v26.0', Factory::effectiveGraphVersion('v26.0'));
        $this->assertSame('v25.0', Factory::make('graph', 'basura', null)->graphVersion());
    }

    // ── Escenarios ──────────────────────────────────────────────────────────

    public function test_aprobar_crea_pending_y_al_mirarla_queda_approved(): void
    {
        $doble = $this->doble();
        $creada = $doble->create(self::WABA, self::plantilla('aprobar_bienvenida_v1'));
        $this->assertSame([R::OK, 'PENDING', 'UTILITY'], [$creada->outcome, $creada->createdStatus, $creada->createdCategory]);
        $this->assertMatchesRegularExpression('/^1[0-9]{15}$/', $creada->createdId);

        // Leer sin pedir el estado no avanza.
        $this->assertNull($doble->find($creada->createdId, ['name'])->template()->status);
        $t = self::mirar($doble, $creada->createdId)->template();
        $this->assertSame(['APPROVED', 'GREEN', 'NONE'], [$t->status, $t->qualityScore, $t->rejectedReason]);
        $this->assertSame([['type' => 'BODY', 'text' => 'Hola {{1}}, tu clase sigue en pie. Responde BAJA para no recibir más.'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Darme de baja']]]], $t->components, 'por la misma normalización que Graph: sin ejemplos');
        $this->assertSame('APPROVED', self::mirar($doble, $creada->createdId)->template()->status);

        // Sin prefijo conocido, también `aprobar`.
        $this->assertSame('aprobar', Fake::scenarioOf('renovacion_v3'));
    }

    public function test_rechazar_pausar_tardar_editar_recategorizar_y_borrar(): void
    {
        $doble = $this->doble(2);
        $id = static fn (string $n) => $doble->create(self::WABA, self::plantilla($n))->createdId;

        $r = $id('rechazar_x');
        $this->assertSame(['REJECTED', 'INVALID_FORMAT'], [($t = self::mirar($doble, $r)->template())->status, $t->rejectedReason]);

        $p = $id('pausar_x');
        $this->assertSame(['APPROVED', 'PAUSED', 'APPROVED'], [self::mirar($doble, $p)->template()->status, self::mirar($doble, $p)->template()->status, self::mirar($doble, $p)->template()->status]);

        $t2 = $id('tardar_x');
        $this->assertSame(['PENDING', 'PENDING', 'APPROVED'], [self::mirar($doble, $t2)->template()->status, self::mirar($doble, $t2)->template()->status, self::mirar($doble, $t2)->template()->status]);

        $e = $id('editar_x');
        $antes = self::mirar($doble, $e)->template()->components;
        $despues = self::mirar($doble, $e)->template()->components;
        $this->assertNotSame($antes, $despues);
        $this->assertSame(['type' => 'FOOTER', 'text' => Fake::EDITED_FOOTER], $despues[1], 'el pie aparece antes de los botones');

        $c = $id('recategorizar_x');
        self::mirar($doble, $c);
        $t = self::mirar($doble, $c)->template();
        $this->assertSame(['APPROVED', 'MARKETING'], [$t->status, $t->category]);
        $this->assertSame('UTILITY', $doble->find($c, ['previous_category'])->template()->previousCategory);

        $b = $id('borrar_x');
        $this->assertSame('APPROVED', self::mirar($doble, $b)->template()->status);
        $fallo = self::mirar($doble, $b);
        $this->assertSame([R::FAILED, E::NOT_FOUND, 100, 33], [$fallo->outcome, $fallo->error, $fallo->metaCode, $fallo->metaSubcode]);
        $this->assertNotContains($b, array_map(static fn ($t) => $t->id, $doble->list(self::WABA, ['id'])->templates), 'borrada: tampoco sale en la lista');
        $otra = $doble->create(self::WABA, self::plantilla('borrar_x'));
        $this->assertSame([E::VALIDATION, Fake::SUBCODE_LANGUAGE_BEING_DELETED], [$otra->error, $otra->metaSubcode], 'el nombre recién borrado no se puede reutilizar');
    }

    public function test_caer_perder_fallar_y_el_nombre_duplicado(): void
    {
        $doble = $this->doble();

        $cae = $doble->create(self::WABA, self::plantilla('caer_x'));
        $this->assertSame([R::UNKNOWN, E::TRANSPORT, 'maybe_sent'], [$cae->outcome, $cae->error, $cae->detail]);
        $hallada = $doble->findByName(self::WABA, 'caer_x', 'es_CO', ['status']);
        $this->assertCount(1, $hallada->templates, 'caer: Meta la creó aunque no contestó');
        $this->assertSame('APPROVED', $hallada->template()->status);

        $pierde = $doble->create(self::WABA, self::plantilla('perder_x'));
        $this->assertTrue($pierde->unknown());
        $this->assertSame([], $doble->findByName(self::WABA, 'perder_x', null, ['status'])->templates, 'perder: no se creó');

        $falla = $doble->create(self::WABA, self::plantilla('fallar_x'));
        $this->assertSame([R::FAILED, E::VALIDATION, 100], [$falla->outcome, $falla->error, $falla->metaCode]);
        $this->assertSame([], $doble->findByName(self::WABA, 'fallar_x', null, ['id'])->templates);

        $this->assertTrue($doble->create(self::WABA, self::plantilla('aprobar_d'))->ok());
        $dup = $doble->create(self::WABA, self::plantilla('aprobar_d'));
        $this->assertSame([R::FAILED, E::DUPLICATE_NAME, 100, E::SUBCODE_DUPLICATE_NAME], [$dup->outcome, $dup->error, $dup->metaCode, $dup->metaSubcode]);
        $this->assertTrue($doble->create(self::WABA, self::plantilla('aprobar_d', idioma: 'es'))->ok(), 'otro idioma, otra plantilla');

        $this->assertSame(E::NOT_FOUND, $doble->find('1999999999999999', ['status'])->error);
    }

    public function test_list_pagina_por_cursor_y_seed_pone_plantillas_fijas(): void
    {
        $doble = $this->doble();
        foreach (['aprobar_a', 'aprobar_b', 'aprobar_c'] as $n) {
            $doble->create(self::WABA, self::plantilla($n));
        }
        $doble->create('999', self::plantilla('aprobar_otra_cuenta'));
        $fija = $doble->seed(self::WABA, ['name' => 'creada_fuera', 'language' => 'es', 'category' => 'MARKETING', 'status' => 'PAUSED', 'quality' => 'RED']);

        $uno = $doble->list(self::WABA, ['name', 'status'], null, 2);
        $this->assertSame(['aprobar_a', 'aprobar_b'], array_map(static fn ($t) => $t->name, $uno->templates));
        $this->assertNotNull($uno->nextCursor);
        $dos = $doble->list(self::WABA, ['name', 'status'], $uno->nextCursor, 2);
        $this->assertSame(['aprobar_c', 'creada_fuera'], array_map(static fn ($t) => $t->name, $dos->templates));
        $this->assertNull($dos->nextCursor);
        $this->assertSame(E::VALIDATION, $doble->list(self::WABA, ['id'], base64_encode('fake:42'))->error, 'un cursor que no conoce');

        for ($i = 0; $i < 3; $i++) {
            $t = self::mirar($doble, $fija)->template();
            $this->assertSame(['PAUSED', 'RED', 'MARKETING'], [$t->status, $t->qualityScore, $t->category], 'las sembradas no avanzan');
        }
        $this->expectException(InvalidArgumentException::class);
        $doble->seed(self::WABA, ['name' => 'creada_fuera', 'language' => 'es', 'category' => 'MARKETING']);
    }

    public function test_el_doble_rechaza_las_mismas_entradas_que_el_cliente_de_verdad(): void
    {
        $doble = $this->doble();
        foreach ([
            fn () => $doble->create('12a', self::plantilla('aprobar_x')),
            fn () => $doble->create(self::WABA, ['name' => 'aprobar_x']),
            fn () => $doble->find('x1', ['status']),
            fn () => $doble->find('1', ['secreto']),
            fn () => $doble->list(self::WABA, ['id'], null, 0),
            fn () => $doble->findByName(self::WABA, 'Mayus', null, ['id']),
        ] as $i => $mala) {
            try {
                $mala();
                $this->fail("caso {$i}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull($doble->configurationProblem());
        $this->assertSame(['whatsapp_business_management' => 'granted', 'whatsapp_business_messaging' => 'granted'], $doble->permissions()->permissions);
    }

    // ── La sonda ────────────────────────────────────────────────────────────

    public function test_la_sonda_con_el_doble_dice_ok(): void
    {
        $r = Probe::run($this->doble(), self::WABA);
        $this->assertSame(['outcome' => 'ok', 'scopes' => ['whatsapp_business_management' => true], 'templatesReadable' => true, 'templatesError' => null, 'requests' => 2, 'durationMs' => 0], $r);
    }

    /** @return array<string, array{0: mixed, 1: mixed, 2: string, 3: ?bool, 4: ?bool}> permisos, lectura, veredicto, gestión, legible */
    public static function sondas(): array
    {
        $con = ['data' => [['permission' => 'whatsapp_business_management', 'status' => 'granted']]];
        $sin = ['data' => [['permission' => 'whatsapp_business_messaging', 'status' => 'granted']]];
        $err = static fn (int $estado, int $code, ?int $sub = null) => ['error' => array_filter(['code' => $code, 'error_subcode' => $sub])];

        return [
            'todo bien' => [[$con, 200], [['data' => [['id' => '1']]], 200], 'ok', true, true],
            'lee aunque /me/permissions no lo liste (V-3)' => [[$sin, 200], [['data' => []], 200], 'ok', false, true],
            'sin el permiso' => [[$sin, 200], [$err(403, 200), 403], 'missing_permission', false, false],
            'con el permiso y sin la cuenta asignada' => [[$con, 200], [$err(403, 200), 403], 'no_waba_access', true, false],
            'la cuenta no existe' => [[$con, 200], [$err(400, 100, 33), 400], 'no_waba_access', true, false],
            'permisos ilegibles y sin la cuenta' => [['<html>', 500], [$err(403, 10), 403], 'no_waba_access', null, false],
            'token caducado' => [[$err(401, 190), 401], [$err(401, 190), 401], 'auth_failed', null, false],
            'Meta no contesta' => [['timeout', 0], ['timeout', 0], 'unreachable', null, false],
            'Meta pide frenar' => [[$con, 200], [$err(400, 80008), 400], 'unreachable', true, false],
            'otra cosa' => [[$con, 200], [$err(400, 1234), 400], 'error', true, false],
        ];
    }

    #[DataProvider('sondas')]
    public function test_el_veredicto_de_la_sonda_contra_graph_de_mentira(array $permisos, array $lectura, string $veredicto, ?bool $gestion, ?bool $legible): void
    {
        $responde = static fn (array $r) => static fn (Request $p) => $r[0] === 'timeout'
            ? Create::rejectionFor(new ConnectException('cURL error 28: timed out', $p->toPsrRequest(), null, ['errno' => 28]))
            : Http::response($r[0], $r[1]);
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(static fn (Request $p) => str_ends_with(explode('?', $p->url())[0], '/me/permissions') ? $responde($permisos)($p) : $responde($lectura)($p));

        $cliente = new Graph(new class extends MetaAuthService
        {
            public function __construct() {}

            public function accessToken(): ?string
            {
                return 'EAAsonda123';
            }
        }, 'v25.0', 'http://127.0.0.1:8097');
        $r = Probe::run($cliente, self::WABA);

        $this->assertSame($veredicto, $r['outcome']);
        $this->assertSame($gestion, $r['scopes']['whatsapp_business_management']);
        $this->assertSame($legible, $r['templatesReadable']);
        $this->assertSame(2, $r['requests']);
        Http::assertSent(function (Request $p): bool {
            if (! str_contains($p->url(), 'message_templates')) {
                return false;
            }
            parse_str((string) parse_url($p->url(), PHP_URL_QUERY), $q);

            return $q === ['fields' => 'id', 'limit' => '1'];
        });
        $this->assertContains($r['outcome'], Probe::OUTCOMES);
    }

    public function test_la_sonda_sin_configurar_no_sale_a_la_red(): void
    {
        $cliente = new Graph(new class extends MetaAuthService
        {
            public function __construct() {}

            public function accessToken(): ?string
            {
                return null;
            }
        }, 'v25.0', 'http://127.0.0.1:8097');

        $r = Probe::run($cliente, self::WABA);

        $this->assertSame(['not_configured', null, null, 'not_configured', 0], [$r['outcome'], $r['scopes']['whatsapp_business_management'], $r['templatesReadable'], $r['templatesError'], $r['requests']]);
        Http::assertNothingSent();
    }
}
