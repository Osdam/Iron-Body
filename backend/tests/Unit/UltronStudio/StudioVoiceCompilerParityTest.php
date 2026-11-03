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
 * (TEST8-a, escrito desde el contrato H8 y alineado con este compilador por la
 * Enmienda 1, E1-9; esta prueba, por la Enmienda 2, E2-9).
 *
 * El fixture usa una forma NEUTRA de entrada y sus propios nombres de motivo:
 * esta prueba traduce la entrada (perfil, versión, tope del entorno) y la
 * salida (motivo y campo) y compara caso a caso. La traducción es la misma que
 * usa `tests/fixtures/voice/tools/compiler-export.php`, que escribió las
 * huellas reales en `expected.realCompiler` (DF-C12: una instantánea de
 * regresión): por eso las variantes de versión se leen de la cabecera
 * (`realCompilerExport.versionVariants`) y no dependen del orden de ejecución.
 *
 * Cada caso se comprueba DOS veces: contra lo que dice el contrato (`reason`,
 * `field`, las invariantes, `sameSpecHash`) y contra la instantánea (la huella
 * de la especificación y del perfil, y el motivo y el campo del compilador).
 *
 * NADA SE SALTA EN SILENCIO. Los casos que dependen de algo que el compilador
 * no tiene ({@see NOT_APPLICABLE}) están aquí por su nombre y cada uno
 * comprueba por qué. Una discrepancia que tenga que decidir el lead se fija en
 * {@see CONFLICTS} con el motivo exacto del compilador; con este fixture la
 * lista está vacía.
 */
class StudioVoiceCompilerParityTest extends TestCase
{
    /** La `APP_KEY` sintética del fixture (bytes 00..1f) y la etiqueta de la huella de voz (D8-17). */
    private const VOICE_PHONE_LABEL = 'voice-phone';

    /** Motivo del compilador → motivo del fixture (los que solo dependen del motivo). La tabla de `compiler-export.php`. */
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
        'text_characters' => 'slot_characters',
        'tool_not_in_catalog' => 'tool_not_in_catalog',
        'system_tool_not_allowed' => 'system_tool_not_allowed',
        'end_call_required' => 'end_call_missing',
        'opt_out_tool_required' => 'opt_out_tool_missing',
        'transfer_tools_mismatch' => 'transfer_requires_human_tool',
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
        'profile_field_invalid|profile.content.voicemail' => 'voicemail_not_allowed',
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

    /**
     * Las discrepancias que decide el lead: nombre del caso → el motivo exacto
     * del compilador. Vacía desde la Enmienda 1 (E1-9) y la reexportación del
     * fixture.
     *
     * @var array<string,string>
     */
    private const CONFLICTS = [];

    private static ?array $fixture = null;

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

        // El aviso aprobado del fixture es el de la plantilla (E1-3), y la instantánea es de esta plantilla.
        $this->assertSame(T::AI_NOTICE, self::baseInput()['profile']['content']['notice']);
        $exportado = $f['realCompilerExport']['compiler'];
        $this->assertSame([C::VERSION, T::VERSION, T::hash(), T::AI_NOTICE], [$exportado['version'], $exportado['templateVersion'], $exportado['templateHash'], $exportado['aiNotice']]);

        // Cada negativa trae UN motivo (DF-C11 ya no hace falta) y cada caso de compilar o de huellas, su instantánea.
        foreach ($f['compile'] as $caso) {
            $this->assertArrayHasKey('realCompiler', $caso['expected'], $caso['name']);
            if ($caso['expected']['compiles'] === false) {
                $this->assertIsString($caso['expected']['reason'], $caso['name']);
                $this->assertArrayNotHasKey('reasonOneOf', $caso['expected'], $caso['name']);
            }
        }
        foreach ($f['hashRelations'] as $caso) {
            $this->assertArrayHasKey('realCompiler', $caso['expected'], $caso['name']);
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
        $instantanea = $caso['expected']['realCompiler'];
        if (isset(self::NOT_APPLICABLE[$nombre])) {
            // La versión nombra un alias que el perfil no tiene: el compilador no lee eso y compila.
            $alias = array_column($caso['input']['profile']['content']['transferDestinations'], 'alias');
            $this->assertNotSame([], array_diff($caso['input']['version']['handoffAliases'], $alias));
            $this->assertTrue($instantanea['compiles']);
            $this->assertSame($instantanea['specHash'], $this->compileInput($caso['input'])['specHash']);

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
            // La instantánea: la misma huella de la especificación y del perfil traducido.
            $this->assertSame($instantanea['specHash'], $resultado['compiled']['specHash'], "{$nombre}: specHash");
            $this->assertSame($instantanea['voiceProfileHash'], $resultado['compiled']['spec']['source']['voiceProfileHash'], "{$nombre}: huella del perfil");

            return;
        }

        $this->assertFalse($resultado['compiles'], "{$nombre}: compiló");
        $this->assertSame(422, $esperado['http']);
        $this->assertSame($esperado['reason'], $resultado['reason'], "{$nombre}: el compilador dio {$resultado['myReason']} en {$resultado['myField']}");
        if (isset($esperado['field'])) {
            $this->assertSame($esperado['field'], $resultado['field'], $nombre);
        }
        // La instantánea: el motivo y el campo del compilador, sin traducir.
        $this->assertSame([$instantanea['reason'], $instantanea['field']], [$resultado['myReason'], $resultado['myField']], "{$nombre}: instantánea");
    }

