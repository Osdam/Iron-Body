<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase as Base;
use App\Services\Marketing\Ultron\Prompts\UltronPromptException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * E1 · LA BASE DE LOS PROMPTS (plan de motores §B.3; Enmienda M-1 del H5).
 *
 * Lo que se fija:
 *  1. Cada fichero de `resources/ultron-prompts/n8n-29de5119/` es, byte a byte,
 *     `<nodo>.system` de `docs/marketing/prompts/2026-09-22-voz/nuevo4.json`
 *     (la copia del flujo vivo 29de5119), sin salto de línea final añadido.
 *  2. Sus sha256 y tamaños son los fijados en la clase.
 *  3. `LEGACY_BUNDLE_HASH` es la cuenta de §B.3 sobre la base, hecha aquí a
 *     mano y además con un valor calculado FUERA de PHP (Python, hashlib, el
 *     11-11-2026). Lo mismo con un vector sintético que fija la fórmula.
 *  4. `bundleHash()` exige los cinco roles y no depende del orden de claves.
 */
class UltronPromptBaseTest extends TestCase
{
    private const NUEVO4 = __DIR__.'/../../../../../docs/marketing/prompts/2026-09-22-voz/nuevo4.json';

    /** Calculado con Python 3 (hashlib) sobre nuevo4.json → .system, el 11-11-2026. */
    private const LEGACY_PY = '3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902';

    /**
     * Vector sintético, calculado con Python 3:
     *   roles = {strategist: "Sñ — uno", composer: "C", critic: "K", composer_retry: "CR", critic_retry: "KR\nlinea"}
     *   sha256("\n".join(["ultron-prompts/1"] + [r, roles[r] for r in orden]).encode("utf-8"))
     */
    private const SINTETICO_PY = 'ffc7693fca1a0ed3e2d8ca25805565516e0100ef0d7e1bd26a265c236d3dca2a';

    /** @return array<string,mixed> */
    private static function nuevo4(): array
    {
        $json = file_get_contents(self::NUEVO4);
        self::assertIsString($json, 'nuevo4.json existe en el repositorio');

        return json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        $out = [];
        foreach (Base::ROLES as $rol) {
            $out[$rol] = [$rol];
        }

        return $out;
    }

    #[DataProvider('roles')]
    public function test_cada_fichero_es_el_system_de_nuevo4_byte_a_byte(string $rol): void
    {
        $nodo = Base::NODES[$rol];
        $esperado = self::nuevo4()[$nodo]['system'] ?? null;
        $this->assertIsString($esperado, "nuevo4.json trae {$nodo}.system");

        $bytes = file_get_contents(Base::directory().'/'.$rol.'.txt');
        $this->assertSame($esperado, $bytes, "{$rol}.txt = nuevo4.json → {$nodo}.system");
        $this->assertSame(Base::SHA256[$rol], hash('sha256', $bytes));
        $this->assertSame(Base::BYTES[$rol], strlen($bytes));
        $this->assertStringEndsNotWith("\n", $bytes, 'sin salto de línea final añadido');
        $this->assertStringNotContainsString("\r", $bytes);
        $this->assertTrue(mb_check_encoding($bytes, 'UTF-8'));
        // Un literal, no una expresión de n8n: no empieza por «=» y no lleva nada que JS interpolaría.
        $this->assertStringStartsNotWith('=', $bytes);
        $this->assertStringNotContainsString('`', $bytes);
        $this->assertStringNotContainsString('${', $bytes);
    }

    public function test_los_nodos_son_los_cinco_de_nuevo4_y_el_orden_es_el_canonico(): void
    {
        $this->assertSame(['strategist', 'composer', 'critic', 'composer_retry', 'critic_retry'], Base::ROLES);
        $this->assertSame(Base::ROLES, array_keys(Base::NODES));
        $this->assertSame(Base::ROLES, array_keys(Base::SHA256));
        $this->assertSame(Base::ROLES, array_keys(Base::BYTES));
        $this->assertEqualsCanonicalizing(array_values(Base::NODES), array_keys(self::nuevo4()));
        $this->assertSame(Base::ROLES, array_keys(Base::roles()));
        $this->assertSame(112216, array_sum(Base::BYTES));
        $this->assertSame('n8n-29de5119', Base::ID);
        $this->assertSame('ultron-prompts/1', Base::CONTRACT);
    }

    public function test_la_huella_legacy_es_la_cuenta_de_b3_sobre_la_base(): void
    {
        $roles = Base::roles();
        $material = 'ultron-prompts/1'
            ."\nstrategist\n".$roles['strategist']
            ."\ncomposer\n".$roles['composer']
            ."\ncritic\n".$roles['critic']
            ."\ncomposer_retry\n".$roles['composer_retry']
            ."\ncritic_retry\n".$roles['critic_retry'];

        $this->assertSame(self::LEGACY_PY, hash('sha256', $material), 'la cuenta a mano = la de Python');
        $this->assertSame(self::LEGACY_PY, Base::LEGACY_BUNDLE_HASH);
        $this->assertSame(self::LEGACY_PY, Base::bundleHash($roles));
    }

    public function test_un_vector_sintetico_fija_la_formula(): void
    {
        $roles = ['strategist' => 'Sñ — uno', 'composer' => 'C', 'critic' => 'K', 'composer_retry' => 'CR', 'critic_retry' => "KR\nlinea"];

        $this->assertSame(self::SINTETICO_PY, Base::bundleHash($roles));
        // El orden de las claves de entrada no cuenta.
        $this->assertSame(self::SINTETICO_PY, Base::bundleHash(array_reverse($roles, true)));
        // Un byte distinto, otra huella.
        $this->assertNotSame(self::SINTETICO_PY, Base::bundleHash(array_replace($roles, ['critic' => 'K '])));
    }

    /** @return array<string, array{array<string,mixed>}> */
    public static function paquetesMalos(): array
    {
        $bien = ['strategist' => 'S', 'composer' => 'C', 'critic' => 'K', 'composer_retry' => 'CR', 'critic_retry' => 'KR'];
        $sinUno = $bien;
        unset($sinUno['critic_retry']);

        return [
            'falta un rol' => [$sinUno],
            'sobra un rol' => [$bien + ['judge' => 'J']],
            'un rol que no es texto' => [array_replace($bien, ['composer' => 7])],
            'un rol null' => [array_replace($bien, ['composer' => null])],
            'vacío' => [[]],
        ];
    }

    /** @param array<string,mixed> $roles */
    #[DataProvider('paquetesMalos')]
    public function test_la_huella_exige_los_cinco_roles_como_texto(array $roles): void
    {
        try {
            Base::bundleHash($roles);
            $this->fail('tenía que lanzar');
        } catch (UltronPromptException $e) {
            $this->assertSame(UltronPromptException::ROLES, $e->reason);
        }
    }

    public function test_la_base_se_carga_desde_una_ruta_relativa_a_la_clase(): void
    {
        $this->assertDirectoryExists(Base::directory());
        $this->assertStringEndsWith('/resources/ultron-prompts/n8n-29de5119', Base::directory());
        foreach (Base::ROLES as $rol) {
            $this->assertFileExists(Base::directory().'/'.$rol.'.txt');
        }
        // Solo los cinco ficheros de la base (un sexto no tendría quien lo verifique).
        $ficheros = array_values(array_diff(scandir(Base::directory()) ?: [], ['.', '..']));
        sort($ficheros);
        $esperados = array_map(static fn (string $r): string => $r.'.txt', Base::ROLES);
        sort($esperados);
        $this->assertSame($esperados, $ficheros);
    }
}
