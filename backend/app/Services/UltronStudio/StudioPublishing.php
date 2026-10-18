<?php

namespace App\Services\UltronStudio;

/**
 * Publicar y revertir versiones del Studio: en esta fase, SIEMPRE rechazado.
 *
 * Dos cerrojos y los dos tienen que abrirse (contrato, decisión D1):
 *
 *  - La bandera `ULTRON_STUDIO_PUBLISHING_ENABLED` (config/ultron_studio.php),
 *    apagada.
 *  - La constante {@see ENGINE_READS_VERSIONS}: el motor de ULTRON no lee
 *    versiones del Studio. Mientras sea false, encender la bandera solo cambia
 *    el motivo del 409 (`engine_not_reading_versions`), nunca el resultado:
 *    marcar «publicada» una versión que el motor no usa sería mentir.
 *
 * La fase que conecte el motor cambiará la constante en el MISMO commit que el
 * motor, e implementará la transición; hasta entonces no existe código que
 * publique.
 */
final class StudioPublishing
{
    public const ENGINE_READS_VERSIONS = false;

    public const CODE_DISABLED = 'publishing_disabled';

    public const CODE_ENGINE_NOT_READING = 'engine_not_reading_versions';

    public const ENGINE_MESSAGE = 'El motor de ULTRON no lee versiones del Studio: aprobar aquí no cambia al agente.';

    public static function flagEnabled(): bool
    {
        return (bool) config('ultron_studio.publishing_enabled', false);
    }

    /** ¿Se puede publicar? En esta fase, nunca. */
    public static function available(): bool
    {
        return false;
    }

    /**
     * Por qué se rechaza publicar (`publish`) o revertir (`rollback`).
     *
     * @return array{code:string,message:string}
     */
    public static function refusal(string $action = 'publish'): array
    {
        $verbo = $action === 'rollback' ? 'Revertir' : 'Publicar';

        if (! self::flagEnabled()) {
            return [
                'code' => self::CODE_DISABLED,
                'message' => "{$verbo} está desactivado: el motor de ULTRON aún no lee versiones del Studio.",
            ];
        }

        return [
            'code' => self::CODE_ENGINE_NOT_READING,
            'message' => "La publicación está encendida en el servidor, pero el motor de ULTRON aún no lee versiones del Studio: {$verbo} no tendría ningún efecto.",
        ];
    }

    /**
     * El bloque `publishing` de `GET status`.
     *
     * @return array{available:bool,code:string,message:string}
     */
    public static function status(): array
    {
        return ['available' => self::available()] + self::refusal('publish');
    }

    /**
     * El bloque `engine` de `GET status`.
     *
     * @return array{readsVersions:bool,activeVersion:null,message:string}
     */
    public static function engine(): array
    {
        return [
            'readsVersions' => self::ENGINE_READS_VERSIONS,
            'activeVersion' => null,
            'message' => self::ENGINE_MESSAGE,
        ];
    }
}
