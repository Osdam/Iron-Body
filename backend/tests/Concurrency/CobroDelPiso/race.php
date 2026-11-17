<?php

/**
 * Carrera REAL del cobro del piso contra PostgreSQL: dos «pagó noviembre» del
 * mismo acuerdo a la vez (doble clic, o dos recepcionistas).
 *
 * Cada petición es un PROCESO aparte (worker.php) con su propia conexión. Para
 * que la carrera cuente, una conexión propia retiene `FOR UPDATE` sobre la fila
 * del acuerdo (barrera) y solo la suelta cuando pg_blocking_pids ve bloqueadas
 * LAS DOS peticiones (o vence el plazo). Las peticiones salen escalonadas 40 ms.
 *
 * Dónde se bloquean, según la versión:
 *  · con la corrección, en el `SELECT … FOR UPDATE` del acuerdo, ANTES de mirar
 *    si el mes ya está cobrado;
 *  · sin ella, ya habiendo mirado (y visto el mes libre): en el INSERT del
 *    cobro, cuya llave foránea pide FOR KEY SHARE sobre esa misma fila.
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
$tmp = dirname($scenarioFile).'/race-'.$s['scenario'].'-'.getmypid();
@mkdir($tmp);

$uri = "/api/admin/commissions/agreements/{$s['agreement_id']}/charge";
$cuerpo = ['period' => $s['period'], 'method' => 'cash'];
$requests = [
    ['method' => 'POST', 'uri' => $uri, 'body' => $cuerpo, 'label' => 'cobrar-1'],
    ['method' => 'POST', 'uri' => $uri, 'body' => $cuerpo, 'label' => 'cobrar-2'],
];

$barrier->beginTransaction();
$barrier->exec("SELECT id FROM trainer_commission_agreements WHERE id = {$s['agreement_id']} FOR UPDATE");

$procs = [];
$startAt = microtime(true);
foreach ($requests as $i => $r) {
    $jobFile = "{$tmp}/job-{$i}.json";
    $outFile = "{$tmp}/out-{$i}.json";
    file_put_contents($jobFile, json_encode($r + ['token' => $s['token']]));
    $procs[$i] = [
        'proc' => proc_open([PHP_BINARY, __DIR__.'/worker.php', $appPath, $jobFile, $outFile], [1 => ['file', "{$tmp}/stdout-{$i}.txt", 'w'], 2 => ['file', "{$tmp}/stderr-{$i}.txt", 'w']], $pipes),
        'out' => $outFile,
    ];
    usleep(40000); // escalón de 40 ms
}

$expectedBlocked = count($requests);
$likeSql = implode(' OR ', array_map(fn ($l) => 'query ILIKE '.$watch->quote($l),
    ['%trainer_commission_agreements%', '%trainer_commission_charges%']));
$maxBlocked = 0;
$deadline = microtime(true) + (float) (getenv('RACE_DEADLINE') ?: 8);
do {
    $n = (int) $watch->query("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND cardinality(pg_blocking_pids(pid)) > 0 AND ({$likeSql})")->fetchColumn();
    $maxBlocked = max($maxBlocked, $n);
    if ($n >= $expectedBlocked) {
        break;
    }
    usleep(5000);
} while (microtime(true) < $deadline);
$releasedAt = microtime(true);
$barrier->commit();

$responses = [];
foreach ($procs as $i => $p) {
    proc_close($p['proc']);
    $responses[$i] = json_decode((string) @file_get_contents($p['out']), true) ?? ['label' => $requests[$i]['label'], 'status' => -1, 'body' => null];
}

$overlap = $maxBlocked >= $expectedBlocked;
$report = [
    'scenario' => $s['scenario'], 'requests' => $expectedBlocked, 'max_blocked_seen' => $maxBlocked, 'overlap' => $overlap,
    'barrier_held_ms' => (int) round(($releasedAt - $startAt) * 1000),
    'responses' => array_map(fn ($r) => sprintf('%s → %d %s', $r['label'], $r['status'], $r['body']['message'] ?? ''), $responses),
];

$one = fn (string $sql) => $q->query($sql)->fetchColumn();
$periodo = $s['period'].'-01';
$vigentes = (int) $one("SELECT count(*) FROM trainer_commission_charges c JOIN receivables r ON r.id = c.receivable_id
    WHERE c.agreement_id = {$s['agreement_id']} AND c.period = '{$periodo}' AND r.status <> 'cancelled'");
$deudas = (int) $one("SELECT count(*) FROM trainer_commission_charges WHERE agreement_id = {$s['agreement_id']}");
$abonos = (int) $one("SELECT count(*) FROM receivable_payments p JOIN trainer_commission_charges c ON c.receivable_id = p.receivable_id
    WHERE c.agreement_id = {$s['agreement_id']} AND p.status = 'applied'");
$cobrado = (float) $one("SELECT coalesce(sum(p.amount), 0) FROM receivable_payments p JOIN trainer_commission_charges c ON c.receivable_id = p.receivable_id
    WHERE c.agreement_id = {$s['agreement_id']} AND p.status = 'applied'");
$report['db'] = ['live_charges_for_month' => $vigentes, 'charges' => $deudas, 'applied_payments' => $abonos, 'collected' => $cobrado];

$violations = [];
if (count(array_filter($responses, fn ($r) => $r['status'] >= 500 || $r['status'] <= 0)) > 0) {
    $violations[] = 'hubo respuestas 5xx o el proceso murió';
}
$codigos = array_map(fn ($r) => $r['status'], $responses);
sort($codigos);
if ($codigos !== [201, 422]) {
    $violations[] = 'se esperaba un cobro aceptado (201) y el otro rechazado (422); hubo: '.implode(', ', $codigos);
}
if ($vigentes !== 1 || $deudas !== 1) {
    $violations[] = "{$vigentes} cobros vigentes ({$deudas} en total) del mismo mes (esperado 1)";
}
if ($abonos !== 1 || abs($cobrado - (float) $s['amount']) > 0.001) {
    $violations[] = "{$abonos} abonos por {$cobrado} (esperado 1 por {$s['amount']}): se cobró dos veces el mismo mes";
}

$report['violations'] = $violations;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($violations !== [] ? 1 : ($overlap ? 0 : 3));
