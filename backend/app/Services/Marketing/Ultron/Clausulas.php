<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;

/**
 * EL TEXTO DE LA PERSONA, PARTIDO EN LO QUE AFIRMA Y LO QUE NIEGA.
 *
 * «El viernes no, mejor el miércoles a las 10» nombra dos días y quiere uno.
 * Leer la primera fecha registraba el viernes: el equipo preparaba un día y
 * la persona llegaba otro. La unidad de lectura es la CLÁUSULA —lo que va
 * entre comas, puntos o antes de un «pero», un «mejor» o un «sino»—: una
 * cláusula con una negación descarta lo que nombra, y una que empieza por
 * «mejor», «prefiero» o «sino» es la corrección que manda. Un comparativo
 * («mejor el sábado que el viernes») se parte en lo elegido y lo descartado,
 * un «mejor no» suelto niega lo que iba antes, y lo que sigue a un
 * condicional («si no se puede, entonces el jueves») es una alternativa, no
 * una corrección.
 *
 * Lo mismo vale para lo que se aprende de la persona: «no me interesan las
 * clases grupales» o «las clases grupales no me gustan» no son un interés, y
 * «no soy principiante» no es un principiante. Todo esto se escribe en la
 * ficha PERMANENTE del lead, así que leer al revés un «no» es un error que
 * dura meses.
 *
 * Trabaja sobre texto ya normalizado (minúsculas y sin tildes, ver
 * {@see SalesAgentDecisionSchema::normalize()}).
 */
final class Clausulas
{
    /** Una negación dentro de la cláusula, incluida la excepción («menos el viernes», «excepto los lunes»). */
    private const NIEGA = '/\b(no|nunca|tampoco|ni|imposible|jamas|menos|excepto|salvo)\b/u';

    /** La cláusula que corrige a la anterior: la que manda. */
    private const PREFIERE = '/^(mejor|mas bien|prefiero|preferiria|sino|entonces)\b/u';

    /**
     * Frases con «no» que no niegan nada de lo que viene: un condicional de
     * cortesía («si no es molestia») o una sugerencia («¿por qué no el
     * sábado?»). Sin quitarlas, «mañana a las 2 si no es molestia» perdía la
     * fecha y la visita volvía a no registrarse.
     */
    private const NO_QUE_NO_NIEGA = '/\b(si no (es|hay|te|le|les|fuera|seria)|por que no|como no|o si no|mas o menos|por lo menos|al menos|a menos|menos (cuarto|cinco|diez|veinte|veinticinco))\b/u';

    /**
     * Una cláusula que sólo dice que no: niega la última con contenido antes de
     * ella («el viernes, pues mejor no»). «Mentiras» o «es broma» a secas
     * retiran lo dicho igual que un «no»: «busco bajar de peso. Mentiras».
     */
    private const SOLO_NO = '/^((mejor|pensandolo bien|bueno|pues|ah|la verdad|no se)\s+)*(no|nel|nop|nah|paso|mentiras?|mentiritas?|es broma|era broma)(\s+(puedo|me sirve|me queda|me da|gracias|creo))?$/u';

    /** Una cláusula que es sólo muletilla: no tiene contenido que negar ni elegir. */
    private const MULETILLA = '/^(pues|bueno|ah|ok|vale|listo|la verdad|entonces|mejor|o sea)$/u';

    /** Lo que hace que la cláusula siguiente sea una alternativa y no una corrección. */
    private const CONDICIONAL = '/\bsi\b/u';

    /** Una preferencia con su término de comparación: se queda lo elegido, se descarta lo comparado. */
    private const PREFERENCIA = '/\b(mejor|prefiero|preferiria|me (queda|quedaria|sirve|serviria|conviene|convendria) (mas|mejor))\b/u';

    private const COMPARATIVO = '/\b(?:antes que|mas que|en vez de|en lugar de|que)\s+(?=(?:el|la|los|las|a las?|manana|hoy|pasado|este|esta)\b)/u';

