<?php

use Illuminate\Contracts\Console\Kernel;

/**
 * Un proceso = una ejecución del comando `payments:reclassify-to-commission
 * --apply --json`, despachada por el kernel de consola de Laravel dentro de este
 * proceso, con su propia conexión a PostgreSQL.
 *
 * Uso: php worker.php <ruta_backend> <trabajo.json> <salida.json>
 * El trabajo: {label, options}. La salida: {label, exit, report, ms}.
 */
[$_, $appPath, $jobFile, $outFile] = $argv + [null, null, null, null];
$job = json_decode((string) file_get_contents($jobFile), true);

require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$inicio = microtime(true);
try {
    $salida = $kernel->call('payments:reclassify-to-commission', $job['options']);
    $informe = json_decode($kernel->output(), true);
} catch (Throwable $e) {
    $salida = -1;
    $informe = ['exception' => get_class($e), 'message' => $e->getMessage()];
}

file_put_contents($outFile, json_encode([
    'label' => $job['label'],
    'exit' => $salida,
    'report' => $informe,
    'ms' => (int) round((microtime(true) - $inicio) * 1000),
]));
