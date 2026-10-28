<?php

/**
 * Prepara un escenario de carrera en la base PG desechable con los SERVICIOS y
 * ENDPOINTS REALES del backend que se está probando (nada reimplementado). Usa
 * solo lo que existe tanto en 2425eef como después de las correcciones, para que
 * el mismo arnés sirva para demostrar el fallo en la base y su arreglo.
 *
 * Uso: php setup.php <ruta_backend> <escenario> <salida.json>
 * Escenarios: pago_doble_anulacion | venta_doble_cobro | venta_anular_vs_cobrar
 */

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Admin\AdminSessionService;
use App\Services\Caja\CashShiftService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

[$_, $appPath, $scenario, $out] = $argv + [null, null, null, null];
$validos = ['pago_doble_anulacion', 'venta_doble_cobro', 'venta_anular_vs_cobrar', 'venta_devoluciones_simultaneas'];
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
    'name' => 'Carrera Supervisión', 'email' => 'carrera-'.uniqid().'@ironbody.test',
    'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
]);
$token = app(AdminSessionService::class)->issueSession($admin)['token'];

/** Una petición por el kernel HTTP real, con la sesión del supervisor. */
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

$datos = ['scenario' => $scenario, 'token' => $token];

if ($scenario === 'pago_doble_anulacion') {
    app(CashShiftService::class)->open($admin, CashShiftType::GYM);
    $user = User::create(['name' => 'Socia Carrera', 'email' => 'socia-'.uniqid().'@ironbody.test', 'password' => 'secret', 'status' => 'active']);
    Member::create(['user_id' => $user->id, 'full_name' => 'Socia Carrera', 'document_number' => (string) random_int(10000000, 99999999), 'is_staff' => false]);
    $plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
    $r = $http('POST', '/api/payments', ['user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 80000, 'method' => 'cash', 'status' => 'paid']);
    $pago = Payment::findOrFail($r['id']);

    $datos += [
        'user_id' => $user->id,
        'payment_id' => $pago->id,
        'end_before' => (string) $user->fresh()->membership_end_date,
        // Anular devuelve el periodo entero: la membresía vuelve a terminar
        // donde empezó el periodo de este cobro.
        'end_after_expected' => $pago->period_start->format('Y-m-d'),
    ];
} else {
    app(CashShiftService::class)->open($admin, CashShiftType::PRODUCTS);
    $tarifa = TaxRate::firstOrCreate(['code' => 'EXEMPT'], ['name' => 'Exento', 'rate' => 0, 'active' => true, 'price_includes_tax' => true]);
    $producto = Product::create([
        'name' => 'Agua carrera', 'category' => 'Cafetería', 'sale_price' => 3000, 'cost_price' => 1500,
        'stock' => 10, 'min_stock' => 0, 'active' => true, 'visible_in_app' => true,
        'tax_rate_id' => $tarifa->id, 'pricing_mode' => 'legacy_inclusive',
    ]);
    // Devoluciones: una venta COBRADA de 3 unidades. Las demás: un pedido
    // pendiente de 1 unidad.
    $devoluciones = $scenario === 'venta_devoluciones_simultaneas';
    $r = $http('POST', '/api/admin/caja/sales', [
        'items' => [['product_id' => $producto->id, 'quantity' => $devoluciones ? 3 : 1]], 'payment_method' => 'cash', 'paid' => $devoluciones,
    ]);

    $datos += [
        'product_id' => $producto->id,
        'sale_id' => $r['data']['id'],
        'item_id' => (int) DB::table('product_sale_items')->where('product_sale_id', $r['data']['id'])->value('id'),
        // Lo que había ANTES de la venta: una cobrada ya sacó sus 3 unidades.
        'stock_before' => 10,
        'sold_out' => $devoluciones ? 3 : 0,
    ];
}

file_put_contents($out, json_encode($datos, JSON_PRETTY_PRINT));
echo "ok\n";
