<?php

namespace Tests\Feature\UltronStudio;

use App\Http\Controllers\Crm\UltronStudio\StudioRouteCount;
use App\Http\Controllers\Crm\UltronStudio\UltronStudioVoiceController as C;
use App\Http\Middleware\LimitRequestBodySize;
use App\Models\Admin;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LA PUERTA de las rutas del Studio del Hito 8 (contrato H8, «API», D8-22 y
 * E2-6; S8-b): cada una de las 21 rutas nuevas (15 de voz y 6 del laboratorio
 * hablado) existe, la clasifica `AuthorizationMap` con su permiso (de B8), lleva
 * el tope de 64 KB y su cupo, exige sesión (401) y una persona (403 con el
 * token compartido), y el total de rutas del Studio es la constante que leen
 * las guardas en lugar de un literal.
 */
class UltronStudioVoiceRoutesTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private static function nuevas(): array
    {
        return [...C::ROUTES, ...C::LAB_ROUTES];
    }

    private static function url(string $ruta): string
    {
        return '/api/admin/ultron-studio/'.str_replace('{uuid}', (string) Str::uuid(), $ruta);
    }

    public function test_las_21_rutas_existen_con_su_tope_y_su_cupo_y_el_total_es_la_constante(): void
    {
        $this->assertCount(15, C::ROUTES);
        $this->assertCount(6, C::LAB_ROUTES);
        $this->assertSame(C::PREVIOUS_STUDIO_ROUTES + count(C::ROUTES) + count(C::LAB_ROUTES), C::STUDIO_ROUTE_COUNT);

        $delStudio = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/admin/ultron-studio')) {
                $delStudio[AuthorizationMap::routeKey($route)] = $route;
            }
        }
        $this->assertCount(StudioRouteCount::TOTAL, $delStudio);

        foreach (self::nuevas() as [$metodo, $ruta]) {
            $clave = "{$metodo} api/admin/ultron-studio/{$ruta}";
            $this->assertArrayHasKey($clave, $delStudio, "falta {$clave}");
            $mw = $delStudio[$clave]->gatherMiddleware();
            $this->assertContains(LimitRequestBodySize::class.':65536', $mw, "{$clave} sin el tope de 64 KB");
            $this->assertContains($metodo === 'GET' || $ruta === 'voice/compile-preview' ? 'throttle:ultron-studio-read' : 'throttle:ultron-studio-write', $mw, "{$clave} sin su cupo");
        }
        foreach (['POST api/admin/ultron-studio/voice/publish', 'POST api/admin/ultron-studio/voice/promote', 'GET api/admin/ultron-studio/voice/canary-evidence'] as $clave) {
            $this->assertContains('throttle:ultron-studio-evidence', $delStudio[$clave]->gatherMiddleware(), "{$clave} sin el limitador de la evidencia");
        }
        $this->assertContains('throttle:ultron-studio-lab-run', $delStudio['POST api/admin/ultron-studio/voice-lab/sessions']->gatherMiddleware());
    }

    /**
     * La primera cerradura: AuthorizationMap (B8, «Traspasos») clasifica cada
     * ruta nueva con su permiso de D8-22 y el catálogo lo ofrece. Hasta que B8
     * las añada, esta prueba cae: es la lista que S8 le pasa.
     */
    public function test_authorization_map_clasifica_cada_ruta_nueva_con_su_permiso(): void
    {
        $esperado = [];
        foreach (self::nuevas() as [$metodo, $ruta, $permiso]) {
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

        foreach (['ultron.voice_manage', 'ultron.voice_publish', 'ultron.voice_lab_run'] as $llave) {
            $this->assertContains($llave, CrmPermission::all(), "el catálogo no ofrece {$llave}");
        }
    }

    public function test_sin_sesion_401_y_con_el_token_compartido_403_en_las_21(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (self::nuevas() as [$metodo, $ruta]) {
            $this->assertSame(401, $this->json($metodo, self::url($ruta), [])->status(), "sin sesión · {$metodo} {$ruta}");
            $r = $this->json($metodo, self::url($ruta), [], $this->sharedTokenHeaders());
            $this->assertSame(403, $r->status(), "token compartido · {$metodo} {$ruta}: ".$r->getContent());
        }
    }

    public function test_una_persona_sin_llaves_del_studio_no_entra_en_ninguna(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $quien = $this->person(Admin::ROLE_RECEPCION, 'Recepción');
        foreach (self::nuevas() as [$metodo, $ruta]) {
            $this->assertSame(403, $this->studio($metodo, str_replace('{uuid}', (string) Str::uuid(), $ruta), $quien)->status(), "{$metodo} {$ruta}");
        }
    }
}
