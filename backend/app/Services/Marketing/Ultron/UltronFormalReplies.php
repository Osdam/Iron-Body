<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesConversationReplyService;

/**
 * LOS TEXTOS DE LARAVEL, DE USTED (cierre V2, apartado 4, H4-9; plan del
 * arquitecto `plan-arquitecto-a4-resto.md` §2.2 (c) y §2.3, decisión T-4 del
 * lead).
 *
 * Con la versión publicada en «profesional», todo lo que el commit puede
 * escribir por su cuenta (respaldos por intención, bienvenida, últimos
 * recursos, horario, pago pendiente, objeciones, los textos de la política,
 * la memoria de la visita, la ficha del gimnasio y el acta de la cortesía)
 * tiene que salir de usted: si no, el detector del registro descartaría el
 * borrador del modelo por tutear… y lo sustituiría por un texto que tutea.
 *
 * POR QUÉ UNA TABLA Y NO OTRA COPIA DE CADA PRODUCTOR. Los textos de hoy los
 * escriben `SalesConversationReplyService` y `SalesObjectionResponderService`
 * (que usa TAMBIÉN el agente Legacy con los clientes reales: diff cero),
 * `CommercialTurnPolicy`, `CourtesyRequestService` y el propio commit, y
 * varios llevan lógica (qué estado tiene la cita, si ya pasó, qué dato falta
 * de la visita). Aquí no se repite esa lógica: el commit sigue llamando al
 * productor de hoy y, solo con «profesional», pasa su texto por
 * {@see self::deUsted()}, que cambia cada frase o fragmento FIJO por su
 * variante. Los datos que van en medio (la franja, la fecha y la hora de la
 * cita, la dirección, el nombre y el precio de un plan) quedan tal cual. Con
 * cualquier otro tono, el commit no llama a nada de esto: la salida es la de
 * hoy byte a byte.
 *
 * LA REGLA DE LAS VARIANTES: la misma frase pasada a usted, sin muletillas
 * («Listo», «Tranquilo», «De una»), con el mismo número de frases y en el
 * mismo orden, para que {@see UltronReplyFitter} las ajuste al tope igual que
 * las originales. Una excepción, a propósito (m-4 del revisor): la de
 * `not_interested` funde «Listo, tranquilo. No hay problema.» en «Entendido,
 * no hay problema.» (de tres frases a dos, sin perder contenido), porque
 * «tranquilo» es una muletilla. Las 36 del plan (§2.3) van tal cual; el resto las redactó
 * `backend` con esa regla. Las aprueba el propietario en
 * `plans/informes-motores/a4-textos-usted.md`, que genera y fija una prueba.
 *
 * TEXTO DE LA BASE DE DATOS: NUNCA SE REESCRIBE. La ficha del gimnasio, las
 * líneas del horario o los beneficios de un plan son contenido del CRM: a
 * esos textos solo se les cambia la cola fija ({@see self::cola()}) o se les
 * pone la apertura y el cierre de usted ({@see self::FICHA_APERTURA}). Si lo
 * que viene de la base de datos tutea, lo anota la última red del commit
 * (`antesDeEnviar()`, `metadata.register.fallback_mismatch`) y sale igual.
 *
 * Si un productor cambia un texto, su variante deja de aplicarse en silencio
 * y saldría el original de tú: por eso la prueba de completitud exige que
 * cada clave de la tabla siga existiendo, tal cual, en su productor, y que
 * cada salida de hoy pase el detector una vez convertida.
 *
 * PURA: sin base de datos, sin config, sin reloj y sin log.
 */
final class UltronFormalReplies
{
    /** El único tono que trata de usted (el catálogo: `UltronPromptCompiler::TONES`). */
    public const TONO = 'profesional';

    /** La ficha del gimnasio (`respuestaConHechos()`): su apertura y su cierre, de usted. */
    public const FICHA_APERTURA = 'Con gusto le cuento.';

    public const FICHA_CIERRE = '¿Qué le cuento más a fondo: los planes, las clases o cómo empezar?';

