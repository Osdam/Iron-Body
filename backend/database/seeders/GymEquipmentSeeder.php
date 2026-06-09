<?php

namespace Database\Seeders;

use App\Models\GymEquipment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Inventario REAL de equipos de Iron Body (Centro de Acondicionamiento Físico).
 *
 * Es la fuente de verdad de "qué máquinas tenemos". Alimenta a IRON IA vía
 * GymEquipmentContextService (chat, voz/visión y coach) para que NUNCA recomiende
 * un ejercicio con una máquina que no existe.
 *
 * Idempotente (firstOrCreate por slug):
 *   php artisan db:seed --class=Database\\Seeders\\GymEquipmentSeeder
 *
 * Cuando una máquina se dañe o se retire, NO la borres del seeder: edítala desde
 * el CRM (estado "Mantenimiento"/"Fuera de servicio" o quita "Disponible para IA")
 * y la IA dejará de tenerla en cuenta automáticamente.
 */
class GymEquipmentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->equipment() as $row) {
            $slug = Str::slug($row['name']);
            GymEquipment::firstOrCreate(
                ['slug' => $slug],
                array_merge($row, [
                    'slug'                => $slug,
                    'status'              => $row['status'] ?? 'operational',
                    'is_available_for_ai' => $row['is_available_for_ai'] ?? true,
                    'quantity'            => $row['quantity'] ?? 1,
                    'zone'                => $row['zone'] ?? null,
                ]),
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function equipment(): array
    {
        return [
            // ── Tren inferior — máquinas guiadas ─────────────────────────────
            [
                'name' => 'Hack Squat',
                'category' => 'strength_machine',
                'zone' => 'Zona de piernas',
                'muscle_groups' => ['cuádriceps', 'glúteo mayor', 'isquiotibiales', 'aductores'],
                'aliases' => ['hack squat', 'sentadilla hack', 'máquina hack'],
                'notes' => 'Sentadilla guiada en recorrido inclinado. El énfasis muscular cambia según la posición de los pies.',
            ],
            [
                'name' => 'Prensa Pendular',
                'category' => 'strength_machine',
                'zone' => 'Zona de piernas',
                'muscle_groups' => ['cuádriceps', 'glúteos', 'isquiotibiales', 'gemelos'],
                'aliases' => ['prensa pendular', 'pendular leg press'],
                'notes' => 'Empuje de piernas con trayectoria pendular (curva de resistencia distinta a la prensa convencional).',
            ],
            [
                'name' => 'Prensa Lineal',
                'category' => 'strength_machine',
                'zone' => 'Zona de piernas',
                'muscle_groups' => ['cuádriceps', 'glúteos', 'isquiotibiales'],
                'aliases' => ['prensa lineal', 'leg press lineal', 'prensa de piernas'],
                'notes' => 'Empuje de piernas sobre recorrido lineal (sentadilla asistida).',
            ],
            [
                'name' => 'Sentadilla Pendular',
                'category' => 'strength_machine',
                'zone' => 'Zona de piernas',
                'muscle_groups' => ['cuádriceps', 'glúteos', 'aductores'],
                'aliases' => ['sentadilla pendular', 'pendulum squat'],
                'notes' => 'Sentadilla guiada con gran énfasis en la flexión de rodilla.',
            ],
            [
                'name' => 'Extensión de Pierna',
                'category' => 'strength_machine',
                'zone' => 'Zona de piernas',
                'muscle_groups' => ['cuádriceps'],
                'aliases' => ['leg extension', 'extensión de cuádriceps', 'extensiones de pierna'],
                'notes' => 'Aislamiento de extensión de rodilla.',
            ],
            [
                'name' => 'Curl Femoral Sentado',
                'category' => 'strength_machine',
                'zone' => 'Zona de piernas',
                'muscle_groups' => ['isquiotibiales', 'gemelos'],
                'aliases' => ['seated leg curl', 'curl femoral sentado'],
                'notes' => 'Flexión de rodilla en posición sentada.',
            ],
            [
                'name' => 'Curl Femoral Acostado',
                'category' => 'strength_machine',
                'zone' => 'Zona de piernas',
                'muscle_groups' => ['isquiotibiales', 'gemelos'],
                'aliases' => ['lying leg curl', 'curl femoral acostado', 'femoral acostado'],
                'notes' => 'Flexión de rodilla boca abajo.',
            ],
            [
                'name' => 'Máquina de Hip Thrust',
                'category' => 'strength_machine',
                'zone' => 'Zona de glúteos',
                'muscle_groups' => ['glúteo mayor', 'isquiotibiales'],
                'aliases' => ['hip thrust', 'hip thrust machine', 'empuje de cadera'],
                'notes' => 'Extensión de cadera guiada para glúteos.',
            ],
            [
                'name' => 'Máquina de Aducción',
                'category' => 'strength_machine',
                'zone' => 'Zona de piernas',
                'muscle_groups' => ['aductores'],
                'aliases' => ['aducción', 'hip adduction', 'aductores', 'máquina de abducción y aducción'],
                'notes' => 'Aducción de cadera (acercar las piernas a la línea media).',
            ],
            [
                'name' => 'Máquina de Abducción',
                'category' => 'strength_machine',
                'zone' => 'Zona de piernas',
                'muscle_groups' => ['glúteo medio', 'glúteo menor'],
                'aliases' => ['abducción', 'hip abduction', 'abductores', 'máquina de abducción y aducción'],
                'notes' => 'Abducción de cadera (separar las piernas de la línea media).',
            ],

            // ── Pecho ────────────────────────────────────────────────────────
            [
                'name' => 'Press de Banca Convergente',
                'category' => 'strength_machine',
                'zone' => 'Zona de pecho',
                'muscle_groups' => ['pectoral mayor', 'tríceps', 'deltoides anterior'],
                'aliases' => ['press de banca convergente', 'chest press', 'press de pecho convergente'],
                'notes' => 'Palancas que convergen durante el recorrido.',
            ],
            [
                'name' => 'Press Inclinado',
                'category' => 'strength_machine',
                'zone' => 'Zona de pecho',
                'muscle_groups' => ['pecho superior', 'deltoides anterior', 'tríceps'],
                'aliases' => ['press inclinado', 'incline press'],
                'notes' => 'Enfocado en la porción clavicular del pecho.',
            ],
            [
                'name' => 'Press Inclinado Convergente',
                'category' => 'strength_machine',
                'zone' => 'Zona de pecho',
                'muscle_groups' => ['pectoral superior'],
                'aliases' => ['press inclinado convergente', 'incline convergente'],
                'notes' => 'Variante guiada con trayectoria convergente para pecho superior.',
            ],

            // ── Espalda ──────────────────────────────────────────────────────
            [
                'name' => 'Máquina de Remo Bajo',
                'category' => 'strength_machine',
                'zone' => 'Zona de espalda',
                'muscle_groups' => ['dorsal ancho', 'romboides', 'bíceps', 'trapecio medio'],
                'aliases' => ['remo bajo', 'seated row', 'remo horizontal'],
                'notes' => 'Remos horizontales guiados.',
            ],
            [
                'name' => 'Remo Barra T',
                'category' => 'strength_machine',
                'zone' => 'Zona de espalda',
                'muscle_groups' => ['espalda media', 'dorsales', 'trapecio', 'bíceps'],
                'aliases' => ['remo barra t', 't-bar row', 'barra t'],
                'notes' => 'Remo con barra T (agarre cerrado o abierto).',
            ],
            [
                'name' => 'Hiperextensión Lumbar',
                'category' => 'functional',
                'zone' => 'Zona de core',
                'muscle_groups' => ['erectores espinales', 'glúteos', 'isquiotibiales'],
                'aliases' => ['hiperextensión lumbar', 'hiperextensiones', 'banco lumbar', 'hyperextension'],
                'notes' => 'Banco para fortalecer la cadena posterior.',
            ],

            // ── Multifuncional ───────────────────────────────────────────────
            [
                'name' => 'Sentadilla Smith',
                'category' => 'strength_machine',
                'zone' => 'Zona multifuncional',
                'muscle_groups' => ['cuádriceps', 'glúteos', 'pectoral', 'deltoides'],
                'aliases' => ['smith', 'máquina smith', 'sentadilla smith', 'multipower smith'],
                'notes' => 'Barra guiada verticalmente. Permite sentadilla, press militar, press de pecho y zancadas.',
            ],
            [
                'name' => 'Multipower',
                'category' => 'strength_machine',
                'zone' => 'Zona multifuncional',
                'muscle_groups' => ['cuerpo completo'],
                'aliases' => ['multipower', 'smith machine', 'máquina multipower'],
                'notes' => 'Barra guiada para sentadilla, press banca, press militar, remo, hip thrust y zancadas.',
            ],
            [
                'name' => 'Cable Dual (Poleas)',
                'category' => 'functional',
                'zone' => 'Zona multifuncional',
                'muscle_groups' => ['cuerpo completo'],
                'aliases' => ['cable dual', 'poleas', 'crossover', 'polea doble', 'cruce de poleas'],
                'notes' => 'Estación de poleas ajustables: cruces de pecho, jalones, remo, curl, extensión de tríceps, elevaciones laterales y core.',
            ],

            // ── Peso libre / estructuras / bancos ────────────────────────────
            [
                'name' => 'Jaula para Sentadilla',
                'category' => 'free_weights',
                'zone' => 'Zona de peso libre',
                'muscle_groups' => ['piernas', 'glúteos', 'espalda', 'cuerpo completo'],
                'aliases' => ['jaula', 'power rack', 'squat rack', 'jaula de sentadilla'],
                'notes' => 'Estructura para barra libre con seguridad: sentadilla, peso muerto, press militar, hip thrust.',
            ],
            [
                'name' => 'Estructura para Sentadilla Búlgara',
                'category' => 'free_weights',
                'zone' => 'Zona de peso libre',
                'muscle_groups' => ['cuádriceps', 'glúteos', 'isquiotibiales'],
                'aliases' => ['sentadilla búlgara', 'bulgarian split squat', 'estructura búlgara'],
                'notes' => 'Soporte para zancada/sentadilla búlgara.',
            ],
            [
                'name' => 'Banco Plano',
                'category' => 'free_weights',
                'zone' => 'Zona de peso libre',
                'muscle_groups' => ['pectoral', 'cuerpo completo'],
                'aliases' => ['banco plano', 'flat bench', 'banco fijo'],
                'notes' => 'Banco para ejercicios con peso libre y mancuernas.',
            ],
            [
                'name' => 'Banco Reclinable Ajustable',
                'category' => 'free_weights',
                'zone' => 'Zona de peso libre',
                'muscle_groups' => ['pectoral', 'deltoides', 'cuerpo completo'],
                'aliases' => ['banco ajustable', 'banco inclinado', 'banco reclinable', 'adjustable bench'],
                'notes' => 'Banco configurable en ángulos inclinado, declinado y plano.',
            ],

            // ── Cardio ───────────────────────────────────────────────────────
            [
                'name' => 'Caminadora Eléctrica',
                'category' => 'cardio',
                'zone' => 'Zona de cardio',
                'muscle_groups' => ['cardio', 'piernas'],
                'aliases' => ['caminadora', 'treadmill', 'trotadora eléctrica', 'banda eléctrica'],
                'notes' => 'Motorizada, con control de velocidad e inclinación. Resistencia cardiovascular y acondicionamiento.',
            ],
            [
                'name' => 'Caminadora Mecánica',
                'category' => 'cardio',
                'zone' => 'Zona de cardio',
                'muscle_groups' => ['cardio', 'piernas'],
                'aliases' => ['caminadora mecánica', 'curve treadmill', 'trotadora mecánica'],
                'notes' => 'Impulsada por la fuerza del usuario (mayor demanda metabólica).',
            ],
            [
                'name' => 'Bicicleta de Cycling',
                'category' => 'cardio',
                'zone' => 'Zona de cardio',
                'muscle_groups' => ['cuádriceps', 'glúteos', 'isquiotibiales', 'gemelos'],
                'aliases' => ['bicicleta', 'spinning', 'cycling', 'bici estática', 'bicicleta de cycling'],
                'notes' => 'Bicicleta estacionaria para ciclismo indoor y HIIT.',
            ],
        ];
    }
}
