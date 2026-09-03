<?php

namespace App\Support\Access;

use App\Models\Admin;
use App\Models\AdminRole;
use App\Models\RolePermission;
use Illuminate\Support\Facades\Cache;

/**
 * Política efectiva de permisos: los valores por defecto del código con las
 * concesiones y revocaciones persistidas aplicadas encima.
 *
 * Es la respuesta a que Configuración → Roles solo escribiera en
 * `localStorage`: la pantalla aparentaba conceder y revocar permisos para toda
 * la organización sin salir del navegador de quien la usaba.
 *
 * CACHÉ. Se consulta en cada petición autorizada, así que el mapa se guarda en
 * caché y se invalida al escribir. Si la caché falla, se lee de la base: la
 * autorización nunca depende de que la caché esté disponible.
 */
class RolePermissionPolicy
{
    private const CACHE_KEY = 'crm.role_permissions.v1';

    private const CACHE_TTL = 300;

    /**
     * Permisos efectivos de un rol.
     *
     * @return list<string>
     */
    public function effectiveFor(string $role): array
    {
        $defaults = CrmPermission::defaultsFor($role);
        $overrides = $this->overrides()[$role] ?? [];

        if ($overrides === []) {
            return $defaults;
        }

        // Se resuelve sobre nombres CANÓNICOS: revocar `caja.sell` debe revocar
        // también `cash.products.operate`, porque son el mismo permiso con dos
        // nombres. Si no, una revocación en la pantalla de Roles no surtiría
        // efecto y el CRM mentiría sobre lo que acaba de guardar.
        $effective = [];
        foreach ($defaults as $permission) {
            $effective[CrmPermission::canonical($permission)] = true;
        }
        foreach ($overrides as $raw => $granted) {
            $permission = CrmPermission::canonical($raw);
            if ($granted) {
                $effective[$permission] = true;
            } else {
                // Una revocación explícita vence al valor por defecto: es el
                // caso que no se puede expresar con la ausencia de fila.
                unset($effective[$permission]);
            }
        }

        return array_values(array_keys($effective));
    }

    /**
     * Mapa completo para la pantalla de administración: por cada rol, cada
     * permiso conocido con su estado efectivo y si difiere del código.
     *
     * @return array<string, array<string, array{granted: bool, default: bool, overridden: bool}>>
     */
    public function matrix(): array
    {
        $out = [];
        foreach (self::knownRoles() as $role) {
            $defaults = CrmPermission::defaultsFor($role);
            $effective = $this->effectiveFor($role);

            foreach (CrmPermission::all() as $permission) {
                $isDefault = in_array($permission, $defaults, true);
                $isGranted = in_array($permission, $effective, true);
                $out[$role][$permission] = [
                    'granted' => $isGranted,
                    'default' => $isDefault,
                    'overridden' => $isGranted !== $isDefault,
                ];
            }
        }

        return $out;
    }

    /**
     * Fija el estado de un permiso para un rol.
     *
     * Si coincide con el valor por defecto del código se BORRA la fila en vez
     * de guardarla: así la tabla contiene solo excepciones reales y un cambio
     * futuro en el código vuelve a aplicarse a quien no lo tenía tocado.
     */
    public function set(string $role, string $permission, bool $granted, ?Admin $actor = null): void
    {
        $isDefault = in_array($permission, CrmPermission::defaultsFor($role), true);

        if ($granted === $isDefault) {
            RolePermission::where('role', $role)->where('permission', $permission)->delete();
        } else {
            RolePermission::updateOrCreate(
                ['role' => $role, 'permission' => $permission],
                [
                    'granted' => $granted,
                    'updated_by' => $actor?->id,
                    'updated_by_name' => $actor?->name,
                ],
            );
        }

        $this->flush();
    }

    /**
     * Roles para los que hay política. Sale del catálogo `admin_roles`, que
     * incluye los del sistema y los creados desde el CRM; si la tabla aún no
     * existe (migración pendiente) se cae a las constantes del código, para que
     * la autorización nunca dependa de un despliegue a medias.
     *
     * @return list<string>
     */
    public static function knownRoles(): array
    {
        try {
            $roles = AdminRole::allNames();
        } catch (\Throwable) {
            return Admin::ROLES;
        }

        return $roles !== [] ? $roles : Admin::ROLES;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Excepciones persistidas, agrupadas por rol.
     *
     * @return array<string, array<string, bool>>
     */
    private function overrides(): array
    {
        $load = function (): array {
            $map = [];
            foreach (RolePermission::query()->get(['role', 'permission', 'granted']) as $row) {
                $map[$row->role][$row->permission] = (bool) $row->granted;
            }

            return $map;
        };

        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, $load);
        } catch (\Throwable) {
            // Sin caché disponible se lee de la base. La autorización no puede
            // depender de que el almacén de caché esté en pie.
            return $load();
        }
    }
}
