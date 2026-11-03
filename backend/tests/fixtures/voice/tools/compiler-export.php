<?php

/*
 * EXPORTADOR de compiler-cases.json frente al COMPILADOR REAL (Enmienda 1 del contrato H8, E1-9: «TEST8-a lo aplica y
 * reexporta los hashes»). No es una prueba ni la ejecuta la suite: pasa cada caso por
 * App\Services\UltronStudio\StudioVoiceCompiler con la MISMA traducción de la entrada neutra del fixture que usa
 * tests/Unit/UltronStudio/StudioVoiceCompilerParityTest.php (S8-a) y escribe lo que da:
 *  - una compilación válida: specHash y la huella del perfil traducido (StudioVoiceCompiler::profileHash);
 *  - una negativa: el motivo y el campo del compilador y su traducción a los nombres del fixture;
 *  - una relación de huellas: la specHash de A y la de B.
 *
 * Uso (desde backend/):  php tests/fixtures/voice/tools/compiler-export.php [compiler-cases.json] > tests/fixtures/voice/tools/build/compiler-export.json
 * Después, python3 -I tests/fixtures/voice/tools/build/build.py incrusta esas huellas en expected.realCompiler.
 * Carga el autoload de Composer (las clases puras del Studio); no arranca Laravel, no toca la base ni la red.
 *
 * LA TRADUCCIÓN (la de S8-a, copiada sin cambiar nada):
 *  - versión: el contenido real del catálogo congelado (StudioCatalog::baseline() y legacyRules(), formato 2) con
 *    configs.persona.maxChars = 160 + 20 × variante, donde la variante es el orden de aparición de cada
 *    recomputedContentHash distinto, recorriendo compile en orden y después hashRelations (A y luego B); si
 *    contentHash ≠ recomputedContentHash, la huella guardada son 64 ceros;
 *  - perfil: los nombres del compilador (aiNotice, greetings.{inbound,outbound}, holes.{presentation,closing},
 *    transfer.destinations[{alias, phoneHash}], privacy.storeAudio, voicemail, format) y la huella de voz del número
 *    (HMAC-SHA256 con la subclave voice-phone de la APP_KEY sintética 00..1f); si storedHash no cuadra con el
 *    contenido neutro, la huella guardada son 64 ceros;
 *  - tope: ausente o vacío → 300; no entero → 0; recorte a 60–600 (DF-C6).
 */

declare(strict_types=1);

use App\Exceptions\UltronStudioException;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioVoiceCompiler as C;
use App\Services\UltronStudio\StudioVoiceTemplate as T;

$backend = dirname(__DIR__, 4);
require $backend.'/vendor/autoload.php';

$ruta = $argv[1] ?? dirname(__DIR__).'/compiler-cases.json';
$fixture = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);

const REASONS = [
    'profile_not_approved' => 'profile_not_approved', 'profile_four_eyes' => 'profile_not_approved',
    'version_not_approved' => 'version_not_approved', 'version_four_eyes' => 'version_not_approved',
    'profile_hash_mismatch' => 'profile_hash_mismatch', 'version_hash_mismatch' => 'version_hash_mismatch',
    'text_money' => 'slot_money', 'text_discount' => 'slot_discount', 'text_url' => 'slot_url', 'text_phone' => 'slot_phone',
    'text_email' => 'slot_email', 'text_invisible' => 'slot_invisible', 'text_characters' => 'slot_characters',
    'tool_not_in_catalog' => 'tool_not_in_catalog', 'system_tool_not_allowed' => 'system_tool_not_allowed',
    'end_call_required' => 'end_call_missing', 'opt_out_tool_required' => 'opt_out_tool_missing',
    'transfer_tools_mismatch' => 'transfer_requires_human_tool',
    'duration_exceeds_limit' => 'max_duration_exceeds_cap', 'recording_not_allowed' => 'recording_enabled',
    'retention_exceeds_limit' => 'retention_exceeds_limit', 'notice_missing' => 'notice_missing', 'notice_mismatch' => 'notice_missing',
    'profile_field_unknown' => 'profile_field_not_allowed', 'audio_retention_not_allowed' => 'audio_storage_enabled',
];
const REASONS_BY_FIELD = [
    'profile_field_invalid|profile.content.language' => 'language_not_allowed',
    'profile_field_invalid|profile.content.maxDurationSeconds' => 'max_duration_below_minimum',
    'profile_field_invalid|profile.content.voicemail' => 'voicemail_not_allowed',
    'transfer_destinations_invalid|profile.content.transfer.destinations' => 'too_many_transfer_destinations',
];
const FIELDS = [
    'profile.content.greetings.inbound' => 'greetingInbound', 'profile.content.greetings.outbound' => 'greetingOutbound',
    'profile.content.holes.presentation' => 'slots.presentacion', 'profile.content.holes.closing' => 'slots.cierre',
];

