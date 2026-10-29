<?php

namespace Tests\Feature\Plans;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\DeletedPlanRestorer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Members\MembershipTransferFixtures;
use Tests\TestCase;

/**
 * `plans:restore-deleted`: restaurar como histórico el plan 22 borrado y volver
 * a enlazar SOLO los cobros que su traza de creación demuestra.
 *
 * El escenario tiene, además de los cobros del plan 22 (la socia a plazos con
 * su deuda y sus abonos, otro socio y un cobro anulado), huérfanos que NO son
 * suyos y no se pueden tocar: un `MIGR-*` sin traza, uno cuya traza no trae
 * plan, uno sin traza y uno de otro plan borrado (el 23).
 */
class RestoreDeletedPlanCommandTest extends TestCase
{
    use DeletedPlanFixtures;
    use MembershipTransferFixtures;
    use RefreshDatabase;

    private User $yurany;

    private Payment $aPlazos;

    private Payment $deOtroSocio;

    private Payment $anulado;

    private Plan $plan23;

    /** @var list<int> */
    private array $intocables = [];

    /** La fila del plan 22 justo antes del borrado. */
    private array $filaAntesDelBorrado = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->planesDelCaso();
        $this->prepararTraspasos();
        $plan23 = $this->plan23 = Plan::create(['name' => 'Plan 23 borrado', 'price' => 50000, 'duration_days' => 30, 'active' => true]);

        $this->yurany = $this->socio('Yurany Pabón');
        $this->aPlazos = $this->cobroAPlazos($this->yurany);
        $otro = $this->socio('Otro Socio Total');
        $this->deOtroSocio = $this->cobrar($otro, $this->totalAccess);
        $anulada = $this->socio('Socia Anulada');
        $this->anulado = $this->cobrar($anulada, $this->totalAccess);
        $this->patchJson("/api/payments/{$this->anulado->id}", ['status' => 'cancelled', 'reason' => 'Se cobró dos veces'], $this->h())
            ->assertSuccessful();
        $this->assertSame('cancelled', $this->anulado->fresh()->status);
        $delPlan23 = $this->cobrar($this->socio('Socio del 23'), $plan23);

        // Huérfanos ajenos: importado, traza sin plan, sin traza.
        $sinPlan = fn (string $ref) => Payment::create([
            'user_id' => $otro->id, 'plan_id' => null, 'amount' => 70000, 'method' => 'cash', 'status' => 'paid',
            'reference' => $ref, 'paid_at' => now(),
        ]);
        $migr = $sinPlan('MIGR-000123');
        $conTrazaSinPlan = $sinPlan('AJUSTE-1');
        AuditLog::create([
            'action' => 'create', 'module' => 'Pagos', 'entity' => 'pago', 'entity_id' => (string) $conTrazaSinPlan->id,
            'summary' => 'Registró un cobro de membresía', 'metadata' => ['plan_id' => null, 'amount' => '70000'],
            'actor_id' => (string) $this->super->id, 'actor_name' => $this->super->name, 'created_at' => now(),
        ]);
        $sinTraza = $sinPlan('SIN-TRAZA-1');

        $this->trasladoDePlan([$this->yurany->id, $otro->id]);
        $this->assertSame('Élite', $this->yurany->fresh()->plan);

        $this->filaAntesDelBorrado = (array) DB::table('plans')->where('id', 22)->first();
        $this->borrarPlanComoEl16DeOctubre($this->totalAccess);
        $this->borrarPlanComoEl16DeOctubre($plan23, '2026-10-16 13:10');
        $this->intocables = [$migr->id, $conTrazaSinPlan->id, $sinTraza->id, $delPlan23->id];

