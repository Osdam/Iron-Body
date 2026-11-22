<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\UltronRegisterDetector;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use App\Services\Marketing\Ultron\UltronReplyOrigin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * A4 · TURNO 2 DE LA TERCERA PRUEBA REAL (22-11-2026): LAS NOTAS DEL SERVIDOR
 * CON EL ESQUELETO Y EL PRESUPUESTO, POR LOS ENDPOINTS REALES
 * (`informe-debugger-a4-turno2.md` §7, correcciones (1) y (1b)).
 *
 * El escenario es el de `UltronA4ListaDelModeloTest`: la versión publicada
 * SOLO en la canaria («profesional» y 200), los 13 planes vendibles de
 * producción y la ficha del horario. A «¿Qué planes tienen y cuánto cuesta la
 * mensualidad?» `decide` firma la lista `po` = [3, 4, 8] (Plan Valera, Plan
 * Mensual y Élite), que expandida ocupa 25 + 26 + 20 = 71 caracteres.
 *
 * Lo que se fija, `decide` → `ai/check` → `commit` con las clases reales:
 *  - (1) al borrador 3 de la prueba (repite `{{PLAN_3}}`; 251 expandido) la
 *    comprobación le devuelve `plans_overview/mixed` y `length_over_cap` con
 *    las dos notas en su variante con el esqueleto y el presupuesto, ENTERAS;
 *    y un borrador que sigue ese esqueleto (el de referencia del informe, con
 *    tres, y otro con dos y «para quién es» en su mención) pasa la
 *    comprobación sin códigos, cabe en lo que la nota dice que queda y sale
 *    por el commit como escrito por el modelo, con la lista válida, dentro
 *    del tope y de usted.
 */