    /**
     * Original de hoy → variante de usted. Textos enteros o fragmentos fijos
     * (lo que va entre los datos). {@see self::deUsted()} los sustituye con
     * `strtr`, que prueba primero la clave más larga en cada posición: un
     * texto entero gana a un fragmento suyo.
     */
    public const FRASES = [
        // ── Por intención (`SalesConversationReplyService::replyFor`; plan §2.3) ──
        'Hola, bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos ayudar hoy?' => 'Hola, bienvenido a Iron Body, qué gusto atenderle. Cuénteme, ¿en qué le podemos ayudar hoy?',
        'Sí, claro. Cuéntame qué buscas —bajar grasa, ganar masa o coger el hábito— y te digo el plan que mejor te encaja y su precio.' => 'Sí, claro. Cuénteme qué busca —bajar grasa, ganar masa o coger el hábito— y le digo el plan que mejor le encaja y su precio.',
        'Listo. Para bajar grasa lo más importante es empezar con algo que puedas sostener. ¿Ya vienes entrenando o arrancas desde cero?' => 'Muy bien. Para bajar grasa lo más importante es empezar con algo que pueda sostener. ¿Ya viene entrenando o empieza desde cero?',
        'Perfecto. Para ganar masa lo clave es entrenar constante y con buena guía. ¿Ya has entrenado antes o estás empezando?' => 'Perfecto. Para ganar masa lo clave es entrenar constante y con buena guía. ¿Ya ha entrenado antes o está empezando?',
        'Eso se puede trabajar como recomposición corporal: ganar músculo y bajar grasa poco a poco. Lo clave es hacerlo con constancia y buena guía. ¿Ya has entrenado antes o estás empezando?' => 'Eso se puede trabajar como recomposición corporal: ganar músculo y bajar grasa poco a poco. Lo clave es hacerlo con constancia y buena guía. ¿Ya ha entrenado antes o está empezando?',
        'Estamos en '.SalesConversationReplyService::ADDRESS.'. ¿Vas a ir por primera vez?' => 'Estamos en '.SalesConversationReplyService::ADDRESS.'. ¿Va a venir por primera vez?',
        'No tengo un horario general confirmado en mi información, y prefiero no darte uno equivocado. Si quieres, pregúntame por una clase en concreto y te digo el día y la hora que tengo registrados; también te ayudo con planes o ubicación.' => 'No tengo un horario general confirmado en mi información, y prefiero no darle uno equivocado. Si quiere, pregúnteme por una clase en concreto y le digo el día y la hora que tengo registrados; también le ayudo con planes o ubicación.',
        'Con gusto te cuento. Iron Body es un gimnasio en Neiva donde entrenas con acompañamiento y un espacio preparado para avanzar en tu objetivo, seas principiante o ya vengas entrenando. ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?' => 'Con gusto le cuento. Iron Body es un gimnasio en Neiva donde se entrena con acompañamiento y un espacio preparado para avanzar en su objetivo, sea principiante o ya venga entrenando. ¿Qué le cuento más a fondo: los planes, las clases o cómo empezar?',
        'Con gusto. Si después quieres empezar o comparar planes, me escribes y te ayudo sin problema.' => 'Con gusto. Si después quiere empezar o comparar planes, me escribe y le ayudo sin problema.',
        'Listo, tranquilo. No hay problema. Si después te animas o solo quieres resolver dudas, aquí te ayudamos.' => 'Entendido, no hay problema. Si después se anima o solo quiere resolver dudas, aquí le ayudamos.',
        'Soy el asistente automático de Iron Body y te puedo ayudar con información inicial. Si prefieres hablar con una persona del equipo, solo dímelo.' => 'Soy el asistente automático de Iron Body y le puedo ayudar con información inicial. Si prefiere hablar con una persona del equipo, solo dígamelo.',
        'Claro, dejo marcada tu solicitud para que alguien del equipo la revise. Igual sigo por aquí si quieres que te ayude con precios, ubicación o planes.' => 'Claro, dejo marcada su solicitud para que alguien del equipo la revise. Igual sigo por aquí si quiere que le ayude con precios, ubicación o planes.',
        'Entiendo. Lo dejo marcado como caso para revisión del equipo. Cuéntame qué pasó exactamente y lo atendemos.' => 'Entiendo. Lo dejo marcado como caso para revisión del equipo. Cuénteme qué pasó exactamente y lo atendemos.',
        'Claro. Para factura necesito que el equipo confirme los datos correctos para no cometer errores. Te dejo marcada la solicitud de facturación. Si quieres, también puedo ayudarte con información de planes o ubicación.' => 'Claro. Para factura necesito que el equipo confirme los datos correctos para no cometer errores. Le dejo marcada la solicitud de facturación. Si quiere, también puedo ayudarle con información de planes o ubicación.',
        'Gracias por contarlo. Con una lesión es mejor no recomendar ejercicios específicos por aquí para no darte una orientación irresponsable. Te dejo marcado que necesitas revisión del equipo, y mientras tanto puedo ayudarte con información general de planes o ubicación.' => 'Gracias por contarlo. Con una lesión es mejor no recomendar ejercicios específicos por aquí para no darle una orientación irresponsable. Le dejo marcado que necesita revisión del equipo, y mientras tanto puedo ayudarle con información general de planes o ubicación.',
        'Tranquilo, lo dejo marcado para que el equipo revise tu caso de pago con cuidado. La membresía solo queda activa cuando el pago está confirmado. ¿Quieres que te ayude con algo más mientras tanto?' => 'Entiendo, lo dejo marcado para que el equipo revise su caso de pago con cuidado. La membresía solo queda activa cuando el pago está confirmado. ¿Le ayudo con algo más mientras tanto?',
        'De una, tranquilo. Si más adelante quieres empezar o resolver dudas, me escribes y te ayudo.' => 'Con gusto. Si más adelante quiere empezar o resolver dudas, me escribe y le ayudo.',
        'No quiero responderte cualquier cosa. ¿Te refieres a los planes, horarios o ubicación?' => 'No quiero responderle cualquier cosa. ¿Se refiere a los planes, horarios o ubicación?',

        // ── Pago pendiente (`paymentPendingReply`; redactado por `backend`) ──
        'El pago lo haces tú mismo desde la app Iron Body Workout: creas tu cuenta con tu documento y ahí pagas con Nequi, PSE, tarjeta o Daviplata. También puedes pagar en el gimnasio cuando vengas. ¿Quieres que te pase la app para descargarla?' => 'El pago lo hace usted mismo desde la app Iron Body Workout: crea su cuenta con su documento y ahí paga con Nequi, PSE, tarjeta o Daviplata. También puede pagar en el gimnasio cuando venga. ¿Quiere que le pase la app para descargarla?',

        // ── Objeciones (`SalesObjectionResponderService`; redactadas por `backend`) ──
        'Te entiendo. Más que pagar por pagar, la idea es que sientas que lo vas a aprovechar. ¿Quieres que te cuente para quién sí vale la pena el mensual?' => 'Le entiendo. Más que pagar por pagar, la idea es que sienta que lo va a aprovechar. ¿Quiere que le cuente para quién sí vale la pena el mensual?',
        'Te entiendo. A veces la clave es armar algo realista, no perfecto. ¿Cuántos días a la semana crees que podrías ir sin complicarte?' => 'Le entiendo. A veces la clave es armar algo realista, no perfecto. ¿Cuántos días a la semana cree que podría venir sin complicarse?',
        'Tranquilo, puedes pensarlo con calma. Si decides empezar, te ayudamos a hacerlo bien. ¿Te queda alguna duda que pueda resolverte?' => 'Por supuesto, puede pensarlo con calma. Si decide empezar, le ayudamos a hacerlo bien. ¿Le queda alguna duda que pueda resolverle?',
        'Es normal sentir pena al inicio. A muchas personas les pasa cuando no conocen el ambiente o las máquinas. ¿Qué te preocupa más: ir solo o no saber qué hacer?' => 'Es normal sentir pena al inicio. A muchas personas les pasa cuando no conocen el ambiente o las máquinas. ¿Qué le preocupa más: venir solo o no saber qué hacer?',
        'Te entiendo, y no tienes por qué sentirte juzgado por eso. Mucha gente empieza justo desde ahí, paso a paso. ¿Te preocupa más el ambiente o no saber cómo empezar?' => 'Le entiendo, y no tiene por qué sentirse juzgado por eso. Mucha gente empieza justo desde ahí, paso a paso. ¿Le preocupa más el ambiente o no saber cómo empezar?',

        // ── Bienvenida (`welcomeReplies`; plan §2.3, W1-W6): lo que va después de la franja ──
        '. Bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos ayudar hoy?' => '. Bienvenido a Iron Body, qué gusto atenderle. Cuénteme, ¿en qué le podemos ayudar hoy?',
        '. Qué gusto tenerte por aquí: bienvenido a Iron Body. Cuéntame, ¿en qué te ayudo hoy?' => '. Qué gusto tenerle por aquí: bienvenido a Iron Body. Cuénteme, ¿en qué le ayudo hoy?',
        ', bienvenido a Iron Body. Cuéntame qué necesitas y te acompaño.' => ', bienvenido a Iron Body. Cuénteme qué necesita y le acompaño.',
        '. Cuéntame, ¿en qué te podemos ayudar hoy?' => '. Cuénteme, ¿en qué le podemos ayudar hoy?',
        'Hola, aquí seguimos. Cuéntame qué necesitas y te acompaño.' => 'Hola, aquí seguimos. Cuénteme qué necesita y le acompaño.',
        '. Dime en qué te ayudo hoy.' => '. Dígame en qué le ayudo hoy.',

        // ── Últimos recursos (`lastResorts`; plan §2.3, U1-U5). Los dos «sensibles» ya son neutros ──
        'Claro, con gusto te ayudo. Cuéntame, ¿en qué te ayudo hoy?' => 'Claro, con gusto le ayudo. Cuénteme, ¿en qué le ayudo hoy?',
        'Con gusto. ¿Qué te cuento primero del gimnasio?' => 'Con gusto. ¿Qué le cuento primero del gimnasio?',
        'Cuéntame qué buscas y te oriento con lo que tenemos.' => 'Cuénteme qué busca y le oriento con lo que tenemos.',
        'Con gusto te paso la información de los planes. Dime si empiezas este mes o estás comparando opciones.' => 'Con gusto le paso la información de los planes. Dígame si empieza este mes o está comparando opciones.',
        'Con gusto te ayudo con la ubicación y cómo llegar. Dime si necesitas también horarios o planes.' => 'Con gusto le ayudo con la ubicación y cómo llegar. Dígame si necesita también horarios o planes.',

        // ── Memoria de la visita (`UltronCommitService::respaldoDeMemoria`; plan §2.3, M1-M4) ──
        'No tengo registrada una visita tuya. Dime el día y la hora que te sirven y la dejo solicitada.' => 'No tengo registrada una visita suya. Dígame el día y la hora que le sirven y la dejo solicitada.',
        'Tu visita estaba para el ' => 'Su visita estaba para el ',
        '. Si no alcanzaste a venir, dime qué otro día y hora te sirven y la dejo solicitada.' => '. Si no alcanzó a venir, dígame qué otro día y hora le sirven y la dejo solicitada.',
        'El equipo ya confirmó tu visita: ' => 'El equipo ya confirmó su visita: ',
        '. Te esperamos.' => '. Le esperamos.',
        'Claro. Tu visita de cortesía quedó solicitada para el ' => 'Claro. Su visita de cortesía quedó solicitada para el ',

        // ── La política (`CommercialTurnPolicy`: visita y retomar la visita; redactadas por `backend`) ──
        'Perfecto. Para tu visita al gimnasio, ' => 'Perfecto. Para su visita al gimnasio, ',
        'ya tengo el día y solo me falta la hora: ¿a qué hora te queda bien?' => 'ya tengo el día y solo me falta la hora: ¿a qué hora le queda bien?',
        'ya tengo la hora y solo me falta el día: ¿qué día te queda bien?' => 'ya tengo la hora y solo me falta el día: ¿qué día le queda bien?',
        '¿qué día y a qué hora te queda bien?' => '¿qué día y a qué hora le queda bien?',
        'Y sobre tu visita: la solicitud ya quedó anotada y el equipo la revisa.' => 'Y sobre su visita: la solicitud ya quedó anotada y el equipo la revisa.',
        'Y sobre tu visita: ' => 'Y sobre su visita: ',

        // ── El acta de la cortesía (`CourtesyRequestService`; redactadas por `backend`) ──
        'Dejé registrada tu solicitud de cortesía para el ' => 'Dejé registrada su solicitud de cortesía para el ',
        'Tu visita está confirmada para ' => 'Su visita está confirmada para ',
        'Cancelé tu solicitud de visita para ' => 'Cancelé su solicitud de visita para ',
        'Tu solicitud de visita sigue registrada para ' => 'Su solicitud de visita sigue registrada para ',
        'Tu visita sigue confirmada para ' => 'Su visita sigue confirmada para ',
        'Perfecto. Para tu visita al gimnasio, ya tengo el día y solo me falta confirmar la hora: ¿te refieres ' => 'Perfecto. Para su visita al gimnasio, ya tengo el día y solo me falta confirmar la hora: ¿se refiere ',
        '¿Te refieres ' => '¿Se refiere ',

        // ── Los textos propios del commit (`UltronCommitService`; redactados por `backend`) ──
        'Dejé registrada tu solicitud de día de cortesía. El equipo de Iron Body la revisará para tener todo listo para tu visita.' => 'Dejé registrada su solicitud de día de cortesía. El equipo de Iron Body la revisará para tener todo listo para su visita.',
        'Para no darte un dato equivocado: la visita la confirma el equipo de Iron Body. ¿Qué día y a qué hora te quedarían bien?' => 'Para no darle un dato equivocado: la visita la confirma el equipo de Iron Body. ¿Qué día y a qué hora le quedarían bien?',
        'Puedes hacer el pago desde la app Iron Body Workout o directamente en el gimnasio. Dime qué plan quieres y lo dejamos listo.' => 'Puede hacer el pago desde la app Iron Body Workout o directamente en el gimnasio. Dígame qué plan quiere y lo dejamos listo.',
        '¿Quieres que la deje para el ' => '¿Quiere que la deje para el ',
        'No encontré ninguna visita pendiente a tu nombre para cancelar.' => 'No encontré ninguna visita pendiente a su nombre para cancelar.',
        'Todavía no cambié nada de tu visita. ¿Qué día y a qué hora te queda bien?' => 'Todavía no cambié nada de su visita. ¿Qué día y a qué hora le queda bien?',
        'Todavía no quedó registrada tu visita. ¿Qué día y a qué hora te queda bien?' => 'Todavía no quedó registrada su visita. ¿Qué día y a qué hora le queda bien?',
        'No tengo esa información confirmada en este momento, pero puedo ayudarte con lo que sí tenemos registrado.' => 'No tengo esa información confirmada en este momento, pero puedo ayudarle con lo que sí tenemos registrado.',

        // ── El cobro (enlaces de pago APAGADOS en producción; por completitud) ──
        '¡Hola! 💪 Aquí tienes tu link para activar tu membresía ' => '¡Hola! 💪 Aquí tiene su link para activar su membresía ',
        'en Iron Body Neiva. Pagas seguro desde acá y tu acceso queda listo al confirmarse ' => 'en Iron Body Neiva. Paga seguro desde acá y su acceso queda listo al confirmarse ',
    ];

