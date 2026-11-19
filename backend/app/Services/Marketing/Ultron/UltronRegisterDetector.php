<?php

namespace App\Services\Marketing\Ultron;

/**
 * EL DETECTOR DEL TUTEO (cierre V2, apartado 4, H4-9; plan del arquitecto
 * `plan-arquitecto-a4-resto.md` §2.2 (a), decisión T-2 del lead).
 *
 * Nace de la prueba real del 18-11-2026: con la versión publicada en
 * «profesional» (de usted), el revisor de n8n aprobó un horario escrito
 * entero de tú («Te entiendo, quieres saber…») y una respuesta de precios
 * que mezclaba («…seguimiento en tus ejercicios. ¿Me cuenta si ya ha
 * entrenado…?»). El tono se le pedía al modelo y nada lo comprobaba. Esto lo
 * comprueba, de forma determinista, y SOLO dice qué marcas hay: qué hacer con
 * ellas lo decide el commit.
 *
 * CÓMO MIRA, en este orden:
 *  1. Quita lo que no es la voz de ULTRON: las URL, los marcadores `{{…}}`,
 *     lo que va entre comillas («…», "…", “…»: lo que dijo la persona, citado)
 *     y los nombres de plan del catálogo (un plan puede llamarse «Tu Plan»).
 *     Las comillas, solo si lo que queda FUERA de ellas es la mayor parte del
 *     mensaje (más palabras fuera que dentro; G6 del lead, S-6 de `security`
 *     y m-2 del revisor): un mensaje entero (o casi) entre comillas, o una
 *     frase de la casa entrecomillada como lema, se mira completo, comillas
 *     incluidas. Unas comillas sin cerrar no tapan nada.
 *  2. Minúsculas CONSERVANDO las tildes: «estás» es tuteo y «estas» no; por
 *     eso no usa `SalesAgentDecisionSchema::normalize()`, que las quita.
 *  3. Palabras enteras con una clase explícita (`[a-záéíóúñü]+`): el PCRE de
 *     producción no admite `\p{…}`. Por palabra entera, «te» no se encuentra
 *     dentro de «este», «parte» o «gente», ni «tu» dentro de «estatus».
 *
 * LISTA CERRADA ({@see self::MARCAS}): pronombres de tú, imperativos de tú con
 * enclítico y formas verbales que solo son de tú. Nunca marca «su»/«sus» (son
 * de usted o de tercera persona), «usted», «té» ni «esta»/«estas». Una forma
 * de tú que no está en la lista no se detecta: es un falso negativo aceptado
 * (preferible a tumbar mensajes correctos), y la lista crece con su caso de
 * prueba, no a ojo.
 *
 * PURA: sin base de datos, sin config, sin reloj y sin log. El catálogo de
 * planes lo pasa quien llama (`activePlans()`: cada plan con su `name`).
 */
final class UltronRegisterDetector
{
    /** Como mucho cuántas marcas se devuelven: para la traza basta con saber cuáles, no todas. */
    public const MAX_MARCAS = 5;

    /**
     * Las marcas de tuteo, en minúsculas y con sus tildes. Los imperativos
     * van también sin tilde, porque la base de los prompts se escribe sin
     * tildes y el modelo a veces las copia así («cuentame»); «estas» no,
     * porque sin tilde es otra palabra.
     *
     * Las del final las propuso `backend` (decisión T-2: «puedes proponer
     * añadir alguna con su caso de prueba»): todas salen hoy en un texto de
     * Laravel o en un borrador real y todas son formas que solo existen de tú
     * («¿ya has entrenado?», «seas principiante o ya vengas», «algo que puedas
     * sostener», «puedo ayudarte», «para no darte», «pueda resolverte»…).
     */
    public const MARCAS = [
        // Pronombres.
        'tú', 'tu', 'tus', 'te', 'ti', 'contigo', 'tuyo', 'tuya', 'tuyos', 'tuyas',
        // Imperativos con enclítico.
        'cuéntame', 'cuentame', 'dime', 'dímelo', 'dimelo', 'escríbeme', 'escribeme', 'avísame', 'avisame',
        'pregúntame', 'preguntame', 'cuéntanos', 'cuentanos', 'escríbenos', 'escribenos', 'avísanos', 'avisanos', 'dinos',
        // Verbos de tú.
        'quieres', 'puedes', 'tienes', 'necesitas', 'buscas', 'prefieres', 'vienes', 'estás', 'sabes', 'vas', 'eres',
        'empiezas', 'arrancas', 'entrenas', 'animas', 'alcanzaste', 'viniste', 'quisieras', 'podrías', 'podrias',
        // Propuestas de `backend`, cada una con su caso.
        'has', 'vengas', 'seas', 'puedas', 'quieras', 'sientas', 'crees', 'decides',
        'ayudarte', 'contarte', 'darte', 'orientarte', 'responderte', 'resolverte', 'recomendarte',
        // m-1 del revisor (G9 del lead): falsos negativos medidos sin «te/tu»; solo formas
        // que no existen de usted ni en tercera persona («ven», «mira», «aprovecha»,
        // «recuerda» y «cuenta» NO entran: «ellos ven», «el plan aprovecha»).
        'anímate', 'animate', 'pásate', 'pasate', 'inscríbete', 'inscribete', 'acércate', 'acercate',
        'estés', 'tengas', 'necesites', 'llegas', 'entrenaste',
    ];

