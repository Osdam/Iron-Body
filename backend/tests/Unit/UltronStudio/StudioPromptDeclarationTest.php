<?php

namespace Tests\Unit\UltronStudio;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptOffer;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioEngineReadings;
use App\Services\UltronStudio\StudioPromptBundle as B;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use App\Services\UltronStudio\StudioPublishing;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * E11, la parte pura (Enmienda M-1 del contrato H5; plan de motores §B.4 y
 * §D): la declaración `ultron-engine-config/2` del Studio y su paquete de
 * prompts, sin base de datos ni configuración.
 *
 *  - las constantes del Studio atadas a las del motor (contratos, gobernados,
 *    llaves, modos y motivos de la oferta);
 *  - los BYTES exactos de una entrada `/2`, escritos a mano desde §B.4 (no
 *    los produce ninguna implementación), y su lectura de vuelta;
 *  - Legacy siempre en `/1`; ninguna parte de un contrato en una entrada del
 *    otro; una parte `/2` sin la forma del lector no se escribe ni se lee;
 *  - la clasificación: con la llave en `off`, la de siempre en los 34 casos
 *    congelados de `publication-cases.json`; con ella encendida, en esos MISMOS
 *    casos, los cuatro gobernados pasan de `noEffect` a `prompt` y nada más
 *    cambia (`intent.fallback` y las S-NNN siguen en `noEffect`);
 *  - `previewHash`: la de siempre sin prompts, y cambia con la huella del
 *    paquete, con la parte arrastrada y con la lista `prompt`;
 *  - el paquete: la línea base es Legacy byte a byte; el cambio de aceptación
 *    (G8) da la huella de referencia de B-M; un valor inválido no compila;
 *  - la oferta que enseña `status`, paso a paso con los motivos de `decide`.
 */
class StudioPromptDeclarationTest extends TestCase
{
    private const E1 = '0b7e8f10-2c3d-4e5f-9a0b-1c2d3e4f5a6b';

    private const V = '6f1c2a9e-1d2b-4c3d-8e4f-5a6b7c8d9e0f';

    private const W = '7a2d3b0f-2e3c-4d4e-9f50-6b7c8d9e0f1a';

    private const DECLARED = ['governance.canaryOnly' => true, 'governance.enabled' => true, 'intent.driver' => 'entorno', 'rules.paymentLinksEnabled' => false];

    /** El cambio de aceptación (G8) y su huella de referencia (`api-compilador.md` §4, calculada por B-M). */
    private const G8 = ['persona.emojis' => true, 'persona.maxChars' => 200, 'persona.tone' => 'profesional', 'strategy.goal' => 'cita'];

    /** G8 con el compilador 2: la misma huella (sin frases no hay parche nuevo) y la quinta clave vacía. */
    private const G8_C2 = ['persona.emojis' => true, 'persona.maxChars' => 200, 'persona.tone' => 'profesional', 'rules.forbiddenPhrases' => [], 'strategy.goal' => 'cita'];

    private const G8_HASH = '702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c';

    private const LEGACY_HASH = '3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902';

    private static ?array $fixture = null;

    private static function part(string $version = self::V): array
    {
        return D::part($version, str_repeat('ab', 32), '2026-10-19.1', 12, 7, self::DECLARED);
    }

    /** Una parte `/2` firmada con el compilador 1 (cuatro gobernados): la forma que sigue en la cadena tras subir el compilador. */
    private static function partV2(string $version = self::V, array $governed = self::G8, string $hash = self::G8_HASH): array
    {
        return D::partV2(self::part($version), $governed, ['base' => 'n8n-29de5119', 'bundleHash' => $hash, 'compiler' => 1]);
    }

    /** Una parte `/2` firmada con el compilador de hoy (2: con las frases). */
    private static function partActual(string $version = self::V, array $governed = self::G8_C2, string $hash = self::G8_HASH): array
    {
        return D::partV2(self::part($version), $governed, ['base' => 'n8n-29de5119', 'bundleHash' => $hash, 'compiler' => 2]);
    }

