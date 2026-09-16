<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El comando que limpia lo que el fallo ya dejó guardado.
 *
 * Reproduce el estado exacto que hay en producción —un cobro anulado que
 * conserva su periodo, y otro encadenado detrás— y comprueba que en seco no
 * toca nada y con `--apply` devuelve los días una sola vez.
 */
class RepairCancelledPaymentMembershipsTest extends TestCase
{
    use RefreshDatabase;

    private function hoy(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    /**
     * El daño tal cual quedó: dos cobros de 30 días encadenados, el primero
     * anulado sin devolver nada. La socia ve 60 días de un plan de 30.
     */
    private function socioDanado(): User
    {
        $plan = Plan::create(['name' => 'Élite', 'price' => 180000, 'duration_days' => 30, 'active' => true]);
        $inicio = $this->hoy()->subDays(5);

        $u = User::create([
            'name' => 'Socia dañada',
            'email' => 'danada-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
            'plan' => 'Élite',
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $inicio->addDays(60)->toDateString(),
        ]);

        Payment::create([
            'user_id' => $u->id, 'plan_id' => $plan->id, 'amount' => 180000,
            'method' => 'transfer', 'status' => 'cancelled', 'paid_at' => $inicio->addHours(10),
            'period_start' => $inicio->toDateString(), 'period_end' => $inicio->addDays(30)->toDateString(),
        ]);
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $plan->id, 'amount' => 180000,
            'method' => 'transfer', 'status' => 'paid', 'paid_at' => $inicio->addDays(2)->addHours(10),
            'period_start' => $inicio->addDays(30)->toDateString(), 'period_end' => $inicio->addDays(60)->toDateString(),
        ]);

        return $u;
    }

    public function test_en_seco_informa_y_no_guarda_nada(): void
    {
        $u = $this->socioDanado();
        $antes = $u->membership_end_date;

        $this->artisan('memberships:repair-cancelled')->assertSuccessful();

        $this->assertSame($antes, $u->refresh()->membership_end_date);
    }

    public function test_con_apply_devuelve_el_mes_fantasma(): void
    {
        $u = $this->socioDanado();
        $pagado = Payment::where('user_id', $u->id)->where('status', 'paid')->firstOrFail();

        $this->artisan('memberships:repair-cancelled --apply')->assertSuccessful();

        $u->refresh();
        // El cobro bueno entró dos días después del inicio, así que su mes
        // corre desde ahí: 27 días por delante, no 55.
        $this->assertSame($this->hoy()->subDays(3)->addDays(30)->toDateString(), $u->membership_end_date);
        $this->assertSame($this->hoy()->subDays(3)->toDateString(), $pagado->refresh()->period_start->toDateString());
    }

    public function test_correrlo_dos_veces_no_vuelve_a_restar(): void
    {
        $u = $this->socioDanado();

        $this->artisan('memberships:repair-cancelled --apply')->assertSuccessful();
        $reparado = $u->refresh()->membership_end_date;

        $this->artisan('memberships:repair-cancelled --apply')->assertSuccessful();

        $this->assertSame($reparado, $u->refresh()->membership_end_date);
    }

    public function test_solo_toca_al_socio_pedido(): void
    {
        $a = $this->socioDanado();
        $b = $this->socioDanado();
        $finB = $b->membership_end_date;

        $this->artisan("memberships:repair-cancelled --apply --user={$a->id}")->assertSuccessful();

        $this->assertNotSame($finB, $a->refresh()->membership_end_date);
        $this->assertSame($finB, $b->refresh()->membership_end_date);
    }
}
