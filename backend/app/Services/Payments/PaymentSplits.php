<?php

namespace App\Services\Payments;

use App\Models\ProductSale;
use App\Services\Caja\CashReceipts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * EL DESGLOSE DE UN COBRO MIXTO, en un solo sitio.
 *
 * «Pago 20.000 en efectivo y 30.000 con tarjeta» es la misma operación cobre
 * quien cobre: una membresía en Pagos, un batido en Caja o la diferencia de
 * una mejora de plan. Esta clase existe porque estaba escrita solo para Pagos
 * y hacía falta en los otros dos, y copiarla era garantizar que dentro de tres
 * meses una de las tres redondeara distinto.
 *
 * LA SUMA SE COMPARA EN CENTAVOS ENTEROS. En coma flotante,
 * 0.1 + 0.2 !== 0.3: un desglose correcto podría rechazarse y uno descuadrado
 * por un céntimo colarse. Ese céntimo acaba apareciendo en el arqueo.
 *
 * SE BORRA Y SE REINSERTA en vez de conciliar fila a fila: son seis líneas
 * como mucho, y así no puede quedar un resto de un desglose anterior sumando
 * en los reportes.
 */
class PaymentSplits
{
    /** Tope de líneas. Seis cubren de sobra lo que pasa en el mostrador. */
    public const MAX = 6;

    /** El valor de `method` cuando el cobro se repartió. No es un medio de pago. */
    public const MIXED = 'mixed';

    /**
     * Las reglas de validación, para que las tres pantallas pidan lo mismo.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $campo = 'splits'): array
    {
        return [
            $campo => 'nullable|array|min:2|max:'.self::MAX,
            $campo.'.*.method' => self::methodRules(),
            $campo.'.*.amount' => 'required|numeric|min:0.01',
            $campo.'.*.reference' => 'nullable|string|max:120',
        ];
    }

    /**
     * El medio de una parte. Una parte es dinero que entró, así que nunca
     * `receivable`: esa marca es el devengo de un plan a plazos, y como parte
     * caería en «Otros» del arqueo y contaría como recibido sin deuda detrás.
     *
     * @return array<int, mixed>
     */
    public static function methodRules(): array
    {
        return ['required', 'string', 'max:80', function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && app(CashReceipts::class)->isAccrual($value)) {
                $fail('«receivable» no es un medio de pago: el desglose reparte el dinero que entró entre medios reales.');
            }
        }];
    }

    /**
     * ¿El desglose suma exactamente el total?
     *
     * @param  array<int, array{method: string, amount: mixed}>  $splits
     *
     * @throws ValidationException
     */
    public function assertMatchesTotal(array $splits, float $total, string $campo = 'splits'): void
    {
        $centavosPartes = 0;
        foreach ($splits as $parte) {
            $centavosPartes += (int) round(((float) $parte['amount']) * 100);
        }
        $centavosTotal = (int) round($total * 100);

        if ($centavosPartes !== $centavosTotal) {
            throw ValidationException::withMessages([
                $campo => sprintf(
                    'El desglose suma %s y el cobro es de %s. Tienen que coincidir.',
                    number_format($centavosPartes / 100, 2, ',', '.'),
                    number_format($centavosTotal / 100, 2, ',', '.'),
                ),
            ]);
        }
    }

    /**
     * Guarda el desglose y marca el cobro como mixto.
     *
     * Vale para un `Payment` y para una `ProductSale`: las dos tienen la
     * relación `splits()` y una columna de medio, que es todo lo que esto
     * necesita saber de ellas.
     *
     * @param  array<int, array{method: string, amount: mixed, reference?: string|null}>  $splits
     */
    public function sync(Model $cobro, array $splits): void
    {
        $cobro->splits()->delete();

        foreach ($splits as $parte) {
            $cobro->splits()->create([
                'method' => $parte['method'],
                'amount' => round((float) $parte['amount'], 2),
                'reference' => $parte['reference'] ?? null,
            ]);
        }

        $columna = $cobro instanceof ProductSale ? 'payment_method' : 'method';
        $cobro->forceFill([$columna => self::MIXED])->save();
    }

    /** ¿Este cobro se repartió entre varios medios? */
    public function isMixed(Model $cobro): bool
    {
        $columna = $cobro instanceof ProductSale ? 'payment_method' : 'method';

        return strtolower((string) $cobro->getAttribute($columna)) === self::MIXED;
    }
}
