<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Exceptions\ReceivableException;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\ReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dos cajeros sobre la misma deuda.
 *
 * LO QUE SE PROTEGE. Dos mostradores con la misma pantalla abierta pueden leer
 * «saldo 50.000» a la vez y abonar 50.000 cada uno: las dos comprobaciones pasan
 * y la cuenta acaba en -50.000. Lo mismo entre cobrar y anular: si el cobro
 * final entra mientras alguien anula, no puede quedar el dinero recibido Y la
 * deuda anulada con saldo.
 *
 * LO QUE AQUÍ NO SE PUEDE PROBAR, Y POR QUÉ. El bloqueo de fila
 * (`lockForUpdate`) es lo que serializa esas dos peticiones, y su efecto NO es
 * observable en esta suite: SQLite serializa las escrituras de todas formas y
 * los tests corren en un solo proceso. Quitar el bloqueo no rompe ni una
 * prueba. Así que se comprueban las DOS cosas por separado:
 *
 *   · que el bloqueo está en la consulta —lo único demostrable aquí—, y
 *   · que el servicio REVALIDA contra la base en vez de fiarse del modelo que
 *     le pasaron, que es lo que hace que el segundo cajero vea el saldo nuevo.
 *
 * Un bloqueo sin revalidación no serviría de nada: bloquear la fila y luego
 * calcular sobre el saldo que traía el objeto en memoria daría el mismo
 * sobregiro, solo que más despacio.
 */
class ReceivableConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Recepción',
            'email' => 'conc-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    private function deuda(float $total = 100000): Receivable
    {
        $user = User::create([
            'name' => 'Alejandro Casas',
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'plan' => 'Premium',
            'membershipEndDate' => now()->addDays(30)->toDateString(),
        ]);
        $socio = Member::create([
            'user_id' => $user->id,
            'full_name' => 'Alejandro Casas',
            'document_number' => (string) random_int(10000000, 99999999),
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);

        app(CashShiftService::class)->open($this->cajero, CashShiftType::GYM);

        return app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER,
            debtorId: $socio->id,
            type: CashShiftType::GYM,
            concept: 'Plan Premium',
            total: Money::fromAmount($total),
            actor: $this->cajero,
        );
    }

    private function servicio(): ReceivableService
    {
        return app(ReceivableService::class);
    }

    /** Lo que el otro cajero ya escribió, sin que este se entere. */
    private function abonoAjeno(Receivable $cuenta, float $monto): void
    {
        $antes = $cuenta->fresh()->balance()->toFloat();
        ReceivablePayment::create([
            'receivable_id' => $cuenta->id, 'amount' => $monto, 'method' => 'cash',
            'cash_shift_id' => null, 'balance_before' => $antes, 'balance_after' => $antes - $monto,
            'status' => ReceivablePayment::STATUS_APPLIED,
        ]);
        Receivable::whereKey($cuenta->id)->update(['status' => $cuenta->fresh()->statusForBalance()]);
    }

    // ── Revalidación: el objeto en memoria no manda ─────────────────────────

    public function test_el_segundo_cajero_no_puede_abonar_sobre_un_saldo_viejo(): void
    {
        $cuenta = $this->deuda(100000);

        // Los dos leyeron «saldo 100.000». El primero cobra 60.000.
        $comoLoViaElSegundo = Receivable::findOrFail($cuenta->id);
        $this->abonoAjeno($cuenta, 60000);

        // El segundo intenta cobrar los 100.000 que su pantalla seguía
        // enseñando. Sin revalidar, la cuenta acabaría en -60.000.
        $this->expectException(ReceivableException::class);
        try {
            $this->servicio()->settle(
                receivable: $comoLoViaElSegundo,
                amount: Money::fromAmount(100000),
                method: 'cash',
                actor: $this->cajero,
            );
        } catch (ReceivableException $e) {
            $this->assertSame('overpayment', $e->code_);
            // Y el mensaje lleva el saldo REAL, no el que traía su pantalla:
            // quien está con el socio delante necesita saber cuánto cobrar.
            $this->assertStringContainsString('40.000', $e->getMessage());
            $this->assertEqualsWithDelta(40000, $cuenta->fresh()->balance()->toFloat(), 0.01);

            throw $e;
        }
    }

    public function test_el_saldo_nunca_queda_en_negativo(): void
    {
        $cuenta = $this->deuda(100000);
        $viejo = Receivable::findOrFail($cuenta->id);
        $this->abonoAjeno($cuenta, 100000);

        // La cuenta ya está saldada; el segundo cobro se rechaza entero.
        try {
            $this->servicio()->settle(
                receivable: $viejo, amount: Money::fromAmount(50000), method: 'cash', actor: $this->cajero,
            );
            $this->fail('Abonar sobre una cuenta saldada no puede aceptarse.');
        } catch (ReceivableException $e) {
            $this->assertSame('already_settled', $e->code_);
        }

        $this->assertEqualsWithDelta(0, $cuenta->fresh()->balance()->toFloat(), 0.01);
        $this->assertFalse($cuenta->fresh()->balance()->isNegative());
    }

    public function test_anular_pierde_contra_el_cobro_que_entro_primero(): void
    {
        $cuenta = $this->deuda(100000);
        $viejo = Receivable::findOrFail($cuenta->id);

        // El cobro final entra mientras alguien tenía abierta la anulación.
        $this->abonoAjeno($cuenta, 100000);

        try {
            $this->servicio()->cancel($viejo, 'Se anula por acuerdo con el socio.', $this->cajero);
            $this->fail('No se puede anular una deuda que acaban de pagar.');
        } catch (ReceivableException $e) {
            $this->assertSame('receivable_already_paid', $e->code_);
        }

        // No puede quedar el dinero recibido Y la deuda anulada.
        $this->assertSame(Receivable::STATUS_PAID, $cuenta->fresh()->status);
        $this->assertFalse($cuenta->fresh()->isCancelled());
    }

    public function test_cobrar_pierde_contra_la_anulacion_que_entro_primero(): void
    {
        $cuenta = $this->deuda(100000);
        $viejo = Receivable::findOrFail($cuenta->id);

        $this->servicio()->cancel($cuenta, 'Se anula: la venta era de otra persona.', $this->cajero);

        try {
            $this->servicio()->settle(
                receivable: $viejo, amount: Money::fromAmount(50000), method: 'cash', actor: $this->cajero,
            );
            $this->fail('No se puede abonar sobre una deuda anulada.');
        } catch (ReceivableException $e) {
            $this->assertSame('receivable_cancelled', $e->code_);
        }

        $this->assertSame(0, ReceivablePayment::where('receivable_id', $cuenta->id)->count());
    }

    public function test_el_detector_no_avisa_de_una_mora_que_acaban_de_pagar(): void
    {
        $cuenta = $this->deuda(100000);
        $cuenta->update(['due_at' => Receivable::businessToday()->subDay()->toDateString()]);

        // Entre que el detector selecciona la cuenta y llega a emitir, entra el
        // pago por caja. Revalida con la fila bloqueada y no avisa.
        $this->abonoAjeno($cuenta, 100000);
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        $this->assertDatabaseMissing('automation_events', ['event_type' => 'receivable.overdue']);
    }

    public function test_el_detector_no_avisa_de_una_deuda_que_acaban_de_anular(): void
    {
        $cuenta = $this->deuda(100000);
        $cuenta->update(['due_at' => Receivable::businessToday()->subDay()->toDateString()]);

        $this->servicio()->cancel($cuenta->fresh(), 'Se anula: se cobró por error.', $this->cajero);
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        $this->assertDatabaseMissing('automation_events', ['event_type' => 'receivable.overdue']);
    }

    public function test_abonar_dos_veces_con_la_misma_peticion_cobra_una_sola_vez(): void
    {
        $cuenta = $this->deuda(100000);
        $llave = 'doble-clic-'.uniqid();

        $uno = $this->servicio()->settle(
            receivable: $cuenta, amount: Money::fromAmount(30000), method: 'cash',
            actor: $this->cajero, clientRequestId: $llave,
        );
        $dos = $this->servicio()->settle(
            receivable: $cuenta->fresh(), amount: Money::fromAmount(30000), method: 'cash',
            actor: $this->cajero, clientRequestId: $llave,
        );

        $this->assertSame($uno->id, $dos->id, 'El reintento devuelve el abono que ya existía.');
        $this->assertSame(1, ReceivablePayment::where('receivable_id', $cuenta->id)->count());
        $this->assertEqualsWithDelta(70000, $cuenta->fresh()->balance()->toFloat(), 0.01);
    }

    public function test_anular_dos_veces_deja_una_sola_anulacion(): void
    {
        $cuenta = $this->deuda(100000);

        $this->servicio()->cancel($cuenta, 'Primera anulación, la buena.', $this->cajero);
        $primera = $cuenta->fresh()->cancelled_at;
        $this->servicio()->cancel($cuenta->fresh(), 'Segunda, que no debe hacer nada.', $this->cajero);

        $this->assertSame($primera->toIso8601String(), $cuenta->fresh()->cancelled_at->toIso8601String());
        $this->assertStringContainsString('la buena', (string) $cuenta->fresh()->cancellation_reason);
    }

    // ── El bloqueo, lo único demostrable aquí ───────────────────────────────

    public function test_las_operaciones_bloquean_la_fila_antes_de_decidir(): void
    {
        // Se comprueba sobre el CÓDIGO y no sobre la consulta ejecutada porque
        // el motor de los tests no lo deja ver: la gramática de SQLite compila
        // `lockForUpdate()` a nada —no existe `FOR UPDATE`— y en PostgreSQL, que
        // es lo que corre en producción, sí es la cláusula que serializa a dos
        // mostradores. Quitarla no rompe ninguna otra prueba de esta suite, y
        // por eso hace falta esta.
        $fuente = file_get_contents(app_path('Services/Caja/ReceivableService.php'));

        foreach ([
            'settle' => 'abonar',
            'reverse' => 'anular un abono',
            'cancel' => 'anular la deuda',
            'reopen' => 'reabrirla',
            'changeDueDate' => 'cambiar el plazo',
        ] as $metodo => $que) {
            $inicio = strpos($fuente, "public function {$metodo}(");
            $this->assertNotFalse($inicio, "No se encontró {$metodo}().");

            // Hasta la siguiente declaración de método: el cuerpo completo.
            $siguiente = strpos($fuente, "\n    public function ", $inicio + 10);
            $siguiente = $siguiente === false
                ? strpos($fuente, "\n    private function ", $inicio + 10)
                : $siguiente;
            $cuerpo = substr($fuente, $inicio, ($siguiente ?: strlen($fuente)) - $inicio);

            $this->assertStringContainsString('lockForUpdate()', $cuerpo,
                "«{$que}» tiene que leer la fila BLOQUEADA: sin eso, dos mostradores ".
                'deciden a la vez sobre el mismo saldo.');
            $this->assertStringContainsString('DB::transaction', $cuerpo,
                "«{$que}» tiene que ir en una transacción: un bloqueo fuera de ella no bloquea nada.");
        }
    }
}
