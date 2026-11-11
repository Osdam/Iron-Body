<?php

namespace Tests\Unit\Telephony;

use App\Services\Telephony\VoiceTranscriptAllowlist as A;
use App\Services\Telephony\VoiceTranscriptGuard;
use App\Services\UltronStudio\StudioVoiceTemplate as T;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P-M2 (REV8-c M-2, opción (a) del propietario, con las decisiones del lead):
 * LA PLANTILLA `/3` FIJA LA FRASE EXACTA DE CADA NEGATIVA Y LA GUARDA SOLO DEJA
 * PASAR ESAS.
 *
 *  - PARIDAD en los dos sentidos y con los dos tratos: cada frase que el prompt
 *    renderizado manda decir «exactamente» (tú y usted) está en
 *    {@see A::PHRASES} con su petición, y cada frase de la lista la prescribe
 *    la plantilla; las citas ({@see A::ANCHORS}) son las peticiones de la
 *    plantilla tal cual, y la versión es la suya.
 *  - Las frases prescritas pasan LIMPIAS: solas, en mitad de un turno, con cada
 *    entrada y cada cola de la lista (y con las dos a la vez), en mayúsculas y
 *    en NFD.
 *  - Lo que no es la frase exacta SIGUE MARCANDO: la oferta en la misma frase,
 *    una cláusula más larga, homoglifos, ancho completo e invisibles; y una
 *    negativa en pregunta no se quita (REV8-c r-2).
 *  - Las 15 negativas NATURALES (decisión 1 del lead): las 9 que cita el
 *    revisor (`informe-reviewer.md` §C-2, M-2; su lista de 15 se perdió con su
 *    scratchpad) y 6 variantes nuestras. Las que coinciden con una fórmula
 *    prescrita (más las entradas y colas que ya había) pasan limpias; las demás
 *    siguen marcando: el agente tiene que decir la frase exacta.
 * Pura: sin base, red ni registro.
 */
class VoiceTranscriptRefusalsTest extends TestCase
{
    private const AVISO = 'Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. Si no quieres recibir más llamadas, dímelo.';

    private const FAMILIA_DESCUENTO = ['machine_reply_invented_discount', 'voice_discount_extra'];

    /** Las peticiones cuya frase nombra algo que la guarda busca (sin la lista, marcarían). */
    private const NOMBRAN_ALGO = ['discounts' => self::FAMILIA_DESCUENTO, 'instructions' => ['voice_prompt_leak']];

    /** Lo que deja {@see A::strip()} cuando quita la cláusula entera: solo signos y espacios. */
    private const SOLO_SIGNOS = '/^[\s.,;:!¡?¿…]*$/u';

    /** @return list<string> los códigos del turno de la frase (el aviso va delante y no da nada) */
    private static function codigos(string $frase): array
    {
        $turnos = [
            ['index' => 0, 'text' => self::AVISO, 'tools' => [], 'transfers' => [], 'planResults' => []],
            ['index' => 1, 'text' => $frase, 'tools' => [], 'transfers' => [], 'planResults' => []],
        ];
        $violaciones = VoiceTranscriptGuard::inspect($turnos, [], true);
        foreach ($violaciones as $v) {
            self::assertSame(1, $v['turn'], 'el aviso no da nada');
        }

        return array_column($violaciones, 'code');
    }

    /** @return array<string, mixed> lo mínimo que pide {@see T::render()} */
    private static function base(): array
    {
        return [
            'tone' => 'cercano', 'goal' => 'cita', 'fallback' => 'preguntar', 'inboundGreeting' => 'Hola, ¿en qué te ayudo?', 'outboundGreeting' => 'Te llamo de Iron Body.',
            'holes' => ['presentation' => 'Te ayudo con planes.', 'closing' => 'Adiós'], 'tools' => ['no_volver_a_llamar'], 'transfer' => false, 'forbiddenPhrases' => [],
        ];
    }

