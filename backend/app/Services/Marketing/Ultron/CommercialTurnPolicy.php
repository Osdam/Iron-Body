<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesIntents;

/**
 * QUÉ TIENE QUE HACER ESTE TURNO. EL CÓMO LO ESCRIBE EL MODELO.
 *
 * Nace de una prueba física en la que las reglas comerciales estaban escritas
 * —en el prompt— y aun así no se cumplieron:
 *
 *   · «quisiera información del gimnasio» se contestó preguntando el objetivo,
 *     sin contar nada. El Critic lo aprobó: nada en el backend decía que
 *     responder fuera obligatorio antes de interrogar.
 *   · el segundo turno volvió a abrir con «Buenas tardes». El prompt pedía no
 *     repetir el saludo, pero no existía ningún estado que dijera si ya se
 *     había saludado.
 *   · y se ofreció el Plan Mensual cuando el plan insignia es otro, porque en
 *     el prompt conviven dos reglas y ganó la más imperativa: «usa EXACTAMENTE
 *     default_plan_id».
 *
 * La lección es la misma que este sistema aprendió con el dinero y con las
 * promesas: una regla que sólo vive en el prompt es una sugerencia. Aquí las
 * decisiones comerciales del turno se resuelven ANTES de redactar, con datos
 * del CRM, y quedan como hechos que el texto tiene que respetar.
 *
 * Lo que esto NO hace: escribir por el modelo. No hay respuestas enlatadas
 * aquí, ni se le quita libertad de redacción. Se le fija el QUÉ —saluda o no,
 * responde antes de preguntar, este plan y no otro— y se comprueba después que
 * el borrador lo cumpla.
 */
final class CommercialTurnPolicy
{
    /** Intenciones en las que la persona PIDE INFORMACIÓN: primero se responde. */
    private const INFORMATIVAS = [
        SalesIntents::GENERAL_INFO,
        SalesIntents::PRICING_QUESTION,
        SalesIntents::LOCATION_QUESTION,
        SalesIntents::SCHEDULE_QUESTION,
        SalesIntents::GREETING,
    ];

    /**
     * Intenciones en las que el plan YA está elegido y no se toca.
     *
     * Cerrar o pedir el enlace son turnos sobre un plan concreto, casi siempre
     * el que la persona nombró. Imponerles el insignia cambiaría el precio que
     * se cotiza debajo de un texto que habla de otro plan: la prioridad
     * comercial es para RECOMENDAR, no para corregir a quien ya eligió.
     */
    private const PLAN_YA_ELEGIDO = [
        SalesIntents::HIGH_INTENT_CLOSE,
        SalesIntents::PAYMENT_LINK_REQUEST,
    ];

    /** Lo que un primer turno de información general NO puede hacer. */
    public const PROHIBIDO_EN_INFO = [
        'goal_discovery_only',
        'plan_recommendation',
        'registration',
        'payment',
        'checkout',
    ];

    public const VIOLACION_SOLO_DESCUBRIMIENTO = 'policy_general_info_only_discovery';

    public const VIOLACION_SALUDO_REPETIDO = 'policy_greeting_repeated';

    public const VIOLACION_PLAN_EQUIVOCADO = 'policy_wrong_first_plan';

    /** Con una visita a medio concretar, el turno se puso a vender. */
    public const VIOLACION_OBJETIVO_PERDIDO = 'policy_active_goal_lost';

    /** Pidió información del gimnasio y el texto no trae ni un hecho del CRM. */
    public const VIOLACION_SIN_HECHO = 'policy_answer_without_fact';

    /**
     * La persona duda de un precio que ya se le dio, o ve dos distintos.
     *
     * No es una objeción —no dice que sea caro— y no es una pregunta nueva:
     * es una VERIFICACIÓN. La etiqueta del modelo la confundió con objeción
     * en la prueba física y eso desvió la estrategia a «resolver barrera».
     * La respuesta correcta es el precio actual del CRM, y nada más.
     */
    private const PRECIO_EN_DUDA = '/\b(seguro que|segur[oa] de que|en serio|de verdad)\b[^.!?]{0,40}\b(cuesta|vale|precio|cobra|sale)\b'
        .'|\b(cuesta|vale|sale)\s+eso\b'
        .'|\bpor\s?que\b[^.!?]{0,30}\b(antes|anterior|primero|luego|ahora|otro precio|otra cifra)\b[^.!?]{0,40}\b(precio|cuesta|vale|mil|k)\b'
        .'|\bme\s+(dices|dijiste|dijo|dijeron|decias|habias dicho|habian dicho)\b[^.!?]{0,40}\b(y|luego|ahora|despues)\b[^.!?]{0,30}\b(precio|cuesta|vale|\d)'
        // Dos cifras de dinero en el mismo mensaje, unidas por «y luego / y
        // ahora»: la persona está mostrando la discrepancia, con la ortografía
        // que sea («80mil», «45 mil», «80k»).
        .'|\b\d{2,3}\s?(mil|k)\b[^.!?]{0,50}\b(y|luego|ahora|despues|pero)\b[^.!?]{0,40}\b\d{2,3}\s?(mil|k)\b'
        // Y con las cifras peladas, atadas por «antes … ahora/luego»: «antes 80 y ahora 45».
        .'|\b(antes|primero|al principio)\b[^.!?]{0,15}\b\d{2,3}\b[^.!?]{0,20}\b(y|luego|ahora|despues)\b[^.!?]{0,15}\b\d{2,3}\b'
        .'|\bcual (es|seria) el precio (real|correcto|verdadero|de verdad)\b/u';

    /** Una pregunta de precio hecha con palabras, sea cual sea la etiqueta del modelo. */
    private const PREGUNTA_PRECIO = '/\b(cuanto (vale|valen|cuesta|cuestan|es|son|sale|salen|seria|serian)|que precio|precios?\b|precio de|a cuanto|(la )?mensualidad|cuanto (me )?(cobran|cobrarian))\b/u';

