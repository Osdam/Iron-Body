<?php

namespace Tests\Feature\Plans;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\ContractTemplate;
use App\Models\ElectronicInvoice;
use App\Models\EmployeeAccess;
use App\Models\MemberContract;
use App\Models\MemberRealtimeEvent;
use App\Models\MembershipSubscription;
use App\Models\NotificationDispatch;
use App\Models\Payment;
use App\Models\PlanTransferBatch;
use App\Models\PlanTransferItem;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Membership\MembershipAccess;
use App\Services\Membership\MembershipAdjustments;
use App\Services\Membership\MembershipFreeze;
use App\Services\Membership\PlanAccessRules;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EJECUTAR UN TRASLADO: una columna por socio y nada más.
 *
 * Lo que se fija aquí, contra la base entera y no contra las columnas que uno
 * espera: que de cada socio solo cambian `plan` (al nombre EXACTO del destino)
 * y `updated_at`; que pagos, facturas, cuentas por cobrar, contratos y
 * accesos de empleado quedan idénticos; que los socios que no se eligieron
 * —del mismo plan o de otros— no se tocan ni en `updated_at`; que todo es o
 * nada; que el doble clic no duplica; que una simulación vieja no se aplica; y
 * que la señal a la app sale después del commit, nunca dentro.
 */
class PlanTransferExecutionTest extends TestCase
{
    use PlanTransferFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararTraslado();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function eventosDeTraslado(): int
    {
        return AuditLog::whereIn('metadata->event', ['plan.transfer.executed', 'membership.plan.transferred'])->count();
    }

    // ── Un socio ────────────────────────────────────────────────────────────

    public function test_un_elegible_solo_cambia_su_plan_al_nombre_exacto(): void
    {
        $socio = $this->socio();
        $antes = $this->fila('users', $socio->id);
        $this->avanzarReloj(5);

        $r = $this->trasladar([$socio->id])->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('batch.kind', 'transfer')
            ->assertJsonPath('batch.status', 'applied')
            ->assertJsonPath('batch.members_count', 1)
            ->assertJsonPath('batch.from_plan.name', 'TOTAL ACCESS - ELITE')
            ->assertJsonPath('batch.to_plan.name', 'Élite')
            ->assertJsonPath('batch.created_by_role', 'Administrador')
            ->assertJsonPath('batch.business_day', '2026-10-15')
            ->assertJsonPath('batch.items.0.before.plan', 'TOTAL ACCESS - ELITE')
            ->assertJsonPath('batch.items.0.after.plan', 'Élite')
            ->assertJsonPath('batch.items.0.current.plan', 'Élite')
            ->assertJsonPath('batch.items.0.current.changed_since', false);

        $despues = $this->fila('users', $socio->id);
        // El nombre EXACTO, con la tilde: en PostgreSQL `Plan::where('name')`
        // no encontraría «Elite».
        $this->assertSame('Élite', $despues['plan']);
        $this->assertNotSame($antes['updated_at'], $despues['updated_at']);
        unset($antes['plan'], $antes['updated_at'], $despues['plan'], $despues['updated_at']);
        $this->assertSame($antes, $despues, 'Cambió algo más que el plan.');

        // La puerta ya lo ve en el plan destino, con las mismas fechas.
        $acceso = app(MembershipAccess::class)->state($socio->refresh());
        $this->assertSame($this->destino->id, $acceso['plan']['id']);
        $this->assertTrue($acceso['can_enter']);
        $this->assertSame(PlanTransferBatch::find($r->json('batch.id'))->fingerprint, $r->json('batch.fingerprint'));
    }

    public function test_veinticinco_elegibles_un_lote_veinticinco_items_veintiseis_trazas(): void
    {
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $ids[] = $this->socio()->id;
        }

        $r = $this->trasladar($ids)->assertCreated()->assertJsonPath('batch.members_count', 25);

