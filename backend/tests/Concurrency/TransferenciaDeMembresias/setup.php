<?php

/**
 * Prepara un escenario de carrera de TRANSFERIR MEMBRESÍA en la base PG
 * desechable, con los ENDPOINTS REALES del backend (nada reimplementado): la
 * titular paga su anualidad por la caja y los beneficiarios se dan de alta por
 * el CRM. La titular se crea PRIMERO para tener el id más bajo: el traspaso
 * bloquea a los socios por id ascendente, y así todas las peticiones del
 * escenario chocan en la fila de la titular (la barrera de race.php).
 *
 * Uso: php setup.php <ruta_backend> <escenario> <salida.json>
 */

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Admin\AdminSessionService;
use App\Services\Caja\CashShiftService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

[$_, $appPath, $scenario, $out] = $argv + [null, null, null, null];
$validos = ['traspaso_doble_mismo_cobro', 'traspaso_misma_llave', 'traspaso_vs_anular', 'traspaso_vs_congelar', 'traspaso_vs_ajustar'];
if (! in_array($scenario, $validos, true) || $out === null) {
    fwrite(STDERR, 'uso: setup.php <backend> '.implode('|', $validos)." <salida.json>\n");
    exit(2);
}

require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'ultron_studio_')) {
    fwrite(STDERR, "solo contra una base PG desechable ultron_studio_*\n");
    exit(2);
}

$admin = Admin::create([
    'name' => 'Carrera Traspasos', 'email' => 'carrera-'.uniqid().'@ironbody.test',
    'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
]);
$token = app(AdminSessionService::class)->issueSession($admin)['token'];

$http = function (string $method, string $uri, array $body) use ($app, $token): array {
    $kernel = $app->make(HttpKernel::class);
    $req = Request::create($uri, $method, [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1',
    ], json_encode($body));
    $res = $kernel->handle($req);
    if ($res->getStatusCode() >= 300) {
        fwrite(STDERR, "setup: {$method} {$uri} → {$res->getStatusCode()} ".substr((string) $res->getContent(), 0, 300)."\n");
        exit(2);
    }

    return json_decode((string) $res->getContent(), true);
};

app(CashShiftService::class)->open($admin, CashShiftType::GYM);
$plan = Plan::create(['name' => 'Anualidad Élite', 'price' => 624000, 'duration_days' => 365, 'active' => true]);
$alta = fn (string $nombre, string $doc): int => (int) $http('POST', '/api/users', [
    'fullName' => $nombre, 'document' => $doc, 'phone' => '300'.substr($doc, -7), 'birthDate' => '1990-05-05',
])['id'];

$a = $alta('Titular Carrera', (string) random_int(1000000000, 1099999999));
$b1 = $alta('Beneficiario Uno', (string) random_int(1100000000, 1199999999));
$b2 = $alta('Beneficiario Dos', (string) random_int(1200000000, 1299999999));
$cobro = $http('POST', '/api/payments', ['user_id' => $a, 'plan_id' => $plan->id, 'amount' => 624000, 'method' => 'cash', 'status' => 'paid']);
$pago = Payment::findOrFail($cobro['id']);

file_put_contents($out, json_encode([
    'scenario' => $scenario,
    'token' => $token,
    'holder_id' => $a,
    'b1_id' => $b1,
    'b2_id' => $b2,
    'payment_id' => $pago->id,
    'plan_name' => $plan->name,
    'end' => $pago->period_end->format('Y-m-d'),
    'holder_end_before' => User::findOrFail($a)->membershipEndDate,
], JSON_PRETTY_PRINT));
echo "ok\n";
