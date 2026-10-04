<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\MyClass;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\ConversationMemory as CM;
use App\Services\Marketing\Ultron\LeadProfileService;
use App\Services\Marketing\Ultron\UltronCommitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ¿UN ASESOR COMERCIAL EXPERTO RESPONDERÍA ASÍ?
 *
 * Los seis casos obligatorios del refinamiento final, por el recorrido real
 * (decide firma, commit ejecuta), más los dos fallos físicos que lo
 * motivaron: la visita que nunca se registró y la pregunta de memoria que se
 * contestó vendiendo. Lo que se mide es lo determinista: la ficha permanente
 * del lead, el estado temporal que NO se hereda, la política de memoria, la
 * pregunta adaptativa y la red de respaldo. El «modelo» es el borrador que
 * iría en `reply_draft`.
 */
class AsesorSeniorTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const CRITIC_CAIDO = ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3];

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private Plan $mensual;

    private Plan $insignia;

    protected function setUp(): void
    {
        parent::setUp();
        // Martes 22 de septiembre, nueve de la mañana en Neiva.
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
            ['business_identity', 'identity.brand', 'Quiénes somos', 'Iron Body Neiva es un centro de acondicionamiento físico en Neiva.', []],
            // La sede con el teléfono en la misma ficha: en producción esos dígitos bloqueaban el respaldo.
            ['location', 'location.city', 'Sede principal', 'Iron Body Neiva está ubicado en Cl. 24 Sur #33-53, Neiva, Huila. Teléfono de contacto: 314 3455483.', []],
            ['schedule', 'schedule.opening_hours', 'Horario general de atencion', 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados y domingos de 8:00 a. m. a 2:00 p. m.', ['windows' => [
                'lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'],
                'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'],
                'sabado' => ['08:00', '14:00'], 'domingo' => ['08:00', '14:00'],
            ]]],
        ] as [$cat, $key, $title, $content, $meta]) {
            MarketingKnowledgeItem::create(['category' => $cat, 'key' => $key, 'title' => $title, 'content' => $content, 'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL, 'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED, 'metadata' => $meta ?: null]);
        }
        foreach (['IRON PARTY' => 'friday', 'IRON POWERFLOW' => 'monday', 'IRON STRENGTH' => 'wednesday'] as $nombre => $dia) {
            MyClass::create(['name' => $nombre, 'type' => 'Funcional', 'day_of_week' => $dia, 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'status' => 'active', 'max_capacity' => 20]);
        }

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = $this->nuevaConversacion();
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

    /** Una conversación nueva del mismo lead: memoria en blanco, como en la vida real. */
    private function nuevaConversacion(): MarketingConversation
    {
        return MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::NEW_LEAD,
        ]);
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

        $r = $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => array_merge([
                'next_state' => P::DISCOVERY, 'intent' => SalesIntents::GENERAL_INFO, 'confidence' => 0.9,
                'tools_requested' => [], 'recommended_plan_id' => null,
            ], $proposal),
            'critic' => array_merge(['verdict' => 'pass', 'attempt' => 1, 'score' => 0.95, 'issues' => [], 'hard_fail' => null], $critic),
        ], $this->h());
        if ($r->status() !== 200) {
            $this->fail('commit devolvió '.$r->status().': '.$r->getContent());
        }

        return $r;
    }

    /** El commit sin exigir 200: para los turnos que el backend TIENE que rechazar. */
    private function commitCrudo(MarketingMessage $m, array $proposal): TestResponse
    {
        $token = (string) $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->h())->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => array_merge([
                'next_state' => P::DISCOVERY, 'intent' => SalesIntents::GENERAL_INFO, 'confidence' => 0.9,
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

    private function meta(): array
    {
        return MarketingAiAction::latest('id')->first()->metadata ?? [];
    }

    private function ficha(): array
    {
        return (new LeadProfileService)->profileOf($this->lead->fresh());
    }

    /** @return string[] */
    private function significados(): array
    {
        return array_map(fn ($e) => $e['meaning'], $this->ficha()['episodes']);
    }

    private function sinVenta(string $texto, string $donde): void
    {
        $bajo = mb_strtolower($texto);
        foreach (['$', 'precio', 'elite', 'total access', 'plan mensual', 'pagar', 'pago', 'inscri', 'objetivo'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $bajo, "{$donde}: dice «{$prohibido}»");
        }
    }

    // ── TEST 1 · lead antiguo + conversación nueva + saludo ──────────────────

    /**
     * Conversación 1: «quiero recomposición corporal». Conversación 2: «hola».
     * La ficha recuerda el objetivo (no se pierde información comercial) y la
     * recepción no lo da por hecho (no se hereda un estado temporal): se
     * recibe de nuevo, sin vender ni preguntar el objetivo.
     */
    public function test_1_lead_antiguo_en_conversacion_nueva_recibe_bienvenida_natural_sin_venta(): void
    {
        ['message' => $m] = $this->decide('Hola, quiero recomposición corporal', 'w.as.1a');
        $this->commit($m, [
            'intent' => SalesIntents::GOAL_RECOMPOSITION, 'recommended_plan_id' => $this->insignia->id, 'next_state' => P::RECOMMENDATION,
            'reply_draft' => 'Perfecto, para recomposición corporal el {{PLAN_NAME}} es el indicado: acceso completo y acompañamiento, por {{PLAN_PRICE}} al mes. ¿Sería tu primera vez entrenando o ya tienes experiencia?',
        ]);

        // La memoria PERMANENTE aprendió lo comercial.
        $this->assertSame('recomposición corporal', $this->lead->fresh()->objective, 'marketing_leads.objective nunca se escribía');
        $this->assertSame('recomposición corporal', $this->ficha()['objective']);

        // Conversación nueva: estado temporal en blanco.
        $this->conversation = $this->nuevaConversacion();
        ['message' => $m2, 'json' => $j] = $this->decide('hola', 'w.as.1b');
        $c = $j['context']['customer'];
        $p = $j['context']['commercial_turn_policy'];
        $h = $j['context']['strategy_hints'];

        $this->assertSame('recomposición corporal', $c['known']['objective'], 'la conversación nueva perdió el objetivo');
        $this->assertTrue($c['known']['returning_lead']);
        $this->assertNotContains('objective', $c['unknown'], 'lo que ya se sabe no se vuelve a preguntar');
        $this->assertTrue($p['reception_mode']);
        $this->assertFalse($p['memory_mode']);
        $this->assertNull($p['active_goal'] ?? null, 'heredó un objetivo activo de otra conversación');
        $this->assertTrue($h['greeting_only']);
        $this->assertNull($h['suggested_question'], 'en recepción no hay pregunta de descubrimiento');
        $this->assertFalse($h['should_recommend_now'], 'el saludo no es el momento de recomendar aunque se sepa el objetivo');

        // El borrador que da por hecho el objetivo y vende se descarta; sale la recepción «de nuevo».
        $this->commit($m2, [
            'intent' => SalesIntents::GREETING, 'recommended_plan_id' => $this->insignia->id, 'next_state' => P::RAPPORT,
            'reply_draft' => 'Hola de nuevo. Como buscas recomposición corporal, el {{PLAN_NAME}} por {{PLAN_PRICE}} es tu plan. ¿Lo activamos?',
        ]);
        $t = $this->ultimo();
        $this->assertStringContainsString('hola de nuevo', mb_strtolower($t), 'a quien ya habló con nosotros se le recibe de nuevo, no se le estrena');
        $this->assertStringNotContainsString('bienvenido', mb_strtolower($t));
        $this->assertStringNotContainsString('recomposici', mb_strtolower($t), 'la recepción dio por hecho qué quiere hoy');
        $this->sinVenta($t, 'la recepción de un lead antiguo');
        $this->assertContains('policy_greeting_sells', $this->meta()['policy_violations'] ?? []);
        $this->assertNull($this->conversation->fresh()->memory['last_recommendation'] ?? null);
    }

    // ── TEST 2 · lead antiguo pregunta por algo hablado: recuperación contextual ──

    /**
     * Conversación 1: pide la visita «mañana miércoles a las 2 pm» y el
     * estratega manda la fecha y la hora en null (lo que pasó en producción):
     * la fecha y la hora se leen del texto y la visita queda solicitada.
     * Conversación 2: «me recuerdas a qué hora era mi visita?» se resuelve con
     * el compromiso real, se confirma su estado y NO se vende.
     */
    public function test_2_lead_antiguo_pregunta_por_su_visita_y_se_le_recupera_el_compromiso(): void
    {
        ['message' => $m] = $this->decide('quiero conocer las instalaciones mañana miércoles a las 2 pm', 'w.as.2a');
        $this->commit($m, [
            'intent' => SalesIntents::GENERAL_INFO, 'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Perfecto, dejo solicitada tu visita para mañana miércoles a las 2:00 p. m.; el equipo la confirma.',
        ]);

        $a = MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->where('marketing_lead_id', $this->lead->id)->latest('id')->first();
        $this->assertNotNull($a, 'la visita no se registró: la fecha y la hora iban en null y nadie las leyó del texto');
        $this->assertSame(MarketingAppointment::STATUS_REQUESTED, $a->status);
        $this->assertSame('2026-09-23', data_get($a->metadata, 'requested_date'));
        $this->assertSame('14:00', data_get($a->metadata, 'requested_time'));
        $this->assertSame('requested', data_get($this->conversation->fresh()->memory, 'courtesy_request.status'));
        $this->assertContains('Solicitó una visita de cortesía para el miércoles 23 de septiembre a las 14:00; el equipo la confirma.', $this->significados());
        $this->assertContains('quiere conocer las instalaciones antes de decidir', $this->ficha()['preferences']);

        // Conversación nueva, semanas después no: al día siguiente por la mañana.
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        $this->conversation = $this->nuevaConversacion();
        ['message' => $m2, 'json' => $j] = $this->decide('hola, me recuerdas a qué hora era mi visita?', 'w.as.2b');
        $c = $j['context']['customer'];
        $p = $j['context']['commercial_turn_policy'];

        $this->assertTrue($p['memory_mode'], 'una pregunta de memoria no entró en modo memoria');
        $this->assertFalse($p['reception_mode'], 'un «hola» seguido de una pregunta no es un saludo puro');
        $this->assertContains('plan_recommendation', $p['forbidden_actions']);
        $this->assertSame('courtesy_visit', $c['commitments'][0]['kind'] ?? null, 'el compromiso de otra conversación no llegó al contexto');
        $this->assertSame('miércoles 23 de septiembre a las 14:00', $c['commitments'][0]['human']);
        $this->assertSame('2026-09-23', $c['commitments'][0]['date']);
        $this->assertNotEmpty($c['history'], 'la historia con significado no llegó al contexto');

        // Lo que salió en producción: la hora, «tienes agendada», y el plan Élite.
        $this->commit($m2, [
            'intent' => SalesIntents::SCHEDULE_QUESTION, 'recommended_plan_id' => $this->insignia->id, 'next_state' => P::RECOMMENDATION,
            'reply_draft' => 'Tienes agendada tu visita hoy a las 2 pm. Y aprovecho: el {{PLAN_NAME}} por {{PLAN_PRICE}} es el que más te conviene, ¿te cuento?',
        ]);
        $t = $this->ultimo();
        $bajo = mb_strtolower($t);
        $this->assertStringContainsString('14:00', $t, 'no resolvió la hora de la visita');
        $this->assertStringContainsString('miércoles 23 de septiembre', $bajo);
        $this->assertStringContainsString('solicitada', $bajo, 'la verdad del estado: solicitada, no agendada');
        $this->assertStringNotContainsString('agendada', $bajo);
        $this->sinVenta($t, 'la respuesta a una pregunta de memoria');
        $this->assertContains('policy_memory_turn_sells', $this->meta()['policy_violations'] ?? []);
        $this->assertNull($this->conversation->fresh()->memory['last_recommendation'] ?? null, 'una pregunta de memoria dejó una recomendación en memoria');

        // Y un buen borrador —resolver, confirmar, esperar— sale intacto.
        $this->conversation = $this->nuevaConversacion();
        ['message' => $m3] = $this->decide('me acuerdas cuándo era mi cita?', 'w.as.2c');
        $bueno = 'Claro. Tu visita quedó solicitada para el miércoles 23 de septiembre a las 14:00 y el equipo la confirma contigo. Si necesitas moverla, me avisas.';
        $this->commit($m3, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => $bueno]);
        $this->assertSame($bueno, $this->ultimo(), 'un buen borrador de memoria no sale intacto');
        $this->assertSame([], $this->meta()['policy_violations'] ?? []);
    }

    /**
     * Cuando el equipo ya EJECUTÓ la solicitud desde el panel, la acción pasa a
     * `executed` y nace la cita real: a esa persona no se le puede decir «no
     * tengo nada registrado». La verdad es que está confirmada, y se dice.
     */
    public function test_2c_la_cita_ya_confirmada_por_el_equipo_se_recuerda_como_confirmada(): void
    {
        ['message' => $m] = $this->decide('quiero conocer el gimnasio mañana a las 2 pm', 'w.as.2e');
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => '2026-09-23', 'courtesy_time' => '14:00',
            'reply_draft' => 'Listo, dejo solicitada tu visita para mañana a las 2:00 p. m.; el equipo la confirma.',
        ]);
        $a = MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->where('marketing_lead_id', $this->lead->id)->latest('id')->first();
        $this->assertNotNull($a);

        // El equipo la confirma desde la agenda: la MISMA cita pasa a confirmada.
        $a->forceFill(['status' => MarketingAppointment::STATUS_SCHEDULED])->save();
        $this->assertSame('2026-09-23 19:00:00', $a->scheduled_at->copy()->utc()->toDateTimeString(), 'la hora de Neiva no se guardó en UTC');

        $this->conversation = $this->nuevaConversacion();
        ['message' => $m2, 'json' => $j] = $this->decide('me recuerdas a qué hora era mi visita?', 'w.as.2f');
        $c = $j['context']['customer'];
        $this->assertSame('confirmed', $c['commitments'][0]['status'] ?? null, 'la cita confirmada no llegó como compromiso');
        $this->assertSame('miércoles 23 de septiembre a las 14:00', $c['commitments'][0]['human']);

        $this->commit($m2, [
            'intent' => SalesIntents::SCHEDULE_QUESTION, 'recommended_plan_id' => $this->insignia->id, 'next_state' => P::RECOMMENDATION,
            'reply_draft' => 'No tengo registrada ninguna visita tuya. Mientras tanto, el {{PLAN_NAME}} por {{PLAN_PRICE}} te conviene.',
        ]);
        $t = $this->ultimo();
        $bajo = mb_strtolower($t);
        $this->assertStringContainsString('confirmó tu visita', $bajo, 'a quien tiene la cita confirmada se le dice');
        $this->assertStringContainsString('14:00', $t);
        $this->assertStringNotContainsString('no tengo registrada', $bajo);
        $this->sinVenta($t, 'la memoria con cita confirmada');

        // Y el redactor puede decirlo con sus palabras: la cita es real, la frase es verdad.
        $this->conversation = $this->nuevaConversacion();
        ['message' => $m3] = $this->decide('a qué hora era mi cita?', 'w.as.2g');
        $bueno = 'Claro: tu visita quedó confirmada por el equipo para el miércoles 23 de septiembre a las 14:00. Te esperamos.';
        $this->commit($m3, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => $bueno]);
        $this->assertSame($bueno, $this->ultimo(), 'la guarda retiró una confirmación verdadera');
    }

    /** Sin compromiso registrado, la memoria dice la verdad: no hay, y pide el dato. */
    public function test_2b_sin_compromiso_la_memoria_no_inventa_una_visita(): void
    {
        ['message' => $m] = $this->decide('me recuerdas a qué hora quedé para la visita?', 'w.as.2d');
        $this->commit($m, [
            'intent' => SalesIntents::SCHEDULE_QUESTION,
            'reply_draft' => 'Claro, quedaste agendado mañana a las 10. ¿Te cuento del {{PLAN_NAME}}?',
            'recommended_plan_id' => $this->insignia->id,
        ]);
        $bajo = mb_strtolower($this->ultimo());
        $this->assertStringContainsString('no tengo registrada', $bajo);
        $this->assertStringNotContainsString('agendad', $bajo);
        $this->sinVenta($this->ultimo(), 'la memoria sin compromiso');
    }

    // ── TEST 3 · lead caliente pregunta planes: Total Access explicado con valor ──

    public function test_3_lead_caliente_que_pregunta_planes_recibe_total_access_con_su_porque(): void
    {
        // Lo que ya contó en conversaciones anteriores.
        $this->lead->forceFill(['objective' => 'ganar masa muscular', 'metadata' => [LeadProfileService::KEY => [
            'objective' => 'ganar masa muscular', 'experience_level' => 'experienced', 'availability' => null, 'preferences' => [],
            'episodes' => [['kind' => 'interest', 'meaning' => 'Pidió información del gimnasio por primera vez.', 'at' => '2026-09-20T14:00:00+00:00']],
            'updated_at' => '2026-09-20T14:00:00+00:00',
        ]]])->save();

        ['message' => $m, 'json' => $j] = $this->decide('Quiero entrenar 6 días a la semana, ¿qué plan me conviene y cuánto vale?', 'w.as.3a');
        $c = $j['context']['customer'];
        $p = $j['context']['commercial_turn_policy'];
        $h = $j['context']['strategy_hints'];

        $this->assertSame('ganar masa muscular', $c['known']['objective']);
        $this->assertSame('experienced', $c['known']['experience_level']);
        $this->assertSame('6 dias', $c['inferred']['availability'], 'no leyó cuándo puede venir del mensaje de hoy');
        $this->assertSame($this->insignia->id, $p['preferred_commercial_plan'], 'la prioridad comercial de Total Access no se toca');
        $this->assertTrue($h['should_recommend_now'], 'con objetivo y experiencia conocidos se recomienda, no se pregunta');
        $this->assertNull($h['suggested_question'], 'a quien ya contó todo no se le pregunta nada');
        $this->assertFalse($p['reception_mode']);
        $this->assertFalse($p['memory_mode']);

        // El porqué, con el plan y el precio reales: sale intacto.
        $this->commit($m, [
            'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => $this->insignia->id, 'next_state' => P::RECOMMENDATION,
            // Con la marca de recomendación: sin ella la memoria no registra lo que se recomendó.
            'reply_draft' => 'Perfecto, revisemos. Por lo que me cuentas —6 días a la semana y experiencia—, lo que mejor te encaja es el {{PLAN_NAME}}: acceso completo a todas las áreas y acompañamiento del equipo, por {{PLAN_PRICE}} al mes. ¿Empezamos esta semana?',
        ]);
        $t = $this->ultimo();
        $this->assertStringContainsString('TOTAL ACCESS - ELITE', $t);
        $this->assertStringContainsString('180', $t);
        $this->assertStringContainsString('acompañamiento', mb_strtolower($t), 'el plan sin su porqué es una tarifa');
        $this->assertSame([], $this->meta()['policy_violations'] ?? []);

        $this->assertSame([$this->insignia->id], array_map('intval', (array) data_get($this->conversation->fresh()->memory, 'last_recommendation.plan_ids')), 'la recomendación no quedó registrada: el «quiero ese» siguiente apuntaría a otro plan');

        // Y la ficha aprendió la disponibilidad para la próxima conversación.
        $this->assertSame('6 dias', $this->ficha()['availability']);
        $this->assertContains('Pidió el precio del TOTAL ACCESS - ELITE.', $this->significados());
    }

    // ── TEST 4 · lead frío, solo información: información + apertura ─────────

    public function test_4_lead_frio_que_solo_pide_informacion_recibe_hechos_y_una_apertura(): void
    {
        ['message' => $m, 'json' => $j] = $this->decide('Hola, quiero información del gimnasio', 'w.as.4a');
        $h = $j['context']['strategy_hints'];
        $c = $j['context']['customer'];

        $this->assertFalse($c['known']['returning_lead']);
        $this->assertSame([], $c['history']);
        $this->assertSame('seria tu primera vez entrenando o ya tienes experiencia?', $h['suggested_question'], 'a quien no contó nada se le pregunta por su punto de partida, no «¿cuál es tu objetivo?»');

        // Con el critic caído, la ficha real: quiénes somos, dónde, y una apertura. Sin el teléfono de la sede.
        $this->commit($m, ['reply_draft' => '¿Qué necesitas saber? Puedes preguntarme por planes, horarios o clases.'], self::CRITIC_CAIDO);
        $t = $this->ultimo();
        $bajo = mb_strtolower($t);
        $this->assertStringContainsString('iron body', $bajo);
        $this->assertStringContainsString('cl. 24 sur', $bajo, 'sin la sede real');
        $this->assertStringNotContainsString('314', $t, 'el teléfono de la ficha se coló, o bloqueó la respuesta como precio inventado');
        $this->assertStringContainsString('?', $t, 'información sin apertura es un folleto');
        foreach (['qué necesitas saber', 'puedes preguntarme', 'para orientarte mejor', 'cuál es tu objetivo'] as $muletilla) {
            $this->assertStringNotContainsString($muletilla, $bajo, "dice «{$muletilla}»");
        }
        $this->assertContains('Pidió información del gimnasio por primera vez.', $this->significados());
    }

    // ── TEST 5 · lead con objeción: empatía + solución ───────────────────────

    public function test_5_lead_con_objecion_recibe_empatia_y_solucion(): void
    {
        $this->conversation->forceFill(['memory' => array_merge(CM::blank(), [
            'prices_delivered' => [['plan_id' => $this->insignia->id, 'price' => 180000, 'at' => '2026-09-22T13:00:00+00:00']],
            'plans_discussed' => [$this->insignia->id],
        ]), 'commercial_phase' => P::RECOMMENDATION])->save();

        ['message' => $m, 'json' => $j] = $this->decide('uy no, está muy caro para mí', 'w.as.5a');
        $h = $j['context']['strategy_hints'];
        $this->assertTrue($h['open_objection']);
        $this->assertNull($h['suggested_question'], 'con una objeción abierta no se abre descubrimiento');
        $this->assertFalse($h['should_recommend_now'], 'primero la objeción, después la recomendación');

        // Un buen borrador: reconoce, resuelve con la alternativa real, avanza.
        $bueno = 'Te entiendo, es una inversión y tiene que tener sentido para ti. Por eso también está el {{PLAN_NAME}} por {{PLAN_PRICE}}: acceso ilimitado al gimnasio, sin el acompañamiento del Élite. ¿Te cuadra mejor para empezar?';
        $this->commit($m, ['intent' => SalesIntents::PRICE_OBJECTION, 'next_state' => P::OBJECTION_HANDLING, 'recommended_plan_id' => $this->mensual->id, 'reply_draft' => $bueno]);
        $t = $this->ultimo();
        $this->assertStringContainsString('Te entiendo', $t);
        $this->assertStringContainsString('Plan Mensual', $t);
        $this->assertStringContainsString('80', $t);
        $this->assertSame([], $this->meta()['policy_violations'] ?? []);
        $this->assertContains('Manifestó una objeción: el precio.', $this->significados());

        // Con el critic caído tampoco se responde con un interrogatorio ni con un menú.
        ['message' => $m2] = $this->decide('es que no me alcanza', 'w.as.5b');
        $this->commit($m2, ['intent' => SalesIntents::PRICE_OBJECTION, 'next_state' => P::OBJECTION_HANDLING, 'reply_draft' => '¿Cuál es tu objetivo?'], self::CRITIC_CAIDO);
        $bajo = mb_strtolower($this->ultimo());
        $this->assertNotSame('', trim($bajo));
        foreach (['qué necesitas saber', 'puedes preguntarme', 'cuál es tu objetivo'] as $muletilla) {
            $this->assertStringNotContainsString($muletilla, $bajo, "dice «{$muletilla}»");
        }
    }

    // ── TEST 6 · información inexistente: no inventar ────────────────────────

    public function test_6_lo_que_no_esta_registrado_no_se_afirma(): void
    {
        ['message' => $m] = $this->decide('tienen piscina o clases de zumba?', 'w.as.6a');
        $this->commit($m, [
            'intent' => SalesIntents::GENERAL_INFO,
            'reply_draft' => 'Sí, tenemos zumba los martes y jueves y una piscina climatizada. ¿Te cuento los horarios?',
        ]);
        $t = mb_strtolower($this->ultimo());
        foreach (['tenemos zumba', 'piscina climatizada', 'zumba los martes'] as $inventado) {
            $this->assertStringNotContainsString($inventado, $t, "afirmó «{$inventado}», que no existe en el catálogo");
        }
        $this->assertNotEmpty($this->meta()['invented_service_dropped'] ?? [], 'el servicio inventado salió sin que nadie lo retirara');
        // Lo que sí tenemos, con nombre real, o la honestidad de que no.
        $this->assertTrue(
            str_contains($t, 'iron powerflow') || str_contains($t, 'no ') || str_contains($t, 'no tenemos'),
            "ni ofreció lo real ni dijo que no: «{$t}»",
        );
    }

    // ── El otro fallo físico: la visita a medias no se contesta con el horario ──

    public function test_7_con_la_visita_a_medio_concretar_el_respaldo_pide_lo_que_falta(): void
    {
        // Turno 1: pide la visita sin decir cuándo → el objetivo queda recogiendo.
        ['message' => $m] = $this->decide('quiero ir a conocer el gimnasio', 'w.as.7a');
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'reply_draft' => 'Con gusto. ¿Qué día y a qué hora te queda bien pasar?',
        ]);
        $this->assertSame('collecting', data_get($this->conversation->fresh()->memory, 'active_goal.status'));

        // Turno 2: «mañana» con el critic caído y la etiqueta schedule_question (lo que pasó): el respaldo es la visita, no el horario de apertura.
        ['message' => $m2] = $this->decide('mañana', 'w.as.7b');
        $this->commit($m2, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => 'Nuestro horario es de lunes a viernes de 5 a. m. a 10 p. m.'], self::CRITIC_CAIDO);
        $t = mb_strtolower($this->ultimo());
        $this->assertStringContainsString('visita', $t, 'contestó con otra cosa a quien estaba concretando su visita');
        $this->assertStringContainsString('hora', $t, 'ya tenía el día; faltaba la hora');
        $this->assertStringNotContainsString('lunes a viernes', $t);
    }

    /** La ficha nunca tumba un turno: un lead sin metadata y sin objetivo se atiende igual. */
    public function test_8_el_estado_temporal_no_cruza_conversaciones_pero_la_ficha_si(): void
    {
        ['message' => $m] = $this->decide('soy principiante, nunca he entrenado, y solo puedo en las noches', 'w.as.8a');
        $this->commit($m, ['intent' => SalesIntents::BEGINNER_FEAR, 'reply_draft' => 'Tranquilo, la mayoría empieza así. En las noches hay acompañamiento del equipo desde el primer día. ¿Qué te gustaría lograr al empezar?']);

        $ficha = $this->ficha();
        $this->assertSame('beginner', $ficha['experience_level']);
        $this->assertSame('noches', $ficha['availability']);
        // El miedo a empezar se atiende en el turno, pero no se guarda en la ficha permanente.
        $this->assertNotContains('Manifestó una objeción: miedo a empezar.', $this->significados(), 'una objeción sensible quedó en la ficha permanente');

        $this->conversation = $this->nuevaConversacion();
        ['message' => $m2, 'json' => $j] = $this->decide('hola, quiero información', 'w.as.8b');
        $c = $j['context']['customer'];
        $h = $j['context']['strategy_hints'];
        $this->assertSame('beginner', $c['known']['experience_level']);
        $this->assertSame('noches', $c['known']['availability']);
        $this->assertNotContains('experience_level', $c['unknown']);
        $this->assertNotContains('time_constraints', $c['unknown']);
        $this->assertContains('objective', $c['unknown']);
        $this->assertSame('que buscas lograr al empezar: bajar grasa, ganar masa o coger el habito?', $h['suggested_question'], 'a un principiante se le pregunta como a un principiante');
        $this->assertNull($j['context']['commercial_turn_policy']['active_goal'] ?? null);
        $this->assertNull($j['context']['memory']['summary'] ?? null, 'la memoria de la conversación anterior se heredó');
    }

    // ── Hallazgos de la revisión: cada uno con su prueba por el recorrido real ──

    /**
     * «¿Me recuerdas cuánto vale el mensual?» es memoria, pero su respuesta es
     * un precio: no entra en modo memoria y la respuesta honesta sale entera.
     * La revisión lo midió saliendo como «No tengo registrada una visita tuya».
     */
    public function test_9_recordar_un_precio_no_es_modo_memoria_y_el_precio_sale(): void
    {
        ['message' => $m, 'json' => $j] = $this->decide('me recuerdas cuánto vale el mensual?', 'w.as.9a');
        $this->assertFalse($j['context']['commercial_turn_policy']['memory_mode']);

        $this->commit($m, [
            'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => $this->mensual->id, 'next_state' => P::RECOMMENDATION,
            'reply_draft' => 'Claro, te lo recuerdo: el {{PLAN_NAME}} está en {{PLAN_PRICE}} al mes, con acceso ilimitado al gimnasio.',
        ]);
        $t = $this->ultimo();
        $this->assertStringContainsString('Plan Mensual', $t);
        $this->assertStringContainsString('80', $t);
        $this->assertStringNotContainsString('no tengo registrada', mb_strtolower($t));
        $this->assertNotContains('policy_memory_turn_sells', $this->meta()['policy_violations'] ?? []);
    }

    /**
     * «Rechazó el X» era inalcanzable: la memoria de «antes» se recargaba con
     * el rechazo ya dentro. Por el recorrido real: tras una recomendación, «está
     * muy caro, muéstrame otro» deja el episodio en la ficha permanente.
     */
    public function test_10_el_rechazo_de_un_plan_queda_en_la_ficha_del_lead(): void
    {
        $this->conversation->forceFill(['memory' => array_merge(CM::blank(), [
            'last_recommendation' => ['plan_ids' => [$this->insignia->id], 'at' => '2026-09-22T13:00:00+00:00'],
            'plans_discussed' => [$this->insignia->id],
            'prices_delivered' => [['plan_id' => $this->insignia->id, 'price' => 180000, 'at' => '2026-09-22T13:00:00+00:00']],
        ]), 'commercial_phase' => P::RECOMMENDATION])->save();

        ['message' => $m] = $this->decide('está muy caro, muéstrame otro', 'w.as.10a');
        $this->commit($m, [
            'intent' => SalesIntents::PRICE_OBJECTION, 'next_state' => P::OBJECTION_HANDLING, 'recommended_plan_id' => $this->mensual->id,
            'reply_draft' => 'Te entiendo. Una opción más ligera es el {{PLAN_NAME}} por {{PLAN_PRICE}}: acceso ilimitado al gimnasio para empezar.',
        ]);

        $this->assertContains($this->insignia->id, array_map('intval', (array) data_get($this->conversation->fresh()->memory, 'plans_rejected')), 'el fixture no produjo el rechazo');
        $this->assertContains('Rechazó el TOTAL ACCESS - ELITE.', $this->significados(), 'el rechazo no llegó a la ficha permanente');
        $this->assertContains('Manifestó una objeción: el precio.', $this->significados());
    }

    /**
     * La excepción de la memoria cubre SÓLO la frase honesta de la visita. Un
     * «lo dejo escalado al equipo» en el mismo borrador sigue siendo una promesa
     * sin autoridad y el turno se rechaza.
     */
    public function test_11_recordar_la_visita_no_abre_la_puerta_a_otras_promesas(): void
    {
        ['message' => $m] = $this->decide('quiero conocer el gimnasio mañana a las 2 pm', 'w.as.11a');
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => '2026-09-23', 'courtesy_time' => '14:00',
            'reply_draft' => 'Listo, dejo solicitada tu visita para mañana a las 2:00 p. m.; el equipo la confirma.',
        ]);

        $this->conversation = $this->nuevaConversacion();
        ['message' => $m2] = $this->decide('me recuerdas a qué hora era mi visita?', 'w.as.11b');
        $r = $this->commitCrudo($m2, [
            'intent' => SalesIntents::SCHEDULE_QUESTION,
            // La frase honesta PRIMERO: así la promesa de marca no es la primera que ve el detector.
            'reply_draft' => 'Tu visita quedó solicitada para el miércoles 23 de septiembre a las 14:00. Además dejo marcado tu caso para revisión.',
        ]);
        $r->assertStatus(422);
        $this->assertSame('promised_effect_without_authority', $r->json('code'));

        // Y la misma frase honesta, sola, sale.
        ['message' => $m3] = $this->decide('me recuerdas a qué hora era mi visita?', 'w.as.11c');
        $bueno = 'Tu visita quedó solicitada para el miércoles 23 de septiembre a las 14:00 y el equipo la confirma contigo.';
        $this->commit($m3, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => $bueno]);
        $this->assertSame($bueno, $this->ultimo());
    }

    /**
     * Con la fecha en null y un mensaje con dudas —una corrección («el viernes
     * no, mejor el miércoles»), dos días («el sábado o el domingo»)—, el texto
     * NO registra nada: registrar el día equivocado manda al equipo a preparar
     * una visita que nadie pidió, y no registrar sólo cuesta una pregunta. Y
     * el borrador que dice «dejo solicitada tu visita» no sale: no hay
     * solicitud, y se pregunta lo que falta.
     */
    public function test_12_con_dudas_en_el_texto_no_se_registra_y_no_se_afirma(): void
    {
        ['message' => $m] = $this->decide('el viernes no puedo, mejor el miércoles a las 10', 'w.as.12a');
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Perfecto, dejo solicitada tu visita para el miércoles a las 10:00 a. m.; el equipo la confirma.',
        ]);
        $this->assertSame(0, MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->where('marketing_lead_id', $this->lead->id)->count(), 'se registró una visita leyendo un texto con dudas');
        $t = mb_strtolower($this->ultimo());
        $this->assertStringNotContainsString('dejo solicitada', $t, 'se afirmó una solicitud que no existe');
        $this->assertStringContainsString('?', $t, 'no se preguntó lo que falta');
        $this->assertSame(1, $this->meta()['courtesy_unregistered_claim_dropped'] ?? null);

        // Otra persona: duda entre dos días → tampoco se registra nada.
        $otro = MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150000000', 'meta_user_id' => '573150000000', 'name' => 'Dudoso', 'status' => MarketingLead::STATUS_NEW]);
        $this->lead = $otro;
        $this->conversation = $this->nuevaConversacion();
        ['message' => $m2] = $this->decide('puede ser el sábado o el domingo a las 10', 'w.as.12b');
        $this->commit($m2, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Con gusto. ¿Cuál de los dos te queda mejor, el sábado o el domingo?',
        ]);
        $this->assertSame(0, MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->where('marketing_lead_id', $otro->id)->count(), 'se registró una visita para un día que la persona no eligió');
    }

    /** El respaldo con la ficha real: sin teléfonos, pero con la dirección entera aunque tenga cifras. */
    public function test_13_el_respaldo_quita_telefonos_sin_comerse_la_direccion(): void
    {
        MarketingKnowledgeItem::query()->where('key', 'location.city')->update([
            // Sin años: el guard de salida lee cuatro cifras seguidas como un precio, y eso es otra guarda.
            'content' => 'Estamos en la Calle 10 20-30, barrio Centro. Escríbenos al 3143455483.',
        ]);
        ['message' => $m] = $this->decide('Hola, quiero información del gimnasio', 'w.as.13a');
        $this->commit($m, ['reply_draft' => '¿Qué necesitas saber?'], self::CRITIC_CAIDO);

        $t = $this->ultimo();
        $this->assertStringContainsString('Calle 10 20-30', $t, 'el borrado de teléfonos se comió la dirección');
        $this->assertStringNotContainsString('3143455483', $t);
        $this->assertStringNotContainsString('Escríbenos al', $t, 'quedó una frase colgando sin su número');
    }

    /** «Buenas tardes» no es disponibilidad por las tardes: la ficha permanente no se ensucia con saludos. */
    public function test_14_un_saludo_de_franja_no_escribe_disponibilidad(): void
    {
        ['message' => $m] = $this->decide('Buenas tardes, quiero información', 'w.as.14a');
        $this->commit($m, ['reply_draft' => 'Buenas tardes, con gusto te cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. ¿Sería tu primera vez entrenando o ya tienes experiencia?']);

        $this->assertNull($this->ficha()['availability'], 'un saludo quedó como disponibilidad permanente');

        $this->conversation = $this->nuevaConversacion();
        ['json' => $j] = $this->decide('hola, qué planes tienen?', 'w.as.14b');
        $this->assertContains('time_constraints', $j['context']['customer']['unknown'], 'el agente dejó de poder preguntar por horarios');
    }

    // ── Segunda revisión: cada hallazgo confirmado, fijado por el recorrido real ──

    /** Una visita a medias con el critic caído no pisa el texto propio de lo sensible, del precio ni del «ya no quiero ir». */
    public function test_15_con_la_visita_a_medias_el_respaldo_no_secuestra_lo_sensible(): void
    {
        ['message' => $m] = $this->decide('quiero ir a conocer el gimnasio', 'w.as.15a');
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'reply_draft' => 'Con gusto. ¿Qué día y a qué hora te queda bien pasar?',
        ]);
        $this->assertSame('collecting', data_get($this->conversation->fresh()->memory, 'active_goal.status'));

        $casos = [
            'w.as.15b' => [SalesIntents::MEDICAL_RISK_ESCALATION, 'me lesioné la rodilla, ¿puedo entrenar?'],
            'w.as.15c' => [SalesIntents::HUMAN_REQUEST, 'quiero hablar con una persona'],
            'w.as.15d' => [SalesIntents::PRICING_QUESTION, '¿cuánto vale el mensual?'],
            'w.as.15e' => [SalesIntents::NOT_INTERESTED, 'ya no quiero ir, gracias'],
        ];
        foreach ($casos as $wamid => [$intent, $texto]) {
            ['message' => $mm] = $this->decide($texto, $wamid);
            $this->commit($mm, ['intent' => $intent, 'next_state' => P::DISCOVERY, 'reply_draft' => 'x'], self::CRITIC_CAIDO);
            $t = mb_strtolower($this->ultimo());
            $this->assertStringNotContainsString('para tu visita al gimnasio', $t, "con «{$texto}» ({$intent}) el respaldo volvió a pedir el día de la visita");
        }
    }

    /** Un lead que ya eligió plan pregunta por su visita: las pistas no empujan a cerrar y el plan no queda en memoria. */
    public function test_16_el_modo_memoria_manda_sobre_el_lead_caliente(): void
    {
        ['message' => $m] = $this->decide('quiero conocer el gimnasio mañana a las 2 pm', 'w.as.16a');
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => '2026-09-23', 'courtesy_time' => '14:00',
            'reply_draft' => 'Listo, dejo solicitada tu visita para mañana a las 2:00 p. m.; el equipo la confirma.',
        ]);
        $this->conversation->forceFill(['memory' => array_merge((array) $this->conversation->fresh()->memory, [
            'commercial_commitment' => ['plan_id' => $this->insignia->id, 'at' => '2026-09-22T13:00:00+00:00'],
            'plans_discussed' => [$this->mensual->id],
        ])])->save();

        ['message' => $m2, 'json' => $j] = $this->decide('me recuerdas a qué hora era mi visita?', 'w.as.16b');
        $h = $j['context']['strategy_hints'];
        $this->assertTrue($j['context']['commercial_turn_policy']['memory_mode']);
        $this->assertTrue($h['memory_only']);
        $this->assertFalse($h['hot_lead_fast_path'], 'la vía rápida de cierre siguió activa en un turno de memoria');
        $this->assertFalse($h['should_recommend_now']);
        $this->assertNull($h['suggested_question']);

        $this->commit($m2, [
            'intent' => SalesIntents::SCHEDULE_QUESTION, 'recommended_plan_id' => $this->insignia->id, 'next_state' => P::RECOMMENDATION,
            'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
            'reply_draft' => 'Tu visita es mañana a las 2. Y aquí tienes el link para pagar el {{PLAN_NAME}}.',
        ]);
        $mem = $this->conversation->fresh()->memory;
        $this->assertNotContains($this->insignia->id, array_map('intval', (array) ($mem['plans_discussed'] ?? [])), 'la memoria anotó un plan que no salió');
        $this->assertStringNotContainsString('link', mb_strtolower($this->ultimo()));
        $this->assertNotContains('Comparó planes: Plan Mensual y TOTAL ACCESS - ELITE.', $this->significados());
    }

    /**
     * El incidente original con la frase que el propio prompt recomienda: el
     * estratega manda la cortesía sin fecha ni hora y el redactor escribe «queda
     * registrada». La fecha y la hora se leen del texto ANTES de la invariante
     * de promesas: el turno no muere en 422 y la visita se registra.
     */
    public function test_17_queda_registrada_con_fecha_nula_no_tumba_el_turno(): void
    {
        ['message' => $m] = $this->decide('quiero ir mañana miércoles a las 2 pm', 'w.as.17a');
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Listo, tu solicitud queda registrada para mañana miércoles a las 2 pm y el equipo la revisará.',
        ]);
        $a = MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->where('marketing_lead_id', $this->lead->id)->latest('id')->first();
        $this->assertNotNull($a, 'la visita no se registró');
        $this->assertSame('2026-09-23', data_get($a->metadata, 'requested_date'));
        $this->assertSame('14:00', data_get($a->metadata, 'requested_time'));
    }

    /** Una LLAMADA agendada por el equipo no es la visita: no se cuenta como compromiso ni abre la guarda. */
    public function test_18_una_llamada_agendada_no_es_una_visita_confirmada(): void
    {
        MarketingAppointment::create([
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $this->conversation->id,
            'type' => MarketingAppointment::TYPE_CALL, 'title' => 'Llamada de seguimiento',
            'scheduled_at' => Carbon::parse('2026-09-24 10:00', 'America/Bogota')->setTimezone('UTC'), 'status' => MarketingAppointment::STATUS_SCHEDULED,
        ]);
        ['message' => $m, 'json' => $j] = $this->decide('me recuerdas a qué hora era mi cita?', 'w.as.18a');
        $this->assertSame([], $j['context']['customer']['commitments'], 'una llamada se contó como visita');

        $this->commit($m, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => 'El equipo ya confirmó tu visita: jueves 24 de septiembre a las 10:00. Te esperamos.']);
        $t = mb_strtolower($this->ultimo());
        $this->assertStringNotContainsString('ya confirmó tu visita', $t, 'se le dijo «te esperamos» por una llamada telefónica');
    }

    /** Con una visita confirmada el jueves, «quedaste agendado para el sábado» sigue siendo mentira, en cualquier conversación del lead. */
    public function test_19_la_visita_confirmada_solo_respalda_su_propio_dia(): void
    {
        MarketingAppointment::create([
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $this->conversation->id,
            'type' => MarketingAppointment::TYPE_VISIT, 'title' => 'Día de cortesía',
            'scheduled_at' => Carbon::parse('2026-09-24 10:00', 'America/Bogota')->setTimezone('UTC'), 'status' => MarketingAppointment::STATUS_SCHEDULED,
        ]);
        $this->conversation = $this->nuevaConversacion();

        ['message' => $m] = $this->decide('nos vemos el sábado entonces', 'w.as.19a');
        $this->commit($m, ['intent' => SalesIntents::GENERAL_INFO, 'reply_draft' => 'Listo, quedaste agendado para el sábado a las 10. Trae ropa cómoda.']);
        $this->assertStringNotContainsString('quedaste agendado para el sábado', mb_strtolower($this->ultimo()), 'el día equivocado pasó por tener otra cita confirmada');

        ['message' => $m2] = $this->decide('entonces nos vemos el jueves', 'w.as.19b');
        $bueno = 'Perfecto, tu visita del jueves 24 de septiembre a las 10:00 ya está confirmada. Te esperamos.';
        $this->commit($m2, ['intent' => SalesIntents::GENERAL_INFO, 'reply_draft' => $bueno]);
        $this->assertSame($bueno, $this->ultimo(), 'la confirmación verdadera, con su día, se retiró');
    }

    /** En modo memoria, un día u hora distintos del compromiso no salen: sale el respaldo, escrito con la fila. */
    public function test_20_en_modo_memoria_la_hora_equivocada_no_sale(): void
    {
        ['message' => $m] = $this->decide('quiero conocer el gimnasio mañana a las 2 pm', 'w.as.20a');
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => '2026-09-23', 'courtesy_time' => '14:00',
            'reply_draft' => 'Listo, dejo solicitada tu visita para mañana a las 2:00 p. m.; el equipo la confirma.',
        ]);
        $this->conversation = $this->nuevaConversacion();
        ['message' => $m2] = $this->decide('me recuerdas a qué hora era mi visita?', 'w.as.20b');
        $this->commit($m2, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => 'Claro, tu visita quedó solicitada para mañana miércoles a las 4 pm.']);

        $t = $this->ultimo();
        $this->assertStringNotContainsString('4 pm', $t, 'la hora equivocada salió');
        $this->assertStringContainsString('14:00', $t);
        $this->assertContains(UltronCommitService::VIOLACION_MEMORIA_DATO, $this->meta()['policy_violations'] ?? []);
    }

    /** Una visita de hoy cuya hora ya pasó no se contesta con «te esperamos». */
    public function test_21_la_visita_de_hoy_ya_pasada_no_se_espera(): void
    {
        MarketingAppointment::create([
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $this->conversation->id,
            'type' => MarketingAppointment::TYPE_VISIT, 'title' => 'Día de cortesía',
            // Hoy a las 08:00 en Neiva; ahora son las 09:00.
            'scheduled_at' => Carbon::parse('2026-09-22 08:00', 'America/Bogota')->setTimezone('UTC'), 'status' => MarketingAppointment::STATUS_SCHEDULED,
        ]);
        ['message' => $m, 'json' => $j] = $this->decide('me recuerdas a qué hora era mi visita?', 'w.as.21a');
        $this->assertTrue($j['context']['customer']['commitments'][0]['past'] ?? false);

        $this->commit($m, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => 'Tu visita es hoy a las 3 pm, te esperamos.']);
        $t = mb_strtolower($this->ultimo());
        $this->assertStringNotContainsString('te esperamos', $t, 'se le dijo «te esperamos» por una hora que ya pasó');
        $this->assertStringContainsString('estaba para el martes 22 de septiembre a las 08:00', $t);
    }

    /** La excepción del recuerdo honesto no cubre una «solicitud» cualquiera ni lo que va coordinado con ella. */
    public function test_22_el_recuerdo_honesto_no_deja_pasar_otra_constancia(): void
    {
        ['message' => $m] = $this->decide('quiero conocer el gimnasio mañana a las 2 pm', 'w.as.22a');
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => '2026-09-23', 'courtesy_time' => '14:00',
            'reply_draft' => 'Listo, dejo solicitada tu visita para mañana a las 2:00 p. m.; el equipo la confirma.',
        ]);
        $this->conversation = $this->nuevaConversacion();
        foreach ([
            'w.as.22b' => 'Tu solicitud quedó registrada y escalada al equipo de ventas.',
            'w.as.22c' => 'Tu visita sigue pendiente y quedó anotada tu solicitud de cambio para el jueves a las 5.',
            // Mismo día y hora que el compromiso: sólo lo coordinado («y escalada») la delata.
            'w.as.22d' => 'Tu visita quedó solicitada para el miércoles 23 de septiembre a las 14:00 y escalada al equipo de ventas.',
        ] as $wamid => $borrador) {
            ['message' => $mm] = $this->decide('me recuerdas a qué hora era mi visita?', $wamid);
            $r = $this->commitCrudo($mm, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => $borrador]);
            $r->assertStatus(422);
            $this->assertSame('promised_effect_without_authority', $r->json('code'), $borrador);
        }
    }

    /** «Hola» de alguien a quien ya recibimos en ESTA conversación: el respaldo dice «hola de nuevo», no la bienvenida. */
    public function test_23_quien_vuelve_a_la_misma_conversacion_no_se_estrena(): void
    {
        ['message' => $m] = $this->decide('hola', 'w.as.23a');
        $this->commit($m, ['intent' => SalesIntents::GREETING, 'next_state' => P::RAPPORT, 'reply_draft' => 'x'], self::CRITIC_CAIDO);
        $this->assertStringContainsString('bienvenido', mb_strtolower($this->ultimo()));

        ['message' => $m2, 'json' => $j] = $this->decide('hola', 'w.as.23b');
        $this->assertFalse($j['context']['commercial_turn_policy']['welcome_required'], 'la política no sabe que ya se dio la bienvenida');
        $this->commit($m2, ['intent' => SalesIntents::GREETING, 'next_state' => P::RAPPORT, 'reply_draft' => 'x'], self::CRITIC_CAIDO);
        $this->assertStringNotContainsString('bienvenido', mb_strtolower($this->ultimo()));
    }

    // ── Tercera tanda de arreglos: cada hallazgo de la segunda revisión, por el recorrido real ──

    /** Deja una visita SOLICITADA para mañana a las 14:00 y abre una conversación nueva. */
    private function conVisitaSolicitadaYConversacionNueva(): MarketingAppointment
    {
        ['message' => $m] = $this->decide('quiero conocer el gimnasio mañana a las 2 pm', 'w.prep.'.uniqid());
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => '2026-09-23', 'courtesy_time' => '14:00',
            'reply_draft' => 'Listo, dejo solicitada tu visita para mañana a las 2:00 p. m.; el equipo la confirma.',
        ]);
        $this->conversation = $this->nuevaConversacion();

        return MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->where('marketing_lead_id', $this->lead->id)->latest('id')->firstOrFail();
    }

    /** La misma pregunta de memoria dos veces en el día recibe la hora las dos veces: no es una respuesta «repetida». */
    public function test_24_preguntar_dos_veces_por_la_visita_no_es_repetirse(): void
    {
        $this->conVisitaSolicitadaYConversacionNueva();
        $bueno = 'Claro. Tu visita quedó solicitada para el miércoles 23 de septiembre a las 14:00; el equipo la revisa para tenerlo todo listo.';
        foreach (['w.as.24a', 'w.as.24b'] as $wamid) {
            ['message' => $m] = $this->decide('me recuerdas a qué hora era mi visita?', $wamid);
            $this->commit($m, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => $bueno]);
            $this->assertSame($bueno, $this->ultimo(), 'la segunda vez se tumbó por repetida y no se le dio la hora');
        }
    }

    /**
     * Un turno de memoria sólo contesta: la cortesía que el modelo pida con el
     * día y la hora de la PREGUNTA no mueve la visita ni le quita la aprobación,
     * y el marcador de la app no pega enlaces que luego se anoten como enviados.
     */
    public function test_25_el_modo_memoria_no_toca_la_visita_ni_manda_enlaces(): void
    {
        $solicitud = $this->conVisitaSolicitadaYConversacionNueva();
        // El equipo ya la confirmó en la agenda.
        $solicitud->forceFill(['status' => MarketingAppointment::STATUS_SCHEDULED])->save();

        ['message' => $m] = $this->decide('me recuerdas qué día agendé la visita?', 'w.as.25a');
        $this->commit($m, [
            'intent' => SalesIntents::SCHEDULE_QUESTION,
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST, SalesIntents::TOOL_APP_LINKS_SEND], 'courtesy_action' => 'request',
            'courtesy_date' => '2026-09-26', 'courtesy_time' => '10:00',
            'reply_draft' => 'Tu visita quedó solicitada para el miércoles 23 de septiembre a las 14:00. {{APP_LINKS}}',
        ]);

        $solicitud->refresh();
        $this->assertSame(MarketingAppointment::STATUS_SCHEDULED, $solicitud->status, 'la pregunta de memoria le quitó la confirmación a la visita');
        $this->assertSame('2026-09-23', data_get($solicitud->metadata, 'requested_date'), 'la pregunta de memoria movió la visita');
        $this->assertNull(data_get($this->conversation->fresh()->memory, 'app_context.links_sent_at'), 'se anotaron enlaces que no salieron');
        $this->assertStringNotContainsString('{{', $this->ultimo());
    }

    /** Cancelar desde una conversación nueva cancela la solicitud abierta del lead, venga de donde venga. */
    public function test_26_cancelar_desde_otra_conversacion_cancela_la_solicitud_del_lead(): void
    {
        $solicitud = $this->conVisitaSolicitadaYConversacionNueva();

        ['message' => $m] = $this->decide('ya no voy a poder ir, cancela la visita por favor', 'w.as.26a');
        $this->commit($m, [
            'intent' => SalesIntents::GENERAL_INFO,
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'cancel',
            'reply_draft' => 'Listo, cancelé tu visita del miércoles. Cuando quieras volvemos a agendarla.',
        ]);
        $this->assertSame(MarketingAppointment::STATUS_CANCELLED, $solicitud->fresh()->status, 'la cancelación no llegó a la solicitud de la otra conversación');
    }

    /** «Listo, cancelé tu visita» sin nada que cancelar es una promesa sin autoridad: el turno no sale así. */
    public function test_27_decir_que_se_cancelo_sin_haber_cancelado_nada_no_sale(): void
    {
        ['message' => $m] = $this->decide('cancela mi visita', 'w.as.27a');
        $r = $this->commitCrudo($m, ['intent' => SalesIntents::GENERAL_INFO, 'reply_draft' => 'Listo, cancelé tu visita del sábado.']);
        $r->assertStatus(422);
        $this->assertSame('promised_effect_without_authority', $r->json('code'));
    }

    /** En modo memoria, decir «confirmada» de una visita que sólo está SOLICITADA no sale: sale el estado real. */
    public function test_28_el_estado_de_la_visita_tambien_se_contrasta(): void
    {
        $this->conVisitaSolicitadaYConversacionNueva();
        ['message' => $m] = $this->decide('me recuerdas a qué hora era mi visita?', 'w.as.28a');
        $this->commit($m, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => 'Claro. El equipo ya confirmó tu visita: miércoles 23 de septiembre a las 14:00. Te esperamos.']);

        // La guarda de cortesía lo retira antes que el contraste de memoria: sale el estado real, con su día y su hora.
        $t = mb_strtolower($this->ultimo());
        $this->assertStringNotContainsString('ya confirmó', $t, 'se afirmó una confirmación que no existe');
        $this->assertMatchesRegularExpression('/solicit(ada|ud)/u', $t);
        $this->assertStringContainsString('miércoles 23 de septiembre a las 2:00 p. m.', $t);
    }

    /** Con la visita a medias y el critic caído, una objeción recibe su propio texto, no la pregunta por el día. */
    public function test_29_la_visita_a_medias_no_pisa_una_objecion(): void
    {
        ['message' => $m] = $this->decide('quiero ir a conocer el gimnasio', 'w.as.29a');
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'reply_draft' => 'Con gusto. ¿Qué día y a qué hora te queda bien pasar?',
        ]);
        foreach ([
            'w.as.29b' => [SalesIntents::DELAY_OBJECTION, 'lo voy a pensar'],
            'w.as.29c' => [SalesIntents::INSECURITY_BODY, 'me da pena ir, estoy muy gorda'],
            'w.as.29d' => [SalesIntents::GENERAL_INFO, 'no voy a ir'],
        ] as $wamid => [$intent, $texto]) {
            ['message' => $mm] = $this->decide($texto, $wamid);
            $this->commit($mm, ['intent' => $intent, 'reply_draft' => 'x'], self::CRITIC_CAIDO);
            $this->assertStringNotContainsString('para tu visita al gimnasio', mb_strtolower($this->ultimo()), "«{$texto}» recibió la pregunta por el día");
        }
        // Y un fragmento de verdad («mañana») sí sigue con la visita.
        ['message' => $m2] = $this->decide('mañana', 'w.as.29e');
        $this->commit($m2, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => 'x'], self::CRITIC_CAIDO);
        $this->assertStringContainsString('visita', mb_strtolower($this->ultimo()));
    }

    /**
     * La guarda con una visita CONFIRMADA a las 19:00: «a las 7 p. m.» y «a las
     * 7» son verdad y salen enteras (sin partir la frase en el «p.»); un
     * «quedaste agendado» sin día, en otra conversación, se retira y la persona
     * lee la cita real.
     */
    public function test_30_la_guarda_lee_bien_la_hora_de_la_visita_confirmada(): void
    {
        MarketingAppointment::create([
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $this->conversation->id,
            'type' => MarketingAppointment::TYPE_VISIT, 'title' => 'Día de cortesía',
            'scheduled_at' => Carbon::parse('2026-09-23 19:00', 'America/Bogota')->setTimezone('UTC'), 'status' => MarketingAppointment::STATUS_SCHEDULED,
        ]);
        foreach ([
            'w.as.30a' => '¡Te esperamos mañana a las 7:00 p. m.! Trae ropa cómoda y agua.',
            'w.as.30b' => 'Listo, te esperamos mañana a las 7. Trae ropa cómoda.',
        ] as $wamid => $borrador) {
            ['message' => $m] = $this->decide('nos vemos mañana entonces', $wamid);
            $this->commit($m, ['intent' => SalesIntents::THANKS, 'reply_draft' => $borrador]);
            $this->assertSame($borrador, $this->ultimo(), 'la confirmación verdadera se mutiló o se retiró');
        }

        $this->conversation = $this->nuevaConversacion();
        ['message' => $m3] = $this->decide('gracias', 'w.as.30c');
        $this->commit($m3, ['intent' => SalesIntents::THANKS, 'reply_draft' => 'Con gusto. Quedaste agendado, te esperamos.']);
        $t = mb_strtolower($this->ultimo());
        $this->assertStringNotContainsString('quedaste agendado', $t, 'un «quedaste agendado» sin día pasó en otra conversación');
        $this->assertStringContainsString('ya confirmó tu visita: miércoles 23 de septiembre a las 19:00', $t);
    }

    /** La visita de ayer se sigue recordando: «¿a qué hora era?» no recibe «no tengo registrada una visita». */
    public function test_31_la_visita_de_ayer_se_recuerda_como_pasada(): void
    {
        $this->conVisitaSolicitadaYConversacionNueva();
        Carbon::setTestNow(Carbon::parse('2026-09-24 15:00:00', 'UTC'));

        ['message' => $m, 'json' => $j] = $this->decide('me recuerdas a qué hora era mi visita?', 'w.as.31a');
        $this->assertTrue($j['context']['customer']['commitments'][0]['past'] ?? false);
        $this->commit($m, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => 'x'], self::CRITIC_CAIDO);

        $t = mb_strtolower($this->ultimo());
        $this->assertStringContainsString('estaba para el miércoles 23 de septiembre a las 14:00', $t);
        $this->assertStringNotContainsString('no tengo registrada', $t);
    }

    /**
     * Al retirar una confirmación que nadie dio, la frase se retira ENTERA
     * aunque lleve «p. m.»: cortar en el «p.» dejaba un «m.» huérfano
     * delante de lo que sí era verdad.
     */
    public function test_32_retirar_una_frase_con_p_m_no_deja_restos(): void
    {
        ['message' => $m] = $this->decide('gracias', 'w.as.32a');
        $this->commit($m, ['intent' => SalesIntents::THANKS, 'reply_draft' => 'Listo, quedó agendada tu visita para mañana a las 7 p. m. Trae ropa cómoda y agua.']);

        $t = $this->ultimo();
        $this->assertStringNotContainsString('quedó agendada', $t, 'la confirmación que nadie dio salió');
        $this->assertDoesNotMatchRegularExpression('/(^|[.!?]\s+)m\.(\s|$)/u', $t, "quedó un «m.» huérfano: {$t}");
        $this->assertStringContainsString('Trae ropa cómoda y agua.', $t);
    }

    // ── Tercera revisión: el relleno de la cortesía, la visita confirmada, el orden de las guardas ──

    /** Registra en ESTA conversación una visita solicitada y devuelve su cita (solicitada). */
    private function conVisitaSolicitadaAqui(string $fecha, string $hora, string $wamid): MarketingAppointment
    {
        ['message' => $m] = $this->decide("quiero ir el {$fecha} a las {$hora}", $wamid);
        $this->commit($m, [
            'strategy_goal' => 'book_visit', 'next_best_action' => 'offer_appointment',
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => $fecha, 'courtesy_time' => $hora,
            'reply_draft' => 'Listo, el equipo revisa tu solicitud de visita y te confirma.',
        ]);

        return MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->where('marketing_lead_id', $this->lead->id)->latest('id')->firstOrFail();
    }

    /**
     * El relleno de la cortesía sólo usa lo que se está RECOGIENDO. Con una
     * visita ya solicitada (viernes 18:00), «el viernes no puedo, mejor el
     * miércoles a las 10» con el modelo en null/null no reescribe el viernes
     * que la persona acaba de rechazar, y «quiero volver a ir el jueves» no
     * registra las 18:00 que nadie dijo para el jueves. El flujo legítimo —el
     * día en un turno y la hora en el siguiente— sigue registrando.
     */
    public function test_33_la_franja_ya_registrada_no_rellena_la_cortesia(): void
    {
        $solicitud = $this->conVisitaSolicitadaAqui('2026-09-25', '18:00', 'w.as.33a');
        $franja = fn (): array => [data_get($solicitud->fresh()->metadata, 'requested_date'), data_get($solicitud->fresh()->metadata, 'requested_time')];

        // Justo después de solicitar el viernes: el jueves sin hora no se registra con las 18:00 del viernes.
        ['message' => $m] = $this->decide('quiero volver a ir el jueves', 'w.as.33b');
        $this->commit($m, [
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => '2026-09-24', 'courtesy_time' => null,
            'reply_draft' => '¡Qué bien! ¿A qué hora te queda bien el jueves?',
        ]);
        $this->assertSame(['2026-09-25', '18:00'], $franja(), 'se registró el jueves con una hora que nadie dijo');
        $this->assertSame(1, MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->where('marketing_lead_id', $this->lead->id)->count());

        // Un «dale» sin hora tampoco: el objetivo en recogida no heredó las 18:00 de la visita solicitada.
        ['message' => $m2] = $this->decide('dale', 'w.as.33c');
        $this->commit($m2, [
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => '¿A qué hora te queda bien el jueves?',
        ]);
        $this->assertSame(['2026-09-25', '18:00'], $franja(), 'la hora de la visita vieja se coló en el jueves');

        // Con dudas y otro día nombrado, nada se rellena: ni el viernes rechazado ni el jueves recogido.
        ['message' => $m3] = $this->decide('el viernes no puedo, mejor el miércoles a las 10', 'w.as.33d');
        $this->commit($m3, [
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Perfecto, dejo solicitada tu visita para el miércoles a las 10:00 a. m.; el equipo la confirma.',
        ]);
        $this->assertSame(['2026-09-25', '18:00'], $franja(), 'se reescribió la visita con un día que la persona no pidió');
        $this->assertStringNotContainsString('dejo solicitada', mb_strtolower($this->ultimo()));

        // La hora llega sola en el turno siguiente: el día recogido la completa y se registra.
        ['message' => $m4] = $this->decide('a las 5 pm', 'w.as.33e');
        $this->commit($m4, [
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => null, 'courtesy_time' => null,
            'reply_draft' => 'Listo, el equipo revisa tu solicitud de visita y te confirma.',
        ]);
        $this->assertSame(['2026-09-24', '17:00'], $franja());
    }

    /**
     * La visita que el equipo ya confirmó en ESTA conversación: la plantilla
     * de confirmada («… Te esperamos.») sale intacta, sin el cierre retirado
     * ni la confirmación repetida detrás.
     */
    public function test_34_la_plantilla_de_confirmada_sale_entera_en_la_misma_conversacion(): void
    {
        $solicitud = $this->conVisitaSolicitadaAqui('2026-09-23', '14:00', 'w.as.34a');
        // El equipo la confirma desde la agenda: la MISMA cita pasa a confirmada.
        $solicitud->forceFill(['status' => MarketingAppointment::STATUS_SCHEDULED])->save();

        $plantilla = 'Claro. El equipo ya confirmó tu visita: miércoles 23 de septiembre a las 14:00. Te esperamos.';
        ['message' => $m] = $this->decide('me recuerdas a qué hora era mi visita?', 'w.as.34b');
        $this->commit($m, ['intent' => SalesIntents::SCHEDULE_QUESTION, 'reply_draft' => $plantilla]);

        $this->assertSame($plantilla, $this->ultimo());
        $this->assertArrayNotHasKey('courtesy_confirmation_dropped', $this->meta());
    }

    /**
     * Con la visita del miércoles solicitada y un cambio que la autoridad
     * rechaza (el jueves a las 11 pm está cerrado), el acta que escribe
     * Laravel sale entera: la guarda de constancia ya no le quita su primera
     * oración. Y la negación honesta («todavía no queda registrada») sale.
     */
    public function test_35_el_acta_de_la_solicitud_sale_entera_y_la_negacion_honesta_tambien(): void
    {
        $this->conVisitaSolicitadaAqui('2026-09-23', '14:00', 'w.as.35a');

        ['message' => $m] = $this->decide('mejor el jueves a las 11 pm', 'w.as.35b');
        $this->commit($m, [
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => '2026-09-24', 'courtesy_time' => '23:00',
            'reply_draft' => 'Listo, quedaste agendado para el jueves a las 11 pm, te esperamos.',
        ]);
        $t = mb_strtolower($this->ultimo());
        $this->assertStringNotContainsString('quedaste agendado', $t);
        $this->assertStringContainsString('dejé registrada tu solicitud de cortesía para el miércoles 23 de septiembre a las 2:00 p. m.', $t, 'el acta salió mutilada');

        ['message' => $m2] = $this->decide('y el jueves a las 11 pm?', 'w.as.35c');
        $this->commit($m2, [
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => '2026-09-24', 'courtesy_time' => '23:00',
            'reply_draft' => 'Todavía no queda registrada tu visita del jueves: a las 11 pm ya cerramos. ¿Te sirve más temprano?',
        ]);
        $this->assertStringContainsString('Todavía no queda registrada tu visita del jueves', $this->ultimo(), 'se retiró la verdad negada');
    }

    /**
     * Si una guarda retira la frase donde se pegaron los enlaces de la app, no
     * salieron: no se anotan, y en la hora siguiente no se dice «te los dejé
     * aquí mismo». El control: sin guarda de por medio, sí se anotan.
     */
    public function test_36_los_enlaces_que_no_salieron_no_se_anotan(): void
    {
        $this->conVisitaSolicitadaAqui('2026-09-26', '10:00', 'w.as.36a');

        ['message' => $m] = $this->decide('mándame la app', 'w.as.36b');
        $this->commit($m, [
            'tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND],
            'reply_draft' => 'Listo. Te esperamos el sábado a las 10 y mientras tanto descarga la app: {{APP_LINKS}}',
        ]);
        $this->assertStringNotContainsString('http', $this->ultimo(), 'la frase con los enlaces debía retirarse');
        $this->assertNull(data_get($this->conversation->fresh()->memory, 'app_context.links_sent_at'), 'se anotaron enlaces que no salieron');

        ['message' => $m2] = $this->decide('pásame la app otra vez', 'w.as.36c');
        $this->commit($m2, ['tools_requested' => [SalesIntents::TOOL_APP_LINKS_SEND], 'reply_draft' => 'Claro, aquí la tienes: {{APP_LINKS}}']);
        $this->assertStringContainsString('http', $this->ultimo());
        $this->assertNotNull(data_get($this->conversation->fresh()->memory, 'app_context.links_sent_at'));
    }

    /** Sin ninguna solicitud, «tu visita quedó registrada» tampoco sale: no hay nada registrado. */
    public function test_37_sin_solicitud_la_visita_no_se_da_por_registrada(): void
    {
        ['message' => $m] = $this->decide('gracias', 'w.as.37a');
        $this->commit($m, ['intent' => SalesIntents::THANKS, 'reply_draft' => 'Listo, tu visita quedó registrada para el sábado a las 10.']);
        $this->assertStringNotContainsString('quedó registrada', $this->ultimo());
    }

    /**
     * «Sin problema, cancelé tu visita del sábado, ¿algo más?» con la visita
     * ya confirmada (la herramienta no la toca): la coletilla no convierte en
     * pregunta lo que se afirmó, y el turno no sale. Ofrecerlo, sí.
     */
    public function test_38_cancelar_con_coletilla_tampoco_sale_sin_haberlo_hecho(): void
    {
        MarketingAppointment::create([
            'marketing_lead_id' => $this->lead->id, 'marketing_conversation_id' => $this->conversation->id,
            'type' => MarketingAppointment::TYPE_VISIT, 'title' => 'Día de cortesía',
            'scheduled_at' => Carbon::parse('2026-09-26 10:00', 'America/Bogota')->setTimezone('UTC'), 'status' => MarketingAppointment::STATUS_SCHEDULED,
        ]);
        ['message' => $m] = $this->decide('cancela mi visita del sábado', 'w.as.38a');
        $r = $this->commitCrudo($m, ['reply_draft' => 'Sin problema, cancelé tu visita del sábado, ¿algo más?']);
        $r->assertStatus(422);
        $this->assertSame('promised_effect_without_authority', $r->json('code'));

        ['message' => $m2] = $this->decide('cancela mi visita del sábado', 'w.as.38b');
        $this->commit($m2, ['reply_draft' => 'Tu visita del sábado ya la confirmó el equipo. ¿Quieres que la cancele?']);
        $this->assertStringContainsString('¿Quieres que la cancele?', $this->ultimo());
    }

    /**
     * Con el viernes recogido (sin hora) y el modelo trayendo sólo la hora,
     * «el viernes no puedo, mejor el sábado a las 10» no se registra el viernes
     * a las 10: el texto nombra otro día, y lo recogido no rellena un dato que
     * la persona acaba de cambiar.
     */
    public function test_39_lo_recogido_no_rellena_el_dia_que_la_persona_cambia(): void
    {
        ['message' => $m] = $this->decide('quiero ir el viernes', 'w.as.39a');
        $this->commit($m, [
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => '2026-09-25', 'courtesy_time' => null,
            'reply_draft' => '¡Genial! ¿A qué hora te queda bien el viernes?',
        ]);
        $this->assertSame(0, MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->where('marketing_lead_id', $this->lead->id)->count());

        ['message' => $m2] = $this->decide('el viernes no puedo, mejor el sábado a las 10', 'w.as.39b');
        $this->commit($m2, [
            'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST], 'courtesy_action' => 'request',
            'courtesy_date' => null, 'courtesy_time' => '10:00',
            'reply_draft' => '¿Entonces el sábado a las 10:00 a. m.?',
        ]);
        $this->assertSame(0, MarketingAppointment::query()->where('source', MarketingAppointment::SOURCE_ULTRON)->where('marketing_lead_id', $this->lead->id)->count(), 'se registró el viernes que la persona cambió');
    }
}
