<?php

namespace Tests\Unit\Marketing\Ultron;

use PHPUnit\Framework\TestCase;

/**
 * «REGENERAR ANTES DE SUSTITUIR» · OLA 1 · LAS PIEZAS NUEVAS SON PURAS Y SU
 * PCRE ES EL DE PRODUCCIÓN (plan del arquitecto
 * `plan-arquitecto-a4-regenerar.md` §3 P-8 y §6 «Ola 1»).
 *
 *  1. Ninguna expresión regular nueva lleva `\p{…}` ni `\P{…}`: el PCRE de
 *     producción es el 10.39 y no las compila todas (clases explícitas, como
 *     `UltronRegisterDetector` y `UltronReplyFitter`). Se mira el código sin
 *     comentarios: un comentario puede nombrar `\p{L}` para decir que no se usa.
 *  2. Las cuatro clases nuevas son PURAS: ni base de datos, ni config, ni
 *     reloj, ni log, ni caché, ni red. Lo que necesitan se lo pasa quien llama.
 *  3. `peek()` no lee el estado del mundo: ni la fase, ni las transiciones, ni
 *     la versión del conocimiento.
 */
class UltronRegenerarPiezasPurasTest extends TestCase
{
    private const NUEVAS = [
        'app/Services/Marketing/Ultron/UltronPlansOverview.php',
        'app/Services/Marketing/Ultron/UltronBenefitAttribution.php',
        'app/Services/Marketing/Ultron/UltronRegenerationNotes.php',
        'app/Services/Marketing/Ultron/UltronDraftDefects.php',
    ];

    /** Lo que una pieza pura no puede usar: clases y métodos de consulta o escritura. */
    private const IMPUREZAS = [
        '::query(', 'Carbon', 'ChannelLog', 'curl_', 'file_get_contents', '->save(', '->update(', '->create(', '->get(', '->first(', 'random_', 'mt_rand',
    ];

    /** Las fachadas, por su nombre entero (`SalesAgentDecisionSchema::` no es `Schema::`). */
    private const FACHADAS = '/(?<!\w)(DB|Schema|Config|Log|Cache|Http|Session|Storage|Queue|Bus|Event|Date)::/';

    /** Y las funciones globales de Laravel o de PHP que leen el reloj, la config o el contenedor (no un método `->x(` ni `::x(`). */
    private const FUNCIONES_IMPURAS = '/(?<![>:\w$])(app|resolve|config|env|now|today|logger|cache|event|dispatch|time|date|microtime|rand)\s*\(/';

    private static function raiz(): string
    {
        return dirname(__DIR__, 4);
    }

    /** El código sin comentarios (las cadenas y las expresiones regulares cuentan). */
    private static function sinComentarios(string $fichero): string
    {
        $codigo = '';
        foreach (token_get_all((string) file_get_contents(self::raiz().'/'.$fichero)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codigo .= is_array($t) ? $t[1] : $t;
        }

        return $codigo;
    }

    public function test_ninguna_expresion_nueva_lleva_propiedades_unicode(): void
    {
        foreach ([...self::NUEVAS, 'app/Services/Marketing/Ultron/UltronDecideToken.php'] as $fichero) {
            $this->assertFileExists(self::raiz().'/'.$fichero);
            $codigo = self::sinComentarios($fichero);
            $this->assertDoesNotMatchRegularExpression('/\\\\[pP]\{/', $codigo, $fichero.' lleva \p{…} o \P{…}');
            $this->assertDoesNotMatchRegularExpression('/\\\\[pP][A-Z]/', $codigo, $fichero.' lleva \pL o similar');
            $this->assertDoesNotMatchRegularExpression('/\\\\X/', $codigo, $fichero.' lleva \X');
        }
        // El buscador funciona: lo encontraría.
        $this->assertMatchesRegularExpression('/\\\\[pP]\{/', "preg_match('/\\p{L}+/u', \$x)");
        $this->assertMatchesRegularExpression(self::FUNCIONES_IMPURAS, '$x = now();');
        $this->assertMatchesRegularExpression(self::FUNCIONES_IMPURAS, "return config('a');");
        $this->assertDoesNotMatchRegularExpression(self::FUNCIONES_IMPURAS, '$p->resolve($d); X::date($x); $fecha(1);');
        $this->assertMatchesRegularExpression(self::FACHADAS, 'Schema::hasTable($t);');
        $this->assertMatchesRegularExpression(self::FACHADAS, '\\Log::info(1);');
        $this->assertDoesNotMatchRegularExpression(self::FACHADAS, 'SalesAgentDecisionSchema::normalize($t); ChannelLogger::x();');
    }

    public function test_las_piezas_nuevas_son_puras(): void
    {
        foreach (self::NUEVAS as $fichero) {
            $codigo = self::sinComentarios($fichero);
            foreach (self::IMPUREZAS as $impureza) {
                $this->assertStringNotContainsString($impureza, $codigo, "{$fichero} usa {$impureza}");
            }
            $this->assertDoesNotMatchRegularExpression(self::FUNCIONES_IMPURAS, $codigo, "{$fichero} llama a una función global impura");
            $this->assertDoesNotMatchRegularExpression(self::FACHADAS, $codigo, "{$fichero} usa una fachada");
            $this->assertStringContainsString('final class ', $codigo);
        }
    }

    public function test_peek_no_mira_el_mundo(): void
    {
        $fuente = (string) file_get_contents(self::raiz().'/app/Services/Marketing/Ultron/UltronDecideToken.php');
        $ini = strpos($fuente, 'public function peek(');
        $this->assertNotFalse($ini, 'peek() existe');
        $fin = strpos($fuente, "\n    }\n", $ini);
        $cuerpo = substr($fuente, $ini, $fin - $ini);
        foreach (["['p']", "['t']", "['k']", 'fingerprint(', 'phase', 'knowledge'] as $mundo) {
            $this->assertStringNotContainsString($mundo, $cuerpo, "peek() no mira {$mundo}");
        }
        $this->assertStringContainsString("['i']", $cuerpo, 'devuelve el instante de emisión');
    }
}
