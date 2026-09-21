<?php

namespace App\Services\Marketing;

use App\Models\MarketingMessage;
use App\Services\Observability\ChannelLog;

/**
 * Último filtro para el texto que escribe una MÁQUINA y no compuso Laravel.
 *
 * El agujero que tapa es concreto. `SalesAgentDecisionValidator` revisa lo que
 * dice el modelo, pero vive DENTRO de los responders: solo corre cuando el
 * cerebro local produce la respuesta. Un cuerpo que llega ya escrito por
 * `POST /api/internal/marketing/send-message` —hoy el camino previsto para
 * n8n— no lo atravesaba: entraba directo al dispatcher y salía a Meta con un
 * precio inventado, una promesa prohibida o un intento de activar una
 * membresía, sin que nada lo mirara.
 *
 * POR AUTOR, NO POR CANAL. Esta es la parte que no es obvia:
 *
 *  - `ai` y `system`  → se filtra. Todo texto de máquina pasa por las mismas
 *    reglas, venga del modelo o de una automatización externa.
 *  - `human`          → NO se filtra. Un asesor PUEDE citar un precio: es su
 *    trabajo. Aplicarle la expresión regular de precios le rompería la
 *    herramienta para protegerlo de sí mismo, que no es de lo que va esto.
 *
 * Tampoco se aplica al texto que compone Laravel a partir de `Plan::price`
 * —`pricingReply()`, `paymentLinkMessage()`—: ese precio es la verdad de la
 * base de datos, no una invención del modelo. Por eso el guard vive en la
 * frontera de ENTRADA del texto ajeno (el endpoint interno) y no dentro de
 * {@see MarketingMessageDispatcher}, que seguiría siendo un despachador y
 * pasaría a ser, además, un censor de sus propios mensajes legítimos.
 *
 * No duplica ninguna regla: pregunta a {@see SalesAgentDecisionSchema}, que es
 * donde ya vivían los catálogos y adonde se movió el patrón de precios.
 */
class OutboundContentGuard
{
    /** Autores cuyo texto lo escribe una máquina. */
    public const MACHINE_SENDERS = [
        MarketingMessage::SENDER_AI,
        MarketingMessage::SENDER_SYSTEM,
    ];

    public const CODE_FORBIDDEN_ACTION = 'machine_reply_forbidden_action';

    /** El borrador del modelo trae una URL: los enlaces los pone Laravel en su propio mensaje. */
    public const CODE_URL_IN_REPLY = 'machine_reply_url';

    /** El borrador pide datos de tarjeta, claves u OTP: nunca, bajo ningún pretexto. */
    public const CODE_CARD_DATA_REQUEST = 'machine_reply_card_data';

    public const CODE_UNSAFE_CLAIM = 'machine_reply_unsafe_claim';

    public const CODE_INVENTED_PRICE = 'machine_reply_invented_price';

    /** El borrador promete una rebaja, una promoción o algo gratis que nadie declaró. */
    public const CODE_INVENTED_DISCOUNT = 'machine_reply_invented_discount';

    /** Ofrece pasar la conversación a una persona sin que nadie lo haya autorizado. */
    public const CODE_UNAUTHORIZED_HANDOFF = 'machine_reply_unauthorized_handoff';

    /**
     * Prometer que una persona dirá el HORARIO que el CRM no tiene.
     *
     * Es primo del traspaso, pero no es el mismo: aquí no se pasa la
     * conversación a nadie, se aplaza un dato. Aun así promete atención humana
     * que nadie ha autorizado, y en una conversación de WhatsApp eso se lee
     * como «espera, que te escriben» — y no escribe nadie.
     *
     * Va acotado al horario a propósito: es el hecho que hoy falta de verdad
     * (`gym.opening_hours = SOURCE_NOT_AVAILABLE`), y acotarlo deja fuera los
     * aplazamientos que SÍ son correctos, como que el equipo confirme el medio
     * de pago o revise una gestión de membresía. Lo demás lo juzga el Critic,
     * que sí tiene el contexto del turno y cuyo rechazo es un reintento y no un
     * turno perdido.
     */
    public const CODE_SCHEDULE_DEFERRAL = 'machine_reply_schedule_deferral';

    /**
     * Negar un cobro que SÍ existe.
     *
     * Medido en el canario: a «y tienes un link de pago mas directo de
     * casualidad?» el asistente contestó «No contamos con un link de pago
     * directo por ahora, pero puedes hacer el pago desde la app o en el
     * gimnasio». El checkout estaba disponible, la autoridad lo permitía y el
     * plan era vendible. La frase era, simplemente, falsa.
     *
     * Y no era el modelo equivocándose por su cuenta: los prompts, escritos
     * cuando el cobro automático estaba apagado para siempre, le enseñaron que
     * pagar es la app o el gimnasio. Por eso esto no se arregla en el prompt.
     *
     * Esto NO es un detector de intención sobre lo que escribe la persona
     * —eso sería una lista de frases, y ya se midió que cubre 2 de 20—. Es una
     * comprobación sobre NUESTRO propio texto, que es curado y del que sí
     * podemos decir si contradice un hecho del sistema.
     */
    public const CODE_DENIES_AVAILABLE_CHECKOUT = 'machine_reply_denies_available_checkout';

    /**
     * Dar por CONFIRMADA una visita que sólo está solicitada.
     *
     * El día de cortesía lo confirma una persona del equipo. Si el mensaje
     * dice «quedaste agendado» o «te esperamos el sábado», alguien se presenta
     * un día que nadie preparó, y eso no lo arregla ninguna disculpa
     * posterior: la persona ya hizo el viaje.
     *
     * No lo cubría nada. Se midió: ni «quedaste agendado», ni «tu cortesía
     * quedó confirmada», ni «ya te reservé el cupo» disparaban la invariante
     * de promesas —sólo caía «te agendo», en primera persona y futuro—. Las
     * tres formas que de verdad se escriben pasaban limpias.
     */
    public const CODE_CLAIMS_CONFIRMED_COURTESY = 'machine_reply_claims_confirmed_courtesy';

    /** ¿Este autor está sujeto al filtro? */
    public function appliesTo(string $senderType): bool
    {
        return in_array($senderType, self::MACHINE_SENDERS, true);
    }