class UltronA4NotasDelServidorTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000b9';

    /** El catálogo vendible de producción (A4 §2.1): id, nombre, precio, días; en el orden de `activePlans()`. */
    private const CATALOGO = [
        [25, 'PLAN DÍA', 10000, 1],
        [2, 'Plan Semana', 45000, 7],
        [3, 'Plan Valera', 65000, 30],
        [4, 'Plan Mensual', 80000, 30],
        [5, 'Trimestre', 210000, 90],
        [6, 'Semestre', 390000, 180],
        [7, 'Anualidad', 624000, 365],
        [8, 'Élite', 180000, 30],
        [9, 'Pro', 499000, 90],
        [10, 'Prime', 950000, 180],
        [11, 'Evolution', 1800000, 365],
        [14, 'PROMO X4', 75000, 30],
        [16, 'INGRESO EMPLEADOS', 1, 360],
    ];

    /** La pregunta del turno 2 de la prueba real. */
    private const PANORAMA_CON_PRECIO = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private const PO = [3, 4, 8];

    /** El borrador 3 de la prueba real, sin expandir (200 caracteres): repite `{{PLAN_3}}`. */
    private const BORRADOR_3 = 'Le comento que tenemos el {{PLAN_1}}, el {{PLAN_2}} y el {{PLAN_3}}. Por ejemplo, el {{PLAN_3}} incluye acceso ilimitado, asesoría y seguimiento con entrenador. ¿Qué busca lograr con su entrenamiento?';

    /** Lo que el servidor le dice al redactor de ese borrador: las dos notas con el presupuesto, unidas por un espacio (469 caracteres). */
    private const NOTAS_DEL_BORRADOR_3 = 'MARCADOR REPETIDO O MEZCLADO: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION. Ya expandidos ocupan 71 caracteres: quedan 89 de 160. DEMASIADO LARGO: mide 251 caracteres y el limite publicado es 200. Reescribelo en unos 160: primero la respuesta, sin relleno. Los marcadores de la lista ya ocupan 71: quedan 89. Si no caben, dos planes; una pregunta corta o ninguna.';

    private const APROBADO = ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9];

    private MarketingConversation $conversation;

    private int $mundo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Domingo 22-11-2026, 14:11 en Bogotá (en UTC: la trampa conocida de la suite).
        Carbon::setTestNow(Carbon::parse('2026-11-22 19:11:00', 'UTC'));
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

        $this->catalogoDeProduccion();
        $this->fichaDelHorario();
        $this->nuevaConversacion();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── (1) Las notas y el borrador que sigue su esqueleto ──────────────────

    /**
     * Los dos borradores que siguen el esqueleto: el de referencia del informe
     * (§8, tres planes, 120 expandido) y uno con dos y «para quién es» en su
     * mención, con los beneficios del catálogo.
     *
     * @return array<string, array{string, string, list<int>}>
     */
    public static function borradoresDelEsqueleto(): array
    {
        return [
            'el de referencia, con tres' => [
                'Claro. Tenemos el {{PLAN_1}}, el {{PLAN_2}} y el {{PLAN_3}}. ¿Cuál le interesa?',
                'Claro. Tenemos el Plan Valera ($65.000 COP), el Plan Mensual ($80.000 COP) y el Élite ($180.000 COP). ¿Cuál le interesa?',
                [3, 4, 8],
            ],
            'dos, con para quién es en su mención' => [
                'Claro. Tenemos el {{PLAN_1}}, con acceso total al gimnasio, y el {{PLAN_3}}, con entrenador personal dedicado. ¿Cuál le interesa?',
                'Claro. Tenemos el Plan Valera ($65.000 COP), con acceso total al gimnasio, y el Élite ($180.000 COP), con entrenador personal dedicado. ¿Cuál le interesa?',
                [3, 8],
            ],
        ];
    }

    /** @param list<int> $usados */
    #[DataProvider('borradoresDelEsqueleto')]
    public function test_el_borrador_que_sigue_el_esqueleto_de_la_nota_pasa_la_comprobacion_y_sale_del_modelo(string $borrador, string $expandido, array $usados): void
    {
        $this->beneficiosDistintos();
        $this->publica('profesional', 200);
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m);
        $this->assertSame(self::PO, (self::cargaDelToken($decide['decide_token'])['x'] ?? [])['po'] ?? null);

        // Lo que el servidor le dice al redactor del borrador 3 de la prueba: las dos notas, con el presupuesto, enteras.
        $r = $this->check($m, $decide['decide_token'], self::BORRADOR_3, 2)->assertOk();
        $this->assertSame([
            ['code' => 'plans_overview', 'detail' => 'mixed', 'issue' => 'relevance'],
            ['code' => 'length_over_cap', 'detail' => null, 'issue' => 'progressive_disclosure'],
        ], $r->json('defects'));
        $this->assertSame(self::NOTAS_DEL_BORRADOR_3, $r->json('notes'));
        $this->assertTrue($r->json('rewrite'));

        // El borrador que sigue el esqueleto: sin códigos, y cabe en lo que la nota dice que queda (89 de 160 tras los 71).
        $r = $this->check($m, $decide['decide_token'], $borrador, 3)->assertOk();
        $this->assertTrue($r->json('pass'));
        $this->assertSame([], $r->json('defects'));
        $this->assertSame('', $r->json('notes'));
        $this->assertLessThanOrEqual(89, UltronReplyFitter::measure($expandido) - 71, 'lo que no son marcadores cabe en lo que queda');
        $this->assertLessThanOrEqual(200, UltronReplyFitter::measure($expandido));

        // Y sale por el commit tal cual lo escribió el modelo.
        $c = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, $borrador, null), self::APROBADO))->assertOk()->json();
        $this->assertSame($expandido, $c['reply_final']);
        $this->assertSame($expandido, $this->ultimoSaliente()?->body);
        $fila = $this->ultimaAccion();
        $this->assertArrayNotHasKey('policy_violations', $fila->metadata, 'ni policy_plans_overview ni policy_length_exceeded');
        $this->assertTrue($fila->metadata['plans_overview']['valid']);
        $this->assertSame($usados, $fila->metadata['plans_overview']['used']);
        $this->assertSame(UltronReplyFitter::measure($expandido), $fila->metadata['length']['to']);
        $this->assertSame([], $fila->metadata['register']['found']);
        $this->assertSame([], UltronRegisterDetector::tuteo($expandido));
        $this->assertSame(UltronReplyOrigin::MODEL, UltronReplyOrigin::classify($fila->metadata)['origin']);
        $this->assertArrayNotHasKey('fallback_mode', $fila->metadata);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    private function beneficiosDistintos(): void
    {
        Plan::query()->whereKey(3)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Asesoría semi personalizada'])]);
        Plan::query()->whereKey(4)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Clases grupales ilimitadas'])]);
        Plan::query()->whereKey(8)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Entrenador personal dedicado'])]);
    }

    private function catalogoDeProduccion(): void
    {
        foreach (self::CATALOGO as $i => [$id, $nombre, $precio, $dias]) {
            (new Plan)->forceFill([
                'id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias,
                'active' => true, 'sellable' => true, 'sort_order' => $i + 1, 'is_recommended' => false,
                'benefits' => json_encode(['Acceso total al gimnasio']),
            ])->save();
        }
    }

    private function fichaDelHorario(): void
    {
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
            'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
            'metadata' => ['windows' => [
                'lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'],
                'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'],
                'sabado' => ['08:00', '14:00'], 'domingo' => ['08:00', '14:00'],
            ]],
        ]);
    }

    private function nuevaConversacion(): void
    {
        $n = str_pad((string) ++$this->mundo, 3, '0', STR_PAD_LEFT);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3150539'.$n, 'meta_user_id' => '573150539'.$n,
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        config()->set('marketing.ultron.studio_prompts', 'canary');
    }

    private function publica(string $tono, int $maxChars): void
    {
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => $tono, 'persona.maxChars' => $maxChars]), null);
    }

    private function entrante(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.a4n.'.$this->mundo.'.'.uniqid(), 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m): array
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id, 'prompt_contract' => 'ultron-prompts/1',
        ], $this->headers())->assertOk()->json();
    }

    private function check(MarketingMessage $m, string $token, string $borrador, int $attempt): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/check', [
            'conversation_id' => $this->conversation->id,
            'message_id' => $m->id,
            'decide_token' => $token,
            'attempt' => $attempt,
            'draft' => ['reply_draft' => $borrador, 'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => null, 'tools_requested' => []],
        ], $this->headers());
    }

    private static function propuesta(string $intent, string $borrador, ?int $plan): array
    {
        return [
            'strategy_goal' => 'collect_data',
            'next_state' => P::GOAL_DISCOVERY,
            'intent' => $intent,
            'reply_draft' => $borrador,
            'recommended_plan_id' => $plan,
            'main_barrier' => null,
            'next_best_action' => 'ask_discovery',
            'confidence' => 0.9,
            'tools_requested' => [],
        ];
    }

    private function payload(MarketingMessage $m, string $token, array $propuesta, array $critico, ?array $regeneracion = null): array
    {
        return [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => $propuesta,
            'critic' => $critico,
        ] + ($regeneracion === null ? [] : ['regeneration' => $regeneracion]);
    }

    private function commit(array $payload): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->headers());
    }

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
