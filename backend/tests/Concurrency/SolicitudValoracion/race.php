<?php

/**
 * Carreras REALES de «SOLICITAR VALORACIÓN» contra PostgreSQL.
 *
 * Cada petición es un PROCESO aparte (worker.php) con su propia conexión. Una
 * conexión propia retiene con `FOR UPDATE` la fila donde chocan todas las
 * peticiones del escenario y solo la suelta cuando pg_blocking_pids ve
 * bloqueadas TODAS (o vence RACE_DEADLINE, 8 s). Las peticiones salen
 * escalonadas 40 ms.
 *
 *  · POST de la app: la fila del SOCIO. Con el código bueno chocan en su
 *    `FOR NO KEY UPDATE`; sin ese cerrojo, en el `FOR KEY SHARE` de la FK del
 *    INSERT. La barrera las retiene en los dos casos.
 *  · schedule frente a reject: la fila de la SOLICITUD (`FOR UPDATE` en los
 *    dos; sin él, el UPDATE).
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
$pedir = fn (string $llave, string $label): array => [
    'method' => 'POST', 'uri' => '/api/app/valuation-requests', 'label' => $label,
    'token' => $s['member_token'], 'body' => ['client_request_id' => $llave],
];
$llave = fn (): string => sprintf('%04x%04x-%04x-4%03x-a%03x-%04x%04x%04x', random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0, 0xFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF));

$requests = match ($sc) {
    // Doble toque: el mismo intento dos veces (la app reutiliza su id).
    'dos_toques_misma_llave' => (function () use ($pedir, $llave) {
        $k = $llave();

        return [$pedir($k, 'toque-1'), $pedir($k, 'toque-2')];
    })(),
    // Dos intentos distintos a la vez (dos dispositivos, o la app reabierta).
    'dos_toques_llaves_distintas' => [$pedir($llave(), 'intento-a'), $pedir($llave(), 'intento-b')],
    // Recepción enlaza la cita mientras otra persona rechaza la misma solicitud.
    'agendar_vs_rechazar' => [
        ['method' => 'POST', 'uri' => "/api/admin/valuations/requests/{$s['request_uuid']}/schedule", 'label' => 'agendar',
            'token' => $s['admin_token'], 'body' => ['appointment_uuid' => $s['appointment_uuid'], 'expected_version' => 1]],
        ['method' => 'POST', 'uri' => "/api/admin/valuations/requests/{$s['request_uuid']}/reject", 'label' => 'rechazar',
            'token' => $s['admin_token'], 'body' => ['reason' => 'Carrera: sin cupos esta semana', 'expected_version' => 1]],
    ],
};
// RACE_REVERSE=1 lanza primero la segunda: el ganador es quien toma antes el
// cerrojo, nunca una mezcla de los dos.
if (getenv('RACE_REVERSE') === '1') {
    $requests = array_reverse($requests);
}

$barrier->beginTransaction();
if ($sc === 'agendar_vs_rechazar') {
    $st = $barrier->prepare('SELECT id FROM valuation_requests WHERE uuid = ? FOR UPDATE');
    $st->execute([$s['request_uuid']]);
} else {
    $barrier->exec('SELECT id FROM members WHERE id = '.(int) $s['member_id'].' FOR UPDATE');
}

$procs = [];
$startAt = microtime(true);
foreach ($requests as $i => $r) {
    $jobFile = "{$tmp}/job-{$i}.json";
    $outFile = "{$tmp}/out-{$i}.json";
    file_put_contents($jobFile, json_encode($r));
    $procs[$i] = [
        'proc' => proc_open([PHP_BINARY, __DIR__.'/worker.php', $appPath, $jobFile, $outFile], [1 => ['file', "{$tmp}/stdout-{$i}.txt", 'w'], 2 => ['file', "{$tmp}/stderr-{$i}.txt", 'w']], $pipes),
        'out' => $outFile,
    ];
    usleep(40000); // escalón de 40 ms
}

$expectedBlocked = count($requests);
$maxBlocked = 0;
$deadline = microtime(true) + (float) (getenv('RACE_DEADLINE') ?: 8);
do {
    $n = (int) $watch->query('SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND cardinality(pg_blocking_pids(pid)) > 0')->fetchColumn();
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
$codigo = fn (array $r): string => implode(' ', array_filter([
    (string) $r['status'], $r['body']['code'] ?? null,
    isset($r['body']['changed']) ? ($r['body']['changed'] ? 'changed' : 'unchanged') : null,
]));
$report = [
    'scenario' => $sc, 'launch_order' => array_column($requests, 'label'), 'requests' => $expectedBlocked, 'max_blocked_seen' => $maxBlocked, 'overlap' => $overlap,
    'barrier_held_ms' => (int) round(($releasedAt - $startAt) * 1000),
    'responses' => array_map(fn ($r) => $r['label'].' → '.$codigo($r), $responses),
];
$violations = [];
$one = fn (string $sql) => $q->query($sql)->fetchColumn();
if (count(array_filter($responses, fn ($r) => $r['status'] >= 500 || $r['status'] <= 0)) > 0) {
    $violations[] = 'hubo respuestas 5xx o el proceso murió';
}

$socio = (int) $s['member_id'];
$filas = (int) $one("SELECT count(*) FROM valuation_requests WHERE member_id = {$socio}");
$pendientes = (int) $one("SELECT count(*) FROM valuation_requests WHERE member_id = {$socio} AND status = 'pending'");
$avisos = $q->query("SELECT event_key FROM notifications WHERE event_key LIKE 'valuation:request:%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$report['db'] = ['requests' => $filas, 'pending' => $pendientes, 'notices' => $avisos];
$estados = array_map($codigo, $responses);
sort($estados);

if ($sc === 'dos_toques_misma_llave' || $sc === 'dos_toques_llaves_distintas') {
    $esperado = $sc === 'dos_toques_misma_llave' ? ['200', '201'] : ['201', '409 valuation_request_pending'];
    if ($estados !== $esperado) {
        $violations[] = 'se esperaba '.implode(' y ', $esperado).' (hubo: '.implode(', ', $estados).')';
    }
    if ($filas !== 1 || $pendientes !== 1) {
        $violations[] = "{$filas} solicitudes y {$pendientes} pendientes del socio (esperada 1)";
    }
    $uuid = (string) $one("SELECT uuid FROM valuation_requests WHERE member_id = {$socio} ORDER BY id LIMIT 1");
    if ($avisos !== ["valuation:request:{$uuid}:created", "valuation:request:{$uuid}:received"]) {
        $violations[] = 'avisos: se esperaban exactamente el del CRM y el del socio de la única solicitud';
    }
    // Lo que respondieron los dos describe la misma solicitud.
    $vistas = array_values(array_unique(array_filter(array_map(fn ($r) => $r['body']['data']['request']['uuid'] ?? null, $responses))));
    if ($vistas !== [$uuid]) {
        $violations[] = 'las respuestas no enseñan la misma solicitud';
    }
} else {
    $fila = $q->query("SELECT status, appointment_id, rejection_reason, version FROM valuation_requests WHERE uuid = '{$s['request_uuid']}'")->fetch(PDO::FETCH_ASSOC);
    $trazas = (int) $one("SELECT count(*) FROM audit_logs WHERE entity = 'solicitud_valoracion'");
    $rechazos = count(array_filter($avisos, fn ($k) => str_ends_with($k, ':rejected')));
    $report['db'] += ['row' => $fila, 'audit_rows' => $trazas, 'rejected_notices' => $rechazos];
    $porEtiqueta = array_column($responses, null, 'label');
    [$agendar, $rechazar] = [$porEtiqueta['agendar'], $porEtiqueta['rechazar']];
    $gano = fn (array $r): bool => $r['status'] === 200 && ($r['body']['changed'] ?? null) === true;
    $perdio = fn (array $r): bool => $r['status'] === 409 && in_array($r['body']['code'] ?? null, ['request_changed', 'request_not_pending'], true);

    if ($gano($agendar) && $perdio($rechazar)) {
        $report['winner'] = 'agendar';
        if ($fila['status'] !== 'scheduled' || (int) $fila['appointment_id'] !== (int) $s['appointment_id'] || $fila['rejection_reason'] !== null || $rechazos !== 0) {
            $violations[] = 'ganó agendar pero la fila o los avisos no lo dicen (mezcla de los dos)';
        }
    } elseif ($gano($rechazar) && $perdio($agendar)) {
        $report['winner'] = 'rechazar';
        if ($fila['status'] !== 'rejected' || $fila['appointment_id'] !== null || $fila['rejection_reason'] === null || $rechazos !== 1) {
            $violations[] = 'ganó rechazar pero la fila o los avisos no lo dicen (mezcla de los dos)';
        }
    } else {
        $violations[] = 'no hubo exactamente un ganador y un 409 (hubo: '.implode(', ', $estados).')';
    }
    if ((int) $fila['version'] !== 2) {
        $violations[] = "versión {$fila['version']} (esperada 2: un solo cambio)";
    }
    if ($trazas !== 1) {
        $violations[] = "{$trazas} trazas de auditoría (esperada 1)";
    }
}

$report['violations'] = $violations;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
exit($violations !== [] ? 1 : ($overlap ? 0 : 3));
