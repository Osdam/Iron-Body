<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Marketing\Ultron\UltronDraftCheck;
use App\Services\Marketing\Ultron\UltronDraftDefects;
use App\Services\Marketing\Ultron\UltronRegenerationNotes;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * A4 · «REGENERAR ANTES DE SUSTITUIR», OLA 4: LA COMPROBACIÓN DEL SERVIDOR
 * (`POST /api/internal/marketing/ai/check`, contrato `ultron-regen/1`; plan
 * `plan-arquitecto-a4-regenerar.md` §3 P-1, P-2, P-6 y P-8, §4.1, §4.4 y §6
 * «Ola 4»; decisiones del lead del encargo de la ola 4), por el endpoint y con
 * las clases reales.
 *
 * n8n le pregunta al servidor, entre el redactor y el revisor, si aceptaría el
 * borrador. La puerta es de SOLO LECTURA: ni cerrojo, ni escrituras, ni red,
 * ni despachador. Del token usa `peek()` (firma, caducidad y el par
 * conversación/mensaje), no `verify()`: si el mundo cambió, lo decide el
 * commit. Es un AVISO: el commit no lee su resultado y lo vuelve a comprobar
 * todo (lo fija `UltronCheckCommitParityTest`).
 *
 * Lo que se fija aquí:
 *  - la tabla de seguridad de §3 P-8: 401 sin secreto; un token ajeno,
 *    caducado, alterado o mal formado → 422 `invalid_token` sin detalle; una
 *    clave de más → 422 `unexpected_fields`; solo `select`, ningún cerrojo,
 *    ninguna petición HTTP, nada despachado, cero filas nuevas; ni el texto de
 *    la persona ni el token en la respuesta o en el log; el borrador con una
 *    URL o con datos de tarjeta PASA la comprobación (no es un oráculo de las
 *    guardas) y el commit lo tumba igual;
 *  - cada código con su nota LITERAL (§7.2);
 *  - el presupuesto: `attempt` 3 → `last_attempt`; 26 s desde `decide` →
 *    `time_budget` (con `Carbon::setTestNow` EN UTC, la trampa conocida);
 *  - sin versión del Studio, `legacy` y sin leer nada;
 *  - `decide` ofrece el bloque `regeneration` solo con versión del Studio;
 *  - ola 4b: las seis reglas de política corregibles, cada una con su nota
 *    LITERAL (encargo de la ola 4b), DETRÁS de los cuatro de §4.4 y con el
 *    tope de `UltronDraftDefects::MAX_DEFECTS`; la política se LEE (con la
 *    memoria: ni una escritura) y su lector no nombra nada que escriba.
 *
 * El escenario es el de `UltronA4ListaDelModeloTest`: la versión publicada en
 * la canaria («profesional», 200), los 13 planes vendibles de producción y la
 * ficha del horario.
 */
class UltronCheckEndpointTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000c4';

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

    private const TRES = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?';

    private const TRES_DE_TU = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál te interesa más?';

    /** La lista de usted, larga: sin expandir cabe en 200; expandida, no. */
    private const LARGO = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes y con acceso total al gimnasio. Le explico con calma lo que incluye cada uno para que elija con tranquilidad. ¿Cuál le interesa más?';

    /**
     * PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): con la lista, el tope aplicable es 320.
     * Esta lista, sin expandir, cabe en 320 (298); expandida, no (339).
     */
    private const LARGO_320 = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes y con acceso total al gimnasio. Le explico con calma lo que incluye cada uno para que elija con tranquilidad, sin prisa y con toda la información que necesite antes de decidir, para que empiece con buen pie. ¿Cuál le interesa más?';

    private const LARGO_320_EXPANDIDO = 'Claro. Tenemos Plan Valera ($65.000 COP), Plan Mensual ($80.000 COP) y Élite ($180.000 COP), todos de un mes y con acceso total al gimnasio. Le explico con calma lo que incluye cada uno para que elija con tranquilidad, sin prisa y con toda la información que necesite antes de decidir, para que empiece con buen pie. ¿Cuál le interesa más?';

    /** La respuesta de la puerta, en su orden (§4.1): la lista blanca de claves. */
    private const CLAVES = ['ok', 'contract', 'mode', 'attempt', 'pass', 'defects', 'issues', 'notes', 'may_rewrite', 'rewrite', 'budget'];

    // ── Las notas de §7.2, LITERALES (copiadas a mano del plan) ─────────────

    /*
     * 22-11-2026 (corrección (1) del turno 2 de la tercera prueba real): con
     * la lista y el tope (aquí, siempre 200), N-P1, N-P2 y N-L salen en su
     * variante con el esqueleto y el presupuesto de la lista; con este
     * catálogo, los marcadores ocupan 25 + 26 + 20 = 71 y quedan 89 de 160.
     */
    private const N_P1 = 'FALTA LA LISTA DE PLANES: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME ni PLAN_PRICE. Ya expandidos ocupan 71 caracteres: quedan 89 de 160. Si no caben, dos; una pregunta corta o ninguna.';

    private const N_P2 = 'MARCADOR REPETIDO O MEZCLADO: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION. Ya expandidos ocupan 71 caracteres: quedan 89 de 160.';

    private const N_P3 = 'NOMBRE DUPLICADO: no escribas el nombre de un plan de la lista; su marcador ya pone el nombre y el precio.';

    private const N_P4_K3 = 'MARCADOR INEXISTENTE: este turno solo tiene PLAN_1 a PLAN_3. Usa solo esos, entre dobles llaves.';

    private const N_P5 = 'SIN LISTA DE PLANES: en este turno no uses PLAN_1, PLAN_2 ni PLAN_3. Para un precio usa PLAN_PRICE con el plan de la estrategia.';

    private const N_P6 = 'SIN CIFRAS: no escribas precios ni cifras de dinero; el backend los pone con los marcadores.';

    private const N_R_TE = 'REESCRIBE DE USTED: la version publicada trata a la persona de usted y el borrador tutea (te). Pasa todo el mensaje a usted sin mezclar (le, su, usted, cuenteme, quiere) y conserva lo que decia.';

    /** PT-17b: las mismas notas con las cifras del tope de la lista (320 y su objetivo, 250; quedan 250 − 71 = 179). */
    private const N_P2_LISTA = 'MARCADOR REPETIDO O MEZCLADO: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION. Ya expandidos ocupan 71 caracteres: quedan 179 de 250.';

    private const N_L_LISTA = 'DEMASIADO LARGO: mide %d caracteres y el limite publicado es 320. Reescribelo en unos 250: primero la respuesta, sin relleno. Los marcadores de la lista ya ocupan 71: quedan 179. Si no caben, dos planes; una pregunta corta o ninguna.';

    private const N_B = 'BENEFICIO DE OTRO PLAN: el borrador atribuye a PLAN_1 un beneficio que en active_plans es de otro plan ("Entrenador personal dedicado"). A cada plan, solo los benefits de su plan_id.';

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
        $this->publica();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Un borrador que pasa, uno que pide reescritura y uno sin presupuesto ──

    public function test_un_borrador_que_pasa(): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        $r = $this->check($m, $token, self::TRES)->assertOk();

        $this->assertSame([
            'ok' => true,
            'contract' => 'ultron-regen/1',
            'mode' => 'studio',
            'attempt' => 1,
            'pass' => true,
            'defects' => [],
            'issues' => [],
            'notes' => '',
            'may_rewrite' => true,
            'rewrite' => false,
            'budget' => ['max_drafts' => 3, 'elapsed_s' => 0, 'reason' => null],
        ], $r->json());
    }

    public function test_un_borrador_que_tutea_y_se_pasa_pide_reescritura_con_sus_notas(): void
    {
        // PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): afirmaba tuteo + longitud con la lista de 241 contra 200; esa lista cabe en el tope aplicable (320) y ahora solo da el tuteo, y tuteo + longitud se afirman con una lista de 339.
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        Carbon::setTestNow(Carbon::parse('2026-11-18 22:47:09', 'UTC'));
        $cabe = $this->check($m, $token, str_replace('¿Cuál le interesa más?', '¿Cuál te interesa más?', self::LARGO))->assertOk();
        $this->assertSame([['code' => 'register_tuteo', 'detail' => null, 'issue' => 'naturalness']], $cabe->json('defects'));
        $this->assertSame([self::N_R_TE, true], [$cabe->json('notes'), $cabe->json('rewrite')]);

        $borrador = str_replace('¿Cuál le interesa más?', '¿Cuál te interesa más?', self::LARGO_320);

        $r = $this->check($m, $token, $borrador)->assertOk();

        $largo = UltronReplyFitter::measure(str_replace('¿Cuál le interesa más?', '¿Cuál te interesa más?', self::LARGO_320_EXPANDIDO));
        $this->assertSame([
            'ok' => true,
            'contract' => 'ultron-regen/1',
            'mode' => 'studio',
            'attempt' => 1,
            'pass' => false,
            'defects' => [
                ['code' => 'register_tuteo', 'detail' => null, 'issue' => 'naturalness'],
                ['code' => 'length_over_cap', 'detail' => null, 'issue' => 'progressive_disclosure'],
            ],
            'issues' => ['naturalness', 'progressive_disclosure'],
            'notes' => self::N_R_TE.' '.sprintf(self::N_L_LISTA, $largo),
            'may_rewrite' => true,
            'rewrite' => true,
            'budget' => ['max_drafts' => 3, 'elapsed_s' => 9, 'reason' => null],
        ], $r->json());
        $this->assertGreaterThan(320, $largo);
    }

    public function test_el_tercer_borrador_no_tiene_presupuesto(): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        $r = $this->check($m, $token, self::TRES_DE_TU, attempt: 3)->assertOk();

        $this->assertSame([
            'ok' => true,
            'contract' => 'ultron-regen/1',
            'mode' => 'studio',
            'attempt' => 3,
            'pass' => false,
            'defects' => [['code' => 'register_tuteo', 'detail' => null, 'issue' => 'naturalness']],
            'issues' => ['naturalness'],
            'notes' => self::N_R_TE,
            'may_rewrite' => false,
            'rewrite' => false,
            'budget' => ['max_drafts' => 3, 'elapsed_s' => 0, 'reason' => 'last_attempt'],
        ], $r->json());

        // El segundo, con defecto, todavía puede.
        $this->assertTrue($this->check($m, $token, self::TRES_DE_TU, attempt: 2)->assertOk()->json('rewrite'));
    }

    /** El techo real es el job de 45 s (H13): pasados 25 s desde `decide` no se concede otra redacción. */
    public function test_pasados_25_segundos_no_hay_presupuesto(): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        Carbon::setTestNow(Carbon::parse('2026-11-18 22:47:25', 'UTC'));
        $r = $this->check($m, $token, self::TRES_DE_TU)->assertOk();
        $this->assertSame([true, true, ['max_drafts' => 3, 'elapsed_s' => 25, 'reason' => null]], [$r->json('may_rewrite'), $r->json('rewrite'), $r->json('budget')], 'a los 25 s justos, todavía');

        Carbon::setTestNow(Carbon::parse('2026-11-18 22:47:26', 'UTC'));
        $r = $this->check($m, $token, self::TRES_DE_TU)->assertOk();
        $this->assertSame([false, false, false, ['max_drafts' => 3, 'elapsed_s' => 26, 'reason' => 'time_budget']], [$r->json('pass'), $r->json('may_rewrite'), $r->json('rewrite'), $r->json('budget')]);
        $this->assertSame(['register_tuteo'], array_column($r->json('defects'), 'code'), 'los defectos se dicen igual: sirven a la traza');

        // Y sin defectos, el presupuesto se informa igual.
        $r = $this->check($m, $token, self::TRES)->assertOk();
        $this->assertSame([true, false, false, 'time_budget'], [$r->json('pass'), $r->json('may_rewrite'), $r->json('rewrite'), $r->json('budget.reason')]);

        // Las dos razones a la vez: manda la del número de borradores.
        $this->assertSame('last_attempt', $this->check($m, $token, self::TRES_DE_TU, attempt: 3)->assertOk()->json('budget.reason'));
    }

    /**
     * m-8 del revisor: el tope de 25 s lo defendía una sola prueba (la de
     * arriba), y quitarlo o subirlo al TTL del token tumbaba solo esa. Esta lo
     * defiende por otro lado: con el segundo borrador, a los 24 s todavía se
     * concede; a los 26, a los 60 y a los 119 s (el token aún vale: caduca a
     * los 120), ya no.
     */
    public function test_el_tope_de_25_segundos_vale_para_el_segundo_borrador_hasta_el_ttl(): void
    {
        $this->assertSame(25, UltronDraftCheck::TIME_BUDGET_SECONDS);
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        foreach ([24 => true, 26 => false, 60 => false, 119 => false] as $segundos => $concede) {
            Carbon::setTestNow(Carbon::parse('2026-11-18 22:47:00', 'UTC')->addSeconds($segundos));
            $r = $this->check($m, $token, self::TRES_DE_TU, attempt: 2)->assertOk();
            $this->assertSame(
                [$concede, $concede, $concede ? null : 'time_budget', $segundos],
                [$r->json('may_rewrite'), $r->json('rewrite'), $r->json('budget.reason'), $r->json('budget.elapsed_s')],
                "{$segundos} s",
            );
        }
    }

    // ── Correcciones tras las revisiones (encargo `encargo-be-r6.md`) ────────

    /**
     * C2 · I-2 del revisor: el borrador del 18-11 («Plan Valera» a mano con
     * `{{PLAN_PRICE}}`, ningún marcador de la lista) recibe la nota que le pide
     * la lista (N-P1), no la que le pide quitar el nombre.
     */
    public function test_el_borrador_del_18_11_recibe_la_nota_de_la_lista(): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        $r = $this->check($m, $token, 'Claro. El Plan Valera cuesta {{PLAN_PRICE}} al mes e incluye acceso total al gimnasio. ¿Le gustaría conocer más?', plan: 3)->assertOk();

        $this->assertSame([['code' => 'plans_overview', 'detail' => 'missing', 'issue' => 'relevance']], $r->json('defects'));
        $this->assertSame(self::N_P1, $r->json('notes'));
        $this->assertSame([false, true, true], [$r->json('pass'), $r->json('may_rewrite'), $r->json('rewrite')]);
    }

    /**
     * C3 · I-3 del revisor: cada dato al que remite una nota es uno que el
     * redactor RECIBE. Las notas nombran claves de `commercial_turn_policy`, y
     * todas viajan en `decide` (la lista blanca de `politicaDelTurno()`);
     * ninguna nombra `forbidden_plan_ids`, que no viaja.
     */
    public function test_cada_nota_remite_a_un_dato_que_el_redactor_recibe(): void
    {
        $politica = $this->decide($this->entrante(self::PANORAMA_CON_PRECIO), true)['context']['commercial_turn_policy'];

        $vistas = [];
        foreach (UltronRegenerationNotes::entries() as [$code, $detail, , $plantilla]) {
            $this->assertStringNotContainsString('forbidden_plan_ids', $plantilla, $code.'/'.$detail);
            preg_match_all('/commercial_turn_policy\.([a-z_]+)/', $plantilla, $m);
            foreach ($m[1] as $clave) {
                $this->assertArrayHasKey($clave, $politica, "{$code}/{$detail} remite a {$clave}, que no viaja");
                $vistas[$clave] = true;
            }
        }
        ksort($vistas);
        $this->assertSame(['allowed_plan_ids', 'plans_overview', 'required_plan_id'], array_keys($vistas));
    }

    /**
     * C6 · R-8 de seguridad: una nota no tumba la puerta. Un plan cuyo nombre
     * queda vacío al sanearlo (solo llaves) y al que el borrador cuelga un
     * beneficio de otro: la nota lo llama por su marcador y la puerta contesta
     * 200 (antes, `InvalidArgumentException` → 500).
     */
    public function test_un_nombre_que_queda_vacio_no_tumba_la_puerta(): void
    {
        $this->beneficiosDistintos();
        Plan::query()->whereKey(3)->update(['name' => '{{}}']);
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        $r = $this->check($m, $token, 'Claro. El {{}} incluye entrenador personal dedicado. También tenemos {{PLAN_2}}. ¿Cuál le interesa?')->assertOk();

        $this->assertContains('benefit_misattributed', array_column($r->json('defects'), 'code'));
        $this->assertStringContainsString('el borrador atribuye a PLAN_1 un beneficio que en active_plans es de otro plan ("Entrenador personal dedicado")', $r->json('notes'));
        $this->assertDoesNotMatchRegularExpression('/[{}]/', $r->json('notes'));
    }

    // ── Cada código con su nota literal (§4.4 y §7.2) ──────────────────────

    /** @return array<string, array{string, string, string, ?int, array<int,array{0:string,1:?string,2:string}>, string}> */
    public static function defectos(): array
    {
        return [
            'tuteo' => [self::PANORAMA_CON_PRECIO, SalesIntents::PRICING_QUESTION, self::TRES_DE_TU, null, [['register_tuteo', null, 'naturalness']], self::N_R_TE],
            'falta la lista' => [self::PANORAMA_CON_PRECIO, SalesIntents::PRICING_QUESTION, 'Claro. Tenemos {{PLAN_1}}, de un mes. ¿Le interesa?', null, [['plans_overview', 'missing', 'relevance']], self::N_P1],
            // PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): afirmaba «quedan 89 de 160»; con dos marcadores de la lista, las cifras del tope de lista («quedan 179 de 250»).
            'mezcla' => [self::PANORAMA_CON_PRECIO, SalesIntents::PRICING_QUESTION, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}} desde {{PLAN_PRICE}}. ¿Cuál le interesa?', 3, [['plans_overview', 'mixed', 'relevance']], self::N_P2_LISTA],
            'repetido' => [self::PANORAMA_CON_PRECIO, SalesIntents::PRICING_QUESTION, 'Claro. Tenemos {{PLAN_1}} y otra vez {{PLAN_1}}. ¿Cuál le interesa?', null, [['plans_overview', 'mixed', 'relevance']], self::N_P2],
            'nombre a mano' => [self::PANORAMA_CON_PRECIO, SalesIntents::PRICING_QUESTION, 'Claro. Tenemos el Plan Valera {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa?', null, [['plans_overview', 'name_written', 'relevance']], self::N_P3],
            'marcador inexistente' => [self::PANORAMA_CON_PRECIO, SalesIntents::PRICING_QUESTION, 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. ¿Cuál le interesa?', null, [['plans_overview', 'unknown_marker', 'relevance']], self::N_P4_K3],
            'cifra escrita' => [self::PANORAMA_CON_PRECIO, SalesIntents::PRICING_QUESTION, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}} desde $50.000. ¿Cuál le interesa?', null, [['plans_overview', 'price_written', 'factuality']], self::N_P6],
            'marcador sin lista' => [self::HORARIO, SalesIntents::SCHEDULE_QUESTION, 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. Tenemos {{PLAN_1}}. ¿Le cuento más?', null, [['plans_overview', 'no_list', 'relevance']], self::N_P5],
        ];
    }

    /** @param array<int,array{0:string,1:?string,2:string}> $esperados */
    #[DataProvider('defectos')]
    public function test_cada_codigo_con_su_nota_literal(string $entrante, string $intent, string $borrador, ?int $plan, array $esperados, string $nota): void
    {
        [$m, $token] = $this->turno($entrante);

        $r = $this->check($m, $token, $borrador, intent: $intent, plan: $plan)->assertOk();

        $this->assertSame(array_map(fn (array $d) => ['code' => $d[0], 'detail' => $d[1], 'issue' => $d[2]], $esperados), $r->json('defects'));
        $this->assertSame(array_values(array_unique(array_column($esperados, 2))), $r->json('issues'));
        $this->assertSame($nota, $r->json('notes'));
        $this->assertSame([false, true, true], [$r->json('pass'), $r->json('may_rewrite'), $r->json('rewrite')]);
        $this->assertStringNotContainsString('{{', $r->json('notes'));
    }

    public function test_la_longitud_se_mide_expandida_con_su_nota_literal(): void
    {
        // PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): afirmaba length_over_cap para la lista de 228 contra 200; con la lista el tope aplicable es 320, así que el borrador es uno que sin expandir cabe en 320 y expandido no, con la nota de 320.
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        $this->assertLessThanOrEqual(320, mb_strlen(self::LARGO_320), 'sin expandir cabría');

        $r = $this->check($m, $token, self::LARGO_320)->assertOk();

        $this->assertSame([['code' => 'length_over_cap', 'detail' => null, 'issue' => 'progressive_disclosure']], $r->json('defects'));
        $this->assertSame(sprintf(self::N_L_LISTA, UltronReplyFitter::measure(self::LARGO_320_EXPANDIDO)), $r->json('notes'));
    }

    public function test_el_beneficio_de_otro_plan_con_su_nota_literal(): void
    {
        $this->beneficiosDistintos();
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        $r = $this->check($m, $token, 'Claro. {{PLAN_1}} incluye entrenador personal dedicado. También tenemos {{PLAN_2}}. ¿Cuál le interesa?')->assertOk();

        $this->assertSame([['code' => 'benefit_misattributed', 'detail' => null, 'issue' => 'factuality']], $r->json('defects'));
        $this->assertSame(['factuality'], $r->json('issues'));
        $this->assertSame(self::N_B, $r->json('notes'));

        // El mismo beneficio en su plan: pasa.
        $this->assertTrue($this->check($m, $token, 'Claro. {{PLAN_3}} incluye entrenador personal dedicado. También tenemos {{PLAN_1}}. ¿Cuál le interesa?')->assertOk()->json('pass'));
    }

    /**
     * Decisión del lead (encargo de la ola 4, laguna de §4.1, paso 4a): un
     * `PLAN_k` que no es de la lista se señala SIEMPRE, se pida o no el
     * panorama y con o sin herramientas; en un turno de cobro, cualquier
     * `PLAN_k` es `no_list` (la lista no vale con dinero). El commit rechaza los
     * dos (422 `unknown_placeholder`): lo fija la paridad.
     */
    public function test_un_marcador_que_no_es_de_la_lista_se_senala_siempre(): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        $borrador = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. Le dejo también los enlaces de la app. ¿Cuál le interesa?';

        $r = $this->check($m, $token, $borrador, tools: ['app_links_send'])->assertOk();
        $this->assertSame([['code' => 'plans_overview', 'detail' => 'unknown_marker', 'issue' => 'relevance']], $r->json('defects'), 'con herramientas');

        $r = $this->check($m, $token, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. Le dejo también los enlaces de la app. ¿Cuál le interesa?', tools: ['app_links_send'])->assertOk();
        $this->assertSame([], $r->json('defects'), 'con herramientas y marcadores de la lista, nada');

        // Sin pedir el precio («¿qué membresías tienen?»: intención desconocida) y con dos planes nombrados.
        [$m, $token] = $this->turno('¿Qué membresías tienen?');
        $r = $this->check($m, $token, 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. ¿Cuál le interesa?', intent: SalesIntents::GENERAL_INFO)->assertOk();
        $this->assertSame([['code' => 'plans_overview', 'detail' => 'unknown_marker', 'issue' => 'relevance']], $r->json('defects'), 'sin pedir el precio');
        $this->assertSame(self::N_P4_K3, $r->json('notes'));

        // Turno de cobro: con la lista ofrecida, los marcadores de la lista pasan a no valer.
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        foreach ([[SalesIntents::PAYMENT_LINK_REQUEST, []], [SalesIntents::HIGH_INTENT_CLOSE, []], [SalesIntents::PRICING_QUESTION, ['payment_link_send']]] as [$intent, $tools]) {
            $r = $this->check($m, $token, self::TRES, intent: $intent, tools: $tools)->assertOk();
            $this->assertSame([['code' => 'plans_overview', 'detail' => 'no_list', 'issue' => 'relevance']], $r->json('defects'), $intent);
            $this->assertSame(self::N_P5, $r->json('notes'));
        }
    }

    // ── Sin versión del Studio: legacy, sin leer nada (P-6) ─────────────────

    public function test_sin_version_del_studio_contesta_legacy_sin_leer_nada(): void
    {
        foreach (['sin negociar' => false, 'prompts del Studio apagados' => true] as $caso => $negocia) {
            $this->nuevaConversacion();
            $this->publica();
            if ($negocia) {
                config()->set('marketing.ultron.studio_prompts', 'off');
            }
            [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO, $negocia);

            $sql = $this->escuchaLasConsultas();
            $r = $this->check($m, $token, self::TRES_DE_TU.' '.str_repeat('Le cuento todo con calma. ', 10), attempt: 2)->assertOk();

            $this->assertSame([
                'ok' => true,
                'contract' => 'ultron-regen/1',
                'mode' => 'legacy',
                'attempt' => 2,
                'pass' => true,
                'defects' => [],
                'issues' => [],
                'notes' => '',
                'may_rewrite' => false,
                'rewrite' => false,
                'budget' => ['max_drafts' => 2, 'elapsed_s' => null, 'reason' => 'legacy'],
            ], $r->json(), $caso);
            $this->assertSame([], $sql(), $caso.': ni el mensaje ni los planes');
        }
    }

    // ── `decide` ofrece el contrato solo con versión del Studio (§4.2) ──────

    public function test_decide_ofrece_el_contrato_solo_con_version_del_studio(): void
    {
        $m = $this->entrante(self::HORARIO);
        $decide = $this->decide($m, true);
        $this->assertSame(['contract' => 'ultron-regen/1', 'max_drafts' => 3], $decide['regeneration']);
        $this->assertSame('studio', $decide['studio_prompts']['mode']);

        $this->nuevaConversacion();
        $this->publica();
        $this->assertArrayNotHasKey('regeneration', $this->decide($this->entrante(self::HORARIO), false), 'sin negociar');

        $this->nuevaConversacion();
        $this->publica();
        config()->set('marketing.ultron.studio_prompts', 'off');
        $legacy = $this->decide($this->entrante(self::HORARIO), true);
        $this->assertSame('legacy', $legacy['studio_prompts']['mode']);
        $this->assertArrayNotHasKey('regeneration', $legacy, 'negociando, pero sin versión del Studio');
    }

    // ── Seguridad (§3 P-8) ──────────────────────────────────────────────────

    public function test_sin_secreto_401(): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        $cuerpo = $this->cuerpo($m, $token, self::TRES);

        $this->postJson('/api/internal/marketing/ai/check', $cuerpo)->assertStatus(401);
        $this->postJson('/api/internal/marketing/ai/check', $cuerpo, ['Authorization' => 'Bearer otro-secreto'])->assertStatus(401);
        $this->postJson('/api/internal/marketing/ai/check', $cuerpo, $this->headers())->assertOk();
    }

    /** @return array<string, array{\Closure}> */
    public static function tokensQueNoValen(): array
    {
        return [
            // El token ata el par conversación/mensaje: sin token válido para ESE par no se mira nada ajeno (IDOR).
            'de otra conversación' => [function (self $t): array {
                [, $token] = $t->turno(self::PANORAMA_CON_PRECIO);
                $t->nuevaConversacion();
                $t->publica();
                $ajeno = $t->entrante(self::PANORAMA_CON_PRECIO);

                return [$ajeno, $token];
            }],
            'de otro mensaje de la misma conversación' => [function (self $t): array {
                [, $token] = $t->turno(self::PANORAMA_CON_PRECIO);

                return [$t->entrante('¿Y el horario?'), $token];
            }],
            'caducado' => [function (self $t): array {
                [$m, $token] = $t->turno(self::PANORAMA_CON_PRECIO);
                Carbon::setTestNow(Carbon::parse('2026-11-18 22:49:01', 'UTC'));

                return [$m, $token];
            }],
            'con la firma alterada' => [function (self $t): array {
                [$m, $token] = $t->turno(self::PANORAMA_CON_PRECIO);
                $ultimo = substr($token, -1);

                return [$m, substr($token, 0, -1).($ultimo === 'a' ? 'b' : 'a')];
            }],
            'con la carga alterada y la firma de antes' => [function (self $t): array {
                [$m, $token] = $t->turno(self::PANORAMA_CON_PRECIO);
                [$cuerpo, $firma] = explode('.', $token);
                $carga = self::cargaDelToken($token);
                $carga['x']['sp']['t'] = 'cercano';

                return [$m, rtrim(strtr(base64_encode(json_encode($carga)), '+/', '-_'), '=').'.'.$firma];
            }],
            'sin punto' => [function (self $t): array {
                [$m] = $t->turno(self::PANORAMA_CON_PRECIO);

                return [$m, 'nada'];
            }],
            'con tres partes' => [function (self $t): array {
                [$m, $token] = $t->turno(self::PANORAMA_CON_PRECIO);

                return [$m, $token.'.otra'];
            }],
            'firmado pero sin JSON dentro' => [function (self $t): array {
                [$m] = $t->turno(self::PANORAMA_CON_PRECIO);
                $cuerpo = 'esto-no-es-json';

                return [$m, $cuerpo.'.'.hash_hmac('sha256', $cuerpo, (string) config('app.key'))];
            }],
        ];
    }

    #[DataProvider('tokensQueNoValen')]
    public function test_un_token_que_no_vale_da_422_invalid_token_sin_detalle(\Closure $prepara): void
    {
        [$m, $token] = $prepara($this);

        $r = $this->check($m, $token, self::TRES_DE_TU);

        $r->assertStatus(422);
        $this->assertSame(['ok' => false, 'code' => 'invalid_token'], $r->json(), 'ni el motivo, ni la conversación, ni nada del token');
    }

    public function test_una_clave_de_mas_da_422_unexpected_fields(): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        $raiz = $this->cuerpo($m, $token, self::TRES) + ['phone' => '3001234567'];
        $this->postJson('/api/internal/marketing/ai/check', $raiz, $this->headers())
            ->assertStatus(422)->assertJsonPath('code', 'unexpected_fields')->assertJsonPath('fields', ['phone']);

        $enElBorrador = $this->cuerpo($m, $token, self::TRES);
        $enElBorrador['draft']['price'] = 80000;
        $enElBorrador['draft']['critic_notes'] = 'otra cosa';
        $this->postJson('/api/internal/marketing/ai/check', $enElBorrador, $this->headers())
            ->assertStatus(422)->assertJsonPath('code', 'unexpected_fields')->assertJsonPath('fields', ['draft.price', 'draft.critic_notes']);
    }

    /** @return array<string, array{\Closure, string}> */
    public static function formasQueNoValen(): array
    {
        return [
            'sin conversación' => [fn (array $c) => array_diff_key($c, ['conversation_id' => 1]), 'conversation_id'],
            'conversación cero' => [fn (array $c) => ['conversation_id' => 0] + $c, 'conversation_id'],
            'sin mensaje' => [fn (array $c) => array_diff_key($c, ['message_id' => 1]), 'message_id'],
            'sin token' => [fn (array $c) => array_diff_key($c, ['decide_token' => 1]), 'decide_token'],
            'token enorme' => [fn (array $c) => ['decide_token' => str_repeat('a', 2049)] + $c, 'decide_token'],
            'sin intento' => [fn (array $c) => array_diff_key($c, ['attempt' => 1]), 'attempt'],
            'intento cero' => [fn (array $c) => ['attempt' => 0] + $c, 'attempt'],
            'cuarto intento' => [fn (array $c) => ['attempt' => 4] + $c, 'attempt'],
            'sin borrador' => [fn (array $c) => array_diff_key($c, ['draft' => 1]), 'draft'],
            'borrador como texto' => [fn (array $c) => ['draft' => 'Claro.'] + $c, 'draft'],
            'texto vacío' => [fn (array $c) => array_replace_recursive($c, ['draft' => ['reply_draft' => '']]), 'draft.reply_draft'],
            'texto de más de 4000' => [fn (array $c) => array_replace_recursive($c, ['draft' => ['reply_draft' => str_repeat('a', 4001)]]), 'draft.reply_draft'],
            'intención inventada' => [fn (array $c) => array_replace_recursive($c, ['draft' => ['intent' => 'vender_ya']]), 'draft.intent'],
            'plan cero' => [fn (array $c) => array_replace_recursive($c, ['draft' => ['recommended_plan_id' => 0]]), 'draft.recommended_plan_id'],
            'herramienta inventada' => [fn (array $c) => array_replace_recursive($c, ['draft' => ['tools_requested' => ['activar_membresia']]]), 'draft.tools_requested.0'],
            'seis herramientas' => [fn (array $c) => array_replace_recursive($c, ['draft' => ['tools_requested' => ['staff_review', 'staff_review', 'staff_review', 'staff_review', 'staff_review', 'staff_review']]]), 'draft.tools_requested'],
        ];
    }

    #[DataProvider('formasQueNoValen')]
    public function test_la_forma_se_valida_en_el_borde(\Closure $estropea, string $campo): void
    {
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

        $r = $this->postJson('/api/internal/marketing/ai/check', $estropea($this->cuerpo($m, $token, self::TRES)), $this->headers());

        $r->assertStatus(422)->assertJsonValidationErrors([$campo]);
    }

    /**
     * Ni escritura, ni cerrojo, ni red, ni despachador: solo `select`, ninguna
     * llamada a `Cache::lock`, ninguna petición HTTP, nada en la cola y cero
     * filas nuevas en las tablas de Marketing.
     */
    public function test_solo_lee(): void
    {
        $this->beneficiosDistintos();
        [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);
        $antes = $this->filasDeMarketing();
        $conversacion = $this->conversation->fresh()->toArray();

        Cache::partialMock()->shouldReceive('lock')->never();
        Queue::fake();
        Bus::fake();
        $sql = $this->escuchaLasConsultas();

        // Un borrador con todos los defectos (lista, tuteo, longitud, beneficio) y con marcadores de precio y de la app.
        $this->check($m, $token, 'Te cuento: {{PLAN_1}} incluye entrenador personal dedicado, y el {{PLAN_NAME}} cuesta {{PLAN_PRICE}}. {{APP_LINKS}} '.str_repeat('Es una gran opción para empezar a entrenar. ', 4), plan: 4, tools: ['app_links_send'])->assertOk();
        $this->check($m, $token, self::TRES)->assertOk();

        $consultas = $sql();
        $this->assertNotSame([], $consultas, 'lee algo: el mensaje y los planes');
        foreach ($consultas as $q) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $q);
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        $this->assertSame($antes, $this->filasDeMarketing(), 'cero filas nuevas');
        $this->assertSame($conversacion, $this->conversation->fresh()->toArray(), 'ni la memoria ni nada de la conversación');
    }

    /**
     * Ni el texto de la persona, ni el token, ni datos personales: en la
     * respuesta solo hay códigos, números, marcas de la lista cerrada del
     * detector y beneficios del catálogo; en el log, lo mismo.
     */
    public function test_ni_la_respuesta_ni_el_log_llevan_texto_de_la_persona_ni_el_token(): void
    {
        $this->beneficiosDistintos();
        [$m, $token] = $this->turno('Hola, soy Ramiro Zuluetagüe del conjunto QX-77812, mi celular es 3209876543. '.self::PANORAMA_CON_PRECIO);
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs): void {
            $logs[] = $e->message.' '.json_encode($e->context, JSON_UNESCAPED_UNICODE);
        });

        $borrador = 'Claro Ramiro Zuluetagüe, te cuento: {{PLAN_1}} incluye entrenador personal dedicado. '.str_repeat('Te escribo al 3209876543 con todo. ', 5);
        $r = $this->check($m, $token, $borrador)->assertOk();
        foreach (['register_tuteo', 'length_over_cap', 'benefit_misattributed'] as $codigo) {
            $this->assertContains($codigo, array_column($r->json('defects'), 'code'), 'las notas llevan parámetros: marcas, números y un beneficio');
        }
        $this->check($m, $token.'x', $borrador)->assertStatus(422);

        $this->assertSame(self::CLAVES, array_keys($r->json()), 'la lista blanca de claves');
        $this->assertSame(['max_drafts', 'elapsed_s', 'reason'], array_keys($r->json('budget')));
        foreach ($r->json('defects') as $d) {
            $this->assertSame(['code', 'detail', 'issue'], array_keys($d));
        }
        $salidas = [$r->getContent(), ...$logs];
        $this->assertNotSame([], $logs, 'el log se escucha');
        foreach ($salidas as $salida) {
            foreach (['3209876543', 'Ramiro', 'Zuluetag', 'QX-77812', $token, explode('.', $token)[1]] as $dato) {
                $this->assertStringNotContainsString($dato, $salida);
            }
        }
    }

    /**
     * No es un oráculo para esquivar las guardas: solo habla de registro,
     * longitud, lista y beneficios. Un borrador con una URL o que pide los
     * datos de la tarjeta PASA la comprobación, y el commit lo tumba igual.
     */
    public function test_no_es_un_oraculo_de_las_guardas(): void
    {
        foreach ([
            OutboundContentGuard::CODE_URL_IN_REPLY => 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. Vea todo en ironbody.co/planes. ¿Cuál le interesa?',
            OutboundContentGuard::CODE_CARD_DATA_REQUEST => 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. Mándeme los 16 dígitos de su tarjeta y lo dejamos listo.',
        ] as $codigo => $borrador) {
            $this->nuevaConversacion();
            $this->publica();
            [$m, $token] = $this->turno(self::PANORAMA_CON_PRECIO);

            $check = $this->check($m, $token, $borrador)->assertOk();
            $this->assertSame([true, []], [$check->json('pass'), $check->json('defects')], $codigo);

            $this->postJson('/api/internal/marketing/ai/commit', [
                'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
                'conversation_id' => $this->conversation->id, 'decide_token' => $token,
                'proposal' => [
                    'strategy_goal' => 'resolve_objection', 'next_state' => 'GOAL_DISCOVERY', 'intent' => SalesIntents::PRICING_QUESTION,
                    'reply_draft' => $borrador, 'recommended_plan_id' => null, 'main_barrier' => null, 'next_best_action' => 'answer',
                    'confidence' => 0.9, 'tools_requested' => [],
                ],
                'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
            ], $this->headers())->assertStatus(422)->assertJsonPath('code', $codigo);
        }
    }

    /** El servicio no nombra nada que escriba, bloquee, salga a la red o despache (defensa estática, además de la de arriba). */
    public function test_el_servicio_no_nombra_nada_que_escriba(): void
    {
        $codigo = '';
        foreach (token_get_all((string) file_get_contents(base_path('app/Services/Marketing/Ultron/UltronDraftCheck.php'))) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codigo .= is_array($t) ? $t[1] : $t;
        }
        foreach (['Cache', 'lock(', 'Http', 'dispatch', 'Bus::', 'Queue::', 'DB::', '->save(', '->update(', '->create(', '->delete(', 'forceFill(', 'increment(',
            'insert(', 'upsert(', 'lockForUpdate', 'sharedLock', 'Dispatcher', 'MemoryService', 'UltronPublishedConfig', 'UltronCommitService', '->verify('] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $codigo, "UltronDraftCheck nombra {$prohibido}");
        }
        $this->assertStringContainsString('->peek(', $codigo, 'usa peek(), no verify()');
    }

    // ── Ola 4b · las reglas de política corregibles ─────────────────────────

    /** Las seis notas, LITERALES (copiadas a mano del encargo de la ola 4b). */
    private const POLITICA = [
        'policy_greeting_sells' => 'SALUDO QUE VENDE: la persona solo saludo. Saluda, da la bienvenida y pregunta en que le puede ayudar. No nombres planes, precios ni pagos y no preguntes todavia su objetivo.',
        'policy_active_goal_lost' => 'VISITA A MEDIAS: hay una visita de cortesia a medio concretar y la persona esta dando sus datos. Retoma la visita pidiendo solo el dato que falta (el dia o la hora). No nombres planes ni precios en este turno.',
        'policy_wrong_first_plan' => 'PRIMER PLAN EQUIVOCADO: el primer plan que nombres tiene que ser el de commercial_turn_policy.required_plan_id, y nombra solo planes de commercial_turn_policy.allowed_plan_ids. Los demas permitidos, solo despues y si aportan.',
        'policy_answer_without_fact' => 'FALTA EL DATO: la persona pidio informacion del gimnasio y el borrador no da ningun dato de knowledge_base. Responde primero con el dato pedido, copiado tal cual de knowledge_base (direccion, horario o servicios), y deja como mucho una pregunta al final.',
        'policy_general_info_only_discovery' => 'SOLO PREGUNTAS: la persona pidio informacion y el borrador solo pregunta. Responde primero con lo que pidio, con un dato concreto, y deja como mucho una pregunta al final.',
        'policy_memory_turn_sells' => 'PREGUNTA DE MEMORIA: la persona pregunto por algo ya acordado (su visita). Responde solo eso, con el dato del compromiso. No vendas ni preguntes su objetivo en este turno.',
    ];

    /** @return array<string, array{string, string, string, ?int, string, string, string}> entrante, intención, borrador, plan, memoria, código, issue */
    public static function reglasDeLaPolitica(): array
    {
        return [
            'saludo que vende' => ['Hola buenas tardes', SalesIntents::GREETING, 'Hola, buenas tardes. Bienvenido a Iron Body, qué gusto atenderle. ¿Ya ha entrenado antes?', null, '', 'policy_greeting_sells', 'customer_fit'],
            'visita a medias que vende' => ['en la mañana', SalesIntents::UNKNOWN, 'Perfecto. Mientras tanto, el Plan Mensual le sirve para empezar.', 4, 'visita', 'policy_active_goal_lost', 'phase_alignment'],
            'primer plan equivocado' => ['¿Cuánto cuesta el Plan Mensual?', SalesIntents::PRICING_QUESTION, 'El Plan Valera cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?', 3, '', 'policy_wrong_first_plan', 'phase_alignment'],
            'información sin el dato' => ['Quiero información del gimnasio', SalesIntents::GENERAL_INFO, 'Con gusto le cuento: somos un gimnasio para entrenar a su ritmo. ¿Qué le gustaría saber primero?', null, '', 'policy_answer_without_fact', 'relevance'],
            'solo preguntas' => [self::HORARIO, SalesIntents::SCHEDULE_QUESTION, '¿Para qué día de la semana le gustaría saber el horario?', null, '', 'policy_general_info_only_discovery', 'relevance'],
            'memoria que vende' => ['¿A qué hora era mi visita?', SalesIntents::UNKNOWN, 'No veo una visita registrada todavía. Si quiere, le cuento sobre los planes.', null, '', 'policy_memory_turn_sells', 'customer_fit'],
        ];
    }

    #[DataProvider('reglasDeLaPolitica')]
    public function test_cada_regla_de_la_politica_con_su_nota_literal(string $entrante, string $intent, string $borrador, ?int $plan, string $memoria, string $codigo, string $issue): void
    {
        $this->memoria($memoria);
        [$m, $token] = $this->turno($entrante);

        $r = $this->check($m, $token, $borrador, intent: $intent, plan: $plan)->assertOk();

        $this->assertSame([['code' => $codigo, 'detail' => null, 'issue' => $issue]], $r->json('defects'));
        $this->assertSame([$issue], $r->json('issues'));
        $this->assertSame(self::POLITICA[$codigo], $r->json('notes'));
        $this->assertSame([false, true, true], [$r->json('pass'), $r->json('may_rewrite'), $r->json('rewrite')]);
    }

    /** Primero los de §4.4 (lista, registro, longitud, beneficio) y después los de política; las notas, en ese orden. */
    public function test_primero_los_cuatro_y_despues_la_politica(): void
    {
        [$m, $token] = $this->turno('Hola buenas tardes');

        $r = $this->check($m, $token, 'Hola, buenas tardes. Bienvenido a Iron Body. ¿Ya has entrenado antes?', intent: SalesIntents::GREETING)->assertOk();

        $this->assertSame([
            ['code' => 'register_tuteo', 'detail' => null, 'issue' => 'naturalness'],
            ['code' => 'policy_greeting_sells', 'detail' => null, 'issue' => 'customer_fit'],
        ], $r->json('defects'));
        $this->assertSame(['naturalness', 'customer_fit'], $r->json('issues'));
        $this->assertSame(str_replace('(te)', '(has)', self::N_R_TE).' '.self::POLITICA['policy_greeting_sells'], $r->json('notes'));
        $this->assertSame(8, UltronDraftDefects::MAX_DEFECTS, 'cuatro de §4.4 y como mucho cuatro de política a la vez');
        $this->assertLessThanOrEqual(UltronDraftDefects::MAX_DEFECTS, count($r->json('defects')));
    }

    /** Sin versión del Studio, la política tampoco se mira: Legacy no cambia y no se lee nada. */
    public function test_sin_version_del_studio_la_politica_tampoco(): void
    {
        [$m, $token] = $this->turno('Hola buenas tardes', negocia: false);
        $sql = $this->escuchaLasConsultas();

        $r = $this->check($m, $token, 'Hola, buenas tardes. Bienvenido a Iron Body. ¿Ya has entrenado antes?', intent: SalesIntents::GREETING)->assertOk();

        $this->assertSame(['legacy', true, []], [$r->json('mode'), $r->json('pass'), $r->json('defects')]);
        $this->assertSame([], $sql(), 'ni la memoria ni nada');
    }

    /** Leer la política (la memoria, el resolutor, los hechos del gimnasio) es solo `select`: ni una fila, ni la memoria, ni un cerrojo. */
    public function test_la_politica_solo_lee(): void
    {
        $this->memoria('visita');
        $this->memoria('rechazado');
        [$m, $token] = $this->turno('Quiero información del gimnasio');
        $antes = $this->filasDeMarketing();
        $conversacion = $this->conversation->fresh()->toArray();

        Cache::partialMock()->shouldReceive('lock')->never();
        Queue::fake();
        Bus::fake();
        $sql = $this->escuchaLasConsultas();

        $r = $this->check($m, $token, '¿Qué le gustaría saber del Plan Valera?', intent: SalesIntents::GENERAL_INFO)->assertOk();

        $this->assertSame(['policy_answer_without_fact', 'policy_general_info_only_discovery'], array_slice(array_column($r->json('defects'), 'code'), -2), 'leyó la política (y los hechos del gimnasio)');
        $consultas = $sql();
        $this->assertNotSame([], $consultas);
        foreach ($consultas as $q) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $q);
        }
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        $this->assertSame($antes, $this->filasDeMarketing(), 'cero filas nuevas');
        $this->assertSame($conversacion, $this->conversation->fresh()->toArray(), 'ni la memoria ni nada de la conversación');
    }

    /**
     * El lector de la política sí nombra el servicio de la memoria (para LEERLA
     * igual que el commit), así que la guarda estática de la comprobación no
     * le alcanza: la suya, más estricta. Del servicio solo llama a `load()` y a
     * `sellablePlansForMemory()`, que no escriben.
     */
    public function test_el_lector_de_la_politica_no_nombra_nada_que_escriba(): void
    {
        $codigo = '';
        foreach (token_get_all((string) file_get_contents(base_path('app/Services/Marketing/Ultron/UltronTurnPolicyReader.php'))) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codigo .= is_array($t) ? $t[1] : $t;
        }
        foreach (['Cache', 'lock(', 'Http', 'dispatch', 'Bus::', 'Queue::', 'DB::', '->save(', '->update(', '->create(', '->delete(', 'forceFill(', 'increment(',
            'insert(', 'upsert(', 'lockForUpdate', 'sharedLock', 'Dispatcher', 'UltronPublishedConfig', 'UltronCommitService', '->verify(', 'record', 'forget', 'remember'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $codigo, "UltronTurnPolicyReader nombra {$prohibido}");
        }
        preg_match_all('/->memoryService->(\w+)\(/', $codigo, $m);
        $this->assertNotSame([], $m[1]);
        $this->assertSame([], array_values(array_diff($m[1], ['load', 'sellablePlansForMemory'])), 'del servicio de la memoria, solo lecturas');
        $this->assertStringContainsString('CommercialTurnPolicy::decide(', $codigo, 'la MISMA función que el commit');
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    /** Un turno con su `decide` negociado: el mensaje y el token. @return array{MarketingMessage,string} */
    public function turno(string $texto, bool $negocia = true): array
    {
        $m = $this->entrante($texto);

        return [$m, $this->decide($m, $negocia)['decide_token']];
    }

    private function cuerpo(MarketingMessage $m, string $token, string $borrador, int $attempt = 1, string $intent = SalesIntents::PRICING_QUESTION, ?int $plan = null, array $tools = []): array
    {
        return [
            'conversation_id' => $m->conversation_id,
            'message_id' => $m->id,
            'decide_token' => $token,
            'attempt' => $attempt,
            'draft' => [
                'reply_draft' => $borrador,
                'intent' => $intent,
                'recommended_plan_id' => $plan,
                'tools_requested' => $tools,
            ],
        ];
    }

    /** La memoria del turno, escrita ANTES de `decide` (es el andamio, no la puerta). */
    private function memoria(string $estado): void
    {
        $servicio = app(ConversationMemoryService::class);
        match ($estado) {
            '' => null,
            'visita' => $servicio->recordCourtesyCollecting($this->conversation->fresh(), ['date' => '2026-11-21']),
            'rechazado' => $servicio->recordPlanRejected($this->conversation->fresh(), 3),
        };
    }

    private function check(MarketingMessage $m, string $token, string $borrador, int $attempt = 1, string $intent = SalesIntents::PRICING_QUESTION, ?int $plan = null, array $tools = []): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/check', $this->cuerpo($m, $token, $borrador, $attempt, $intent, $plan, $tools), $this->headers());
    }

    /** @return \Closure(): list<string> las sentencias desde ahora */
    private function escuchaLasConsultas(): \Closure
    {
        $sql = [];
        $escuchando = true;
        DB::listen(function (QueryExecuted $q) use (&$sql, &$escuchando): void {
            if ($escuchando) {
                $sql[] = $q->sql;
            }
        });

        return function () use (&$sql, &$escuchando): array {
            $escuchando = false;

            return $sql;
        };
    }

    /** @return array<string,int> cada tabla de Marketing con sus filas */
    private function filasDeMarketing(): array
    {
        $filas = [];
        foreach (Schema::getTables() as $tabla) {
            if (str_starts_with($tabla['name'], 'marketing_') || in_array($tabla['name'], ['plans', 'payment_transactions', 'incidents'], true)) {
                $filas[$tabla['name']] = DB::table($tabla['name'])->count();
            }
        }
        ksort($filas);

        return $filas;
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

    private function beneficiosDistintos(): void
    {
        Plan::query()->whereKey(3)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Asesoría semi personalizada'])]);
        Plan::query()->whereKey(4)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Clases grupales ilimitadas'])]);
        Plan::query()->whereKey(8)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Entrenador personal dedicado'])]);
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

    public function nuevaConversacion(): void
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

    public function publica(): void
    {
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
    }

    public function entrante(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.chk.'.$this->mundo.'.'.uniqid(), 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m, bool $negocia): array
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ] + ($negocia ? ['prompt_contract' => 'ultron-prompts/1'] : []), $this->headers())->assertOk()->json();
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }
}
