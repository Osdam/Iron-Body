<?php

/** Local artifact generator and export comparator. No application bootstrap or network. */
require dirname(__DIR__, 4).'/vendor/autoload.php';

use App\Services\UltronStudio\StudioListN8nFlow;

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (! preg_match('/\A--(s2|output|verify)=(.+)\z/', $argument, $match) || isset($options[$match[1]])) {
            throw new InvalidArgumentException('Uso: php generar-flujo-s3.php [--s2=export-local.json] [--output=artefacto.json | --verify=export-local-s3.json]');
        }
        $options[$match[1]] = $match[2];
    }
    if (isset($options['output'], $options['verify'])) {
        throw new InvalidArgumentException('output y verify son alternativas.');
    }
    $source = StudioListN8nFlow::read($options['s2'] ?? StudioListN8nFlow::referencePath());
    $expected = StudioListN8nFlow::build($source);
    if (isset($options['verify'])) {
        $actual = StudioListN8nFlow::read($options['verify']);
        $differences = StudioListN8nFlow::integrationDifferences($actual, $expected);
        fwrite(STDOUT, json_encode([
            'ok' => $differences === [], 'flowRev' => StudioListN8nFlow::REV,
            'sourceSemanticHash' => StudioListN8nFlow::semanticHash($source),
            'expectedSemanticHash' => StudioListN8nFlow::semanticHash($expected),
            'differences' => $differences,
            'runtimeVerified' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        exit($differences === [] ? 0 : 1);
    }
    $json = json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    if (! isset($options['output'])) {
        fwrite(STDOUT, $json);
        exit(0);
    }
    $directory = dirname($options['output']);
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException('No se pudo crear el directorio del artefacto.');
    }
    if (file_put_contents($options['output'], $json) === false) {
        throw new RuntimeException('No se pudo escribir el artefacto local.');
    }
    fwrite(STDOUT, json_encode(['ok' => true, 'flowRev' => StudioListN8nFlow::REV, 'active' => false, 'nodes' => count($expected['nodes']), 'semanticHash' => StudioListN8nFlow::semanticHash($expected)], JSON_UNESCAPED_SLASHES)."\n");
} catch (Throwable $exception) {
    // The supplied export may contain secrets. Never print its values or code.
    fwrite(STDERR, ($exception instanceof InvalidArgumentException ? $exception->getMessage() : 'No se pudo generar o comprobar el artefacto local.')."\n");
    exit(2);
}
