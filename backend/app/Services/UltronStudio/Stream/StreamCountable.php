<?php

namespace App\Services\UltronStudio\Stream;

/**
 * Un tema por `id` creciente que sabe CONTAR sus filas hasta un cursor.
 *
 * Para qué sirve. Los ids se reservan antes del commit: una transacción larga
 * (publicar, con su despliegue) puede hacerse visible DESPUÉS que otra con un
 * id mayor ya emitido. Esa fila queda bajo el cursor y `since()` no la vería
 * nunca. El controlador cuenta en cada sondeo las filas hasta el cursor y, si
 * el recuento crece sin que el cursor se haya movido, manda un `ready` con
 * `resync: true` para ese tema: el panel relee su lista (el mismo oído que
 * `MembershipRealtimeController`). Así el `ready` de una reconexión que honra
 * los cursores puede ir con `resync: false` y los paneles dejan de releer
 * cada 55 s sin perder un commit tardío.
 *
 * Opcional: los temas de cursor compuesto (toque) no lo implementan.
 */
interface StreamCountable
{
    /** Filas del tema con id ≤ cursor, con los mismos filtros que `since()`. */
    public function countThrough(string $cursor): int;
}
