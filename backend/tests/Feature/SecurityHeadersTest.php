<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BACK-014: todas las respuestas llevan cabeceras de seguridad de base.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_responses_carry_baseline_security_headers(): void
    {
        $res = $this->getJson('/api/exercises');

        $res->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }

    public function test_headers_present_even_on_401(): void
    {
        // También en respuestas de error de auth (no dependen del contenido).
        $this->getJson('/api/notifications/unread-count')
            ->assertStatus(401)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
