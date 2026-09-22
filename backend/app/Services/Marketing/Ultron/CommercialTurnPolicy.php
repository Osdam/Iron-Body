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

    /**
     * Palabras con las que la gente pide OTRA cosa. Si aparecen, el plan
     * insignia deja de ser obligatorio: insistir con el mismo es no escuchar.
     */
    private const PIDE_OTRO = '/\b(otro\s+plan|otra\s+opcion|mas\s+economic|mas\s+barat|muy\s+caro|esta\s+caro|no\s+quiero\s+ese|algo\s+mas\s+(barato|economico))/u';

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
        $referido = self::planNombradoEn($texto, $activePlans)
            ?? self::planDeLaReferencia($resolution, $activePlans);

        $planYaElegido = in_array($intent, self::PLAN_YA_ELEGIDO, true);
        $hablaDePlanes = $pideRecomendacion || $referido !== null;

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
        if ($pideRecomendacion && ! $planYaElegido && $insignia !== null && ! $pideOtro
            && ! in_array($insignia, $rechazados, true)
            && $referido === null) {
            $obligatorio = $insignia;
            $origen = 'commercial_priority';
        }
        if ($referido !== null) {
            $obligatorio = $referido;
            $origen = 'named_by_person';
        }

        // Y cuando pide otro, el insignia queda PROHIBIDO en este turno.
        $prohibidos = [];
        if ($pideOtro && $insignia !== null) {
            $prohibidos[] = $insignia;
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

        $informativo = in_array($intent, self::INFORMATIVAS, true);
        $primeraInfo = $intent === SalesIntents::GENERAL_INFO && ! $hablaDePlanes;

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
            'allowed_plan_ids' => $permitidos,
            'forbidden_plan_ids' => $prohibidos,
            'forbidden_actions' => $primeraInfo ? self::PROHIBIDO_EN_INFO : [],
            'required_facts' => $primeraInfo ? ['gym_identity'] : [],
            'allowed_next_actions' => $primeraInfo
                ? ['answer', 'orient', 'open_question']
                : ['answer', 'orient', 'open_question', 'recommend_plan'],
            'wants_alternative' => $pideOtro,
        ];
    }

    /**
     * Lo que el borrador incumple, por su nombre. Vacío = cumple.
     *
     * @param  array<string,mixed>  $policy
     * @param  array<int,array<string,mixed>>  $activePlans
     * @return string[]
     */
    public static function violations(string $draft, array $policy, array $activePlans): array
    {
        $t = SalesAgentDecisionSchema::normalize($draft);
        $fallos = [];

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
