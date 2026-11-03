<?php

namespace Tests\Unit\Twilio;

use App\Services\Twilio\TwilioRequestSignature as S;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El validador de la subcuenta de voz contra el fixture CONFIRMADO de TEST8
 * (`tests/fixtures/voice/twilio-signature-vectors.json`: 15 de cálculo, 25 de
 * validación y 5 de construcción de la URL = 45). Puro: sin aplicación ni red.
 *
 * Las decisiones del fixture (DF-T1 a DF-T5) son las del validador: la URL es
 * la base + la URI cruda; tolerancia de puerto como `validate()` del SDK;
 * orden de bytes; `bodySHA256` exacto en minúsculas; token vacío = excepción.
 */
class TwilioRequestSignatureFixtureTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/voice/twilio-signature-vectors.json'), true, 64, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function compute(): iterable
    {
        foreach (self::fixture()['compute'] as $c) {
            yield $c['name'] => [$c];
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function validate(): iterable
    {
        foreach (self::fixture()['validate'] as $c) {
            yield $c['name'] => [$c];
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function urlBuild(): iterable
    {
        foreach (self::fixture()['urlBuild'] as $c) {
            yield $c['name'] => [$c];
        }
    }

    public function test_el_fixture_tiene_los_45_vectores(): void
    {
        $f = self::fixture();
        $this->assertSame(['compute' => 15, 'validate' => 25, 'urlBuild' => 5, 'total' => 45], $f['counts']);
        $this->assertCount(45, [...$f['compute'], ...$f['validate'], ...$f['urlBuild']]);
    }

    /** @param  array<string, mixed>  $c */
    #[DataProvider('compute')]
    public function test_calculo(array $c): void
    {
        $pares = $c['kind'] === 'json' ? [] : $c['params'];
        if (isset($c['expected']['base'])) {
            $this->assertSame($c['expected']['base'], S::signingBase($c['url'], $pares), 'el texto que se firma');
        }
        $this->assertSame($c['expected']['signature'], S::compute($c['url'], $pares, $c['token']));
        if ($c['kind'] === 'json') {
            $this->assertSame('ok', S::verifyJson($c['url'], $c['rawBody'], $c['expected']['signature'], $c['token']));
        }
    }

    /** @param  array<string, mixed>  $c */
    #[DataProvider('validate')]
    public function test_validacion(array $c): void
    {
        $valida = static fn (): string => $c['kind'] === 'json'
            ? S::verifyJson($c['url'], $c['rawBody'], $c['header'], $c['token'])
            : S::verifyPairs($c['url'], $c['params'], $c['header'], $c['token']);

        if (isset($c['expected']['throws'])) {
            $this->assertSame('InvalidArgumentException', $c['expected']['throws']);
            $this->expectException(InvalidArgumentException::class);
            $valida();

            return;
        }
        $resultado = $valida();
        $this->assertSame($c['expected']['valid'], $resultado === S::OK, $resultado);
        $this->assertContains($resultado, S::RESULTS);
    }

    /** @param  array<string, mixed>  $c */
    #[DataProvider('urlBuild')]
    public function test_construccion_de_la_url(array $c): void
    {
        $this->assertSame($c['expected']['url'], S::canonicalUrl($c['baseUrl'], $c['requestUri']));
    }
}
