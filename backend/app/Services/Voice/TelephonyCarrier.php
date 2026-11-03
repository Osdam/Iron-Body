<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\CarrierCallsPage;
use App\Services\Voice\Dto\CarrierResult;
use DateTimeInterface;

/**
 * El operador telefónico de la subcuenta de voz (contrato H8, D8-3, D8-20 y
 * D8-21). NO INICIA LLAMADAS: con la integración nativa las crea el
 * proveedor de voz. Solo corta y lee.
 */
interface TelephonyCarrier
{
    /** Corte de emergencia: la llamada a `completed`. Idempotente. */
    public function hangup(string $callSid): CarrierResult;

    /**
     * Conciliación: las llamadas de la subcuenta desde `$since` (por fecha, en
     * UTC), UNA página y solo GET. `$pageToken` es el `nextPageToken` de la
     * página anterior (null = la primera).
     */
    public function listCalls(DateTimeInterface $since, ?string $pageToken): CarrierCallsPage;
}
