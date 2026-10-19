<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabScenario;
use App\Services\UltronStudio\StudioLabCatalog;
use App\Services\UltronStudio\StudioLabPersonalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los escenarios del laboratorio (contrato H4 §A2; Enmienda 2, §1–§3): alta
 * idempotente, edición parcial con `baseRevision`, archivo, catálogo de solo
 * lectura, tope de vivos, el filtro de datos personales e invisibles, el 413,
 * la importación del catálogo y que el libro de F/G no cambia.
 */
class UltronStudioLabScenariosTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->labFake();
        $this->importLabScenarios();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
    }

    public function test_alta_con_su_forma_ids_por_posicion_y_evento(): void
    {
        $cuerpo = $this->scenarioBody();
        $r = $this->lab('POST', 'scenarios', $this->ana, $cuerpo)->assertCreated();
        $s = $r->json('data');

        $this->assertSame([
            'uuid', 'code', 'source', 'status', 'revision', 'category', 'title', 'description', 'turns', 'facts', 'criteria',
            'contentHash', 'createdBy', 'updatedBy', 'createdAt', 'updatedAt', 'archivedAt', 'archiveReason',
        ], array_keys($s));
        $this->assertSame('E-001', $s['code']);
        $this->assertSame(['studio', 'active', 1], [$s['source'], $s['status'], $s['revision']]);
        $this->assertSame(['c1', 'c2'], array_column($s['criteria'], 'id'));
        $this->assertSame(['paymentState' => 'none', 'handoffRequested' => false], $s['facts']);
        $this->assertSame(['name' => 'Ana Pérez', 'role' => null], $s['createdBy']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $s['contentHash']);
        $this->assertSame(1, StudioLabEvent::query()->where('event', 'scenario.created')->where('subject_uuid', $s['uuid'])->count());

        // Hechos ausentes: los de por defecto, con sus dos claves exactas.
        $sinHechos = $cuerpo;
        unset($sinHechos['facts']);
        $sinHechos['clientRequestId'] = (string) Str::uuid();
        $this->assertSame(['paymentState' => 'none', 'handoffRequested' => false], $this->lab('POST', 'scenarios', $this->ana, $sinHechos)->assertCreated()->json('data.facts'));
        $this->assertSame('E-002', StudioLabScenario::query()->where('source', 'studio')->orderByDesc('id')->value('code'));
    }

    public function test_repetir_la_peticion_es_200_replayed_y_reutilizar_la_llave_es_409(): void
    {
        $cuerpo = $this->scenarioBody();
        $uuid = $this->lab('POST', 'scenarios', $this->ana, $cuerpo)->assertCreated()->json('data.uuid');

        $this->lab('POST', 'scenarios', $this->ana, $cuerpo)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.uuid', $uuid);
        $this->assertSame(1, StudioLabScenario::query()->where('source', 'studio')->count());

        $this->lab('POST', 'scenarios', $this->ana, ['title' => 'Otro título distinto'] + $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $bea = $this->person(Admin::ROLE_ADMINISTRADOR, 'Bea');
        $this->lab('POST', 'scenarios', $bea, $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->assertSame(1, StudioLabScenario::query()->where('source', 'studio')->count());
    }

    public function test_los_del_catalogo_son_de_solo_lectura(): void
    {
        $c04 = StudioLabScenario::query()->where('code', 'C-04')->sole();
        $this->lab('PATCH', "scenarios/{$c04->uuid}", $this->ana, ['baseRevision' => 1, 'title' => 'Cambiado'])->assertStatus(409)->assertJsonPath('code', 'catalog_scenario_read_only');
        $this->lab('POST', "scenarios/{$c04->uuid}/archive", $this->ana, ['reason' => 'No se puede'])->assertStatus(409)->assertJsonPath('code', 'catalog_scenario_read_only');
        $this->assertSame(1, (int) $c04->fresh()->revision);
        $this->assertSame('active', $c04->fresh()->status);
    }

    public function test_editar_es_parcial_con_revision_y_sin_cambios_no_escribe(): void
    {
        $s = $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody())->assertCreated()->json('data');

        $editado = $this->lab('PATCH', "scenarios/{$s['uuid']}", $this->ana, ['baseRevision' => 1, 'title' => 'Título nuevo del escenario'])->assertOk()->json('data');
        $this->assertSame(2, $editado['revision']);
        $this->assertSame('Título nuevo del escenario', $editado['title']);
        $this->assertSame($s['turns'], $editado['turns'], 'un PATCH parcial no toca lo que no llegó');
        $this->assertNotSame($s['contentHash'], $editado['contentHash']);
        $evento = StudioLabEvent::query()->where('event', 'scenario.updated')->sole();
        $this->assertSame(['fromRevision' => 1, 'toRevision' => 2], array_intersect_key($evento->diff, ['fromRevision' => 0, 'toRevision' => 0]));
        $this->assertSame(['title'], array_column($evento->diff['changes'], 'field'));

        // Una revisión vieja: 409 con lo que hay ahora.
        $this->lab('PATCH', "scenarios/{$s['uuid']}", $this->ana, ['baseRevision' => 1, 'title' => 'Otra vez'])
            ->assertStatus(409)->assertJsonPath('code', 'scenario_conflict')->assertJsonPath('current.revision', 2)->assertJsonPath('current.title', 'Título nuevo del escenario');

        // Lo mismo que ya tiene: 200, sin revisión nueva ni traza.
        $this->lab('PATCH', "scenarios/{$s['uuid']}", $this->ana, ['baseRevision' => 2, 'title' => 'Título nuevo del escenario'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertSame(1, StudioLabEvent::query()->where('event', 'scenario.updated')->count());

        // Los criterios viajan sin `id`: el servidor los vuelve a numerar.
        $nuevos = $this->lab('PATCH', "scenarios/{$s['uuid']}", $this->ana, ['baseRevision' => 2, 'criteria' => [
            ['kind' => 'should', 'text' => 'Ser amable', 'rules' => []],
            ['kind' => 'must_not', 'text' => 'Confirmar el pago', 'rules' => ['R-04']],
            ['kind' => 'must', 'text' => 'Ofrecer verificarlo', 'rules' => []],
        ]])->assertOk()->json('data.criteria');
        $this->assertSame(['c1', 'c2', 'c3'], array_column($nuevos, 'id'));
    }

    public function test_archivar_con_motivo_y_un_archivado_no_se_toca(): void
    {
        $s = $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody())->assertCreated()->json('data');

        $this->lab('POST', "scenarios/{$s['uuid']}/archive", $this->ana, ['reason' => 'abc'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $archivado = $this->lab('POST', "scenarios/{$s['uuid']}/archive", $this->ana, ['reason' => 'Ya no aplica'])->assertOk()->json('data');
        $this->assertSame(['archived', 2, 'Ya no aplica'], [$archivado['status'], $archivado['revision'], $archivado['archiveReason']]);
        $this->assertNotNull($archivado['archivedAt']);

        $this->lab('PATCH', "scenarios/{$s['uuid']}", $this->ana, ['baseRevision' => 2, 'title' => 'Otro'])->assertStatus(409)->assertJsonPath('code', 'scenario_archived');
        $this->lab('POST', "scenarios/{$s['uuid']}/archive", $this->ana, ['reason' => 'Otra vez'])->assertStatus(409)->assertJsonPath('code', 'scenario_archived');
        $this->lab('POST', 'scenarios/'.Str::uuid().'/archive', $this->ana, ['reason' => 'No existe'])->assertStatus(404)->assertJsonPath('code', 'scenario_not_found');

        // La lista: activos por defecto, archivados a petición, por categoría.
        $this->assertNotContains($s['uuid'], array_column($this->lab('GET', 'scenarios', $this->ana)->json('data'), 'uuid'));
        $this->assertSame([$s['uuid']], array_column($this->lab('GET', 'scenarios?status=archived', $this->ana)->json('data'), 'uuid'));
        $this->assertSame(['C-04', 'C-05'], array_column($this->lab('GET', 'scenarios?category=pago', $this->ana)->assertOk()->json('data'), 'code'));
        $this->lab('GET', 'scenarios?status=todos', $this->ana)->assertStatus(422);
    }

    public function test_tope_de_cien_vivos_y_los_archivados_no_cuentan(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $contenido = ['turns' => [['role' => 'prospect', 'text' => 'Hola']], 'facts' => StudioLabCatalog::DEFAULT_FACTS, 'criteria' => [['id' => 'c1', 'kind' => 'must', 'text' => 'Saludar', 'rules' => []]]];
        for ($i = 1; $i <= StudioLabCatalog::MAX_LIVE_SCENARIOS; $i++) {
            StudioLabScenario::query()->forceCreate([
                'code' => sprintf('E-%03d', $i), 'source' => 'studio', 'status' => $i === 1 ? 'archived' : 'active', 'category' => 'otro',
                'title' => "Sembrado {$i}", 'description' => null, 'content' => $contenido, 'content_hash' => str_repeat('a', 64), 'revision' => 1,
                'archived_at' => $i === 1 ? now() : null, 'archive_reason' => $i === 1 ? 'Sembrado archivado' : null,
            ]);
        }

        // 99 vivos: cabe uno más (el archivado no cuenta).
        $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody())->assertCreated()->assertJsonPath('data.code', 'E-101');
        $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody())->assertStatus(409)->assertJsonPath('code', 'scenario_limit_reached');
    }

    public function test_datos_personales_e_invisibles_son_422_con_el_mensaje_del_contrato_en_su_campo(): void
    {
        $mensaje = StudioLabPersonalData::MESSAGE.'.';
        $casos = [
            'title' => ['title' => 'Escribe a ana@correo.com'],
            'description' => ['description' => 'Su cédula es 1.075.234.567'],
            'turns.1.text' => ['turns' => [['role' => 'agent', 'text' => 'Hola'], ['role' => 'prospect', 'text' => 'Mi número es +57 300 123 4567']]],
            'turns.0.text' => ['turns' => [['role' => 'prospect', 'text' => "Hola\u{200B}mundo"]]],
            'criteria.0.text' => ['criteria' => [['kind' => 'must', 'text' => "Separar\u{2028}párrafos", 'rules' => []]]],
            'criteria.1.text' => ['criteria' => [['kind' => 'must', 'text' => 'Bien escrito', 'rules' => []], ['kind' => 'must', 'text' => "Marca\u{061C}árabe", 'rules' => []]]],
        ];
        foreach ($casos as $clave => $cambio) {
            $r = $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody($cambio))->assertStatus(422);
            $this->assertSame([$clave => [$mensaje]], $r->json('errors'), "{$clave}: ".$r->getContent());
        }

        // Lo que parece un precio y no un documento pasa.
        $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['turns' => [['role' => 'prospect', 'text' => '¿80 mil o $80.000? ¿Hay 2x1?']]]))->assertCreated();
        $this->assertSame(1, StudioLabScenario::query()->where('source', 'studio')->count());
    }

    public function test_la_forma_y_lo_que_no_se_ve_en_ella(): void
    {
        // El último turno es de la persona.
        $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['turns' => [['role' => 'prospect', 'text' => 'Hola'], ['role' => 'agent', 'text' => 'Hola, ¿qué tal?']]]))
            ->assertStatus(422)->assertJsonValidationErrors('turns');
        // Reglas que existen (las del código, o S-NNN sin archivar), sin repetir dentro del criterio.
        $r = $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['criteria' => [['kind' => 'must', 'text' => 'Una regla', 'rules' => ['R-04', 'R-99', 'S-001']]]]))->assertStatus(422);
        $this->assertSame(['criteria.0.rules.1', 'criteria.0.rules.2'], array_keys($r->json('errors')));
        $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['criteria' => [['kind' => 'must', 'text' => 'Repetida', 'rules' => ['R-04', 'R-04']]]]))
            ->assertStatus(422)->assertJsonValidationErrors('criteria.0.rules');
        $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['criteria' => [['kind' => 'must', 'text' => 'Mal código', 'rules' => ['X-1']]]]))
            ->assertStatus(422)->assertJsonValidationErrors('criteria.0.rules.0');
        // Una S-NNN viva sí.
        $this->newStudioRule($this->ana)->assertCreated();
        $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['criteria' => [['kind' => 'must', 'text' => 'Regla del Studio', 'rules' => ['S-001']]]]))->assertCreated();
        // Hechos con claves exactas.
        $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['facts' => ['paymentState' => 'none', 'otro' => true]]))->assertStatus(422)->assertJsonValidationErrors('facts');
        $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['facts' => ['paymentState' => 'pagado']]))->assertStatus(422)->assertJsonValidationErrors('facts.paymentState');
        // Listas por encima de su tope: 422 SOLO por el tope (dos fases).
        $r = $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['criteria' => array_fill(0, 500, ['kind' => 'x'])]))->assertStatus(422);
        $this->assertSame(['criteria'], array_keys($r->json('errors')));
        $r = $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['turns' => array_fill(0, 500, ['role' => 'x'])]))->assertStatus(422);
        $this->assertSame(['turns'], array_keys($r->json('errors')));
        // 16 KB codificado como mucho (ocho turnos de 600 caracteres de 4 bytes).
        $turnos = array_fill(0, 7, ['role' => 'agent', 'text' => str_repeat('🏋', 600)]);
        $turnos[] = ['role' => 'prospect', 'text' => str_repeat('🏋', 600)];
        $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['turns' => $turnos]))->assertStatus(422)->assertJsonValidationErrors('content');
        $this->assertSame(1, StudioLabScenario::query()->where('source', 'studio')->count());
    }

    public function test_un_cuerpo_de_mas_de_64_kb_es_413(): void
    {
        $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody(['description' => str_repeat('x', 70000)]))
            ->assertStatus(413)
            ->assertExactJson(['ok' => false, 'code' => 'payload_too_large', 'message' => 'La petición supera el tamaño máximo (64 KB).']);
    }

    public function test_el_libro_de_f_y_g_no_cambia(): void
    {
        $antes = StudioAuditEvent::query()->count();
        $s = $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody())->assertCreated()->json('data');
        $this->lab('PATCH', "scenarios/{$s['uuid']}", $this->ana, ['baseRevision' => 1, 'title' => 'Otro título'])->assertOk();
        $this->lab('POST', "scenarios/{$s['uuid']}/archive", $this->ana, ['reason' => 'Prueba del libro'])->assertOk();

        $this->assertSame($antes, StudioAuditEvent::query()->count(), 'El laboratorio escribió en el libro de F/G.');
        $this->assertSame(0, $this->studio('GET', 'audit', $this->ana)->assertOk()->json('data.total') - $antes);
        $this->assertSame(['scenario.archived', 'scenario.updated', 'scenario.created'], array_column(
            $this->lab('GET', "events?subjectType=scenario&subjectUuid={$s['uuid']}", $this->ana)->assertOk()->json('data.data'), 'event'));
    }

    public function test_la_importacion_es_idempotente_sincroniza_retira_y_no_pisa_al_personal(): void
    {
        $this->assertSame(12, StudioLabScenario::query()->where('source', 'catalog')->count());
        $eventos = StudioLabEvent::query()->count();
        $this->assertSame(13, $eventos, '12 importados y el resumen del sistema');

        // Otra vez: nada que hacer, ni una traza.
        $this->assertSame(0, Artisan::call('ultron-studio:lab-import-scenarios'));
        $this->assertStringContainsString('nada que importar', Artisan::output());
        $this->assertSame($eventos, StudioLabEvent::query()->count());

        // Alguien alteró una fila y otra que el código ya no tiene sigue viva.
        StudioLabScenario::query()->where('code', 'C-07')->update(['title' => 'Alterado a mano']);
        $retirado = StudioLabScenario::query()->forceCreate([
            'code' => 'C-99', 'source' => 'catalog', 'status' => 'active', 'category' => 'otro', 'title' => 'Retirado del código',
            'description' => null, 'content' => ['turns' => [['role' => 'prospect', 'text' => 'Hola']], 'facts' => StudioLabCatalog::DEFAULT_FACTS, 'criteria' => []],
            'content_hash' => str_repeat('b', 64), 'revision' => 1,
        ]);

        // --dry-run lo dice y no escribe.
        $this->assertSame(0, Artisan::call('ultron-studio:lab-import-scenarios', ['--dry-run' => true]));
        $this->assertStringContainsString('simulación', Artisan::output());
        $this->assertSame('Alterado a mano', StudioLabScenario::query()->where('code', 'C-07')->value('title'));

        $this->assertSame(0, Artisan::call('ultron-studio:lab-import-scenarios'));
        $c07 = StudioLabScenario::query()->where('code', 'C-07')->sole();
        $this->assertSame([StudioLabCatalog::byCode('C-07')['title'], 2], [$c07->title, (int) $c07->revision]);
        $this->assertSame(['archived', 2], [$retirado->fresh()->status, (int) $retirado->fresh()->revision]);
        $this->assertSame(1, StudioLabEvent::query()->where('event', 'scenario.catalog_synced')->count());
        $this->assertSame(1, StudioLabEvent::query()->where('event', 'scenario.catalog_retired')->count());
        $sistema = StudioLabEvent::query()->where('event', 'system.lab_catalog_imported')->orderByDesc('id')->first();
        $this->assertSame(['system', null, 'ultron-studio:lab-import-scenarios'], [$sistema->actor_type, $sistema->actor_admin_id, $sistema->actor_name]);
        $this->assertSame(['C-07'], $sistema->diff['updated']);
        $this->assertSame(['C-99'], $sistema->diff['retired']);

        // Un escenario del personal que ocupa un código del catálogo: no se toca nada.
        StudioLabScenario::query()->where('code', 'C-03')->update(['source' => 'studio', 'title' => 'Del personal']);
        $antes = StudioLabEvent::query()->count();
        $this->assertSame(1, Artisan::call('ultron-studio:lab-import-scenarios'));
        $this->assertSame($antes, StudioLabEvent::query()->count());
        $this->assertSame('Del personal', StudioLabScenario::query()->where('code', 'C-03')->value('title'));
    }

    public function test_la_importacion_funciona_con_la_bandera_apagada(): void
    {
        $this->labOff();
        StudioLabScenario::query()->where('code', 'C-01')->update(['title' => 'Cambiado']);
        $this->assertSame(0, Artisan::call('ultron-studio:lab-import-scenarios'));
        $this->assertSame(StudioLabCatalog::byCode('C-01')['title'], StudioLabScenario::query()->where('code', 'C-01')->value('title'));
    }
}
