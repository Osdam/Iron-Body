<?php

// Mutaciones de la Enmienda M-1 (paso 5b del plan de motores). $base = backend de la copia (lo define mutar.php).
$OFFER = 'app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php';
$CTRL = 'app/Http/Controllers/Api/Internal/UltronController.php';
$COMP = 'app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php';
$GEN = 'app/Services/Marketing/Ultron/Prompts/UltronPromptsN8nFlow.php';
$SELJS = '../n8n/flujos/ultron-studio-s1/elegir-prompts.js';
$FLUJO = '../n8n/flujos/ultron-studio-s1/flujo-s1.json';
$PUBS = 'app/Services/UltronStudio/StudioPublications.php';
$DECL = 'app/Services/UltronStudio/StudioPublicationDeclaration.php';

$copia = dirname($base);
$PROMPTS_UNIT = ['sqlite', ['tests/Unit/Marketing/Ultron/Prompts'], null];
$DECIDE = ['sqlite', ['tests/Feature/Marketing/UltronDecidePromptsTest.php'], null];
$COMMIT = ['sqlite', ['tests/Feature/Marketing/UltronCommitPromptsTest.php'], null];
$N8N = ['sqlite', ['tests/Feature/Marketing/UltronPromptsN8nCommandTest.php'], null];
$MIO = ['sqlite', ['tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompileCasesTest.php'], null];
$STUDIO = ['sqlite', ['tests/Unit/UltronStudio/StudioPromptDeclarationTest.php', 'tests/Feature/UltronStudio/UltronStudioPublicationPromptsTest.php', 'tests/Feature/UltronStudio/UltronStudioPublicationRollbackTest.php'], null];
$NODE_FLUJO = ['node', ["$base/docs/ultron-studio/ops/motores/verificar-flujo.cjs", "$copia/n8n/flujos/ultron-studio-s1/flujo-s1.json"], null];
$NODE_SEL_JS = ['node', ["$base/tests/fixtures/ultron-prompts/tools/verificar-selector.cjs", "$copia/n8n/flujos/ultron-studio-s1/elegir-prompts.js"], null];
$NODE_SEL_FLUJO = ['node', ["$base/tests/fixtures/ultron-prompts/tools/verificar-selector.cjs", "$copia/n8n/flujos/ultron-studio-s1/flujo-s1.json"], null];

// El try principal del selector, en sus tres formas (JS, JSON escapado y el generador PHP).
$tryJs = ["roles = LEGACY, observed = null;\ntry {", "roles = LEGACY, observed = null;\n{"];
$catchJs = ['} catch (e) { mode = "legacy"; roles = LEGACY; reason = "selector_error"; observed = null; }', '}'];
$tryJson = ['roles = LEGACY, observed = null;\\ntry {', 'roles = LEGACY, observed = null;\\n{'];
$catchJson = ['} catch (e) { mode = \\"legacy\\"; roles = LEGACY; reason = \\"selector_error\\"; observed = null; }', '}'];

$paso7 = "        if (! is_string(\$firmada) || ! hash_equals(\$paquete['bundleHash'], \$firmada)) {\n            return self::legacy(self::BUNDLE_MISMATCH);\n        }\n";
$r10 = "        if (\$contract === self::CONTRACT_V2 && \$canary === null && \$rest === null) {\n            throw new InvalidArgumentException('Volver a Legacy se escribe siempre en '.self::CONTRACT.' (R10).');\n        }\n";
$legacyV2 = ['self::notApplicableLabGate(), null, null, $reason, null);', 'self::notApplicableLabGate(), null, null, $reason, null, D::CONTRACT_V2);'];
$moverV2 = ['if ($vivas === [] || StudioEngineReadings::promptsKey() !== StudioEngineReadings::PROMPTS_CANARY) {', 'if (StudioEngineReadings::promptsKey() !== StudioEngineReadings::PROMPTS_CANARY) {'];

