<?php

use Illuminate\Support\Env;

/**
 * meta_preflight.php — qué dice Meta de las credenciales que hay configuradas.
 *
 * Todo son GET a Graph. No envía mensajes, no registra números, no suscribe
 * webhooks: sirve para saber, ANTES de tocar el portal, si lo que hay en el
 * .env es utilizable o es un resto de la etapa de BSP/coexistencia.
 *
 * Responde a cuatro preguntas que el runbook daba por sabidas:
 *   1. ¿El token es válido, de qué app es y cuándo caduca?
 *   2. ¿El phone_number_id existe y a qué número corresponde?
 *   3. ¿El WABA es el que creemos y qué números cuelgan de él?
 *   4. ¿Qué campos de webhook están suscritos hoy?
 *
 * No imprime tokens ni secretos: sólo longitud y sha256 truncado.
 */
require '/var/www/api/backend/vendor/autoload.php';

$appDir = '/var/www/api/backend';
$repo = Env::getRepository();
Dotenv\Dotenv::create($repo, $appDir)->load();
$env = fn (string $k, string $d = '') => (string) (Env::get($k) ?? $d);

$token = $env('META_ACCESS_TOKEN');
$appId = $env('META_APP_ID');
$appSecret = $env('META_APP_SECRET');
$waba = $env('META_WHATSAPP_BUSINESS_ACCOUNT_ID');
$phoneId = $env('META_WHATSAPP_PHONE_NUMBER_ID');
$version = $env('META_GRAPH_VERSION', 'v21.0');
$display = $env('WHATSAPP_DISPLAY_PHONE');

$fp = fn (string $v) => $v === '' ? '<VACIO>' : 'len='.strlen($v).' sha='.substr(hash('sha256', $v), 0, 8);
$line = fn () => str_repeat('─', 68);

echo 'graph_version            : '.$version.PHP_EOL;
echo 'META_APP_ID              : '.($appId ?: '<VACIO>').PHP_EOL;
echo 'META_APP_SECRET          : '.$fp($appSecret).PHP_EOL;
echo 'META_ACCESS_TOKEN        : '.$fp($token).PHP_EOL;
echo 'WABA configurado         : '.($waba ?: '<VACIO>').PHP_EOL;
echo 'phone_number_id          : '.($phoneId ?: '<VACIO>').PHP_EOL;
echo 'WHATSAPP_DISPLAY_PHONE   : '.($display ?: '<VACIO>').PHP_EOL;
echo $line().PHP_EOL;

if ($token === '') {
    echo 'Sin META_ACCESS_TOKEN no hay nada que preguntar a Graph.'.PHP_EOL;
    exit(1);
}

