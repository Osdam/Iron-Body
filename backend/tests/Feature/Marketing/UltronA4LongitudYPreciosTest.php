<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Marketing\Ultron\UltronCommitService;
use App\Services\Marketing\Ultron\UltronDecideToken;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * A4 · BLOQUEANTES DEL CIERRE V2: H4-2 (planes y precios) Y H4-1 + H4-3 (el
 * tope de longitud: los respaldos de Laravel y el redactor) (plan del arquitecto, revisión 2,
 * con las decisiones D-1 a D-6 del lead), por el endpoint: `decide` negociado
 * firma lo ofrecido (`sp`, con `n` = el tope publicado) y `commit` lo lee.
 *
 * Los datos son los de producción del 12-11-2026 (plan §2.1 y §3.1): los 13
 * planes vendibles en el orden de `activePlans()` y la línea del horario de
 * 253 caracteres. El reloj, fijado en la tarde de Bogotá (la franja del
 * saludo entra en los textos).
 *
 * LEGACY NO CAMBIA NI UN BYTE: la instantánea `legacy-a4.json` se tomó con el
 * código de `3b917bac`, ANTES de tocar nada, sobre las mismas entradas sin
 * versión aplicada (token sin `sp` y llave de prompts apagada). Se regenera
 * solo a mano con `A4_INSTANTANEA=escribir`, y nunca para que pase.
 */
