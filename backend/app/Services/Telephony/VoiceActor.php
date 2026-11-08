<?php

namespace App\Services\Telephony;

use App\Models\Admin;
use App\Support\Access\AdminActor;
use App\Support\Access\CrmPermission;
use Illuminate\Http\Request;

/**
 * La segunda cerradura de las rutas del CRM de voz (contrato H8, D8-22): la
 * puerta (`AuthorizationMap`, ruta a ruta) ya comprobó la llave; aquí se exige
 * además una PERSONA (el token compartido de automatizaciones no llama ni lee
 * la telefonía) y se vuelve a comprobar la llave dentro del dominio, por si
 * una ruta se clasificara mal algún día.
 */
final class VoiceActor
{
    /** @throws TelephonyException 403 `person_required` o `forbidden` */
    public static function require(Request $request, string $permission): Admin
    {
        $admin = AdminActor::from($request);
        if (! $admin instanceof Admin) {
            throw TelephonyException::personRequired();
        }
        if (! CrmPermission::allows($admin, $permission)) {
            throw TelephonyException::forbidden($permission);
        }

        return $admin;
    }

    public static function can(Admin $admin, string $permission): bool
    {
        return CrmPermission::allows($admin, $permission);
    }
}