$get = function (string $path, array $query = []) use ($token, $version): array {
    $url = 'https://graph.facebook.com/'.$version.'/'.ltrim($path, '/');
    if ($query !== []) {
        $url .= '?'.http_build_query($query);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer '.$token],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, json_decode((string) $body, true) ?: []];
};

$fail = 0;
$report = function (string $label, int $code, array $body, ?callable $onOk = null) use (&$fail) {
    if ($code === 200) {
        echo '  ✔ '.$label.PHP_EOL;
        if ($onOk) {
            $onOk($body);
        }

        return true;
    }
    $fail++;
    $msg = $body['error']['message'] ?? '';
    $type = $body['error']['type'] ?? '';
    $sub = $body['error']['error_subcode'] ?? '';
    echo '  ✘ '.$label.' → HTTP '.$code.'  '.$type.($sub ? " ({$sub})" : '').': '.substr((string) $msg, 0, 160).PHP_EOL;

    return false;
};

// 1. El token: de qué app es, qué permisos trae y hasta cuándo vale.
echo 'TOKEN'.PHP_EOL;
if ($appId !== '' && $appSecret !== '') {
    [$c, $b] = $get('debug_token', [
        'input_token' => $token,
        'access_token' => $appId.'|'.$appSecret,
    ]);
    $report('debug_token', $c, $b, function (array $b) use ($appId) {
        $d = $b['data'] ?? [];
        $exp = (int) ($d['expires_at'] ?? 0);
        printf("      app_id=%s%s  tipo=%s  válido=%s\n",
            (string) ($d['app_id'] ?? '?'),
            ((string) ($d['app_id'] ?? '') === $appId ? ' (coincide con META_APP_ID)' : ' ⚠ NO coincide con META_APP_ID'),
            (string) ($d['type'] ?? '?'),
            var_export($d['is_valid'] ?? null, true),
        );
        printf("      caduca=%s\n", $exp === 0 ? 'nunca (token permanente)' : date('c', $exp));
        printf("      scopes=%s\n", implode(',', (array) ($d['scopes'] ?? [])));
        if (! empty($d['granular_scopes'])) {
            foreach ($d['granular_scopes'] as $g) {
                printf("      · %s → %s\n", $g['scope'] ?? '?', implode(',', (array) ($g['target_ids'] ?? [])));
            }
        }
    });
} else {
    echo '  · sin META_APP_ID/META_APP_SECRET no se puede inspeccionar el token'.PHP_EOL;
}

// 2. El número.
echo PHP_EOL.'NÚMERO'.PHP_EOL;
if ($phoneId !== '') {
    [$c, $b] = $get($phoneId, ['fields' => 'id,display_phone_number,verified_name,quality_rating,name_status,code_verification_status,platform_type']);
    $report('phone_number_id '.$phoneId, $c, $b, function (array $b) use ($display) {
        printf("      número=%s  nombre=%s\n", (string) ($b['display_phone_number'] ?? '?'), (string) ($b['verified_name'] ?? '?'));
        printf("      calidad=%s  estado_nombre=%s  verificación=%s  plataforma=%s\n",
            (string) ($b['quality_rating'] ?? '?'), (string) ($b['name_status'] ?? '?'),
            (string) ($b['code_verification_status'] ?? '?'), (string) ($b['platform_type'] ?? '?'));
        $real = preg_replace('/\D/', '', (string) ($b['display_phone_number'] ?? ''));
        $want = preg_replace('/\D/', '', $display);
        if ($want !== '' && $real !== '' && ! str_ends_with($real, substr($want, -10))) {
            echo '      ⚠ no coincide con WHATSAPP_DISPLAY_PHONE'.PHP_EOL;
        }
    });
} else {
    echo '  · sin phone_number_id'.PHP_EOL;
}

// 3. El WABA y los números que cuelgan de él.
echo PHP_EOL.'CUENTA DE WHATSAPP (WABA)'.PHP_EOL;
if ($waba !== '') {
    [$c, $b] = $get($waba, ['fields' => 'id,name,timezone_id,message_template_namespace,account_review_status']);
    $report('WABA '.$waba, $c, $b, function (array $b) {
        printf("      nombre=%s  revisión=%s\n", (string) ($b['name'] ?? '?'), (string) ($b['account_review_status'] ?? '?'));
    });

    [$c2, $b2] = $get($waba.'/phone_numbers', ['fields' => 'id,display_phone_number,verified_name,code_verification_status,quality_rating']);
    $report('números del WABA', $c2, $b2, function (array $b) {
        foreach (($b['data'] ?? []) as $n) {
            printf("      · id=%s  %s  «%s»  verificación=%s  calidad=%s\n",
                (string) ($n['id'] ?? '?'), (string) ($n['display_phone_number'] ?? '?'),
                (string) ($n['verified_name'] ?? '?'), (string) ($n['code_verification_status'] ?? '?'),
                (string) ($n['quality_rating'] ?? '?'));
        }
        if (($b['data'] ?? []) === []) {
            echo '      (ninguno: el WABA no tiene números dados de alta)'.PHP_EOL;
        }
    });

    [$c3, $b3] = $get($waba.'/subscribed_apps');
    $report('apps suscritas al WABA', $c3, $b3, function (array $b) {
        foreach (($b['data'] ?? []) as $a) {
            $app = $a['whatsapp_business_api_data'] ?? [];
            printf("      · app=%s (%s)\n", (string) ($app['name'] ?? '?'), (string) ($app['id'] ?? '?'));
        }
        if (($b['data'] ?? []) === []) {
            echo '      (ninguna: sin app suscrita NO llega ni un webhook)'.PHP_EOL;
        }
    });
} else {
    echo '  · sin WABA configurado'.PHP_EOL;
}

echo PHP_EOL.str_repeat('═', 68).PHP_EOL;
if ($fail === 0) {
    echo 'META PREFLIGHT = PASS (las credenciales configuradas responden en Graph)'.PHP_EOL;
    exit(0);
}
echo 'META PREFLIGHT = '.$fail.' COMPROBACIÓN(ES) EN ROJO'.PHP_EOL;
echo 'Esto NO bloquea la preparación: significa que la configuración actual no'.PHP_EOL;
echo 'sirve y hay que sustituirla en la activación, que es justo lo previsto.'.PHP_EOL;
exit(1);
