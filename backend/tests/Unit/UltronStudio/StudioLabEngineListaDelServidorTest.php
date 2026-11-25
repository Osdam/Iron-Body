<?php

namespace Tests\Unit\UltronStudio;

use App\Models\MarketingKnowledgeItem;
use App\Models\Plan;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as C;
use App\Services\Marketing\Ultron\UltronPlansOverview as O;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use App\Services\UltronStudio\StudioLabEngineCase;
use App\Services\UltronStudio\StudioLabEngineFlow;
use App\Services\UltronStudio\StudioLabEngineModelClient;
use App\Services\UltronStudio\StudioLabModelClient;
use App\Services\UltronStudio\StudioPromptBundle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17 · PUERTA 2 · L1 EN EL LABORATORIO: un caso entero por el motor del
 * laboratorio ({@see StudioLabEngineCase::run()}) con el sello del compilador
 * 8 y un cliente que solo repite lo que se le da (sin modelo ni red).
 *
 * El turno es el de E-001 («¿Qué planes tienen y cuánto cuesta la
 * mensualidad?») con el catálogo de la prueba real. Con la lista del
 * servidor, el bloque `final` del caso lleva la forma de la lista (`list`:
 * `server` o `markers` y si traía la ranura; especificación §1.4, aditivo) y
 * mide el texto con la lista que compone Laravel puesta, contra el tope de la
 * lista (320). Con el sello del 7, `final` no lleva `list` (la forma de
 * siempre).
 *
 * Cómo cae sin el cambio: la ranura no es un marcador para el laboratorio
 * (`plans_overview/missing` y respaldo) y `final` no lleva `list`.
 */
class StudioLabEngineListaDelServidorTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const VERSION = 'c0000000-0000-4000-8000-0000000001c8';

    /** El catálogo de la prueba real: id → nombre, precio, días y beneficios. */
    private const CATALOGO = [
        3 => ['Plan Valera', 65000, 30, ['Acceso total al gimnasio', 'Asesoría semi personalizada']],
        4 => ['Plan Mensual', 80000, 30, ['Acceso total al gimnasio', 'Clases grupales ilimitadas']],
        8 => ['Élite', 180000, 30, ['Acceso total al gimnasio', 'Entrenador personal dedicado']],
    ];

    private const LISTA = 'Plan Valera ($65.000 COP); Plan Mensual ($80.000 COP); Élite ($180.000 COP)';

    private const ESCENARIO = [
        'turns' => [['role' => 'prospect', 'text' => '¿Qué planes tienen y cuánto cuesta la mensualidad?']],
        'facts' => ['paymentState' => 'none', 'handoffRequested' => false],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-23 14:05:00', 'UTC'));
        config()->set('marketing.ultron.enabled', true);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::fake();
        $orden = 0;
        foreach (self::CATALOGO as $id => [$nombre, $precio, $dias, $beneficios]) {
            (new Plan)->forceFill([
                'id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias,
                'active' => true, 'sellable' => true, 'sort_order' => ++$orden, 'is_recommended' => false,
                'benefits' => json_encode($beneficios),
            ])->save();
        }
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{string, string, string, bool}> [borrador, forma, el texto con la lista puesta, ranura] */
    public static function borradores(): array
    {
        return [
            'forma B con la ranura' => [
                'Claro, con gusto. Le comparto nuestros planes: {{'.O::SLOT.'}}. ¿Cuál le interesa más?', O::SHAPE_SERVER,
                'Claro, con gusto. Le comparto nuestros planes: '.self::LISTA.'. ¿Cuál le interesa más?', true,
            ],
            'forma B sin ranura' => [
                'Claro, con gusto. Todos incluyen acceso total al gimnasio. ¿Cuál le interesa más?', O::SHAPE_SERVER,
                'Claro, con gusto. Todos incluyen acceso total al gimnasio. '.O::HEADER_ALL_MONTHLY.self::LISTA.'. ¿Cuál le interesa más?', false,
            ],
            'forma A entera y en orden' => [
                'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?', O::SHAPE_MARKERS,
                'Claro. Tenemos Plan Valera ($65.000 COP), Plan Mensual ($80.000 COP) y Élite ($180.000 COP), todos de un mes. ¿Cuál le interesa más?', false,
            ],
        ];
    }

    #[DataProvider('borradores')]
    public function test_con_el_compilador_8_el_caso_lleva_la_forma_de_la_lista_y_mide_la_lista_puesta(string $borrador, string $forma, string $puesta, bool $ranura): void
    {
        $paquete = self::paquete();
        $this->assertSame([8, true], [$paquete['sp']['k'], O::serverList($paquete['sp'])]);

        $caso = $this->corre($paquete, $borrador);

        $this->assertSame('done', $caso['status']);
        $final = $caso['engine']['final'];
        $this->assertSame(['strategist', 'composer', 'check1', 'critic'], $caso['engine']['path'], 'una sola comprobación: la lista vale a la primera');
        $this->assertSame(['model', null, [], 320, true, false], [$final['origin'], $final['cause'], $final['codes'], $final['cap'], $final['withinCap'], $final['trimmed']]);
        $this->assertSame(UltronReplyFitter::measure($puesta), $final['chars'], 'mide el texto con la lista que compone Laravel');
        $this->assertSame(['shape' => $forma, 'slot' => $ranura], $final['list'] ?? null);
    }

    /** Con el sello del 7 (un token de antes), la forma de siempre: sin `list`. */
    public function test_con_el_compilador_7_el_final_no_lleva_la_forma_de_la_lista(): void
    {
        $paquete = self::paquete();
        $paquete['sp']['k'] = 7;

        $caso = $this->corre($paquete, 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?');

        $this->assertSame('done', $caso['status']);
        $this->assertSame('model', $caso['engine']['final']['origin']);
        $this->assertArrayNotHasKey('list', $caso['engine']['final']);
    }

    /** @return array{roles:array<string,string>,sp:array<string,mixed>,bundleHash:string} el paquete de la v4 («profesional», 200) con el compilador de hoy */
    private static function paquete(): array
    {
        $parte = self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]);

        $historical = C::compile($parte['governed'], 8);
        $bundle = StudioPromptBundle::rolesFor($parte['governed'], self::VERSION, $parte['contentHash']);
        $bundle['roles'] = $historical['roles'];
        $bundle['bundleHash'] = $historical['bundleHash'];
        $bundle['sp']['k'] = 8;
        $bundle['sp']['h'] = $historical['bundleHash'];

        return $bundle;
    }

    /**
     * El caso por el motor del laboratorio, con un cliente que devuelve la
     * estrategia de la lista, el borrador dado y el revisor aprobando. Si el
     * recorrido pide otro nodo, el caso no vale (lo dice el estado).
     *
     * @param  array<string,mixed>  $paquete
     * @return array<string,mixed>
     */
    private function corre(array $paquete, string $borrador): array
    {
        $salidas = [
            StudioLabEngineFlow::STRATEGIST => [
                'strategy_goal' => 'informar', 'next_state' => 'NEW_LEAD', 'intent' => 'pricing_question', 'recommended_plan_id' => 3,
                'main_barrier' => null, 'next_best_action' => 'answer', 'confidence' => 0.9, 'tools_requested' => [], 'staff_review_reason' => null,
                'reason' => 'caso de la prueba', 'human_handoff_requested' => false, 'human_handoff_reason' => null, 'human_handoff_evidence' => null,
                'conversation_goal' => 'recommend', 'information_needed' => [], 'question_needed' => false, 'response_goal' => 'presentar los planes de la lista',
                'buying_signal' => 'weak', 'closing_opportunity' => false, 'payment_opportunity' => false, 'app_support_opportunity' => false, 'recommendation_goal' => 'compare_plans',
            ],
            StudioLabEngineFlow::COMPOSERS[0] => ['reply_draft' => $borrador, 'uses_price_placeholder' => false, 'questions_asked' => substr_count($borrador, '?'), 'plans_mentioned' => 3],
            StudioLabEngineFlow::CRITICS[0] => ['verdict' => 'pass', 'issues' => [], 'notes' => '', 'hard_fail' => null],
        ];
        $cliente = new class($salidas) implements StudioLabEngineModelClient
        {
            /** @param array<string,array<string,mixed>> $salidas */
            public function __construct(private readonly array $salidas) {}

            public function completeEngine(string $node, string $system, string $user, int $timeoutSeconds): array
            {
                if (! array_key_exists($node, $this->salidas)) {
                    return ['status' => StudioLabModelClient::STATUS_ERROR, 'output' => null, 'usage' => null, 'class' => 'not_scripted', 'http' => null, 'latencyMs' => 0, 'exception' => null];
                }

                return ['status' => StudioLabModelClient::STATUS_OK, 'output' => $this->salidas[$node], 'usage' => ['inputTokens' => 0, 'outputTokens' => 0, 'reasoningTokens' => 0], 'class' => null, 'http' => null, 'latencyMs' => 0, 'exception' => null];
            }
        };

        return app(StudioLabEngineCase::class)->run($paquete, self::ESCENARIO, $cliente, static fn (): int => 0);
    }
}
