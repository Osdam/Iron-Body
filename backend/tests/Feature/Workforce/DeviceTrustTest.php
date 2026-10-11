<?php

namespace Tests\Feature\Workforce;

use App\Models\AuthorizedDevice;
use App\Models\ClassEvent;
use App\Models\DeviceSecurityEvent;
use App\Support\Workforce\DeviceSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * El dispositivo se prueba con su clave, no con lo que dice ser.
 * Escenarios del encargo: 26 (autorizado), 27 (desconocido), 28 (revocado),
 * 29 (APK instalada en otra tablet), 30 (device_id falsificado),
 * 31 (desafío firmado reutilizado).
 */
class DeviceTrustTest extends TestCase
{
    use RefreshDatabase;
    use WorkforceKit;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_un_dispositivo_vinculado_con_codigo_de_administrador_queda_autorizado(): void
    {
        $tablet = $this->tablet();

        $device = AuthorizedDevice::query()->where('uuid', $tablet['uuid'])->firstOrFail();
        $this->assertSame('Tablet Recepción 01', $device->name);
        $this->assertSame('active', $device->status);
        $this->assertSame(hash('sha256', $tablet['der']), $device->key_fingerprint);
        // Sin atestación, lo declarado se guarda pero no se da por verificado.
        $this->assertSame('not_provided', $device->attestation_status);
        $this->assertSame('strongbox', $device->key_storage_declared);

        $this->signed('GET', '/api/kiosk/device/status', null, $tablet)
            ->assertOk()
            ->assertJsonPath('device.name', 'Tablet Recepción 01')
            ->assertJsonPath('device.status', 'active');
    }

    public function test_el_codigo_de_vinculacion_es_de_un_solo_uso(): void
    {
        $code = $this->pairingCode();
        $this->postJson('/api/kiosk/device/register', $this->registrationBody($code, $this->newKey()))->assertCreated();

        $this->postJson('/api/kiosk/device/register', $this->registrationBody($code, $this->newKey()))
            ->assertStatus(422)->assertJsonPath('code', 'pairing_code_invalid');
        $this->assertSame(1, DeviceSecurityEvent::query()->where('event', 'pairing_code_invalid')->count());
    }

    public function test_un_codigo_caducado_no_vincula(): void
    {
        $code = $this->pairingCode();
        Carbon::setTestNow(now()->addMinutes(16));

        $this->postJson('/api/kiosk/device/register', $this->registrationBody($code, $this->newKey()))
            ->assertStatus(422)->assertJsonPath('code', 'pairing_code_invalid');
    }

    public function test_sin_la_clave_privada_no_se_registra_una_clave_publica(): void
    {
        // Prueba de posesión firmada con OTRA clave: quien solo copió la
        // clave pública de alguien no puede registrarla como suya.
        $body = $this->registrationBody($this->pairingCode(), $this->newKey(), proofKey: $this->newKey());

        $this->postJson('/api/kiosk/device/register', $body)->assertStatus(422)->assertJsonPath('code', 'proof_invalid');
        $this->assertSame(0, AuthorizedDevice::count());
    }

    public function test_la_misma_clave_no_se_registra_dos_veces(): void
    {
        $key = $this->newKey();
        $this->postJson('/api/kiosk/device/register', $this->registrationBody($this->pairingCode(), $key))->assertCreated();

        $this->postJson('/api/kiosk/device/register', $this->registrationBody($this->pairingCode('Otra'), $key))
            ->assertStatus(409)->assertJsonPath('code', 'public_key_already_registered');
    }

    public function test_un_desafio_firmado_no_se_puede_reutilizar(): void
    {
        $tablet = $this->tablet();
        $nonce = $this->nonce($tablet);

        $this->signed('GET', '/api/kiosk/device/status', null, $tablet, nonce: $nonce)->assertOk();
        $this->signed('GET', '/api/kiosk/device/status', null, $tablet, nonce: $nonce)
            ->assertStatus(401)->assertJsonPath('code', 'device_challenge_invalid');

        $this->assertSame(1, DeviceSecurityEvent::query()->where('event', 'device_challenge_invalid')->count());
    }

