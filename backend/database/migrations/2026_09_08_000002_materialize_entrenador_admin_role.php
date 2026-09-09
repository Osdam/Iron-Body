<?php

use App\Support\Access\CrmPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Materializa el rol ENTRENADOR en el catálogo `admin_roles`.
 *
 * El rol estaba a medio construir: `CrmPermission::ROLE_ENTRENADOR` ya existía
 * con sus permisos por defecto, y el propio código lo anticipaba —«se define
 * aquí para poder darle defaults, y el catálogo `admin_roles` lo materializa»—
 * pero nadie llegó a materializarlo. Como `AdminUserController` valida el rol
 * con `Rule::in(AdminRole::assignableNames())`, y esa lista sale del catálogo,
 * crear una cuenta de entrenador devolvía 422. El rol existía en el papel y era
 * inasignable en la práctica.
 *
 * ES SUFICIENTE CON ESTA FILA. La resolución de permisos ya trabaja con nombres
 * de rol en texto: `RolePermissionPolicy::knownRoles()` enumera el catálogo,
 * `CrmPermission::byRole()` aporta los defaults y `role_permissions` los ajusta
 * encima desde Configuración → Roles. No hace falta tocar la autorización.
 *
 * POR QUÉ NO SE AÑADE A `Admin::ROLES`. Esa constante no es la lista de roles
 * asignables —eso es el catálogo—, sino una lista ORDENADA DE MÁS A MENOS MANDO
 * que `ImportLegacyProductsCommand::ordenPorMando()` convierte en un CASE de SQL
 * para elegir autor al importar. Añadir ahí al entrenador cambiaría el
 * comportamiento de un comando que no tiene nada que ver con esto.
 *
 * POR QUÉ `is_system = true`. El código nombra este rol por constante. Si se
 * pudiera renombrar o archivar desde el CRM, los entrenadores existentes
 * quedarían apuntando a un rol sin defaults —es decir, sin permisos— y la
 * pantalla de Roles dejaría de ofrecerlo. Sus permisos SÍ se editan, como los
 * de los otros cuatro roles del sistema; lo que se protege es el nombre.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `updateOrInsert` para que sea idempotente: si alguien ya creó el rol a
        // mano desde el CRM, esto no lo duplica, solo lo promueve a del sistema
        // y le pone descripción. Sus permisos persistidos se conservan, porque
        // `role_permissions` referencia el NOMBRE, que no cambia.
        DB::table('admin_roles')->updateOrInsert(
            ['name' => CrmPermission::ROLE_ENTRENADOR],
            [
                'description' => 'Rutinas, clases y ficha de los socios que atiende.',
                'is_system' => true,
                'archived_at' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        // Solo se retira si nadie lo está usando. Borrar un rol con cuentas
        // asignadas las dejaría apuntando a un rol inexistente: sin defaults y,
        // por tanto, sin permisos. Eso no es un rollback, es una avería.
        $enUso = DB::table('admins')
            ->where('role', CrmPermission::ROLE_ENTRENADOR)
            ->exists();

        if (! $enUso) {
            DB::table('admin_roles')
                ->where('name', CrmPermission::ROLE_ENTRENADOR)
                ->delete();
        }
    }
};
