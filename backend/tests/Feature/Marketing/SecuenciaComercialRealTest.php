<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\CommercialTurnPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LA CONVERSACIÓN REAL QUE SALIÓ MAL, DE PUNTA A PUNTA.
 *
 * Son los cuatro mensajes físicos, con su ortografía, y los BORRADORES que el
 * modelo escribió aquel día. El Critic los aprobó todos y aun así:
 *
 *   T1 · pidió información y recibió un interrogatorio sobre su objetivo.
 *   T2 · volvió a saludar, y ofreció el Plan Mensual cuando el insignia del
 *        CRM es otro.
 *   T3 y T4 · silencio.
 *
 * Aquí se le entrega a Laravel exactamente lo que escribió el modelo aquel
 * día. Lo que se mide es que el turno salga bien IGUALMENTE: la política es lo
 * que convierte una regla escrita en el prompt en un hecho del sistema.
 */
class SecuenciaComercialRealTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private MarketingConversation $conversation;

    private Plan $insignia;

    private Plan $mensual;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::fake();

        // El catálogo, como en el CRM: el insignia va marcado, no escrito en el código.
        Plan::create(['name' => 'Plan Semana', 'price' => 45000, 'duration_days' => 7, 'active' => true, 'sellable' => true, 'sort_order' => 1]);
        $this->mensual = Plan::create(['name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 3]);
        $this->insignia = Plan::create(['name' => 'TOTAL ACCESS - ELITE', 'price' => 180000, 'duration_days' => 30, 'active' => true, 'sellable' => true, 'sort_order' => 108, 'is_recommended' => true]);

        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);

        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::NEW_LEAD,
        ]);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function turno(string $texto, string $wamid, array $overrides = []): TestResponse
    {
        $m = MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $texto,
            'meta_message_id' => $wamid, 'status' => 'received',
        ]);
        $token = (string) $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->h())->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', array_replace_recursive([
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $wamid,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => [
                'next_state' => P::DISCOVERY, 'intent' => SalesIntents::GENERAL_INFO,
                'confidence' => 0.8, 'tools_requested' => [],
            ],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ], $overrides), $this->h());
    }

    private function ultimoSaliente(): string
    {
        return mb_strtolower((string) MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first()?->body);
    }

    private function salientes(): int
    {
        return MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count();
    }

    public function test_the_real_four_turn_sequence_goes_out_right(): void
    {
        // ── TURNO 1 ──────────────────────────────────────────────────────────
        // El borrador REAL: sólo pregunta el objetivo, no cuenta nada.
        $this->turno('Hola buenas tardes, quisiera informacion del gimnasio', 's.1', [
            'proposal' => ['reply_draft' => 'Buenas tardes, gracias por escribirnos. Para orientarte mejor, ¿cuál es tu objetivo principal al venir al gimnasio? Por ejemplo, ¿quieres bajar grasa, ganar músculo o mejorar tu condición física?'],
        ])->assertOk();

        $t1 = $this->ultimoSaliente();
        $this->assertSame(1, $this->salientes(), 'el primer turno se quedó sin respuesta');
        $this->assertStringNotContainsString('objetivo principal', $t1, 'siguió siendo un interrogatorio');
        $this->assertStringNotContainsString('mensual', $t1, 'apareció un plan que nadie pidió');
        $this->assertStringNotContainsString('total access', $t1, 'apareció un plan que nadie pidió');
        $this->assertStringContainsString('?', $t1, 'no dejó ninguna puerta abierta');

        // Y ya consta que saludamos: eso es lo que impide el segundo saludo.
        $this->assertNotNull($this->conversation->fresh()->memory['greeted'] ?? null);

        // ── TURNO 2 ──────────────────────────────────────────────────────────
        // El borrador REAL: vuelve a saludar y ofrece el Plan Mensual.
        $this->turno('todavia no tengo claro que objetivo tengo solo queria saber informacion del gimnasio como por ejemplo donde estan ubicados y sobre planes y cual es mas recomendado', 's.2', [
            'proposal' => [
                'reply_draft' => 'Buenas tardes, Iron Body Neiva está ubicado en Cl. 24 Sur #33-53. Entre los planes que ofrecemos, el Plan Mensual es uno de los más completos y recomendados. ¿Quieres que te cuente más detalles de este plan o de otro?',
                'recommended_plan_id' => $this->mensual->id,
            ],
        ])->assertOk();

        $t2 = $this->ultimoSaliente();
        $this->assertSame(2, $this->salientes(), 'el segundo turno se quedó sin respuesta');
        $this->assertStringNotContainsString('buenas tardes', $t2, 'saludó dos veces');
        $this->assertStringNotContainsString('plan mensual', $t2, 'ofreció el plan por defecto en vez del insignia');

        // ── TURNO 3 ──────────────────────────────────────────────────────────
        $this->turno('no hay otro plan mas recomendado? que ese', 's.3', [
            'proposal' => ['reply_draft' => 'El TOTAL ACCESS - ELITE sigue siendo el que más recomendamos.'],
        ])->assertOk();

        $this->assertSame(3, $this->salientes(), 'el tercer turno se quedó MUDO, como en la prueba real');
        $this->assertStringNotContainsString('total access', $this->ultimoSaliente(), 'insistió con el plan que acababa de rechazar');

        // ── TURNO 4 ──────────────────────────────────────────────────────────
        $this->turno('mencioname otro plan', 's.4', [
            'proposal' => [
                'reply_draft' => 'Claro, el TOTAL ACCESS - ELITE es el mejor para ti.',
                'recommended_plan_id' => $this->insignia->id,
            ],
        ])->assertOk();

        $this->assertSame(4, $this->salientes(), 'el cuarto turno se quedó MUDO, como en la prueba real');
        $this->assertStringNotContainsString('total access', $this->ultimoSaliente(), 'volvió a insistir con el mismo plan');
        $this->assertNotSame('', trim($this->ultimoSaliente()));
    }

    /** Y la política, leída directamente sobre los cuatro mensajes. */
    public function test_the_policy_reads_the_four_turns_as_it_should(): void
    {
        $planes = app(\App\Services\Marketing\MarketingKnowledgeBaseService::class)->activePlans();
        $memoria = \App\Services\Marketing\Ultron\ConversationMemory::fromArray(\App\Services\Marketing\Ultron\ConversationMemory::blank());

        $p1 = CommercialTurnPolicy::decide(SalesIntents::GENERAL_INFO, P::NEW_LEAD, $memoria, $planes, [], 'Hola buenas tardes, quisiera informacion del gimnasio');
        $this->assertTrue($p1['greet_required']);
        $this->assertTrue($p1['welcome_required']);
        $this->assertTrue($p1['answer_first']);
        $this->assertNull($p1['required_plan_id'], 'un primer «quiero información» no lleva plan');
        $this->assertContains('plan_recommendation', $p1['forbidden_actions']);
        $this->assertContains('checkout', $p1['forbidden_actions']);

        $saludada = \App\Services\Marketing\Ultron\ConversationMemory::fromArray(
            array_merge(\App\Services\Marketing\Ultron\ConversationMemory::blank(), ['greeted' => ['at' => 'x', 'message_id' => 1]]),
        );

        $p2 = CommercialTurnPolicy::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $saludada, $planes, [], 'sobre planes y cual es mas recomendado');
        $this->assertFalse($p2['greet_required']);
        $this->assertSame($this->insignia->id, $p2['required_plan_id'], 'el insignia del CRM no venció al plan por defecto');

        $p4 = CommercialTurnPolicy::decide(SalesIntents::GENERAL_INFO, P::DISCOVERY, $saludada, $planes, [], 'mencioname otro plan');
        $this->assertTrue($p4['wants_alternative']);
        $this->assertNull($p4['required_plan_id']);
        $this->assertContains($this->insignia->id, $p4['forbidden_plan_ids'], 'seguía pudiendo insistir con el mismo');
    }
}
