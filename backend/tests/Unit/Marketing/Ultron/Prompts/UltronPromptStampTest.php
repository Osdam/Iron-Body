<?php

namespace Tests\Unit\Marketing\Ultron\Prompts;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase as Base;
use App\Services\Marketing\Ultron\Prompts\UltronPromptStamp as Stamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * E4 · EL SELLO DE LOS PROMPTS (plan de motores §B.6; Enmienda M-1 del H5, M1-5).
 *
 * La tabla de estados de §B.6, entera, con lo esperado escrito a mano; la
 * forma exacta del sello (claves, orden y tipos); y que sin `sp` ni bloque no
 * hay sello (la fila queda como hoy).
 */
class UltronPromptStampTest extends TestCase
{
    private const H_STUDIO = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const H_OTRA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const VERSION = 'a0000000-0000-4000-8000-00000000000a';

    private const ENTRADA = 'e0000000-0000-4000-8000-00000000000e';

    /** @return array<string,mixed> el `sp` de un token con oferta studio */
    private static function spStudio(string $huella = self::H_STUDIO): array
    {
        return ['m' => 'studio', 'r' => null, 'h' => $huella, 'v' => self::VERSION, 'c' => str_repeat('c', 64), 'e' => self::ENTRADA, 's' => 7];
    }

    /** @return array<string,mixed> el `sp` de un token con oferta legacy */
    private static function spLegacy(string $motivo = 'no_publication'): array
    {
        return ['m' => 'legacy', 'r' => $motivo, 'h' => null, 'v' => null, 'c' => null, 'e' => null, 's' => null];
    }

    /** @return array<string,mixed> el bloque `prompts` del commit */
    private static function bloque(string $modo, ?string $huella, ?string $motivo = null): array
    {
        return ['mode' => $modo, 'bundle_hash' => $huella, 'reason' => $motivo, 'flow_rev' => 'S1', 'execution_id' => '12345'];
    }

    /** @return array<string, array{mixed, mixed, string}> */
    public static function tabla(): array
    {
        $L = Base::LEGACY_BUNDLE_HASH;

        return [
            // (sin bloque) · cualquiera → not_reported
            'sin bloque · ofrecido studio' => [self::spStudio(), null, Stamp::NOT_REPORTED],
            'sin bloque · ofrecido legacy' => [self::spLegacy(), null, Stamp::NOT_REPORTED],
            'bloque vacío · ofrecido studio' => [self::spStudio(), [], Stamp::NOT_REPORTED],

            // studio · studio · = ofrecida → applied
            'studio · studio · misma huella' => [self::spStudio(), self::bloque('studio', self::H_STUDIO), Stamp::APPLIED],
            'studio · studio · la huella Legacy de una versión con la línea base' => [self::spStudio($L), self::bloque('studio', $L), Stamp::APPLIED],

            // studio · (≠ huella, u ofrecido ≠ studio) → mismatch
            'studio · studio · otra huella' => [self::spStudio(), self::bloque('studio', self::H_OTRA), Stamp::MISMATCH],
            'studio · legacy' => [self::spLegacy(), self::bloque('studio', self::H_STUDIO), Stamp::MISMATCH],
            'studio · nada ofrecido' => [null, self::bloque('studio', self::H_STUDIO), Stamp::MISMATCH],

            // legacy · studio · = Legacy → engine_fallback
            'legacy · studio · huella Legacy' => [self::spStudio(), self::bloque('legacy', $L, 'hash_mismatch'), Stamp::ENGINE_FALLBACK],

            // legacy · legacy · = Legacy → legacy
            'legacy · legacy · huella Legacy' => [self::spLegacy(), self::bloque('legacy', $L, 'offered_no_publication'), Stamp::LEGACY],
            'legacy · nada ofrecido · huella Legacy' => [null, self::bloque('legacy', $L, 'absent'), Stamp::LEGACY],

            // legacy · cualquiera · ≠ Legacy → legacy_drift
            'legacy · studio · otra huella' => [self::spStudio(), self::bloque('legacy', self::H_OTRA), Stamp::LEGACY_DRIFT],
            'legacy · legacy · otra huella' => [self::spLegacy(), self::bloque('legacy', self::H_OTRA), Stamp::LEGACY_DRIFT],
            'legacy · nada · otra huella' => [null, self::bloque('legacy', self::H_OTRA), Stamp::LEGACY_DRIFT],
            'legacy · studio · la huella del paquete ofrecido' => [self::spStudio(), self::bloque('legacy', self::H_STUDIO), Stamp::LEGACY_DRIFT],

            // cualquiera · cualquiera · null → unverified
            'studio · studio · sin huella' => [self::spStudio(), self::bloque('studio', null), Stamp::UNVERIFIED],
            'legacy · legacy · sin huella (selector_error)' => [self::spLegacy(), self::bloque('legacy', null, 'selector_error'), Stamp::UNVERIFIED],
            'legacy · nada · sin huella' => [null, self::bloque('legacy', null), Stamp::UNVERIFIED],
        ];
    }

