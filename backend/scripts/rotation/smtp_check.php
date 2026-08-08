<?php

use Illuminate\Support\Env;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * smtp_check.php — ¿autentica esta credencial SMTP contra el proveedor?
 *
 * Abre una conexión real y hace AUTH. No manda ningún correo: comprobar que el
 * buzón funciona no puede consistir en escribirle a nadie.
 *
 * Los valores candidatos llegan en FICHEROS (por argv viaja la ruta, nunca el
 * valor). Sin argumentos comprueba la credencial que hay ahora en el .env, que
 * es como se toma la foto de "antes".
 *
 * Uso:
 *   php smtp_check.php                                   (credencial actual)
 *   php smtp_check.php MAIL_PASSWORD=/ruta/tmp           (candidata)
 *   php smtp_check.php --send=destino@dominio            (envío controlado)
 *
 * No bootea Laravel: como root dejaría logs de root en storage/logs.
 */
require '/var/www/api/backend/vendor/autoload.php';

$appDir = '/var/www/api/backend';

$repo = Env::getRepository();
Dotenv\Dotenv::create($repo, $appDir)->load();
$env = fn (string $k, string $d = '') => (string) (Env::get($k) ?? $d);

$cfg = [
    'MAIL_HOST' => $env('MAIL_HOST'),
    'MAIL_PORT' => (int) $env('MAIL_PORT', '587'),
    'MAIL_ENCRYPTION' => $env('MAIL_ENCRYPTION'),
    'MAIL_USERNAME' => $env('MAIL_USERNAME'),
    'MAIL_PASSWORD' => $env('MAIL_PASSWORD'),
    'MAIL_FROM_ADDRESS' => $env('MAIL_FROM_ADDRESS'),
];

$sendTo = null;
$rotating = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--send=')) {
        $sendTo = substr($arg, 7);

        continue;
    }
    [$key, $path] = array_pad(explode('=', $arg, 2), 2, null);
    if (! array_key_exists($key, $cfg)) {
        fwrite(STDERR, "  ✘ clave desconocida: {$key}\n");
        exit(2);
    }
    if (! is_string($path) || ! is_file($path)) {
        fwrite(STDERR, "  ✘ no existe el fichero de valor para {$key}\n");
        exit(2);
    }
    $cfg[$key] = (string) file_get_contents($path);
    $rotating[] = $key;
}

$fp = fn (string $v) => $v === '' ? '<VACIO>' : 'len='.strlen($v).' sha='.substr(hash('sha256', $v), 0, 8);

foreach ($cfg as $k => $v) {
    $secret = in_array($k, ['MAIL_PASSWORD', 'MAIL_USERNAME'], true);
    printf("  %-20s %s%s\n", $k, $secret ? $fp((string) $v) : (string) $v,
        in_array($k, $rotating, true) ? '   ← NUEVA' : '');
}

// El puerto 465 es SMTPS (TLS desde el primer byte); el 587 es STARTTLS.
// Pasarle `tls: true` al 587 —o `false` al 465— es la forma más común de que
// «no funcione el correo» sin ningún mensaje que lo explique.
$implicitTls = $cfg['MAIL_PORT'] === 465 || strtolower($cfg['MAIL_ENCRYPTION']) === 'ssl';

$transport = new EsmtpTransport(
    $cfg['MAIL_HOST'],
    $cfg['MAIL_PORT'],
    $implicitTls,
);
$transport->setUsername($cfg['MAIL_USERNAME']);
$transport->setPassword($cfg['MAIL_PASSWORD']);

printf("  conexión: %s:%d  TLS %s\n", $cfg['MAIL_HOST'], $cfg['MAIL_PORT'], $implicitTls ? 'implícito (SMTPS)' : 'STARTTLS');

try {
    $transport->start();
} catch (Throwable $e) {
    echo '  ✘ AUTH falló: '.substr($e->getMessage(), 0, 200).PHP_EOL;
    echo '  SMTP CHECK = FAIL'.PHP_EOL;
    exit(1);
}

echo '  ✔ conexión y AUTH correctos'.PHP_EOL;

if ($sendTo !== null) {
    if (! filter_var($sendTo, FILTER_VALIDATE_EMAIL)) {
        echo '  ✘ destino inválido'.PHP_EOL;
        exit(2);
    }

    $email = (new Email)
        ->from($cfg['MAIL_FROM_ADDRESS'])
        ->to($sendTo)
        ->subject('Iron Body · verificación de credencial SMTP')
        ->text(
            "Correo de verificación tras rotar la credencial SMTP.\n\n".
            'Emitido: '.date('c')."\n".
            "No requiere respuesta y no forma parte de ninguna campaña.\n"
        );

    try {
        $transport->send($email);
        echo '  ✔ correo de verificación enviado a '.$sendTo.PHP_EOL;
    } catch (Throwable $e) {
        echo '  ✘ el envío falló: '.substr($e->getMessage(), 0, 200).PHP_EOL;
        echo '  SMTP CHECK = FAIL'.PHP_EOL;
        exit(1);
    }
}

$transport->stop();
echo '  SMTP CHECK = PASS'.PHP_EOL;
exit(0);