    private function hashRelation(array $caso): void
    {
        $nombre = $caso['name'];
        $instantanea = $caso['expected']['realCompiler'];
        if (isset(self::NOT_APPLICABLE[$nombre])) {
            $this->assertNotSame(C::VERSION, $caso['inputB']['compilerVersion']);
            $this->assertSame(C::VERSION, $caso['inputA']['compilerVersion']);
            $this->assertArrayHasKey('notApplicable', $instantanea);
            $this->assertSame($instantanea['specHashA'], $this->compileInput($caso['inputA'])['specHash']);

            return;
        }

        $a = $this->compileInput($caso['inputA'])['specHash'];
        $b = $this->compileInput($caso['inputB'])['specHash'];
        $this->assertSame($caso['expected']['sameSpecHash'], $a === $b, $nombre);
        $this->assertSame([$instantanea['specHashA'], $instantanea['specHashB']], [$a, $b], "{$nombre}: instantánea");
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

    /**
     * La versión del fixture (sin contenido) → una versión real: la variante
     * de contenido de su huella, la de la cabecera (`versionVariants`), con
     * `persona.maxChars` = 160 + 20 × variante.
     */
    private function version(array $v): array
    {
        $this->assertSame(StudioCatalog::VERSION, $v['catalogVersion']);
        $variantes = self::fixture()['realCompilerExport']['versionVariants'];
        $this->assertArrayHasKey($v['recomputedContentHash'], $variantes, 'una huella de versión sin su variante en la cabecera');
        $base = StudioCatalog::baseline();
        $configs = $base['configs'];
        $configs['persona']['maxChars'] = 160 + 20 * $variantes[$v['recomputedContentHash']];   // otro contenido, el mismo prompt
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
            'voiceId' => 'voiceId', 'language' => 'language', 'notice' => 'aiNotice', 'maxDurationSeconds' => 'maxDurationSeconds',
            'tools' => 'tools', 'systemTools' => 'systemTools', 'voicemailPolicy' => 'voicemail',
        ];
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

    /** Las invariantes de una compilación válida (DF-C8), traducidas a la especificación. */
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
        $this->assertSame($inv['transferDestinationAliases'], array_column($s['transfer']['destinations'], 'alias'));
        $this->assertSame($inv['transferDestinationRefs'], array_column($s['transfer']['destinations'], 'ref'));
        $this->assertSame($inv['recordingEnabled'], $s['privacy']['recording']);
        $this->assertFalse($inv['twilioRecordingEnabled']);   // es de la carga del proveedor (B8)
        $this->assertSame($inv['retentionDays'], $s['privacy']['retentionDays']);
        $this->assertSame($inv['audioStorage'], $s['privacy']['storeAudio']);
        $this->assertSame($inv['dynamicVariables'], array_keys($s['dynamicVariables']));
        foreach ($inv['dynamicVariableDefaults'] as $nombre => $defecto) {
            $this->assertSame($defecto, $s['dynamicVariables'][$nombre]);
        }
        $this->assertSame($inv['conversationConfigOverrideAllowed'], $s['overridesAllowed']);
        // E1-6: el primer mensaje es solo el aviso, en los dos sentidos; los saludos van en el prompt.
        $this->assertSame($inv['firstMessageEquals'], $s['firstMessage']);
        foreach ($inv['systemPromptContainsGreetings'] as $saludo) {
            $this->assertStringContainsString('«'.$saludo.'»', $s['systemPrompt']);
        }
        $this->assertTrue($inv['promptHasNoMoneyFigures']);
        $this->assertSame(0, preg_match(T::PRICE_PATTERN, $s['systemPrompt']));
        // Ningún teléfono en la especificación, tampoco los de los destinos (ni su parte nacional).
        $json = StudioContentHasher::canonicalJson($s);
        foreach ($inv['phonesNotInSpec'] as $e164) {
            $this->assertStringNotContainsString($e164, $json);
            $this->assertStringNotContainsString(substr($e164, 3), $json);
        }
        $this->assertTrue($inv['specHashIsSha256OfCanonicalSpec']);
        $this->assertSame(hash('sha256', $json), $compilado['specHash']);
        $this->assertTrue($inv['payloadHashIsSha256OfCanonicalPayload']);   // la carga es del mapper (B8)
        $this->assertTrue(self::cap($input['env']['VOICE_PER_CALL_MAX_SECONDS'] ?? null) >= $s['maxDurationSeconds']);
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
