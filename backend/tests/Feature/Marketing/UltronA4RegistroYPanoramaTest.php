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
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesConversationReplyService;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\SalesObjectionResponderService;
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

    public function test_turno_3_real_sin_tope_sale_el_ultimo_recurso_de_usted(): void
    {
        $this->publica('profesional', 320);
        $r = $this->aprobado(SalesIntents::SCHEDULE_QUESTION, self::REAL_HORARIO_ENTRANTE, self::REAL_HORARIO_BORRADOR, null);

        $this->assertSame('Claro, con gusto le ayudo. Cuénteme, ¿en qué le ayudo hoy?', $r['reply_final']);
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

    public function test_el_tuteo_solo_en_la_pregunta_final_se_quita_la_pregunta(): void
    {
        $this->publica('profesional', 200);
        $r = $this->aprobado(SalesIntents::SCHEDULE_QUESTION, self::REAL_HORARIO_ENTRANTE, self::SOLO_LA_PREGUNTA, null);

        $this->assertSame('El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m.', $r['reply_final']);
        $fila = $this->ultimaAccion();
        $this->assertEquals(['expected' => 'usted', 'found' => ['quieres', 'te'], 'action' => 'trimmed'], $fila->metadata['register']);
        $this->assertArrayNotHasKey('policy_violations', $fila->metadata);
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

    // ── Andamio ──────────────────────────────────────────────────────────────

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
