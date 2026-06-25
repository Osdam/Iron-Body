<?php

namespace Tests\Feature\Billing;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FactusSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // nada real debe salir
        config([
            'billing.env'      => 'sandbox',
            'billing.base_url' => 'https://api-sandbox.factus.com.co',
            'billing.credentials' => ['username' => 'u', 'password' => 'p', 'client_id' => 'c', 'client_secret' => 's'],
        ]);
    }

    public function test_refuses_when_not_sandbox(): void
    {
        config(['billing.env' => 'production', 'billing.base_url' => 'https://api.factus.com.co']);

        $this->artisan('billing:factus-smoke', ['--payment-id' => 1, '--confirm' => true])
            ->expectsOutputToContain('Bloqueado')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_refuses_without_credentials(): void
    {
        config(['billing.credentials' => ['username' => '', 'password' => '', 'client_id' => '', 'client_secret' => '']]);

        $this->artisan('billing:factus-smoke', ['--payment-id' => 1, '--confirm' => true])
            ->expectsOutputToContain('Bloqueado')
            ->assertExitCode(1);
    }

    public function test_refuses_when_payment_not_paid(): void
    {
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'amount' => 1000, 'method' => 'cash',
            'reference' => 'R', 'status' => 'pending',
        ]);

        $this->artisan('billing:factus-smoke', ['--payment-id' => $payment->id, '--confirm' => true])
            ->expectsOutputToContain("no está 'paid'")
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_check_refuses_when_not_sandbox(): void
    {
        config(['billing.env' => 'production', 'billing.base_url' => 'https://api.factus.com.co']);

        $this->artisan('billing:factus-check')
            ->expectsOutputToContain('Bloqueado')
            ->assertExitCode(1);
    }
}
