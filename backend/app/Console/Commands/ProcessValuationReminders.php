<?php

namespace App\Console\Commands;

use App\Services\Valuations\ValuationReminderProcessor;
use Illuminate\Console\Command;

/**
 * Envía los avisos vencidos de la agenda de valoraciones.
 *
 *   php artisan valuations:process-reminders
 *
 * Lo corre el scheduler cada minuto (`withoutOverlapping()->onOneServer()`,
 * routes/console.php). Sin colas. Seguro de repetir y de correr dos a la vez:
 * cada aviso se reclama con un UPDATE condicionado (y `SKIP LOCKED` en
 * PostgreSQL), y la notificación es idempotente por `event_key`. Ver
 * {@see ValuationReminderProcessor}.
 */
class ProcessValuationReminders extends Command
{
    protected $signature = 'valuations:process-reminders {--limit=100 : Avisos como mucho por corrida}';

    protected $description = 'Envía los avisos vencidos de la agenda de valoraciones (socio y CRM)';

    public function handle(ValuationReminderProcessor $processor): int
    {
        $limite = max(1, min(500, (int) $this->option('limit')));
        $r = $processor->run($limite);

        $this->info(sprintf(
            'Avisos de valoraciones: reclamados %d, aceptados %d, omitidos %d, con error %d, fallidos %d (recuperados %d, agotados %d).',
            $r['claimed'], $r['accepted'], $r['skipped'], $r['error'], $r['failed'], $r['recovered'], $r['exhausted'],
        ));

        return self::SUCCESS;
    }
}
