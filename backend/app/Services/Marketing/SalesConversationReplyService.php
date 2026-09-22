<?php

namespace App\Services\Marketing;

use App\Models\Plan;
use App\Services\Marketing\Ultron\GymFactsProvider;

/**
 * Construye la respuesta HUMANA sugerida para cada intención. Textos curados,
 * comerciales y SEGUROS: nunca incluyen precios inventados, ni prometen
 * resultados físicos garantizados, ni dan diagnósticos médicos. El precio real
 * (cuando se comparte) sale SIEMPRE del plan activo del backend.
 */
class SalesConversationReplyService
{
    /** Dirección OFICIAL de Iron Body Neiva (provista por el negocio). */
    public const ADDRESS = 'Cl. 24 Sur #33-53, Neiva, Huila';

    public function __construct(
        private readonly SalesObjectionResponderService $objections = new SalesObjectionResponderService,
        private readonly GymFactsProvider $gym = new GymFactsProvider,
    ) {}

    /**
     * Respuesta sugerida (curada) para una intención. null = no responder.
     *
     * Tono de ASESOR HUMANO de Iron Body: cercano, tranquilo, breve y cálido.
     * Estructura: (1) reconoce lo que dijo, (2) ayuda/orienta concreto,
     * (3) MÁXIMO una pregunta pequeña. Nada de presión, urgencia, promesas de
     * resultados, ni listas robóticas de beneficios. Primero entiende y cuida;
     * vende solo cuando hay intención clara.
     */
    public function replyFor(string $intent, array $context = []): ?string
    {
        // Objeciones / pena / inseguridad: manejador dedicado (validar + cuidar).
        if ($this->objections->isObjection($intent)) {
            return $this->objections->reply($intent);
        }

        return match ($intent) {
            /*
             * Un saludo solo se contesta con saludo, bienvenida y una apertura
             * humana. Decía «¿Buscas información de planes, ubicación o quieres
             * empezar con algún objetivo?»: un menú y una pregunta de objetivo
             * a quien solo dijo «hola», que es lo que hace sonar a máquina.
             * La pregunta va sin verbo de ofrecimiento (ver `lastResorts`).
             */
            SalesIntents::GREETING => 'Hola, bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos '
                .'ayudar hoy?',

            SalesIntents::PRICING_QUESTION => 'Sí, claro. Para recomendarte mejor, ¿quieres empezar por bajar grasa, ganar masa '
                .'o simplemente coger hábito?',

            SalesIntents::PAYMENT_LINK_REQUEST, SalesIntents::HIGH_INTENT_CLOSE => $this->paymentPendingReply(),

            SalesIntents::GOAL_FAT_LOSS => 'Listo. Para bajar grasa lo más importante es empezar con algo que puedas sostener. '
                .'¿Ya vienes entrenando o arrancas desde cero?',

            SalesIntents::GOAL_MUSCLE_GAIN => 'Perfecto. Para ganar masa lo clave es entrenar constante y con buena guía. '
                .'¿Ya has entrenado antes o estás empezando?',

            SalesIntents::GOAL_RECOMPOSITION => 'Eso se puede trabajar como recomposición corporal: ganar músculo y bajar grasa '
                .'poco a poco. Lo clave es hacerlo con constancia y buena guía. '
                .'¿Ya has entrenado antes o estás empezando?',

            SalesIntents::LOCATION_QUESTION => 'Estamos en '.self::ADDRESS.'. ¿Vas a ir por primera vez?',

            /*
             * Ni se inventa el horario ni se promete una persona que nadie ha
             * autorizado. Decía «eso lo confirma una persona del equipo», y en
             * WhatsApp eso se lee como «espera, que te escriben»: no escribe
             * nadie, porque derivar exige que lo pida la persona.
             *
             * Lo honesto es decir que ese dato no está confirmado y ofrecer lo
             * que sí existe: las clases, que llevan día y hora reales. Y si la
             * base de conocimiento SÍ declara el horario, se dice ese: negarlo
             * teniéndolo era mentir por escrito.
             */
            SalesIntents::SCHEDULE_QUESTION => $this->scheduleReply(),

            /*
             * NO NOMBRA UN PLAN, Y ESO ES EL ARREGLO.
             *
             * Decía «el mensual te sirve para entrenar constante» y preguntaba
             * el objetivo. Salió tal cual en una prueba física sobre una
             * conversación recién creada: la persona pidió información del
             * gimnasio y recibió un plan concreto que nadie había elegido
             * —`recommended_plan_id` iba en null— más una pregunta de
             * descubrimiento que no había pedido. Parecía contaminación de
             * memoria y no lo era: era este texto, escrito aquí.
             *
             * Quien pide información general recibe información general y UNA
             * puerta abierta. No se nombra ningún plan: el catálogo lo sirve el
             * CRM cuando la conversación llegue ahí, y adelantarlo es empezar a
             * vender a quien todavía está mirando.
             *
             * Tampoco afirma hechos que este texto no tiene forma de comprobar
             * (horarios, clases). Enumeraba «planes, horarios, ubicación,
             * clases o lo que necesites», y ese menú es lo que hace sonar a
             * máquina: ahora presenta y deja UNA pregunta abierta, sin verbo
             * de ofrecimiento (ver `lastResorts`).
             */
            SalesIntents::GENERAL_INFO => 'Con gusto te cuento. Iron Body es un gimnasio en Neiva donde entrenas con '
                .'acompañamiento y un espacio preparado para avanzar en tu objetivo, seas principiante o ya vengas '
                .'entrenando. ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?',

            SalesIntents::THANKS => 'Con gusto. Si después quieres empezar o comparar planes, me escribes y te ayudo '
                .'sin problema.',

            SalesIntents::NOT_INTERESTED => 'Listo, tranquilo. No hay problema. Si después te animas o solo quieres resolver '
                .'dudas, aquí te ayudamos.',

            // Sin ofrecer traspaso: la persona puede pedirlo, la máquina no lo
            // propone. Esta respuesta sale por el camino del critic fallido,
            // que no pasa por el guard de salida, así que el texto tiene que
            // ser correcto por sí mismo.
            SalesIntents::BOT_QUESTION => 'Soy el asistente automático de Iron Body y te puedo ayudar con información '
                .'inicial. Si prefieres hablar con una persona del equipo, solo dímelo.',

            SalesIntents::HUMAN_REQUEST => 'Claro, dejo marcada tu solicitud para que alguien del equipo la revise. Igual sigo '
                .'por aquí si quieres que te ayude con precios, ubicación o planes.',

            SalesIntents::COMPLAINT => 'Entiendo. Lo dejo marcado como caso para revisión del equipo. Para ayudarte mejor, '
                .'¿me cuentas qué pasó exactamente?',

            SalesIntents::INVOICE_REQUEST => 'Claro. Para factura necesito que el equipo confirme los datos correctos para no '
                .'cometer errores. Te dejo marcada la solicitud de facturación. Si quieres, también '
                .'puedo ayudarte con información de planes o ubicación.',

            SalesIntents::MEDICAL_RISK_ESCALATION => 'Gracias por contarlo. Con una lesión es mejor no recomendar ejercicios específicos '
                .'por aquí para no darte una orientación irresponsable. Te dejo marcado que necesitas '
                .'revisión del equipo, y mientras tanto puedo ayudarte con información general de '
                .'planes o ubicación.',

            SalesIntents::FRAUD_OR_PAYMENT_CLAIM => 'Tranquilo, lo dejo marcado para que el equipo revise tu caso de pago con cuidado. La '
                .'membresía solo queda activa cuando el pago está confirmado. ¿Quieres que te ayude '
                .'con algo más mientras tanto?',

            SalesIntents::GOODBYE => 'De una, tranquilo. Si más adelante quieres empezar o resolver dudas, me escribes y '
                .'te ayudo.',

            SalesIntents::DO_NOT_CONTACT_REQUEST => null, // respetamos: no insistimos.

            SalesIntents::SPAM_LOW_QUALITY => null, // no enganchamos con mensajes sin contenido.

            default => 'No quiero responderte cualquier cosa. ¿Te refieres a los planes, horarios o '
                .'ubicación?',
        };
    }

