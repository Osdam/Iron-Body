<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\Factus\FactusTokenManager;
use App\Services\Billing\InvoiceDtoBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FactusV2IntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.credentials' => ['username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's'],
            'billing.numbering.range_id' => 4,
        ]);
    }

    private function consumer(): array
    {
        return [
            'doc_type' => 'CC', 'doc_number' => '222222222222', 'dv' => null,
            'name' => 'Consumidor final', 'legal_name' => 'Consumidor final',
            'email' => null, 'phone' => null, 'address' => null,
            'city_code' => null, 'department_code' => null,
            'is_final_consumer' => true, 'person_type' => null,
        ];
    }

    public function test_invoice_payload_uses_official_v2_field_names(): void
    {
        $rate = TaxRate::create(['code' => 'IVA_19', 'name' => 'IVA 19%', 'rate' => 19, 'active' => true]);
        $plan = Plan::create([
            'name' => 'Premium', 'price' => 119000, 'duration_days' => 30, 'benefits' => '',
            'tax_rate_id' => $rate->id, 'price_includes_tax' => true,
        ]);
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => 119000,
            'method' => 'cash', 'reference' => 'R1', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $p = app(InvoiceDtoBuilder::class)->forPayment($payment, $this->consumer())['payload'];

        // Raíz V2
        $this->assertSame('01', $p['document']);
        $this->assertSame('10', $p['operation_type']);
        $this->assertSame(4, $p['numbering_range_id']);
        $this->assertFalse($p['send_email']);
        // payment_details
        $this->assertSame(1, $p['payment_details'][0]['payment_form']);          // entero
        $this->assertIsString($p['payment_details'][0]['payment_method_code']);
        $this->assertSame('119000.00', $p['payment_details'][0]['amount']);      // total string
        // customer V2 (_code) — natural → names
        $c = $p['customer'];
        $this->assertSame('13', $c['identification_document_code']);             // CC → 13
        $this->assertSame('222222222222', $c['identification']);
        $this->assertSame('2', $c['legal_organization_code']);                   // natural
        $this->assertSame('ZZ', $c['tribute_code']);
        $this->assertArrayHasKey('names', $c);
        $this->assertArrayNotHasKey('country_code', $c);                         // V2 no lo lleva
        // items V2
        $it = $p['items'][0];
        $this->assertSame('100000.00', $it['price']);                           // base sin IVA, string
        $this->assertSame('94', $it['unit_measure_code']);
        $this->assertSame('999', $it['standard_code']);
        $this->assertSame('01', $it['taxes'][0]['code']);
        $this->assertSame('19.00', $it['taxes'][0]['rate']);
    }

    public function test_token_uses_password_then_refresh_grant(): void
    {
        Http::fake([
            '*oauth/token' => Http::response(['access_token' => 'A1', 'refresh_token' => 'R1', 'expires_in' => 3600]),
        ]);

        $tm = FactusTokenManager::fromConfig();
        $tm->accessToken();   // primera: password
        $tm->forget();        // expira el access
        $tm->accessToken();   // segunda: refresh_token (hay refresh cacheado)

        Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth/token')
            && ($req->data()['grant_type'] ?? '') === 'password');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth/token')
            && ($req->data()['grant_type'] ?? '') === 'refresh_token');
    }

    public function test_client_classifies_conflict(): void
    {
        Http::fake([
            '*oauth/token'        => Http::response(['access_token' => 'A', 'expires_in' => 3600]),
            '*/v2/bills/validate' => Http::response(['message' => 'dup'], 409),
        ]);
        $conflict = FactusClient::make()->createInvoice(['x' => 1]);
        $this->assertFalse($conflict['ok']);
        $this->assertSame('conflict', $conflict['error_class']);
    }

    public function test_client_classifies_rate_limit(): void
    {
        Http::fake([
            '*oauth/token'        => Http::response(['access_token' => 'A', 'expires_in' => 3600]),
            '*/v2/bills/validate' => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '30']),
        ]);
        $rate = FactusClient::make()->createInvoice(['x' => 1]);
        $this->assertSame('rate_limit', $rate['error_class']);
        $this->assertSame(30, $rate['retry_after']);
    }
}