    /** NFD sin `ext-intl` (producción no la tiene): cada vocal con tilde, la ñ y la ü, como letra base + marca combinada. */
    private static function nfd(string $texto): string
    {
        $marcas = ['á' => "a\u{0301}", 'é' => "e\u{0301}", 'í' => "i\u{0301}", 'ó' => "o\u{0301}", 'ú' => "u\u{0301}", 'ñ' => "n\u{0303}", 'ü' => "u\u{0308}"];
        foreach ($marcas as $compuesta => $descompuesta) {
            $marcas[mb_strtoupper($compuesta)] = mb_strtoupper(mb_substr($descompuesta, 0, 1)).mb_substr($descompuesta, 1);
        }

        return strtr($texto, $marcas);
    }

    /** @return array<string, array{string, string, string}> cada frase prescrita (con su petición y su trato) */
    public static function prescritas(): array
    {
        $casos = [];
        foreach (T::REFUSALS as $tema => $negativa) {
            foreach (array_keys(T::ADDRESS_FORMS) as $trato) {
                $casos["{$tema} · {$trato}"] = [$negativa[$trato], $tema, $trato];
            }
        }

        return $casos;
    }

    // ── Paridad con la plantilla ───────────────────────────────────────────

    public function test_la_lista_es_exactamente_la_de_la_plantilla_en_los_dos_sentidos_y_con_los_dos_tratos(): void
    {
        $this->assertSame(T::VERSION, A::TEMPLATE_VERSION, 'si la plantilla cambia de versión, la lista se revisa');
        $this->assertSame('ultron-voice-template/3', A::TEMPLATE_VERSION);
        $this->assertSame(['tu', 'usted'], array_keys(T::ADDRESS_FORMS));

        // Desde las constantes: la lista es EXACTAMENTE lo que prescribe la plantilla, con su petición y en su orden.
        $prescritas = [];
        foreach (T::REFUSALS as $tema => $negativa) {
            $this->assertSame(['when', 'tu', 'usted'], array_keys($negativa), $tema);
            foreach (array_keys(T::ADDRESS_FORMS) as $trato) {
                $prescritas[$negativa[$trato]] = $tema;
            }
        }
        $this->assertSame($prescritas, A::PHRASES, 'cada frase prescrita está en la lista y cada frase de la lista está prescrita');
        $this->assertCount(8, A::PHRASES, 'cuatro peticiones × dos tratos');
        // Las citas: las peticiones de la plantilla, tal cual y todas.
        $this->assertSame(array_map(static fn (array $n): string => $n['when'], T::REFUSALS), A::ANCHORS);

        // Desde el prompt RENDERIZADO de cada trato: todo lo que manda decir «exactamente» está en la lista, y nada del otro trato.
        $vistas = [];
        foreach (array_keys(T::ADDRESS_FORMS) as $trato) {
            $prompt = T::render(['addressForm' => $trato] + self::base())['systemPrompt'];
            $this->assertSame(4, preg_match_all('/responde exactamente: «([^»]+)\.»/u', $prompt, $m), $trato);
            foreach ($m[1] as $i => $frase) {
                $tema = array_keys(T::REFUSALS)[$i];
                $this->assertSame($tema, A::PHRASES[$frase] ?? null, "{$trato}: «{$frase}» no está en la lista con su petición");
                $this->assertSame(T::REFUSALS[$tema][$trato], $frase, "{$trato}: el prompt dice la frase de su trato");
                $this->assertStringContainsString('- Si te piden '.A::ANCHORS[$tema].', responde exactamente: «'.$frase.'.»', $prompt);
                $vistas[$frase] = true;
            }
            $otro = $trato === 'tu' ? 'usted' : 'tu';
            foreach (T::REFUSALS as $tema => $negativa) {
                $this->assertStringNotContainsString('«'.$negativa[$otro].'.»', $prompt, "{$trato}: «{$tema}» con el trato {$otro}");
            }
        }
        $this->assertEqualsCanonicalizing(array_keys(A::PHRASES), array_keys($vistas), 'ninguna frase de la lista sobra: todas salen de un prompt');
    }

