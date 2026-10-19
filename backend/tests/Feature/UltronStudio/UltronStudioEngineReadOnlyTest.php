<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\MarketingAgentSetting;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\Plan;
use App\Models\UltronAbort;
use App\Services\Marketing\MarketingAgentSwitch;
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
 */
class UltronStudioEngineReadOnlyTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** Los únicos ficheros del Studio que pueden nombrar clases del motor. */
    private const ENGINE_READERS = ['StudioEngineReadings.php', 'StudioLabEngineChecks.php', 'StudioRules.php'];

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
        $this->assertSame(self::ENGINE_READERS, $nombran, 'Solo el adaptador de lecturas, el de las guardas del laboratorio y las reglas pueden nombrar clases del motor.');

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
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();

        // Y lo que se recorrió son exactamente las rutas del Studio.
        $todas = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/admin/ultron-studio')) {
                $todas[] = AuthorizationMap::routeKey($route);
            }
        }
        sort($todas);
        $recorridas = array_values(array_unique($this->walked));
        sort($recorridas);
        $this->assertSame($todas, $recorridas);
        $this->assertCount(33, $recorridas);
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
        $this->walk('POST', "versions/{$v1}/publish", $root)->assertStatus(409);
        $this->walk('POST', "versions/{$v1}/rollback", $root)->assertStatus(409);

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
        MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
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
}
