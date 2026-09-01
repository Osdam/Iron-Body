<?php

namespace App\Support\Access;

use App\Http\Middleware\EnsureAdminAuth;
use App\Models\Admin;
use Illuminate\Http\Request;

/**
 * Quién está ejecutando una acción administrativa.
 *
 * Existe porque `$request->user()` NO está poblado en las rutas del CRM:
 * {@see EnsureAdminAuth} deja el administrador en el
 * atributo `auth_admin` del request, no en el guard de autenticación. Todo el
 * código que preguntaba por `$request->user()` en /api/admin/* recibía `null`
 * en silencio, y por eso las ventas se guardaban sin cajero y los movimientos
 * de inventario sin autor: la traza registraba el qué y perdía el quién.
 *
 * Aquí se resuelve en un solo sitio, para que no vuelva a haber dos formas de
 * preguntar lo mismo con respuestas distintas.
 */
final class AdminActor
{
    /** El administrador autenticado, o null si se entró con el token compartido. */
    public static function from(Request $request): ?Admin
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? $admin : null;
    }

    /** Id del administrador, para columnas que referencian `admins`. */
    public static function id(Request $request): ?int
    {
        return self::from($request)?->id;
    }

    /**
     * Nombre para congelar como instantánea en la traza.
     *
     * Se guarda además del id porque un registro histórico debe seguir
     * diciendo quién lo hizo aunque la cuenta se elimine después.
     */
    public static function name(Request $request): ?string
    {
        return self::from($request)?->name;
    }

    /**
     * Etiqueta legible del origen de la acción, para auditoría.
     *
     * El token compartido de automatizaciones no es una persona; decirlo
     * explícitamente vale más que dejar el campo vacío.
     */
    public static function label(Request $request): string
    {
        return self::from($request)?->name ?? 'Automatización (token compartido)';
    }
}
