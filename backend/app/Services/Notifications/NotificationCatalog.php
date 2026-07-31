<?php

namespace App\Services\Notifications;

use App\Support\Notifications\NotificationCategory as Cat;
use App\Support\Notifications\NotificationSlot as Slot;

/**
 * Catálogo de contenido: motivación, hábitos, nutrición y suplementos.
 *
 * Reglas de tono que se aplicaron a cada texto:
 *  - breve, en segunda persona y sin signos de exclamación acumulados;
 *  - sin culpa ("llevas 5 días sin venir" → "cuando quieras retomar");
 *  - sin promesas de resultados ni de cambios corporales;
 *  - sin vocabulario médico: nada cura, previene ni trata;
 *  - sin marcas, sin dosis personalizadas, sin urgencia comercial.
 *
 * Los textos de suplementos son EDUCATIVOS. Explican qué mirar y cuándo
 * preguntar a un profesional; no dicen a nadie qué tomar. Todos llevan un aviso
 * al pie, editable desde el CRM.
 *
 * Cada plantilla declara dos cosas más:
 *
 *  1. Si DA POR HECHO que el socio puede entrar al gimnasio. «Bebe agua durante
 *     el entrenamiento» no le sirve a quien tiene la membresía vencida; a esa
 *     persona le corresponde el tono de reactivación.
 *
 *  2. En qué FRANJAS del día tiene sentido. Con cinco envíos diarios, «come algo
 *     antes de entrenar» a las diez de la noche deja de ser un consejo y pasa a
 *     ser ruido. `null` = vale a cualquier hora, y es el caso mayoritario a
 *     propósito: cuantas menos plantillas atadas a una hora, más margen tiene la
 *     rotación para no repetirse en catorce días.
 *
 * El tamaño del catálogo no es decorativo. Cinco avisos diarios sin repetir
 * plantilla en catorce días exigen setenta textos distintos por socio, y quien
 * tiene la membresía vencida y los suplementos apagados solo alcanza los que
 * están marcados como válidos para todos. Ese es el número que manda.
 *
 * Se siembran en `notification_templates` y desde ahí el CRM puede editarlos o
 * apagarlos sin desplegar.
 */
final class NotificationCatalog
{
    /** Aviso al pie de todo lo relacionado con suplementos. */
    public const SUPPLEMENT_DISCLAIMER =
        'Información educativa, no consejo médico. Consulta a un profesional de la salud antes de empezar cualquier suplemento.';

    /** La plantilla asume que el socio puede entrenar hoy. */
    private const REQUIERE_MEMBRESIA = true;

    /** Sirve igual a quien está al día y a quien tiene la membresía vencida. */
    private const SIRVE_A_TODOS = false;

    // ── Franjas ────────────────────────────────────────────────────────────
    /** Vale a cualquier hora del día. */
    private const CUALQUIERA = null;

    private const SOLO_MANANA = [Slot::MORNING];

    private const MANANAS = [Slot::MORNING, Slot::MIDMORNING];

    private const CENTRO_DIA = [Slot::MIDMORNING, Slot::AFTERNOON];

    private const TARDE_NOCHE = [Slot::AFTERNOON, Slot::EVENING];

    private const SOLO_NOCHE = [Slot::NIGHT];

    private const CIERRE = [Slot::EVENING, Slot::NIGHT];

    /** Todo el día menos el cierre: nada que invite a moverse a las 21:45. */
    private const DIURNAS = [Slot::MORNING, Slot::MIDMORNING, Slot::AFTERNOON, Slot::EVENING];

    /**
     * @return list<array{key:string,category:string,supplement_kind:?string,title:string,body:string,action_route:?string,disclaimer:?string,requires_active_membership:bool,slots:?list<string>}>
     */
    public static function templates(): array
    {
        return array_merge(
            self::motivation(),
            self::recovery(),
            self::hydration(),
            self::nutrition(),
            self::creatine(),
            self::protein(),
            self::preWorkout(),
            self::multivitamins(),
            self::bcaa(),
        );
    }

