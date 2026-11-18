<?php

/**
 * Prepara un escenario de carrera del ciclo de valoraciones en la base PG
 * desechable (vc_a_*). El borrador de la valoración y su envío van por el
 * portal REAL del entrenador; la cita del día se crea como dato (lo que se
 * prueba es el envío, no la agenda).
 *
 * Uso: php setup.php <ruta_backend> <escenario> <salida.json>
 */

use App\Models\Admin;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Models\ValuationAppointment;
use App\Models\ValuationAvailabilityRule;
use App\Services\Admin\AdminSessionService;
use App\Services\Trainer\TrainerSessionService;
use App\Services\Valuations\ValuationTime;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

[$_, $appPath, $scenario, $out] = $argv + [null, null, null, null];
$validos = ['doble_envio', 'proxima_cita_doble'];
if (! in_array($scenario, $validos, true) || $out === null) {
    fwrite(STDERR, 'uso: setup.php <backend> '.implode('|', $validos)." <salida.json>\n");
    exit(2);
}

require $appPath.'/vendor/autoload.php';
$app = require $appPath.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || ! str_starts_with((string) DB::connection()->getDatabaseName(), 'vc_a_')) {
    fwrite(STDERR, "solo contra una base PG desechable vc_a_*\n");
    exit(2);
}

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

$entrenador = function (string $nombre): Trainer {
    $t = Trainer::create([
        'full_name' => $nombre, 'email' => 'carrera-'.Str::random(8).'@ironbody.test',
        'document' => (string) random_int(10_000_000, 99_999_999), 'phone' => '3001234567',
        'status' => 'active', 'location' => 'Sede principal',
    ]);
    $t->syncRoles([TrainerRole::FLOOR]);
    foreach (range(1, 7) as $dia) {
        ValuationAvailabilityRule::create(['trainer_id' => $t->id, 'weekday' => $dia, 'start_time' => '06:00', 'end_time' => '21:00', 'active' => true]);
    }

    return $t;
};

$socio = Member::create([
    'full_name' => 'Socia Carrera', 'email' => 'socia-'.Str::random(8).'@ironbody.test',
    'document_number' => (string) random_int(1_000_000_000, 1_099_999_999), 'phone' => '3109876543',
    'status' => Member::STATUS_ACTIVE,
]);

if ($scenario === 'doble_envio') {
    $carlos = $entrenador('Carlos Pérez');
    MemberTrainerAssignment::create(['member_id' => $socio->id, 'trainer_id' => $carlos->id, 'status' => MemberTrainerAssignment::STATUS_ACTIVE, 'assigned_at' => now()]);
    $token = app(TrainerSessionService::class)->issueSession($carlos, ['device_id' => 'carrera'])['token'];

    // La cita de HOY (Bogotá): hace media hora, o a medianoche si es más temprano.
    $hoy = ValuationTime::today();
    $inicio = Carbon::now()->utc()->subMinutes(30)->startOfMinute();
    if (ValuationTime::local($inicio)->toDateString() !== $hoy->toDateString()) {
        $inicio = ValuationTime::dayStartUtc($hoy->toDateString());
    }
    if (ValuationTime::local(Carbon::now())->format('H:i') >= '23:58') {
        fwrite(STDERR, "setup: demasiado cerca de la medianoche de Bogotá; repetir en un par de minutos\n");
        exit(2);
    }
    $cita = ValuationAppointment::create([
        'client_request_id' => (string) Str::uuid(), 'member_id' => $socio->id, 'trainer_id' => $carlos->id,
        'type' => 'initial', 'status' => ValuationAppointment::STATUS_SCHEDULED,
        'starts_at' => $inicio, 'ends_at' => $inicio->copy()->addMinutes(60), 'duration_minutes' => 60,
        'source' => ValuationAppointment::SOURCE_CRM, 'version' => 1,
    ]);

    $borrador = $http('POST', "/api/trainer/members/{$socio->id}/assessments", ['weight_kg' => 80.5, 'observations' => 'Carrera'], $token)['data'];

    file_put_contents($out, json_encode([
        'scenario' => $scenario,
        'token' => $token,
        'member_id' => $socio->id,
        'appointment_id' => $cita->id,
        'assessment_uuid' => $borrador['uuid'],
        'assessment_id' => (int) DB::table('professional_assessments')->where('uuid', $borrador['uuid'])->value('id'),
    ], JSON_PRETTY_PRINT));
} else {
    $admin = Admin::create([
        'name' => 'Carrera Valoraciones', 'email' => 'carrera-'.uniqid().'@ironbody.test',
        'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
    ]);
    $t1 = $entrenador('Andrés Pérez');
    $t2 = $entrenador('Ana Rojas');
    $dia = fn (int $mas): string => ValuationTime::today()->addDays($mas)->toDateString();

    file_put_contents($out, json_encode([
        'scenario' => $scenario,
        'token' => app(AdminSessionService::class)->issueSession($admin)['token'],
        'member_id' => $socio->id,
        'trainer_ids' => [$t1->id, $t2->id],
        'starts' => [$dia(3).'T10:00:00-05:00', $dia(4).'T11:00:00-05:00'],
    ], JSON_PRETTY_PRINT));
}
echo "ok\n";
