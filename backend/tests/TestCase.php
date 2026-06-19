<?php

namespace Tests;

use App\Models\Admin;
use App\Services\Admin\AdminSessionService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cabecera con una sesión admin REAL (login email+contraseña). Crea el admin
     * si no se pasa, emite una sesión vía AdminSessionService y devuelve el
     * header Bearer con el token en claro. Para probar el camino de sesión (no el
     * fallback del secreto compartido).
     */
    protected function actingAsAdmin(?Admin $admin = null, array $headers = []): array
    {
        $admin ??= Admin::create([
            'name' => 'Test Admin',
            'email' => 'test-admin-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        $issued = app(AdminSessionService::class)->issueSession($admin);

        return array_merge(['Authorization' => 'Bearer '.$issued['token']], $headers);
    }

    /**
     * Cabecera con el secreto administrativo (blindaje de /api/admin/* y pagos
     * legacy). Usa el token configurado o uno determinista de respaldo. Los
     * helpers admin* de abajo la inyectan para que los tests de funcionalidad
     * CRM crucen el middleware EnsureAdminAuth sin repetir el header a mano.
     */
    protected function adminHeaders(array $headers = []): array
    {
        $token = config('admin.api_token') ?: 'test-admin-secret';
        config(['admin.api_token' => $token]);

        return array_merge(['Authorization' => 'Bearer ' . $token], $headers);
    }

    protected function adminGetJson(string $uri, array $headers = []): TestResponse
    {
        return $this->getJson($uri, $this->adminHeaders($headers));
    }

    protected function adminPostJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->postJson($uri, $data, $this->adminHeaders($headers));
    }

    protected function adminPutJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->putJson($uri, $data, $this->adminHeaders($headers));
    }

    protected function adminPatchJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->patchJson($uri, $data, $this->adminHeaders($headers));
    }

    protected function adminDeleteJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->deleteJson($uri, $data, $this->adminHeaders($headers));
    }
}
