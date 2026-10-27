<?php

/**
 * Prepara un escenario de carrera en la base PG de la copia aislada, usando
 * los SERVICIOS REALES del backend (nada reimplementado). Solo la parte
 * concurrente va luego por HTTP (race.php).
 *
 * Uso (desde la copia de la app): php ../race/setup.php <escenario> <salida.json>
 * Escenarios: pin | exc_first | exc_renewal | pair
 */

use App\Exceptions\WorkforceException;
use App\Models\Identity;
use App\Models\MyClass;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Models\WorkforceAuthorizationCode;
use App\Services\Identity\IdentityLinkService;
use App\Services\Trainer\TrainerSessionService;
use App\Services\Workforce\AuthorizationCodeService;
use App\Services\Workforce\DeviceRegistry;
use App\Services\Workforce\FaceReferenceService;
use App\Services\Workforce\KioskTokens;
use App\Services\Workforce\LookalikeRegistry;
use App\Services\Workforce\WorkforcePins;
use App\Support\Workforce\DeviceSignature;
use App\Support\Workforce\FaceVector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

[$_, $scenario, $out] = $argv + [null, null, null];
if (! in_array($scenario, ['pin', 'exc_first', 'exc_renewal', 'pair', 'twin'], true) || $out === null) {
    fwrite(STDERR, "uso: setup.php pin|exc_first|exc_renewal|pair salida.json\n");
    exit(2);
}

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// ── geometría (misma que FaceSecondFactorTest) ─────────────────────────────
function v(float ...$lead): array
{
    $o = array_fill(0, 192, 0.0);
    foreach ($lead as $i => $x) {
        $o[$i] = $x;
    }
    $n = sqrt(array_sum(array_map(fn ($x) => $x * $x, $o)));

    return array_map(fn ($x) => $x / $n, $o);
}
$juanFace = v(1.0);
$c = 1 - (0.91 ** 2) / 2;
$camiloFace = v($c, sqrt(1 - $c * $c));
// Otra captura de Camilo (ligeramente distinta) para distinguir plantillas.
$camiloFace2 = v($c, sqrt(1 - $c * $c), 0.02);

$faces = app(FaceReferenceService::class);
$codes = app(AuthorizationCodeService::class);
$tokens = app(KioskTokens::class);
$pins = app(WorkforcePins::class);
$lookalikes = app(LookalikeRegistry::class);

function person(string $doc, string $name): Trainer
{
    $identity = app(IdentityLinkService::class)->ensureIdentity($doc);
    $t = Trainer::create(['full_name' => $name, 'document' => $doc, 'status' => 'active', 'identity_id' => $identity->id]);
    $t->syncRoles([TrainerRole::FLOOR, TrainerRole::FUNCTIONAL]);

    return $t;
}

