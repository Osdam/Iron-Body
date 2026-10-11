<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\Identity;
use App\Models\WorkforceAuthorizationCode;
use App\Support\Workforce\Secrets;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que concede un administrador desde el CRM a una persona concreta:
 * registrar su rostro, o registrar UNA operación sin rostro si la cámara falla.
 *
 * Es la autorización administrativa que exige el enrolamiento: el documento
 * identifica, pero no prueba nada (cualquiera puede saber una cédula). El
 * administrador tiene a la persona delante y responde de haberlo autorizado.
 *
 * Un código vivo por persona y propósito: emitir otro anula el anterior. Un
 * solo uso, caduca y admite pocos intentos.
 */
class AuthorizationCodeService
{
    /** @return array{code:string, model:WorkforceAuthorizationCode} */
    public function issue(Identity $identity, string $purpose, ?string $reason, ?int $adminId, ?string $adminName): array
    {
        return DB::transaction(function () use ($identity, $purpose, $reason, $adminId, $adminName): array {
            WorkforceAuthorizationCode::query()
                ->where('identity_id', $identity->getKey())
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $code = Secrets::numericCode();
            $model = WorkforceAuthorizationCode::create([
                'identity_id' => $identity->getKey(),
                'purpose' => $purpose,
                'code_hash' => Secrets::hash($purpose.'|'.$identity->getKey().'|'.$code),
                'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
                'created_by_admin_id' => $adminId,
                'created_by_name' => $adminName !== null ? mb_substr($adminName, 0, 120) : null,
                'expires_at' => now()->addMinutes((int) config('workforce.codes.ttl_minutes', 30)),
            ]);

            return ['code' => $code, 'model' => $model];
        });
    }

    /**
     * Consume el código. Respuesta uniforme si no existe, caducó o no coincide;
     * un fallo cuenta como intento y el código muere al agotarlos.
     */
    public function consume(Identity $identity, string $purpose, string $code, ?int $deviceId): WorkforceAuthorizationCode
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';

        $max = (int) config('workforce.codes.max_attempts', 5);

        // El intento fallido se CONFIRMA (la transacción termina bien) y el
        // rechazo se lanza fuera: si se lanzara dentro, el contador de intentos
        // se desharía con ella y los intentos serían infinitos.
        [$live, $ok] = DB::transaction(function () use ($identity, $purpose, $code, $deviceId, $max): array {
            /** @var WorkforceAuthorizationCode|null $live */
            $live = WorkforceAuthorizationCode::query()
                ->where('identity_id', $identity->getKey())
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($live === null || $live->attempts >= $max) {
                return [$live, false];
            }

            $expected = Secrets::hash($purpose.'|'.$identity->getKey().'|'.$code);
            if ($code === '' || ! hash_equals($live->code_hash, $expected)) {
                $live->increment('attempts');

                return [$live, false];
            }

            $live->forceFill(['consumed_at' => now(), 'consumed_device_id' => $deviceId])->save();

            return [$live, true];
        });

        if (! $ok) {
            throw $live !== null && $live->attempts >= $max
                ? WorkforceException::authorizationCodeAttemptsExceeded()
                : WorkforceException::authorizationCodeInvalid();
        }

        return $live;
    }

    /** @return Collection<int, WorkforceAuthorizationCode> */
    public function pending(Identity $identity)
    {
        return WorkforceAuthorizationCode::query()
            ->where('identity_id', $identity->getKey())
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->get();
    }
}
