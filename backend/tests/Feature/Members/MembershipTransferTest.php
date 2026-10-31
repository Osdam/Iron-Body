<?php

namespace Tests\Feature\Members;

use App\Models\Admin;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MemberBiometric;
use App\Models\MemberRealtimeEvent;
use App\Models\MembershipTransfer;
use App\Models\MyClass;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TRANSFERIR MEMBRESÍA: qué cambia y qué no (docs/ops/TRANSFERENCIA-DE-MEMBRESIAS.md §1).
 *
 * Lo que se fija aquí, contra la base y contra lo que leen la puerta, la app y
 * el torniquete local: A termina ayer, B recibe el plan EXACTO hasta el fin de
 * A, ni un cobro nuevo, la traza en las dos fichas, las señales después del
 * commit, el doble clic, la reversión y la renovación posterior de B.
 */
class MembershipTransferTest extends TestCase
{
    use MembershipTransferFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararTraspasos();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1 · La anualidad transferida ────────────────────────────────────────

    public function test_anualidad_transferida_a_termina_ayer_y_b_recibe_el_plan_exacto_hasta_el_mismo_fin(): void
    {
        [$a, $cobro] = $this->titularConAnualidad();
        $b = $this->socio('Beatriz Beneficiaria');
        $fin = $cobro->period_end->format('Y-m-d');
        $hoy = $this->hoy();
        $dias = (int) $hoy->diffInDays(CarbonImmutable::parse($fin, 'America/Bogota')) + 1;
        $this->assertSame('2027-09-01', $fin);
        $this->assertSame('2026-09-01', $a->membershipStartDate);

        $r = $this->transferir($a, $b)->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('idempotent_replay', false)
            ->assertJsonPath('transfer.status', 'active')
            ->assertJsonPath('transfer.source_payment_id', $cobro->id)
            ->assertJsonPath('transfer.plan.name', 'Anualidad Élite')
            ->assertJsonPath('transfer.effective_date', '2026-10-15')
            ->assertJsonPath('transfer.end_date', $fin)
            ->assertJsonPath('transfer.days', $dias)
            ->assertJsonPath('transfer.holder_before.end', $fin)
            ->assertJsonPath('transfer.beneficiary_before.end', null)
            ->assertJsonPath('holder.membershipEndDate', '2026-10-14')
            ->assertJsonPath('beneficiary.membershipEndDate', $fin);

        $a->refresh();
        $b->refresh();
        // A: fin = ayer; inicio, plan y estado intactos.
        $this->assertSame('2026-10-14', $a->membershipEndDate);
        $this->assertSame('2026-09-01', $a->membershipStartDate);
        $this->assertSame('Anualidad Élite', $a->plan);
        $this->assertSame('active', $a->status);
        // B: el nombre EXACTO del plan del cobro, de hoy a E, activo.
        $this->assertSame('Anualidad Élite', $b->plan);
        $this->assertSame('2026-10-15', $b->membershipStartDate);
        $this->assertSame($fin, $b->membershipEndDate);
        $this->assertSame('active', $b->status);
        $this->assertSame(322, $dias, 'Del 15-10-2026 al 01-09-2027, los dos incluidos.');

        $fila = MembershipTransfer::findOrFail($r->json('transfer.id'));
        $this->assertSame((int) $this->super->id, $fila->created_by);
        $this->assertSame('Formato firmado n.º 123', $fila->evidence);
        $this->assertNotNull($fila->holder_consent_at);
        $this->assertNotNull($fila->beneficiary_acceptance_at);
    }

    // ── 2 · Con y sin días consumidos ──────────────────────────────────────

    public function test_sin_dias_consumidos_a_queda_en_ayer_y_b_recibe_el_periodo_entero(): void
    {
        $this->diaDelTraspaso();
        $a = $this->socio('Ana Hoy');
        $cobro = $this->cobrar($a, $this->anualidad);
        $b = $this->socio('Beto Hoy');
        $this->assertSame('2026-10-15', $cobro->period_start->format('Y-m-d'));

        $this->transferir($a, $b)->assertCreated()->assertJsonPath('transfer.days', 366);

        // Empezaba hoy: el inicio pasa a ayer para no quedar después del fin.
        $this->assertSame(['2026-10-14', '2026-10-14'], [$a->fresh()->membershipStartDate, $a->fresh()->membershipEndDate]);
        $this->assertSame(['2026-10-15', '2027-10-15'], [$b->fresh()->membershipStartDate, $b->fresh()->membershipEndDate]);
    }

