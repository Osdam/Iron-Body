<?php

namespace App\Services\Trainer;

use App\Exceptions\AttendanceException;
use App\Models\ClassAttendance;
use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Services\Classes\ClassBookingService;
use App\Services\Classes\ClassEventBus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Lógica de dominio de la asistencia a clases. Garantiza que solo se marque a
 * participantes inscritos, en clases activas, sin doble marcado, y que las
 * correcciones queden auditadas (nunca se sobrescribe sin dejar rastro).
 */
class ClassAttendanceService
{
    // Resultados del check-in del propio socio.
    public const CHECKIN_OK = 'checked_in';

    public const CHECKIN_ALREADY = 'already_marked';

    public const CHECKIN_NOT_STARTED = 'not_started';

    public const CHECKIN_CLOSED = 'closed';

    public const CHECKIN_NOT_RESERVED = 'not_reserved';

    public function __construct(
        private readonly TrainerAuditService $audit,
        private readonly ClassBookingService $booking,
        private readonly ClassEventBus $events,
    ) {}

    /**
     * Participantes (inscritos) de la clase con su asistencia para una fecha de
     * sesión. Es la lista AUTORIZADA: solo quienes tienen reserva.
     *
     * Sale de la MISMA consulta que cuenta el cupo
     * ({@see ClassBookingService::occurrenceReservations()}): la lista del
     * entrenador y el «1/20» de la app no pueden contar cosas distintas.
     *
     * Documento ENMASCARADO: el entrenador necesita distinguir homónimos, no
     * leer el documento completo, que sigue siendo cosa de la ficha del socio.
     *
     * @return array<int, array{member_id:int, full_name:string, document:?string, status:?string, booking_status:string, reservation_id:int, reserved_at:?string}>
     */
    public function participants(MyClass $class, Carbon $sessionDate): array
    {
        $fecha = $sessionDate->toDateString();

        $attendance = ClassAttendance::query()
            ->where('class_id', $class->getKey())
            ->whereDate('session_date', $fecha)
            ->get()
            ->keyBy('member_id');

        $inscritos = $this->booking->occurrenceReservations($class, $fecha)
            ->unique('member_id')
            ->map(fn (ClassReservation $r): array => [
                'member_id' => (int) $r->member_id,
                'full_name' => $r->member?->full_name ?? '',
                'document' => self::maskDocument($r->member?->document_number),
                // Asistencia de ESA sesión (null = aún sin marcar). Se conserva el
                // nombre `status` porque la app publicada lo lee así.
                'status' => $attendance[$r->member_id]->status ?? null,
                // Cancelar borra la fila: toda reserva que aparece está vigente.
                'booking_status' => 'confirmed',
                'reservation_id' => (int) $r->id,
                'reserved_at' => $r->reserved_at?->toIso8601String(),
            ])
            ->keyBy('member_id');

        // La renovación borra las reservas de una sesión ya dictada, pero su
        // asistencia queda. Sin esto, la lista de esa sesión salía «Sin
        // inscritos» y el entrenador no podía corregir una marca. Salen con la
        // reserva `released` (liberada por la renovación): no ocupan cupo.
        $sinReserva = $attendance->keys()->map(fn ($id) => (int) $id)->diff($inscritos->keys())->values();
        if ($sinReserva->isNotEmpty()) {
            $socios = Member::query()->whereIn('id', $sinReserva)->get(['id', 'full_name', 'document_number'])->keyBy('id');
            foreach ($sinReserva as $memberId) {
                $inscritos[$memberId] = [
                    'member_id' => $memberId,
                    'full_name' => $socios[$memberId]->full_name ?? '',
                    'document' => self::maskDocument($socios[$memberId]->document_number ?? null),
                    'status' => $attendance[$memberId]->status,
                    'booking_status' => 'released',
                    'reservation_id' => null,
                    'reserved_at' => null,
                ];
            }
        }

        return $inscritos->values()->all();
    }

