<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\RolePermission;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Models\UltronStudio\StudioRule;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioLabConfig;
use App\Services\UltronStudio\StudioLabRunner;
use App\Services\UltronStudio\StudioLabRuns;
use App\Services\UltronStudio\StudioLabStepJob;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las ejecuciones del laboratorio desde la API (contrato H4 §A3 y §A7;
 * Enmiendas 2 y 3): estimar sin escribir, los topes (por ejecución, del día y
 * de ejecuciones), la reserva, el día de Bogotá con el reloj en UTC, los tres
 * objetivos, la visibilidad de las ejecuciones sobre un borrador ajeno, la
 * caducidad presentada sin escribir, cancelar, la repetición y el objetivo con
 * datos personales. Y la ejecución HUÉRFANA (H-P2-1 del tester, Enmienda 5):
 * cancelar sin caso en vuelo la cierra en el acto y, vencido el plazo, se
 * presenta detenida sin escribir hasta que algo la materializa.
 *
 * Con `Queue::fake()` las ejecuciones se quedan en cola: así se ve la reserva
 * y el estado intermedio. El runner en línea va en `UltronStudioLabRunnerTest`.
 */
class UltronStudioLabRunsTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const ACTOR_OK = ['reply' => 'Hola, ¿en qué te puedo ayudar hoy?', 'usage' => [900, 40]];

    private const JUDGE_PASS = ['judge' => 'pass', 'usage' => [1500, 300]];

    private Admin $ana;

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importBaseline();
        $this->importLabScenarios();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
    }

    public function test_estimar_no_escribe_nada_y_trae_su_forma(): void
    {
        $this->labOpenAi();
        $v = (string) $this->createVersion($this->ana)->assertCreated()->json('data.uuid');
        $cuerpo = ['target' => self::versionTarget($v), 'scenarioUuids' => [$this->scenarioUuid('C-01'), $this->scenarioUuid('C-04')]];

        $escrituras = [];
        DB::listen(function (QueryExecuted $q) use (&$escrituras): void {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate)\b/i', $q->sql) === 1 && ! str_contains($q->sql, 'admin_sessions')) {
                $escrituras[] = $q->sql;
            }
        });
        $e = $this->lab('POST', 'estimate', $this->root, $cuerpo)->assertOk()->json('data');

        $this->assertSame([], $escrituras, 'La estimación escribió.');
        $this->assertSame(['mode', 'model', 'promptVersion', 'cases', 'estimate', 'budget', 'allowed', 'refusal', 'excludedRules'], array_keys($e));
        $this->assertSame(['day', 'dailyCapMicros', 'committedMicros', 'remainingMicros', 'runCapMicros', 'runsToday', 'maxRunsPerDay'], array_keys($e['budget']));
        $this->assertSame(['openai', 'gpt-lab-pruebas', '2026-10-19.1', 2], [$e['mode'], $e['model'], $e['promptVersion'], $e['cases']]);
        $this->assertGreaterThan(0, $e['estimate']['maxMicros']);
        $this->assertLessThanOrEqual($e['estimate']['maxMicros'], $e['estimate']['expectedMicros']);
        $this->assertSame([true, null, []], [$e['allowed'], $e['refusal'], $e['excludedRules']]);
        $this->assertSame([2000000, 0, 2000000, 500000], [$e['budget']['dailyCapMicros'], $e['budget']['committedMicros'], $e['budget']['remainingMicros'], $e['budget']['runCapMicros']]);
        $this->assertSame(0, StudioLabRun::query()->count());

        // Quien no tiene la llave de lanzar ve la estimación y el motivo por el que no puede.
        $sinLlave = $this->lab('POST', 'estimate', $this->ana, $cuerpo)->assertOk()->json('data');
        $this->assertSame([false, 'forbidden'], [$sinLlave['allowed'], $sinLlave['refusal']['code']]);
    }

    public function test_la_estimacion_dice_que_s_nnn_no_caben(): void
    {
        $this->labFake();
        for ($i = 1; $i <= 22; $i++) {
            $campos = ['description' => "Regla viva {$i}", 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'],
                'refs' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []], 'origin' => null, 'conditions' => null];
            StudioRule::query()->forceCreate($campos + ['code' => sprintf('S-%03d', $i), 'source' => 'studio', 'enforcement' => 'none', 'status' => 'draft',
                'revision' => 1, 'content_hash' => StudioContentHasher::ruleHash($campos), 'client_request_id' => (string) Str::uuid()]);
        }
        $revision = $this->saveDraft($this->root)->assertOk()->json('data.revision');

        $e = $this->lab('POST', 'estimate', $this->root, ['target' => ['kind' => 'draft', 'revision' => $revision], 'scenarioUuids' => [$this->scenarioUuid('C-01')]])->assertOk()->json('data');
        $this->assertSame(['S-021', 'S-022'], $e['excludedRules']);
    }

    public function test_estimate_changed_y_el_tope_por_ejecucion(): void
    {
        Queue::fake();
        $this->labOpenAi();
        $cuerpo = ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01'), $this->scenarioUuid('C-02')]];
        $maximo = $this->lab('POST', 'estimate', $this->root, $cuerpo)->assertOk()->json('data.estimate.maxMicros');

        $r = $this->lab('POST', 'runs', $this->root, $cuerpo + ['acceptedMaxCostMicros' => $maximo - 1, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'estimate_changed')->assertJsonPath('estimate.estimate.maxMicros', $maximo);
        $this->assertSame(['mode', 'model', 'promptVersion', 'cases', 'estimate', 'budget', 'allowed', 'refusal', 'excludedRules'], array_keys($r->json('estimate')));

        $this->labOpenAi(['ultron_studio.lab.run_budget_usd' => '0.000001']);
        $this->lab('POST', 'runs', $this->root, $cuerpo + ['acceptedMaxCostMicros' => $maximo, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'lab_run_budget_exceeded')->assertJsonPath('requiredMicros', $maximo)->assertJsonPath('capMicros', 1);
        $this->assertSame('lab_run_budget_exceeded', $this->lab('POST', 'estimate', $this->root, $cuerpo)->json('data.refusal.code'));
        $this->assertSame(0, StudioLabRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_la_reserva_del_dia_y_el_409_del_presupuesto(): void
    {
        Queue::fake();
        $this->labOpenAi(['ultron_studio.lab.daily_budget_usd' => '0.010000']);
        $dos = ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01'), $this->scenarioUuid('C-02')]];
        $primera = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02'])->assertCreated()->json('data');
        $reserva = $primera['cost']['estimateMaxMicros'];
        $this->assertSame($reserva, (int) StudioLabRunCase::query()->sum('reserved_micros'));
        $this->assertGreaterThan(5000, $reserva, 'el caso de la prueba necesita una reserva grande');

        $presupuesto = $this->lab('GET', 'status', $this->root)->json('data.budget');
        $this->assertSame([0, $reserva, 10000 - $reserva], [$presupuesto['spentMicros'], $presupuesto['reservedMicros'], $presupuesto['remainingMicros']]);

        $maximo = $this->lab('POST', 'estimate', $this->root, $dos)->json('data.estimate.maxMicros');
        $this->lab('POST', 'runs', $this->root, $dos + ['acceptedMaxCostMicros' => $maximo, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)
            ->assertJsonPath('code', 'lab_daily_budget_exceeded')
            ->assertJsonPath('requiredMicros', $maximo)
            ->assertJsonPath('remainingMicros', 10000 - $reserva);
        $this->assertSame(1, StudioLabRun::query()->count());
    }

    public function test_las_ejecuciones_del_dia_cuentan_tambien_las_de_ensayo(): void
    {
        Queue::fake();
        $this->labFake(['ultron_studio.lab.max_runs_per_day' => 2]);
        $this->launch($this->root, ['kind' => 'baseline'], ['C-01'])->assertCreated();
        $this->launch($this->root, ['kind' => 'baseline'], ['C-02'])->assertCreated();

        $cuerpo = ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-03')]];
        $this->assertSame('lab_daily_runs_exceeded', $this->lab('POST', 'estimate', $this->root, $cuerpo)->json('data.refusal.code'));
        $this->lab('POST', 'runs', $this->root, $cuerpo + ['acceptedMaxCostMicros' => 0, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'lab_daily_runs_exceeded')->assertJsonPath('runsToday', 2)->assertJsonPath('maxRunsPerDay', 2);
    }

    public function test_el_dia_del_presupuesto_es_el_de_bogota_con_el_reloj_en_utc(): void
    {
        Queue::fake();
        $this->labFake(['ultron_studio.lab.max_runs_per_day' => 1]);

        // 23:30 en Bogotá es el día siguiente en UTC (04:30): el presupuesto sigue en el 19.
        $this->travelTo(Carbon::parse('2026-10-19 23:30', 'America/Bogota')->utc());
        $this->assertSame('2026-10-20', now()->toDateString(), 'el reloj de la prueba va en UTC');
        $this->launch($this->root, ['kind' => 'baseline'], ['C-01'])->assertCreated();
        $this->assertSame('2026-10-19', StudioLabRun::query()->value('budget_day'));
        $this->assertSame(['2026-10-19', 1], [$this->lab('GET', 'status', $this->root)->json('data.budget.day'), $this->lab('GET', 'status', $this->root)->json('data.budget.runsToday')]);
        $cuerpo = ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-02')], 'acceptedMaxCostMicros' => 0];
        $this->lab('POST', 'runs', $this->root, $cuerpo + ['clientRequestId' => (string) Str::uuid()])->assertStatus(409)->assertJsonPath('code', 'lab_daily_runs_exceeded');

        // Pasada la medianoche de Bogotá, día nuevo.
        $this->travelTo(Carbon::parse('2026-10-20 00:30', 'America/Bogota')->utc());
        $this->assertSame(['2026-10-20', 0], [$this->lab('GET', 'status', $this->root)->json('data.budget.day'), $this->lab('GET', 'status', $this->root)->json('data.budget.runsToday')]);
        $this->lab('POST', 'runs', $this->root, $cuerpo + ['clientRequestId' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(['2026-10-19', '2026-10-20'], StudioLabRun::query()->orderBy('id')->pluck('budget_day')->all());
        $this->assertSame(['2026-10-19', '2026-10-20'], StudioLabRunCase::query()->orderBy('id')->pluck('budget_day')->all());
    }

    public function test_los_tres_objetivos_y_sus_rechazos(): void
    {
        Queue::fake();
        $this->labFake();
        $escenarios = [$this->scenarioUuid('C-01')];
        $estimar = fn (array $target) => $this->lab('POST', 'estimate', $this->root, ['target' => $target, 'scenarioUuids' => $escenarios]);

        // Versión íntegra; con la huella alterada, 409; que no existe, 404.
        $v = (string) $this->createVersion($this->ana)->assertCreated()->json('data.uuid');
        $run = $this->launch($this->root, self::versionTarget($v), ['C-01'])->assertCreated()->json('data');
        $this->assertSame(['version', $v], [$run['target']['kind'], $run['target']['uuid']]);
        $rota = $this->seedVersion($this->ana, $this->baseline(), 'REVIEW_REQUIRED', str_repeat('0', 64));
        $estimar(self::versionTarget($rota->uuid))->assertStatus(409)->assertJsonPath('code', 'integrity_mismatch');
        $estimar(self::versionTarget((string) Str::uuid()))->assertStatus(404)->assertJsonPath('code', 'version_not_found');

        // Borrador: el PROPIO y con su revisión.
        $estimar(['kind' => 'draft', 'revision' => 1])->assertStatus(409)->assertJsonPath('code', 'draft_conflict')->assertJsonPath('current.revision', 0);
        $revision = $this->saveDraft($this->root)->assertOk()->json('data.revision');
        $estimar(['kind' => 'draft', 'revision' => $revision + 1])->assertStatus(409)->assertJsonPath('code', 'draft_conflict')->assertJsonPath('current.revision', $revision);
        $borrador = $this->launch($this->root, ['kind' => 'draft', 'revision' => $revision], ['C-01'])->assertCreated()->json('data');
        $this->assertSame(['draft', null, "Borrador · revisión {$revision}"], [$borrador['target']['kind'], $borrador['target']['uuid'], $borrador['target']['label']]);

        // Línea base.
        $base = $this->launch($this->root, ['kind' => 'baseline'], ['C-01'])->assertCreated()->json('data');
        $this->assertSame(['baseline', null, 'Línea base del código'], [$base['target']['kind'], $base['target']['uuid'], $base['target']['label']]);

        // La forma del objetivo.
        $this->lab('POST', 'estimate', $this->root, ['target' => ['kind' => 'version'], 'scenarioUuids' => $escenarios])->assertStatus(422)->assertJsonValidationErrors('target.uuid');
        $this->lab('POST', 'estimate', $this->root, ['target' => ['kind' => 'draft', 'revision' => 1, 'uuid' => $v], 'scenarioUuids' => $escenarios])->assertStatus(422)->assertJsonValidationErrors('target.uuid');
        $this->lab('POST', 'estimate', $this->root, ['target' => ['kind' => 'otro'], 'scenarioUuids' => $escenarios])->assertStatus(422)->assertJsonValidationErrors('target.kind');
    }

    public function test_una_ejecucion_sobre_el_borrador_ajeno_es_un_404_identico_al_de_un_uuid_inexistente(): void
    {
        Queue::fake();
        $this->labFake();
        // La dueña: puede lanzar, sin `audit.view`.
        $duena = $this->person(Admin::ROLE_ADMINISTRADOR, 'Dueña del borrador');
        $this->grant($duena, ['ultron.view', 'ultron.edit_draft', 'ultron.lab_run']);
        $revision = $this->saveDraft($duena)->assertOk()->json('data.revision');
        $run = $this->launch($duena, ['kind' => 'draft', 'revision' => $revision], ['C-01'])->assertCreated()->json('data');
        $this->launch($duena, ['kind' => 'baseline'], ['C-02'])->assertCreated();

        // Otra persona con `view`, `lab_run` y `approve`, sin `audit.view`.
        $otra = $this->person(Admin::ROLE_ADMINISTRADOR, 'Otra persona');
        $this->grant($otra, ['ultron.view', 'ultron.lab_run', 'ultron.approve']);
        $inexistente = (string) Str::uuid();
        foreach ([['GET', ''], ['POST', '/cancel'], ['POST', '/verdict']] as [$metodo, $sufijo]) {
            $cuerpo = $sufijo === '/verdict' ? ['decision' => 'rejected', 'note' => 'Nota de prueba', 'resultsHash' => str_repeat('a', 64)] : [];
            $ajena = $this->lab($metodo, "runs/{$run['uuid']}{$sufijo}", $otra, $cuerpo)->assertStatus(404);
            $nada = $this->lab($metodo, "runs/{$inexistente}{$sufijo}", $otra, $cuerpo)->assertStatus(404);
            $this->assertSame($nada->json(), $ajena->json(), "{$metodo} runs/{uuid}{$sufijo}");
            $this->assertSame('run_not_found', $ajena->json('code'));
        }
        $this->assertNotContains($run['uuid'], array_column($this->lab('GET', 'runs', $otra)->json('data.data'), 'uuid'));
        $this->assertSame(1, $this->lab('GET', 'runs', $otra)->json('data.total'));
        $this->assertNotContains($run['uuid'], array_column($this->lab('GET', 'events?subjectType=run', $otra)->json('data.data'), 'subjectUuid'));
        $this->assertSame('queued', StudioLabRun::query()->where('uuid', $run['uuid'])->value('status'), 'nadie la canceló');

        // La dueña y quien tiene `audit.view` (Super Admin) sí la ven.
        $this->lab('GET', "runs/{$run['uuid']}", $duena)->assertOk();
        $this->lab('GET', "runs/{$run['uuid']}", $this->root)->assertOk();
        $this->assertContains($run['uuid'], array_column($this->lab('GET', 'events?subjectType=run', $this->root)->json('data.data'), 'subjectUuid'));
    }

    public function test_la_caducidad_se_presenta_al_leer_sin_escribir_y_la_aplica_el_siguiente_paso(): void
    {
        Queue::fake();
        $this->labOpenAi();
        $run = $this->launch($this->root, ['kind' => 'baseline'], ['C-01'])->assertCreated()->json('data');
        $reserva = $run['cost']['estimateMaxMicros'];
        $this->assertSame($reserva, $this->lab('GET', 'status', $this->root)->json('data.budget.reservedMicros'));

        $this->travel(31)->minutes();
        $escrituras = [];
        DB::listen(function (QueryExecuted $q) use (&$escrituras): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql) === 1 && ! str_contains($q->sql, 'admin_sessions')) {
                $escrituras[] = $q->sql;
            }
        });
        $leida = $this->lab('GET', "runs/{$run['uuid']}", $this->root)->assertOk()->json('data');
        $this->assertSame(['queued', true], [$leida['status'], $leida['expired']]);
        $this->assertTrue($this->lab('GET', 'runs', $this->root)->json('data.data.0.expired'));
        // Su reserva ya no cuenta para el día.
        $this->assertSame(0, $this->lab('GET', 'status', $this->root)->json('data.budget.reservedMicros'));
        $this->assertSame([], $escrituras, 'Leer una ejecución caducada escribió.');

        $this->assertSame('closed', app(StudioLabRunner::class)->step(StudioLabRun::query()->where('uuid', $run['uuid'])->value('id')));
        $cerrada = $this->lab('GET', "runs/{$run['uuid']}", $this->root)->json('data');
        $this->assertSame(['stopped', 'expired', true], [$cerrada['status'], $cerrada['stopReason'], $cerrada['expired']]);
        $this->assertSame('skipped', $cerrada['cases'][0]['status']);
    }

    public function test_cancelar_en_cola_en_curso_y_terminada(): void
    {
        Queue::fake();
        $this->labFake();

        // En cola: cancelada al instante, con sus casos saltados.
        $enCola = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02'])->assertCreated()->json('data');
        $d = $this->lab('POST', "runs/{$enCola['uuid']}/cancel", $this->root)->assertOk()->json('data');
        $this->assertSame(['cancelled', true], [$d['status'], $d['cancelRequested']]);
        $this->assertSame(['skipped', 'skipped'], array_column($d['cases'], 'status'));
        $this->assertSame(['run.created', 'run.cancel_requested', 'run.finished'], StudioLabEvent::query()->where('subject_uuid', $enCola['uuid'])->orderBy('id')->pluck('event')->all());
        $this->lab('POST', "runs/{$enCola['uuid']}/cancel", $this->root)->assertStatus(409)->assertJsonPath('code', 'run_not_cancellable');

        // En curso ENTRE DOS PASOS (ningún caso en vuelo): también al instante,
        // bajo el cerrojo de los reclamos (Enmienda 5). El paso que llegue
        // después no hace nada.
        $entrePasos = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data');
        $id = (int) StudioLabRun::query()->where('uuid', $entrePasos['uuid'])->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $d = $this->lab('POST', "runs/{$entrePasos['uuid']}/cancel", $this->root)->assertOk()->json('data');
        $this->assertSame(['cancelled', true], [$d['status'], $d['cancelRequested']]);
        $this->assertSame(['done', 'skipped', 'skipped'], array_column($d['cases'], 'status'));
        $this->assertNotNull($d['resultsHash']);
        $this->assertSame('noop', app(StudioLabRunner::class)->step($id));

        // En curso CON UN CASO EN VUELO: se marca; la llamada termina (y se
        // cobra) y el siguiente paso la cierra.
        $this->labOpenAi();
        $guion = $this->script([self::ACTOR_OK, self::JUDGE_PASS, self::ACTOR_OK, self::JUDGE_PASS]);
        $enVuelo = $this->launch($this->root, ['kind' => 'baseline'], ['C-04', 'C-05', 'C-06'])->assertCreated()->json('data');
        $id = (int) StudioLabRun::query()->where('uuid', $enVuelo['uuid'])->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $marcada = null;
        $guion->whileWaiting = function (int $llamada) use (&$marcada, $enVuelo): void {
            if ($llamada === 3) {
                // El actor del caso 2 está en vuelo.
                $marcada = $this->lab('POST', "runs/{$enVuelo['uuid']}/cancel", $this->root)->assertOk()->json('data');
                // Pedirlo otra vez con el caso aún en vuelo no escribe nada más.
                $this->lab('POST', "runs/{$enVuelo['uuid']}/cancel", $this->root)->assertOk()->assertJsonPath('data.status', 'running');
            }
        };
        $this->assertSame('case', app(StudioLabRunner::class)->step($id), 'la llamada en vuelo termina');
        $this->assertSame(['running', true], [$marcada['status'], $marcada['cancelRequested']]);
        $this->assertSame(['allowed' => false, 'code' => 'cancel_requested', 'message' => 'Ya se pidió cancelarla: la cierra el siguiente paso.'], $marcada['actions']['cancel']);
        $this->assertSame(1, StudioLabEvent::query()->where('subject_uuid', $enVuelo['uuid'])->where('event', 'run.cancel_requested')->count());

        $this->assertSame('closed', app(StudioLabRunner::class)->step($id));
        $d = $this->lab('GET', "runs/{$enVuelo['uuid']}", $this->root)->json('data');
        $this->assertSame('cancelled', $d['status']);
        $this->assertSame(['done', 'done', 'skipped'], array_column($d['cases'], 'status'));
        $this->assertCount(4, $guion->calls, 'el caso en vuelo se completó y se cobró; el tercero no empezó');
    }

    public function test_cancelar_una_ejecucion_huerfana_la_cierra_en_el_acto(): void
    {
        // H-P2-1 del tester (Enmienda 5): el paso del caso 2 falla ANTES de
        // reclamar. La ejecución queda en curso, sin caso en vuelo y sin ningún
        // paso en la cola. Antes, cancelar solo la marcaba y nadie la cerraba.
        Queue::fake();
        $this->labFake();
        $run = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data');
        $id = (int) StudioLabRun::query()->where('uuid', $run['uuid'])->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $encolados = count(Queue::pushed(StudioLabStepJob::class));
        $this->stepFailsBeforeClaiming($id);
        $this->assertCount($encolados, Queue::pushed(StudioLabStepJob::class), 'el paso que falla no deja otro en la cola');
        $this->assertSame(['running', ['done', 'pending', 'pending']], [StudioLabRun::query()->whereKey($id)->value('status'), StudioLabRunCase::query()->where('run_id', $id)->orderBy('position')->pluck('status')->all()]);

        $d = $this->lab('POST', "runs/{$run['uuid']}/cancel", $this->root)->assertOk()->json('data');

        $this->assertSame(['cancelled', 'Cancelada', null, true, false], [$d['status'], $d['statusLabel'], $d['stopReason'], $d['cancelRequested'], $d['expired']]);
        $this->assertSame(['done', 'skipped', 'skipped'], array_column($d['cases'], 'status'));
        $this->assertNotNull($d['finishedAt']);
        $fila = StudioLabRun::query()->findOrFail($id);
        $this->assertSame([$fila->results_hash, $fila->results_hash], [$d['resultsHash'], StudioLabRuns::resultsHash($fila, $fila->cases()->get())], 'la huella, calculada al cerrar');
        $this->assertSame(['allowed' => false, 'code' => 'run_not_cancellable', 'message' => 'La ejecución ya terminó: no hay nada que cancelar.'], $d['actions']['cancel']);
        $eventos = StudioLabEvent::query()->where('subject_uuid', $run['uuid'])->orderBy('id')->get();
        $this->assertSame(['run.created', 'run.cancel_requested', 'run.finished'], $eventos->pluck('event')->all());
        $this->assertSame(['admin', 'Root', 'running', 'cancelled'], [$eventos[2]->actor_type, $eventos[2]->actor_name, $eventos[2]->from_status, $eventos[2]->to_status]);
        $this->assertSame('noop', app(StudioLabRunner::class)->step($id), 'si después llega un paso, no hace nada');
        $this->lab('POST', "runs/{$run['uuid']}/cancel", $this->root)->assertStatus(409)->assertJsonPath('code', 'run_not_cancellable');
    }

    public function test_con_la_cancelacion_ya_pedida_y_sin_caso_en_vuelo_pedirla_otra_vez_la_cierra(): void
    {
        // La petición llegó con el caso 1 en vuelo (se marcó) y el paso que
        // tenía que cerrarla falló antes de reclamar: viva, marcada y sin paso.
        Queue::fake();
        $this->labOpenAi();
        $guion = $this->script([self::ACTOR_OK, self::JUDGE_PASS]);
        $run = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data');
        $id = (int) StudioLabRun::query()->where('uuid', $run['uuid'])->value('id');
        $guion->whileWaiting = function (int $llamada) use ($run): void {
            if ($llamada === 1) {
                $this->lab('POST', "runs/{$run['uuid']}/cancel", $this->root)->assertOk()->assertJsonPath('data.cancelRequested', true)->assertJsonPath('data.status', 'running');
            }
        };
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $this->stepFailsBeforeClaiming($id);
        $this->assertSame('running', StudioLabRun::query()->whereKey($id)->value('status'));

        $d = $this->lab('POST', "runs/{$run['uuid']}/cancel", $this->root)->assertOk()->json('data');

        $this->assertSame(['cancelled', true], [$d['status'], $d['cancelRequested']]);
        $this->assertSame(['done', 'skipped', 'skipped'], array_column($d['cases'], 'status'));
        $this->assertSame(['run.created', 'run.cancel_requested', 'run.finished'], StudioLabEvent::query()->where('subject_uuid', $run['uuid'])->orderBy('id')->pluck('event')->all(), 'la petición no se escribe dos veces');
        $this->assertCount(2, $guion->calls);
    }

    public function test_una_huerfana_con_el_plazo_vencido_se_presenta_detenida_sin_escribir_y_la_materializa_el_siguiente_paso(): void
    {
        // H-P2-1 (Enmienda 5): en curso, sin caso en vuelo y pasados 30 min
        // desde que empezó. Se presenta `stopped`/`deadline` con `expired`, como
        // la caducidad en cola, sin escribir nada.
        Queue::fake();
        $this->labFake();
        $run = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data');
        $id = (int) StudioLabRun::query()->where('uuid', $run['uuid'])->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($id));
        $this->stepFailsBeforeClaiming($id);
        $antes = $this->lab('GET', "runs/{$run['uuid']}", $this->root)->assertOk()->json('data');
        $this->assertSame(['running', null, false], [$antes['status'], $antes['stopReason'], $antes['expired']], 'dentro del plazo sigue en curso');

        $this->travel(StudioLabConfig::RUN_DEADLINE_MINUTES + 1)->minutes();
        $escrituras = [];
        DB::listen(function (QueryExecuted $q) use (&$escrituras): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql) === 1 && ! str_contains($q->sql, 'admin_sessions')) {
                $escrituras[] = $q->sql;
            }
        });
        $leida = $this->lab('GET', "runs/{$run['uuid']}", $this->root)->assertOk()->json('data');
        $enLista = collect($this->lab('GET', 'runs', $this->root)->assertOk()->json('data.data'))->firstWhere('uuid', $run['uuid']);

        foreach (['detalle' => $leida, 'lista' => $enLista] as $donde => $r) {
            $this->assertSame(['stopped', 'Detenida', 'deadline', true, null], [$r['status'], $r['statusLabel'], $r['stopReason'], $r['expired'], $r['finishedAt']], $donde);
        }
        $this->assertSame(['done', 'pending', 'pending'], array_column($leida['cases'], 'status'), 'los casos, tal como están');
        $this->assertNull($leida['resultsHash']);
        $this->assertSame([], $escrituras, 'Leer una ejecución huérfana escribió.');
        $this->assertSame(['running', null], [StudioLabRun::query()->whereKey($id)->value('status'), StudioLabRun::query()->whereKey($id)->value('stop_reason')]);

        // El siguiente paso que llegue la materializa, con el mismo motivo.
        $this->assertSame('closed', app(StudioLabRunner::class)->step($id));
        $cerrada = $this->lab('GET', "runs/{$run['uuid']}", $this->root)->json('data');
        $this->assertSame(['stopped', 'deadline'], [$cerrada['status'], $cerrada['stopReason']]);
        $this->assertSame(['done', 'skipped', 'skipped'], array_column($cerrada['cases'], 'status'));
        $this->assertNotNull($cerrada['finishedAt']);
    }

    public function test_con_un_caso_en_curso_no_se_presenta_detenida_y_cancelar_una_huerfana_vencida_la_cierra(): void
    {
        Queue::fake();
        $this->labFake();
        // B: huérfana (su paso falló antes de reclamar).
        $b = $this->launch($this->root, ['kind' => 'baseline'], ['C-03', 'C-04'])->assertCreated()->json('data');
        $idB = (int) StudioLabRun::query()->where('uuid', $b['uuid'])->value('id');
        $this->assertSame('case', app(StudioLabRunner::class)->step($idB));
        $this->stepFailsBeforeClaiming($idB);
        // A: en curso con su caso 1 en curso (el que tiene el proceso que la mueve).
        $a = $this->launch($this->root, ['kind' => 'baseline'], ['C-01', 'C-02'])->assertCreated()->json('data');
        $idA = (int) StudioLabRun::query()->where('uuid', $a['uuid'])->value('id');
        $this->assertTrue(StudioLabRun::advance($idA, ['queued'], ['status' => 'running', 'started_at' => now()]));
        $this->assertTrue(StudioLabRunCase::advance((int) StudioLabRunCase::query()->where('run_id', $idA)->where('position', 1)->value('id'), ['pending'], ['status' => 'running', 'started_at' => now()]));

        $this->travel(StudioLabConfig::RUN_DEADLINE_MINUTES + 1)->minutes();
        $lista = collect($this->lab('GET', 'runs', $this->root)->assertOk()->json('data.data'))->keyBy('uuid');
        $this->assertSame(['running', null, false], [$lista[$a['uuid']]['status'], $lista[$a['uuid']]['stopReason'], $lista[$a['uuid']]['expired']], 'con un caso en curso no es huérfana');
        $this->assertSame(['stopped', 'deadline', true], [$lista[$b['uuid']]['status'], $lista[$b['uuid']]['stopReason'], $lista[$b['uuid']]['expired']]);
        $this->assertSame('running', $this->lab('GET', "runs/{$a['uuid']}", $this->root)->json('data.status'));

        // Cancelar la huérfana la materializa: `cancelled` (la petición expresa
        // de la persona, como en la caducidad en cola).
        $d = $this->lab('POST', "runs/{$b['uuid']}/cancel", $this->root)->assertOk()->json('data');
        $this->assertSame(['cancelled', null, false], [$d['status'], $d['stopReason'], $d['expired']]);
        $this->assertSame(['done', 'skipped'], array_column($d['cases'], 'status'));
    }

    public function test_la_misma_peticion_es_200_replayed_y_la_llave_reutilizada_409(): void
    {
        Queue::fake();
        $this->labFake();
        $id = (string) Str::uuid();
        $cuerpo = ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01'), $this->scenarioUuid('C-02')], 'acceptedMaxCostMicros' => 0, 'clientRequestId' => $id];

        $uuid = $this->lab('POST', 'runs', $this->root, $cuerpo)->assertCreated()->json('data.uuid');
        $this->lab('POST', 'runs', $this->root, $cuerpo)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('data.uuid', $uuid);
        $this->assertSame(1, StudioLabRun::query()->count());
        Queue::assertPushed(StudioLabStepJob::class, 1);

        $this->lab('POST', 'runs', $this->root, ['scenarioUuids' => [$this->scenarioUuid('C-01')]] + $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->lab('POST', 'runs', $this->root, ['acceptedMaxCostMicros' => 5] + $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $otro = $this->person(Admin::ROLE_SUPER_ADMIN, 'Otro Root');
        $this->lab('POST', 'runs', $otro, $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->assertSame(1, StudioLabRun::query()->count());
    }

    public function test_un_objetivo_con_datos_personales_es_409_y_no_se_construye_la_entrada(): void
    {
        Queue::fake();
        $this->labFake();
        $this->newStudioRule($this->ana, ['description' => 'Si duda, que llame al 300 123 4567.'])->assertCreated();
        $contenido = $this->contentWith(['knowledge' => ['sources' => ['Base de conocimiento aprobada', 'ventas@gimnasio.com']]]);
        $revision = $this->saveDraft($this->root, $contenido)->assertOk()->json('data.revision');
        $cuerpo = ['target' => ['kind' => 'draft', 'revision' => $revision], 'scenarioUuids' => [$this->scenarioUuid('C-01')]];

        $this->lab('POST', 'estimate', $this->root, $cuerpo)
            ->assertStatus(409)->assertJsonPath('code', 'target_contains_personal_data')->assertJsonPath('fields', ['configs.knowledge.sources', 'rules.S-001']);
        $this->lab('POST', 'runs', $this->root, $cuerpo + ['acceptedMaxCostMicros' => 0, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'target_contains_personal_data');
        $this->assertSame(0, StudioLabRun::query()->count());
    }

    public function test_los_escenarios_se_validan_por_posicion(): void
    {
        $this->labFake();
        $archivado = $this->lab('POST', 'scenarios', $this->ana, $this->scenarioBody())->assertCreated()->json('data.uuid');
        $this->lab('POST', "scenarios/{$archivado}/archive", $this->ana, ['reason' => 'Archivado para la prueba'])->assertOk();

        $r = $this->lab('POST', 'estimate', $this->root, ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01'), $archivado, (string) Str::uuid()]])->assertStatus(422);
        $this->assertSame(['scenarioUuids.1', 'scenarioUuids.2'], array_keys($r->json('errors')));
        $c01 = $this->scenarioUuid('C-01');
        $this->lab('POST', 'estimate', $this->root, ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$c01, strtoupper($c01)]])->assertStatus(422);
        $r = $this->lab('POST', 'estimate', $this->root, ['target' => ['kind' => 'baseline'], 'scenarioUuids' => array_fill(0, 500, 'x')])->assertStatus(422);
        $this->assertSame(['scenarioUuids'], array_keys($r->json('errors')));
    }

    public function test_la_lista_filtra_y_pagina(): void
    {
        Queue::fake();
        $this->labFake();
        $v = (string) $this->createVersion($this->ana)->assertCreated()->json('data.uuid');
        $sobreVersion = $this->launch($this->root, self::versionTarget($v), ['C-01'])->assertCreated()->json('data.uuid');
        $sobreBase = $this->launch($this->root, ['kind' => 'baseline'], ['C-02'])->assertCreated()->json('data.uuid');

        $pagina = $this->lab('GET', 'runs?per_page=1', $this->ana)->assertOk()->json('data');
        $this->assertSame([1, 2, 1, 2], [$pagina['current_page'], $pagina['last_page'], $pagina['per_page'], $pagina['total']]);
        $this->assertSame($sobreBase, $pagina['data'][0]['uuid'], 'la más reciente primero');
        $this->assertSame([$sobreVersion], array_column($this->lab('GET', "runs?version={$v}", $this->ana)->json('data.data'), 'uuid'));
        $this->assertSame([$sobreBase], array_column($this->lab('GET', 'runs?target=baseline', $this->ana)->json('data.data'), 'uuid'));
        $this->assertSame([], $this->lab('GET', 'runs?scope=mine', $this->ana)->json('data.data'));
        $this->assertCount(2, $this->lab('GET', 'runs?scope=mine', $this->root)->json('data.data'));
        $this->lab('GET', 'runs?version=no-es-un-uuid', $this->ana)->assertStatus(422);

        $resumen = $this->lab('GET', 'runs', $this->ana)->json('data.data.0');
        $this->assertSame([
            'uuid', 'status', 'statusLabel', 'stopReason', 'budgetCap', 'expired', 'cancelRequested', 'target', 'mode', 'model', 'promptVersion',
            'caseCount', 'progress', 'outcomes', 'aiRecommendation', 'cost', 'createdBy', 'mine', 'createdAt', 'startedAt', 'finishedAt', 'verdict',
        ], array_keys($resumen));
        $this->assertSame(['done' => 0, 'error' => 0, 'skipped' => 0, 'pending' => 1], $resumen['progress']);
        $this->assertSame(['pass' => 0, 'review' => 0, 'fail' => 0], $resumen['outcomes']);
        $this->assertSame(['queued', 'En cola', null, 'fake', null], [$resumen['status'], $resumen['statusLabel'], $resumen['model'], $resumen['mode'], $resumen['budgetCap']]);
    }

    /**
     * Concede EXACTAMENTE estas llaves a la persona (su rol propio).
     *
     * @param  list<string>  $permissions
     */
    private function grant(Admin $admin, array $permissions): void
    {
        $rol = 'Lab-'.Str::random(6);
        AdminRole::query()->firstOrCreate(['name' => $rol], ['is_system' => false]);
        foreach ($permissions as $p) {
            RolePermission::query()->updateOrCreate(['role' => $rol, 'permission' => $p], ['granted' => true]);
        }
        $admin->forceFill(['role' => $rol])->save();
        app(RolePermissionPolicy::class)->flush();
    }
}
