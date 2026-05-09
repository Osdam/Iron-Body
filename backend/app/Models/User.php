<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'document',
        'phone',
        'status',
        'plan',
        'membership_start_date',
        'membership_end_date',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'membership_start_date',
        'membership_end_date',
    ];

    protected $appends = [
        'membershipStartDate',
        'membershipEndDate',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'membership_start_date' => 'date:Y-m-d',
            'membership_end_date' => 'date:Y-m-d',
        ];
    }

    public function getMembershipStartDateAttribute(): ?string
    {
        $value = $this->attributes['membership_start_date'] ?? null;
        return $value ? substr($value, 0, 10) : null;
    }

    public function getMembershipEndDateAttribute(): ?string
    {
        $value = $this->attributes['membership_end_date'] ?? null;
        return $value ? substr($value, 0, 10) : null;
    }
}
