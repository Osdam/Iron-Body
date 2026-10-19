<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Services\UltronStudio\StudioLabCost;
use App\Services\UltronStudio\StudioLabModelClient;
use App\Services\UltronStudio\StudioLabRunner;
use App\Services\UltronStudio\StudioLabRuns;
use App\Services\UltronStudio\StudioLabStepJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * El RUNNER del laboratorio en línea (contrato H4 §A3, §A4 y §A5; Enmienda 1):
 * el ensayo sin IA de principio a fin, y con un proveedor GUIONIZADO
 * registrado solo en esta prueba: el coste de cada llamada, la agregación (un
 * hallazgo bloqueante gana al juez; sin juez, como mucho revisión), las
 * paradas por auth, cuota y rechazo, la racha de dos errores POR CASO, el 429
 * de ritmo que retrasa y no cuenta, el plazo, el presupuesto agotado,
 * `failed()` cobrando la reserva y dos pasos a la vez que nunca procesan el
 * mismo caso.
 *
 * Precios de las pruebas: 0,40 y 1,60 US$ por millón (400 000 y 1 600 000
 * micro-US$). Un caso con actor [900, 40] y juez [1500, 300] cuesta
 * (360 + 64) + (600 + 480) = 1504 micro-US$, redondeando hacia arriba cada
 * término.
 */
class UltronStudioLabRunnerTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const ACTOR_OK = ['reply' => 'Hola, ¿en qué te puedo ayudar hoy?', 'usage' => [900, 40]];

    private const JUDGE_PASS = ['judge' => 'pass', 'usage' => [1500, 300]];

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importBaseline();
        $this->importLabScenarios();
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
    }

    public function test_el_ensayo_sin_ia_de_principio_a_fin_por_la_cola(): void
    {
        $this->labFake();
        // La cola de la suite es síncrona: el job real hace cada paso dentro de la petición.
        $d = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data');

        $this->assertSame(['completed', 'fake', null, null], [$d['status'], $d['mode'], $d['model'], $d['aiRecommendation']]);
        $this->assertSame(['done' => 3, 'error' => 0, 'skipped' => 0, 'pending' => 0], $d['progress']);
        $this->assertSame(['pass' => 0, 'review' => 3, 'fail' => 0], $d['outcomes'], 'sin IA, como mucho revisión');
        $this->assertSame(0, $d['cost']['actualMicros']);
        foreach ($d['cases'] as $caso) {
            $this->assertSame([], $caso['checks'], 'la frase del ensayo no dispara ninguna prueba');
            $this->assertSame('Modo de prueba: sin IA', $caso['judge']['summary']);
            $this->assertSame(['inputTokens' => 0, 'outputTokens' => 0, 'costMicros' => 0, 'overrunMicros' => 0], $caso['usage']);
        }
        $this->assertSame(['C-01', 'C-02', 'C-03'], $d['requiredAcknowledgements']);
        $run = StudioLabRun::query()->where('uuid', $d['uuid'])->sole();
        $this->assertSame($run->results_hash, StudioLabRuns::resultsHash($run, $run->cases()->get()), 'la huella se recalcula igual');
        $fin = StudioLabEvent::query()->where('event', 'run.finished')->sole();
        $this->assertSame(['system', 'ultron-studio:lab-runner', 'running', 'completed'], [$fin->actor_type, $fin->actor_name, $fin->from_status, $fin->to_status]);
        $this->assertSame(['status', 'stopReason', 'outcomes', 'costMicros', 'aiRecommendation'], array_keys($fin->diff));
    }

    public function test_openai_guionizado_liquida_cada_llamada_y_propone_aprobar(): void
    {
        $this->labOpenAi();
        $guion = $this->script([self::ACTOR_OK, self::JUDGE_PASS, self::ACTOR_OK, self::JUDGE_PASS]);

        $d = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-07'])->assertCreated()->json('data');

        $this->assertSame(0, $guion->remaining());
        $this->assertSame(['completed', 'approve', 'gpt-lab-pruebas'], [$d['status'], $d['aiRecommendation'], $d['model']]);
        $this->assertSame(['pass' => 2, 'review' => 0, 'fail' => 0], $d['outcomes']);
        foreach ($d['cases'] as $caso) {
            $this->assertSame(['inputTokens' => 2400, 'outputTokens' => 340, 'costMicros' => 1504, 'overrunMicros' => 0], $caso['usage']);
        }
        $this->assertSame(3008, $d['cost']['actualMicros']);
        $llamadas = StudioLabRunCase::query()->orderBy('id')->first()->calls;
        $this->assertSame([['actor', null, 900, 40, 424], ['judge', null, 1500, 300, 1080]], array_map(
            static fn (array $l): array => [$l['role'], $l['class'], $l['inputTokens'], $l['outputTokens'], $l['costMicros']], $llamadas));
        $this->assertSame([], $d['requiredAcknowledgements']);
        // El actor nunca recibe los criterios ni el título; el juez, sí.
        $this->assertStringNotContainsString('criterios', $guion->calls[0]['input']);
        $this->assertStringContainsString('"criterios"', $guion->calls[1]['input']);
        $this->assertSame([400, 900], [$guion->calls[0]['maxOutputTokens'], $guion->calls[1]['maxOutputTokens']]);
    }

    public function test_un_hallazgo_bloqueante_gana_al_juez(): void
    {
        $this->labOpenAi();
        $this->script([['reply' => 'La mensualidad cuesta 80.000 pesos', 'usage' => [900, 40]], self::JUDGE_PASS]);

        $d = $this->launch($this->root, ['kind' => 'baseline'], ['C-02'])->assertCreated()->json('data');

        $caso = $d['cases'][0];
        $this->assertSame('pass', $caso['judge']['criteria'][0]['verdict'], 'el juez dio todo por bueno');
        $this->assertSame('fail', $caso['outcome']);
        $this->assertContains('motor-machine_reply_invented_price', array_column($caso['checks'], 'id'));
        $this->assertSame(['engine', 'blocking'], [$caso['checks'][0]['source'], $caso['checks'][0]['level']]);
        $this->assertStringStartsWith('El filtro real del motor marcaría este texto', $caso['checks'][0]['title']);
        $this->assertSame('reject', $d['aiRecommendation']);
        $this->assertSame(['C-02'], $d['requiredAcknowledgements']);
    }

    public function test_si_falla_el_juez_el_caso_queda_done_sin_opinion_y_como_mucho_en_revision(): void
    {
        $this->labOpenAi();
        $this->script([self::ACTOR_OK, ['class' => 'server', 'http' => 503], self::ACTOR_OK, self::JUDGE_PASS]);

        $d = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-07'])->assertCreated()->json('data');

        $caso = $d['cases'][0];
        $this->assertSame(['done', 'review', null, ['class' => 'server'], null], [$caso['status'], $caso['outcome'], $caso['judge'], $caso['judgeError'], $caso['errorCode']]);
        $this->assertSame(424, $caso['usage']['costMicros'], 'el actor por su uso; el 5xx no cuesta');
        $this->assertSame(['done', 'pass', null], [$d['cases'][1]['status'], $d['cases'][1]['outcome'], $d['cases'][1]['judgeError']]);
        $this->assertSame(['completed', 'review'], [$d['status'], $d['aiRecommendation']]);
    }

    public function test_auth_cuota_y_rechazo_paran_al_instante_y_saltan_el_resto(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logs): void {
            $logs[] = [$m->message, $m->context];
        });
        foreach (['auth' => 'provider_auth', 'quota' => 'provider_quota', 'bad_request' => 'provider_rejected'] as $clase => $motivo) {
            $this->labOpenAi();
            $this->script([['class' => $clase, 'http' => $clase === 'auth' ? 401 : ($clase === 'quota' ? 429 : 400)]]);

            $d = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data');

            $this->assertSame(['stopped', $motivo, null], [$d['status'], $d['stopReason'], $d['aiRecommendation']], $clase);
            $this->assertSame(['error', 'skipped', 'skipped'], array_column($d['cases'], 'status'), $clase);
            $this->assertSame([$clase, 0], [$d['cases'][0]['errorCode'], $d['cases'][0]['usage']['costMicros']], $clase);
            $this->assertSame(StudioLabModelClient::MESSAGES[$clase], $d['cases'][0]['errorMessage']);
            $this->assertSame(['C-01', 'C-02', 'C-03'], $d['requiredAcknowledgements']);
        }
        // El log del laboratorio: solo {run, posición, papel, clase, http, excepción}; nada del proveedor.
        $llamadas = array_values(array_filter($logs, static fn (array $l): bool => $l[0] === 'ultron_studio.lab.provider_call'));
        $this->assertCount(3, $llamadas);
        foreach ($llamadas as [, $contexto]) {
            $this->assertSame(['run', 'position', 'role', 'class', 'http', 'exception'], array_keys($contexto));
        }
    }

    public function test_dos_errores_seguidos_por_caso_paran_con_provider_errors(): void
    {
        // 5xx y luego timeout (el timeout cobra la reserva de ESA llamada).
        $this->labOpenAi();
        $this->script([['class' => 'server', 'http' => 500], ['class' => 'timeout']]);
        $d = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data');
        $this->assertSame(['stopped', 'provider_errors'], [$d['status'], $d['stopReason']]);
        $this->assertSame(['error', 'error', 'skipped'], array_column($d['cases'], 'status'));
        $run = StudioLabRun::query()->where('uuid', $d['uuid'])->sole();
        $segundo = $run->cases()->get()[1];
        $estimado = StudioLabCost::estimateScenario($run->target_snapshot, $segundo->scenario_snapshot, 400, 900, ['input' => 400000, 'output' => 1600000]);
        $topeActor = StudioLabCost::callEstimate($estimado['actorInputTokens'], 400, ['input' => 400000, 'output' => 1600000])['maxMicros'];
        $this->assertSame([0, $topeActor], [$d['cases'][0]['usage']['costMicros'], $d['cases'][1]['usage']['costMicros']]);
        $this->assertLessThan($segundo->reserved_micros, $topeActor, 'se cobra la reserva de la llamada, no la del caso');

        // Un modelo que siempre queda incompleto para en el segundo caso (Enmienda 1, decisión 2).
        $this->labOpenAi();
        $this->script([['class' => 'incomplete', 'usage' => [900, 400]], ['class' => 'incomplete', 'usage' => [900, 400]]]);
        $d = $this->launch($this->root, ['kind' => 'baseline'], ['C-04', 'C-05', 'C-06'])->assertCreated()->json('data');
        $this->assertSame(['stopped', 'provider_errors'], [$d['status'], $d['stopReason']]);
        $this->assertSame(360 + 640, $d['cases'][0]['usage']['costMicros'], 'una respuesta incompleta se cobra por su uso');

        // Un caso bueno en medio reinicia la racha.
        $this->labOpenAi();
        $this->script([['class' => 'server'], self::ACTOR_OK, self::JUDGE_PASS, ['class' => 'server']]);
        $d = $this->launch($this->root, ['kind' => 'baseline'], ['C-08', 'C-09', 'C-10'])->assertCreated()->json('data');
        $this->assertSame(['completed', ['error', 'done', 'error']], [$d['status'], array_column($d['cases'], 'status')]);
    }

    public function test_el_429_de_ritmo_retrasa_el_paso_y_no_cuenta_para_la_racha(): void
    {
        Queue::fake();
        $this->labOpenAi();
        $this->script([['class' => 'server'], ['class' => 'rate_limited', 'http' => 429], ['class' => 'server']]);
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03', 'C-04'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $runner = app(StudioLabRunner::class);

        $this->assertSame('case', $runner->step($id));
        $this->assertSame('case', $runner->step($id));
        $retrasados = Queue::pushed(StudioLabStepJob::class, fn (StudioLabStepJob $j): bool => $j->delay !== null);
        $this->assertCount(1, $retrasados, 'el 429 de ritmo retrasa el siguiente paso');
        $this->assertSame('stopped', $runner->step($id), 'server, 429 (no cuenta) y server: dos seguidos');
        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertSame(['stopped', 'provider_errors'], [$run->status, $run->stop_reason]);
        $this->assertSame(['error', 'error', 'error', 'skipped'], $run->cases()->pluck('status')->all());
        $this->assertSame('rate_limited', $run->cases()->get()[1]->error_code);
    }

    public function test_el_plazo_de_media_hora_y_el_presupuesto_agotado(): void
    {
        Queue::fake();
        // El plazo: empezó hace más de 30 minutos.
        $this->labOpenAi();
        $this->script([self::ACTOR_OK, self::JUDGE_PASS]);
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $this->travel(31)->minutes();
        $this->assertSame('closed', app(StudioLabRunner::class)->step($id));
        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertSame(['stopped', 'deadline'], [$run->status, $run->stop_reason]);
        $this->assertSame(['done', 'skipped', 'skipped'], $run->cases()->pluck('status')->all());
        $this->travelBack();

        // El presupuesto: un exceso real (la estimación se quedó corta) deja el día por encima del tope.
        $this->labOpenAi(['ultron_studio.lab.daily_budget_usd' => '0.010000']);
        $this->script([['reply' => 'Hola, ¿en qué te ayudo?', 'usage' => [20000, 400]], self::JUDGE_PASS]);
        $d = $this->launch($this->root, ['kind' => 'baseline'], ['C-04', 'C-05'])->assertCreated()->json('data');
        $id = (int) StudioLabRun::query()->where('uuid', $d['uuid'])->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $primero = StudioLabRunCase::query()->where('run_id', $id)->where('position', 1)->sole();
        $this->assertGreaterThan(0, $primero->overrun_micros, 'el real superó la reserva');
        $this->assertSame((int) $primero->cost_micros - (int) $primero->reserved_micros, (int) $primero->overrun_micros);
        $this->assertSame('closed', app(StudioLabRunner::class)->step($id));
        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertSame(['stopped', 'budget_exhausted'], [$run->status, $run->stop_reason]);
        $this->assertSame(['done', 'skipped'], $run->cases()->pluck('status')->all());
    }

    public function test_failed_cobra_la_reserva_entera_del_caso_y_encola_el_siguiente(): void
    {
        Queue::fake();
        $this->labOpenAi();
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $casos = StudioLabRunCase::query()->where('run_id', $id)->orderBy('position')->get();

        // El job murió por timeout con el caso 1 en curso.
        $this->claimByHand($id, $casos[0]->id);
        $antes = count(Queue::pushed(StudioLabStepJob::class));
        (new StudioLabStepJob($id))->failed(new TimeoutExceededException('timeout de la prueba'));
        $c1 = $casos[0]->fresh();
        $this->assertSame(['error', 'timeout', (int) $c1->reserved_micros, 0], [$c1->status, $c1->error_code, (int) $c1->cost_micros, (int) $c1->overrun_micros]);
        $this->assertCount($antes + 1, Queue::pushed(StudioLabStepJob::class), 'se encola el siguiente paso');

        // Y luego el worker se perdió con el caso 2: `worker_lost`, también con su reserva.
        $this->claimByHand($id, $casos[1]->id);
        (new StudioLabStepJob($id))->failed(new MaxAttemptsExceededException('worker perdido'));
        $c2 = $casos[1]->fresh();
        $this->assertSame(['error', 'worker_lost', (int) $c2->reserved_micros], [$c2->status, $c2->error_code, (int) $c2->cost_micros]);
        $this->assertSame('running', StudioLabRun::query()->whereKey($id)->value('status'), 'worker_lost no es del proveedor: no cuenta para la racha');

        // Sin caso en curso no hay nada que liquidar: no se encola nada (no gira en bucle).
        $antes = count(Queue::pushed(StudioLabStepJob::class));
        (new StudioLabStepJob($id))->failed(new \RuntimeException('fallo antes de reclamar'));
        $this->assertCount($antes, Queue::pushed(StudioLabStepJob::class));

        // El coste de la ejecución ya cuenta las dos reservas.
        $this->assertSame((int) $c1->reserved_micros + (int) $c2->reserved_micros, (int) StudioLabRun::query()->whereKey($id)->value('cost_micros'));
    }

    public function test_un_timeout_del_job_tras_un_error_del_proveedor_para_la_ejecucion(): void
    {
        Queue::fake();
        $this->labOpenAi();
        $this->script([['class' => 'server', 'http' => 502]]);
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));

        $this->claimByHand($id, (int) StudioLabRunCase::query()->where('run_id', $id)->where('position', 2)->value('id'));
        (new StudioLabStepJob($id))->failed(new TimeoutExceededException('timeout de la prueba'));
        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertSame(['stopped', 'provider_errors'], [$run->status, $run->stop_reason]);
        $this->assertSame(['error', 'error', 'skipped'], $run->cases()->pluck('status')->all());
    }

    public function test_dos_pasos_a_la_vez_nunca_procesan_el_mismo_caso(): void
    {
        Queue::fake();
        $this->labOpenAi();
        $guion = $this->script([self::ACTOR_OK, self::JUDGE_PASS]);
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');

        // Mientras el primer paso espera al proveedor, llega un paso duplicado.
        $duplicado = null;
        $guion->whileWaiting = function (int $llamada) use ($id, &$duplicado): void {
            if ($llamada === 1) {
                $duplicado = app(StudioLabRunner::class)->step($id);
            }
        };
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));

        $this->assertSame('busy', $duplicado, 'el duplicado vio el caso en curso y esperó');
        $this->assertCount(2, $guion->calls, 'nadie llamó dos veces por el mismo caso');
        $this->assertSame(['done', 'pending'], StudioLabRunCase::query()->where('run_id', $id)->orderBy('position')->pluck('status')->all());
        $this->assertNotEmpty(Queue::pushed(StudioLabStepJob::class, fn (StudioLabStepJob $j): bool => $j->delay !== null), 'el duplicado se volvió a encolar con retraso');
    }

    public function test_un_caso_en_curso_en_otra_ejecucion_hace_esperar_y_uno_perdido_no(): void
    {
        Queue::fake();
        $this->labFake();
        $a = (int) StudioLabRun::query()->where('uuid', $this->launch($this->root, ['kind' => 'baseline'], ['C-01'])->assertCreated()->json('data.uuid'))->value('id');
        $b = (int) StudioLabRun::query()->where('uuid', $this->launch($this->root, ['kind' => 'baseline'], ['C-02'])->assertCreated()->json('data.uuid'))->value('id');
        $casoA = (int) StudioLabRunCase::query()->where('run_id', $a)->value('id');

        $this->claimByHand($a, $casoA);
        $this->assertSame('busy', app(StudioLabRunner::class)->step($b), 'un caso a la vez en todo el sistema');
        $this->assertSame('queued', StudioLabRun::query()->whereKey($b)->value('status'));

        // Pasados 150 s, ese caso está perdido: ya no frena a nadie.
        $this->travel(StudioLabRunner::STALE_AFTER_SECONDS + 1)->seconds();
        // Su único caso se procesa y, sin más pendientes, la ejecución se cierra en el mismo paso.
        $this->assertSame('closed', app(StudioLabRunner::class)->step($b));
        $this->assertSame('done', StudioLabRunCase::query()->where('run_id', $b)->value('status'));
        $this->assertSame('completed', StudioLabRun::query()->whereKey($b)->value('status'));
    }

    public function test_bandera_apagada_o_modelo_cambiado_a_mitad_paran_la_ejecucion(): void
    {
        Queue::fake();
        $this->labOpenAi();
        $this->script([self::ACTOR_OK, self::JUDGE_PASS]);
        $a = (int) StudioLabRun::query()->where('uuid', $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02'])->assertCreated()->json('data.uuid'))->value('id');
        $b = (int) StudioLabRun::query()->where('uuid', $this->launch($this->root, ['kind' => 'baseline'], ['C-03'])->assertCreated()->json('data.uuid'))->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($a));

        $this->labOpenAi(['ultron_studio.lab.openai.model' => 'otro-modelo']);
        $this->assertSame('closed', app(StudioLabRunner::class)->step($a));
        $this->assertSame(['stopped', 'not_configured'], [StudioLabRun::query()->whereKey($a)->value('status'), StudioLabRun::query()->whereKey($a)->value('stop_reason')]);

        $this->labOff();
        $this->assertSame('closed', app(StudioLabRunner::class)->step($b));
        $this->assertSame(['stopped', 'lab_disabled'], [StudioLabRun::query()->whereKey($b)->value('status'), StudioLabRun::query()->whereKey($b)->value('stop_reason')]);
        // Terminal: ningún paso más hace nada.
        $this->assertSame('noop', app(StudioLabRunner::class)->step($b));
    }

    /** Lo que hace el reclamo del runner, sin pasar por él: la ejecución en curso y el caso reclamado. */
    private function claimByHand(int $runId, int $caseId): void
    {
        StudioLabRun::advance($runId, ['queued', 'running'], ['status' => 'running', 'started_at' => now()]);
        $this->assertTrue(StudioLabRunCase::advance($caseId, ['pending'], ['status' => 'running', 'started_at' => now()]));
    }
}
