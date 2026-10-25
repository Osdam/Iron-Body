<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioPublication;
use App\Support\Access\CrmPermission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * QUIÉN PUEDE QUÉ EN LA PUBLICACIÓN (contrato H5 §A2, §A5 y §A7; A12 «API»):
 * los cuatro ojos por IDENTIDAD (no por permiso) al publicar y al promover, con
 * su rastro en el libro; cada llave abre solo lo suyo (`ultron.publish`:
 * publicar y promover; `ultron.rollback`: revertir y Legacy); el Administrador
 * lee la publicación pero no publica ni revierte; el token compartido y los
 * roles sin Studio no entran; la vista previa y las acciones del detalle dicen
 * lo que puede QUIEN MIRA. La matriz de las 38 rutas por perfil vive en
 * `UltronStudioPermissionsTest`.
 */
class UltronStudioPublicationAccessTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** Las cinco rutas nuevas del Hito 5, con su permiso. */
    private const H5_ROUTES = [
        ['GET', 'versions/{uuid}/publication-preview', 'ultron.view'],
        ['GET', 'versions/{uuid}/canary-evidence', 'ultron.view'],
        ['POST', 'versions/{uuid}/promote', 'ultron.publish'],
        ['GET', 'publications', 'ultron.view'],
        ['POST', 'publications/legacy', 'ultron.rollback'],
    ];

    private Admin $ana;

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->importBaseline();
        Carbon::setTestNow(Carbon::parse('2026-11-03T15:00:00Z'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03T15:00:00Z'));
        $this->openKeys(['marketing.ultron.studio_config' => 'all']);
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private static function body(string $ruta): array
    {
        return match ($ruta) {
            'versions/{uuid}/publish' => ['clientRequestId' => (string) Str::uuid(), 'previewHash' => str_repeat('0', 64), 'acknowledgedNoEffect' => []],
            'versions/{uuid}/promote' => ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => str_repeat('0', 64)],
            'versions/{uuid}/rollback', 'publications/legacy' => ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Motivo de la prueba de acceso'],
            default => [],
        };
    }

    private function hit(string $metodo, string $ruta, string $uuid, array $cabeceras): TestResponse
    {
        return $this->json($metodo, '/api/admin/ultron-studio/'.str_replace('{uuid}', $uuid, $ruta), self::body($ruta), $cabeceras);
    }

    private function assertForbidden(TestResponse $r, string $permiso, string $donde): void
    {
        $this->assertSame(403, $r->status(), "{$donde}: ".$r->getContent());
        $this->assertSame('forbidden', $r->json('code'), $donde);
        $this->assertSame($permiso, $r->json('required_permission'), $donde);
    }

    public function test_los_cuatro_ojos_por_identidad_al_publicar_y_al_promover_y_quedan_en_el_libro(): void
    {
        // La autora TIENE la llave de publicar: no basta, es su versión.
        $autora = $this->personWith(['ultron.view', 'ultron.edit_draft', 'ultron.publish'], 'Autora que publica');
        $v = $this->approvedVersion($autora, $this->root, 'De la autora');

        for ($i = 0; $i < 3; $i++) {
            $this->publishAs($autora, $v->uuid)->assertStatus(403)->assertJsonPath('code', 'four_eyes_required')
                ->assertJsonPath('message', 'Nadie publica lo que creó: pide a otra persona con permiso que lo haga.');
        }
        $denegado = StudioAuditEvent::query()->where('event', 'version.four_eyes_denied')->sole();
        $this->assertSame([$v->uuid, ['action' => 'publish']], [$denegado->subject_uuid, $denegado->diff]);
        $this->assertSame([0, 'APPROVED'], [StudioPublication::query()->count(), $v->fresh()->status]);

        // Otra persona sí; y promover tampoco lo hace la autora.
        $this->publishAs($this->root, $v->uuid)->assertCreated();
        $this->stampTurns(3, $v->uuid, now()->addHour());
        Carbon::setTestNow(Carbon::parse('2026-11-04T16:00:00Z'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-04T16:00:00Z'));
        $this->freshSessions();
        $this->promoteAs($autora, $v->uuid)->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $this->assertSame(['action' => 'promote'], StudioAuditEvent::query()->where('event', 'version.four_eyes_denied')->orderByDesc('id')->first()->diff);
        $this->promoteAs($this->root, $v->uuid)->assertCreated();
        // Revertir no pide cuatro ojos: es el sentido seguro (la autora no tiene la llave aquí).
        $this->assertForbidden($this->rollbackAs($autora, $v->uuid), 'ultron.rollback', 'autora sin llave de revertir');
    }

    public function test_cada_llave_abre_solo_lo_suyo(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root);
        $publica = $this->personWith(['ultron.view', 'ultron.publish'], 'Solo publica');
        $revierte = $this->personWith(['ultron.view', 'ultron.rollback'], 'Solo revierte');

        $this->publishAs($publica, $v->uuid)->assertCreated();
        $this->assertForbidden($this->rollbackAs($publica, $v->uuid), 'ultron.rollback', 'publica · revertir');
        $this->assertForbidden($this->legacyAs($publica), 'ultron.rollback', 'publica · legacy');

        $this->assertForbidden($this->hit('POST', 'versions/{uuid}/promote', $v->uuid, $this->as($revierte)), 'ultron.publish', 'revierte · promover');
        $this->assertForbidden($this->hit('POST', 'versions/{uuid}/publish', $v->uuid, $this->as($revierte)), 'ultron.publish', 'revierte · publicar');
        $this->rollbackAs($revierte, $v->uuid)->assertCreated();
        $this->assertChainMatchesStates();
    }

    public function test_el_administrador_lee_la_publicacion_pero_no_publica_promueve_ni_revierte(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root);
        $administradora = $this->person(Admin::ROLE_ADMINISTRADOR, 'Administradora');
        $cabeceras = $this->as($administradora);

        $this->hit('GET', 'versions/{uuid}/publication-preview', $v->uuid, $cabeceras)->assertOk()->assertJsonPath('data.blocking', ['forbidden']);
        $this->hit('GET', 'publications', $v->uuid, $cabeceras)->assertOk();
        $this->hit('GET', 'versions/{uuid}/canary-evidence', $v->uuid, $cabeceras)->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        foreach ([['versions/{uuid}/publish', 'ultron.publish'], ['versions/{uuid}/promote', 'ultron.publish'], ['versions/{uuid}/rollback', 'ultron.rollback'], ['publications/legacy', 'ultron.rollback']] as [$ruta, $permiso]) {
            $this->assertForbidden($this->hit('POST', $ruta, $v->uuid, $cabeceras), $permiso, "Administrador · POST {$ruta}");
        }
        $this->assertSame(0, StudioPublication::query()->count());
    }

    public function test_sin_sesion_el_token_compartido_y_los_roles_sin_studio_no_entran_en_las_cinco_rutas_nuevas(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root);
        foreach (self::H5_ROUTES as [$metodo, $ruta]) {
            $this->hit($metodo, $ruta, $v->uuid, [])->assertStatus(401);
        }
        $perfiles = [
            'token' => $this->sharedTokenHeaders(),
            'Recepción' => $this->as($this->person(Admin::ROLE_RECEPCION, 'Recepción')),
            'Entrenador' => $this->as($this->person(CrmPermission::ROLE_ENTRENADOR, 'Entrenador')),
            'Administrativo' => $this->as($this->person(Admin::ROLE_ADMINISTRATIVO, 'Administrativo')),
            'Mercadeo' => $this->adminWithPermissions(['marketing.view', 'marketing.manage', 'marketing.agent.manage']),
        ];
        foreach ($perfiles as $quien => $cabeceras) {
            foreach (self::H5_ROUTES as [$metodo, $ruta, $permiso]) {
                $this->assertForbidden($this->hit($metodo, $ruta, $v->uuid, $cabeceras), $permiso, "{$quien} · {$metodo} {$ruta}");
            }
        }
        $this->assertSame(0, StudioPublication::query()->count());
    }

    public function test_la_vista_previa_y_las_acciones_dicen_lo_que_puede_quien_mira(): void
    {
        $v = $this->approvedVersion($this->ana, $this->root);
        $soloMira = $this->personWith(['ultron.view'], 'Solo mira');

        // Ana es la autora y, además, el Administrador no tiene la llave de publicar.
        $deAna = $this->previewOf($this->ana, $v->uuid);
        $this->assertFalse($deAna['requirements']['fourEyes']['ok']);
        $this->assertSame(['forbidden', 'four_eyes_required'], $deAna['blocking']);
        $this->assertSame(['forbidden'], $this->previewOf($soloMira, $v->uuid)['blocking']);
        $this->assertTrue($this->previewOf($this->root, $v->uuid)['publishable']);
        // La misma huella para todos: la vista previa es de la versión, no de quien mira.
        $this->assertSame($this->previewOf($this->root, $v->uuid)['previewHash'], $deAna['previewHash']);

        $acciones = fn (Admin $quien): array => $this->studio('GET', "versions/{$v->uuid}", $quien)->assertOk()->json('data.actions');
        $this->assertSame(['allowed' => true], $acciones($this->root)['publish']);
        $this->assertSame('invalid_transition', $acciones($this->root)['promote']['code']);
        $this->assertSame('not_active_publication', $acciones($this->root)['rollback']['code']);
        $this->assertSame('forbidden', $acciones($soloMira)['publish']['code']);
        $this->assertSame('forbidden', $acciones($this->ana)['publish']['code'], 'el Administrador no tiene la llave');

        // Ya en canario: promover y revertir abiertos para quien tiene las llaves.
        $this->publishAs($this->root, $v->uuid)->assertCreated();
        $this->assertSame(['allowed' => true], $acciones($this->root)['promote']);
        $this->assertSame(['allowed' => true], $acciones($this->root)['rollback']);
        $this->assertSame('invalid_transition', $acciones($this->root)['publish']['code']);
        // Con la llave del motor en `canary`, promover no.
        config(['marketing.ultron.studio_config' => 'canary']);
        $this->assertSame('scope_not_allowed', $acciones($this->root)['promote']['code']);
    }

    public function test_un_rechazo_por_llave_sobre_un_uuid_inventado_no_escribe_en_el_libro(): void
    {
        // El libro no se puede vaciar: un uuid que no existe no deja ninguna fila.
        config(['ultron_studio.publishing_enabled' => false]);
        for ($i = 0; $i < 5; $i++) {
            $this->hit('POST', 'versions/{uuid}/publish', (string) Str::uuid(), $this->as($this->root))->assertStatus(409)->assertJsonPath('code', 'publishing_disabled');
        }
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'version.publish_refused')->count());

        // Sobre una que existe, sí (deduplicado, como en F).
        $v = $this->approvedVersion($this->ana, $this->root);
        $this->hit('POST', 'versions/{uuid}/publish', $v->uuid, $this->as($this->root))->assertStatus(409);
        $this->hit('POST', 'versions/{uuid}/promote', $v->uuid, $this->as($this->root))->assertStatus(409);
        $this->assertSame([['code' => 'publishing_disabled']], StudioAuditEvent::query()->where('event', 'version.publish_refused')->pluck('diff')->all());
        $this->assertSame([['code' => 'publishing_disabled']], StudioAuditEvent::query()->where('event', 'version.promote_refused')->pluck('diff')->all());
    }
}
