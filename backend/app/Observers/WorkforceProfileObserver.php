<?php

namespace App\Observers;

use App\Models\StaffProfile;
use App\Models\Trainer;
use App\Services\Workforce\BiometricRetention;
use App\Services\Workforce\WorkdayService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Cuando un perfil laboral se desactiva o se borra —desde la ficha del
 * entrenador, desde Personal o por cualquier otro camino—, la plantilla facial
 * de quien ya no trabaja aquí se revoca y se borra, y su jornada abierta (si ya
 * no puede registrar jornada) queda como incidencia. Un observer y no una
 * llamada en cada controlador: la desactivación de entrenadores tiene varios
 * caminos (ficha, activar/desactivar, borrar) y uno olvidado dejaría el dato.
 */
class WorkforceProfileObserver
{
    public function __construct(
        private readonly BiometricRetention $retention,
        private readonly WorkdayService $workdays,
    ) {}

    public function updated(Model $model): void
    {
        if (! $model->wasChanged('status') || $this->isActive($model)) {
            return;
        }
        $this->retain($model);
    }

    public function deleted(Model $model): void
    {
        $this->retain($model);
    }

    private function retain(Model $model): void
    {
        if ($model instanceof Trainer) {
            $this->retention->afterTrainerDeactivated($model);
            $identity = $model->identity_id ? $model->identity()->first() : null;
        } elseif ($model instanceof StaffProfile) {
            $this->retention->afterStaffDeactivated($model);
            $identity = $model->identity()->first();
        } else {
            return;
        }

        if ($identity !== null) {
            $this->workdays->afterProfileDeactivated($identity);
        }
    }

    private function isActive(Model $model): bool
    {
        return in_array(Str::lower((string) $model->getAttribute('status')), ['active', 'activo'], true);
    }
}
