<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EpaycoMemberPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_epayco_create_accepts_member_id_and_links_crm_user(): void
    {
        $plan = Plan::create([
            'name' => 'Mensual',
            'price' => 80000,
            'duration_days' => 30,
            'active' => true,
        ]);

        $member = Member::create([
            'full_name' => 'Oscar Mancipe',
            'email' => 'oscar@example.com',
            'document_number' => '1004301550',
            'phone' => '3215542105',
            'status' => Member::STATUS_PENDING_REGISTRATION,
        ]);

        $response = $this->postJson('/api/payments/epayco/create', [
            'amount' => 80000,
            'currency' => 'COP',
            'description' => 'Membresia Mensual · Iron Body',
            'idempotency_key' => 'idem-member-1004301550',
            'plan_id' => $plan->id,
            'member_id' => $member->id,
            'customer' => [
                'name' => 'Oscar Mancipe',
                'email' => 'oscar@example.com',
                'phone' => '3215542105',
                'doc_number' => '1004301550',
                'doc_type' => 'CC',
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('member_id', $member->id)
            ->assertJsonPath('plan_id', $plan->id);

        $member->refresh();

        $this->assertNotNull($member->user_id);
        $this->assertDatabaseHas('payment_transactions', [
            'member_id' => $member->id,
            'user_id' => $member->user_id,
            'plan_id' => $plan->id,
            'amount' => 80000,
        ]);
    }

    public function test_epayco_create_requires_member_or_user(): void
    {
        $plan = Plan::create([
            'name' => 'Mensual',
            'price' => 80000,
            'duration_days' => 30,
            'active' => true,
        ]);

        $this->postJson('/api/payments/epayco/create', [
            'amount' => 80000,
            'plan_id' => $plan->id,
        ])->assertUnprocessable();
    }
}
