<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolExecutor;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\WompiPaymentLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * EL CERROJO DEL ENLACE DE PAGO, CAMINO POR CAMINO.
 *
 * El estado de producción del día en que se escribe esto: Wompi con
 * credenciales REALES y en `production`, ULTRON apagado y la bandera del
 * negocio apagada. Es decir: lo único que separaba al sistema de acuñar un
 * cobro real era que nadie llamara a los endpoints correctos. La bandera
 * gobernaba la herramienta de ULTRON y nada más; el secreto interno
 * —`automation.internal_secret`, que es una máquina— generaba y enviaba
 * enlaces reales sin consultarla.
 *
 * Este archivo recorre TODOS los caminos que terminan en
 * {@see WompiPaymentLinkService::generateForLead()} y exige lo mismo de cada
 * uno: con la bandera apagada no hay transacción nueva ni URL de checkout.
 * Se comprueba el EFECTO (filas y mensajes), no el mensaje de error, porque lo
 * que hace daño es el cobro, no el texto.
 *
 * Y la otra mitad, que sin ella esto sería un cerrojo del que nadie sabe si
 * abre: con la bandera encendida y Wompi productivo, el camino legítimo sigue
 * funcionando.
 */
class PaymentLinkHardGateTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const ADMIN_TOKEN = 'test-admin-api-token';

    private Plan $plan;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('automation.internal_secret', self::SECRET);
        // El token compartido del CRM: lo usan las automatizaciones, y abre
        // /api/admin/* igual que una sesión de persona. Es una máquina.
        config()->set('admin.api_token', self::ADMIN_TOKEN);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.inbound.auto_analyze', false);

        // Wompi «productivo» SIN tocar la red: el Web Checkout se firma en
        // local y las llaves son de prueba. Nunca se llama a Wompi de verdad.
        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'production',
            'currency' => 'COP',
            'public_key' => 'pub_prod_test_key',
            'private_key' => 'prv_prod_test_key',
            'integrity_secret' => 'integrity_test_secret',
            'events_secret' => 'events_test_secret',
            'checkout' => array_merge((array) config('wompi.checkout'), [
                'base_url' => 'https://checkout.wompi.co/p/',
                'redirect_url' => 'https://web.ironbodyneiva.cloud/pago',
                'expiration_minutes' => 1440,
            ]),
        ]));

        // El subsistema Commercial, encendido: interesa probar la herramienta,
        // no que esté apagada por configuración.
        config()->set('commercial.autonomy_enabled', true);
        config()->set('commercial.tools', [
            'catalog' => true, 'lead' => true, 'payments' => true,
            'memberships' => true, 'agenda' => true, 'invoicing' => true, 'app' => true,
        ]);

        // LA BANDERA, APAGADA: el estado de producción.
        config()->set('marketing.ultron.payment_links_enabled', false);

        Http::fake();
        Http::preventStrayRequests();

        $this->plan = Plan::create([
            'name' => 'Plan Mensual', 'price' => 80000, 'duration_days' => 30,
            'active' => true, 'sellable' => true,
        ]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3150536026',
            'meta_user_id' => '573150536026', 'name' => 'Prospecto',
            'status' => MarketingLead::STATUS_HOT,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false, 'commercial_phase' => P::CLOSING,
        ]);
    }

    /** @return array<string,string> credencial de máquina del grupo internal/. */
    private function internal(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    /** @return array<string,string> credencial de máquina del grupo admin/. */
    private function adminMachine(): array
    {
        return ['Authorization' => 'Bearer '.self::ADMIN_TOKEN];
    }

    /**
     * Lo único que de verdad importa: no hay fila de cobro nueva y no hay una
     * sola URL de checkout escrita en ningún mensaje.
     */
    private function assertNothingMinted(string $camino): void
    {
        $this->assertSame(0, PaymentTransaction::count(), "{$camino}: se creó una transacción de cobro.");
        $this->assertSame(
            0,
            MarketingMessage::where('body', 'like', '%checkout.wompi.co%')->count(),
            "{$camino}: salió un mensaje con un enlace de checkout.",
        );
    }

    // ── Caminos de MÁQUINA con la bandera apagada ────────────────────────────

    /** Camino 1: POST internal/marketing/payment-links (n8n, secreto HMAC). */
    public function test_the_internal_mint_endpoint_cannot_mint_with_the_flag_off(): void
    {
        $r = $this->postJson('/api/internal/marketing/payment-links', [
            'marketing_lead_id' => $this->lead->id, 'plan_id' => $this->plan->id,
        ], $this->internal());

        $r->assertStatus(403)->assertJsonPath('ok', false)->assertJsonPath('code', 'payment_links_disabled');
        $this->assertNull($r->json('payment_url'));
        $this->assertNothingMinted('internal/payment-links');
    }

    /** Camino 2: POST internal/marketing/payment-links/send (genera Y entrega). */
    public function test_the_internal_send_endpoint_cannot_mint_or_deliver_with_the_flag_off(): void
    {
        $r = $this->postJson('/api/internal/marketing/payment-links/send', [
            'marketing_lead_id' => $this->lead->id, 'plan_id' => $this->plan->id,
        ], $this->internal());

        $r->assertStatus(403)->assertJsonPath('code', 'payment_links_disabled')->assertJsonPath('sent', false);
        $this->assertNothingMinted('internal/payment-links/send');
        Http::assertNothingSent();
    }

    /**
     * Camino 3: el endpoint del PANEL llamado con el token compartido del CRM.
     *
     * `EnsureAdminAuth` acepta dos credenciales: una sesión de persona y el
     * secreto `admin.api_token` para automatizaciones. La segunda es una
     * máquina con otro nombre, y no puede tener más poder que la bandera.
     */
    public function test_the_panel_route_with_the_shared_automation_token_is_a_machine_too(): void
    {
        $r = $this->postJson(
            "/api/admin/marketing/leads/{$this->lead->id}/payment-link",
            ['plan_id' => $this->plan->id],
            $this->adminMachine(),
        );

        // La para ANTES el mapa de autorización —el token compartido no trae la
        // llave de mercadeo—, así que ni llega al cerrojo del pago. El código es
        // otro; lo que importa es lo mismo: no se acuñó nada.
        $r->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->assertNothingMinted('admin/payment-link con token de automatización');
    }

    /** Camino 4: la herramienta del subsistema Commercial. */
    public function test_the_commercial_tool_cannot_mint_with_the_flag_off(): void
    {
        $result = app(ToolExecutor::class)->execute('create_payment_link', [
            'plan_id' => $this->plan->id,
        ], new ToolContext(
            lead: $this->lead,
            conversation: $this->conversation,
            requestedBy: 'engine',
            correlationId: 'hard-gate-1',
        ));

        $this->assertFalse($result->successful(), 'La herramienta entregó un enlace con la bandera apagada.');
        $this->assertNothingMinted('commercial/create_payment_link');
    }

    /** Camino 5: la herramienta de ULTRON, pedida por `ai/commit`. */
    public function test_ultron_commit_cannot_mint_with_the_flag_off(): void
    {
        $m = MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => 'dale, mándame el link', 'meta_message_id' => 'gate.1', 'status' => 'received',
        ]);

        $r = $this->commit($m);

        $this->assertLessThan(500, $r->status(), 'el rechazo no puede ser un 500');
        $this->assertNothingMinted('ultron ai/commit');
    }

    /** Camino 6: el orquestador legado (`ai/analyze-message` con auto_execute). */
    public function test_the_legacy_orchestrator_cannot_mint_with_the_flag_off(): void
    {
        $r = $this->postJson('/api/internal/marketing/ai/analyze-message', [
            'marketing_lead_id' => $this->lead->id,
            'conversation_id' => $this->conversation->id,
            'body' => 'quiero pagar el mensual',
            'plan_id' => $this->plan->id,
            'auto_execute' => true,
        ], $this->internal());

        $r->assertOk();
        $this->assertNothingMinted('ai/analyze-message auto_execute');
    }

    /**
     * Camino 7: el embudo. Cualquier llamador futuro —un job, un comando, una
     * herramienta nueva— pasa por aquí, y aquí se le dice que no.
     */
    public function test_the_funnel_itself_refuses_so_any_future_caller_is_covered(): void
    {
        $outcome = WompiPaymentLinkService::make()->generateForLead($this->lead, $this->plan, [
            'channel' => 'whatsapp',
        ]);

        $this->assertFalse($outcome['authorized'] ?? true, 'el embudo autorizó un enlace con la bandera apagada');
        $this->assertSame('payment_links_disabled', $outcome['error'] ?? null);
        $this->assertNull($outcome['payment_url']);
        $this->assertNothingMinted('WompiPaymentLinkService::generateForLead');
    }

    /** Ni siquiera reutiliza un enlace ya acuñado: no se refresca ni se devuelve. */
    public function test_an_already_minted_link_is_not_handed_out_again_with_the_flag_off(): void
    {
        config()->set('marketing.ultron.payment_links_enabled', true);
        $this->postJson('/api/internal/marketing/payment-links', [
            'marketing_lead_id' => $this->lead->id, 'plan_id' => $this->plan->id,
        ], $this->internal())->assertOk();
        $this->assertSame(1, PaymentTransaction::count());

        config()->set('marketing.ultron.payment_links_enabled', false);
        $r = $this->postJson('/api/internal/marketing/payment-links', [
            'marketing_lead_id' => $this->lead->id, 'plan_id' => $this->plan->id,
        ], $this->internal());

        $r->assertStatus(403);
        $this->assertNull($r->json('payment_url'), 'se devolvió el enlace en vuelo con la bandera apagada');
        $this->assertSame(1, PaymentTransaction::count(), 'no debería haber una segunda transacción');
    }

    // ── La excepción decidida y auditada: una PERSONA en el panel ────────────

    /**
     * El cerrojo es ABSOLUTO: ni siquiera una persona.
     *
     * Se consideró dejar pasar al administrador con sesión real —tiene nombre y
     * queda en el registro—, y se descartó: mientras la bandera esté apagada no
     * se acuña un cobro por NINGÚN camino, que es justo lo que hay que poder
     * demostrar antes de un canario. Encenderla es una línea del entorno, y el
     * panel vuelve al instante.
     */
    public function test_not_even_a_real_admin_session_mints_with_the_flag_off(): void
    {
        $admin = Admin::create([
            'name' => 'Asesora del CRM', 'email' => 'asesora-gate@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);

        $r = $this->postJson(
            "/api/admin/marketing/leads/{$this->lead->id}/payment-link",
            ['plan_id' => $this->plan->id],
            $this->actingAsAdmin($admin),
        );

        $r->assertStatus(403)->assertJsonPath('code', 'payment_links_disabled');
        $this->assertNothingMinted('admin/payment-link con sesión real de persona');
    }

    /** Y con la bandera encendida, esa misma persona sí puede. */
    public function test_with_the_flag_on_the_panel_works_again(): void
    {
        config()->set('marketing.ultron.payment_links_enabled', true);

        $admin = Admin::create([
            'name' => 'Asesora del CRM', 'email' => 'asesora-on@ironbody.test',
            'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active',
        ]);

        $r = $this->postJson(
            "/api/admin/marketing/leads/{$this->lead->id}/payment-link",
            ['plan_id' => $this->plan->id],
            $this->actingAsAdmin($admin),
        );

        $r->assertOk()->assertJsonPath('ok', true);
        $this->assertStringContainsString('checkout.wompi.co/p/', (string) $r->json('payment_url'));
        $this->assertSame(1, PaymentTransaction::count());
    }

    // ── El reverso: con permiso, el camino legítimo sigue abierto ────────────

    public function test_with_the_flag_on_the_machine_path_still_mints(): void
    {
        config()->set('marketing.ultron.payment_links_enabled', true);

        $r = $this->postJson('/api/internal/marketing/payment-links', [
            'marketing_lead_id' => $this->lead->id, 'plan_id' => $this->plan->id,
        ], $this->internal())->assertOk();

        $this->assertStringContainsString('checkout.wompi.co/p/', (string) $r->json('payment_url'));
        $this->assertStringContainsString('signature:integrity=', (string) $r->json('payment_url'));
        $this->assertSame(80000.0, (float) $r->json('amount'), 'el importe sale del catálogo');
        $this->assertSame(1, PaymentTransaction::count());
        Http::assertNothingSent();
    }

    public function test_with_the_flag_on_the_send_path_still_prepares_the_message(): void
    {
        config()->set('marketing.ultron.payment_links_enabled', true);

        $r = $this->postJson('/api/internal/marketing/payment-links/send', [
            'marketing_lead_id' => $this->lead->id, 'plan_id' => $this->plan->id,
        ], $this->internal())->assertOk();

        $this->assertTrue((bool) $r->json('dry_run'), 'con Meta apagado se prepara, no se entrega');
        $this->assertSame(1, MarketingMessage::where('body', 'like', '%checkout.wompi.co%')->count());
        $this->assertSame(1, PaymentTransaction::count());
    }

    /**
     * Wompi en sandbox no es «casi» producción: un enlace de sandbox entregado
     * como si fuera real es una venta que no cobra. Ni con la bandera puesta.
     */
    public function test_sandbox_never_becomes_a_link_even_with_the_flag_on(): void
    {
        config()->set('marketing.ultron.payment_links_enabled', true);
        config()->set('wompi.env', 'sandbox');

        $r = $this->postJson('/api/internal/marketing/payment-links', [
            'marketing_lead_id' => $this->lead->id, 'plan_id' => $this->plan->id,
        ], $this->internal());

        $r->assertStatus(403)->assertJsonPath('code', 'wompi_not_production');
        $this->assertNothingMinted('sandbox con la bandera encendida');
    }

    /** Propuesta de cierre de ULTRON pidiendo el enlace. */
    private function commit(MarketingMessage $m): TestResponse
    {
        $token = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->internal())->json('decide_token');

        return $this->postJson('/api/internal/marketing/ai/commit', [
            'source_type' => 'inbound_message', 'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id, 'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
            'proposal' => [
                'strategy_goal' => 'collect_payment', 'next_state' => P::CLOSING,
                'intent' => SalesIntents::PAYMENT_LINK_REQUEST,
                'reply_draft' => 'Listo, te paso el link de pago del {{PLAN_NAME}} para que lo hagas desde el celular.',
                'recommended_plan_id' => $this->plan->id, 'main_barrier' => null,
                'next_best_action' => 'recommend_plan', 'confidence' => 0.9,
                'tools_requested' => [SalesIntents::TOOL_PAYMENT_LINK_SEND],
            ],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9, 'issues' => [], 'hard_fail' => null],
        ], $this->internal());
    }
}
