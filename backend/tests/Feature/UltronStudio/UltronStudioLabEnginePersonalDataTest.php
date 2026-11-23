<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Models\UltronStudio\StudioLabEvent;
use App\Models\UltronStudio\StudioLabRun;
use App\Models\UltronStudio\StudioLabRunCase;
use App\Models\UltronStudio\StudioRule;
use App\Models\UltronStudio\StudioVersion;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioLabEngineFlow;
use App\Services\UltronStudio\StudioLabPrompts;
use App\Services\UltronStudio\StudioLabRunner;
use App\Services\UltronStudio\StudioLabRuns;
use App\Services\UltronStudio\StudioLabStepJob;
use App\Services\UltronStudio\StudioPromptBundle;
use App\Services\UltronStudio\StudioPublicationDeclaration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B1 DE LA REVISIÓN DE SEGURIDAD DEL APARTADO 5 (`informe-security-lab.md`):
 * el filtro de datos personales tiene que ver TODO lo que el modo motor
 * compila y envía al proveedor, no solo lo que viaja en la simulación.
 *
 * La simulación lleva las R-* y como mucho 20 reglas S-NNN
 * ({@see StudioLabPrompts::MAX_STUDIO_RULES}); el modo motor compila las
 * frases de TODAS las S-NNN vivas de la versión (como al publicar). La sonda
 * de seguridad: 20 reglas S documentales y una S-021 que prohíbe «llama al
 * 3001234567». Antes de la corrección, la S-021 quedaba fuera del filtro y
 * su cifra llegaba a los prompts compilados que se envían.
 *
 * Por HTTP, con el proveedor guionizado en la frontera (sin guion: ninguna
 * llamada pasaría sin verse) y la red prohibida. Y la defensa del runner: una
 * ejecución cuya instantánea ya trae una frase así no sale a la red.
 */
class UltronStudioLabEnginePersonalDataTest extends TestCase
{
    use LabFixtures;
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const MODEL = 'gpt-4.1-mini';

    private const PHONE_PHRASE = 'llama al 3001234567';

