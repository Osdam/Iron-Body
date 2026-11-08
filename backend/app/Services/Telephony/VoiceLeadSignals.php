<?php

namespace App\Services\Telephony;

use App\Models\MarketingLead;

/**
 * El ÚNICO adaptador de la telefonía hacia los leads del motor (contrato H8,
 * D8-18 y protección 5): las dos señales que excluyen una saliente,
 * `do_not_contact` y `consent_status = denied`, del lead de origen de un
 * contacto. SOLO LECTURA (una consulta, dos columnas): no escribe nada en el
 * motor ni registra.
 */
final class VoiceLeadSignals
{
    /** @return array{doNotContact: bool, consentStatus: ?string}|null null si el lead no existe */
    public static function forLead(int $leadId): ?array
    {
        $lead = MarketingLead::query()->whereKey($leadId)->first(['id', 'do_not_contact', 'consent_status']);
        if ($lead === null) {
            return null;
        }

        return [
            'doNotContact' => (bool) $lead->do_not_contact,
            'consentStatus' => is_string($lead->consent_status) ? $lead->consent_status : null,
        ];
    }
}
