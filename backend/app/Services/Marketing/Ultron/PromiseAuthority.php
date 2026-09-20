<?php

namespace App\Services\Marketing\Ultron;

/**
 * Lo que el texto AFIRMA que va a pasar, frente a lo que el turno hace.
 *
 * Nace de un patrón que se repitió tres veces en una semana, siempre con la
 * misma forma: algo afirma por escrito un efecto durable y el código no lo
 * produce. Las dos primeras veces el autor era Laravel —un texto curado que
 * prometía una marca que ya no se ponía, un docblock que prometía una fila que
 * no existía—. La tercera el autor es el modelo, y es la peor de las tres,
 * porque el modelo redacta libre: puede escribir la misma frase que el texto
 * curado sin que nadie haya pedido nada.
 *
 * Aquí se separan dos familias, y la diferencia decide qué se hace con ellas:
 *
 *   EFECTO_DURABLE  «lo dejo marcado», «queda registrado», «lo escalo». Dice
 *                   que ESTE turno deja algo hecho. Puede ser verdad: si el
 *                   turno marca de verdad, la frase es honesta. Se contrasta
 *                   contra la autoridad que decide la marca, no contra un
 *                   diccionario de frases prohibidas.
 *
 *   ACCION_FUTURA   «te escribo mañana», «te guardo el cupo». Dice que la
 *                   máquina hará algo MÁS TARDE por su cuenta. Nunca puede ser
 *                   verdad: `should_schedule_followup` está fijado a false y no
 *                   existe programador de seguimientos. No hay nada que
 *                   contrastar, es mentira siempre.
 *
 * No es un diccionario de frases. Cada familia exige DOS piezas —un verbo de
 * efecto y el objeto o el ancla temporal que lo convierte en promesa—, porque
 * las mismas palabras en presente informativo son respuestas legítimas:
 * «te aviso: el mensual cuesta X» no promete nada, y tumbarlo dejaría a alguien
 * sin respuesta para arreglar un problema de estilo.
 */
final class PromiseAuthority
{
    public const EFECTO_DURABLE = 'durable_effect_claim';

    public const ACCION_FUTURA = 'unsupported_future_action_promise';

    /** Ventana, en caracteres y sin salir de la frase, donde se busca la segunda pieza. */
    private const VENTANA = 40;

    /**
     * Verbos con los que la máquina dice que actuará DESPUÉS.
     *
     * Van con el clítico opcional («te lo recuerdo») y en primera persona del
     * singular y del plural, que es como se promete de verdad. No entran los
     * imperativos del cliente hacia nosotros —«avísame», «escríbeme»—, que son
     * lo contrario: la persona pidiendo, no la máquina prometiendo.
     */
    private const VERBO_FUTURO = '(escrib(o|imos)|avis(o|amos)|confirm(o|amos)|recuerdo|recordamos|llam(o|amos)|busc(o|amos)|contact(o|amos)|mand(o|amos)|envi(o|amos)|paso|pasamos)';

    /**
     * Verbos que prometen una capacidad que NO EXISTE, con ancla o sin ella.
     *
     * No hay agenda, ni reserva, ni programador. «Te agendo una cita» no
     * necesita decir cuándo para ser falso: ya lo es al decir que agenda.
     */
    private const VERBO_SIN_CAPACIDAD = '(agend(o|amos)|reserv(o|amos)|program(o|amos)|aparto|apartamos)';

    /**
     * Lo que convierte un presente informativo en una promesa: el cuándo.
     *
     * `cuando` lleva una excepción que se ganó a pulso: «te paso el link
     * cuando me digas» NO promete nada. Ahí la máquina no se compromete a
     * actuar sola más tarde, sino a responder cuando la persona vuelva a
     * escribir, que es exactamente lo que sí sabe hacer. El `cuando` que
     * promete es el que espera un hecho del mundo —«cuando abra la
     * inscripción», «cuando haya cupo»—, no el que espera a la persona.
     */
    private const ANCLA_TEMPORAL = '(manana|pasado\s+manana|luego|mas\s+tarde|despues|apenas|cuando(?!\s+(me\s+(digas|avises|escribas|confirmes)|quieras|gustes|puedas|decidas|lo\s+pidas|te\s+sirva|estes\s+list[oa]))|pronto|enseguida|en\s+la\s+(tarde|manana|noche)|en\s+un\s+rato|el\s+(lunes|martes|miercoles|jueves|viernes|sabado|domingo)|la\s+proxima|la\s+semana\s+que\s+viene|esta\s+semana|en\s+unos\s+dias|el\s+dia)';

