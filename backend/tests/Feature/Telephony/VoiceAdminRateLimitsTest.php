<?php

namespace Tests\Feature\Telephony;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\RolePermission;
use App\Support\Access\RolePermissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Telephony\Support\VoiceFixtures;
use Tests\TestCase;

/**
 * LOS CUPOS DEL CRM DE LA TELEFONÍA (`/api/admin/voice/*`; PERF8 P8-5 y el
 * hallazgo de OPS8-b en el banco; B8-c).
 *
 * Antes eran `throttle:120,1` y `throttle:30,1` SIN NOMBRE: la sesión del panel
 * no rellena `$request->user()`, así que Laravel contaba en `sha1(dominio|IP)`,
 * un cubo COMÚN a todas las rutas con `throttle` sin nombre. Toda la recepción
 * (una IP) compartía 120 lecturas por minuto, y unas pocas peticiones anónimas
 * a la telefonía dejaban el login del panel (`throttle:8,1`) en 429.
 *
 * Ahora, limitadores con NOMBRE (`voice-admin-read`, `voice-admin-write`): con
 * sesión, un cubo por PERSONA (otra sesión de la misma persona no da más); sin
 * sesión (sin bearer, uno inventado o el token compartido), un cubo propio por
 * ruta y red que no gasta el de nadie. Todo desde la MISMA IP de la prueba,
 * como la recepción. Sin red.
 */
class VoiceAdminRateLimitsTest extends TestCase
{
    use RefreshDatabase;
    use VoiceFixtures;

    /** Los de `AppServiceProvider::limitadoresDeLaTelefonia()`. */
    private const READ_PER_PERSON = 120;

    private const WRITE_PER_PERSON = 30;

