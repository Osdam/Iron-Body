<?php

/*
 * Escribe tests/Unit/Outreach/Core/fixtures/trace-text-cases.json (L9-25) desde
 * la implementación de B9-core. Uso, desde backend/:
 *
 *   php tests/Unit/Outreach/Core/tools/export-trace-text-cases.php
 *
 * DecisionTraceAndPurityTest falla mientras el fichero no sea el export vigente.
 */

use Tests\Unit\Outreach\Core\TraceTextCases;

require __DIR__.'/../../../../../vendor/autoload.php';

$json = TraceTextCases::json();
$directorio = dirname(TraceTextCases::PATH);
if (! is_dir($directorio) && ! mkdir($directorio, 0755, true)) {
    fwrite(STDERR, "No se pudo crear {$directorio}.\n");
    exit(1);
}
if (file_put_contents(TraceTextCases::PATH, $json) === false) {
    fwrite(STDERR, 'No se pudo escribir '.TraceTextCases::PATH.".\n");
    exit(1);
}
fwrite(STDOUT, 'trace-text-cases.json: '.strlen($json).' bytes, sha256 '.hash('sha256', $json)."\n");
