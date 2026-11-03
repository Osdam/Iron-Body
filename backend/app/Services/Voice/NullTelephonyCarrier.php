<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\CarrierCallsPage;
use App\Services\Voice\Dto\CarrierResult;
use DateTimeInterface;

/** El operador por defecto (contrato H8, D8-3): sin credencial no corta ni lista. `not_configured`, sin red. */
final class NullTelephonyCarrier implements TelephonyCarrier
{
    public function hangup(string $callSid): CarrierResult
    {
        return new CarrierResult(CarrierResult::OUTCOME_NOT_CONFIGURED, null, null, null, null);
    }

    public function listCalls(DateTimeInterface $since, ?string $pageToken): CarrierCallsPage
    {
        return new CarrierCallsPage(CarrierCallsPage::OUTCOME_NOT_CONFIGURED, [], null, false, null);
    }
}
