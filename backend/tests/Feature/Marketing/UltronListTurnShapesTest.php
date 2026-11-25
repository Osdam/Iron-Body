<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
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
use App\Services\Marketing\Ultron\UltronDraftPlaceholders;
use App\Services\Marketing\Ultron\UltronPlansOverview as O;
use App\Services\Marketing\Ultron\UltronRegenerationNotes;
use App\Services\Marketing\Ultron\UltronRegisterDetector;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use App\Services\Marketing\Ultron\UltronReplyOrigin;
use App\Services\UltronStudio\StudioLabEngineCheck;
use App\Services\UltronStudio\StudioLabEngineContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\HistoricalListTurn;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * PT-17 · PUERTA 2 · LAS DOS FORMAS DE LA LISTA SOBRE LOS BORRADORES REALES
 * (especificación `ops/evidencia-h9/2026-11-24/12-pt17-puerta-2-especificacion.md`,
 * §4 «UltronListTurnShapesTest»; la API, §1.2; la precedencia, §1.3; los
 * enganches, §1.4).
 *
 * EL CORPUS, solo texto del modelo y del servidor:
 *  - la medición del compilador 7 (`pt17-borradores-reales-c7.json`): 25
 *    casos y 67 borradores (55 del turno de planes y 12 de la consulta
 *    combinada), 50 de ellos con lo que dio `ai/check` en producción (el
 *    tercero va al revisor sin comprobación);
 *  - la del compilador 6 (`pt17c-borradores-reales-c6.json`): 25 primeros
 *    borradores con sus códigos, 14 textos finales del modelo (sin
 *    comprobación grabada) y 5 segundos de la combinada que `ai/check` dio por
 *    buenos.
 * Con `po` [3, 4, 8] y `sp` {m: studio, n: 200, t: profesional, o: 1, k: 8}:
 * el que firma `decide` para la v4 con el compilador de este árbol. El
 * catálogo vendible de producción (el de las pruebas de paridad), con los
 * nombres y precios de la medición y los beneficios de `PlansSeeder` en la
 * lista (3 y 4, «Básicos»; 8, «Total Access»), que son los que citan los
 * borradores; con él, `decide` ofrece [3, 4, 8] al panorama y a la combinada
 * y queda otro plan de un mes fuera de la lista (PROMO X4).
 *
 *  (a) FIDELIDAD. `verify(..., false)` reproduce el código `plans_overview/*`
 *      que dio `ai/check` en producción en las 80 comprobaciones grabadas (50
 *      + 30), con UNA excepción declarada: los 11 borradores c7 que la puerta
 *      1 (L2, C2 del diagnóstico del 24-11-2026) pasó a propósito a `missing`
 *      porque hablan de un solo plan de la lista (10 grabados como
 *      `name_written` y 1 como `mixed`; el docblock de `verify()` los cuenta
 *      igual). La lista va fijada por su nombre, con lo grabado y el porqué.
 *  (b) FORMA A ESTRICTA (`verify(..., true)`, §1.3 regla 5), sobre los 82
 *      borradores con algún `{{PLAN_k}}`:
 *       b1. Solo cambian listas que hoy valen, y solo a `missing` (menos de
 *           tres) o a `mixed` (fuera de orden); ninguna que hoy no vale pasa a
 *           valer. Las reales: 3, todas por ser de dos planes; ninguna real va
 *           fuera de orden.
 *       b2. En las que hoy ya no valen, el detalle no cambia: con la lista del
 *           servidor, una forma A que habla de menos de dos planes de la lista
 *           sigue en `missing` (L2), antes de la regla 5 de §1.3 (decisión del
 *           lead, 24-11-2026, la regla L2 de la puerta 1 se mantiene con la
 *           lista del servidor). Van fijados por su nombre los 14 en que §1.3
 *           al pie de la letra habría dado `name_written` (12) o `mixed` (2).
 *  (c) FORMA B SOBRE PROSA REAL. De cada borrador, una variante con ranura
 *      (el tramo del primer `{{` al último `}}` pasa a ser la ranura) y otra
 *      sin ranura (ese tramo fuera); el que no lleva marcadores ya es forma B
 *      sin ranura. 95 con ranura y 111 sin ella. En cada una, `render()` deja
 *      la lista exactamente una vez (con ranura, en su sitio y el resto byte a
 *      byte; sin ranura, «encabezado + lista + .» antes de la pregunta final),
 *      no queda nada con forma de marcador, el tope es el de la lista (320),
 *      el detector de tuteo da las mismas marcas que sin la lista y
 *      `predict().trimmed` es false. Ninguna marca de tuteo grabada en el
 *      corpus: la comparación vigila que la lista no las añada.
 *  (d) PARIDAD, sobre 36 variantes, cada una en su conversación, por el
 *      `decide` y el commit reales: 32 de los 16 borradores c7 que `ai/check`
 *      dio por limpios en producción (con y sin ranura; salen del modelo), 2
 *      con un nombre a mano y 2 que pasan de 320 (salen de respaldo).
 *      `UltronDraftCheck::inspect()` y `StudioLabEngineCheck::check()` dan los
 *      mismos defectos y notas; el origen que predice el laboratorio es el del
 *      commit; el texto que envía el commit es el que predice el laboratorio
 *      (el mismo `render()`) y mide lo que dice `predict()`; y la forma y la
 *      ranura de `predict().list` son las de la traza del commit.
 *
 * CÓMO CAE SIN EL CAMBIO (en un árbol sin L1): (a) pasa; (b), (c) y (d) dan
 * error (`serverList()`, `shape()`, `compose()`, `header()` y `render()` no
 * existen). Con esos métodos pero sin el resto de L1, la ranura da 422
 * `unknown_placeholder` en el commit, `plans_overview/missing` en `ai/check`
 * y el laboratorio predice un respaldo: lo ve (d).
 *
 * DE DÓNDE SALE LO ESPERADO: de la especificación y de los datos de las
 * fixtures, nunca de lo que devuelve el código bajo prueba. Las tablas
 * literales se derivaron de las fixtures con las reglas de §1.3; su punto de
 * partida, `verify(..., false)`, se contrastó con el de este árbol en los 111
 * textos. Los nombres a mano se leen con las tres reglas de
 * `CommercialTurnPolicy::plansNamedIn()` escritas aquí, sin llamarla.
 */
