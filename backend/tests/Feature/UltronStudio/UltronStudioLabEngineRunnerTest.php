<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Models\UltronStudio\StudioLabScenario;
use App\Models\UltronStudio\StudioVersion;
use App\Services\Marketing\Ultron\Prompts\UltronListTurnPrompts;
use App\Services\UltronStudio\StudioLabCost;
use App\Services\UltronStudio\StudioLabEngineCase;
use App\Services\UltronStudio\StudioLabEngineFlow;
use App\Services\UltronStudio\StudioLabPrompts;
use App\Services\UltronStudio\StudioLabRunner;
use App\Services\UltronStudio\StudioLabRuns;
use App\Services\UltronStudio\StudioLabScenarios;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T7 (Y PARTE DE T8) DEL APARTADO 5: EL RECORRIDO DEL MOTOR EN EL RUNNER
 * REAL, con el cliente guionizado en la frontera con OpenAI y TODO lo demás
 * real: el contexto, el port del flujo, la comprobación del laboratorio, la
 * predicción, el juez, la agregación, la liquidación y la huella.
 *
 * La versión probada es «profesional, 200» (el motor comprueba el usted y el
 * tope). Se fijan: los recorridos (el servidor pide una reescritura, el revisor
 * rechaza los tres borradores, un fallo del proveedor), los 25 s, el tope de 3,
 * el coste por llamada y su papel, el bloque `engine`, los hallazgos del motor,
 * lo que se envía a cada nodo (su prompt compilado), la creación con `fidelity`
 * (el modelo del motor o 409) y que una ejecución del motor solo escribe en las
 * tablas del laboratorio y no sale a la red.
 */
class UltronStudioLabEngineRunnerTest extends TestCase
{
    use LabFixtures;
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const MODEL = 'gpt-4.1-mini';

    private const TUTEA = 'Buenas tardes, ¿en qué te puedo ayudar?';

    private const DE_USTED = 'Buenas tardes, ¿en qué le puedo ayudar?';

