<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\CommercialPhaseMachine as Fase;

/**
 * CÓMO se vende en cada punto de la conversación. No QUÉ se dice.
 *
 * Existe por un hueco de calidad, no de seguridad: los cerrojos que ya hay
 * impiden que ULTRON mienta, prometa o presione, pero nada le decía cómo
 * vender bien. El resultado era un agente correcto y plano —contestaba, no
 * vendía—, que soltaba características antes de saber para qué las quiere la
 * persona, y que trataba igual a alguien que acaba de escribir «hola» y a
 * alguien que ya dijo «quiero pagar».
 *
 * Esta clase condensa PRINCIPIOS de venta consultiva y de influencia ética en
 * directrices de lenguaje, y elige cuáles aplican según la fase comercial.
 *
 * Reglas duras de esta clase, y la razón de cada una:
 *
 *  - NO IMITA A NADIE. Los principios vienen de la literatura de ventas
 *    (Rackham y la venta consultiva, Carnegie y el interés genuino, Girard y
 *    la relación a largo plazo, Ziglar y el valor antes que el producto,
 *    Cialdini y la influencia ética, Mary Kay y la dignidad del cliente,
 *    Cardone y el cierre decidido), pero de ahí sale el PRINCIPIO, nunca la
 *    persona ni la frase. Ningún nombre propio ni cita textual sale de aquí
 *    hacia el modelo: si un nombre llegara al prompt, el modelo intentaría
 *    imitar a esa persona en vez de aplicar la idea, y un vendedor imitado en
 *    WhatsApp suena a personaje. {@see UltronSalesPlaybookTest} lo verifica.
 *
 *  - NO DECIDE NADA. Devuelve lenguaje: qué priorizar al redactar y qué evitar.
 *    Quién saluda, qué plan se nombra, qué herramienta se usa y qué se puede
 *    afirmar lo sigue decidiendo {@see CommercialTurnPolicy} y las autoridades
 *    de Laravel. Aquí no hay un id de plan, ni una cifra, ni el nombre de una
 *    herramienta, y el test lo comprueba. Una capa de estilo que pudiera abrir
 *    una acción sería una puerta trasera a todo lo demás.
 *
 *  - PURA. Sin base de datos, sin red, sin config y sin hora. Entra una fase y
 *    unas señales que el contexto YA tiene; sale una lista de directrices. El
 *    modelo no investiga en internet en cada conversación: la doctrina viaja
 *    compilada en el turno.
 *
 *  - EL PISO ÉTICO NO DEPENDE DE LA FASE. Lo que nunca se hace se devuelve
 *    igual en las dieciocho fases, incluidas las de cierre. Una técnica de
 *    cierre que se activara «solo al final» es exactamente donde se cuela la
 *    presión.
 */
final class UltronSalesPlaybook
{
    // ── Técnicas ──────────────────────────────────────────────────────────────
    // Nombres funcionales a propósito: describen lo que se hace, no a quién se
    // parece. Es lo que viaja al modelo.

    /** Preguntar antes de proponer: situación, problema, efecto, beneficio. */
    public const DESCUBRIMIENTO_CONSULTIVO = 'consultive_discovery';

    /** Interés real por la persona: su nombre, su meta, su palabra. */
    public const INTERES_GENUINO = 'genuine_interest';

    /** Traducir característica en consecuencia para ESA persona. */
    public const TRADUCCION_DE_VALOR = 'value_translation';

    /** Influencia ética: prueba real, reciprocidad, coherencia. Nunca fabricada. */
    public const INFLUENCIA_ETICA = 'ethical_influence';

    /** Dignidad del cliente: nadie queda mal por dudar, preguntar o decir no. */
    public const DIGNIDAD_DEL_CLIENTE = 'customer_dignity';

    /** La relación sobrevive al turno: lo dicho se recuerda y se retoma. */
    public const MEMORIA_DE_RELACION = 'relationship_memory';

