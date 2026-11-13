<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;

/**
 * EL TOPE DE LONGITUD DE LA VERSIÓN PUBLICADA, MEDIDO Y CUMPLIDO EN LARAVEL
 * (apartado 4 del cierre V2, bloqueantes H4-1 y H4-3; plan del arquitecto §3.1 y §3.2
 * con las decisiones D-3, D-4 y D-5 del lead).
 *
 * Existe por una prueba real: con una versión publicada de tope 200 salió un
 * mensaje de 300 caracteres —el texto de respaldo del horario, que es de
 * Laravel— y el redactor se pasó del tope en 4 de 5 borradores. El compilador
 * le PIDE el tope al modelo; nadie lo medía.
 *
 * Qué hace: medir, partir en frases enteras y soltar frases enteras por un
 * orden fijado hasta que el texto quepa. NUNCA corta una frase por la mitad y
 * NUNCA reescribe: lo que sale es un recorte de un texto que ya existía. Si no
 * hay forma de que quepa, devuelve null y quien llama decide el siguiente
 * escalón (otro texto, nunca el silencio).
 *
 * PURA: sin base de datos, sin `config()`, sin reloj y sin log. Quien llama
 * decide si hay tope (solo con la versión del Studio aplicada y `sp.n` en el
 * token firmado) y deja la traza.
 *
 * Sin `\p{…}` en las expresiones: el PCRE de producción (10.39) no las compila
 * todas. Clases explícitas.
 */
final class UltronReplyFitter
{
    /** El rango que admite el compilador para `persona.maxChars`: lo que no esté aquí no es un tope firmado. */
    public const MIN_CAP = UltronPromptCompiler::MAX_CHARS_MIN;

    public const MAX_CAP = UltronPromptCompiler::MAX_CHARS_MAX;

    /** Las direcciones que escribe Laravel (los enlaces de la app, el checkout): no cuentan (D-3). */
    private const URL = '#https?://\S+#u';

    /**
     * Frontera de frase: tras `.`, `!` o `?`, espacio y una mayúscula, `¿` o
     * `¡`. Corta tras «p. m.» cuando sigue «Sabados…» y no dentro de «5:00 a.
     * m. a 10:00», porque ahí sigue una minúscula. El separador se captura para
     * volver a unir el texto tal cual (un salto de línea antes de los enlaces
     * sigue siendo un salto de línea).
     */
    private const FRONTERA = '/(?<=[.!?])(\s+)(?=[¿¡A-ZÁÉÍÓÚÑ])/u';

    /**
     * El punto de una abreviatura de dirección o de tratamiento NO termina la
     * frase (ajuste 1 del lead): «Estamos en la Av. La Toma…» se partía tras
     * «Av.» y el recorte del borrador podía dejar «Estamos en la Av.» como
     * mensaje. Se mira la palabra que cierra el trozo, con o sin mayúscula.
     * «etc.» no está: sí termina frase; «a. m.» y «p. m.» tampoco: el horario
     * se parte justo ahí. «No» tampoco (decisión del lead): la negación al
     * final de frase («creo que no. Mañana…») es mucho más frecuente que
     * «No.» seguido de mayúscula, y «No. 12» ya no era frontera (sigue una cifra).
     */
    private const ABREVIATURA = '/(?:^|[^a-záéíóúñ])(av|avda|cra|kra|cr|cl|cll|dg|diag|tv|transv|nro|num|núm|sr|sra|srta|dr|dra|ing|lic|ed|edif|apto|urb|km|tel|cel|ext|int|mz|of|loc)\.$/iu';

    /** Una frase con una cifra de dinero: con `$` o con «COP» (D-4). */
    private const PRECIO = '/\$\s?\d|\d\s?cop\b/iu';

    /** Un grupo de días de la ficha del horario, sobre el texto normalizado. */
    private const DIA = '/\b(lunes|martes|miercoles|jueves|viernes|sabados?|domingos?|festivos?)\b/u';

    private const LABORABLE = '/\b(lunes|martes|miercoles|jueves|viernes)\b/u';

