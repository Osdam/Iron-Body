<?php

/*
 * RELOJ DESPLAZADO del E2E del Hito 9 (la costura del H8, `ops/h8-e2e/reloj.php`, con variables propias). Lo carga PHP
 * antes que nada (`-d auto_prepend_file=`) SOLO en el proceso de `outreach:dispatch --inline` del guion. No toca el
 * código de la aplicación.
 *
 * Por qué: el planificador fija `not_before` con un desfase DETERMINISTA de 0–14 min por persona dentro del hueco
 * (`OutreachPlanner::OFFSET_MINUTES`) y, fuera del horario legal (Ley 2300: lunes a viernes 08–19 y sábados 08–15 de
 * Bogotá), en el primer hueco permitido. Para despachar en la misma vuelta sin esperar, el guion corre el despacho con
 * `now()` de Carbon en el ÚLTIMO `not_before` planificado + 1 min. La base de datos, la API y el navegador siguen con el
 * reloj real; `time()` nativo también.
 *
 * Variables: H9_E2E_RELOJ_OBJETIVO (ISO 8601 con zona) y H9_E2E_VENDOR (el autoload de la copia). Sin ellas, no hace nada.
 */

declare(strict_types=1);
use Carbon\CarbonImmutable;

(static function (): void {
    $objetivo = getenv('H9_E2E_RELOJ_OBJETIVO');
    $vendor = getenv('H9_E2E_VENDOR');
    if (! is_string($objetivo) || $objetivo === '' || ! is_string($vendor) || ! is_file($vendor)) {
        return;
    }
    require_once $vendor;
    $desfase = CarbonImmutable::parse($objetivo)->getTimestamp() - time();
    // Un DateTimeImmutable NATIVO: crear un Carbon dentro del cierre vuelve a pedir «ahora» y recursa sin fin.
    Carbon\Carbon::setTestNow(static fn (): DateTimeImmutable => (new DateTimeImmutable('@'.sprintf('%.6F', microtime(true) + $desfase)))->setTimezone(new DateTimeZone('UTC')));
    fwrite(STDERR, 'h9-e2e reloj: desfase de '.$desfase." s (solo Carbon en este proceso)\n");
})();
