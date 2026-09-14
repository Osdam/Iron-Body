<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Http\Controllers\Api\PaymentController;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'birth_date',
        'gender',
        'address',
        'emergency_contact',
        'notes',
        'status',
        'plan',
        'membership_start_date',
        'membership_end_date',
        'membership_auto_renew',
        'membership_cancellation_requested_at',
        'membership_cancellation_effective_at',
        'payment_provider_subscription_id',
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
            'birth_date' => 'date:Y-m-d',
            'membership_start_date' => 'date:Y-m-d',
            'membership_end_date' => 'date:Y-m-d',
            'membership_auto_renew' => 'boolean',
            'membership_cancellation_requested_at' => 'datetime',
            'membership_cancellation_effective_at' => 'date:Y-m-d',
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

    public function appMember(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Último cobro que de verdad entró (pagado), no el último registrado.
     *
     * Un pago pendiente o anulado posterior no puede pasar por «último pago»:
     * en una exportación de socios haría creer que alguien pagó ayer cuando lo
     * que hizo fue dejar un cobro sin completar.
     */
    public function lastPaidPayment(): HasOne
    {
        $pagados = PaymentController::PAID_STATUSES;

        return $this->hasOne(Payment::class)->ofMany(
            ['id' => 'max'],
            fn ($q) => $q->whereRaw(
                'LOWER(payments.status) IN ('.implode(', ', array_fill(0, count($pagados), '?')).')',
                $pagados,
            ),
        );
    }
}
