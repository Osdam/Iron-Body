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
 * Se siembran en `notification_templates` y desde ahí el CRM puede editarlos o
 * apagarlos sin desplegar.
 */
final class NotificationCatalog
{
    /** Aviso al pie de todo lo relacionado con suplementos. */
    public const SUPPLEMENT_DISCLAIMER =
        'Información educativa, no consejo médico. Consulta a un profesional de la salud antes de empezar cualquier suplemento.';

    /**
     * @return list<array{key:string,category:string,supplement_kind:?string,title:string,body:string,action_route:?string,disclaimer:?string}>
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
                'Un entrenamiento corto sigue siendo un paso adelante. Revisa tu rutina cuando estés listo.'],
            ['mot_constancia', 'Mantén la constancia',
                'Cada sesión suma. Hoy puedes avanzar a tu ritmo.'],
            ['mot_vuelve_sin_culpa', 'Cuando quieras retomar, aquí estamos',
                'No hace falta empezar de cero. Vuelve por donde lo dejaste.'],
            ['mot_semana_empieza', 'Prepara tu semana',
                'Elegir ahora qué días vas a entrenar hace el resto más fácil.'],
            ['mot_racha', 'Llevas una buena racha',
                'Has sostenido tu rutina varios días seguidos. Buen trabajo.'],
            ['mot_objetivo', 'Recuerda por qué empezaste',
                'Tu objetivo sigue ahí. Hoy puedes acercarte un poco más.'],
            ['mot_dia_dificil', 'Los días flojos también cuentan',
                'Si hoy no rindes como quisieras, entrenar igual ya es ganancia.'],
            ['mot_pequenos_pasos', 'Poco, pero hecho',
                'Veinte minutos bien aprovechados valen más que una sesión que no ocurre.'],
            ['mot_tecnica', 'La técnica primero',
                'Subir peso puede esperar. Un movimiento bien hecho protege lo que ya has ganado.'],
            ['mot_celebra', 'Has avanzado',
                'Compara con cómo empezaste, no con nadie más.'],
        ]);
    }

    /** Entrenamiento y recuperación. */
    private static function recovery(): array
    {
        return self::build(Cat::RECOVERY, null, null, [
            ['rec_descanso', 'Es momento de recuperarte',
                'Dormir, hidratarte y descansar también hacen parte de tu entrenamiento.'],
            ['rec_sueno', 'El músculo se construye descansando',
                'El trabajo lo haces en el gimnasio; la recuperación ocurre mientras duermes.'],
            ['rec_dia_libre', 'Un día de descanso no te retrasa',
                'Alternar esfuerzo y descanso es parte del plan, no una pausa en él.'],
            ['rec_molestia', 'Escucha las molestias',
                'Si algo duele más de lo normal, baja la carga y coméntalo con tu entrenador.'],
            ['rec_estiramiento', 'Dedica unos minutos al final',
                'Estirar y bajar pulsaciones al terminar te deja mejor para la próxima sesión.'],
        ]);
    }

    /** Hidratación y hábitos. */
    private static function hydration(): array
    {
        return self::build(Cat::HYDRATION, null, null, [
            ['hid_durante', 'Bebe agua durante el entrenamiento',
                'No esperes a tener sed: da pequeños tragos a lo largo de la sesión.'],
            ['hid_calor', 'Hoy hace calor',
                'Con temperaturas altas sudas más de lo que notas. Lleva tu botella.'],
            ['hid_dia', 'La hidratación es de todo el día',
                'Repartir el agua a lo largo del día funciona mejor que beber mucho de golpe.'],
            ['hab_desayuno', 'Come algo antes de entrenar',
                'Entrenar en vacío puede restarte energía. Algo ligero suele bastar.'],
            ['hab_rutina_sueno', 'Un horario estable ayuda',
                'Acostarte y levantarte a horas parecidas mejora cómo rindes al entrenar.'],
        ]);
    }

