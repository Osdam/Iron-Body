<?php

namespace Tests\Feature\Commissions;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\EmployeeAccess;
use App\Models\Identity;
use App\Models\Member;
use App\Models\Receivable;
use App\Models\Trainer;
use App\Models\TrainerCommissionAgreement;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\Commissions\TrainerCommissionAccess;
use App\Services\Membership\MembershipAccess;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EL PISO PAGADO ABRE LA PUERTA, Y SE CIERRA SOLO CUANDO SE ACABA.
 *
 * Hasta ahora el piso era solo dinero: se cobraba y no daba acceso a nadie.
 * Ahora el entrenador entra mientras lo tenga al día, que es lo que convierte
 * el cobro en algo que se reclama solo — quien no paga, no trabaja dentro.
 *
 * NO HAY UNA PUERTA NUEVA. Lo que se escribe es un acceso de EMPLEADO, el
 * mecanismo que ya existe y que el terminal aplica sin internet. Dos sistemas
 * decidiendo sobre la misma puerta es como se acaba sin saber cuál manda.
 *
 * Y LO CONCEDIDO A MANO NO SE TOCA: si el gimnasio le dio acceso a alguien, el
 * piso no se lo quita.
 */
class CommissionAccessTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Dueño Iron Body',
            'email' => 'acceso-piso-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        $this->h = $this->actingAsAdmin($this->admin);
        app(CashShiftService::class)->open($this->admin, CashShiftType::GYM);
    }

    private function hoy(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    /**
     * Un entrenador que ADEMÁS existe como persona para la puerta.
     *
     * El puente es Trainer → Identity → Member → User, y es lo que permite que
     * el lector lo reconozca. Un entrenador sin ese puente no puede entrar
     * aunque pague, y eso tiene su propio test.
     *
     * @return array{0: Trainer, 1: User}
     */
    private function entrenadorConFicha(string $nombre = 'Carlos Entrenador'): array
    {
        $identidad = Identity::create([]);

        $user = User::create([
            'name' => $nombre,
            'email' => 'tr-'.uniqid().'@ironbody.test',
            'password' => bcrypt('secret-password'),
            'status' => 'active',
        ]);

        Member::create([
            'identity_id' => $identidad->id,
            'user_id' => $user->id,
            'full_name' => $nombre,
            'email' => $user->email,
            'document_number' => (string) random_int(10_000_000, 99_999_999),
            'status' => Member::STATUS_ACTIVE,
        ]);

        $trainer = Trainer::create([
            'identity_id' => $identidad->id,
            'full_name' => $nombre,
            'document' => (string) random_int(10_000_000, 99_999_999),
            'email' => 'prof-'.uniqid().'@ironbody.test',
            'status' => 'active',
        ]);

        return [$trainer, $user];
    }

    private function cliente(): Member
    {
        $user = User::create([
            'name' => 'Olga Cliente',
            'email' => 'cli-'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'status' => 'active',
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Olga Cliente',
            'email' => $user->email,
            'document_number' => (string) random_int(10_000_000, 99_999_999),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function acuerdo(Trainer $t, int $dias = 30): TrainerCommissionAgreement
    {
        return TrainerCommissionAgreement::findOrFail(
            $this->postJson('/api/admin/commissions/agreements', [
                'trainer_id' => $t->id,
                'member_id' => $this->cliente()->id,
                'amount' => 50000,
                'cycle_days' => $dias,
                'payer' => 'trainer',
            ], $this->h)->assertStatus(201)->json('id'),
        );
    }

    private function cobrar(TrainerCommissionAgreement $a, array $cuerpo = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/admin/commissions/agreements/{$a->id}/charge", array_merge([
            'period' => $this->hoy()->format('Y-m'),
        ], $cuerpo), $this->h);
    }

    private function puedeEntrar(User $u, ?CarbonImmutable $cuando = null): bool
    {
        return app(MembershipAccess::class)->state($u->refresh(), $cuando)['can_enter'];
    }

    // ── La puerta ───────────────────────────────────────────────────────────

    public function test_pagar_el_piso_le_abre_la_puerta_al_entrenador(): void
    {
        [$trainer, $user] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer, dias: 30);

        // Sin pagar no entra: no tiene membresía ni acceso de empleado.
        $this->assertFalse($this->puedeEntrar($user));

        $this->cobrar($acuerdo, [
            'method' => 'cash',
            'covers_from' => $this->hoy()->toDateString(),
        ])->assertStatus(201);

        $this->assertTrue($this->puedeEntrar($user));
        $this->assertSame(
            MembershipAccess::VIA_EMPLOYEE,
            app(MembershipAccess::class)->state($user->refresh())['via'],
        );
    }

    public function test_el_acceso_dura_los_dias_pactados_y_despues_se_cierra(): void
    {
        [$trainer, $user] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer, dias: 15);

        $this->cobrar($acuerdo, [
            'method' => 'cash',
            'covers_from' => $this->hoy()->toDateString(),
        ])->assertStatus(201);

        // El día 15 todavía entra…
        $this->assertTrue($this->puedeEntrar($user, $this->hoy()->addDays(14)->setTime(10, 0)));
        // …y el 16 ya no.
        $this->assertFalse($this->puedeEntrar($user, $this->hoy()->addDays(15)->setTime(10, 0)));

        $acceso = EmployeeAccess::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($this->hoy()->addDays(14)->toDateString(), $acceso->ends_on->toDateString());
    }

    public function test_abrir_la_deuda_sin_cobrar_no_abre_la_puerta(): void
    {
        // Deber el piso no es pagarlo.
        [$trainer, $user] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer);

        $this->cobrar($acuerdo, [
            'due_at' => $this->hoy()->addDays(10)->toDateString(),
            'covers_from' => $this->hoy()->toDateString(),
        ])->assertStatus(201);

        $this->assertFalse($this->puedeEntrar($user));
    }

    public function test_pagar_la_deuda_desde_cuentas_por_cobrar_tambien_abre(): void
    {
        // El abono no siempre pasa por el módulo de Comisiones. Si solo lo
        // mirara ese controlador, alguien pagaría en caja y seguiría fuera.
        [$trainer, $user] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer);

        $this->cobrar($acuerdo, [
            'due_at' => $this->hoy()->addDays(10)->toDateString(),
            'covers_from' => $this->hoy()->toDateString(),
        ])->assertStatus(201);
        $this->assertFalse($this->puedeEntrar($user));

        $deuda = Receivable::firstOrFail();
        $this->postJson("/api/admin/receivables/{$deuda->id}/payments", [
            'amount' => 50000, 'method' => 'cash',
        ], $this->h)->assertSuccessful();

        $this->assertTrue($this->puedeEntrar($user));
    }

    public function test_un_abono_a_medias_no_abre_nada(): void
    {
        // Pagado es saldo cero. Medio piso no es un piso.
        [$trainer, $user] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer);

        $this->cobrar($acuerdo, [
            'due_at' => $this->hoy()->addDays(10)->toDateString(),
            'covers_from' => $this->hoy()->toDateString(),
        ])->assertStatus(201);

        $deuda = Receivable::firstOrFail();
        $this->postJson("/api/admin/receivables/{$deuda->id}/payments", [
            'amount' => 20000, 'method' => 'cash',
        ], $this->h)->assertSuccessful();

        $this->assertFalse($this->puedeEntrar($user));
    }

    public function test_renovar_el_piso_alarga_el_acceso(): void
    {
        [$trainer, $user] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer, dias: 30);

        $this->cobrar($acuerdo, [
            'method' => 'cash',
            'covers_from' => $this->hoy()->toDateString(),
        ])->assertStatus(201);

        // El mes siguiente, arrancando donde terminaba el anterior.
        $this->cobrar($acuerdo, [
            'period' => $this->hoy()->addMonth()->format('Y-m'),
            'method' => 'cash',
            'covers_from' => $this->hoy()->addDays(30)->toDateString(),
        ])->assertStatus(201);

        $this->assertSame(
            $this->hoy()->addDays(59)->toDateString(),
            EmployeeAccess::where('user_id', $user->id)->firstOrFail()->ends_on->toDateString(),
        );
        $this->assertTrue($this->puedeEntrar($user, $this->hoy()->addDays(45)->setTime(10, 0)));
    }

    public function test_el_acceso_concedido_a_mano_no_lo_toca_el_piso(): void
    {
        // Si el gimnasio decidió que ese entrenador entra, el piso no se lo
        // quita cuando venza.
        [$trainer, $user] = $this->entrenadorConFicha();
        $this->putJson("/api/users/{$user->id}/employee-access", [
            'position' => 'Jefe de piso',
        ], $this->h)->assertStatus(201);

        $acuerdo = $this->acuerdo($trainer);
        $this->cobrar($acuerdo, [
            'method' => 'cash',
            'covers_from' => $this->hoy()->subDays(60)->toDateString(),
        ])->assertStatus(201);

        $acceso = EmployeeAccess::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('manual', $acceso->source);
        $this->assertSame('Jefe de piso', $acceso->position);
        $this->assertNull($acceso->ends_on, 'sigue sin caducar');
        $this->assertTrue($this->puedeEntrar($user));
    }

    public function test_un_entrenador_sin_ficha_de_persona_no_tiene_puerta_que_abrir(): void
    {
        // La puerta reconoce socios. Un entrenador cargado solo como ficha
        // profesional no existe para el lector, y eso no se tapa en silencio.
        $suelto = Trainer::create([
            'full_name' => 'Marta Sin Ficha',
            'document' => (string) random_int(10_000_000, 99_999_999),
            'email' => 'suelta-'.uniqid().'@ironbody.test',
            'status' => 'active',
        ]);

        $acuerdo = $this->acuerdo($suelto);
        $this->cobrar($acuerdo, ['method' => 'cash'])->assertStatus(201);

        $this->assertNull(app(TrainerCommissionAccess::class)->userOf($suelto));
        $this->assertSame(0, EmployeeAccess::count());
    }

    // ── Correr las fechas sin mover dinero ──────────────────────────────────

    public function test_se_corre_el_tramo_cuando_no_entrenaron_una_semana(): void
    {
        // Se pago el mes pero hubo un paron: los dias pagados no se gastaron.
        // Antes la unica salida era anular y volver a cobrar, que mueve dinero
        // que nadie movio y ensucia el arqueo de dos turnos.
        [$trainer, $user] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer, dias: 30);

        $cobroId = $this->cobrar($acuerdo, [
            'method' => 'cash',
            'covers_from' => $this->hoy()->toDateString(),
        ])->assertStatus(201)->json('charge.id');

        // El dia 31 ya no entraria…
        $this->assertFalse($this->puedeEntrar($user, $this->hoy()->addDays(30)->setTime(10, 0)));

        $this->patchJson("/api/admin/commissions/charges/{$cobroId}/coverage", [
            'cycle_days' => 37,
            'reason' => 'Una semana sin entrenar: el entrenador estuvo enfermo',
        ], $this->h)->assertOk()->assertJsonPath('charge.cycle_days', 37);

        // …y ahora si, siete dias mas.
        $this->assertTrue($this->puedeEntrar($user, $this->hoy()->addDays(30)->setTime(10, 0)));
        $this->assertSame(
            $this->hoy()->addDays(36)->toDateString(),
            EmployeeAccess::where('user_id', $user->id)->firstOrFail()->ends_on->toDateString(),
        );
    }

    public function test_correr_el_tramo_no_toca_el_dinero(): void
    {
        [$trainer] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer);
        $cobroId = $this->cobrar($acuerdo, [
            'method' => 'cash',
            'covers_from' => $this->hoy()->toDateString(),
        ])->assertStatus(201)->json('charge.id');

        $deuda = Receivable::firstOrFail();
        $abonosAntes = $deuda->payments()->count();

        $this->patchJson("/api/admin/commissions/charges/{$cobroId}/coverage", [
            'covers_from' => $this->hoy()->addDays(7)->toDateString(),
            'reason' => 'Empezaron una semana mas tarde',
        ], $this->h)->assertOk();

        $deuda->refresh();
        $this->assertSame($abonosAntes, $deuda->payments()->count());
        $this->assertTrue($deuda->balance()->isZero(), 'sigue pagado');
        $this->assertSame('50000.00', (string) $deuda->total_amount);
    }

    public function test_correr_el_tramo_exige_motivo(): void
    {
        [$trainer] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer);
        $cobroId = $this->cobrar($acuerdo, ['method' => 'cash'])->assertStatus(201)->json('charge.id');

        $this->patchJson("/api/admin/commissions/charges/{$cobroId}/coverage", [
            'cycle_days' => 45,
        ], $this->h)->assertStatus(422);
    }

    public function test_un_mes_anulado_no_se_corre(): void
    {
        [$trainer] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer);
        $cobroId = $this->cobrar($acuerdo, ['method' => 'cash'])->assertStatus(201)->json('charge.id');

        $this->postJson("/api/admin/commissions/charges/{$cobroId}/cancel", [
            'reason' => 'No iba',
        ], $this->h)->assertOk();

        $this->patchJson("/api/admin/commissions/charges/{$cobroId}/coverage", [
            'cycle_days' => 45, 'reason' => 'Da igual',
        ], $this->h)->assertStatus(422);
    }

    public function test_recepcion_no_alarga_los_dias_de_nadie(): void
    {
        // Alargar el tramo le da al entrenador mas dias de puerta por el mismo
        // dinero: eso es comercial, no mostrador.
        [$trainer] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer);
        $cobroId = $this->cobrar($acuerdo, ['method' => 'cash'])->assertStatus(201)->json('charge.id');

        $recepcion = $this->actingAsAdmin(\App\Models\Admin::create([
            'name' => 'Laura Mostrador',
            'email' => 'rec-tramo-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => \App\Models\Admin::ROLE_RECEPCION,
            'status' => 'active',
        ]));

        $this->patchJson("/api/admin/commissions/charges/{$cobroId}/coverage", [
            'cycle_days' => 90, 'reason' => 'Porque si',
        ], $recepcion)->assertStatus(403);
    }

    // ── Los días ────────────────────────────────────────────────────────────

    public function test_los_dias_se_pactan_en_el_trato(): void
    {
        [$trainer] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer, dias: 15);

        $this->assertSame(15, $acuerdo->refresh()->cycle_days);

        $cobro = $this->cobrar($acuerdo, [
            'method' => 'cash',
            'covers_from' => $this->hoy()->toDateString(),
        ])->assertStatus(201)->json('charge');

        $this->assertSame(15, $cobro['cycle_days']);
        $this->assertSame($this->hoy()->addDays(14)->toDateString(), $cobro['covers_to']);
    }

    public function test_y_se_pueden_cambiar_solo_para_un_cobro(): void
    {
        // Entró a mitad de mes: se le cobran siete días, y el trato sigue en 30.
        [$trainer] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer, dias: 30);

        $cobro = $this->cobrar($acuerdo, [
            'method' => 'cash',
            'cycle_days' => 7,
            'amount' => 12000,
            'covers_from' => $this->hoy()->toDateString(),
        ])->assertStatus(201)->json('charge');

        $this->assertSame(7, $cobro['cycle_days']);
        $this->assertSame($this->hoy()->addDays(6)->toDateString(), $cobro['covers_to']);
        $this->assertSame(30, $acuerdo->refresh()->cycle_days, 'el trato no se toca');
    }

    public function test_sin_decir_nada_el_tramo_arranca_el_primer_dia_del_mes(): void
    {
        [$trainer] = $this->entrenadorConFicha();
        $acuerdo = $this->acuerdo($trainer, dias: 30);

        $cobro = $this->cobrar($acuerdo, ['method' => 'cash'])->assertStatus(201)->json('charge');

        $primero = $this->hoy()->startOfMonth();
        $this->assertSame($primero->toDateString(), $cobro['covers_from']);
        $this->assertSame($primero->addDays(29)->toDateString(), $cobro['covers_to']);
    }
}
