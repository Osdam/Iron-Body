<?php
// SOLO LECTURA. Los turnos de la conversación canaria (la del propietario) desde una hora dada, para la repetición de
// la prueba real del apartado 4 del cierre V2: qué versión y qué huella se aplicaron, qué midió el backend contra el
// tope publicado, si el texto que salió fue del modelo o de respaldo, y los datos comerciales que nombra.
// Uso (servidor, como www-data): DESDE='2026-11-18 22:40:00' php artisan tinker --execute="require '/tmp/turnos-a4.php';"
use App\Models\Plan;
use App\Services\Marketing\Ultron\UltronReplyFitter;
use Illuminate\Support\Facades\DB;

$id = (int) config('marketing.ultron.canary_conversation_id');
$desde = getenv('DESDE') ?: '2026-11-18 22:40:00';
$filas = DB::table('marketing_ai_actions')->where('conversation_id', $id)->where('created_at', '>=', $desde)->orderBy('id')->get();
echo 'conversación canaria: #'.$id.' · acciones desde '.$desde.' UTC: '.count($filas)."\n";
$j = static fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
foreach ($filas as $f) {
    $m = json_decode((string) $f->metadata, true) ?: [];
    $ent = DB::table('marketing_messages')->where('id', $m['message_id'] ?? 0)->first();
    $sal = DB::table('marketing_messages')->where('id', $m['outbound']['message_id'] ?? 0)->first();
    $te = $ent ? (string) $ent->body : '';
    $ts = $sal ? (string) $sal->body : '';
    echo "\n================ acción #{$f->id} · {$f->action_type} · {$f->created_at} UTC · estado {$f->status} ================\n";
    echo 'ENTRANTE ('.mb_strlen($te).'): '.$te."\n";
    echo 'SALIENTE ('.mb_strlen($ts).' caracteres; medida del backend sin URL: '.UltronReplyFitter::measure($ts).'): '.$ts."\n";
    foreach (['intent', 'origin', 'source', 'fallback_mode', 'previous_phase', 'next_phase', 'policy_violations', 'tools_requested', 'risk_flags', 'price_source', 'recovered_reply'] as $k) {
        if (array_key_exists($k, $m)) { echo "  $k = ".$j($m[$k])."\n"; }
    }
    echo '  length = '.$j($m['length'] ?? null)."\n";
    echo '  studio (sello de la publicación) = '.$j(array_intersect_key((array) ($m['studio'] ?? []), array_flip(['state', 'seq', 'part', 'version', 'reason'])))."\n";
    $sp = (array) ($m['studio_prompts'] ?? []);
    $of = (array) ($sp['offered'] ?? []); $ob = (array) ($sp['observed'] ?? []);
    echo '  studio_prompts: state='.($sp['state'] ?? '-').' · ofrecida: '.($of['mode'] ?? '-').' '.substr((string) ($of['version'] ?? ''), 0, 8).' '.substr((string) ($of['bundleHash'] ?? ''), 0, 8)
        .' · observada: '.($ob['mode'] ?? '-').' '.substr((string) ($ob['bundleHash'] ?? ''), 0, 8).' flujo '.($ob['flowRev'] ?? '-').' ejecución '.($ob['execution'] ?? '-')."\n";
    if (isset($m['critic'])) {
        $c = (array) $m['critic'];
        echo '  CRITIC: verdict='.($c['verdict'] ?? '?').' · score='.$j($c['score'] ?? null).' · attempt='.$j($c['attempt'] ?? null).' · hard_fail='.$j($c['hard_fail'] ?? null).' · issues='.$j($c['issues'] ?? null)."\n";
    }
    foreach (['discarded_draft', 'policy_discarded_draft'] as $k) {
        if (! empty($m[$k])) { echo "  $k (".mb_strlen((string) $m[$k]).'): '.mb_substr((string) $m[$k], 0, 320)."\n"; }
    }
    if (isset($m['strategy'])) {
        echo '  strategy = '.$j(array_intersect_key((array) $m['strategy'], array_flip(['goal', 'next_best_action', 'required_plan_id', 'recommended_plan_id', 'plan_strategy', 'answer_first', 'tone'])))."\n";
    }
    echo '  plan de la fila: '.$j($f->recommended_plan_id ?? ($m['plan_id'] ?? null))."\n";
}
echo "\n=== El plan que nombra la respuesta comercial, en el catálogo de hoy ===\n";
foreach (Plan::query()->where('name', 'like', '%Valera%')->get() as $p) {
    echo sprintf("#%d %s · \$%s · %d días · activo %s · vendible %s · beneficios: %s\n", $p->id, $p->name, number_format((float) $p->price, 0, ',', '.'), $p->duration_days,
        $p->active ? 'sí' : 'no', $p->sellable ? 'sí' : 'no', $j(array_slice($p->benefitsArray(), 0, 6)));
}