    private ?StudioVersion $version = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        $this->labOpenAi(['ultron_studio.lab.openai.model' => self::MODEL, 'ultron_studio.lab.run_budget_usd' => '2']);
        config(['marketing.ai.driver' => 'fake', 'marketing.ultron.payment_links_enabled' => false]);
        (new Plan)->forceFill(['id' => 4, 'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 1, 'is_recommended' => false, 'benefits' => json_encode(['Acceso total al gimnasio'])])->save();
        MarketingKnowledgeItem::create(['category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario', 'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED]);
        $this->importBaseline();
        $this->importLabScenarios();
    }

    public function test_el_servidor_pide_una_reescritura_y_el_texto_final_es_del_modelo(): void
    {
        $cliente = $this->script([['judge' => 'pass', 'usage' => [900, 120]]]);
        $cliente->engineScript = [
            self::paso(StudioLabEngineFlow::STRATEGIST, self::estrategia(), [3000, 200]),
            self::paso(StudioLabEngineFlow::COMPOSER, self::borrador(self::TUTEA), [4000, 60]),
            self::paso(StudioLabEngineFlow::RETRY, self::borrador(self::DE_USTED), [4100, 50]),
            self::paso(StudioLabEngineFlow::CRITIC_2, self::revisor('pass'), [3500, 40]),
        ];
        $run = $this->launchEngine(['C-01']);
        $this->assertSame(StudioLabEngineFlow::promptVersion(), $run->prompt_version);
        // Desde aquí, solo el procesamiento de la ejecución del motor.
        $escrituras = $this->listenWrites();
        $run = $this->drain($run->uuid);
        $caso = StudioLabRunCase::query()->where('run_id', $run->id)->sole();

        $this->assertSame([StudioLabRun::STATUS_COMPLETED, StudioLabRunCase::STATUS_DONE, 'pass'], [$run->status, $caso->status, $caso->outcome]);
        $this->assertSame(self::DE_USTED, $caso->reply);
        $e = $caso->engine;
        $this->assertSame(['strategist', 'composer', 'check1', 'composer_retry', 'check2', 'critic_2'], $e['path']);
        $this->assertSame([1, 2], array_column($e['drafts'], 'n'));
        $this->assertContains('register_tuteo', $e['drafts'][0]['check']['codes']);
        $this->assertSame([true, 'server'], [$e['drafts'][0]['check']['mayRewrite'], $e['drafts'][1]['rewriteBy']]);
        $this->assertSame(['te'], $e['drafts'][0]['tuteo']);
        $this->assertSame(['pass' => true, 'codes' => []], array_intersect_key($e['drafts'][1]['check'], ['pass' => 1, 'codes' => 1]));
        $this->assertSame(['draft' => 2, 'origin' => 'model', 'cause' => null, 'codes' => [], 'cap' => 200, 'withinCap' => true, 'register' => 'ok'],
            array_intersect_key($e['final'], array_flip(['draft', 'origin', 'cause', 'codes', 'withinCap', 'register', 'cap'])));
        $this->assertSame(['motor-reescrituras'], array_values(array_filter(array_column($caso->checks, 'id'), static fn (string $id): bool => str_starts_with($id, 'motor-'))));
        $this->assertLessThanOrEqual(16384, strlen((string) json_encode($e)), 'el bloque cabe en 16 KB');

        // Cada llamada con su papel y su coste; la del juez, la de siempre.
        $this->assertSame(['strategist', 'composer', 'composer_retry', 'critic_2', 'judge'], array_column($caso->calls, 'role'));
        $precios = ['input' => (int) $run->prices['input'], 'output' => (int) $run->prices['output']];
        $esperado = array_sum(array_map(static fn (array $u): int => StudioLabCost::actualMicros(['inputTokens' => $u[0], 'outputTokens' => $u[1], 'reasoningTokens' => 0], $precios), [[3000, 200], [4000, 60], [4100, 50], [3500, 40], [900, 120]]));
        $this->assertSame([$esperado, $esperado], [(int) $caso->cost_micros, (int) $run->cost_micros]);
        $this->assertSame($run->results_hash, StudioLabRuns::resultsHash($run, $run->cases()->get()), 'la huella se recalcula igual, con el bloque engine');

        // Lo que recibió cada nodo: el prompt compilado de su rol de ESTA versión, con un timeout de 15 s.
        $roles = StudioPromptBundle::rolesFor($run->target_snapshot['engine']['governed'])['roles'];
        foreach ($cliente->engineCalls as $l) {
            $role = StudioLabEngineFlow::NODES[$l['node']]['role'];
            $this->assertSame(UltronListTurnPrompts::select($role, $roles[$role], false), $l['system'], 'en este escenario de saludo se invoca la variante general firmada');
            $this->assertSame(StudioLabEngineCase::CALL_TIMEOUT_SECONDS, $l['timeout']);
        }
        $this->assertSame([0, 0], [$cliente->remaining(), count($cliente->engineScript)]);

        // T8 (parte): solo escribe en las tablas del laboratorio (y la cola falsa), y no sale a la red.
        $this->assertSame([], array_values(array_filter($escrituras(), static fn (string $t): bool => ! str_starts_with($t, 'ultron_studio_lab_') && ! in_array($t, ['cache', 'cache_locks'], true))));
        Http::assertNothingSent();
    }

    public function test_c9_firma_el_juez_y_una_huella_distinta_detiene_el_caso_antes_de_llamar(): void
    {
        $client = $this->script([]);
        $run = $this->launchEngine(['C-01']);
        $target = $run->target_snapshot;
        $this->assertSame(StudioLabPrompts::ENGINE_JUDGE_VERSION, $target['engine']['judgeVersion']);
        $this->assertSame(StudioLabPrompts::engineJudgeHash(), $target['engine']['judgeHash']);
        $target['engine']['judgeHash'] = str_repeat('0', 64);
        // Se prueba la defensa del worker con una copia en memoria, sin violar la inmutabilidad de la fila.
        $case = StudioLabRunCase::query()->where('run_id', $run->id)->sole();
        StudioLabRun::advance($run->id, [StudioLabRun::STATUS_QUEUED], ['status' => StudioLabRun::STATUS_RUNNING, 'started_at' => now()]);
        StudioLabRunCase::advance($case->id, [StudioLabRunCase::STATUS_PENDING], ['status' => StudioLabRunCase::STATUS_RUNNING, 'started_at' => now()]);
        $run->setAttribute('target_snapshot', $target);
        (new \ReflectionMethod(StudioLabRunner::class, 'processEngine'))->invoke(app(StudioLabRunner::class), $run, $case);
        $case->refresh();
        $this->assertSame(['error', 'internal_error', [], 0], [$case->status, $case->error_code, $case->calls, (int) $case->cost_micros]);
        $this->assertSame([], $client->engineCalls);
        $this->assertSame([], $client->calls);
        Http::assertNothingSent();
    }

    public static function rawFueraDeAuditoria(): array
    {
        return ['2001 caracteres' => [2001], '4000 caracteres del contrato HTTP' => [4000]];
    }

    #[DataProvider('rawFueraDeAuditoria')]
    public function test_c9_no_recorta_un_raw_largo_y_liquida_sin_aceptar_un_segundo_borrador_ni_llamar_al_juez(int $chars): void
    {
        $client = $this->script([]);
        $raw = str_repeat('x', $chars);
        $client->engineScript = [
            self::paso(StudioLabEngineFlow::STRATEGIST, self::estrategia(), [3000, 200]),
            self::paso(StudioLabEngineFlow::COMPOSER, self::borrador($raw), [4000, 700]),
            self::paso(StudioLabEngineFlow::RETRY, self::borrador(self::DE_USTED), [4100, 50]),
            self::paso(StudioLabEngineFlow::CRITIC_2, self::revisor('pass'), [3500, 40]),
        ];
        $run = $this->drain($this->launchEngine(['C-01'])->uuid);
        $case = StudioLabRunCase::query()->where('run_id', $run->id)->sole();
        $this->assertSame(['error', 'invalid_output', null, null], [$case->status, $case->error_code, $case->reply, $case->engine]);
        $this->assertSame(['strategist', 'composer'], array_column($case->calls, 'role'));
        $this->assertGreaterThan(0, (int) $case->cost_micros);
        $this->assertSame((int) $case->cost_micros, (int) $run->cost_micros);
        $this->assertSame([], $client->calls, 'el juez no recibe una versión truncada del texto');
        $this->assertCount(2, $client->engineCalls);
        $this->assertCount(2, $client->engineScript, 'el segundo borrador válido permanece sin invocar');
        Http::assertNothingSent();
    }

    public function test_el_revisor_rechaza_los_tres_borradores_y_saldria_un_respaldo(): void
    {
        $this->script([['judge' => 'pass', 'usage' => [900, 120]]])->engineScript = [
            self::paso(StudioLabEngineFlow::STRATEGIST, self::estrategia(), [3000, 200]),
            self::paso(StudioLabEngineFlow::COMPOSER, self::borrador(self::DE_USTED), [4000, 60]),
            self::paso(StudioLabEngineFlow::CRITIC, self::revisor('fail'), [3500, 40]),
            self::paso(StudioLabEngineFlow::RETRY, self::borrador(self::DE_USTED.' Con gusto.'), [4100, 50]),
            self::paso(StudioLabEngineFlow::CRITIC_2, self::revisor('fail'), [3500, 40]),
            self::paso(StudioLabEngineFlow::RETRY_2, self::borrador('Buenas tardes. ¿Le cuento?'), [4100, 50]),
            self::paso(StudioLabEngineFlow::CRITIC_3, self::revisor('fail'), [3500, 40]),
        ];

        $run = $this->drain($this->launchEngine(['C-01'])->uuid);
        $caso = StudioLabRunCase::query()->where('run_id', $run->id)->sole();

        $this->assertSame(['strategist', 'composer', 'check1', 'critic', 'composer_retry', 'check2', 'critic_2', 'composer_retry_2', 'critic_3'], $caso->engine['path']);
        $this->assertCount(3, $caso->engine['drafts'], 'el tope de 3 borradores');
        $this->assertNull($caso->engine['drafts'][2]['check'], 'el tercero no se comprueba');
        $this->assertSame(['critic', 'critic'], [$caso->engine['drafts'][1]['rewriteBy'], $caso->engine['drafts'][2]['rewriteBy']]);
        $this->assertSame(['fallback', 'critic_failed'], [$caso->engine['final']['origin'], $caso->engine['final']['cause']]);
        $this->assertContains('motor-respaldo', array_column($caso->checks, 'id'));
        $this->assertSame('fail', $caso->outcome, 'un respaldo bloquea');
        $this->assertCount(8, $caso->calls);
    }

    public function test_un_fallo_del_proveedor_para_el_caso_con_su_clase_y_cobra_la_reserva_de_esa_llamada(): void
    {
        $this->script([])->engineScript = [
            self::paso(StudioLabEngineFlow::STRATEGIST, self::estrategia(), [3000, 200]),
            ['node' => StudioLabEngineFlow::COMPOSER, 'class' => 'timeout'],
        ];

        $run = $this->drain($this->launchEngine(['C-01'])->uuid);
        $caso = StudioLabRunCase::query()->where('run_id', $run->id)->sole();

        $this->assertSame([StudioLabRunCase::STATUS_ERROR, 'timeout', null], [$caso->status, $caso->error_code, $caso->engine]);
        $this->assertSame(['strategist', 'composer'], array_column($caso->calls, 'role'));
        $this->assertGreaterThan(0, $caso->calls[1]['costMicros'], 'un timeout cobra el tope de su llamada');
        $this->assertSame((int) $caso->cost_micros, array_sum(array_column($caso->calls, 'costMicros')));
    }

    public function test_pasados_25_segundos_no_hay_reescritura_y_el_tuteo_sale_como_respaldo(): void
    {
        $cliente = $this->script([]);
        $cliente->engineScript = [
            self::paso(StudioLabEngineFlow::STRATEGIST, self::estrategia(), [3000, 200]),
            self::paso(StudioLabEngineFlow::COMPOSER, self::borrador(self::TUTEA), [4000, 60]),
            self::paso(StudioLabEngineFlow::CRITIC, self::revisor('pass'), [3500, 40]),
        ];
        $ms = 0;
        // Cada llamada tarda 13 s: la comprobación del primer borrador llega a los 26.
        $cliente->whileEngineWaiting = function () use (&$ms): void {
            $ms += 13_000;
        };
        $version = $this->professionalVersion();
        $gobernado = StudioPromptBundle::governedFrom($version->content);
        $paquete = StudioPromptBundle::rolesFor($gobernado, $version->uuid, $version->content_hash);
        $escenario = StudioLabScenarios::contentOf(StudioLabScenario::query()->where('code', 'C-01')->sole());

        $r = app(StudioLabEngineCase::class)->run($paquete, $escenario, $cliente, function () use (&$ms): int {
            return $ms;
        });

        $this->assertSame('done', $r['status']);
        $this->assertSame(['strategist', 'composer', 'check1', 'critic'], $r['engine']['path']);
        $this->assertSame(['time_budget', false], [$r['engine']['drafts'][0]['check']['reason'], $r['engine']['drafts'][0]['check']['mayRewrite']]);
        $this->assertSame(['fallback', 'register_tuteo'], [$r['engine']['final']['origin'], $r['engine']['final']['cause']]);
        $this->assertSame(39_000, $r['engine']['elapsedMs']);
    }

    public function test_el_modo_motor_exige_el_modelo_de_los_nodos_y_la_simulacion_no_cambia(): void
    {
        $this->labOpenAi(['ultron_studio.lab.openai.model' => 'gpt-lab-pruebas']);
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $version = $this->professionalVersion();
        $cuerpo = ['target' => ['kind' => 'version', 'uuid' => $version->uuid], 'scenarioUuids' => [$this->scenarioUuid('C-01')]];

        $motor = $this->lab('POST', 'estimate', $root, $cuerpo + ['fidelity' => 'engine'])->assertOk()->json('data');
        $this->assertSame([false, 'lab_not_configured'], [$motor['allowed'], $motor['refusal']['code']]);
        $this->assertSame([StudioLabEngineFlow::promptVersion(), 'engine'], [$motor['promptVersion'], $motor['fidelityMode']]);
        $this->assertSame(StudioLabRuns::FIDELITY_ENGINE, $motor['fidelity']);
        $this->lab('POST', 'runs', $root, $cuerpo + ['fidelity' => 'engine', 'acceptedMaxCostMicros' => $motor['estimate']['maxMicros'], 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'lab_not_configured');

        // La simulación: la misma estimación de siempre, sin ninguna clave nueva.
        $sim = $this->lab('POST', 'estimate', $root, $cuerpo)->assertOk()->json('data');
        $this->assertSame(['mode', 'model', 'promptVersion', 'cases', 'estimate', 'budget', 'allowed', 'refusal', 'excludedRules'], array_keys($sim));
        $this->assertSame($sim, $this->lab('POST', 'estimate', $root, $cuerpo + ['fidelity' => 'simulation'])->assertOk()->json('data'));
        $this->lab('POST', 'estimate', $root, $cuerpo + ['fidelity' => 'otra'])->assertStatus(422);
        // El motor reserva más que la simulación: siete nodos y el juez.
        $this->assertGreaterThan($sim['estimate']['maxMicros'], $motor['estimate']['maxMicros']);
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    private function professionalVersion(): StudioVersion
    {
        if ($this->version !== null) {
            return $this->version;
        }
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $revisa = $this->person(Admin::ROLE_SUPER_ADMIN, 'Revisa');

        return $this->version = $this->approvedVersion($ana, $revisa, 'Profesional y corta', ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]);
    }

    /** @param  list<string>  $codes */
    private function launchEngine(array $codes): StudioLabRun
    {
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $cuerpo = ['target' => ['kind' => 'version', 'uuid' => $this->professionalVersion()->uuid], 'scenarioUuids' => array_map(fn (string $c): string => $this->scenarioUuid($c), $codes), 'fidelity' => 'engine'];
        $estimacion = $this->lab('POST', 'estimate', $root, $cuerpo)->assertOk()->json('data');
        $this->assertTrue($estimacion['allowed'], json_encode($estimacion['refusal']));
        $uuid = (string) $this->lab('POST', 'runs', $root, $cuerpo + ['acceptedMaxCostMicros' => $estimacion['estimate']['maxMicros'], 'clientRequestId' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.fidelityMode', 'engine')->json('data.uuid');

        return StudioLabRun::query()->where('uuid', $uuid)->sole();
    }

    /** @return \Closure(): list<string> las tablas en las que se escribió desde ahora */
    private function listenWrites(): \Closure
    {
        $tablas = [];
        DB::listen(function (QueryExecuted $q) use (&$tablas): void {
            if (preg_match('/^\s*(?:insert\s+into|update|delete\s+from)\s+"?(\w+)"?/i', $q->sql, $m) === 1) {
                $tablas[] = strtolower($m[1]);
            }
        });

        return function () use (&$tablas): array {
            return array_values(array_unique($tablas));
        };
    }

    /** @return array<string,mixed> */
    private static function paso(string $nodo, array $salida, array $uso): array
    {
        return ['node' => $nodo, 'output' => $salida, 'usage' => $uso];
    }

    /** @return array<string,mixed> */
    private static function estrategia(): array
    {
        return [
            'strategy_goal' => 'recibir', 'next_state' => 'NEW_LEAD', 'intent' => 'greeting', 'recommended_plan_id' => null, 'main_barrier' => null,
            'next_best_action' => 'answer', 'confidence' => 0.9, 'tools_requested' => [], 'staff_review_reason' => null, 'reason' => 'saludo',
            'human_handoff_requested' => false, 'human_handoff_reason' => null, 'human_handoff_evidence' => null, 'conversation_goal' => 'understand',
            'information_needed' => [], 'question_needed' => false, 'response_goal' => 'recibir', 'buying_signal' => 'none', 'closing_opportunity' => false,
            'payment_opportunity' => false, 'app_support_opportunity' => false, 'recommendation_goal' => 'none',
        ];
    }

    /** @return array<string,mixed> */
    private static function borrador(string $texto): array
    {
        return ['reply_draft' => $texto, 'uses_price_placeholder' => false, 'questions_asked' => 1, 'plans_mentioned' => 0];
    }

    /** @return array<string,mixed> */
    private static function revisor(string $veredicto): array
    {
        return ['verdict' => $veredicto, 'score' => $veredicto === 'pass' ? 0.9 : 0.2, 'issues' => $veredicto === 'pass' ? [] : ['naturalness'], 'notes' => $veredicto === 'pass' ? '' : 'Más cercano.', 'hard_fail' => null];
    }
}
