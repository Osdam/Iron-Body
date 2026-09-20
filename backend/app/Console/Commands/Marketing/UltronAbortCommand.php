<?php

namespace App\Console\Commands\Marketing;

use App\Models\UltronAbort;
use App\Services\Marketing\Ultron\UltronAbortLatch;
use Illuminate\Console\Command;

/**
 * El freno de ULTRON, a mano: mirarlo, ponerlo y soltarlo.
 *
 * Soltarlo exige firmar con un nombre. No es burocracia: el freno lo acciona un
 * detector automático, y si lo soltara otro automatismo cuando el síntoma deja
 * de verse, se soltaría solo en el peor momento —cuando el fallo es
 * intermitente—. Quien decide que un silencio ya está entendido es una persona.
 *
 * Soltar el freno NO enciende ULTRON: si el entorno lo tiene apagado, sigue
 * apagado. El freno sólo empuja en un sentido.
 */
class UltronAbortCommand extends Command
{
    protected $signature = 'ultron:abort
        {--engage : acciona el freno (para ULTRON ahora mismo)}
        {--release : suelta el freno}
        {--by= : quién firma (obligatorio para accionar o soltar)}
        {--note= : por qué, para el historial}';

    protected $description = 'Estado del freno de emergencia de ULTRON; lo acciona o lo suelta con firma.';

    public function handle(UltronAbortLatch $latch): int
    {
        $firma = trim((string) $this->option('by'));

        if ($this->option('engage') && $this->option('release')) {
            $this->error('O se acciona o se suelta. Las dos a la vez no significan nada.');

            return self::FAILURE;
        }

        if ($this->option('engage')) {
            if ($firma === '') {
                $this->error('Falta --by: el freno lleva firma.');

                return self::FAILURE;
            }

            $abort = $latch->engage(UltronAbort::REASON_MANUAL, $firma, array_filter([
                'note' => $this->option('note'),
            ]));

            $this->warn('Freno '.$abort->id.' accionado por '.$abort->engaged_by.'. ULTRON no ejecuta nada.');

            return self::SUCCESS;
        }

        if ($this->option('release')) {
            if ($firma === '') {
                $this->error('Falta --by: soltar el freno es un acto de alguien, no del sistema.');

                return self::FAILURE;
            }

            $abort = $latch->release($firma, $this->option('note'));

            if ($abort === null) {
                $this->info('No había ningún freno puesto.');

                return self::SUCCESS;
            }

            $this->info('Freno '.$abort->id.' soltado por '.$abort->released_by.'.');
            $this->line('  Ojo: soltar el freno NO enciende ULTRON. Eso lo decide MARKETING_ULTRON_ENABLED.');

            return self::SUCCESS;
        }

        // Sin opciones: estado.
        $abort = $latch->current();

        if ($abort === null) {
            $this->info('FRENO SUELTO. ULTRON funciona según sus interruptores de entorno.');

            return self::SUCCESS;
        }

        $this->error('FRENO PUESTO desde '.$abort->engaged_at?->toDateTimeString().'.');
        $this->line('  motivo:     '.$abort->reason);
        $this->line('  lo accionó: '.$abort->engaged_by);
        $this->line('  evidencia:  '.json_encode($abort->evidence, JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
