<?php

use App\Models\Admin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles administrables desde el CRM.
 *
 * Hasta ahora los roles eran cuatro constantes en `Admin::ROLES`: se podían
 * editar sus permisos, pero no crear uno nuevo sin desplegar código.
 *
 * El diseño se apoya en lo que ya existía y no lo reemplaza: `role_permissions`
 * ya guarda la política por NOMBRE de rol (string), y `admins.role` ya es un
 * string. Así que esta tabla es un CATÁLOGO —qué roles existen y cuáles son del
 * sistema—, no un nuevo eje de autorización. Los permisos siguen resolviéndose
 * exactamente igual.
 *
 * Los cuatro roles actuales se insertan como `is_system = true`: no se pueden
 * renombrar ni archivar, porque el código los nombra por constante y perderlos
 * dejaría admins existentes apuntando a un rol inexistente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            // El nombre ES la clave de negocio: `admins.role` y
            // `role_permissions.role` lo referencian por valor. Único, y por eso
            // renombrar un rol es una operación con consecuencias (ver modelo).
            $table->string('name', 60)->unique();
            $table->string('description', 255)->nullable();

            // De sistema: el código lo nombra por constante. No se renombra ni
            // se archiva; sus permisos sí se pueden editar.
            $table->boolean('is_system')->default(false);

            // Archivar en vez de borrar. Un rol borrado dejaría huérfanos a los
            // admins que lo tuvieran y perdería la traza de por qué alguien
            // tenía los permisos que tenía.
            $table->timestamp('archived_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index('archived_at');
        });

        // Backfill de los cuatro roles vigentes. `updateOrInsert` para que la
        // migración sea idempotente si se reejecuta tras un rollback parcial.
        foreach (Admin::ROLES as $nombre) {
            DB::table('admin_roles')->updateOrInsert(
                ['name' => $nombre],
                ['is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        // Cualquier rol que ya exista en `admins` y no esté en la lista de
        // constantes (datos históricos) se cataloga también, para que ningún
        // admin quede apuntando a un rol que el CRM no sabe listar.
        $huerfanos = DB::table('admins')->select('role')->distinct()->pluck('role')
            ->filter(fn ($r) => filled($r) && ! in_array($r, Admin::ROLES, true));
        foreach ($huerfanos as $nombre) {
            DB::table('admin_roles')->updateOrInsert(
                ['name' => $nombre],
                ['is_system' => false, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_roles');
    }
};
