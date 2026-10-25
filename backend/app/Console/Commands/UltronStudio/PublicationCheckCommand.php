<?php

namespace App\Console\Commands\UltronStudio;

use App\Services\UltronStudio\StudioPublications;
use Illuminate\Console\Command;
use Throwable;

/**
 * ¿Sigue siendo VERDAD lo que declara la cadena de publicaciones? (contrato H5
 * §A6, «Freno»). Para cron o para quien opere: sale 1 si la cabeza no verifica
 * (huella, firma o forma: el motor la está sellando `fallback`) o si lo que
 * declara alguna de sus partes no es lo que lee hoy el motor (deriva; una
 * lectura caída cuenta como deriva, Q-5). Sale 0 si todo cuadra, también sin
 * ninguna publicación (Legacy).
 *
 * Solo lee y solo informa: no frena, no revierte y no toca nada. El Studio no
 * revierte solo por deriva (§A6): eso lo decide una persona.
 *
 * Nunca imprime ids de conversación, textos ni secretos: lo mismo que
 * `status.engine.publication` (`PublicationStateDto`).
 */
class PublicationCheckCommand extends Command
{
    protected $signature = 'ultron-studio:publication-check {--json : el resultado en JSON (con el PublicationStateDto)}';

    protected $description = 'Comprueba la publicación vigente de ULTRON STUDIO: que la cabeza de la cadena verifica y que lo declarado es lo que lee el motor. Sale 1 ante deriva o cabeza inválida.';

    public function handle(StudioPublications $publications): int
    {
        try {
            $estado = $publications->state();
        } catch (Throwable $e) {
            // Sin el mensaje (puede llevar SQL): no se pudo comprobar, y eso no es un «todo bien».
            $this->error('No se pudo leer la cadena de publicaciones ('.class_basename($e).').');

            return self::FAILURE;
        }

        $problemas = [];
        if ($estado['head'] !== null && $estado['head']['signatureOk'] !== true) {
            $problemas[] = 'head_invalid';
        }
        if ($estado['drift'] !== []) {
            $problemas[] = 'drift';
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $problemas === [], 'problems' => $problemas, 'publication' => $estado], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $problemas === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line("Llave del motor: {$estado['envScope']} · estado: {$estado['state']}");
        $cabeza = $estado['head'];
        $this->line($cabeza === null
            ? 'Cadena vacía: el motor lee Legacy.'
            : "Cabeza: entrada {$cabeza['seq']} ({$cabeza['action']}, {$cabeza['at']}) · ".($cabeza['signatureOk'] ? 'verifica' : 'NO VERIFICA'));
        foreach (['canary' => 'Canario', 'rest' => 'Resto'] as $parte => $nombre) {
            $p = $estado[$parte];
            $this->line("{$nombre}: ".($p === null ? 'Legacy' : "«{$p['label']}» ({$p['versionUuid']})"));
        }
        foreach ($estado['drift'] as $d) {
            $this->warn("Deriva en {$d['param']}: declara ".json_encode($d['declared']).' y el motor lee '.json_encode($d['engine']).'.');
        }

        if ($problemas === []) {
            $this->info('La publicación vigente cuadra con el motor.');

            return self::SUCCESS;
        }

        $this->error('La publicación vigente NO cuadra: '.implode(', ', $problemas).'.');

        return self::FAILURE;
    }
}
