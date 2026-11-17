<?php

/**
 * Prepara el caso de la reclasificación en una base PG desechable, con los
 * ENDPOINTS y SERVICIOS REALES del backend (nada reimplementado): mensualidad,
 * el cobro del «plan comisión» con su recibo y su borrador de factura, la
 * anualidad encadenada detrás y el turno CERRADO con su cierre congelado.
 *
 * Uso: php setup.php <ruta_backend> <salida.json>
 */

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Admin\AdminSessionService;
use App\Services\Caja\CashShiftService;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

[$_, $appPath, $out] = $argv + [null, null, null];
if ($appPath === null || $out === null) {
    fwrite(STDERR, "uso: setup.php <backend> <salida.json>\n");
    exit(2);
}

require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'subs_beb_')) {
    fwrite(STDERR, "solo contra una base PG desechable subs_beb_*\n");
    exit(2);
}

config(['billing.company.name' => 'IRON BODY NEIVA S.A.S.', 'billing.company.nit' => '900123456', 'billing.company.dv' => '7',
    'billing.company.address' => 'Calle 10 # 5-20, Neiva', 'billing.company.phone' => '3001234567']);

$super = Admin::create(['name' => 'Carrera Supervisión', 'email' => 'super-'.uniqid().'@ironbody.test',
    'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
$cajero = Admin::create(['name' => 'Carrera Cajero', 'email' => 'cajero-'.uniqid().'@ironbody.test',
    'password' => 'secret-password', 'role' => Admin::ROLE_RECEPCION, 'status' => 'active']);
$token = app(AdminSessionService::class)->issueSession($cajero)['token'];

/** Una petición por el kernel HTTP real, con la sesión del cajero. */
$http = function (string $method, string $uri, array $body) use ($app, $token): array {
    $res = $app->make(HttpKernel::class)->handle(Request::create($uri, $method, [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1',
    ], json_encode($body)));
    if ($res->getStatusCode() >= 300) {
        fwrite(STDERR, "setup: {$method} {$uri} → {$res->getStatusCode()} ".substr((string) $res->getContent(), 0, 300)."\n");
        exit(2);
    }

    return json_decode((string) $res->getContent(), true);
};

$persona = function (string $nombre, string $doc) {
    $user = User::create(['name' => $nombre, 'email' => 'p-'.uniqid().'@ironbody.test', 'password' => 'secret', 'status' => 'active', 'document' => $doc]);

    return Member::create(['user_id' => $user->id, 'full_name' => $nombre, 'document_number' => $doc, 'phone' => '310'.random_int(1000000, 9999999), 'status' => 'active']);
};

$mensual = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
$comision = Plan::create(['name' => 'Comisión de personalizados', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
$anual = Plan::create(['name' => 'Anualidad', 'price' => 600000, 'duration_days' => 365, 'active' => true]);
$socia = $persona('Socia Carrera', (string) random_int(10000000, 99999999));
$ficha = $persona('Entrenador Carrera', (string) random_int(1000000000, 1999999999));

// Hoy, en Bogotá: la mensualidad empieza hoy, y el cobro del piso y la
// anualidad (con inicio pedido hoy, anterior a su base) se encadenan detrás.
$hoy = MembershipPeriod::today();
app(CashShiftService::class)->open($cajero, CashShiftType::GYM);
$http('POST', '/api/payments', ['user_id' => $socia->user_id, 'plan_id' => $mensual->id, 'amount' => 80000, 'method' => 'cash', 'status' => 'paid', 'reference' => 'REC-9001']);
$pago = Payment::findOrFail($http('POST', '/api/payments', ['user_id' => $socia->user_id, 'plan_id' => $comision->id, 'amount' => 50000, 'method' => 'cash', 'status' => 'paid', 'reference' => 'REC-9002'])['id']);
$http('POST', '/api/admin/receipts', ['source_type' => 'payment', 'source_id' => $pago->id]);
$anualPago = Payment::findOrFail($http('POST', '/api/payments', ['user_id' => $socia->user_id, 'plan_id' => $anual->id, 'amount' => 600000, 'method' => 'card', 'status' => 'paid', 'reference' => 'REC-9003', 'starts_on' => $hoy->toDateString()])['id']);
app(CashShiftService::class)->close($cajero, CashShiftType::GYM);

$finAntes = (string) User::findOrFail($socia->user_id)->membershipEndDate;

file_put_contents($out, json_encode([
    'payment_id' => $pago->id,
    'annual_payment_id' => $anualPago->id,
    'user_id' => $socia->user_id,
    'member_id' => $socia->id,
    'trainer_member_id' => $ficha->id,
    'actor_id' => $super->id,
    'cash_shift_id' => $pago->cash_shift_id,
    'end_before' => $finAntes,
    // Anular el piso devuelve sus 30 días: la anualidad se corre hacia atrás.
    'end_after_expected' => CarbonImmutable::parse($finAntes)->subDays(30)->toDateString(),
], JSON_PRETTY_PRINT));
echo "ok\n";
