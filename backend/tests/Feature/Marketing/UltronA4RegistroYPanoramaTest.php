<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MobileAppCatalog;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\SalesObjectionResponderService;
use App\Services\Marketing\StaffReviewAuthority;
use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use App\Services\Marketing\Ultron\ConversationMemory;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Marketing\Ultron\CourtesyRequestService;
use App\Services\Marketing\Ultron\ReferenceResolver;
use App\Services\Marketing\Ultron\StrategyContract;
use App\Services\Marketing\Ultron\UltronCommitService;
use App\Services\Marketing\Ultron\UltronDecideToken;
use App\Services\Marketing\Ultron\UltronFormalReplies as U;
use App\Services\Marketing\Ultron\UltronRegisterDetector;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * A4 · H4-9 (EL TONO PUBLICADO SE CUMPLE) Y H4-10 (LA PREGUNTA POR «LOS
 * PLANES» RECIBE MÁS DE UN PLAN), por el endpoint `decide` → `commit` con las
 * clases reales (plan `plan-arquitecto-a4-resto.md` §2 y §3; decisiones T-1 a
 * T-5 y P-1 del lead).
 *
 * El escenario es el de producción del 18-11-2026: la versión publicada SOLO
 * en la canaria, tono «profesional» y tope 200; los 13 planes vendibles y la
 * ficha del horario de producción. Los borradores de «la prueba real» son
 * literales de `ops/evidencia-h9/2026-11-18/canario-a4/04-mensajes-y-dictamen.txt`.
 *
 * `cercano`, `entusiasta`, un token sin `t` y Legacy NO cambian nada: lo fijan
 * aquí casos propios y, byte a byte, la instantánea de `UltronA4LongitudYPreciosTest`.
 */
