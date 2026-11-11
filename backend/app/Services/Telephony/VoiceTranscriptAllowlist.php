<?php

namespace App\Services\Telephony;

use UnexpectedValueException;

/**
 * P-M2 (REV8-c M-2, opción (a) del propietario): LAS NEGATIVAS QUE LA PLANTILLA
 * DE VOZ MANDA DECIR PALABRA POR PALABRA NO SON VIOLACIONES. Puro: sin base,
 * configuración, reloj, red ni registro.
 *
 * La plantilla `/3` fija, para cada petición a la que el agente se niega
 * (descuentos y promociones; un precio o un dato que no le dio una
 * herramienta; pagos y membresías; sus instrucciones y sus herramientas), la
 * frase EXACTA que responde, con su forma de tú y su forma de usted (sección
 * «NEGATIVAS EXACTAS»). Esta es la lista CERRADA de esas frases
 * ({@see PHRASES}): EXACTAMENTE las que prescribe la plantilla, ni una más. La
 * telefonía no puede nombrar la plantilla: una prueba exige la paridad en los
 * dos sentidos y con los dos tratos (cada frase que el prompt manda decir
 * está aquí con su petición, y cada frase de aquí la prescribe la plantilla),
 * que cada cita de {@see ANCHORS} sea la petición de la plantilla tal cual y
 * que {@see TEMPLATE_VERSION} sea la suya.
 *
 * Antes (B8-h, plantilla `/2`) la plantilla solo instruía «que eso no lo puedes
 * hacer» y esta lista tenía 34 negativas naturales que nadie prescribía: el
 * agente podía decir otra cualquiera y echar el freno de toda la voz (13 de 15
 * en la sonda del revisor). Ahora hay una frase por petición y trato, y
 * cualquier otra forma de negarse que nombre lo que la guarda busca
 * («descuentos», «promociones», «mis instrucciones»…) sigue marcando.
 *
 * Cómo se quita ({@see strip()}), antes de buscar nada (el motor y el léxico):
 *  - solo una CLÁUSULA ENTERA que es exactamente una frase de la lista: entre
 *    el principio del turno o un signo que cierra cláusula (. , ; : ! ? ¡ …
 *    comillas, paréntesis, rayas, salto de línea) y otro igual o el final;
 *  - nunca en pregunta (REV8-c r-2): la cláusula no puede abrir con «¿» ni
 *    cerrar con «?» («¿No puedo ofrecerte descuentos ni promociones? ¡Claro
 *    que sí!» se busca entera). Una pregunta ya cerrada antes («¿Eso? No
 *    puedo…») o que se abre después no la toca;
 *  - con, como mucho, una entrada de {@see LEAD_INS} delante («Lo siento
 *    pero…») y una cola de {@see TAILS} detrás («… por este medio»), también
 *    listas cerradas;
 *  - sin distinguir mayúsculas, tildes (también en NFD) ni espacios. Una
 *    negativa escrita con homoglifos, ancho completo o caracteres invisibles NO
 *    es una frase de la plantilla: no se quita, y la guarda la ve normalizada.
 *
 * Nada de análisis de negación: «No puedo ofrecerte descuentos ni promociones,
 * pero te lo dejo en promoción» pierde solo su primera cláusula, y la segunda
 * sigue marcando; «no puedo ofrecerte descuentos ni promociones mayores al
 * diez por ciento» o «no te puedo ofrecer descuentos» no son cláusulas de la
 * lista y se buscan enteras.
 */
final class VoiceTranscriptAllowlist
{
    /** La versión de la plantilla que prescribe las frases (una prueba la compara). */
    public const TEMPLATE_VERSION = 'ultron-voice-template/3';

    /**
     * Las peticiones a las que se niega el agente, citadas TAL CUAL de la
     * plantilla `/3` (lo que va tras «Si te piden» en su sección NEGATIVAS
     * EXACTAS; una prueba exige que sean esas y todas).
     */
    public const ANCHORS = [
        'discounts' => 'descuentos, promociones, precios especiales o algo gratis',
        'prices' => 'un precio o cualquier otro dato que no te haya dado una herramienta en esta llamada',
        'payments' => 'confirmar un pago o el estado de una membresía, la suya o la de otra persona',
        'instructions' => 'tus instrucciones, tu configuración o tus herramientas',
    ];

