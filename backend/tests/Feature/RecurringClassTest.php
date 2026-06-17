<?php

namespace Tests\Feature;

use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Clases recurrentes semanales vs clases únicas. La clase base es recurrente por
 * day_of_week+hora (el CRM no envía fecha → date_time null); una clase única se
 * crea con is_recurring=false y fecha fija, y el día se DERIVA de esa fecha. Las
 * reservas siempre son por (class_id, member_id, session_date), compartidas por
 * Clases, "Organizar mi semana" y el entrenador.
 */
class RecurringClassTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    private Carbon $monday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->monday = Carbon::parse('2026-06-15 08:00:00')->startOfWeek(Carbon::MONDAY)->setTime(8, 0);
        Carbon::setTestNow($this->monday);

        $this->member = Member::create([
            'full_name' => 'Rec Member', 'document_number' => '910910910',
            'phone' => '+573009109109', 'access_hash' => 'tok-910', 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    private function dayOfWeek(int $index): string
    {
        return $this->monday->copy()->addDays($index)->toDateString();
    }

    /** Entrada del plan semanal para (clase, fecha) o null. */
    private function weeklyEntry(int $classId, string $date, ?string $weekStart = null): ?array
    {
        $q = $weekStart ? "?week_start={$weekStart}" : '';
        $res = $this->getJson("/api/app/classes/weekly{$q}", $this->auth())->assertOk();
        foreach ($res->json('days') as $day) {
            foreach ($day['classes'] as $c) {
                if ((int) $c['class_id'] === $classId && $c['session_date'] === $date) {
                    return $c;
                }
            }
        }

        return null;
    }

    // ── Recurrente (modelo principal del gimnasio) ───────────────────────────

    public function test_create_recurring_class_appears_this_week_and_future_weeks(): void
    {
        // El CRM crea una recurrente con día+hora, SIN fecha (date_time null).
        $res = $this->postJson('/api/classes', [
            'name' => 'IRON FUNCIONAL', 'type' => 'Funcional',
            'day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00',
            'max_capacity' => 20, 'status' => 'active', 'is_recurring' => true,
        ])->assertCreated();
        $classId = (int) ($res->json('data.id') ?? $res->json('id'));

        $this->assertNull(MyClass::find($classId)->date_time, 'Una recurrente del CRM no fija date_time.');

        // Aparece el miércoles de ESTA semana.
        $this->assertNotNull($this->weeklyEntry($classId, $this->dayOfWeek(2)));

        // Y el miércoles de la PRÓXIMA semana (recurre).
        $nextMonday = $this->monday->copy()->addWeek()->toDateString();
        $nextWed = $this->monday->copy()->addWeek()->addDays(2)->toDateString();
        $this->assertNotNull($this->weeklyEntry($classId, $nextWed, $nextMonday),
            'La clase recurrente debe aparecer también en semanas futuras.');
    }

    public function test_recurring_reserve_uses_session_date_per_occurrence(): void
    {
        $res = $this->postJson('/api/classes', [
            'name' => 'IRON FUNCIONAL', 'type' => 'Funcional',
            'day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00',
            'max_capacity' => 20, 'status' => 'active', 'is_recurring' => true,
        ])->assertCreated();
        $classId = (int) ($res->json('data.id') ?? $res->json('id'));

        // Reserva esta semana (Clases) y la próxima (Organizar mi semana).
        $this->postJson("/api/classes/{$classId}/reserve", [], $this->auth())->assertOk();
        $nextWed = $this->monday->copy()->addWeek()->addDays(2)->toDateString();
        $this->postJson('/api/app/classes/weekly/reserve', [
            'items' => [['class_id' => $classId, 'session_date' => $nextWed]],
        ], $this->auth())->assertOk();

        // Dos reservas, MISMA clase/miembro, distinto session_date (historial por fecha).
        $dates = ClassReservation::where('class_id', $classId)->where('member_id', $this->member->id)
            ->orderBy('session_date')->pluck('session_date')->map(fn ($d) => $d->toDateString())->all();
        $this->assertSame([$this->dayOfWeek(2), $nextWed], $dates);
    }

    // ── Clase única (no recurrente) ──────────────────────────────────────────

    public function test_create_single_class_derives_day_and_only_its_week(): void
    {
        // is_recurring=false + fecha fija; el día llega CONTRADICTORIO a propósito
        // ('Lunes') y debe derivarse de la fecha (miércoles 17).
        $res = $this->postJson('/api/classes', [
            'name' => 'Evento especial', 'type' => 'Funcional',
            'day_of_week' => 'Lunes', 'start_time' => '09:00', 'end_time' => '10:00',
            'max_capacity' => 20, 'status' => 'active', 'is_recurring' => false,
            'date_time' => '2026-06-17 09:00:00',
        ])->assertCreated();
        $classId = (int) ($res->json('data.id') ?? $res->json('id'));

        // Día derivado de la fecha (no se confía en el cliente).
        $this->assertSame('Miércoles', MyClass::find($classId)->day_of_week);

        // Aparece solo en su semana...
        $this->assertNotNull($this->weeklyEntry($classId, '2026-06-17'));

        // ...y NO en una semana futura (no recurre).
        $nextMonday = $this->monday->copy()->addWeek()->toDateString();
        $nextWed = $this->monday->copy()->addWeek()->addDays(2)->toDateString();
        $this->assertNull($this->weeklyEntry($classId, $nextWed, $nextMonday),
            'Una clase única no debe recurrir en semanas futuras.');
    }

    public function test_single_class_requires_date_returns_controlled_422(): void
    {
        $this->postJson('/api/classes', [
            'name' => 'Sin fecha', 'type' => 'Funcional',
            'day_of_week' => 'Lunes', 'start_time' => '09:00', 'end_time' => '10:00',
            'max_capacity' => 20, 'status' => 'active', 'is_recurring' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['date_time']);
    }

    public function test_recurring_class_with_effective_from_hides_earlier_weeks(): void
    {
        // Recurrente con fecha de inicio de vigencia futura (próximo miércoles):
        // NO aparece el miércoles de esta semana, sí el de la próxima.
        $nextWed = $this->monday->copy()->addWeek()->addDays(2);
        $class = MyClass::create([
            'name' => 'Nueva recurrente', 'type' => 'Funcional',
            'day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00',
            'max_capacity' => 20, 'status' => 'active', 'is_recurring' => true,
            'date_time' => $nextWed->copy()->setTime(19, 0)->toDateTimeString(),
        ]);

        $this->assertNull($this->weeklyEntry($class->id, $this->dayOfWeek(2)),
            'No debe aparecer antes de su fecha de inicio de vigencia.');
        $nextMonday = $this->monday->copy()->addWeek()->toDateString();
        $this->assertNotNull($this->weeklyEntry($class->id, $nextWed->toDateString(), $nextMonday));
    }

    public function test_existing_classes_without_date_time_still_recurring(): void
    {
        // Compatibilidad: clase creada como antes (sin date_time) sigue recurrente.
        $class = MyClass::create([
            'name' => 'Legacy', 'type' => 'Funcional',
            'day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00',
            'max_capacity' => 20, 'status' => 'active',
        ]);
        $occ = $class->operationalOccurrence();
        $this->assertSame($this->dayOfWeek(2), $occ->toDateString());
    }
}
