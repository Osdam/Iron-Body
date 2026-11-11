<?php

namespace Tests\Feature\UltronStudio;

use App\Http\Controllers\Crm\UltronStudio\UltronStudioVoiceController;
use App\Models\Admin;
use App\Models\MarketingAgentSetting;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\Plan;
use App\Models\UltronAbort;
use App\Services\Marketing\MarketingAgentSwitch;
use App\Services\UltronStudio\StudioCanaryMeasures;
use App\Services\UltronStudio\StudioLabStepJob;
use App\Support\Access\AuthorizationMap;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * EL STUDIO NO ESCRIBE EN EL MOTOR (contrato G §A9, «aislamiento ampliado»).
 *
 * `UltronEngineIsolationTest` prueba que el motor no lee el Studio. Esta prueba
 * la otra dirección, que es la que abre el Hito G al leer el motor:
 *
 *  1. ESTÁTICA, por tokens. En `app/Services/UltronStudio/` solo
 *     `StudioEngineReadings.php`, `StudioRules.php` y, desde el Hito 4,
 *     `StudioLabEngineChecks.php` (las guardas puras del motor sobre el texto
 *     del laboratorio) nombran clases del motor, y en ellos no hay ninguna
 *     llamada de escritura salvo las de
 *     `StudioRules` sobre su propio modelo (su nivel de aislamiento y su
 *     cerrojo consultivo). Desde las correcciones del Hito G (I-2), también
 *     los controladores, modelos y comandos del Studio: solo el controlador
 *     de reglas nombra el motor (las categorías del conocimiento), y solo lo
 *     lee.
 *  2. DE COMPORTAMIENTO. Con la pausa puesta, el freno echado, conocimiento,
 *     planes y una conversación sembrados, se toma la huella de TODAS las
 *     tablas que no son del Studio y de la configuración; se recorren las 21
 *     rutas con escrituras reales (cada parámetro distinto de la línea base,
 *     reglas con condiciones, versiones, envío, aprobación por otra persona,
 *     rechazo, publicar y revertir, lecturas y comparación); y al final las
 *     huellas son idénticas, no se encoló nada y no salió ninguna petición.
 *     Además se ESCUCHA cada sentencia: un INSERT, UPDATE, DELETE, REPLACE o
 *     TRUNCATE fuera de las tablas `ultron_studio_*` y de `admin_sessions`
 *     (la sesión del personal, que se toca en cada petición), o cualquier DDL,
 *     es un fallo aunque su efecto se deshaga. La huella sola no lo veía: el
 *     tester del Hito G metió en la lectura de la pausa una escritura que la
 *     ALTERNA, el recorrido lee el motor dos veces y `updated_at` tiene
 *     precisión de segundo, así que la huella salía idéntica (H-1). Esa
 *     escucha venía de `UltronStudioGEngineWritesTest`, que con esto quedaba
 *     repetida y se retiró.
 *
 * Si una mutación mete una escritura del motor en el adaptador de lecturas (o
 * en cualquier ruta del Studio), la parte de comportamiento falla aunque la
 * estática no la vea.
 *
 * Hito 4 (contrato H4 §A10): el recorrido son 33 rutas, con el laboratorio
 * ENCENDIDO en `fake` (escenarios, estimación, ejecuciones procesadas en
 * línea, cancelación, veredicto rechazado y libro), sus tablas entre las del
 * Studio, y lo único que se encola es el paso del laboratorio en su carril.
 *
 * Hito 5 (contrato H5 §A9, prueba 3): el recorrido son 38 rutas (con el freno
 * echado y las llaves por defecto, publicar y promover son 409 y revertir y
 * Legacy no tienen nada que deshacer), y una segunda prueba recorre de VERDAD
 * publicar, promover, revertir (la CANARY y la PUBLISHED) y volver a Legacy, con
 * las llaves abiertas y el freno suelto, más la vista previa, la evidencia del
 * canario (con su acta) y el historial: solo escriben en `ultron_studio_*`, y
 * la evidencia y la vista previa no escriben nada. En los servicios, los
 * ficheros que llaman al lector del motor (`signatureFor()` y `describe()`) y
 * los que cuentan el canario entran en la lista de los que nombran el motor,
 * con su aserción de solo lectura.
 *
 * Hito 6 (contrato H6 §I9.2): los servicios de las integraciones que LEEN el
 * espejo de Meta, la conversación canaria y su lead, `marketing_calls` y el OTP
 * entran en la lista, cada uno con su aserción de solo lectura; el de campañas
 * lleva además la lista blanca de llamadas a Meta (§I3.3) y, del OTP, solo se
 * llaman `OtpCostGuard::snapshot()` y `TwilioVerifyService::isActive()`. Las 3
 * tablas nuevas son del Studio, y el recorrido pasa por las 56 rutas con las
 * escrituras del hito encendidas y la sonda contra `Http::fake`: al final, las
 * huellas idénticas, nada encolado y ninguna petición salvo los 5 GET de la
 * sonda a los hosts oficiales.
 */
class UltronStudioEngineReadOnlyTest extends TestCase
{
    use IntegrationFixtures;
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /**
     * Los únicos ficheros del Studio que pueden nombrar clases del motor. Hito
     * 5: `StudioPublications` (la firma del motor, pura) y `StudioCanaryMeasures`
     * (los recuentos del canario y su acta, de lectura). Enmienda M-1 (M1-6):
     * `StudioPromptBundle`, que nombra SOLO el compilador de prompts, puro.
     */
    private const ENGINE_READERS = [
        'StudioCampaignLinks.php', 'StudioCanaryCoherence.php', 'StudioCanaryMeasures.php', 'StudioEngineReadings.php', 'StudioIntegrationConfig.php',
        'StudioLabEngineChecks.php', 'StudioMetaCampaigns.php', 'StudioPromptBundle.php', 'StudioPublications.php', 'StudioRules.php', 'StudioTwilioPanel.php',
    ];

    /** Enmienda M-1 (M1-6): lo único del motor que el Studio importa para los prompts. */
    private const PROMPT_COMPILER_CLASSES = [
        'App\\Services\\Marketing\\Ultron\\Prompts\\UltronPromptBase',
        'App\\Services\\Marketing\\Ultron\\Prompts\\UltronPromptCompiler',
        'App\\Services\\Marketing\\Ultron\\Prompts\\UltronPromptException',
    ];

    /**
     * Hito 6: los lectores de las integraciones, con los modelos del Studio en
     * los que SÍ pueden escribir (sus propias tablas). Lo que nombran del motor,
     * solo lo leen.
     */
    private const INTEGRATION_READERS = [
        'StudioCampaignLinks.php' => ['StudioCampaignLink'],
        'StudioCanaryCoherence.php' => [],
        'StudioIntegrationConfig.php' => ['StudioIntegrationSnapshot'],
        'StudioMetaCampaigns.php' => [],
        'StudioTwilioPanel.php' => [],
    ];

    /** La lista blanca de llamadas a clases de Meta en el lector de campañas (§I3.3), más la consulta de lectura de las pasadas. */
    private const META_ALLOWED = ['configurationproblem', 'accountids', 'statusall', 'forconfiguredaccount', 'accountnames', 'opaqueid', 'publicaccount', 'maskid', 'maskdigits', 'query'];

    /** Las que salen a Meta o escriben el espejo (§I3.3): no aparecen en el lector de campañas. */
    private const META_FORBIDDEN = ['campaigns', 'adsets', 'ads', 'insights', 'reach', 'account', 'tokeninfo', 'adowner', 'accounttotals', 'run', 'runall', 'refreshreach', 'refreshstatuses'];

    /** Las clases de Meta y de las integraciones existentes que el Studio lee (no las atrapa el patrón del motor). */
    private const INTEGRATION_CLASSES = ['MetaAdEntity', 'MetaSyncRun', 'MetaAdsSync', 'MetaAdsApiClient', 'LeadSourceClassifier', 'OtpCostGuard', 'TwilioVerifyService',
        'MemberAuthChallenge', 'TrainerAuthChallenge', 'MarketingCall', 'MarketingConversation', 'MarketingLead'];

    /** Lo que es escribir (contrato G §A9): no puede aparecer en esos ficheros sobre el motor. */
    private const WRITE_CALLS = ['set', 'engage', 'release', 'save', 'update', 'delete', 'create', 'forcecreate', 'forcefill', 'fill',
        'insert', 'upsert', 'increment', 'decrement', 'approve', 'reject', 'truncate', 'statement', 'saveQuietly', 'push', 'touch', 'destroy'];

    /** Fuera de los servicios, los únicos ficheros del Studio que pueden nombrar clases del motor. */
    private const ENGINE_NAMERS_OUTSIDE_SERVICES = ['Http/Controllers/Crm/UltronStudio/UltronStudioRuleController.php'];