return [
    // ── Las cinco del plan (5b) ─────────────────────────────────────────────
    'M1' => ['Oferta: sin la comparación de huella (paso 7)', [[$OFFER, $paso7, '']], [$DECIDE, $PROMPTS_UNIT]],
    'M1b' => ['Oferta: el paso 7 solo mira que la huella firmada sea texto', [[$OFFER, "! is_string(\$firmada) || ! hash_equals(\$paquete['bundleHash'], \$firmada)", '! is_string($firmada)']], [$DECIDE]],
    'M2' => ["Commit: sin 'prompts' en unexpectedKeys", [[$CTRL, "foreach (['proposal', 'critic', 'recovery', 'prompts'] as \$bloque)", "foreach (['proposal', 'critic', 'recovery'] as \$bloque)"]], [$COMMIT]],
    'M3a' => ['Selector: sin el try principal, solo en el artefacto elegir-prompts.js', [[$SELJS, ...$tryJs], [$SELJS, ...$catchJs]], [$NODE_SEL_JS, $MIO, $N8N]],
    'M3b' => ['Selector: sin el try principal, solo en el jsCode de flujo-s1.json', [[$FLUJO, ...$tryJson], [$FLUJO, ...$catchJson]], [$NODE_FLUJO, $NODE_SEL_FLUJO, $N8N]],
    'M3c' => ['Selector: sin el try en el .js Y en el flujo (coherentes entre sí)', [[$SELJS, ...$tryJs], [$SELJS, ...$catchJs], [$FLUJO, ...$tryJson], [$FLUJO, ...$catchJson]], [$NODE_FLUJO, $NODE_SEL_JS, $MIO, $N8N]],
    'M3d' => ['Selector: sin el try en el GENERADOR (UltronPromptsN8nFlow)', [[$GEN, ...$tryJs], [$GEN, ...$catchJs]], [$N8N, $MIO]],
    'M4' => ['Compilador: sin la unicidad del ancla (solo exige que exista)', [[$COMP, '        if ($veces !== 1) {', '        if ($veces === 0) {']], [$PROMPTS_UNIT]],
    'M4b' => ['Compilador: sin ninguna comprobación del ancla', [[$COMP, '        if ($veces !== 1) {', '        if (false) {']], [$PROMPTS_UNIT]],
    'M5a' => ['Studio: «volver a Legacy» pide /2 (la guarda R10 sigue)', [[$PUBS, ...$legacyV2]], [$STUDIO]],
    'M5b' => ['Studio: «volver a Legacy» escribe /2 (sin la guarda R10)', [[$PUBS, ...$legacyV2], [$DECL, $r10, '']], [$STUDIO]],
    'M5c' => ['Studio: sin la guarda R10 de effectiveJson (nadie pide /2 aún)', [[$DECL, $r10, '']], [$STUDIO]],
    'M5d' => ['Studio: revertir la última canaria (sin partes) escribe /2 sin la guarda R10', [[$PUBS, ...$moverV2], [$DECL, $r10, '']], [$STUDIO]],
    // ── Extra: los dientes de la prueba nueva (compile-cases) ──────────────
    'X1' => ['Compilador: un texto de parche sin su punto final', [[$COMP, "Sigue siendo calido; profesional no es frio.'", "Sigue siendo calido; profesional no es frio'"]], [$MIO, $PROMPTS_UNIT]],
    'X2' => ['Compilador: emojis se aplica antes que tone', [[$COMP, 'public const PARAMETERS = [self::TONE, self::EMOJIS, self::MAX_CHARS, self::GOAL];', 'public const PARAMETERS = [self::EMOJIS, self::TONE, self::MAX_CHARS, self::GOAL];']], [$MIO, $PROMPTS_UNIT]],
    'X3' => ['Compilador: maxChars escribe N − 20', [[$COMP, "str_replace('{N}', (string) \$valor, \$plantilla)", "str_replace('{N}', (string) (\$valor - 20), \$plantilla)"]], [$MIO, $PROMPTS_UNIT]],
];
