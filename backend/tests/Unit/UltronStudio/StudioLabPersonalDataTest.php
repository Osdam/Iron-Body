<?php

namespace Tests\Unit\UltronStudio;

use App\Services\UltronStudio\StudioLabPersonalData as P;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El filtro de lo que viaja al proveedor (contrato H4 §A2): rechaza correos,
 * 7 o más dígitos aunque vayan separados, `+57`, `\p{Cf}`, el bloque de
 * etiquetas y U+2028/2029; deja pasar los precios cortos y lo normal.
 *
 * Las expresiones solo usan categorías generales y rangos: el PCRE 10.39 de
 * producción las admite (hay que sondearlas en el servidor tras desplegar).
 */
class StudioLabPersonalDataTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function rechazados(): iterable
    {
        yield 'celular pegado' => ['Mi número es 3001234567', P::REASON_DIGITS];
        yield 'celular con espacios' => ['Escríbeme al 300 123 4567', P::REASON_DIGITS];
        yield 'celular con guiones' => ['300-123-4567', P::REASON_DIGITS];
        yield 'cédula con puntos' => ['Cédula 1.075.234.567', P::REASON_DIGITS];
        yield 'siete dígitos justos' => ['1234567', P::REASON_DIGITS];
        yield 'dígitos de anchura completa' => ['３００１２３４５６７', P::REASON_DIGITS];
        yield 'separados por espacio no separable' => ["300\u{00A0}123\u{00A0}4567", P::REASON_DIGITS];
        yield 'separadores en racha' => ['300 - 123 - 4567', P::REASON_DIGITS];
        // Consecuencia del texto literal del contrato (fijada en lab-cases.json): un rango de precios también.
        yield 'rango con guion espaciado' => ['80.000 - 120.000', P::REASON_DIGITS];
        yield 'fecha con guiones' => ['15-10-2026', P::REASON_DIGITS];
        yield 'precio de siete cifras' => ['El anual vale 1.200.000', P::REASON_DIGITS];
        yield 'prefijo +57' => ['+57 300', P::REASON_PHONE_PREFIX];
        yield 'prefijo con espacio' => ['Llama al + 57', P::REASON_PHONE_PREFIX];
        yield 'prefijo de anchura completa' => ["\u{FF0B}57", P::REASON_PHONE_PREFIX];
        yield 'correo' => ['Mándalo a persona@ejemplo.com', P::REASON_EMAIL];
        yield 'correo corto' => ['a@b.c', P::REASON_EMAIL];
        yield 'correo con arroba de anchura completa' => ["persona\u{FF20}ejemplo.co", P::REASON_EMAIL];
        yield 'ZWSP' => ["hola\u{200B}", P::REASON_INVISIBLE];
        yield 'ZWJ' => ["a\u{200D}b", P::REASON_INVISIBLE];
        yield 'marca bidi' => ["\u{202E}texto", P::REASON_INVISIBLE];
        yield 'aislado bidi' => ["\u{2066}texto", P::REASON_INVISIBLE];
        yield 'U+061C' => ["hola\u{061C}", P::REASON_INVISIBLE];
        yield 'U+2060' => ["hola\u{2060}", P::REASON_INVISIBLE];
        yield 'U+180E' => ["hola\u{180E}", P::REASON_INVISIBLE];
        yield 'guion blando U+00AD' => ["ins\u{00AD}cribirme", P::REASON_INVISIBLE];
        yield 'BOM' => ["\u{FEFF}hola", P::REASON_INVISIBLE];
        yield 'etiqueta U+E0041' => ["x\u{E0041}", P::REASON_INVISIBLE];
        yield 'etiqueta U+E007F' => ["x\u{E007F}", P::REASON_INVISIBLE];
        yield 'principio del bloque U+E0000' => ["x\u{E0000}", P::REASON_INVISIBLE];
        yield 'U+2028' => ["una\u{2028}otra", P::REASON_INVISIBLE];
        yield 'U+2029' => ["una\u{2029}otra", P::REASON_INVISIBLE];
        yield 'control BEL' => ["hola\u{0007}", P::REASON_INVISIBLE];
        yield 'control C1' => ["hola\u{0085}", P::REASON_INVISIBLE];
        yield 'UTF-8 inválido' => ["hola \xFF", P::REASON_INVALID_UTF8];
    }

    #[DataProvider('rechazados')]
    public function test_rechaza(string $texto, string $motivo): void
    {
        $this->assertFalse(P::passes($texto));
        $this->assertSame($motivo, P::violation($texto));
    }

    /** @return iterable<string,array{string}> */
    public static function admitidos(): iterable
    {
        yield '80 mil' => ['La mensualidad vale 80 mil'];
        yield '2x1' => ['¿Tienen 2x1?'];
        yield '$80.000' => ['¿Son $80.000?'];
        yield 'dos precios separados' => ['entre 120.000 y 150.000'];
        yield 'fecha con barras' => ['15/10/2026'];
        yield 'seis dígitos' => ['123456'];
        yield 'hora' => ['A las 10:30'];
        yield 'arroba de usuario' => ['Búscanos como @gimnasio'];
        yield 'marcador' => ['{{PLAN_PRICE}} al mes'];
        yield 'salto de línea y tabulador' => ["Hola\nbuenas\ttardes\r\n"];
        yield 'emoji' => ['Hola 💪'];
        yield 'tildes y eñes' => ['¿Cuánto cuesta el año? Ñandú, pingüino'];
        yield 'vacío' => [''];
    }

    #[DataProvider('admitidos')]
    public function test_admite(string $texto): void
    {
        $this->assertTrue(P::passes($texto), (string) P::violation($texto));
        $this->assertNull(P::violation($texto));
    }

    public function test_el_mensaje_es_el_literal_del_contrato(): void
    {
        $this->assertSame(
            'Parece un dato personal (correo, teléfono o documento) o un carácter invisible: los escenarios son sintéticos y se envían a un proveedor externo',
            P::MESSAGE,
        );
    }

    public function test_scan_da_la_ruta_de_cada_texto_que_no_pasa_tambien_en_las_claves(): void
    {
        $escenario = [
            'title' => 'Bien',
            'turns' => [['role' => 'prospect', 'text' => 'Hola'], ['role' => 'prospect', 'text' => 'Al 3001234567']],
            'criteria' => [['id' => 'c1', 'text' => "con\u{200B}invisible", 'rules' => ['R-01']]],
            'facts' => ['paymentState' => 'none', 'handoffRequested' => false],
            'otro' => ['persona@ejemplo.com' => 'clave con correo'],
        ];

        $this->assertSame(['turns.1.text', 'criteria.0.text', 'otro.persona@ejemplo.com'], P::scan($escenario));
        $this->assertSame([], P::scan(['a' => 'ok', 'b' => 3, 'c' => null, 'd' => true]));
        $this->assertSame([''], P::scan('3001234567'));
    }

    public function test_las_expresiones_no_usan_propiedades_que_el_pcre_de_produccion_no_conoce(): void
    {
        foreach ([P::INVISIBLE_PATTERN, P::EMAIL_PATTERN, P::PHONE_PREFIX_PATTERN, P::DIGITS_PATTERN] as $patron) {
            $this->assertStringNotContainsString('Extended_Pictographic', $patron);
            $this->assertStringNotContainsString('Emoji', $patron);
            // Solo categorías generales de una o dos letras.
            preg_match_all('/\\\\p\{([^}]+)\}/', $patron, $m);
            foreach ($m[1] as $propiedad) {
                $this->assertMatchesRegularExpression('/^[A-Z][a-z]?$/', $propiedad, "{$propiedad} no es una categoría general");
            }
            $this->assertNotFalse(@preg_match($patron, 'x'), 'la expresión no compila');
        }
    }
}
