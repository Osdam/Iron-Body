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
 *  - (3) `decide` da, junto a cada marcador de `plans_overview.plans`, los
 *    beneficios de SU plan (los de `active_plans`, con su mismo filtro): el
 *    tercero, los del Élite (id 8), no los del Plan Valera (id 3).
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

    /**
     * PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): lo que el servidor le dice ahora al redactor
     * de ese borrador. Afirmaba `mixed` + `length_over_cap` con sus dos notas (251 contra 200); con dos marcadores de la
     * lista el tope aplicable es 320, así que solo queda `mixed`, con las cifras del tope de la lista (179 de 250).
     */
    private const NOTA_DEL_BORRADOR_3 = 'MARCADOR REPETIDO O MEZCLADO: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION. Ya expandidos ocupan 71 caracteres: quedan 179 de 250.';

    /** El borrador 3 alargado por encima del tope de la lista (293 sin expandir, 344 expandido): conserva `mixed` + exceso. */
    private const BORRADOR_3_LARGO = 'Le comento que tenemos el {{PLAN_1}}, el {{PLAN_2}} y el {{PLAN_3}}. Por ejemplo, el {{PLAN_3}} incluye acceso ilimitado, asesoría y seguimiento con entrenador. Los tres le dan acceso total al gimnasio y el acompañamiento del equipo desde el primer día. ¿Qué busca lograr con su entrenamiento?';

    /** Lo que el servidor le dice de ese: las dos notas con el presupuesto del tope de la lista, unidas por un espacio (464 caracteres). */
    private const NOTAS_DEL_BORRADOR_3_LARGO = 'MARCADOR REPETIDO O MEZCLADO: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION. Ya expandidos ocupan 71 caracteres: quedan 179 de 250. DEMASIADO LARGO: mide 344 caracteres y el limite publicado es 320. Reescribelo en unos 250: primero la respuesta, sin relleno. Los marcadores de la lista ya ocupan 71: quedan 179. Si no caben, dos planes; una pregunta corta o ninguna.';

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

        // PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): afirmaba `mixed` + `length_over_cap` para el borrador 3 (251); ahora solo `mixed`, con las cifras del tope de la lista, y `mixed` + exceso se afirma con el borrador 3 alargado (344).
        $r = $this->check($m, $decide['decide_token'], self::BORRADOR_3, 2)->assertOk();
        $this->assertSame([['code' => 'plans_overview', 'detail' => 'mixed', 'issue' => 'relevance']], $r->json('defects'));
        $this->assertSame(self::NOTA_DEL_BORRADOR_3, $r->json('notes'));
        $this->assertTrue($r->json('rewrite'));
        $r = $this->check($m, $decide['decide_token'], self::BORRADOR_3_LARGO, 2)->assertOk();
        $this->assertSame([
            ['code' => 'plans_overview', 'detail' => 'mixed', 'issue' => 'relevance'],
            ['code' => 'length_over_cap', 'detail' => null, 'issue' => 'progressive_disclosure'],
        ], $r->json('defects'));
        $this->assertSame(self::NOTAS_DEL_BORRADOR_3_LARGO, $r->json('notes'));
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
        // PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): afirmaba `policy_length_exceeded` + `policy_plans_overview` y `mixed` + `length_over_cap` en el rechazo; con 251 dentro del tope aplicable (320), solo la lista. El exceso lo conserva la prueba del borrador 3 alargado, abajo.
        $this->assertSame(['policy_plans_overview'], $filaAprobada->metadata['policy_violations']);
        $this->assertMismaTraza(['offered' => self::PO, 'used' => self::PO, 'valid' => false, 'detail' => 'mixed'], $filaAprobada->metadata['plans_overview']);
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
        $this->assertMismaTraza(['pass' => false, 'codes' => ['plans_overview/mixed'], 'length' => 251], $fila->metadata['discarded_draft_check'] ?? null);

        $origen = UltronReplyOrigin::classify($fila->metadata);
        $this->assertSame([UltronReplyOrigin::FALLBACK, UltronReplyOrigin::REASON_CRITIC, UltronReplyOrigin::SERVER_WOULD_REJECT, ['plans_overview/mixed']],
            [$origen['origin'], $origen['fallback_reason'], $origen['server_verdict'], $origen['server_codes']]);
    }

    /**
     * PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): lo que la prueba de arriba afirmaba del
     * exceso (las dos causas en la fila y en el rechazo), con el borrador 3 alargado por encima del tope aplicable (344).
     * No se recorta: se descarta por las dos causas y sale el mismo texto fijo, con el tope aplicado en la traza.
     */
    public function test_el_borrador_3_alargado_por_encima_del_tope_de_lista_conserva_las_dos_causas(): void
    {
        $this->publica('profesional', 200);

        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m);
        $aprobado = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::BORRADOR_3_LARGO, null), self::APROBADO))->assertOk()->json();
        $filaAprobada = $this->ultimaAccion();
        $this->assertSame(self::PRECIOS_200, $aprobado['reply_final']);
        $this->assertSame(['policy_length_exceeded', 'policy_plans_overview'], $filaAprobada->metadata['policy_violations']);
        $this->assertSame([344, 320], [$filaAprobada->metadata['length']['from'], $filaAprobada->metadata['length']['list_cap']]);

        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $m = $this->entrante(self::PANORAMA_CON_PRECIO);
        $decide = $this->decide($m);
        $rechazado = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, self::BORRADOR_3_LARGO, null), self::RECHAZADO_3, self::REGENERACION))->assertOk()->json();
        $fila = $this->ultimaAccion();
        $this->assertSame(self::PRECIOS_200, $rechazado['reply_final']);
        $this->assertMismaTraza(['pass' => false, 'codes' => ['plans_overview/mixed', 'length_over_cap'], 'length' => 344], $fila->metadata['discarded_draft_check'] ?? null);
        $this->assertSame(['plans_overview/mixed', 'length_over_cap'], UltronReplyOrigin::classify($fila->metadata)['server_codes']);
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
        $this->assertMismaTraza(['pass' => true, 'codes' => [], 'length' => 120], $fila->metadata['discarded_draft_check'] ?? null);
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

    // ── (3) Los beneficios de cada plan junto a su marcador ─────────────────

    /**
     * Los beneficios de producción de los tres planes de la lista (leídos por
     * el lead en solo lectura el 22-11, `plans.benefits`): el Valera y el
     * Mensual comparten los primeros; el Élite tiene otros.
     */
    private const BENEFICIOS_DE_PRODUCCION = [
        3 => ['Acceso ilimitado al gimnasio', 'Zona de pesas y maquinaria', 'Asesoría semi personalizada con entrenador de planta', 'Seguimiento en ejecución de ejercicios', 'Valoración inicial'],
        4 => ['Acceso ilimitado al gimnasio', 'Zona de pesas y maquinaria', 'Asesoría semi personalizada con entrenador de planta', 'Seguimiento en ejecución de ejercicios'],
        8 => ['Acceso total al gimnasio', 'Acceso completo a IRONBODY WORKOUT', 'Sesiones funcionales por agenda', 'Entrenamiento intensivo y dinámico', 'Rutinas guiadas por entrenadores', 'Seguimiento continuo durante todo el proceso'],
    ];

    /**
     * CORRECCIÓN (3): `PLAN_3` es una POSICIÓN (aquí, el Élite, id 8), pero
     * `active_plans` y la memoria hablan del «plan 3» (el Valera). En la prueba
     * real el modelo le pegó al `{{PLAN_3}}` los beneficios del Valera. Ahora
     * cada elemento de `plans_overview.plans` lleva los beneficios de SU plan:
     * los mismos, con el mismo filtro, que su entrada de `active_plans` (sin la
     * clave si no tiene ninguno), y sin ninguna cifra. Sin versión del Studio no
     * hay lista (como hoy).
     */
    public function test_decide_da_junto_a_cada_marcador_los_beneficios_de_su_plan(): void
    {
        foreach (self::BENEFICIOS_DE_PRODUCCION as $id => $beneficios) {
            Plan::query()->whereKey($id)->update(['benefits' => json_encode($beneficios)]);
        }
        $this->publica('profesional', 200);
        $decide = $this->decide($this->entrante(self::PANORAMA_CON_PRECIO));
        $planes = $decide['context']['commercial_turn_policy']['plans_overview']['plans'];

        $this->assertSame([[3, '{{PLAN_1}}'], [4, '{{PLAN_2}}'], [8, '{{PLAN_3}}']], array_map(fn (array $p) => [$p['plan_id'], $p['marker']], $planes));
        $this->assertSame(self::BENEFICIOS_DE_PRODUCCION[8], $planes[2]['benefits'], 'el tercero, los del Élite (id 8)');
        $this->assertNotContains('Acceso ilimitado al gimnasio', $planes[2]['benefits'], 'no los del plan id 3');
        $this->assertSame(self::BENEFICIOS_DE_PRODUCCION[3], $planes[0]['benefits']);
        $activos = array_column($decide['context']['active_plans'], null, 'id');
        foreach ($planes as $p) {
            $this->assertSame($activos[$p['plan_id']]['benefits'], $p['benefits'], 'los mismos que en active_plans: '.$p['marker']);
        }
        $this->assertDoesNotMatchRegularExpression('/\d{2,3}[.,]?\d{3}|\$/', json_encode($decide['context']['commercial_turn_policy'], JSON_UNESCAPED_UNICODE), 'ninguna cifra de dinero');

        // Un plan sin beneficios: sin la clave, igual que en `active_plans`.
        $this->nuevaConversacion();
        Plan::query()->whereKey(4)->update(['benefits' => json_encode([])]);
        $decide = $this->decide($this->entrante(self::PANORAMA_CON_PRECIO));
        $this->assertArrayNotHasKey('benefits', $decide['context']['commercial_turn_policy']['plans_overview']['plans'][1]);
        $this->assertArrayNotHasKey('benefits', array_column($decide['context']['active_plans'], null, 'id')[4]);

        // Legacy (sin negociar): ni lista ni beneficios en la política.
        $this->nuevaConversacion();
        $decide = $this->decide($this->entrante(self::PANORAMA_CON_PRECIO), false);
        $this->assertArrayNotHasKey('plans_overview', $decide['context']['commercial_turn_policy']);
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    /** La metadata es `jsonb` en PostgreSQL, que reordena las claves de un objeto: se compara sin su orden (las listas, en el suyo). */
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
