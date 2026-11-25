<?php

namespace Tests\Unit\UltronStudio;

use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioLabEngineCase;
use App\Services\UltronStudio\StudioLabEngineCheck;
use App\Services\UltronStudio\StudioLabEngineContext;
use App\Services\UltronStudio\StudioLabFakeClient;
use App\Services\UltronStudio\StudioLabPrompts;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regresión con los 25 casos REALES c8. Los textos no se arreglan ni se
 * convierten en salidas ideales. Repetir una salida grabada comprueba guardas
 * y serialización, nunca mide qué generaría c9. El 0/20 y el 12/20 históricos
 * permanecen explícitos; esta prueba no concede aceptación al compilador nuevo.
 */
class StudioLabEnginePt17C8CorpusTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../../fixtures/ultron-studio/pt17-borradores-reales-c8.json';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.enabled', true);
        config()->set('meta.enabled', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::preventStrayRequests();
        foreach ([3 => ['Plan Valera', 65000], 4 => ['Plan Mensual', 80000], 8 => ['Élite', 180000]] as $id => [$name, $price]) {
            (new Plan)->forceFill([
                'id' => $id, 'name' => $name, 'price' => $price, 'duration_days' => 30,
                'active' => true, 'sellable' => true, 'sort_order' => $id, 'is_recommended' => false,
                'benefits' => json_encode(['Acceso al gimnasio']),
            ])->save();
        }
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atención',
            'content' => 'Lunes a viernes de 5 a.m. a 10 p.m. Sábados y domingos de 8 a.m. a 2 p.m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
            'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
        ]);
    }

    public function test_el_corpus_conserva_0_de_20_primeros_y_12_de_20_finales_historicos(): void
    {
        $fixture = self::fixture();
        $this->assertCount(25, $fixture['cases']);
        $this->assertSame('335d8fe19c6fc2775dd0d4a96062ff7134a440cd68693191a7e51a25cb51cd88', $fixture['sourceSha256']);
        $this->assertSame('f5cd9bfe0a7de62ac8a067e7c51867476be9c4c1ed91448aca69a0f39d922e37', $fixture['textsSha256']);
        $texts = array_map(static fn (array $case): array => array_column($case['engine']['drafts'], 'text'), $fixture['cases']);
        $this->assertSame($fixture['textsSha256'], hash('sha256', json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'ningún texto se repara en la fixture');
        $plans = array_values(array_filter($fixture['cases'], static fn (array $case): bool => $case['scenarioCode'] !== 'E-006'));
        $this->assertCount(20, $plans);
        $first = 0;
        $validFinal = 0;
        $missing = 0;
        $names = 0;
        foreach ($plans as $case) {
            $draft = $case['engine']['drafts'][0];
            $first += $draft['check']['pass'] === true ? 1 : 0;
            $validFinal += $case['engine']['final']['origin'] === 'model' ? 1 : 0;
            $missing += in_array('plans_overview/missing', $draft['check']['codes'], true) ? 1 : 0;
            $names += in_array('plans_overview/name_written', $draft['check']['codes'], true) ? 1 : 0;
            $this->assertStringNotContainsString('{{LISTA_DE_PLANES}}', $draft['text']);
        }
        $this->assertSame([0, 12, 13, 7], [$first, $validFinal, $missing, $names]);
    }

    public function test_las_guardas_c9_rechazan_los_25_primeros_borradores_originales(): void
    {
        $checker = app(StudioLabEngineCheck::class);
        foreach (self::fixture()['cases'] as $case) {
            $turn = $this->turn($case, 9);
            $raw = $case['engine']['drafts'][0]['text'];
            $check = $checker->respond($turn, ['attempt' => 1, 'draft' => [
                'reply_draft' => $raw, 'intent' => $case['engine']['strategy']['intent'],
                'recommended_plan_id' => 3, 'tools_requested' => [],
            ]], 0);
            $label = $case['run'].'/'.$case['scenarioCode'];
            $this->assertSame(200, $check['statusCode'], $label);
            $this->assertFalse($check['body']['pass'], $label.': rechazar una salida histórica no equivale a producir una nueva válida');
            $this->assertContains('plans_overview', array_column($check['body']['defects'], 'code'), $label);
        }
        Http::assertNothingSent();
    }

    public function test_el_motor_baseline_c9_tambien_entrega_el_candidato_al_juez_sin_cambiar_su_sp_legacy(): void
    {
        $bundle = StudioPromptBundle::rolesFor(UltronPromptCompiler::BASELINE);
        $this->assertArrayNotHasKey('k', $bundle['sp'], 'la línea base conserva su sello histórico sin parche');
        $scenario = ['turns' => [['role' => 'prospect', 'text' => 'Hola']], 'facts' => ['paymentState' => 'none', 'handoffRequested' => false]];
        $result = app(StudioLabEngineCase::class)->run($bundle, $scenario, new StudioLabFakeClient, static fn (): int => 0);
        $this->assertSame('done', $result['status']);
        $this->assertArrayHasKey('judgment', $result);
        $this->assertSame($result['engine']['final']['origin'], $result['judgment']['prediction']['origin']);
        $this->assertIsString($result['judgment']['candidate_rendered']);
        Http::assertNothingSent();
    }

    public function test_el_juez_recibe_el_render_real_y_el_raw_intacto_de_los_25_ultimos_borradores(): void
    {
        $checker = app(StudioLabEngineCheck::class);
        $target = ['engine' => ['compiler' => 9], 'configs' => StudioCatalog::baseline()['configs']];
        $historicalModel = 0;
        foreach (self::fixture()['cases'] as $case) {
            $turn = $this->turn($case, 8);
            $last = $case['engine']['drafts'][count($case['engine']['drafts']) - 1];
            $raw = $last['text'];
            $strategy = ['intent' => $case['engine']['strategy']['intent'], 'tools_requested' => []];
            $critic = $last['critic'] ?? [];
            $prediction = $checker->predict($turn, ['reply_draft' => $raw, 'recommended_plan_id' => 3, 'critic' => [
                'verdict' => $critic['verdict'] ?? 'pass', 'issues' => $critic['issues'] ?? [], 'hard_fail' => $critic['hardFail'] ?? null,
            ]], $strategy);
            $this->assertSame($case['engine']['final']['origin'], $prediction['origin'], $case['run'].'/'.$case['scenarioCode'].': origen histórico c8');
            $historicalModel += $case['scenarioCode'] !== 'E-006' && $prediction['origin'] === 'model' ? 1 : 0;
            $rendered = $checker->judgeText($turn, $raw, $strategy, 3);
            $input = json_decode(StudioLabPrompts::engineJudgeInput($target, $case['scenario'], $raw, ['candidate_rendered' => $rendered, 'prediction' => $prediction]), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($raw, $input['borrador_modelo']);
            $this->assertSame($rendered, $input['respuesta']);
            $this->assertSame($prediction['origin'], $input['observacion_servidor']['origin']);
            $this->assertSame($prediction['codes'], $input['observacion_servidor']['codes']);
            $this->assertSame(320, $input['observacion_servidor']['cap']);
            if ($prediction['origin'] === 'model') {
                $this->assertStringNotContainsString('{{LISTA_DE_PLANES}}', $input['respuesta']);
                $this->assertStringContainsString('Plan Valera ($65.000 COP)', $input['respuesta']);
                $this->assertStringContainsString('Plan Mensual ($80.000 COP)', $input['respuesta']);
                $this->assertStringContainsString('Élite ($180.000 COP)', $input['respuesta']);
            }
        }
        $this->assertSame(12, $historicalModel, 'reproducir textos c8 no es medir generación c9');
        Http::assertNothingSent();
    }

    private function turn(array $case, int $compiler): array
    {
        return app(StudioLabEngineContext::class)->build($case['scenario'], ['m' => 'studio', 'o' => 1, 'k' => $compiler, 'n' => 200, 't' => 'profesional'])['turn'];
    }

    private static function fixture(): array
    {
        return json_decode(file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
    }
}