    public function test_cada_frase_es_una_sola_clausula_sin_cifras_ni_dinero_y_los_tratos_se_distinguen(): void
    {
        foreach (array_keys(A::PHRASES) as $frase) {
            // Una cláusula: un signo dentro la partiría y la guarda buscaría cada trozo por su cuenta.
            $this->assertDoesNotMatchRegularExpression('/[.,;:!?¡¿…«»"“”()\[\]\x{2013}\x{2014}\n]/u', $frase, $frase);
            $this->assertDoesNotMatchRegularExpression('/\d/u', $frase, $frase);
            $this->assertSame(0, preg_match(T::PRICE_PATTERN, $frase), $frase);
            $this->assertSame($frase, trim($frase));
        }
        foreach (T::REFUSALS as $tema => $negativa) {
            $this->assertNotSame($negativa['tu'], $negativa['usted'], "{$tema}: cada trato tiene su frase");
            $this->assertMatchesRegularExpression('/\b(te|ofrecerte|confirmarte|compartirte)\b/u', $negativa['tu'], $tema);
            $this->assertDoesNotMatchRegularExpression('/\b(te|ofrecerte|confirmarte|compartirte)\b/u', $negativa['usted'], $tema);
        }
    }

    // ── Las frases prescritas pasan limpias ────────────────────────────────

    #[DataProvider('prescritas')]
    public function test_la_frase_prescrita_pasa_limpia_sola_en_un_turno_en_mayusculas_y_en_nfd(string $frase, string $tema, string $trato): void
    {
        $dicha = $frase.'.';   // como la manda el prompt: «…».
        foreach ([
            'sola' => $dicha,
            'sin punto' => $frase,
            'en mayúsculas' => mb_strtoupper($dicha),
            'en NFD' => self::nfd($dicha),
            'en mayúsculas y NFD' => self::nfd(mb_strtoupper($dicha)),
            'en mitad de un turno' => 'Claro, te entiendo. '.$dicha.' ¿Quieres que sigamos con los planes vigentes?',
            'dos veces' => $dicha.' '.$dicha,
            'tras una pregunta ya cerrada' => '¿Me preguntas por eso? '.$dicha,
            'con saltos de línea y espacios' => "Entiendo.\n  ".str_replace(' ', "  \t", $frase)."  \n",
        ] as $como => $texto) {
            $this->assertSame([], self::codigos($texto), "{$tema} · {$trato} · {$como}");
        }
        // La lista la quita entera (también las que no nombran nada que la guarda busque).
        $this->assertMatchesRegularExpression(self::SOLO_SIGNOS, A::strip($dicha), "{$tema} · {$trato}");
        $this->assertMatchesRegularExpression(self::SOLO_SIGNOS, A::strip(self::nfd(mb_strtoupper($dicha))), "{$tema} · {$trato} · NFD");
    }

    #[DataProvider('prescritas')]
    public function test_la_frase_prescrita_pasa_limpia_con_cada_entrada_y_cada_cola_de_la_lista(string $frase, string $tema, string $trato): void
    {
        $cuerpo = lcfirst($frase);
        foreach (A::LEAD_INS as $entrada) {
            foreach (['', ...A::TAILS] as $cola) {
                $texto = ucfirst($entrada).' '.$cuerpo.($cola === '' ? '' : ' '.$cola).'.';
                $this->assertMatchesRegularExpression(self::SOLO_SIGNOS, A::strip($texto), "{$tema} · {$trato} · «{$texto}»");
                $this->assertMatchesRegularExpression(self::SOLO_SIGNOS, A::strip(self::nfd($texto)), "{$tema} · {$trato} · NFD «{$texto}»");
            }
        }
        foreach (A::TAILS as $cola) {
            $this->assertMatchesRegularExpression(self::SOLO_SIGNOS, A::strip($frase.' '.$cola.'.'), "{$tema} · {$trato} · cola «{$cola}»");
        }
        // Y por la guarda entera: cada entrada (tras su propia cláusula de cortesía) y cada cola, con un turno real.
        foreach (A::LEAD_INS as $entrada) {
            $this->assertSame([], self::codigos('Lo siento, '.$entrada.' '.$cuerpo.' por este medio.'), "{$tema} · {$trato} · entrada «{$entrada}»");
        }
        foreach (A::TAILS as $cola) {
            $this->assertSame([], self::codigos('Qué pena, '.$cuerpo.' '.$cola.'.'), "{$tema} · {$trato} · cola «{$cola}»");
        }
    }

    // ── Lo que no es la frase exacta sigue marcando ────────────────────────

