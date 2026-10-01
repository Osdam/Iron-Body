<?php

namespace App\Services\Classes;

use App\Models\ClassEvent;
use App\Models\MyClass;
use App\Services\RealtimeEvents;
use App\Services\Trainer\TrainerRealtimeEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El único punto por el que salen los hechos de clases y reservas.
 *
 * Cada hecho tiene TRES destinos, y cada uno recibe lo que necesita y nada más:
 *
 *   · CRM: el hecho tipado (`booking.created`, `booking.cancelled`,
 *     `booking.updated`, `class.updated`) en `class_events`, con id para leerlo
 *     por cursor y reanudar sin huecos tras una reconexión. Se escribe DENTRO
 *     de la transacción del cambio: o quedan los dos o ninguno.
 *
 *   · App de los socios: UNA fila en el canal global (`catalog_events`) si el
 *     cambio afecta a todos —el cupo— con tipo `reservation.*` o
 *     `class.updated`, que son los prefijos que la app publicada ya entiende.
 *     `booking.*` no: la app lo tomaría por un cambio de cuenta y recargaría
 *     el estado general en vez de Clases. La asistencia solo le importa al socio
 *     marcado: a él, en su canal personal.
 *
 *   · Entrenador dueño de la clase (y el anterior, si cambió): su canal, con el
 *     tipo del hecho en `changed`, que es lo que su app publicada mira.
 *
 * Los avisos a socios y entrenadores salen DESPUÉS del commit: nunca se anuncia
 * un cambio que luego se revierte. Son señales: quien las recibe vuelve a leer
 * de la base con sus permisos.
 */
class ClassEventBus
{
    public const BOOKING_CREATED = 'booking.created';

    public const BOOKING_CANCELLED = 'booking.cancelled';

    /** La reserva sigue, pero cambió su asistencia. */
    public const BOOKING_UPDATED = 'booking.updated';

    /** La clase cambió: edición, sesión iniciada o cerrada, renovación, borrado. */
    public const CLASS_UPDATED = 'class.updated';

    public const SOURCE_APP = 'app';

    public const SOURCE_CRM = 'crm';

    public const SOURCE_TRAINER = 'trainer';

    public const SOURCE_SYSTEM = 'system';

    /** Anidamiento de {@see batch()}. */
    private int $lote = 0;

    /** Tipo del aviso global pendiente dentro de un lote, o null. */
    private ?string $globalPendiente = null;

    /** @var array<int, string> entrenador => tipo de su aviso pendiente */
    private array $entrenadoresPendientes = [];

    /** @var array<int, list<string>> entrenador => valores de `changed` */
    private array $cambiosPendientes = [];

    /**
     * Una reserva nació, se canceló o cambió su asistencia.
     *
     * `$memberId` solo se usa para avisar al propio socio de un cambio de
     * asistencia; no viaja en ningún canal compartido.
     */
    public function booking(
        string $type,
        MyClass $class,
        ?string $date,
        string $source,
        ?int $reservationId = null,
        ?int $memberId = null,
    ): void {
        $classId = (int) $class->getKey();
        $trainerId = $class->trainer_id ? (int) $class->trainer_id : null;

        $this->record($type, $classId, $date, $source, $reservationId, null);

        DB::afterCommit(function () use ($type, $classId, $date, $memberId, $trainerId): void {
            if ($type === self::BOOKING_UPDATED) {
                RealtimeEvents::emit($memberId, RealtimeEvents::CLASS_EVENT, ['classes']);
            } else {
                $this->toMembers('reservation.'.substr($type, strlen('booking.')), $classId, $date);
            }

            $this->toTrainer(
                $trainerId,
                $type === self::BOOKING_UPDATED ? TrainerRealtimeEvents::ATTENDANCE : TrainerRealtimeEvents::CLASS_EVENT,
                ['classes', $type],
            );
        });
    }

    /**
     * La clase cambió de un modo que ve todo el mundo.
     *
     * @param  array<int, int|null>  $otrosEntrenadores  p. ej. el anterior, si se reasignó
     */
    public function classChanged(
        MyClass $class,
        string $reason,
        string $source,
        ?string $date = null,
        array $otrosEntrenadores = [],
    ): void {
        $classId = (int) $class->getKey();
        $entrenadores = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, array_merge([$class->trainer_id], $otrosEntrenadores)),
        )));

        $this->record(self::CLASS_UPDATED, $classId, $date, $source, null, $reason);

        DB::afterCommit(function () use ($classId, $date, $entrenadores): void {
            $this->toMembers(RealtimeEvents::CLASS_EVENT, $classId, $date);
            foreach ($entrenadores as $trainerId) {
                $this->toTrainer($trainerId, TrainerRealtimeEvents::CLASS_EVENT, ['classes', self::CLASS_UPDATED]);
            }
        });
    }

    /**
     * Agrupa varios hechos (el plan semanal reserva varias clases de una vez):
     * cada uno queda en el registro del CRM, pero socios y entrenadores reciben
     * UN aviso al final en vez de uno por reserva. Cada aviso hace que cada app
     * abierta vuelva a pedir sus clases.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function batch(callable $fn): mixed
    {
        $this->lote++;
        try {
            return $fn();
        } finally {
            $this->lote--;
            if ($this->lote === 0) {
                $this->flush();
            }
        }
    }

    private function record(string $type, int $classId, ?string $date, string $source, ?int $reservationId, ?string $reason): void
    {
        try {
            // Transacción anidada = SAVEPOINT: si esta fila no se puede escribir,
            // se deshace SOLO ella y la reserva sigue. En PostgreSQL un error
            // dentro de la transacción la invalida entera; sin el savepoint, un
            // fallo del registro tumbaría la reserva. El CRM tiene además la
            // huella como red.
            DB::transaction(fn () => ClassEvent::create([
                'type' => $type,
                'class_id' => $classId,
                'session_date' => $date,
                'reservation_id' => $reservationId,
                'source' => $source,
                'reason' => $reason,
                'created_at' => now(),
            ]));
        } catch (\Throwable $e) {
            Log::warning('class_event.record_failed', ['type' => $type, 'class_id' => $classId, 'error' => $e->getMessage()]);
        }
    }

    private function toMembers(string $type, int $classId, ?string $date): void
    {
        if ($this->lote > 0) {
            // Dentro de un lote gana el último tipo; la app refresca igual.
            $this->globalPendiente = $type;

            return;
        }

        RealtimeEvents::classesChanged($classId, $date, $type);
    }

    /** @param list<string> $changed */
    private function toTrainer(?int $trainerId, string $type, array $changed): void
    {
        if ($trainerId === null || $trainerId <= 0) {
            return;
        }

        if ($this->lote > 0) {
            $this->entrenadoresPendientes[$trainerId] = $type;
            $this->cambiosPendientes[$trainerId] = array_values(array_unique(array_merge($this->cambiosPendientes[$trainerId] ?? [], $changed)));

            return;
        }

        TrainerRealtimeEvents::emit($trainerId, $type, $changed);
    }

    private function flush(): void
    {
        if ($this->globalPendiente !== null) {
            RealtimeEvents::classesChanged(null, null, $this->globalPendiente);
        }
        foreach ($this->entrenadoresPendientes as $trainerId => $type) {
            TrainerRealtimeEvents::emit($trainerId, $type, $this->cambiosPendientes[$trainerId] ?? ['classes']);
        }

        $this->globalPendiente = null;
        $this->entrenadoresPendientes = [];
        $this->cambiosPendientes = [];
    }
}
