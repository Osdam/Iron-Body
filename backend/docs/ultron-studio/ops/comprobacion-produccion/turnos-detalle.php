<?php
// SOLO LECTURA. El detalle de los turnos de prueba de la conversación canaria (la del propietario): qué decidió la
// estrategia, qué dijo el revisor, qué borrador se descartó y qué texto salió, con sus longitudes.
use Illuminate\Support\Facades\DB;
$id = (int) config('marketing.ultron.canary_conversation_id');
$desde = $argv[1] ?? '2026-11-12 21:50:00';
$filas = DB::table('marketing_ai_actions')->where('conversation_id', $id)->where('created_at', '>=', '2026-11-12 21:50:00')->where('action_type', 'reply')->orderBy('id')->get();
foreach ($filas as $f) {
    $m = json_decode((string) $f->metadata, true) ?: [];
    $ent = DB::table('marketing_messages')->where('id', $m['message_id'] ?? 0)->first();
    $sal = DB::table('marketing_messages')->where('id', $m['outbound']['message_id'] ?? 0)->first();
    $txt = static fn ($x) => $x ? (string) ($x->body ?? $x->text ?? $x->content ?? '') : '';
    echo "\n================ acción #{$f->id} · {$f->created_at} UTC · reason={$f->reason} · confianza={$f->confidence} ================\n";
    echo "ENTRANTE (", mb_strlen($txt($ent)), "): ", $txt($ent), "\n";
    echo "SALIENTE (", mb_strlen($txt($sal)), " caracteres): ", $txt($sal), "\n";
    foreach (['intent', 'origin', 'source', 'strategy_goal', 'next_best_action', 'fallback_mode', 'previous_phase', 'next_phase', 'main_barrier', 'lead_temperature', 'customer_lifecycle', 'reference_resolution', 'plans_mentioned', 'price_enriched', 'recommended_plan_id', 'knowledge_version'] as $k) {
        if (array_key_exists($k, $m)) { echo "  $k = ", json_encode($m[$k], JSON_UNESCAPED_UNICODE), "\n"; }
    }
    foreach (['risk_flags', 'tools_requested', 'policy_violations', 'strategy', 'strategy_hints'] as $k) {
        if (array_key_exists($k, $m)) { echo "  $k = ", json_encode($m[$k], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; }
    }
    if (isset($m['policy'])) {
        $p = $m['policy'];
        echo "  policy = ", json_encode(array_intersect_key($p, array_flip(['intent', 'reception_mode', 'memory_mode', 'answer_first', 'greet_required', 'welcome_required', 'commercial_phase', 'required_facts', 'forbidden_actions', 'allowed_next_actions', 'price_verification', 'required_plan_id', 'goal_takes_turn'])), JSON_UNESCAPED_UNICODE), "\n";
    }
    if (isset($m['critic'])) {
        $c = $m['critic'];
        echo "  CRITIC: verdict=", $c['verdict'] ?? '?', " · score=", $c['score'] ?? '?', " · attempt=", $c['attempt'] ?? '?', " · hard_fail=", json_encode($c['hard_fail'] ?? null), "\n";
        echo "  CRITIC issues: ", json_encode($c['issues'] ?? null, JSON_UNESCAPED_UNICODE), "\n";
        echo "  CRITIC notes: ", $c['notes'] ?? '', "\n";
    }
    foreach (['discarded_draft', 'policy_discarded_draft'] as $k) {
        if (isset($m[$k])) { echo "  $k (", mb_strlen((string) $m[$k]), " caracteres): ", $m[$k], "\n"; }
    }
    if (isset($m['studio_prompts'])) {
        $sp = $m['studio_prompts'];
        echo "  studio_prompts: state=", $sp['state'] ?? '?', " · ofrecida=", substr((string) ($sp['offered']['bundleHash'] ?? ''), 0, 8), " (", $sp['offered']['mode'] ?? '?', ") · observada=", substr((string) ($sp['observed']['bundleHash'] ?? ''), 0, 8), " (", $sp['observed']['mode'] ?? '?', ", ", $sp['observed']['flowRev'] ?? '?', ", ejecución ", $sp['observed']['execution'] ?? '?', ")\n";
    }
}
