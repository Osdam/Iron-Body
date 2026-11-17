<?php

namespace App\Models;

use App\Enums\DebtorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El trato: lo que el gimnasio cobra por un cliente personalizado concreto.
 *
 * «Por Ana Ruiz, Carlos paga 50.000 al mes, y lo asume la clienta». Ver la
 * migración para por qué esto no es un plan.
 */
class TrainerCommissionAgreement extends Model
{
    /** Lo asume el entrenador. */
    public const PAYER_TRAINER = 'trainer';

    /** Lo asume el cliente. */
    public const PAYER_MEMBER = 'member';

    public const PAYERS = [self::PAYER_TRAINER, self::PAYER_MEMBER];

    protected $fillable = [
        'trainer_id', 'member_id', 'amount', 'cycle_days', 'payer', 'blocks_app', 'active',
        'starts_on', 'ends_on', 'notes',
        'created_by', 'created_by_name', 'ended_at', 'ended_by_name',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            // Cuántos días cubre cada cobro. 30 es la costumbre, no la regla.
            'cycle_days' => 'integer',
            // ¿Si se pasa de la fecha, le quita la app al socio? Solo tiene
            // sentido cuando lo asume el cliente: un entrenador no es socio y
            // no tiene beneficios que perder.
            'blocks_app' => 'boolean',
            'active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'ended_at' => 'datetime',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(TrainerCommissionCharge::class, 'agreement_id');
    }

    /** A quién se le reclama: es lo que decide el trato, no el sistema. */
    public function debtorType(): DebtorType
    {
        return $this->payer === self::PAYER_MEMBER ? DebtorType::MEMBER : DebtorType::TRAINER;
    }

    public function debtorId(): int
    {
        return $this->payer === self::PAYER_MEMBER
            ? (int) $this->member_id
            : (int) $this->trainer_id;
    }

    public function payerLabel(): string
    {
        return $this->payer === self::PAYER_MEMBER ? 'El cliente' : 'El entrenador';
    }

    /**
     * ¿El trato está en vigor en algún día de ese mes?
     *
     * Activo y con el mes cruzando `[starts_on, ends_on]`. Una fecha nula es
     * «sin límite» por ese lado: así nacieron todos los acuerdos, y con las dos
     * nulas el trato vale cualquier mes, que es lo que el panel hacía antes de
     * mirar la vigencia.
     *
     * Las fechas se comparan como texto `Y-m-d`: son fechas de calendario, no
     * instantes, y así no entra en juego la zona horaria de nadie.
     */
    public function inForceDuring(CarbonImmutable $mes): bool
    {
        if (! $this->active) {
            return false;
        }

        $primero = $mes->startOfMonth()->toDateString();
        $ultimo = $mes->endOfMonth()->toDateString();

        $desde = $this->starts_on?->toDateString();
        $hasta = $this->ends_on?->toDateString();

        return ($desde === null || $desde <= $ultimo)
            && ($hasta === null || $hasta >= $primero);
    }

    /**
     * Desde qué día cubre, por defecto, el cobro de ese mes: el día 1, o el día
     * en que empezó el trato si empezó a mitad de mes. Cobrar desde el 1 un
     * trato que empezó el 6 daría por pagados cinco días que no se pactaron.
     */
    public function coverageStartFor(CarbonImmutable $mes): CarbonImmutable
    {
        $primero = $mes->startOfMonth()->startOfDay();

        if ($this->starts_on === null) {
            return $primero;
        }

        $inicio = CarbonImmutable::parse($this->starts_on->toDateString(), $primero->getTimezone())->startOfDay();

        return $inicio->greaterThan($primero) ? $inicio : $primero;
    }
}
