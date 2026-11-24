<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MarketingKnowledgeBaseService;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use App\Services\Marketing\Ultron\ConversationMemoryService;
use App\Services\Marketing\Ultron\UltronCommitService;
use App\Services\Marketing\Ultron\UltronRegenerationNotes;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use App\Services\Marketing\Ultron\UltronTurnPolicyReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * A4 · «REGENERAR ANTES DE SUSTITUIR», OLA 4: LA COMPROBACIÓN Y EL COMMIT
 * DICEN LO MISMO (plan `plan-arquitecto-a4-regenerar.md` §3 P-1, cambio 3; §6
 * «Ola 4»; riesgo R3).
 *
 * La comprobación (`ai/check`) es un AVISO y el commit no lee su resultado:
 * vuelve a comprobarlo todo sobre su texto final. Para que el aviso sirva, sobre
 * el MISMO turno (la misma conversación, el mismo token) y una matriz de
 * borradores, la comprobación marca `register_tuteo`, `length_over_cap`,
 * `plans_overview` o `benefit_misattributed` SI Y SOLO SI el commit descarta,
 * recorta o viola por esa causa:
 *
 *  - `register_tuteo` ⇔ `metadata.register.action` ≠ `ok` (recorta o descarta);
 *  - `length_over_cap` ⇔ `metadata.length.from` > `metadata.length.cap`: el
 *    texto que midió el commit no cabía, así que lo recortó o lo descartó (la
 *    acción pasa a `replaced` si la política lo sustituye por otra causa, pero
 *    `from` sigue siendo la medida del borrador);
 *  - `plans_overview` ⇔ la violación `policy_plans_overview`, o el 422 de un
 *    `PLAN_k` desconocido, o el 422 de una cifra escrita con la lista;
 *  - `benefit_misattributed` ⇔ la violación `policy_benefit_misattributed`.
 *
 * LAS EXCEPCIONES, UNA A UNA (hecho H7 del plan: el commit pega o retira texto
 * antes de medir, y decide sobre el mundo de ese instante). SON ONCE, E1 a E11
 * (m-7 del revisor: este docblock decía siete y eran nueve; E10 y E11 se
 * añadieron en las correcciones tras las revisiones). E1 a E3 se ejercitan
 * abajo, cada una con su prueba que fija la divergencia exacta; E10 la fija la
 * suya en `UltronA4ListaDelModeloTest`; las demás quedan declaradas aquí con su
 * porqué, sin prueba propia. En ninguna sale un texto peor que sin la
 * comprobación: lo peor es una reescritura perdida (E1, E4, E6, E9, E10, E11) o
 * una llamada de más (E2, E3, E5 y la otra dirección de E6):
 *
 *  E1. Un plan de la lista deja de venderse entre la comprobación y el commit
 *      (H14): la comprobación no lo ve (no lo arregla una reescritura) y el
 *      commit da la lista fija (`not_sellable`). Prueba propia.
 *  E2. El commit RETIRA frases antes de medir (`sinServiciosInventados()`: una
 *      clase que no existe): un borrador que solo se pasa por esa frase es
 *      `length_over_cap` en la comprobación y cabe en el commit. Prueba propia.
 *      De la misma familia, sin prueba propia: `sinConfirmarUnaCortesia…()`,
 *      `sinCambioDeVisitaQueNoSeHizo()`, `sinConstanciaDeUnaCortesia…()`,
 *      `sinNegarUnCobroDisponible()` y `sinExcusasDePrecio()`.
 *  E3. El commit PEGA los enlaces de la app solo si no salieron hace menos de
 *      60 minutos (eso está en la memoria, que la comprobación no lee): con los
 *      enlaces ya enviados, la comprobación los cuenta y el commit no.
 *      Prueba propia. En modo memoria el commit además los quita.
 *  E4. El commit PEGA la línea del cobro con el enlace de pago (`conCheckout()`)
 *      antes de medir; la comprobación no (el plan, §4.1 paso 3, solo pega los
 *      enlaces de la app). En un turno de cobro con el enlace dentro, el commit
 *      puede recortar un borrador que la comprobación dio por bueno. Sin prueba
 *      propia: aquí la bandera del cobro está apagada (es el estado de
 *      producción) y el enlace no se acuña.
 *  E5. El commit REESCRIBE la frase del precio cuando el texto nombra varios
 *      planes y cotiza con `{{PLAN_PRICE}}` sin dueño claro (realineación,
 *      `price_sentence`) o cuando la política impone otro plan: cambia el texto
 *      que se mide. La comprobación mide la expansión con el plan propuesto.
 *  E6. El commit decide el panorama con la intención SANEADA (el validador
 *      puede bajarla a `unknown` o el saludo puro la vuelve `greeting`), con su
 *      política (la memoria pudo cambiar) y sin herramientas ejecutadas ni
 *      relevo autorizado en el turno; la comprobación mira la intención del
 *      borrador y si `decide` ofreció la lista (`po`). Va en las dos
 *      direcciones: casi siempre es más laxa, pero cuando la intención del
 *      estratega no es de las del panorama, la comprobación pide la lista
 *      porque la persona preguntó el precio (`preguntaPorPrecio`) y el commit
 *      no la habría exigido: una llamada de más, hacia lo que pidió el
 *      propietario (H4-10).
 *  E7. Un borrador que el commit tumba ANTES (URL, datos de tarjeta, pago o
 *      membresía inventados, guard de contenido, presión, transición ilegal,
 *      efecto sin autoridad) no llega a medirse: el 422 no es por estas
 *      causas. Lo fija `UltronCheckEndpointTest::test_no_es_un_oraculo_de_las_guardas`.
 *      Igual con la política (ola 4b): un `PLAN_k` desconocido o una cifra son
 *      un 422 antes de `violations()`; la comprobación puede decir además una
 *      regla de política (pide la reescritura de todos modos). Y la respuesta
 *      repetida o la reventa sustituyen el texto antes de mirar la política.
 *
 * OLA 4b · LAS REGLAS DE POLÍTICA CORREGIBLES (decisión L-3 del lead). La
 * comprobación calcula la política del turno IGUAL que el commit
 * (`UltronTurnPolicyReader`: la intención del borrador con el saludo puro como
 * saludo, la memoria LEÍDA, el resolutor, el catálogo y el entrante) y marca
 * cada una de las seis (`policy_greeting_sells`, `policy_active_goal_lost`,
 * `policy_wrong_first_plan`, `policy_answer_without_fact`,
 * `policy_general_info_only_discovery`, `policy_memory_turn_sells`) SI Y SOLO
 * SI el commit la pone en `policy_violations`. Para que sea el mismo texto, la
 * comprobación hace lo que hace el commit ANTES de `violations()`: el plan que
 * impone la política pone `{{PLAN_NAME}}`/`{{PLAN_PRICE}}` (antes, E5), el modo
 * memoria quita los enlaces de la app, el tope quita la pregunta final (F1) y
 * el registro la quita con el bloque de Laravel, y el saludo repetido se
 * recorta. Además, en cada fila que la política sustituyó, la política que leyó
 * la comprobación es LA MISMA que guardó el commit (`metadata.policy`). Quedan:
 *  E5'. La realineación del precio (`price_sentence`) sigue siendo E5.
 *  E8.  Los textos que el commit reescribe tras ejecutar herramientas (las
 *       actas de la cortesía, E2): pueden quitar una frase que nombraba un
 *       plan. Sin prueba propia; no hay herramientas en estos turnos.
 *  E9.  La memoria se lee unos segundos antes que en el commit: un objetivo
 *       que caduca justo entre los dos (horas de antigüedad) lo verían
 *       distinto. Sin prueba propia.
 *
 * CORRECCIONES TRAS LAS REVISIONES (encargo `encargo-be-r6.md`):
 *  E10. Un marcador MAL ESCRITO (`PLAN_1` sin llaves, `{PLAN_1}`) en un turno
 *       con herramientas (I-1 del revisor): ni la comprobación ni el commit
 *       miran la lista con herramientas (G1.2), así que la comprobación dice
 *       `[]`; el commit lo para en el punto de control (C1: la red de los
 *       marcadores) y manda el último recurso, con `metadata.server_replaced`.
 *       Una reescritura perdida, no un texto peor: antes salía literal. Lo fija
 *       `UltronA4ListaDelModeloTest::test_un_marcador_mal_escrito_no_sale_con_herramientas`.
 *  E11. «Profesional» y los enlaces de la app ya enviados hace menos de una
 *       hora (H4-12 b, anterior a este cambio): la comprobación cuenta los
 *       enlaces (E3), y el commit pega `LINKS_ALREADY_SENT`, que tutea, y lo
 *       descarta por el registro. Una reescritura no lo arregla. Sin prueba
 *       propia.
 */
class UltronCheckCommitParityTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000e4';

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

    private const PANORAMA = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private const HORARIO = '¿Cuál es el horario de atención entre semana?';

    private const MEMBRESIAS = '¿Qué membresías tienen?';

    private const TRES = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?';

    private const LARGO = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes y con acceso total al gimnasio. Le explico con calma lo que incluye cada uno para que elija con tranquilidad. ¿Cuál le interesa más?';

    /** PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): con la lista el tope aplicable es 320; esta, sin expandir, cabe (298) y expandida, no (339). */
    private const LARGO_320 = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes y con acceso total al gimnasio. Le explico con calma lo que incluye cada uno para que elija con tranquilidad, sin prisa y con toda la información que necesite antes de decidir, para que empiece con buen pie. ¿Cuál le interesa más?';

    private const HORARIO_LARGO = 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m., y los sábados, domingos y festivos de 8:00 a. m. a 2:00 p. m. Es el horario de apertura del gimnasio, no el de las clases, así que puede entrenar cuando más le convenga. ¿Le gustaría venir esta semana?';

    /** Las causas en un orden fijo, el de los códigos (con las seis de la política detrás, ola 4b). */
    private const ORDEN = UltronRegenerationNotes::CODES;

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

        foreach (self::CATALOGO as $i => [$id, $nombre, $precio, $dias]) {
            (new Plan)->forceFill([
                'id' => $id, 'name' => $nombre, 'price' => $precio, 'duration_days' => $dias,
                'active' => true, 'sellable' => true, 'sort_order' => $i + 1, 'is_recommended' => false,
                'benefits' => json_encode(['Acceso total al gimnasio']),
            ])->save();
        }
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
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── La matriz ───────────────────────────────────────────────────────────

    /**
     * entrante, intención, borrador, plan propuesto, herramientas, beneficios distintos, causas esperadas.
     *
     * @return array<string, array{string, string, string, ?int, list<string>, bool, list<string>}>
     */
    public static function matriz(): array
    {
        $precio = SalesIntents::PRICING_QUESTION;
        $horario = SalesIntents::SCHEDULE_QUESTION;

        return [
            // El panorama con precio, con la lista firmada (Valera, Mensual, Élite).
            'lista de usted' => [self::PANORAMA, $precio, self::TRES, null, [], false, []],
            'lista que tutea' => [self::PANORAMA, $precio, str_replace('le interesa', 'te interesa', self::TRES), null, [], false, ['register_tuteo']],
            // PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): estas dos usaban la lista de 228, que ya cabe en el tope aplicable (320); ahora usan una de 339 y conservan sus causas.
            'lista que solo se pasa expandida' => [self::PANORAMA, $precio, self::LARGO_320, null, [], false, ['length_over_cap']],
            'lista que tutea y se pasa' => [self::PANORAMA, $precio, str_replace(['Le explico', 'le interesa'], ['Te explico', 'te interesa'], self::LARGO_320), null, [], false, ['register_tuteo', 'length_over_cap']],
            // Y la de 228 (más de 200, dentro de 320), con lo que da ahora la regla: ni la comprobación ni el commit la marcan por la longitud.
            'lista que pasa de 200 y cabe en el tope de lista' => [self::PANORAMA, $precio, self::LARGO, null, [], false, []],
            'lista que tutea y cabe en el tope de lista' => [self::PANORAMA, $precio, str_replace(['Le explico', 'le interesa'], ['Te explico', 'te interesa'], self::LARGO), null, [], false, ['register_tuteo']],
            'falta la lista' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}}, de un mes. ¿Le interesa?', null, [], false, ['plans_overview']],
            'mezcla con el precio' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}} desde {{PLAN_PRICE}}. ¿Cuál le interesa?', 3, [], false, ['plans_overview']],
            'marcador repetido' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}} y otra vez {{PLAN_1}}. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'nombre a mano' => [self::PANORAMA, $precio, 'Claro. Tenemos el Plan Valera {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'marcador inexistente' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'cifra escrita' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}} desde $50.000. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            // PT-17, puerta 2, L1: con k ≥ 8 la lista de dos de tres no vale (forma A estricta): los del beneficio llevan la lista entera, en orden, para aislar su causa.
            'beneficio de otro plan' => [self::PANORAMA, $precio, 'Claro. {{PLAN_1}} incluye entrenador personal dedicado. También tenemos {{PLAN_2}} y {{PLAN_3}}. ¿Cuál le interesa?', null, [], true, ['benefit_misattributed']],
            'beneficio en su plan' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. {{PLAN_3}} incluye entrenador personal dedicado. ¿Cuál le interesa?', null, [], true, []],
            'beneficio de otro plan y tuteo' => [self::PANORAMA, $precio, 'Claro. {{PLAN_1}} incluye entrenador personal dedicado. También tenemos {{PLAN_2}} y {{PLAN_3}}. ¿Cuál te interesa?', null, [], true, ['register_tuteo', 'benefit_misattributed']],
            // PT-17, puerta 2, L1 (la lista la compone Laravel, k ≥ 8): la forma B, la A estricta y la ranura fuera de su turno.
            'forma B con la ranura' => [self::PANORAMA, $precio, 'Claro, con gusto. Le comparto nuestros planes: {{LISTA_DE_PLANES}}. ¿Cuál le interesa más?', null, [], false, []],
            'forma B sin ranura' => [self::PANORAMA, $precio, 'Claro, con gusto. Todos incluyen acceso total al gimnasio. ¿Cuál le interesa más?', null, [], false, []],
            'forma B que tutea' => [self::PANORAMA, $precio, 'Claro, con gusto. Te comparto nuestros planes: {{LISTA_DE_PLANES}}. ¿Cuál te interesa más?', null, [], false, ['register_tuteo']],
            'forma B que se pasa con la lista dentro' => [self::PANORAMA, $precio, 'Claro, con gusto le cuento. Estos son nuestros planes: {{LISTA_DE_PLANES}}. Todos incluyen acceso a la zona de pesas, a la de cardio y a los vestidores, y puede venir en el horario que mejor le quede durante toda la semana, con el acompañamiento del equipo desde el primer día. ¿Cuál le interesa más?', null, [], false, ['length_over_cap']],
            'forma B con un nombre a mano' => [self::PANORAMA, $precio, 'Claro. El Plan Valera es el más pedido. Estos son: {{LISTA_DE_PLANES}}. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'forma A con dos de tres' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}, los dos de un mes. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'forma A fuera de orden' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_2}}, {{PLAN_1}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'A y B a la vez' => [self::PANORAMA, $precio, 'Claro. Le comparto {{LISTA_DE_PLANES}}; el más pedido es {{PLAN_1}}. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'la ranura en un turno de cobro' => [self::PANORAMA, SalesIntents::PAYMENT_LINK_REQUEST, 'Claro. Le comparto {{LISTA_DE_PLANES}}. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'la ranura sin lista' => [self::HORARIO, $horario, 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. Y estos son los planes: {{LISTA_DE_PLANES}}. ¿Le sirve?', null, [], false, ['plans_overview']],
            // Decisión del lead: un marcador que no es de la lista, siempre (con herramientas; sin pedir el precio).
            'marcador inexistente con herramientas' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. Le dejo también los enlaces de la app.', null, ['app_links_send'], false, ['plans_overview']],
            'marcador inexistente sin pedir el precio' => [self::MEMBRESIAS, SalesIntents::GENERAL_INFO, 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            // Turno de cobro con la lista ofrecida: la lista no vale.
            'la lista en un turno de cobro' => [self::PANORAMA, SalesIntents::PAYMENT_LINK_REQUEST, self::TRES, null, [], false, ['plans_overview']],
            // Fuera del panorama: el horario, sin lista.
            'horario de usted' => [self::HORARIO, $horario, 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Le gustaría venir a conocernos?', null, [], false, []],
            'horario que tutea en la pregunta' => [self::HORARIO, $horario, 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Quieres que te cuente también sobre las clases?', null, [], false, ['register_tuteo']],
            'horario largo' => [self::HORARIO, $horario, self::HORARIO_LARGO, null, [], false, ['length_over_cap']],
            'marcador de la lista sin lista' => [self::HORARIO, $horario, 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. Tenemos {{PLAN_1}}. ¿Le cuento más?', null, [], false, ['plans_overview']],
            'beneficio de otro plan sin lista' => [self::HORARIO, $horario, 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. El Plan Valera incluye entrenador personal dedicado. ¿Le cuento cómo empezar?', null, [], true, ['benefit_misattributed']],
            // Con los enlaces de la app pegados (los dos los cuentan): cabe sin ellos, no con ellos.
            'enlaces de la app que no caben' => [self::HORARIO, $horario, 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m., y los sábados, domingos y festivos de 8:00 a. m. a 2:00 p. m. Le dejo aquí los enlaces para descargar la app.', null, ['app_links_send'], false, ['length_over_cap']],
            'enlaces de la app que caben' => [self::HORARIO, $horario, 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. Le dejo los enlaces de la app.', null, ['app_links_send'], false, []],
        ];
    }

    /**
     * @param  list<string>  $herramientas
     * @param  list<string>  $esperadas
     */
    #[DataProvider('matriz')]
    public function test_la_comprobacion_marca_si_y_solo_si_el_commit_descarta_recorta_o_viola_por_esa_causa(
        string $entrante, string $intent, string $borrador, ?int $plan, array $herramientas, bool $beneficios, array $esperadas,
    ): void {
        if ($beneficios) {
            $this->beneficiosDistintos();
        }
        [$m, $token, $conLista] = $this->turno($entrante);

        $marca = $this->loQueMarcaLaComprobacion($m, $token, $borrador, $intent, $plan, $herramientas);
        $commit = $this->commit($m, $token, $borrador, $intent, $plan, $herramientas);
        $causas = $this->causasDelCommit($commit, $conLista && ! in_array($intent, [SalesIntents::PAYMENT_LINK_REQUEST, SalesIntents::HIGH_INTENT_CLOSE], true));

        $this->assertSame($esperadas, $marca, 'la comprobación');
        $this->assertSame($marca, $causas, 'el commit, por la misma causa');
    }

    // ── Ola 4b · las seis reglas de política corregibles ────────────────────

    private const SALUDO_LARGO = 'Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderle por aquí. Estamos para resolver cualquier duda que tenga sobre el gimnasio y para acompañarle desde el primer día, con calma. ¿Cuál es su objetivo?';

    /**
     * entrante, intención, borrador, plan propuesto, memoria, causas esperadas.
     *
     * @return array<string, array{string, string, string, ?int, string, list<string>}>
     */
    public static function matrizDeLaPolitica(): array
    {
        $saludo = SalesIntents::GREETING;
        $precio = SalesIntents::PRICING_QUESTION;
        $info = SalesIntents::GENERAL_INFO;
        $horario = SalesIntents::SCHEDULE_QUESTION;
        $otra = SalesIntents::UNKNOWN;
        $mensual = '¿Cuánto cuesta el Plan Mensual?';
        $infoGym = 'Quiero información del gimnasio';
        $visita = '¿A qué hora era mi visita?';

        return [
            // 1. El saludo que vende (modo recepción).
            'saludo que pregunta si ha entrenado' => ['Hola buenas tardes', $saludo, 'Hola, buenas tardes. Bienvenido a Iron Body, qué gusto atenderle. ¿Ya ha entrenado antes?', null, '', ['policy_greeting_sells']],
            'saludo puro con la intención de precio (el commit lo vuelve saludo)' => ['Hola', $precio, 'Hola, bienvenido a Iron Body. El Plan Mensual es el más pedido. ¿En qué le puedo ayudar?', null, '', ['policy_greeting_sells']],
            'saludo que recibe' => ['Hola buenas tardes', $saludo, 'Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderle. Cuénteme, ¿en qué le podemos ayudar hoy?', null, '', []],
            'saludo largo con el objetivo al final (el tope quita la pregunta)' => ['Hola', $saludo, self::SALUDO_LARGO, null, '', ['length_over_cap']],
            // 2. La visita a medias.
            'visita a medias que vende' => ['en la mañana', $otra, 'Perfecto. Mientras tanto, el Plan Mensual le sirve para empezar.', 4, 'visita', ['policy_active_goal_lost']],
            'visita a medias que la retoma' => ['en la mañana', $otra, 'Perfecto, en la mañana. ¿A qué hora le queda mejor venir?', null, 'visita', []],
            // 3. El primer plan equivocado (el que nombró la persona, o uno que ya rechazó).
            'el plan que nombró la persona no va primero' => [$mensual, $precio, 'El Plan Valera cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?', 3, '', ['policy_wrong_first_plan']],
            'el marcador del nombre con el plan que impone la política' => [$mensual, $precio, 'El {{PLAN_NAME}} cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?', 3, '', []],
            'el que nombró va primero' => [$mensual, $precio, 'El Plan Mensual cuesta {{PLAN_PRICE}} al mes. ¿Le cuento qué incluye?', 4, '', []],
            'nombra un plan que rechazó' => [self::HORARIO, $horario, 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. Con el Plan Valera puede venir cuando quiera. ¿Le sirve?', null, 'rechazado', ['policy_wrong_first_plan']],
            'nombra otro plan' => [self::HORARIO, $horario, 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. Con el Plan Mensual puede venir cuando quiera. ¿Le sirve?', null, 'rechazado', []],
            // 4 y 5. La información: sin el dato, y solo preguntas.
            'información sin el dato' => [$infoGym, $info, 'Con gusto le cuento: somos un gimnasio para entrenar a su ritmo. ¿Qué le gustaría saber primero?', null, '', ['policy_answer_without_fact']],
            'información con el dato' => [$infoGym, $info, 'Con gusto. Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. y los sábados de 8:00 a. m. a 2:00 p. m. ¿Qué le gustaría saber?', null, '', []],
            'información que solo pregunta' => [$infoGym, $info, '¿Qué le gustaría saber del gimnasio?', null, '', ['policy_answer_without_fact', 'policy_general_info_only_discovery']],
            'el horario que solo pregunta' => [self::HORARIO, $horario, '¿Para qué día de la semana le gustaría saber el horario?', null, '', ['policy_general_info_only_discovery']],
            // 6. La pregunta de memoria.
            'memoria que vende' => [$visita, $otra, 'No veo una visita registrada todavía. Si quiere, le cuento sobre los planes.', null, '', ['policy_memory_turn_sells']],
            'memoria que contesta' => [$visita, $otra, 'No veo una visita registrada todavía. ¿Qué día le gustaría venir?', null, '', []],
        ];
    }

    /** @param list<string> $esperadas */
    #[DataProvider('matrizDeLaPolitica')]
    public function test_la_politica_la_marca_la_comprobacion_si_y_solo_si_el_commit_la_pone_en_policy_violations(
        string $entrante, string $intent, string $borrador, ?int $plan, string $memoria, array $esperadas,
    ): void {
        [$m, $token] = $this->turno($entrante, $memoria);
        $leida = app(UltronTurnPolicyReader::class)->read($this->conversation->fresh(), $entrante, $intent, app(MarketingKnowledgeBaseService::class)->activePlans())['policy'];

        $marca = $this->loQueMarcaLaComprobacion($m, $token, $borrador, $intent, $plan, []);
        $commit = $this->commit($m, $token, $borrador, $intent, $plan, []);
        $causas = $this->causasDelCommit($commit, false);

        $this->assertSame($esperadas, $marca, 'la comprobación');
        $this->assertSame($marca, $causas, 'el commit, por la misma causa');

        $fila = $this->ultimaAccion()->metadata;
        if (array_intersect(UltronRegenerationNotes::POLICY_CODES, $esperadas) !== []) {
            $this->assertArrayHasKey('policy', $fila, 'la política sustituyó el borrador');
            $this->assertSame(self::ordenada($fila['policy']), self::ordenada($leida), 'la misma política que el commit');
        }
    }

    /** Lo mismo, con las claves de los objetos ordenadas (`jsonb` las reordena en PostgreSQL); las listas, en su orden. */
    private static function ordenada(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }
        $valor = array_map(self::ordenada(...), $valor);
        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }

    /**
     * En modo memoria el commit retira los enlaces de la app (la herramienta y
     * el marcador) antes de medir: la comprobación tampoco los cuenta. Con
     * ellos (unos 30 caracteres de «Android: · iPhone: · Web:»), este borrador
     * de 196 pasaría de 200 y pediría una reescritura que el commit no pide.
     */
    public function test_en_modo_memoria_los_enlaces_de_la_app_no_cuentan(): void
    {
        $borrador = 'No veo una visita registrada todavía a su nombre. El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m., y los sábados de 8:00 a. m. a 2:00 p. m. ¿Qué día le gustaría venir a conocernos?';
        $this->assertSame(196, UltronReplyFitter::measure($borrador));

        foreach ([[['app_links_send'], $borrador], [[], $borrador.' {{APP_LINKS}}']] as [$herramientas, $texto]) {
            [$m, $token] = $this->turno('¿A qué hora era mi visita?');
            $this->assertTrue(app(UltronTurnPolicyReader::class)->read($this->conversation->fresh(), '¿A qué hora era mi visita?', SalesIntents::UNKNOWN, app(MarketingKnowledgeBaseService::class)->activePlans())['policy']['memory_mode'], 'es un turno de memoria');

            $marca = $this->loQueMarcaLaComprobacion($m, $token, $texto, SalesIntents::UNKNOWN, null, $herramientas);
            $commit = $this->commit($m, $token, $texto, SalesIntents::UNKNOWN, null, $herramientas);

            $this->assertSame([], $marca, 'la comprobación');
            $this->assertSame($marca, $this->causasDelCommit($commit, false), 'el commit');
            $this->assertLessThanOrEqual(200, $this->ultimaAccion()->metadata['length']['from'], 'el commit midió sin los enlaces');
        }
    }

    /** La política se LEE: ni la comprobación ni el lector tocan la memoria (lo demás de solo lectura, en `UltronCheckEndpointTest`). */
    public function test_leer_la_politica_no_cambia_la_memoria(): void
    {
        [$m, $token] = $this->turno('en la mañana', 'visita');
        $antes = $this->conversation->fresh()->memory;

        $this->assertSame(['policy_active_goal_lost'], $this->loQueMarcaLaComprobacion($m, $token, 'Perfecto. Mientras tanto, el Plan Mensual le sirve para empezar.', SalesIntents::UNKNOWN, 4, []));
        $this->assertSame($antes, $this->conversation->fresh()->memory);
        $this->assertSame(0, MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->count());
    }

    // ── Las excepciones que se ejercitan (E1 a E3) ──────────────────────────

    /** E1. El plan deja de venderse entre los dos: la comprobación no lo ve; el commit da la lista fija. */
    public function test_e1_un_plan_que_deja_de_venderse_entre_la_comprobacion_y_el_commit(): void
    {
        [$m, $token, $conLista] = $this->turno(self::PANORAMA);
        $this->assertTrue($conLista);

        $this->assertSame([], $this->loQueMarcaLaComprobacion($m, $token, self::TRES, SalesIntents::PRICING_QUESTION, null, []));
        Plan::query()->whereKey(4)->update(['sellable' => false]);
        $commit = $this->commit($m, $token, self::TRES, SalesIntents::PRICING_QUESTION, null, []);

        $this->assertSame(['plans_overview'], $this->causasDelCommit($commit, true), 'la divergencia declarada');
        $this->assertSame('not_sellable', $this->ultimaAccion()->metadata['plans_overview']['detail']);
        // Si se volviera a comprobar ahora, la lista tampoco: no lo arregla una reescritura (no es un código corregible).
        // Ola 4b: ahora la política se lee de la memoria que este commit ACABA de escribir (saludó y ofreció la lista
        // fija, cuya PROMO X4 el resolutor da por referida en «la mensualidad»): el turno de después ya obliga ese plan,
        // y el borrador, que nombra antes el Valera, incumple ESA política. En la vida real la comprobación va antes.
        $despues = app(UltronTurnPolicyReader::class)->read($this->conversation->fresh(), self::PANORAMA, SalesIntents::PRICING_QUESTION, app(MarketingKnowledgeBaseService::class)->activePlans())['policy'];
        $this->assertSame([14, 'resolved_reference'], [$despues['required_plan_id'], $despues['required_plan_source']]);
        $this->assertSame(['policy_wrong_first_plan'], $this->loQueMarcaLaComprobacion($m, $token, self::TRES, SalesIntents::PRICING_QUESTION, null, []));
    }

    /** E2. El commit retira la frase de una clase que no existe antes de medir: con ella, se pasaba; sin ella, cabe. */
    public function test_e2_el_commit_retira_una_frase_antes_de_medir(): void
    {
        [$m, $token] = $this->turno(self::HORARIO);
        $borrador = 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m. También tenemos clases de pilates y yoga todas las mañanas para quienes prefieren empezar el día entrenando con calma. ¿Le gustaría venir esta semana?';
        $this->assertGreaterThan(200, UltronReplyFitter::measure($borrador));

        $this->assertSame(['length_over_cap'], $this->loQueMarcaLaComprobacion($m, $token, $borrador, SalesIntents::SCHEDULE_QUESTION, null, []));
        $commit = $this->commit($m, $token, $borrador, SalesIntents::SCHEDULE_QUESTION, null, []);

        $this->assertSame([], $this->causasDelCommit($commit, false), 'la divergencia declarada');
        $fila = $this->ultimaAccion()->metadata;
        $this->assertNotEmpty($fila['invented_service_dropped'] ?? [], 'retiró la frase');
        $this->assertSame('fit', $fila['length']['action']);
        $this->assertLessThanOrEqual(200, $fila['length']['from']);
    }

    /** E3. Los enlaces de la app ya salieron hace diez minutos: el commit no los vuelve a pegar; la comprobación (que no lee la memoria) sí los cuenta. */
    public function test_e3_los_enlaces_que_ya_salieron_no_se_pegan(): void
    {
        $this->nuevaConversacion();
        $this->conversation->forceFill(['memory' => ['app_context' => ['links_sent_at' => now()->subMinutes(10)->toIso8601String()]]])->save();
        $m = $this->entrante(self::HORARIO);
        $token = $this->decide($m)['decide_token'];
        $borrador = 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m., y los sábados, domingos y festivos de 8:00 a. m. a 2:00 p. m. Le dejo aquí los enlaces para descargar la app.';

        $this->assertSame(['length_over_cap'], $this->loQueMarcaLaComprobacion($m, $token, $borrador, SalesIntents::SCHEDULE_QUESTION, null, ['app_links_send']));
        $commit = $this->commit($m, $token, $borrador, SalesIntents::SCHEDULE_QUESTION, null, ['app_links_send']);

        $this->assertSame([], $this->causasDelCommit($commit, false), 'la divergencia declarada');
        $this->assertSame('fit', $this->ultimaAccion()->metadata['length']['action']);
    }

    // ── Andamio ──────────────────────────────────────────────────────────────

    /** @return list<string> los códigos de la comprobación, en el orden de los códigos */
    private function loQueMarcaLaComprobacion(MarketingMessage $m, string $token, string $borrador, string $intent, ?int $plan, array $herramientas): array
    {
        $r = $this->postJson('/api/internal/marketing/ai/check', [
            'conversation_id' => $m->conversation_id,
            'message_id' => $m->id,
            'decide_token' => $token,
            'attempt' => 1,
            'draft' => ['reply_draft' => $borrador, 'intent' => $intent, 'recommended_plan_id' => $plan, 'tools_requested' => $herramientas],
        ], $this->headers())->assertOk();
        $this->assertSame('studio', $r->json('mode'));

        return self::enOrden(array_column((array) $r->json('defects'), 'code'));
    }

    /**
     * Por qué causa de las cuatro descarta, recorta o viola el commit.
     *
     * @return list<string>
     */
    private function causasDelCommit(TestResponse $r, bool $conLista): array
    {
        $causas = [];
        if ($r->status() === 422) {
            $codigo = (string) $r->json('code');
            // PT-17, puerta 2, L1: la ranura de la lista fuera de su turno (o con otra caja) también es de la lista.
            if ($codigo === 'unknown_placeholder' && preg_grep('/^(PLAN_|LISTA_DE_PLANES$)/i', (array) $r->json('detail.unknown')) !== []) {
                $causas[] = UltronRegenerationNotes::PLANS_OVERVIEW;
            }
            // Una cifra escrita: el guard de contenido o el validador la tumban siempre; con la lista, es del panorama (`price_written`).
            if ($conLista && in_array($codigo, ['draft_rejected', OutboundContentGuard::CODE_INVENTED_PRICE], true)) {
                $causas[] = UltronRegenerationNotes::PLANS_OVERVIEW;
            }

            return self::enOrden($causas);
        }
        $r->assertOk();
        $fila = $this->ultimaAccion()->metadata;
        $violaciones = (array) ($fila['policy_violations'] ?? []);
        if (($fila['register']['action'] ?? 'ok') !== 'ok') {
            $causas[] = UltronRegenerationNotes::REGISTER_TUTEO;
        }
        // PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): comparaba con `cap` (el publicado); ahora con el tope aplicado (`list_cap` si la regla se aplicó; si no, `cap`, como antes). Con la línea base, `cap` va null y `list_cap` no (B1).
        $topeAplicado = $fila['length']['list_cap'] ?? $fila['length']['cap'] ?? null;
        if (isset($fila['length']['from']) && $topeAplicado !== null && $fila['length']['from'] > $topeAplicado) {
            $causas[] = UltronRegenerationNotes::LENGTH_OVER_CAP;
        }
        if (in_array(CommercialTurnPolicy::VIOLACION_PANORAMA, $violaciones, true)) {
            $causas[] = UltronRegenerationNotes::PLANS_OVERVIEW;
        }
        if (in_array(UltronCommitService::VIOLACION_BENEFICIO, $violaciones, true)) {
            $causas[] = UltronRegenerationNotes::BENEFIT_MISATTRIBUTED;
        }
        // Ola 4b: las seis de la política, con su mismo nombre.
        foreach (UltronRegenerationNotes::POLICY_CODES as $codigo) {
            if (in_array($codigo, $violaciones, true)) {
                $causas[] = $codigo;
            }
        }

        return self::enOrden($causas);
    }

    /** @param list<string> $codigos */
    private static function enOrden(array $codigos): array
    {
        return array_values(array_filter(self::ORDEN, fn (string $c) => in_array($c, $codigos, true)));
    }

    /** @return array{MarketingMessage,string,bool} el mensaje, el token y si `decide` firmó la lista */
    private function turno(string $entrante, string $memoria = ''): array
    {
        $this->nuevaConversacion();
        $servicio = app(ConversationMemoryService::class);
        match ($memoria) {
            '' => null,
            // Una visita a medio concretar: el objetivo vivo (como `UltronA4RegistroYPanoramaTest::visitaAMedias()`).
            'visita' => $servicio->recordCourtesyCollecting($this->conversation->fresh(), ['date' => '2026-11-21']),
            // La persona ya rechazó el Plan Valera.
            'rechazado' => $servicio->recordPlanRejected($this->conversation->fresh(), 3),
        };
        $m = $this->entrante($entrante);
        $decide = $this->decide($m);

        return [$m, $decide['decide_token'], isset(self::cargaDelToken($decide['decide_token'])['x']['po'])];
    }

    private function commit(MarketingMessage $m, string $token, string $borrador, string $intent, ?int $plan, array $herramientas): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => [
                'strategy_goal' => 'resolve_objection',
                'next_state' => P::GOAL_DISCOVERY,
                'intent' => $intent,
                'reply_draft' => $borrador,
                'recommended_plan_id' => $plan,
                'main_barrier' => null,
                'next_best_action' => 'answer',
                'confidence' => 0.9,
                'tools_requested' => $herramientas,
            ],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $this->headers());
    }

    private function beneficiosDistintos(): void
    {
        Plan::query()->whereKey(3)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Asesoría semi personalizada'])]);
        Plan::query()->whereKey(4)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Clases grupales ilimitadas'])]);
        Plan::query()->whereKey(8)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Entrenador personal dedicado'])]);
    }

    private function nuevaConversacion(): void
    {
        $n = str_pad((string) ++$this->mundo, 3, '0', STR_PAD_LEFT);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '3150541'.$n, 'meta_user_id' => '573150541'.$n,
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
    }

    private function entrante(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.par.'.$this->mundo, 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m): array
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id, 'prompt_contract' => 'ultron-prompts/1',
        ], $this->headers())->assertOk()->json();
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function ultimaAccion(): MarketingAiAction
    {
        return MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
    }
}
