<?php

namespace Tests\Feature\UltronStudio\E2E;

use App\Models\Admin;
use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Models\UltronStudio\StudioPublication;
use App\Services\UltronStudio\StudioLabEngineFlow;
use App\Services\UltronStudio\StudioLabRuns;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Feature\UltronStudio\LabFixtures;
use Tests\Feature\UltronStudio\PublicationFixtures;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\TestCase;

/**
 * T9 DEL APARTADO 5: EL RECORRIDO DEL MOTOR DE PUNTA A PUNTA CON EL WORKER REAL.
 *
 * Una base PostgreSQL propia (`ultron_studio_*`, migrada desde cero), el
 * simulador de la Responses API que contesta también los esquemas de los nodos
 * del motor (`tests/fixtures/ultron-studio/e2e/mock-openai-lab-motor.cjs`) y
 * `php artisan queue:work lab --queue=studio-lab` en OTRO proceso, con su propio
 * entorno (como en producción: el laboratorio en `openai`, `gpt-4.1-mini`, su
 * clave y su URL). Por las rutas reales: una versión «profesional, 200»
 * aprobada, una ejecución con `fidelity=engine` que hace el worker, su detalle,
 * el veredicto de otra persona y la puerta de publicar con la llave
 * `ULTRON_STUDIO_PUBLISH_LAB_SOURCE` en sus dos estados: `simulation` (como hoy:
 * el veredicto del motor no cuenta) y `engine` (cuenta y publica).
 *
 * Como `H8StackE2ETestCase`, solo corre con su arnés:
 * `tests/fixtures/ultron-studio/e2e/run-lab-motor-e2e.sh` (pone
 * `ULTRON_STUDIO_LAB_E2E=1` y una base desechable). En la suite, salta.
 */
class UltronStudioLabEngineE2ETest extends TestCase
{
    use LabFixtures;
    use PublicationFixtures;
    use StudioFixtures;

    private const PORT = 18093;

    private const MODEL = 'gpt-4.1-mini';

    private ?InvokedProcess $mock = null;

    private ?InvokedProcess $worker = null;

