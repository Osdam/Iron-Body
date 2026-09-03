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
    /**
     * Rol de entrenador dentro del CRM. No está en Admin::ROLES porque no
     * existía como cuenta administrativa; se define aquí para poder darle
     * defaults, y el catálogo `admin_roles` lo materializa.
     */
    public const ROLE_ENTRENADOR = 'Entrenador';

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

    // ── Administración de roles ─────────────────────────────────────────────
    public const ROLES_MANAGE = 'roles.manage';

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

    /**
     * Catálogo completo. Sale de {@see PermissionCatalog}, que a su vez lo
     * deriva de las rutas reales: no hay forma de que aparezca aquí un permiso
     * que no proteja nada.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(
            PermissionCatalog::all(),
            // El vocabulario anterior sigue aceptándose como alias.
            [self::CAJA_VIEW, self::CAJA_SELL, self::CAJA_MANAGE],
        )));
    }

    /**
     * Permisos de SOLO LECTURA. Es lo máximo que obtiene una credencial sin
     * identidad de persona (el token compartido de automatizaciones).
     *
     * @return list<string>
     */
    public static function readOnly(): array
    {
        // TODA lectura, ninguna escritura. Es lo máximo que puede obtener una
        // credencial sin persona detrás: puede consultar para automatizar, pero
        // nunca cobrar, mover existencias ni sancionar, porque un descuadre sin
        // responsable no se puede investigar.
        return array_values(array_filter(
            PermissionCatalog::all(),
            fn (string $p) => str_ends_with($p, '.view'),
        ));
    }

    /**
     * Permisos por defecto de cada rol del sistema.
     *
     * Estos valores son la BASE; `role_permissions` los ajusta encima desde la
     * pantalla de Roles. Cambiar aquí solo mueve el punto de partida.
     *
     * @return array<string, list<string>>
     */
    public static function byRole(): array
    {
        $todo = PermissionCatalog::all();

        /*
         * ADMINISTRADOR. En este primer despliegue conserva el acceso amplio
         * que ya tenía de hecho —hasta ahora podía llamar cualquier endpoint
         * administrativo— menos las tres llaves que son autoridad sobre el
         * propio sistema. Endurecerlo más de golpe habría convertido un
         * despliegue de seguridad en una interrupción de servicio; se afina
         * después, con uso real medido.
         */
        $administrador = array_values(array_diff($todo, [
            'roles.manage',        // repartir permisos es de Super Admin
            'users.manage',        // crear cuentas del CRM, también
            'integrations.manage', // conectar/desconectar canales externos
            'audit.view',          // el registro de quién hizo qué
        ]));

        /*
         * RECEPCIÓN. El mostrador: atiende, cobra y consulta. Puede abrir y
         * cerrar las dos cajas porque es quien está delante cuando alguien paga
         * —un batido o una mensualidad—, pero no supervisa turnos ajenos ni
         * registra arqueos físicos.
         *
         * Fuera queda todo lo que no necesita para atender: ganancias,
         * auditoría, roles, usuarios, integraciones, facturación electrónica,
         * moderación y seguridad de plataforma.
         */
        $recepcion = [
            'members.view', 'members.create',
            'plans.view',
            'payments.view', 'payments.create',
            'cash.products.view', 'cash.products.operate',
            'cash.gym.view', 'cash.gym.operate',
            'inventory.view',
            // Recepción gestiona inscripciones a clases desde el mostrador, así
            // que necesita verlas. Es lectura: crear y editar horarios no.
            'classes.view',
            'support.view',
            // SIN moderación, ni siquiera lectura. ModerationPermission se la
            // concedía, pero el rol base de recepción es atención y cobro; si
            // alguna recepcionista debe revisar comunidad, se le concede
            // explícitamente desde la pantalla de Roles.
            // Alias del vocabulario anterior, para no romper nada que aún lo
            // nombre. Equivale a cash.products.*, no concede nada nuevo.
            self::CAJA_VIEW, self::CAJA_SELL,
        ];

        /*
         * ENTRENADOR. Según lo que hace hoy en el CRM: rutinas, ejercicios,
         * clases y la ficha de los socios que atiende. No cobra, no toca caja
         * y no administra nada.
         */
        $entrenador = [
            'members.view',
            'routines.view', 'routines.manage',
            'classes.view', 'classes.manage',
            'trainers.view',
        ];

        /*
         * ADMINISTRATIVO. Sin perfil operativo: su alcance es la moderación de
         * comunidad. ModerationPermission le concede revisar y asignar, así que
         * la puerta exterior tiene que dejarle pasar a esas rutas; qué puede
         * hacer una vez dentro lo sigue decidiendo aquel mapa, que es más
         * estricto. Sin esto, activar el enforcement le habría quitado en
         * silencio el único trabajo que tiene en el CRM.
         */
        $administrativo = [
            'moderation.view',
            'moderation.manage',
        ];

        return [
            Admin::ROLE_SUPER_ADMIN => self::all(),
            Admin::ROLE_ADMINISTRADOR => $administrador,
            Admin::ROLE_ADMINISTRATIVO => $administrativo,
            Admin::ROLE_RECEPCION => $recepcion,
            self::ROLE_ENTRENADOR => $entrenador,
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
