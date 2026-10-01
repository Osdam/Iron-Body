<?php

namespace Tests\Feature\Classes;

use App\Models\ClassEvent;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use App\Services\Classes\ClassBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lo que la app publicada lee del recurso de clase.
 *
 * LA HORA. La app hace `DateTime.tryParse(date_time)` y lee `.hour` sin pasar
 * a hora local. El recurso emitía la próxima ocurrencia con zona '-05:00' y
 * Dart la convierte a UTC: en producción las cinco clases salían con cinco
 * horas de más (la de las 06:00, a las 11:00 AM). Se emite la hora de pared del
 * gimnasio, sin zona, que Dart toma como hora local del teléfono.
 *
 * EL CUPO. Sin cupo precalculado el recurso contaba TODAS las reservas de la
 * clase, de cualquier semana: tras editar una clase el CRM enseñaba el
 * histórico en lugar de la próxima sesión.
 */
class ClassResourceContractTest extends TestCase
{
    use RefreshDatabase;

    /** Miércoles 30/09/2026 20:30 en Bogotá = jueves 01:30 UTC. */
    private const AHORA = '2026-09-30 20:30';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::AHORA, 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function recurrente(): MyClass
    {
        return MyClass::create([
            'name' => 'Madrugadores', 'type' => 'Funcional', 'day_of_week' => 'Lunes',
            'start_time' => '06:00', 'end_time' => '07:00', 'status' => 'active',
            'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);
    }

    private function enLista(MyClass $clase): array
    {
        return collect($this->getJson('/api/classes')->assertOk()->json('data'))->firstWhere('id', (string) $clase->id);
    }

    public function test_la_hora_de_una_clase_recurrente_es_la_de_pared_sin_zona(): void
    {
        $clase = $this->recurrente();

        $this->assertSame('2026-10-05T06:00:00', $this->enLista($clase)['date_time']);
        $this->assertSame('2026-10-05T06:00:00', $this->getJson("/api/classes/{$clase->id}")->assertOk()->json('data.date_time'));
    }

    public function test_la_hora_de_una_clase_de_fecha_unica_tambien(): void
    {
        $clase = MyClass::create([
            'name' => 'Masterclass', 'type' => 'Yoga', 'day_of_week' => 'Sábado',
            'start_time' => '19:00', 'end_time' => '20:30', 'status' => 'active', 'max_capacity' => 20,
            'allow_online_booking' => true, 'is_recurring' => false, 'date_time' => '2026-10-10T19:00:00',
        ]);

        $this->assertSame('2026-10-10T19:00:00', $this->enLista($clase)['date_time']);
    }

    public function test_ninguna_hora_lleva_zona(): void
    {
        $this->recurrente();

        foreach ($this->getJson('/api/classes')->assertOk()->json('data') as $c) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $c['date_time'], 'Sin zona: la app no la convierte.');
        }
    }

    public function test_el_crm_recibe_el_cursor_de_hechos_y_la_app_no(): void
    {
        $clase = $this->recurrente();
        $m = $this->givePlanWithClasses(Member::create(['full_name' => 'Con reserva', 'document_number' => '5559991', 'status' => Member::STATUS_ACTIVE]));
        $this->assertTrue(app(ClassBookingService::class)->enrollByStaff($m, $clase, null)->ok);
        $ultimo = (int) ClassEvent::max('id');
        $this->assertGreaterThan(0, $ultimo);

        $this->adminGetJson('/api/classes')->assertOk()
            ->assertJsonPath('events_cursor', $ultimo)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.booked_spots', 1);

        $this->assertArrayNotHasKey('events_cursor', $this->getJson('/api/classes')->assertOk()->json());
    }

    public function test_tras_editar_una_clase_el_cupo_es_el_de_la_proxima_sesion_no_el_historico(): void
    {
        $clase = $this->recurrente();
        foreach (['2026-08-10', '2026-08-31', '2026-09-21'] as $i => $pasada) {
            $m = Member::create(['full_name' => "Histórica {$i}", 'document_number' => "55500{$i}", 'status' => Member::STATUS_ACTIVE]);
            ClassReservation::create(['class_id' => $clase->id, 'member_id' => $m->id, 'session_date' => $pasada]);
        }

        $this->adminPatchJson("/api/classes/{$clase->id}", ['name' => 'Madrugadores PM'])
            ->assertOk()
            ->assertJsonPath('data.enrolled_count', 0)
            ->assertJsonPath('data.booked_spots', 0)
            ->assertJsonPath('data.available_spots', 20);

        $this->assertSame(0, $this->enLista($clase)['booked_spots'], 'El listado dice lo mismo.');
    }
}
