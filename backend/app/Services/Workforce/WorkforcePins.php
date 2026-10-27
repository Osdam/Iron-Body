<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\Identity;
use App\Models\WorkforcePin;
use App\Support\Workforce\Secrets;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * El PIN personal: segundo factor del rostro cuando el modelo no basta
 * (contrato §10). Solo vale junto a un rostro que coincide; esta clase no lo
 * sabe ni lo decide: guarda, comprueba y bloquea.
 *
 * Se guarda bcrypt(HMAC(APP_KEY, identidad|PIN)): un volcado de la base no
 * permite probar los 10⁶ PIN fuera de línea sin la APP_KEY. Los intentos viven
 * en la fila, no en la caché: reiniciar el servidor no regala intentos.
 *
 * Quien ataca un PIN es justo quien se parece al titular: su cara pasa. Por
 * eso los fallos se cuentan en una ventana que el acierto del titular NO
 * reinicia, y los bloqueos escalan (revisión de seguridad 2026-10-27, M-1).
 *
 * La traza la deja quien llama ({@see FaceReferenceService}); aquí no, para
 * no depender de él.
 */
class WorkforcePins
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const LOCKED = 'locked';

    public const NOT_SET = 'not_set';

    /**
     * PIN de 6 dígitos que aparecen en las listas de los más usados y que no
     * cubre ninguna de las reglas de {@see weak()}.
     */
    private const COMMON = [
        '100000', '200000', '102030', '010203', '000001', '123654', '123465', '987456', '654987',
        '131420', '201314', '521314', '520131', '112358', '314159', '271828', '142857',
    ];

    public function __construct(private readonly LookalikeRegistry $lookalikes) {}

    public function find(int $identityId): ?WorkforcePin
    {
        return WorkforcePin::query()->where('identity_id', $identityId)->first();
    }

    public function status(int $identityId): string
    {
        return $this->find($identityId)?->status() ?? WorkforcePin::STATUS_NOT_SET;
    }

    /**
     * Seis dígitos que no se adivinen: ni patrones (repetidos, series,
     * ABABAB, AABBCC, AAABBB, ABCABC, capicúas, recorridos del teclado), ni
     * fechas, ni los más comunes, ni el documento de la persona o de quien se
     * le parece (que es quien probaría).
     */
    public function assertStrong(Identity $identity, string $pin): void
    {
        if (preg_match('/^\d{6}$/', $pin) !== 1 || $this->weak($pin)) {
            throw WorkforceException::pinWeak();
        }
        $people = [(int) $identity->getKey()];
        foreach ($this->lookalikes->pairsOf((int) $identity->getKey()) as $pair) {
            $people[] = $pair->otherThan((int) $identity->getKey());
        }
        foreach (Identity::query()->whereIn('id', $people)->pluck('document_normalized') as $document) {
            $digits = (string) preg_replace('/\D+/', '', (string) $document);
            if (strlen($digits) >= 6 && (substr($digits, -6) === $pin || substr($digits, 0, 6) === $pin)) {
                throw WorkforceException::pinWeak();
            }
        }
    }

    /** Crea o reemplaza el PIN; pone a cero fallos, ventana y bloqueos. */
    public function set(Identity $identity, string $pin, ?int $deviceId, ?int $codeId): WorkforcePin
    {
        $model = WorkforcePin::query()->firstOrNew(['identity_id' => $identity->getKey()]);
        $model->fill([
            'pin_hash' => Hash::make($this->secret((int) $identity->getKey(), $pin)),
            'set_at' => now(),
            'set_device_id' => $deviceId,
            'set_authorization_code_id' => $codeId,
            'failed_attempts' => 0,
            'failures_window_started_at' => null,
            'locked_until' => null,
            'lock_level' => 0,
            'last_locked_at' => null,
            'revoked_at' => null,
            'revoked_by_admin_id' => null,
            'revoked_by_name' => null,
            'revoked_reason' => null,
        ]);
        $model->save();

        return $model;
    }

    /**
     * Comprueba el PIN. El fallo se CONFIRMA (la transacción termina bien) y
     * quien llama lanza el rechazo: lanzado dentro, el contador se desharía y
     * los intentos serían infinitos (mismo motivo que los códigos).
     *
     * @return array{result:string, locked_until:?Carbon, just_locked:bool}
     */
    public function check(int $identityId, string $pin): array
    {
        $max = (int) config('workforce.pin.max_attempts', 5);
        $windowHours = (int) config('workforce.pin.failure_window_hours', 24);
        $steps = array_values((array) config('workforce.pin.lockout_minutes', [15, 60, 240, 1440]));
        $decayDays = (int) config('workforce.pin.lock_decay_days', 7);

        return DB::transaction(function () use ($identityId, $pin, $max, $windowHours, $steps, $decayDays): array {
            /** @var WorkforcePin|null $model */
            $model = WorkforcePin::query()->where('identity_id', $identityId)->lockForUpdate()->first();
            $status = $model?->status() ?? WorkforcePin::STATUS_NOT_SET;

            if ($status === WorkforcePin::STATUS_NOT_SET || $status === WorkforcePin::STATUS_REVOKED) {
                return ['result' => self::NOT_SET, 'locked_until' => null, 'just_locked' => false];
            }
            if ($status === WorkforcePin::STATUS_LOCKED) {
                return ['result' => self::LOCKED, 'locked_until' => $model->locked_until, 'just_locked' => false];
            }

            // La ventana solo caduca con el tiempo; acertar no la toca.
            if ($model->failures_window_started_at === null || $model->failures_window_started_at->lte(now()->subHours($windowHours))) {
                $model->failed_attempts = 0;
                $model->failures_window_started_at = null;
            }

            if (Hash::check($this->secret($identityId, $pin), (string) $model->pin_hash)) {
                $model->save();

                return ['result' => self::OK, 'locked_until' => null, 'just_locked' => false];
            }

            $model->failed_attempts++;
            $model->failures_window_started_at ??= now();
            if ($model->failed_attempts < $max) {
                $model->save();

                return ['result' => self::INVALID, 'locked_until' => null, 'just_locked' => false];
            }

            $recent = $model->last_locked_at !== null && $model->last_locked_at->gt(now()->subDays($decayDays));
            $level = $recent ? min($model->lock_level + 1, count($steps) - 1) : 0;
            $model->forceFill([
                'failed_attempts' => 0,
                'failures_window_started_at' => null,
                'lock_level' => $level,
                'last_locked_at' => now(),
                'locked_until' => now()->addMinutes((int) $steps[$level]),
            ])->save();

            return ['result' => self::LOCKED, 'locked_until' => $model->locked_until, 'just_locked' => true];
        });
    }

    /** Anula el PIN: el hash se borra; la fila queda como traza. */
    public function revoke(int $identityId, string $reason, ?int $adminId, string $actorName): bool
    {
        $model = $this->find($identityId);
        if ($model === null || in_array($model->status(), [WorkforcePin::STATUS_NOT_SET, WorkforcePin::STATUS_REVOKED], true)) {
            return false;
        }
        $model->forceFill([
            'pin_hash' => null,
            'failed_attempts' => 0,
            'failures_window_started_at' => null,
            'locked_until' => null,
            'revoked_at' => now(),
            'revoked_by_admin_id' => $adminId,
            'revoked_by_name' => mb_substr($actorName, 0, 120),
            'revoked_reason' => mb_substr($reason, 0, 500),
        ])->save();

        return true;
    }

    private function weak(string $pin): bool
    {
        $d = array_map('intval', str_split($pin));
        $steps = [];
        for ($i = 1; $i < 6; $i++) {
            // Módulo 10: las series que dan la vuelta (890123, 135791) también.
            $steps[] = ($d[$i] - $d[$i - 1] + 10) % 10;
        }
        $step = array_unique($steps);

        return count(array_unique($d)) === 1                              // 111111
            || (count($step) === 1 && in_array((int) reset($step), [1, 2, 8, 9], true)) // 123456, 654321, 135791…
            || ($d[0] === $d[2] && $d[2] === $d[4] && $d[1] === $d[3] && $d[3] === $d[5]) // 121212
            || ($d[0] === $d[1] && $d[2] === $d[3] && $d[4] === $d[5])     // 112233
            || ($d[0] === $d[1] && $d[1] === $d[2] && $d[3] === $d[4] && $d[4] === $d[5]) // 111222
            || substr($pin, 0, 3) === substr($pin, 3, 3)                   // 123123
            || strrev($pin) === $pin                                        // 123321
            || in_array($pin, $this->keypadWalks(), true)                  // 147258, 159753
            || $this->looksLikeDate($pin)
            || in_array($pin, self::COMMON, true);
    }

    /**
     * Dos trazos rectos del teclado numérico (fila, columna o diagonal, en
     * cualquier sentido): 147258, 159753, 789456…
     *
     * @return list<string>
     */
    private function keypadWalks(): array
    {
        static $walks = null;
        if ($walks !== null) {
            return $walks;
        }
        $lines = ['123', '456', '789', '147', '258', '369', '159', '357', '0258', '2580'];
        $strokes = [];
        foreach ($lines as $line) {
            foreach ([$line, strrev($line)] as $l) {
                for ($i = 0; $i + 3 <= strlen($l); $i++) {
                    $strokes[] = substr($l, $i, 3);
                }
            }
        }
        $strokes = array_values(array_unique($strokes));
        $walks = [];
        foreach ($strokes as $a) {
            foreach ($strokes as $b) {
                $walks[] = $a.$b;
            }
        }

        return $walks = array_values(array_unique($walks));
    }

    /** DDMMAA, MMDDAA, AAMMDD, AAAAMM o MMAAAA con día y mes posibles. */
    private function looksLikeDate(string $pin): bool
    {
        $two = fn (int $at) => (int) substr($pin, $at, 2);
        $day = fn (int $v) => $v >= 1 && $v <= 31;
        $month = fn (int $v) => $v >= 1 && $v <= 12;
        $year4 = fn (int $v) => $v >= 1940 && $v <= 2039;

        return ($day($two(0)) && $month($two(2)))                 // DDMM AA
            || ($month($two(0)) && $day($two(2)))                 // MMDD AA
            || ($month($two(2)) && $day($two(4)))                 // AA MMDD
            || ($year4((int) substr($pin, 0, 4)) && $month($two(4))) // AAAA MM
            || ($month($two(0)) && $year4((int) substr($pin, 2, 4))); // MM AAAA
    }

    private function secret(int $identityId, string $pin): string
    {
        return Secrets::hash('pin|'.$identityId.'|'.$pin);
    }
}