    /**
     * La persona RECHAZA la visita con sus palabras, aunque el resolutor no
     * lo lea como «no» a la última oferta: «prefiero no ir por ahora», «ya no
     * quiero la visita», «después te digo». Cierra el objetivo; sin esto se
     * retomaba durante veinticuatro horas una visita que ya habían rechazado.
     */
    private const NIEGA_VISITA = '/\b(prefiero no\b|no (quiero|puedo|voy a poder|me interesa|me sirve)\s+(ir|pasar|visitar|la visita|conocer|asistir|por ahora)|ya no (quiero|me interesa)\b|mejor (no|luego|despues|otro dia)\b|(despues|luego|otro dia|mas adelante|la otra semana) (te|les) (digo|aviso|confirmo|escribo)|(lo|la) (pienso|dejamos|vemos) (y te|luego|despues)|cancela(r|me|la)?\s+(la\s+)?(visita|cita)|olvidalo|dejalo asi)\b/u';

    /** El mensaje es una PREGUNTA: se contesta primero, con la visita a medias o sin ella. */
    private const ES_PREGUNTA = '/\?|^\s*(que|q|cual|cuales|cuanto|cuanta|cuantos|cuantas|como|donde|cuando|hay|tienen|tiene|puedo|se puede|hasta|a que hora|por que|porque)\b/u';

    /** El texto sigue hablando de la visita: eso es retomar el objetivo. */
    private const HABLA_DE_VISITA = '/\b(visita|cortesia|conocer (el gimnasio|las instalaciones)|pasar (a conocer|por el gimnasio)|que dia|que hora|a que hora|el dia|la hora|mañana|manana|hoy|el (lunes|martes|miercoles|jueves|viernes|sabado|domingo))\b/u';

    /**
     * La persona PIDE información con sus palabras.
     *
     * La etiqueta `general_info` la pone el modelo y la pone a casi todo
     * —«mañana en la noche» llegó etiquetada así—, de modo que exigir un
     * hecho del gimnasio por la etiqueta mataba turnos que no pedían nada.
     * El hecho se exige cuando lo pidieron y todavía no se les ha dado.
     */
    private const PIDE_INFO = '/\b(informacion|info|informarme|informes|saber (mas|sobre|de|del|que)|conocer (mas|el gimnasio|sobre|del)|que (es|ofrecen|tienen|manejan|hay)|cuentame|me cuentas|me cuente|hablame|como (es|funciona))\b/u';

    /**
     * Una pregunta incidental hecha con palabras: horario, ubicación, algo
     * concreto del gimnasio. Con la visita a medias se contesta y se retoma.
     * Por texto y no solo por etiqueta, porque «hasta q hora abren» llega
     * etiquetada como cualquier cosa.
     */
    private const PREGUNTA_INCIDENTAL = '/\b(hasta (que|q) hora|a (que|q) hora (abren|cierran|atienden)|(que|q) horario|horarios?\b|donde (quedan|estan|queda|es)|direccion|ubicacion|ubicados|como llego|tienen (parqueadero|clases|pilates|yoga|duchas|zumba|crossfit|casilleros|lockers)|hay (parqueadero|clases|duchas|casilleros))\b/u';

    /** Una cifra de dinero ya puesta, o el marcador que la traerá. */
    private const HAY_PRECIO = '/\$\s?\d|\d{2,3}[.,]\d{3}|\bcop\b|\{\{\s*plan_price\s*\}\}/u';

    /**
     * Palabras con las que la gente pide OTRA cosa. Si aparecen, el plan
     * insignia deja de ser obligatorio: insistir con el mismo es no escuchar.
     */
    private const PIDE_OTRO = '/\b(otro\s+plan|otra\s+opcion|mas\s+economic|mas\s+barat|muy\s+caro|esta\s+caro|no\s+quiero\s+ese|algo\s+mas\s+(barato|economico)'
        // «muéstrame otro», «hay otra?», «dame otro»: pedir otro sin decir «plan».
        .'|(muestrame|mostrarme|dame|mencioname|ensename|tienes|hay|cual\s+es|conoces)\s+otr[oa]\b|^\s*otr[oa]\s*[?.!]*\s*$)/u';

    /**
     * «No quiero el X» delante del nombre, o «el X no me sirve» detrás.
     *
     * Atada al plan que niega, no al mensaje entero: «no quiero esperar más,
     * dame el plan mensual» no niega ningún plan, y leerlo así vetaba el
     * insignia para siempre y ofrecía otro a quien pidió uno por su nombre.
     */
    private const NIEGA_ANTES = '/\b(no\s+(quiero|me\s+sirve|me\s+interesa|me\s+gusta|me\s+convence|lo\s+quiero|me\s+llama)|nada\s+de|ni\s+loco)\s+(el\s+|la\s+|ese\s+|esa\s+|con\s+el\s+|con\s+la\s+|de\s+|un\s+)?$/u';

    private const NIEGA_DESPUES = '/^\s*(no\s+(me\s+(sirve|interesa|gusta|convence|llama)|lo\s+quiero)|ese\s+no|esa\s+no|no,?\s+gracias)\b/u';

    /**
     * Cuándo la persona está pidiendo que le RECOMIENDEN.
     *
     * Es más estrecho que «habla de planes» a propósito. «¿Cuánto vale el
     * trimestre?» habla de planes y no pide recomendación: ahí manda el plan
     * que nombró ella. Lo que abre la puerta a la prioridad comercial es
     * preguntar en abierto —qué planes hay, cuál recomiendan, qué ofrecen—.
     */
    private const PIDE_RECOMENDACION = '/\b(planes|membresias|recomendad|recomiendas|recomienda|que\s+ofrecen|que\s+opciones|opciones\s+(hay|tienen))/u';