    /** Cierre decidido: cuando la persona YA decidió, no se le estorba. */
    public const CIERRE_DECIDIDO = 'decided_closing';

    /**
     * Qué significa cada técnica, en imperativo y en una línea.
     *
     * Una línea por técnica y no un párrafo: esto entra en cada turno, y una
     * doctrina larga se la salta el modelo antes que una corta.
     */
    private const DOCTRINA = [
        self::DESCUBRIMIENTO_CONSULTIVO => [
            'Antes de proponer, entiende: para qué quiere entrenar, desde cuándo lo viene pensando y qué le ha frenado.',
            'Una sola pregunta por mensaje, y que sea la que de verdad falta para poder ayudar.',
            'Si ya contó su objetivo, no lo vuelvas a preguntar: úsalo.',
        ],
        self::INTERES_GENUINO => [
            'Habla de la persona, no de ti: su meta antes que nuestro catálogo.',
            'Usa su nombre cuando lo sepas, y una sola vez: repetirlo suena a plantilla.',
            'Reconoce lo que dijo con sus propias palabras antes de añadir las tuyas.',
        ],
        self::TRADUCCION_DE_VALOR => [
            'No enumeres características: di qué cambia para ella. «Tienes rutina en la app» vale menos que «no llegas a improvisar».',
            'Conecta cada cosa que menciones con el objetivo que la persona ya dijo.',
            'Dos ideas bien traducidas convencen más que ocho características seguidas.',
        ],
        self::INFLUENCIA_ETICA => [
            'Apóyate solo en lo que es verdad y está confirmado en el contexto.',
            'La prueba social solo si es un hecho del negocio; si no la tienes, no la insinúes.',
            'Si la persona ya dio un paso, reconócelo y sigue desde ahí.',
        ],
        self::DIGNIDAD_DEL_CLIENTE => [
            'Que dudar salga gratis: ninguna respuesta debe hacer sentir mal por preguntar el precio, comparar o pensarlo.',
            'Trata a quien todavía no compra como a quien ya compró.',
            'Si no le sirve, dilo: perder una venta honesta es más barato que un cliente enfadado.',
        ],
        self::MEMORIA_DE_RELACION => [
            'Retoma lo que ya sabes de esta conversación en lugar de empezar de cero.',
            'Quien ya compró es una relación, no un cierre: pregunta cómo va, no qué más compra.',
        ],
        self::CIERRE_DECIDIDO => [
            'Cuando la persona ya decidió, deja de vender: dale el siguiente paso, claro y en una frase.',
            'Ni una pregunta más de descubrimiento, ni un beneficio más: estorban.',
            'Decidido no es lo mismo que presionado: si dice que lo piensa, se piensa.',
        ],
    ];

