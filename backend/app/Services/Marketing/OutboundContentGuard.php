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
     * ¿Hay una URL, un dominio o «www» en el texto? Los links (pago, app) los pone
     * Laravel en un mensaje propio; una URL escrita por el modelo es, en el mejor
     * de los casos, una inventada.
     */
    public static function containsUrl(string $body): bool
    {
        // Disfraces habituales: «checkout(.)wompi(.)co», «[.]», «punto», «barra».
        $body = preg_replace(['~\s*[\(\[]\s*\.\s*[\)\]]\s*~u', '~\s+punto\s+~iu', '~\s+barra\s+~iu'], ['.', '.', '/'], $body) ?? $body;

        return preg_match('~(https?://|www\.|\b[a-z0-9-]+\.(com|co|net|org|io|app|cloud|me|ly|link|page|site)(/|\b))~iu', $body) === 1;
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
