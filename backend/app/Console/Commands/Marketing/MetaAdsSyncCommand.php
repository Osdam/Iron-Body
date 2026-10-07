<?php

namespace App\Console\Commands\Marketing;

use App\Models\MetaAdEntity;
use App\Models\MetaSyncRun;
use App\Services\Marketing\Attribution\LeadSourceClassifier;
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
 * duplica nada. Solo lee de Meta.
 *
 * MULTICUENTA: por defecto, todas las cuentas conectadas, cada una en su
 * pasada ({@see MetaAdsSync::runAll()}); con `--account`, solo esas. Una tabla
 * por cuenta. Si Meta pide frenar en una, las siguientes no se piden, y el
 * alcance de NINGUNA se pide en esa vuelta (aunque el límite llegara en la
 * última).
 */
class MetaAdsSyncCommand extends Command
{
    protected $signature = 'marketing:meta-ads-sync
        {--from= : Primer día, AAAA-MM-DD en la zona de la cuenta. Por defecto, los últimos META_ADS_SYNC_DAYS días}
        {--to= : Último día, inclusive, AAAA-MM-DD. Por defecto, hoy}
        {--account=* : Cuenta(s) conectada(s), con o sin act_ (se puede repetir o separar por comas). Por defecto, todas}
        {--no-reach : No refresca el alcance de los rangos del panel al terminar}';

    protected $description = 'Trae de Meta Ads el gasto por anuncio y día de un rango, por cuenta conectada (relleno manual del pasado).';

    public function handle(MetaAdsSync $sync, MetaAdsApiClient $client): int
    {
        $tz = $client->timezone();

        $from = $this->day('from', $tz);
        $to = $this->day('to', $tz);
        if ($from === false || $to === false) {
            return self::FAILURE;
        }

        $only = $this->accountsOption($client);
        if ($only === false) {
            return self::FAILURE;
        }

        try {
            $all = $sync->runAll('manual', $from, $to, $only);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($all['status'] === MetaSyncRun::STATUS_NOT_CONFIGURED) {
            $this->error(MetaAdsApiClient::problemMessage((string) ($all['reason'] ?? 'not_configured')));

            return self::FAILURE;
        }

        $names = MetaAdEntity::accountNames(array_map('strval', array_column($all['accounts'], 'account')));
        $ok = [];
        // El de la vuelta entera: también un límite en la petición principal de la ÚLTIMA cuenta,
        // que no deja a nadie «saltado» y que el resultado de esa cuenta no marca como `limited`.
        $limited = ($all['limited'] ?? false) === true;
        foreach ($all['accounts'] as $result) {
            $account = (string) $result['account'];
            $this->line('Cuenta '.$this->label($account, $names[$account] ?? null).':');

            if ($result['status'] === MetaSyncRun::STATUS_RUNNING) {
                $this->warn('  Ya hay una sincronización de esta cuenta en curso; no se lanzó otra.');

                continue;
            }

            if ($result['status'] === MetaAdsSync::STATUS_SKIPPED) {
                $this->warn('  No se pidió: Meta pidió frenar en una cuenta anterior (el cupo es de la app). Vuelve a lanzarlo más tarde.');

                continue;
            }

            $this->table(
                ['estado', 'pasada', 'desde', 'hasta', 'filas', 'borradas', 'error'],
                [[
                    $result['status'],
                    isset($result['run_id']) ? '#'.$result['run_id'] : '—',
                    $result['range_from'] ?? '—',
                    $result['range_to'] ?? '—',
                    $result['rows_upserted'] ?? 0,
                    $result['rows_deleted'] ?? 0,
                    $result['error_code'] ?? '—',
                ]],
            );

            if ($result['status'] !== MetaSyncRun::STATUS_OK) {
                // El mensaje ya viene saneado de la pasada: sin token ni ids completos.
                $this->error('  '.($result['error_message'] ?? 'La sincronización no terminó bien.'));

                continue;
            }

            $ok[] = $account;
        }

        if ($ok !== [] && ! $this->option('no-reach')) {
            if ($limited) {
                $this->warn('Alcance: no se pide en esta vuelta, Meta pidió frenar.');
            } else {
                foreach ($ok as $account) {
                    $accountSync = $sync->forAccount($account);
                    $reach = $accountSync->refreshReach();
                    $this->line('Alcance de '.$this->label($account, $names[$account] ?? null).': '.$this->reachLine($reach));
                    if ($accountSync->limited()) {
                        $this->warn('Meta pidió frenar: el alcance de las demás cuentas no se pide en esta vuelta.');

                        break;
                    }
                }
            }
        }

        if ($all['status'] !== MetaSyncRun::STATUS_OK) {
            return self::FAILURE;
        }

        $this->info('Listo. El gasto de esos días ya está en el CRM.');

        return self::SUCCESS;
    }

    /**
     * Las cuentas de `--account`, normalizadas; null si no vino (todas), o
     * false si alguna no está conectada.
     *
     * @return list<string>|false|null
     */
    private function accountsOption(MetaAdsApiClient $client): array|false|null
    {
        $raw = (array) $this->option('account');
        if ($raw === []) {
            return null;
        }

        $wanted = MetaAdsApiClient::parseAccountIds($raw);
        $connected = $client->accountIds();
        $unknown = array_diff($wanted, $connected);
        if ($wanted === [] || $unknown !== []) {
            $this->error('--account tiene que ser una cuenta conectada (META_AD_ACCOUNT_IDS): '.implode(', ', $connected === [] ? ['ninguna'] : $connected).'.');

            return false;
        }

        return $wanted;
    }

    private function label(string $account, ?string $name): string
    {
        $ref = LeadSourceClassifier::maskId($account);

        return $name !== null ? "{$name} ({$ref})" : (string) $ref;
    }

    /** @param  array{status: string, ranges_ok: int, ranges_failed: int, rows: int}  $reach */
    private function reachLine(array $reach): string
    {
        return match ($reach['status']) {
            MetaAdsSync::REACH_SKIPPED_INACTIVE => 'no se pidió: sin impresiones en '.MetaAdsSync::REACH_ACTIVE_DAYS.' días (su alcance es 0).',
            default => "{$reach['ranges_ok']} al día, {$reach['ranges_failed']} con fallo (ver el log del canal).",
        };
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
