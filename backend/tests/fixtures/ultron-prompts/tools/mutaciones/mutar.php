<?php

// Ejecutor de mutaciones SOBRE UNA COPIA del backend (nunca el worktree).
//   php mutar.php <backend de la copia> <spec.php> <dir de salida> [ids…]
// spec.php devuelve: id => [descripción, [[fichero, desde, hasta], …], [[driver, [rutas], filtro|null], …]]
// Cada `desde` tiene que aparecer EXACTAMENTE una vez. Línea base sin mutar por cada ejecución distinta;
// una mutación «cae» solo si aparecen rojos NUEVOS respecto de su línea base. Restauración en finally con
// sha256 comprobado; huella de app/ tests/ config/ database/ routes/ resources/ antes y después.
declare(strict_types=1);

$base = rtrim($argv[1] ?? '', '/');
$spec = $argv[2] ?? '';
$out = rtrim($argv[3] ?? '', '/');
$pedidos = array_slice($argv, 4);
if ($base === '' || ! is_dir("$base/app") || ! is_file($spec) || $out === '') {
    fwrite(STDERR, "uso: php mutar.php <backend> <spec.php> <salida> [ids…]\n");
    exit(2);
}
if (str_contains(realpath($base), '/_worktrees/') || is_dir("$base/.git") || is_file("$base/.git") || is_dir("$base/../.git") || is_file("$base/../.git")) {
    fwrite(STDERR, "$base parece un árbol de git: las mutaciones van en una copia.\n");
    exit(2);
}
@mkdir($out, 0777, true);
$M = require $spec;
$pgDb = (string) (getenv('MUT_PG_DB') ?: 'ultron_studio_tg_mut');

function huella(string $base): string
{
    $h = hash_init('sha256');
    foreach (['app', 'tests', 'config', 'database', 'routes', 'resources', '../n8n/flujos'] as $d) {
        if (! is_dir("$base/$d")) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$base/$d", FilesystemIterator::SKIP_DOTS));
        $fs = [];
        foreach ($it as $f) {
            if ($f->isFile()) {
                $fs[] = $f->getPathname();
            }
        }
        sort($fs);
        foreach ($fs as $f) {
            hash_update($h, $f."\0".hash_file('sha256', $f)."\n");
        }
    }

    return hash_final($h);
}

/** @return array{fallos: list<string>, total: int, salida: string, codigo: int} */
function ejecuta(string $base, string $out, string $etiqueta, array $run, string $pgDb): array
{
    [$driver, $rutas, $filtro] = $run;
    if ($driver === 'node') {
        // Una comprobación de Node: «pasa» = sale con 0. $rutas = argumentos (rutas absolutas en la copia).
        $p = proc_open(array_merge(['nice', '-n', '10', 'node'], $rutas), [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname($base));
        $salida = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $codigo = proc_close($p);
        file_put_contents("$out/$etiqueta.txt", $salida);

        return ['fallos' => $codigo === 0 ? [] : ['node '.basename($rutas[0]).' → salida '.$codigo.': '.trim(substr(trim($salida), 0, 160))], 'total' => 1, 'salida' => $salida, 'codigo' => $codigo];
    }
    $junit = "$out/$etiqueta.junit.xml";
    @unlink($junit);
    $args = ['--no-logging', '--log-junit', $junit, '--do-not-cache-result'];
    if ($filtro !== null) {
        $args[] = '--filter';
        $args[] = $filtro;
    }
    $args = array_merge($args, $rutas);
    if ($driver === 'pgsql') {
        $cmd = array_merge(['env', 'PGGSSENCMODE=disable', 'UNIT_DB='.$pgDb, 'nice', '-n', '10', 'bash', 'docs/ultron-studio/ops/pgunit.sh'], $args);
    } else {
        $cmd = array_merge(['nice', '-n', '10', 'php', '-d', 'memory_limit=2G', 'vendor/bin/phpunit'], $args);
    }
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $base);
    $salida = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $codigo = proc_close($p);
    file_put_contents("$out/$etiqueta.txt", $salida);
    $fallos = [];
    $total = 0;
    if (is_file($junit)) {
        $xml = simplexml_load_file($junit);
        foreach ($xml->xpath('//testcase') as $tc) {
            $total++;
            if (count($tc->failure) + count($tc->error) > 0) {
                $fallos[] = (string) $tc['class'].'::'.(string) $tc['name'];
            }
        }
    }
    sort($fallos);

    return ['fallos' => array_values(array_unique($fallos)), 'total' => $total, 'salida' => $salida, 'codigo' => $codigo];
}

