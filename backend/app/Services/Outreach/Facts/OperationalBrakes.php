<?php

namespace App\Services\Outreach\Facts;

use App\Services\Marketing\MarketingAgentSwitch;
use App\Services\Marketing\Ultron\UltronAbortLatch;
use App\Services\Telephony\VoiceBrake;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Los FRENOS Y CERROJOS AJENOS que N2 tiene que ver (contrato H9, D9-2 «única
 * excepción enumerada», D9-7 N2, §9.3). Es el ÚNICO fichero del H9 que nombra
 * `UltronAbortLatch`, `MarketingAgentSwitch` y `VoiceBrake`, y solo para LEER:
 * `engaged()`, `isPaused()` e `isOpen()`. Nunca `engage`, `release`, `open`,
 * `set` ni `openOrRearm` (lo vigila la guarda de SEC9).
 *
 * Las claves son los MOTIVOS de N2, `true` = frena:
 *  - `ultron_abort_latch`: el freno de ULTRON está echado;
 *  - `marketing_agent_paused`: el agente está pausado (nada de la máquina sale por WhatsApp);
 *  - `voice_braked`: el freno de voz del H8 está echado;
 *  - `legacy_autonomy_on`: `COMMERCIAL_AUTONOMY_ENABLED` encendida (D9-1: dos
 *    decisores sobre la misma persona);
 *  - `legacy_followups_active`: `MARKETING_FOLLOWUPS_SCHEDULER` encendida (D9-3:
 *    `lead.followup_due` no puede competir con `marketing:dispatch-followups`).
 *
 * FALLA CERRADO: si un freno no se puede leer (la tabla no existe, la base
 * falla), cuenta como ECHADO y su nombre va en `unreadable`. Los servicios de
 * ULTRON y del agente fallan ABIERTO a propósito (para no tumbar las
 * respuestas a quien escribe); el H9 inicia contactos, así que el lado seguro
 * es el contrario. Sin cachés: se lee en cada pasada.
 */
final class OperationalBrakes
{
    public const TABLES = ['ultron_aborts', 'marketing_agent_settings', 'voice_brakes'];

    public const KEYS = ['legacy_autonomy_on', 'legacy_followups_active', 'marketing_agent_paused', 'ultron_abort_latch', 'voice_braked'];

    /** @return array{brakes: array<string, bool>, unreadable: list<string>} */
    public function read(): array
    {
        $ilegibles = [];
        $frenos = [
            'ultron_abort_latch' => $this->guarded('ultron_abort_latch', 'ultron_aborts', static fn (): bool => app(UltronAbortLatch::class)->engaged(), $ilegibles),
            'marketing_agent_paused' => $this->guarded('marketing_agent_paused', 'marketing_agent_settings', static fn (): bool => app(MarketingAgentSwitch::class)->isPaused(), $ilegibles),
            'voice_braked' => $this->guarded('voice_braked', 'voice_brakes', static fn (): bool => VoiceBrake::isOpen(), $ilegibles),
            'legacy_autonomy_on' => config('commercial.autonomy_enabled') === true,
            'legacy_followups_active' => config('marketing.followups.scheduler_enabled') === true,
        ];
        ksort($frenos, SORT_STRING);
        sort($ilegibles, SORT_STRING);

        return ['brakes' => $frenos, 'unreadable' => $ilegibles];
    }

    /**
     * @param  list<string>  $ilegibles
     */
    private function guarded(string $nombre, string $tabla, callable $leer, array &$ilegibles): bool
    {
        try {
            if (! Schema::hasTable($tabla)) {
                $ilegibles[] = $nombre;

                return true;
            }

            return $leer() === true;
        } catch (Throwable) {
            // Tragado a propósito y reportado en `unreadable`: un freno que no se lee FRENA.
            $ilegibles[] = $nombre;

            return true;
        }
    }
}
