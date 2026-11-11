<?php

namespace Tests\Feature\Outreach\B9Cierre;

use App\Services\Outreach\Run\OutreachMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Cierre de B9 · H-1 de TEST9-c (L9-65, L9-73): `OutreachMonitor::run()` SIN
 * argumento —como lo llama `outreach:monitor`— usa el reloj de la aplicación
 * (`now()`), como las demás pasadas: en producción el de verdad y en las
 * pruebas el de `Carbon::setTestNow()`. Antes usaba el reloj del sistema: la
 * pasada quedaba con otra hora y el techo de gasto miraba OTRO día de Bogotá.
 * El instante elegido está lejos del reloj real a propósito.
 */
class MonitorClockTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    /** Martes 2 de marzo de 2027, 10:00 de Bogotá: no es «hoy» en ninguna máquina que corra esto. */
    private const FROZEN = '2027-03-02 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::FROZEN, 'UTC'));
        $this->runFlags('live', ['outreach.monitor.enabled' => true, 'outreach.assisted.daily_cap' => 4]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sin_argumento_la_pasada_usa_el_reloj_de_la_aplicacion_y_el_dia_de_bogota_de_ese_reloj(): void
    {
        // Lo reservado HOY (el día del reloj de la aplicación) supera el techo de ahora (4).
        $this->insertBudget(['budget_day' => '2027-03-02', 'scope' => 'channel:assisted', 'mode' => 'live', 'cap_units' => 5, 'cap_micros' => 0, 'reserved_units' => 5, 'reserved_micros' => 0]);

        $r = (new OutreachMonitor)->run();

        $this->assertSame(self::FROZEN, (string) DB::table('outreach_runs')->where('id', $r['runId'])->value('started_at'), 'la pasada lleva la hora del reloj de la aplicación');
        $this->assertSame([['scope' => 'channel', 'scopeRef' => 'assisted', 'reason' => 'budget_breach']], $r['opened'], 'y mira el gasto de SU día de Bogotá');
    }

    public function test_la_orden_outreach_monitor_tambien(): void
    {
        $this->artisan('outreach:monitor')->assertExitCode(0);

        $this->assertSame(self::FROZEN, (string) DB::table('outreach_runs')->where('kind', 'monitor')->value('started_at'));
    }
}
