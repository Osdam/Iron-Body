<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Run\OutreachPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * I-6 (informe-tester-i6.md, defecto D-1): `renewal_window_opened` a la HORA
 * REAL a la que lo emite producción, no a las 10:00 de Bogotá.
 *
 * `commercial:scan` corre cada hora en punto (`routes/console.php:328-331`) y
 * abre la ventana de renovación del motor (`commercial.thresholds.renewal_window_days`
 * = 10) con `Carbon::today()`, que va en UTC (`config/app.php`). El H9 cuenta
 * los días en Bogotá (contrato H9, D9-4: «date_relative siempre como días u
 * horas desde ahora en Bogotá»). Comportamiento esperado: un socio al que le
 * quedan 10 días de Bogotá entra en una estrategia cuya audiencia es esa misma
 * ventana (`membership.days_to_expiry ≤ 10`), una vez.
 *
 * Esta prueba recorre las pasadas horarias reales (escaneo + ingesta) desde
 * las 18:00 de Bogotá del 17-11 hasta las 10:00 del 18-11, con el emisor real
 * y las clases reales del H9. NO se adapta al fallo: si queda en rojo, el
 * hecho llega a las 19:00 de Bogotá del día anterior (11 días), la audiencia
 * lo deja fuera y el emisor no lo repite (una vez por vencimiento).
 */
class H9RenewalWindowBogotaDayTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_renewal_window_opened_inscribe_al_socio_cuando_le_quedan_10_dias_de_bogota_con_las_pasadas_horarias_reales(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 23:00:00', 'UTC'));   // martes 17-11, 18:00 de Bogotá
        config(['commercial.events_enabled' => true]);
        Http::preventStrayRequests();
        $this->threePeople();
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), [
            'goal' => 'renew',
            'trigger' => ['event' => 'renewal_window_opened'],
            'audience' => ['all' => [
                ['fact' => 'membership.active', 'op' => 'eq', 'value' => true],
                ['fact' => 'membership.frozen', 'op' => 'eq', 'value' => false],
                ['fact' => 'membership.days_to_expiry', 'op' => 'lte', 'value' => 10],   // la misma ventana que el motor
            ]],
            'success' => ['fact' => 'membership.days_to_expiry', 'op' => 'gt', 'value' => 30],
        ]));
        // Vence el sábado 28-11: el 17-11 de Bogotá le quedan 11 días; el 18-11, 10.
        $socio = $this->socio(['membership_end_date' => '2026-11-28', 'plan' => 'Mensual']);
        $this->conversation($this->lead(['member_id' => $socio['member'], 'phone' => '3001234561', 'consent_status' => 'granted']));

        $planner = new OutreachPlanner;
        $diario = [];
        foreach (range(0, 16) as $h) {
            Carbon::setTestNow(Carbon::parse('2026-11-17 23:00:30', 'UTC')->addHours($h));
            $this->artisan('commercial:scan')->assertExitCode(0);
            $r = $planner->ingest(now());
            $c = $r['strategies'][$vivo['strategyUuid']] ?? null;
            if ($c !== null) {
                $diario[] = now()->setTimezone('America/Bogota')->format('d-m H:i').' Bogotá → '.json_encode(array_intersect_key($c, array_flip(['candidates', 'enrolled', 'not_in_audience'])));
            }
        }

        $this->assertSame(1, DB::table('commercial_events')->where('event', 'renewal_window_opened')->count(), 'el emisor lo registra una vez por vencimiento');
        $this->assertSame(
            1,
            DB::table('outreach_enrollments')->where('subject_key', 'm:'.$socio['member'])->count(),
            'D-1 (I-6): el socio al que le quedan 10 días de Bogotá no se inscribió nunca. Pasadas: '.json_encode($diario, JSON_UNESCAPED_UNICODE)
            .'. `commercial:scan` abre la ventana con `Carbon::today()` en UTC: el hecho llega a las 19:00 de Bogotá con 11 días, la audiencia ≤ 10 lo deja fuera y no se repite.',
        );
    }
}
