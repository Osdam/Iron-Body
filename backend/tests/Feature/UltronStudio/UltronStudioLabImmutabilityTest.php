<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Models\UltronStudio\StudioLabScenario;
use App\Models\UltronStudio\StudioLabVerdict;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

/**
 * La inmutabilidad del laboratorio en la base y en los modelos (contrato H4
 * §A8): 11 disparadores de fila en los dos motores (16 en PostgreSQL, con los
 * de TRUNCATE); una ejecución o un caso terminal no admiten NINGÚN UPDATE, ni
 * con los mismos valores; lo congelado no cambia; nada se borra; veredictos y
 * libro son de solo inserción. Y el patrón del servicio: un UPDATE
 * condicionado que no encuentra la fila no dispara nada.
 */
class UltronStudioLabImmutabilityTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const ROW_TRIGGERS = [
        'uslc_forbid_delete', 'uslc_forbid_immutable_update', 'uslc_forbid_terminal_update',
        'usle_forbid_delete', 'usle_forbid_update',
        'uslr_forbid_delete', 'uslr_forbid_immutable_update', 'uslr_forbid_terminal_update',
        'usls_forbid_delete',
        'uslv_forbid_delete', 'uslv_forbid_update',
    ];

    public function test_estan_los_disparadores_del_laboratorio(): void
    {
        if ($this->isPostgres()) {
            $nombres = array_map(static fn (object $f): string => $f->tgname, DB::select(
                "SELECT t.tgname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_lab_%' ORDER BY 1"));
            $this->assertCount(16, $nombres);
            $this->assertSame(self::ROW_TRIGGERS, array_values(array_filter($nombres, static fn (string $n): bool => ! str_ends_with($n, '_truncate'))));
            $this->assertCount(5, array_filter($nombres, static fn (string $n): bool => str_ends_with($n, '_truncate')));

            return;
        }

        $nombres = DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'usl%')->orderBy('name')->pluck('name')->all();
        $this->assertSame(self::ROW_TRIGGERS, $nombres);
    }

    public function test_una_ejecucion_y_un_caso_terminales_no_admiten_ningun_update(): void
    {
        $run = $this->finishedFakeRun();
        $caso = StudioLabRunCase::query()->where('run_id', $run->id)->firstOrFail();

        foreach ([
            fn () => DB::table('ultron_studio_lab_runs')->where('id', $run->id)->update(['status' => 'running']),
            fn () => DB::table('ultron_studio_lab_runs')->where('id', $run->id)->update(['updated_at' => DB::raw('updated_at')]),
            fn () => DB::table('ultron_studio_lab_runs')->where('id', $run->id)->update(['cost_micros' => 1]),
            fn () => DB::table('ultron_studio_lab_run_cases')->where('id', $caso->id)->update(['status' => 'pending']),
            fn () => DB::table('ultron_studio_lab_run_cases')->where('id', $caso->id)->update(['reply' => DB::raw('reply')]),
        ] as $i => $escribir) {
            $this->assertBlocked($escribir, "escritura {$i}");
        }

        // El patrón del servicio: condicionado al estado vivo, 0 filas y ningún disparador.
        $this->assertFalse(StudioLabRun::advance($run->id, StudioLabRun::LIVE_STATUSES, ['cost_micros' => 1]));
        $this->assertFalse(StudioLabRunCase::advance($caso->id, [StudioLabRunCase::STATUS_RUNNING], ['cost_micros' => 1]));
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_lo_congelado_no_cambia_ni_con_el_mismo_valor(): void
    {
        Queue::fake();
        $this->labFake();
        $this->importLabScenarios();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $uuid = $this->launch($root, ['kind' => 'baseline'], ['C-01'])->assertCreated()->json('data.uuid');
        $run = StudioLabRun::query()->where('uuid', $uuid)->sole();
        $caso = StudioLabRunCase::query()->where('run_id', $run->id)->sole();

        $this->assertBlocked(fn () => DB::table('ultron_studio_lab_runs')->where('id', $run->id)->update(['model' => DB::raw('model')]), 'model');
        $this->assertBlocked(fn () => DB::table('ultron_studio_lab_runs')->where('id', $run->id)->update(['accepted_max_micros' => DB::raw('accepted_max_micros')]), 'accepted_max_micros');
        $this->assertBlocked(fn () => DB::table('ultron_studio_lab_run_cases')->where('id', $caso->id)->update(['reserved_micros' => DB::raw('reserved_micros')]), 'reserved_micros');
        // Y desde el modelo, antes de llegar a la base.
        $this->expectException(LogicException::class);
        $run->forceFill(['model' => 'otro'])->save();
    }

    public function test_nada_se_borra_y_veredicto_y_libro_son_de_solo_insercion(): void
    {
        $run = $this->finishedFakeRun();
        $escenario = StudioLabScenario::query()->firstOrFail();
        $evento = StudioLabEvent::query()->firstOrFail();
        $veredicto = StudioLabVerdict::query()->forceCreate([
            'run_id' => $run->id, 'version_id' => $this->versionId(), 'version_content_hash' => str_repeat('a', 64), 'results_hash' => str_repeat('b', 64),
            'decision' => 'rejected', 'note' => 'Sembrado para la prueba', 'acknowledged_cases' => null,
            'reviewer_admin_id' => 1, 'reviewer_name' => 'Prueba', 'reviewer_role' => null, 'created_at' => now(),
        ]);

        foreach ([
            'escenario' => fn () => $escenario->delete(),
            'ejecución' => fn () => $run->delete(),
            'caso' => fn () => StudioLabRunCase::query()->firstOrFail()->delete(),
            'veredicto' => fn () => $veredicto->delete(),
            'evento' => fn () => $evento->delete(),
            'veredicto (cambio)' => fn () => $veredicto->forceFill(['note' => 'otra'])->save(),
            'evento (cambio)' => fn () => $evento->forceFill(['summary' => 'otro'])->save(),
        ] as $que => $intento) {
            try {
                $intento();
                $this->fail("Eloquent dejó tocar: {$que}");
            } catch (LogicException $e) {
                $this->assertStringStartsWith('ultron_studio_lab:', $e->getMessage(), $que);
            }
        }

        foreach ([
            'ultron_studio_lab_scenarios' => $escenario->id, 'ultron_studio_lab_runs' => $run->id, 'ultron_studio_lab_verdicts' => $veredicto->id, 'ultron_studio_lab_events' => $evento->id,
        ] as $tabla => $id) {
            $this->assertBlocked(fn () => DB::table($tabla)->where('id', $id)->delete(), "DELETE {$tabla}");
        }
        $this->assertBlocked(fn () => DB::table('ultron_studio_lab_verdicts')->where('id', $veredicto->id)->update(['note' => DB::raw('note')]), 'UPDATE veredicto');
        $this->assertBlocked(fn () => DB::table('ultron_studio_lab_events')->where('id', $evento->id)->update(['summary' => DB::raw('summary')]), 'UPDATE libro');
    }

    public function test_solo_en_postgres_los_check_del_laboratorio(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('Los CHECK solo existen en PostgreSQL (SQLite no admite añadirlos a una tabla existente).');
        }
        $checks = array_map(static fn (object $f): string => $f->conname, DB::select(
            "SELECT k.conname FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_lab_%' ORDER BY 1"));
        $this->assertCount(13, $checks);
        $this->assertContains('uslr_target_chk', $checks);

        $run = $this->finishedFakeRun();
        try {
            DB::transaction(fn () => DB::table('ultron_studio_lab_run_cases')->insert([
                'run_id' => $run->id, 'position' => 99, 'scenario_id' => StudioLabScenario::query()->value('id'), 'scenario_code' => 'C-01',
                'scenario_revision' => 1, 'scenario_snapshot' => '{}', 'status' => 'perdido', 'budget_day' => '2026-10-19', 'reserved_micros' => 0,
                'overrun_micros' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->fail('El CHECK del estado del caso no saltó.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
    }

    /** Una ejecución `fake` de un caso, terminada (la cola de la suite es síncrona). */
    private function finishedFakeRun(): StudioLabRun
    {
        $this->labFake();
        $this->importLabScenarios();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $uuid = $this->launch($root, self::versionTarget($this->versionUuid($root)), ['C-01'])->assertCreated()->json('data.uuid');
        $run = StudioLabRun::query()->where('uuid', $uuid)->sole();
        $this->assertSame('completed', $run->status);

        return $run;
    }

    private ?string $version = null;

    private function versionUuid(Admin $as): string
    {
        return $this->version ??= (string) $this->createVersion($as)->assertCreated()->json('data.uuid');
    }

    private function versionId(): int
    {
        return (int) DB::table('ultron_studio_versions')->where('uuid', $this->version)->value('id');
    }

    private function assertBlocked(callable $escribir, string $que): void
    {
        try {
            // En PostgreSQL el error aborta la transacción: va en un punto de guardado.
            DB::transaction(fn () => $escribir());
            $this->fail("La base dejó pasar: {$que}");
        } catch (QueryException $e) {
            $this->assertStringContainsString('ultron_studio_lab:', $e->getMessage(), $que);
        }
    }
}
