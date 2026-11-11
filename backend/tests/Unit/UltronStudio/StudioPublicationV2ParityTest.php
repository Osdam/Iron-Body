<?php

namespace Tests\Unit\UltronStudio;

use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * E11 DEL TESTER · LA DECLARACIÓN `ultron-engine-config/2` DEL STUDIO FRENTE A
 * UNA DERIVACIÓN INDEPENDIENTE DE LOS CONTRATOS (Enmienda M-1 del H5; plan de
 * motores §B.4, M1-5, M1-12).
 *
 * `tests/fixtures/ultron-studio/publication-cases-v2.json` lo genera un guion
 * Node del tester (`tools/derivar-publication-cases-v2.cjs`) SIN leer
 * `StudioPublicationDeclaration.php`: JSON canónico del contrato F, firma de
 * A4.1/Q-1, partes `/2` de §B.4 con las huellas de paquete de
 * `ultron-prompts/compile-cases.json`, Q-4 y la clasificación con la llave. El
 * guion se comprueba primero contra los casos `/1` del fixture congelado del H5.
 *
 * Lo que se fija contra el Studio real:
 *  1. las entradas `/2` (canaria sola, canaria + resto, resto arrastrado,
 *     promover, revertir, `{null, X}`, `issuedAt` de noche) y las `/1` (Legacy,
 *     llave apagada): bytes exactos, huella, contrato y firma REAL del motor
 *     (con el prefijo `/1`: «Firma … los de `/1`»), y la lectura estricta de vuelta;
 *  2. lo que no se escribe: Legacy en `/2`, contratos y partes cruzados,
 *     `governed` y `prompts` con claves o tipos fuera de contrato (sin firmar nada);
 *  3. las partes: `partV2(partV1(p)) = p`, `isPartV2`;
 *  4. `previewHash` sin paquete = el objeto de Q-4, byte a byte;
 *  5. con la llave de prompts, los cuatro gobernados salen de `noEffect` y lo
 *     demás de la clasificación no cambia (34 casos del fixture congelado).
 */