    /**
     * Colas fijas de textos que llevan contenido del CRM delante (las líneas
     * del horario de la ficha, los beneficios de un plan). Solo se cambia la
     * cola: lo de delante es de la base de datos y no se toca.
     */
    public const COLAS = [
        ' ¿En qué franja te queda mejor entrenar?' => ' ¿En qué franja le queda mejor entrenar?',
        ' ¿Quieres que te cuente los demás?' => ' ¿Quiere que le cuente los demás?',
    ];

    /** La línea del enlace que el commit pega debajo del texto (`conCheckout()`). */
    public const LINEA_DEL_COBRO = "\n\nEste es su enlace de pago seguro por $";

    /** ¿Este tono trata de usted? Solo «profesional»; null (Legacy, un token sin `t`) y los demás, no. */
    public static function aplica(?string $tono): bool
    {
        return $tono === self::TONO;
    }

    /**
     * El texto fijo de Laravel, de usted: cada frase o fragmento de
     * {@see self::FRASES} por su variante, y lo demás (los datos) tal cual.
     * Solo para textos escritos por Laravel; nunca para un borrador del
     * modelo (el commit no reescribe al modelo: lo acepta o lo descarta) ni
     * para texto libre de la base de datos.
     */
    public static function deUsted(string $texto): string
    {
        return strtr($texto, self::FRASES);
    }

    /** El texto con su cola fija de usted ({@see self::COLAS}); lo de delante, tal cual. Sin cola conocida, la tabla entera. */
    public static function cola(string $texto): string
    {
        foreach (self::COLAS as $original => $usted) {
            if (str_ends_with($texto, $original)) {
                return substr($texto, 0, -strlen($original)).$usted;
            }
        }

        return self::FRASES[$texto] ?? $texto;
    }

    /** {@see self::deUsted()} solo si el tono lo pide; si no, el texto de hoy, sin tocar. */
    public static function segun(?string $tono, ?string $texto): ?string
    {
        return $texto === null || ! self::aplica($tono) ? $texto : self::deUsted($texto);
    }

    /** {@see self::cola()} solo si el tono lo pide; si no, el texto de hoy, sin tocar. */
    public static function colaSegun(?string $tono, ?string $texto): ?string
    {
        return $texto === null || ! self::aplica($tono) ? $texto : self::cola($texto);
    }
}
