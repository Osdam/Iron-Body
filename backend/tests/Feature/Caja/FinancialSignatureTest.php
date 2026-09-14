<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Http\Controllers\Api\Admin\EarningsController;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\ReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La firma del canal financiero: qué hace que una pantalla abierta se entere.
 *
 * LO QUE SE PROTEGE. El CRM en tiempo real no recibe el dato, recibe una SEÑAL:
 * cuando la firma cambia, cada pantalla vuelve a preguntar al servidor. Si una
 * tabla queda fuera de la firma, el fallo es silencioso y de la peor clase —el
 * backend registra bien el dinero y la pantalla sigue enseñando el número
 * anterior— y solo se descubre cambiando de módulo y volviendo.
 *
 * Era exactamente lo que pasaba en Caja Productos: la caja marcaba 3.000,
 * alguien cobraba un crédito de 3.000 y el total seguía en 3.000.
 */
class FinancialSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Admin $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cajero = Admin::create([
            'name' => 'Ana Recepción',
            'email' => 'firma-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
    }

    private function firma(): string
    {
        return EarningsController::financialSignature();
    }

    private function socio(): Member
    {
        $user = User::create([
            'name' => 'Alejandro Casas',
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'plan' => 'Premium',
            'membershipEndDate' => now()->addDays(30)->toDateString(),
        ]);

        return Member::create([
            'user_id' => $user->id,
            'full_name' => 'Alejandro Casas',
            'document_number' => (string) random_int(10000000, 99999999),
            'access_hash' => 'tok-'.uniqid(),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function deuda(Member $socio, float $total = 80000): Receivable
    {
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

    public function test_cobrar_un_abono_mueve_la_firma(): void
    {
        // EL CASO DEL QA: se cobra un crédito y la caja tiene que enterarse.
        $cuenta = $this->deuda($this->socio());
        $antes = $this->firma();

        app(ReceivableService::class)->settle(
            receivable: $cuenta, amount: Money::fromAmount(3000), method: 'cash', actor: $this->cajero,
        );

        $this->assertNotSame($antes, $this->firma(),
            'Sin esto, la caja sigue enseñando el total anterior hasta cambiar de módulo.');
    }

    public function test_anular_un_abono_mueve_la_firma(): void
    {
        $cuenta = $this->deuda($this->socio());
        app(ReceivableService::class)->settle(
            receivable: $cuenta, amount: Money::fromAmount(3000), method: 'cash', actor: $this->cajero,
        );
        $abono = $cuenta->payments()->firstOrFail();
        $antes = $this->firma();

        app(ReceivableService::class)->reverse($abono, 'Se digitó el importe equivocado.', $this->cajero);

        $this->assertNotSame($antes, $this->firma());
    }

    public function test_abrir_una_deuda_mueve_la_firma(): void
    {
        $socio = $this->socio();
        $antes = $this->firma();

        $this->deuda($socio);

        $this->assertNotSame($antes, $this->firma());
    }

    public function test_cambiar_el_plazo_mueve_la_firma_sin_mover_un_peso(): void
    {
        // No entra ni sale dinero, pero la pantalla estaría diciendo «al día»
        // sobre una deuda que acaba de vencer.
        $cuenta = $this->deuda($this->socio());
        $antes = $this->firma();

        app(ReceivableService::class)->changeDueDate(
            $cuenta, Receivable::businessToday()->subDay(), 'Se corrige la fecha pactada.', $this->cajero,
        );

        $this->assertNotSame($antes, $this->firma());
    }

    public function test_anular_una_deuda_mueve_la_firma(): void
    {
        $cuenta = $this->deuda($this->socio());
        $antes = $this->firma();

        app(ReceivableService::class)->cancel($cuenta, 'Se cobró por error de mostrador.', $this->cajero);

        $this->assertNotSame($antes, $this->firma());
    }

    public function test_un_cobro_de_membresia_mueve_la_firma(): void
    {
        $user = User::create([
            'name' => 'Contado', 'email' => 'contado-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
        ]);
        $antes = $this->firma();

        Payment::create([
            'user_id' => $user->id, 'amount' => 50000, 'method' => 'cash',
            'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->assertNotSame($antes, $this->firma());
    }

    public function test_una_venta_de_cafeteria_mueve_la_firma(): void
    {
        Product::create(['name' => 'Agua', 'sale_price' => 3000, 'cost_price' => 1000, 'stock' => 10]);
        $antes = $this->firma();

        ProductSale::create([
            'uuid' => (string) Str::uuid(), 'code' => 'V-'.uniqid(), 'channel' => 'pos',
            'status' => 'paid', 'payment_method' => 'cash',
            'subtotal' => 3000, 'total' => 3000, 'paid_at' => now(),
        ]);

        $this->assertNotSame($antes, $this->firma());
    }

    public function test_abrir_una_caja_mueve_la_firma(): void
    {
        // Tampoco mueve un peso, pero quien tiene Caja abierta en otra pestaña
        // seguiría viéndola cerrada después de que un compañero la abra.
        $antes = $this->firma();

        app(CashShiftService::class)->open($this->cajero, CashShiftType::PRODUCTS);

        $this->assertNotSame($antes, $this->firma());
    }

    public function test_cerrar_una_caja_mueve_la_firma(): void
    {
        app(CashShiftService::class)->open($this->cajero, CashShiftType::PRODUCTS);
        $antes = $this->firma();

        app(CashShiftService::class)->close($this->cajero, CashShiftType::PRODUCTS);

        $this->assertNotSame($antes, $this->firma());
    }

    public function test_sin_movimientos_la_firma_no_cambia(): void
    {
        // Si la firma cambiara sola, cada latido despertaría a todas las
        // pantallas abiertas del gimnasio para no enseñar nada nuevo.
        $this->deuda($this->socio());

        $this->assertSame($this->firma(), $this->firma());
    }
}
