<?php

// MUTACIONES DE LA CADENA DE PUBLICACIONES DEL HITO 5 (informe del tester, informes-h5/informe-tester-backend.md §6).
// Se ejecuta SIEMPRE sobre una COPIA del backend (git archive + vendor copiado + .env.testing + las pruebas sin
// confirmar), NUNCA sobre el worktree: otro agente puede estar corriendo pruebas en él (memoria «mutar en copia»).
//   php docs/ultron-studio/ops/h5-mutations.php <backend de la copia> <dir de salida> [ids…]
//   MUT_PG_DB=ultron_studio_…  base desechable de PostgreSQL (por defecto ultron_studio_h5_mut; tiene que existir)
//   MUT_CHECK=1                solo comprueba que cada cadena aparece exactamente una vez
// Cada mutación: una o más sustituciones EXACTAS (cada cadena, una sola vez), php -l, sus pruebas (SQLite y/o PG)
// y restauración en finally con sha256 comprobado. Antes, una línea base SIN mutar de cada ejecución: una mutación
// «cae» solo por rojos NUEVOS. Huella de app/, tests/, config/, database/ y routes/ antes y después: tiene que ser
// idéntica (sale 4 si no). Las cadenas son las de d98bbf9; si el código cambia, MUT_CHECK=1 lo dice.
declare(strict_types=1);

$base = rtrim($argv[1] ?? '', '/');
$out = rtrim($argv[2] ?? '', '/');
$pedidos = array_slice($argv, 3);
if (is_dir("$base/.git") || is_file("$base/.git") || is_dir("$base/../.git") || is_file("$base/../.git")) {
    fwrite(STDERR, "$base es un árbol de git: las mutaciones van en una copia (git archive), nunca en el worktree.\n");
    exit(2);
}
$pgDb = (string) (getenv('MUT_PG_DB') ?: 'ultron_studio_h5_mut');
if (! str_starts_with($pgDb, 'ultron_studio_')) {
    fwrite(STDERR, "MUT_PG_DB tiene que empezar por ultron_studio_\n");
    exit(2);
}
if (! is_dir("$base/app") || $out === '') {
    fwrite(STDERR, "uso: php mutate.php <backend> <salida> [ids…]\n");
    exit(2);
}
@mkdir($out, 0777, true);

$SP = 'app/Services/UltronStudio/StudioPublications.php';
$CFG = 'app/Services/Marketing/Ultron/UltronPublishedConfig.php';
$DECL = 'app/Services/UltronStudio/StudioPublicationDeclaration.php';
$PUBL = 'app/Services/UltronStudio/StudioPublishing.php';
$MEAS = 'app/Services/UltronStudio/StudioCanaryMeasures.php';
$AUD = 'app/Services/UltronStudio/StudioAudit.php';

$STUDIO = ['tests/Feature/UltronStudio', 'tests/Unit/UltronStudio'];
$RACE = 'tests/Feature/UltronStudio/UltronStudioPublicationRaceTest.php';
$sqlite = static fn (array $rutas, ?string $filtro = null): array => ['driver' => 'sqlite', 'paths' => $rutas, 'filter' => $filtro];
$pg = static fn (array $rutas, ?string $filtro = null): array => ['driver' => 'pgsql', 'paths' => $rutas, 'filter' => $filtro];

$M = [];

// ── Las que pide el lead ────────────────────────────────────────────────────
$M['A1'] = ['Firmar con OTRA subclave (la etiqueta del decide_token) en el Studio; el lector verifica con la suya', [[$SP,
    "UltronPublishedConfig::signatureFor(...));\n        \$yo = \$access->snapshot();",
    "static fn (string \$j): string => hash_hmac('sha256', UltronPublishedConfig::CONTRACT.\"\\n\".\$j, hash_hmac('sha256', 'ultron-decide-token', (string) config('app.key'), true)));\n        \$yo = \$access->snapshot();",
]], [$sqlite($STUDIO), $pg([$RACE], 'test_veinte_publicaciones_simultaneas_con_distintos|test_la_que_espera_el_seq|test_promover_contra_revertir_la_misma_canaria')]];

