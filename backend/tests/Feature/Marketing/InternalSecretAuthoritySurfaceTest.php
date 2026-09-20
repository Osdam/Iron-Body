<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\OutboundContentGuard;
use App\Services\Marketing\SalesPaymentReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * QUÉ ABRE DE VERDAD EL SECRETO INTERNO.
 *
 * El diseño de ULTRON se cuenta así: n8n solo tiene dos puertas, `ai/decide` y
 * `ai/commit`, y el dinero lo decide Laravel. Eso es cierto del WORKFLOW. No lo
 * es del SECRETO: `automation.internal_secret` abre todo el grupo
 * `internal/marketing/*`, donde viven desde la Fase 1.5 dos endpoints que
 * generan y envían enlaces de pago reales, y que NO consultan la bandera del
 * negocio (`MARKETING_ULTRON_PAYMENT_LINKS_ENABLED`), solo los guardrails de
 * pago.
 *
 * Así era hasta RC-2, y este archivo lo fijó para que la decisión de cerrarlo
 * fuera del dueño y no de una prueba. El dueño decidió cerrarlo: la bandera
 * manda ahora sobre TODOS los caminos, y los dos casos de abajo —que antes
 * documentaban el agujero— documentan su cierre. La asimetría estaba escrita al
 * revés en un comentario de `routes/marketing.php`, y una garantía que solo
 * vive en un comentario es la que se descubre el día malo.
 *
 * Lo que el secreto SIGUE abriendo, y conviene tener escrito: toda la superficie
 * `internal/marketing/*` con una sola llave compartida, incluida la base de
 * conocimiento —acotada en RC-4 a PROPONER borradores, nunca a publicar— y la
 * puerta por la que entra texto de máquina hacia el cliente.
 *
 * Lo que sí se comprueba aquí como garantía real: el camino de ULTRON respeta
 * la bandera, y el guard de salida tapa lo que el modelo no puede decir.
 */
class InternalSecretAuthoritySurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private Plan $plan;

    private MarketingLead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'production',
            'public_key' => 'pub_prod_test_key',
            'private_key' => 'prv_prod_test_key',
            'integrity_secret' => 'integrity_test_secret',
            'events_secret' => 'events_test_secret',
            'checkout' => array_merge((array) config('wompi.checkout'), [
                'base_url' => 'https://checkout.wompi.co/p/',
                'redirect_url' => 'https://web.ironbodyneiva.cloud/pago',
            ]),
        ]));
        // La bandera del negocio, APAGADA: es el estado de producción hoy.
        config()->set('marketing.ultron.payment_links_enabled', false);
        Http::fake();

        $this->plan = Plan::create([
            'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30,
            'active' => true, 'sellable' => true,
        ]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
    }

    /** @return array<string,string> */
    private function h(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    // ── Lo que la bandera SÍ gobierna ────────────────────────────────────────

    public function test_with_the_flag_off_ultron_is_not_even_offered_the_payment_tool(): void
    {
        $readiness = app(SalesPaymentReadinessService::class);

        $this->assertSame('production_ready', $readiness->state(), 'Wompi está listo: lo único que falta es el permiso del negocio');
        $this->assertFalse($readiness->canGenerateAutomaticLink(), 'la bandera apagada retira el permiso');

        $conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        $message = MarketingMessage::create([
            'conversation_id' => $conversation->id, 'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD, 'body' => 'quiero pagar ya',
            'meta_message_id' => 'surface.1', 'status' => 'received',
        ]);

        $d = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $conversation->id, 'message_id' => $message->id,
        ], $this->h())->assertOk();

        $this->assertNotContains('payment_link_send', $d->json('allowed_tools'));
        $this->assertFalse($d->json('context.flags.can_offer_link'));
    }

    // ── Lo que la bandera TAMPOCO deja pasar desde RC-2 ──────────────────────

    /**
     * Lo que este caso afirmaba: con la bandera apagada, el secreto interno
     * todavía acuñaba un enlace de pago REAL —y en producción Wompi está
     * productivo con llaves de verdad—. Ya no: apagar la bandera apaga el
     * dinero por todos los caminos, y el rechazo no deja fila de cobro.
     */
    public function test_the_internal_secret_no_longer_mints_a_real_payment_link_with_the_flag_off(): void
    {
        $r = $this->postJson('/api/internal/marketing/payment-links', [
            'marketing_lead_id' => $this->lead->id, 'plan_id' => $this->plan->id,
        ], $this->h());

        $r->assertStatus(403)->assertJsonPath('code', 'payment_links_disabled');
        $this->assertNull($r->json('payment_url'));
        $this->assertSame(0, PaymentTransaction::count(), 'no se acuñó ninguna fila de cobro');
        $this->assertFalse(app(SalesPaymentReadinessService::class)->canGenerateAutomaticLink(), 'la bandera es la que manda');
    }

    /** Lo mismo, pero además dejando el mensaje preparado para la persona. */
    /** Y la variante que además lo entregaba por WhatsApp tampoco prepara nada. */
    public function test_the_internal_secret_does_not_even_prepare_the_message_with_the_flag_off(): void
    {
        $r = $this->postJson('/api/internal/marketing/payment-links/send', [
            'marketing_lead_id' => $this->lead->id, 'plan_id' => $this->plan->id,
        ], $this->h());

        $r->assertStatus(403)
            ->assertJsonPath('code', 'payment_links_disabled')
            ->assertJsonPath('sent', false);

        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count());
        $this->assertSame(0, PaymentTransaction::count());
    }

    /** El secreto es uno solo: no hay un permiso por puerta. */
    public function test_one_secret_opens_the_whole_internal_surface(): void
    {
        foreach ([
            '/api/internal/marketing/meta/doctor',
            '/api/internal/marketing/ai/doctor',
            '/api/internal/marketing/knowledge/doctor',
        ] as $ruta) {
            $this->getJson($ruta, $this->h())->assertOk();
            $this->getJson($ruta)->assertStatus(401);
        }
    }

    // ── La otra puerta por la que entra texto de máquina ─────────────────────

    /**
     * `send-message` comparte el guard de salida con `commit`, así que una
     * oferta de traspaso no sale por ahí tampoco. Es la garantía que sí existe.
     */
    public function test_machine_text_sent_through_the_other_door_still_meets_the_outbound_guard(): void
    {
        $r = $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $this->lead->id,
            'body' => 'Te paso con un asesor para que te explique.',
            'sender_type' => MarketingMessage::SENDER_AI,
        ], $this->h());

        $r->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_UNAUTHORIZED_HANDOFF);
        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count());
    }

    /**
     * Las dos puertas comparten filtro DE VERDAD, no solo en el comentario.
     *
     * Hasta este cierre, `inspect()` miraba traspaso, acciones prohibidas,
     * afirmaciones inseguras y cifras; las URLs y la petición de datos de
     * tarjeta solo las comprobaba `commit`, por separado, antes de llamarlo.
     * Resultado: por `send-message` salía un enlace que nadie había verificado,
     * y por la salida de respuestas CURADAS también. Ahora las dos viven en el
     * filtro compartido.
     *
     * @param  string  $body  texto de máquina que no puede salir por ninguna puerta
     */
    #[DataProvider('textosDeMaquinaQueNoPuedenSalir')]
    public function test_both_doors_share_the_same_filter(string $body, string $code): void
    {
        $r = $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $this->lead->id,
            'body' => $body,
            'sender_type' => MarketingMessage::SENDER_AI,
        ], $this->h());

        $r->assertStatus(422)->assertJsonPath('code', $code);
        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count(), $body);
    }

    /** @return iterable<string, array{string, string}> */
    public static function textosDeMaquinaQueNoPuedenSalir(): iterable
    {
        yield 'url' => ['Mira esto: https://ejemplo.co/promo', OutboundContentGuard::CODE_URL_IN_REPLY];
        yield 'dominio sin esquema' => ['Entra a ejemplo.co/promo y listo.', OutboundContentGuard::CODE_URL_IN_REPLY];
        yield 'datos de tarjeta' => ['Mándame los 16 dígitos por aquí.', OutboundContentGuard::CODE_CARD_DATA_REQUEST];
        yield 'cvv' => ['Pásame el CVV de la tarjeta.', OutboundContentGuard::CODE_CARD_DATA_REQUEST];
        yield 'traspaso' => ['Te paso con un asesor para que te explique.', OutboundContentGuard::CODE_UNAUTHORIZED_HANDOFF];
        yield 'precio inventado' => ['El plan sale en 80.000 pesos.', OutboundContentGuard::CODE_INVENTED_PRICE];
    }

    /**
     * Por esa puerta no se puede entrar fingiéndose una persona.
     *
     * El endpoint fija el autor en máquina y NO lo acepta del request, que es
     * lo que impide saltarse el filtro declarándose humano. Conviene tenerlo
     * fijado: es una decisión fácil de deshacer sin darse cuenta.
     */
    public function test_that_door_always_writes_as_a_machine_whatever_the_caller_claims(): void
    {
        $r = $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $this->lead->id,
            'body' => 'Mira esto: https://ejemplo.co/promo',
            'sender_type' => MarketingMessage::SENDER_HUMAN,
        ], $this->h());

        $r->assertStatus(422)->assertJsonPath('code', OutboundContentGuard::CODE_URL_IN_REPLY);
        $this->assertSame(0, MarketingMessage::where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count());

        $ok = $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $this->lead->id,
            'body' => 'Claro, con gusto te cuento.',
            'sender_type' => MarketingMessage::SENDER_HUMAN,
        ], $this->h())->assertOk();

        $this->assertSame(MarketingMessage::SENDER_AI, $ok->json('sender_type'), 'el autor lo fija el backend');
    }
}
