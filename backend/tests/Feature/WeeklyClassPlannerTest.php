<?php

namespace Tests\Feature;

use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Notification;
use App\Models\Trainer;
use App\Models\TrainerRealtimeEvent;
use App\Services\ClassRenewalService;
use App\Services\Trainer\ClassAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Organizar mi semana": planificación semanal y reserva en lote por OCURRENCIA
 * (fecha), conservando la reserva individual, los cupos por fecha y sin que el
 * reset de renovación borre reservas futuras.
 */
class WeeklyClassPlannerTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    private Carbon $monday;

    protected function setUp(): void
    {
        parent::setUp();
        // Lunes 08:00 fijo → las clases de mié/vie de esta semana son futuras.
        $this->monday = Carbon::parse('2026-06-15 08:00:00')->startOfWeek(Carbon::MONDAY)->setTime(8, 0);
        Carbon::setTestNow($this->monday);

        $this->member = $this->makeMember('900900900');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeMember(string $doc): Member
    {
        return Member::create([
            'full_name' => 'Plan '.$doc,
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

    private function makeClass(array $attrs = []): MyClass
    {
        return MyClass::create(array_merge([
            'name' => 'Funcional', 'type' => 'Funcional',
            'day_of_week' => 'Miércoles', 'start_time' => '10:00', 'end_time' => '11:00',
            'status' => 'active', 'max_capacity' => 10, 'allow_online_booking' => true,
        ], $attrs));
    }

    /** Fecha (Y-m-d) del día $index (0=lunes) de la semana de prueba. */
    private function dayOfWeek(int $index): string
    {
        return $this->monday->copy()->addDays($index)->toDateString();
    }

    public function test_weekly_plan_lists_real_classes_with_capacity(): void
    {
        $class = $this->makeClass(); // Miércoles (índice 2)

        $res = $this->getJson('/api/app/classes/weekly', $this->auth($this->member))->assertOk();

        $wednesday = collect($res->json('days'))->firstWhere('weekday', 3);
        $this->assertNotNull($wednesday);
        $entry = collect($wednesday['classes'])->firstWhere('class_id', $class->id);
        $this->assertNotNull($entry, 'La clase real del CRM debe aparecer en su día.');
        $this->assertSame($this->dayOfWeek(2), $entry['session_date']);
        $this->assertSame(10, $entry['available_spots']);
        $this->assertTrue($entry['can_reserve']);
        $this->assertFalse($entry['is_reserved']);
    }

    public function test_reserve_week_batch_partial_result(): void
    {
        $a = $this->makeClass(['name' => 'A', 'day_of_week' => 'Miércoles', 'start_time' => '10:00', 'end_time' => '11:00']);
        $full = $this->makeClass(['name' => 'B', 'day_of_week' => 'Viernes', 'start_time' => '10:00', 'end_time' => '11:00', 'max_capacity' => 1]);

        // Otro miembro llena la clase B para su ocurrencia del viernes.
        $other = $this->makeMember('800800800');
        ClassReservation::create(['class_id' => $full->id, 'member_id' => $other->id, 'session_date' => $this->dayOfWeek(4)]);

        $res = $this->postJson('/api/app/classes/weekly/reserve', [
            'items' => [
                ['class_id' => $a->id, 'session_date' => $this->dayOfWeek(2)],
                ['class_id' => $full->id, 'session_date' => $this->dayOfWeek(4)],
            ],
        ], $this->auth($this->member))->assertOk();

        $this->assertSame(1, $res->json('summary.reserved'));
        $this->assertSame(1, $res->json('summary.full'));
        $this->assertSame(1, ClassReservation::where('class_id', $a->id)
            ->where('member_id', $this->member->id)
            ->whereDate('session_date', $this->dayOfWeek(2))->count());

        // Notificación interna de confirmación registrada (no sistema paralelo).
        $this->assertSame(1, Notification::where('member_id', $this->member->id)
            ->where('title', 'Tu semana quedó organizada')->count());
    }

    public function test_reserve_week_no_duplicate(): void
    {
        $a = $this->makeClass();
        $payload = ['items' => [['class_id' => $a->id, 'session_date' => $this->dayOfWeek(2)]]];

        $first = $this->postJson('/api/app/classes/weekly/reserve', $payload, $this->auth($this->member))->assertOk();
        $this->assertSame(1, $first->json('summary.reserved'));

        // Reenviar la misma ocurrencia NO duplica: se reporta como ya reservada.
        $second = $this->postJson('/api/app/classes/weekly/reserve', $payload, $this->auth($this->member))->assertOk();
        $this->assertSame(0, $second->json('summary.reserved'));
        $this->assertSame(1, $second->json('summary.already'));

        $this->assertSame(1, ClassReservation::where('class_id', $a->id)
            ->where('member_id', $this->member->id)->count());
    }

    public function test_reserve_week_does_not_exceed_capacity(): void
    {
        $class = $this->makeClass(['max_capacity' => 1]);
        $other = $this->makeMember('700700700');
        ClassReservation::create(['class_id' => $class->id, 'member_id' => $other->id, 'session_date' => $this->dayOfWeek(2)]);

        $res = $this->postJson('/api/app/classes/weekly/reserve', [
            'items' => [['class_id' => $class->id, 'session_date' => $this->dayOfWeek(2)]],
        ], $this->auth($this->member))->assertOk();

        $this->assertSame(1, $res->json('summary.full'));
        $this->assertSame(1, ClassReservation::where('class_id', $class->id)->count());
    }

    public function test_time_conflict_in_selection_is_skipped(): void
    {
        $x = $this->makeClass(['name' => 'X', 'day_of_week' => 'Miércoles', 'start_time' => '10:00', 'end_time' => '11:00']);
        $y = $this->makeClass(['name' => 'Y', 'day_of_week' => 'Miércoles', 'start_time' => '10:30', 'end_time' => '11:30']);

        $res = $this->postJson('/api/app/classes/weekly/reserve', [
            'items' => [
                ['class_id' => $x->id, 'session_date' => $this->dayOfWeek(2)],
                ['class_id' => $y->id, 'session_date' => $this->dayOfWeek(2)],
            ],
        ], $this->auth($this->member))->assertOk();

        $this->assertSame(1, $res->json('summary.reserved'));
        $this->assertSame(1, $res->json('summary.conflict'));
    }

    public function test_individual_reserve_still_works(): void
    {
        $class = $this->makeClass();

        $this->postJson("/api/app/classes/{$class->id}/reserve", [], $this->auth($this->member))->assertOk();

        $reservation = ClassReservation::where('class_id', $class->id)
            ->where('member_id', $this->member->id)->first();
        $this->assertNotNull($reservation);
        $this->assertSame($this->dayOfWeek(2), $reservation->session_date->toDateString());
    }

    public function test_alias_reserve_path_is_per_occurrence(): void
    {
        // La app reserva por /classes/{id}/reserve (ClassController). Debe sellar
        // session_date y respetar cupo por fecha igual que el planificador.
        $class = $this->makeClass(['max_capacity' => 1]);

        $this->postJson("/api/classes/{$class->id}/reserve", [], $this->auth($this->member))
            ->assertOk();

        $reservation = ClassReservation::where('class_id', $class->id)
            ->where('member_id', $this->member->id)->first();
        $this->assertNotNull($reservation);
        $this->assertSame($this->dayOfWeek(2), $reservation->session_date->toDateString());

        // Otro miembro: clase llena para esa ocurrencia → 422 (sin sobrecupo).
        $other = $this->makeMember('600600600');
        $this->postJson("/api/classes/{$class->id}/reserve", [], $this->auth($other))
            ->assertStatus(422);
        $this->assertSame(1, ClassReservation::where('class_id', $class->id)->count());
    }

    public function test_renewal_keeps_future_reservations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-19 12:00:00')); // viernes
        $class = $this->makeClass(['day_of_week' => 'Lunes', 'start_time' => '07:00', 'end_time' => '08:00', 'renewal_hours' => 24]);

        $pastDate = Carbon::parse('2026-06-15')->toDateString();   // lunes de esta semana (pasado)
        $futureDate = Carbon::parse('2026-06-22')->toDateString(); // lunes siguiente (futuro)

        ClassReservation::create(['class_id' => $class->id, 'member_id' => $this->member->id, 'session_date' => $pastDate]);
        ClassReservation::create(['class_id' => $class->id, 'member_id' => $this->member->id, 'session_date' => $futureDate]);

        ClassSession::create([
            'class_id' => $class->id, 'session_date' => $pastDate,
            'started_at' => Carbon::parse('2026-06-15 07:00:00'),
            'ended_at' => Carbon::parse('2026-06-15 08:00:00'), // finalizó hace >24h
        ]);

        app(ClassRenewalService::class)->renewDue();

        $this->assertSame(0, ClassReservation::where('class_id', $class->id)
            ->whereDate('session_date', $pastDate)->count(), 'La reserva vencida debe limpiarse.');
        $this->assertSame(1, ClassReservation::where('class_id', $class->id)
            ->whereDate('session_date', $futureDate)->count(), 'La reserva FUTURA debe conservarse.');
    }

    public function test_closed_class_cannot_be_reserved(): void
    {
        $class = $this->makeClass();
        ClassSession::create([
            'class_id' => $class->id, 'session_date' => $this->dayOfWeek(2),
            'started_at' => $this->monday->copy()->addDays(2)->setTime(10, 0),
            'ended_at' => $this->monday->copy()->addDays(2)->setTime(11, 0),
        ]);

        $res = $this->postJson('/api/app/classes/weekly/reserve', [
            'items' => [['class_id' => $class->id, 'session_date' => $this->dayOfWeek(2)]],
        ], $this->auth($this->member))->assertOk();

        $this->assertSame(1, $res->json('summary.closed'));
        $this->assertSame(0, ClassReservation::where('class_id', $class->id)->count());
    }

    public function test_trainer_roster_and_realtime_for_date(): void
    {
        $trainer = Trainer::create([
            'full_name' => 'Coach R', 'document' => '555', 'phone' => '+573009990055', 'status' => 'active',
        ]);
        $class = $this->makeClass(['trainer_id' => $trainer->id]);

        $this->postJson('/api/app/classes/weekly/reserve', [
            'items' => [['class_id' => $class->id, 'session_date' => $this->dayOfWeek(2)]],
        ], $this->auth($this->member))->assertOk();

        // El entrenador ve al inscrito en la sesión de ESA fecha.
        $participants = app(ClassAttendanceService::class)
            ->participants($class, Carbon::parse($this->dayOfWeek(2)));
        $this->assertCount(1, $participants);
        $this->assertSame($this->member->id, $participants[0]['member_id']);

        // SSE: señal real-time emitida al portal del entrenador dueño.
        $this->assertTrue(
            TrainerRealtimeEvent::where('trainer_id', $trainer->id)
                ->where('type', 'class.updated')->exists()
        );
    }
}
