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
 *  - (1b) el borrador 3 EXACTO por el commit: con el revisor aprobando o
 *    rechazando sale el MISMO texto fijo (el comportamiento de hoy, fijado);
 *    en el rechazo la fila anota lo que el servidor le encontró
 *    (`discarded_draft_check`: `plans_overview/mixed`, `length_over_cap` y
 *    251). Si el servidor lo aceptaba, lo dice; sin versión del Studio, la
 *    fila no lleva la clave.
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

    /** La lista fija de hoy con el tope de la canaria (la de `UltronA4ListaDelModeloTest`): la que le llegó a la persona en la prueba. */
    private const PRECIOS_200 = 'Claro. Algunos planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?';

    private const APROBADO = ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9];

    /** El «no» de «Critic Comercial 3» en la prueba real (0,4). */
    private const RECHAZADO_3 = ['verdict' => 'fail', 'attempt' => 3, 'score' => 0.4, 'issues' => ['naturalness', 'repetition', 'progressive_disclosure', 'question_discipline']];

    /** Lo que mandó S2 en ese turno: dos reescrituras del servidor y un solo revisor. */
    private const REGENERACION = [
        'contract' => 'ultron-regen/1',
        'drafts' => 3,
        'critics' => 1,
        'checks' => 2,
        'check_errors' => 0,
        'rewrites' => [
            ['by' => 'server', 'codes' => ['plans_overview'], 'issues' => ['relevance']],
            ['by' => 'server', 'codes' => ['plans_overview', 'length_over_cap'], 'issues' => ['relevance', 'progressive_disclosure']],
        ],
        'tokens' => null,
    ];

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

    // ── (1b) La causa determinista en la fila del respaldo ──────────────────

    /**
     * El borrador 3 EXACTO de la prueba real por el commit: con el revisor
     * APROBANDO sale la lista fija (la lista no vale y no cabe: la rama de la
     * política) y con el revisor RECHAZANDO sale el MISMO texto, byte a byte
     * (es el comportamiento de hoy, y se fija). En el rechazo, la fila lleva
     * además lo que el servidor le encontró al borrador: `mixed` y 251.
     */
    public function test_el_borrador_3_sale_igual_con_el_revisor_aprobando_o_rechazando_y_el_rechazo_anota_la_causa_del_servidor(): void
    {
        $this->publica('profesional', 200);

        // El revisor aprueba.
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m);
        $aprobado = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::BORRADOR_3, null), self::APROBADO))->assertOk()->json();
        $filaAprobada = $this->ultimaAccion();
        $this->assertSame(self::PRECIOS_200, $aprobado['reply_final']);
        $this->assertSame(['policy_length_exceeded', 'policy_plans_overview'], $filaAprobada->metadata['policy_violations']);
        $this->assertSame(['offered' => self::PO, 'used' => self::PO, 'valid' => false, 'detail' => 'mixed'], $filaAprobada->metadata['plans_overview']);
        $this->assertSame(251, $filaAprobada->metadata['length']['from']);
        $this->assertArrayNotHasKey('discarded_draft_check', $filaAprobada->metadata, 'solo en el rechazo del revisor');

        // El revisor rechaza el tercero (S2: dos reescrituras del servidor y un solo revisor).
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m);
        $rechazado = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::BORRADOR_3, null), self::RECHAZADO_3, self::REGENERACION))->assertOk()->json();
        $fila = $this->ultimaAccion();

        $this->assertSame(self::PRECIOS_200, $rechazado['reply_final'], 'el mismo texto fijo, byte a byte');
        $this->assertSame($aprobado['reply_final'], $this->ultimoSaliente()?->body);
        $this->assertSame('SAFE_CURATED_REPLY', $fila->metadata['fallback_mode']);
        $this->assertSame([3, 'fail'], [$fila->metadata['critic']['attempt'], $fila->metadata['critic']['verdict']]);
        $this->assertSame(['pass' => false, 'codes' => ['plans_overview/mixed', 'length_over_cap'], 'length' => 251], $fila->metadata['discarded_draft_check']);

        $origen = UltronReplyOrigin::classify($fila->metadata);
        $this->assertSame([UltronReplyOrigin::FALLBACK, UltronReplyOrigin::REASON_CRITIC, UltronReplyOrigin::SERVER_WOULD_REJECT, ['plans_overview/mixed', 'length_over_cap']],
            [$origen['origin'], $origen['fallback_reason'], $origen['server_verdict'], $origen['server_codes']]);
    }

    /** El revisor rechaza un borrador que el servidor sí aceptaba: la fila lo dice; sin versión del Studio, la fila no lleva la clave (como hoy). */
    public function test_el_rechazo_de_un_borrador_que_el_servidor_aceptaba_y_sin_studio_nada(): void
    {
        $this->publica('profesional', 200);
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m);
        $referencia = 'Claro. Tenemos el {{PLAN_1}}, el {{PLAN_2}} y el {{PLAN_3}}. ¿Cuál le interesa?';
        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, $referencia, null), self::RECHAZADO_3, self::REGENERACION))->assertOk()->json();
        $fila = $this->ultimaAccion();
        $this->assertSame(self::PRECIOS_200, $r['reply_final'], 'el rechazo del revisor da el respaldo, como hoy');
        $this->assertSame(['pass' => true, 'codes' => [], 'length' => 120], $fila->metadata['discarded_draft_check']);
        $this->assertSame(UltronReplyOrigin::SERVER_WOULD_ACCEPT, UltronReplyOrigin::classify($fila->metadata)['server_verdict']);

        // Legacy (sin negociar los prompts): ni la comprobación ni la clave.
        $this->nuevaConversacion();
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m, false);
        $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, 'Claro, el Plan Valera es de un mes. ¿Le interesa?', 3), ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3]))->assertOk();
        $fila = $this->ultimaAccion();
        $this->assertSame('SAFE_CURATED_REPLY', $fila->metadata['fallback_mode']);
        $this->assertArrayNotHasKey('discarded_draft_check', $fila->metadata);
        $this->assertSame(UltronReplyOrigin::SERVER_UNKNOWN, UltronReplyOrigin::classify($fila->metadata)['server_verdict'], 'sin dato, como una fila de antes');
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

    private function decide(MarketingMessage $m, bool $negocia = true): array
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ] + ($negocia ? ['prompt_contract' => 'ultron-prompts/1'] : []), $this->headers())->assertOk()->json();
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
