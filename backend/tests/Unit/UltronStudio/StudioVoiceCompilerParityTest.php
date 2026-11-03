<?php

namespace Tests\Unit\UltronStudio;

use App\Exceptions\UltronStudioException;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioVoiceCompiler as C;
use App\Services\UltronStudio\StudioVoiceTemplate as T;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PARIDAD del compilador de la voz con `tests/fixtures/voice/compiler-cases.json`
 * (TEST8-a, escrito desde el contrato H8 sin mirar ninguna implementación).
 *
 * El fixture propone una forma NEUTRA de entrada y unos nombres de motivo
 * («si S8 usa otros nombres, la prueba traduce; el caso no cambia»). Esta
 * prueba traduce la entrada (perfil, versión, tope del entorno) y la salida
 * (motivo y campo) y compara caso a caso.
 *
 * EL AVISO. El fixture fija la regla (el aviso del perfil es EXACTAMENTE el
 * aprobado) con la redacción de P-9; la Enmienda 1 (E1-3) la sustituyó. El
 * aviso aprobado del fixture se traduce al de la plantilla solo si es ese texto
 * de P-9, que se lee del contrato; cualquier otro (vacío, con una palabra
 * cambiada) llega tal cual.
 *
 * NADA SE SALTA EN SILENCIO. Los casos que dependen de algo que el compilador
 * no tiene ({@see NOT_APPLICABLE}) y los que discrepan por una decisión que
 * tiene que tomar el lead ({@see CONFLICTS}) están aquí por su nombre, y cada
 * uno COMPRUEBA su discrepancia exacta: si cambia el fixture o el compilador,
 * la prueba falla y obliga a reclasificarlo. El informe S8 los explica.
 */
class StudioVoiceCompilerParityTest extends TestCase
{
    /** La `APP_KEY` sintética del fixture (bytes 00..1f) y la etiqueta de la huella de voz (D8-17). */
    private const VOICE_PHONE_LABEL = 'voice-phone';

    /** Motivo del compilador → motivo del fixture (los que solo dependen del motivo). */
    private const REASONS = [
        'profile_not_approved' => 'profile_not_approved',
        'profile_four_eyes' => 'profile_not_approved',
        'version_not_approved' => 'version_not_approved',
        'version_four_eyes' => 'version_not_approved',
        'profile_hash_mismatch' => 'profile_hash_mismatch',
        'version_hash_mismatch' => 'version_hash_mismatch',
        'text_money' => 'slot_money',
        'text_discount' => 'slot_discount',
        'text_url' => 'slot_url',
        'text_phone' => 'slot_phone',
        'text_email' => 'slot_email',
        'text_invisible' => 'slot_invisible',
        'tool_not_in_catalog' => 'tool_not_in_catalog',
        'system_tool_not_allowed' => 'system_tool_not_allowed',
        'end_call_required' => 'end_call_missing',
        'duration_exceeds_limit' => 'max_duration_exceeds_cap',
        'recording_not_allowed' => 'recording_enabled',
        'retention_exceeds_limit' => 'retention_exceeds_limit',
        'notice_missing' => 'notice_missing',
        'notice_mismatch' => 'notice_missing',
        'profile_field_unknown' => 'profile_field_not_allowed',
        'audio_retention_not_allowed' => 'audio_storage_enabled',
    ];

    /** Los que dependen del campo: `profile_field_invalid` y los destinos. */
    private const REASONS_BY_FIELD = [
        'profile_field_invalid|profile.content.language' => 'language_not_allowed',
        'profile_field_invalid|profile.content.maxDurationSeconds' => 'max_duration_below_minimum',
        'transfer_destinations_invalid|profile.content.transfer.destinations' => 'too_many_transfer_destinations',
    ];

