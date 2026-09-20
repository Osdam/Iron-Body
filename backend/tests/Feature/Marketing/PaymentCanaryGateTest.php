<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Marketing\SalesGuardrailException;
use App\Services\Marketing\SalesPaymentGuardrailService;
use App\Services\Marketing\WompiPaymentLinkService;
use App\Services\Wompi\PaymentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El canario del cobro: UNA conversación puede acuñar un cobro real, y sólo
 * una.
 *
 * Lo que se mide aquí no es que el canario funcione —eso es la mitad fácil—
 * sino que TODO LO DEMÁS siga cerrado, y que una llamada directa al generador
 * no pueda saltárselo por llegar desde dentro. Un cerrojo que se rodea
 * llamando a otra puerta no es un cerrojo.
 */
class PaymentCanaryGateTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private MarketingLead $lead;

    private MarketingConversation $canaria;

    private MarketingConversation $otra;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        // Wompi «productivo» sin tocar la red: el checkout se firma en local.
        config()->set('wompi.env', 'production');
        config()->set('wompi.public_key', 'pub_prod_test_key');
        config()->set('wompi.private_key', 'prv_prod_test_key');
        config()->set('wompi.integrity_secret', 'integrity_test_secret');
        config()->set('wompi.events_secret', 'events_test_secret');
        config()->set('wompi.checkout.base_url', 'https://checkout.wompi.co/p/');
        config()->set('wompi.checkout.redirect_url', 'https://web.ironbodyneiva.cloud/pago');

        // El estado del canario: grifo general CERRADO, una conversación abierta.
        config()->set('marketing.ultron.payment_links_enabled', false);

        $this->plan = Plan::create([
            'name' => 'Trimestre', 'price' => 210000, 'duration_days' => 90,
            'active' => true, 'sellable' => true,
        ]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto', 'status' => MarketingLead::STATUS_HOT,
        ]);
        $this->canaria = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        $this->otra = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);

        config()->set('marketing.ultron.payment_canary_conversation_id', $this->canaria->id);
    }

    private function generar(?int $conversationId): array
    {
        return WompiPaymentLinkService::make()->generateForLead($this->lead, $this->plan, array_filter([
            'channel' => 'whatsapp',
            'conversation_id' => $conversationId,
        ], fn ($v) => $v !== null));
    }

    // ── A · la conversación del canario acuña ────────────────────────────────

    public function test_a_the_canary_conversation_can_mint_a_checkout(): void
    {
        $r = $this->generar($this->canaria->id);

        $this->assertTrue($r['authorized'] ?? false, json_encode($r));
        $this->assertNotEmpty($r['payment_url']);
        $this->assertStringContainsString('checkout.wompi.co/p/', $r['payment_url']);
        $this->assertSame(210000.0, (float) $r['amount']);
        $this->assertSame('COP', $r['currency']);
    }

    // ── B, C, D · todo lo demás, no ──────────────────────────────────────────

    /**
     * B · otra conversación, denegada.
     *
     * Y lo que importa tanto como el «no» es que se vaya con las manos
     * vacías: sin referencia, sin fila y sin URL. Una denegación que deja una
     * transacción a medias es una denegación que cuesta dinero de limpiar.
     */
    public function test_b_another_conversation_is_denied_and_leaves_nothing(): void
    {
        $antes = PaymentTransaction::count();

        $r = $this->generar($this->otra->id);

        $this->assertFalse($r['authorized'] ?? true);
        $this->assertSame('payment_outside_canary', $r['error']);
        $this->assertNull($r['payment_url']);
        $this->assertArrayNotHasKey('reference', $r);
        $this->assertSame($antes, PaymentTransaction::count(), 'ni una fila de transacción');
    }

    /** C · sin conversación, denegada: quien no dice para quién no cobra a nadie. */
    public function test_c_without_a_conversation_it_is_denied(): void
    {
        $antes = PaymentTransaction::count();

        $r = $this->generar(null);

        $this->assertFalse($r['authorized'] ?? true);
        $this->assertSame('payment_conversation_required', $r['error']);
        $this->assertNull($r['payment_url']);
        $this->assertSame($antes, PaymentTransaction::count());
    }

    /**
     * D · la llamada directa al guardrail tampoco se salta el canario.
     *
     * Es la prueba de la defensa en profundidad: el cerrojo no vive donde se
     * OFRECE la herramienta, vive donde se ACUÑA el cobro, así que da igual
     * por qué puerta se entre.
     */
    public function test_d_a_direct_internal_invocation_outside_the_canary_is_denied(): void
    {
        $guardrail = app(SalesPaymentGuardrailService::class);

        foreach ([
            ['conversation_id' => $this->otra->id],
            ['conversation_id' => null],
            [],
            ['origin' => SalesPaymentGuardrailService::ORIGIN_HUMAN_PANEL, 'admin_id' => 1],
        ] as $autoridad) {
            try {
                $guardrail->assertCanGeneratePaymentLink($this->lead, $this->plan, [], $autoridad);
                $this->fail('debió denegar: '.json_encode($autoridad));
            } catch (SalesGuardrailException $e) {
                $this->assertContains($e->errorCode, [
                    'payment_outside_canary', 'payment_conversation_required', 'payment_links_disabled',
                ], json_encode($autoridad));
            }
        }

        $this->assertSame(0, PaymentTransaction::count());
    }

    /**
     * D.bis · nombrar la conversación del canario no basta: tiene que ser TUYA.
     *
     * Sin esto el canario sería un NÚMERO, y un número se escribe. Quien
     * pudiera nombrar el id 19 acuñaría un cobro real para cualquier otra
     * persona: el cerrojo vería que el id coincide —coincide— y nadie miraría
     * de quién es.
     *
     * Hoy ningún camino lo permite, pero por accidente: el endpoint interno
     * vuelca `conversation_id` del cuerpo en las opciones del generador y sólo
     * se salva porque su pre-chequeo llama al guardrail sin la autoridad. La
     * pertenencia no depende de que nadie se olvide de nada.
     */
    public function test_d_bis_naming_the_canary_conversation_of_another_lead_is_denied(): void
    {
        $otroLead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3009999999',
            'meta_user_id' => '573009999999', 'name' => 'Otra persona', 'status' => MarketingLead::STATUS_NEW,
        ]);

        try {
            app(SalesPaymentGuardrailService::class)->assertCanGeneratePaymentLink(
                $otroLead,
                $this->plan,
                [],
                ['conversation_id' => $this->canaria->id],
            );
            $this->fail('debió denegar: la conversación del canario no es de ese lead');
        } catch (SalesGuardrailException $e) {
            $this->assertSame('payment_conversation_mismatch', $e->errorCode);
        }

        // Y por el generador entero, con las manos vacías.
        $r = WompiPaymentLinkService::make()->generateForLead($otroLead, $this->plan, [
            'channel' => 'whatsapp', 'conversation_id' => $this->canaria->id,
        ]);

        $this->assertFalse($r['authorized'] ?? true);
        $this->assertSame('payment_conversation_mismatch', $r['error']);
        $this->assertNull($r['payment_url']);
        $this->assertSame(0, PaymentTransaction::count(), 'ni una fila');
    }

    /** Y una conversación que no existe tampoco abre nada. */
    public function test_d_ter_a_conversation_that_does_not_exist_is_denied(): void
    {
        $r = $this->generar(999999);

        $this->assertFalse($r['authorized'] ?? true);
        $this->assertSame('payment_conversation_mismatch', $r['error']);
        $this->assertSame(0, PaymentTransaction::count());
    }

    // ── E · el monto no lo pone el modelo ────────────────────────────────────

    /** E · un monto venido de fuera se rechaza; el de la base de datos manda. */
    public function test_e_an_amount_from_outside_is_rejected(): void
    {
        $guardrail = app(SalesPaymentGuardrailService::class);

        foreach (['amount' => 1, 'amount_in_cents' => 100, 'price' => 1, 'total' => 1] as $campo => $valor) {
            try {
                $guardrail->assertCanGeneratePaymentLink($this->lead, $this->plan, [$campo => $valor], [
                    'conversation_id' => $this->canaria->id,
                ]);
                $this->fail('debió rechazar el campo '.$campo);
            } catch (SalesGuardrailException $e) {
                $this->assertSame('amount_not_allowed', $e->errorCode, $campo);
            }
        }

        // Y el que sale es el del plan, no el que nadie pidió.
        $this->assertSame(210000.0, (float) $this->generar($this->canaria->id)['amount']);
    }

    // ── F, G · el plan, leído de la base en el momento ───────────────────────

    /** F · un plan que deja de venderse deja de cobrarse, aunque sea el canario. */
    public function test_f_a_non_sellable_plan_is_denied_even_in_the_canary(): void
    {
        $this->plan->forceFill(['sellable' => false])->save();

        $r = $this->generar($this->canaria->id);

        $this->assertFalse($r['authorized'] ?? true);
        $this->assertSame('plan_not_sellable', $r['error']);
        $this->assertSame(0, PaymentTransaction::count());
    }

    /** G · si el precio cambia en la base, el checkout cobra el nuevo. */
    public function test_g_a_price_change_in_the_db_is_the_one_charged(): void
    {
        $this->plan->forceFill(['price' => 240000])->save();

        $r = $this->generar($this->canaria->id);

        $this->assertSame(240000.0, (float) $r['amount']);
        $this->assertStringContainsString('amount-in-cents=24000000', $r['payment_url']);
    }

    // ── H, I · idempotencia ──────────────────────────────────────────────────

    /** H · «mándamelo otra vez» reutiliza; no acuña una referencia nueva. */
    public function test_h_repeating_the_request_does_not_mint_a_second_checkout(): void
    {
        $primero = $this->generar($this->canaria->id);
        $segundo = $this->generar($this->canaria->id);
        $tercero = $this->generar($this->canaria->id);

        $this->assertSame($primero['reference'], $segundo['reference']);
        $this->assertSame($primero['reference'], $tercero['reference']);
        $this->assertSame(1, PaymentTransaction::count(), 'una sola transacción para las tres peticiones');
    }

    /** I · con el pago ya aprobado no se acuña otro checkout. Nunca. */
    public function test_i_an_approved_payment_never_mints_another_checkout(): void
    {
        $r = $this->generar($this->canaria->id);
        $tx = PaymentTransaction::where('reference', $r['reference'])->sole();
        $tx->forceFill(['status' => PaymentStateMachine::APPROVED])->save();

        $otro = $this->generar($this->canaria->id);

        $this->assertTrue($otro['already_paid'] ?? false);
        $this->assertNull($otro['payment_url']);
        $this->assertSame(1, PaymentTransaction::count());
    }

    // ── J · sin secretos ─────────────────────────────────────────────────────

    /**
     * J · el resultado lleva la llave PÚBLICA y la firma, que es lo que Wompi
     * necesita, y ningún secreto.
     *
     * La firma de integridad se calcula aquí con el secreto y viaja como
     * resultado; el secreto en sí no sale por ningún lado, ni en la URL, ni en
     * la respuesta, ni en la fila.
     */
    public function test_j_no_secret_is_exposed(): void
    {
        $r = $this->generar($this->canaria->id);
        $tx = PaymentTransaction::where('reference', $r['reference'])->sole();

        $superficie = json_encode($r).' '.json_encode($tx->toArray()).' '.(string) $tx->checkout_url;

        foreach (['integrity_test_secret', 'events_test_secret', 'prv_prod_test_key'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $superficie);
        }

        // Lo que SÍ tiene que estar, porque es lo que Wompi lee.
        $this->assertStringContainsString('public-key=pub_prod_test_key', (string) $tx->checkout_url);
        $this->assertStringContainsString('signature:integrity=', (string) $tx->checkout_url);
    }
}
