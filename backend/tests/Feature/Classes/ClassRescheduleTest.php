<?php

namespace Tests\Feature\Classes;

use App\Models\CatalogEvent;
use App\Models\ClassEvent;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Notification;
use App\Models\Trainer;
use App\Models\TrainerRealtimeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cambiar el día de una clase desde el CRM.
 *
 * Las reservas futuras quedaban con la fecha de un día en que la clase ya no se
 * dicta: la app contaba 0/20, el entrenador veía «Sin inscritos» y el socio
 * creía tener plaza. Ahora se mueven a la ocurrencia de su misma semana, o se
 * cancelan con aviso si no cabe. Lo ya dictado no se toca.
 */
class ClassRescheduleTest extends TestCase
{
    use RefreshDatabase;

    /** Miércoles 30/09/2026 10:00 en Bogotá. */
    private const HOY = '2026-09-30';

    private MyClass $clase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::HOY.' 10:00', 'America/Bogota')->utc());
        $coach = Trainer::create(['full_name' => 'Coach Cambio', 'document' => '4455001', 'status' => 'active']);
        $this->clase = MyClass::create([
            'name' => 'Funcional', 'type' => 'Funcional', 'trainer_id' => $coach->id,
            'day_of_week' => 'Miércoles', 'start_time' => '07:00', 'end_time' => '08:00',
            'status' => 'active', 'max_capacity' => 3, 'allow_online_booking' => true, 'is_recurring' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function socio(string $nombre): Member
    {
        static $n = 0;
        $n++;

        return Member::create(['full_name' => $nombre, 'document_number' => '4466'.$n, 'status' => Member::STATUS_ACTIVE]);
    }

    private function reserva(Member $socio, string $fecha): ClassReservation
    {
        return ClassReservation::create(['class_id' => $this->clase->id, 'member_id' => $socio->id, 'session_date' => $fecha]);
    }

    private function fechaDe(Member $socio): ?string
    {
        return ClassReservation::where('class_id', $this->clase->id)->where('member_id', $socio->id)->first()?->session_date?->toDateString();
    }

    private function mover(string $dia): void
    {
        $this->adminPatchJson("/api/classes/{$this->clase->id}", ['day_of_week' => $dia])->assertOk();
    }

    private function avisosDeCambio(Member $socio): array
    {
        return Notification::where('member_id', $socio->id)->where('title', 'Tu reserva cambió')->pluck('event_key')->all();
    }

    public function test_la_reserva_futura_pasa_al_nuevo_dia_de_su_semana(): void
    {
        $socio = $this->socio('Próxima semana');
        $reserva = $this->reserva($socio, '2026-10-07'); // miércoles de la semana que viene

        $this->mover('Viernes');

        $this->assertSame('2026-10-09', $this->fechaDe($socio), 'El viernes de esa misma semana.');
        $this->assertSame([], $this->avisosDeCambio($socio));
        $hechos = ClassEvent::where('reservation_id', $reserva->id)->orderBy('id')->get(['type', 'session_date']);
        $this->assertSame(['booking.cancelled', 'booking.created'], $hechos->pluck('type')->all());
        $this->assertSame(['2026-10-07', '2026-10-09'], $hechos->map(fn ($h) => $h->session_date->toDateString())->all());
    }

    public function test_si_el_nuevo_dia_de_esa_semana_ya_paso_se_cancela_con_aviso(): void
    {
        $socio = $this->socio('Hoy sin empezar');
        $this->reserva($socio, self::HOY); // la clase de hoy aún no se ha dictado

        $this->mover('Lunes'); // el lunes de esta semana fue el 28

        $this->assertNull($this->fechaDe($socio));
        $this->assertSame(["class_dropped_{$this->clase->id}_{$socio->id}_".self::HOY], $this->avisosDeCambio($socio));
    }

    public function test_sin_cupo_o_si_ya_ocupa_el_nuevo_dia_se_cancela(): void
    {
        // Viernes 09/10 con el cupo lleno (3) antes del cambio.
        foreach (['A', 'B', 'C'] as $n) {
            $this->reserva($this->socio("Viernes {$n}"), '2026-10-09');
        }
        $sinSitio = $this->socio('Sin sitio');
        $this->reserva($sinSitio, '2026-10-07');

        $this->mover('Viernes');

        $this->assertNull($this->fechaDe($sinSitio));
        $this->assertSame(3, ClassReservation::where('class_id', $this->clase->id)->whereDate('session_date', '2026-10-09')->count(), 'Nunca más que el cupo.');
        $this->assertCount(1, $this->avisosDeCambio($sinSitio));
    }

    public function test_el_socio_que_ya_tenia_el_nuevo_dia_no_queda_duplicado(): void
    {
        $socio = $this->socio('Las dos');
        $this->reserva($socio, '2026-10-07');
        $this->reserva($socio, '2026-10-09');

        $this->mover('Viernes');

        $this->assertSame(['2026-10-09'], ClassReservation::where('member_id', $socio->id)->get()->map(fn ($r) => $r->session_date->toDateString())->all());
    }

    public function test_la_sesion_de_hoy_ya_iniciada_o_cerrada_no_se_toca(): void
    {
        $socio = $this->socio('Fue a clase hoy');
        $this->reserva($socio, self::HOY);
        ClassSession::create([
            'class_id' => $this->clase->id, 'session_date' => self::HOY,
            'started_at' => Carbon::parse(self::HOY.' 07:01', 'America/Bogota')->utc(),
            'ended_at' => Carbon::parse(self::HOY.' 08:00', 'America/Bogota')->utc(),
        ]);

        $this->mover('Viernes');

        $this->assertSame(self::HOY, $this->fechaDe($socio), 'Ni movida al viernes ni borrada: esa clase ya se dictó.');
        $this->assertSame([], $this->avisosDeCambio($socio));
    }

    public function test_lo_pasado_no_se_toca_y_editar_el_nombre_no_reconcilia(): void
    {
        $pasado = $this->socio('Agosto');
        $this->reserva($pasado, '2026-08-26');
        $futuro = $this->socio('Futuro');
        $this->reserva($futuro, '2026-10-07');

        $this->adminPatchJson("/api/classes/{$this->clase->id}", ['name' => 'Funcional AM'])->assertOk();
        $this->assertSame('2026-10-07', $this->fechaDe($futuro));

        $this->mover('Viernes');
        $this->assertSame('2026-08-26', $this->fechaDe($pasado));
    }

    public function test_un_solo_aviso_a_socios_y_uno_al_entrenador(): void
    {
        foreach (['2026-10-07', '2026-10-14', '2026-10-21'] as $i => $fecha) {
            $this->reserva($this->socio("Semana {$i}"), $fecha);
        }
        $globales = CatalogEvent::count();
        $entrenador = TrainerRealtimeEvent::count();

        $this->mover('Viernes');

        $this->assertSame(1, CatalogEvent::count() - $globales, 'Tres reservas movidas, un aviso global.');
        $this->assertSame(1, TrainerRealtimeEvent::count() - $entrenador);
        $this->assertSame(6, ClassEvent::where('class_id', $this->clase->id)->where('type', 'like', 'booking.%')->count(), 'Pero cada movimiento queda en el registro del CRM.');
    }

    public function test_una_pestana_vieja_del_crm_no_mueve_una_clase_de_fecha_unica(): void
    {
        $unica = MyClass::create([
            'name' => 'Masterclass', 'type' => 'Yoga', 'day_of_week' => 'Sábado', 'start_time' => '20:00',
            'end_time' => '21:00', 'status' => 'active', 'max_capacity' => 10, 'allow_online_booking' => true,
            'is_recurring' => false, 'date_time' => '2026-10-10 20:00:00',
        ]);
        $socio = $this->socio('Con plaza');
        ClassReservation::create(['class_id' => $unica->id, 'member_id' => $socio->id, 'session_date' => '2026-10-10']);

        // Lo que manda el CRM desplegado al solo renombrarla: una fecha recalculada en el navegador.
        $this->adminPatchJson("/api/classes/{$unica->id}", [
            'name' => 'Masterclass de octubre', 'is_recurring' => false, 'date_time' => '2026-10-04T20:00:00',
        ])->assertOk();

        $unica->refresh();
        $this->assertSame('Masterclass de octubre', $unica->name);
        $this->assertSame('2026-10-10', $unica->date_time->toDateString(), 'La fecha no se mueve sin pedirlo.');
        $this->assertSame('Sábado', $unica->day_of_week);
        $this->assertSame(1, ClassReservation::where('class_id', $unica->id)->count(), 'Y la reserva sigue.');
        $this->assertSame([], $this->avisosDeCambio($socio));

        // Con la intención explícita, sí se reprograma (y la reserva se mueve o cae con aviso).
        $this->adminPatchJson("/api/classes/{$unica->id}", [
            'is_recurring' => false, 'date_time' => '2026-10-17T20:00:00', 'reschedule' => true,
        ])->assertOk();
        $this->assertSame('2026-10-17', $unica->fresh()->date_time->toDateString());
        $this->assertSame(0, ClassReservation::where('class_id', $unica->id)->whereDate('session_date', '2026-10-10')->count());
    }

    public function test_cambiar_solo_la_hora_de_una_clase_de_fecha_unica_si_se_aplica(): void
    {
        $unica = MyClass::create([
            'name' => 'Masterclass', 'type' => 'Yoga', 'day_of_week' => 'Sábado', 'start_time' => '20:00',
            'end_time' => '21:00', 'status' => 'active', 'max_capacity' => 10, 'allow_online_booking' => true,
            'is_recurring' => false, 'date_time' => '2026-10-10 20:00:00',
        ]);

        // El CRM nuevo sin tocar la fecha (sin reschedule): la misma fecha, otra hora.
        $this->adminPatchJson("/api/classes/{$unica->id}", [
            'is_recurring' => false, 'start_time' => '19:00', 'end_time' => '20:00', 'date_time' => '2026-10-10T19:00:00',
        ])->assertOk()->assertJsonPath('data.date_time', '2026-10-10T19:00:00');

        // Una pestaña vieja: fecha recalculada y otra hora. La fecha se queda; la hora sí cambia.
        $this->adminPatchJson("/api/classes/{$unica->id}", [
            'is_recurring' => false, 'start_time' => '18:30', 'end_time' => '19:30', 'date_time' => '2026-10-04T18:30:00',
        ])->assertOk()->assertJsonPath('data.date_time', '2026-10-10T18:30:00');
        $this->assertSame('Sábado', $unica->fresh()->day_of_week);
    }
}
