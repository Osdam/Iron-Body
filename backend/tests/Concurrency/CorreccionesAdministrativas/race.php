<?php

/**
 * Carreras REALES de las correcciones administrativas contra PostgreSQL.
 *
 * Cada petición es un PROCESO aparte (worker.php) con su propia conexión. Para
 * que la carrera cuente, una conexión propia retiene en PG el cerrojo de la fila
 * crítica (barrera) y solo lo suelta cuando pg_blocking_pids ve bloqueadas TODAS
 * las peticiones (o vence el plazo). Las peticiones salen escalonadas 40 ms.
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

$motivo = 'Carrera: corrección simultánea';
$sc = $s['scenario'];
$requests = match ($sc) {
    // Dos supervisores anulan el MISMO cobro a la vez.
    'pago_doble_anulacion' => [
        ['method' => 'PATCH', 'uri' => "/api/payments/{$s['payment_id']}", 'body' => ['status' => 'cancelled', 'reason' => $motivo], 'label' => 'anular-1'],
        ['method' => 'PATCH', 'uri' => "/api/payments/{$s['payment_id']}", 'body' => ['status' => 'cancelled', 'reason' => $motivo], 'label' => 'anular-2'],
    ],
    // Doble clic en «Cobrar» (o dos mostradores) sobre el mismo pedido.
    'venta_doble_cobro' => [
        ['method' => 'POST', 'uri' => "/api/admin/caja/sales/{$s['sale_id']}/pay", 'body' => ['payment_method' => 'cash'], 'label' => 'cobrar-1'],
        ['method' => 'POST', 'uri' => "/api/admin/caja/sales/{$s['sale_id']}/pay", 'body' => ['payment_method' => 'cash'], 'label' => 'cobrar-2'],
    ],
    // Dos supervisores devuelven 2 de las 3 unidades a la vez: juntas pasan de lo vendido.
    'venta_devoluciones_simultaneas' => [
        ['method' => 'POST', 'uri' => "/api/admin/caja/sales/{$s['sale_id']}/refund", 'body' => ['reason' => $motivo, 'items' => [['item_id' => $s['item_id'], 'quantity' => 2]]], 'label' => 'devolver-1'],
        ['method' => 'POST', 'uri' => "/api/admin/caja/sales/{$s['sale_id']}/refund", 'body' => ['reason' => $motivo, 'items' => [['item_id' => $s['item_id'], 'quantity' => 2]]], 'label' => 'devolver-2'],
    ],
    // Un mostrador cobra el pedido mientras un supervisor lo cancela.
    'venta_anular_vs_cobrar' => [
        ['method' => 'POST', 'uri' => "/api/admin/caja/sales/{$s['sale_id']}/pay", 'body' => ['payment_method' => 'cash'], 'label' => 'cobrar'],
        ['method' => 'POST', 'uri' => "/api/admin/caja/sales/{$s['sale_id']}/cancel", 'body' => ['reason' => $motivo], 'label' => 'anular'],
    ],
};

// La barrera: la fila que TODAS las versiones del código bloquean en ese camino
// (el socio al anular un cobro; el producto al descontar stock).
[$barrierSql, $blockedLike] = $sc === 'pago_doble_anulacion'
    ? ["SELECT id FROM users WHERE id = {$s['user_id']} FOR UPDATE", ['%from "users"%']]
    : ["SELECT id FROM products WHERE id = {$s['product_id']} FOR UPDATE", ['%from "products"%', '%from "product_sales"%']];

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
$likeSql = implode(' OR ', array_map(fn ($l) => 'query ILIKE '.$watch->quote($l), $blockedLike));
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
    'scenario' => $sc, 'requests' => $expectedBlocked, 'max_blocked_seen' => $maxBlocked, 'overlap' => $overlap,
    'barrier_held_ms' => (int) round(($releasedAt - $startAt) * 1000),
    'responses' => array_map(fn ($r) => sprintf('%s → %d %s%s', $r['label'], $r['status'],
        $r['body']['code'] ?? '', isset($r['body']['already']) && $r['body']['already'] ? ' (already)' : ''), $responses),
];
$violations = [];
$one = fn (string $sql) => $q->query($sql)->fetchColumn();
if (count(array_filter($responses, fn ($r) => $r['status'] >= 500 || $r['status'] <= 0)) > 0) {
    $violations[] = 'hubo respuestas 5xx o el proceso murió';
}

if ($sc === 'pago_doble_anulacion') {
    $estado = $one("SELECT status FROM payments WHERE id = {$s['payment_id']}");
    $trazas = (int) $one("SELECT count(*) FROM audit_logs WHERE entity = 'pago' AND entity_id = '{$s['payment_id']}' AND action <> 'create'");
    $dias = (int) $one("SELECT count(*) FROM audit_logs WHERE entity = 'membership' AND entity_id = '{$s['user_id']}'");
    $fin = (string) $one("SELECT membership_end_date FROM users WHERE id = {$s['user_id']}");
    $report['db'] = ['status' => $estado, 'audit_rows' => $trazas, 'membership_audit_rows' => $dias, 'end_before' => $s['end_before'], 'end_after' => $fin];
    if ($estado !== 'cancelled') {
        $violations[] = "el cobro quedó «{$estado}»";
    }
    if ($trazas !== 1) {
        $violations[] = "{$trazas} trazas de anulación para UNA anulación (esperada 1)";
    }
    if ($dias !== 1) {
        $violations[] = "{$dias} devoluciones de días registradas (esperada 1)";
    }
    if ($fin !== $s['end_after_expected']) {
        $violations[] = "la membresía terminó en {$fin}; tras devolver UNA vez el periodo debía terminar en {$s['end_after_expected']}";
    }
} else {
    $venta = $q->query("SELECT status, cancelled_at, payment_status FROM product_sales WHERE id = {$s['sale_id']}")->fetch(PDO::FETCH_ASSOC);
    $stock = (int) $one("SELECT stock FROM products WHERE id = {$s['product_id']}");
    $salidas = (int) $one("SELECT coalesce(sum(quantity), 0) FROM inventory_movements WHERE product_id = {$s['product_id']} AND type = 'out'");
    $entradas = (int) $one("SELECT coalesce(sum(quantity), 0) FROM inventory_movements WHERE product_id = {$s['product_id']} AND type = 'in'");
    $report['db'] = ['sale' => $venta, 'stock' => $stock, 'units_out' => $salidas, 'units_in' => $entradas];

    if ($stock !== $s['stock_before'] - $salidas + $entradas) {
        $violations[] = 'el stock no cuadra con sus movimientos';
    }
    if ($sc === 'venta_devoluciones_simultaneas') {
        $devuelto = (int) $one("SELECT refunded_quantity FROM product_sale_items WHERE id = {$s['item_id']}");
        $salidasCaja = (int) $one("SELECT count(*) FROM cash_refunds WHERE refundable_id = {$s['sale_id']}");
        $codigos = array_map(fn ($r) => $r['status'].' '.($r['body']['code'] ?? ''), $responses);
        sort($codigos);
        $report['db'] += ['refunded_quantity' => $devuelto, 'cash_refunds' => $salidasCaja];
        if ($codigos !== ['201 ', '422 refund_exceeds_sold']) {
            $violations[] = 'se esperaba una devolución aceptada y la otra 422 refund_exceeds_sold (hubo: '.implode(', ', $codigos).')';
        }
        if ($devuelto !== 2 || $salidasCaja !== 1 || $entradas !== 2 || $stock !== $s['stock_before'] - 3 + 2) {
            $violations[] = "devueltas {$devuelto} unidades con {$salidasCaja} salidas de caja (de 3 vendidas, cabían 2 una vez)";
        }
    } elseif ($sc === 'venta_doble_cobro') {
        if ($venta['status'] !== 'paid' || $salidas !== 1 || $stock !== $s['stock_before'] - 1) {
            $violations[] = "un pedido de 1 unidad cobrado dos veces sacó {$salidas} unidades (stock {$stock})";
        }
    } else {
        $anular = $responses[1];
        $dijoAnulada = $anular['status'] === 200 && ($anular['body']['data']['status'] ?? null) === 'cancelled';
        if ($dijoAnulada && $venta['status'] !== 'cancelled') {
            $violations[] = "se respondió «anulada» y la venta quedó «{$venta['status']}»";
        }
        if ($venta['status'] === 'cancelled' && $stock !== $s['stock_before']) {
            $violations[] = "venta anulada con {$stock} de stock (esperado {$s['stock_before']}: el producto vuelve)";
        }
        if ($venta['status'] === 'paid' && ($venta['cancelled_at'] !== null || $stock !== $s['stock_before'] - 1)) {
            $violations[] = 'venta cobrada con marca de anulación o stock incoherente';
        }
    }
}

$report['violations'] = $violations;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($violations !== [] ? 1 : ($overlap ? 0 : 3));
