<?php

namespace App\Models;

use App\Enums\CashShiftType;
use App\Exceptions\ReceiptException;
use App\Services\Caja\ReceiptComposer;
use App\Services\Caja\ReceiptIssuer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Un recibo térmico de caja: la tirilla que se le entrega a quien paga.
 *
 * Lo crea {@see ReceiptIssuer} y SOLO él. El contenido que se
 * imprime vive en `snapshot`, compuesto con los datos reales de la operación en
 * el momento de emitirlo ({@see ReceiptComposer}).
 *
 * INMUTABLE. Una vez guardado no se modifica ni se borra: una reimpresión tiene
 * que decir lo mismo que el original aunque después se renombre el plan, cambie
 * el precio o se anule la venta. Lo que sí cambia con el tiempo —el estado de la
 * operación, la factura electrónica— no va aquí: se consulta en vivo cada vez.
 *
 * El estado de impresión tampoco se guarda: se deriva de `receipt_prints`, que
 * es append-only. Guardarlo aquí obligaría a actualizar el recibo, y dos números
 * que pueden discrepar sobre si algo se imprimió no sirven para nada.
 */
class Receipt extends Model
{
    public const KIND_MEMBERSHIP_PAYMENT = 'membership_payment';

    public const KIND_PLAN_UPGRADE = 'plan_upgrade';

    public const KIND_INSTALLMENT_FIRST_PAYMENT = 'installment_first_payment';

    public const KIND_RECEIVABLE_PAYMENT = 'receivable_payment';

    public const KIND_SETTLEMENT = 'settlement';

    public const KIND_COMMISSION = 'commission';

    public const KIND_PRODUCT_SALE = 'product_sale';

    public const KIND_PRODUCT_CREDIT_SALE = 'product_credit_sale';

    public const PRINT_PENDING = 'pending';

    public const PRINT_PRINTED = 'printed';

    public const PRINT_SKIPPED = 'skipped';

    public const PRINT_ERROR = 'error';

    protected $fillable = [
        'uuid', 'number', 'cash_type', 'cash_shift_id', 'kind', 'origin_key',
        'operation_ref', 'operation_at', 'issued_at', 'issued_late',
        'issued_by_admin_id', 'issued_by_name', 'total', 'customer_name',
        'snapshot', 'template_version',
    ];

    protected $casts = [
        'operation_at' => 'datetime',
        'issued_at' => 'datetime',
        'issued_late' => 'boolean',
        'total' => 'decimal:2',
        'snapshot' => 'array',
        'template_version' => 'integer',
    ];

    protected static function booted(): void
    {
        // Ni un campo cambia después de emitir. No solo el snapshot: si el
        // número, la caja o el total pudieran moverse, el snapshot inmutable
        // dejaría de describir el recibo que dice describir.
        static::updating(function (Receipt $receipt): void {
            throw ReceiptException::immutable($receipt->number);
        });

        static::deleting(function (Receipt $receipt): void {
            throw ReceiptException::immutable($receipt->number);
        });
    }

    public function cashType(): CashShiftType
    {
        return CashShiftType::from($this->cash_type);
    }

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ReceiptSource::class);
    }

    public function prints(): HasMany
    {
        return $this->hasMany(ReceiptPrint::class);
    }

    /**
     * ¿Salió papel? Derivado de los intentos, nunca guardado.
     *
     *  · printed: al menos un intento llegó a la impresora (`sent`).
     *  · skipped: alguien con permiso decidió no imprimir, y nada se imprimió.
     *  · error:   el último INTENTO DE IMPRESIÓN falló. Las omisiones (también
     *             las denegadas) no cuentan como intento: que alguien pruebe a
     *             saltarse la tirilla no borra que la impresora falló.
     *  · pending: todo lo demás, incluido un intento que sigue en `started`.
     *
     * @param  Collection<int, ReceiptPrint>|null  $prints  ya cargados, para no
     *                                                      consultar por fila.
     */
    public function printState(?Collection $prints = null): string
    {
        $prints ??= $this->relationLoaded('prints') ? $this->prints : $this->prints()->get();

        if ($prints->contains(fn (ReceiptPrint $p) => $p->status === ReceiptPrint::STATUS_SENT)) {
            return self::PRINT_PRINTED;
        }

        if ($prints->contains(fn (ReceiptPrint $p) => $p->status === ReceiptPrint::STATUS_SKIPPED)) {
            return self::PRINT_SKIPPED;
        }

        $ultimoIntento = $prints
            ->filter(fn (ReceiptPrint $p) => $p->kind !== ReceiptPrint::KIND_SKIP)
            ->sortBy('id')
            ->last();

        if ($ultimoIntento?->status === ReceiptPrint::STATUS_ERROR) {
            return self::PRINT_ERROR;
        }

        return self::PRINT_PENDING;
    }
}
