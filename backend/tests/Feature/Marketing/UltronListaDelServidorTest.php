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
use App\Services\Marketing\Ultron\UltronDecideToken;
use App\Services\Marketing\Ultron\UltronDraftPlaceholders;
use App\Services\Marketing\Ultron\UltronPlansOverview as O;
use App\Services\Marketing\Ultron\UltronRegenerationNotes as N;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HistoricalListTurn;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17 · PUERTA 2 · L1: LAS CUATRO COMPROBACIONES DEL PROPIETARIO, por los
 * endpoints `decide` → `ai/check` → `commit` con las clases reales (P-2 A y
 * «Ranura A», 24-11-2026; especificación `12-pt17-puerta-2-especificacion.md`
 * §1). La versión publicada es la de la canaria («profesional», 200), así que
 * Se reproduce un turno con el paquete y el token del compilador 8 emitidos
 * antes del despliegue del 9 (HistoricalListTurn), no una oferta actual del 8.
 *
 *  1. Nunca llega al cliente un marcador sin resolver: la ranura solo vale con
 *     `k` ≥ 8, en el turno de lista y con su caja exacta; en otro caso, 422 en
 *     el commit (y `no_list` o `unknown_marker` en `ai/check`); el texto final
 *     nunca lleva `{{`, `}}` ni `LISTA_DE_PLANES`.
 *  2. Nombres, precios y orden del catálogo autorizado: leídos de `Plan` en el
 *     commit, en el orden firmado de `po`, sin añadir exclusiones.
 *  3. La lista entera o nada: la nota de la longitud pide acortar la prosa
 *     («No quites planes»), y si el último borrador no cabe sale la lista fija
 *     ENTERA, nunca parcial (con un catálogo de nombres largos).
 *  4. La forma A estricta (todos, en orden) y «A y B a la vez» → `mixed` y la
 *     lista fija.
 *
 * Cómo cae sin el cambio: la ranura es un marcador desconocido (422 en el
 * commit y `plans_overview/missing` en `ai/check`), dos de tres marcadores
 * valen, y la lista fija se ajusta al tope publicado soltando planes.
 */
class UltronListaDelServidorTest extends TestCase
{
    use HistoricalListTurn;
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000001b1';

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

    private const PANORAMA_CON_PRECIO = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private const HORARIO = '¿Cuál es el horario de atención entre semana?';

    /** La lista que firma `decide` para esa pregunta: los tres primeros de un mes, en el orden del catálogo. */
    private const PO = [3, 4, 8];

    /** La lista que compone Laravel con ese `po` (75 caracteres). */
    private const LISTA = 'Plan Valera ($65.000 COP); Plan Mensual ($80.000 COP); Élite ($180.000 COP)';

    /** La lista fija (entera: cabe en el tope de la lista, 320, y también en 200). */
    private const LISTA_FIJA = 'Claro. Algunos planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?';

    /** Forma B con la ranura. */
    private const CON_RANURA = 'Claro, con gusto. Le comparto nuestros planes: {{LISTA_DE_PLANES}}. ¿Cuál le interesa más?';

    /** Forma B sin ranura: el servidor pone la lista antes de la pregunta final. */
    private const SIN_RANURA = 'Claro, con gusto. Todos incluyen acceso total al gimnasio. ¿Cuál le interesa más?';

    private const TRES = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?';

