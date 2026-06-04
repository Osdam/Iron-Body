<?php

namespace Tests\Feature;

use App\Models\AppAd;
use App\Models\AppAdView;
use App\Models\AppEvent;
use App\Models\Member;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Publicidad (campañas Home) y Eventos gestionados desde el CRM (Bloque 4).
 * Cubre CRUD admin, consumo del miembro y reglas de frecuencia.
 */
class AppAdsEventsTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create([
            'name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secret',
            'document' => '1010', 'phone' => '3001112233', 'status' => 'active',
        ]);
        $this->member = Member::create([
            'user_id' => $user->id, 'full_name' => 'Ana', 'email' => 'ana@example.com',
            'document_number' => '1010', 'phone' => '3001112233',
            'access_hash' => 'tok-1010', 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->member->access_hash];
    }

    private function ad(array $attrs = []): AppAd
    {
        return AppAd::create(array_merge([
            'title' => 'Promo', 'image_url' => 'https://cdn/x.jpg',
            'frequency_rule' => AppAd::FREQ_ONCE, 'is_active' => true,
        ], $attrs));
    }

    public function test_admin_creates_and_lists_ad(): void
    {
        $r = $this->postJson('/api/admin/ads', [
            'title' => 'Black Friday',
            'image_url' => 'https://cdn/bf.jpg',
            'frequency_rule' => 'daily',
        ]);
        $r->assertCreated()->assertJsonPath('data.title', 'Black Friday');

        $this->getJson('/api/admin/ads')->assertOk()->assertJsonFragment(['title' => 'Black Friday']);
    }

    public function test_admin_create_ad_requires_image(): void
    {
        $this->postJson('/api/admin/ads', ['title' => 'X'])->assertStatus(422);
    }

    public function test_member_sees_active_ad_once_then_hidden_after_seen(): void
    {
        $ad = $this->ad();

        $this->getJson('/api/member/ads/active', $this->auth())
            ->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/member/ads/{$ad->id}/seen", [], $this->auth())->assertOk();

        $this->getJson('/api/member/ads/active', $this->auth())
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_daily_ad_shows_again_next_day(): void
    {
        $ad = $this->ad(['frequency_rule' => AppAd::FREQ_DAILY]);
        AppAdView::create([
            'app_ad_id' => $ad->id, 'member_id' => $this->member->id,
            'seen_at' => Carbon::yesterday(),
        ]);

        $this->getJson('/api/member/ads/active', $this->auth())
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_inactive_ad_not_shown(): void
    {
        $this->ad(['is_active' => false]);

        $this->getJson('/api/member/ads/active', $this->auth())
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_out_of_window_ad_not_shown(): void
    {
        $this->ad(['ends_at' => Carbon::now()->subDay()]);

        $this->getJson('/api/member/ads/active', $this->auth())
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_event_lifecycle(): void
    {
        $r = $this->postJson('/api/admin/events', [
            'title' => 'Clase abierta', 'image_url' => 'https://cdn/e.jpg',
            'location' => 'Sede norte',
        ]);
        $r->assertCreated();
        $id = $r->json('data.id');

        $this->getJson('/api/member/events', $this->auth())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonFragment(['title' => 'Clase abierta']);

        $this->getJson("/api/member/events/{$id}", $this->auth())
            ->assertOk()->assertJsonPath('data.location', 'Sede norte');

        $this->postJson("/api/admin/events/{$id}/deactivate")->assertOk();

        $this->getJson('/api/member/events', $this->auth())
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/member/events/{$id}", $this->auth())->assertStatus(404);
    }
}
