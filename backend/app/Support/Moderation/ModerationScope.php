<?php

namespace App\Support\Moderation;

/**
 * Alcances de sanción y tipos de acción administrativa.
 *
 * El alcance define QUÉ pierde el miembro. Ninguno de ellos toca membresía,
 * pagos, facturación electrónica ni acceso físico al gimnasio — eso es una
 * invariante del sistema, no una convención.
 */
final class ModerationScope
{
    /** Solo afecta al contenido, no al miembro. */
    public const CONTENT_ONLY = 'content_only';

    /** No puede publicar Stories. Todo lo demás sigue igual. */
    public const STORY_POSTING = 'story_posting';

    /** No puede reaccionar ni reportar. Sigue viendo contenido permitido. */
    public const STORY_INTERACTION = 'story_interaction';

    /** Sin Stories ni funciones sociales. Conserva rutinas, nutrición, clases. */
    public const SOCIAL_FEATURES = 'social_features';

    /** Bloqueo total de la app. Solo casos graves + permiso elevado. */
    public const FULL_APP_ACCESS = 'full_app_access';

    /** @return list<string> Scopes que se materializan como suspensión. */
    public static function suspendable(): array
    {
        return [
            self::STORY_POSTING,
            self::STORY_INTERACTION,
            self::SOCIAL_FEATURES,
            self::FULL_APP_ACCESS,
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge([self::CONTENT_ONLY], self::suspendable());
    }

    /**
     * Scopes que quedan cubiertos por uno dado (jerarquía de contención).
     *
     * `social_features` implica que tampoco puede publicar ni interactuar;
     * `full_app_access` lo implica todo. Así una sola consulta responde
     * "¿puede publicar?" sin enumerar combinaciones en cada call-site.
     *
     * @return list<string>
     */
    public static function implies(string $scope): array
    {
        return match ($scope) {
            self::FULL_APP_ACCESS => [
                self::FULL_APP_ACCESS,
                self::SOCIAL_FEATURES,
                self::STORY_POSTING,
                self::STORY_INTERACTION,
            ],
            self::SOCIAL_FEATURES => [
                self::SOCIAL_FEATURES,
                self::STORY_POSTING,
                self::STORY_INTERACTION,
            ],
            self::STORY_POSTING => [self::STORY_POSTING],
            self::STORY_INTERACTION => [self::STORY_INTERACTION],
            default => [],
        };
    }

    /**
     * Scopes cuya existencia activa restringe la capacidad pedida.
     * Inverso de `implies()`: "¿qué sanciones me impiden publicar?".
     *
     * @return list<string>
     */
    public static function blockedBy(string $capability): array
    {
        $out = [];
        foreach (self::suspendable() as $scope) {
            if (in_array($capability, self::implies($scope), true)) {
                $out[] = $scope;
            }
        }

        return $out;
    }

    public static function label(string $scope): string
    {
        return [
            self::CONTENT_ONLY => 'Solo contenido',
            self::STORY_POSTING => 'Publicación de estados',
            self::STORY_INTERACTION => 'Interacción social',
            self::SOCIAL_FEATURES => 'Funciones sociales',
            self::FULL_APP_ACCESS => 'Acceso completo a la app',
        ][$scope] ?? $scope;
    }

    /** Texto que ve el miembro sancionado — sin jerga interna. */
    public static function memberExplanation(string $scope): string
    {
        return [
            self::STORY_POSTING => 'No puedes publicar estados durante este periodo. '
                .'El resto de la app (rutinas, nutrición, clases y tu membresía) sigue disponible.',
            self::STORY_INTERACTION => 'No puedes reaccionar a estados durante este periodo. '
                .'Puedes seguir viendo el contenido permitido y usar el resto de la app.',
            self::SOCIAL_FEATURES => 'Las funciones sociales (estados y reacciones) están '
                .'desactivadas durante este periodo. Tus rutinas, nutrición, clases y '
                .'membresía no se ven afectadas.',
            self::FULL_APP_ACCESS => 'El acceso a la aplicación está restringido durante este periodo. '
                .'Tu membresía del gimnasio no se ha cancelado.',
        ][$scope] ?? 'Se aplicó una restricción a tu cuenta.';
    }
}
