<?php

namespace App\Console\Commands\Outreach;

use App\Models\Admin;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachStop;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `outreach:stop` — la PARADA GLOBAL y los frenos por consola (contrato H9,
 * D9-14, §9.2 nivel 0b, L9-44). Es una de las dos órdenes que el mando de
 * OPS9 exige antes de encender nada: sin ella no hay nivel 0b.
 *
 *   php artisan outreach:stop --all --reason="…"              la PARADA GLOBAL
 *   php artisan outreach:stop --strategy=<uuid> --reason="…"  el freno de una estrategia
 *   php artisan outreach:stop --channel=assisted --reason="…" el freno de un canal
 *
 * Abre (nunca suelta: soltar es de una persona con su llave y una nota, por
 * la API). Idempotente: si ya estaba echado, lo dice y sale con 0. Con
 * `--by=<id o correo de una cuenta del CRM>` queda a nombre de esa cuenta; sin
 * él, a nombre de la consola (`OutreachStop::CONSOLE_ADMIN_ID`). Devuelve
 * cuántos trabajos quedaron retenidos (D9-14). Sale con 2 si los datos no
 * valen y con 1 si la base falla.
 */
class OutreachStopCommand extends Command
{
    protected $signature = 'outreach:stop
        {--all : la PARADA GLOBAL (alcance global)}
        {--strategy= : el uuid de la estrategia que se frena}
        {--channel= : el canal que se frena (assisted | whatsapp_template | voice_call)}
        {--reason= : el motivo (obligatorio, 3–500 caracteres, sin teléfonos ni correos)}
        {--by= : la cuenta del CRM (id o correo) a cuyo nombre queda; sin ella, la consola}
        {--json : la respuesta en JSON}';

    protected $description = 'PARADA GLOBAL o freno de una estrategia o un canal de la automatización comercial (D9-14). Solo abre; soltar es de una persona por la API.';

    public function handle(OutreachStop $stop): int
    {
        $motivo = trim((string) $this->option('reason'));
        $alcances = array_filter([
            $this->option('all') ? OutreachStop::SCOPE_GLOBAL : null,
            is_string($this->option('strategy')) && $this->option('strategy') !== '' ? OutreachStop::SCOPE_STRATEGY : null,
            is_string($this->option('channel')) && $this->option('channel') !== '' ? OutreachStop::SCOPE_CHANNEL : null,
        ]);
        if (count($alcances) !== 1) {
            $this->error('Indica UN alcance: --all, --strategy=<uuid> o --channel=<canal>.');

            return 2;
        }
        if ($motivo === '') {
            $this->error('El motivo es obligatorio (--reason="…").');

            return 2;
        }
        $alcance = array_values($alcances)[0];
        $ref = match ($alcance) {
            OutreachStop::SCOPE_STRATEGY => strtolower((string) $this->option('strategy')),
            OutreachStop::SCOPE_CHANNEL => (string) $this->option('channel'),
            default => null,
        };
        $cuenta = $this->account();
        if ($cuenta === false) {
            $this->error('La cuenta de --by no existe.');

            return 2;
        }

        try {
            $r = $stop->open($alcance, $ref, OutreachStop::REASON_MANUAL, $motivo, $cuenta, (string) Str::uuid());
        } catch (OutreachException $e) {
            $this->error('NO SE APLICA: '.$e->getMessage().' ('.$e->errorCode.')'.(isset($e->payload['errors']) ? ' '.json_encode($e->payload['errors']) : ''));

            return 2;
        }

        if ($this->option('json')) {
            $this->output->writeln((string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }
        $this->info('── Automatización comercial · freno '.$alcance.($ref !== null ? " ({$ref})" : '').' ──');
        $this->line($r['replayed'] ? 'Ya estaba echado: no se abre otro (idempotente).' : 'ECHADO. Nada nuevo sale en ese ámbito hasta que una persona lo suelte con su llave y una nota.');
        $this->line('Freno       : '.$r['brake']['uuid'].' · motivo: '.$r['brake']['reason'].' · por: '.$r['brake']['openedBy']['name']);
        $this->line('Retenidos   : '.$r['retainedJobs'].' trabajo(s) vivo(s) que esperan (los planificados vencen si pasa su plazo).');

        return self::SUCCESS;
    }

    /** La cuenta de `--by` (id o correo), null sin la opción, false si no existe. */
    private function account(): Admin|null|false
    {
        $by = $this->option('by');
        if (! is_string($by) || trim($by) === '') {
            return null;
        }
        $by = trim($by);
        $admin = ctype_digit($by) ? Admin::query()->find((int) $by) : Admin::query()->where('email', $by)->first();

        return $admin instanceof Admin ? $admin : false;
    }
}
