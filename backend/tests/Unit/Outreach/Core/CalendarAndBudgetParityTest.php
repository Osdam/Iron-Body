<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\ContactBudget;
use App\Services\Outreach\Core\ContactCalendar;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Telephony\ColombianHolidays;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PARIDAD del calendario (`calendar-cases.json`: N1 = ley ∩ silencio, horario
 * propio y de la estrategia, primer hueco; §13.1 #17, T16, T22) y del
 * presupuesto de contacto (`contact-budget-cases.json`: límites efectivos,
 * los motivos con su `releaseAt` y `retryAt`, y la paridad con `ContactPolicy`
 * por frecuencia y horario, T9), caso a caso.
 */
class CalendarAndBudgetParityTest extends TestCase
{
    use LoadsOutreachFixtures;

    private const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function calendar(): iterable
    {
        return self::cases('calendar-cases', ['holidays', 'window', 'nextSlot']);
    }

    #[DataProvider('calendar')]
    public function test_calendario(array $caso): void
    {
        $e = $caso['expected'];
        if ($caso['_section'] === 'holidays') {
            $fechas = array_keys(ColombianHolidays::forYear($caso['year']));
            $this->assertSame($e['dates'], $fechas);
            $this->assertCount($e['count'], $fechas);

            return;
        }
        if ($caso['_section'] === 'window') {
            $ahora = RelativeTime::parseInstant($caso['now']);
            $local = RelativeTime::local($ahora);
            $this->assertSame($e, ContactCalendar::window($ahora, $caso['ownHours']) + ['bogota' => $local->format('Y-m-d H:i:s'), 'weekday' => self::DIAS[(int) $local->format('N')]]);

            return;
        }
        $hueco = ContactCalendar::nextSlot(RelativeTime::parseInstant($caso['from']), $caso['ownHours'], $caso['schedule']);
        $this->assertNotNull($hueco);
        $local = RelativeTime::local($hueco);
        $this->assertSame($e, ['at' => RelativeTime::format($hueco), 'bogota' => $local->format('Y-m-d H:i:s'), 'weekday' => self::DIAS[(int) $local->format('N')]]);
        // El hueco está dentro de las tres capas y nunca antes de lo pedido.
        $this->assertTrue(ContactCalendar::allows($hueco, $caso['ownHours'], $caso['schedule']));
        $this->assertGreaterThanOrEqual(RelativeTime::parseInstant($caso['from']), $hueco);
    }

    /** @return iterable<string, array{array<string,mixed>}> */
    public static function budget(): iterable
    {
        return self::cases('contact-budget-cases', ['effectiveLimits', 'check', 'parity']);
    }

    #[DataProvider('budget')]
    public function test_presupuesto_de_contacto(array $caso): void
    {
        if ($caso['_section'] === 'effectiveLimits') {
            $this->assertSame($caso['expected'], ContactBudget::effectiveLimits($caso['config']));

            return;
        }
        $limites = $caso['limits'] ?? ContactBudget::effectiveLimits();
        $v = ContactBudget::check($caso['history'], RelativeTime::parseInstant($caso['now']), $caso['purpose'], $caso['medium'], $limites, $caso['ownHours'] ?? null)->toArray();
        if ($caso['_section'] === 'check') {
            $this->assertCanonicalSame($caso['expected'], $v);
            $this->assertSame(array_keys($caso['expected']['releaseAt']), array_keys($v['releaseAt']), 'releaseAt en el orden de los motivos');

            return;
        }
        $e = $caso['expected'];
        $this->assertSame($e['contactBudget'], ['allowed' => $v['allowed'], 'reasons' => $v['reasons'], 'retryAt' => $v['retryAt']]);
        // T9: lo que ContactPolicy niega por frecuencia u horario, el presupuesto también.
        if ($e['contactPolicy']['denied']) {
            $this->assertFalse($v['allowed'], 'ContactPolicy niega ('.$e['contactPolicy']['reason'].') y el H9 permite');
        }
    }

    public function test_el_fixture_del_presupuesto_es_el_del_codigo(): void
    {
        $f = self::fixture('contact-budget-cases');
        $this->assertSame(['perDay' => ContactBudget::PER_DAY, 'perWeek' => ContactBudget::DEFAULT_PER_WEEK, 'minHoursBetween' => ContactBudget::DEFAULT_MIN_HOURS], $f['floor']);
        $this->assertSame($f['countedSources'], ContactBudget::COUNTED_SOURCES);
        $this->assertSame($f['notCountedSources'], ContactBudget::NOT_COUNTED_SOURCES);
        $this->assertSame($f['reasonOrder'], ContactBudget::REASON_ORDER);
        $c = self::fixture('calendar-cases');
        $this->assertSame($c['n1Windows']['weekdays'], ContactCalendar::N1_WINDOWS[1]);
        $this->assertSame($c['n1Windows']['saturday'], ContactCalendar::N1_WINDOWS[6]);
        $this->assertArrayNotHasKey(7, ContactCalendar::N1_WINDOWS);
    }

    public function test_una_fila_del_historial_sin_forma_es_un_error_de_quien_llama(): void
    {
        $ahora = RelativeTime::parseInstant('2026-11-10T15:00:00Z');
        foreach ([
            [['at' => '2026-11-10T13:00:00Z', 'medium' => 'whatsapp', 'purpose' => 'commercial', 'source' => 'sms']],
            [['at' => '2026-11-10T13:00:00Z', 'medium' => 'sms', 'purpose' => 'commercial', 'source' => 'outreach']],
            [['at' => '2026-11-10 13:00:00', 'medium' => 'whatsapp', 'purpose' => 'commercial', 'source' => 'outreach']],
            ['no es una fila'],
        ] as $historial) {
            try {
                ContactBudget::check($historial, $ahora, 'commercial', 'whatsapp', ContactBudget::effectiveLimits());
                $this->fail('Una fila sin forma no se cuenta «a ojo».');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        ContactBudget::check([], $ahora, 'commercial', 'whatsapp', ['perDay' => 1, 'perWeek' => 0, 'minHoursBetween' => 48]);
    }

    public function test_sin_ningun_dia_con_franja_no_hay_hueco(): void
    {
        $desde = RelativeTime::parseInstant('2026-11-10T15:00:00Z');
        $this->assertNull(ContactCalendar::nextSlot($desde, 'invalid'));
        $this->assertNull(ContactCalendar::nextSlot($desde, false), 'false (lo que da OutboundSchedule::windows) también falla cerrado');
        $this->assertNull(ContactCalendar::nextSlot($desde, ['weekdays' => null, 'saturday' => null]));
        $this->assertNull(ContactCalendar::nextSlot($desde, null, ['days' => [2], 'windows' => [['from' => 1200, 'to' => 1300]]]), 'una franja fuera de la ley no deja hueco');
        $this->assertSame(['inside' => false, 'n1' => [], 'n5' => ['calendar_outside_own_hours']], ContactCalendar::window($desde, ['weekdays' => [600, 500], 'saturday' => null]), 'una franja propia al revés es mal configurada (N5, L9-15)');
        $this->assertSame(['inside' => false, 'n1' => ['calendar_sunday'], 'n5' => []], ContactCalendar::window(RelativeTime::parseInstant('2026-11-08T15:00:00Z'), 'invalid'), 'fuera de la ley decide N1 y N5 ni se mira');
    }

    public function test_el_hueco_nunca_es_antes_de_lo_pedido(): void
    {
        // Una fracción de segundo se redondea hacia arriba (dentro de la franja, el mismo instante redondeado).
        $con = new DateTimeImmutable('2026-11-10 15:00:00.250000', new DateTimeZone('UTC'));
        $this->assertSame('2026-11-10T15:00:01Z', RelativeTime::format(ContactCalendar::nextSlot($con, null)));
        // Recorrido: cada media hora de dos semanas, el hueco es ≥ el instante, permitido, y nunca domingo ni festivo.
        $t = RelativeTime::parseInstant('2026-11-07T00:00:00Z');
        for ($i = 0; $i < 14 * 48; $i++) {
            $desde = $t->modify('+'.($i * 30).' minutes');
            $hueco = ContactCalendar::nextSlot($desde, ['weekdays' => [540, 1080], 'saturday' => [540, 780]], ['days' => [1, 2, 3, 4, 5, 6], 'windows' => [['from' => 600, 'to' => 720], ['from' => 840, 'to' => 1020]]]);
            $this->assertGreaterThanOrEqual($desde, $hueco);
            $local = RelativeTime::local($hueco);
            $this->assertNotSame('7', $local->format('N'));
            $this->assertFalse(ColombianHolidays::isHolidayDate($local->format('Y-m-d')));
            $minuto = (int) $local->format('G') * 60 + (int) $local->format('i');
            $this->assertTrue(($minuto >= 600 && $minuto < 720) || ($minuto >= 840 && $minuto < 1020), $local->format('c'));
        }
    }

    /** L9-27, L9-30: el tope diario también por teléfono, con la lista completa; desconocido no se cruza. */
    public function test_el_tope_diario_tambien_por_telefono(): void
    {
        $ahora = RelativeTime::parseInstant('2026-11-10T15:00:00Z'); // martes 10:00 de Bogotá
        [$a, $b, $c] = [str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64)];
        $limites = ContactBudget::effectiveLimits();
        $con = static fn (array $refs, array $contactos): array => ContactBudget::check([], $ahora, 'commercial', 'whatsapp', $limites, ['refs' => $refs, 'contacts' => $contactos])->toArray();
        $hoy = ['at' => '2026-11-10T13:00:00Z', 'phoneRef' => $b];

        $v = $con([$a, $b], [$hoy]);
        $this->assertSame([false, ['daily_contact_limit'], ['daily_contact_limit' => '2026-11-11T05:00:00Z'], '2026-11-11T13:00:00Z'], [$v['allowed'], $v['reasons'], $v['releaseAt'], $v['retryAt']], 'cualquiera de sus teléfonos ocupa el día');
        $this->assertSame(['today' => 0, 'rolling7' => 0], $v['counts'], 'el contacto del teléfono no es de la persona');
        $this->assertFalse($con([$b], [['at' => '2026-11-10T05:00:00Z', 'phoneRef' => $b]])['allowed'], '00:00 de Bogotá ya es hoy');
        $this->assertTrue($con([$b], [['at' => '2026-11-10T04:59:59Z', 'phoneRef' => $b]])['allowed'], '23:59:59 de ayer en Bogotá no es hoy');
        $this->assertTrue($con([$a, $b], [['at' => '2026-11-10T13:00:00Z', 'phoneRef' => $c]])['allowed'], 'otro teléfono no cuenta');
        $this->assertTrue($con([], [$hoy])['allowed'], 'teléfono desconocido: no se cruza');
        $this->assertTrue($con([$b], [['at' => '2026-11-10T16:00:00Z', 'phoneRef' => $b]])['allowed'], 'lo posterior a «ahora» no cuenta');
        $ayer = $con([$b], [['at' => '2026-11-09T20:00:00Z', 'phoneRef' => $b]]);
        $this->assertSame([true, []], [$ayer['allowed'], $ayer['reasons']], 'ni la semana ni las 48 h: son de la persona');
        $this->assertTrue(ContactBudget::check([], $ahora, 'commercial', 'whatsapp', $limites, null)->allowed(), 'sin el dato: no se cruza');

        foreach ([
            ['refs' => [$a]],
            ['refs' => [$a], 'contacts' => [], 'extra' => 1],
            ['refs' => [strtoupper($a)], 'contacts' => []],
            ['refs' => ['abc'], 'contacts' => []],
            ['refs' => [$a], 'contacts' => [['at' => '2026-11-10T13:00:00Z']]],
            ['refs' => [$a], 'contacts' => [['at' => '2026-11-10 13:00:00', 'phoneRef' => $a]]],
            ['refs' => [$a], 'contacts' => [['at' => '2026-11-10T13:00:00Z', 'phoneRef' => $a, 'who' => 'x']]],
            ['refs' => ['x' => $a], 'contacts' => []],
        ] as $i => $telefono) {
            try {
                ContactBudget::check([], $ahora, 'commercial', 'whatsapp', $limites, $telefono);
                $this->fail("El teléfono {$i} sin forma no lanzó.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
