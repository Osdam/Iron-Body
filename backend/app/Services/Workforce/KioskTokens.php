<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\AuthorizedDevice;
use App\Models\Identity;
use App\Models\WorkforceKioskToken;
use App\Support\Workforce\Secrets;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Lo que el kiosko sostiene entre una pantalla y la siguiente, y nada más.
 *
 *  · Referencia de empleado: opaca (cifrada), 10 min, solo en el dispositivo
 *    que la pidió. Ningún id interno viaja a la tablet.
 *  · Verificación: la identidad ya comprobada (rostro o código), para UNA
 *    operación, 120 s, ligada a persona + dispositivo + propósito.
 *  · Enrolamiento: el código del administrador ya aceptado, pendiente de las
 *    capturas.
 *
 * Nada de esto es una sesión: la tablet compartida no guarda a nadie.
 */
class KioskTokens
{
    public function employeeRef(Identity $identity, AuthorizedDevice $device): string
    {
        return Crypt::encryptString(json_encode([
            'i' => $identity->getKey(),
            'd' => $device->getKey(),
            'e' => now()->addSeconds((int) config('workforce.tokens.employee_ref_ttl', 600))->getTimestamp(),
        ]));
    }

    public function resolveRef(string $ref, AuthorizedDevice $device): Identity
    {
        try {
            $data = json_decode(Crypt::decryptString($ref), true);
        } catch (DecryptException) {
            throw WorkforceException::employeeRefInvalid();
        }
        if (! is_array($data) || ($data['d'] ?? null) !== $device->getKey() || ($data['e'] ?? 0) < now()->getTimestamp()) {
            throw WorkforceException::employeeRefInvalid();
        }
        $identity = Identity::find($data['i'] ?? 0);
        if ($identity === null) {
            throw WorkforceException::employeeRefInvalid();
        }

        return $identity;
    }

    /** @return array{token:string, expires_at:Carbon} */
    public function issueVerification(Identity $identity, AuthorizedDevice $device, string $purpose, string $method, ?float $distance = null, ?int $codeId = null): array
    {
        return $this->issue(WorkforceKioskToken::KIND_VERIFICATION, 'vt', $identity, $device, $purpose, $method, $distance, $codeId,
            (int) config('workforce.tokens.verification_ttl', 120));
    }

    /** @return array{token:string, expires_at:Carbon} */
    public function issueEnrollment(Identity $identity, AuthorizedDevice $device, int $codeId): array
    {
        return $this->issue(WorkforceKioskToken::KIND_ENROLLMENT, 'et', $identity, $device, 'enrollment', null, null, $codeId,
            (int) config('workforce.tokens.enrollment_ttl', 600));
    }

    /**
     * Consume un token vivo de este dispositivo y propósito. Una sola vez:
     * la actualización condicional es atómica.
     */
    public function consume(string $token, string $kind, string $purpose, AuthorizedDevice $device): WorkforceKioskToken
    {
        $hash = Secrets::hash('kiosk|'.$token);
        $updated = WorkforceKioskToken::query()
            ->where('token_hash', $hash)
            ->where('kind', $kind)
            ->where('purpose', $purpose)
            ->where('authorized_device_id', $device->getKey())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]);

        if ($updated !== 1) {
            throw $kind === WorkforceKioskToken::KIND_ENROLLMENT
                ? WorkforceException::enrollmentInvalid()
                : WorkforceException::verificationInvalid();
        }

        return WorkforceKioskToken::query()->where('token_hash', $hash)->firstOrFail();
    }

    /** El token (consumido o no) de este dispositivo, para reconocer un reintento. */
    public function find(string $token, AuthorizedDevice $device): ?WorkforceKioskToken
    {
        return WorkforceKioskToken::query()
            ->where('token_hash', Secrets::hash('kiosk|'.$token))
            ->where('authorized_device_id', $device->getKey())
            ->first();
    }

    /** @return array{token:string, expires_at:Carbon} */
    private function issue(string $kind, string $prefix, Identity $identity, AuthorizedDevice $device, string $purpose, ?string $method, ?float $distance, ?int $codeId, int $ttl): array
    {
        $token = Secrets::token($prefix);
        $model = WorkforceKioskToken::create([
            'token_hash' => Secrets::hash('kiosk|'.$token),
            'kind' => $kind,
            'purpose' => $purpose,
            'identity_id' => $identity->getKey(),
            'authorized_device_id' => $device->getKey(),
            'method' => $method,
            'face_distance' => $distance !== null ? round($distance, 4) : null,
            'authorization_code_id' => $codeId,
            'expires_at' => now()->addSeconds($ttl),
            'created_at' => now(),
        ]);

        return ['token' => $token, 'expires_at' => $model->expires_at];
    }
}
