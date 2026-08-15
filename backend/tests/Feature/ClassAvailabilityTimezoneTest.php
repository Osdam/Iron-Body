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
        // Reservar exige un plan con clases (ver MemberClassContext).
        return $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socio '.$doc,
            'document_number' => $doc,
            'phone' => '+57300'.substr($doc, -7),
            'access_hash' => 'tok-'.$doc,
            'status' => Member::STATUS_ACTIVE,
        ]));
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

    /**
     * La app bloquea la pestaña Clases cuando el plan no las incluye, pero la
     * API no lo comprobaba: una llamada directa reservaba igual y ocupaba un
     * cupo que corresponde a otro socio.
     */
    public function test_un_plan_sin_clases_no_puede_reservar(): void
    {
        $class = $this->makeClass(capacity: 10);

        $sinClases = Member::create([
            'full_name' => 'Sin clases',
            'document_number' => '700700703',
            'phone' => '+573007007003',
            'access_hash' => 'tok-700700703',
            'status' => Member::STATUS_ACTIVE,
        ]);
        $this->givePlanWithClasses($sinClases, ['classes' => false]);

        $this->postJson("/api/app/classes/{$class->id}/reserve", [], $this->auth($sinClases))
            ->assertStatus(403)
            ->assertJsonPath('code', 'classes_not_in_plan');

        // La ruta alias que usa la app móvil cierra el mismo hueco.
        $this->postJson("/api/classes/{$class->id}/reserve", [], $this->auth($sinClases))
            ->assertStatus(403)
            ->assertJsonPath('code', 'classes_not_in_plan');

        // Y la reserva semanal en lote.
        $this->postJson('/api/app/classes/weekly/reserve', [
            'items' => [['class_id' => $class->id, 'session_date' => self::LOCAL_DATE]],
        ], $this->auth($sinClases))
            ->assertStatus(403)
            ->assertJsonPath('code', 'classes_not_in_plan');

        $this->assertSame(0, ClassReservation::where('member_id', $sinClases->id)->count());
    }

    /**
     * Una membresía vencida tampoco puede tomar cupo. Se usa una fecha de hace
     * varios días a propósito: el plan sigue vigente hasta la medianoche LOCAL
     * del último día, así que "ayer" en UTC todavía contaría como vigente.
     */
    public function test_una_membresia_vencida_no_puede_reservar(): void
    {
        $class = $this->makeClass(capacity: 10);

        $vencido = $this->makeMember('700700704');
        $vencido->user->forceFill([
            'membership_end_date' => now()->subDays(3)->toDateString(),
        ])->save();

        $this->postJson("/api/app/classes/{$class->id}/reserve", [], $this->auth($vencido))
            ->assertStatus(403)
            ->assertJsonPath('code', 'classes_not_in_plan');
    }
}
