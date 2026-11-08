<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceCallEvent;
use App\Services\Twilio\TwilioRequestSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * El aviso de gasto de la SUBCUENTA de voz (contrato H8, D8-16, D8-19 [P-17];
 * fixture `twilio-webhook-bodies.json` §usageTrigger), ya verificado con
 * `TwilioRequestSignature` y el token de la subcuenta (el del OTP no vale).
 *
 * A diferencia del OTP, SE PERSISTE y ABRE EL FRENO (cualquier aviso de la
 * subcuenta, diario o mensual). Idempotente por `IdempotencyToken`: el mismo
 * aviso otra vez no deja otro evento ni otra apertura; sin token se persiste y
 * se abre igual, sin deduplicar (DF-W3: abrir es idempotente).
 *
 * El evento va como `cost_recorded` (proveedor `twilio`): el tipo
 * `usage_alert` del fixture no está en el conjunto cerrado de D8-13 ni en la
 * base de DB8 (DF-W1, Q-5: decisión pendiente del lead). Solo códigos y cifras.
 */
final class TwilioUsageAlert
{
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_.:-]{1,100}$/D';

    /** @return array<string, mixed> */
    public function ingest(string $rawBody): array
    {
        $campos = [];
        foreach (TwilioRequestSignature::formPairs($rawBody) as [$clave, $valor]) {
            $campos[$clave] ??= $valor;
        }
        $token = isset($campos['IdempotencyToken']) && preg_match(self::TOKEN_PATTERN, $campos['IdempotencyToken']) === 1 ? $campos['IdempotencyToken'] : null;
        $codigos = [
            'source' => 'usage_trigger',
            'usageTriggerSid' => self::code($campos['UsageTriggerSid'] ?? null, '/^UT[0-9a-f]{32}$/D'),
            'recurring' => self::code($campos['Recurring'] ?? null, '/^[a-z]{1,16}$/D'),
            'usageCategory' => self::code($campos['UsageCategory'] ?? null, '/^[a-z0-9-]{1,48}$/D'),
            'triggerBy' => self::code($campos['TriggerBy'] ?? null, '/^[a-z]{1,16}$/D'),
            'triggerValue' => self::code($campos['TriggerValue'] ?? null, '/^[0-9]{1,9}(\.[0-9]{1,6})?$/D'),
            'currentValue' => self::code($campos['CurrentValue'] ?? null, '/^[0-9]{1,9}(\.[0-9]{1,6})?$/D'),
        ];

        return DB::transaction(function () use ($token, $codigos): array {
            $evento = VoiceEvents::record('cost_recorded', null, VoiceCallEvent::PROVIDER_TWILIO, $token, ['outcome' => 'usage_alert', 'codes' => $codigos]);
            if ($evento === null) {
                return ['ok' => true, 'duplicate' => true];
            }
            VoiceBrake::open('subaccount_alert', 'usage:'.($token ?? (string) Str::uuid()), $codigos, null, (int) $evento->id);
            Log::channel('voice')->warning('voice.twilio.usage_alert', ['recurring' => $codigos['recurring'], 'category' => $codigos['usageCategory'], 'has_token' => $token !== null]);

            return ['ok' => true, 'persisted' => true];
        });
    }

    private static function code(?string $valor, string $patron): ?string
    {
        return is_string($valor) && preg_match($patron, $valor) === 1 ? $valor : null;
    }
}
