<?php

namespace App\Services\Trainer;

use App\Models\ClassReservation;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerAuditLog;
use App\Services\NotificationService;
use App\Services\RealtimeEvents;
use Illuminate\Support\Carbon;

/**
 * Ciclo de vida REAL de una clase: el entrenador la inicia (con rostro) y la
 * finaliza (con rostro). Registra los horarios efectivos para supervisión y, al
 * iniciar, avisa a los miembros inscritos. Idempotente: re-iniciar/re-finalizar
 * no duplica ni reenvía la notificación.
 */
class ClassSessionService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TrainerAuditService $audit,
    ) {}

    /**
     * Marca el INICIO real de la clase para la fecha dada. Solo notifica a los
     * inscritos la PRIMERA vez que se inicia (no en reintentos).
     */
    public function start(MyClass $class, Trainer $trainer, Carbon $sessionDate, bool $faceVerified): ClassSession
    {
        $session = ClassSession::firstOrNew([
            'class_id' => $class->getKey(),
            'session_date' => $sessionDate->toDateString(),
        ]);

        $firstStart = $session->started_at === null;

        if ($firstStart) {
            $session->started_at = now();
            $session->started_by = $trainer->getKey();
            $session->start_face_verified = $faceVerified;
            $session->save();

            $this->audit->record(
                TrainerAuditLog::EVENT_CLASS_STARTED,
                $trainer,
                actorType: TrainerAuditLog::ACTOR_TRAINER,
                metadata: [
                    'class_id' => $class->getKey(),
                    'session_date' => $sessionDate->toDateString(),
                    'face_verified' => $faceVerified,
                ],
            );

            $this->notifyEnrolled($class);
        }

        // Realtime: los miembros refrescan sus clases en vivo → aparece el botón
        // "Marcar presente" sin recargar.
        RealtimeEvents::classesChanged();

        return $session;
    }

    /** Marca el FIN real de la clase. Requiere que se haya iniciado. */
    public function end(MyClass $class, Trainer $trainer, Carbon $sessionDate, bool $faceVerified): ?ClassSession
    {
        $session = ClassSession::query()
            ->where('class_id', $class->getKey())
            ->whereDate('session_date', $sessionDate->toDateString())
            ->first();

        if ($session === null || $session->started_at === null) {
            return null; // no se puede finalizar lo que no inició
        }

        if ($session->ended_at === null) {
            $session->ended_at = now();
            $session->ended_by = $trainer->getKey();
            $session->end_face_verified = $faceVerified;
            $session->save();

            $this->audit->record(
                TrainerAuditLog::EVENT_CLASS_ENDED,
                $trainer,
                actorType: TrainerAuditLog::ACTOR_TRAINER,
                metadata: [
                    'class_id' => $class->getKey(),
                    'session_date' => $sessionDate->toDateString(),
                    'face_verified' => $faceVerified,
                ],
            );
        }

        // Realtime: al finalizar, los miembros ven el estado "finalizada" en vivo.
        RealtimeEvents::classesChanged();

        return $session;
    }

    /** Sesión real (si existe) de una clase en una fecha. */
    public function forDate(MyClass $class, Carbon $sessionDate): ?ClassSession
    {
        return ClassSession::query()
            ->where('class_id', $class->getKey())
            ->whereDate('session_date', $sessionDate->toDateString())
            ->first();
    }

    /** Notifica "la clase inició" a los miembros inscritos en la clase. */
    private function notifyEnrolled(MyClass $class): void
    {
        $memberIds = ClassReservation::query()
            ->where('class_id', $class->getKey())
            ->pluck('member_id')
            ->unique()
            ->all();

        if ($memberIds === []) {
            return;
        }

        Member::query()
            ->whereIn('id', $memberIds)
            ->get()
            ->each(fn (Member $member) => $this->notifications->notifyClassStarted($member, $class));
    }
}
