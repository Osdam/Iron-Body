<?php

namespace App\Console\Commands;

use App\Exceptions\DeletedPlanRestoreException;
use App\Services\Membership\DeletedPlanRestorer;
use Illuminate\Console\Command;

/**
 * Restaura como histórico un plan borrado del catálogo y vuelve a enlazar los
 * cobros que el borrado dejó sin plan. La lógica y sus garantías están en
 * {@see DeletedPlanRestorer}.
 *
 *   php artisan plans:restore-deleted --plan=22 --snapshot=plan-22.json --actor=1            (en seco)
 *   php artisan plans:restore-deleted --plan=22 --snapshot=plan-22.json --actor=1 --commit
 *
 * EN SECO POR DEFECTO: hace todo dentro de una transacción y la deshace, para
 * que el informe sea lo que de verdad va a pasar. Solo `--commit` confirma.
 * Volver a ejecutarlo cuando ya está restaurado no escribe nada.
 */
class RestoreDeletedPlanCommand extends Command
{
    protected $signature = 'plans:restore-deleted
        {--plan= : Id del plan borrado (nunca el nombre)}
        {--snapshot= : JSON con la fila del plan sacada del respaldo: {table: "plans", row: {...}}}
        {--actor= : Id del administrador (persona) que firma la traza}
        {--reason= : Motivo que queda en la traza}
        {--sha256= : SHA-256 esperado del snapshot; si no coincide, aborta}
        {--commit : Confirma los cambios. Sin esta opción todo termina en ROLLBACK}
        {--json : Imprime el informe como JSON}';

    protected $description = 'Restaura como histórico (archivado) un plan borrado y vuelve a enlazar sus cobros demostrados por la auditoría';

    private const DEFAULT_REASON = 'Restaurar como histórico el plan eliminado del catálogo y volver a enlazar los cobros '
        .'que su traza de creación demuestra (el borrado los dejó sin plan por ON DELETE SET NULL).';

    public function handle(DeletedPlanRestorer $restorer): int
    {
        foreach (['plan', 'snapshot', 'actor'] as $opcion) {
            if (blank($this->option($opcion))) {
                $this->error("Falta --{$opcion}.");

                return self::INVALID;
            }
        }
        if (! ctype_digit((string) $this->option('plan')) || ! ctype_digit((string) $this->option('actor'))) {
            $this->error('--plan y --actor son ids numéricos.');

            return self::INVALID;
        }
        $motivo = trim((string) ($this->option('reason') ?? '')) ?: self::DEFAULT_REASON;
        $confirmar = (bool) $this->option('commit');

        try {
            $informe = $restorer->run(
                (int) $this->option('plan'),
                (string) $this->option('snapshot'),
                (int) $this->option('actor'),
                mb_substr($motivo, 0, 500),
                $confirmar,
                $this->option('sha256') ?: null,
            );
        } catch (DeletedPlanRestoreException $e) {
            $this->error("[{$e->code_}] {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->print($informe);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $r */
    private function print(array $r): void
    {
        $seco = $r['mode'] === 'dry-run';
        $plan = $r['plan'];
        $this->line(($seco ? 'EN SECO (ROLLBACK)' : 'CONFIRMADO (COMMIT)').' — resultado: '.$r['outcome']);
        $this->newLine();

        $this->table(['Plan', 'Valor'], [
            ['Id', $plan['id']],
            ['Nombre', $plan['name']],
            ['Ya existía (archivado)', $plan['existed_before'] ? 'sí' : 'no'],
            ['Precio / días', $plan['price'].' / '.$plan['duration_days']],
            ['Creado', $plan['created_at']],
            ['Queda', 'ARCHIVADO: active=no, sellable=no, visible_in_app=no'],
            ['Motivo del archivo', $plan['archive_reason']],
            ['Borrado (auditoría)', '#'.$r['deletion_audit']['id'].' '.$r['deletion_audit']['created_at'].' por '.$r['deletion_audit']['actor_name']],
            ['Snapshot', $r['snapshot']['file']],
            ['SHA-256 snapshot', $r['snapshot']['sha256']],
            ['Respaldo de origen', ($r['snapshot']['source'] ?? '—').' (SHA-256 '.($r['snapshot']['dump_sha256'] ?? '—').')'],
            ['Columnas que toman su valor por defecto', $r['missing_columns'] === [] ? 'ninguna' : implode(', ', $r['missing_columns'])],
        ]);

        $this->line(count($r['payments']).' cobro(s) se '.($seco ? 'enlazarían' : 'enlazaron').' con el plan #'.$plan['id'].':');
        $this->table(
            ['Cobro', 'Referencia', 'Socio', 'Importe', 'Medio', 'Estado', 'Vigencia', 'Creado', 'Traza'],
            array_map(fn (array $p): array => [
                '#'.$p['id'],
                $p['reference'] ?? '—',
                '#'.$p['user_id'].' '.($p['user_name'] ?? '?').($p['member_id'] ? ' (ficha #'.$p['member_id'].')' : ''),
                $p['amount'],
                $p['method'],
                $p['status'],
                ($p['period_start'] ?? '—').' → '.($p['period_end'] ?? '—'),
                $p['created_at'],
                '#'.implode(', #', $p['creation_audit_ids']),
            ], $r['payments']),
        );

        $x = $r['excluded'];
        $this->line('Cobros sin plan que NO se tocan: '.$x['migrated'].' MIGR-*, '.$x['no_creation_audit'].' sin traza de creación, '
            .$x['audit_without_plan'].' con traza sin plan_id, '.count($x['other_plan']).' de otro plan, '
            .count($x['ambiguous']).' ambiguos, '.$x['created_after_deletion'].' creados después del borrado.');
        foreach ($x['other_plan'] as $o) {
            $this->line('  · cobro #'.$o['payment_id'].' declara el plan '.json_encode($o['declared']));
        }
        foreach ($x['ambiguous'] as $o) {
            $this->warn('  · cobro #'.$o['payment_id'].' AMBIGUO: su traza declara '.json_encode($o['declared']));
        }

        $w = $r['writes'];
        $this->newLine();
        $this->line('Escrituras ('.($seco ? 'deshechas' : 'confirmadas').'): '.$w['plans_insert'].' INSERT plans, '
            .$w['payments_update_statements'].' UPDATE payments ('.$w['payments_rows'].' filas), '
            .$w['audit_logs_insert'].' INSERT audit_logs. Ninguna otra.');
        $this->line('Sin cambios en: '.implode(', ', $r['checks']['guarded_tables']).' (comparado antes y después).');

        $s = $r['sequence'];
        if ($s['below_restored_id'] === true) {
            $this->warn("La secuencia de plans ({$s['driver']}) va por {$s['last_value']}, por debajo del #{$plan['id']}. NO se toca: un plan nuevo chocaría al llegar a ese id.");
        } else {
            $this->line("Secuencia de plans ({$s['driver']}): ".($s['last_value'] ?? 'desconocida').'. No se toca.');
        }
        $this->line("Socios cuyo users.plan es «{$plan['name']}»: {$r['users_with_plan_name']} (desde ahora su plan resuelve a este, archivado).");
        $this->line("Lotes de traslado con este plan por nombre y sin id: {$r['transfer_batches_without_plan_id']} (no se tocan).");

        if ($seco) {
            $this->newLine();
            $this->info('Nada quedó escrito. Repite con --commit para confirmar.');
        }
    }
}