$M['A2'] = ['La subclave compartida derivada con otra etiqueta (firmante y lector de acuerdo, distintos del contrato)', [[$CFG,
    "private const KEY_LABEL = 'ultron-studio-publication';",
    "private const KEY_LABEL = 'ultron-studio-publication-v2';",
]], [$sqlite([...$STUDIO, 'tests/Unit/Marketing/UltronPublishedConfigTest.php'])]];

$M['B'] = ['seq = cabeza + 2', [[$SP,
    '$seq = ($cabeza !== null ? (int) $cabeza->seq : 0) + 1;',
    '$seq = ($cabeza !== null ? (int) $cabeza->seq : 0) + 2;',
]], [$sqlite($STUDIO), $pg([$RACE], 'test_veinte_publicaciones_simultaneas_con_distintos|test_promover_contra_revertir_la_misma_canaria')]];

$M['C1'] = ['Componer sin verificar la cabeza (sus partes, sin contrato, huella ni firma)', [[$SP,
    'return self::verify($cabeza) ?? throw UltronStudioException::publicationHeadInvalid();',
    'return D::parts((string) $cabeza->effective_json, (string) $cabeza->uuid, (int) $cabeza->seq);',
]], [$sqlite($STUDIO)]];

$M['C2'] = ['verify() del Studio sin comprobar la huella (contrato y firma sí)', [[$SP,
    "            || ! hash_equals(D::effectiveHash(\$json), (string) \$fila->effective_hash)\n",
    '',
]], [$sqlite($STUDIO)]];

$M['D'] = ['La evidencia cuenta turnos de cualquier conversación (sin filtro de la canaria)', [[$MEAS,
    "            ->where('conversation_id', \$conversacion)\n",
    '',
]], [$sqlite($STUDIO)]];

$M['E'] = ['evidenceHash ignorado al promover (se compara la evidencia consigo misma)', [[$SP,
    '$rechazo = StudioCanaryEvidence::refusal($evaluation, $sentEvidenceHash);',
    "\$rechazo = StudioCanaryEvidence::refusal(\$evaluation, \$evaluation['evidenceHash']);",
]], [$sqlite($STUDIO)]];

$M['F1'] = ['restore_integrity_mismatch desactivado (1): la entrada anterior a la promoción no se verifica', [[$SP,
    '$partes = $anterior !== null ? self::verify($anterior) : null;',
    '$partes = $anterior !== null ? D::parts((string) $anterior->effective_json, (string) $anterior->uuid, (int) $anterior->seq) : null;',
]], [$sqlite($STUDIO)]];

$M['F2'] = ['restore_integrity_mismatch desactivado (2): la versión que vuelve no se comprueba íntegra', [[$SP,
    "            || ! StudioVersions::integrityOk(\$vuelve)\n",
    '',
]], [$sqlite($STUDIO)]];

$M['G'] = ['La ventana de publicación leída en UTC en vez de Bogotá', [[$PUBL,
    '$local = CarbonImmutable::instance($now)->setTimezone(self::TIMEZONE);',
    "\$local = CarbonImmutable::instance(\$now)->setTimezone('UTC');",
]], [$sqlite($STUDIO)]];

$M['H'] = ['clientRequestId no ligado a la persona en el reintento', [[$SP,
    'if ((int) $previa->actor_admin_id !== $access->id() || $previa->action !== $action',
    'if ($previa->action !== $action',
]], [$sqlite($STUDIO)]];

$M['I1'] = ['previewHash de la vista previa con la versión del catálogo de HOY en vez de la de la versión', [[$SP,
    '$huella = D::previewHash((string) $version->uuid, (string) $version->content_hash, (string) $version->catalog_version, $clasificacion,',
    '$huella = D::previewHash((string) $version->uuid, (string) $version->content_hash, StudioCatalog::VERSION, $clasificacion,',
]], [$sqlite($STUDIO)]];

$M['I2'] = ['previewHash (núcleo) sin catalogVersion en el objeto', [[$DECL,
    "            'catalogVersion' => \$catalogVersion,\n            'mustMatch' => self::listOf(\$classification, 'mustMatch'),",
    "            'mustMatch' => self::listOf(\$classification, 'mustMatch'),",
]], [$sqlite($STUDIO)]];

$M['J1'] = ['Sin el tope de 16 385 caracteres al leer la cabeza en el Studio', [[$SP,
    "->selectRaw('substr(effective_json, 1, '.(D::MAX_EFFECTIVE_JSON + 1).') as effective_json');",
    "->selectRaw('effective_json');",
]], [$sqlite($STUDIO), $pg($STUDIO)]];

