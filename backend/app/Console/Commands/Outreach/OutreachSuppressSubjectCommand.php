<?php

namespace App\Console\Commands\Outreach;

use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Run\BudgetReservations;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachStore;
use App\Services\Outreach\Run\OutreachTables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `outreach:suppress-subject <m:id|l:id>` — la SUPRESIÓN de la Ley 1581 en la
 * ejecución (contrato H9, §3 protección 10, D9-15, L9-5; `ops/supresion-1581.sql`
 * de OPS9). Dos mitades, porque la base lo exige así:
 *
 *  1. LO QUE LA APLICACIÓN PUEDE HACER (esta orden, con `--apply`): parar todo
 *     lo vivo de la persona: cancelar sus trabajos vivos (`suppressed_1581`,
 *     reserva liberada) y cerrar sus inscripciones abiertas (`exited`,
 *     `suppressed_1581`). Nada nuevo nace: una persona suprimida no vuelve a
 *     entrar mientras exista su clave (el libro queda).
 *  2. LO QUE SOLO EL DUEÑO DE LA BASE PUEDE HACER (el SQL que esta orden
 *     imprime, para el runbook de OPS9): la forma suprimida de las filas
 *     (`subject_key = 'x:<id>'`, `subject_ref` = 64 ceros, sin socio ni lead,
 *     `phone_ref` a NULL, `redacted_at`) y borrar los contadores `phone:`. Las
 *     columnas son inmutables para la aplicación (disparadores + `REVOKE`):
 *     va en una transacción del dueño con `ALTER TABLE … DISABLE TRIGGER
 *     USER`, nunca desde aquí (Q-3 de DB9).
 *
 * Sin `--apply` es un informe en seco: cuenta lo que hay y enseña el SQL.
 */
class OutreachSuppressSubjectCommand extends Command
{
    protected $signature = 'outreach:suppress-subject
        {subject : la clave interna de la persona (m:<member_id> o l:<lead_id>)}
        {--apply : parar lo vivo de la persona (cancelar trabajos y cerrar inscripciones); sin él, solo informa}
        {--json : el resumen en JSON}';

    protected $description = 'Supresión 1581 de una persona en la automatización comercial: para lo vivo (aplicación) y enseña el SQL de la forma suprimida (dueño de la base).';

    public const EXIT_REASON = 'suppressed_1581';

