<?php

namespace App\Console\Commands\Marketing;

use App\Models\MarketingLeadAttribution;
use App\Services\Marketing\Attribution\LeadUniverse;
use App\Services\Marketing\Meta\MetaAdsApiClient;
use App\Services\Marketing\Meta\MetaAdsApiException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Diagnóstico: ¿de qué cuenta publicitaria son los anuncios que traen leads?
 *
 * Toma los anuncios del primer toque de los leads reales de pauta y pregunta a
 * Meta, uno a uno (`GET /{ad_id}`, el token solo en la cabecera), su
 * `account_id`. No asume que todos sean de las cuentas conectadas. Un anuncio
 * de una cuenta que el token no ve sale «sin acceso»: hay que dar `ads_read`
 * sobre esa cuenta al usuario de sistema para saber cuál es.
 *
 * «Conectadas» son TODAS las de META_AD_ACCOUNT_IDS (o META_AD_ACCOUNT_ID).
 *
 * Solo lee: no cambia la configuración ni la atribución. Las cuentas en juego
 * son las de esos anuncios y las conectadas que gastaron en la ventana (su
 * gasto también es pauta). Con dos o más, MULTI_AD_ACCOUNT_REQUIRED=YES, y no
 * se fuerza nada: el panel sigue resolviendo contra las cuentas conectadas y
 * avisa de la pauta que no está en ellas. Con algún anuncio sin respuesta y
 * menos de dos cuentas vistas, UNKNOWN. CONNECTED_ACCOUNT_HAS_LEAD_ADS dice
 * aparte si alguna conectada tiene alguno de esos anuncios.
 *
 * Una petición por anuncio, con pausa: la app tiene un cupo corto. Ante un
 * límite de Meta o un token inválido (190) se detiene y lo dice.
 */
class MetaAdsAdAccountsCommand extends Command
{
    protected $signature = 'marketing:meta-ads-ad-accounts
        {--since= : Primer contacto desde este día (AAAA-MM-DD, Bogotá). Por defecto, 90 días atrás}
        {--max=100 : Anuncios como mucho (una petición a Meta por anuncio)}
        {--pause=1 : Segundos entre peticiones}';

    protected $description = 'Diagnóstico de solo lectura: a qué cuenta publicitaria pertenece cada anuncio que trae leads de pauta (MULTI_AD_ACCOUNT_REQUIRED).';

    public function handle(MetaAdsApiClient $client): int
    {
        $problem = $client->configurationProblem();
        if ($problem !== null) {
            $this->error(MetaAdsApiClient::problemMessage($problem));
            $this->line('META_TOKEN_REQUIRED='.($problem === 'missing_access_token' ? 'YES' : 'NO'));

            return self::FAILURE;
        }

        $tz = $client->timezone();
        $sinceRaw = (string) ($this->option('since') ?: CarbonImmutable::now($tz)->subDays(90)->toDateString());
        $since = preg_match('/^\d{4}-\d{2}-\d{2}$/', $sinceRaw) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $sinceRaw, $tz) : false;
        if ($since === false || $since->format('Y-m-d') !== $sinceRaw) {
            $this->error('--since tiene que ser una fecha AAAA-MM-DD.');

            return self::FAILURE;
        }
        $max = max(1, (int) $this->option('max'));
        $pause = max(0, (int) $this->option('pause'));
        $connected = $client->accountIds();
        $isConnected = fn (?string $account): bool => $account !== null && in_array($account, $connected, true);

        $first = LeadUniverse::firstContactSql('l');
        $ads = LeadUniverse::constrain(
            DB::table('marketing_lead_attributions as a')->join('marketing_leads as l', 'l.id', '=', 'a.marketing_lead_id'),
            'l.source',
        )
            ->where('a.first_touch_source_type', MarketingLeadAttribution::SOURCE_AD)
            ->whereNotNull('a.first_touch_ad_id')
            ->whereRaw("{$first} >= ?", [$since->utc()])
            ->groupBy('a.first_touch_ad_id')
            ->selectRaw('a.first_touch_ad_id as ad, count(distinct a.marketing_lead_id) as leads')
            ->orderByDesc('leads')
            ->get();

        if ($ads->isEmpty()) {
            $this->info("No hay leads de pauta con anuncio desde {$sinceRaw}.");
            $this->line('AD_IDS_CHECKED=0');
            $this->line('MULTI_AD_ACCOUNT_REQUIRED=NO');

            return self::SUCCESS;
        }

