<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\AuthorizedDevice;
use App\Models\DeviceChallenge;
use App\Models\DevicePairingCode;
use App\Models\DeviceSecurityEvent;
use App\Support\Workforce\DeviceSignature;
use App\Support\Workforce\KeyAttestation;
use App\Support\Workforce\Secrets;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dispositivos autorizados: vincular, desafiar, verificar, revocar.
 *
 * La identidad del dispositivo es su clave privada no exportable. Lo que la app
 * declara (modelo, versión, «soy la tablet de recepción») se guarda y se
 * enseña, pero no autoriza nada: autoriza la firma.
 *
 * No impide TODA suplantación —un dispositivo vinculado y robado firma igual—;
 * por eso va combinado con la identidad de la persona (rostro o código), su
 * rol, la hora del servidor y la auditoría.
 */
class DeviceRegistry
{
    public const HEADER_ID = 'X-Device-Id';

    public const HEADER_NONCE = 'X-Device-Nonce';

    public const HEADER_SIGNATURE = 'X-Device-Signature';

    public function __construct(private readonly WorkforceEvents $events) {}

    // ── Vinculación ─────────────────────────────────────────────────────────

    /** @return array{code:string, expires_at:Carbon} */
    public function issuePairingCode(string $name, ?string $location, ?int $adminId, ?string $adminName): array
    {
        $code = Secrets::pairingCode();
        $model = DevicePairingCode::create([
            'code_hash' => Secrets::hash('pairing|'.$code),
            'name' => mb_substr($name, 0, 120),
            'location' => $location !== null ? mb_substr($location, 0, 120) : null,
            'created_by_admin_id' => $adminId,
            'created_by_name' => $adminName !== null ? mb_substr($adminName, 0, 120) : null,
            'expires_at' => now()->addMinutes((int) config('workforce.codes.pairing_ttl_minutes', 15)),
        ]);

        return ['code' => Secrets::displayPairingCode($code), 'expires_at' => $model->expires_at];
    }