class UltronListTurnShapesTest extends TestCase
{
    use HistoricalListTurn;
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000c8';

    private const FIXTURE_C7 = __DIR__.'/../../fixtures/ultron-studio/pt17-borradores-reales-c7.json';

    private const FIXTURE_C6 = __DIR__.'/../../fixtures/ultron-studio/pt17c-borradores-reales-c6.json';

    /** El catálogo vendible de producción (el de `UltronCheckEndpointTest` y las paridades), con los nombres y precios de la medición. */
    private const CATALOGO = [
        [25, 'PLAN DÍA', 10000, 1], [2, 'Plan Semana', 45000, 7], [3, 'Plan Valera', 65000, 30], [4, 'Plan Mensual', 80000, 30],
        [5, 'Trimestre', 210000, 90], [6, 'Semestre', 390000, 180], [7, 'Anualidad', 624000, 365], [8, 'Élite', 180000, 30],
        [9, 'Pro', 499000, 90], [10, 'Prime', 950000, 180], [11, 'Evolution', 1800000, 365], [14, 'PROMO X4', 75000, 30],
        [16, 'INGRESO EMPLEADOS', 1, 360],
    ];

    private const BASICOS = ['Acceso ilimitado al gimnasio', 'Zona de pesas y maquinaria', 'Asesoría semi personalizada con entrenador de planta', 'Seguimiento en ejecución de ejercicios', 'Valoración inicial', 'Acceso básico completo a la app', 'Perfil de usuario', 'Visualización de plan activo', 'Historial de pagos y documentos', 'Rutinas generales básicas', 'Registro básico de IMC, peso y racha', 'Acceso a reels, stories y eventos generales', 'IRON IA limitada'];

    private const TOTAL_ACCESS = ['Acceso total al gimnasio', 'Acceso completo a IRONBODY WORKOUT', 'Sesiones funcionales por agenda', 'Entrenamiento intensivo y dinámico', 'Rutinas guiadas por entrenadores', 'Seguimiento continuo durante todo el proceso', 'Guía nutricional para mejorar resultados', 'Acceso completo a la app', 'IRON IA completa', 'IRON IA por texto, voz, audio, imagen y cámara', 'Análisis visual en vivo', 'IRON IA coach completo', 'Rutinas personalizadas', 'Seguimiento digital completo', 'Nutrición avanzada', 'Historial completo de progreso'];

    /** La versión 4 medida: «profesional · 200». */
    private const V4 = ['persona.tone' => 'profesional', 'persona.maxChars' => 200];

    private const PO = [3, 4, 8];

    /** Lo que fija la especificación del `sp` del turno (el firmado lleva además r, h, v, c, e y s). */
    private const SP = ['m' => 'studio', 'n' => 200, 't' => 'profesional', 'o' => 1, 'k' => 8];

    /** La ranura (§1.2, `SLOT`), con la caja exacta. */
    private const RANURA = '{{LISTA_DE_PLANES}}';

    /** `compose([3, 4, 8])`: «Nombre ($precio COP)» de cada plan, en el orden de `po`, con «; ». 75 caracteres (§1.2 [C]). */
    private const LISTA = 'Plan Valera ($65.000 COP); Plan Mensual ($80.000 COP); Élite ($180.000 COP)';

    /** La frase que añade `place()` sin ranura: `HEADER_SOME_MONTHLY . lista . '.'`. 102 caracteres (§1.2 [C]). */
    private const FRASE = 'Algunos planes de un mes: '.self::LISTA.'.';

    /** El revisor de la predicción del laboratorio, y el del commit. */
    private const PASS = ['verdict' => 'pass', 'issues' => [], 'notes' => ''];

