<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Marketing\Ultron\ReferenceResolver;
use App\Services\Marketing\Ultron\UltronDecideToken;
use App\Services\Marketing\Ultron\UltronDraftCheck;
use App\Services\UltronStudio\StudioLabEngineCheck;
use App\Services\UltronStudio\StudioLabEngineFlow as F;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Tests\Support\HistoricalListTurn;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17 · PUERTA 1 · L4 · «LISTA FIRME» EN `ai/check` (decisión P-1 del
 * propietario del 24-11-2026; diagnóstico estructural, C4 y C5: «un segundo
 * borrador bueno se pierde si el tercero empeora»).
 *
 *  1. A la lista limpia de un turno de lista, el `POST ai/check` real contesta
 *     `pass`, `may_rewrite=false` y `budget.reason = list_final`, en el
 *     primer intento y en el segundo; el último intento y el tiempo siguen
 *     mandando. Cualquier otra cosa —un defecto, otro turno, Legacy—, como
 *     hoy. El laboratorio ({@see StudioLabEngineCheck::respond()}) contesta lo
 *     MISMO, byte a byte. (Riesgo R5, aceptado y escrito en
 *     `UltronDraftCheck::REASON_LIST_FINAL`: `may_rewrite` también significa
 *     ya «este borrador es definitivo».)
 *  2. EL COMPROBADOR DE S2: el `flujo-s2.json` REAL, ejecutado con Node
 *     (`tests/fixtures/ultron-studio/tools/recorrer-s2-lab.cjs`, el de T3), con
 *     el `decide` real y las respuestas reales de `ai/check`: tras un primer
 *     borrador de un solo plan (el servidor pide reescribir) y un segundo que
 *     es la lista firme, el revisor 2 lo rechaza sin fallo grave y «Decidir
 *     Intento 3» NO lanza el tercer borrador: Preparar Commit recibe el
 *     segundo, con ese veredicto. Sin tocar n8n. El port del laboratorio
 *     recorre lo mismo.
 */
class UltronCheckListFinalTest extends TestCase
{
    use HistoricalListTurn;
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000f2';

    private const FLOW = 'n8n/flujos/ultron-studio-s2/flujo-s2.json';

    private const HARNESS = 'tests/fixtures/ultron-studio/tools/recorrer-s2-lab.cjs';

    private const CATALOGO = [
        [25, 'PLAN DÍA', 10000, 1], [2, 'Plan Semana', 45000, 7], [3, 'Plan Valera', 65000, 30], [4, 'Plan Mensual', 80000, 30],
        [5, 'Trimestre', 210000, 90], [8, 'Élite', 180000, 30],
    ];

    private const PANORAMA = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private const TRES = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?';

