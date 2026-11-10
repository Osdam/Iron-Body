<?php

/*
 * Ayudante del ensayo OPS8-d en el banco del Hito 8 (ensayar-ops8d.sh). Arranca la aplicación de la «producción» del
 * banco ($B/api/backend) con SU fichero de entorno y hace lo que el ensayo no puede hacer por HTTP ni con psql. Imprime
 * UNA línea JSON, sin secretos, tokens ni teléfonos completos. Es el ayudante.php del E2E (ops/h8-e2e/) reducido a lo
 * que necesita este ensayo, y se niega fuera de una base ultron_studio_banco_h8_* en 127.0.0.1 (el E2E se niega fuera de
 * ultron_studio_h8_e2e*: por eso no se reutiliza tal cual).
 *
 *   php ayudante-ops8d.php <backend del banco> cuentas <directorio de tokens, 700>
 *       Las dos cuentas de los cuatro ojos: Ana (Super Admin, autora) y Beto (Super Admin, revisor), ACTIVAS, con una
 *       sesión cada una por el servicio de sesiones del CRM; cada token va a su fichero en 600 y no se imprime.
 *   php ayudante-ops8d.php <backend> canarios
 *       Los uuid de los canarios de voz activos (los siembra sembrar.sh --canarios-voz); nunca el teléfono.
 *   php ayudante-ops8d.php <backend> observar-numero
 *       La parte de NÚMEROS de la conciliación (VoiceReconciler::numbers(), lo mismo que `voice:reconcile --numbers`)
 *       sin la de llamadas: deja en la caché la observación del número importado que la puerta y el job exigen de menos
 *       de 2 h (B8-g, `number_unverified`). Con el reloj desplazado del E2E (reloj.php por auto_prepend_file) la
 *       observación es «reciente» para el proceso desplazado; después se repite con el reloj real.
 *   php ayudante-ops8d.php <backend> revocar
 *       Revoca las sesiones de las dos cuentas (al terminar).
 */

declare(strict_types=1);

use App\Models\Admin;
use App\Models\UltronStudio\StudioCanaryNumber;
use App\Services\Admin\AdminSessionService;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$dir = $argv[1] ?? '';
$orden = $argv[2] ?? '';
if ($dir === '' || ! is_file($dir.'/artisan')) {
    fwrite(STDERR, "ayudante-ops8d: falta el backend del banco\n");
    exit(2);
}

require $dir.'/vendor/autoload.php';
$app = require $dir.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Nunca contra producción ni fuera de la base desechable del banco.
$base = (string) config('database.connections.pgsql.database');
if (app()->isProduction() || ! preg_match('/^ultron_studio_banco_h8_[a-z0-9_]{1,40}$/D', $base) || config('database.default') !== 'pgsql'
    || ! in_array((string) config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "ayudante-ops8d: me niego: no es la base desechable del banco en 127.0.0.1\n");
    exit(2);
}

$salida = static function (array $datos): never {
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
};

const CUENTAS = [
    'a' => ['Ana Autora (OPS8-d)', 'ops8d-ana@ironbody.invalid'],
    'b' => ['Beto Revisor (OPS8-d)', 'ops8d-beto@ironbody.invalid'],
];

switch ($orden) {
    case 'cuentas':
        $tokens = $argv[3] ?? '';
        if ($tokens === '' || ! is_dir($tokens) || (fileperms($tokens) & 0o077) !== 0) {
            fwrite(STDERR, "ayudante-ops8d: el directorio de tokens tiene que existir y estar en 700\n");
            exit(2);
        }
        $sesiones = app(AdminSessionService::class);
        $ids = [];
        foreach (CUENTAS as $clave => [$nombre, $correo]) {
            $admin = Admin::query()->where('email', $correo)->first() ?? Admin::query()->forceCreate([
                'uuid' => (string) Str::uuid(), 'name' => $nombre, 'email' => $correo, 'password' => '!', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
            ]);
            $r = $sesiones->issueSession($admin, ['user_agent' => 'ops8d']);
            $f = $tokens.'/token-'.$clave;
            $viejo = umask(0o077);
            file_put_contents($f, $r['token']);
            umask($viejo);
            chmod($f, 0o600);
            $ids[$clave] = (int) $admin->id;
        }
        $salida(['ok' => true, 'accounts' => $ids]);

    case 'canarios':
        $uuids = StudioCanaryNumber::query()->where('channel', 'voice')->where('status', 'active')->orderBy('id')->pluck('uuid')->map(static fn ($u): string => (string) $u)->all();
        $salida(['ok' => true, 'canaries' => $uuids]);

    case 'observar-numero':
        $r = app(VoiceReconciler::class)->numbers(VoiceAdapters::numberObserver());
        $salida(['ok' => true, 'at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'result' => $r['result'] ?? null, 'matchesFrom' => $r['matchesFrom'] ?? null,
            'brakeOpened' => $r['brakeOpened'] ?? null, 'policy' => VoiceNumberGuard::policyCheck()]);

    case 'revocar':
        $ids = Admin::query()->whereIn('email', array_column(CUENTAS, 1))->pluck('id');
        $n = DB::table('admin_sessions')->whereIn('admin_id', $ids)->delete();
        $salida(['ok' => true, 'sessionsDeleted' => $n]);

    default:
        fwrite(STDERR, "ayudante-ops8d: orden desconocida (cuentas|canarios|observar-numero|revocar)\n");
        exit(2);
}