    private static function fixture(): array
    {
        return self::$fixture ??= json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/ultron-studio/publication-cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function classifyCases(): iterable
    {
        foreach (self::fixture()['classify'] as $caso) {
            yield $caso['name'] => [$caso];
        }
    }

    // ── El mismo contrato que el motor ──────────────────────────────────────

    public function test_las_constantes_del_studio_son_las_del_motor(): void
    {
        $this->assertSame(UltronPublishedConfig::CONTRACT, D::CONTRACT);
        $this->assertSame(UltronPublishedConfig::CONTRACT_V2, D::CONTRACT_V2);
        $this->assertSame(UltronPublishedConfig::CONTRACTS, D::CONTRACTS);

        // Compilador 2: los cuatro del catálogo (D::GOVERNED) y la quinta clave, las frases.
        $this->assertSame(UltronPromptCompiler::PHRASES, D::GOVERNED_PHRASES);
        $this->assertSame(2, D::PHRASES_SINCE_COMPILER);
        $this->assertLessThanOrEqual(UltronPromptCompiler::VERSION, D::PHRASES_SINCE_COMPILER);
        $cuatro = array_values(array_diff(UltronPromptCompiler::PARAMETERS, [UltronPromptCompiler::PHRASES]));
        $gobernados = $cuatro;
        sort($gobernados, SORT_STRING);
        $this->assertSame($gobernados, array_keys(D::GOVERNED), 'D::GOVERNED son los cuatro del catálogo que gobierna el compilador, por orden de bytes');
        $linea = UltronPromptCompiler::BASELINE;
        $todas = array_keys(D::GOVERNED + [D::GOVERNED_PHRASES => 'array']);
        sort($todas, SORT_STRING);
        $this->assertSame(array_keys($linea), $todas, 'la línea base del compilador lleva las cinco claves, por orden de bytes');
        foreach ($linea as $id => $valor) {
            $this->assertSame((D::GOVERNED + [D::GOVERNED_PHRASES => 'array'])[$id], gettype($valor), $id);
        }
        $this->assertSame($cuatro, B::parameters(), 'los parámetros del catálogo, en el orden de aplicación');
        $this->assertSame(UltronPromptCompiler::PHRASES, B::phrasesParameter());
        $this->assertSame(12, B::maxPhrases());
        $this->assertSame(UltronPromptBase::ROLES, B::roles());

        // La línea base del catálogo dice de los cuatro lo mismo que el compilador: «como hoy» (G4); sin reglas, sin frases.
        $deLaLinea = B::governedFrom(['configs' => StudioCatalog::baseline()['configs']]);
        ksort($deLaLinea, SORT_STRING);
        $this->assertSame(UltronPromptCompiler::BASELINE, $deLaLinea);

        $this->assertSame(UltronPromptOffer::KEY_OFF, StudioEngineReadings::PROMPTS_OFF);
        $this->assertSame(UltronPromptOffer::KEY_CANARY, StudioEngineReadings::PROMPTS_CANARY);
        $this->assertSame(UltronPromptOffer::MODE_STUDIO, B::MODE_STUDIO);
        $this->assertSame(UltronPromptOffer::MODE_LEGACY, B::MODE_LEGACY);
        foreach (['PROMPTS_OFF', 'STUDIO_CONFIG_OFF', 'OUT_OF_SCOPE', 'NO_PUBLICATION', 'PART_LEGACY', 'DECLARATION_V1', 'PUBLICATION_PREFIX', 'BASE_CHANGED', 'COMPILER_CHANGED', 'BUNDLE_MISMATCH', 'TOO_LARGE', 'ERROR'] as $c) {
            $this->assertSame(constant(UltronPromptOffer::class.'::'.$c), constant(B::class.'::'.$c), $c);
        }
        $this->assertSame(UltronPromptBase::ID, B::base());
        $this->assertSame(UltronPromptCompiler::VERSION, B::compilerVersion());
        $this->assertSame(self::LEGACY_HASH, B::legacyBundleHash());
    }

    // ── Los bytes de `/2` (§B.4) ────────────────────────────────────────────

    public function test_los_bytes_de_una_entrada_v2_son_los_del_contrato(): void
    {
        // Escrito a mano desde §B.4: la entrada de /1 con `contract` /2 y, en la
        // parte, `governed` y `prompts` (claves por orden de bytes en todos los
        // niveles, sin espacios, sin escapar la barra).
        $esperado = '{"canary":{"approvedBy":12,"catalogVersion":"2026-10-19.1","contentHash":"'.str_repeat('ab', 32).'",'
            .'"declared":{"governance.canaryOnly":true,"governance.enabled":true,"intent.driver":"entorno","rules.paymentLinksEnabled":false},'
            .'"governed":{"persona.emojis":true,"persona.maxChars":200,"persona.tone":"profesional","strategy.goal":"cita"},'
            .'"prompts":{"base":"n8n-29de5119","bundleHash":"'.self::G8_HASH.'","compiler":1},'
            .'"publishedBy":7,"version":"'.self::V.'"},'
            .'"contract":"ultron-engine-config/2","entry":"'.self::E1.'","issuedAt":"2026-11-03T15:04:05Z","rest":null,"seq":1}';

        // Las claves de entrada en cualquier orden: la salida es la misma.
        $parte = D::partV2(self::part(), array_reverse(self::G8, true), ['compiler' => 1, 'bundleHash' => self::G8_HASH, 'base' => 'n8n-29de5119']);
        $json = D::effectiveJson(self::E1, 1, new DateTimeImmutable('2026-11-03T10:04:05.999-05:00'), $parte, null, D::CONTRACT_V2);

        $this->assertSame($esperado, $json);
        $this->assertSame(['contract' => D::CONTRACT_V2, 'canary' => $parte, 'rest' => null], D::entry($json, self::E1, 1));
        $this->assertSame(['canary' => $parte, 'rest' => null], D::parts($json, strtoupper(self::E1), 1));
        $this->assertSame($json, D::effectiveJson(self::E1, 1, new DateTimeImmutable('2026-11-03T15:04:05Z'), ...[...array_values(D::parts($json, self::E1, 1)), D::CONTRACT_V2]));

        // La firma recibe esos bytes y la declaración dice su contrato.
        $vistos = [];
        $d = D::declaration(self::E1, 1, new DateTimeImmutable('2026-11-03T15:04:05Z'), $parte, null, static function (string $t) use (&$vistos): string {
            $vistos[] = $t;

            return str_repeat('c', 64);
        }, D::CONTRACT_V2);
        $this->assertSame([$esperado], $vistos);
        $this->assertSame(['contract' => D::CONTRACT_V2, 'effectiveJson' => $esperado, 'effectiveHash' => hash('sha256', $esperado), 'signature' => str_repeat('c', 64)], $d);

        // `/1` sigue siendo `/1`: el contrato por defecto no cambia.
        $this->assertStringContainsString('"contract":"ultron-engine-config/1"', D::effectiveJson(self::E1, 1, new DateTimeImmutable('2026-11-03T15:04:05Z'), self::part(), null));
        $this->assertSame(self::part(), D::partV1($parte));
        $this->assertFalse(D::isPartV2(self::part()));
        $this->assertTrue(D::isPartV2($parte));
    }

    /** @return iterable<string,array{Closure():mixed}> */
    public static function badEntries(): iterable
    {
        $t = new DateTimeImmutable('2026-11-03T15:04:05Z');

        yield 'Legacy en /2' => [fn () => D::effectiveJson(self::E1, 1, $t, null, null, D::CONTRACT_V2)];
        yield 'una parte /1 en /2' => [fn () => D::effectiveJson(self::E1, 1, $t, self::part(), null, D::CONTRACT_V2)];
        yield 'una parte /2 en /1' => [fn () => D::effectiveJson(self::E1, 1, $t, self::partV2(), null)];
        yield 'mezcla en /2' => [fn () => D::effectiveJson(self::E1, 1, $t, self::partV2(), self::part(self::W), D::CONTRACT_V2)];
        yield 'contrato desconocido' => [fn () => D::effectiveJson(self::E1, 1, $t, self::part(), null, 'ultron-engine-config/3')];
        yield 'gobernados de más' => [fn () => self::partV2(governed: self::G8 + ['intent.fallback' => 'humano'])];
        yield 'gobernados de menos' => [fn () => self::partV2(governed: array_slice(self::G8, 1, null, true))];
        yield 'maxChars como texto' => [fn () => self::partV2(governed: array_replace(self::G8, ['persona.maxChars' => '200']))];
        yield 'emojis como entero' => [fn () => self::partV2(governed: array_replace(self::G8, ['persona.emojis' => 1]))];
        yield 'huella en mayúsculas' => [fn () => self::partV2(hash: strtoupper(self::G8_HASH))];
        yield 'huella corta' => [fn () => self::partV2(hash: substr(self::G8_HASH, 1))];
        yield 'compilador como texto' => [fn () => D::partV2(self::part(), self::G8, ['base' => 'n8n-29de5119', 'bundleHash' => self::G8_HASH, 'compiler' => '1'])];
        yield 'compilador cero' => [fn () => D::partV2(self::part(), self::G8, ['base' => 'n8n-29de5119', 'bundleHash' => self::G8_HASH, 'compiler' => 0])];
        yield 'prompts con clave de más' => [fn () => D::partV2(self::part(), self::G8, ['base' => 'n8n-29de5119', 'bundleHash' => self::G8_HASH, 'compiler' => 1, 'roles' => []])];
        yield 'base vacía' => [fn () => D::partV2(self::part(), self::G8, ['base' => '', 'bundleHash' => self::G8_HASH, 'compiler' => 1])];
    }

    #[DataProvider('badEntries')]
    public function test_lo_que_el_motor_no_leeria_no_se_escribe(Closure $escribir): void
    {
        $this->expectException(InvalidArgumentException::class);
        $escribir();
    }

    public function test_una_entrada_v2_sin_la_forma_del_lector_no_se_lee(): void
    {
        $bien = D::effectiveJson(self::E1, 1, new DateTimeImmutable('2026-11-03T15:04:05Z'), self::partV2(), null, D::CONTRACT_V2);
        $this->assertSame(D::CONTRACT_V2, D::entry($bien, self::E1, 1)['contract']);

        $malas = [
            'parte /1 en una entrada /2' => str_replace(['"governed":{"persona.emojis":true,"persona.maxChars":200,"persona.tone":"profesional","strategy.goal":"cita"},', '"prompts":{"base":"n8n-29de5119","bundleHash":"'.self::G8_HASH.'","compiler":1},'], '', $bien),
            'parte /2 en una entrada /1' => str_replace('ultron-engine-config/2', 'ultron-engine-config/1', $bien),
            'contrato /3' => str_replace('ultron-engine-config/2', 'ultron-engine-config/3', $bien),
            'compilador como texto' => str_replace('"compiler":1', '"compiler":"1"', $bien),
            'gobernado de más' => str_replace('"strategy.goal":"cita"', '"strategy.goal":"cita","intent.fallback":"humano"', $bien),
        ];
        foreach ($malas as $nombre => $json) {
            try {
                D::entry($json, self::E1, 1);
                $this->fail("Se leyó {$nombre}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── La clasificación (§B.4) ─────────────────────────────────────────────

    #[DataProvider('classifyCases')]
    public function test_con_la_llave_apagada_la_clasificacion_es_la_de_siempre_y_encendida_solo_mueve_los_gobernados(array $caso): void
    {
        $args = [$caso['configs'], $caso['rules'], StudioCatalog::baseline()['configs'], $caso['engine'], StudioCatalog::toArray()];
        $esperado = $caso['expected'];

        $this->assertSame($esperado, D::classify(...[...$args, false]), 'Con la llave en off, el fixture congelado entero.');

        $con = D::classify(...[...$args, true]);
        $gobernados = array_values(array_filter($esperado['noEffect'], static fn (string $id): bool => isset(D::GOVERNED[$id])));
        $resto = array_values(array_filter($esperado['noEffect'], static fn (string $id): bool => ! isset(D::GOVERNED[$id])));
        $this->assertSame([
            'mustMatch' => $esperado['mustMatch'],
            'matches' => $esperado['matches'],
            'noEffect' => $resto,
            'mismatches' => $esperado['mismatches'],
            'engineReadingUnavailable' => $esperado['engineReadingUnavailable'],
            'prompt' => $gobernados,
        ], $con);
        // Los gobernados no se reconocen; `intent.fallback` y las reglas, sí.
        $this->assertSame($resto, D::requiredAcknowledgements($con));
    }

    public function test_fallback_y_las_reglas_siguen_sin_efecto_con_la_llave_encendida(): void
    {
        $configs = StudioCatalog::baseline()['configs'];
        $configs['persona'] = ['tone' => 'entusiasta', 'emojis' => false, 'maxChars' => 480];
        $configs['intent']['fallback'] = 'humano';
        $configs['strategy']['goal'] = 'venta';
        $lecturas = [
            'intent.driver' => ['available' => true, 'value' => 'openai'],
            'rules.paymentLinksEnabled' => ['available' => true, 'value' => false],
            'governance.enabled' => ['available' => true, 'value' => true],
            'governance.canaryOnly' => ['available' => true, 'value' => true],
        ];

        $c = D::classify($configs, [['code' => 'S-002'], ['code' => 'R-01'], ['code' => 'S-001']], StudioCatalog::baseline()['configs'], $lecturas, StudioCatalog::toArray(), true);

        $this->assertSame(['persona.tone', 'persona.emojis', 'persona.maxChars', 'strategy.goal'], $c['prompt']);
        $this->assertSame(['intent.fallback', 'rule:S-001', 'rule:S-002'], $c['noEffect']);
        $this->assertSame(['intent.fallback', 'rule:S-001', 'rule:S-002'], D::requiredAcknowledgements($c));
    }

    // ── La huella de la vista previa (E11) ──────────────────────────────────

    public function test_preview_hash_sin_prompts_es_la_de_siempre_y_con_ellos_cambia_con_el_paquete(): void
    {
        $base = StudioCatalog::baseline()['configs'];
        $lecturas = ['intent.driver' => ['available' => true, 'value' => 'openai'], 'rules.paymentLinksEnabled' => ['available' => true, 'value' => false], 'governance.enabled' => ['available' => true, 'value' => true], 'governance.canaryOnly' => ['available' => true, 'value' => true]];
        $configs = $base;
        $configs['persona']['tone'] = 'profesional';
        $lab = ['mode' => 'required', 'latest' => 'approved'];
        $sin = D::classify($configs, [], $base, $lecturas, StudioCatalog::toArray());
        $con = D::classify($configs, [], $base, $lecturas, StudioCatalog::toArray(), true);
        $huella = static fn (array $c, ?array $p = null): string => D::previewHash(self::V, str_repeat('b', 64), '2026-10-19.1', $c, $lab, $p);
        $prompts = static fn (?string $h = self::G8_HASH, ?array $arrastrada = null): array => ['base' => 'n8n-29de5119', 'bundleHash' => $h, 'carried' => $arrastrada, 'compiler' => 1];

        $this->assertSame(D::previewHash(self::V, str_repeat('b', 64), '2026-10-19.1', $sin, $lab), $huella($sin), 'sin prompts, el objeto de Q-4 de siempre');

        $todas = [
            'sin prompts' => $huella($sin),
            'con prompts' => $huella($con, $prompts()),
            'otro paquete' => $huella($con, $prompts(self::LEGACY_HASH)),
            'sin paquete (no compila)' => $huella($con, $prompts(null)),
            'arrastra una parte' => $huella($con, $prompts(arrastrada: ['bundleHash' => self::LEGACY_HASH, 'version' => self::W])),
            'arrastra otra versión' => $huella($con, $prompts(arrastrada: ['bundleHash' => self::LEGACY_HASH, 'version' => self::V])),
            'arrastra otro paquete' => $huella($con, $prompts(arrastrada: ['bundleHash' => self::G8_HASH, 'version' => self::W])),
            'otra lista prompt' => $huella(['prompt' => []] + $con, $prompts()),
        ];
        $this->assertSame(count($todas), count(array_unique($todas)), 'cada cambio cambia la huella');

        $this->expectException(InvalidArgumentException::class);
        $huella($con, ['base' => 'n8n-29de5119', 'bundleHash' => self::G8_HASH, 'compiler' => 1]);
    }

    // ── El paquete (§B.3) ───────────────────────────────────────────────────

    public function test_la_linea_base_es_legacy_y_el_cambio_de_aceptacion_tiene_su_huella(): void
    {
        $legacy = B::compile(B::governedFrom(['configs' => StudioCatalog::baseline()['configs']]));
        $this->assertTrue($legacy['baseline']);
        $this->assertSame([], $legacy['changes']);
        $this->assertSame(['base' => 'n8n-29de5119', 'bundleHash' => self::LEGACY_HASH, 'compiler' => 2], $legacy['prompts']);
        $this->assertSame(array_sum(UltronPromptBase::BYTES), $legacy['bytes']);

        $g8 = B::compile(array_reverse(self::G8_C2, true));
        $this->assertFalse($g8['baseline']);
        $this->assertSame(self::G8_C2, $g8['governed']);
        $this->assertSame(self::G8_HASH, $g8['prompts']['bundleHash']);
        $this->assertSame(113672, $g8['bytes']);
        $this->assertSame(['persona.tone', 'persona.maxChars'], array_column($g8['changes'], 'param'));
        $this->assertSame(['profesional', 200], array_column($g8['changes'], 'value'));
        foreach ($g8['changes'] as $cambio) {
            $this->assertSame(['composer', 'critic', 'composer_retry', 'critic_retry'], $cambio['roles'], 'roles en su orden canónico');
        }
        // Los parches son los del compilador, con su texto exacto y en su orden.
        $this->assertSame(
            array_map(static fn (array $p): array => ['role' => $p['role'], 'operation' => $p['operation'], 'anchor' => $p['anchor'], 'text' => $p['text']], UltronPromptCompiler::patches(self::G8_C2)),
            array_merge(...array_column($g8['changes'], 'patches')),
        );
        $this->assertSame('mas de ~200 caracteres', $g8['changes'][1]['patches'][2]['text']);

        $venta = B::compile(array_replace(self::G8_C2, ['persona.tone' => 'cercano', 'persona.maxChars' => 320, 'strategy.goal' => 'venta']));
        $this->assertSame([['param' => 'strategy.goal', 'value' => 'venta', 'roles' => ['strategist']]], array_map(static fn (array $c): array => array_diff_key($c, ['patches' => 0]), $venta['changes']));
    }

    /** @return iterable<string,array{array<string,mixed>,string}> */
    public static function invalidGoverned(): iterable
    {
        yield 'tono desconocido' => [array_replace(self::G8_C2, ['persona.tone' => 'serio']), 'invalid_governed'];
        yield 'maxChars fuera de paso' => [array_replace(self::G8_C2, ['persona.maxChars' => 330]), 'invalid_governed'];
        yield 'maxChars fuera de rango' => [array_replace(self::G8_C2, ['persona.maxChars' => 500]), 'invalid_governed'];
        yield 'objetivo desconocido' => [array_replace(self::G8_C2, ['strategy.goal' => 'vender']), 'invalid_governed'];
        yield 'falta uno' => [array_slice(self::G8_C2, 0, 4, true), 'invalid_governed'];
        yield 'la forma del compilador 1 (sin frases)' => [self::G8, 'invalid_governed'];
        yield 'una frase con comillas' => [array_replace(self::G8_C2, ['rules.forbiddenPhrases' => ['di "gratis"']]), 'invalid_phrases'];
    }

    #[DataProvider('invalidGoverned')]
    public function test_un_valor_que_no_compila_es_un_error_con_el_motivo_del_compilador(array $gobernados, string $motivo): void
    {
        try {
            B::compile($gobernados);
            $this->fail('compiló');
        } catch (InvalidArgumentException $e) {
            $this->assertSame($motivo, B::failureReason($e));
            $this->assertStringContainsString("({$motivo})", $e->getMessage());
        }
    }

    public function test_governed_from_no_inventa_un_valor_que_falta(): void
    {
        $configs = StudioCatalog::baseline()['configs'];
        unset($configs['strategy']['goal']);

        $this->expectException(InvalidArgumentException::class);
        B::governedFrom(['configs' => $configs]);
    }

    // ── La oferta que enseña `status` (§B.5) ────────────────────────────────

    public function test_la_oferta_sigue_los_pasos_de_decide_con_sus_motivos(): void
    {
        $ok = static fn (?array $parte, string $contrato = D::CONTRACT_V2): array => ['state' => 'ok', 'reason' => null, 'contract' => $contrato, 'part' => $parte];
        $sin = ['state' => 'none', 'reason' => null, 'contract' => null, 'part' => null];
        $off = StudioEngineReadings::PROMPTS_OFF;
        $on = StudioEngineReadings::PROMPTS_CANARY;
        $canary = StudioPublishing::SCOPE_CANARY;
        $motivo = static fn (array $o): ?string => $o['reason'];

        $this->assertSame('prompts_off', $motivo(B::offer($off, $canary, true, $ok(self::partActual()))));
        $this->assertSame('prompts_off', $motivo(B::offer('all', $canary, true, $ok(self::partActual()))));
        $this->assertSame('studio_config_off', $motivo(B::offer($on, StudioPublishing::SCOPE_OFF, true, $ok(self::partActual()))));
        $this->assertSame('out_of_scope', $motivo(B::offer($on, $canary, false, $ok(self::partActual()))));
        $this->assertSame('no_publication', $motivo(B::offer($on, $canary, true, $sin)));
        $this->assertSame('publication_signature_invalid', $motivo(B::offer($on, $canary, true, ['state' => 'invalid', 'reason' => 'signature_invalid', 'contract' => null, 'part' => null])));
        $this->assertSame('part_legacy', $motivo(B::offer($on, $canary, true, $ok(null))));
        $this->assertSame('declaration_v1', $motivo(B::offer($on, $canary, true, $ok(self::part(), D::CONTRACT))));
        $base = self::partActual();
        $base['prompts']['base'] = 'n8n-00000000';
        $this->assertSame('base_changed', $motivo(B::offer($on, $canary, true, $ok($base))));
        // Lo firmado con el compilador 1 (la misma G8, misma huella) cae a Legacy hasta volver a publicar.
        $this->assertSame('compiler_changed', $motivo(B::offer($on, $canary, true, $ok(self::partV2()))));
        $compilador = self::partActual();
        $compilador['prompts']['compiler'] = 3;
        $this->assertSame('compiler_changed', $motivo(B::offer($on, $canary, true, $ok($compilador))));
        $this->assertSame('error', $motivo(B::offer($on, $canary, true, $ok(self::partActual(governed: array_replace(self::G8_C2, ['persona.tone' => 'serio']))))));
        $this->assertSame('bundle_mismatch', $motivo(B::offer($on, $canary, true, $ok(self::partActual(hash: self::LEGACY_HASH)))));

        $this->assertSame(['mode' => 'studio', 'version' => self::V, 'bundleHash' => self::G8_HASH, 'reason' => null], B::offer($on, $canary, true, $ok(self::partActual())));
        $this->assertSame(['mode' => 'studio', 'version' => self::V, 'bundleHash' => self::G8_HASH, 'reason' => null], B::offer($on, StudioPublishing::SCOPE_ALL, true, $ok(self::partActual())));
        $this->assertSame(['mode' => 'legacy', 'version' => null, 'bundleHash' => null, 'reason' => 'prompts_off'], B::offer($off, $canary, true, $sin));
    }
}
