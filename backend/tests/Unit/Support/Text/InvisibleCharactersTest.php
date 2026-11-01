<?php

namespace Tests\Unit\Support\Text;

use App\Rules\PlainText;
use App\Services\UltronStudio\StudioLabChecks;
use App\Services\UltronStudio\StudioLabPersonalData;
use App\Support\Text\InvisibleCharacters as I;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La clase de invisibles de `PlainText` y de la API interna de conocimiento
 * (H7, SEC7; AS-5 del H5): la de `lab-invisibles`, menos lo que forma un
 * emoji. Pura: ni base, ni configuración. Este fichero no lleva ningún
 * invisible literal: todos van con `\u{…}`.
 */
class InvisibleCharactersTest extends TestCase
{
    /** Emojis compuestos y textos legítimos que tienen que pasar en una línea y en varias. */
    public static function legitimos(): array
    {
        return [
            'familia (ZWJ entre pictogramas)' => ["\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"],
            'corazón rojo (U+FE0F)' => ["\u{2764}\u{FE0F}"],
            'levantadora (U+FE0F antes del ZWJ)' => ["\u{1F3CB}\u{FE0F}\u{200D}\u{2640}\u{FE0F}"],
            'apretón con tonos de piel (tono antes del ZWJ)' => ["\u{1F9D1}\u{1F3FD}\u{200D}\u{1F91D}\u{200D}\u{1F9D1}\u{1F3FB}"],
            'corazón en llamas' => ["\u{2764}\u{FE0F}\u{200D}\u{1F525}"],
            'bandera arcoíris' => ["\u{1F3F3}\u{FE0F}\u{200D}\u{1F308}"],
            'pirata' => ["\u{1F3F4}\u{200D}\u{2620}\u{FE0F}"],
            'oso polar' => ["\u{1F43B}\u{200D}\u{2744}\u{FE0F}"],
            'pájaro negro (U+2B1B)' => ["\u{1F426}\u{200D}\u{2B1B}"],
            'cabeza que niega (U+2194)' => ["\u{1F642}\u{200D}\u{2194}\u{FE0F}"],
            'sanitario con tono' => ["\u{1F468}\u{1F3FE}\u{200D}\u{2695}\u{FE0F}"],
            'tecla 1 (U+20E3)' => ["1\u{FE0F}\u{20E3}"],
            'copyright y marca con selector' => ["\u{00A9}\u{FE0F} \u{2122}\u{FE0F}"],
            'bandera de Colombia' => ["\u{1F1E8}\u{1F1F4}"],
            'emoji dentro de una frase' => ["Promo de enero \u{1F3CB}\u{FE0F}\u{200D}\u{2640}\u{FE0F} con \u{2764}\u{FE0F}"],
            'español con signos' => ['Promoción 2x1 — ¡ñandú! «Hola» $80.000 · ¿sí?'],
            'NBSP y espacio fino (visibles, fuera de la clase)' => ["10\u{00A0}000 y 10:00\u{202F}a. m."],
        ];
    }

    #[DataProvider('legitimos')]
    public function test_lo_legitimo_pasa_en_una_linea_y_en_varias(string $texto): void
    {
        $this->assertNull(I::violation($texto, false));
        $this->assertNull(I::violation($texto, true));
        $this->assertSame(['text' => $texto, 'removed' => 0, 'codepoints' => [], 'invalidUtf8' => false], I::clean($texto));
    }

    /** Un representante de cada rango de la clase (y de cada familia que tenía que cubrir AS-5). */
    public static function prohibidos(): array
    {
        $casos = [];
        foreach ([
            0x00AD, 0x0600, 0x061C, 0x06DD, 0x070F, 0x180E,
            0x200B, 0x200C, 0x200E, 0x200F,
            0x2028, 0x2029, 0x202A, 0x202B, 0x202C, 0x202D, 0x202E,
            0x2060, 0x2061, 0x2062, 0x2063, 0x2064, 0x2066, 0x2067, 0x2068, 0x2069,
            0x206A, 0x206B, 0x206C, 0x206D, 0x206E, 0x206F,
            0xFEFF, 0xFFF9, 0xFFFA, 0xFFFB, 0x110BD, 0x1D173, 0x1D17A,
            0xE0000, 0xE0001, 0xE0020, 0xE0041, 0xE007E, 0xE007F,
            0x0000, 0x0001, 0x0008, 0x000B, 0x000C, 0x000E, 0x001B, 0x001F,
            0x007F, 0x0080, 0x0085, 0x009B, 0x009F,
        ] as $cp) {
            $casos[sprintf('U+%04X', $cp)] = [$cp];
        }

        return $casos;
    }