    /** Los demás directorios del Studio, que se rastrean como los servicios (correcciones del Hito G, I-2). */
    private const OTHER_STUDIO_DIRS = ['Http/Controllers/Crm/UltronStudio', 'Models/UltronStudio', 'Console/Commands/UltronStudio'];

    /** Las tablas del Studio, en las que sus rutas sí escriben. */
    private const STUDIO_TABLES = [
        'ultron_studio_drafts', 'ultron_studio_rules', 'ultron_studio_versions', 'ultron_studio_version_reviews', 'ultron_studio_audit_events',
        // Hito 4: el laboratorio (sin veredictos: en `fake` no se admiten).
        'ultron_studio_lab_scenarios', 'ultron_studio_lab_runs', 'ultron_studio_lab_run_cases', 'ultron_studio_lab_events',
        // Hito 6: las integraciones comerciales.
        'ultron_studio_campaign_links', 'ultron_studio_canary_numbers', 'ultron_studio_integration_snapshots',
    ];

    /** Una sentencia que escribe, con su tabla (con o sin esquema y comillas). */
    private const WRITE_SQL = '/^\s*(?:with\b.*?\)\s*)?(?:insert\s+(?:or\s+\w+\s+)?into|update(?:\s+or\s+\w+)?|delete\s+from|replace\s+into|truncate(?:\s+table)?)\s+(?:only\s+)?((?:"?\w+"?\.)?"?\w+"?)/is';

    /** Una sentencia que cambia el esquema o los permisos: ninguna ruta del Studio la necesita. */
    private const DDL_SQL = '/^\s*(?:create|alter|drop|rename|comment\s+on|grant|revoke)\b/i';

    /** @var list<array{table:string,sql:string}> */
    private array $writes = [];

    /** Fachadas y funciones que el adaptador de lecturas no necesita para nada. */
    private const READER_FORBIDDEN = ['DB', 'Http', 'Queue', 'Bus', 'Artisan', 'Cache', 'Event', 'Mail', 'Notification', 'Storage', 'File',
        'dispatch', 'event', 'cache', 'artisan', 'file_put_contents', 'unlink', 'putenv', 'exec', 'shell_exec'];

    /**
     * Fuera de las tablas del Studio, lo único que cambia con una petición:
     * la última vez que se usó la sesión del personal (`AdminSessionService::touch`).
     * No es del motor.
     */
    private const VOLATILE_COLUMNS = ['admin_sessions' => ['last_seen_at', 'updated_at']];

    // ── 1. Estática ─────────────────────────────────────────────────────────

    public function test_solo_dos_ficheros_del_studio_nombran_el_motor_y_ninguno_lo_escribe(): void
    {
        $nombran = [];
        foreach (glob(app_path('Services/UltronStudio/*.php')) as $ruta) {
            if ($this->engineReferences($ruta) !== []) {
                $nombran[] = basename($ruta);
            }
        }
        sort($nombran);
        $this->assertSame(self::ENGINE_READERS, $nombran, 'Solo el adaptador de lecturas, el de las guardas del laboratorio, las reglas y, desde el Hito 5, la publicación y los recuentos del canario pueden nombrar clases del motor.');

        // El adaptador: ni una llamada de escritura, ni fachadas que escriban, ni `config([...])`.
        $lector = app_path('Services/UltronStudio/StudioEngineReadings.php');
        $this->assertSame([], $this->writeCalls($lector), 'StudioEngineReadings escribe.');
        $this->assertSame([], $this->forbiddenNames($lector), 'StudioEngineReadings usa algo que escribe.');
        $this->assertSame([], $this->configWrites($lector), 'StudioEngineReadings cambia la configuración.');

        // Hito 4 (contrato H4 §A10): el adaptador de las guardas puras del
        // laboratorio, con las mismas restricciones que el de lecturas.
        $guardas = app_path('Services/UltronStudio/StudioLabEngineChecks.php');
        $this->assertSame([], $this->writeCalls($guardas), 'StudioLabEngineChecks escribe.');
        $this->assertSame([], $this->forbiddenNames($guardas), 'StudioLabEngineChecks usa algo que escribe.');
        $this->assertSame([], $this->configWrites($guardas), 'StudioLabEngineChecks cambia la configuración.');

        // Las reglas: escriben SOLO su propio modelo (y toman su cerrojo). Lo que
        // nombra la base de conocimiento, solo lee.
        $reglas = app_path('Services/UltronStudio/StudioRules.php');
        $codigo = (string) file_get_contents($reglas);
        $this->assertStringContainsString('$consulta = StudioRule::query()', $codigo);
        $permitidas = [];
        foreach ($this->writeCalls($reglas) as $llamada) {
            $permitidas[] = match (true) {
                $llamada['method'] === 'forcecreate' && str_contains($llamada['statement'], 'StudioRule::query()->forceCreate(') => 'StudioRule::forceCreate',
                $llamada['method'] === 'update' && str_contains($llamada['statement'], '$consulta->update(') => 'StudioRule::update',
                $llamada['method'] === 'statement' && $llamada['statement'] === "DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');" => 'read_committed',
                $llamada['method'] === 'statement' && str_contains($llamada['statement'], "DB::statement('SELECT pg_advisory_xact_lock(?)'") => 'advisory_lock',
                default => $this->fail("StudioRules escribe algo que no es su propio modelo: {$llamada['statement']}"),
            };
        }
        $this->assertSame(['StudioRule::forceCreate', 'StudioRule::forceCreate', 'StudioRule::update', 'read_committed', 'advisory_lock'], $permitidas);
        foreach ($this->statementsNaming($reglas, 'MarketingKnowledgeItem') as $sentencia) {
            foreach (self::WRITE_CALLS as $metodo) {
                $this->assertDoesNotMatchRegularExpression('/(->|::)'.preg_quote($metodo, '/').'\s*\(/i', $sentencia, "StudioRules escribe la base de conocimiento: {$sentencia}");
            }
        }
        $this->assertSame([], $this->configWrites($reglas));

        // Hito 5 (contrato H5 §A9): la publicación nombra del motor SOLO su
        // lector, y solo para la firma (pura) en dos sitios: al firmar y al
        // verificar una fila. Ninguna sentencia que lo nombre escribe.
        $publicacion = app_path('Services/UltronStudio/StudioPublications.php');
        $this->assertSame(['App\\Services\\Marketing\\Ultron\\UltronPublishedConfig'], $this->engineReferences($publicacion), 'StudioPublications solo nombra del motor su lector.');
        $conElLector = $this->statementsNaming($publicacion, 'UltronPublishedConfig');
        $this->assertCount(2, $conElLector, 'firmar una entrada y verificar una fila');
        foreach ($conElLector as $sentencia) {
            $this->assertStringContainsString('UltronPublishedConfig::signatureFor(', $sentencia, "StudioPublications usa del lector algo que no es la firma: {$sentencia}");
            foreach (self::WRITE_CALLS as $metodo) {
                $this->assertDoesNotMatchRegularExpression('/(->|::)'.preg_quote($metodo, '/').'\s*\(/i', $sentencia, "StudioPublications escribe con el lector del motor: {$sentencia}");
            }
        }
        $this->assertSame([], $this->configWrites($publicacion));

        // Los recuentos del canario: ni una escritura, la única fachada es el
        // acta (`Artisan::call` del comando de lectura), y nada de configuración.
        $recuentos = app_path('Services/UltronStudio/StudioCanaryMeasures.php');
        $this->assertSame([], $this->writeCalls($recuentos), 'StudioCanaryMeasures escribe.');
        $this->assertSame(['Artisan'], $this->forbiddenNames($recuentos), 'StudioCanaryMeasures usa algo que escribe, o más de una llamada a Artisan.');
        $this->assertSame(["\$codigo = Artisan::call(self::ACTA_COMMAND, [ 'conversation' => \$conversacion, '--since' => StudioPublicationDeclaration::utc(\$since), '--turns' => \$turnos, '--json' => true, ], \$salida);"],
            $this->statementsNaming($recuentos, 'Artisan'));
        $this->assertSame('ultron:canary-report', StudioCanaryMeasures::ACTA_COMMAND);
        $this->assertSame([], $this->configWrites($recuentos), 'StudioCanaryMeasures cambia la configuración.');

        // Enmienda M-1 (M1-6): el paquete de prompts nombra del motor SOLO el
        // compilador y su base (puros) y su excepción; ni una escritura, ni una
        // fachada, ni configuración, ni el lector de la cadena.
        $paquete = app_path('Services/UltronStudio/StudioPromptBundle.php');
        $nombrados = array_values(array_unique($this->engineReferences($paquete)));
        sort($nombrados);
        $this->assertSame(self::PROMPT_COMPILER_CLASSES, $nombrados, 'StudioPromptBundle nombra del motor algo que no es el compilador de prompts.');
        $this->assertSame([], $this->writeCalls($paquete), 'StudioPromptBundle escribe.');
        $this->assertSame([], $this->forbiddenNames($paquete), 'StudioPromptBundle usa algo que escribe.');
        $this->assertSame([], $this->configWrites($paquete), 'StudioPromptBundle cambia la configuración.');
        $this->assertStringNotContainsString('config(', (string) file_get_contents($paquete), 'StudioPromptBundle es puro: las llaves le llegan como argumentos.');
    }

