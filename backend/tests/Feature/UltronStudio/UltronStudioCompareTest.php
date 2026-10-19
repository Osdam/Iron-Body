<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioDraft;
use App\Services\UltronStudio\StudioCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET compare?from&to` (contrato G §A4 y §A6): las combinaciones de la línea
 * base, el borrador y una versión; `draft` es SIEMPRE el borrador de quien pide
 * (el de otra persona no se filtra, ni por su uuid); 404 y 422; y no se audita.
 * La lógica del diff la prueba `StudioContentDiffTest`; aquí, que la ruta arma
 * bien cada lado.
 */
class UltronStudioCompareTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $ana;

    private Admin $bea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importBaseline();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $this->bea = $this->person(Admin::ROLE_SUPER_ADMIN, 'Bea Ruiz');
    }

    private function compare(Admin $as, string $from, string $to): TestResponse
    {
        return $this->studio('GET', "compare?from={$from}&to={$to}", $as);
    }

    public function test_la_linea_base_contra_un_borrador_sin_cambios_es_igual(): void
    {
        $r = $this->compare($this->ana, 'baseline', 'draft')->assertOk();

        $this->assertSame(['ok' => true, 'data' => [
            'from' => ['kind' => 'baseline', 'uuid' => null, 'label' => 'Línea base del código', 'catalogVersion' => StudioCatalog::VERSION, 'format' => 2],
            'to' => ['kind' => 'draft', 'uuid' => null, 'label' => 'Mi borrador', 'catalogVersion' => StudioCatalog::VERSION, 'format' => 2],
            'same' => true,
            'edges' => ['added' => [], 'removed' => []],
            'configs' => [],
            'rules' => ['added' => [], 'removed' => [], 'changed' => []],
            'warnings' => ['added' => [], 'removed' => []],
            'catalogChanged' => false,
        ]], $r->json());
    }

    public function test_el_borrador_con_cambios_y_reglas_del_studio(): void
    {
        $this->newStudioRule($this->ana, ['description' => 'Mensajes cortos.', 'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)])])->assertCreated();
        $this->saveDraft($this->ana, $this->contentWith(['persona' => ['maxChars' => 400]], [...$this->baseline()['definition']['edgeIds'], 'E14']))->assertOk();

        $data = $this->compare($this->ana, 'baseline', 'draft')->assertOk()->json('data');

        $this->assertFalse($data['same']);
        $this->assertSame(['added' => [['id' => 'E14', 'label' => 'enlace de pago como mensaje aparte']], 'removed' => []], $data['edges']);
        $this->assertSame([['layer' => 'persona', 'key' => 'maxChars', 'label' => 'Longitud máxima', 'from' => 320, 'to' => 400]], $data['configs']);
        $this->assertSame(['added' => [['code' => 'S-001', 'description' => 'Mensajes cortos.']], 'removed' => [], 'changed' => []], $data['rules']);
        // Con E14 se va su aviso de opcional y llegan el de la bandera apagada y los de la regla.
        $this->assertContains('opcional-E14', $data['warnings']['removed']);
        $this->assertContains('regla-S-001', $data['warnings']['added']);
        $this->assertContains('reglas-sin-aplicacion', $data['warnings']['added']);

        // Al revés, los mismos cambios en sentido contrario.
        $reves = $this->compare($this->ana, 'draft', 'baseline')->assertOk()->json('data');
        $this->assertSame([['layer' => 'persona', 'key' => 'maxChars', 'label' => 'Longitud máxima', 'from' => 400, 'to' => 320]], $reves['configs']);
        $this->assertSame([['code' => 'S-001', 'description' => 'Mensajes cortos.']], $reves['rules']['removed']);
        $this->assertSame($data['warnings']['added'], $reves['warnings']['removed']);
    }

    public function test_draft_es_siempre_el_de_quien_pide(): void
    {
        $this->saveDraft($this->ana, $this->contentWith(['persona' => ['maxChars' => 400]]))->assertOk();
        $borradorDeAna = StudioDraft::query()->where('admin_id', $this->ana->id)->sole()->uuid;

        // Bea no tiene borrador: su `draft` es la línea base, y el de Ana no aparece.
        $deBea = $this->compare($this->bea, 'baseline', 'draft')->assertOk();
        $deBea->assertJsonPath('data.same', true)->assertJsonPath('data.configs', []);
        $this->assertStringNotContainsString('400', $deBea->getContent());

        // Ni con su uuid: un uuid solo se busca entre las versiones.
        foreach (["{$borradorDeAna}&to=baseline", "baseline&to={$borradorDeAna}"] as $query) {
            $this->studio('GET', "compare?from={$query}", $this->bea)->assertStatus(404)->assertJsonPath('code', 'version_not_found');
        }
        // Y Ana ve el suyo.
        $this->compare($this->ana, 'baseline', 'draft')->assertOk()->assertJsonPath('data.configs.0.to', 400);
    }

    public function test_versiones_entre_si_y_contra_el_borrador(): void
    {
        $v1 = (string) $this->createVersion($this->ana, 'Primera', null, $this->contentWith(['persona' => ['maxChars' => 400]]))->assertCreated()->json('data.uuid');
        $v2 = (string) $this->createVersion($this->ana, 'Segunda', null, $this->contentWith(['persona' => ['maxChars' => 360], 'strategy' => ['goal' => 'venta']]))->assertCreated()->json('data.uuid');

        $r = $this->compare($this->bea, $v1, $v2)->assertOk();
        $r->assertJsonPath('data.from', ['kind' => 'version', 'uuid' => $v1, 'label' => 'Primera', 'catalogVersion' => StudioCatalog::VERSION, 'format' => 2])
            ->assertJsonPath('data.to.label', 'Segunda')
            ->assertJsonPath('data.same', false)
            ->assertJsonPath('data.catalogChanged', false)
            ->assertJsonPath('data.rules', ['added' => [], 'removed' => [], 'changed' => []]);
        $this->assertSame([
            ['layer' => 'strategy', 'key' => 'goal', 'label' => 'Objetivo comercial', 'from' => 'cita', 'to' => 'venta'],
        ], array_values(array_filter($r->json('data.configs'), static fn (array $c): bool => $c['layer'] === 'strategy')));
        $this->assertSame(['layer' => 'persona', 'key' => 'maxChars', 'label' => 'Longitud máxima', 'from' => 400, 'to' => 360], $r->json('data.configs.0'));

        // La segunda versión contra el borrador de Ana (que es el de la segunda): igual.
        $this->compare($this->ana, $v2, 'draft')->assertOk()->assertJsonPath('data.same', true);
        // El uuid vale en mayúsculas.
        $this->compare($this->ana, strtoupper($v2), 'draft')->assertOk()->assertJsonPath('data.from.uuid', $v2);
    }

    public function test_una_version_de_f_contra_el_borrador(): void
    {
        $f = $this->seedFormat1Version($this->ana);

        $data = $this->compare($this->ana, $f->uuid, 'draft')->assertOk()->json('data');

        $this->assertSame(['kind' => 'version', 'uuid' => $f->uuid, 'label' => $f->label, 'catalogVersion' => self::CATALOG_F, 'format' => 1], $data['from']);
        $this->assertTrue($data['catalogChanged']);
        // Las reglas del código ganaron condiciones y aristas en G.
        $cambiadas = collect($data['rules']['changed'])->keyBy('code');
        $this->assertSame(['refs', 'conditions'], $cambiadas['R-01']['fields']);
        $this->assertSame(['conditions'], $cambiadas['R-02']['fields']);
        $this->assertSame(['refs'], $cambiadas['R-04']['fields']);
        $this->assertSame([1, 1], [$cambiadas['R-01']['fromRevision'], $cambiadas['R-01']['toRevision']]);
        $this->assertArrayNotHasKey('R-03', $cambiadas->all());
        // Mismo contenido: ni aristas ni configuración.
        $this->assertSame([], $data['configs']);
        $this->assertSame(['added' => [], 'removed' => []], $data['edges']);
        $this->assertFalse($data['same']);
    }

    public function test_la_revision_de_la_linea_base_es_la_que_dejaria_la_importacion(): void
    {
        // Una fila del código alterada a mano: la línea base (el código) la tendría
        // en la revisión siguiente, con su descripción.
        DB::table('ultron_studio_rules')->where('code', 'R-03')->update(['description' => 'Alterada a mano']);

        $data = $this->compare($this->ana, 'baseline', 'draft')->assertOk()->json('data');

        $this->assertSame([['code' => 'R-03', 'fromRevision' => 2, 'toRevision' => 1, 'fields' => ['description']]], $data['rules']['changed']);
        $this->assertFalse($data['same']);
    }

    public function test_lados_que_no_valen_dan_422_y_una_version_que_no_existe_404(): void
    {
        foreach ([
            'from=baseline' => ['to'],
            'to=draft' => ['from'],
            'from=baseline&to=baseline' => ['to'],
            'from=draft&to=draft' => ['to'],
            'from=BASELINE&to=draft' => ['from'],
            'from=otra&to=draft' => ['from'],
            'from=draft&to=12345' => ['to'],
            'from[]=draft&to=baseline' => ['from'],
        ] as $query => $claves) {
            $this->studio('GET', "compare?{$query}", $this->ana)->assertStatus(422)->assertJsonValidationErrors($claves);
        }

        $uuid = (string) $this->createVersion($this->ana)->assertCreated()->json('data.uuid');
        $this->compare($this->ana, $uuid, strtoupper($uuid))->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->compare($this->ana, (string) Str::uuid(), 'draft')->assertStatus(404)->assertJsonPath('code', 'version_not_found');
    }

    public function test_comparar_no_escribe_nada_ni_se_audita(): void
    {
        $uuid = (string) $this->createVersion($this->ana)->assertCreated()->json('data.uuid');
        $eventos = StudioAuditEvent::query()->count();
        $borradores = StudioDraft::query()->get()->map->getAttributes()->all();

        $this->compare($this->ana, $uuid, 'baseline')->assertOk();
        $this->compare($this->bea, 'draft', $uuid)->assertOk();
        $this->compare($this->bea, 'baseline', 'baseline')->assertStatus(422);

        $this->assertSame($eventos, StudioAuditEvent::query()->count());
        $this->assertSame($borradores, StudioDraft::query()->get()->map->getAttributes()->all());
        $this->assertSame(0, StudioDraft::query()->where('admin_id', $this->bea->id)->count(), 'comparar no crea el borrador de nadie');
    }
}
