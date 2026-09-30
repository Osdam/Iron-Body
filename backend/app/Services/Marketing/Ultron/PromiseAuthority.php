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

    /**
     * «Cancelé tu visita», «la moví al jueves», «tu visita quedó cancelada»,
     * «se canceló tu visita», «te la acabo de cancelar»: un cambio que sólo es
     * verdad si la herramienta de cortesía lo hizo. Sin esto, con una visita
     * ya confirmada (que la herramienta no toca) o sin nada que cancelar, la
     * frase salía y la persona no iba, o iba, a una visita que seguía en pie o
     * que nunca existió.
     *
     * Una lista y no una sola expresión: cada forma marca dónde está el verbo
     * (el grupo `v`), porque la negación y la pregunta se miran pegadas a él y
     * no a una ventana de caracteres. Sin tildes el subjuntivo y el pretérito
     * se escriben igual («si quieres que te la cambie» / «la cambié»): tras
     * «que», con o sin pronombre, es un ofrecimiento. Con el pronombre solo,
     * los verbos de mover piden además el cuándo, porque «la pasamos bien»
     * también es «la pasamos». El segundo valor dice si la forma nombra la
     * visita; si no la nombra, el pronombre puede ser de otra cosa («la
     * membresía la cancelamos en recepción») y decide el antecedente. El resto
     * lo decide {@see cambioDeVisitaEn()}.
     *
     * @var array<int,array{0:string,1:bool}>
     */
    private const CAMBIOS_DE_VISITA = [
        // «cancelé tu visita», «pasamos tu cita para el lunes».
        ['/(?<!que )(?<!que te )(?<!que le )\b(?<v>cancele|cancelamos|anule|anulamos|movi|movimos|cambie|cambiamos|corri|corrimos|reagende|reagendamos|reprograme|reprogramamos|elimine|eliminamos|borre|borramos|pase|pasamos)\s+(tu|la|su)\s+(visita|cita|cortesia|solicitud)\b/u', true],
        // «ya la cancelé», «la reagendamos».
        ['/(?<!que )(?<!que te )(?<!que le )(?<!que se )(?<!cuando )(?<!apenas )\b(la|lo)\s+(?<v>cancele|cancelamos|anule|anulamos|reagende|reagendamos|reprograme|reprogramamos|elimine|eliminamos|borre|borramos)\b/u', false],
        // «la moví para el jueves», y no «la pasamos bien».
        ['/(?<!que )(?<!que te )(?<!que le )(?<!que se )(?<!cuando )(?<!apenas )\b(la|lo)\s+(?<v>movi|movimos|pase|pasamos|corri|corrimos|cambie|cambiamos)\s+(para|al|a|hasta|el)\s+(el |la |las |los )?(manana|pasado|hoy|lunes|martes|miercoles|jueves|viernes|sabado|domingo|proxim\w*|otr[oa]|semana|\d)/u', false],
        // «tu visita quedó cancelada», «la cita ha sido reprogramada».
        ['/\b(visita|cita|cortesia|solicitud)\b[^.!?;]{0,40}?(?<v>\b(qued(o|a)|fue|esta|ya\s+esta|ha\s+sido)\s+(cancelad|anulad|movid|cambiad|reagendad|reprogramad|eliminad|borrad)\w*)/u', true],
        // «ya quedó cancelada tu visita»: el participio delante, el orden más común.
        ['/(?<v>\b(qued(o|a)|esta|fue|ha\s+sido)\s+(cancelad|anulad|movid|cambiad|reagendad|reprogramad|eliminad|borrad)[oa]s?)\s+(tu|la|su)\s+(visita|cita|cortesia|solicitud)\b/u', true],
        // «listo, ya quedó anulada».
        ['/(?<v>\bqued(o|a)\s+(cancelad|anulad|reagendad|reprogramad|eliminad)[oa])\b/u', false],
        // «se canceló tu visita», «tu visita ya se canceló».
        ['/(?<v>\bse\s+(cancelo|anulo|movio|cambio|reagendo|reprogramo|elimino|borro))\s+(tu|la|su)\s+(visita|cita|cortesia|solicitud)\b/u', true],
        ['/\b(visita|cita|cortesia|solicitud)\b[^.!?;]{0,30}?(?<v>\bse\s+(cancelo|anulo|movio|reagendo|reprogramo|elimino|borro))\b/u', true],
        // «he cancelado tu visita», «ya la he movido».
        ['/(?<v>\b(he|hemos)\s+(cancelado|anulado|movido|cambiado|reagendado|reprogramado|eliminado|borrado|pasado))\s+(tu|la|su)\s+(visita|cita|cortesia|solicitud)\b/u', true],
        ['/\b(la|lo)\s+(?<v>(he|hemos)\s+(cancelado|anulado|movido|cambiado|reagendado|reprogramado|eliminado|borrado))\b/u', false],
        // «dejé cancelada tu visita», «te la dejé cancelada».
        ['/(?<v>\b(deje|dejamos)\s+(cancelad|anulad|movid|cambiad|reagendad|reprogramad)[oa])\s+(tu|la|su)\s+(visita|cita|cortesia|solicitud)\b/u', true],
        ['/\b(la|lo)\s+(?<v>(deje|dejamos)\s+(cancelad|anulad|movid|cambiad|reagendad|reprogramad)[oa])\b/u', false],
        // «acabo de cancelar tu visita», «acabo de cancelarla», «te la acabo de cancelar».
        ['/(?<v>\b(acabo|acabamos|acabe)\s+de\s+(cancelar|anular|mover|cambiar|reagendar|reprogramar|eliminar|borrar))\s+(tu|la|su)\s+(visita|cita|cortesia|solicitud)\b/u', true],
        ['/(?<v>\b(acabo|acabamos|acabe)\s+de\s+(cancelar|anular|mover|cambiar|reagendar|reprogramar|eliminar|borrar))(la|lo)\b/u', false],
        ['/\b(la|lo)\s+(?<v>(acabo|acabamos|acabe)\s+de\s+(cancelar|anular|mover|cambiar|reagendar|reprogramar|eliminar|borrar))\b/u', false],
    ];

    /** En la primera persona del plural el presente y el pretérito son iguales: «la cancelamos» puede ser un hecho o una oferta. */
    private const AMBIGUAS = '/\b(cancelamos|anulamos|cambiamos|pasamos|reagendamos|reprogramamos|eliminamos|borramos|dejamos)\b/u';

    /**
     * La negación PEGADA al cambio, dentro de su cláusula: «no la cancelé»,
     * «todavía no la he movido», «tu visita no está cancelada». Una ventana de
     * caracteres leía «No te preocupes, ya la cancelé» como negada, y «bueno»
     * contiene «no ».
     */
    private const NEGACION_PEGADA = '/\b(no|nunca|tampoco|jamas|ni)(\s+(te|le|les|se|me|nos|lo|la|los|las|ya|todavia|aun|he|hemos|ha|han))*\s*$/u';

    /**
     * Lo que convierte una forma en «-amos» en ofrecimiento: la condición, vaya
     * delante o detrás en la misma frase («si quieres, la cambiamos para el
     * sábado», «la cancelamos si quieres», «si no puedes venir, la
     * cancelamos», «cuando me confirmes la cancelamos»).
     */
    private const CONDICION_DE_OFRECIMIENTO = '/\b(si|cuando|apenas|en cuanto)\s+(no\s+)?(me |nos |lo |te |tu |asi lo )?(quieres|quieras|prefieres|prefieras|gustas|gustes|necesitas|necesites|deseas|desees|sirve|parece|queda|dices|digas|confirmas|confirmes|avisas|avises|puedes|puedas|animas|animes|alcanzas|alcances|vienes|vengas|llegas|tienes)\b|\b(de ser necesario|en ese caso|si es necesario)\b/u';

    /** «Dime y la cancelamos», «avísame y la pasamos para el lunes»: se pide el dato antes de hacerlo. */
    private const PIDE_ANTES = '/\b(dime|avisame|confirmame|cuentame|escribeme|me\s+(dices|avisas|confirmas|escribes|cuentas))\b/u';

    /** «Con gusto la reagendamos»: disposición, no constancia. */
    private const DISPOSICION = '/\b(con gusto|encantad[oa]s?|claro que si|por supuesto que)\b/u';

    /** Detrás, la pregunta por el día o la hora nuevos: sin ellos no se pudo mover nada. */
    private const PIDE_DIA_U_HORA = '/\b(que|cual|cuales)\s+(dia|dias|hora|horario|fecha)\b|\ba\s+que\s+hora\b|\bcuando\s+te\s+(sirve|queda|viene|conviene)\b/u';

    /** Lo que no es la visita: si es lo último que se nombró, el pronombre habla de ello. */
    private const OTRO_OBJETO = '/\b(membresia|plan|planes|debito|cobro|cobros|pago|pagos|suscripcion|mensualidad|inscripcion|matricula|clase|clases|cuota|tarjeta|cuenta|renovacion|congelamiento|sesion|rutina|link|enlace|app|aplicacion|pedido|compra|factura)\b/u';

    private const LA_VISITA = '/\b(visita|cita|cortesia|solicitud)\b/u';

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

        return $this->primeraCitaNoNegada($t, [self::TRASLADO_AL_EQUIPO]) ?? $this->cambioDeVisitaEn($t);
    }

    /**
     * El cambio de visita que el texto da por HECHO, o null. Cada coincidencia
     * de cada forma se juzga en su cláusula, no sólo la primera: negada («no la
     * cancelé»), preguntada («¿tu visita quedó cancelada?»), de otra cosa («la
     * membresía la cancelamos en recepción») u ofrecida («la cancelamos si
     * quieres») no afirma nada, y la siguiente de la misma respuesta todavía
     * puede afirmarlo. Una coletilla detrás («…, ¿algo más?») no convierte en
     * pregunta lo que ya se afirmó, ni un «no te preocupes» de otra cláusula lo
     * niega.
     */
    private function cambioDeVisitaEn(string $t): ?string
    {
        foreach (self::CAMBIOS_DE_VISITA as [$patron, $nombraLaVisita]) {
            if (preg_match_all($patron, $t, $ms, PREG_OFFSET_CAPTURE) < 1) {
                continue;
            }
            foreach ($ms[0] as $i => [$cita, $ini]) {
                [$verbo, $posVerbo] = $ms['v'][$i];
                $ini = (int) $ini;
                $fin = $ini + strlen((string) $cita);
                if ($this->negadaEnSuClausula($t, $ini) || $this->negadaEnSuClausula($t, (int) $posVerbo)
                    || (! $nombraLaVisita && $this->hablaDeOtraCosa($t, $ini))
                    || $this->enPregunta($t, $ini, $fin)
                    || (preg_match(self::AMBIGUAS, (string) $verbo) === 1 && $this->loOfrece($t, $ini, $fin))) {
                    continue;
                }

                return trim((string) $cita);
            }
        }

        return null;
    }

    /** ¿Hay una negación pegada justo antes de esta posición, dentro de su cláusula? */
    private function negadaEnSuClausula(string $t, int $pos): bool
    {
        $previo = substr($t, 0, $pos);
        $desde = 0;
        foreach ([',', ';', ':', '.', '!', '?', "\n", '¿', '¡'] as $corte) {
            $p = strrpos($previo, $corte);
            if ($p !== false) {
                $desde = max($desde, $p + strlen($corte));
            }
        }

        return preg_match(self::NEGACION_PEGADA, substr($previo, $desde)) === 1;
    }

    /** ¿Lo último que se nombró antes del pronombre es otra cosa y no la visita? */
    private function hablaDeOtraCosa(string $t, int $pos): bool
    {
        $previo = substr($t, 0, $pos);
        $ultima = function (string $patron) use ($previo): int {
            if (preg_match_all($patron, $previo, $m, PREG_OFFSET_CAPTURE) < 1) {
                return -1;
            }

            return (int) end($m[0])[1];
        };
        $otra = $ultima(self::OTRO_OBJETO);

        return $otra >= 0 && $otra > $ultima(self::LA_VISITA);
    }

    /**
     * ¿El cambio está DENTRO de una pregunta? Un «¿» abierto antes en la misma
     * oración, o su propia cláusula cerrando con «?» («ya se canceló tu
     * visita?»). La pregunta de otra cláusula («cancelé tu visita, ¿algo
     * más?») no cuenta.
     */
    private function enPregunta(string $t, int $ini, int $fin): bool
    {
        [$antes] = $this->oracionDe($t, $ini, $fin);

        return str_contains($antes, '¿') || preg_match('/^[^,;:.!?¿¡\n]*\?/u', substr($t, $fin)) === 1;
    }

    /**
     * ¿Una forma en «-amos» se ofrece en vez de afirmarse? Con «ya» delante es
     * un hecho («ya la cancelamos»). Si no, la ofrecen la condición antes o
     * después, el «dime / avísame» que pide el dato antes, la disposición
     * («con gusto la reagendamos»), la frase que cierra preguntando («la
     * cambiamos para el viernes, ¿te parece?») o la pregunta que sigue por el
     * día o la hora nuevos, sin los cuales no se pudo mover nada.
     */
    private function loOfrece(string $t, int $ini, int $fin): bool
    {
        [$antes, $despues, $cierre, $resto] = $this->oracionDe($t, $ini, $fin);
        if (preg_match('/\bya(\s+(te|le|se|me|nos))?\s*$/u', $antes) === 1) {
            return false;
        }

        return $cierre === '?'
            || preg_match(self::CONDICION_DE_OFRECIMIENTO, $antes) === 1
            || preg_match(self::CONDICION_DE_OFRECIMIENTO, $despues) === 1
            || preg_match(self::PIDE_ANTES, $antes) === 1
            || preg_match(self::DISPOSICION, $antes) === 1
            || preg_match(self::PIDE_DIA_U_HORA, $resto) === 1;
    }

    /**
     * La oración de una coincidencia: lo que va antes dentro de ella, lo que va
     * detrás, el signo con que cierra y lo que sigue después.
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function oracionDe(string $t, int $ini, int $fin): array
    {
        $previo = substr($t, 0, $ini);
        $desde = 0;
        foreach (['.', '!', '?', ';', "\n"] as $corte) {
            $p = strrpos($previo, $corte);
            if ($p !== false) {
                $desde = max($desde, $p + 1);
            }
        }
        $resto = substr($t, $fin);
        if (preg_match('/[.!?;\n]/u', $resto, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [substr($previo, $desde), $resto, '', ''];
        }
        $pos = (int) $m[0][1];

        return [substr($previo, $desde), substr($resto, 0, $pos), $m[0][0], substr($resto, $pos + 1)];
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
