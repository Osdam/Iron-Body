<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MyClass;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LA EXPERIENCIA DE CONVERSAR CON ULTRON, EN LOS CINCO CASOS OBLIGATORIOS.
 *
 * La lógica comercial ya vende; lo que fallaba en la prueba física era la
 * forma: un saludo que era un menú, «quiero información» contestado con un
 * interrogatorio, clases que no existen dichas como si existieran, pregunta
 * tras pregunta. Lo que el modelo escribe lo gobiernan los prompts; lo que
 * se mide aquí es la capa determinista de Laravel: los textos de respaldo,
 * el cerrojo contra servicios inventados, y que un buen borrador salga
 * intacto. El «modelo» de cada caso es el borrador que iría en `reply_draft`.
 *
 * Lo que NO puede medir esta prueba, y se dice: que el modelo no pregunte el
 * objetivo en un saludo solo. Eso vive en el prompt del Composer y lo
 * comprueba la prueba física.
 */
class ExperienciaConversacionalTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const IDENTIDAD = 'Iron Body Neiva es un centro de acondicionamiento físico en Neiva.';

    private MarketingConversation $conversation;

    private Plan $mensual;

    private Plan $insignia;

    protected function setUp(): void
    {
        parent::setUp();
        // Un martes a las nueve de la mañana en Neiva: «buenos días».
        Carbon::setTestNow(Carbon::parse('2026-09-22 14:00:00', 'UTC'));

        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::fake();

        $this->mensual = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 3, 'benefits' => json_encode(['Acceso ilimitado al gimnasio', 'Zona de pesas y maquinaria'])]);
        $this->insignia = Plan::create(['name' => 'TOTAL ACCESS - ELITE', 'price' => 180000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 108, 'is_recommended' => true, 'benefits' => json_encode(['Acceso completo', 'Acompañamiento'])]);

        // La ficha de producción, en lo que importa, y las clases que existen.
        foreach ([
            ['business_identity', 'identity.brand', 'Quiénes somos', self::IDENTIDAD],
            ['gym_info', 'gym.benefits', 'Beneficios', 'En Iron Body entrenas en un lugar serio, con estructura y acompañamiento para avanzar con seguridad hacia tu objetivo.'],
            ['location', 'location.city', 'Sede principal', 'Iron Body Neiva está ubicado en Cl. 24 Sur #33-53, Neiva, Huila.'],
            ['schedule', 'schedule.opening_hours', 'Horario general de atencion', 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados y domingos de 8:00 a. m. a 2:00 p. m.'],
            ['tone', 'tone.brand_personality', 'Personalidad de Iron Body', 'Iron Body habla con seguridad y orgullo de lo que es: un gimnasio serio, con equipo profesional y ambiente de gente que va a por sus objetivos.'],
            ['brand_copy', 'brand.promise', 'Promesa de marca', 'Iron Body lleva tu experiencia de entrenamiento a otro nivel.'],
        ] as [$cat, $key, $title, $content]) {
            MarketingKnowledgeItem::create([
                'category' => $cat, 'key' => $key, 'title' => $title, 'content' => $content, 'is_active' => true,
                'origin' => MarketingKnowledgeItem::ORIGIN_SEEDER,
            ]);
        }
        foreach (['IRON PARTY' => 'friday', 'IRON POWERFLOW' => 'monday', 'IRON STRENGTH' => 'wednesday'] as $nombre => $dia) {
            MyClass::create(['name' => $nombre, 'type' => 'Funcional', 'day_of_week' => $dia, 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'status' => 'active', 'max_capacity' => 20]);
        }
        MyClass::create(['name' => 'IRON YOGA', 'type' => 'Funcional', 'day_of_week' => 'tuesday', 'start_time' => '19:00:00', 'end_time' => '20:00:00', 'status' => 'inactive', 'max_capacity' => 12]);

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
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
    private function turno(string $texto, string $wamid, array $proposal, array $critic = []): TestResponse
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
            'critic' => array_merge(['verdict' => 'pass', 'attempt' => 1, 'score' => 0.95, 'issues' => [], 'hard_fail' => null], $critic),
        ], $this->h());
    }

    /** El critic tumbando el borrador: sale el texto de respaldo de Laravel. */
    private const CRITIC_CAIDO = ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3];

    private function ultimo(): string
    {
        return (string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body;
    }

    private function memoria(): array
    {
        return (array) $this->conversation->fresh()->memory;
    }

    // ── CASO 1 · «Hola buenos días» ──────────────────────────────────────────

    public function test_caso_1_un_saludo_solo_recibe_saludo_bienvenida_y_apertura_sin_menu_ni_objetivo(): void
    {
        // Camino de respaldo: el critic tumba el borrador y sale el texto de Laravel.
        $this->turno('Hola buenos días', 'exp.1a', ['intent' => SalesIntents::GREETING, 'next_state' => P::RAPPORT, 'reply_draft' => 'Hola.'], self::CRITIC_CAIDO)->assertOk();
        $t = mb_strtolower($this->ultimo());

        $this->assertStringContainsString('bienvenid', $t, 'sin bienvenida');
        $this->assertStringContainsString('?', $t, 'sin apertura');
        foreach (['objetivo', 'plan', 'inscri', 'puedes preguntarme', 'planes, horarios'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $t, "el saludo dice «{$prohibido}»");
        }
    }

    public function test_caso_1_un_buen_saludo_del_modelo_sale_intacto(): void
    {
        $borrador = 'Hola, muy buenos días. Bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos ayudar hoy?';
        $this->turno('Hola buenos días', 'exp.1b', ['intent' => SalesIntents::GREETING, 'next_state' => P::RAPPORT, 'reply_draft' => $borrador])->assertOk();

        $this->assertSame($borrador, $this->ultimo(), 'Laravel reescribió un saludo correcto');
        $this->assertNotNull($this->memoria()['greeted'] ?? null);
    }

    // ── CASO 2 · «Quiero información del gimnasio» ───────────────────────────

    public function test_caso_2_pedir_informacion_sin_un_solo_hecho_recibe_el_gimnasio_real_y_no_un_menu(): void
    {
        $this->turno('Quiero información del gimnasio', 'exp.2a', [
            'reply_draft' => 'Claro, con gusto. Para darte la mejor información, ¿me cuentas qué objetivo tienes?',
        ])->assertOk();
        $t = $this->ultimo();

        $this->assertStringContainsString(self::IDENTIDAD, $t, 'sin presentación');
        $this->assertStringContainsString('Cl. 24 Sur', $t, 'sin la ubicación real');
        $this->assertStringContainsString('5:00 a. m.', $t, 'sin el horario real');
        $this->assertStringContainsString('IRON POWERFLOW', $t, 'sin las clases reales');
        $this->assertStringContainsString('?', $t, 'sin pregunta abierta');
        $bajo = mb_strtolower($t);
        foreach (['objetivo tienes', 'puedes preguntarme', 'planes, horarios o cómo empezar', 'plan mensual', 'total access', '$'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $bajo, "la información general dice «{$prohibido}»");
        }
    }

    public function test_caso_2_una_presentacion_con_hechos_del_crm_sale_intacta(): void
    {
        $borrador = 'Claro, te cuento. '.self::IDENTIDAD.' En Iron Body entrenas con acompañamiento y un espacio preparado para avanzar en tu objetivo. '
            .'Estamos en Cl. 24 Sur #33-53, Neiva, y abrimos de lunes a viernes de 5:00 a. m. a 10:00 p. m. y fines de semana de 8:00 a. m. a 2:00 p. m. '
            .'Tenemos clases funcionales como IRON PARTY, IRON POWERFLOW e IRON STRENGTH. ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?';
        $this->turno('Quiero información del gimnasio', 'exp.2b', ['reply_draft' => $borrador])->assertOk();

        $this->assertSame($borrador, $this->ultimo(), 'Laravel reescribió una presentación correcta');
    }

    /** El respaldo de horario dice el horario real de la ficha, no que no lo tiene. */
    public function test_caso_2_el_respaldo_de_horario_dice_el_horario_real(): void
    {
        $this->turno('¿Hasta qué hora abren?', 'exp.2c', [
            'intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => 'Abrimos en el horario habitual.',
        ], self::CRITIC_CAIDO)->assertOk();
        $t = $this->ultimo();

        $this->assertStringContainsString('5:00 a. m.', $t, 'el respaldo no dijo el horario que la ficha sí tiene');
        $this->assertStringContainsString('?', $t);
        $this->assertStringNotContainsString('no tengo un horario', mb_strtolower($t));
    }

    // ── CASO 3 · «Busco ganar masa muscular» ─────────────────────────────────

    public function test_caso_3_el_descubrimiento_valida_aporta_y_pregunta_y_la_recomendacion_se_justifica(): void
    {
        $descubrimiento = 'Perfecto, ganar masa muscular es un objetivo claro y con constancia se avanza rápido. ¿Normalmente prefieres entrenar en la mañana, tarde o noche?';
        $this->turno('Busco ganar masa muscular', 'exp.3a', [
            'intent' => SalesIntents::GOAL_MUSCLE_GAIN, 'next_state' => P::GOAL_DISCOVERY, 'reply_draft' => $descubrimiento,
        ])->assertOk();
        $this->assertSame($descubrimiento, $this->ultimo(), 'Laravel reescribió un descubrimiento correcto');

        // La recomendación se explica con lo que la persona dijo y conserva la
        // marca que la memoria comercial reconoce («lo que mejor te encaja»).
        $this->turno('tengo seis días disponibles, en la noche', 'exp.3b', [
            'intent' => SalesIntents::GOAL_MUSCLE_GAIN, 'next_state' => P::RECOMMENDATION,
            'recommended_plan_id' => $this->insignia->id,
            'reply_draft' => 'Por lo que me cuentas, buscas ganar masa y tienes buena disponibilidad para entrenar; lo que mejor te encaja es {{PLAN_NAME}}: acceso completo y acompañamiento para aprovechar esos días. ¿Te cuento cómo empezar?',
        ])->assertOk();
        $t = $this->ultimo();

        $this->assertStringContainsString('Por lo que me cuentas', $t, 'la recomendación perdió su justificación');
        $this->assertStringContainsString('TOTAL ACCESS - ELITE', $t, 'la recomendación salió sin el plan');
        $this->assertStringNotContainsString('$', $t, 'nadie pidió el precio');
        $this->assertSame([$this->insignia->id], $this->memoria()['last_recommendation']['plan_ids'] ?? null, 'la memoria no registró la recomendación');
    }

    // ── CASO 4 · «¿Qué clases tienen?» ───────────────────────────────────────

    public function test_caso_4_solo_salen_las_clases_que_existen(): void
    {
        $this->turno('¿Qué clases tienen?', 'exp.4a', [
            'reply_draft' => 'Tenemos clases de yoga, pilates y spinning. También IRON POWERFLOW e IRON STRENGTH para todos los niveles. ¿Cuál te llama más?',
        ])->assertOk();
        $t = $this->ultimo();

        foreach (['yoga', 'pilates', 'spinning'] as $inventada) {
            $this->assertStringNotContainsString($inventada, mb_strtolower($t), "salió «{$inventada}», que no existe");
        }
        $this->assertStringContainsString('IRON POWERFLOW', $t);
        $this->assertStringContainsString('IRON STRENGTH', $t);
        $this->assertSame(['Tenemos clases de yoga, pilates y spinning.'], MarketingAiAction::latest('id')->first()->metadata['invented_service_dropped'] ?? null);
    }

    public function test_caso_4_decir_que_no_tenemos_yoga_es_honesto_y_sale_intacto(): void
    {
        $borrador = 'No tenemos yoga por ahora; nuestras clases son IRON PARTY, IRON POWERFLOW e IRON STRENGTH. ¿Cuál te llama más?';
        $this->turno('¿Tienen yoga?', 'exp.4b', ['reply_draft' => $borrador])->assertOk();

        $this->assertSame($borrador, $this->ultimo(), 'Laravel reescribió una negación honesta');
    }

    public function test_caso_4_un_borrador_que_era_solo_una_clase_inventada_sale_como_la_verdad(): void
    {
        $this->turno('¿Tienen yoga?', 'exp.4c', ['reply_draft' => 'Claro, tenemos clases de yoga los martes.'])->assertOk();
        $t = $this->ultimo();

        $this->assertStringNotContainsString('yoga', mb_strtolower($t));
        // En el orden del calendario, que es como las entrega el CRM.
        $this->assertStringContainsString('IRON POWERFLOW, IRON STRENGTH e IRON PARTY', $t, 'sin las clases reales');
        $this->assertStringNotContainsString('IRON YOGA', $t, 'la clase apagada no cuenta');
        $this->assertStringContainsString('no la tengo confirmada', $t);
    }

    // ── CASO 5 · «Me interesa inscribirme» ───────────────────────────────────

    public function test_caso_5_el_interes_recibe_asesoria_y_no_un_pago_inmediato(): void
    {
        $this->conversation->update(['commercial_phase' => P::RECOMMENDATION]);
        $borrador = 'Excelente, me alegra que te interese. Antes de avanzar, confirmemos que el {{PLAN_NAME}} se ajusta a lo que buscas y te cuento cómo empezar.';

        $r = $this->turno('Me interesa inscribirme', 'exp.5a', [
            'intent' => SalesIntents::HIGH_INTENT_CLOSE, 'next_state' => P::BUYING_SIGNAL,
            'recommended_plan_id' => $this->insignia->id, 'reply_draft' => $borrador,
        ])->assertOk();
        $t = $this->ultimo();

        $this->assertStringContainsString('confirmemos que el TOTAL ACCESS - ELITE se ajusta a lo que buscas', $t, 'se perdió la asesoría');
        $this->assertStringNotContainsString('http', mb_strtolower($t), 'salió un enlace');
        $this->assertStringNotContainsString('realiza el pago', mb_strtolower($t));
        $this->assertSame([], $r->json('applied.tools_executed') ?? [], 'se ejecutó una herramienta que nadie pidió');
    }
}
