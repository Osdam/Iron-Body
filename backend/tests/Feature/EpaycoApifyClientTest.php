<?php

namespace Tests\Feature;

use App\Services\EpaycoApiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cliente APIFY (login + session/create). Verifica manejo controlado de fallos
 * SIN llamadas reales (Http::fake). NUNCA se asienta sobre secretos.
 */
class EpaycoApifyClientTest extends TestCase
{
    private function withKeys(): void
    {
        config([
            'services.epayco.public_key' => 'pub_test',
            'services.epayco.private_key' => 'priv_test',
            'services.epayco.apify_base' => 'https://apify.epayco.co',
            'services.epayco.test' => true,
        ]);
    }

    public function test_apify_login_failure_returns_controlled_error(): void
    {
        $this->withKeys();
        // Credenciales inválidas → 401: NO retry, token null.
        Http::fake([
            'apify.epayco.co/login' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $client = app(EpaycoApiClient::class);
        $this->assertNull($client->apifyToken());

        // session/create sin token → error controlado, sin excepción.
        $r = $client->createCheckoutSession(['invoice' => 'IRON-X']);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['session_id']);
        $this->assertNotEmpty($r['message']);
    }

    public function test_apify_session_create_success_returns_session_id(): void
    {
        $this->withKeys();
        Http::fake([
            'apify.epayco.co/login' => Http::response(['token' => 'tok_123', 'exp' => time() + 600], 200),
            'apify.epayco.co/payment/session/create' => Http::response([
                'success' => true,
                'data' => ['sessionId' => 'sess_OK_1'],
            ], 200),
        ]);

        $client = app(EpaycoApiClient::class);
        $r = $client->createCheckoutSession(['invoice' => 'IRON-Y', 'amount' => '80000.00']);

        $this->assertTrue($r['ok']);
        $this->assertSame('sess_OK_1', $r['session_id']);
    }

    public function test_apify_session_create_failure_is_controlled(): void
    {
        $this->withKeys();
        Http::fake([
            'apify.epayco.co/login' => Http::response(['token' => 'tok_123'], 200),
            'apify.epayco.co/payment/session/create' => Http::response([
                'success' => false,
                'text_response' => 'rejected',
            ], 200),
        ]);

        $client = app(EpaycoApiClient::class);
        $r = $client->createCheckoutSession(['invoice' => 'IRON-Z']);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['session_id']);
    }

    public function test_parses_session_id_from_root(): void
    {
        $this->withKeys();
        Http::fake([
            'apify.epayco.co/login' => Http::response(['token' => 'tok_123'], 200),
            // 200 con sessionId en la RAÍZ (no en data) — caso del bug real.
            'apify.epayco.co/payment/session/create' => Http::response([
                'success' => true, 'sessionId' => 'sess_ROOT',
            ], 200),
        ]);

        $r = app(EpaycoApiClient::class)->createCheckoutSession(['invoice' => 'IRON-R']);
        $this->assertTrue($r['ok']);
        $this->assertSame('sess_ROOT', $r['session_id']);
    }

    public function test_parses_session_id_when_data_is_string(): void
    {
        $this->withKeys();
        Http::fake([
            'apify.epayco.co/login' => Http::response(['token' => 'tok_123'], 200),
            // 200 con data como STRING (el propio sessionId) — variante real.
            'apify.epayco.co/payment/session/create' => Http::response([
                'success' => true, 'data' => 'sess_STR',
            ], 200),
        ]);

        $r = app(EpaycoApiClient::class)->createCheckoutSession(['invoice' => 'IRON-S']);
        $this->assertTrue($r['ok']);
        $this->assertSame('sess_STR', $r['session_id']);
    }

    public function test_parses_checkout_url_when_present(): void
    {
        $this->withKeys();
        Http::fake([
            'apify.epayco.co/login' => Http::response(['token' => 'tok_123'], 200),
            'apify.epayco.co/payment/session/create' => Http::response([
                'success' => true,
                'data' => ['sessionId' => 'sess_U', 'url' => 'https://checkout.epayco.co/x'],
            ], 200),
        ]);

        $r = app(EpaycoApiClient::class)->createCheckoutSession(['invoice' => 'IRON-U']);
        $this->assertTrue($r['ok']);
        $this->assertSame('sess_U', $r['session_id']);
        $this->assertSame('https://checkout.epayco.co/x', $r['checkout_url']);
    }

    public function test_no_session_id_returns_controlled_failure(): void
    {
        $this->withKeys();
        Http::fake([
            'apify.epayco.co/login' => Http::response(['token' => 'tok_123'], 200),
            'apify.epayco.co/payment/session/create' => Http::response([
                'success' => true, 'data' => ['foo' => 'bar'],
            ], 200),
        ]);

        $r = app(EpaycoApiClient::class)->createCheckoutSession(['invoice' => 'IRON-N']);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['session_id']);
    }
}
