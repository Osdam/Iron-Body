<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioRule;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioAudit;
use App\Services\UltronStudio\StudioRules;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Endurecimientos tras la revisión de seguridad de la API (H1, H3, H4) y la
 * propuesta P5 del especialista de datos: una llave de escritura sin
 * `ultron.view` no abre nada; los intentos denegados no inundan un libro que
 * no se puede vaciar; las reglas del Studio tienen tope; y una regla no se
 * borra ni por SQL.
 */
class UltronStudioHardeningTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    public function test_una_llave_de_escritura_sin_ultron_view_no_abre_nada(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $uuid = $this->submittedVersion($ana);

        // Solo `ultron.approve`: ni leer la versión ni aprobarla a ciegas. Y sin
        // oráculo de existencia: un uuid inexistente también responde 403, no 404.
        $soloAprobar = $this->adminWithPermissions(['ultron.approve']);
        $this->json('GET', "/api/admin/ultron-studio/versions/{$uuid}", [], $soloAprobar)->assertStatus(403);
        $this->json('POST', '/api/admin/ultron-studio/versions/'.Str::uuid().'/approve', ['acknowledgedWarnings' => [], 'note' => null], $soloAprobar)
            ->assertStatus(403)->assertJsonPath('required_permission', 'ultron.view');
        $this->json('POST', "/api/admin/ultron-studio/versions/{$uuid}/approve", ['acknowledgedWarnings' => [], 'note' => null], $soloAprobar)
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden')
            ->assertJsonPath('required_permission', 'ultron.view');
        $this->assertSame('REVIEW_REQUIRED', StudioVersion::query()->where('uuid', $uuid)->value('status'));

        // Solo `ultron.edit_draft`: tampoco escribe el borrador (el único que hay es el de Ana).
        $borradores = DB::table('ultron_studio_drafts')->count();
        $soloEditar = $this->adminWithPermissions(['ultron.edit_draft']);
        $this->json('PUT', '/api/admin/ultron-studio/draft', ['baseRevision' => 0, 'content' => $this->baseline()], $soloEditar)
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'ultron.view');
        $this->assertSame($borradores, DB::table('ultron_studio_drafts')->count());

        // Con las dos llaves, sí.
        $completo = $this->adminWithPermissions(['ultron.view', 'ultron.edit_draft']);
        $this->json('PUT', '/api/admin/ultron-studio/draft', ['baseRevision' => 0, 'content' => $this->baseline()], $completo)->assertOk();
    }

    public function test_los_intentos_denegados_no_inundan_el_libro(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $uuid = $this->submittedVersion($ana);

        for ($i = 0; $i < 5; $i++) {
            $this->studio('POST', "versions/{$uuid}/approve", $ana, ['acknowledgedWarnings' => [], 'note' => null])
                ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
            $this->studio('POST', "versions/{$uuid}/publish", $root)->assertStatus(409)->assertJsonPath('code', 'publishing_disabled');
        }
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'version.four_eyes_denied')->count());
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'version.publish_refused')->count());

        // Pasada la ventana se vuelve a anotar: el libro sigue contando intentos, pero no por segundo.
        $this->travel(StudioAudit::DENIED_WINDOW_MINUTES + 1)->minutes();
        $this->studio('POST', "versions/{$uuid}/publish", $root)->assertStatus(409);
        $this->studio('POST', "versions/{$uuid}/publish", $root)->assertStatus(409);
        $this->assertSame(2, StudioAuditEvent::query()->where('event', 'version.publish_refused')->count());
    }

    public function test_las_reglas_del_studio_tienen_tope_y_las_archivadas_no_cuentan(): void
    {
        // Cien creaciones seguidas superan el cupo de escritura (30/min), que aquí no es lo que se prueba.
        $this->withoutMiddleware(ThrottleRequests::class);
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $cuerpo = fn (int $i, string $clientRequestId) => [
            'description' => "Regla de prueba número {$i} para el tope",
            'type' => 'restriction',
            'priority' => 'normal',
            'layers' => ['composer'],
            'clientRequestId' => $clientRequestId,
        ];
        $nueva = fn (int $i, ?string $clientRequestId = null) => $this->studio('POST', 'rules', $ana, $cuerpo($i, $clientRequestId ?? (string) Str::uuid()));

        $primera = null;
        $idPrimera = (string) Str::uuid();
        for ($i = 1; $i <= StudioRules::MAX_LIVE_STUDIO_RULES; $i++) {
            $r = $nueva($i, $i === 1 ? $idPrimera : null)->assertCreated();
            $primera ??= $r->json('data');
        }
        $nueva(0)->assertStatus(409)->assertJsonPath('code', 'rule_limit_reached');

        // Con el tope alcanzado, repetir una creación ya hecha sigue siendo replay (200), no 409.
        $nueva(1, $idPrimera)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.code', $primera['code']);

        // Archivar una libera un hueco.
        $this->studio('POST', "rules/{$primera['uuid']}/archive", $ana, ['baseRevision' => $primera['revision'], 'reason' => 'Hace hueco para la prueba'])->assertOk();
        $nueva(0)->assertCreated();
        $this->assertSame(StudioRules::MAX_LIVE_STUDIO_RULES, DB::table('ultron_studio_rules')->where('source', 'studio')->where('status', '<>', 'archived')->count());
    }

    public function test_la_escritura_condicionada_no_deja_activar_ni_cambiar_de_identidad_a_una_regla_del_studio(): void
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $regla = $this->studio('POST', 'rules', $ana, [
            'description' => 'Regla del Studio para probar la guarda de escritura',
            'type' => 'restriction',
            'priority' => 'normal',
            'layers' => ['composer'],
            'clientRequestId' => (string) Str::uuid(),
        ])->assertCreated()->json('data');
        $modelo = StudioRule::query()->where('uuid', $regla['uuid'])->sole();

        // La guarda vive en código (además del CHECK de PostgreSQL): el UPDATE
        // del constructor de consultas no pasa por el modelo.
        $metodo = new \ReflectionMethod(StudioRules::class, 'conditionalWrite');
        $servicio = app(StudioRules::class);
        foreach ([['status' => 'active'], ['enforcement' => 'code'], ['source' => 'legacy_baseline'], ['code' => 'R-99']] as $valores) {
            try {
                $metodo->invoke($servicio, $modelo, $modelo->revision, $valores);
                $this->fail('Tendría que lanzar: '.json_encode($valores));
            } catch (\LogicException $e) {
                $this->assertStringContainsString('solo cambia de contenido o se archiva', $e->getMessage());
            }
        }
        $this->assertSame('draft', $modelo->fresh()->status);
        $this->assertSame('none', $modelo->fresh()->enforcement);
    }

    public function test_una_regla_no_se_borra_ni_por_sql(): void
    {
        $this->importBaseline();
        $antes = DB::table('ultron_studio_rules')->count();

        try {
            // En una transacción anidada: en PostgreSQL el fallo abortaría la de la prueba.
            DB::transaction(fn () => DB::table('ultron_studio_rules')->where('code', 'R-01')->delete());
            $this->fail('El DELETE de una regla tendría que fallar por el disparador.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ultron_studio:', $e->getMessage());
        }

        $this->assertSame($antes, DB::table('ultron_studio_rules')->count());
    }
}
