<?php

namespace Tests\Feature\Classes;

use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use App\Services\Admin\IronCrm\IronCrmToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lo que el asistente IRON del CRM contesta sobre clases.
 *
 * Leía la columna `enrolled_count`, que ya no se mantiene; filtraba el día con
 * un número contra una columna que guarda «Lunes» (nunca casaba y en PostgreSQL
 * fallaba), y «hoy» era el día UTC. Debe decir lo mismo que la app, el CRM y el
 * entrenador.
 */
class IronCrmClassToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Miércoles 30/09/2026 20:30 en Bogotá: ya es jueves en UTC.
        Carbon::setTestNow(Carbon::parse('2026-09-30 20:30', 'America/Bogota')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tools(): IronCrmToolService
    {
        return app(IronCrmToolService::class);
    }

    private function clase(string $dia, string $hora, string $nombre): MyClass
    {
        $clase = MyClass::create([
            'name' => $nombre, 'type' => 'Funcional', 'day_of_week' => $dia, 'start_time' => $hora,
            'end_time' => '23:00', 'status' => 'active', 'max_capacity' => 20,
            'allow_online_booking' => true, 'is_recurring' => true,
        ]);
        // La columna vieja, con un valor que ya no dice nada.
        $clase->forceFill(['enrolled_count' => 7])->save();

        return $clase;
    }

    private function reserva(MyClass $clase, string $nombre, ?string $fecha): void
    {
        static $n = 0;
        $n++;
        $m = Member::create(['full_name' => $nombre, 'document_number' => '8800'.$n, 'status' => Member::STATUS_ACTIVE]);
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $m->id, 'session_date' => $fecha]);
    }

    public function test_los_inscritos_son_los_de_la_proxima_sesion(): void
    {
        $lunes = $this->clase('Lunes', '17:22', 'Funcional lunes');
        $this->reserva($lunes, 'Próxima', '2026-10-05');
        $this->reserva($lunes, 'Pasada', '2026-09-28');

        $clase = collect($this->tools()->run('list_classes', [])['classes'])->firstWhere('id', $lunes->id);

        $this->assertSame(1, $clase['enrolled'], 'Ni la columna vieja (7) ni el histórico (2).');
        $this->assertSame('2026-10-05', $clase['next_session_date']);
    }

    public function test_filtra_por_dia_y_ordena_por_calendario(): void
    {
        $this->clase('Domingo', '08:00', 'Domingo');
        $this->clase('Lunes', '18:00', 'Lunes tarde');
        $this->clase('Lunes', '06:00', 'Lunes mañana');
        $this->clase('Jueves', '07:00', 'Jueves');

        $lunes = $this->tools()->run('list_classes', ['day_of_week' => 1]);
        $this->assertArrayNotHasKey('error', $lunes);
        $this->assertSame(['Lunes mañana', 'Lunes tarde'], array_column($lunes['classes'], 'name'));

        $todas = $this->tools()->run('list_classes', []);
        $this->assertSame(['Lunes mañana', 'Lunes tarde', 'Jueves', 'Domingo'], array_column($todas['classes'], 'name'));

        $this->assertArrayHasKey('error', $this->tools()->run('list_classes', ['day_of_week' => 9]));
    }

    public function test_las_reservas_de_hoy_son_las_del_dia_del_gimnasio(): void
    {
        $miercoles = $this->clase('Miércoles', '19:00', 'Miércoles noche');
        $jueves = $this->clase('Jueves', '06:00', 'Jueves temprano');
        $this->reserva($miercoles, 'Hoy', '2026-09-30');
        $this->reserva($jueves, 'Mañana', '2026-10-01');
        $this->reserva($miercoles, 'Heredada', null);
        $this->reserva($jueves, 'Heredada de otra clase', null);

        $hoy = $this->tools()->run('reservations_for_date', []);

        $this->assertSame('2026-09-30', $hoy['date']);
        $this->assertEqualsCanonicalizing(['Hoy', 'Heredada'], array_column($hoy['reservations'], 'member'));
        $this->assertSame(2, $hoy['total']);
        $this->assertSame('Miércoles noche', $hoy['reservations'][0]['class']);
    }
}