    /** Campo del compilador → campo del fixture (solo los textos lo llevan). */
    private const FIELDS = [
        'profile.content.greetings.inbound' => 'greetingInbound',
        'profile.content.greetings.outbound' => 'greetingOutbound',
        'profile.content.holes.presentation' => 'slots.presentacion',
        'profile.content.holes.closing' => 'slots.cierre',
    ];

    /** Lo que el compilador no tiene, y por qué (cada caso lo comprueba). */
    private const NOT_APPLICABLE = [
        'compile · negative · la versión nombra un destino (Gerencia) que el perfil no tiene' => 'handoffAliases: ningún parámetro del catálogo congelado nombra destinos; R-07 se aplica al desplegar (assertDestinationsMatch)',
        'hashRelations · specHash · otra versión del compilador → otra huella' => 'la versión del compilador es una constante del código: no es una entrada',
    ];

    /** Las discrepancias que decide el lead (cada caso comprueba la suya). */
    private const CONFLICTS = [
        'compile · valid · emoji legítimos en un slot: corazón con U+FE0F y familia con ZWJ (InvisibleCharacters los deja pasar)' => 'text_characters',
        'compile · valid · política de buzón leave_message [P-10]' => 'profile_field_invalid',
        'compile · valid · solo dos herramientas del catálogo' => 'transfer_tools_mismatch',
        'compile · negative · dos campos con problemas distintos (URL en el saludo de entrada, cifra en un slot): manda el motivo, slot_money, y su campo' => 'text_url',
        'hashRelations · specHash · otro uuid de perfil y de versión (mismo contenido) → la misma huella' => 'distinta',
        'hashRelations · specHash · una herramienta menos → otra huella' => 'opt_out_tool_required',
    ];

    private static ?array $fixture = null;

    /** @var array<string,int> contentHash del fixture → variante de contenido real */
    private static array $variantes = [];