    /** Lo que va entre comillas (latinas, rectas e inglesas), siempre con su pareja de cierre. */
    private const CITAS = ['/«[^»]*»/u', '/"[^"]*"/u', '/“[^”]*”/u'];

    /** Para quitar un nombre de plan escrito sin tildes («plan dia» por «PLAN DÍA»). */
    private const SIN_TILDES = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u'];

    /**
     * Las marcas de tuteo del texto, una vez cada una y en el orden en que
     * aparecen; como mucho {@see self::MAX_MARCAS}. Vacío = no tutea.
     *
     * @param  array<int,array<string,mixed>>  $planes  el catálogo (`activePlans()`): se quitan sus nombres antes de mirar
     * @return list<string>
     */
    public static function tuteo(string $texto, array $planes = []): array
    {
        $t = (string) preg_replace(['#https?://\S+#u', '#\bwww\.\S+#u', '/\{\{[^}]*\}\}/u'], ' ', $texto);
        $sinCitas = (string) preg_replace(self::CITAS, ' ', $t);
        // La cita se descarta solo si fuera queda la mayor parte del mensaje (G6, S-6): si no, se mira entero.
        $fuera = count(self::palabras($sinCitas));
        $palabras = self::palabras($fuera > count(self::palabras($t)) - $fuera ? $sinCitas : $t);

        foreach ($planes as $plan) {
            $nombre = self::palabras((string) ($plan['name'] ?? ''));
            if ($nombre === []) {
                continue;
            }
            foreach ([$nombre, array_map(fn (string $p) => strtr($p, self::SIN_TILDES), $nombre)] as $forma) {
                $palabras = self::sinLaSecuencia($palabras, $forma);
            }
        }

        $marcas = [];
        foreach ($palabras as $p) {
            if ($p !== null && in_array($p, self::MARCAS, true) && ! in_array($p, $marcas, true)) {
                $marcas[] = $p;
                if (count($marcas) === self::MAX_MARCAS) {
                    break;
                }
            }
        }

        return $marcas;
    }

    /**
     * Las palabras del texto, en minúsculas y con sus tildes. Sin `\p{L}`: el
     * PCRE de producción no lo compila.
     *
     * @return list<string>
     */
    private static function palabras(string $texto): array
    {
        preg_match_all('/[a-záéíóúñü]+/u', mb_strtolower($texto), $m);

        return $m[0];
    }

    /**
     * Las palabras con cada aparición de la secuencia tapada (null): así se
     * quita un nombre de plan de varias palabras sin tocar las de alrededor.
     *
     * @param  list<string|null>  $palabras
     * @param  list<string>  $secuencia
     * @return list<string|null>
     */
    private static function sinLaSecuencia(array $palabras, array $secuencia): array
    {
        $n = count($secuencia);
        for ($i = 0; $i + $n <= count($palabras); $i++) {
            if (array_slice($palabras, $i, $n) === $secuencia) {
                array_splice($palabras, $i, $n, array_fill(0, $n, null));
                $i += $n - 1;
            }
        }

        return $palabras;
    }
}
