<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase as Base;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler as Compiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * E2/E3 DEL TESTER · EL COMPILADOR FRENTE A UNA DERIVACIÓN INDEPENDIENTE DEL
 * CONTRATO (Enmienda M-1 del H5; plan de motores §B.3, paso 5a).
 *
 * `tests/fixtures/ultron-prompts/compile-cases.json` lo genera un guion Node del
 * tester (`tools/derivar-compile-cases.cjs` + `tools/parches-m1.cjs`) que aplica
 * la tabla de parches de §B.3 sobre la base del repositorio SIN leer
 * `UltronPromptCompiler.php`: los textos están transcritos del plan (y el guion
 * exige que aparezcan literalmente en §B.3) y la base se comprueba contra el
 * flujo vivo 29de5119 exportado. Cubre los 306 valores válidos (3 tonos × 2
 * emojis × 17 `maxChars` × 3 objetivos: la línea base, cada valor suelto y todas
 * las combinaciones) y 26 entradas que el contrato no admite.
 *
 * Lo que se fija:
 *  1. el fixture y las constantes publicadas hablan del mismo contrato (base,
 *     compilador, línea base, roles, huella Legacy);
 *  2. por caso, `compile()` da EXACTAMENTE los bytes derivados (sha256 y
 *     tamaño de cada rol, incluidos los que no cambian) y la huella del paquete;
 *  3. por caso, la vista previa (`patches()`) enumera, rol a rol y en orden,
 *     los parches del contrato con sus textos, y es la misma lista que `compile()`;
 *  4. el resultado no depende del orden de las claves de entrada;
 *  5. lo que el contrato no admite no compila (`invalid_governed`), en las tres entradas;
 *  6. el fixture sigue siendo el que sale hoy del contrato y de la base (Node);
 *  7. el selector generado para n8n acepta, con su SHA-256 en JS, los 306
 *     paquetes del contrato y cae a Legacy sin lanzar ante todo lo demás (Node).
 */
class UltronPromptCompileCasesTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../../../fixtures/ultron-prompts/compile-cases.json';

    private const TOOLS = __DIR__.'/../../../../fixtures/ultron-prompts/tools';

    /** El selector generado (B.7), relativo a la raíz del repositorio. */
    private const SELECTOR = 'n8n/flujos/ultron-studio-s1/elegir-prompts.js';

    /** @var array<string,mixed>|null */
    private static ?array $fixture = null;

    /** @return array<string,mixed> */
    private static function fixture(): array
    {
        return self::$fixture ??= json_decode((string) file_get_contents(self::FIXTURE), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function casos(): iterable
    {
        foreach (self::fixture()['cases'] as $caso) {
            yield $caso['id'].' · '.$caso['name'] => [$caso];
        }
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function invalidos(): iterable
    {
        foreach (self::fixture()['invalid'] as $caso) {
            yield $caso['name'] => [$caso['governed']];
        }
    }

    public function test_el_fixture_y_el_codigo_hablan_del_mismo_contrato(): void
    {
        $f = self::fixture();

        $this->assertSame(Base::CONTRACT, $f['contract']);
        $this->assertSame(Base::ID, $f['base']);
        $this->assertSame(Compiler::VERSION, $f['compiler']);
        $this->assertSame(Base::ROLES, $f['roles']);
        $this->assertSame(self::porClave(Compiler::BASELINE), self::porClave($f['baseline']));
        $this->assertSame(Base::LEGACY_BUNDLE_HASH, $f['legacyBundleHash'], 'La huella Legacy derivada fuera de PHP es la constante del código.');
        $base = Base::roles();
        foreach (Base::ROLES as $rol) {
            $this->assertSame([hash('sha256', $base[$rol]), strlen($base[$rol])], [$f['baseRoles'][$rol]['sha256'], $f['baseRoles'][$rol]['bytes']], "La base de {$rol} no es la del fixture.");
        }
        $this->assertSame([Compiler::MAX_PATCH_BYTES, Compiler::MAX_ROLE_BYTES, Compiler::MAX_BUNDLE_BYTES], array_values($f['limits']));

        // La prueba ve lo que tiene que ver: todo el espacio de valores válidos, una vez.
        $this->assertCount(3 * 2 * 17 * 3, $f['cases']);
        $this->assertCount(count($f['cases']), array_unique(array_map(static fn (array $c): string => json_encode($c['governed']), $f['cases'])));
        $this->assertCount(1, array_filter($f['cases'], static fn (array $c): bool => $c['changed'] === []));
        $this->assertGreaterThanOrEqual(20, count($f['invalid']));
    }

    #[DataProvider('casos')]
    public function test_el_compilador_da_los_bytes_que_salen_del_contrato(array $caso): void
    {
        $f = self::fixture();
        $c = Compiler::compile($caso['governed']);

        foreach (Base::ROLES as $rol) {
            // Los roles que no cambian tienen que ser la base, byte a byte.
            $esperado = $caso['expect']['roles'][$rol] ?? [$f['baseRoles'][$rol]['sha256'], $f['baseRoles'][$rol]['bytes']];
            $this->assertSame($esperado, [hash('sha256', $c['roles'][$rol]), strlen($c['roles'][$rol])], "{$caso['name']}: el rol {$rol} no es el que sale del contrato.");
        }
        $this->assertSame($caso['expect']['bundleHash'], $c['bundleHash'], "{$caso['name']}: la huella del paquete.");
        $this->assertSame($caso['expect']['bytes'], $c['bytes']);
        $this->assertSame([Base::CONTRACT, Base::ID, Compiler::VERSION], [$c['contract'], $c['base'], $c['compiler']]);
        $this->assertSame(self::porClave($caso['governed']), self::porClave($c['governed']));

        $this->assertSame($caso['changedRoles'], Compiler::changedRoles($caso['governed']), 'changedRoles()');
        $this->assertSame($caso['changed'] === [], Compiler::isBaseline($caso['governed']), 'isBaseline()');
    }

    #[DataProvider('casos')]
    public function test_la_vista_previa_enumera_los_parches_del_contrato_rol_a_rol(array $caso): void
    {
        $textos = self::fixture()['texts'];
        $esperado = [];
        foreach ($caso['expect']['patches'] as [$rol, $operacion, $ancla, $clave]) {
            $t = $textos[$clave];
            $esperado[$rol][] = ['parameter' => $t['parameter'], 'value' => $t['value'], 'operation' => $operacion, 'anchor' => $ancla, 'text' => $t['text']];
        }

        $parches = Compiler::patches($caso['governed']);
        $porRol = [];
        foreach ($parches as $p) {
            $porRol[$p['role']][] = ['parameter' => $p['parameter'], 'value' => $p['value'], 'operation' => $p['operation'], 'anchor' => $p['anchor'], 'text' => $p['text']];
        }
        ksort($esperado);
        ksort($porRol);

        // El orden ENTRE roles no es contrato; dentro de un rol, sí (es el de aplicación).
        $this->assertSame($esperado, $porRol, "{$caso['name']}: los parches de la vista previa no son los del contrato.");
        $this->assertSame($parches, Compiler::compile($caso['governed'])['patches'], 'La vista previa y la compilación enumeran los mismos parches.');
    }

    /** Determinismo (E2): mismas entradas en otro orden = mismo resultado, en los 306 casos. */
    public function test_el_orden_de_las_claves_no_cambia_el_resultado(): void
    {
        mt_srand(20261111);
        foreach (self::fixture()['cases'] as $caso) {
            $claves = array_keys($caso['governed']);
            shuffle($claves);
            $barajado = [];
            foreach ($claves as $k) {
                $barajado[$k] = $caso['governed'][$k];
            }
            $this->assertSame($caso['expect']['bundleHash'], Compiler::compile(array_reverse($caso['governed'], true))['bundleHash'], $caso['name']);
            $this->assertSame($caso['expect']['bundleHash'], Compiler::compile($barajado)['bundleHash'], $caso['name']);
        }
    }

    #[DataProvider('invalidos')]
    public function test_lo_que_el_contrato_no_admite_no_compila(array $governed): void
    {
        foreach (['compile', 'patches', 'validate'] as $metodo) {
            try {
                call_user_func([Compiler::class, $metodo], $governed);
                $this->fail("{$metodo}() aceptó un valor que el contrato no admite.");
            } catch (UltronPromptException $e) {
                $this->assertSame(UltronPromptException::INVALID_GOVERNED, $e->reason, $metodo);
            }
        }
    }

    /** `maxChars` como número decimal (JSON no lo distingue de un entero; aquí sí): no compila. */
    public function test_maxchars_decimal_no_compila(): void
    {
        $this->expectException(UltronPromptException::class);
        Compiler::compile(['persona.maxChars' => 200.0] + Compiler::BASELINE);
    }

    /** El fixture es el que sale HOY del contrato (§B.3 del plan) y de la base: nadie lo editó a mano ni se quedó viejo. */
    public function test_el_fixture_es_el_que_sale_hoy_del_contrato(): void
    {
        [$codigo, $salida] = $this->node(self::TOOLS.'/derivar-compile-cases.cjs', '--comprobar');
        $this->assertSame(0, $codigo, $salida);
    }

    /** E3: la SHA-256 en JS del selector = la del contrato, en los 306 paquetes; y Legacy sin lanzar ante todo lo demás. */
    public function test_el_selector_generado_acepta_lo_que_compila_el_contrato_y_cae_a_legacy_sin_lanzar(): void
    {
        $selector = dirname(__DIR__, 6).'/'.self::SELECTOR;
        $this->assertFileExists($selector);
        [$codigo, $salida] = $this->node(self::TOOLS.'/verificar-selector.cjs', $selector);
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('306 casos del contrato', $salida);
    }

    /**
     * @param  array<string,mixed>  $mapa
     * @return array<string,mixed>
     */
    private static function porClave(array $mapa): array
    {
        ksort($mapa);

        return $mapa;
    }

    /**
     * Node, sin shell (como `UltronPromptsN8nCommandTest`: E10 también lo exige en el PATH).
     *
     * @return array{int, string}
     */
    private function node(string ...$argumentos): array
    {
        $p = proc_open(['node', ...$argumentos], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
        $this->assertIsResource($p, 'No se pudo lanzar Node (hace falta en el PATH).');
        $salida = stream_get_contents($tubos[1]).stream_get_contents($tubos[2]);
        fclose($tubos[1]);
        fclose($tubos[2]);

        return [proc_close($p), (string) $salida];
    }
}