    public function test_un_desafio_caducado_no_sirve(): void
    {
        $tablet = $this->tablet();
        $nonce = $this->nonce($tablet);
        Carbon::setTestNow(now()->addSeconds(121));

        $this->signed('GET', '/api/kiosk/device/status', null, $tablet, nonce: $nonce)
            ->assertStatus(401)->assertJsonPath('code', 'device_challenge_invalid');
    }

    public function test_la_firma_cubre_el_cuerpo_cambiar_un_byte_la_invalida(): void
    {
        $tablet = $this->tablet();
        $nonce = $this->nonce($tablet);
        // Se firma un documento y se envía otro con la misma firma.
        $canonical = DeviceSignature::canonical('POST', '/api/kiosk/employees/identify', $tablet['uuid'], $nonce, json_encode(['document' => '111']));
        $firma = base64_encode($this->sign($canonical, $tablet['key']));

        $this->signed('POST', '/api/kiosk/employees/identify', ['document' => '222'], $tablet, ['X-Device-Signature' => $firma], $nonce)
            ->assertStatus(401)->assertJsonPath('code', 'device_signature_invalid');
    }

    public function test_la_apk_copiada_a_otra_tablet_no_tiene_la_clave(): void
    {
        // La otra tablet conoce el id del dispositivo autorizado (lo copió),
        // pide su propio nonce y firma con SU clave.
        $tablet = $this->tablet();

        $this->signed('GET', '/api/kiosk/device/status', null, $tablet, signWith: $this->newKey())
            ->assertStatus(401)->assertJsonPath('code', 'device_signature_invalid');
        $this->assertSame(1, DeviceSecurityEvent::query()->where('event', 'device_signature_invalid')->count());
    }

    public function test_un_device_id_inventado_no_entra(): void
    {
        $falso = ['uuid' => (string) Str::uuid(), 'key' => $this->newKey(), 'der' => ''];

        $this->postJson('/api/kiosk/device/challenge', ['device_id' => $falso['uuid']])
            ->assertStatus(401)->assertJsonPath('code', 'device_unknown');
        $this->assertSame(1, DeviceSecurityEvent::query()->where('event', 'device_unknown')->count());
    }

    public function test_el_nonce_de_otro_dispositivo_no_sirve(): void
    {
        $a = $this->tablet('a', 'Tablet A');
        $b = $this->tablet('b', 'Tablet B');

        $this->signed('GET', '/api/kiosk/device/status', null, $b, nonce: $this->nonce($a))
            ->assertStatus(401)->assertJsonPath('code', 'device_challenge_invalid');
    }

    public function test_sin_cabeceras_de_firma_no_hay_operacion(): void
    {
        $this->getJson('/api/kiosk/device/status')->assertStatus(401)->assertJsonPath('code', 'device_signature_required');
    }

    public function test_un_dispositivo_revocado_desde_el_crm_deja_de_operar(): void
    {
        $tablet = $this->tablet();
        $this->postJson("/api/admin/devices/{$tablet['uuid']}/revoke", ['reason' => 'Tablet perdida en la sede'], $this->adminWithPermissions(['devices.manage']))
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->postJson('/api/kiosk/device/challenge', ['device_id' => $tablet['uuid']])
            ->assertStatus(403)->assertJsonPath('code', 'device_revoked');
        $this->assertSame(1, DeviceSecurityEvent::query()->where('event', 'device_revoked')->count());
    }

    public function test_un_nonce_pedido_antes_de_revocar_tambien_muere(): void
    {
        $tablet = $this->tablet();
        $nonce = $this->nonce($tablet);
        $this->postJson("/api/admin/devices/{$tablet['uuid']}/revoke", ['reason' => 'Revocada por pruebas'], $this->adminWithPermissions(['devices.manage']))->assertOk();

        $this->signed('GET', '/api/kiosk/device/status', null, $tablet, nonce: $nonce)
            ->assertStatus(403)->assertJsonPath('code', 'device_revoked');
    }