    /**
     * @param  array<int,array<string,mixed>>  $activePlans  catálogo del CRM, ya ordenado
     * @param  array<string,mixed>  $resolution  lo que resolvió {@see ReferenceResolver}
     * @return array<string,mixed>
     */
    public static function decide(
        string $intent,
        string $phase,
        ConversationMemory $memory,
        array $activePlans,
        array $resolution,
        string $inbound,
    ): array {
        $texto = SalesAgentDecisionSchema::normalize($inbound);
        $yaSaludamos = $memory->get('greeted') !== null;
        $yaDimosBienvenida = $memory->get('initial_welcome_delivered') !== null;

        $insignia = self::planInsignia($activePlans);
        $pideOtro = preg_match(self::PIDE_OTRO, $texto) === 1;
        $pideRecomendacion = preg_match(self::PIDE_RECOMENDACION, $texto) === 1;

        $rechazados = $memory->rejectedPlans();

        /*
         * Si la persona NOMBRA un plan, ese manda. Nombrarlo es la forma más
         * clara que hay de elegir, y la prioridad comercial no está para
         * corregir a quien ya eligió.
         */
        $nombradoEnTexto = self::planNombradoEn($texto, $activePlans);
        $referido = $nombradoEnTexto ?? self::planDeLaReferencia($resolution, $activePlans);

        /*
         * UN PLAN NOMBRADO Y NEGADO ES UN RECHAZO, NO UNA ELECCIÓN.
         *
         * «No quiero Total Access, muéstrame otro» nombra el insignia, y
         * leerlo como elección lo convertía en OBLIGATORIO: justo lo contrario
         * de lo que la persona dijo. Se rechaza ese plan en este turno y se
         * pide otro; el «no» de la memoria lo anota commit.
         */
        $negados = ($resolution['type'] ?? null) === ReferenceResolver::CHOOSE_PLAN
            ? []
            : self::planesNegadosEn($texto, $activePlans);
        if ($negados !== []) {
            $pideOtro = true;
            if ($referido !== null && in_array($referido, $negados, true)) {
                // El primero que nombra está negado: el referido es el
                // siguiente nombrado que NO lo esté («el mensual está bien,
                // no me gusta el trimestral» elige el mensual).
                $referido = null;
                foreach (self::plansNamedIn($texto, $activePlans) as $id) {
                    if (! in_array($id, $negados, true)) {
                        $referido = $id;
                        break;
                    }
                }
            }
        }

        /*
         * NOMBRAR DOS PLANES ES COMPARAR, NO ELEGIR. «¿Por qué el Semana vale
         * 45 y el Mensual 80?» no obliga a ninguno: forzar el primero cotizaba
         * el Semana bajo una frase que hablaba del Mensual.
         */
        $elegibles = array_values(array_diff(self::plansNamedIn($texto, $activePlans), $negados));
        if (count($elegibles) > 1 && ($resolution['type'] ?? null) !== ReferenceResolver::CHOOSE_PLAN) {
            $referido = null;
        }

        $planYaElegido = in_array($intent, self::PLAN_YA_ELEGIDO, true);
        $hablaDePlanes = $pideRecomendacion || $referido !== null;

        /*
         * QUÉ ESTÁ HACIENDO ESTA CONVERSACIÓN, antes de decidir qué hacer.
         *
         * Una visita a medio concretar es un objetivo vivo. Mientras lo esté,
         * vender es cambiar de tema: la persona dijo «mañana en la noche» y
         * recibió un plan con precio, y eso se midió en una prueba física.
         * La excepción es que la propia persona saque los planes o el precio:
         * entonces se contesta ESO primero y se retoma la visita después.
         */
        $objetivo = $memory->activeGoal();
        $rechazaVisita = self::declinesVisit($texto);
        $visitaAbierta = $objetivo !== null
            && $objetivo['kind'] === ConversationMemoryService::GOAL_COURTESY
            && $objetivo['status'] === ConversationMemoryService::GOAL_COLLECTING
            && ! $rechazaVisita;
        if ($rechazaVisita && $objetivo !== null && $objetivo['kind'] === ConversationMemoryService::GOAL_COURTESY) {
            // Este turno ya no tiene objetivo: la memoria lo cierra al enviar.
            $objetivo = null;
        }
        $dudaDePrecio = preg_match(self::PRECIO_EN_DUDA, $texto) === 1;
        $preguntaPrecio = $dudaDePrecio
            || $intent === SalesIntents::PRICING_QUESTION
            || preg_match(self::PREGUNTA_PRECIO, $texto) === 1;
        $sacaLosPlanes = $hablaDePlanes || $preguntaPrecio || $planYaElegido;
        /*
         * UNA PREGUNTA SE CONTESTA, con la visita a medias o sin ella.
         *
         * La revisión secuestró «¿cuánto cuestan las clases de yoga?» con la
         * visita abierta: la política prohibía el plan, el texto nombraba uno
         * y el respaldo pidió el día y la hora. La pregunta se perdió entera.
         * Lo que manda la visita es un FRAGMENTO («mañana en la noche», «tipo
         * 7»); una pregunta, sea de lo que sea, se contesta y luego se retoma.
         */
        $preguntaIncidental = preg_match(self::PREGUNTA_INCIDENTAL, $texto) === 1
            || preg_match(self::ES_PREGUNTA, $texto) === 1
            || in_array($intent, [SalesIntents::SCHEDULE_QUESTION, SalesIntents::LOCATION_QUESTION], true);
        $visitaManda = $visitaAbierta && ! $sacaLosPlanes && ! $preguntaIncidental;
        $retomarVisita = $visitaAbierta && ($sacaLosPlanes || $preguntaIncidental);

        /*
         * El plan OBLIGATORIO del turno, y sus excepciones, que son las que
         * distinguen una prioridad comercial de una imposición sorda:
         *   · la persona pidió otro, o dijo que está caro;
         *   · ya lo rechazó antes;
         *   · viene hablando de otro plan sin ambigüedad;
         *   · el insignia no está a la venta.
         */
        $obligatorio = null;
        $origen = null;

        /*
         * PREFERENCIA COMERCIAL y OBLIGACIÓN son dos cosas distintas.
         *
         * El insignia es lo que el negocio quiere que se presente PRIMERO
         * cuando se habla de planes: una preferencia, gobernada, que se
         * evapora si la persona lo rechazó, pide otro, o está concretando una
         * visita. El plan que la persona NOMBRA es un hecho, y ése sí obliga.
         * Mezclarlos daba una obligación torpe: el insignia se imponía donde
         * nadie había pedido un plan.
         */
        $preferido = ($insignia !== null && ! $pideOtro && ! in_array($insignia, $rechazados, true) && ! $visitaManda)
            ? $insignia
            : null;

        if ($pideRecomendacion && ! $planYaElegido && $preferido !== null && $referido === null) {
            $obligatorio = $preferido;
            $origen = 'commercial_priority';
        }
        if ($referido !== null) {
            $obligatorio = $referido;
            /*
             * Escrito por la persona («quiero el plan mensual») o deducido por
             * el resolutor («mándamelo» sobre el link vivo). Los dos obligan;
             * sólo el primero puede PISAR el plan de la propuesta en commit:
             * el resolutor sobre un link vivo devuelve el plan del link, y
             * pisar con él cuando la persona acaba de nombrar OTRO en corto
             * («mejor el trimestral») reenviaba el link viejo en vez de
             * frenar el cambio de plan.
             */
            $origen = $nombradoEnTexto !== null && $nombradoEnTexto === $referido ? 'named_by_person' : 'resolved_reference';
        }

        /*
         * Cuando la persona duda de un precio, el plan del turno es el que
         * está en duda: el que nombró, o el último del que se hablaba. Se
         * marca como nombrado por ella para que commit NO lo pise con la
         * prioridad comercial: contestar «seguro que el Semana vale eso?»
         * cotizando el insignia sería exactamente el fallo que se investiga.
         */
        if ($dudaDePrecio && $obligatorio === null) {
            $enDuda = $memory->pendingPlanId();
            if ($enDuda !== null && in_array($enDuda, array_map(static fn (array $p) => (int) $p['id'], $activePlans), true)) {
                $obligatorio = $enDuda;
                $origen = 'named_by_person';
            }
        }

        // Y cuando pide otro, el insignia queda PROHIBIDO en este turno.
        $prohibidos = [];
        if ($pideOtro && $insignia !== null) {
            $prohibidos[] = $insignia;
        }
        foreach ($negados as $negado) {
            $prohibidos[] = $negado;
        }
        foreach ($rechazados as $r) {
            $prohibidos[] = $r;
        }

        /*
         * Volver a preguntar por un plan lo saca de la lista de rechazados.
         *
         * Sin esto, el mismo plan salía OBLIGATORIO y PROHIBIDO en el mismo
         * turno: la persona lo rechazó ayer y hoy pregunta por él, y la
         * política se contradecía a sí misma. Quien vuelve a nombrarlo lo está
         * reabriendo; el «no» de antes se acabó ahí.
         */
        if ($obligatorio !== null) {
            $prohibidos = array_values(array_diff($prohibidos, [(int) $obligatorio]));
        }

        $prohibidos = array_values(array_unique($prohibidos));

        $ids = array_map(static fn (array $p) => (int) $p['id'], $activePlans);
        $permitidos = array_values(array_diff($ids, $prohibidos));

        $informativo = in_array($intent, self::INFORMATIVAS, true) || $dudaDePrecio || $preguntaPrecio;
        $primeraInfo = $intent === SalesIntents::GENERAL_INFO && ! $hablaDePlanes;

        /*
         * El hecho del gimnasio se exige cuando lo PIDIERON y aún no lo tienen.
         * «Todavía no entregada» se lee de la memoria: ningún hecho de la
         * base de conocimiento ha salido en esta conversación.
         */
        $pideInfo = preg_match(self::PIDE_INFO, $texto) === 1;
        $sinHechosAun = ! self::algunHechoEntregado($memory);
        $exigeHecho = $primeraInfo && $pideInfo && $sinHechosAun;

        $prohibidasAcciones = $primeraInfo ? self::PROHIBIDO_EN_INFO : [];
        if ($visitaManda) {
            $prohibidasAcciones = array_values(array_unique(array_merge(
                $prohibidasAcciones,
                ['plan_recommendation', 'payment', 'checkout'],
            )));
        }

        $siguientes = $primeraInfo
            ? ['answer', 'orient', 'open_question']
            : ['answer', 'orient', 'open_question', 'recommend_plan'];
        if ($visitaManda) {
            $siguientes = ['answer', 'orient', 'open_question', 'continue_goal'];
        } elseif ($retomarVisita) {
            $siguientes[] = 'resume_goal';
        }

        return [
            'intent' => $intent,
            'commercial_phase' => $phase,
            'greet_required' => ! $yaSaludamos,
            'welcome_required' => ! $yaDimosBienvenida,
            'answer_first' => $informativo,
            'required_plan_id' => $obligatorio,
            /*
             * De dónde sale esa obligación, porque decide si puede PISAR al
             * plan que eligió el modelo. Sólo la prioridad comercial lo pisa:
             * si el plan lo nombró la persona, el id que trae la propuesta ya
             * es el correcto y tocarlo cambiaría el precio que se cotiza bajo
             * un texto que habla de otro plan. Eso está fijado en los tests de
             * dinero y no se toca.
             */
            'required_plan_source' => $origen,
            // Lo que OBLIGA (un hecho: la persona lo nombró) y lo que el
            // negocio PREFIERE (una prioridad, que cede ante la persona).
            'hard_required_plan' => $referido,
            'preferred_commercial_plan' => $preferido,
            'allowed_plan_ids' => $permitidos,
            'forbidden_plan_ids' => $prohibidos,
            'forbidden_actions' => $prohibidasAcciones,
            'required_facts' => $exigeHecho ? ['gym_identity'] : [],
            'allowed_next_actions' => $siguientes,
            'wants_alternative' => $pideOtro,
            // Los que la persona NEGÓ con palabras en este turno. Commit
            // rechaza ésos, no el que acaba de aceptar: «el mensual está bien,
            // no me gusta el trimestral» dejaba vetado el Mensual.
            'rejected_plan_ids' => $negados,
            'price_verification' => $dudaDePrecio,
            'active_goal' => $objetivo === null ? null : [
                'kind' => $objetivo['kind'],
                'status' => $objetivo['status'],
                'data' => $objetivo['data'],
            ],
            /*
             * La decisión, dicha con su nombre: este turno es de la visita
             * (se sigue concretando y no se vende) o no lo es. `violations()`
             * la leía deducida de `forbidden_actions`, y ahí
             * `plan_recommendation` también entra por la regla de
             * `general_info`: con esa etiqueta, una pregunta que `decide()`
             * había mandado contestar y retomar se daba por secuestro.
             */
            'goal_takes_turn' => $visitaManda,
            'resume_goal_after_answer' => $retomarVisita,
        ];
    }