    /**
     * Hito 6 (contrato H6 §I9.2): los lectores de las integraciones solo leen
     * lo que nombran del motor y de las integraciones existentes, y solo
     * escriben en sus propias tablas del Studio.
     */
    public function test_los_lectores_de_las_integraciones_solo_leen_el_motor_y_meta_tiene_su_lista_blanca(): void
    {
        foreach (self::INTEGRATION_READERS as $fichero => $propios) {
            $ruta = app_path('Services/UltronStudio/'.$fichero);
            $this->assertSame([], $this->configWrites($ruta), "{$fichero} cambia la configuración.");
            foreach ($this->writeCalls($ruta) as $llamada) {
                $deUnPropio = false;
                foreach ($propios as $modelo) {
                    $deUnPropio = $deUnPropio || str_contains($llamada['statement'], $modelo.'::query()->forceCreate(');
                }
                $this->assertTrue($deUnPropio, "{$fichero} escribe algo que no es su propia tabla del Studio: {$llamada['statement']}");
            }
            $clases = array_values(array_unique(array_map(
                static fn (string $nombre): string => substr((string) strrchr('\\'.$nombre, '\\'), 1),
                $this->engineReferences($ruta),
            )));
            foreach ([...$clases, ...array_values(array_filter(self::INTEGRATION_CLASSES, fn (string $c): bool => $this->names($ruta, $c)))] as $clase) {
                foreach ($this->statementsNaming($ruta, $clase) as $sentencia) {
                    foreach (self::WRITE_CALLS as $metodo) {
                        $this->assertDoesNotMatchRegularExpression('/(->|::)'.preg_quote($metodo, '/').'\s*\(/i', $sentencia, "{$fichero} escribe el motor: {$sentencia}");
                    }
                }
            }
            // Ninguna fachada de red, colas, eventos ni ficheros; la base, solo para su transacción.
            foreach ($this->forbiddenNames($ruta) as $nombre) {
                $this->assertSame('DB', $nombre, "{$fichero} usa {$nombre}.");
            }
            foreach ($this->names($ruta, 'DB') ? $this->statementsNaming($ruta, 'DB') : [] as $sentencia) {
                $this->assertMatchesRegularExpression('/^(\[\$\w+, \$\w+\] = |\$\w+ = )?DB::transaction\(/', $sentencia, "{$fichero} usa la base fuera de su transacción: {$sentencia}");
            }
        }

        // El lector de campañas: solo la lista blanca de Meta (§I3.3).
        $campanas = app_path('Services/UltronStudio/StudioMetaCampaigns.php');
        $llamadas = $this->calledMethodsOn($campanas, ['MetaAdEntity', 'MetaSyncRun', 'LeadSourceClassifier', '$this->client', '$this->sync']);
        $this->assertSame([], array_values(array_diff($llamadas, self::META_ALLOWED)), 'StudioMetaCampaigns llama a Meta fuera de su lista blanca.');
        foreach (self::META_FORBIDDEN as $prohibida) {
            $this->assertDoesNotMatchRegularExpression('/(->|::)'.$prohibida.'\s*\(/i', $this->codeOf($campanas), "StudioMetaCampaigns llama a {$prohibida}().");
        }
        $this->assertContains('statusall', $llamadas, 'la prueba ve las llamadas a Meta');

        // De `marketing_calls`, ni una columna del prospecto (§I5.2): ni en una consulta ni en un DTO.
        $panel = $this->codeOf(app_path('Services/UltronStudio/StudioTwilioPanel.php'));
        foreach (['to_phone', 'from_phone', 'transcript', 'summary', 'metadata', 'marketing_lead_id', 'outcome'] as $columna) {
            $this->assertStringNotContainsString("'{$columna}'", $panel, "StudioTwilioPanel nombra la columna {$columna} de marketing_calls.");
        }
        $this->assertStringContainsString("'status', 'direction', 'reason', 'scheduled_at', 'started_at', 'ended_at', 'duration_seconds', 'created_at'", $panel, 'las columnas que sí lee');

        // Del OTP, solo la foto del día y si Verify está activo: nada que envíe, cuente o gaste.
        foreach (glob(app_path('Services/UltronStudio/*.php')) as $ruta) {
            foreach (['$this->cost' => ['snapshot'], '$this->verify' => ['isactive']] as $objeto => $permitidas) {
                $this->assertSame([], array_values(array_diff($this->calledMethodsOn($ruta, [$objeto]), $permitidas)), basename($ruta)." usa del OTP algo que no es {$permitidas[0]}().");
            }
            foreach (['OtpCostGuard', 'TwilioVerifyService', 'TwilioSmsSender', 'OtpPolicy'] as $clase) {
                $this->assertSame([], array_values(array_diff($this->calledMethodsOn($ruta, [$clase]), [])), basename($ruta)." llama estáticamente a {$clase}.");
            }
        }
    }

    public function test_controladores_modelos_y_comandos_del_studio_no_escriben_el_motor(): void
    {
        $nombran = [];
        foreach (self::OTHER_STUDIO_DIRS as $dir) {
            $ficheros = glob(app_path($dir.'/*.php'));
            $this->assertNotEmpty($ficheros, "No hay nada que rastrear en {$dir}: actualiza esta prueba.");
            foreach ($ficheros as $ruta) {
                $relativa = $dir.'/'.basename($ruta);
                $this->assertSame([], $this->configWrites($ruta), "{$relativa} cambia la configuración.");

                $clases = array_values(array_unique(array_map(
                    static fn (string $nombre): string => substr((string) strrchr('\\'.$nombre, '\\'), 1),
                    $this->engineReferences($ruta),
                )));
                if ($clases === []) {
                    continue;
                }
                $nombran[] = $relativa;
                // Lo que nombra del motor, solo lo lee: ninguna sentencia que lo nombre escribe.
                foreach ($clases as $clase) {
                    foreach ($this->statementsNaming($ruta, $clase) as $sentencia) {
                        foreach (self::WRITE_CALLS as $metodo) {
                            $this->assertDoesNotMatchRegularExpression('/(->|::)'.preg_quote($metodo, '/').'\s*\(/i', $sentencia, "{$relativa} escribe el motor: {$sentencia}");
                        }
                    }
                }
            }
        }
        sort($nombran);
        $this->assertSame(self::ENGINE_NAMERS_OUTSIDE_SERVICES, $nombran, 'Fuera de los servicios, solo el controlador de reglas nombra el motor (la lista de categorías del conocimiento).');
    }

    // ── 2. De comportamiento ────────────────────────────────────────────────

