<?php

namespace Tests\Feature\Classes;

use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El recordatorio «Tu clase está por comenzar».
 *
 * Leía la hora de la clase sobre el reloj UTC del servidor: el de una clase de
 * las 18:00 salía a las 10:00 de la mañana, y a todo el que alguna vez había
 * reservado esa clase (cualquier semana). Ahora es la sesión que cuentan la app,
 * el CRM y el entrenador, a su hora de pared, y solo a quien la ocupa.
 */
class ClassRemindersTest extends TestCase
{
    use RefreshDatabase;

    private const LUNES = '2026-10-05';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ahora(string $horaBogota, string $fecha = self::LUNES): void
    {
        Carbon::setTestNow(Carbon::parse("{$fecha} {$horaBogota}", 'America/Bogota')->utc());
    }

    private function clase(string $hora, array $extra = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => "Clase {$hora}", 'type' => 'Funcional', 'day_of_week' => 'Lunes',
            'start_time' => $hora, 'end_time' => '23:59', 'status' => 'active',
            'max_capacity' => 20, 'allow_online_booking' => true, 'is_recurring' => true,
        ], $extra));
    }

    private function socio(string $nombre): Member
    {
        static $n = 0;
        $n++;

        return Member::create(['full_name' => $nombre, 'document_number' => '7700'.$n, 'status' => Member::STATUS_ACTIVE]);
    }

    private function reserva(MyClass $clase, Member $socio, ?string $fecha): void
    {
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $socio->id, 'session_date' => $fecha]);
    }

    /** @return array<int, Notification> socio => recordatorio */
    private function recordatorios(): array
    {
        return Notification::query()
            ->where('title', 'Tu clase está por comenzar')
            ->get()
            ->keyBy('member_id')
            ->all();
    }

    private function correr(): void
    {
        $this->artisan('notifications:class-reminders', ['--hours' => 3])->assertSuccessful();
    }

    public function test_avisa_a_quien_ocupa_la_sesion_de_hoy_a_su_hora_de_pared(): void
    {
        $this->ahora('15:30');
        $clase = $this->clase('18:00');
        $hoy = $this->socio('Hoy');
        $heredada = $this->socio('Heredada sin fecha');
        $pasada = $this->socio('Semana pasada');
        $siguiente = $this->socio('Semana siguiente');
        $this->reserva($clase, $hoy, self::LUNES);
        $this->reserva($clase, $heredada, null);
        $this->reserva($clase, $pasada, '2026-09-28');
        $this->reserva($clase, $siguiente, '2026-10-12');

        $this->correr();

        $avisos = $this->recordatorios();
        $this->assertEqualsCanonicalizing([$hoy->id, $heredada->id], array_keys($avisos), 'Solo quien ocupa la sesión de hoy.');
        $this->assertSame('Recuerda tu clase Clase 18:00 a las 18:00.', $avisos[$hoy->id]->message);
        $this->assertSame("class_reminder_{$clase->id}_{$hoy->id}_2026-10-05_18", $avisos[$hoy->id]->event_key);
    }

    public function test_no_avisa_ocho_horas_antes(): void
    {
        // 10:00 en Bogotá = 15:00 UTC: con la hora leída como UTC, la clase de
        // las 18:00 caía dentro de la ventana de 3 horas.
        $this->ahora('10:00');
        $clase = $this->clase('18:00');
        $this->reserva($clase, $this->socio('Madrugador'), self::LUNES);

        $this->correr();

        $this->assertSame([], $this->recordatorios());
    }

    public function test_fuera_de_la_ventana_o_ya_empezada_no_avisa(): void
    {
        $this->ahora('15:30');
        $temprano = $this->clase('10:00');
        $tarde = $this->clase('20:00');
        $iniciada = $this->clase('17:00');
        foreach ([$temprano, $tarde, $iniciada] as $c) {
            $this->reserva($c, $this->socio("Socio {$c->id}"), self::LUNES);
        }
        ClassSession::create(['class_id' => $iniciada->id, 'session_date' => self::LUNES, 'started_at' => now()]);

        $this->correr();

        $this->assertSame([], $this->recordatorios(), 'Pasada, a 4 h y media, e iniciada por el entrenador: ninguna.');
    }

    public function test_una_clase_de_madrugada_se_avisa_la_noche_anterior(): void
    {
        $this->ahora('22:00');
        $clase = $this->clase('00:30', [
            'day_of_week' => 'Martes', 'is_recurring' => false, 'date_time' => '2026-10-06 00:30:00',
        ]);
        $socio = $this->socio('Trasnochador');
        $this->reserva($clase, $socio, '2026-10-06');

        $this->correr();

        $avisos = $this->recordatorios();
        $this->assertSame([$socio->id], array_keys($avisos));
        $this->assertSame("class_reminder_{$clase->id}_{$socio->id}_2026-10-06_00", $avisos[$socio->id]->event_key);
    }

    public function test_correrlo_varias_veces_no_duplica(): void
    {
        $this->ahora('15:30');
        $clase = $this->clase('18:00');
        $this->reserva($clase, $this->socio('Uno'), self::LUNES);

        $this->correr();
        $this->ahora('15:45');
        $this->correr();

        $this->assertSame(1, Notification::where('title', 'Tu clase está por comenzar')->count());
    }
}
