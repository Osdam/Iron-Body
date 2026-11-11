<?php

// Mutaciones de la declaración /2 del Studio (tarea 3 del tester): ¿las pruebas nuevas las cazan? Cada una se
// ejecuta contra las tres pruebas nuevas (MIAS) y, aparte, contra las de B-S (BS), para ver qué añaden las nuevas.
$DECL = 'app/Services/UltronStudio/StudioPublicationDeclaration.php';
$PUBS = 'app/Services/UltronStudio/StudioPublications.php';

$PARIDAD = ['sqlite', ['tests/Unit/UltronStudio/StudioPublicationV2ParityTest.php'], null];
$HTTP = ['sqlite', ['tests/Feature/UltronStudio/UltronStudioPublicationV2Test.php'], null];
$CARRERA = ['pgsql', ['tests/Feature/UltronStudio/UltronStudioPublicationV2RaceTest.php'], null];
$BS = ['sqlite', ['tests/Unit/UltronStudio/StudioPromptDeclarationTest.php', 'tests/Feature/UltronStudio/UltronStudioPublicationPromptsTest.php'], null];
$BS_PG = ['pgsql', ['tests/Feature/UltronStudio/UltronStudioPublicationPromptsRaceTest.php'], null];

$r10 = "        if (\$contract === self::CONTRACT_V2 && \$canary === null && \$rest === null) {\n            throw new InvalidArgumentException('Volver a Legacy se escribe siempre en '.self::CONTRACT.' (R10).');\n        }\n";

return [
    'V1' => ['Declaración: sin la guarda R10 (Legacy en /2)', [[$DECL, $r10, '']], [$PARIDAD, $BS]],
    'V2' => ['Declaración: sin la comprobación de tipos de governed/prompts', [[$DECL, 'if (gettype($parte[$clave][$id]) !== $tipo) {', 'if (false) {']], [$PARIDAD, $BS]],
    'V3' => ['Declaración: governed/prompts sin claves exactas (basta con que sean array)', [[$DECL, 'if (! self::hasExactKeys($parte[$clave], array_keys($tipos))) {', 'if (! is_array($parte[$clave])) {']], [$PARIDAD, $BS]],
    'V4' => ['Declaración: bundleHash sin el formato de 64 hex', [[$DECL, "        self::assertHex64(\$parte['prompts']['bundleHash'], \"{\$campo}.prompts.bundleHash\");\n", '']], [$PARIDAD, $BS]],
    'V5' => ['Declaración: el texto de una entrada /2 dice /1 (columna /2)', [[$DECL, "            'contract' => \$contract,\n            'entry' => \$entry,", "            'contract' => self::CONTRACT,\n            'entry' => \$entry,"]], [$PARIDAD, $BS]],
    'V6' => ['Clasificación: con la llave, los gobernados siguen en noEffect', [[$DECL, 'if ($promptsGoverned && isset(self::GOVERNED[$id])) {', 'if (false) {']], [$PARIDAD, $HTTP, $BS]],
    'V7' => ['previewHash: no ata el paquete (prompts ignorado)', [[$DECL, "        if (\$prompts !== null) {\n            if (! self::hasExactKeys(\$prompts", "        if (false) {\n            if (! self::hasExactKeys(\$prompts"]], [$PARIDAD, $HTTP, $BS]],
    'V8' => ['previewHash: no ata la parte arrastrada', [[$PUBS, "'carried' => \$p['carried'] === null ? null : ['bundleHash' => \$p['carried']['bundleHash'], 'version' => \$p['carried']['version']],", "'carried' => null,"]], [$HTTP, $BS]],
    'V9' => ['Publicar: sin la comprobación del arrastre en la transacción (publication_moved)', [[$PUBS, 'if (($vista === null) !== ($ahora === null)', 'if (false && ($vista === null) !== ($ahora === null)']], [$CARRERA, $BS, $BS_PG]],
    'V10' => ['Promover/revertir: no mira si las partes ya eran /2', [[$PUBS, '            if (! D::isPartV2($parte)) {', '            if (false) {']], [$HTTP, $BS]],
];
