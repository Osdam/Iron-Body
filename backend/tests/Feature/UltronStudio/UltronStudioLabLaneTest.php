<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Services\Observability\QueueHealthService;
use App\Services\UltronStudio\StudioLabRunner;
use App\Services\UltronStudio\StudioLabStepJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El CARRIL del laboratorio (contrato H4 §A3 y decisión 4 del lead): el job
 * vive en `app/Services/UltronStudio/`, se coloca solo en `studio-lab`, hace UN
 * intento (un reintento podría pagar dos veces) con 150 s y `failOnTimeout`;
 * su conexión tiene `retry_after` 300 > 150; el worker es un programa de
 * supervisor APARTE; crear una ejecución encola un solo paso, después de
 * confirmar; y el latido de la cola ve el carril.
 */
class UltronStudioLabLaneTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    public function test_el_job_se_coloca_solo_en_su_carril_con_un_intento_y_150_segundos(): void
    {
        $job = new StudioLabStepJob(7);
        $this->assertSame('studio-lab', config('queue.lanes.lab.queue'));
        $this->assertSame('studio-lab', $job->queue);
        $this->assertSame([1, 150, true], [$job->tries, $job->timeout, $job->failOnTimeout]);
        $this->assertStringEndsWith('app/Services/UltronStudio/StudioLabStepJob.php', (string) (new \ReflectionClass($job))->getFileName());
    }

    public function test_la_conexion_del_carril_y_retry_after_mayor_que_el_timeout(): void
    {
        $this->assertSame(['connection' => 'lab', 'queue' => 'studio-lab', 'priority' => 5, 'slo_wait_ms' => 600000], config('queue.lanes.lab'));
        $conexion = config('queue.connections.lab');
        $this->assertSame(['database', 'studio-lab', 300, false], [$conexion['driver'], $conexion['queue'], $conexion['retry_after'], $conexion['after_commit']]);
        $this->assertSame(config('queue.connections.database.table'), $conexion['table'], 'la misma tabla jobs');
        $this->assertGreaterThan((new StudioLabStepJob(1))->timeout, $conexion['retry_after']);
        $this->assertSame(StudioLabRunner::STALE_AFTER_SECONDS, (new StudioLabStepJob(1))->timeout, 'un caso en curso más allá del timeout está perdido');
    }

    public function test_el_worker_es_un_programa_de_supervisor_aparte(): void
    {
        $conf = (string) file_get_contents(base_path('docs/ops/supervisor/ironbody-lab.conf'));
        $this->assertStringContainsString('[program:ironbody-lab]', $conf);
        $this->assertMatchesRegularExpression('#^command=/usr/bin/php /var/www/api/backend/artisan queue:work lab --queue=studio-lab --sleep=3 --tries=1 --timeout=150 --max-jobs=100 --max-time=3600$#m', $conf);
        foreach (['numprocs=1', 'stopwaitsecs=180', 'user=www-data', 'stdout_logfile=/var/www/api/backend/storage/logs/worker-lab.log'] as $linea) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($linea, '/').'$/m', $conf);
        }
        $this->assertStringNotContainsString('studio-lab', (string) file_get_contents(base_path('docs/ops/supervisor/ironbody-workers.conf')), 'el laboratorio no va en el fichero de los otros doce');
        $this->assertStringContainsString('`StudioLabStepJob`', (string) file_get_contents(base_path('docs/ops/WORKER_TOPOLOGY.md')));
    }

    public function test_crear_una_ejecucion_encola_un_solo_paso_despues_de_confirmar(): void
    {
        Queue::fake();
        $this->labFake();
        $this->importLabScenarios();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');

        $this->launch($root, ['kind' => 'baseline'], ['C-01', 'C-02'])->assertCreated();

        Queue::assertPushedOn('studio-lab', StudioLabStepJob::class);
        $empujados = Queue::pushed(StudioLabStepJob::class);
        $this->assertCount(1, $empujados);
        $this->assertTrue($empujados->first()->afterCommit, 'las conexiones de carril tienen after_commit = false: lo pide quien encola');
    }

    public function test_lanzar_tiene_su_propio_techo_por_hora(): void
    {
        Queue::fake();
        $this->labFake(['ultron_studio.lab.max_runs_per_day' => 50]);
        $this->importLabScenarios();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $cuerpo = fn (): array => ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01')], 'acceptedMaxCostMicros' => 0, 'clientRequestId' => (string) Str::uuid()];

        for ($i = 1; $i <= 10; $i++) {
            $this->lab('POST', 'runs', $root, $cuerpo())->assertCreated();
        }
        $r = $this->lab('POST', 'runs', $root, $cuerpo())->assertStatus(429);
        $r->assertJsonPath('ok', false)->assertJsonPath('code', 'too_many_requests')
            ->assertJsonPath('message', 'Demasiadas ejecuciones del laboratorio en una hora. Espera antes de lanzar otra.');
        $this->assertGreaterThan(60, $r->json('retry_after'), 'el techo es por hora');

        // El de lectura es otro cubo, y el de otra persona también.
        $this->lab('GET', 'status', $root)->assertOk();
        $this->lab('POST', 'runs', $this->person(Admin::ROLE_SUPER_ADMIN, 'Otra'), $cuerpo())->assertCreated();
    }

    public function test_el_techo_de_lanzar_es_por_persona_y_no_por_sesion(): void
    {
        // I-3 de api-security (Enmienda 4.4): entrar otra vez o abrir otra
        // pestaña no da diez ejecuciones más. Dos sesiones REALES de la misma
        // persona comparten el cupo; otra persona tiene el suyo.
        Queue::fake();
        $this->labFake(['ultron_studio.lab.max_runs_per_day' => 50]);
        $this->importLabScenarios();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $cuerpo = fn (): array => ['target' => ['kind' => 'baseline'], 'scenarioUuids' => [$this->scenarioUuid('C-01')], 'acceptedMaxCostMicros' => 0, 'clientRequestId' => (string) Str::uuid()];
        $primera = $this->actingAsAdmin($root);
        $segunda = $this->actingAsAdmin($root);
        $this->assertNotSame($primera['Authorization'], $segunda['Authorization'], 'dos sesiones distintas');

        for ($i = 1; $i <= 10; $i++) {
            $this->json('POST', '/api/admin/ultron-studio/lab/runs', $cuerpo(), $i % 2 === 0 ? $segunda : $primera)->assertCreated();
        }
        $this->json('POST', '/api/admin/ultron-studio/lab/runs', $cuerpo(), $segunda)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $this->json('POST', '/api/admin/ultron-studio/lab/runs', $cuerpo(), $this->actingAsAdmin($root))->assertStatus(429);

        $this->lab('POST', 'runs', $this->person(Admin::ROLE_SUPER_ADMIN, 'Otra'), $cuerpo())->assertCreated();
    }

    public function test_el_latido_de_la_cola_ve_el_carril(): void
    {
        $foto = app(QueueHealthService::class)->snapshot();
        $this->assertArrayHasKey('lab', $foto);
        $this->assertSame(['studio-lab', 5, 600000], [$foto['lab']['queue'], $foto['lab']['priority'], $foto['lab']['slo_wait_ms']]);
    }
}