class UltronA4RegistroYPanoramaTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000a9';

    /** Legacy con borradores de usted: tomada ANTES de que los detectores entendieran el usted (añadido del lead, 19-11). */
    private const INSTANTANEA_USTED = __DIR__.'/../../fixtures/ultron-a4/legacy-usted.json';

    /** El saludo cuyo borrador pregunta el objetivo de usted, sin las palabras que ya caza `VENDE_EN_SALUDO`. */
    private const SALUDO_QUE_PREGUNTA = 'Hola, buenas tardes. Bienvenido a Iron Body, qué gusto atenderle. ¿Ya ha entrenado antes?';

    /** Un turno de memoria cuyo borrador ofrece los planes de usted. */
    private const MEMORIA_QUE_VENDE = 'No veo una visita registrada todavía. Si quiere, le cuento sobre los planes.';

    /** Una oferta de usted en el borrador del modelo. */
    private const OFERTA_DE_USTED = 'Con gusto le ayudo con eso. ¿Quiere que le cuente cómo empezar?';

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

    private const HORARIO_TITULO = 'Horario general de atencion';

    private const HORARIO_CONTENIDO = 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases.';

    private const HORARIO_200 = 'Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.';

    private const PRECIOS_200 = 'Claro. Algunos planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?';

    // ── Los tres turnos de la prueba real del 18-11 ─────────────────────────

    private const REAL_PRECIOS_ENTRANTE = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private const REAL_PRECIOS_BORRADOR = 'Claro, el Plan Valera tiene una mensualidad de {{PLAN_PRICE}} y ofrece acceso ilimitado al gimnasio, asesoría semi personalizada y seguimiento en tus ejercicios. ¿Me cuenta si ya ha entrenado antes o es su primera vez?';

    private const REAL_HORARIO_ENTRANTE = '¿Cuál es el horario de atención entre semana?';

    private const REAL_HORARIO_BORRADOR = 'Te entiendo, quieres saber el horario entre semana. El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Quieres que te cuente también sobre las clases o los planes que tenemos?';

    /** El tuteo SOLO en la pregunta final. */
    private const SOLO_LA_PREGUNTA = 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Quieres que te cuente también sobre las clases?';

    private const CRITICO_CAIDO = ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3, 'issues' => ['relevance'], 'hard_fail' => null];

    private const APROBADO = ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9];

    /** Un mensaje real por intención (los de A4), para la completitud de T-4. */
    private const ENTRANTES = [
        SalesIntents::PRICING_QUESTION => '¿Cuánto cuesta?',
        SalesIntents::PAYMENT_LINK_REQUEST => 'Mándame el link para pagar',
        SalesIntents::PRICE_OBJECTION => 'Está muy caro',
        SalesIntents::TIME_OBJECTION => 'No tengo tiempo',
        SalesIntents::DELAY_OBJECTION => 'Lo voy a pensar',
        SalesIntents::BEGINNER_FEAR => 'Nunca he entrenado y me da miedo',
        SalesIntents::INSECURITY_BODY => 'Me da pena mi cuerpo',
        SalesIntents::LOCATION_QUESTION => '¿Dónde quedan?',
        SalesIntents::SCHEDULE_QUESTION => self::REAL_HORARIO_ENTRANTE,
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
        // Miércoles 18-11-2026, 17:47 en Bogotá: «buenas tardes».
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
        // Más turnos por minuto congelado de los que permite el limitador interno: no es lo que se prueba.
        config()->set('automation.rate_limits.marketing_ai', 100000);
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

    // ── T-1 · El tono, firmado en el token y solo ahí ───────────────────────

    public function test_el_token_lleva_t_solo_con_studio_y_tono_distinto_de_la_linea_base(): void
    {
        $this->publica('profesional', 200);
        $sp = $this->spDe($this->decide($this->entrante('hola'), true));
        $this->assertSame('profesional', $sp['t'] ?? null);
        $this->assertSame(200, $sp['n'] ?? null);

        $this->nuevaConversacion();
        $this->publica('entusiasta', 320);
        $sp = $this->spDe($this->decide($this->entrante('hola'), true));
        $this->assertSame('entusiasta', $sp['t'] ?? null);
        $this->assertArrayNotHasKey('n', $sp);

        $this->nuevaConversacion();
        $this->publica('cercano', 200);
        $sp = $this->spDe($this->decide($this->entrante('hola'), true));
        $this->assertArrayNotHasKey('t', $sp, 'la línea base no se firma (M1-4)');

        $this->nuevaConversacion();
        config()->set('marketing.ultron.studio_prompts', 'off');
        $sp = $this->spDe($this->decide($this->entrante('hola'), true));
        $this->assertSame(['m', 'r', 'h', 'v', 'c', 'e', 's'], array_keys($sp), 'Legacy: el sp de hoy, sin la clave');
    }

    public function test_t_no_viaja_en_la_respuesta_a_n8n(): void
    {
        $this->publica('profesional', 200);
        $decide = $this->decide($this->entrante('hola'), true);
        $this->assertSame(['contract', 'mode', 'reason', 'base', 'compiler', 'bundle_hash', 'version', 'content_hash', 'entry', 'seq', 'roles'], array_keys($decide['studio_prompts']));
        $this->assertStringNotContainsString('"t"', json_encode($decide['studio_prompts']));
    }

    public function test_un_tono_desconocido_se_ignora_con_aviso_y_sin_el_valor(): void
    {
        $this->publica('profesional', 200);
        $log = $this->espiaElLog();
        $r = $this->conTokenRetocado(fn (array $sp) => ['t' => 'formalisimo'] + $sp, SalesIntents::SCHEDULE_QUESTION, self::REAL_HORARIO_ENTRANTE, self::SOLO_LA_PREGUNTA);

        $this->assertSame(self::SOLO_LA_PREGUNTA, $r['reply_final'], 'sin tono válido no se comprueba nada');
        $this->assertArrayNotHasKey('register', $this->ultimaAccion()->metadata);
        $log->shouldHaveReceived('warning')->withArgs(fn (string $e, array $c = []) => $e === 'ultron.commit.tone_invalid'
            && ($c['type'] ?? null) === 'string' && ! str_contains(json_encode($c), 'formalisimo'))->once();
    }

    public function test_un_token_sin_t_no_hace_nada(): void
    {
        $this->publica('profesional', 200);
        $r = $this->conTokenRetocado(function (array $sp) {
            unset($sp['t']);

            return $sp;
        }, SalesIntents::SCHEDULE_QUESTION, self::REAL_HORARIO_ENTRANTE, self::REAL_HORARIO_BORRADOR);

        $this->assertSame(self::REAL_HORARIO_BORRADOR, $r['reply_final'], 'un token de antes del despliegue: como hoy');
        $this->assertArrayNotHasKey('register', $this->ultimaAccion()->metadata);
    }

    // ── T-3 · El registro en el commit ──────────────────────────────────────

    public function test_turno_3_real_un_borrador_de_tu_se_descarta_y_sale_el_horario(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::SCHEDULE_QUESTION, self::REAL_HORARIO_ENTRANTE, self::REAL_HORARIO_BORRADOR, null);

        $this->assertSame(self::HORARIO_200, $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertContains(UltronCommitService::VIOLACION_REGISTRO, $fila->metadata['policy_violations']);
        $this->assertEquals(['expected' => 'usted', 'found' => ['te', 'quieres'], 'action' => 'discarded'], $fila->metadata['register']);
        $this->assertSame([], UltronRegisterDetector::tuteo($r['reply_final']));
    }

    /** G3 (m-7): sin tope, el horario de usted (antes, el último recurso genérico, que no contestaba). */
    public function test_turno_3_real_sin_tope_sale_el_horario_de_usted(): void
    {
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::SCHEDULE_QUESTION, self::REAL_HORARIO_ENTRANTE, self::REAL_HORARIO_BORRADOR, null);

        $this->assertSame('Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja le queda mejor entrenar?', $r['reply_final']);
        $this->assertSame('discarded', $this->ultimaAccion()->metadata['register']['action']);
    }

    public function test_turno_2_real_la_mezcla_se_descarta_y_sale_la_lista_de_precios(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, self::REAL_PRECIOS_ENTRANTE, self::REAL_PRECIOS_BORRADOR, 3);

        $this->assertSame(self::PRECIOS_200, $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertEquals(['expected' => 'usted', 'found' => ['tus'], 'action' => 'discarded'], $fila->metadata['register']);
        $this->assertContains(UltronCommitService::VIOLACION_REGISTRO, $fila->metadata['policy_violations']);
        $this->assertNull($r['price_source'], 'la lista no cotiza el plan del modelo');
    }

    public function test_turno_1_real_de_usted_sale_tal_cual(): void
    {
        $this->publica('profesional', 200);
        $borrador = 'Buenas tardes, bienvenido a Iron Body Neiva, que gusto atenderle. Cuénteme, ¿qué le gustaría conocer hoy del gimnasio?';
        $r = $this->aprobado(SalesIntents::GREETING, 'Hola, buenas tardes', $borrador, null);

        $this->assertSame($borrador, $r['reply_final']);
        $this->assertEquals(['expected' => 'usted', 'found' => [], 'action' => 'ok'], $this->ultimaAccion()->metadata['register']);
    }

    /**
     * G2 (B-2): con el tuteo solo en la pregunta final pero UNA sola frase
     * detrás, ya no se recorta: se descarta y sale el horario de usted de la
     * ficha. El recorte con dos frases detrás lo fija
     * `test_g2_con_dos_frases_tras_quitar_la_pregunta_se_sigue_recortando`.
     */
    public function test_el_tuteo_solo_en_la_pregunta_final_con_una_sola_frase_detras_se_descarta(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::SCHEDULE_QUESTION, self::REAL_HORARIO_ENTRANTE, self::SOLO_LA_PREGUNTA, null);

        $this->assertSame(self::HORARIO_200, $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertEquals(['expected' => 'usted', 'found' => ['quieres', 'te'], 'action' => 'discarded'], $fila->metadata['register']);
        $this->assertSame([UltronCommitService::VIOLACION_REGISTRO], $fila->metadata['policy_violations']);
    }

    public function test_una_pregunta_final_de_tu_con_cifra_no_se_quita_se_descarta(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::SCHEDULE_QUESTION, self::REAL_HORARIO_ENTRANTE, 'El gimnasio abre de lunes a viernes desde temprano. ¿Te queda bien venir a las 6 p. m.?', null);

        $this->assertSame(self::HORARIO_200, $r['reply_final']);
        $this->assertSame('discarded', $this->ultimaAccion()->metadata['register']['action']);
    }

    /** @return array<string, array{string}> */
    public static function tonosQueTutean(): array
    {
        return ['cercano (línea base)' => ['cercano'], 'entusiasta' => ['entusiasta']];
    }

    #[DataProvider('tonosQueTutean')]
    public function test_cercano_y_entusiasta_no_hacen_nada(string $tono): void
    {
        $this->publica($tono, 200);
        $r = $this->aprobado(SalesIntents::SCHEDULE_QUESTION, self::REAL_HORARIO_ENTRANTE, self::REAL_HORARIO_BORRADOR, null);
        $this->assertSame(self::REAL_HORARIO_BORRADOR, $r['reply_final']);
        $this->assertArrayNotHasKey('register', $this->ultimaAccion()->metadata);

        // Y los respaldos, los de hoy.
        $this->nuevaConversacion();
        $this->publica($tono, 320);
        $r = $this->criticoCaido(SalesIntents::GENERAL_INFO, 'Quiero información del gimnasio');
        $this->assertStringStartsWith('Con gusto te cuento.', $r['reply_final']);
    }

    public function test_la_frase_que_retoma_la_visita_sale_de_usted(): void
    {
        $this->publica('profesional', 320);
        $this->visitaAMedias(['date' => '2026-11-21']);
        $r = $this->aprobado(SalesIntents::LOCATION_QUESTION, '¿Dónde quedan?', 'Estamos en Cl. 24 Sur #33-53, Neiva, Huila.', null);

        $this->assertSame('Estamos en Cl. 24 Sur #33-53, Neiva, Huila. Y sobre su visita: ya tengo el día y solo me falta la hora: ¿a qué hora le queda bien?', $r['reply_final']);
    }

    // ── T-4 · Los textos de Laravel, de usted ───────────────────────────────

    /** @return array<string, array{int}> */
    public static function topes(): array
    {
        return ['sin tope (320)' => [320], 'tope 200' => [200], 'tope 160' => [160]];
    }

    #[DataProvider('topes')]
    public function test_ningun_respaldo_de_usted_lleva_marcas(int $tope): void
    {
        $this->fichaDeProduccion();
        foreach (self::ENTRANTES as $intent => $texto) {
            $this->nuevaConversacion();
            $this->publica('profesional', $tope);
            $r = $this->criticoCaido($intent, $texto);
            if ($r['reply_final'] === null) {
                continue;
            }
            $this->assertSame([], UltronRegisterDetector::tuteo($r['reply_final']), "{$intent} a {$tope}: «{$r['reply_final']}»");
            $this->assertArrayNotHasKey('register', $this->ultimaAccion()->metadata, "{$intent}: la última red no tuvo que actuar");
        }
    }

    public function test_la_bienvenida_a_quien_ya_saludamos_y_la_memoria_de_la_visita_salen_de_usted(): void
    {
        $this->publica('profesional', 200);
        $this->criticoCaido(SalesIntents::GREETING, 'Hola buenas tardes');
        $r = $this->criticoCaido(SalesIntents::GREETING, 'Hola');
        $this->assertSame('Hola de nuevo, muy buenas tardes. Cuénteme, ¿en qué le podemos ayudar hoy?', $r['reply_final']);

        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $r = $this->criticoCaido(SalesIntents::UNKNOWN, '¿A qué hora era mi visita?');
        $this->assertSame('No tengo registrada una visita suya. Dígame el día y la hora que le sirven y la dejo solicitada.', $r['reply_final']);
    }

    public function test_la_ultima_red_anota_una_ficha_que_tutea_y_sale_igual(): void
    {
        MarketingKnowledgeItem::create([
            'category' => 'business_identity', 'key' => 'business.identity', 'title' => '',
            'content' => 'Iron Body Neiva es tu gimnasio en Neiva.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
            'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
        ]);
        $this->publica('profesional', 320);
        $log = $this->espiaElLog();
        $r = $this->criticoCaido(SalesIntents::GENERAL_INFO, 'Quiero información del gimnasio');

        $this->assertStringStartsWith('Con gusto le cuento. Iron Body Neiva es tu gimnasio en Neiva.', $r['reply_final'], 'el texto de la base de datos no se reescribe');
        $this->assertSame($r['reply_final'], $this->ultimoSaliente()->body, 'nunca un turno mudo');
        $this->assertSame(['tu'], $this->ultimaAccion()->metadata['register']['fallback_mismatch']);
        $log->shouldHaveReceived('warning')->withArgs(fn (string $e, array $c = []) => $e === 'ultron.commit.register_fallback_mismatch')->once();
    }

    public function test_el_acta_de_una_visita_registrada_sale_de_usted(): void
    {
        $this->publica('profesional', 320);
        $r = $this->conCortesia('Perfecto, quedamos el sábado a las 6:00 a. m.');

        $this->assertStringContainsString('Dejé registrada su solicitud de cortesía para el sábado 21 de noviembre a las 10:00 a. m.', $r['reply_final']);
        $this->assertSame([], UltronRegisterDetector::tuteo($r['reply_final']));
    }

    /**
     * La completitud de T-4 y `a4-textos-usted.md`: todo texto que Laravel
     * puede escribir, con su variante de usted, generado por las clases
     * reales (los productores de hoy y la tabla de `UltronFormalReplies`).
     * Ninguna variante lleva marcas; cada fragmento de la tabla sigue
     * existiendo en el código de su productor; y el documento que aprueba el
     * propietario es exactamente este (se regenera con `A4_USTED=escribir`).
     */
    public function test_los_textos_de_usted_que_aprueba_el_propietario(): void
    {
        $replies = app(SalesConversationReplyService::class);
        $objeciones = new SalesObjectionResponderService;
        $planes = array_map(fn (array $p) => ['id' => $p[0], 'name' => $p[1]], self::CATALOGO);
        $filas = [];
        $anota = function (string $seccion, string $caso, string $original, string $usted) use (&$filas, $planes): void {
            $this->assertSame([], UltronRegisterDetector::tuteo($usted, $planes), "{$seccion} · {$caso}: «{$usted}»");
            $filas[$seccion][] = [$caso, $original, $usted];
        };

        // Por intención (`replyFor`), con la ficha del horario de producción.
        foreach (SalesAgentDecisionSchema::INTENTS as $intent) {
            $original = $replies->replyFor($intent);
            if ($original !== null) {
                $anota('Por intención (`replyFor`)', $intent, $original, $intent === SalesIntents::SCHEDULE_QUESTION ? U::cola($original) : U::deUsted($original));
            }
        }
        // Sin ficha del horario: la rama honesta (S2).
        MarketingKnowledgeItem::query()->where('category', 'schedule')->update(['is_active' => false]);
        $s2 = (string) (new SalesConversationReplyService)->replyFor(SalesIntents::SCHEDULE_QUESTION);
        MarketingKnowledgeItem::query()->where('category', 'schedule')->update(['is_active' => true]);
        $anota('Horario sin ficha (`replyFor`)', 'schedule_question', $s2, U::cola($s2));
        foreach (SalesIntents::OBJECTION_INTENTS as $intent) {
            $anota('Objeciones', $intent, (string) $objeciones->reply($intent), U::deUsted((string) $objeciones->reply($intent)));
        }
        $anota('Pago pendiente', 'paymentPendingReply', $replies->paymentPendingReply(), U::deUsted($replies->paymentPendingReply()));
        foreach (['buenos dias', 'buenas tardes', 'buenas noches'] as $franja) {
            foreach ([false, true] as $yaSaludado) {
                foreach ($replies->welcomeReplies($franja, $yaSaludado) as $i => $w) {
                    $anota('Bienvenida (`welcomeReplies`)', "{$franja}, ".($yaSaludado ? 'ya saludado' : 'primera vez').' #'.($i + 1), $w, U::deUsted($w));
                }
            }
        }
        $vistos = [];
        foreach (SalesAgentDecisionSchema::INTENTS as $intent) {
            foreach ($replies->lastResorts($intent) as $v) {
                if (! isset($vistos[$v])) {
                    $vistos[$v] = true;
                    $anota('Últimos recursos (`lastResorts`)', $intent, $v, U::deUsted($v));
                }
            }
        }

        // La política: plan, visita y retomar la visita, en todos sus estados.
        $catalogo = [['id' => 4, 'name' => 'Plan Mensual', 'benefits' => ['Acceso total al gimnasio', 'Rutinas guiadas']]];
        foreach ([false, true] as $otro) {
            $plan = (string) CommercialTurnPolicy::planReply(['required_plan_id' => 4, 'wants_alternative' => $otro], $catalogo);
            $anota('Política: plan (`planReply`)', $otro ? 'otra opción' : 'recomendado', $plan, U::cola($plan));
        }
        foreach (['sin día ni hora' => [], 'con día' => ['date' => '2026-11-21'], 'con hora' => ['time' => '18:00']] as $caso => $data) {
            $objetivo = ['kind' => ConversationMemoryService::GOAL_COURTESY, 'status' => ConversationMemoryService::GOAL_COLLECTING, 'data' => $data];
            $anota('Política: visita (`courtesyReply`)', $caso, $v = (string) CommercialTurnPolicy::courtesyReply(['active_goal' => $objetivo]), U::deUsted($v));
            $anota('Política: retomar la visita (`courtesyResumption`)', $caso, $v = (string) CommercialTurnPolicy::courtesyResumption(['active_goal' => $objetivo]), U::deUsted($v));
        }
        $pedida = ['kind' => ConversationMemoryService::GOAL_COURTESY, 'status' => ConversationMemoryService::GOAL_REQUESTED, 'data' => []];
        $anota('Política: retomar la visita (`courtesyResumption`)', 'ya solicitada', $v = (string) CommercialTurnPolicy::courtesyResumption(['active_goal' => $pedida]), U::deUsted($v));

        // El acta de la cortesía (CourtesyRequestService), con una cita en memoria (sin base de datos).
        $courtesy = new CourtesyRequestService;
        foreach ([MarketingAppointment::STATUS_REQUESTED, MarketingAppointment::STATUS_SCHEDULED, MarketingAppointment::STATUS_CANCELLED] as $estado) {
            // Sábado 21-11 a las 10:00 en Bogotá (15:00 UTC, como se guarda).
            $cita = (new MarketingAppointment)->forceFill(['status' => $estado, 'scheduled_at' => Carbon::parse('2026-11-21 15:00:00', 'UTC')]);
            $anota('Acta de la cortesía (`actaDeLaFila`)', $estado, $v = (string) $courtesy->actaDeLaFila($cita), U::deUsted($v));
        }
        foreach ([false, true] as $conContexto) {
            $anota('Acta de la cortesía (`preguntaDeHora`)', $conContexto ? 'con contexto' : 'sola', $v = CourtesyRequestService::preguntaDeHora(['07:00', '19:00'], $conContexto), U::deUsted($v));
        }
        foreach (['Tu solicitud de visita sigue registrada para ', 'Tu visita sigue confirmada para '] as $inicio) {
            $anota('Acta de la cortesía (`estadoDe`)', $inicio, $v = $inicio.'el sábado 21 de noviembre a las 10:00 a. m.', U::deUsted($v));
        }

        // Los textos propios del commit (constantes y frases), por su nombre.
        foreach (['CORTESIA_SIN_CONFIRMAR', 'NADA_QUE_CONFIRMAR', 'SIN_ENLACE_PERO_SIN_MENTIR'] as $nombre) {
            $v = (string) (new ReflectionClassConstant(UltronCommitService::class, $nombre))->getValue();
            $anota('Commit: constantes', $nombre, $v, U::deUsted($v));
        }
        foreach (U::FRASES as $original => $usted) {
            $anota('Tabla completa (`UltronFormalReplies::FRASES`)', '—', $original, $usted);
            $this->assertTrue($this->existeEnSuProductor($original), "el fragmento ya no existe en su productor: «{$original}»");
        }
        foreach (U::COLAS as $original => $usted) {
            $anota('Colas (`UltronFormalReplies::COLAS`)', '—', $original, $usted);
            $this->assertTrue($this->existeEnSuProductor($original), "la cola ya no existe en su productor: «{$original}»");
        }
        $anota('Ficha del gimnasio', 'apertura', 'Con gusto te cuento.', U::FICHA_APERTURA);
        $anota('Ficha del gimnasio', 'cierre', '¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?', U::FICHA_CIERRE);
        foreach (StrategyContract::PREGUNTAS_DE_USTED as $original => $usted) {
            $anota('Pregunta sugerida (`StrategyContract`, sin tildes: cruza al modelo)', '—', $original, $usted);
        }

        // Cada variante, ajustada a 200 y a 160 (la misma regla general de los textos de Laravel).
        $mide = function (string $texto, int $tope, bool $horario): string {
            // El horario con la ficha se ajusta con su propia regla (la del commit), preguntado «entre semana».
            $ajustado = $horario ? UltronReplyFitter::schedule($texto, $tope, self::REAL_HORARIO_ENTRANTE) : UltronReplyFitter::curated($texto, $tope);

            return $ajustado === null ? '— (no cabe: último recurso)' : '('.UltronReplyFitter::measure($ajustado).') «'.str_replace("\n", ' ⏎ ', $ajustado).'»';
        };
        $md = "# A4 · Textos de Laravel de usted, con el tono «profesional» (para aprobar)\n\n";
        $md .= 'Generado por `UltronA4RegistroYPanoramaTest::test_los_textos_de_usted_que_aprueba_el_propietario` (no a mano), con los productores reales de hoy ';
        $md .= '(`SalesConversationReplyService`, `SalesObjectionResponderService`, `CommercialTurnPolicy`, `CourtesyRequestService`, las constantes de `UltronCommitService`) ';
        $md .= "y la tabla de `UltronFormalReplies`. Solo se usan con el tono «profesional»; con cualquier otro tono salen los originales, sin cambios. Ninguna variante lleva marcas de `UltronRegisterDetector`.\n\n";
        $md .= "Ficha del horario: la de producción (253 caracteres). Entre paréntesis, la longitud medida (sin URL). Cada variante se ajusta a 200 y a 160 con la regla de los textos de Laravel (`UltronReplyFitter::curated`); el horario con ficha, con la suya (`schedule`, preguntado «entre semana»), como en el commit. Las fechas de ejemplo: sábado 21-11 a las 10:00 (Bogotá).\n\n";
        foreach ($filas as $seccion => $casos) {
            $md .= "## {$seccion}\n\n";
            foreach ($casos as [$caso, $original, $usted]) {
                $md .= "### {$caso}\n\n";
                $md .= '- **Hoy** ('.UltronReplyFitter::measure($original).') «'.str_replace("\n", ' ⏎ ', $original)."»\n";
                $md .= '- **De usted** ('.UltronReplyFitter::measure($usted).') «'.str_replace("\n", ' ⏎ ', $usted)."»\n";
                if (! str_starts_with($seccion, 'Tabla') && ! str_starts_with($seccion, 'Colas') && ! str_starts_with($seccion, 'Pregunta')) {
                    $horario = $caso === SalesIntents::SCHEDULE_QUESTION && str_starts_with($seccion, 'Por intención');
                    $md .= "- **De usted, tope 200** {$mide($usted, 200, $horario)}\n";
                    $md .= "- **De usted, tope 160** {$mide($usted, 160, $horario)}\n";
                }
                $md .= "\n";
            }
        }

        $doc = base_path('docs/ultron-studio/plans/informes-motores/a4-textos-usted.md');
        if (getenv('A4_USTED') === 'escribir') {
            file_put_contents($doc, $md);
        }
        $this->assertFileExists($doc);
        $this->assertSame((string) file_get_contents($doc), $md, 'los textos cambiaron: regenerar con A4_USTED=escribir y volver a aprobarlos');
    }

    // ── T-5 · La pregunta sugerida, de usted con «profesional» ──────────────

    public function test_la_pregunta_sugerida_va_de_usted_solo_con_profesional(): void
    {
        $this->publica('profesional', 200);
        $pregunta = $this->decide($this->entrante('Quiero información del gimnasio'), true)['context']['strategy_hints']['suggested_question'];
        $this->assertContains($pregunta, StrategyContract::PREGUNTAS_DE_USTED);

        $this->nuevaConversacion();
        $this->publica('entusiasta', 200);
        $pregunta = $this->decide($this->entrante('Quiero información del gimnasio'), true)['context']['strategy_hints']['suggested_question'];
        $this->assertArrayHasKey($pregunta, StrategyContract::PREGUNTAS_DE_USTED, 'con otro tono, la de hoy');

        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $pregunta = $this->decide($this->entrante('Quiero información del gimnasio'), false)['context']['strategy_hints']['suggested_question'];
        $this->assertArrayHasKey($pregunta, StrategyContract::PREGUNTAS_DE_USTED, 'sin negociar (Legacy), la de hoy');
    }

    // ── P-1 · El panorama de planes ─────────────────────────────────────────

    public function test_panorama_con_precio_sale_siempre_la_lista(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Qué planes tienen y cuánto cuestan?', 'Claro, el Plan Valera cuesta {{PLAN_PRICE}} al mes e incluye acceso total al gimnasio. ¿Le interesa?', 3);

        $this->assertSame(self::PRECIOS_200, $r['reply_final']);
        $this->assertContains(CommercialTurnPolicy::VIOLACION_PANORAMA, $this->ultimaAccion()->metadata['policy_violations']);
        $this->assertNull($r['price_source']);
    }

    public function test_panorama_sin_precio_con_un_plan_sale_la_lista_y_con_dos_el_modelo(): void
    {
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, '¿Qué planes tienen?', 'Tenemos el Plan Valera, con acceso total al gimnasio. ¿Le cuento cómo empezar?', null);
        $this->assertStringStartsWith('Claro. Algunos planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP.', $r['reply_final']);
        $this->assertContains(CommercialTurnPolicy::VIOLACION_PANORAMA, $this->ultimaAccion()->metadata['policy_violations']);

        $this->nuevaConversacion();
        $this->publica('profesional', 320);
        $dos = 'Tenemos el Plan Valera y el Plan Mensual, los dos de un mes, y también el Trimestre. ¿Sobre cuál le cuento más?';
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, '¿Qué planes tienen?', $dos, null);
        $this->assertSame($dos, $r['reply_final']);
        $this->assertArrayNotHasKey('policy_violations', $this->ultimaAccion()->metadata);
    }

    public function test_un_plan_nombrado_no_es_panorama(): void
    {
        $this->publica('profesional', 320);
        $borrador = 'El Plan Mensual cuesta {{PLAN_PRICE}} e incluye acceso total al gimnasio. ¿Le cuento cómo empezar?';
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Mensual?', $borrador, 4);
        $this->assertSame('El Plan Mensual cuesta $80.000 COP e incluye acceso total al gimnasio. ¿Le cuento cómo empezar?', $r['reply_final']);
        $this->assertSame(4, $r['price_source']['plan_id']);

        $this->nuevaConversacion();
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta la mensualidad?', 'El Plan Valera cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?', 3);
        $this->assertSame('El Plan Valera cuesta $65.000 COP al mes. ¿Le cuento qué incluye?', $r['reply_final'], '«la mensualidad» en singular no es panorama');
    }

    public function test_un_plan_nombrado_y_que_otros_hay_es_panorama_con_ese_plan_primero(): void
    {
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Élite y qué otros hay?', 'El Élite cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?', 8);
        $this->assertStringStartsWith('Claro. Algunos planes de un mes: Élite, $180.000 COP; Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP.', $r['reply_final']);
    }

    public function test_panorama_con_el_revisor_caido_sale_la_lista(): void
    {
        $this->publica('profesional', 200);
        $r = $this->criticoCaido(SalesIntents::GENERAL_INFO, '¿Qué planes tienen?');
        $this->assertSame(self::PRECIOS_200, $r['reply_final']);
    }

    public function test_sin_studio_el_panorama_no_cambia_nada(): void
    {
        $this->publica('profesional', 200);
        config()->set('marketing.ultron.studio_prompts', 'off');
        $borrador = 'Tenemos el Plan Valera, con acceso total al gimnasio. ¿Te cuento cómo empezar?';
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, '¿Qué planes tienen?', $borrador, null);
        $this->assertSame($borrador, $r['reply_final']);

        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        config()->set('marketing.ultron.studio_prompts', 'off');
        $r = $this->criticoCaido(SalesIntents::GENERAL_INFO, '¿Qué planes tienen?');
        $this->assertStringNotContainsString('$', (string) $r['reply_final']);
    }

    // ── Añadido del lead (19-11): los detectores entienden el usted ────────

    public function test_el_acta_sale_de_usted_cuando_el_registro_descarta_un_borrador_con_cortesia_ejecutada(): void
    {
        // El borrador de R7 (A4), que tutea en el cuerpo; sin tope: solo actúa el registro.
        $borrador = 'Listo, ya te dejo anotado. En la primera visita te muestran las máquinas, te arman una rutina de prueba y resuelven tus dudas con un entrenador. Queda registrada tu solicitud de visita para el sábado a las 10:00 a. m. y el equipo la revisará.';
        $this->publica('profesional', 320);
        $r = $this->conCortesia($borrador);

        $this->assertSame('Dejé registrada su solicitud de cortesía para el sábado 21 de noviembre a las 10:00 a. m. El equipo de Iron Body la revisará para tener todo preparado.', $r['reply_final']);
        $this->assertContains(SalesIntents::TOOL_COURTESY_REQUEST, $r['applied']['tools_executed']);
        $fila = $this->ultimaAccion();
        $this->assertContains(UltronCommitService::VIOLACION_REGISTRO, $fila->metadata['policy_violations']);
        $this->assertNotContains(UltronCommitService::VIOLACION_LONGITUD, $fila->metadata['policy_violations']);
        $this->assertNull($r['price_source']);
    }

    public function test_a_el_respaldo_de_pago_ofrece_de_usted_y_el_si_se_resuelve_contra_esa_oferta(): void
    {
        $this->publica('profesional', 200);
        $r = $this->criticoCaido(SalesIntents::PAYMENT_LINK_REQUEST, 'Quiero pagar');
        $this->assertStringEndsWith('¿Quiere que le pase la app para descargarla?', (string) $r['reply_final']);
        $this->assertSame('generic_offer', $this->memoria()->get('last_agent_offer')['kind'] ?? null, 'anotada como oferta, igual que su forma de tú');

        $decide = $this->decide($this->entrante('Sí, por favor'), true);
        $this->assertSame(ReferenceResolver::ACCEPT_OFFER, $decide['context']['resolved_reference']['type']);
    }

    public function test_a_una_oferta_de_usted_del_modelo_queda_anotada_con_su_clase(): void
    {
        $this->publica('profesional', 200);
        $this->assertSame(self::OFERTA_DE_USTED, $this->aprobado(SalesIntents::GENERAL_INFO, 'Quiero empezar', self::OFERTA_DE_USTED, null)['reply_final']);
        $this->assertSame('explain_how_to_start', $this->memoria()->get('last_agent_offer')['kind'] ?? null);

        $decide = $this->decide($this->entrante('Sí'), true);
        $this->assertSame([ReferenceResolver::ACCEPT_OFFER, 'explain_how_to_start'], [$decide['context']['resolved_reference']['type'], $decide['context']['resolved_reference']['offer_kind'] ?? null]);
    }

    public function test_b_un_saludo_que_pregunta_el_objetivo_de_usted_lo_caza_la_politica_igual_que_el_de_tu(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::GREETING, 'Hola buenas tardes', self::SALUDO_QUE_PREGUNTA, null);
        $this->assertContains(CommercialTurnPolicy::VIOLACION_SALUDO_COMERCIAL, $this->ultimaAccion()->metadata['policy_violations']);
        $this->assertSame('Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderle. Cuénteme, ¿en qué le podemos ayudar hoy?', $r['reply_final']);

        // La misma pregunta de tú, con la línea base: la caza hoy, y la sigue cazando.
        $this->nuevaConversacion();
        $this->publica('cercano', 200);
        $this->aprobado(SalesIntents::GREETING, 'Hola buenas tardes', 'Hola, buenas tardes. Bienvenido a Iron Body, qué gusto atenderte. ¿Ya has entrenado antes?', null);
        $this->assertContains(CommercialTurnPolicy::VIOLACION_SALUDO_COMERCIAL, $this->ultimaAccion()->metadata['policy_violations']);

        // De usted, solo cuenta dentro de una pregunta: «el equipo tiene experiencia» no es preguntar.
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $afirma = 'Hola, buenas tardes. Bienvenido a Iron Body: nuestro equipo tiene experiencia y le acompaña. ¿En qué le podemos ayudar hoy?';
        $this->assertSame($afirma, $this->aprobado(SalesIntents::GREETING, 'Hola buenas tardes', $afirma, null)['reply_final']);
    }

    public function test_un_turno_de_memoria_que_ofrece_los_planes_de_usted_lo_caza_la_politica(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::UNKNOWN, '¿A qué hora era mi visita?', self::MEMORIA_QUE_VENDE, null);
        $this->assertContains(CommercialTurnPolicy::VIOLACION_MEMORIA_VENDE, $this->ultimaAccion()->metadata['policy_violations']);
        $this->assertSame('No tengo registrada una visita suya. Dígame el día y la hora que le sirven y la dejo solicitada.', $r['reply_final']);
    }

    /** @return array<string, array{string}> */
    public static function modosLegacy(): array
    {
        return ['token sin sp' => ['sin_sp'], 'llave de prompts apagada' => ['llave_off']];
    }

    /**
     * EL INVARIANTE DE LA ENMIENDA A4-1, PARA LOS DETECTORES: sin la versión en
     * «profesional», un borrador de usted da hoy y mañana lo mismo. La
     * instantánea `legacy-usted.json` se tomó con los detectores de antes
     * (sin entender el usted) y no se regenera para que pase.
     */
    #[DataProvider('modosLegacy')]
    public function test_legacy_un_borrador_de_usted_da_lo_mismo_que_antes(string $modo): void
    {
        $negocia = $modo === 'llave_off';
        $prepara = function () use ($modo): void {
            $this->nuevaConversacion();
            $this->publica('profesional', 200);
            if ($modo === 'llave_off') {
                config()->set('marketing.ultron.studio_prompts', 'off');
            }
        };
        $salidas = [];
        $prepara();
        $salidas['saludo que pregunta de usted'] = $this->huellaLegacy($this->aprobadoSegun(SalesIntents::GREETING, 'Hola buenas tardes', self::SALUDO_QUE_PREGUNTA, $negocia));
        $prepara();
        $salidas['memoria que vende de usted'] = $this->huellaLegacy($this->aprobadoSegun(SalesIntents::UNKNOWN, '¿A qué hora era mi visita?', self::MEMORIA_QUE_VENDE, $negocia));
        $prepara();
        $salidas['oferta de usted'] = $this->huellaLegacy($this->aprobadoSegun(SalesIntents::GENERAL_INFO, 'Quiero empezar', self::OFERTA_DE_USTED, $negocia));
        $salidas['y el «sí» siguiente'] = $this->decide($this->entrante('Sí'), $negocia)['context']['resolved_reference']['type'] ?? null;

        if (getenv('A4_USTED_LEGACY') === 'escribir') {
            $todas = is_file(self::INSTANTANEA_USTED) ? json_decode((string) file_get_contents(self::INSTANTANEA_USTED), true) : [];
            $todas[$modo] = $salidas;
            file_put_contents(self::INSTANTANEA_USTED, json_encode($todas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $this->markTestIncomplete('instantánea escrita: vuelve a correr sin A4_USTED_LEGACY');
        }

        $this->assertFileExists(self::INSTANTANEA_USTED);
        $hoy = json_decode((string) file_get_contents(self::INSTANTANEA_USTED), true)[$modo] ?? null;
        $this->assertIsArray($hoy, "la instantánea no tiene el modo {$modo}");
        $this->assertSame($hoy, $salidas);
    }

    // ── V-2 · Los datos comerciales salen del CRM, en vivo ─────────────────
    //
    // Encargo del propietario: planes, precios y la lista de «varios planes»
    // salen SIEMPRE del catálogo del CRM en el momento del turno. Entre un turno
    // y el siguiente no se toca ni un prompt ni una línea de código: solo la
    // fila de `plans`. Con la versión aplicada en la línea base del tono
    // («cercano», sin tope): lo que se mide aquí es la fuente, no el registro.

    public function test_v2a_un_precio_nuevo_en_el_crm_sale_en_el_turno_siguiente_y_entre_decide_y_commit(): void
    {
        $borrador = 'El Plan Mensual cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?';
        $this->publica('cercano', 320);
        $this->assertSame('El Plan Mensual cuesta $80.000 COP al mes. ¿Le cuento qué incluye?', $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Mensual?', $borrador, 4)['reply_final']);

        Plan::query()->whereKey(4)->update(['price' => 85000]);
        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $this->assertSame('El Plan Mensual cuesta $85.000 COP al mes. ¿Le cuento qué incluye?', $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Mensual?', $borrador, 4)['reply_final']);

        // Y entre `decide` y `commit`: el precio es el de la fila al COMPONER, no el de cuando se decidió.
        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $m = $this->entrante('¿Cuánto cuesta el Mensual?');
        $decide = $this->decide($m, true);
        Plan::query()->whereKey(4)->update(['price' => 87500]);
        $r = $this->commit($this->payload($m, $decide['decide_token'], self::propuesta(SalesIntents::PRICING_QUESTION, $borrador, 4), self::APROBADO))->assertOk()->json();
        $this->assertSame('El Plan Mensual cuesta $87.500 COP al mes. ¿Le cuento qué incluye?', $r['reply_final']);
        $this->assertSame(87500.0, (float) $r['price_source']['price']);

        // La lista determinista, con el mismo precio nuevo.
        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $this->assertStringContainsString('Plan Mensual, $87.500 COP', (string) $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Qué planes tienen y cuánto cuestan?')['reply_final']);
    }

    public function test_v2b_un_nombre_nuevo_en_el_crm_sale_en_decide_en_el_marcador_y_en_la_lista(): void
    {
        Plan::query()->whereKey(3)->update(['name' => 'Plan Ónix']);
        $this->publica('cercano', 320);

        $nombres = array_column($this->decide($this->entrante('hola'), true)['context']['active_plans'], 'name');
        $this->assertContains('Plan Ónix', $nombres);
        $this->assertNotContains('Plan Valera', $nombres);

        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta la mensualidad?', 'El {{PLAN_NAME}} cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?', 3);
        $this->assertSame('El Plan Ónix cuesta $65.000 COP al mes. ¿Le cuento qué incluye?', $r['reply_final']);

        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $this->assertStringStartsWith('Claro. Algunos planes de un mes: Plan Ónix, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP.', (string) $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Qué planes tienen y cuánto cuestan?')['reply_final']);
    }

    public function test_v2c_un_plan_que_deja_de_venderse_o_se_archiva_desaparece_de_decide_y_de_la_lista(): void
    {
        Plan::query()->whereKey(3)->update(['sellable' => false]);
        Plan::query()->whereKey(4)->update(['archived_at' => now()]);
        $this->publica('cercano', 320);

        $ids = array_column($this->decide($this->entrante('hola'), true)['context']['active_plans'], 'id');
        $this->assertNotContains(3, $ids, 'no vendible');
        $this->assertNotContains(4, $ids, 'archivado');

        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $lista = (string) $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Qué planes tienen y cuánto cuestan?')['reply_final'];
        $this->assertSame('Claro. Planes de un mes: Élite, $180.000 COP; PROMO X4, $75.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?', $lista);
    }

    public function test_v2d_el_orden_y_el_plan_por_defecto_los_decide_el_crm(): void
    {
        $this->publica('cercano', 320);
        $this->assertSame(3, $this->decide($this->entrante('hola'), true)['context']['default_plan_id'], 'hoy: Plan Valera, el primero de un mes por sort_order');

        // `sort_order`: el Mensual delante → cambian el plan por defecto («la mensualidad») y el orden de la lista.
        Plan::query()->whereKey(4)->update(['sort_order' => 0]);
        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $this->assertSame(4, $this->decide($this->entrante('hola'), true)['context']['default_plan_id']);
        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $this->assertStringStartsWith('Claro. Algunos planes de un mes: Plan Mensual, $80.000 COP; Plan Valera, $65.000 COP; Élite, $180.000 COP.', (string) $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Qué planes tienen y cuánto cuestan?')['reply_final']);

        // `is_recommended`: el Élite primero en el catálogo y en la lista; el plan por defecto NO cambia (lo decide `sort_order`).
        Plan::query()->whereKey(8)->update(['is_recommended' => true]);
        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $contexto = $this->decide($this->entrante('hola'), true)['context'];
        $this->assertSame(8, $contexto['active_plans'][0]['id']);
        $this->assertSame(4, $contexto['default_plan_id']);
        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $this->assertStringStartsWith('Claro. Algunos planes de un mes: Élite, $180.000 COP; Plan Mensual, $80.000 COP; Plan Valera, $65.000 COP.', (string) $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Qué planes tienen y cuánto cuestan?')['reply_final']);
    }

    /**
     * EL ENCABEZADO VERAZ (encargo del propietario del 19-11, PENDIENTE de su
     * aprobación): «Algunos planes de un mes» solo si quedan planes de un mes
     * vendibles sin nombrar; si se nombran todos, «Planes de un mes».
     */
    public function test_el_encabezado_dice_algunos_solo_si_quedan_planes_de_un_mes_sin_nombrar(): void
    {
        // Producción: cuatro de un mes (Valera, Mensual, Élite y PROMO X4) y se nombran tres. Mide 199 y cabe en 200.
        $this->publica('profesional', 200);
        $r = $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Qué planes tienen y cuánto cuestan?');
        $this->assertSame(self::PRECIOS_200, $r['reply_final']);
        $this->assertSame(199, UltronReplyFitter::measure($r['reply_final']));

        // Tres de un mes exactos (PROMO X4 fuera de la venta): se nombran todos, sin «Algunos».
        Plan::query()->whereKey(14)->update(['sellable' => false]);
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $this->assertSame(
            'Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?',
            $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Qué planes tienen y cuánto cuestan?')['reply_final'],
        );

        // Los mismos tres, pero el tope suelta el tercero (un nombre largo): queda uno sin nombrar y vuelve «Algunos».
        Plan::query()->whereKey(8)->update(['name' => 'Élite Total con Entrenador Personal y Nutrición']);
        $this->nuevaConversacion();
        $this->publica('profesional', 160);
        $this->assertSame(
            'Claro. Algunos planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP. ¿Sobre cuál amplío la información?',
            $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Qué planes tienen y cuánto cuestan?')['reply_final'],
        );
    }

    public function test_v2e_al_modelo_le_llegan_los_planes_sin_precio(): void
    {
        $this->publica('cercano', 320);
        $planes = $this->decide($this->entrante('¿Qué planes tienen?'), true)['context']['active_plans'];

        $this->assertCount(13, $planes, 'todos los vendibles del catálogo, uno a uno');
        foreach ($planes as $p) {
            $this->assertArrayNotHasKey('price', $p);
            $this->assertArrayNotHasKey('original_price', $p);
        }
        $this->assertDoesNotMatchRegularExpression('/\d{2,3}[.,]?\d{3}/', json_encode($planes, JSON_UNESCAPED_UNICODE), 'ninguna cifra de dinero');
    }

    public function test_v2f_un_catalogo_sintetico_sale_tal_cual_nada_esta_escrito_a_mano(): void
    {
        // Ninguno de estos nombres ni de estas cifras existe en el código, los prompts ni n8n.
        Plan::query()->update(['sellable' => false]);
        $ids = [];
        // Ids propios: en PostgreSQL la secuencia no avanzó con los ids explícitos del catálogo de producción.
        foreach ([['Plan Zafiro', 123456, 30], ['Plan Ámbar', 98765, 30], ['Membresía Cuarzo', 234567, 90]] as $i => [$nombre, $precio, $dias]) {
            $plan = (new Plan)->forceFill([
                'id' => 901 + $i, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias, 'active' => true, 'sellable' => true,
                'sort_order' => 100 + $i, 'is_recommended' => false, 'benefits' => json_encode(['Acceso total al gimnasio']),
            ]);
            $plan->save();
            $ids[] = (int) $plan->id;
        }
        $this->publica('cercano', 320);

        $contexto = $this->decide($this->entrante('hola'), true)['context'];
        $this->assertSame(['Plan Zafiro', 'Plan Ámbar', 'Membresía Cuarzo'], array_column($contexto['active_plans'], 'name'));
        $this->assertSame($ids[0], $contexto['default_plan_id']);

        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $this->assertSame(
            'Claro. Planes de un mes: Plan Zafiro, $123.456 COP; Plan Ámbar, $98.765 COP. También hay planes de varios meses. ¿Sobre cuál amplío la información?',
            $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Qué planes tienen y cuánto cuestan?')['reply_final'],
        );

        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Ámbar?', 'El {{PLAN_NAME}} cuesta {{PLAN_PRICE}} y dura {{PLAN_DURATION}}. ¿Le cuento qué incluye?', $ids[1]);
        $this->assertSame('El Plan Ámbar cuesta $98.765 COP y dura un mes. ¿Le cuento qué incluye?', $r['reply_final']);

        // Con un tercero y un cuarto de un mes sintéticos, se nombran tres y el encabezado dice «Algunos».
        foreach ([['Plan Ópalo', 111111, 904, 103], ['Plan Topacio', 222222, 905, 104]] as [$nombre, $precio, $id, $orden]) {
            (new Plan)->forceFill([
                'id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => 30, 'active' => true, 'sellable' => true,
                'sort_order' => $orden, 'is_recommended' => false, 'benefits' => json_encode(['Acceso total al gimnasio']),
            ])->save();
        }
        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $this->assertSame(
            'Claro. Algunos planes de un mes: Plan Zafiro, $123.456 COP; Plan Ámbar, $98.765 COP; Plan Ópalo, $111.111 COP. También hay planes de varios meses. ¿Sobre cuál amplío la información?',
            $this->criticoCaido(SalesIntents::PRICING_QUESTION, '¿Qué planes tienen y cuánto cuestan?')['reply_final'],
        );
        Plan::query()->whereKey([904, 905])->update(['sellable' => false]);

        // Y con el tope de la canaria (200), el mismo catálogo: la lista cabe y no inventa ni oculta.
        $this->nuevaConversacion();
        $this->publica('profesional', 200);
        $this->assertSame(
            'Claro. Planes de un mes: Plan Zafiro, $123.456 COP; Plan Ámbar, $98.765 COP. También hay planes de varios meses. ¿Sobre cuál amplío la información?',
            $this->criticoCaido(SalesIntents::GENERAL_INFO, '¿Qué planes tienen?')['reply_final'],
        );
    }

    // ── 14 · Correcciones tras las revisiones (reviewer A4b y security A4b) ──
    //
    // G1 (B-1, S-4, I-1, I-2, I-3): cuándo sale la lista por panorama.
    // G2 (B-2): el recorte por registro no deja respuestas vacías.
    // G3 (punto 9 y m-7): tras un descarte por registro, el texto curado de la intención, de usted.
    // G4 (I-4): la memoria dice lo que salió.
    // G5 (I-6): el encabezado mixto no presenta una lista parcial como completa.
    // G7 (S-7, m-6): los detectores de usted, con construcciones.
    // G8 (I-5, m-8): el texto EXACTO de usted en cada punto del commit que aplica la tabla.

    /**
     * Las entradas que NO piden la lista (B-1, S-4, I-3), con un borrador
     * correcto de usted que el revisor aprobó: sale ESE borrador, nunca la
     * lista, con cualquier versión del Studio. [intención, entrante, borrador,
     * plan, campos de la propuesta, lo que sale].
     *
     * @return array<string, array{string, string, string, ?int, array<string,mixed>, string}>
     */
    public static function noEsPanoramaPorElEndpoint(): array
    {
        $revision = ['tools_requested' => [SalesIntents::TOOL_STAFF_REVIEW]];

        return [
            'opciones de pago (B-1)' => [SalesIntents::PAYMENT_LINK_REQUEST, '¿Qué opciones de pago tienen?',
                'Puede pagar desde la app Iron Body Workout con Nequi, PSE, tarjeta o Daviplata, o directamente en el gimnasio. ¿Le paso la app?', null, [],
                'Puede pagar desde la app Iron Body Workout con Nequi, PSE, tarjeta o Daviplata, o directamente en el gimnasio. ¿Le paso la app?'],
            'la negativa (B-1)' => [SalesIntents::NOT_INTERESTED, 'No me interesan los planes, gracias',
                'Entendido, no hay problema. Si más adelante quiere resolver dudas, aquí estamos.', null, [],
                'Entendido, no hay problema. Si más adelante quiere resolver dudas, aquí estamos.'],
            'un asesor (B-1)' => [SalesIntents::HUMAN_REQUEST, 'Quiero hablar con un asesor sobre los planes',
                'Claro, dejo marcada su solicitud para que alguien del equipo la revise.', null, $revision,
                'Claro, dejo marcada su solicitud para que alguien del equipo la revise.'],
            'la recomendación (B-1, R6)' => [SalesIntents::GOAL_FAT_LOSS, '¿Cuál de los planes me recomienda para bajar de peso?',
                'Para bajar de peso le recomiendo el Élite, porque incluye acompañamiento cercano.', 8, [],
                'Para bajar de peso le recomiendo el Élite, porque incluye acompañamiento cercano.'],
            'opciones de horario (B-1)' => [SalesIntents::SCHEDULE_QUESTION, '¿Qué opciones de horario hay?',
                'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m., y sábados y domingos de 8:00 a. m. a 2:00 p. m.', null, [],
                'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m., y sábados y domingos de 8:00 a. m. a 2:00 p. m.'],
            'una lesión y los planes (B-1)' => [SalesIntents::MEDICAL_RISK_ESCALATION, 'Tengo una lesión de rodilla, ¿qué planes tienen?',
                'Gracias por contarlo. Con una lesión es mejor que el equipo revise su caso antes de recomendarle un plan.', null, $revision,
                'Gracias por contarlo. Con una lesión es mejor que el equipo revise su caso antes de recomendarle un plan.'],
            'el horario y los planes (I-3)' => [SalesIntents::PRICING_QUESTION, '¿Cuál es el horario y cuánto cuestan los planes?',
                'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. El Plan Valera cuesta {{PLAN_PRICE}} al mes.', 3, [],
                'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. El Plan Valera cuesta $65.000 COP al mes.'],
            'un pago reclamado (S-4)' => [SalesIntents::FRAUD_OR_PAYMENT_CLAIM, 'Ya pagué uno de los planes y no me llega nada',
                'Entiendo, lo dejo marcado para que el equipo revise su caso de pago con cuidado.', null, $revision,
                'Entiendo, lo dejo marcado para que el equipo revise su caso de pago con cuidado.'],
            'una queja (S-4)' => [SalesIntents::COMPLAINT, 'Quiero poner una queja por los planes, me cobraron de más',
                'Entiendo. Lo dejo marcado como caso para revisión del equipo.', null, $revision,
                'Entiendo. Lo dejo marcado como caso para revisión del equipo.'],
            'el agradecimiento (S-4)' => [SalesIntents::THANKS, 'Gracias, ya vi los planes',
                'Con gusto. Si más adelante quiere empezar, me escribe y le ayudo.', null, [],
                'Con gusto. Si más adelante quiere empezar, me escribe y le ayudo.'],
        ];
    }

    #[DataProvider('noEsPanoramaPorElEndpoint')]
    public function test_g1_lo_que_no_pide_la_lista_recibe_el_borrador_con_cercano_y_con_profesional(string $intent, string $texto, string $borrador, ?int $plan, array $extra, string $sale): void
    {
        foreach (['cercano', 'profesional'] as $tono) {
            $this->nuevaConversacion();
            $this->publica($tono, 200);
            $r = $this->aprobadoCon($intent, $texto, $borrador, $plan, $extra);

            $this->assertSame($sale, $r['reply_final'], "{$tono}: «{$texto}»");
            $this->assertNotContains(CommercialTurnPolicy::VIOLACION_PANORAMA, (array) ($this->ultimaAccion()->metadata['policy_violations'] ?? []), $tono);
        }
    }

    #[DataProvider('noEsPanoramaPorElEndpoint')]
    public function test_g1_lo_que_no_pide_la_lista_tampoco_la_recibe_con_el_revisor_caido(string $intent, string $texto, string $borrador, ?int $plan, array $extra, string $sale): void
    {
        foreach (['cercano', 'profesional'] as $tono) {
            $this->nuevaConversacion();
            $this->publica($tono, 200);
            $r = $this->caidoCon($intent, $texto, $extra);

            if ($intent === SalesIntents::PRICING_QUESTION) {
                // La lista por intención de precios ya estaba desplegada (D-6) y NO cambia.
                $this->assertSame(self::PRECIOS_200, $r['reply_final'], $tono);

                continue;
            }
            $this->assertStringNotContainsString('planes de un mes', (string) $r['reply_final'], "{$tono}: «{$texto}» → «{$r['reply_final']}»");
            $this->assertStringNotContainsString('$', (string) $r['reply_final'], $tono);
        }
    }

    /**
     * S-4 · un relevo autorizado: la persona tiene que leer que la van a
     * atender, no la lista de precios. Con cercano y con profesional.
     */
    public function test_g1_un_relevo_autorizado_no_recibe_la_lista(): void
    {
        $borrador = 'Claro, ya dejé su solicitud para que una persona del equipo le atienda por aquí.';
        foreach (['cercano', 'profesional'] as $tono) {
            $this->nuevaConversacion();
            $this->publica($tono, 200);
            $r = $this->aprobadoCon(SalesIntents::HUMAN_REQUEST, 'Quiero hablar con una persona del equipo sobre los planes', $borrador, null, [
                'tools_requested' => [SalesIntents::TOOL_STAFF_REVIEW],
                'human_handoff_requested' => true, 'human_handoff_reason' => 'explicit_human_request',
            ]);

            $this->assertSame($borrador, $r['reply_final'], $tono);
            $this->assertTrue((bool) ($this->ultimaAccion()->metadata['handoff_authorized'] ?? false), "{$tono}: el relevo se autorizó");

            // Y con el revisor caído (sin relevo: la rendición no lo ejecuta), tampoco la lista.
            $this->nuevaConversacion();
            $this->publica($tono, 200);
            $caido = (string) $this->caidoCon(SalesIntents::HUMAN_REQUEST, 'Quiero hablar con una persona del equipo sobre los planes', ['tools_requested' => [SalesIntents::TOOL_STAFF_REVIEW]])['reply_final'];
            $this->assertStringNotContainsString('$', $caido, "{$tono}, revisor caído: «{$caido}»");
        }
    }

    /**
     * S-4 y R7 · la cortesía recién registrada: «quiero ir el sábado a las 10,
     * ¿y qué planes tienen?» con la cita ejecutada. La persona lee que su
     * visita quedó registrada (el borrador que lo dice), no la lista.
     */
    public function test_g1_una_cortesia_recien_registrada_no_recibe_la_lista(): void
    {
        $borrador = 'Listo, quedó registrada su solicitud de visita para el sábado a las 10:00 a. m.; el equipo la revisará. El Plan Valera es una buena opción para empezar.';
        foreach (['cercano', 'profesional'] as $tono) {
            $this->nuevaConversacion();
            $this->publica($tono, 200);
            $r = $this->conCortesiaY('quiero ir el sábado a las 10 de la mañana, ¿y qué planes tienen?', $borrador);

            $this->assertSame($borrador, $r['reply_final'], $tono);
            $this->assertContains(SalesIntents::TOOL_COURTESY_REQUEST, $r['applied']['tools_executed'], $tono);

            // Y con el revisor caído, el mismo mensaje tampoco recibe la lista (habla de una visita: G1.4).
            $this->nuevaConversacion();
            $this->publica($tono, 200);
            $caido = (string) $this->caidoCon(SalesIntents::GENERAL_INFO, 'quiero ir el sábado a las 10 de la mañana, ¿y qué planes tienen?', [])['reply_final'];
            $this->assertStringNotContainsString('$', $caido, "{$tono}, revisor caído: «{$caido}»");
        }
    }

    /**
     * G1.2 · NINGUNA HERRAMIENTA EN EL TURNO, aislada: el mensaje sí pide el
     * panorama, la intención está en la lista blanca y no hay otro tema; pero
     * el turno lleva los enlaces de la app. Sale el borrador con sus enlaces.
     */
    public function test_g1_con_una_herramienta_en_el_turno_no_hay_lista_por_panorama(): void
    {
        $borrador = 'Tenemos el Plan Valera, con acceso total al gimnasio.';
        foreach (['cercano', 'profesional'] as $tono) {
            $this->nuevaConversacion();
            $this->publica($tono, 320);
            $r = $this->aprobadoCon(SalesIntents::GENERAL_INFO, '¿Qué planes tienen?', $borrador, null, ['tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND]]);

            $this->assertStringStartsWith($borrador, (string) $r['reply_final'], $tono);
            $this->assertStringContainsString('https://', (string) $r['reply_final'], "{$tono}: los enlaces que se pidieron");
            $this->assertNotContains(CommercialTurnPolicy::VIOLACION_PANORAMA, (array) ($this->ultimaAccion()->metadata['policy_violations'] ?? []));
        }
    }

    /** @return array<string, array{string, string}> */
    public static function panoramasNuevos(): array
    {
        return [
            '¿Cuáles son los precios?' => [SalesIntents::PRICING_QUESTION, '¿Cuáles son los precios?'],
            '¿Qué precios manejan?' => [SalesIntents::PRICING_QUESTION, '¿Qué precios manejan?'],
            'Info de precios' => [SalesIntents::PRICING_QUESTION, 'Info de precios'],
            'las tarifas' => [SalesIntents::PRICING_QUESTION, 'Las tarifas'],
            '¿Cuánto valen las membresías?' => [SalesIntents::PRICING_QUESTION, '¿Cuánto valen las membresías?'],
            '¿Tienen planes?' => [SalesIntents::GENERAL_INFO, '¿Tienen planes?'],
            // El mensaje entero es solo eso (añadido del lead, 19-11): la forma más corriente por WhatsApp.
            'Precios?' => [SalesIntents::PRICING_QUESTION, 'Precios?'],
            '¿Y los planes?' => [SalesIntents::GENERAL_INFO, '¿Y los planes?'],
        ];
    }

    /** I-2 · las formas corrientes de pedir el panorama reciben la lista, no el plan único del modelo (R2). */
    #[DataProvider('panoramasNuevos')]
    public function test_g1_i2_las_formas_corrientes_del_panorama_reciben_la_lista(string $intent, string $texto): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado($intent, $texto, 'El Plan Valera cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?', 3);

        $this->assertSame(self::PRECIOS_200, $r['reply_final'], $texto);
        $this->assertContains(CommercialTurnPolicy::VIOLACION_PANORAMA, $this->ultimaAccion()->metadata['policy_violations']);
        $this->assertNull($r['price_source']);
    }

    /**
     * I-1 · el panorama va con CUALQUIER versión del Studio, no solo con
     * «profesional» (decisión del lead): con «cercano», la lista. Mata M8.
     */
    public function test_g1_i1_el_panorama_tambien_con_cercano(): void
    {
        $this->publica('cercano', 200);
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, '¿Qué planes tienen?', 'Tenemos el Plan Valera, con acceso total al gimnasio. ¿Te cuento cómo empezar?', null);
        $this->assertSame(self::PRECIOS_200, $r['reply_final']);
        $this->assertContains(CommercialTurnPolicy::VIOLACION_PANORAMA, $this->ultimaAccion()->metadata['policy_violations']);

        $this->nuevaConversacion();
        $this->publica('cercano', 200);
        $this->assertSame(self::PRECIOS_200, $this->criticoCaido(SalesIntents::GENERAL_INFO, '¿Qué planes tienen?')['reply_final'], 'y con el revisor caído');
    }

    /**
     * B-2 · los cinco borradores de la tabla del revisor (R3), profesional
     * 200: quitar la pregunta final que tutea dejaba una sola frase de
     * relleno. Ahora se descarta y sale el respaldo de la intención.
     *
     * @return array<string, array{string, string, string, bool, string, string}>
     */
    public static function recortesQueDejabanRelleno(): array
    {
        return [
            'clases de spinning' => [SalesIntents::GENERAL_INFO, '¿Tienen clases de spinning?', 'Claro. ¿Quieres que te cuente qué clases tenemos esta semana?', true, 'discarded',
                'Con gusto le cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. ¿Qué le cuento más a fondo: los planes, las clases o cómo empezar?'],
            'pagar con tarjeta' => [SalesIntents::PAYMENT_LINK_REQUEST, '¿Puedo pagar con tarjeta?', 'Sí, claro. ¿Quieres que te pase la app para pagar desde ahí?', false, 'discarded',
                'El pago lo hace usted mismo desde la app Iron Body Workout: crea su cuenta con su documento y ahí paga con Nequi, PSE, tarjeta o Daviplata. ¿Quiere que le pase la app para descargarla?'],
            'el saludo' => [SalesIntents::GREETING, 'Hola', '¡Hola, buenas tardes! ¿En qué te puedo ayudar hoy?', false, 'discarded',
                'Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderle. Cuénteme, ¿en qué le podemos ayudar hoy?'],
            'el miedo de principiante' => [SalesIntents::BEGINNER_FEAR, 'Nunca he entrenado y me da pena', 'Es muy normal al inicio. ¿Qué es lo que más te preocupa?', false, 'discarded',
                'Es normal sentir pena al inicio. A muchas personas les pasa cuando no conocen el ambiente o las máquinas. ¿Qué le preocupa más: venir solo o no saber qué hacer?'],
            // G2-bis: dos frases tras quitar la pregunta ya no bastan; sin enlaces ni cobro se descarta, y sale la ficha (antes, `trimmed` y la rescataba la ficha por `policy_answer_without_fact`).
            'hola, quisiera información' => [SalesIntents::GENERAL_INFO, 'Hola, quisiera información', '¡Hola! Con gusto. ¿Qué te gustaría saber?', true, 'discarded',
                'Con gusto le cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. ¿Qué le cuento más a fondo: los planes, las clases o cómo empezar?'],
        ];
    }

    #[DataProvider('recortesQueDejabanRelleno')]
    public function test_g2_el_recorte_por_registro_no_deja_una_sola_frase_de_relleno(string $intent, string $texto, string $borrador, bool $ficha, string $accion, string $sale): void
    {
        if ($ficha) {
            $this->fichaDeProduccion();
        }
        $this->publica('profesional', 200);
        $r = $this->aprobado($intent, $texto, $borrador, null);

        $this->assertSame($sale, $r['reply_final']);
        $this->assertSame($accion, $this->ultimaAccion()->metadata['register']['action']);
        $this->assertGreaterThanOrEqual(2, count(UltronReplyFitter::sentences((string) $r['reply_final'])), 'nunca una sola frase de relleno');
        $this->assertSame([], UltronRegisterDetector::tuteo((string) $r['reply_final']));
    }

    /**
     * G2-bis: con dos frases tras quitar la pregunta, sin enlaces ni cobro, ya
     * no se recorta: se descarta y sale el horario de la ficha, de usted.
     * Antes (G2) salía «Claro. El gimnasio abre de lunes a viernes de 5:00 a.
     * m. a 10:00 p. m.», `trimmed`; el respaldo dice lo mismo y más.
     */
    public function test_g2bis_con_dos_frases_tras_quitar_la_pregunta_tambien_se_descarta_y_sale_el_horario(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::SCHEDULE_QUESTION, self::REAL_HORARIO_ENTRANTE, 'Claro. El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Te sirve ese horario?', null);

        $this->assertSame(self::HORARIO_200, $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertSame('discarded', $fila->metadata['register']['action']);
        $this->assertSame([UltronCommitService::VIOLACION_REGISTRO], $fila->metadata['policy_violations']);
    }

    // ── G2-bis (B-2, segunda corrección): con «profesional», el borrador que tutea se descarta siempre ──

    /**
     * Las siete filas del punto 2 del apéndice del revisor (19-11, commit
     * `3eca6206`; la de spinning, con y sin la ficha, como la midió): con G2,
     * quitada la pregunta que tutea quedaban dos frases y salían, pero las dos
     * eran apertura o cortesía. Ahora, sin enlaces ni cobro en el turno, el
     * borrador que tutea se descarta y sale el respaldo de usted de su
     * intención. [intención, entrante, borrador, ficha, el
     * relleno que salía con G2, lo que sale a 200, lo que sale sin tope (320)
     * si es distinto: el mismo respaldo, sin recortar].
     *
     * @return array<string, array{string, string, string, bool, string, string, ?string}>
     */
    public static function rellenoEnDosFrases(): array
    {
        $bienvenida = 'Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderle. Cuénteme, ¿en qué le podemos ayudar hoy?';
        $ficha = 'Con gusto le cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. ¿Qué le cuento más a fondo: los planes, las clases o cómo empezar?';

        return [
            'hola, buenas tardes' => [SalesIntents::GREETING, 'Hola', '¡Hola! Buenas tardes. ¿En qué te puedo ayudar hoy?', false, '¡Hola! Buenas tardes.', $bienvenida, null],
            'hola, bienvenido' => [SalesIntents::GREETING, 'Hola', '¡Hola! Bienvenido a Iron Body. ¿En qué te puedo ayudar hoy?', false, '¡Hola! Bienvenido a Iron Body.', $bienvenida, null],
            'hola, con gusto le atiendo' => [SalesIntents::GREETING, 'Hola', '¡Hola! Con gusto le atiendo. ¿En qué te puedo ayudar?', false, '¡Hola! Con gusto le atiendo.', $bienvenida, null],
            'pagar con tarjeta' => [SalesIntents::PAYMENT_LINK_REQUEST, '¿Puedo pagar con tarjeta?', 'Sí, claro. Con mucho gusto. ¿Quieres que te pase la app para pagar desde ahí?', false, 'Sí, claro. Con mucho gusto.',
                'El pago lo hace usted mismo desde la app Iron Body Workout: crea su cuenta con su documento y ahí paga con Nequi, PSE, tarjeta o Daviplata. ¿Quiere que le pase la app para descargarla?',
                'El pago lo hace usted mismo desde la app Iron Body Workout: crea su cuenta con su documento y ahí paga con Nequi, PSE, tarjeta o Daviplata. También puede pagar en el gimnasio cuando venga. ¿Quiere que le pase la app para descargarla?'],
            'clases de spinning, sin la ficha' => [SalesIntents::GENERAL_INFO, '¿Tienen clases de spinning?', '¡Claro! Con gusto. ¿Quieres que te cuente qué clases tenemos?', false, '¡Claro! Con gusto.',
                'Con gusto le cuento. ¿Qué le cuento más a fondo: los planes, las clases o cómo empezar?',
                'Con gusto le cuento. Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿Qué le cuento más a fondo: los planes, las clases o cómo empezar?'],
            'clases de spinning, con la ficha' => [SalesIntents::GENERAL_INFO, '¿Tienen clases de spinning?', '¡Claro! Con gusto. ¿Quieres que te cuente qué clases tenemos?', true, '¡Claro! Con gusto.', $ficha,
                'Con gusto le cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. Iron Body Neiva está ubicado en Cl. 24 Sur #33-53, Neiva, Huila. Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿Qué le cuento más a fondo: los planes, las clases o cómo empezar?'],
            'me da pena' => [SalesIntents::BEGINNER_FEAR, 'Nunca he entrenado y me da pena', 'Claro. Es muy normal. ¿Qué es lo que más te preocupa?', false, 'Claro. Es muy normal.',
                'Es normal sentir pena al inicio. A muchas personas les pasa cuando no conocen el ambiente o las máquinas. ¿Qué le preocupa más: venir solo o no saber qué hacer?', null],
            'bajar de peso' => [SalesIntents::GOAL_FAT_LOSS, 'Quiero bajar de peso', '¡Qué bien! Con gusto. ¿Ya has entrenado antes?', false, '¡Qué bien! Con gusto.',
                'Muy bien. Para bajar grasa lo más importante es empezar con algo que pueda sostener. ¿Ya viene entrenando o empieza desde cero?', null],
        ];
    }

    #[DataProvider('rellenoEnDosFrases')]
    public function test_g2bis_un_borrador_que_tutea_sin_enlaces_ni_cobro_se_descarta_siempre(string $intent, string $texto, string $borrador, bool $ficha, string $relleno, string $sale200, ?string $sinTope): void
    {
        if ($ficha) {
            $this->fichaDeProduccion();
        }
        foreach ([200 => $sale200, 320 => $sinTope ?? $sale200] as $tope => $sale) {
            $this->nuevaConversacion();
            $this->publica('profesional', $tope);
            $r = $this->aprobado($intent, $texto, $borrador, null);

            $this->assertSame($sale, $r['reply_final'], "a {$tope}");
            $this->assertSame($sale, $this->ultimoSaliente()?->body, "a {$tope}: el mensaje que sale");
            $this->assertNotSame($relleno, $r['reply_final'], "a {$tope}: nunca solo la apertura o la cortesía");
            $fila = $this->ultimaAccion();
            $this->assertSame('discarded', $fila->metadata['register']['action'], "a {$tope}");
            $this->assertContains(UltronCommitService::VIOLACION_REGISTRO, $fila->metadata['policy_violations'], "a {$tope}");
            $this->assertArrayNotHasKey('fallback_mismatch', $fila->metadata['register'], "a {$tope}: sale de usted, la última red no actúa");
            $this->assertSame([], UltronRegisterDetector::tuteo((string) $r['reply_final']), "a {$tope}");
        }

        // Con `cercano` y `entusiasta` el registro no se mira: ni traza ni violación.
        foreach (['cercano', 'entusiasta'] as $tono) {
            $this->nuevaConversacion();
            $this->publica($tono, 200);
            $this->aprobado($intent, $texto, $borrador, null);
            $fila = $this->ultimaAccion();
            $this->assertArrayNotHasKey('register', $fila->metadata, $tono);
            $this->assertNotContains(UltronCommitService::VIOLACION_REGISTRO, (array) ($fila->metadata['policy_violations'] ?? []), $tono);
        }
    }

    /**
     * La regresión de G2 que vio el revisor: «Mándame la app» con
     * `app_links_send` y un borrador que tutea solo en su pregunta final. Con
     * los enlaces que pega Laravel, «una frase + los enlaces» es una respuesta
     * completa: se quita la pregunta y sale con los enlaces, sin mínimo de
     * frases. Con G2 se descartaba y la persona se quedaba sin los enlaces.
     */
    public function test_g2bis_con_los_enlaces_de_la_app_se_quita_la_pregunta_y_salen_los_enlaces(): void
    {
        foreach ([200, 320] as $tope) {
            $this->nuevaConversacion();
            $this->publica('profesional', $tope);
            $r = $this->aprobado(SalesIntents::GENERAL_INFO, 'Mándame la app', 'Claro, con gusto. ¿Quieres que te ayude con algo más?', null, [SalesIntents::TOOL_APP_LINKS_SEND]);

            $this->assertSame("Claro, con gusto.\n\n".MobileAppCatalog::linksInline(), $r['reply_final'], "a {$tope}");
            $this->assertSame($r['reply_final'], $this->ultimoSaliente()?->body, "a {$tope}: el mensaje que sale");
            $this->assertContains(SalesIntents::TOOL_APP_LINKS_SEND, $r['applied']['tools_executed'], "a {$tope}: y constan como enviados");
            $fila = $this->ultimaAccion();
            $this->assertSame('trimmed', $fila->metadata['register']['action'], "a {$tope}");
            $this->assertNotContains(UltronCommitService::VIOLACION_REGISTRO, (array) ($fila->metadata['policy_violations'] ?? []), "a {$tope}");
        }
    }

    /**
     * Con los enlaces y el tuteo en el CUERPO no hay recorte posible: se
     * descarta, y los enlaces no salen ni constan como enviados. Es el
     * comportamiento conocido de cualquier descarte (anterior a esta ronda);
     * aquí se fija tal como es.
     */
    public function test_g2bis_con_los_enlaces_y_el_tuteo_en_el_cuerpo_se_descarta_y_los_enlaces_no_salen(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, 'Mándame la app', 'Claro, aquí tienes la app para descargarla. ¿Quieres que te ayude con algo más?', null, [SalesIntents::TOOL_APP_LINKS_SEND]);

        $this->assertStringNotContainsString('https://', (string) $r['reply_final']);
        $this->assertSame([], UltronRegisterDetector::tuteo((string) $r['reply_final']));
        $this->assertNotContains(SalesIntents::TOOL_APP_LINKS_SEND, $r['applied']['tools_executed']);
        $fila = $this->ultimaAccion();
        $this->assertSame('discarded', $fila->metadata['register']['action']);
        $this->assertContains(UltronCommitService::VIOLACION_REGISTRO, $fila->metadata['policy_violations']);
    }

    /**
     * El bloque cuenta si SIGUE en el texto que se mira, no solo si su
     * herramienta se ejecutó: aquí los enlaces se pegan en el marcador, dentro
     * de una frase que nombra una clase que no existe, y la guarda de los
     * servicios inventados retira esa frase (con los enlaces) antes del
     * registro. Queda «Claro, con gusto.» y la pregunta que tutea: sin el
     * bloque, se descarta; recortar dejaría solo la cortesía.
     */
    public function test_g2bis_si_una_guarda_retiro_los_enlaces_antes_del_registro_se_descarta(): void
    {
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, 'Mándame la app', 'Claro, con gusto. Le paso la app para sus clases de pilates: {{APP_LINKS}}. ¿Quieres que te ayude con algo más?', null, [SalesIntents::TOOL_APP_LINKS_SEND]);

        $this->assertNotSame('Claro, con gusto.', $r['reply_final'], 'nunca solo la cortesía');
        $this->assertStringNotContainsString('https://', (string) $r['reply_final']);
        $this->assertSame([], UltronRegisterDetector::tuteo((string) $r['reply_final']));
        $this->assertNotContains(SalesIntents::TOOL_APP_LINKS_SEND, $r['applied']['tools_executed']);
        $fila = $this->ultimaAccion();
        $this->assertSame('discarded', $fila->metadata['register']['action']);
        $this->assertContains(UltronCommitService::VIOLACION_REGISTRO, $fila->metadata['policy_violations']);
    }

    /**
     * Lo mismo con el cobro que pega Laravel dentro de la respuesta (13.ter,
     * cobro del canario disponible): se quita la pregunta que tutea y sale la
     * frase con su línea de cobro, de usted, y el enlace.
     */
    public function test_g2bis_con_el_cobro_dentro_se_quita_la_pregunta_y_sale_el_cobro(): void
    {
        config()->set('wompi.env', 'production');
        config()->set('wompi.public_key', 'pub_prod_test_key');
        config()->set('wompi.private_key', 'prv_prod_test_key');
        config()->set('wompi.integrity_secret', 'integrity_test_secret');
        config()->set('wompi.events_secret', 'events_test_secret');
        config()->set('wompi.checkout.base_url', 'https://checkout.wompi.co/p/');
        config()->set('wompi.checkout.redirect_url', 'https://web.ironbodyneiva.cloud/pago');
        config()->set('marketing.ultron.payment_canary_conversation_id', $this->conversation->id);
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::PAYMENT_LINK_REQUEST, 'Quiero pagar el Plan Mensual', 'Perfecto, con gusto. ¿Quieres que te ayude con algo más?', 4, [SalesIntents::TOOL_PAYMENT_LINK_SEND]);

        $this->assertStringStartsWith('Perfecto, con gusto.'.U::LINEA_DEL_COBRO."80.000:\nhttps://checkout.wompi.co/p/", (string) $r['reply_final']);
        $this->assertSame($r['reply_final'], $this->ultimoSaliente()?->body, 'el mensaje que sale');
        $this->assertContains(SalesIntents::TOOL_PAYMENT_LINK_SEND, $r['applied']['tools_executed']);
        $fila = $this->ultimaAccion();
        $this->assertSame('trimmed', $fila->metadata['register']['action']);
        $this->assertNotContains(UltronCommitService::VIOLACION_REGISTRO, (array) ($fila->metadata['policy_violations'] ?? []));
    }

    /**
     * G3 · tras un descarte por REGISTRO, la intención sin respaldo propio
     * recibe su texto curado de usted (antes: el último recurso genérico, que
     * no contesta). [intención, entrante, borrador que tutea, campos, tope, lo que sale].
     *
     * @return array<string, array{string, string, string, array<string,mixed>, int, string}>
     */
    public static function descartesPorRegistro(): array
    {
        return [
            'un objetivo (R5)' => [SalesIntents::GOAL_FAT_LOSS, 'Quiero bajar de peso',
                'Para bajar de peso te recomiendo el Plan Mensual, que cuesta {{PLAN_PRICE}} e incluye acompañamiento. ¿Quieres que te cuente cómo empezar?', ['recommended_plan_id' => 4], 200,
                'Muy bien. Para bajar grasa lo más importante es empezar con algo que pueda sostener. ¿Ya viene entrenando o empieza desde cero?'],
            'el miedo de principiante' => [SalesIntents::BEGINNER_FEAR, 'Me da miedo empezar, nunca he ido a un gimnasio',
                'Tranquilo, te entiendo: muchos empiezan así y el equipo te acompaña desde el primer día. ¿Qué es lo que más te preocupa?', [], 200,
                'Es normal sentir pena al inicio. A muchas personas les pasa cuando no conocen el ambiente o las máquinas. ¿Qué le preocupa más: venir solo o no saber qué hacer?'],
            'una objeción' => [SalesIntents::TIME_OBJECTION, 'No tengo tiempo para entrenar',
                'Te entiendo, el tiempo es lo más difícil. Con tres días a la semana ya notas el cambio. ¿Cuántos días tienes libres?', [], 200,
                'Le entiendo. A veces la clave es armar algo realista, no perfecto. ¿Cuántos días a la semana cree que podría venir sin complicarse?'],
            'pedir una persona, con su revisión ejecutada' => [SalesIntents::HUMAN_REQUEST, 'Quiero hablar con una persona',
                'Claro, dejo marcada tu solicitud para que alguien del equipo la revise.', ['tools_requested' => [SalesIntents::TOOL_STAFF_REVIEW]], 200,
                'Claro, dejo marcada su solicitud para que alguien del equipo la revise. Igual sigo por aquí si quiere que le ayude con precios, ubicación o planes.'],
            'el horario sin tope (m-7)' => [SalesIntents::SCHEDULE_QUESTION, self::REAL_HORARIO_ENTRANTE, self::REAL_HORARIO_BORRADOR, [], 320,
                'Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja le queda mejor entrenar?'],
        ];
    }

    #[DataProvider('descartesPorRegistro')]
    public function test_g3_tras_un_descarte_por_registro_sale_el_texto_curado_de_la_intencion(string $intent, string $texto, string $borrador, array $extra, int $tope, string $sale): void
    {
        $this->publica('profesional', $tope);
        $r = $this->aprobadoCon($intent, $texto, $borrador, null, $extra);

        $this->assertSame($sale, $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertContains(UltronCommitService::VIOLACION_REGISTRO, $fila->metadata['policy_violations']);
        $this->assertSame('discarded', $fila->metadata['register']['action']);
        $this->assertArrayNotHasKey('fallback_mismatch', $fila->metadata['register'], 'sale de usted: la última red no actúa');
    }

    /**
     * G3 en una intención sensible: su texto curado promete la marca para el
     * equipo («le dejo marcada la solicitud»). En el camino normal la pone la
     * revisión que el guardarraíl añade a toda intención sensible
     * (`needs_staff_review` → `staff_review`), así que el texto es verdad.
     */
    public function test_g3_una_intencion_sensible_recibe_su_texto_curado_porque_la_marca_esta_puesta(): void
    {
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::INVOICE_REQUEST, 'Necesito factura', 'Claro, te ayudo con la factura. ¿Me pasas tus datos?', null);

        $this->assertSame('Claro. Para factura necesito que el equipo confirme los datos correctos para no cometer errores. Le dejo marcada la solicitud de facturación. Si quiere, también puedo ayudarle con información de planes o ubicación.', $r['reply_final']);
        $this->assertTrue((bool) $this->conversation->fresh()->staff_review_pending, 'la marca que el texto promete está puesta');
    }

    /**
     * Y si la marca NO estuviera puesta (no se alcanza hoy por el endpoint: el
     * guardarraíl la pone siempre en las cinco), el texto curado que la
     * promete no sale: lo que `safeFallback()` garantiza levantando la marca,
     * aquí se garantiza no prometiéndola.
     */
    public function test_g3_sin_la_marca_puesta_no_sale_un_texto_que_la_promete(): void
    {
        $this->publica('profesional', 200);
        $this->criticoCaido(SalesIntents::GREETING, 'hola');
        $this->conversation->forceFill(['staff_review_pending' => false])->save();

        $curado = new \ReflectionMethod(UltronCommitService::class, 'textoCuradoTrasElRegistro');
        $turno = ['studio' => true, 'tope' => 200, 'tono' => 'profesional'];
        foreach (array_keys(StaffReviewAuthority::POR_INTENCION) as $intent) {
            $this->assertNull($curado->invoke(app(UltronCommitService::class), $this->conversation->fresh(), $intent, ['intent' => $intent], $turno, 'x'), $intent);
        }
        $this->assertNotNull($curado->invoke(app(UltronCommitService::class), $this->conversation->fresh(), SalesIntents::BEGINNER_FEAR, ['intent' => SalesIntents::BEGINNER_FEAR], $turno, 'x'), 'una intención que no promete nada, sí');
    }

    /** G3 · el descarte por LONGITUD sigue como está desplegado: el último recurso. */
    public function test_g3_el_descarte_por_longitud_no_cambia(): void
    {
        $this->publica('profesional', 160);
        $largo = 'Para bajar de peso lo mejor es combinar entrenamiento de fuerza con cardio y cuidar la alimentación. Con constancia se notan cambios en pocas semanas, y el equipo acompaña cada etapa del proceso con rutinas guiadas.';
        $r = $this->aprobado(SalesIntents::GOAL_FAT_LOSS, 'Quiero bajar de peso', $largo, null);

        $this->assertSame('Claro, con gusto le ayudo. Cuénteme, ¿en qué le ayudo hoy?', $r['reply_final']);
        $this->assertSame([UltronCommitService::VIOLACION_LONGITUD], $this->ultimaAccion()->metadata['policy_violations']);
    }

    /**
     * G4 · I-4 (R5): tras un descarte por registro con un respaldo que no
     * nombra el plan del modelo, ni la memoria ni `price_source` lo citan.
     */
    public function test_g4_la_memoria_no_cita_un_plan_que_no_salio(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::GOAL_FAT_LOSS, 'Quiero bajar de peso', 'Para bajar de peso te recomiendo el Plan Mensual, que cuesta {{PLAN_PRICE}} e incluye acompañamiento.', 4);

        $this->assertStringNotContainsString('Mensual', (string) $r['reply_final']);
        $this->assertNull($r['price_source'], 'price_source citaba el plan 4 a $80.000');
        $memoria = $this->memoria();
        $this->assertNotContains(4, (array) $memoria->get('plans_discussed'), 'plans_discussed = [4] sin que saliera');
        $this->assertSame([], (array) $memoria->get('prices_delivered'));
    }

    public function test_g4_si_la_alternativa_nombra_el_plan_del_turno_la_memoria_lo_conserva(): void
    {
        // Plan equivocado: la persona nombró el Mensual (el commit cotiza ese) y el borrador abría con el
        // Valera. Sale «El que más recomendamos es el Plan Mensual…»: el plan del turno salió y se conserva.
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Plan Mensual?', 'El Plan Valera cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?', 3);
        $this->assertStringStartsWith('El que más recomendamos es el Plan Mensual.', (string) $r['reply_final']);
        $this->assertSame(4, $r['price_source']['plan_id'] ?? null);
        $this->assertContains(4, (array) $this->memoria()->get('plans_discussed'));

        // Sin sustitución (con «cercano» el registro no se mira), la de hoy.
        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $sinSustituir = $this->aprobado(SalesIntents::GOAL_FAT_LOSS, 'Quiero bajar de peso', 'Para bajar de peso te recomiendo el Plan Mensual, que cuesta {{PLAN_PRICE}} e incluye acompañamiento.', 4);
        $this->assertSame(4, $sinSustituir['price_source']['plan_id'] ?? null);
    }

    /**
     * G5 · I-6 (R8): la lista mixta no nombra todos los vendibles → «Algunos de
     * nuestros planes»; «Nuestros planes» solo si los nombra todos.
     */
    public function test_g5_el_encabezado_mixto_dice_algunos_si_no_nombra_todos(): void
    {
        foreach ([200 => 'Claro. Algunos de nuestros planes: Trimestre (tres meses), $210.000 COP; Plan Valera (un mes), $65.000 COP; Plan Mensual (un mes), $80.000 COP. ¿Sobre cuál amplío la información?',
            160 => 'Claro. Algunos de nuestros planes: Trimestre (tres meses), $210.000 COP; Plan Valera (un mes), $65.000 COP. ¿Sobre cuál amplío la información?'] as $tope => $sale) {
            $this->nuevaConversacion();
            $this->publica('profesional', $tope);
            $this->assertSame($sale, $this->caidoCon(SalesIntents::PRICING_QUESTION, '¿Y el Trimestre cuánto cuesta? ¿Qué planes tienen?', [])['reply_final'], (string) $tope);
        }

        // Con TODOS los vendibles nombrados (tres, mixtos), «Nuestros planes».
        Plan::query()->whereNotIn('id', [5, 3, 4])->update(['sellable' => false]);
        $this->nuevaConversacion();
        $this->publica('profesional', 320);
        $this->assertSame(
            'Claro. Nuestros planes: Trimestre (tres meses), $210.000 COP; Plan Valera (un mes), $65.000 COP; Plan Mensual (un mes), $80.000 COP. ¿Sobre cuál amplío la información?',
            $this->caidoCon(SalesIntents::PRICING_QUESTION, '¿Y el Trimestre cuánto cuesta? ¿Qué planes tienen?', ['recommended_plan_id' => null])['reply_final'],
        );
    }

    /**
     * G7 · S-7: con «profesional», una oferta de usted solo con una
     * construcción dirigida a la persona; «quiere decir» o un «desea» de
     * tercera persona no son ofertas.
     *
     * @return array<string, array{string, ?string}>
     */
    public static function ofertasDeUsted(): array
    {
        return [
            'quiere decir (S-7)' => ['¿Sabe qué quiere decir el enlace de pago?', null],
            'el equipo desea (S-7)' => ['¿El equipo desea revisar su pago por el link?', null],
            'quiere que le' => ['¿Quiere que le cuente cómo empezar?', 'explain_how_to_start'],
            'le gustaría' => ['¿Le gustaría que le explique cómo empezar?', 'explain_how_to_start'],
            'le interesa' => ['¿Le interesa conocer los detalles?', 'plan_details'],
            'prefiere' => ['¿Prefiere venir en la mañana o en la tarde?', 'generic_offer'],
            'desea' => ['¿Desea que le pase el enlace de pago?', 'send_payment_link'],
            'le parece' => ['¿Le parece si agendamos la visita?', 'book_visit'],
        ];
    }

    #[DataProvider('ofertasDeUsted')]
    public function test_g7_una_oferta_de_usted_solo_con_su_construccion(string $pregunta, ?string $clase): void
    {
        $this->publica('profesional', 320);
        $borrador = 'Con gusto le ayudo con eso. '.$pregunta;
        $this->assertSame($borrador, $this->aprobado(SalesIntents::GENERAL_INFO, 'Quiero empezar', $borrador, null)['reply_final']);
        $this->assertSame($clase, $this->memoria()->get('last_agent_offer')['kind'] ?? null, $pregunta);
    }

    /** G7 · m-6: «le recomiendo» anota la recomendación y «se inscribe / viene con su» el cómo empezar, solo con «profesional». */
    public function test_g7_la_recomendacion_y_el_como_empezar_de_usted(): void
    {
        $borrador = 'Para lo que me cuenta, le recomiendo el Plan Mensual: solo viene con su documento y se inscribe en recepción.';
        $this->publica('profesional', 320);
        $this->assertSame($borrador, $this->aprobado(SalesIntents::GENERAL_INFO, '¿Cuál plan me recomienda?', $borrador, 4)['reply_final']);
        $memoria = $this->memoria();
        $this->assertSame([4], $memoria->get('last_recommendation')['plan_ids'] ?? null);
        $this->assertContains('how_to_start', array_column((array) $memoria->get('facts_delivered'), 'key'));

        // Con «cercano», la de hoy: ni la recomendación ni el cómo empezar de usted.
        $this->nuevaConversacion();
        $this->publica('cercano', 320);
        $this->aprobado(SalesIntents::GENERAL_INFO, '¿Cuál plan me recomienda?', $borrador, 4);
        $memoria = $this->memoria();
        $this->assertNull($memoria->get('last_recommendation'));
        $this->assertNotContains('how_to_start', array_column((array) $memoria->get('facts_delivered'), 'key'));

        // «El plan viene con…» (tercera persona) no es cómo empezar.
        $this->nuevaConversacion();
        $this->publica('profesional', 320);
        $this->aprobado(SalesIntents::GENERAL_INFO, '¿Qué trae el plan?', 'El Plan Mensual viene con acceso total al gimnasio y rutinas guiadas.', 4);
        $this->assertNotContains('how_to_start', array_column((array) $this->memoria()->get('facts_delivered'), 'key'));
    }

    // ── G8 · el texto EXACTO de usted en cada punto del commit (I-5) ────────

    /** M15 · la hora de la visita, contestada por Laravel. */
    public function test_g8_la_hora_de_la_visita_sale_de_usted(): void
    {
        $this->publica('profesional', 320);
        $this->visitaAMedias(['date' => '2026-11-19']);
        $r = $this->aprobado(SalesIntents::UNKNOWN, 'mañana a las 7', 'Perfecto, te espero mañana a las 7.', null);

        $this->assertSame('Perfecto. Para su visita al gimnasio, ya tengo el día y solo me falta confirmar la hora: ¿se refiere a las 7:00 a. m. o a las 7:00 p. m.?', $r['reply_final']);
        $this->assertSame('ask', $this->ultimaAccion()->metadata['visit_hour']['mode']);
        $this->assertArrayNotHasKey('register', $this->ultimaAccion()->metadata, 'la última red no actuó');
    }

    /** M22 · `planReply` en la rama de la política. */
    public function test_g8_el_plan_que_manda_la_politica_sale_de_usted(): void
    {
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::PRICING_QUESTION, '¿Cuánto cuesta el Plan Mensual?', 'El Plan Valera cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?', 3);

        $this->assertSame('El que más recomendamos es el Plan Mensual. Incluye acceso total al gimnasio. ¿Quiere que le cuente los demás?', $r['reply_final']);
        $this->assertContains(CommercialTurnPolicy::VIOLACION_PLAN_EQUIVOCADO, $this->ultimaAccion()->metadata['policy_violations']);
        $this->assertEquals(['expected' => 'usted', 'found' => [], 'action' => 'ok'], $this->ultimaAccion()->metadata['register'], 'el borrador era de usted y la última red no actuó');
    }

    /** M23 · `courtesyReply`: con la visita a medias, el turno se puso a vender. */
    public function test_g8_la_visita_a_medias_sale_de_usted(): void
    {
        $this->publica('profesional', 320);
        $this->visitaAMedias(['date' => '2026-11-21']);
        $r = $this->aprobado(SalesIntents::UNKNOWN, 'en la mañana', 'Perfecto. Mientras tanto, el Plan Mensual le sirve para empezar.', 4);

        $this->assertSame('Perfecto. Para su visita al gimnasio, ya tengo el día y solo me falta la hora: ¿a qué hora le queda bien?', $r['reply_final']);
        $this->assertContains(CommercialTurnPolicy::VIOLACION_OBJETIVO_PERDIDO, $this->ultimaAccion()->metadata['policy_violations']);
        $this->assertNull($r['price_source']);
    }

    /** M16 · `conLaFechaDeLaCita`: la hora del borrador no es la de la fila; lo del modelo queda y el acta va de usted. */
    public function test_g8_el_acta_que_corrige_la_hora_sale_de_usted_y_el_borrador_no_se_descarta(): void
    {
        $this->publica('profesional', 320);
        $r = $this->conCortesia('Perfecto, con gusto. Quedamos el sábado a las 6:00 a. m.');

        $this->assertSame('Perfecto, con gusto. Dejé registrada su solicitud de cortesía para el sábado 21 de noviembre a las 10:00 a. m. El equipo de Iron Body la revisará para tener todo preparado.', $r['reply_final']);
        $this->assertSame('ok', $this->ultimaAccion()->metadata['register']['action']);
    }

    /** M17 · `sinCambioDeVisitaQueNoSeHizo`: un cambio de la visita sin herramienta. */
    public function test_g8_el_cambio_de_visita_que_no_se_hizo_sale_de_usted(): void
    {
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::UNKNOWN, '¿Me pueden cambiar la visita?', 'Listo, le cambio la visita para el sábado.', null);

        $this->assertSame('Todavía no cambié nada de su visita. ¿Qué día y a qué hora le queda bien?', $r['reply_final']);
        $this->assertSame('ok', $this->ultimaAccion()->metadata['register']['action']);
    }

    /** M24 · `sinConfirmarUnaCortesiaQueNadieConfirmo`: «quedó confirmada» sin ninguna solicitud. */
    public function test_g8_la_confirmacion_que_nadie_dio_sale_de_usted(): void
    {
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::UNKNOWN, '¿Ya quedó lo de mi visita?', 'Sí, su visita quedó confirmada para el sábado a las 10.', null);

        $this->assertSame('Para no darle un dato equivocado: la visita la confirma el equipo de Iron Body. ¿Qué día y a qué hora le quedarían bien?', $r['reply_final']);
        $this->assertSame('ok', $this->ultimaAccion()->metadata['register']['action']);
    }

    /** M25 · `sinConstanciaDeUnaCortesiaQueNoSeRegistro`: la cortesía se pidió y no se registró (falta la hora). */
    public function test_g8_la_constancia_de_una_cortesia_que_no_se_registro_sale_de_usted(): void
    {
        $this->publica('profesional', 320);
        $m = $this->entrante('quiero ir el sábado');
        $decide = $this->decide($m, true);
        $propuesta = array_merge(self::propuesta(SalesIntents::GENERAL_INFO, 'Listo, le dejo registrada su visita para el sábado.', null, [SalesIntents::TOOL_COURTESY_REQUEST]), [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment', 'courtesy_action' => 'request',
            'courtesy_date' => '2026-11-21', 'courtesy_time' => null,
        ]);
        $r = $this->commit($this->payload($m, $decide['decide_token'], $propuesta, self::APROBADO))->assertOk()->json();

        $this->assertSame('Perfecto. Para su visita al gimnasio, ya tengo el día y solo me falta la hora: ¿a qué hora le queda bien?', $r['reply_final']);
        $this->assertSame('ok', $this->ultimaAccion()->metadata['register']['action']);
    }

    /** M19 · `sinServiciosInventados` → `loQueSiTenemos`. */
    public function test_g8_una_clase_inventada_se_retira_y_lo_que_si_hay_sale_de_usted(): void
    {
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, '¿Tienen pilates?', 'Tenemos clases de yoga, pilates y spinning todos los días. ¿Le interesa alguna?', null);

        $this->assertSame('No tengo esa información confirmada en este momento, pero puedo ayudarle con lo que sí tenemos registrado. ¿Le interesa alguna?', $r['reply_final']);
        $this->assertSame('ok', $this->ultimaAccion()->metadata['register']['action']);
    }

    /** M18 · `sinNegarUnCobroDisponible` → `SIN_ENLACE_PERO_SIN_MENTIR` (con el cobro del canario disponible). */
    public function test_g8_negar_un_cobro_disponible_deja_el_texto_de_usted(): void
    {
        config()->set('wompi.env', 'production');
        config()->set('wompi.public_key', 'pub_prod_test_key');
        config()->set('wompi.private_key', 'prv_prod_test_key');
        config()->set('wompi.integrity_secret', 'integrity_test_secret');
        config()->set('wompi.events_secret', 'events_test_secret');
        config()->set('wompi.checkout.base_url', 'https://checkout.wompi.co/p/');
        config()->set('wompi.checkout.redirect_url', 'https://web.ironbodyneiva.cloud/pago');
        config()->set('marketing.ultron.payment_canary_conversation_id', $this->conversation->id);
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, '¿Tienen link de pago?', 'No contamos con un link de pago por ahora.', 4);

        $this->assertSame('Puede hacer el pago desde la app Iron Body Workout o directamente en el gimnasio. Dígame qué plan quiere y lo dejamos listo.', $r['reply_final']);
        $this->assertSame('ok', $this->ultimaAccion()->metadata['register']['action']);
    }

    /** «profesional + tope» · el descarte por longitud Y por registro a la vez: el texto curado de usted, ajustado. */
    public function test_g8_tope_las_dos_violaciones_a_la_vez(): void
    {
        $this->publica('profesional', 160);
        $largo = 'Para bajar de peso lo mejor es combinar fuerza y cardio, y tu alimentación cuenta mucho. Con constancia notas cambios en pocas semanas, y el equipo te acompaña en cada etapa con rutinas guiadas.';
        $r = $this->aprobado(SalesIntents::GOAL_FAT_LOSS, 'Quiero bajar de peso', $largo, null);

        $this->assertSame('Muy bien. Para bajar grasa lo más importante es empezar con algo que pueda sostener. ¿Ya viene entrenando o empieza desde cero?', $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertContains(UltronCommitService::VIOLACION_LONGITUD, $fila->metadata['policy_violations']);
        $this->assertContains(UltronCommitService::VIOLACION_REGISTRO, $fila->metadata['policy_violations']);
        $this->assertLessThanOrEqual(160, UltronReplyFitter::measure($r['reply_final']));
    }

    /** «profesional + tope» · el acta tras un descarte por registro, ajustada al tope. */
    public function test_g8_tope_el_acta_de_usted_tras_el_registro(): void
    {
        $this->publica('profesional', 160);
        // Tutea en el cuerpo y no afirma nada de la visita (eso lo corregirían antes las guardas de la cortesía).
        $r = $this->conCortesia('Con gusto, el sábado a las 10:00 a. m. te muestran las máquinas y te arman una rutina de prueba.');

        $this->assertSame('Dejé registrada su solicitud de cortesía para el sábado 21 de noviembre a las 10:00 a. m. El equipo de Iron Body la revisará para tener todo preparado.', $r['reply_final']);
        $this->assertContains(UltronCommitService::VIOLACION_REGISTRO, $this->ultimaAccion()->metadata['policy_violations']);
        $this->assertLessThanOrEqual(160, UltronReplyFitter::measure($r['reply_final']));
    }

    /** «profesional + tope» · 13.ter: la frase que retoma la visita, de usted, añadida si cabe. */
    public function test_g8_tope_la_frase_que_retoma_la_visita(): void
    {
        $this->publica('profesional', 200);
        $this->visitaAMedias(['date' => '2026-11-21']);
        $r = $this->aprobado(SalesIntents::LOCATION_QUESTION, '¿Dónde quedan?', 'Estamos en Cl. 24 Sur #33-53, Neiva, Huila.', null);

        $this->assertSame('Estamos en Cl. 24 Sur #33-53, Neiva, Huila. Y sobre su visita: ya tengo el día y solo me falta la hora: ¿a qué hora le queda bien?', $r['reply_final']);
        $this->assertLessThanOrEqual(200, UltronReplyFitter::measure($r['reply_final']));
    }

    /** «profesional + tope» · el desbordamiento de `antesDeEnviar()`: el último recurso que cabe, de usted. */
    public function test_g8_tope_el_desbordamiento_sale_con_el_ultimo_recurso_de_usted(): void
    {
        $this->publica('profesional', 160);
        $this->criticoCaido(SalesIntents::GREETING, 'hola');
        $fila = $this->ultimaAccion();

        $control = new \ReflectionMethod(UltronCommitService::class, 'antesDeEnviar');
        $sale = $control->invoke(app(UltronCommitService::class), $this->conversation, $fila, str_repeat('Texto que no cabe. ', 12), SalesIntents::UNKNOWN, ['studio' => true, 'tope' => 160, 'tono' => 'profesional'], ['action' => 'fit', 'from' => 228, 'dropped' => 0]);

        $this->assertSame('Claro, con gusto le ayudo. Cuénteme, ¿en qué le ayudo hoy?', $sale);
        $this->assertTrue($fila->fresh()->metadata['length']['overflow']);
    }

    /** «profesional + tope» · `sinExcusasDePrecio` que deja el mensaje vacío: el último recurso de usted. */
    public function test_g8_tope_una_excusa_de_precio_sola_deja_el_ultimo_recurso_de_usted(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::GENERAL_INFO, '¿Por qué antes me dijeron otro precio?', 'La diferencia puede ser por confusión con otro plan o información previa.', null);

        $this->assertSame('Claro, con gusto le ayudo. Cuénteme, ¿en qué le ayudo hoy?', $r['reply_final']);
        $this->assertSame(1, $this->ultimaAccion()->metadata['price_excuse_dropped'] ?? null);
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    /** Un borrador aprobado con campos de la propuesta a medida (herramientas, relevo…). */
    private function aprobadoCon(string $intent, string $texto, string $borrador, ?int $plan, array $extra): array
    {
        $m = $this->entrante($texto);
        $decide = $this->decide($m, true);

        return $this->commit($this->payload($m, $decide['decide_token'], array_merge(self::propuesta($intent, $borrador, $plan), $extra), self::APROBADO))->assertOk()->json();
    }

    /** Dos rechazos del revisor, con campos de la propuesta a medida. */
    private function caidoCon(string $intent, string $texto, array $extra): array
    {
        $m = $this->entrante($texto);
        $decide = $this->decide($m, true);
        $propuesta = array_merge(self::propuesta($intent, 'Te confirmo que el plan Élite es el que mejor te encaja. ¿Quieres que te ayude a concretar tu inscripción?', 8), $extra);

        return $this->commit($this->payload($m, $decide['decide_token'], $propuesta, self::CRITICO_CAIDO))->assertOk()->json();
    }

    /** La cortesía de R7 con otro entrante (el día y la hora, y algo más). */
    private function conCortesiaY(string $texto, string $borrador): array
    {
        $m = $this->entrante($texto);
        $decide = $this->decide($m, true);
        $propuesta = array_merge(self::propuesta(SalesIntents::GENERAL_INFO, $borrador, null, [SalesIntents::TOOL_COURTESY_REQUEST]), [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment', 'courtesy_action' => 'request',
            'courtesy_date' => '2026-11-21', 'courtesy_time' => '10:00',
        ]);

        return $this->commit($this->payload($m, $decide['decide_token'], $propuesta, self::APROBADO))->assertOk()->json();
    }

    private function memoria(): ConversationMemory
    {
        return app(ConversationMemoryService::class)->load($this->conversation->fresh());
    }

    /** Un borrador aprobado, negociando o no (Legacy por token sin `sp`). */
    private function aprobadoSegun(string $intent, string $texto, string $borrador, bool $negocia): array
    {
        $m = $this->entrante($texto);
        $decide = $this->decide($m, $negocia);

        return $this->commit($this->payload($m, $decide['decide_token'], self::propuesta($intent, $borrador, null), self::APROBADO))->assertOk()->json();
    }

    /** Lo que se compara en Legacy (la misma huella que A4): el texto, el desenlace, la fila y la memoria. */
    private function huellaLegacy(array $r): array
    {
        $fila = MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->first();
        $meta = $fila === null ? [] : $fila->metadata;
        $claves = array_keys($meta);
        sort($claves);
        $memoria = $this->memoria();

        return [
            'reply_final' => $r['reply_final'] ?? null,
            'outcome' => $r['outcome'] ?? null,
            'saliente' => $this->ultimoSaliente()?->body,
            'claves' => $claves,
            'policy_violations' => $meta['policy_violations'] ?? null,
            'oferta' => $memoria->get('last_agent_offer')['kind'] ?? null,
            'pregunta' => $memoria->get('last_agent_question')['text'] ?? null,
        ];
    }

    /** ¿El fragmento sigue escrito, tal cual, en el código de alguno de sus productores? */
    private function existeEnSuProductor(string $fragmento): bool
    {
        $fuentes = '';
        foreach (['app/Services/Marketing/SalesConversationReplyService.php', 'app/Services/Marketing/SalesObjectionResponderService.php',
            'app/Services/Marketing/Ultron/CommercialTurnPolicy.php', 'app/Services/Marketing/Ultron/CourtesyRequestService.php',
            'app/Services/Marketing/Ultron/UltronCommitService.php'] as $f) {
            // Los textos largos van partidos en varias líneas con «.'»: se unen para buscar.
            $fuentes .= preg_replace("/'\\s*\\n\\s*\\.'/u", '', (string) file_get_contents(base_path($f)))."\n";
        }
        $fragmento = str_replace(SalesConversationReplyService::ADDRESS, "'.self::ADDRESS.'", $fragmento);

        return str_contains($fuentes, $fragmento) || str_contains($fuentes, trim($fragmento));
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
            'phone' => '3150537'.$n, 'meta_user_id' => '573150537'.$n,
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

    /** Una visita a medio concretar en la memoria de la conversación (el objetivo vivo). */
    private function visitaAMedias(array $data): void
    {
        $memoria = app(ConversationMemoryService::class);
        $memoria->recordCourtesyCollecting($this->conversation->fresh(), $data);
    }

    private function entrante(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.a4r.'.$this->mundo.'.'.uniqid(), 'status' => 'received',
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

    /** Un turno aprobado con el `sp` del token retocado y vuelto a firmar con el mismo firmador de `decide`. */
    private function conTokenRetocado(callable $retoca, string $intent, string $texto, string $borrador): array
    {
        $m = $this->entrante($texto);
        $decide = $this->decide($m, true);
        $x = self::cargaDelToken($decide['decide_token'])['x'];
        $x['sp'] = $retoca($x['sp']);
        $token = app(UltronDecideToken::class)->issue((int) $this->conversation->id, (int) $m->id, $decide['commercial_phase'], $decide['allowed_transitions'], $decide['knowledge_version'], $x)['token'];

        return $this->commit($this->payload($m, $token, self::propuesta($intent, $borrador, null), self::APROBADO))->assertOk()->json();
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

    /** Dos rechazos del revisor: el turno sale por `safeFallback()` (el borrador, de tú, no sale). */
    private function criticoCaido(string $intent, string $texto, bool $negocia = true): array
    {
        $m = $this->entrante($texto);
        $decide = $this->decide($m, $negocia);

        return $this->commit($this->payload($m, $decide['decide_token'], self::propuesta($intent, 'Te confirmo que el plan Élite es el que mejor te encaja. ¿Quieres que te ayude a concretar tu inscripción?', 8), self::CRITICO_CAIDO))->assertOk()->json();
    }

    /** Un borrador que el revisor aprobó: el turno sale por el camino normal. */
    private function aprobado(string $intent, string $texto, string $borrador, ?int $plan, array $herramientas = []): array
    {
        $m = $this->entrante($texto);
        $decide = $this->decide($m, true);

        return $this->commit($this->payload($m, $decide['decide_token'], self::propuesta($intent, $borrador, $plan, $herramientas), self::APROBADO))->assertOk()->json();
    }

    /** La persona da el día y la hora, el revisor aprueba y el modelo pide la cortesía (R7 de A4). */
    private function conCortesia(string $borrador): array
    {
        $m = $this->entrante('quiero ir el sábado a las 10 de la mañana');
        $decide = $this->decide($m, true);
        $propuesta = array_merge(self::propuesta(SalesIntents::GENERAL_INFO, $borrador, null, [SalesIntents::TOOL_COURTESY_REQUEST]), [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment', 'courtesy_action' => 'request',
            'courtesy_date' => '2026-11-21', 'courtesy_time' => '10:00',
        ]);

        return $this->commit($this->payload($m, $decide['decide_token'], $propuesta, self::APROBADO))->assertOk()->json();
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
