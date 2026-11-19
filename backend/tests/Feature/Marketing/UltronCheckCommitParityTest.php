<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use App\Services\Marketing\Ultron\UltronCommitService;
use App\Services\Marketing\Ultron\UltronRegenerationNotes;
use App\Services\Marketing\Ultron\UltronReplyFitter;
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
 * antes de medir, y decide sobre el mundo de ese instante). Las tres primeras
 * se ejercitan abajo, cada una con su prueba que fija la divergencia exacta; las
 * demás quedan declaradas aquí con su porqué, sin prueba propia:
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
 *      borrador y si `decide` ofreció la lista (`po`).
 *  E7. Un borrador que el commit tumba ANTES (URL, datos de tarjeta, pago o
 *      membresía inventados, guard de contenido, presión, transición ilegal,
 *      efecto sin autoridad) no llega a medirse: el 422 no es por estas
 *      causas. Lo fija `UltronCheckEndpointTest::test_no_es_un_oraculo_de_las_guardas`.
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

    private const HORARIO_LARGO = 'El gimnasio abre de lunes a viernes de 5:00 a. m. a 10:00 p. m., y los sábados, domingos y festivos de 8:00 a. m. a 2:00 p. m. Es el horario de apertura del gimnasio, no el de las clases, así que puede entrenar cuando más le convenga. ¿Le gustaría venir esta semana?';

    /** Las causas en un orden fijo, el de los códigos. */
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
            'lista que solo se pasa expandida' => [self::PANORAMA, $precio, self::LARGO, null, [], false, ['length_over_cap']],
            'lista que tutea y se pasa' => [self::PANORAMA, $precio, str_replace(['Le explico', 'le interesa'], ['Te explico', 'te interesa'], self::LARGO), null, [], false, ['register_tuteo', 'length_over_cap']],
            'falta la lista' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}}, de un mes. ¿Le interesa?', null, [], false, ['plans_overview']],
            'mezcla con el precio' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}} desde {{PLAN_PRICE}}. ¿Cuál le interesa?', 3, [], false, ['plans_overview']],
            'marcador repetido' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}} y otra vez {{PLAN_1}}. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'nombre a mano' => [self::PANORAMA, $precio, 'Claro. Tenemos el Plan Valera {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'marcador inexistente' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'cifra escrita' => [self::PANORAMA, $precio, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}} desde $50.000. ¿Cuál le interesa?', null, [], false, ['plans_overview']],
            'beneficio de otro plan' => [self::PANORAMA, $precio, 'Claro. {{PLAN_1}} incluye entrenador personal dedicado. También tenemos {{PLAN_2}}. ¿Cuál le interesa?', null, [], true, ['benefit_misattributed']],
            'beneficio en su plan' => [self::PANORAMA, $precio, 'Claro. {{PLAN_3}} incluye entrenador personal dedicado. También tenemos {{PLAN_1}}. ¿Cuál le interesa?', null, [], true, []],
            'beneficio de otro plan y tuteo' => [self::PANORAMA, $precio, 'Claro. {{PLAN_1}} incluye entrenador personal dedicado. También tenemos {{PLAN_2}}. ¿Cuál te interesa?', null, [], true, ['register_tuteo', 'benefit_misattributed']],
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
        // Si se volviera a comprobar ahora, tampoco: no lo arregla una reescritura (no es un código corregible).
        $this->assertSame([], $this->loQueMarcaLaComprobacion($m, $token, self::TRES, SalesIntents::PRICING_QUESTION, null, []));
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
            if ($codigo === 'unknown_placeholder' && preg_grep('/^PLAN_/i', (array) $r->json('detail.unknown')) !== []) {
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
        if (isset($fila['length']['from'], $fila['length']['cap']) && $fila['length']['from'] > $fila['length']['cap']) {
            $causas[] = UltronRegenerationNotes::LENGTH_OVER_CAP;
        }
        if (in_array(CommercialTurnPolicy::VIOLACION_PANORAMA, $violaciones, true)) {
            $causas[] = UltronRegenerationNotes::PLANS_OVERVIEW;
        }
        if (in_array(UltronCommitService::VIOLACION_BENEFICIO, $violaciones, true)) {
            $causas[] = UltronRegenerationNotes::BENEFIT_MISATTRIBUTED;
        }

        return self::enOrden($causas);
    }

    /** @param list<string> $codigos */
    private static function enOrden(array $codigos): array
    {
        return array_values(array_filter(self::ORDEN, fn (string $c) => in_array($c, $codigos, true)));
    }

    /** @return array{MarketingMessage,string,bool} el mensaje, el token y si `decide` firmó la lista */
    private function turno(string $entrante): array
    {
        $this->nuevaConversacion();
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
