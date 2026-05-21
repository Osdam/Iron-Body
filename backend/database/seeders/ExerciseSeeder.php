<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;

class ExerciseSeeder extends Seeder
{
    public function run(): void
    {
        $exercises = [
            [
                'name'              => 'Press de Banca',
                'muscle_group'      => 'Pecho',
                'equipment'         => 'Barra',
                'difficulty'        => 'Intermedio',
                'description'       => 'Ejercicio compuesto para desarrollo del pecho, hombros y tríceps.',
                'steps'             => [
                    'Acuéstate en el banco con los pies apoyados en el suelo.',
                    'Agarra la barra con agarre ligeramente más ancho que los hombros.',
                    'Baja la barra controlando hasta el pecho.',
                    'Empuja hacia arriba extendiendo los codos por completo.',
                ],
                'common_mistakes'   => ['Arquear excesivamente la espalda', 'Rebotar en el pecho'],
                'secondary_muscles' => ['Hombros', 'Tríceps'],
                'suggested_sets'    => 4,
                'suggested_reps'    => '8-10',
                'provider'          => 'manual',
            ],
            [
                'name'              => 'Sentadilla',
                'muscle_group'      => 'Piernas',
                'equipment'         => 'Barra',
                'difficulty'        => 'Intermedio',
                'description'       => 'El rey de los ejercicios para piernas.',
                'steps'             => [
                    'Coloca la barra en la parte alta de la espalda.',
                    'Separa los pies al ancho de los hombros.',
                    'Baja flexionando rodillas y caderas manteniendo la espalda neutra.',
                    'Sube empujando el suelo con los talones.',
                ],
                'common_mistakes'   => ['Rodillas hacia adentro', 'Talones levantados'],
                'secondary_muscles' => ['Core', 'Lumbares'],
                'suggested_sets'    => 4,
                'suggested_reps'    => '6-10',
                'provider'          => 'manual',
            ],
            [
                'name'              => 'Peso Muerto',
                'muscle_group'      => 'Espalda',
                'equipment'         => 'Barra',
                'difficulty'        => 'Avanzado',
                'description'       => 'Ejercicio compuesto total. Trabaja toda la cadena posterior.',
                'steps'             => [
                    'Para frente a la barra con pies al ancho de la cadera.',
                    'Agarra la barra justo fuera de las piernas.',
                    'Mantén la espalda plana y el core activo.',
                    'Levanta empujando el suelo y extendiendo caderas.',
                ],
                'common_mistakes'   => ['Espalda redondeada', 'Barra lejos del cuerpo'],
                'secondary_muscles' => ['Glúteos', 'Cuádriceps', 'Core'],
                'suggested_sets'    => 4,
                'suggested_reps'    => '5-8',
                'provider'          => 'manual',
            ],
            [
                'name'              => 'Dominadas',
                'muscle_group'      => 'Espalda',
                'equipment'         => 'Peso corporal',
                'difficulty'        => 'Intermedio',
                'description'       => 'Excelente para desarrollar el ancho de espalda y bíceps.',
                'steps'             => [
                    'Agarra la barra con agarre prono más ancho que los hombros.',
                    'Cuelga con brazos extendidos.',
                    'Tira hacia arriba hasta que la barbilla supere la barra.',
                    'Baja controlado hasta extender los brazos.',
                ],
                'common_mistakes'   => ['Usar impulso', 'No extender completamente'],
                'secondary_muscles' => ['Bíceps', 'Core'],
                'suggested_sets'    => 3,
                'suggested_reps'    => '6-10',
                'provider'          => 'manual',
            ],
            [
                'name'              => 'Press Militar',
                'muscle_group'      => 'Hombros',
                'equipment'         => 'Barra',
                'difficulty'        => 'Intermedio',
                'description'       => 'Ejercicio fundamental para construir hombros fuertes y anchos.',
                'steps'             => [
                    'De pie o sentado, sostén la barra a la altura del pecho.',
                    'Presiona hacia arriba hasta extender los brazos.',
                    'Baja controlado hasta el nivel del mentón.',
                ],
                'common_mistakes'   => ['Arquear la espalda', 'Codos muy hacia adelante'],
                'secondary_muscles' => ['Tríceps', 'Core'],
                'suggested_sets'    => 4,
                'suggested_reps'    => '8-12',
                'provider'          => 'manual',
            ],
            [
                'name'              => 'Curl con Mancuernas',
                'muscle_group'      => 'Brazos',
                'equipment'         => 'Mancuernas',
                'difficulty'        => 'Principiante',
                'description'       => 'Ejercicio de aislamiento para desarrollo del bíceps.',
                'steps'             => [
                    'De pie con mancuernas a los lados.',
                    'Flexiona los codos llevando las mancuernas hacia los hombros.',
                    'Aprieta el bíceps en la parte superior.',
                    'Baja lentamente a la posición inicial.',
                ],
                'common_mistakes'   => ['Usar el cuerpo para impulsar', 'No controlar la bajada'],
                'secondary_muscles' => ['Braquial', 'Antebrazo'],
                'suggested_sets'    => 3,
                'suggested_reps'    => '10-15',
                'provider'          => 'manual',
            ],
            [
                'name'              => 'Plancha',
                'muscle_group'      => 'Core',
                'equipment'         => 'Peso corporal',
                'difficulty'        => 'Principiante',
                'description'       => 'Ejercicio isométrico fundamental para fortalecer el core.',
                'steps'             => [
                    'Apóyate sobre los antebrazos y puntas de los pies.',
                    'Mantén el cuerpo en línea recta.',
                    'Activa el abdomen y glúteos.',
                    'Mantén la posición el tiempo indicado.',
                ],
                'common_mistakes'   => ['Cadera arriba o abajo', 'No respirar'],
                'secondary_muscles' => ['Hombros', 'Glúteos'],
                'suggested_sets'    => 3,
                'suggested_reps'    => '30-60 seg',
                'provider'          => 'manual',
            ],
            [
                'name'              => 'Burpees',
                'muscle_group'      => 'Cardio',
                'equipment'         => 'Peso corporal',
                'difficulty'        => 'Avanzado',
                'description'       => 'Ejercicio de cuerpo completo de alta intensidad.',
                'steps'             => [
                    'De pie, desciende a posición de squat.',
                    'Lleva los pies hacia atrás a posición de plancha.',
                    'Haz una flexión.',
                    'Regresa los pies y salta explosivamente.',
                ],
                'common_mistakes'   => ['Perder la posición de plancha', 'Sin salto'],
                'secondary_muscles' => ['Todo el cuerpo'],
                'suggested_sets'    => 3,
                'suggested_reps'    => '10-15',
                'provider'          => 'manual',
            ],
        ];

        foreach ($exercises as $data) {
            Exercise::firstOrCreate(['name' => $data['name']], $data);
        }
    }
}