    /**
     * Constancia FUERTE: participios y verbos que sólo significan una cosa.
     *
     * «Lo dejo escalado» no necesita decir a quién: escalar es trasladar al
     * equipo, no hay otra lectura. Por eso estos bastan solos.
     */
    private const CONSTANCIA_FUERTE = '(\bescal(o|amos)\b|\b(dejo|dejamos|queda|quedan|quedo)\s+[^.!?;]{0,25}?(escalad|radicad|reportad)\w*)';

    /**
     * Constancia DÉBIL: la misma palabra sirve para el equipo y para la app.
     *
     * «Queda registrada tu cuenta en la app» y «queda registrada tu solicitud»
     * comparten el verbo y no significan lo mismo. Por eso éstos exigen además
     * el objeto: sin él, la frase habla del registro de la app y es cierta.
     */
    private const CONSTANCIA_DEBIL = '(\b(dejo|dejamos|queda|quedan|quedo)\s+[^.!?;]{0,25}?(marcad|anotad|registrad|solicitad|abiert)\w*|\banot(o|amos)\b|\breport(o|amos)\b)';

    /**
     * El objeto que convierte la constancia en una promesa hacia el equipo.
     *
     * Sin esto, «queda registrado» habla de la app y es cierto; con esto,
     * habla de alguien que va a mirar el caso.
     */
    private const OBJETO_DEL_EQUIPO = '(equipo|solicitud|peticion|caso|reclamo|reporte|revis\w*|radicad\w*)';

    /** «lo envío al equipo», «le paso el caso al equipo»: transferencia explícita. */
    private const TRASLADO_AL_EQUIPO = '/\b(se\s+)?(lo|la|le|les)?\s*(envio|enviamos|paso|pasamos|reporto|reportamos|traslado|trasladamos)\b[^.!?;]{0,40}\bal\s+equipo\b/u';

    /**
     * Negaciones que dan la vuelta a la frase.
     *
     * Son las frases HONESTAS las que más se parecen a las prohibidas: «no te
     * puedo guardar el cupo» y «te guardo el cupo» comparten casi todo. Decir
     * la verdad sobre lo que no se puede hacer es exactamente lo que queremos
     * que el modelo haga, así que tumbarlo sería premiar la mentira.
     */
    private const NIEGA = ['no ', 'nunca ', 'tampoco ', 'sin ', 'jamas ', 'ni '];

    private const VENTANA_NEGACION = 40;

    /**
     * ¿Este texto promete algo? Y si sí, ¿de qué familia?
     *
     * @return array{kind:?string, quote:?string}
     */
    public function detectar(?string $texto): array
    {
        $t = $this->normalizar((string) $texto);
        if ($t === '') {
            return ['kind' => null, 'quote' => null];
        }

        // La acción futura va primero: es la que nunca puede ser verdad, así
        // que si una frase cae en las dos, manda la que no tiene salvación.
        if ($cita = $this->accionFuturaEn($t)) {
            return ['kind' => self::ACCION_FUTURA, 'quote' => $cita];
        }

        if ($cita = $this->efectoDurableEn($t)) {
            return ['kind' => self::EFECTO_DURABLE, 'quote' => $cita];
        }

        return ['kind' => null, 'quote' => null];
    }

    /** El fragmento donde la máquina promete actuar después, o null. */
    public function accionFuturaEn(?string $texto): ?string
    {
        $t = $this->normalizar((string) $texto);

        $patrones = [
            /*
             * Verbo de acción + el cuándo, sin salir de la frase.
             *
             * El lookahead es la mitad de la regla y se ganó midiendo: «te
             * aviso QUE mañana no hay clase» no promete nada, INFORMA de un
             * hecho fechado, y es la respuesta correcta a la pregunta más
             * común de un gimnasio. Lo que distingue una cosa de la otra es el
             * `que` pegado al verbo, que abre una completiva en vez de un
             * complemento temporal. Tumbarlo costaba más que un turno
             * degradado: el texto curado de horarios dice «no tengo un horario
             * general confirmado», así que la persona recibía PEOR información
             * que la verdadera que el modelo había escrito.
             */
            '/\b(te|le|les)\s+(lo\s+|la\s+|los\s+|las\s+)?'.self::VERBO_FUTURO.'\b(?!\s+(que\b|el\s+dato\b|la\s+info\b|los\s+datos\b))[^.!?;]{0,'.self::VENTANA.'}?\b'.self::ANCLA_TEMPORAL.'\b/u',
            /*
             * Y el mismo compromiso dicho al revés: «mañana te escribo».
             *
             * Faltaba, y por mi propio criterio era el error caro: un falso
             * negativo aquí es una mentira que llega a una persona, mientras
             * que un falso positivo sólo degrada el turno. La ventana es corta
             * y sin comas a propósito: en «mañana hay clase, te paso los
             * horarios» el cuándo pertenece a la otra oración y no promete
             * nada.
             */
            '/\b'.self::ANCLA_TEMPORAL.'\b[^.!?;,]{0,25}?\b(te|le|les)\s+(lo\s+|la\s+|los\s+|las\s+)?'.self::VERBO_FUTURO.'\b/u',
            // Capacidad inexistente: no hace falta decir cuándo.
            '/\b(te|le|les)\s+(lo\s+|la\s+|los\s+|las\s+)?'.self::VERBO_SIN_CAPACIDAD.'\b/u',
            // «te guardo el cupo»: guardar sólo promete cuando guarda un sitio.
            '/\b(te|le|les)\s+(lo\s+|la\s+)?guard(o|amos)\b[^.!?;]{0,'.self::VENTANA.'}?\b(cupo|lugar|puesto|espacio|clase|horario)\b/u',
            /*
             * NO hay patrón para «quedo pendiente». Lo hubo, y era un falso
             * positivo: «quedo pendiente de lo que necesites» es una muletilla
             * de bot —fea, ya marcada como style:bot_phrase— y no promete
             * ninguna acción. La promesa de verdad, «quedo pendiente y te
             * confirmo en la tarde», ya la caza el primer patrón por «te
             * confirmo» + «en la tarde»; el segundo sobraba y cobraba caro.
             */
        ];

        return $this->primeraCitaNoNegada($t, $patrones);
    }

