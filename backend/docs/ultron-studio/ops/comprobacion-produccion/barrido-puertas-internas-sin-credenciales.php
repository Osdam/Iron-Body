<?php
// Unidad 1 (PT-19) · barrido de TODAS las puertas internas SIN credenciales. Se ejecuta en el servidor como www-data:
//     ssh ironbody-vps "sudo -u www-data php" < barrido-puertas-internas-sin-credenciales.php
// SOLO tiene sentido con PT-19 desplegado (desde `d52e46a9`): antes, cada petición de este barrido descontaba del cupo.
// Lee las rutas de api/internal/ con route:list y a cada una le hace UNA petición sin credenciales, con su método
// (los POST, con un cuerpo JSON vacío). Sin secreto ninguna llega a su controlador: no escribe ni dispara nada.
// De cada respuesta imprime el código y si trae X-RateLimit-* (nunca valores de configuración ni cuerpos).
declare(strict_types=1);

const API = 'https://api.ironbodyneiva.cloud/';

$json = shell_exec('cd /var/www/api/backend && php artisan route:list --path=api/internal --json 2>/dev/null');
$rutas = json_decode((string) $json, true);
if (!is_array($rutas) || $rutas === []) {
    fwrite(STDERR, "route:list no devolvió rutas\n");
    exit(2);
}

$total = 0; $bien = 0; $conPuerta = 0; $raras = [];
foreach ($rutas as $r) {
    $uri = (string) ($r['uri'] ?? '');
    if (!str_starts_with($uri, 'api/internal/')) {
        continue;
    }
    $total++;
    $mw = implode(' ', (array) ($r['middleware'] ?? []));
    $puerta = str_contains($mw, 'VerifyInternalAutomationSignature') || str_contains($mw, 'automation.internal');
    $conPuerta += $puerta ? 1 : 0;
    $metodo = explode('|', (string) ($r['method'] ?? 'GET'))[0];
    $url = API . preg_replace('/\{[^}]+\}/', '1', $uri);

    $cab = [];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $linea) use (&$cab): int {
            $p = strpos($linea, ':');
            if ($p !== false) {
                $cab[strtolower(trim(substr($linea, 0, $p)))] = true;
            }

            return strlen($linea);
        },
    ];
    if ($metodo !== 'GET') {
        $opts[CURLOPT_POSTFIELDS] = '{}';
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $cuenta = isset($cab['x-ratelimit-limit']) || isset($cab['x-ratelimit-remaining']);
    $ok = $codigo === 401 && !$cuenta && $puerta;
    $bien += $ok ? 1 : 0;
    if (!$ok) {
        $raras[] = "$metodo $uri";
    }
    printf("  %-5s %-58s → %d · X-RateLimit-*: %s · puerta del secreto: %s\n", $metodo, $uri, $codigo,
        $cuenta ? 'PRESENTES (cuenta)' : 'ausentes', $puerta ? 'sí' : 'NO');
}
printf("  rutas de api/internal/: %d · con la puerta del secreto: %d · 401 sin contar: %d de %d\n", $total, $conPuerta, $bien, $total);
echo $raras === [] ? "  RESULTADO: las $total puertas internas rechazan sin credenciales y ninguna descuenta cupo\n"
    : '  RESULTADO: REVISAR → ' . implode(' · ', $raras) . "\n";
