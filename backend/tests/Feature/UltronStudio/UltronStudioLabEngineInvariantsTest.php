<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Models\UltronStudio\StudioLabScenario;
use App\Models\UltronStudio\StudioLabVerdict;
use App\Models\UltronStudio\StudioPublication;
use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\UltronStudio\StudioLabEngineFlow;
use App\Services\UltronStudio\StudioLabPrompts;
use App\Services\UltronStudio\StudioLabRuns;
use App\Services\UltronStudio\StudioLabVerdicts;
use App\Services\UltronStudio\StudioPublishing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LO QUE EL LABORATORIO FRENTE AL MOTOR NO PUEDE MOVER (apartado 5 del cierre,
 * diseño `plans/informes-cierre/diseno-arq-lab.md` §0; T6).
 *
 * En producción hay una versión APROBADA con veredicto del laboratorio
 * (simulación) pendiente de publicar. Nada del recorrido del motor puede
 * invalidarla. Estas pruebas se escribieron ANTES del primer cambio de tiempo
 * de ejecución, sobre el código de `296cf5a1`, y las huellas son las de ese
 * código: si una se pone en rojo, el cambio movió lo firmado.
 *
 *  1. `StudioLabPrompts::PROMPT_VERSION` y su huella.
 *  2. El `resultsHash` de una ejecución de SIMULACIÓN, byte a byte (el
 *     veredicto lo recalcula: H4 §A6, 409 `run_integrity_mismatch`).
 *  3. La forma y los valores de `labGate` en la vista previa (la huella de la
 *     vista previa exige `{mode, latest}`).
 *  4. `latestFor()` no cuenta un veredicto sobre una ejecución del motor (otro
 *     `prompt_version`), ni aunque sea posterior.
 *  5. El compilador, la base y la huella Legacy solo se leen.
 */
class UltronStudioLabEngineInvariantsTest extends TestCase
{
    use LabFixtures;
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    /** La huella de `2026-11-12.6`, la misma que fija `StudioLabPromptsTest`. */
    private const PROMPTS_HASH = '01fc520af225f0373d6d4a3312e93fa1fca72cd8eb74037526da222102c51f0b';

    /** El `resultsHash` de la ejecución sembrada de {@see goldenRun()}, calculado con el código de `296cf5a1`. */
    private const GOLDEN_RESULTS_HASH = '0084bb78bdfc8e7024544d9734fe1d3c4223159de7bd96ce5876f5ec7d1c5c1a';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_la_version_de_prompts_de_la_simulacion_y_su_huella_no_se_mueven(): void
    {
        $this->assertSame('2026-11-12.6', StudioLabPrompts::PROMPT_VERSION);
        $this->assertSame(self::PROMPTS_HASH, StudioLabPrompts::hash());
    }

    public function test_el_compilador_la_base_y_la_huella_legacy_solo_se_leen(): void
    {
        $this->assertSame(4, UltronPromptCompiler::VERSION);
        $this->assertSame('n8n-29de5119', UltronPromptCompiler::BASE);
        $this->assertSame('n8n-29de5119', UltronPromptBase::ID);
        $this->assertSame(['strategist', 'composer', 'critic', 'composer_retry', 'critic_retry'], UltronPromptBase::ROLES);
    }

    public function test_el_results_hash_de_una_simulacion_es_el_de_siempre(): void
    {
        [$run, $casos] = $this->goldenRun();

        $this->assertSame(self::GOLDEN_RESULTS_HASH, StudioLabRuns::resultsHash($run, $casos));
        // Leída de la base otra vez, la misma.
        $this->assertSame(self::GOLDEN_RESULTS_HASH, StudioLabRuns::resultsHash($run->fresh(), $run->cases()->orderBy('position')->get()));
    }

    public function test_una_simulacion_de_principio_a_fin_cierra_con_la_huella_recalculable(): void
    {
        Queue::fake();
        $this->labFake();
        $this->importLabScenarios();
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $uuid = (string) $this->launch($root, ['kind' => 'baseline'], ['C-01', 'C-09'])->assertCreated()->json('data.uuid');
        $run = $this->drain($uuid);

        $this->assertSame('completed', $run->status);
        $this->assertSame(StudioLabPrompts::PROMPT_VERSION, $run->prompt_version);
        $this->assertSame($run->results_hash, StudioLabRuns::resultsHash($run, $run->cases()->orderBy('position')->get()));
    }

    public function test_la_puerta_del_laboratorio_tiene_la_misma_forma_y_los_mismos_valores(): void
    {
        $this->importBaseline();
        $this->openKeys(['ultron_studio.publishing.lab_gate' => 'required']);
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);

        $this->assertSame(['mode' => 'required', 'ok' => false, 'latest' => null, 'message' => 'Sin veredicto del laboratorio sobre esta huella.'],
            $this->previewOf($root, $v->uuid)['requirements']['labGate']);

