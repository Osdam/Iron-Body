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
}