    private static function fixture(): array
    {
        return self::$fixture ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/voice/compiler-cases.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function casos(): iterable
    {
        foreach (['compile', 'hashRelations', 'canonicalHash', 'capClamp'] as $seccion) {
            foreach (self::fixture()[$seccion] as $caso) {
                yield $caso['name'] => [$caso + ['section' => $seccion]];
            }
        }
    }

    public function test_el_fixture_esta_bien_formado_y_las_listas_nombran_casos_que_existen(): void
    {
        $f = self::fixture();
        $this->assertSame('compiler-cases.json', $f['fixture']);
        $this->assertSame(C::VERSION, $f['compilerVersion']);
        $this->assertSame(C::APPROVED_VERSION_STATES, $f['closedSets']['approvedVersionStatuses']);
        $this->assertEqualsCanonicalizing(array_keys(T::SERVER_TOOLS), $f['closedSets']['catalogTools']);
        $this->assertSame(array_keys(T::SYSTEM_TOOLS), $f['closedSets']['systemToolsAllowed']);

        $nombres = [];
        foreach (['compile', 'hashRelations', 'canonicalHash', 'capClamp'] as $seccion) {
            $nombres = [...$nombres, ...array_column($f[$seccion], 'name')];
        }
        $this->assertSame(count($nombres), count(array_unique($nombres)), 'nombres repetidos');
        $this->assertSame($f['counts']['total'], count($nombres));
        foreach ([...array_keys(self::NOT_APPLICABLE), ...array_keys(self::CONFLICTS)] as $nombre) {
            $this->assertContains($nombre, $nombres, "la lista nombra un caso que el fixture ya no tiene: {$nombre}");
        }
        // El aviso aprobado del fixture es el de la plantilla (E1-3) o, hasta que TEST8-a reexporte
        // (Enmienda 1, E1-9), EXACTAMENTE el de P-9 que E1-3 sustituyó. Ningún otro.
        $this->assertNotSame('', self::supersededP9Notice(), 'el contrato ya no tiene el texto de P-9 en su sitio');
        $this->assertContains(self::fixtureNotice(), [T::AI_NOTICE, self::supersededP9Notice()]);

        // Cada negativa trae un motivo o una lista de motivos admitidos (DF-C11), nunca las dos cosas.
        foreach ($f['compile'] as $caso) {
            if ($caso['expected']['compiles'] === false) {
                $this->assertTrue(isset($caso['expected']['reason']) !== isset($caso['expected']['reasonOneOf']), $caso['name']);
            }
        }
        // Todos los motivos del fixture tienen traducción.
        $traducidos = [...array_values(self::REASONS), ...array_values(self::REASONS_BY_FIELD), 'transfer_destination_not_in_profile'];
        $this->assertSame([], array_values(array_diff($f['closedSets']['reasonsInOrder'], $traducidos)));
    }

    #[DataProvider('casos')]
    public function test_el_php_reproduce_el_caso(array $caso): void
    {
        match ($caso['section']) {
            'compile' => $this->compileCase($caso),
            'hashRelations' => $this->hashRelation($caso),
            'canonicalHash' => $this->canonicalHash($caso),
            'capClamp' => $this->capClamp($caso),
        };
    }

    // ── Secciones ───────────────────────────────────────────────────────────

    private function compileCase(array $caso): void
    {
        $nombre = $caso['name'];
        if (isset(self::NOT_APPLICABLE[$nombre])) {
            // La versión nombra un alias que el perfil no tiene: el compilador no lee eso y compila.
            $alias = array_column($caso['input']['profile']['content']['transferDestinations'], 'alias');
            $this->assertNotSame([], array_diff($caso['input']['version']['handoffAliases'], $alias));
            $this->assertArrayHasKey('specHash', $this->compileInput($caso['input']));

            return;
        }

        $resultado = $this->outcome($caso['input']);
        if (isset(self::CONFLICTS[$nombre])) {
            $this->assertFalse($resultado['compiles'], "{$nombre}: el conflicto cambió (ahora compila)");
            $this->assertSame(self::CONFLICTS[$nombre], $resultado['myReason'], "{$nombre}: el conflicto cambió");

            return;
        }

        $esperado = $caso['expected'];
        if ($esperado['compiles']) {
            $this->assertTrue($resultado['compiles'], "{$nombre}: se negó con ".($resultado['myReason'] ?? '?').' en '.($resultado['myField'] ?? '?'));
            $this->invariants($esperado['invariants'], $resultado['compiled'], $caso['input']);

            return;
        }

        $this->assertFalse($resultado['compiles'], "{$nombre}: compiló");
        $this->assertSame(422, $esperado['http']);
        // DF-C11: donde el contrato no fija el detector, vale cualquiera de los motivos de la lista.
        $admitidos = isset($esperado['reasonOneOf']) ? $esperado['reasonOneOf'] : [$esperado['reason']];
        $this->assertContains($resultado['reason'], $admitidos, "{$nombre}: el compilador dio {$resultado['myReason']} en {$resultado['myField']}");
        if (isset($esperado['field'])) {
            $this->assertSame($esperado['field'], $resultado['field'], $nombre);
        }
    }

    private function hashRelation(array $caso): void
    {
        $nombre = $caso['name'];
        if (isset(self::NOT_APPLICABLE[$nombre])) {
            $this->assertNotSame(C::VERSION, $caso['inputB']['compilerVersion']);
            $this->assertSame(C::VERSION, $caso['inputA']['compilerVersion']);

            return;
        }

        if (isset(self::CONFLICTS[$nombre]) && self::CONFLICTS[$nombre] !== 'distinta') {
            $this->assertSame(self::CONFLICTS[$nombre], $this->outcome($caso['inputB'])['myReason'], "{$nombre}: el conflicto cambió");

            return;
        }

        $a = $this->compileInput($caso['inputA'])['specHash'];
        $b = $this->compileInput($caso['inputB'])['specHash'];
        if (isset(self::CONFLICTS[$nombre])) {
            // Los uuids están en `source`: la huella identifica también el origen.
            $this->assertTrue($caso['expected']['sameSpecHash']);
            $this->assertNotSame($a, $b, "{$nombre}: el conflicto cambió");

            return;
        }
        $this->assertSame($caso['expected']['sameSpecHash'], $a === $b, $nombre);
    }

    private function canonicalHash(array $caso): void
    {
        if (isset($caso['expected']['throws'])) {
            $this->assertSame('InvalidArgumentException', $caso['expected']['throws']);
            $this->expectException(InvalidArgumentException::class);
            StudioContentHasher::canonicalJson($caso['value']);

            return;
        }
        $this->assertSame($caso['expected']['canonicalJson'], StudioContentHasher::canonicalJson($caso['value']));
        $this->assertSame($caso['expected']['sha256'], StudioContentHasher::hash($caso['value']));
    }

    /**
     * El recorte del tope es de la configuración (B8, `config/voice.php`): el
     * compilador recibe el tope ya recortado. Aquí se comprueba la traducción
     * que usa esta prueba y que el compilador admite cada tope recortado.
     */
    private function capClamp(array $caso): void
    {
        $tope = self::cap($caso['raw'] ?? null);
        $this->assertSame($caso['expected']['capSeconds'], $tope);
        $perfil = $this->profile(self::baseInput()['profile']);
        $perfil = self::withDuration($perfil, min(300, $tope));
        $this->assertSame(64, strlen(C::compile($this->version(self::baseInput()['version']), $perfil, $tope)['specHash']));
    }

    // ── Traducción ──────────────────────────────────────────────────────────

    /** @return array{compiles:bool,compiled?:array,reason?:string,field?:string|null,myReason?:string,myField?:string|null} */
    private function outcome(array $input): array
    {
        try {
            return ['compiles' => true, 'compiled' => $this->compileInput($input)];
        } catch (UltronStudioException $e) {
            $this->assertSame(C::ERROR_CODE, $e->errorCode);
            $this->assertSame(422, $e->status);
            $motivo = $e->payload['reason'];
            $campo = $e->payload['field'] ?? null;
            $suyo = self::REASONS_BY_FIELD["{$motivo}|{$campo}"] ?? self::REASONS[$motivo] ?? "sin-traduccion:{$motivo}";

            return ['compiles' => false, 'reason' => $suyo, 'field' => self::FIELDS[$campo] ?? $campo, 'myReason' => $motivo, 'myField' => $campo];
        }
    }

    private function compileInput(array $input): array
    {
        $this->assertSame(['recordingAllowed' => C::RECORDING_ALLOWED, 'maxRetentionDays' => C::MAX_RETENTION_DAYS], $input['owner'], 'las decisiones del propietario del fixture son las del compilador');

        return C::compile($this->version($input['version']), $this->profile($input['profile']), self::cap($input['env']['VOICE_PER_CALL_MAX_SECONDS'] ?? null));
    }

    /** La versión del fixture (sin contenido) → una versión real: cada huella distinta del fixture, una variante del contenido. */
    private function version(array $v): array
    {
        $this->assertSame(StudioCatalog::VERSION, $v['catalogVersion']);
        $variante = self::$variantes[$v['recomputedContentHash']] ??= count(self::$variantes);
        $base = StudioCatalog::baseline();
        $configs = $base['configs'];
        $configs['persona']['maxChars'] = 160 + 20 * $variante;   // otro contenido, el mismo prompt
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
            'uuid' => $v['uuid'],
            'status' => $v['status'],
            // La huella guardada que no cuadra con la recalculada, también aquí.
            'contentHash' => $v['contentHash'] === $v['recomputedContentHash'] ? $hash : str_repeat('0', 64),
            'content' => $content,
            'createdBy' => $v['createdBy'],
            'approvedBy' => $v['approvedBy'],
        ];
    }