    public function test_recorrer_todas_las_rutas_del_studio_no_cambia_nada_del_motor(): void
    {
        // Las escrituras reales superan el cupo de 30/min, que aquí no es lo que se prueba.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedEngine();
        $this->importBaseline();
        // Hito 4: el laboratorio encendido en `fake` y su catálogo importado.
        $this->labFake();
        $this->importLabScenarios();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        // Las sesiones se abren ANTES de la huella: abrirlas no es del Studio.
        $this->as($ana);
        $this->as($root);

        Queue::fake();
        Http::fake();
        Mail::fake();
        Notification::fake();
        $antes = $this->fingerprint();
        DB::listen(function (QueryExecuted $q): void {
            if (preg_match(self::WRITE_SQL, $q->sql, $m) === 1) {
                $this->writes[] = ['table' => strtolower((string) preg_replace('/^.*\./', '', str_replace('"', '', $m[1]))), 'sql' => $q->sql];
            } elseif (preg_match(self::DDL_SQL, $q->sql) === 1) {
                $this->writes[] = ['table' => '(ddl)', 'sql' => $q->sql];
            }
        });

        $this->walkEveryRoute($ana, $root);

        // Ninguna sentencia de escritura fuera del Studio, deje el valor que deje.
        $ajenas = array_values(array_filter($this->writes, static fn (array $e): bool => ! str_starts_with($e['table'], 'ultron_studio_') && $e['table'] !== 'admin_sessions'));
        $this->assertSame([], $ajenas, 'El Studio mandó escrituras fuera de sus tablas (aunque su efecto se deshaga).');
        // El oído funciona: vio las escrituras del propio Studio.
        foreach (self::STUDIO_TABLES as $tabla) {
            $this->assertContains($tabla, array_column($this->writes, 'table'), "No se vio ninguna escritura en {$tabla}: el recorrido o el oído fallan.");
        }

        $despues = $this->fingerprint();
        foreach ($antes['tables'] as $tabla => $filas) {
            $this->assertSame($filas, $despues['tables'][$tabla], "El Studio cambió la tabla {$tabla}.");
        }
        $this->assertSame(array_keys($antes['tables']), array_keys($despues['tables']), 'El Studio creó o borró tablas.');
        $this->assertSame($antes['config'], $despues['config'], 'El Studio cambió la configuración.');
        // Solo el paso del laboratorio, y solo en su carril (contrato H4 §A10).
        $empujados = Queue::pushedJobs();
        $this->assertSame([StudioLabStepJob::class], array_keys($empujados), 'El Studio encoló algo que no es el paso del laboratorio.');
        foreach ($empujados[StudioLabStepJob::class] as $empujado) {
            $this->assertSame((string) config('queue.lanes.lab.queue'), $empujado['queue'], 'El paso del laboratorio salió de su carril.');
        }
        // La única salida: los 5 GET de la sonda de Twilio, a los hosts oficiales (§I9.2).
        $salidas = array_map(static fn (array $p): string => $p[0]->method().' '.parse_url($p[0]->url(), PHP_URL_HOST), Http::recorded()->all());
        $this->assertSame(['GET api.twilio.com', 'GET api.twilio.com', 'GET api.twilio.com', 'GET api.twilio.com', 'GET monitor.twilio.com'], $salidas);
        Mail::assertNothingSent();
        Notification::assertNothingSent();

        // Y lo que se recorrió son exactamente las rutas del Studio.
        $todas = [];
        foreach (Route::getRoutes() as $route) {
            // Las 44 del Hito 9 (strategies/**, stream, outreach/**) tienen las suyas (L9-38): Strategies/UltronStudioStrategyRoutesTest, Stream/* y Outreach/Guards/*.
            if (str_starts_with($route->uri(), 'api/admin/ultron-studio') && ! str_starts_with($route->uri(), 'api/admin/ultron-studio/strategies') && $route->uri() !== 'api/admin/ultron-studio/stream' && ! str_starts_with($route->uri(), 'api/admin/ultron-studio/outreach/')) {
                $todas[] = AuthorizationMap::routeKey($route);
            }
        }
        sort($todas);
        $recorridas = array_values(array_unique($this->walked));
        sort($recorridas);
        $this->assertSame($todas, $recorridas);
        // Hito 6: las 18 de las integraciones (56 en total); Hito 8: las 21 de la voz (la constante, E2-6).
        $this->assertCount(UltronStudioVoiceController::STUDIO_ROUTE_COUNT, $recorridas);
    }

