<?php
// Unidad 1 (PT-19) · sonda del cupo de las puertas internas (n8n → API). Se ejecuta EN EL SERVIDOR como www-data:
//     ssh ironbody-vps "sudo -u www-data php" < sonda-cupo-puertas-internas.php
// Usada antes y después del despliegue del 23-11-2026 (evidencia-h9/2026-11-23/03-…): antes, el cupo bajó 7; después, 1.
// No escribe nada. Llama a GET api/internal/marketing/ai/doctor (solo lee configuración; sin efectos) por el mismo
// camino que n8n (nombre público, TLS, nginx, FPM). El secreto se lee de la caché de configuración DENTRO de este
// proceso y solo viaja en la cabecera Authorization: nunca se imprime ni pasa por la línea de órdenes. De cada respuesta
// se imprime el código, si trae X-RateLimit-* y sus dos números (el tope y lo que queda: no son secretos), y `ok`.
declare(strict_types=1);

const URL_DOCTOR = 'https://api.ironbodyneiva.cloud/api/internal/marketing/ai/doctor';
const SIN_CREDENCIALES = 5;

$cfg = require '/var/www/api/backend/bootstrap/cache/config.php';
$secreto = (string) ($cfg['automation']['internal_secret'] ?? '');
if ($secreto === '') {
    fwrite(STDERR, "no hay secreto interno en la caché de configuración\n");
    exit(2);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "este PHP no tiene la extensión curl\n");
    exit(2);
}

/** @return array{codigo:int, tope:?string, queda:?string, ok:mixed, error:int} */
function pide(?string $bearer): array
{
    $cab = [];
    $h = ['Accept: application/json'];
    if ($bearer !== null) {
        $h[] = 'Authorization: Bearer ' . $bearer;
    }
    $ch = curl_init(URL_DOCTOR);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $linea) use (&$cab): int {
            $p = strpos($linea, ':');
            if ($p !== false) {
                $cab[strtolower(trim(substr($linea, 0, $p)))] = trim(substr($linea, $p + 1));
            }

            return strlen($linea);
        },
    ]);
    $cuerpo = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($ch);
    curl_close($ch);
    $j = is_string($cuerpo) ? json_decode($cuerpo, true) : null;

    return [
        'codigo' => $codigo,
        'tope' => $cab['x-ratelimit-limit'] ?? null,
        'queda' => $cab['x-ratelimit-remaining'] ?? null,
        'ok' => is_array($j) ? ($j['ok'] ?? ($j['success'] ?? null)) : null,
        'error' => $error,
    ];
}

function linea(string $que, array $r): void
{
    $cupo = $r['tope'] === null && $r['queda'] === null
        ? 'X-RateLimit-*: AUSENTES (no cuenta)'
        : 'X-RateLimit-*: presentes · tope ' . ($r['tope'] ?? '?') . ' · quedan ' . ($r['queda'] ?? '?');
    printf("  %s UTC · %-34s → %d · %s%s%s\n", gmdate('H:i:s'), $que, $r['codigo'], $cupo,
        $r['ok'] === null ? '' : ' · ok=' . var_export($r['ok'], true),
        $r['error'] !== 0 ? ' · error de red ' . $r['error'] : '');
}

$a = pide($secreto);
linea('CON el secreto (como n8n)', $a);
for ($i = 1; $i <= SIN_CREDENCIALES; $i++) {
    linea("SIN credenciales ($i de " . SIN_CREDENCIALES . ')', pide(null));
}
linea('con un secreto FALSO', pide(bin2hex(random_bytes(32))));
$b = pide($secreto);
linea('CON el secreto (como n8n)', $b);

$n = SIN_CREDENCIALES + 1;
if ($a['codigo'] === 200 && $b['codigo'] === 200 && is_numeric($a['queda']) && is_numeric($b['queda'])) {
    $gastado = (int) $a['queda'] - (int) $b['queda'];
    printf("  entre las dos peticiones con secreto hubo %d sin él: el cupo de n8n bajó %d (1 = solo la suya; %d = las %d anónimas también contaron)\n",
        $n, $gastado, $n + 1, $n);
    echo $gastado === 1 ? "  RESULTADO: las peticiones sin secreto NO gastan el cupo de n8n\n"
        : ($gastado === $n + 1 ? "  RESULTADO: las peticiones sin secreto SÍ gastan el cupo de n8n\n"
            : "  RESULTADO: no concluyente (otra llamada de n8n o un cambio de ventana en medio): repetir\n");
} else {
    echo "  RESULTADO: no se pudo medir (alguna petición con secreto no dio 200)\n";
}
