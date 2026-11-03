<?php

namespace Tests\Feature\Valuations;

use App\Models\Member;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationAppointmentEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Duraciones cortas (10, 15, 20 y 25 min) junto a las de siempre (30–120).
 *
 * Una cita de 10 minutos ocupa exactamente 10 minutos: el fin, los huecos, el
 * solape con el entrenador y con el socio, el margen y la reprogramación se
 * cuentan al minuto. Todo por los endpoints reales; reloj y columnas en UTC.
 */
class ValuationShortDurationsTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    private const TODAS = [10, 15, 20, 25, 30, 45, 60, 90, 120];

    private array $rec;

    private Trainer $andres;

    private Member $laura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00'); // lunes, 13:00 UTC
        $this->andres = $this->trainer('Andrés Pérez');
        $this->laura = $this->member('Laura Gómez');
        $this->everyDay($this->andres, '06:00', '21:00');
        $this->rec = $this->reception();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function code(string $cuando, int $duracion, ?Member $m = null, ?Trainer $t = null): ?string
    {
        $r = $this->book($this->rec, $m ?? $this->laura, $t ?? $this->andres, $cuando, ['duration_minutes' => $duracion]);

        return $r->status() === 201 ? null : $r->json('code');
    }

    /** @return list<string> */
    private function inicios(string $fecha, int $duracion): array
    {
        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date={$fecha}&duration_minutes={$duracion}", $this->rec)
            ->assertOk()->json('data');

        return array_column($s['slots'], 'local_start');
    }

    // ── Crear ──────────────────────────────────────────────────────────────

    public function test_las_nueve_duraciones_se_guardan_y_terminan_al_minuto_exacto(): void
    {
        foreach (self::TODAS as $i => $duracion) {
            // Un día por duración: ni el entrenador ni el socio chocan consigo mismos.
            $fecha = Carbon::parse('2026-10-20')->addDays($i)->toDateString();
            $r = $this->book($this->rec, $this->laura, $this->andres, "{$fecha}T13:00:00-05:00", ['duration_minutes' => $duracion])
                ->assertCreated();

            $fin = Carbon::parse("{$fecha} 13:00", 'America/Bogota')->addMinutes($duracion);
            $this->assertSame($duracion, $r->json('data.duration_minutes'), "{$duracion} min");
            $this->assertSame('13:00', $r->json('data.local_start'), "{$duracion} min");
            $this->assertSame($fin->format('H:i'), $r->json('data.local_end'), "{$duracion} min");
            $this->assertSame($fin->copy()->utc()->toIso8601String(), $r->json('data.ends_at'), "{$duracion} min");

            $fila = $this->appointment($r->json('data.uuid'));
            $this->assertSame($duracion, (int) $fila->duration_minutes);
            $this->assertSame($fin->copy()->utc()->format('Y-m-d H:i:s'), $fila->getRawOriginal('ends_at'), "{$duracion} min en la columna");
        }

        // 1:00 p. m. + 10/15/20/25 = 1:10/1:15/1:20/1:25 p. m.
        $finales = ValuationAppointment::query()->orderBy('starts_at')->get()
            ->map(fn (ValuationAppointment $c) => [(int) $c->duration_minutes, $c->ends_at->copy()->setTimezone('America/Bogota')->format('H:i')])
            ->all();
        $this->assertSame([[10, '13:10'], [15, '13:15'], [20, '13:20'], [25, '13:25']], array_slice($finales, 0, 4));
    }

    public function test_por_debajo_de_10_fuera_de_rango_o_sin_multiplo_de_5_sigue_rechazado(): void
    {
        foreach ([0, 5, 9, 12, 245, 300] as $mala) {
            $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['duration_minutes' => $mala])
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
                ->assertJsonStructure(['errors' => ['duration_minutes']]);
            $this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24&duration_minutes={$mala}", $this->rec)
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
        $this->assertSame(0, ValuationAppointment::count());

        $u = $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['duration_minutes' => 10])->assertCreated()->json('data.uuid');
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T10:00:00-05:00', 'duration_minutes' => 5], $this->rec)
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame('2026-10-24 14:10:00', $this->appointment($u)->getRawOriginal('ends_at'), 'la cita no se tocó');
    }

    // ── Solape ─────────────────────────────────────────────────────────────

    public function test_citas_cortas_seguidas_no_chocan_y_el_solape_real_si(): void
    {
        // 13:00–13:10, 13:10–13:25, 13:25–13:45, 13:45–14:10: pegadas, sin hueco ni solape.
        $this->assertNull($this->code('2026-10-24T13:00:00-05:00', 10, $this->member('A')));
        $this->assertNull($this->code('2026-10-24T13:10:00-05:00', 15, $this->member('B')));
        $this->assertNull($this->code('2026-10-24T13:25:00-05:00', 20, $this->member('C')));
        $this->assertNull($this->code('2026-10-24T13:45:00-05:00', 25, $this->member('D')));

        $this->assertSame('slot_taken', $this->code('2026-10-24T13:05:00-05:00', 10, $this->member('E')), 'dentro de 13:00–13:10');
        $this->assertSame('slot_taken', $this->code('2026-10-24T12:55:00-05:00', 10, $this->member('F')), '12:55–13:05 pisa la de 13:00');
        $this->assertSame('slot_taken', $this->code('2026-10-24T13:20:00-05:00', 10, $this->member('G')), 'dentro de 13:10–13:25');
        $this->assertSame('slot_taken', $this->code('2026-10-24T14:05:00-05:00', 10, $this->member('H')), 'dentro de 13:45–14:10');
        $this->assertNull($this->code('2026-10-24T12:50:00-05:00', 10, $this->member('I')), '12:50–13:00 acaba cuando empieza la otra');
        $this->assertNull($this->code('2026-10-24T14:10:00-05:00', 10, $this->member('J')), 'empieza cuando acaba la de 25');

        $this->assertSame(6, ValuationAppointment::count());
    }

    /** La red de la base (EXCLUDE con `[)`, solo PostgreSQL) también cuenta al minuto con las cortas. */
    public function test_la_exclude_deja_pegadas_las_de_10_y_rechaza_el_solape_sin_pasar_por_el_servicio(): void
    {
        $pg = DB::connection()->getDriverName() === 'pgsql';
        $fila = function (string $desde, int $minutos): array {
            $inicio = Carbon::parse("2026-10-24 {$desde}", 'America/Bogota')->utc();

            return [
                'member_id' => $this->member('Socio '.$desde)->id, 'trainer_id' => $this->andres->id, 'type' => 'initial',
                'status' => 'scheduled', 'starts_at' => $inicio, 'ends_at' => $inicio->copy()->addMinutes($minutos),
                'duration_minutes' => $minutos, 'source' => 'crm',
            ];
        };

        ValuationAppointment::create($fila('13:00', 10));
        ValuationAppointment::create($fila('13:10', 10)); // [13:00, 13:10) y [13:10, 13:20) se tocan: no chocan.
        try {
            DB::transaction(fn () => ValuationAppointment::create($fila('13:15', 10)));
            $this->assertFalse($pg, 'en PostgreSQL la EXCLUDE tenía que rechazar 13:15–13:25');
        } catch (QueryException $e) {
            $this->assertTrue($pg);
            $this->assertSame('23P01', $e->errorInfo[0], 'violación de exclusión');
        }
        $this->assertSame($pg ? 2 : 3, ValuationAppointment::count(), $pg ? 'solo las pegadas' : 'SQLite no tiene esta red');
    }

    public function test_el_socio_no_se_doble_reserva_con_citas_cortas(): void
    {
        $sofia = $this->trainer('Sofía Ríos');
        $this->everyDay($sofia);
        $this->assertNull($this->code('2026-10-24T13:00:00-05:00', 10));
        $this->assertSame('member_overlap', $this->code('2026-10-24T13:05:00-05:00', 10, null, $sofia));
        $this->assertNull($this->code('2026-10-24T13:10:00-05:00', 10, null, $sofia), 'con otro entrenador, justo al terminar');
    }

    public function test_el_margen_entre_citas_se_suma_a_la_duracion_corta(): void
    {
        $this->settings(['buffer_minutes' => 5]);
        $this->assertNull($this->code('2026-10-24T13:00:00-05:00', 10));
        $this->assertSame('slot_taken', $this->code('2026-10-24T13:10:00-05:00', 10, $this->member('A')), 'a 0 min con margen de 5');
        $this->assertNull($this->code('2026-10-24T13:15:00-05:00', 10, $this->member('B')));
    }

    // ── Huecos ─────────────────────────────────────────────────────────────

    public function test_huecos_con_duraciones_cortas_respetan_franja_intervalo_y_lo_ocupado(): void
    {
        // Entrenadora con una sola franja corta el sábado 24: 08:00–09:00.
        $ana = $this->trainer('Ana Ruiz');
        $this->rule($ana, 6, '08:00', '09:00');
        $ver = fn (int $d) => array_column($this->getJson("/api/admin/valuations/slots?trainer_id={$ana->id}&date=2026-10-24&duration_minutes={$d}", $this->rec)->assertOk()->json('data.slots'), 'local_end', 'local_start');

        // Intervalo por defecto (30): las cortas empiezan en la rejilla y acaban a su minuto.
        $this->assertSame(['08:00' => '08:10', '08:30' => '08:40'], $ver(10));
        $this->assertSame(['08:00' => '08:25', '08:30' => '08:55'], $ver(25));

        $this->settings(['slot_interval_minutes' => 15]);
        $this->assertSame(['08:00' => '08:10', '08:15' => '08:25', '08:30' => '08:40', '08:45' => '08:55'], $ver(10));
        $this->assertSame(['08:00' => '08:15', '08:15' => '08:30', '08:30' => '08:45', '08:45' => '09:00'], $ver(15));
        $this->assertSame(['08:00' => '08:20', '08:15' => '08:35', '08:30' => '08:50'], $ver(20), '08:45 + 20 sale de la franja');
        $this->assertSame(['08:00' => '08:25', '08:15' => '08:40', '08:30' => '08:55'], $ver(25));

        // Una de 10 a las 08:15 ocupa 08:15–08:25, no la media hora.
        $this->book($this->rec, $this->laura, $ana, '2026-10-24T08:15:00-05:00', ['duration_minutes' => 10])->assertCreated();
        $s = $this->getJson("/api/admin/valuations/slots?trainer_id={$ana->id}&date=2026-10-24&duration_minutes=10", $this->rec)->json('data');
        $this->assertSame([['local_start' => '08:15', 'local_end' => '08:25', 'reason' => 'appointment', 'label' => 'Ocupado']], $s['busy']);
        $this->assertSame(['08:00' => '08:10', '08:30' => '08:40', '08:45' => '08:55'], array_column($s['slots'], 'local_end', 'local_start'));
        $this->assertSame(['08:30' => '08:50'], $ver(20), '08:00–08:20 pisa la de 08:15');

        // Los huecos que se ofrecen se pueden reservar de verdad.
        $this->book($this->rec, $this->member('Otro'), $ana, '2026-10-24T08:00:00-05:00', ['duration_minutes' => 10])->assertCreated();
        $this->book($this->rec, $this->member('Otra'), $ana, '2026-10-24T08:30:00-05:00', ['duration_minutes' => 10])->assertCreated();
    }

    // ── Editar y reprogramar ───────────────────────────────────────────────

    public function test_reprogramar_a_y_desde_una_duracion_corta(): void
    {
        $u = $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00', ['duration_minutes' => 60])->assertCreated()->json('data.uuid');

        // Misma hora, de 60 a 10: libera 09:10–10:00.
        $r = $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T09:00:00-05:00', 'duration_minutes' => 10], $this->rec)
            ->assertOk();
        $this->assertSame([10, '09:00', '09:10'], [$r->json('data.duration_minutes'), $r->json('data.local_start'), $r->json('data.local_end')]);
        $ev = ValuationAppointmentEvent::where('appointment_id', $this->appointment($u)->id)->where('action', 'rescheduled')->firstOrFail();
        $this->assertSame([60, 10], [$ev->metadata['from_duration_minutes'], $ev->metadata['to_duration_minutes']]);
        $this->assertNull($this->code('2026-10-24T09:10:00-05:00', 20, $this->member('Siguiente')), 'el tiempo liberado se puede reservar');

        // Sin duración, la reprogramación conserva los 10 minutos.
        $v = (int) $this->appointment($u)->version;
        $r = $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => $v, 'starts_at' => '2026-10-24T11:00:00-05:00'], $this->rec)->assertOk();
        $this->assertSame([10, '11:00', '11:10'], [$r->json('data.duration_minutes'), $r->json('data.local_start'), $r->json('data.local_end')]);

        // Editar tipo y notas no toca la hora ni la duración.
        $v = (int) $this->appointment($u)->version;
        $this->patchJson("/api/admin/valuations/{$u}", ['expected_version' => $v, 'type' => 'follow_up', 'notes' => 'Solo medidas'], $this->rec)->assertOk();
        $fila = $this->appointment($u);
        $this->assertSame([10, '2026-10-24 16:00:00', '2026-10-24 16:10:00'], [(int) $fila->duration_minutes, $fila->getRawOriginal('starts_at'), $fila->getRawOriginal('ends_at')]);

        // Y de 10 a 120 (las de siempre siguen valiendo al reprogramar).
        $v = (int) $fila->version;
        $r = $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => $v, 'starts_at' => '2026-10-24T11:00:00-05:00', 'duration_minutes' => 120], $this->rec)->assertOk();
        $this->assertSame([120, '13:00'], [$r->json('data.duration_minutes'), $r->json('data.local_end')]);
    }

    public function test_reprogramar_una_corta_pegada_a_otra_corta(): void
    {
        $this->assertNull($this->code('2026-10-24T13:00:00-05:00', 10, $this->member('A')));
        $u = $this->book($this->rec, $this->laura, $this->andres, '2026-10-24T15:00:00-05:00', ['duration_minutes' => 15])->assertCreated()->json('data.uuid');

        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T13:05:00-05:00'], $this->rec)
            ->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-24T13:10:00-05:00'], $this->rec)
            ->assertOk()->assertJsonPath('data.local_end', '13:25');

        // 13:00 sigue ocupada por la de 10; 13:30 queda libre porque la movida acaba a las 13:25.
        $inicios = $this->inicios('2026-10-24', 10);
        $this->assertNotContains('13:00', $inicios);
        $this->assertContains('13:30', $inicios);
        $this->assertNotContains('15:00', array_column($this->getJson("/api/admin/valuations/slots?trainer_id={$this->andres->id}&date=2026-10-24&duration_minutes=10", $this->rec)->json('data.busy'), 'local_start'), 'su hora vieja quedó libre');
    }
}
