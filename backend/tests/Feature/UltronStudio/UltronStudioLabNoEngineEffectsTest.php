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
use App\Services\UltronStudio\StudioLabPrompts;
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
 * veredicto, y solo su servicio lo inserta); y el contrato de las 21 rutas de
 * F/G sigue igual.
 */
class UltronStudioLabNoEngineEffectsTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const OPENAI_URL = 'https://api.openai.com/v1/responses';

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
        $detecta = new \ReflectionMethod($comando, 'containsRealSecret');
        $fichero = tempnam(sys_get_temp_dir(), 'lab-env');
        try {
            file_put_contents($fichero, "APP_NAME=Iron\nULTRON_STUDIO_LAB_OPENAI_API_KEY=".self::SENTINEL_KEY."\n");
            $this->assertTrue($detecta->invoke($comando, $fichero), 'una clave del laboratorio de verdad es un secreto');
            file_put_contents($fichero, "APP_NAME=Iron\nULTRON_STUDIO_LAB_OPENAI_API_KEY=\n");
            $this->assertFalse($detecta->invoke($comando, $fichero), 'vacía es un ejemplo');
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
        $real = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/admin/ultron-studio') && ! str_starts_with($route->uri(), 'api/admin/ultron-studio/lab/')) {
                $real[AuthorizationMap::routeKey($route)] = AuthorizationMap::resolve($route);
                $this->assertStringNotContainsString('ultron-studio-lab-run', implode(',', $route->gatherMiddleware()), $route->uri());
            }
        }
        ksort($real);
        ksort($fg);
        $this->assertSame($fg, $real);

        // Las respuestas de F no ganan claves del laboratorio.
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $estado = $this->studio('GET', 'status', $ana)->assertOk()->json('data');
        $this->assertSame(['engine', 'publishing', 'can', 'catalog', 'lifecycle', 'legacyBaseline'], array_keys($estado));
        $this->assertSame(['view', 'editDraft', 'manageRules', 'approve', 'publish', 'rollback'], array_keys($estado['can']));
        $version = $this->createVersion($ana)->assertCreated()->json('data');
        $this->assertSame(['submit', 'approve', 'reject', 'publish', 'rollback'], array_keys($version['actions']));
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
