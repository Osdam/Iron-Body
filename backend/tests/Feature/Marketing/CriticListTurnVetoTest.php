<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Marketing\Ultron\CriticContract;
use App\Services\Marketing\Ultron\ReferenceResolver;
use App\Services\Marketing\Ultron\UltronDecideToken;
use App\Services\Marketing\Ultron\UltronReplyOrigin as Origin;
use App\Services\UltronStudio\StudioLabEngineCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17 · PUERTA 1 · L4 · EL VETO ACOTADO DEL REVISOR (decisión P-1 del
 * propietario del 24-11-2026: «El revisor solo podrá provocar fallback ante
 * errores graves, defectos de servidor o incumplimientos reales de seguridad,
 * exactitud o política. Los rechazos injustificados deben evitarse sin
 * eliminar las validaciones críticas»; diagnóstico estructural, causa C4).
 *
 * Por el `POST ai/commit` real, con la versión «profesional · 200» publicada
 * en la canaria y el catálogo vendible de producción, y al lado la predicción
 * del laboratorio ({@see StudioLabEngineCheck::predict()}) para el mismo turno.
 *
 *  1. En un turno de lista, un rechazo BLANDO (`fail` sin fallo duro) sobre
 *     la lista limpia del turno —la que `ai/check` da por buena— no produce
 *     respaldo: sale el texto del modelo, la fila lleva el veredicto y el
 *     aviso (`critic_advisory`) y el informe de origen lo dice. El
 *     laboratorio predice lo mismo.
 *  2. Siguen produciendo respaldo, como hoy: un fallo duro, un rechazo que
 *     señala seguridad, exactitud o política aunque no declare fallo duro
 *     ({@see CriticContract::VETO_DIMENSIONS}; uno por categoría), un defecto
 *     del servidor en el borrador, cualquier otro turno y Legacy.
 *  3. Un aviso no es un salvoconducto: las guardas del commit (la URL, los
 *     datos de tarjeta) siguen tumbando el borrador.
 *  4. El informe de origen solo marca el aviso en una fila del modelo con la
 *     marca del commit.
 */
class CriticListTurnVetoTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000f4';

    private const CATALOGO = [
        [25, 'PLAN DÍA', 10000, 1], [2, 'Plan Semana', 45000, 7], [3, 'Plan Valera', 65000, 30], [4, 'Plan Mensual', 80000, 30],
        [5, 'Trimestre', 210000, 90], [8, 'Élite', 180000, 30],
    ];

    private const PANORAMA = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    /** La lista limpia del turno. */
    private const TRES = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?';

    /** El rechazo blando de la medición: naturalidad y divulgación progresiva, sin fallo duro. */
    private const BLANDO = ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.4, 'issues' => ['naturalness', 'progressive_disclosure'], 'notes' => 'Mezcla registros y da tres planes sin que la persona haya pedido todos.', 'hard_fail' => null];

    private MarketingConversation $conversation;

    private int $mundo = 0;

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
        $this->nuevaConversacion();
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1 ───────────────────────────────────────────────────────────────────

    public function test_1_un_rechazo_blando_sobre_la_lista_limpia_es_un_aviso_y_sale_el_texto_del_modelo(): void
    {
        [$m, $token] = $this->turno(self::PANORAMA, true);
        $prediccion = $this->prediccion($m, $token, self::TRES, self::BLANDO);

        $r = $this->commit($m, $token, self::TRES, self::BLANDO)->assertOk()->json();

        $lista = 'Claro. Tenemos Plan Valera ($65.000 COP), Plan Mensual ($80.000 COP) y Élite ($180.000 COP), todos de un mes. ¿Cuál le interesa más?';
        $this->assertSame($lista, $r['reply_final'], 'el texto del modelo, con la lista');
        $this->assertSame($lista, $this->ultimoSaliente()?->body);
        $fila = $this->ultimaAccion();
        $this->assertTrue($fila->metadata[Origin::CRITIC_ADVISORY] ?? null, 'la fila lleva el aviso');
        $this->assertSame(['fail', 2, ['naturalness', 'progressive_disclosure'], null], [$fila->metadata['critic']['verdict'], $fila->metadata['critic']['attempt'], $fila->metadata['critic']['issues'], $fila->metadata['critic']['hard_fail']], 'y el veredicto tal cual');
        $this->assertArrayNotHasKey('fallback_mode', $fila->metadata);
        $this->assertArrayNotHasKey('policy_violations', $fila->metadata);
        $origen = Origin::classify($fila->metadata, (string) $fila->action_type);
        $this->assertSame([Origin::MODEL, null, true], [$origen['origin'], $origen['fallback_reason'], $origen['critic_advisory']], 'el informe de origen: del modelo, con el aviso del revisor');

        // El laboratorio predice lo mismo.
        $this->assertSame([StudioLabEngineCheck::ORIGIN_MODEL, null, [], true], [$prediccion['origin'], $prediccion['cause'], $prediccion['codes'], $prediccion['criticAdvisory'] ?? null]);
    }

    // ── 2 ───────────────────────────────────────────────────────────────────

    public function test_2_un_fallo_duro_un_defecto_del_servidor_otro_turno_y_legacy_siguen_siendo_respaldo(): void
    {
        $this->assertTrue(CriticContract::isSoftFail(self::BLANDO));
        $casos = [
            'un fallo duro, sobre la misma lista limpia' => [self::PANORAMA, true, self::TRES, ['hard_fail' => 'invented_price'] + self::BLANDO],
            'un fallo duro con «pass» (el modelo se contradice)' => [self::PANORAMA, true, self::TRES, ['verdict' => 'pass', 'hard_fail' => 'pressure'] + self::BLANDO],
            // P-1 A: sin fallo duro, pero con un incumplimiento de seguridad, exactitud o política, el revisor sigue vetando.
            'una dimensión de seguridad, sin fallo duro' => [self::PANORAMA, true, self::TRES, ['issues' => ['naturalness', 'payment_safety']] + self::BLANDO],
            'una dimensión de exactitud, sin fallo duro' => [self::PANORAMA, true, self::TRES, ['issues' => ['progressive_disclosure', 'factuality']] + self::BLANDO],
            'una dimensión de política, sin fallo duro' => [self::PANORAMA, true, self::TRES, ['issues' => ['pressure_control']] + self::BLANDO],
            'un defecto del servidor: el nombre a mano' => [self::PANORAMA, true, 'Claro. Tenemos el Plan Valera {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa más?', self::BLANDO],
            'un defecto del servidor: un solo plan con su precio' => [self::PANORAMA, true, 'Claro. El {{PLAN_1}} cuesta {{PLAN_PRICE}} al mes. ¿Le interesa?', self::BLANDO],
            'otro turno: el horario' => ['¿Qué horario tienen?', true, 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Le gustaría conocer los planes?', self::BLANDO],
            'Legacy (sin la oferta del Studio)' => [self::PANORAMA, false, 'Claro, con gusto le cuento de los planes. ¿Qué objetivo tiene?', self::BLANDO],
        ];
        foreach ($casos as $nombre => [$entrante, $negocia, $borrador, $revisor]) {
            $this->nuevaConversacion();
            [$m, $token] = $this->turno($entrante, $negocia);
            $prediccion = $negocia ? $this->prediccion($m, $token, $borrador, $revisor) : null;

            $this->commit($m, $token, $borrador, $revisor)->assertOk();

            $fila = $this->ultimaAccion();
            $this->assertArrayHasKey('fallback_mode', $fila->metadata, "{$nombre}: el respaldo de siempre");
            $this->assertArrayNotHasKey(Origin::CRITIC_ADVISORY, $fila->metadata, $nombre);
            $origen = Origin::classify($fila->metadata, (string) $fila->action_type);
            $this->assertSame([Origin::FALLBACK, Origin::REASON_CRITIC, false], [$origen['origin'], $origen['fallback_reason'], $origen['critic_advisory']], $nombre);
            if ($prediccion !== null) {
                $this->assertSame([StudioLabEngineCheck::ORIGIN_FALLBACK, StudioLabEngineCheck::CAUSE_CRITIC], [$prediccion['origin'], $prediccion['cause']], "{$nombre}: el laboratorio predice lo mismo");
                $this->assertArrayNotHasKey('criticAdvisory', $prediccion, $nombre);
            }
        }
    }

    // ── 3 ───────────────────────────────────────────────────────────────────

    public function test_3_un_aviso_no_es_un_salvoconducto_las_guardas_del_commit_siguen(): void
    {
        $guardas = [
            OutboundContentGuard::CODE_URL_IN_REPLY => 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. Mire los detalles en https://ironbody.example/planes ¿Cuál le interesa más?',
            OutboundContentGuard::CODE_CARD_DATA_REQUEST => 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. Para separarle el cupo, envíeme el número de su tarjeta y la fecha de vencimiento. ¿Cuál le interesa más?',
        ];
        foreach ($guardas as $codigo => $borrador) {
            $this->nuevaConversacion();
            [$m, $token] = $this->turno(self::PANORAMA, true);
            // La comprobación del servidor no mira esas guardas: para ella es la lista limpia del turno.
            $check = $this->postJson('/api/internal/marketing/ai/check', [
                'conversation_id' => $m->conversation_id, 'message_id' => $m->id, 'decide_token' => $token, 'attempt' => 2,
                'draft' => ['reply_draft' => $borrador, 'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => null, 'tools_requested' => []],
            ], $this->headers())->assertOk()->json();
            $this->assertSame([true, 'list_final'], [$check['pass'], $check['budget']['reason']], "{$codigo}: ai/check la da por firme");

            // Con el rechazo blando, el commit la deja pasar al camino normal… y ahí la guarda la tumba (422: n8n pide la rendición).
            $this->commit($m, $token, $borrador, self::BLANDO)->assertStatus(422)->assertJsonPath('code', $codigo);
            $this->assertSame(0, MarketingMessage::query()->where('conversation_id', $this->conversation->id)->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count(), "{$codigo}: no sale nada");
        }
    }

    // ── 4 ───────────────────────────────────────────────────────────────────

    public function test_4_el_informe_de_origen_solo_marca_el_aviso_en_una_fila_del_modelo_con_la_marca_del_commit(): void
    {
        $blando = ['verdict' => 'fail', 'attempt' => 2, 'issues' => ['naturalness'], 'hard_fail' => null];
        $this->assertTrue(Origin::classify(['critic' => $blando, Origin::CRITIC_ADVISORY => true])['critic_advisory'], 'del modelo, con la marca');
        $this->assertFalse(Origin::classify(['critic' => $blando])['critic_advisory'], 'un fail suelto no es un aviso');
        $this->assertFalse(Origin::classify(['critic' => $blando, Origin::CRITIC_ADVISORY => 'sí'])['critic_advisory'], 'solo `true`');
        $this->assertFalse(Origin::classify(['critic' => $blando, Origin::CRITIC_ADVISORY => true, 'policy_violations' => ['policy_length_exceeded'], 'policy_discarded_draft' => '…'])['critic_advisory'], 'la política lo sustituyó: es un respaldo');
        $this->assertFalse(Origin::classify(['critic' => $blando, Origin::CRITIC_ADVISORY => true, 'fallback_mode' => 'SAFE_CURATED_REPLY'])['critic_advisory'], 'en un respaldo, no');

        // El rechazo blando y el que veta, en el vocabulario del revisor (falla cerrado; por categoría, en `CriticContractVetoDimensionsTest`).
        foreach ([[['verdict' => 'fail', 'hard_fail' => null], true], [['verdict' => 'fail', 'hard_fail' => ''], true], [['verdict' => 'fail'], true],
            [['verdict' => 'fail', 'hard_fail' => null, 'issues' => ['naturalness', 'customer_fit']], true],
            [['verdict' => 'fail', 'hard_fail' => null, 'issues' => ['naturalness', 'url_safety']], false],
            [['verdict' => 'fail', 'hard_fail' => null, 'issues' => ['membership_factuality']], false],
            [['verdict' => 'fail', 'hard_fail' => null, 'issues' => ['pressure_control', 'naturalness']], false],
            [['verdict' => 'fail', 'hard_fail' => 'pressure'], false], [['verdict' => 'fail', 'hard_fail' => 'algo'], false], [['verdict' => 'fail', 'hard_fail' => true], false],
            [['verdict' => 'pass', 'hard_fail' => null], false], [['verdict' => 'pass', 'hard_fail' => 'pressure'], false], [[], false]] as [$revisor, $blandoEsperado]) {
            $this->assertSame($blandoEsperado, CriticContract::isSoftFail($revisor), json_encode($revisor));
        }
    }

    // ── Andamio ─────────────────────────────────────────────────────────────

    /** @return array{0:MarketingMessage,1:string} el mensaje y el token de su `decide` real */
    private function turno(string $texto, bool $negocia): array
    {
        $m = MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND, 'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.l4.'.$this->mundo.'.'.uniqid(), 'status' => 'received',
        ]);
        $decide = $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id] + ($negocia ? ['prompt_contract' => 'ultron-prompts/1'] : []),
            $this->headers())->assertOk()->json();

        return [$m, (string) $decide['decide_token']];
    }

    private function commit(MarketingMessage $m, string $token, string $borrador, array $revisor): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => [
                'strategy_goal' => 'resolve_objection', 'next_state' => P::GOAL_DISCOVERY, 'intent' => SalesIntents::PRICING_QUESTION, 'reply_draft' => $borrador,
                'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'answer', 'confidence' => 0.9, 'tools_requested' => [],
            ],
            'critic' => $revisor,
        ], $this->headers());
    }

    /** @return array<string,mixed> la predicción del laboratorio para ese turno, ese borrador y ese veredicto */
    private function prediccion(MarketingMessage $m, string $token, string $borrador, array $revisor): array
    {
        $firmado = app(UltronDecideToken::class)->peek($token, (int) $m->conversation_id, (int) $m->id);
        $this->assertTrue($firmado['valid']);
        $memoria = app(ConversationMemoryService::class);
        $leida = $memoria->load($this->conversation->fresh());
        $turno = [
            'sp' => $firmado['extra']['sp'],
            'po' => $firmado['extra']['po'] ?? [],
            'catalog' => app(MarketingKnowledgeBaseService::class)->activePlans(),
            'inbound' => (string) $m->body,
            'phase' => app(P::class)->normalize($this->conversation->fresh()->commercial_phase),
            'memory' => $leida,
            'resolution' => app(ReferenceResolver::class)->resolve((string) $m->body, $leida, $memoria->sellablePlansForMemory()),
        ];

        return app(StudioLabEngineCheck::class)->predict($turno, ['reply_draft' => $borrador, 'recommended_plan_id' => null, 'critic' => $revisor], ['intent' => SalesIntents::PRICING_QUESTION, 'tools_requested' => []]);
    }

    private function nuevaConversacion(): void
    {
        $n = str_pad((string) ++$this->mundo, 3, '0', STR_PAD_LEFT);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150543'.$n, 'meta_user_id' => '573150543'.$n, 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function ultimaAccion(): MarketingAiAction
    {
        return MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
    }

    private function ultimoSaliente(): ?MarketingMessage
    {
        return MarketingMessage::query()->where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first();
    }
}
