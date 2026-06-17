<?php

namespace Tests\Feature;

use App\Models\ClassAttendance;
use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\MemberRealtimeEvent;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Models\TrainerRole;
use App\Services\Identity\IdentityLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fase 9 — Clases y asistencia (entrenador funcional). Verifica agenda propia,
 * participantes autorizados, registro sin doble marcado, corrección auditada,
 * clase no activa, propiedad de la clase y permisos.
 */
class TrainerClassAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private Trainer $trainer;

    private string $token;

    private MyClass $class;

    private Member $member;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.flags.trainer_classes_enabled' => true,
        ]);
        $this->today = now()->toDateString();

        $this->trainer = $this->makeTrainer('100', [TrainerRole::FUNCTIONAL]);
        $this->token = $this->login('100');

        $this->class = MyClass::create([
            'name' => 'Funcional AM', 'type' => 'Funcional',
            'trainer_id' => $this->trainer->id,
            'day_of_week' => 'Lunes', 'start_time' => '07:00', 'end_time' => '08:00',
            'status' => 'active', 'max_capacity' => 15,
        ]);

        $this->member = Member::create([
            'full_name' => 'Member One', 'document_number' => '200',
            'phone' => '+573001112233', 'status' => Member::STATUS_ACTIVE,
        ]);
        ClassReservation::create(['class_id' => $this->class->id, 'member_id' => $this->member->id]);
    }

    private function makeTrainer(string $document, array $roles): Trainer
    {
        $trainer = Trainer::create([
            'full_name' => 'Coach '.$document, 'document' => $document,
            'phone' => '+5730099988'.substr($document, -2), 'status' => 'active',
        ]);
        app(IdentityLinkService::class)->backfillExisting();
        $trainer->refresh();
        $trainer->syncRoles($roles);

        return $trainer->fresh('roleAssignments');
    }

    private function login(string $document): string
    {
        $access = $this->postJson('/api/trainer/auth/access', ['document' => $document, 'device_id' => 't'.$document])->assertOk();

        return $this->postJson('/api/trainer/auth/verify', [
            'challenge_id' => $access->json('challenge_id'),
            'code' => $access->json('dev_code'),
            'device_id' => 't'.$document,
        ])->assertOk()->json('token');
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_agenda_lists_only_own_classes(): void
    {
        $other = $this->makeTrainer('900', [TrainerRole::FUNCTIONAL]);
        MyClass::create([
            'name' => 'Ajena', 'type' => 'Yoga', 'trainer_id' => $other->id,
            'day_of_week' => 'Martes', 'start_time' => '09:00', 'end_time' => '10:00',
            'status' => 'active', 'max_capacity' => 10,
        ]);

        $this->getJson('/api/trainer/classes', $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->class->id)
            ->assertJsonPath('data.0.enrolled', 1)
            ->assertJsonPath('data.0.spots_left', 14);
    }

    public function test_show_returns_authorized_participants(): void
    {
        $this->getJson("/api/trainer/classes/{$this->class->id}?session_date={$this->today}", $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'participants')
            ->assertJsonPath('participants.0.member_id', $this->member->id)
            ->assertJsonPath('participants.0.status', null);
    }

    public function test_mark_attendance_for_participant(): void
    {
        $this->postJson("/api/trainer/classes/{$this->class->id}/attendance", [
            'member_id' => $this->member->id, 'session_date' => $this->today, 'status' => 'present',
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('participants.0.status', 'present');

        $this->assertDatabaseHas('class_attendances', [
            'class_id' => $this->class->id, 'member_id' => $this->member->id, 'status' => 'present',
        ]);
    }

    public function test_marking_attendance_emits_member_realtime(): void
    {
        // Al marcar asistencia, el miembro debe recibir señal SSE para refrescar
        // "Clases" y "Organizar mi semana" (verá Presente/Tarde/Ausente en vivo).
        $this->postJson("/api/trainer/classes/{$this->class->id}/attendance", [
            'member_id' => $this->member->id, 'session_date' => $this->today, 'status' => 'present',
        ], $this->auth())->assertOk();

        $this->assertTrue(
            MemberRealtimeEvent::where('member_id', $this->member->id)
                ->where('type', 'class.updated')->exists(),
            'Marcar asistencia debe emitir señal real-time al miembro.'
        );
    }

    public function test_cannot_double_mark(): void
    {
        $payload = ['member_id' => $this->member->id, 'session_date' => $this->today, 'status' => 'present'];
        $this->postJson("/api/trainer/classes/{$this->class->id}/attendance", $payload, $this->auth())->assertOk();
        $this->postJson("/api/trainer/classes/{$this->class->id}/attendance", $payload, $this->auth())->assertStatus(409);

        $this->assertSame(1, ClassAttendance::where('class_id', $this->class->id)->count());
    }

    public function test_cannot_mark_non_participant(): void
    {
        $stranger = Member::create([
            'full_name' => 'Stranger', 'document_number' => '999',
            'phone' => '+573000000000', 'status' => Member::STATUS_ACTIVE,
        ]);

        $this->postJson("/api/trainer/classes/{$this->class->id}/attendance", [
            'member_id' => $stranger->id, 'session_date' => $this->today, 'status' => 'present',
        ], $this->auth())->assertStatus(422);
    }

    public function test_cannot_mark_on_inactive_class(): void
    {
        $this->class->update(['status' => 'cancelled']);

        $this->postJson("/api/trainer/classes/{$this->class->id}/attendance", [
            'member_id' => $this->member->id, 'session_date' => $this->today, 'status' => 'present',
        ], $this->auth())->assertStatus(409);
    }

    public function test_correct_attendance_is_audited(): void
    {
        $this->postJson("/api/trainer/classes/{$this->class->id}/attendance", [
            'member_id' => $this->member->id, 'session_date' => $this->today, 'status' => 'present',
        ], $this->auth())->assertOk();

        $this->putJson("/api/trainer/classes/{$this->class->id}/attendance", [
            'member_id' => $this->member->id, 'session_date' => $this->today,
            'status' => 'absent', 'note' => 'Marcado por error',
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('participants.0.status', 'absent');

        $row = ClassAttendance::where('class_id', $this->class->id)->first();
        $this->assertNotNull($row->corrected_at);
        $this->assertSame('Marcado por error', $row->correction_note);
        $this->assertDatabaseHas('trainer_audit_logs', [
            'event' => TrainerAuditLog::EVENT_ATTENDANCE_CORRECTED,
        ]);
    }

    public function test_correct_without_prior_mark_is_404(): void
    {
        $this->putJson("/api/trainer/classes/{$this->class->id}/attendance", [
            'member_id' => $this->member->id, 'session_date' => $this->today, 'status' => 'absent',
        ], $this->auth())->assertStatus(404);
    }

    public function test_cannot_access_other_trainers_class(): void
    {
        $other = $this->makeTrainer('900', [TrainerRole::FUNCTIONAL]);
        $otherClass = MyClass::create([
            'name' => 'Ajena', 'type' => 'Yoga', 'trainer_id' => $other->id,
            'day_of_week' => 'Martes', 'start_time' => '09:00', 'end_time' => '10:00',
            'status' => 'active', 'max_capacity' => 10,
        ]);

        $this->getJson("/api/trainer/classes/{$otherClass->id}", $this->auth())->assertStatus(403);
    }

    public function test_floor_trainer_lacks_class_permissions(): void
    {
        $floor = $this->makeTrainer('500', [TrainerRole::FLOOR]);
        $token = $this->login('500');

        $this->getJson('/api/trainer/classes', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);
    }

    public function test_routes_hidden_when_feature_off(): void
    {
        config(['trainer.flags.trainer_classes_enabled' => false, 'trainer.pilot_identities' => []]);

        $this->getJson('/api/trainer/classes', $this->auth())->assertStatus(404);
    }

    // ── Agenda: conteo por OCURRENCIA real (no histórico de todas las semanas) ──

    /** Clase recurrente propia + dos reservas en semanas distintas. */
    private function recurringClassWithTwoWeeks(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 08:00:00')); // lunes
        $class = MyClass::create([
            'name' => 'IRON FUNCIONAL', 'type' => 'Funcional', 'trainer_id' => $this->trainer->id,
            'day_of_week' => 'Miércoles', 'start_time' => '19:00', 'end_time' => '20:00',
            'status' => 'active', 'max_capacity' => 10, 'is_recurring' => true,
        ]);
        $m2 = Member::create(['full_name' => 'M2', 'document_number' => '321', 'phone' => '+573001110001', 'status' => Member::STATUS_ACTIVE]);
        $m3 = Member::create(['full_name' => 'M3', 'document_number' => '322', 'phone' => '+573001110002', 'status' => Member::STATUS_ACTIVE]);
        ClassReservation::create(['class_id' => $class->id, 'member_id' => $m2->id, 'session_date' => '2026-06-17']); // esta semana
        ClassReservation::create(['class_id' => $class->id, 'member_id' => $m3->id, 'session_date' => '2026-06-24']); // próxima

        return [$class, $m2, $m3];
    }

    public function test_agenda_counts_only_current_occurrence_not_all_weeks(): void
    {
        [$class] = $this->recurringClassWithTwoWeeks();

        $res = $this->getJson('/api/trainer/classes', $this->auth())->assertOk();
        $item = collect($res->json('data'))->firstWhere('id', $class->id);

        // Solo cuenta el miércoles de ESTA semana (06-17), no ambas reservas.
        $this->assertSame(1, $item['enrolled']);
        $this->assertSame(9, $item['spots_left']);
        // next_occurrence alineado con la ocurrencia operativa (miércoles 06-17 en
        // Bogotá), no el nextOccurrence() viejo. Se compara el INSTANTE en Bogotá
        // (el JSON se serializa en UTC: 06-17 19:00-05:00 == 06-18 00:00Z).
        $this->assertSame(
            '2026-06-17',
            Carbon::parse((string) $item['next_occurrence'])->setTimezone('America/Bogota')->toDateString(),
        );

        Carbon::setTestNow();
    }

    public function test_show_counts_and_roster_by_viewed_session_date(): void
    {
        [$class, , $m3] = $this->recurringClassWithTwoWeeks();

        // Viendo la sesión de la PRÓXIMA semana (06-24): cuenta solo esa.
        $res = $this->getJson("/api/trainer/classes/{$class->id}?session_date=2026-06-24", $this->auth())->assertOk();
        $this->assertSame(1, $res->json('data.enrolled'));
        $this->assertSame(9, $res->json('data.spots_left'));
        $this->assertCount(1, $res->json('participants'));
        $this->assertSame($m3->id, $res->json('participants.0.member_id'));

        Carbon::setTestNow();
    }
}
