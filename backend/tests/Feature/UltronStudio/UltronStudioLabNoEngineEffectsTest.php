<?php

namespace Tests\Feature\UltronStudio;

use App\Console\Commands\CheckSecretExposure;
use App\Models\Admin;
use App\Models\MarketingAgentSetting;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\Plan;
use App\Models\UltronAbort;
use App\Services\Marketing\MarketingAgentSwitch;
use App\Services\Marketing\SalesAiConfig;
use App\Services\UltronStudio\StudioLabPrompts;
use App\Services\UltronStudio\StudioLabStepJob;
use App\Support\Access\AuthorizationMap;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
 * EL LABORATORIO NO TOCA EL MOTOR (contrato H4 §A10,
 * `UltronStudioLabNoEngineEffectsTest`), y sus secretos no salen.
 *
 * Con datos del motor sembrados (pausa, freno, conocimiento, planes, una
 * conversación con su mensaje), se recorre el laboratorio de verdad: un
 * escenario, estimaciones, una ejecución `fake` y otra `openai` con el
 * CLIENTE REAL contra `Http::fake` (los pasos los hace el job por la cola
 * síncrona de la suite), una cancelación, un veredicto y el libro. Y:
 *
 *  - `DB::listen` falla ante CUALQUIER escritura fuera de `ultron_studio_lab_*`,
 *    `admin_sessions`, `jobs` y la caché del latido (aunque su efecto se
 *    deshaga), y ante cualquier DDL;
 *  - la huella de las tablas del motor (`marketing_*`, `ultron_aborts`, planes…
 *    y `ultron_studio_versions`) y `config('marketing')` no cambian;
 *  - HTTP solo a `https://api.openai.com/v1/responses`, con la clave DEL
 *    LABORATORIO (nunca la del motor), `store:false`, sin `user`,
 *    `safety_identifier` ni `metadata`, y sin nombres, correos, ids ni datos
 *    del motor en lo que viaja;
 *  - ni correo ni notificaciones;
 *  - la clave centinela no aparece en ninguna respuesta, en el libro, en las
 *    filas ni en el log, ni siquiera cuando el proveedor la devuelve en un 401.
 *
 * Además, por tokens: los `StudioLab*` no nombran la configuración ni la clave
 * del motor; NADA APRUEBA SOLO (el runner, el job y los comandos no nombran el
 * veredicto, solo su servicio lo inserta y la tabla solo la nombran el modelo
 * y ese servicio); y el contrato de las 21 rutas de F/G sigue igual.
 */
class UltronStudioLabNoEngineEffectsTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const OPENAI_URL = 'https://api.openai.com/v1/responses';

    /**
     * El precio del plan sembrado (98 765) como viaja en la respuesta del
     * servidor («$98.765») o sin separador, y nunca como parte de un número
     * más largo ni como decimales (los microsegundos de una hora: «.987654»).
     */
    private const PRICE_AS_SENT = '/(?<![\d.])98\.?765(?!\d)/';

    /** @var list<array{table:string,sql:string}> */
    private array $writes = [];

    /** @var list<TestResponse> */
    private array $responses = [];

    /** @var list<HttpRequest> */
    private array $sent = [];

    /** @var list<string> */
    private array $logs = [];

    public function test_recorrer_el_laboratorio_no_cambia_nada_del_motor_y_no_suelta_la_clave(): void
    {
        $this->seedEngine();
        $this->importBaseline();
        $this->importLabScenarios();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Laboratorio');
        $bea = $this->person(Admin::ROLE_ADMINISTRADOR, 'Bea Revisora');
        // Las personas y sus sesiones, ANTES de la huella: no son del laboratorio.
        $this->as($ana);
        $this->as($root);
        $this->as($bea);
        $version = (string) $this->createVersion($ana, 'Versión con etiqueta secreta')->assertCreated()->json('data.uuid');

        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        Http::fake([self::OPENAI_URL => fn (HttpRequest $r) => $this->answer($r)]);
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->logs[] = $m->message.' '.json_encode($m->context);
        });
        $antes = $this->fingerprint();
        DB::listen(function (QueryExecuted $q): void {
            if (preg_match('/^\s*(?:insert\s+(?:or\s+\w+\s+)?into|update(?:\s+or\s+\w+)?|delete\s+from|replace\s+into|truncate(?:\s+table)?)\s+(?:only\s+)?((?:"?\w+"?\.)?"?\w+"?)/is', $q->sql, $m) === 1) {
                $this->writes[] = ['table' => strtolower((string) preg_replace('/^.*\./', '', str_replace('"', '', $m[1]))), 'sql' => $q->sql];
            } elseif (preg_match('/^\s*(?:create|alter|drop|rename|comment\s+on|grant|revoke)\b/i', $q->sql) === 1) {
                $this->writes[] = ['table' => '(ddl)', 'sql' => $q->sql];
            }
        });

        // Ensayo sin IA, con un escenario del personal.
        $this->labFake();
        $propio = $this->keep($this->lab('POST', 'scenarios', $ana, $this->scenarioBody()))->assertCreated()->json('data.uuid');
        $fake = $this->keep($this->launch($root, self::versionTarget($version), ['C-01', 'C-04']))->assertCreated()->json('data');
        $this->assertSame('completed', $fake['status']);

        // OpenAI con el cliente real (la cola síncrona hace los pasos con el job).
        $this->labOpenAi();
        $this->keep($this->lab('GET', 'status', $root))->assertOk();
        $real = $this->keep($this->launch($root, self::versionTarget($version), ['C-02', 'C-04']))->assertCreated()->json('data');
        $this->assertSame(['completed', 'openai', 'gpt-lab-pruebas'], [$real['status'], $real['mode'], $real['model']]);
        $this->assertSame('fail', $real['cases'][0]['outcome'], 'el precio del actor lo marca el filtro del motor');
        $real = $this->keep($this->lab('POST', "runs/{$real['uuid']}/verdict", $ana, [
            'decision' => 'needs_changes', 'note' => 'Hay que quitar el precio', 'resultsHash' => $real['resultsHash'],
        ]))->assertStatus(403)->json();
        $realUuid = (string) DB::table('ultron_studio_lab_runs')->where('mode', 'openai')->value('uuid');
        $hash = (string) DB::table('ultron_studio_lab_runs')->where('uuid', $realUuid)->value('results_hash');
        $this->keep($this->lab('POST', "runs/{$realUuid}/verdict", $bea, ['decision' => 'needs_changes', 'note' => 'Hay que quitar el precio', 'resultsHash' => $hash]))->assertOk();

        // El proveedor rechaza la clave y la repite en su cuerpo: no sale de aquí.
        $this->rejectKey = true;
        $parada = $this->keep($this->launch($root, ['kind' => 'baseline'], ['C-05', 'C-06']))->assertCreated()->json('data');
        $this->assertSame(['stopped', 'provider_auth'], [$parada['status'], $parada['stopReason']]);
        $this->rejectKey = false;

        // Una cancelación en cola, el libro y las listas.
        $this->labFake();
        Queue::fake();
        $enCola = $this->keep($this->launch($root, ['kind' => 'baseline'], ['C-07']))->assertCreated()->json('data.uuid');
        $this->keep($this->lab('POST', "runs/{$enCola}/cancel", $root))->assertOk();
        foreach (['runs', "runs/{$realUuid}", 'events', 'scenarios', 'status'] as $lectura) {
            $this->keep($this->lab('GET', $lectura, $root))->assertOk();
        }
        $this->keep($this->lab('POST', "scenarios/{$propio}/archive", $ana, ['reason' => 'Fin de la prueba']))->assertOk();

        // 1) Ninguna escritura fuera del laboratorio (la sesión, la cola y la caché del latido aparte).
        $ajenas = array_values(array_filter($this->writes, static fn (array $e): bool => ! str_starts_with($e['table'], 'ultron_studio_lab_')
            && ! in_array($e['table'], ['admin_sessions', 'jobs', 'cache', 'cache_locks'], true)));
        $this->assertSame([], $ajenas, 'El laboratorio escribió fuera de sus tablas.');
        foreach (['ultron_studio_lab_scenarios', 'ultron_studio_lab_runs', 'ultron_studio_lab_run_cases', 'ultron_studio_lab_verdicts', 'ultron_studio_lab_events'] as $tabla) {
            $this->assertContains($tabla, array_column($this->writes, 'table'), "No se vio ninguna escritura en {$tabla}: el recorrido o el oído fallan.");
        }

        // 2) La huella del motor y su configuración, idénticas.
        $despues = $this->fingerprint();
        $this->assertSame(array_keys($antes['tables']), array_keys($despues['tables']));
        foreach ($antes['tables'] as $tabla => $filas) {
            $this->assertSame($filas, $despues['tables'][$tabla], "El laboratorio cambió {$tabla}.");
        }
        $this->assertSame($antes['marketing'], $despues['marketing'], 'El laboratorio cambió config(marketing).');

        // 3) HTTP: solo la Responses API, con la clave del laboratorio y sin nada que no deba viajar.
        $this->assertNotEmpty($this->sent);
        foreach ($this->sent as $peticion) {
            $this->assertSame(self::OPENAI_URL, $peticion->url());
            $this->assertSame('Bearer '.self::SENTINEL_KEY, $peticion->header('Authorization')[0] ?? null);
            $cuerpo = $peticion->data();
            $this->assertFalse($cuerpo['store']);
            $this->assertSame('gpt-lab-pruebas', $cuerpo['model']);
            $this->assertTrue($cuerpo['text']['format']['strict']);
            foreach (['user', 'safety_identifier', 'metadata'] as $prohibida) {
                $this->assertArrayNotHasKey($prohibida, $cuerpo);
            }
            $crudo = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
            foreach (['Ana Pérez', 'Root Laboratorio', 'Bea Revisora', '@ironbody.test', 'Versión con etiqueta secreta', $version, self::ENGINE_KEY,
                '98765', 'tone.cercano', 'Cercano y claro', '3001234567', 'Prospecto'] as $nunca) {
                $this->assertStringNotContainsString($nunca, (string) $crudo, "Viajó al proveedor: {$nunca}");
            }
        }
        Http::assertSentCount(count($this->sent));
        Mail::assertNothingSent();
        Notification::assertNothingSent();

        // 4) La clave centinela no está en ninguna respuesta, fila, evento ni línea de log.
        foreach ($this->responses as $r) {
            $this->assertStringNotContainsString(self::SENTINEL_KEY, (string) $r->getContent());
            $this->assertStringNotContainsString('CENTINELA', (string) $r->getContent());
        }
        foreach (['ultron_studio_lab_scenarios', 'ultron_studio_lab_runs', 'ultron_studio_lab_run_cases', 'ultron_studio_lab_verdicts', 'ultron_studio_lab_events'] as $tabla) {
            $this->assertStringNotContainsString('CENTINELA', (string) json_encode(DB::table($tabla)->get()), "La clave quedó en {$tabla}.");
        }
        $this->assertNotEmpty($this->logs, 'el oído del log no oyó nada');
        foreach ($this->logs as $linea) {
            $this->assertStringNotContainsString('CENTINELA', $linea);
            $this->assertStringNotContainsString('Incorrect API key', $linea, 'el cuerpo del proveedor llegó al log');
        }
    }

    /**
     * T8 DEL APARTADO 5: EL RECORRIDO DEL MOTOR TAMPOCO TOCA EL MOTOR. Con los
     * mismos datos del motor sembrados y su clasificador configurado en
     * `openai` con la clave del MOTOR (si el laboratorio lo usara, saldría a la
     * red con esa clave y esto caería), una ejecución en modo motor con el
     * CLIENTE REAL contra `Http::fake`, de punta a punta: no escribe fuera de
     * `ultron_studio_lab_*`, no crea conversación, mensaje, acción de IA ni
     * evento comercial (la huella del motor no cambia), no encola nada más que
     * su paso, no sale a la red salvo la Responses API con la clave del
     * laboratorio, y no envía la persona ni la conversación reales.
     *
     * EL PRECIO (NB1 de la revisión de seguridad; lo acepta el propietario):
     * para una intención de precio, la respuesta determinista del servidor,
     * con el precio del catálogo, viaja al estratega dentro de
     * `base_decision`, IGUAL que en el motor real (paridad). Fuera de ahí, no.
     * Se busca como viaja («$98.765», con el separador de miles) y también sin
     * él, sin dígitos ni punto delante ni dígitos detrás: los microsegundos de
     * la hora (`business_time`) y un decimal no dan un falso positivo.
     */
    public function test_el_recorrido_del_motor_no_cambia_nada_del_motor_y_no_suelta_la_clave(): void
    {
        $this->seedEngine();
        $this->importBaseline();
        $this->importLabScenarios();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root Laboratorio');
        $this->as($ana);
        $this->as($root);
        $version = (string) $this->createVersion($ana, 'Versión con etiqueta secreta')->assertCreated()->json('data.uuid');
        $this->labOpenAi(['ultron_studio.lab.openai.model' => 'gpt-4.1-mini', 'ultron_studio.lab.run_budget_usd' => '2']);
        // El cerebro del motor, como en producción con proveedor: el laboratorio no puede usarlo.
        config(['marketing.ai.enabled' => true, 'marketing.ai.driver' => 'openai', 'marketing.ai.openai.enabled' => true, 'marketing.ai.openai.model' => 'gpt-motor-pruebas']);
        $this->assertSame('openai', SalesAiConfig::effectiveDriver(), 'el cerebro del motor está listo para salir a la red');

        Mail::fake();
        Notification::fake();
        Queue::fake();
        Http::preventStrayRequests();
        // Toda petición se registra: el responder del motor se degrada solo si falla, y una petición perdida no se vería.
        Http::fake([self::OPENAI_URL => fn (HttpRequest $r) => $this->answerEngine($r), '*' => function (HttpRequest $r) {
            $this->sent[] = $r;

            return Http::response(['error' => 'fuera del laboratorio'], 500);
        }]);
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->logs[] = $m->message.' '.json_encode($m->context);
        });
        $antes = $this->fingerprint();
        DB::listen(function (QueryExecuted $q): void {
            if (preg_match('/^\s*(?:insert\s+(?:or\s+\w+\s+)?into|update(?:\s+or\s+\w+)?|delete\s+from|replace\s+into|truncate(?:\s+table)?)\s+(?:only\s+)?((?:"?\w+"?\.)?"?\w+"?)/is', $q->sql, $m) === 1) {
                $this->writes[] = ['table' => strtolower((string) preg_replace('/^.*\./', '', str_replace('"', '', $m[1]))), 'sql' => $q->sql];
            } elseif (preg_match('/^\s*(?:create|alter|drop|rename|comment\s+on|grant|revoke)\b/i', $q->sql) === 1) {
                $this->writes[] = ['table' => '(ddl)', 'sql' => $q->sql];
            }
        });

        $uuids = [$this->scenarioUuid('C-01'), $this->scenarioUuid('C-02')];
        $cuerpo = ['target' => self::versionTarget($version), 'scenarioUuids' => $uuids, 'fidelity' => 'engine'];
        $estimacion = $this->keep($this->lab('POST', 'estimate', $root, $cuerpo))->assertOk()->json('data');
        $this->assertTrue($estimacion['allowed']);
        $uuid = (string) $this->keep($this->lab('POST', 'runs', $root, $cuerpo + ['acceptedMaxCostMicros' => $estimacion['estimate']['maxMicros'], 'clientRequestId' => (string) Str::uuid()]))
            ->assertCreated()->json('data.uuid');
        $run = $this->drain($uuid);
        $detalle = $this->keep($this->lab('GET', "runs/{$uuid}", $root))->assertOk()->json('data');
        $this->assertSame(['completed', 'engine', 2], [$run->status, $detalle['fidelityMode'], count($detalle['cases'])]);
        foreach ($detalle['cases'] as $caso) {
            $this->assertSame('done', $caso['status']);
            $this->assertSame('strategist', $caso['engine']['path'][0]);
        }

        // 1) Ninguna escritura fuera del laboratorio (la sesión, la cola y la caché aparte).
        $ajenas = array_values(array_filter($this->writes, static fn (array $e): bool => ! str_starts_with($e['table'], 'ultron_studio_lab_')
            && ! in_array($e['table'], ['admin_sessions', 'jobs', 'cache', 'cache_locks'], true)));
        $this->assertSame([], $ajenas, 'El recorrido del motor escribió fuera de las tablas del laboratorio.');
        $this->assertContains('ultron_studio_lab_run_cases', array_column($this->writes, 'table'), 'el oído no vio la liquidación');

        // 2) La huella del motor (conversaciones, mensajes, acciones de IA, eventos, planes…) y su configuración, idénticas.
        $despues = $this->fingerprint();
        foreach (['marketing_conversations', 'marketing_messages', 'marketing_ai_actions', 'marketing_automation_events', 'marketing_leads'] as $clave) {
            $this->assertArrayHasKey($clave, $despues['tables']);
        }
        foreach ($antes['tables'] as $tabla => $filas) {
            $this->assertSame($filas, $despues['tables'][$tabla], "El recorrido del motor cambió {$tabla}.");
        }
        $this->assertSame($antes['marketing'], $despues['marketing']);

        // 3) Nada encolado salvo su propio paso; ni correo ni notificaciones.
        $this->assertSame([StudioLabStepJob::class], array_values(array_unique(array_keys(Queue::pushedJobs()))));
        Mail::assertNothingSent();
        Notification::assertNothingSent();

        // 4) HTTP: solo la Responses API, con la clave del laboratorio y la forma del nodo de n8n.
        $delMotor = array_values(array_filter($this->sent, static fn (HttpRequest $p): bool => str_starts_with((string) ($p->data()['text']['format']['name'] ?? ''), 'ultron_') && ! str_starts_with((string) $p->data()['text']['format']['name'], 'ultron_lab_')));
        $this->assertGreaterThanOrEqual(6, count($delMotor), 'dos casos, al menos estratega, redactor y revisor en cada uno');
        $conPrecio = [];
        foreach ($this->sent as $peticion) {
            $this->assertSame(self::OPENAI_URL, $peticion->url());
            $this->assertSame('Bearer '.self::SENTINEL_KEY, $peticion->header('Authorization')[0] ?? null);
            $c = $peticion->data();
            $this->assertSame(['gpt-4.1-mini', false], [$c['model'], $c['store']]);
            foreach (['user', 'safety_identifier', 'metadata'] as $prohibida) {
                $this->assertArrayNotHasKey($prohibida, $c);
            }
            $crudo = (string) json_encode($c, JSON_UNESCAPED_UNICODE);
            // Ni la persona, ni la conversación reales, ni el personal, ni la etiqueta, ni la clave del motor.
            foreach (['3001234567', 'Prospecto', 'Hola, quiero información', 'Ana Pérez', 'Root Laboratorio', '@ironbody.test', 'Versión con etiqueta secreta', $version, self::ENGINE_KEY] as $nunca) {
                $this->assertStringNotContainsString($nunca, $crudo, "Viajó al proveedor: {$nunca}");
            }
            // El precio, solo en la `base_decision` del estratega (NB1, ver arriba): se quita y se mira el resto. Se
            // cuenta por petición: el doble de `Http::fake` pasa dos veces por cada una (el mismo objeto).
            $segundo = json_decode((string) ($c['input'][1]['content'][0]['text'] ?? 'null'), true);
            if (is_array($segundo) && array_key_exists('base_decision', $segundo)) {
                $conPrecio[spl_object_id($peticion)] = preg_match(self::PRICE_AS_SENT, (string) json_encode($segundo['base_decision'], JSON_UNESCAPED_UNICODE));
                unset($segundo['base_decision']);
                $c['input'][1]['content'][0]['text'] = json_encode($segundo, JSON_UNESCAPED_UNICODE);
            }
            $this->assertDoesNotMatchRegularExpression(self::PRICE_AS_SENT, (string) json_encode($c, JSON_UNESCAPED_UNICODE), 'El precio viajó fuera de base_decision.');
        }
        $this->assertSame(1, array_sum($conPrecio), 'el precio viaja, formateado, en la base_decision del estratega de C-02 (intención de precio), y solo ahí');
        foreach ($delMotor as $peticion) {
            $c = $peticion->data();
            $this->assertSame(['user', 'user'], array_column($c['input'], 'role'));
            $this->assertSame('input_text', $c['input'][1]['content'][0]['type']);
            $this->assertArrayNotHasKey('instructions', $c);
            $this->assertArrayHasKey('temperature', $c);
        }
        // El contexto de NEGOCIO sí viaja (enmienda 9 de H4): los planes (en `plans`, sin precio) y la base de conocimiento.
        $todo = (string) json_encode(array_map(static fn (HttpRequest $p): array => $p->data(), $delMotor), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Mensualidad', $todo);
        $this->assertStringContainsString('Cercano y claro', $todo);

        // 5) La clave centinela no está en ninguna respuesta, fila ni línea de log.
        foreach ($this->responses as $r) {
            $this->assertStringNotContainsString('CENTINELA', (string) $r->getContent());
        }
        foreach (['ultron_studio_lab_runs', 'ultron_studio_lab_run_cases', 'ultron_studio_lab_events'] as $tabla) {
            $this->assertStringNotContainsString('CENTINELA', (string) json_encode(DB::table($tabla)->get()), "La clave quedó en {$tabla}.");
        }
        foreach ($this->logs as $linea) {
            $this->assertStringNotContainsString('CENTINELA', $linea);
        }
    }

    public function test_los_ficheros_del_laboratorio_no_nombran_la_configuracion_ni_la_clave_del_motor(): void
    {
        $ficheros = glob(app_path('Services/UltronStudio/StudioLab*.php'));
        $this->assertGreaterThanOrEqual(17, count($ficheros));
        foreach ($ficheros as $ruta) {
            $todo = (string) file_get_contents($ruta);
            foreach (['services.openai', 'marketing.ai', 'OPENAI_API_KEY', 'SalesAiConfig'] as $ajeno) {
                $this->assertStringNotContainsStringIgnoringCase($ajeno, $todo, basename($ruta)." nombra {$ajeno}");
            }
        }
    }

    public function test_el_detector_de_secretos_expuestos_conoce_la_clave_del_laboratorio(): void
    {
        $comando = app(CheckSecretExposure::class);
        // B-T17 (L9-82) cambió el detector booleano por la lista de NOMBRES de las claves con valor de verdad: la
        // aserción es ahora más estricta (que sea ESTA clave la detectada), no más laxa.
        $detecta = new \ReflectionMethod($comando, 'realSecretKeys');
        $fichero = tempnam(sys_get_temp_dir(), 'lab-env');
        try {
            file_put_contents($fichero, "APP_NAME=Iron\nULTRON_STUDIO_LAB_OPENAI_API_KEY=".self::SENTINEL_KEY."\n");
            $this->assertContains('ULTRON_STUDIO_LAB_OPENAI_API_KEY', $detecta->invoke($comando, $fichero), 'una clave del laboratorio de verdad es un secreto');
            file_put_contents($fichero, "APP_NAME=Iron\nULTRON_STUDIO_LAB_OPENAI_API_KEY=\n");
            $this->assertSame([], $detecta->invoke($comando, $fichero), 'vacía es un ejemplo');
        } finally {
            @unlink($fichero);
        }
    }

    public function test_nada_aprueba_solo(): void
    {
        // Quién nombra el veredicto, en el código (no en comentarios).
        $nombran = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php' && $this->namesVerdict($f->getPathname())) {
                $nombran[] = substr($f->getPathname(), strlen(app_path()) + 1);
            }
        }
        sort($nombran);
        $this->assertSame([
            'Http/Controllers/Crm/UltronStudio/UltronStudioLabController.php',
            'Models/UltronStudio/StudioLabRun.php',
            'Models/UltronStudio/StudioLabVerdict.php',
            'Services/UltronStudio/StudioLabVerdicts.php',
        ], $nombran, 'Solo el modelo, la relación, el servicio del veredicto y su ruta nombran el veredicto.');

        // Ni el runner, ni el job, ni ningún comando del Studio.
        foreach (['Services/UltronStudio/StudioLabRunner.php', 'Services/UltronStudio/StudioLabStepJob.php', ...array_map(
            static fn (string $p): string => 'Console/Commands/UltronStudio/'.basename($p), glob(app_path('Console/Commands/UltronStudio/*.php')))] as $relativa) {
            $this->assertFalse($this->namesVerdict(app_path($relativa)), "{$relativa} nombra el veredicto.");
        }

        // El controlador lo nombra solo por sus constantes; inserta UNO: el servicio.
        $controlador = (string) file_get_contents(app_path('Http/Controllers/Crm/UltronStudio/UltronStudioLabController.php'));
        $this->assertSame(0, preg_match('/StudioLabVerdict::(query|create|forceCreate|insert)\b/', $controlador));
        $servicio = (string) file_get_contents(app_path('Services/UltronStudio/StudioLabVerdicts.php'));
        $this->assertSame(1, preg_match_all('/StudioLabVerdict::query\(\)->forceCreate\(/', $servicio));

        // Ni por la TABLA (H-P2-2 del tester): `DB::table('ultron_studio_lab_verdicts')`,
        // un `insert`/`insertGetId` o un SQL con ese nombre no nombran el modelo.
        // La tabla solo la nombran su modelo y el servicio del veredicto, en todo
        // app/ y routes/ (comandos de consola incluidos), en el código y no en
        // los comentarios. Un nombre armado a trozos o con una variable no se ve
        // aquí: para eso, lo de abajo.
        $nombranLaTabla = [];
        foreach ([app_path(), base_path('routes')] as $raiz) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
                if ($f->getExtension() === 'php' && $this->namesVerdictTable($f->getPathname())) {
                    $nombranLaTabla[] = substr($f->getPathname(), strlen(base_path()) + 1);
                }
            }
        }
        sort($nombranLaTabla);
        $this->assertContains('app/Models/UltronStudio/StudioLabVerdict.php', $nombranLaTabla, 'el escáner no ve la tabla ni en su modelo: no vigila nada');
        $this->assertSame([], array_values(array_diff($nombranLaTabla, ['app/Models/UltronStudio/StudioLabVerdict.php', 'app/Services/UltronStudio/StudioLabVerdicts.php'])),
            'Solo el modelo y el servicio del veredicto nombran la tabla de los veredictos.');

        // Y el runner, el job y los comandos del Studio no tienen NADA del
        // veredicto en su código: ni la tabla a trozos, ni la relación
        // (`$run->verdict()->create(…)`), ni el modelo.
        foreach (['Services/UltronStudio/StudioLabRunner.php', 'Services/UltronStudio/StudioLabStepJob.php', ...array_map(
            static fn (string $p): string => 'Console/Commands/UltronStudio/'.basename($p), glob(app_path('Console/Commands/UltronStudio/*.php')))] as $relativa) {
            $this->assertSame([], $this->verdictTokens(app_path($relativa)), "{$relativa} tiene algo del veredicto en su código.");
        }
    }

    public function test_el_contrato_de_las_21_rutas_de_f_y_g_no_cambia(): void
    {
        $fg = [
            'GET api/admin/ultron-studio/status' => 'ultron.view',
            'GET api/admin/ultron-studio/catalog' => 'ultron.view',
            'GET api/admin/ultron-studio/draft' => 'ultron.view',
            'PUT api/admin/ultron-studio/draft' => 'ultron.edit_draft',
            'POST api/admin/ultron-studio/draft/discard' => 'ultron.edit_draft',
            'GET api/admin/ultron-studio/versions' => 'ultron.view',
            'POST api/admin/ultron-studio/versions' => 'ultron.edit_draft',
            'GET api/admin/ultron-studio/versions/{uuid}' => 'ultron.view',
            'POST api/admin/ultron-studio/versions/{uuid}/validate' => 'ultron.view',
            'POST api/admin/ultron-studio/versions/{uuid}/submit' => 'ultron.edit_draft',
            'POST api/admin/ultron-studio/versions/{uuid}/approve' => 'ultron.approve',
            'POST api/admin/ultron-studio/versions/{uuid}/reject' => 'ultron.approve',
            'POST api/admin/ultron-studio/versions/{uuid}/publish' => 'ultron.publish',
            'POST api/admin/ultron-studio/versions/{uuid}/rollback' => 'ultron.rollback',
            'GET api/admin/ultron-studio/rules' => 'ultron.view',
            'POST api/admin/ultron-studio/rules' => 'ultron.manage_rules',
            'PATCH api/admin/ultron-studio/rules/{uuid}' => 'ultron.manage_rules',
            'POST api/admin/ultron-studio/rules/{uuid}/archive' => 'ultron.manage_rules',
            'GET api/admin/ultron-studio/audit' => 'ultron.view',
            'GET api/admin/ultron-studio/engine/readings' => 'ultron.view',
            'GET api/admin/ultron-studio/compare' => 'ultron.view',
        ];
        // Las cinco del Hito 5 (contrato H5 §A7) son de la publicación, no del
        // laboratorio: van aparte, y las 21 de F y G siguen idénticas.
        $h5 = [
            'GET api/admin/ultron-studio/versions/{uuid}/publication-preview' => 'ultron.view',
            'GET api/admin/ultron-studio/versions/{uuid}/canary-evidence' => 'ultron.view',
            'POST api/admin/ultron-studio/versions/{uuid}/promote' => 'ultron.publish',
            'GET api/admin/ultron-studio/publications' => 'ultron.view',
            'POST api/admin/ultron-studio/publications/legacy' => 'ultron.rollback',
        ];
        $real = [];
        $deH5 = [];
        foreach (Route::getRoutes() as $route) {
            // Las 18 de las integraciones (Hito 6) tienen su propia matriz: UltronStudioIntegrationsPermissionsTest.
            // Las 21 de la voz (Hito 8), también: UltronStudioVoiceRoutesTest (y abrir una sesión del laboratorio
            // hablado lleva a propósito el limitador del laboratorio de texto).
            if (str_starts_with($route->uri(), 'api/admin/ultron-studio') && ! str_starts_with($route->uri(), 'api/admin/ultron-studio/lab/')
                && ! str_starts_with($route->uri(), 'api/admin/ultron-studio/integrations/')
                && ! str_starts_with($route->uri(), 'api/admin/ultron-studio/voice/') && ! str_starts_with($route->uri(), 'api/admin/ultron-studio/voice-lab/')
                // Las 44 del Hito 9 (strategies/**, stream, outreach/**) tienen las suyas (L9-38): Strategies/UltronStudioStrategyRoutesTest, Stream/* y Outreach/Guards/*.
                && ! str_starts_with($route->uri(), 'api/admin/ultron-studio/strategies') && $route->uri() !== 'api/admin/ultron-studio/stream' && ! str_starts_with($route->uri(), 'api/admin/ultron-studio/outreach/')) {
                $clave = AuthorizationMap::routeKey($route);
                if (array_key_exists($clave, $h5)) {
                    $deH5[$clave] = AuthorizationMap::resolve($route);
                } else {
                    $real[$clave] = AuthorizationMap::resolve($route);
                }
                $this->assertStringNotContainsString('ultron-studio-lab-run', implode(',', $route->gatherMiddleware()), $route->uri());
            }
        }
        ksort($real);
        ksort($fg);
        $this->assertSame($fg, $real);
        ksort($deH5);
        ksort($h5);
        $this->assertSame($h5, $deH5);

        // Las respuestas de F no ganan claves del laboratorio.
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $estado = $this->studio('GET', 'status', $ana)->assertOk()->json('data');
        $this->assertSame(['engine', 'publishing', 'can', 'catalog', 'lifecycle', 'legacyBaseline'], array_keys($estado));
        $this->assertSame(['view', 'editDraft', 'manageRules', 'approve', 'publish', 'rollback'], array_keys($estado['can']));
        $version = $this->createVersion($ana)->assertCreated()->json('data');
        // Hito 5: `promote`, junto a publicar y revertir.
        $this->assertSame(['submit', 'approve', 'reject', 'publish', 'promote', 'rollback'], array_keys($version['actions']));
    }

    // ────────────────────────────────────────────────────────────────────────

    private bool $rejectKey = false;

    /** El simulador de la Responses API: el actor pone un precio en C-02; el juez, `pass` a todo. */
    private function answer(HttpRequest $r): mixed
    {
        $this->sent[] = $r;
        if ($this->rejectKey) {
            return Http::response(['error' => ['message' => 'Incorrect API key provided: '.self::SENTINEL_KEY, 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], 401);
        }
        $cuerpo = $r->data();
        $entrada = (string) ($cuerpo['input'][0]['content'] ?? '');
        if (($cuerpo['text']['format']['name'] ?? null) === StudioLabPrompts::schemaName(StudioLabPrompts::ROLE_ACTOR)) {
            $salida = ['reply' => str_contains($entrada, 'mensualidad') ? 'La mensualidad cuesta 80.000 pesos' : 'Hola, ¿en qué te puedo ayudar?'];
        } else {
            $objetivos = StudioLabPrompts::judgeTargets($entrada);
            $salida = [
                'criteria' => array_map(static fn (string $id): array => ['id' => $id, 'verdict' => 'pass', 'evidence' => '', 'reason' => 'Simulador.'], $objetivos['criteria']),
                'rules' => array_map(static fn (string $c): array => ['code' => $c, 'verdict' => 'not_applicable', 'reason' => ''], $objetivos['rules']),
                'risks' => [],
                'summary' => 'Simulador del proveedor.',
            ];
        }

        return Http::response([
            'id' => 'resp_'.Str::random(8), 'object' => 'response', 'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
                ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode($salida, JSON_UNESCAPED_UNICODE)]]],
            ],
            'usage' => ['input_tokens' => 900, 'output_tokens' => 60, 'output_tokens_details' => ['reasoning_tokens' => 20], 'total_tokens' => 960],
        ]);
    }

    /** El simulador de la Responses API para los nodos del motor (por el nombre del esquema) y el juez del laboratorio. */
    private function answerEngine(HttpRequest $r): mixed
    {
        $this->sent[] = $r;
        $cuerpo = $r->data();
        $salida = match ($cuerpo['text']['format']['name'] ?? null) {
            'ultron_strategy' => [
                'strategy_goal' => 'recibir', 'next_state' => 'NEW_LEAD', 'intent' => 'greeting', 'recommended_plan_id' => null, 'main_barrier' => null,
                'next_best_action' => 'answer', 'confidence' => 0.9, 'tools_requested' => [], 'staff_review_reason' => null, 'reason' => 'Simulador.',
                'human_handoff_requested' => false, 'human_handoff_reason' => null, 'human_handoff_evidence' => null, 'conversation_goal' => 'understand',
                'information_needed' => [], 'question_needed' => false, 'response_goal' => 'recibir', 'buying_signal' => 'none', 'closing_opportunity' => false,
                'payment_opportunity' => false, 'app_support_opportunity' => false, 'recommendation_goal' => 'none',
            ],
            'ultron_reply' => ['reply_draft' => 'Buenas tardes, ¿en qué le puedo ayudar?', 'uses_price_placeholder' => false, 'questions_asked' => 1, 'plans_mentioned' => 0],
            'ultron_critic' => ['verdict' => 'pass', 'score' => 0.9, 'issues' => [], 'notes' => '', 'hard_fail' => null],
            default => (function () use ($cuerpo): array {
                $objetivos = StudioLabPrompts::judgeTargets((string) ($cuerpo['input'][0]['content'] ?? ''));

                return [
                    'criteria' => array_map(static fn (string $id): array => ['id' => $id, 'verdict' => 'pass', 'evidence' => '', 'reason' => 'Simulador.'], $objetivos['criteria'] ?? []),
                    'rules' => array_map(static fn (string $c): array => ['code' => $c, 'verdict' => 'not_applicable', 'reason' => ''], $objetivos['rules'] ?? []),
                    'risks' => [],
                    'summary' => 'Simulador del proveedor.',
                ];
            })(),
        };

        return Http::response([
            'id' => 'resp_'.Str::random(8), 'object' => 'response', 'status' => 'completed',
            'output' => [['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode($salida, JSON_UNESCAPED_UNICODE)]]]],
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 80, 'total_tokens' => 1280],
        ]);
    }

    private function keep(TestResponse $r): TestResponse
    {
        $this->responses[] = $r;

        return $r;
    }

    /** ¿Nombra el fichero el MODELO del veredicto (no su servicio) en su código? */
    private function namesVerdict(string $ruta): bool
    {
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (! is_array($t) || ! in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if (substr((string) strrchr('\\'.$t[1], '\\'), 1) === 'StudioLabVerdict') {
                return true;
            }
        }

        return false;
    }

    /** ¿Nombra el fichero la TABLA de los veredictos en una cadena de su código (también en heredocs y SQL)? */
    private function namesVerdictTable(string $ruta): bool
    {
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)
                && stripos($t[1], 'ultron_studio_lab_verdicts') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Las piezas de CÓDIGO (no comentarios) que contienen «verdict», en
     * cualquier forma: identificadores, nombres cualificados, cadenas.
     *
     * @return list<string>
     */
    private function verdictTokens(string $ruta): array
    {
        $piezas = [];
        foreach (token_get_all((string) file_get_contents($ruta)) as $t) {
            if (is_array($t) && ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true) && stripos($t[1], 'verdict') !== false) {
                $piezas[] = $t[1];
            }
        }

        return $piezas;
    }

    /** El motor «vivo»: pausa, freno, conocimiento, planes y una conversación con su mensaje. */
    private function seedEngine(): void
    {
        config([
            'marketing.ultron.enabled' => true,
            'marketing.ultron.payment_links_enabled' => false,
            'marketing.ultron.canary_conversation_id' => 987654321,
            'marketing.ultron.webhook_url' => 'https://n8n.ejemplo.test/webhook/ultron',
        ]);
        MarketingAgentSetting::query()->whereKey(MarketingAgentSwitch::ROW_ID)->update(['paused' => true, 'paused_at' => now(), 'version' => 3]);
        UltronAbort::query()->create(['reason' => UltronAbort::REASON_MANUAL, 'engaged_by' => 'Consola de prueba', 'engaged_at' => now()]);
        MarketingKnowledgeItem::create(['category' => 'tone', 'key' => 'tone.cercano', 'title' => 'Tono', 'content' => 'Cercano y claro.', 'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_SERVER]);
        Plan::create(['name' => 'Mensualidad', 'price' => 98765, 'duration_days' => 30, 'active' => true, 'is_recommended' => true, 'sort_order' => 1]);
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3001234567', 'name' => 'Prospecto']);
        $conversacion = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open']);
        DB::table('marketing_messages')->insert([
            'conversation_id' => $conversacion->id, 'direction' => 'inbound', 'sender_type' => 'lead', 'body' => 'Hola, quiero información',
            'status' => 'received', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * La huella de todo lo que no es del laboratorio (y la de las versiones de
     * F/G, que el laboratorio solo lee), y la configuración del motor.
     *
     * @return array{tables:array<string,list<string>>,marketing:string}
     */
    private function fingerprint(): array
    {
        $tablas = [];
        foreach (Schema::getTables() as $t) {
            $nombre = (string) $t['name'];
            if (str_starts_with($nombre, 'ultron_studio_lab_') || in_array($nombre, ['admin_sessions', 'jobs', 'cache', 'cache_locks'], true)
                || ($t['schema'] ?? null) === 'information_schema' || ($t['schema'] ?? null) === 'pg_catalog') {
                continue;
            }
            $filas = DB::table($nombre)->get()->map(static fn (object $f): string => (string) json_encode((array) $f))->all();
            sort($filas);
            $tablas[$nombre] = $filas;
        }
        ksort($tablas);
        foreach (['marketing_messages', 'marketing_agent_settings', 'ultron_aborts', 'marketing_knowledge_items', 'plans', 'ultron_studio_versions'] as $clave) {
            $this->assertArrayHasKey($clave, $tablas);
        }
        $this->assertNotSame([], $tablas['marketing_messages']);

        return ['tables' => $tablas, 'marketing' => (string) json_encode(config('marketing'))];
    }
}
