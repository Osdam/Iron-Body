<?php

namespace App\Services\Telephony;

/**
 * La guarda del número de voz (contrato H8, D8-5; fixture
 * `outbound-gate-cases.json` §whatsappGuard): el número dedicado de la voz
 * (`TWILIO_VOICE_FROM`) nunca puede ser el número del gimnasio en la
 * aplicación de mensajería del motor (`WHATSAPP_DISPLAY_PHONE`) ni uno de los
 * protegidos (`WHATSAPP_PROTECTED_NUMBERS`). Si coincide, la política dice
 * el motivo por nombre y el doctor también.
 *
 * Se comparan solo los DÍGITOS, como la lista de `config/meta.php`; un número
 * nacional de 10 dígitos (3… o 60…) gana el 57 delante (DF-G10). Es el ÚNICO
 * fichero de la telefonía que lee esas dos claves de Meta (D8-5), en solo
 * lectura; no lee nada más del motor ni de Meta.
 */
final class VoiceNumberGuard
{
    /** Pura (DF-G10). Sin número de voz no hay con qué chocar: false. */
    public static function collides(?string $voiceFrom, ?string $displayPhone, array|string|null $protectedNumbers): bool
    {
        $voz = self::digits((string) $voiceFrom);
        if ($voz === '') {
            return false;
        }
        $lista = is_array($protectedNumbers) ? $protectedNumbers : explode(',', (string) $protectedNumbers);
        $lista[] = (string) $displayPhone;
        foreach ($lista as $numero) {
            if (is_string($numero) && self::digits($numero) !== '' && hash_equals(self::digits($numero), $voz)) {
                return true;
            }
        }

        return false;
    }

    /** Con la configuración real: `voice.twilio.from` frente a los dos de Meta. */
    public static function collidesWithConfig(): bool
    {
        $desde = config('voice.twilio.from');
        $visible = config('meta.whatsapp_display_phone');
        $protegidos = config('meta.protected_numbers');

        return self::collides(is_string($desde) ? $desde : null, is_string($visible) ? $visible : null, is_array($protegidos) || is_string($protegidos) ? $protegidos : null);
    }

    /** Los dígitos; un nacional de Colombia de 10 dígitos (3… o 60…) gana el 57. */
    public static function digits(string $raw): string
    {
        $digitos = (string) preg_replace('/\D+/', '', $raw);

        return preg_match('/^(3[0-9]{9}|60[0-9]{8})$/D', $digitos) === 1 ? '57'.$digitos : $digitos;
    }
}