    /**
     * Lo que el borrador incumple, por su nombre. Vacío = cumple.
     *
     * @param  array<string,mixed>  $policy
     * @param  array<int,array<string,mixed>>  $activePlans
     * @return string[]
     */
    public static function violations(string $draft, array $policy, array $activePlans, array $knownFacts = []): array
    {
        $t = SalesAgentDecisionSchema::normalize($draft);
        $fallos = [];

        /*
         * 0. Con una visita a medio concretar, el turno NO vende.
         *
         * Se mide contra el texto y no contra la etiqueta: si nombra un plan o
         * trae una cifra —o el marcador que la traerá— mientras el objetivo es
         * cerrar el día y la hora, se cambió de tema. Si este turno es de la
         * visita lo dijo `decide()` en `goal_takes_turn`, y se lee de ahí: se
         * deducía de que `plan_recommendation` estuviera prohibido, y eso
         * también lo prohíbe la regla de `general_info`, así que una pregunta
         * («cuánto cuestan las clases de yoga?») que `decide()` había mandado
         * contestar y retomar se tomaba por secuestro y perdía la respuesta.
         */
        $visitaManda = (bool) ($policy['goal_takes_turn'] ?? false);
        if ($visitaManda && (self::plansNamedIn($t, $activePlans) !== [] || preg_match(self::HAY_PRECIO, $t) === 1)) {
            $fallos[] = self::VIOLACION_OBJETIVO_PERDIDO;
        }

        /*
         * 0.bis Pedir información del gimnasio y no recibir ni un hecho.
         *
         * `soloPregunta()` mide que haya ALGO además de la pregunta, y una
         * prueba física demostró que «para darte la mejor información sobre
         * el gimnasio» ya era «algo»: veintiún caracteres de relleno y ni un
         * dato. `required_facts` llevaba semanas declarado y nadie lo leía.
         * Ahora se exige que el texto contenga al menos un hecho del CRM de
         * los que se pasan aquí; sin lista, no se puede medir y no se mide.
         */
        if (($policy['answer_first'] ?? false)
            && in_array('gym_identity', (array) ($policy['required_facts'] ?? []), true)
            && $knownFacts !== []
            && ! self::factPresent($t, $knownFacts)) {
            $fallos[] = self::VIOLACION_SIN_HECHO;
        }

        /*
         * 1. Pedir información y recibir un interrogatorio.
         *
         * Lo que se mide no es que pregunte —una pregunta abierta al final es
         * justo lo que queremos— sino que SÓLO pregunte: un turno que no
         * cuenta nada y devuelve la pelota deja a la persona igual que estaba.
         */
        if (($policy['answer_first'] ?? false) && self::soloPregunta($t)) {
            $fallos[] = self::VIOLACION_SOLO_DESCUBRIMIENTO;
        }

        // 2. Saludar dos veces.
        if (! ($policy['greet_required'] ?? true) && self::abreSaludando($t)) {
            $fallos[] = self::VIOLACION_SALUDO_REPETIDO;
        }

        // 3. Nombrar otro plan antes que el obligatorio.
        $obligatorio = $policy['required_plan_id'] ?? null;
        if ($obligatorio !== null) {
            $primero = self::primerPlanNombrado($t, $activePlans);
            if ($primero !== null && $primero !== (int) $obligatorio) {
                $fallos[] = self::VIOLACION_PLAN_EQUIVOCADO;
            }
        }

        // 4. Nombrar un plan prohibido (el que acaba de rechazar).
        foreach ((array) ($policy['forbidden_plan_ids'] ?? []) as $prohibido) {
            if (self::nombraElPlan($t, (int) $prohibido, $activePlans)) {
                $fallos[] = self::VIOLACION_PLAN_EQUIVOCADO;
                break;
            }
        }

        return array_values(array_unique($fallos));
    }

