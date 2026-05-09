<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MyClass extends Model
{
    /** @use HasFactory<\Database\Factories\ClassFactory> */
    use HasFactory;

    protected $table = 'classes';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'trainer_id',
        'day_of_week',
        'start_time',
        'end_time',
        'duration_minutes',
        'max_capacity',
        'enrolled_count',
        'location',
        'status',
        'description',
        'notes',
        'is_recurring',
        'allow_online_booking',
        'requires_active_plan',
    ];

    /**
     * Get the trainer of the class.
     */
    public function trainer()
    {
        return $this->belongsTo(Trainer::class, 'trainer_id');
    }
}
