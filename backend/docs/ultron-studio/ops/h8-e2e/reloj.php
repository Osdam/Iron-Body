<?php

/*
 * RELOJ DESPLAZADO del E2E del Hito 8 (TEST8-b). Lo carga PHP antes que nada (`auto_prepend_file`, por un `.ini` en
 * PHP_INI_SCAN_DIR) SOLO en el worker de UN trabajo (`queue:work voice --once`) de la saliente completa, en la copia
 * ABIERTA del banco. No toca el código de la aplicación.
 *
 * Por qué: el horario legal de las salientes (D8-15: lunes a viernes 07–19, sábados 08–15, Bogotá; ni domingos ni
 * festivos) se vuelve a comprobar en el job justo antes de marcar (E6-6). Si el E2E corre fuera de ese horario, el job
 * cancela con `outside_hours` (es el caso «fuera de ventana», que el E2E también prueba con el reloj real). Para la
 * saliente COMPLETA hace falta un instante dentro del horario: este fichero desplaza `now()` de Carbon (lo que usan
 * Laravel y la telefonía) para que el reloj de ESE proceso marque H8_E2E_RELOJ_OBJETIVO + lo transcurrido desde que
 * arrancó. `time()` y `microtime()` nativos, la base de datos y los simuladores siguen con el reloj real.
 *
 * Variables: H8_E2E_RELOJ_OBJETIVO (ISO 8601 con zona, p. ej. 2026-11-09T10:00:00-05:00) y H8_E2E_VENDOR (el
 * autoload de la copia). Sin ellas, no hace nada.
 */

declare(strict_types=1);
use Carbon\CarbonImmutable;

(static function (): void {
    $objetivo = getenv('H8_E2E_RELOJ_OBJETIVO');
    $vendor = getenv('H8_E2E_VENDOR');
    if (! is_string($objetivo) || $objetivo === '' || ! is_string($vendor) || ! is_file($vendor)) {
        return;
    }
    require_once $vendor;
    $desfase = CarbonImmutable::parse($objetivo)->getTimestamp() - time();
    // Un DateTimeImmutable NATIVO: crear un Carbon dentro del cierre vuelve a pedir «ahora» y recursa sin fin.
    Carbon\Carbon::setTestNow(static fn (): DateTimeImmutable => (new DateTimeImmutable('@'.sprintf('%.6F', microtime(true) + $desfase)))->setTimezone(new DateTimeZone('UTC')));
    fwrite(STDERR, 'h8-e2e reloj: desfase de '.$desfase." s (solo Carbon en este proceso)\n");
})();
