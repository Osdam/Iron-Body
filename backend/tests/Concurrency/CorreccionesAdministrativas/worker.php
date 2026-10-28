<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Un proceso = una petición HTTP real al backend, despachada por el kernel de
 * Laravel dentro de este proceso (sin `artisan serve`: así no hay workers de
 * php -S que reparten conexiones ni el SIGSEGV de libpq en macOS).
 *
 * Uso: php worker.php <ruta_backend> <peticion.json> <salida.json>
 * La petición: {method, uri, token, body}. La salida: {status, body, ms}.
 */
[$_, $appPath, $jobFile, $outFile] = $argv + [null, null, null, null];
$job = json_decode((string) file_get_contents($jobFile), true);

require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);

$inicio = microtime(true);
$request = Request::create($job['uri'], $job['method'], [], [], [], [
    'HTTP_AUTHORIZATION' => 'Bearer '.$job['token'],
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'REMOTE_ADDR' => '127.0.0.1',
], json_encode($job['body'] ?? new stdClass));

try {
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $body = json_decode((string) $response->getContent(), true);
    $kernel->terminate($request, $response);
} catch (Throwable $e) {
    $status = 0;
    $body = ['exception' => get_class($e), 'message' => $e->getMessage()];
}

file_put_contents($outFile, json_encode([
    'label' => $job['label'] ?? $job['uri'],
    'status' => $status,
    'body' => $body,
    'ms' => (int) round((microtime(true) - $inicio) * 1000),
]));