    /**
     * Quita el saludo repetido SIN tirar el resto del mensaje.
     *
     * Un borrador que vuelve a saludar suele traer, detrás del saludo, toda la
     * información que la persona pidió. Descartarlo entero por abrir mal sería
     * cambiar un defecto de forma por uno de fondo: la persona pasaría de leer
     * «buenas tardes» dos veces a no recibir la dirección que preguntó. Se
     * recorta la fórmula y se deja lo demás tal cual lo escribió el modelo.
     */
    public static function withoutRepeatedGreeting(string $draft): string
    {
        $limpio = (string) preg_replace(
            '/^\s*(hola[,!\s]*)?(muy\s+)?buen(os|as)\s+(d[ií]as|tardes|noches)[,.!\s]*/iu',
            '',
            $draft,
        );

        $limpio = trim($limpio);
        if ($limpio === '') {
            return trim($draft);
        }

        // La frase recortada empieza en minúscula si arrastraba el saludo.
        return mb_strtoupper(mb_substr($limpio, 0, 1)).mb_substr($limpio, 1);
    }

    /**
     * Un respaldo que SÍ sirve cuando lo que falla es el plan.
     *
     * Si el borrador nombró el plan equivocado, mandar un «cuéntame qué
     * necesitas» cambia un plan mal elegido por una respuesta vacía: la
     * persona preguntó por planes y se queda sin ninguno. Aquí se arma la
     * respuesta con el CATÁLOGO DEL CRM —el nombre y los beneficios que ya
     * están en la ficha— y sin cifras, que las pone el motor de precios.
     *
     * No es copia de ventas escrita a mano: si mañana el negocio cambia de
     * plan insignia o le cambia los beneficios, esto cambia con él.
     *
     * @param  array<string,mixed>  $policy
     * @param  array<int,array<string,mixed>>  $activePlans
     */
    public static function planReply(array $policy, array $activePlans): ?string
    {
        $elegido = $policy['required_plan_id'] ?? null;

        // Sin plan obligatorio —pidió otro— se ofrece el primero permitido.
        if ($elegido === null) {
            $permitidos = (array) ($policy['allowed_plan_ids'] ?? []);
            $elegido = $permitidos[0] ?? null;
        }

        if ($elegido === null) {
            return null;
        }

        foreach ($activePlans as $p) {
            if ((int) $p['id'] !== (int) $elegido) {
                continue;
            }

            $nombre = trim((string) ($p['name'] ?? ''));
            if ($nombre === '') {
                return null;
            }

            $beneficios = array_values(array_filter(array_map(
                static fn ($b) => trim((string) $b),
                (array) ($p['benefits'] ?? []),
            )));

            $detalle = $beneficios === []
                ? ''
                : ' Incluye '.mb_strtolower(implode(' y ', array_slice($beneficios, 0, 2))).'.';

            // «el Anualidad» y «el Élite» suenan a máquina. El artículo sólo
            // cabe cuando el nombre empieza por «Plan».
            $articulo = preg_match('/^plan\b/iu', $nombre) === 1 ? 'el ' : '';

            $encabezado = ($policy['wants_alternative'] ?? false) === true
                ? 'Claro, otra opción es '
                : 'El que más recomendamos es ';

            return $encabezado.$articulo.$nombre.'.'.$detalle.' ¿Quieres que te cuente los demás?';
        }

        return null;
    }