    /**
     * La negación que va DETRÁS de lo que niega: «las clases grupales no me
     * gustan», «entrenador personal no necesito», «bajar de peso no es mi
     * meta», «clases ni loco», o un «no» que cierra la cláusula. Estrecha a
     * propósito: «¿las clases no tienen costo?» no dice que no le interesen.
     */
    private const NIEGA_DESPUES = '/^\s*(\S+\s+){0,2}?((no|ni)\s+(me\s+|te\s+|le\s+|les\s+)?(gusta\w*|interesa\w*|llama\w*|quiero|queremos|necesito|busco|es\s+mi|es\s+lo\s+mio|son\s+lo\s+mio|va\s+conmigo|loco|nada)\b|no\s*$|paso\s*$)/u';

    /**
     * La negación DÉBIL que va detrás: basta para no ANOTAR un rasgo, pero no
     * para retirarlo. «Principiante no soy» no es un principiante; «las clases
     * no son caras» no dice que no le interesen.
     */
    private const NIEGA_DESPUES_DEBIL = '/^\s*(\S+\s+){0,2}?(no|ni)\s+(soy|es|son|somos|estoy|tengo)\b/u';

    /**
     * «a. m.» y «p. m.» como UNA marca: sin esto el punto partía «2 p. m.» en
     * dos cláusulas y la hora se quedaba sin su tarde.
     */
    public static function canonica(string $t): string
    {
        return (string) preg_replace(
            [
                '/\b([ap])\.\s?m\b\.?/u',
                // «7.30» es una hora: sin esto el punto partía «7.30 pm» y el pm se perdía.
                '/\b(\d{1,2})\.([0-5]\d)\b/u',
                // «a las 6 30» también.
                '/\b(a\s+las?\s+\d{1,2})\s+([0-5]\d)\b/u',
            ],
            ['$1m', '$1:$2', '$1:$2'],
            $t,
        );
    }

