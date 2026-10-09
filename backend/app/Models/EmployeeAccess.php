<?php

namespace App\Models;

use App\Services\Membership\PlanAccessRules;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El permiso de un empleado para entrar al gimnasio, sin plan y sin cobro.
 *
 * Ver la migración para el porqué. Aquí solo vive la pregunta que hace la
 * puerta: ¿este permiso vale HOY? Las horas y los días los resuelve
 * {@see PlanAccessRules}, la misma clase que gobierna las horas valle de los
 * planes, para que «de 2 a 5» signifique lo mismo en los dos sitios.
 */
class EmployeeAccess extends Model
{
    protected $fillable = [
        'user_id',
        'position',
        // 'manual' (lo concedió alguien) o 'commission' (lo abrió un piso
        // pagado). Lo manual no lo toca nunca el piso.
        'source',
        'active',
        'starts_on',
        'ends_on',
        'access_days',
        'access_windows',
        'note',
        'granted_by',
        'granted_by_name',
        'revoked_at',
        'revoked_by_name',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'access_days' => 'array',
            'access_windows' => 'array',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ¿El permiso está vigente este día?
     *
     * Son tres cosas distintas y las tres tienen que cumplirse: que el
     * interruptor esté encendido, que ya haya empezado y que no haya terminado.
     * Lo de las horas y los días NO se mira aquí —eso decide si puede pasar
     * AHORA, no si el permiso existe—, y separarlo es lo que permite decirle a
     * recepción «sí es empleado, pero su franja es de 14:00 a 17:00» en vez de
     * «no es empleado».
     */
    public function isCurrentOn(?CarbonImmutable $day = null): bool
    {
        if (! $this->active) {
            return false;
        }

        $hoy = ($day ?? CarbonImmutable::now(MembershipPeriod::TZ))->startOfDay();

        if ($this->starts_on && $hoy->lessThan($this->day($this->starts_on))) {
            return false;
        }

        if ($this->ends_on && $hoy->greaterThan($this->day($this->ends_on))) {
            return false;
        }

        return true;
    }

    /** Sus días y sus franjas, en el mismo formato que las de un plan. */
    public function rules(): PlanAccessRules
    {
        return PlanAccessRules::fromArray([
            'access_mode' => PlanAccessRules::MODE_UNLIMITED,
            'access_days' => $this->access_days,
            'access_windows' => $this->access_windows,
        ]);
    }

    private function day(mixed $fecha): CarbonImmutable
    {
        return CarbonImmutable::parse(substr((string) $fecha, 0, 10), MembershipPeriod::TZ)->startOfDay();
    }
}
