<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAgentAction;
use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MyClass;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ConversationMemoryService as Mem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LA CONVERSACIÓN 254, REPRODUCIDA TURNO A TURNO.
 *
 * Es la prueba física del 22 de septiembre, con los MISMOS mensajes de la
 * persona y los MISMOS borradores y planes que devolvió el modelo, leídos de
 * las filas de `marketing_ai_actions`. Lo que se replica es el modelo; lo
 * que se mide es lo que Laravel deja salir.
 *
 * Lo que salió aquella noche, en orden:
 *
 *   · «quisiera más información del gimnasio» → un interrogatorio sobre el
 *     objetivo, sin un solo hecho del gimnasio;
 *   · «me gustaría el día de mañana en la noche» —la persona concretando su
 *     visita— → «El Plan Semana te ofrece … por $80.000 COP», que es el
 *     nombre de un plan con el precio de OTRO (el estratega eligió el
 *     Mensual, el redactor escribió «Semana»);
 *   · «¿seguro que cuesta eso?» → «$45.000», y con razón: la persona vio dos
 *     precios para el mismo plan;
 *   · «¿por qué antes 80 y luego 45?» → «la diferencia puede ser por
 *     confusión con otro plan o información previa», que es una causa
 *     inventada.
 *
 * Y la cortesía nunca se registró: `courtesy_request` en null, cero citas.
 */