$variantes = [];

$cap = static function (?string $raw): int {
    $raw = $raw === null ? '' : trim($raw);
    $valor = $raw === '' ? 300 : (preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : 0);

    return min(C::MAX_DURATION_CEILING, max(C::MIN_DURATION_SECONDS, $valor));
};

$voicePhoneHash = static function (string $e164): string {
    $subclave = hash_hmac('sha256', 'voice-phone', implode('', array_map('chr', range(0, 31))), true);

    return hash_hmac('sha256', $e164, $subclave);
};

$version = static function (array $v) use (&$variantes): array {
    if ($v['catalogVersion'] !== StudioCatalog::VERSION) {
        throw new RuntimeException('catálogo distinto');
    }
    $variante = $variantes[$v['recomputedContentHash']] ??= count($variantes);
    $base = StudioCatalog::baseline();
    $configs = $base['configs'];
    $configs['persona']['maxChars'] = 160 + 20 * $variante;
    $reglas = array_map(static fn (array $r): array => [
        'code' => $r['code'], 'revision' => 2, 'source' => 'legacy_baseline', 'enforcement' => 'code', 'status' => $r['status'],
        'description' => $r['description'], 'type' => $r['type'], 'layers' => $r['layers'], 'priority' => $r['priority'],
        'refs' => $r['refs'], 'conditions' => $r['conditions'],
    ], StudioCatalog::legacyRules());
    $content = [
        'catalogVersion' => $v['catalogVersion'], 'format' => StudioContentHasher::FORMAT_2,
        'definition' => ['layers' => StudioCatalog::layerIds(), 'edgeIds' => $base['edgeIds']],
        'configs' => $configs, 'layout' => ['positions' => $base['positions'], 'viewport' => null], 'rules' => $reglas,
    ];
    $hash = StudioContentHasher::versionHash($content['catalogVersion'], $content, $reglas);

    return [
        'uuid' => $v['uuid'], 'status' => $v['status'],
        'contentHash' => $v['contentHash'] === $v['recomputedContentHash'] ? $hash : str_repeat('0', 64),
        'content' => $content, 'createdBy' => $v['createdBy'], 'approvedBy' => $v['approvedBy'],
    ];
};

