<?php

namespace App\Console\Commands;

use App\Models\DeviceChallenge;
use App\Models\DeviceSecurityEvent;
use App\Models\FaceDuplicateMatch;
use App\Models\WorkforceKioskToken;
use App\Services\Workforce\WorkdayService;
use Illuminate\Console\Command;

/**
 * Mantenimiento del kiosko de personal:
 *
 *  · Marca como incidencia «salida no registrada» las jornadas abiertas de más
 *    de `workforce.workday.stale_after_hours`. NO las cierra ni inventa una
 *    hora de salida: deja de contarlas como «en jornada» y las pone delante de
 *    un administrador. Cerrar la pantalla nunca cerró una jornada, y esto
 *    tampoco.
 *  · Poda nonces y tokens del kiosko caducados hace más de un día (efímeros;
 *    la traza de cada operación vive en workday_events y en las bitácoras) y
 *    los intentos de dispositivos de más de 180 días.
 *  · Poda los diagnósticos de rostro duplicado vencidos
 *    (`workforce.face.duplicate_match_retention_days`); la traza del rechazo
 *    sigue en la bitácora.
 */
class WorkforceMaintenanceCommand extends Command
{
    protected $signature = 'workforce:maintenance';

    protected $description = 'Marca salidas olvidadas como incidencia y poda desafíos y tokens caducados del kiosko';

    public function handle(WorkdayService $workdays): int
    {
        $flagged = $workdays->flagStale();
        $limit = now()->subDay();
        $challenges = DeviceChallenge::query()->where('expires_at', '<', $limit)->delete();
        $tokens = WorkforceKioskToken::query()->where('expires_at', '<', $limit)->delete();
        // Intentos de dispositivos: evidencia de seguridad, se guardan 180 días.
        $intentos = DeviceSecurityEvent::query()->where('created_at', '<', now()->subDays(180))->delete();
        $coincidencias = FaceDuplicateMatch::query()
            ->where('created_at', '<', now()->subDays((int) config('workforce.face.duplicate_match_retention_days', 30)))
            ->delete();

        $this->info("Jornadas marcadas como incidencia: {$flagged}. Desafíos podados: {$challenges}. Tokens podados: {$tokens}. Intentos podados: {$intentos}. Coincidencias de rostro podadas: {$coincidencias}.");

        return self::SUCCESS;
    }
}
