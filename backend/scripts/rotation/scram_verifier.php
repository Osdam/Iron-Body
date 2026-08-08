<?php

/**
 * scram_verifier.php — convierte una contraseña en el verificador SCRAM-SHA-256
 * que PostgreSQL guarda en `pg_authid.rolpassword`.
 *
 * Se usa para que `ALTER ROLE … PASSWORD …` reciba YA el verificador y la
 * contraseña en claro no llegue nunca al servidor de base de datos: ni por el
 * socket, ni al log si alguien sube `log_statement`, ni al historial de psql.
 * PostgreSQL acepta el verificador tal cual y lo almacena sin volver a
 * derivarlo, así que el resultado es idéntico a haberla enviado en claro.
 *
 * Formato (documentado por PostgreSQL):
 *   SCRAM-SHA-256$<iteraciones>:<sal_b64>$<StoredKey_b64>:<ServerKey_b64>
 *
 * Uso: php scram_verifier.php /ruta/al/fichero/con/la/password
 *      (la contraseña llega por FICHERO, nunca por argv: `ps` no la ve)
 */
$path = $argv[1] ?? '';
if ($path === '' || ! is_file($path)) {
    fwrite(STDERR, "uso: scram_verifier.php <fichero-con-la-password>\n");
    exit(2);
}

$password = (string) file_get_contents($path);
if ($password === '') {
    fwrite(STDERR, "la contraseña está vacía\n");
    exit(2);
}

$iterations = 4096;
$salt = random_bytes(16);

$saltedPassword = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
$clientKey = hash_hmac('sha256', 'Client Key', $saltedPassword, true);
$storedKey = hash('sha256', $clientKey, true);
$serverKey = hash_hmac('sha256', 'Server Key', $saltedPassword, true);

printf(
    'SCRAM-SHA-256$%d:%s$%s:%s',
    $iterations,
    base64_encode($salt),
    base64_encode($storedKey),
    base64_encode($serverKey),
);
