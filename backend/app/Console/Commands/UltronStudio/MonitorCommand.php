<?php

namespace App\Console\Commands\UltronStudio;

use App\Models\Incident;
use App\Services\IronGuard\IncidentRecorder;
use App\Services\Observability\QueueHealthService;
use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\UltronStudio\StudioIntegrations;
use App\Services\UltronStudio\StudioPublications;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * El MONITOR de ULTRON STUDIO (contrato H7, OPS7 (a)): ¿sigue siendo verdad lo
 * que el Studio afirma? Agrupa comprobaciones que ya existían, con sus mismas
 * reglas sobre sus mismos servicios (una prueba de paridad lo vigila):
 *
 *  - `publication`: la de `ultron-studio:publication-check` (la cabeza de la
 *    cadena verifica y lo que declara es lo que lee el motor);
 *  - `integrations`: la de `ultron-studio:integrations-doctor --expect=…` con
 *    la expectativa de `ultron_studio.monitor.expect`, y la deriva de la
 *    configuración autorizada por una persona (`GET integrations/config`, H6
 *    §I5.3; sin reconocimiento previo no hay deriva posible);
 *  - `queues`: la de `queue:health` (un carril sin atender o saturado, o con
 *    un trabajo reservado huérfano, B-T16).
 *
 * No lanza esos comandos por dentro: `Artisan::call` pisaría la salida de
 * quien llamó al monitor con `Artisan::call`.
 *
 * Sale 0 si todo cuadra, 1 si algo no cuadra o no se pudo comprobar, y 2 con
 * una opción mal escrita. Una comprobación que revienta no tumba a las demás.
 *
 * DERIVA → IRON GUARD: con algo que no cuadra abre o suma al incidente de su
 * clase con `IncidentRecorder` (fuente `ultron_studio`; `publication_drift`,
 * `integrations_drift` o `monitor_check_failed`), igual que los detectores de
 * `iron-guard:scan`: la misma deriva repetida es UN incidente con más
 * ocurrencias. Las colas NO abren incidente propio: `iron-guard:scan` ya abre
 * `queue_unattended` y `queue_backlog` por carril (y `queue_reserved_orphaned`
 * por trabajo, B-T16), y duplicarlos sería ruido.
 * `--dry-run` solo lee (despliegue, smoke, quien opere).
 *
 * Solo lee y solo informa: no frena, no revierte, no llama a ningún proveedor
 * y no notifica fuera de IRON GUARD. El Studio no revierte solo por deriva
 * (H5 §A6): eso lo decide una persona. Nunca imprime ids de conversación,
 * textos, valores declarados ni secretos: estados, códigos y nombres.
 */
class MonitorCommand extends Command
{
    public const CHECKS = ['publication', 'integrations', 'queues'];

    public const SOURCE = 'ultron_studio';

    public const KIND_PUBLICATION = 'publication_drift';

    public const KIND_INTEGRATIONS = 'integrations_drift';

    public const KIND_CHECK_FAILED = 'monitor_check_failed';

    /** Lo que significa «no se pudo comprobar» (distinto de «comprobado y no cuadra»). */
    private const UNCHECKED = ['check_failed', 'expectation_invalid'];

    /** Un identificador que se puede enseñar tal cual (estados, clasificaciones, códigos). */
    private const SAFE = '/^[A-Za-z0-9_.\-]{1,60}$/D';

    protected $signature = 'ultron-studio:monitor
        {--check=* : publication, integrations o queues (por defecto, las tres)}
        {--dry-run : solo lee: no abre ni actualiza incidentes}
        {--json : el resultado en JSON}';

    protected $description = 'Vigila ULTRON STUDIO: publicación frente al motor, integraciones frente a lo esperado y colas. Sale 1 si algo no cuadra y abre o suma al incidente de IRON GUARD de su clase.';

