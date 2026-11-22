<?php

namespace App\Console\Commands;

use App\Services\IronGuard\IncidentRecorder;
use App\Services\IronGuard\UnactivatedApprovedPayments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * El AVISO de «cobrado sin servicio»: pagos de pasarela aprobados que se
 * quedaron sin su membresía (criterio en {@see UnactivatedApprovedPayments}).
 *
 * SOLO AVISA. No activa nada, no escribe en pagos ni en socios y no reintenta
 * el activador: reactivar es núcleo de pagos y lo decide una persona con la
 * consulta de diagnóstico delante.
 *
 * Con algo que avisar deja un `Log::error` y abre (o suma al) incidente
 * crítico de IRON GUARD `wompi`/`payments_approved_unactivated` con
 * `IncidentRecorder`, que no depende de IRON_GUARD_ENABLED: dos pasadas sobre
 * los mismos pagos son UN incidente con dos ocurrencias.
 *
 * La bandera (`observability.approved_unactivated.enabled`, apagada por
 * defecto) gobierna el programador y la propia orden: apagada, no se agenda y,
 * lanzada a mano, no lee ni escribe y lo dice. `--dry-run` lee e informa
 * aunque la bandera esté apagada (para probarla en producción sin encender
 * nada) y no escribe nada: ni incidente ni log.
 *
 * Sale 0 si no hay nada que avisar (o con la bandera apagada), y 1 si hay
 * pagos que avisar o la comprobación no se pudo hacer.
 */
class ApprovedUnactivatedPaymentsCheck extends Command
{
    protected $signature = 'payments:approved-unactivated-check
        {--dry-run : solo lee: no abre incidente ni escribe en el log (funciona con la bandera apagada)}
        {--json : el resultado en JSON}';

    protected $description = 'Avisa (incidente crítico + log) de pagos de pasarela aprobados que se quedaron sin su membresía. Solo lee; no activa nada.';

    public function handle(UnactivatedApprovedPayments $check, IncidentRecorder $recorder): int
    {
        $enabled = (bool) config('observability.approved_unactivated.enabled', false);
        $dryRun = (bool) $this->option('dry-run');

        if (! $enabled && ! $dryRun) {
            return $this->report([
                'enabled' => false, 'dry_run' => false, 'ran' => false,
                'message' => 'Apagado (PAYMENTS_UNACTIVATED_CHECK_ENABLED=false): no se lee ni se escribe nada. Usa --dry-run para una lectura en seco.',
            ], self::SUCCESS);
        }

        try {
            $result = $check->find();
        } catch (Throwable $e) {
            // Sin el mensaje (puede llevar SQL): solo la clase.
            if (! $dryRun) {
                $this->logError('payments.approved_unactivated.check_failed', ['error_class' => class_basename($e)]);
            }

            return $this->report([
                'enabled' => $enabled, 'dry_run' => $dryRun, 'ran' => false,
                'error_class' => class_basename($e),
                'message' => 'No se pudo comprobar ('.class_basename($e).').',
            ], self::FAILURE);
        }

        $evidence = UnactivatedApprovedPayments::evidence($result);
        $salida = [
            'enabled' => $enabled, 'dry_run' => $dryRun, 'ran' => true,
            'window' => ['from' => $result['from'], 'to' => $result['to']],
        ] + $evidence + [
            'payments' => $result['payments'],
            'incident' => null,
        ];

        $datos = UnactivatedApprovedPayments::incident($result);
        if ($datos === null) {
            return $this->report($salida, self::SUCCESS);
        }

        if (! $dryRun) {
            // El log primero: si la base no deja anotar el incidente, el aviso ya salió.
            $salida['logged'] = $this->logError('payments.approved_unactivated', $evidence);

            try {
                $incidente = $recorder->record($datos);
                $salida['incident'] = [
                    'id' => (int) $incidente->id,
                    'severity' => (string) $incidente->severity,
                    'occurrences' => (int) $incidente->occurrences,
                ];
            } catch (Throwable $e) {
                $this->logError('payments.approved_unactivated.incident_not_recorded', ['error_class' => class_basename($e)]);
                $salida['incident'] = 'not_recorded';
                $salida['incident_error'] = class_basename($e);
            }
        }

        return $this->report($salida, self::FAILURE);
    }

    /**
     * `Log::error`, sin dejar que un log que no se puede escribir tumbe el
     * aviso: ya pasó en producción que el fichero del día quedara en propiedad
     * de otro usuario y Monolog lanzara (ver ChannelLog::write()). Perder la
     * línea es menos grave que perder el incidente; devuelve si se escribió
     * para decirlo en la salida, no para callarlo.
     *
     * @param  array<string,mixed>  $context
     */
    private function logError(string $event, array $context): bool
    {
        try {
            Log::error($event, $context);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param  array<string,mixed>  $salida */
    private function report(array $salida, int $code): int
    {
        if ($this->option('json')) {
            $this->output->writeln(
                (string) json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                OutputInterface::OUTPUT_RAW,
            );

            return $code;
        }

        if (($salida['ran'] ?? false) !== true) {
            $code === self::SUCCESS ? $this->comment($salida['message']) : $this->error($salida['message']);

            return $code;
        }

        $modo = $salida['dry_run'] ? ' (en seco: no se escribe nada)' : '';
        $this->line("Pagos de pasarela aprobados del {$salida['window']['from']} al {$salida['window']['to']}{$modo}.");

        if ($salida['count'] === 0) {
            $this->info('Ninguno sin su membresía.');

            return $code;
        }

        $this->error("{$salida['count']} pago(s) aprobado(s) sin su membresía (cobrado sin servicio):");
        $this->table(
            ['transacción', 'referencia', 'proveedor', 'clase', 'aprobado'],
            array_map(static fn (array $p): array => [
                $p['transaction_id'],
                OutputFormatter::escape($p['reference']),
                OutputFormatter::escape($p['provider']),
                $p['class'],
                $p['approved_at'],
            ], $salida['payments']),
        );

        if (is_array($salida['incident'])) {
            $i = $salida['incident'];
            $this->line("Incidente de IRON GUARD #{$i['id']} ({$i['severity']}, {$i['occurrences']} ".($i['occurrences'] === 1 ? 'vez' : 'veces').').');
        } elseif ($salida['incident'] === 'not_recorded') {
            $this->error('NO se pudo anotar el incidente ('.$salida['incident_error'].').');
        }
        if (($salida['logged'] ?? null) === false) {
            $this->error('NO se pudo escribir el Log::error del aviso.');
        }

        $this->line('Qué hacer: '.UnactivatedApprovedPayments::RUNBOOK.' (diagnóstico: '.UnactivatedApprovedPayments::DIAGNOSTIC_QUERY.').');

        return $code;
    }
}
