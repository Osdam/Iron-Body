<?php

namespace Tests\Feature\Members;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MembershipSubscription;
use App\Models\MembershipTransfer;
use App\Models\MemberTrainerAssignment;
use App\Models\MyClass;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Trainer;
use App\Models\User;
use App\Services\Membership\MembershipFreeze;
use App\Support\Access\CrmPermission;
use App\Support\Access\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TRANSFERIR MEMBRESÍA: lo que NO se puede (contrato §2), quién puede (§3) y
 * las guardas nuevas sobre lo que ya existía (§4): rebuild, future-dates,
 * anular y devolver el cobro de origen.
 *
 * Cada bloqueo se comprueba dos veces: en la vista previa y en la ejecución,
 * que lo revalida bajo bloqueo y no escribe nada.
 */
class MembershipTransferGuardsTest extends TestCase
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

    // ── 5 · Bloqueos ───────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string}> */
    public static function bloqueos(): array
    {
        return [
            'B con plan activo' => ['b_con_plan_activo', 'beneficiary_active'],
            'B con plan programado' => ['b_programada', 'beneficiary_scheduled'],
            'B congelada' => ['b_congelada', 'beneficiary_frozen'],
            'B suspendida' => ['b_suspendida', 'beneficiary_suspended'],
            'B eliminada' => ['b_eliminada', 'beneficiary_deleted'],
            'B sin ficha de socio' => ['b_sin_ficha', 'beneficiary_no_member_profile'],
            'B = A' => ['b_es_a', 'same_person'],
            'misma identidad' => ['misma_identidad', 'same_identity'],
            'A congelada' => ['a_congelada', 'holder_frozen'],
            'A con la cuenta suspendida' => ['a_suspendida', 'holder_suspended'],
            'A con la cuenta eliminada' => ['a_eliminada', 'holder_deleted'],
            'A con el cobro anulado' => ['a_anulada', 'holder_expired'],
            'A vencida' => ['a_vencida', 'holder_expired'],
            'A con inicio futuro' => ['a_futura', 'holder_not_started'],
            'A con la cuenta inactiva' => ['a_inactiva', 'holder_account_inactive'],
            'vigencia sin cobro (cortesía)' => ['vigencia_sin_cobro', 'no_source_payment'],
            'vigencia con ajuste manual' => ['ajuste_manual', 'source_mismatch'],
            'renovación encadenada' => ['renovacion_encadenada', 'source_not_unique'],
            'cobro importado MIGR' => ['migrado', 'source_migrated'],
            'plan por entradas' => ['plan_por_entradas', 'entries_plan'],
            'mejora de plan' => ['mejora', 'source_is_upgrade'],
            'plan de A distinto del cobro' => ['plan_distinto', 'plan_mismatch'],
            'deuda del cobro sin pagar' => ['deuda', 'source_receivable_unpaid'],
            'A en mora' => ['mora', 'holder_overdue'],
            'cobro ya cedido' => ['ya_transferida', 'source_already_transferred'],
            'A recibió por traspaso' => ['recibida_por_traspaso', 'holder_received_by_transfer'],
            'cancelación solicitada' => ['cancelacion', 'holder_cancellation_requested'],
            'cobro pendiente' => ['cobro_pendiente', 'holder_pending_payment'],
            'suscripción activa' => ['suscripcion', 'holder_live_subscription'],
            'reservas de clase por delante' => ['reservas', 'holder_upcoming_reservations'],
        ];
    }

    #[DataProvider('bloqueos')]
    public function test_bloqueo_en_la_vista_previa_y_en_la_ejecucion_sin_escribir_nada(string $escenario, string $codigo): void
    {
        [$a, $b] = $this->escenario($escenario);
        $traspasosAntes = MembershipTransfer::count();
        $usuarios = $this->tabla('users');

        $previa = $this->previa($a, $b)->assertOk()->assertJsonPath('allowed', false);
        $this->assertContains($codigo, $this->codigos($previa), 'La vista previa no lo bloquea: '.json_encode($previa->json('blockers')));
        if ($escenario === 'deuda') {
            $this->assertSame(['source_receivable_unpaid'], $this->codigos($previa), 'La deuda tiene que ser el único motivo.');
        }

        $r = $this->transferir($a, $b)->assertStatus(409)->assertJsonPath('code', 'transfer_blocked');
        $this->assertContains($codigo, $this->codigos($r));
        $this->assertSame($traspasosAntes, MembershipTransfer::count());
        $this->assertSame($usuarios, $this->tabla('users'), 'Un traspaso bloqueado escribió en users.');
    }

    /** @return array{0: User, 1: User} */
    private function escenario(string $nombre): array
    {
        $b = $this->socio('Beto '.$nombre);

        switch ($nombre) {
            case 'b_con_plan_activo':
                [$a] = $this->titularConAnualidad();
                $this->cobrar($b, $this->mensual);

                return [$a, $b->refresh()];
            case 'b_programada':
                [$a] = $this->titularConAnualidad();
                $this->cobrar($b, $this->mensual, ['starts_on' => '2026-10-20']);

                return [$a, $b->refresh()];
            case 'b_congelada':
                [$a] = $this->titularConAnualidad();
                $this->cobrar($b, $this->mensual);
                app(MembershipFreeze::class)->freeze($b->refresh(), 15, 'Viaje');

                return [$a, $b->refresh()];
            case 'b_suspendida':
                Member::where('user_id', $b->id)->update(['status' => Member::STATUS_SUSPENDED]);

                return [$this->titularConAnualidad()[0], $b];
            case 'b_eliminada':
                Member::where('user_id', $b->id)->update(['status' => Member::STATUS_DELETED]);

                return [$this->titularConAnualidad()[0], $b];
            case 'b_sin_ficha':
                return [$this->titularConAnualidad()[0], $this->socio('Beto sin ficha', conFicha: false)];
            case 'b_es_a':
                $a = $this->titularConAnualidad()[0];

                return [$a, $a];
            case 'misma_identidad':
                $a = $this->titularConAnualidad()[0];
                Member::whereIn('user_id', [$a->id, $b->id])->update(['identity_id' => 4242]);

                return [$a, $b];
            case 'a_congelada':
                $a = $this->titularConAnualidad()[0];
                app(MembershipFreeze::class)->freeze($a, 30, 'Lesión');

                return [$a->refresh(), $b];
            case 'a_suspendida':
                $a = $this->titularConAnualidad()[0];
                Member::where('user_id', $a->id)->update(['status' => Member::STATUS_SUSPENDED]);

                return [$a, $b];
            case 'a_eliminada':
                $a = $this->titularConAnualidad()[0];
                Member::where('user_id', $a->id)->update(['status' => Member::STATUS_DELETED]);

                return [$a, $b];
            case 'a_anulada':
                [$a, $cobro] = $this->titularConAnualidad();
                $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => 'Cobro duplicado'], $this->h())->assertOk();

                return [$a->refresh(), $b];
            case 'a_vencida':
                $a = $this->socio('Ana vencida');
                $this->cobrar($a, $this->mensual);
                $this->diaDelTraspaso();

                return [$a->refresh(), $b];
            case 'a_futura':
                $this->diaDelTraspaso();
                $a = $this->socio('Ana futura');
                $this->cobrar($a, $this->mensual, ['starts_on' => '2026-10-25']);

                return [$a->refresh(), $b];
            case 'a_inactiva':
                $a = $this->titularConAnualidad()[0];
                $a->forceFill(['status' => 'inactive'])->save();

                return [$a->refresh(), $b];
            case 'vigencia_sin_cobro':
                $this->diaDelTraspaso();
                $a = $this->socio('Ana cortesía');
                $a->forceFill(['plan' => 'Anualidad Élite', 'membership_start_date' => '2026-10-01', 'membership_end_date' => '2027-10-01'])->save();

                return [$a->refresh(), $b];
            case 'ajuste_manual':
                $a = $this->titularConAnualidad()[0];
                $this->postJson("/api/users/{$a->id}/adjustments", ['kind' => 'days', 'amount' => 3, 'reason' => 'No pudo venir'], $this->h())
                    ->assertSuccessful();

                return [$a->refresh(), $b];
            case 'renovacion_encadenada':
                $a = $this->socio('Ana encadenada');
                $this->cobrar($a, $this->mensual);
                $this->cobrar($a, $this->mensual);
                $this->reloj('2026-09-10 10:00');

                return [$a->refresh(), $b];
            case 'migrado':
                [$a, $cobro] = $this->titularConAnualidad();
                $cobro->forceFill(['reference' => 'MIGR-000123'])->save();

                return [$a, $b];
            case 'plan_por_entradas':
                $doce = $this->plan('Doce entradas', 30, 60000, ['access_mode' => 'entries', 'entry_credits' => 12]);
                $a = $this->socio('Ana entradas');
                $this->cobrar($a, $doce);
                $this->reloj('2026-09-10 10:00');

                return [$a->refresh(), $b];
            case 'mejora':
                $a = $this->socio('Ana mejora');
                $this->cobrar($a, $this->mensual);
                $this->reloj('2026-09-10 10:00');
                $this->postJson("/api/users/{$a->id}/plan-upgrade", ['plan_id' => $this->anualidad->id, 'method' => 'cash'], $this->h())
                    ->assertSuccessful();

                return [$a->refresh(), $b];
            case 'plan_distinto':
                $a = $this->titularConAnualidad()[0];
                $a->forceFill(['plan' => 'Mensual'])->save();

                return [$a->refresh(), $b];
            case 'deuda':
                $a = $this->socio('Ana debe');
                // Con plazo pactado lejos: debe, pero no está en mora. Es la
                // deuda del cobro, y solo ella, la que bloquea.
                $this->postJson('/api/admin/receivables/plan', [
                    'user_id' => $a->id, 'plan_id' => $this->anualidad->id, 'first_payment' => 100000, 'method' => 'transfer',
                    'due_at' => '2026-12-01',
                ], $this->h())->assertStatus(201);
                $this->diaDelTraspaso();

                return [$a->refresh(), $b];
            case 'mora':
                $a = $this->titularConAnualidad()[0];
                Receivable::create([
                    'member_id' => Member::where('user_id', $a->id)->value('id'),
                    'debtor_type' => DebtorType::MEMBER->value, 'debtor_id' => Member::where('user_id', $a->id)->value('id'),
                    'type' => CashShiftType::GYM->value, 'concept' => 'Saldo de un plan anterior', 'total_amount' => 50000,
                    'status' => Receivable::STATUS_PENDING, 'due_at' => '2026-10-01', 'blocks_benefits' => true,
                ]);

                return [$a, $b];
            case 'ya_transferida':
                [$a, $cobro] = $this->titularConAnualidad();
                $this->transferir($a, $this->socio('Primer beneficiario'))->assertCreated();
                // Alguien le devolvió las fechas a mano: el cobro sigue cedido.
                $a->refresh()->forceFill(['membership_start_date' => '2026-09-01', 'membership_end_date' => '2027-09-01'])->save();

                return [$a->refresh(), $b];
            case 'recibida_por_traspaso':
                [$a] = $this->titularConAnualidad();
                $intermedio = $this->socio('Intermedio');
                $this->transferir($a, $intermedio)->assertCreated();

                return [$intermedio->refresh(), $b];
            case 'cancelacion':
                $a = $this->titularConAnualidad()[0];
                $a->forceFill(['membership_cancellation_requested_at' => now()])->save();

                return [$a->refresh(), $b];
            case 'cobro_pendiente':
                $a = $this->titularConAnualidad()[0];
                $this->postJson('/api/payments', [
                    'user_id' => $a->id, 'plan_id' => $this->mensual->id, 'amount' => 80000, 'method' => 'transfer', 'status' => 'pending',
                ], $this->h())->assertStatus(201);

                return [$a->refresh(), $b];
            case 'suscripcion':
                $a = $this->titularConAnualidad()[0];
                MembershipSubscription::create([
                    'uuid' => (string) Str::uuid(), 'user_id' => $a->id,
                    'member_id' => Member::where('user_id', $a->id)->value('id'), 'plan_id' => $this->anualidad->id,
                    'status' => MembershipSubscription::STATUS_ACTIVE, 'price_snapshot' => 624000, 'currency' => 'COP',
                    'interval_days' => 365, 'method' => 'card',
                ]);

                return [$a, $b];
            case 'reservas':
                $a = $this->titularConAnualidad()[0];
                $clase = MyClass::create([
                    'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '07:00',
                    'end_time' => '08:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
                ]);
                ClassReservation::create(['class_id' => $clase->id, 'member_id' => Member::where('user_id', $a->id)->value('id'), 'session_date' => '2026-10-19']);

                return [$a, $b];
        }

        $this->fail("Escenario desconocido: {$nombre}");
    }

    public function test_una_reserva_ya_pasada_no_bloquea(): void
    {
        [$a] = $this->titularConAnualidad();
        $clase = MyClass::create([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '07:00',
            'end_time' => '08:00', 'status' => 'active', 'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => Member::where('user_id', $a->id)->value('id'), 'session_date' => '2026-10-12']);

        $this->previa($a, $this->socio('Beto'))->assertOk()->assertJsonPath('allowed', true);
    }

    public function test_sin_consentimientos_ni_motivo_es_422_y_no_escribe(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto');

        $this->transferir($a, $b, ['holder_consent' => false])->assertStatus(422)->assertJsonValidationErrors('holder_consent');
        $this->transferir($a, $b, ['beneficiary_acceptance' => null])->assertStatus(422)->assertJsonValidationErrors('beneficiary_acceptance');
        $this->transferir($a, $b, ['reason' => 'ok'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->transferir($a, $b, ['evidence' => str_repeat('x', 256)])->assertStatus(422)->assertJsonValidationErrors('evidence');
        $this->transferir($a, $b, ['client_request_id' => null])->assertStatus(422)->assertJsonValidationErrors('client_request_id');
        $this->transferir($a, $b, ['beneficiary_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('beneficiary_id');
        $this->assertSame(0, MembershipTransfer::count());
        $this->assertSame('2027-09-01', $a->fresh()->membershipEndDate);
    }

    public function test_sin_beneficiario_la_vista_previa_solo_trae_la_parte_de_a(): void
    {
        [$a, $cobro] = $this->titularConAnualidad();

        $this->previa($a)->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('beneficiary', null)
            ->assertJsonPath('holder.days', 322)
            ->assertJsonPath('effects.source_payment.id', $cobro->id)
            ->assertJsonPath('effects.holder.end_after', '2026-10-14')
            ->assertJsonPath('effects.beneficiary', null)
            ->assertJsonPath('effects.creates_payment', false);
    }

    // ── 7 · Permisos ───────────────────────────────────────────────────────

    public function test_sin_permiso_403_el_administrador_no_lo_tiene_por_defecto_y_el_super_admin_si(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Permisos');
        $porRol = CrmPermission::byRole();
        $p = CrmPermission::MEMBERS_TRANSFER_MEMBERSHIP;

        $this->assertContains($p, PermissionCatalog::all());
        $this->assertContains($p, $porRol[Admin::ROLE_SUPER_ADMIN]);
        foreach ([Admin::ROLE_ADMINISTRADOR, Admin::ROLE_RECEPCION, Admin::ROLE_ADMINISTRATIVO, CrmPermission::ROLE_ENTRENADOR] as $rol) {
            $this->assertNotContains($p, $porRol[$rol], "{$rol} no trae {$p} por defecto");
        }
        $this->assertNotContains($p, CrmPermission::readOnly(), 'El token compartido no lo lee.');
        $fila = collect(PermissionCatalog::rows())->firstWhere('key', $p);
        $this->assertSame('Transferir membresías', $fila['label']);
        $this->assertSame('members', $fila['domain']);
        $this->assertNotEmpty($fila['help']);

        $credenciales = [
            'Administrador' => $this->adminAs(Admin::ROLE_ADMINISTRADOR),
            'Recepción' => $this->adminAs(Admin::ROLE_RECEPCION),
            'token compartido' => $this->sharedTokenHeaders(),
        ];
        foreach ($credenciales as $quien => $h) {
            $this->assertSame(403, $this->previa($a, $b, $h)->status(), "{$quien}: vista previa");
            $this->assertSame(403, $this->transferir($a, $b, [], $h)->status(), "{$quien}: transferir");
        }
        $this->assertSame(0, MembershipTransfer::count());

        $id = $this->transferir($a, $b)->assertCreated()->json('transfer.id');
        foreach ($credenciales as $quien => $h) {
            $this->assertSame(403, $this->revertir($id, 'Prueba de permisos', $h)->status(), "{$quien}: revertir");
        }
        // El historial de la ficha va con «Ver miembros», y lo lee una PERSONA:
        // el token compartido tiene members.view pero no ve motivos ni evidencias.
        $this->getJson("/api/users/{$a->id}/membership-transfers", $credenciales['Recepción'])->assertOk();
        $this->getJson("/api/users/{$a->id}/membership-transfers", $credenciales['token compartido'])
            ->assertStatus(403)->assertJsonPath('code', 'person_required');
        $this->assertTrue(MembershipTransfer::findOrFail($id)->isLive());
    }

    public function test_el_propietario_se_lo_da_al_administrador_desde_roles(): void
    {
        [$a] = $this->titularConAnualidad();
        $adm = $this->adminAs(Admin::ROLE_ADMINISTRADOR);
        $this->previa($a, null, $adm)->assertStatus(403);

        $this->putJson('/api/admin/roles/permissions', [
            'role' => Admin::ROLE_ADMINISTRADOR, 'permission' => CrmPermission::MEMBERS_TRANSFER_MEMBERSHIP, 'granted' => true,
        ], $this->h())->assertOk();

        $this->previa($a, null, $adm)->assertOk();
        $this->transferir($a, $this->socio('Beto'), [], $adm)->assertCreated();
    }

    public function test_el_entrenador_no_ve_los_datos_de_la_otra_persona_si_no_es_suya(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Entrena');
        $this->transferir($a, $b)->assertCreated();
        AdminRole::firstOrCreate(['name' => CrmPermission::ROLE_ENTRENADOR], ['is_system' => true]);
        $entrenador = Trainer::create(['full_name' => 'Coach Uno', 'email' => 'coach-'.uniqid().'@ironbody.test', 'status' => 'active']);
        MemberTrainerAssignment::create([
            'member_id' => Member::where('user_id', $b->id)->value('id'), 'trainer_id' => $entrenador->id,
            'status' => MemberTrainerAssignment::STATUS_ACTIVE, 'assigned_at' => now(),
        ]);
        $cuenta = Admin::create([
            'name' => 'Cuenta coach', 'email' => 'coach-cuenta-'.uniqid().'@ironbody.test', 'password' => 'secret-password',
            'role' => CrmPermission::ROLE_ENTRENADOR, 'trainer_id' => $entrenador->id, 'status' => 'active',
        ]);

        $fila = $this->getJson("/api/users/{$b->id}/membership-transfers", $this->actingAsAdmin($cuenta))->assertOk()->json('data.0');

        $this->assertSame('beneficiary', $fila['role']);
        $this->assertSame(['id' => $b->id, 'name' => 'Beto Entrena'], $fila['beneficiary']);
        $this->assertSame(['id' => null, 'name' => null], $fila['holder'], 'La titular no es suya: ni nombre ni id.');
        $this->assertSame(['plan' => null, 'start' => null, 'end' => null, 'status' => null], $fila['holder_before']);
        $this->assertNull($fila['source_payment_id']);
        $this->assertNull($fila['reason']);
        $this->assertNull($fila['evidence']);
        $this->assertStringNotContainsString('Ana Titular', json_encode($fila));
        // A la titular no la ve en absoluto.
        $this->getJson("/api/users/{$a->id}/membership-transfers", $this->actingAsAdmin($cuenta))->assertNotFound();
        // Quien ve a los dos lo ve todo.
        $this->getJson("/api/users/{$b->id}/membership-transfers", $this->h())->assertOk()
            ->assertJsonPath('data.0.holder.name', 'Ana Titular')
            ->assertJsonPath('data.0.reason', 'Se muda de ciudad y cede su anualidad a su hermana');
    }

    public function test_la_mejora_de_plan_se_rechaza_a_la_titular_y_al_beneficiario_con_traspaso_vivo(): void
    {
        // Planes sin tilde: en SQLite `LOWER()` no baja la «É» y
        // PlanUpgrades::currentPlan() no encontraría el plan actual (en
        // PostgreSQL sí), con lo que la mejora se negaría por otro motivo.
        $plus = $this->plan('Anualidad Plus', 365, 624000);
        $black = $this->plan('Anualidad Black', 365, 900000);
        $a = $this->socio('Ana Mejora');
        $this->cobrar($a, $plus);
        $this->diaDelTraspaso();
        $b = $this->socio('Beto Mejora');
        $id = $this->transferir($a->refresh(), $b)->assertCreated()->json('transfer.id');

        // Sin la guarda: B no tiene cobro, su periodo empezaría hoy y la mejora
        // le dejaría hasta el 2027-10-15, 44 días después del fin que tenía A.
        $previa = $this->getJson("/api/users/{$b->id}/plan-upgrade?plan_id={$black->id}", $this->h())->assertOk()
            ->assertJsonPath('can_upgrade', false);
        $this->assertStringContainsString("traspaso (#{$id})", (string) $previa->json('reason'));
        $this->postJson("/api/users/{$b->id}/plan-upgrade", ['plan_id' => $black->id, 'method' => 'cash'], $this->h())
            ->assertStatus(422);
        $this->assertSame(['Anualidad Plus', '2027-09-01'], [$b->fresh()->plan, $b->fresh()->membershipEndDate]);
        $this->assertSame(0, Payment::where('user_id', $b->id)->count());

        $this->assertStringContainsString("traspaso (#{$id})", (string) $this->getJson(
            "/api/users/{$a->id}/plan-upgrade?plan_id={$black->id}", $this->h())->assertOk()->json('reason'));

        // Revertido el traspaso, A vuelve a poder mejorar con las reglas de siempre.
        $this->revertir($id)->assertOk();
        $this->getJson("/api/users/{$a->id}/plan-upgrade?plan_id={$black->id}", $this->h())->assertOk()
            ->assertJsonPath('can_upgrade', true);
    }

    // ── 11 · Guardas sobre lo que ya existía ───────────────────────────────

    public function test_rebuild_rechaza_a_la_titular_y_al_beneficiario(): void
    {
        [$a] = $this->titularConAnualidad();
        // B con un cobro viejo: reconstruirlo le pondría la vigencia de ese cobro.
        $b = $this->socio('Beto Rebuild');
        $this->reloj('2026-08-01 10:00');
        $this->cobrar($b, $this->mensual);
        $this->diaDelTraspaso();
        $id = $this->transferir($a, $b->refresh())->assertCreated()->json('transfer.id');

        foreach ([$a, $b] as $socio) {
            $this->artisan("memberships:rebuild --user={$socio->id} --apply")
                ->expectsOutputToContain("traspaso de membresía vivo (#{$id})")
                ->assertSuccessful();
        }
        $this->artisan('memberships:rebuild --all --apply')->assertSuccessful();

        $this->assertSame('2026-10-14', $a->fresh()->membershipEndDate, 'El rebuild le devolvió a A lo que cedió.');
        $this->assertSame('2027-09-01', $b->fresh()->membershipEndDate, 'El rebuild le quitó a B lo que recibió.');
    }

    public function test_future_dates_fix_no_toca_a_ninguno_de_los_dos(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Futuro');
        $this->reloj('2026-08-01 10:00');
        $this->cobrar($b, $this->mensual);
        $this->diaDelTraspaso();
        $this->transferir($a, $b->refresh())->assertCreated();

        // B vence dentro de más de 6 meses: sale en la lista, pero no se toca.
        $this->artisan('members:future-dates --fix')->assertSuccessful();
        $this->artisan("members:future-dates --user={$a->id} --fix")->assertSuccessful();
        $this->artisan("members:future-dates --user={$b->id} --fix")->assertSuccessful();
        $this->artisan("members:future-dates --user={$a->id} --fix --start=2026-09-01 --end=2027-09-01")->assertFailed();

        $this->assertSame(['2026-09-01', '2026-10-14'], [$a->fresh()->membershipStartDate, $a->fresh()->membershipEndDate]);
        $this->assertSame(['2026-10-15', '2027-09-01'], [$b->fresh()->membershipStartDate, $b->fresh()->membershipEndDate]);
    }

    public function test_anular_o_devolver_el_cobro_de_origen_es_409_membership_transferred(): void
    {
        [$a, $cobro] = $this->titularConAnualidad();
        $b = $this->socio('Beto Guarda');
        $id = $this->transferir($a, $b)->assertCreated()->json('transfer.id');

        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => 'Cobro duplicado'], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'membership_transferred')->assertJsonPath('transfer_id', $id);
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'pending', 'reason' => 'No ha pagado todavía'], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'membership_transferred');
        $this->postJson("/api/payments/{$cobro->id}/refund", ['reason' => 'Se arrepintió'], $this->h())
            ->assertStatus(409)->assertJsonPath('code', 'membership_transferred');
        foreach (['cancel', 'refund'] as $accion) {
            $previa = $this->getJson("/api/payments/{$cobro->id}/correction-preview?action={$accion}", $this->h())->assertOk();
            $this->assertContains('membership_transferred', array_column($previa->json('blockers'), 'code'), $accion);
        }

        $this->assertSame('paid', $cobro->fresh()->status);
        $this->assertSame(0, DB::table('cash_refunds')->count());
        $this->assertSame('2027-09-01', $b->fresh()->membershipEndDate);

        // Revertido el traspaso, el cobro vuelve a poder anularse.
        $this->revertir($id)->assertOk();
        $this->patchJson("/api/payments/{$cobro->id}", ['status' => 'cancelled', 'reason' => 'Cobro duplicado'], $this->h())->assertOk();
        $this->assertSame('cancelled', $cobro->fresh()->status);
    }

    public function test_anular_un_cobro_de_b_posterior_no_queda_bloqueado(): void
    {
        [$a] = $this->titularConAnualidad();
        $b = $this->socio('Beto Renueva');
        $this->transferir($a, $b)->assertCreated();
        $renovacion = $this->cobrar($b->fresh(), $this->mensual);

        $this->patchJson("/api/payments/{$renovacion->id}", ['status' => 'cancelled', 'reason' => 'Cobro duplicado'], $this->h())->assertOk();
        $this->assertSame('2027-09-01', $b->fresh()->membershipEndDate, 'Devuelve los días de la renovación y deja los recibidos.');
        $this->assertInstanceOf(Payment::class, $renovacion);
    }
}
