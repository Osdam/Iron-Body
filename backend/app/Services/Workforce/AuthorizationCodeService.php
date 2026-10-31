<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\Identity;
use App\Models\WorkforceAuthorizationCode;
use App\Support\Workforce\Secrets;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

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
    /** Qué autoriza cada código, dicho como lo entiende quien está en la tablet. */
    private const OPERATION = [
        WorkforceAuthorizationCode::PURPOSE_ENROLLMENT => 'registrar el rostro',
        WorkforceAuthorizationCode::PURPOSE_FALLBACK_WORKDAY => 'registrar la jornada',
        WorkforceAuthorizationCode::PURPOSE_FALLBACK_CLASS => 'iniciar o finalizar clases',
        WorkforceAuthorizationCode::PURPOSE_PIN_SETUP => 'crear el PIN',
    ];

    /** Dónde se escribe cada código en la tablet. */
    private const WHERE = [
        WorkforceAuthorizationCode::PURPOSE_ENROLLMENT => 'se escribe en «Acceso para empleados» → identifícate → «Registrar mi rostro»',
        WorkforceAuthorizationCode::PURPOSE_FALLBACK_WORKDAY => 'se escribe en «Acceso para empleados» → «Registrar entrada y salida» → «Usar código de administrador»',
        WorkforceAuthorizationCode::PURPOSE_FALLBACK_CLASS => 'se escribe al iniciar o finalizar la clase, en «Usar código de administrador»',
        WorkforceAuthorizationCode::PURPOSE_PIN_SETUP => 'se escribe en «Acceso para empleados» → identifícate → «Crear mi PIN»',
    ];

    /** Cómo se pide en el CRM el código que hace falta. */
    private const ASK = [
        WorkforceAuthorizationCode::PURPOSE_ENROLLMENT => 'Pídelo en el CRM: Personal → la persona → «Abrir biometría» → «Autorizar enrolamiento».',
        WorkforceAuthorizationCode::PURPOSE_FALLBACK_WORKDAY => 'Pídelo en el CRM: Personal → la persona → «Código de respaldo» → «Registrar la jornada».',
        WorkforceAuthorizationCode::PURPOSE_FALLBACK_CLASS => 'Pídelo en el CRM: Personal → la persona → «Código de respaldo» → «Iniciar o finalizar una clase».',
        WorkforceAuthorizationCode::PURPOSE_PIN_SETUP => 'Pídelo en el CRM: Personal → la persona → «Abrir biometría» → «Autorizar PIN».',
    ];

    /**
     * Cuántos rechazos iguales (persona, operación y motivo) se anotan por
     * ventana. Como los fallos de rostro: sin tope, repetir un código falso
     * llenaba el historial del CRM (enseña los 50 últimos) y tapaba lo importante.
     */
    private const AUDIT_MAX = 5;

    private const AUDIT_WINDOW_SECONDS = 900;

    public function __construct(private readonly FaceReferenceService $faces) {}

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
     * Consume el código. Un número equivocado frente al código vivo cuenta como
     * intento y el código muere al agotarlos.
     *
     * SI NO HAY CÓDIGO VIVO DE ESA OPERACIÓN, se dice por qué —ya se usó,
     * caducó, o no hay ninguno— y, si la persona tiene un código vigente de
     * OTRA operación, para qué sirve y dónde se escribe. Antes la respuesta era
     * la misma para todo, y el 16-10-2026 seis códigos válidos se escribieron
     * en el sitio equivocado sin que nadie pudiera saber por qué no entraban.
     * No revela nada que sirva para adivinar: el número escrito no se compara
     * con los códigos de otras operaciones, y sin código vivo nada puede pasar.
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
            [$outcome, $exception] = $this->rejection($identity, $purpose, $live, $max);
            $this->recordRejection($identity, $purpose, $outcome, $deviceId);

            throw $exception;
        }

        return $live;
    }

    /**
     * Hay un código vivo de esa operación, sin consumirlo ni contar intento:
     * para no comparar un rostro (ni gastar sus intentos) cuando ningún código
     * podría pasar después. Sin código vivo, el mismo rechazo que `consume()`.
     */
    public function assertLive(Identity $identity, string $purpose): void
    {
        $max = (int) config('workforce.codes.max_attempts', 5);
        /** @var WorkforceAuthorizationCode|null $live */
        $live = WorkforceAuthorizationCode::query()
            ->where('identity_id', $identity->getKey())
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();
        if ($live === null || $live->attempts >= $max) {
            // Con el código vivo pero sin intentos, el motivo es ese, no «no hay código».
            [$outcome, $exception] = $this->rejection($identity, $purpose, $live, $max);
            $this->recordRejection($identity, $purpose, $outcome, null);

            throw $exception;
        }
    }

    /** @return array{0: string, 1: WorkforceException} */
    private function rejection(Identity $identity, string $purpose, ?WorkforceAuthorizationCode $live, int $max): array
    {
        if ($live !== null) {
            return $live->attempts >= $max
                ? ['attempts_exceeded', WorkforceException::authorizationCodeAttemptsExceeded()]
                : ['mismatch', WorkforceException::authorizationCodeInvalid()];
        }

        $operation = self::OPERATION[$purpose] ?? 'esta operación';
        $ask = self::ASK[$purpose] ?? 'Pide uno nuevo a un administrador.';
        $hints = $this->otherLiveHints($identity, $purpose);

        /** @var WorkforceAuthorizationCode|null $last */
        $last = WorkforceAuthorizationCode::query()
            ->where('identity_id', $identity->getKey())
            ->where('purpose', $purpose)
            ->orderByDesc('id')
            ->first();

        if ($last !== null && $last->consumed_at !== null) {
            return ['used', WorkforceException::authorizationCodeUsed(
                trim("El código para {$operation} ya se usó: cada código sirve una sola vez. {$ask} {$hints}")
            )];
        }

        if ($last !== null && $last->revoked_at === null && ! $last->expires_at->isFuture()) {
            $ttl = (int) config('workforce.codes.ttl_minutes', 30);

            return ['expired', WorkforceException::authorizationCodeExpired(
                trim("El código para {$operation} caducó (dura {$ttl} minutos). {$ask} {$hints}")
            )];
        }

        return ['not_issued', WorkforceException::authorizationCodeNotIssued(
            trim("No hay un código vigente para {$operation}. {$ask} {$hints}")
        )];
    }

    /** «Hay un código vigente para registrar el rostro: se escribe en …» por cada operación con código vivo. */
    private function otherLiveHints(Identity $identity, string $purpose): string
    {
        return $this->pending($identity)
            ->pluck('purpose')
            ->unique()
            ->reject(fn (string $p) => $p === $purpose)
            ->map(fn (string $p) => 'Hay un código vigente para '.(self::OPERATION[$p] ?? $p).': '.(self::WHERE[$p] ?? 'úsalo donde corresponde').'.')
            ->implode(' ');
    }

    /**
     * Deja el rechazo en el historial de biometría que ve el CRM. Sin el código
     * ni su huella: qué operación se intentó, por qué no pasó y qué otros
     * códigos tenía vivos la persona.
     */
    private function recordRejection(Identity $identity, string $purpose, string $outcome, ?int $deviceId): void
    {
        $others = $this->pending($identity)->pluck('purpose')->unique()->reject(fn (string $p) => $p === $purpose)->values()->all();
        $operation = self::OPERATION[$purpose] ?? $purpose;
        $why = match ($outcome) {
            'mismatch' => 'número equivocado',
            'attempts_exceeded' => 'demasiados intentos',
            'used' => 'el código ya se había usado',
            'expired' => 'el código había caducado',
            default => 'no había un código vigente para esa operación',
        };
        $extra = $others === [] ? '' : ' (tenía uno vigente para '.implode(' y ', array_map(fn ($p) => self::OPERATION[$p] ?? $p, $others)).')';

        // La respuesta a la tablet no depende de esto: solo deja de anotarse.
        $key = 'workforce-code-rejected:'.$identity->getKey().'|'.$purpose.'|'.$outcome;
        if (RateLimiter::tooManyAttempts($key, self::AUDIT_MAX)) {
            return;
        }
        RateLimiter::hit($key, self::AUDIT_WINDOW_SECONDS);

        try {
            $this->faces->audit(FaceReferenceService::EVENT_CODE_REJECTED, null, [
                'identity_id' => $identity->getKey(),
                'purpose' => $purpose,
                'outcome' => $outcome,
                'other_live_purposes' => $others,
                'device_id' => $deviceId,
                'reason' => "Para {$operation}: {$why}{$extra}",
            ]);
        } catch (\Throwable $e) {
            // La respuesta a la tablet no cambia por esto, pero no se esconde.
            report($e);
        }
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
