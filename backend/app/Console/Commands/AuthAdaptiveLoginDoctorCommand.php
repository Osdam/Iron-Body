<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberDeviceBinding;
use App\Services\AccountRiskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnóstico del LOGIN ADAPTATIVO (Bloque 8). Read-only: NO modifica nada y
 * NO imprime tokens, OTP ni el device_id completo. Sirve para verificar en
 * producción por qué un dispositivo confiable cae a OTP en vez de desbloqueo
 * local (Face ID/huella).
 *
 * Uso:
 *   php artisan auth:adaptive-login-doctor --document=1010101010 --device-id=dev_abc...
 *
 * Comprueba y reporta exactamente:
 *   adaptive_login   (flag de config en runtime; ojo con config:cache en VPS)
 *   migration        (columna last_otp_reauth_at presente)
 *   device_binding_found / trusted_device / binding_member_match
 *   risk_score / risk_score_for_tier (sin new_device) / reauth_due
 *   login_tier resultante (local | otp | otp_face) y si pediría SMS.
 */
class AuthAdaptiveLoginDoctorCommand extends Command
{
    protected $signature = 'auth:adaptive-login-doctor
        {--document=  : Documento del miembro a inspeccionar}
        {--device-id= : device_id (fingerprint) del equipo a inspeccionar}
        {--prefer-otp : Simula la app pidiendo OTP (fallback de biometría)}';

    protected $description = 'Diagnóstico read-only del login adaptativo/OTP en un dispositivo (no toca datos, no imprime secretos).';

    public function handle(AccountRiskService $risk): int
    {
        $document = trim((string) $this->option('document'));
        $deviceId = trim((string) $this->option('device-id'));
        $preferOtp = (bool) $this->option('prefer-otp');

        // ── 1) Estado de config y migración (causas típicas en VPS) ─────────────
        $adaptive = (bool) config('security.adaptive_login', false);
        $reauthDays = (int) config('security.trusted_reauth_days', 30);
        $localThreshold = (int) config('security.local_threshold', 1);
        $hasColumn = Schema::hasColumn('member_device_bindings', 'last_otp_reauth_at');

        $this->line('── Config (runtime) ──');
        $this->row('adaptive_login', $this->boolStr($adaptive).($adaptive ? '' : '  ⚠ APAGADO → siempre OTP+cara'));
        $this->row('trusted_reauth_days', (string) $reauthDays);
        $this->row('local_threshold', (string) $localThreshold);
        $this->row('migration last_otp_reauth_at', $hasColumn
            ? 'presente ✔'
            : 'AUSENTE ⚠  → corre: php artisan migrate --force');

        if (! $adaptive) {
            $this->warn("\nadaptive_login=false en runtime. Si .env trae SECURITY_ADAPTIVE_LOGIN=true,");
            $this->warn('es config:cache viejo en el VPS. Corre:  php artisan config:clear && php artisan config:cache');
        }
        if (! $hasColumn) {
            $this->warn("\nSin la columna last_otp_reauth_at, todo dispositivo confiable se trata como");
            $this->warn('"revalidación vencida" → OTP en CADA login. Aplica la migración en el VPS.');
        }

        if ($document === '' || $deviceId === '') {
            $this->line("\nSugerencia: pasa --document y --device-id para evaluar un equipo concreto.");
            return self::SUCCESS;
        }

        // ── 2) Miembro + binding del dispositivo ────────────────────────────────
        $member = Member::query()->where('document_number', $document)->first();
        if (! $member) {
            $this->error("\nNo existe un miembro con ese documento.");
            return self::FAILURE;
        }

        $binding = $hasColumn ? MemberDeviceBinding::forDevice($deviceId) : null;
        $bindingFound = $binding !== null;
        $match = $bindingFound && (int) $binding->member_id === (int) $member->id;
        $trusted = $match;
        $reauthDue = $trusted && $binding !== null && $binding->needsOtpReauth();
        $score = $risk->score($member);
        $scoreForTier = $risk->score($member, [\App\Models\MemberSecurityEvent::TYPE_NEW_DEVICE]);

        $tier = $adaptive
            ? $risk->loginTier($member, $trusted, $preferOtp, $reauthDue)
            : MemberAuthChallenge::TIER_OTP_FACE;

        $this->line("\n── Dispositivo / miembro ──");
        $this->row('member_id', (string) $member->id);
        $this->row('device_id', $this->mask($deviceId));
        $this->row('device_binding_found', $this->boolStr($bindingFound));
        $this->row('binding_member_match', $this->boolStr($match));
        $this->row('trusted_device', $this->boolStr($trusted));
        $this->row('last_otp_reauth_at', $binding?->last_otp_reauth_at?->toIso8601String() ?? '(null)');
        $this->row('reauth_due', $this->boolStr($reauthDue).($reauthDue ? '  → pide OTP una vez' : ''));
        $this->row('risk_score (total)', (string) $score);
        $this->row('risk_score (para tier, sin new_device)', (string) $scoreForTier);
        $this->row('prefer_otp', $this->boolStr($preferOtp));

        $this->line("\n── Resultado ──");
        $emoji = $tier === MemberAuthChallenge::TIER_LOCAL ? '✔ Face ID/huella local (sin SMS)'
            : ($tier === MemberAuthChallenge::TIER_OTP ? 'OTP por SMS (sin cara)' : 'OTP por SMS + cara');
        $this->row('login_tier', $tier.'   '.$emoji);

        if ($tier === MemberAuthChallenge::TIER_LOCAL) {
            $this->info("\n✔ Este equipo desbloqueará con biometría local SIN gastar SMS.");
        } else {
            $this->warn("\nEste equipo pediría OTP. Causa probable:");
            if (! $trusted) {
                $this->line('  • No es confiable (sin binding a este miembro): el PRIMER login exige OTP. Normal.');
            } elseif ($reauthDue) {
                $this->line('  • Revalidación vencida/null. Tras verificar el OTP, last_otp_reauth_at=now() y');
                $this->line('    los próximos logins (dentro de '.$reauthDays.' días) usarán biometría local.');
            } elseif ($scoreForTier >= $localThreshold) {
                $this->line('  • Riesgo adversarial reciente (≥ local_threshold). Tras enfriarse, vuelve a local.');
            } elseif ($preferOtp) {
                $this->line('  • La app envió prefer_otp=true (fallback de biometría).');
            }
        }

        return self::SUCCESS;
    }

    private function row(string $label, string $value): void
    {
        $this->line(sprintf('  %-42s %s', $label, $value));
    }

    private function boolStr(bool $v): string
    {
        return $v ? 'true' : 'false';
    }

    private function mask(string $v): string
    {
        return strlen($v) <= 8 ? str_repeat('•', strlen($v)) : substr($v, 0, 6).'…'.substr($v, -2);
    }
}
