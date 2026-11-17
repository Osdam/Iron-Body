<?php

namespace Tests\Feature\Trainer;

use App\Enums\CashShiftType;
use App\Http\Controllers\Api\TrainerController;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentReclassification;
use App\Models\Plan;
use App\Models\Trainer;
use App\Models\TrainerCommissionAgreement;
use App\Models\TrainerCommissionCharge;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\NotificationService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * BORRAR UN ENTRENADOR: O SE BORRA DE VERDAD, O NO SE AVISA NADA.
 *
 * EL FALLO (revisión de la reclasificación de cobros). DELETE /api/trainers/{id}
 * de un entrenador cuyo acuerdo de piso está enlazado a una reclasificación
 * respondía 500: la llave RESTRICT de la constancia chocaba con el borrado en
 * cascada del acuerdo. Y ANTES de ese borrado fallido ya se había creado el
 * aviso «Entrenador eliminado», que era falso.
 *
 * LO QUE QUEDA FIJADO
 *   · con una reclasificación enlazada (por el acuerdo o por su cobro), 409
 *     `trainer_has_reclassifications`, sin aviso y sin tocar nada;
 *   · si una se cuela entre la comprobación y el borrado, la violación de
 *     llave foránea también es 409 (`trainer_has_history`), sin aviso;
 *   · si el borrado falla por otra cosa, se deshace entero y no hay aviso;
 *   · el aviso sale DESPUÉS del commit, con el entrenador ya borrado;
 *   · sin nada de eso, se borra como siempre (204), con sus acuerdos.
 *
 * Las valoraciones siguen en ValuationTrainerDeletionTest.
 */
class TrainerDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const AVISO = 'Entrenador eliminado';

    private const MOTIVO_RECLASIFICACION = 'Este entrenador tiene un piso que salió de reclasificar un cobro, y esa '
        .'constancia contable no se borra. Desactívalo en lugar de eliminarlo.';

    private Admin $super;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        // El recibo y el borrador de factura del cobro original los exige la
        // reclasificación real, igual que en PaymentReclassificationTest.
        config([
            'billing.company.name' => 'IRON BODY NEIVA S.A.S.',
            'billing.company.nit' => '900123456',
            'billing.company.dv' => '7',
            'billing.company.address' => 'Calle 10 # 5-20, Neiva',
            'billing.company.phone' => '3001234567',
        ]);

        $this->super = Admin::create([
            'name' => 'Dueña Supervisión', 'email' => 'borrar-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);
        $this->cajero = Admin::create([
            'name' => 'Cajero Nueve', 'email' => 'cajero-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── El caso de la revisión ──────────────────────────────────────────────

    public function test_un_piso_reclasificado_no_deja_borrar_al_entrenador_ni_se_avisa(): void
    {
        [$entrenador, $constancia] = $this->reclasificacionAplicada();
        $this->assertSame($entrenador->id, (int) TrainerCommissionAgreement::findOrFail($constancia->agreement_id)->trainer_id,
            'la constancia apunta a un acuerdo de este entrenador');
        $antes = $this->huella();

        $this->borrar($entrenador)
            ->assertStatus(409)
            ->assertExactJson(['ok' => false, 'code' => 'trainer_has_reclassifications', 'message' => self::MOTIVO_RECLASIFICACION]);

        $this->assertSame($antes, $this->huella(), 'un 409 no toca nada');
        $this->assertSame(0, $this->avisos(), 'no se avisa «eliminado» de algo que sigue');
    }

    public function test_la_llave_del_cobro_tambien_impide_borrarlo(): void
    {
        app(CashShiftService::class)->open($this->super, CashShiftType::GYM);
        [$acuerdo, $entrenador, $socia] = $this->acuerdo();
        $this->postJson("/api/admin/commissions/agreements/{$acuerdo->id}/charge",
            ['period' => now()->format('Y-m'), 'method' => 'cash'], $this->actingAsAdmin($this->super))->assertStatus(201);
        $cobro = TrainerCommissionCharge::query()->where('agreement_id', $acuerdo->id)->sole();

        // Hoy la reclasificación enlaza el acuerdo y su cobro a la vez. La
        // llave del cobro existe por su cuenta (y también es RESTRICT): una
        // constancia que solo apunte al cobro tiene que impedirlo igual. Se
        // inserta, no se edita: la constancia es inmutable.
        $this->constancia($socia, chargeId: $cobro->id);
        $antes = $this->huella();

        $this->borrar($entrenador)
            ->assertStatus(409)
            ->assertJsonPath('code', 'trainer_has_reclassifications');

        $this->assertSame($antes, $this->huella());
        $this->assertSame(0, $this->avisos());
    }

    // ── Lo que la comprobación no ve ────────────────────────────────────────

    public function test_si_se_cuela_una_reclasificacion_antes_del_borrado_tambien_es_409_sin_aviso(): void
    {
        [$acuerdo, $entrenador, $socia] = $this->acuerdo();

        // Lo que haría otra sesión justo entre la comprobación y el DELETE:
        // reclasificar un cobro como piso de este acuerdo.
        Event::listen('eloquent.deleting: '.Trainer::class, function () use ($socia, $acuerdo): void {
            $this->constancia($socia, agreementId: $acuerdo->id);
        });
        $antes = $this->huella();

        $this->borrar($entrenador)
            ->assertStatus(409)
            ->assertExactJson(['ok' => false, 'code' => 'trainer_has_history',
                'message' => 'Este entrenador tiene historial que impide eliminarlo. Desactívalo en lugar de eliminarlo.']);

        $this->assertSame($antes, $this->huella(), 'el borrado se deshizo entero');
        $this->assertSame(0, $this->avisos());
    }

    public function test_si_el_borrado_falla_despues_se_deshace_entero_y_no_se_avisa(): void
    {
        [, $entrenador] = $this->acuerdo();
        // Lo que corre al borrar (el observador de perfiles laborales y lo que
        // escriba) va dentro de la transacción: si falla, no queda nada.
        Event::listen('eloquent.deleted: '.Trainer::class, function (): void {
            throw new RuntimeException('falla lo que corre al borrar');
        });
        $antes = $this->huella();

        $this->borrar($entrenador)->assertStatus(500);

        $this->assertNotNull(Trainer::query()->find($entrenador->id), 'el entrenador sigue');
        $this->assertSame($antes, $this->huella());
        $this->assertSame(0, $this->avisos());
    }

    public function test_un_entrenador_que_ya_no_existe_no_se_avisa_otra_vez(): void
    {
        $entrenador = $this->entrenador('Ya Borrado');
        // Otra petición lo borró después de resolver la ruta.
        DB::table('trainers')->where('id', $entrenador->id)->delete();

        $respuesta = TestResponse::fromBaseResponse(app(TrainerController::class)->destroy($entrenador));

        $respuesta->assertStatus(404)->assertJsonPath('ok', false);
        $this->assertSame(0, $this->avisos());
    }

    // ── Lo de siempre ───────────────────────────────────────────────────────

    public function test_sin_reclasificacion_se_borra_con_sus_acuerdos_y_se_avisa_una_vez_tras_el_commit(): void
    {
        app(CashShiftService::class)->open($this->super, CashShiftType::GYM);
        [$acuerdo, $entrenador] = $this->acuerdo();
        $this->postJson("/api/admin/commissions/agreements/{$acuerdo->id}/charge",
            ['period' => now()->format('Y-m'), 'method' => 'cash'], $this->actingAsAdmin($this->super))->assertStatus(201);
        $cobro = TrainerCommissionCharge::query()->where('agreement_id', $acuerdo->id)->sole();

        // Cuándo se pide el aviso. Se mira al LLAMAR y no al crear la fila:
        // `firstOrCreate` abre su propio punto de guardado y el nivel de ahí no
        // dice nada. El servicio real sigue creando el aviso (parent::).
        $alAvisar = null;
        $servicio = new class extends NotificationService
        {
            public ?Closure $alLlamar = null;

            public function notifyTrainerDeleted($trainer): void
            {
                ($this->alLlamar)();
                parent::notifyTrainerDeleted($trainer);
            }
        };
        $servicio->alLlamar = function () use (&$alAvisar, $entrenador): void {
            $alAvisar = [
                'entrenador_existe' => Trainer::query()->whereKey($entrenador->id)->exists(),
                'nivel_de_transaccion' => DB::transactionLevel(),
            ];
        };
        $this->app->instance(NotificationService::class, $servicio);
        $nivelBase = DB::transactionLevel();

        $this->borrar($entrenador)->assertNoContent();

        $this->assertNull(Trainer::query()->find($entrenador->id));
        $this->assertNull(TrainerCommissionAgreement::query()->find($acuerdo->id), 'sus acuerdos se van con él, como siempre');
        $this->assertNull(TrainerCommissionCharge::query()->find($cobro->id));

        $this->assertSame(1, $this->avisos());
        $this->assertSame(['entrenador_existe' => false, 'nivel_de_transaccion' => $nivelBase], $alAvisar,
            'el aviso sale con el borrado ya confirmado, fuera de su transacción');
        $aviso = Notification::query()->where('title', self::AVISO)->sole();
        $this->assertSame('Se eliminó al entrenador '.$entrenador->full_name.'.', $aviso->message);
        $this->assertSame($entrenador->id, (int) $aviso->metadata['trainer_id']);
    }

    // ────────────────────────────────────────────────────────────────────────

    private function borrar(Trainer $entrenador): TestResponse
    {
        // Con la llave MÍNIMA que el borrado exige.
        return $this->deleteJson("/api/trainers/{$entrenador->id}", [], $this->adminWithPermissions(['trainers.manage']));
    }

    private function avisos(): int
    {
        return Notification::query()->where('title', self::AVISO)->count();
    }

    /** Todo lo que un borrado rechazado no puede tocar. */
    private function huella(): array
    {
        $tablas = ['trainers', 'trainer_commission_agreements', 'trainer_commission_charges', 'payment_reclassifications',
            'receivables', 'receivable_payments', 'payments', 'notifications', 'trainer_audit_logs', 'trainer_roles',
            'trainer_reviews', 'trainer_tasks', 'identities', 'class_events'];
        $out = array_combine($tablas, array_map(fn (string $t) => DB::table($t)->count(), $tablas));
        $out['firma'] = md5(json_encode([
            DB::table('trainers')->orderBy('id')->get(['id', 'status', 'updated_at']),
            DB::table('trainer_commission_agreements')->orderBy('id')->get(['id', 'trainer_id', 'active', 'updated_at']),
            DB::table('payment_reclassifications')->orderBy('id')->get(['id', 'agreement_id', 'charge_id']),
        ]));

        return $out;
    }

    private function entrenador(string $nombre): Trainer
    {
        return Trainer::create([
            'full_name' => $nombre, 'document' => (string) random_int(10_000_000, 99_999_999),
            'email' => 'entrenador-'.uniqid().'@ironbody.test', 'status' => 'active',
        ]);
    }

    /** Un acuerdo de piso pactado por el endpoint real, sin reclasificación. */
    private function acuerdo(): array
    {
        $entrenador = $this->entrenador('Marta Entrenadora');
        $socia = $this->persona('Ana Ruiz', '52999888', '3105550009');
        $id = $this->postJson('/api/admin/commissions/agreements', [
            'trainer_id' => $entrenador->id, 'member_id' => $socia->id, 'amount' => 50000, 'payer' => 'member',
        ], $this->actingAsAdmin($this->super))->assertStatus(201)->json('id');

        return [TrainerCommissionAgreement::findOrFail($id), $entrenador, $socia];
    }

    /**
     * Una constancia de reclasificación escrita a mano, con un cobro propio:
     * para fijar estados que el procedimiento real no produce hoy (solo el
     * cobro) o que produciría otra sesión a la vez. Solo INSERT, como el
     * procedimiento.
     */
    private function constancia(Member $socia, ?int $agreementId = null, ?int $chargeId = null): void
    {
        $pagoId = DB::table('payments')->insertGetId([
            'user_id' => $socia->user_id, 'member_id' => $socia->id, 'amount' => 50000, 'method' => 'cash',
            'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payment_reclassifications')->insert([
            'payment_id' => $pagoId, 'kind' => PaymentReclassification::KIND_TRAINER_COMMISSION,
            'amount' => 50000, 'economic_at' => now(), 'agreement_id' => $agreementId, 'charge_id' => $chargeId,
            'reason' => 'Constancia de la prueba de borrado de entrenadores', 'performed_by_name' => 'Otra sesión',
            'performed_at' => now(), 'procedure' => 'prueba', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function persona(string $nombre, string $documento, string $telefono, ?string $plan = null): Member
    {
        $user = User::create([
            'name' => $nombre, 'email' => 'p-'.uniqid().'@ironbody.test', 'password' => 'secret-password',
            'status' => 'active', 'document' => $documento, 'phone' => $telefono, 'plan' => $plan,
        ]);

        return Member::create([
            'user_id' => $user->id, 'full_name' => $nombre, 'document_number' => $documento, 'phone' => $telefono,
            'status' => Member::STATUS_ACTIVE, 'access_hash' => 'tok-'.uniqid(),
        ]);
    }

    private function bogota(string $cuando): void
    {
        Carbon::setTestNow(Carbon::parse($cuando, 'America/Bogota')->utc());
    }

    private function cobrar(Member $socio, Plan $plan, string $medio, array $extra = []): Payment
    {
        $id = $this->postJson('/api/payments', array_merge([
            'user_id' => $socio->user_id, 'plan_id' => $plan->id, 'amount' => (float) $plan->price,
            'method' => $medio, 'status' => 'paid',
        ], $extra), $this->actingAsAdmin($this->cajero))->assertStatus(201)->json('id');

        return Payment::findOrFail($id);
    }

    /**
     * El caso real, reducido: el piso del entrenador se cobró en el mostrador
     * como el plan «Comisión de personalizados» y se reclasificó con el comando
     * de verdad, que da de alta al entrenador desde su ficha de socio y le
     * pacta el acuerdo al que apunta la constancia.
     *
     * @return array{0: Trainer, 1: PaymentReclassification}
     */
    private function reclasificacionAplicada(): array
    {
        $comision = Plan::create(['name' => 'Comisión de personalizados', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        $anual = Plan::create(['name' => 'Anualidad', 'price' => 600000, 'duration_days' => 365, 'active' => true]);
        Plan::create(['name' => 'ACCESO EMPLEADOS', 'price' => 1, 'duration_days' => 365, 'active' => true]);
        $socia = $this->persona('Olga Socia', '52111222', '3105550001');
        $ficha = $this->persona('Carlos Entrenador', '1075222333', '3105550002', 'ACCESO EMPLEADOS');

        $this->bogota('2026-11-06 06:00');
        app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);
        $this->bogota('2026-11-06 20:43');
        $pago = $this->cobrar($socia, $comision, 'cash');
        $this->bogota('2026-11-06 20:45');
        $this->cobrar($socia, $anual, 'card', ['starts_on' => '2026-11-06']);
        $this->bogota('2026-11-06 21:30');
        app(CashShiftService::class)->close($this->cajero, CashShiftType::GYM);
        $this->bogota('2026-11-17 10:00');

        $codigo = Artisan::call('payments:reclassify-to-commission', [
            '--payment' => $pago->id, '--member' => $socia->id, '--trainer-from-member' => $ficha->id,
            '--actor' => $this->super->id, '--reason' => 'Era el piso mensual del entrenador de la socia',
            '--json' => true, '--apply' => true,
        ]);
        $informe = json_decode(Artisan::output(), true);
        $this->assertSame(0, $codigo, Artisan::output());
        $this->assertSame('applied', $informe['status']);

        return [
            Trainer::findOrFail($informe['created']['trainer_id']),
            PaymentReclassification::findOrFail($informe['reclassification_id']),
        ];
    }
}