    private Admin $root;

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
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
    }

    public function test_b1_la_frase_de_una_regla_que_no_viaja_en_la_simulacion_para_el_modo_motor_con_409_sin_red_ni_filas(): void
    {
        $version = $this->versionWithRules(self::PHONE_PHRASE);
        // Lo que hace alcanzable el fallo: la S-021 queda fuera de la simulación y su frase SÍ se compila en el motor.
        $this->assertSame(['S-021'], StudioLabPrompts::selectRules($version->content['rules'])['excluded']);
        $this->assertContains(self::PHONE_PHRASE, StudioPromptBundle::governedFrom($version->content)[StudioPublicationDeclaration::GOVERNED_PHRASES]);

        $cliente = $this->script([]);
        $antes = $this->rowCounts();
        $cuerpo = ['target' => ['kind' => 'version', 'uuid' => $version->uuid], 'scenarioUuids' => [$this->scenarioUuid('C-01'), $this->scenarioUuid('C-02')], 'fidelity' => 'engine'];

        $this->lab('POST', 'estimate', $this->root, $cuerpo)
            ->assertStatus(409)->assertJsonPath('code', 'target_contains_personal_data')->assertJsonPath('fields', ['rules.S-021']);
        $this->lab('POST', 'runs', $this->root, $cuerpo + ['acceptedMaxCostMicros' => 2_000_000, 'clientRequestId' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'target_contains_personal_data')->assertJsonPath('fields', ['rules.S-021']);

        // Ni una llamada al modelo, ni a la red, ni un job, ni una fila del laboratorio.
        $this->assertSame([[], []], [$cliente->engineCalls, $cliente->calls]);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame($antes, $this->rowCounts());

        // La simulación de ESA misma versión se lanza como hoy: la S-021 no viaja y lo dice `excludedRules`.
        unset($cuerpo['fidelity']);
        $sim = $this->lab('POST', 'estimate', $this->root, $cuerpo)->assertOk()->json('data');
        $this->assertSame([true, null, ['S-021'], StudioLabPrompts::PROMPT_VERSION], [$sim['allowed'], $sim['refusal'], $sim['excludedRules'], $sim['promptVersion']]);
        $uuid = $this->lab('POST', 'runs', $this->root, $cuerpo + ['acceptedMaxCostMicros' => $sim['estimate']['maxMicros'], 'clientRequestId' => (string) Str::uuid()])
            ->assertCreated()->assertJsonMissingPath('data.fidelityMode')->json('data.uuid');
        $this->assertSame(StudioLabPrompts::PROMPT_VERSION, StudioLabRun::query()->where('uuid', $uuid)->value('prompt_version'));
        Queue::assertPushed(StudioLabStepJob::class, 1);
    }

    public function test_b1_la_misma_frase_en_una_regla_que_si_viaja_ya_se_paraba_y_sigue_igual(): void
    {
        // Con 20 S en total, la de la frase va en la simulación: el filtro de siempre la para en los dos modos.
        $version = $this->versionWithRules(self::PHONE_PHRASE, 20);
        $cuerpo = ['target' => ['kind' => 'version', 'uuid' => $version->uuid], 'scenarioUuids' => [$this->scenarioUuid('C-01')]];

        foreach ([[], ['fidelity' => 'engine']] as $modo) {
            $this->lab('POST', 'estimate', $this->root, $cuerpo + $modo)
                ->assertStatus(409)->assertJsonPath('code', 'target_contains_personal_data')->assertJsonPath('fields', ['rules.S-020']);
        }
    }

    public function test_b1_defensa_del_runner_una_instantanea_con_una_frase_que_no_pasa_no_sale_a_la_red(): void
    {
        // Una ejecución del motor de verdad, con una frase limpia en la S-021…
        $version = $this->versionWithRules('llama ya mismo');
        $cuerpo = ['target' => ['kind' => 'version', 'uuid' => $version->uuid], 'scenarioUuids' => [$this->scenarioUuid('C-01')], 'fidelity' => 'engine'];
        $estimacion = $this->lab('POST', 'estimate', $this->root, $cuerpo)->assertOk()->json('data');
        $this->assertTrue($estimacion['allowed'], (string) json_encode($estimacion['refusal']));
        $limpia = (string) $this->lab('POST', 'runs', $this->root, $cuerpo + ['acceptedMaxCostMicros' => $estimacion['estimate']['maxMicros'], 'clientRequestId' => (string) Str::uuid()])
            ->assertCreated()->json('data.uuid');

        // …y su gemela con la instantánea que habría congelado el código de antes de B1 (o un
        // filtro más estricto que el de cuando se creó): la frase con el teléfono y la huella
        // del paquete RECALCULADA, para que la comprobación de la huella no la pare antes.
        $forjada = $this->forgedTwin($limpia, 'llama ya mismo', self::PHONE_PHRASE);
        $cliente = $this->script([]);
        $cliente->engineScript = [['node' => StudioLabEngineFlow::STRATEGIST, 'class' => 'timeout']];

        $run = $this->drain($forjada->uuid);
        $caso = StudioLabRunCase::query()->where('run_id', $run->id)->sole();

        $this->assertSame([StudioLabRunCase::STATUS_ERROR, StudioLabRunner::CLASS_BLOCKED_INPUT, []], [$caso->status, $caso->error_code, $caso->calls]);
        $this->assertSame([[], []], [$cliente->engineCalls, $cliente->calls], 'ninguna llamada al proveedor');
        $this->assertSame(0, (int) $caso->cost_micros, 'no se envió nada: no se cobra');
        Http::assertNothingSent();
    }

    public function test_b1_las_rutas_de_lo_gobernado_que_no_pasa(): void
    {
        $frases = StudioPublicationDeclaration::GOVERNED_PHRASES;
        $gobernado = ['persona.emojis' => false, 'persona.maxChars' => 200, 'persona.tone' => 'profesional', 'strategy.goal' => 'vender', $frases => ['gratis', self::PHONE_PHRASE]];
        $porRegla = [['code' => 'S-003', 'phrases' => ['gratis']], ['code' => 'S-021', 'phrases' => [self::PHONE_PHRASE]], ['code' => 'S-030', 'phrases' => ['oiga', self::PHONE_PHRASE]]];

        // Cada regla que aporta la frase, en el orden del contenido; sin reglas, la clave de las frases.
        $this->assertSame(['rules.S-021', 'rules.S-030'], StudioLabRuns::governedPersonalData($gobernado, $porRegla));
        $this->assertSame([$frases], StudioLabRuns::governedPersonalData($gobernado));
        // Un valor gobernado también se mira (hoy salen de un catálogo cerrado).
        $this->assertSame(['configs.strategy.goal'], StudioLabRuns::governedPersonalData(['strategy.goal' => 'ventas@gimnasio.com', $frases => ['gratis']], $porRegla));
        $this->assertSame([], StudioLabRuns::governedPersonalData(['persona.tone' => 'profesional', $frases => ['gratis', 'oiga']], $porRegla));
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    /**
     * Una versión aprobada («profesional, 200») con `$total` reglas S-NNN
     * vivas por prioridad normal: todas documentales (sembradas) salvo la
     * última, que prohíbe `$frase` sin condición y se crea por la API (con su
     * línea del libro: sin ella, los cuatro ojos por regla fallan cerrado y
     * nadie aprueba la versión). La escribe Ana, que crea la versión; la
     * aprueba otra persona.
     */
    private function versionWithRules(string $frase, int $total = 21): StudioVersion
    {
        $ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana');
        $revisa = $this->person(Admin::ROLE_SUPER_ADMIN, 'Revisa');
        for ($i = 1; $i < $total; $i++) {
            $campos = ['description' => "Regla viva {$i}", 'type' => 'style', 'priority' => 'normal', 'layers' => ['persona'],
                'refs' => ['configs' => [], 'edges' => [], 'knowledgeKeys' => [], 'knowledgeCategories' => []], 'origin' => null, 'conditions' => null];
            StudioRule::query()->forceCreate($campos + ['code' => sprintf('S-%03d', $i), 'source' => 'studio', 'enforcement' => 'none', 'status' => 'draft',
                'revision' => 1, 'content_hash' => StudioContentHasher::ruleHash($campos), 'client_request_id' => (string) Str::uuid()]);
        }
        $codigo = $this->newStudioRule($ana, ['conditions' => self::conditionsOf([['kind' => 'forbid', 'target' => 'text', 'op' => 'contains', 'phrases' => [$frase]]])])
            ->assertCreated()->json('data.code');
        $this->assertSame(sprintf('S-%03d', $total), $codigo);

        return $this->approvedVersion($ana, $revisa, 'Con reglas del Studio', ['persona' => ['tone' => 'profesional', 'maxChars' => 200]]);
    }

    /**
     * Una copia, fila a fila, de una ejecución del motor EN COLA, con la frase
     * `$de` cambiada por `$a` en lo gobernado congelado y la huella del
     * paquete recalculada (las filas terminales no cambian, y la instantánea
     * es inmutable: se inserta otra).
     */
    private function forgedTwin(string $uuid, string $de, string $a): StudioLabRun
    {
        $fila = (array) DB::table('ultron_studio_lab_runs')->where('uuid', $uuid)->first();
        $this->assertSame(StudioLabRun::STATUS_QUEUED, $fila['status']);
        $instantanea = json_decode((string) $fila['target_snapshot'], true);
        $motor = $instantanea['engine'];
        $frases = $motor['governed'][StudioPublicationDeclaration::GOVERNED_PHRASES];
        $this->assertContains($de, $frases);
        $frases = array_map(static fn (string $f): string => $f === $de ? $a : $f, $frases);
        sort($frases, SORT_STRING);
        $motor['governed'][StudioPublicationDeclaration::GOVERNED_PHRASES] = $frases;
        $motor['bundleHash'] = StudioPromptBundle::rolesFor($motor['governed'], $motor['version'], $motor['contentHash'])['bundleHash'];
        $instantanea['engine'] = $motor;

        $id = $fila['id'];
        unset($fila['id']);
        $nuevo = DB::table('ultron_studio_lab_runs')->insertGetId(['uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'target_snapshot' => json_encode($instantanea)] + $fila);
        foreach (DB::table('ultron_studio_lab_run_cases')->where('run_id', $id)->get() as $caso) {
            $caso = (array) $caso;
            unset($caso['id']);
            DB::table('ultron_studio_lab_run_cases')->insert(['run_id' => $nuevo] + $caso);
        }
        // La original se cancela: un caso a la vez en todo el sistema.
        $this->lab('POST', "runs/{$uuid}/cancel", $this->root)->assertOk();

        return StudioLabRun::query()->whereKey($nuevo)->sole();
    }

    /** @return array{runs:int,cases:int,events:int} */
    private function rowCounts(): array
    {
        return ['runs' => StudioLabRun::query()->count(), 'cases' => StudioLabRunCase::query()->count(), 'events' => StudioLabEvent::query()->count()];
    }
}
