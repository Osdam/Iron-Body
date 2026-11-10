<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\CanonicalCondition;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\ConditionCanonicalizer;
use App\Services\Outreach\Core\ConditionEvaluator;
use App\Services\Outreach\Core\EnrollmentGate;
use App\Services\Outreach\Core\FactSnapshot;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\UltronStudio\StudioContentHasher;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Las piezas del núcleo que los fixtures no cubren enteras: el JSON canónico
 * (los bytes de `StudioContentHasher`, que `Outreach` no puede nombrar), el
 * tiempo relativo de Bogotá y sus prefiltros, la foto de hechos (solo valores
 * derivados), que nada se evalúe sin validar (T11) y la puerta de
 * inscripción (D9-8 etapa 2).
 */
class CorePrimitivesTest extends TestCase
{
    public function test_el_json_canonico_son_los_bytes_del_studio(): void
    {
        $valores = [
            ['b' => 1, 'a' => ['z' => true, 'y' => null, 'x' => [3, 2, 1]], 'ñ' => 'tilde/á', 'c' => []],
            [['k' => 'v'], ['j' => -0, 'i' => PHP_INT_MAX]],
            ['10' => 'x', '9' => 'y', 'a' => 'z'],
            'texto con "comillas" y \\barra\\',
            ['a' => ['b' => ['c' => ['d' => ['e' => 'f']]]]],
        ];
        mt_srand(20261110);
        for ($i = 0; $i < 200; $i++) {
            $valores[] = self::random(3);
        }
        foreach ($valores as $v) {
            $this->assertSame(StudioContentHasher::canonicalJson($v), CanonicalJson::encode($v));
            $this->assertSame(StudioContentHasher::hash($v), CanonicalJson::hash($v));
        }
        foreach ([0.5, ['a' => 1.0], new \stdClass, "\xB1\x31", self::nest(40)] as $malo) {
            try {
                CanonicalJson::encode($malo);
                $this->fail('Se codificó algo que no es canónico.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_la_normalizacion_de_los_numeros(): void
    {
        $this->assertSame([CanonicalJson::NUMBER_OK, 3], CanonicalJson::normalizeNumber(3.0));
        $this->assertSame([CanonicalJson::NUMBER_OK, 0], CanonicalJson::normalizeNumber(-0.0));
        $this->assertSame([CanonicalJson::NUMBER_OK, 10], CanonicalJson::normalizeNumber(1e1));
        $this->assertSame([CanonicalJson::NUMBER_NOT_INTEGER, null], CanonicalJson::normalizeNumber(7.5));
        $this->assertSame([CanonicalJson::NUMBER_NOT_INTEGER, null], CanonicalJson::normalizeNumber(1e20), 'más allá de 2^53 no es un entero exacto');
        $this->assertSame([CanonicalJson::NUMBER_NOT_FINITE, null], CanonicalJson::normalizeNumber(INF));
        $this->assertSame([CanonicalJson::NUMBER_NOT_FINITE, null], CanonicalJson::normalizeNumber(NAN));
        $this->assertSame([CanonicalJson::NUMBER_NOT_NUMBER, null], CanonicalJson::normalizeNumber('7'));
        $this->assertSame([CanonicalJson::NUMBER_NOT_NUMBER, null], CanonicalJson::normalizeNumber(true));
    }

    public function test_el_tiempo_relativo_de_bogota(): void
    {
        $ahora = RelativeTime::parseInstant('2026-11-10T03:30:00Z'); // lunes 22:30 de Bogotá
        $this->assertSame('2026-11-09', RelativeTime::bogotaDate($ahora));
        $this->assertSame(1, RelativeTime::daysUntilDate('2026-11-10', $ahora));
        $this->assertSame(-9, RelativeTime::daysUntilDate('2026-10-31', $ahora));
        $this->assertSame(0, RelativeTime::daysUntilDate('2026-11-09', $ahora));
        $this->assertSame(27, RelativeTime::hoursSince(RelativeTime::parseInstant('2026-11-09T00:00:00Z'), $ahora));
        $this->assertSame(-1, RelativeTime::hoursSince(RelativeTime::parseInstant('2026-11-10T05:00:00Z'), $ahora));
        $this->assertSame('2026-11-10T03:30:00Z', RelativeTime::format(new DateTimeImmutable('2026-11-09 22:30:00.9', new DateTimeZone('America/Bogota'))), 'al segundo, sin fracciones');
        foreach (['2026-11-10T03:30:00', '2026-11-10 03:30:00Z', '2026-02-30T00:00:00Z', '2026-11-10T24:00:00Z', '2026-11-10T03:30:00.000Z', '2026-11-10T03:30:00-05:00'] as $malo) {
            $this->assertFalse(RelativeTime::isInstant($malo), $malo);
        }
        $this->assertFalse(RelativeTime::isInstant(1731209400));
        $this->expectException(InvalidArgumentException::class);
        RelativeTime::daysUntilDate('2026-02-29', $ahora);
    }

    /** El prefiltro (`columna >= inicio AND columna <= ahora`) da EXACTAMENTE la ventana del evaluador (DF-C3). */
    public function test_el_prefiltro_es_la_ventana_del_evaluador(): void
    {
        $ahora = RelativeTime::parseInstant('2026-11-10T05:30:00Z'); // martes 00:30 de Bogotá
        foreach ([['n' => 1, 'unit' => 'days'], ['n' => 2, 'unit' => 'weeks'], ['n' => 30, 'unit' => 'hours'], ['n' => 730, 'unit' => 'days']] as $w) {
            $inicio = RelativeTime::windowStart($ahora, $w['n'], $w['unit']);
            for ($m = -60 * 24 * 16; $m <= 60; $m += 17) {
                $t = $ahora->modify("{$m} minutes");
                $porPrefiltro = $t >= $inicio && $t <= $ahora;
                $this->assertSame($porPrefiltro, RelativeTime::withinLast($t, $ahora, $w['n'], $w['unit']), json_encode($w).' '.$t->format('c'));
                $this->assertSame($porPrefiltro, ConditionEvaluator::inWindow($t, $ahora, $w));
            }
        }
        $this->expectException(InvalidArgumentException::class);
        RelativeTime::withinLast($ahora, $ahora, 1, 'months');
    }

    public function test_la_foto_solo_lleva_valores_derivados(): void
    {
        $bien = FactSnapshot::fromArray(['facts' => ['b' => 1, 'a' => 'granted', 'c' => null, 'd' => true], 'contacts' => null, 'events' => [], 'transitions' => null]);
        $this->assertSame(['a', 'b', 'c', 'd'], array_keys($bien->facts));
        $this->assertNull($bien->contacts, 'null = fuente sin datos');
        $this->assertSame([], $bien->events, 'lista vacía = fuente al día sin filas');
        $this->assertSame(64, strlen($bien->hash()));
        $this->assertSame($bien->json(), FactSnapshot::fromArray(['facts' => ['d' => true, 'c' => null, 'a' => 'granted', 'b' => 1], 'contacts' => null, 'events' => [], 'transitions' => null])->json());

        $malas = [
            ['facts' => ['x' => '+57 300 000 0001']],
            ['facts' => ['x' => '3000000001']],
            ['facts' => ['x' => 'ana@example.test']],
            ['facts' => ['x' => 'Ana María']],
            ['facts' => ['x' => 2.5]],
            ['facts' => ['x' => ['a' => 1]]],
            ['facts' => [1, 2]],
            ['otra' => []],
            ['contacts' => [['at' => '2026-11-10T00:00:00Z', 'channel' => 'sms', 'purpose' => 'commercial', 'strategy' => null, 'outcome' => null]]],
            ['contacts' => [['at' => '2026-11-10T00:00:00Z', 'channel' => 'voice', 'purpose' => 'commercial', 'strategy' => null, 'outcome' => null, 'phone' => '3000000001']]],
            ['events' => [['key' => 'membership.expiring_window', 'at' => '2026-11-10T00:00:00Z', 'attrs' => []]]],
            ['events' => [['key' => 'payment_failed', 'at' => '2026-11-10T00:00:00Z', 'attrs' => ['amount' => 'Ciento veinte mil']]]],
            ['transitions' => [['entity' => 'membership', 'from' => 'active', 'to' => 'refunded', 'at' => '2026-11-10T00:00:00Z']]],
            ['contacts' => 'ninguno'],
        ];
        foreach ($malas as $i => $mala) {
            try {
                FactSnapshot::fromArray($mala);
                $this->fail("La foto {$i} no lanzó.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** T11: el evaluador solo acepta una condición validada, y solo el canonicalizador la construye. */
    public function test_nada_se_evalua_sin_validar(): void
    {
        $constructor = (new ReflectionClass(CanonicalCondition::class))->getConstructor();
        $this->assertTrue($constructor->isPrivate());
        $tipo = (new ReflectionClass(ConditionEvaluator::class))->getMethod('evaluate')->getParameters()[0]->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $tipo);
        $this->assertSame(CanonicalCondition::class, $tipo->getName());

        $r = CanonicalCondition::tryParse(['fact' => 'membership.active', 'op' => 'eq', 'value' => 'DROP TABLE users']);
        $this->assertNull($r['condition']);
        $this->assertSame([['key' => 'conditions.value', 'code' => 'invalid_type']], $r['errors']);
        // Los mensajes del 422 nunca repiten lo que mandó el usuario.
        $mensajes = ConditionCanonicalizer::messages(ConditionCanonicalizer::check(['all' => [['fact' => 'x; DROP TABLE users', 'op' => 'eq', 'value' => 1], ['sql' => 'SELECT 1']]])['errors']);
        $this->assertStringNotContainsString('DROP', json_encode($mensajes));
        $this->assertStringNotContainsString('SELECT', json_encode($mensajes));
    }

    public function test_un_arbol_absurdo_no_se_recorre_entero(): void
    {
        $hojas = array_fill(0, 5000, ['fact' => 'membership.active', 'op' => 'eq', 'value' => true]);
        $r = ConditionCanonicalizer::check(['any' => $hojas]);
        $this->assertSame([['key' => 'conditions', 'code' => 'too_many_nodes']], $r['errors']);
        $r = ConditionCanonicalizer::check(['any' => [['x' => 1], ...$hojas]]);
        $this->assertSame(['conditions', 'conditions.any.0'], array_values(array_unique(array_column($r['errors'], 'key'))));
        // Una clave rara no sale tal cual en la ruta del error.
        $r = ConditionCanonicalizer::check(['fact' => 'membership.active', 'op' => 'eq', 'value' => true, "clave con espacios\n" => 1]);
        $this->assertSame([['key' => 'conditions._invalid_key', 'code' => 'unexpected_key']], $r['errors']);
    }

    public function test_usos_y_conjuncion_raiz(): void
    {
        $c = CanonicalCondition::tryParse(['all' => [
            ['fact' => 'membership.days_to_expiry', 'op' => 'lte', 'value' => 7],
            ['not' => ['event' => 'human_requested', 'op' => 'exists', 'within' => ['n' => 30, 'unit' => 'days']]],
            ['count' => ['of' => 'event:payment_failed', 'within' => ['n' => 7, 'unit' => 'days']], 'op' => 'lte', 'value' => 1],
            ['any' => [['fact' => 'contact.count', 'args' => ['within' => ['n' => 7, 'unit' => 'days']], 'op' => 'eq', 'value' => 0], ['transition' => ['entity' => 'membership', 'from' => 'active', 'to' => 'expired', 'within' => ['n' => 7, 'unit' => 'days']]]]],
        ]])['condition'];
        $this->assertSame(['facts' => ['contact.count', 'membership.days_to_expiry'], 'events' => ['human_requested', 'payment_failed'], 'contacts' => true, 'transitions' => true], $c->uses());
        $this->assertSame([['fact' => 'membership.days_to_expiry', 'op' => 'lte', 'value' => 7], ['count' => ['of' => 'event:payment_failed', 'within' => ['n' => 7, 'unit' => 'days']], 'op' => 'lte', 'value' => 1]], $c->rootConjunction());
        $this->assertSame(OutreachCatalog::VERSION, $c->catalogVersion);
    }

    public function test_la_puerta_de_inscripcion(): void
    {
        $p = static fn (array $t): CanonicalCondition => CanonicalCondition::tryParse($t)['condition'];
        $audiencia = $p(['fact' => 'membership.active', 'op' => 'eq', 'value' => true]);
        $exclusion = $p(['fact' => 'membership.frozen', 'op' => 'eq', 'value' => true]);
        $exito = $p(['fact' => 'membership.days_to_expiry', 'op' => 'gt', 'value' => 30]);
        $ahora = RelativeTime::parseInstant('2026-11-10T15:00:00Z');
        $foto = static fn (array $h): FactSnapshot => FactSnapshot::fromArray(['facts' => $h]);

        $casos = [
            [['membership.active' => true, 'membership.frozen' => false, 'membership.days_to_expiry' => 5], true, EnrollmentGate::ENROLL],
            [['membership.active' => false, 'membership.frozen' => false, 'membership.days_to_expiry' => 5], true, EnrollmentGate::NOT_IN_AUDIENCE],
            [['membership.frozen' => false, 'membership.days_to_expiry' => 5], true, EnrollmentGate::INSUFFICIENT_FACTS],
            [['membership.active' => true, 'membership.frozen' => true, 'membership.days_to_expiry' => 5], true, EnrollmentGate::EXCLUDED],
            [['membership.active' => true, 'membership.days_to_expiry' => 5], true, EnrollmentGate::INSUFFICIENT_FACTS],
            [['membership.active' => true, 'membership.frozen' => false, 'membership.days_to_expiry' => 40], true, EnrollmentGate::SUCCESS_REACHED],
            [['membership.active' => true, 'membership.frozen' => false], true, EnrollmentGate::INSUFFICIENT_FACTS],
            [['membership.active' => true, 'membership.days_to_expiry' => 5], false, EnrollmentGate::ENROLL],
        ];
        foreach ($casos as $i => [$hechos, $conExclusion, $motivo]) {
            $g = EnrollmentGate::evaluate($audiencia, $conExclusion ? $exclusion : null, $exito, $foto($hechos), $ahora);
            $this->assertSame([$motivo === EnrollmentGate::ENROLL, $motivo], [$g['enroll'], $g['reason']], "caso {$i}");
        }
        $g = EnrollmentGate::evaluate($audiencia, null, $exito, $foto(['membership.active' => true, 'membership.days_to_expiry' => 5]), $ahora);
        $this->assertSame(['audience' => 'true', 'exclusions' => 'false', 'success' => 'false'], EnrollmentGate::context($g));
        $this->assertSame('audience', $g['audience']->trace[0]['path']);
    }

    private static function random(int $profundidad): mixed
    {
        $tipo = mt_rand(0, $profundidad <= 0 ? 4 : 6);

        return match ($tipo) {
            0 => null,
            1 => (bool) mt_rand(0, 1),
            2 => mt_rand(-1000000, 1000000),
            3 => implode('', array_map(static fn (): string => ['a', 'b', 'ñ', 'á', 'é', '/', '\\', '"', 'x', '0', '9', ' ', '€'][mt_rand(0, 12)], range(0, mt_rand(0, 10)))),
            4 => mt_rand(0, 1) ? [] : 'k',
            5 => array_map(static fn (): mixed => self::random($profundidad - 1), range(1, mt_rand(1, 4))),
            default => (static function () use ($profundidad): array {
                $o = [];
                for ($i = mt_rand(1, 4); $i > 0; $i--) {
                    $o['k'.mt_rand(0, 30).['x', 'y', 'z', 'ñ'][mt_rand(0, 3)]] = self::random($profundidad - 1);
                }

                return $o;
            })(),
        };
    }

    private static function nest(int $n): array
    {
        $v = ['x' => 1];
        for ($i = 0; $i < $n; $i++) {
            $v = ['a' => $v];
        }

        return $v;
    }

    /** `is_known` nunca da desconocido (un dato que falta es `false`) y DF-C8: a igual instante, el último contacto de la lista. */
    public function test_is_known_y_el_empate_del_ultimo_contacto(): void
    {
        $ahora = RelativeTime::parseInstant('2026-11-10T15:00:00Z');
        $conocido = CanonicalCondition::parse(['fact' => 'membership.days_to_expiry', 'op' => 'is_known']);
        $this->assertFalse(ConditionEvaluator::evaluate($conocido, FactSnapshot::fromArray(['facts' => []]), $ahora)->value);
        $this->assertTrue(ConditionEvaluator::evaluate($conocido, FactSnapshot::fromArray(['facts' => ['membership.days_to_expiry' => 3]]), $ahora)->value);

        $ultimo = CanonicalCondition::parse(['fact' => 'contact.last_outcome', 'op' => 'in', 'value' => ['replied']]);
        $fila = static fn (?string $resultado): array => ['at' => '2026-11-09T15:00:00Z', 'channel' => 'whatsapp', 'outcome' => $resultado, 'purpose' => 'commercial', 'strategy' => 'self'];
        $valor = static fn (array $filas): ?bool => ConditionEvaluator::evaluate($ultimo, FactSnapshot::fromArray(['contacts' => $filas]), $ahora)->value;
        $this->assertTrue($valor([$fila('not_interested'), $fila('replied')]));
        $this->assertFalse($valor([$fila('replied'), $fila('not_interested')]));
        $this->assertNull($valor([$fila('replied'), $fila(null)]), 'el último aún sin resultado: desconocido (DF-C8)');
    }
}
