<?php

namespace App\Services\Trainer;

use App\Exceptions\AttendanceException;
use App\Models\ClassAttendance;
use App\Models\ClassReservation;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use Illuminate\Support\Carbon;

/**
 * Lógica de dominio de la asistencia a clases. Garantiza que solo se marque a
 * participantes inscritos, en clases activas, sin doble marcado, y que las
 * correcciones queden auditadas (nunca se sobrescribe sin dejar rastro).
 */
class ClassAttendanceService
{
    public function __construct(private readonly TrainerAuditService $audit) {}

    /**
     * Participantes (inscritos) de la clase con su asistencia para una fecha de
     * sesión. Es la lista AUTORIZADA: solo quienes tienen reserva.
     *
     * @return array<int, array{member_id:int, full_name:string, status:?string}>
     */
    public function participants(MyClass $class, Carbon $sessionDate): array
    {
        $attendance = ClassAttendance::query()
            ->where('class_id', $class->getKey())
            ->whereDate('session_date', $sessionDate->toDateString())
            ->get()
            ->keyBy('member_id');

        return $class->reservations()
            ->with('member:id,full_name')
            ->get()
            ->map(fn (ClassReservation $r): array => [
                'member_id' => $r->member_id,
                'full_name' => $r->member?->full_name ?? '',
                'status' => $attendance[$r->member_id]->status ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Marca la asistencia de un miembro inscrito. Anti-doble: si ya existe para
     * esa sesión, lanza {@see AttendanceException::alreadyMarked} (usar corrección).
     */
    public function mark(
        MyClass $class,
        int $memberId,
        Carbon $sessionDate,
        string $status,
        Trainer $trainer,
    ): ClassAttendance {
        $this->assertMarkable($class, $memberId, $status);

        $exists = ClassAttendance::query()
            ->where('class_id', $class->getKey())
            ->where('member_id', $memberId)
            ->whereDate('session_date', $sessionDate->toDateString())
            ->exists();

        if ($exists) {
            throw AttendanceException::alreadyMarked();
        }

        $attendance = ClassAttendance::create([
            'class_id' => $class->getKey(),
            'member_id' => $memberId,
            'session_date' => $sessionDate->toDateString(),
            'status' => $status,
            'marked_by_trainer_id' => $trainer->getKey(),
            'marked_at' => now(),
        ]);

        $this->audit->record(
            TrainerAuditLog::EVENT_ATTENDANCE_MARKED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_TRAINER,
            metadata: [
                'class_id' => $class->getKey(),
                'member_id' => $memberId,
                'session_date' => $sessionDate->toDateString(),
                'status' => $status,
            ],
        );

        return $attendance;
    }

    /**
     * Corrige una asistencia ya registrada, con motivo y auditoría. No crea
     * registros nuevos: si no hay asistencia previa, lanza notMarked.
     */
    public function correct(
        MyClass $class,
        int $memberId,
        Carbon $sessionDate,
        string $status,
        ?string $note,
        Trainer $trainer,
    ): ClassAttendance {
        $this->assertMarkable($class, $memberId, $status);

        $attendance = ClassAttendance::query()
            ->where('class_id', $class->getKey())
            ->where('member_id', $memberId)
            ->whereDate('session_date', $sessionDate->toDateString())
            ->first();

        if ($attendance === null) {
            throw AttendanceException::notMarked();
        }

        $previous = $attendance->status;
        $attendance->update([
            'status' => $status,
            'corrected_at' => now(),
            'correction_note' => $note,
            'marked_by_trainer_id' => $trainer->getKey(),
        ]);

        $this->audit->record(
            TrainerAuditLog::EVENT_ATTENDANCE_CORRECTED,
            $trainer,
            actorType: TrainerAuditLog::ACTOR_TRAINER,
            metadata: [
                'class_id' => $class->getKey(),
                'member_id' => $memberId,
                'session_date' => $sessionDate->toDateString(),
                'from' => $previous,
                'to' => $status,
            ],
        );

        return $attendance;
    }

    private function assertMarkable(MyClass $class, int $memberId, string $status): void
    {
        if (! ClassAttendance::isValidStatus($status)) {
            throw new AttendanceException('Estado de asistencia inválido.');
        }

        if (! $class->acceptsAttendance()) {
            throw AttendanceException::classNotActive();
        }

        $isParticipant = ClassReservation::query()
            ->where('class_id', $class->getKey())
            ->where('member_id', $memberId)
            ->exists();

        if (! $isParticipant) {
            throw AttendanceException::notAParticipant();
        }
    }
}
