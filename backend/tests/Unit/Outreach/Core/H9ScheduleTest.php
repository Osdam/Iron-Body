<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Outreach\Core\ContactCalendar;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Telephony\ColombianHolidays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §13.1 #17, texto literal del propietario: «Horario o día no permitido».
 * Domingo, festivo (Ley 2578 incluida), fuera del horario legal (Ley 2300) o
 * del propio: `defer` al primer hueco permitido, nunca contacto. Sobre
 * `PolicyLadder` y `ContactCalendar` reales; «el primer hueco» se comprueba
 * minuto a minuto (ninguno permitido entre «ahora» y `retryAt`).
 */
class H9ScheduleTest extends TestCase
{
    use H9Scenario;

    private const PROPIO = ['weekdays' => [540, 1080], 'saturday' => [540, 780]];

    /** @return iterable<string, array{string, mixed, string, list<string>, string}> */
    public static function noPermitidos(): iterable
    {
        // [ahora (UTC), horario propio, nivel, motivos, primer hueco (UTC)]; Bogotá = UTC−05:00.
        yield 'domingo 15-11 10:00 → martes 17-11 08:00 (el lunes 16 es festivo)' => ['2026-11-15T15:00:00Z', null, 'N1', ['calendar_sunday'], '2026-11-17T13:00:00Z'];
        yield 'lunes festivo trasladado 16-11 10:00 → martes 08:00' => ['2026-11-16T15:00:00Z', null, 'N1', ['calendar_holiday'], '2026-11-17T13:00:00Z'];
        yield 'Ley 2578: lunes 13-07-2026 (Chiquinquirá trasladado) 10:00 → martes 08:00' => ['2026-07-13T15:00:00Z', null, 'N1', ['calendar_holiday'], '2026-07-14T13:00:00Z'];
        yield 'martes 07:59 → 08:00' => ['2026-11-10T12:59:00Z', null, 'N1', ['calendar_outside_legal_hours'], '2026-11-10T13:00:00Z'];
        yield 'martes 19:00 justas → miércoles 08:00' => ['2026-11-11T00:00:00Z', null, 'N1', ['calendar_outside_legal_hours'], '2026-11-11T13:00:00Z'];
        yield 'sábado 15:00 justas → martes 17-11 08:00' => ['2026-11-14T20:00:00Z', null, 'N1', ['calendar_outside_legal_hours'], '2026-11-17T13:00:00Z'];
        yield 'horario propio 09:00–18:00, martes 08:30 (la ley ya abrió) → 09:00' => ['2026-11-10T13:30:00Z', self::PROPIO, 'N5', ['calendar_outside_own_hours'], '2026-11-10T14:00:00Z'];
        yield 'horario propio, martes 18:00 justas → miércoles 09:00' => ['2026-11-10T23:00:00Z', self::PROPIO, 'N5', ['calendar_outside_own_hours'], '2026-11-11T14:00:00Z'];
        yield 'horario propio sábado 09:00–13:00, sábado 13:30 → martes 17-11 09:00' => ['2026-11-14T18:30:00Z', self::PROPIO, 'N5', ['calendar_outside_own_hours'], '2026-11-17T14:00:00Z'];
        yield 'fuera de la ley y del propio: decide la ley (N1), con el hueco del propio' => ['2026-11-10T12:00:00Z', self::PROPIO, 'N1', ['calendar_outside_legal_hours'], '2026-11-10T14:00:00Z'];
    }

    #[DataProvider('noPermitidos')]
    public function test_aplaza_al_primer_hueco_permitido_y_nunca_contacta(string $ahora, mixed $propio, string $nivel, array $motivos, string $hueco): void
    {
        $d = self::ladderFor('whatsapp_template', ['now' => $ahora, 'ownHours' => $propio]);

        $this->assertSame(['defer', false, $nivel, $motivos, $hueco], [$d['verdict'], $d['allowed'], $d['level'], $d['reasons'], $d['retryAt']]);
        $reintento = RelativeTime::parseInstant($hueco);
        $this->assertTrue(ContactCalendar::allows($reintento, $propio, null), 'el hueco está permitido');
        $local = RelativeTime::local($reintento);
        $this->assertNotSame('7', $local->format('N'), 'nunca en domingo');
        $this->assertFalse(ColombianHolidays::isHolidayDate($local->format('Y-m-d')), 'nunca en festivo');
        // El PRIMERO: ningún minuto entre «ahora» y el hueco está permitido.
        for ($t = RelativeTime::parseInstant($ahora); $t < $reintento; $t = $t->modify('+1 minute')) {
            $this->assertFalse(ContactCalendar::allows($t, $propio, null), RelativeTime::format($t));
        }
    }

    public function test_el_festivo_de_la_ley_2578_esta_en_el_calendario(): void
    {
        $this->assertContains('virgen_de_chiquinquira', ColombianHolidays::forYear(2026)['2026-07-13'] ?? []);
        $this->assertTrue(ColombianHolidays::isHolidayDate('2026-11-16'), 'Independencia de Cartagena, trasladada al lunes');
    }
}