    /** El fragmento donde el texto afirma un efecto durable de este turno, o null. */
    public function efectoDurableEn(?string $texto): ?string
    {
        $t = $this->normalizar((string) $texto);

        if ($t === '') {
            return null;
        }

        /*
         * Se mira FRASE a FRASE, no con una ventana en una dirección.
         *
         * El objeto puede ir antes, después o dentro del verbo —«dejo tu
         * solicitud radicada», «queda registrada tu solicitud», «lo anoto para
         * que el equipo lo revise»— y una ventana direccional se deja fuera un
         * orden de cada tres. La frase es la unidad correcta: lo que se afirma
         * y sobre qué se afirma viajan juntos en ella.
         */
        foreach ($this->frases($t) as [$frase, $desplazamiento]) {
            $fuerte = $this->citaDe(self::CONSTANCIA_FUERTE, $frase);
            $debil = $this->citaDe(self::CONSTANCIA_DEBIL, $frase);

            if ($fuerte === null && $debil === null) {
                continue;
            }
            if ($fuerte === null && $this->citaDe(self::OBJETO_DEL_EQUIPO, $frase) === null) {
                // Constancia sin destinatario: habla de la app, no del equipo.
                continue;
            }

            $cita = $fuerte ?? $debil;
            if ($this->estaNegada($t, $desplazamiento + (int) mb_strpos($frase, $cita))) {
                continue;
            }

            return $cita;
        }

        return $this->primeraCitaNoNegada($t, [self::TRASLADO_AL_EQUIPO]);
    }

    /**
     * El texto partido en frases, con dónde empieza cada una.
     *
     * @return array<int, array{0:string, 1:int}>
     */
    private function frases(string $t): array
    {
        $out = [];
        $pos = 0;
        foreach (preg_split('/([.!?;]+)/u', $t, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $trozo) {
            if ($i % 2 === 0 && trim($trozo) !== '') {
                $out[] = [$trozo, $pos];
            }
            $pos += mb_strlen($trozo);
        }

        return $out;
    }

    /** La primera coincidencia de un patrón suelto, o null. */
    private function citaDe(string $patron, string $frase): ?string
    {
        return preg_match('/'.$patron.'/u', $frase, $m) === 1 ? trim($m[0]) : null;
    }

    /**
     * @param  string[]  $patrones
     */
    private function primeraCitaNoNegada(string $t, array $patrones): ?string
    {
        if ($t === '') {
            return null;
        }

        foreach ($patrones as $patron) {
            if (preg_match($patron, $t, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            if ($this->estaNegada($t, (int) $m[0][1])) {
                continue;
            }

            return trim($m[0][0]);
        }

        return null;
    }

    private function estaNegada(string $texto, int $posicion): bool
    {
        $desde = max(0, $posicion - self::VENTANA_NEGACION);
        $antes = substr($texto, $desde, $posicion - $desde);

        // Sólo cuenta la negación de ESTA frase: un «no» dos frases atrás no
        // desactiva una promesa posterior.
        $corte = max(
            (int) strrpos($antes, '.'),
            max((int) strrpos($antes, '!'), (int) strrpos($antes, ';')),
        );
        if ($corte > 0) {
            $antes = substr($antes, $corte);
        }

        foreach (self::NIEGA as $marca) {
            if (str_contains($antes, $marca)) {
                return true;
            }
        }

        return false;
    }

    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return (string) preg_replace('/\s+/u', ' ', $s);
    }
}
