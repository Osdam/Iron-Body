<?php

/*
 * PERF7: tramos del ACTA del canario (`ultron:canary-report`), que es casi todo el tiempo de
 * GET versions/{uuid}/canary-evidence. Mide por separado, con los datos que dejó la familia G
 * del arnés en `ultron_studio_h7_perf` (conversación canaria 987654321 con 1 000 turnos):
 *   1. las dos consultas del comando (mensajes y acciones desde `--since`, SIN límite);
 *   2. turnos() (empareja cada entrante con sus salientes y su acción);
 *   3. revisar() (los contadores mecánicos);
 *   4. el JSON con JSON_PRETTY_PRINT que el Studio vuelve a decodificar.
 * A T = 200 turnos fijos (el tope del acta) y M mensajes variables (moviendo `--since`), y con
 * T variable a M fijo. Solo lee. Uso (desde backend/):
 *   env APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=ultron_studio_h7_perf \
 *       DB_USERNAME=ironbody DB_PASSWORD= DB_URL= PGGSSENCMODE=disable php docs/ultron-studio/ops/perf-h7/tramos-acta.php
 */

use App\Console\Commands\Marketing\UltronCanaryReport;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use App\Models\Plan;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$base = dirname(__DIR__, 4);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$db = DB::connection()->getDatabaseName();
if (! str_starts_with((string) $db, 'ultron_studio_h7_perf')) {
    fwrite(STDERR, "base no permitida: {$db}\n");
    exit(2);
}

$conv = MarketingConversation::with('lead')->findOrFail(987654321);
$entrantes = MarketingMessage::query()->where('conversation_id', $conv->id)->where('direction', 'inbound')->orderBy('id')->pluck('created_at')->all();
if (count($entrantes) < 1000) {
    fwrite(STDERR, 'la conversación canaria tiene '.count($entrantes)." entrantes; lanza antes run-queries.sh test_g\n");
    exit(2);
}

$cmd = app(UltronCanaryReport::class);
$turnos = (new ReflectionClass($cmd))->getMethod('turnos');
$revisar = (new ReflectionClass($cmd))->getMethod('revisar');

$medir = static function (callable $f, int $n = 5): array {
    $f();
    $t = [];
    for ($i = 0; $i < $n; $i++) {
        $a = hrtime(true);
        $f();
        $t[] = (hrtime(true) - $a) / 1e6;
    }
    sort($t);

    return ['p50' => round($t[intdiv($n, 2)], 1), 'max' => round(end($t), 1)];
};

printf("| T (turnos del acta) | M (mensajes desde --since) | consultas ms | turnos() ms | revisar() ms | JSON ms | total ms |\n|---|---|---|---|---|---|---|\n");
// [T, índice del primer entrante desde el que se cuenta]: T fijo a 200 con M creciente, y T creciente.
foreach ([[40, 960], [200, 800], [200, 600], [200, 0], [100, 900], [200, 800]] as [$T, $desdeIdx]) {
    $desde = $entrantes[$desdeIdx];
    $cargar = static function () use ($conv, $desde): array {
        return [
            MarketingMessage::query()->where('conversation_id', $conv->id)->where('created_at', '>=', $desde)->orderBy('id')
                ->get(['id', 'direction', 'sender_type', 'body', 'metadata', 'created_at']),
            MarketingAiAction::query()->where('conversation_id', $conv->id)->where('created_at', '>=', $desde)->orderBy('id')
                ->get(['id', 'action_type', 'status', 'source_type', 'source_event_id', 'metadata', 'created_at']),
        ];
    };
    [$mensajes, $acciones] = $cargar();
    $vendibles = Plan::query()->sellable()->get(['id', 'name', 'price']);
    $t1 = $medir($cargar);
    $lista = $turnos->invoke($cmd, $mensajes, $acciones, $T);
    $t2 = $medir(fn () => $turnos->invoke($cmd, $mensajes, $acciones, $T));
    $t3 = $medir(fn () => $revisar->invoke($cmd, $lista, $vendibles, $conv));
    $hallazgos = $revisar->invoke($cmd, $lista, $vendibles, $conv);
    $t4 = $medir(fn () => json_decode((string) json_encode(['conversation_id' => $conv->id, 'turns' => $lista, 'findings' => $hallazgos], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), true));
    printf("| %d | %d | %.1f | %.1f | %.1f | %.1f | %.1f |\n", count($lista), $mensajes->count(), $t1['p50'], $t2['p50'], $t3['p50'], $t4['p50'], $t1['p50'] + $t2['p50'] + $t3['p50'] + $t4['p50']);
}
printf("\ncarga: %s\n", implode(' ', array_map(fn ($x) => round($x, 2), sys_getloadavg())));
