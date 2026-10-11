<?php

namespace Tests\Feature\Workforce\Concerns;

use App\Models\Identity;
use App\Models\StaffProfile;
use App\Models\Trainer;
use App\Models\TrainerRole;
use App\Services\Identity\IdentityLinkService;
use App\Support\Workforce\DeviceSignature;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Utilería de las pruebas del kiosko de personal.
 *
 * El «dispositivo» es una clave EC P-256 REAL generada con OpenSSL: firma la
 * cadena canónica del contrato exactamente como lo hará el Keystore de la
 * tablet. Nada de la verificación del servidor se finge.
 */
trait WorkforceKit
{
    /** @var array<string, array{uuid:string, key:\OpenSSLAsymmetricKey, der:string}> */
    protected array $tablets = [];

    protected function newKey(): \OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    }

    protected function spkiDer(\OpenSSLAsymmetricKey $key): string
    {
        $pem = openssl_pkey_get_details($key)['key'];

        return base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem));
    }

    protected function sign(string $data, \OpenSSLAsymmetricKey $key): string
    {
        openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);

        return $signature;
    }

    /** Pide un código de vinculación como administrador. */
    protected function pairingCode(string $name = 'Tablet Recepción 01', ?string $location = 'Sede principal'): string
    {
        return $this->postJson('/api/admin/devices/pairing-codes', ['name' => $name, 'location' => $location], $this->adminWithPermissions(['devices.manage']))
            ->assertCreated()
            ->json('code');
    }

    /** @return array<string, mixed> */
    protected function registrationBody(string $code, \OpenSSLAsymmetricKey $key, ?\OpenSSLAsymmetricKey $proofKey = null): array
    {
        $der = $this->spkiDer($key);
        $normalized = strtoupper((string) preg_replace('/[\s\-]+/', '', $code));

        return [
            'pairing_code' => $code,
            'public_key' => base64_encode($der),
            'key_algorithm' => 'ES256',
            'proof' => base64_encode($this->sign(DeviceSignature::registrationProof($normalized, $der), $proofKey ?? $key)),
            'key_storage' => 'strongbox',
            'attestation' => null,
            'device' => ['platform' => 'android', 'manufacturer' => 'samsung', 'model' => 'SM-X200', 'os_version' => '14', 'app_version' => '2.1.0+16'],
        ];
    }

    /** Vincula una tablet y la recuerda con un alias. */
    protected function tablet(string $alias = 'recepcion', string $name = 'Tablet Recepción 01'): array
    {
        if (isset($this->tablets[$alias])) {
            return $this->tablets[$alias];
        }
        $key = $this->newKey();
        $uuid = $this->postJson('/api/kiosk/device/register', $this->registrationBody($this->pairingCode($name), $key))
            ->assertCreated()
            ->json('device.id');

        return $this->tablets[$alias] = ['uuid' => $uuid, 'key' => $key, 'der' => $this->spkiDer($key)];
    }

    protected function nonce(array $tablet): string
    {
        return $this->postJson('/api/kiosk/device/challenge', ['device_id' => $tablet['uuid']])->assertOk()->json('nonce');
    }

    /**
     * Petición firmada con los bytes EXACTOS que se hashean.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string>  $override  cabeceras a falsear en una prueba
     */
    protected function signed(string $method, string $uri, ?array $body, array $tablet, array $override = [], ?string $nonce = null, ?\OpenSSLAsymmetricKey $signWith = null): TestResponse
    {
        $content = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
        $nonce ??= $this->nonce($tablet);
        $canonical = DeviceSignature::canonical($method, parse_url($uri, PHP_URL_PATH), $tablet['uuid'], $nonce, $content);
        $headers = array_merge([
            'X-Device-Id' => $tablet['uuid'],
            'X-Device-Nonce' => $nonce,
            'X-Device-Signature' => base64_encode($this->sign($canonical, $signWith ?? $tablet['key'])),
        ], $override);

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $content);
    }

    // ── Rostros ─────────────────────────────────────────────────────────────

    /** Vector unitario determinista: «la cara» de alguien. */
    protected function face(int $seed): array
    {
        mt_srand($seed);
        $v = array_map(fn () => mt_rand(-1000, 1000) / 1000, range(1, 192));

        return $this->unit($v);
    }

    /** Otra captura de la MISMA cara: un poco de ruido, renormalizada. */
    protected function capture(array $face, int $seed, float $noise = 0.02): array
    {
        mt_srand($seed);

        return $this->unit(array_map(fn ($x) => $x + (mt_rand(-1000, 1000) / 1000) * $noise, $face));
    }

    protected function unit(array $v): array
    {
        $n = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));

        return array_map(fn ($x) => $x / $n, $v);
    }

    // ── Personas ────────────────────────────────────────────────────────────

    protected function receptionist(string $document = '1075000001', string $name = 'Ana María Rojas'): Identity
    {
        $identity = app(IdentityLinkService::class)->ensureIdentity($document);
        StaffProfile::create([
            'identity_id' => $identity->id,
            'full_name' => $name,
            'positions' => ['receptionist'],
            'status' => StaffProfile::STATUS_ACTIVE,
        ]);

        return $identity;
    }

    /** @param list<string> $roles */
    protected function trainerPerson(string $document, array $roles, string $name = 'Coach Carlos Pérez'): Trainer
    {
        $identity = app(IdentityLinkService::class)->ensureIdentity($document);
        $trainer = Trainer::create(['full_name' => $name, 'document' => $document, 'status' => 'active', 'identity_id' => $identity->id]);
        $trainer->syncRoles($roles);

        return $trainer;
    }

    protected function floorTrainer(string $document = '1075000002'): Trainer
    {
        return $this->trainerPerson($document, [TrainerRole::FLOOR]);
    }

    // ── Flujos del kiosko ───────────────────────────────────────────────────

    protected function identify(string $document, array $tablet): TestResponse
    {
        return $this->signed('POST', '/api/kiosk/employees/identify', ['document' => $document], $tablet);
    }

    protected function ref(string $document, array $tablet): string
    {
        return (string) $this->identify($document, $tablet)->assertOk()->json('employee.ref');
    }

    /** Enrola por el camino real: código de un administrador + consentimiento + 3 capturas. */
    protected function enroll(Identity $identity, string $document, array $face, array $tablet): TestResponse
    {
        $code = $this->postJson("/api/admin/biometrics/identities/{$identity->uuid}/enrollment-code", ['reason' => 'Primer registro en recepción'], $this->adminWithPermissions(['biometrics.manage']))
            ->assertCreated()->json('code');
        $start = $this->signed('POST', '/api/kiosk/enrollment/start', ['employee_ref' => $this->ref($document, $tablet), 'authorization_code' => $code], $tablet)->assertOk();

        return $this->signed('POST', '/api/kiosk/enrollment/complete', [
            'enrollment_token' => $start->json('enrollment.token'),
            'consent_version' => $start->json('consent.version'),
            'consent_accepted' => true,
            'embeddings' => [$this->capture($face, 1), $this->capture($face, 2), $this->capture($face, 3)],
            'operation_id' => (string) Str::uuid(),
        ], $tablet);
    }

    protected function verifyFace(string $document, array $face, array $tablet, string $purpose = 'workday'): TestResponse
    {
        return $this->signed('POST', '/api/kiosk/verify', [
            'employee_ref' => $this->ref($document, $tablet),
            'purpose' => $purpose,
            'embedding' => $this->capture($face, 99),
        ], $tablet);
    }

    protected function clock(string $action, string $verification, array $tablet, ?string $operationId = null): TestResponse
    {
        return $this->signed('POST', "/api/kiosk/workday/clock-{$action}", [
            'verification_token' => $verification,
            'operation_id' => $operationId ?? (string) Str::uuid(),
        ], $tablet);
    }
}
