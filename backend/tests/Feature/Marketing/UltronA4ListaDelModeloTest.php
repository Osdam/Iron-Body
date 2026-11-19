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
use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use App\Services\Marketing\Ultron\ConversationMemory;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Marketing\Ultron\UltronCommitService;
use App\Services\Marketing\Ultron\UltronDraftPlaceholders;
use App\Services\Marketing\Ultron\UltronPlansOverview;
use App\Services\Marketing\Ultron\UltronRegisterDetector;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * A4 · «REGENERAR ANTES DE SUSTITUIR», OLA 3: LA LISTA DE PLANES LA REDACTA EL
 * MODELO Y LARAVEL PONE LOS DATOS (plan `plan-arquitecto-a4-regenerar.md` §3
 * P-3 y P-4, §4.2, §4.3, §6 «Ola 3»; decisiones L-2 y L-5 del lead), por el
 * endpoint `decide` → `commit` con las clases reales.
 *
 * El escenario es el de `UltronA4RegistroYPanoramaTest`: la versión publicada
 * SOLO en la canaria, los 13 planes vendibles de producción y la ficha del
 * horario. A «¿Qué planes tienen y cuánto cuesta la mensualidad?» `decide`
 * elige y firma la lista (`po` = Valera, Mensual y Élite, los tres primeros de
 * un mes) y se la da al modelo SIN PRECIO; el modelo escribe `{{PLAN_1}}` a
 * `{{PLAN_3}}` y el commit pone «Nombre ($precio COP)» leído de `Plan` en ese
 * momento.
 *
 * Lo que no cambia lo fijan otras pruebas: sin versión del Studio, las dos
 * instantáneas Legacy de A4 (sin regenerar); con versión pero sin lista, las
 * pruebas de A4 de antes, sin tocar.
 */
