<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\CanonicalCondition;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\ConditionCanonicalizer;
use App\Services\Outreach\Core\ConditionEvaluator;
use App\Services\Outreach\Core\DecisionTrace;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\UltronStudio\StudioContentHasher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PARIDAD de la gramática de condiciones (D9-5; T10, T11, T12) con
 * `conditions-cases.json`, caso a caso: la forma canónica, su JSON y su huella;
 * los 422 como CONJUNTO exacto de pares {key, code}; la equivalencia de
 * huellas; el valor de tres estados, la traza PRE-ORDEN y la `traceHash`; y
 * los días de Bogotá.
 */
class ConditionsParityTest extends TestCase
{
    use LoadsOutreachFixtures;

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function canonicalAndReject(): iterable
    {
        return self::cases('conditions-cases', ['canonicalize', 'reject']);
    }

    #[DataProvider('canonicalAndReject')]
    public function test_canonicaliza_o_rechaza_como_el_fixture(array $caso): void
    {
        $r = ConditionCanonicalizer::check(self::input($caso), self::fixture('conditions-cases')['dynamicDomains']);
        $e = $caso['expected'];

        $this->assertSame($e['errors'], $r['errors'], 'Los errores son el conjunto exacto {key, code}, ordenado.');
        if ($e['errors'] === []) {
            $this->assertCanonicalSame($e['canonical'], $r['canonical']);
            $this->assertSame($e['canonicalJson'], $r['json']);
            $this->assertSame($e['hash'], $r['hash']);
            $this->assertSame($e['canonicalBytes'], strlen($r['json']));
            $this->assertSame($e['hash'], StudioContentHasher::hash($r['canonical']), 'la huella es la de StudioContentHasher');
        } else {
            $this->assertNull($r['canonical']);
            $this->assertSame($e['errorKeys'], array_keys(ConditionCanonicalizer::messages($r['errors'])), 'las claves del 422 de Laravel');
            $this->assertSame(422, $e['http']);
        }
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function equivalence(): iterable
    {
        return self::cases('conditions-cases', ['equivalence']);
    }

    #[DataProvider('equivalence')]
    public function test_equivalencia_de_huellas(array $caso): void
    {
        $dominios = self::fixture('conditions-cases')['dynamicDomains'];
        $a = ConditionCanonicalizer::check(self::input($caso, 'a', 'aText'), $dominios);
        $b = ConditionCanonicalizer::check(self::input($caso, 'b', 'bText'), $dominios);

        $this->assertSame($caso['expected']['hashA'], $a['hash']);
        $this->assertSame($caso['expected']['hashB'], $b['hash']);
        $this->assertSame($caso['expected']['sameHash'], $a['hash'] === $b['hash']);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function evaluate(): iterable
    {
        return self::cases('conditions-cases', ['evaluate']);
    }

    #[DataProvider('evaluate')]
    public function test_evalua_con_tres_valores_y_traza(array $caso): void
    {
        $r = CanonicalCondition::tryParse(self::input($caso), self::fixture('conditions-cases')['dynamicDomains']);
        $this->assertNotNull($r['condition'], json_encode($r['errors']));
        $foto = FactSnapshot::fromArray($caso['snapshot']);
        $ahora = RelativeTime::parseInstant($caso['now']);

        $resultado = ConditionEvaluator::evaluate($r['condition'], $foto, $ahora);
        $e = $caso['expected'];
        $this->assertCanonicalSame($e['canonical'], $r['condition']->tree);
        $this->assertSame($e['value'], $resultado->label());
        $this->assertCanonicalSame($e['trace'], $resultado->trace);
        $this->assertSame($e['traceHash'], $resultado->traceHash());
        $hojas = array_values(array_map(static fn (array $t): string => $t['result'], array_filter($resultado->trace, static fn (array $t): bool => ! in_array($t['kind'], ['all', 'any', 'not'], true))));
        $this->assertSame($e['leafResults'], $hojas);

        // T12: la forma canónica es un punto fijo y, releída, da la misma traza; la traza solo lleva valores derivados (T5).
        $releida = CanonicalCondition::tryParse($r['condition']->tree, self::fixture('conditions-cases')['dynamicDomains']);
        $this->assertSame($r['condition']->json, $releida['condition']->json);
        $this->assertSame(CanonicalJson::encode($r['condition']->tree), $r['condition']->json);
        $otra = ConditionEvaluator::evaluate($releida['condition'], FactSnapshot::fromArray($caso['snapshot']), $ahora);
        $this->assertSame($resultado->traceHash(), $otra->traceHash());
        DecisionTrace::assertDerived($resultado->trace);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function bogotaDays(): iterable
    {
        return self::cases('conditions-cases', ['bogotaDays']);
    }

    #[DataProvider('bogotaDays')]
    public function test_dias_de_bogota(array $caso): void
    {
        $ahora = RelativeTime::parseInstant($caso['now']);
        $en = RelativeTime::parseInstant($caso['at']);

        $this->assertSame($caso['expected'], [
            'daysAgo' => RelativeTime::daysAgo($en, $ahora),
            'bogotaDateNow' => RelativeTime::bogotaDate($ahora),
            'bogotaDateAt' => RelativeTime::bogotaDate($en),
        ]);
    }

    public function test_el_fixture_es_de_este_catalogo_y_trae_lo_que_dice(): void
    {
        $f = self::fixture('conditions-cases');
        $this->assertSame([OutreachCatalog::VERSION, OutreachCatalog::hash()], self::ratifiedPin($f['catalogVersion'], $f['catalogHash']), 'L9-29: el fixture o es del catálogo vigente o es el de antes con solo ese cambio');
        $this->assertSame(OutreachCatalog::GRAMMAR, $f['grammar']);
        $this->assertSame(ConditionCanonicalizer::CODES, $f['errorCodes']);
        foreach (['canonicalize', 'equivalence', 'reject', 'evaluate', 'bogotaDays'] as $seccion) {
            $this->assertCount($f['counts'][$seccion], $f[$seccion], $seccion);
        }
        // Todos los códigos del fixture tienen mensaje; ningún mensaje repite lo que mandó el usuario.
        foreach ($f['errorCodes'] as $codigo) {
            $this->assertArrayHasKey($codigo, ConditionCanonicalizer::MESSAGES);
        }
    }
}