    /**
     * Mensaje para cuando hay intención de pago.
     *
     * Decía que el equipo confirmaría el medio correcto, y eso convertía una
     * BANDERA APAGADA en una política de negocio falsa. Son dos cosas distintas
     * y hay que separarlas:
     *
     *  - Lo que está apagado es el LINK DE PAGO AUTOMÁTICO por WhatsApp
     *    (`marketing.ultron.payment_links_enabled`). Es una capacidad del
     *    canario, reversible con una variable de entorno.
     *  - Lo que NO existe es un proceso manual en el que alguien del equipo
     *    «confirma el medio». Nunca existió. Pagar es algo que la persona hace
     *    sola: desde la app, con Wompi, o en el mostrador cuando viene.
     *
     * Decir lo primero como si fuera lo segundo deja a alguien esperando un
     * mensaje que no va a llegar, y ése es el peor daño que puede hacer un
     * asistente comercial. Aquí se dicen los DOS caminos que sí existen y
     * ninguno de los dos promete a nadie.
     *
     * Qué NO dice, a propósito: no afirma que el pago quede confirmado ni que
     * la membresía quede activa —ese hecho es del CRM, no de esta frase—, no
     * pide datos de tarjeta y no escribe URLs (los enlaces los pone Laravel por
     * el marcador `{{APP_LINKS}}` o por su herramienta).
     */
    public function paymentPendingReply(): string
    {
        return 'El pago lo haces tú mismo desde la app Iron Body Workout: creas tu cuenta con tu '
            .'documento y ahí pagas con Nequi, PSE, tarjeta o Daviplata. También puedes pagar en '
            .'el gimnasio cuando vengas. ¿Quieres que te pase la app para descargarla?';
    }