    /** El plan que el negocio quiere ofrecer primero, si está a la venta. */
    public static function planInsignia(array $activePlans): ?int
    {
        foreach ($activePlans as $p) {
            if (($p['is_recommended'] ?? false) === true) {
                return (int) $p['id'];
            }
        }

        return null;
    }

    /**
     * El plan que la persona nombró en su mensaje, si nombró alguno.
     *
     * Por PALABRA COMPLETA, no por subcadena. «Pro» es un plan real del
     * catálogo y `str_contains` lo encontraba dentro de «aprovechar»,
     * «problema» y «promoción»: un «quiero aprovechar, ¿cuánto vale el
     * mensual?» acababa nombrando el Pro en el texto mientras el precio y el
     * `plan_id` grabados seguían siendo los del Mensual. Dos planes distintos
     * en el mismo turno es exactamente lo que las guardas del dinero impiden.
     */
    /**
     * Los planes que el texto NOMBRA, por orden de aparición.
     *
     * Es la mitad visible de la invariante plan/precio: el precio que se
     * inyecta tiene que ser el del plan que estas palabras nombran, y para
     * saberlo hay que leer las palabras. Público porque commit lo necesita
     * ANTES de rellenar el marcador, no después.
     *
     * @param  array<int,array{id:int,name:string}>  $activePlans
     * @return int[]
     */
    public static function plansNamedIn(string $draft, array $activePlans): array
    {
        $t = SalesAgentDecisionSchema::normalize($draft);
        // Sin tildes pero con mayúsculas: «el Mensual» es el producto, «el mensual» puede ser el mes.
        $conMayusculas = strtr($draft, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N']);
        $posiciones = [];
        foreach ($activePlans as $p) {
            $nombre = SalesAgentDecisionSchema::normalize((string) ($p['name'] ?? ''));
            if ($nombre === '') {
                continue;
            }
            $core = trim(preg_replace('/^plan\s+/u', '', $nombre) ?? $nombre);
            $q = preg_quote($core, '/');
            $pos = null;
            // 1. El nombre entero.
            if (self::nombraPalabraCompleta($t, $nombre)) {
                $pos = mb_strpos($t, $nombre);
            }
            // 2. El nombre corto regido por «el/la/del/plan»; NO si el nombre es
            //    palabra de calendario: «toda la semana» no es el Plan Semana.
            if ($pos === null && ! ReferenceResolver::isCalendarWord($core)
                && preg_match('/\b(el|la|del|plan) '.$q.'\b/u', $t, $m, PREG_OFFSET_CAPTURE) === 1) {
                $pos = mb_strlen(substr($t, 0, (int) $m[0][1]));
            }
            // 3. El nombre corto en mayúscula inicial, como se escribe un producto.
            if ($pos === null && preg_match('/\b'.mb_strtoupper(mb_substr($q, 0, 1)).mb_substr($q, 1).'\b/u', $conMayusculas, $m, PREG_OFFSET_CAPTURE) === 1) {
                $pos = mb_strlen(substr($conMayusculas, 0, (int) $m[0][1]));
            }
            if ($pos !== null) {
                $posiciones[(int) $p['id']] = $pos === false ? PHP_INT_MAX : $pos;
            }
        }
        asort($posiciones);

        return array_keys($posiciones);
    }

    /**
     * El plan al que pertenece el marcador de precio: el último nombrado
     * ANTES del marcador dentro de la misma oración, o null si no hay.
     *
     * «Tienes el Mensual y el Trimestral; el precio es {{PLAN_PRICE}}» no
     * tiene dueño —el marcador va en otra oración— y no se adivina. «…pero
     * el Mensual cuesta {{PLAN_PRICE}}» sí: el Mensual.
     *
     * @param  array<int,array{id:int,name:string}>  $activePlans
     */
    public static function planOwningPrice(string $draft, array $activePlans): ?int
    {
        $pos = mb_stripos($draft, '{{');
        if ($pos === false) {
            return null;
        }
        $antes = mb_substr($draft, 0, $pos);
        // Solo la oración que contiene el marcador.
        $trozos = preg_split('/[.;!?]/u', $antes) ?: [];
        $oracion = (string) end($trozos);

        $nombrados = self::plansNamedIn($oracion, $activePlans);

        return $nombrados === [] ? null : (int) end($nombrados);
    }

    /**
     * Un hecho del CRM está en el texto si aparece el arranque de alguno.
     *
     * Misma regla que usa la memoria para anotar «hecho entregado»: los
     * primeros veinticinco caracteres del contenido normalizado. Así lo que
     * exige la política y lo que anota la memoria es la misma cosa.
     *
     * @param  string[]  $knownFacts  contenidos normalizados
     */
    public static function factPresent(string $t, array $knownFacts): bool
    {
        foreach ($knownFacts as $hecho) {
            $h = SalesAgentDecisionSchema::normalize(trim((string) $hecho));
            if (mb_strlen($h) < 12) {
                continue;
            }
            if (str_contains($t, mb_substr($h, 0, min(25, mb_strlen($h))))) {
                return true;
            }
        }

        return false;
    }

    /** La persona rechaza la visita con sus palabras. */
    public static function declinesVisit(string $inbound): bool
    {
        return preg_match(self::NIEGA_VISITA, SalesAgentDecisionSchema::normalize($inbound)) === 1;
    }

    /** El texto sigue hablando de la visita: eso es retomar el objetivo. */
    public static function mentionsVisit(string $draft): bool
    {
        return preg_match(self::HABLA_DE_VISITA, SalesAgentDecisionSchema::normalize($draft)) === 1;
    }

    /**
     * La frase que retoma la visita, construida desde lo que ya se sabe.
     *
     * Se usa de dos formas: como respaldo entero cuando el turno se puso a
     * vender con la visita a medias, y como cola cuando la persona hizo una
     * pregunta incidental y el texto contestó pero olvidó retomar. No es una
     * plantilla de respuesta: es el estado —qué dato falta— dicho en una
     * frase, y no afirma que nada quede registrado ni agendado.
     */
    public static function courtesyResumption(array $policy): ?string
    {
        $objetivo = $policy['active_goal'] ?? null;
        if (! is_array($objetivo) || ($objetivo['kind'] ?? null) !== ConversationMemoryService::GOAL_COURTESY) {
            return null;
        }
        $data = (array) ($objetivo['data'] ?? []);
        $tieneDia = ! empty($data['date']);
        $tieneHora = ! empty($data['time']);

        if (($objetivo['status'] ?? null) === ConversationMemoryService::GOAL_REQUESTED) {
            return 'Y sobre tu visita: la solicitud ya quedó anotada y el equipo la revisa.';
        }

        return 'Y sobre tu visita: '.self::loQueFaltaDeLaVisita($tieneDia, $tieneHora);
    }

    /** El respaldo entero cuando el turno debía seguir con la visita y se puso a vender. */
    public static function courtesyReply(array $policy): ?string
    {
        $objetivo = $policy['active_goal'] ?? null;
        if (! is_array($objetivo) || ($objetivo['kind'] ?? null) !== ConversationMemoryService::GOAL_COURTESY) {
            return null;
        }
        $data = (array) ($objetivo['data'] ?? []);

        return 'Perfecto. Para tu visita al gimnasio, '.self::loQueFaltaDeLaVisita(! empty($data['date']), ! empty($data['time']));
    }

    /** Qué dato falta para la visita, dicho como pregunta. */
    private static function loQueFaltaDeLaVisita(bool $tieneDia, bool $tieneHora): string
    {
        return match (true) {
            $tieneDia && ! $tieneHora => 'ya tengo el día y solo me falta la hora: ¿a qué hora te queda bien?',
            ! $tieneDia && $tieneHora => 'ya tengo la hora y solo me falta el día: ¿qué día te queda bien?',
            default => '¿qué día y a qué hora te queda bien?',
        };
    }

    /**
     * Los planes que el texto nombra Y niega, por su posición.
     *
     * @param  array<int,array{id:int,name:string}>  $activePlans
     * @return int[]
     */
    private static function planesNegadosEn(string $texto, array $activePlans): array
    {
        $negados = [];
        foreach ($activePlans as $p) {
            $nombre = SalesAgentDecisionSchema::normalize((string) ($p['name'] ?? ''));
            if ($nombre === '' || ! self::nombraPalabraCompleta($texto, $nombre)) {
                continue;
            }
            $pos = mb_strpos($texto, $nombre);
            if ($pos === false) {
                continue;
            }
            $antes = mb_substr($texto, max(0, $pos - 25), min(25, $pos));
            $despues = mb_substr($texto, $pos + mb_strlen($nombre), 20);
            if (preg_match(self::NIEGA_ANTES, $antes) === 1 || preg_match(self::NIEGA_DESPUES, $despues) === 1) {
                $negados[] = (int) $p['id'];
            }
        }

        return $negados;
    }

    /** ¿Ya salió algún hecho de la base de conocimiento en esta conversación? */
    private static function algunHechoEntregado(ConversationMemory $memory): bool
    {
        foreach ((array) $memory->get('facts_delivered') as $f) {
            if (str_starts_with((string) ($f['key'] ?? ''), 'kb:')) {
                return true;
            }
        }

        return false;
    }

    private static function planNombradoEn(string $texto, array $activePlans): ?int
    {
        foreach ($activePlans as $p) {
            $nombre = SalesAgentDecisionSchema::normalize((string) ($p['name'] ?? ''));
            if ($nombre !== '' && self::nombraPalabraCompleta($texto, $nombre)) {
                return (int) $p['id'];
            }
        }

        return null;
    }

    /** ¿Aparece ese nombre como palabra, y no dentro de otra? */
    private static function nombraPalabraCompleta(string $texto, string $nombre): bool
    {
        return preg_match('/(?<![a-z0-9])'.preg_quote($nombre, '/').'(?![a-z0-9])/u', $texto) === 1;
    }

    /** @param array<string,mixed> $resolution */
    private static function planDeLaReferencia(array $resolution, array $activePlans): ?int
    {
        $id = $resolution['plan_id'] ?? null;
        if ($id === null) {
            return null;
        }

        $ids = array_map(static fn (array $p) => (int) $p['id'], $activePlans);

        return in_array((int) $id, $ids, true) ? (int) $id : null;
    }

    /** ¿El borrador es sólo una pregunta, sin contar nada? */
    private static function soloPregunta(string $t): bool
    {
        // Sin interrogación no hay interrogatorio.
        if (! str_contains($t, '?')) {
            return false;
        }

        /*
         * Se quita SÓLO la oración interrogativa, no todo lo que va delante.
         *
         * Cortar hasta el `?` desde el punto anterior se llevaba por delante
         * la respuesta: en español la pregunta suele ir detrás de una coma, y
         * «Estamos en la Calle 10, ¿te queda cerca?» perdía la dirección y se
         * juzgaba como puro interrogatorio. Una guarda que descarta la
         * respuesta correcta reproduce el incidente que vino a impedir. La
         * coma y el punto y coma cortan igual que el punto.
         */
        $sinPreguntas = (string) preg_replace(
            [
                // La oración interrogativa ENTERA, de apertura a cierre. Hace
                // falta porque una sola pregunta con enumeración dentro
                // —«¿cuál es tu objetivo: bajar grasa, ganar músculo o
                // mejorar?»— dejaba la enumeración de residuo y el
                // interrogatorio pasaba por respuesta.
                '/¿[^?]*\?/u',
                // Y las que se escriben sin abrir, cortando por coma para no
                // llevarse la respuesta que va delante.
                '/[^.!?;,]*\?/u',
            ],
            '',
            $t,
        );

        /*
         * Y fuera las fórmulas de cortesía, que ocupan sitio sin informar de
         * nada. Sin esto, «gracias por escribirnos. Para orientarte mejor,»
         * sumaba caracteres suficientes para parecer una respuesta.
         */
        $sinRelleno = (string) preg_replace(
            '/\b(hola|buenas|buenos)\s*(dias|tardes|noches)?\b'
            .'|gracias por escribirnos|para (orientarte|ayudarte|entenderte|darte la mejor informacion)( mejor)?'
            .'|por ejemplo|con gusto|claro que si|claro|perfecto|listo/u',
            '',
            $sinPreguntas,
        );

        /*
         * El umbral es bajo a propósito. «Sí, tenemos parqueadero» son veintidós
         * caracteres y es una respuesta completa: pedir más descartaba
         * contestaciones cortas y correctas. Lo que se busca aquí no es
         * elocuencia, es que haya ALGO además de la pregunta.
         */
        return mb_strlen(trim((string) preg_replace('/[^a-z0-9]+/u', ' ', $sinRelleno))) < 15;
    }

    /** ¿Abre con un saludo de los que sólo se dan una vez? */
    private static function abreSaludando(string $t): bool
    {
        return preg_match('/^\s*[^.!?]{0,20}\b(buenos\s+dias|buenas\s+tardes|buenas\s+noches)\b/u', $t) === 1;
    }

    private static function primerPlanNombrado(string $t, array $activePlans): ?int
    {
        $mejor = null;
        $posicion = PHP_INT_MAX;

        foreach ($activePlans as $p) {
            $nombre = SalesAgentDecisionSchema::normalize((string) ($p['name'] ?? ''));
            if ($nombre === '') {
                continue;
            }
            if (! self::nombraPalabraCompleta($t, $nombre)) {
                continue;
            }
            $i = mb_strpos($t, $nombre);
            if ($i !== false && $i < $posicion) {
                $posicion = $i;
                $mejor = (int) $p['id'];
            }
        }

        return $mejor;
    }

    private static function nombraElPlan(string $t, int $planId, array $activePlans): bool
    {
        foreach ($activePlans as $p) {
            if ((int) $p['id'] !== $planId) {
                continue;
            }
            $nombre = SalesAgentDecisionSchema::normalize((string) ($p['name'] ?? ''));

            return $nombre !== '' && self::nombraPalabraCompleta($t, $nombre);
        }

        return false;
    }
}
