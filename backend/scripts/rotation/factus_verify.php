<?php

/**
 * factus_verify.php — verificación de la autenticación Factus DESDE CERO.
 *
 * El requisito que resuelve: que la autenticación nueva NO parezca funcionar
 * porque queda un refresh_token viejo en cache. FactusTokenManager intenta
 * `grant_type=refresh_token` ANTES que `password`, así que mientras el refresh
 * anterior siga vivo (TTL 14 días) una credencial equivocada pasaría por buena.
 *
 * Por eso el orden es: purgar los dos tokens derivados → pedir token por el
 * camino real de la aplicación (que ahora sólo puede ser password grant) →
 * consultar un endpoint NO FISCAL.
 *
 * Modos:
 *   --inspect   sólo informa qué tokens derivados hay en cache
 *   --purge     borra access_token y refresh_token cacheados
 *   --verify    purga, autentica desde cero y consulta /v2/numbering-ranges
 *
 * Nunca imprime credenciales ni tokens: sólo longitud y sha256 truncado.
 */
use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\Factus\FactusTokenManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

// Los `use` van ANTES del require a propósito: un formateador automático que
// los reordene por debajo del bootstrap deja `Kernel::class` sin resolver y el
// script muere con «Target class [Kernel] does not exist».
require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '--verify';
$env = (string) config('billing.env');
$keys = [
    'access_token' => 'billing.factus.token.'.$env,
    'refresh_token' => 'billing.factus.refresh.'.$env,
];

$line = fn () => str_repeat('─', 62);
$fp = function ($v) {
    if (! is_string($v) || $v === '') {
        return '<AUSENTE>';
    }

    return 'len='.strlen($v).' sha='.substr(hash('sha256', $v), 0, 8);
};

echo 'ambiente Factus : '.$env.PHP_EOL;
echo 'base_url        : '.config('billing.base_url').PHP_EOL;
echo 'cache store     : '.config('cache.default').PHP_EOL;
echo $line().PHP_EOL;

echo 'TOKENS DERIVADOS EN CACHE (antes):'.PHP_EOL;
foreach ($keys as $label => $key) {
    echo '  '.str_pad($label, 15).': '.$fp(Cache::get($key)).PHP_EOL;
}

if ($mode === '--inspect') {
    exit(0);
}

echo $line().PHP_EOL;
foreach ($keys as $label => $key) {
    Cache::forget($key);
}
$residual = 0;
foreach ($keys as $label => $key) {
    $still = Cache::get($key);
    $gone = ! is_string($still) || $still === '';
    echo '  purga '.str_pad($label, 15).': '.($gone ? 'OK (ausente)' : 'FALLÓ (sigue presente)').PHP_EOL;
    $residual += $gone ? 0 : 1;
}
if ($residual > 0) {
    echo 'FACTUS VERIFY = FAIL (tokens derivados no se pudieron purgar)'.PHP_EOL;
    exit(1);
}

if ($mode === '--purge') {
    echo 'Tokens derivados eliminados. La próxima llamada hará password grant.'.PHP_EOL;
    exit(0);
}

echo $line().PHP_EOL;
echo 'AUTENTICACIÓN DESDE CERO (sólo puede ser password grant):'.PHP_EOL;
try {
    $token = FactusTokenManager::fromConfig()->accessToken();
} catch (Throwable $e) {
    echo '  ✘ '.$e->getMessage().PHP_EOL;
    echo 'FACTUS VERIFY = FAIL'.PHP_EOL;
    exit(1);
}
echo '  access_token obtenido : '.$fp($token).PHP_EOL;

$newRefresh = Cache::get($keys['refresh_token']);
echo '  refresh_token nuevo   : '.$fp($newRefresh).PHP_EOL;
if (! is_string($newRefresh) || $newRefresh === '') {
    echo '  ⚠ no se cacheó refresh nuevo (Factus no lo devolvió en este grant)'.PHP_EOL;
}

echo $line().PHP_EOL;
echo 'CONSULTA NO FISCAL — GET /v2/numbering-ranges:'.PHP_EOL;
$res = FactusClient::make()->getNumberingRanges();
echo '  HTTP '.$res['status'].'  ok='.var_export($res['ok'], true).'  clase='.$res['error_class'].PHP_EOL;

// La respuesta viene envuelta dos veces: body.data.data son las filas y
// body.data.pagination el paginador. Leer body.data directamente hacía pasar el
// paginador por un rango ("desde=1 hasta=2") y daba los rangos por no visibles.
$rows = $res['body']['data']['data'] ?? [];
$ranges = 0;
if (is_array($rows)) {
    foreach ($rows as $r) {
        if (! is_array($r)) {
            continue;
        }
        $ranges++;
        // `technical_key` NO se imprime: es la llave técnica de la resolución
        // DIAN y participa en el cálculo del CUFE.
        printf("  · id=%s  documento=%-16s prefijo=%-5s rango=%s-%s  actual=%s  activo=%s  vencido=%s\n",
            $r['id'] ?? '?',
            (string) ($r['document'] ?? '?'),
            (string) ($r['prefix'] ?? '?'),
            $r['from'] ?? '—', $r['to'] ?? '—', $r['current'] ?? '?',
            var_export($r['is_active'] ?? null, true),
            var_export($r['is_expired'] ?? null, true),
        );
    }
}
echo '  rangos devueltos: '.$ranges.PHP_EOL;

$expected = [
    'factura' => (string) config('billing.numbering.range_id'),
    'nota crédito' => (string) config('billing.numbering.credit_range_id'),
];
$seen = [];
$consecutivos = [];
foreach (($rows ?: []) as $r) {
    if (is_array($r) && isset($r['id'])) {
        $seen[] = (string) $r['id'];
        $consecutivos[(string) $r['id']] = ['current' => $r['current'] ?? null, 'updated_at' => $r['updated_at'] ?? null];
    }
}
foreach ($expected as $label => $id) {
    $hit = in_array($id, $seen, true);
    echo '  rango '.str_pad($label, 13).' ('.$id.'): '.($hit ? 'VISIBLE en la cuenta' : 'NO visible');
    if ($hit) {
        // Testigo antifraude del procedimiento: si una rotación consumiera un
        // consecutivo, este número lo delataría sin tener que mirar la DIAN.
        echo '  · consecutivo actual='.var_export($consecutivos[$id]['current'], true)
            .'  (últ. cambio '.(string) $consecutivos[$id]['updated_at'].')';
    }
    echo PHP_EOL;
}

echo str_repeat('═', 62).PHP_EOL;
$pass = ($res['ok'] === true) && $ranges > 0;
echo $pass ? 'FACTUS VERIFY = PASS' : 'FACTUS VERIFY = FAIL';
echo PHP_EOL;
exit($pass ? 0 : 1);
