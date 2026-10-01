<?php

namespace Tests\Feature\Classes;

use App\Models\ClassAttendance;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Supervisión de horarios del CRM: horario programado frente al real.
 *
 * Contaba como inscritos TODAS las reservas de la clase, de cualquier semana, y
 * medía el retraso contra la hora programada leída en UTC: un inicio puntual
 * salía con 300 minutos de retraso.
 */
class ClassSupervisionTest extends TestCase
{
    use RefreshDatabase;

    private const LUNES = '2026-10-05';

    private const LUNES_PASADO = '2026-09-28';

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes 20:30 en Bogotá: ya es martes en UTC.
        Carbon::setTestNow(Carbon::parse(self::LUNES.' 20:30', 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function socio(): Member
    {
        static $n = 0;
        $n++;

        return Member::create(['full_name' => "Socio {$n}", 'document_number' => '9900'.$n, 'status' => Member::STATUS_ACTIVE]);
    }

    /** @return array<string, array<string, mixed>> fecha => fila */
    private function filas(MyClass $clase, array $query = []): array
    {
        $rows = $this->adminGetJson('/api/admin/class-sessions?'.http_build_query($query))->assertOk()->json('data');

        return collect($rows)->where('class_id', $clase->id)->keyBy('session_date')->all();
    }

    public function test_inscritos_de_cada_sesion_y_retraso_a_la_hora_del_gimnasio(): void
    {
        $clase = MyClass::create([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '17:22',
            'end_time' => '18:22', 'status' => 'active', 'max_capacity' => 20,
            'allow_online_booking' => true, 'is_recurring' => true,
        ]);

        // Sesión de hoy: 1 reservada; la de la semana que viene tiene 3 que no son de hoy.
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $this->socio()->id, 'session_date' => self::LUNES]);
        foreach (range(1, 3) as $_) {
            ClassReservation::create(['class_id' => $clase->id, 'member_id' => $this->socio()->id, 'session_date' => '2026-10-12']);
        }
        ClassSession::create([
            'class_id' => $clase->id, 'session_date' => self::LUNES,
            'started_at' => Carbon::parse(self::LUNES.' 17:25', 'America/Bogota')->utc(),
        ]);

        // Sesión pasada, ya renovada: sin reservas, con su lista de asistencia.
        ClassSession::create([
            'class_id' => $clase->id, 'session_date' => self::LUNES_PASADO,
            'started_at' => Carbon::parse(self::LUNES_PASADO.' 17:20', 'America/Bogota')->utc(),
            'ended_at' => Carbon::parse(self::LUNES_PASADO.' 18:20', 'America/Bogota')->utc(),
        ]);
        foreach (['present', 'late', 'absent'] as $estado) {
            ClassAttendance::create([
                'class_id' => $clase->id, 'member_id' => $this->socio()->id,
                'session_date' => self::LUNES_PASADO, 'status' => $estado, 'marked_at' => now(),
            ]);
        }

        $filas = $this->filas($clase);

        $this->assertSame(1, $filas[self::LUNES]['enrolled'], 'Las 3 de la semana que viene no son de esta sesión.');
        $this->assertSame(3, $filas[self::LUNES]['start_delay_minutes'], 'Empezó a las 17:25 para las 17:22.');
        $this->assertSame(3, $filas[self::LUNES_PASADO]['enrolled'], 'Renovada: cuenta su lista de asistencia.');
        $this->assertSame(2, $filas[self::LUNES_PASADO]['attended']);
        $this->assertSame(-2, $filas[self::LUNES_PASADO]['start_delay_minutes'], 'Dos minutos antes.');
    }

    public function test_por_defecto_son_los_siete_dias_del_gimnasio_hasta_hoy(): void
    {
        $clase = MyClass::create([
            'name' => 'Madrugada', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '05:00',
            'end_time' => '06:00', 'status' => 'active', 'max_capacity' => 20,
            'allow_online_booking' => true, 'is_recurring' => true,
        ]);
        // Hoy es lunes 5 en el gimnasio y martes 6 en UTC.
        ClassSession::create(['class_id' => $clase->id, 'session_date' => self::LUNES_PASADO]);
        ClassSession::create(['class_id' => $clase->id, 'session_date' => '2026-10-06']);

        $this->assertSame([self::LUNES_PASADO], array_keys($this->filas($clase)), 'Entra el lunes pasado; mañana todavía no.');
    }
}
