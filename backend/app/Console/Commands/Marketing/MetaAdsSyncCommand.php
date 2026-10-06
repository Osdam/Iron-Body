<?php

namespace App\Console\Commands\Marketing;

use App\Services\Marketing\Meta\MetaAdsApiClient;
use App\Services\Marketing\Meta\MetaAdsSync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Sincronización manual de Meta Ads, para traer el pasado.
 *
 * La pasada horaria solo repasa los últimos META_ADS_SYNC_DAYS días. Para que el
 * panel pueda decir cuánto se gastó el mes pasado, esos días tienen que haberse
 * sincronizado alguna vez con éxito; este comando lo hace a mano. No se programa
 * solo.
 *
 * Es la misma pasada que la del scheduler (mismo cerrojo, misma transacción,
 * mismo registro en `meta_sync_runs`) con el disparador `manual`. Repetirla no
 * duplica nada.
 */
class MetaAdsSyncCommand extends Command
{
    protected $signature = 'marketing:meta-ads-sync
        {--from= : Primer día, AAAA-MM-DD en la zona de la cuenta. Por defecto, los últimos META_ADS_SYNC_DAYS días}
        {--to= : Último día, inclusive, AAAA-MM-DD. Por defecto, hoy}
        {--no-reach : No refresca el alcance de los rangos del panel al terminar}';

    protected $description = 'Trae de Meta Ads el gasto por anuncio y día de un rango (relleno manual del pasado).';

    public function handle(MetaAdsSync $sync, MetaAdsApiClient $client): int
    {
        $tz = $client->timezone();

        $from = $this->day('from', $tz);
        $to = $this->day('to', $tz);
        if ($from === false || $to === false) {
            return self::FAILURE;
        }

        try {
            $result = $sync->run('manual', $from, $to);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['status'] === 'running') {
            $this->warn('Ya hay una sincronización de Meta Ads en curso; no se lanzó otra.');

            return self::FAILURE;
        }

        $this->table(
            ['estado', 'pasada', 'desde', 'hasta', 'filas', 'borradas', 'error'],
            [[
                $result['status'],
                '#'.$result['run_id'],
                $result['range_from'] ?? '—',
                $result['range_to'] ?? '—',
                $result['rows_upserted'],
                $result['rows_deleted'],
                $result['error_code'] ?? '—',
            ]],
        );

        if ($result['status'] !== 'ok') {
            // El mensaje ya viene saneado de la pasada: sin token ni ids completos.
            $this->error((string) ($result['error_message'] ?? 'La sincronización no terminó bien.'));

            return self::FAILURE;
        }

        $this->info('Listo. El gasto de esos días ya está en el CRM.');

        if (! $this->option('no-reach')) {
            $reach = $sync->refreshReach();
            $this->line("Alcance de los rangos del panel: {$reach['ranges_ok']} al día, {$reach['ranges_failed']} con fallo (ver el log del canal).");
        }

        return self::SUCCESS;
    }

    /** Fecha de una opción, null si no vino, o false si no se entiende. */
    private function day(string $option, string $tz): CarbonImmutable|false|null
    {
        $raw = trim((string) $this->option($option));
        if ($raw === '') {
            return null;
        }

        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $raw, $tz)
            : false;

        // createFromFormat acepta el 31 de febrero y lo corre a marzo; eso no es
        // la fecha que se pidió.
        if ($day === false || $day->format('Y-m-d') !== $raw) {
            $this->error("No entiendo esa fecha: --{$option}={$raw} (usa AAAA-MM-DD).");

            return false;
        }

        return $day;
    }
}