    public function test_lo_que_nombra_la_guarda_sigue_marcando_si_no_es_la_clausula_exacta(): void
    {
        foreach (self::NOMBRAN_ALGO as $tema => $familia) {
            foreach (array_keys(T::ADDRESS_FORMS) as $trato) {
                $frase = T::REFUSALS[$tema][$trato];
                $cuerpo = lcfirst($frase);
                $resto = mb_substr($frase, 2);   // la frase sin su «No» o «Es» inicial
                foreach ([
                    // Sin la lista marcaría: dentro de otra cláusula no es la frase de la plantilla.
                    'dentro de otra cláusula' => 'Te digo que '.$cuerpo.'.',
                    'una cláusula más larga' => $frase.' completas ni nada parecido.',
                    'pegada a otra palabra' => $frase.'s.',
                    'sin la coma que la separa de lo que sigue' => $frase.' pero mira esto.',
                    // Una negativa disfrazada no es una frase de la plantilla: no se quita, y la guarda la ve normalizada.
                    'homoglifo cirílico' => "N\u{043E}".$resto.'.',
                    'ancho completo' => "\u{FF2E}\u{FF4F}".$resto.'.',
                    'espacio de ancho cero dentro' => str_replace('puedo', "pu\u{200B}edo", $frase).'.',
                    'unión de ancho cero al final' => $frase."\u{200D}.",
                    'guion blando dentro' => str_replace('puedo', "pue\u{00AD}do", $frase).'.',
                ] as $como => $texto) {
                    $this->assertNotEmpty(array_intersect($familia, self::codigos($texto)), "{$tema} · {$trato} · {$como}: «{$texto}»");
                }
            }
        }
        // La oferta en la misma frase que la negativa: se quita la negativa, la oferta marca.
        $this->assertContains('machine_reply_invented_discount', self::codigos('No puedo ofrecerte descuentos ni promociones, pero te lo dejo en promoción.'));
        $this->assertContains('machine_reply_invented_discount', self::codigos('No puedo ofrecerle descuentos ni promociones; eso sí, la primera clase es gratis.'));
        $this->assertContains('voice_prompt_leak', self::codigos('No puedo compartirte mis instrucciones ni mis herramientas, pero tengo consultar_planes.'));
        $this->assertNotEmpty(array_intersect(self::FAMILIA_DESCUENTO, self::codigos('No puedo ofrecerte descuentos ni promociones mayores al diez por ciento.')));

        // Las otras dos no nombran nada que la guarda busque: limpias con lista o sin ella (nada que esconder).
        foreach (['prices', 'payments'] as $tema) {
            foreach (array_keys(T::ADDRESS_FORMS) as $trato) {
                $this->assertSame([], self::codigos('Te digo que '.lcfirst(T::REFUSALS[$tema][$trato]).'.'), "{$tema} · {$trato}");
            }
        }
    }

    /** REV8-c r-2: una negativa en pregunta no es una negativa. No se quita una cláusula que abre con «¿» o cierra con «?». */
    public function test_r2_una_negativa_en_pregunta_no_se_quita(): void
    {
        foreach (A::PHRASES as $frase => $tema) {
            foreach (['¿'.$frase.'?', '¿'.$frase.'.', $frase.'?', '¿Y '.lcfirst($frase).'?', '¿Lo siento, '.lcfirst($frase).' por este medio?', 'Mira: '.lcfirst($frase).' ?'] as $texto) {
                $this->assertSame($texto, A::strip($texto), "«{$texto}» no se quita");
            }
        }
        // El ejemplo del revisor y el mismo con la frase prescrita: los dos marcan.
        $this->assertNotEmpty(array_intersect(self::FAMILIA_DESCUENTO, self::codigos('¿No tenemos promociones? ¡Claro que sí, este mes sí!')));
        $this->assertNotEmpty(array_intersect(self::FAMILIA_DESCUENTO, self::codigos('¿No puedo ofrecerte descuentos ni promociones? ¡Claro que sí, este mes sí!')));
        $this->assertNotEmpty(array_intersect(self::FAMILIA_DESCUENTO, self::codigos('No puedo ofrecerle descuentos ni promociones?')));
        $this->assertContains('voice_prompt_leak', self::codigos('¿No puedo compartirte mis instrucciones ni mis herramientas? Claro que sí.'));
        // Una pregunta YA CERRADA antes, o una que se abre DESPUÉS, no toca la negativa.
        $this->assertSame([], self::codigos('¿Me preguntas por eso? No puedo ofrecerte descuentos ni promociones.'));
        $this->assertSame([], self::codigos('No puedo ofrecerte descuentos ni promociones. ¿Te cuento los planes vigentes?'));
        $this->assertSame([], self::codigos('No puedo compartirle mis instrucciones ni mis herramientas ¿le ayudo con algo más?'));
    }