class Conversacion254Test extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private MarketingConversation $conversation;

    private Plan $semana;

    private Plan $mensual;

    private Plan $insignia;

    protected function setUp(): void
    {
        parent::setUp();
        // Un martes por la noche en Neiva: «mañana» es miércoles y está abierto.
        Carbon::setTestNow(Carbon::parse('2026-09-22 04:46:00', 'UTC'));

        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::fake();

        // El catálogo de producción aquella noche, en lo que importa.
        $this->semana = Plan::create(['name' => 'Plan Semana', 'price' => 45000, 'duration_days' => 7, 'active' => true, 'sellable' => true, 'sort_order' => 1, 'benefits' => json_encode(['Acceso al gimnasio por 7 días'])]);
        $this->mensual = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 3, 'benefits' => json_encode(['Acceso ilimitado al gimnasio', 'Zona de pesas y maquinaria'])]);
        $this->insignia = Plan::create(['name' => 'TOTAL ACCESS - ELITE', 'price' => 180000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 108, 'is_recommended' => true, 'benefits' => json_encode(['Acceso completo', 'Acompañamiento'])]);

        // La ficha del gimnasio, como está sembrada.
        foreach ([
            ['business_identity', 'identity.brand', 'Quiénes somos', 'Iron Body Neiva es un centro de acondicionamiento físico en Neiva.'],
            ['gym_info', 'gym.benefits', 'Beneficios', 'En Iron Body entrenas en un lugar serio, con estructura y acompañamiento para avanzar con seguridad hacia tu objetivo.'],
            ['schedule', 'schedule.opening_hours', 'Horario', 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados y domingos de 8:00 a. m. a 2:00 p. m.'],
        ] as [$cat, $key, $title, $content]) {
            MarketingKnowledgeItem::create([
                'category' => $cat, 'key' => $key, 'title' => $title, 'content' => $content, 'is_active' => true,
                'origin' => MarketingKnowledgeItem::ORIGIN_SEEDER,
                'metadata' => $key === 'schedule.opening_hours' ? ['windows' => [
                    'lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'],
                    'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'], 'sabado' => ['08:00', '14:00'], 'domingo' => ['08:00', '14:00'],
                ]] : null,
            ]);
        }

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Alejandro', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::NEW_LEAD,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    /** Un turno por el recorrido real: decide firma, commit ejecuta. */
    private function turno(string $texto, string $wamid, array $proposal): TestResponse
    {
        $m = MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $texto, 'meta_message_id' => $wamid, 'status' => 'received',
        ]);
        $token = (string) $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->h())->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $wamid,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => array_merge([
                'next_state' => P::DISCOVERY, 'intent' => SalesIntents::GENERAL_INFO, 'confidence' => 0.8,
                'tools_requested' => [], 'recommended_plan_id' => null,
            ], $proposal),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.95, 'issues' => [], 'hard_fail' => null],
        ], $this->h());
    }

    private function ultimo(): string
    {
        return (string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body;
    }

    /** @return string[] */
    private function salientes(): array
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->orderBy('id')->pluck('body')->all();
    }

    private function memoria(): array
    {
        return (array) $this->conversation->fresh()->memory;
    }

    public function test_la_noche_del_22_de_septiembre_sale_bien_esta_vez(): void
    {
        // ── 1 · «quisiera más información» recibió un interrogatorio ────────
        $this->turno('hola buenas noches, quisiera mas informacion del gimnasio', 'c254.1', [
            'next_state' => P::NEW_LEAD,
            'reply_draft' => 'Muy buenas noches, gracias por escribirnos. Para darte la mejor información sobre el gimnasio, ¿me puedes contar qué objetivo tienes con tu entrenamiento?',
        ])->assertOk();
        $t1 = mb_strtolower($this->ultimo());
        $this->assertStringNotContainsString('objetivo tienes', $t1, 'volvió a interrogar antes de informar');
        $this->assertTrue(
            str_contains($t1, 'iron body neiva es un centro') || str_contains($t1, 'lugar serio'),
            "no salió ni un hecho del gimnasio: «{$t1}»",
        );
        foreach (['plan semana', 'plan mensual', 'total access'] as $nombre) {
            $this->assertStringNotContainsString($nombre, $t1, 'apareció un plan que nadie pidió');
        }
        $this->assertNotNull($this->memoria()['greeted'] ?? null);

        // ── 2 · un borrador que informa con lo que existe sale intacto ─────
        // (el real de esa noche decía «pilates, yoga»: ese se fija aparte, y
        // ya no sale así)
        $this->turno('es que los vi por redes sociales y me gustaria saber mas del gimnasio', 'c254.2', [
            'reply_draft' => 'Claro, te puedo contar que en Iron Body Neiva contamos con entrenadores especializados en musculación y funcional. ¿Qué tipo de entrenamiento te llama más la atención?',
        ])->assertOk();
        $this->assertStringContainsString('entrenadores', mb_strtolower($this->ultimo()));

        // ── 3 · el objetivo llega solo; preguntar la disponibilidad es legítimo
        $this->turno('lo que pasa es que ando buscando un gimnasio y pues me recomendaron este, con el tema de mi entrenamiento soy pricipiante y me gustaria el tema de ganar masa muscular', 'c254.3', [
            'intent' => SalesIntents::GOAL_MUSCLE_GAIN,
            'reply_draft' => 'Perfecto, ganar masa muscular es un objetivo claro para empezar a entrenar. Para recomendarte un plan que se ajuste, ¿qué días y horarios tienes disponibles para entrenar en la semana?',
        ])->assertOk();

        // ── 4 · «6 días» NO es el Plan Semana ────────────────────────────────
        $this->turno('tengo 6 dias disponibles', 'c254.4', [
            'intent' => SalesIntents::GOAL_MUSCLE_GAIN,
            'reply_draft' => 'Perfecto, tienes 6 días disponibles para entrenar, eso es genial para ganar masa muscular. ¿Me puedes decir en qué horarios te gustaría venir esos días?',
        ])->assertOk();
        $this->assertStringNotContainsString('semana', mb_strtolower($this->ultimo()), '«6 días» volvió a ser el Plan Semana');
        $this->assertSame([], $this->memoria()['plans_discussed'] ?? [], 'algún plan entró en la conversación sin que nadie lo nombrara');

        // ── 5 · la jornada ───────────────────────────────────────────────────
        $this->turno('en la jornada de la noche', 'c254.5', [
            'intent' => SalesIntents::GOAL_MUSCLE_GAIN,
            'reply_draft' => 'Claro, el gimnasio está abierto en la noche de lunes a viernes hasta las 10:00 p.m., ¿a qué hora prefieres llegar en esos días para entrenar?',
        ])->assertOk();

        // ── 6 · pide conocer el gimnasio; el estratega pide la cortesía SIN hora
        $this->turno('tipo 7 aproximadamente pero tengo una duda hay alguna manera de poder acercame las instalaciones para observar que tienen y como se o algo?', 'c254.6', [
            'reply_draft' => 'Claro que sí, en Iron Body Neiva ofrecemos un día de cortesía para que puedas conocer las instalaciones, observar el equipo que tenemos y resolver todas tus dudas. ¿Qué día y hora te gustaría pasar para hacer esta visita?',
            'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
        ])->assertOk();
        $goal = $this->memoria()['active_goal'] ?? null;
        $this->assertSame(Mem::GOAL_COURTESY, $goal['kind'] ?? null, 'la visita a medias no dejó rastro: eso es lo que perdió la cortesía');
        $this->assertSame(Mem::GOAL_COLLECTING, $goal['status'] ?? null);

        // ── 7 · «mañana en la noche» recibió el Plan Semana con el precio del Mensual
        $this->turno('me gustaria el dia de mañana en la noche', 'c254.7', [
            'reply_draft' => 'Para tu visita mañana en la noche, ten en cuenta que el gimnasio está abierto hasta las 10:00 p.m. El Plan Semana te ofrece acceso ilimitado al gimnasio y zona de pesas y maquinaria por {{PLAN_PRICE}}. ¿Quieres que te explique más sobre este plan?',
            'recommended_plan_id' => $this->mensual->id, 'next_best_action' => 'orient',
        ])->assertOk();
        $t7 = mb_strtolower($this->ultimo());
        $this->assertStringNotContainsString('plan semana', $t7, 'volvió a vender con la visita a medias');
        $this->assertStringNotContainsString('$', $t7, 'salió un precio en pleno registro de la visita');
        $this->assertStringContainsString('visita', $t7, 'la cortesía se perdió otra vez');
        $this->assertSame([], $this->memoria()['prices_delivered'] ?? [], 'la memoria anotó un precio que no salió');
        $this->assertSame(Mem::GOAL_COLLECTING, $this->memoria()['active_goal']['status'] ?? null, 'el objetivo se cerró sin registrar nada');

        // ── 8 · duda del precio: se contesta el precio REAL y se retoma la visita
        $this->turno('seguro que el plan semana cuesta eso? es mas no que me iba era dar una cortesia?', 'c254.8', [
            'intent' => SalesIntents::PRICE_OBJECTION, 'main_barrier' => 'price',
            'reply_draft' => 'El Plan Semana cuesta exactamente {{PLAN_PRICE}} y te da acceso ilimitado al gimnasio y a la zona de pesas y maquinaria. Sobre la cortesía, el día de cortesía es para que conozcas las instalaciones y pruebes el gimnasio una vez, pero no reemplaza el costo del plan si quieres entrenar más días.',
            'recommended_plan_id' => $this->semana->id, 'next_best_action' => 'handle_objection',
        ])->assertOk();
        $t8 = $this->ultimo();
        $this->assertStringContainsString('$45.000', $t8, 'no salió el precio real del Plan Semana');
        $this->assertStringNotContainsString('$80.000', $t8);
        $this->assertStringContainsString('cortes', mb_strtolower($t8), 'contestó el precio y olvidó la visita');

        // ── 9 · «¿por qué antes 80 y luego 45?» no recibe una excusa inventada
        $this->turno('entonces porque en el aterior mensaje me dices que el plan semana cuesta 80mil y luego que 45mil', 'c254.9', [
            'intent' => SalesIntents::PRICE_OBJECTION, 'main_barrier' => 'price',
            'reply_draft' => 'La diferencia en el precio puede ser por confusión con otro plan o información previa. El Plan Semana cuesta {{PLAN_PRICE}} y te da acceso ilimitado al gimnasio y zona de pesas y maquinaria. El día de cortesía es una visita para conocer el gimnasio, pero no es un plan ni reemplaza el pago si quieres entrenar más días. ¿Quieres que te cuente cómo aprovechar mejor el Plan Semana según tus objetivos?',
            'recommended_plan_id' => $this->semana->id, 'next_best_action' => 'handle_objection',
        ])->assertOk();
        $t9 = mb_strtolower($this->ultimo());
        $this->assertStringNotContainsString('puede ser por', $t9, 'salió la excusa inventada');
        $this->assertStringNotContainsString('confusion', $t9);
        $this->assertStringContainsString('$45.000', $this->ultimo(), 'la persona se quedó sin el precio verdadero');

        // ── Y en toda la conversación, UN solo precio para el Plan Semana ────
        foreach ($this->salientes() as $s) {
            if (str_contains(mb_strtolower($s), 'plan semana') && str_contains($s, '$')) {
                $this->assertStringContainsString('$45.000', $s, "el Plan Semana salió con otro precio: «{$s}»");
                $this->assertStringNotContainsString('$80.000', $s);
            }
        }
        $this->assertSame(9, count($this->salientes()), 'algún turno se quedó mudo');
        $this->assertSame(0, MarketingAgentAction::where('status', MarketingAgentAction::STATUS_SUGGESTED)->count(), 'se registró una visita que nadie completó');
    }

    /**
     * LA PALABRA «SEMANA» NO ES EL PLAN SEMANA.
     *
     * Así entró el plan en 254 sin que nadie lo nombrara: el agente escribió
     * «¿qué días tienes disponibles en la semana?», la memoria anotó el Plan
     * Semana como discutido, la novedad ofreció sus beneficios como «lo
     * fresco» y el redactor lo nombró en el turno siguiente.
     */
    public function test_la_palabra_semana_en_boca_del_agente_no_anota_el_plan_semana(): void
    {
        $this->turno('soy principiante y quiero ganar masa muscular', 'c254.s1', [
            'intent' => SalesIntents::GOAL_MUSCLE_GAIN,
            'reply_draft' => 'Perfecto. Para recomendarte bien, ¿qué días tienes disponibles para entrenar en la semana?',
        ])->assertOk();

        $this->assertSame([], $this->memoria()['plans_discussed'] ?? [], 'la palabra «semana» anotó el Plan Semana');
        $this->assertNull(app(Mem::class)->load($this->conversation->fresh())->pendingPlanId());

        // Escrito entero sí cuenta: es el nombre del producto.
        $this->turno('y el plan semana que incluye?', 'c254.s2', [
            'intent' => SalesIntents::PRICING_QUESTION,
            'reply_draft' => 'El Plan Semana te da acceso al gimnasio por 7 días y cuesta {{PLAN_PRICE}}.',
            'recommended_plan_id' => $this->semana->id,
        ])->assertOk();

        $this->assertSame([$this->semana->id], $this->memoria()['plans_discussed'] ?? []);
    }

    /** Un borrador que ERA todo excusa no sale vacío: sale el respaldo. */
    public function test_retirar_la_excusa_nunca_deja_un_mensaje_vacio(): void
    {
        $this->turno('porque antes 80 y ahora 45?', 'c254.x1', [
            'intent' => SalesIntents::PRICE_OBJECTION,
            'reply_draft' => 'La diferencia en el precio puede ser por confusión con otro plan.',
            'recommended_plan_id' => $this->semana->id,
        ])->assertOk();

        $this->assertNotSame('', trim($this->ultimo()), 'salió un mensaje vacío');
        $this->assertStringNotContainsString('confusion', mb_strtolower($this->ultimo()));
        $this->assertStringContainsString('$45.000', $this->ultimo(), 'quien duda del precio recibe el precio de hoy');
    }

    /** Y la frase honesta sobre la diferencia sale entera. */
    public function test_hablar_de_la_diferencia_sin_inventar_una_causa_sale_entero(): void
    {
        $this->turno('por que el plan semana vale 45 y el plan mensual 80?', 'c254.x2', [
            'intent' => SalesIntents::PRICING_QUESTION,
            'reply_draft' => 'La diferencia de precio entre el Plan Semana y el Plan Mensual puede parecer grande, pero el Mensual cuesta {{PLAN_PRICE}} y rinde mucho más.',
            'recommended_plan_id' => $this->mensual->id,
        ])->assertOk();

        $this->assertStringContainsString('puede parecer grande', $this->ultimo());
        $this->assertStringContainsString('$80.000', $this->ultimo());
    }

    // ── Hallazgos de la revisión final ───────────────────────────────────────

    /** Dos planes nombrados con la cifra pegada al primero: sale entero. */
    public function test_dos_planes_nombrados_con_la_cifra_pegada_a_uno_salen_enteros(): void
    {
        // Sin pedir recomendación ni información: si pidiera planes, el
        // insignia mandaría por prioridad comercial; si pidiera información,
        // se exigiría un hecho del gimnasio. Las dos reglas ya están
        // certificadas aparte; aquí se mide SOLO el dueño de la cifra.
        $this->turno('listo, dale', 'c254.r4', [
            'reply_draft' => 'Tenemos el Plan Mensual por {{PLAN_PRICE}} y tambien el Plan Semana si prefieres menos tiempo. ¿Cual te sirve?',
            'recommended_plan_id' => $this->mensual->id,
        ])->assertOk();

        $t = $this->ultimo();
        $this->assertStringContainsString('$80.000', $t, 'la cifra pegada al Mensual no salió');
        $this->assertStringContainsString('Plan Semana', $t, 'se perdió el resto del borrador');
        $this->assertStringContainsString('¿Cual te sirve?', $t);
    }

    /** Y cuando la cifra no tiene dueño, el respaldo sigue hablando del plan. */
    public function test_una_cifra_sin_dueno_no_tira_el_texto_a_la_basura(): void
    {
        $this->turno('listo, dale', 'c254.r4b', [
            'reply_draft' => 'Tienes el Plan Mensual y el Plan Semana; el precio es {{PLAN_PRICE}}.',
            'recommended_plan_id' => $this->mensual->id,
        ])->assertOk();

        $t = $this->ultimo();
        // El texto se conserva menos la oración ambigua, y la cifra vuelve con dueño explícito.
        $this->assertStringContainsString('Plan Mensual y el Plan Semana', $t, 'se perdió lo que el borrador contaba');
        $this->assertStringNotContainsString('el precio es', $t, 'salió la oración con la cifra sin dueño');
        $this->assertStringContainsString('El Plan Mensual cuesta $80.000', $t, 'la cifra no volvió con su dueño');
    }

    /** Una comparativa en UNA oración: el dueño es el plan pegado a la cifra. */
    public function test_una_comparativa_en_una_sola_oracion_tiene_dueno(): void
    {
        $this->turno('listo, dale', 'c254.r4c', [
            'reply_draft' => 'Tenemos el Plan Semana para quien viene con poco tiempo, pero el Plan Mensual cuesta {{PLAN_PRICE}} y es el que mas sale.',
            'recommended_plan_id' => $this->semana->id,
        ])->assertOk();

        $t = $this->ultimo();
        $this->assertStringContainsString('el Plan Mensual cuesta $80.000', $t, 'la cifra no siguió al plan pegado a ella');
        $this->assertStringContainsString('Plan Semana para quien', $t, 'se perdió el texto');
    }

    /** Aceptar un plan y negar otro veta el negado, nunca el aceptado. */
    public function test_negar_un_plan_no_veta_el_que_acaba_de_aceptar(): void
    {
        $this->turno('el plan mensual esta bien, no me gusta el plan semana', 'c254.r3', [
            'intent' => SalesIntents::PRICING_QUESTION,
            'reply_draft' => 'El Plan Mensual te queda en {{PLAN_PRICE}}.',
            'recommended_plan_id' => $this->mensual->id,
        ])->assertOk();

        $this->assertStringContainsString('$80.000', $this->ultimo());
        $this->assertSame([$this->semana->id], $this->memoria()['plans_rejected'] ?? [], 'se vetó el plan equivocado');
    }

    /** Rechazar la visita con palabras la cierra: no se retoma nunca más. */
    public function test_rechazar_la_visita_con_palabras_cierra_el_objetivo(): void
    {
        $this->turno('quiero conocer el gimnasio antes', 'c254.r5a1', [
            'reply_draft' => 'Claro, puedes venir un día de cortesía a conocerlo. ¿Qué día te gustaría pasar para hacer esta visita?',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
        ])->assertOk();
        $this->assertSame(Mem::GOAL_COLLECTING, $this->memoria()['active_goal']['status'] ?? null);

        $this->turno('no, prefiero no ir por ahora', 'c254.r5a2', [
            'reply_draft' => 'Sin problema, cuando quieras me dices.',
        ])->assertOk();
        $this->assertNull($this->memoria()['active_goal'] ?? null, 'la visita rechazada siguió abierta');
        $this->assertStringNotContainsString('visita', mb_strtolower($this->ultimo()), 'insistió con la visita que acaba de rechazar');

        $this->turno('cuanto vale el plan mensual?', 'c254.r5a3', [
            'intent' => SalesIntents::PRICING_QUESTION,
            'reply_draft' => 'El Plan Mensual cuesta {{PLAN_PRICE}}.',
            'recommended_plan_id' => $this->mensual->id,
        ])->assertOk();
        $this->assertStringNotContainsString('visita', mb_strtolower($this->ultimo()), 'retomó una visita que la persona rechazó');
        $this->assertStringContainsString('$80.000', $this->ultimo());
    }

    /** Una pregunta con la visita a medias se contesta y luego se retoma: no se secuestra. */
    public function test_una_pregunta_con_la_visita_a_medias_se_contesta_y_se_retoma(): void
    {
        $this->turno('quiero conocer el gimnasio antes', 'c254.r5b1', [
            'reply_draft' => 'Claro, puedes venir un día de cortesía. ¿Qué día te gustaría pasar para hacer esta visita?',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
        ])->assertOk();

        // No hay clase de yoga: la respuesta honesta lo dice y contesta igual.
        $this->turno('cuanto cuestan las clases de yoga?', 'c254.r5b2', [
            'intent' => SalesIntents::PRICING_QUESTION,
            'reply_draft' => 'Clases de yoga no tenemos, pero las clases funcionales estan incluidas en el {{PLAN_NAME}}, que cuesta {{PLAN_PRICE}}.',
            'recommended_plan_id' => $this->mensual->id,
        ])->assertOk();

        $t = $this->ultimo();
        $this->assertStringContainsString('yoga', mb_strtolower($t), 'la pregunta se perdió entera');
        $this->assertStringContainsString('$80.000', $t, 'la respuesta salió sin el precio');
        $this->assertStringContainsString('visita', mb_strtolower($t), 'contestó y no retomó la visita');
        $this->assertSame(Mem::GOAL_COLLECTING, $this->memoria()['active_goal']['status'] ?? null, 'una pregunta cerró la visita');
    }

    /**
     * Lo mismo con la etiqueta que el modelo le puso a todo en la 254:
     * `general_info`. Con ella `plan_recommendation` queda prohibido por la
     * regla de información, y de eso se deducía que la visita mandaba: la
     * respuesta salía sustituida por «¿qué día y a qué hora te queda bien?».
     */
    public function test_la_etiqueta_general_info_no_convierte_la_pregunta_en_secuestro(): void
    {
        $this->turno('quiero conocer el gimnasio antes', 'c254.r5c1', [
            'reply_draft' => 'Claro, puedes venir un día de cortesía. ¿Qué día te gustaría pasar para hacer esta visita?',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
        ])->assertOk();

        $this->turno('cuanto cuestan las clases de yoga?', 'c254.r5c2', [
            'reply_draft' => 'Clases de yoga no tenemos, pero las clases funcionales estan incluidas en el {{PLAN_NAME}}, que cuesta {{PLAN_PRICE}}.',
            'recommended_plan_id' => $this->mensual->id,
        ])->assertOk();

        $t = $this->ultimo();
        $this->assertStringContainsString('yoga', mb_strtolower($t), 'la pregunta se perdió entera');
        $this->assertStringContainsString('$80.000', $t, 'la respuesta salió sin el precio');
        $this->assertStringContainsString('visita', mb_strtolower($t), 'contestó y no retomó la visita');
        $this->assertSame(Mem::GOAL_COLLECTING, $this->memoria()['active_goal']['status'] ?? null, 'una pregunta cerró la visita');

        $this->turno('tienen entrenador personal?', 'c254.r5c3', [
            'reply_draft' => 'Si, en la sede tienes entrenadores que te acompanan en cada sesion.',
        ])->assertOk();

        $t = $this->ultimo();
        $this->assertStringContainsString('entrenador', mb_strtolower($t), 'la segunda pregunta se perdió entera');
        $this->assertStringContainsString('visita', mb_strtolower($t), 'contestó y no retomó la visita');
    }

    /**
     * El turno 2 real de aquella noche, palabra por palabra. «Pilates» y
     * «yoga» venían de las especialidades de la ficha de los entrenadores,
     * no de una clase: el gimnasio tiene IRON PARTY, IRON POWERFLOW e IRON
     * STRENGTH y nada más, y eso es lo único que puede afirmar.
     */
    public function test_el_turno_dos_de_la_254_ya_no_sale_con_pilates_ni_yoga(): void
    {
        foreach (['IRON PARTY' => 'friday', 'IRON POWERFLOW' => 'monday', 'IRON STRENGTH' => 'wednesday'] as $nombre => $dia) {
            MyClass::create(['name' => $nombre, 'type' => 'Funcional', 'day_of_week' => $dia, 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'status' => 'active', 'max_capacity' => 20]);
        }

        $this->turno('es que los vi por redes sociales y me gustaria saber mas del gimnasio', 'c254.real2', [
            'reply_draft' => 'Claro, te puedo contar que en Iron Body Neiva contamos con entrenadores especializados en musculación, funcional, pilates, yoga y más. Tenemos clases como IRON POWERFLOW, IRON PARTY e IRON STRENGTH para distintos niveles. ¿Qué tipo de entrenamiento te interesa o qué objetivo tienes en mente?',
        ])->assertOk();

        $t = $this->ultimo();
        $this->assertStringNotContainsString('pilates', mb_strtolower($t), 'volvió a afirmar una clase que no existe');
        $this->assertStringNotContainsString('yoga', mb_strtolower($t), 'volvió a afirmar una clase que no existe');
        $this->assertStringContainsString('IRON POWERFLOW', $t, 'se perdió lo que sí era verdad');
        $this->assertStringContainsString('IRON STRENGTH', $t);
        $this->assertNotEmpty(MarketingAiAction::latest('id')->first()->metadata['invented_service_dropped'] ?? [], 'sin constancia de lo retirado');
    }
}
