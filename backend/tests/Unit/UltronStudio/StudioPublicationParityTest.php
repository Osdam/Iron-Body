<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioCanaryEvidence as E;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PARIDAD del núcleo de publicación (contrato H5 §A12 «Núcleo» y Enmienda 1):
 * el servidor reproduce EXACTAMENTE cada caso de las secciones `classify`,
 * `effectiveJson` (bytes y `compose`) y `evidence` de
 * `tests/fixtures/ultron-studio/publication-cases.json`, y la parte pura de
 * `gates`: los pasos 8 y 9 de publicar y la evidencia con su huella al promover.
 *
 * Ese fichero lo escribió el tester a mano desde el contrato (ver su README);
 * no lo genera ninguna implementación. Si un caso discrepa, se corrige el PHP;
 * el fixture solo cambia con una enmienda. Catálogo: el REAL, después de
 * comprobar que es el que transcribe el fixture. Igualdad estricta
 * (`assertSame`): valores, tipos, orden de las listas y de las claves.
 *
 * `signature` y `scope` son del lector del motor (`UltronPublishedConfigTest`).
 * De `scope` solo se usan sus cabezas para un cruce: lo que el lector aplica, el
 * Studio lo tiene que poder leer para componer la entrada siguiente, y lo que el
 * lector llama `malformed`, el Studio no lo copia.
 */
class StudioPublicationParityTest extends TestCase
{
    /** Los recuentos que fija el README (y la Enmienda 1). */
    private const SECTIONS = ['classify' => 34, 'effectiveJson' => 21, 'signature' => 24, 'scope' => 63, 'gates' => 71, 'evidence' => 18];

    /** Puertas de A5.1/A5.2 anteriores a la parte pura (o el reintento): con ellas, el fixture no dice nada de la parte pura. */
    private const BEFORE = [
        'publish' => ['person_required', 'forbidden', 'publishing_disabled', 'engine_not_reading_versions', 'replayed', 'client_request_reused',
            'version_not_found', 'four_eyes_required', 'invalid_transition', 'integrity_mismatch', 'revalidation_blocked', 'revalidation_changed',
            'lab_verdict_required'],
        'promote' => ['person_required', 'forbidden', 'publishing_disabled', 'engine_not_reading_versions', 'replayed', 'client_request_reused',
            'version_not_found', 'four_eyes_required', 'scope_not_allowed', 'invalid_transition', 'integrity_mismatch'],
    ];

    /** Los códigos de la parte pura, en su orden. */
    private const PURE = [
        'publish' => [D::CODE_ENGINE_VALUE_MISMATCH, D::CODE_ENGINE_READING_UNAVAILABLE, D::CODE_NO_EFFECT_NOT_ACKNOWLEDGED, D::CODE_PREVIEW_STALE],
        'promote' => [E::CODE_INSUFFICIENT, E::CODE_CHANGED],
    ];

    /** Puertas posteriores (o ninguna, null): con ellas, la parte pura tiene que pasar. */
    private const AFTER = [
        'publish' => [null, 'engine_has_no_canary', 'engine_braked', 'canary_in_progress', 'outside_publication_window'],
        'promote' => [null, 'engine_braked', 'outside_publication_window'],
    ];

    /** Los valores declarados de las partes sintéticas de `compose`. */
    private const DECLARED = ['governance.canaryOnly' => true, 'governance.enabled' => true, 'intent.driver' => 'entorno', 'rules.paymentLinksEnabled' => false];

    private static ?array $fixture = null;

