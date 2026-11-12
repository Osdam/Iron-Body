<?php
// SOLO LECTURA. El estado de la conversación canaria de ULTRON, sin teléfonos, nombres ni textos: banderas, fechas y longitudes.
use Illuminate\Support\Facades\DB;
$id = config('marketing.ultron.canary_conversation_id');
echo "canaria configurada: ", $id ? 'sí' : 'NO', "\n";
if (! $id) { return; }
$c = DB::table('marketing_conversations')->where('id', $id)->first();
if (! $c) { echo "la conversación no existe\n"; return; }
$a = (array) $c;
$ver = static fn (string $k) => array_key_exists($k, $a) ? var_export($a[$k], true) : '(sin columna)';
foreach (['status', 'channel', 'ai_enabled', 'human_takeover', 'human_takeover_at', 'assigned_admin_id', 'needs_human', 'last_inbound_at', 'last_outbound_at', 'last_message_at', 'closed_at'] as $k) {
    echo "  conversación.$k = ", $ver($k), "\n";
}
$leadId = $a['lead_id'] ?? null;
if ($leadId) {
    $l = (array) DB::table('marketing_leads')->where('id', $leadId)->first();
    foreach (['do_not_contact', 'commercial_opt_out', 'consent_status', 'consent_denied', 'status', 'ai_enabled'] as $k) {
        echo "  prospecto.$k = ", array_key_exists($k, $l) ? var_export($l[$k], true) : '(sin columna)', "\n";
    }
}
$ms = DB::table('marketing_messages')->where('conversation_id', $id)->orderByDesc('id')->limit(6)->get();
foreach ($ms as $m) {
    $x = (array) $m;
    $cuerpo = (string) ($x['body'] ?? $x['text'] ?? $x['content'] ?? '');
    echo sprintf("  mensaje #%d · %s · %s · %s · %d caracteres · estado=%s\n", $x['id'], $x['direction'] ?? '?', $x['sender_type'] ?? ($x['author_type'] ?? '?'), $x['created_at'] ?? '?', mb_strlen($cuerpo), $x['status'] ?? '?');
}
echo "ULTRON: enabled=", var_export(config('marketing.ultron.enabled'), true), " · canary_only=", var_export(config('marketing.ultron.canary_only'), true), " · payment_links=", var_export(config('marketing.ultron.payment_links_enabled'), true), "\n";
