<?php

namespace Database\Seeders;

use App\Models\Routine;
use Illuminate\Database\Seeder;

/**
 * Carga las rutinas pre-hechas (plantillas) IRONBODY por nivel y género.
 * Cada plantilla es un programa multi-día (Lun–Vie) guardado en la columna
 * JSON `days`. Son visibles en el catálogo "Explorar rutinas" de la app
 * (is_template = true) y también asignables desde el CRM.
 *
 * Idempotente: updateOrCreate por nombre. Ejecutar con:
 *   php artisan db:seed --class=Database\\Seeders\\RoutineTemplatesSeeder
 */
class RoutineTemplatesSeeder extends Seeder
{
    /** Construye un ejercicio del día. */
    private function ex(string $name, int $sets, string $reps, string $muscle = '', string $notes = ''): array
    {
        return [
            'name'         => $name,
            'muscle_group' => $muscle,
            'sets'         => $sets,
            'reps'         => $reps,
            'notes'        => $notes,
        ];
    }

    /** Construye un día de entrenamiento. */
    private function day(string $weekday, string $title, array $exercises, string $objective = ''): array
    {
        return [
            'day'       => $weekday,
            'title'     => $title,
            'objective' => $objective,
            'exercises' => $exercises,
        ];
    }

    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            Routine::updateOrCreate(
                ['name' => $tpl['name']],
                [
                    'objective'         => $tpl['objective'],
                    'level'             => $tpl['level'],
                    'gender'            => $tpl['gender'],
                    'muscle_group'      => 'Full body',
                    'estimated_minutes' => 60,
                    'duration_minutes'  => 60,
                    'days_per_week'     => count($tpl['days']),
                    'notes'             => $tpl['notes'] ?? '',
                    'days'              => $tpl['days'],
                    'exercises'         => null,
                    'is_template'       => true,
                    'created_by_admin'  => true,
                    'is_assigned'       => false,
                    'member_id'         => null,
                    'status'            => 'Activa',
                ],
            );
        }
    }

    private function templates(): array
    {
        return [
            $this->principianteMujer(),
            $this->principianteHombre(),
            $this->intermedioMujer(),
            $this->intermedioHombre(),
            $this->avanzadoMujer(),
            $this->avanzadoHombre(),
        ];
    }

    // ── Principiante Mujer (P.M) ────────────────────────────────────────────
    private function principianteMujer(): array
    {
        $obj = 'Bajar porcentaje graso, aumento de masa muscular y acondicionamiento físico.';

        $piernaCompleta = [
            $this->ex('Sentadilla en cajón', 3, '10', 'Pierna'),
            $this->ex('Sentadilla en TRX', 3, '10', 'Pierna'),
            $this->ex('Flexión de rodilla sentado', 3, '10', 'Pierna'),
            $this->ex('Plantiflexión en máquina sentado', 3, '10', 'Pantorrilla'),
            $this->ex('Aducción de cadera en máquina sentado', 3, '10', 'Pierna'),
        ];

        return [
            'name' => 'Rutina Principiante – Mujer',
            'level' => 'Principiante', 'gender' => 'Mujer', 'objective' => $obj,
            'days' => [
                $this->day('Lunes', 'Tren inferior pierna completa', $piernaCompleta, $obj),
                $this->day('Martes', 'Pecho, hombro y tríceps', [
                    $this->ex('Press banca plano', 4, '12', 'Pecho'),
                    $this->ex('Abducción horizontal de hombro Pec Deck', 4, '10', 'Pecho'),
                    $this->ex('Abducción hombro con polea', 4, '10', 'Hombro'),
                    $this->ex('Flexión de hombro con disco', 3, '12', 'Hombro'),
                    $this->ex('Press declinado', 4, '10', 'Pecho'),
                    $this->ex('Fondos', 4, '12', 'Tríceps'),
                    $this->ex('Plancha', 4, '30 seg', 'Core'),
                ]),
                $this->day('Miércoles', 'Tren inferior enfoque en glúteo', [
                    $this->ex('Aducción de cadera en máquina sentado', 3, '10', 'Glúteo'),
                    $this->ex('Sentadilla sumo en banco', 3, '10', 'Glúteo'),
                    $this->ex('Step Up', 3, '10', 'Glúteo'),
                    $this->ex('Elevación de talón de pie', 3, '10', 'Pantorrilla'),
                    $this->ex('Plancha', 4, '30 seg', 'Core'),
                ], $obj),
                $this->day('Jueves', 'Espalda y bíceps', [
                    $this->ex('Jalón polea agarre abierto', 3, '10', 'Espalda'),
                    $this->ex('Remo polea sentado', 3, '10', 'Espalda'),
                    $this->ex('Remo polea sentado agarre abierto', 3, '10', 'Espalda'),
                    $this->ex('Curl martillo', 3, '10', 'Bíceps'),
                    $this->ex('Curl de bíceps en polea baja', 3, '10', 'Bíceps'),
                ], $obj),
                $this->day('Viernes', 'Tren inferior pierna completa', $piernaCompleta),
            ],
        ];
    }

    // ── Principiante Hombre (P.H) ───────────────────────────────────────────
    private function principianteHombre(): array
    {
        $obj = 'Acondicionamiento físico y aumento de masa muscular.';

        $pechoHombro = [
            $this->ex('Press plano en máquina Hammer', 3, '12', 'Pecho'),
            $this->ex('Press inclinado en máquina isolateral', 3, '12', 'Pecho'),
            $this->ex('Flexiones de brazo', 3, '12', 'Pecho'),
            $this->ex('Press militar con barra', 3, '12', 'Hombro'),
            $this->ex('Abducción hombro mancuernas', 3, '12', 'Hombro'),
            $this->ex('Abducción horizontal de hombro Pec Deck', 3, '12', 'Pecho'),
        ];

        return [
            'name' => 'Rutina Principiante – Hombre',
            'level' => 'Principiante', 'gender' => 'Hombre', 'objective' => $obj,
            'days' => [
                $this->day('Lunes', 'Pecho y hombro', $pechoHombro),
                $this->day('Martes', 'Espalda', [
                    $this->ex('Remo Hammer', 3, '12', 'Espalda'),
                    $this->ex('Jalón polea agarre abierto', 3, '12', 'Espalda'),
                    $this->ex('Remo con barra T', 3, '12', 'Espalda'),
                    $this->ex('Curl con barra Z', 3, '12', 'Bíceps'),
                    $this->ex('Curl de bíceps en polea baja', 3, '12', 'Bíceps'),
                ]),
                $this->day('Miércoles', 'Pierna', [
                    $this->ex('Sentadilla en cajón', 3, '12', 'Pierna'),
                    $this->ex('Sentadilla sumo en banco', 3, '12', 'Glúteo'),
                    $this->ex('Extensión de rodilla en máquina sentado', 3, '12', 'Cuádriceps'),
                    $this->ex('Aducción de cadera en máquina sentado', 3, '12', 'Pierna'),
                    $this->ex('Plantiflexión en máquina sentado', 3, '12', 'Pantorrilla'),
                ]),
                $this->day('Jueves', 'Brazos', [
                    $this->ex('Curl de bíceps con mancuernas', 3, '12', 'Bíceps'),
                    $this->ex('Curl de bíceps en polea baja', 3, '12', 'Bíceps'),
                    $this->ex('Extensión de codo en polea', 3, '12', 'Tríceps'),
                    $this->ex('Fondos en banco', 3, '12', 'Tríceps'),
                    $this->ex('Press copa', 3, '12', 'Tríceps'),
                ]),
                $this->day('Viernes', 'Pecho y hombro', $pechoHombro),
            ],
        ];
    }

    // ── Intermedio Mujer (I.M) ──────────────────────────────────────────────
    private function intermedioMujer(): array
    {
        $obj = 'Bajar el porcentaje graso, aumento de masa muscular y perfeccionamiento en técnica.';

        $pierna = [
            $this->ex('Prensa lineal', 4, '12', 'Pierna'),
            $this->ex('Sentadilla goblet', 4, '12', 'Pierna'),
            $this->ex('Sentadillas con mancuernas', 4, '12', 'Pierna'),
            $this->ex('Extensión de rodilla en máquina sentado', 4, '12', 'Cuádriceps'),
            $this->ex('Plantiflexión en máquina sentado', 4, '15', 'Pantorrilla'),
        ];

        return [
            'name' => 'Rutina Intermedio – Mujer',
            'level' => 'Intermedio', 'gender' => 'Mujer', 'objective' => $obj,
            'days' => [
                $this->day('Lunes', 'Pierna', $pierna),
                $this->day('Martes', 'Espalda y bíceps', [
                    $this->ex('Jalón polea agarre abierto', 4, '12', 'Espalda'),
                    $this->ex('Jalón polea agarre cerrado', 4, '12', 'Espalda'),
                    $this->ex('Remo con barra T', 4, '12', 'Espalda'),
                    $this->ex('Remo Hammer', 4, '12', 'Espalda'),
                    $this->ex('Curl predicador', 4, '12', 'Bíceps'),
                    $this->ex('Curl de bíceps en polea baja', 4, '12', 'Bíceps'),
                ]),
                $this->day('Miércoles', 'Pierna enfoque en glúteo', [
                    $this->ex('Prensa péndulo', 4, '12', 'Glúteo'),
                    $this->ex('Sentadilla sumo', 4, '12', 'Glúteo'),
                    $this->ex('Abducción de cadera en máquina sentado', 4, '12', 'Glúteo'),
                    $this->ex('Step Up', 4, '12', 'Glúteo'),
                ]),
                $this->day('Jueves', 'Pecho, hombro y tríceps', [
                    $this->ex('Press inclinado en máquina isolateral', 4, '12', 'Pecho'),
                    $this->ex('Press plano en máquina Hammer', 4, '12', 'Pecho'),
                    $this->ex('Press militar con barra', 4, '12', 'Hombro'),
                    $this->ex('Abducción hombro con mancuernas', 4, '12', 'Hombro'),
                    $this->ex('Abducción horizontal de hombro Pec Deck', 4, '12', 'Pecho'),
                    $this->ex('Extensión de codo en polea', 4, '12', 'Tríceps'),
                ]),
                $this->day('Viernes', 'Pierna', $pierna),
            ],
        ];
    }

    // ── Intermedio Hombre (I.H) ─────────────────────────────────────────────
    private function intermedioHombre(): array
    {
        $obj = 'Bajar el porcentaje graso, aumento de masa muscular y perfeccionamiento en técnica.';

        $pierna = [
            $this->ex('Hacka', 4, '12', 'Pierna'),
            $this->ex('Sentadilla Smith', 4, '12', 'Pierna'),
            $this->ex('Prensa lineal', 4, '12', 'Pierna'),
            $this->ex('Peso muerto con barra', 4, '12', 'Pierna'),
            $this->ex('Extensión de rodilla en máquina sentado', 4, '12', 'Cuádriceps'),
            $this->ex('Elevación de talón de pie', 4, '12', 'Pantorrilla'),
        ];
        $pechoHombroTriceps = [
            $this->ex('Press banca plano', 4, '12', 'Pecho'),
            $this->ex('Press inclinado en máquina isolateral', 4, '12', 'Pecho'),
            $this->ex('Aperturas en máquina Peck Deck', 4, '12', 'Pecho'),
            $this->ex('Press militar con barra', 4, '12', 'Hombro'),
            $this->ex('Abducción horizontal de hombro Pec Deck', 4, '12', 'Pecho'),
            $this->ex('Abducción hombro con mancuernas', 4, '12', 'Hombro'),
            $this->ex('Press francés', 4, '12', 'Tríceps'),
        ];

        return [
            'name' => 'Rutina Intermedio – Hombre',
            'level' => 'Intermedio', 'gender' => 'Hombre', 'objective' => $obj,
            'days' => [
                $this->day('Lunes', 'Pierna', $pierna),
                $this->day('Martes', 'Pecho, hombro y tríceps', $pechoHombroTriceps),
                $this->day('Miércoles', 'Espalda y bíceps', [
                    $this->ex('Jalón polea agarre abierto', 4, '12', 'Espalda'),
                    $this->ex('Remo con barra T', 4, '12', 'Espalda'),
                    $this->ex('Remo polea sentado agarre abierto', 4, '12', 'Espalda'),
                    $this->ex('Curl de bíceps en polea baja', 4, '12', 'Bíceps'),
                    $this->ex('Curl concentrado con mancuerna', 4, '12', 'Bíceps'),
                    $this->ex('Curl antebrazo', 4, '12', 'Antebrazo'),
                ]),
                $this->day('Jueves', 'Pierna', $pierna),
                $this->day('Viernes', 'Pecho, hombro y tríceps', $pechoHombroTriceps),
            ],
        ];
    }

    // ── Avanzado Mujer (A.M) ────────────────────────────────────────────────
    private function avanzadoMujer(): array
    {
        $obj = 'Aumento de masa muscular, mantener porcentaje de grasa saludable y fuerza.';

        return [
            'name' => 'Rutina Avanzado – Mujer',
            'level' => 'Avanzado', 'gender' => 'Mujer', 'objective' => $obj,
            'days' => [
                $this->day('Lunes', 'Pierna enfoque en glúteo', [
                    $this->ex('Step Up en polea', 4, '12', 'Glúteo'),
                    $this->ex('Sentadilla búlgara', 4, '12', 'Glúteo'),
                    $this->ex('Abducción de cadera en polea', 4, '12', 'Glúteo'),
                    $this->ex('Peso muerto con mancuerna', 4, '12', 'Pierna'),
                    $this->ex('Elevación de talón de pie', 4, '12', 'Pantorrilla'),
                ]),
                $this->day('Martes', 'Pecho, hombro y tríceps', [
                    $this->ex('Press banca plano', 4, '12', 'Pecho'),
                    $this->ex('Press banca inclinado', 4, '12', 'Pecho'),
                    $this->ex('Press militar con mancuernas', 4, '12', 'Hombro'),
                    $this->ex('Flexión de hombro con mancuernas', 4, '12', 'Hombro'),
                    $this->ex('Pull Face', 4, '12', 'Hombro'),
                    $this->ex('Press francés', 4, '12', 'Tríceps'),
                ]),
                $this->day('Miércoles', 'Pierna', [
                    $this->ex('Hacka', 4, '12', 'Pierna'),
                    $this->ex('Sentadilla Smith', 4, '12', 'Pierna'),
                    $this->ex('Prensa lineal', 4, '12', 'Pierna'),
                    $this->ex('Zancadas con mancuernas', 4, '20', 'Pierna', 'Documento original: 204.'),
                    $this->ex('Elevación de talón de pie', 4, '10', 'Pantorrilla'),
                ]),
                $this->day('Jueves', 'Espalda y bíceps', [
                    $this->ex('Jalón polea agarre abierto', 4, '12', 'Espalda'),
                    $this->ex('Remo polea sentado', 4, '12', 'Espalda'),
                    $this->ex('Remo polea sentado agarre abierto', 4, '12', 'Espalda'),
                    $this->ex('Pull Over', 4, '12', 'Espalda'),
                    $this->ex('Curl martillo', 4, '12', 'Bíceps'),
                    $this->ex('Curl predicador', 4, '12', 'Bíceps'),
                ]),
                $this->day('Viernes', 'Pierna enfoque en glúteo', [
                    $this->ex('Hip Thrust', 4, '12', 'Glúteo'),
                    $this->ex('Peso muerto con barra', 4, '12', 'Pierna'),
                    $this->ex('Extensión de cadera en polea', 4, '12', 'Glúteo'),
                    $this->ex('Prensa péndulo', 4, '12', 'Glúteo'),
                    $this->ex('Plantiflexión en máquina sentado', 4, '20', 'Pantorrilla'),
                ]),
            ],
        ];
    }

    // ── Avanzado Hombre (A.H) ───────────────────────────────────────────────
    private function avanzadoHombre(): array
    {
        $obj = 'Aumento de masa muscular, mantener porcentaje de grasa saludable y fuerza.';
        $notes = 'Realizar 2 o 3 series de aproximación en el primer ejercicio. '
            . 'Al finalizar la rutina de pesas, hacer 4 series de 12 repeticiones de crunch con peso '
            . 'y 4 series de planchas de 1 minuto. Por último, 30 minutos de bicicleta o caminadora.';

        $pechoHombroTriceps = [
            $this->ex('Press banca plano', 5, '10', 'Pecho'),
            $this->ex('Press inclinado en máquina isolateral', 5, '10', 'Pecho'),
            $this->ex('Press militar con mancuernas', 4, '12', 'Hombro'),
            $this->ex('Flexión de hombro con mancuernas', 4, '12', 'Hombro'),
            $this->ex('Abducción hombro con mancuernas', 4, '12', 'Hombro'),
            $this->ex('Pull Face', 4, '12', 'Hombro'),
            $this->ex('Press francés', 3, '10', 'Tríceps'),
            $this->ex('Extensión de codo en polea', 3, '10', 'Tríceps'),
        ];
        $espaldaAntebrazoBiceps = [
            $this->ex('Jalón polea agarre abierto', 4, '12', 'Espalda'),
            $this->ex('Remo polea sentado', 4, '12', 'Espalda'),
            $this->ex('Pull Over', 4, '12', 'Espalda'),
            $this->ex('Curl predicador', 4, '12', 'Bíceps'),
            $this->ex('Curl Bayesian', 4, '12', 'Bíceps'),
            $this->ex('Trapecio alto', 4, '12', 'Trapecio'),
            $this->ex('Curl antebrazo', 4, '12', 'Antebrazo'),
        ];

        return [
            'name' => 'Rutina Avanzado – Hombre',
            'level' => 'Avanzado', 'gender' => 'Hombre', 'objective' => $obj, 'notes' => $notes,
            'days' => [
                $this->day('Lunes', 'Pecho, hombro y tríceps', $pechoHombroTriceps),
                $this->day('Martes', 'Espalda, antebrazo y bíceps', $espaldaAntebrazoBiceps),
                $this->day('Miércoles', 'Pierna', [
                    $this->ex('Sentadilla Smith', 5, '12', 'Pierna'),
                    $this->ex('Sentadilla con barra', 5, '10', 'Pierna'),
                    $this->ex('Prensa lineal', 4, '10', 'Pierna'),
                    $this->ex('Flexión de rodilla máquina acostado', 4, '10', 'Isquiotibial'),
                    $this->ex('Plantiflexión en máquina sentado', 4, '20', 'Pantorrilla'),
                ]),
                $this->day('Jueves', 'Pecho, hombro y tríceps', $pechoHombroTriceps),
                $this->day('Viernes', 'Espalda, antebrazo y bíceps', $espaldaAntebrazoBiceps),
            ],
        ];
    }
}
