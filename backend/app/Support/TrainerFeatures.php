<?php

namespace App\Support;

use App\Models\Identity;

/**
 * Autoridad central de feature flags del portal profesional. Una bandera está
 * activa si está encendida globalmente o si la identidad pertenece al piloto.
 * El backend es quien decide; Flutter solo oculta UI según lo que el backend
 * exponga. Toda ruta/operación profesional debe pasar por aquí (o por el
 * middleware `trainer.feature`).
 */
final class TrainerFeatures
{
    public static function enabled(string $flag, ?Identity $identity = null): bool
    {
        $flags = (array) config('trainer.flags', []);

        if (! array_key_exists($flag, $flags)) {
            return false;
        }

        if ($flags[$flag] === true) {
            return true;
        }

        return $identity !== null && self::inPilot($identity);
    }

    public static function inPilot(Identity $identity): bool
    {
        $pilot = (array) config('trainer.pilot_identities', []);

        if ($pilot === []) {
            return false;
        }

        return in_array((string) $identity->getKey(), array_map('strval', $pilot), true);
    }
}