    /** El perfil del fixture → el del compilador (los nombres, los huecos y la huella del teléfono en vez del número). */
    private function profile(array $p): array
    {
        $suyo = $p['content'];
        $mapa = [
            'voiceId' => 'voiceId', 'language' => 'language', 'maxDurationSeconds' => 'maxDurationSeconds',
            'tools' => 'tools', 'systemTools' => 'systemTools', 'voicemailPolicy' => 'voicemail',
        ];
        $c = ['format' => C::PROFILE_FORMAT];
        foreach ($suyo as $clave => $valor) {
            if ($clave === 'notice') {
                // El aviso APROBADO del fixture es el de la plantilla (E1-3 sustituye la redacción de P-9; la regla
                // «exactamente el aprobado» no cambia). Cualquier otro texto, tal cual.
                $c['aiNotice'] = self::notice($valor);
            } elseif (isset($mapa[$clave])) {
                $c[$mapa[$clave]] = $valor;
            } elseif ($clave === 'greetingInbound') {
                $c['greetings']['inbound'] = $valor;
            } elseif ($clave === 'greetingOutbound') {
                $c['greetings']['outbound'] = $valor;
            } elseif ($clave === 'slots') {
                $c['holes'] = ['presentation' => $valor['presentacion'], 'closing' => $valor['cierre']];
            } elseif ($clave === 'transferDestinations') {
                $c['transfer'] = ['destinations' => array_map(static fn (array $d): array => ['alias' => $d['alias'], 'phoneHash' => self::voicePhoneHash($d['e164'])], $valor)];
            } elseif ($clave === 'privacy') {
                $c['privacy'] = ['retentionDays' => $valor['retentionDays'], 'storeAudio' => $valor['audioStorage'], 'recording' => $valor['recording']];
            } else {
                $c[$clave] = $valor;   // un campo que no es del conjunto: tal cual
            }
        }

        // La huella guardada que no cuadra con su contenido (el fixture la calcula sobre SU contenido), también aquí.
        $cuadra = $p['storedHash'] === StudioContentHasher::hash($suyo);

        return [
            'uuid' => $p['uuid'],
            'status' => $p['status'],
            'contentHash' => $cuadra ? C::profileHash($c) : str_repeat('0', 64),
            'content' => $c,
            'createdBy' => $p['createdBy'],
            'approvedBy' => $p['approvedBy'],
        ];
    }