    /**
     * Revisa el texto sin lanzar. Útil para diagnosticar y para las pruebas.
     *
     * El orden es por gravedad, no por comodidad: primero el intento de hacer
     * algo prohibido (activar una membresía, aprobar un pago), después la
     * promesa que no podemos sostener, y al final el precio inventado. Si un
     * mensaje viola tres reglas, el motivo que se reporta es el más grave.
     *
     * @return array{safe:bool, code:?string, signal:?string, risk_flag:?string}
     */
    /**
     * Ofrecer pasar a una persona, dicho como se dice.
     *
     * Deliberadamente estrecho: son fórmulas de TRASPASO, no menciones a seres
     * humanos. «Tenemos cuatro entrenadores» o «te acompaña un entrenador de
     * planta» son información del gimnasio y deben poder decirse; lo que no
     * puede salir sin permiso es la frase que termina la conversación y la
     * pasa a otro.
     *
     * Esto es la segunda barrera, no la primera: quien autoriza es
     * HumanHandoffAuthority sobre la propuesta. Esto mira el texto por si algo
     * se coló.
     */
    /**
     * «Una persona del equipo», en todas las formas en que el modelo la nombra
     * al intentar traspasar. Un solo sitio: cuando el guard dejó pasar «una
     * asesora» y «la coordinadora» fue porque cada patrón tenía su propia
     * lista y ninguna estaba completa.
     */
    private const PERSONA = '(alguien|una\s+persona|un(a)?\s+asesor(a)?|el\s+equipo|recepcion|mi\s+compan(er)?[oa]|(el|la)\s+coordinador(a)?|un(a)?\s+entrenador(a)?)';

    /** Objetos que se PASAN como información, no como persona. Lista blanca a propósito. */
    private const INFORMACION = '(links?|enlaces?|urls?|datos?|informacion|info|direccion|ubicacion|mapa|horarios?|precios?|valor|detalles?|lista|resumen|pasos?|beneficios?|planes?|opciones?|comparativa|catalogo|fotos?|videos?|documentos?|formatos?|requisitos|pdfs?|instrucciones|(numeros?|whatsapp|celular|telefono|contacto)(\s+de\s+contacto)?\s+(de|del)\s+((la|el|nuestra|nuestro|mi|su)\s+)?(sede|recepcion|gimnasio|gym|iron\s+body))';

    /** Lo que puede ir entre «te paso» y el objeto sin cambiar el sentido: artículos, adverbios, cuantificadores. */
    private const RELLENO = '(el|la|los|las|un|una|unos|unas|este|esta|estos|estas|ese|esa|mi|tu|nuestro|nuestra|otro|otra|ya|ahora|ahorita|aqui|aca|enseguida|rapido|rapidito|tambien|mas|toda|todo|todos|todas|dos|tres|un\s+par\s+de|por\s+aca|por\s+aqui|de\s+una\s+vez|de\s+una|de\s+inmediato|ahora\s+mismo|ya\s+mismo|apenas|entonces|mejor|primero|luego|igual)';

    /** Personas del gimnasio por su rol (sin recepción ni sede, que son lugares). */
    private const ROL = '(asesor(a|es|as)?|coordinador(a|es|as)?|entrenador(a|es|as)?|encargad[oa]s?|compan(er)?[oa]s?|equipo|alguien|persona)';

    private const OFRECE_TRASPASO = [
        '/\bte\s+(conecto|transfiero|derivo)\b/u',
        // «Te paso» y «te comunico» tienen dos sentidos: pasar a la PERSONA con
        // alguien (traspaso) o pasarle INFORMACION («te paso el link», «te comunico
        // que abrimos a las 5»). Se bloquea SALVO que lo que sigue sea, sin duda,
        // información: «que…» o un objeto de INFORMACION (con o sin artículo). Lo
        // desconocido se bloquea: un objeto nuevo da un falso positivo ruidoso y
        // corregible; un traspaso que se cuela («te paso a Carlos», «te paso al
        // entrenador», «te paso su número») llega al cliente en silencio.
        '/\bte\s+(paso|comunico)\b(?!\s*[:,]?\s*(?:'.self::RELLENO.'\s+){0,4}(?:(?:que|cuando|apenas|en\s+cuanto|tan\s+pronto|si)\b|'.self::INFORMACION.'\b))/u',
        // La apertura («que», «si», «cuando»…) no franquea la frase: si más adelante
        // aparece «con» + una persona, es un traspaso («te paso si quieres con la coordinadora»).
        '/\bte\s+(paso|comunico)\b[^.!?]{0,40}\b(con|a|al|donde|para)\s+((el|la|un|una|mi|nuestro|nuestra)\s+)?(?:'.self::PERSONA.'|'.self::ROL.')\b/u',
        // Señuelos: una palabra de la lista blanca cuyo objeto real es una persona
        // («te paso los datos de la asesora»), y el futuro perifrástico o presente
        // de traspaso («te va a llamar», «te contacta una asesora», «para que lo
        // llames»). Recepción y la sede son lugares, no personas: su número es dato.
        '/\b(datos?|informacion|info|contacto|numero|whatsapp|celular|telefono|cel)\s+(de|del)\s+((la|el|un|una|mi|nuestro|nuestra|otro|otra)\s+)?'.self::ROL.'\b/u',
        '/\b(te|le)\s+(va|van)\s+a\s+(llamar|contactar|escribir|marcar)\b/u',
        '/\b(te|le)\s+(contacta|contactan|llama|llaman|escribe|escriben|marca|marcan)\s+((una?|el|la|otra?)\s+)?'.self::ROL.'\b/u',
        '/\bpara\s+que\s+(lo|la|le|los|las)\s+(llames|contactes|escribas|busques|ubiques)\b/u',
        '/\bpara\s+que\s+te\s+(atiendan?|llamen?|contacten?|escriban?)\b/u',
        // Sujeto antepuesto («la coordinadora te escribe hoy») y marcador temporal con
        // nombre propio («Carlos te escribe en un momento»); y entregar TU número a alguien.
        '/\b'.self::ROL.'\s+(te|le)\s+(contacta|llama|escribe|marca|busca|contactara|escribira|llamara|buscara)\b/u',
        '/(?<!\bapp\s)(?<!\baplicacion\s)(?<!\bsistema\s)(?<!\bcrm\s)(?<!\bplataforma\s)(?<!\bbot\s)(?<!\basistente\s)\b(te|le)\s+(escribe|llama|contacta|marca|busca)\s+(en\s+un\s+(momento|rato|ratico)|hoy|mas\s+tarde|ahora|ahorita|enseguida|en\s+breve|luego|manana|esta\s+tarde|en\s+la\s+tarde)\b/u',
        '/\b(le|les)\s+(paso|doy|mando|envio|dejo)\s+tu\s+(numero|contacto|celular|whatsapp|telefono|datos)\b/u',
        '/\bte\s+(voy\s+a\s+)?(pasar|conectar|comunicar)\s+con\b/u',
        '/\b(le|los?|las?)\s+(paso|conecto|comunico)\s+con\b/u',
        '/\bquieres?\s+que\s+te\s+(pase|conecte|comunique|contacte)\s+con\b/u',
        '/\ben\s+un\s+momento\s+te\s+(atender|contactar|escribir|llamar)/u',
        '/\b'.self::PERSONA.'\s+(del\s+equipo\s+)?(te|le)\s+(atendera|contactara|escribira|llamara|explicara|ayudara|dira)/u',
        '/\bpas(o|e|amos|aremos|are)\s+tu\s+(caso|consulta|mensaje|solicitud)\s+(al\s+(equipo|area)|a\s+'.self::PERSONA.')\b/u',
        '/\b(aviso|avisare|avisamos|digo|dire|escribo)\s+a\s+'.self::PERSONA.'\b[^.!?]{0,40}\bpara\s+que\s+te\s+(escriba|llame|contacte|atienda|explique|ayude)\b/u',
        '/\bte\s+(atendera|contactara|escribira|llamara)\s+'.self::PERSONA.'/u',
    ];

