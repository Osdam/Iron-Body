<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Purga de las señales de tiempo real.
 *
 *   php artisan realtime:prune
 *   php artisan realtime:prune --dry-run
 *
 * Son avisos efímeros, no histórico: quien los lee vuelve a pedir el estado a
 * la base. Las de socio y entrenador ya se podan al escribir (más de 5 min),
 * pero solo cuando ESE socio o entrenador recibe otra, así que las de quien no
 * vuelve se quedan. El canal global (`catalog_events`) y el registro de clases
 * del CRM (`class_events`) no se podaban nunca: con cada reserva escriben una
 * fila.
 *
 * Nadie pierde un aviso que necesite: la app de los socios y la del entrenador
 * recargan al volver a primer plano, y el CRM, si su cursor ya no existe, pide
 * el estado completo (`ClassEventsFeed::open`).
 */
class PruneRealtimeEvents extends Command
{
    protected $signature = 'realtime:prune {--dry-run : Cuenta sin borrar}';

    protected $description = 'Borra las señales de tiempo real que ya nadie va a leer';

    /** Tabla => antigüedad máxima en horas. */
    private const RETENCION = [
        'member_realtime_events' => 1,
        'trainer_realtime_events' => 1,
        'catalog_events' => 24 * 7,
        'class_events' => 24 * 7,
    ];

    private const LOTE = 5000;

    public function handle(): int
    {
        $simulacro = (bool) $this->option('dry-run');

        foreach (self::RETENCION as $tabla => $horas) {
            $corte = now()->subHours($horas);
            $base = DB::table($tabla)->where('created_at', '<', $corte);

            if ($simulacro) {
                $this->line(sprintf('%s: %d por borrar (más de %d h).', $tabla, (clone $base)->count(), $horas));

                continue;
            }

            // Por lotes: un DELETE de cientos de miles de filas bloquea la tabla
            // que están leyendo los streams.
            $borradas = 0;
            do {
                $ids = (clone $base)->orderBy('id')->limit(self::LOTE)->pluck('id');
                if ($ids->isNotEmpty()) {
                    $borradas += DB::table($tabla)->whereIn('id', $ids)->delete();
                }
            } while ($ids->count() === self::LOTE);

            $this->line(sprintf('%s: %d borradas (más de %d h).', $tabla, $borradas, $horas));
        }

        return self::SUCCESS;
    }
}