    /** Lo que la persona pudo preguntar, sobre su texto normalizado → el patrón del grupo que lo contesta. */
    private const PEDIDOS = [
        '/\b(entre\s+semana|lunes|martes|miercoles|jueves|viernes)\b/u' => self::LABORABLE,
        '/\bsabados?\b/u' => '/\bsabados?\b/u',
        '/\bdomingos?\b/u' => '/\bdomingos?\b/u',
        '/\bfestivos?\b/u' => '/\bfestivos?\b/u',
    ];

    // ── Medir ───────────────────────────────────────────────────────────────

    /**
     * La única medida (D-3): caracteres del texto que se envía SIN las URL.
     * Los enlaces de la app son unos 190 caracteres de direcciones que escribe
     * Laravel; contarlos haría imposible mandar la app con cualquier tope.
     * Todo lo demás cuenta, también «Android: · iPhone: · Web:».
     */
    public static function measure(string $text): int
    {
        return mb_strlen((string) preg_replace(self::URL, '', $text));
    }

    /** Tolerancia 0: cabe si mide el tope o menos. */
    public static function fits(string $text, int $cap): bool
    {
        return self::measure($text) <= $cap;
    }

    /** `sp.n` del token: un entero dentro del rango del compilador, o null (no se mide). */
    public static function cap(mixed $n): ?int
    {
        return is_int($n) && $n >= self::MIN_CAP && $n <= self::MAX_CAP ? $n : null;
    }