function tablet(string $name): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $pem = openssl_pkey_get_details($key)['key'];
    $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem));
    $code = app(DeviceRegistry::class)->issuePairingCode($name, 'Sede', null, 'Tester')['code'];
    $normalized = strtoupper((string) preg_replace('/[\s\-]+/', '', $code));
    openssl_sign(DeviceSignature::registrationProof($normalized, $der), $proof, $key, OPENSSL_ALGO_SHA256);
    $device = app(DeviceRegistry::class)->register([
        'pairing_code' => $code, 'public_key' => base64_encode($der), 'key_algorithm' => 'ES256',
        'proof' => base64_encode($proof), 'key_storage' => 'strongbox', 'attestation' => null,
        'device' => ['platform' => 'android', 'manufacturer' => 'samsung', 'model' => 'SM-X200', 'os_version' => '14', 'app_version' => '2.1.0+16'],
    ], Request::create('/api/kiosk/device/register', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']));
    openssl_pkey_export($key, $privatePem);

    return ['model' => $device, 'uuid' => $device->uuid, 'private_pem' => $privatePem];
}

function enrollOk(Identity $identity, array $face, $device): void
{
    DB::transaction(fn () => app(FaceReferenceService::class)->enroll($identity, [$face, $face, $face], [
        'device_id' => $device->getKey(), 'admin_id' => null, 'admin_name' => 'Tester', 'consent_version' => (string) config('workforce.consent.version'),
    ]));
}

/** Rechazo real por parecido: deja el par (camino del kiosko: precheck fuera de transacción). */
function enrollRejected(Identity $identity, array $face): void
{
    try {
        app(FaceReferenceService::class)->precheckEnrollment($identity, [$face, $face, $face]);
    } catch (WorkforceException $e) {
        if ($e->code_ === 'face_already_linked') {
            return;
        }
        throw $e;
    }
    throw new RuntimeException('Se esperaba face_already_linked');
}

function enrollmentToken(Identity $identity, $device): string
{
    $codes = app(AuthorizationCodeService::class);
    $issued = $codes->issue($identity, WorkforceAuthorizationCode::PURPOSE_ENROLLMENT, 'Carrera de prueba', null, 'Tester');
    $code = $codes->consume($identity, WorkforceAuthorizationCode::PURPOSE_ENROLLMENT, $issued['code'], $device->getKey());

    return app(KioskTokens::class)->issueEnrollment($identity, $device, $code->getKey())['token'];
}

$juan = person('1075000005', 'Juan Entrenador');
$camilo = person('1075305903', 'Camilo López');
$A = tablet('Tablet A');
$B = tablet('Tablet B');
$result = [
    'scenario' => $scenario,
    'juan_identity' => $juan->identity_id, 'camilo_identity' => $camilo->identity_id,
    'tablets' => ['A' => ['uuid' => $A['uuid'], 'private_pem' => $A['private_pem']], 'B' => ['uuid' => $B['uuid'], 'private_pem' => $B['private_pem']]],
    'consent_version' => (string) config('workforce.consent.version'),
    'faces' => ['juan' => $juanFace, 'camilo' => $camiloFace, 'camilo2' => $camiloFace2],
    'd_juan_camilo' => FaceVector::distance($juanFace, $camiloFace),
];

if ($scenario === 'twin') {
    // Nadie registrado: Juan (tablet A) y Camilo (tablet B), a 0,91, se registran A LA VEZ.
    $result += [
        'token_A' => enrollmentToken($juan->identity, $A['model']),
        'token_B' => enrollmentToken($camilo->identity, $B['model']),
        'juan_ref_A' => $tokens->employeeRef($juan->identity, $A['model']),
    ];
    file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT));
    echo "ok twin\n";
    exit(0);
}
enrollOk($juan->identity, $juanFace, $A['model']);

if ($scenario === 'pin') {
    enrollRejected($camilo->identity, $camiloFace); // par Juan–Camilo
    $pins->set($juan->identity, '482913', $A['model']->getKey(), null);
    $dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $hoy = now('America/Bogota');
    $clase = MyClass::create([
        'name' => 'Funcional carrera', 'type' => 'Funcional', 'trainer_id' => $juan->id, 'day_of_week' => $dias[$hoy->dayOfWeek],
        'start_time' => '23:00', 'end_time' => '23:50', 'max_capacity' => 20, 'status' => 'active',
        'allow_online_booking' => true, 'is_recurring' => true,
    ]);
    $result += [
        'juan_ref_A' => $tokens->employeeRef($juan->identity, $A['model']),
        'juan_bearer' => app(TrainerSessionService::class)->issueSession($juan, ['device_id' => 'kiosk:race', 'device_name' => 'Tablet', 'platform' => 'android'])['token'],
        'class_id' => $clase->id,
        'today' => $hoy->toDateString(),
        'paired' => $lookalikes->isPaired((int) $juan->identity_id),
    ];
}

if ($scenario === 'pair') {
    // Camilo sin plantilla y sin par todavía: dos rechazos a la vez lo registrarán.
    $result += [
        'token_A' => enrollmentToken($camilo->identity, $A['model']),
        'token_B' => enrollmentToken($camilo->identity, $B['model']),
        'paired_before' => $lookalikes->isPaired((int) $camilo->identity_id),
    ];
}

if ($scenario === 'exc_first' || $scenario === 'exc_renewal') {
    enrollRejected($camilo->identity, $camiloFace); // par
    if ($scenario === 'exc_renewal') {
        // Camilo ya se registró una vez con su excepción; ahora renueva con otra.
        $lookalikes->grantException($camilo->identity, $juan->identity, 'Primer registro presencial', null, 'Tester');
        enrollOk($camilo->identity, $camiloFace, $A['model']);
    }
    $exception = $lookalikes->grantException($camilo->identity, $juan->identity, 'Comprobados los dos en recepción', null, 'Tester');
    $result += [
        'exception_id' => $exception->id,
        'camilo_ref_before' => $faces->referenceForIdentity($camilo->identity)?->id,
        'token_A' => enrollmentToken($camilo->identity, $A['model']),
        'token_B' => enrollmentToken($camilo->identity, $B['model']),
    ];
}

file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT));
echo "ok {$scenario}\n";
