<?php

/*
 * Ayudante del E2E del Hito 9 (prueba #26, TEST9-b). Arranca la aplicación de la COPIA del backend (nunca el árbol
 * compartido) con SU fichero de entorno y hace lo que el navegador no puede hacer: las cuentas, las personas, la
 * siembra SINTÉTICA y el volcado de filas para la evidencia. Imprime UNA línea JSON, sin tokens, nombres ni teléfonos.
 *
 *   php ayudante-h9.php <backend de la copia> preflight
 *       Entorno, base, banderas efectivas del H9 y la constante de envío (tiene que seguir en false).
 *   php ayudante-h9.php <backend> cuentas <directorio de tokens, 700>
 *       Ana (Super Admin, autora) y Beto (Super Admin, revisor): una cuenta, una identidad y un perfil laboral cada uno
 *       (nombres sintéticos del personal) y una sesión del CRM por cuenta con el servicio de sesiones (token en 600, no
 *       se imprime). El vínculo cuenta → persona NO se hace aquí: lo hace la orden de consola del arranque (P9-24).
 *   php ayudante-h9.php <backend> sembrar <sombra|asistido> <manifiesto, 600> [T]
 *       Personas SINTÉTICAS (socios, prospectos, una clase) para los tres disparadores. Ningún dato real: nombres
 *       «… Sintético …», correos en .invalid, teléfonos 300 999 01xx. El manifiesto (nombres, teléfonos y claves de
 *       sujeto) va a un fichero 600 para el barrido; por la salida solo van recuentos. Las fechas (vencimientos, la clase,
 *       los seguimientos) son relativas a T (ISO 8601 UTC, el reloj del motor del guion); sin T, a ahora.
 *   php ayudante-h9.php <backend> filas <directorio>
 *       Las filas del H9 (gobierno y ejecución) en JSON, una tabla por fichero, para la evidencia.
 *   php ayudante-h9.php <backend> revocar
 *       Revoca las sesiones de las cuentas del E2E (al parar).
 */

declare(strict_types=1);

use App\Models\Admin;
use App\Services\Admin\AdminSessionService;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachSendGate;
use App\Support\Privacy\PhoneFingerprint;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$dir = $argv[1] ?? '';
$orden = $argv[2] ?? '';
if ($dir === '' || ! is_file($dir.'/artisan')) {
    fwrite(STDERR, "ayudante-h9: falta el backend de la copia\n");
    exit(2);
}

require $dir.'/vendor/autoload.php';
$app = require $dir.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Nunca contra producción ni fuera de la base desechable del E2E.
$base = (string) config('database.connections.pgsql.database');
if (app()->isProduction() || ! preg_match('/^ultron_studio_h9_e2e[a-z0-9_]{0,40}$/D', $base) || config('database.default') !== 'pgsql'
    || ! in_array((string) config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "ayudante-h9: me niego: no es la base desechable del E2E en 127.0.0.1\n");
    exit(2);
}

$salida = static function (array $datos): never {
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
};

$CUENTAS = [
    'a' => ['Ana Autora (E2E H9)', 'e2e-h9-ana@ironbody.invalid'],
    'b' => ['Beto Revisor (E2E H9)', 'e2e-h9-beto@ironbody.invalid'],
];

