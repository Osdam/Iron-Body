<?php

namespace Tests\Unit\Telephony;

use App\Services\Telephony\ColombianHolidays as H;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Los festivos de Colombia (contrato H8, D8-15): Ley 51 de 1983 (Emiliani),
 * los móviles de Pascua y la Ley 2578 de 2026 (Chiquinquirá, desde 2026). El
 * fixture 2026–2028 de abajo está calculado a mano y contrastado con el
 * calendario publicado de 2028 y con la noticia del Ministerio del Interior
 * (05-06-2026: el festivo de 2026 es el lunes 13 de julio); lo aprueba el propietario.
 */
class ColombianHolidaysTest extends TestCase
{
    private const FIXTURE = [
        2026 => ['2026-01-01', '2026-01-12', '2026-03-23', '2026-04-02', '2026-04-03', '2026-05-01', '2026-05-18', '2026-06-08', '2026-06-15', '2026-06-29',
            '2026-07-13', '2026-07-20', '2026-08-07', '2026-08-17', '2026-10-12', '2026-11-02', '2026-11-16', '2026-12-08', '2026-12-25'],
        2027 => ['2027-01-01', '2027-01-11', '2027-03-22', '2027-03-25', '2027-03-26', '2027-05-01', '2027-05-10', '2027-05-31', '2027-06-07', '2027-07-05',
            '2027-07-12', '2027-07-20', '2027-08-07', '2027-08-16', '2027-10-18', '2027-11-01', '2027-11-15', '2027-12-08', '2027-12-25'],
        2028 => ['2028-01-01', '2028-01-10', '2028-03-20', '2028-04-13', '2028-04-14', '2028-05-01', '2028-05-29', '2028-06-19', '2028-06-26', '2028-07-03',
            '2028-07-10', '2028-07-20', '2028-08-07', '2028-08-21', '2028-10-16', '2028-11-06', '2028-11-13', '2028-12-08', '2028-12-25'],
    ];

    public function test_el_fixture_2026_2028(): void
    {
        foreach (self::FIXTURE as $anio => $fechas) {
            $this->assertSame($fechas, array_keys(H::forYear($anio)), (string) $anio);
            $this->assertCount(19, H::forYear($anio));
        }
        $this->assertSame(['virgen_de_chiquinquira'], H::forYear(2026)['2026-07-13']);
        $this->assertSame(['reyes_magos'], H::forYear(2026)['2026-01-12']);
    }

    public function test_pascua_y_la_regla_emiliani(): void
    {
        $this->assertSame(['2026-04-05', '2027-03-28', '2028-04-16', '2024-03-31', '2025-04-20', '2019-04-21'],
            array_map(static fn (int $a): string => H::easterSunday($a)->format('Y-m-d'), [2026, 2027, 2028, 2024, 2025, 2019]));
        $this->assertFalse(H::isHolidayDate('2026-01-06'), 'trasladado al lunes');
        $this->assertTrue(H::isHolidayDate('2026-06-29'), 'un lunes se queda');
        $this->assertFalse(H::isHolidayDate('2026-04-05'), 'el domingo de Pascua no es festivo');
        $this->assertFalse(H::isHolidayDate('2026-12-24'));
        foreach (array_merge(...array_values(self::FIXTURE)) as $fecha) {
            $dia = (int) (new DateTimeImmutable($fecha))->format('N');
            $this->assertTrue($dia === 1 || in_array(substr($fecha, 5), ['01-01', '05-01', '07-20', '08-07', '12-08', '12-25'], true)
                || in_array($fecha, ['2026-04-02', '2026-04-03', '2027-03-25', '2027-03-26', '2028-04-13', '2028-04-14'], true), $fecha);
        }
    }

    public function test_la_ley_2578_cuenta_desde_2026_y_dos_festivos_pueden_coincidir(): void
    {
        // 2025, el calendario que ya pasó: sin Chiquinquirá, y San Pedro (29-06,
        // domingo) y el Sagrado Corazón (Pascua + 71) en el mismo lunes 30-06:
        // 18 festivos en 17 fechas.
        $d2025 = H::forYear(2025);
        $this->assertCount(17, $d2025);
        $this->assertSame(18, array_sum(array_map('count', $d2025)));
        $this->assertSame(['sagrado_corazon', 'san_pedro_y_san_pablo'], $d2025['2025-06-30']);
        $this->assertFalse(H::isHolidayDate('2025-07-14'));
        $d2038 = H::forYear(2038);
        $this->assertSame(['sagrado_corazon', 'san_pedro_y_san_pablo'], $d2038['2038-07-05']);
        $this->assertCount(18, $d2038, '19 festivos en 18 fechas');
    }

    public function test_el_dia_es_el_de_bogota(): void
    {
        $this->assertTrue(H::isHoliday(new DateTimeImmutable('2026-07-14T03:00:00Z')), 'aún es el lunes 13 en Bogotá');
        $this->assertTrue(H::isHoliday(new DateTimeImmutable('2026-07-13T05:00:00Z')));
        $this->assertFalse(H::isHoliday(new DateTimeImmutable('2026-07-13T04:59:59Z')), 'aún es el domingo 12 en Bogotá');
    }

    public function test_fechas_y_anios_fuera_de_rango(): void
    {
        foreach (['2026-02-30', '26-07-13', '2026-7-13', "2026-07-13\n"] as $mala) {
            try {
                H::isHolidayDate($mala);
                $this->fail($mala);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        H::forYear(1983);
    }
}
