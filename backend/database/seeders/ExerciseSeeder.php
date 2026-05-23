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
                    'Acuéstate en el banco boca arriba con los pies en el suelo',
                    'Agarra la barra con agarre ligeramente más ancho que los hombros',
                    'Baja la barra controladamente hasta rozar el pecho',
                    'Empuja la barra hacia arriba de forma explosiva',
                    'Extiende los brazos completamente sin bloquear los codos',
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
                'description'       => 'El rey de los ejercicios para piernas. Trabaja cuádriceps, glúteos y core.',
                'steps'             => [
                    'Coloca la barra sobre la parte alta de la espalda',
                    'Párate con pies al ancho de hombros, punteras ligeramente hacia afuera',
                    'Flexiona rodillas y caderas simultáneamente, bajando el cuerpo',
                    'Mantén la espalda recta y el pecho arriba durante todo el movimiento',
                    'Sube empujando el suelo con los talones hasta la posición inicial',
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
                    'Párate con los pies al ancho de caderas, barra sobre el medio del pie',
                    'Agarra la barra con las manos justo fuera de las piernas',
                    'Baja las caderas, pecho arriba, espalda neutra',
                    'Levanta la barra empujando el suelo con los pies',
                    'Extiende caderas y rodillas simultáneamente hasta quedar erguido',
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
                    'Cuélgate de la barra con agarre prono, manos al ancho de hombros',
                    'Activa el core y los omóplatos antes de comenzar',
                    'Tira de los codos hacia abajo y atrás para subir el cuerpo',
                    'Sube hasta que la barbilla supere la barra',
                    'Baja lentamente hasta la extensión completa de brazos',
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
                    'Coloca la barra a la altura de los hombros frente a ti',
                    'Agarra la barra con las manos al ancho de hombros',
                    'Empuja la barra directamente hacia arriba sobre la cabeza',
                    'Extiende completamente los brazos en la parte superior',
                    'Baja la barra de vuelta a la posición inicial de forma controlada',
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
                    'Párate con una mancuerna en cada mano, palmas hacia adelante',
                    'Mantén los codos pegados al cuerpo durante todo el ejercicio',
                    'Flexiona los codos levantando las mancuernas hacia los hombros',
                    'Aprieta los bíceps en la posición superior',
                    'Baja las mancuernas lentamente a la posición inicial',
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
                    'Colócate en posición de flexión con antebrazos en el suelo',
                    'El cuerpo debe formar una línea recta de cabeza a talones',
                    'Activa el abdomen y glúteos para mantener la posición',
                    'Mantén la posición durante el tiempo indicado',
                    'Respira de forma constante durante el ejercicio',
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
                    'Párate erguido con pies al ancho de hombros',
                    'Agáchate y coloca las manos en el suelo frente a ti',
                    'Salta los pies hacia atrás quedando en posición de plancha',
                    'Realiza una flexión completa (opcional para mayor intensidad)',
                    'Salta los pies hacia las manos y salta hacia arriba con brazos extendidos',
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
