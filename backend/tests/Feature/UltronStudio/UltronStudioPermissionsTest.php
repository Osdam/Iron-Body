<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Support\Access\AuthorizationMap;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Quién puede qué en ULTRON STUDIO, contra el servidor (contrato §A4 y §A8;
 * contrato G §A6 para las dos rutas de lectura del Hito G; y contrato H4 §A7
 * para las 12 del laboratorio).
 *
 * Las 33 rutas, una a una, con cada perfil: sin sesión, token compartido, los
 * roles sin Studio, un rol con TODO Mercadeo (incluido pausar el agente), el
 * Administrador y el Super Admin. «Permitido» quiere decir que la puerta deja
 * pasar: la respuesta puede ser 200, 404, 409 o 422 según el cuerpo, pero
 * nunca 401 ni el 403 `forbidden` del mapa.
 *
 * El laboratorio va ENCENDIDO en modo `fake` en estas pruebas (sin red y coste
 * 0): así sus lecturas responden 200 y se ve la puerta, no la bandera. La
 * bandera apagada (y que sin permiso sea 403 aunque esté apagada) la prueba
 * `UltronStudioLabFlagTest`.
 */
class UltronStudioPermissionsTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** Las 19 rutas del contrato §A3, las 2 del contrato G §A6 y las 12 del H4 §A7, con su permiso. */
    private const ROUTES = [
        ['GET', 'status', 'ultron.view'],
        ['GET', 'catalog', 'ultron.view'],
        ['GET', 'draft', 'ultron.view'],
        ['PUT', 'draft', 'ultron.edit_draft'],
        ['POST', 'draft/discard', 'ultron.edit_draft'],
        ['GET', 'versions', 'ultron.view'],
        ['POST', 'versions', 'ultron.edit_draft'],
        ['GET', 'versions/{uuid}', 'ultron.view'],
        ['POST', 'versions/{uuid}/validate', 'ultron.view'],
        ['POST', 'versions/{uuid}/submit', 'ultron.edit_draft'],
        ['POST', 'versions/{uuid}/approve', 'ultron.approve'],
        ['POST', 'versions/{uuid}/reject', 'ultron.approve'],
        ['POST', 'versions/{uuid}/publish', 'ultron.publish'],
        ['POST', 'versions/{uuid}/rollback', 'ultron.rollback'],
        ['GET', 'rules', 'ultron.view'],
        ['POST', 'rules', 'ultron.manage_rules'],
        ['PATCH', 'rules/{uuid}', 'ultron.manage_rules'],
        ['POST', 'rules/{uuid}/archive', 'ultron.manage_rules'],
        ['GET', 'audit', 'ultron.view'],
        ['GET', 'engine/readings', 'ultron.view'],
        ['GET', 'compare', 'ultron.view'],
        ['GET', 'lab/status', 'ultron.view'],
        ['GET', 'lab/scenarios', 'ultron.view'],
        ['GET', 'lab/runs', 'ultron.view'],
        ['GET', 'lab/runs/{uuid}', 'ultron.view'],
        ['POST', 'lab/estimate', 'ultron.view'],
        ['GET', 'lab/events', 'ultron.view'],
        ['POST', 'lab/scenarios', 'ultron.lab_manage'],
        ['PATCH', 'lab/scenarios/{uuid}', 'ultron.lab_manage'],
        ['POST', 'lab/scenarios/{uuid}/archive', 'ultron.lab_manage'],
        ['POST', 'lab/runs', 'ultron.lab_run'],
        ['POST', 'lab/runs/{uuid}/cancel', 'ultron.lab_run'],
        ['POST', 'lab/runs/{uuid}/verdict', 'ultron.approve'],
    ];

    /** Las ocho llaves del Studio, en el orden del catálogo. */
    private const STUDIO_KEYS = ['ultron.view', 'ultron.edit_draft', 'ultron.manage_rules', 'ultron.lab_manage', 'ultron.lab_run', 'ultron.approve', 'ultron.publish', 'ultron.rollback'];

    private string $version;

    private string $rule;

    private string $scenario;

    private string $run;

    protected function setUp(): void
    {
        parent::setUp();

        // Una versión ajena (de otra persona) y una regla del Studio sobre las
        // que pegar a las rutas con {uuid}.
        $autora = $this->person(Admin::ROLE_SUPER_ADMIN, 'Autora de la versión');
        $this->version = (string) $this->createVersion($autora)->assertCreated()->json('data.uuid');
        $this->rule = (string) $this->studio('POST', 'rules', $autora, [
            'description' => 'No prometer clases que no existen.',
            'type' => 'restriction',
            'priority' => 'high',
            'layers' => ['composer'],
            'clientRequestId' => (string) Str::uuid(),
        ])->assertCreated()->json('data.uuid');

        // Hito 4: el laboratorio en `fake`, sus escenarios y una ejecución
        // (terminada: la cola de la suite es síncrona) sobre la versión.
        $this->labFake();
        $this->importLabScenarios();
        $this->scenario = (string) $this->lab('POST', 'scenarios', $autora, $this->scenarioBody())->assertCreated()->json('data.uuid');
        $this->run = (string) $this->launch($autora, self::versionTarget($this->version), ['C-01'])->assertCreated()->json('data.uuid');
    }

    public function test_el_mapa_resuelve_exactamente_las_33_rutas_del_contrato(): void
    {
        $esperado = [];
        foreach (self::ROUTES as [$metodo, $ruta, $permiso]) {
            $esperado["{$metodo} api/admin/ultron-studio/{$ruta}"] = $permiso;
        }
        ksort($esperado);

        $real = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/admin/ultron-studio')) {
                $real[AuthorizationMap::routeKey($route)] = AuthorizationMap::resolve($route);
            }
        }
        ksort($real);

        $this->assertSame($esperado, $real);
    }

    public function test_sin_sesion_es_401_en_las_33(): void
    {
        foreach (self::ROUTES as [$metodo, $ruta]) {
            $this->json($metodo, $this->url($ruta), $this->body($ruta, $metodo))
                ->assertStatus(401);
        }
    }

    public function test_el_token_compartido_no_entra_en_ninguna(): void
    {
        foreach (self::ROUTES as [$metodo, $ruta, $permiso]) {
            $this->assertForbidden($this->json($metodo, $this->url($ruta), $this->body($ruta, $metodo), $this->sharedTokenHeaders()), $permiso, "token · {$metodo} {$ruta}");
        }
    }

    public function test_recepcion_entrenador_y_administrativo_no_entran_en_ninguna(): void
    {
        foreach ([Admin::ROLE_RECEPCION, CrmPermission::ROLE_ENTRENADOR, Admin::ROLE_ADMINISTRATIVO] as $rol) {
            $quien = $this->person($rol, "Persona {$rol}");
            foreach (self::ROUTES as [$metodo, $ruta, $permiso]) {
                $this->assertForbidden($this->studio($metodo, $this->path($ruta), $quien, $this->body($ruta, $metodo)), $permiso, "{$rol} · {$metodo} {$ruta}");
            }
        }
    }

    public function test_todo_mercadeo_incluido_pausar_el_agente_no_abre_el_studio(): void
    {
        $headers = $this->adminWithPermissions(['marketing.view', 'marketing.manage', 'marketing.agent.manage']);

        foreach (self::ROUTES as [$metodo, $ruta, $permiso]) {
            $this->assertForbidden($this->json($metodo, $this->url($ruta), $this->body($ruta, $metodo), $headers), $permiso, "mercadeo · {$metodo} {$ruta}");
        }
    }

    public function test_el_administrador_entra_en_todo_salvo_publicar_revertir_y_lanzar_ejecuciones(): void
    {
        $administrador = $this->person(Admin::ROLE_ADMINISTRADOR, 'Administradora');

        foreach (self::ROUTES as [$metodo, $ruta, $permiso]) {
            $r = $this->studio($metodo, $this->path($ruta), $administrador, $this->body($ruta, $metodo));
            // Lanzar y cancelar ejecuciones gasta presupuesto: solo Super Admin por
            // defecto (contrato H4, decisión 2).
            if (in_array($permiso, ['ultron.publish', 'ultron.rollback', 'ultron.lab_run'], true)) {
                $this->assertForbidden($r, $permiso, "Administrador · {$metodo} {$ruta}");

                continue;
            }
            $this->assertAllowed($r, "Administrador · {$metodo} {$ruta}");
        }
    }

    public function test_el_super_admin_entra_en_todo_y_publicar_responde_409(): void
    {
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');

        foreach (self::ROUTES as [$metodo, $ruta, $permiso]) {
            $r = $this->studio($metodo, $this->path($ruta), $root, $this->body($ruta, $metodo));
            $this->assertAllowed($r, "Super Admin · {$metodo} {$ruta}");
            if (in_array($permiso, ['ultron.publish', 'ultron.rollback'], true)) {
                $r->assertStatus(409)->assertJsonPath('code', 'publishing_disabled');
            }
        }
    }

    public function test_con_solo_ver_se_lee_pero_no_se_escribe(): void
    {
        $headers = $this->adminWithPermissions(['ultron.view']);

        foreach (self::ROUTES as [$metodo, $ruta, $permiso]) {
            $r = $this->json($metodo, $this->url($ruta), $this->body($ruta, $metodo), $headers);
            if ($permiso === 'ultron.view') {
                $r->assertOk();

                continue;
            }
            $this->assertForbidden($r, $permiso, "solo ver · {$metodo} {$ruta}");
        }
    }

    public function test_cada_escritura_pide_su_llave_y_basta_con_ella(): void
    {
        // Con `ultron.view` + la llave de la ruta, la puerta deja pasar; con
        // `ultron.view` + OTRA llave del Studio, no.
        foreach (['ultron.edit_draft', 'ultron.manage_rules', 'ultron.approve', 'ultron.lab_manage', 'ultron.lab_run'] as $llave) {
            $headers = $this->adminWithPermissions(['ultron.view', $llave]);
            foreach (self::ROUTES as [$metodo, $ruta, $permiso]) {
                if ($permiso === 'ultron.view') {
                    continue;
                }
                $r = $this->json($metodo, $this->url($ruta), $this->body($ruta, $metodo), $headers);
                if ($permiso === $llave) {
                    $this->assertAllowed($r, "{$llave} · {$metodo} {$ruta}");
                } else {
                    $this->assertForbidden($r, $permiso, "{$llave} · {$metodo} {$ruta}");
                }
            }
        }
    }

    public function test_ninguna_llave_del_studio_llega_al_token_compartido(): void
    {
        $soloLectura = CrmPermission::readOnly();

        foreach (self::STUDIO_KEYS as $llave) {
            $this->assertNotContains($llave, $soloLectura, "{$llave} no puede llegar al token compartido.");
        }
        $this->assertContains('ultron', CrmPermission::SHARED_TOKEN_EXCLUDED_DOMAINS);
    }

    public function test_valores_por_defecto_de_cada_rol(): void
    {
        $studio = self::STUDIO_KEYS;
        $de = fn (string $rol): array => array_values(array_intersect($studio, CrmPermission::defaultsFor($rol)));

        $this->assertSame($studio, $de(Admin::ROLE_SUPER_ADMIN));
        // Quien aprueba no es quien publica: publicar y revertir se conceden a
        // mano. Y lanzar ejecuciones del laboratorio, que gastan, tampoco llega
        // por defecto (contrato H4, decisión 2); los escenarios y el veredicto, sí.
        $this->assertSame(['ultron.view', 'ultron.edit_draft', 'ultron.manage_rules', 'ultron.lab_manage', 'ultron.approve'], $de(Admin::ROLE_ADMINISTRADOR));
        foreach ([Admin::ROLE_RECEPCION, CrmPermission::ROLE_ENTRENADOR, Admin::ROLE_ADMINISTRATIVO] as $rol) {
            $this->assertSame([], $de($rol), "{$rol} no recibe nada del Studio por defecto.");
        }
    }

    public function test_el_catalogo_describe_las_ocho_llaves_en_su_orden(): void
    {
        $filas = array_values(array_filter(PermissionCatalog::rows(), fn (array $f): bool => $f['domain'] === 'ultron'));

        // `lab_manage` (6.4) y `lab_run` (6.6) van entre reglas y aprobar.
        $this->assertSame(self::STUDIO_KEYS, array_column($filas, 'key'));
        $this->assertSame('ULTRON Studio', $filas[0]['domain_label']);
        $this->assertSame('account_tree', $filas[0]['domain_icon']);
        $porLlave = array_column($filas, null, 'key');
        $this->assertSame('Ver ULTRON Studio', $porLlave['ultron.view']['label']);
        $this->assertSame('Aprobar o rechazar versiones de otra persona', $porLlave['ultron.approve']['label']);
        $this->assertStringContainsString('Nadie aprueba lo que creó', (string) $porLlave['ultron.approve']['help']);
        $this->assertStringContainsString('emitir el veredicto del laboratorio sobre ejecuciones de versiones ajenas', (string) $porLlave['ultron.approve']['help']);
        $this->assertSame('Administrar escenarios del laboratorio', $porLlave['ultron.lab_manage']['label']);
        $this->assertSame('Lanzar ejecuciones del laboratorio (consume presupuesto)', $porLlave['ultron.lab_run']['label']);
        $this->assertStringContainsString('No llega por defecto al Administrador', (string) $porLlave['ultron.lab_run']['help']);
        foreach (['ultron.publish', 'ultron.rollback'] as $llave) {
            $this->assertStringContainsString('desactivado en el servidor; no llega por defecto al Administrador', (string) $porLlave[$llave]['help']);
        }
        foreach ($filas as $f) {
            $this->assertNotSame('', $f['label']);
            $this->assertNotNull($f['help'], "{$f['key']} necesita su ayuda.");
        }
    }

    public function test_los_cupos_responden_429_con_retry_after_y_no_se_mezclan(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');

        // Escritura: 30 por minuto y credencial. Descartar sin borrador no
        // escribe nada, pero cuenta.
        foreach (range(1, 30) as $i) {
            $this->studio('POST', 'draft/discard', $ana, ['baseRevision' => 0])->assertOk();
        }
        $r = $this->studio('POST', 'draft/discard', $ana, ['baseRevision' => 0])->assertStatus(429);
        $r->assertJsonPath('ok', false)->assertJsonPath('code', 'too_many_requests');
        $this->assertIsInt($r->json('retry_after'));
        $this->assertGreaterThan(0, $r->json('retry_after'));

        // El cubo de lectura es otro, y el de otra persona también.
        $this->studio('GET', 'status', $ana)->assertOk();
        $this->studio('POST', 'draft/discard', $this->person(Admin::ROLE_ADMINISTRADOR, 'Bea'), ['baseRevision' => 0])->assertOk();
    }

    // ────────────────────────────────────────────────────────────────────────

    private function path(string $ruta): string
    {
        $uuid = match (true) {
            str_starts_with($ruta, 'rules/') => $this->rule,
            str_starts_with($ruta, 'lab/scenarios/') => $this->scenario,
            str_starts_with($ruta, 'lab/runs/') => $this->run,
            default => $this->version,
        };

        return str_replace('{uuid}', $uuid, $ruta);
    }

    private function url(string $ruta): string
    {
        return '/api/admin/ultron-studio/'.$this->path($ruta);
    }

    /**
     * Un cuerpo plausible para cada ruta: lo que importa es la puerta, así que
     * basta con que no sea un 401/403 por formato.
     *
     * @return array<string, mixed>
     */
    private function body(string $ruta, string $metodo): array
    {
        // Las lecturas del laboratorio no llevan cuerpo (`GET lab/runs` comparte
        // ruta con `POST lab/runs`).
        if ($metodo === 'GET' && str_starts_with($ruta, 'lab/')) {
            return [];
        }

        return match ($ruta) {
            'draft' => ['baseRevision' => 0, 'content' => $this->baseline()],
            'draft/discard' => ['baseRevision' => 0],
            'versions' => ['label' => 'Desde la matriz', 'note' => null, 'draftRevision' => 1, 'clientRequestId' => (string) Str::uuid()],
            'versions/{uuid}/approve' => ['acknowledgedWarnings' => [], 'note' => null],
            'versions/{uuid}/reject' => ['reason' => 'Motivo de la matriz'],
            'rules' => ['description' => 'Regla de la matriz', 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'], 'clientRequestId' => (string) Str::uuid()],
            'rules/{uuid}' => ['baseRevision' => 1, 'description' => 'Editada desde la matriz'],
            'rules/{uuid}/archive' => ['baseRevision' => 1, 'reason' => 'Archivada desde la matriz'],
            'compare' => ['from' => 'baseline', 'to' => 'draft'],
            'lab/estimate' => ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01')]],
            'lab/scenarios' => $this->scenarioBody(),
            'lab/scenarios/{uuid}' => ['baseRevision' => 1, 'title' => 'Editado desde la matriz'],
            'lab/scenarios/{uuid}/archive' => ['reason' => 'Archivado desde la matriz'],
            'lab/runs' => ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-02')], 'acceptedMaxCostMicros' => 0, 'clientRequestId' => (string) Str::uuid()],
            'lab/runs/{uuid}/verdict' => ['decision' => 'needs_changes', 'note' => 'Nota desde la matriz', 'resultsHash' => str_repeat('0', 64)],
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
        $this->assertFalse($r->status() === 403 && $r->json('code') === 'forbidden', "{$donde}: la puerta no dejó pasar ".$r->getContent());
        $this->assertNotSame('person_required', $r->json('code'), $donde);
        $this->assertLessThan(500, $r->status(), "{$donde}: {$r->status()} ".$r->getContent());
    }
}
