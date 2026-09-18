<?php

namespace App\Services\Marketing;

/**
 * Los enlaces oficiales de la app Iron Body Workout, en un solo sitio. Los
 * escribe Laravel en sus propios mensajes; el modelo nunca escribe URLs.
 */
final class MobileAppLinks
{
    public const ANDROID = 'https://play.google.com/store/apps/details?id=com.ironbodyneiva.workout';

    public const IOS = 'https://apps.apple.com/co/app/iron-body-workout/id6792374138';

    public const WEB = 'https://web.ironbodyneiva.cloud';

    /** Una línea con los tres, para pegar al final de un mensaje. */
    public static function asLine(): string
    {
        return 'Android: '.self::ANDROID.' · iPhone: '.self::IOS.' · Web: '.self::WEB;
    }
}
