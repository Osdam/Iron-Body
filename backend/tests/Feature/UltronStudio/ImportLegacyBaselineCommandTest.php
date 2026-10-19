<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioRule;
use App\Services\UltronStudio\StudioAudit;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `ultron-studio:import-baseline` (contrato §A6 y §A8): crea las doce reglas
 * del código la primera vez, no cambia nada la segunda, sincroniza y audita lo
 * que alguien alteró a mano, `--dry-run` no escribe y nunca toca una regla del
 * Studio.
 */
class ImportLegacyBaselineCommandTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    public function test_la_primera_ejecucion_crea_las_doce_y_lo_audita_como_sistema(): void
    {
        $this->artisan('ultron-studio:import-baseline')->assertExitCode(0);

        $reglas = StudioRule::query()->orderBy('code')->get();
        $this->assertSame(array_column(StudioCatalog::legacyRules(), 'code'), $reglas->pluck('code')->all());
        foreach (StudioCatalog::legacyRules() as $i => $r) {
            $fila = $reglas[$i];
            $this->assertSame(['legacy_baseline', 'code', 'active', 1], [$fila->source, $fila->enforcement, $fila->status, $fila->revision]);
            $this->assertSame([$r['description'], $r['type'], $r['priority'], $r['layers'], $r['refs'], $r['origin']],
                [$fila->description, $fila->type, $fila->priority, $fila->layers, $fila->refs, $fila->origin]);
            $this->assertSame(StudioContentHasher::ruleHash($r), $fila->content_hash);
            $this->assertNull($fila->created_by_admin_id);
            $this->assertNull($fila->client_request_id);
        }

        $eventos = StudioAuditEvent::query()->orderBy('id')->get();
        $this->assertSame(12, $eventos->where('event', 'rule.legacy_imported')->count());
        $sistema = $eventos->where('event', 'system.baseline_imported')->sole();
        $this->assertSame(['system', null, StudioAudit::SYSTEM_IMPORT], [$sistema->actor_type, $sistema->actor_admin_id, $sistema->actor_name]);
        $this->assertSame('system', $sistema->subject_type);
        $this->assertCount(12, $sistema->diff['created']);
        $this->assertSame([], $sistema->diff['updated']);
        foreach ($eventos as $e) {
            $this->assertSame('system', $e->actor_type);
            $this->assertNull($e->ip_address);
        }
    }

    public function test_la_segunda_ejecucion_no_cambia_nada(): void
    {
        $this->artisan('ultron-studio:import-baseline')->assertExitCode(0);
        $antes = StudioRule::query()->orderBy('id')->get()->map->getAttributes()->all();
        $eventos = StudioAuditEvent::query()->count();

        $this->artisan('ultron-studio:import-baseline')
            ->expectsOutputToContain('nada que importar')
            ->assertExitCode(0);

        $this->assertSame($antes, StudioRule::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($eventos, StudioAuditEvent::query()->count());
    }

    public function test_una_fila_alterada_a_mano_se_sincroniza_y_se_audita(): void
    {
        $this->artisan('ultron-studio:import-baseline')->assertExitCode(0);
        DB::table('ultron_studio_rules')->where('code', 'R-03')->update([
            'description' => 'Alterada a mano',
            'status' => 'archived',
            'archived_at' => now(),
            'archive_reason' => 'a mano',
        ]);

        $this->artisan('ultron-studio:import-baseline')->assertExitCode(0);

        $r03 = StudioRule::query()->where('code', 'R-03')->sole();
        $original = collect(StudioCatalog::legacyRules())->firstWhere('code', 'R-03');
        $this->assertSame($original['description'], $r03->description);
        $this->assertSame('active', $r03->status);
        $this->assertNull($r03->archived_at);
        $this->assertNull($r03->archive_reason);
        $this->assertSame(2, $r03->revision);
        $this->assertSame(1, StudioRule::query()->where('code', 'R-04')->value('revision'));

        $evento = StudioAuditEvent::query()->where('event', 'rule.legacy_synced')->sole();
        $this->assertSame($r03->uuid, $evento->subject_uuid);
        $this->assertSame(['archived', 'active'], [$evento->from_status, $evento->to_status]);
        $cambios = collect($evento->diff['changes'])->keyBy('field');
        $this->assertSame(['Alterada a mano', $original['description']], [$cambios['description']['from'], $cambios['description']['to']]);
        $this->assertSame(['archived', 'active'], [$cambios['status']['from'], $cambios['status']['to']]);
        $this->assertSame(['R-03'], StudioAuditEvent::query()->where('event', 'system.baseline_imported')->orderByDesc('id')->firstOrFail()->diff['updated']);
    }

    public function test_las_filas_de_f_se_sincronizan_con_las_condiciones_y_aristas_de_g(): void
    {
        // Hito G (contrato G §A3 y §A8): las doce como las dejó F en producción,
        // sin `conditions` y sin las aristas que añade G.
        foreach (StudioCatalog::legacyRules() as $r) {
            $deF = ['description' => $r['description'], 'type' => $r['type'], 'layers' => $r['layers'], 'priority' => $r['priority'],
                'refs' => array_replace($r['refs'], ['edges' => []]), 'origin' => $r['origin']];
            StudioRule::query()->forceCreate($deF + ['code' => $r['code'], 'source' => 'legacy_baseline', 'enforcement' => 'code',
                'status' => 'active', 'revision' => 1, 'content_hash' => StudioContentHasher::ruleHash($deF)]);
        }

        $this->artisan('ultron-studio:import-baseline')->assertExitCode(0);

        $cambian = ['R-01', 'R-02', 'R-04', 'R-05', 'R-07', 'R-09', 'R-10', 'R-12'];
        $sistema = StudioAuditEvent::query()->where('event', 'system.baseline_imported')->sole();
        $this->assertSame($cambian, $sistema->diff['updated']);
        $this->assertSame(['R-03', 'R-06', 'R-08', 'R-11'], $sistema->diff['unchanged']);
        foreach (StudioCatalog::legacyRules() as $r) {
            $fila = StudioRule::query()->where('code', $r['code'])->sole();
            $this->assertSame([$r['conditions'], $r['refs'], StudioContentHasher::ruleHash($r)], [$fila->conditions, $fila->refs, $fila->content_hash], $r['code']);
            $this->assertSame(in_array($r['code'], $cambian, true) ? 2 : 1, $fila->revision, $r['code']);
        }

        // La sincronización enseña el cambio de condiciones y aristas, sin recortar.
        $r01 = StudioAuditEvent::query()->where('event', 'rule.legacy_synced')->where('subject_uuid', StudioRule::query()->where('code', 'R-01')->value('uuid'))->sole();
        $cambios = collect($r01->diff['changes'])->keyBy('field');
        $this->assertSame(['refs', 'conditions', 'content_hash'], $cambios->keys()->all());
        $this->assertNull($cambios['conditions']['from']);
        $this->assertSame(StudioCatalog::legacyConditions()['R-01'], $cambios['conditions']['to']);
        $this->assertSame(['E07', 'E19'], $cambios['refs']['to']['edges']);
        $this->assertSame(8, StudioAuditEvent::query()->where('event', 'rule.legacy_synced')->count());

        // Y la segunda vez ya no hay nada que sincronizar.
        $this->artisan('ultron-studio:import-baseline')->expectsOutputToContain('nada que importar')->assertExitCode(0);
    }

    public function test_dry_run_no_escribe_nada(): void
    {
        $this->artisan('ultron-studio:import-baseline', ['--dry-run' => true])
            ->expectsOutputToContain('simulación')
            ->assertExitCode(0);

        $this->assertSame(0, StudioRule::query()->count());
        $this->assertSame(0, StudioAuditEvent::query()->count());
    }

    public function test_nunca_toca_las_reglas_del_studio(): void
    {
        $this->artisan('ultron-studio:import-baseline')->assertExitCode(0);
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $uuid = (string) $this->studio('POST', 'rules', $ana, [
            'description' => 'Regla del Studio.', 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'],
            'clientRequestId' => (string) Str::uuid(),
        ])->assertCreated()->json('data.uuid');
        $antes = StudioRule::query()->where('uuid', $uuid)->sole()->getAttributes();
        DB::table('ultron_studio_rules')->where('code', 'R-05')->update(['description' => 'Alterada']);

        $this->artisan('ultron-studio:import-baseline')->assertExitCode(0);

        $this->assertSame($antes, StudioRule::query()->where('uuid', $uuid)->sole()->getAttributes());
    }

    public function test_si_una_regla_del_studio_ocupa_un_codigo_del_codigo_no_importa_nada(): void
    {
        StudioRule::query()->forceCreate([
            'code' => 'R-05', 'source' => 'studio', 'enforcement' => 'none', 'status' => 'draft',
            'description' => 'Del Studio con un código del código', 'type' => 'style', 'priority' => 'normal',
            'layers' => ['persona'], 'refs' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []],
            'revision' => 1, 'content_hash' => str_repeat('a', 64),
        ]);

        $this->assertSame(1, Artisan::call('ultron-studio:import-baseline'));

        $this->assertSame(1, StudioRule::query()->count());
        $this->assertSame(0, StudioAuditEvent::query()->count());
    }
}
