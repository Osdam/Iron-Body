<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Models\UltronStudio\StudioLabScenario;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioLabConfig;
use App\Services\UltronStudio\StudioLabEngineCase;
use App\Services\UltronStudio\StudioLabEngineFlow;
use App\Services\UltronStudio\StudioLabEvents;
use App\Services\UltronStudio\StudioLabRunner;
use App\Services\UltronStudio\StudioLabScenarios;
use App\Services\UltronStudio\StudioLabStepJob;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NB4 DE LA REVISIÓN DE SEGURIDAD DEL APARTADO 5 (`informe-security-lab.md`):
 * un caso del motor tiene que caber en el job del laboratorio (150 s). Si el
 * job muere por tiempo, `failed()` cobra la reserva ENTERA del caso y dos
 * fallos seguidos paran la ejecución.
 *
 * Con un PROVEEDOR LENTO simulado (cada llamada tarda todo lo que se le deja,
 * y el juez sus 60 s) sobre un reloj de prueba que el runner real usa, y todo
 * lo demás real (runner, contexto, port del flujo, comprobación, juez):
 *  - el camino más largo que un proveedor lento puede recorrer HOY (cinco
 *    llamadas: pasados 25 s la comprobación no deja un tercer borrador, así
 *    que las siete del informe no se alcanzan) cabe entero, sin recortar;
 *  - si además algo ajeno al proveedor tarda (aquí, la base al construir el
 *    contexto), el recorrido se corta en su límite y el caso termina
 *    `timeout` antes de que muera el job, y cobra solo las llamadas hechas.
 */
class UltronStudioLabEngineTimeBudgetTest extends TestCase
{
    use LabFixtures;
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const MODEL = 'gpt-4.1-mini';

    private const DE_USTED = 'Buenas tardes, ¿en qué le puedo ayudar?';

    /** El reloj de prueba (ms) del runner, del caso y del proveedor lento. */
    private int $ms = 0;