    public function handle(IncidentRecorder $recorder): int
    {
        $pedidas = array_values(array_unique(array_map('strval', (array) $this->option('check'))));
        $desconocidas = array_values(array_diff($pedidas, self::CHECKS));
        if ($desconocidas !== []) {
            $this->error('Comprobación desconocida: '.OutputFormatter::escape(implode(', ', $desconocidas)).' (valen '.implode(', ', self::CHECKS).').');

            return self::INVALID;
        }
        $checks = $pedidas === [] ? self::CHECKS : array_values(array_intersect(self::CHECKS, $pedidas));

        $resultados = [];
        foreach ($checks as $check) {
            try {
                $resultados[$check] = match ($check) {
                    'publication' => $this->publication(),
                    'integrations' => $this->integrations(),
                    'queues' => $this->queues(),
                };
            } catch (Throwable $e) {
                // Sin el mensaje (puede llevar SQL): solo la clase. Las demás siguen.
                $resultados[$check] = ['ok' => false, 'status' => 'error', 'problems' => ['check_failed'], 'error' => class_basename($e)];
            }
        }

        $incidentes = [];
        if (! $this->option('dry-run')) {
            foreach ($resultados as $check => $r) {
                $datos = $r['ok'] ? null : self::incidentFor($check, $r);
                if ($datos === null) {
                    continue;
                }
                try {
                    $i = $recorder->record($datos);
                    $incidentes[] = ['check' => $check, 'id' => $i->id, 'kind' => $i->kind, 'severity' => $i->severity, 'occurrences' => (int) $i->occurrences];
                } catch (Throwable $e) {
                    // Con la base caída tampoco se puede anotar: se dice, y el código de salida ya es 1.
                    $resultados[$check]['incident'] = 'not_recorded';
                    $resultados[$check]['incidentError'] = class_basename($e);
                }
            }
        }

        $ok = $resultados !== [] && ! in_array(false, array_column($resultados, 'ok'), true);
        $resumen = [];
        foreach ($resultados as $check => $r) {
            $resumen[$check] = $r['ok'] ? 'ok' : implode(',', $r['problems']);
        }
        try {
            Log::log($ok ? 'info' : 'warning', 'ultron_studio.monitor', ['ok' => $ok, 'checks' => $resumen, 'incidents' => array_column($incidentes, 'id'), 'dry_run' => (bool) $this->option('dry-run')]);
        } catch (Throwable) {
            // Un log que no se puede escribir no cambia el veredicto.
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode(['ok' => $ok, 'dryRun' => (bool) $this->option('dry-run'), 'checks' => $resultados, 'incidents' => $incidentes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        foreach ($resultados as $check => $r) {
            $linea = $check.': '.($r['ok'] ? 'OK' : 'NO CUADRA ('.implode(', ', $r['problems']).')').self::details($check, $r);
            $r['ok'] ? $this->line(OutputFormatter::escape($linea)) : $this->warn(OutputFormatter::escape($linea));
        }
        foreach ($incidentes as $i) {
            $this->line("Incidente de IRON GUARD #{$i['id']} ({$i['kind']}, {$i['severity']}, {$i['occurrences']} ".($i['occurrences'] === 1 ? 'vez' : 'veces').').');
        }
        if ($ok) {
            $this->info('El Studio cuadra.');

            return self::SUCCESS;
        }
        $this->error('El Studio NO cuadra: '.implode(', ', array_keys(array_filter($resultados, static fn (array $r): bool => ! $r['ok']))).'.');

        return self::FAILURE;
    }

    /**
     * La cadena de publicaciones, con las reglas de
     * `ultron-studio:publication-check` sobre el mismo servicio: la cabeza no
     * verifica (`head_invalid`) o lo declarado no es lo que lee el motor
     * (`drift`; una lectura caída cuenta como deriva, Q-5 del H5). Una lectura
     * de la cadena que falla la recoge `handle()` como `check_failed`.
     *
     * @return array<string,mixed>
     */
    private function publication(): array
    {
        $estado = app(StudioPublications::class)->state();

        $problemas = [];
        if ($estado['head'] !== null && $estado['head']['signatureOk'] !== true) {
            $problemas[] = 'head_invalid';
        }
        if ($estado['drift'] !== []) {
            $problemas[] = 'drift';
        }
        $cabeza = $estado['head'];

        return [
            'ok' => $problemas === [],
            'status' => $problemas === [] ? 'ok' : 'drift',
            'problems' => $problemas,
            'envScope' => self::safe($estado['envScope']),
            'state' => self::safe($estado['state']),
            'head' => $cabeza === null ? null : ['seq' => (int) $cabeza['seq'], 'action' => self::safe($cabeza['action'] ?? null), 'signatureOk' => $cabeza['signatureOk'] === true],
            // Solo el NOMBRE del parámetro que deriva, nunca lo declarado ni lo que lee el motor.
            'driftParams' => array_values(array_unique(array_map(static fn ($d): string => self::safe(is_array($d) ? ($d['param'] ?? null) : null), $estado['drift']))),
        ];
    }

    /**
     * Las integraciones frente a lo esperado, con la regla de
     * `ultron-studio:integrations-doctor --expect` sobre el mismo servicio (la
     * clasificación del bloque y, en `elevenlabs`, también su código; un
     * bloque no disponible no vale), y la configuración autorizada frente a la
     * efectiva. Sin red y sin escribir nada.
     *
     * @return array<string,mixed>
     */
    private function integrations(): array
    {
        $esperado = (string) config('ultron_studio.monitor.expect', '');
        $mapa = IntegrationsDoctorCommand::parseExpectations($esperado);
        if ($mapa === null || $mapa === []) {
            return ['ok' => false, 'status' => 'invalid', 'problems' => ['expectation_invalid']];
        }

        $porId = array_column(app(StudioIntegrations::class)->providers(), null, 'id');
        $diferencias = [];
        foreach ($mapa as $id => $valor) {
            $bloque = $porId[$id] ?? null;
            $disponible = is_array($bloque) && ($bloque['available'] ?? false) === true;
            $vale = $disponible && (($bloque['classification'] ?? null) === $valor || ($id === 'elevenlabs' && ($bloque['code'] ?? null) === $valor));
            if (! $vale) {
                $diferencias[] = ['id' => self::safe($id), 'expected' => self::safe($valor), 'actual' => $disponible ? self::safe($bloque['classification'] ?? null) : 'unavailable'];
            }
        }

        // La configuración que reconoció una persona (H6 §I5.3): `drift` solo existe tras un reconocimiento.
        $estados = [];
        foreach ((array) (app(StudioIntegrationConfig::class)->overview()['providers'] ?? []) as $p) {
            if (is_array($p) && is_string($p['provider'] ?? null)) {
                $estados[self::safe($p['provider'])] = self::safe($p['state'] ?? null);
            }
        }
        $deriva = array_keys(array_filter($estados, static fn (string $estado): bool => $estado === 'drift'));

        $problemas = array_merge(
            array_map(static fn (array $d): string => "{$d['id']}:{$d['expected']}->{$d['actual']}", $diferencias),
            array_map(static fn (string $p): string => "config_drift:{$p}", $deriva),
        );

        return [
            'ok' => $problemas === [],
            'status' => $problemas === [] ? 'ok' : 'drift',
            'problems' => $problemas,
            'expected' => $mapa,
            'mismatches' => $diferencias,
            'configStates' => $estados,
        ];
    }

    /**
     * Los carriles de cola, con el mismo criterio que `queue:health`.
     *
     * @return array<string,mixed>
     */
    private function queues(): array
    {
        $problemas = [];
        $carriles = app(QueueHealthService::class)->snapshot();
        foreach ($carriles as $nombre => $carril) {
            if (($carril['looks_unattended'] ?? false) === true) {
                $problemas[] = self::safe((string) $nombre).':unattended';
            } elseif (($carril['breaching_slo'] ?? false) === true) {
                $problemas[] = self::safe((string) $nombre).':saturated';
            }
            // B-T16 (H-7 de OPS7): un worker muerto con un trabajo reservado; el incidente lo abre iron-guard:scan, por trabajo.
            if (($carril['looks_orphaned'] ?? false) === true) {
                $problemas[] = self::safe((string) $nombre).':orphaned';
            }
        }

        return ['ok' => $problemas === [], 'status' => $problemas === [] ? 'ok' : 'unhealthy', 'problems' => $problemas, 'lanes' => count($carriles)];
    }

    /**
     * El incidente de IRON GUARD de una comprobación que no cuadra, o null si
     * no le toca al monitor (las colas son de `iron-guard:scan`).
     *
     * @param  array<string,mixed>  $r
     * @return array<string,mixed>|null
     */
    public static function incidentFor(string $check, array $r): ?array
    {
        if ($check === 'queues') {
            return null;
        }

        $problemas = array_values(array_map('strval', (array) ($r['problems'] ?? [])));
        sort($problemas);
        $comando = $check === 'publication' ? 'php artisan ultron-studio:publication-check' : 'php artisan ultron-studio:integrations-doctor --expect=…';

        if (array_diff($problemas, self::UNCHECKED) === []) {
            return [
                'source' => self::SOURCE,
                'kind' => self::KIND_CHECK_FAILED,
                'fingerprint_keys' => [$check],
                'title' => "El monitor de ULTRON STUDIO no pudo comprobar «{$check}» (".implode(', ', $problemas).')',
                // Medio: no hay una deriva comprobada, falta la comprobación.
                'severity' => Incident::SEVERITY_MEDIUM,
                'evidence' => ['check' => $check, 'problems' => $problemas, 'status' => $r['status'] ?? null, 'error_class' => $r['error'] ?? null, 'check_command' => 'php artisan ultron-studio:monitor --check='.$check.' --dry-run'],
            ];
        }

        $evidencia = ['check' => $check, 'problems' => $problemas, 'check_command' => $comando, 'runbook' => 'backend/docs/ultron-studio/ULTRON_STUDIO_ROLLBACK.md'];
        if ($check === 'publication') {
            return [
                'source' => self::SOURCE,
                'kind' => self::KIND_PUBLICATION,
                'fingerprint_keys' => $problemas,
                'title' => in_array('head_invalid', $problemas, true)
                    ? 'La cabeza de la cadena de publicaciones de ULTRON STUDIO no verifica: el motor sella fallback'
                    : 'Lo que declara la publicación vigente de ULTRON STUDIO no es lo que lee el motor',
                // Alto: cambia (o puede cambiar) lo que ULTRON dice. No crítico: el motor sigue respondiendo.
                'severity' => Incident::SEVERITY_HIGH,
                'evidence' => $evidencia + ['env_scope' => $r['envScope'] ?? null, 'state' => $r['state'] ?? null, 'head' => $r['head'] ?? null, 'drift_params' => $r['driftParams'] ?? []],
            ];
        }

        return [
            'source' => self::SOURCE,
            'kind' => self::KIND_INTEGRATIONS,
            'fingerprint_keys' => $problemas,
            'title' => 'Las integraciones de ULTRON STUDIO no son las esperadas: '.implode(', ', $problemas),
            // Alto: una protección del Hito 6 (solo OTP, sin voz, sin ElevenLabs) o la configuración autorizada cambió.
            'severity' => Incident::SEVERITY_HIGH,
            'evidence' => $evidencia + ['mismatches' => $r['mismatches'] ?? [], 'config_states' => $r['configStates'] ?? []],
        ];
    }

    /** @param  array<string,mixed>  $r */
    private static function details(string $check, array $r): string
    {
        return match ($check) {
            'publication' => isset($r['state']) ? " · llave del motor {$r['envScope']} · estado {$r['state']}".($r['head'] === null ? ' · sin cabeza (Legacy)' : " · cabeza {$r['head']['seq']} ".($r['head']['signatureOk'] ? 'verifica' : 'NO verifica')) : '',
            'integrations' => isset($r['configStates']) ? ' · configuración: '.implode(', ', array_map(static fn ($p, $e) => "{$p} {$e}", array_keys($r['configStates']), $r['configStates'])) : '',
            'queues' => isset($r['lanes']) ? " · {$r['lanes']} carriles" : '',
            default => '',
        };
    }

    /** Un valor que se puede enseñar tal cual, o «?» (nunca un texto libre). */
    private static function safe(mixed $valor): string
    {
        return is_string($valor) && preg_match(self::SAFE, $valor) === 1 ? $valor : '?';
    }
}
