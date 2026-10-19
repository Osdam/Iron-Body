<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabVerdict;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * El VEREDICTO HUMANO del laboratorio (contrato H4 §A6; Enmienda 2, §5): solo
 * sobre ejecuciones completadas, con OpenAI y sobre una versión; cuatro ojos
 * respecto al AUTOR de la versión (no a quien lanzó); el reconocimiento EXACTO
 * de los casos que no pasaron; la huella que vio la persona; y uno por
 * ejecución. Nada cambia en la versión ni en el motor.
 */
class UltronStudioLabVerdictTest extends TestCase
{
    use LabFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $ana;

    private Admin $root;

    private string $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importBaseline();
        $this->importLabScenarios();
        // Ana es la AUTORA de la versión; Root lanza las ejecuciones.
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $this->version = (string) $this->createVersion($this->ana)->assertCreated()->json('data.uuid');
        $this->labOpenAi();
    }

    public function test_la_autora_de_la_version_no_lo_emite_y_su_intento_queda_una_vez(): void
    {
        $run = $this->completedRun();
        $cuerpo = ['decision' => 'approved', 'note' => null, 'acknowledgedCases' => [], 'resultsHash' => $run['resultsHash']];
        $this->assertSame(['allowed' => false, 'code' => 'four_eyes_required', 'message' => 'Nadie emite el veredicto sobre su propia versión: tiene que hacerlo otra persona.'],
            $this->lab('GET', "runs/{$run['uuid']}", $this->ana)->json('data.actions.verdict'));

        for ($i = 0; $i < 3; $i++) {
            $this->lab('POST', "runs/{$run['uuid']}/verdict", $this->ana, $cuerpo)->assertStatus(403)->assertJsonPath('code', 'four_eyes_required');
        }
        $this->assertSame(1, StudioLabEvent::query()->where('event', 'run.verdict_denied')->count());
        $this->travel(StudioAudit::DENIED_WINDOW_MINUTES + 1)->minutes();
        $this->lab('POST', "runs/{$run['uuid']}/verdict", $this->ana, $cuerpo)->assertStatus(403);
        $this->assertSame(2, StudioLabEvent::query()->where('event', 'run.verdict_denied')->count());
        $this->assertSame(0, StudioLabVerdict::query()->count());
    }

    public function test_quien_lanzo_lo_emite_si_no_es_el_autor_y_queda_en_el_resumen_y_en_el_libro(): void
    {
        $antes = StudioVersion::query()->where('uuid', $this->version)->sole()->toArray();
        $run = $this->completedRun();

        $d = $this->lab('POST', "runs/{$run['uuid']}/verdict", $this->root, [
            'decision' => 'approved', 'note' => null, 'acknowledgedCases' => [], 'resultsHash' => $run['resultsHash'],
        ])->assertOk()->json('data');

        $this->assertSame('approved', $d['verdict']['decision']);
        $this->assertSame(['name' => 'Root', 'role' => 'Super Admin'], $d['verdict']['by']);
        $this->assertNotNull($d['verdict']['at']);
        $this->assertSame(['allowed' => false, 'code' => 'verdict_exists', 'message' => 'Esta ejecución ya tiene veredicto.'], $d['actions']['verdict']);
        $fila = StudioLabVerdict::query()->sole();
        $version = StudioVersion::query()->where('uuid', $this->version)->sole();
        $this->assertSame([$version->id, $version->content_hash, $run['resultsHash'], []], [$fila->version_id, $fila->version_content_hash, $fila->results_hash, $fila->acknowledged_cases]);
        $evento = StudioLabEvent::query()->where('event', 'run.verdict_recorded')->sole();
        $this->assertSame(['decision' => 'approved', 'note' => null, 'acknowledgedCases' => []], $evento->diff);
        // Relación con F: ninguna. La versión no cambia.
        $this->assertSame($antes, $version->toArray());

        // Uno solo por ejecución.
        $bea = $this->person(Admin::ROLE_ADMINISTRADOR, 'Bea');
        $this->lab('POST', "runs/{$run['uuid']}/verdict", $bea, ['decision' => 'rejected', 'note' => 'Otra opinión', 'resultsHash' => $run['resultsHash']])
            ->assertStatus(409)->assertJsonPath('code', 'verdict_exists');
        $this->assertSame(1, StudioLabVerdict::query()->count());
    }

    public function test_solo_ejecuciones_completadas_con_openai_y_sobre_una_version(): void
    {
        $cuerpo = fn (array $run): array => ['decision' => 'rejected', 'note' => 'No sirve todavía', 'resultsHash' => $run['resultsHash'] ?? str_repeat('a', 64)];

        // Ensayo sin IA.
        $this->labFake();
        $fake = $this->launch($this->root, self::versionTarget($this->version), ['C-01'])->assertCreated()->json('data');
        $this->lab('POST', "runs/{$fake['uuid']}/verdict", $this->ana, $cuerpo($fake))
            ->assertStatus(409)->assertJsonPath('code', 'run_not_reviewable')->assertJsonPath('reason', 'fake_mode');

        // Sobre la línea base.
        $this->labOpenAi();
        $this->script([['reply' => 'Hola', 'usage' => [900, 40]], ['judge' => 'pass', 'usage' => [1500, 300]]]);
        $base = $this->launch($this->root, ['kind' => 'baseline'], ['C-01'])->assertCreated()->json('data');
        $this->lab('POST', "runs/{$base['uuid']}/verdict", $this->ana, $cuerpo($base))
            ->assertStatus(409)->assertJsonPath('code', 'run_not_reviewable')->assertJsonPath('reason', 'target_not_version');

        // Parada (no completada).
        $this->script([['class' => 'auth', 'http' => 401]]);
        $parada = $this->launch($this->root, self::versionTarget($this->version), ['C-01', 'C-02'])->assertCreated()->json('data');
        $this->assertSame('stopped', $parada['status']);
        $this->lab('POST', "runs/{$parada['uuid']}/verdict", $this->ana, $cuerpo($parada))
            ->assertStatus(409)->assertJsonPath('code', 'run_not_reviewable')->assertJsonPath('reason', 'not_completed');

        // En cola.
        Queue::fake();
        $enCola = $this->launch($this->root, self::versionTarget($this->version), ['C-03'])->assertCreated()->json('data');
        $this->lab('POST', "runs/{$enCola['uuid']}/verdict", $this->ana, $cuerpo($enCola))
            ->assertStatus(409)->assertJsonPath('reason', 'not_completed');
        $this->assertSame(0, StudioLabVerdict::query()->count());
    }

    public function test_aprobar_reconoce_exactamente_los_casos_que_no_pasaron(): void
    {
        // C-01 pasa, C-02 falla (un precio: lo marca el filtro del motor) y C-03 queda en revisión.
        $this->script([
            ['reply' => 'Hola, ¿en qué te ayudo?', 'usage' => [900, 40]], ['judge' => 'pass', 'usage' => [1500, 300]],
            ['reply' => 'La mensualidad vale 80.000 pesos', 'usage' => [900, 40]], ['judge' => 'pass', 'usage' => [1500, 300]],
            ['reply' => 'Entiendo, cuéntame qué te interesa', 'usage' => [900, 40]], ['judge' => 'unclear', 'usage' => [1500, 300]],
        ]);
        $run = $this->launch($this->root, self::versionTarget($this->version), ['C-01', 'C-02', 'C-03'])->assertCreated()->json('data');
        $this->assertSame(['completed', 'reject', ['C-02', 'C-03']], [$run['status'], $run['aiRecommendation'], $run['requiredAcknowledgements']]);
        $bea = $this->person(Admin::ROLE_ADMINISTRADOR, 'Bea');
        $aprobar = fn (array $casos) => $this->lab('POST', "runs/{$run['uuid']}/verdict", $bea, ['decision' => 'approved', 'note' => null, 'acknowledgedCases' => $casos, 'resultsHash' => $run['resultsHash']]);

        foreach ([[], ['C-02'], ['C-01', 'C-02', 'C-03']] as $mal) {
            $aprobar($mal)->assertStatus(409)->assertJsonPath('code', 'cases_not_acknowledged')->assertJsonPath('cases', ['C-02', 'C-03']);
        }
        $aprobar(['C-03', 'C-02'])->assertOk()->assertJsonPath('data.verdict.decision', 'approved');
        $this->assertSame(['C-02', 'C-03'], StudioLabVerdict::query()->sole()->acknowledged_cases);
    }

    public function test_dos_veredictos_a_la_vez_dan_uno_y_un_409(): void
    {
        $run = $this->completedRun();
        $id = (int) StudioLabRun::query()->where('uuid', $run['uuid'])->value('id');
        $versionId = (int) StudioVersion::query()->where('uuid', $this->version)->value('id');
        // Otra petición inserta su veredicto justo antes que esta (ya pasó la comprobación previa).
        $this->beforeStatement('insert into "ultron_studio_lab_verdicts"', function () use ($id, $versionId, $run): void {
            StudioLabVerdict::query()->forceCreate([
                'run_id' => $id, 'version_id' => $versionId, 'version_content_hash' => str_repeat('a', 64), 'results_hash' => $run['resultsHash'],
                'decision' => 'rejected', 'note' => 'La otra petición', 'acknowledged_cases' => null,
                'reviewer_admin_id' => 999, 'reviewer_name' => 'Otra persona', 'reviewer_role' => null, 'created_at' => now(),
            ]);
        });

        $bea = $this->person(Admin::ROLE_ADMINISTRADOR, 'Bea');
        $this->lab('POST', "runs/{$run['uuid']}/verdict", $bea, ['decision' => 'needs_changes', 'note' => 'A la vez que otra', 'resultsHash' => $run['resultsHash']])
            ->assertStatus(409)->assertJsonPath('code', 'verdict_exists');
        $this->assertSame(0, StudioLabEvent::query()->where('event', 'run.verdict_recorded')->count(), 'el evento va en la misma transacción que la fila');
    }

    public function test_la_huella_tiene_que_ser_la_que_vio_la_persona(): void
    {
        $run = $this->completedRun();
        $bea = $this->person(Admin::ROLE_ADMINISTRADOR, 'Bea');
        $this->lab('POST', "runs/{$run['uuid']}/verdict", $bea, ['decision' => 'needs_changes', 'note' => 'Faltan cosas', 'resultsHash' => str_repeat('f', 64)])
            ->assertStatus(409)->assertJsonPath('code', 'run_integrity_mismatch');
        $this->lab('POST', "runs/{$run['uuid']}/verdict", $bea, ['decision' => 'needs_changes', 'note' => 'Faltan cosas', 'resultsHash' => strtoupper($run['resultsHash'])])
            ->assertOk()->assertJsonPath('data.verdict.decision', 'needs_changes');
    }

    public function test_la_forma_del_cuerpo_y_la_llave(): void
    {
        $run = $this->completedRun();
        $bea = $this->person(Admin::ROLE_ADMINISTRADOR, 'Bea');
        $url = "runs/{$run['uuid']}/verdict";
        $hash = $run['resultsHash'];

        $this->lab('POST', $url, $bea, ['decision' => 'approved', 'note' => null, 'resultsHash' => $hash])->assertStatus(422)->assertJsonValidationErrors('acknowledgedCases');
        $this->lab('POST', $url, $bea, ['decision' => 'rejected', 'note' => 'Motivo válido', 'acknowledgedCases' => ['C-01'], 'resultsHash' => $hash])->assertStatus(422)->assertJsonValidationErrors('acknowledgedCases');
        $this->lab('POST', $url, $bea, ['decision' => 'rejected', 'note' => null, 'resultsHash' => $hash])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->lab('POST', $url, $bea, ['decision' => 'needs_changes', 'note' => 'abc', 'resultsHash' => $hash])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->lab('POST', $url, $bea, ['decision' => 'publicar', 'note' => 'Nota válida', 'resultsHash' => $hash])->assertStatus(422)->assertJsonValidationErrors('decision');
        $this->lab('POST', $url, $bea, ['decision' => 'rejected', 'note' => 'Nota válida', 'resultsHash' => 'no-es-una-huella'])->assertStatus(422)->assertJsonValidationErrors('resultsHash');

        // Sin `ultron.approve` no hay veredicto, aunque se pueda lanzar.
        $lanzar = $this->adminWithPermissions(['ultron.view', 'ultron.lab_run']);
        $this->json('POST', "/api/admin/ultron-studio/lab/{$url}", ['decision' => 'rejected', 'note' => 'Nota válida', 'resultsHash' => $hash], $lanzar)
            ->assertStatus(403)->assertJsonPath('required_permission', 'ultron.approve');
        $this->assertSame(0, StudioLabVerdict::query()->count());
        $this->assertSame('completed', StudioLabRun::query()->where('uuid', $run['uuid'])->value('status'));
    }

    /** Una ejecución completada de Root sobre la versión de Ana, con todo en verde. */
    private function completedRun(): array
    {
        $this->script([['reply' => 'Hola, ¿en qué te puedo ayudar?', 'usage' => [900, 40]], ['judge' => 'pass', 'usage' => [1500, 300]]]);
        $run = $this->launch($this->root, self::versionTarget($this->version), ['C-01'])->assertCreated()->json('data');
        $this->assertSame(['completed', 'approve', []], [$run['status'], $run['aiRecommendation'], $run['requiredAcknowledgements']]);

        return $run;
    }
}
