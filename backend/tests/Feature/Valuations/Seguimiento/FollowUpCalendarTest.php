<?php

namespace Tests\Feature\Valuations\Seguimiento;

use App\Models\Admin;
use App\Services\Valuations\ValuationFollowUps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EL CALENDARIO DEL PRÓXIMO CONTROL (contrato §3): días de calendario de
 * Bogotá desde la fecha de la última valoración, calculado al leer.
 *
 * Contra la clase real ({@see ValuationFollowUps}) y por la API: el número que
 * ve el CRM es el que sale de aquí. El reloj, en UTC (como el servidor).
 */
class FollowUpCalendarTest extends TestCase
{
    use RefreshDatabase;
    use SeguimientoFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function intervalos(): array
    {
        return [
            '15 días' => [15, '2026-12-03'],
            '20 días' => [20, '2026-12-08'],
            '30 días' => [30, '2026-12-18'],
            '60 días' => [60, '2027-01-17'],
            '90 días' => [90, '2027-02-16'],
            'personalizado 1' => [1, '2026-11-19'],
            'personalizado 45' => [45, '2027-01-02'],
            'personalizado 365' => [365, '2027-11-18'],
        ];
    }

    #[DataProvider('intervalos')]
    public function test_intervalos_desde_el_18_de_noviembre_por_la_api(int $dias, string $esperada): void
    {
        $this->clock('2026-11-18 12:00');
        $andres = $this->trainer();
        $laura = $this->member('Laura Gómez');
        $this->valoracion($laura, $andres, '2026-11-18 10:40');
        $h = $this->reception();

        $this->cambiar($h, $laura, ['interval_days' => $dias])->assertOk()
            ->assertJsonPath('data.next_suggested_on', $esperada)
            ->assertJsonPath('data.interval.days', $dias)
            ->assertJsonPath('data.interval.source', 'member');

        $r = $this->seguimiento($h, $laura)->assertOk();
        $this->assertSame($esperada, $r->json('data.next_suggested_on'));
        $this->assertSame(ValuationFollowUps::daysBetween('2026-11-18', $esperada), $r->json('data.days_until_due'));
        $this->assertSame($dias, $r->json('data.days_until_due'), 'faltan exactamente los días del intervalo');
        $this->assertSame($dias <= 7 ? 'proximo' : 'al_dia', $r->json('data.status'));
    }

    /** @return array<string, array{0: string, 1: int, 2: string}> */
    public static function bordes(): array
    {
        return [
            'fin de mes (año normal)' => ['2027-01-31', 30, '2027-03-02'],
            'fin de mes (bisiesto)' => ['2028-01-31', 30, '2028-03-01'],
            'cambio de año' => ['2026-12-15', 30, '2027-01-14'],
            '31-12 + 1' => ['2026-12-31', 1, '2027-01-01'],
            '29-02-2028 + 60' => ['2028-02-29', 60, '2028-04-29'],
            '29-02-2028 + 365' => ['2028-02-29', 365, '2029-02-28'],
            '28-02-2028 + 1 (existe el 29)' => ['2028-02-28', 1, '2028-02-29'],
            '28-02-2027 + 1 (no existe el 29)' => ['2027-02-28', 1, '2027-03-01'],
        ];
    }

    #[DataProvider('bordes')]
    public function test_bordes_de_calendario_en_la_clase_real(string $desde, int $dias, string $esperada): void
    {
        $this->assertSame($esperada, ValuationFollowUps::addCalendarDays($desde, $dias));
        $this->assertSame($esperada, ValuationFollowUps::nextSuggestedOn(true, null, $desde, $dias));
        $this->assertSame($dias, ValuationFollowUps::daysBetween($desde, $esperada));
    }

    public function test_bisiesto_por_la_api(): void
    {
        $this->clock('2028-03-01 09:00');
        $andres = $this->trainer();
        $laura = $this->member('Laura Gómez', '2028-12-31');
        $this->valoracion($laura, $andres, '2028-02-29 18:00');
        $h = $this->reception();

        $this->cambiar($h, $laura, ['interval_days' => 60])->assertOk()
            ->assertJsonPath('data.last_valuation.date', '2028-02-29')
            ->assertJsonPath('data.next_suggested_on', '2028-04-29')
            ->assertJsonPath('data.days_until_due', 59);
    }

    /**
     * Una valoración a las 22:30 de Bogotá ya es el día siguiente en UTC. Cuenta
     * el día de Bogotá, y «hoy» también es el de Bogotá.
     */
    public function test_borde_bogota_frente_a_utc(): void
    {
        $this->clock('2026-11-18 23:00');
        $andres = $this->trainer();
        $laura = $this->member('Laura Gómez');
        $v = $this->valoracion($laura, $andres, '2026-11-18 22:30');
        $this->assertSame('2026-11-19 03:30:00', $v->fresh()->submitted_at->utc()->format('Y-m-d H:i:s'), 'en UTC ya es el 19');
        $recepcion = $this->staff(Admin::ROLE_RECEPCION);
        $h = $this->h($recepcion);

        $this->cambiar($h, $laura, ['interval_days' => 30])->assertOk()
            ->assertJsonPath('data.last_valuation.date', '2026-11-18')
            ->assertJsonPath('data.last_valuation.at', '2026-11-18T22:30:00-05:00')
            ->assertJsonPath('data.next_suggested_on', '2026-12-18');

        $casos = [
            // [hora de Bogotá, días que faltan, estado]
            ['2026-12-10 23:59', 8, 'al_dia'],
            ['2026-12-11 00:00', 7, 'proximo'],
            ['2026-12-17 23:59', 1, 'proximo'],
            ['2026-12-18 00:00', 0, 'proximo'],
            ['2026-12-18 23:59', 0, 'proximo'],
            ['2026-12-19 00:00', -1, 'vencido'],
        ];
        foreach ($casos as [$bogota, $faltan, $estado]) {
            $utc = $this->clock($bogota);
            // Sesión nueva en cada salto de reloj: la anterior caduca.
            $r = $this->seguimiento($this->h($recepcion), $laura)->assertOk();
            $this->assertSame($faltan, $r->json('data.days_until_due'), "{$bogota} Bogotá ({$utc->format('Y-m-d H:i')} UTC)");
            $this->assertSame($estado, $r->json('data.status'), "{$bogota} Bogotá");
        }
    }

    public function test_el_proximo_control_no_se_guarda_y_se_recalcula_al_cambiar_el_gimnasio(): void
    {
        $this->clock('2026-11-18 12:00');
        $andres = $this->trainer();
        $laura = $this->member('Laura Gómez');
        $this->valoracion($laura, $andres, '2026-11-18 10:40');
        $h = $this->reception();

        // Sin fila del socio: hereda los 60 del gimnasio.
        $this->seguimiento($h, $laura)->assertOk()
            ->assertJsonPath('data.interval.source', 'gym')
            ->assertJsonPath('data.interval.days', 60)
            ->assertJsonPath('data.next_suggested_on', '2027-01-17')
            ->assertJsonPath('data.version', 0);

        $this->settings(['follow_up_interval_days' => 90]);
        $this->seguimiento($h, $laura)->assertOk()->assertJsonPath('data.next_suggested_on', '2027-02-16');
        $this->assertDatabaseMissing('member_valuation_follow_ups', ['member_id' => $laura->id]);
    }
}
