<?php
/*
 * Un proceso LARGO del banco que hace de commit del motor: arranca la aplicación del banco una vez (como un worker o
 * una petición de PHP-FPM) y obedece órdenes por la entrada estándar, una por línea, contestando una línea JSON:
 *   leer        → UltronPublishedConfig::forConversation(987654321) (el sello del commit: nunca lanza) y su duración
 *   escribir    → una escritura de la aplicación después de la lectura (Cache::put en la caché de base de datos), como
 *                 el persist() del commit: si la conexión cayó, Laravel tiene que reconectar
 *   pid         → el pid del servidor de PostgreSQL de su conexión (para terminarla desde fuera)
 *   salir
 * Uso: php lector.php <directorio backend del banco>   (con MARKETING_ULTRON_STUDIO_CONFIG=canary en el entorno, para
 * que el motor lea la cadena, y APP_CONFIG_CACHE apuntando a un fichero que no existe, para que valga el entorno).
 */
declare(strict_types=1);
$dir = $argv[1] ?? exit("uso: php lector.php <backend>\n");
require $dir.'/vendor/autoload.php';
$app = require $dir.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$lector = $app->make(App\Services\Marketing\Ultron\UltronPublishedConfig::class);
$di = static function (array $x): void { fwrite(STDOUT, json_encode($x, JSON_UNESCAPED_UNICODE).PHP_EOL); fflush(STDOUT); };
$di(['listo' => true, 'env' => config('marketing.ultron.studio_config')]);
while (($linea = fgets(STDIN)) !== false) {
    $orden = trim($linea);
    $t = microtime(true);
    try {
        $r = match ($orden) {
            'leer' => ['sello' => $lector->forConversation(987654321)],
            'escribir' => ['escrito' => Illuminate\Support\Facades\Cache::put('banco-h7-recuperacion', time(), 60)],
            'pid' => ['pid' => (int) Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS p')->p],
            'salir' => exit(0),
            default => ['error' => 'orden desconocida'],
        };
    } catch (Throwable $e) {
        $r = ['excepcion' => class_basename($e)];
    }
    $di($r + ['orden' => $orden, 'ms' => (int) round((microtime(true) - $t) * 1000)]);
}