    public function test_con_dias_consumidos_solo_se_cede_lo_que_queda(): void
    {
        $a = $this->socio('Ana Mensual');
        $this->cobrar($a, $this->mensual);
        $this->reloj('2026-09-21 18:00');
        $b = $this->socio('Beto Mensual');

        // 1-09 → 01-10: le quedan del 21-09 al 01-10, once días.
        $this->transferir($a, $b)->assertCreated()->assertJsonPath('transfer.days', 11);
        $this->assertSame(['2026-09-21', '2026-10-01'], [$b->fresh()->membershipStartDate, $b->fresh()->membershipEndDate]);
        $this->assertSame('2026-09-20', $a->fresh()->membershipEndDate);
    }

    // ── 3 · El dinero y los documentos no se tocan ─────────────────────────

    public function test_cobros_facturas_abonos_y_recibos_de_a_intactos_y_cero_cobros_nuevos(): void
    {
        // Todo por los endpoints reales: un mensual facturado, y encima una
        // anualidad a plazos ya SALDADA (cobro de devengo, deuda, dos abonos),
        // con sus recibos de caja.
        $a = $this->socio('Ana Plazos');
        $mensual = $this->cobrar($a, $this->mensual, ['request_invoice' => true, 'invoice_email' => 'ana.plazos@gmail.com']);
        $this->reloj('2026-09-01 09:30');
        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $a->id, 'plan_id' => $this->anualidad->id, 'first_payment' => 100000, 'method' => 'transfer',
        ], $this->h())->assertStatus(201);
        $deuda = Receivable::findOrFail($r->json('data.id'));
        $abono = $this->postJson("/api/admin/receivables/{$deuda->id}/payments", ['amount' => 524000, 'method' => 'cash'], $this->h())
            ->assertStatus(201)->json('data.id');
        $this->assertSame(Receivable::STATUS_PAID, $deuda->fresh()->status);
        $this->postJson('/api/admin/receipts', ['source_type' => 'payment', 'source_id' => $mensual->id], $this->h())->assertSuccessful();
        $this->postJson('/api/admin/receipts', ['source_type' => 'receivable_payment', 'source_ids' => [$r->json('first_payment.id'), $abono]], $this->h())
            ->assertSuccessful();
        $this->diaDelTraspaso();
        $b = $this->socio('Beto Plazos');

        $antes = $this->fotoIntocable();
        $this->assertCount(2, $antes['payments']);
        $this->assertCount(1, $antes['electronic_invoices'], 'la factura del mensual');
        $this->assertCount(2, $antes['receivable_payments']);
        $this->assertGreaterThanOrEqual(2, count($antes['receipts']), 'los recibos del mensual y de los abonos');

        // El periodo cedido es el de la anualidad, encadenada tras el mensual.
        $this->transferir($a, $b)->assertCreated()
            ->assertJsonPath('transfer.source_payment_id', (int) $r->json('payment_id'))
            ->assertJsonPath('transfer.end_date', '2027-10-01');

        $this->assertSame($antes, $this->fotoIntocable(), 'El traspaso tocó dinero, documentos, asistencia o rostros.');
        $this->assertSame(0, Payment::where('user_id', $b->id)->count(), 'B no tiene cobro: la vigencia no es suya por pago.');
        $this->assertSame((int) $a->id, (int) Payment::findOrFail($r->json('payment_id'))->user_id, 'El cobro sigue siendo de A.');
    }

    // ── 4 · B nuevo y B existente sin vigencia ─────────────────────────────

    public function test_b_recien_creado_desde_el_crm_recibe_la_membresia(): void
    {
        [$a] = $this->titularConAnualidad();
        $nuevo = $this->postJson('/api/users', [
            'fullName' => 'Bruno Nuevo', 'document' => '1077000111', 'phone' => '3001112233', 'birthDate' => '1995-04-10',
        ], $this->h())->assertCreated()->json('id');
        $b = User::findOrFail($nuevo);

        $previa = $this->previa($a, $b)->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('beneficiary.has_member_profile', true)
            ->assertJsonPath('beneficiary.has_face', false);
        $this->assertContains('beneficiary_no_face', $this->codigos($previa, 'warnings'));

        $this->transferir($a, $b)->assertCreated();
        $this->assertSame('Anualidad Élite', $b->fresh()->plan);
        // Aparece en la lista de rostros pendientes, que es como registra su cara.
        $pendientes = collect($this->getJson('/api/attendances/face-enrollment/pending', $this->h())->assertOk()->json('data'))
            ->pluck('user_id')->all();
        $this->assertContains($b->id, $pendientes);
    }

    public function test_b_existente_con_membresia_vencida_y_rostro_recibe_la_membresia_y_conserva_su_rostro(): void
    {
        $b = $this->socio('Berta Vencida');
        $this->cobrar($b, $this->mensual); // 01-09 → 01-10: vencida el 15-10
        $miembroB = Member::where('user_id', $b->id)->firstOrFail();
        MemberBiometric::create(['member_id' => $miembroB->id, 'face_path' => 'faces/berta.jpg', 'captured_at' => now()]);
        [$a] = $this->titularConAnualidad();
        $b->refresh();
        $this->assertSame('2026-10-01', $b->membershipEndDate);

        $previa = $this->previa($a, $b)->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('beneficiary.has_face', true);
        $this->assertNotContains('beneficiary_no_face', $this->codigos($previa, 'warnings'));

        $r = $this->transferir($a, $b)->assertCreated()
            ->assertJsonPath('transfer.beneficiary_before.plan', 'Mensual')
            ->assertJsonPath('transfer.beneficiary_before.end', '2026-10-01');
        $this->assertSame(['Anualidad Élite', '2026-10-15', '2027-09-01'], [$b->fresh()->plan, $b->fresh()->membershipStartDate, $b->fresh()->membershipEndDate]);
        $this->assertSame(1, MemberBiometric::where('member_id', $miembroB->id)->count(), 'El rostro de B no se toca.');
        $this->assertSame(0, MemberBiometric::whereHas('member', fn ($q) => $q->where('user_id', $a->id))->count(), 'Nunca se copia un rostro.');
        $this->assertNotNull($r->json('transfer.id'));
    }

    // ── 6 · Idempotencia ───────────────────────────────────────────────────

    public function test_repetir_con_el_mismo_client_request_id_devuelve_el_mismo_traspaso(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Doble');
        $llave = 'doble-clic-1';

        $primero = $this->transferir($a, $b, ['client_request_id' => $llave])->assertCreated();
        $segundo = $this->transferir($a, $b, ['client_request_id' => $llave])->assertOk()
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('transfer.id', $primero->json('transfer.id'));

        $this->assertSame(1, MembershipTransfer::count());
        $this->assertSame(2, AuditLog::where('metadata->event', 'membership.transferred')->count(), 'Sin trazas de más.');
        $this->assertSame($primero->json('transfer'), $segundo->json('transfer'));

        // La misma llave para OTRO beneficiario es otra operación: conflicto.
        $c = $this->socio('Carla Otra');
        $this->transferir($a, $c, ['client_request_id' => $llave])->assertStatus(422)->assertJsonPath('code', 'client_request_id_conflict');
        $this->assertNull($c->fresh()->plan);
    }

    public function test_dos_traspasos_del_mismo_cobro_a_beneficiarios_distintos_gana_uno(): void
    {
        [$a, $cobro] = $this->titularConAnualidad();
        $b = $this->socio('Beto Primero');
        $c = $this->socio('Carla Segunda');

        $this->transferir($a, $b)->assertCreated();
        $r = $this->transferir($a, $c)->assertStatus(409)->assertJsonPath('code', 'transfer_blocked');

        // Bajo bloqueo, A ya no tiene vigencia; y el cobro, ya cedido.
        $this->assertContains('holder_expired', $this->codigos($r));
        $this->assertSame(1, MembershipTransfer::where('source_payment_id', $cobro->id)->count());
        $this->assertNull($c->fresh()->plan);
        $this->assertNull($c->fresh()->membershipEndDate);
    }

    public function test_el_indice_parcial_impide_dos_traspasos_vivos_del_mismo_cobro_y_libera_al_revertir(): void
    {
        [$a, $cobro] = $this->titularConAnualidad();
        $b = $this->socio('Beto Índice');
        $id = $this->transferir($a, $b)->assertCreated()->json('transfer.id');
        $fila = collect(MembershipTransfer::findOrFail($id)->getAttributes())->except(['id', 'client_request_id', 'created_at', 'updated_at'])->all();

        // La base, sin pasar por el servicio: un segundo vivo del mismo cobro
        // choca. En un punto de guardado: en PostgreSQL la violación abortaría
        // la transacción de la prueba entera.
        try {
            DB::transaction(fn () => DB::table('membership_transfers')->insert($fila + ['client_request_id' => 'otro-vivo']));
            $this->fail('El índice único parcial no paró un segundo traspaso vivo del mismo cobro.');
        } catch (UniqueConstraintViolationException) {
            // lo esperado
        }

        // Revertido, deja de contar: cabe otro vivo del mismo cobro.
        DB::table('membership_transfers')->where('id', $id)->update(['reverted_at' => now()]);
        DB::table('membership_transfers')->insert($fila + ['client_request_id' => 'nuevo-vivo']);
        $this->assertSame(2, DB::table('membership_transfers')->where('source_payment_id', $cobro->id)->count());
    }

    // ── 8 · Auditoría de los dos ───────────────────────────────────────────

    public function test_una_traza_por_socio_con_todo_lo_que_pide_el_contrato_y_visible_en_la_ficha(): void
    {
        [$a, $cobro] = $this->titularConAnualidad();
        $b = $this->socio('Beto Traza');
        $id = $this->transferir($a, $b)->assertCreated()->json('transfer.id');

        $trazas = AuditLog::where('metadata->event', 'membership.transferred')->orderBy('id')->get();
        $this->assertCount(2, $trazas);
        [$deA, $deB] = [$trazas[0], $trazas[1]];
        $this->assertSame([(string) $a->id, (string) $b->id], [$deA->entity_id, $deB->entity_id]);
        foreach ($trazas as $t) {
            $this->assertSame('Miembros', $t->module);
            $this->assertSame('membresía', $t->entity);
            $this->assertSame((string) $this->super->id, $t->actor_id);
            $this->assertSame('Fredy Supervisión', $t->actor_name);
            $this->assertSame($id, $t->metadata['transfer_id']);
            $this->assertSame((int) $a->id, $t->metadata['holder_user_id']);
            $this->assertSame((int) $b->id, $t->metadata['beneficiary_user_id']);
            $this->assertSame((int) $cobro->id, $t->metadata['payment_id']);
            $this->assertSame((int) $this->anualidad->id, $t->metadata['plan_id']);
            $this->assertSame('Se muda de ciudad y cede su anualidad a su hermana', $t->metadata['reason']);
            $this->assertSame('Formato firmado n.º 123', $t->metadata['evidence']);
        }
        $this->assertSame('holder', $deA->metadata['role']);
        $this->assertSame(['plan' => 'Anualidad Élite', 'start' => '2026-09-01', 'end' => '2027-09-01', 'status' => 'active', 'frozen' => false], $deA->metadata['before']);
        $this->assertSame('2026-10-14', $deA->metadata['after']['end']);
        $this->assertSame([['field' => 'membership_end_date', 'from' => '2027-09-01', 'to' => '2026-10-14']], $deA->changes);
        $this->assertSame('beneficiary', $deB->metadata['role']);
        $this->assertSame(['plan' => 'Anualidad Élite', 'start' => '2026-10-15', 'end' => '2027-09-01', 'status' => 'active', 'frozen' => false], $deB->metadata['after']);

        // El Administrador, sin audit.view, lo ve en la ficha de cada uno.
        $adm = $this->adminAs(Admin::ROLE_ADMINISTRADOR);
        $this->getJson("/api/users/{$a->id}/membership-transfers", $adm)->assertOk()
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.role', 'holder')
            ->assertJsonPath('data.0.beneficiary.name', 'Beto Traza')
            ->assertJsonPath('data.0.created_by_name', 'Fredy Supervisión')
            ->assertJsonPath('data.0.revertible', true);
        $this->getJson("/api/users/{$b->id}/membership-transfers", $adm)->assertOk()
            ->assertJsonPath('data.0.role', 'beneficiary')->assertJsonPath('data.0.holder.name', 'Ana Titular');
    }

    // ── 9 · La app de los dos, y las señales después del commit ────────────

    public function test_app_state_de_a_inactivo_y_de_b_con_plan_fin_y_beneficios(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto App');
        $tokA = Member::where('user_id', $a->id)->value('access_hash');
        $tokB = Member::where('user_id', $b->id)->value('access_hash');
        $this->getJson('/api/member/app-state', ['Authorization' => 'Bearer '.$tokA])->assertOk()->assertJsonPath('membership.is_active', true);
        $this->getJson('/api/member/app-state', ['Authorization' => 'Bearer '.$tokB])->assertOk()->assertJsonPath('membership.is_active', false);

        $this->transferir($a, $b)->assertCreated();

        $this->getJson('/api/member/app-state', ['Authorization' => 'Bearer '.$tokA])->assertOk()
            ->assertJsonPath('membership.is_active', false)
            ->assertJsonPath('membership.ends_at', '2026-10-14')
            ->assertJsonPath('features.can_use_classes', false);
        $this->getJson('/api/member/app-state', ['Authorization' => 'Bearer '.$tokB])->assertOk()
            ->assertJsonPath('membership.is_active', true)
            ->assertJsonPath('membership.plan_name', 'Anualidad Élite')
            ->assertJsonPath('membership.ends_at', '2027-09-01')
            ->assertJsonPath('features.can_access_home', true)
            ->assertJsonPath('features.can_use_classes', true)
            ->assertJsonPath('features.can_use_ai', true);
    }

    public function test_las_senales_a_la_app_de_los_dos_salen_despues_del_commit(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Señal');
        $miembros = Member::whereIn('user_id', [$a->id, $b->id])->pluck('id')->sort()->values()->all();
        $base = DB::transactionLevel();
        $niveles = [];
        // El doble: se observa cada señal en el momento de escribirse.
        MemberRealtimeEvent::creating(function () use (&$niveles): void {
            $niveles[] = DB::transactionLevel();
        });

        $this->transferir($a, $b)->assertCreated();

        $this->assertSame([$base, $base, $base, $base], $niveles, 'Una señal salió dentro de la transacción.');
        $this->assertSame($miembros, MemberRealtimeEvent::distinct()->orderBy('member_id')->pluck('member_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['app_state.updated', 'membership.updated'], MemberRealtimeEvent::distinct()->orderBy('type')->pluck('type')->all());
    }

    // ── 10 · Lo que lee el torniquete local ────────────────────────────────

    public function test_get_users_y_access_que_lee_el_torniquete(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Puerta');
        $this->transferir($a, $b)->assertCreated();
        $token = $this->sharedTokenHeaders();

        // El sondeo de 120 s: GET users. Deja pasar si el estado no es
        // inactive/expired y fin ≥ hoy.
        $filas = collect($this->getJson('/api/users?per_page=100', $token)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('2026-10-14', $filas[$a->id]['membershipEndDate']);
        $this->assertLessThan($this->hoy()->toDateString(), $filas[$a->id]['membershipEndDate']);
        $this->assertFalse($filas[$a->id]['access']['can_enter']);
        $this->assertSame('2027-09-01', $filas[$b->id]['membershipEndDate']);
        $this->assertSame('Anualidad Élite', $filas[$b->id]['plan']);
        $this->assertSame('active', $filas[$b->id]['status']);
        $this->assertTrue($filas[$b->id]['access']['can_enter']);

        // Al ir a negar, pregunta GET users/{id}/access.
        $this->getJson("/api/users/{$b->id}/access", $token)->assertOk()
            ->assertJsonPath('access.can_enter', true)->assertJsonPath('access.plan.id', $this->anualidad->id);
        $this->getJson("/api/users/{$a->id}/access", $token)->assertOk()
            ->assertJsonPath('access.can_enter', false)->assertJsonPath('access.reason', 'expired');
    }

    // ── 12 · Revertir ──────────────────────────────────────────────────────

    public function test_revertir_restaura_a_los_dos_conserva_el_historial_y_permite_transferir_otra_vez(): void
    {
        [$a, $cobro] = $this->titularConAnualidad();
        $b = $this->socio('Beto Equivocado');
        $c = $this->socio('Carla Correcta');
        $antesA = [$a->plan, $a->membershipStartDate, $a->membershipEndDate, $a->status];
        $antesB = [$b->plan, $b->membershipStartDate, $b->membershipEndDate, $b->status];
        $id = $this->transferir($a, $b)->assertCreated()->json('transfer.id');
        $this->reloj('2026-10-15 11:00');

        $this->revertir($id)->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('already', false)
            ->assertJsonPath('transfer.status', 'reverted')
            ->assertJsonPath('transfer.revert_reason', 'Se eligió al beneficiario equivocado')
            ->assertJsonPath('transfer.reverted_by_name', 'Fredy Supervisión');

        $a->refresh();
        $b->refresh();
        $this->assertSame($antesA, [$a->plan, $a->membershipStartDate, $a->membershipEndDate, $a->status]);
        $this->assertSame($antesB, [$b->plan, $b->membershipStartDate, $b->membershipEndDate, $b->status]);
        $this->assertSame(1, MembershipTransfer::count(), 'Revertir no borra.');
        $this->assertSame(2, AuditLog::where('metadata->event', 'membership.transfer.reverted')->count());
        $this->revertir($id)->assertOk()->assertJsonPath('already', true);
        $this->assertSame(2, AuditLog::where('metadata->event', 'membership.transfer.reverted')->count(), 'Revertir dos veces no escribe dos veces.');

        // Y al beneficiario correcto.
        $this->transferir($a, $c)->assertCreated()->assertJsonPath('transfer.source_payment_id', $cobro->id);
        $this->assertSame('2027-09-01', $c->fresh()->membershipEndDate);
        $this->assertSame(2, MembershipTransfer::where('source_payment_id', $cobro->id)->count());
        $this->assertSame(1, MembershipTransfer::live()->where('source_payment_id', $cobro->id)->count());
    }

    public function test_revertir_se_bloquea_si_b_ya_entro(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Entró');
        $id = $this->transferir($a, $b)->assertCreated()->json('transfer.id');
        $this->reloj('2026-10-15 18:00');
        Attendance::create(['user_id' => $b->id, 'action' => 'entry', 'source' => 'facial', 'captured_at' => now()]);

        $r = $this->revertir($id)->assertStatus(409)->assertJsonPath('code', 'revert_blocked');
        $this->assertContains('beneficiary_entered', $this->codigos($r));
        $this->assertSame('2027-09-01', $b->fresh()->membershipEndDate);
        $this->assertTrue(MembershipTransfer::findOrFail($id)->isLive());
    }

    public function test_revertir_se_bloquea_si_algo_cambio_despues(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Cambió');
        $id = $this->transferir($a, $b)->assertCreated()->json('transfer.id');
        $this->reloj('2026-10-16 09:00');

        // B renovó (o congeló, o le ajustaron días): sus fechas ya no son las del traspaso.
        $this->cobrar($b->fresh(), $this->mensual);

        $r = $this->revertir($id)->assertStatus(409);
        $this->assertContains('beneficiary_changed', $this->codigos($r));
        $this->assertContains('paid_after', $this->codigos($r));
        $this->assertTrue(MembershipTransfer::findOrFail($id)->isLive());
    }

    public function test_revertir_se_bloquea_si_b_tiene_reservas_de_clase_por_delante(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Reserva');
        $id = $this->transferir($a, $b)->assertCreated()->json('transfer.id');
        $clase = MyClass::create([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '07:00',
            'end_time' => '08:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);
        $reserva = ClassReservation::create(['class_id' => $clase->id, 'member_id' => Member::where('user_id', $b->id)->value('id'), 'session_date' => '2026-10-19']);

        $r = $this->revertir($id)->assertStatus(409)->assertJsonPath('code', 'revert_blocked');
        $this->assertSame(['beneficiary_upcoming_reservations'], $this->codigos($r));
        $this->assertTrue(MembershipTransfer::findOrFail($id)->isLive());

        // Cancelada la reserva, se revierte.
        $reserva->delete();
        $this->revertir($id)->assertOk()->assertJsonPath('already', false);
    }

    // ── 13 · Renovación posterior de B ─────────────────────────────────────

    public function test_la_renovacion_de_b_se_encadena_tras_el_fin_recibido(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Renueva');
        $this->transferir($a, $b)->assertCreated();
        $this->reloj('2026-11-02 10:00');

        $renovacion = $this->cobrar($b->fresh(), $this->mensual);

        $this->assertSame('2027-09-01', $renovacion->period_start->format('Y-m-d'), 'Se encadena tras E, no desde hoy.');
        $this->assertSame('2027-10-01', $renovacion->period_end->format('Y-m-d'));
        $this->assertSame(['Mensual', '2026-10-15', '2027-10-01'], [$b->fresh()->plan, $b->fresh()->membershipStartDate, $b->fresh()->membershipEndDate]);
    }
}