    /**
     * Las cláusulas del texto, en orden, con si niegan, si corrigen y si son
     * pregunta.
     *
     * Con `$conCorrecciones` (lo normal para elegir un día o una hora) también
     * se corta antes de «mejor», «sino», «entonces» o «prefiero», que abren la
     * corrección que manda, y los comparativos se parten. Sin ello sólo se
     * corta por puntuación y por contraste («pero», «aunque»): para saber qué
     * dice la persona de sí misma («me quedan mejor las tardes») cortar en
     * «mejor» separaba el verbo de lo que describe.
     *
     * @return array<int,array{i:int,texto:string,niega:bool,prefiere:bool,pregunta:bool,negada_despues:bool}>
     */
    public static function de(string $textoNormalizado, bool $conCorrecciones = true): array
    {
        $t = self::canonica($textoNormalizado);
        // «Mejor» después de «queda», «sirve», «es»… es un adverbio («me queda mejor»), no una corrección.
        $marcas = $conCorrecciones
            ? '(?=\b(?:pero|mas bien|prefiero|preferiria|aunque|sino|entonces)\b)|(?<!queda )(?<!quedan )(?<!sirve )(?<!sirven )(?<!conviene )(?<!\bes )(?<!seria )(?<!\bva )(?<!\bmas )(?<!quedaria )(?=\bmejor\b)'
            : '(?=\b(?:pero|aunque)\b)';

        // Primero por puntuación, guardando los signos: lo que va tras «¿» o antes de «?» es pregunta.
        $trozos = preg_split('/([,.;!?¡¿()\n]+)/u', $t, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $salida = [];
        for ($i = 0; $i < count($trozos); $i += 2) {
            $antes = $trozos[$i - 1] ?? '';
            $despues = $trozos[$i + 1] ?? '';
            $pregunta = str_contains($despues, '?') || str_contains($antes, '¿');

            foreach (preg_split('/'.$marcas.'/u', $trozos[$i]) ?: [] as $parte) {
                $parte = trim((string) preg_replace('/\s+/u', ' ', $parte));
                if ($parte === '') {
                    continue;
                }
                foreach ($conCorrecciones ? self::comparativo($parte) : [[$parte, null]] as [$texto, $papel]) {
                    $sinCortesia = (string) preg_replace(self::NO_QUE_NO_NIEGA, ' ', $texto);
                    $salida[] = [
                        'i' => count($salida),
                        'texto' => $texto,
                        'niega' => $papel === 'descartado' || preg_match(self::NIEGA, $sinCortesia) === 1,
                        'prefiere' => $papel === 'elegido' || ($papel === null && preg_match(self::PREFIERE, $texto) === 1),
                        'pregunta' => $pregunta,
                        'negada_despues' => false,
                    ];
                }
            }
        }

        for ($i = 1; $i < count($salida); $i++) {
            // «el viernes, pues mejor no»: el «no» suelto niega la última cláusula con contenido.
            if (preg_match(self::SOLO_NO, $salida[$i]['texto']) === 1) {
                for ($j = $i - 1; $j >= 0; $j--) {
                    if (preg_match(self::MULETILLA, $salida[$j]['texto']) !== 1) {
                        $salida[$j]['niega'] = true;
                        $salida[$j]['negada_despues'] = true;
                        break;
                    }
                }
            }
            // «si no se puede, entonces el jueves»: una alternativa, no una corrección.
            if ($conCorrecciones && preg_match(self::CONDICIONAL, $salida[$i - 1]['texto']) === 1) {
                $salida[$i]['prefiere'] = false;
            }
        }

        return $salida;
    }

    /**
     * «mejor el sábado que el viernes» → [«mejor el sábado», elegido] y
     * [«el viernes», descartado]. Sin preferencia o sin término de
     * comparación, la cláusula queda entera (papel null).
     *
     * @return array<int,array{0:string,1:?string}>
     */
    private static function comparativo(string $parte): array
    {
        if (preg_match(self::PREFERENCIA, $parte) !== 1 || preg_match(self::COMPARATIVO, $parte, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [[$parte, null]];
        }
        $corte = (int) $m[0][1];
        $cabeza = trim(substr($parte, 0, $corte));
        $cola = trim(substr($parte, $corte + strlen($m[0][0])));
        if ($cabeza === '' || $cola === '' || preg_match(self::PREFERENCIA, $cabeza) !== 1) {
            return [[$parte, null]];
        }

        return [[$cabeza, 'elegido'], [$cola, 'descartado']];
    }

    /**
     * La negación que RIGE un verbo de gusto, interés o necesidad: «no me
     * interesan para nada [las clases]», «no me gustan mucho [las clases]»,
     * «no me hace falta [entrenador]», «tampoco es que sea [principiante]».
     * Niega lo que venga detrás en la cláusula aunque haya más de dos
     * palabras de relleno: con la ventana corta, esas frases dejaban en la
     * ficha justo lo contrario de lo que la persona dijo.
     */
    private const NIEGA_EL_VERBO = '/\b(no|nunca|tampoco|jamas)\s+(me\s+|te\s+|le\s+|nos\s+)?(he\s+|ha\s+|han\s+)?(gusta\w*|interesa\w*|llama\w*|atrae\w*|convence\w*|hace\w*\s+falta|sirve\w*|quiero|queremos|necesit\w*|busco)\b|\b(no|tampoco)\s+es\s+que\b|\bno\s+creo\s+que\b/u';

    /**
     * Lo que le quita el mando a esa negación: la corrección («no quiero
     * bajar de peso sino [ganar masa]») o el deseo afirmado otra vez («no
     * quiero bajar de peso quiero [ganar masa]», sin coma, como se escribe
     * en WhatsApp).
     */
    private const RETOMA = '/\b(sino|mas bien|mejor|prefiero|preferiria|en cambio|quiero|queremos|quisiera|busco|necesito|me (gusta|interesa|llama|atrae|sirve|convence)\w*|me hace\w* falta)\b/u';

    /**
     * ¿Lo que va justo antes de una coincidencia la niega? «no me interesan
     * [las clases]», «no soy [principiante]», «sin [entrenador personal]».
     * Hasta dos palabras de relleno entre la negación y lo negado; o
     * cualquier distancia si la negación rige un verbo de gusto, interés o
     * necesidad ({@see self::NIEGA_EL_VERBO}) y nada le quitó el mando
     * ({@see self::RETOMA}). Ante la duda, negada: la ficha es permanente y
     * no aprender sólo cuesta volver a preguntar.
     */
    public static function negadoAntes(string $antes): bool
    {
        if (preg_match('/\b(no|nunca|ni|sin|tampoco|jamas|odio|detesto|nada de|no soporto|paso de|menos|excepto|salvo)\s+(\S+\s+){0,2}$/u', $antes) === 1) {
            return true;
        }
        if (preg_match_all(self::NIEGA_EL_VERBO, $antes, $m, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }
        // Manda la ÚLTIMA negación que rige: «no me gustan y no me interesan [las clases]».
        [$verbo, $desde] = end($m[0]);

        return preg_match(self::RETOMA, substr($antes, (int) $desde + strlen($verbo))) !== 1;
    }

    /** ¿Lo que va justo detrás la niega? Ver {@see self::NIEGA_DESPUES}. */
    public static function negadoDespues(string $despues): bool
    {
        return preg_match(self::NIEGA_DESPUES, $despues) === 1;
    }

    /**
     * ¿El texto AFIRMA lo que busca el patrón? Al menos una coincidencia que
     * no venga negada —ni antes, ni detrás, ni por un «no» suelto en la
     * cláusula siguiente («clases grupales? no»)—, dentro de su cláusula.
     *
     * Sin `$conPreguntas`, lo que va en una pregunta no cuenta: en «soy
     * principiante, ¿la rutina es avanzada?» lo avanzado es la rutina, no
     * ella.
     */
    public static function afirma(string $patron, string $textoNormalizado, bool $conPreguntas = true): bool
    {
        foreach (self::coincidencias($patron, $textoNormalizado, $conPreguntas) as [$fuerte, $debil]) {
            if (! $fuerte && ! $debil) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿El texto NIEGA lo que busca el patrón? Coincide, y todas sus
     * coincidencias vienen negadas con una negación FUERTE: «no me interesan
     * las clases», «las clases no me gustan», «odio las clases». Una débil
     * («las clases no son caras») no retira nada.
     */
    public static function niega(string $patron, string $textoNormalizado): bool
    {
        $todas = self::coincidencias($patron, $textoNormalizado);
        foreach ($todas as [$fuerte]) {
            if (! $fuerte) {
                return false;
            }
        }

        return $todas !== [];
    }

    /**
     * Cada coincidencia del patrón, cláusula a cláusula: [negada con fuerza, negada débilmente].
     *
     * @return array<int,array{0:bool,1:bool}>
     */
    private static function coincidencias(string $patron, string $textoNormalizado, bool $conPreguntas = true): array
    {
        $salida = [];
        foreach (self::de($textoNormalizado, false) as $c) {
            if ((! $conPreguntas && $c['pregunta']) || preg_match_all($patron, $c['texto'], $m, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            foreach ($m[0] as [$trozo, $desde]) {
                $antes = substr($c['texto'], 0, (int) $desde);
                $despues = substr($c['texto'], (int) $desde + strlen($trozo));
                $salida[] = [
                    self::negadoAntes($antes) || self::negadoDespues($despues) || ($c['negada_despues'] ?? false),
                    preg_match(self::NIEGA_DESPUES_DEBIL, $despues) === 1,
                ];
            }
        }

        return $salida;
    }

    /**
     * El valor que la persona ELIGIÓ entre los que nombró, cláusula a
     * cláusula: se descartan los de cláusulas que niegan; si alguno está en
     * una cláusula que corrige («mejor…», «sino…»), manda la ÚLTIMA de ésas
     * —y si ella misma duda entre dos, null—; si no, sólo vale cuando todos
     * los que quedan coinciden. Si dudan entre dos («el sábado o el
     * domingo»), null: se pregunta, no se adivina.
     *
     * @param  array<int,array{0:?string,1:array{i:int,texto:string,niega:bool,prefiere:bool}}>  $candidatos  [valor (null = inválido), cláusula]
     */
    public static function eleccion(array $candidatos): ?string
    {
        $vivos = array_values(array_filter($candidatos, fn (array $c) => ! $c[1]['niega']));
        if ($vivos === []) {
            return null;
        }

        $corregidos = array_values(array_filter($vivos, fn (array $c) => $c[1]['prefiere']));
        if ($corregidos !== []) {
            $ultima = end($corregidos)[1]['i'];
            $vivos = array_values(array_filter($corregidos, fn (array $c) => $c[1]['i'] === $ultima));
        }

        $distintos = array_values(array_unique(array_map(fn (array $c) => $c[0] ?? "\0", $vivos)));

        return count($distintos) === 1 && $distintos[0] !== "\0" ? $distintos[0] : null;
    }
}
