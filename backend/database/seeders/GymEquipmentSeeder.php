<?php

namespace Database\Seeders;

use App\Models\GymEquipment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Carga inicial de equipos/máquinas del gimnasio.
 *
 * ⚠️  REEMPLAZA / AMPLÍA el array $equipment con las máquinas REALES de la
 *     instalación (Óscar las pasará). Usa `firstOrCreate` por slug, así que
 *     puedes volver a correr el seeder sin duplicar:
 *
 *        php artisan db:seed --class=Database\\Seeders\\GymEquipmentSeeder
 *
 * Categorías válidas: strength_machine | free_weights | cardio | functional |
 * accessory | bodyweight (ver App\Models\GymEquipment::CATEGORIES).
 *
 * El set de abajo es un PUNTO DE PARTIDA común de gimnasio; ajústalo a la realidad.
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
            // ── Máquinas guiadas de fuerza ───────────────────────────────────
            ['name' => 'Prensa de piernas 45°', 'category' => 'strength_machine', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['cuádriceps', 'glúteos', 'isquiotibiales'], 'aliases' => ['leg press', 'prensa']],
            ['name' => 'Extensión de cuádriceps', 'category' => 'strength_machine', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['cuádriceps'], 'aliases' => ['leg extension', 'extensiones']],
            ['name' => 'Curl femoral acostado', 'category' => 'strength_machine', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['isquiotibiales'], 'aliases' => ['leg curl', 'femoral']],
            ['name' => 'Polea alta (jalón al pecho)', 'category' => 'strength_machine', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['dorsales', 'bíceps'], 'aliases' => ['lat pulldown', 'jalón', 'polea']],
            ['name' => 'Remo en polea baja', 'category' => 'strength_machine', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['dorsales', 'trapecio'], 'aliases' => ['seated row', 'remo']],
            ['name' => 'Pecho en máquina (press)', 'category' => 'strength_machine', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['pectoral', 'tríceps'], 'aliases' => ['chest press']],
            ['name' => 'Peck deck (aperturas)', 'category' => 'strength_machine', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['pectoral'], 'aliases' => ['pec deck', 'aperturas']],
            ['name' => 'Máquina de hombros (press militar)', 'category' => 'strength_machine', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['deltoides'], 'aliases' => ['shoulder press']],
            ['name' => 'Máquina de abductores/aductores', 'category' => 'strength_machine', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['abductores', 'aductores'], 'aliases' => ['hip abductor']],

            // ── Peso libre ───────────────────────────────────────────────────
            ['name' => 'Rack de mancuernas', 'category' => 'free_weights', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['cuerpo completo'], 'aliases' => ['mancuernas', 'dumbbells']],
            ['name' => 'Barra olímpica con discos', 'category' => 'free_weights', 'zone' => 'Zona de pesas', 'quantity' => 2, 'muscle_groups' => ['cuerpo completo'], 'aliases' => ['barra', 'barbell', 'discos']],
            ['name' => 'Banco plano ajustable', 'category' => 'free_weights', 'zone' => 'Zona de pesas', 'quantity' => 2, 'muscle_groups' => ['pectoral', 'cuerpo completo'], 'aliases' => ['banco', 'bench']],
            ['name' => 'Rack de sentadillas', 'category' => 'free_weights', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['piernas', 'glúteos'], 'aliases' => ['squat rack', 'jaula']],
            ['name' => 'Banco Scott (predicador)', 'category' => 'free_weights', 'zone' => 'Zona de pesas', 'quantity' => 1, 'muscle_groups' => ['bíceps'], 'aliases' => ['preacher curl', 'scott']],

            // ── Cardio ───────────────────────────────────────────────────────
            ['name' => 'Caminadora (trotadora)', 'category' => 'cardio', 'zone' => 'Sala cardio', 'quantity' => 3, 'muscle_groups' => ['cardio', 'piernas'], 'aliases' => ['treadmill', 'trotadora', 'banda']],
            ['name' => 'Bicicleta estática', 'category' => 'cardio', 'zone' => 'Sala cardio', 'quantity' => 2, 'muscle_groups' => ['cardio', 'piernas'], 'aliases' => ['spinning', 'bici']],
            ['name' => 'Elíptica', 'category' => 'cardio', 'zone' => 'Sala cardio', 'quantity' => 2, 'muscle_groups' => ['cardio', 'cuerpo completo'], 'aliases' => ['elliptical']],
            ['name' => 'Remadora', 'category' => 'cardio', 'zone' => 'Sala cardio', 'quantity' => 1, 'muscle_groups' => ['cardio', 'espalda'], 'aliases' => ['rower', 'remo cardio']],

            // ── Funcional ────────────────────────────────────────────────────
            ['name' => 'Kettlebells', 'category' => 'functional', 'zone' => 'Zona funcional', 'quantity' => 1, 'muscle_groups' => ['cuerpo completo'], 'aliases' => ['pesa rusa']],
            ['name' => 'Bandas de resistencia', 'category' => 'functional', 'zone' => 'Zona funcional', 'quantity' => 1, 'muscle_groups' => ['cuerpo completo'], 'aliases' => ['ligas', 'bands']],
            ['name' => 'Cajón pliométrico', 'category' => 'functional', 'zone' => 'Zona funcional', 'quantity' => 1, 'muscle_groups' => ['piernas'], 'aliases' => ['box jump', 'cajón']],
            ['name' => 'TRX (suspensión)', 'category' => 'functional', 'zone' => 'Zona funcional', 'quantity' => 1, 'muscle_groups' => ['cuerpo completo'], 'aliases' => ['suspensión']],

            // ── Peso corporal ────────────────────────────────────────────────
            ['name' => 'Barra de dominadas', 'category' => 'bodyweight', 'zone' => 'Zona funcional', 'quantity' => 1, 'muscle_groups' => ['dorsales', 'bíceps'], 'aliases' => ['pull up bar', 'dominadas']],
            ['name' => 'Paralelas (fondos)', 'category' => 'bodyweight', 'zone' => 'Zona funcional', 'quantity' => 1, 'muscle_groups' => ['tríceps', 'pectoral'], 'aliases' => ['dips', 'fondos']],
        ];
    }
}
