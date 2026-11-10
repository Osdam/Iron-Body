<?php

/**
 * Un TRABAJADOR REAL del planificador para la carrera de §13.1 #13
 * (`H9IdempotencyRaceTest`, solo PostgreSQL): un proceso PHP aparte que
 * arranca la aplicación contra la base de la prueba, espera en una BARRERA
 * (un cerrojo consultivo de PostgreSQL que la prueba retiene en exclusiva;
 * los trabajadores piden el compartido y salen todos a la vez cuando se
 * suelta) y, ya suelto, barre y planifica UNA pasada con el reloj que le
 * dan. Escribe su resultado en JSON por la salida estándar. No es una prueba
 * ni se carga en la suite (no acaba en `Test.php`).
 *
 * Argumento: un JSON `{now, barrier, mode, label}`. El entorno (la base, la
 * APP_KEY de la suite para que la cadena firmada verifique, las banderas) lo
 * pone la prueba al lanzarlo.
 */

declare(strict_types=1);

use App\Services\Outreach\Run\OutreachPlanner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$raiz = dirname(__DIR__, 5);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = json_decode((string) ($argv[1] ?? '{}'), true, 8, JSON_THROW_ON_ERROR);
config([
    'outreach.enabled' => true,
    'outreach.mode' => (string) ($args['mode'] ?? 'shadow'),
    'outreach.assisted.enabled' => true,
    'outreach.assisted.daily_cap' => 20,
    'commercial.events_enabled' => true,
]);
$ahora = new DateTimeImmutable((string) $args['now'], new DateTimeZone('UTC'));
Carbon::setTestNow(Carbon::instance($ahora));

$salida = ['label' => (string) ($args['label'] ?? '?'), 'pid' => getmypid(), 'driver' => DB::connection()->getDriverName()];
try {
    $barrera = (int) $args['barrier'];
    // La barrera: el compartido espera al exclusivo de la prueba; al soltarlo, todos los trabajadores salen a la vez.
    DB::select('SELECT pg_advisory_lock_shared(?)', [$barrera]);
    DB::select('SELECT pg_advisory_unlock_shared(?)', [$barrera]);
    $planner = new OutreachPlanner;
    $salida['sweep'] = $planner->sweep($ahora, true);
    $salida['plan'] = $planner->planDue($ahora);
} catch (Throwable $e) {
    $salida['error'] = ['class' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($salida, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