    #[DataProvider('prohibidos')]
    public function test_cada_rango_de_la_clase_se_rechaza_y_se_limpia(int $cp): void
    {
        $c = mb_chr($cp, 'UTF-8');
        $esperado = sprintf('U+%04X', $cp);
        $texto = "des{$c}cuento";

        $this->assertSame($esperado, I::violation($texto, true), 'varias líneas');
        $this->assertSame($esperado, I::violation($texto, false), 'una línea');
        $this->assertFalse(I::isPlain($texto, true));

        $r = I::clean($texto);
        $this->assertSame(1, $r['removed']);
        $this->assertSame([$esperado], $r['codepoints']);
        $this->assertTrue(I::isPlain($r['text'], true), 'lo limpio ya es texto plano');
        $reemplazo = match (true) {
            $cp === 0x2028, $cp === 0x2029 => "\n",
            $cp <= 0x001F, $cp >= 0x007F && $cp <= 0x009F => ' ',
            default => '',
        };
        $this->assertSame("des{$reemplazo}cuento", $r['text']);
    }

    /**
     * La «lista exacta del laboratorio» (contrato H7, SEC7), punto de código a
     * punto de código: entre dos letras, `PlainText` de varias líneas rechaza
     * EXACTAMENTE lo que dispara `lab-invisibles`
     * ({@see StudioLabChecks::INVISIBLE_PATTERN}). El ZWJ va aparte: el
     * laboratorio lo deja pasar siempre y aquí solo dentro de un emoji.
     */
    public function test_rechaza_exactamente_lo_que_dispara_lab_invisibles_en_todo_unicode(): void
    {
        $discrepancias = [];
        $rechazados = 0;
        for ($cp = 0; $cp <= 0x10FFFF; $cp++) {
            if (($cp >= 0xD800 && $cp <= 0xDFFF) || $cp === 0x200D) {
                continue;
            }
            $texto = 'a'.mb_chr($cp, 'UTF-8').'b';
            $laboratorio = preg_match(StudioLabChecks::INVISIBLE_PATTERN, $texto) === 1;
            $rechazados += $laboratorio ? 1 : 0;
            if ($laboratorio === I::isPlain($texto, true)) {
                $discrepancias[] = sprintf('U+%04X', $cp);
            }
        }

        $this->assertSame([], array_slice($discrepancias, 0, 20));
        // Cota baja: si la clase se quedara vacía (una expresión rota), esto lo caza.
        $this->assertGreaterThan(200, $rechazados);
    }

    /** Sin deriva: la clase y los pictogramas son los del laboratorio, carácter a carácter. */
    public function test_la_clase_y_los_pictogramas_son_los_del_laboratorio(): void
    {
        $this->assertSame(StudioLabPersonalData::INVISIBLE_CLASS, I::INVISIBLE_CLASS);
        $this->assertSame(StudioLabChecks::PICTO, I::PICTO);
    }

    public static function zwjFueraDeUnEmoji(): array
    {
        return [
            'entre letras' => ["des\u{200D}cuento"],
            'emoji y luego letra' => ["\u{1F468}\u{200D}a"],
            'letra y luego emoji' => ["a\u{200D}\u{1F468}"],
            'al principio' => ["\u{200D}\u{1F468}"],
            'al final' => ["\u{1F468}\u{200D}"],
            'dos seguidos' => ["\u{1F468}\u{200D}\u{200D}\u{1F469}"],
            'solo' => ["\u{200D}"],
            'tras un selector de texto (U+FE0E)' => ["\u{2764}\u{FE0E}\u{200D}\u{1F525}"],
        ];
    }