switch ($orden) {
    case 'preflight':
        $salida([
            'appEnv' => app()->environment(),
            'production' => app()->isProduction(),
            'db' => ['database' => $base, 'user' => (string) config('database.connections.pgsql.username')],
            'sendsEnabledInCode' => OutreachSendGate::SENDS_ENABLED_IN_CODE,
            'strategies' => [
                'enabled' => config('ultron_studio.strategies.enabled') === true,
                'publishing' => config('ultron_studio.strategies.publishing_enabled') === true,
                'window' => (string) config('ultron_studio.strategies.window'),
                'simulationGate' => (string) config('ultron_studio.strategies.simulation_gate'),
            ],
            'outreach' => [
                'enabled' => OutreachConfig::enabled(),
                'mode' => OutreachConfig::mode(),
                'assisted' => OutreachConfig::assistedEnabled(),
                'assistedCap' => OutreachConfig::assistedDailyCap(),
                'scheduler' => OutreachConfig::schedulerEnabled(),
                'monitor' => OutreachConfig::monitorEnabled(),
                'whatsapp' => OutreachConfig::whatsappEnabled(),
                'voice' => OutreachConfig::voiceEnabled(),
                'classifier' => OutreachConfig::classifierEnabled(),
            ],
            'commercialEvents' => config('commercial.events_enabled') === true,
            'stream' => config('ultron_studio_stream.enabled') === true,
            'metaEnabled' => (bool) config('services.meta.enabled', false),
        ]);

    case 'cuentas':
        $tokens = $argv[3] ?? '';
        if ($tokens === '' || ! is_dir($tokens) || (fileperms($tokens) & 0o077) !== 0) {
            fwrite(STDERR, "ayudante-h9: el directorio de tokens tiene que existir y estar en 700\n");
            exit(2);
        }
        $sesiones = app(AdminSessionService::class);
        $out = [];
        foreach ($CUENTAS as $clave => [$nombre, $correo]) {
            $admin = Admin::query()->where('email', $correo)->first() ?? Admin::query()->forceCreate([
                'uuid' => (string) Str::uuid(), 'name' => $nombre, 'email' => $correo, 'password' => '!', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
            ]);
            // La persona: una identidad sin documento ni teléfono y su perfil laboral con el nombre del personal.
            $perfil = DB::table('staff_profiles')->where('full_name', $nombre)->first(['identity_id']);
            if ($perfil === null) {
                $identidad = (int) DB::table('identities')->insertGetId([
                    'uuid' => (string) Str::uuid(), 'document_normalized' => null, 'phone_normalized' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('staff_profiles')->insert([
                    'uuid' => (string) Str::uuid(), 'identity_id' => $identidad, 'full_name' => $nombre, 'phone' => null,
                    'positions' => json_encode(['administracion']), 'status' => 'active', 'created_by_name' => 'E2E H9', 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $identidad = (int) $perfil->identity_id;
            }
            $r = $sesiones->issueSession($admin, ['user_agent' => 'h9-e2e']);
            $f = $tokens.'/token-'.$clave;
            $viejo = umask(0o077);
            file_put_contents($f, $r['token']);
            umask($viejo);
            chmod($f, 0o600);
            $out[$clave] = ['id' => (int) $admin->id, 'role' => Admin::ROLE_SUPER_ADMIN, 'name' => $nombre,
                'identity' => (string) DB::table('identities')->where('id', $identidad)->value('uuid')];
        }
        $salida(['ok' => true, 'accounts' => $out]);

    case 'sembrar':
        $fase = (string) ($argv[3] ?? '');
        $manifiesto = (string) ($argv[4] ?? '');
        if (! in_array($fase, ['sombra', 'asistido'], true) || $manifiesto === '') {
            fwrite(STDERR, "ayudante-h9: sembrar <sombra|asistido> <manifiesto>\n");
            exit(2);
        }
        $ana = (int) Admin::query()->where('email', $CUENTAS['a'][1])->value('id');
        $beto = (int) Admin::query()->where('email', $CUENTAS['b'][1])->value('id');
        if ($ana === 0 || $beto === 0) {
            fwrite(STDERR, "ayudante-h9: faltan las cuentas (orden cuentas)\n");
            exit(2);
        }
        // T: el instante del motor (el reloj de las órdenes de consola del guion); por defecto, ahora.
        $t = (string) ($argv[5] ?? '');
        $ahora = $t !== '' ? new DateTimeImmutable($t, new DateTimeZone('UTC')) : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $hoy = RelativeTime::bogotaDate($ahora);
        $diaBogota = static fn (int $d): string => (new DateTimeImmutable($hoy, new DateTimeZone('America/Bogota')))->modify(($d >= 0 ? '+' : '').$d.' days')->format('Y-m-d');
        $utc = static fn (DateTimeImmutable $d): string => $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $marca = $utc(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $previo = is_file($manifiesto) ? (json_decode((string) file_get_contents($manifiesto), true) ?: []) : [];
        $personas = $previo['people'] ?? [];
        $n = count($personas);

        $canario = static function (string $e164) use ($ana, $beto, $marca): void {
            DB::table('ultron_studio_canary_numbers')->insert([
                'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'channel' => 'whatsapp', 'alias' => 'Canario E2E H9',
                'phone_hash' => PhoneFingerprint::of($e164), 'phone_last4' => substr($e164, -4), 'country_code' => 'CO',
                'phone_encrypted' => null, 'status' => 'active', 'consent_attested' => true,
                'registered_by_admin_id' => $ana, 'registered_by_name' => 'Ana Autora (E2E H9)', 'authorized_by_admin_id' => $beto, 'authorized_by_name' => 'Beto Revisor (E2E H9)',
                'authorized_at' => $marca, 'activated_by_admin_id' => $beto, 'activated_by_name' => 'Beto Revisor (E2E H9)',
                'activated_at' => $marca, 'status_changed_at' => $marca, 'created_at' => $marca, 'updated_at' => $marca,
            ]);
        };
        // Un socio: usuario (con la fecha de fin del plan) y ficha de socio.
        $socio = static function (string $nombre, int $venceEnDias) use (&$n, $diaBogota, $marca): array {
            $n++;
            $user = (int) DB::table('users')->insertGetId([
                'name' => $nombre, 'email' => 'socio-'.$n.'-'.Str::lower(Str::random(6)).'@e2e-h9.invalid', 'password' => '!',
                'plan' => 'Mensual', 'membership_end_date' => $diaBogota($venceEnDias), 'created_at' => $marca, 'updated_at' => $marca,
            ]);
            $member = (int) DB::table('members')->insertGetId([
                'member_uuid' => (string) Str::uuid(), 'access_hash' => hash('sha256', 'e2e-h9-'.$n.Str::random(12)), 'full_name' => $nombre,
                'document_number' => 'E2EH9'.$n.Str::upper(Str::random(5)), 'user_id' => $user, 'birth_date' => '1990-05-05', 'is_minor' => false,
                'status' => 'active', 'created_at' => $marca, 'updated_at' => $marca,
            ]);

            return ['user' => $user, 'member' => $member];
        };
        $prospecto = static function (?int $member, string $telefono, ?string $consentimiento, ?string $fase = null) use ($marca): int {
            $lead = (int) DB::table('marketing_leads')->insertGetId([
                'channel' => 'whatsapp', 'phone' => $telefono, 'status' => 'new', 'temperature' => 'warm', 'member_id' => $member,
                'consent_status' => $consentimiento, 'do_not_contact' => false, 'created_at' => $marca, 'updated_at' => $marca,
            ]);
            DB::table('marketing_conversations')->insert(array_filter([
                'lead_id' => $lead, 'channel' => 'whatsapp', 'status' => 'open', 'human_takeover' => false, 'ai_enabled' => true,
                'commercial_phase' => $fase, 'created_at' => $marca, 'updated_at' => $marca,
            ], static fn ($v) => $v !== null));

            return $lead;
        };
        $anota = static function (string $id, string $nombre, string $telefono, string $clave, string $papel) use (&$personas): void {
            $personas[$id] = ['name' => $nombre, 'phone' => $telefono, 'e164' => '+57'.$telefono, 'subjectKey' => $clave, 'role' => $papel];
        };

        if ($fase === 'sombra') {
            // E1 · membership.expiring_window (7 días): quien vence en 7 (autorizado y canario), quien vence en 7 sin
            // autorización (N1 lo niega), quien vence en 8 (fuera de la ventana).
            $s1 = $socio('Socia Sintetica Uno E2E', 7);
            $prospecto($s1['member'], '3009990101', 'granted');
            $canario('+573009990101');
            $anota('S1', 'Socia Sintetica Uno E2E', '3009990101', SubjectKey::member($s1['member']), 'vence en 7 días, autorizada, canario');
            $s2 = $socio('Socio Sintetico Dos E2E', 7);
            $prospecto($s2['member'], '3009990102', 'denied');
            $canario('+573009990102');
            $anota('S2', 'Socio Sintetico Dos E2E', '3009990102', SubjectKey::member($s2['member']), 'vence en 7 días, autorización DENEGADA');
            $s3 = $socio('Socio Sintetico Tres E2E', 8);
            $prospecto($s3['member'], '3009990103', 'granted');
            $canario('+573009990103');
            $anota('S3', 'Socio Sintetico Tres E2E', '3009990103', SubjectKey::member($s3['member']), 'vence en 8 días (fuera de la ventana)');

            // E2 · class.upcoming_with_capacity: una clase mañana con cupo; quien está activo sin reserva entra; quien ya
            // reservó esa sesión queda fuera por la audiencia.
            $manana = new DateTimeImmutable($diaBogota(1), new DateTimeZone('America/Bogota'));
            $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
            $clase = (int) DB::table('classes')->insertGetId([
                'name' => 'Spinning E2E H9', 'type' => 'Spinning', 'day_of_week' => $dias[(int) $manana->format('N')], 'start_time' => '18:00', 'end_time' => '19:00',
                'max_capacity' => 10, 'status' => 'active', 'is_recurring' => true, 'allow_online_booking' => true, 'created_at' => $marca, 'updated_at' => $marca,
            ]);
            $s5 = $socio('Socia Sintetica Cinco E2E', 30);
            $prospecto($s5['member'], '3009990105', 'granted');
            $canario('+573009990105');
            $anota('S5', 'Socia Sintetica Cinco E2E', '3009990105', SubjectKey::member($s5['member']), 'activa, sin reserva (clase de mañana)');
            $s6 = $socio('Socio Sintetico Seis E2E', 30);
            $prospecto($s6['member'], '3009990106', 'granted');
            $canario('+573009990106');
            DB::table('class_reservations')->insert([
                'class_id' => $clase, 'member_id' => $s6['member'], 'session_date' => $manana->format('Y-m-d'), 'reserved_at' => $marca, 'created_at' => $marca, 'updated_at' => $marca,
            ]);
            $anota('S6', 'Socio Sintetico Seis E2E', '3009990106', SubjectKey::member($s6['member']), 'activo, YA reservó la clase de mañana');

            // E3 · lead.followup_due: un prospecto autorizado con el seguimiento vencido y otro sin autorización.
            $haceUnaHora = $utc($ahora->modify('-1 hour'));
            foreach ([['L1', 'Prospecta Sintetica Uno E2E', '3009990111', 'granted'], ['L2', 'Prospecto Sintetico Dos E2E', '3009990112', 'pending']] as [$id, $nombre, $tel, $consent]) {
                $lead = $prospecto(null, $tel, $consent, 'FOLLOW_UP');
                DB::table('marketing_leads')->where('id', $lead)->update(['name' => $nombre]);
                $canario('+57'.$tel);
                DB::table('marketing_followups')->insert(['lead_id' => $lead, 'type' => 'message', 'status' => 'pending', 'due_at' => $haceUnaHora, 'created_at' => $marca, 'updated_at' => $marca]);
                $anota($id, $nombre, $tel, SubjectKey::lead($lead), $consent === 'granted' ? 'seguimiento vencido, autorizado' : 'seguimiento vencido, SIN autorización expresa');
            }
            $extra = ['classId' => $clase, 'classDay' => $manana->format('Y-m-d')];
        } else {
            // La fase asistida: una socia NUEVA que vence en 7 días (autorizada y canario), inscrita ya en modo `live`.
            $s4 = $socio('Socia Sintetica Cuatro E2E', 7);
            $prospecto($s4['member'], '3009990104', 'granted');
            $canario('+573009990104');
            $anota('S4', 'Socia Sintetica Cuatro E2E', '3009990104', SubjectKey::member($s4['member']), 'vence en 7 días, autorizada, canario (fase asistida)');
            $extra = [];
        }

        $doc = ['bogotaToday' => $hoy, 'motorAt' => $utc($ahora), 'seededAt' => $marca, 'people' => $personas] + ($previo['extra'] ?? []) + $extra;
        $viejo = umask(0o077);
        file_put_contents($manifiesto, json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        umask($viejo);
        chmod($manifiesto, 0o600);
        $salida(['ok' => true, 'phase' => $fase, 'bogotaToday' => $hoy, 'people' => count($personas)]);

    case 'filas':
        $destino = (string) ($argv[3] ?? '');
        if ($destino === '' || ! is_dir($destino)) {
            fwrite(STDERR, "ayudante-h9: filas <directorio existente>\n");
            exit(2);
        }
        $tablas = [
            'ultron_studio_message_templates', 'ultron_studio_strategies', 'ultron_studio_strategy_versions', 'ultron_studio_strategy_simulations',
            'ultron_studio_strategy_reviews', 'ultron_studio_strategy_publications', 'ultron_studio_person_bindings',
            'outreach_runs', 'outreach_enrollments', 'outreach_decisions', 'outreach_jobs', 'outreach_outcomes', 'outreach_brakes',
            'outreach_budget_counters', 'outreach_cursors',
        ];
        $recuentos = [];
        foreach ($tablas as $t) {
            if (! DB::getSchemaBuilder()->hasTable($t)) {
                $recuentos[$t] = null;

                continue;
            }
            // Casi todas por `id`; las que no lo tienen (`outreach_cursors`, por `source`), por su primera columna.
            $orden = DB::getSchemaBuilder()->hasColumn($t, 'id') ? 'id' : (DB::getSchemaBuilder()->getColumnListing($t)[0] ?? null);
            $consulta = DB::table($t)->limit(500);
            if ($orden !== null) {
                $consulta->orderBy($orden);
            }
            $filas = $consulta->get()->map(static fn ($f) => (array) $f)->all();
            file_put_contents($destino.'/'.$t.'.json', json_encode($filas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $recuentos[$t] = count($filas);
        }
        $salida(['ok' => true, 'tables' => $recuentos]);

    case 'revocar':
        $ids = Admin::query()->whereIn('email', array_column($CUENTAS, 1))->pluck('id');
        $n = DB::table('admin_sessions')->whereIn('admin_id', $ids)->delete();
        $salida(['ok' => true, 'sessionsDeleted' => $n]);

    default:
        fwrite(STDERR, "ayudante-h9: orden desconocida (preflight|cuentas|sembrar|filas|revocar)\n");
        exit(2);
}