    /** El aviso del fixture traducido: el aprobado es el de la plantilla (E1-3); cualquier otro texto, tal cual. */
    private static function notice(mixed $delFixture): mixed
    {
        return $delFixture === self::fixtureNotice() ? T::AI_NOTICE : $delFixture;
    }

    /** El aviso aprobado del fixture: el del perfil de su caso base. */
    private static function fixtureNotice(): string
    {
        return self::fixture()['compile'][0]['input']['profile']['content']['notice'];
    }

    /** El texto de P-9 de la congelación, que E1-3 sustituyó (se lee del contrato). */
    private static function supersededP9Notice(): string
    {
        $contrato = (string) file_get_contents(dirname(__DIR__, 3).'/docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md');
        preg_match('/\| P-9 \| Texto provisional del aviso al empezar cada llamada: «([^»]+)»/u', $contrato, $m);

        return $m[1] ?? '';
    }

    /** DF-C6: ausente o vacío → 300; no entero → 0; después, el recorte a 60–600. */
    private static function cap(?string $raw): int
    {
        $raw = $raw === null ? '' : trim($raw);
        $valor = $raw === '' ? 300 : (preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : 0);

        return min(C::MAX_DURATION_CEILING, max(C::MIN_DURATION_SECONDS, $valor));
    }

    /** La huella de voz del fixture: HMAC-SHA256 con la subclave `voice-phone` de la APP_KEY sintética (00..1f). */
    private static function voicePhoneHash(string $e164): string
    {
        $subclave = hash_hmac('sha256', self::VOICE_PHONE_LABEL, implode('', array_map('chr', range(0, 31))), true);

        return hash_hmac('sha256', $e164, $subclave);
    }