    private const ANONYMOUS_PER_ROUTE = 60;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
        $this->voiceWorld();
    }

    public function test_las_rutas_del_crm_usan_los_limitadores_con_nombre_y_ninguno_sin_nombre(): void
    {
        $vistas = 0;
        foreach (Route::getRoutes()->getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'api/admin/voice/')) {
                continue;
            }
            $vistas++;
            $limitadores = array_values(array_filter($r->gatherMiddleware(), static fn ($m): bool => is_string($m) && str_starts_with($m, 'throttle:')));
            $esperado = in_array('GET', $r->methods(), true) ? 'throttle:voice-admin-read' : 'throttle:voice-admin-write';
            $this->assertSame([$esperado], $limitadores, $r->uri().': un limitador con nombre (un `throttle:N,M` cuenta por IP en el cubo del login)');
        }
        $this->assertSame(13, $vistas);
    }

    public function test_dos_personas_desde_la_misma_ip_no_se_gastan_el_cupo_de_lectura(): void
    {
        $ana = $this->adminWithPermissions(['telephony.view']);
        $beto = $this->adminWithPermissions(['telephony.view']);

        for ($i = 1; $i <= self::READ_PER_PERSON; $i++) {
            $this->getJson('/api/admin/voice/calls', $ana)->assertOk();
        }
        $this->assertTooMany($this->getJson('/api/admin/voice/calls', $ana));
        // Beto, desde la misma IP, entra con su cupo entero.
        $this->getJson('/api/admin/voice/calls', $beto)->assertOk();
        $this->getJson('/api/admin/voice/status', $beto)->assertOk();
    }

    public function test_el_cupo_es_de_la_persona_y_no_de_la_sesion(): void
    {
        $rol = 'H8-voz-lectura';
        AdminRole::firstOrCreate(['name' => $rol], ['is_system' => false]);
        RolePermission::updateOrCreate(['role' => $rol, 'permission' => 'telephony.view'], ['granted' => true]);
        app(RolePermissionPolicy::class)->flush();
        $ana = Admin::create(['name' => 'Ana Dos Pestañas', 'email' => 'ana-'.Str::random(6).'@ironbody.test', 'password' => 'secret-password', 'role' => $rol, 'status' => 'active']);
        [$pestana1, $pestana2] = [$this->actingAsAdmin($ana), $this->actingAsAdmin($ana)];

        for ($i = 1; $i <= self::READ_PER_PERSON; $i++) {
            $this->getJson('/api/admin/voice/calls', $i % 2 === 0 ? $pestana1 : $pestana2)->assertOk();
        }
        $this->assertTooMany($this->getJson('/api/admin/voice/calls', $pestana1));
        $this->assertTooMany($this->getJson('/api/admin/voice/calls', $pestana2));
    }

    public function test_dos_personas_desde_la_misma_ip_no_se_gastan_el_cupo_de_escritura(): void
    {
        $ana = $this->adminWithPermissions(['telephony.call_place']);
        $beto = $this->adminWithPermissions(['telephony.call_place']);
        $colgar = fn (array $quien): TestResponse => $this->postJson('/api/admin/voice/calls/'.Str::uuid().'/hangup', [], $quien);

        for ($i = 1; $i <= self::WRITE_PER_PERSON; $i++) {
            $colgar($ana)->assertNotFound();
        }
        $this->assertTooMany($colgar($ana));
        $colgar($beto)->assertNotFound();
        // La lectura es otro cupo: escribir no la gasta.
        $this->getJson('/api/admin/voice/calls', $this->adminWithPermissions(['telephony.view']))->assertOk();
    }

    public function test_cincuenta_peticiones_anonimas_no_cambian_el_login_ni_gastan_el_cupo_de_nadie(): void
    {
        $email = 'recepcion-'.Str::lower(Str::random(6)).'@ironbody.test';   // el login lo pasa a minúsculas
        Admin::create(['name' => 'Recepción', 'email' => $email, 'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $login = fn (): TestResponse => $this->postJson('/api/admin/auth/login', ['email' => $email, 'password' => 'secret-password']);
        $login()->assertOk();

        for ($i = 0; $i < 50; $i++) {
            $this->getJson('/api/admin/voice/status')->assertUnauthorized();
        }

        $login()->assertOk();
        // Ni el de una persona de la misma IP.
        $ana = $this->adminWithPermissions(['telephony.view']);
        for ($i = 1; $i <= self::READ_PER_PERSON; $i++) {
            $this->getJson('/api/admin/voice/calls', $ana)->assertOk();
        }
    }

    public function test_sin_sesion_cada_ruta_tiene_su_cubo_por_red_y_un_bearer_inventado_no_estrena_otro(): void
    {
        // Un bearer distinto en cada petición, que no es de ninguna sesión (403 de EnsureAdminAuth), y sin
        // bearer (401): el mismo cubo anónimo de la ruta, no uno nuevo por bearer.
        for ($i = 1; $i <= self::ANONYMOUS_PER_ROUTE; $i++) {
            $i % 2 === 0
                ? $this->getJson('/api/admin/voice/status', ['Authorization' => 'Bearer '.Str::random(48)])->assertForbidden()
                : $this->getJson('/api/admin/voice/status')->assertUnauthorized();
        }
        $this->assertTooMany($this->getJson('/api/admin/voice/status', ['Authorization' => 'Bearer '.Str::random(48)]));
        $this->assertTooMany($this->getJson('/api/admin/voice/status'));

        // Otra ruta, sin sesión: su propio cubo.
        $this->getJson('/api/admin/voice/calls')->assertUnauthorized();
        // Otra red: su propio cubo.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);
        $this->getJson('/api/admin/voice/status')->assertUnauthorized();
        // Y una persona con sesión, desde la red agotada, no se entera.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->getJson('/api/admin/voice/status', $this->adminWithPermissions(['telephony.view']))->assertOk();
        Http::assertNothingSent();
    }

    private function assertTooMany(TestResponse $r): void
    {
        $r->assertStatus(429)->assertHeader('Retry-After')->assertJson(['ok' => false, 'code' => 'too_many_requests']);
        $this->assertGreaterThanOrEqual(1, (int) $r->json('retry_after'));
    }
}
