<?php

namespace App\Support;

use Database\Seeders\LegacyPlansSeeder;

/**
 * Traduce el nombre del plan tal y como lo exporta el sistema anterior al
 * nombre con el que ese mismo plan vive en `plans`.
 *
 * Por qué existe: el CRM resuelve los módulos de la app buscando
 * `Plan::where('name', $user->plan)`. Si el socio se importa con el nombre
 * crudo del export («MENSUALIDAD») y el catálogo lo llama «Plan Mensual», la
 * búsqueda falla y el socio entra con TODO bloqueado aunque haya pagado.
 *
 * Sólo se traduce lo que tiene equivalente REAL —mismo precio y misma duración
 * verificados contra el export—; lo promocional o de cortesía conserva su
 * nombre y lo crea {@see LegacyPlansSeeder} como plan
 * histórico inactivo.
 */
final class LegacyPlanMap
{
    /**
     * Export → catálogo. Equivalencias comprobadas contra los 9.360 registros
     * del export de membresías (precio y duración coinciden):
     *
     *   MENSUALIDAD       80.000 / 30d  ==  Plan Mensual  80.000 / 30d
     *   TRIMESTRE        210.000 / 90d  ==  Trimestre    210.000 / 90d
     *   SEMESTRE         390.000 /180d  ==  Semestre     390.000 /180d
     *   ANUALIDAD        624.000 /365d  ==  Anualidad    624.000 /365d
     *   SEMANA            45.000 /  7d  ==  Plan Semana   45.000 /  7d
     *   VALERA 2          65.000 / 15   ==  Plan Valera   65.000 / 15d
     *   TOTAL ACCESS XMES180.000 / 30d  ==  Élite        180.000 / 30d
     *
     * VALERA es la misma valera con la tarifa vieja (55.000): mismo plan, no un
     * plan distinto, así que también apunta a «Plan Valera». El precio real
     * cobrado no se pierde — queda en el pago, que guarda el importe del export.
     */
    private const MAP = [
        'MENSUALIDAD' => 'Plan Mensual',
        'TRIMESTRE' => 'Trimestre',
        'SEMESTRE' => 'Semestre',
        'ANUALIDAD' => 'Anualidad',
        'SEMANA' => 'Plan Semana',
        'VALERA' => 'Plan Valera',
        'VALERA 2' => 'Plan Valera',
        'TOTAL ACCESS XMES' => 'Élite',
    ];

    /**
     * Nombre de catálogo para un plan del export. Si no hay equivalencia,
     * devuelve el nombre tal cual: es un plan histórico y se busca por ese
     * mismo nombre (LegacyPlansSeeder los crea con `active = false`).
     */
    public static function resolve(?string $legacyName): ?string
    {
        $name = trim((string) $legacyName);
        if ($name === '') {
            return null;
        }

        return self::MAP[$name] ?? self::MAP[mb_strtoupper($name)] ?? $name;
    }
}
