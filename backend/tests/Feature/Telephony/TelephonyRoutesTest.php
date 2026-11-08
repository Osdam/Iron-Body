<?php

namespace Tests\Feature\Telephony;

use App\Http\Middleware\LimitRequestBodySize;
use App\Support\Access\AuthorizationMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * La tabla de rutas de la telefonía (contrato H8, D8-16, D8-22, «API»; puerta
 * 2): cada ruta de `routes/voice.php` con su tope de cuerpo y su limitador; las
 * públicas, exactamente las de `AuthorizationMap::PUBLIC_VOICE_ROUTES` (las
 * cuatro de D8-16, cada una con su razón); las del CRM, cada una resuelta a su
 * llave `telephony.*`; `auth:coverage --unmapped` vacío; y el 413 en TODAS con
 * un cuerpo por encima de su tope, antes de autenticar.
 */
class TelephonyRoutesTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    private const CAPS = [
        'api/webhooks/voice/elevenlabs/post-call' => 1048576,
        'api/webhooks/voice/elevenlabs/initiation' => 8192,
        'api/voice/tools/{tool}' => 8192,
        'api/webhooks/voice/twilio/usage-trigger' => 65536,
    ];

    private const ADMIN_CAP = 16384;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->voiceWorld();
    }

    /** @return list<\Illuminate\Routing\Route> */
    private function voiceRoutes(): array
    {
        return array_values(array_filter(Route::getRoutes()->getRoutes(), static fn ($r): bool => str_contains(ltrim($r->getActionName(), '\\'), 'Controllers\\Api\\Voice\\')
            || str_contains(ltrim($r->getActionName(), '\\'), 'Controllers\\Crm\\Voice\\')));
    }

    public function test_cada_ruta_lleva_su_tope_y_su_limitador_y_las_publicas_son_las_clasificadas(): void
    {
        $rutas = $this->voiceRoutes();
        $this->assertCount(17, $rutas, '4 públicas y 13 del CRM');
        $publicas = [];
        foreach ($rutas as $r) {
            $mw = $r->gatherMiddleware();
            $tope = null;
            $limitador = false;
            foreach ($mw as $m) {
                if (is_string($m) && str_starts_with($m, LimitRequestBodySize::class.':')) {
                    $tope = (int) substr($m, strlen(LimitRequestBodySize::class) + 1);
                }
                $limitador = $limitador || (is_string($m) && str_starts_with($m, 'throttle:'));
            }
            $clave = AuthorizationMap::routeKey($r);
            $this->assertTrue($limitador, "{$clave} sin limitador");
            if (str_starts_with($r->uri(), 'api/admin/voice/')) {
                $this->assertSame(self::ADMIN_CAP, $tope, "{$clave}: tope del CRM");
                $this->assertTrue(AuthorizationMap::isAdministrative($r));
                $permiso = AuthorizationMap::resolve($r);
                $this->assertIsString($permiso, $clave);
                $this->assertStringStartsWith('telephony.', $permiso, "{$clave} resuelve a {$permiso}");

                continue;
            }
            $this->assertSame(self::CAPS[$r->uri()], $tope, "{$clave}: tope de D8-16");
            $this->assertFalse(AuthorizationMap::isAdministrative($r), "{$clave} es pública: fuera del universo administrativo");
            $this->assertSame(['POST'], $r->methods(), $clave);
            $publicas[] = $clave;
        }
        sort($publicas);
        $clasificadas = array_keys(AuthorizationMap::PUBLIC_VOICE_ROUTES);
        sort($clasificadas);
        $this->assertSame($clasificadas, $publicas, 'las públicas son exactamente las clasificadas, cada una con su razón');
        foreach (AuthorizationMap::PUBLIC_VOICE_ROUTES as $razon) {
            $this->assertGreaterThan(40, mb_strlen($razon));
        }
        $this->assertSame(0, Artisan::call('auth:coverage', ['--unmapped' => true]), Artisan::output());
    }

    public function test_un_cuerpo_por_encima_del_tope_es_413_en_todas(): void
    {
        $uuid = (string) Str::uuid();
        // Las públicas: el tope va ANTES de su autenticación (nada se lee). Las del CRM: el grupo `api` exige la sesión
        // primero (ProtectAdminPaths) y el tope va después, con una sesión válida.
        $sesion = $this->adminHeaders();
        $vistas = 0;
        foreach ($this->voiceRoutes() as $r) {
            if ($r->methods()[0] !== 'POST') {
                continue;   // un GET no lleva cuerpo
            }
            $admin = str_starts_with($r->uri(), 'api/admin/voice/');
            $uri = '/'.str_replace(['{tool}', '{uuid}'], ['consultar_planes', $uuid], $r->uri());
            $tope = $admin ? self::ADMIN_CAP : self::CAPS[$r->uri()];
            $servidor = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
            if ($admin) {
                $servidor['HTTP_AUTHORIZATION'] = $sesion['Authorization'];
            }
            $respuesta = $this->call('POST', $uri, [], [], [], $servidor, str_repeat('a', $tope + 1));
            $this->assertSame(413, $respuesta->status(), "POST {$uri}");
            $this->assertSame('payload_too_large', $respuesta->json('code'), $uri);
            $vistas++;
        }
        $this->assertSame(4 + 8, $vistas, 'las 4 públicas y los 8 POST del CRM');
        Http::assertNothingSent();
    }
}
