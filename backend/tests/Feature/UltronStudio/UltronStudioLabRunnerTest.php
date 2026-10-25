<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioLabConfig;
use App\Services\UltronStudio\StudioLabCost;
use App\Services\UltronStudio\StudioLabModelClient;
use App\Services\UltronStudio\StudioLabRunner;
use App\Services\UltronStudio\StudioLabRuns;
use App\Services\UltronStudio\StudioLabStepJob;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El RUNNER del laboratorio en línea (contrato H4 §A3, §A4 y §A5; Enmienda 1):
 * el ensayo sin IA de principio a fin, y con un proveedor GUIONIZADO
 * registrado solo en esta prueba: el coste de cada llamada, la agregación (un
 * hallazgo bloqueante gana al juez; sin juez, como mucho revisión), las
 * paradas por auth, cuota y rechazo, la racha de dos errores POR CASO, el 429
 * de ritmo que retrasa y no cuenta, el plazo, el presupuesto agotado (del día,
 * y el de la ejecución sobre su coste REAL: Enmienda 4.1), un invisible en la
 * respuesta que bloquea (4.2), `failed()` cobrando la reserva, dos pasos a la
 * vez que nunca procesan el mismo caso, una cancelación confirmada después de
 * cargar el paso que no deja reclamar otro (4.5) y un paso que falla ANTES de
 * reclamar: un rescate a los 30 s, uno por ejecución, o la parada (Enmienda 5).
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

    /** Los once escenarios del catálogo (C-11 se retiró: Enmienda 6.1): la ejecución de la sonda P08. */
    private const CATALOGO = ['C-01', 'C-02', 'C-03', 'C-04', 'C-05', 'C-06', 'C-07', 'C-08', 'C-09', 'C-10', 'C-12'];

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
        // Enmienda 4.1: el motivo se reutiliza y el resumen dice qué tope se alcanzó.
        $fin = StudioLabEvent::query()->where('event', 'run.finished')->where('subject_uuid', $d['uuid'])->sole();
        $this->assertSame('La ejecución se detuvo (budget_exhausted): lo comprometido del día superó el tope diario', $fin->summary);
        $this->assertSame(['kind' => 'daily', 'capMicros' => 10000], $fin->diff['budgetCap']);
        $this->assertSame(['kind' => 'daily', 'capMicros' => 10000], $run->summary['budgetCap']);
    }

    public function test_el_tope_de_la_ejecucion_se_aplica_al_coste_real_y_salta_el_resto(): void
    {
        // La sonda P08 de api-security: tope por ejecución de 0,05 US$, los
        // casos del catálogo con una estimación que cabe (con doce casos eran
        // 47 824 micro-US$; con los once de 2026-10-20.1, algo menos), la
        // persona acepta ese máximo y un proveedor factura el TRIPLE de la
        // entrada estimada (texto denso en tokens) y toda la salida. Antes la
        // ejecución terminaba `completed` con 1,87 veces el tope. Ahora el tope
        // es el menor entre el del servidor y el aceptado (aquí, el aceptado) y
        // la ejecución para justo después del caso que lo supera.
        Queue::fake();
        $this->labOpenAi(['ultron_studio.lab.run_budget_usd' => '0.05']);
        $d = $this->launch($this->root, ['kind' => 'baseline'], self::CATALOGO)->assertCreated()->json('data');
        $aceptado = (int) StudioLabRun::query()->where('uuid', $d['uuid'])->value('accepted_max_micros');
        $this->assertSame($d['cost']['estimateMaxMicros'], $aceptado);
        $this->assertLessThan(50000, $aceptado, 'la estimación cabe en el tope: se admite');
        $guion = $this->script($this->tripleInput($d['uuid']));

        $run = $this->drain($d['uuid'], 100);

        $this->assertSame(['stopped', 'budget_exhausted'], [$run->status, $run->stop_reason]);
        $hechos = $this->assertStoppedRightAfterCrossing($run, $aceptado);
        $this->assertSame(2 * (count(self::CATALOGO) - $hechos), $guion->remaining(), 'ningún caso saltado llegó al proveedor');
        $fin = StudioLabEvent::query()->where('event', 'run.finished')->where('subject_uuid', $d['uuid'])->sole();
        $this->assertSame('La ejecución se detuvo (budget_exhausted): el coste real superó el máximo aceptado al lanzarla', $fin->summary);
        $this->assertSame(['kind' => 'accepted', 'capMicros' => $aceptado], $fin->diff['budgetCap']);
        $this->assertSame(['kind' => 'accepted', 'capMicros' => $aceptado], $run->summary['budgetCap']);
        $vista = $this->lab('GET', "runs/{$d['uuid']}", $this->root)->assertOk()->json('data');
        $this->assertSame(['stopped', 'budget_exhausted', null, (int) $run->cost_micros], [$vista['status'], $vista['stopReason'], $vista['aiRecommendation'], $vista['cost']['actualMicros']]);
        // Enmienda 6.3 (I-3): el detalle y la lista dicen qué tope la paró.
        $this->assertSame(['kind' => 'accepted', 'capMicros' => $aceptado], $vista['budgetCap']);
        $this->assertSame(['kind' => 'accepted', 'capMicros' => $aceptado], collect($this->lab('GET', 'runs', $this->root)->json('data.data'))->firstWhere('uuid', $d['uuid'])['budgetCap']);
    }

    public function test_con_un_maximo_aceptado_holgado_manda_el_tope_por_ejecucion(): void
    {
        // La misma sonda, pero la persona acepta 60 000 (más que el tope del
        // servidor, que es lo que ella no puede subir): corta a los 50 000.
        Queue::fake();
        $this->labOpenAi(['ultron_studio.lab.run_budget_usd' => '0.05']);
        $d = $this->lab('POST', 'runs', $this->root, [
            'target' => ['kind' => 'baseline'],
            'scenarioUuids' => array_map(fn (string $c): string => $this->scenarioUuid($c), self::CATALOGO),
            'acceptedMaxCostMicros' => 60000,
            'clientRequestId' => (string) Str::uuid(),
        ])->assertCreated()->json('data');
        $this->script($this->tripleInput($d['uuid']));

        $run = $this->drain($d['uuid'], 100);

        $this->assertSame(['stopped', 'budget_exhausted'], [$run->status, $run->stop_reason]);
        $this->assertStoppedRightAfterCrossing($run, 50000);
        $fin = StudioLabEvent::query()->where('event', 'run.finished')->where('subject_uuid', $d['uuid'])->sole();
        $this->assertSame('La ejecución se detuvo (budget_exhausted): el coste real superó el tope por ejecución', $fin->summary);
        $this->assertSame(['kind' => 'run', 'capMicros' => 50000], $fin->diff['budgetCap']);
        $this->assertSame(['kind' => 'run', 'capMicros' => 50000], $run->summary['budgetCap']);
    }

    public function test_lo_que_liquida_failed_cuenta_y_el_tope_es_el_de_la_configuracion_de_ahora(): void
    {
        // `failed()` cobra la reserva entera del caso; el tope por ejecución es
        // el de la configuración de AHORA (como el del día). Si se baja a mitad,
        // el paso siguiente mira el coste de la fila y para antes de reclamar.
        Queue::fake();
        $this->labOpenAi();
        $this->script([]);
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-05', 'C-06'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $primero = StudioLabRunCase::query()->where('run_id', $id)->where('position', 1)->sole();
        $this->assertGreaterThan(1000, (int) $primero->reserved_micros);
        $this->claimByHand($id, (int) $primero->id);
        (new StudioLabStepJob($id))->failed(new MaxAttemptsExceededException('worker perdido'));
        $this->assertSame((int) $primero->reserved_micros, (int) StudioLabRun::query()->whereKey($id)->value('cost_micros'));

        $this->labOpenAi(['ultron_studio.lab.run_budget_usd' => '0.001']);
        $this->assertSame('closed', app(StudioLabRunner::class)->step($id));

        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertSame(['stopped', 'budget_exhausted'], [$run->status, $run->stop_reason]);
        $this->assertSame(['error', 'skipped'], $run->cases()->pluck('status')->all());
        $fin = StudioLabEvent::query()->where('event', 'run.finished')->where('subject_uuid', $uuid)->sole();
        $this->assertSame(['kind' => 'run', 'capMicros' => 1000], $fin->diff['budgetCap']);
    }

    public function test_un_invisible_en_la_respuesta_bloquea_aunque_el_juez_apruebe(): void
    {
        // El precio escrito al revés entre controles bidi (sonda de security,
        // B-2): el CRM pintaría «$80.000» y las guardas del motor no lo ven.
        $this->labOpenAi();
        $reply = "Vale \u{202E}000.08\$\u{202C} al mes";
        $this->script([['reply' => $reply, 'usage' => [900, 40]], self::JUDGE_PASS]);

        $d = $this->launch($this->root, ['kind' => 'baseline'], ['C-02'])->assertCreated()->json('data');

        $caso = $d['cases'][0];
        $this->assertSame('pass', $caso['judge']['criteria'][0]['verdict'], 'el juez lo dio por bueno');
        $this->assertSame(['fail', ['lab-invisibles']], [$caso['outcome'], array_column($caso['checks'], 'id')]);
        $this->assertSame(['studio', 'blocking', null], [$caso['checks'][0]['source'], $caso['checks'][0]['level'], $caso['checks'][0]['quote']]);
        $this->assertSame($reply, $caso['reply'], 'la respuesta se guarda y se devuelve literal: el CRM la interpola');
        $this->assertSame('reject', $d['aiRecommendation']);
    }

    public function test_una_cancelacion_confirmada_despues_de_cargar_el_paso_no_deja_reclamar(): void
    {
        // HIP-1 del tester (Enmienda 4.5). El orden de dos sesiones, simulado en
        // una: el paso ya cargó la ejecución (sin petición de cancelar) cuando
        // la cancelación se confirma, justo antes de que el paso tome el cerrojo
        // de los reclamos. La prueba con dos sesiones de verdad está en
        // UltronStudioLabCancelLockTest (PostgreSQL).
        Queue::fake();
        $this->labOpenAi();
        $guion = $this->script([self::ACTOR_OK, self::JUDGE_PASS, self::ACTOR_OK, self::JUDGE_PASS, self::ACTOR_OK, self::JUDGE_PASS]);

        // 1) `cancel()` de verdad, sin ningún caso en vuelo: desde la Enmienda 5
        // la cierra en el acto, así que el paso la encuentra cerrada y no reclama.
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $resultado = $this->stepWithMidStepCancel($id, fn () => app(StudioLabRuns::class)->cancel(new StudioAccess($this->root), StudioLabRun::query()->findOrFail($id)));

        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertNotNull($run->cancel_requested_at);
        $this->assertSame(['gone', 'cancelled'], [$resultado, $run->status], 'la cerró la cancelación; el paso no reclamó');
        $this->assertSame(['done', 'skipped', 'skipped'], $run->cases()->pluck('status')->all());
        $this->assertCount(2, $guion->calls, 'tras la cancelación no se llamó al proveedor (ni se pagó) otro caso');
        // Por estado y no por hora: el reclamo fecha el caso con la hora del INICIO del paso, de antes de la cancelación.
        $this->assertSame([1], StudioLabRunCase::query()->where('run_id', $id)->whereNotNull('started_at')->pluck('position')->all(), 'solo el primer caso llegó a empezar');
        $this->assertSame(['run.created', 'run.cancel_requested', 'run.finished'], StudioLabEvent::query()->where('subject_uuid', $uuid)->orderBy('id')->pluck('event')->all());

        // 2) Una petición solo MARCADA (la que deja una cancelación que llegó con
        // un caso en vuelo, ya terminado): la relee el reclamo, bajo el cerrojo.
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-04', 'C-05', 'C-06'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $resultado = $this->stepWithMidStepCancel($id, fn () => $this->assertSame(1, StudioLabRun::query()->whereKey($id)->whereNull('cancel_requested_at')
            ->update(['cancel_requested_at' => now(), 'cancel_requested_by_name' => 'Otra sesión'])));

        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertSame(['closed', 'cancelled'], [$resultado, $run->status], 'el paso reclamó con la cancelación ya confirmada');
        $this->assertSame(['done', 'skipped', 'skipped'], $run->cases()->pluck('status')->all());
        $this->assertCount(4, $guion->calls, 'tras la cancelación no se llamó al proveedor (ni se pagó) otro caso');
        $this->assertSame([1], StudioLabRunCase::query()->where('run_id', $id)->whereNotNull('started_at')->pluck('position')->all(), 'solo el primer caso llegó a empezar');
    }

    /** Un paso en el que `$cancel` ocurre justo después de cargar la ejecución (en la consulta de sus casos en curso). */
    private function stepWithMidStepCancel(int $id, \Closure $cancel): string
    {
        $armada = true;
        DB::listen(function (QueryExecuted $q) use (&$armada, $id, $cancel): void {
            if ($armada && str_contains($q->sql, '"ultron_studio_lab_run_cases"') && in_array('running', $q->bindings, true) && in_array($id, $q->bindings, true)) {
                $armada = false;
                $cancel();
            }
        });
        $resultado = app(StudioLabRunner::class)->step($id);
        $this->assertFalse($armada, 'la cancelación no llegó en mitad del paso');

        return $resultado;
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

        // Sin caso en curso no hay nada que liquidar: el paso falló antes de
        // reclamar y se encola UN paso de rescate a los 30 s (Enmienda 5; el
        // resto, en test_un_paso_que_falla_antes_de_reclamar_…).
        $antes = count(Queue::pushed(StudioLabStepJob::class));
        (new StudioLabStepJob($id))->failed(new \RuntimeException('fallo antes de reclamar'));
        $this->assertCount($antes + 1, Queue::pushed(StudioLabStepJob::class));
        $this->assertNotNull(Queue::pushed(StudioLabStepJob::class)->last()->delay);

        // El coste de la ejecución ya cuenta las dos reservas.
        $this->assertSame((int) $c1->reserved_micros + (int) $c2->reserved_micros, (int) StudioLabRun::query()->whereKey($id)->value('cost_micros'));
    }

    public function test_un_paso_que_falla_antes_de_reclamar_se_reencola_una_vez_y_si_vuelve_a_fallar_para(): void
    {
        // H-P2-1 del tester (Enmienda 5.1(c)): el paso del caso 2 falla antes de
        // reclamar (la base se cae mientras espera el cerrojo). Antes, `failed()`
        // no hacía nada y la ejecución quedaba en curso para siempre, sin paso.
        Queue::fake();
        $this->labOpenAi();
        $guion = $this->script([self::ACTOR_OK, self::JUDGE_PASS]);
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $antes = count(Queue::pushed(StudioLabStepJob::class));

        $e = $this->stepFailsBeforeClaiming($id);
        $this->assertCount($antes, Queue::pushed(StudioLabStepJob::class), 'el paso que falla no encola nada');
        (new StudioLabStepJob($id))->failed($e);

        // Un paso de rescate a los 30 s, y el intento en el resumen de la ejecución viva.
        $rescates = Queue::pushed(StudioLabStepJob::class)->slice($antes)->values();
        $this->assertCount(1, $rescates);
        $this->assertSame($id, $rescates[0]->runId);
        $this->assertEqualsWithDelta(StudioLabRunner::REQUEUE_DELAY_SECONDS, now()->diffInSeconds($rescates[0]->delay), 2);
        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertSame('running', $run->status);
        $intento = $run->summary['recovery'];
        $this->assertSame(['attempts', 'at', 'class'], array_keys($intento));
        $this->assertSame([1, 'internal_error'], [$intento['attempts'], $intento['class']]);

        // El paso de rescate TAMBIÉN falla antes de reclamar: para con
        // `provider_errors`, los pendientes se saltan y el resumen lo explica.
        (new StudioLabStepJob($id))->failed($this->stepFailsBeforeClaiming($id));

        $run->refresh();
        $this->assertSame(['stopped', 'provider_errors'], [$run->status, $run->stop_reason]);
        $this->assertSame(['done', 'skipped', 'skipped'], $run->cases()->pluck('status')->all());
        $this->assertSame(['attempts' => 1, 'at' => $intento['at'], 'class' => 'internal_error', 'reason' => 'step_failed_before_claim', 'lastClass' => 'internal_error'],
            $run->summary['recovery'], 'el resumen conserva el intento y dice por qué paró');
        $this->assertSame($run->results_hash, StudioLabRuns::resultsHash($run, $run->cases()->get()));
        $this->assertCount($antes + 1, Queue::pushed(StudioLabStepJob::class), 'no hay un segundo rescate');
        $fin = StudioLabEvent::query()->where('event', 'run.finished')->where('subject_uuid', $uuid)->sole();
        $this->assertSame('La ejecución se detuvo (provider_errors): el paso falló dos veces sin llegar a reclamar un caso', $fin->summary);
        $this->assertSame('step_failed_before_claim', $fin->diff['recovery']['reason']);
        $this->assertCount(2, $guion->calls, 'nadie llamó al proveedor por los casos saltados');
    }

    public function test_el_rescate_es_uno_por_ejecucion_aunque_el_rescate_haya_avanzado(): void
    {
        // Una vez por ejecución (Enmienda 5.1(c)): el rescate procesó el caso 2,
        // pero el intento sigue anotado; el siguiente fallo antes de reclamar para.
        Queue::fake();
        $this->labOpenAi();
        $this->script([self::ACTOR_OK, self::JUDGE_PASS, self::ACTOR_OK, self::JUDGE_PASS]);
        $uuid = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03', 'C-04'])->assertCreated()->json('data.uuid');
        $id = (int) StudioLabRun::query()->where('uuid', $uuid)->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        (new StudioLabStepJob($id))->failed($this->stepFailsBeforeClaiming($id));
        $this->assertSame(1, StudioLabRun::query()->findOrFail($id)->summary['recovery']['attempts']);

        // El rescate llega y procesa el caso 2; el paso del 3 vuelve a fallar antes de reclamar.
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $this->assertSame(1, StudioLabRun::query()->findOrFail($id)->summary['recovery']['attempts'], 'liquidar un caso no borra el intento');
        $antes = count(Queue::pushed(StudioLabStepJob::class));
        (new StudioLabStepJob($id))->failed($this->stepFailsBeforeClaiming($id));

        $run = StudioLabRun::query()->findOrFail($id);
        $this->assertSame(['stopped', 'provider_errors', 'step_failed_before_claim'], [$run->status, $run->stop_reason, $run->summary['recovery']['reason']]);
        $this->assertSame(['done', 'done', 'skipped', 'skipped'], $run->cases()->pluck('status')->all());
        $this->assertCount($antes, Queue::pushed(StudioLabStepJob::class), 'sin segundo rescate');
    }

    public function test_sin_caso_en_curso_failed_aplica_el_plazo_y_el_rescate_aplica_la_caducidad_y_la_cancelacion(): void
    {
        Queue::fake();
        $this->labFake();

        // En curso con el plazo vencido: `deadline` en el acto, el motivo con
        // el que ya la presentaba la lectura; sin rescate.
        $enCurso = (int) StudioLabRun::query()->where('uuid', $this->launch($this->root, ['kind' => 'baseline'], ['C-02', 'C-03'])->assertCreated()->json('data.uuid'))->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($enCurso));
        $e = $this->stepFailsBeforeClaiming($enCurso);
        $this->travel(StudioLabConfig::RUN_DEADLINE_MINUTES + 1)->minutes();
        $antes = count(Queue::pushed(StudioLabStepJob::class));
        (new StudioLabStepJob($enCurso))->failed($e);
        $run = StudioLabRun::query()->findOrFail($enCurso);
        $this->assertSame(['stopped', 'deadline', ['done', 'skipped']], [$run->status, $run->stop_reason, $run->cases()->pluck('status')->all()]);
        $this->assertCount($antes, Queue::pushed(StudioLabStepJob::class), 'sin rescate');
        $this->travelBack();

        // En cola desde hace más de 30 min (su primer paso falló antes de
        // reclamar): no tiene plazo (nunca empezó), así que va el rescate, y
        // el rescate la para con `expired` como cualquier paso.
        $enCola = (int) StudioLabRun::query()->where('uuid', $this->launch($this->root, ['kind' => 'baseline'], ['C-01'])->assertCreated()->json('data.uuid'))->value('id');
        $e = $this->stepFailsBeforeClaiming($enCola);
        $this->travel(StudioLabConfig::QUEUED_EXPIRY_MINUTES + 1)->minutes();
        $antes = count(Queue::pushed(StudioLabStepJob::class));
        (new StudioLabStepJob($enCola))->failed($e);
        $this->assertCount($antes + 1, Queue::pushed(StudioLabStepJob::class), 'el rescate');
        $this->assertSame('closed', app(StudioLabRunner::class)->step($enCola));
        $run = StudioLabRun::query()->findOrFail($enCola);
        $this->assertSame(['stopped', 'expired', ['skipped']], [$run->status, $run->stop_reason, $run->cases()->pluck('status')->all()]);
        $this->travelBack();

        // Con la cancelación pedida (llegó con el caso 1 en vuelo): el rescate la cierra `cancelled`.
        $this->labOpenAi();
        $guion = $this->script([self::ACTOR_OK, self::JUDGE_PASS]);
        $pedida = (int) StudioLabRun::query()->where('uuid', $this->launch($this->root, ['kind' => 'baseline'], ['C-04', 'C-05'])->assertCreated()->json('data.uuid'))->value('id');
        $guion->whileWaiting = function (int $llamada) use ($pedida): void {
            if ($llamada === 1) {
                app(StudioLabRuns::class)->cancel(new StudioAccess($this->root), StudioLabRun::query()->findOrFail($pedida));
            }
        };
        $this->assertSame('case', app(StudioLabRunner::class)->step($pedida));
        $this->assertSame('running', StudioLabRun::query()->whereKey($pedida)->value('status'));
        $e = $this->stepFailsBeforeClaiming($pedida);
        $antes = count(Queue::pushed(StudioLabStepJob::class));
        (new StudioLabStepJob($pedida))->failed($e);
        $this->assertCount($antes + 1, Queue::pushed(StudioLabStepJob::class), 'el rescate');
        $this->assertSame('closed', app(StudioLabRunner::class)->step($pedida));
        $run = StudioLabRun::query()->findOrFail($pedida);
        $this->assertSame(['cancelled', null, ['done', 'skipped']], [$run->status, $run->stop_reason, $run->cases()->pluck('status')->all()]);
        $this->assertCount(2, $guion->calls);
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

    /**
     * El guion de la sonda P08: cada caso factura el TRIPLE de la entrada
     * estimada (actor y juez) y toda la salida.
     *
     * @return list<array<string, mixed>>
     */
    private function tripleInput(string $uuid): array
    {
        $run = StudioLabRun::query()->where('uuid', $uuid)->sole();
        $precios = ['input' => 400000, 'output' => 1600000];
        $guion = [];
        foreach ($run->cases()->get() as $c) {
            $e = StudioLabCost::estimateScenario($run->target_snapshot, $c->scenario_snapshot, 400, 900, $precios);
            $guion[] = ['reply' => 'Hola, te ayudo con gusto.', 'usage' => [3 * $e['actorInputTokens'], 400]];
            $guion[] = ['judge' => 'pass', 'usage' => [3 * $e['judgeInputTokens'], 900]];
        }

        return $guion;
    }

    /**
     * La ejecución paró JUSTO después del caso que llevó su coste real por
     * encima de `$cap`: hasta el anterior cabía, los que siguen se saltaron.
     * Devuelve cuántos casos se hicieron.
     */
    private function assertStoppedRightAfterCrossing(StudioLabRun $run, int $cap): int
    {
        $casos = $run->cases()->get();
        $hechos = $casos->where('status', 'done')->count();
        $this->assertGreaterThanOrEqual(2, $hechos, 'la prueba necesita algún caso que quepa antes del que cruza');
        $this->assertLessThan($casos->count(), $hechos, 'si no salta nada, el tope no se aplicó al coste real');
        $this->assertSame(array_merge(array_fill(0, $hechos, 'done'), array_fill(0, $casos->count() - $hechos, 'skipped')), $casos->pluck('status')->all());
        $costes = $casos->take($hechos)->map(static fn (StudioLabRunCase $c): int => (int) $c->cost_micros)->all();
        $this->assertLessThanOrEqual($cap, array_sum(array_slice($costes, 0, -1)), 'hasta el caso anterior cabía en el tope');
        $this->assertGreaterThan($cap, array_sum($costes), 'el último caso hecho es el que superó el tope');
        $this->assertSame(array_sum($costes), (int) $run->cost_micros);

        return $hechos;
    }
}
