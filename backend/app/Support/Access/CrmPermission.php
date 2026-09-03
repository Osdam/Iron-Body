<?php

namespace App\Support\Access;

use App\Models\Admin;
use App\Support\Moderation\ModerationPermission;

/**
 * Permisos OPERATIVOS del CRM y su mapeo a los roles del panel.
 *
 * Hermano de {@see ModerationPermission}, con el mismo
 * diseño y la misma regla de oro: el front puede ocultar un botón, pero quien
 * decide es el backend. Aquel cubre la moderación de comunidad; éste cubre las
 * operaciones de mostrador e inventario. No se duplican permisos entre ambos.
 *
 * El CRM NO tiene tabla de permisos: los deriva del `role` del admin
 * (`Admin::ROLES`), igual que `AccessControlService` en el front Angular. Este
 * mapa es el espejo servidor de esa política.
 *
 * Por qué existe: las rutas `/api/admin/caja/*` y `/api/admin/products/*` solo
 * exigían credencial administrativa. `caja.sell` e `inventory.edit` se
 * comprobaban únicamente en el navegador, así que una llamada directa a la API
 * con la sesión de cualquier admin —por ejemplo, Recepción, que no tiene
 * permisos de inventario— podía cambiar precios, costos y existencias. El
 * comentario de `routes/api.php` ya lo admitía: «Luego se restringirá a ciertos
 * usuarios».
 *
 * Mínimo privilegio, por rol:
 *  - Recepción:     vende en mostrador y CONSULTA inventario. No edita el
 *                   catálogo ni mueve existencias, ni cancela ventas.
 *  - Administrativo: sin operaciones de caja ni de inventario (espejo del front,
 *                   que tampoco le da perfil operativo).
 *  - Administrador / Super Admin: operación completa.
 *
 * El token compartido de automatizaciones (`config('admin.api_token')`) NO
 * resuelve a un Admin: {@see forAdmin()} lo trata como `null` y solo obtiene
 * LECTURA, nunca cobro ni escritura de existencias. Misma política que
 * ModerationPermission.
 */
final class CrmPermission
{
    // ── Caja / punto de venta ───────────────────────────────────────────────
    //
    // LEGADO. Se conservan como ALIAS de la caja de productos: existen rutas,
    // roles persistidos en `role_permissions` y clientes que aún los nombran.
    // Conceder `cash.products.*` concede también su alias, y viceversa, así que
    // nada de lo que ya funcionaba deja de funcionar. Ver ALIASES.
    public const CAJA_VIEW = 'caja.view';

    public const CAJA_SELL = 'caja.sell';

    public const CAJA_MANAGE = 'caja.manage';

    // ── Caja de PRODUCTOS (cafetería / mostrador) ───────────────────────────
    public const CASH_PRODUCTS_VIEW = 'cash.products.view';

    public const CASH_PRODUCTS_OPERATE = 'cash.products.operate';

    public const CASH_PRODUCTS_MANAGE = 'cash.products.manage';

    // ── Caja del GIMNASIO (membresías / planes) ─────────────────────────────
    public const CASH_GYM_VIEW = 'cash.gym.view';

    public const CASH_GYM_OPERATE = 'cash.gym.operate';

    public const CASH_GYM_MANAGE = 'cash.gym.manage';

    /**
     * Equivalencias legado ↔ nuevo. Conceder cualquiera de los dos lados
     * concede el otro: es lo que permite renombrar el vocabulario sin una
     * migración de datos que reescriba `role_permissions`.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        self::CAJA_VIEW => self::CASH_PRODUCTS_VIEW,
        self::CAJA_SELL => self::CASH_PRODUCTS_OPERATE,
        self::CAJA_MANAGE => self::CASH_PRODUCTS_MANAGE,
    ];

    // ── Facturación electrónica (Factus / DIAN) ─────────────────────────────
    public const BILLING_VIEW = 'billing.view';

    public const BILLING_MANAGE = 'billing.manage';

    // ── Inventario ──────────────────────────────────────────────────────────
    public const INVENTORY_VIEW = 'inventory.view';

    public const INVENTORY_CREATE = 'inventory.create';

    public const INVENTORY_EDIT = 'inventory.edit';

    public const INVENTORY_DELETE = 'inventory.delete';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::CAJA_VIEW,
            self::CAJA_SELL,
            self::CAJA_MANAGE,
            self::CASH_PRODUCTS_VIEW,
            self::CASH_PRODUCTS_OPERATE,
            self::CASH_PRODUCTS_MANAGE,
            self::CASH_GYM_VIEW,
            self::CASH_GYM_OPERATE,
            self::CASH_GYM_MANAGE,
            self::BILLING_VIEW,
            self::BILLING_MANAGE,
            self::INVENTORY_VIEW,
            self::INVENTORY_CREATE,
            self::INVENTORY_EDIT,
            self::INVENTORY_DELETE,
        ];
    }

    /**
     * Permisos de SOLO LECTURA. Es lo máximo que obtiene una credencial sin
     * identidad de persona (el token compartido de automatizaciones).
     *
     * @return list<string>
     */
    public static function readOnly(): array
    {
        return [
            self::CAJA_VIEW,
            self::CASH_PRODUCTS_VIEW,
            self::CASH_GYM_VIEW,
            self::INVENTORY_VIEW,
        ];
    }

