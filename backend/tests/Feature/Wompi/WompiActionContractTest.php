<?php

namespace Tests\Feature\Wompi;

use App\Models\Plan;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato explícito `action` por método + estado. Garantiza que CARD NUNCA
 * produce una acción de navegador/WebView (aunque Wompi devuelva redirect_url),
 * que PSE solo abre navegador externo con URL real, y que el monto es autoritativo.
 */
class WompiActionContractTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): WompiTransactionService
    {
        config()->set('wompi', array_merge((array) config('wompi'), [
            'env' => 'sandbox', 'currency' => 'COP',
        ]));
        return WompiTransactionService::make();
    }

    private function tx(string $method, int $planId)
    {
        return $this->svc()->createOrReuse([
            'method' => $method, 'plan_id' => $planId, 'user_id' => null, 'member_id' => null,
            'customer' => ['email' => 'a@b.co'],
        ]);
    }

    private function plan(): Plan
    {
        return Plan::create(['name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
    }

    public function test_card_with_redirect_url_never_produces_browser_action(): void
    {
        $plan = $this->plan();
        $tx = $this->tx('card', $plan->id);

        // Wompi devuelve redirect_url en TODA transacción (incluida CARD). Antes
        // esto forzaba requires_action + WebView. Ya no.
        $updated = $this->svc()->applyWompiTransaction($tx, [
            'id' => 'wtx_card_1',
            'status' => 'PENDING',
            'reference' => $tx->reference,
            'amount_in_cents' => 8000000,
            'currency' => 'COP',
            'redirect_url' => 'https://api.ironbody/return',
            'payment_method' => ['type' => 'CARD', 'extra' => ['brand' => 'VISA', 'last_four' => '4242']],
        ]);

        $this->assertSame(PaymentStateMachine::PENDING, $updated->status);
        $this->assertNull($updated->external_auth_url);

        $arr = $updated->toWompiPublicArray();
        $this->assertSame('CARD', $arr['payment_method']);
        $this->assertFalse($arr['is_final']);
        $this->assertSame('wait_for_confirmation', $arr['action']['type']);
        $this->assertNull($arr['action']['url']);
        $this->assertNull($arr['external_auth_url']);
    }

    public function test_card_approved_is_final_with_no_action(): void
    {
        $plan = $this->plan();
        $tx = $this->tx('card', $plan->id);
        $updated = $this->svc()->applyWompiTransaction($tx, [
            'id' => 'wtx_card_2', 'status' => 'APPROVED', 'reference' => $tx->reference,
            'amount_in_cents' => 8000000, 'currency' => 'COP',
            'payment_method' => ['type' => 'CARD'],
        ]);
        $arr = $updated->toWompiPublicArray();
        $this->assertTrue($arr['is_final']);
        $this->assertSame('none', $arr['action']['type']);
    }

    public function test_pse_with_bank_url_returns_external_browser(): void
    {
        $plan = $this->plan();
        $tx = $this->tx('pse', $plan->id);
        $updated = $this->svc()->applyWompiTransaction($tx, [
            'id' => 'wtx_pse_1', 'status' => 'PENDING', 'reference' => $tx->reference,
            'amount_in_cents' => 8000000, 'currency' => 'COP',
            'redirect_url' => 'https://api.ironbody/return',
            'payment_method' => ['type' => 'PSE', 'extra' => [
                'async_payment_url' => 'https://bank.example/pse/auth?x=1',
            ]],
        ]);

        $this->assertSame(PaymentStateMachine::REQUIRES_ACTION, $updated->status);
        $arr = $updated->toWompiPublicArray();
        $this->assertSame('external_browser', $arr['action']['type']);
        $this->assertSame('https://bank.example/pse/auth?x=1', $arr['action']['url']);
        $this->assertSame('https://bank.example/pse/auth?x=1', $arr['external_auth_url']);
    }

    public function test_pse_without_url_waits_natively(): void
    {
        $plan = $this->plan();
        $tx = $this->tx('pse', $plan->id);
        $updated = $this->svc()->applyWompiTransaction($tx, [
            'id' => 'wtx_pse_2', 'status' => 'PENDING', 'reference' => $tx->reference,
            'amount_in_cents' => 8000000, 'currency' => 'COP',
            'redirect_url' => 'https://api.ironbody/return',
            'payment_method' => ['type' => 'PSE', 'extra' => []],
        ]);
        $arr = $updated->toWompiPublicArray();
        $this->assertSame('wait_for_confirmation', $arr['action']['type']);
        $this->assertNull($arr['external_auth_url']);
    }

    public function test_nequi_returns_open_nequi_app_no_url(): void
    {
        $plan = $this->plan();
        $tx = $this->tx('nequi', $plan->id);
        $updated = $this->svc()->applyWompiTransaction($tx, [
            'id' => 'wtx_nequi_1', 'status' => 'PENDING', 'reference' => $tx->reference,
            'amount_in_cents' => 8000000, 'currency' => 'COP',
            'redirect_url' => 'https://api.ironbody/return',
            'payment_method' => ['type' => 'NEQUI'],
        ]);
        $arr = $updated->toWompiPublicArray();
        $this->assertSame('open_nequi_app', $arr['action']['type']);
        $this->assertNull($arr['action']['url']);
        $this->assertNull($arr['external_auth_url']);
    }

    public function test_daviplata_returns_otp_action(): void
    {
        $plan = $this->plan();
        $tx = $this->tx('daviplata', $plan->id);
        $updated = $this->svc()->applyWompiTransaction($tx, [
            'id' => 'wtx_dav_1', 'status' => 'PENDING', 'reference' => $tx->reference,
            'amount_in_cents' => 8000000, 'currency' => 'COP',
            'payment_method' => ['type' => 'DAVIPLATA'],
        ]);
        $arr = $updated->toWompiPublicArray();
        $this->assertSame('daviplata_otp', $arr['action']['type']);
    }

    public function test_amount_is_authoritative_from_plan(): void
    {
        $plan = $this->plan(); // 80000
        // Aunque el cliente mande 1, se usa el precio del plan.
        $tx = $this->svc()->createOrReuse([
            'method' => 'card', 'plan_id' => $plan->id, 'amount' => 1,
            'customer' => ['email' => 'a@b.co'],
        ]);
        $this->assertSame(80000.0, (float) $tx->amount);
    }
}