$antes = huella($base);
$lineasBase = [];
$informe = [];
foreach ($M as $id => [$desc, $cambios, $runs]) {
    if ($pedidos !== [] && ! in_array((string) $id, $pedidos, true)) {
        continue;
    }
    // Comprobación de las cadenas (antes de tocar nada).
    $originales = [];
    foreach ($cambios as [$fichero, $desde, $hasta]) {
        $ruta = "$base/$fichero";
        $contenido = $originales[$fichero] ?? (string) file_get_contents($ruta);
        $originales[$fichero] ??= $contenido;
        $n = substr_count($contenido, $desde);
        if ($n !== 1) {
            fwrite(STDERR, "[$id] «".substr($desde, 0, 80)."» aparece $n veces en $fichero\n");
            exit(3);
        }
    }
    // Líneas base (una por ejecución distinta).
    foreach ($runs as $run) {
        $clave = md5(json_encode($run));
        if (! isset($lineasBase[$clave])) {
            $lineasBase[$clave] = ejecuta($base, $out, 'base-'.$clave, $run, $pgDb);
            printf("  línea base %s %s: %d pruebas, %d rojos%s\n", $run[0], implode(',', array_map('basename', $run[1])), $lineasBase[$clave]['total'], count($lineasBase[$clave]['fallos']), $lineasBase[$clave]['fallos'] ? ' → '.implode(', ', $lineasBase[$clave]['fallos']) : '');
            if ($lineasBase[$clave]['total'] === 0) {
                fwrite(STDERR, 'línea base sin pruebas: '.substr($lineasBase[$clave]['salida'], -2000)."\n");
                exit(4);
            }
        }
    }
    $sha = array_map(static fn (string $c): string => hash('sha256', $c), $originales);
    $resultado = ['id' => $id, 'desc' => $desc, 'runs' => []];
    try {
        $mutados = $originales;
        foreach ($cambios as [$fichero, $desde, $hasta]) {
            $mutados[$fichero] = str_replace($desde, $hasta, $mutados[$fichero]);
        }
        foreach ($mutados as $fichero => $c) {
            file_put_contents("$base/$fichero", $c);
            if (str_ends_with($fichero, '.php')) {
                exec('php -l '.escapeshellarg("$base/$fichero").' 2>&1', $lint, $rc);
                if ($rc !== 0) {
                    throw new RuntimeException("php -l falla en $fichero: ".implode("\n", $lint));
                }
            }
        }
        foreach ($runs as $k => $run) {
            $clave = md5(json_encode($run));
            $r = ejecuta($base, $out, "mut-$id-$k", $run, $pgDb);
            $nuevos = array_values(array_diff($r['fallos'], $lineasBase[$clave]['fallos']));
            $resultado['runs'][] = ['run' => $run[0].' '.implode(',', array_map('basename', $run[1])).($run[2] ? ' --filter '.$run[2] : ''), 'total' => $r['total'], 'nuevos' => $nuevos, 'codigo' => $r['codigo']];
            if ($r['total'] === 0) {
                $resultado['runs'][array_key_last($resultado['runs'])]['nota'] = 'sin pruebas (¿no carga?): '.substr($r['salida'], -600);
            }
        }
    } finally {
        foreach ($originales as $fichero => $c) {
            file_put_contents("$base/$fichero", $c);
            if (hash_file('sha256', "$base/$fichero") !== $sha[$fichero]) {
                fwrite(STDERR, "¡NO SE RESTAURÓ $fichero!\n");
                exit(5);
            }
        }
    }
    $cae = false;
    foreach ($resultado['runs'] as $r) {
        $cae = $cae || $r['nuevos'] !== [];
    }
    $resultado['cae'] = $cae;
    $informe[] = $resultado;
    printf("[%s] %s → %s\n", $id, $desc, $cae ? 'CAE' : 'SOBREVIVE');
    foreach ($resultado['runs'] as $r) {
        printf("     %s: %d pruebas; rojos nuevos: %s%s\n", $r['run'], $r['total'], $r['nuevos'] ? implode(', ', $r['nuevos']) : '—', isset($r['nota']) ? ' · '.$r['nota'] : '');
    }
}
file_put_contents("$out/informe.json", json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$despues = huella($base);
printf("huella antes %s\nhuella después %s → %s\n", $antes, $despues, $antes === $despues ? 'IDÉNTICA' : 'DISTINTA');
exit($antes === $despues ? 0 : 6);
