<?php

namespace App\Services\Outreach\Facts;

use App\Support\Privacy\PhoneFingerprint;

/**
 * El teléfono como lo compara la puerta de WhatsApp (contrato H9, D9-7 N1
 * `do_not_contact_sibling`, «la misma regla que `MarketingMessageDispatcher`»).
 * PURA. Es el número que se le pasa a Meta: solo dígitos; un celular
 * colombiano de 10 dígitos que empieza por 3 recibe el 57 delante; fuera de
 * 11–15 dígitos no hay número. `PhoneKeyParityTest` la ata a
 * `MarketingMessageDispatcher::normalizePhone()` (este espacio no puede
 * construir el despachador: arrastra el outbox, D9-2).
 *
 * Los valores que salen de aquí NUNCA salen del adaptador: solo se COMPARAN
 * (hermanos) o se convierten en la huella del H6 (canarios).
 */
final class PhoneKey
{
    public static function normalize(#[\SensitiveParameter] ?string $phone): ?string
    {
        $digitos = preg_replace('/[^0-9]/', '', (string) $phone) ?? '';
        if ($digitos === '') {
            return null;
        }
        if (strlen($digitos) === 10 && str_starts_with($digitos, '3')) {
            $digitos = '57'.$digitos;
        }
        if (strlen($digitos) < 11 || strlen($digitos) > 15) {
            return null;
        }

        return $digitos;
    }

    /** El E.164 del número normalizado, o null si no lo es (lo que pide `PhoneFingerprint`). */
    public static function e164(#[\SensitiveParameter] ?string $phone): ?string
    {
        $normal = self::normalize($phone);
        if ($normal === null) {
            return null;
        }
        $e164 = '+'.$normal;

        return preg_match(PhoneFingerprint::E164_PATTERN, $e164) === 1 ? $e164 : null;
    }
}
