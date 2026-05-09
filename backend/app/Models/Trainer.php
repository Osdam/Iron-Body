<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trainer extends Model
{
    protected $fillable = [
        'full_name',
        'document',
        'phone',
        'email',
        'birth_date',
        'main_specialty',
        'specialties',
        'experience_years',
        'contract_type',
        'status',
        'rating',
        'bio',
        'certifications',
        'avatar_url',
        'banner_url',
        'availability',
        'assigned_classes',
        'assigned_members',
    ];

    protected $casts = [
        'specialties' => 'array',
        'availability' => 'array',
        'birth_date' => 'date:Y-m-d',
        'experience_years' => 'integer',
        'assigned_classes' => 'integer',
        'assigned_members' => 'integer',
        'rating' => 'float',
    ];

    protected $appends = ['name'];

    public function classes(): HasMany
    {
        return $this->hasMany(MyClass::class, 'trainer_id');
    }

    public function getNameAttribute(): string
    {
        return (string) ($this->attributes['full_name'] ?? '');
    }
}