    /**
     * «•••5678»: los cuatro últimos, suficiente para distinguir a dos personas.
     * Un documento de cuatro caracteres o menos se tapa entero: los «cuatro
     * últimos» serían el documento completo, que es con lo que el socio entra.
     */
    public static function maskDocument(?string $documento): ?string
    {
        $limpio = trim((string) $documento);
        if ($limpio === '') {
            return null;
        }

        return mb_strlen($limpio) <= 4 ? str_repeat('•', mb_strlen($limpio)) : '•••'.mb_substr($limpio, -4);
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
        $this->assertMarkable($class, $memberId, $status, $sessionDate);

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

        // La reserva de ese socio cambió de estado: lo ven el propio socio, el
        // entrenador y el CRM. Los demás socios no: su cupo no se movió.
        $this->events->booking(
            ClassEventBus::BOOKING_UPDATED, $class, $sessionDate->toDateString(),
            ClassEventBus::SOURCE_TRAINER, null, $memberId,
        );

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
        // Corregir no exige que la reserva siga existiendo: la renovación del
        // ciclo borra las reservas pasadas y la marca previa ya prueba que la
        // persona estaba inscrita. Basta con que exista esa marca.
        $this->assertValid($class, $status);

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

        $this->events->booking(
            ClassEventBus::BOOKING_UPDATED, $class, $sessionDate->toDateString(),
            ClassEventBus::SOURCE_TRAINER, null, $memberId,
        );

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

    /**
     * Se marca asistencia de UNA sesión real y ya llegada, y solo a quien
     * reservó ESA sesión.
     *
     * Antes bastaba con tener cualquier reserva de la clase —de otra semana,
     * pasada o futura— y cualquier fecha valía: se podía marcar presente hoy a
     * quien reservó el lunes que viene, o registrar asistencia en un día en que
     * la clase no se dicta.
     */
    private function assertMarkable(MyClass $class, int $memberId, string $status, Carbon $sessionDate): void
    {
        $this->assertValid($class, $status);

        $fecha = $sessionDate->toDateString();

        if (! $this->booking->isOccurrence($class, $fecha)) {
            throw AttendanceException::notAnOccurrence();
        }

        if ($fecha > $this->booking->todayDate()) {
            throw AttendanceException::notYet();
        }

        if (! $this->booking->occupies($memberId, $class, $fecha)) {
            throw AttendanceException::notAParticipant();
        }
    }

    private function assertValid(MyClass $class, string $status): void
    {
        if (! ClassAttendance::isValidStatus($status)) {
            throw new AttendanceException('Estado de asistencia inválido.');
        }

        if (! $class->acceptsAttendance()) {
            throw AttendanceException::classNotActive();
        }
    }

    /**
     * El socio marca su propia asistencia en la sesión EN CURSO.
     *
     * Tres cosas que antes fallaban:
     *   · valía una reserva de CUALQUIER fecha, así que quien reservó la semana
     *     siguiente entraba en la de hoy por encima del aforo y sin salir en la
     *     lista del entrenador;
     *   · pisaba sin rastro lo que ya hubiera marcado el entrenador («ausente»,
     *     «tarde»): ahora, si ya hay marca, se respeta y se devuelve tal cual;
     *   · una sesión iniciada y nunca cerrada contaba como «en curso» para
     *     siempre: solo se aceptan las de hoy o de ayer (una clase que pasa de
     *     la medianoche).
     *
     * `status` es la marca que queda: la nueva o la que ya había.
     *
     * @return array{outcome:string, date:?string, status?:string}
     */
    public function selfCheckIn(Member $member, MyClass $class): array
    {
        $hoy = $this->booking->todayDate();

        $session = ClassSession::query()
            ->where('class_id', $class->getKey())
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->whereDate('session_date', '>=', $this->booking->liveSince())
            ->orderByDesc('session_date')
            ->first();

        if ($session === null) {
            $cerradaHoy = ClassSession::query()
                ->where('class_id', $class->getKey())
                ->whereDate('session_date', $hoy)
                ->whereNotNull('ended_at')
                ->exists();

            return ['outcome' => $cerradaHoy ? self::CHECKIN_CLOSED : self::CHECKIN_NOT_STARTED, 'date' => null];
        }

        $fecha = $session->session_date->toDateString();

        if (! $this->booking->occupies((int) $member->getKey(), $class, $fecha)) {
            return ['outcome' => self::CHECKIN_NOT_RESERVED, 'date' => $fecha];
        }

        $previa = $this->markOf($class, $member, $fecha);
        if ($previa !== null) {
            return ['outcome' => self::CHECKIN_ALREADY, 'date' => $fecha, 'status' => $previa];
        }

        try {
            ClassAttendance::create([
                'class_id' => $class->getKey(),
                'member_id' => $member->getKey(),
                'session_date' => $fecha,
                'status' => ClassAttendance::STATUS_PRESENT,
                'marked_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // El entrenador la marcó en el mismo instante: gana su marca.
            return ['outcome' => self::CHECKIN_ALREADY, 'date' => $fecha, 'status' => $this->markOf($class, $member, $fecha)];
        }

        $this->events->booking(
            ClassEventBus::BOOKING_UPDATED, $class, $fecha,
            ClassEventBus::SOURCE_APP, null, (int) $member->getKey(),
        );

        return ['outcome' => self::CHECKIN_OK, 'date' => $fecha, 'status' => ClassAttendance::STATUS_PRESENT];
    }

    /** La marca de asistencia de un socio en una sesión, o null si no tiene. */
    private function markOf(MyClass $class, Member $member, string $fecha): ?string
    {
        return ClassAttendance::query()
            ->where('class_id', $class->getKey())
            ->where('member_id', $member->getKey())
            ->whereDate('session_date', $fecha)
            ->value('status');
    }

    /**
     * El texto y el código HTTP de un check-in, para las dos puertas de la app.
     *
     * Si el entrenador ya lo marcó ausente, la marca se respeta (el socio no
     * puede pasarse a presente) y se le dice, en vez de «Asistencia registrada».
     *
     * @param  array{outcome:string, date:?string, status?:string|null}  $resultado
     * @return array{0:int, 1:string}
     */
    public static function checkInReply(array $resultado): array
    {
        return match ($resultado['outcome']) {
            self::CHECKIN_NOT_RESERVED => [422, 'No tienes reserva en esta clase.'],
            self::CHECKIN_NOT_STARTED => [422, 'La clase aún no ha iniciado.'],
            self::CHECKIN_CLOSED => [422, 'La clase ya finalizó.'],
            self::CHECKIN_ALREADY => ($resultado['status'] ?? null) === ClassAttendance::STATUS_ABSENT
                ? [422, 'El entrenador ya registró tu asistencia como ausente. Si llegaste, pídele que la corrija.']
                : [200, 'Tu asistencia ya estaba registrada.'],
            default => [200, 'Asistencia registrada.'],
        };
    }
}
