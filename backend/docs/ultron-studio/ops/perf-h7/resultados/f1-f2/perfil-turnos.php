<?php

/*
 * Perfil de turnos() por tramos, sobre los datos de la familia G (canario 987654321, 1 000 turnos).
 * Solo lee. Uso (desde backend/): env APP_ENV=testing DB_CONNECTION=pgsql ... DB_DATABASE=ultron_studio_h7_perf_f1 php <este fichero>
 */

use App\Console\Commands\Marketing\UltronCanaryReport;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingMessage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$base = getcwd();
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_starts_with((string) DB::connection()->getDatabaseName(), 'ultron_studio_h7_perf')) {
    fwrite(STDERR, "base no permitida\n");
    exit(2);
}

$conv = MarketingConversation::findOrFail(987654321);
$entrantes = MarketingMessage::query()->where('conversation_id', $conv->id)->where('direction', 'inbound')->orderBy('id')->pluck('created_at')->all();
$desde = $entrantes[0];
$mensajes = MarketingMessage::query()->where('conversation_id', $conv->id)->where('created_at', '>=', $desde)->orderBy('id')
    ->get(['id', 'direction', 'sender_type', 'body', 'metadata', 'created_at']);
$acciones = MarketingAiAction::query()->where('conversation_id', $conv->id)->where('created_at', '>=', $desde)->orderBy('id')
    ->get(['id', 'action_type', 'status', 'source_type', 'source_event_id', 'metadata', 'created_at']);
printf("M=%d A=%d\n", $mensajes->count(), $acciones->count());

$medir = static function (string $nombre, callable $f, int $n = 7): void {
    $f();
    $t = [];
    for ($i = 0; $i < $n; $i++) {
        $a = hrtime(true);
        $f();
        $t[] = (hrtime(true) - $a) / 1e6;
    }
    sort($t);
    printf("%-60s p50 %7.2f ms  máx %7.2f ms\n", $nombre, $t[intdiv($n, 2)], end($t));
};

$medir('índice de acciones (metadata decodificada 1 vez)', function () use ($acciones) {
    $accionDe = [];
    foreach ($acciones as $a) {
        $metadata = $a->metadata;
        $accionDe[(int) $a->source_event_id] ??= [$a, $metadata];
        $accionDe[(int) data_get($metadata, 'message_id')] ??= [$a, $metadata];
    }
});
$medir('solo $a->metadata de todas las acciones', function () use ($acciones) {
    foreach ($acciones as $a) {
        $x = $a->metadata;
    }
});
$medir('solo $a->source_event_id de todas las acciones', function () use ($acciones) {
    foreach ($acciones as $a) {
        $x = $a->source_event_id;
    }
});
$medir('pasada por los mensajes hasta el entrante 201', function () use ($mensajes) {
    $e = 0;
    foreach ($mensajes as $m) {
        $d = $m->direction;
        if ($d == 'inbound') {
            if ($e === 200) {
                break;
            }
            $e++;
        } elseif ($d == 'outbound' && $m->id < PHP_INT_MAX) {
        }
    }
});
$primeros = $mensajes->where('direction', 'inbound')->take(200)->values();
$salientes = $mensajes->where('direction', 'outbound')->take(200)->values();
$medir('200 × $entrante->created_at', function () use ($primeros) {
    foreach ($primeros as $m) {
        $x = $m->created_at;
    }
});
$medir('200 × $entrante->metadata', function () use ($primeros) {
    foreach ($primeros as $m) {
        $x = $m->metadata;
    }
});
$medir('200 × toIso8601String()', function () use ($primeros) {
    foreach ($primeros as $m) {
        $x = $m->created_at->toIso8601String();
    }
});
$medir('200 × diffInSeconds + diffInMilliseconds (con 2+2 created_at)', function () use ($primeros, $salientes) {
    foreach ($primeros as $i => $m) {
        $x = $m->created_at->diffInSeconds($salientes[$i]->created_at);
        $y = (int) round($m->created_at->diffInMilliseconds($salientes[$i]->created_at));
    }
});
$medir('200 × diffInSeconds + diffInMilliseconds (instancias reutilizadas)', function () use ($primeros, $salientes) {
    foreach ($primeros as $i => $m) {
        $a = $m->created_at;
        $b = $salientes[$i]->created_at;
        $x = $a->diffInSeconds($b);
        $y = (int) round($a->diffInMilliseconds($b));
    }
});
$medir('200 × collect([saliente])->map(...)->all() con metadata', function () use ($salientes) {
    foreach ($salientes as $s) {
        $x = collect([$s])->map(fn ($m) => ['id' => (int) $m->id, 'tipo' => data_get($m->metadata, 'kind', 'text'), 'texto' => (string) $m->body])->all();
    }
});
$cmd = app(UltronCanaryReport::class);
$turnos = (new ReflectionClass($cmd))->getMethod('turnos');
$medir('turnos() entero, T=200', fn () => $turnos->invoke($cmd, $mensajes, $acciones, 200));
printf("carga: %s\n", implode(' ', array_map(fn ($x) => round($x, 2), sys_getloadavg())));