    /** Las invariantes de una compilación válida, traducidas a la especificación. */
    private function invariants(array $inv, array $compilado, array $input): void
    {
        $s = $compilado['spec'];
        $this->assertSame($inv['compilerVersion'], $s['compilerVersion']);
        $this->assertSame($inv['language'], $s['language']);
        $this->assertSame($inv['voiceId'], $s['voiceId']);
        $this->assertSame($inv['maxDurationSeconds'], $s['maxDurationSeconds']);
        $this->assertSame($inv['tools'], array_column($s['tools'], 'name'));
        $this->assertSame($inv['systemTools'], array_column($s['systemTools'], 'name'));
        // Los destinos, por alias y con la HUELLA del número: el número no está en la especificación.
        $this->assertSame(array_column($inv['transferDestinations'], 'alias'), array_column($s['transfer']['destinations'], 'alias'));
        $this->assertSame(array_map(static fn (array $d): string => self::voicePhoneHash($d['e164']), $inv['transferDestinations']), array_column($s['transfer']['destinations'], 'ref'));
        $this->assertSame($inv['recordingEnabled'], $s['privacy']['recording']);
        $this->assertFalse($inv['twilioRecordingEnabled']);   // es de la carga del proveedor (B8)
        $this->assertSame($inv['retentionDays'], $s['privacy']['retentionDays']);
        $this->assertSame($inv['audioStorage'], $s['privacy']['storeAudio']);
        foreach ($inv['dynamicVariableDefaults'] as $nombre => $defecto) {
            $this->assertSame($defecto, $s['dynamicVariables'][$nombre]);
        }
        $this->assertSame($inv['conversationConfigOverrideAllowed'], $s['overridesAllowed']);
        // Un solo primer mensaje, el aviso, en los dos sentidos...
        $this->assertStringStartsWith(self::notice($inv['firstMessageInboundStartsWith']), $s['firstMessage']);
        $this->assertStringStartsWith(self::notice($inv['firstMessageOutboundStartsWith']), $s['firstMessage']);
        // ...y el saludo de cada sentido en el prompt, en su rama (diseño de S8: informe, «primer mensaje»).
        $this->assertStringContainsString('«'.$inv['firstMessageInboundContains'].'»', $s['systemPrompt']);
        $this->assertStringContainsString('«'.$inv['firstMessageOutboundContains'].'»', $s['systemPrompt']);
        $this->assertTrue($inv['promptHasNoMoneyFigures']);
        $this->assertSame(0, preg_match(T::PRICE_PATTERN, $s['systemPrompt']));
        // Más estricto que el fixture: ningún teléfono en la especificación, tampoco los de los destinos.
        $json = StudioContentHasher::canonicalJson($s);
        foreach ($inv['phonesOnlyInTransferDestinations'] as $e164) {
            $this->assertStringNotContainsString($e164, $json);
            $this->assertStringNotContainsString(substr($e164, 3), $json);
        }
        $this->assertTrue($inv['specHashIsSha256OfCanonicalSpec']);
        $this->assertSame(hash('sha256', $json), $compilado['specHash']);
        $this->assertTrue($inv['payloadHashIsSha256OfCanonicalPayload']);   // la carga es del mapper (B8)
        $this->assertSame(self::cap($input['env']['VOICE_PER_CALL_MAX_SECONDS'] ?? null) >= $s['maxDurationSeconds'], true);
    }

    private static function baseInput(): array
    {
        return self::fixture()['compile'][0]['input'];
    }

    private static function withDuration(array $perfil, int $segundos): array
    {
        $perfil['content']['maxDurationSeconds'] = $segundos;
        $perfil['contentHash'] = C::profileHash($perfil['content']);

        return $perfil;
    }
}
