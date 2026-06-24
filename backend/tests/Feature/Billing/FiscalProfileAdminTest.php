<?php

namespace Tests\Feature\Billing;

use App\Models\FiscalProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalProfileAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_returns_null_when_absent(): void
    {
        $user = User::factory()->create();

        $this->adminGetJson("/api/admin/users/{$user->id}/fiscal-profile")
            ->assertOk()->assertJsonPath('data', null);
    }

    public function test_update_creates_profile_and_validates(): void
    {
        $user = User::factory()->create();

        $this->adminPutJson("/api/admin/users/{$user->id}/fiscal-profile", [
            'doc_type' => 'NIT', 'doc_number' => '900123456', 'dv' => '7',
            'person_type' => 'juridica', 'legal_name' => 'Gimnasios SAS',
            'email' => 'fact@gym.co', 'city_code' => '11001',
        ])->assertOk()->assertJsonPath('data.is_complete', true);

        $this->assertDatabaseHas('fiscal_profiles', [
            'user_id' => $user->id, 'doc_number' => '900123456',
        ]);
    }

    public function test_update_requires_document_fields(): void
    {
        $user = User::factory()->create();

        $this->adminPutJson("/api/admin/users/{$user->id}/fiscal-profile", [
            'email' => 'x@y.co',
        ])->assertStatus(422);
    }

    public function test_update_is_idempotent_upsert(): void
    {
        $user = User::factory()->create();
        $payload = ['doc_type' => 'CC', 'doc_number' => '123', 'legal_name' => 'Juan'];

        $this->adminPutJson("/api/admin/users/{$user->id}/fiscal-profile", $payload)->assertOk();
        $this->adminPutJson("/api/admin/users/{$user->id}/fiscal-profile",
            array_merge($payload, ['doc_number' => '456']))->assertOk();

        $this->assertSame(1, FiscalProfile::where('user_id', $user->id)->count());
        $this->assertSame('456', FiscalProfile::where('user_id', $user->id)->first()->doc_number);
    }

    public function test_requires_admin_auth(): void
    {
        $user = User::factory()->create();
        $this->getJson("/api/admin/users/{$user->id}/fiscal-profile")->assertStatus(401);
    }
}