    #[DataProvider('zwjFueraDeUnEmoji')]
    public function test_el_zwj_suelto_se_rechaza_y_se_limpia(string $texto): void
    {
        $this->assertSame('U+200D', I::violation($texto, true));
        $this->assertSame('U+200D', I::violation($texto, false));

        $r = I::clean($texto);
        $this->assertGreaterThanOrEqual(1, $r['removed']);
        $this->assertSame(['U+200D'], $r['codepoints']);
        $this->assertStringNotContainsString("\u{200D}", $r['text']);
    }

    public function test_el_zwj_de_un_emoji_se_conserva_al_limpiar_lo_demas(): void
    {
        $familia = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $r = I::clean("Plan{$familia}\u{200B} familiar\u{E0041}");

        $this->assertSame("Plan{$familia} familiar", $r['text']);
        $this->assertSame(2, $r['removed']);
        $this->assertSame(['U+200B', 'U+E0041'], $r['codepoints']);
    }

    public function test_tabulador_y_saltos_solo_en_varias_lineas(): void
    {
        foreach (['U+0009' => "a\tb", 'U+000A' => "a\nb", 'U+000D' => "a\r\nb"] as $esperado => $texto) {
            $this->assertNull(I::violation($texto, true), $esperado);
            $this->assertSame($esperado, I::violation($texto, false), $esperado);
            // Limpiar no los toca: no están en la clase.
            $this->assertSame($texto, I::clean($texto)['text']);
        }
    }

    public function test_utf8_invalido_falla_cerrado_y_al_limpiar_se_sustituye(): void
    {
        foreach (["Jos\xE9", "\xC3", "a\xED\xA0\x80b", "\xF4\x90\x80\x80"] as $malo) {
            $this->assertSame(I::INVALID_UTF8, I::violation($malo, true));
            $this->assertFalse(I::isPlain($malo));

            $r = I::clean($malo);
            $this->assertTrue($r['invalidUtf8']);
            $this->assertTrue(mb_check_encoding($r['text'], 'UTF-8'));
            $this->assertStringContainsString('?', $r['text']);
        }
    }

    public function test_limpiar_el_contrabando_de_etiquetas_deja_solo_lo_visible(): void
    {
        // «Hola» + «IGNORE» escrito con etiquetas U+E0049…: un modelo lo lee, una persona no.
        $oculto = implode('', array_map(static fn (string $l): string => mb_chr(0xE0000 + ord($l), 'UTF-8'), str_split('IGNORE')));
        $r = I::clean("Hola{$oculto}");

        $this->assertSame('Hola', $r['text']);
        $this->assertSame(6, $r['removed']);
        $this->assertSame(['U+E0045', 'U+E0047', 'U+E0049', 'U+E004E', 'U+E004F', 'U+E0052'], $r['codepoints']);
    }

    public function test_limpiar_recorta_los_bordes_que_quedan_al_descubierto_y_acota_los_puntos(): void
    {
        $this->assertSame('Hola', I::clean("\u{E0041} Hola \u{2060}")['text']);
        $this->assertSame('', I::clean("\u{200B}\u{2060}\u{FEFF}")['text']);

        $muchos = implode('', array_map(static fn (int $i): string => mb_chr(0xE0041 + $i, 'UTF-8'), range(0, 25)));
        $r = I::clean("x{$muchos}y");
        $this->assertSame('xy', $r['text']);
        $this->assertSame(26, $r['removed']);
        $this->assertCount(I::CODEPOINTS_MAX, $r['codepoints']);
    }

    public function test_el_mensaje_de_plaintext_nombra_el_caracter_sin_repetir_el_texto(): void
    {
        $this->assertSame(PlainText::MESSAGE_INVALID, PlainText::message(I::INVALID_UTF8));
        $this->assertStringContainsString('(U+00AD)', PlainText::message('U+00AD', true));
        $this->assertStringContainsString('invisible o de control', PlainText::message('U+00AD', true));
        $this->assertStringContainsString('una sola línea', PlainText::message('U+000A', false));
        // En varias líneas un U+000A nunca llega aquí, pero si llegara no diría «una sola línea».
        $this->assertStringNotContainsString('una sola línea', PlainText::message('U+000A', true));
    }
}
