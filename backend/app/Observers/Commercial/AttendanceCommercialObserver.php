<?php

namespace App\Observers\Commercial;

use App\Models\Attendance;
use App\Services\Commercial\CommercialEventRecorder;
use App\Services\Commercial\CommercialSubjectResolver;
use App\Services\Commercial\CommercialVocabulary as V;

/**
 * Venir al gimnasio, que es la única prueba de que el producto se está usando.
 *
 * Dos hechos, y los dos son buenos momentos para hablar:
 *
 *  · **La primera vez.** El día que alguien estrena la membresía es cuando más
 *    dispuesto está a que le ayuden a organizarse. Callarse ese día y aparecer
 *    dos meses después a vender algo es desaprovechar la relación.
 *
 *  · **Los hitos.** La visita 10, 25, 50, 100. Son la evidencia que sostiene
 *    cualquier propuesta posterior: a quien lleva cincuenta visitas se le puede
 *    hablar de un plan anual sin que suene a que le están vendiendo humo.
 *
 * La entrada se cuenta solo una vez por día: quien sale a mediodía y vuelve por
 * la tarde vino una vez, no dos.
 */
class AttendanceCommercialObserver
{
    /** Visitas acumuladas que merecen reconocimiento. */
    private const MILESTONES = [10, 25, 50, 100, 200, 365];

    public function __construct(
        private readonly CommercialEventRecorder $recorder,
        private readonly CommercialSubjectResolver $resolver,
    ) {}

    public function created(Attendance $attendance): void
    {
        if (! (bool) config('commercial.events_enabled', false)) {
            return;
        }

        // Solo entradas. La salida no aporta nada comercial.
        if ((string) $attendance->action !== 'entry' || $attendance->member_id === null) {
            return;
        }

        $count = $this->entriesFor((int) $attendance->member_id);

        if ($count === 1) {
            $this->emit($attendance, V::EV_FIRST_CHECKIN, 'first', ['visits' => 1]);

            return;
        }

        if (in_array($count, self::MILESTONES, true)) {
            $this->emit($attendance, V::EV_ATTENDANCE_MILESTONE, "milestone:{$count}", ['visits' => $count]);
        }
    }

    /**
     * Visitas distintas, contadas por día. Dos entradas el mismo día son una
     * sola visita; sin esto, alguien que sale a comer y vuelve alcanzaría la
     * visita número diez sin haber venido diez días.
     */
    private function entriesFor(int $memberId): int
    {
        return Attendance::query()
            ->where('member_id', $memberId)
            ->where('action', 'entry')
            ->distinct()
            ->count(\Illuminate\Support\Facades\DB::raw('date(captured_at)'));
    }

    private function emit(Attendance $attendance, string $event, string $keySuffix, array $payload): void
    {
        $this->recorder->record(
            event: $event,
            subject: $this->resolver->fromMember((int) $attendance->member_id),
            payload: $payload,
            // Se ancla al MIEMBRO y al hito, no a la fila de asistencia: si dos
            // lectores del torniquete registran la misma entrada, el hito sigue
            // siendo uno solo.
            dedupeKey: "attendance:{$attendance->member_id}:{$keySuffix}",
            occurredAt: $attendance->captured_at,
        );
    }
}
