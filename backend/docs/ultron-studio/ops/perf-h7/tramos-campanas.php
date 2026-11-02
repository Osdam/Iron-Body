<?php

/*
 * PERF7: tramos de GET integrations/meta/campaigns (≈ 2× el resto de lecturas del Studio en el banco HTTP), con los
 * datos de la base del banco (`ultron_studio_h7_perf_http`: 3 cuentas, 500 campañas en el espejo, 50 asociaciones
 * activas a 3 versiones). Mide por separado, n = 30 tras calentar:
 *   1. connection()  — el estado de las cuentas (MetaAdsSync::statusAll);
 *   2. campaigns()   — las 500 filas del espejo a DTO y ordenadas (se pagina DESPUÉS, en PHP);
 *   3. la consulta de las asociaciones activas con su versión (incluido `content`);
 *   4. dtos()        — una DTO por asociación activa, con integrityOk() de su versión (huella recalculada);
 *   5. integrityOk() de UNA versión (la huella del contenido), para saber cuánto pesa en 4;
 *   6. campaignsPage() entero, como la ruta.
 * Solo lee. Configura Meta en memoria (sin red: el espejo es local). Uso (desde backend/):
 *   env APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=ultron_studio_h7_perf_http \
 *       DB_USERNAME=ironbody DB_PASSWORD= DB_URL= PGGSSENCMODE=disable php docs/ultron-studio/ops/perf-h7/tramos-campanas.php
 */

use App\Models\UltronStudio\StudioCampaignLink;
use App\Services\UltronStudio\StudioCampaignLinks;
use App\Services\UltronStudio\StudioMetaCampaigns;
use App\Services\UltronStudio\StudioVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$base = dirname(__DIR__, 4);
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
$links = app(StudioCampaignLinks::class);

$medir = static function (string $nombre, callable $f, int $n = 30): void {
    $f();
    $consultas = 0;
    DB::flushQueryLog();
    DB::enableQueryLog();
    $f();
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();
    $t = [];
    for ($i = 0; $i < $n; $i++) {
        $a = hrtime(true);
        $f();
        $t[] = (hrtime(true) - $a) / 1e6;
    }
    sort($t);
    printf("| %s | %d | %.2f | %.2f | %.2f |\n", $nombre, $consultas, $t[intdiv($n, 2)], $t[(int) ceil(0.95 * $n) - 1], end($t));
};

$conexion = $meta->connection();
$filas = $meta->campaigns($conexion['accountStates']);
$activas = StudioCampaignLink::query()->with(['version:id,uuid,label,status,content,content_hash'])->where('status', 'active')
    ->whereIn('campaign_id', array_values(array_unique(array_column($filas, 'entityId'))))->get();
$version = $activas->first(fn ($l) => $l->version !== null)?->version;

printf("campañas en el espejo: %d · asociaciones activas: %d · versiones distintas: %d\n\n", count($filas), $activas->count(), $activas->pluck('version_id')->filter()->unique()->count());
printf("| Tramo | consultas | p50 ms | p95 ms | máx ms |\n|---|---|---|---|---|\n");
$medir('1. connection() (estado de las 3 cuentas)', fn () => $meta->connection());
$medir('2. campaigns() (500 filas → DTO + orden)', fn () => $meta->campaigns($conexion['accountStates']));
$medir('3. asociaciones activas + versión (con content)', fn () => StudioCampaignLink::query()->with(['version:id,uuid,label,status,content,content_hash'])->where('status', 'active')
    ->whereIn('campaign_id', array_values(array_unique(array_column($filas, 'entityId'))))->get());
$medir('4. dtos() de las 50 activas (integrityOk por asociación)', fn () => $links->dtos($activas->map(fn ($l) => $l->withoutRelations())->all()));
$medir('5. integrityOk() de UNA versión', fn () => StudioVersions::integrityOk($version));
$medir('6. campaignsPage() entero (página de 20)', fn () => $links->campaignsPage(['status' => null, 'linked' => null, 'account' => null], 1, 20));
printf("\ncarga: %s\n", implode(' ', array_map(fn ($x) => round($x, 2), sys_getloadavg())));