    public function test_vincular_y_revocar_exigen_su_permiso(): void
    {
        $this->postJson('/api/admin/devices/pairing-codes', ['name' => 'Tablet X'], $this->adminAs('Recepción'))->assertForbidden();
        $this->postJson('/api/admin/devices/pairing-codes', ['name' => 'Tablet X'], $this->adminWithPermissions(['devices.view']))->assertForbidden();
        $this->getJson('/api/admin/devices', $this->adminWithPermissions(['devices.view']))->assertOk();
        $this->getJson('/api/admin/devices', $this->sharedTokenHeaders())->assertForbidden();
    }

    public function test_los_intentos_se_consultan_desde_el_crm(): void
    {
        $this->postJson('/api/kiosk/device/challenge', ['device_id' => (string) Str::uuid()]);

        $this->getJson('/api/admin/devices/security-events', $this->adminWithPermissions(['devices.view']))
            ->assertOk()
            ->assertJsonPath('data.0.event', 'device_unknown')
            ->assertJsonPath('data.0.label', 'Dispositivo desconocido')
            ->assertJsonPath('meta.total', 1);
    }

    // ── Abuso sin credenciales (revisión de seguridad 2026-10-11, F4)

    public function test_ids_inventados_no_llenan_la_tabla_de_intentos(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/kiosk/device/challenge', ['device_id' => (string) Str::uuid()], ['X-Device-Id' => (string) Str::uuid()]);
        }

        $this->assertSame(1, DeviceSecurityEvent::query()->where('event', 'device_unknown')->count());
    }

    public function test_rotar_ids_no_da_cupo_infinito(): void
    {
        $codes = [];
        for ($i = 0; $i < 301; $i++) {
            $codes[] = $this->postJson('/api/kiosk/device/challenge', ['device_id' => (string) Str::uuid()], ['X-Device-Id' => (string) Str::uuid()])->status();
        }

        $this->assertContains(429, $codes);
    }

    public function test_insistir_con_una_tablet_revocada_no_inunda_el_crm(): void
    {
        $tablet = $this->tablet();
        $this->postJson("/api/admin/devices/{$tablet['uuid']}/revoke", ['reason' => 'Tablet perdida en la sede'], $this->adminWithPermissions(['devices.manage']))->assertOk();
        $antes = ClassEvent::query()->where('type', 'device.updated')->count();

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/kiosk/device/challenge', ['device_id' => $tablet['uuid']])->assertStatus(403);
        }

        $this->assertSame(1, DeviceSecurityEvent::query()->where('event', 'device_revoked')->count());
        $this->assertSame($antes + 1, ClassEvent::query()->where('type', 'device.updated')->count());
    }

    public function test_vincular_tiene_cupo_propio(): void
    {
        // Otra ruta `throttle:N,1` desde la misma IP no gasta el cupo de vincular (F3).
        config(['trainer.flags.trainer_auth_enabled' => true]);
        $ultimo = null;
        for ($i = 0; $i < 8; $i++) {
            $ultimo = $this->postJson('/api/trainer/auth/access', ['document' => '1'])->status();
        }
        $this->assertSame(429, $ultimo, 'La otra ruta sí agotó su cupo por IP.');
        $this->postJson('/api/kiosk/device/register', $this->registrationBody($this->pairingCode(), $this->newKey()))->assertCreated();
    }

    public function test_reintentar_un_registro_completado_devuelve_el_mismo_dispositivo(): void
    {
        // La respuesta del registro se perdió: la tablet reintenta con el mismo
        // código y la misma clave y recupera su dispositivo (no queda huérfana).
        $key = $this->newKey();
        $code = $this->pairingCode();
        $first = $this->postJson('/api/kiosk/device/register', $this->registrationBody($code, $key))->assertCreated();

        $this->postJson('/api/kiosk/device/register', $this->registrationBody($code, $key))
            ->assertOk()->assertJsonPath('device.id', $first->json('device.id'));
        $this->assertSame(1, AuthorizedDevice::count());

        // Otro código con la misma clave sigue siendo un duplicado.
        $this->postJson('/api/kiosk/device/register', $this->registrationBody($this->pairingCode('Otra'), $key))
            ->assertStatus(409)->assertJsonPath('code', 'public_key_already_registered');
    }
}
