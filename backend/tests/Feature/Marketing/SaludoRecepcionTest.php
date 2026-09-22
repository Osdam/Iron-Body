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
use App\Services\Marketing\SalesObjectionResponderService;
use App\Services\Marketing\Ultron\ConversationMemory as CM;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PRIMERO RECIBE. DESPUÉS ENTIENDE. DESPUÉS GUÍA. DESPUÉS VENDE.
 *
 * Los cuatro turnos obligatorios de la corrección del saludo, por el
 * recorrido real (decide firma, commit ejecuta). El «modelo» es el borrador
 * que va en `reply_draft`; lo que se mide es lo determinista: la política,
 * las pistas y la red de respaldo.
 */
class SaludoRecepcionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const IDENTIDAD = 'Iron Body Neiva es un centro de acondicionamiento físico en Neiva.';

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private Plan $mensual;

    private Plan $insignia;

    protected function setUp(): void
    {
        parent::setUp();
        // Un martes a las nueve de la mañana en Neiva: «buenos dias».
        Carbon::setTestNow(Carbon::parse('2026-09-22 14:00:00', 'UTC'));

        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::fake();

        $this->mensual = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 3, 'benefits' => json_encode(['Acceso ilimitado al gimnasio'])]);
        $this->insignia = Plan::create(['name' => 'TOTAL ACCESS - ELITE', 'price' => 180000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 108, 'is_recommended' => true, 'benefits' => json_encode(['Acceso completo', 'Acompañamiento'])]);

        foreach ([
            ['business_identity', 'identity.brand', 'Quiénes somos', self::IDENTIDAD],
            ['location', 'location.city', 'Sede principal', 'Iron Body Neiva está ubicado en Cl. 24 Sur #33-53, Neiva, Huila.'],
            ['schedule', 'schedule.opening_hours', 'Horario general de atencion', 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados y domingos de 8:00 a. m. a 2:00 p. m.'],
        ] as [$cat, $key, $title, $content]) {
            MarketingKnowledgeItem::create(['category' => $cat, 'key' => $key, 'title' => $title, 'content' => $content, 'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_SEEDER]);
        }
        foreach (['IRON PARTY' => 'friday', 'IRON POWERFLOW' => 'monday', 'IRON STRENGTH' => 'wednesday'] as $nombre => $dia) {
            MyClass::create(['name' => $nombre, 'type' => 'Funcional', 'day_of_week' => $dia, 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'status' => 'active', 'max_capacity' => 20]);
        }

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
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

    /**
     * La memoria comercial que contaminaba el saludo: la persona ya se había
     * comprometido con un plan en esta conversación (READY_TO_BUY, hot lead).
     */
    private function conMemoriaComercial(): void
    {
        $this->conversation->forceFill(['memory' => array_merge(CM::blank(), [
            'commercial_commitment' => ['plan_id' => $this->insignia->id, 'at' => '2026-09-22T13:00:00+00:00'],
            'plans_discussed' => [$this->insignia->id],
        ])])->save();
    }

    private function decide(string $texto, string $wamid): array
    {
        $m = MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $texto, 'meta_message_id' => $wamid, 'status' => 'received',
        ]);
        $r = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->h())->assertOk();

        return ['message' => $m, 'json' => $r->json()];
    }

    private function commit(MarketingMessage $m, array $proposal, array $critic = []): TestResponse
    {
        $token = (string) $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->h())->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id.'.'.count($this->salientes()),
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => array_merge([
                'next_state' => P::RAPPORT, 'intent' => SalesIntents::GREETING, 'confidence' => 0.9,
                'tools_requested' => [], 'recommended_plan_id' => null,
            ], $proposal),
            'critic' => array_merge(['verdict' => 'pass', 'attempt' => 1, 'score' => 0.95, 'issues' => [], 'hard_fail' => null], $critic),
        ], $this->h());
    }

    private const CRITIC_CAIDO = ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3];

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

    /** Recepción: saludo de franja, bienvenida (la primera vez) y apertura; nada comercial. */
    private function esBienvenida(string $t, bool $primeraVez = true): void
    {
        $bajo = mb_strtolower($t);
        $this->assertStringContainsString('buenos días', $bajo, 'sin el saludo de la franja, con su tilde');
        if ($primeraVez) {
            $this->assertStringContainsString('bienvenido', $bajo, 'sin bienvenida');
            $this->assertStringContainsString('iron body', $bajo);
        } else {
            $this->assertStringContainsString('hola de nuevo', $bajo, 'a quien ya saludamos se le recibe de nuevo, sin repetir la bienvenida');
        }
        $this->assertStringContainsString('?', $t, 'sin apertura');
        foreach (['objetivo', 'plan', 'precio', '$', 'pago', 'pagar', 'inscri', 'total access', 'app', 'puedes preguntarme', 'planes, horarios', 'dime qué necesitas'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $bajo, "la bienvenida dice «{$prohibido}»");
        }
    }

    // ── TEST 1 · lead con memoria comercial, «Hola buenos días» ─────────────

    public function test_1_un_saludo_puro_con_memoria_comercial_entra_en_recepcion(): void
    {
        $this->conMemoriaComercial();
        ['message' => $m, 'json' => $j] = $this->decide('Hola buenos días', 'w.rec.1');

        $p = $j['context']['commercial_turn_policy'];
        $h = $j['context']['strategy_hints'];
        $c = $j['context']['customer'];

        // La memoria del lead sigue siendo un hecho…
        $this->assertSame('READY_TO_BUY', $c['customer_lifecycle'], 'el fixture no reproduce la memoria comercial');
        // …pero no decide este turno.
        $this->assertSame(SalesIntents::GREETING, $p['intent']);
        $this->assertTrue($p['greet_required']);
        $this->assertTrue($p['welcome_required']);
        $this->assertTrue($p['reception_mode']);
        $this->assertFalse($p['answer_first']);
        $this->assertNull($p['required_plan_id']);
        $this->assertNull($p['preferred_commercial_plan']);
        foreach (['discovery', 'plan_recommendation', 'payment', 'checkout'] as $accion) {
            $this->assertContains($accion, $p['forbidden_actions']);
        }
        $this->assertTrue($h['greeting_only']);
        $this->assertFalse($h['hot_lead_fast_path']);
        $this->assertFalse($h['should_recommend_now']);
        $this->assertSame('consultative', $h['lifecycle_mode']);
        $this->assertSame(0, count($h['may_ask']));

        // El borrador que salió en producción (vender ante el saludo) se descarta y sale la bienvenida.
        $r = $this->commit($m, [
            'intent' => SalesIntents::GREETING, 'recommended_plan_id' => $this->insignia->id,
            'reply_draft' => 'Buenas tardes, te recuerdo que puedes descargar la app Iron Body Workout para pagar el {{PLAN_NAME}} y empezar cuando quieras.',
        ])->assertOk();
        $this->esBienvenida($this->ultimo());
        $this->assertContains('policy_greeting_sells', MarketingAiAction::latest('id')->first()->metadata['policy_violations'] ?? []);
        $this->assertNull($this->conversation->fresh()->memory['last_recommendation'] ?? null, 'un saludo no puede dejar una recomendación en memoria');
    }

    /**
     * La etiqueta del modelo no manda: si llama «high_intent_close» o
     * «general_info» a un «hola», el commit recalcula el turno igual que
     * decide y la recepción sigue en pie. La revisión lo midió saliendo con
     * plan, precio y pregunta de objetivo.
     */
    public function test_1d_la_etiqueta_del_modelo_no_esquiva_la_recepcion(): void
    {
        $this->conMemoriaComercial();

        $primeraVez = true;
        foreach ([
            'w.rec.1e' => [SalesIntents::HIGH_INTENT_CLOSE, 'Hola, buenos dias. Como ya veniamos hablando, el {{PLAN_NAME}} cuesta {{PLAN_PRICE}} y es el ideal para ti. ¿Cual es tu objetivo principal?'],
            'w.rec.1f' => [SalesIntents::GENERAL_INFO, 'Hola. Te recomiendo el TOTAL ACCESS - ELITE por {{PLAN_PRICE}}. ¿Cual es tu objetivo?'],
        ] as $wamid => [$etiqueta, $borrador]) {
            ['message' => $m] = $this->decide('Hola buenos dias', $wamid);
            $this->commit($m, [
                'intent' => $etiqueta, 'next_state' => P::RECOMMENDATION, 'recommended_plan_id' => $this->insignia->id, 'reply_draft' => $borrador,
            ])->assertOk();

            // La segunda vez ya saludamos: se recibe «de nuevo», sin repetir la bienvenida.
            $this->esBienvenida($this->ultimo(), $primeraVez);
            $primeraVez = false;
            $meta = MarketingAiAction::latest('id')->first()->metadata;
            $this->assertSame(SalesIntents::GREETING, $meta['intent'] ?? null, "la etiqueta «{$etiqueta}» sobrevivió a un saludo puro");
            $this->assertContains('policy_greeting_sells', $meta['policy_violations'] ?? []);
        }
        $this->assertStringNotContainsString('$', implode(' ', $this->salientes()), 'salió un precio en un saludo');
    }

    public function test_1b_con_el_critic_caido_el_saludo_recibe_bienvenida_y_no_un_menu(): void
    {
        $this->conMemoriaComercial();
        ['message' => $m] = $this->decide('Hola buenos días', 'w.rec.1b');

        $this->commit($m, ['reply_draft' => '¿Qué objetivo tienes en mente?'], self::CRITIC_CAIDO)->assertOk();
        $this->esBienvenida($this->ultimo());

        // Y la segunda vez que el critic cae tampoco es un menú: otra variante de bienvenida.
        ['message' => $m2] = $this->decide('hola', 'w.rec.1c');
        $this->commit($m2, ['reply_draft' => '¿Qué objetivo tienes en mente?'], self::CRITIC_CAIDO)->assertOk();
        $t = mb_strtolower($this->ultimo());
        $this->assertStringContainsString('?', $t);
        foreach (['puedes preguntarme', 'planes, horarios', 'dime qué necesitas', 'objetivo', 'plan', 'pago'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $t, "el respaldo repetido dice «{$prohibido}»");
        }
        $this->assertNotSame($this->salientes()[0], $this->salientes()[1], 'repitió la misma bienvenida palabra por palabra');
    }

    public function test_1c_una_buena_bienvenida_del_modelo_sale_intacta(): void
    {
        $this->conMemoriaComercial();
        ['message' => $m] = $this->decide('Hola buenos días', 'w.rec.1d');
        $borrador = 'Hola, muy buenos días. Bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos ayudar hoy?';

        $this->commit($m, ['reply_draft' => $borrador])->assertOk();

        $this->assertSame($borrador, $this->ultimo(), 'Laravel reescribió una bienvenida correcta');
        $this->assertNotNull($this->conversation->fresh()->memory['greeted'] ?? null);
    }

    // ── TEST 2 · «Hola buenos días quiero información del gimnasio» ────────

    public function test_2_un_saludo_con_peticion_de_informacion_recibe_informacion_real(): void
    {
        ['message' => $m, 'json' => $j] = $this->decide('Hola buenos días quiero información del gimnasio', 'w.rec.2');

        $this->assertFalse($j['context']['commercial_turn_policy']['reception_mode'], 'pedir información no es un saludo puro');
        $this->assertFalse($j['context']['strategy_hints']['greeting_only']);

        $this->commit($m, [
            'intent' => SalesIntents::GENERAL_INFO, 'next_state' => P::DISCOVERY,
            'reply_draft' => 'Hola, muy buenos días. Para orientarte mejor, ¿cuál es tu objetivo?',
        ])->assertOk();
        $t = $this->ultimo();

        $this->assertStringContainsString(self::IDENTIDAD, $t, 'sin presentación');
        $this->assertStringContainsString('Cl. 24 Sur', $t, 'sin la ubicación real');
        $this->assertStringContainsString('5:00 a. m.', $t, 'sin el horario real');
        $this->assertStringContainsString('IRON POWERFLOW', $t, 'sin las clases reales');
        $this->assertStringContainsString('?', $t);
        $this->assertStringNotContainsString('cuál es tu objetivo', mb_strtolower($t));
    }

    // ── TEST 3 · «Quiero inscribirme» conserva la prioridad comercial ──────

    public function test_3_quiero_inscribirme_conserva_la_prioridad_comercial(): void
    {
        ['json' => $j] = $this->decide('Quiero inscribirme', 'w.rec.3');
        $p = $j['context']['commercial_turn_policy'];

        $this->assertFalse($p['reception_mode']);
        $this->assertFalse($j['context']['strategy_hints']['greeting_only']);
        $this->assertSame($this->insignia->id, $p['preferred_commercial_plan'], 'la prioridad comercial se perdió fuera del saludo');
        $this->assertContains('recommend_plan', $p['allowed_next_actions']);
    }

    // ── TEST 4 · «Me parece caro» conserva la objeción ─────────────────────

    public function test_4_me_parece_caro_conserva_la_objecion_comercial(): void
    {
        ['message' => $m, 'json' => $j] = $this->decide('Me parece caro', 'w.rec.4');

        $this->assertFalse($j['context']['commercial_turn_policy']['reception_mode']);
        $this->assertFalse($j['context']['strategy_hints']['greeting_only']);

        // Con el critic caído, el respaldo es el de la objeción, no una bienvenida ni un menú.
        $this->commit($m, [
            'intent' => SalesIntents::PRICE_OBJECTION, 'next_state' => P::OBJECTION_HANDLING,
            'reply_draft' => 'Es carísimo pero vale la pena, ¡no lo pienses más!',
        ], self::CRITIC_CAIDO)->assertOk();
        $t = $this->ultimo();

        $this->assertSame((new SalesObjectionResponderService)->reply(SalesIntents::PRICE_OBJECTION), $t, 'la objeción no recibió su respuesta curada');
        foreach (['bienvenido', 'puedes preguntarme', 'planes, horarios'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, mb_strtolower($t));
        }
    }
}