    /**
     * Qué técnicas aplican en cada fase.
     *
     * Indexado por las fases REALES de {@see CommercialPhaseMachine}, que son
     * dieciocho, y no por las etiquetas conceptuales con que se suele hablar de
     * un embudo: una doctrina indexada por fases que no existen no se aplicaría
     * nunca y nadie lo notaría.
     */
    private const POR_FASE = [
        // Todavía no hay nada que vender: hay que caer bien y ser útil.
        Fase::NEW_LEAD => [self::INTERES_GENUINO, self::DIGNIDAD_DEL_CLIENTE],
        Fase::RAPPORT => [self::INTERES_GENUINO, self::DIGNIDAD_DEL_CLIENTE],

        // Preguntar es el trabajo. Proponer aquí es adivinar.
        Fase::DISCOVERY => [self::DESCUBRIMIENTO_CONSULTIVO, self::INTERES_GENUINO],
        Fase::GOAL_DISCOVERY => [self::DESCUBRIMIENTO_CONSULTIVO, self::INTERES_GENUINO],
        Fase::BARRIER_DISCOVERY => [self::DESCUBRIMIENTO_CONSULTIVO, self::DIGNIDAD_DEL_CLIENTE],
        Fase::QUALIFICATION => [self::DESCUBRIMIENTO_CONSULTIVO, self::INTERES_GENUINO],

        // Ya se sabe para qué: ahora se traduce, no se enumera.
        Fase::VALUE_BUILDING => [self::DESCUBRIMIENTO_CONSULTIVO, self::TRADUCCION_DE_VALOR],
        Fase::RECOMMENDATION => [self::TRADUCCION_DE_VALOR, self::INFLUENCIA_ETICA],

        // Una objeción es información, no un ataque.
        Fase::OBJECTION_HANDLING => [
            self::DESCUBRIMIENTO_CONSULTIVO, self::TRADUCCION_DE_VALOR, self::DIGNIDAD_DEL_CLIENTE,
        ],

        // Ya decidió. Aquí el error es seguir vendiendo.
        Fase::BUYING_SIGNAL => [self::CIERRE_DECIDIDO, self::TRADUCCION_DE_VALOR],
        Fase::CLOSING => [self::CIERRE_DECIDIDO],
        Fase::PAYMENT_HANDOFF => [self::CIERRE_DECIDIDO],

        // Nadie escribe primero en la v1, pero si la persona vuelve, se retoma.
        Fase::FOLLOW_UP => [self::MEMORIA_DE_RELACION, self::DIGNIDAD_DEL_CLIENTE],
        Fase::NURTURE => [self::MEMORIA_DE_RELACION, self::DIGNIDAD_DEL_CLIENTE],

        /*
         * Aquí NO se vende, y por eso la lista está vacía a propósito.
         *
         * Quien pide un humano, quien se dio de baja, quien dijo que no y quien
         * ya compró no están en una fase del embudo: están en una conversación
         * que no es una venta. Colar una técnica de cierre en cualquiera de
         * estas cuatro es la clase de detalle por la que un agente se vuelve
         * insoportable.
         */
        Fase::HUMAN_HANDOFF => [],
        Fase::WON => [self::MEMORIA_DE_RELACION],
        Fase::LOST => [self::DIGNIDAD_DEL_CLIENTE],
        Fase::DO_NOT_CONTACT => [],
    ];

    /**
     * Dónde no se vende, pase lo que pase.
     *
     * Estaba implícito en que {@see POR_FASE} las dejaba vacías, y era falso:
     * las ramas de señales se aplican DESPUÉS del match de fase, así que
     * `DO_NOT_CONTACT` con `returning` —que es el estado normal, porque el
     * resumen de la conversación se reescribe en cada turno— devolvía «pregunta
     * cómo va», y `HUMAN_HANDOFF` con una barrera declarada devolvía seis
     * directrices de venta. A quien pidió un humano o pidió que no le
     * escribieran no se le vende, y eso no puede depender de una señal.
     */
    private const SIN_VENTA = [Fase::HUMAN_HANDOFF, Fase::DO_NOT_CONTACT];

    /**
     * Lo que no se hace nunca, en ninguna fase.
     *
     * Duplica a propósito parte de lo que ya bloquea el código: si el modelo
     * no lo intenta, el cerrojo no tiene que dispararse, y un turno que muere
     * en un guardián es un turno perdido para la persona que está esperando.
     */
    private const PISO_ETICO = [
        'No afirmes nada que no esté en el contexto: ni horarios, ni beneficios, ni resultados, ni cupos.',
        'No prometas resultados físicos, plazos de pérdida de peso ni nada que dependa del cuerpo de otra persona.',
        'No inventes urgencia: ni plazos, ni cupos, ni precios que suben, si no están confirmados.',
        'No presiones después de un no: quien dijo que no lo pensará mejor si nadie insiste.',
        // La única línea del piso ético con una puerta, porque la abre el
        // negocio y no el modelo: si una frase está aprobada por escrito,
        // decirla es legítimo. Sin esa puerta, la doctrina contradiría a la
        // lista de frases aprobadas que viaja en el mismo turno.
        'No compares con otros gimnasios ni te declares el mejor de ningún sitio, salvo que la frase esté '
            .'literalmente en las frases de marca aprobadas por el negocio.',
        'No hables de salud como si diagnosticaras: eso lo ve una persona del equipo.',
        'No uses la culpa, el miedo ni el físico de nadie como argumento de venta.',
    ];

