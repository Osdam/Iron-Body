<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El documento del socio, único también en la base.
 *
 * `members.document_number` ya lo era; `users.document` no. La unicidad del
 * lado de `users` dependía solo de un `exists()` en el controlador, y entre esa
 * consulta y el INSERT cabe otra petición: dos altas simultáneas del mismo
 * documento pasaban las dos el filtro y creaban dos usuarios. El documento es
 * la llave con la que el socio entra a la aplicación, así que duplicarlo
 * significa que no se sabe quién entra.
 *
 * SEGURA DE APLICAR: auditados los 3796 usuarios de producción antes de
 * escribirla, cero documentos repetidos, cero nulos y cero vacíos.
 *
 * Nullable: el índice único de PostgreSQL admite varios NULL, así que un
 * usuario sin documento —hoy no hay ninguno— no bloquearía a los demás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unique('document', 'users_document_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_document_unique');
        });
    }
};