class StudioPublicationV2ParityTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../fixtures/ultron-studio/publication-cases-v2.json';

    private const V1 = __DIR__.'/../../fixtures/ultron-studio/publication-cases.json';

    private const GENERATOR = __DIR__.'/../../fixtures/ultron-studio/tools/derivar-publication-cases-v2.cjs';

    /** @var array<string,mixed>|null */
    private static ?array $fixture = null;

    /** @var array<string,mixed>|null */
    private static ?array $v1 = null;

    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        return self::$fixture ??= json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private static function v1(): array
    {
        return self::$v1 ??= json_decode((string) file_get_contents(self::V1), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    private static function seccion(string $nombre): iterable
    {
        foreach (self::fixture()[$nombre] as $caso) {
            yield $caso['name'] => [$caso];
        }
    }

    public static function entradas(): iterable
    {
        return self::seccion('effectiveJson');
    }

    public static function invalidas(): iterable
    {
        return self::seccion('invalid');
    }

    public static function previews(): iterable
    {
        return self::seccion('previewHash');
    }

    public static function clasificaciones(): iterable
    {
        return self::seccion('classifyWithPrompts');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => self::fixture()['appKey']]);
    }

    public function test_el_fixture_es_el_que_sale_hoy_de_los_contratos_y_cubre_lo_pedido(): void
    {
        $this->assertSame([D::CONTRACT, D::CONTRACT_V2], self::fixture()['contracts']);
        $this->assertSame([UltronPublishedConfig::CONTRACT, UltronPublishedConfig::CONTRACT_V2], self::fixture()['contracts'], 'el Studio y el motor, el mismo contrato');
        $porContrato = array_count_values(array_map(static fn (array $c): string => $c['input']['contract'], self::fixture()['effectiveJson']));
        $this->assertGreaterThanOrEqual(6, $porContrato[D::CONTRACT_V2]);
        $this->assertGreaterThanOrEqual(2, $porContrato[D::CONTRACT]);
        $this->assertGreaterThanOrEqual(25, count(self::fixture()['invalid']));
        $this->assertCount(count(self::v1()['classify']), self::fixture()['classifyWithPrompts']);

        $p = proc_open(['node', self::GENERATOR, '--comprobar'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
        $this->assertIsResource($p, 'Hace falta Node en el PATH (como E10).');
        $salida = stream_get_contents($tubos[1]).stream_get_contents($tubos[2]);
        fclose($tubos[1]);
        fclose($tubos[2]);
        $this->assertSame(0, proc_close($p), (string) $salida);
    }

    #[DataProvider('entradas')]
    public function test_el_studio_escribe_los_bytes_y_la_firma_del_contrato(array $caso): void
    {
        $in = $caso['input'];
        $e = $caso['expected'];
        $emitida = new DateTimeImmutable($in['issuedAt']);

        $json = D::effectiveJson($in['entry'], $in['seq'], $emitida, $in['canary'], $in['rest'], $in['contract']);
        $this->assertSame($e['effectiveJson'], $json, 'bytes');
        $this->assertSame($e['effectiveHash'], D::effectiveHash($json));

        // La firma real del motor, inyectada como la inyecta el Studio al publicar.
        $d = D::declaration($in['entry'], $in['seq'], $emitida, $in['canary'], $in['rest'], UltronPublishedConfig::signatureFor(...), $in['contract']);
        $this->assertSame(['contract' => $in['contract'], 'effectiveJson' => $e['effectiveJson'], 'effectiveHash' => $e['effectiveHash'], 'signature' => $e['signature']], $d);
        if ($e['signatureWithOwnContractPrefix'] !== null) {
            $this->assertNotSame($e['signatureWithOwnContractPrefix'], $d['signature'], 'una fila /2 se firma con el prefijo /1 (§B.4: «Firma … los de /1»)');
        }

        // La lectura estricta de vuelta: el mismo contrato y las mismas partes.
        $leida = D::entry($json, $in['entry'], $in['seq']);
        $this->assertSame($in['contract'], $leida['contract']);
        foreach (['canary', 'rest'] as $lado) {
            $this->assertSame($in[$lado] === null ? null : StudioContentHasher::canonicalJson($in[$lado]), $leida[$lado] === null ? null : StudioContentHasher::canonicalJson($leida[$lado]), $lado);
        }
    }

    #[DataProvider('invalidas')]
    public function test_lo_que_el_contrato_no_admite_no_se_escribe_ni_se_firma(array $caso): void
    {
        $in = $caso['input'];
        $emitida = new DateTimeImmutable($in['issuedAt']);

        try {
            D::effectiveJson($in['entry'], $in['seq'], $emitida, $in['canary'], $in['rest'], $in['contract']);
            $this->fail('effectiveJson() escribió una entrada que el contrato no admite.');
        } catch (InvalidArgumentException) {
            // Lo esperado.
        }

        $firmados = 0;
        try {
            D::declaration($in['entry'], $in['seq'], $emitida, $in['canary'], $in['rest'], static function (string $texto) use (&$firmados): string {
                $firmados++;

                return str_repeat('0', 64);
            }, $in['contract']);
            $this->fail('declaration() devolvió una entrada que el contrato no admite.');
        } catch (InvalidArgumentException) {
            // Lo esperado.
        }
        $this->assertSame(0, $firmados, 'se firmó algo que no se escribe');
    }

    #[DataProvider('entradas')]
    public function test_las_partes_v2_son_las_v1_mas_governed_y_prompts(array $caso): void
    {
        $vistas = 0;
        foreach (['canary', 'rest'] as $lado) {
            $p = $caso['input'][$lado];
            if ($p === null) {
                continue;
            }
            $vistas++;
            $esV2 = array_key_exists('governed', $p);
            $this->assertSame($esV2, D::isPartV2($p), "{$lado}: isPartV2");
            if (! $esV2) {
                continue;
            }
            $v1 = $p;
            unset($v1['governed'], $v1['prompts']);
            $this->assertSame(StudioContentHasher::canonicalJson($v1), StudioContentHasher::canonicalJson(D::partV1($p)), "{$lado}: partV1 quita exactamente las dos claves");
            $this->assertSame(StudioContentHasher::canonicalJson($p), StudioContentHasher::canonicalJson(D::partV2($v1, $p['governed'], $p['prompts'])), "{$lado}: partV2 pone exactamente governed y prompts");
            $this->assertFalse(D::isPartV2($v1));
        }
        $this->assertTrue($vistas > 0 || ($caso['input']['canary'] === null && $caso['input']['rest'] === null));
    }

    #[DataProvider('previews')]
    public function test_preview_hash_sin_paquete_es_el_objeto_de_q4(array $caso): void
    {
        $in = $caso['input'];
        $this->assertSame($caso['expected']['previewHash'], D::previewHash($in['versionUuid'], $in['contentHash'], $in['catalogVersion'], $in['classification'], $in['labGate']));
        $this->assertSame($caso['expected']['previewHash'], D::previewHash($in['versionUuid'], $in['contentHash'], $in['catalogVersion'], $in['classification'], $in['labGate'], null), 'prompts null = sin paquete');
    }

    #[DataProvider('clasificaciones')]
    public function test_con_la_llave_los_gobernados_salen_de_no_effect_y_lo_demas_no_cambia(array $caso): void
    {
        $original = null;
        foreach (self::v1()['classify'] as $c) {
            if ($c['name'] === $caso['name']) {
                $original = $c;
            }
        }
        $this->assertNotNull($original, 'el caso del fixture congelado existe');

        $obtenido = D::classify($original['configs'], $original['rules'], StudioCatalog::baseline()['configs'], $original['engine'], StudioCatalog::toArray(), true);
        $e = $caso['expected'];
        foreach (['mustMatch', 'matches', 'noEffect', 'mismatches', 'engineReadingUnavailable'] as $clave) {
            $this->assertSame($e[$clave], $obtenido[$clave] ?? null, $clave);
        }
        $this->assertSame($e['noEffect'], D::requiredAcknowledgements($obtenido), 'lo que hay que reconocer');
        // Los gobernados que salen de noEffect no desaparecen: siguen en la clasificación (clase `prompt`).
        foreach ($e['governedOutOfNoEffect'] as $param) {
            $this->assertStringContainsString('"'.$param.'"', json_encode($obtenido, JSON_UNESCAPED_SLASHES), "{$param} desaparece de la clasificación");
        }
        // Y sin la llave, la de siempre (la del fixture congelado).
        $this->assertSame($original['expected'], D::classify($original['configs'], $original['rules'], StudioCatalog::baseline()['configs'], $original['engine'], StudioCatalog::toArray()));
    }
}
