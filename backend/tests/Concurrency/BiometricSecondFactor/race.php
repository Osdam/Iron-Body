<?php

/**
 * Cliente de carreras REALES contra `artisan serve` (workers) + PostgreSQL.
 *
 * Para que la carrera cuente, una conexión propia retiene en PG el cerrojo de
 * la sentencia crítica (barrera) y solo lo suelta cuando pg_blocking_pids ve
 * bloqueadas TODAS las peticiones de la carrera (contadas por el texto de la
 * sentencia bloqueada). Las peticiones salen escalonadas 40 ms para que los
 * workers del servidor se repartan las conexiones.
 *
 * Uso: php race.php <escenario.json> <base_url> <dbname>
 * Sale con 0 si se cumplen las invariantes, 1 si no, 3 si no hubo solapamiento.
 */
[$_, $scenarioFile, $base, $dbname] = $argv + [null, null, null, null];
$s = json_decode((string) file_get_contents($scenarioFile), true);
$pgPort = getenv('PG_PORT') ?: '55432';
$pdo = fn () => new PDO("pgsql:host=127.0.0.1;port={$pgPort};dbname={$dbname}", 'ironbody', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$barrier = $pdo();
$watch = $pdo();
$q = $pdo();

function http(string $method, string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, $res];
}

function nonce(string $base, string $uuid): string
{
    [$code, $res] = http('POST', "{$base}/api/kiosk/device/challenge", json_encode(['device_id' => $uuid]), ['Content-Type: application/json', 'Accept: application/json']);
    if ($code !== 200) {
        throw new RuntimeException("challenge {$code}: {$res}");
    }

    return json_decode($res, true)['nonce'];
}

/** Petición firmada como la tablet (contrato §1): cadena canónica + ECDSA P-256. */
function signedRequest(string $base, array $tablet, string $path, array $payload, array $extra = []): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $nonce = nonce($base, $tablet['uuid']);
    $canonical = implode("\n", ['IB-DEVICE-SIG-V1', 'POST', ltrim(preg_replace('#^/?api/#', '', $path), '/'), $tablet['uuid'], $nonce, hash('sha256', $body)]);
    openssl_sign($canonical, $sig, openssl_pkey_get_private($tablet['private_pem']), OPENSSL_ALGO_SHA256);

    return ['url' => "{$base}{$path}", 'body' => $body, 'headers' => array_merge([
        'Content-Type: application/json', 'Accept: application/json',
        "X-Device-Id: {$tablet['uuid']}", "X-Device-Nonce: {$nonce}", 'X-Device-Signature: '.base64_encode($sig),
    ], $extra), 'label' => $path];
}

