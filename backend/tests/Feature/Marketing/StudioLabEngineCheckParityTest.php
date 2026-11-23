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
use App\Services\Marketing\Ultron\UltronReplyOrigin;
use App\Services\UltronStudio\StudioLabEngineCheck;
use App\Services\UltronStudio\StudioLabEngineFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * T4 DEL APARTADO 5 (diseño `plans/informes-cierre/diseno-arq-lab.md` §3.4):
 * LA COMPROBACIÓN DEL LABORATORIO ES `ai/check`.
 *
 * Sobre una conversación SEMBRADA (el catálogo vendible de producción, la
 * ficha del horario, la versión «profesional, 200» publicada en la canaria),
 * cada turno pasa por el `decide` real (token firmado, con `sp` y, si toca,
 * `po`) y cada borrador por el `POST ai/check` real. El mismo borrador, con
 * lo firmado en ESE token y lo que el motor leería al comprobar (el catálogo,
 * el texto, la fase, la memoria y el referente), pasa por
 * {@see StudioLabEngineCheck::check()}. Las dos respuestas tienen que ser
 * idénticas, clave a clave: los defectos, las notas, el presupuesto (el
 * intento y los 25 s) y si pide reescribir. Más de 30 borradores.
 *
 * Si el motor cambia la comprobación sin el laboratorio, esto se pone en rojo.
 */
class StudioLabEngineCheckParityTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION = 'c0000000-0000-4000-8000-0000000000c5';

    /** El catálogo vendible de producción (el de `UltronCheckEndpointTest`). */
    private const CATALOGO = [
        [25, 'PLAN DÍA', 10000, 1], [2, 'Plan Semana', 45000, 7], [3, 'Plan Valera', 65000, 30], [4, 'Plan Mensual', 80000, 30],
        [5, 'Trimestre', 210000, 90], [6, 'Semestre', 390000, 180], [7, 'Anualidad', 624000, 365], [8, 'Élite', 180000, 30],
        [9, 'Pro', 499000, 90], [10, 'Prime', 950000, 180], [11, 'Evolution', 1800000, 365], [14, 'PROMO X4', 75000, 30],
        [16, 'INGRESO EMPLEADOS', 1, 360],
    ];

    private const PANORAMA = '¿Qué planes tienen y cuánto cuesta la mensualidad?';

    private const HORARIO = '¿Cuál es el horario de atención entre semana?';

    private const SALUDO = 'Hola, buenas tardes';

    private const MENSUAL = '¿Qué incluye el plan mensual?';

    private const INFORMAL = 'oye parce cuentame todo de los planes q tienen, cuanto valen y si hay clases, es q quiero empezar ya';

    private const TODO = 'Cuénteme todo: horarios, planes, clases y dónde quedan';

    private const BAJA = 'Por favor no me escriban más';

    private const TRES = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál le interesa más?';

    private const TRES_DE_TU = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes. ¿Cuál te interesa más?';

    private const LARGO = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes y con acceso total al gimnasio. Le explico con calma lo que incluye cada uno para que elija con tranquilidad. ¿Cuál le interesa más?';

    /** PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): una lista que pasa del tope aplicable (320) con y sin su pregunta (379; 356 sin ella). */
    private const LARGO_360 = 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes y con acceso total al gimnasio. Le explico con calma lo que incluye cada uno para que elija con tranquilidad, sin prisa y con toda la información que necesite antes de decidir, para que empiece con buen pie y resuelva cualquier duda con el equipo. ¿Cuál le interesa más?';

    private MarketingConversation $conversation;

    private int $mundo = 0;

    protected function setUp(): void
    {
        parent::setUp();
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
        Plan::query()->whereKey(3)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Asesoría semi personalizada'])]);
        Plan::query()->whereKey(4)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Clases grupales ilimitadas'])]);
        Plan::query()->whereKey(8)->update(['benefits' => json_encode(['Acceso total al gimnasio', 'Entrenador personal dedicado'])]);
        MarketingKnowledgeItem::create([
            'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario general de atencion',
            'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m.',
            'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
            'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
            'metadata' => ['windows' => [
                'lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'],
                'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'], 'sabado' => ['08:00', '14:00'], 'domingo' => ['08:00', '14:00'],
            ]],
        ]);
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150539001', 'meta_user_id' => '573150539001', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_las_constantes_del_contrato_son_las_del_motor(): void
    {
        $this->assertSame(UltronDraftCheck::CONTRACT, StudioLabEngineCheck::CONTRACT);
        $this->assertSame(UltronDraftCheck::MAX_DRAFTS, StudioLabEngineFlow::MAX_DRAFTS);
        $this->assertSame(UltronDraftCheck::LEGACY_MAX_DRAFTS, StudioLabEngineFlow::MAX_DRAFTS - 1);
        $this->assertSame(UltronDraftCheck::TIME_BUDGET_SECONDS, StudioLabEngineFlow::TIME_BUDGET_SECONDS);
        $this->assertSame([UltronDraftCheck::REASON_LAST_ATTEMPT, UltronDraftCheck::REASON_TIME_BUDGET, UltronDraftCheck::REASON_LEGACY],
            [StudioLabEngineCheck::REASON_LAST_ATTEMPT, StudioLabEngineCheck::REASON_TIME_BUDGET, StudioLabEngineCheck::REASON_LEGACY]);
    }

    /** @return array<string, array{0:string,1:string,2:string,3:?int,4:list<string>,5:int,6:int}> [entrante, intención, borrador, plan, herramientas, intento, segundos] */
    public static function borradores(): array
    {
        $p = SalesIntents::PRICING_QUESTION;
        $h = SalesIntents::SCHEDULE_QUESTION;
        $g = SalesIntents::GREETING;
        $i = SalesIntents::GENERAL_INFO;

        return [
            'pasa' => [self::PANORAMA, $p, self::TRES, null, [], 1, 0],
            'tutea' => [self::PANORAMA, $p, self::TRES_DE_TU, null, [], 1, 0],
            'tutea y se pasa' => [self::PANORAMA, $p, self::LARGO.' ¿Te lo explico?', null, [], 1, 9],
            'se pasa expandido' => [self::PANORAMA, $p, self::LARGO, null, [], 1, 0],
            'falta la lista' => [self::PANORAMA, $p, 'Claro. Tenemos {{PLAN_1}}, de un mes. ¿Le interesa?', null, [], 1, 0],
            'mezcla' => [self::PANORAMA, $p, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}} desde {{PLAN_PRICE}}. ¿Cuál le interesa?', 3, [], 1, 0],
            'repetido' => [self::PANORAMA, $p, 'Claro. Tenemos {{PLAN_1}} y otra vez {{PLAN_1}}. ¿Cuál le interesa?', null, [], 1, 0],
            'nombre a mano' => [self::PANORAMA, $p, 'Claro. Tenemos el Plan Valera {{PLAN_1}} y {{PLAN_2}}. ¿Cuál le interesa?', null, [], 1, 0],
            'marcador inexistente' => [self::PANORAMA, $p, 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. ¿Cuál le interesa?', null, [], 1, 0],
            'cifra escrita' => [self::PANORAMA, $p, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}} desde $50.000. ¿Cuál le interesa?', null, [], 1, 0],
            'beneficio de otro plan' => [self::PANORAMA, $p, 'Claro. {{PLAN_1}} incluye entrenador personal dedicado. También tenemos {{PLAN_2}}. ¿Cuál le interesa?', null, [], 1, 0],
            'beneficio en su plan' => [self::PANORAMA, $p, 'Claro. {{PLAN_3}} incluye entrenador personal dedicado. También tenemos {{PLAN_1}}. ¿Cuál le interesa?', null, [], 1, 0],
            'un solo plan con precio' => [self::PANORAMA, $p, 'El {{PLAN_NAME}} cuesta {{PLAN_PRICE}} al mes. ¿Le interesa?', 4, [], 1, 0],
            'turno de cobro sin lista' => [self::PANORAMA, SalesIntents::PAYMENT_LINK_REQUEST, 'Claro, le paso {{PLAN_1}}.', null, ['payment_link_send'], 1, 0],
            'con los enlaces de la app' => [self::PANORAMA, $p, self::TRES, null, ['app_links_send'], 1, 0],
            'tutea solo en la pregunta, con enlaces' => [self::PANORAMA, $p, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}. ¿Quieres que te ayude?', null, ['app_links_send'], 1, 0],
            'segundo intento' => [self::PANORAMA, $p, self::TRES_DE_TU, null, [], 2, 12],
            'tercer intento, sin presupuesto' => [self::PANORAMA, $p, self::TRES_DE_TU, null, [], 3, 14],
            'a los 25 segundos aún hay' => [self::PANORAMA, $p, self::TRES_DE_TU, null, [], 2, 25],
            'a los 26 ya no' => [self::PANORAMA, $p, self::TRES_DE_TU, null, [], 2, 26],
            'horario que responde' => [self::HORARIO, $h, 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Le gustaría conocer los planes?', null, [], 1, 0],
            'horario que aplaza' => [self::HORARIO, $h, 'Le confirmo el horario en un momento. ¿Qué objetivo tiene?', null, [], 1, 0],
            'horario con marcador sin lista' => [self::HORARIO, $h, 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. Tenemos {{PLAN_1}}. ¿Le cuento más?', null, [], 1, 0],
            'horario de tú' => [self::HORARIO, $h, 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Te sirve?', null, [], 1, 0],
            'saludo que saluda' => [self::SALUDO, $g, 'Buenas tardes, bienvenido a Iron Body. ¿En qué le puedo ayudar?', null, [], 1, 0],
            'saludo que vende' => [self::SALUDO, $g, 'Buenas tardes. El {{PLAN_NAME}} cuesta {{PLAN_PRICE}}. ¿Se inscribe hoy?', 4, [], 1, 0],
            'saludo con otra intención' => [self::SALUDO, $i, 'Buenas tardes. ¿En qué le puedo ayudar?', null, [], 1, 0],
            'el mensual, bien' => [self::MENSUAL, $i, 'El {{PLAN_NAME}} incluye acceso total al gimnasio y clases grupales ilimitadas. ¿Le interesa?', 4, [], 1, 0],
            'el mensual, con un beneficio ajeno' => [self::MENSUAL, $i, 'El Plan Mensual incluye entrenador personal dedicado. ¿Le interesa?', 4, [], 1, 0],
            'el mensual, solo preguntas' => [self::MENSUAL, $i, '¿Qué objetivo tiene? ¿Ha entrenado antes?', null, [], 1, 0],
            'informal y largo, de tú' => [self::INFORMAL, $p, 'Claro que sí, mira: tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}. Todos te dan acceso total al gimnasio y puedes empezar cuando quieras. ¿Cuál te llama más la atención para arrancar ya mismo?', null, [], 1, 3],
            'informal, de usted' => [self::INFORMAL, $p, self::TRES, null, [], 1, 3],
            'todo, se pasa' => [self::TODO, $i, 'Con gusto. Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. y los sábados y domingos de 8:00 a. m. a 2:00 p. m. Tenemos varios planes mensuales con acceso total, clases grupales y asesoría. ¿Qué le gustaría saber primero?', null, [], 1, 0],
            'todo, en el tope' => [self::TODO, $i, 'Con gusto. Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Qué le gustaría saber primero?', null, [], 1, 0],
            'baja' => [self::BAJA, SalesIntents::DO_NOT_CONTACT_REQUEST, 'Entendido, no le volveremos a escribir.', null, ['mark_do_not_contact'], 1, 0],
            'vacío' => [self::PANORAMA, $p, '', null, [], 1, 0],
            'solo espacios' => [self::PANORAMA, $p, "  \n ", null, [], 1, 0],
            'con espacios alrededor que tutean' => [self::PANORAMA, $p, '  '.self::TRES_DE_TU."\n\n", null, [], 1, 0],
            'herramienta fuera del vocabulario' => [self::PANORAMA, $p, self::TRES, null, ['human_takeover'], 1, 0],
        ];
    }

    /** @param  list<string>  $tools */
    #[DataProvider('borradores')]
    public function test_t4_el_laboratorio_contesta_lo_mismo_que_ai_check(string $entrante, string $intent, string $borrador, ?int $plan, array $tools, int $intento, int $segundos): void
    {
        $m = $this->entrante($entrante);
        $token = (string) $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id, 'prompt_contract' => 'ultron-prompts/1'], $this->headers())
            ->assertOk()->json('decide_token');
        Carbon::setTestNow(now()->addSeconds($segundos));

        $real = $this->postJson('/api/internal/marketing/ai/check', [
            'conversation_id' => $m->conversation_id, 'message_id' => $m->id, 'decide_token' => $token, 'attempt' => $intento,
            'draft' => ['reply_draft' => $borrador, 'intent' => $intent, 'recommended_plan_id' => $plan, 'tools_requested' => $tools],
        ], $this->headers());

        // Lo que recibe n8n del nodo HTTP: el estado y, con un 200, la respuesta entera.
        $lab = app(StudioLabEngineCheck::class)->respond($this->turnoDe($m, $token), ['attempt' => $intento, 'draft' => ['reply_draft' => $borrador, 'intent' => $intent, 'recommended_plan_id' => $plan, 'tools_requested' => $tools]], $segundos);

        $this->assertSame($real->status(), $lab['statusCode']);
        if ($real->status() === 200) {
            $this->assertSame($real->json(), $lab['body']);
        }
    }

    public function test_t4_la_matriz_cubre_lo_que_dice(): void
    {
        // Más de 30 borradores, y todos los códigos de los cuatro de §4.4 y varios de política aparecen.
        $this->assertGreaterThan(30, count(self::borradores()));
        $codigos = [];
        foreach (self::borradores() as [$entrante, $intent, $borrador, $plan, $tools, $intento, $segundos]) {
            $m = $this->entrante($entrante);
            $token = (string) $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id, 'prompt_contract' => 'ultron-prompts/1'], $this->headers())->json('decide_token');
            $r = app(StudioLabEngineCheck::class)->check($this->turnoDe($m, $token), $intento, ['reply_draft' => $borrador, 'intent' => $intent, 'recommended_plan_id' => $plan, 'tools_requested' => $tools], $segundos);
            foreach ($r['defects'] as $d) {
                $codigos[$d['code']] = true;
            }
        }
        foreach (['register_tuteo', 'length_over_cap', 'plans_overview', 'benefit_misattributed'] as $c) {
            $this->assertArrayHasKey($c, $codigos, "ningún borrador da {$c}");
        }
        $this->assertNotEmpty(array_filter(array_keys($codigos), static fn (string $c): bool => str_starts_with($c, 'policy_')), 'ninguna regla de política');
    }

    /** @return array<string, array{0:string,1:string,2:string,3:?int,4:list<string>,5:string}> [entrante, intención, borrador, plan, herramientas, veredicto] */
    public static function finales(): array
    {
        $p = SalesIntents::PRICING_QUESTION;
        $h = SalesIntents::SCHEDULE_QUESTION;

        return [
            'pasa: del modelo' => [self::PANORAMA, $p, self::TRES, null, [], 'pass'],
            'tutea: respaldo' => [self::PANORAMA, $p, self::TRES_DE_TU, null, [], 'pass'],
            // PT-17b (regla «tope de lista», decisión del propietario del 23-11-2026): estos tres usaban listas de 200 a 320, que ahora caben en el tope
            // aplicable y salen enteras; el recorte se prueba con un horario (sin lista) y los dos respaldos con listas de más de 320.
            'se pasa y cabe sin la pregunta: del modelo, recortado' => [self::HORARIO, $h, 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m., y los sábados, domingos y festivos de 8:00 a. m. a 2:00 p. m. ¿Le gustaría que le cuente también cómo funciona la inscripción y qué necesita para empezar a entrenar con nosotros?', null, [], 'pass'],
            'se pasa y ni sin la pregunta cabe: respaldo' => [self::PANORAMA, $p, self::LARGO_360, null, [], 'pass'],
            'se pasa y no cabe: respaldo' => [self::PANORAMA, $p, 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}, todos de un mes y con acceso total al gimnasio, con duchas, lockers, zona de cardio, zona de pesas y clases grupales de lunes a sábado. Le explico con calma lo que incluye cada uno, para que compare con tranquilidad y elija el que mejor le encaje.', null, [], 'pass'],
            // Y las listas de antes, con lo que da ahora la regla: del modelo, enteras (sin recortar), medidas contra 320.
            'lista de dos que pasa de 200 y cabe en el tope de lista: del modelo, entera' => [self::PANORAMA, $p, 'Claro. Tenemos {{PLAN_1}} y {{PLAN_2}}, los dos de un mes y con acceso total al gimnasio y a las zonas de pesas. Le explico lo que incluye cada uno. ¿Cuál le interesa más de los dos?', null, [], 'pass'],
            'lista de tres que pasa de 200 y cabe en el tope de lista: del modelo, entera' => [self::PANORAMA, $p, self::LARGO, null, [], 'pass'],
            'falta la lista: respaldo' => [self::PANORAMA, $p, 'Claro. Tenemos {{PLAN_1}}, de un mes. ¿Le interesa?', null, [], 'pass'],
            'marcador inexistente: respaldo' => [self::PANORAMA, $p, 'Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_4}}. ¿Cuál le interesa?', null, [], 'pass'],
            'beneficio de otro plan: respaldo' => [self::PANORAMA, $p, 'Claro. {{PLAN_1}} incluye entrenador personal dedicado. También tenemos {{PLAN_2}}. ¿Cuál le interesa?', null, [], 'pass'],
            'horario que responde: del modelo' => [self::HORARIO, $h, 'Abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿Le gustaría conocer los planes?', null, [], 'pass'],
            'horario que aplaza (la política del motor no lo marca): del modelo' => [self::HORARIO, $h, 'Le confirmo el horario en un momento. ¿Qué objetivo tiene?', null, [], 'pass'],
            'saludo que vende: respaldo' => [self::SALUDO, SalesIntents::GREETING, 'Buenas tardes. El {{PLAN_NAME}} cuesta {{PLAN_PRICE}}. ¿Se inscribe hoy?', 4, [], 'pass'],
            'el revisor lo rechaza: respaldo' => [self::PANORAMA, $p, self::TRES, null, [], 'fail'],
        ];
    }

    /**
     * T4b · LA PREDICCIÓN DEL TEXTO FINAL ({@see StudioLabEngineCheck::predict()})
     * frente al `POST ai/commit` real del mismo turno, clasificado con
     * {@see UltronReplyOrigin::classify()} (el origen del informe de la
     * canaria). Un 422 del commit también es respaldo: n8n pide entonces la
     * rendición. Una conversación nueva por caso: el commit cambia la memoria.
     *
     * @param  list<string>  $tools
     */
    #[DataProvider('finales')]
    public function test_t4b_la_prediccion_del_texto_final_es_la_del_commit(string $entrante, string $intent, string $borrador, ?int $plan, array $tools, string $veredicto): void
    {
        $lead = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150539777', 'meta_user_id' => '573150539777', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW]);
        $this->conversation = MarketingConversation::create(['lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
        $m = $this->entrante($entrante);
        $token = (string) $this->postJson('/api/internal/marketing/ai/decide', ['conversation_id' => $this->conversation->id, 'message_id' => $m->id, 'prompt_contract' => 'ultron-prompts/1'], $this->headers())->assertOk()->json('decide_token');
        $critic = ['verdict' => $veredicto, 'attempt' => $veredicto === 'pass' ? 1 : 3, 'score' => $veredicto === 'pass' ? 0.9 : 0.2, 'notes' => '', 'issues' => [], 'hard_fail' => null];
        $prediccion = app(StudioLabEngineCheck::class)->predict($this->turnoDe($m, $token), ['reply_draft' => $borrador, 'recommended_plan_id' => $plan, 'critic' => $critic], ['intent' => $intent, 'tools_requested' => $tools]);

        $r = $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => [
                'strategy_goal' => 'resolve_objection', 'next_state' => 'GOAL_DISCOVERY', 'intent' => $intent, 'reply_draft' => $borrador,
                'recommended_plan_id' => $plan, 'main_barrier' => null, 'next_best_action' => 'answer', 'confidence' => 0.9, 'tools_requested' => $tools,
            ],
            'critic' => $critic,
        ], $this->headers());
        $this->assertContains($r->status(), [200, 201, 422], 'el commit contesta');
        if ($r->status() === 422) {
            $real = UltronReplyOrigin::FALLBACK;
        } else {
            $accion = MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
            $real = UltronReplyOrigin::classify(is_array($accion->metadata) ? $accion->metadata : [], (string) $accion->action_type)['origin'];
        }

        $this->assertSame($real, $prediccion['origin'], 'causa predicha: '.($prediccion['cause'] ?? 'ninguna'));
        // El caso dice lo que espera: así la paridad no pasa en vacío (p. ej. con todo en 422).
        $this->assertSame(str_contains((string) $this->dataName(), 'del modelo') ? UltronReplyOrigin::MODEL : UltronReplyOrigin::FALLBACK, $real,
            'lo que hizo el commit (HTTP '.$r->status().', causa predicha '.($prediccion['cause'] ?? 'ninguna').', '.json_encode($prediccion['codes']).', '.$prediccion['chars'].' caracteres)');
        if (str_contains((string) $this->dataName(), 'recortado')) {
            $this->assertTrue($prediccion['trimmed']);
            $this->assertTrue($prediccion['withinCap']);
        }
        // PT-17b (regla «tope de lista»): la lista que cabe en el tope aplicable sale sin recortar, medida contra 320.
        if (str_contains((string) $this->dataName(), 'entera')) {
            $this->assertSame([false, true, 320], [$prediccion['trimmed'], $prediccion['withinCap'], $prediccion['cap']]);
        }
        if ($veredicto === 'fail') {
            $this->assertSame(StudioLabEngineCheck::CAUSE_CRITIC, $prediccion['cause']);
        }
        $this->assertSame([StudioLabEngineCheck::ORIGIN_MODEL, StudioLabEngineCheck::ORIGIN_FALLBACK], [UltronReplyOrigin::MODEL, UltronReplyOrigin::FALLBACK]);
    }

    /**
     * El turno del laboratorio con lo que el motor tendría al comprobar ESTE
     * mensaje: lo firmado en el token y lo que leería.
     *
     * @return array<string,mixed>
     */
    private function turnoDe(MarketingMessage $m, string $token): array
    {
        $firmado = app(UltronDecideToken::class)->peek($token, (int) $m->conversation_id, (int) $m->id);
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

    private function entrante(string $texto): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => 'wamid.lab.'.(++$this->mundo).'.'.uniqid(), 'status' => 'received',
        ]);
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }
}
