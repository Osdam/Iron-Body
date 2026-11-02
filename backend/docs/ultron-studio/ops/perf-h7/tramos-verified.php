<?php

/*
 * PERF7: cuánto cuesta `verified` (B-2, B7) por entrada del historial, separado del resto de la DTO y del JSON.
 * Con la cadena de 200 entradas de la base del banco (`ultron_studio_h7_perf_http`), firmada con la clave de ese
 * banco (PERF_STATE_DIR/app.key: si no es la misma, todas salen `verified=false` y el camino medido sería el corto).
 * Mide, n = 30 tras calentar, sobre las 200 filas leídas UNA vez (sin base de datos dentro del cronómetro):
 *   1. entryDto() ×200 (la DTO sin verificar), 2. historyEntryDto() ×200 (con verified), 3. json_encode de 2,
 *   4. UltronPublishedConfig::signatureFor() ×200 (la firma sola).
 * Uso (desde backend/), con PERF_STATE_DIR apuntando al directorio del banco (de ahí lee la clave):
 *   env APP_ENV=testing PERF_STATE_DIR=<dir> DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 \
 *       DB_DATABASE=ultron_studio_h7_perf_http DB_USERNAME=ironbody DB_PASSWORD= DB_URL= PGGSSENCMODE=disable \
 *       php docs/ultron-studio/ops/perf-h7/tramos-verified.php
 */

use App\Models\UltronStudio\StudioPublication;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioPublications;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$base = dirname(__DIR__, 4);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'ultron_studio_h7_perf_http') {
    fwrite(STDERR, "base no permitida\n");
    exit(2);
}
// La clave con la que se firmó la cadena del banco (serve.sh la guarda en PERF_STATE_DIR/app.key).
$fichero = rtrim((string) getenv('PERF_STATE_DIR'), '/').'/app.key';
if (is_readable($fichero)) {
    config(['app.key' => trim((string) file_get_contents($fichero))]);
}

// Las mismas columnas y el mismo texto acotado que lee el historial (StudioPublications::withJson()).
$columnas = (new ReflectionClassConstant(StudioPublications::class, 'COLUMNS'))->getValue();
$filas = StudioPublication::query()->select($columnas)->selectRaw('substr(effective_json, 1, 16385) as effective_json')
    ->with('version:id,uuid,label')->orderByDesc('seq')->get();
$verificadas = $filas->filter(fn ($p) => StudioPublications::historyEntryDto($p)['verified'])->count();
printf("entradas: %d · verified=true: %d (si no son todas, la clave no es la del banco)\n\n", $filas->count(), $verificadas);

$medir = static function (string $nombre, callable $f, int $n = 30) use ($filas): void {
    $f();
    $t = [];
    for ($i = 0; $i < $n; $i++) {
        $a = hrtime(true);
        $f();
        $t[] = (hrtime(true) - $a) / 1e6;
    }
    sort($t);
    printf("| %s | %.2f | %.2f | %.1f µs |\n", $nombre, $t[intdiv($n, 2)], $t[(int) ceil(0.95 * $n) - 1], 1000 * $t[intdiv($n, 2)] / max(1, $filas->count()));
};

printf("| Tramo (200 entradas) | p50 ms | p95 ms | p50 por entrada |\n|---|---|---|---|\n");
$medir('1. entryDto() sin verificar', fn () => $filas->map(fn ($p) => StudioPublications::entryDto($p))->all());
$medir('2. historyEntryDto() con verified', fn () => $filas->map(fn ($p) => StudioPublications::historyEntryDto($p))->all());
$medir('3. json_encode de 2', fn () => json_encode($filas->map(fn ($p) => StudioPublications::historyEntryDto($p))->all()));
$medir('4. signatureFor() sola', fn () => $filas->map(fn ($p) => UltronPublishedConfig::signatureFor((string) $p->effective_json))->all());
printf("\ncarga: %s\n", implode(' ', array_map(fn ($x) => round($x, 2), sys_getloadavg())));
