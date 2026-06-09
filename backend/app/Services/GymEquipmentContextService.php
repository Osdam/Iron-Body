<?php

namespace App\Services;

use App\Models\GymEquipment;
use Illuminate\Support\Facades\Cache;

/**
 * Provee el catálogo de equipos del gimnasio a la capa de IRON IA.
 *
 * Es el ÚNICO punto que la IA debe usar para saber qué máquinas existen. El
 * resultado se cachea (el catálogo cambia poco) y se invalida automáticamente
 * cuando el CRM crea/edita/elimina un equipo (ver GymEquipmentController).
 *
 * Uso típico desde un servicio de IA:
 *   $catalog = app(GymEquipmentContextService::class)->catalog();
 *   $prompt  = $this->buildEquipmentConstraint($catalog['names']);
 */
class GymEquipmentContextService
{
    public const CACHE_KEY = 'gym_equipment.ai_catalog';
    public const CACHE_TTL = 600; // 10 min

    /** Catálogo completo agrupado (ver GymEquipment::aiCatalog()). */
    public function catalog(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => GymEquipment::aiCatalog());
    }

    /** Lista plana de nombres disponibles (lo mínimo para validar un ejercicio). */
    public function availableNames(): array
    {
        return $this->catalog()['names'] ?? [];
    }

    /**
     * Frase lista para inyectar en el system prompt de la IA. Si no hay equipos
     * registrados devuelve cadena vacía (la IA opera sin restricción dura).
     */
    public function promptConstraint(): string
    {
        $catalog = $this->catalog();
        if (($catalog['total'] ?? 0) === 0) {
            return '';
        }

        // Etiquetas legibles por categoría (las claves internas no se muestran).
        $labels = [
            'strength_machine' => 'Máquinas de fuerza',
            'free_weights'     => 'Peso libre y bancos',
            'cardio'           => 'Cardio',
            'functional'       => 'Funcional',
            'accessory'        => 'Accesorios',
            'bodyweight'       => 'Peso corporal',
        ];

        $blocks = [];
        foreach ($catalog['by_category'] ?? [] as $category => $items) {
            $label = $labels[$category] ?? $category;
            $names = [];
            foreach ($items as $item) {
                $name = $item['name'];
                $muscles = $item['muscle_groups'] ?? [];
                $names[] = ! empty($muscles)
                    ? $name . ' (' . implode(', ', array_slice($muscles, 0, 3)) . ')'
                    : $name;
            }
            $blocks[] = "  • {$label}: " . implode('; ', $names) . '.';
        }

        return "EQUIPOS DISPONIBLES EN EL GIMNASIO (inventario real, restricción dura):\n"
            . implode("\n", $blocks) . "\n"
            . 'NO recomiendes ejercicios que requieran equipos/máquinas que no estén en esta lista '
            . '(p. ej. si una máquina está dañada o fue retirada, no aparece aquí). Si el ejercicio '
            . 'ideal necesita algo que no existe, ofrece una alternativa con el equipo disponible o '
            . 'una variante con peso corporal.';
    }

    /** Invalida la caché (se llama al mutar el catálogo desde el CRM). */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