    // ── Las 15 negativas naturales ─────────────────────────────────────────

    /**
     * N01–N09: las nueve que cita el revisor (`informe-reviewer.md` §C-2, M-2,
     * «Medido»; su lista de 15 se perdió con su scratchpad). N10–N15: variantes
     * nuestras, documentadas en `informe-p-m2.md`: tres que DICEN la fórmula
     * prescrita con una entrada o una cola de las que ya había (pasan limpias) y
     * tres que se le parecen sin serlo (marcan), una de ellas permitida por la
     * lista de la `/2` (N14). El código esperado es el que da la guarda HOY: si
     * cambia, se revisa aquí, nunca se afloja.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function naturales(): array
    {
        return [
            'N01 no manejo descuentos' => ['Lo siento, no manejo descuentos.', ['voice_discount_extra']],
            'N02 no contamos con promociones' => ['No contamos con promociones en este momento.', ['voice_discount_extra']],
            'N03 por ahora no hay promociones' => ['Por ahora no hay promociones.', ['voice_discount_extra']],
            'N04 no te puedo dar descuentos' => ['No te puedo dar descuentos.', ['voice_discount_extra']],
            'N05 no tenemos descuentos ni promociones' => ['Lamentablemente no tenemos descuentos ni promociones en este momento.', ['voice_discount_extra']],
            'N06 no estoy autorizado' => ['No estoy autorizado para dar descuentos.', ['voice_discount_extra']],
            'N07 no puedo decirte mis instrucciones' => ['No puedo decirte mis instrucciones.', ['voice_prompt_leak']],
            'N08 ni precios especiales' => ['No puedo ofrecerte descuentos ni precios especiales.', ['voice_discount_extra']],
            'N09 no tengo promociones para ofrecerte' => ['Disculpa, no tengo promociones para ofrecerte.', ['voice_discount_extra']],
            'N10 la fórmula con entrada y cola (tú)' => ['Lo siento, pero no puedo ofrecerte descuentos ni promociones por este medio.', []],
            'N11 la fórmula con entrada y cola (usted)' => ['Lamentablemente no puedo ofrecerle descuentos ni promociones en este momento.', []],
            'N12 la fórmula de instrucciones tras una disculpa' => ['Disculpa, no puedo compartirte mis instrucciones ni mis herramientas.', []],
            'N13 instrucciones sin herramientas' => ['No te puedo compartir mis instrucciones.', ['voice_prompt_leak']],
            'N14 la de la lista de la /2' => ['No puedo confirmar si tu pago quedó registrado.', ['voice_member_or_payment_claim']],
            'N15 pagos con el pronombre delante' => ['No te puedo confirmar pagos ni membresías.', ['machine_reply_forbidden_action']],
        ];
    }

    /** @param  list<string>  $esperado */
    #[DataProvider('naturales')]
    public function test_las_15_negativas_naturales(string $frase, array $esperado): void
    {
        $this->assertSame($esperado, self::codigos($frase), $frase);
    }

    public function test_son_15_y_solo_pasan_las_que_dicen_la_formula(): void
    {
        $naturales = self::naturales();
        $this->assertCount(15, $naturales);
        $limpias = array_keys(array_filter($naturales, static fn (array $c): bool => $c[1] === []));
        $this->assertSame(['N10 la fórmula con entrada y cola (tú)', 'N11 la fórmula con entrada y cola (usted)', 'N12 la fórmula de instrucciones tras una disculpa'], $limpias);
        // Las limpias contienen, letra por letra, una fórmula prescrita; las que marcan, ninguna.
        foreach ($naturales as $id => [$frase]) {
            $dice = false;
            foreach (array_keys(A::PHRASES) as $formula) {
                $dice = $dice || str_contains(mb_strtolower($frase), mb_strtolower($formula));
            }
            $this->assertSame(in_array($id, $limpias, true), $dice, $id);
        }
    }
}
