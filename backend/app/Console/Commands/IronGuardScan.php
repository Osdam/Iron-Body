<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Services\IronGuard\ChannelHealthDetector;
use App\Services\Observability\ChannelLog;
use Illuminate\Console\Command;

/**
 * Pasada de IRON GUARD sobre la salud del canal.
 *
 * Consulta estado real —eventos sin procesar, mensajes muertos, adjuntos
 * fallidos, errores de Meta, jobs caídos— y abre o actualiza incidentes
 * agrupados por clase de problema.
 *
 * Es idempotente: correrlo diez veces con el mismo problema deja UN incidente
 * con diez ocurrencias, no diez incidentes.
 */
class IronGuardScan extends Command
{
    protected $signature = 'iron-guard:scan
        {--json : Salida en JSON}
        {--force : Ejecuta aunque IRON_GUARD_ENABLED sea false}';

    protected $description = 'Detecta incidentes del canal de WhatsApp (determinista, sin IA).';

    public function handle(ChannelHealthDetector $detector): int
    {
        if (! (bool) config('observability.enabled', false) && ! $this->option('force')) {
            if (! $this->option('json')) {
                $this->comment('IRON GUARD está apagado (IRON_GUARD_ENABLED=false). Usa --force para una pasada puntual.');
            }

            return self::SUCCESS;
        }

        $incidents = $detector->scan();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'detected' => count($incidents),
                'incidents' => array_map(fn (Incident $i) => [
                    'id' => $i->id,
                    'source' => $i->source,
                    'kind' => $i->kind,
                    'severity' => $i->severity,
                    'title' => $i->title,
                    'occurrences' => $i->occurrences,
                ], $incidents),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($incidents === []) {
            $this->info('Sin incidentes. El canal está sano.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['id', 'gravedad', 'fuente', 'veces', 'qué pasa'],
            array_map(fn (Incident $i) => [
                $i->id,
                $this->paint($i->severity),
                $i->source,
                $i->occurrences,
                mb_substr($i->title, 0, 70),
            ], $incidents),
        );

        ChannelLog::info('guard.scan.completed', ['detected' => count($incidents)]);

        // Se devuelve SUCCESS a propósito aunque haya incidentes: detectar es el
        // trabajo del comando, no fallar. Un exit code distinto haría que el
        // scheduler lo tratara como avería del propio detector.
        return self::SUCCESS;
    }

    private function paint(string $severity): string
    {
        return match ($severity) {
            Incident::SEVERITY_CRITICAL => '<fg=red;options=bold>CRÍTICO</>',
            Incident::SEVERITY_HIGH => '<fg=red>alto</>',
            Incident::SEVERITY_MEDIUM => '<fg=yellow>medio</>',
            default => '<fg=gray>bajo</>',
        };
    }
}
