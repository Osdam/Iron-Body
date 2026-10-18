<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVersionReview;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioVersionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * La máquina de estados de las versiones (contrato §A2 y §A8): cada fila de la
 * tabla, la idempotencia, la copia de las reglas, la integridad, los cuatro
 * ojos por identidad, el reconocimiento exacto de los avisos, publicar y
 * revertir siempre en 409, y que ningún estado inalcanzable aparezca nunca.
 */
class UltronStudioVersionLifecycleTest extends TestCase
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

    public function test_crear_copia_el_borrador_guardado_con_sus_reglas_y_su_huella(): void
    {
        $r = $this->createVersion($this->ana, 'Cobro con enlaces (propuesta)')->assertCreated();

        $r->assertJsonPath('ok', true)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.statusLabel', 'Creada, sin enviar')
            ->assertJsonPath('data.mine', true)
            ->assertJsonPath('data.createdBy.name', 'Ana Pérez')
            ->assertJsonPath('data.createdBy.role', 'Administrador')
            ->assertJsonPath('data.catalogVersion', StudioCatalog::VERSION)
            ->assertJsonPath('data.warnings', ['opcional-E14', 'opcional-E16'])
            ->assertJsonPath('data.blocking', 0)
            ->assertJsonPath('data.review', null)
            ->assertJsonPath('data.engineReads', false)
            ->assertJsonPath('data.integrityOk', true)
            ->assertJsonPath('data.actions.submit.allowed', true)
            ->assertJsonPath('data.actions.approve', ['allowed' => false, 'code' => 'four_eyes', 'message' => 'Nadie aprueba ni rechaza lo que creó: tiene que revisarla otra persona.'])
            ->assertJsonPath('data.actions.publish.code', 'publishing_disabled');
        $this->assertSame(substr((string) $r->json('data.contentHash'), 0, 8), $r->json('data.shortHash'));

        $v = StudioVersion::query()->sole();
        $this->assertSame(1, $v->source_draft_revision);
        $this->assertSame(StudioCatalog::hash(), $v->catalog_hash);
        // Copió las doce del código, ordenadas por código.
        $this->assertSame(array_column(StudioCatalog::legacyRules(), 'code'), array_column($v->content['rules'], 'code'));
        $this->assertSame(
            StudioContentHasher::versionHash(StudioCatalog::VERSION, $v->content, $v->content['rules']),
            $v->content_hash,
        );
        $this->assertSame($this->baseline()['definition'], $r->json('data.content.definition'));

        $evento = StudioAuditEvent::query()->where('event', 'version.created')->sole();
        $this->assertSame($v->uuid, $evento->subject_uuid);
        $this->assertSame('DRAFT', $evento->to_status);
        $this->assertSame(['draftRevision' => 1, 'catalogVersion' => StudioCatalog::VERSION, 'warnings' => ['opcional-E14', 'opcional-E16'], 'rulesCount' => 12], $evento->diff);
    }

    public function test_crear_es_idempotente_por_client_request_id(): void
    {
        $id = (string) Str::uuid();
        $primera = $this->createVersion($this->ana, 'Doble clic', $id)->assertCreated();

        $this->studio('POST', 'versions', $this->ana, ['label' => 'Doble clic', 'note' => null, 'draftRevision' => 1, 'clientRequestId' => $id])
            ->assertOk()
            ->assertJsonPath('replayed', true)
            ->assertJsonPath('data.uuid', $primera->json('data.uuid'));

        // La misma llave para otra cosa, o de otra persona, no es la misma operación.
        $this->studio('POST', 'versions', $this->ana, ['label' => 'Otra etiqueta', 'note' => null, 'draftRevision' => 1, 'clientRequestId' => $id])
            ->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->saveDraft($this->root)->assertOk();
        $this->studio('POST', 'versions', $this->root, ['label' => 'Doble clic', 'note' => null, 'draftRevision' => 1, 'clientRequestId' => $id])
            ->assertStatus(409)->assertJsonPath('code', 'client_request_reused');

        $this->assertSame(1, StudioVersion::query()->count());
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'version.created')->count());
    }

    public function test_crear_sin_borrador_o_con_revision_vieja(): void
    {
        $this->studio('POST', 'versions', $this->ana, ['label' => 'Sin borrador', 'note' => null, 'draftRevision' => 1, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(404)->assertJsonPath('code', 'draft_not_found');

        $this->saveDraft($this->ana)->assertOk();
        $this->studio('POST', 'versions', $this->ana, ['label' => 'Revisión vieja', 'note' => null, 'draftRevision' => 2, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)
            ->assertJsonPath('code', 'draft_conflict')
            ->assertJsonPath('current.revision', 1);

        $this->studio('POST', 'versions', $this->ana, ['label' => '', 'draftRevision' => 1, 'clientRequestId' => 'no-es-un-uuid'])
            ->assertStatus(422)->assertJsonValidationErrors(['label', 'clientRequestId']);

        $this->assertSame(0, StudioVersion::query()->count());
    }

    public function test_crear_con_bloqueos_da_422_y_no_crea_nada(): void
    {
        $sinE10 = array_values(array_diff($this->baseline()['definition']['edgeIds'], ['E10']));
        $this->saveDraft($this->ana, $this->contentWith([], $sinE10))->assertOk();
        $antes = StudioAuditEvent::query()->count();

        $r = $this->studio('POST', 'versions', $this->ana, ['label' => 'Con bloqueos', 'note' => null, 'draftRevision' => 1, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_blocked');

        $this->assertContains('aristas-obligatorias', collect($r->json('findings'))->where('level', 'blocking')->pluck('id')->all());
        $this->assertSame(0, StudioVersion::query()->count());
        $this->assertSame($antes, StudioAuditEvent::query()->count());
    }

    public function test_validar_simula_y_no_escribe_nada(): void
    {
        $uuid = (string) $this->createVersion($this->ana)->assertCreated()->json('data.uuid');
        $eventos = StudioAuditEvent::query()->count();
        $antes = StudioVersion::query()->sole()->getAttributes();

        $this->studio('POST', "versions/{$uuid}/validate", $this->root)
            ->assertOk()
            ->assertJsonPath('data.catalogVersion', StudioCatalog::VERSION)
            ->assertJsonPath('data.publishable', true)
            ->assertJsonPath('data.warnings', ['opcional-E14', 'opcional-E16'])
            ->assertJsonPath('data.wouldBecome', 'REVIEW_REQUIRED');

        $this->assertSame($eventos, StudioAuditEvent::query()->count());
        $this->assertSame($antes, StudioVersion::query()->sole()->getAttributes());
    }

    public function test_enviar_solo_quien_la_creo_y_solo_desde_draft(): void
    {
        $uuid = (string) $this->createVersion($this->ana)->assertCreated()->json('data.uuid');

        $this->studio('POST', "versions/{$uuid}/submit", $this->root)
            ->assertStatus(403)->assertJsonPath('code', 'not_version_owner');

        $r = $this->studio('POST', "versions/{$uuid}/submit", $this->ana)->assertOk();
        $r->assertJsonPath('data.status', 'REVIEW_REQUIRED')
            ->assertJsonPath('data.statusLabel', 'Con avisos · espera revisión')
            ->assertJsonPath('data.actions.submit.allowed', false);
        $this->assertNotNull($r->json('data.submittedAt'));

        $evento = StudioAuditEvent::query()->where('event', 'version.submitted')->sole();
        $this->assertSame(['DRAFT', 'REVIEW_REQUIRED'], [$evento->from_status, $evento->to_status]);
        $this->assertSame(['opcional-E14', 'opcional-E16'], $evento->diff['warnings']);

        $this->studio('POST', "versions/{$uuid}/submit", $this->ana)
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition')
            ->assertJsonPath('currentStatus', 'REVIEW_REQUIRED')
            ->assertJsonPath('allowedFrom', ['DRAFT']);
    }

    public function test_sin_avisos_enviar_lleva_a_validated(): void
    {
        $v = $this->seedVersion($this->ana, $this->contentWithoutWarnings());
        $this->studio('POST', "versions/{$v->uuid}/validate", $this->ana)
            ->assertOk()->assertJsonPath('data.warnings', [])->assertJsonPath('data.wouldBecome', 'VALIDATED');

        $this->studio('POST', "versions/{$v->uuid}/submit", $this->ana)
            ->assertOk()
            ->assertJsonPath('data.status', 'VALIDATED')
            ->assertJsonPath('data.statusLabel', 'Validada, espera revisión');

        // Sin avisos, aprobar exige reconocer el conjunto vacío.
        $this->studio('POST', "versions/{$v->uuid}/approve", $this->root, ['acknowledgedWarnings' => [], 'note' => 'Sin avisos'])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_enviar_con_bloqueos_da_422_y_sigue_en_draft(): void
    {
        $sinE10 = array_values(array_diff($this->baseline()['definition']['edgeIds'], ['E10']));
        $v = $this->seedVersion($this->ana, $this->contentWith([], $sinE10));

        $this->studio('POST', "versions/{$v->uuid}/submit", $this->ana)
            ->assertStatus(422)->assertJsonPath('code', 'validation_blocked');
        $this->assertSame('DRAFT', $v->fresh()->status);
    }

    public function test_aprobar_exige_otra_persona_y_el_intento_propio_queda_auditado(): void
    {
        $uuid = $this->submittedVersion($this->ana);

        $this->studio('POST', "versions/{$uuid}/approve", $this->ana, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])
            ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $this->studio('POST', "versions/{$uuid}/reject", $this->ana, ['reason' => 'Me la rechazo yo'])
            ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');

        $denegados = StudioAuditEvent::query()->where('event', 'version.four_eyes_denied')->orderBy('id')->get();
        $this->assertSame([['action' => 'approve'], ['action' => 'reject']], $denegados->pluck('diff')->all());
        $this->assertSame([$this->ana->id, $this->ana->id], $denegados->pluck('actor_admin_id')->all());
        $this->assertSame('REVIEW_REQUIRED', StudioVersion::query()->sole()->status);

        $r = $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['acknowledgedWarnings' => ['opcional-E16', 'opcional-E14'], 'note' => 'Revisado con el propietario'])
            ->assertOk();
        $r->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.review.decision', 'approved')
            ->assertJsonPath('data.review.by', ['name' => 'Root', 'role' => 'Super Admin'])
            ->assertJsonPath('data.review.note', 'Revisado con el propietario')
            ->assertJsonPath('data.review.acknowledgedWarnings', ['opcional-E14', 'opcional-E16'])
            ->assertJsonPath('data.mine', false);

        $resena = StudioVersionReview::query()->sole();
        $this->assertSame($this->root->id, $resena->reviewer_admin_id);
        $this->assertSame(StudioVersion::query()->sole()->content_hash, $resena->content_hash);
        $this->assertTrue($resena->validation['publishable']);

        $evento = StudioAuditEvent::query()->where('event', 'version.approved')->sole();
        $this->assertSame(['REVIEW_REQUIRED', 'APPROVED'], [$evento->from_status, $evento->to_status]);
        $this->assertSame(['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16'], 'note' => 'Revisado con el propietario'], $evento->diff);
    }

    public function test_los_cuatro_ojos_comparan_la_identidad_y_no_el_rol(): void
    {
        // Dos Super Admin: mismo rol, mismos permisos, personas distintas.
        $otraRoot = $this->person(Admin::ROLE_SUPER_ADMIN, 'Otra Root');
        $uuid = $this->submittedVersion($this->root);

        $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])
            ->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        $this->studio('POST', "versions/{$uuid}/approve", $otraRoot, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_el_reconocimiento_de_avisos_tiene_que_ser_exacto(): void
    {
        $uuid = $this->submittedVersion($this->ana);

        foreach ([[], ['opcional-E14'], ['opcional-E14', 'opcional-E16', 'config-cobro-aprobacion']] as $reconocidos) {
            $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['acknowledgedWarnings' => $reconocidos])
                ->assertStatus(409)
                ->assertJsonPath('code', 'warnings_not_acknowledged')
                ->assertJsonPath('warnings', ['opcional-E14', 'opcional-E16']);
        }
        $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E14']])
            ->assertStatus(422)->assertJsonValidationErrors(['acknowledgedWarnings.0']);
        $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['note' => 'sin la lista'])
            ->assertStatus(422)->assertJsonValidationErrors(['acknowledgedWarnings']);

        $this->assertSame(0, StudioVersionReview::query()->count());
        $this->assertSame('REVIEW_REQUIRED', StudioVersion::query()->sole()->status);
    }

    public function test_una_segunda_decision_da_409(): void
    {
        $uuid = $this->submittedVersion($this->ana);
        $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])->assertOk();

        $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_transition')->assertJsonPath('currentStatus', 'APPROVED');
        $this->studio('POST', "versions/{$uuid}/reject", $this->root, ['reason' => 'Demasiado tarde'])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

        $this->assertSame(1, StudioVersionReview::query()->count());
    }

    public function test_rechazar_exige_motivo_y_es_terminal(): void
    {
        $uuid = $this->submittedVersion($this->ana);

        $this->studio('POST', "versions/{$uuid}/reject", $this->root, ['reason' => 'no'])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->studio('POST', "versions/{$uuid}/reject", $this->root, ['reason' => str_repeat('a', 501)])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->studio('POST', "versions/{$uuid}/reject", $this->root, ['reason' => 'Falta el resultado de Wompi'])
            ->assertOk()
            ->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.review.decision', 'rejected')
            ->assertJsonPath('data.review.note', 'Falta el resultado de Wompi')
            ->assertJsonPath('data.review.acknowledgedWarnings', []);

        $this->assertSame(['reason' => 'Falta el resultado de Wompi'], StudioAuditEvent::query()->where('event', 'version.rejected')->sole()->diff);
        // REJECTED es terminal.
        $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])
            ->assertStatus(409)->assertJsonPath('code', 'invalid_transition')->assertJsonPath('currentStatus', 'REJECTED');
        $this->studio('POST', "versions/{$uuid}/submit", $this->ana)
            ->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    }

    public function test_aprobar_revalida_y_con_bloqueos_da_409(): void
    {
        $sinE10 = array_values(array_diff($this->baseline()['definition']['edgeIds'], ['E10']));
        $v = $this->seedVersion($this->ana, $this->contentWith([], $sinE10), 'REVIEW_REQUIRED');

        $r = $this->studio('POST', "versions/{$v->uuid}/approve", $this->root, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])
            ->assertStatus(409)
            ->assertJsonPath('code', 'revalidation_blocked');
        $this->assertContains('aristas-obligatorias', collect($r->json('findings'))->where('level', 'blocking')->pluck('id')->all());
        $this->assertSame('REVIEW_REQUIRED', $v->fresh()->status);
    }

    public function test_una_huella_que_no_cuadra_se_ve_y_bloquea_enviar_y_aprobar(): void
    {
        $alterada = $this->seedVersion($this->ana, $this->baseline(), 'DRAFT', str_repeat('f', 64));
        $this->studio('GET', "versions/{$alterada->uuid}", $this->root)->assertOk()->assertJsonPath('data.integrityOk', false);
        $this->studio('POST', "versions/{$alterada->uuid}/submit", $this->ana)
            ->assertStatus(409)->assertJsonPath('code', 'integrity_mismatch');

        $enviada = $this->seedVersion($this->ana, $this->baseline(), 'REVIEW_REQUIRED', str_repeat('e', 64));
        $this->studio('POST', "versions/{$enviada->uuid}/approve", $this->root, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])
            ->assertStatus(409)->assertJsonPath('code', 'integrity_mismatch');

        $sana = $this->seedVersion($this->ana, $this->baseline());
        $this->studio('GET', "versions/{$sana->uuid}", $this->root)->assertOk()->assertJsonPath('data.integrityOk', true);
    }

    public function test_publicar_y_revertir_responden_409_siempre_y_queda_auditado(): void
    {
        $uuid = $this->submittedVersion($this->ana);
        $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])->assertOk();

        $this->studio('POST', "versions/{$uuid}/publish", $this->root)
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'publishing_disabled')
            ->assertJsonPath('message', 'Publicar está desactivado: el motor de ULTRON aún no lee versiones del Studio.');
        $this->studio('POST', "versions/{$uuid}/rollback", $this->root)
            ->assertStatus(409)
            ->assertJsonPath('code', 'publishing_disabled')
            ->assertJsonPath('message', 'Revertir está desactivado: el motor de ULTRON aún no lee versiones del Studio.');

        // Con la bandera encendida, el motor sigue sin leer versiones.
        config(['ultron_studio.publishing_enabled' => true]);
        $this->studio('POST', "versions/{$uuid}/publish", $this->root)
            ->assertStatus(409)->assertJsonPath('code', 'engine_not_reading_versions');
        $this->studio('GET', 'status', $this->root)->assertOk()
            ->assertJsonPath('data.publishing.available', false)
            ->assertJsonPath('data.publishing.code', 'engine_not_reading_versions');

        $this->assertSame('APPROVED', StudioVersion::query()->sole()->status);
        $this->assertSame(
            [['code' => 'publishing_disabled'], ['code' => 'engine_not_reading_versions']],
            StudioAuditEvent::query()->where('event', 'version.publish_refused')->orderBy('id')->pluck('diff')->all(),
        );
        $this->assertSame(1, StudioAuditEvent::query()->where('event', 'version.rollback_refused')->count());
    }

    public function test_ningun_estado_inalcanzable_aparece_nunca(): void
    {
        $uuid = $this->submittedVersion($this->ana);
        $this->studio('POST', "versions/{$uuid}/approve", $this->root, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])->assertOk();
        $this->studio('POST', "versions/{$uuid}/publish", $this->root)->assertStatus(409);
        $rechazada = $this->submittedVersion($this->ana, 'Otra');
        $this->studio('POST', "versions/{$rechazada}/reject", $this->root, ['reason' => 'No convence'])->assertOk();

        $alcanzables = StudioVersionStatus::reachableValues();
        $this->assertSame(['DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED'], $alcanzables);
        foreach (StudioVersion::query()->pluck('status') as $estado) {
            $this->assertContains($estado, $alcanzables);
        }
        foreach (StudioAuditEvent::query()->whereNotNull('to_status')->where('subject_type', 'version')->pluck('to_status') as $estado) {
            $this->assertContains($estado, $alcanzables);
        }

        // Y el modelo no deja escribirlos, tampoco en SQLite (sin CHECK).
        try {
            $this->seedVersion($this->ana, $this->baseline(), 'PUBLISHED');
            $this->fail('Una versión PUBLISHED no puede crearse.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('no alcanzable', $e->getMessage());
        }
        $this->expectException(LogicException::class);
        StudioVersion::transition(StudioVersion::query()->value('id'), ['APPROVED'], ['status' => 'CANARY']);
    }

    public function test_la_version_copia_las_reglas_no_archivadas(): void
    {
        $viva = $this->studio('POST', 'rules', $this->root, ['description' => 'Proponer la cita antes que el precio.', 'type' => 'condition', 'priority' => 'normal', 'layers' => ['strategy'], 'clientRequestId' => (string) Str::uuid()])->assertCreated();
        $archivada = $this->studio('POST', 'rules', $this->root, ['description' => 'Regla que se archiva.', 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'], 'clientRequestId' => (string) Str::uuid()])->assertCreated();
        $this->studio('POST', 'rules/'.$archivada->json('data.uuid').'/archive', $this->root, ['baseRevision' => 1, 'reason' => 'Ya no aplica'])->assertOk();

        $r = $this->createVersion($this->ana)->assertCreated();

        $codigos = array_column($r->json('data.content.rules'), 'code');
        $this->assertContains($viva->json('data.code'), $codigos);
        $this->assertNotContains($archivada->json('data.code'), $codigos);
        $this->assertCount(13, $codigos);
        $copia = collect($r->json('data.content.rules'))->firstWhere('code', $viva->json('data.code'));
        $this->assertSame(['code', 'revision', 'source', 'enforcement', 'status', 'description', 'type', 'layers', 'priority', 'refs'], array_keys($copia));
        $this->assertSame(['studio', 'none', 'draft'], [$copia['source'], $copia['enforcement'], $copia['status']]);
    }

    public function test_dos_aprobaciones_a_la_vez_dan_un_200_y_un_409(): void
    {
        $uuid = $this->submittedVersion($this->ana);
        $otra = $this->person(Admin::ROLE_SUPER_ADMIN, 'Otra revisora');

        // La otra revisora aprueba justo entre la revalidación de esta y su
        // UPDATE condicionado: el UPDATE no encuentra la versión en revisión.
        $this->beforeStatement('update "ultron_studio_versions"', function () use ($uuid): void {
            DB::table('ultron_studio_versions')->where('uuid', $uuid)->update(['status' => 'APPROVED']);
        });

        $this->studio('POST', "versions/{$uuid}/approve", $otra, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition')
            ->assertJsonPath('currentStatus', 'APPROVED');
        $this->assertSame(0, StudioVersionReview::query()->count());
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'version.approved')->count());
    }

    public function test_la_lista_filtra_por_estado_y_por_alcance(): void
    {
        $deAna = $this->submittedVersion($this->ana, 'De Ana enviada');
        $this->createVersion($this->ana, 'De Ana sin enviar')->assertCreated();
        $deRoot = $this->submittedVersion($this->root, 'De Root enviada');

        $uuids = fn ($r): array => array_column($r->json('data.data'), 'uuid');

        $todas = $this->studio('GET', 'versions', $this->ana)->assertOk();
        $this->assertSame(3, $todas->json('data.total'));
        $this->assertSame(['De Root enviada', 'De Ana sin enviar', 'De Ana enviada'], array_column($todas->json('data.data'), 'label'));

        $this->assertSame([$deRoot], $uuids($this->studio('GET', 'versions?scope=reviewable', $this->ana)->assertOk()));
        $this->assertSame([$deAna], $uuids($this->studio('GET', 'versions?scope=reviewable', $this->root)->assertOk()));
        $this->assertCount(2, $uuids($this->studio('GET', 'versions?scope=mine', $this->ana)->assertOk()));
        $this->assertCount(2, $uuids($this->studio('GET', 'versions?status=VALIDATED,REVIEW_REQUIRED', $this->ana)->assertOk()));
        $this->assertSame([], $uuids($this->studio('GET', 'versions?status=PUBLISHED', $this->ana)->assertOk()));

        $pagina = $this->studio('GET', 'versions?per_page=500', $this->ana)->assertOk();
        $this->assertSame(100, $pagina->json('data.per_page'));
        $this->studio('GET', 'versions?status=INVENTADO', $this->ana)->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->studio('GET', 'versions?scope=todas', $this->ana)->assertStatus(422)->assertJsonValidationErrors(['scope']);

        // El resumen no lleva el contenido, pero sí el resumen de la reseña.
        $this->assertArrayNotHasKey('content', $todas->json('data.data.0'));
        $this->assertArrayHasKey('review', $todas->json('data.data.0'));
    }

    public function test_los_textos_libres_pasan_por_plaintext(): void
    {
        $this->saveDraft($this->ana)->assertOk();
        $this->studio('POST', 'versions', $this->ana, ['label' => "Etiqueta\u{0007}", 'note' => null, 'draftRevision' => 1, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors(['label']);
        $this->studio('POST', 'versions', $this->ana, ['label' => str_repeat('a', 81), 'note' => null, 'draftRevision' => 1, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors(['label']);
        $this->studio('POST', 'versions', $this->ana, ['label' => 'Bien', 'note' => "Nota\u{200B}invisible", 'draftRevision' => 1, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(422)->assertJsonValidationErrors(['note']);
        // Una nota de varias líneas sí.
        $this->studio('POST', 'versions', $this->ana, ['label' => 'Bien', 'note' => "Primera línea\nSegunda línea", 'draftRevision' => 1, 'clientRequestId' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.note', "Primera línea\nSegunda línea");

        $uuid = $this->submittedVersion($this->ana, 'Para rechazar');
        $this->studio('POST', "versions/{$uuid}/reject", $this->root, ['reason' => "Motivo\u{0000}con nulo"])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_una_version_que_no_existe_es_404(): void
    {
        $nadie = (string) Str::uuid();
        $this->studio('GET', "versions/{$nadie}", $this->root)->assertStatus(404)->assertJsonPath('code', 'version_not_found');
        $this->studio('POST', "versions/{$nadie}/approve", $this->root, ['acknowledgedWarnings' => []])->assertStatus(404)->assertJsonPath('code', 'version_not_found');
        $this->studio('POST', "versions/{$nadie}/publish", $this->root)->assertStatus(404)->assertJsonPath('code', 'version_not_found');
        $this->studio('GET', 'versions/no-es-un-uuid', $this->root)->assertStatus(404);
    }
}