$M['J2'] = ['Sin el tope de 16 385 caracteres al leer la cabeza en el motor', [[$CFG,
    "->selectRaw('substr(effective_json, 1, '.(self::MAX_EFFECTIVE_JSON + 1).') as effective_json')",
    "->selectRaw('effective_json')",
]], [$sqlite([...$STUDIO, 'tests/Unit/Marketing/UltronPublishedConfigTest.php'])]];

// ── Las de las carreras (que las pruebas nuevas tengan dientes) ─────────────
$M['K1'] = ['Publicar: sin reintento tras el 23505', [[$SP,
    "            // FUERA de la transacción (en PostgreSQL un 23505 la deja abortada).\n            if (\$previa = \$this->replay(\$access, D::ACTION_PUBLISH, \$uuid, \$clientRequestId, \$cuerpo)) {\n                return ['entry' => \$previa, 'replayed' => true];\n            }\n",
    "            // FUERA de la transacción (en PostgreSQL un 23505 la deja abortada).\n",
]], [$pg([$RACE], 'test_el_doble_clic|test_veinte_sesiones_que_chocan')]];

$M['K2'] = ['El 23505 de usv_one_canary leído como publication_moved', [[$SP,
    "if (str_contains(\$mensaje, 'usv_one_canary') || (\$estado && \$action === D::ACTION_PUBLISH)) {\n            return UltronStudioException::canaryInProgress();",
    "if (str_contains(\$mensaje, 'usv_one_canary') || (\$estado && \$action === D::ACTION_PUBLISH)) {\n            return UltronStudioException::publicationMoved();",
]], [$pg([$RACE], 'test_el_indice_usv_one_canary|test_veinte_versiones_distintas')]];

$M['K3'] = ['Publicar lee la cabeza ANTES de abrir la transacción', [[$SP,
    "        try {\n            \$fila = DB::transaction(function () use (\$access, \$request, \$version, \$clientRequestId, \$h, \$acknowledged, \$note): StudioPublication {\n                \$ahora = now();\n                \$cabeza = \$this->head();",
    "        \$cabezaFuera = \$this->head();\n        try {\n            \$fila = DB::transaction(function () use (\$access, \$request, \$version, \$clientRequestId, \$h, \$acknowledged, \$note, \$cabezaFuera): StudioPublication {\n                \$ahora = now();\n                \$cabeza = \$cabezaFuera;",
]], [$pg([$RACE], 'test_publicar_encima_de_un_legacy|test_la_que_lee_la_cabeza_despues')]];

$M['K4'] = ['Legacy lee la CANARY y la PUBLISHED antes de la transacción', [
    [$SP,
        "        \$this->assertDeclaring(\$this->head());\n\n        try {\n            \$fila = DB::transaction(function () use (\$access, \$request, \$clientRequestId, \$reason): StudioPublication {",
        "        \$this->assertDeclaring(\$this->head());\n        \$canarioFuera = StudioVersion::query()->where('status', StudioVersionStatus::Canary->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']);\n        \$publicadaFuera = StudioVersion::query()->where('status', StudioVersionStatus::Published->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']);\n\n        try {\n            \$fila = DB::transaction(function () use (\$access, \$request, \$clientRequestId, \$reason, \$canarioFuera, \$publicadaFuera): StudioPublication {",
    ],
    [$SP,
        "                \$canario = StudioVersion::query()->where('status', StudioVersionStatus::Canary->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']);\n                \$publicada = StudioVersion::query()->where('status', StudioVersionStatus::Published->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']);\n                \$fila = \$this->insertEntry(D::ACTION_LEGACY",
        "                \$canario = \$canarioFuera;\n                \$publicada = \$publicadaFuera;\n                \$fila = \$this->insertEntry(D::ACTION_LEGACY",
    ],
], [$pg([$RACE], 'test_volver_a_legacy_justo_despues')]];