    /** Motivación, disciplina, constancia y progreso. */
    private static function motivation(): array
    {
        return self::build(Cat::MOTIVATION, null, null, [
            // ── Requieren poder entrenar hoy ────────────────────────────────
            ['mot_progreso_cuenta', 'Tu progreso también cuenta hoy',
                'Un entrenamiento corto sigue siendo un paso adelante. Revisa tu rutina cuando estés listo.',
                self::REQUIERE_MEMBRESIA, self::DIURNAS],
            ['mot_constancia', 'Mantén la constancia',
                'Cada sesión suma. Hoy puedes avanzar a tu ritmo.',
                self::REQUIERE_MEMBRESIA, self::DIURNAS],
            ['mot_semana_empieza', 'Prepara tu semana',
                'Elegir ahora qué días vas a entrenar hace el resto más fácil.',
                self::REQUIERE_MEMBRESIA, self::MANANAS],
            ['mot_racha', 'Llevas una buena racha',
                'Has sostenido tu rutina varios días seguidos. Buen trabajo.',
                self::REQUIERE_MEMBRESIA, self::CUALQUIERA],
            ['mot_dia_dificil', 'Los días flojos también cuentan',
                'Si hoy no rindes como quisieras, entrenar igual ya es ganancia.',
                self::REQUIERE_MEMBRESIA, self::DIURNAS],
            ['mot_pequenos_pasos', 'Poco, pero hecho',
                'Veinte minutos bien aprovechados valen más que una sesión que no ocurre.',
                self::REQUIERE_MEMBRESIA, self::DIURNAS],
            ['mot_tecnica', 'La técnica primero',
                'Subir peso puede esperar. Un movimiento bien hecho protege lo que ya has ganado.',
                self::REQUIERE_MEMBRESIA, self::CENTRO_DIA],
            ['mot_plan_hoy', 'Decide hoy qué vas a trabajar',
                'Llegar con el plan hecho ahorra tiempo y evita improvisar a medias.',
                self::REQUIERE_MEMBRESIA, self::MANANAS],
            ['mot_calentar', 'Empieza por calentar',
                'Cinco minutos de activación cambian cómo se siente todo lo que viene después.',
                self::REQUIERE_MEMBRESIA, self::CENTRO_DIA],
            ['mot_registra', 'Anota lo que levantas',
                'Llevar registro convierte la sensación en dato y te enseña dónde estás avanzando.',
                self::REQUIERE_MEMBRESIA, self::TARDE_NOCHE],
            ['mot_carga_progresiva', 'Sube de a poco',
                'Progresar despacio y sin dolor llega más lejos que forzar una semana.',
                self::REQUIERE_MEMBRESIA, self::CENTRO_DIA],
            ['mot_cabeza', 'Prepara la cabeza',
                'Decidir que vas antes de pensarlo demasiado es media sesión ganada.',
                self::REQUIERE_MEMBRESIA, self::CENTRO_DIA],
            ['mot_falta_animo', 'Empieza aunque no tengas ganas',
                'Las ganas suelen aparecer en la primera serie, no antes de salir de casa.',
                self::REQUIERE_MEMBRESIA, self::CENTRO_DIA],
            ['mot_repaso_semana', 'Mira la semana completa',
                'Un día flojo pesa poco si la semana entera se sostiene.',
                self::REQUIERE_MEMBRESIA, self::CIERRE],
            ['mot_horario_fijo', 'Ponle una hora',
                'Entrenar siempre a la misma hora convierte la decisión en costumbre.',
                self::REQUIERE_MEMBRESIA, self::MANANAS],
            ['mot_compania', 'Ir acompañado ayuda',
                'Quedar con alguien es una de las formas más simples de no fallar.',
                self::REQUIERE_MEMBRESIA, self::DIURNAS],

            // ── Sirven también a quien tiene la membresía vencida ────────────
            ['mot_vuelve_sin_culpa', 'Cuando quieras retomar, aquí estamos',
                'No hace falta empezar de cero. Vuelve por donde lo dejaste.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_objetivo', 'Recuerda por qué empezaste',
                'Tu objetivo sigue ahí. Hoy puedes acercarte un poco más.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_celebra', 'Has avanzado',
                'Compara con cómo empezaste, no con nadie más.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_puerta_abierta', 'Tu sitio sigue siendo tuyo',
                'Cuando decidas volver, lo retomamos desde donde lo dejaste.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_sin_prisa', 'Sin prisa y sin culpa',
                'Las pausas son parte de cualquier proceso largo. Lo que cuenta es volver.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_un_paso', 'Empieza por lo pequeño',
                'No hace falta un plan perfecto para dar el primer paso.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_disciplina', 'La disciplina pesa más que el ánimo',
                'El ánimo va y viene. Lo que sostiene el resultado es lo que haces igual.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_manana_empieza', 'Hoy empieza ahora',
                'No hace falta esperar al lunes para tomar una decisión mejor.',
                self::SIRVE_A_TODOS, self::MANANAS],
            ['mot_energia_manana', 'Arranca con calma',
                'Los primeros minutos del día marcan el tono del resto.',
                self::SIRVE_A_TODOS, self::SOLO_MANANA],
            ['mot_comparacion', 'Tu ritmo es tuyo',
                'Comparar tu progreso con el de otro suele restar más de lo que suma.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_constante_imperfecto', 'Mejor constante que perfecto',
                'Un plan sencillo que cumples vale más que uno impecable que abandonas.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_balance_noche', 'Haz balance del día',
                'Piensa en una cosa que hiciste bien hoy. Con una basta.',
                self::SIRVE_A_TODOS, self::SOLO_NOCHE],
            ['mot_manana_mejor', 'Deja mañana preparado',
                'Dejar la ropa lista o la mochila hecha quita una excusa por adelantado.',
                self::SIRVE_A_TODOS, self::SOLO_NOCHE],
            ['mot_cierre_amable', 'Cierra el día en paz',
                'No todo tiene que salir. Mañana hay otro intento.',
                self::SIRVE_A_TODOS, self::SOLO_NOCHE],
            ['mot_habito', 'Los hábitos se construyen repitiendo',
                'Lo que hoy cuesta decidir, en unas semanas se hace solo.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_paciencia', 'Los cambios tardan',
                'El cuerpo responde a meses, no a días. Dale tiempo a tu trabajo.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_meta_pequena', 'Ponte una meta corta',
                'Un objetivo de dos semanas se ve y se alcanza. Uno de un año, casi nunca.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_recaida', 'Volver también es progreso',
                'Nadie sostiene una rutina sin cortes. Lo que define es cuánto tardas en retomar.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_agradece_cuerpo', 'Tu cuerpo hace mucho',
                'Moverte, descansar y comer bien son formas de cuidarlo, no de exigirle.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_excusa_tiempo', 'El tiempo casi nunca sobra',
                'Media hora recortada de otra cosa suele ser todo lo que hace falta.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_medir_progreso', 'El progreso no solo se ve',
                'Dormir mejor, cansarte menos o subir escaleras sin ahogo también son avances.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_primer_dia', 'El primer día siempre es el más caro',
                'Después de ese, el resto se parecen entre sí.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['mot_entorno', 'Ponlo fácil',
                'Cambiar el entorno funciona mejor que confiar solo en la fuerza de voluntad.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
        ]);
    }

    /** Descanso, sueño y recuperación. */
    private static function recovery(): array
    {
        return self::build(Cat::RECOVERY, null, null, [
            // ── Requieren poder entrenar hoy ────────────────────────────────
            ['rec_sueno', 'El músculo se construye descansando',
                'El trabajo lo haces en el gimnasio; la recuperación ocurre mientras duermes.',
                self::REQUIERE_MEMBRESIA, self::CUALQUIERA],
            ['rec_dia_libre', 'Un día de descanso no te retrasa',
                'Alternar esfuerzo y descanso es parte del plan, no una pausa en él.',
                self::REQUIERE_MEMBRESIA, self::CUALQUIERA],
            ['rec_molestia', 'Escucha las molestias',
                'Si algo duele más de lo normal, baja la carga y coméntalo con tu entrenador.',
                self::REQUIERE_MEMBRESIA, self::DIURNAS],
            ['rec_estiramiento', 'Dedica unos minutos al final',
                'Estirar y bajar pulsaciones al terminar te deja mejor para la próxima sesión.',
                self::REQUIERE_MEMBRESIA, self::TARDE_NOCHE],
            ['rec_agujetas', 'Las agujetas no miden nada',
                'Que no duela al día siguiente no significa que la sesión no sirviera.',
                self::REQUIERE_MEMBRESIA, self::CUALQUIERA],
            ['rec_series', 'Respeta el descanso entre series',
                'Cortarlo por prisa suele bajar la calidad de lo que viene después.',
                self::REQUIERE_MEMBRESIA, self::CENTRO_DIA],
            ['rec_dos_grupos', 'Alterna lo que trabajas',
                'Darle un día a cada grupo muscular rinde más que insistir en el mismo.',
                self::REQUIERE_MEMBRESIA, self::DIURNAS],
            ['rec_sobreentreno', 'Más no siempre es mejor',
                'Si llevas semanas sin descansar y rindes menos, el cuerpo está pidiendo pausa.',
                self::REQUIERE_MEMBRESIA, self::CUALQUIERA],

            // ── Sirven también a quien tiene la membresía vencida ────────────
            ['rec_descanso', 'Es momento de recuperarte',
                'Dormir, hidratarte y descansar también hacen parte de tu entrenamiento.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['rec_dormir_bien', 'Dormir es la base',
                'Ninguna rutina compensa dormir mal de forma sostenida.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['rec_horas', 'Cuenta las horas de sueño',
                'La mayoría de los adultos funciona mejor entre siete y nueve horas.',
                self::SIRVE_A_TODOS, self::CIERRE],
            ['rec_pantallas', 'Baja las pantallas antes de dormir',
                'La luz fuerte en la última hora del día retrasa el sueño.',
                self::SIRVE_A_TODOS, self::SOLO_NOCHE],
            ['rec_rutina_noche', 'Una rutina para dormir',
                'Hacer siempre lo mismo antes de acostarte le avisa al cuerpo de que toca parar.',
                self::SIRVE_A_TODOS, self::SOLO_NOCHE],
            ['rec_cafeina_tarde', 'Ojo con la cafeína tardía',
                'Su efecto dura horas. Tomarla al final del día puede costarte sueño.',
                self::SIRVE_A_TODOS, self::TARDE_NOCHE],
            ['rec_siesta', 'Una siesta corta basta',
                'Veinte minutos reponen sin dejarte espeso ni quitarte sueño por la noche.',
                self::SIRVE_A_TODOS, self::CENTRO_DIA],
            ['rec_caminar', 'Moverte suave también recupera',
                'Caminar un rato ayuda más que quedarse quieto cuando el cuerpo está cargado.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['rec_estres', 'El estrés también cansa',
                'Un día tenso pesa en el cuerpo aunque no te hayas movido.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['rec_respirar', 'Respira despacio un minuto',
                'Alargar la exhalación es de las formas más rápidas de bajar revoluciones.',
                self::SIRVE_A_TODOS, self::CIERRE],
            ['rec_habitacion', 'Cuida dónde duermes',
                'Oscuridad, silencio y algo de fresco hacen más por tu descanso que cualquier truco.',
                self::SIRVE_A_TODOS, self::SOLO_NOCHE],
            ['rec_desconectar', 'Desconecta antes de acostarte',
                'Dejar los pendientes escritos ayuda a que no te acompañen a la cama.',
                self::SIRVE_A_TODOS, self::SOLO_NOCHE],
            ['rec_constante_sueno', 'Duerme a horas parecidas',
                'La regularidad importa tanto como el total de horas.',
                self::SIRVE_A_TODOS, self::CIERRE],
            ['rec_dolor_persistente', 'Un dolor que no se va merece consulta',
                'Si algo lleva semanas molestando, conviene que lo mire un profesional.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['rec_domingo', 'Un día sin plan también sirve',
                'Descansar de verdad es parte del proceso, no una interrupción.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['rec_calor_noche', 'El calor estorba al sueño',
                'Ventilar un rato antes de acostarte suele ayudar más que cualquier otra cosa.',
                self::SIRVE_A_TODOS, self::SOLO_NOCHE],
            ['rec_musculo_cansado', 'El cansancio acumulado avisa',
                'Si arrastras varios días pesados, bajar el ritmo es una decisión, no una rendición.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
        ]);
    }

    /** Hidratación y hábitos diarios. */
    private static function hydration(): array
    {
        return self::build(Cat::HYDRATION, null, null, [
            // ── Requieren poder entrenar hoy ────────────────────────────────
            ['hid_durante', 'Bebe agua durante el entrenamiento',
                'No esperes a tener sed: da pequeños tragos a lo largo de la sesión.',
                self::REQUIERE_MEMBRESIA, self::CENTRO_DIA],
            ['hab_desayuno', 'Come algo antes de entrenar',
                'Entrenar en vacío puede restarte energía. Algo ligero suele bastar.',
                self::REQUIERE_MEMBRESIA, self::MANANAS],
            ['hab_rutina_sueno', 'Un horario estable ayuda',
                'Acostarte y levantarte a horas parecidas mejora cómo rindes al entrenar.',
                self::REQUIERE_MEMBRESIA, self::CUALQUIERA],
            ['hid_botella', 'Lleva tu botella',
                'Tenerla a la vista es el recordatorio más eficaz que existe.',
                self::REQUIERE_MEMBRESIA, self::CENTRO_DIA],
            ['hid_despues', 'Repón lo que sudaste',
                'Después de una sesión larga conviene seguir bebiendo un buen rato.',
                self::REQUIERE_MEMBRESIA, self::TARDE_NOCHE],
            ['hab_ropa_lista', 'Deja la ropa preparada',
                'Quitar pasos pequeños entre tú y la sesión hace que ocurra más veces.',
                self::REQUIERE_MEMBRESIA, self::CIERRE],

            // ── Sirven también a quien tiene la membresía vencida ────────────
            ['hid_calor', 'Hoy hace calor',
                'Con temperaturas altas sudas más de lo que notas. Ten agua a mano.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['hid_dia', 'La hidratación es de todo el día',
                'Repartir el agua a lo largo del día funciona mejor que beber mucho de golpe.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['hid_senal', 'La sed llega tarde',
                'Cuando aparece, ya llevas un rato por debajo de lo que necesitas.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['hid_primer_vaso', 'Empieza el día con agua',
                'Un vaso al levantarte es el hábito más fácil de sostener del día.',
                self::SIRVE_A_TODOS, self::SOLO_MANANA],
            ['hid_color', 'Un indicador simple',
                'El color claro de la orina suele ser buena señal de que vas bien de agua.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['hid_azucar', 'Cuidado con las bebidas azucaradas',
                'Hidratan, pero traen mucho azúcar de acompañante. El agua sigue ganando.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['hid_cafe', 'El café también cuenta',
                'Suma líquido, aunque conviene que no sea tu única fuente del día.',
                self::SIRVE_A_TODOS, self::MANANAS],
            ['hab_pausas', 'Levántate cada tanto',
                'Si pasas horas sentado, unos minutos de pie cada hora se notan al final del día.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['hab_pasos', 'Camina un poco más',
                'Moverte fuera del gimnasio también cuenta, y suele ser lo más fácil de añadir.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['hab_escaleras', 'Elige las escaleras',
                'Es de los cambios pequeños que no cuestan tiempo y se acumulan.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['hab_pantalla_comida', 'Come sin pantalla',
                'Prestar atención a la comida ayuda a notar cuándo ya tienes suficiente.',
                self::SIRVE_A_TODOS, self::CENTRO_DIA],
            ['hab_alcohol', 'El alcohol pasa factura al descanso',
                'Aunque dé sueño, empeora la calidad de lo que duermes después.',
                self::SIRVE_A_TODOS, self::CIERRE],
            ['hab_sol', 'Sal a la luz un rato',
                'Un poco de luz natural temprano ayuda a que el sueño llegue a su hora.',
                self::SIRVE_A_TODOS, self::MANANAS],
            ['hid_botella_casa', 'Ten agua siempre cerca',
                'Dejarla a la vista en casa o en el trabajo hace el resto por ti.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['hab_respiro_pantallas', 'Descansa la vista',
                'Mirar lejos unos segundos cada tanto alivia más de lo que parece.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['hid_noche', 'No cargues agua justo al acostarte',
                'Beber bien durante el día evita tener que compensar a última hora.',
                self::SIRVE_A_TODOS, self::SOLO_NOCHE],
            ['hab_movimiento_corto', 'Cinco minutos cuentan',
                'Un rato de movimiento suelto vale más que esperar el momento perfecto.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['hid_recordatorio', 'Ponte una señal',
                'Asociar el agua a algo que ya haces cada día es lo que hace que no se olvide.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['hab_postura', 'Revisa cómo estás sentado',
                'Cambiar de postura de vez en cuando alivia más que buscar la postura perfecta.',
                self::SIRVE_A_TODOS, self::DIURNAS],
        ]);
    }

    /**
     * Nutrición: alimentación real, no suplementación.
     *
     * Va en su propia categoría porque el socio debe poder apagar los consejos
     * de comida sin perder los de hidratación ni los de descanso. Comparte
     * categoría con los avisos de nutrición del coach, que es justo lo que se
     * espera: quien no quiere que le hablen de comida, no quiere de ninguna.
     */
    private static function nutrition(): array
    {
        return self::build(Cat::NUTRITION, null, null, [
            // ── Requieren poder entrenar hoy ────────────────────────────────
            ['nut_pre_ligero', 'Come ligero antes de moverte',
                'Algo sencillo una o dos horas antes suele sentar mejor que una comida pesada.',
                self::REQUIERE_MEMBRESIA, self::CENTRO_DIA],
            ['nut_post', 'Come algo después',
                'Una comida completa en las horas siguientes ayuda a recuperar mejor.',
                self::REQUIERE_MEMBRESIA, self::TARDE_NOCHE],
            ['nut_energia_tarde', 'Si entrenas tarde, no llegues en ayunas',
                'Un tentempié a media tarde suele arreglar la falta de energía al final del día.',
                self::REQUIERE_MEMBRESIA, self::CENTRO_DIA],
            ['nut_proteina_post', 'Proteína en la comida siguiente',
                'Incluir una fuente de proteína después de entrenar es una costumbre sencilla y útil.',
                self::REQUIERE_MEMBRESIA, self::TARDE_NOCHE],
            ['nut_hidratos_dia', 'Los carbohidratos son combustible',
                'Si entrenas fuerte, quitarlos del todo suele restar más que sumar.',
                self::REQUIERE_MEMBRESIA, self::DIURNAS],
            ['nut_planifica_semana', 'Deja comida lista',
                'Cocinar de más un día evita improvisar mal el resto de la semana.',
                self::REQUIERE_MEMBRESIA, self::CIERRE],

            // ── Sirven también a quien tiene la membresía vencida ────────────
            ['nut_desayuno_real', 'Un desayuno que sostenga',
                'Proteína y algo de fibra aguantan mejor la mañana que el azúcar solo.',
                self::SIRVE_A_TODOS, self::SOLO_MANANA],
            ['nut_verduras', 'Que haya color en el plato',
                'Verdura en dos comidas del día es de los cambios que más rinden.',
                self::SIRVE_A_TODOS, self::CENTRO_DIA],
            ['nut_proteina_reparto', 'Reparte la proteína',
                'Incluir algo en cada comida suele funcionar mejor que concentrarla en una.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['nut_ultraprocesados', 'Menos ultraprocesados',
                'No hace falta eliminarlos: bajar la frecuencia ya cambia bastante.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['nut_fibra', 'La fibra ayuda',
                'Fruta, verdura y legumbre te dejan más saciado con menos esfuerzo.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['nut_cena_ligera', 'Cena sin sobrecargar',
                'Una cena muy pesada suele pasarle factura al sueño.',
                self::SIRVE_A_TODOS, self::CIERRE],
            ['nut_compra', 'La lista antes del súper',
                'Lo que no entra en casa no hay que resistirlo después.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['nut_snack', 'Ten a mano algo sencillo',
                'Fruta o frutos secos evitan muchas decisiones malas a media tarde.',
                self::SIRVE_A_TODOS, self::CENTRO_DIA],
            ['nut_azucar_bebidas', 'El azúcar líquido pasa desapercibido',
                'Se bebe rápido, no sacia y suma más de lo que parece.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['nut_cocinar_casa', 'Cocinar en casa da control',
                'No hace falta que sea elaborado: basta con que sea tuyo.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['nut_porciones', 'Mira las porciones sin obsesionarte',
                'Servir en plato en vez de comer del paquete ya ordena bastante.',
                self::SIRVE_A_TODOS, self::CENTRO_DIA],
            ['nut_desayuno_no_obligatorio', 'No hay una única forma correcta',
                'Si desayunar no te sienta bien, lo que importa es cómo queda el día completo.',
                self::SIRVE_A_TODOS, self::SOLO_MANANA],
            ['nut_comer_despacio', 'Come sin prisa',
                'La sensación de saciedad tarda un rato en llegar.',
                self::SIRVE_A_TODOS, self::CENTRO_DIA],
            ['nut_equilibrio', 'Ninguna comida arruina nada',
                'Lo que decide es el patrón de semanas, no un plato suelto.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['nut_legumbres', 'Las legumbres rinden mucho',
                'Baratas, saciantes y fáciles de dejar cocinadas para varios días.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['nut_frutas', 'Fruta entera mejor que zumo',
                'La pieza entera trae fibra y llena más que el vaso.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['nut_sal', 'Revisa la sal escondida',
                'La mayor parte no viene del salero, sino de lo que ya viene preparado.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['nut_profesional', 'Un plan a tu medida lo hace un profesional',
                'Si buscas algo personalizado, consultar a nutrición es el camino corto.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['nut_huevos', 'Opciones sencillas de proteína',
                'Huevo, atún, yogur o queso fresco resuelven una comida sin complicarse.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['nut_agua_comida', 'Agua en la mesa',
                'Es la bebida que acompaña cualquier comida sin sumar nada de más.',
                self::SIRVE_A_TODOS, self::CENTRO_DIA],
            ['nut_desayuno_prisa', 'Si vas con prisa, deja algo listo',
                'Preparar la noche anterior evita salir de casa sin nada.',
                self::SIRVE_A_TODOS, self::SOLO_NOCHE],
            ['nut_antojo', 'Los antojos pasan',
                'Esperar diez minutos antes de decidir suele bastar para que aflojen.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
        ]);
    }

    // Los suplementos son información educativa y valen igual con la membresía
    // al día o vencida: no invitan a entrenar, explican qué mirar.

    private static function creatine(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_CREATINE, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_crea_constancia', 'Creatina: la constancia importa',
                'Su efecto depende de tomarla a diario durante semanas, no de un día suelto.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_crea_agua', 'Creatina e hidratación',
                'Si la tomas, acompáñala de una buena hidratación a lo largo del día.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['sup_crea_tiempo', 'No esperes cambios inmediatos',
                'Los efectos, cuando aparecen, se ven con semanas de uso constante y entrenamiento.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_crea_etiqueta', 'Lee la etiqueta',
                'Revisa la porción indicada por el fabricante y no la superes por tu cuenta.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_crea_consulta', 'Cuándo preguntar antes',
                'Si tienes una condición renal, tomas medicación, estás embarazada o en lactancia, consulta a un profesional antes de empezar.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
        ]);
    }

    private static function protein(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_PROTEIN, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_prot_comida', 'La comida va primero',
                'Huevos, carnes, lácteos y legumbres cubren la proteína de la mayoría de las personas.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_prot_complemento', 'Un suplemento complementa, no sustituye',
                'La proteína en polvo sirve para completar lo que falta, no es obligatoria.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_prot_porcion', 'Revisa porción y alérgenos',
                'Comprueba en la etiqueta la cantidad por medida y si contiene lactosa, soja o frutos secos.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_prot_individual', 'Tus necesidades son tuyas',
                'La cantidad que te conviene depende de tu peso, tu actividad y tu salud. Consúltalo con un profesional.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
        ]);
    }

    private static function preWorkout(): array
    {
        // Nunca en el cierre del día: hablar de estimulantes a las 21:45 va justo
        // en contra de lo que dicen los propios textos sobre el sueño.
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_PRE_WORKOUT, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_pre_cafeina', 'Mira la cafeína por porción',
                'Los preentrenos varían mucho entre marcas. Comprueba cuánta cafeína lleva una medida.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['sup_pre_suma', 'No sumes estimulantes',
                'Café, bebidas energéticas y preentreno se acumulan. Cuenta el total del día.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['sup_pre_sueno', 'Cuidado con la hora',
                'Tomarlo por la tarde o noche puede dificultarte dormir, y el descanso es parte del progreso.',
                self::SIRVE_A_TODOS, self::CENTRO_DIA],
            ['sup_pre_precaucion', 'Cuándo tener precaución',
                'Si eres sensible a la cafeína, tienes hipertensión, ansiedad, una condición cardiovascular o tomas medicación, consulta antes a un profesional.',
                self::SIRVE_A_TODOS, self::DIURNAS],
            ['sup_pre_dosis', 'Más no es mejor',
                'Empezar por debajo de la porción indicada es una forma prudente de ver cómo te sienta.',
                self::SIRVE_A_TODOS, self::DIURNAS],
        ]);
    }

    private static function multivitamins(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_MULTIVITAMINS, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_multi_dieta', 'No sustituyen a la comida',
                'Un multivitamínico no reemplaza una alimentación variada; la complementa cuando falta algo.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_multi_duplicar', 'Evita duplicar',
                'Si tomas varios productos, revisa que no repitan las mismas vitaminas o minerales.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_multi_etiqueta', 'Respeta la etiqueta',
                'Más cantidad no aporta más beneficio, y en algunos micronutrientes puede ser contraproducente.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_multi_consulta', 'Cuándo consultar',
                'En embarazo, lactancia, con medicación o con alguna enfermedad, conviene preguntar antes a un profesional.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
        ]);
    }

    private static function bcaa(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_BCAA, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_bcaa_necesidad', 'Los BCAA no siempre hacen falta',
                'Si ya cubres tu proteína diaria, es probable que no aporten nada adicional.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_bcaa_prioridad', 'Primero el total del día',
                'La proteína completa de la comida tiene prioridad sobre cualquier aminoácido aislado.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_bcaa_expectativa', 'Qué esperar',
                'No hay motivo para esperar de ellos un cambio en tu masa muscular por sí solos.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
            ['sup_bcaa_etiqueta', 'Compara antes de comprar',
                'Revisa la etiqueta y valora si lo que aporta justifica su precio frente a tu comida habitual.',
                self::SIRVE_A_TODOS, self::CUALQUIERA],
        ]);
    }

    /** @param list<array{0:string,1:string,2:string,3:bool,4:?list<string>}> $rows */
    private static function build(string $category, ?string $kind, ?string $disclaimer, array $rows): array
    {
        return array_map(fn (array $r): array => [
            'key' => $r[0],
            'category' => $category,
            'supplement_kind' => $kind,
            'title' => $r[1],
            'body' => $r[2],
            'action_route' => null,
            'disclaimer' => $disclaimer,
            'requires_active_membership' => $r[3],
            'slots' => $r[4],
        ], $rows);
    }
}
