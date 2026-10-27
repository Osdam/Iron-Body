<?php

namespace App\Rules;

use App\Services\Caja\CashReceipts;
use App\Services\Payments\PaymentSplits;
use App\Support\Caja\PaymentMethodKind;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un medio de pago que llega por la API es un medio que la caja sabe contar.
 *
 * Sin esto cualquier texto («fiado», «crédito») entraba, `normalize()` lo
 * mandaba a «Otros» y contaba como dinero recibido sin deuda detrás. Vale:
 * una grafía reconocida u `other` dicho a propósito ({@see PaymentMethodKind::isKnown()}),
 * y `mixed` solo para el cobro entero y con su desglose.
 *
 * `receivable` lo rechaza aquí, salvo donde lo decide
 * PaymentController::assertMedioSinCruzarElDevengo con su propio mensaje
 * (`$devengoLoDecideElCobro`, solo en el alta y la edición de cobros). En una
 * mejora de plan nadie más lo miraría y `normalize()` lo dejaría en «Otros».
 *
 * Se aplica en todos los sitios que aceptan un medio libre (cobros, partes de
 * un desglose, mejoras de plan) para que la regla sea una sola.
 */
class PaymentMethodInput implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  bool  $esParte  Valida una parte de un desglose, no el cobro entero.
     * @param  string|null  $actual  El medio que el cobro ya tiene: reenviarlo no es
     *                               cambiarlo, y así un importado con `manual` se
     *                               sigue pudiendo editar.
     * @param  bool  $devengoLoDecideElCobro  `receivable` lo juzga después
     *                                        assertMedioSinCruzarElDevengo.
     */
    public function __construct(
        private readonly bool $esParte = false,
        private readonly ?string $actual = null,
        private readonly bool $devengoLoDecideElCobro = false,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Vacío lo resuelven `nullable`/`required`, y el tipo, `string`.
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $medio = strtolower(trim($value));

        if ($this->actual !== null && $medio === strtolower(trim($this->actual))) {
            return;
        }

        if (app(CashReceipts::class)->isAccrual($medio)) {
            if ($this->esParte) {
                $fail('«receivable» no es un medio de pago: el desglose reparte el dinero que entró entre medios reales.');
            } elseif (! $this->devengoLoDecideElCobro) {
                $fail('«receivable» no es un medio de pago: las ventas a plazos se registran desde «Pago parcial», en Pagos.');
            }

            return;
        }

        if ($medio === PaymentSplits::MIXED) {
            if ($this->esParte) {
                $fail('Una parte del desglose no puede ser a su vez «mixto».');
            } elseif (empty($this->data['splits'])) {
                $fail('«mixed» necesita el desglose: indica cuánto entró por cada medio.');
            }

            return;
        }

        if (! PaymentMethodKind::isKnown($medio)) {
            $fail('Ese medio de pago no lo sabe contar la caja: usa efectivo, transferencia, tarjeta, Nequi, Daviplata, PSE u «other».');
        }
    }
}