        $rows = [];
        $stopped = false;
        foreach ($ads->take($max) as $i => $a) {
            if ($i > 0 && $pause > 0) {
                Sleep::for($pause)->seconds();
            }
            $ad = (string) $a->ad;
            try {
                $owner = $client->adOwner($ad);
                $rows[] = ['ad' => $ad, 'leads' => (int) $a->leads, 'account' => $owner['account_id'], 'status' => $owner['effective_status'], 'error' => null];
            } catch (MetaAdsApiException $e) {
                if ($e->category === MetaAdsApiException::RATE_LIMIT) {
                    $stopped = true;
                    $this->warn('Meta pidió frenar (límite de peticiones): se detiene aquí. Repetirlo más tarde.');

                    break;
                }
                // Con el token inválido o caducado, cada petición siguiente fallaría igual y gastaría cupo.
                if ($e->metaCode === 190) {
                    $stopped = true;
                    $this->warn('Meta rechaza el token (190: inválido o caducado): se detiene aquí. Hay que renovarlo.');

                    break;
                }
                $rows[] = ['ad' => $ad, 'leads' => (int) $a->leads, 'account' => null, 'status' => null,
                    'transient' => $e->category === MetaAdsApiException::TRANSIENT,
                    'error' => $e->category.' '.($e->metaCode ?? $e->httpStatus ?? '?').'/'.($e->metaSubcode ?? '-')];
            }
        }

        $this->table(['anuncio', 'leads', 'cuenta publicitaria', 'estado'], array_map(fn (array $r): array => [
            $r['ad'],
            $r['leads'],
            $r['account'] === null
                ? (($r['transient'] ?? false) ? 'error pasajero' : 'sin acceso').' ('.$r['error'].')'
                : $r['account'].($isConnected($r['account']) ? ' (conectada)' : ''),
            $r['status'] ?? '—',
        ], $rows));

        $byAccount = [];
        foreach ($rows as $r) {
            if ($r['account'] !== null) {
                $byAccount[$r['account']]['ads'] = ($byAccount[$r['account']]['ads'] ?? 0) + 1;
                $byAccount[$r['account']]['leads'] = ($byAccount[$r['account']]['leads'] ?? 0) + $r['leads'];
            }
        }
        $unreadable = array_values(array_filter($rows, fn (array $r): bool => $r['account'] === null));
        $pending = $ads->count() - count($rows);
        $complete = $unreadable === [] && ! $stopped && $pending === 0;

        // Las conectadas también están en juego si gastaron en la ventana, aunque ningún anuncio con leads sea suyo.
        $spent = $connected === [] ? [] : DB::table('meta_ad_insights_daily')
            ->whereIn('ad_account_id', $connected)
            ->where('date', '>=', $sinceRaw)
            ->where('spend', '>', 0)
            ->distinct()
            ->pluck('ad_account_id')
            ->map(fn (mixed $a): string => (string) $a)
            ->all();
        $accounts = array_map('strval', array_keys($byAccount));
        foreach ($connected as $account) {
            if (in_array($account, $spent, true) && ! in_array($account, $accounts, true)) {
                $accounts[] = $account;
            }
        }

        $connectedAds = 0;
        $connectedLeads = 0;
        foreach ($byAccount as $account => $n) {
            if ($isConnected((string) $account)) {
                $connectedAds += $n['ads'];
                $connectedLeads += $n['leads'];
            }
        }

        $multi = match (true) {
            count($accounts) >= 2 => 'YES',
            $complete => 'NO',
            default => 'UNKNOWN',
        };
        $connectedHasLeadAds = match (true) {
            $connectedAds > 0 => 'YES',
            $complete => 'NO',
            default => 'UNKNOWN',
        };
        [$theConnected, $inThem] = count($connected) === 1 ? ['la cuenta conectada', 'en ella'] : ['las cuentas conectadas', 'en ellas'];

        $this->line('AD_IDS_CHECKED='.count($rows).' de '.$ads->count());
        $this->line('AD_ACCOUNTS='.implode(',', array_keys($byAccount)));
        $this->line('CONNECTED_ACCOUNTS='.count($connected));
        $this->line('CONNECTED_ACCOUNT_ADS='.$connectedAds.' · leads '.$connectedLeads);
        $this->line('CONNECTED_ACCOUNT_SPEND_SINCE='.($spent !== [] ? 'YES' : 'NO'));
        $this->line('OTHER_ACCOUNT_ADS='.(count($rows) - count($unreadable) - $connectedAds));
        $this->line('UNREADABLE_ADS='.count($unreadable).' · leads '.array_sum(array_column($unreadable, 'leads')));
        $this->line('CONNECTED_ACCOUNT_HAS_LEAD_ADS='.$connectedHasLeadAds);
        $this->line('MULTI_AD_ACCOUNT_REQUIRED='.$multi);
        if ($connectedHasLeadAds === 'NO') {
            $this->line("Ningún anuncio con leads es de {$theConnected}: cambiar de cuenta dejaría fuera del panel el gasto ya sincronizado. Decidir qué cuentas conectar.");
        }
        if ($multi !== 'NO' || $connectedHasLeadAds !== 'YES') {
            $this->line("Nada cambia: el panel sigue resolviendo contra {$theConnected} y avisa de la pauta que no está {$inThem}.");
        }

        return self::SUCCESS;
    }
}
