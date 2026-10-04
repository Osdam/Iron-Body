<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\Ultron\CourtesyAuthority;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La hora de una visita solo se registra si la persona la dijo SIN DUDAS.
 *
 * Medido en el canario el 2026-10-04: «Mejor mañana a las 7» con la visita a
 * las 18:00; el modelo entendió las 07:00. La regla del usuario: si la hora es
 * realmente ambigua se pregunta siempre, sin inferir a. m. o p. m. por el
 * contexto. Una lectura solo cuenta si la visita entera cabe en el horario de
 * ese día: «a las 3» no es ambiguo (las 03:00 están cerradas) ni «a las 10» un
 * día que cierra a las 22:00.
 */
class HoraAmbiguaTest extends TestCase
{
    private const VENTANAS = [
        'lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'],
        'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'],
        'sabado' => ['08:00', '14:00'], 'domingo' => ['08:00', '14:00'],
    ];

    /** @return array<string,array{0:string,1:string,2:string,3:?array}> texto, fecha, hora propuesta, lecturas si es ambigua */
    public static function casos(): array
    {
        return [
            'mañana a las 7: ambigua' => ['Mejor mañana a las 7', '2026-10-05', '07:00', ['07:00', '19:00']],
            'ambigua también si el modelo eligió la tarde' => ['Mejor mañana a las 7', '2026-10-05', '19:00', ['07:00', '19:00']],
            'mejor a las 7: ambigua' => ['mejor a las 7', '2026-10-05', '19:00', ['07:00', '19:00']],
            'quiero ir mañana a las 6: ambigua' => ['Quiero ir mañana a las 6', '2026-10-05', '18:00', ['06:00', '18:00']],
            'de la mañana: 07:00' => ['mañana a las 7 de la mañana', '2026-10-05', '07:00', null],
            'pm: 19:00' => ['mañana a las 7 pm', '2026-10-05', '19:00', null],
            'p. m.: 19:00' => ['7 p. m.', '2026-10-05', '19:00', null],
            'de la noche: 19:00' => ['a las 7 de la noche', '2026-10-05', '19:00', null],
            'una hora después: se deriva' => ['mejor una hora después', '2026-10-05', '19:00', null],
            'a las 3: las 03:00 cierran' => ['a las 3', '2026-10-05', '15:00', null],
            'a las 10 entre semana: la visita de las 22:00 no cabe' => ['a las 10', '2026-10-05', '10:00', null],
            'a las 9 entre semana: ambigua' => ['a las 9', '2026-10-05', '21:00', ['09:00', '21:00']],
            'a las 9 el sábado: solo la mañana' => ['a las 9', '2026-10-10', '09:00', null],
            'otra hora ambigua no estorba a una sin dudas' => ['salgo a las 5, mejor mañana a las 7 pm', '2026-10-05', '19:00', null],
        ];
    }

    #[DataProvider('casos')]
    public function test_la_hora_se_registra_solo_si_se_dijo_sin_dudas(string $texto, string $fecha, string $hora, ?array $lecturas): void
    {
        $ahora = Carbon::parse('2026-10-04 21:25:00', 'UTC');
        $v = CourtesyAuthority::decide($fecha, $hora, self::VENTANAS, $ahora, $texto);

        if ($lecturas === null) {
            $this->assertTrue($v['ok'], "«{$texto}» → {$hora} debía registrarse y salió {$v['reason']}");
        } else {
            $this->assertFalse($v['ok'], "«{$texto}» → {$hora} se registró sin que la persona aclarara la hora");
            $this->assertSame(CourtesyAuthority::HORA_AMBIGUA, $v['reason']);
            $this->assertSame($lecturas, $v['options']);
        }
    }

    /**
     * La hora que la persona PROPONE, leída por cláusulas: sin las del trabajo
     * o el gimnasio, sin las negadas y con la corrección que manda. Null si no
     * propone una; una lectura si es inequívoca; dos si es ambigua.
     *
     * @return array<string,array{0:string,1:string,2:?array}>
     */
    public static function propuestas(): array
    {
        return [
            'el caso del canario' => ['Mejor mañana a las 7', '2026-10-05', ['07:00', '19:00']],
            'sin día: el de la visita' => ['mejor a las 7', '2026-10-05', ['07:00', '19:00']],
            'con pregunta sigue siendo la propuesta' => ['¿Mejor mañana a las 7?', '2026-10-05', ['07:00', '19:00']],
            '7 p. m.' => ['7 p. m.', '2026-10-05', ['19:00']],
            '7pm porfa' => ['7pm porfa', '2026-10-05', ['19:00']],
            'de la noche' => ['a las 7 de la noche', '2026-10-05', ['19:00']],
            'de la mañana' => ['mañana a las 7 de la mañana', '2026-10-05', ['07:00']],
            'la del trabajo no es la de la visita' => ['mejor mañana a las 7, salgo del trabajo a las 7 pm', '2026-10-05', ['07:00', '19:00']],
            'ni estorba a una sin dudas' => ['salgo a las 5, mejor mañana a las 7 pm', '2026-10-05', ['19:00']],
            'la del trabajo tampoco cuenta sin corrección' => ['mañana a las 7, salgo del trabajo a las 6 pm', '2026-10-05', ['07:00', '19:00']],
            'la corrección manda' => ['a las 7 no puedo, mejor a las 8', '2026-10-05', ['08:00', '20:00']],
            'a las 3: sólo la tarde' => ['a las 3', '2026-10-05', ['15:00']],
            'a las 9 el sábado: sólo la mañana' => ['a las 9', '2026-10-10', ['09:00']],
            'fuera del horario' => ['a las 23', '2026-10-05', []],
            'dos horas distintas' => ['a las 7 u 8', '2026-10-05', null],
            'ninguna hora' => ['mejor una hora después', '2026-10-05', null],
        ];
    }

    #[DataProvider('propuestas')]
    public function test_la_hora_propuesta_se_lee_por_clausulas(string $texto, string $fecha, ?array $esperado): void
    {
        $this->assertSame($esperado, CourtesyAuthority::lecturasPropuestas($texto, $fecha, self::VENTANAS, Carbon::parse('2026-10-04 21:49:00', 'UTC')));
    }

    public function test_sin_el_texto_la_autoridad_decide_como_siempre(): void
    {
        $v = CourtesyAuthority::decide('2026-10-05', '07:00', self::VENTANAS, Carbon::parse('2026-10-04 21:25:00', 'UTC'));
        $this->assertTrue($v['ok']);
    }
}