$requests = [];
$sc = $s['scenario'];
if ($sc === 'pin') {
    // 5 por el kiosko (contador facial del kiosko) + 5 por inicio de clase
    // (contador facial de clases): el contador del PIN es UNO por persona.
    for ($i = 0; $i < 5; $i++) {
        $requests[] = signedRequest($base, $s['tablets']['A'], '/api/kiosk/verify', [
            'employee_ref' => $s['juan_ref_A'], 'purpose' => 'workday', 'embedding' => $s['faces']['juan'], 'pin' => '135792',
        ]) + ['via' => 'kiosk'];
        // Con embedding, la clase exige la firma de la tablet (contrato §6.1).
        $requests[] = signedRequest($base, $s['tablets']['B'], "/api/trainer/classes/{$s['class_id']}/start", [
            'session_date' => $s['today'], 'embedding' => $s['faces']['juan'], 'pin' => '135792',
        ], ["Authorization: Bearer {$s['juan_bearer']}"]) + ['via' => 'class'];
    }
    $barrierSql = "SELECT id FROM workforce_pins WHERE identity_id = {$s['juan_identity']} FOR UPDATE";
    $blockedLike = ['%workforce_pins%'];
    $releaseWith = 'COMMIT';
} else {
    foreach (($sc === 'twin' ? ['A' => 'juan', 'B' => 'camilo'] : ['A' => 'camilo', 'B' => 'camilo2']) as $t => $face) {
        $requests[] = signedRequest($base, $s['tablets'][$t], '/api/kiosk/enrollment/complete', [
            'enrollment_token' => $s["token_{$t}"], 'consent_version' => $s['consent_version'], 'consent_accepted' => true,
            'embeddings' => [$s['faces'][$face], $s['faces'][$face], $s['faces'][$face]],
        ]) + ['via' => "tablet{$t}"];
    }
    if ($sc === 'twin') {
        // Barrera: SHARE sobre la tabla de plantillas. Las lecturas del 1:N
        // (fuera y dentro de la transacción) pasan; los INSERT esperan.
        $barrierSql = 'LOCK TABLE trainer_face_references IN SHARE MODE';
        $blockedLike = ['%trainer_face_references%'];
        $releaseWith = 'COMMIT';
    } elseif ($sc === 'pair') {
        // Barrera: una inserción SIN confirmar del mismo par. Los dos
        // INSERT … ON CONFLICT DO NOTHING esperan a que se resuelva; se
        // suelta con ROLLBACK y compiten de verdad entre ellos.
        [$low, $high] = [min($s['juan_identity'], $s['camilo_identity']), max($s['juan_identity'], $s['camilo_identity'])];
        $barrierSql = "INSERT INTO face_lookalikes (identity_low_id, identity_high_id, source, first_detected_at, last_detected_at, created_at, updated_at) VALUES ({$low}, {$high}, 'barrier', now(), now(), now(), now())";
        $blockedLike = ['%face_lookalikes%'];
        $releaseWith = 'ROLLBACK';
    } else {
        $barrierSql = "SELECT id FROM face_lookalike_exceptions WHERE id = {$s['exception_id']} FOR UPDATE";
        $blockedLike = ['%face_lookalike_exceptions%', '%trainer_face_references%'];
        $releaseWith = 'COMMIT';
    }
}

$expectedBlocked = count($requests);
$barrier->beginTransaction();
$barrier->exec($barrierSql);

$mh = curl_multi_init();
$handles = [];
$startAt = microtime(true);
foreach ($requests as $i => $r) {
    $ch = curl_init($r['url']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $r['body'], CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $r['headers'], CURLOPT_TIMEOUT => 60, CURLOPT_FORBID_REUSE => true, CURLOPT_FRESH_CONNECT => true]);
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = $ch;
    $deadline = microtime(true) + 0.04; // escalón de 40 ms
    do {
        curl_multi_exec($mh, $running);
        usleep(2000);
    } while (microtime(true) < $deadline);
}

$likeSql = implode(' OR ', array_map(fn ($l) => 'query ILIKE '.$watch->quote($l), $blockedLike));
$maxBlocked = 0;
$released = false;
$deadline = microtime(true) + 25;
do {
    curl_multi_exec($mh, $running);
    if (! $released) {
        $n = (int) $watch->query("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND cardinality(pg_blocking_pids(pid)) > 0 AND ({$likeSql})")->fetchColumn();
        $maxBlocked = max($maxBlocked, $n);
        if ($n >= $expectedBlocked || microtime(true) > $deadline) {
            $released = true;
            $releasedAt = microtime(true);
            $releaseWith === 'COMMIT' ? $barrier->commit() : $barrier->rollBack();
        }
    }
    curl_multi_select($mh, 0.01);
} while ($running > 0 || ! $released);

$responses = [];
foreach ($handles as $i => $ch) {
    $body = curl_multi_getcontent($ch);
    $json = json_decode((string) $body, true);
    $responses[] = ['via' => $requests[$i]['via'], 'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'code' => $json['code'] ?? ($json['ok'] ?? null ? 'ok' : null),
        'attempts_left' => $json['second_factor']['attempts_left'] ?? null, 'raw' => is_array($json) ? null : substr((string) $body, 0, 200)];
    curl_multi_remove_handle($mh, $ch);
}

