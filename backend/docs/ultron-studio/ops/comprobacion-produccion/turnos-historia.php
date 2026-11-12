<?php
// SOLO LECTURA. Historial de los turnos de ULTRON en la conversación canaria: en cada uno, si el texto que salió fue el
// del modelo o uno de respaldo, qué dijo el revisor y las longitudes. Sin textos de la persona.
use Illuminate\Support\Facades\DB;
$id = (int) config('marketing.ultron.canary_conversation_id');
$filas = DB::table('marketing_ai_actions')->where('conversation_id', $id)->where('action_type', 'reply')->orderByDesc('id')->limit(40)->get()->reverse();
echo "turnos con respuesta: ", count($filas), "\n";
$n = ['modelo' => 0, 'respaldo' => 0, 'politica' => 0];
foreach ($filas as $f) {
    $m = json_decode((string) $f->metadata, true) ?: [];
    $sal = DB::table('marketing_messages')->where('id', $m['outbound']['message_id'] ?? 0)->first();
    $lon = $sal ? mb_strlen((string) ($sal->body ?? $sal->text ?? $sal->content ?? '')) : null;
    $c = $m['critic'] ?? [];
    $clase = isset($m['fallback_mode']) ? 'respaldo' : (isset($m['policy_discarded_draft']) ? 'politica' : 'modelo');
    $n[$clase]++;
    echo sprintf("#%d %s · reason=%-22s · intent=%-20s · fase=%-12s · temp=%-6s · crítico=%s/%s intento %s%s · %s%s%s · sale %s car.%s · prompts=%s\n",
        $f->id, substr((string) $f->created_at, 5, 11), $f->reason, $m['intent'] ?? '?', $m['next_phase'] ?? '?', $m['lead_temperature'] ?? '—',
        $c['verdict'] ?? '—', $c['score'] ?? '—', $c['attempt'] ?? '—', isset($c['hard_fail']) && $c['hard_fail'] ? ' hard='.$c['hard_fail'] : '',
        strtoupper($clase), isset($m['fallback_mode']) ? ' ('.$m['fallback_mode'].')' : '', isset($m['policy_violations']) ? ' '.json_encode($m['policy_violations']) : '',
        $lon ?? '?', isset($m['discarded_draft']) ? ' · borrador descartado '.mb_strlen((string) $m['discarded_draft']) : (isset($m['policy_discarded_draft']) ? ' · borrador descartado '.mb_strlen((string) $m['policy_discarded_draft']) : ''),
        $m['studio_prompts']['state'] ?? ($m['studio']['state'] ?? '—'));
}
echo "RESUMEN: ", json_encode($n), "\n";
// Lo que se sabe del prospecto canario para la estrategia (sin datos personales): fase y temperatura guardadas.
$conv = DB::table('marketing_conversations')->where('id', $id)->first();
$a = (array) $conv;
foreach (['commercial_phase', 'phase', 'stage', 'sales_stage'] as $k) { if (array_key_exists($k, $a)) { echo "conversación.$k = ", var_export($a[$k], true), "\n"; } }
$mem = isset($a['memory']) ? json_decode((string) $a['memory'], true) : null;
if (is_array($mem)) { echo "memoria: claves = ", implode(',', array_keys($mem)), "\n"; if (isset($mem['structured'])) { echo "memoria.structured: claves = ", implode(',', array_keys($mem['structured'])), "\n"; } }
