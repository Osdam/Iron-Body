<?php

namespace Tests\Feature\Proactive;

use App\Models\Member;
use App\Models\MemberRoutineAssignment;
use App\Models\Routine;
use App\Models\RoutineCompletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Regresión de `ironbody:detect-workout-not-started` con las formas REALES de
 * `routines.days` en producción:
 *
 *   A) lista de objetos {day,title,objective,exercises} con day = "Lunes".."Viernes"
 *      → SÍ codifican día de la semana.
 *   B) lista de objetos {name,order,exercises} con name = "Día 1".."Día 4"
 *      → NO codifican día de semana (son días ordinales de la rutina).
 *   C) lista de escalares ["lunes","miercoles"] → forma heredada, aún soportada.
 *
 * Con la forma (A)/(B) el comando moría cada día a las 17:00 en producción con
 * `ErrorException: Array to string conversion` y exit 1 SIN evaluar a nadie.
 *
 * El comando se invoca por su firma real y siempre con --dry-run: no escribe ni
 * emite nada (ProactiveCoachService::consider() devuelve would_emit y corta).
 */
class DetectWorkoutNotStartedTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'ironbody:detect-workout-not-started';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            // Nada sale hacia n8n durante el test.
            'automation.enabled' => false,
            // Ventana nula de quiet hours: el resultado depende del calendario
            // de la rutina, no de la hora a la que se ejecute la suite.
            'proactive_coach.quiet_hours.start_hour' => 0,
            'proactive_coach.quiet_hours.end_hour' => 0,
        ]);

        // Por defecto: LUNES 2026-09-14 a las 17:00 hora del gimnasio, la misma
        // hora a la que el scheduler lanza el detector en producción.
        $this->onMonday();
    }

    /** Lunes 2026-09-14 17:00 America/Bogota. */
    private function onMonday(): void
    {
        $this->travelTo(Carbon::parse('2026-09-14 17:00:00', 'America/Bogota'));
    }

    /** Miércoles 2026-09-16 17:00 America/Bogota. */
    private function onWednesday(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 17:00:00', 'America/Bogota'));
    }

    /** Sábado 2026-09-19 17:00 America/Bogota (ninguna rutina real lo incluye). */
    private function onSaturday(): void
    {
        $this->travelTo(Carbon::parse('2026-09-19 17:00:00', 'America/Bogota'));
    }

    private function member(string $name = 'Miembro Prueba'): Member
    {
        return Member::create([
            'full_name' => $name,
            'document_number' => (string) random_int(10000000, 99999999),
            'status' => Member::STATUS_ACTIVE,
        ]);
    }

    /** Crea la rutina con la forma de `days` indicada y se la asigna al miembro. */
    private function assignRoutine(Member $member, ?array $days, string $name = 'Rutina'): Routine
    {
        $routine = Routine::create(['name' => $name, 'days' => $days]);

        MemberRoutineAssignment::create([
            'member_id' => $member->id,
            'routine_id' => $routine->id,
            'assigned_at' => now(),
        ]);

        return $routine;
    }

    /** Forma (A) de producción: objetos con día de semana explícito. */
    private function weekdayProgram(array $weekdays): array
    {
        return array_map(fn (string $day) => [
            'day' => $day,
            'title' => 'Full body',
            'objective' => 'Hipertrofia',
            'exercises' => [['name' => 'Sentadilla', 'sets' => 4, 'reps' => '10']],
        ], $weekdays);
    }

    /** Forma (B) de producción: días ordinales, sin calendario semanal. */
    private function ordinalProgram(int $days): array
    {
        return array_map(fn (int $i) => [
            'name' => "Día {$i}",
            'order' => $i,
            'exercises' => [['name' => 'Press banca', 'sets' => 4, 'reps' => '8']],
        ], range(1, $days));
    }

    /** El detector considera al miembro (lo vería en el resumen como would_emit). */
    private function assertConsidered(Member $member): void
    {
        $this->artisan(self::COMMAND, ['--dry-run' => true])
            ->expectsOutputToContain("would_emit  workout.not_started_today → member {$member->id}")
            ->expectsOutputToContain('emitidos:0 would_emit:1 ')
            ->assertExitCode(0);
    }

    /** El detector NO considera al miembro, pero termina bien (exit 0). */
    private function assertNotConsidered(Member $member): void
    {
        $this->artisan(self::COMMAND, ['--dry-run' => true])
            ->doesntExpectOutputToContain("→ member {$member->id}")
            ->expectsOutputToContain('emitidos:0 would_emit:0 ')
            ->assertExitCode(0);
    }

    // ── (a) forma {day: "Lunes"}: no puede lanzar; respeta el calendario ──────

    public function test_weekday_object_days_are_expected_on_that_weekday(): void
    {
        $member = $this->member();
        $this->assignRoutine($member, $this->weekdayProgram(['Lunes', 'Miércoles', 'Viernes']));

        $this->onMonday();

        $this->assertConsidered($member);
    }

    public function test_weekday_object_days_are_not_expected_on_another_weekday(): void
    {
        $member = $this->member();
        $this->assignRoutine($member, $this->weekdayProgram(['Lunes', 'Miércoles', 'Viernes']));

        $this->onSaturday();

        $this->assertNotConsidered($member);
    }

    /** El acento importa: "Miércoles" debe casar el miércoles. */
    public function test_weekday_object_days_match_accented_weekday(): void
    {
        $member = $this->member();
        $this->assignRoutine($member, $this->weekdayProgram(['Miércoles']));

        $this->onWednesday();

        $this->assertConsidered($member);
    }

    // ── (b) forma {name: "Día 1"}: sin calendario → cualquier día vale ────────

    public function test_ordinal_day_objects_have_no_weekly_calendar_and_match_any_day(): void
    {
        $member = $this->member();
        $this->assignRoutine($member, $this->ordinalProgram(4));

        $this->onSaturday(); // ningún día de semana está codificado en la rutina

        $this->assertConsidered($member);
    }

    // ── (c) forma escalar heredada ───────────────────────────────────────────

    public function test_legacy_scalar_days_still_match_today(): void
    {
        $member = $this->member();
        $this->assignRoutine($member, ['lunes', 'miercoles']);

        $this->onMonday();

        $this->assertConsidered($member);
    }

    public function test_legacy_scalar_days_still_exclude_other_days(): void
    {
        $member = $this->member();
        $this->assignRoutine($member, ['lunes', 'miercoles']);

        $this->onSaturday();

        $this->assertNotConsidered($member);
    }

    // ── (d) days nulo o vacío → cualquier día vale ───────────────────────────

    public function test_null_days_match_any_day(): void
    {
        $member = $this->member();
        $this->assignRoutine($member, null);

        $this->onSaturday();

        $this->assertConsidered($member);
    }

    public function test_empty_days_match_any_day(): void
    {
        $member = $this->member();
        $this->assignRoutine($member, []);

        $this->onSaturday();

        $this->assertConsidered($member);
    }

    // ── (e) ya entrenó hoy → no se considera ─────────────────────────────────

    public function test_member_that_already_completed_today_is_not_considered(): void
    {
        $member = $this->member();
        $routine = $this->assignRoutine($member, $this->weekdayProgram(['Lunes']));

        RoutineCompletion::create([
            'member_id' => $member->id,
            'routine_id' => $routine->id,
            'completed_at' => now(),
            'source' => 'app',
        ]);

        $this->onMonday();

        $this->assertNotConsidered($member);
    }

    // ── (f) el comando termina en 0 con el conjunto real de producción ───────

    public function test_command_exits_zero_with_production_shaped_data(): void
    {
        $conCalendario = $this->member('Con calendario');
        $this->assignRoutine($conCalendario, $this->weekdayProgram(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes']), 'Plan 5 días');

        $ordinal = $this->member('Ordinal');
        $this->assignRoutine($ordinal, $this->ordinalProgram(4), 'Plan por días');

        $heredada = $this->member('Heredada');
        $this->assignRoutine($heredada, ['lunes', 'miercoles', 'viernes'], 'Plan antiguo');

        $sinRutina = $this->member('Sin rutina'); // sin asignación → no aplica

        $this->onMonday();

        $this->artisan(self::COMMAND, ['--dry-run' => true])
            ->expectsOutputToContain("would_emit  workout.not_started_today → member {$conCalendario->id}")
            ->expectsOutputToContain("would_emit  workout.not_started_today → member {$ordinal->id}")
            ->expectsOutputToContain("would_emit  workout.not_started_today → member {$heredada->id}")
            ->doesntExpectOutputToContain("→ member {$sinRutina->id}")
            ->expectsOutputToContain('emitidos:0 would_emit:3 ')
            ->assertExitCode(0);
    }
}