    private static function fixture(): array
    {
        return self::$fixture ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/ultron-studio/publication-cases.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    private static function cases(string $section, ?string $kind = null): iterable
    {
        foreach (self::fixture()[$section] as $caso) {
            if ($kind === null || $caso['kind'] === $kind) {
                yield $caso['name'] => [$caso];
            }
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function classifyCases(): iterable
    {
        return self::cases('classify', 'classify');
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function effectiveJsonCases(): iterable
    {
        return self::cases('effectiveJson', 'effectiveJson');
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function composeCases(): iterable
    {
        return self::cases('effectiveJson', 'compose');
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function evidenceCases(): iterable
    {
        return self::cases('evidence', 'evidence');
    }

    /**
     * Las puertas de publicar y promover en las que la parte pura decide algo:
     * rechaza ella, rechaza una puerta posterior o no rechaza ninguna.
     *
     * @return iterable<string,array{array<string,mixed>}>
     */
    public static function pureGateCases(): iterable
    {
        foreach (self::fixture()['gates'] as $caso) {
            if (isset(self::BEFORE[$caso['kind']]) && ! in_array($caso['expected']['code'], self::BEFORE[$caso['kind']], true)) {
                yield $caso['name'] => [$caso];
            }
        }
    }

    // ── El fixture y su catálogo ────────────────────────────────────────────

    public function test_el_fixture_es_el_del_contrato_y_transcribe_el_catalogo_real(): void
    {
        $f = self::fixture();

        $this->assertSame(1, $f['version']);
        $this->assertSame(StudioCatalog::VERSION, $f['catalogVersion'], 'Los casos se escribieron con otra versión del catálogo: hay que enmendar el fixture.');
        $this->assertSame(D::CONTRACT, $f['contract']);

        $nombres = [];
        foreach (self::SECTIONS as $seccion => $cuantos) {
            $this->assertCount($cuantos, $f[$seccion], $seccion);
            foreach ($f[$seccion] as $caso) {
                $this->assertStringStartsWith($seccion, $caso['name']);
                $nombres[] = $caso['name'];
            }
        }
        $this->assertCount(231, $nombres);
        $this->assertSame(count($nombres), count(array_unique($nombres)), 'nombres de caso repetidos');

        // La clasificación depende del catálogo: el transcrito tiene que ser el real, en su orden.
        $proyectado = [];
        foreach (StudioCatalog::layerIds() as $capa) {
            foreach (StudioCatalog::bindings()[$capa] ?? [] as $clave => $mando) {
                $p = ['param' => "{$capa}.{$clave}", 'origin' => $mando['origin'], 'valueSource' => $mando['valueSource']];
                foreach (['declaredValue', 'engineOption'] as $extra) {
                    if (array_key_exists($extra, $mando)) {
                        $p[$extra] = $mando[$extra];
                    }
                }
                $proyectado[] = $p;
            }
        }
        $this->assertSame($proyectado, $f['catalog']['parameters']);
        $this->assertSame(StudioCatalog::baseline()['configs'], $f['catalog']['baseline']);
    }

    // ── classify ────────────────────────────────────────────────────────────

    #[DataProvider('classifyCases')]
    public function test_classify_reproduce_el_caso(array $caso): void
    {
        $obtenido = D::classify($caso['configs'], $caso['rules'], StudioCatalog::baseline()['configs'], $caso['engine'], StudioCatalog::toArray());

        $this->assertSame($caso['expected'], $obtenido, 'Discrepa del fixture escrito desde el contrato: se corrige el PHP, nunca expected.');
        $this->assertSame($caso['expected']['noEffect'], D::requiredAcknowledgements($obtenido));
    }

    // ── effectiveJson: bytes y composición ──────────────────────────────────

    #[DataProvider('effectiveJsonCases')]
    public function test_effective_json_da_los_bytes_exactos(array $caso): void
    {
        $in = $caso['input'];
        $emitida = new DateTimeImmutable($in['issuedAt']);

        $json = D::effectiveJson($in['entry'], $in['seq'], $emitida, $in['canary'], $in['rest']);
        $this->assertSame($caso['expected']['effectiveJson'], $json);
        $this->assertSame($caso['expected']['effectiveHash'], D::effectiveHash($json));

        // El punto de inyección de la firma recibe esos mismos bytes y nada más.
        $firmados = [];
        $firma = hash('sha256', 'firma de prueba');
        $d = D::declaration($in['entry'], $in['seq'], $emitida, $in['canary'], $in['rest'], static function (string $texto) use (&$firmados, $firma): string {
            $firmados[] = $texto;

            return $firma;
        });
        $this->assertSame([$caso['expected']['effectiveJson']], $firmados);
        $this->assertSame(['contract' => D::CONTRACT, 'effectiveJson' => $caso['expected']['effectiveJson'], 'effectiveHash' => $caso['expected']['effectiveHash'], 'signature' => $firma], $d);

        // Y el Studio lee lo que escribe (para componer la entrada siguiente).
        $partes = D::parts($json, $in['entry'], $in['seq']);
        $this->assertSame($json, D::effectiveJson($in['entry'], $in['seq'], $emitida, $partes['canary'], $partes['rest']));
    }

    #[DataProvider('composeCases')]
    public function test_compose_pone_cada_version_en_su_parte(array $caso): void
    {
        $in = $caso['input'];
        $cabeza = $in['head'] === null ? null : ['canary' => self::part($in['head']['canary']), 'rest' => self::part($in['head']['rest'])];

        $r = D::compose(
            $in['action'],
            $in['version'],
            $in['versionStatus'],
            $cabeza,
            $in['action'] === D::ACTION_PUBLISH ? self::part($in['version']) : null,
            self::part($in['restores']),
        );

        $this->assertSame($caso['expected'], ['canary' => $r['canary']['version'] ?? null, 'rest' => $r['rest']['version'] ?? null]);
    }

    // ── evidence ────────────────────────────────────────────────────────────

    #[DataProvider('evidenceCases')]
    public function test_la_evidencia_reproduce_el_caso(array $caso): void
    {
        $r = E::evaluate($caso['measures'], $caso['thresholds'], new DateTimeImmutable($caso['now']));
        $esperado = $caso['expected'];

        $this->assertSame(['hours', 'passes', 'failing', 'evidenceHash'], array_keys($r));
        $this->assertSame($esperado['hours'], $r['hours']);
        $this->assertSame($esperado['passes'], $r['passes']);
        $this->assertSame($esperado['failing'], $r['failing']);
        $this->assertSame($esperado['evidenceHash'], $r['evidenceHash']);
        $this->assertSame($esperado['evidenceJson'], E::evidenceJson($caso['measures'], $caso['thresholds']));
        $this->assertSame($esperado['evidenceHash'], hash('sha256', $esperado['evidenceJson']));
    }

    // ── gates: la parte pura ────────────────────────────────────────────────

    #[DataProvider('pureGateCases')]
    public function test_la_parte_pura_de_las_puertas_decide_lo_que_dice_el_fixture(array $caso): void
    {
        $f = $caso['facts'];
        $tipo = $caso['kind'];
        $esperado = $caso['expected'];

        $veredicto = $tipo === 'publish'
            ? D::refusal($f['classification'], $f['acknowledgedNoEffect'], $f['previewHash']['sent'], $f['previewHash']['current'])
            : E::refusal(['passes' => $f['evidence']['passes'], 'evidenceHash' => $f['evidenceHash']['current']], $f['evidenceHash']['sent']);

        if (in_array($esperado['code'], self::AFTER[$tipo], true)) {
            $this->assertNull($veredicto, 'La primera puerta que rechaza es posterior (o ninguna): la parte pura tiene que pasar.');

            return;
        }

        $this->assertContains($esperado['code'], self::PURE[$tipo]);
        $this->assertSame(409, $esperado['status']);
        $this->assertNotNull($veredicto);
        $this->assertSame($esperado['code'], $veredicto['code']);
        if (array_key_exists('payload', $esperado)) {
            $this->assertSame($esperado['payload'], $veredicto['payload']);
        }
    }

    public function test_cada_puerta_del_fixture_cae_antes_en_o_despues_de_la_parte_pura(): void
    {
        $vistas = ['publish' => [], 'promote' => []];
        $aplicables = ['publish' => 0, 'promote' => 0];
        foreach (self::fixture()['gates'] as $caso) {
            $tipo = $caso['kind'];
            if (! isset(self::BEFORE[$tipo])) {
                $this->assertContains($tipo, ['rollback', 'legacy'], $caso['name']);

                continue;
            }
            $codigo = $caso['expected']['code'];
            $this->assertTrue(
                in_array($codigo, self::BEFORE[$tipo], true) || in_array($codigo, self::PURE[$tipo], true) || in_array($codigo, self::AFTER[$tipo], true),
                "Código sin clasificar en esta prueba: {$codigo} ({$caso['name']}).",
            );
            if (! in_array($codigo, self::BEFORE[$tipo], true)) {
                $aplicables[$tipo]++;
                $vistas[$tipo][] = $codigo;
            }
        }

        // 25 de publicar y 5 de promover pasan por la parte pura; cada código suyo sale al menos una vez.
        $this->assertSame(['publish' => 25, 'promote' => 5], $aplicables);
        foreach (self::PURE as $tipo => $codigos) {
            foreach ($codigos as $codigo) {
                $this->assertContains($codigo, $vistas[$tipo], "Ningún caso de {$tipo} llega a {$codigo}.");
            }
        }
    }

    // ── Cruce con las cabezas de `scope` (del lector del motor) ─────────────

    public function test_el_studio_lee_las_cabezas_que_el_lector_acepta_y_no_las_que_rechaza_por_su_forma(): void
    {
        $leidas = 0;
        $rechazadas = 0;
        foreach (self::fixture()['scope'] as $caso) {
            $fila = $caso['head'];
            $sello = $caso['expected']['stamp'];
            if ($fila === null || $sello === null) {
                continue;
            }

            if (in_array($sello['state'], ['applied', 'legacy', 'out_of_scope'], true)) {
                $partes = D::parts($fila['effectiveJson'], $fila['uuid'], $fila['seq']);
                if ($sello['state'] === 'applied') {
                    $this->assertSame($sello['version'], $partes[$sello['part']]['version'], $caso['name']);
                    $this->assertSame($sello['contentHash'], $partes[$sello['part']]['contentHash'], $caso['name']);
                }
                $leidas++;
            } elseif ($sello['state'] === 'fallback' && $sello['reason'] === 'malformed') {
                try {
                    D::parts($fila['effectiveJson'], $fila['uuid'], $fila['seq']);
                    $this->fail("El Studio copiaría una cabeza que el lector llama malformed: {$caso['name']}");
                } catch (InvalidArgumentException) {
                    $rechazadas++;
                }
            }
        }

        $this->assertSame(26, $leidas);
        $this->assertSame(16, $rechazadas);
    }

    /** Una parte sintética de esa versión (null si no hay versión), con las identidades de la publicación. */
    private static function part(?string $version): ?array
    {
        return $version === null ? null : D::part($version, hash('sha256', $version), StudioCatalog::VERSION, 12, 7, self::DECLARED);
    }
}
