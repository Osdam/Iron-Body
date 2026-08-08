<?php

use Illuminate\Support\Env;

/**
 * factus_precheck.php — ¿autentican las credenciales NUEVAS, antes de escribirlas?
 *
 * Se ejecuta con el .env todavía intacto. Toma las credenciales actuales del
 * fichero y sustituye SÓLO las que se van a rotar, cuyo valor llega en ficheros
 * temporales 0600 (por argv viaja la RUTA, jamás el valor: `ps` no ve secretos).
 *
 * Si Factus rechaza la combinación, el rotador aborta sin haber tocado nada.
 * Es lo que separa "rotar" de "romper la facturación y averiguarlo después".
 *
 * Uso: php factus_precheck.php FACTUS_PASSWORD=/ruta/tmp [FACTUS_CLIENT_ID=/ruta …]
 *
 * NO bootea Laravel a propósito: correr el framework como root dejaría ficheros
 * de log de root en storage/logs y www-data no podría volver a escribirlos.
 */
require '/var/www/api/backend/vendor/autoload.php';

$appDir = '/var/www/api/backend';

// Mismo repositorio que usa Laravel: comprobado en este servidor que ante
// claves duplicadas gana la ÚLTIMA ocurrencia, y el .env productivo las tiene.
$repo = Env::getRepository();
Dotenv\Dotenv::create($repo, $appDir)->load();
$env = fn (string $k) => (string) (Env::get($k) ?? '');

$creds = [
    'FACTUS_USERNAME' => $env('FACTUS_USERNAME'),
    'FACTUS_PASSWORD' => $env('FACTUS_PASSWORD'),
    'FACTUS_CLIENT_ID' => $env('FACTUS_CLIENT_ID'),
    'FACTUS_CLIENT_SECRET' => $env('FACTUS_CLIENT_SECRET'),
];
$baseUrl = rtrim($env('FACTUS_BASE_URL') ?: 'https://api.factus.com.co', '/');

$rotating = [];
foreach (array_slice($argv, 1) as $arg) {
    [$key, $path] = array_pad(explode('=', $arg, 2), 2, null);
    if (! isset($creds[$key])) {
        fwrite(STDERR, "  ✘ clave desconocida: {$key}\n");
        exit(2);
    }
    if (! is_string($path) || ! is_file($path)) {
        fwrite(STDERR, "  ✘ no existe el fichero de valor para {$key}\n");
        exit(2);
    }
    $creds[$key] = (string) file_get_contents($path);
    $rotating[] = $key;
}

$fp = fn (string $v) => $v === '' ? '<VACIO>' : 'len='.strlen($v).' sha='.substr(hash('sha256', $v), 0, 8);

foreach ($creds as $k => $v) {
    printf("  %-22s %s%s\n", $k, $fp($v), in_array($k, $rotating, true) ? '   ← NUEVO' : '');
}

$ch = curl_init($baseUrl.'/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'password',
        'client_id' => $creds['FACTUS_CLIENT_ID'],
        'client_secret' => $creds['FACTUS_CLIENT_SECRET'],
        'username' => $creds['FACTUS_USERNAME'],
        'password' => $creds['FACTUS_PASSWORD'],
    ]),
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

echo '  POST '.$baseUrl.'/oauth/token (password grant) → HTTP '.$code.($err ? " [curl: {$err}]" : '').PHP_EOL;

$json = json_decode((string) $body, true);
if ($code !== 200) {
    // Sólo el mensaje del proveedor, recortado: el cuerpo entero puede traer eco
    // de lo enviado.
    $msg = is_array($json) ? (string) ($json['message'] ?? $json['error_description'] ?? $json['error'] ?? '') : '';
    echo '  motivo: '.substr($msg, 0, 160).PHP_EOL;
    echo '  PRECHECK = FAIL'.PHP_EOL;
    exit(1);
}
if (! is_array($json) || empty($json['access_token'])) {
    echo '  respuesta 200 sin access_token'.PHP_EOL;
    echo '  PRECHECK = FAIL'.PHP_EOL;
    exit(1);
}

echo '  access_token emitido (len='.strlen((string) $json['access_token']).', expira en '.((int) ($json['expires_in'] ?? 0)).' s)'.PHP_EOL;
echo '  PRECHECK = PASS'.PHP_EOL;
exit(0);