$M['K5'] = ['Publicar sin transacción (entrada, transición y libro en autocommit)', [
    [$SP,
        '            $fila = DB::transaction(function () use ($access, $request, $version, $clientRequestId, $h, $acknowledged, $note): StudioPublication {',
        '            $fila = (function () use ($access, $request, $version, $clientRequestId, $h, $acknowledged, $note): StudioPublication {',
    ],
    [$SP,
        "                return \$fila;\n            });\n        } catch (UniqueConstraintViolationException \$e) {\n            // FUERA de la transacción",
        "                return \$fila;\n            })();\n        } catch (UniqueConstraintViolationException \$e) {\n            // FUERA de la transacción",
    ],
], [$pg([$RACE], 'test_el_indice_usv_one_canary|test_la_que_espera_el_seq')]];

$M['K6'] = ['recordDenied sin READ COMMITTED (M7 del informe, contra las pruebas nuevas)', [[$AUD,
    "                DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');\n",
    '',
]], [$pg([$RACE], 'test_record_denied|test_veinte_intentos_simultaneos_de_la_autora')]];

$M['K7'] = ['Promover elige la PUBLISHED que sustituye ANTES de la transacción', [
    [$SP,
        "        try {\n            \$fila = DB::transaction(function () use (\$access, \$request, \$version, \$clientRequestId, \$evidencia, \$note): StudioPublication {",
        "        \$anteriorFuera = StudioVersion::query()->where('status', StudioVersionStatus::Published->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']);\n        try {\n            \$fila = DB::transaction(function () use (\$access, \$request, \$version, \$clientRequestId, \$evidencia, \$note, \$anteriorFuera): StudioPublication {",
    ],
    [$SP,
        "                \$anterior = StudioVersion::query()->where('status', StudioVersionStatus::Published->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']);\n                \$fila = \$this->insertEntry(D::ACTION_PROMOTE",
        "                \$anterior = \$anteriorFuera;\n                \$fila = \$this->insertEntry(D::ACTION_PROMOTE",
    ],
], [$pg([$RACE], 'test_promover_justo_despues_de_revertir')]];

// Comprobación en seco: cada cadena, una sola vez (y aplicadas en orden).
if (getenv('MUT_CHECK') === '1') {
    $mal = 0;
    foreach ($M as $id => [$titulo, $cambios]) {
        $copias = [];
        foreach ($cambios as [$fichero, $buscar, $poner]) {
            $copias[$fichero] ??= file_get_contents("$base/$fichero");
            $n = substr_count($copias[$fichero], $buscar);
            echo "$id $fichero: $n\n";
            $mal += $n === 1 ? 0 : 1;
            $copias[$fichero] = str_replace($buscar, $poner, $copias[$fichero]);
        }
    }
    exit($mal === 0 ? 0 : 1);
}

// ── Ejecución ───────────────────────────────────────────────────────────────
$huella = static function () use ($base): string {
    $lista = [];
    foreach (['app', 'tests', 'config', 'database', 'routes'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$base/$dir", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $lista[substr($f->getPathname(), strlen($base) + 1)] = hash_file('sha256', $f->getPathname());
            }
        }
    }
    ksort($lista);

    return hash('sha256', json_encode($lista));
};

$correr = static function (array $run, string $junit) use ($base, $pgDb): array {
    $args = ['--do-not-cache-result', '--log-junit', $junit];
    if ($run['filter'] !== null) {
        $args[] = '--filter';
        $args[] = $run['filter'];
    }
    $args = [...$args, ...$run['paths']];
    if ($run['driver'] === 'pgsql') {
        $cmd = ['bash', "$base/docs/ultron-studio/ops/pgunit.sh", ...$args];
        $env = ['UNIT_DB' => $pgDb, 'PGGSSENCMODE' => 'disable'];
    } else {
        $cmd = [PHP_BINARY, '-d', 'memory_limit=2G', "$base/vendor/bin/phpunit", ...$args];
        $env = [];
    }
    $t0 = microtime(true);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $base, $env + getenv());
    $salida = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    $rc = proc_close($p);
    $resumen = '';
    foreach (array_reverse(explode("\n", trim($salida))) as $l) {
        if (preg_match('/^(OK|Tests:|FAILURES|ERRORS)/', $l)) {
            $resumen = $l.($resumen !== '' ? ' | '.$resumen : '');
        }
        if (str_starts_with($l, 'Time:')) {
            break;
        }
    }
    $fallos = [];
    if (is_file($junit)) {
        $doc = new SimpleXMLElement(file_get_contents($junit));
        foreach ($doc->xpath('//testcase[failure or error]') as $tc) {
            $fallos[] = (string) $tc['class'].'::'.(string) $tc['name'];
        }
    }
    sort($fallos);

    return ['driver' => $run['driver'], 'filter' => $run['filter'], 'paths' => $run['paths'], 'rc' => $rc, 'summary' => $resumen, 'failed' => $fallos, 'seconds' => round(microtime(true) - $t0, 1)];
};

