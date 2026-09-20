<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\MarketingMessageDispatcher;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LA RESPUESTA PERTENECE AL HILO EN EL QUE SE PREGUNTÓ.
 *
 * Esto lo encontró el canario físico, y no lo habría encontrado ninguna prueba
 * anterior porque todas daban al lead UNA sola conversación. En producción el
 * lead del canario tenía dos —una vieja cerrada y la abierta de hoy— y el
 * despachador resolvía con `firstOrCreate(['lead_id','channel'])`, que devuelve
 * la PRIMERA por orden de inserción: la vieja.
 *
 * El resultado no era un detalle de archivo. El mensaje llegaba bien a la
 * persona, porque Meta solo necesita el teléfono, pero se guardaba en el hilo
 * equivocado, y el modelo lee `recent_messages` del SUYO: dejaba de ver sus
 * propias respuestas y contestaba a ciegas sobre lo que ya había dicho. Siete
 * entrantes en una conversación y cuatro salientes en otra.
 */
class OutboundLandsInItsConversationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private MarketingLead $lead;

    private MarketingConversation $vieja;

    private MarketingConversation $actual;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::fake();

        Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true]);

        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);

        // La vieja se crea PRIMERO, como en producción: id menor y cerrada.
        $this->vieja = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'closed',
            'ai_enabled' => true, 'human_takeover' => false, 'last_message_at' => now()->subMonths(3),
        ]);
        $this->actual = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'last_message_at' => now(),
        ]);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function entrante(string $texto, string $wamid): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->actual->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $texto, 'meta_message_id' => $wamid, 'status' => 'received',
        ]);
    }

    private function decide(MarketingMessage $m): array
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->actual->id, 'message_id' => $m->id,
        ], $this->h())->assertOk()->json();
    }

    private function commit(MarketingMessage $m, string $draft): void
    {
        $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id, 'conversation_id' => $this->actual->id,
            'decide_token' => $this->decide($m)['decide_token'],
            'proposal' => [
                'strategy_goal' => 'collect_data', 'next_state' => P::GOAL_DISCOVERY,
                'intent' => SalesIntents::GENERAL_INFO, 'reply_draft' => $draft,
                'recommended_plan_id' => null, 'main_barrier' => null,
                'next_best_action' => 'ask_discovery', 'confidence' => 0.8, 'tools_requested' => [],
            ],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $this->h())->assertOk();
    }

    public function test_the_reply_is_filed_in_the_conversation_that_was_asked(): void
    {
        $m = $this->entrante('Hola, quiero información para empezar a entrenar', 'w.hilo.1');

        $this->commit($m, 'Claro, ¿cuál es tu objetivo al empezar a entrenar?');

        $saliente = MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->sole();
        $this->assertSame($this->actual->id, (int) $saliente->conversation_id, 'la respuesta va al hilo del turno');
        $this->assertSame(0, MarketingMessage::where('conversation_id', $this->vieja->id)->count(), 'y la conversación vieja no recibe nada');
    }

    /**
     * La consecuencia de verdad: el modelo vuelve a verse a sí mismo. Sin esto
     * el contexto del turno siguiente solo trae mensajes de la persona.
     */
    public function test_the_model_sees_its_own_reply_in_the_next_turn(): void
    {
        $primero = $this->entrante('Hola, quiero información', 'w.hilo.2');
        $this->commit($primero, 'Claro, ¿cuál es tu objetivo al empezar a entrenar?');

        $segundo = $this->entrante('quiero ganar masa', 'w.hilo.3');
        $recientes = $this->decide($segundo)['context']['recent_messages'] ?? [];

        $roles = array_map(fn ($r) => $r['role'] ?? $r['direction'] ?? '?', $recientes);
        $this->assertContains('ai', $roles, 'su propia respuesta tiene que estar en el contexto del turno siguiente');
        $this->assertStringContainsString('cuál es tu objetivo', json_encode($recientes, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Y para quien NO puede saber la conversación, el respaldo usa la regla del
     * dominio —abierta primero, luego la del último mensaje— en vez del orden
     * de inserción, que es lo que elegía la vieja.
     */
    public function test_without_an_explicit_conversation_the_fallback_picks_the_live_one(): void
    {
        $send = app(MarketingMessageDispatcher::class)->dispatchWhatsapp(
            $this->lead, 'whatsapp', 'Mensaje sin conversación explícita', ['kind' => 'text'],
        );

        $this->assertSame($this->actual->id, (int) ($send['conversation_id'] ?? 0));
        $this->assertSame(0, MarketingMessage::where('conversation_id', $this->vieja->id)->count());
    }
}
