<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Quién puede qué en las INTEGRACIONES del Studio, contra el servidor
 * (contrato H6 §I7.1, «Matriz de las pruebas»): las 18 rutas nuevas, una a
 * una, con cada perfil. «Permitido» quiere decir que la puerta deja pasar: la
 * respuesta puede ser 2xx, 404, 409 o 422 según el cuerpo y las banderas, pero
 * nunca 401 ni el 403 `forbidden` / `person_required`. Las 38 de antes siguen
 * en `UltronStudioPermissionsTest`.
 */
class UltronStudioIntegrationsPermissionsTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** Las 18 rutas del contrato §I7.1, con su número y su permiso. */
    private const ROUTES = [
        [39, 'GET', 'integrations/status', 'ultron.view'],
        [40, 'GET', 'integrations/meta/campaigns', 'ultron.view'],
        [41, 'GET', 'integrations/meta/links', 'ultron.view'],
        [42, 'GET', 'integrations/canaries', 'ultron.view'],
        [43, 'GET', 'integrations/twilio', 'ultron.view'],
        [44, 'GET', 'integrations/twilio/calls', 'ultron.view'],
        [45, 'GET', 'integrations/voice', 'ultron.view'],
        [46, 'GET', 'integrations/config', 'ultron.view'],
        [47, 'POST', 'integrations/meta/links', 'ultron.campaigns_manage'],
        [48, 'POST', 'integrations/meta/links/{uuid}/deactivate', 'ultron.campaigns_manage'],
        [49, 'POST', 'integrations/canaries', 'ultron.canary_manage'],
        [50, 'POST', 'integrations/canaries/{uuid}/authorize', 'ultron.canary_authorize'],
        [51, 'POST', 'integrations/canaries/{uuid}/activate', 'ultron.canary_manage'],
        [52, 'POST', 'integrations/canaries/{uuid}/deactivate', 'ultron.canary_manage'],
        [53, 'POST', 'integrations/canaries/{uuid}/revoke', 'ultron.canary_manage'],
        [54, 'POST', 'integrations/canaries/{uuid}/expectation', 'ultron.canary_manage'],
        [55, 'POST', 'integrations/config/acknowledge', 'ultron.integrations_manage'],
        [56, 'POST', 'integrations/twilio/probe', 'ultron.integrations_manage'],
    ];

    /** Las cuatro llaves nuevas, en el orden del catálogo. */
    private const NEW_KEYS = ['ultron.campaigns_manage', 'ultron.canary_manage', 'ultron.canary_authorize', 'ultron.integrations_manage'];

    private string $canary;

    protected function setUp(): void
    {
        parent::setUp();
        // Muchas peticiones por persona: el cupo no es lo que se prueba aquí (salvo en su prueba).
        $this->withoutMiddleware(ThrottleRequests::class);
        // Un número pendiente de otra persona, sobre el que pegar a las rutas con {uuid}.
        $this->integrationsFlags(writes: true);
        $this->canary = (string) $this->registerCanary($this->person(Admin::ROLE_SUPER_ADMIN, 'Autora'), '+573000000031')->assertCreated()->json('data.number.uuid');
        $this->integrationsFlags(writes: false);
    }

    public function test_el_mapa_resuelve_exactamente_las_56_rutas_y_las_18_nuevas_con_su_permiso(): void
    {
        $esperado = [];
        foreach (self::ROUTES as [, $metodo, $ruta, $permiso]) {
            $esperado["{$metodo} api/admin/ultron-studio/{$ruta}"] = $permiso;
        }
        ksort($esperado);

        $real = [];
        $todas = 0;
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/admin/ultron-studio')) {
                $todas++;
                if (str_starts_with($route->uri(), 'api/admin/ultron-studio/integrations/')) {
                    $real[AuthorizationMap::routeKey($route)] = AuthorizationMap::resolve($route);
                }
            }
        }
        ksort($real);

        $this->assertSame($esperado, $real);
        $this->assertSame(56, $todas);
        // La sonda lleva su propio limitador además del de escritura.
        $sonda = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/admin/ultron-studio/integrations/twilio/probe');
        $this->assertContains('throttle:ultron-studio-probe', $sonda->gatherMiddleware());
        $this->assertContains('throttle:ultron-studio-write', $sonda->gatherMiddleware());
    }

    public function test_sin_sesion_401_y_token_compartido_403_en_las_18(): void
    {
        foreach (self::ROUTES as [$n, $metodo, $ruta]) {
            $this->assertSame(401, $this->json($metodo, $this->url($ruta), $this->body($n))->status(), "sin sesión · {$n}");
            $r = $this->json($metodo, $this->url($ruta), $this->body($n), $this->sharedTokenHeaders());
            $this->assertSame(403, $r->status(), "token compartido · {$n}: ".$r->getContent());
        }
    }

    public function test_recepcion_entrenador_y_administrativo_no_entran_en_ninguna(): void
    {
        foreach ([Admin::ROLE_RECEPCION, CrmPermission::ROLE_ENTRENADOR, Admin::ROLE_ADMINISTRATIVO] as $rol) {
            $quien = $this->person($rol, "Persona {$rol}");
            foreach (self::ROUTES as [$n, $metodo, $ruta, $permiso]) {
                $this->assertForbidden($this->studio($metodo, $this->path($ruta), $quien, $this->body($n)), $permiso, "{$rol} · {$n}");
            }
        }
    }

    public function test_el_administrador_lee_y_escribe_salvo_autorizar_reconocer_y_la_sonda(): void
    {
        $admin = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Administradora');
        foreach (self::ROUTES as [$n, $metodo, $ruta, $permiso]) {
            $r = $this->studio($metodo, $this->path($ruta), $admin, $this->body($n));
            if (in_array($n, [50, 55, 56], true)) {
                $this->assertForbidden($r, $permiso, "Administrador · {$n}");
            } elseif ($n <= 46) {
                $this->assertSame(200, $r->status(), "Administrador · {$n}: ".$r->getContent());
            } else {
                $this->assertAllowed($r, "Administrador · {$n}");
                // Con la bandera apagada, las escrituras del hito responden 409 (después de la puerta).
                $this->assertSame('integrations_writes_disabled', $r->json('code'), "Administrador · {$n}");
            }
        }
    }

    public function test_el_super_admin_entra_en_todas(): void
    {
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        foreach (self::ROUTES as [$n, $metodo, $ruta]) {
            $r = $this->studio($metodo, $this->path($ruta), $root, $this->body($n));
            $this->assertAllowed($r, "Super Admin · {$n}");
            $esperado = match (true) {
                $n <= 46 => null,
                $n === 55 => 'config_changed',
                $n === 56 => 'probe_disabled',
                default => 'integrations_writes_disabled',
            };
            $n <= 46 ? $r->assertOk() : $r->assertStatus(409)->assertJsonPath('code', $esperado);
        }
    }

    public function test_una_llave_de_escritura_sin_ver_el_studio_es_403_antes_de_buscar_el_recurso(): void
    {
        foreach (self::ROUTES as [$n, $metodo, $ruta, $permiso]) {
            if ($n <= 46) {
                continue;
            }
            $quien = $this->personWith([$permiso], "Sin ver {$n}");
            // Un uuid que NO existe: si se buscara antes, sería 404 (oráculo de existencia).
            $r = $this->studio($metodo, str_replace('{uuid}', (string) Str::uuid(), $ruta), $quien, $this->body($n));
            $this->assertForbidden($r, 'ultron.view', "{$permiso} sin ver · {$n}");
        }
    }

    public function test_cada_llave_nueva_abre_solo_lo_suyo(): void
    {
        $this->integrationsFlags(writes: true, probe: true);
        foreach (self::NEW_KEYS as $llave) {
            $quien = $this->personWith(['ultron.view', $llave], "Solo {$llave}");
            foreach (self::ROUTES as [$n, $metodo, $ruta, $permiso]) {
                if ($permiso === 'ultron.view') {
                    continue;
                }
                $r = $this->studio($metodo, $this->path($ruta), $quien, $this->body($n));
                $permiso === $llave ? $this->assertAllowed($r, "{$llave} · {$n}") : $this->assertForbidden($r, $permiso, "{$llave} · {$n}");
            }
        }
    }

    public function test_valores_por_defecto_catalogo_y_token_compartido(): void
    {
        $de = fn (string $rol): array => array_values(array_intersect(self::NEW_KEYS, CrmPermission::defaultsFor($rol)));
        $this->assertSame(self::NEW_KEYS, $de(Admin::ROLE_SUPER_ADMIN));
        $this->assertSame(['ultron.campaigns_manage', 'ultron.canary_manage'], $de(Admin::ROLE_ADMINISTRADOR));
        foreach ([Admin::ROLE_RECEPCION, CrmPermission::ROLE_ENTRENADOR, Admin::ROLE_ADMINISTRATIVO] as $rol) {
            $this->assertSame([], $de($rol), "{$rol} no recibe nada de las integraciones.");
        }
        foreach (self::NEW_KEYS as $llave) {
            $this->assertNotContains($llave, CrmPermission::readOnly());
        }

        $filas = array_column(array_values(array_filter(PermissionCatalog::rows(), fn (array $f): bool => $f['domain'] === 'ultron')), null, 'key');
        $this->assertSame(self::NEW_KEYS, array_slice(array_keys($filas), 8));
        $this->assertSame('Asociar campañas de Meta', $filas['ultron.campaigns_manage']['label']);
        $this->assertSame('Administrar números canarios', $filas['ultron.canary_manage']['label']);
        $this->assertSame('Autorizar números canarios de otra persona', $filas['ultron.canary_authorize']['label']);
        $this->assertSame('Configuración autorizada y comprobación de Twilio', $filas['ultron.integrations_manage']['label']);
        $this->assertSame('Asociar campañas de Meta a la línea base o a una versión del Studio, y desactivar esas asociaciones. Solo queda registrado en el Studio: no cambia nada en Meta ni en lo que ULTRON dice o hace.', $filas['ultron.campaigns_manage']['help']);
        $this->assertSame('Registrar números de prueba con el consentimiento de su titular, activarlos cuando otra persona los autorizó, desactivarlos y revocarlos. Son datos personales: se guardan como huella, y cifrados solo si son de voz. Nadie activa un número que registró. El motor no los lee en este hito: ULTRON sigue atendiendo solo la conversación canaria configurada en el servidor.', $filas['ultron.canary_manage']['help']);
        $this->assertSame('Autorizar números canarios registrados por otra persona (cuatro ojos): nadie autoriza lo que registró. No llega por defecto al Administrador.', $filas['ultron.canary_authorize']['help']);
        $this->assertSame('Reconocer como autorizada la configuración vigente de Meta Ads, Twilio y voz, sin ver ni editar secretos (solo huellas), y lanzar la comprobación de solo lectura de Twilio. No llega por defecto al Administrador.', $filas['ultron.integrations_manage']['help']);
    }

    public function test_la_sonda_tiene_su_propio_cupo_de_6_por_hora_y_persona(): void
    {
        // Este caso sí pasa por los limitadores (el setUp los quitó para el resto).
        $this->withMiddleware(ThrottleRequests::class);
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        foreach (range(1, 6) as $i) {
            $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'probe_disabled');
        }
        $r = $this->integrations('POST', 'twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertStatus(429);
        $r->assertJsonPath('ok', false)->assertJsonPath('code', 'too_many_requests');
        $this->assertGreaterThan(0, (int) $r->json('retry_after'));
        // El cubo es suyo: las demás escrituras siguen, y el de otra persona también.
        $this->integrations('POST', 'config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => str_repeat('a', 64)])->assertStatus(409);
        $this->integrations('POST', 'twilio/probe', $this->person(Admin::ROLE_SUPER_ADMIN, 'Otra'), ['clientRequestId' => (string) Str::uuid()])->assertStatus(409);
    }

    // ────────────────────────────────────────────────────────────────────────

    private function path(string $ruta): string
    {
        return str_replace('{uuid}', $this->canary, $ruta);
    }

    private function url(string $ruta): string
    {
        return '/api/admin/ultron-studio/'.$this->path($ruta);
    }

    /** Un cuerpo plausible: lo que importa es la puerta. @return array<string, mixed> */
    private function body(int $n): array
    {
        return match ($n) {
            47 => ['clientRequestId' => (string) Str::uuid(), 'campaign' => 'campaign:0000000000000000', 'target' => ['kind' => 'baseline']],
            48, 52, 53 => ['reason' => 'Motivo de la matriz'],
            49 => ['clientRequestId' => (string) Str::uuid(), 'channel' => 'whatsapp', 'phone' => '+573000000032', 'alias' => 'Móvil de la matriz', 'consentAttested' => true],
            54 => ['versionUuid' => null],
            55 => ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => str_repeat('0', 64)],
            56 => ['clientRequestId' => (string) Str::uuid()],
            default => [],
        };
    }

    private function assertForbidden(TestResponse $r, string $permiso, string $donde): void
    {
        $this->assertSame(403, $r->status(), "{$donde}: se esperaba 403 y llegó {$r->status()} ".$r->getContent());
        $this->assertSame('forbidden', $r->json('code'), $donde);
        $this->assertSame($permiso, $r->json('required_permission'), $donde);
    }

    private function assertAllowed(TestResponse $r, string $donde): void
    {
        $this->assertNotSame(401, $r->status(), "{$donde}: 401 ".$r->getContent());
        $this->assertFalse($r->status() === 403 && in_array($r->json('code'), ['forbidden', 'person_required'], true), "{$donde}: la puerta no dejó pasar ".$r->getContent());
        $this->assertLessThan(500, $r->status(), "{$donde}: {$r->status()} ".$r->getContent());
    }
}