$overlap = $maxBlocked >= $expectedBlocked;
$report = ['scenario' => $sc, 'requests' => $expectedBlocked, 'max_blocked_seen' => $maxBlocked, 'overlap' => $overlap,
    'barrier_held_ms' => (int) round((($releasedAt ?? microtime(true)) - $startAt) * 1000)];

$tally = [];
foreach ($responses as $r) {
    $k = "{$r['status']} {$r['code']}".($r['attempts_left'] !== null ? " left={$r['attempts_left']}" : '');
    $tally[$k] = ($tally[$k] ?? 0) + 1;
}
ksort($tally);
$report['responses'] = $tally;
$byVia = [];
foreach ($responses as $r) {
    $k = "{$r['via']}: {$r['status']} {$r['code']}";
    $byVia[$k] = ($byVia[$k] ?? 0) + 1;
}
ksort($byVia);
$report['by_via'] = $byVia;
$violations = [];

$one = fn (string $sql) => $q->query($sql)->fetchColumn();
if ($sc === 'pin') {
    $pin = $q->query("SELECT failed_attempts, failures_window_started_at, lock_level, locked_until, now() AS db_now FROM workforce_pins WHERE identity_id = {$s['juan_identity']}")->fetch(PDO::FETCH_ASSOC);
    $failedAudits = (int) $one("SELECT count(*) FROM trainer_audit_logs WHERE event = 'biometric.pin_failed'");
    $lockedAudits = (int) $one("SELECT count(*) FROM trainer_audit_logs WHERE event = 'biometric.pin_locked'");
    $invalid = count(array_filter($responses, fn ($r) => $r['code'] === 'pin_invalid'));
    $lockedResp = count(array_filter($responses, fn ($r) => $r['code'] === 'pin_locked'));
    $report['db'] = ['pin_row' => $pin, 'audit_pin_failed' => $failedAudits, 'audit_pin_locked' => $lockedAudits,
        'class_sessions' => (int) $one('SELECT count(*) FROM class_sessions'), 'pin_invalid' => $invalid, 'pin_locked' => $lockedResp];
    // Invariantes: como mucho 5 PIN equivocados llegan a compararse: 4 «inválido» + 1 que bloquea.
    if ($invalid !== 4 || $lockedResp !== 6) {
        $violations[] = "se esperaban 4 pin_invalid y 6 pin_locked (hubo {$invalid} y {$lockedResp})";
    }
    if ($failedAudits !== 4 || $lockedAudits !== 1) {
        $violations[] = "traza incoherente: pin_failed={$failedAudits} pin_locked={$lockedAudits} (esperado 4 y 1)";
    }
    if ($pin['locked_until'] === null || (int) $pin['failed_attempts'] !== 0) {
        $violations[] = 'el PIN no quedó bloqueado con el contador a cero';
    }
    if (count(array_filter($responses, fn ($r) => $r['status'] >= 500 || $r['status'] === 0)) > 0) {
        $violations[] = 'hubo respuestas 5xx o cortes';
    }
    if ((int) $report['db']['class_sessions'] !== 0) {
        $violations[] = 'se inició una clase sin segundo factor';
    }
} elseif ($sc === 'twin') {
    $report['db'] = [
        'active_refs' => (int) $one("SELECT count(*) FROM trainer_face_references WHERE status = 'active'"),
        'face_lookalikes' => (int) $one('SELECT count(*) FROM face_lookalikes'),
    ];
    // ¿Camilo entra como Juan con su cara sola? (después de la carrera, en serie)
    $after = signedRequest($base, $s['tablets']['A'], '/api/kiosk/verify', ['employee_ref' => $s['juan_ref_A'], 'purpose' => 'workday', 'embedding' => $s['faces']['camilo']]);
    [$st, $res] = http('POST', $after['url'], $after['body'], $after['headers']);
    $j = json_decode($res, true);
    $report['camilo_as_juan_after'] = ['status' => $st, 'code' => $j['code'] ?? ($j['ok'] ? 'ok' : null), 'second_factor' => $j['second_factor'] ?? null];
    $cause = $one("SELECT metadata FROM trainer_audit_logs WHERE event = 'biometric.verify_ambiguous' ORDER BY id DESC LIMIT 1");
    $report['ambiguous_cause'] = $cause;
    if ($report['db']['active_refs'] === 2 && $report['db']['face_lookalikes'] === 0) {
        $violations[] = 'carrera 1:N: dos rostros a 0,91 registrados a la vez y SIN par (la «red» de enroll() no ve lo no confirmado)';
    }
    if ($st === 200) {
        $violations[] = 'Camilo entra como Juan con el rostro solo';
    }
} elseif ($sc === 'pair') {
    $report['db'] = [
        'face_lookalikes' => (int) $one('SELECT count(*) FROM face_lookalikes'),
        'face_lookalikes_source' => $one('SELECT string_agg(source, \',\') FROM face_lookalikes'),
        'audit_lookalike_detected' => (int) $one("SELECT count(*) FROM trainer_audit_logs WHERE event = 'biometric.lookalike_detected'"),
        'audit_duplicate_rejected' => (int) $one("SELECT count(*) FROM trainer_audit_logs WHERE event = 'biometric.duplicate_rejected'"),
        'face_duplicate_matches' => (int) $one('SELECT count(*) FROM face_duplicate_matches'),
    ];
    if ($report['db']['face_lookalikes'] !== 1 || $report['db']['face_lookalikes_source'] !== 'duplicate_rejected') {
        $violations[] = 'se esperaba UNA fila de par, creada por el rechazo';
    }
    if ($report['db']['audit_lookalike_detected'] !== 2) {
        $violations[] = 'se esperaban 2 trazas lookalike_detected (una por persona), no '.$report['db']['audit_lookalike_detected'];
    }
    if (($tally['409 face_already_linked'] ?? 0) !== 2) {
        $violations[] = 'los dos rechazos deben responder 409 face_already_linked';
    }
} else {
    $exc = $q->query("SELECT consumed_at, consumed_reference_id FROM face_lookalike_exceptions WHERE id = {$s['exception_id']}")->fetch(PDO::FETCH_ASSOC);
    $refs = $q->query("SELECT id, status FROM trainer_face_references WHERE identity_id = {$s['camilo_identity']}")->fetchAll(PDO::FETCH_ASSOC);
    $enrolledUsing = (int) $one("SELECT count(*) FROM trainer_audit_logs WHERE event = 'biometric.enrolled' AND metadata::jsonb -> 'lookalike_exceptions' @> '[{$s['exception_id']}]'::jsonb");
    $report['db'] = ['exception' => $exc, 'camilo_refs' => $refs, 'enrolled_audits_using_exception' => $enrolledUsing,
        'tokens_consumed' => (int) $one('SELECT count(*) FROM workforce_kiosk_tokens WHERE kind = \'enrollment\' AND consumed_at IS NOT NULL')];
    $ok201 = count(array_filter($responses, fn ($r) => $r['status'] === 201));
    if ($ok201 !== 1) {
        $violations[] = "la misma excepción sirvió a {$ok201} enrolamientos (debe ser 1)";
    }
    if ($enrolledUsing !== 1 || $exc['consumed_at'] === null) {
        $violations[] = "la excepción quedó usada {$enrolledUsing} veces según la traza";
    }
    if (count($refs) !== 1 || (int) $exc['consumed_reference_id'] !== (int) $refs[0]['id']) {
        $violations[] = 'plantillas de Camilo incoherentes con la excepción consumida';
    }
    if (count(array_filter($responses, fn ($r) => $r['status'] >= 500 || $r['status'] === 0)) > 0) {
        $violations[] = 'el perdedor recibió un 5xx (no un rechazo limpio)';
    }
}

$report['violations'] = $violations;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
exit(! $overlap ? 3 : ($violations === [] ? 0 : 1));