        $this->assertSame(7, Payment::whereNull('plan_id')->count(), 'Tres del 22, uno del 23 y tres ajenos.');
        $this->reloj('2026-10-28 10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── En seco ────────────────────────────────────────────────────────────

    public function test_en_seco_informa_los_cobros_demostrados_y_no_deja_nada_escrito(): void
    {
        $antes = $this->baseEntera();

        [$codigo, $salida] = $this->restaurarPlan();

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('EN SECO (ROLLBACK)', $salida);
        $this->assertStringContainsString('3 cobro(s) se enlazarían con el plan #22', $salida);
        foreach ([$this->aPlazos, $this->deOtroSocio, $this->anulado] as $p) {
            $this->assertStringContainsString('#'.$p->id, $salida);
        }
        $this->assertStringContainsString('Yurany Pabón', $salida);
        $this->assertStringContainsString('1 INSERT plans, 1 UPDATE payments (3 filas), 4 INSERT audit_logs. Ninguna otra.', $salida);
        $this->assertStringContainsString('1 MIGR-*, 1 sin traza de creación, 1 con traza sin plan_id, 1 de otro plan', $salida);
        $this->assertSame($antes, $this->baseEntera(), 'En seco escribió algo.');
        $this->assertNull(Plan::find(22));
    }

    public function test_el_informe_json_trae_cada_cobro_con_socio_importe_estado_y_vigencia(): void
    {
        [$codigo, $salida] = $this->restaurarPlan(['--json' => true]);

        $this->assertSame(0, $codigo, $salida);
        $r = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('dry-run', $r['mode']);
        $this->assertSame('restored', $r['outcome']);
        $this->assertSame([$this->aPlazos->id, $this->deOtroSocio->id, $this->anulado->id], array_column($r['payments'], 'id'));
        $fila = $r['payments'][0];
        $this->assertSame($this->yurany->id, $fila['user_id']);
        $this->assertSame('Yurany Pabón', $fila['user_name']);
        $this->assertSame('receivable', $fila['method']);
        $this->assertSame('paid', $fila['status']);
        $this->assertSame(180000.0, (float) $fila['amount']);
        $this->assertSame(['2026-10-19', '2026-11-18'], [$fila['period_start'], $fila['period_end']]);
        $this->assertSame('cancelled', $r['payments'][2]['status']);
        $this->assertSame([['payment_id' => $this->intocables[3], 'declared' => [$this->plan23->id]]], $r['excluded']['other_plan']);
        $this->assertSame([], $r['excluded']['ambiguous']);
        // Traslados, traspasos y la membresía de cada socio: comparados enteros.
        foreach (['users', 'plan_transfer_batches', 'plan_transfer_items', 'membership_transfers', 'receivables', 'electronic_invoices'] as $t) {
            $this->assertContains($t, $r['checks']['guarded_tables']);
        }
        $this->assertSame('Plan eliminado el 16-10-2026 y restaurado como histórico para conservar la trazabilidad de sus cobros',
            $r['plan']['archive_reason']);
        $this->assertSame([1, 1, 1], [$r['excluded']['migrated'], $r['excluded']['no_creation_audit'], $r['excluded']['audit_without_plan']]);
    }

    // ── Confirmado ─────────────────────────────────────────────────────────

    public function test_commit_restaura_el_plan_archivado_y_enlaza_solo_los_cobros_demostrados(): void
    {
        $intocable = $this->fotoIntocableSinPlanId([$this->aPlazos->id, $this->deOtroSocio->id, $this->anulado->id]);
        $usuarios = $this->tabla('users');
        $sha = hash_file('sha256', $ruta = $this->snapshotFile());
        $trazas = AuditLog::count();

        [$codigo, $salida] = $this->restaurarPlan(['--snapshot' => $ruta, '--commit' => true]);

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('CONFIRMADO (COMMIT)', $salida);

        // El plan: los valores del snapshot, el mismo id, archivado.
        $fila = (array) DB::table('plans')->where('id', 22)->first();
        $this->assertNotEmpty($fila);
        foreach ($this->filaAntesDelBorrado as $columna => $valor) {
            if (in_array($columna, ['active', 'sellable', 'visible_in_app', 'updated_at', 'archived_at', 'archived_by', 'archive_reason'], true)) {
                continue;
            }
            $this->assertEquals($valor, $fila[$columna], "Columna {$columna} distinta de la que se borró.");
        }
        $plan = Plan::findOrFail(22);
        $this->assertFalse($plan->active);
        $this->assertFalse($plan->sellable);
        $this->assertFalse($plan->visible_in_app);
        $this->assertFalse($plan->isSellable());
        $this->assertFalse($plan->isVisibleInApp());
        // Archivado, con el motivo que pidió el propietario y firmado por quien ejecuta.
        $this->assertTrue($plan->isArchived());
        $this->assertSame((int) $this->super->id, $plan->archived_by);
        $this->assertSame('Plan eliminado el 16-10-2026 y restaurado como histórico para conservar la trazabilidad de sus cobros',
            $plan->archive_reason);

        // Los tres demostrados, enlazados; los ajenos, intactos.
        foreach ([$this->aPlazos, $this->deOtroSocio, $this->anulado] as $p) {
            $this->assertSame(22, (int) $p->fresh()->plan_id);
        }
        foreach ($this->intocables as $id) {
            $this->assertNull(Payment::findOrFail($id)->plan_id, "El cobro #{$id} no es del plan 22.");
        }

        // Importes, estados, fechas, deudas, abonos, facturas y socios: iguales.
        $this->assertSame($intocable, $this->fotoIntocableSinPlanId([$this->aPlazos->id, $this->deOtroSocio->id, $this->anulado->id]));
        $this->assertSame($usuarios, $this->tabla('users'));
        $this->assertSame('cancelled', $this->anulado->fresh()->status);

        // La traza: una del plan y una por cobro, con el SHA-256 del snapshot.
        $this->assertSame($trazas + 4, AuditLog::count());
        $delPlan = AuditLog::where('entity', 'plan')->where('entity_id', '22')->where('action', 'create')->sole();
        $this->assertSame(DeletedPlanRestorer::EVENT_PLAN, $delPlan->metadata['event']);
        $this->assertSame($sha, $delPlan->metadata['snapshot']['sha256']);
        $this->assertSame($ruta, $delPlan->metadata['snapshot']['file']);
        $this->assertSame((string) $this->super->id, $delPlan->actor_id);
        $this->assertSame([$this->aPlazos->id, $this->deOtroSocio->id, $this->anulado->id], $delPlan->metadata['relinked_payment_ids']);
        $this->assertNotEmpty($delPlan->metadata['reason']);
        $deCobros = AuditLog::where('entity', 'pago')->where('action', 'update')
            ->whereIn('entity_id', [(string) $this->aPlazos->id, (string) $this->deOtroSocio->id, (string) $this->anulado->id])->get();
        $this->assertCount(3, $deCobros);
        foreach ($deCobros as $t) {
            $this->assertSame([['field' => 'plan_id', 'from' => null, 'to' => '22']], $t->changes);
            $this->assertSame(DeletedPlanRestorer::EVENT_PAYMENT, $t->metadata['event']);
            $this->assertSame($sha, $t->metadata['snapshot_sha256']);
            $this->assertNotEmpty($t->metadata['creation_audit_ids']);
        }
    }

    public function test_segunda_ejecucion_no_hace_nada(): void
    {
        $this->assertSame(0, $this->restaurarPlan(['--commit' => true])[0]);
        $antes = $this->baseEntera();

        [$codigo, $salida] = $this->restaurarPlan(['--commit' => true, '--json' => true]);

        $this->assertSame(0, $codigo, $salida);
        $r = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('noop', $r['outcome']);
        $this->assertSame([], $r['payments']);
        $this->assertSame([], $r['writes']['statements']);
        $this->assertSame($antes, $this->baseEntera());
    }

    public function test_el_cambio_se_ve_en_el_historial_del_socio_como_plan_archivado(): void
    {
        $this->getJson("/api/admin/users/{$this->yurany->id}/payment-history", $this->h())->assertOk()
            ->assertJsonPath('payments.0.id', $this->aPlazos->id)
            ->assertJsonPath('payments.0.plan', null);

        $this->restaurarPlan(['--commit' => true]);

        $this->getJson("/api/admin/users/{$this->yurany->id}/payment-history", $this->h())->assertOk()
            ->assertJsonPath('payments.0.plan.id', 22)
            ->assertJsonPath('payments.0.plan.name', 'TOTAL ACCESS - ELITE')
            ->assertJsonPath('payments.0.plan.active', false);
        $this->getJson("/api/payments/{$this->aPlazos->id}", $this->h())->assertOk()
            ->assertJsonPath('plan.active', false);
    }

    // ── Precondiciones que abortan sin escribir ────────────────────────────

    public function test_snapshot_con_otro_nombre_aborta(): void
    {
        $this->assertAborta('deletion_audit_mismatch', ['--snapshot' => $this->snapshotFile(['name' => 'OTRO PLAN'] + $this->snapshotRow)]);
    }

    public function test_snapshot_de_otro_id_aborta(): void
    {
        $this->assertAborta('snapshot_id_mismatch', ['--snapshot' => $this->snapshotFile(['id' => 23] + $this->snapshotRow)]);
    }

    public function test_sin_auditoria_de_borrado_aborta(): void
    {
        AuditLog::where('action', 'delete')->where('entity', 'plan')->where('entity_id', '22')->delete();

        $this->assertAborta('no_deletion_audit');
    }

    public function test_un_plan_con_ese_id_y_otro_nombre_ya_existente_aborta(): void
    {
        DB::table('plans')->insert(['id' => 22, 'name' => 'Otro plan con el 22', 'price' => 1, 'duration_days' => 30,
            'active' => false, 'sellable' => false, 'visible_in_app' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertAborta('plan_exists');
    }

    public function test_el_mismo_plan_activo_ya_existente_aborta(): void
    {
        DB::table('plans')->insert(['id' => 22, 'name' => 'TOTAL ACCESS - ELITE', 'price' => 1, 'duration_days' => 30,
            'active' => true, 'sellable' => false, 'visible_in_app' => false, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertAborta('plan_exists');
    }

    public function test_sha256_esperado_distinto_aborta(): void
    {
        $this->assertAborta('snapshot_sha_mismatch', ['--sha256' => str_repeat('0', 64)]);
    }

    public function test_actor_que_no_es_un_administrador_activo_aborta(): void
    {
        $this->assertAborta('invalid_actor', ['--actor' => 999999]);
    }

    public function test_snapshot_con_columnas_que_plans_ya_no_tiene_aborta(): void
    {
        $this->assertAborta('snapshot_columns_mismatch', ['--snapshot' => $this->snapshotFile($this->snapshotRow + ['columna_fantasma' => 1])]);
    }

    // ── Las redes: escrituras y datos vigilados ────────────────────────────

    public function test_una_escritura_no_prevista_deshace_todo(): void
    {
        $antes = $this->baseEntera();
        DB::listen(function (QueryExecuted $q): void {
            static $una = false;
            if (! $una && str_starts_with(strtolower($q->sql), 'update "payments"')) {
                $una = true;
                DB::table('cash_shifts')->where('id', -1)->update(['updated_at' => now()]);
            }
        });

        $this->assertAborta('unexpected_writes', ['--commit' => true], $antes);
    }

    public function test_un_cambio_en_una_tabla_vigilada_deshace_todo(): void
    {
        // Un disparador que tocara deudas al enlazar: la foto lo detecta.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION test_toca_deudas() RETURNS trigger AS \$\$ BEGIN UPDATE receivables SET notes = 'tocada'; RETURN NEW; END \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER test_toca_deudas AFTER UPDATE OF plan_id ON payments FOR EACH ROW EXECUTE FUNCTION test_toca_deudas();");
        } else {
            DB::unprepared("CREATE TRIGGER test_toca_deudas AFTER UPDATE OF plan_id ON payments BEGIN UPDATE receivables SET notes = 'tocada'; END;");
        }
        $antes = $this->baseEntera();

        $this->assertAborta('guarded_data_changed', ['--commit' => true], $antes);
    }

    public function test_la_secuencia_de_plans_se_informa_y_no_se_toca(): void
    {
        $pg = DB::connection()->getDriverName() === 'pgsql';
        if ($pg) {
            // El caso que hay que decir: la secuencia por debajo del id restaurado.
            DB::select("SELECT setval('plans_id_seq', 3)");
        }
        $antes = $this->secuencia();

        [, $salida] = $this->restaurarPlan(['--commit' => true, '--json' => true]);

        $r = json_decode($salida, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(DB::connection()->getDriverName(), $r['sequence']['driver']);
        $this->assertSame($antes, $this->secuencia(), 'La secuencia se movió.');
        if ($pg) {
            $this->assertSame(3, $r['sequence']['last_value']);
            $this->assertTrue($r['sequence']['below_restored_id']);
            [, $texto] = $this->restaurarPlan();
            $this->assertStringContainsString('por debajo del #22. NO se toca', $texto);
        } else {
            // SQLite (AUTOINCREMENT) la lleva siempre al mayor id insertado.
            $this->assertFalse($r['sequence']['below_restored_id']);
        }
        // Los dos trasladados ya son «Élite»; la del cobro anulado conserva el
        // texto (anular no restaura el plan) y desde ahora resuelve al archivado.
        $this->assertSame(1, $r['users_with_plan_name']);
        $this->assertSame('TOTAL ACCESS - ELITE', User::whereKey($this->anulado->user_id)->value('plan'));
        $this->assertSame(1, $r['transfer_batches_without_plan_id']);
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $opciones
     * @param  array<string, mixed>|null  $antes
     */
    private function assertAborta(string $codigo, array $opciones = [], ?array $antes = null): void
    {
        $antes ??= $this->baseEntera();

        [$salida, $texto] = $this->restaurarPlan($opciones + ['--commit' => true]);

        $this->assertSame(1, $salida, $texto);
        $this->assertStringContainsString("[{$codigo}]", $texto);
        $this->assertSame($antes, $this->baseEntera(), 'Abortó pero dejó algo escrito.');
    }

    /** Todas las tablas que el comando podría tocar, enteras. */
    private function baseEntera(): array
    {
        $tablas = ['plans', 'payments', 'audit_logs', 'receivables', 'receivable_payments', 'electronic_invoices',
            'users', 'members', 'cash_shifts', 'plan_transfer_batches', 'plan_transfer_items', 'membership_transfers'];

        return array_combine($tablas, array_map(fn (string $t): array => $this->tabla($t), $tablas));
    }

    /**
     * Dinero y documentos, con el `plan_id` de los cobros enlazados leído como
     * nulo: es lo único que el comando puede cambiar en ellos.
     *
     * @param  list<int>  $enlazados
     */
    private function fotoIntocableSinPlanId(array $enlazados): array
    {
        $foto = [];
        foreach (['payments', 'payment_splits', 'receivables', 'receivable_payments', 'electronic_invoices',
            'receipts', 'receipt_sources', 'cash_refunds', 'cash_shifts', 'members', 'membership_adjustments',
            'plan_transfer_items', 'plan_transfer_batches', 'membership_transfers'] as $t) {
            $foto[$t] = array_map(function (array $fila) use ($t, $enlazados): array {
                if ($t === 'payments' && in_array((int) $fila['id'], $enlazados, true)) {
                    $fila['plan_id'] = null;
                }

                return $fila;
            }, $this->tabla($t));
        }

        return $foto;
    }

    private function secuencia(): ?int
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return (int) DB::selectOne('SELECT last_value FROM plans_id_seq')->last_value;
        }
        $seq = DB::table('sqlite_sequence')->where('name', 'plans')->value('seq');

        return $seq === null ? null : (int) $seq;
    }
}
