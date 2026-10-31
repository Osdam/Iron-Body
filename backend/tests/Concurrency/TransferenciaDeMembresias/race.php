<?php

/**
 * Carreras REALES de TRANSFERIR MEMBRESÍA contra PostgreSQL.
 *
 * Cada petición es un PROCESO aparte (worker.php) con su propia conexión. Una
 * conexión propia retiene en PG el cerrojo de la fila de la TITULAR (la que
 * todas las peticiones bloquean primero) y solo lo suelta cuando
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
$traspaso = fn (int $b, string $llave, string $label): array => [
    'method' => 'POST', 'uri' => "/api/users/{$s['holder_id']}/membership-transfer", 'label' => $label,
    'body' => ['beneficiary_id' => $b, 'reason' => 'Carrera: cede su anualidad', 'holder_consent' => true,
        'beneficiary_acceptance' => true, 'client_request_id' => $llave],
];
$requests = match ($sc) {
    // Dos mostradores ceden el MISMO cobro a dos personas distintas a la vez.
    'traspaso_doble_mismo_cobro' => [
        $traspaso($s['b1_id'], 'carrera-b1', 'a-b1'),
        $traspaso($s['b2_id'], 'carrera-b2', 'a-b2'),
    ],
    // Doble clic: la misma petición dos veces.
    'traspaso_misma_llave' => [
        $traspaso($s['b1_id'], 'carrera-doble-clic', 'clic-1'),
        $traspaso($s['b1_id'], 'carrera-doble-clic', 'clic-2'),
    ],
    // Un mostrador cede la membresía mientras otro la congela. Antes del
    // bloqueo, congelar calculaba con la titular que cargó la ruta y guardaba
    // los días que ya había cedido (revisión de seguridad M2).
    'traspaso_vs_congelar' => [
        $traspaso($s['b1_id'], 'carrera-vs-congelar', 'transferir'),
        ['method' => 'POST', 'uri' => "/api/users/{$s['holder_id']}/freeze", 'label' => 'congelar',
            'body' => ['days' => 15, 'reason' => 'Carrera: viaje']],
    ],
    // Un mostrador cede la membresía mientras otro le suma días.
    'traspaso_vs_ajustar' => [
        $traspaso($s['b1_id'], 'carrera-vs-ajustar', 'transferir'),
        ['method' => 'POST', 'uri' => "/api/users/{$s['holder_id']}/adjustments", 'label' => 'ajustar',
            'body' => ['kind' => 'days', 'amount' => 3, 'reason' => 'Carrera: no pudo venir']],
    ],
    // Un mostrador cede la membresía mientras un supervisor anula el cobro de origen.
    'traspaso_vs_anular' => [
        $traspaso($s['b1_id'], 'carrera-vs-anular', 'transferir'),
        ['method' => 'PATCH', 'uri' => "/api/payments/{$s['payment_id']}", 'label' => 'anular',
            'body' => ['status' => 'cancelled', 'reason' => 'Carrera: cobro duplicado']],
    ],
};

$barrier->beginTransaction();
$barrier->exec("SELECT id FROM users WHERE id = {$s['holder_id']} FOR UPDATE");

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
do {
    $n = (int) $watch->query("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND cardinality(pg_blocking_pids(pid)) > 0 AND query ILIKE '%\"users\"%'")->fetchColumn();
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
    'responses' => array_map(fn ($r) => sprintf('%s → %d %s%s', $r['label'], $r['status'], $r['body']['code'] ?? '',
        ! empty($r['body']['idempotent_replay']) ? ' (idempotent_replay)' : ''), $responses),
];
$violations = [];
$one = fn (string $sql) => $q->query($sql)->fetchColumn();
if (count(array_filter($responses, fn ($r) => $r['status'] >= 500 || $r['status'] <= 0)) > 0) {
    $violations[] = 'hubo respuestas 5xx o el proceso murió';
}

$vivos = (int) $one("SELECT count(*) FROM membership_transfers WHERE source_payment_id = {$s['payment_id']} AND reverted_at IS NULL");
$filas = (int) $one('SELECT count(*) FROM membership_transfers');
$trazas = (int) $one("SELECT count(*) FROM audit_logs WHERE metadata->>'event' = 'membership.transferred'");
$socio = fn (int $id): array => $q->query("SELECT plan, membership_start_date::date AS start, membership_end_date::date AS fin, membership_frozen_at::date AS congelada, membership_frozen_days_left AS dias_guardados FROM users WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
$a = $socio($s['holder_id']);
$b1 = $socio($s['b1_id']);
$b2 = $socio($s['b2_id']);
$estadoCobro = (string) $one("SELECT status FROM payments WHERE id = {$s['payment_id']}");
$ayer = (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->modify('-1 day')->format('Y-m-d');
$report['db'] = ['live_transfers' => $vivos, 'transfer_rows' => $filas, 'audit_rows' => $trazas, 'payment' => $estadoCobro,
    'holder' => $a, 'b1' => $b1, 'b2' => $b2];
$recibio = fn (array $u): bool => $u['fin'] === $s['end'] && $u['plan'] === $s['plan_name'];
$intacto = fn (array $u): bool => $u['fin'] === null && $u['plan'] === null && $u['congelada'] === null;
$estados = array_map(fn ($r) => $r['status'].' '.($r['body']['code'] ?? '').(! empty($r['body']['idempotent_replay']) ? 'replay' : ''), $responses);
sort($estados);

if ($sc === 'traspaso_doble_mismo_cobro') {
    if ($estados !== ['201 ', '409 transfer_blocked']) {
        $violations[] = 'se esperaba un 201 y un 409 transfer_blocked (hubo: '.implode(', ', $estados).')';
    }
    if ($vivos !== 1 || $filas !== 1) {
        $violations[] = "{$vivos} traspasos vivos y {$filas} filas del mismo cobro (esperado 1)";
    }
    if (! (($recibio($b1) && $intacto($b2)) || ($recibio($b2) && $intacto($b1)))) {
        $violations[] = 'la membresía no la tiene exactamente uno de los dos beneficiarios';
    }
    if ($trazas !== 2) {
        $violations[] = "{$trazas} trazas de traspaso (esperadas 2: titular y beneficiario)";
    }
    if ($a['fin'] !== $ayer) {
        $violations[] = "la titular termina el {$a['fin']} (esperado {$ayer})";
    }
} elseif ($sc === 'traspaso_misma_llave') {
    if ($estados !== ['200 replay', '201 ']) {
        $violations[] = 'se esperaba un 201 y un 200 idempotent_replay (hubo: '.implode(', ', $estados).')';
    }
    if ($filas !== 1 || $trazas !== 2 || ! $recibio($b1) || $a['fin'] !== $ayer) {
        $violations[] = "doble clic: {$filas} traspasos y {$trazas} trazas (esperado 1 y 2)";
    }
} elseif ($sc === 'traspaso_vs_congelar') {
    [$transferir, $congelar] = $responses;
    // Nunca las dos cosas: el traspaso vivo y la titular con días guardados,
    // que al reanudar le devolverían lo que ya cedió.
    if ($vivos > 0 && $a['congelada'] !== null) {
        $violations[] = "traspaso vivo y la titular congelada con {$a['dias_guardados']} días guardados: al reanudar recupera lo cedido";
    }
    if ($transferir['status'] === 201) {
        if ($congelar['status'] !== 422 || $a['fin'] !== $ayer || ! $recibio($b1)) {
            $violations[] = "ganó el traspaso pero congelar respondió {$congelar['status']} o la titular no termina ayer";
        }
    } elseif ($congelar['status'] === 201) {
        if ($transferir['status'] !== 409 || $filas !== 0 || ! $intacto($b1)) {
            $violations[] = 'ganó el congelamiento pero el traspaso no quedó bloqueado o escribió algo';
        }
    } else {
        $violations[] = 'ninguna de las dos operaciones se aplicó';
    }
} elseif ($sc === 'traspaso_vs_ajustar') {
    [$transferir, $ajustar] = $responses;
    $previoDelAjuste = $one("SELECT previous_end_date::date FROM membership_adjustments WHERE user_id = {$s['holder_id']} ORDER BY id DESC LIMIT 1");
    $report['db']['adjustment_previous_end'] = $previoDelAjuste === false ? null : $previoDelAjuste;
    // Nunca las dos cosas: el traspaso vivo y la titular con el fin cedido (o más).
    if ($vivos > 0 && $a['fin'] !== null && $a['fin'] >= $s['end']) {
        $violations[] = "traspaso vivo y la titular termina el {$a['fin']}: el ajuste se sumó sobre el fin que ya había cedido ({$s['end']})";
    }
    if ($transferir['status'] === 201) {
        if ($ajustar['status'] === 201 && $previoDelAjuste !== $ayer) {
            $violations[] = "el ajuste partió de {$previoDelAjuste}, no del fin que dejó el traspaso ({$ayer})";
        }
        if (! $recibio($b1)) {
            $violations[] = 'ganó el traspaso pero el beneficiario no tiene la membresía';
        }
    } elseif ($ajustar['status'] === 201) {
        if ($transferir['status'] !== 409 || $filas !== 0 || ! $intacto($b1)) {
            $violations[] = 'ganó el ajuste pero el traspaso no quedó bloqueado o escribió algo';
        }
    } else {
        $violations[] = 'ninguna de las dos operaciones se aplicó';
    }
} else {
    // Nunca las dos cosas: el cobro anulado con su periodo cedido y en uso.
    if ($estadoCobro === 'cancelled' && $vivos > 0) {
        $violations[] = 'el cobro quedó anulado con un traspaso vivo: B usa una membresía sin dinero detrás';
    }
    [$transferir, $anular] = $responses;
    if ($transferir['status'] === 201) {
        if ($anular['status'] !== 409 || ($anular['body']['code'] ?? null) !== 'membership_transferred' || $estadoCobro !== 'paid' || ! $recibio($b1)) {
            $violations[] = 'ganó el traspaso pero la anulación no respondió 409 membership_transferred o el cobro cambió';
        }
    } elseif ($anular['status'] === 200) {
        if ($transferir['status'] !== 409 || $filas !== 0 || ! $intacto($b1) || $estadoCobro !== 'cancelled') {
            $violations[] = 'ganó la anulación pero el traspaso no quedó bloqueado o escribió algo';
        }
    } else {
        $violations[] = 'ninguna de las dos operaciones se aplicó';
    }
}

$report['violations'] = $violations;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($violations !== [] ? 1 : ($overlap ? 0 : 3));
