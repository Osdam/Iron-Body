<?php

/*
 * Ayudante del E2E del Hito 8 (TEST8-b). Arranca la aplicación de UNA COPIA del árbol (la cerrada o la abierta del
 * banco, nunca el árbol compartido) con SU fichero de entorno y hace lo que el arnés no puede hacer por HTTP. Imprime
 * UNA línea JSON, sin secretos, tokens ni teléfonos completos.
 *
 *   php ayudante.php <backend de la copia> preflight
 *       Lo que el arnés comprueba ANTES de arrancar nada: entorno, base de datos, las bases de los adaptadores reales
 *       (tienen que ser los simuladores de 127.0.0.1: si faltaran, el código cae a los hosts reales), la constante de
 *       las llamadas y los estados del proveedor.
 *   php ayudante.php <backend> cuentas <directorio de tokens, 700>
 *       Las cuentas del E2E: Ana (Super Admin, autora), Beto (Super Admin, revisor: los cuatro ojos) y Carla
 *       (Administrador, para los 403 de función). Una sesión por cuenta con el servicio de sesiones del CRM; cada token
 *       va a su fichero en 600 y no se imprime.
 *   php ayudante.php <backend> plan
 *       Un plan vendible sintético para `consultar_planes` (el precio exacto de la base, P-3).
 *   php ayudante.php <backend> saliente <canary|contact> <uuid del destino> <clientRequestId> <id de quien pide> <despachar 0|1>
 *       COSTURA DEL E2E (documentada en el informe): la fila `requested` SELLADA de una saliente, con exactamente las
 *       columnas, el evento y el job que escribe la puerta (OutboundGate::decide, «4. La fila, el evento y el job»).
 *       Existe porque la puerta no deja pasar ninguna saliente hasta E4-6 (VoiceProviderState::verifiedRecently() =
 *       false → `elevenlabs_unverified`), y así se puede ejercer el JOB real (recheck, adaptador real, simulador). No
 *       evalúa la política: eso lo prueba la puerta por HTTP.
 *   php ayudante.php <backend> despachar <uuid de la llamada> [voice|voice-e2e]
 *       El job `PlaceOutboundVoiceCall` de una fila `requested`: a la cola `voice` (la del worker residente, como la
 *       puerta) o a `voice-e2e` (la de los workers de UN trabajo con el reloj desplazado, `reloj.php`).
 *   php ayudante.php <backend> cadena-canaria <uuid del agente de publicación> <id de quien publica>
 *       SEGUNDA COSTURA, solo en la copia CERRADA: la entrada firmada de la cadena que publicar escribe después de
 *       asignar el número (que con la constante cerrada nunca llega). Sin ella no puede existir una fila sellada.
 *   php ayudante.php <backend> olvidar-deriva <provider_agent_id>
 *       Borra la caché de deriva de un agente (la deja el job con el reloj desplazado; ver el informe).
 *   php ayudante.php <backend> observar-numero
 *       La parte de NÚMEROS de la conciliación (`VoiceReconciler::numbers()`, el mismo método de
 *       `voice:reconcile --numbers`, contra el simulador) SIN la de llamadas: deja en la caché la observación del número
 *       importado que la puerta y el job exigen de menos de 2 h (B8-g, `number_unverified`). Existe para el reloj
 *       DESPLAZADO de los workers de un trabajo (`reloj.php`): la observación tiene que ser «reciente» con SU reloj, y la
 *       conciliación completa con ese reloj pasaría a `ambiguous` las filas `requested`. Imprime el resumen, sin el número.
 *   php ayudante.php <backend> revocar
 *       Revoca las sesiones de las cuentas del E2E (al parar).
 */

declare(strict_types=1);

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Plan;
use App\Models\UltronStudio\StudioVoiceAgent;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Models\Voice\VoiceCall;
use App\Models\Voice\VoiceCallEvent;
use App\Models\Voice\VoiceContact;
use App\Services\Admin\AdminSessionService;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\VoiceCanaryDirectory;
use App\Services\Telephony\VoiceCostGuard;
use App\Services\Telephony\VoiceEvents;
use App\Services\Telephony\VoiceLocks;
use App\Services\Telephony\VoiceNumberGuard;
use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Twilio\TwilioVoiceCarrier;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioIntegrations;
use App\Services\UltronStudio\StudioVoiceEvidence;
use App\Services\UltronStudio\StudioVoiceLabConfig;
use App\Services\UltronStudio\StudioVoicePublications;
use App\Services\UltronStudio\StudioVoicePublishing;
use App\Services\Voice\ElevenLabs\ElevenLabsConfig;
use App\Services\Voice\VoiceAdapters;
use App\Services\Voice\VoiceCallContext;
use App\Services\Voice\VoiceCallPolicy;
use App\Services\Voice\VoiceProviders;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$dir = $argv[1] ?? '';
$orden = $argv[2] ?? '';
if ($dir === '' || ! is_file($dir.'/artisan')) {
    fwrite(STDERR, "ayudante: falta el backend de la copia\n");
    exit(2);
}

