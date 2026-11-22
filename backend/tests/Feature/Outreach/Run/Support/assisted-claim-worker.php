<?php

/**
 * Un TRABAJADOR REAL de la reclamación de la bandeja asistida para la carrera de R3-2
 * (`H9AssistedClaimRaceTest`, solo PostgreSQL): un proceso PHP aparte, con su propia conexión y sin
 * transacción que lo envuelva (como una petición de PHP-FPM), que arranca la aplicación contra la base de
 * la prueba y reclama UN trabajo con `AssistedQueue::claim()`. Justo después de la última lectura de la
 * comprobación (la del libro de supresiones) y ANTES del `UPDATE`, espera en una BARRERA (un cerrojo
 * consultivo de PostgreSQL que la prueba retiene en exclusiva): mientras espera, la prueba confirma la señal
 * por su conexión y suelta la barrera. Escribe su resultado en JSON por la salida estándar (un 409 de la
 * bandeja va en `conflict`, con su estado y su código: es una respuesta, no un fallo). No es una prueba
 * ni se carga en la suite (no acaba en `Test.php`).
 *
 * Argumento: un JSON `{now, barrier, job, clientRequestId, adminId, adminName}`. El entorno (la base, la
 * APP_KEY de la suite para que la cadena firmada verifique) lo pone la prueba al lanzarlo.
 */

declare(strict_types=1);

use App\Models\Admin;
use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\OutreachException;
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
    'outreach.mode' => 'live',
    'outreach.assisted.enabled' => true,
    'outreach.assisted.daily_cap' => 20,
    'commercial.events_enabled' => true,
    'commercial.enabled' => false,
]);
Carbon::setTestNow(Carbon::instance(new DateTimeImmutable((string) $args['now'], new DateTimeZone('UTC'))));

$salida = ['pid' => getmypid(), 'driver' => DB::connection()->getDriverName(), 'inTransaction' => DB::transactionLevel()];
$sql = [];
$barreraEn = null;
try {
    $barrera = (int) $args['barrier'];
    DB::listen(function ($q) use (&$sql, &$barreraEn, $barrera): void {
        $sql[] = strtolower((string) $q->sql);
        if ($barreraEn === null && str_contains(strtolower((string) $q->sql), '"detail_code"')) {
            $barreraEn = count($sql) - 1;
            // La barrera: el compartido espera al exclusivo de la prueba, que lo suelta después de confirmar la señal.
            DB::select('SELECT pg_advisory_lock_shared(?)', [$barrera]);
            DB::select('SELECT pg_advisory_unlock_shared(?)', [$barrera]);
        }
    });
    // La cuenta que reclama, sin leerla: `claim()` solo usa su id y su nombre.
    $quien = (new Admin)->forceFill(['id' => (int) $args['adminId'], 'name' => (string) $args['adminName']]);
    $salida['claim'] = (new AssistedQueue)->claim($quien, (string) $args['job'], (string) $args['clientRequestId']);
} catch (OutreachException $e) {
    // Un 409 de la bandeja es su RESPUESTA (m-1 de security R3: `enrollment_closed`), no un fallo del trabajador.
    $salida['conflict'] = ['status' => $e->status, 'code' => $e->errorCode, 'message' => $e->getMessage()];
} catch (Throwable $e) {
    $salida['error'] = ['class' => $e::class, 'message' => $e->getMessage()];
}
$salida['barrierAt'] = $barreraEn;
$salida['updateAt'] = array_key_first(array_filter($sql, static fn (string $q): bool => str_starts_with(ltrim($q), 'update "outreach_jobs"')));
$salida['statements'] = count($sql);

echo json_encode($salida, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