    private const APROBADO = ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9];

    /**
     * (a) Los que la puerta 1 (L2) pasó a `missing`: id → lo que dio `ai/check`
     * en producción. Todos hablan de UN plan de la lista (por su marcador y su
     * nombre, o con `{{PLAN_PRICE}}`). En la medición del compilador 6, ninguno.
     */
    private const RECLASIFICADOS_POR_L2 = [
        'c7 43/194/E-001#1' => O::NAME_WRITTEN,
        'c7 43/195/E-002#1' => O::NAME_WRITTEN,
        'c7 43/196/E-003#1' => O::NAME_WRITTEN,
        'c7 43/197/E-004#1' => O::NAME_WRITTEN,
        'c7 44/200/E-001#1' => O::NAME_WRITTEN,
        'c7 44/203/E-004#1' => O::NAME_WRITTEN,
        'c7 44/204/E-005#1' => O::NAME_WRITTEN,
        'c7 45/211/E-006#1' => O::NAME_WRITTEN,
        'c7 46/213/E-002#1' => O::NAME_WRITTEN,
        'c7 46/213/E-002#2' => O::MIXED,
        'c7 46/216/E-005#1' => O::NAME_WRITTEN,
    ];

    /** (b1) Las listas de forma A que hoy valen y la forma A estricta invalida: id → detalle. Las tres, de dos planes. */
    private const INVALIDADAS_POR_LA_FORMA_A_ESTRICTA = [
        'c7 43/195/E-002#2' => O::MISSING,
        'c6 39/176/E-002#3' => O::MISSING,
        'c6 41/189/E-003#2' => O::MISSING,
    ];

    /**
     * (b2) Forma A que hoy no vale porque habla de un solo plan de la lista
     * (L2) y lleva un nombre de la lista a mano (12) o un marcador de un plan
     * (2): con §1.3 al pie de la letra habría pasado a `name_written` o
     * `mixed`. id → [hoy, con la lista del servidor]. Decisión del lead,
     * 24-11-2026, la regla L2 de la puerta 1 se mantiene con la lista del
     * servidor: siguen en `missing`.
     */
    private const L2_CON_LA_LISTA_DEL_SERVIDOR = [
        'c7 43/194/E-001#1' => [O::MISSING, O::MISSING],
        'c7 43/195/E-002#1' => [O::MISSING, O::MISSING],
        'c7 43/195/E-002#3' => [O::MISSING, O::MISSING],
        'c7 43/196/E-003#1' => [O::MISSING, O::MISSING],
        'c7 43/197/E-004#1' => [O::MISSING, O::MISSING],
        'c7 44/200/E-001#1' => [O::MISSING, O::MISSING],
        'c7 44/203/E-004#1' => [O::MISSING, O::MISSING],
        'c7 44/204/E-005#1' => [O::MISSING, O::MISSING],
        'c7 45/206/E-001#3' => [O::MISSING, O::MISSING],
        'c7 45/211/E-006#1' => [O::MISSING, O::MISSING],
        'c7 46/213/E-002#1' => [O::MISSING, O::MISSING],
        'c7 46/213/E-002#2' => [O::MISSING, O::MISSING],
        'c7 46/213/E-002#3' => [O::MISSING, O::MISSING],
        'c7 46/216/E-005#1' => [O::MISSING, O::MISSING],
    ];

    /**
     * (d) Las variantes de la paridad: [borrador, con o sin ranura, origen
     * esperado, código que lo explica]. Las 32 primeras, de los 16 borradores
     * que `ai/check` dio por limpios en la medición c7 (sin tuteo, sin
     * beneficio ajeno, sin regla de política; ya con la lista, de 181 a 270
     * caracteres): salen del modelo. Después, dos con un nombre a mano (259 y
     * 313) y dos que pasan de 320 (338 y 362): salen de respaldo.
     */
    private const PARIDAD = [
        ['c7 43/195/E-002#2', 'con', 'model', null], ['c7 43/195/E-002#2', 'sin', 'model', null],
        ['c7 43/198/E-005#2', 'con', 'model', null], ['c7 43/198/E-005#2', 'sin', 'model', null],
        ['c7 43/199/E-006#2', 'con', 'model', null], ['c7 43/199/E-006#2', 'sin', 'model', null],
        ['c7 44/201/E-002#2', 'con', 'model', null], ['c7 44/201/E-002#2', 'sin', 'model', null],
        ['c7 44/202/E-003#2', 'con', 'model', null], ['c7 44/202/E-003#2', 'sin', 'model', null],
        ['c7 44/205/E-006#2', 'con', 'model', null], ['c7 44/205/E-006#2', 'sin', 'model', null],
        ['c7 45/206/E-001#2', 'con', 'model', null], ['c7 45/206/E-001#2', 'sin', 'model', null],
        ['c7 45/207/E-002#2', 'con', 'model', null], ['c7 45/207/E-002#2', 'sin', 'model', null],
        ['c7 45/208/E-003#2', 'con', 'model', null], ['c7 45/208/E-003#2', 'sin', 'model', null],
        ['c7 45/209/E-004#2', 'con', 'model', null], ['c7 45/209/E-004#2', 'sin', 'model', null],
        ['c7 45/210/E-005#2', 'con', 'model', null], ['c7 45/210/E-005#2', 'sin', 'model', null],
        ['c7 46/212/E-001#2', 'con', 'model', null], ['c7 46/212/E-001#2', 'sin', 'model', null],
        ['c7 46/214/E-003#2', 'con', 'model', null], ['c7 46/214/E-003#2', 'sin', 'model', null],
        ['c7 46/215/E-004#2', 'con', 'model', null], ['c7 46/215/E-004#2', 'sin', 'model', null],
        ['c7 46/217/E-006#2', 'con', 'model', null], ['c7 46/217/E-006#2', 'sin', 'model', null],
        ['c7 47/218/E-006#2', 'con', 'model', null], ['c7 47/218/E-006#2', 'sin', 'model', null],
        ['c7 45/209/E-004#1', 'con', 'fallback', 'plans_overview/name_written'],
        ['c7 46/217/E-006#1', 'sin', 'fallback', 'plans_overview/name_written'],
        ['c7 45/208/E-003#3', 'sin', 'fallback', 'length_over_cap'],
        ['c7 45/211/E-006#2', 'sin', 'fallback', 'length_over_cap'],
    ];

    /** @var list<array{id:string,texto:string,grabado:string|null|false,escenario:string}>|null */
    private static ?array $corpus = null;

    private MarketingConversation $conversation;

    private int $mundo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Martes 24-11-2026, 08:55 en Bogotá (en UTC: la trampa conocida de la suite).
        Carbon::setTestNow(Carbon::parse('2026-11-24 13:55:00', 'UTC'));
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
                'benefits' => json_encode(match ($id) {
                    3, 4 => self::BASICOS,
                    8 => self::TOTAL_ACCESS,
                    default => ['Acceso total al gimnasio'],
                }),
            ])->save();
        }
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
        ]);
        // La v4 publicada en la canaria, con el compilador de este árbol: `decide` firma su `sp` y, al panorama, la lista.
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, self::V4), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── (a) Fidelidad ───────────────────────────────────────────────────────

    public function test_a_fidelidad_verify_sin_la_lista_del_servidor_da_el_codigo_que_dio_ai_check_en_produccion(): void
    {
        $catalogo = $this->catalogo();
        $esperado = [];
        $real = [];
        foreach (self::corpus() as $b) {
            if ($b['grabado'] === false) {
                continue;
            }
            $esperado[$b['id']] = array_key_exists($b['id'], self::RECLASIFICADOS_POR_L2) ? O::MISSING : $b['grabado'];
            $real[$b['id']] = O::verify($b['texto'], self::PO, $catalogo, false);
        }
        $this->assertCount(80, $real, 'las comprobaciones grabadas: 50 de la medición del compilador 7 y 30 de la del 6');
        $this->assertSame($esperado, $real, 'lo que dio ai/check en producción, salvo los que la puerta 1 (L2) pasó a missing');

        // Los reclasificados: lo que se grabó y por qué cambia (lectura propia, sin la función que se prueba).
        foreach (self::RECLASIFICADOS_POR_L2 as $id => $grabado) {
            $b = self::borrador($id);
            $this->assertSame($grabado, $b['grabado'], "{$id}: lo que dio ai/check en producción");
            $this->assertCount(1, self::planesQueNombra($b['texto']), "{$id}: habla de un solo plan de la lista");
        }
        // Y son todos los que la regla de L2 alcanza: ningún otro grabado como name_written o mixed habla de menos de dos planes.
        $alcanzados = array_values(array_map(
            static fn (array $b): string => $b['id'],
            array_filter(self::corpus(), static fn (array $b): bool => in_array($b['grabado'], [O::NAME_WRITTEN, O::MIXED], true) && count(self::planesQueNombra($b['texto'])) < 2),
        ));
        $this->assertSame(array_keys(self::RECLASIFICADOS_POR_L2), $alcanzados);
    }

    // ── (b) Forma A estricta ────────────────────────────────────────────────

    public function test_b1_forma_a_estricta_solo_invalida_listas_que_hoy_valen_por_faltar_un_plan_o_ir_fuera_de_orden(): void
    {
        $catalogo = $this->catalogo();
        $conLista = O::serverList(self::sp());
        $this->assertTrue($conLista, 'sp.m = studio y sp.k = 8: la lista es del servidor');

        $formaA = 0;
        $validasHoy = 0;
        $invalidadas = [];
        $revividas = [];
        foreach (self::corpus() as $b) {
            if (! self::formaA($b['texto'])) {
                continue;
            }
            $formaA++;
            $this->assertSame(O::SHAPE_MARKERS, O::shape($b['texto']), "{$b['id']}: forma A");
            $hoy = O::verify($b['texto'], self::PO, $catalogo, false);
            $estricta = O::verify($b['texto'], self::PO, $catalogo, $conLista);
            if ($hoy === null) {
                $validasHoy++;
                if ($estricta !== null) {
                    $invalidadas[$b['id']] = $estricta;
                }
            } elseif ($estricta === null) {
                $revividas[] = $b['id'];
            }
        }

        $this->assertSame(82, $formaA, 'los borradores con algún {{PLAN_k}}: 82 de 111');
        $this->assertSame(46, $validasHoy, 'las listas de forma A que hoy valen');
        $this->assertSame([], $revividas, 'ninguna lista que hoy no vale pasa a valer');
        $this->assertSame(self::INVALIDADAS_POR_LA_FORMA_A_ESTRICTA, $invalidadas, 'las que pasan de válidas a no válidas: la lista exacta');
        // El porqué de cada una, con lectura propia: menos de tres marcadores distintos → missing; los tres, fuera de orden → mixed.
        foreach ($invalidadas as $id => $detalle) {
            $ks = self::marcadoresDeLaLista(self::borrador($id)['texto']);
            $porque = count(array_unique($ks)) < count(self::PO) ? O::MISSING : ($ks !== range(1, count(self::PO)) ? O::MIXED : 'ninguno');
            $this->assertSame($porque, $detalle, "{$id}: marcadores ".json_encode($ks));
        }
    }

    public function test_b2_forma_a_estricta_en_las_listas_que_hoy_no_valen_manda_la_precedencia_de_la_especificacion(): void
    {
        $catalogo = $this->catalogo();
        $conLista = O::serverList(self::sp());
        $this->assertTrue($conLista, 'sp.m = studio y sp.k = 8: la lista es del servidor');
        $esperado = [];
        $real = [];
        foreach (self::corpus() as $b) {
            if (! self::formaA($b['texto'])) {
                continue;
            }
            $hoy = O::verify($b['texto'], self::PO, $catalogo, false);
            if ($hoy === null) {
                continue;
            }
            $real[$b['id']] = [$hoy, O::verify($b['texto'], self::PO, $catalogo, $conLista)];
            $esperado[$b['id']] = self::L2_CON_LA_LISTA_DEL_SERVIDOR[$b['id']] ?? [$hoy, $hoy];
        }
        $this->assertCount(36, $real, 'las de forma A que hoy no valen');
        $this->assertSame($esperado, $real, 'L2 (menos de dos planes de la lista → missing) y después §1.3 regla 5: mezcla, nombre a mano, menos de tres, fuera de orden');

        // Por qué están en la tabla, con lectura propia: hablan de un solo plan (L2) y la regla 5 los tomaría por un nombre a mano o por una mezcla.
        $razones = ['nombre a mano' => 0, 'marcador de un plan' => 0];
        foreach (array_keys(self::L2_CON_LA_LISTA_DEL_SERVIDOR) as $id) {
            $texto = self::borrador($id)['texto'];
            $this->assertCount(1, self::planesQueNombra($texto), "{$id}: habla de un solo plan de la lista (L2)");
            if (self::nombradosAMano($texto) !== []) {
                $razones['nombre a mano']++;
            } elseif (preg_match('/\{\{\s*PLAN_(PRICE|NAME|DURATION)\s*\}\}/', $texto) === 1) {
                $razones['marcador de un plan']++;
            }
        }
        $this->assertSame(['nombre a mano' => 12, 'marcador de un plan' => 2], $razones, 'con §1.3 al pie de la letra serían 12 name_written y 2 mixed');
    }

    // ── (c) Forma B sobre prosa real ────────────────────────────────────────

    public function test_c_forma_b_con_ranura_sobre_prosa_real(): void
    {
        $this->formaBSobreProsaReal('con', 95);
    }

    public function test_c_forma_b_sin_ranura_sobre_prosa_real(): void
    {
        $this->formaBSobreProsaReal('sin', 111);
    }

    // ── (d) Paridad: ai/check, laboratorio y commit ─────────────────────────

    public function test_d_paridad_ai_check_laboratorio_y_commit_con_la_lista_del_servidor(): void
    {
        $this->assertGreaterThanOrEqual(10, count(self::PARIDAD));
        $variantes = [];
        foreach (self::variantes() as $v) {
            $variantes[$v['id'].' '.$v['forma']] = $v;
        }
        $lab = app(StudioLabEngineCheck::class);
        $textosDeLaLista = $this->textosDeLaLista();

        $previos = $previosEsperados = [];
        $aiCheck = $laboratorio = [];
        $porque = $porqueEsperado = [];
        $origenes = $origenesEsperados = [];
        $listas = $listasEsperadas = [];
        $trazas = $trazasEsperadas = [];
        $predichos = $enviados = $salientes = [];
        $medidasPredichas = $medidasEnviadas = [];
        foreach (self::PARIDAD as [$id, $forma, $origen, $causa]) {
            $clave = $id.' '.$forma;
            $v = $variantes[$clave];
            $conRanura = $forma === 'con';

            $this->nuevaConversacion();
            $m = $this->mensaje(self::entrante($v['escenario']));
            $token = $this->decide($m);
            $firmado = app(UltronDecideToken::class)->peek($token, (int) $m->conversation_id, (int) $m->id);
            $sp = (array) ($firmado['extra']['sp'] ?? []);
            $po = $firmado['extra']['po'] ?? null;
            $borrador = ['reply_draft' => $v['texto'], 'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => 3, 'tools_requested' => []];

            // ai/check (`inspect()`) y el laboratorio (`check()`) sobre el mismo turno.
            $inspeccion = app(UltronDraftCheck::class)->inspect((int) $m->conversation_id, (int) $m->id, $sp, $po, $borrador);
            $turno = $this->turnoDe($m, $firmado);
            $respuesta = $lab->check($turno, 1, $borrador, 0);
            $prediccion = $lab->predict($turno, ['reply_draft' => $v['texto'], 'recommended_plan_id' => 3, 'critic' => self::PASS], ['intent' => SalesIntents::PRICING_QUESTION, 'tools_requested' => []]);
            // El texto que predice el laboratorio: el `render()` único, con lo que lee el laboratorio.
            $predicho = O::render($v['texto'], $turno['po'], $textosDeLaLista, $turno['catalog'], O::serverList($turno['sp']))['text'];

            $r = $this->commit($m, $token, $v['texto']);
            $fila = $this->ultimaAccion();
            $metadata = is_array($fila?->metadata) ? $fila->metadata : [];
            $traza = (array) ($metadata['plans_overview'] ?? []);

            $previos[$clave] = [self::delSp($sp), $po, $r->status()];
            $previosEsperados[$clave] = [self::SP, self::PO, 200];
            $aiCheck[$clave] = [
                'defects' => array_map(static fn (array $d): array => ['code' => $d['code'], 'detail' => $d['detail'], 'issue' => $d['issue']], $inspeccion['defects']),
                'notes' => UltronRegenerationNotes::merge(array_column($inspeccion['defects'], 'note')),
            ];
            $laboratorio[$clave] = ['defects' => $respuesta['defects'], 'notes' => $respuesta['notes']];
            $codigos = array_map(static fn (array $d): string => $d['detail'] === null ? $d['code'] : $d['code'].'/'.$d['detail'], $inspeccion['defects']);
            $porque[$clave] = $causa === null ? $codigos : in_array($causa, $codigos, true);
            $porqueEsperado[$clave] = $causa === null ? [] : true;
            $origenes[$clave] = [$prediccion['origin'], $fila === null ? null : UltronReplyOrigin::classify($metadata, (string) $fila->action_type)['origin']];
            $origenesEsperados[$clave] = [$origen, $origen];
            $listas[$clave] = [$prediccion['list'] ?? null, ['shape' => $traza['shape'] ?? null, 'slot' => $traza['slot'] ?? null]];
            $listasEsperadas[$clave] = [['shape' => O::SHAPE_SERVER, 'slot' => $conRanura], ['shape' => O::SHAPE_SERVER, 'slot' => $conRanura]];
            $trazas[$clave] = [$traza['valid'] ?? null, $traza['detail'] ?? null];
            $trazasEsperadas[$clave] = $causa === 'plans_overview/name_written' ? [false, O::NAME_WRITTEN] : [true, null];
            if ($origen === 'model') {
                $predichos[$clave] = $predicho;
                $enviados[$clave] = $r->json('reply_final');
                $salientes[$clave] = $this->ultimoSaliente()?->body;
                $medidasPredichas[$clave] = $prediccion['chars'];
                $medidasEnviadas[$clave] = UltronReplyFitter::measure((string) $r->json('reply_final'));
            } elseif ($causa === 'length_over_cap') {
                $this->assertGreaterThan(320, $prediccion['measured'], "{$clave}: pasa del tope de la lista");
            }
        }

        $this->assertSame($previosEsperados, $previos, 'el sp y la lista que firma decide, y el commit contesta 200 (la ranura no es un marcador desconocido)');
        $this->assertSame($aiCheck, $laboratorio, 'ai/check y el laboratorio: los mismos defectos y las mismas notas');
        $this->assertSame($porqueEsperado, $porque, 'lo que encuentra ai/check: nada en las 32 limpias; el nombre a mano o la longitud en las demás');
        $this->assertSame($origenesEsperados, $origenes, 'el origen que predice el laboratorio es el del commit');
        $this->assertSame($listasEsperadas, $listas, 'la forma y la ranura: las de predict().list y las de la traza del commit');
        $this->assertSame($trazasEsperadas, $trazas, 'la traza del commit: la lista vale salvo con un nombre a mano');
        $this->assertCount(32, $enviados);
        $this->assertSame($predichos, $enviados, 'el texto que envía el commit es el que predice el laboratorio');
        $this->assertSame($enviados, $salientes, 'y es el que sale por WhatsApp');
        $this->assertSame($medidasPredichas, $medidasEnviadas, 'y mide lo que dice predict()');
    }

    // ── Andamio de (c) ──────────────────────────────────────────────────────

    /**
     * Las comprobaciones de (c) sobre las variantes de una forma ('con' o
     * 'sin' ranura). Lo esperado de cada una sale de §1.2: la lista
     * ({@see self::LISTA}) en la ranura con el resto byte a byte, o la frase
     * ({@see self::FRASE}) justo antes de la última frase si acaba en «?» (si
     * no, al final). Sin ranura se compara sin mirar los espacios: la
     * especificación solo fija que las frases del modelo no cambien de texto ni
     * de orden.
     */
    private function formaBSobreProsaReal(string $forma, int $cuantas): void
    {
        $catalogo = $this->catalogo();
        $sp = self::sp();
        $textos = $this->textosDeLaLista();

        // Lo que la especificación fija para este turno (§1.2).
        $this->assertSame(self::SP, self::delSp($sp), 'el sp de la v4 con el compilador 8');
        $this->assertTrue(O::serverList($sp));
        $this->assertSame([self::RANURA, '; '], ['{{'.O::SLOT.'}}', O::SEPARATOR]);
        $this->assertSame(self::LISTA, O::compose(self::PO, $textos), 'la lista, en el orden de po');
        $this->assertSame(75, mb_strlen(self::LISTA));
        $this->assertSame('Algunos planes de un mes: ', O::HEADER_SOME_MONTHLY);
        $this->assertSame(O::HEADER_SOME_MONTHLY, O::header(self::PO, $catalogo), 'todos de un mes y otro de un mes vendible fuera de la lista (PROMO X4)');
        $this->assertSame(102, mb_strlen(self::FRASE));
        $this->assertSame(320, UltronReplyFitter::plansListCap($sp, self::PO), 'el tope de la lista');
        $this->assertSame([self::fixture6()['scenarios']['E-001'], self::fixture6()['scenarios']['E-006']], [self::entrante('E-001'), self::entrante('E-006')], 'los mismos entrantes en las dos mediciones');
        $turnos = ['planes' => $this->turnoDelLaboratorio(self::entrante('E-001'), $sp), 'combinada' => $this->turnoDelLaboratorio(self::entrante('E-006'), $sp)];
        $this->assertSame([self::PO, self::PO], [$turnos['planes']['po'], $turnos['combinada']['po']], 'el servidor ofrece la lista en los dos');
        $lab = app(StudioLabEngineCheck::class);

        $real = $esperado = [];
        $textosReales = $textosEsperados = [];
        foreach (self::variantes() as $v) {
            if ($v['forma'] !== $forma) {
                continue;
            }
            $clave = $v['id'].' '.$forma;
            $r = O::render($v['texto'], self::PO, $textos, $catalogo, true);
            $p = $lab->predict($turnos[$v['escenario'] === 'E-006' ? 'combinada' : 'planes'], ['reply_draft' => $v['texto'], 'recommended_plan_id' => 3, 'critic' => self::PASS], ['intent' => SalesIntents::PRICING_QUESTION, 'tools_requested' => []]);
            $real[$clave] = [
                'forma' => [O::shape($v['texto']), $r['shape'], $r['slot'], $r['used'], $r['placed']],
                'lista' => substr_count($r['text'], self::LISTA),
                'forma de marcador' => UltronDraftPlaceholders::looksLikeMarker($r['text']),
                'llaves o ranura' => [str_contains($r['text'], '{{'), str_contains($r['text'], '}}'), stripos($r['text'], 'LISTA_DE_PLANES') !== false],
                'tuteo' => UltronRegisterDetector::tuteo($r['text'], $catalogo),
                'tope y recorte' => [$p['cap'], $p['trimmed']],
                'la ranura no es un defecto' => ! in_array(O::verify($v['texto'], self::PO, $catalogo, true), [O::MISSING, O::MIXED, O::UNKNOWN_MARKER, O::NO_LIST, O::PRICE_WRITTEN], true),
            ];
            $esperado[$clave] = [
                'forma' => [O::SHAPE_SERVER, O::SHAPE_SERVER, $forma === 'con', self::PO, true],
                'lista' => 1,
                'forma de marcador' => false,
                'llaves o ranura' => [false, false, false],
                // Las mismas marcas que sin la lista (el detector ya no mira lo que va entre llaves).
                'tuteo' => UltronRegisterDetector::tuteo($v['texto'], $catalogo),
                'tope y recorte' => [320, false],
                'la ranura no es un defecto' => true,
            ];
            $textosReales[$clave] = $forma === 'con' ? $r['text'] : self::sinEspaciosDeMas($r['text']);
            $textosEsperados[$clave] = $forma === 'con' ? $v['esperado'] : self::sinEspaciosDeMas($v['esperado']);
        }
        $this->assertCount($cuantas, $real, "las variantes {$forma} ranura");
        $this->assertSame($esperado, $real);
        $this->assertSame($textosEsperados, $textosReales, $forma === 'con' ? 'la lista en la ranura y el resto byte a byte' : 'la frase de la lista antes de la pregunta final; las del modelo, sin cambiar');
    }

    /**
     * Las variantes de forma B de §4 (c): de cada borrador con marcadores, una
     * con ranura (el tramo del primer `{{` al último `}}` pasa a ser la
     * ranura) y otra sin ranura (ese tramo fuera, sin espacios dobles); el que
     * no lleva marcadores ya es forma B sin ranura y da solo esa. Con lo que
     * debe dejar `render()` según §1.2.
     *
     * @return list<array{id:string,forma:string,texto:string,escenario:string,esperado:string}>
     */
    private static function variantes(): array
    {
        $out = [];
        foreach (self::corpus() as $b) {
            $desde = strpos($b['texto'], '{{');
            $hasta = strrpos($b['texto'], '}}');
            if ($desde === false || $hasta === false || $hasta < $desde) {
                $out[] = ['id' => $b['id'], 'forma' => 'sin', 'texto' => $b['texto'], 'escenario' => $b['escenario'], 'esperado' => self::conLaFraseInsertada($b['texto'])];

                continue;
            }
            $antes = substr($b['texto'], 0, $desde);
            $despues = substr($b['texto'], $hasta + 2);
            $sin = trim((string) preg_replace('/ {2,}/', ' ', $antes.$despues));
            $out[] = ['id' => $b['id'], 'forma' => 'con', 'texto' => $antes.self::RANURA.$despues, 'escenario' => $b['escenario'], 'esperado' => $antes.self::LISTA.$despues];
            $out[] = ['id' => $b['id'], 'forma' => 'sin', 'texto' => $sin, 'escenario' => $b['escenario'], 'esperado' => self::conLaFraseInsertada($sin)];
        }

        return $out;
    }

    /** `place()` sin ranura (§1.2): la frase de la lista justo antes de la última frase de `sentences()` si acaba en «?»; si no, al final, tras un espacio. */
    private static function conLaFraseInsertada(string $prosa): string
    {
        $frases = UltronReplyFitter::sentences($prosa);
        $ultima = $frases === [] ? null : $frases[array_key_last($frases)];
        if ($ultima === null || ! str_ends_with(rtrim($ultima[0]), '?')) {
            return rtrim($prosa).' '.self::FRASE;
        }
        $antes = '';
        foreach (array_slice($frases, 0, -1) as [$frase, $separador]) {
            $antes .= $frase.$separador;
        }

        return $antes.self::FRASE.' '.$ultima[0];
    }

    private static function sinEspaciosDeMas(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    // ── Andamio: el corpus y su lectura propia ──────────────────────────────

    /** @return array<string,mixed> */
    private static function fixture7(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE_C7), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private static function fixture6(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE_C6), true, 16, JSON_THROW_ON_ERROR);
    }

    /**
     * Los 111 textos, en el orden de las fixtures, con lo que dio `ai/check`
     * en producción: el detalle de `plans_overview` (null si no dio ninguno) o
     * false si ese texto no se comprobó.
     *
     * @return list<array{id:string,texto:string,grabado:string|null|false,escenario:string}>
     */
    private static function corpus(): array
    {
        if (self::$corpus !== null) {
            return self::$corpus;
        }
        $detalle = static function (array $codigos): ?string {
            foreach ($codigos as $c) {
                if (str_starts_with($c, UltronRegenerationNotes::PLANS_OVERVIEW.'/')) {
                    return substr($c, strlen(UltronRegenerationNotes::PLANS_OVERVIEW) + 1);
                }
            }

            return null;
        };
        $out = [];
        foreach (self::fixture7()['cases'] as $c) {
            foreach ($c['drafts'] as $d) {
                $out[] = ['id' => 'c7 '.$c['case'].'#'.$d['n'], 'texto' => $d['text'], 'grabado' => $d['check'] === null ? false : $detalle($d['check']['codes']), 'escenario' => substr($c['case'], -5)];
            }
        }
        $c6 = self::fixture6();
        foreach ($c6['firstDrafts'] as $c) {
            $out[] = ['id' => 'c6 '.$c['case'].'#1', 'texto' => $c['text'], 'grabado' => $detalle($c['codes']), 'escenario' => substr($c['case'], -5)];
        }
        foreach ($c6['finalModel'] as $c) {
            $out[] = ['id' => 'c6 '.$c['case'].'#'.$c['draft'], 'texto' => $c['text'], 'grabado' => false, 'escenario' => substr($c['case'], -5)];
        }
        // «Los que ai/check dio por buenos y el revisor rechazó» (la fuente de la fixture): sin código.
        foreach ($c6['combinedSecond'] as $c) {
            $out[] = ['id' => 'c6 '.$c['case'].'#2', 'texto' => $c['text'], 'grabado' => null, 'escenario' => substr($c['case'], -5)];
        }

        return self::$corpus = $out;
    }

    /** @return array{id:string,texto:string,grabado:string|null|false,escenario:string} */
    private static function borrador(string $id): array
    {
        foreach (self::corpus() as $b) {
            if ($b['id'] === $id) {
                return $b;
            }
        }
        self::fail("No hay borrador {$id} en las fixtures.");
    }

    /** El entrante de un escenario (E-001 a E-005, el turno de planes; E-006, la combinada). */
    private static function entrante(string $escenario): string
    {
        return (string) self::fixture7()['scenarios'][$escenario]['turns'][0]['text'];
    }

    /** ¿Forma A? Algún `{{x}}` con x de la forma `PLAN_` y cifras, con cualquier caja (§1.2, `shape()`). */
    private static function formaA(string $texto): bool
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $texto, $m);

        return preg_grep('/\APLAN_[0-9]+\z/i', $m[1]) !== [];
    }

    /** @return list<int> las posiciones de los `{{PLAN_k}}` de la lista, en el orden en que aparecen */
    private static function marcadoresDeLaLista(string $texto): array
    {
        preg_match_all('/\{\{\s*PLAN_([1-9][0-9]*)\s*\}\}/', $texto, $m);

        return array_map('intval', $m[1]);
    }

    /**
     * Los planes de la lista nombrados a mano, con las tres reglas de
     * `CommercialTurnPolicy::plansNamedIn()` escritas aquí: el nombre entero;
     * el corto tras «el/la/del/plan» si no es palabra de calendario («mensual»
     * lo es); el corto con mayúscula inicial. Contrastada con `verify()` de
     * hoy en los 111 textos.
     *
     * @return list<int>
     */
    private static function nombradosAMano(string $texto): array
    {
        $sinTildes = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'];
        $t = strtr(mb_strtolower($texto), $sinTildes);
        $conMayusculas = strtr($texto, $sinTildes + ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
        $planes = [];
        if (preg_match('/(?<![a-z0-9])plan valera(?![a-z0-9])|\b(?:el|la|del|plan) valera\b/u', $t) === 1 || preg_match('/\bValera\b/u', $conMayusculas) === 1) {
            $planes[] = 3;
        }
        if (preg_match('/(?<![a-z0-9])plan mensual(?![a-z0-9])/u', $t) === 1 || preg_match('/\bMensual\b/u', $conMayusculas) === 1) {
            $planes[] = 4;
        }
        if (preg_match('/(?<![a-z0-9])elite(?![a-z0-9])/u', $t) === 1) {
            $planes[] = 8;
        }

        return $planes;
    }

    /** @return list<int> los planes de la lista de los que habla un texto, por su marcador o a mano */
    private static function planesQueNombra(string $texto): array
    {
        $porMarcador = array_map(static fn (int $k): int => self::PO[$k - 1], array_filter(self::marcadoresDeLaLista($texto), static fn (int $k): bool => $k <= count(self::PO)));

        return array_values(array_unique([...$porMarcador, ...self::nombradosAMano($texto)]));
    }

    // ── Andamio: el turno ───────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function catalogo(): array
    {
        return app(MarketingKnowledgeBaseService::class)->activePlans();
    }

    /** @return array<int,string> plan_id → su texto ({@see O::item()}), de los planes de la lista que se venden */
    private function textosDeLaLista(): array
    {
        $textos = [];
        foreach (Plan::query()->sellable()->whereIn('id', self::PO)->get() as $plan) {
            $textos[(int) $plan->id] = O::item($plan);
        }

        return $textos;
    }

    /** @return array<string,mixed> el `sp` que firma el Studio para la v4 con el compilador de este árbol */
    private static function sp(): array
    {
        $parte = self::parteV2(self::VERSION, self::V4);

        return self::historicalListBundle($parte['governed'], self::VERSION, $parte['contentHash'])['sp'];
    }

    /** @return array{m:mixed,n:mixed,t:mixed,o:mixed,k:mixed} lo del `sp` que fija la especificación, en su orden */
    private static function delSp(array $sp): array
    {
        return ['m' => $sp['m'] ?? null, 'n' => $sp['n'] ?? null, 't' => $sp['t'] ?? null, 'o' => $sp['o'] ?? null, 'k' => $sp['k'] ?? null];
    }

    /** @return array<string,mixed> el turno del laboratorio para ese entrante (lo que el motor firmaría y leería) */
    private function turnoDelLaboratorio(string $entrante, array $sp): array
    {
        return app(StudioLabEngineContext::class)->build(['turns' => [['role' => 'prospect', 'text' => $entrante]], 'facts' => ['paymentState' => 'none', 'handoffRequested' => false]], $sp)['turn'];
    }

    /**
     * El turno del laboratorio con lo que el motor tendría al comprobar ESTE
     * mensaje: lo firmado en el token y lo que leería de la conversación (como
     * `StudioLabEngineCheckParityTest`).
     *
     * @param  array<string,mixed>  $firmado  {@see UltronDecideToken::peek()}
     * @return array<string,mixed>
     */
    private function turnoDe(MarketingMessage $m, array $firmado): array
    {
        $this->assertTrue($firmado['valid']);
        $conversacion = $this->conversation->fresh();
        $memoria = app(ConversationMemoryService::class);
        $leida = $memoria->load($conversacion);

        return [
            'sp' => $firmado['extra']['sp'],
            'po' => $firmado['extra']['po'] ?? [],
            'catalog' => app(MarketingKnowledgeBaseService::class)->activePlans(),
            'inbound' => (string) $m->body,
            'phase' => app(CommercialPhaseMachine::class)->normalize($conversacion->commercial_phase),
            'memory' => $leida,
            'resolution' => app(ReferenceResolver::class)->resolve((string) $m->body, $leida, $memoria->sellablePlansForMemory()),
        ];
    }

    private function nuevaConversacion(): void
    {
        $n = str_pad((string) ++$this->mundo, 3, '0', STR_PAD_LEFT);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3150543'.$n, 'meta_user_id' => '573150543'.$n,
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
    }

    private function mensaje(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.formas.'.$this->mundo, 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m): string
    {
        $decide = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id, 'prompt_contract' => 'ultron-prompts/1', 'generation_contract' => 'ultron-list-turn/1',
        ], $this->headers())->assertOk()->json();

        return (string) $this->historicalListTurn($decide, $m)['decide_token'];
    }

    private function commit(MarketingMessage $m, string $token, string $borrador): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => [
                'strategy_goal' => 'resolve_objection',
                'next_state' => CommercialPhaseMachine::GOAL_DISCOVERY,
                'intent' => SalesIntents::PRICING_QUESTION,
                'reply_draft' => $borrador,
                'recommended_plan_id' => 3,
                'main_barrier' => null,
                'next_best_action' => 'answer',
                'confidence' => 0.9,
                'tools_requested' => [],
            ],
            'critic' => self::APROBADO,
        ], $this->headers());
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function ultimaAccion(): ?MarketingAiAction
    {
        return MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->first();
    }

    private function ultimoSaliente(): ?MarketingMessage
    {
        return MarketingMessage::query()->where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first();
    }
}
