<?php

namespace App\Services\Otp;

use App\Exceptions\OtpException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Puerta única por la que pasa toda llamada que le cuesta dinero a Ironbody.
 *
 * Antes de esto, cada pulsación de "Entrar" que resolviera a un socio válido se
 * convertía en un SMS: sin enfriamiento, sin reuso del código vivo, sin candado
 * y sin techo. La auditoría del 12/09/2026 midió el resultado — 562 SMS para 403
 * logins buenos, 70 de ellos lanzados sobre una verificación que seguía viva.
 *
 * Aquí no se genera ningún código ni se guarda ninguno: la verificación sigue
 * siendo íntegramente de Twilio Verify. Esta clase sólo decide SI procede
 * llamarle, y deja constancia de por qué.
 *
 * Llaves, por orden de fuerza:
 *   teléfono  → la fuerte, porque es lo que Twilio cobra
 *   cuenta    → red de apoyo para quien cambia de número
 *   IP        → SEÑAL SECUNDARIA y holgada a propósito: una sola IP (la recepción
 *               del gimnasio) da servicio a 24 socios legítimos, y castigarla
 *               dejaría fuera a gente que no ha hecho nada.
 */
class OtpPolicy
{
    public const SCOPE_MEMBER = 'member';

    public const SCOPE_TRAINER = 'trainer';

    /** Sal fija del hash: agrupa sin permitir reconstruir el número. */
    private const SAL = 'ironbody-otp-policy';

    public function __construct(private OtpCostGuard $cost) {}

    // ── Decisión ─────────────────────────────────────────────────────────────

    /**
     * ¿Procede llamar al proveedor? Lanza {@see OtpException} cuando no, con el
     * código HTTP y el `retry_after` que la app ya sabe interpretar.
     *
     * No incrementa nada: los contadores sólo suben cuando la llamada ocurre de
     * verdad (ver {@see registerProviderCall}), para que reusar un código vivo
     * no consuma cupo de nadie.
     *
     * @throws OtpException 503 kill switch / techo duro · 429 límite alcanzado
     */
    public function assertStartAllowed(
        string $scope,
        int|string|null $subjectId,
        string $purpose,
        ?string $phone,
        ?string $ip = null,
        ?int $secondsSinceLastUnusedSend = null,
    ): void {
        $this->cost->assertCanSend();

        $enfriamiento = (int) config('otp.policy.start_cooldown', 60);
        if ($enfriamiento > 0
            && $secondsSinceLastUnusedSend !== null
            && $secondsSinceLastUnusedSend < $enfriamiento) {
            $this->rechazar($scope, $subjectId, $purpose, $phone, $ip, 'cooldown',
                $enfriamiento - $secondsSinceLastUnusedSend);
        }

        foreach ($this->limites($scope, $subjectId, $phone, $ip) as $limite) {
            [$etiqueta, $clave, $ventana, $tope] = $limite;
            if ($tope > 0 && $this->contador($clave, $ventana) >= $tope) {
                $this->rechazar($scope, $subjectId, $purpose, $phone, $ip,
                    'rate_limited:'.$etiqueta, $this->faltaPara($ventana));
            }
        }
    }

    /**
     * Deja constancia de una llamada que SÍ viajó al proveedor: sube los
     * contadores de cupo y el gasto estimado del día.
     */
    public function registerProviderCall(
        string $scope,
        int|string|null $subjectId,
        ?string $phone,
        ?string $ip = null,
    ): void {
        foreach ($this->limites($scope, $subjectId, $phone, $ip) as [, $clave, $ventana]) {
            $this->incrementar($clave, $ventana);
        }

        $this->cost->recordSmsStart();
    }

    /** Una verificación que se completó: es lo único que Twilio cobra como tal. */
    public function registerVerification(): void
    {
        $this->cost->recordVerification();
    }

    // ── Reuso de verificación viva ───────────────────────────────────────────

    /**
     * UUID del reto cuyo SMS salió de verdad y sigue vigente, si lo hay.
     *
     * La marca sólo se pone cuando el proveedor ACEPTÓ el envío: así un reto
     * cuyo SMS nunca llegó a salir no se reutiliza nunca, que sería la trampa
     * obvia (devolver un código que no existe en ningún teléfono).
     */
    public function liveChallengeUuid(string $scope, int|string|null $subjectId, string $purpose): ?string
    {
        $v = Cache::get($this->claveViva($scope, $subjectId, $purpose));

        return is_string($v) && $v !== '' ? $v : null;
    }

    public function markLive(string $scope, int|string|null $subjectId, string $purpose, string $uuid, int $ttl): void
    {
        if ($ttl <= 0) {
            return;
        }
        Cache::put($this->claveViva($scope, $subjectId, $purpose), $uuid, $ttl);
    }

    public function forgetLive(string $scope, int|string|null $subjectId, string $purpose): void
    {
        Cache::forget($this->claveViva($scope, $subjectId, $purpose));
    }

    // ── Concurrencia ─────────────────────────────────────────────────────────

