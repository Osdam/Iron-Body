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
        $names = $this->availableNames();
        if (empty($names)) {
            return '';
        }

        $list = implode(', ', $names);

        return "EQUIPOS DISPONIBLES EN EL GIMNASIO (úsalos como restricción dura): {$list}. "
            . 'NO recomiendes ejercicios que requieran equipos que no estén en esta lista. '
            . 'Si un ejercicio ideal necesita una máquina que no existe, ofrece una alternativa '
            . 'con el equipo disponible o una variante con peso corporal.';
    }

    /** Invalida la caché (se llama al mutar el catálogo desde el CRM). */
    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