    /**
     * Registra el dispositivo. Exige: código de vinculación vivo, clave pública
     * EC P-256 y la prueba de que la app tiene la clave privada (firma sobre el
     * código y la huella de la clave). La atestación, si viene, se verifica y
     * se guarda como prueba adicional.
     *
     * @param  array<string, mixed>  $data  validados por el controlador
     */
    public function register(array $data, Request $request): AuthorizedDevice
    {
        $code = Secrets::normalizeCode((string) $data['pairing_code']);
        $publicDer = DeviceSignature::decodeBase64((string) $data['public_key']);
        $pem = $publicDer !== null ? DeviceSignature::publicKeyPem($publicDer) : null;
        if ($pem === null) {
            throw new WorkforceException('La clave del dispositivo no es válida.', 'public_key_invalid', 422);
        }

        $proof = DeviceSignature::decodeBase64((string) $data['proof']) ?? '';
        if (! DeviceSignature::verify(DeviceSignature::registrationProof($code, $publicDer), $proof, $pem)) {
            throw new WorkforceException('No pudimos comprobar la clave del dispositivo.', 'proof_invalid', 422);
        }

        $fingerprint = hash('sha256', $publicDer);
        $existing = AuthorizedDevice::query()->where('key_fingerprint', $fingerprint)->first();
        if ($existing !== null) {
            // Reintento de un registro que SÍ se completó (la respuesta se
            // perdió): misma clave y el mismo código, que consumió ESTE
            // dispositivo. Se devuelve el dispositivo en vez de dejar la tablet
            // con una clave que el servidor ya conoce y sin forma de vincularse.
            $sameCode = DevicePairingCode::query()
                ->where('code_hash', Secrets::hash('pairing|'.$code))
                ->where('consumed_device_id', $existing->id)
                ->exists();
            if ($sameCode && $existing->isActive()) {
                $existing->setAttribute('replayed', true);

                return $existing;
            }
            throw new WorkforceException('Esta clave ya está registrada.', 'public_key_already_registered', 409);
        }

        $attestation = $this->attestation($data, $code, $publicDer);

        try {
            return DB::transaction(function () use ($data, $code, $publicDer, $fingerprint, $attestation, $request): AuthorizedDevice {
                /** @var DevicePairingCode|null $pairing */
                $pairing = DevicePairingCode::query()
                    ->where('code_hash', Secrets::hash('pairing|'.$code))
                    ->whereNull('consumed_at')
                    ->where('expires_at', '>', now())
                    ->lockForUpdate()
                    ->first();
                if ($pairing === null) {
                    throw WorkforceException::pairingCodeInvalid();
                }

                $declared = (array) ($data['device'] ?? []);
                $device = AuthorizedDevice::create([
                    'uuid' => (string) Str::uuid(),
                    'name' => $pairing->name,
                    'location' => $pairing->location,
                    'status' => AuthorizedDevice::STATUS_ACTIVE,
                    'platform' => $this->str($declared['platform'] ?? null, 20),
                    'manufacturer' => $this->str($declared['manufacturer'] ?? null, 80),
                    'model' => $this->str($declared['model'] ?? null, 80),
                    'os_version' => $this->str($declared['os_version'] ?? null, 40),
                    'app_version' => $this->str($declared['app_version'] ?? null, 40),
                    'key_storage_declared' => $this->str($data['key_storage'] ?? null, 20),
                    'key_algorithm' => 'ES256',
                    'public_key' => base64_encode($publicDer),
                    'key_fingerprint' => $fingerprint,
                    'attestation_status' => $attestation['status'],
                    'attestation_security_level' => $attestation['security_level'],
                    'attestation_summary' => $attestation['reason'] !== null ? ['reason' => $attestation['reason']] : null,
                    'registered_at' => now(),
                    'registered_by_admin_id' => $pairing->created_by_admin_id,
                    'registered_by_name' => $pairing->created_by_name,
                    'last_seen_at' => now(),
                    'last_ip' => $request->ip(),
                ]);

                $pairing->forceFill(['consumed_at' => now(), 'consumed_device_id' => $device->id])->save();
                $this->events->publish(WorkforceEvents::DEVICE_UPDATED, 'registered', $device->uuid, WorkforceEvents::SOURCE_KIOSK);

                return $device;
            });
        } catch (WorkforceException $e) {
            if ($e->code_ === 'pairing_code_invalid') {
                $this->securityEvent(DeviceSecurityEvent::PAIRING_CODE_INVALID, null, null, $request);
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status:string, security_level:?string, reason:?string}
     */
    private function attestation(array $data, string $code, string $publicDer): array
    {
        $chain = $data['attestation']['certificate_chain'] ?? null;
        if (! is_array($chain) || $chain === []) {
            return ['status' => 'not_provided', 'security_level' => null, 'reason' => null];
        }
        $der = [];
        foreach ($chain as $c) {
            $d = is_string($c) ? DeviceSignature::decodeBase64($c) : null;
            if ($d === null) {
                return ['status' => 'unverified', 'security_level' => null, 'reason' => 'chain_unreadable'];
            }
            $der[] = $d;
        }

        return KeyAttestation::verify($der, $code, $publicDer);
    }

    // ── Desafíos y verificación ─────────────────────────────────────────────

    public function challenge(string $deviceUuid, Request $request): DeviceChallenge
    {
        $device = $this->activeDevice($deviceUuid, $request, 'kiosk/device/challenge');

        return DeviceChallenge::create([
            'authorized_device_id' => $device->id,
            'nonce' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'expires_at' => now()->addSeconds((int) config('workforce.devices.challenge_ttl', 120)),
            'created_at' => now(),
        ]);
    }

    public function hasSignatureHeaders(Request $request): bool
    {
        return $request->hasHeader(self::HEADER_ID)
            || $request->hasHeader(self::HEADER_NONCE)
            || $request->hasHeader(self::HEADER_SIGNATURE);
    }

    /**
     * Comprueba la firma de la petición. El nonce se consume ANTES de mirar la
     * firma: una firma fallida también lo quema, así que no se puede tantear.
     */
    public function verifyRequest(Request $request): AuthorizedDevice
    {
        $operation = DeviceSignature::normalizePath($request->path());
        $uuid = (string) $request->header(self::HEADER_ID, '');
        $nonce = (string) $request->header(self::HEADER_NONCE, '');
        $signature = DeviceSignature::decodeBase64((string) $request->header(self::HEADER_SIGNATURE, ''));

        // La query string no entra en la firma: en una ruta firmada no se admite
        // (podría añadirse a una petición legítima sin romperla; I3).
        if ((string) $request->getQueryString() !== '') {
            $this->securityEvent(DeviceSecurityEvent::SIGNATURE_INVALID, null, $uuid !== '' ? $uuid : null, $request, $operation);

            throw WorkforceException::signatureInvalid();
        }

        if ($uuid === '' || $nonce === '' || $signature === null) {
            $this->securityEvent(DeviceSecurityEvent::SIGNATURE_REQUIRED, null, $uuid !== '' ? $uuid : null, $request, $operation);

            throw WorkforceException::deviceSignatureRequired();
        }

        $device = $this->activeDevice($uuid, $request, $operation);

        $consumed = DeviceChallenge::query()
            ->where('nonce', $nonce)
            ->where('authorized_device_id', $device->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]);
        if ($consumed !== 1) {
            $this->securityEvent(DeviceSecurityEvent::CHALLENGE_INVALID, $device, $uuid, $request, $operation);

            throw WorkforceException::challengeInvalid();
        }

        $pem = DeviceSignature::publicKeyPem((string) base64_decode((string) $device->public_key, true));
        $canonical = DeviceSignature::canonical($request->method(), $request->path(), $uuid, $nonce, $request->getContent());
        if ($pem === null || ! DeviceSignature::verify($canonical, $signature, $pem)) {
            $this->securityEvent(DeviceSecurityEvent::SIGNATURE_INVALID, $device, $uuid, $request, $operation);

            throw WorkforceException::signatureInvalid();
        }

        if ($device->last_seen_at === null || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        }

        return $device;
    }

    // ── Administración ──────────────────────────────────────────────────────

    public function revoke(AuthorizedDevice $device, string $reason, ?int $adminId, ?string $adminName): void
    {
        if (! $device->isActive()) {
            return;
        }
        DB::transaction(function () use ($device, $reason, $adminId, $adminName): void {
            $device->forceFill([
                'status' => AuthorizedDevice::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_by_admin_id' => $adminId,
                'revoked_by_name' => $adminName !== null ? mb_substr($adminName, 0, 120) : null,
                'revoked_reason' => mb_substr($reason, 0, 500),
            ])->save();
            // Los desafíos pendientes mueren con él.
            DeviceChallenge::query()->where('authorized_device_id', $device->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $this->events->publish(WorkforceEvents::DEVICE_UPDATED, 'revoked', $device->uuid, WorkforceEvents::SOURCE_CRM);
        });
    }

    public function rename(AuthorizedDevice $device, ?string $name, ?string $location): void
    {
        DB::transaction(function () use ($device, $name, $location): void {
            $device->fill(array_filter([
                'name' => $name !== null ? mb_substr($name, 0, 120) : null,
                'location' => $location !== null ? mb_substr($location, 0, 120) : null,
            ], fn ($v) => $v !== null))->save();
            $this->events->publish(WorkforceEvents::DEVICE_UPDATED, 'renamed', $device->uuid, WorkforceEvents::SOURCE_CRM);
        });
    }

    // ── Interno ─────────────────────────────────────────────────────────────

    private function activeDevice(string $uuid, Request $request, string $operation): AuthorizedDevice
    {
        $device = Str::isUuid($uuid) ? AuthorizedDevice::query()->where('uuid', $uuid)->first() : null;
        if ($device === null) {
            $this->securityEvent(DeviceSecurityEvent::UNKNOWN_DEVICE, null, $uuid, $request, $operation);

            throw WorkforceException::deviceUnknown();
        }
        if (! $device->isActive()) {
            $this->securityEvent(DeviceSecurityEvent::REVOKED_DEVICE, $device, $uuid, $request, $operation);

            throw WorkforceException::deviceRevoked();
        }

        return $device;
    }

    /**
     * Registra el intento. Fuera de cualquier transacción del negocio y sin
     * dejar que un fallo de escritura tape el rechazo original.
     */
    public function securityEvent(string $event, ?AuthorizedDevice $device, ?string $claimed, Request $request, ?string $operation = null): void
    {
        try {
            // Un intento repetido es UN intento: como mucho una fila por tipo,
            // id reclamado e IP por minuto. Sin esto, rotar ids inventados sin
            // credenciales llenaba la tabla y enterraba las señales reales (F4).
            // Un id inventado no dice nada: los «desconocidos» se agrupan solo por IP.
            $claimedKey = $event === DeviceSecurityEvent::UNKNOWN_DEVICE ? '*' : ($claimed ?? '-');
            $key = 'device-sec:'.hash('sha256', $event.'|'.$claimedKey.'|'.$request->ip());
            if (! Cache::add($key, 1, 60)) {
                return;
            }

            DeviceSecurityEvent::create([
                'authorized_device_id' => $device?->id,
                'claimed_device_id' => $claimed !== null ? mb_substr($claimed, 0, 64) : null,
                'event' => $event,
                'operation' => $operation !== null ? mb_substr($operation, 0, 120) : DeviceSignature::normalizePath($request->path()),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
            // Y como mucho un aviso por dispositivo y minuto al CRM.
            if ($device !== null && Cache::add('device-sec-signal:'.$device->id, 1, 60)) {
                $this->events->publish(WorkforceEvents::DEVICE_UPDATED, 'security_event', $device->uuid, WorkforceEvents::SOURCE_SYSTEM);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function str(mixed $value, int $max): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}
