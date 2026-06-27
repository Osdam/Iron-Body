<?php

namespace Tests\Feature\Marketing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Fase 1.6 — diagnóstico Meta (comando + endpoint). NUNCA debe imprimir valores
 * de tokens/secretos: solo SET/MISSING y decisiones derivadas.
 */
class MetaDoctorTest extends TestCase
{
    use RefreshDatabase;

    private const INTERNAL_SECRET = 'test-internal-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('automation.internal_secret', self::INTERNAL_SECRET);
        config()->set('meta.enabled', false);
    }

    public function test_command_shows_missing_without_leaking_secrets(): void
    {
        config()->set('meta.access_token', 'SUPER_SECRET_TOKEN_VALUE');
        config()->set('meta.whatsapp_phone_number_id', ''); // MISSING

        $code = Artisan::call('meta:doctor');
        $output = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('MISSING', $output);
        $this->assertStringContainsString('SET', $output);
        // El valor del token JAMÁS aparece.
        $this->assertStringNotContainsString('SUPER_SECRET_TOKEN_VALUE', $output);
    }

    public function test_doctor_endpoint_requires_bearer(): void
    {
        $this->getJson('/api/internal/marketing/meta/doctor')->assertStatus(401);
    }

    public function test_doctor_endpoint_returns_dry_run_when_meta_off(): void
    {
        $this->getJson('/api/internal/marketing/meta/doctor', [
            'Authorization' => 'Bearer '.self::INTERNAL_SECRET,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.send_mode', 'dry_run')
            ->assertJsonPath('data.live_send_allowed', false)
            ->assertJsonStructure(['data' => ['present', 'missing', 'suggestions', 'webhook_url']]);
    }

    public function test_doctor_endpoint_does_not_expose_secret_values(): void
    {
        config()->set('meta.access_token', 'ANOTHER_SECRET_XYZ');

        $body = $this->getJson('/api/internal/marketing/meta/doctor', [
            'Authorization' => 'Bearer '.self::INTERNAL_SECRET,
        ])->getContent();

        $this->assertStringNotContainsString('ANOTHER_SECRET_XYZ', $body);
    }
}
