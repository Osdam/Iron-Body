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

    public const CODE_UNSAFE_CLAIM = 'machine_reply_unsafe_claim';

    public const CODE_INVENTED_PRICE = 'machine_reply_invented_price';

    /** Ofrece pasar la conversación a una persona sin que nadie lo haya autorizado. */
    public const CODE_UNAUTHORIZED_HANDOFF = 'machine_reply_unauthorized_handoff';

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
    private const INFORMACION = '(links?|enlaces?|urls?|datos?|informacion|info|direccion|ubicacion|mapa|horarios?|precios?|valor|detalles?|lista|resumen|pasos?|beneficios?|planes?|opciones?|comparativa|catalogo|fotos?|videos?|documentos?|formatos?|requisitos|pdfs?|instrucciones|whatsapp|numero|celular|telefono|contacto)';

    /** Lo que puede ir entre «te paso» y el objeto sin cambiar el sentido: artículos, adverbios, cuantificadores. */
    private const RELLENO = '(el|la|los|las|un|una|unos|unas|este|esta|estos|estas|ese|esa|mi|tu|nuestro|nuestra|otro|otra|ya|ahora|ahorita|aqui|aca|enseguida|rapido|rapidito|tambien|mas|toda|todo|todos|todas|dos|tres|un\s+par\s+de|por\s+aca|por\s+aqui|de\s+una|de\s+inmediato|entonces|mejor|primero|luego|igual)';

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
        '/\bte\s+(paso|comunico)\b(?!\s*[:,]?\s*(?:'.self::RELLENO.'\s+){0,4}(?:que\b|'.self::INFORMACION.'\b))/u',
        // Señuelos: una palabra de la lista blanca cuyo objeto real es una persona
        // («te paso los datos de la asesora»), y el futuro perifrástico o presente
        // de traspaso («te va a llamar», «te contacta una asesora», «para que lo
        // llames»). Recepción y la sede son lugares, no personas: su número es dato.
        '/\b(datos?|informacion|info|contacto|numero|whatsapp|celular|telefono|cel)\s+(de|del)\s+((la|el|un|una|mi|nuestro|nuestra|otro|otra)\s+)?'.self::ROL.'\b/u',
        '/\b(te|le)\s+(va|van)\s+a\s+(llamar|contactar|escribir|marcar)\b/u',
        '/\b(te|le)\s+(contacta|contactan|llama|llaman|escribe|escriben|marca|marcan)\s+((una?|el|la|otra?)\s+)?'.self::ROL.'\b/u',
        '/\bpara\s+que\s+(lo|la|le|los|las)\s+(llames|contactes|escribas|busques|ubiques)\b/u',
        '/\bpara\s+que\s+te\s+(atiendan?|llamen?|contacten?|escriban?)\b/u',
        '/\bte\s+(voy\s+a\s+)?(pasar|conectar|comunicar)\s+con\b/u',
        '/\b(le|los?|las?)\s+(paso|conecto|comunico)\s+con\b/u',
        '/\bquieres?\s+que\s+te\s+(pase|conecte|comunique|contacte)\s+con\b/u',
        '/\ben\s+un\s+momento\s+te\s+(atender|contactar|escribir|llamar)/u',
        '/\b'.self::PERSONA.'\s+(del\s+equipo\s+)?(te|le)\s+(atendera|contactara|escribira|llamara|explicara|ayudara|dira)/u',
        '/\bpas(o|e|amos|aremos|are)\s+tu\s+(caso|consulta|mensaje|solicitud)\s+(al\s+(equipo|area)|a\s+'.self::PERSONA.')\b/u',
        '/\b(aviso|avisare|avisamos|digo|dire|escribo)\s+a\s+'.self::PERSONA.'\b[^.!?]{0,40}\bpara\s+que\s+te\s+(escriba|llame|contacte|atienda|explique|ayude)\b/u',
        '/\bte\s+(atendera|contactara|escribira|llamara)\s+'.self::PERSONA.'/u',
    ];

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

        if (! $handoffAllowed && ($signal = $this->handoffOfferIn($body)) !== null) {
            return [
                'safe' => false,
                'code' => self::CODE_UNAUTHORIZED_HANDOFF,
                'signal' => $signal,
                'risk_flag' => self::CODE_UNAUTHORIZED_HANDOFF,
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
}
