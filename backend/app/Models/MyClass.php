<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\ClassFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MyClass extends Model
{
    /** @use HasFactory<ClassFactory> */
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'name',
        'type',
        'trainer_id',
        'instructor',
        'day_of_week',
        'start_time',
        'end_time',
        'date_time',
        'duration_minutes',
        'max_capacity',
        'enrolled_count',
        'location',
        'status',
        'description',
        'notes',
        'is_recurring',
        'renewal_hours',
        'allow_online_booking',
        'requires_active_plan',
    ];

    protected function casts(): array
    {
        return [
            'date_time' => 'datetime',
            'is_recurring' => 'boolean',
            'renewal_hours' => 'integer',
            'allow_online_booking' => 'boolean',
            'requires_active_plan' => 'boolean',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class, 'trainer_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(ClassReservation::class, 'class_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(ClassAttendance::class, 'class_id');
    }

    /** Una clase recibe asistencia solo si está activa (no cancelada/finalizada). */
    public function acceptsAttendance(): bool
    {
        return in_array(strtolower((string) $this->status), ['active', 'activa'], true);
    }

    /**
     * Returns the next occurrence datetime for a recurring class.
     * If date_time is set, returns that directly.
     */
    public function nextOccurrence(): ?Carbon
    {
        if ($this->date_time) {
            return Carbon::parse($this->date_time);
        }

        if (! $this->day_of_week || ! $this->start_time) {
            return null;
        }

        $dayMap = [
            'Lunes' => Carbon::MONDAY,
            'Martes' => Carbon::TUESDAY,
            'Miércoles' => Carbon::WEDNESDAY,
            'Jueves' => Carbon::THURSDAY,
            'Viernes' => Carbon::FRIDAY,
            'Sábado' => Carbon::SATURDAY,
            'Domingo' => Carbon::SUNDAY,
        ];

        $targetDay = $dayMap[$this->day_of_week] ?? null;
        if ($targetDay === null) {
            return null;
        }

        [$hour, $minute] = array_pad(explode(':', $this->start_time), 2, '0');
        $now = Carbon::now();

        $next = $now->copy()->next($targetDay)->setTime((int) $hour, (int) $minute, 0);

        if ($now->dayOfWeek === $targetDay) {
            $today = $now->copy()->setTime((int) $hour, (int) $minute, 0);
            $next = $today->isFuture() ? $today : $next;
        }

        return $next;
    }

    /** Índice 0..6 (Lunes..Domingo) del día programado, o null. */
    public const WEEK_DAY_INDEX = [
        'Lunes' => 0, 'Martes' => 1, 'Miércoles' => 2, 'Jueves' => 3,
        'Viernes' => 4, 'Sábado' => 5, 'Domingo' => 6,
    ];

    /**
     * Datetime de la ocurrencia de esta clase dentro de la semana cuyo lunes es
     * $weekStart. Para clases recurrentes mapea day_of_week→fecha; para clases
     * con fecha fija (date_time) devuelve esa fecha si cae en la semana pedida.
     * Usado por el planificador semanal ("Organizar mi semana").
     */
    public function occurrenceDateTimeInWeek(Carbon $weekStart): ?Carbon
    {
        $monday = $weekStart->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $monday->copy()->addDays(6)->endOfDay();

        // Clase ÚNICA (no recurrente): solo aparece en la semana de su fecha fija.
        if (! $this->is_recurring && $this->date_time) {
            $dt = Carbon::parse($this->date_time);

            return $dt->betweenIncluded($monday, $end) ? $dt : null;
        }

        $index = self::WEEK_DAY_INDEX[$this->day_of_week] ?? null;
        if ($index === null || ! $this->start_time) {
            // Recurrente sin día válido pero con fecha (compat): trátala como única.
            if ($this->date_time) {
                $dt = Carbon::parse($this->date_time);

                return $dt->betweenIncluded($monday, $end) ? $dt : null;
            }

            return null;
        }

        [$hour, $minute] = array_pad(explode(':', $this->start_time), 2, '0');
        $occ = $monday->copy()->addDays($index)->setTime((int) $hour, (int) $minute, 0);

        // Recurrente con fecha de inicio de vigencia: no aparece antes de esa fecha.
        if ($this->date_time && $occ->copy()->startOfDay()->lessThan(Carbon::parse($this->date_time)->startOfDay())) {
            return null;
        }

        return $occ;
    }

    /**
     * Ocurrencia RESERVABLE en día operativo (zona del gimnasio, por DÍA y NO por
     * hora). FUENTE ÚNICA del `session_date` de una reserva: si la clase es HOY se
     * reserva HOY aunque su hora ya pasara (el cierre lo decide el entrenador, no
     * el reloj); si su día ya pasó esta semana, la próxima semana. Así "Clases",
     * su detalle, el planificador semanal y el roster del entrenador comparten
     * EXACTAMENTE la misma fecha (antes `nextOccurrence()` saltaba a la próxima
     * semana al pasar la hora —y en UTC—, dejando la reserva en otra fecha que el
     * roster de hoy no veía). Para clases con fecha fija usa `date_time`.
     */
    public function operationalOccurrence(string $tz = 'America/Bogota'): ?Carbon
    {
        // Clase ÚNICA (no recurrente): su única ocurrencia es la fecha fija.
        if (! $this->is_recurring && $this->date_time) {
            return Carbon::parse($this->date_time);
        }

        $index = self::WEEK_DAY_INDEX[$this->day_of_week] ?? null;
        if ($index === null || ! $this->start_time) {
            // Recurrente sin día válido: si hay fecha, úsala (compat); si no, nada.
            return $this->date_time ? Carbon::parse($this->date_time) : null;
        }

        [$hour, $minute] = array_pad(explode(':', $this->start_time), 2, '0');
        $today = Carbon::today($tz);
        $occ = $today->copy()->startOfWeek(Carbon::MONDAY)->addDays($index)->setTime((int) $hour, (int) $minute, 0);

        // Cota inferior: hoy y, si la recurrente define fecha de inicio de vigencia
        // (date_time), tampoco antes de esa fecha.
        $lowerBound = $today->copy();
        if ($this->date_time) {
            $from = Carbon::parse($this->date_time)->startOfDay();
            if ($from->greaterThan($lowerBound)) {
                $lowerBound = $from;
            }
        }
        while ($occ->copy()->startOfDay()->lessThan($lowerBound)) {
            $occ->addWeek();
        }

        return $occ;
    }
}
