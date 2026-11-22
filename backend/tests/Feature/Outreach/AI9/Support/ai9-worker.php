<?php

/**
 * Un TRABAJADOR REAL de la pasada de WhatsApp para la carrera de AI9
 * (`ReplyClassifierRaceTest`, solo PostgreSQL): un proceso PHP aparte que
 * arranca la aplicación contra la base de la prueba, enlaza el DOBLE del
 * transporte (que apunta cada llamada en un fichero compartido y tarda
 * 300 ms, para que la carrera sea real), espera en la BARRERA (un cerrojo
 * consultivo que la prueba retiene) y, ya suelto, corre UNA pasada de
 * `WhatsappTemplateOutcomes` con el reloj que le dan. Escribe su resultado
 * en JSON. No es una prueba ni se carga en la suite.
 *
 * Argumento: un JSON `{now, barrier, calls, label}`.
 */

declare(strict_types=1);

use App\Services\Outreach\Run\Classifier\ReplyClassifierTransport;
use App\Services\Outreach\Run\WhatsappTemplateOutcomes;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Outreach\AI9\Support\FakeReplyTransport;

$raiz = dirname(__DIR__, 5);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = json_decode((string) ($argv[1] ?? '{}'), true, 8, JSON_THROW_ON_ERROR);
config([
    'outreach.enabled' => true,
    'outreach.mode' => 'live',
    'outreach.whatsapp.enabled' => true,
    'outreach.classifier.enabled' => true,
    'outreach.classifier.daily_cap' => 10,
    'outreach.classifier.daily_budget_usd' => '1',
    'outreach.classifier.model' => 'gpt-4.1-mini',
    'outreach.classifier.price_input_usd_per_mtok' => '0.40',
    'outreach.classifier.price_output_usd_per_mtok' => '1.60',
    'commercial.events_enabled' => true,
]);
$app->instance(ReplyClassifierTransport::class, new FakeReplyTransport(FakeReplyTransport::says('not_interested', 95), true, (string) $args['calls']));
$ahora = new DateTimeImmutable((string) $args['now'], new DateTimeZone('UTC'));
Carbon::setTestNow(Carbon::instance($ahora));

$salida = ['label' => (string) ($args['label'] ?? '?'), 'pid' => getmypid(), 'driver' => DB::connection()->getDriverName()];
try {
    $barrera = (int) $args['barrier'];
    DB::select('SELECT pg_advisory_lock_shared(?)', [$barrera]);
    DB::select('SELECT pg_advisory_unlock_shared(?)', [$barrera]);
    $salida['run'] = (new WhatsappTemplateOutcomes)->run($ahora);
} catch (Throwable $e) {
    $salida['error'] = ['class' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($salida, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