    /** Un primer borrador real de la medición: un solo plan (el servidor pide la lista). */
    private const UN_PLAN = 'Le cuento que el Plan Valera incluye acceso ilimitado al gimnasio, zona de pesas, asesoría semi personalizada y valoración inicial, todo por {{PLAN_1}}. Para orientarle mejor, ¿ha entrenado antes o es principiante?';

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-24 15:00:00', 'UTC'));
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.ultron.studio_config', 'canary');
        config()->set('marketing.ultron.studio_prompts', 'canary');
        config()->set('automation.rate_limits.marketing_ai', 100000);
        Http::fake();
        foreach (self::CATALOGO as $i => [$id, $nombre, $precio, $dias]) {
            (new Plan)->forceFill([
                'id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias,
                'active' => true, 'sellable' => true, 'sort_order' => $i + 1, 'is_recommended' => false,
                'benefits' => json_encode(['Acceso total al gimnasio']),
            ])->save();
        }
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
        ]);
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150544001', 'meta_user_id' => '573150544001', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1 ───────────────────────────────────────────────────────────────────

    public function test_1_ai_check_da_por_firme_la_lista_limpia_del_turno_y_el_laboratorio_contesta_lo_mismo(): void
    {
        $this->assertSame(['list_final', 'list_final'], [UltronDraftCheck::REASON_LIST_FINAL, StudioLabEngineCheck::REASON_LIST_FINAL]);
        $m = $this->entrante(self::PANORAMA);
        $decide = $this->decide($m, true);
        $token = (string) $decide['decide_token'];

        // [borrador, intento, segundos] → [pass, may_rewrite, rewrite, motivo]
        $casos = [
            'la lista limpia, primer intento' => [self::TRES, 1, 0, [true, false, false, UltronDraftCheck::REASON_LIST_FINAL]],
            'la lista limpia, segundo intento' => [self::TRES, 2, 9, [true, false, false, UltronDraftCheck::REASON_LIST_FINAL]],
            'el último intento sigue mandando' => [self::TRES, 3, 12, [true, false, false, UltronDraftCheck::REASON_LAST_ATTEMPT]],
            'el tiempo sigue mandando' => [self::TRES, 2, 26, [true, false, false, UltronDraftCheck::REASON_TIME_BUDGET]],
            'un solo plan: se reescribe' => [self::UN_PLAN, 1, 0, [false, true, true, null]],
            'tutea: se reescribe' => ['Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál te interesa más?', 2, 0, [false, true, true, null]],
        ];
        foreach ($casos as $nombre => [$borrador, $intento, $segundos, $esperado]) {
            Carbon::setTestNow(Carbon::parse('2026-11-24 15:00:00', 'UTC')->addSeconds($segundos));
            $real = $this->check($m, $token, $borrador, $intento, SalesIntents::PRICING_QUESTION)->assertOk()->json();
            $this->assertSame($esperado, [$real['pass'], $real['may_rewrite'], $real['rewrite'], $real['budget']['reason']], $nombre);
            $lab = app(StudioLabEngineCheck::class)->respond($this->turnoDe($m, $token), ['attempt' => $intento, 'draft' => ['reply_draft' => $borrador, 'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => null, 'tools_requested' => []]], $segundos);
            $this->assertSame([200, $real], [$lab['statusCode'], $lab['body']], "{$nombre}: el laboratorio contesta lo mismo");
        }

        // Otro turno: la respuesta limpia del horario no es una lista firme (no hay lista): se puede pedir otra, como hoy.
        Carbon::setTestNow(Carbon::parse('2026-11-24 15:00:00', 'UTC'));
        $h = $this->entrante('¿Qué horario tienen?');
        $th = (string) $this->decide($h, true)['decide_token'];
        $real = $this->check($h, $th, 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Le gustaría conocer los planes?', 1, SalesIntents::SCHEDULE_QUESTION)->assertOk()->json();
        $this->assertSame([true, true, false, null], [$real['pass'], $real['may_rewrite'], $real['rewrite'], $real['budget']['reason']], 'sin lista, como hoy');

        // Legacy: la comprobación ni mira.
        $l = $this->entrante(self::PANORAMA);
        $tl = (string) $this->decide($l, false)['decide_token'];
        $real = $this->check($l, $tl, self::TRES, 1, SalesIntents::PRICING_QUESTION)->assertOk()->json();
        $this->assertSame(['legacy', false, UltronDraftCheck::REASON_LEGACY], [$real['mode'], $real['may_rewrite'], $real['budget']['reason']], 'Legacy no cambia');
    }

    // ── 2 ───────────────────────────────────────────────────────────────────

    public function test_2_el_flujo_s2_real_no_pide_el_tercer_borrador_tras_la_lista_firme(): void
    {
        $this->assertTrue(Process::run(['node', '--version'])->successful(), 'El comprobador de S2 necesita Node en el PATH (como T3).');
        $m = $this->entrante(self::PANORAMA);
        $decide = $this->decide($m, true);
        $this->assertSame('studio', $decide['studio_prompts']['mode']);
        $token = (string) $decide['decide_token'];

        // Las respuestas REALES de `ai/check` a los dos borradores, como las recibe el nodo HTTP.
        $check1 = $this->check($m, $token, self::UN_PLAN, 1, SalesIntents::PRICING_QUESTION)->assertOk()->json();
        $check2 = $this->check($m, $token, self::TRES, 2, SalesIntents::PRICING_QUESTION)->assertOk()->json();
        $this->assertSame([true, false], [$check1['rewrite'], $check2['may_rewrite']], 'el servidor pide la lista y después la da por firme');
        $http = static fn (array $cuerpo): array => ['body' => $cuerpo, 'headers' => ['content-type' => 'application/json'], 'statusCode' => 200, 'statusMessage' => 'OK'];
        $salida = static fn (array $json): array => ['output' => [['content' => [['text' => $json]]]], 'usage' => ['input_tokens' => 9000, 'output_tokens' => 120]];
        $revisor2 = ['verdict' => 'fail', 'score' => 0.4, 'issues' => ['naturalness', 'progressive_disclosure'], 'notes' => 'Mezcla registros.', 'hard_fail' => null];
        $pins = [
            F::STRATEGIST => $salida([
                'strategy_goal' => 'informar', 'next_state' => 'NEW_LEAD', 'intent' => 'pricing_question', 'recommended_plan_id' => 3, 'main_barrier' => null,
                'next_best_action' => 'answer', 'confidence' => 0.9, 'tools_requested' => [], 'staff_review_reason' => null, 'reason' => 'lista', 'human_handoff_requested' => false,
                'human_handoff_reason' => null, 'human_handoff_evidence' => null, 'conversation_goal' => 'recommend', 'information_needed' => [], 'question_needed' => false,
                'response_goal' => 'presentar la lista', 'buying_signal' => 'weak', 'closing_opportunity' => false, 'payment_opportunity' => false, 'app_support_opportunity' => false, 'recommendation_goal' => 'compare_plans',
            ]),
            F::COMPOSER => $salida(['reply_draft' => self::UN_PLAN, 'uses_price_placeholder' => false, 'questions_asked' => 1, 'plans_mentioned' => 1]),
            F::CHECK_1 => $http($check1),
            F::RETRY => $salida(['reply_draft' => self::TRES, 'uses_price_placeholder' => false, 'questions_asked' => 1, 'plans_mentioned' => 3]),
            F::CHECK_2 => $http($check2),
            F::CRITIC_2 => $salida($revisor2),
            // Ni «Composer Reintento 2» ni «Critic Comercial 3»: si el flujo los pidiera, el arnés lo diría («no prevista»).
        ];

        $real = $this->ejecuta($decide, $pins, $m);
        $this->assertSame('done', $real['status']);
        $this->assertSame([F::STRATEGIST, F::VALIDATE, F::COMPOSER, F::IF_CHECK_1, F::CHECK_1, F::READ_1, F::IF_REWRITE_1, F::NOTE_1, F::RETRY, F::IF_CHECK_2, F::CHECK_2, F::READ_2, F::IF_REWRITE_2, F::CRITIC_2, F::DECIDE_3, F::IF_THIRD, F::COMMIT], $real['order'], 'sin tercer borrador');
        $this->assertSame([self::TRES, 2, 'fail'], [$real['commit']['proposal']['reply_draft'], $real['commit']['critic']['attempt'], $real['commit']['critic']['verdict']], 'Preparar Commit recibe el segundo borrador con el veredicto del revisor 2');

        // El port del laboratorio recorre lo mismo.
        $lab = F::walk($decide, $decide['studio_prompts']['roles'], self::PANORAMA,
            function (string $nodo) use ($pins): array {
                $this->assertArrayHasKey($nodo, $pins, "el laboratorio llama a «{$nodo}»");

                return ['ok' => true, 'json' => $pins[$nodo]];
            },
            fn (int $k): array => $pins[$k === 1 ? F::CHECK_1 : F::CHECK_2]);
        $this->assertSame($real['order'], $lab['order']);
        $this->assertSame([self::TRES, 2], [$lab['commit']['reply_draft'], $lab['commit']['attempt']]);
        $this->assertSame($real['commit']['critic'], $lab['commit']['critic']);
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    /**
     * El flujo real con Node, como `StudioLabEngineFlowParityTest::ejecuta()`.
     *
     * @param  array<string,mixed>  $decide
     * @param  array<string,mixed>  $pins
     * @return array<string,mixed>
     */
    private function ejecuta(array $decide, array $pins, MarketingMessage $m): array
    {
        $raiz = dirname(__DIR__, 4);
        $evento = ['conversation_id' => (int) $m->conversation_id, 'message_id' => (int) $m->id, 'idempotency_key' => (string) $m->meta_message_id, 'text' => (string) $m->body, 'message_type' => 'text', 'channel' => 'whatsapp', 'event_id' => ''];
        $spec = tempnam(sys_get_temp_dir(), 'ultron-s2-lista-firme-');
        file_put_contents($spec, json_encode(['flow' => $raiz.'/'.self::FLOW, 'decide' => $decide, 'event' => $evento, 'pins' => $pins, 'mode' => 'lanza'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        try {
            $r = Process::timeout(60)->run(['node', $raiz.'/backend/'.self::HARNESS, $spec]);
        } finally {
            unlink($spec);
        }
        $this->assertTrue($r->successful(), 'el flujo real falló (¿pidió un nodo sin fijar?): '.$r->errorOutput());

        return json_decode($r->output(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function entrante(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.lf.'.uniqid(), 'status' => 'received',
        ]);
    }

    /** @return array<string,mixed> */
    private function decide(MarketingMessage $m, bool $negocia): array
    {
        $decide = $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id] + ($negocia ? ['prompt_contract' => 'ultron-prompts/1', 'generation_contract' => 'ultron-list-turn/1'] : []),
            $this->headers())->assertOk()->json();

        return $this->historicalListTurn($decide, $m);
    }

    private function check(MarketingMessage $m, string $token, string $borrador, int $intento, string $intent): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/check', [
            'conversation_id' => $m->conversation_id, 'message_id' => $m->id, 'decide_token' => $token, 'attempt' => $intento,
            'draft' => ['reply_draft' => $borrador, 'intent' => $intent, 'recommended_plan_id' => null, 'tools_requested' => []],
        ], $this->headers());
    }

    /**
     * El turno del laboratorio con lo que el motor tendría al comprobar ESTE
     * mensaje, como en `StudioLabEngineCheckParityTest`.
     *
     * @return array<string,mixed>
     */
    private function turnoDe(MarketingMessage $m, string $token): array
    {
        $firmado = app(UltronDecideToken::class)->peek($token, (int) $m->conversation_id, (int) $m->id);
        $this->assertTrue($firmado['valid']);
        $memoria = app(ConversationMemoryService::class);
        $leida = $memoria->load($this->conversation->fresh());

        return [
            'sp' => $firmado['extra']['sp'],
            'po' => $firmado['extra']['po'] ?? [],
            'catalog' => app(MarketingKnowledgeBaseService::class)->activePlans(),
            'inbound' => (string) $m->body,
            'phase' => app(CommercialPhaseMachine::class)->normalize($this->conversation->fresh()->commercial_phase),
            'memory' => $leida,
            'resolution' => app(ReferenceResolver::class)->resolve((string) $m->body, $leida, $memoria->sellablePlansForMemory()),
        ];
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }
}