        $lote = PlanTransferBatch::findOrFail($r->json('batch.id'));
        $this->assertSame(1, PlanTransferBatch::count());
        $this->assertSame(25, PlanTransferItem::where('batch_id', $lote->id)->count());
        $this->assertSame(26, $this->eventosDeTraslado());
        $this->assertSame(1, AuditLog::where('metadata->event', 'plan.transfer.executed')
            ->where('module', 'Planes')->where('entity', 'traslado de plan')->where('entity_id', (string) $lote->id)->count());
        $this->assertSame(25, User::whereIn('id', $ids)->where('plan', 'Élite')->count());

        $traza = AuditLog::where('metadata->event', 'membership.plan.transferred')->firstOrFail();
        $this->assertSame('Miembros', $traza->module);
        $this->assertSame('membresía', $traza->entity);
        $this->assertSame([['field' => 'plan', 'from' => 'TOTAL ACCESS - ELITE', 'to' => 'Élite']], $traza->changes);
        $this->assertSame($this->destino->id, $traza->metadata['plan_id']);
        $this->assertSame($lote->id, $traza->metadata['batch_id']);

        // La señal silenciosa a la app: membresía y funciones, por socio.
        $this->assertSame(50, MemberRealtimeEvent::count());
        // Y ninguna notificación al socio: ni push, ni mensaje, ni envío.
        $this->assertSame(0, AppNotification::count());
        $this->assertSame(0, NotificationDispatch::count());
    }

    public function test_la_senal_a_la_app_sale_despues_del_commit(): void
    {
        // Dentro de la transacción, la señal anunciaría un cambio que todavía
        // puede deshacerse, y en PostgreSQL un INSERT fallido la abortaría
        // entera. Se mide el nivel de transacción en el momento de emitirla.
        $socio = $this->socio();
        $base = DB::transactionLevel();
        $niveles = [];
        MemberRealtimeEvent::creating(function () use (&$niveles): void {
            $niveles[] = DB::transactionLevel();
        });

        $this->trasladar([$socio->id])->assertCreated();

        $this->assertSame([$base, $base], $niveles);
        $this->assertSame(
            ['membership.updated', 'app_state.updated'],
            MemberRealtimeEvent::orderBy('id')->pluck('type')->all(),
        );
    }

    // ── Quién se traslada y quién no ────────────────────────────────────────

    public function test_si_todos_ya_estan_en_el_destino_no_hay_nada_que_trasladar(): void
    {
        $a = $this->socio($this->destino);
        $b = $this->socio($this->destino);

        $this->trasladar([$a->id, $b->id])->assertStatus(422)->assertJsonPath('code', 'nothing_to_transfer');

        $this->assertSame(0, PlanTransferBatch::count());
        $this->assertSame(0, $this->eventosDeTraslado());
    }

    public function test_el_vencido_se_traslada_solo_si_se_incluye_y_sin_tocar_fechas(): void
    {
        $vencido = $this->socio(null, [
            'membership_start_date' => $this->hoy()->subDays(40)->toDateString(),
            'membership_end_date' => $this->hoy()->subDays(10)->toDateString(),
        ]);
        $otroVencido = $this->socio(null, [
            'membership_start_date' => $this->hoy()->subDays(40)->toDateString(),
            'membership_end_date' => $this->hoy()->subDays(10)->toDateString(),
        ]);
        $fechas = [$vencido->membershipStartDate, $vencido->membershipEndDate];

        $r = $this->trasladar([$vencido->id])->assertCreated();

        $this->assertContains('expired', array_column($r->json('batch.items.0.warnings'), 'code'));
        $vencido->refresh();
        $this->assertSame('Élite', $vencido->plan);
        $this->assertSame($fechas, [$vencido->membershipStartDate, $vencido->membershipEndDate]);
        $this->assertSame('TOTAL ACCESS - ELITE', $otroVencido->refresh()->plan, 'Se trasladó a un vencido que no se eligió.');
    }

    public function test_el_congelado_conserva_su_congelamiento_y_al_reanudar_recibe_sus_dias_con_el_destino(): void
    {
        $socio = $this->socio();
        app(MembershipFreeze::class)->freeze($socio, 15, 'Viaje');
        $socio->refresh();
        $congelamiento = array_intersect_key($this->fila('users', $socio->id), array_flip([
            'membership_frozen_at', 'membership_frozen_until', 'membership_frozen_days_left',
            'membership_status_before_freeze', 'membership_frozen_reason', 'membership_end_date', 'status',
        ]));

        $r = $this->trasladar([$socio->id])->assertCreated();

        $this->assertContains('frozen', array_column($r->json('batch.items.0.warnings'), 'code'));
        $this->assertSame($congelamiento, array_intersect_key($this->fila('users', $socio->id), $congelamiento));

        $estado = app(MembershipFreeze::class)->resume($socio->refresh());
        $socio->refresh();
        $this->assertSame('Élite', $socio->plan);
        $this->assertSame($this->hoy()->addDays(20)->toDateString(), $socio->membershipEndDate);
        $this->assertSame(20, $estado['days_returned']);
        $this->assertSame('active', $socio->status);
    }

    public function test_cuenta_inactiva_se_traslada_sin_activarla(): void
    {
        $socio = $this->socio(null, ['status' => 'inactive']);

        $r = $this->trasladar([$socio->id])->assertCreated();

        $this->assertContains('account_inactive', array_column($r->json('batch.items.0.warnings'), 'code'));
        $this->assertSame('inactive', $socio->refresh()->status);
    }

    public function test_membresia_futura_y_periodo_prepagado_no_mueven_los_periodos_del_pago(): void
    {
        $socio = $this->socio(null, [
            'membership_start_date' => $this->hoy()->addDays(3)->toDateString(),
            'membership_end_date' => $this->hoy()->addDays(33)->toDateString(),
        ], false);
        $pago = Payment::create(['user_id' => $socio->id, 'plan_id' => $this->origen->id, 'amount' => 180000,
            'method' => 'cash', 'status' => 'paid', 'paid_at' => now(), 'starts_on' => $this->hoy()->addDays(3)->toDateString(),
            'period_start' => $this->hoy()->addDays(3)->toDateString(), 'period_end' => $this->hoy()->addDays(33)->toDateString()]);
        $antes = $this->fila('payments', $pago->id);

        $r = $this->trasladar([$socio->id])->assertCreated();

        $codigos = array_column($r->json('batch.items.0.warnings'), 'code');
        $this->assertContains('future_start', $codigos);
        $this->assertContains('prepaid_origin_period', $codigos);
        $this->assertSame($antes, $this->fila('payments', $pago->id));
    }

    public function test_restriccion_horaria_sin_confirmar_advertencias_no_se_ejecuta(): void
    {
        $valle = $this->plan('Horas valle', ['access_windows' => [['from' => '14:00', 'to' => '17:00']]]);
        $socio = $this->socio();

        $this->trasladar([$socio->id], ['confirm_warnings' => false], null, $valle)
            ->assertStatus(422)->assertJsonPath('code', 'warnings_not_confirmed');
        $this->assertSame('TOTAL ACCESS - ELITE', $socio->refresh()->plan);
        $this->assertSame(0, PlanTransferBatch::count());

        $this->trasladar([$socio->id], ['confirm_warnings' => true], null, $valle)->assertCreated();
        $this->assertSame('Horas valle', $socio->refresh()->plan);
        // A las 10:00 ya no entra: la regla del destino aplica desde ya.
        $this->assertSame('hours', app(MembershipAccess::class)->state($socio)['reason']);
    }

    // ── Lo que NO se toca ───────────────────────────────────────────────────

    public function test_pagos_facturas_cuentas_contratos_y_accesos_quedan_identicos(): void
    {
        $socio = $this->socio();
        $miembro = $socio->appMember;
        $pago = Payment::where('user_id', $socio->id)->firstOrFail();

        ElectronicInvoice::create(['uuid' => (string) Str::uuid(), 'source_type' => Payment::class, 'source_id' => $pago->id,
            'type' => 'invoice', 'status' => 'validated', 'total' => 180000, 'customer_name' => $socio->name]);
        $abierta = Receivable::create(['member_id' => $miembro->id, 'debtor_type' => 'member', 'debtor_id' => $miembro->id,
            'type' => 'gym', 'concept' => 'Plan TOTAL ACCESS - ELITE', 'total_amount' => 50000, 'status' => 'pending',
            'due_at' => $this->hoy()->addDays(5)->toDateString()]);
        $plantilla = ContractTemplate::create(['template_key' => 'basic_registration', 'name' => 'Registro', 'version' => '1',
            'source_file_path' => 'contracts/basic.md']);
        MemberContract::create(['contract_uuid' => (string) Str::uuid(), 'member_id' => $miembro->id,
            'contract_template_id' => $plantilla->id, 'contract_type' => 'basic_registration', 'status' => 'signed']);
        $empleado = $this->socio();
        EmployeeAccess::create(['user_id' => $empleado->id, 'position' => 'Entrenador', 'active' => true, 'source' => 'manual']);

        $fotos = $this->fotoExterna();
        Queue::fake();
        $this->avanzarReloj(5);

        $r = $this->trasladar([$socio->id, $empleado->id])->assertCreated();

        $porSocio = collect($r->json('batch.items'))->keyBy('user_id');
        $this->assertContains('open_receivable', array_column($porSocio[$socio->id]['warnings'], 'code'));
        $this->assertContains('employee_access', array_column($porSocio[$empleado->id]['warnings'], 'code'));

        $despues = $this->fotoExterna();
        foreach ($fotos as $tabla => $filas) {
            $this->assertSame($filas, $despues[$tabla], "La tabla {$tabla} cambió con el traslado.");
        }
        Queue::assertNothingPushed();
        $this->assertSame('pending', $abierta->refresh()->status);

        // El empleado sigue entrando por su puerta, ahora con el plan destino.
        $acceso = app(MembershipAccess::class)->state($empleado->refresh());
        $this->assertSame(MembershipAccess::VIA_EMPLOYEE, $acceso['via']);
        $this->assertSame('Élite', $empleado->plan);
    }

    public function test_los_socios_no_elegidos_y_los_de_otros_planes_no_se_tocan_ni_en_updated_at(): void
    {
        $elegido = $this->socio();
        $mismoPlan = [$this->socio(), $this->socio('Total Access - Élite')];
        $otros = [$this->socio($this->destino), $this->socio('Mensual'), $this->socio(null, [], false, false)];
        $intactos = array_map(fn (User $u): int => $u->id, [...$mismoPlan, ...$otros]);
        $foto = DB::table('users')->whereIn('id', $intactos)->orderBy('id')->get()->map(fn ($f): array => (array) $f)->all();
        $this->avanzarReloj(5);

        $this->trasladar([$elegido->id])->assertCreated();

        $this->assertSame($foto, DB::table('users')->whereIn('id', $intactos)->orderBy('id')->get()->map(fn ($f): array => (array) $f)->all());
    }

    // ── Todo o nada ─────────────────────────────────────────────────────────

    public function test_lote_parcialmente_invalido_aplica_los_elegibles_y_anota_los_excluidos(): void
    {
        $buenos = [$this->socio(), $this->socio(), $this->socio()];
        $pendiente = $this->socio();
        Payment::create(['user_id' => $pendiente->id, 'plan_id' => $this->origen->id, 'amount' => 180000,
            'method' => 'transfer', 'status' => 'pending']);
        $suscrito = $this->socio();
        MembershipSubscription::create(['uuid' => (string) Str::uuid(), 'user_id' => $suscrito->id,
            'member_id' => $suscrito->appMember->id, 'plan_id' => $this->origen->id, 'status' => 'active', 'price_snapshot' => 180000]);
        $fotoBloqueados = [$this->fila('users', $pendiente->id), $this->fila('users', $suscrito->id)];
        $ids = [...array_map(fn (User $u): int => $u->id, $buenos), $pendiente->id, $suscrito->id];

        $r = $this->trasladar($ids)->assertCreated()->assertJsonPath('batch.members_count', 3);

        $excluidos = collect($r->json('batch.excluded'))->keyBy('user_id');
        $this->assertCount(2, $excluidos);
        $this->assertSame('pending_origin_payment', $excluidos[$pendiente->id]['code']);
        $this->assertSame('live_subscription_origin', $excluidos[$suscrito->id]['code']);
        $this->assertSame(3, User::whereIn('id', array_map(fn (User $u): int => $u->id, $buenos))->where('plan', 'Élite')->count());
        $this->assertSame($fotoBloqueados, [$this->fila('users', $pendiente->id), $this->fila('users', $suscrito->id)]);
        $this->assertSame(3, PlanTransferItem::count());
    }

    public function test_un_fallo_a_mitad_no_deja_nada(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->socio()->id;
        }
        $simulacion = $this->simular($ids)->assertOk()->json();
        $usuarios = $this->tabla('users');

        // La base se cae al guardar el TERCER socio: los dos primeros ya
        // estaban escritos dentro de la transacción.
        $guardados = 0;
        User::updating(function () use (&$guardados): void {
            if (++$guardados === 3) {
                throw new QueryException('pgsql', 'update "users" ...', [],
                    new \PDOException('SQLSTATE[57014]: canceling statement due to statement timeout'));
            }
        });

        $this->ejecutar($simulacion, $ids)->assertStatus(500);
        User::flushEventListeners();

        $this->assertSame(3, $guardados, 'El fallo no llegó a inyectarse donde se quería.');
        $this->assertSame($usuarios, $this->tabla('users'));
        $this->assertSame(0, PlanTransferBatch::count());
        $this->assertSame(0, PlanTransferItem::count());
        $this->assertSame(0, $this->eventosDeTraslado());
        $this->assertSame(0, MemberRealtimeEvent::count(), 'Se avisó a la app de un cambio que no ocurrió.');
    }

    // ── Doble clic y simulación vieja ───────────────────────────────────────

    public function test_la_misma_operacion_dos_veces_crea_un_solo_lote(): void
    {
        $socio = $this->socio();
        $simulacion = $this->simular([$socio->id])->assertOk()->json();
        $llave = 'op-doble-clic-1';

        $primera = $this->ejecutar($simulacion, [$socio->id], ['client_request_id' => $llave])->assertCreated();
        $segunda = $this->ejecutar($simulacion, [$socio->id], ['client_request_id' => $llave])
            ->assertOk()
            ->assertJsonPath('replayed', true);

        $this->assertSame($primera->json('batch.id'), $segunda->json('batch.id'));
        $this->assertSame(1, PlanTransferBatch::count());
        $this->assertSame(2, $this->eventosDeTraslado());
        $this->assertSame(2, MemberRealtimeEvent::count(), 'El reintento volvió a avisar a la app.');

        // La misma llave para OTRA operación es un conflicto, no un replay.
        $otro = $this->socio();
        $otra = $this->simular([$otro->id])->assertOk()->json();
        $this->ejecutar($otra, [$otro->id], ['client_request_id' => $llave])
            ->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->assertSame('TOTAL ACCESS - ELITE', $otro->refresh()->plan);
        $this->assertSame(1, PlanTransferBatch::count());
    }

    public function test_si_algo_cambia_entre_simular_y_ejecutar_no_se_aplica_y_se_devuelve_la_simulacion_nueva(): void
    {
        $socio = $this->socio();
        $vieja = $this->simular([$socio->id])->assertOk()->json();

        app(MembershipAdjustments::class)->addDays($socio->refresh(), 3, 'Compensación');

        $r = $this->ejecutar($vieja, [$socio->id])->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'simulation_stale');

        $nueva = $r->json('simulation');
        $this->assertNotSame($vieja['fingerprint'], $nueva['fingerprint']);
        $this->assertSame($socio->refresh()->membershipEndDate, $nueva['items'][0]['before']['membership_end_date']);
        $this->assertSame('TOTAL ACCESS - ELITE', $socio->plan);
        $this->assertSame(0, PlanTransferBatch::count());
        $this->assertSame(0, $this->eventosDeTraslado());

        // Con la simulación nueva sí.
        $this->ejecutar($nueva, [$socio->id])->assertCreated();
        $this->assertSame('Élite', $socio->refresh()->plan);
    }

    public function test_una_huella_inventada_no_aplica_nada(): void
    {
        $socio = $this->socio();

        $this->ejecutar(['fingerprint' => str_repeat('a', 64)], [$socio->id])
            ->assertStatus(409)->assertJsonPath('code', 'simulation_stale');
        $this->assertSame('TOTAL ACCESS - ELITE', $socio->refresh()->plan);
    }

    public function test_validacion_del_motivo_y_la_llave(): void
    {
        $socio = $this->socio();
        $simulacion = $this->simular([$socio->id])->json();

        $this->ejecutar($simulacion, [$socio->id], ['reason' => 'ok'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->ejecutar($simulacion, [$socio->id], ['reason' => str_repeat('x', 501)])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->ejecutar($simulacion, [$socio->id], ['client_request_id' => ''])->assertStatus(422)->assertJsonValidationErrors('client_request_id');
        $this->ejecutar($simulacion, [$socio->id], ['fingerprint' => 'no-es-una-huella'])->assertStatus(422)->assertJsonValidationErrors('fingerprint');
        $this->assertSame(0, PlanTransferBatch::count());
    }

    // ── Entradas: lo usado cuenta contra el destino ─────────────────────────

    public function test_a_quince_entradas_quedan_ocho_tras_ejecutar(): void
    {
        $quince = $this->plan('Valera 15', ['access_mode' => PlanAccessRules::MODE_ENTRIES, 'entry_credits' => 15]);
        $socio = $this->socio();
        $this->entradas($socio, 7);

        $this->trasladar([$socio->id], [], null, $quince)->assertCreated();

        $acceso = app(MembershipAccess::class)->state($socio->refresh());
        $this->assertSame(7, $acceso['entries']['used']);
        $this->assertSame(8, $acceso['entries']['left']);
    }

    // ── Orden de los bloqueos ───────────────────────────────────────────────

    public function test_bloquea_primero_a_los_socios_y_despues_los_planes(): void
    {
        // SQLite compila `lockForUpdate()` a nada, así que el bloqueo no se ve
        // ejecutando: se fija en la fuente (y en PostgreSQL, en el SQL real:
        // PlanTransferPostgresTest).
        $fuente = file_get_contents(app_path('Services/Membership/PlanTransfers.php'));
        $socios = strpos($fuente, "User::query()->whereKey(\$ids)->orderBy('id')->lockForUpdate()->get()");
        $planes = strpos($fuente, "Plan::query()->whereKey([\$fromId, \$toId])->orderBy('id')->lockForUpdate()->get()");
        $this->assertNotFalse($socios, 'La ejecución ya no bloquea a los socios en orden de id.');
        $this->assertNotFalse($planes, 'La ejecución ya no bloquea los planes en orden de id.');
        $this->assertLessThan($planes, $socios, 'Los planes se bloquean antes que los socios.');

        // Y el orden real de las lecturas dentro de la transacción.
        $socio = $this->socio();
        $simulacion = $this->simular([$socio->id])->json();
        $sql = [];
        DB::listen(function ($q) use (&$sql): void {
            $sql[] = $q->sql;
        });
        $this->ejecutar($simulacion, [$socio->id])->assertCreated();

        // En SQLite sin «for update»; en PostgreSQL, con él.
        $primeroSocios = $this->indice($sql, '/^select \* from "users" where "users"\."id" in \(.*\) order by "id" asc( for update)?$/');
        $primeroPlanes = $this->indice($sql, '/^select \* from "plans" where "plans"\."id" in \(.*\) order by "id" asc( for update)?$/');
        $this->assertNotNull($primeroSocios);
        $this->assertNotNull($primeroPlanes);
        $this->assertLessThan($primeroPlanes, $primeroSocios);
    }

    /** @param  list<string>  $sql */
    private function indice(array $sql, string $patron): ?int
    {
        foreach ($sql as $i => $s) {
            if (preg_match($patron, $s) === 1) {
                return $i;
            }
        }

        return null;
    }
}
