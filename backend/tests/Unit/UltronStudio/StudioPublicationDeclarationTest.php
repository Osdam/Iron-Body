<?php

namespace Tests\Unit\UltronStudio;

use App\Models\UltronStudio\StudioLabVerdict;
use App\Services\Marketing\Ultron\UltronPublishedConfig;
use App\Services\UltronStudio\StudioCatalog;
use App\Services\UltronStudio\StudioPublicationDeclaration as D;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * La declaración que se publica (contrato H5 §A1, §A4.2, §A5.1 pasos 8 y 9,
 * §A5.2–A5.4 y Enmienda 1) en lo que el fixture no afirma: la paridad caso a
 * caso está en `StudioPublicationParityTest`.
 *
 * LO QUE SE PROTEGE. Que el núcleo y el lector del motor hablen el mismo
 * contrato (constantes atadas; los `env_flag` del catálogo son los cuatro de
 * `declared`); que lo que se escribe el motor no pueda leerlo como `malformed`;
 * que al promover la parte pase entera, con las identidades de su publicación
 * (Q-3), y que revertir la publicada conserve el canario en curso (Q-2); que
 * una entrada que rompería la invariante de §A2 no se componga; que la firma
 * solo entre por su punto de inyección y con la forma del contrato; que
 * `previewHash` sea exactamente el objeto de Q-4 (vector escrito a mano) y
 * cambie con todo lo que enseña la vista previa; y que una lectura caída cuente
 * como deriva (Q-5). Catálogo: el REAL salvo donde se simula otro.
 */
class StudioPublicationDeclarationTest extends TestCase
{
    private const A = '11111111-1111-4111-8111-111111111111';

    private const B = '22222222-2222-4222-8222-222222222222';

    private const C = '33333333-3333-4333-8333-333333333333';

    private const Z = '99999999-9999-4999-8999-999999999999';

    /** Un uuid con letras: el de las variantes en mayúsculas (los de A, B, C y Z son solo dígitos). */
    private const LETRAS = 'abcdef01-2345-4678-89ab-cdef01234567';

    private const E1 = 'eeeeeeee-0000-4000-8000-000000000001';

    private const E2 = 'eeeeeeee-0000-4000-8000-000000000002';

    private const DECLARED = ['governance.canaryOnly' => true, 'governance.enabled' => true, 'intent.driver' => 'entorno', 'rules.paymentLinksEnabled' => false];

    // ── Ayudas ──────────────────────────────────────────────────────────────

    /** @return array<string,array<string,mixed>> */
    private static function base(): array
    {
        return StudioCatalog::baseline()['configs'];
    }

    /** Las lecturas del motor de hoy (efectivo openai), con cambios por id. */
    private static function readings(array $cambios = []): array
    {
        return array_replace([
            'intent.driver' => ['available' => true, 'value' => 'openai'],
            'rules.paymentLinksEnabled' => ['available' => true, 'value' => false],
            'governance.enabled' => ['available' => true, 'value' => true],
            'governance.canaryOnly' => ['available' => true, 'value' => true],
            'strategy.offerPriority' => ['available' => true, 'value' => ['Plan Insignia', 'Plan Mensual']],
        ], $cambios);
    }

    private static function classify(array $configs, array $rules = [], ?array $readings = null): array
    {
        return D::classify($configs, $rules, self::base(), $readings ?? self::readings(), StudioCatalog::toArray());
    }

    private static function part(string $version, int $publishedBy = 7, int $approvedBy = 12, array $declared = self::DECLARED): array
    {
        return D::part($version, hash('sha256', $version), StudioCatalog::VERSION, $approvedBy, $publishedBy, $declared);
    }

    /** @param  array<string,mixed>  $cambios  `capa.clave` → valor */
    private static function configs(array $cambios): array
    {
        $c = self::base();
        foreach ($cambios as $id => $valor) {
            [$capa, $clave] = explode('.', $id, 2);
            $c[$capa][$clave] = $valor;
        }

        return $c;
    }

    // ── El mismo contrato que el motor ──────────────────────────────────────

    public function test_el_contrato_y_el_tope_son_los_del_lector_del_motor(): void
    {
        $this->assertSame(UltronPublishedConfig::CONTRACT, D::CONTRACT);
        $this->assertSame(UltronPublishedConfig::MAX_EFFECTIVE_JSON, D::MAX_EFFECTIVE_JSON);
    }

