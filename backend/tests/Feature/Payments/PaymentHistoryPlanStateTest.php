<?php

namespace Tests\Feature\Payments;

use App\Models\Admin;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El plan de cada cobro en GET /api/admin/users/{user}/payment-history:
 * `{id, name, duration_days, active}`. El CRM (ficha del socio e informe de
 * miembros) pinta «(histórico)» cuando `active === false`. Se fija la forma
 * EXACTA para que nadie añada o quite un campo sin que esto lo diga.
 */
class PaymentHistoryPlanStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_plan_del_cobro_dice_si_esta_activo_y_no_cambia_ningun_otro_campo(): void
    {
        $h = $this->actingAsAdmin(Admin::create(['name' => 'Caja', 'email' => 'h-'.uniqid().'@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']));
        $vivo = Plan::create(['name' => 'Élite', 'price' => 180000, 'duration_days' => 30, 'active' => true]);
        $historico = Plan::create(['name' => 'TOTAL ACCESS - ELITE', 'price' => 180000, 'duration_days' => 30, 'active' => true]);
        $socio = User::create(['name' => 'Socia', 'email' => 's-'.uniqid().'@ironbody.test', 'password' => 'x', 'status' => 'active']);
        $pago = fn (?int $plan, string $cuando) => Payment::create(['user_id' => $socio->id, 'plan_id' => $plan, 'amount' => 180000,
            'method' => 'cash', 'status' => 'paid', 'paid_at' => $cuando]);
        $pago($vivo->id, '2026-10-20 10:00:00');
        $pago($historico->id, '2026-10-10 10:00:00');
        $pago(null, '2026-10-01 10:00:00');
        $this->postJson("/api/plans/{$historico->id}/archive", ['reason' => 'Plan heredado'], $h)->assertOk();

        $planes = array_column($this->getJson("/api/admin/users/{$socio->id}/payment-history", $h)->assertOk()->json('payments'), 'plan');

        $this->assertSame([
            ['id' => $vivo->id, 'name' => 'Élite', 'duration_days' => 30, 'active' => true],
            ['id' => $historico->id, 'name' => 'TOTAL ACCESS - ELITE', 'duration_days' => 30, 'active' => false],
            null,
        ], $planes);

        // Inactivo a la antigua (sin archivo) también sale como `active: false`.
        DB::table('plans')->where('id', $vivo->id)->update(['active' => false]);
        $this->getJson("/api/admin/users/{$socio->id}/payment-history", $h)->assertOk()->assertJsonPath('payments.0.plan.active', false);
    }
}