    /**
     * El texto en frases enteras, cada una con el separador que la seguía.
     *
     * @return array<int, array{0:string, 1:string}>
     */
    public static function sentences(string $text): array
    {
        $trozos = preg_split(self::FRONTERA, trim($text), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $frases = [];
        $pendiente = null;
        for ($i = 0; $i < count($trozos); $i += 2) {
            if (trim($trozos[$i]) === '') {
                continue;
            }
            $frase = [$trozos[$i], $trozos[$i + 1] ?? ''];
            // Lo que venía tras una abreviatura se pega al trozo anterior con su mismo separador.
            if ($pendiente !== null) {
                $frase = [$pendiente[0].$pendiente[1].$frase[0], $frase[1]];
                $pendiente = null;
            }
            if ($frase[1] !== '' && preg_match(self::ABREVIATURA, $frase[0]) === 1) {
                $pendiente = $frase;

                continue;
            }
            $frases[] = $frase;
        }
        if ($pendiente !== null) {
            $frases[] = [$pendiente[0], ''];
        }

        return $frases;
    }

    // ── Textos de Laravel: la regla general (D-5) ───────────────────────────

    /**
     * Un texto curado ajustado al tope. Obligatorias la primera frase y la
     * pregunta final (si la hay); se sueltan las del medio en orden, desde la
     * segunda; después, la pregunta. Null si ni así cabe.
     */
    public static function curated(string $text, int $cap): ?string
    {
        if (self::fits($text, $cap)) {
            return $text;
        }
        $frases = self::sentences($text);

        return self::dropInOrder($frases, self::generalOrder(array_column($frases, 0)), $cap);
    }

    /**
     * La ficha del gimnasio por piezas (`respuestaConHechos()`): una pieza
     * puede tener varias frases y entra o sale entera.
     *
     * LLENADO EN ORDEN, no la regla general (ajuste 2 del lead): con la regla
     * general se soltaba primero la segunda pieza —la identidad— y a tope 200
     * podía no quedar ningún hecho. Obligatorias la apertura y la pregunta
     * final; después entran las del medio en su orden mientras quepan, y la
     * que no cabe se salta y se prueba la siguiente. Null si ni la apertura
     * con la pregunta caben.
     *
     * @param  string[]  $pieces
     */
    public static function pieces(array $pieces, int $cap): ?string
    {
        $piezas = array_values(array_filter(array_map('trim', $pieces), fn (string $p) => $p !== ''));
        $n = count($piezas);
        if ($n === 0) {
            return null;
        }
        $conPregunta = $n >= 2 && str_ends_with($piezas[$n - 1], '?');
        $cola = $conPregunta ? [$piezas[$n - 1]] : [];
        $dentro = [$piezas[0]];
        if (! self::fits(implode(' ', [...$dentro, ...$cola]), $cap)) {
            return null;
        }
        foreach (array_slice($piezas, 1, $conPregunta ? $n - 2 : $n - 1) as $pieza) {
            if (self::fits(implode(' ', [...$dentro, $pieza, ...$cola]), $cap)) {
                $dentro[] = $pieza;
            }
        }

        return implode(' ', [...$dentro, ...$cola]);
    }

    /**
     * El horario: LOS DATOS MANDAN SOBRE LA PREGUNTA (D-5, difiere del plan).
     *
     * Obligatorios la primera frase («Claro.») y el grupo de días que se
     * preguntó («entre semana» o un día laborable → el de lunes a viernes;
     * «sábado», «domingo», «festivo»; si no nombra ninguno, el primero). Se
     * suelta en este orden: la aclaración («Este es el horario de apertura…»),
     * la pregunta final y los grupos no preguntados, del último al primero.
     * Una ficha sin días reconocibles sigue la regla general.
     */
    public static function schedule(string $text, int $cap, string $inbound): ?string
    {
        if (self::fits($text, $cap)) {
            return $text;
        }
        $frases = self::sentences($text);
        $n = count($frases);
        $dias = [];
        $aclaraciones = [];
        $pregunta = null;
        for ($i = 1; $i < $n; $i++) {
            $f = SalesAgentDecisionSchema::normalize($frases[$i][0]);
            if ($i === $n - 1 && str_ends_with(trim($frases[$i][0]), '?')) {
                $pregunta = $i;
            } elseif (preg_match(self::DIA, $f) === 1) {
                $dias[] = $i;
            } else {
                $aclaraciones[] = $i;
            }
        }
        if ($dias === []) {
            return self::curated($text, $cap);
        }

        $entrante = SalesAgentDecisionSchema::normalize($inbound);
        $pedidos = [];
        foreach (self::PEDIDOS as $pregunto => $grupo) {
            if (preg_match($pregunto, $entrante) !== 1) {
                continue;
            }
            foreach ($dias as $i) {
                if (preg_match($grupo, SalesAgentDecisionSchema::normalize($frases[$i][0])) === 1) {
                    $pedidos[] = $i;
                }
            }
        }
        $pedidos = $pedidos === [] ? [$dias[0]] : array_values(array_unique($pedidos));

        $orden = [...$aclaraciones, ...($pregunta === null ? [] : [$pregunta]), ...array_reverse(array_values(array_diff($dias, $pedidos)))];

        return self::dropInOrder($frases, $orden, $cap);
    }

    // ── El borrador del modelo (D-4) ────────────────────────────────────────

    /**
     * El borrador aprobado, recortado: se sueltan frases enteras DESDE EL
     * FINAL hacia atrás hasta que quepa. Nunca la primera, ni una que lleve
     * una URL (los enlaces que pegó Laravel), ni una que lleve un precio: esas
     * se saltan y se sigue con la anterior. Null si ni así cabe: entonces el
     * borrador se descarta (`policy_length_exceeded`).
     *
     * @return array{text:string, dropped:int}|null
     */
    public static function draft(string $text, int $cap): ?array
    {
        if (self::fits($text, $cap)) {
            return ['text' => $text, 'dropped' => 0];
        }
        $frases = self::sentences($text);
        $quedan = array_fill(0, count($frases), true);
        $soltadas = 0;
        for ($i = count($frases) - 1; $i >= 1; $i--) {
            if (self::protegida($frases[$i][0])) {
                continue;
            }
            $quedan[$i] = false;
            $soltadas++;
            $recorte = self::join($frases, $quedan);
            if (self::fits($recorte, $cap)) {
                return ['text' => $recorte, 'dropped' => $soltadas];
            }
        }

        return null;
    }

    /**
     * Una frase que Laravel añade al final (13.ter, la que retoma la visita;
     * 13.quater, el precio verificado) sin romper el tope.
     *
     * Con prioridad (el precio): se sueltan frases del cuerpo con la regla del
     * borrador para hacerle sitio y, si ni así, sale ella sola. Sin prioridad
     * (la visita): solo si cabe; null = no se añade.
     */
    public static function append(string $body, string $tail, int $cap, bool $priority): ?string
    {
        $entero = rtrim($body).' '.$tail;
        if (self::fits($entero, $cap)) {
            return $entero;
        }
        if (! $priority) {
            return null;
        }
        $sitio = $cap - self::measure(' '.$tail);
        $recorte = $sitio > 0 ? self::draft($body, $sitio) : null;

        return $recorte === null ? $tail : rtrim($recorte['text']).' '.$tail;
    }

    // ── El respaldo de precios (D-6) ────────────────────────────────────────

    /**
     * Qué partes del respaldo de precios caben. Obligatorios el encabezado y
     * el primer plan con su precio; se suelta en este orden: «También hay…»,
     * el plan 3, la pregunta, el plan 2. Sin tope, todo. Null si ni el
     * encabezado con el primer plan caben.
     *
     * @param  string[]  $items  «Nombre, $precio» (uno a tres)
     * @return array{items:int, also:bool, question:bool}|null
     */
    public static function pricingLayout(string $header, array $items, ?string $also, string $question, ?int $cap): ?array
    {
        $n = count($items);
        if ($n === 0) {
            return null;
        }
        $candidatos = [
            ['items' => $n, 'also' => $also !== null, 'question' => true],
            ['items' => $n, 'also' => false, 'question' => true],
            ['items' => min($n, 2), 'also' => false, 'question' => true],
            ['items' => min($n, 2), 'also' => false, 'question' => false],
            ['items' => 1, 'also' => false, 'question' => false],
        ];
        foreach ($candidatos as $c) {
            if ($cap === null || self::fits(self::composePricing($header, $items, $also, $question, $c), $cap)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * @param  string[]  $items
     * @param  array{items:int, also:bool, question:bool}  $layout
     */
    public static function composePricing(string $header, array $items, ?string $also, string $question, array $layout): string
    {
        $texto = $header.implode('; ', array_slice($items, 0, $layout['items'])).'.';
        if ($layout['also'] && $also !== null) {
            $texto .= ' '.$also;
        }
        if ($layout['question']) {
            $texto .= ' '.$question;
        }

        return $texto;
    }

    // ── Piezas internas ─────────────────────────────────────────────────────

    /**
     * El orden de la regla general sobre una lista de frases o piezas: las del
     * medio desde la segunda y, al final, la pregunta (si la última lo es).
     *
     * @param  string[]  $partes
     * @return int[]
     */
    private static function generalOrder(array $partes): array
    {
        $n = count($partes);
        if ($n <= 1) {
            return [];
        }
        $conPregunta = str_ends_with(trim($partes[$n - 1]), '?');
        $ultimoDelMedio = $conPregunta ? $n - 2 : $n - 1;
        // `range()` cuenta hacia atrás si el final es menor: sin frases del medio, ninguna.
        $orden = $ultimoDelMedio >= 1 ? range(1, $ultimoDelMedio) : [];
        if ($conPregunta) {
            $orden[] = $n - 1;
        }

        return $orden;
    }

    /**
     * Suelta las partes en el orden dado, de una en una, hasta que quepa.
     *
     * @param  array<int, array{0:string, 1:string}>  $partes
     * @param  int[]  $orden
     */
    private static function dropInOrder(array $partes, array $orden, int $cap): ?string
    {
        $quedan = array_fill(0, count($partes), true);
        foreach ($orden as $i) {
            $quedan[$i] = false;
            $texto = self::join($partes, $quedan);
            if ($texto !== '' && self::fits($texto, $cap)) {
                return $texto;
            }
        }

        return null;
    }

    /**
     * Vuelve a unir las partes que quedan. Entre dos que quedan va el
     * separador que seguía a la ANTERIOR a la siguiente: al soltar la frase de
     * antes de los enlaces, el salto de línea que los separaba se conserva.
     *
     * @param  array<int, array{0:string, 1:string}>  $partes
     * @param  array<int, bool>  $quedan
     */
    private static function join(array $partes, array $quedan): string
    {
        $texto = '';
        $hay = false;
        foreach ($partes as $i => [$frase]) {
            if (! ($quedan[$i] ?? false)) {
                continue;
            }
            if ($hay) {
                $sep = $partes[$i - 1][1] ?? ' ';
                $texto .= $sep !== '' ? $sep : ' ';
            }
            $texto .= $frase;
            $hay = true;
        }

        return trim($texto);
    }

    private static function protegida(string $frase): bool
    {
        return preg_match(self::URL, $frase) === 1 || preg_match(self::PRECIO, $frase) === 1;
    }
}