    /** El hecho que hoy no existe y que no se puede aplazar en una persona. */
    private const HORARIO = '(horarios?|hora\s+de\s+apertura|horas\s+de\s+apertura|apertura|dias?\s+de\s+apertura)';

    /**
     * Aplazar el HORARIO en una persona, en los dos órdenes en que se dice.
     *
     * «El equipo te confirma el horario» y «el horario lo confirma una persona»
     * son la misma promesa con el sujeto cambiado de sitio, así que van las dos
     * formas. El verbo tiene que ser de DECIR —confirmar, informar, indicar—:
     * «el horario lo pone la administración» no promete que nadie te escriba.
     */
    private const APLAZA_EL_HORARIO = [
        '/\b'.self::PERSONA.'\b[^.!?]{0,40}\b(confirma|confirman|confirmara|confirmaran|dice|dicen|dira|diran|informa|informan|informara|indica|indican|indicara|da|dan|dara|daran)\b[^.!?]{0,40}\b'.self::HORARIO.'\b/u',
        '/\b'.self::HORARIO.'\b[^.!?]{0,40}\b(lo|la|los|las|te\s+lo|te\s+la)\s+(confirma|confirman|confirmara|confirmaran|dice|dicen|dira|diran|informa|informan|informara|indica|indican|indicara|da|dan|dara|daran)\b[^.!?]{0,30}\b'.self::PERSONA.'\b/u',
        '/\b'.self::HORARIO.'\b[^.!?]{0,40}\b(lo|la|los|las)\s+(confirma|confirman|confirmara|dice|dicen|dira|informa|informara|indica|indicara)\b(?![^.!?]{0,30}\b(app|aplicacion|sistema|crm|plataforma)\b)/u',
    ];

    /**
     * Las formas de dar por hecha una visita que nadie ha confirmado.
     *
     * Se buscan las tres que se escriben de verdad: declarar el estado
     * («quedaste agendado», «quedó confirmada»), reservar («te reservé el
     * cupo») y esperar a alguien un día concreto («te esperamos el sábado»).
     *
     * Lo que NO cae aquí es lo honesto: «queda registrada tu solicitud», «el
     * equipo la revisará», «cuando la confirmen te avisan». Decir que algo
     * está anotado es verdad; decir que está confirmado, no.
     */
    /**
     * El cuándo que convierte una despedida en una cita.
     *
     * Sin esto el patrón de «te esperamos» pedía sólo un artículo detrás, y
     * «te esperamos en la sede de la carrera 5» —que no afirma ninguna cita—
     * caía igual que «te esperamos el sábado a las 10». Lo que convierte una
     * despedida en una promesa es el día o la hora, no el artículo.
     */
    private const CUANDO_DE_LA_CITA = '(manana|pasado\s+manana|hoy|lunes|martes|miercoles|jueves|viernes|sabado|domingo|a\s+las\s+\d{1,2})';

    private const AFIRMA_CONFIRMADA = [
        '/\b(qued(as|o|aste|amos)|estas|ya\s+estas)\s+(agendad|confirmad|reservad|anotad[oa]\s+y\s+confirmad)/u',
        '/\b(tu|su|la)\s+(cita|visita|cortesia)\b[^.!?]{0,30}\b(qued(o|a)|esta|fue)\s+(agendad|confirmad|reservad)/u',
        '/\b(te|le)\s+(reserv(e|amos|o)|aparte|apartamos|guarde|guardamos)\b[^.!?]{0,20}\b(cupo|lugar|puesto|espacio|hora)/u',
        '/\b(te|los?|las?)\s+esperamos\b[^.!?]{0,30}\b'.self::CUANDO_DE_LA_CITA.'\b/u',
        '/\bconfirmad[oa]\s+(tu|su)\s+(cita|visita|cortesia)/u',
    ];

    /**
     * La negación que da la vuelta a la afirmación, PEGADA a ella.
     *
     * Va así de corta a propósito. Una ventana ancha de «hay un no por aquí
     * cerca» deja pasar «no hay problema, quedaste agendado para el sábado»,
     * que es justo la mentira que esto persigue: entre el «no» y la afirmación
     * sólo cabe relleno.
     */
    private const NIEGA_LA_CITA = '/\b(no|nunca|tampoco|jamas|sin)(\s+(todavia|aun|ya|aqui|te|le|se|me|lo|la|hemos|he|han|ha|puedo|podemos|voy\s+a|vamos\s+a))*\s*$/u';

    /**
     * La misma negación, pero mirando desde el PARTICIPIO.
     *
     * Hay una forma honesta que la comprobación de arriba no podía ver: «tu
     * visita AÚN NO está confirmada». Ahí el patrón empieza en «tu visita», o
     * sea ANTES de la negación, así que el «no» queda dentro de lo que casa y
     * mirar sólo lo que hay delante no encuentra nada. Se midió: esa frase
     * —que es de las que más queremos que el asesor escriba— se retiraba.
     *
     * Por eso se mira también justo delante del participio, y ahí sí pueden
     * mediar las cópulas. Sigue siendo una ventana pegada y cortada por coma:
     * «tu cita, no te preocupes, quedó confirmada» no se salva, porque su
     * negación pertenece a otra oración.
     */
    private const NIEGA_ANTES_DEL_PARTICIPIO = '/\b(no|nunca|tampoco|jamas)(\s+(todavia|aun|ya|te|le|se|me|lo|la|esta|estas|estan|es|son|fue|va|sera|quedo|queda|quedas|ha|han|hemos))*\s*$/u';