    public function test_los_must_match_son_los_env_flag_del_catalogo_en_su_orden_y_con_su_tipo(): void
    {
        $banderas = [];
        foreach (StudioCatalog::layerIds() as $capa) {
            foreach (StudioCatalog::bindings()[$capa] ?? [] as $clave => $mando) {
                if ($mando['origin'] === 'env_flag') {
                    $banderas[] = "{$capa}.{$clave}";
                    $this->assertSame(D::MUST_MATCH["{$capa}.{$clave}"], gettype(self::base()[$capa][$clave]));
                }
            }
        }

        $this->assertSame(array_keys(D::MUST_MATCH), $banderas);
    }

    public function test_los_latest_del_laboratorio_son_sin_veredicto_las_decisiones_del_veredicto_y_prompts_viejos(): void
    {
        $this->assertSame([null, ...StudioLabVerdict::DECISIONS, 'stale_prompts'], D::LAB_LATEST);
    }

    // ── Clasificación ───────────────────────────────────────────────────────

    /** @return iterable<string,array{Closure(array): array}> */
    public static function catalogosQueNoCasan(): iterable
    {
        yield 'un quinto env_flag' => [static function (array $c): array {
            $c['bindings']['composer']['critic']['origin'] = 'env_flag';

            return $c;
        }];
        yield 'un must_match que deja de ser env_flag' => [static function (array $c): array {
            $c['bindings']['governance']['enabled']['origin'] = 'fixed';

            return $c;
        }];
        yield 'los env_flag en otro orden (las capas al revés)' => [static function (array $c): array {
            $c['layers'] = array_reverse($c['layers']);

            return $c;
        }];
        yield 'un origen desconocido' => [static function (array $c): array {
            $c['bindings']['persona']['tone']['origin'] = 'magia';

            return $c;
        }];
        yield 'mandos de una capa que no existe' => [static function (array $c): array {
            $c['bindings']['nueva'] = ['x' => ['origin' => 'fixed', 'valueSource' => 'none']];

            return $c;
        }];
        yield 'sin bindings' => [static function (array $c): array {
            unset($c['bindings']);

            return $c;
        }];
    }

    #[DataProvider('catalogosQueNoCasan')]
    public function test_un_catalogo_que_no_casa_con_el_contrato_del_motor_no_clasifica(Closure $cambio): void
    {
        $this->expectException(InvalidArgumentException::class);

        D::classify(self::base(), [], self::base(), self::readings(), $cambio(StudioCatalog::toArray()));
    }

    /** @return iterable<string,array{array<string,array<string,mixed>>,list<array<string,mixed>>}> */
    public static function contenidosSinForma(): iterable
    {
        $base = StudioCatalog::baseline()['configs'];

        $falta = $base;
        unset($falta['persona']['tone']);
        yield 'falta un parámetro' => [$falta, []];

        $sobra = $base;
        $sobra['persona']['voz'] = 'grave';
        yield 'sobra un parámetro' => [$sobra, []];

        yield 'una capa que no existe' => [$base + ['nueva' => []], []];
        yield 'un decimal (la huella no los admite)' => [array_replace_recursive($base, ['persona' => ['maxChars' => 320.0]]), []];
        yield 'una regla sin código' => [$base, [['source' => 'studio', 'status' => 'draft']]];
        yield 'un código que no es S-NNN ni R-xx' => [$base, [['code' => 'X-001']]];
        yield 'una S-NNN repetida' => [$base, [['code' => 'S-001'], ['code' => 'S-001']]];
    }

