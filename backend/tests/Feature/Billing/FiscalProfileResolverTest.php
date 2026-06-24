<?php

namespace Tests\Feature\Billing;

use App\Models\FiscalProfile;
use App\Models\Payment;
use App\Models\User;
use App\Services\Billing\FiscalProfileResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalProfileResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.consumer_final' => [
                'document_type'   => '13',
                'document_number' => '222222222222',
                'name'            => 'Consumidor final',
            ],
        ]);
    }

    public function test_falls_back_to_consumer_final_without_profile(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'amount' => 50000, 'method' => 'cash',
            'reference' => 'R-1', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $resolved = app(FiscalProfileResolver::class)->resolveForPayment($payment);

        $this->assertTrue($resolved['is_final_consumer']);
        $this->assertSame('222222222222', $resolved['doc_number']);
        $this->assertSame('Consumidor final', $resolved['name']);
        // El contacto real se conserva para entrega del comprobante.
        $this->assertSame($user->email, $resolved['email']);
    }

    public function test_uses_complete_fiscal_profile_when_present(): void
    {
        $user = User::factory()->create();
        FiscalProfile::create([
            'user_id' => $user->id,
            'doc_type' => 'NIT', 'doc_number' => '900123456', 'dv' => '7',
            'person_type' => 'juridica', 'legal_name' => 'Gimnasios SAS',
            'email' => 'facturacion@gimnasios.co', 'city_code' => '11001',
        ]);
        $payment = Payment::create([
            'user_id' => $user->id, 'amount' => 50000, 'method' => 'cash',
            'reference' => 'R-2', 'status' => 'paid', 'paid_at' => now(),
        ]);

        $resolved = app(FiscalProfileResolver::class)->resolveForPayment($payment);

        $this->assertFalse($resolved['is_final_consumer']);
        $this->assertSame('NIT', $resolved['doc_type']);
        $this->assertSame('900123456', $resolved['doc_number']);
        $this->assertSame('Gimnasios SAS', $resolved['name']);
    }
}
