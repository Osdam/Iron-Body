<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Routine extends Model
{
    protected $fillable = [
        'name',
        'objective',
        'level',
        'gender',
        'muscle_group',
        'estimated_minutes',
        'duration_minutes',
        'days_per_week',
        'trainer_name',
        'trainer_id',
        'assigned_member_name',
        'assigned_member_id',
        'is_assigned',
        'member_id',
        'created_by_admin',
        'is_template',
        'status',
        'description',
        'notes',
        'exercises',
        'days',
    ];

    protected $casts = [
        'exercises'         => 'array',
        'days'              => 'array',
        'duration_minutes'  => 'integer',
        'estimated_minutes' => 'integer',
        'days_per_week'     => 'integer',
        'is_assigned'       => 'boolean',
        'created_by_admin'  => 'boolean',
        'is_template'       => 'boolean',
    ];

    public function routineExercises(): HasMany
    {
        return $this->hasMany(RoutineExercise::class)->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MemberRoutineAssignment::class);
    }

    /**
     * Clasificación para la app (fuente única, sin migración):
     *  - 'semi_personalized': plan base del gimnasio (plantilla/Seeder, por días,
     *    sin vínculo real al catálogo). NO fuerza video; es OCULTABLE por miembro.
     *  - 'personalized': hecha para el miembro con ejercicios del catálogo
     *    (exercise_id) → puede mostrar referencia visual.
     */
    public function classifyType(): string
    {
        return $this->isSemiPersonalized() ? 'semi_personalized' : 'personalized';
    }

    public function isSemiPersonalized(): bool
    {
        // Las rutinas del CRM hechas/asignadas para un miembro concreto NUNCA son
        // semi (aunque sean multi-día o aún no tengan exercise_id): van a "Mis
        // rutinas". Solo los planes base del gimnasio (plantillas SIN dueño) lo son.
        if (! empty($this->member_id) || ! empty($this->assigned_member_id)) {
            return false;
        }
        if ($this->is_template) {
            return true; // los planes base del gimnasio son plantillas
        }
        if ($this->hasCatalogLink()) {
            return false; // vinculada al catálogo → personalizada
        }
        // Sin dueño ni vínculo: un programa multi-día es un plan base.
        return is_array($this->days) && ! empty($this->days);
    }

    /** ¿Algún ejercicio (JSON, days o tabla normalizada) referencia el catálogo? */
    private function hasCatalogLink(): bool
    {
        $inJson = collect(is_array($this->exercises) ? $this->exercises : [])
            ->contains(fn ($e) => is_array($e) && ! empty($e['exercise_id']));
        if ($inJson) {
            return true;
        }

        $inDays = collect(is_array($this->days) ? $this->days : [])
            ->contains(function ($d) {
                $exs = is_array($d['exercises'] ?? null) ? $d['exercises'] : [];
                return collect($exs)->contains(fn ($e) => is_array($e) && ! empty($e['exercise_id']));
            });
        if ($inDays) {
            return true;
        }

        // routine_exercises siempre lleva exercise_id (FK): su existencia = vínculo.
        return $this->routineExercises()->exists();
    }
}
