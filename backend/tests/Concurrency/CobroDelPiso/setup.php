<?php

/**
 * Prepara la carrera del cobro del piso en una base PG desechable, con los
 * ENDPOINTS REALES del backend que se prueba (nada reimplementado). Usa solo lo
 * que existe antes y después de la corrección, para que el mismo arnés sirva
 * para demostrar el fallo en la base y su arreglo.
 *
 * Uso: php setup.php <ruta_backend> <escenario> <salida.json>
 * Escenario: cobro_doble_del_mes
 */

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\User;
use App\Services\Admin\AdminSessionService;
use App\Services\Caja\CashShiftService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

[$_, $appPath, $scenario, $out] = $argv + [null, null, null, null];
if ($scenario !== 'cobro_doble_del_mes' || $out === null) {
    fwrite(STDERR, "uso: setup.php <backend> cobro_doble_del_mes <salida.json>\n");
    exit(2);
}

require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

$base = (string) DB::connection()->getDatabaseName();
if (DB::connection()->getDriverName() !== 'pgsql' || ! (str_starts_with($base, 'ultron_studio_') || str_starts_with($base, 'subs_bea_'))) {
    fwrite(STDERR, "solo contra una base PG desechable ultron_studio_* o subs_bea_*\n");
    exit(2);
}

$admin = Admin::create([
    'name' => 'Carrera Mostrador', 'email' => 'carrera-'.uniqid().'@ironbody.test',
    'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
]);
$token = app(AdminSessionService::class)->issueSession($admin)['token'];

/** Una petición por el kernel HTTP real, con la sesión del mostrador. */
$http = function (string $method, string $uri, array $body) use ($app, $token): array {
    $kernel = $app->make(HttpKernel::class);
    $req = Request::create($uri, $method, [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1',
    ], json_encode($body));
    $res = $kernel->handle($req);
    $json = json_decode((string) $res->getContent(), true);
    if ($res->getStatusCode() >= 300) {
        fwrite(STDERR, "setup: {$method} {$uri} → {$res->getStatusCode()} ".substr((string) $res->getContent(), 0, 300)."\n");
        exit(2);
    }

    return $json;
};

// «Pagó noviembre» con el dinero delante: hace falta la caja del gimnasio abierta.
app(CashShiftService::class)->open($admin, CashShiftType::GYM);

$entrenador = Trainer::create(['full_name' => 'Entrenador Carrera', 'document' => (string) random_int(10000000, 99999999),
    'email' => 'entrenador-carrera-'.uniqid().'@ironbody.test', 'status' => 'active']);
$user = User::create(['name' => 'Clienta Carrera', 'email' => 'clienta-carrera-'.uniqid().'@ironbody.test',
    'password' => 'secret', 'status' => 'active']);
$cliente = Member::create(['user_id' => $user->id, 'full_name' => 'Clienta Carrera',
    'document_number' => (string) random_int(10000000, 99999999), 'status' => Member::STATUS_ACTIVE]);

$acuerdo = $http('POST', '/api/admin/commissions/agreements', [
    'trainer_id' => $entrenador->id, 'member_id' => $cliente->id, 'amount' => 50000, 'payer' => 'trainer',
]);

file_put_contents($out, json_encode([
    'scenario' => $scenario,
    'token' => $token,
    'agreement_id' => $acuerdo['id'],
    'period' => now()->format('Y-m'),
    'amount' => 50000,
], JSON_PRETTY_PRINT));
echo "ok\n";
