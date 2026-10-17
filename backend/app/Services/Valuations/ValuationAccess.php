<?php

namespace App\Services\Valuations;

use App\Exceptions\ValuationException;
use App\Models\Admin;
use App\Models\ValuationAppointment;
use App\Support\Access\CrmPermission;

/**
 * Qué puede hacer UNA persona en la agenda de valoraciones y sobre QUÉ citas.
 *
 * Por PERMISOS y `admins.trainer_id`, nunca por nombre de rol (contrato §0.12):
 *
 *  · con `valuations.view_all`            → todas las citas;
 *  · si no, con `valuations.view` y un `trainer_id` → solo las de ese entrenador;
 *  · si no                                 → nada (403 `forbidden`).
 *
 * Toda escritura exige su permiso Y que la cita quede en el alcance: quien solo
 * ve lo suyo solo crea, mueve o cierra citas de SU entrenador. Así un entrenador
 * vinculado no puede operar la cita de otro aunque conozca su uuid (IDOR).
 *
 * El token compartido de automatizaciones no llega aquí: el mapa le niega todo
 * `valuations` y el controlador exige además una persona.
 */
final class ValuationAccess
{
    public const VIEW = 'valuations.view';

    public const VIEW_ALL = 'valuations.view_all';

    public const CREATE = 'valuations.create';

    public const UPDATE = 'valuations.update';

    public const CANCEL = 'valuations.cancel';

    public const COMPLETE = 'valuations.complete';

    public const MANAGE_AVAILABILITY = 'valuations.manage_availability';

    public const MANAGE_SETTINGS = 'valuations.manage_settings';

    public const ALL = [
        self::VIEW, self::VIEW_ALL, self::CREATE, self::UPDATE, self::CANCEL,
        self::COMPLETE, self::MANAGE_AVAILABILITY, self::MANAGE_SETTINGS,
    ];

    /** @var array<string,bool> */
    private array $memo = [];

    public function __construct(public readonly Admin $actor) {}

    public function can(string $permiso): bool
    {
        return $this->memo[$permiso] ??= CrmPermission::allows($this->actor, $permiso);
    }

    public function viewsAll(): bool
    {
        return $this->can(self::VIEW_ALL);
    }

    public function ownTrainerId(): ?int
    {
        return $this->actor->trainer_id ? (int) $this->actor->trainer_id : null;
    }

    /**
     * El entrenador al que está acotada la persona, o null si lo ve todo.
     *
     * @throws ValuationException 403 si no tiene alcance ninguno
     */
    public function scopedTrainerId(): ?int
    {
        if ($this->viewsAll()) {
            return null;
        }
        if ($this->can(self::VIEW) && $this->ownTrainerId() !== null) {
            return $this->ownTrainerId();
        }

        throw ValuationException::forbidden('No tienes acceso a la agenda de valoraciones: hace falta ver todas las citas, o ver la agenda con una cuenta vinculada a un entrenador.');
    }

    public function hasScope(): bool
    {
        return $this->viewsAll() || ($this->can(self::VIEW) && $this->ownTrainerId() !== null);
    }

    /** ¿Puede ver / operar citas de este entrenador? */
    public function coversTrainer(int $trainerId): bool
    {
        if (! $this->hasScope()) {
            return false;
        }

        return $this->viewsAll() || $this->ownTrainerId() === $trainerId;
    }

    public function covers(ValuationAppointment $cita): bool
    {
        return $this->coversTrainer((int) $cita->trainer_id);
    }

    /** ¿Es una cita de SU entrenador? (para las notas y la disponibilidad propia) */
    public function owns(int $trainerId): bool
    {
        return $this->ownTrainerId() !== null && $this->ownTrainerId() === $trainerId;
    }

    /** @throws ValuationException 403 */
    public function require(string $permiso): void
    {
        if (! $this->can($permiso)) {
            throw ValuationException::forbidden();
        }
    }

    /**
     * La cita tiene que estar en el alcance; si no, se responde como si no
     * existiera (no se confirma la existencia de la cita de otro).
     *
     * @throws ValuationException 403|404
     */
    public function requireCovers(ValuationAppointment $cita): void
    {
        $this->scopedTrainerId();
        if (! $this->covers($cita)) {
            throw ValuationException::notFound();
        }
    }

    /** @throws ValuationException 403 */
    public function requireCoversTrainer(int $trainerId): void
    {
        $this->scopedTrainerId();
        if (! $this->coversTrainer($trainerId)) {
            throw ValuationException::forbidden('Solo puedes operar la agenda de tu propio entrenador.');
        }
    }

    /** @return array<string,bool> */
    public function capabilities(): array
    {
        $out = [];
        foreach (self::ALL as $p) {
            $out[substr($p, strlen('valuations.'))] = $this->can($p);
        }

        return $out;
    }

    /** Leer disponibilidad: quien la gestiona, o el propio entrenador con `view`. */
    public function canReadAvailability(int $trainerId): bool
    {
        return $this->can(self::MANAGE_AVAILABILITY)
            || ($this->owns($trainerId) && ($this->can(self::VIEW) || $this->can(self::VIEW_ALL)));
    }
}