    /**
     * Esperar a alguien, SIN pedir el cuándo.
     *
     * El ancla temporal de arriba existe para distinguir una despedida —«te
     * esperamos en la sede»— de una cita. Pero cuando el CRM ya sabe que hay
     * una solicitud de cortesía sin confirmar, esa distinción sobra: cualquier
     * «te esperamos» habla de ESA visita, y la visita no está confirmada.
     *
     * Por eso este patrón no mira la fecha. Perseguir todas las formas de
     * escribir un día —«el 26», «el 26 de septiembre», «el 26/09», «este 26»,
     * «a la 1»— era una carrera perdida: cada forma nueva es un agujero, y
     * ensanchar la regex a ciegas ya se midió que retira frases honestas («el
     * 1 a 1 con el entrenador», «el 80% de los socios», «el 5 de la carrera
     * 5»). El estado persistido sabe la verdad sin adivinar nada.
     */
    private const AFIRMA_ESPERANDO = [
        '/\b(te|los?|las?)\s+esperamos\b/u',
        // La primera persona del singular. «Te espero el sábado» dice lo mismo
        // que «te esperamos» y se escapaba por el número del verbo.
        '/\bte\s+espero\b/u',
        // «Nos vemos el sábado a las 10» afirma la cita sin nombrarla.
        '/\bnos\s+vemos\b/u',
        /*
         * La PERSONA dada por anotada, en segunda persona.
         *
         * Va con `estas` y no con `queda` a propósito: «queda anotada tu
         * solicitud» es verdad y tiene que salir —lo que está anotado es la
         * SOLICITUD—, mientras que «ya estás anotado» habla de la persona y de
         * una visita que nadie ha confirmado.
         */
        '/\b(ya\s+)?estas\s+(anotad|agendad|apartad|reservad|confirmad)[oa]\b/u',
        // El participio suelto: «Listo, agendado para el sábado». Exige
        // `para`, que es lo que lo ata a una fecha; «dejé anotado el día que
        // me dijiste» no afirma ninguna cita y no cae.
        // `reservad` NO entra aquí: «la zona reservada para funcionales» y
        // «un espacio reservado para el entrenamiento» son frases honestas y
        // frecuentes en un gimnasio. Reservar un CUPO ya lo cazan el patrón de
        // «te reservé … cupo» y el de «tu cupo … apartado», así que quitarlo
        // de esta alternancia cierra el falso positivo sin perder cobertura.
        '/\b(agendad[oa]|anotad[oa]|apartad[oa])\s+para\b/u',
        // «Tu cupo quedó apartado».
        '/\b(tu|su)\s+cupo\b[^.!?]{0,20}\b(qued(o|a)|esta|fue)\s+(apartad|guardad|reservad|confirmad|asegurad)/u',
    ];

    /**
     * ¿El borrador da por confirmada una cortesía? Devuelve la frase, o null.
     *
     * Defensa SECUNDARIA: pide el cuándo, así que sólo cae lo que se parece a
     * una cita. Es la que vale cuando no hay ninguna solicitud de por medio.
     */
    public function courtesyConfirmationIn(string $body): ?string
    {
        return $this->primeraAfirmacionNoNegada($body, self::AFIRMA_CONFIRMADA);
    }

    /**
     * Lo mismo, pero SIN exigir fecha: para cuando hay una solicitud viva.
     *
     * Ésta es la defensa principal, y no depende de haber acertado con el
     * formato de la fecha sino de lo que el CRM tiene escrito.
     */
    public function courtesyClaimIn(string $body): ?string
    {
        return $this->primeraAfirmacionNoNegada(
            $body,
            [...self::AFIRMA_CONFIRMADA, ...self::AFIRMA_ESPERANDO],
        );
    }

