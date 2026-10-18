<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioDraft;
use App\Models\UltronStudio\StudioRule;
use App\Models\UltronStudio\StudioVersion;
use App\Models\UltronStudio\StudioVersionReview;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Inmutabilidad en tres capas (contrato §A1 y §A8): no hay ruta que edite
 * contenido (lo cubren las pruebas de la API), los MODELOS lo impiden y la BASE
 * lo impide aunque se escriba a mano.
 *
 * En PostgreSQL, cada sentencia que tiene que fallar va en una transacción
 * anidada (SAVEPOINT): sin ella el error abortaría la transacción de la prueba
 * y la siguiente sentencia daría 25P02.
 */
class UltronStudioImmutabilityTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private StudioVersion $version;

    private StudioVersionReview $review;

    private StudioAuditEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importBaseline();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');

        $uuid = $this->submittedVersion($ana);
        $this->studio('POST', "versions/{$uuid}/approve", $root, ['acknowledgedWarnings' => ['opcional-E14', 'opcional-E16']])->assertOk();

        $this->version = StudioVersion::query()->where('uuid', $uuid)->sole();
        $this->review = StudioVersionReview::query()->sole();
        $this->event = StudioAuditEvent::query()->orderBy('id')->firstOrFail();
    }

    // ── Capa 2: los modelos ─────────────────────────────────────────────────

    public function test_eloquent_no_cambia_nada_inmutable_de_una_version(): void
    {
        foreach ([
            'label' => 'Otra etiqueta',
            'note' => 'Otra nota',
            'content' => ['definition' => [], 'configs' => []],
            'content_hash' => str_repeat('0', 64),
            'created_by_admin_id' => 999,
        ] as $campo => $valor) {
            $v = $this->version->fresh();
            try {
                $v->forceFill([$campo => $valor])->save();
                $this->fail("Eloquent dejó cambiar {$campo}.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('inmutable', $e->getMessage(), $campo);
            }
        }

        $this->assertSame($this->version->content_hash, $this->version->fresh()->content_hash);
    }

    public function test_eloquent_si_cambia_lo_que_mueve_la_maquina_de_estados(): void
    {
        $v = $this->version->fresh();
        $v->forceFill(['status_changed_at' => now()->subMinute()])->save();

        $this->assertTrue($v->wasChanged('status_changed_at'));
    }

    public function test_eloquent_no_borra_versiones_resenas_auditoria_reglas_ni_borradores(): void
    {
        foreach ([
            'versión' => $this->version->fresh(),
            'reseña' => $this->review->fresh(),
            'evento' => $this->event->fresh(),
            'regla' => StudioRule::query()->firstOrFail(),
            'borrador' => StudioDraft::query()->firstOrFail(),
        ] as $que => $modelo) {
            try {
                $modelo->delete();
                $this->fail("Eloquent dejó borrar un(a) {$que}.");
            } catch (LogicException $e) {
                $this->assertStringStartsWith('ultron_studio:', $e->getMessage(), $que);
            }
        }
    }

    public function test_eloquent_no_actualiza_resenas_ni_auditoria(): void
    {
        foreach ([[$this->review->fresh(), 'note'], [$this->event->fresh(), 'summary']] as [$modelo, $campo]) {
            try {
                $modelo->forceFill([$campo => 'reescrito'])->save();
                $this->fail('Eloquent dejó reescribir '.$modelo::class);
            } catch (LogicException $e) {
                $this->assertStringContainsString('solo inserción', $e->getMessage());
            }
        }
    }

    public function test_la_transicion_solo_escribe_columnas_mutables(): void
    {
        $this->expectException(LogicException::class);
        StudioVersion::transition($this->version->id, ['APPROVED'], ['status' => 'APPROVED', 'label' => 'colada']);
    }

    // ── Capa 3: la base, con el constructor de consultas a pelo ─────────────

    public function test_la_base_rechaza_cambiar_el_contenido_de_una_version_aunque_sea_el_mismo(): void
    {
        $id = $this->version->id;

        $this->assertRejectedByDatabase(fn () => DB::table('ultron_studio_versions')->where('id', $id)->update(['content' => '{}']));
        // El disparador va por lista de columnas: escribir el MISMO valor también falla.
        $this->assertRejectedByDatabase(fn () => DB::table('ultron_studio_versions')->where('id', $id)->update(['label' => DB::raw('label')]));
        $this->assertRejectedByDatabase(fn () => DB::table('ultron_studio_versions')->where('id', $id)->update(['content_hash' => str_repeat('0', 64)]));
        $this->assertRejectedByDatabase(fn () => DB::table('ultron_studio_versions')->where('id', $id)->update(['created_at' => now()]));
        $this->assertRejectedByDatabase(fn () => DB::table('ultron_studio_versions')->where('id', $id)->delete());

        $this->assertSame($this->version->content_hash, DB::table('ultron_studio_versions')->where('id', $id)->value('content_hash'));
    }

    public function test_la_base_si_deja_mover_el_estado(): void
    {
        $filas = DB::table('ultron_studio_versions')->where('id', $this->version->id)->update([
            'status' => 'REJECTED',
            'last_validation' => '{"publishable":true}',
            'status_changed_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, $filas);
        $this->assertSame('REJECTED', $this->version->fresh()->status);
    }

    public function test_la_base_rechaza_reescribir_o_borrar_resenas_y_auditoria(): void
    {
        $this->assertRejectedByDatabase(fn () => DB::table('ultron_studio_version_reviews')->where('id', $this->review->id)->update(['note' => 'reescrita']));
        $this->assertRejectedByDatabase(fn () => DB::table('ultron_studio_version_reviews')->where('id', $this->review->id)->delete());
        $this->assertRejectedByDatabase(fn () => DB::table('ultron_studio_audit_events')->where('id', $this->event->id)->update(['summary' => 'reescrito']));
        $this->assertRejectedByDatabase(fn () => DB::table('ultron_studio_audit_events')->where('id', $this->event->id)->delete());
        $this->assertRejectedByDatabase(fn () => DB::table('ultron_studio_audit_events')->delete());

        // Insertar sí: es un libro.
        DB::table('ultron_studio_audit_events')->insert([
            'event' => 'system.baseline_imported', 'subject_type' => 'system', 'actor_type' => 'system',
            'actor_name' => 'prueba', 'summary' => 'insertar se puede', 'created_at' => now(),
        ]);
        $this->assertSame('insertar se puede', StudioAuditEvent::query()->orderByDesc('id')->value('summary'));
    }

    public function test_solo_en_postgres_truncate_y_los_check(): void
    {
        if (! $this->isPostgres()) {
            // SQLite no tiene TRUNCATE ni admite añadir CHECK: lo vigila el
            // modelo (estados alcanzables, reglas del Studio nunca activas).
            // Se marca OMITIDA, no verde: el hueco tiene que verse en la suite.
            $this->markTestSkipped('Solo PostgreSQL: TRUNCATE y los CHECK de dominio. Ejecuta el grupo con DB_CONNECTION=pgsql.');
        }

        foreach (['ultron_studio_versions', 'ultron_studio_version_reviews', 'ultron_studio_audit_events'] as $tabla) {
            $this->assertRejectedByDatabase(fn () => DB::statement("TRUNCATE {$tabla} CASCADE"), 'P0001');
        }
        $this->assertRejectedByDatabase(
            fn () => DB::table('ultron_studio_versions')->where('id', $this->version->id)->update(['status' => 'PUBLISHED']),
            '23514',
        );
        $this->assertRejectedByDatabase(
            fn () => DB::table('ultron_studio_rules')->insert([
                'uuid' => (string) Str::uuid(), 'code' => 'S-900', 'source' => 'studio', 'enforcement' => 'none', 'status' => 'active',
                'description' => 'activa', 'type' => 'style', 'priority' => 'normal', 'layers' => '["persona"]', 'refs' => '{}',
                'revision' => 1, 'content_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
            ]),
            '23514',
        );
    }

    /**
     * La sentencia tiene que fallar en la BASE (QueryException), con el
     * mensaje del disparador o el SQLSTATE indicado. En un SAVEPOINT, para que
     * la transacción de la prueba siga viva en PostgreSQL.
     */
    private function assertRejectedByDatabase(Closure $sentencia, ?string $sqlstate = null): void
    {
        try {
            DB::transaction(fn () => $sentencia());
            $this->fail('La base aceptó una escritura que tenía que rechazar.');
        } catch (QueryException $e) {
            if ($sqlstate !== null) {
                $this->assertSame($sqlstate, (string) $e->getCode(), $e->getMessage());

                return;
            }
            $this->assertStringContainsString('ultron_studio:', $e->getMessage());
            $this->assertSame($this->isPostgres() ? 'P0001' : '23000', (string) $e->getCode());
        }
    }
}
