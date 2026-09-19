<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ULTRON no ve cifras, tampoco las suyas.
 *
 * La invariante del sistema es que el modelo nunca lee un precio: `active_plans`
 * viaja sin `price` y el borrador escribe `{{PLAN_PRICE}}`, que Laravel sustituye
 * DESPUÉS de validar. Pero el mensaje que sale sí lleva la cifra real, se guarda
 * como mensaje de máquina y al turno siguiente vuelve por `context.recent_messages`:
 * la invariante se rompía por la puerta de atrás, y el modelo aprendía del historial
 * un número que su propio guard de salida (`machine_reply_invented_price`) le prohíbe
 * escribir, dejando a la persona sin respuesta ese turno.
 *
 * Aquí se fija el contrato del historial: de MÁQUINA no sale ninguna cifra; del LEAD
 * no se toca nada, porque «tengo 50.000» es el dato con el que se le entiende.
 */
class UltronPriceRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    /** El precio real del catálogo, tal y como lo escribe el CRM: «$80.000 COP». */
    private const FIGURE = '80.000';

    /**
     * Lo que el modelo ve donde había una cifra.
     *
     * NO es el marcador que Laravel resuelve, y esa es toda la gracia: «esto
     * parece un precio» es una heurística, así que un año o un documento del
     * historial se convertirían en el precio REAL del plan si el marcador fuera
     * resoluble y el modelo copiara la frase.
     */
    private const MARK = '[precio]';

    private Plan $plan;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.inbound.auto_analyze', false);
        Http::fake();

        $this->plan = Plan::create([
            'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true,
            'benefits' => json_encode(['Acceso ilimitado al gimnasio', 'Reserva de clases grupales']),
        ]);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function inbound(string $body, string $wamid): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received',
        ]);
    }

    /** Un mensaje YA enviado por el CRM, como el que queda en la tabla tras un turno. */
    private function machine(string $body, array $metadata = []): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id, 'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'sender_type' => MarketingMessage::SENDER_AI, 'body' => $body, 'status' => 'sent',
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    private function decide(MarketingMessage $m): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->h());
    }

    private function commit(MarketingMessage $m, array $proposal, ?string $token = null): TestResponse
    {
        $token ??= $this->decide($m)->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id, 'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id, 'decide_token' => $token,
            'proposal' => array_merge([
                'strategy_goal' => 'collect_data', 'next_state' => P::RECOMMENDATION, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te cuento.', 'recommended_plan_id' => null, 'main_barrier' => null,
                'next_best_action' => 'recommend_plan', 'confidence' => 0.8, 'tools_requested' => [],
            ], $proposal),
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $this->h());
    }

    /** El historial tal y como lo recibe el modelo en el turno siguiente. */
    private function recentMessages(string $wamid): array
    {
        return (array) $this->decide($this->inbound('ok', $wamid))->assertOk()->json('context.recent_messages');
    }

    /** El cuerpo, ya redactado, del mensaje que contiene $tag. */
    private function bodyContaining(string $tag, array $recent): string
    {
        foreach ($recent as $entry) {
            if (str_contains((string) ($entry['body'] ?? ''), $tag)) {
                return (string) $entry['body'];
            }
        }

        $this->fail("ningún mensaje de recent_messages contiene «{$tag}»: ".json_encode($recent, JSON_UNESCAPED_UNICODE));
    }

    /**
     * El turno real: el CRM cotiza, el mensaje sale con la cifra, y al turno
     * siguiente esa cifra NO puede estar en lo que se le manda al modelo.
     */
    public function test_a_price_the_crm_already_sent_never_returns_to_the_model(): void
    {
        $m1 = $this->inbound('hola, cuanto vale el plan?', 'red.1');
        $this->commit($m1, [
            'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Claro. El {{PLAN_NAME}} está en {{PLAN_PRICE}}. ¿Quieres que te cuente más detalles?',
        ])->assertOk();

        $sent = MarketingMessage::where('conversation_id', $this->conversation->id)
            ->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->latest('id')->first();
        $this->assertNotNull($sent);
        $this->assertStringContainsString('$'.self::FIGURE.' COP', (string) $sent->body, 'lo que SALE sí lleva el precio real: eso es correcto');

        $recent = $this->recentMessages('red.2');
        $historial = json_encode($recent, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::FIGURE, $historial, 'la cifra que el CRM ya dijo no puede volver al modelo por recent_messages');
        $this->assertStringContainsString(self::MARK, $historial, 'en su lugar va el mismo marcador que el sistema ya sustituye al redactar');

        $redactado = $this->bodyContaining('El Plan Mensual', $recent);
        $this->assertSame('Claro. El Plan Mensual está en '.self::MARK.'. ¿Quieres que te cuente más detalles?', $redactado, 'el modelo sigue leyendo una frase entera: pierde la cifra, no el sentido');
    }

    /** Lo que el sistema recuerda de sus propias cotizaciones no cambia: solo deja de verse la CIFRA. */
    public function test_the_model_still_knows_which_plan_it_quoted(): void
    {
        $m1 = $this->inbound('hola, cuanto vale el plan?', 'mem.1');
        $this->commit($m1, [
            'intent' => SalesIntents::PRICING_QUESTION, 'recommended_plan_id' => $this->plan->id,
            'reply_draft' => 'Claro. El {{PLAN_NAME}} está en {{PLAN_PRICE}}. ¿Quieres que te cuente más detalles?',
        ])->assertOk();

        $d = $this->decide($this->inbound('ok', 'mem.2'))->assertOk();

        $this->assertSame([$this->plan->id], array_column((array) $d->json('context.memory.structured.prices_delivered'), 'plan_id'), 'prices_delivered sigue diciendo QUÉ plan se cotizó');
        $this->assertSame([$this->plan->id], (array) $d->json('context.novelty.must_not_repeat.price_of_plans'), 'y la novedad sigue impidiendo repetirlo');
    }

    /**
     * Toda forma de escribir un precio, no solo la del CRM: el historial de
     * máquina se limpia con la MISMA definición de «esto parece un precio» que
     * usa el guard de salida ({@see SalesAgentDecisionSchema::PRICE_PATTERN}).
     */
    #[DataProvider('priceShapes')]
    public function test_every_shape_of_a_price_leaves_the_machine_history(string $body, string $figure): void
    {
        $this->machine($body);

        $redactado = $this->bodyContaining('El plan queda en', $this->recentMessages('shape.1'));

        $this->assertStringNotContainsString($figure, $redactado);
        $this->assertStringContainsString(self::MARK, $redactado);
        $this->assertSame('El plan queda en '.self::MARK.' al mes.', $redactado, 'redactado, pero legible: no queda vacío ni troceado');
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function priceShapes(): array
    {
        return [
            'separador de miles' => ['El plan queda en 80.000 al mes.', '80.000'],
            'con signo de peso' => ['El plan queda en $80000 al mes.', '80000'],
            'con cop' => ['El plan queda en 80000 COP al mes.', '80000'],
            'con pesos' => ['El plan queda en 80000 pesos al mes.', '80000'],
            'formato real del CRM' => ['El plan queda en $80.000 COP al mes.', '80.000'],
            'millon con dos separadores' => ['El plan queda en 1.200.000 al mes.', '1.200.000'],
        ];
    }

    /** URL y cifra en el mismo mensaje: las dos quedan tapadas, y el link sigue anunciándose como tal. */
    public function test_a_machine_message_with_a_url_and_a_price_hides_both(): void
    {
        $this->machine('Te dejo el link: https://checkout.wompi.co/l/abc123 para pagar los $80.000 COP.', ['kind' => 'payment_link']);
        $this->machine('Descarga la app en https://play.google.com/store y el plan queda en $80.000 COP.');

        $historial = json_encode($this->recentMessages('url.1'), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('checkout.wompi.co', $historial, 'una URL pagable no vuelve al modelo');
        $this->assertStringNotContainsString('play.google.com', $historial);
        $this->assertStringNotContainsString(self::FIGURE, $historial, 'y la cifra tampoco, aunque viaje junto a la URL');
        $this->assertStringContainsString('[link de pago enviado]', $historial);
        $this->assertStringContainsString('[enlace enviado por el CRM]', $historial);
    }

    /** Lo que dice la PERSONA sobre su dinero es dato, no fuga: se le entrega intacto al modelo. */
    public function test_what_the_lead_says_about_money_is_never_redacted(): void
    {
        $this->inbound('tengo 50.000, me alcanza para el mensual?', 'lead.1');

        $recent = $this->recentMessages('lead.2');

        $this->assertSame('tengo 50.000, me alcanza para el mensual?', $this->bodyContaining('me alcanza', $recent), 'sin su presupuesto el modelo no puede entenderla');
        $this->assertStringNotContainsString(self::MARK, $this->bodyContaining('me alcanza', $recent));
    }

    /** Sin cifras no hay redacción: un mensaje de máquina normal llega palabra por palabra. */
    public function test_a_machine_message_without_figures_comes_back_untouched(): void
    {
        $texto = 'Abrimos de lunes a viernes de 5 am a 10 pm y el sábado hasta el mediodía.';
        $this->machine($texto);

        $this->assertSame($texto, $this->bodyContaining('Abrimos de lunes', $this->recentMessages('plain.1')));
    }
}
