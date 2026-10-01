<?php

namespace Tests\Feature\Classes;

use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use App\Services\IronAiService;
use App\Services\IronAiUserContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lo que el asistente IRON le dice al socio sobre sus clases.
 *
 * Tomaba la ÚLTIMA reserva creada (aunque fuera de una clase de hace semanas)
 * con la fecha de creación de la clase, y la «próxima reserva» era la que el
 * socio había HECHO hoy, no la clase a la que va. Ahora son sus próximas
 * sesiones, a la hora de la clase.
 */
class IronMemberClassContextTest extends TestCase
{
    use RefreshDatabase;

    private Member $socio;

    protected function setUp(): void
    {
        parent::setUp();
        // Miércoles 30/09/2026 20:30 en Bogotá.
        Carbon::setTestNow(Carbon::parse('2026-09-30 20:30', 'America/Bogota')->utc());
        $this->socio = Member::create(['full_name' => 'Ana Socia', 'document_number' => '4400123', 'status' => Member::STATUS_ACTIVE]);

        // Orden de creación a propósito: la última creada es la ya empezada.
        $this->reserva($this->clase('Lunes', '17:22', 'Funcional lunes'), '2026-10-05');
        $this->reserva($this->clase('Jueves', '06:00', 'Spinning jueves'), '2026-10-01');
        $this->reserva($this->clase('Lunes', '07:00', 'Yoga de la semana pasada'), '2026-09-28');
        $this->reserva($this->clase('Miércoles', '19:00', 'Box de esta noche'), '2026-09-30');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function clase(string $dia, string $hora, string $nombre): MyClass
    {
        return MyClass::create([
            'name' => $nombre, 'type' => 'Funcional', 'day_of_week' => $dia, 'start_time' => $hora,
            'end_time' => '23:00', 'status' => 'active', 'max_capacity' => 20,
            'allow_online_booking' => true, 'is_recurring' => true,
            // Fecha de creación de la clase: lo que el asistente enseñaba como hora.
            'date_time' => '2026-01-10 09:00:00',
        ]);
    }

    private function reserva(MyClass $clase, string $fecha): void
    {
        ClassReservation::create([
            'class_id' => $clase->id, 'member_id' => $this->socio->id,
            'session_date' => $fecha, 'reserved_at' => now(),
        ]);
    }

    public function test_la_proxima_reserva_es_la_proxima_clase_a_la_que_va(): void
    {
        $ctx = app(IronAiUserContextService::class)->build($this->socio, ['classes'])['classes'];

        $this->assertSame('2026-10-01T06:00:00-05:00', $ctx['next_reservation_at']);
        $this->assertSame('Spinning jueves', $ctx['next_reservation_class']);
        $this->assertSame(4, $ctx['reservations_last_30d']);
    }

    public function test_el_contexto_del_chat_lista_sus_proximas_sesiones_en_orden(): void
    {
        $contexto = app(IronAiService::class)->buildUserContext($this->socio, null);

        $this->assertStringContainsString(
            'Clases reservadas/próximas: Spinning jueves (2026-10-01 06:00) | Funcional lunes (2026-10-05 17:22)',
            $contexto,
        );
        $this->assertStringNotContainsString('semana pasada', $contexto);
        $this->assertStringNotContainsString('Box de esta noche', $contexto, 'Ya empezó.');
    }

    public function test_el_recordatorio_es_de_la_proxima_clase(): void
    {
        $recs = collect(app(IronAiService::class)->recommendations($this->socio, null));

        $clase = $recs->firstWhere('type', 'class');
        $this->assertNotNull($clase);
        $this->assertSame('Recuerda tu clase Spinning jueves (2026-10-01 06:00). ¡Te esperamos!', $clase['message']);
    }
}