class UltronA4ListaDelModeloTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000b3';

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

    /** La pregunta de la prueba real del 18-11 (turno 2). */
    private const PANORAMA_CON_PRECIO = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    /** La lista que firma `decide` para esa pregunta: los tres primeros de un mes, en el orden del catálogo. */
    private const PO = [3, 4, 8];

    /** La lista fija de hoy con el tope de la canaria (la de `UltronA4RegistroYPanoramaTest`). */
    private const PRECIOS_200 = 'Claro. Algunos planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?';

    private const TRES = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?';

    private const TRES_EXPANDIDO = 'Claro. Tenemos Plan Valera ($65.000 COP), Plan Mensual ($80.000 COP) y Élite ($180.000 COP), todos de un mes. ¿Cuál le interesa más?';

    private const DOS = 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}, los dos de un mes. ¿Cuál le interesa?';

    private const DOS_EXPANDIDO = 'Claro. Tenemos Plan Valera ($65.000 COP) y Plan Mensual ($80.000 COP), los dos de un mes. ¿Cuál le interesa?';

    /** La misma lista, pero tutea en la pregunta final. */
    private const TRES_DE_TU = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál te interesa más?';

    private const TRES_DE_TU_EXPANDIDO = 'Claro. Tenemos Plan Valera ($65.000 COP), Plan Mensual ($80.000 COP) y Élite ($180.000 COP), todos de un mes. ¿Cuál te interesa más?';

    private const APROBADO = ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9];

    private MarketingConversation $conversation;

    private int $mundo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Miércoles 18-11-2026, 17:47 en Bogotá (en UTC: la trampa conocida de la suite).
        Carbon::setTestNow(Carbon::parse('2026-11-18 22:47:00', 'UTC'));
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

    // ── `decide`: la lista, firmada y sin precio (§4.2) ─────────────────────

    public function test_decide_ofrece_la_lista_firmada_y_sin_precio_al_panorama(): void
    {
        $this->publica('profesional', 200);
        $decide = $this->decide($this->entrante(self::PANORAMA_CON_PRECIO), true);

        $this->assertSame(1, $this->spDe($decide)['o'] ?? null, 'el paquete ofrecido lleva el parche del motor');
        $this->assertSame([
            'plans' => [
                ['marker' => '{{PLAN_1}}', 'plan_id' => 3, 'name' => 'Plan Valera', 'duration_days' => 30],
                ['marker' => '{{PLAN_2}}', 'plan_id' => 4, 'name' => 'Plan Mensual', 'duration_days' => 30],
                ['marker' => '{{PLAN_3}}', 'plan_id' => 8, 'name' => 'Élite', 'duration_days' => 30],
            ],
            'price_asked' => true,
        ], $decide['context']['commercial_turn_policy']['plans_overview']);
        $this->assertSame(self::PO, $this->cargaDe($decide)['po'] ?? null, 'los id, en orden, firmados en el token');
        $this->assertDoesNotMatchRegularExpression('/\d{2,3}[.,]?\d{3}|\$/', json_encode($decide['context']['commercial_turn_policy'], JSON_UNESCAPED_UNICODE), 'ninguna cifra de dinero');
        // La lista va FUERA de la lista blanca de la política: las otras claves, las de siempre y en su orden.
        $this->assertSame(['intent', 'commercial_phase', 'greet_required', 'welcome_required', 'answer_first', 'required_plan_id', 'allowed_plan_ids',
            'forbidden_actions', 'required_facts', 'allowed_next_actions', 'hard_required_plan', 'preferred_commercial_plan', 'price_verification',
            'active_goal', 'resume_goal_after_answer', 'reception_mode', 'memory_mode', 'plans_overview'], array_keys($decide['context']['commercial_turn_policy']));

        // `price_asked`: la intención del turno es de precio o la persona pregunta un precio. El clasificador
        // local da `pricing_question` a «¿Qué planes tienen?» (sin la palabra precio): en verdadero.
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $decide = $this->decide($this->entrante('¿Qué planes tienen?'), true);
        $this->assertSame(SalesIntents::PRICING_QUESTION, $decide['context']['commercial_turn_policy']['intent']);
        $this->assertTrue($decide['context']['commercial_turn_policy']['plans_overview']['price_asked']);
        $this->assertSame(self::PO, $this->cargaDe($decide)['po'] ?? null);

        // Y con otra intención y sin preguntar un precio, la misma lista con `price_asked` en falso.
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $decide = $this->decide($this->entrante('¿Qué membresías tienen?'), true);
        $this->assertSame(SalesIntents::UNKNOWN, $decide['context']['commercial_turn_policy']['intent']);
        $this->assertFalse($decide['context']['commercial_turn_policy']['plans_overview']['price_asked']);
        $this->assertSame(self::PO, $this->cargaDe($decide)['po'] ?? null);

        // El plan que nombra la persona va primero (la misma selección que la lista fija).
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $decide = $this->decide($this->entrante('¿Cuánto cuesta el Élite y qué otros hay?'), true);
        $this->assertSame([8, 3, 4], $this->cargaDe($decide)['po'] ?? null);
        $this->assertSame([8, 3, 4], array_column($decide['context']['commercial_turn_policy']['plans_overview']['plans'], 'plan_id'));
    }

    public function test_decide_no_ofrece_la_lista_fuera_del_panorama_ni_sin_el_parche_ni_en_legacy(): void
    {
        // No es panorama: un saludo y un plan nombrado a secas.
        foreach (['Hola, buenas tardes', '¿Cuánto cuesta el Mensual?'] as $texto) {
            $this->nuevaConversacion();
            $this->publica('profesional', 200);
            $decide = $this->decide($this->entrante($texto), true);
            $this->assertArrayNotHasKey('plans_overview', $decide['context']['commercial_turn_policy'], $texto);
            $this->assertArrayNotHasKey('po', $this->cargaDe($decide), $texto);
        }

        // Una versión del Studio con todos los valores por defecto: sin parche del motor, sin `o`, sin lista (como hoy).
        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $decide = $this->decide($this->entrante(self::PANORAMA_CON_PRECIO), true);
        $this->assertArrayNotHasKey('o', (array) $this->spDe($decide));
        $this->assertArrayNotHasKey('plans_overview', $decide['context']['commercial_turn_policy']);
        $this->assertArrayNotHasKey('po', $this->cargaDe($decide));

        // Legacy: sin negociar, y negociando con los prompts del Studio apagados.
        foreach ([false, true] as $negocia) {
            $this->nuevaConversacion();
            $this->publica('profesional', 200);
            if ($negocia) {
                config()->set('marketing.ultron.studio_prompts', 'off');
            }
            $decide = $this->decide($this->entrante(self::PANORAMA_CON_PRECIO), $negocia);
            $this->assertArrayNotHasKey('plans_overview', $decide['context']['commercial_turn_policy']);
            $this->assertArrayNotHasKey('po', $this->cargaDe($decide));
        }

        // Con menos de dos planes que elegir no hay lista.
        Plan::query()->whereNotIn('id', [3])->update(['sellable' => false]);
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $decide = $this->decide($this->entrante(self::PANORAMA_CON_PRECIO), true);
        $this->assertArrayNotHasKey('plans_overview', $decide['context']['commercial_turn_policy']);
        $this->assertArrayNotHasKey('po', $this->cargaDe($decide));
    }

    // ── `commit`: la lista del modelo (P-3) ─────────────────────────────────

    public function test_la_lista_del_modelo_con_tres_marcadores_sale_con_los_datos_de_plan(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PANORAMA_CON_PRECIO, self::TRES, null);

        $this->assertSame(self::TRES_EXPANDIDO, $r['reply_final']);
        $this->assertSame(self::TRES_EXPANDIDO, $this->ultimoSaliente()?->body);
        $fila = $this->ultimaAccion();
        $this->assertArrayNotHasKey('policy_violations', $fila->metadata, 'sin policy_plans_overview aunque se pregunte el precio');
        $this->assertArrayNotHasKey('plan_price_realigned', $fila->metadata, 'con dos nombres o más y sin PLAN_PRICE no se realinea');
        $this->assertMismaTraza(['offered' => self::PO, 'used' => self::PO, 'valid' => true, 'detail' => null], $fila->metadata['plans_overview']);
        // La longitud, medida sobre el texto YA EXPANDIDO (el borrador sin expandir mide mucho menos).
        $this->assertSame(UltronReplyFitter::measure(self::TRES_EXPANDIDO), $fila->metadata['length']['from']);
        $this->assertSame(UltronReplyFitter::measure(self::TRES_EXPANDIDO), $fila->metadata['length']['to']);
        $this->assertGreaterThan(UltronReplyFitter::measure(self::TRES), $fila->metadata['length']['from']);
        $this->assertMismaTraza(['expected' => 'usted', 'found' => [], 'action' => 'ok'], $fila->metadata['register']);
        // La memoria anota el precio entregado de cada plan de la lista.
        $memoria = $this->memoria();
        $this->assertSame(self::PO, array_column((array) $memoria->get('prices_delivered'), 'plan_id'));
        $this->assertNull($r['price_source'], 'la lista no cotiza un plan: enseña varios');
    }

    public function test_la_lista_del_modelo_con_dos_marcadores(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PANORAMA_CON_PRECIO, self::DOS, null);

        $this->assertSame(self::DOS_EXPANDIDO, $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertArrayNotHasKey('policy_violations', $fila->metadata);
        $this->assertMismaTraza(['offered' => self::PO, 'used' => [3, 4], 'valid' => true, 'detail' => null], $fila->metadata['plans_overview']);
        $this->assertSame(UltronReplyFitter::measure(self::DOS_EXPANDIDO), $fila->metadata['length']['from']);
        $this->assertSame([3, 4], array_column((array) $this->memoria()->get('prices_delivered'), 'plan_id'), 'el Élite no salió');
    }

    /** Si el modelo cotiza un plan que la lista no nombra, ese plan no salió: ni la memoria ni `price_source` lo citan (F3). */
    public function test_el_plan_del_modelo_fuera_de_la_lista_no_cuenta_como_entregado(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PANORAMA_CON_PRECIO, self::DOS, 5);

        $this->assertSame(self::DOS_EXPANDIDO, $r['reply_final']);
        $this->assertNull($r['price_source']);
        $this->assertArrayNotHasKey('plan_price_realigned', $this->ultimaAccion()->metadata, 'con dos nombres y sin PLAN_PRICE no se realinea');
        $this->assertSame(5, $this->ultimaAccion()->metadata['recommended_plan_id'], 'el plan del modelo no se mueve');
        $this->assertSame([3, 4], array_column((array) $this->memoria()->get('prices_delivered'), 'plan_id'), 'el Trimestre no se dio');

        // Si el plan del modelo está en la lista, sí salió.
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PANORAMA_CON_PRECIO, self::DOS, 3);
        $this->assertSame(3, $r['price_source']['plan_id'] ?? null);
        $this->assertSame([3, 4], array_column((array) $this->memoria()->get('prices_delivered'), 'plan_id'));
    }

    public function test_el_precio_cambiado_entre_decide_y_commit_sale_el_nuevo(): void
    {
        $this->publica('profesional', 200);
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m, true);
        Plan::query()->whereKey(4)->update(['price' => 87500]);
        Plan::query()->whereKey(3)->update(['name' => 'Plan Ónix']);

        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::DOS, null), self::APROBADO))->assertOk()->json();

        $this->assertSame('Claro. Tenemos Plan Ónix ($65.000 COP) y Plan Mensual ($87.500 COP), los dos de un mes. ¿Cuál le interesa?', $r['reply_final']);
        $this->assertTrue($this->ultimaAccion()->metadata['plans_overview']['valid']);
    }

    /** @return array<string, array{array<string,mixed>}> */
    public static function planesQueDejanDeVenderse(): array
    {
        return [
            'no vendible' => [['sellable' => false]],
            'archivado' => [['archived_at' => '2026-11-18 22:46:00']],
            'precio a cero' => [['price' => 0]],
        ];
    }

    #[DataProvider('planesQueDejanDeVenderse')]
    public function test_un_plan_que_deja_de_venderse_entre_decide_y_commit_da_la_lista_fija_de_ahora(array $cambio): void
    {
        $this->publica('profesional', 200);
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m, true);
        Plan::query()->whereKey(4)->update($cambio);

        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::TRES, null), self::APROBADO))->assertOk()->json();

        // La lista fija del catálogo de AHORA: sin el Mensual, y con PROMO X4, que es el cuarto de un mes.
        $this->assertSame('Claro. Planes de un mes: Plan Valera, $65.000 COP; Élite, $180.000 COP; PROMO X4, $75.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?', $r['reply_final']);
        $this->assertStringNotContainsString('Mensual', (string) $this->ultimoSaliente()?->body);
        $fila = $this->ultimaAccion();
        $this->assertContains(CommercialTurnPolicy::VIOLACION_PANORAMA, $fila->metadata['policy_violations']);
        $this->assertMismaTraza(['offered' => self::PO, 'used' => self::PO, 'valid' => false, 'detail' => 'not_sellable'], $fila->metadata['plans_overview']);
        $this->assertStringNotContainsString('$80.000', (string) $fila->metadata['policy_discarded_draft'], 'ni la evidencia escribe el precio de un plan que ya no se vende');
        $this->assertNull($r['price_source']);
    }

    /** Un plan que deja de venderse no sale aunque el commit no vea panorama (aquí, el modelo pidió además los enlaces de la app). */
    public function test_un_plan_que_deja_de_venderse_no_sale_ni_con_herramientas_en_el_turno(): void
    {
        $this->publica('profesional', 200);
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m, true);
        Plan::query()->whereKey(4)->update(['sellable' => false]);

        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::DOS, null, [SalesIntents::TOOL_APP_LINKS_SEND]), self::APROBADO))->assertOk()->json();

        $this->assertStringNotContainsString('Mensual', (string) $r['reply_final']);
        $this->assertStringNotContainsString('{{', (string) $r['reply_final']);
        $this->assertStringNotContainsString('PLAN_', (string) $r['reply_final']);
        $this->assertContains(CommercialTurnPolicy::VIOLACION_PANORAMA, $this->ultimaAccion()->metadata['policy_violations']);
        $this->assertSame('not_sellable', $this->ultimaAccion()->metadata['plans_overview']['detail']);
    }

    public function test_plan_4_con_tres_ofrecidos_es_un_marcador_desconocido(): void
    {
        $this->publica('profesional', 200);
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m, true);
        $borrador = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. ¿Cuál le interesa?';

        $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, $borrador, null), self::APROBADO))
            ->assertStatus(422)
            ->assertJsonPath('code', 'unknown_placeholder')
            ->assertJsonPath('detail.unknown', ['PLAN_4']);
        $this->assertSame(0, MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->count(), 'un 422 antes de escribir nada');

        // Con dos ofrecidos, `PLAN_3` tampoco existe.
        Plan::query()->whereKey([8, 14])->update(['sellable' => false]);
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m, true);
        $this->assertSame([3, 4], $this->cargaDe($decide)['po'] ?? null);
        $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::TRES, null), self::APROBADO))
            ->assertStatus(422)
            ->assertJsonPath('code', 'unknown_placeholder')
            ->assertJsonPath('detail.unknown', ['PLAN_3']);
    }

    public function test_plan_1_sin_lista_en_el_turno_es_un_marcador_desconocido(): void
    {
        $this->publica('profesional', 200);
        $m = $this->entrante('¿Cuánto cuesta el Mensual?');
        $decide = $this->decide($m, true);
        $this->assertArrayNotHasKey('po', $this->cargaDe($decide));

        $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, 'El {{PLAN_1}} es el que más piden. ¿Le cuento qué incluye?', 4), self::APROBADO))
            ->assertStatus(422)
            ->assertJsonPath('code', 'unknown_placeholder')
            ->assertJsonPath('detail.unknown', ['PLAN_1'])
            ->assertJsonPath('detail.allowed', UltronDraftPlaceholders::ALLOWED);
    }

    /** @return array<string, array{bool}> */
    public static function modosLegacy(): array
    {
        return ['sin negociar' => [false], 'prompts del Studio apagados' => [true]];
    }

    #[DataProvider('modosLegacy')]
    public function test_un_turno_legacy_con_plan_1_da_422_como_hoy(bool $negocia): void
    {
        $this->publica('profesional', 200);
        if ($negocia) {
            config()->set('marketing.ultron.studio_prompts', 'off');
        }
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m, $negocia);

        $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::TRES, null), self::APROBADO))
            ->assertStatus(422)
            ->assertJsonPath('code', 'unknown_placeholder')
            ->assertJsonPath('detail.unknown', ['PLAN_1', 'PLAN_2', 'PLAN_3'])
            ->assertJsonPath('detail.allowed', UltronDraftPlaceholders::ALLOWED);
    }

    /**
     * Las listas que no valen (P-3, «lista válida»): con el panorama y el
     * precio pedidos, salta `policy_plans_overview` como hoy y sale la lista
     * fija. [borrador, plan del modelo, detalle].
     *
     * @return array<string, array{string, ?int, string}>
     */
    public static function listasQueNoValen(): array
    {
        return [
            'el nombre escrito a mano' => ['Claro. Tenemos el Plan Valera {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa?', null, UltronPlansOverview::NAME_WRITTEN],
            'mezclada con PLAN_NAME' => ['Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. El {{PLAN_NAME}} es el más pedido. ¿Cuál le interesa?', 3, UltronPlansOverview::MIXED],
            'mezclada con PLAN_PRICE' => ['Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}; la mensualidad cuesta {{PLAN_PRICE}}. ¿Cuál le interesa?', 3, UltronPlansOverview::MIXED],
            'un marcador repetido' => ['Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. Le recomiendo {{PLAN_1}}. ¿Cuál le interesa?', null, UltronPlansOverview::MIXED],
            'un solo plan' => ['Claro. Tenemos {{PLAN_1}}, de un mes. ¿Le interesa?', null, UltronPlansOverview::MISSING],
        ];
    }

    #[DataProvider('listasQueNoValen')]
    public function test_una_lista_que_no_vale_da_la_lista_fija(string $borrador, ?int $plan, string $detalle): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PANORAMA_CON_PRECIO, $borrador, $plan);

        $this->assertSame(self::PRECIOS_200, $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertContains(CommercialTurnPolicy::VIOLACION_PANORAMA, $fila->metadata['policy_violations']);
        $this->assertFalse($fila->metadata['plans_overview']['valid']);
        $this->assertSame($detalle, $fila->metadata['plans_overview']['detail']);
        $this->assertNull($r['price_source']);
    }

    /** Sin el panorama en el commit, una lista que no vale sale expandida, con los datos de `Plan` (como hoy sale un borrador que nombra planes a mano). */
    public function test_sin_panorama_en_el_commit_la_lista_que_no_vale_sale_como_hoy(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PANORAMA_CON_PRECIO, self::DOS, null, [SalesIntents::TOOL_STAFF_REVIEW]);

        $this->assertSame(self::DOS_EXPANDIDO, $r['reply_final'], 'con una herramienta en el turno no hay panorama (G1.2)');
        $this->assertTrue($this->ultimaAccion()->metadata['plans_overview']['valid']);
    }

    // ── El turno de cobro (P-3, cambio 5) ───────────────────────────────────

    /** @return array<string, array{string, list<string>}> */
    public static function turnosDeCobro(): array
    {
        return [
            'intención de pago' => [SalesIntents::PAYMENT_LINK_REQUEST, []],
            'cierre' => [SalesIntents::HIGH_INTENT_CLOSE, []],
            'el enlace de pago pedido' => [SalesIntents::PRICING_QUESTION, [SalesIntents::TOOL_PAYMENT_LINK_SEND]],
        ];
    }

    #[DataProvider('turnosDeCobro')]
    public function test_en_un_turno_de_cobro_la_lista_no_vale(string $intent, array $herramientas): void
    {
        $this->publica('profesional', 200);
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m, true);
        $this->assertSame(self::PO, $this->cargaDe($decide)['po'] ?? null, 'decide la ofreció');

        $this->commit($this->payload($m, $decide['decide_token'], self::propuesta($intent, self::DOS, null, $herramientas), self::APROBADO))
            ->assertStatus(422)
            ->assertJsonPath('code', 'unknown_placeholder')
            ->assertJsonPath('detail.unknown', ['PLAN_1', 'PLAN_2'])
            ->assertJsonPath('detail.allowed', UltronDraftPlaceholders::ALLOWED);
    }

    public function test_un_turno_de_cobro_sin_marcadores_deja_la_traza(): void
    {
        $this->publica('profesional', 200);
        $borrador = 'Con gusto. Puede pagar en el gimnasio o desde la app Iron Body Workout. ¿Qué plan le interesa?';
        $this->aprobado(SalesIntents::PAYMENT_LINK_REQUEST, self::PANORAMA_CON_PRECIO, $borrador, null);

        $this->assertMismaTraza(['offered' => self::PO, 'used' => [], 'valid' => false, 'detail' => 'money_turn'], $this->ultimaAccion()->metadata['plans_overview']);
    }

    // ── El plan nombrado y la realineación (P-3, cambio 4) ──────────────────

    /** La política fija el plan que nombró la persona y vuelve a resolver el texto: la expansión, hecha antes, no se pierde. */
    public function test_el_plan_que_fuerza_la_politica_no_pierde_la_lista(): void
    {
        $this->publica('profesional', 200);
        $borrador = 'Claro. {{PLAN_1}} es el que preguntó; también tenemos {{PLAN_2}} y {{PLAN_3}}. ¿Cuál le interesa?';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Élite y qué otros hay?', $borrador, null);

        $this->assertSame('Claro. Élite ($180.000 COP) es el que preguntó; también tenemos Plan Valera ($65.000 COP) y Plan Mensual ($80.000 COP). ¿Cuál le interesa?', $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertArrayNotHasKey('policy_violations', $fila->metadata);
        $this->assertMismaTraza(['offered' => [8, 3, 4], 'used' => [8, 3, 4], 'valid' => true, 'detail' => null], $fila->metadata['plans_overview']);
        $this->assertSame(8, $r['price_source']['plan_id'] ?? null, 'el plan de la política, que salió');
    }

    // ── P-4 · El beneficio de otro plan ─────────────────────────────────────

    public function test_un_beneficio_de_otro_plan_va_por_la_rama_de_la_politica(): void
    {
        $this->beneficiosDistintos();
        $this->publica('profesional', 200);
        $borrador = 'Claro. {{PLAN_1}} incluye entrenador personal dedicado. También tenemos {{PLAN_2}}. ¿Cuál le interesa?';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PANORAMA_CON_PRECIO, $borrador, null);

        $this->assertSame(self::PRECIOS_200, $r['reply_final'], 'el respaldo de la intención: la lista fija');
        $fila = $this->ultimaAccion();
        $this->assertSame([UltronCommitService::VIOLACION_BENEFICIO], $fila->metadata['policy_violations'], 'la lista vale: solo el beneficio');
        $this->assertMismaTraza(['misattributed' => [['plan_id' => 3, 'benefit_plan_id' => 8, 'benefit_index' => 1]]], $fila->metadata['benefits']);
        $this->assertTrue($fila->metadata['plans_overview']['valid']);
        $this->assertStringNotContainsString('entrenador personal', json_encode($fila->metadata['benefits'], JSON_UNESCAPED_UNICODE), 'el índice, no el texto');

        // El mismo beneficio, en su plan: sale el borrador.
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $bien = 'Claro. {{PLAN_3}} incluye entrenador personal dedicado. También tenemos {{PLAN_1}}. ¿Cuál le interesa?';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PANORAMA_CON_PRECIO, $bien, null);
        $this->assertSame('Claro. Élite ($180.000 COP) incluye entrenador personal dedicado. También tenemos Plan Valera ($65.000 COP). ¿Cuál le interesa?', $r['reply_final']);
        $this->assertArrayNotHasKey('benefits', $this->ultimaAccion()->metadata);
    }

    public function test_el_beneficio_de_otro_plan_con_studio_y_sin_lista_y_en_legacy_nada(): void
    {
        $this->beneficiosDistintos();
        $borrador = 'El Plan Valera incluye entrenador personal dedicado. ¿Le cuento cómo empezar?';

        // Con versión del Studio, también sin lista en el turno (P-4: «con versión del Studio»).
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Qué incluye el Plan Valera?', $borrador, 3);
        $this->assertNotSame($borrador, $r['reply_final']);
        $this->assertContains(UltronCommitService::VIOLACION_BENEFICIO, $this->ultimaAccion()->metadata['policy_violations']);
        $this->assertArrayNotHasKey('plans_overview', $this->ultimaAccion()->metadata, 'sin lista en el token, sin traza de la lista');

        // Legacy: sale tal cual, sin violación ni traza.
        foreach ([false, true] as $negocia) {
            $this->nuevaConversacion();
            $this->publica('profesional', 320);
            if ($negocia) {
                config()->set('marketing.ultron.studio_prompts', 'off');
            }
            $m = $this->entrante('¿Qué incluye el Plan Valera?');
            $decide = $this->decide($m, $negocia);
            $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, $borrador, 3), self::APROBADO))->assertOk()->json();
            $this->assertSame($borrador, $r['reply_final']);
            $this->assertArrayNotHasKey('benefits', $this->ultimaAccion()->metadata);
            $this->assertArrayNotHasKey('policy_violations', $this->ultimaAccion()->metadata);
        }
    }

    // ── Los tres tonos ──────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function tonosQueTutean(): array
    {
        return ['cercano' => ['cercano'], 'entusiasta' => ['entusiasta']];
    }

    #[DataProvider('tonosQueTutean')]
    public function test_cercano_y_entusiasta_la_lista_que_tutea_sale(string $tono): void
    {
        $this->publica($tono, 200);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PANORAMA_CON_PRECIO, self::TRES_DE_TU, null);

        $this->assertSame(self::TRES_DE_TU_EXPANDIDO, $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertArrayNotHasKey('policy_violations', $fila->metadata);
        $this->assertArrayNotHasKey('register', $fila->metadata);
        $this->assertTrue($fila->metadata['plans_overview']['valid']);
        $this->assertSame(self::PO, array_column((array) $this->memoria()->get('prices_delivered'), 'plan_id'));
    }

    public function test_profesional_la_lista_que_tutea_se_descarta_como_cualquier_borrador(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PANORAMA_CON_PRECIO, self::TRES_DE_TU, null);

        $this->assertSame(self::PRECIOS_200, $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertSame([UltronCommitService::VIOLACION_REGISTRO], $fila->metadata['policy_violations'], 'la lista vale; el registro, no');
        $this->assertMismaTraza(['expected' => 'usted', 'found' => ['te'], 'action' => 'discarded'], $fila->metadata['register']);
        $this->assertTrue($fila->metadata['plans_overview']['valid']);
        $this->assertSame([], UltronRegisterDetector::tuteo((string) $r['reply_final']));
        $this->assertStringNotContainsString(self::TRES_DE_TU_EXPANDIDO, (string) $this->ultimoSaliente()?->body);

        // La misma lista de usted sale.
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $this->assertSame(self::TRES_EXPANDIDO, $this->aprobado(SalesIntents::PRICING_QUESTION, self::PANORAMA_CON_PRECIO, self::TRES, null)['reply_final']);
    }

    // ── El validador de marcadores (P-3, cambio 1) ──────────────────────────

    public function test_los_marcadores_de_la_lista_solo_valen_con_lista(): void
    {
        $p = new UltronDraftPlaceholders;
        $texto = '{{PLAN_1}} {{PLAN_2}} {{PLAN_3}} {{PLAN_4}} {{PLAN_0}} {{PLAN_01}} {{plan_1}} {{PLAN_PRICE}} {{APP_LINKS}} {{OTRO}}';

        $this->assertSame($p->unknown($texto), $p->unknown($texto, 0), 'la llamada de hoy, idéntica');
        $this->assertSame(['PLAN_1', 'PLAN_2', 'PLAN_3', 'PLAN_4', 'PLAN_0', 'PLAN_01', 'plan_1', 'OTRO'], $p->unknown($texto));
        $this->assertSame(['PLAN_3', 'PLAN_4', 'PLAN_0', 'PLAN_01', 'plan_1', 'OTRO'], $p->unknown($texto, 2));
        $this->assertSame(['PLAN_4', 'PLAN_0', 'PLAN_01', 'plan_1', 'OTRO'], $p->unknown($texto, 3));
        $this->assertSame(['PLAN_4', 'PLAN_0', 'PLAN_01', 'plan_1', 'OTRO'], $p->unknown($texto, 9), 'nunca más de tres');
        $this->assertSame(UltronDraftPlaceholders::ALLOWED, $p->allowed(0));
        $this->assertSame([...UltronDraftPlaceholders::ALLOWED, 'PLAN_1', 'PLAN_2'], $p->allowed(2));
        $this->assertFalse($p->needsPlan('{{PLAN_1}} y {{PLAN_2}}'), 'un marcador de la lista no pide el plan del turno');
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    /**
     * La misma traza, valor a valor y con sus tipos (`assertSame`), sin mirar
     * el orden de las claves de los objetos: PostgreSQL guarda `metadata` como
     * `jsonb`, que las reordena. Las listas conservan su orden (el de `po` y
     * el de `used` importan).
     */
    private function assertMismaTraza(array $esperada, mixed $real): void
    {
        $this->assertIsArray($real);
        $this->assertSame(self::sinOrdenDeClaves($esperada), self::sinOrdenDeClaves($real));
    }

    private static function sinOrdenDeClaves(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }
        $valor = array_map(self::sinOrdenDeClaves(...), $valor);
        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }

    private function beneficiosDistintos(): void
    {
        Plan::query()->whereKey(3)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Asesoría semi personalizada'])]);
        Plan::query()->whereKey(4)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Clases grupales ilimitadas'])]);
        Plan::query()->whereKey(8)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Entrenador personal dedicado'])]);
    }

    private function memoria(): ConversationMemory
    {
        return app(ConversationMemoryService::class)->load($this->conversation->fresh());
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
            'phone' => '3150538'.$n, 'meta_user_id' => '573150538'.$n,
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
            'body' => $texto, 'meta_message_id' => 'wamid.a4l.'.$this->mundo.'.'.uniqid(), 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m, bool $negocia): array
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ] + ($negocia ? ['prompt_contract' => 'ultron-prompts/1'] : []), $this->headers())->assertOk()->json();
    }

    /** La carga firmada del token (`x`). */
    private function cargaDe(array $decide): array
    {
        return (array) (self::cargaDelToken($decide['decide_token'])['x'] ?? []);
    }

    private function spDe(array $decide): ?array
    {
        return $this->cargaDe($decide)['sp'] ?? null;
    }

    private static function propuesta(string $intent, string $borrador, ?int $plan, array $herramientas = []): array
    {
        return [
            'strategy_goal' => 'resolve_objection',
            'next_state' => P::GOAL_DISCOVERY,
            'intent' => $intent,
            'reply_draft' => $borrador,
            'recommended_plan_id' => $plan,
            'main_barrier' => null,
            'next_best_action' => 'answer',
            'confidence' => 0.9,
            'tools_requested' => $herramientas,
        ];
    }

    private function payload(MarketingMessage $m, string $token, array $propuesta, array $critico): array
    {
        return [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => $propuesta,
            'critic' => $critico,
        ];
    }

    /** Un borrador que el revisor aprobó: el turno sale por el camino normal. */
    private function aprobado(string $intent, string $texto, string $borrador, ?int $plan, array $herramientas = []): array
    {
        $m = $this->entrante($texto);
        $decide = $this->decide($m, true);

        return $this->commit($this->payload($m, $decide['decide_token'], self::propuesta($intent, $borrador, $plan, $herramientas), self::APROBADO))->assertOk()->json();
    }

    private function commit(array $payload)
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
