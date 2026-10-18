<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioDraft;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El borrador de cada persona (contrato §A1, §A3 rutas 3–5 y §A8): la línea
 * base sin guardar, el primer guardado en un SAVEPOINT, la concurrencia
 * optimista por revisión, la normalización del servidor, el descarte que no
 * borra y la auditoría con su diff.
 */
class UltronStudioDraftTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
    }

    public function test_sin_borrador_devuelve_la_linea_base_con_revision_cero(): void
    {
        $r = $this->studio('GET', 'draft', $this->ana)->assertOk();

        $r->assertJsonPath('ok', true)
            ->assertJsonPath('data.exists', false)
            ->assertJsonPath('data.isBaseline', true)
            ->assertJsonPath('data.revision', 0)
            ->assertJsonPath('data.savedAt', null)
            ->assertJsonPath('data.catalogVersion', StudioCatalog::VERSION)
            ->assertJsonPath('data.contentHash', StudioContentHasher::draftHash(StudioCatalog::VERSION, $this->baseline()))
            ->assertJsonPath('data.validation.publishable', true)
            ->assertJsonPath('data.validation.warnings', ['opcional-E14', 'opcional-E16']);

        $this->assertSame($this->baseline(), $r->json('data.content'));
        $this->assertSame(StudioCatalog::baseline()['edgeIds'], $r->json('data.content.definition.edgeIds'));
        // Leer no escribe nada.
        $this->assertSame(0, StudioDraft::query()->count());
        $this->assertSame(0, StudioAuditEvent::query()->count());
    }

    public function test_el_primer_guardado_crea_la_revision_uno_y_lo_audita(): void
    {
        $antes = StudioCatalog::baseline()['configs']['persona']['maxChars'];
        $nuevo = $antes === 400 ? 420 : 400;

        $r = $this->saveDraft($this->ana, $this->contentWith(['persona' => ['maxChars' => $nuevo]]))->assertOk();

        $r->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.isBaseline', false)
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.content.configs.persona.maxChars', $nuevo);
        $this->assertNotNull($r->json('data.savedAt'));

        $fila = StudioDraft::query()->where('admin_id', $this->ana->id)->sole();
        $this->assertSame(1, $fila->revision);
        $this->assertSame(StudioContentHasher::draftHash(StudioCatalog::VERSION, $fila->content), $fila->content_hash);
        $this->assertSame($fila->content_hash, $r->json('data.contentHash'));

        $evento = StudioAuditEvent::query()->where('event', 'draft.saved')->sole();
        $this->assertSame('draft', $evento->subject_type);
        $this->assertSame($fila->uuid, $evento->subject_uuid);
        $this->assertSame('admin', $evento->actor_type);
        $this->assertSame($this->ana->id, $evento->actor_admin_id);
        $this->assertSame('Administrador', $evento->actor_role);
        $this->assertSame(0, $evento->diff['fromRevision']);
        $this->assertSame(1, $evento->diff['toRevision']);
        $this->assertSame([['layer' => 'persona', 'key' => 'maxChars', 'from' => $antes, 'to' => $nuevo]], $evento->diff['configChanges']);
        $this->assertSame([], $evento->diff['edgesAdded']);
        $this->assertFalse($evento->diff['positionsChanged']);
    }

    public function test_una_revision_vieja_da_409_con_el_borrador_actual(): void
    {
        $this->saveDraft($this->ana, $this->contentWith(['persona' => ['emojis' => false]]))->assertOk();

        // Otra pestaña que también partía de «sin borrador»: choca con la
        // unicidad dentro del savepoint y recibe lo que hay.
        $this->saveDraft($this->ana, $this->contentWith(['persona' => ['tone' => 'profesional']]), 0)
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'draft_conflict')
            ->assertJsonPath('current.exists', true)
            ->assertJsonPath('current.revision', 1)
            ->assertJsonPath('current.content.configs.persona.emojis', false);

        $this->saveDraft($this->ana, $this->contentWith(['persona' => ['emojis' => true]]), 1)->assertOk()->assertJsonPath('data.revision', 2);
        $this->saveDraft($this->ana, $this->contentWith(['persona' => ['tone' => 'profesional']]), 1)
            ->assertStatus(409)
            ->assertJsonPath('code', 'draft_conflict')
            ->assertJsonPath('current.revision', 2);

        // Los conflictos no se auditan: no cambió nada.
        $this->assertSame(2, StudioAuditEvent::query()->where('event', 'draft.saved')->count());
        $this->assertSame(2, StudioDraft::query()->sole()->revision);
    }

    public function test_otra_pestana_guarda_entre_la_lectura_y_el_update(): void
    {
        $this->saveDraft($this->ana)->assertOk();

        // Justo antes del UPDATE condicionado, otra petición sube la revisión:
        // el UPDATE no encuentra `revision = 1` y la respuesta es 409.
        $this->beforeStatement('update "ultron_studio_drafts"', function (): void {
            DB::table('ultron_studio_drafts')->where('admin_id', $this->ana->id)->update(['revision' => 2]);
        });

        $this->saveDraft($this->ana, $this->contentWith(['persona' => ['emojis' => false]]), 1)
            ->assertStatus(409)
            ->assertJsonPath('code', 'draft_conflict')
            ->assertJsonPath('current.revision', 2);

        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'draft.saved')->count());
    }

    public function test_el_primer_guardado_concurrente_choca_en_el_savepoint_y_la_transaccion_sigue_viva(): void
    {
        // La otra pestaña ya insertó la fila (revisión 1) y esta todavía cree
        // que no hay borrador. No hay lectura previa: su INSERT choca con
        // UNIQUE(admin_id) dentro del savepoint, y la lectura de `current` que
        // viene después funciona (en PostgreSQL, sin el savepoint, sería 25P02
        // y un 500).
        StudioDraft::query()->forceCreate([
            'admin_id' => $this->ana->id,
            'revision' => 1,
            'catalog_version' => StudioCatalog::VERSION,
            'content' => $this->baseline(),
            'content_hash' => StudioContentHasher::draftHash(StudioCatalog::VERSION, $this->baseline()),
        ]);

        $this->saveDraft($this->ana, $this->contentWith(['persona' => ['emojis' => false]]), 0)
            ->assertStatus(409)
            ->assertJsonPath('code', 'draft_conflict')
            ->assertJsonPath('current.exists', true)
            ->assertJsonPath('current.revision', 1)
            ->assertJsonPath('current.isBaseline', true);

        $this->assertSame(1, StudioDraft::query()->count());
        $this->assertSame(0, StudioAuditEvent::query()->count());
    }

    public function test_el_servidor_normaliza_y_el_cliente_adopta_lo_que_devuelve(): void
    {
        $c = $this->baseline();
        $c['definition']['layers'] = ['knowledge'];                 // se ignora
        $c['definition']['edgeIds'] = array_reverse($c['definition']['edgeIds']);
        $c['definition']['extra'] = 'se descarta';
        $c['otra'] = ['clave' => 'se descarta'];
        unset($c['configs']['memory']);                             // se rellena
        $c['configs']['knowledge']['sources'] = ['  Planes vendibles ', '', '   ', 'Clases y horarios'];
        $c['layout']['viewport'] = ['x' => -120.5, 'y' => 40, 'scale' => 0.8];
        $c['layout']['positions'] = array_reverse($c['layout']['positions'], true);

        $r = $this->saveDraft($this->ana, $c)->assertOk();
        $guardado = $r->json('data.content');

        $this->assertSame(StudioCatalog::layerIds(), $guardado['definition']['layers']);
        $this->assertSame(StudioCatalog::baseline()['edgeIds'], $guardado['definition']['edgeIds']);
        $this->assertArrayNotHasKey('extra', $guardado['definition']);
        $this->assertArrayNotHasKey('otra', $guardado);
        $this->assertSame($this->baseline()['configs']['memory'], $guardado['configs']['memory']);
        // Recortadas y sin las vacías (los textos llegan crudos al normalizador).
        $this->assertSame(['Planes vendibles', 'Clases y horarios'], $guardado['configs']['knowledge']['sources']);
        $this->assertSame(['x' => -120.5, 'y' => 40, 'scale' => 0.8], $guardado['layout']['viewport']);
        // Las posiciones vuelven en el orden del catálogo.
        $this->assertSame(StudioCatalog::layerIds(), array_keys($guardado['layout']['positions']));
        $this->assertSame($guardado, StudioDraft::query()->sole()->content);
    }

    public function test_lo_que_el_catalogo_no_admite_es_422_con_claves_content(): void
    {
        $max = (int) collect(StudioCatalog::parameters()['persona'])->firstWhere('key', 'maxChars')['max'];

        $this->saveDraft($this->ana, $this->contentWith(['persona' => ['maxChars' => $max + 1]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content.configs.persona.maxChars']);

        $this->saveDraft($this->ana, $this->contentWith([], ['E01', 'E99']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content.definition.edgeIds.1']);

        $this->studio('PUT', 'draft', $this->ana, ['baseRevision' => '0', 'content' => $this->baseline()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['baseRevision']);

        $this->studio('PUT', 'draft', $this->ana, ['baseRevision' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);

        $this->assertSame(0, StudioDraft::query()->count());
        $this->assertSame(0, StudioAuditEvent::query()->count());
    }

    public function test_un_contenido_de_mas_de_64_kb_o_con_controles_es_422(): void
    {
        $grande = $this->baseline();
        $grande['relleno'] = str_repeat('x', 70 * 1024);
        $this->saveDraft($this->ana, $grande)->assertStatus(422)->assertJsonValidationErrors(['content']);

        $this->saveDraft($this->ana, $this->contentWith(['knowledge' => ['sources' => ["Planes\u{0000}ocultos"]]]))
            ->assertStatus(422)->assertJsonValidationErrors(['content.configs.knowledge.sources.0']);

        $this->assertSame(0, StudioDraft::query()->count());
    }

    public function test_un_borrador_con_bloqueos_se_guarda_y_los_ensena(): void
    {
        $sinE10 = array_values(array_diff($this->baseline()['definition']['edgeIds'], ['E10']));

        $r = $this->saveDraft($this->ana, $this->contentWith([], [...$sinE10, 'E14']))->assertOk();

        $r->assertJsonPath('data.validation.publishable', false);
        $bloqueos = collect($r->json('data.validation.findings'))->where('level', 'blocking')->pluck('id')->all();
        $this->assertContains('aristas-obligatorias', $bloqueos);

        $diff = StudioAuditEvent::query()->sole()->diff;
        $this->assertSame(['E14'], $diff['edgesAdded']);
        $this->assertSame(['E10'], $diff['edgesRemoved']);
    }

    public function test_descartar_vuelve_a_la_linea_base_sin_borrar_la_fila(): void
    {
        $this->saveDraft($this->ana, $this->contentWith(['strategy' => ['goal' => 'informar']]))->assertOk();
        $uuid = StudioDraft::query()->sole()->uuid;

        $r = $this->studio('POST', 'draft/discard', $this->ana, ['baseRevision' => 1])->assertOk();

        $r->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.isBaseline', true)
            ->assertJsonPath('data.revision', 2);
        $this->assertSame($this->baseline(), $r->json('data.content'));

        $fila = StudioDraft::query()->sole();
        $this->assertSame($uuid, $fila->uuid);
        $this->assertSame(2, $fila->revision);
        $this->assertSame($this->baseline(), $fila->content);

        // Lo descartado queda en la auditoría: con eso se puede reconstruir.
        $evento = StudioAuditEvent::query()->where('event', 'draft.discarded')->sole();
        $this->assertSame([[
            'layer' => 'strategy',
            'key' => 'goal',
            'from' => StudioCatalog::baseline()['configs']['strategy']['goal'],
            'to' => 'informar',
        ]], $evento->diff['configChanges']);
        $this->assertSame(1, $evento->diff['fromRevision']);
        $this->assertSame(2, $evento->diff['toRevision']);

        // Descartar con una revisión vieja: 409.
        $this->studio('POST', 'draft/discard', $this->ana, ['baseRevision' => 1])
            ->assertStatus(409)->assertJsonPath('code', 'draft_conflict')->assertJsonPath('current.revision', 2);
    }

    public function test_descartar_sin_borrador_no_escribe_nada(): void
    {
        $this->studio('POST', 'draft/discard', $this->ana, ['baseRevision' => 0])
            ->assertOk()
            ->assertJsonPath('data.exists', false)
            ->assertJsonPath('data.revision', 0);

        $this->studio('POST', 'draft/discard', $this->ana, ['baseRevision' => 3])
            ->assertStatus(409)->assertJsonPath('code', 'draft_conflict')->assertJsonPath('current.exists', false);

        $this->assertSame(0, StudioDraft::query()->count());
        $this->assertSame(0, StudioAuditEvent::query()->count());
    }

    public function test_cada_persona_tiene_su_borrador(): void
    {
        $bea = $this->person(Admin::ROLE_SUPER_ADMIN, 'Bea Ruiz');
        $this->saveDraft($this->ana, $this->contentWith(['persona' => ['emojis' => false]]))->assertOk();

        // Bea no ve el de Ana: ve la línea base, y guarda el suyo sin choque.
        $this->studio('GET', 'draft', $bea)->assertOk()->assertJsonPath('data.exists', false);
        $this->saveDraft($bea, $this->contentWith(['persona' => ['tone' => 'profesional']]))->assertOk()->assertJsonPath('data.revision', 1);

        $this->studio('GET', 'draft', $this->ana)->assertOk()
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.content.configs.persona.emojis', false)
            ->assertJsonPath('data.content.configs.persona.tone', StudioCatalog::baseline()['configs']['persona']['tone']);
        $this->assertSame(2, StudioDraft::query()->count());
    }
}
