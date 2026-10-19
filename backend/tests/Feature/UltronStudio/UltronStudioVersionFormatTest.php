<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioRule;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El formato del contenido de las versiones (contrato G §A3 y §A7).
 *
 *  - Una versión de formato 1, sembrada con la forma exacta de F, conserva su
 *    huella, se valida SIN hallazgos de reglas (aunque haya reglas del Studio
 *    vivas que no cumpliría) y se envía y aprueba como en F: su conjunto de
 *    avisos no cambia bajo los pies de quien aprueba.
 *  - Una de formato 2 copia las reglas con `conditions`, añade al final los
 *    hallazgos de SUS reglas copiadas (nunca las vivas) y exige reconocer
 *    `regla-*` y `reglas-sin-aplicacion` al aprobar.
 *  - La huella se elige por el formato: ni una de F se firma como de G ni al
 *    revés, y un formato desconocido no se interpreta (409, nunca 500).
 */
class UltronStudioVersionFormatTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $ana;

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importBaseline();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
    }

    /** S-001: «Longitud máxima ≤ 300». La línea base (320) no la cumple. */
    private function maxCharsRule(): array
    {
        return $this->newStudioRule($this->ana, [
            'description' => 'Mensajes cortos.',
            'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]),
        ])->assertCreated()->json('data');
    }

    public function test_una_version_de_f_tiene_integridad_y_se_valida_y_aprueba_como_en_f(): void
    {
        $f = $this->seedFormat1Version($this->ana);
        $this->assertSame(self::F_BASELINE_VERSION_HASH, $f->content_hash, 'la fila es la que escribía F');
        // Una regla del Studio viva que la línea base no cumple: en F no existía.
        $this->maxCharsRule();

        $detalle = $this->studio('GET', "versions/{$f->uuid}", $this->root)->assertOk();
        $detalle->assertJsonPath('data.integrityOk', true)
            ->assertJsonPath('data.content.format', 1)
            ->assertJsonPath('data.catalogVersion', self::CATALOG_F);
        foreach ($detalle->json('data.content.rules') as $regla) {
            $this->assertArrayNotHasKey('conditions', $regla, $regla['code']);
        }

        $f1 = ['opcional-E14', 'opcional-E16'];
        $validacion = $this->studio('POST', "versions/{$f->uuid}/validate", $this->root)->assertOk();
        $validacion->assertJsonPath('data.warnings', $f1)->assertJsonPath('data.wouldBecome', 'REVIEW_REQUIRED');
        foreach ($validacion->json('data.findings') as $hallazgo) {
            $this->assertStringStartsNotWith('regla', $hallazgo['id']);
        }

        $this->studio('POST', "versions/{$f->uuid}/submit", $this->ana)->assertOk()
            ->assertJsonPath('data.status', 'REVIEW_REQUIRED')
            ->assertJsonPath('data.warnings', $f1);
        $this->studio('POST', "versions/{$f->uuid}/approve", $this->root, ['acknowledgedWarnings' => $f1, 'note' => 'Versión de F'])->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.review.acknowledgedWarnings', $f1)
            ->assertJsonPath('data.integrityOk', true);
        // Aprobar no tocó el contenido ni su huella.
        $this->assertSame(self::F_BASELINE_VERSION_HASH, $f->fresh()->content_hash);
    }

    public function test_una_version_de_formato_2_copia_las_condiciones_y_trae_los_hallazgos_de_sus_reglas(): void
    {
        $s001 = $this->maxCharsRule();

        $r = $this->createVersion($this->ana, 'Con reglas del Studio')->assertCreated();

        $r->assertJsonPath('data.content.format', 2)
            ->assertJsonPath('data.integrityOk', true)
            ->assertJsonPath('data.warnings', ['opcional-E14', 'opcional-E16', 'regla-S-001', 'reglas-sin-aplicacion']);
        $reglas = collect($r->json('data.content.rules'))->keyBy('code');
        $this->assertCount(13, $reglas);
        foreach ($reglas as $codigo => $regla) {
            $this->assertSame(StudioContentHasher::VERSION_RULE_FIELDS_FORMAT_2, array_keys($regla), $codigo);
        }
        $this->assertSame($s001['conditions'], $reglas['S-001']['conditions']);
        $this->assertSame(StudioCatalog::legacyConditions()['R-01'], $reglas['R-01']['conditions']);
        $this->assertNull($reglas['R-03']['conditions']);

        $v = StudioVersion::query()->sole();
        $this->assertSame(2, $v->content['format']);
        $this->assertSame(StudioContentHasher::versionHash(StudioCatalog::VERSION, $v->content, $v->content['rules']), $v->content_hash);
        // Los hallazgos de reglas van AL FINAL de los de F, en el orden del contrato.
        $ultimos = array_slice($v->last_validation['findings'], -2);
        $this->assertSame([
            ['id' => 'regla-S-001', 'level' => 'warning', 'title' => 'S-001 no se cumple', 'detail' => 'Mensajes cortos. (Longitud máxima: 320)', 'layer' => 'persona'],
            ['id' => 'reglas-sin-aplicacion', 'level' => 'warning', 'title' => 'Reglas que el motor no aplica', 'detail' => 'S-001: son propuestas del Studio; aprobar no las hace cumplir.'],
        ], $ultimos);

        // Hay que reconocerlos TODOS al aprobar.
        $uuid = $v->uuid;
        $this->studio('POST', "versions/{$uuid}/submit", $this->ana)->assertOk()->assertJsonPath('data.status', 'REVIEW_REQUIRED');
        $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])
            ->assertStatus(409)
            ->assertJsonPath('code', 'warnings_not_acknowledged')
            ->assertJsonPath('warnings', ['opcional-E14', 'opcional-E16', 'regla-S-001', 'reglas-sin-aplicacion']);
        $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['acknowledgedWarnings' => ['reglas-sin-aplicacion', 'regla-S-001', 'opcional-E16', 'opcional-E14']])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_una_version_se_valida_con_sus_reglas_copiadas_nunca_con_las_vivas(): void
    {
        $s001 = $this->maxCharsRule();
        $uuid = (string) $this->createVersion($this->ana)->assertCreated()->json('data.uuid');

        // Después de crearla: S-001 se archiva y nace S-002, que tampoco cumple.
        $this->studio('POST', "rules/{$s001['uuid']}/archive", $this->ana, ['baseRevision' => 1, 'reason' => 'Ya no se quiere'])->assertOk();
        $this->newStudioRule($this->ana, ['conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 200)])])->assertCreated();

        $this->studio('POST', "versions/{$uuid}/validate", $this->root)->assertOk()
            ->assertJsonPath('data.warnings', ['opcional-E14', 'opcional-E16', 'regla-S-001', 'reglas-sin-aplicacion']);
        // El borrador, en cambio, se evalúa con las vivas.
        $this->studio('GET', 'draft', $this->ana)->assertOk()
            ->assertJsonPath('data.validation.warnings', ['opcional-E14', 'opcional-E16', 'regla-S-002', 'reglas-sin-aplicacion']);
    }

    public function test_con_reglas_que_se_contradicen_no_se_crea_la_version(): void
    {
        // Solo pasa si alguien escribió en la tabla o cambió el catálogo: la API lo impide.
        foreach (['S-001' => 'lte', 'S-002' => 'gte'] as $codigo => $op) {
            $campos = ['description' => "Sembrada {$codigo}", 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'],
                'refs' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []], 'origin' => null,
                'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', $op, $op === 'lte' ? 300 : 320)])];
            StudioRule::query()->forceCreate($campos + ['code' => $codigo, 'source' => 'studio', 'enforcement' => 'none', 'status' => 'draft',
                'revision' => 1, 'content_hash' => StudioContentHasher::ruleHash($campos), 'client_request_id' => (string) Str::uuid()]);
        }

        $this->saveDraft($this->ana)->assertOk()->assertJsonPath('data.validation.publishable', false);
        $r = $this->studio('POST', 'versions', $this->ana, ['label' => 'Con contradicción', 'note' => null, 'draftRevision' => 1, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_blocked');

        $bloqueos = collect($r->json('findings'))->where('level', 'blocking');
        $this->assertSame(['reglas-contradictorias'], $bloqueos->pluck('id')->values()->all());
        $this->assertSame('S-001 ↔ S-002 (Longitud máxima)', $bloqueos->first()['detail']);
        $this->assertSame(0, StudioVersion::query()->count());
    }

    public function test_la_huella_se_elige_por_el_formato(): void
    {
        $this->maxCharsRule();
        $base = $this->baseline();

        // Formato 2 firmado como si fuera de F (sin `format` y sin condiciones): no es íntegra.
        $deG = ['catalogVersion' => StudioCatalog::VERSION, 'format' => 2] + $base + ['rules' => StudioRules::snapshotForVersion(2)];
        $sinFormato = $deG;
        unset($sinFormato['format']);
        $firmadaComoF = StudioContentHasher::versionHash(StudioCatalog::VERSION, $sinFormato, StudioRules::snapshotForVersion(1));
        $mal = $this->seedVersion($this->ana, $base, 'DRAFT', $firmadaComoF);
        $this->studio('GET', "versions/{$mal->uuid}", $this->root)->assertOk()->assertJsonPath('data.integrityOk', false);
        $this->studio('POST', "versions/{$mal->uuid}/submit", $this->ana)->assertStatus(409)->assertJsonPath('code', 'integrity_mismatch');

        // Formato 1 firmado como de G: tampoco.
        $firmadaComoG = StudioContentHasher::versionHash(StudioCatalog::VERSION, $deG, StudioRules::snapshotForVersion(2));
        $f = $this->seedVersion($this->ana, $base, 'DRAFT', $firmadaComoG, StudioContentHasher::FORMAT_1);
        $this->studio('GET', "versions/{$f->uuid}", $this->root)->assertOk()->assertJsonPath('data.integrityOk', false);

        // Bien firmadas, las dos son íntegras.
        foreach ([1, 2] as $formato) {
            $bien = $this->seedVersion($this->ana, $base, 'DRAFT', null, $formato);
            $this->studio('GET', "versions/{$bien->uuid}", $this->root)->assertOk()
                ->assertJsonPath('data.integrityOk', true)
                ->assertJsonPath('data.content.format', $formato);
        }
    }

    public function test_un_formato_desconocido_no_se_interpreta_y_no_da_500(): void
    {
        $contenido = ['catalogVersion' => StudioCatalog::VERSION, 'format' => 3] + $this->baseline() + ['rules' => StudioRules::snapshotForVersion(2)];
        $v = StudioVersion::query()->forceCreate([
            'client_request_id' => (string) Str::uuid(), 'label' => 'Formato 3', 'status' => 'REVIEW_REQUIRED', 'content' => $contenido,
            'content_hash' => str_repeat('c', 64), 'catalog_version' => StudioCatalog::VERSION, 'catalog_hash' => StudioCatalog::hash(),
            'source_draft_revision' => 1, 'created_by_admin_id' => $this->ana->id, 'created_by_name' => $this->ana->name,
            'created_by_role' => $this->ana->role, 'last_validation' => null, 'submitted_at' => now(), 'status_changed_at' => now(),
        ]);

        $this->studio('GET', "versions/{$v->uuid}", $this->root)->assertOk()
            ->assertJsonPath('data.integrityOk', false)
            ->assertJsonPath('data.content.format', 3);
        foreach ([
            ['POST', "versions/{$v->uuid}/validate", []],
            ['POST', "versions/{$v->uuid}/approve", ['acknowledgedWarnings' => []]],
            ['POST', "versions/{$v->uuid}/reject", ['reason' => 'No se entiende']],
            ['GET', "compare?from={$v->uuid}&to=baseline", []],
        ] as [$metodo, $ruta, $cuerpo]) {
            $this->studio($metodo, $ruta, $this->root, $cuerpo)->assertStatus(409)->assertJsonPath('code', 'integrity_mismatch');
        }
        $this->assertSame('REVIEW_REQUIRED', $v->fresh()->status);
    }
}
