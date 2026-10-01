<?php

namespace Tests\Feature\Classes;

use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * «Organizar mi semana» con una reserva heredada (sin fecha) del propio socio,
 * y el domingo.
 *
 * La heredada ocupa TODAS las sesiones de su clase: el cupo ya la contaba, pero
 * el plan no la reconocía como del socio y le ofrecía «Reservar», que contestaba
 * «ya reservada»; y cancelar esa fecha desde el plan no la liberaba. Además, en
 * SQLite el rango de la semana dejaba fuera el domingo.
 */
class WeeklyLegacyReservationTest extends TestCase
{
    use RefreshDatabase;

    private Member $socio;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes 05/10/2026 10:00 en Bogotá.
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'America/Bogota')->utc());
        $this->socio = $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socia Heredada', 'document_number' => '6260001', 'phone' => '+573006260001',
            'access_hash' => 'tok-6260001', 'status' => Member::STATUS_ACTIVE,
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function clase(string $dia): MyClass
    {
        return MyClass::create([
            'name' => "Clase {$dia}", 'type' => 'Funcional', 'day_of_week' => $dia, 'start_time' => '09:00',
            'end_time' => '10:00', 'status' => 'active', 'max_capacity' => 20,
            'allow_online_booking' => true, 'is_recurring' => true,
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer tok-6260001'];
    }

    private function entrada(MyClass $clase): array
    {
        $semana = $this->getJson('/api/app/classes/weekly?week_start=2026-10-05', $this->auth())->assertOk();

        return collect($semana->json('days'))->flatMap(fn ($d) => $d['classes'])->firstWhere('class_id', $clase->id);
    }

    public function test_la_reserva_heredada_del_socio_sale_como_suya_en_el_plan(): void
    {
        $clase = $this->clase('Miércoles');
        $fila = ClassReservation::create(['class_id' => $clase->id, 'member_id' => $this->socio->id, 'session_date' => null]);

        $e = $this->entrada($clase);

        $this->assertTrue($e['is_reserved']);
        $this->assertFalse($e['can_reserve']);
        $this->assertSame($fila->id, $e['reservation_id']);
        $this->assertSame(1, $e['booked_spots'], 'Se cuenta una vez: es la suya.');
    }

    public function test_cancelar_esa_fecha_desde_el_plan_libera_la_heredada(): void
    {
        $clase = $this->clase('Miércoles');
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $this->socio->id, 'session_date' => null]);

        $this->deleteJson("/api/app/classes/{$clase->id}/reserve", ['session_date' => '2026-10-07'], $this->auth())->assertOk();

        $this->assertSame(0, ClassReservation::where('member_id', $this->socio->id)->count());
        $this->assertFalse($this->entrada($clase)['is_reserved']);
    }

    public function test_el_domingo_tambien_cuenta_la_reserva_y_el_cupo(): void
    {
        $clase = $this->clase('Domingo');
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $this->socio->id, 'session_date' => '2026-10-11']);

        $e = $this->entrada($clase);

        $this->assertSame('2026-10-11', $e['session_date']);
        $this->assertTrue($e['is_reserved']);
        $this->assertSame(1, $e['booked_spots']);
    }
}