    #[DataProvider('tabla')]
    public function test_la_tabla_de_estados_de_b6(mixed $sp, mixed $bloque, string $estado): void
    {
        $sello = Stamp::build($sp, $bloque);

        $this->assertIsArray($sello);
        $this->assertSame($estado, $sello['state']);
        $this->assertSame($estado, Stamp::state(Stamp::offered($sp), Stamp::observed($bloque)));
    }

    public function test_sin_sp_ni_bloque_no_hay_sello(): void
    {
        $this->assertNull(Stamp::build(null, null));
        $this->assertNull(Stamp::build([], []));
        $this->assertNull(Stamp::build('basura', 7));
    }

    public function test_la_forma_exacta_del_sello(): void
    {
        $sello = Stamp::build(self::spStudio(), self::bloque('studio', self::H_STUDIO));

        $this->assertSame([
            'v' => 1,
            'state' => 'applied',
            'offered' => [
                'mode' => 'studio', 'reason' => null, 'version' => self::VERSION, 'contentHash' => str_repeat('c', 64),
                'entry' => self::ENTRADA, 'seq' => 7, 'bundleHash' => self::H_STUDIO,
            ],
            'observed' => [
                'mode' => 'studio', 'bundleHash' => self::H_STUDIO, 'reason' => null, 'flowRev' => 'S1', 'execution' => '12345',
            ],
        ], $sello);
    }

    public function test_solo_ofrecido_o_solo_observado(): void
    {
        $this->assertSame(['v' => 1, 'state' => 'not_reported', 'offered' => Stamp::offered(self::spLegacy()), 'observed' => null], Stamp::build(self::spLegacy(), null));
        $this->assertSame(['v' => 1, 'state' => 'legacy', 'offered' => null, 'observed' => Stamp::observed(self::bloque('legacy', Base::LEGACY_BUNDLE_HASH))], Stamp::build(null, self::bloque('legacy', Base::LEGACY_BUNDLE_HASH)));
    }

    public function test_los_tipos_raros_no_se_cuelan(): void
    {
        $offered = Stamp::offered(['m' => 'studio', 'r' => ['x'], 'h' => 7, 'v' => true, 'c' => null, 'e' => 1.5, 's' => '7']);
        $this->assertSame(['mode' => 'studio', 'reason' => null, 'version' => null, 'contentHash' => null, 'entry' => null, 'seq' => null, 'bundleHash' => null], $offered);

        $observed = Stamp::observed(['mode' => 'otro', 'bundle_hash' => self::H_STUDIO]);
        $this->assertSame(['mode' => 'otro', 'bundleHash' => self::H_STUDIO, 'reason' => null, 'flowRev' => null, 'execution' => null], $observed);
        $this->assertSame(Stamp::UNVERIFIED, Stamp::state(null, $observed), 'un modo que no es studio ni legacy no se puede verificar');
    }

    public function test_el_sello_de_error(): void
    {
        $this->assertSame(['v' => 1, 'state' => 'stamp_error', 'offered' => null, 'observed' => null], Stamp::failed());
    }

    public function test_los_estados_son_los_siete_del_contrato_mas_el_de_error(): void
    {
        $this->assertSame(
            ['applied', 'mismatch', 'engine_fallback', 'legacy', 'legacy_drift', 'not_reported', 'unverified', 'stamp_error'],
            [Stamp::APPLIED, Stamp::MISMATCH, Stamp::ENGINE_FALLBACK, Stamp::LEGACY, Stamp::LEGACY_DRIFT, Stamp::NOT_REPORTED, Stamp::UNVERIFIED, Stamp::STAMP_ERROR],
        );
    }
}
