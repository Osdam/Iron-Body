<?php

namespace Tests\Feature\Members;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SOCIOS CON FECHAS QUE NO PUEDEN SER.
 *
 * El caso real: Alejandra tiene su anualidad pagada y el perfil dice que su
 * membresía empieza el 1 de marzo de 2027, así que la puerta no la deja
 * entrar. La fecha no salió de su anualidad: salió de una «Comisión de
 * personalizados» que el sistema anterior tenía cargada como un plan de
 * treinta días, y el importador copia las fechas de la fila que termina más
 * tarde.
 *
 * Lo que estos tests fijan:
 *   · se encuentran los que empiezan en el futuro y los que vencen dentro de
 *     años, que son dos síntomas del mismo origen;
 *   · la vigencia que se propone sale del último pago REAL, nunca de una
 *     comisión;
 *   · sin --fix no se toca una sola fila. Esto corre sobre gente.
 */
class FutureDatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function hoy(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    private function socio(string $nombre, array $attrs): User
    {
        return User::create(array_merge([
            'name' => $nombre,
            'email' => strtolower(str_replace(' ', '.', $nombre)).'-'.uniqid().'@example.com',
            'password' => 'secret',
            'status' => 'active',
        ], $attrs));
    }

    private function pago(User $u, Plan $p, CarbonImmutable $desde, ?CarbonImmutable $hasta = null): Payment
    {
        return Payment::create([
            'user_id' => $u->id,
            'plan_id' => $p->id,
            'amount' => $p->price,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => $desde->setTime(9, 0),
            'period_start' => $desde->toDateString(),
            'period_end' => ($hasta ?? $desde->addDays((int) $p->duration_days))->toDateString(),
        ]);
    }

    /** @return array{0: Plan, 1: Plan} [anualidad, comisión] */
    private function planes(): array
    {
        return [
            Plan::create(['name' => 'Anualidad', 'price' => 900000, 'duration_days' => 365, 'active' => true]),
            Plan::create(['name' => 'Comisión de personalizados', 'price' => 50000, 'duration_days' => 30, 'active' => true]),
        ];
    }

    public function test_encuentra_al_socio_cuya_membresia_empieza_en_el_futuro(): void
    {
        $this->socio('Alejandra Palacios', [
            'plan' => 'Comisión de personalizados',
            'membership_start_date' => '2027-03-01',
            'membership_end_date' => '2027-03-01',
        ]);
        $this->socio('Socio Normal', [
            'plan' => 'Anualidad',
            'membership_start_date' => $this->hoy()->subDays(10)->toDateString(),
            'membership_end_date' => $this->hoy()->addDays(80)->toDateString(),
        ]);

        $this->artisan('members:future-dates')
            ->expectsOutputToContain('Alejandra')
            ->doesntExpectOutputToContain('Socio Normal')
            ->assertSuccessful();
    }

    public function test_tambien_encuentra_las_vigencias_que_terminan_dentro_de_anos(): void
    {
        $this->socio('Vence Lejísimos', [
            'plan' => 'Comisión de personalizados',
            'membership_start_date' => $this->hoy()->subDays(5)->toDateString(),
            'membership_end_date' => $this->hoy()->addYears(2)->toDateString(),
        ]);

        $this->artisan('members:future-dates')
            ->expectsOutputToContain('Vence')
            ->assertSuccessful();
    }

    public function test_la_vigencia_que_propone_sale_del_pago_real_y_no_de_la_comision(): void
    {
        [$anual, $comision] = $this->planes();
        $inicio = $this->hoy()->subDays(100);

        $user = $this->socio('Alejandra Palacios', [
            'plan' => 'Comisión de personalizados',
            'membership_start_date' => '2027-03-01',
            'membership_end_date' => '2027-03-01',
        ]);

        // Su anualidad de verdad…
        $this->pago($user, $anual, $inicio);
        // …y la comisión, que es la que le rompió las fechas y es MÁS reciente.
        $this->pago($user, $comision, CarbonImmutable::parse('2027-03-01'));

        $this->artisan('members:future-dates')
            ->expectsOutputToContain($inicio->toDateString())
            ->assertSuccessful();

        // Y sin --fix no se tocó nada.
        $this->assertSame('2027-03-01', substr((string) $user->refresh()->membership_start_date, 0, 10));
    }

    public function test_con_fix_le_devuelve_la_vigencia_de_su_anualidad(): void
    {
        [$anual, $comision] = $this->planes();
        $inicio = $this->hoy()->subDays(100);

        $user = $this->socio('Alejandra Palacios', [
            'plan' => 'Comisión de personalizados',
            'membership_start_date' => '2027-03-01',
            'membership_end_date' => '2027-03-01',
        ]);
        $this->pago($user, $anual, $inicio);
        $this->pago($user, $comision, CarbonImmutable::parse('2027-03-01'));

        $this->artisan('members:future-dates', ['--fix' => true])->assertSuccessful();

        $user->refresh();
        $this->assertSame($inicio->toDateString(), substr((string) $user->membership_start_date, 0, 10));
        $this->assertSame($inicio->addDays(365)->toDateString(), substr((string) $user->membership_end_date, 0, 10));
        // Y el plan deja de ser una comisión.
        $this->assertSame('Anualidad', $user->plan);
    }

    public function test_a_quien_no_tiene_ningun_pago_real_no_se_le_inventa_nada(): void
    {
        [, $comision] = $this->planes();

        $user = $this->socio('Solo Comisiones', [
            'plan' => 'Comisión de personalizados',
            'membership_start_date' => '2027-03-01',
            'membership_end_date' => '2027-03-01',
        ]);
        $this->pago($user, $comision, CarbonImmutable::parse('2027-03-01'));

        $this->artisan('members:future-dates', ['--fix' => true])->assertSuccessful();

        // Se queda como estaba: hay que mirarlo a mano, no adivinarlo.
        $this->assertSame('2027-03-01', substr((string) $user->refresh()->membership_start_date, 0, 10));
    }

    public function test_sin_nadie_raro_lo_dice_y_ya(): void
    {
        $this->socio('Socio Normal', [
            'plan' => 'Anualidad',
            'membership_start_date' => $this->hoy()->subDays(10)->toDateString(),
            'membership_end_date' => $this->hoy()->addDays(80)->toDateString(),
        ]);

        $this->artisan('members:future-dates')
            ->expectsOutputToContain('Ninguno')
            ->assertSuccessful();
    }

    public function test_se_puede_revisar_a_una_sola_persona(): void
    {
        [$anual] = $this->planes();
        $user = $this->socio('Alejandra Palacios', [
            'plan' => 'Anualidad',
            'membership_start_date' => $this->hoy()->subDays(10)->toDateString(),
            'membership_end_date' => $this->hoy()->addDays(80)->toDateString(),
        ]);
        $this->pago($user, $anual, $this->hoy()->subDays(10));

        // Aunque sus fechas estén bien: --user lo mira igualmente.
        $this->artisan('members:future-dates', ['--user' => $user->id])
            ->expectsOutputToContain('Alejandra')
            ->assertSuccessful();
    }
}
