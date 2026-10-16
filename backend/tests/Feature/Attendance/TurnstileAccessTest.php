<?php

namespace Tests\Feature\Attendance;

use App\Models\Admin;
use App\Models\TurnstileSetting;
use App\Services\SerialTurnstileService;
use App\Services\ZktecoTurnstileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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
            // Solo el estado HTTP: el cuerpo de lo que contesta el destino no
            // vuelve al cliente (ver la sección SSRF de abajo).
            ->assertJsonMissingPath('result.body');

        Http::assertSentCount(1);
    }

    // ── Una persona lo ve igual que antes ───────────────────────────────────

    public function test_recepcion_sigue_leyendo_la_configuracion_sin_las_credenciales(): void
    {
        // Recepción y el lector de la sede leen la configuración, pero las
        // credenciales del dispositivo ya no salen en claro: solo se dice que
        // existen. El formulario del CRM no las envía al guardar.
        $res = $this->getJson('/api/turnstile', $this->adminAs(Admin::ROLE_RECEPCION))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.device_comm_key', '••••')
            ->assertJsonPath('data.has_device_comm_key', true)
            ->assertJsonPath('data.auth_header', '••••')
            ->assertJsonPath('data.has_auth_header', true)
            ->assertJsonPath('data.webhook_url', 'http://192.168.1.50:8081/zeroconf/switch')
            ->assertJsonStructure(['data' => [
                'id', 'name', 'enabled', 'mode', 'device_host', 'device_port', 'device_comm_key',
                'serial_port', 'serial_baud', 'serial_command', 'webhook_url', 'http_method',
                'auth_header', 'request_payload', 'open_duration_ms', 'fire_on_entry', 'fire_on_exit',
                'sound_enabled', 'last_triggered_at', 'last_status', 'last_error', 'last_http_code',
            ]]);

        $this->assertStringNotContainsString(self::COMM_KEY, (string) $res->getContent());
        $this->assertStringNotContainsString('secreto-del-rele', (string) $res->getContent());
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

    // ── SSRF: a dónde puede llamar el servidor ──────────────────────────────
    //
    // Disparar el relé es que el SERVIDOR haga una petición a una URL o abra
    // un socket a un host que llega en el cuerpo. Quien gestiona clases lo
    // sigue pudiendo hacer hacia la red de la sede (rangos privados: es donde
    // vive un relé Sonoff/Shelly/ESP32 o un ZKTeco), pero nunca hacia el
    // propio servidor ni hacia los metadatos de la nube; sin redirecciones, y
    // sin devolver ni guardar lo que contesta el destino: solo su estado HTTP.

    public static function destinosProhibidos(): array
    {
        return [
            'metadatos de la nube' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback' => ['http://127.0.0.1:8000/api/health'],
            'loopback por nombre' => ['http://localhost/'],
            'loopback decimal' => ['http://2130706433/'],
            'loopback ipv6' => ['http://[::1]/'],
            'ipv4 mapeada en ipv6' => ['http://[::ffff:127.0.0.1]/'],
            'sin especificar' => ['http://0.0.0.0:80/'],
            'enlace local ipv6' => ['http://[fe80::1]/'],
            'con usuario delante del host' => ['http://relay@127.0.0.1/'],
        ];
    }

    #[DataProvider('destinosProhibidos')]
    public function test_el_webhook_no_llama_al_propio_servidor_ni_a_los_metadatos(string $url): void
    {
        Http::fake(['*' => Http::response('{"AccessKeyId":"AKIA-SECRETO"}', 200)]);

        $res = $this->postJson('/api/turnstile/webhook/fire', ['url' => $url, 'method' => 'GET'], $this->gestor())
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'destination_not_allowed');

        Http::assertNothingSent();
        $this->assertStringNotContainsString('AKIA', (string) $res->getContent());
    }

    public function test_la_red_de_la_sede_sigue_permitida(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        foreach (['http://10.0.0.5/relay', 'http://172.16.4.2:8081/zeroconf/switch', 'http://192.168.1.50/'] as $url) {
            $this->postJson('/api/turnstile/webhook/fire', ['url' => $url], $this->gestor())->assertOk();
        }

        Http::assertSentCount(3);
    }

    public function test_el_webhook_no_sigue_redirecciones(): void
    {
        Http::fake([
            '192.168.1.50:8081/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '169.254.169.254/*' => Http::response('{"AccessKeyId":"AKIA-SECRETO"}', 200),
        ]);

        $res = $this->postJson('/api/turnstile/webhook/fire', [
            'url' => 'http://192.168.1.50:8081/zeroconf/switch', 'method' => 'GET',
        ], $this->gestor())
            ->assertStatus(502)
            ->assertJsonPath('result.status', 302);

        Http::assertSentCount(1);
        $this->assertStringNotContainsString('AKIA', (string) $res->getContent());
    }

    public function test_lo_que_contesta_el_destino_no_se_devuelve_ni_se_guarda(): void
    {
        Http::fake(['*' => Http::response('db_password=secreto-interno', 500)]);

        $res = $this->postJson('/api/turnstile/webhook/fire', [
            'url' => 'http://192.168.1.50:8081/zeroconf/switch',
        ], $this->gestor())
            ->assertStatus(502)
            ->assertJsonPath('result.status', 500)
            ->assertJsonPath('data.last_http_code', 500);

        $this->assertStringNotContainsString('secreto-interno', (string) $res->getContent());
        $this->assertStringNotContainsString('secreto-interno', (string) TurnstileSetting::current()->last_error);
    }

    public function test_un_fallo_de_conexion_no_devuelve_el_detalle_interno(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to 10.0.0.9 port 6379: Connection refused'));

        $res = $this->postJson('/api/turnstile/webhook/fire', ['url' => 'http://10.0.0.9:6379/'], $this->gestor())
            ->assertStatus(502)
            ->assertJsonPath('ok', false);

        $this->assertStringNotContainsString('6379', (string) $res->getContent());
        $this->assertStringNotContainsString('Connection refused', (string) TurnstileSetting::current()->last_error);
    }

    public function test_la_url_guardada_tampoco_llama_a_destinos_prohibidos(): void
    {
        // Mismo SSRF por el otro camino: guardar la URL y disparar.
        Http::fake(['*' => Http::response('{"AccessKeyId":"AKIA-SECRETO"}', 200)]);
        $h = $this->gestor();
        $this->putJson('/api/turnstile', [
            'enabled' => true, 'mode' => 'webhook', 'http_method' => 'GET',
            'webhook_url' => 'http://169.254.169.254/latest/meta-data/',
        ], $h)->assertOk();

        $res = $this->postJson('/api/turnstile/trigger', [], $h)
            ->assertStatus(502)
            ->assertJsonPath('result.reason', 'destination_not_allowed');

        Http::assertNothingSent();
        $this->assertStringNotContainsString('AKIA', (string) $res->getContent());
    }

    public function test_el_disparo_con_la_url_guardada_tampoco_devuelve_el_cuerpo(): void
    {
        Http::fake(['*' => Http::response('respuesta-interna-del-rele', 200)]);
        $h = $this->gestor();
        $this->putJson('/api/turnstile', ['enabled' => true, 'mode' => 'webhook'], $h)->assertOk();

        $res = $this->postJson('/api/turnstile/trigger', [], $h)
            ->assertOk()
            ->assertJsonPath('result.status', 200);

        Http::assertSentCount(1);
        $this->assertStringNotContainsString('respuesta-interna', (string) $res->getContent());
    }

    public static function hostsProhibidos(): array
    {
        return [
            'loopback' => ['127.0.0.1'],
            'loopback por nombre' => ['localhost'],
            'metadatos' => ['169.254.169.254'],
            'loopback ipv6' => ['::1'],
            'sin especificar' => ['0.0.0.0'],
        ];
    }

    #[DataProvider('hostsProhibidos')]
    public function test_zkteco_no_abre_sockets_hacia_el_propio_servidor(string $host): void
    {
        $this->mock(ZktecoTurnstileService::class)->shouldNotReceive('openDoor');

        $this->postJson('/api/turnstile/zkteco/open', ['host' => $host, 'port' => 6379], $this->gestor())
            ->assertStatus(422)
            ->assertJsonPath('code', 'destination_not_allowed');
    }

    // ── Credenciales del dispositivo: se escriben, no se leen ───────────────

    public function test_guardar_sin_tocar_las_credenciales_las_conserva(): void
    {
        $h = $this->gestor();

        // Lo que envía hoy el CRM (persistSerialPort): sin credenciales.
        $this->putJson('/api/turnstile', [
            'mode' => 'serial', 'serial_port' => 'COM4', 'serial_baud' => 9600,
            'serial_command' => 'PULSE 3000', 'enabled' => true,
        ], $h)->assertOk();
        // Un formulario que reenvía lo que leyó (enmascarado) o las deja vacías.
        $this->putJson('/api/turnstile', ['device_comm_key' => '••••', 'auth_header' => '••••'], $h)->assertOk();
        $this->putJson('/api/turnstile', ['device_comm_key' => '', 'auth_header' => null], $h)->assertOk();

        $s = TurnstileSetting::current();
        $this->assertSame(self::COMM_KEY, $s->device_comm_key);
        $this->assertSame(self::AUTH_HEADER, $s->auth_header);
        $this->assertSame('COM4', $s->serial_port);
    }

    public function test_una_credencial_nueva_se_guarda_y_no_se_devuelve(): void
    {
        $res = $this->putJson('/api/turnstile', ['device_comm_key' => '987654', 'auth_header' => 'Bearer otro'], $this->gestor())
            ->assertOk()
            ->assertJsonPath('data.device_comm_key', '••••')
            ->assertJsonPath('data.auth_header', '••••');

        $this->assertStringNotContainsString('987654', (string) $res->getContent());
        $s = TurnstileSetting::current();
        $this->assertSame('987654', $s->device_comm_key);
        $this->assertSame('Bearer otro', $s->auth_header);
    }
}