    private string $log = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ULTRON_STUDIO_LAB_E2E') !== '1') {
            $this->markTestSkipped('E2E del laboratorio frente al motor: solo con su arnés tests/fixtures/ultron-studio/e2e/run-lab-motor-e2e.sh (worker real y simulador).');
        }
        $base = (string) config('database.connections.pgsql.database');
        $this->assertSame('pgsql', DB::connection()->getDriverName(), 'el E2E necesita PostgreSQL: el worker no ve una base en memoria');
        $this->assertStringStartsWith('ultron_studio_', $base, 'solo una base desechable del Studio');
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['queue.default' => 'database']);
        $this->labOpenAi(['ultron_studio.lab.openai.model' => self::MODEL, 'ultron_studio.lab.openai.base_url' => 'http://127.0.0.1:'.self::PORT, 'ultron_studio.lab.run_budget_usd' => '2']);
        $this->log = sys_get_temp_dir().'/ultron-lab-motor-e2e-'.bin2hex(random_bytes(4)).'.jsonl';
        $this->mock = Process::start(['node', base_path('tests/fixtures/ultron-studio/e2e/mock-openai-lab-motor.cjs'), (string) self::PORT, $this->log]);
        $this->waitFor(fn (): bool => str_contains($this->mock->output(), 'listo'), 10, 'el simulador no arrancó: '.$this->mock->errorOutput());
    }

    protected function tearDown(): void
    {
        foreach ([$this->worker, $this->mock] as $p) {
            if ($p !== null && $p->running()) {
                $p->signal(SIGTERM);
                $p->wait();
            }
        }
        if ($this->log !== '' && is_file($this->log)) {
            unlink($this->log);
        }
        parent::tearDown();
    }

    public function test_una_ejecucion_del_motor_de_punta_a_punta_con_el_worker_real_y_la_puerta_en_sus_dos_estados(): void
    {
        (new Plan)->forceFill(['id' => 4, 'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 1, 'is_recommended' => false, 'benefits' => json_encode(['Acceso total al gimnasio'])])->save();
        MarketingKnowledgeItem::create(['category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario', 'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED]);
        $this->importBaseline();
        $this->importLabScenarios();
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana E2E');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root E2E');
        $version = $this->approvedVersion($ana, $root, 'Profesional y corta', ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]);

        // Lanzar en modo motor por las rutas reales: la ejecución queda en cola, con su paso en `studio-lab`.
        $cuerpo = ['target' => ['kind' => 'version', 'uuid' => $version->uuid], 'scenarioUuids' => [$this->scenarioUuid('C-01')], 'fidelity' => 'engine'];
        $estimacion = $this->lab('POST', 'estimate', $root, $cuerpo)->assertOk()->json('data');
        $this->assertTrue($estimacion['allowed'], (string) json_encode($estimacion['refusal']));
        $uuid = (string) $this->lab('POST', 'runs', $root, $cuerpo + ['acceptedMaxCostMicros' => $estimacion['estimate']['maxMicros'], 'clientRequestId' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.status', 'queued')->json('data.uuid');
        $this->assertSame(1, DB::table('jobs')->where('queue', 'studio-lab')->count(), 'el paso está en la cola del laboratorio');

        // El worker real, en otro proceso y con el entorno de producción del laboratorio.
        $this->worker = Process::path(base_path())->env($this->workerEnv())->start(['php', 'artisan', 'queue:work', 'lab', '--queue=studio-lab', '--tries=1', '--timeout=170', '--sleep=1', '--max-time=170']);
        $this->waitFor(fn (): bool => in_array(DB::table('ultron_studio_lab_runs')->where('uuid', $uuid)->value('status'), StudioLabRun::TERMINAL_STATUSES, true), 160,
            'la ejecución no terminó: '.$this->worker->output().$this->worker->errorOutput());
        $this->worker->signal(SIGTERM);
        $this->worker->wait();

        $run = StudioLabRun::query()->where('uuid', $uuid)->sole();
        $caso = StudioLabRunCase::query()->where('run_id', $run->id)->sole();
        $this->assertSame([StudioLabRun::STATUS_COMPLETED, StudioLabRunCase::STATUS_DONE, 'pass', StudioLabEngineFlow::promptVersion()], [$run->status, $caso->status, $caso->outcome, $run->prompt_version]);
        $this->assertSame(['strategist', 'composer', 'check1', 'critic'], $caso->engine['path']);
        $this->assertSame(['model', 'ok', true], [$caso->engine['final']['origin'], $caso->engine['final']['register'], $caso->engine['final']['withinCap']]);
        $this->assertSame(['strategist', 'composer', 'critic', 'judge'], array_column($caso->calls, 'role'));
        $this->assertGreaterThan(0, (int) $run->cost_micros);
        $this->assertSame($run->results_hash, StudioLabRuns::resultsHash($run, $run->cases()->get()));
        $detalle = $this->lab('GET', "runs/{$uuid}", $root)->assertOk()->json('data');
        $this->assertSame(['engine', StudioLabRuns::FIDELITY_ENGINE, self::MODEL], [$detalle['fidelityMode'], $detalle['fidelity'], $detalle['engine']['model']]);

        // Lo que vio el simulador: la forma del nodo de n8n en los del motor; el juez, la del laboratorio.
        $peticiones = array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($this->log)))));
        $nombres = array_map(static fn (array $p): string => (string) $p['body']['text']['format']['name'], $peticiones);
        $this->assertSame(['ultron_strategy', 'ultron_reply', 'ultron_critic', 'ultron_lab_judge_v1'], $nombres);
        foreach ($peticiones as $p) {
            $this->assertSame(['POST', '/v1/responses', 'Bearer '.substr(self::SENTINEL_KEY, 0, 6).'…'], [$p['method'], $p['url'], $p['authorization']]);
            $this->assertSame([self::MODEL, false], [$p['body']['model'], $p['body']['store']]);
        }
        foreach (array_slice($peticiones, 0, 3) as $p) {
            $this->assertSame(['user', 'user'], array_column($p['body']['input'], 'role'));
            $this->assertArrayNotHasKey('instructions', $p['body']);
            $this->assertArrayNotHasKey('strict', $p['body']['text']['format']);
        }
        $this->assertSame([0.1, 0.7, 0.1], array_map(static fn (array $p): float => (float) $p['body']['temperature'], array_slice($peticiones, 0, 3)));

        // El veredicto de otra persona sobre la ejecución del motor.
        $this->lab('POST', "runs/{$uuid}/verdict", $root, ['decision' => 'approved', 'resultsHash' => $detalle['resultsHash'], 'acknowledgedCases' => $detalle['requiredAcknowledgements']])->assertOk();

        // La puerta con la llave en `simulation` (la de siempre): el veredicto del motor no cuenta.
        $this->openKeys(['ultron_studio.publishing.lab_gate' => 'required', 'ultron_studio.publishing.lab_source' => 'simulation']);
        $this->assertSame(['mode' => 'required', 'ok' => false, 'latest' => null, 'message' => 'Sin veredicto del laboratorio sobre esta huella.'], $this->previewOf($root, $version->uuid)['requirements']['labGate']);
        $this->publishAs($root, $version->uuid)->assertStatus(409)->assertJsonPath('code', 'lab_verdict_required');

        // Con `engine`: cuenta y publica, con la versión de prompts del motor firmada en la entrada.
        config(['ultron_studio.publishing.lab_source' => 'engine']);
        $this->assertSame(['mode' => 'required', 'ok' => true, 'latest' => 'approved'], $this->previewOf($root, $version->uuid)['requirements']['labGate']);
        $this->publishAs($root, $version->uuid)->assertCreated()->assertJsonPath('data.labGate', ['mode' => 'required']);
        $this->assertSame(['mode' => 'required', 'runUuid' => $uuid, 'promptVersion' => StudioLabEngineFlow::promptVersion()],
            array_intersect_key(StudioPublication::query()->sole()->lab_gate, array_flip(['mode', 'runUuid', 'promptVersion'])));
    }

    /** @return array<string,string> el entorno del worker: el de producción del laboratorio, sin `.env` */
    private function workerEnv(): array
    {
        $db = config('database.connections.pgsql');

        return [
            'PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME'), 'LC_ALL' => 'C', 'PGGSSENCMODE' => 'disable',
            'APP_ENV' => 'local', 'APP_KEY' => (string) config('app.key'), 'APP_DEBUG' => 'false', 'LOG_CHANNEL' => 'stderr',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) ($db['password'] ?? ''), 'DB_URL' => '',
            'QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'OPENAI_API_KEY' => '',
            'ULTRON_STUDIO_LAB_ENABLED' => 'true', 'ULTRON_STUDIO_LAB_DRIVER' => 'openai', 'ULTRON_STUDIO_LAB_OPENAI_API_KEY' => self::SENTINEL_KEY,
            'ULTRON_STUDIO_LAB_OPENAI_MODEL' => self::MODEL, 'ULTRON_STUDIO_LAB_OPENAI_BASE_URL' => 'http://127.0.0.1:'.self::PORT,
            'ULTRON_STUDIO_LAB_PRICE_INPUT_USD_PER_MTOK' => '0.40', 'ULTRON_STUDIO_LAB_PRICE_OUTPUT_USD_PER_MTOK' => '1.60',
            'ULTRON_STUDIO_LAB_DAILY_BUDGET_USD' => '2', 'ULTRON_STUDIO_LAB_RUN_BUDGET_USD' => '2', 'ULTRON_STUDIO_LAB_MAX_RUNS_PER_DAY' => '20',
            'ULTRON_STUDIO_LAB_ACTOR_MAX_OUTPUT_TOKENS' => '400', 'ULTRON_STUDIO_LAB_JUDGE_MAX_OUTPUT_TOKENS' => '900', 'ULTRON_STUDIO_LAB_TIMEOUT_SECONDS' => '60',
        ];
    }

    private function waitFor(\Closure $hecho, int $segundos, string $mensaje): void
    {
        $limite = microtime(true) + $segundos;
        while (microtime(true) < $limite) {
            if ($hecho()) {
                return;
            }
            usleep(250_000);
        }
        $this->fail($mensaje);
    }
}
