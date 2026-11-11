<?php

namespace Tests\Feature\UltronStudio\MetaTemplates;

use App\Http\Controllers\Crm\UltronStudio\StudioRouteCount;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioMetaTemplateController as C;
use App\Http\Middleware\LimitRequestBodySize;
use App\Models\Admin;
use App\Models\WhatsappBusinessIntegration;
use App\Services\Meta\Templates\GraphWhatsappTemplateClient;
use App\Services\Meta\WhatsappIntegrationRegistry;
use App\Services\UltronStudio\StudioMessageTemplates as T;
use App\Services\UltronStudio\StudioMetaTemplates;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\NullHandler;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PLANTILLAS DE META · PASO 3, LA API (PLANTILLAS_META_PLAN §5.8, §5.10,
 * §5.11, §5.14; L9-96):
 *
 *  - las 4 rutas (de 121 a 125) con su tope, sus cupos, su llave en
 *    `AuthorizationMap`, y la llave nueva solo para Super Admin y fuera del
 *    token compartido;
 *  - las cerraduras: sin sesión 401, token compartido 403, estrategias apagadas
 *    409 en las 4, el módulo apagado 409 en 3 y `status` 200;
 *  - la sonda (enfriamiento, libro) y el estado (sin token ni cuenta);
 *  - CERO RED con las banderas por defecto, aunque el driver sea `graph`;
 *  - la CENTINELA: el token (el de la conexión del CRM y el del .env) no aparece
 *    en respuestas, tablas, libro, registros ni URLs, ni viaja al simulador
 *    (PM-1: fuera de producción, el portador fijo);
 *  - el cupo propio de enviar y comprobar, y el de la sonda, que no es el de
 *    Twilio (PM-2);
 *  - la puerta de diff vacía del WhatsApp de hoy (§3.1 «no se tocan»).
 */
class MetaTemplateRoutesTest extends TestCase
{
    use MetaTemplateFixtures;
    use RefreshDatabase;

    /** Los ficheros del WhatsApp de hoy que esta función no toca (§3.1; la puerta del despliegue). */
    private const DIFF_GATE = [
        'app/Http/Controllers/Api/WebhookMetaController.php', 'app/Jobs/ProcessMetaWebhookEvent.php', 'app/Services/Meta/MetaWebhookService.php',
        'app/Services/Meta/MetaWebhookIngestService.php', 'app/Services/Meta/MetaMessagingService.php', 'app/Services/Meta/MetaAuthService.php',
        'app/Services/Meta/WhatsappIntegrationRegistry.php', 'app/Services/Marketing/WhatsappOutboxService.php',
        'app/Services/Marketing/MarketingMessageDispatcher.php', 'app/Services/Marketing/OutreachTemplateMessage.php',
        'app/Services/Outreach/Run/Channels/WhatsappTemplateChannel.php', 'routes/api.php', 'routes/marketing.php', 'config/meta.php',
    ];

    private const VARIABLES = [
        'ULTRON_STUDIO_META_TEMPLATES_ENABLED', 'ULTRON_STUDIO_META_TEMPLATES_SUBMIT_ENABLED', 'ULTRON_STUDIO_META_TEMPLATES_SYNC_ENABLED',
        'ULTRON_STUDIO_META_TEMPLATES_DRIVER', 'ULTRON_STUDIO_META_TEMPLATES_GRAPH_VERSION', 'ULTRON_STUDIO_META_TEMPLATES_GRAPH_BASE',
        'ULTRON_STUDIO_META_TEMPLATES_URL_HOSTS', 'ULTRON_STUDIO_META_TEMPLATES_PHONE_BUTTON',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['logging.channels.channel' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
        $this->metaWorld();
    }

    private static function url(string $ruta, ?string $uuid = null): string
    {
        return '/api/admin/ultron-studio/'.str_replace('{uuid}', $uuid ?? (string) Str::uuid(), $ruta);
    }

    // ── Las rutas ───────────────────────────────────────────────────────────

    public function test_las_4_rutas_existen_con_su_tope_sus_cupos_y_su_llave_y_el_total_es_125(): void
    {
        $this->assertCount(C::ROUTE_COUNT, C::ROUTES);
        $this->assertSame(125, StudioRouteCount::TOTAL);
        $delStudio = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/admin/ultron-studio')) {
                $delStudio[AuthorizationMap::routeKey($route)] = $route;
            }
        }
        $this->assertCount(StudioRouteCount::TOTAL, $delStudio);