require $dir.'/vendor/autoload.php';
$app = require $dir.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Nunca contra producción ni fuera de la base desechable del E2E.
$base = (string) config('database.connections.pgsql.database');
if (app()->isProduction() || ! preg_match('/^ultron_studio_h8_e2e[a-z0-9_]{0,40}$/D', $base) || config('database.default') !== 'pgsql'
    || ! in_array((string) config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "ayudante: me niego: no es la base desechable del E2E en 127.0.0.1\n");
    exit(2);
}

$salida = static function (array $datos): never {
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
};

/** La URL base del adaptador de Twilio (privada en la clase: se lee igual que ella). */
$baseTwilio = static function (): string {
    $m = new ReflectionMethod(TwilioVoiceCarrier::class, 'base');

    return (string) $m->invoke(null);
};

switch ($orden) {
    case 'preflight':
        $el = ElevenLabsConfig::fromConfig();
        $salida([
            'appEnv' => app()->environment(),
            'production' => app()->isProduction(),
            'db' => ['database' => $base, 'user' => (string) config('database.connections.pgsql.username')],
            'callsEnabledInCode' => VoiceCallPolicy::CALLS_ENABLED_IN_CODE,
            'inboundEnabled' => config('voice.inbound.enabled') === true,
            'elevenlabsBase' => $el->baseUrl(),
            'twilioBase' => $baseTwilio(),
            'twilioConfigured' => TwilioVoiceCarrier::configured(),
            'webhookBase' => config('voice.webhook_base_url'),
            'providerState' => VoiceProviders::status()->state,
            'studioVoiceState' => StudioIntegrations::voiceState(),
            'lab' => array_intersect_key(StudioVoiceLabConfig::current()->toArray(), array_flip(['enabled', 'mode', 'configured', 'reasons'])),
            'queueVoice' => config('queue.connections.voice.driver'),
            'outboundScope' => config('voice.outbound.scope'),
            'publishWindow' => config('ultron_studio.publishing.window'),
        ]);

    case 'cuentas':
        $tokens = $argv[3] ?? '';
        if ($tokens === '' || ! is_dir($tokens) || (fileperms($tokens) & 0o077) !== 0) {
            fwrite(STDERR, "ayudante: el directorio de tokens tiene que existir y estar en 700\n");
            exit(2);
        }
        $sesiones = app(AdminSessionService::class);
        $ids = [];
        foreach ([
            'a' => ['Ana Autora (E2E H8)', 'e2e-h8-ana@ironbody.invalid', Admin::ROLE_SUPER_ADMIN],
            'b' => ['Beto Revisor (E2E H8)', 'e2e-h8-beto@ironbody.invalid', Admin::ROLE_SUPER_ADMIN],
            'c' => ['Carla Administradora (E2E H8)', 'e2e-h8-carla@ironbody.invalid', Admin::ROLE_ADMINISTRADOR],
        ] as $clave => [$nombre, $correo, $rol]) {
            $admin = Admin::query()->where('email', $correo)->first() ?? Admin::query()->forceCreate([
                'uuid' => (string) Str::uuid(), 'name' => $nombre, 'email' => $correo, 'password' => '!', 'role' => $rol, 'status' => 'active',
            ]);
            $r = $sesiones->issueSession($admin, ['user_agent' => 'h8-e2e']);
            $f = $tokens.'/token-'.$clave;
            $viejo = umask(0o077);
            file_put_contents($f, $r['token']);
            umask($viejo);
            chmod($f, 0o600);
            $ids[$clave] = ['id' => (int) $admin->id, 'role' => $rol];
        }
        $salida(['ok' => true, 'accounts' => $ids]);

    case 'plan':
        $plan = Plan::query()->where('name', 'Plan Mensual del banco')->first() ?? Plan::query()->forceCreate([
            'name' => 'Plan Mensual del banco', 'price' => 89000, 'duration_days' => 30, 'active' => true, 'sellable' => true,
        ]);
        $salida(['ok' => true, 'plan' => (int) $plan->id, 'sellable' => Plan::query()->sellable()->whereKey($plan->id)->exists()]);

    case 'saliente':
        [$tipo, $uuid, $crid, $actorId, $despachar] = [$argv[3] ?? '', strtolower($argv[4] ?? ''), strtolower($argv[5] ?? ''), (int) ($argv[6] ?? 0), ($argv[7] ?? '0') === '1'];
        $actor = Admin::query()->find($actorId);
        if (! in_array($tipo, ['canary', 'contact'], true) || ! Str::isUuid($uuid) || ! Str::isUuid($crid) || $actor === null) {
            fwrite(STDERR, "ayudante: saliente <canary|contact> <uuid> <clientRequestId> <actor> <0|1>\n");
            exit(2);
        }
        try {
            $fila = DB::transaction(static function () use ($tipo, $uuid, $crid, $actor): VoiceCall {
                VoiceLocks::gate();
                $ahora = now();
                $alcance = OutboundGate::currentScope();
                $parte = VoicePublishedConfig::read()[VoicePublishedConfig::partForScope($alcance)];
                if ($tipo === 'canary') {
                    $c = VoiceCanaryDirectory::forDial($uuid);
                    $numero = $c !== null && $c['number'] instanceof SensitiveParameterValue ? $c['number']->getValue() : null;
                    $origen = [VoiceCallContext::SOURCE_CANARY_REGISTRY, $c['uuid'] ?? null, null];
                } else {
                    $contacto = VoiceContact::query()->where('uuid', $uuid)->first();
                    $numero = $contacto?->phone_encrypted;
                    $origen = [VoiceCallContext::SOURCE_VOICE_CONTACTS, null, $contacto?->id];
                }
                if (! VoicePhone::isE164($numero)) {
                    throw new RuntimeException('ayudante: el destino no tiene un número que se pueda marcar');
                }
                $reserva = VoiceCostGuard::reserveMicros(VoiceCostGuard::destinationKind($numero), (int) config('voice.limits.per_call_max_seconds', 300), VoiceCostGuard::KIND_LANDLINE, VoiceCostGuard::prices());
                $llamada = (new VoiceCall)->forceFill([
                    'direction' => VoiceCall::DIRECTION_OUTBOUND,
                    'status' => VoiceCall::STATUS_REQUESTED,
                    'target_source' => $origen[0],
                    'canary_number_uuid' => $origen[1],
                    'voice_contact_id' => $origen[2],
                    'remote_hash' => VoicePhone::hash($numero),
                    'remote_last4' => VoicePhone::last4($numero),
                    'caller_authorized' => null,
                    'policy_reason' => null,
                    'client_request_id' => $crid,
                    'requested_by_type' => 'admin',
                    'requested_by_admin_id' => $actor->id,
                    'requested_by_name' => (string) $actor->name,
                    'requested_by_role' => $actor->role,
                    'attempt' => 1,
                    ...$parte->stampColumns(),
                    'budget_day' => VoiceCostGuard::budgetDay($ahora),
                    'reserved_micros' => $reserva,
                    'tool_calls' => 0,
                    'status_changed_at' => $ahora,
                ]);
                unset($numero);
                $llamada->save();
                VoiceEvents::record('outbound_requested', (int) $llamada->id, VoiceCallEvent::PROVIDER_LARAVEL, 'req:'.$llamada->uuid, [
                    'actor_type' => VoiceCallEvent::ACTOR_ADMIN, 'actor_admin_id' => $actor->id, 'actor_name' => (string) $actor->name, 'actor_role' => $actor->role,
                    'codes' => ['scope' => $alcance, 'seq' => $parte->seq, 'part' => $parte->part, 'reservedMicros' => $reserva],
                ]);

                return $llamada->refresh();
            });
        } catch (QueryException $e) {
            // La base se negó (p. ej. `vc_stamp_chk`: una saliente sin sello aplicado no puede existir). Sin registrar
            // nada en el log de la aplicación: es el resultado que el E2E comprueba.
            preg_match('/constraint "([a-z0-9_]+)"/', $e->getMessage(), $m);
            echo json_encode(['ok' => false, 'sqlstate' => (string) $e->getCode(), 'constraint' => $m[1] ?? null]), PHP_EOL;
            exit(3);
        }
        if ($despachar) {
            PlaceOutboundVoiceCall::dispatch((int) $fila->id);
        }
        $salida(['ok' => true, 'call' => (string) $fila->uuid, 'status' => (string) $fila->status, 'seq' => $fila->voice_publication_seq, 'part' => $fila->part,
            'stampState' => $fila->stamp_state, 'reservedMicros' => (int) $fila->reserved_micros, 'dispatched' => $despachar]);

    case 'despachar':
        $fila = VoiceCall::query()->where('uuid', strtolower($argv[3] ?? ''))->first();
        if ($fila === null || $fila->status !== VoiceCall::STATUS_REQUESTED) {
            fwrite(STDERR, "ayudante: no hay una saliente requested con ese uuid\n");
            exit(2);
        }
        // La cola: `voice` (la del worker residente, como la puerta) o `voice-e2e` (la de los workers de un trabajo con
        // el reloj desplazado). La conexión es siempre la del job (`voice`).
        $cola = (string) ($argv[4] ?? 'voice');
        if (! in_array($cola, ['voice', 'voice-e2e'], true)) {
            fwrite(STDERR, "ayudante: cola voice o voice-e2e\n");
            exit(2);
        }
        PlaceOutboundVoiceCall::dispatch((int) $fila->id)->onQueue($cola);
        $salida(['ok' => true, 'dispatched' => (string) $fila->uuid, 'queue' => $cola]);

    case 'cadena-canaria':
        // SEGUNDA COSTURA, solo para la copia CERRADA: la entrada de la cadena que `StudioVoicePublishing::publishGated`
        // escribe DESPUÉS de asignar el número (su transacción: el cerrojo de la cadena, `insertEntry` con la firma
        // real y la parte canaria movida al agente). Con la constante cerrada asignar es `not_attempted` y publicar se
        // detiene antes: sin esta entrada no puede existir ninguna fila sellada (`vc_stamp_chk`), y el E2E no podría
        // comprobar que el JOB también se niega por la constante. No llama a nadie.
        if (VoiceCallPolicy::CALLS_ENABLED_IN_CODE !== false) {
            fwrite(STDERR, "ayudante: la cadena a mano solo vale en la copia cerrada\n");
            exit(2);
        }
        $agente = StudioVoiceAgent::query()->where('uuid', strtolower($argv[3] ?? ''))->first();
        $actor = Admin::query()->find((int) ($argv[4] ?? 0));
        if ($agente === null || $agente->purpose !== 'publication' || $agente->status !== 'deployed' || $actor === null) {
            fwrite(STDERR, "ayudante: cadena-canaria <uuid de un agente de publicación desplegado> <id de quien publica>\n");
            exit(2);
        }
        $acceso = new StudioAccess($actor);
        $veredicto = StudioVoiceEvidence::labVerdict((string) $agente->spec_hash);
        $puerta = array_filter(['mode' => StudioVoicePublishing::labGateMode(), 'verdict' => $veredicto['latest'], 'sessionUuid' => $veredicto['sessionUuid']],
            static fn (mixed $v): bool => $v !== null);
        $fila = DB::transaction(static function () use ($agente, $acceso, $puerta) {
            StudioIntegrations::lock(StudioVoicePublishing::LOCK);
            $cabeza = StudioVoicePublications::head();
            $fila = StudioVoicePublications::insertEntry(StudioVoicePublication::ACTION_PUBLISH,
                StudioVoicePublication::SCOPE_CANARY, $agente, $cabeza, now(), $acceso, (string) Str::uuid(), $puerta, null,
                'E2E (copia cerrada): la entrada que publicar escribiría si el número se pudiera asignar', null, StudioVoicePublications::signer());
            StudioVoiceAgent::release('canary');
            StudioVoiceAgent::take((int) $agente->id, 'canary');

            return $fila;
        });
        $salida(['ok' => true, 'entry' => (string) $fila->uuid, 'seq' => (int) $fila->seq, 'scope' => (string) $fila->scope]);

    case 'observar-numero':
        $r = app(VoiceReconciler::class)->numbers(VoiceAdapters::numberObserver());
        $salida(['ok' => true, 'at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'result' => $r['result'] ?? null, 'matchesFrom' => $r['matchesFrom'] ?? null,
            'collidesWithMessaging' => $r['collidesWithMessaging'] ?? null, 'observed' => $r['observed'] ?? null, 'expected' => $r['expected'] ?? null,
            'brakeOpened' => $r['brakeOpened'] ?? null, 'policy' => VoiceNumberGuard::policyCheck()]);

    case 'olvidar-deriva':
        $agente = (string) ($argv[3] ?? '');
        $salida(['ok' => true, 'forgotten' => Cache::forget(OutboundGate::DRIFT_CACHE_PREFIX.$agente)]);

    case 'revocar':
        $ids = Admin::query()->whereIn('email', ['e2e-h8-ana@ironbody.invalid', 'e2e-h8-beto@ironbody.invalid', 'e2e-h8-carla@ironbody.invalid'])->pluck('id');
        $n = DB::table('admin_sessions')->whereIn('admin_id', $ids)->delete();
        $salida(['ok' => true, 'sessionsDeleted' => $n]);

    default:
        fwrite(STDERR, "ayudante: orden desconocida (preflight|cuentas|plan|saliente|despachar|cadena-canaria|observar-numero|olvidar-deriva|revocar)\n");
        exit(2);
}
