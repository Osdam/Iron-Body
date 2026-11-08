<?php

namespace App\Services\Telephony;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * ¿Está excluido el destino? ¿Falta la consulta al RNE? (contrato H8, D8-18,
 * [P-14]; fixture `policy-tables.json` §exclusions). Pura.
 *
 *  - Las exclusiones (cualquier fuente) y las señales del lead de origen
 *    (`do_not_contact` o `consent_status = denied`, leídas en vivo y en solo
 *    lectura) excluyen en TODA saliente, también a un canario (DF-P6).
 *  - El RNE solo se mira en el alcance `contacts`: la consulta más reciente
 *    vale si `checked_at ≥ now − vigencia` (el borde vale); `listed` excluye;
 *    sin consulta vigente, `rne_check_missing` (DF-P7). Una vigencia de 0 (el
 *    defecto mientras P-14 no se decida) no deja ninguna vigente: falla
 *    cerrado.
 */
final class VoiceExclusionRule
{
    public const RNE_LISTED = 'listed';

    public const RNE_NOT_LISTED = 'not_listed';

    public const LEAD_CONSENT_DENIED = 'denied';

    /**
     * @param  array{doNotContact: bool, consentStatus: ?string}|null  $lead
     * @param  array{checkedAt: DateTimeInterface|string, result: string}|null  $rne  la consulta más reciente
     * @return array{excluded: bool, rneCheckMissing: bool}
     */
    public static function evaluate(string $scope, bool $hasExclusion, ?array $lead, ?array $rne, DateTimeInterface $now, int $validityDays): array
    {
        $excluido = $hasExclusion
            || ($lead !== null && ($lead['doNotContact'] || $lead['consentStatus'] === self::LEAD_CONSENT_DENIED));

        $falta = false;
        if ($scope === OutboundPolicy::SCOPE_CONTACTS) {
            $vigente = $validityDays > 0 && $rne !== null
                && self::instant($rne['checkedAt']) >= DateTimeImmutable::createFromInterface($now)->modify('-'.$validityDays.' days');
            if (! $vigente) {
                $falta = true;
            } elseif ($rne['result'] === self::RNE_LISTED) {
                $excluido = true;
            }
        }

        return ['excluded' => $excluido, 'rneCheckMissing' => $falta];
    }

    private static function instant(DateTimeInterface|string $at): DateTimeImmutable
    {
        return $at instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($at) : new DateTimeImmutable($at);
    }
}