    private static function creatine(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_CREATINE, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_crea_constancia', 'Creatina: la constancia importa',
                'Su efecto depende de tomarla a diario durante semanas, no de un día suelto.'],
            ['sup_crea_agua', 'Creatina e hidratación',
                'Si la tomas, acompáñala de una buena hidratación a lo largo del día.'],
            ['sup_crea_tiempo', 'No esperes cambios inmediatos',
                'Los efectos, cuando aparecen, se ven con semanas de uso constante y entrenamiento.'],
            ['sup_crea_etiqueta', 'Lee la etiqueta',
                'Revisa la porción indicada por el fabricante y no la superes por tu cuenta.'],
            ['sup_crea_consulta', 'Cuándo preguntar antes',
                'Si tienes una condición renal, tomas medicación, estás embarazada o en lactancia, consulta a un profesional antes de empezar.'],
        ]);
    }

    private static function protein(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_PROTEIN, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_prot_comida', 'La comida va primero',
                'Huevos, carnes, lácteos y legumbres cubren la proteína de la mayoría de las personas.'],
            ['sup_prot_complemento', 'Un suplemento complementa, no sustituye',
                'La proteína en polvo sirve para completar lo que falta, no es obligatoria.'],
            ['sup_prot_porcion', 'Revisa porción y alérgenos',
                'Comprueba en la etiqueta la cantidad por medida y si contiene lactosa, soja o frutos secos.'],
            ['sup_prot_individual', 'Tus necesidades son tuyas',
                'La cantidad que te conviene depende de tu peso, tu actividad y tu salud. Consúltalo con un profesional.'],
        ]);
    }

    private static function preWorkout(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_PRE_WORKOUT, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_pre_cafeina', 'Mira la cafeína por porción',
                'Los preentrenos varían mucho entre marcas. Comprueba cuánta cafeína lleva una medida.'],
            ['sup_pre_suma', 'No sumes estimulantes',
                'Café, bebidas energéticas y preentreno se acumulan. Cuenta el total del día.'],
            ['sup_pre_sueno', 'Cuidado con la hora',
                'Tomarlo por la tarde o noche puede dificultarte dormir, y el descanso es parte del progreso.'],
            ['sup_pre_precaucion', 'Cuándo tener precaución',
                'Si eres sensible a la cafeína, tienes hipertensión, ansiedad, una condición cardiovascular o tomas medicación, consulta antes a un profesional.'],
            ['sup_pre_dosis', 'Más no es mejor',
                'Empezar por debajo de la porción indicada es una forma prudente de ver cómo te sienta.'],
        ]);
    }

    private static function multivitamins(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_MULTIVITAMINS, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_multi_dieta', 'No sustituyen a la comida',
                'Un multivitamínico no reemplaza una alimentación variada; la complementa cuando falta algo.'],
            ['sup_multi_duplicar', 'Evita duplicar',
                'Si tomas varios productos, revisa que no repitan las mismas vitaminas o minerales.'],
            ['sup_multi_etiqueta', 'Respeta la etiqueta',
                'Más cantidad no aporta más beneficio, y en algunos micronutrientes puede ser contraproducente.'],
            ['sup_multi_consulta', 'Cuándo consultar',
                'En embarazo, lactancia, con medicación o con alguna enfermedad, conviene preguntar antes a un profesional.'],
        ]);
    }

    private static function bcaa(): array
    {
        return self::build(Cat::SUPPLEMENTS, Cat::SUPPLEMENT_BCAA, self::SUPPLEMENT_DISCLAIMER, [
            ['sup_bcaa_necesidad', 'Los BCAA no siempre hacen falta',
                'Si ya cubres tu proteína diaria, es probable que no aporten nada adicional.'],
            ['sup_bcaa_prioridad', 'Primero el total del día',
                'La proteína completa de la comida tiene prioridad sobre cualquier aminoácido aislado.'],
            ['sup_bcaa_expectativa', 'Qué esperar',
                'No hay motivo para esperar de ellos un cambio en tu masa muscular por sí solos.'],
            ['sup_bcaa_etiqueta', 'Compara antes de comprar',
                'Revisa la etiqueta y valora si lo que aporta justifica su precio frente a tu comida habitual.'],
        ]);
    }

    /** @param list<array{0:string,1:string,2:string}> $rows */
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
        ], $rows);
    }
}
