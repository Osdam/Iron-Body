<?php

namespace App\Services\Notifications;

use App\Support\Notifications\NotificationCategory as Cat;

/**
 * Catálogo de contenido: motivación, hábitos y suplementos.
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
 * Cada plantilla declara además si DA POR HECHO que el socio puede entrar al
 * gimnasio. «Bebe agua durante el entrenamiento» no le sirve a quien tiene la
 * membresía vencida; a esa persona le corresponde el tono de reactivación.
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

    /**
     * @return list<array{key:string,category:string,supplement_kind:?string,title:string,body:string,action_route:?string,disclaimer:?string,requires_active_membership:bool}>
     */
    public static function templates(): array
    {
        return array_merge(
            self::motivation(),
            self::recovery(),
            self::hydration(),
            self::creatine(),
            self::protein(),
            self::preWorkout(),
            self::multivitamins(),
            self::bcaa(),
        );
    }

    /** Motivación y constancia. Variedad suficiente para no repetirse en meses. */
    private static function motivation(): array
    {
        return self::build(Cat::MOTIVATION, null, null, [
            ['mot_progreso_cuenta', 'Tu progreso también cuenta hoy',
                'Un entrenamiento corto sigue siendo un paso adelante. Revisa tu rutina cuando estés listo.',
                self::REQUIERE_MEMBRESIA],
            ['mot_constancia', 'Mantén la constancia',
                'Cada sesión suma. Hoy puedes avanzar a tu ritmo.',
                self::REQUIERE_MEMBRESIA],
            ['mot_semana_empieza', 'Prepara tu semana',
                'Elegir ahora qué días vas a entrenar hace el resto más fácil.',
                self::REQUIERE_MEMBRESIA],
            ['mot_racha', 'Llevas una buena racha',
                'Has sostenido tu rutina varios días seguidos. Buen trabajo.',
                self::REQUIERE_MEMBRESIA],
            ['mot_dia_dificil', 'Los días flojos también cuentan',
                'Si hoy no rindes como quisieras, entrenar igual ya es ganancia.',
                self::REQUIERE_MEMBRESIA],
            ['mot_pequenos_pasos', 'Poco, pero hecho',
                'Veinte minutos bien aprovechados valen más que una sesión que no ocurre.',
                self::REQUIERE_MEMBRESIA],
            ['mot_tecnica', 'La técnica primero',
                'Subir peso puede esperar. Un movimiento bien hecho protege lo que ya has ganado.',
                self::REQUIERE_MEMBRESIA],

            // ── Sirven también a quien tiene la membresía vencida ────────────
            ['mot_vuelve_sin_culpa', 'Cuando quieras retomar, aquí estamos',
                'No hace falta empezar de cero. Vuelve por donde lo dejaste.',
                self::SIRVE_A_TODOS],
            ['mot_objetivo', 'Recuerda por qué empezaste',
                'Tu objetivo sigue ahí. Hoy puedes acercarte un poco más.',
                self::SIRVE_A_TODOS],
            ['mot_celebra', 'Has avanzado',
                'Compara con cómo empezaste, no con nadie más.',
                self::SIRVE_A_TODOS],
            ['mot_puerta_abierta', 'Tu sitio sigue siendo tuyo',
                'Cuando decidas volver, lo retomamos desde donde lo dejaste.',
                self::SIRVE_A_TODOS],
            ['mot_sin_prisa', 'Sin prisa y sin culpa',
                'Las pausas son parte de cualquier proceso largo. Lo que cuenta es volver.',
                self::SIRVE_A_TODOS],
            ['mot_un_paso', 'Empieza por lo pequeño',
                'No hace falta un plan perfecto para dar el primer paso.',
                self::SIRVE_A_TODOS],
        ]);
    }

    /** Entrenamiento y recuperación. */
    private static function recovery(): array
    {
        return self::build(Cat::RECOVERY, null, null, [
            ['rec_sueno', 'El músculo se construye descansando',
                'El trabajo lo haces en el gimnasio; la recuperación ocurre mientras duermes.',
                self::REQUIERE_MEMBRESIA],
            ['rec_dia_libre', 'Un día de descanso no te retrasa',
                'Alternar esfuerzo y descanso es parte del plan, no una pausa en él.',
                self::REQUIERE_MEMBRESIA],
            ['rec_molestia', 'Escucha las molestias',
                'Si algo duele más de lo normal, baja la carga y coméntalo con tu entrenador.',
                self::REQUIERE_MEMBRESIA],
            ['rec_estiramiento', 'Dedica unos minutos al final',
                'Estirar y bajar pulsaciones al terminar te deja mejor para la próxima sesión.',
                self::REQUIERE_MEMBRESIA],

            ['rec_descanso', 'Es momento de recuperarte',
                'Dormir, hidratarte y descansar también hacen parte de tu entrenamiento.',
                self::SIRVE_A_TODOS],
            ['rec_dormir_bien', 'Dormir es la base',
                'Ninguna rutina compensa dormir mal de forma sostenida.',
                self::SIRVE_A_TODOS],
        ]);
    }

    /** Hidratación y hábitos. */
    private static function hydration(): array
    {
        return self::build(Cat::HYDRATION, null, null, [
            ['hid_durante', 'Bebe agua durante el entrenamiento',
                'No esperes a tener sed: da pequeños tragos a lo largo de la sesión.',
                self::REQUIERE_MEMBRESIA],
            ['hab_desayuno', 'Come algo antes de entrenar',
                'Entrenar en vacío puede restarte energía. Algo ligero suele bastar.',
                self::REQUIERE_MEMBRESIA],
            ['hab_rutina_sueno', 'Un horario estable ayuda',
                'Acostarte y levantarte a horas parecidas mejora cómo rindes al entrenar.',
                self::REQUIERE_MEMBRESIA],

            ['hid_calor', 'Hoy hace calor',
                'Con temperaturas altas sudas más de lo que notas. Ten agua a mano.',
                self::SIRVE_A_TODOS],
            ['hid_dia', 'La hidratación es de todo el día',
                'Repartir el agua a lo largo del día funciona mejor que beber mucho de golpe.',
                self::SIRVE_A_TODOS],
            ['hid_senal', 'La sed llega tarde',
                'Cuando aparece, ya llevas un rato por debajo de lo que necesitas.',
                self::SIRVE_A_TODOS],
        ]);
    }

    // Los suplementos son información educativa y valen igual con la membresía
    // al día o vencida: no invitan a entrenar, explican qué mirar.

    private static function creatine(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_CREATINE, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_crea_constancia', 'Creatina: la constancia importa',
                'Su efecto depende de tomarla a diario durante semanas, no de un día suelto.', self::SIRVE_A_TODOS],
            ['sup_crea_agua', 'Creatina e hidratación',
                'Si la tomas, acompáñala de una buena hidratación a lo largo del día.', self::SIRVE_A_TODOS],
            ['sup_crea_tiempo', 'No esperes cambios inmediatos',
                'Los efectos, cuando aparecen, se ven con semanas de uso constante y entrenamiento.', self::SIRVE_A_TODOS],
            ['sup_crea_etiqueta', 'Lee la etiqueta',
                'Revisa la porción indicada por el fabricante y no la superes por tu cuenta.', self::SIRVE_A_TODOS],
            ['sup_crea_consulta', 'Cuándo preguntar antes',
                'Si tienes una condición renal, tomas medicación, estás embarazada o en lactancia, consulta a un profesional antes de empezar.', self::SIRVE_A_TODOS],
        ]);
    }

    private static function protein(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_PROTEIN, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_prot_comida', 'La comida va primero',
                'Huevos, carnes, lácteos y legumbres cubren la proteína de la mayoría de las personas.', self::SIRVE_A_TODOS],
            ['sup_prot_complemento', 'Un suplemento complementa, no sustituye',
                'La proteína en polvo sirve para completar lo que falta, no es obligatoria.', self::SIRVE_A_TODOS],
            ['sup_prot_porcion', 'Revisa porción y alérgenos',
                'Comprueba en la etiqueta la cantidad por medida y si contiene lactosa, soja o frutos secos.', self::SIRVE_A_TODOS],
            ['sup_prot_individual', 'Tus necesidades son tuyas',
                'La cantidad que te conviene depende de tu peso, tu actividad y tu salud. Consúltalo con un profesional.', self::SIRVE_A_TODOS],
        ]);
    }

    private static function preWorkout(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_PRE_WORKOUT, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_pre_cafeina', 'Mira la cafeína por porción',
                'Los preentrenos varían mucho entre marcas. Comprueba cuánta cafeína lleva una medida.', self::SIRVE_A_TODOS],
            ['sup_pre_suma', 'No sumes estimulantes',
                'Café, bebidas energéticas y preentreno se acumulan. Cuenta el total del día.', self::SIRVE_A_TODOS],
            ['sup_pre_sueno', 'Cuidado con la hora',
                'Tomarlo por la tarde o noche puede dificultarte dormir, y el descanso es parte del progreso.', self::SIRVE_A_TODOS],
            ['sup_pre_precaucion', 'Cuándo tener precaución',
                'Si eres sensible a la cafeína, tienes hipertensión, ansiedad, una condición cardiovascular o tomas medicación, consulta antes a un profesional.', self::SIRVE_A_TODOS],
            ['sup_pre_dosis', 'Más no es mejor',
                'Empezar por debajo de la porción indicada es una forma prudente de ver cómo te sienta.', self::SIRVE_A_TODOS],
        ]);
    }

    private static function multivitamins(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_MULTIVITAMINS, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_multi_dieta', 'No sustituyen a la comida',
                'Un multivitamínico no reemplaza una alimentación variada; la complementa cuando falta algo.', self::SIRVE_A_TODOS],
            ['sup_multi_duplicar', 'Evita duplicar',
                'Si tomas varios productos, revisa que no repitan las mismas vitaminas o minerales.', self::SIRVE_A_TODOS],
            ['sup_multi_etiqueta', 'Respeta la etiqueta',
                'Más cantidad no aporta más beneficio, y en algunos micronutrientes puede ser contraproducente.', self::SIRVE_A_TODOS],
            ['sup_multi_consulta', 'Cuándo consultar',
                'En embarazo, lactancia, con medicación o con alguna enfermedad, conviene preguntar antes a un profesional.', self::SIRVE_A_TODOS],
        ]);
    }

    private static function bcaa(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_BCAA, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_bcaa_necesidad', 'Los BCAA no siempre hacen falta',
                'Si ya cubres tu proteína diaria, es probable que no aporten nada adicional.', self::SIRVE_A_TODOS],
            ['sup_bcaa_prioridad', 'Primero el total del día',
                'La proteína completa de la comida tiene prioridad sobre cualquier aminoácido aislado.', self::SIRVE_A_TODOS],
            ['sup_bcaa_expectativa', 'Qué esperar',
                'No hay motivo para esperar de ellos un cambio en tu masa muscular por sí solos.', self::SIRVE_A_TODOS],
            ['sup_bcaa_etiqueta', 'Compara antes de comprar',
                'Revisa la etiqueta y valora si lo que aporta justifica su precio frente a tu comida habitual.', self::SIRVE_A_TODOS],
        ]);
    }

    /** @param list<array{0:string,1:string,2:string,3:bool}> $rows */
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
        ], $rows);
    }
}