$profile = static function (array $p) use ($voicePhoneHash): array {
    $suyo = $p['content'];
    $mapa = ['voiceId' => 'voiceId', 'language' => 'language', 'notice' => 'aiNotice', 'maxDurationSeconds' => 'maxDurationSeconds',
        'tools' => 'tools', 'systemTools' => 'systemTools', 'voicemailPolicy' => 'voicemail'];
    $c = ['format' => C::PROFILE_FORMAT];
    foreach ($suyo as $clave => $valor) {
        if (isset($mapa[$clave])) {
            $c[$mapa[$clave]] = $valor;
        } elseif ($clave === 'greetingInbound') {
            $c['greetings']['inbound'] = $valor;
        } elseif ($clave === 'greetingOutbound') {
            $c['greetings']['outbound'] = $valor;
        } elseif ($clave === 'slots') {
            $c['holes'] = ['presentation' => $valor['presentacion'], 'closing' => $valor['cierre']];
        } elseif ($clave === 'transferDestinations') {
            $c['transfer'] = ['destinations' => array_map(static fn (array $d): array => ['alias' => $d['alias'], 'phoneHash' => $voicePhoneHash($d['e164'])], $valor)];
        } elseif ($clave === 'privacy') {
            $c['privacy'] = ['retentionDays' => $valor['retentionDays'], 'storeAudio' => $valor['audioStorage'], 'recording' => $valor['recording']];
        } else {
            $c[$clave] = $valor;
        }
    }
    $cuadra = $p['storedHash'] === StudioContentHasher::hash($suyo);

    return ['uuid' => $p['uuid'], 'status' => $p['status'], 'contentHash' => $cuadra ? C::profileHash($c) : str_repeat('0', 64),
        'content' => $c, 'createdBy' => $p['createdBy'], 'approvedBy' => $p['approvedBy']];
};

$run = static function (array $input) use ($version, $profile, $cap): array {
    if ($input['owner'] !== ['recordingAllowed' => C::RECORDING_ALLOWED, 'maxRetentionDays' => C::MAX_RETENTION_DAYS]) {
        throw new RuntimeException('las decisiones del propietario del fixture no son las del compilador');
    }
    $v = $version($input['version']);
    $p = $profile($input['profile']);
    try {
        $r = C::compile($v, $p, $cap($input['env']['VOICE_PER_CALL_MAX_SECONDS'] ?? null));

        return ['compiles' => true, 'specHash' => $r['specHash'], 'voiceProfileHash' => $p['contentHash'],
            'firstMessage' => $r['spec']['firstMessage'], 'templateHash' => $r['spec']['template']['hash']];
    } catch (UltronStudioException $e) {
        $motivo = $e->payload['reason'];
        $campo = $e->payload['field'] ?? null;

        return ['compiles' => false, 'compilerReason' => $motivo, 'compilerField' => $campo,
            'reason' => REASONS_BY_FIELD["{$motivo}|{$campo}"] ?? REASONS[$motivo] ?? "sin-traduccion:{$motivo}",
            'field' => FIELDS[$campo] ?? $campo, 'http' => $e->status];
    }
};

$out = [
    'compiler' => [
        'class' => C::class, 'version' => C::VERSION,
        'compilerSha256' => hash_file('sha256', $backend.'/app/Services/UltronStudio/StudioVoiceCompiler.php'),
        'templateSha256' => hash_file('sha256', $backend.'/app/Services/UltronStudio/StudioVoiceTemplate.php'),
        'templateVersion' => T::VERSION, 'templateHash' => T::hash(), 'aiNotice' => T::AI_NOTICE,
        'php' => PHP_VERSION,
    ],
    'compile' => [],
    'hashRelations' => [],
];
foreach ($fixture['compile'] as $caso) {
    $out['compile'][$caso['name']] = ['inputSha256' => StudioContentHasher::hash($caso['input'])] + $run($caso['input']);
}
foreach ($fixture['hashRelations'] as $caso) {
    $a = $caso['inputA']['compilerVersion'] === C::VERSION ? $run($caso['inputA']) : ['notApplicable' => 'la versión del compilador es una constante'];
    $b = $caso['inputB']['compilerVersion'] === C::VERSION ? $run($caso['inputB']) : ['notApplicable' => 'la versión del compilador es una constante'];
    $out['hashRelations'][$caso['name']] = ['inputSha256A' => StudioContentHasher::hash($caso['inputA']), 'inputSha256B' => StudioContentHasher::hash($caso['inputB']),
        'a' => $a, 'b' => $b];
}
$out['variants'] = $variantes;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