    /**
     * La doctrina de este turno, lista para el prompt.
     *
     * Las señales NO son estado nuevo: salen de hechos que el contexto ya
     * lleva (si rechazó un plan, si ya es miembro, si hay una barrera declarada).
     * Inventar aquí un estado paralelo al de {@see ConversationMemory} sería
     * crear una segunda verdad sobre la misma conversación.
     *
     * @param  string  $phase  Fase de {@see CommercialPhaseMachine}.
     * @param  array{rejected_a_plan?: bool, is_member?: bool, has_barrier?: bool, returning?: bool}  $signals
     * @return array{phase: string, techniques: array<int, string>, directives: array<int, string>, never: array<int, string>}
     */
    public static function forPhase(string $phase, array $signals = []): array
    {
        if (in_array($phase, self::SIN_VENTA, true)) {
            // El piso ético sigue viajando: lo que no se hace nunca no deja de
            // regir porque esta conversación ya no sea una venta.
            return ['phase' => $phase, 'techniques' => [], 'directives' => [], 'never' => self::PISO_ETICO];
        }

        $tecnicas = self::POR_FASE[$phase] ?? [self::INTERES_GENUINO, self::DIGNIDAD_DEL_CLIENTE];

        /*
         * Duda y rechazo APAGAN el cierre.
         *
         * Es la corrección más importante de toda esta capa: la fase la calcula
         * la máquina a partir de la intención del último mensaje, así que
         * alguien que escribe «me interesa pero está caro» puede aterrizar en
         * BUYING_SIGNAL. Cerrar ahí es exactamente lo que convierte a un
         * vendedor en un vendedor pesado.
         */
        if (($signals['rejected_a_plan'] ?? false) || ($signals['has_barrier'] ?? false)) {
            $tecnicas = array_values(array_diff($tecnicas, [self::CIERRE_DECIDIDO]));
            $tecnicas[] = self::DIGNIDAD_DEL_CLIENTE;
            $tecnicas[] = self::INTERES_GENUINO;
        }

        // A quien ya es miembro no se le vende lo que ya tiene.
        if ($signals['is_member'] ?? false) {
            $tecnicas = array_values(array_diff($tecnicas, [self::CIERRE_DECIDIDO, self::INFLUENCIA_ETICA]));
            $tecnicas[] = self::MEMORIA_DE_RELACION;
        }

        // Vuelve después de un rato: lo que ya contó no se vuelve a preguntar.
        if ($signals['returning'] ?? false) {
            $tecnicas[] = self::MEMORIA_DE_RELACION;
        }

        $tecnicas = array_values(array_unique($tecnicas));

        $directrices = [];
        foreach ($tecnicas as $t) {
            foreach (self::DOCTRINA[$t] ?? [] as $linea) {
                $directrices[] = $linea;
            }
        }

        return [
            'phase' => $phase,
            'techniques' => $tecnicas,
            'directives' => array_values(array_unique($directrices)),
            'never' => self::PISO_ETICO,
        ];
    }

    /**
     * Las señales, derivadas de lo que el contexto ya sabe.
     *
     * Vive aquí y no en el servicio que arma el contexto para que la regla sea
     * una sola y se pueda probar sin base de datos.
     *
     * @return array{rejected_a_plan: bool, is_member: bool, has_barrier: bool, returning: bool}
     */
    public static function signalsFrom(
        ConversationMemory $memory,
        bool $isMember = false,
        ?string $mainBarrier = null,
        ?string $summary = null,
    ): array {
        return [
            'rejected_a_plan' => $memory->rejectedPlans() !== [],
            'is_member' => $isMember,
            'has_barrier' => is_string($mainBarrier) && trim($mainBarrier) !== '',
            'returning' => is_string($summary) && trim($summary) !== '',
        ];
    }
}