    #[DataProvider('contenidosSinForma')]
    public function test_un_contenido_sin_la_forma_del_catalogo_no_clasifica(array $configs, array $rules): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::classify($configs, $rules);
    }

    /** @return iterable<string,array{mixed}> */
    public static function lecturasSinForma(): iterable
    {
        yield 'sin la entrada' => [null];
        yield 'disponible sin valor' => [['available' => true]];
        yield 'available como texto' => [['available' => 'true', 'value' => true]];
        yield 'no disponible aunque traiga valor' => [['available' => false, 'value' => true]];
        yield 'un texto en vez de la lectura' => ['true'];
    }

    #[DataProvider('lecturasSinForma')]
    public function test_una_lectura_sin_la_forma_de_engine_readings_es_una_lectura_caida(mixed $lectura): void
    {
        $lecturas = self::readings();
        if ($lectura === null) {
            unset($lecturas['governance.enabled']);
        } else {
            $lecturas['governance.enabled'] = $lectura;
        }

        $r = self::classify(self::base(), [], $lecturas);

        $this->assertSame(['param' => 'governance.enabled', 'versionValue' => true, 'engineValue' => null, 'ok' => false], $r['mustMatch'][2]);
        $this->assertSame(['governance.enabled'], $r['engineReadingUnavailable']);
        $this->assertSame([], $r['mismatches']);
    }

    public function test_la_igualdad_es_estricta_en_el_tipo(): void
    {
        $r = self::classify(self::configs(['governance.enabled' => 1, 'persona.emojis' => 1, 'context.windowTurns' => '10']));

        $this->assertSame([['param' => 'governance.enabled', 'versionValue' => 1, 'engineValue' => true]], $r['mismatches']);
        $this->assertSame(['persona.emojis', 'context.windowTurns'], $r['noEffect']);
        $this->assertNotContains('context.windowTurns', $r['matches']);
    }

    // ── Partes y texto firmado ──────────────────────────────────────────────

    public function test_declared_son_los_cuatro_must_match_de_la_version(): void
    {
        $this->assertSame(
            ['intent.driver' => 'entorno', 'rules.paymentLinksEnabled' => false, 'governance.enabled' => true, 'governance.canaryOnly' => true],
            D::declared(self::base()),
        );
        $this->assertSame('openai', D::declared(self::configs(['intent.driver' => 'openai']))['intent.driver']);
    }

    public function test_declared_no_inventa_un_valor_que_falta_ni_acepta_otro_tipo(): void
    {
        $falta = self::base();
        unset($falta['governance']['canaryOnly']);
        foreach ([$falta, self::configs(['governance.canaryOnly' => 'true'])] as $configs) {
            try {
                D::declared($configs);
                $this->fail('declared() aceptó una configuración sin la forma del contrato.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_una_parte_lleva_solo_las_identidades_y_los_cuatro_must_match_por_orden_de_bytes(): void
    {
        $desordenado = ['intent.driver' => 'entorno', 'governance.enabled' => true, 'rules.paymentLinksEnabled' => false, 'governance.canaryOnly' => true];
        $p = D::part(self::B, str_repeat('b', 64), '2026-10-19.1', 12, 7, $desordenado);

        $this->assertSame([
            'approvedBy' => 12,
            'catalogVersion' => '2026-10-19.1',
            'contentHash' => str_repeat('b', 64),
            'declared' => self::DECLARED,
            'publishedBy' => 7,
            'version' => self::B,
        ], $p);
    }

    /** @return iterable<string,array{list<mixed>}> */
    public static function partesQueElMotorNoLeeria(): iterable
    {
        $bien = [self::B, str_repeat('b', 64), '2026-10-19.1', 12, 7, self::DECLARED];
        $con = static function (int $i, mixed $valor) use ($bien): array {
            $bien[$i] = $valor;

            return [$bien];
        };

        yield 'version en mayúsculas' => $con(0, strtoupper(self::LETRAS));
        yield 'version que no es un uuid' => $con(0, 'version-b');
        yield 'contentHash de 63' => $con(1, str_repeat('b', 63));
        yield 'contentHash en mayúsculas' => $con(1, str_repeat('B', 64));
        yield 'catalogVersion vacío' => $con(2, '');
        yield 'catalogVersion de 33' => $con(2, str_repeat('1', 33));
        yield 'catalogVersion con un espacio' => $con(2, '2026 10');
        yield 'approvedBy 0' => $con(3, 0);
        yield 'publishedBy negativo' => $con(4, -7);
        yield 'driver vacío' => $con(5, ['intent.driver' => ''] + self::DECLARED);
        yield 'declared de 3 claves' => $con(5, array_slice(self::DECLARED, 0, 3, true));
        yield 'declared de 5 claves' => $con(5, self::DECLARED + ['persona.tone' => 'cercano']);
        yield 'governance.enabled como texto' => $con(5, ['governance.enabled' => 'true'] + self::DECLARED);
    }

    #[DataProvider('partesQueElMotorNoLeeria')]
    public function test_una_parte_que_el_motor_no_leeria_no_se_construye(array $args): void
    {
        $this->expectException(InvalidArgumentException::class);

        D::part(...$args);
    }

    /** @return iterable<string,array{string,int,array<string,mixed>|null,array<string,mixed>|null}> */
    public static function entradasQueElMotorNoLeeria(): iterable
    {
        $b = self::part(self::B);

        yield 'entry en mayúsculas' => [strtoupper(self::E1), 1, $b, null];
        yield 'seq 0' => [self::E1, 0, $b, null];
        yield 'una clave de más en la parte' => [self::E1, 1, $b + ['label' => 'Versión B'], null];
        yield 'el resto como lista vacía' => [self::E1, 1, $b, []];
        yield 'una parte sin approvedBy' => [self::E1, 1, null, array_diff_key($b, ['approvedBy' => true])];
    }

    #[DataProvider('entradasQueElMotorNoLeeria')]
    public function test_effective_json_no_escribe_lo_que_el_motor_leeria_como_malformed(string $entry, int $seq, ?array $canary, ?array $rest): void
    {
        $this->expectException(InvalidArgumentException::class);

        D::effectiveJson($entry, $seq, new DateTimeImmutable('2026-11-03T15:04:05Z'), $canary, $rest);
    }

    public function test_la_entrada_mas_larga_posible_queda_lejos_del_tope_del_motor(): void
    {
        // Los textos que más crecen al escribirse en JSON (cada " y cada \ se escapan) y los enteros más largos.
        $declarado = ['intent.driver' => str_repeat('\\', 32)] + self::DECLARED;
        $parte = D::part(self::B, str_repeat('f', 64), str_repeat('"', 32), PHP_INT_MAX, PHP_INT_MAX, $declarado);

        $json = D::effectiveJson(self::E1, PHP_INT_MAX, new DateTimeImmutable('9999-12-31T23:59:59Z'), $parte, $parte);

        $this->assertLessThan(intdiv(D::MAX_EFFECTIVE_JSON, 8), mb_strlen($json, 'UTF-8'));
    }

    public function test_issued_at_es_el_mismo_segundo_utc_venga_en_la_zona_que_venga(): void
    {
        $bogota = new DateTimeImmutable('2026-11-03 10:04:05.999999', new DateTimeZone('America/Bogota'));

        $this->assertSame('2026-11-03T15:04:05Z', D::utc($bogota));
        $this->assertSame('2026-11-04T01:30:00Z', D::utc(new DateTimeImmutable('2026-11-03 20:30:00', new DateTimeZone('America/Bogota'))));
    }

    // ── La firma: solo por su punto de inyección ────────────────────────────

    /** @return iterable<string,array{mixed}> */
    public static function firmasSinForma(): iterable
    {
        $buena = hash('sha256', 'x');

        yield 'en mayúsculas' => [strtoupper($buena)];
        yield 'de 63' => [substr($buena, 1)];
        yield 'con un salto de línea al final' => [$buena."\n"];
        yield 'vacía' => [''];
        yield 'no hexadecimal' => [str_repeat('g', 64)];
        yield 'un entero' => [42];
    }

    #[DataProvider('firmasSinForma')]
    public function test_una_firma_sin_la_forma_del_contrato_no_se_escribe(mixed $firma): void
    {
        $this->expectException(UnexpectedValueException::class);

        D::declaration(self::E1, 1, new DateTimeImmutable('2026-11-03T15:04:05Z'), self::part(self::B), null, static fn (string $texto): mixed => $firma);
    }

    public function test_la_firma_recibe_los_bytes_de_la_entrada_y_sale_tal_cual(): void
    {
        $vistos = [];
        $d = D::declaration(self::E1, 1, new DateTimeImmutable('2026-11-03T15:04:05Z'), self::part(self::B), null, static function (string $texto) use (&$vistos): string {
            $vistos[] = $texto;

            return hash('sha256', 'firma:'.$texto);
        });

        $this->assertCount(1, $vistos);
        $this->assertSame($d['effectiveJson'], $vistos[0]);
        $this->assertSame(hash('sha256', 'firma:'.$vistos[0]), $d['signature']);
        $this->assertSame(hash('sha256', $vistos[0]), $d['effectiveHash']);
        $this->assertSame(D::CONTRACT, $d['contract']);
    }

    // ── Composición: Q-2, Q-3 y la invariante de §A2 ────────────────────────

    public function test_al_promover_la_parte_canaria_pasa_entera_al_resto_con_las_identidades_de_su_publicacion(): void
    {
        $b = self::part(self::B, publishedBy: 7, approvedBy: 12);
        $a = self::part(self::A, publishedBy: 3, approvedBy: 4);

        $r = D::compose(D::ACTION_PROMOTE, self::B, 'CANARY', ['canary' => $b, 'rest' => $a]);

        $this->assertSame(['canary' => $b, 'rest' => $b], $r);
        $json = D::effectiveJson(self::E2, 2, new DateTimeImmutable('2026-11-04T16:30:00Z'), $r['canary'], $r['rest']);
        $this->assertSame(2, substr_count($json, '"publishedBy":7'));
        $this->assertSame(2, substr_count($json, '"approvedBy":12'));
    }

    public function test_revertir_la_publicada_con_otra_version_en_canario_conserva_su_parte(): void
    {
        $b = self::part(self::B);
        $a = self::part(self::A);
        $z = self::part(self::Z);
        $cabeza = ['canary' => $b, 'rest' => $a];

        $this->assertSame(['canary' => $b, 'rest' => $z], D::compose(D::ACTION_ROLLBACK, self::A, 'PUBLISHED', $cabeza, null, $z));
        $this->assertSame(['canary' => $b, 'rest' => null], D::compose(D::ACTION_ROLLBACK, self::A, 'PUBLISHED', $cabeza));
    }

    public function test_publicar_y_revertir_el_canario_copian_la_parte_resto_tal_cual(): void
    {
        $a = self::part(self::A, publishedBy: 3, approvedBy: 4);
        $c = self::part(self::C);

        $this->assertSame(['canary' => $c, 'rest' => $a], D::compose(D::ACTION_PUBLISH, self::C, 'APPROVED', ['canary' => $a, 'rest' => $a], $c));
        $this->assertSame(['canary' => $a, 'rest' => $a], D::compose(D::ACTION_ROLLBACK, self::C, 'CANARY', ['canary' => $c, 'rest' => $a]));
    }

    /** @return iterable<string,array{Closure(): mixed}> */
    public static function composicionesQueRompenLaInvariante(): iterable
    {
        $a = self::part(self::A);
        $b = self::part(self::B);
        $c = self::part(self::C);
        $enCanario = ['canary' => $b, 'rest' => $a];
        $publicada = ['canary' => $a, 'rest' => $a];

        yield 'publicar con otra versión en canario' => [static fn () => D::compose(D::ACTION_PUBLISH, self::C, 'APPROVED', $enCanario, $c)];
        yield 'publicar una versión que no está APPROVED' => [static fn () => D::compose(D::ACTION_PUBLISH, self::C, 'VALIDATED', $publicada, $c)];
        yield 'publicar sin la parte nueva' => [static fn () => D::compose(D::ACTION_PUBLISH, self::C, 'APPROVED', $publicada)];
        yield 'publicar con la parte de otra versión' => [static fn () => D::compose(D::ACTION_PUBLISH, self::C, 'APPROVED', $publicada, $b)];
        yield 'publicar con un uuid en mayúsculas' => [static fn () => D::compose(D::ACTION_PUBLISH, strtoupper(self::LETRAS), 'APPROVED', $publicada, self::part(self::LETRAS))];
        yield 'promover otra versión que la canaria' => [static fn () => D::compose(D::ACTION_PROMOTE, self::C, 'CANARY', $enCanario)];
        yield 'promover sin canario en curso' => [static fn () => D::compose(D::ACTION_PROMOTE, self::A, 'CANARY', $publicada)];
        yield 'promover desde APPROVED' => [static fn () => D::compose(D::ACTION_PROMOTE, self::B, 'APPROVED', $enCanario)];
        yield 'revertir como canaria otra versión' => [static fn () => D::compose(D::ACTION_ROLLBACK, self::C, 'CANARY', $enCanario)];
        yield 'revertir como publicada una versión que no está en el resto' => [static fn () => D::compose(D::ACTION_ROLLBACK, self::B, 'PUBLISHED', $publicada)];
        yield 'revertir restaurando la misma versión' => [static fn () => D::compose(D::ACTION_ROLLBACK, self::A, 'PUBLISHED', $publicada, null, $a)];
        yield 'revertir una RETIRED' => [static fn () => D::compose(D::ACTION_ROLLBACK, self::A, 'RETIRED', $publicada)];
        yield 'Legacy sobre una versión' => [static fn () => D::compose(D::ACTION_LEGACY, self::A, 'PUBLISHED', $publicada)];
        yield 'una acción desconocida' => [static fn () => D::compose('delete', self::A, 'PUBLISHED', $publicada)];
        yield 'una parte nueva fuera de publicar' => [static fn () => D::compose(D::ACTION_PROMOTE, self::B, 'CANARY', $enCanario, $b)];
        yield 'una parte restaurada fuera de revertir la publicada' => [static fn () => D::compose(D::ACTION_PUBLISH, self::C, 'APPROVED', $publicada, $c, $a)];
        yield 'una cabeza con una clave de más' => [static fn () => D::compose(D::ACTION_LEGACY, null, null, ['canary' => null, 'rest' => null, 'seq' => 3])];
        yield 'una cabeza con una parte sin forma' => [static fn () => D::compose(D::ACTION_PUBLISH, self::C, 'APPROVED', ['canary' => ['version' => self::B], 'rest' => null], $c)];
    }

    #[DataProvider('composicionesQueRompenLaInvariante')]
    public function test_no_se_compone_una_entrada_que_rompa_la_invariante_de_la_cadena(Closure $componer): void
    {
        $this->expectException(InvalidArgumentException::class);

        $componer();
    }

    public function test_una_cadena_entera_se_compone_leyendo_cada_cabeza_escrita(): void
    {
        $b = self::part(self::B, publishedBy: 7, approvedBy: 12);
        $c = self::part(self::C, publishedBy: 9, approvedBy: 15);
        $a = self::part(self::A, publishedBy: 5, approvedBy: 6);

        $pasos = [
            ['publish', self::B, 'APPROVED', $b, null, [self::B, null]],
            ['promote', self::B, 'CANARY', null, null, [self::B, self::B]],
            ['publish', self::C, 'APPROVED', $c, null, [self::C, self::B]],
            ['rollback', self::C, 'CANARY', null, null, [self::B, self::B]],
            ['publish', self::A, 'APPROVED', $a, null, [self::A, self::B]],
            ['promote', self::A, 'CANARY', null, null, [self::A, self::A]],
            // Revertir A restaura la parte resto de la entrada anterior a su promoción (la 5: B).
            ['rollback', self::A, 'PUBLISHED', null, 'restaurar', [self::B, self::B]],
            ['legacy', null, null, null, null, [null, null]],
        ];

        $cabeza = null;
        $escritas = [];
        foreach ($pasos as $i => [$accion, $version, $estado, $parte, $restaurar, $esperado]) {
            $seq = $i + 1;
            $entrada = sprintf('eeeeeeee-0000-4000-8000-%012d', $seq);
            $restores = $restaurar === null ? null : $escritas[5]['rest'];

            $r = D::compose($accion, $version, $estado, $cabeza, $parte, $restores);
            $json = D::effectiveJson($entrada, $seq, new DateTimeImmutable('2026-11-03T15:00:00Z'), $r['canary'], $r['rest']);
            $cabeza = D::parts($json, $entrada, $seq);
            $escritas[$seq] = $cabeza;

            $this->assertSame($esperado, [$cabeza['canary']['version'] ?? null, $cabeza['rest']['version'] ?? null], "paso {$seq}: {$accion}");
        }

        // B vuelve con las identidades de SU publicación, no las de quien promovió ni las de quien revirtió.
        $this->assertSame($b, $escritas[7]['rest']);
        $this->assertSame($b, $escritas[7]['canary']);
    }

    public function test_parts_solo_lee_la_entrada_de_esa_fila_y_del_contrato(): void
    {
        $json = D::effectiveJson(self::E1, 1, new DateTimeImmutable('2026-11-03T15:04:05Z'), self::part(self::B), null);

        // Como el lector: el uuid de la fila sin distinguir mayúsculas.
        $this->assertSame(self::B, D::parts($json, strtoupper(self::E1), 1)['canary']['version']);

        $malas = [
            'otra fila' => [$json, self::E2, 1],
            'otro seq' => [$json, self::E1, 2],
            'otro contrato' => [str_replace('ultron-engine-config/1', 'ultron-engine-config/2', $json), self::E1, 1],
            'no es JSON' => ['{"canary":', self::E1, 1],
            'un objeto cualquiera' => ['{"a":1}', self::E1, 1],
            'una lista' => ['[]', self::E1, 1],
        ];
        foreach ($malas as $motivo => [$texto, $entrada, $seq]) {
            try {
                D::parts($texto, $entrada, $seq);
                $this->fail("parts() leyó una entrada que no es la de esa fila o del contrato: {$motivo}.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ── previewHash (Q-4) ───────────────────────────────────────────────────

    public function test_preview_hash_es_la_huella_canonica_del_objeto_de_q4(): void
    {
        $clasificacion = [
            'mustMatch' => [['param' => 'intent.driver', 'versionValue' => 'entorno', 'engineValue' => 'openai', 'ok' => true]],
            'matches' => ['knowledge.strictFacts'],
            'noEffect' => ['persona.tone', 'rule:S-001'],
            'mismatches' => [],
            'engineReadingUnavailable' => [],
        ];

        // Escrito a mano desde Q-4: claves por orden de bytes en cada nivel, sin espacios.
        $bytes = '{"catalogVersion":"2026-10-19.1","contentHash":"'.str_repeat('b', 64).'",'
            .'"labGate":{"latest":null,"mode":"required"},"matches":["knowledge.strictFacts"],'
            .'"mustMatch":[{"engineValue":"openai","ok":true,"param":"intent.driver","versionValue":"entorno"}],'
            .'"noEffect":["persona.tone","rule:S-001"],"versionUuid":"'.self::B.'"}';

        $this->assertSame(
            hash('sha256', $bytes),
            D::previewHash(self::B, str_repeat('b', 64), '2026-10-19.1', $clasificacion, ['mode' => 'required', 'latest' => null]),
        );
    }

    public function test_preview_hash_es_estable_y_cambia_con_todo_lo_que_ensena_la_vista_previa(): void
    {
        $huella = static fn (array $clasificacion, string $version = self::B, string $contenido = '', string $catalogo = '2026-10-19.1', array $lab = ['mode' => 'required', 'latest' => 'approved']): string => D::previewHash($version, $contenido === '' ? str_repeat('b', 64) : $contenido, $catalogo, $clasificacion, $lab);

        $base = self::classify(self::base(), [['code' => 'S-001']]);
        $h = $huella($base);

        // Estable: el mismo contenido, con las claves en otro orden, da la misma huella.
        $this->assertSame($h, $huella(array_reverse($base, true), lab: ['latest' => 'approved', 'mode' => 'required']));
        // `mismatches` y `engineReadingUnavailable` no entran (se deducen de mustMatch).
        $this->assertSame($h, $huella(['mismatches' => [['param' => 'x']], 'engineReadingUnavailable' => ['y']] + $base));

        $distintas = [
            'otra versión' => $huella($base, version: self::C),
            'otra huella de contenido' => $huella($base, contenido: str_repeat('c', 64)),
            'otra versión del catálogo' => $huella($base, catalogo: '2026-10-20.1'),
            'otra lectura del motor' => $huella(self::classify(self::base(), [['code' => 'S-001']], self::readings(['intent.driver' => ['available' => true, 'value' => 'rules']]))),
            'un parámetro más sin efecto' => $huella(self::classify(self::configs(['persona.tone' => 'profesional']), [['code' => 'S-001']])),
            'un fijo que deja de coincidir' => $huella(self::classify(self::configs(['knowledge.strictFacts' => false]), [['code' => 'S-001']])),
            'una regla menos' => $huella(self::classify(self::base())),
            'el laboratorio dispensado' => $huella($base, lab: ['mode' => 'waived', 'latest' => 'approved']),
            'otro veredicto' => $huella($base, lab: ['mode' => 'required', 'latest' => 'needs_changes']),
        ];
        foreach ($distintas as $que => $otra) {
            $this->assertNotSame($h, $otra, "previewHash no cambió con {$que}.");
        }
        $this->assertCount(count($distintas), array_unique($distintas));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function puertasDelLaboratorioSinForma(): iterable
    {
        yield 'sin latest' => [['mode' => 'required']];
        yield 'con una clave de más' => [['mode' => 'required', 'latest' => null, 'runUuid' => self::A]];
        yield 'un modo que no existe' => [['mode' => 'off', 'latest' => null]];
        yield 'un veredicto que no existe' => [['mode' => 'required', 'latest' => 'pending']];
        yield 'latest false' => [['mode' => 'required', 'latest' => false]];
    }

    #[DataProvider('puertasDelLaboratorioSinForma')]
    public function test_preview_hash_no_firma_una_puerta_del_laboratorio_sin_forma(array $lab): void
    {
        $this->expectException(InvalidArgumentException::class);

        D::previewHash(self::B, str_repeat('b', 64), '2026-10-19.1', self::classify(self::base()), $lab);
    }

    public function test_preview_hash_necesita_las_tres_listas_de_la_clasificacion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        D::previewHash(self::B, str_repeat('b', 64), '2026-10-19.1', ['matches' => [], 'noEffect' => []], ['mode' => 'required', 'latest' => null]);
    }

    // ── Pasos 8 y 9 de publicar ─────────────────────────────────────────────

    public function test_el_reconocimiento_es_un_conjunto_exacto(): void
    {
        $clasificacion = ['noEffect' => ['persona.tone', 'rule:S-001']];

        $this->assertTrue(D::acknowledges($clasificacion, ['persona.tone', 'rule:S-001']));
        $this->assertTrue(D::acknowledges($clasificacion, ['rule:S-001', 'persona.tone']));
        $this->assertTrue(D::acknowledges($clasificacion, ['persona.tone', 'persona.tone', 'rule:S-001']));
        $this->assertFalse(D::acknowledges($clasificacion, ['persona.tone']));
        $this->assertFalse(D::acknowledges($clasificacion, ['persona.tone', 'rule:S-001', 'rule:S-002']));
        $this->assertFalse(D::acknowledges($clasificacion, []));
        $this->assertFalse(D::acknowledges($clasificacion, ['persona.tone', 1]));
        $this->assertTrue(D::acknowledges(['noEffect' => []], []));
        $this->assertFalse(D::acknowledges(['noEffect' => []], ['persona.tone']));
    }

    public function test_los_pasos_8_y_9_rechazan_en_su_orden_y_con_su_payload(): void
    {
        $desacuerdo = ['param' => 'governance.enabled', 'versionValue' => true, 'engineValue' => false];
        $clasificacion = ['mismatches' => [$desacuerdo], 'engineReadingUnavailable' => ['intent.driver'], 'noEffect' => ['persona.tone', 'rule:S-001']];
        $vieja = str_repeat('f0', 32);
        $actual = str_repeat('0f', 32);

        $this->assertSame(
            ['code' => 'engine_value_mismatch', 'payload' => ['mismatches' => [$desacuerdo]]],
            D::refusal($clasificacion, [], $vieja, $actual),
        );

        $clasificacion['mismatches'] = [];
        $this->assertSame(
            ['code' => 'engine_reading_unavailable', 'payload' => ['engineReadingUnavailable' => ['intent.driver']]],
            D::refusal($clasificacion, [], $vieja, $actual),
        );

        $clasificacion['engineReadingUnavailable'] = [];
        $this->assertSame(
            ['code' => 'no_effect_not_acknowledged', 'payload' => ['noEffect' => ['persona.tone', 'rule:S-001']]],
            D::refusal($clasificacion, ['rule:S-001'], $vieja, $actual),
        );

        $this->assertSame(['code' => 'preview_stale', 'payload' => null], D::refusal($clasificacion, ['rule:S-001', 'persona.tone'], $vieja, $actual));
        $this->assertNull(D::refusal($clasificacion, ['rule:S-001', 'persona.tone'], $actual, $actual));
    }

    // ── Deriva (Q-5) ────────────────────────────────────────────────────────

    public function test_la_deriva_compara_como_must_match_y_una_lectura_caida_cuenta(): void
    {
        $catalogo = StudioCatalog::toArray();
        $caida = ['available' => false];

        $this->assertSame([], D::drift(self::DECLARED, self::readings(), $catalogo));
        $this->assertSame(
            [['param' => 'rules.paymentLinksEnabled', 'declared' => false, 'engine' => true]],
            D::drift(self::DECLARED, self::readings(['rules.paymentLinksEnabled' => ['available' => true, 'value' => true]]), $catalogo),
        );
        $this->assertSame(
            [['param' => 'intent.driver', 'declared' => 'entorno', 'engine' => null]],
            D::drift(self::DECLARED, self::readings(['intent.driver' => $caida]), $catalogo),
        );

        $openai = ['intent.driver' => 'openai'] + self::DECLARED;
        $this->assertSame([], D::drift($openai, self::readings(), $catalogo));
        $this->assertSame(
            [['param' => 'intent.driver', 'declared' => 'openai', 'engine' => 'rules']],
            D::drift($openai, self::readings(['intent.driver' => ['available' => true, 'value' => 'fake']]), $catalogo),
        );

        // Varias a la vez, en el orden del catálogo.
        $this->assertSame(
            [
                ['param' => 'governance.enabled', 'declared' => true, 'engine' => false],
                ['param' => 'governance.canaryOnly', 'declared' => true, 'engine' => null],
            ],
            D::drift(self::DECLARED, self::readings(['governance.canaryOnly' => $caida, 'governance.enabled' => ['available' => true, 'value' => false]]), $catalogo),
        );
    }

    public function test_la_deriva_solo_se_mide_sobre_una_declaracion_con_su_forma(): void
    {
        $this->expectException(InvalidArgumentException::class);

        D::drift(array_slice(self::DECLARED, 0, 3, true), self::readings(), StudioCatalog::toArray());
    }
}
