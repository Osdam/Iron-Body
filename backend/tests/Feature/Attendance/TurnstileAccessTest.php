<?php

namespace Tests\Feature\Attendance;

use App\Models\Admin;
use App\Models\TurnstileSetting;
use App\Services\SerialTurnstileService;
use App\Services\ZktecoTurnstileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * EL TORNIQUETE LO MANEJA UNA PERSONA.
 *
 * LOS FALLOS QUE SE CIERRAN
 *
 *  · `POST api/turnstile/webhook/fire` estaba marcado PÚBLICO en el mapa de
 *    autorización con el comentario «lo llama el hardware». No lo llama el
 *    hardware: es el servidor el que lanza una petición HTTP a la URL que le
 *    pasen y devuelve parte de la respuesta. Con eso, el token compartido —o
 *    cualquier sesión, tuviera el permiso que tuviera— hacía que el servidor
 *    consultara direcciones internas y le enseñara lo que contestaban.
 *
 *  · `GET api/turnstile` devuelve en claro las credenciales del dispositivo
 *    (`device_comm_key`, `auth_header`). Es lectura, así que resolvía a
 *    `classes.view`, y el token compartido obtiene toda lectura.
 *
 * LO QUE NO CAMBIA. Una persona con su permiso de siempre ve y usa todo esto
 * exactamente igual: el CRM lee esa misma respuesta para editar la
 * configuración. El lector local de la sede no llama a ninguna de estas rutas.
 */
class TurnstileAccessTest extends TestCase
{
    use RefreshDatabase;

    private const COMM_KEY = 'clave-del-dispositivo-123';

    private const AUTH_HEADER = 'Bearer secreto-del-rele';

    protected function setUp(): void
    {
        parent::setUp();

        TurnstileSetting::current()->forceFill([
            'device_comm_key' => self::COMM_KEY,
            'auth_header' => self::AUTH_HEADER,
            'webhook_url' => 'http://192.168.1.50:8081/zeroconf/switch',
        ])->save();
    }

    private function gestor(): array
    {
        return $this->adminWithPermissions(['classes.view', 'classes.manage']);
    }

    // ── El token compartido no toca el torniquete ───────────────────────────

    public function test_el_token_compartido_no_lee_las_credenciales_del_dispositivo(): void
    {
        $res = $this->getJson('/api/turnstile', $this->sharedTokenHeaders())
            ->assertStatus(403)
            ->assertJsonPath('code', 'person_required');

        $this->assertStringNotContainsString(self::COMM_KEY, (string) $res->getContent());
        $this->assertStringNotContainsString(self::AUTH_HEADER, (string) $res->getContent());
    }

    public function test_el_token_compartido_no_lanza_peticiones_desde_el_servidor(): void
    {
        Http::fake(['*' => Http::response('{"secreto":"interno"}', 200)]);

        $this->postJson('/api/turnstile/webhook/fire', [
            'url' => 'http://169.254.169.254/latest/meta-data/',
            'method' => 'GET',
        ], $this->sharedTokenHeaders())->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_el_token_compartido_no_abre_ni_configura_el_torniquete(): void
    {
        // Escribir ya exigía `classes.manage`, que el token no tiene: esto fija
        // que siga así para TODAS las rutas del torniquete.
        $this->mock(ZktecoTurnstileService::class)->shouldNotReceive('openDoor');
        $this->mock(SerialTurnstileService::class)->shouldNotReceive('open');
        Http::fake();

        $h = $this->sharedTokenHeaders();
        $this->putJson('/api/turnstile', ['enabled' => true], $h)->assertStatus(403);
        $this->postJson('/api/turnstile/trigger', [], $h)->assertStatus(403);
        $this->postJson('/api/turnstile/zkteco/open', ['host' => '10.0.0.1', 'port' => 22], $h)->assertStatus(403);
        $this->postJson('/api/turnstile/serial/open', ['port' => 'COM4'], $h)->assertStatus(403);

        Http::assertNothingSent();
        $this->assertFalse((bool) TurnstileSetting::current()->enabled);
    }

    // ── Disparar un webhook es gestionar el torniquete ──────────────────────

    public function test_ver_clases_no_basta_para_disparar_un_webhook(): void
    {
        // Recepción tiene `classes.view`: lee la configuración, pero no hace
        // que el servidor llame a una URL arbitraria.
        Http::fake();

        $this->postJson('/api/turnstile/webhook/fire', [
            'url' => 'http://192.168.1.50:8081/zeroconf/switch',
        ], $this->adminAs(Admin::ROLE_RECEPCION))
            ->assertStatus(403)
            ->assertJsonPath('required_permission', 'classes.manage');

        Http::assertNothingSent();
    }

    public function test_quien_gestiona_clases_sigue_disparando_el_webhook(): void
    {
        Http::fake(['*' => Http::response('{"error":0}', 200)]);

        $this->postJson('/api/turnstile/webhook/fire', [
            'url' => 'http://192.168.1.50:8081/zeroconf/switch',
            'method' => 'POST',
            'payload' => '{"data":{"switch":"on"}}',
        ], $this->gestor())
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.status', 200)
            ->assertJsonPath('result.body', '{"error":0}');

        Http::assertSentCount(1);
    }

    // ── Una persona lo ve igual que antes ───────────────────────────────────

    public function test_recepcion_sigue_leyendo_la_configuracion_completa(): void
    {
        // El CRM edita la configuración con esta misma respuesta: una persona
        // con permiso la recibe entera, credenciales incluidas.
        $this->getJson('/api/turnstile', $this->adminAs(Admin::ROLE_RECEPCION))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.device_comm_key', self::COMM_KEY)
            ->assertJsonPath('data.auth_header', self::AUTH_HEADER)
            ->assertJsonPath('data.webhook_url', 'http://192.168.1.50:8081/zeroconf/switch')
            ->assertJsonStructure(['data' => [
                'id', 'name', 'enabled', 'mode', 'device_host', 'device_port', 'device_comm_key',
                'serial_port', 'serial_baud', 'serial_command', 'webhook_url', 'http_method',
                'auth_header', 'request_payload', 'open_duration_ms', 'fire_on_entry', 'fire_on_exit',
                'sound_enabled', 'last_triggered_at', 'last_status', 'last_error', 'last_http_code',
            ]]);
    }

    public function test_quien_gestiona_clases_sigue_configurando_y_abriendo(): void
    {
        $h = $this->gestor();

        $this->putJson('/api/turnstile', ['name' => 'Torniquete sede'], $h)
            ->assertOk()
            ->assertJsonPath('data.name', 'Torniquete sede');

        $this->mock(ZktecoTurnstileService::class)
            ->shouldReceive('openDoor')->once()
            ->andReturn(['ok' => true, 'host' => '192.168.1.201', 'port' => 4370]);
        $this->postJson('/api/turnstile/zkteco/open', ['host' => '192.168.1.201'], $h)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->mock(SerialTurnstileService::class)
            ->shouldReceive('open')->once()
            ->andReturn(['ok' => true, 'port' => 'COM4', 'baud' => 9600, 'command' => 'PULSE 3000']);
        $this->postJson('/api/turnstile/serial/open', ['port' => 'COM4'], $h)
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