    private ?StudioVersion $version = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        $this->labOpenAi(['ultron_studio.lab.openai.model' => self::MODEL, 'ultron_studio.lab.run_budget_usd' => '2', 'ultron_studio.lab.timeout_seconds' => 60]);
        config(['marketing.ai.driver' => 'fake', 'marketing.ultron.payment_links_enabled' => false]);
        (new Plan)->forceFill(['id' => 4, 'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 1, 'is_recommended' => false, 'benefits' => json_encode(['Acceso total al gimnasio'])])->save();
        MarketingKnowledgeItem::create(['category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario', 'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED]);
        $this->importBaseline();
        $this->importLabScenarios();
        // El runner real, con el reloj de la prueba.
        $this->app->instance(StudioLabRunner::class, new StudioLabRunner(app(StudioLabEvents::class), fn (): int => $this->ms));
    }

    public function test_nb4_el_presupuesto_del_caso_cabe_en_el_job_y_no_recorta_el_camino_mas_largo_de_hoy(): void
    {
        $job = new StudioLabStepJob(1);
        // El caso, con 10 s para reclamar, liquidar y encolar, dentro del job y antes de darlo por perdido.
        $this->assertLessThanOrEqual($job->timeout - 10, StudioLabEngineCase::CASE_BUDGET_SECONDS);
        $this->assertLessThan(StudioLabRunner::STALE_AFTER_SECONDS, StudioLabEngineCase::CASE_BUDGET_SECONDS);
        // Con el juez en su máximo, el recorrido aún tiene sitio para cinco llamadas enteras.
        $this->assertGreaterThanOrEqual(5 * StudioLabEngineCase::CALL_TIMEOUT_SECONDS, StudioLabEngineCase::CASE_BUDGET_SECONDS - StudioLabConfig::HARD_MAX_TIMEOUT_SECONDS);
        $this->assertSame([15, 15, 10, null, null, 15], [
            StudioLabEngineCase::callTimeout(0, null), StudioLabEngineCase::callTimeout(0, 80_000), StudioLabEngineCase::callTimeout(70_000, 80_999),
            StudioLabEngineCase::callTimeout(75_001, 80_000), StudioLabEngineCase::callTimeout(90_000, 80_000), StudioLabEngineCase::callTimeout(0, 15_000),
        ]);
    }

    public function test_nb4_con_un_proveedor_lento_el_peor_camino_real_cabe_entero_en_el_plazo(): void
    {
        $cliente = $this->slowProvider([['judge' => 'pass', 'usage' => [900, 120]]], [
            self::paso(StudioLabEngineFlow::STRATEGIST, self::estrategia(), [3000, 200]),
            self::paso(StudioLabEngineFlow::COMPOSER, self::borrador(self::DE_USTED), [4000, 60]),
            self::paso(StudioLabEngineFlow::CRITIC, self::revisor('fail'), [3500, 40]),
            self::paso(StudioLabEngineFlow::RETRY, self::borrador(self::DE_USTED.' Con gusto.'), [4100, 50]),
            self::paso(StudioLabEngineFlow::CRITIC_2, self::revisor('fail'), [3500, 40]),
        ]);
        $run = $this->launchEngine();
        $inicio = $this->ms;

        $run = $this->drain($run->uuid);
        $caso = StudioLabRunCase::query()->where('run_id', $run->id)->sole();

        // Pasados 25 s no hay tercer borrador: cinco llamadas, cada una con sus 15 s enteros, y el juez con sus 60.
        $this->assertSame(['strategist', 'composer', 'check1', 'critic', 'composer_retry', 'check2', 'critic_2'], $caso->engine['path']);
        $this->assertSame(array_fill(0, 5, StudioLabEngineCase::CALL_TIMEOUT_SECONDS), array_column($cliente->engineCalls, 'timeout'));
        $this->assertSame([StudioLabRunCase::STATUS_DONE, ['strategist', 'composer', 'critic', 'composer_retry', 'critic_2', 'judge']], [$caso->status, array_column($caso->calls, 'role')]);
        // 5 × 15 + 60 = 135 s: dentro del plazo del caso, y el caso cobra lo suyo, no la reserva.
        $this->assertSame(135_000, $this->ms - $inicio);
        $this->assertLessThanOrEqual(StudioLabEngineCase::CASE_BUDGET_SECONDS * 1000, $this->ms - $inicio);
        $this->assertSame((int) $caso->cost_micros, array_sum(array_column($caso->calls, 'costMicros')));
        $this->assertLessThan((int) $caso->reserved_micros, (int) $caso->cost_micros);
    }

    public function test_nb4_si_el_caso_no_cabe_termina_timeout_antes_de_que_muera_el_job_y_cobra_lo_gastado(): void
    {
        // El mismo proveedor lento; el juez no llega a llamarse.
        $cliente = $this->slowProvider([], [
            self::paso(StudioLabEngineFlow::STRATEGIST, self::estrategia(), [3000, 200]),
            self::paso(StudioLabEngineFlow::COMPOSER, self::borrador(self::DE_USTED), [4000, 60]),
            self::paso(StudioLabEngineFlow::CRITIC, self::revisor('fail'), [3500, 40]),
            self::paso(StudioLabEngineFlow::RETRY, self::borrador(self::DE_USTED.' Con gusto.'), [4100, 50]),
        ]);
        $run = $this->launchEngine();
        // …y la base tarda 20 s en dar el contexto del caso (la primera lectura de la base de conocimiento).
        $lenta = true;
        DB::listen(function (QueryExecuted $q) use (&$lenta): void {
            if ($lenta && str_contains($q->sql, 'marketing_knowledge_items')) {
                $lenta = false;
                $this->ms += 20_000;
            }
        });
        $inicio = $this->ms;

        $run = $this->drain($run->uuid);
        $caso = StudioLabRunCase::query()->where('run_id', $run->id)->sole();

        $this->assertFalse($lenta, 'el contexto leyó la base de conocimiento');
        // 20 + 4 × 15 = 80 s: el límite del recorrido (140 − 60 del juez). El segundo revisor ya no cabe y no se llama.
        $this->assertSame([StudioLabRunCase::STATUS_ERROR, 'timeout', null], [$caso->status, $caso->error_code, $caso->engine]);
        $this->assertSame(['strategist', 'composer', 'critic', 'composer_retry'], array_column($caso->calls, 'role'));
        $this->assertSame([StudioLabEngineFlow::STRATEGIST, StudioLabEngineFlow::COMPOSER, StudioLabEngineFlow::CRITIC, StudioLabEngineFlow::RETRY], array_column($cliente->engineCalls, 'node'));
        $this->assertSame([], $cliente->calls, 'sin el juez');
        $this->assertSame(80_000, $this->ms - $inicio);
        // Sin el límite serían 20 + 75 + 60 = 155 s > 150: el job moría y cobraba la reserva entera. Ahora, lo gastado.
        $this->assertSame((int) $caso->cost_micros, array_sum(array_column($caso->calls, 'costMicros')));
        $this->assertLessThan((int) $caso->reserved_micros, (int) $caso->cost_micros);
        $this->assertSame(StudioLabRun::STATUS_COMPLETED, $run->status);
    }

    public function test_nb4_el_caso_respeta_el_limite_que_le_pasan(): void
    {
        $cliente = $this->slowProvider([], [
            self::paso(StudioLabEngineFlow::STRATEGIST, self::estrategia(), [3000, 200]),
            self::paso(StudioLabEngineFlow::COMPOSER, self::borrador(self::DE_USTED), [4000, 60]),
            self::paso(StudioLabEngineFlow::CRITIC, self::revisor('fail'), [3500, 40]),
        ]);
        $version = $this->professionalVersion();
        $paquete = StudioPromptBundle::rolesFor(StudioPromptBundle::governedFrom($version->content), $version->uuid, $version->content_hash);
        $escenario = StudioLabScenarios::contentOf(StudioLabScenario::query()->where('code', 'C-01')->sole());

        // Un límite de 40 s: 15 + 15, el revisor con los 10 que quedan, y el reintento ya no cabe.
        $r = app(StudioLabEngineCase::class)->run($paquete, $escenario, $cliente, fn (): int => $this->ms, 40_000);

        $this->assertSame(['error', 'timeout'], [$r['status'], $r['class']]);
        $this->assertSame([15, 15, 10], array_column($cliente->engineCalls, 'timeout'));
        $this->assertSame(['strategist', 'composer', 'critic'], array_column($r['calls'], 'role'));
        $this->assertSame(40_000, $this->ms);
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    /**
     * El proveedor lento: el guion de siempre en la frontera con OpenAI, pero
     * cada llamada del motor tarda TODO el plazo que se le da y el juez los
     * segundos configurados.
     *
     * @param  list<array<string,mixed>>  $juez
     * @param  list<array<string,mixed>>  $motor
     */
    private function slowProvider(array $juez, array $motor): ScriptedLabClient
    {
        $cliente = $this->script($juez);
        $cliente->engineScript = $motor;
        $cliente->whileEngineWaiting = function (int $n) use ($cliente): void {
            $this->ms += $cliente->engineCalls[$n - 1]['timeout'] * 1000;
        };
        $cliente->whileWaiting = function (): void {
            $this->ms += StudioLabConfig::current()->timeoutSeconds * 1000;
        };

        return $cliente;
    }

    private function professionalVersion(): StudioVersion
    {
        if ($this->version !== null) {
            return $this->version;
        }
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $revisa = $this->person(Admin::ROLE_SUPER_ADMIN, 'Revisa');

        return $this->version = $this->approvedVersion($ana, $revisa, 'Profesional y corta', ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]);
    }

    private function launchEngine(): StudioLabRun
    {
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $cuerpo = ['target' => ['kind' => 'version', 'uuid' => $this->professionalVersion()->uuid], 'scenarioUuids' => [$this->scenarioUuid('C-01')], 'fidelity' => 'engine'];
        $estimacion = $this->lab('POST', 'estimate', $root, $cuerpo)->assertOk()->json('data');
        $this->assertTrue($estimacion['allowed'], (string) json_encode($estimacion['refusal']));
        $uuid = (string) $this->lab('POST', 'runs', $root, $cuerpo + ['acceptedMaxCostMicros' => $estimacion['estimate']['maxMicros'], 'clientRequestId' => (string) Str::uuid()])
            ->assertCreated()->json('data.uuid');

        return StudioLabRun::query()->where('uuid', $uuid)->sole();
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
