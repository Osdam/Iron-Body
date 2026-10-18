<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\MarketingKnowledgeItem;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioRule;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Las reglas del Studio (contrato §A3 rutas 15–18 y §A8): las del código son
 * de solo lectura; las del Studio nacen S-NNN en borrador y sin que el motor
 * las aplique; las referencias se validan contra el catálogo y la base de
 * conocimiento; la concurrencia va por revisión; archivar no borra.
 */
class UltronStudioRulesTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importBaseline();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
    }

    /** @param  array<string, mixed>  $extra */
    private function newRule(array $extra = [], ?Admin $as = null): TestResponse
    {
        return $this->studio('POST', 'rules', $as ?? $this->ana, $extra + [
            'description' => 'Proponer la valoración antes que el precio.',
            'type' => 'condition',
            'priority' => 'normal',
            'layers' => ['strategy', 'composer'],
            'clientRequestId' => (string) Str::uuid(),
        ]);
    }

    public function test_la_lista_trae_las_doce_del_codigo_como_solo_lectura(): void
    {
        $r = $this->studio('GET', 'rules', $this->ana)->assertOk();

        $reglas = $r->json('data');
        $this->assertSame(array_column(StudioCatalog::legacyRules(), 'code'), array_column($reglas, 'code'));
        foreach ($reglas as $regla) {
            $this->assertSame(['legacy_baseline', 'code', 'active', 1], [$regla['source'], $regla['enforcement'], $regla['status'], $regla['revision']]);
            $this->assertNull($regla['createdBy']);
            $this->assertNotEmpty($regla['origin']);
        }
        $r02 = collect($reglas)->firstWhere('code', 'R-02');
        $this->assertSame(['configs' => [['layer' => 'strategy', 'key' => 'discountsAllowed']], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []], $r02['refs']);
    }

    public function test_las_del_codigo_no_se_editan_ni_se_archivan_desde_la_api(): void
    {
        $r01 = StudioRule::query()->where('code', 'R-01')->sole();

        $this->studio('PATCH', "rules/{$r01->uuid}", $this->ana, ['baseRevision' => 1, 'description' => 'Reescrita'])
            ->assertStatus(409)->assertJsonPath('code', 'legacy_rule_read_only');
        $this->studio('POST', "rules/{$r01->uuid}/archive", $this->ana, ['baseRevision' => 1, 'reason' => 'Intento de archivarla'])
            ->assertStatus(409)->assertJsonPath('code', 'legacy_rule_read_only');

        $this->assertSame(1, $r01->fresh()->revision);
        $this->assertSame('active', $r01->fresh()->status);
    }

    public function test_una_regla_del_studio_nace_s001_en_borrador_y_sin_que_el_motor_la_aplique(): void
    {
        $r = $this->newRule()->assertCreated();

        $r->assertJsonPath('data.code', 'S-001')
            ->assertJsonPath('data.source', 'studio')
            ->assertJsonPath('data.enforcement', 'none')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.origin', null)
            ->assertJsonPath('data.createdBy', ['name' => 'Ana Pérez', 'role' => null])
            ->assertJsonPath('data.refs', ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []]);

        $fila = StudioRule::query()->where('code', 'S-001')->sole();
        $this->assertSame(StudioContentHasher::ruleHash($fila->only(StudioContentHasher::RULE_FIELDS)), $fila->content_hash);
        $evento = StudioAuditEvent::query()->where('event', 'rule.created')->sole();
        $this->assertSame($fila->uuid, $evento->subject_uuid);
        $this->assertSame('S-001', $evento->diff['code']);

        $this->newRule()->assertCreated()->assertJsonPath('data.code', 'S-002');
    }

    public function test_crear_es_idempotente_por_client_request_id(): void
    {
        $id = (string) Str::uuid();
        $primera = $this->newRule(['clientRequestId' => $id])->assertCreated();

        $this->newRule(['clientRequestId' => $id])
            ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.uuid', $primera->json('data.uuid'));
        $this->newRule(['clientRequestId' => $id, 'description' => 'Otra regla con la misma llave'])
            ->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->newRule(['clientRequestId' => $id], $this->person(Admin::ROLE_SUPER_ADMIN, 'Otra persona'))
            ->assertStatus(409)->assertJsonPath('code', 'client_request_reused');

        $this->assertSame(1, StudioRule::query()->where('source', 'studio')->count());
    }

    public function test_dos_reglas_nuevas_a_la_vez_no_pisan_su_codigo(): void
    {
        // Otra petición inserta S-001 justo antes que esta: el UNIQUE de `code`
        // para a esta, que reintenta en vez de devolver un 500.
        //
        // En una carrera real la fila de la otra petición está confirmada en
        // OTRA conexión y esta acaba en S-002. Aquí las dos van por la misma
        // conexión y la fila simulada se deshace con el savepoint del intento
        // fallido, así que lo que se mide es el choque y el reintento: tres
        // INSERT (el de esta, el de la otra y el reintento) y un 201.
        $inserts = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$inserts): void {
            if (str_contains($query, 'insert into "ultron_studio_rules"')) {
                $inserts++;
            }
        });
        $this->beforeStatement('insert into "ultron_studio_rules"', function (): void {
            StudioRule::query()->forceCreate([
                'code' => 'S-001', 'source' => 'studio', 'enforcement' => 'none', 'status' => 'draft',
                'description' => 'La de la otra pestaña.', 'type' => 'style', 'priority' => 'normal',
                'layers' => ['persona'], 'refs' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []],
                'revision' => 1, 'content_hash' => str_repeat('a', 64), 'client_request_id' => (string) Str::uuid(),
            ]);
        });

        $r = $this->newRule()->assertCreated();

        $this->assertSame(3, $inserts);
        $this->assertMatchesRegularExpression('/^S-\d{3}$/', (string) $r->json('data.code'));
        $this->assertSame(1, StudioRule::query()->where('source', 'studio')->count());
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'rule.created')->count());
    }

    public function test_referencias_que_el_catalogo_no_conoce_son_422(): void
    {
        foreach ([
            'refs.configs.0.layer' => ['configs' => [['layer' => 'inventada', 'key' => 'tone']]],
            'refs.configs.0.key' => ['configs' => [['layer' => 'persona', 'key' => 'noExiste']]],
            'refs.edges.0' => ['edges' => ['E99']],
            'refs.knowledgeCategories.0' => ['knowledgeCategories' => ['secretos']],
            'refs' => ['otraCosa' => []],
        ] as $clave => $refs) {
            $this->newRule(['refs' => $refs])->assertStatus(422)->assertJsonValidationErrors([$clave]);
        }
        $this->newRule(['refs' => ['configs' => [['layer' => 'persona', 'key' => 'tone'], ['layer' => 'persona', 'key' => 'tone']]]])
            ->assertStatus(422)->assertJsonValidationErrors(['refs.configs.1']);
        $this->newRule(['layers' => []])->assertStatus(422)->assertJsonValidationErrors(['layers']);
        $this->newRule(['type' => 'mandato'])->assertStatus(422)->assertJsonValidationErrors(['type']);

        $this->assertSame(0, StudioRule::query()->where('source', 'studio')->count());
    }

    public function test_las_claves_de_conocimiento_tienen_que_existir_y_se_ve_como_estan(): void
    {
        $this->newRule(['refs' => ['knowledgeKeys' => ['brand_copy.no_existe']]])
            ->assertStatus(422)->assertJsonValidationErrors(['refs.knowledgeKeys.0']);

        MarketingKnowledgeItem::create([
            'category' => 'brand_copy', 'key' => 'brand_copy.experiencia', 'title' => 'Experiencia',
            'content' => 'Más de diez años entrenando en Neiva.', 'is_active' => true,
            'origin' => MarketingKnowledgeItem::ORIGIN_SERVER,
        ]);

        $r = $this->newRule(['refs' => ['knowledgeKeys' => ['brand_copy.experiencia'], 'knowledgeCategories' => ['brand_copy']]])->assertCreated();
        $r->assertJsonPath('data.knowledgeRefs', [['key' => 'brand_copy.experiencia', 'exists' => true, 'reviewStatus' => 'approved', 'isActive' => true]]);
        // El contenido de la base de conocimiento no viaja: solo su estado.
        $this->assertStringNotContainsString('diez años', $r->getContent());
    }

    public function test_editar_con_revision_y_conflicto(): void
    {
        $uuid = (string) $this->newRule()->assertCreated()->json('data.uuid');

        $this->studio('PATCH', "rules/{$uuid}", $this->ana, ['baseRevision' => 1, 'priority' => 'high', 'refs' => ['edges' => ['E14']]])
            ->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.refs.edges', ['E14'])
            ->assertJsonPath('data.updatedBy', ['name' => 'Ana Pérez', 'role' => null]);

        $evento = StudioAuditEvent::query()->where('event', 'rule.updated')->sole();
        $this->assertSame(1, $evento->diff['fromRevision']);
        $this->assertSame(2, $evento->diff['toRevision']);
        $this->assertSame(['priority', 'refs'], array_column($evento->diff['changes'], 'field'));
        $this->assertSame(['normal', 'high'], [$evento->diff['changes'][0]['from'], $evento->diff['changes'][0]['to']]);

        $fila = StudioRule::query()->where('uuid', $uuid)->sole();
        $this->assertSame(StudioContentHasher::ruleHash($fila->only(StudioContentHasher::RULE_FIELDS)), $fila->content_hash);

        $this->studio('PATCH', "rules/{$uuid}", $this->ana, ['baseRevision' => 1, 'priority' => 'critical'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'rule_conflict')
            ->assertJsonPath('current.revision', 2)
            ->assertJsonPath('current.priority', 'high');

        // Sin cambios reales: ni escritura, ni revisión nueva, ni traza.
        $this->studio('PATCH', "rules/{$uuid}", $this->ana, ['baseRevision' => 2, 'priority' => 'high'])
            ->assertOk()->assertJsonPath('data.revision', 2);
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'rule.updated')->count());
    }

    public function test_otra_edicion_se_adelanta_entre_la_lectura_y_el_update(): void
    {
        $uuid = (string) $this->newRule()->assertCreated()->json('data.uuid');
        $this->beforeStatement('update "ultron_studio_rules"', function () use ($uuid): void {
            DB::table('ultron_studio_rules')->where('uuid', $uuid)->update(['revision' => 2, 'priority' => 'critical']);
        });

        $this->studio('PATCH', "rules/{$uuid}", $this->ana, ['baseRevision' => 1, 'priority' => 'high'])
            ->assertStatus(409)->assertJsonPath('code', 'rule_conflict')->assertJsonPath('current.revision', 2);
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'rule.updated')->count());
    }

    public function test_archivar_no_borra_y_saca_la_regla_de_la_lista(): void
    {
        $uuid = (string) $this->newRule()->assertCreated()->json('data.uuid');

        $this->studio('POST', "rules/{$uuid}/archive", $this->ana, ['baseRevision' => 1, 'reason' => 'no'])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $r = $this->studio('POST', "rules/{$uuid}/archive", $this->ana, ['baseRevision' => 1, 'reason' => 'La cubre R-08'])->assertOk();
        $r->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.archiveReason', 'La cubre R-08');
        $this->assertNotNull($r->json('data.archivedAt'));

        $evento = StudioAuditEvent::query()->where('event', 'rule.archived')->sole();
        $this->assertSame(['draft', 'archived'], [$evento->from_status, $evento->to_status]);
        $this->assertSame('La cubre R-08', $evento->diff['reason']);

        $this->studio('PATCH', "rules/{$uuid}", $this->ana, ['baseRevision' => 2, 'priority' => 'high'])
            ->assertStatus(409)->assertJsonPath('code', 'rule_archived');
        $this->studio('POST', "rules/{$uuid}/archive", $this->ana, ['baseRevision' => 2, 'reason' => 'Otra vez'])
            ->assertStatus(409)->assertJsonPath('code', 'rule_archived');

        $this->assertNotContains($uuid, array_column($this->studio('GET', 'rules', $this->ana)->json('data'), 'uuid'));
        $this->assertSame([$uuid], array_column($this->studio('GET', 'rules?status=archived', $this->ana)->assertOk()->json('data'), 'uuid'));
        $this->studio('GET', 'rules?status=borrada', $this->ana)->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->assertSame(1, StudioRule::query()->where('uuid', $uuid)->count());
    }

    public function test_una_regla_que_no_existe_es_404(): void
    {
        $nadie = (string) Str::uuid();
        $this->studio('PATCH', "rules/{$nadie}", $this->ana, ['baseRevision' => 1, 'priority' => 'high'])
            ->assertStatus(404)->assertJsonPath('code', 'rule_not_found');
        $this->studio('POST', "rules/{$nadie}/archive", $this->ana, ['baseRevision' => 1, 'reason' => 'No existe'])
            ->assertStatus(404)->assertJsonPath('code', 'rule_not_found');
    }
}
