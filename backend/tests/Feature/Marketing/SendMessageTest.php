<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Wompi\PaymentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fase 1.5 — envío controlado de mensajes / link de pago por WhatsApp.
 * Con META deshabilitado/sin credenciales: dry_run (prepara, NO entrega).
 * Respeta do_not_contact y exige teléfono. Nunca activa membresía.
 */
class SendMessageTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;
    private MarketingLead $lead;

    private const INTERNAL_SECRET = 'test-internal-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox', 'currency' => 'COP',
            'public_key' => 'pub_test_link', 'integrity_secret' => 'test_integrity_link',
            'events_secret' => 'test_events_link', 'redirect_url' => 'https://app.ironbody.test/return',
            'checkout' => ['base_url' => 'https://checkout.wompi.co/p/', 'redirect_url' => null, 'expiration_minutes' => 1440],
        ]));

        config()->set('automation.internal_secret', self::INTERNAL_SECRET);
        // META deshabilitado por defecto en los tests (modo seguro).
        config()->set('meta.enabled', false);

        $this->plan = Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3215542105',
            'name' => 'Lead Demo', 'status' => MarketingLead::STATUS_INTERESTED,
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::INTERNAL_SECRET];
    }

    public function test_send_message_with_meta_disabled_is_dry_run_and_calls_no_provider(): void
    {
        Http::fake();

        $res = $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $this->lead->id,
            'channel'           => 'whatsapp',
            'body'              => 'Hola, ¿te ayudo con tu plan?',
        ], $this->headers());

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('sent', false)
            ->assertJsonPath('safe_to_send', true);

        Http::assertNothingSent();

        $this->assertDatabaseHas('marketing_messages', [
            'direction' => MarketingMessage::DIRECTION_OUTBOUND,
            'status'    => 'dry_run',
        ]);
    }

    public function test_do_not_contact_blocks_send(): void
    {
        Http::fake();
        $this->lead->update(['do_not_contact' => true]);

        $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $this->lead->id,
            'body'              => 'Hola',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('sent', false)
            ->assertJsonPath('safe_to_send', false)
            ->assertJsonPath('reason', 'do_not_contact');

        Http::assertNothingSent();
        $this->assertDatabaseCount('marketing_messages', 0);
    }

    public function test_lead_without_phone_blocks_whatsapp(): void
    {
        Http::fake();
        $noPhone = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'name' => 'Sin Tel',
            'status' => MarketingLead::STATUS_NEW,
        ]);

        $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $noPhone->id,
            'body'              => 'Hola',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('sent', false)
            ->assertJsonPath('safe_to_send', false)
            ->assertJsonPath('reason', 'lead_without_phone');

        Http::assertNothingSent();
    }

    public function test_send_message_requires_bearer(): void
    {
        $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $this->lead->id,
            'body'              => 'Hola',
        ])->assertStatus(401);
    }

    public function test_meta_enabled_but_unconfigured_is_dry_run_not_500(): void
    {
        Http::fake();
        // enabled pero SIN access_token/app_secret → isConfigured()=false → dry_run.
        config()->set('meta.enabled', true);
        config()->set('meta.access_token', '');
        config()->set('meta.app_secret', '');

        $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $this->lead->id,
            'body'              => 'Hola',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('sent', false);

        Http::assertNothingSent();
    }

    public function test_meta_configured_sends_real_message(): void
    {
        config()->set('meta.enabled', true);
        config()->set('meta.access_token', 'tok_x');
        config()->set('meta.app_secret', 'sec_x');
        config()->set('meta.whatsapp_phone_number_id', '123456');
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.graph_version', 'v21.0');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.TEST123']]], 200),
        ]);

        $this->postJson('/api/internal/marketing/send-message', [
            'marketing_lead_id' => $this->lead->id,
            'body'              => 'Hola',
        ], $this->headers())
            ->assertOk()
            ->assertJsonPath('sent', true)
            ->assertJsonPath('dry_run', false)
            ->assertJsonPath('provider_message_id', 'wamid.TEST123');

        $this->assertDatabaseHas('marketing_messages', [
            'status'          => 'sent',
            'meta_message_id' => 'wamid.TEST123',
        ]);
    }

    public function test_payment_links_send_generates_link_and_prepares_message(): void
    {
        Http::fake();

        $res = $this->postJson('/api/internal/marketing/payment-links/send', [
            'marketing_lead_id' => $this->lead->id,
            'plan_id'           => $this->plan->id,
            'channel'           => 'whatsapp',
        ], $this->headers());

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('sent', false)
            ->assertJsonPath('safe_to_send', true);

        $url = $res->json('payment_url');
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $url);
        $this->assertStringContainsString($url, $res->json('prepared_body'));

        Http::assertNothingSent();
        $this->assertDatabaseHas('payment_transactions', ['provider' => 'wompi', 'method' => 'web_checkout']);
    }

    public function test_payment_links_send_does_not_activate_membership(): void
    {
        Http::fake();

        $this->postJson('/api/internal/marketing/payment-links/send', [
            'marketing_lead_id' => $this->lead->id,
            'plan_id'           => $this->plan->id,
        ], $this->headers())->assertOk();

        $tx = PaymentTransaction::where('provider', 'wompi')->latest()->first();
        $this->assertSame(PaymentStateMachine::CREATED, $tx->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_payment_links_send_respects_do_not_contact(): void
    {
        Http::fake();
        $this->lead->update(['do_not_contact' => true]);

        $this->postJson('/api/internal/marketing/payment-links/send', [
            'marketing_lead_id' => $this->lead->id,
            'plan_id'           => $this->plan->id,
        ], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('code', 'lead_do_not_contact')
            ->assertJsonPath('sent', false);

        // No se generó link ni se envió nada.
        $this->assertDatabaseCount('payment_transactions', 0);
        Http::assertNothingSent();
    }
}