    /**
     * Mapa rol → permisos. Espejo de los perfiles de `AccessControlService`.
     *
     * @return array<string, list<string>>
     */
    public static function byRole(): array
    {
        // Recepción atiende el mostrador: cobra y consulta existencias para
        // saber qué hay. No toca el catálogo ni da de baja mercancía, no
        // cancela ventas ya registradas y NO emite comprobantes fiscales: una
        // factura electrónica es un documento ante la DIAN a nombre del cliente.
        // Recepción cobra en las DOS cajas: es quien está en el mostrador
        // cuando alguien paga una mensualidad. Lo que no tiene es supervisión
        // (`manage`): cerrar turnos ajenos y registrar arqueos físicos.
        $reception = [
            self::CAJA_VIEW,
            self::CAJA_SELL,
            self::CASH_PRODUCTS_VIEW,
            self::CASH_PRODUCTS_OPERATE,
            self::CASH_GYM_VIEW,
            self::CASH_GYM_OPERATE,
            self::INVENTORY_VIEW,
        ];

        // Administrativo no tiene perfil operativo en el CRM (el front tampoco
        // se lo da). Su alcance hoy es la moderación, que vive en su propio mapa.
        $administrative = [];

        return [
            Admin::ROLE_SUPER_ADMIN => self::all(),
            Admin::ROLE_ADMINISTRADOR => self::all(),
            Admin::ROLE_ADMINISTRATIVO => $administrative,
            Admin::ROLE_RECEPCION => $reception,
        ];
    }

    /**
     * Permisos efectivos de un admin.
     *
     * Falla CERRADO en los dos bordes: un admin inactivo o de rol desconocido no
     * obtiene nada, y una credencial sin persona detrás obtiene solo lectura.
     *
     * Sobre el mapa del código se aplica la política PERSISTIDA
     * ({@see RolePermissionPolicy}), que es lo que edita Configuración → Roles.
     * Con la tabla vacía el resultado es idéntico al de antes: la persistencia
     * añade excepciones, no reemplaza la política base.
     *
     * @return list<string>
     */
    public static function forAdmin(?Admin $admin): array
    {
        if (! $admin instanceof Admin) {
            // Token compartido de automatizaciones: nunca cobra ni mueve stock.
            return self::readOnly();
        }

        if (! $admin->isActive()) {
            return [];
        }

        return app(RolePermissionPolicy::class)->effectiveFor($admin->role);
    }

    /**
     * Permisos por defecto de un rol, tal y como los define el CÓDIGO, sin
     * aplicar la política persistida. Lo usa la propia política como base y la
     * pantalla de Roles para poder mostrar qué se ha cambiado respecto a esto.
     *
     * @return list<string>
     */
    public static function defaultsFor(string $role): array
    {
        return self::byRole()[$role] ?? [];
    }

    /**
     * Nombre CANÓNICO de un permiso: los legados se resuelven a su equivalente
     * nuevo.
     *
     * Es una identidad, no un "o": `caja.sell` y `cash.products.operate` son el
     * MISMO permiso con dos nombres. Tratarlos como dos permisos que se conceden
     * mutuamente haría que revocar uno no sirviera de nada, porque el otro lo
     * devolvería — justo el agujero que esto evita.
     */
    public static function canonical(string $permission): string
    {
        return self::ALIASES[$permission] ?? $permission;
    }

    public static function allows(?Admin $admin, string $permission): bool
    {
        return in_array(
            self::canonical($permission),
            array_map(self::canonical(...), self::forAdmin($admin)),
            true,
        );
    }
}
