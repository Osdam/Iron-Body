<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdminAuth;
use App\Models\Admin;
use App\Models\AdminSession;
use App\Services\Admin\AdminSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Login real del panel/CRM (email + contraseña): emisión de sesión, `me`,
 * `logout` (revocación), caducidad y anti-enumeración.
 */
class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(array $overrides = []): Admin
    {
        return Admin::create(array_merge([
            'name' => 'Admin Uno',
            'email' => 'admin@ironbody.test',
            'password' => 'super-secret',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ], $overrides));
    }

    public function test_login_correcto_emite_token_y_datos(): void
    {
        $this->makeAdmin();

        $res = $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@ironbody.test',
            'password' => 'super-secret',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.email', 'admin@ironbody.test')
            ->assertJsonPath('user.role', Admin::ROLE_SUPER_ADMIN);

        $this->assertNotEmpty($res->json('token'));
        $this->assertDatabaseCount('admin_sessions', 1);
    }

    public function test_login_password_incorrecta_devuelve_401_generico(): void
    {
        $this->makeAdmin();

        $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@ironbody.test',
            'password' => 'mala',
        ])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_login_email_inexistente_devuelve_mismo_401(): void
    {
        // Anti-enumeración: misma respuesta que password incorrecta.
        $this->postJson('/api/admin/auth/login', [
            'email' => 'noexiste@ironbody.test',
            'password' => 'lo-que-sea',
        ])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_admin_deshabilitado_no_puede_entrar(): void
    {
        $this->makeAdmin(['status' => 'disabled']);

        $this->postJson('/api/admin/auth/login', [
            'email' => 'admin@ironbody.test',
            'password' => 'super-secret',
        ])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_me_devuelve_admin_con_token_de_sesion(): void
    {
        $admin = $this->makeAdmin();

        $this->getJson('/api/admin/auth/me', $this->actingAsAdmin($admin))
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@ironbody.test');
    }

    public function test_logout_revoca_la_sesion(): void
    {
        $admin = $this->makeAdmin();
        $issued = app(\App\Services\Admin\AdminSessionService::class)->issueSession($admin);
        $headers = ['Authorization' => 'Bearer '.$issued['token']];

        $this->postJson('/api/admin/auth/logout', [], $headers)->assertOk();

        $this->assertNotNull($issued['session']->fresh()->revoked_at);

        // El token ya no sirve.
        $this->getJson('/api/admin/auth/me', $headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'admin_token_invalid');
    }

    public function test_stream_acepta_token_de_sesion_por_query(): void
    {
        // EventSource no puede mandar header: las rutas /stream aceptan ?token=.
        $admin = $this->makeAdmin();
        $issued = app(AdminSessionService::class)->issueSession($admin);

        $request = Request::create('/api/admin/exercises/stream', 'GET', ['token' => $issued['token']]);

        $this->assertNull(EnsureAdminAuth::challenge($request)); // pasa (autenticado)
    }

    public function test_token_por_query_no_vale_fuera_de_stream(): void
    {
        // Fuera de /stream el token por query se ignora (no normalizar token-en-URL).
        $admin = $this->makeAdmin();
        $issued = app(AdminSessionService::class)->issueSession($admin);

        $request = Request::create('/api/admin/exercises', 'GET', ['token' => $issued['token']]);
        $response = EnsureAdminAuth::challenge($request);

        $this->assertNotNull($response);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_sesion_caducada_no_autentica(): void
    {
        $admin = $this->makeAdmin();
        $issued = app(\App\Services\Admin\AdminSessionService::class)->issueSession($admin);

        // Forzamos caducidad en el pasado.
        AdminSession::query()->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/admin/auth/me', ['Authorization' => 'Bearer '.$issued['token']])
            ->assertStatus(403)
            ->assertJsonPath('code', 'admin_token_invalid');
    }
}
