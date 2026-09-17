<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** El comando de diagnóstico lee y no rompe: se corre sobre producción. */
class InspectMemberMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_muestra_la_membresia_y_sus_pagos_sin_tocar_nada(): void
    {
        $plan = Plan::create(['name' => 'Élite', 'price' => 180000, 'duration_days' => 30, 'active' => true]);
        $u = User::create([
            'name' => 'Socia', 'email' => 'inspect@example.com', 'password' => 'secret',
            'status' => 'active', 'plan' => 'Élite', 'document' => '42122926',
            'membership_start_date' => '2026-09-12', 'membership_end_date' => '2026-11-11',
        ]);
        Payment::create([
            'user_id' => $u->id, 'plan_id' => $plan->id, 'amount' => 180000,
            'method' => 'transfer', 'status' => 'cancelled', 'paid_at' => '2026-09-12 16:24:00',
            'period_start' => '2026-09-12', 'period_end' => '2026-10-12',
        ]);
        // Un pago sin fecha de cobro: no debe reventar la tabla.
        Payment::create([
            'user_id' => $u->id, 'amount' => 25000, 'method' => 'cash', 'status' => 'pending',
        ]);

        $this->artisan('memberships:inspect '.$u->id)->assertSuccessful();
        $this->artisan('memberships:inspect inspect@example.com')->assertSuccessful();
        // Una cédula es solo dígitos, igual que un id: tiene que encontrarse.
        $this->artisan('memberships:inspect 42122926')
            ->expectsOutputToContain('Socia')
            ->assertSuccessful();
        $this->artisan('memberships:inspect nadie@example.com')->assertFailed();

        $this->assertSame('2026-11-11', $u->refresh()->membership_end_date);
        $this->assertSame('2026-10-12', Payment::whereNotNull('period_end')->first()->period_end->toDateString());
    }
}