    /**
     * La primera afirmación de estado que no venga negada.
     *
     * @param  string[]  $patrones
     */
    private function primeraAfirmacionNoNegada(string $body, array $patrones): ?string
    {
        $t = SalesAgentDecisionSchema::normalize($body);

        foreach ($patrones as $patron) {
            if (preg_match($patron, $t, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            if ($this->citaNegadaAntesDe($t, (int) $m[0][1])) {
                continue;
            }
            if ($this->negadaJustoAntesDelParticipio($t, $m)) {
                continue;
            }

            return trim($m[0][0]);
        }

        return null;
    }

    /**
     * ¿Viene negada esta afirmación?
     *
     * La frase más honesta que el asesor puede escribir —«NO quedaste agendado
     * todavía, falta que el equipo confirme»— lleva dentro las mismas palabras
     * que la mentira. Retirarla no sólo degradaba el turno: enseñaba a no
     * decirla, que es lo contrario de lo que esta guarda persigue.
     *
     * El corte por coma importa tanto como el corte por punto: en «no hay
     * problema, quedaste agendado» la negación pertenece a otra cosa.
     */
    private function citaNegadaAntesDe(string $texto, int $posicion): bool
    {
        $antes = substr($texto, 0, $posicion);

        foreach (['.', '!', '?', ';', ','] as $fin) {
            if (($corte = strrpos($antes, $fin)) !== false) {
                $antes = substr($antes, $corte + 1);
            }
        }

        return preg_match(self::NIEGA_LA_CITA, $antes) === 1;
    }

    /**
     * ¿La negación va dentro de lo que casó, pegada al participio?
     *
     * El último grupo de cada patrón es la palabra que afirma el estado
     * —«agendad», «confirmad», «reservad», «cupo»—. Lo que haya justo delante
     * de ella, dentro de su propia oración, es lo que decide si la frase
     * afirma o niega.
     *
     * @param  array<int, array{0:string,1:int}>  $m
     */
    private function negadaJustoAntesDelParticipio(string $texto, array $m): bool
    {
        $ultimo = end($m);
        if (! is_array($ultimo) || ($ultimo[1] ?? -1) < 0) {
            return false;
        }

        $antes = substr($texto, 0, (int) $ultimo[1]);

        foreach (['.', '!', '?', ';', ','] as $fin) {
            if (($corte = strrpos($antes, $fin)) !== false) {
                $antes = substr($antes, $corte + 1);
            }
        }

        return preg_match(self::NIEGA_ANTES_DEL_PARTICIPIO, $antes) === 1;
    }

    /**
     * Negar el enlace de pago, en las formas en que se niega de verdad.
     *
     * Lo que se busca es la NEGACIÓN de que exista o se pueda dar, no el hecho
     * de mencionar otros medios: «puedes pagar en la app o en el gimnasio» es
     * una enumeración correcta y no debe caer aquí. Por eso todos los patrones
     * exigen un verbo de negación pegado al enlace, dentro de la misma frase
     * —los `[^.!?]` impiden que la negación de una oración se cruce con el
     * enlace de la siguiente—.
     */
    private const ENLACE_DE_COBRO = '(link|enlace|url)s?';

    private const NO_EXISTE = '(contamos\\s+con|tenemos|manejamos|disponemos\\s+de|hay|existe|existen|ofrecemos|trabajamos\\s+con)';

    /**
     * Lo único que cabe entre la negación y el enlace.
     *
     * Aquí había `[^.!?]{0,40}`, y con eso «no hay problema, te paso el link de
     * pago» contaba como negar el cobro. No es un matiz: se midió que con esa
     * frase de cortesía —la más corriente que existe— un turno de «no me
     * interesa por ahora» acuñaba un checkout pagable. El hueco ancho convertía
     * un detector de polaridad en un detector de dos palabras sueltas.
     *
     * Así que entre el verbo negado y el enlace sólo pueden ir determinantes y
     * adverbios de tiempo: lo que acompaña al objeto, nunca OTRO objeto. En
     * cuanto aparece un sustantivo («problema», «afán», «prisa»,
     * «restricción») o una coma, lo negado ya no es el enlace y esto no casa.
     */
    private const SOLO_ACOMPANA = '(\\s+(un|una|unos|unas|el|la|los|las|ningun|ninguna|ningunos|ningunas|todavia|aun|ya|por\\s+ahora|de\\s+momento|actualmente|aqui|por\\s+aqui))*\\s+';

    /** Lo único que cabe entre el enlace y su «no está disponible». */
    private const SOLO_DEMORA = '(\\s+(todavia|aun|ya|por\\s+ahora|de\\s+momento|actualmente))*\\s+';

    private const NIEGA_EL_COBRO = [
        // «no contamos con un link de pago directo por ahora»
        '/\\bno\\s+'.self::NO_EXISTE.self::SOLO_ACOMPANA.self::ENLACE_DE_COBRO.'(\\s+(directos?|de\\s+pagos?))*\\s+de\\s+pagos?\\b/u',
        // «no contamos con un link directo de pago» (el orden inverso)
        '/\\bno\\s+'.self::NO_EXISTE.self::SOLO_ACOMPANA.self::ENLACE_DE_COBRO.'\\s+(directos?|online|virtuales?)\\s+de\\s+pagos?\\b/u',
        // «el link de pago (todavía) no está disponible». El hueco va igual de
        // apretado que en los otros, y por la misma razón medida: con treinta
        // caracteres libres, «el link de pago te llega ya; el parqueadero no
        // está disponible» contaba como negar el cobro. Lo que no está
        // disponible tiene que ser el ENLACE.
        '/\\b'.self::ENLACE_DE_COBRO.'\\s+de\\s+pagos?'.self::SOLO_DEMORA.'no\\s+(esta|estan|estara|estaran)\\s+disponibles?\\b/u',
        // «no manejamos pagos en línea»
        '/\\bno\\s+'.self::NO_EXISTE.self::SOLO_ACOMPANA.'pagos?\\s+(en\\s+linea|online|virtual|virtuales|por\\s+internet)\\b/u',
        // «no puedo enviarte un enlace de pago»
        '/\\bno\\s+(puedo|podemos|se\\s+puede|es\\s+posible)\\s+(enviar|mandar|generar|dar|pasar|compartir)[a-z]{0,4}'.self::SOLO_ACOMPANA.self::ENLACE_DE_COBRO.'(\\s+directos?)?\\s+de\\s+pagos?\\b/u',
    ];

    /**
     * ¿El borrador niega que exista un enlace de pago? Devuelve la frase, o null.
     *
     * Público porque lo consultan dos sitios con propósitos distintos: el
     * enrutado del cobro, que lo toma como prueba de que a la persona le
     * interesaba pagar, y la invariante que impide que salga una respuesta
     * que contradice un hecho del sistema.
     */
    public function checkoutDenialIn(string $body): ?string
    {
        $t = SalesAgentDecisionSchema::normalize($body);

        foreach (self::NIEGA_EL_COBRO as $patron) {
            if (preg_match($patron, $t, $m) === 1) {
                return trim($m[0]);
            }
        }

        return null;
    }

    /**
     * ¿El borrador aplaza el horario en una persona? Devuelve la frase, o null.
     *
     * Público porque el acta del canario cuenta esto igual que cuenta las
     * ofertas de traspaso: si aparece en un turno donde nadie pidió una
     * persona, es un hallazgo.
     */
    public function scheduleDeferralIn(string $body): ?string
    {
        $t = SalesAgentDecisionSchema::normalize($body);

        foreach (self::APLAZA_EL_HORARIO as $patron) {
            if (preg_match($patron, $t, $m) === 1) {
                return trim($m[0]);
            }
        }

        return null;
    }

    /**
     * @param  bool  $handoffAllowed  ¿autorizó el backend pasar a una persona?
     *                                Por defecto NO: el permiso se concede, no
     *                                se presume.
     */
    public function inspect(string $body, string $senderType, bool $handoffAllowed = false): array
    {
        $ok = ['safe' => true, 'code' => null, 'signal' => null, 'risk_flag' => null];

        if (! $this->appliesTo($senderType)) {
            return $ok;
        }

        if (trim($body) === '') {
            return $ok; // un envío vacío lo rechaza la validación del endpoint.
        }

        /*
         * Pedir datos de pago va lo primero porque es lo único de esta lista que
         * le cuesta dinero a la persona, y porque una petición así no mejora con
         * contexto: el cobro vive en el checkout de Wompi, nunca en el chat.
         *
         * Vive AQUÍ, y no solo en el commit de ULTRON, porque `inspect()` es el
         * filtro que comparten las dos puertas por las que sale texto de máquina
         * —`send-message` y el commit— y además el que revisa las respuestas
         * CURADAS, que es la salida que ya se coló una vez por no mirarse.
         */
        if (self::containsCardDataRequest($body)) {
            return [
                'safe' => false,
                'code' => self::CODE_CARD_DATA_REQUEST,
                'signal' => null, // la señal sería el propio texto: no va al log.
                'risk_flag' => 'card_data_request',
            ];
        }

        if (! $handoffAllowed && ($signal = $this->handoffOfferIn($body)) !== null) {
            return [
                'safe' => false,
                'code' => self::CODE_UNAUTHORIZED_HANDOFF,
                'signal' => $signal,
                'risk_flag' => self::CODE_UNAUTHORIZED_HANDOFF,
            ];
        }

        /*
         * El horario que no existe no se aplaza en una persona. Va justo detrás
         * del traspaso porque es la misma promesa vista de lado, y con el mismo
         * permiso: si la persona PIDIÓ hablar con alguien, decir que el equipo
         * le confirma el horario es exactamente lo que toca.
         */
        if (! $handoffAllowed && ($signal = $this->scheduleDeferralIn($body)) !== null) {
            return [
                'safe' => false,
                'code' => self::CODE_SCHEDULE_DEFERRAL,
                'signal' => $signal,
                'risk_flag' => self::CODE_SCHEDULE_DEFERRAL,
            ];
        }

        if ($signal = SalesAgentDecisionSchema::forbiddenSignalIn($body)) {
            return [
                'safe' => false,
                'code' => self::CODE_FORBIDDEN_ACTION,
                'signal' => $signal,
                // Mismo nombre que usa el validador, para poder cruzar ambos en el log.
                'risk_flag' => 'forbidden_action',
            ];
        }

        if ($signal = SalesAgentDecisionSchema::unsafeSignalIn($body)) {
            return [
                'safe' => false,
                'code' => self::CODE_UNSAFE_CLAIM,
                'signal' => $signal,
                'risk_flag' => 'unsafe_claim',
            ];
        }

        if (SalesAgentDecisionSchema::containsPrice($body)) {
            return [
                'safe' => false,
                'code' => self::CODE_INVENTED_PRICE,
                // La señal NO es el precio encontrado: sería meter el contenido
                // del mensaje en el log. Basta con saber qué regla saltó.
                'signal' => null,
                'risk_flag' => 'price_in_reply',
            ];
        }

        /*
         * Una rebaja la declara el negocio, no el modelo. Va justo después del
         * precio porque es la misma decisión vista por el otro lado: el
         * catálogo dice cuánto vale algo, y decir «te lo dejo a mitad de
         * precio» es fijar un precio distinto sin que nadie lo autorice.
         */
        if (preg_match(SalesAgentDecisionSchema::DISCOUNT_PATTERN, $body) === 1) {
            return [
                'safe' => false,
                'code' => self::CODE_INVENTED_DISCOUNT,
                'signal' => null,
                'risk_flag' => 'discount_in_reply',
            ];
        }

        /*
         * Los enlaces los pone Laravel en su propio mensaje, que sale por el
         * despachador sin pasar por aquí. Una URL en un texto de máquina que SÍ
         * pasa por este filtro es, como mínimo, un enlace que nadie verificó.
         */
        if (self::containsUrl($body)) {
            return [
                'safe' => false,
                'code' => self::CODE_URL_IN_REPLY,
                'signal' => null,
                'risk_flag' => 'url_in_reply',
            ];
        }

        return $ok;
    }

    /**
     * Deja pasar el texto o lo detiene.
     *
     * @throws SalesGuardrailException cuando el texto de máquina no puede salir.
     */
    public function assertSafe(string $body, string $senderType, array $context = [], bool $handoffAllowed = false): void
    {
        $result = $this->inspect($body, $senderType, $handoffAllowed);

        if ($result['safe']) {
            return;
        }

        // Nunca se registra el cuerpo: la señal sale de NUESTRO catálogo y la
        // longitud basta para reproducir el caso sin publicar lo que iba a
        // leer una persona.
        ChannelLog::warning('outbound.guard.blocked', array_merge($context, [
            'code' => $result['code'],
            'risk_flag' => $result['risk_flag'],
            'signal' => $result['signal'],
            'sender_type' => $senderType,
            'body_length' => mb_strlen($body),
        ]));

        throw SalesGuardrailException::make(
            $result['code'],
            $this->explain($result['code']),
            escalate: true,
        );
    }

    /**
     * Qué se le dice a quien llamó (n8n o una automatización), no al cliente.
     * Explica la regla y cómo cumplirla; un «contenido no permitido» obligaría
     * a leer este archivo para saber qué cambiar.
     */
    private function explain(string $code): string
    {
        return match ($code) {
            self::CODE_FORBIDDEN_ACTION => 'El mensaje automático menciona una acción que el agente no puede ejecutar '
                .'(activar membresía, aprobar pago o tocar facturación). No se envía. Esas acciones las resuelve una persona.',
            self::CODE_UNSAFE_CLAIM => 'El mensaje automático promete resultados o da un diagnóstico. No se envía. '
                .'Reformúlalo sin garantías ni valoraciones clínicas.',
            self::CODE_UNAUTHORIZED_HANDOFF => 'El mensaje automático ofrece pasar la conversación a una persona '
                .'sin que el backend lo haya autorizado. No se envía.',
            self::CODE_INVENTED_PRICE => 'El mensaje automático incluye una cifra que parece un precio. No se envía. '
                .'Los precios los pone el backend desde el plan activo: manda el texto sin la cifra.',
            default => 'El mensaje automático no cumple las reglas de contenido y no se envía.',
        };
    }

    /** El fragmento donde el texto ofrece pasar a una persona, o null. */
    public function handoffOfferIn(string $body): ?string
    {
        $t = SalesAgentDecisionSchema::normalize($body);

        foreach (self::OFRECE_TRASPASO as $patron) {
            if (preg_match($patron, $t, $m) === 1) {
                return trim($m[0]);
            }
        }

        return null;
    }

    /**
     * ¿Hay una URL, un dominio o «www» en el texto? Las URLs las escribe Laravel
     * —en su propio mensaje o sustituyendo el marcador {{APP_LINKS}} dentro del
     * borrador—; una URL escrita por el modelo es, en el mejor de los casos, una
     * inventada.
     */
    public static function containsUrl(string $body): bool
    {
        // Disfraces habituales: «checkout(.)wompi(.)co», «[.]», «punto», «barra».
        $body = preg_replace(['~\s*[\(\[]\s*\.\s*[\)\]]\s*~u', '~\s+punto\s+~iu', '~\s+barra\s+~iu'], ['.', '.', '/'], $body) ?? $body;

        return preg_match('~(https?://|www\.|\b[a-z0-9-]+\.(com|co|net|org|io|app|cloud|me|ly|link|page|site)(/|\b))~iu', $body) === 1;
    }

    /**
     * El mismo texto con las URLs tapadas, pero LEGIBLE.
     *
     * Hace falta desde que los enlaces de la app viajan dentro de la respuesta:
     * antes, un saliente con URL era un mensaje de Laravel entero y se le
     * enseñaba al modelo como «[enlace enviado por el CRM]». Ahora ese mismo
     * mensaje lleva TAMBIÉN la prosa que escribió el modelo, y taparlo entero
     * le borraría de la memoria lo que acaba de decir: se repetiría.
     *
     * Se tapa token a token con la MISMA definición de URL que usa el guard, y
     * se devuelve null si después de tapar todavía queda algo que parezca una
     * URL. Fail-closed: quien llama se queda entonces con el texto opaco, que
     * es peor de leer pero no filtra un enlace.
     */
    public static function withoutUrls(string $body, string $marca = '[enlace]'): ?string
    {
        $limpio = preg_replace_callback(
            '~\S+~u',
            fn (array $m) => self::containsUrl($m[0]) ? $marca : $m[0],
            $body,
        );

        if ($limpio === null || self::containsUrl($limpio)) {
            return null;
        }

        // «[enlace] · [enlace] · [enlace]» no aporta nada: se dice una vez.
        $q = preg_quote($marca, '~');

        return preg_replace('~'.$q.'(?:\s*[·,;|-]?\s*'.$q.')+~u', $marca, $limpio) ?? $limpio;
    }

    /**
     * Fin de frase. La frase es la UNIDAD de análisis, y no es un detalle.
     *
     * «Pedir» y «afirmar» solo se distinguen dentro de una misma oración: son
     * la misma oración con el verbo cambiado. Si el veredicto se diera sobre el
     * mensaje entero bastaría con saludar hablando del torniquete para pedir la
     * tarjeta en el punto siguiente, y al revés: una petición en el primer
     * renglón condenaría el aviso legítimo del segundo.
     */
    private const FIN_DE_FRASE = '~[.!?;\r\n]+~u';

    /**
     * CREDENCIAL: lo que no se le pide a una persona por este canal. Nunca.
     *
     * Nombrar cualquiera de estos términos NO bloquea —el gimnasio tiene que
     * poder decir cuándo vence un plan o cuántos dígitos tiene una cédula—: lo
     * que bloquea es pedirlo, y eso lo decide {@see self::PETICION}.
     *
     * «Numero» y «datos» NO están aquí a propósito: son demasiado corrientes
     * para valer por sí solos («tu número de cédula», «los datos de la sede») y
     * en toda petición real vienen pegados a un término que sí está («el número
     * de tu TARJETA», «los datos de tu TARJETA»).
     *
     * Sin propiedades Unicode a propósito: solo \b, \s, \d, clases explícitas y
     * lookarounds. El PCRE de producción (10.39) no compila `\p{...}` y el de
     * desarrollo (10.47) sí; una regex que solo falla en el servidor es una
     * regla que no existe.
     */
    private const CREDENCIAL = '~\btarjetas?\b'
        .'|\b(?:cvv|cvc|otp)\b'
        .'|\bpin(?:es)?\b'
        .'|\bclaves?\b|\bcontrasenas?\b'
        .'|\bcodigos?\s+de\s+(?:seguridad|verificacion|confirmacion)\b'
        .'|\bcodigos?\s+(?:dinamico|sms|otp|del\s+banco)\b'
        .'|\bcodigos?\s+que\s+(?:te\s+|le\s+)?llego\b'
        .'|\bdigitos?\b'
        .'|\bnumeracion\b'
        .'|\bfecha\s+de\s+(?:vencimiento|expiracion)\b'
        .'|\bvencimiento\b|\bexpiracion\b'
        .'|\btokens?\b'
        .'~u';

    /**
     * PETICIÓN dirigida a la persona, en las formas en que se pide de verdad.
     *
     * Las tres primeras familias son las del enunciado; la cuarta y la quinta
     * son las dos formas colombianas normales que el ciclo anterior no vio: la
     * pregunta SIN signo de apertura («Me lees la tarjeta por favor») y el «me
     * + verbo en presente» («me confirmas», «me pasas»), que en WhatsApp casi
     * nunca llevan «¿».
     */
    private const PETICION = [
        // (1) Imperativo con el clítico pegado: no admite otra lectura.
        '~\b(?:dime|dimelo|dinos|digame|diganme|dame|danos|deme|denme|demelo|mandame|mandanos|mandeme|enviame|enviame|envienos|envieme|pasame|pasanos|paseme|escribeme|escribenos|escribame|dictame|dicteme|regalame|regaleme|confirmame|confirmanos|confirmeme|comparteme|compartame|facilitame|faciliteme|digitame|digiteme|indicame|indiqueme|traeme|traenos|traigame|muestrame|muestreme|repiteme|repitame|deletreame|anotame|adjuntame|reenviame)\b~u',

        // (2) «Me / nos + verbo en segunda persona»: la pregunta de aquí, lleve
        //     o no signos de interrogación.
        '~\b(?:me|nos)\s+(?:confirmas|das|dictas|lees|pasas|mandas|envias|escribes|dices|compartes|indicas|regalas|digitas|facilitas|muestras|repites|deletreas|anotas|adjuntas|reenvias|traes)\b~u',

        // (3) Cortesía y perífrasis: «¿me puedes dar…?», «tienes que darme…».
        '~\b(?:puedes|podrias|puede|podria|podrian|pudieras|regalarias)\s+(?:dar|darme|darnos|pasar|pasarme|decir|decirme|mandar|mandarme|enviar|enviarme|compartir|compartirme|confirmar|confirmarme|dictar|dictarme|leer|leerme|escribir|escribirme|digitar|indicar|indicarme|facilitar|regalar|regalarme|mostrar|mostrarme|repetir|repetirme|deletrear|adjuntar|reenviar|traer|traerme)\b~u',
        '~\b(?:darme|darnos|pasarme|pasarnos|decirme|decirnos|mandarme|mandarnos|enviarme|enviarnos|compartirme|confirmarme|dictarme|leerme|escribirme|indicarme|facilitarme|regalarme|digitarme|mostrarme|repetirme|deletrearme|anotarme|adjuntarme|reenviarme|traerme|traernos)\b~u',

        // (4) Subjuntivo de segunda persona tras «que»: «necesito que me digas»,
        //     «falta que me mandes».
        '~\bque\s+(?:me|nos)\s+(?:digas|des|pases|mandes|envies|escribas|confirmes|dictes|leas|compartas|indiques|facilites|regales|digites|muestres|repitas|deletrees|anotes|adjuntes|reenvies|traigas)\b~u',

        // (5) Verbo de NECESIDAD en primera persona. Quien necesita es el
        //     asistente, y lo que necesita se lo pide a la persona. En segunda
        //     («necesitas tu documento») es información, no petición: por eso la
        //     lista es de formas de primera persona, no del lema «necesitar».
        '~\b(?:necesito|necesitamos|requiero|requerimos|preciso)\b~u',
        '~\b(?:me|nos)\s+hace\s+falta\b|\bhace\s+falta\s+que\s+(?:me|nos)\b~u',

        // (6) Pregunta de identidad: «¿cuál es…?», «¿cuáles son…?».
        '~\bcual(?:es)?\s+(?:es|son|seria|serian|fue|sera)\b~u',

        // (7) Imperativo DESNUDO, y solo al principio de la frase. En español el
        //     imperativo de tú coincide con la tercera persona del presente, así
        //     que «trae» al principio es una orden y «lo DA recepción» a mitad de
        //     frase no lo es. Restringirlo a la posición inicial es lo que
        //     permite cubrir «trae el número de tu tarjeta» sin bloquear «el
        //     código de seguridad de la puerta lo da recepción».
        '~^(?:(?:y|ya|ahora|ahorita|entonces|bueno|listo|porfa|por\s+favor|hola|ok|ah)\s+|[,\s]+)*'
            .'(?:trae|traiga|manda|mande|envia|envie|escribe|escriba|dicta|dicte|confirma|confirme|comparte|comparta'
            .'|digita|digite|indica|indique|pasa|pase|regala|regale|adjunta|adjunte|reenvia|reenvie|anota|anote'
            .'|deletrea|deletree|muestra|muestre|repite|repita|di|diga)\b~u',
    ];

    /**
     * EXCEPCIÓN ESTRECHA: los objetos FÍSICOS de la sede, que sí se piden.
     *
     * Va atada al SINTAGMA —«tarjeta de acceso», «pin del torniquete», «código
     * de la puerta», «clave del casillero»— y no a que la frase mencione el
     * gimnasio en algún sitio. Esa fue exactamente la puerta por la que se coló
     * «los datos de tu tarjeta de membresía para cobrarte»: bastaba una palabra
     * de gimnasio suelta para franquear la frase entera.
     *
     * Por eso «tarjeta de acceso» está y «tarjeta de socio», «de ingreso» y «de
     * membresía» no: la primera es una pieza de plástico del torniquete, las
     * otras tres son maneras de decir «tarjeta» sin que lo parezca.
     */
    private const OBJETO_FISICO_DEL_GIMNASIO = '~\btarjeta\s+de\s+acceso\b'
        .'|\bpin(?:es)?\s+(?:del|de|para\s+el|para\s+la|en\s+el|en\s+la)\s+(?:el\s+|la\s+)?(?:torniquete|torniquetes|molinete|molinetes|puerta|puertas|casillero|casilleros|locker|lockers)\b'
        .'|\bclaves?\s+(?:del|de|para\s+el|para\s+la)\s+(?:el\s+|la\s+)?(?:casillero|casilleros|locker|lockers|torniquete|torniquetes|puerta|puertas)\b'
        .'|\bcodigos?(?:\s+de\s+seguridad)?\s+(?:del|de|para)\s+(?:la\s+|el\s+)?(?:puerta|puertas|entrada|torniquete|torniquetes|casillero|casilleros|locker|lockers)\b'
        .'|\b(?:digitos|numero|numeros)\s+(?:del|de)\s+(?:la\s+|el\s+)?(?:puerta|torniquete|casillero|locker)\b'
        .'~u';

    /**
     * Verbo de COBRO: con él en la frase, ninguna excepción vale.
     *
     * Un torniquete no cobra. «Tráeme tu tarjeta de acceso para activarla» es
     * recepción; «tráeme tu tarjeta de acceso para cobrarte» es otra cosa con
     * el mismo sintagma delante.
     *
     * Es el cobro HECHO POR NOSOTROS, no la palabra «pagar» en cualquier
     * posición: «cuando vengas a pagar la mensualidad, trae tu tarjeta de
     * acceso» es una frase legítima de gimnasio y el sujeto que paga es la
     * persona, no el asistente.
     */
    private const VERBO_DE_COBRO = '~\bcobrar(?:te|le|lo|la|nos|se)?\b|\bcobros?\b|\bcobrando\b'
        .'|\bdebitar(?:te|le|lo)?\b|\bdescontar(?:te|le|lo)?\b'
        .'|\bprocesar(?:lo|la|te|le)?\b'
        .'|\b(?:registrar|tomar|recibir|aplicar|hacer|realizar|generar|adelantar)(?:te|le|lo|la)?\s+(?:el\s+|tu\s+|su\s+|la\s+)?(?:pago|cobro|transaccion)\b'
        .'|\bpara\s+(?:el\s+|tu\s+|su\s+)?(?:pago|cobro)\b'
        .'~u';

    /**
     * ¿El texto le PIDE a la persona una credencial? Eso es lo que se bloquea.
     *
     * EL CRITERIO, Y POR QUÉ ES ESTE. Después del punto 11 el asistente ya tiene
     * todos los hechos de la membresía —fecha de fin, días restantes, estado,
     * plan— en `context.membership`, servidos por el CRM; y no cobra nunca por
     * el chat, porque el cobro vive en el checkout de Wompi. De ahí se sigue
     * algo que cierra el problema de raíz: el asistente NO NECESITA pedirle a
     * nadie un número, un código, una clave, una fecha ni una tarjeta. Jamás.
     * Si una respuesta lo pide, o es cosecha de datos o es una respuesta que
     * igualmente no debería salir. En los dos casos se para aquí.
     *
     * Por eso se mira la POLARIDAD y no el vocabulario. Los dos intentos
     * anteriores fallaron por lo mismo: una lista blanca de palabras no puede
     * separar «la fecha de vencimiento de tu membresía es el 30» de «dime la
     * fecha de vencimiento de tu plan». Comparten todo el léxico y son cosas
     * opuestas; lo único que las distingue es que una AFIRMA y la otra PIDE.
     *
     * De ahí la forma, que es la inversa de la de antes:
     *
     *  (a) Se trabaja POR FRASE, que es donde pedir y afirmar se distinguen.
     *  (b) Bloquea si en la MISMA frase hay una credencial y una petición
     *      dirigida a la persona.
     *  (c) Deja pasar las afirmaciones aunque usen ese mismo vocabulario: el
     *      gimnasio tiene que poder decir cuándo vence un plan, qué es el PIN
     *      del torniquete o cuántos dígitos tiene un documento.
     *  (d) Excepción estrecha para PEDIR objetos físicos de la sede, atada al
     *      sintagma y no al hecho de que la frase nombre el gimnasio.
     *  (e) Con un verbo de cobro en la frase, ninguna excepción vale.
     *
     * La excepción se resuelve POR OCURRENCIA y no por frase, y esa es la
     * pieza que desarma el fallo del ciclo 1. Allí la lista blanca BORRABA el
     * tramo del gimnasio antes de buscar, así que «necesito el pin de acceso de
     * tu tarjeta» se quedaba sin «pin de acceso» y salía limpio: la excepción
     * desarmaba la petición que la contenía. Aquí no se borra nada; cada
     * mención de credencial se mira por separado y basta con que UNA quede
     * fuera de todo sintagma exento para bloquear. En esa frase «tarjeta» queda
     * fuera, y con eso sobra.
     */
    public static function containsCardDataRequest(string $body): bool
    {
        // Mismo normalizado que el resto del guard: minúsculas y sin tildes.
        $texto = SalesAgentDecisionSchema::normalize($body);

        foreach (preg_split(self::FIN_DE_FRASE, $texto) ?: [] as $frase) {
            $frase = trim($frase);

            if ($frase === '' || ! self::pideALaPersona($frase)) {
                continue;
            }

            if (self::credencialSinCoartada($frase)) {
                return true;
            }
        }

        return false;
    }

    /** ¿La frase le pide algo a la persona, en vez de contarle algo? */
    private static function pideALaPersona(string $frase): bool
    {
        foreach (self::PETICION as $patron) {
            if (preg_match($patron, $frase) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Queda en la frase alguna credencial que el gimnasio no justifique?
     *
     * Se comparan POSICIONES, no presencias: una credencial solo está excusada
     * si cae DENTRO de un sintagma de objeto físico de la sede. «Pin de acceso
     * de tu tarjeta» tiene dos menciones y ningún sintagma exento que las
     * cubra; «los datos de tu tarjeta de acceso» tiene una, y cae dentro.
     */
    private static function credencialSinCoartada(string $frase): bool
    {
        if (preg_match_all(self::CREDENCIAL, $frase, $hallazgos, PREG_OFFSET_CAPTURE) < 1) {
            return false;
        }

        $exentos = preg_match(self::VERBO_DE_COBRO, $frase) === 1
            ? []                        // (e) si la frase cobra, no hay coartada.
            : self::tramosDelGimnasio($frase);

        foreach ($hallazgos[0] as [$termino, $inicio]) {
            $fin = $inicio + strlen($termino);

            foreach ($exentos as [$desde, $hasta]) {
                if ($inicio >= $desde && $fin <= $hasta) {
                    continue 2;         // esta mención la explica el gimnasio.
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Tramos [inicio, fin) del texto ocupados por un objeto físico de la sede.
     *
     * @return list<array{0:int,1:int}>
     */
    private static function tramosDelGimnasio(string $frase): array
    {
        if (preg_match_all(self::OBJETO_FISICO_DEL_GIMNASIO, $frase, $hallazgos, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }

        return array_map(
            static fn (array $h): array => [(int) $h[1], (int) $h[1] + strlen((string) $h[0])],
            $hallazgos[0],
        );
    }
}
