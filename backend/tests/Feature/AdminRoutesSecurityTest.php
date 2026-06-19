<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blindaje de las rutas administrativas / CRM y de los pagos legacy.
 *
 * Auditoría en producción encontró /api/admin/* y /api/payments respondiendo 200
 * SIN token. Este test fija el contrato de seguridad:
 *
 *   - /api/admin/*  sin token  => 401  (y con token inválido => 403)
 *   - /api/payments sin token  => 401
 *   - rutas públicas legítimas (app/exercises, iron-ai/equipment-catalog) siguen 200
 *   - app/* e iron-ai/* siguen exigiendo auth.member (401)
 *   - los pagos in-app Wompi conservan auth.member (NO el secreto del CRM)
 */
class AdminRoutesSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-admin-secret';

    protected function setUp(): void
    {
        parent::setUp();
        // Secreto determinista para el test (independiente del .env real).
        config(['admin.api_token' => self::SECRET]);
    }

    /** Rutas que la auditoría reportó como públicas: deben exigir el secreto. */
    public static function protectedAdminRoutes(): array
    {
        return [
            'productos admin'  => ['/api/admin/products'],
            'soporte admin'    => ['/api/admin/support'],
            'audit logs'       => ['/api/admin/audit-logs'],
            'caja ventas'      => ['/api/admin/caja/sales'],
            'security locks'   => ['/api/admin/security/locks'],
            'marketing'        => ['/api/admin/marketing/overview'],
        ];
    }

    /**
     * @dataProvider protectedAdminRoutes
     */
    public function test_admin_route_sin_token_devuelve_401(string $uri): void
    {
        $this->getJson($uri)
            ->assertStatus(401)
            ->assertJsonPath('code', 'admin_token_required');
    }

    /**
     * @dataProvider protectedAdminRoutes
     */
    public function test_admin_route_con_token_invalido_devuelve_403(string $uri): void
    {
        $this->getJson($uri, ['Authorization' => 'Bearer token-incorrecto'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'admin_token_invalid');
    }

    public function test_payments_legacy_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/payments')
            ->assertStatus(401)
            ->assertJsonPath('code', 'admin_token_required');

        // Mutaciones legacy tampoco públicas.
        $this->postJson('/api/payments', [])->assertStatus(401);
    }

    public function test_admin_route_con_secreto_correcto_pasa_el_middleware(): void
    {
        // El secreto válido (fallback n8n) cruza el blindaje (audit-logs index → 200).
        $this->getJson('/api/admin/audit-logs', ['Authorization' => 'Bearer ' . self::SECRET])
            ->assertOk();
    }

    public function test_admin_route_con_sesion_real_pasa_el_middleware(): void
    {
        // Una sesión admin real (login email+contraseña) también cruza el blindaje.
        $this->getJson('/api/admin/audit-logs', $this->actingAsAdmin())
            ->assertOk();
    }

    public function test_login_admin_es_publico_y_no_exige_secreto(): void
    {
        // /api/admin/auth/login está bajo /admin pero ProtectAdminPaths lo excluye:
        // sin token NO devuelve 401 de blindaje, sino el 401 de credenciales.
        $this->postJson('/api/admin/auth/login', ['email' => 'x@y.z', 'password' => 'nope'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_credentials');
    }

    /** Rutas CRM antes públicas (fuera del prefijo /admin): ahora exigen secreto. */
    public static function protectedCrmRoutes(): array
    {
        return [
            'dashboard'        => ['get',  '/api/dashboard'],
            'reports/stats'    => ['get',  '/api/reports/stats'],
            'users (PII)'      => ['get',  '/api/users'],
            'attendances'      => ['get',  '/api/attendances'],
            'turnstile'        => ['get',  '/api/turnstile'],
            'routines'         => ['get',  '/api/routines'],
            'plans (escritura)'    => ['post', '/api/plans'],
            'classes (escritura)'  => ['post', '/api/classes'],
            'trainers (escritura)' => ['post', '/api/trainers'],
        ];
    }

    /**
     * @dataProvider protectedCrmRoutes
     */
    public function test_ruta_crm_sin_token_devuelve_401(string $verb, string $uri): void
    {
        $this->json(strtoupper($verb), $uri)->assertStatus(401);
    }

    public function test_rutinas_de_miembro_crm_sin_token_devuelve_401(): void
    {
        // El binding {member} resuelve un miembro REAL antes del middleware; con
        // un id existente la respuesta debe ser 401 (no exponer sus rutinas).
        $member = \App\Models\Member::create([
            'full_name'       => 'Rutinas CRM',
            'document_number' => '900700700',
            'phone'           => '+573009009000',
            'status'          => \App\Models\Member::STATUS_ACTIVE,
        ]);

        $this->getJson("/api/members/{$member->id}/routines")
            ->assertStatus(401)
            ->assertJsonPath('code', 'admin_token_required');
    }

    public function test_rutas_publicas_legitimas_siguen_abiertas(): void
    {
        $this->getJson('/api/app/exercises')->assertOk();
        $this->getJson('/api/iron-ai/equipment-catalog')->assertOk();
        // Lecturas que deben seguir públicas (la app las usa sin sesión admin).
        $this->getJson('/api/plans')->assertOk();
        $this->getJson('/api/classes')->assertOk();
        $this->getJson('/api/trainers')->assertOk();
        $this->getJson('/api/membership-plans')->assertOk();
        // Notificaciones de la app (audience=member): NO las cubre el secreto admin.
        $this->getJson('/api/notifications/unread-count')->assertStatus(200);
    }

    public function test_app_e_iron_ai_siguen_exigiendo_auth_member(): void
    {
        // No los toca el blindaje admin: conservan su 401 de auth.member.
        $this->getJson('/api/app/payments')->assertStatus(401);
        $this->postJson('/api/iron-ai/chat', [])->assertStatus(401);
    }

    public function test_wompi_in_app_conserva_auth_member_no_el_secreto_admin(): void
    {
        // payments/wompi/* está EXCLUIDO del secreto admin: responde el 401 de
        // auth.member (token_required), nunca el código del blindaje admin.
        $this->getJson('/api/payments/wompi/config')
            ->assertStatus(401)
            ->assertJsonPath('code', 'token_required');
    }
}