        $veredicto = $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED);
        $gate = $this->previewOf($root, $v->uuid)['requirements']['labGate'];
        // Aprobada, sin mensaje: las tres claves de siempre.
        $this->assertSame(['mode', 'ok', 'latest'], array_keys($gate));
        $this->assertSame(['required', true, 'approved'], [$gate['mode'], $gate['ok'], $gate['latest']]);

        // Lo que se firma en la entrada: los cuatro campos de siempre.
        $this->publishAs($root, $v->uuid)->assertCreated()->assertJsonPath('data.labGate', ['mode' => 'required']);
        $fila = StudioPublication::query()->sole()->lab_gate;
        $this->assertSame(['mode' => 'required', 'runUuid' => $veredicto->run->uuid, 'verdictId' => $veredicto->id, 'promptVersion' => StudioLabPrompts::PROMPT_VERSION], $fila);
    }

    public function test_un_veredicto_sobre_una_ejecucion_del_motor_no_cuenta_para_la_puerta_de_hoy(): void
    {
        $this->importBaseline();
        $this->openKeys(['ultron_studio.publishing.lab_gate' => 'required']);
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);

        $aprobado = $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED);
        // DESPUÉS, uno del motor que rechaza: tiene otro `prompt_version` y no lo pisa.
        $this->labVerdict($v, StudioLabVerdict::DECISION_REJECTED, 'motor-S2-c4-2026-11-22.1');

        $ultimo = StudioLabVerdicts::latestFor($v, StudioLabPrompts::PROMPT_VERSION);
        $this->assertSame(['latest' => 'approved', 'runUuid' => $aprobado->run->uuid, 'verdictId' => $aprobado->id, 'promptVersion' => StudioLabPrompts::PROMPT_VERSION], $ultimo);
        $this->assertSame(['mode' => 'required', 'ok' => true, 'latest' => 'approved'], array_intersect_key($this->previewOf($root, $v->uuid)['requirements']['labGate'], array_flip(['mode', 'ok', 'latest'])));
    }

    public function test_un_veredicto_solo_del_motor_no_es_stale_prompts_para_la_simulacion(): void
    {
        $this->importBaseline();
        $this->openKeys(['ultron_studio.publishing.lab_gate' => 'required']);
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);
        $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED, StudioLabEngineFlow::promptVersion());

        // Ignorado del todo: ni la cuenta ni la da por «prompts viejos».
        $this->assertNull(StudioLabVerdicts::latestFor($v, StudioLabPrompts::PROMPT_VERSION)['latest']);
        $this->assertSame(['mode' => 'required', 'ok' => false, 'latest' => null, 'message' => 'Sin veredicto del laboratorio sobre esta huella.'], $this->previewOf($root, $v->uuid)['requirements']['labGate']);
        // Uno de la simulación con prompts viejos sigue siendo `stale_prompts`, como siempre.
        $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED, '2000-01-01.1');
        $this->assertSame('stale_prompts', StudioLabVerdicts::latestFor($v, StudioLabPrompts::PROMPT_VERSION)['latest']);
    }

    /**
     * B8 (apartado 5): la llave `ULTRON_STUDIO_PUBLISH_LAB_SOURCE`. Nace en
     * `simulation` y la puerta es la de siempre; con `engine`, cuenta el
     * veredicto del recorrido del motor (y uno de la simulación no basta ni es
     * «prompts viejos» del motor). La forma de `labGate` no cambia.
     */
    public function test_la_llave_de_la_puerta_nace_en_simulacion_y_con_engine_cuenta_el_motor(): void
    {
        $this->assertSame([StudioLabRuns::FIDELITY_SIMULATION, StudioLabPrompts::PROMPT_VERSION], [StudioPublishing::labSource(), StudioPublishing::labGatePromptVersion()]);
        $this->importBaseline();
        $this->openKeys(['ultron_studio.publishing.lab_gate' => 'required']);
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $v = $this->approvedVersion($ana, $root);
        $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED);

        config(['ultron_studio.publishing.lab_source' => 'engine']);
        $this->assertSame([StudioLabRuns::FIDELITY_MODE_ENGINE, StudioLabEngineFlow::promptVersion()], [StudioPublishing::labSource(), StudioPublishing::labGatePromptVersion()]);
        $this->assertSame(['mode' => 'required', 'ok' => false, 'latest' => null, 'message' => 'Sin veredicto del laboratorio sobre esta huella.'], $this->previewOf($root, $v->uuid)['requirements']['labGate'], 'la simulación no basta');
        $this->publishAs($root, $v->uuid)->assertStatus(409)->assertJsonPath('code', 'lab_verdict_required');

        $delMotor = $this->labVerdict($v, StudioLabVerdict::DECISION_APPROVED, StudioLabEngineFlow::promptVersion());
        $gate = $this->previewOf($root, $v->uuid)['requirements']['labGate'];
        $this->assertSame(['mode' => 'required', 'ok' => true, 'latest' => 'approved'], $gate);
        $this->publishAs($root, $v->uuid)->assertCreated()->assertJsonPath('data.labGate', ['mode' => 'required']);
        $this->assertSame(['mode' => 'required', 'runUuid' => $delMotor->run->uuid, 'verdictId' => $delMotor->id, 'promptVersion' => StudioLabEngineFlow::promptVersion()],
            StudioPublication::query()->sole()->lab_gate);
    }

    /**
     * Una ejecución de simulación terminada, sembrada con valores fijos (uuid,
     * escenario, respuesta, hallazgos y juez): la entrada de la huella dorada.
     *
     * @return array{0:StudioLabRun,1:list<StudioLabRunCase>}
     */
    private function goldenRun(): array
    {
        $root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
        $escenario = StudioLabScenario::query()->forceCreate([
            'uuid' => '00000000-0000-4000-8000-0000000000c1',
            'code' => 'C-01',
            'source' => 'catalog',
            'status' => 'active',
            'category' => 'saludo',
            'title' => 'Saludo',
            'description' => 'Saludo de primer contacto.',
            'content' => $contenido = ['title' => 'Saludo', 'description' => 'Saludo de primer contacto.', 'turns' => [['role' => 'person', 'text' => 'Hola, buenas tardes']], 'facts' => ['paymentState' => 'none', 'handoffRequested' => false], 'criteria' => [['id' => 'K1', 'text' => 'Saluda y ofrece ayuda.', 'kind' => 'must']]],
            'content_hash' => hash('sha256', json_encode($contenido)),
            'revision' => 1,
            'created_by_admin_id' => $root->id,
            'created_by_name' => $root->name,
        ]);
        $run = StudioLabRun::query()->forceCreate([
            'uuid' => '00000000-0000-4000-8000-00000000a001',
            'client_request_id' => (string) Str::uuid(),
            'target_kind' => StudioLabRun::TARGET_BASELINE,
            'target_version_id' => null,
            'target_label' => 'Línea base del código',
            'target_content_hash' => str_repeat('a', 64),
            'target_snapshot' => ['kind' => 'baseline'],
            'catalog_version' => 'lab-catalog-dorado',
            'mode' => 'openai',
            'model' => 'gpt-lab-pruebas',
            'prompt_version' => StudioLabPrompts::PROMPT_VERSION,
            'prices' => ['input' => 400000, 'output' => 1600000, 'actorMaxOutputTokens' => 400, 'judgeMaxOutputTokens' => 900],
            'status' => StudioLabRun::STATUS_RUNNING,
            'case_count' => 2,
            'estimate_expected_micros' => 10,
            'estimate_max_micros' => 20,
            'accepted_max_micros' => 20,
            'budget_day' => '2026-11-22',
            'created_by_admin_id' => $root->id,
            'created_by_name' => $root->name,
        ]);
        $comun = ['run_id' => $run->id, 'scenario_id' => $escenario->id, 'scenario_code' => 'C-01', 'scenario_revision' => 1, 'scenario_snapshot' => $escenario->content, 'budget_day' => '2026-11-22', 'reserved_micros' => 10, 'overrun_micros' => 0];
        StudioLabRunCase::query()->forceCreate($comun + [
            'position' => 1,
            'status' => StudioLabRunCase::STATUS_DONE,
            'outcome' => 'pass',
            'reply' => 'Buenas tardes, ¿en qué le puedo ayudar?',
            'checks' => [['id' => 'lab-longitud', 'source' => 'deterministic', 'level' => 'info', 'title' => 'Longitud', 'detail' => '40 de 300']],
            'judge' => ['criteria' => [['id' => 'K1', 'verdict' => 'pass', 'evidence' => 'Buenas tardes', 'reason' => 'Saluda.']], 'rules' => [], 'risks' => [], 'summary' => 'Bien.'],
            'calls' => [['role' => 'actor', 'class' => null, 'inputTokens' => 10, 'outputTokens' => 5, 'costMicros' => 3, 'latencyMs' => 1]],
            'cost_micros' => 5,
            'input_tokens' => 10,
            'output_tokens' => 5,
        ]);
        StudioLabRunCase::query()->forceCreate($comun + [
            'position' => 2,
            'status' => StudioLabRunCase::STATUS_ERROR,
            'error_code' => 'timeout',
            'cost_micros' => 10,
        ]);

        return [$run, StudioLabRunCase::query()->where('run_id', $run->id)->orderBy('position')->get()->all()];
    }
}