        // PM-2: la sonda de Meta tiene su propio limitador; el de Twilio no la toca.
        $this->assertNotContains('throttle:ultron-studio-probe', $delStudio['POST api/admin/ultron-studio/strategies/templates/meta/probe']->gatherMiddleware());
        $propio = ['GET strategies/templates/meta/status' => null, 'POST strategies/templates/meta/probe' => 'throttle:ultron-studio-meta-probe',
            'POST strategies/templates/{uuid}/meta/submit' => 'throttle:ultron-studio-meta', 'POST strategies/templates/{uuid}/meta/refresh' => 'throttle:ultron-studio-meta'];
        foreach (C::ROUTES as [$metodo, $ruta, $llave]) {
            $clave = "{$metodo} api/admin/ultron-studio/{$ruta}";
            $this->assertArrayHasKey($clave, $delStudio, "falta {$clave}");
            $mw = $delStudio[$clave]->gatherMiddleware();
            $this->assertContains(LimitRequestBodySize::class.':65536', $mw, $clave);
            $this->assertContains($metodo === 'GET' ? 'throttle:ultron-studio-read' : 'throttle:ultron-studio-write', $mw, $clave);
            if ($propio["{$metodo} {$ruta}"] !== null) {
                $this->assertContains($propio["{$metodo} {$ruta}"], $mw, $clave);
            }
            $this->assertSame($llave, AuthorizationMap::resolve($delStudio[$clave]), "{$clave}: la llave en AuthorizationMap");
            $this->assertSame($llave, C::authorizationMap()[$clave]);
        }
        // `{uuid}` con whereUuid: `meta` no se toma por una versión.
        $this->assertSame('uuid', array_key_first($delStudio['POST api/admin/ultron-studio/strategies/templates/{uuid}/meta/submit']->wheres));
    }

    public function test_la_llave_nueva_esta_en_el_catalogo_solo_para_super_admin_y_fuera_del_token_compartido(): void
    {
        $llave = 'ultron.meta_templates_submit';
        $this->assertContains($llave, CrmPermission::all());
        $this->assertContains($llave, CrmPermission::defaultsFor(Admin::ROLE_SUPER_ADMIN));
        foreach ([Admin::ROLE_ADMINISTRADOR, Admin::ROLE_RECEPCION, Admin::ROLE_ADMINISTRATIVO, CrmPermission::ROLE_ENTRENADOR] as $rol) {
            $this->assertNotContains($llave, CrmPermission::defaultsFor($rol), $rol);
        }
        $this->assertContains('ultron', CrmPermission::SHARED_TOKEN_EXCLUDED_DOMAINS);
        $fila = array_column(PermissionCatalog::rows(), null, 'key')[$llave];
        $this->assertSame('Enviar plantillas de WhatsApp a Meta y comprobar su estado', $fila['label']);
        $this->assertStringContainsString('Crea plantillas en la cuenta de WhatsApp del gimnasio', (string) $fila['help']);
        $this->assertStringContainsString('No llega por defecto al Administrador', (string) $fila['help']);
    }

    public function test_sin_sesion_401_y_con_el_token_compartido_403_en_las_4(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (C::ROUTES as [$metodo, $ruta]) {
            $this->assertSame(401, $this->json($metodo, self::url($ruta), [])->status(), "sin sesión · {$metodo} {$ruta}");
            $this->assertSame(403, $this->json($metodo, self::url($ruta), [], $this->sharedTokenHeaders())->status(), "token compartido · {$metodo} {$ruta}");
        }
    }

    public function test_con_las_estrategias_apagadas_las_4_dan_409_y_con_el_modulo_apagado_3_y_status_200_sin_escribir(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $t = $this->approvedMeta('aprobar_cerraduras_v1');
        $antes = DB::table('ultron_studio_audit_events')->count();

        $this->strategyFlags(enabled: false);
        foreach (C::ROUTES as [$metodo, $ruta]) {
            $r = $this->json($metodo, self::url($ruta, (string) $t->uuid), ['clientRequestId' => (string) Str::uuid()], $this->headersOf($this->ana));
            $this->assertSame([409, 'strategies_disabled'], [$r->status(), $r->json('code')], "{$metodo} {$ruta}");
        }

        $this->strategyFlags(enabled: true);
        $this->metaFlags(enabled: false);
        foreach (C::ROUTES as [$metodo, $ruta]) {
            $r = $this->json($metodo, self::url($ruta, (string) $t->uuid), ['clientRequestId' => (string) Str::uuid()], $this->headersOf($this->ana));
            if ($ruta === 'strategies/templates/meta/status') {
                $this->assertSame(200, $r->status());
                $this->assertFalse($r->json('data.enabled'));

                continue;
            }
            $this->assertSame([409, 'meta_templates_disabled'], [$r->status(), $r->json('code')], "{$metodo} {$ruta}");
        }
        $this->assertSame($antes, DB::table('ultron_studio_audit_events')->count(), 'nada escrito');
        $this->assertSame(0, DB::table('ultron_studio_meta_template_submissions')->count());
    }

    // ── La sonda y el estado ────────────────────────────────────────────────

    public function test_la_sonda_con_su_enfriamiento_su_libro_y_su_llave(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        $r = $this->meta('POST', 'strategies/templates/meta/probe', $this->ana)->assertCreated();
        $this->assertSame(['ok', false, true, ['whatsapp_business_management' => true], 'fake', 'v25.0', false],
            [$r->json('data.probe.outcome'), $r->json('data.reused'), $r->json('data.probe.templatesReadable'), $r->json('data.probe.scopes'), $r->json('data.probe.driver'), $r->json('data.probe.graphVersion'), $r->json('data.probe.writeVerified')]);
        $otra = $this->meta('POST', 'strategies/templates/meta/probe', $this->beto)->assertOk();
        $this->assertSame([true, $r->json('data.probe.uuid')], [$otra->json('data.reused'), $otra->json('data.probe.uuid')], 'dentro de los 300 s, la última, sin red');
        $this->assertSame(1, DB::table('ultron_studio_audit_events')->where('event', 'meta_templates.probed')->count());
        $this->travel(301)->seconds();
        $this->meta('POST', 'strategies/templates/meta/probe', $this->beto)->assertCreated();
        $this->assertSame('ok', $this->meta('GET', 'strategies/templates/meta/status', $this->beto)->json('data.lastProbe.outcome'));

        $this->meta('POST', 'strategies/templates/meta/probe', $this->account(Admin::ROLE_ADMINISTRADOR))->assertStatus(403);
        $this->meta('POST', 'strategies/templates/meta/probe', $this->account(Admin::ROLE_SUPER_ADMIN, 'Sin Vínculo'))->assertStatus(403)->assertJsonPath('code', 'person_binding_required');
        $this->metaFlags(driver: 'graph', base: null);
        $this->meta('POST', 'strategies/templates/meta/probe', $this->ana)->assertStatus(409)->assertJsonPath('code', 'meta_not_configured')->assertJsonPath('reason', 'no_simulator');
    }

    public function test_el_estado_dice_banderas_conexion_y_recuentos_sin_el_token_ni_la_cuenta(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['meta.access_token' => 'EAAcentinelaDelEstado987654321']);
        $vacio = $this->meta('GET', 'strategies/templates/meta/status', $this->beto)->assertOk();
        $this->assertStringContainsString('"counts":{}', $vacio->getContent(), 'sin envíos, `counts` es un objeto');
        $this->submitMeta($this->approvedMeta('aprobar_estado_v1'))->assertCreated();
        $this->submitMeta($this->approvedMeta('fallar_estado_v1'))->assertCreated();

        $r = $this->meta('GET', 'strategies/templates/meta/status', $this->beto)->assertOk();

        $this->assertSame([
            'enabled' => true, 'submitEnabled' => true, 'syncEnabled' => true, 'metaEnabled' => true, 'driver' => 'fake', 'graphVersion' => 'v25.0',
            'configured' => true, 'configurationProblem' => null, 'credentialSource' => 'env', 'lastProbe' => null, 'lastSyncAt' => null, 'lastSync' => null,
            'counts' => ['PENDING' => 1], 'submissions' => ['SENDING' => 0, 'SUBMITTED' => 1, 'FAILED' => 1, 'UNKNOWN' => 0],
            'can' => ['submit' => true, 'refresh' => true, 'probe' => true],
        ], $r->json('data'));
        $this->assertStringNotContainsString(self::WABA, $r->getContent());
        $this->assertStringNotContainsString('EAAcentinela', $r->getContent());
        $admin = $this->account(Admin::ROLE_ADMINISTRADOR);
        $this->assertSame(['submit' => false, 'refresh' => false, 'probe' => false], $this->meta('GET', 'strategies/templates/meta/status', $admin)->assertOk()->json('data.can'));
    }

    // ── Cero red ────────────────────────────────────────────────────────────

    public function test_con_las_banderas_por_defecto_ni_las_4_rutas_ni_la_orden_salen_a_la_red_aunque_el_driver_sea_graph(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $antes = [];
        foreach (self::VARIABLES as $v) {
            $antes[$v] = [$_SERVER[$v] ?? null, $_ENV[$v] ?? null, getenv($v)];
            unset($_SERVER[$v], $_ENV[$v]);
            putenv($v);
        }
        try {
            $defecto = (require base_path('config/ultron_studio.php'))['meta_templates'];
        } finally {
            foreach ($antes as $v => [$servidor, $entorno, $proceso]) {
                if ($servidor !== null) {
                    $_SERVER[$v] = $servidor;
                }
                if ($entorno !== null) {
                    $_ENV[$v] = $entorno;
                }
                if ($proceso !== false) {
                    putenv("{$v}={$proceso}");
                }
            }
        }
        $this->assertSame(['enabled' => false, 'submit_enabled' => false, 'sync_enabled' => false, 'driver' => 'fake', 'graph_version' => 'v25.0',
            'graph_base' => null, 'url_hosts' => [], 'phone_button' => false], $defecto, 'todo nace apagado y con el doble');

        $t = $this->approvedMeta('renovacion_sin_red_v1');
        // Los valores por defecto, pero con Graph y un simulador «configurados»: aun así, nada sale.
        config(['ultron_studio.meta_templates' => ['driver' => 'graph', 'graph_base' => 'http://127.0.0.1:8097'] + $defecto, 'meta.access_token' => 'EAAsinRed123']);
        Http::fake();
        foreach (C::ROUTES as [$metodo, $ruta]) {
            $r = $this->json($metodo, self::url($ruta, (string) $t->uuid), ['clientRequestId' => (string) Str::uuid()], $this->headersOf($this->ana));
            $this->assertLessThan(500, $r->status(), "{$metodo} {$ruta}: ".$r->getContent());
        }
        $this->assertSame(0, Artisan::call('ultron-studio:meta-templates-sync'));
        $this->assertSame(0, Artisan::call('ultron-studio:meta-templates-sync', ['--template' => (string) $t->uuid]));
        $this->assertCount(0, Http::recorded(), 'ninguna petición saliente');
    }

    // ── La centinela ────────────────────────────────────────────────────────

    public function test_la_centinela_el_token_no_aparece_en_respuestas_tablas_libro_registros_ni_urls(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $deLaBase = 'EAAcentinelaDeLaConexionDelCRM0123456789';
        $delEnv = 'EAAcentinelaDelPuntoEnv9876543210';
        config(['meta.access_token' => $delEnv]);
        WhatsappBusinessIntegration::create(['waba_id' => self::WABA, 'phone_number_id' => '109876543210987', 'business_id' => '55555', 'display_phone_number' => '+57 314 345 5483',
            'status' => WhatsappBusinessIntegration::STATUS_CONNECTED, 'access_token' => $deLaBase, 'connected_at' => now()]);
        app(WhatsappIntegrationRegistry::class)->forget();
        $this->metaFlags(driver: 'graph', base: 'http://127.0.0.1:8097', waba: null);
        $registros = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$registros): void {
            $registros[] = $m->message.' '.json_encode($m->context);
        });
        // Dos versiones: Meta rechaza la primera (con el token en su texto) y acepta la segunda.
        $rechazada = $this->approvedMeta('renovacion_centinela_v1');
        $buena = $this->approvedMeta('renovacion_centinela_v2');
        $nodo = ['id' => '7200000000000002', 'name' => 'renovacion_centinela_v2', 'language' => 'es_CO', 'status' => 'APPROVED', 'category' => 'MARKETING',
            'components' => T::metaPayload(json_decode($buena->content_json, true))['components'], 'quality_score' => ['score' => 'GREEN'], 'rejected_reason' => 'NONE'];
        Http::fake(function (HttpRequest $p) use ($nodo, $deLaBase) {
            $ruta = (string) parse_url($p->url(), PHP_URL_PATH);

            return match (true) {
                $p->method() === 'POST' && str_contains($p->body(), 'renovacion_centinela_v1') => Http::response(['error' => ['code' => 100, 'error_subcode' => 2388024,
                    'message' => "dev {$deLaBase}", 'error_user_msg' => "Ya existe (token {$deLaBase})", 'fbtrace_id' => 'Trz_1']], 400),
                $p->method() === 'POST' => Http::response(['id' => $nodo['id'], 'status' => 'PENDING', 'category' => 'MARKETING']),
                str_ends_with($ruta, '/me/permissions') => Http::response(['data' => [['permission' => 'whatsapp_business_management', 'status' => 'granted']]]),
                str_ends_with($ruta, '/message_templates') => Http::response(['data' => [$nodo]]),
                default => Http::response($nodo),
            };
        });

        $respuestas = [
            $this->meta('POST', 'strategies/templates/meta/probe', $this->ana)->assertCreated(),
            $this->submitMeta($rechazada)->assertCreated(),
            $this->submitMeta($buena)->assertCreated(),
        ];
        $this->assertSame('ok', app(StudioMetaTemplates::class)->sync(null, false, now())['outcome']);
        $respuestas[] = $this->meta('POST', "strategies/templates/{$buena->uuid}/meta/refresh", $this->beto)->assertOk();
        $respuestas[] = $this->meta('GET', 'strategies/templates/meta/status', $this->ana)->assertOk();
        $respuestas[] = $this->meta('GET', "strategies/templates/{$rechazada->uuid}", $this->ana)->assertOk();
        $respuestas[] = $this->meta('GET', "strategies/templates/{$buena->uuid}", $this->ana)->assertOk();
        $respuestas[] = $this->meta('GET', 'strategies/templates', $this->ana)->assertOk();
        $this->assertSame(['FAILED', 'SUBMITTED'], [$this->submissionOf($rechazada)->state, $this->submissionOf($buena)->state]);
        $this->assertStringContainsString('[token]', (string) $respuestas[1]->json('data.meta.submission.error.message'), 'el texto de Meta, con el token tachado');
        $this->assertTrue($this->accepts($buena), 'la buena, aprobada por Graph de mentira, casa');

        foreach ($respuestas as $i => $r) {
            foreach ([$deLaBase, $delEnv, self::WABA] as $secreto) {
                $this->assertStringNotContainsString($secreto, $r->getContent(), "respuesta {$i}");
            }
        }
        foreach (['ultron_studio_meta_template_submissions', 'ultron_studio_template_meta_checks', 'ultron_studio_audit_events'] as $tabla) {
            $volcado = json_encode(DB::table($tabla)->get());
            $this->assertStringNotContainsString($deLaBase, $volcado, $tabla);
            $this->assertStringNotContainsString($delEnv, $volcado, $tabla);
        }
        foreach ($registros as $linea) {
            $this->assertStringNotContainsString($deLaBase, $linea);
            $this->assertStringNotContainsString($delEnv, $linea);
        }
        // PM-1: fuera de producción (aquí, APP_ENV=testing) ningún token real viaja al
        // simulador, ni en la cabecera: solo el portador fijo. En producción, el de la
        // conexión del CRM (lo fija GraphWhatsappTemplateClientTest).
        $this->assertFalse(app()->isProduction());
        $this->assertNotEmpty(Http::recorded(), 'hubo peticiones al simulador');
        foreach (Http::recorded() as [$peticion]) {
            foreach ([$deLaBase, $delEnv] as $secreto) {
                $this->assertStringNotContainsString($secreto, $peticion->url());
                $this->assertStringNotContainsString($secreto, $peticion->body());
                $this->assertStringNotContainsString($secreto, implode(',', $peticion->header('Authorization')), 'un token real en la cabecera hacia el simulador');
            }
            $this->assertSame(['Bearer '.GraphWhatsappTemplateClient::SIMULATOR_BEARER], $peticion->header('Authorization'));
        }
        $this->assertNotEmpty($registros);
    }

    // ── El cupo propio ──────────────────────────────────────────────────────

    public function test_enviar_y_comprobar_tienen_su_cupo_de_20_por_hora_y_persona(): void
    {
        $t = $this->approvedMeta('aprobar_cupo_v1');
        for ($i = 1; $i <= 20; $i++) {
            $r = $this->meta('POST', "strategies/templates/{$t->uuid}/meta/refresh", $this->beto);
            $this->assertNotSame(429, $r->status(), "intento {$i}");
            $this->travel(4)->seconds();
        }
        $r = $this->meta('POST', "strategies/templates/{$t->uuid}/meta/refresh", $this->beto);
        $this->assertSame([429, 'too_many_requests'], [$r->status(), $r->json('code')]);
        $this->assertNotNull($r->headers->get('Retry-After'));
        // Otra persona tiene su propio cubo.
        $this->assertNotSame(429, $this->submitMeta($t, $this->ana)->status());
    }

    public function test_pm2_la_sonda_de_meta_tiene_su_cupo_y_su_texto_y_no_gasta_ni_nombra_el_de_twilio(): void
    {
        // Este caso pasa por los limitadores. La sonda de Twilio, apagada: 409 sin red.
        config(['ultron_studio.twilio_probe.enabled' => false]);
        $twilio = fn (): TestResponse => $this->meta('POST', 'integrations/twilio/probe', $this->ana, ['clientRequestId' => (string) Str::uuid()]);
        $sondaMeta = fn (Admin $quien): TestResponse => $this->meta('POST', 'strategies/templates/meta/probe', $quien, ['clientRequestId' => (string) Str::uuid()]);

        for ($i = 1; $i <= 6; $i++) {
            $r = $twilio();
            $this->assertLessThan(500, $r->status(), "Twilio {$i}: ".$r->getContent());
            $this->assertNotSame(429, $r->status(), "Twilio {$i}");
        }
        $this->assertSame(429, $twilio()->status(), 'el cupo de la sonda de Twilio, agotado');

        // La séptima sonda de Ana en la hora (la primera de Meta) no es 429: su cubo es otro.
        for ($i = 1; $i <= 6; $i++) {
            $r = $sondaMeta($this->ana);
            $this->assertContains($r->status(), [200, 201], "Meta {$i}: ".$r->getContent());
        }
        $r = $sondaMeta($this->ana);
        $this->assertSame([429, 'too_many_requests'], [$r->status(), $r->json('code')], 'su propio tope: 6 por hora y persona');
        $this->assertNotNull($r->headers->get('Retry-After'));
        $this->assertStringStartsWith('Demasiadas comprobaciones de Meta seguidas', (string) $r->json('message'));
        $this->assertStringNotContainsStringIgnoringCase('Twilio', $r->getContent(), 'el 429 de la sonda de Meta no habla de Twilio');
        // Otra persona tiene su propio cubo.
        $this->assertNotSame(429, $sondaMeta($this->beto)->status());
    }

    // ── La puerta de diff (§3.1) ────────────────────────────────────────────

    public function test_la_puerta_de_diff_del_whatsapp_de_hoy_esta_vacia(): void
    {
        foreach (self::DIFF_GATE as $fichero) {
            $this->assertFileExists(base_path($fichero), "La puerta nombra algo que no existe: {$fichero}");
        }
        $cambiados = $this->git(['diff', '--name-only', 'HEAD', '--', ...self::DIFF_GATE]);
        $this->assertSame('', $cambiados, "El WhatsApp de hoy (webhook, outbox, despachador, puerta, su DTO, Meta) se tocó frente al commit desplegado:\n{$cambiados}");
        $nuevos = $this->git(['ls-files', '--others', '--exclude-standard', '--', 'app/Services/Meta/MetaMessagingService.php', 'app/Services/Marketing/WhatsappOutboxService.php']);
        $this->assertSame('', $nuevos);
    }

    /** @param  list<string>  $args */
    private function git(array $args): string
    {
        $p = new Process(['git', ...$args], base_path());
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'git '.implode(' ', $args).' falló: '.$p->getErrorOutput());

        return trim($p->getOutput());
    }
}
