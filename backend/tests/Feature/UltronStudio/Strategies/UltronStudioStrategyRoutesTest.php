<?php

namespace Tests\Feature\UltronStudio\Strategies;

use App\Http\Controllers\Crm\UltronStudio\StudioRouteCount;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioStrategyController as C;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioVoiceController;
use App\Http\Middleware\LimitRequestBodySize;
use App\Models\Admin;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LAS 30 RUTAS DEL GOBIERNO DE ESTRATEGIAS (contrato H9, §7, D9-13; protección
 * 1): existen con su tope de 64 KB y su cupo, el total del Studio es la
 * constante (E2-6), nadie entra sin una persona, el token compartido no entra,
 * y con la bandera maestra APAGADA todas responden 409 `strategies_disabled`
 * (también las lecturas) sin tocar nada. Cero red.
 */
class UltronStudioStrategyRoutesTest extends TestCase
{
    use RefreshDatabase;
    use StrategyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private static function url(string $ruta): string
    {
        return '/api/admin/ultron-studio/'.str_replace('{uuid}', (string) Str::uuid(), $ruta);
    }

    public function test_las_30_rutas_existen_con_su_tope_y_su_cupo_y_el_total_es_la_constante(): void
    {
        $this->assertCount(30, C::ROUTES);
        $this->assertSame(C::PREVIOUS_STUDIO_ROUTES + count(C::ROUTES), C::STUDIO_ROUTE_COUNT_WITH_STRATEGIES);
        // El 77 es del Hito 8 y no cambia: el total con estrategias vive en el lado del H9.
        $this->assertSame(C::PREVIOUS_STUDIO_ROUTES, UltronStudioVoiceController::STUDIO_ROUTE_COUNT);

        $delStudio = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/admin/ultron-studio')) {
                $delStudio[AuthorizationMap::routeKey($route)] = $route;
            }
        }
        $this->assertCount(StudioRouteCount::TOTAL, $delStudio);

        foreach (C::ROUTES as [$metodo, $ruta]) {
            $clave = "{$metodo} api/admin/ultron-studio/{$ruta}";
            $this->assertArrayHasKey($clave, $delStudio, "falta {$clave}");
            $mw = $delStudio[$clave]->gatherMiddleware();
            $this->assertContains(LimitRequestBodySize::class.':65536', $mw, "{$clave} sin el tope de 64 KB");
            $lectura = $metodo === 'GET' || $ruta === 'strategies/{uuid}/validate';
            $this->assertContains($lectura ? 'throttle:ultron-studio-read' : 'throttle:ultron-studio-write', $mw, "{$clave} sin su cupo");
            $cara = in_array("{$metodo} {$ruta}", C::EXPENSIVE_ROUTES, true);
            $this->assertSame($cara, in_array('throttle:ultron-studio-evidence', $mw, true), "{$clave}: el limitador de las lecturas caras");
        }
    }

    public function test_sin_sesion_401_y_con_el_token_compartido_403_en_las_30(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (C::ROUTES as [$metodo, $ruta]) {
            $this->assertSame(401, $this->json($metodo, self::url($ruta), [])->status(), "sin sesión · {$metodo} {$ruta}");
            $r = $this->json($metodo, self::url($ruta), [], $this->sharedTokenHeaders());
            $this->assertSame(403, $r->status(), "token compartido · {$metodo} {$ruta}: ".$r->getContent());
        }
    }

    public function test_con_la_bandera_maestra_apagada_las_30_responden_409_strategies_disabled_sin_escribir(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withoutRouteMap();
        $this->grantStrategyPermissions();
        $this->strategyFlags(enabled: false);
        $super = $this->account(Admin::ROLE_SUPER_ADMIN);
        $antes = DB::table('ultron_studio_audit_events')->count();
        foreach (C::ROUTES as [$metodo, $ruta]) {
            $r = $this->json($metodo, self::url($ruta), [], $this->headersOf($super));
            $this->assertSame(409, $r->status(), "{$metodo} {$ruta}: ".$r->getContent());
            $this->assertSame('strategies_disabled', $r->json('code'), "{$metodo} {$ruta}");
        }
        $this->assertSame($antes, DB::table('ultron_studio_audit_events')->count(), 'Con la bandera apagada no se escribe nada.');
    }

    public function test_una_cuenta_sin_ultron_view_no_entra_aunque_tenga_otra_llave(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->withoutRouteMap();
        $this->strategyFlags(enabled: true);
        $recepcion = $this->account(Admin::ROLE_RECEPCION);
        foreach ([['GET', 'strategies'], ['GET', 'strategies/catalog'], ['POST', 'strategies']] as [$metodo, $ruta]) {
            $r = $this->json($metodo, self::url($ruta), [], $this->headersOf($recepcion));
            $this->assertSame(403, $r->status(), "{$metodo} {$ruta}");
            $this->assertSame('forbidden', $r->json('code'));
        }
    }

    /**
     * LA PRIMERA CERRADURA: AuthorizationMap (B9-run, «Traspasos» de §11)
     * clasifica cada ruta con su llave de D9-13 y el catálogo la ofrece. Es la
     * lista que S9 le pasa: HASTA QUE B9-run LA AÑADA, ESTA PRUEBA CAE (a
     * propósito, como la de S8 en el H8).
     */
    public function test_authorization_map_clasifica_cada_ruta_con_su_llave(): void
    {
        $esperado = [];
        foreach (C::ROUTES as [$metodo, $ruta, $permiso]) {
            $esperado["{$metodo} api/admin/ultron-studio/{$ruta}"] = $permiso;
        }
        ksort($esperado);
        $real = [];
        foreach (Route::getRoutes() as $route) {
            $clave = AuthorizationMap::routeKey($route);
            if (array_key_exists($clave, $esperado)) {
                $real[$clave] = AuthorizationMap::resolve($route);
            }
        }
        ksort($real);
        $this->assertSame($esperado, $real);
        foreach (['ultron.strategies_edit', 'ultron.strategies_publish', 'ultron.strategies_rollback', 'ultron.person_bindings_manage'] as $llave) {
            $this->assertContains($llave, CrmPermission::all(), "el catálogo no ofrece {$llave}");
        }
    }
}