    /**
     * Respuesta SEGURA para un caso sensible que no encaja en una intención
     * concreta: la IA ayuda hasta donde puede y deja la parte puntual marcada para
     * revisión del equipo, SIN cortar la conversación. La IA nunca se apaga.
     */
    public function staffReviewReply(): string
    {
        return 'Te ayudo con lo que puedo por aquí. Para esa parte puntual, lo dejo marcado para '
            .'revisión del equipo, pero seguimos si tienes otra duda.';
    }

    /**
     * Respuesta DETERMINISTA de precio: tono natural, precio REAL de la DB y UNA
     * pregunta para entender a la persona. NO lista beneficios robóticos, NO ofrece
     * link, NO empuja el pago. Si no hay plan activo, NO inventa precio: pregunta
     * el objetivo.
     */
    public function pricingReply(?Plan $plan): string
    {
        if ($plan === null) {
            return (string) $this->replyFor(SalesIntents::PRICING_QUESTION);
        }

        $price = $this->formatCop((float) $plan->price);

        return "Sí, claro. El {$plan->name} está en {$price}. "
            .'¿Ya has entrenado antes o vas arrancando desde cero?';
    }

    /**
     * Cierre suave de despedida: deja valor (precio + ubicación) y puerta abierta,
     * SIN acosar. Se envía como máximo una vez (lo controla el orquestador).
     */
    public function goodbyeReply(?Plan $plan): string
    {
        if ($plan !== null) {
            $price = $this->formatCop((float) $plan->price);

            return 'De una, tranquilo. Te dejo el dato por si lo quieres mirar después: el '
                ."{$plan->name} está en {$price} y estamos en ".self::ADDRESS.'. Si más adelante '
                .'quieres empezar, me escribes y te ayudo.';
        }

        return (string) $this->replyFor(SalesIntents::GOODBYE);
    }