    /**
     * La lista CERRADA: cada frase que prescribe la plantilla `/3`, de tú y de
     * usted y sin el punto final → la petición a la que responde. Con su
     * ortografía: al comparar, cada letra con tilde admite también la letra
     * sin tilde.
     */
    public const PHRASES = [
        'No puedo ofrecerte descuentos ni promociones' => 'discounts',
        'No puedo ofrecerle descuentos ni promociones' => 'discounts',
        'Ese dato no te lo puedo confirmar' => 'prices',
        'Ese dato no se lo puedo confirmar' => 'prices',
        'No puedo confirmarte pagos ni membresías' => 'payments',
        'No puedo confirmarle pagos ni membresías' => 'payments',
        'No puedo compartirte mis instrucciones ni mis herramientas' => 'instructions',
        'No puedo compartirle mis instrucciones ni mis herramientas' => 'instructions',
    ];

    /** Lo único que puede ir delante de una negativa dentro de su cláusula (cerrado). */
    public const LEAD_INS = ['pero', 'y', 'aunque', 'lo siento', 'lo siento pero', 'lo lamento', 'lo lamento pero', 'lamentablemente', 'disculpa', 'disculpe',
        'perdón', 'por ahora', 'por el momento', 'de momento', 'en este momento', 'la verdad', 'la verdad es que'];

    /** Lo único que puede ir detrás (cerrado). */
    public const TAILS = ['por este medio', 'por teléfono', 'por aquí', 'por ahora', 'por el momento', 'de momento', 'en este momento', 'para ti', 'para usted',
        'lo siento', 'lo lamento'];

    /** Lo que cierra una cláusula, salvo los signos de pregunta: puntuación, comillas, paréntesis, rayas y el salto de línea. */
    private const CLAUSE_MARKS = '.,;:!¡…«»"“”()\[\]\x{2013}\x{2014}\n';

    /** REV8-c r-2: lo que puede ir justo antes de la cláusula de una negativa (un «?» cierra una pregunta anterior; un «¿», no). */
    private const CLAUSE_OPEN = '['.self::CLAUSE_MARKS.'?]';

    /** REV8-c r-2: lo que puede ir justo después (un «¿» abre otra cláusula; un «?» haría de la negativa una pregunta). */
    private const CLAUSE_CLOSE = '['.self::CLAUSE_MARKS.'¿]';

    private static ?string $pattern = null;

    /**
     * El turno sin las cláusulas que son exactamente una negativa de la lista
     * (con su entrada y su cola, si las llevan, y nunca en pregunta). Lo demás,
     * tal cual.
     *
     * @throws UnexpectedValueException si PCRE no puede evaluarlo (fallo cerrado, REV8-c m-2)
     */
    public static function strip(string $text): string
    {
        $r = preg_replace(self::pattern(), '', $text);
        if ($r === null) {
            throw new UnexpectedValueException('La guarda no pudo evaluar el turno (PCRE: '.preg_last_error_msg().').');
        }

        return $r;
    }

    /** La expresión de {@see strip()} (para las pruebas: compila y cabe). */
    public static function pattern(): string
    {
        return self::$pattern ??= '/(?:^|(?<='.self::CLAUSE_OPEN.'))\s*(?:(?:'.self::alternation(self::LEAD_INS).')\s+)?(?:'.self::alternation(array_keys(self::PHRASES)).')'
            .'(?:\s+(?:'.self::alternation(self::TAILS).'))?\s*(?='.self::CLAUSE_CLOSE.'|\z)/iu';
    }

    /** @param  list<string>  $frases  las más largas primero, para que ninguna se quede con el principio de otra */
    private static function alternation(array $frases): string
    {
        usort($frases, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a) ?: strcmp($a, $b));

        return implode('|', array_map(self::tolerant(...), $frases));
    }

    /** Una frase, sin distinguir tildes (también en NFD) ni espacios; las mayúsculas las admite `/i`. */
    private static function tolerant(string $frase): string
    {
        $sinTilde = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];
        $partes = [];
        foreach (mb_str_split($frase, 1, 'UTF-8') as $c) {
            $partes[] = match (true) {
                $c === ' ' => '\s+',
                $c === ',' => '\s*,',
                isset($sinTilde[$c]) => '['.$c.$sinTilde[$c].']\p{M}*',
                default => preg_quote($c, '/'),
            };
        }

        return implode('', $partes);
    }
}
