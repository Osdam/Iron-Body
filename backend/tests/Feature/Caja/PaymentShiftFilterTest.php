<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtrar los cobros de un turno de caja concreto.
 *
 * Las ventas de productos ya se podían filtrar por `shift_id`; los cobros del
 * gimnasio no, aunque llevan `cash_shift_id` desde que existe la doble caja. Esa
 * asimetría dejaba el detalle de un cierre de Gimnasio fuera del alcance de la
 * API: el dinero estaba vinculado y no había forma de listarlo.
 */
class PaymentShiftFilterTest extends TestCase
{
    use RefreshDatabase;

    private array $turnos = [];

    /** Turnos reales: `cash_shift_id` tiene clave foránea. */
    private function turno(string $etiqueta, CashShiftType $type = CashShiftType::GYM): int
    {
        return $this->turnos[$etiqueta] ??= CashShift::create([
            'type' => $type->value, 'status' => 'closed',
            'opened_by' => $this->responsable()->id, 'opened_by_name' => 'Recepción',
            'opened_at' => now()->subHours(4), 'opening_amount' => 0,
            'closed_by' => $this->responsable()->id, 'closed_by_name' => 'Recepción',
            'closed_at' => now(), 'sales_total' => 0, 'operations_count' => 0,
        ])->id;
    }

    private function responsable(): Admin
    {
        return $this->admin ??= Admin::create([
            'name' => 'Responsable', 'email' => 'r-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active',
        ]);
    }

    private ?Admin $admin = null;

    private function cobro(?int $turno, float $monto = 50000): Payment
    {
        return Payment::create([
            'user_id' => User::factory()->create()->id,
            'amount' => $monto,
            'method' => 'cash',
            'status' => 'paid',
            'cash_shift_id' => $turno,
        ]);
    }

    public function test_devuelve_solo_los_cobros_de_ese_turno(): void
    {
        $a = $this->cobro($this->turno('A'), 1000);
        $this->cobro($this->turno('B'), 2000);
        $this->cobro(null, 3000);

        $res = $this->getJson('/api/payments?cash_shift_id='.$this->turno('A'), $this->adminHeaders())->assertOk();

        $ids = array_column($res->json('data'), 'id');
        $this->assertSame([$a->id], $ids);
    }

    public function test_sin_filtro_el_comportamiento_no_cambia(): void
    {
        // Incluidos los de pasarela y los históricos, que tienen turno nulo por
        // diseño y no deben desaparecer al añadir un filtro opcional.
        $this->cobro($this->turno('A'));
        $this->cobro(null);

        $res = $this->getJson('/api/payments', $this->adminHeaders())->assertOk();

        $this->assertCount(2, $res->json('data'));
    }

    public function test_un_turno_sin_cobros_devuelve_vacio_no_todos(): void
    {
        $this->cobro($this->turno('A'));

        $res = $this->getJson('/api/payments?cash_shift_id=999999', $this->adminHeaders())->assertOk();

        $this->assertSame([], $res->json('data'));
    }

    public function test_conserva_la_paginacion(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->cobro($this->turno('A'));
        }

        $res = $this->getJson('/api/payments?cash_shift_id='.$this->turno('A').'&per_page=2', $this->adminHeaders())->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame(5, $res->json('total'));
    }

    public function test_el_filtro_sigue_exigiendo_permiso(): void
    {
        $this->cobro($this->turno('A'));

        $this->getJson('/api/payments?cash_shift_id='.$this->turno('A'))->assertUnauthorized();
    }
}
