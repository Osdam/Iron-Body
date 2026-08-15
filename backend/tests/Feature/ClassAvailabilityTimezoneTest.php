<?php

namespace Tests\Feature;

use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El backend corre en UTC y el gimnasio opera en America/Bogota (UTC-5). Desde
 * las 19:00 hora local, `Carbon::today()` ya devuelve el día siguiente.
 *
 * `GET /api/app/classes` usaba ese "hoy" en UTC para traer las reservas
 * vigentes, así que a partir de esa hora descartaba las reservas de la clase del
 * día en curso: el cupo se veía libre y la clase aparecía "Disponible" pese a
 * estar llena, y al miembro con reserva se le volvía a ofrecer "Reservar".
 * Al pulsar, `reserve()` —que sí calcula la ocurrencia en hora local— respondía
 * "Clase completa" o "Ya tienes una reserva".
 */
class ClassAvailabilityTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /** Miércoles 17/06/2026 21:00 en Bogotá = jueves 18/06/2026 02:00 UTC. */
    private const NOW_UTC = '2026-06-18 02:00:00';

    private const LOCAL_DATE = '2026-06-17';

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));

        $this->member = $this->makeMember('700700700');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeMember(string $doc): Member
    {
        return Member::create([
            'full_name' => 'Socio '.$doc,
            'document_number' => $doc,
            'phone' => '+57300'.substr($doc, -7),
            'access_hash' => 'tok-'.$doc,
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    /** Clase recurrente del MIÉRCOLES, es decir la de hoy en hora local. */
    private function makeClass(int $capacity): MyClass
    {
        return MyClass::create([
            'name' => 'Funcional', 'type' => 'Funcional',
            'day_of_week' => 'Miércoles', 'start_time' => '10:00', 'end_time' => '11:00',
            'status' => 'active', 'max_capacity' => $capacity, 'allow_online_booking' => true,
        ]);
    }

    /** La fecha de la ocurrencia se calcula en hora local, no en UTC. */
    public function test_la_ocurrencia_del_dia_se_calcula_en_hora_local(): void
    {
        $class = $this->makeClass(10);

        $this->assertSame(
            self::LOCAL_DATE,
            $class->operationalOccurrence()->toDateString(),
        );
    }

    public function test_una_clase_llena_no_aparece_disponible_de_noche(): void
    {
        $class = $this->makeClass(capacity: 1);

        // Otro socio ya ocupó el único cupo de la ocurrencia de HOY (hora local).
        ClassReservation::create([
            'class_id' => $class->id,
            'member_id' => $this->makeMember('700700701')->id,
            'session_date' => self::LOCAL_DATE,
            'reserved_at' => now(),
        ]);

        $card = $this->getJson('/api/app/classes', $this->auth($this->member))
            ->assertOk()
            ->json('data.0');

        $this->assertSame(1, $card['booked_spots']);
        $this->assertSame(0, $card['available_spots']);
        $this->assertSame('full', $card['status']);
        $this->assertFalse($card['can_reserve']);
    }

    public function test_la_reserva_propia_sigue_visible_de_noche(): void
    {
        $class = $this->makeClass(capacity: 10);

        ClassReservation::create([
            'class_id' => $class->id,
            'member_id' => $this->member->id,
            'session_date' => self::LOCAL_DATE,
            'reserved_at' => now(),
        ]);

        $card = $this->getJson('/api/app/classes', $this->auth($this->member))
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($card['is_reserved']);
        $this->assertFalse($card['can_reserve']);
    }

    /**
     * La lista y el endpoint de reserva tienen que coincidir: si la card dice
     * "Disponible", reservar no puede responder "Clase completa".
     */
    public function test_la_lista_y_la_reserva_coinciden_de_noche(): void
    {
        $class = $this->makeClass(capacity: 1);
        ClassReservation::create([
            'class_id' => $class->id,
            'member_id' => $this->makeMember('700700702')->id,
            'session_date' => self::LOCAL_DATE,
            'reserved_at' => now(),
        ]);

        $card = $this->getJson('/api/app/classes', $this->auth($this->member))
            ->assertOk()->json('data.0');

        $this->assertFalse($card['can_reserve'], 'La lista debe reflejar que no queda cupo.');

        $this->postJson("/api/app/classes/{$class->id}/reserve", [], $this->auth($this->member))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Clase completa.');
    }
}