    public function handle(OutreachStore $store): int
    {
        $clave = trim((string) $this->argument('subject'));
        if (! SubjectKey::isValid($clave)) {
            $this->error('La persona se nombra por su clave interna: m:<id> o l:<id>.');

            return 2;
        }
        $ahora = RelativeTime::utc(now());
        $tablas = [OutreachTables::ENROLLMENTS, OutreachTables::JOBS, OutreachTables::DECISIONS, OutreachTables::OUTCOMES];
        $recuento = [];
        foreach ($tablas as $t) {
            $recuento[$t] = (int) DB::table($t)->where('subject_key', $clave)->count();
        }
        $vivos = DB::table(OutreachTables::JOBS)->where('subject_key', $clave)->whereIn('status', OutreachTables::JOB_LIVE_STATUSES)->get();
        $abiertas = DB::table(OutreachTables::ENROLLMENTS)->where('subject_key', $clave)->whereIn('status', OutreachTables::ENROLLMENT_OPEN_STATUSES)->get();
        $telefonos = DB::table(OutreachTables::JOBS)->where('subject_key', $clave)->whereNotNull('phone_ref')->distinct()->pluck('phone_ref')->all();

        $aplicado = ['jobsCanceled' => 0, 'enrollmentsClosed' => 0];
        if ($this->option('apply')) {
            foreach ($vivos as $trabajo) {
                $cancelable = in_array((string) $trabajo->status, ['planned', 'claimed'], true);
                if ($cancelable && $store->transitionJob((int) $trabajo->id, ['planned', 'claimed'], 'canceled', $ahora, ['cancel_reason' => self::EXIT_REASON])) {
                    $aplicado['jobsCanceled']++;
                    if ((int) $trabajo->reserved_units > 0 || (int) $trabajo->reserved_micros > 0) {
                        $uuid = (string) DB::table(OutreachTables::STRATEGIES)->where('id', (int) $trabajo->strategy_id)->value('uuid');
                        (new BudgetReservations)->release((string) $trabajo->budget_day, (string) $trabajo->mode, BudgetReservations::parcelsOfJob($trabajo, $uuid), $ahora);
                        $store->markBudgetReleased((int) $trabajo->id, $ahora);
                    }
                }
            }
            foreach ($abiertas as $e) {
                if ($store->closeEnrollment((int) $e->id, 'exited', self::EXIT_REASON, $ahora)) {
                    $aplicado['enrollmentsClosed']++;
                }
            }
        }

        $sql = self::ownerSql($clave, $telefonos);
        $r = ['subject' => $clave, 'applied' => (bool) $this->option('apply'), 'rows' => $recuento, 'liveJobs' => count($vivos), 'openEnrollments' => count($abiertas), 'phoneRefs' => count($telefonos)] + $aplicado + ['ownerSql' => $sql];
        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        $this->info('── Automatización comercial · supresión 1581 de '.$clave.($this->option('apply') ? ' · APLICADA (lo vivo)' : ' · EN SECO').' ──');
        $this->line('Filas       : inscripciones '.$recuento[OutreachTables::ENROLLMENTS].' · trabajos '.$recuento[OutreachTables::JOBS].' · decisiones '.$recuento[OutreachTables::DECISIONS].' · resultados '.$recuento[OutreachTables::OUTCOMES].' · teléfonos (seudónimos) '.count($telefonos));
        $this->line('Vivo        : '.count($vivos).' trabajo(s) · '.count($abiertas).' inscripción(es) abierta(s)'.($this->option('apply') ? ' → cancelados '.$aplicado['jobsCanceled'].' · cerradas '.$aplicado['enrollmentsClosed'] : ' (con --apply se paran)'));
        $this->line('Modo        : '.(OutreachConfig::active() ? OutreachConfig::mode() : 'apagado').' (la supresión no depende de las banderas)');
        $this->newLine();
        $this->line('SQL del DUEÑO de la base (OPS9, en una transacción, con orden escrita; la aplicación no puede: columnas inmutables y REVOKE):');
        $this->output->writeln($sql, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * La forma suprimida (L9-5; CHECK `*_subject_chk`): `subject_key = 'x:' || id`,
     * `subject_ref` = 64 ceros, sin socio ni lead, `phone_ref` NULL y
     * `redacted_at`; los contadores `phone:` de sus seudónimos se borran.
     *
     * @param  list<string>  $phoneRefs
     */
    public static function ownerSql(string $subjectKey, array $phoneRefs): string
    {
        $k = str_replace("'", "''", $subjectKey);
        $ceros = str_repeat('0', 64);
        $lineas = ['BEGIN;', "-- Supresión 1581 de {$k} (ejecutar como DUEÑO; DISABLE/ENABLE TRIGGER toma SHARE ROW EXCLUSIVE: hora de poco tráfico)."];
        foreach ([OutreachTables::ENROLLMENTS, OutreachTables::JOBS, OutreachTables::DECISIONS, OutreachTables::OUTCOMES] as $t) {
            $lineas[] = "ALTER TABLE {$t} DISABLE TRIGGER USER;";
        }
        $lineas[] = 'UPDATE '.OutreachTables::ENROLLMENTS." SET subject_key = 'x:' || id::text, subject_ref = '{$ceros}', member_id = NULL, lead_id = NULL, redacted_at = now() WHERE subject_key = '{$k}' AND redacted_at IS NULL;";
        $lineas[] = 'UPDATE '.OutreachTables::JOBS." SET subject_key = 'x:' || id::text, subject_ref = '{$ceros}', phone_ref = NULL, redacted_at = now() WHERE subject_key = '{$k}' AND redacted_at IS NULL;";
        $lineas[] = 'UPDATE '.OutreachTables::DECISIONS." SET subject_key = 'x:' || id::text, subject_ref = '{$ceros}', redacted_at = now() WHERE subject_key = '{$k}' AND redacted_at IS NULL;";
        $lineas[] = 'UPDATE '.OutreachTables::OUTCOMES." SET subject_key = 'x:' || id::text, subject_ref = '{$ceros}', redacted_at = now() WHERE subject_key = '{$k}' AND redacted_at IS NULL;";
        foreach ($phoneRefs as $ref) {
            if (preg_match('/^[0-9a-f]{64}$/D', (string) $ref) === 1) {
                $lineas[] = 'DELETE FROM '.OutreachTables::BUDGETS." WHERE scope = 'phone:{$ref}';";
            }
        }
        foreach ([OutreachTables::ENROLLMENTS, OutreachTables::JOBS, OutreachTables::DECISIONS, OutreachTables::OUTCOMES] as $t) {
            $lineas[] = "ALTER TABLE {$t} ENABLE TRIGGER USER;";
        }
        $lineas[] = 'COMMIT;';

        return implode("\n", $lineas);
    }
}
