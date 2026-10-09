<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El piso de UN MES de un acuerdo.
 *
 * Guarda a qué mes pertenece y con qué condiciones se cobró —importe, quién lo
 * asumía, a quién se le reclamó—, todo CONGELADO: si el trato se renegocia en
 * noviembre, octubre siguió costando lo que costó.
 *
 * El dinero no está aquí. Está en la cuenta por cobrar, que es la que tiene los
 * abonos, el plazo y la anulación con motivo. Preguntarle a ella el saldo —en
 * vez de guardar una copia— es lo que impide tener dos números que discrepen.
 */
class TrainerCommissionCharge extends Model
{
    protected $fillable = [
        'agreement_id', 'period', 'covers_from', 'covers_to', 'cycle_days', 'amount', 'payer',
        'debtor_type', 'debtor_id', 'receivable_id',
        'created_by', 'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            // El tramo EXACTO que cubrió, congelado como el importe.
            'covers_from' => 'date',
            'covers_to' => 'date',
            'cycle_days' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(TrainerCommissionAgreement::class, 'agreement_id');
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    /** «octubre de 2026», como se dice en el mostrador. */
    public function periodLabel(): string
    {
        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        $mes = CarbonImmutable::parse($this->period);

        return $meses[(int) $mes->month].' de '.$mes->year;
    }

    /** El mes como lo guarda la columna: siempre el día 1. */
    public static function periodOf(string|CarbonImmutable $mes): string
    {
        return CarbonImmutable::parse((string) $mes)->startOfMonth()->toDateString();
    }
}