class UltronA4LongitudYPreciosTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-00000000000c';

    private const INSTANTANEA = __DIR__.'/../../fixtures/ultron-a4/legacy-a4.json';

    /** El catálogo vendible de producción (plan §2.1): id, nombre, precio, días; en el orden de `activePlans()`. */
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

    /** La ficha del horario de producción: título y contenido (la línea entera mide 253). */
    private const HORARIO_TITULO = 'Horario general de atencion';

    private const HORARIO_CONTENIDO = 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases.';

    private const PRECIOS_200 = 'Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?';

    private const PRECIOS_160 = 'Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. ¿Sobre cuál amplío la información?';

    private const HORARIO_200 = 'Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.';

    private const HORARIO_160 = 'Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m.';

    private const HORARIO_ENTERO = 'Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja te queda mejor entrenar?';

    private const PREGUNTA_PRECIOS = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private const PREGUNTA_HORARIO = '¿Cuál es el horario de  atención entre semana?';

    private const CRITICO_CAIDO = ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3, 'issues' => ['relevance'], 'hard_fail' => null];

    /** Un mensaje real por intención, para la matriz (§5.5) y la instantánea. */
    private const ENTRANTES = [
        SalesIntents::PRICING_QUESTION => self::PREGUNTA_PRECIOS,
        SalesIntents::PAYMENT_LINK_REQUEST => 'Mándame el link para pagar',
        SalesIntents::PRICE_OBJECTION => 'Está muy caro',
        SalesIntents::TIME_OBJECTION => 'No tengo tiempo',
        SalesIntents::DELAY_OBJECTION => 'Lo voy a pensar',
        SalesIntents::BEGINNER_FEAR => 'Nunca he entrenado y me da miedo',
        SalesIntents::INSECURITY_BODY => 'Me da pena mi cuerpo',
        SalesIntents::LOCATION_QUESTION => '¿Dónde quedan?',
        SalesIntents::SCHEDULE_QUESTION => self::PREGUNTA_HORARIO,
        SalesIntents::GENERAL_INFO => 'Quiero información del gimnasio',
        SalesIntents::GOAL_FAT_LOSS => 'Quiero bajar de peso',
        SalesIntents::GOAL_MUSCLE_GAIN => 'Quiero ganar masa',
        SalesIntents::GOAL_RECOMPOSITION => 'Quiero bajar grasa y ganar músculo',
        SalesIntents::HIGH_INTENT_CLOSE => 'Quiero inscribirme ya',
        SalesIntents::GREETING => 'Hola buenas tardes',
        SalesIntents::THANKS => 'Gracias por la info',
        SalesIntents::GOODBYE => 'Chao, hablamos luego',
        SalesIntents::NOT_INTERESTED => 'No me interesa',
        SalesIntents::BOT_QUESTION => '¿Eres un bot?',
        SalesIntents::HUMAN_REQUEST => 'Quiero hablar con una persona',
        SalesIntents::COMPLAINT => 'Tengo una queja del servicio',
        SalesIntents::INVOICE_REQUEST => 'Necesito factura',
        SalesIntents::MEDICAL_RISK_ESCALATION => 'Me lesioné la rodilla',
        SalesIntents::FRAUD_OR_PAYMENT_CLAIM => 'Me cobraron dos veces',
        SalesIntents::DO_NOT_CONTACT_REQUEST => 'No me escriban más',
        SalesIntents::SPAM_LOW_QUALITY => 'asdf',
        SalesIntents::UNKNOWN => 'mmm',
    ];

    private MarketingConversation $conversation;

    private int $mundo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Martes 12-11-2026, 16:52 en Bogotá: «buenas tardes».
        Carbon::setTestNow(Carbon::parse('2026-11-12 21:52:00', 'UTC'));
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.ultron.studio_config', 'canary');
        config()->set('marketing.ultron.studio_prompts', 'canary');
        Http::fake();

        $this->catalogoDeProduccion();
        $this->fichaDelHorario(self::HORARIO_CONTENIDO);
        $this->nuevaConversacion();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── §5.8 · El token: `sp.n` solo con un tope distinto de la línea base ──

    public function test_el_token_lleva_n_solo_con_studio_y_tope_distinto_de_320(): void
    {
        $this->publica(200);
        $this->assertSame(200, $this->spDe($this->decide($this->entrante('hola'), true))['n'] ?? null, 'studio con 200');

        $this->nuevaConversacion();
        $this->publica(320);
        $sp = $this->spDe($this->decide($this->entrante('hola'), true));
        $this->assertSame('studio', $sp['m']);
        $this->assertArrayNotHasKey('n', $sp, 'con la línea base no se mide (M1-4)');

        $this->nuevaConversacion();
        config()->set('marketing.ultron.studio_prompts', 'off');
        $sp = $this->spDe($this->decide($this->entrante('hola'), true));
        $this->assertSame(['m', 'r', 'h', 'v', 'c', 'e', 's'], array_keys($sp), 'Legacy: el sp de hoy, sin la clave');

        $this->nuevaConversacion();
        $this->assertNull($this->spDe($this->decide($this->entrante('hola'), false)), 'sin negociar no hay sp');
    }

    public function test_n_viaja_en_el_token_y_no_en_la_respuesta_a_n8n(): void
    {
        $this->publica(200);
        $decide = $this->decide($this->entrante('hola'), true);
        $this->assertSame(['contract', 'mode', 'reason', 'base', 'compiler', 'bundle_hash', 'version', 'content_hash', 'entry', 'seq', 'roles'], array_keys($decide['studio_prompts']));
        $this->assertStringNotContainsString('"n"', json_encode($decide['studio_prompts']));
    }

    public function test_un_n_fuera_de_rango_se_ignora_con_aviso(): void
    {
        $this->publica(200);
        $log = $this->espiaElLog();
        $m = $this->entrante(self::PREGUNTA_HORARIO);
        $decide = $this->decide($m, true);
        $x = self::cargaDelToken($decide['decide_token'])['x'];
        $x['sp']['n'] = 999;
        // El mismo firmador de `decide`: un token válido con un n que el commit no admite.
        $token = app(UltronDecideToken::class)->issue((int) $this->conversation->id, (int) $m->id, $decide['commercial_phase'], $decide['allowed_transitions'], $decide['knowledge_version'], $x)['token'];

        $r = $this->commit($this->payload($m, $token, self::propuesta(SalesIntents::SCHEDULE_QUESTION), self::CRITICO_CAIDO))->assertOk()->json();
        $this->assertSame(self::HORARIO_ENTERO, $r['reply_final'], 'sin tope válido no se mide');
        $log->shouldHaveReceived('warning')->withArgs(fn (string $e, array $c = []) => $e === 'ultron.commit.length_cap_invalid')->once();
    }

    // ── §5.1 · D1: dos rechazos ante «¿qué planes tienen y cuánto cuesta?» ──

    public function test_precios_con_tope_200_nombra_los_tres_de_un_mes_con_su_precio(): void
    {
        $this->publica(200);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS);

        $this->assertSame(self::PRECIOS_200, $r['reply_final']);
        $this->assertSame('SAFE_CURATED_REPLY', $r['fallback_mode']);
        $this->assertLessThanOrEqual(200, UltronReplyFitter::measure($r['reply_final']));
        $this->assertStringNotContainsString('EMPLEADOS', $r['reply_final']);
        $this->assertStringNotContainsString('$1 ', $r['reply_final']);
        $this->assertSame($r['reply_final'], $this->ultimoSaliente()->body);

        $fila = $this->ultimaAccion();
        $this->assertEquals(['cap' => 200, 'action' => 'fit', 'from' => mb_strlen(self::PRECIOS_200), 'to' => mb_strlen(self::PRECIOS_200), 'dropped' => 0], $fila->metadata['length']);
    }

    public function test_precios_con_tope_160_suelta_la_frase_de_las_demas_duraciones(): void
    {
        $this->publica(160);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS);
        $this->assertSame(self::PRECIOS_160, $r['reply_final']);
    }

    public function test_precios_con_studio_sin_tope_sale_entero_y_sin_medida(): void
    {
        $this->publica(320);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS);
        $this->assertSame(self::PRECIOS_200, $r['reply_final'], 'D-1: el respaldo de precios cuelga de studio, no del tope');
        $this->assertArrayNotHasKey('length', $this->ultimaAccion()->metadata, 'sin n no se mide');
    }

    public function test_la_memoria_dice_lo_que_salio_y_la_pregunta_no_pisa_la_oferta(): void
    {
        $this->publica(200);
        $this->criticoCaido(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS);

        $memoria = app(ConversationMemoryService::class)->load($this->conversation->fresh());
        foreach ([3, 4, 8] as $id) {
            $this->assertTrue($memoria->priceDeliveredFor($id), "precio entregado del plan {$id}");
        }
        $this->assertFalse($memoria->priceDeliveredFor(16));
        $this->assertSame([3, 4, 8], array_values(array_intersect($memoria->plansDiscussed(), [3, 4, 8, 14, 16])));
        // El detector real (`offerKindOf`): «¿Sobre cuál amplío…?» no es una oferta.
        $this->assertNull($memoria->get('last_agent_offer'));
        $this->assertSame('¿Sobre cuál amplío la información?', $memoria->get('last_agent_question')['text'] ?? null);
    }

    // ── §5.2 · D1: el guard inspecciona el esqueleto ────────────────────────

    public function test_un_plan_con_gratis_en_el_nombre_no_tumba_el_respaldo_de_precios(): void
    {
        Plan::query()->whereKey(4)->update(['name' => 'Mensual gratis la primera semana']);
        $this->publica(200);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS);

        $this->assertStringContainsString('Mensual gratis la primera semana, $80.000 COP', $r['reply_final']);
        // Mutación documentada: si el guard mirara el texto final, el nombre lo tumbaría.
        $this->assertFalse(app(OutboundContentGuard::class)->inspect($r['reply_final'], MarketingMessage::SENDER_AI, false)['safe']);
    }

    // ── §5.3 · D1: el catálogo ──────────────────────────────────────────────

    public function test_sin_planes_de_un_mes_nombra_los_tres_primeros_con_su_duracion(): void
    {
        Plan::query()->whereBetween('duration_days', [28, 31])->update(['sellable' => false]);
        $this->publica(200);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS);
        // F5 (I-4): «1 día», en singular (antes «1 días», que esta prueba fijaba como correcto).
        $this->assertSame('Claro. Nuestros planes: PLAN DÍA (1 día), $10.000 COP; Plan Semana (7 días), $45.000 COP; Trimestre (tres meses), $210.000 COP. También hay planes de varios meses. ¿Sobre cuál amplío la información?', $r['reply_final']);
    }

    public function test_el_plan_que_nombra_la_persona_va_primero(): void
    {
        $this->publica(200);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Y el Élite cuánto cuesta? ¿Qué planes tienen?');
        $this->assertStringStartsWith('Claro. Planes de un mes: Élite, $180.000 COP; Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP.', $r['reply_final']);
    }

    public function test_la_regla_depende_del_orden_del_catalogo(): void
    {
        // Documenta (plan §2.2 c): si PROMO X4 se mueve delante, sale él.
        Plan::query()->whereKey(14)->update(['sort_order' => 0]);
        $this->publica(200);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS);
        $this->assertStringStartsWith('Claro. Planes de un mes: PROMO X4, $75.000 COP; Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP.', $r['reply_final']);
    }

    // ── §5.4 · El horario ───────────────────────────────────────────────────

    public function test_horario_entre_semana_a_200_y_a_160(): void
    {
        $this->publica(200);
        $r = $this->criticoCaido(SalesIntents::SCHEDULE_QUESTION, self::PREGUNTA_HORARIO);
        $this->assertSame(self::HORARIO_200, $r['reply_final']);
        $this->assertSame(186, UltronReplyFitter::measure($r['reply_final']));
        $this->assertEquals(['cap' => 200, 'action' => 'fitted', 'from' => 300, 'to' => 186, 'dropped' => 2], $this->ultimaAccion()->metadata['length']);

        $this->nuevaConversacion();
        $this->publica(160);
        $r = $this->criticoCaido(SalesIntents::SCHEDULE_QUESTION, self::PREGUNTA_HORARIO);
        $this->assertSame(self::HORARIO_160, $r['reply_final']);
    }

    public function test_horario_los_sabados_a_160(): void
    {
        $this->publica(160);
        $r = $this->criticoCaido(SalesIntents::SCHEDULE_QUESTION, '¿y los sábados?');
        $this->assertStringContainsString('Sabados de 8:00 a. m. a 2:00 p. m.', $r['reply_final']);
        $this->assertLessThanOrEqual(160, UltronReplyFitter::measure($r['reply_final']));
    }

    public function test_horario_con_una_ficha_sin_dias_aplica_la_regla_general(): void
    {
        MarketingKnowledgeItem::query()->where('category', 'schedule')->delete();
        $this->fichaDelHorario('Abrimos todo el año con atención continua en recepción y entrenadores en sala durante toda la jornada de apertura del gimnasio para quien quiera venir. Te esperamos con gusto cuando quieras.');
        $this->nuevaConversacion();
        $this->publica(160);
        $r = $this->criticoCaido(SalesIntents::SCHEDULE_QUESTION, self::PREGUNTA_HORARIO);
        $this->assertLessThanOrEqual(160, UltronReplyFitter::measure($r['reply_final']));
        $this->assertStringStartsWith('Claro.', $r['reply_final']);
    }

    // ── Ajuste 2 del lead · La ficha por piezas se llena en orden ──────────

    public function test_informacion_general_a_200_y_160_conserva_la_identidad(): void
    {
        $this->fichaDeProduccion();
        foreach ([200, 160] as $tope) {
            $this->nuevaConversacion();
            $this->publica($tope);
            $r = $this->criticoCaido(SalesIntents::GENERAL_INFO, 'Quiero información del gimnasio');
            $this->assertSame('Con gusto te cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?', $r['reply_final'], (string) $tope);
        }

        // Sin la versión aplicada, la ficha entera, como hoy (sin el teléfono: `sinTelefonos`).
        $this->nuevaConversacion();
        $r = $this->criticoCaido(SalesIntents::GENERAL_INFO, 'Quiero información del gimnasio', false);
        $this->assertSame('Con gusto te cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. Iron Body Neiva está ubicado en Cl. 24 Sur #33-53, Neiva, Huila. '.self::HORARIO_CONTENIDO.' ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?', $r['reply_final']);
    }

    // ── §5.5 · El tope para todas las intenciones ───────────────────────────

    /** @return array<string, array{string, int}> */
    public static function intencionesPorTope(): array
    {
        $casos = [];
        foreach (SalesAgentDecisionSchema::INTENTS as $intent) {
            foreach ([160, 200] as $tope) {
                $casos["{$intent} a {$tope}"] = [$intent, $tope];
            }
        }

        return $casos;
    }

    #[DataProvider('intencionesPorTope')]
    public function test_ningun_respaldo_supera_el_tope(string $intent, int $tope): void
    {
        $this->publica($tope);
        $r = $this->criticoCaido($intent, self::ENTRANTES[$intent]);

        if ($intent === SalesIntents::DO_NOT_CONTACT_REQUEST) {
            $this->assertNull($r['reply_final'], 'quien pide que no le escriban no recibe nada');

            return;
        }
        $texto = (string) $r['reply_final'];
        $this->assertNotSame('', trim($texto), 'nunca un turno mudo');
        $this->assertLessThanOrEqual($tope, UltronReplyFitter::measure($texto), $texto);
        $this->assertSame($texto, $this->ultimoSaliente()->body, 'lo medido es lo que salió');
        if ($intent !== SalesIntents::PRICING_QUESTION) {
            // El de precios pasa el guard por su esqueleto (§5.2); el resto, tal cual sale.
            $this->assertTrue(app(OutboundContentGuard::class)->inspect($texto, MarketingMessage::SENDER_AI, false)['safe'], $texto);
        }
    }

    // ── §5.6 y D-4 · El borrador aprobado que se pasa del tope ──────────────

    public function test_borrador_aprobado_que_se_pasa_se_recorta_desde_el_final(): void
    {
        $this->publica(200);
        $borrador = 'Te cuento: el Élite tiene un costo mensual de {{PLAN_PRICE}} e incluye acceso total al gimnasio. Además tienes sesiones funcionales, rutinas guiadas y seguimiento continuo con tu entrenador. ¿Quieres que te cuente cómo empezar?';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Élite?', $borrador, 8);

        $esperado = 'Te cuento: el Élite tiene un costo mensual de $180.000 COP e incluye acceso total al gimnasio. Además tienes sesiones funcionales, rutinas guiadas y seguimiento continuo con tu entrenador.';
        $this->assertSame($esperado, $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertEquals(['cap' => 200, 'action' => 'trimmed', 'from' => UltronReplyFitter::measure($esperado.' ¿Quieres que te cuente cómo empezar?'), 'to' => UltronReplyFitter::measure($esperado), 'dropped' => 1], $fila->metadata['length']);
        $this->assertArrayNotHasKey('policy_violations', $fila->metadata);
    }

    /**
     * F1: la frase del precio era la última y no es una pregunta, así que no
     * se recorta nada (antes se soltaba la del medio, la que describe el
     * plan): se descarta y sale el respaldo de precios con el Élite primero.
     */
    public function test_un_borrador_que_cierra_con_el_precio_no_se_recorta_se_descarta(): void
    {
        $this->publica(160);
        $borrador = 'Claro, te cuento del Élite. Incluye acceso total al gimnasio, sesiones funcionales, rutinas guiadas y seguimiento continuo con guía nutricional. Su valor es {{PLAN_PRICE}} al mes.';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Élite?', $borrador, 8);
        $this->assertSame('Claro. Planes de un mes: Élite, $180.000 COP; Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP. ¿Sobre cuál amplío la información?', $r['reply_final']);
        $this->assertContains(UltronCommitService::VIOLACION_LONGITUD, $this->ultimaAccion()->metadata['policy_violations']);
    }

    /**
     * Decisión del lead tras F1: la pregunta final que lleva el único precio
     * del borrador no se suelta (antes de esto salía «El Plan Mensual
     * incluye…» sin ninguna cifra a quien preguntó cuánto cuesta): se descarta
     * y sale el respaldo de precios, con el plan nombrado primero.
     */
    public function test_la_pregunta_final_con_el_unico_precio_no_se_suelta(): void
    {
        $this->publica(200);
        $borrador = 'El Plan Mensual incluye acceso completo al gimnasio, rutinas guiadas desde la app y una valoración inicial con un entrenador para que empieces con buen pie desde el primer día. ¿Te interesa el Plan Mensual por {{PLAN_PRICE}} al mes?';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Plan Mensual?', $borrador, 4);

        $this->assertSame('Claro. Planes de un mes: Plan Mensual, $80.000 COP; Plan Valera, $65.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?', $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertContains(UltronCommitService::VIOLACION_LONGITUD, $fila->metadata['policy_violations']);
        $this->assertEquals(['cap' => 200, 'action' => 'replaced', 'from' => 229, 'to' => UltronReplyFitter::measure($r['reply_final']), 'dropped' => 0], $fila->metadata['length']);
    }

    /** F1: la pregunta final que va justo antes del bloque de enlaces se suelta; los enlaces se quedan con su salto. */
    public function test_los_enlaces_de_la_app_no_cuentan_y_su_linea_no_se_suelta(): void
    {
        $this->publica(160);
        $borrador = 'Claro, esta es la app de Iron Body para descargarla en tu celular. Desde ahí pagas tu plan con Nequi o PSE. ¿Te ayudo con algo más?';
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, 'Pásame la app', $borrador, null, [SalesIntents::TOOL_APP_LINKS_SEND]);

        $this->assertSame("Claro, esta es la app de Iron Body para descargarla en tu celular. Desde ahí pagas tu plan con Nequi o PSE.\n\n".MobileAppCatalog::linksInline(), $r['reply_final'], 'sin la pregunta; los enlaces salen, con su salto');
        $this->assertLessThanOrEqual(160, UltronReplyFitter::measure($r['reply_final']));
        $this->assertGreaterThan(160, mb_strlen($r['reply_final']), 'con las URL contadas no cabría: no cuentan');
        $this->assertContains(SalesIntents::TOOL_APP_LINKS_SEND, $r['applied']['tools_executed'], 'y constan como enviados');
        $this->assertEquals(['cap' => 160, 'action' => 'trimmed', 'from' => 161, 'to' => 137, 'dropped' => 1], $this->ultimaAccion()->metadata['length']);

        // Si sin la pregunta tampoco cabe (164), no se suelta «Desde ahí…»: se descarta, y los enlaces no constan como enviados.
        $this->nuevaConversacion();
        $this->publica(160);
        $largo = 'Claro, esta es la app de Iron Body para descargarla en tu celular. Desde ahí creas tu cuenta y pagas tu plan con Nequi, PSE o tarjeta. ¿Te ayudo con algo más?';
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, 'Pásame la app', $largo, null, [SalesIntents::TOOL_APP_LINKS_SEND]);
        $this->assertContains(UltronCommitService::VIOLACION_LONGITUD, $this->ultimaAccion()->metadata['policy_violations']);
        $this->assertStringNotContainsString('Android:', (string) $r['reply_final']);
        $this->assertNotContains(SalesIntents::TOOL_APP_LINKS_SEND, $r['applied']['tools_executed']);
    }

    public function test_si_no_se_puede_recortar_se_descarta_y_sale_el_respaldo_ajustado(): void
    {
        $this->publica(200);
        $borrador = 'Te confirmo que el plan Élite, que es el que mejor te encaja por todo lo que me has contado hasta ahora, tiene un costo mensual de {{PLAN_PRICE}} e incluye acceso total al gimnasio, sesiones funcionales, rutinas guiadas y seguimiento continuo.';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS, $borrador, 8);

        $this->assertSame(self::PRECIOS_200, $r['reply_final'], 'el respaldo de precios, por la rama de la política');
        $fila = $this->ultimaAccion();
        $this->assertContains('policy_length_exceeded', $fila->metadata['policy_violations']);
        $this->assertStringStartsWith('Te confirmo que el plan Élite', $fila->metadata['policy_discarded_draft']);
        // m-3: la traza dice que el texto se SUSTITUYÓ, con la medida del borrador y la de lo que salió.
        $this->assertEquals(['cap' => 200, 'action' => 'replaced', 'from' => UltronReplyFitter::measure(str_replace('{{PLAN_PRICE}}', '$180.000 COP', $borrador)), 'to' => UltronReplyFitter::measure(self::PRECIOS_200), 'dropped' => 0], $fila->metadata['length']);
        // F3: el turno no cotiza el plan del modelo, enseña varios.
        $this->assertNull($r['price_source']);
    }

    public function test_el_precio_verificado_tiene_prioridad_sobre_el_cuerpo(): void
    {
        $this->publica(160);
        $borrador = 'Sí, el Plan Mensual incluye acceso completo al gimnasio y rutinas guiadas desde la app. Además puedes entrenar en cualquier franja del horario de apertura. ¿Te ayudo con algo más?';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Seguro que el Plan Mensual cuesta eso?', $borrador, 4);

        // F1: el recorte suelta la pregunta (179 → 155); para el precio ya no se suelta «Además…»: sale la frase del precio sola.
        $this->assertSame('El precio actual del Plan Mensual es $80.000 COP.', $r['reply_final']);
        $this->assertEquals(['cap' => 160, 'action' => 'trimmed', 'from' => 179, 'to' => 49, 'dropped' => 3], $this->ultimaAccion()->metadata['length']);

        // Si soltar la pregunta del cuerpo basta, el cuerpo se queda con el precio detrás.
        $this->nuevaConversacion();
        $this->publica(160);
        $corto = 'Sí, el Plan Mensual incluye acceso completo al gimnasio y rutinas guiadas desde la app. ¿Te ayudo con algo más?';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Seguro que el Plan Mensual cuesta eso?', $corto, 4);
        $this->assertSame('Sí, el Plan Mensual incluye acceso completo al gimnasio y rutinas guiadas desde la app. El precio actual del Plan Mensual es $80.000 COP.', $r['reply_final']);
        $this->assertEquals(['cap' => 160, 'action' => 'trimmed', 'from' => 111, 'to' => 137, 'dropped' => 1], $this->ultimaAccion()->metadata['length']);
    }

    /** m-8: si ni la frase del precio verificado cabe sola, el cuerpo sale como estaba y queda un aviso. */
    public function test_si_la_frase_del_precio_no_cabe_ni_sola_el_cuerpo_sale_como_estaba(): void
    {
        Plan::query()->whereKey(4)->update(['name' => 'Plan Mensual Integral de Acondicionamiento Físico con Acceso Completo a la Sede y Rutinas Guiadas desde la Aplicación Oficial de Iron Body Neiva']);
        $this->publica(160);
        $log = $this->espiaElLog();
        $cuerpo = 'Sí, ese plan incluye acceso completo al gimnasio y rutinas guiadas desde la app. ¿Te ayudo con algo más?';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Seguro que cuesta eso?', $cuerpo, 4);

        $this->assertSame($cuerpo, $r['reply_final']);
        $this->assertArrayNotHasKey('overflow', $this->ultimaAccion()->metadata['length']);
        $log->shouldHaveReceived('warning')->withArgs(fn (string $e, array $c = []) => $e === 'ultron.commit.verified_price_skipped_cap')->once();
        $log->shouldNotHaveReceived('error', fn (string $e, array $c = []) => $e === 'ultron.commit.length_cap_overflow');
    }

    public function test_la_frase_que_retoma_la_visita_solo_se_anade_si_cabe(): void
    {
        $borrador = 'Estamos en la Calle 24 Sur #33-53, en Neiva, Huila, muy cerca del centro y con parqueadero para motos y carros.';
        foreach ([200 => true, 160 => false] as $tope => $cabe) {
            $this->nuevaConversacion();
            $this->publica($tope);
            app(ConversationMemoryService::class)->recordCourtesyCollecting($this->conversation, ['date' => null, 'time' => null]);
            $r = $this->aprobado(SalesIntents::LOCATION_QUESTION, '¿Dónde quedan?', $borrador, null);

            $this->assertLessThanOrEqual($tope, UltronReplyFitter::measure($r['reply_final']), (string) $tope);
            if ($cabe) {
                $this->assertStringStartsWith($borrador.' Y sobre tu visita: ', $r['reply_final'], 'a 200 cabe y se retoma');
            } else {
                $this->assertSame($borrador, $r['reply_final'], 'a 160 no cabe: sale la respuesta sin la cola');
            }
        }
    }

    // ── Correcciones tras las revisiones de seguridad y del revisor (F1–F7) ──

    /**
     * B-1 (informe del revisor): los cinco borradores, a 160 y a 200, por el
     * endpoint. O sale el borrador sin su pregunta, o se descarta y sale el
     * respaldo ajustado de la intención; NUNCA la apertura sola.
     *
     * @return array<string, array{string, string, string, bool, int, ?string}>
     */
    public static function borradoresDeB1(): array
    {
        $miedo = 'Muchas personas que llegan por primera vez sienten exactamente lo mismo, por eso en Iron Body te acompañamos desde el primer día con una rutina adaptada a tu nivel y un entrenador pendiente de ti. ¿Qué es lo que más te preocupa?';
        $horario = 'Claro. El horario de atención entre semana es de lunes a viernes de 5:00 a. m. a 10:00 p. m., y los sábados y domingos abrimos de 8:00 a. m. a 2:00 p. m. para que entrenes cuando mejor te quede. ¿Te sirve?';
        $borradores = [
            'beginner_fear' => [SalesIntents::BEGINNER_FEAR, 'Nunca he entrenado y me da miedo', 'Te entiendo. '.$miedo, false],
            'time_objection' => [SalesIntents::TIME_OBJECTION, 'No tengo tiempo', 'Te entiendo perfectamente. Con tres sesiones de cuarenta y cinco minutos a la semana en la franja que mejor te quede, ya puedes ver avances reales en tu proceso sin tener que cambiar toda tu rutina diaria. ¿Qué días tienes más libres?', false],
            'schedule_question' => [SalesIntents::SCHEDULE_QUESTION, '¿Cuál es el horario de atención entre semana?', $horario, false],
            'goal_fat_loss' => [SalesIntents::GOAL_FAT_LOSS, 'Quiero bajar de peso', 'Listo. Para bajar de peso lo que mejor funciona es combinar entrenamiento de fuerza con sesiones funcionales tres o cuatro veces por semana, y en Iron Body te armamos la rutina según tu nivel y tu disponibilidad. ¿Ya vienes entrenando?', false],
            'beginner_fear tras un saludo (R8)' => [SalesIntents::BEGINNER_FEAR, 'Nunca he entrenado y me da miedo', '¡Hola de nuevo! '.$miedo, true],
        ];
        $casos = [];
        foreach ($borradores as $nombre => $b) {
            foreach ([160, 200] as $tope) {
                // Solo el horario a 200 cabe sin su pregunta (194): sale recortado. El resto se descarta.
                $recortado = $b[0] === SalesIntents::SCHEDULE_QUESTION && $tope === 200 ? mb_substr($horario, 0, -mb_strlen(' ¿Te sirve?')) : null;
                $casos["{$nombre} a {$tope}"] = [...$b, $tope, $recortado];
            }
        }

        return $casos;
    }

    #[DataProvider('borradoresDeB1')]
    public function test_b1_el_recorte_nunca_deja_solo_la_apertura(string $intent, string $entrante, string $borrador, bool $trasSaludo, int $tope, ?string $recortado): void
    {
        $this->publica($tope);
        if ($trasSaludo) {
            $this->aprobado(SalesIntents::GREETING, 'Hola', 'Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos ayudar hoy?', null);
        }
        $r = $this->aprobado($intent, $entrante, $borrador, null);
        $texto = (string) $r['reply_final'];
        $fila = $this->ultimaAccion();

        $this->assertNotContains($texto, ['Te entiendo.', 'Te entiendo perfectamente.', 'Listo.', 'Claro.', '¡Hola de nuevo!'], 'nunca la apertura sola');
        $this->assertLessThanOrEqual($tope, UltronReplyFitter::measure($texto), $texto);
        $this->assertSame($texto, $this->ultimoSaliente()->body);
        if ($recortado !== null) {
            $this->assertSame($recortado, $texto, 'el borrador, sin su pregunta');
            // assertEquals: `jsonb` (PostgreSQL) no conserva el orden de las claves.
            $this->assertEquals(['cap' => $tope, 'action' => 'trimmed', 'from' => UltronReplyFitter::measure($borrador), 'to' => UltronReplyFitter::measure($recortado), 'dropped' => 1], $fila->metadata['length']);
            $this->assertArrayNotHasKey('policy_violations', $fila->metadata);

            return;
        }
        $this->assertContains(UltronCommitService::VIOLACION_LONGITUD, $fila->metadata['policy_violations'], 'se descarta');
        $this->assertStringStartsWith(mb_substr($borrador, 0, 40), $fila->metadata['policy_discarded_draft']);
        $this->assertSame('replaced', $fila->metadata['length']['action']);
        if ($intent === SalesIntents::SCHEDULE_QUESTION) {
            $this->assertSame(self::HORARIO_160, $texto, 'el respaldo del horario, ajustado');
        } else {
            $this->assertContains($texto, (new SalesConversationReplyService)->lastResorts($intent), 'el respaldo de la intención');
        }
    }

    /**
     * S-1 (informe de seguridad): la frase que pone la condición va al final y
     * no se suelta: el borrador se descarta. El ejemplo literal del informe
     * («Te reservo el cupo…») no llega a esta guarda por el endpoint: lo para
     * antes la autoridad de promesas («te reservo», 422); está en la prueba
     * pura del ajustador. Aquí, la misma forma con una condición de horario.
     */
    public function test_s1_un_borrador_con_la_condicion_al_final_se_descarta_no_se_recorta(): void
    {
        $afirmacion = 'Claro, puedes venir a conocer la sede cuando quieras.';
        $borrador = $afirmacion.' Ojo: los domingos y festivos solo abrimos de 8:00 a. m. a 2:00 p. m., así que si vienes esos días te conviene llegar temprano para recorrerla con calma.';
        foreach ([160, 200] as $tope) {
            $this->nuevaConversacion();
            $this->publica($tope);
            $r = $this->aprobado(SalesIntents::LOCATION_QUESTION, '¿Puedo ir a conocer la sede?', $borrador, null);

            $this->assertNotSame($afirmacion, $r['reply_final'], "{$tope}: la afirmación sin su condición no sale");
            $this->assertContains(UltronCommitService::VIOLACION_LONGITUD, $this->ultimaAccion()->metadata['policy_violations'], (string) $tope);
            $this->assertContains($r['reply_final'], (new SalesConversationReplyService)->lastResorts(SalesIntents::LOCATION_QUESTION), (string) $tope);
        }
    }

    /** F4 (I-3, escenario R2): una lista numerada no se parte tras el número; nunca sale un «3.» colgando. */
    public function test_r2_una_lista_numerada_sale_entera_o_no_sale(): void
    {
        $lista = "Te cuento lo que incluye el Élite:\n1. Acceso total al gimnasio y a todas las máquinas.\n2. Clases grupales funcionales todos los días.\n3. Seguimiento con IRON IA en la app.";
        $borrador = $lista.' ¿Te animas a empezar esta semana?';

        $this->publica(200);
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, '¿Qué incluye el Élite?', $borrador, 8);
        $this->assertSame($lista, $r['reply_final'], 'a 200, la lista entera sin la pregunta');

        $this->nuevaConversacion();
        $this->publica(160);
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, '¿Qué incluye el Élite?', $borrador, 8);
        $this->assertDoesNotMatchRegularExpression('/(^|\s)\d{1,2}\.$/u', (string) $r['reply_final'], 'antes salía «…\n3.»');
        $this->assertContains(UltronCommitService::VIOLACION_LONGITUD, $this->ultimaAccion()->metadata['policy_violations'], 'a 160 sin la pregunta (171) no cabe: se descarta');
        $this->assertLessThanOrEqual(160, UltronReplyFitter::measure((string) $r['reply_final']));
    }

    /**
     * F2 (I-2, escenario R7): la cortesía se ejecutó (la cita existe) y el
     * borrador se descarta por longitud: la persona lee el día y la hora que
     * quedaron registrados, con el acta que Laravel escribe desde la fila.
     */
    public function test_r7_la_constancia_de_una_visita_registrada_no_se_pierde(): void
    {
        $borrador = 'Listo, ya te dejo anotado. En la primera visita te muestran las máquinas, te arman una rutina de prueba y resuelven tus dudas con un entrenador. Queda registrada tu solicitud de visita para el sábado a las 10:00 a. m. y el equipo la revisará.';
        $acta = 'Dejé registrada tu solicitud de cortesía para el sábado 14 de noviembre a las 10:00 a. m. El equipo de Iron Body la revisará para tener todo preparado.';
        foreach ([200, 160] as $tope) {
            $this->nuevaConversacion();
            $this->publica($tope);
            $r = $this->conCortesia($borrador, true);

            $this->assertSame($acta, $r['reply_final'], "{$tope}: el acta de la fila");
            $this->assertContains(SalesIntents::TOOL_COURTESY_REQUEST, $r['applied']['tools_executed']);
            $fila = $this->ultimaAccion();
            $this->assertContains(UltronCommitService::VIOLACION_LONGITUD, $fila->metadata['policy_violations']);
            $this->assertSame('replaced', $fila->metadata['length']['action']);
            // La memoria dice lo que salió: la solicitud registrada, ninguna pregunta abierta ni plan cotizado.
            $memoria = app(ConversationMemoryService::class)->load($this->conversation->fresh());
            $this->assertSame('requested', $memoria->get('courtesy_request')['status'] ?? null);
            $this->assertNull($memoria->get('last_agent_question')['text'] ?? null);
            $this->assertNull($r['price_source']);
        }

        // Sin la versión aplicada no cambia nada: el borrador sale entero.
        $this->nuevaConversacion();
        $r = $this->conCortesia($borrador, false);
        $this->assertSame($borrador, $r['reply_final']);
    }

    /** F3 (I-1, escenario R1): el plan del modelo no está en la lista; ni la memoria ni `price_source` lo dan por cotizado. */
    public function test_r1_la_memoria_no_anota_el_precio_del_plan_del_modelo_si_no_salio(): void
    {
        $this->publica(200);
        $borrador = 'Te confirmo que el Trimestre, que es el que mejor te encaja por todo lo que me has contado hasta ahora, tiene un costo de {{PLAN_PRICE}} e incluye acceso total al gimnasio, sesiones funcionales, rutinas guiadas y seguimiento continuo.';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS, $borrador, 5);

        $this->assertSame(self::PRECIOS_200, $r['reply_final']);
        $this->assertNull($r['price_source'], 'antes: {plan_id: 5, price: 210000}');
        $memoria = app(ConversationMemoryService::class)->load($this->conversation->fresh());
        $this->assertFalse($memoria->priceDeliveredFor(5), 'el precio del Trimestre no salió');
        foreach ([3, 4, 8] as $id) {
            $this->assertTrue($memoria->priceDeliveredFor($id), "el del plan {$id} sí");
        }
    }

    /**
     * F5 (I-4, escenario R3) y la mutación M10: con un plan nombrado que no es
     * de un mes la cabecera es «Nuestros planes» con duraciones, nunca «Planes
     * de un mes», y la duración de un día va en singular.
     */
    public function test_un_plan_nombrado_que_no_es_de_un_mes_no_va_bajo_planes_de_un_mes(): void
    {
        foreach ([200, 160] as $tope) {
            $this->nuevaConversacion();
            $this->publica($tope);
            $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Cuánto vale el plan día? ¿Qué planes tienen?');
            $this->assertSame('Claro. Nuestros planes: PLAN DÍA (1 día), $10.000 COP; Plan Valera (un mes), $65.000 COP; Plan Mensual (un mes), $80.000 COP. ¿Sobre cuál amplío la información?', $r['reply_final'], (string) $tope);
        }

        $this->nuevaConversacion();
        $this->publica(200);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Y el Trimestre cuánto cuesta? ¿Qué planes tienen?');
        $this->assertSame('Claro. Nuestros planes: Trimestre (tres meses), $210.000 COP; Plan Valera (un mes), $65.000 COP; Plan Mensual (un mes), $80.000 COP. ¿Sobre cuál amplío la información?', $r['reply_final']);
        $this->assertStringNotContainsString('Planes de un mes', $r['reply_final']);
    }

    /** Mutación M12: en la rama de la política, los precios cuelgan de la versión aplicada, no del tope. */
    public function test_precios_en_la_rama_de_la_politica_con_la_version_y_sin_tope(): void
    {
        $this->publica(320);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS, '¿Qué te gustaría lograr con tu entrenamiento?', null);

        $this->assertSame(self::PRECIOS_200, $r['reply_final'], 'sin tope, el respaldo de precios entero');
        $fila = $this->ultimaAccion();
        $this->assertNotContains(UltronCommitService::VIOLACION_LONGITUD, $fila->metadata['policy_violations']);
        $this->assertArrayNotHasKey('length', $fila->metadata, 'sin n no se mide');
        $this->assertNull($r['price_source']);
    }

    /** F6: el respaldo de precios no nombra un plan rechazado, salvo el que la persona acaba de nombrar; en los dos caminos. */
    public function test_el_respaldo_de_precios_no_nombra_un_plan_rechazado(): void
    {
        $sinValera = 'Claro. Planes de un mes: Plan Mensual, $80.000 COP; Élite, $180.000 COP; PROMO X4, $75.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?';

        // El revisor caído (`safeFallback`).
        $this->publica(200);
        app(ConversationMemoryService::class)->recordPlanRejected($this->conversation, 3);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS);
        $this->assertSame($sinValera, $r['reply_final']);

        // La rama de la política: el borrador se descarta por longitud.
        $this->nuevaConversacion();
        $this->publica(200);
        app(ConversationMemoryService::class)->recordPlanRejected($this->conversation, 3);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS, 'Te confirmo que el plan Élite, que es el que mejor te encaja por todo lo que me has contado hasta ahora, tiene un costo mensual de {{PLAN_PRICE}} e incluye acceso total al gimnasio, sesiones funcionales, rutinas guiadas y seguimiento continuo.', 8);
        $this->assertSame($sinValera, $r['reply_final']);
        $this->assertFalse(app(ConversationMemoryService::class)->load($this->conversation->fresh())->priceDeliveredFor(3));

        // Si la persona vuelve a nombrarlo, lo reabre (la política lo saca de los rechazados): va primero.
        $this->nuevaConversacion();
        $this->publica(200);
        app(ConversationMemoryService::class)->recordPlanRejected($this->conversation, 3);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Y el Plan Valera cuánto cuesta? ¿Qué planes tienen?');
        $this->assertStringStartsWith('Claro. Planes de un mes: Plan Valera, $65.000 COP;', $r['reply_final']);
    }

    /** m-5: en «También hay…», 1 día → por día; de 5 a 9 → por semana; 32 o más → de varios meses; el resto no se menciona. */
    public function test_las_demas_duraciones_solo_se_nombran_si_se_describen_bien(): void
    {
        Plan::query()->whereKey(2)->update(['duration_days' => 15]);
        $this->publica(200);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS);
        $this->assertSame('Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día y de varios meses. ¿Sobre cuál amplío la información?', $r['reply_final'], 'un plan de 15 días no es «por semana»');
    }

    /** m-3 (escenario R5): en la rama de la política, si el texto se sustituye, `metadata.length` lo dice. */
    public function test_la_traza_dice_que_el_texto_se_sustituyo(): void
    {
        $this->publica(160);
        $borrador = 'Bienvenido a Iron Body Neiva, un centro serio y con ambiente profesional. ¿Le cuento más sobre cómo avanzar con su inscripción o el pago?';
        $r = $this->aprobado(SalesIntents::GREETING, 'Hola buenas tardes', $borrador, null);

        $fila = $this->ultimaAccion();
        $this->assertNotSame($borrador, $r['reply_final']);
        $this->assertNotEmpty($fila->metadata['policy_violations']);
        $this->assertEquals(['cap' => 160, 'action' => 'replaced', 'from' => 137, 'to' => UltronReplyFitter::measure($r['reply_final']), 'dropped' => 0], $fila->metadata['length'], 'antes: action fit, como si hubiera salido el borrador');
    }

    // ── D-5 · Un único punto de control antes de enviar ────────────────────

    public function test_todo_envio_del_commit_pasa_por_el_punto_de_control_salvo_el_del_enlace_de_pago(): void
    {
        $fuente = (string) file_get_contents(app_path('Services/Marketing/Ultron/UltronCommitService.php'));
        $this->assertSame(4, substr_count($fuente, '->dispatchWhatsapp('), 'inventario: cuatro envíos');
        // Tres: el texto que se envía es, en la sentencia anterior, lo que devolvió el punto de control.
        $this->assertSame(3, preg_match_all('/\$(\w+) = \$this->antesDeEnviar\([^;]*\);\s*\n\s*\$send = \$this->dispatcher->dispatchWhatsapp\(\s*[^,]+,\s*[^,]+,\s*\$\1,/u', $fuente));
        // El cuarto, el mensaje aparte del enlace de pago, queda fuera del tope (lleva importe y enlace).
        $this->assertSame(1, preg_match_all('/\$send = \$this->dispatcher->dispatchWhatsapp\(\$lead, \$conversation->channel, \$body, \[\s*\n\s*\'kind\' => \'payment_link\'/u', $fuente));
    }

    public function test_el_punto_de_control_cambia_lo_que_no_cabe_por_un_ultimo_recurso_y_lo_anota(): void
    {
        $this->publica(160);
        $log = $this->espiaElLog();
        $this->criticoCaido(SalesIntents::GREETING, 'hola');
        $fila = $this->ultimaAccion();

        $control = new ReflectionMethod(UltronCommitService::class, 'antesDeEnviar');
        $sale = $control->invoke(app(UltronCommitService::class), $this->conversation, $fila, str_repeat('Texto que no cabe. ', 12), SalesIntents::UNKNOWN, ['studio' => true, 'tope' => 160], ['action' => 'fit', 'from' => 228, 'dropped' => 0]);

        $this->assertLessThanOrEqual(160, UltronReplyFitter::measure($sale));
        $this->assertContains($sale, (new SalesConversationReplyService)->lastResorts(SalesIntents::UNKNOWN));
        $this->assertTrue($fila->fresh()->metadata['length']['overflow']);
        $log->shouldHaveReceived('error')->withArgs(fn (string $e, array $c = []) => $e === 'ultron.commit.length_cap_overflow')->once();
    }

    // ── §5.7 · Legacy: las mismas entradas, la salida de hoy ────────────────

    /** @return array<string, array{string}> */
    public static function modosLegacy(): array
    {
        return ['token sin sp' => ['sin_sp'], 'llave de prompts apagada' => ['llave_off']];
    }

    #[DataProvider('modosLegacy')]
    public function test_legacy_da_exactamente_la_salida_de_hoy(string $modo): void
    {
        $this->publica(200);
        $negocia = $modo === 'llave_off';
        if ($modo === 'llave_off') {
            config()->set('marketing.ultron.studio_prompts', 'off');
        }

        $salidas = [];
        foreach (self::ENTRANTES as $intent => $texto) {
            $this->nuevaConversacion();
            $salidas["critico {$intent}"] = $this->huella($this->criticoCaido($intent, $texto, $negocia));
        }
        $this->nuevaConversacion();
        $salidas['aprobado largo'] = $this->huella($this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Élite?', 'Te cuento: el Élite tiene un costo mensual de {{PLAN_PRICE}} e incluye acceso total al gimnasio. Además tienes sesiones funcionales, rutinas guiadas y seguimiento continuo con tu entrenador. ¿Quieres que te cuente cómo empezar?', 8, [], $negocia));
        $this->nuevaConversacion();
        $salidas['aprobado que no cabe'] = $this->huella($this->aprobado(SalesIntents::PRICING_QUESTION, self::PREGUNTA_PRECIOS, 'Te confirmo que el plan Élite, que es el que mejor te encaja por todo lo que me has contado hasta ahora, tiene un costo mensual de {{PLAN_PRICE}} e incluye acceso total al gimnasio, sesiones funcionales, rutinas guiadas y seguimiento continuo.', 8, [], $negocia));
        $this->nuevaConversacion();
        $salidas['saludo que vende'] = $this->huella($this->aprobado(SalesIntents::GREETING, 'Hola buenas tardes', 'Bienvenido a Iron Body Neiva, un centro serio y con ambiente profesional. ¿Le cuento más sobre cómo avanzar con su inscripción o el pago?', null, [], $negocia));
        $this->nuevaConversacion();
        $salidas['precio en duda'] = $this->huella($this->aprobado(SalesIntents::PRICING_QUESTION, '¿Seguro que el Plan Mensual cuesta eso?', 'Sí, el Plan Mensual incluye acceso completo al gimnasio y rutinas guiadas desde la app. Además puedes entrenar en cualquier franja del horario de apertura. ¿Te ayudo con algo más?', 4, [], $negocia));
        $this->nuevaConversacion();
        $salidas['enlaces de la app'] = $this->huella($this->aprobado(SalesIntents::GENERAL_INFO, 'Pásame la app', 'Claro, esta es la app de Iron Body para descargarla en tu celular. Desde ahí creas tu cuenta y pagas tu plan con Nequi, PSE o tarjeta. ¿Te ayudo con algo más?', null, [SalesIntents::TOOL_APP_LINKS_SEND], $negocia));

        if (getenv('A4_INSTANTANEA') === 'escribir') {
            @mkdir(dirname(self::INSTANTANEA), 0777, true);
            $todas = is_file(self::INSTANTANEA) ? json_decode((string) file_get_contents(self::INSTANTANEA), true) : [];
            $todas[$modo] = $salidas;
            file_put_contents(self::INSTANTANEA, json_encode($todas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $this->markTestIncomplete('instantánea escrita: vuelve a correr sin A4_INSTANTANEA');
        }

        $this->assertFileExists(self::INSTANTANEA);
        $hoy = json_decode((string) file_get_contents(self::INSTANTANEA), true)[$modo] ?? null;
        $this->assertIsArray($hoy, "la instantánea no tiene el modo {$modo}");
        foreach ($hoy as $caso => $esperado) {
            $this->assertSame($esperado, $salidas[$caso] ?? null, "{$modo} · {$caso}");
        }
        $this->assertSame(array_keys($hoy), array_keys($salidas));
    }

    // ── Los textos que aprueba el propietario, generados por las clases reales ──

    /**
     * `docs/…/a4-textos-ajustados.md` sale de aquí (con `A4_TEXTOS=escribir`) y
     * esta prueba lo fija: si un texto cambia, falla hasta regenerarlo y que el
     * propietario lo vuelva a aprobar. Cada fila es un turno completo por el
     * endpoint con el revisor caído: Legacy (hoy), la versión sin tope (320),
     * y los topes 200 y 160.
     */
    public function test_los_textos_ajustados_que_aprueba_el_propietario(): void
    {
        // Unos 260 turnos en el mismo minuto congelado: el limitador interno (120/min) no es lo que se prueba aquí.
        config()->set('automation.rate_limits.marketing_ai', 100000);
        // La base de conocimiento de producción que ve el respaldo: identidad, ubicación y horario (ajuste 2 del lead).
        $this->fichaDeProduccion();
        $modos = ['Hoy (Legacy)' => null, 'Versión aplicada, sin tope (320)' => 320, 'Tope 200' => 200, 'Tope 160' => 160];
        $sale = function (?int $tope, string $intent, string $texto): string {
            $this->nuevaConversacion();
            if ($tope !== null) {
                $this->publica($tope);
            }
            $r = $this->criticoCaido($intent, $texto, $tope !== null);

            return $r['reply_final'] === null ? '— (no se contesta)' : '('.UltronReplyFitter::measure($r['reply_final']).') «'.str_replace("\n", ' ⏎ ', $r['reply_final']).'»';
        };

        $md = "# A4 · Textos de respaldo ajustados al tope (para aprobar)\n\n";
        $md .= 'Generado por `UltronA4LongitudYPreciosTest::test_los_textos_ajustados_que_aprueba_el_propietario` (no a mano), turno completo por el endpoint con el revisor caído dos veces. ';
        $md .= 'Datos: los 13 planes vendibles de producción (plan §2.1) y la ficha del horario de producción (253 caracteres). La base de conocimiento de la prueba tiene las tres piezas de producción (identidad, ubicación con un teléfono ficticio que `sinTelefonos` retira, y horario; no hay `gym_info`): `general_info` se llena por piezas en orden. ';
        $md .= "Entre paréntesis, la longitud medida (sin URL). Hora fijada: martes 12-11-2026, 16:52 en Bogotá («buenas tardes»).\n\n";
        $md .= "Leyenda: «Hoy (Legacy)» es lo que sale sin versión aplicada y no cambia; «Versión aplicada, sin tope (320)» solo difiere en la pregunta de precios (D-6).\n\n";
        $md .= "## Por intención (`SalesAgentDecisionSchema::INTENTS`)\n\n";
        foreach (self::ENTRANTES as $intent => $texto) {
            $md .= "### `{$intent}` — entrante: «{$texto}»\n\n";
            foreach ($modos as $nombre => $tope) {
                $md .= "- **{$nombre}** {$sale($tope, $intent, $texto)}\n";
            }
            $md .= "\n";
        }
        $md .= "## Horario (ficha de producción)\n\n";
        foreach (['Entre semana' => self::PREGUNTA_HORARIO, 'Sábado' => '¿y los sábados?', 'Sin día' => '¿Qué horario tienen?'] as $caso => $texto) {
            $md .= "### {$caso} — «{$texto}»\n\n";
            foreach ($modos as $nombre => $tope) {
                $md .= "- **{$nombre}** {$sale($tope, SalesIntents::SCHEDULE_QUESTION, $texto)}\n";
            }
            $md .= "\n";
        }
        $md .= "## Precios (catálogo de producción)\n\n";
        $precios = [
            '«¿Qué planes tienen y cuánto cuesta la mensualidad?»' => self::PREGUNTA_PRECIOS,
            '«¿Y el Élite cuánto cuesta? ¿Qué planes tienen?» (nombra un plan)' => '¿Y el Élite cuánto cuesta? ¿Qué planes tienen?',
            // F5: dos planes nombrados que no son de un mes (cabecera «Nuestros planes», con duraciones).
            '«¿Cuánto vale el plan día? ¿Qué planes tienen?» (nombra un plan de un día)' => '¿Cuánto vale el plan día? ¿Qué planes tienen?',
            '«¿Y el Trimestre cuánto cuesta? ¿Qué planes tienen?» (nombra un plan de tres meses)' => '¿Y el Trimestre cuánto cuesta? ¿Qué planes tienen?',
        ];
        foreach ($precios as $caso => $texto) {
            $md .= "### {$caso}\n\n";
            foreach ($modos as $nombre => $tope) {
                $md .= "- **{$nombre}** {$sale($tope, SalesIntents::PRICING_QUESTION, $texto)}\n";
            }
            $md .= "\n";
        }

        $doc = base_path('docs/ultron-studio/plans/informes-motores/a4-textos-ajustados.md');
        if (getenv('A4_TEXTOS') === 'escribir') {
            file_put_contents($doc, $md);
        }
        $this->assertFileExists($doc);
        $this->assertSame((string) file_get_contents($doc), $md, 'los textos cambiaron: regenerar con A4_TEXTOS=escribir y volver a aprobarlos');
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    /** Lo que se compara en Legacy: el texto, el desenlace y la forma de la fila y de la memoria. */
    private function huella(array $r): array
    {
        $fila = MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->first();
        $meta = $fila === null ? [] : $fila->metadata;
        $memoria = app(ConversationMemoryService::class)->load($this->conversation->fresh());

        return [
            'status' => $r['status'] ?? 200,
            'error' => $r['error'] ?? null,
            'reply_final' => $r['reply_final'] ?? null,
            'outcome' => $r['outcome'] ?? null,
            'fallback_mode' => $r['fallback_mode'] ?? null,
            'saliente' => $this->ultimoSaliente()?->body,
            'claves' => self::ordenadas(array_keys($meta)),
            'policy_violations' => $meta['policy_violations'] ?? null,
            'tools_executed' => $r['applied']['tools_executed'] ?? null,
            'precios_entregados' => array_values(array_filter(array_column(self::CATALOGO, 0), fn (int $id) => $memoria->priceDeliveredFor($id))),
            'oferta' => $memoria->get('last_agent_offer')['kind'] ?? null,
        ];
    }

    private static function ordenadas(array $v): array
    {
        sort($v);

        return $v;
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

    private function fichaDelHorario(string $contenido): void
    {
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => self::HORARIO_TITULO,
            'content' => $contenido,
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
            'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
            'metadata' => ['windows' => [
                'lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'],
                'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'],
                'sabado' => ['08:00', '14:00'], 'domingo' => ['08:00', '14:00'],
            ]],
        ]);
    }

    /** Identidad y ubicación de la base de conocimiento de producción (leídas por el lead el 12-11; el teléfono es ficticio). */
    private function fichaDeProduccion(): void
    {
        foreach ([
            ['business_identity', 'business.identity', 'Iron Body Neiva es un centro de acondicionamiento físico en Neiva.'],
            ['location', 'business.location', 'Iron Body Neiva está ubicado en Cl. 24 Sur #33-53, Neiva, Huila. Teléfono de contacto: 3001234567.'],
        ] as [$categoria, $clave, $contenido]) {
            MarketingKnowledgeItem::create([
                'category' => $categoria, 'key' => $clave, 'title' => '', 'content' => $contenido,
                'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
                'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
            ]);
        }
    }

    private function nuevaConversacion(): void
    {
        $n = str_pad((string) ++$this->mundo, 3, '0', STR_PAD_LEFT);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3150536'.$n, 'meta_user_id' => '573150536'.$n,
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
    }

    private function publica(int $maxChars): void
    {
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => $maxChars]), null);
    }

    private function entrante(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.a4.'.$this->mundo.'.'.uniqid(), 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m, bool $negocia): array
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ] + ($negocia ? ['prompt_contract' => 'ultron-prompts/1'] : []), $this->headers())->assertOk()->json();
    }

    private function spDe(array $decide): ?array
    {
        return self::cargaDelToken($decide['decide_token'])['x']['sp'] ?? null;
    }

    private static function propuesta(string $intent, string $borrador = 'Claro, te cuento. ¿Qué te gustaría lograr?', ?int $plan = null, array $herramientas = []): array
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

    /** Dos rechazos del revisor: el turno sale por `safeFallback()`. */
    private function criticoCaido(string $intent, string $texto, bool $negocia = true): array
    {
        $m = $this->entrante($texto);
        $decide = $this->decide($m, $negocia);

        return $this->commit($this->payload($m, $decide['decide_token'], self::propuesta($intent, 'Te confirmo que el plan Élite es el que mejor te encaja. ¿Quieres que te ayude a concretar tu inscripción?', 8), self::CRITICO_CAIDO))->assertOk()->json();
    }

    /** Un borrador que el revisor aprobó: el turno sale por el camino normal. */
    private function aprobado(string $intent, string $texto, string $borrador, ?int $plan, array $herramientas = [], bool $negocia = true): array
    {
        $m = $this->entrante($texto);
        $decide = $this->decide($m, $negocia);

        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta($intent, $borrador, $plan, $herramientas), ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9]));

        // Un 422 también es una salida (la rendición de n8n cuelga de él): se devuelve con su código.
        return $r->status() === 422 ? ['status' => 422, 'error' => $r->json('error') ?? $r->json('code') ?? $r->json()] : $r->assertOk()->json();
    }

    /**
     * El turno del escenario R7 del revisor: la persona da el día y la hora,
     * el revisor aprueba y el modelo pide la cortesía, que Laravel ejecuta.
     */
    private function conCortesia(string $borrador, bool $negocia): array
    {
        $m = $this->entrante('quiero ir el sábado a las 10 de la mañana');
        $decide = $this->decide($m, $negocia);
        $propuesta = array_merge(self::propuesta(SalesIntents::GENERAL_INFO, $borrador, null, [SalesIntents::TOOL_COURTESY_REQUEST]), [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment', 'courtesy_action' => 'request',
            'courtesy_date' => '2026-11-14', 'courtesy_time' => '10:00',
        ]);

        return $this->commit($this->payload($m, $decide['decide_token'], $propuesta, ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9]))->assertOk()->json();
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

    private function espiaElLog(): MockInterface
    {
        $spy = Log::spy();
        Log::shouldReceive('channel')->andReturn($spy);
        Log::shouldReceive('getFacadeRoot')->andReturn($spy);

        return $spy;
    }
}
