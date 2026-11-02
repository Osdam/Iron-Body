<?php

/*
 * F-2(b): cuánto de StudioMetaCampaigns::campaigns() (500 filas) es la DTO de cada fila y cuánto la consulta y el orden.
 * Solo lee la base del banco de PERF7. Uso (desde backend/):
 *   env APP_ENV=testing DB_CONNECTION=pgsql ... DB_DATABASE=ultron_studio_h7_perf_http php <este fichero>
 */

use App\Models\MetaAdEntity;
use App\Services\UltronStudio\StudioCampaignLinks;
use App\Services\UltronStudio\StudioMetaCampaigns;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$base = getcwd();
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'ultron_studio_h7_perf_http') {
    fwrite(STDERR, "base no permitida\n");
    exit(2);
}
Http::preventStrayRequests();
config([
    'meta.ads.access_token' => 'perf-h7-centinela-sin-red',
    'meta.ads.ad_account_ids' => ['1000000000004714', '1000000000009023', '1000000000005856'],
    'meta.ads.ad_account_id' => null,
    'meta.ads.sync_enabled' => true,
    'meta.ads.stale_after_minutes' => 180,
]);

$meta = app(StudioMetaCampaigns::class);
$medir = static function (string $nombre, callable $f, int $n = 30): void {
    $f();
    $t = [];
    for ($i = 0; $i < $n; $i++) {
        $a = hrtime(true);
        $f();
        $t[] = (hrtime(true) - $a) / 1e6;
    }
    sort($t);
    printf("| %s | %.2f | %.2f | %.2f |\n", $nombre, $t[intdiv($n, 2)], $t[(int) ceil(0.95 * $n) - 1], end($t));
};

$conexion = $meta->connection();
$filas = $meta->campaigns($conexion['accountStates']);
$entidades = MetaAdEntity::forConfiguredAccount()->where('level', MetaAdEntity::LEVEL_CAMPAIGN)->get(['ad_account_id', 'entity_id', 'name', 'effective_status', 'synced_at']);
$cuentas = array_values(array_unique($entidades->map(fn ($e) => (string) $e->ad_account_id)->all()));
$nombres = MetaAdEntity::accountNames($cuentas);
$publicas = [];
foreach ($cuentas as $c) {
    $publicas[$c] = MetaAdEntity::publicAccount($c, $nombres[$c] ?? null);
}
$referencias = StudioMetaCampaigns::referenceRuns($cuentas);
$ahora = CarbonImmutable::now();

printf("filas: %d\n\n| Tramo | p50 ms | p95 ms | máx ms |\n|---|---|---|---|\n", count($filas));
$medir('campaigns() entero', fn () => $meta->campaigns($conexion['accountStates']));
$medir('solo la consulta del espejo (500 modelos)', fn () => MetaAdEntity::forConfiguredAccount()->where('level', MetaAdEntity::LEVEL_CAMPAIGN)->get(['ad_account_id', 'entity_id', 'name', 'effective_status', 'synced_at']));
$medir('500 × (synced_at → CarbonImmutable::instance + campaignDto()); lo primero sigue antes de paginar', function () use ($entidades, $publicas, $conexion, $referencias, $ahora) {
    foreach ($entidades as $e) {
        $c = (string) $e->ad_account_id;
        $s = $e->synced_at !== null ? CarbonImmutable::instance($e->synced_at) : null;
        StudioMetaCampaigns::campaignDto((string) $e->entity_id, $e->name, $e->effective_status, $s, $publicas[$c], $conexion['accountStates'][$publicas[$c]['id']] ?? null, $referencias[$c] ?? null, $ahora);
    }
});
$medir('sort() de 500 filas', fn () => StudioMetaCampaigns::sort($filas));
$links = app(StudioCampaignLinks::class);
$medir('campaignsPage() entero (página de 20)', fn () => $links->campaignsPage(['status' => null, 'linked' => null, 'account' => null], 1, 20));
printf("\ncarga: %s\n", implode(' ', array_map(fn ($x) => round($x, 2), sys_getloadavg())));