    /** Frases que OFRECEN un link de pago (prohibidas si Wompi no es productivo). */
    private const LINK_OFFER_PHRASES = [
        'link seguro', 'te envio el link', 'te envío el link', 'envie el link', 'envíe el link',
        'link de pago', 'pagar por aqui', 'pagar por aquí', 'pagar por link', 'te paso el link',
        'link para pagar', 'mando el link', 'mandar el link', 'el link', 'por link',
    ];

    /** ¿El texto OFRECE/menciona un link de pago? (para el guardrail Wompi). */
    public function offersLink(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $needle = $this->normalize($text);
        foreach (self::LINK_OFFER_PHRASES as $p) {
            if (str_contains($needle, $this->normalize($p))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Elimina del texto cualquier oración que ofrezca un link de pago (cuando
     * Wompi no es productivo). Garantiza que quede al menos una pregunta de cierre
     * sin mencionar links.
     */
    public function scrubLinkOffer(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
        $kept = array_values(array_filter(
            $sentences,
            fn ($s) => trim($s) !== '' && ! $this->offersLink($s),
        ));

        $out = trim(implode(' ', $kept));
        if ($out === '') {
            $out = 'Con gusto te oriento con lo que necesites.';
        }
        if (! str_contains($out, '?')) {
            $out .= ' ¿Quieres que te explique los planes?';
        }

        return $out;
    }

    /** Términos que delatan un CTA/empuje de pago (para intenciones que no deben pagar aún). */
    private const PAYMENT_CTA_TERMS = ['pago', 'pagar', 'link', 'medio de pago', 'proceso de compra'];

    /** ¿El texto empuja el pago (CTA de pago)? */
    public function mentionsPaymentCta(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $needle = $this->normalize($text);
        foreach (self::PAYMENT_CTA_TERMS as $t) {
            if (str_contains($needle, $this->normalize($t))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Elimina del texto cualquier oración que empuje el pago y garantiza un cierre
     * suave (sin pago). Usado para intenciones que NO deben llevar a pago todavía
     * (ubicación, miedo de principiante, objeción de precio).
     */
    public function scrubPaymentCta(?string $text, string $softClosing): ?string
    {
        if ($text === null) {
            return null;
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
        $kept = array_values(array_filter(
            $sentences,
            fn ($s) => trim($s) !== '' && ! $this->mentionsPaymentCta($s),
        ));

        $out = trim(implode(' ', $kept));
        if ($out === '') {
            $out = 'Con gusto te oriento.';
        }
        if (! str_contains($out, '?')) {
            $out .= ' '.$softClosing;
        }

        return $out;
    }

    private function normalize(string $s): string
    {
        $lower = mb_strtolower(trim($s));

        return strtr($lower, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    /** Formato de precio en COP sin decimales: 80000 → "$80.000 COP". */
    public function formatCop(float $amount): string
    {
        return '$'.number_format($amount, 0, ',', '.').' COP';
    }

    /** Mensaje neutro de espera cuando se fuerza un escalado a humano. */
    public function escalationReply(): string
    {
        return 'Para ayudarte bien con esto, te va a atender una persona del equipo en un momento. '
            .'Gracias por tu paciencia.';
    }

    /**
     * Mensaje humano corto que acompaña un link de pago. El precio es el REAL
     * del plan activo (nunca inventado).
     */
    public function paymentLinkMessage(Plan $plan, float $amount, string $url): string
    {
        $price = '$'.number_format($amount, 0, ',', '.').' COP';

        return "¡Hola! 💪 Aquí tienes tu link para activar tu membresía {$plan->name} ({$price}) "
            .'en Iron Body Neiva. Pagas seguro desde acá y tu acceso queda listo al confirmarse '
            ."el pago: {$url}";
    }

    /**
     * LA BIENVENIDA, cuando el modelo no la dio.
     *
     * Un saludo puro con el critic caído o con el borrador descartado por
     * vender caía en los últimos recursos genéricos («dime qué necesitas…»):
     * quien dijo «hola» recibía un mostrador, no una persona. Estas son las
     * variantes de recepción: devuelven el saludo de la franja, dan la
     * bienvenida a Iron Body y abren, sin planes, precios ni objetivo. Si ya
     * saludamos en esta conversación, se recibe de nuevo sin volver a dar la
     * bienvenida. Ninguna cierra con verbo de ofrecimiento (ver `lastResorts`).
     *
     * @param  string  $saludoFranja  «buenos dias», «buenas tardes», «buenas noches»
     * @return string[] de más completa a más mínima; nunca vacío
     */
    public function welcomeReplies(string $saludoFranja, bool $yaSaludado): array
    {
        // La franja llega sin tildes (así cruza al modelo); a la persona se le
        // escribe en español correcto.
        $franja = strtr(trim($saludoFranja) !== '' ? trim($saludoFranja) : 'hola', ['buenos dias' => 'buenos días']);

        if ($yaSaludado) {
            return [
                'Hola de nuevo, muy '.$franja.'. Cuéntame, ¿en qué te podemos ayudar hoy?',
                'Hola, aquí seguimos. Cuéntame qué necesitas y te acompaño.',
                'Muy '.$franja.'. Dime en qué te ayudo hoy.',
            ];
        }

        return [
            'Hola, muy '.$franja.'. Bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos ayudar hoy?',
            'Hola, '.$franja.'. Qué gusto tenerte por aquí: bienvenido a Iron Body. Cuéntame, ¿en qué te ayudo hoy?',
            'Muy '.$franja.', bienvenido a Iron Body. Cuéntame qué necesitas y te acompaño.',
        ];
    }

    /**
     * El horario, si la base de conocimiento lo declara; si no, la honestidad
     * de antes. La pregunta va sin verbo de ofrecimiento (ver `lastResorts`).
     * Sin aplicación ni base de datos (los textos se leen también en pruebas
     * unitarias puras) no hay horario que afirmar, y se cae a la rama honesta.
     */
    private function scheduleReply(): string
    {
        try {
            $horario = $this->gym->openingHours();
        } catch (\Throwable) {
            $horario = null;
        }
        if (is_array($horario) && $horario !== []) {
            // Cada línea de la ficha cierra con punto antes de la pregunta.
            $lineas = array_map(fn ($l) => rtrim(trim((string) $l), '.').'.', $horario);

            return 'Claro. '.implode(' ', $lineas).' ¿En qué franja te queda mejor entrenar?';
        }

        return 'No tengo un horario general confirmado en mi información, y prefiero no darte '
            .'uno equivocado. Si quieres, pregúntame por una clase en concreto y te digo el día y la hora que tengo '
            .'registrados; también te ayudo con planes o ubicación.';
    }

    /**
     * LO QUE SE DICE CUANDO NO QUEDA NADA MÁS QUE DECIR.
     *
     * El texto curado de arriba es la primera red. Tenía un agujero que se vio
     * en producción: si ya se había usado en la conversación, la guarda
     * antirrepetición lo anulaba y el turno salía MUDO. Tres mensajes seguidos
     * sin respuesta, y el vigía en verde porque la fila existía. Un último
     * recurso que sólo puede dispararse una vez por conversación no es un
     * último recurso.
     *
     * Así que aquí hay varias formas de decir lo mismo, de la más útil a la
     * más mínima, para que repetirse nunca sea la única salida. Ninguna nombra
     * un plan ni afirma un hecho que no se pueda comprobar: enumeran lo que se
     * puede preguntar, que es cierto por construcción.
     *
     * Y si al final todas se han usado, se repite la última. Repetirse es un
     * defecto de estilo; callarse es dejar a una persona esperando.
     *
     * @return string[] de más útil a más mínima; nunca vacío
     */
    public function lastResorts(string $intent): array
    {
        /*
         * NINGUNA CIERRA CON UN VERBO DE OFRECIMIENTO, Y ES A PROPÓSITO.
         *
         * «¿Qué te gustaría…?» y «¿prefieres…?» los lee el detector de ofertas
         * como una OFERTA del agente, y entonces este texto de relleno pisaba
         * la oferta viva de la conversación: un «sí, por favor» posterior
         * dejaba de resolverse contra «te explico cómo empezar» y pasaba a
         * resolverse contra un ofrecimiento genérico. Lo cazó el arco de
         * memoria. Un texto que pregunta qué necesitas no ofrece nada, y no
         * tiene por qué borrar lo que sí se ofreció.
         */
        // Y NINGUNA ENUMERA UN MENÚ: «puedes preguntarme por planes, horarios,
        // ubicación, clases…» es la frase de máquina que la persona reconoce.
        // Pero la puerta sigue abierta: quien pidió información y recibe este
        // respaldo tiene que poder seguir con una pregunta, no con un punto.
        $generales = [
            'Claro, con gusto te ayudo. Cuéntame, ¿qué necesitas saber del gimnasio?',
            'Con gusto. ¿Qué te cuento primero del gimnasio?',
            'Cuéntame qué necesitas saber del gimnasio y te ayudo.',
        ];

        /*
         * Y LO SENSIBLE NO RECIBE UN MENÚ DE VENTAS.
         *
         * El `default` mandaba el menú comercial a las cinco intenciones que
         * nunca deberían verlo. Medido: a «me cobraron dos veces», a una queja
         * o a una lesión se les contestaba «puedes preguntarme por planes,
         * horarios, ubicación, clases…». La persona llega igual al equipo
         * —{@see StaffReviewAuthority} levanta la marca por intención— pero el
         * texto que lee mientras tanto le dice que no la han escuchado.
         *
         * Éstas SÍ pueden prometer que alguien lo mira, porque en estas cinco
         * la marca se pone de verdad. Es la única familia donde esa frase no
         * es una promesa vacía.
         */
        $sensibles = [
            /*
             * NO PROMETEN LA MARCA, Y ES A PROPÓSITO.
             *
             * Decían «queda registrado para que el equipo lo revise», y la
             * invariante de promesas clasifica eso como efecto durable: si el
             * turno no pide `staff_review`, el commit muere en 422 y la
             * persona se queda sin nada. Justo en las cinco intenciones donde
             * el silencio duele más.
             *
             * Dicen lo que es cierto sin comprometer a nadie: que esto no lo
             * resuelve una máquina. La marca, cuando toca, la pone la autoridad
             * de revisión por su cuenta.
             */
            'Entiendo, y lo siento. Esto lo revisa una persona del equipo, no yo.',
            'Entiendo. Esto no lo resuelvo yo: lo ve una persona del equipo.',
        ];

        return match ($intent) {
            SalesIntents::COMPLAINT,
            SalesIntents::FRAUD_OR_PAYMENT_CLAIM,
            SalesIntents::MEDICAL_RISK_ESCALATION,
            SalesIntents::HUMAN_REQUEST,
            SalesIntents::INVOICE_REQUEST => $sensibles,
            /*
             * Quien pregunta por el precio o quiere empezar no se merece un
             * menú: se le dice que de eso se habla aquí y se le deja seguir.
             * Sigue sin nombrar plan ni cifra, que es lo que no puede salir de
             * un texto escrito a mano.
             */
            SalesIntents::PRICING_QUESTION => [
                'Con gusto te paso la información de los planes. Dime si empiezas este mes o estás comparando opciones.',
                ...$generales,
            ],
            SalesIntents::LOCATION_QUESTION => [
                'Con gusto te ayudo con la ubicación y cómo llegar. Dime si necesitas también horarios o planes.',
                ...$generales,
            ],
            default => $generales,
        };
    }
}
