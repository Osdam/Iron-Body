<?php

/**
 * Dos reclasificaciones SIMULTÁNEAS del mismo cobro contra PostgreSQL.
 *
 * Cada ejecución es un PROCESO aparte (worker.php) con su propia conexión. Para
 * que la carrera cuente, una conexión propia retiene en PG el cerrojo de la
 * fila de la socia (la que el procedimiento bloquea, como applyUpdate) y solo
 * lo suelta cuando pg_blocking_pids ve bloqueadas LAS DOS ejecuciones: una en
 * esa fila y la otra en el cerrojo consultivo del procedimiento (o en la misma
 * fila, si se probara sin él). Salen escalonadas 40 ms.
 *
 * Invariante: una aplica y la otra informa «ya aplicada»; UNA constancia, UN
 * abono, UN entrenador, UN acuerdo, UN cobro del mes, el cobro anulado una vez
 * y la membresía recortada una vez.
 *
 * Uso: php race.php <ruta_backend> <escenario.json> <dbname>
 * Sale con 0 si se cumplen las invariantes, 1 si no, 3 si no hubo solapamiento
 * y tampoco violación (la carrera no cuenta).
 */
[$_, $appPath, $scenarioFile, $dbname] = $argv + [null, null, null, null];
$s = json_decode((string) file_get_contents($scenarioFile), true);
$pgPort = getenv('PG_PORT') ?: '55432';
$pdo = fn () => new PDO("pgsql:host=127.0.0.1;port={$pgPort};dbname={$dbname}", 'ironbody', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$barrier = $pdo();
$watch = $pdo();
$q = $pdo();
$tmp = dirname($scenarioFile).'/race-reclasificacion-'.getmypid();
@mkdir($tmp);

$opciones = [
    '--payment' => $s['payment_id'],
    '--member' => $s['member_id'],
    '--trainer-from-member' => $s['trainer_member_id'],
    '--actor' => $s['actor_id'],
    '--reason' => 'Carrera: dos reclasificaciones simultáneas del mismo cobro',
    '--apply' => true,
    '--json' => true,
];
$trabajos = [
    ['label' => 'reclasificar-1', 'options' => $opciones],
    ['label' => 'reclasificar-2', 'options' => $opciones],
];

// La barrera: la fila de la socia, que el procedimiento bloquea después del turno.
$barrier->beginTransaction();
$barrier->exec("SELECT id FROM users WHERE id = {$s['user_id']} FOR UPDATE");

$procs = [];
$startAt = microtime(true);
foreach ($trabajos as $i => $t) {
    $jobFile = "{$tmp}/job-{$i}.json";
    $outFile = "{$tmp}/out-{$i}.json";
    file_put_contents($jobFile, json_encode($t));
    $procs[$i] = [
        'proc' => proc_open([PHP_BINARY, __DIR__.'/worker.php', $appPath, $jobFile, $outFile], [1 => ['file', "{$tmp}/stdout-{$i}.txt", 'w'], 2 => ['file', "{$tmp}/stderr-{$i}.txt", 'w']], $pipes),
        'out' => $outFile,
    ];
    usleep(40000); // escalón de 40 ms
}

$esperadas = count($trabajos);
$maxBloqueadas = 0;
$deadline = microtime(true) + (float) (getenv('RACE_DEADLINE') ?: 15);
do {
    $n = (int) $watch->query("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid()
        AND cardinality(pg_blocking_pids(pid)) > 0 AND (query ILIKE '%from \"users\"%' OR query ILIKE '%pg_advisory_xact_lock%')")->fetchColumn();
    $maxBloqueadas = max($maxBloqueadas, $n);
    if ($n >= $esperadas) {
        break;
    }
    usleep(5000);
} while (microtime(true) < $deadline);
$releasedAt = microtime(true);
$barrier->commit();

$respuestas = [];
foreach ($procs as $i => $p) {
    proc_close($p['proc']);
    $respuestas[$i] = json_decode((string) @file_get_contents($p['out']), true) ?? ['label' => $trabajos[$i]['label'], 'exit' => -1, 'report' => null];
}

$solape = $maxBloqueadas >= $esperadas;
$one = fn (string $sql) => $q->query($sql)->fetchColumn();
$pago = (int) $s['payment_id'];
$db = [
    'payment_status' => $one("SELECT status FROM payments WHERE id = {$pago}"),
    'reclassifications' => (int) $one("SELECT count(*) FROM payment_reclassifications WHERE payment_id = {$pago}"),
    'reclass_abonos' => (int) $one("SELECT count(*) FROM receivable_payments WHERE client_request_id = 'reclass:payment:{$pago}'"),
    'trainers' => (int) $one('SELECT count(*) FROM trainers'),
    'identities' => (int) $one('SELECT count(*) FROM identities'),
    'agreements' => (int) $one('SELECT count(*) FROM trainer_commission_agreements'),
    'charges' => (int) $one('SELECT count(*) FROM trainer_commission_charges'),
    'receivables' => (int) $one('SELECT count(*) FROM receivables'),
    'cancel_audits' => (int) $one("SELECT count(*) FROM audit_logs WHERE entity = 'pago' AND entity_id = '{$pago}' AND action = 'status'"),
    'membership_audits' => (int) $one("SELECT count(*) FROM audit_logs WHERE entity = 'membership' AND entity_id = '{$s['user_id']}'"),
    'end_after' => (string) $one("SELECT membership_end_date FROM users WHERE id = {$s['user_id']}"),
];
$estados = array_map(fn ($r) => $r['report']['status'] ?? ('exit '.$r['exit']), $respuestas);
$ordenados = $estados;
sort($ordenados);

$violaciones = [];
if ($ordenados !== ['already_applied', 'applied']) {
    $violaciones[] = 'se esperaba una «applied» y una «already_applied»; hubo: '.implode(', ', $estados);
}
foreach ($respuestas as $r) {
    if ($r['exit'] !== 0) {
        $violaciones[] = "{$r['label']} salió con {$r['exit']}";
    }
}
foreach (['reclassifications', 'reclass_abonos', 'trainers', 'identities', 'agreements', 'charges', 'receivables', 'cancel_audits', 'membership_audits'] as $k) {
    if ($db[$k] !== 1) {
        $violaciones[] = "{$db[$k]} filas de {$k} (esperada 1)";
    }
}
if ($db['payment_status'] !== 'cancelled') {
    $violaciones[] = "el cobro quedó «{$db['payment_status']}»";
}
if (substr($db['end_after'], 0, 10) !== $s['end_after_expected']) {
    $violaciones[] = "la membresía terminó en {$db['end_after']}; recortada UNA vez debía terminar en {$s['end_after_expected']}";
}

echo json_encode([
    'scenario' => 'reclasificacion_doble',
    'runs' => $esperadas,
    'max_blocked_seen' => $maxBloqueadas,
    'overlap' => $solape,
    'barrier_held_ms' => (int) round(($releasedAt - $startAt) * 1000),
    'responses' => array_map(fn ($r) => sprintf('%s → salida %s, %s%s (%d ms)', $r['label'], $r['exit'], $r['report']['status'] ?? '?',
        isset($r['report']['problem']['code']) ? ' ['.$r['report']['problem']['code'].']' : '', $r['ms'] ?? 0), $respuestas),
    'db' => $db,
    'end_before' => $s['end_before'],
    'violations' => $violaciones,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($violaciones !== [] ? 1 : ($solape ? 0 : 3));
