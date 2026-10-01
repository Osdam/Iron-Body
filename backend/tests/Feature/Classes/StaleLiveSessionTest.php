<?php

namespace Tests\Feature\Classes;

use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Una sesión que el entrenador inició y olvidó cerrar.
 *
 * La app de los socios tomaba cualquier sesión iniciada y sin cerrar como la
 * vigente, de cualquier fecha: la clase quedaba «en curso» para siempre, sin
 * cancelación posible, mientras la reserva de la semana siguiente seguía
 * abierta. Cuenta como en curso solo la de hoy o la de ayer (la que pasa de la
 * medianoche), igual que el check-in.
 */
class StaleLiveSessionTest extends TestCase
{
    use RefreshDatabase;

    private const LUNES = '2026-10-05';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ahora(string $fechaHoraBogota): void
    {
        Carbon::setTestNow(Carbon::parse($fechaHoraBogota, 'America/Bogota')->utc());
    }

    private function socio(): Member
    {
        $m = Member::create([
            'full_name' => 'Socio Vigente', 'document_number' => '5150001', 'phone' => '+573005150001',
            'access_hash' => 'tok-5150001', 'status' => Member::STATUS_ACTIVE,
        ]);

        return $this->givePlanWithClasses($m);
    }

    private function clase(string $hora): MyClass
    {
        return MyClass::create([
            'name' => 'Funcional', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => $hora,
            'end_time' => '23:59', 'status' => 'active', 'max_capacity' => 20,
            'allow_online_booking' => true, 'is_recurring' => true,
        ]);
    }

    private function enLaApp(MyClass $clase, Member $socio): array
    {
        return collect($this->getJson('/api/app/classes', ['Authorization' => 'Bearer '.$socio->access_hash])->assertOk()->json('data'))
            ->firstWhere('id', (string) $clase->id);
    }

    public function test_una_sesion_olvidada_de_hace_dias_no_deja_la_clase_en_curso(): void
    {
        $this->ahora(self::LUNES.' 10:00');
        $clase = $this->clase('17:22');
        $socio = $this->socio();
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $socio->id, 'session_date' => self::LUNES]);
        ClassSession::create([
            'class_id' => $clase->id, 'session_date' => '2026-09-21',
            'started_at' => Carbon::parse('2026-09-21 17:25', 'America/Bogota')->utc(),
        ]);

        $vista = $this->enLaApp($clase, $socio);

        $this->assertSame('scheduled', $vista['session_status']);
        $this->assertTrue($vista['can_cancel'], 'La reserva de hoy se puede cancelar.');

        $this->deleteJson("/api/app/classes/{$clase->id}/reserve", [], ['Authorization' => 'Bearer '.$socio->access_hash])
            ->assertOk();
    }

    public function test_la_que_pasa_de_la_medianoche_sigue_en_curso(): void
    {
        $this->ahora('2026-10-06 00:10');
        $clase = $this->clase('23:00');
        $socio = $this->socio();
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $socio->id, 'session_date' => self::LUNES]);
        ClassSession::create([
            'class_id' => $clase->id, 'session_date' => self::LUNES,
            'started_at' => Carbon::parse(self::LUNES.' 23:02', 'America/Bogota')->utc(),
        ]);

        $this->assertSame('live', $this->enLaApp($clase, $socio)['session_status']);
    }
}