    /**
     * Hito 5 (contrato H5 §A9, prueba 3): publicar, promover, revertir (la
     * CANARY y la PUBLISHED) y volver a Legacy DE VERDAD, con las llaves
     * abiertas y el freno suelto, escriben solo en `ultron_studio_*`; la vista
     * previa, la evidencia del canario (con su acta) y el historial no escriben
     * nada. El motor «vivo» (pausa puesta, una parada antigua ya soltada,
     * conocimiento, planes, la conversación canaria y sus turnos sellados)
     * queda idéntico; no se encola nada y no sale ninguna petición.
     */
    public function test_publicar_promover_revertir_y_legacy_escriben_solo_en_el_studio(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->importBaseline();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->as($ana);
        $this->as($root);

        // El motor como en producción: encendido, la conversación canaria, el
        // cobro apagado; las dos llaves abiertas; sin ventana; la puerta del
        // laboratorio dispensada (la decide el propietario) y el umbral mínimo.
        MarketingAgentSetting::query()->whereKey(MarketingAgentSwitch::ROW_ID)->update(['paused' => true, 'paused_at' => now(), 'version' => 3]);
        UltronAbort::query()->create(['reason' => UltronAbort::REASON_MANUAL, 'engaged_by' => 'Consola de prueba', 'engaged_at' => now()->subDays(3), 'released_at' => now()->subDays(2), 'released_by' => 'Consola de prueba']);
        MarketingKnowledgeItem::create(['category' => 'tone', 'key' => 'tone.cercano', 'title' => 'Tono', 'content' => 'Cercano y claro.', 'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);
        Plan::create(['name' => 'Mensualidad', 'price' => 98765, 'duration_days' => 30, 'active' => true, 'is_recommended' => true, 'sort_order' => 1]);
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3001234567', 'name' => 'Prospecto']);
        $canario = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
        config([
            'marketing.ultron.enabled' => true,
            'marketing.ultron.payment_links_enabled' => false,
            'marketing.ultron.canary_conversation_id' => $canario->id,
            'marketing.ultron.studio_config' => 'all',
            'ultron_studio.publishing_enabled' => true,
            'ultron_studio.publishing.lab_gate' => 'waived',
            'ultron_studio.publishing.window' => '',
            'ultron_studio.publishing.canary_min_hours' => 1,
            'ultron_studio.publishing.canary_min_turns' => 1,
        ]);

        // Tres versiones aprobadas, de Ana, con la línea base (dice lo que lee el motor).
        $aprobada = function (string $etiqueta) use ($ana, $root): string {
            $uuid = (string) $this->createVersion($ana, $etiqueta)->assertCreated()->json('data.uuid');
            $avisos = $this->studio('POST', "versions/{$uuid}/submit", $ana)->assertOk()->json('data.warnings');
            $this->studio('POST', "versions/{$uuid}/approve", $root, ['acknowledgedWarnings' => $avisos])->assertOk();

            return $uuid;
        };
        $v1 = $aprobada('Primera');
        $v2 = $aprobada('Segunda');
        $v3 = $aprobada('Tercera');

        // Dos turnos del canario sellados con V1, dentro de media hora (después
        // de publicarla): los escribe la prueba ANTES de la huella, como el motor.
        foreach ([1, 2] as $i) {
            MarketingAiAction::create([
                'lead_id' => $lead->id, 'conversation_id' => $canario->id, 'action_type' => 'reply', 'status' => 'executed',
                'source_type' => 'inbound_message', 'source_event_id' => 9000 + $i, 'idempotency_key' => "recorrido-h5-{$i}",
                'metadata' => ['origin' => 'ultron', 'studio' => ['v' => 1, 'env' => 'all', 'state' => 'applied', 'entry' => null, 'seq' => 1, 'part' => 'canary', 'version' => $v1, 'contentHash' => str_repeat('a', 64), 'reason' => null]],
            ])->forceFill(['created_at' => now()->addMinutes(30)])->saveQuietly();
        }

        Queue::fake();
        Http::fake();
        Mail::fake();
        Notification::fake();
        $antes = $this->fingerprint();
        DB::listen(function (QueryExecuted $q): void {
            if (preg_match(self::WRITE_SQL, $q->sql, $m) === 1) {
                $this->writes[] = ['table' => strtolower((string) preg_replace('/^.*\./', '', str_replace('"', '', $m[1]))), 'sql' => $q->sql];
            } elseif (preg_match(self::DDL_SQL, $q->sql) === 1) {
                $this->writes[] = ['table' => '(ddl)', 'sql' => $q->sql];
            }
        });

        $publicar = function (string $uuid) use ($root): void {
            $vista = $this->studio('GET', "versions/{$uuid}/publication-preview", $root)->assertOk()->json('data');
            $this->assertTrue($vista['publishable'], json_encode($vista['blocking']));
            $this->studio('POST', "versions/{$uuid}/publish", $root, ['clientRequestId' => (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $vista['noEffect']])
                ->assertCreated()->assertJsonPath('data.action', 'publish');
        };
        $revertir = fn (string $uuid) => $this->studio('POST', "versions/{$uuid}/rollback", $root, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Recorrido de la publicación'])
            ->assertCreated()->assertJsonPath('data.action', 'rollback');

        // V1 en canario, su evidencia (con el acta) y promovida.
        $publicar($v1);
        $this->travel(2)->hours();
        $evidencia = $this->studio('GET', "versions/{$v1}/canary-evidence", $root)->assertOk()->json('data');
        $this->assertTrue($evidencia['passes'], json_encode($evidencia['failing']));
        $this->assertSame(2, $evidencia['turns']);
        $this->studio('POST', "versions/{$v1}/promote", $root, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => $evidencia['evidenceHash']])
            ->assertCreated()->assertJsonPath('data.action', 'promote');
        // V2 en canario y revertida (la CANARY); V3 en canario y V1 revertida (la PUBLISHED, con V3 todavía en canario).
        $publicar($v2);
        $revertir($v2);
        $publicar($v3);
        $revertir($v1);
        // Y vuelta a Legacy.
        $this->studio('POST', 'publications/legacy', $root, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Fin del recorrido'])
            ->assertCreated()->assertJsonPath('data.action', 'legacy');
        foreach (['publications', "versions/{$v1}", 'status', 'engine/readings', 'audit'] as $lectura) {
            $this->studio('GET', $lectura, $root)->assertOk();
        }

        $ajenas = array_values(array_filter($this->writes, static fn (array $e): bool => ! str_starts_with($e['table'], 'ultron_studio_') && $e['table'] !== 'admin_sessions'));
        $this->assertSame([], $ajenas, 'La publicación mandó escrituras fuera de las tablas del Studio (aunque su efecto se deshaga).');
        foreach (['ultron_studio_publications', 'ultron_studio_versions', 'ultron_studio_audit_events'] as $tabla) {
            $this->assertContains($tabla, array_column($this->writes, 'table'), "No se vio ninguna escritura en {$tabla}: el recorrido o el oído fallan.");
        }
        $this->assertSame(7, DB::table('ultron_studio_publications')->count(), 'tres publicaciones, una promoción, dos reversiones y Legacy');

        $despues = $this->fingerprint();
        foreach ($antes['tables'] as $tabla => $filas) {
            $this->assertSame($filas, $despues['tables'][$tabla], "La publicación cambió la tabla {$tabla}.");
        }
        $this->assertSame(array_keys($antes['tables']), array_keys($despues['tables']));
        $this->assertSame($antes['config'], $despues['config'], 'La publicación cambió la configuración.');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    /**
     * Hito 5 (contrato H5 §A9, prueba 3): la vista previa y la evidencia del
     * canario NO escriben nada, en ninguna tabla (tampoco en las del Studio:
     * no se auditan), aunque la evidencia ejecute el acta.
     */
    public function test_la_vista_previa_y_la_evidencia_no_escriben_nada(): void
    {
        $this->importBaseline();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3001234567', 'name' => 'Prospecto']);
        $canario = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
        config([
            'marketing.ultron.enabled' => true,
            'marketing.ultron.payment_links_enabled' => false,
            'marketing.ultron.canary_conversation_id' => $canario->id,
            'marketing.ultron.studio_config' => 'canary',
            'ultron_studio.publishing_enabled' => true,
            'ultron_studio.publishing.lab_gate' => 'waived',
            'ultron_studio.publishing.window' => '',
        ]);
        $uuid = (string) $this->createVersion($ana)->assertCreated()->json('data.uuid');
        $avisos = $this->studio('POST', "versions/{$uuid}/submit", $ana)->assertOk()->json('data.warnings');
        $this->studio('POST', "versions/{$uuid}/approve", $root, ['acknowledgedWarnings' => $avisos])->assertOk();
        $vista = $this->studio('GET', "versions/{$uuid}/publication-preview", $root)->assertOk()->json('data');
        $this->studio('POST', "versions/{$uuid}/publish", $root, ['clientRequestId' => (string) Str::uuid(), 'previewHash' => $vista['previewHash'], 'acknowledgedNoEffect' => $vista['noEffect']])->assertCreated();
        $this->as($root);

        DB::listen(function (QueryExecuted $q): void {
            if (preg_match(self::WRITE_SQL, $q->sql, $m) === 1) {
                $this->writes[] = ['table' => strtolower((string) preg_replace('/^.*\./', '', str_replace('"', '', $m[1]))), 'sql' => $q->sql];
            }
        });
        $this->studio('GET', "versions/{$uuid}/publication-preview", $root)->assertOk();
        $this->studio('GET', "versions/{$uuid}/canary-evidence", $root)->assertOk();

        $this->assertSame([], array_values(array_filter($this->writes, static fn (array $e): bool => $e['table'] !== 'admin_sessions')), 'La vista previa o la evidencia escribieron algo.');
    }

    /** @var list<string> */
    private array $walked = [];

    /** Una petición del recorrido, apuntando qué ruta atendió. */
    private function walk(string $method, string $path, Admin $as, array $body = []): TestResponse
    {
        $r = $this->studio($method, $path, $as, $body);
        $this->assertLessThan(500, $r->status(), "{$method} {$path}: ".$r->getContent());
        $route = app('router')->getRoutes()->match(Request::create('/api/admin/ultron-studio/'.ltrim(strtok($path, '?'), '/'), $method));
        $this->walked[] = AuthorizationMap::routeKey($route);

        return $r;
    }

    private function walkEveryRoute(Admin $ana, Admin $root): void
    {
        foreach (['status', 'catalog', 'draft', 'engine/readings', 'rules', 'versions', 'audit', 'compare?from=baseline&to=draft'] as $lectura) {
            $this->walk('GET', $lectura, $ana)->assertOk();
        }

        // Cada parámetro distinto de la línea base (el canal no puede: tiene una
        // sola opción), aristas opcionales puestas y una obligatoria quitada. Se
        // guarda con bloqueos: el borrador los admite.
        $aristas = array_values(array_diff([...$this->baseline()['definition']['edgeIds'], 'E14', 'E16'], ['E10']));
        $todoDistinto = $this->contentWith([
            'knowledge' => ['sources' => ['Solo planes vendibles'], 'strictFacts' => false],
            'persona' => ['tone' => 'entusiasta', 'emojis' => false, 'maxChars' => 480],
            'intent' => ['driver' => 'hermes', 'fallback' => 'humano'],
            'context' => ['windowTurns' => 30],
            'memory' => ['useLeadProfile' => false],
            'strategy' => ['goal' => 'venta', 'offerPriority' => ['Trimestral', 'Mensualidad'], 'discountsAllowed' => true],
            'rules' => ['handoffOnlyCorroborated' => false, 'paymentLinksEnabled' => true],
            'composer' => ['critic' => false, 'retry' => false],
            'integrations' => ['appointments' => false],
            'governance' => ['enabled' => false, 'canaryOnly' => false, 'autoBrake' => false],
        ], $aristas);
        $this->walk('PUT', 'draft', $ana, ['baseRevision' => 0, 'content' => $todoDistinto])->assertOk()->assertJsonPath('data.validation.publishable', false);
        $iguales = [];
        foreach ($this->baseline()['configs'] as $capa => $valores) {
            foreach ($valores as $clave => $valor) {
                if (json_encode($valor) === json_encode($todoDistinto['configs'][$capa][$clave])) {
                    $iguales[] = "{$capa}.{$clave}";
                }
            }
        }
        $this->assertSame(['integrations.channel'], $iguales, 'cada parámetro distinto de la línea base, salvo el que solo tiene una opción');

        // Lo mismo sin bloqueos, para poder crear versiones.
        $publicable = $this->contentWith([
            'knowledge' => ['sources' => ['Solo planes vendibles']],
            'persona' => ['tone' => 'entusiasta', 'emojis' => false, 'maxChars' => 480],
            'intent' => ['driver' => 'openai', 'fallback' => 'humano'],
            'context' => ['windowTurns' => 30],
            'memory' => ['useLeadProfile' => false],
            'strategy' => ['goal' => 'venta', 'offerPriority' => ['Trimestral', 'Mensualidad']],
            'rules' => ['paymentLinksEnabled' => true],
            'composer' => ['retry' => false],
            'integrations' => ['appointments' => false],
            'governance' => ['enabled' => false, 'autoBrake' => false],
        ], [...$this->baseline()['definition']['edgeIds'], 'E14', 'E16']);
        $borrador = $this->walk('PUT', 'draft', $ana, ['baseRevision' => 1, 'content' => $publicable])->assertOk()->assertJsonPath('data.validation.publishable', true)->json('data');

        // Reglas: con condiciones, editada, una que choca (409) y otra contra un control (422); una documental archivada.
        $s001 = $this->walk('POST', 'rules', $ana, ['description' => 'Mensajes cortos.', 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'],
            'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 300)]), 'clientRequestId' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->walk('PATCH', "rules/{$s001['uuid']}", $ana, ['baseRevision' => 1, 'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'lte', 280)])])->assertOk();
        $this->walk('POST', 'rules', $ana, ['description' => 'Choca.', 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'],
            'conditions' => self::conditionsOf([self::limitOn('persona', 'maxChars', 'gte', 400)]), 'clientRequestId' => (string) Str::uuid()])->assertStatus(409);
        $this->walk('POST', 'rules', $ana, ['description' => 'Pide descuentos.', 'type' => 'permission', 'priority' => 'high', 'layers' => ['strategy'],
            'conditions' => self::conditionsOf([self::requireEq('strategy', 'discountsAllowed', true)]), 'clientRequestId' => (string) Str::uuid()])->assertStatus(422);
        $s002 = $this->walk('POST', 'rules', $ana, ['description' => 'Documental.', 'type' => 'condition', 'priority' => 'normal', 'layers' => ['strategy'],
            'clientRequestId' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->walk('POST', "rules/{$s002['uuid']}/archive", $ana, ['baseRevision' => 1, 'reason' => 'Prueba de aislamiento'])->assertOk();

        // Versiones: una aprobada por otra persona, otra rechazada; publicar y revertir, 409.
        $nueva = fn (string $etiqueta) => (string) $this->walk('POST', 'versions', $ana, ['label' => $etiqueta, 'note' => null, 'draftRevision' => $borrador['revision'],
            'clientRequestId' => (string) Str::uuid()])->assertCreated()->json('data.uuid');
        $v1 = $nueva('Aprobada');
        $this->walk('GET', "versions/{$v1}", $root)->assertOk();
        $this->walk('POST', "versions/{$v1}/validate", $root)->assertOk();
        $avisos = $this->walk('POST', "versions/{$v1}/submit", $ana)->assertOk()->json('data.warnings');
        $this->assertContains('regla-S-001', $avisos);
        $this->walk('POST', "versions/{$v1}/approve", $root, ['acknowledgedWarnings' => $avisos, 'note' => 'Aprobada en la prueba'])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $v2 = $nueva('Rechazada');
        $this->walk('POST', "versions/{$v2}/submit", $ana)->assertOk();
        $this->walk('POST', "versions/{$v2}/reject", $root, ['reason' => 'Rechazada en la prueba'])->assertOk();
        // Hito 5: con las llaves por defecto, publicar y promover son 409;
        // revertir y Legacy no miran llaves, pero no hay nada que deshacer. La
        // vista previa, la evidencia (de una versión que no está en canario) y
        // el historial solo leen.
        $this->walk('GET', "versions/{$v1}/publication-preview", $root)->assertOk()->assertJsonPath('data.publishable', false);
        $this->walk('GET', "versions/{$v1}/canary-evidence", $root)->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->walk('POST', "versions/{$v1}/publish", $root, ['clientRequestId' => (string) Str::uuid(), 'previewHash' => str_repeat('0', 64), 'acknowledgedNoEffect' => []])
            ->assertStatus(409)->assertJsonPath('code', 'publishing_disabled');
        $this->walk('POST', "versions/{$v1}/promote", $root, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => str_repeat('0', 64)])
            ->assertStatus(409)->assertJsonPath('code', 'publishing_disabled');
        $this->walk('POST', "versions/{$v1}/rollback", $root, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Recorrido del motor'])
            ->assertStatus(409)->assertJsonPath('code', 'not_active_publication');
        $this->walk('POST', 'publications/legacy', $root, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Recorrido del motor'])
            ->assertStatus(409)->assertJsonPath('code', 'already_legacy');
        $this->walk('GET', 'publications', $root)->assertOk();

        // Lecturas y comparaciones con todo eso dentro.
        foreach (["compare?from={$v1}&to={$v2}", "compare?from=baseline&to={$v1}", "compare?from={$v1}&to=draft", 'engine/readings', 'audit', 'rules', 'versions', 'status'] as $lectura) {
            $this->walk('GET', $lectura, $root)->assertOk();
        }

        // Hito 4: el laboratorio en `fake`. Un escenario propio, editado; una
        // ejecución sobre la versión aprobada, procesada EN LÍNEA (el runner
        // y las guardas del motor sobre el texto); otra cancelada en cola; un
        // veredicto que el ensayo sin IA no admite; el archivo y las lecturas.
        $this->walk('GET', 'lab/status', $ana)->assertOk()->assertJsonPath('data.mode', 'fake');
        $this->walk('GET', 'lab/scenarios', $ana)->assertOk();
        $propio = $this->walk('POST', 'lab/scenarios', $ana, $this->scenarioBody())->assertCreated()->json('data');
        $this->walk('PATCH', "lab/scenarios/{$propio['uuid']}", $ana, ['baseRevision' => 1, 'title' => 'Editado en el recorrido'])->assertOk();
        $cuerpo = ['target' => ['kind' => 'version', 'uuid' => $v1], 'scenarioUuids' => [$this->scenarioUuid('C-02'), $this->scenarioUuid('C-04'), $propio['uuid']]];
        $estimacion = $this->walk('POST', 'lab/estimate', $root, $cuerpo)->assertOk()->json('data');
        $ejecucion = $this->walk('POST', 'lab/runs', $root, $cuerpo + ['acceptedMaxCostMicros' => $estimacion['estimate']['maxMicros'], 'clientRequestId' => (string) Str::uuid()])
            ->assertCreated()->json('data');
        $this->assertSame('completed', $this->drain($ejecucion['uuid'])->status);
        $otra = $this->walk('POST', 'lab/runs', $root, ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01')], 'acceptedMaxCostMicros' => 0, 'clientRequestId' => (string) Str::uuid()])
            ->assertCreated()->json('data');
        $this->walk('POST', "lab/runs/{$otra['uuid']}/cancel", $root)->assertOk()->assertJsonPath('data.status', 'cancelled');
        $huella = $this->walk('GET', "lab/runs/{$ejecucion['uuid']}", $ana)->assertOk()->json('data.resultsHash');
        $this->walk('POST', "lab/runs/{$ejecucion['uuid']}/verdict", $root, ['decision' => 'needs_changes', 'note' => 'Recorrido del motor', 'resultsHash' => $huella])
            ->assertStatus(409)->assertJsonPath('code', 'run_not_reviewable');
        $this->walk('POST', "lab/scenarios/{$propio['uuid']}/archive", $ana, ['reason' => 'Fin del recorrido'])->assertOk();
        foreach (['lab/runs', 'lab/events', 'lab/status'] as $lectura) {
            $this->walk('GET', $lectura, $root)->assertOk();
        }

        $this->walk('POST', 'draft/discard', $ana, ['baseRevision' => $borrador['revision']])->assertOk();

        $this->walkIntegrations($ana, $root, $v1);
        $this->walkVoice($ana, $root, $v1);
    }

    /**
     * Hito 8 (contrato H8, «API»): las 15 rutas del gobierno de la voz y las
     * 6 del laboratorio hablado, con las banderas por defecto (publicar y el
     * laboratorio apagados): un perfil se crea, se envía, se aprueba; otro se
     * rechaza; se previsualiza la compilación; publicar y promover son 409;
     * revertir y apagar no tienen nada que hacer; el freno no existe. Nada sale
     * a la red y nada escribe fuera de `ultron_studio_*`.
     */
    private function walkVoice(Admin $ana, Admin $root, string $version): void
    {
        $contenido = [
            'voiceId' => 'pNInz6obpgDQGcFmaJgB',
            'greetings' => ['inbound' => '¿En qué te puedo ayudar hoy?', 'outbound' => 'Te llamo porque pediste información de Iron Body.'],
            'holes' => ['presentation' => 'Te ayudo con planes, horarios y la sede.', 'closing' => 'Gracias por tu tiempo.'],
            'maxDurationSeconds' => 300,
            'tools' => ['consultar_planes', 'consultar_horarios', 'no_volver_a_llamar'],
            'systemTools' => ['end_call'],
            'transfer' => ['destinations' => []],
            'privacy' => ['retentionDays' => 30],
            'voicemail' => 'hang_up',
        ];
        $perfil = $this->walk('POST', 'voice/profiles', $ana, ['clientRequestId' => (string) Str::uuid(), 'label' => 'Voz del recorrido', 'content' => $contenido])
            ->assertCreated()->json('data.uuid');
        $this->walk('POST', "voice/profiles/{$perfil}/submit", $ana)->assertOk();
        $this->walk('POST', "voice/profiles/{$perfil}/approve", $root)->assertOk();
        $otro = $this->walk('POST', 'voice/profiles', $ana, ['clientRequestId' => (string) Str::uuid(), 'label' => 'Voz rechazada', 'content' => ['maxDurationSeconds' => 240] + $contenido])
            ->assertCreated()->json('data.uuid');
        $this->walk('POST', "voice/profiles/{$otro}/submit", $ana)->assertOk();
        $this->walk('POST', "voice/profiles/{$otro}/reject", $root, ['note' => 'Rechazado en el recorrido'])->assertOk();
        // La versión del recorrido cambia parámetros que la voz no compila: 200 o el 422 del compilador, sin red.
        $this->walk('POST', 'voice/compile-preview', $ana, ['versionUuid' => $version, 'profileUuid' => $perfil]);
        $this->walk('POST', 'voice/publish', $root, ['clientRequestId' => (string) Str::uuid(), 'versionUuid' => $version, 'profileUuid' => $perfil, 'expectedSpecHash' => str_repeat('0', 64)])
            ->assertStatus(409)->assertJsonPath('code', 'voice_publishing_disabled');
        $this->walk('POST', 'voice/promote', $root, ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => str_repeat('0', 64), 'expectedSpecHash' => str_repeat('0', 64)])
            ->assertStatus(409)->assertJsonPath('code', 'voice_publishing_disabled');
        $this->walk('POST', 'voice/rollback', $root, ['clientRequestId' => (string) Str::uuid(), 'scope' => 'canary', 'reason' => 'Recorrido del motor'])
            ->assertStatus(409)->assertJsonPath('code', 'nothing_to_rollback');
        $this->walk('POST', 'voice/off', $root, ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Recorrido del motor'])
            ->assertStatus(409)->assertJsonPath('code', 'voice_already_off');
        $this->walk('POST', 'voice/brakes/'.Str::uuid().'/release', $root, ['clientRequestId' => (string) Str::uuid(), 'note' => 'Recorrido del motor'])->assertNotFound();
        foreach (['voice/profiles', "voice/profiles/{$perfil}", 'voice/publications', 'voice/canary-evidence', 'voice/brakes'] as $lectura) {
            $this->walk('GET', $lectura, $root)->assertOk();
        }

        // El laboratorio hablado (LAB8), apagado: el estado responde; lo demás, 4xx sin red.
        $this->walk('GET', 'voice-lab/status', $ana)->assertOk();
        $sesion = (string) Str::uuid();
        foreach ([['GET', 'voice-lab/sessions', []], ['GET', "voice-lab/sessions/{$sesion}", []],
            ['POST', 'voice-lab/sessions', ['versionUuid' => $version, 'profileUuid' => $perfil, 'acceptedMaxCostMicros' => 0, 'clientRequestId' => (string) Str::uuid()]],
            ['POST', "voice-lab/sessions/{$sesion}/end", []], ['POST', "voice-lab/sessions/{$sesion}/verdict", ['decision' => 'approved', 'specHash' => str_repeat('0', 64)]]] as [$metodo, $ruta, $cuerpo]) {
            $this->assertGreaterThanOrEqual(400, $this->walk($metodo, $ruta, $root, $cuerpo)->status(), "{$metodo} {$ruta} con el laboratorio hablado apagado");
        }
    }

    /**
     * Hito 6: las 18 rutas de las integraciones con las escrituras encendidas y
     * la sonda contra `Http::fake` (sus 5 GET son la única salida permitida).
     * Ana registra y Root autoriza y activa (cuatro ojos); una asociación de
     * campaña se crea, se sustituye por la versión aprobada y se desactiva; la
     * configuración se reconoce.
     */
    private function walkIntegrations(Admin $ana, Admin $root, string $version): void
    {
        foreach (['integrations/status', 'integrations/meta/campaigns', 'integrations/meta/links', 'integrations/canaries', 'integrations/twilio',
            'integrations/twilio/calls', 'integrations/voice', 'integrations/config'] as $lectura) {
            $this->walk('GET', $lectura, $ana)->assertOk();
        }

        $campana = self::campaignId('120000000000000387');
        $linea = $this->walk('POST', 'integrations/meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => $campana, 'target' => ['kind' => 'baseline']])
            ->assertCreated()->json('data.link.uuid');
        $nueva = $this->walk('POST', 'integrations/meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => $campana,
            'target' => ['kind' => 'version', 'versionUuid' => $version], 'replaces' => $linea])->assertCreated()->json('data.link.uuid');
        $this->walk('POST', "integrations/meta/links/{$nueva}/deactivate", $ana, ['reason' => 'Fin del recorrido'])->assertOk();

        $numero = $this->walk('POST', 'integrations/canaries', $ana, ['clientRequestId' => (string) Str::uuid(), 'channel' => 'whatsapp', 'phone' => '+57 300 123 4567',
            'alias' => 'Móvil del recorrido', 'consentAttested' => true, 'expectedVersionUuid' => $version])->assertCreated()->json('data.number.uuid');
        $this->walk('POST', "integrations/canaries/{$numero}/authorize", $root)->assertOk();
        $this->walk('POST', "integrations/canaries/{$numero}/activate", $root)->assertOk();
        $this->walk('POST', "integrations/canaries/{$numero}/expectation", $ana, ['versionUuid' => null])->assertOk();
        $this->walk('POST', "integrations/canaries/{$numero}/deactivate", $ana, ['reason' => 'Fin del recorrido'])->assertOk();
        $this->walk('POST', "integrations/canaries/{$numero}/revoke", $ana, ['reason' => 'Fin del recorrido'])->assertOk();

        $huella = $this->walk('GET', 'integrations/config', $root)->assertOk()->json('data.providers.1.effectiveFingerprint');
        $this->walk('POST', 'integrations/config/acknowledge', $root, ['clientRequestId' => (string) Str::uuid(), 'provider' => 'twilio', 'fingerprint' => $huella])->assertCreated();
        $this->walk('POST', 'integrations/twilio/probe', $root, ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        foreach (['integrations/status', 'integrations/canaries?status=all', 'integrations/meta/campaigns?linked=no', 'integrations/twilio', 'integrations/config'] as $lectura) {
            $this->walk('GET', $lectura, $root)->assertOk();
        }
    }

    /** La pausa puesta, el freno echado, conocimiento, planes y una conversación: el motor «vivo». */
    private function seedEngine(): void
    {
        config([
            'marketing.ultron.enabled' => true,
            'marketing.ultron.payment_links_enabled' => false,
            'marketing.ultron.canary_conversation_id' => 987654321,
            'marketing.ultron.payment_canary_conversation_id' => 555444333,
            'marketing.ultron.webhook_url' => 'https://n8n.ejemplo.test/webhook/ultron',
        ]);
        MarketingAgentSetting::query()->whereKey(MarketingAgentSwitch::ROW_ID)->update(['paused' => true, 'paused_at' => now(), 'version' => 3]);
        UltronAbort::query()->create(['reason' => UltronAbort::REASON_MANUAL, 'engaged_by' => 'Consola de prueba', 'engaged_at' => now()]);
        MarketingKnowledgeItem::create(['category' => 'tone', 'key' => 'tone.cercano', 'title' => 'Tono', 'content' => 'Cercano y claro.', 'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);
        MarketingKnowledgeItem::create(['category' => 'faq', 'key' => 'faq.propuesta', 'title' => 'Propuesta', 'content' => 'Pendiente.', 'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_INTERNAL_API]);
        Plan::create(['name' => 'Mensualidad', 'price' => 98765, 'duration_days' => 30, 'active' => true, 'is_recommended' => true, 'sort_order' => 1]);
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3001234567', 'name' => 'Prospecto']);
        $conversacion = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open']);

        // Hito 6: lo que leen las integraciones. La conversación canaria con su
        // lead (la coherencia lee su teléfono), el espejo de Meta con sus
        // pasadas, retos del OTP, llamadas y la configuración de Twilio y de la
        // sonda; las escrituras del hito encendidas.
        config(['marketing.ultron.canary_conversation_id' => $conversacion->id]);
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], '120000000000000387', 'Pauta del recorrido');
        DB::table('trainer_auth_challenges')->insert(['uuid' => (string) Str::uuid(), 'purpose' => 'trainer_login', 'code_hash' => 'x', 'channel' => 'sms',
            'destination' => '+573009998877', 'status' => 'verified', 'expires_at' => now(), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
        DB::table('marketing_calls')->insert(['provider' => 'twilio', 'to_phone' => '+573009998866', 'status' => 'pending', 'direction' => 'outbound',
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        config([
            'otp.driver' => 'twilio',
            'otp.twilio.sid' => 'AC0123456789abcdef0123456789abcdef',
            'otp.twilio.token' => 'token-del-recorrido-de-solo-lectura',
            'otp.twilio.verify_service_sid' => 'VA'.str_repeat('0', 28).'9f0e',
            'ultron_studio.integrations.writes_enabled' => true,
            'ultron_studio.integrations.twilio_probe.enabled' => true,
            'ultron_studio.integrations.twilio_probe.base' => null,
        ]);
    }

    /**
     * La huella de todo lo que no es del Studio: cada tabla (filas ordenadas,
     * sin las columnas volátiles de la sesión) y la configuración entera.
     *
     * @return array{tables:array<string,list<string>>,config:string}
     */
    private function fingerprint(): array
    {
        $tablas = [];
        foreach (Schema::getTables() as $t) {
            $nombre = (string) $t['name'];
            if (str_starts_with($nombre, 'ultron_studio_') || ($t['schema'] ?? null) === 'information_schema' || ($t['schema'] ?? null) === 'pg_catalog') {
                continue;
            }
            $filas = DB::table($nombre)->get()->map(function (object $fila) use ($nombre): string {
                $columnas = (array) $fila;
                foreach (self::VOLATILE_COLUMNS[$nombre] ?? [] as $volatil) {
                    unset($columnas[$volatil]);
                }

                return (string) json_encode($columnas);
            })->all();
            sort($filas);
            $tablas[$nombre] = $filas;
        }
        ksort($tablas);
        $this->assertArrayHasKey('marketing_agent_settings', $tablas);
        $this->assertArrayHasKey('ultron_aborts', $tablas);
        $this->assertArrayHasKey('marketing_knowledge_items', $tablas);
        $this->assertArrayHasKey('plans', $tablas);

        return ['tables' => $tablas, 'config' => (string) json_encode(config()->all())];
    }

    // ── Lectura de tokens ───────────────────────────────────────────────────

    /**
     * Qué clases del motor nombra un fichero en su CÓDIGO (no en comentarios):
     * nombres cualificados (los `use` incluidos) y nombres de clase dentro de
     * cadenas.
     *
     * @return list<string>
     */
    private function engineReferences(string $ruta): array
    {
        $patron = '/^\\\\?App\\\\(Services\\\\Marketing\\\\|Models\\\\Marketing|Models\\\\Plan$|Models\\\\UltronAbort)|SalesAiConfig|UltronAbort/';
        $out = [];
        foreach ($this->tokens($ruta) as $t) {
            $texto = $t[1];
            if (in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $texto = str_replace('\\\\', '\\', trim($texto, '\'"'));
                if (! str_starts_with(ltrim($texto, '\\'), 'App\\')) {
                    continue;
                }
            } elseif (! in_array($t[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if (preg_match($patron, $texto) === 1) {
                $out[] = $texto;
            }
        }

        return $out;
    }

    /**
     * Las llamadas a métodos de escritura (`->x(`, `?->x(` o `::x(`), con la
     * sentencia en la que van.
     *
     * @return list<array{method:string,statement:string}>
     */
    private function writeCalls(string $ruta): array
    {
        $tokens = $this->tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if (! in_array($t[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            $j = $this->nextCode($tokens, $i);
            $nombre = $tokens[$j] ?? null;
            $parentesis = $tokens[$this->nextCode($tokens, $j)] ?? null;
            if ($nombre === null || $nombre[0] !== T_STRING || $parentesis === null || $parentesis[1] !== '(') {
                continue;
            }
            if (in_array(strtolower($nombre[1]), array_map('strtolower', self::WRITE_CALLS), true)) {
                $out[] = ['method' => strtolower($nombre[1]), 'statement' => $this->statementAround($tokens, $i)];
            }
        }

        return $out;
    }

    /**
     * Fachadas y funciones que el adaptador no puede usar.
     *
     * @return list<string>
     */
    private function forbiddenNames(string $ruta): array
    {
        $tokens = $this->tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($t[0] !== T_STRING && $t[0] !== T_NAME_QUALIFIED && $t[0] !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            $corto = substr((string) strrchr('\\'.$t[1], '\\'), 1);
            $antes = $tokens[$this->previousCode($tokens, $i)] ?? null;
            $despues = $tokens[$this->nextCode($tokens, $i)] ?? null;
            // Un método (`->x`, `::x`) o una declaración no cuentan: solo el uso como fachada o función.
            if ($antes !== null && in_array($antes[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                continue;
            }
            if (in_array($corto, self::READER_FORBIDDEN, true) && $despues !== null && in_array($despues[1], ['::', '('], true)) {
                $out[] = $t[1];
            }
        }

        return $out;
    }

    /**
     * `config([...])` o `config()->set(`: cambiar la configuración.
     *
     * @return list<string>
     */
    private function configWrites(string $ruta): array
    {
        $tokens = $this->tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            $abre = $this->nextCode($tokens, $i);
            if ($t[0] === T_STRING && strtolower($t[1]) === 'config' && ($tokens[$abre][1] ?? null) === '(') {
                // Con un array se escribe; sin argumentos, el repositorio (para `->set()`).
                $siguiente = $tokens[$this->nextCode($tokens, $abre)][1] ?? null;
                if ($siguiente === '[' || $siguiente === ')') {
                    $out[] = $this->statementAround($tokens, $i);
                }
            }
        }

        return $out;
    }

    /**
     * Las sentencias que nombran `$nombre`.
     *
     * @return list<string>
     */
    private function statementsNaming(string $ruta, string $nombre): array
    {
        $tokens = $this->tokens($ruta);
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($t[0] === T_STRING && $t[1] === $nombre) {
                $out[] = $this->statementAround($tokens, $i);
            }
        }
        $this->assertNotSame([], $out, "{$nombre} ya no aparece en ".basename($ruta).': actualiza esta prueba.');

        return array_values(array_unique($out));
    }

    /**
     * El texto de la sentencia que contiene el token `$i`: desde el `;`, `{` o
     * `}` anterior hasta el `;` siguiente (sin comentarios ni espacios de más).
     *
     * @param  list<array{0:int,1:string,2:int}>  $tokens
     */
    private function statementAround(array $tokens, int $i): string
    {
        $inicio = $i;
        while ($inicio > 0 && ! in_array($tokens[$inicio - 1][1], [';', '{', '}'], true)) {
            $inicio--;
        }
        $fin = $i;
        while ($fin < count($tokens) - 1 && $tokens[$fin][1] !== ';') {
            $fin++;
        }
        $texto = '';
        for ($k = $inicio; $k <= $fin; $k++) {
            $texto .= $tokens[$k][0] === T_WHITESPACE ? ' ' : $tokens[$k][1];
        }

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }

    /**
     * Los tokens de CÓDIGO de un fichero (sin comentarios; los espacios se
     * quedan para poder escribir cada sentencia), todos como
     * `[id, texto, línea]`; los de un carácter llevan id 0.
     *
     * @return list<array{0:int,1:string,2:int}>
     */
    private function tokens(string $ruta): array
    {
        $out = [];
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            $t = is_array($t) ? $t : [0, $t, 0];
            if (! in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * El siguiente token que no es un espacio (`-> update (` cuenta igual que `->update(`).
     *
     * @param  list<array{0:int,1:string,2:int}>  $tokens
     */
    private function nextCode(array $tokens, int $i): int
    {
        do {
            $i++;
        } while (isset($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE);

        return $i;
    }

    /** @param  list<array{0:int,1:string,2:int}>  $tokens */
    private function previousCode(array $tokens, int $i): int
    {
        do {
            $i--;
        } while ($i >= 0 && $tokens[$i][0] === T_WHITESPACE);

        return $i;
    }

    // ── Hito 6: lectura de tokens de los lectores de las integraciones ──────

    /** ¿Nombra el código de un fichero (sin comentarios) este identificador? */
    private function names(string $ruta, string $nombre): bool
    {
        foreach ($this->tokens($ruta) as $t) {
            if (($t[0] === T_STRING || $t[0] === T_NAME_QUALIFIED || $t[0] === T_NAME_FULLY_QUALIFIED) && substr((string) strrchr('\\'.$t[1], '\\'), 1) === $nombre) {
                return true;
            }
        }

        return false;
    }

    /** El código de un fichero sin comentarios. */
    private function codeOf(string $ruta): string
    {
        return implode('', array_map(static fn (array $t): string => $t[1], $this->tokens($ruta)));
    }

    /**
     * Los métodos que se llaman directamente sobre unos receptores: `Clase::x(`
     * o `$this->propiedad->x(`, en minúscula.
     *
     * @param  list<string>  $receivers  nombres cortos de clase o `$this->propiedad`
     * @return list<string>
     */
    private function calledMethodsOn(string $ruta, array $receivers): array
    {
        $tokens = array_values(array_filter($this->tokens($ruta), static fn (array $t): bool => $t[0] !== T_WHITESPACE));
        $out = [];
        foreach ($tokens as $i => $t) {
            foreach ($receivers as $receptor) {
                if (str_starts_with($receptor, '$this->')) {
                    $propiedad = substr($receptor, 7);
                    $casa = $t[0] === T_VARIABLE && $t[1] === '$this' && ($tokens[$i + 1][1] ?? null) === '->' && ($tokens[$i + 2][1] ?? null) === $propiedad
                        && ($tokens[$i + 3][1] ?? null) === '->' && ($tokens[$i + 5][1] ?? null) === '(';
                    if ($casa) {
                        $out[] = strtolower($tokens[$i + 4][1]);
                    }

                    continue;
                }
                $casa = ($t[0] === T_STRING || $t[0] === T_NAME_QUALIFIED || $t[0] === T_NAME_FULLY_QUALIFIED) && substr((string) strrchr('\\'.$t[1], '\\'), 1) === $receptor
                    && ($tokens[$i + 1][1] ?? null) === '::' && ($tokens[$i + 2][0] ?? null) === T_STRING && ($tokens[$i + 3][1] ?? null) === '(';
                if ($casa) {
                    $out[] = strtolower($tokens[$i + 2][1]);
                }
            }
        }

        return array_values(array_unique($out));
    }
}
