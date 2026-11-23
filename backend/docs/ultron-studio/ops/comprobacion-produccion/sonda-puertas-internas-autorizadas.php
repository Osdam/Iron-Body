<?php
// Unidad 1 (PT-19) · las peticiones AUTORIZADAS siguen entrando por los tres grupos de puertas internas. Se ejecuta EN EL
// SERVIDOR como www-data:   ssh ironbody-vps "sudo -u www-data php" < sonda-puertas-internas-autorizadas.php
// Tres peticiones CON el secreto, por el mismo camino que n8n (nombre público, TLS, nginx, FPM), una por grupo:
//   · GET  internal/marketing/ai/doctor        (cubo del asesor, 120 por minuto): solo lee configuración.
//   · POST internal/automation/notify-member   (cubo de las notificaciones, 600 por minuto) con el cuerpo «{}»: pasa la
//     puerta y el limitador y la PRIMERA línea del controlador (la validación) la rechaza con 422. No consulta la base
//     ni crea nada: los campos obligatorios faltan y sus reglas no leen tablas.
//   · GET  internal/marketing/context/999999999 (el tercer grupo: ai-action, send-message, human-takeover, followups y
//     context, con su throttle de 120 por minuto por origen): un prospecto que no existe. Pasa la puerta y el limitador y
//     el controlador responde 404 tras UNA lectura que no encuentra nada. (knowledge/doctor, que se probó en la primera
//     pasada, está en el cubo del asesor y no en este grupo: por eso su contador siguió al de ai/doctor.)
// No escribe nada. El secreto se lee de la caché de configuración DENTRO de este proceso y solo viaja en la cabecera
// Authorization: nunca se imprime ni pasa por la línea de órdenes. De cada respuesta: el código y los dos números de
// X-RateLimit-* (no son secretos). Ningún cuerpo.
declare(strict_types=1);

const API = 'https://api.ironbodyneiva.cloud/api/internal/';

$cfg = require '/var/www/api/backend/bootstrap/cache/config.php';
$secreto = (string) ($cfg['automation']['internal_secret'] ?? '');
if ($secreto === '' || !function_exists('curl_init')) {
    fwrite(STDERR, "sin secreto en la caché de configuración o sin la extensión curl\n");
    exit(2);
}

$casos = [
    ['GET', 'marketing/ai/doctor', null, 200, 'el asesor (decide, commit…)'],
    ['POST', 'automation/notify-member', '{}', 422, 'las notificaciones a socios'],
    ['GET', 'marketing/context/999999999', null, 404, 'el tercer grupo (send-message, followups, context)'],
];
$bien = 0;
foreach ($casos as [$metodo, $ruta, $cuerpo, $esperado, $grupo]) {
    $cab = [];
    $ch = curl_init(API . $ruta);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $secreto],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $linea) use (&$cab): int {
            $p = strpos($linea, ':');
            if ($p !== false) {
                $cab[strtolower(trim(substr($linea, 0, $p)))] = trim(substr($linea, $p + 1));
            }

            return strlen($linea);
        },
    ];
    if ($cuerpo !== null) {
        $opts[CURLOPT_POSTFIELDS] = $cuerpo;
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $ok = $codigo === $esperado && isset($cab['x-ratelimit-limit']);
    $bien += $ok ? 1 : 0;
    printf("  %s UTC · CON el secreto · %-4s %-28s → %d (esperado %d) · tope %s · quedan %s · %s%s\n", gmdate('H:i:s'), $metodo, $ruta,
        $codigo, $esperado, $cab['x-ratelimit-limit'] ?? 'sin cabecera', $cab['x-ratelimit-remaining'] ?? 'sin cabecera', $grupo,
        $ok ? '' : '  ← REVISAR');
}
echo $bien === count($casos)
    ? "  RESULTADO: con el secreto, los tres grupos pasan la puerta y el limitador y llegan a su controlador, con su tope de siempre\n"
    : "  RESULTADO: REVISAR ($bien de " . count($casos) . ")\n";