    /**
     * Candado atómico del inicio de verificación, por teléfono y propósito.
     *
     * Diez peticiones simultáneas del mismo número: una entra, llama a Twilio y
     * deja la marca de verificación viva; las otras nueve esperan a que suelte y
     * se encuentran el código ya enviado. Una comprobación del tipo
     * "¿existe? entonces…" sin candado no sirve: las diez pasarían a la vez.
     */
    public function lock(string $purpose, ?string $phone): Lock
    {
        return Cache::lock(
            'otp:start:'.$purpose.':'.$this->phoneHash($phone),
            max((int) config('otp.policy.lock_seconds', 20), 1),
        );
    }

    public function lockWaitSeconds(): int
    {
        return max((int) config('otp.policy.lock_wait', 8), 0);
    }

    // ── Observabilidad ───────────────────────────────────────────────────────

    /**
     * Evento del ciclo OTP. Nunca lleva el código, ni credenciales, ni el SID
     * completo del proveedor, ni el teléfono en claro.
     */
    public function event(string $event, array $campos = []): void
    {
        Log::info('otp.event', array_merge([
            'event' => $event,
            'endpoint' => $this->endpoint(),
            'provider' => 'twilio_verify',
            'at' => now()->toIso8601String(),
        ], array_filter($campos, static fn ($v) => $v !== null)));
    }

    public function phoneHash(?string $phone): string
    {
        $normalizado = preg_replace('/[^\d]/', '', (string) $phone) ?? '';

        return $normalizado === ''
            ? 'sin-telefono'
            : substr(hash('sha256', self::SAL.$normalizado), 0, 16);
    }

    public function ipHash(?string $ip): ?string
    {
        return $ip === null || $ip === '' ? null : substr(hash('sha256', self::SAL.$ip), 0, 16);
    }

    public function costSnapshot(): array
    {
        return $this->cost->snapshot();
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /**
     * Los cinco cupos vigentes: teléfono y cuenta en ventana corta y diaria, más
     * la IP como red de seguridad muy holgada.
     *
     * @return list<array{0:string,1:string,2:int,3:int}> [etiqueta, clave, ventana, tope]
     */
    private function limites(string $scope, int|string|null $subjectId, ?string $phone, ?string $ip): array
    {
        $tel = $this->phoneHash($phone);
        $cuenta = $scope.':'.($subjectId ?? 'anon');
        $ipH = $this->ipHash($ip);

        $cfg = static fn (string $k, string $sub, int $def) => (int) config("otp.policy.{$k}.{$sub}", $def);

        $out = [
            ['phone_window', "otp:rl:phone:{$tel}", $cfg('phone', 'window', 900), $cfg('phone', 'window_limit', 3)],
            ['phone_daily', "otp:rl:phone-d:{$tel}", 86400, $cfg('phone', 'daily_limit', 8)],
            ['account_window', "otp:rl:acct:{$cuenta}", $cfg('account', 'window', 900), $cfg('account', 'window_limit', 4)],
            ['account_daily', "otp:rl:acct-d:{$cuenta}", 86400, $cfg('account', 'daily_limit', 10)],
        ];

        if ($ipH !== null) {
            $out[] = ['ip_window', "otp:rl:ip:{$ipH}", $cfg('ip', 'window', 900), $cfg('ip', 'window_limit', 40)];
            $out[] = ['ip_daily', "otp:rl:ip-d:{$ipH}", 86400, $cfg('ip', 'daily_limit', 200)];
        }

        return $out;
    }

    /** Ventana fija: la clave lleva el hueco temporal, así caduca sola. */
    private function claveVentana(string $clave, int $ventana): string
    {
        return $clave.':'.intdiv(time(), max($ventana, 1));
    }

    private function contador(string $clave, int $ventana): int
    {
        return (int) (Cache::get($this->claveVentana($clave, $ventana)) ?? 0);
    }

    private function incrementar(string $clave, int $ventana): void
    {
        $k = $this->claveVentana($clave, $ventana);
        Cache::add($k, 0, $ventana + 60);
        Cache::increment($k);
    }

    private function faltaPara(int $ventana): int
    {
        $v = max($ventana, 1);

        return $v - (time() % $v);
    }

    private function claveViva(string $scope, int|string|null $subjectId, string $purpose): string
    {
        return 'otp:live:'.$scope.':'.($subjectId ?? 'anon').':'.$purpose;
    }

    private function endpoint(): ?string
    {
        try {
            $r = request();

            return $r ? $r->method().' '.$r->path() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @throws OtpException
     */
    private function rechazar(
        string $scope,
        int|string|null $subjectId,
        string $purpose,
        ?string $phone,
        ?string $ip,
        string $motivo,
        int $retryAfter,
    ): void {
        $this->event('otp.rate_limited', [
            'scope' => $scope,
            'subject_id' => $subjectId,
            'purpose' => $purpose,
            'phone_hash' => $this->phoneHash($phone),
            'ip_hash' => $this->ipHash($ip),
            'decision' => $motivo,
            'retry_after' => $retryAfter,
        ]);

        throw new OtpException(
            'Has solicitado demasiados códigos. Espera unos minutos e inténtalo de nuevo.',
            429,
            ['code' => 'rate_limited', 'retry_after' => $retryAfter],
        );
    }
}