    private const APROBADO = ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9];

    /** Un veredicto duro del revisor: el respaldo de la rendición ({@see UltronCommitService::safeFallback()}). */
    private const RECHAZO_DURO = ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.2, 'hard_fail' => 'invented_price', 'issues' => ['factuality']];

    /** Un rechazo BLANDO del revisor (sin fallo duro ni dimensiones de veto): con la lista final del turno, un aviso (L4). */
    private const RECHAZO_BLANDO = ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3, 'issues' => ['relevance'], 'hard_fail' => null];

    /**
     * Nombres largos para los tres de la lista (44, 47 y 47 caracteres): la
     * lista fija entera mide 309 y no cabe en el tope publicado (200), que
     * soltaría el tercero; sí cabe en el tope de la lista (320).
     */
    private const NOMBRES_LARGOS = [
        3 => 'Plan Valera Acceso Total con Asesoría Grupal',
        4 => 'Plan Mensual Clases Ilimitadas y Zona Funcional',
        8 => 'Élite con Entrenador Personal y Nutrición Total',
    ];

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

    public function test_el_turno_historico_del_compilador_8_lleva_la_lista_sin_marcadores(): void
    {
        $decide = $this->decide($this->entrante(self::PANORAMA_CON_PRECIO));

        $sp = $this->cargaDe($decide)['sp'];
        $this->assertSame(8, $sp['k'], 'historical c8 turn emitted before the c9 deployment');
        $this->assertTrue(O::serverList($sp));
        $this->assertSame(self::PO, $this->cargaDe($decide)['po']);
        $this->assertSame([
            ['plan_id' => 3, 'name' => 'Plan Valera', 'duration_days' => 30, 'benefits' => ['Acceso total al gimnasio']],
            ['plan_id' => 4, 'name' => 'Plan Mensual', 'duration_days' => 30, 'benefits' => ['Acceso total al gimnasio']],
            ['plan_id' => 8, 'name' => 'Élite', 'duration_days' => 30, 'benefits' => ['Acceso total al gimnasio']],
        ], $decide['context']['commercial_turn_policy']['plans_overview']['plans'], 'un solo sistema: la ranura; ningún PLAN_k');
        $this->assertStringNotContainsString('PLAN_', json_encode($decide['context']['commercial_turn_policy']));
    }

    // ── 1. Nunca llega al cliente un marcador sin resolver ─────────────────

    public function test_1_la_ranura_sale_como_la_lista_entera(): void
    {
        $r = $this->aprobado(self::PANORAMA_CON_PRECIO, self::CON_RANURA);

        $esperado = 'Claro, con gusto. Le comparto nuestros planes: '.self::LISTA.'. ¿Cuál le interesa más?';
        $this->assertSame($esperado, $r['reply_final']);
        $this->assertSame($esperado, $this->ultimoSaliente()?->body);
        $this->assertSinMarcadores($r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertArrayNotHasKey('policy_violations', $fila->metadata);
        $this->assertMismaTraza(['offered' => self::PO, 'used' => self::PO, 'valid' => true, 'detail' => null, 'shape' => O::SHAPE_SERVER, 'slot' => true], $fila->metadata['plans_overview']);
        $this->assertSame(self::PO, array_column((array) $this->memoria()->get('prices_delivered'), 'plan_id'), 'la memoria anota los tres');

        // Sin ranura, la pone el servidor antes de la pregunta final, con el encabezado de la lista fija.
        $this->nuevaConversacion();
        $r = $this->aprobado(self::PANORAMA_CON_PRECIO, self::SIN_RANURA);
        $this->assertSame('Claro, con gusto. Todos incluyen acceso total al gimnasio. '.O::HEADER_SOME_MONTHLY.self::LISTA.'. ¿Cuál le interesa más?', $r['reply_final']);
        $this->assertSame(false, $this->ultimaAccion()->metadata['plans_overview']['slot']);
    }

    /** @return array<string, array{\Closure(self): array{MarketingMessage,string}, string, string, list<string>}> */
    public static function ranurasFueraDeSuTurno(): array
    {
        return [
            'con un token del compilador 7' => [fn (self $t): array => $t->turnoConCompilador(self::PANORAMA_CON_PRECIO, 7), SalesIntents::PRICING_QUESTION, self::CON_RANURA, []],
            'en un turno sin lista' => [fn (self $t): array => $t->turno(self::HORARIO), SalesIntents::SCHEDULE_QUESTION, 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. Y estos son los planes: {{LISTA_DE_PLANES}}.', []],
            'en un turno de cobro' => [fn (self $t): array => $t->turno(self::PANORAMA_CON_PRECIO), SalesIntents::PAYMENT_LINK_REQUEST, self::CON_RANURA, []],
            'con otra caja' => [fn (self $t): array => $t->turno(self::PANORAMA_CON_PRECIO), SalesIntents::PRICING_QUESTION, 'Claro. Le comparto nuestros planes: {{lista_de_planes}}. ¿Cuál le interesa?', []],
        ];
    }

    /** @param \Closure(self): array{MarketingMessage,string} $turno */
    #[DataProvider('ranurasFueraDeSuTurno')]
    public function test_1_la_ranura_fuera_de_su_turno_es_un_422_y_no_sale_nada(\Closure $turno, string $intent, string $borrador, array $herramientas): void
    {
        [$m, $token] = $turno($this);

        $r = $this->commit($this->payload($m, $token, self::propuesta($intent, $borrador, null, $herramientas), self::APROBADO));

        $r->assertStatus(422)->assertJsonPath('code', 'unknown_placeholder');
        $this->assertContains(strtolower(O::SLOT), array_map('strtolower', (array) $r->json('detail.unknown')));
        $this->assertSame(0, MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->count(), 'un 422 antes de escribir nada');
        $this->assertNull($this->ultimoSaliente(), 'no sale nada');
    }

    public function test_1_ai_check_senala_la_ranura_fuera_de_su_turno(): void
    {
        [$m, $token] = $this->turno(self::HORARIO);
        $r = $this->check($m, $token, 'Abrimos de lunes a viernes. Y estos son los planes: {{LISTA_DE_PLANES}}. ¿Le sirve?', SalesIntents::SCHEDULE_QUESTION)->assertOk();
        $this->assertSame([['code' => 'plans_overview', 'detail' => 'no_list', 'issue' => 'relevance']], $r->json('defects'));
        $this->assertSame(N::serverNote(N::PLANS_OVERVIEW, O::NO_LIST), $r->json('notes'));

        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        $r = $this->check($m, $token, 'Claro. Le comparto nuestros planes: {{lista_de_planes}}. ¿Cuál le interesa?')->assertOk();
        $this->assertSame([['code' => 'plans_overview', 'detail' => 'unknown_marker', 'issue' => 'relevance']], $r->json('defects'));
        $this->assertSame(N::serverNote(N::PLANS_OVERVIEW, O::UNKNOWN_MARKER), $r->json('notes'));

        // Y en su turno, con su caja: pasa.
        $this->assertTrue($this->check($m, $token, self::CON_RANURA)->assertOk()->json('pass'));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function borradoresConLaRanuraMalPuesta(): array
    {
        $con = [SalesIntents::TOOL_APP_LINKS_SEND];

        return [
            'suelta, sin herramientas' => ['Claro. Le comparto LISTA_DE_PLANES. ¿Cuál le interesa?', []],
            'suelta, con herramientas' => ['Claro. Le comparto LISTA_DE_PLANES. Le dejo la app. ¿Cuál le interesa?', $con],
            'con una llave, sin herramientas' => ['Claro. Le comparto {LISTA_DE_PLANES}. ¿Cuál le interesa?', []],
            'con una llave, con herramientas' => ['Claro. Le comparto {LISTA_DE_PLANES}. Le dejo la app. ¿Cuál le interesa?', $con],
            'repetida, sin herramientas' => ['Claro. {{LISTA_DE_PLANES}}. Repito: {{LISTA_DE_PLANES}}. ¿Cuál?', []],
            'repetida, con herramientas' => ['Claro. {{LISTA_DE_PLANES}}. Repito: {{LISTA_DE_PLANES}}. Le dejo la app. ¿Cuál?', $con],
            'A y B, con herramientas' => ['Claro. {{LISTA_DE_PLANES}} y {{PLAN_1}}. Le dejo la app. ¿Cuál?', $con],
            'llaves sueltas, con herramientas' => ['Claro. {{LISTA_DE_PLANES}} }}. Le dejo la app. ¿Cuál?', $con],
            'bien puesta, con herramientas' => ['Claro. Estos son: {{LISTA_DE_PLANES}}. Le dejo la app. ¿Cuál?', $con],
            // Revisión de seguridad de la puerta 2, B1: dentro de otra palabra (el `_` es de palabra) también tiene que saltar la red.
            'en cursiva, con herramientas' => ['Con gusto, le comparto _LISTA_DE_PLANES_ ¿Cuál le interesa?', $con],
            'pegada a una cifra, con herramientas' => ['Con gusto, le comparto LISTA_DE_PLANES2. ¿Cuál le interesa?', $con],
            'pegada a una palabra, con herramientas' => ['Con gusto, le comparto laLISTA_DE_PLANES. ¿Cuál le interesa?', $con],
            // B1, el mismo hueco en los marcadores de siempre.
            '_PLAN_1_ en cursiva, con herramientas' => ['Con gusto. Tenemos _PLAN_1_ y _PLAN_2_, los dos de un mes. Le dejo la app. ¿Cuál le interesa?', $con],
            '_APP_LINKS_ en cursiva, con herramientas' => ['Con gusto, aquí tiene la app: _APP_LINKS_ ¿Le ayudo con algo más?', $con],
        ];
    }

    /** Pase lo que pase con la ranura, lo que sale nunca lleva `{{`, `}}` ni `LISTA_DE_PLANES`. */
    #[DataProvider('borradoresConLaRanuraMalPuesta')]
    public function test_1_el_texto_final_nunca_lleva_la_ranura_ni_llaves(string $borrador, array $herramientas): void
    {
        $r = $this->aprobado(self::PANORAMA_CON_PRECIO, $borrador, $herramientas);

        $this->assertSinMarcadores((string) $r['reply_final']);
        $this->assertSinMarcadores((string) $this->ultimoSaliente()?->body);
    }

    /** Sin herramientas, la ranura suelta no es una lista: `mixed`, y sale la lista fija (no el último recurso). */
    public function test_1_la_ranura_suelta_sin_herramientas_da_la_lista_fija(): void
    {
        $r = $this->aprobado(self::PANORAMA_CON_PRECIO, 'Claro. Le comparto LISTA_DE_PLANES. ¿Cuál le interesa?');

        $this->assertSame(self::LISTA_FIJA, $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertContains(CommercialTurnPolicy::VIOLACION_PANORAMA, $fila->metadata['policy_violations']);
        $this->assertSame(O::MIXED, $fila->metadata['plans_overview']['detail']);
    }

    // ── 2. Nombres, precios y orden del catálogo autorizado ─────────────────

    public function test_2_los_nombres_y_los_precios_se_leen_de_plan_en_el_commit(): void
    {
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m);
        Plan::query()->whereKey(4)->update(['price' => 87500]);
        Plan::query()->whereKey(3)->update(['name' => 'Plan Ónix']);

        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::CON_RANURA, null), self::APROBADO))->assertOk()->json();

        $this->assertSame('Claro, con gusto. Le comparto nuestros planes: Plan Ónix ($65.000 COP); Plan Mensual ($87.500 COP); Élite ($180.000 COP). ¿Cuál le interesa más?', $r['reply_final']);
        $this->assertTrue($this->ultimaAccion()->metadata['plans_overview']['valid']);
    }

    public function test_2_en_el_orden_firmado_de_po(): void
    {
        $r = $this->aprobado('¿Cuánto cuesta el Élite y qué otros hay?', self::CON_RANURA);

        $this->assertSame([8, 3, 4], $this->ultimaAccion()->metadata['plans_overview']['offered'], 'el que nombró, primero');
        $this->assertSame('Claro, con gusto. Le comparto nuestros planes: Élite ($180.000 COP); Plan Valera ($65.000 COP); Plan Mensual ($80.000 COP). ¿Cuál le interesa más?', $r['reply_final']);
    }

    /** Las exclusiones vigentes (el plan rechazado) y ninguna más: la lista es la de `po`, entera. */
    public function test_2_sin_añadir_exclusiones(): void
    {
        app(ConversationMemoryService::class)->recordPlanRejected($this->conversation->fresh(), 3);

        $r = $this->aprobado(self::PANORAMA_CON_PRECIO, self::CON_RANURA);

        $this->assertSame([4, 8, 14], $this->ultimaAccion()->metadata['plans_overview']['offered'], 'sin el rechazado; PROMO X4 entra');
        $this->assertSame('Claro, con gusto. Le comparto nuestros planes: Plan Mensual ($80.000 COP); Élite ($180.000 COP); PROMO X4 ($75.000 COP). ¿Cuál le interesa más?', $r['reply_final']);
    }

    /** Todo o nada: si un plan de `po` deja de venderse entre `decide` y el commit, la lista fija del catálogo de AHORA. */
    public function test_2_un_plan_que_deja_de_venderse_da_la_lista_fija_de_ahora(): void
    {
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m);
        Plan::query()->whereKey(4)->update(['sellable' => false]);

        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::CON_RANURA, null), self::APROBADO))->assertOk()->json();

        $this->assertSame('Claro. Planes de un mes: Plan Valera, $65.000 COP; Élite, $180.000 COP; PROMO X4, $75.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?', $r['reply_final']);
        $this->assertMismaTraza(['offered' => self::PO, 'used' => self::PO, 'valid' => false, 'detail' => 'not_sellable', 'shape' => O::SHAPE_SERVER, 'slot' => true], $this->ultimaAccion()->metadata['plans_overview']);
    }

    // ── 3. La lista entera o nada ───────────────────────────────────────────

    public function test_3_la_nota_de_la_longitud_pide_acortar_la_prosa_y_no_quitar_planes(): void
    {
        $this->nombresLargos();
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        $largo = self::largo('{{LISTA_DE_PLANES}}');

        $r = $this->check($m, $token, $largo)->assertOk();

        $lista = $this->listaLarga();
        $this->assertSame(185, UltronReplyFitter::measure($lista));
        $mide = UltronReplyFitter::measure(str_replace('{{LISTA_DE_PLANES}}', $lista, $largo));
        $this->assertGreaterThan(320, $mide);
        $this->assertSame([['code' => 'length_over_cap', 'detail' => null, 'issue' => 'progressive_disclosure']], $r->json('defects'));
        $this->assertSame('DEMASIADO LARGO: con la lista mide '.$mide.' caracteres y el limite de este turno es 320. La lista ya ocupa 185: tu texto, como mucho 135. Primero la respuesta; una pregunta corta o ninguna. No quites planes: la lista va entera.', $r->json('notes'));
    }

    /** @return array<string, array{string}> la misma prosa larga con la lista en cada forma */
    public static function borradoresQueNoCaben(): array
    {
        return [
            'forma B, con la ranura' => [self::largo('{{LISTA_DE_PLANES}}')],
            'forma A, entera' => [self::largo('{{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}')],
        ];
    }

    /**
     * Si el último borrador no cabe, sale la lista fija ENTERA (con el tope de
     * la lista, 320), nunca una parcial. Antes del cambio, la forma A salía con
     * la lista fija ajustada al tope publicado (200): sin el tercer plan.
     */
    #[DataProvider('borradoresQueNoCaben')]
    public function test_3_si_el_ultimo_borrador_no_cabe_sale_la_lista_fija_entera(string $borrador): void
    {
        $this->nombresLargos();

        $r = $this->aprobado(self::PANORAMA_CON_PRECIO, $borrador);

        $entera = 'Claro. Algunos planes de un mes: '.self::NOMBRES_LARGOS[3].', $65.000 COP; '.self::NOMBRES_LARGOS[4].', $80.000 COP; '.self::NOMBRES_LARGOS[8].', $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?';
        $this->assertSame(309, UltronReplyFitter::measure($entera));
        $this->assertSame($entera, $r['reply_final']);
        $this->assertSame($entera, $this->ultimoSaliente()?->body);
        $fila = $this->ultimaAccion();
        // La causa es la longitud (la que señaló `ai/check`); la lista valía: lo que sale en su lugar es la lista fija.
        $this->assertContains(UltronCommitService::VIOLACION_LONGITUD, $fila->metadata['policy_violations']);
        $this->assertNotContains(CommercialTurnPolicy::VIOLACION_PANORAMA, $fila->metadata['policy_violations']);
        $this->assertSame(320, $fila->metadata['length']['list_cap']);
        $this->assertArrayNotHasKey('overflow', $fila->metadata['length']);
        $this->assertSame([3, 4, 8], array_column((array) $this->memoria()->get('prices_delivered'), 'plan_id'), 'la memoria: los tres');
    }

    /** También en la rendición (el revisor caído): la lista fija entera, con el tope de la lista. */
    public function test_3_en_la_rendicion_la_lista_fija_tambien_sale_entera(): void
    {
        $this->nombresLargos();
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m);

        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::CON_RANURA, null), self::RECHAZO_DURO))->assertOk()->json();

        foreach (self::NOMBRES_LARGOS as $nombre) {
            $this->assertStringContainsString($nombre, (string) $r['reply_final']);
        }
        $this->assertLessThanOrEqual(320, UltronReplyFitter::measure((string) $r['reply_final']));
        $this->assertSame(320, $this->ultimaAccion()->metadata['length']['list_cap']);
    }

    /**
     * El caso degenerado: con nombres tan largos que ni la lista fija entera
     * cabe en el tope de la lista, no sale ninguna lista (ni la del modelo ni
     * una parcial): sale el respaldo de la intención.
     */
    public function test_3_si_ni_la_lista_fija_entera_cabe_no_sale_una_parcial(): void
    {
        // The c8 token predates both deployment and this catalog change;
        // c9 correctly refuses to generate a contract for an oversized list.
        [$message, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        foreach ([3, 4, 8] as $id) {
            Plan::query()->whereKey($id)->update(['name' => str_repeat('Plan con un nombre larguísimo de verdad ', 3).$id]);
        }

        $r = $this->commit($this->payload($message, $token, self::propuesta(SalesIntents::PRICING_QUESTION, self::CON_RANURA, null), self::APROBADO))->assertOk()->json();

        $nombrados = array_filter([3, 4, 8], fn (int $id): bool => str_contains((string) $r['reply_final'], 'larguísimo de verdad '.$id));
        $this->assertSame([], array_values($nombrados), 'ni uno: nunca una lista parcial');
        $this->assertLessThanOrEqual(200, UltronReplyFitter::measure((string) $r['reply_final']));
    }

    // ── 4. La forma A estricta y «A y B a la vez» ───────────────────────────

    /** @return array<string, array{string, string}> */
    public static function listasAQueNoValen(): array
    {
        return [
            'dos de tres' => ['Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}, los dos de un mes. ¿Cuál le interesa?', O::MISSING],
            'fuera de orden' => ['Claro. Tenemos {{PLAN_2}}, {{PLAN_1}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa?', O::MIXED],
            'A y B a la vez' => ['Claro. Le comparto {{LISTA_DE_PLANES}}; el más pedido es {{PLAN_1}}. ¿Cuál le interesa?', O::MIXED],
        ];
    }

    #[DataProvider('listasAQueNoValen')]
    public function test_4_la_forma_a_estricta_y_a_y_b_a_la_vez_dan_su_nota_y_la_lista_fija(string $borrador, string $detalle): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        $c = $this->check($m, $token, $borrador)->assertOk();
        $this->assertSame([['code' => 'plans_overview', 'detail' => $detalle, 'issue' => 'relevance']], $c->json('defects'));
        $this->assertSame(N::serverNote(N::PLANS_OVERVIEW, $detalle, $detalle === O::MISSING || $detalle === O::MIXED ? ['X' => 75, 'N' => 320, 'R' => 245] : []), $c->json('notes'));

        $r = $this->commit($this->payload($m, $token, self::propuesta(SalesIntents::PRICING_QUESTION, $borrador, null), self::APROBADO))->assertOk()->json();

        $this->assertSame(self::LISTA_FIJA, $r['reply_final'], 'el respaldo justificado: la lista fija');
        $fila = $this->ultimaAccion();
        $this->assertContains(CommercialTurnPolicy::VIOLACION_PANORAMA, $fila->metadata['policy_violations']);
        $this->assertSame([false, $detalle, O::SHAPE_MARKERS], [$fila->metadata['plans_overview']['valid'], $fila->metadata['plans_overview']['detail'], $fila->metadata['plans_overview']['shape']]);
    }

    /**
     * Decisión del lead (24-11): la regla L2 de la puerta 1 se mantiene con la
     * lista del servidor. En forma A, un borrador que habla de menos de dos
     * planes de la lista (por su marcador o a mano) recibe «falta la lista»,
     * no «nombre duplicado» ni «mezclados»: los dos borradores de L2.
     *
     * @return array<string, array{string}>
     */
    public static function deUnSoloPlan(): array
    {
        return [
            'un plan a mano y su marcador' => ['Le cuento que el Plan Valera incluye acceso ilimitado al gimnasio, zona de pesas, asesoría semi personalizada y valoración inicial, todo por {{PLAN_1}}. Para orientarle mejor, ¿ha entrenado antes o es principiante?'],
            'un marcador con PLAN_PRICE' => ['Por lo que pregunta, tenemos el {{PLAN_1}} con acceso ilimitado y asesoría semi personalizada por {{PLAN_PRICE}}. ¿Ha entrenado antes o es su primera vez para orientarle mejor?'],
        ];
    }

    #[DataProvider('deUnSoloPlan')]
    public function test_4_l2_de_un_solo_plan_falta_la_lista_tambien_con_el_compilador_8(string $borrador): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        $c = $this->check($m, $token, $borrador)->assertOk();

        $this->assertSame(O::MISSING, $c->json('defects.0.detail'));
        $this->assertSame([N::PLANS_OVERVIEW, O::MISSING], [$c->json('defects.0.code'), $c->json('defects.0.detail')]);
        $this->assertStringStartsWith(N::serverNote(N::PLANS_OVERVIEW, O::MISSING, ['X' => 75, 'N' => 320, 'R' => 245]), (string) $c->json('notes'), 'la nota de la ranura: «Sin nombres de plan, cifras ni marcadores PLAN_»');
    }

    public function test_4_la_forma_a_entera_y_en_orden_vale(): void
    {
        $r = $this->aprobado(self::PANORAMA_CON_PRECIO, self::TRES);

        $this->assertSame('Claro. Tenemos Plan Valera ($65.000 COP), Plan Mensual ($80.000 COP) y Élite ($180.000 COP), todos de un mes. ¿Cuál le interesa más?', $r['reply_final']);
        $this->assertMismaTraza(['offered' => self::PO, 'used' => self::PO, 'valid' => true, 'detail' => null, 'shape' => O::SHAPE_MARKERS, 'slot' => false], $this->ultimaAccion()->metadata['plans_overview']);
    }

    // ── Revisión de seguridad de la puerta 2 ────────────────────────────────

    /**
     * M1: la ranura con una llave pegada (`{{{LISTA_DE_PLANES}}}`) saldría
     * entre llaves sueltas. Es `mixed`: `ai/check` pide reescribirla y, con el
     * panorama, sale la lista fija.
     */
    public function test_m1_la_ranura_con_una_llave_pegada_es_mixed(): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        $borrador = 'Claro, con gusto. Le comparto nuestros planes: {{{LISTA_DE_PLANES}}}. ¿Cuál le interesa más?';

        $c = $this->check($m, $token, $borrador)->assertOk();
        $this->assertSame([['code' => 'plans_overview', 'detail' => O::MIXED, 'issue' => 'relevance']], $c->json('defects'));

        $r = $this->commit($this->payload($m, $token, self::propuesta(SalesIntents::PRICING_QUESTION, $borrador, null), self::APROBADO))->assertOk()->json();
        $this->assertSame(self::LISTA_FIJA, $r['reply_final']);
        $this->assertSame([false, O::MIXED], [$this->ultimaAccion()->metadata['plans_overview']['valid'], $this->ultimaAccion()->metadata['plans_overview']['detail']]);
    }

    /**
     * I1: con `k` ≥ 8 y el panorama en el commit, pero SIN la lista firmada
     * (`decide` no la ofreció: aquí, el token firmado sin `po`, como lo firma
     * cuando la política de SU intención no ve el panorama o no hay dos planes
     * que elegir), la regla nueva no aplica y el turno va como con `k` ≤ 7: el
     * borrador que nombra dos planes y no pregunta el precio se queda. Antes de
     * la corrección salía la lista fija, y con el tope publicado (160) PARCIAL.
     */
    public function test_i1_sin_la_lista_firmada_el_panorama_va_como_antes(): void
    {
        $this->nuevaConversacion(160);
        $m = $this->entrante('¿Qué planes tienen?');
        $decide = $this->decide($m);
        $x = $this->cargaDe($decide);
        $this->assertSame([8, 160], [$x['sp']['k'] ?? null, $x['sp']['n'] ?? null], 'el sello del 8, con tope 160');
        unset($x['po']);
        $token = app(UltronDecideToken::class)->issue((int) $this->conversation->id, (int) $m->id, $decide['commercial_phase'], $decide['allowed_transitions'], $decide['knowledge_version'], $x)['token'];
        $borrador = 'Tenemos el Plan Valera y el Plan Mensual, los dos de un mes. ¿Cuál le interesa más?';

        $r = $this->commit($this->payload($m, $token, self::propuesta(SalesIntents::UNKNOWN, $borrador, null), self::APROBADO))->assertOk()->json();

        $this->assertSame($borrador, $r['reply_final'], 'como con k ≤ 7: nombra dos planes y no pregunta el precio');
        $this->assertNotContains(CommercialTurnPolicy::VIOLACION_PANORAMA, (array) ($this->ultimaAccion()->metadata['policy_violations'] ?? []));
        $this->assertArrayNotHasKey('plans_overview', $this->ultimaAccion()->metadata, 'sin lista firmada no hay traza de la lista');
    }

    /**
     * M2: un nombre del CRM con un control de dirección (U+202E) o un
     * separador de línea (U+2028): la lista del servidor no se compone con él
     * (`compose()` da null), la traza lo dice con su propio código (el plan
     * SÍ se vende: no es `not_sellable`) y el aviso lleva el id del plan, sin
     * el nombre.
     *
     * @return array<string, array{string}>
     */
    public static function nombresConControles(): array
    {
        return [
            'U+202E' => ["Plan \u{202E}Mensual"],
            'U+2028' => ["Plan\u{2028}Mensual"],
        ];
    }

    #[DataProvider('nombresConControles')]
    public function test_m2_un_nombre_con_controles_no_entra_en_la_lista_y_la_traza_lo_dice(string $nombre): void
    {
        $avisos = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$avisos): void {
            if ($e->message === 'ultron.commit.plans_overview_unsafe_name') {
                $avisos[] = [$e->level, $e->context];
            }
        });
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m);
        Plan::query()->whereKey(4)->update(['name' => $nombre]);

        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::CON_RANURA, null), self::APROBADO))->assertOk()->json();

        $fila = $this->ultimaAccion();
        $this->assertSame([false, 'unsafe_plan_name'], [$fila->metadata['plans_overview']['valid'], $fila->metadata['plans_overview']['detail']]);
        $this->assertContains(CommercialTurnPolicy::VIOLACION_PANORAMA, $fila->metadata['policy_violations'], 'no sale la lista del modelo: la rama de la política');
        $this->assertStringNotContainsString('LISTA_DE_PLANES', (string) $r['reply_final']);
        $this->assertCount(1, $avisos);
        $this->assertSame(['warning', [4]], [$avisos[0][0], $avisos[0][1]['plan_ids'] ?? null]);
        $this->assertStringNotContainsString('Mensual', (string) json_encode($avisos[0][1], JSON_UNESCAPED_UNICODE), 'sin el texto del nombre');
    }

    // ── Revisión final de la puerta 2 ───────────────────────────────────────

    /**
     * I1-a, acotado por el lead: en la forma B, `name_written` también con el
     * nombre a mano de un plan del catálogo (sin archivar) que NO SE VENDE
     * ahora: ofrecer lo que no se vende. La sonda S3: el Élite desactivado,
     * fuera de la lista. Con un rechazo blando del revisor, el respaldo.
     */
    public function test_i1a_un_plan_que_no_se_vende_escrito_a_mano_es_name_written(): void
    {
        Plan::query()->whereBetween('duration_days', [28, 31])->update(['sellable' => false]);
        $borrador = 'Le confirmo que el plan Élite es el que mejor le encaja. ¿Le ayudo a concretar su inscripción?';
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        $this->assertNotContains(8, $this->cargaDe(['decide_token' => $token])['po'], 'el Élite no es de la lista');

        $c = $this->check($m, $token, $borrador)->assertOk();
        $this->assertSame([['code' => 'plans_overview', 'detail' => O::NAME_WRITTEN, 'issue' => 'relevance']], $c->json('defects'));
        $this->assertSame(N::serverNote(N::PLANS_OVERVIEW, O::NAME_WRITTEN), $c->json('notes'));

        // Con un rechazo blando del revisor, no es la lista final: el respaldo, no el aviso, y sin el Élite.
        $r = $this->commit($this->payload($m, $token, self::propuesta(SalesIntents::PRICING_QUESTION, $borrador, null), self::RECHAZO_BLANDO))->assertOk()->json();
        $this->assertSame('SAFE_CURATED_REPLY', $r['fallback_mode'] ?? null);
        $this->assertStringNotContainsString('Élite', (string) $r['reply_final']);
        $this->assertArrayNotHasKey('critic_advisory', $this->ultimaAccion()->metadata);
    }

    /**
     * I1-a, acotado por el lead (la sonda S2): un plan que SE VENDE, aunque no
     * sea de la lista, se puede nombrar: es verdad. `ai/check` no lo marca y,
     * con el revisor de acuerdo, sale del modelo con la lista del servidor.
     * Con I1-a sobre todo el catálogo era `name_written`: si el filtro de los
     * vendibles desaparece, esta prueba cae.
     */
    public function test_i1a_un_plan_que_se_vende_fuera_de_la_lista_se_puede_nombrar(): void
    {
        $borrador = 'Le recomiendo el Trimestre, que es el que mejor se ajusta a lo que busca. ¿Le interesa?';
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        $this->assertNotContains(5, $this->cargaDe(['decide_token' => $token])['po'], 'el Trimestre no es de la lista');

        $c = $this->check($m, $token, $borrador)->assertOk();
        $this->assertSame([true, []], [$c->json('pass'), $c->json('defects')]);

        $r = $this->commit($this->payload($m, $token, self::propuesta(SalesIntents::PRICING_QUESTION, $borrador, null), self::APROBADO))->assertOk()->json();
        $this->assertSame('Le recomiendo el Trimestre, que es el que mejor se ajusta a lo que busca. Algunos planes de un mes: '.self::LISTA.'. ¿Le interesa?', $r['reply_final']);
        $this->assertSame([true, null], [$this->ultimaAccion()->metadata['plans_overview']['valid'], $this->ultimaAccion()->metadata['plans_overview']['detail']]);
    }

    /**
     * I1-b: con la lista del servidor, «lista final» solo con la forma A
     * estricta válida o la forma B CON ranura. Sin ranura (el revisor no ve la
     * lista que pondrá el servidor, riesgo 1 de §6), un rechazo blando se
     * respeta y `ai/check` deja reescribir. S6: un nombre inventado, que solo
     * el revisor puede ver.
     */
    public function test_i1b_la_forma_b_sin_ranura_no_es_la_lista_final(): void
    {
        $inventado = 'Le recomiendo el Plan Gold Premium, ideal para empezar con todo. ¿Le interesa?';
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        $c = $this->check($m, $token, $inventado)->assertOk();
        $this->assertSame([true, [], true, null], [$c->json('pass'), $c->json('defects'), $c->json('may_rewrite'), $c->json('budget.reason')], 'sin ranura no es la lista final: se puede reescribir');
        $r = $this->commit($this->payload($m, $token, self::propuesta(SalesIntents::PRICING_QUESTION, $inventado, null), self::RECHAZO_BLANDO))->assertOk()->json();
        $this->assertSame('SAFE_CURATED_REPLY', $r['fallback_mode'] ?? null, 'el rechazo del revisor se respeta');
        $this->assertStringNotContainsString('Gold Premium', (string) $r['reply_final']);

        // Con la ranura sí es la lista final: el mismo rechazo blando es un aviso y sale del modelo (L4). En otra conversación: la memoria de la primera cambia el orden de la lista.
        $this->nuevaConversacion();
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        $c = $this->check($m, $token, self::CON_RANURA)->assertOk();
        $this->assertSame([true, false, 'list_final'], [$c->json('pass'), $c->json('may_rewrite'), $c->json('budget.reason')]);
        $r = $this->commit($this->payload($m, $token, self::propuesta(SalesIntents::PRICING_QUESTION, self::CON_RANURA, null), self::RECHAZO_BLANDO))->assertOk()->json();
        $this->assertSame('Claro, con gusto. Le comparto nuestros planes: '.self::LISTA.'. ¿Cuál le interesa más?', $r['reply_final']);
        $this->assertTrue($this->ultimaAccion()->metadata['critic_advisory'] ?? false);
    }

    /**
     * I1-c: la constante de §6 que apaga la inserción sin ranura (por defecto,
     * encendida). Apagada, el borrador sin ranura en el turno de lista es
     * `missing` en `ai/check` y en el commit (sale la lista fija); con la
     * ranura, nada cambia.
     */
    public function test_i1c_con_la_insercion_sin_ranura_apagada_falta_la_lista(): void
    {
        $this->assertTrue(O::INSERT_WITHOUT_SLOT, 'por defecto, encendida');
        $antes = O::$insertWithoutSlot;
        O::$insertWithoutSlot = false;
        try {
            [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
            $c = $this->check($m, $token, self::SIN_RANURA)->assertOk();
            $this->assertSame([['code' => 'plans_overview', 'detail' => O::MISSING, 'issue' => 'relevance']], $c->json('defects'));
            $this->assertSame(N::serverNote(N::PLANS_OVERVIEW, O::MISSING, ['X' => 75, 'N' => 320, 'R' => 245]), $c->json('notes'));
            $r = $this->commit($this->payload($m, $token, self::propuesta(SalesIntents::PRICING_QUESTION, self::SIN_RANURA, null), self::APROBADO))->assertOk()->json();
            $this->assertSame(self::LISTA_FIJA, $r['reply_final']);
            $this->assertSame([false, O::MISSING, false], [$this->ultimaAccion()->metadata['plans_overview']['valid'], $this->ultimaAccion()->metadata['plans_overview']['detail'], $this->ultimaAccion()->metadata['plans_overview']['slot']]);

            // Con la ranura, igual que encendida (en otra conversación: la memoria de la primera cambia el orden de la lista).
            $this->nuevaConversacion();
            $r = $this->aprobado(self::PANORAMA_CON_PRECIO, self::CON_RANURA);
            $this->assertSame('Claro, con gusto. Le comparto nuestros planes: '.self::LISTA.'. ¿Cuál le interesa más?', $r['reply_final']);
        } finally {
            O::$insertWithoutSlot = $antes;
        }
    }

    /**
     * I3-3: las notas de la lista del servidor de `name_written` y
     * `price_written`, literales, por el endpoint de `ai/check` con `k` = 8.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function notasPorElEndpoint(): array
    {
        return [
            'name_written' => ['Claro. El Plan Valera es el más pedido. Estos son: {{LISTA_DE_PLANES}}. ¿Cuál le interesa?', O::NAME_WRITTEN, 'relevance'],
            'price_written' => ['Claro. Desde $50.000 al mes: {{LISTA_DE_PLANES}}. ¿Cuál le interesa?', O::PRICE_WRITTEN, 'factuality'],
        ];
    }

    #[DataProvider('notasPorElEndpoint')]
    public function test_i3_las_notas_de_la_ranura_por_el_endpoint(string $borrador, string $detalle, string $issue): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        $c = $this->check($m, $token, $borrador)->assertOk();

        $this->assertSame([['code' => 'plans_overview', 'detail' => $detalle, 'issue' => $issue]], $c->json('defects'));
        $this->assertSame(N::serverNote(N::PLANS_OVERVIEW, $detalle), $c->json('notes'));
        $this->assertStringNotContainsString('{{', (string) $c->json('notes'));
    }

    /** Menor 1 (S4): con herramientas, la triple llave sale sin llaves sueltas (la ranura se las queda); `verify()` sigue dando `mixed`. */
    public function test_menor1_la_triple_llave_con_herramientas_sale_sin_llaves(): void
    {
        $r = $this->aprobado(self::PANORAMA_CON_PRECIO, 'Claro, con gusto. Le comparto nuestros planes: {{{LISTA_DE_PLANES}}}. Le dejo la app. ¿Cuál le interesa más?', [SalesIntents::TOOL_APP_LINKS_SEND]);

        $this->assertStringContainsString('Le comparto nuestros planes: '.self::LISTA.'. Le dejo la app.', (string) $r['reply_final']);
        $this->assertDoesNotMatchRegularExpression('/[{}]/', (string) $r['reply_final']);
        $this->assertSame(O::MIXED, $this->ultimaAccion()->metadata['plans_overview']['detail']);
    }

    /**
     * Menor 2 (S7): un nombre con un control de dirección o un separador de
     * línea sale en la lista fija SIN el control y con todos los planes; el
     * aviso lleva el id del plan, sin el nombre.
     *
     * @return array<string, array{string}>
     */
    public static function nombresParaLaListaFija(): array
    {
        return [
            'U+202E' => ["Plan \u{202E}Mensual"],
            'U+2028' => ["Plan\u{2028}Mensual"],
            'U+2066 y U+2069' => ["Plan \u{2066}Mensual\u{2069}"],
        ];
    }

    #[DataProvider('nombresParaLaListaFija')]
    public function test_menor2_la_lista_fija_quita_los_controles_del_nombre_y_no_suelta_el_plan(string $nombre): void
    {
        $avisos = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$avisos): void {
            if ($e->message === 'ultron.commit.fixed_list_name_controls') {
                $avisos[] = [$e->level, $e->context];
            }
        });
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m);
        Plan::query()->whereKey(4)->update(['name' => $nombre]);

        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::CON_RANURA, null), self::APROBADO))->assertOk()->json();

        $this->assertSame(self::LISTA_FIJA, $r['reply_final'], 'la lista fija entera, con el nombre sin el control');
        $this->assertDoesNotMatchRegularExpression('/[\x{0B}\x{0C}\x{85}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', (string) $r['reply_final']);
        $this->assertSame([['warning', [4]]], array_map(static fn (array $a): array => [$a[0], $a[1]['plan_ids'] ?? null], $avisos));
        $this->assertStringNotContainsString('Mensual', (string) json_encode($avisos, JSON_UNESCAPED_UNICODE), 'sin el texto del nombre');
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    /** La prosa larga (unos 200 caracteres) con la lista en `$lista`. */
    private static function largo(string $lista): string
    {
        return 'Claro, con gusto le cuento. Estos son nuestros planes: '.$lista.'. Todos incluyen acceso a la zona de pesas, a la de cardio y a los vestidores, y puede venir en el horario que mejor le quede durante toda la semana. ¿Cuál le interesa más?';
    }

    private function nombresLargos(): void
    {
        foreach (self::NOMBRES_LARGOS as $id => $nombre) {
            Plan::query()->whereKey($id)->update(['name' => $nombre]);
        }
    }

    private function listaLarga(): string
    {
        return (string) O::compose(self::PO, array_map(fn (int $id): string => O::item(Plan::query()->findOrFail($id)), array_combine(self::PO, self::PO)));
    }

    private function assertSinMarcadores(string $texto): void
    {
        $this->assertStringNotContainsString('{{', $texto);
        $this->assertStringNotContainsString('}}', $texto);
        $this->assertStringNotContainsStringIgnoringCase('LISTA_DE_PLANES', $texto);
        // Con una expresión propia, no con la de la red que se prueba (B1: con `\b` la red no veía `_PLAN_1_`).
        $this->assertDoesNotMatchRegularExpression('/PLAN_[0-9A-Z]|APP_LINKS/i', $texto, '«'.$texto.'»');
        $this->assertFalse(UltronDraftPlaceholders::looksLikeMarker($texto), '«'.$texto.'»');
    }

    /** La misma traza sin mirar el orden de las claves (PostgreSQL guarda `metadata` como `jsonb`). */
    private function assertMismaTraza(array $esperada, mixed $real): void
    {
        $this->assertIsArray($real);
        ksort($esperada);
        ksort($real);
        $this->assertSame($esperada, $real);
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

    private function nuevaConversacion(int $tope = 200): void
    {
        $n = str_pad((string) ++$this->mundo, 3, '0', STR_PAD_LEFT);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3150537'.$n, 'meta_user_id' => '573150537'.$n,
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        config()->set('marketing.ultron.studio_prompts', 'canary');
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => $tope]), null);
    }

    private function entrante(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.l1.'.$this->mundo.'.'.uniqid(), 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m): array
    {
        $decide = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id, 'prompt_contract' => 'ultron-prompts/1', 'generation_contract' => 'ultron-list-turn/1',
        ], $this->headers())->assertOk()->json();

        return $this->historicalListTurn($decide, $m);
    }

    /** Un turno con su `decide` negociado: el mensaje y el token. @return array{MarketingMessage,string} */
    public function turno(string $texto): array
    {
        $m = $this->entrante($texto);

        return [$m, $this->decide($m)['decide_token']];
    }

    /**
     * El mismo turno con el token firmado como lo firmaba `decide` con otro
     * compilador (`sp.k`): el mismo firmador, para un token de antes del
     * despliegue que llega al commit después. @return array{MarketingMessage,string}
     */
    public function turnoConCompilador(string $texto, int $k): array
    {
        $m = $this->entrante($texto);
        $decide = $this->decide($m);
        $x = self::cargaDelToken($decide['decide_token'])['x'];
        $x['sp']['k'] = $k;

        return [$m, app(UltronDecideToken::class)->issue((int) $this->conversation->id, (int) $m->id, $decide['commercial_phase'], $decide['allowed_transitions'], $decide['knowledge_version'], $x)['token']];
    }

    /** La carga firmada del token (`x`). */
    private function cargaDe(array $decide): array
    {
        return (array) (self::cargaDelToken($decide['decide_token'])['x'] ?? []);
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
    private function aprobado(string $texto, string $borrador, array $herramientas = []): array
    {
        [$m, $token] = $this->turno($texto);

        return $this->commit($this->payload($m, $token, self::propuesta(SalesIntents::PRICING_QUESTION, $borrador, null, $herramientas), self::APROBADO))->assertOk()->json();
    }

    private function check(MarketingMessage $m, string $token, string $borrador, string $intent = SalesIntents::PRICING_QUESTION): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/check', [
            'conversation_id' => $m->conversation_id,
            'message_id' => $m->id,
            'decide_token' => $token,
            'attempt' => 1,
            'draft' => ['reply_draft' => $borrador, 'intent' => $intent, 'recommended_plan_id' => null, 'tools_requested' => []],
        ], $this->headers());
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
