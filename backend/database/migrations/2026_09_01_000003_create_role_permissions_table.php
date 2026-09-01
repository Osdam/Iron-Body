<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Política de permisos por rol, persistida.
 *
 * Hasta ahora los permisos vivían SOLO en código (`CrmPermission::byRole()`), y
 * la pantalla Configuración → Roles guardaba sus cambios en `localStorage`: la
 * interfaz aparentaba conceder y revocar permisos para toda la organización y
 * no salía del navegador de quien la usaba. Seguridad aparente.
 *
 * DISEÑO: esta tabla es una CAPA SOBRE los valores por defecto del código, no
 * su sustituta. Cada fila dice «para este rol, este permiso queda concedido o
 * revocado». Un permiso sin fila conserva lo que dice el código.
 *
 * Por qué así y no una tabla que reemplace el mapa entero:
 *
 *  · Con la tabla vacía el sistema se comporta EXACTAMENTE igual que antes del
 *    despliegue. La migración no cambia el acceso de nadie.
 *  · Un permiso nuevo añadido en código llega ya concedido a quien corresponde,
 *    sin tener que acordarse de insertar filas.
 *  · Si la tabla se corrompe o se vacía, el sistema cae en el mapa del código,
 *    que es conocido y revisable. No se queda sin política.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();

            // Valor de `admins.role`. No hay tabla de roles: los roles son un
            // vocabulario cerrado en Admin::ROLES, y crear roles dinámicos
            // exigiría bastante más que esta tabla.
            $table->string('role', 60);
            $table->string('permission', 60);

            // true = concedido, false = revocado. Se guardan las dos cosas: una
            // revocación explícita tiene que poder vencer al valor por defecto
            // del código, y eso no se puede expresar con la ausencia de fila.
            $table->boolean('granted');

            // Quién lo cambió y cuándo. Un cambio de permisos es un hecho de
            // seguridad; sin autor no se puede revisar después.
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('updated_by_name')->nullable();

            $table->timestamps();

            $table->unique(['role', 'permission']);
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