$antes = $huella();
file_put_contents("$out/huella-antes.txt", $antes."\n");
$ids = $pedidos === [] ? array_keys($M) : $pedidos;

// Línea base SIN mutación de cada ejecución distinta: una mutación «cae» solo
// por los rojos NUEVOS (las pruebas de H-1 ya están en rojo sin mutar nada).
$lineaBase = [];
foreach ($ids as $id) {
    foreach ($M[$id][2] as $run) {
        $clave = md5(json_encode($run));
        if (isset($lineaBase[$clave])) {
            continue;
        }
        $fichero = "$out/base-$clave.json";
        if (is_file($fichero)) {
            $lineaBase[$clave] = json_decode(file_get_contents($fichero), true);

            continue;
        }
        $lineaBase[$clave] = $correr($run, "$out/junit-base-$clave.xml");
        file_put_contents($fichero, json_encode($lineaBase[$clave], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo "[base] {$run['driver']} ".($run['filter'] ?? implode(' ', $run['paths'])).": {$lineaBase[$clave]['summary']} · rojos: ".count($lineaBase[$clave]['failed'])."\n";
    }
}

foreach ($ids as $id) {
    [$titulo, $cambios, $runs] = $M[$id] ?? throw new RuntimeException("mutación desconocida: $id");
    $originales = [];
    $registro = ['id' => $id, 'title' => $titulo, 'files' => [], 'lint' => [], 'runs' => [], 'restored' => false];
    try {
        foreach ($cambios as [$fichero, $buscar, $poner]) {
            $ruta = "$base/$fichero";
            $originales[$ruta] ??= [file_get_contents($ruta), hash_file('sha256', $ruta)];
            $actual = file_get_contents($ruta);
            $n = substr_count($actual, $buscar);
            if ($n !== 1) {
                throw new RuntimeException("$id: la cadena aparece $n veces en $fichero");
            }
            file_put_contents($ruta, str_replace($buscar, $poner, $actual));
            $registro['files'][$fichero][] = ['search' => $buscar, 'replace' => $poner];
        }
        foreach (array_keys($originales) as $ruta) {
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($ruta).' 2>&1', $l, $rc);
            $registro['lint'][substr($ruta, strlen($base) + 1)] = $rc === 0 ? 'ok' : implode(' ', $l);
            $l = [];
        }
        foreach ($runs as $k => $run) {
            $r = $correr($run, "$out/junit-$id-$k.xml");
            $r['new'] = array_values(array_diff($r['failed'], $lineaBase[md5(json_encode($run))]['failed']));
            $registro['runs'][] = $r;
        }
    } finally {
        foreach ($originales as $ruta => [$contenido, $sha]) {
            file_put_contents($ruta, $contenido);
            if (hash_file('sha256', $ruta) !== $sha) {
                fwrite(STDERR, "RESTAURACIÓN FALLIDA en $ruta\n");
                exit(3);
            }
            $registro['restoredSha'][substr($ruta, strlen($base) + 1)] = $sha;
        }
        $registro['restored'] = true;
    }
    file_put_contents("$out/mutaciones.jsonl", json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
    $cayeron = array_sum(array_map(static fn (array $r): int => count($r['new'] ?? []), $registro['runs']));
    echo "[$id] {$titulo}: ".($cayeron > 0 ? "CAE ($cayeron)" : 'SOBREVIVE').' · '.implode(' · ', array_map(static fn (array $r): string => "{$r['driver']} {$r['summary']} ({$r['seconds']} s)", $registro['runs']))."\n";
}
$despues = $huella();
file_put_contents("$out/huella-despues.txt", $despues."\n");
echo "huella antes  $antes\nhuella después $despues\n".($antes === $despues ? "IDÉNTICA\n" : "DISTINTA\n");
exit($antes === $despues ? 0 : 4);
