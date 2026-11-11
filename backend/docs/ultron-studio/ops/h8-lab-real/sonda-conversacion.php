<?php

// SOLO LECTURA (lead, 11-11-2026): qué dice ElevenLabs de las últimas conversaciones del agente de laboratorio de una
// sesión: estado, duración, motivo de terminación y error. No imprime la clave, el prompt ni ids completos.
// Uso (servidor, como www-data): php sonda-conversacion.php <uuid de la sesión del laboratorio>

require '/var/www/api/backend/vendor/autoload.php';
$app = require '/var/www/api/backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$corto = static function (mixed $v, int $n = 300): string {
    $s = is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return mb_strlen((string) $s) > $n ? mb_substr((string) $s, 0, $n).'…('.mb_strlen((string) $s).')' : (string) $s;
};

$sesion = DB::table('ultron_studio_voice_lab_sessions')->where('uuid', $argv[1] ?? '')->first();
if ($sesion === null) {
    echo "sin sesión\n";
    exit(1);
}
$agente = DB::table('ultron_studio_voice_agents')->where('id', $sesion->agent_id)->first();
$id = (string) $agente->provider_agent_id;
echo 'sesión: ', $sesion->status, ' · ', $sesion->end_reason, ' · columnas con datos: ',
    implode(', ', array_keys(array_filter((array) $sesion, static fn ($v) => $v !== null && $v !== ''))), PHP_EOL;
foreach ((array) $sesion as $k => $v) {
    if (is_string($v) && ($v[0] ?? '') === '{' && ! str_contains($k, 'url')) {
        echo '   ', $k, ' = ', $corto($v, 900), PHP_EOL;
    }
}

$base = rtrim((string) (config('voice.elevenlabs.base_url') ?: 'https://api.elevenlabs.io'), '/');
$http = static fn () => Http::withHeaders(['xi-api-key' => (string) config('voice.elevenlabs.api_key')])->timeout(25);

$lista = $http()->get($base.'/v1/convai/conversations', ['agent_id' => $id, 'page_size' => 5]);
echo 'conversaciones del agente …', substr($id, -4), ': http=', $lista->status(), PHP_EOL;
$filas = (array) ($lista->json('conversations') ?? []);
foreach ($filas as $c) {
    echo '   …', substr((string) ($c['conversation_id'] ?? ''), -4), ' · status=', $c['status'] ?? '?', ' · duración=', $c['call_duration_secs'] ?? '?',
        ' s · mensajes=', $c['message_count'] ?? '?', ' · éxito=', $c['call_successful'] ?? '?', ' · inicio=', isset($c['start_time_unix_secs']) ? gmdate('H:i:s', (int) $c['start_time_unix_secs']).'Z' : '?', PHP_EOL;
}
if ($filas !== []) {
    $cid = (string) $filas[0]['conversation_id'];
    $d = $http()->get($base.'/v1/convai/conversations/'.$cid);
    $j = (array) $d->json();
    $m = (array) ($j['metadata'] ?? []);
    echo 'detalle …', substr($cid, -4), ': http=', $d->status(), ' · status=', $j['status'] ?? '?', ' · has_audio=', $corto($j['has_audio'] ?? null),
        ' · transcript=', count((array) ($j['transcript'] ?? [])), ' turnos', PHP_EOL;
    foreach (['termination_reason', 'error', 'call_duration_secs', 'cost', 'main_language', 'conversation_initiation_source', 'conversation_initiation_source_version', 'initiator_id', 'text_only', 'warnings', 'authorization_method'] as $k) {
        if (array_key_exists($k, $m)) {
            echo '   metadata.', $k, ' = ', $corto($m[$k]), PHP_EOL;
        }
    }
    echo '   claves de metadata: ', implode(', ', array_keys($m)), PHP_EOL;
    $ini = (array) ($j['conversation_initiation_client_data'] ?? []);
    echo '   datos de iniciación del cliente: claves=', implode(', ', array_keys($ini)), ' · variables=', $corto(array_keys((array) ($ini['dynamic_variables'] ?? []))), ' · overrides=', $corto($ini['conversation_config_override'] ?? null, 400), PHP_EOL;
    foreach ((array) ($j['transcript'] ?? []) as $t) {
        echo '   [', $t['time_in_call_secs'] ?? '?', ' s] ', $t['role'] ?? '?', ': ', $corto($t['message'] ?? null, 200), PHP_EOL;
    }
}

// El agente tal como lo tiene ElevenLabs: formatos de audio, flujo, variables y ajustes de cliente (sin el prompt).
$a = $http()->get($base.'/v1/convai/agents/'.$id);
$cc = (array) ($a->json('conversation_config') ?? []);
echo 'agente …', substr($id, -4), ': http=', $a->status(), PHP_EOL;
echo '   tts.agent_output_audio_format = ', $corto($cc['tts']['agent_output_audio_format'] ?? null), ' · asr.user_input_audio_format = ', $corto($cc['asr']['user_input_audio_format'] ?? null), PHP_EOL;
echo '   conversation = ', $corto($cc['conversation'] ?? null, 500), PHP_EOL;
echo '   turn = ', $corto($cc['turn'] ?? null, 400), PHP_EOL;
echo '   agent.dynamic_variables = ', $corto($cc['agent']['dynamic_variables'] ?? null, 400), PHP_EOL;
echo '   agent.first_message (longitud) = ', mb_strlen((string) ($cc['agent']['first_message'] ?? '')), ' · language = ', $corto($cc['agent']['language'] ?? null), PHP_EOL;
$ps = (array) ($a->json('platform_settings') ?? []);
echo '   platform_settings: claves = ', implode(', ', array_keys($ps)), PHP_EOL;
foreach (['auth', 'overrides', 'call_limits', 'privacy', 'widget'] as $k) {
    if (isset($ps[$k]) && $k !== 'widget') {
        echo '   platform_settings.', $k, ' = ', $corto($ps[$k], 500), PHP_EOL;
    }
}
