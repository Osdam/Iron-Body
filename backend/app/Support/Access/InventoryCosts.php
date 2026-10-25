<?php

namespace App\Support\Access;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Quién puede ver lo que CUESTA la mercancía.
 *
 * El precio de compra no es un dato más de la ficha: es con cuánto se queda el
 * gimnasio en cada venta, y hay dueños que no lo quieren a la vista de quien
 * atiende el mostrador. El precio de VENTA y las existencias siguen siendo de
 * todos —sin eso no se puede trabajar—; lo que se reserva es el costo.
 *
 * Se decide en UN solo sitio porque el costo no sale solo por la ficha del
 * producto: también por el valor del inventario a costo, por el importe de cada
 * movimiento de existencias y por la utilidad de cafetería, que es la venta
 * menos el costo y permite despejarlo con una resta. Repartir esta regla por
 * cada controlador habría dejado una puerta abierta por cada sitio que se
 * olvidara.
 *
 * Falla CERRADO: el token compartido de automatizaciones no resuelve a una
 * persona, solo obtiene permisos de lectura `.view`, y este no lo es.
 */
final class InventoryCosts
{
    /** Lo que revela el costo en la ficha de un producto. */
    private const PRODUCT_KEYS = ['cost_price'];

    /**
     * Lo que lo revela en un movimiento de existencias. `unit_amount` es el
     * costo unitario con el que entró la mercancía —y con el que sale, también
     * en las ventas—, y `total_amount` es ese mismo costo multiplicado.
     */
    private const MOVEMENT_KEYS = ['unit_amount', 'total_amount'];

    public static function visibleTo(Request $request): bool
    {
        return CrmPermission::allows(AdminActor::from($request), CrmPermission::INVENTORY_COST);
    }

    /**
     * Ficha de producto sin el costo, si quien pregunta no puede verlo.
     *
     * Acepta el modelo o un array ya compuesto porque los dos conviven: unas
     * respuestas devuelven el producto tal cual y otras le añaden si se puede
     * cobrar. La clave se QUITA en vez de ponerse en cero: un cero diría que la
     * mercancía no costó nada, y eso es una afirmación falsa, no una omisión.
     *
     * @param  array<string, mixed>|Model  $product
     * @return array<string, mixed>
     */
    public static function product(Request $request, array|Model $product): array
    {
        $fila = $product instanceof Model ? $product->toArray() : $product;

        return self::visibleTo($request) ? $fila : Arr::except($fila, self::PRODUCT_KEYS);
    }

    /**
     * Lo mismo para una lista. Se resuelve el permiso UNA vez: preguntarlo por
     * producto recalcularía la política entera en cada fila del catálogo.
     *
     * @param  iterable<array<string, mixed>|Model>  $products
     * @return list<array<string, mixed>>
     */
    public static function products(Request $request, iterable $products): array
    {
        $puede = self::visibleTo($request);
        $filas = [];

        foreach ($products as $p) {
            $fila = $p instanceof Model ? $p->toArray() : $p;
            $filas[] = $puede ? $fila : Arr::except($fila, self::PRODUCT_KEYS);
        }

        return $filas;
    }

    /**
     * Movimiento de existencias sin su importe, si no se puede ver el costo.
     *
     * El movimiento NO se esconde: qué entró, qué salió, cuánto, por qué y
     * quién lo registró es la historia del inventario y sigue completa. Lo
     * único que se va es el dinero.
     *
     * @param  array<string, mixed>  $movement
     * @return array<string, mixed>
     */
    public static function movement(Request $request, array $movement): array
    {
        return self::visibleTo($request) ? $movement : Arr::except($movement, self::MOVEMENT_KEYS);
    }

    /**
     * @param  iterable<array<string, mixed>>  $movements
     * @return list<array<string, mixed>>
     */
    public static function movements(Request $request, iterable $movements): array
    {
        $puede = self::visibleTo($request);

        $filas = [];
        foreach ($movements as $m) {
            $filas[] = $puede ? $m : Arr::except($m, self::MOVEMENT_KEYS);
        }

        return $filas;
    }
}
