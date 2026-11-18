<?php

/**
 * Carreras REALES del ciclo de valoraciones contra PostgreSQL.
 *
 * Cada petición es un PROCESO aparte (worker.php) con su propia conexión. Una
 * conexión propia retiene en PG el cerrojo de la fila que todas las peticiones
 * toman primero (la valoración, o el socio) y solo lo suelta cuando
 * pg_blocking_pids ve bloqueadas TODAS las peticiones (o vence el plazo). Las
 * peticiones salen escalonadas 40 ms.
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

$sc = $s['scenario'];
if ($sc === 'doble_envio') {
    // Doble toque en «Enviar al miembro» (o un reintento con la red lenta).
    $requests = [
        ['method' => 'POST', 'uri' => "/api/trainer/assessments/{$s['assessment_uuid']}/submit", 'label' => 'envio-1', 'body' => []],
        ['method' => 'POST', 'uri' => "/api/trainer/assessments/{$s['assessment_uuid']}/submit", 'label' => 'envio-2', 'body' => []],
    ];
    $barrierSql = "SELECT id FROM professional_assessments WHERE id = {$s['assessment_id']} FOR UPDATE";
    $pattern = '"professional_assessments"';
} else {
    // Dos mostradores programan la próxima valoración del mismo socio a la
    // vez, con entrenadores y horas distintos: solo el socio los ordena.
    $cita = fn (int $i): array => [
        'method' => 'POST', 'uri' => '/api/admin/valuations', 'label' => 'proxima-'.($i + 1),
        'body' => [
            'client_request_id' => sprintf('%08x-0000-4000-8000-%012d', $i + 1, $i + 1),
            'member_id' => $s['member_id'], 'trainer_id' => $s['trainer_ids'][$i], 'type' => 'follow_up',
            'starts_at' => $s['starts'][$i], 'duration_minutes' => 60, 'as_next_follow_up' => true,
        ],
    ];
    $requests = [$cita(0), $cita(1)];
    $barrierSql = "SELECT id FROM members WHERE id = {$s['member_id']} FOR UPDATE";
    $pattern = '"members"';
}

$barrier->beginTransaction();
$barrier->exec($barrierSql);

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
$maxBlocked = 0;
$deadline = microtime(true) + (float) (getenv('RACE_DEADLINE') ?: 8);
$watchSql = 'SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND cardinality(pg_blocking_pids(pid)) > 0 AND query ILIKE '.$watch->quote('%'.$pattern.'%');
do {
    $n = (int) $watch->query($watchSql)->fetchColumn();
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
    'scenario' => $sc, 'requests' => $expectedBlocked, 'max_blocked_seen' => $maxBlocked, 'overlap' => $overlap,
    'barrier_held_ms' => (int) round(($releasedAt - $startAt) * 1000),
    'responses' => array_map(fn ($r) => sprintf('%s → %d %s', $r['label'], $r['status'], $r['body']['code'] ?? ''), $responses),
];
$violations = [];
$one = fn (string $sql) => $q->query($sql)->fetchColumn();
if (count(array_filter($responses, fn ($r) => $r['status'] >= 500 || $r['status'] <= 0)) > 0) {
    $violations[] = 'hubo respuestas 5xx o el proceso murió';
}
$estados = array_map(fn ($r) => trim($r['status'].' '.($r['body']['code'] ?? '')), $responses);
sort($estados);

if ($sc === 'doble_envio') {
    $m = (int) $s['member_id'];
    $db = [
        'assessment_status' => $one("SELECT status FROM professional_assessments WHERE id = {$s['assessment_id']}"),
        'appointment' => $q->query("SELECT status, version, professional_assessment_id FROM valuation_appointments WHERE id = {$s['appointment_id']}")->fetch(PDO::FETCH_ASSOC),
        'completed_events' => (int) $one("SELECT count(*) FROM valuation_appointment_events WHERE appointment_id = {$s['appointment_id']} AND action = 'completed'"),
        'crm_notices' => (int) $one("SELECT count(*) FROM notifications WHERE audience = 'admin' AND event_key LIKE 'valuation:assessment:%'"),
        'member_notices' => (int) $one("SELECT count(*) FROM notifications WHERE audience = 'member' AND member_id = {$m} AND type = 'professional_assessment'"),
        'physical_evaluations' => (int) $one("SELECT count(*) FROM physical_evaluations WHERE member_id = {$m}"),
    ];
    $report['db'] = $db;
    if ($estados !== ['200', '409 assessment_error']) {
        $violations[] = 'se esperaba un 200 y un 409 (hubo: '.implode(', ', $estados).')';
    }
    if ($db['assessment_status'] !== 'submitted') {
        $violations[] = "la valoración quedó en {$db['assessment_status']}";
    }
    if ($db['appointment']['status'] !== 'completed' || (int) $db['appointment']['professional_assessment_id'] !== (int) $s['assessment_id'] || (int) $db['appointment']['version'] !== 2) {
        $violations[] = 'la cita no quedó completada y enlazada UNA vez (versión 2): '.json_encode($db['appointment']);
    }
    foreach (['completed_events' => 'eventos completed', 'crm_notices' => 'avisos al CRM', 'member_notices' => 'avisos al socio', 'physical_evaluations' => 'evaluaciones físicas'] as $k => $que) {
        if ($db[$k] !== 1) {
            $violations[] = "{$db[$k]} {$que} (esperado 1)";
        }
    }
} else {
    $citas = (int) $one("SELECT count(*) FROM valuation_appointments WHERE member_id = {$s['member_id']}");
    $report['db'] = ['appointments' => $citas];
    if ($estados !== ['201', '409 member_has_future_appointment']) {
        $violations[] = 'se esperaba un 201 y un 409 member_has_future_appointment (hubo: '.implode(', ', $estados).')';
    }
    if ($citas !== 1) {
        $violations[] = "{$citas} citas del socio (esperada 1)";
    }
}

$report['violations'] = $violations;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($violations !== [] ? 1 : ($overlap ? 0 : 3));
