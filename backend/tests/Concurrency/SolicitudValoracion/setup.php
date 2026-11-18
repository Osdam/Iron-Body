<?php

/**
 * Prepara un escenario de carrera de «SOLICITAR VALORACIÓN» en la base PG
 * desechable, con los ENDPOINTS REALES del backend para todo lo que tiene uno
 * (agendar la cita, pedir la solicitud). Entrenador, disponibilidad y socio se
 * crean con los modelos, como en las pruebas de la agenda (no hay alta de socio
 * con token de la app por HTTP).
 *
 * Uso: php setup.php <ruta_backend> <escenario> <salida.json>
 *
 * Con RACE_DROP_PENDING_INDEX=1 se borra el índice único parcial: solo para
 * demostrar que el arnés detecta dos pendientes cuando faltan las dos puertas.
 */

use App\Models\Admin;
use App\Models\Member;
use App\Models\Trainer;
use App\Models\User;
use App\Models\ValuationAvailabilityRule;
use App\Services\Admin\AdminSessionService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

[$_, $appPath, $scenario, $out] = $argv + [null, null, null, null];
$validos = ['dos_toques_misma_llave', 'dos_toques_llaves_distintas', 'agendar_vs_rechazar'];
if (! in_array($scenario, $validos, true) || $out === null) {
    fwrite(STDERR, 'uso: setup.php <backend> '.implode('|', $validos)." <salida.json>\n");
    exit(2);
}

require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'vc_c_')) {
    fwrite(STDERR, "solo contra una base PG desechable vc_c_*\n");
    exit(2);
}

if (getenv('RACE_DROP_PENDING_INDEX') === '1') {
    DB::statement('DROP INDEX valuation_requests_one_pending_per_member');
}

$admin = Admin::create([
    'name' => 'Carrera Solicitudes', 'email' => 'carrera-'.uniqid().'@ironbody.test',
    'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
]);
$adminToken = app(AdminSessionService::class)->issueSession($admin)['token'];

$http = function (string $method, string $uri, array $body, string $token) use ($app): array {
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

$entrenador = Trainer::create([
    'full_name' => 'Carlos Carrera', 'email' => 'coach-'.Str::random(8).'@ironbody.test',
    'document' => (string) random_int(10_000_000, 99_999_999), 'phone' => '3001234567',
    'status' => 'active', 'location' => 'Sede principal',
]);
foreach (range(1, 7) as $dia) {
    ValuationAvailabilityRule::create(['trainer_id' => $entrenador->id, 'weekday' => $dia, 'start_time' => '06:00', 'end_time' => '21:00', 'active' => true]);
}

$documento = (string) random_int(1_000_000_000, 1_099_999_999);
$usuario = User::create([
    'name' => 'Socia Carrera', 'email' => 'socio-'.Str::random(8).'@ironbody.test', 'password' => bcrypt('secret-password'),
    'document' => $documento, 'status' => 'active', 'membership_end_date' => '2027-12-31',
]);
$socio = Member::create([
    'full_name' => 'Socia Carrera', 'email' => 'm-'.Str::random(8).'@ironbody.test', 'document_number' => $documento,
    'phone' => '3109876543', 'status' => Member::STATUS_ACTIVE, 'user_id' => $usuario->id,
    'access_hash' => 'tok-carrera-'.Str::random(24),
]);

$escenario = [
    'scenario' => $scenario,
    'admin_token' => $adminToken,
    'member_token' => $socio->access_hash,
    'member_id' => $socio->id,
];

if ($scenario === 'agendar_vs_rechazar') {
    $escenario['request_uuid'] = $http('POST', '/api/app/valuation-requests', ['client_request_id' => (string) Str::uuid()], $socio->access_hash)['data']['request']['uuid'];
    // Mañana a las 10:00 de Bogotá: futura, dentro de la disponibilidad.
    $manana = Carbon::now('America/Bogota')->addDay()->format('Y-m-d');
    $cita = $http('POST', '/api/admin/valuations', [
        'client_request_id' => (string) Str::uuid(), 'member_id' => $socio->id, 'trainer_id' => $entrenador->id,
        'type' => 'initial', 'starts_at' => $manana.'T10:00:00-05:00', 'duration_minutes' => 60,
    ], $adminToken);
    $escenario['appointment_uuid'] = $cita['data']['uuid'];
    $escenario['appointment_id'] = (int) DB::table('valuation_appointments')->where('uuid', $cita['data']['uuid'])->value('id');
}

file_put_contents($out, json_encode($escenario, JSON_PRETTY_PRINT));
echo "ok\n";
